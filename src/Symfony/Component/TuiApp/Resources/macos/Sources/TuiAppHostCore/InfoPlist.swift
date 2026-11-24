import Foundation

/// The bundle's Info.plist, made from the manifest by `build-app.sh`.
public enum InfoPlist {
    public static func dictionary(manifest: Manifest, executable: String, iconFile: String?) -> [String: Any] {
        let app = manifest.app
        var plist: [String: Any] = [
            "CFBundleName": String(app.name.prefix(15)),
            "CFBundleDisplayName": app.name,
            "CFBundleIdentifier": app.id,
            "CFBundleShortVersionString": app.version,
            "CFBundleVersion": app.version,
            "CFBundleExecutable": executable,
            "CFBundlePackageType": "APPL",
            "CFBundleInfoDictionaryVersion": "6.0",
            "CFBundleDevelopmentRegion": "en",
            "LSMinimumSystemVersion": "13.0",
            "NSHighResolutionCapable": true,
            "NSPrincipalClass": "NSApplication",
            "NSSupportsAutomaticTermination": false,
            "NSSupportsSuddenTermination": false,
        ]
        if let copyright = app.copyright, !copyright.isEmpty {
            plist["NSHumanReadableCopyright"] = copyright
        }
        if let category = app.category, !category.isEmpty {
            plist["LSApplicationCategoryType"] = "public.app-category.\(category)"
        }
        if let iconFile {
            plist["CFBundleIconFile"] = iconFile
        }
        if let associations = app.fileAssociations, !associations.isEmpty {
            plist["CFBundleDocumentTypes"] = associations.map { association -> [String: Any] in
                var entry: [String: Any] = [
                    "CFBundleTypeName": association.name,
                    "CFBundleTypeRole": association.role == "viewer" ? "Viewer" : "Editor",
                    "CFBundleTypeExtensions": [association.extension],
                    "LSHandlerRank": "Owner",
                ]
                if let mime = association.mimeType, !mime.isEmpty { entry["CFBundleTypeMIMETypes"] = [mime] }
                return entry
            }
        }
        if let schemes = app.urlSchemes, !schemes.isEmpty {
            plist["CFBundleURLTypes"] = [["CFBundleURLName": app.id, "CFBundleURLSchemes": schemes]]
        }
        return plist
    }

    public static func xml(manifest: Manifest, executable: String, iconFile: String?) throws -> Data {
        try PropertyListSerialization.data(fromPropertyList: dictionary(manifest: manifest, executable: executable, iconFile: iconFile), format: .xml, options: 0)
    }

    /// A file name for the bundle and its executable: the app name without path separators.
    public static func bundleName(_ manifest: Manifest) -> String {
        let cleaned = manifest.app.name.map { "/:".contains($0) ? "-" : $0 }
        return String(cleaned).trimmingCharacters(in: .whitespaces)
    }
}
