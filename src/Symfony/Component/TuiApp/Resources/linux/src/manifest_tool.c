/* tui-app-manifest <manifest> <what> [exec]: what package.sh needs from a manifest.
 *   id | name | version | mode                    print that field
 *   desktop <exec>                                 print the .desktop entry
 *   mime                                           print the shared-mime-info XML (exit 3 when there is none) */
#include "core.h"

#include <stdio.h>
#include <string.h>

int main(int argc, char **argv)
{
    g_autoptr(GError) error = NULL;

    if (argc < 3) {
        fprintf(stderr, "usage: %s <manifest> id|name|version|mode|desktop <exec>|mime\n", argv[0]);
        return 2;
    }
    g_autoptr(TuiManifest) manifest = tui_manifest_load(argv[1], &error);
    if (NULL == manifest) {
        fprintf(stderr, "%s: %s\n", argv[1], error->message);
        return 1;
    }
    const char *what = argv[2];
    if (0 == strcmp(what, "id")) {
        printf("%s\n", manifest->id);
    } else if (0 == strcmp(what, "name")) {
        printf("%s\n", manifest->name);
    } else if (0 == strcmp(what, "version")) {
        printf("%s\n", manifest->version);
    } else if (0 == strcmp(what, "mode")) {
        printf("%s\n", manifest->mode);
    } else if (0 == strcmp(what, "desktop") && argc > 3) {
        g_autofree char *entry = tui_desktop_entry(manifest, argv[3]);
        fputs(entry, stdout);
    } else if (0 == strcmp(what, "mime")) {
        g_autofree char *xml = tui_mime_xml(manifest);
        if (NULL == xml) {
            return 3;
        }
        fputs(xml, stdout);
    } else {
        fprintf(stderr, "unknown request %s\n", what);
        return 2;
    }
    return 0;
}
