import Foundation
import Network

final class MobileSession {
  let queue = DispatchQueue(label: "org.symfony.tui.connection")
  var listener: NWListener?
  var connection: NWConnection?
  var clients = [MobileClient]()
  var queued = 0
  var hello: Hello
  let token = Token.make()
  var receive: ((Message) -> Void)?
  var failure: ((String) -> Void)?
  var ended: (() -> Void)?
  var authenticated = false
  var stopped = false
  var foreground = true
  var didExit: ((Int32) -> Void)?

  init(hello: Hello) { self.hello = hello }

  func start() throws {
    let resources = Bundle.main.resourceURL!
    let manifest = try Manifest.parse(
      Data(contentsOf: resources.appendingPathComponent("app.manifest.json")))
    guard manifest.launch.mode == "embedded" else {
      throw SessionError.invalid("iOS requires the embedded runtime")
    }
    var index = 0
    while index < manifest.launch.args.count {
      if manifest.launch.args[index].hasPrefix("./app/") { break }
      if manifest.launch.args[index] == "-d" {
        index += 1
        guard index < manifest.launch.args.count else {
          throw SessionError.invalid("Missing PHP INI value")
        }
        let parts = manifest.launch.args[index].split(
          separator: "=", maxSplits: 1, omittingEmptySubsequences: false)
        guard parts.count == 2, !parts[0].isEmpty, !parts.contains(where: { $0.contains("\0") })
        else { throw SessionError.invalid("Invalid PHP INI setting") }
        let status = String(parts[0]).withCString { key in
          String(parts[1]).withCString { value in tui_mobile_set_ini(key, value) }
        }
        guard status == 0 else { throw SessionError.invalid("Cannot configure PHP INI setting") }
      }
      index += 1
    }
    let name = try String(
      contentsOf: resources.appendingPathComponent("mobile.entry"), encoding: .utf8
    ).trimmingCharacters(in: .whitespacesAndNewlines)
    let payload = resources.appendingPathComponent("payload").resolvingSymlinksInPath()
    let entry = payload.appendingPathComponent(name).resolvingSymlinksInPath()
    guard entry.path.hasPrefix(payload.path + "/"),
      FileManager.default.fileExists(atPath: entry.path)
    else { throw SessionError.invalid("Invalid mobile entry") }
    let parameters = NWParameters.tcp
    parameters.requiredLocalEndpoint = .hostPort(host: "127.0.0.1", port: .any)
    let listener = try NWListener(using: parameters)
    self.listener = listener
    listener.newConnectionHandler = { [weak self] connection in
      guard let self else {
        connection.cancel()
        return
      }
      guard !self.authenticated, self.clients.count < 4 else {
        connection.cancel()
        return
      }
      let client = MobileClient(connection: connection, owner: self)
      self.clients.append(client)
      client.start()
    }
    listener.stateUpdateHandler = { [weak self] state in
      guard let self else { return }
      switch state {
      case .ready:
        guard let port = listener.port else {
          self.fail("No listener port")
          return
        }
        DispatchQueue.global(qos: .userInitiated).async {
          let address = "tcp://127.0.0.1:\(port.rawValue)"
          setenv("TMPDIR", NSTemporaryDirectory(), 1)
          guard chdir(payload.path) == 0 else {
            self.queue.async { self.fail("Cannot enter application directory") }
            return
          }
          let status = entry.path.withCString { path in
            address.withCString { address in
              self.token.withCString { token in tui_mobile_run(path, address, token) }
            }
          }
          self.queue.async {
            if status != 0 || !self.authenticated {
              let log =
                (try? String(
                  contentsOfFile: NSTemporaryDirectory() + "/tui-php.log", encoding: .utf8)) ?? ""
              self.fail("PHP exited with status \(status)\n" + String(log.suffix(4096)))
            } else {
              DispatchQueue.main.async {
                self.ended?()
                self.didExit?(status)
              }
            }
          }
        }
        self.queue.asyncAfter(deadline: .now() + 10) { [weak self] in
          if let self, !self.authenticated, !self.stopped {
            self.fail("PHP did not authenticate within 10 seconds")
          }
        }
      case .failed(let error): self.fail(error.localizedDescription)
      default: break
      }
    }
    listener.start(queue: queue)
  }
  func setForeground(_ value: Bool) {
    queue.async {
      self.foreground = value
      if value || self.authenticated { tui_mobile_set_foreground(value ? 1 : 0) }
    }
  }
  func send(_ message: Message) { queue.async { self.write(message) } }
  func write(_ message: Message) {
    guard let connection else { return }
    do {
      let frame = try Frame.encode(message.encode())
      guard queued + frame.count <= Frame.maxBytes else {
        fail("Host write buffer exceeded 16 MiB")
        return
      }
      queued += frame.count
      connection.send(
        content: frame,
        completion: .contentProcessed { [weak self] error in
          guard let self else { return }
          self.queued -= frame.count
          if let error { self.fail(error.localizedDescription) }
        })
    } catch { fail(String(describing: error)) }
  }
  func fail(_ text: String) {
    guard !stopped else { return }
    close()
    DispatchQueue.main.async { self.failure?(text) }
  }
  func close() {
    tui_mobile_set_foreground(1)
    stopped = true
    listener?.cancel()
    connection?.cancel()
    for client in clients { client.connection.cancel() }
    clients.removeAll()
  }
  enum SessionError: Error { case invalid(String) }
}

final class MobileClient {
  let connection: NWConnection
  weak var owner: MobileSession?
  var decoder = Frame.Decoder()
  var authenticated = false
  init(connection: NWConnection, owner: MobileSession) {
    self.connection = connection
    self.owner = owner
  }
  func start() {
    guard let owner else { return }
    connection.start(queue: owner.queue)
    read()
  }
  func read() {
    connection.receive(minimumIncompleteLength: 1, maximumLength: 65536) {
      [weak self] data, _, complete, error in
      guard let self, let owner = self.owner else { return }
      do {
        if let data {
          self.decoder.append(data)
          while let payload = try self.decoder.next() {
            let message = try Message.decode(payload)
            if !self.authenticated {
              guard !owner.authenticated, case .auth(let token, let version) = message,
                version == 1, Token.matches(token, owner.token)
              else {
                self.connection.cancel()
                owner.clients.removeAll { $0 === self }
                return
              }
              self.authenticated = true
              owner.authenticated = true
              owner.connection = self.connection
              owner.listener?.cancel()
              for client in owner.clients where client !== self { client.connection.cancel() }
              owner.clients = [self]
              owner.write(.hello(owner.hello))
              if !owner.foreground { tui_mobile_set_foreground(0) }
            } else {
              DispatchQueue.main.async { owner.receive?(message) }
            }
          }
        }
        if let error { throw error }
        if complete {
          self.connection.cancel()
          owner.clients.removeAll { $0 === self }
          if self.authenticated { DispatchQueue.main.async { owner.ended?() } }
        } else {
          self.read()
        }
      } catch {
        self.connection.cancel()
        owner.clients.removeAll { $0 === self }
        if self.authenticated { owner.fail(String(describing: error)) }
      }
    }
  }
}
