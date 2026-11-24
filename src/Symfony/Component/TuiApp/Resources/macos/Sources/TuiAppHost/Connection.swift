import Darwin
import Foundation
import TuiAppHostCore

/// The loopback listener and the one authenticated connection. Sockets are read on background
/// threads; every message is delivered on the main thread, in order.
final class Connection: @unchecked Sendable {
    /// Called on the main thread.
    struct Handlers {
        var authenticated: @MainActor () -> Void
        var received: @MainActor (Message) -> Void
        var closed: @MainActor (String) -> Void
        var log: @MainActor (String) -> Void
    }

    let token: String
    private(set) var port: UInt16 = 0
    private let lock = NSLock()
    private var listener: Int32 = -1
    private var active: Int32 = -1
    private var stopped = false
    private let writes = DispatchQueue(label: "tuiapp.connection.write")
    private let handlers: Handlers

    init(token: String, handlers: Handlers) {
        self.token = token
        self.handlers = handlers
    }

    var address: String { "tcp://127.0.0.1:\(port)" }

    /// Binds 127.0.0.1:0 and starts accepting.
    func listen() throws {
        let fd = socket(AF_INET, SOCK_STREAM, 0)
        guard fd >= 0 else { throw posixError("socket") }
        var yes: Int32 = 1
        setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &yes, socklen_t(MemoryLayout<Int32>.size))
        setsockopt(fd, SOL_SOCKET, SO_NOSIGPIPE, &yes, socklen_t(MemoryLayout<Int32>.size))
        var address = sockaddr_in()
        address.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)
        address.sin_family = sa_family_t(AF_INET)
        address.sin_port = 0
        address.sin_addr.s_addr = inet_addr("127.0.0.1")
        let bound = withUnsafePointer(to: &address) {
            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) { bind(fd, $0, socklen_t(MemoryLayout<sockaddr_in>.size)) }
        }
        guard bound == 0 else {
            close(fd)
            throw posixError("bind")
        }
        guard Darwin.listen(fd, 8) == 0 else {
            close(fd)
            throw posixError("listen")
        }
        var length = socklen_t(MemoryLayout<sockaddr_in>.size)
        _ = withUnsafeMutablePointer(to: &address) {
            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) { getsockname(fd, $0, &length) }
        }
        port = UInt16(bigEndian: address.sin_port)
        listener = fd
        let thread = Thread { [self] in acceptLoop(fd) }
        thread.name = "tuiapp.accept"
        thread.start()
    }

    private func acceptLoop(_ fd: Int32) {
        while true {
            let client = accept(fd, nil, nil)
            if client < 0 {
                if errno == EINTR { continue }
                return // the listener was closed
            }
            var yes: Int32 = 1
            setsockopt(client, SOL_SOCKET, SO_NOSIGPIPE, &yes, socklen_t(MemoryLayout<Int32>.size))
            let thread = Thread { [self] in serve(client) }
            thread.name = "tuiapp.read"
            thread.start()
        }
    }

    /// Reads one connection: its first frame must be a valid auth, then every frame goes to the
    /// main thread.
    private func serve(_ fd: Int32) {
        // A peer that connects and says nothing gives up its place after 10 s.
        var timeout = timeval(tv_sec: 10, tv_usec: 0)
        setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &timeout, socklen_t(MemoryLayout<timeval>.size))
        var decoder = Frame.Decoder()
        var authenticated = false
        var buffer = [UInt8](repeating: 0, count: 64 * 1024)
        var why = "the application closed the connection"
        reading: while true {
            let count = read(fd, &buffer, buffer.count)
            if count < 0, errno == EINTR { continue }
            if count <= 0 {
                if count < 0 { why = "read: \(String(cString: strerror(errno)))" }
                break
            }
            decoder.append(Data(buffer[0..<count]))
            do {
                while let payload = try decoder.next() {
                    let message: Message
                    do {
                        message = try Message.decode(payload)
                    } catch {
                        if !authenticated { break reading }
                        let text = String(decoding: payload.prefix(200), as: UTF8.self)
                        main { $0.handlers.log("unreadable message (\(error)): \(text)") }
                        continue
                    }
                    if !authenticated {
                        guard case let .auth(token, version) = message, version == 1, Token.matches(token, self.token), claim(fd) else {
                            break reading
                        }
                        authenticated = true
                        timeout = timeval(tv_sec: 0, tv_usec: 0)
                        setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &timeout, socklen_t(MemoryLayout<timeval>.size))
                        main { $0.handlers.authenticated() }
                        continue
                    }
                    main { $0.handlers.received(message) }
                }
            } catch {
                why = "bad frame: \(error)"
                break
            }
        }
        close(fd)
        if authenticated {
            lock.withLock { active = -1 }
            main { $0.handlers.closed(why) }
        }
    }

    /// Makes this connection the one, and stops listening. False when another one won.
    private func claim(_ fd: Int32) -> Bool {
        lock.withLock {
            guard active < 0, !stopped else { return false }
            active = fd
            if listener >= 0 {
                close(listener)
                listener = -1
            }
            return true
        }
    }

    private func main(_ work: @escaping @MainActor @Sendable (Connection) -> Void) {
        DispatchQueue.main.async { [self] in
            MainActor.assumeIsolated { work(self) }
        }
    }

    func send(_ message: Message) {
        let fd = lock.withLock { active }
        guard fd >= 0, let frame = try? Frame.encode(message.encode()) else { return }
        writes.async {
            frame.withUnsafeBytes { raw in
                var offset = 0
                while offset < raw.count {
                    let written = write(fd, raw.baseAddress! + offset, raw.count - offset)
                    if written < 0 {
                        if errno == EINTR { continue }
                        return
                    }
                    offset += written
                }
            }
        }
    }

    func stop() {
        lock.withLock {
            stopped = true
            if listener >= 0 {
                close(listener)
                listener = -1
            }
            if active >= 0 {
                shutdown(active, SHUT_RDWR)
            }
        }
    }

    private func posixError(_ call: String) -> NSError {
        NSError(domain: NSPOSIXErrorDomain, code: Int(errno), userInfo: [NSLocalizedDescriptionKey: "\(call): \(String(cString: strerror(errno)))"])
    }
}
