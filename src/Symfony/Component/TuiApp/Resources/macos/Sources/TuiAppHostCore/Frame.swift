import Foundation

/// A frame: a 4-byte unsigned big-endian length, then that many bytes of UTF-8 JSON.
public enum Frame {
    public static let maxBytes = 16 * 1024 * 1024

    public enum Error: Swift.Error, Equatable {
        case tooLarge(Int)
    }

    public static func encode(_ payload: Data) throws -> Data {
        guard payload.count <= maxBytes else {
            throw Error.tooLarge(payload.count)
        }
        let length = UInt32(payload.count)
        var frame = Data([UInt8(length >> 24 & 0xff), UInt8(length >> 16 & 0xff), UInt8(length >> 8 & 0xff), UInt8(length & 0xff)])
        frame.append(payload)
        return frame
    }

    /// Accumulates bytes from a stream and hands out whole payloads.
    public struct Decoder: Sendable {
        private var buffer = Data()
        private var offset = 0

        public init() {}

        public mutating func append(_ bytes: Data) {
            if offset > 0 {
                buffer = Data(buffer.dropFirst(offset))
                offset = 0
            }
            buffer.append(bytes)
        }

        /// The next whole payload, nil when more bytes are needed. Throws on a frame over the limit.
        public mutating func next() throws -> Data? {
            guard buffer.count - offset >= 4 else {
                return nil
            }
            let start = buffer.startIndex + offset
            let length = Int(buffer[start]) << 24 | Int(buffer[start + 1]) << 16 | Int(buffer[start + 2]) << 8 | Int(buffer[start + 3])
            guard length <= Frame.maxBytes else {
                throw Error.tooLarge(length)
            }
            guard buffer.count - offset >= 4 + length else {
                return nil
            }
            let payload = Data(buffer[(start + 4)..<(start + 4 + length)])
            offset += 4 + length
            if offset == buffer.count {
                buffer = Data()
                offset = 0
            }
            return payload
        }
    }
}

public enum Hex {
    public static func encode(_ data: Data) -> String {
        data.map { String(format: "%02x", $0) }.joined()
    }

    public static func decode(_ text: String) -> Data? {
        var bytes = [UInt8]()
        var index = text.startIndex
        while index < text.endIndex {
            let next = text.index(index, offsetBy: 2, limitedBy: text.endIndex) ?? text.endIndex
            guard let byte = UInt8(text[index..<next], radix: 16) else { return nil }
            bytes.append(byte)
            index = next
        }
        return Data(bytes)
    }
}

/// The connection token: 16 random bytes in lowercase hex, compared in constant time.
public enum Token {
    public static func make() -> String {
        var generator = SystemRandomNumberGenerator()
        return (0..<16).map { _ in String(format: "%02x", UInt8.random(in: 0...255, using: &generator)) }.joined()
    }

    public static func matches(_ given: String, _ expected: String) -> Bool {
        let a = Array(given.utf8)
        let b = Array(expected.utf8)
        var difference = UInt8(a.count == b.count ? 0 : 1)
        for index in 0..<b.count {
            difference |= (index < a.count ? a[index] : 0) ^ b[index]
        }
        return difference == 0
    }
}
