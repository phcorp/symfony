import AppKit
import TuiAppHostCore

let usage = """
usage: TuiAppHost [--self-test|--feature-test|--canvas-test] [--manifest <app.manifest.json>] [<path>…]
       TuiAppHost [--self-test|--feature-test|--canvas-test] [<path>…] --dev -- <command> [args…]
       TuiAppHost --info-plist <app.manifest.json> <executable> [<icon file>]
       TuiAppHost --bundle-name <app.manifest.json>

"""

func fail(_ message: String) -> Never {
    FileHandle.standardError.write(Data("TuiAppHost: \(message)\n".utf8))
    exit(2)
}

func readManifest(_ path: String) -> Manifest {
    guard let data = FileManager.default.contents(atPath: path) else { fail("cannot read \(path)") }
    do {
        return try Manifest.parse(data)
    } catch {
        fail(String(describing: error))
    }
}

var arguments = Array(CommandLine.arguments.dropFirst())
var options = LaunchOptions()

// Packaging helpers for build-app.sh: no window.
if arguments.first == "--info-plist" {
    guard arguments.count >= 3 else { fail(usage) }
    let manifest = readManifest(arguments[1])
    let data = try InfoPlist.xml(manifest: manifest, executable: arguments[2], iconFile: arguments.count > 3 ? arguments[3] : nil)
    FileHandle.standardOutput.write(data)
    exit(0)
}
if arguments.first == "--bundle-name" {
    guard arguments.count == 2 else { fail(usage) }
    print(InfoPlist.bundleName(readManifest(arguments[1])))
    exit(0)
}

while let argument = arguments.first {
    arguments.removeFirst()
    switch argument {
    case "--self-test":
        options.selfTest = true
    case "--feature-test":
        options.featureTest = true
    case "--canvas-test":
        options.canvasTest = true
    case "--manifest":
        guard let path = arguments.first else { fail("--manifest needs a file") }
        arguments.removeFirst()
        options.manifestPath = path
    case "--dev":
        guard arguments.first == "--", arguments.count >= 2 else { fail("--dev needs -- <command> [args…]") }
        options.development = Array(arguments.dropFirst())
        arguments = []
    case "--help", "-h":
        FileHandle.standardOutput.write(Data(usage.utf8))
        exit(0)
    default:
        if argument.hasPrefix("-psn_") || argument.hasPrefix("-NS") || argument.hasPrefix("-Apple") {
            // Launch Services and AppKit arguments: a value may follow.
            if !argument.hasPrefix("-psn_"), let next = arguments.first, !next.hasPrefix("-") { arguments.removeFirst() }
        } else if argument.hasPrefix("-") {
            fail("unknown option \(argument)\n\(usage)")
        } else {
            options.paths.append(argument)
        }
    }
}

// Paths on the command line are ours: AppKit must not open them as documents too.
UserDefaults.standard.register(defaults: ["NSTreatUnknownArgumentsAsOpen": "NO"])

let application = NSApplication.shared
let delegate = AppDelegate(options: options)
application.delegate = delegate
application.setActivationPolicy(.regular)
application.run()
