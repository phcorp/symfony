/* The core against contract/fixtures, plus the host-side rules HOSTS.md sets. */
#include "../src/core.h"

#include <string.h>

static JsonNode *load_fixture(const char *name)
{
    g_autofree char *path = g_build_filename(CONTRACT_DIR, "fixtures", name, NULL);
    g_autoptr(JsonParser) parser = json_parser_new();
    g_autoptr(GError) error = NULL;
    g_assert_true(json_parser_load_from_file(parser, path, &error));
    g_assert_no_error(error);
    return json_node_copy(json_parser_get_root(parser));
}

static guint8 *from_hex(const char *hex, gsize *length)
{
    *length = strlen(hex) / 2;
    guint8 *bytes = g_malloc(*length);
    for (gsize i = 0; i < *length; i++) {
        bytes[i] = (guint8) (g_ascii_xdigit_value(hex[2 * i]) * 16 + g_ascii_xdigit_value(hex[2 * i + 1]));
    }
    return bytes;
}

static JsonNode *parse_json(const char *json)
{
    g_autoptr(JsonParser) parser = json_parser_new();
    g_assert_true(json_parser_load_from_data(parser, json, -1, NULL));
    return json_node_copy(json_parser_get_root(parser));
}

/* ---- frames.json ---- */

static void test_frames_encode_byte_for_byte(void)
{
    g_autoptr(JsonNode) root = load_fixture("frames.json");
    JsonArray *frames = json_object_get_array_member(json_node_get_object(root), "frames");

    g_assert_cmpuint(json_array_get_length(frames), >, 0);
    for (guint i = 0; i < json_array_get_length(frames); i++) {
        JsonObject *frame = json_array_get_object_element(frames, i);
        const char *json = json_object_get_string_member(frame, "json");
        gsize length;
        g_autofree guint8 *expected = from_hex(json_object_get_string_member(frame, "hex"), &length);
        g_autoptr(GBytes) encoded = tui_frame_encode(json, strlen(json));
        gsize encoded_length;
        const guint8 *data = g_bytes_get_data(encoded, &encoded_length);

        g_test_message("frame %s", json_object_get_string_member(frame, "name"));
        g_assert_cmpmem(data, encoded_length, expected, length);
    }
}

static void test_frames_decode_byte_for_byte_even_split(void)
{
    g_autoptr(JsonNode) root = load_fixture("frames.json");
    JsonArray *frames = json_object_get_array_member(json_node_get_object(root), "frames");
    GByteArray *stream = g_byte_array_new();

    for (guint i = 0; i < json_array_get_length(frames); i++) {
        gsize length;
        g_autofree guint8 *bytes = from_hex(json_object_get_string_member(json_array_get_object_element(frames, i), "hex"), &length);
        g_byte_array_append(stream, bytes, (guint) length);
    }
    /* Every split point of the concatenated stream decodes the same frames. */
    for (guint split = 0; split <= stream->len; split++) {
        TuiDecoder *decoder = tui_decoder_new();
        g_autoptr(GPtrArray) found = g_ptr_array_new_with_free_func(g_free);
        g_assert_true(tui_decoder_feed(decoder, stream->data, split, found, NULL));
        g_assert_true(tui_decoder_feed(decoder, stream->data + split, stream->len - split, found, NULL));
        g_assert_cmpuint(found->len, ==, json_array_get_length(frames));
        for (guint i = 0; i < found->len; i++) {
            g_assert_cmpstr(found->pdata[i], ==, json_object_get_string_member(json_array_get_object_element(frames, i), "json"));
        }
        tui_decoder_free(decoder);
    }
    g_byte_array_unref(stream);
}

static void test_an_oversized_frame_is_refused(void)
{
    const guint8 header[] = {0x01, 0x00, 0x00, 0x01};
    TuiDecoder *decoder = tui_decoder_new();
    g_autoptr(GPtrArray) found = g_ptr_array_new_with_free_func(g_free);
    g_autoptr(GError) error = NULL;

    g_assert_false(tui_decoder_feed(decoder, header, sizeof header, found, &error));
    g_assert_nonnull(error);
    tui_decoder_free(decoder);
}

/* ---- messages.json ---- */

static void test_messages_round_trip(void)
{
    g_autoptr(JsonNode) root = load_fixture("messages.json");
    JsonArray *messages = json_node_get_array(root);

    g_assert_cmpuint(json_array_get_length(messages), >=, 30);
    for (guint i = 0; i < json_array_get_length(messages); i++) {
        JsonObject *entry = json_array_get_object_element(messages, i);
        JsonNode *expected = json_object_get_member(entry, "message");
        g_autoptr(GError) error = NULL;

        g_test_message("message %s", json_object_get_string_member(entry, "name"));
        /* Through text, as it arrives on the wire. */
        g_autofree char *wire = json_to_string(expected, FALSE);
        g_autoptr(TuiMessage) message = tui_message_parse(wire, -1, &error);
        g_assert_no_error(error);
        g_assert_nonnull(message);
        g_assert_cmpint(message->type, !=, TUI_MSG_UNKNOWN);

        g_autofree char *encoded = tui_message_to_json(message);
        g_autoptr(JsonNode) back = parse_json(encoded);
        if (!tui_json_equal(expected, back)) {
            g_error("%s encodes back as %s, not %s", json_object_get_string_member(entry, "name"), encoded, wire);
        }
    }
}

static void test_json_equality_is_structural(void)
{
    g_autoptr(JsonNode) a = parse_json("{\"a\":[1,2.0,{\"b\":null}],\"c\":\"x\"}");
    g_autoptr(JsonNode) b = parse_json("{\"c\":\"x\",\"a\":[1.0,2,{\"b\":null}]}");
    g_autoptr(JsonNode) c = parse_json("{\"c\":\"x\",\"a\":[1.0,2,{\"b\":false}]}");
    g_autoptr(JsonNode) d = parse_json("{\"c\":\"x\",\"a\":[1.0,2,{}]}");

    g_assert_true(tui_json_equal(a, b));
    g_assert_false(tui_json_equal(a, c));
    g_assert_false(tui_json_equal(a, d));
}

static void test_bad_messages_are_refused_and_unknown_ones_kept(void)
{
    g_autoptr(GError) error = NULL;
    g_autoptr(TuiMessage) unknown = tui_message_parse("{\"type\":\"future\",\"x\":1}", -1, &error);

    g_assert_no_error(error);
    g_assert_cmpint(unknown->type, ==, TUI_MSG_UNKNOWN);
    g_assert_null(tui_message_parse("[1]", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"auth\",\"protocol\":1}", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"patch\",\"ops\":[{\"op\":\"create\",\"id\":1}]}", -1, NULL));
    g_assert_null(tui_message_parse("not json", -1, NULL));

    /* PHP's json_encode writes empty props as []: they read as {} (tools/fake-app.php sends them so). */
    g_autoptr(TuiMessage) php = tui_message_parse("{\"type\":\"patch\",\"ops\":[{\"op\":\"create\",\"id\":1,\"kind\":\"stack\","
                                                  "\"parent\":null,\"index\":0,\"props\":[]}]}", -1, &error);
    g_assert_no_error(error);
    g_assert_cmpuint(json_object_get_size(((TuiOp *) php->ops->pdata[0])->props), ==, 0);
    g_assert_null(tui_message_parse("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":1,\"props\":[1]}]}", -1, NULL));
}

/* pointer: button and modifiers always, null button, rows and columns with wheel only */
static void test_pointer_messages(void)
{
    g_autoptr(GError) error = NULL;
    g_autoptr(TuiMessage) wheel = tui_message_parse("{\"type\":\"pointer\",\"node\":21,\"action\":\"wheel\",\"row\":-2,\"column\":12,"
                                                    "\"button\":null,\"modifiers\":[\"ctrl\",\"super\"],\"rows\":-3,\"columns\":1}", -1, &error);
    g_assert_no_error(error);
    g_assert_cmpint(wheel->type, ==, TUI_MSG_POINTER);
    g_assert_cmpint(wheel->node, ==, 21);
    g_assert_cmpstr(wheel->action, ==, "wheel");
    g_assert_cmpint(wheel->row, ==, -2);
    g_assert_cmpint(wheel->column, ==, 12);
    g_assert_null(wheel->button);
    g_assert_cmpstr(wheel->modifiers[0], ==, "ctrl");
    g_assert_cmpstr(wheel->modifiers[1], ==, "super");
    g_assert_null(wheel->modifiers[2]);
    g_assert_true(wheel->has_scroll);
    g_assert_cmpint(wheel->scroll_rows, ==, -3);
    g_assert_cmpint(wheel->scroll_columns, ==, 1);

    TuiMessage *press = tui_message_new(TUI_MSG_POINTER);
    press->node = 21;
    press->action = g_strdup("press");
    press->row = 3;
    press->column = 12;
    press->button = g_strdup("left");
    press->modifiers = g_new0(char *, 1);
    g_autofree char *json = tui_message_to_json(press);
    g_assert_cmpstr(json, ==, "{\"type\":\"pointer\",\"node\":21,\"action\":\"press\",\"row\":3,\"column\":12,\"button\":\"left\",\"modifiers\":[]}");
    tui_message_free(press);

    /* button and modifiers are required */
    g_assert_null(tui_message_parse("{\"type\":\"pointer\",\"node\":21,\"action\":\"move\",\"row\":0,\"column\":0,\"modifiers\":[]}", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"pointer\",\"node\":21,\"action\":\"move\",\"row\":0,\"column\":0,\"button\":null}", -1, NULL));
}

/* cursor: the contract fixture, every keyword of the contract's list and no other */
static void test_cursor_messages(void)
{
    g_autoptr(JsonNode) messages_root = load_fixture("messages.json");
    JsonArray *messages = json_node_get_array(messages_root);
    gboolean found = FALSE;
    for (guint i = 0; i < json_array_get_length(messages); i++) {
        JsonObject *entry = json_array_get_object_element(messages, i);
        if (0 != g_strcmp0(json_object_get_string_member(entry, "name"), "cursor")) {
            continue;
        }
        g_autoptr(GError) error = NULL;
        g_autoptr(TuiMessage) message = tui_message_from_node(json_object_get_member(entry, "message"), &error);
        g_assert_no_error(error);
        g_assert_cmpint(message->type, ==, TUI_MSG_CURSOR);
        g_assert_cmpint(message->node, ==, 7);
        g_assert_cmpstr(message->shape, ==, "pointer");
        found = TRUE;
    }
    g_assert_true(found);

    g_autofree char *path = g_build_filename(CONTRACT_DIR, "protocol.json", NULL);
    g_autoptr(JsonParser) parser = json_parser_new();
    g_assert_true(json_parser_load_from_file(parser, path, NULL));
    JsonObject *defs = json_object_get_object_member(json_node_get_object(json_parser_get_root(parser)), "$defs");
    JsonObject *properties = json_object_get_object_member(json_object_get_object_member(defs, "cursor"), "properties");
    JsonArray *shapes = json_object_get_array_member(json_object_get_object_member(properties, "shape"), "enum");
    g_assert_cmpuint(json_array_get_length(shapes), ==, 35);
    for (guint i = 0; i < json_array_get_length(shapes); i++) {
        const char *shape = json_array_get_string_element(shapes, i);
        g_autofree char *json = g_strdup_printf("{\"type\":\"cursor\",\"node\":21,\"shape\":\"%s\"}", shape);
        g_autoptr(TuiMessage) message = tui_message_parse(json, -1, NULL);
        g_assert_true(tui_cursor_shape_known(shape));
        g_assert_nonnull(message);
        g_assert_cmpstr(message->shape, ==, shape);
    }
    g_assert_false(tui_cursor_shape_known("hand"));
    g_assert_false(tui_cursor_shape_known("Pointer"));
    g_assert_false(tui_cursor_shape_known(""));
    g_assert_false(tui_cursor_shape_known(NULL));

    /* A keyword not in the list is the arrow; node and a string shape are required */
    g_autoptr(TuiMessage) unknown = tui_message_parse("{\"type\":\"cursor\",\"node\":21,\"shape\":\"hand2\"}", -1, NULL);
    g_assert_nonnull(unknown);
    g_assert_cmpstr(unknown->shape, ==, "default");
    g_assert_null(tui_message_parse("{\"type\":\"cursor\",\"node\":21}", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"cursor\",\"node\":21,\"shape\":null}", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"cursor\",\"shape\":\"text\"}", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"cursor\",\"node\":null,\"shape\":\"text\"}", -1, NULL));
}

/* The fixture of that name, parsed */
static TuiMessage *fixture_message(const char *name)
{
    g_autoptr(JsonNode) root = load_fixture("messages.json");
    JsonArray *messages = json_node_get_array(root);
    for (guint i = 0; i < json_array_get_length(messages); i++) {
        JsonObject *entry = json_array_get_object_element(messages, i);
        if (0 == g_strcmp0(json_object_get_string_member(entry, "name"), name)) {
            g_autoptr(GError) error = NULL;
            TuiMessage *message = tui_message_from_node(json_object_get_member(entry, "message"), &error);
            g_assert_no_error(error);
            return message;
        }
    }
    g_error("no fixture %s", name);
    return NULL;
}

/* contextmenu: its items in order, a separator, a disabled one, the paste role; contextmenu-select its id;
 * clipboard its text; required members refused when missing */
static void test_contextmenu_and_clipboard_messages(void)
{
    g_autoptr(TuiMessage) menu = fixture_message("contextmenu");
    g_assert_cmpint(menu->type, ==, TUI_MSG_CONTEXTMENU);
    g_assert_cmpint(menu->node, ==, 7);
    g_assert_cmpint(menu->row, ==, 3);
    g_assert_cmpint(menu->column, ==, 12);
    g_assert_cmpuint(menu->items->len, ==, 5);
    TuiMenuItem *open = menu->items->pdata[0];
    TuiMenuItem *separator = menu->items->pdata[2];
    TuiMenuItem *paste = menu->items->pdata[3];
    TuiMenuItem *disabled = menu->items->pdata[4];
    g_assert_cmpstr(open->id, ==, "1");
    g_assert_cmpstr(open->label, ==, "Open Link");
    g_assert_false(open->has_enabled);
    g_assert_null(open->role);
    g_assert_true(separator->has_separator && separator->separator);
    g_assert_cmpstr(paste->role, ==, "paste");
    g_assert_cmpstr(paste->label, ==, "Paste");
    g_assert_true(disabled->has_enabled);
    g_assert_false(disabled->enabled);

    g_autoptr(TuiMessage) select = fixture_message("contextmenu-select");
    g_assert_cmpint(select->type, ==, TUI_MSG_CONTEXTMENU_SELECT);
    g_assert_cmpstr(select->id, ==, "2");
    g_autoptr(TuiMessage) clipboard = fixture_message("clipboard");
    g_assert_cmpint(clipboard->type, ==, TUI_MSG_CLIPBOARD);
    g_assert_cmpstr(clipboard->text, ==, "https://example.com/docs");

    /* The host sends contextmenu-select */
    g_autoptr(TuiMessage) sent = tui_message_new(TUI_MSG_CONTEXTMENU_SELECT);
    sent->id = g_strdup("4");
    g_autofree char *json = tui_message_to_json(sent);
    g_assert_cmpstr(json, ==, "{\"type\":\"contextmenu-select\",\"id\":\"4\"}");

    g_assert_null(tui_message_parse("{\"type\":\"contextmenu\",\"node\":7,\"row\":0,\"column\":0}", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"contextmenu\",\"row\":0,\"column\":0,\"items\":[]}", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"contextmenu\",\"node\":7,\"column\":0,\"items\":[]}", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"contextmenu\",\"node\":7,\"row\":0,\"column\":0,\"items\":[{\"id\":\"1\"}]}", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"contextmenu\",\"node\":7,\"row\":0,\"column\":0,\"items\":[{\"label\":\"Open\"}]}", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"contextmenu\",\"node\":7,\"row\":0,\"column\":0,\"items\":[{\"separator\":false}]}", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"contextmenu\",\"node\":7,\"row\":0,\"column\":0,\"items\":[42]}", -1, NULL));
    g_autoptr(TuiMessage) empty = tui_message_parse("{\"type\":\"contextmenu\",\"node\":7,\"row\":0,\"column\":0,\"items\":[]}", -1, NULL);
    g_assert_nonnull(empty);
    g_assert_cmpuint(empty->items->len, ==, 0);
    g_assert_null(tui_message_parse("{\"type\":\"contextmenu-select\"}", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"clipboard\"}", -1, NULL));
    g_assert_null(tui_message_parse("{\"type\":\"clipboard\",\"text\":null}", -1, NULL));
    g_autoptr(TuiMessage) blank = tui_message_parse("{\"type\":\"clipboard\",\"text\":\"\"}", -1, NULL);
    g_assert_nonnull(blank);
    g_assert_cmpstr(blank->text, ==, "");
}

/* ---- session.json ---- */

static void test_session_trees(void)
{
    g_autoptr(JsonNode) messages_root = load_fixture("messages.json");
    g_autoptr(JsonNode) session = load_fixture("session.json");
    JsonArray *messages = json_node_get_array(messages_root);
    JsonArray *steps = json_object_get_array_member(json_node_get_object(session), "steps");
    TuiTree *tree = tui_tree_new();

    for (guint i = 0; i < json_array_get_length(steps); i++) {
        JsonObject *step = json_array_get_object_element(steps, i);
        const char *name = json_object_get_string_member(step, "patch");
        JsonNode *patch = NULL;
        for (guint j = 0; j < json_array_get_length(messages); j++) {
            if (0 == g_strcmp0(json_object_get_string_member(json_array_get_object_element(messages, j), "name"), name)) {
                patch = json_object_get_member(json_array_get_object_element(messages, j), "message");
            }
        }
        g_assert_nonnull(patch);
        g_autoptr(TuiMessage) message = tui_message_from_node(patch, NULL);
        g_assert_nonnull(message);
        g_assert_cmpint(message->type, ==, TUI_MSG_PATCH);

        TuiChanges changes;
        g_autoptr(GError) error = NULL;
        tui_changes_init(&changes);
        g_assert_true(tui_tree_apply(tree, message, &changes, &error));
        g_assert_no_error(error);
        g_autoptr(JsonNode) actual = tui_tree_to_node(tree);
        if (!tui_json_equal(actual, json_object_get_member(step, "tree"))) {
            g_autofree char *text = json_to_string(actual, TRUE);
            g_error("after %s the tree is\n%s", name, text);
        }
        /* The sorted-keys rule: the serialized props come out sorted. */
        if (0 == i) {
            g_assert_cmpuint(changes.created->len, ==, 3);
            g_assert_cmpuint(changes.relinked->len, ==, 1);
        }
        if (2 == i) {
            /* update 2, move 3, remove 3 */
            g_assert_cmpuint(changes.updated->len, ==, 1);
            g_assert_cmpuint(changes.removed->len, ==, 1);
            g_assert_cmpint(g_array_index(changes.removed, gint64, 0), ==, 3);
        }
        tui_changes_clear(&changes);
    }
    tui_tree_free(tree);
}

static void test_removing_a_node_removes_its_descendants(void)
{
    TuiTree *tree = tui_tree_new();
    g_autoptr(TuiMessage) create = tui_message_parse("{\"type\":\"patch\",\"ops\":["
                                                     "{\"op\":\"create\",\"id\":1,\"kind\":\"stack\",\"parent\":null,\"index\":0,\"props\":{}},"
                                                     "{\"op\":\"create\",\"id\":2,\"kind\":\"stack\",\"parent\":1,\"index\":0,\"props\":{}},"
                                                     "{\"op\":\"create\",\"id\":3,\"kind\":\"label\",\"parent\":2,\"index\":0,\"props\":{\"text\":\"a\"}},"
                                                     "{\"op\":\"create\",\"id\":4,\"kind\":\"label\",\"parent\":1,\"index\":0,\"props\":{}}]}",
        -1, NULL);
    g_autoptr(TuiMessage) remove = tui_message_parse("{\"type\":\"patch\",\"ops\":[{\"op\":\"remove\",\"id\":2},{\"op\":\"update\",\"id\":3,\"props\":{}}]}", -1, NULL);
    TuiChanges changes;

    tui_changes_init(&changes);
    g_assert_true(tui_tree_apply(tree, create, &changes, NULL));
    /* Index 0 puts node 4 before node 2. */
    g_assert_cmpint(((TuiNode *) tui_tree_get(tree, 1)->children->pdata[0])->id, ==, 4);
    tui_changes_clear(&changes);
    tui_changes_init(&changes);
    g_assert_false(tui_tree_apply(tree, remove, &changes, NULL)); /* the update of 3 finds nothing */
    g_assert_cmpuint(changes.removed->len, ==, 2);
    g_assert_null(tui_tree_get(tree, 3));
    g_assert_cmpuint(g_hash_table_size(tree->nodes), ==, 2);
    tui_changes_clear(&changes);
    tui_tree_free(tree);
}

static void test_moves_keep_the_index_and_null_resets_a_prop(void)
{
    TuiTree *tree = tui_tree_new();
    g_autoptr(TuiMessage) create = tui_message_parse("{\"type\":\"patch\",\"ops\":["
                                                     "{\"op\":\"create\",\"id\":1,\"kind\":\"stack\",\"parent\":null,\"index\":0,\"props\":{}},"
                                                     "{\"op\":\"create\",\"id\":2,\"kind\":\"label\",\"parent\":1,\"index\":0,\"props\":{\"style\":{\"bold\":true}}},"
                                                     "{\"op\":\"create\",\"id\":3,\"kind\":\"label\",\"parent\":1,\"index\":1,\"props\":{}},"
                                                     "{\"op\":\"create\",\"id\":4,\"kind\":\"label\",\"parent\":1,\"index\":2,\"props\":{}}]}",
        -1, NULL);
    g_autoptr(TuiMessage) change = tui_message_parse("{\"type\":\"patch\",\"ops\":[{\"op\":\"move\",\"id\":2,\"parent\":1,\"index\":1},"
                                                     "{\"op\":\"update\",\"id\":2,\"props\":{\"style\":null,\"text\":\"a\"}}]}",
        -1, NULL);

    g_assert_true(tui_tree_apply(tree, create, NULL, NULL));
    g_assert_true(tui_tree_apply(tree, change, NULL, NULL));
    GPtrArray *children = tui_tree_get(tree, 1)->children;
    g_assert_cmpint(((TuiNode *) children->pdata[0])->id, ==, 3);
    g_assert_cmpint(((TuiNode *) children->pdata[1])->id, ==, 2);
    g_assert_cmpint(((TuiNode *) children->pdata[2])->id, ==, 4);
    /* null goes back to the default: the prop is gone, not stored as null. */
    g_assert_false(json_object_has_member(tui_tree_get(tree, 2)->props, "style"));
    g_assert_cmpstr(tui_prop_string(tui_tree_get(tree, 2)->props, "text", NULL), ==, "a");
    tui_tree_free(tree);
}

/* ---- manifest.json ---- */

static void test_manifest_parses(void)
{
    g_autofree char *path = g_build_filename(CONTRACT_DIR, "fixtures", "manifest.json", NULL);
    g_autoptr(GError) error = NULL;
    g_autoptr(TuiManifest) manifest = tui_manifest_load(path, &error);

    g_assert_no_error(error);
    g_assert_cmpstr(manifest->name, ==, "Acme Notes");
    g_assert_cmpstr(manifest->id, ==, "com.acme.notes");
    g_assert_cmpstr(manifest->version, ==, "1.2.0");
    g_assert_true(manifest->single_instance);
    g_assert_cmpint(manifest->width, ==, 900);
    g_assert_cmpint(manifest->height, ==, 640);
    g_assert_cmpint(manifest->min_width, ==, 480);
    g_assert_true(manifest->remember_geometry);
    g_assert_cmpstr(manifest->theme, ==, "system");
    g_assert_null(manifest->font_family);
    g_assert_cmpfloat(manifest->font_size, ==, 13);
    g_assert_true(manifest->menu_about);
    g_assert_cmpstr(manifest->mode, ==, "embedded");
    g_assert_cmpstr(manifest->log_level, ==, "warning");
    g_assert_cmpstr(manifest->categories[0], ==, "Office");
    g_assert_null(manifest->categories[1]);
    g_assert_cmpuint(manifest->file_associations->len, ==, 1);
    TuiFileAssociation *association = manifest->file_associations->pdata[0];
    g_assert_cmpstr(association->extension, ==, "acme");
    g_assert_cmpstr(association->name, ==, "Acme note");
    g_assert_cmpstr(association->role, ==, "editor");
    g_assert_cmpstr(association->mime_type, ==, "application/x-com.acme.notes-acme");
    g_assert_true(association->generated);
    g_assert_cmpstr(manifest->url_schemes[0], ==, "acme");
    g_assert_null(manifest->url_schemes[1]);
    g_autofree char *entry = tui_desktop_entry(manifest, "com.acme.notes");
    g_assert_nonnull(strstr(entry, "\nMimeType=application/x-com.acme.notes-acme;x-scheme-handler/acme;\n"));
    g_assert_nonnull(strstr(entry, "\nCategories=Office;\n"));

    g_auto(GStrv) argv = tui_launch_argv(manifest, NULL, &error);
    g_assert_no_error(error);
    g_autofree char *dir = g_path_get_dirname(path);
    g_autofree char *canonical = g_canonicalize_filename(dir, NULL);
    g_autofree char *php = g_build_filename(canonical, "runtime/php", NULL);
    g_autofree char *ini = g_build_filename(canonical, "./runtime/php.ini", NULL);
    g_assert_cmpstr(argv[0], ==, php);
    g_assert_cmpstr(argv[1], ==, "-n");
    g_assert_cmpstr(argv[2], ==, "-c");
    g_assert_cmpstr(argv[3], ==, ini);
    g_assert_null(argv[5]);
}

static void test_bad_manifests_are_refused(void)
{
    g_assert_null(tui_manifest_parse("{\"manifest\":2}", -1, "/", NULL));
    g_assert_null(tui_manifest_parse("{\"manifest\":1,\"app\":{\"name\":\"a\"}}", -1, "/", NULL));
    g_assert_null(tui_manifest_parse("[]", -1, "/", NULL));
}

static const char *system_manifest = "{\"manifest\":1,"
                                     "\"app\":{\"name\":\"Fake; app\",\"id\":\"org.example.Fake\",\"version\":\"1.0\",\"description\":\"Line\\none\","
                                     "\"category\":\"finance\","
                                     "\"fileAssociations\":[{\"extension\":\"fake\",\"name\":\"Fake doc\",\"mimeType\":null,\"role\":\"editor\"},"
                                     "{\"extension\":\"md\",\"name\":\"Markdown\",\"mimeType\":\"text/markdown\",\"role\":\"viewer\"}],"
                                     "\"urlSchemes\":[\"fake\",\"fake-two\"]},"
                                     "\"window\":{\"title\":\"Fake\",\"width\":800,\"height\":600},"
                                     "\"menu\":{},"
                                     "\"launch\":{\"mode\":\"system\",\"args\":[\"./app/main.php\",\"--flag\"],\"php\":{\"path\":null,\"minVersion\":\"8.2\"},"
                                     "\"env\":{\"pass\":[\"KEEP_ME\"],\"set\":{\"APP_ENV\":\"prod\",\"PATH\":\"/override\"}}},"
                                     "\"logging\":{}}";

static void test_system_mode_runs_php_with_resolved_args(void)
{
    g_autoptr(TuiManifest) manifest = tui_manifest_parse(system_manifest, -1, "/opt/fake/share/org.example.Fake", NULL);
    g_autoptr(GError) error = NULL;

    g_assert_nonnull(manifest);
    g_assert_null(tui_launch_argv(manifest, NULL, NULL));
    g_auto(GStrv) argv = tui_launch_argv(manifest, "/usr/bin/php", &error);
    g_assert_no_error(error);
    g_assert_cmpstr(argv[0], ==, "/usr/bin/php");
    g_assert_cmpstr(argv[1], ==, "/opt/fake/share/org.example.Fake/./app/main.php");
    g_assert_cmpstr(argv[2], ==, "--flag");
    g_assert_cmpstr(manifest->php_min_version, ==, "8.2");
}

static void test_versions_compare_numerically(void)
{
    g_assert_cmpint(tui_version_compare("8.3.6", "8.2"), ==, 1);
    g_assert_cmpint(tui_version_compare("8.2.10", "8.2.9"), ==, 1);
    g_assert_cmpint(tui_version_compare("8.2", "8.2.0"), ==, 0);
    g_assert_cmpint(tui_version_compare("8.1.27", "8.2"), ==, -1);
    g_assert_cmpint(tui_version_compare("8.4.0-dev", "8.4"), ==, 0);
    g_assert_cmpint(tui_version_compare("10.0", "9.9"), ==, 1);
}

static void test_the_environment_keeps_the_allowlist_only(void)
{
    g_autoptr(TuiManifest) manifest = tui_manifest_parse(system_manifest, -1, "/", NULL);
    char *inherited[] = {"PATH=/usr/bin", "HOME=/home/ada", "SECRET=1", "LC_ALL=C", "XDG_RUNTIME_DIR=/run", "KEEP_ME=yes",
        "DISPLAY=:1", "TUI_APP_TOKEN=parent", "LD_PRELOAD=x", "SystemRoot=C", "NOEQUALS", "USER=ada", "LANG=fr_FR.UTF-8", "TMPDIR=/t",
        "TEMP=/t", "TMP=/t", "APPDATA=a", "LOCALAPPDATA=l", "WAYLAND_DISPLAY=w", "LANGUAGE=fr", "XDGX=no", NULL};
    const char *kept[] = {"USER", "LANG", "TMPDIR", "TEMP", "TMP", "APPDATA", "LOCALAPPDATA", "WAYLAND_DISPLAY"};
    g_auto(GStrv) env = tui_launch_env(inherited, manifest, "tcp://127.0.0.1:1234", "abc");

    g_assert_cmpstr(g_environ_getenv(env, "HOME"), ==, "/home/ada");
    g_assert_cmpstr(g_environ_getenv(env, "PATH"), ==, "/override"); /* set wins */
    g_assert_cmpstr(g_environ_getenv(env, "LC_ALL"), ==, "C");
    g_assert_cmpstr(g_environ_getenv(env, "XDG_RUNTIME_DIR"), ==, "/run");
    g_assert_cmpstr(g_environ_getenv(env, "KEEP_ME"), ==, "yes");
    g_assert_cmpstr(g_environ_getenv(env, "DISPLAY"), ==, ":1");
    g_assert_cmpstr(g_environ_getenv(env, "SystemRoot"), ==, "C");
    g_assert_cmpstr(g_environ_getenv(env, "APP_ENV"), ==, "prod");
    g_assert_null(g_environ_getenv(env, "SECRET"));
    g_assert_null(g_environ_getenv(env, "LD_PRELOAD"));
    g_assert_null(g_environ_getenv(env, "LANGUAGE"));
    g_assert_null(g_environ_getenv(env, "XDGX"));
    for (guint i = 0; i < G_N_ELEMENTS(kept); i++) {
        g_assert_nonnull(g_environ_getenv(env, kept[i]));
    }
    g_assert_cmpstr(g_environ_getenv(env, "TUI_APP_ADDR"), ==, "tcp://127.0.0.1:1234");
    g_assert_cmpstr(g_environ_getenv(env, "TUI_APP_TOKEN"), ==, "abc");
}

static void apply_json(TuiTree *tree, const char *json)
{
    g_autoptr(GError) error = NULL;
    g_autoptr(TuiMessage) patch = tui_message_parse(json, -1, &error);
    TuiChanges changes;
    g_assert_no_error(error);
    tui_changes_init(&changes);
    g_assert_true(tui_tree_apply(tree, patch, &changes, NULL));
    tui_changes_clear(&changes);
}

static JsonNode *parse_node(const char *json)
{
    return json_from_string(json, NULL);
}

/* What the user changes on a control is its prop from then on, as the application takes it. */
static void test_acknowledged_edits_are_kept(void)
{
    TuiTree *tree = tui_tree_new();
    apply_json(tree, "{\"type\":\"patch\",\"ops\":["
                     "{\"op\":\"create\",\"id\":1,\"kind\":\"stack\",\"parent\":null,\"index\":0,\"props\":{}},"
                     "{\"op\":\"create\",\"id\":2,\"kind\":\"textfield\",\"parent\":1,\"index\":0,\"props\":{\"value\":\"Ada\",\"maxLength\":40}},"
                     "{\"op\":\"create\",\"id\":3,\"kind\":\"checkbox\",\"parent\":1,\"index\":1,\"props\":{\"checked\":false}},"
                     "{\"op\":\"create\",\"id\":4,\"kind\":\"list\",\"parent\":1,\"index\":2,\"props\":{\"multiple\":true}},"
                     "{\"op\":\"create\",\"id\":5,\"kind\":\"listbox\",\"parent\":1,\"index\":3,\"props\":{\"selected\":[0]}},"
                     "{\"op\":\"create\",\"id\":6,\"kind\":\"slider\",\"parent\":1,\"index\":4,\"props\":{\"value\":5}}]}");
    g_autoptr(JsonNode) typed = parse_node("\"Ada Lovelace\"");
    g_autoptr(JsonNode) checked = parse_node("true");
    g_autoptr(JsonNode) rows = parse_node("[0,2]");
    g_autoptr(JsonNode) half = parse_node("7.5");
    g_autoptr(JsonNode) index = parse_node("1");

    g_assert_true(tui_tree_acknowledge(tree, 2, "change", typed));
    g_assert_false(tui_tree_acknowledge(tree, 2, "change", typed)); /* no change */
    /* The application lowers the limit: the value it took stays */
    apply_json(tree, "{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":2,\"props\":{\"maxLength\":30}}]}");
    g_assert_cmpstr(tui_prop_string(tui_tree_get(tree, 2)->props, "value", NULL), ==, "Ada Lovelace");
    g_assert_true(tui_tree_acknowledge(tree, 3, "toggle", checked));
    g_assert_true(tui_prop_bool(tui_tree_get(tree, 3)->props, "checked", FALSE));
    g_assert_true(tui_tree_acknowledge(tree, 4, "select", rows));
    g_assert_cmpuint(json_array_get_length(tui_prop_array(tui_tree_get(tree, 4)->props, "checked")), ==, 2);
    g_assert_true(tui_tree_acknowledge(tree, 5, "select", rows));
    g_assert_cmpint(json_array_get_int_element(tui_prop_array(tui_tree_get(tree, 5)->props, "selected"), 1), ==, 2);
    g_assert_true(tui_tree_acknowledge(tree, 6, "change", half));
    g_assert_false(tui_tree_acknowledge(tree, 2, "cancel", NULL));
    g_assert_false(tui_tree_acknowledge(tree, 3, "select", index));
    g_assert_false(tui_tree_acknowledge(tree, 99, "change", typed));
    /* Its own update wins */
    apply_json(tree, "{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":2,\"props\":{\"value\":\"Ada\"}}]}");
    g_assert_cmpstr(tui_prop_string(tui_tree_get(tree, 2)->props, "value", NULL), ==, "Ada");
    tui_tree_free(tree);
}

static void test_dev_mode_keeps_the_environment_and_command(void)
{
    char *command[] = {"php", "./tools/fake-app.php", NULL};
    g_autoptr(TuiManifest) manifest = tui_manifest_dev(command);
    char *inherited[] = {"SECRET=1", "TUI_APP_TOKEN=parent", "TUI_APP_ADDR=tcp://127.0.0.1:9", "TUI_APP_AUTOLOAD=/a.php", "TUI_APP_CANVAS_CAPTURE=/c", NULL};
    g_auto(GStrv) env = tui_launch_env(inherited, manifest, "tcp://127.0.0.1:1", "t");
    g_auto(GStrv) argv = tui_launch_argv(manifest, NULL, NULL);

    g_assert_cmpstr(g_environ_getenv(env, "SECRET"), ==, "1");
    g_assert_cmpstr(g_environ_getenv(env, "TUI_APP_TOKEN"), ==, "t");
    g_assert_cmpstr(g_environ_getenv(env, "TUI_APP_ADDR"), ==, "tcp://127.0.0.1:1");
    /* Like macOS, only the address and the token are replaced: the other TUI_APP_* variables are kept */
    g_assert_cmpstr(g_environ_getenv(env, "TUI_APP_AUTOLOAD"), ==, "/a.php");
    g_assert_cmpstr(g_environ_getenv(env, "TUI_APP_CANVAS_CAPTURE"), ==, "/c");
    g_assert_cmpstr(argv[0], ==, "php");
    g_assert_cmpstr(argv[1], ==, "./tools/fake-app.php");
}

static void test_tokens(void)
{
    g_autofree char *a = tui_new_token();
    g_autofree char *b = tui_new_token();
    g_autoptr(GRegex) pattern = g_regex_new("^[0-9a-f]{32}$", 0, 0, NULL);

    g_assert_true(g_regex_match(pattern, a, 0, NULL));
    g_assert_cmpstr(a, !=, b);
    g_assert_true(tui_token_equal(a, a));
    g_assert_false(tui_token_equal(a, b));
    g_assert_false(tui_token_equal(a, "0123"));
    g_assert_false(tui_token_equal("0123", a));
    g_assert_false(tui_token_equal(a, NULL));
}

/* ---- keys ---- */

static void check_key(guint keyval, gunichar unicode, guint mods, const char *id, const char *text)
{
    g_autofree char *typed = NULL;
    g_autofree char *actual = tui_key_id(keyval, unicode, mods, &typed);
    g_assert_cmpstr(actual, ==, id);
    g_assert_cmpstr(typed, ==, text);
}

static void check_committed(const char *text, const char *const *expected)
{
    g_auto(GStrv) keys = tui_committed_keys(text);
    g_assert_cmpuint(g_strv_length(keys), ==, g_strv_length((char **) expected));
    for (guint i = 0; NULL != expected[i]; i++) {
        g_assert_cmpstr(keys[i], ==, expected[i]);
    }
}

static void test_committed_text_is_typed_keys(void)
{
    check_committed("é", (const char *const[]) {"é", "é", NULL});
    check_committed("日本", (const char *const[]) {"日", "日", "本", "本", NULL});
    check_committed("Éa ", (const char *const[]) {"shift+é", "É", "a", "a", "space", " ", NULL});
    /* e and a combining acute: one character */
    check_committed("e\xcc\x81", (const char *const[]) {"e\xcc\x81", "e\xcc\x81", NULL});
    /* E and a combining acute: an uppercase letter, as É is (macOS and Windows say so too) */
    check_committed("E\xcc\x81", (const char *const[]) {"shift+e\xcc\x81", "E\xcc\x81", NULL});
    /* a family (joined by ZWJ), a thumb with a skin tone, a flag, then a heart with its emoji selector */
    check_committed("👨‍👩‍👧👍🏽🇫🇷❤️",
        (const char *const[]) {"👨‍👩‍👧", "👨‍👩‍👧", "👍🏽", "👍🏽", "🇫🇷", "🇫🇷", "❤️", "❤️", NULL});
    /* two flags in a row */
    check_committed("🇫🇷🇩🇪", (const char *const[]) {"🇫🇷", "🇫🇷", "🇩🇪", "🇩🇪", NULL});
    /* control characters are not typed text */
    check_committed("a\nb\t", (const char *const[]) {"a", "a", "b", "b", NULL});
    check_committed("", (const char *const[]) {NULL});
    check_committed("\xff", (const char *const[]) {NULL});
}

static void test_key_ids(void)
{
    check_key(0x73, 's', TUI_MOD_CTRL, "ctrl+s", NULL);
    check_key(0x53, 'S', TUI_MOD_CTRL | TUI_MOD_SHIFT, "ctrl+shift+s", NULL);
    check_key(0x41, 'A', TUI_MOD_SHIFT, "shift+a", "A");
    check_key(0x61, 'a', 0, "a", "a");
    check_key(0x21, '!', TUI_MOD_SHIFT, "!", "!");
    check_key(0xe9, 0xe9, 0, "é", "é");
    check_key(0xff0d, '\r', 0, "enter", NULL);
    check_key(0xff8d, '\r', TUI_MOD_CTRL, "ctrl+enter", NULL);
    check_key(0xff1b, 0x1b, 0, "escape", NULL);
    check_key(0xfe20, 0, TUI_MOD_SHIFT, "shift+tab", NULL);
    check_key(0x20, ' ', 0, "space", " ");
    check_key(0xff55, 0, 0, "page_up", NULL);
    check_key(0xffc2, 0, TUI_MOD_ALT, "alt+f5", NULL);
    check_key(0xff52, 0, TUI_MOD_CTRL | TUI_MOD_SHIFT | TUI_MOD_ALT | TUI_MOD_SUPER, "ctrl+shift+alt+super+up", NULL);
    check_key(0x78, 'x', TUI_MOD_SUPER, "super+x", NULL);
    check_key(0xffe1, 0, TUI_MOD_SHIFT, NULL, NULL); /* Shift_L alone */
    check_key(0xffe3, 0, TUI_MOD_CTRL, NULL, NULL);  /* Control_L alone */
    g_assert_cmpstr(tui_keyval_name(0xffff), ==, "delete");
    g_assert_cmpstr(tui_keyval_name(0xffc9), ==, "f12");
    g_assert_null(tui_keyval_name(0x61));
}

static void test_shortcuts_become_accelerators(void)
{
    const char *pairs[][2] = {
        {"ctrl+s", "<Control>s"}, {"ctrl+shift+z", "<Control><Shift>z"}, {"alt+f4", "<Alt>F4"},
        {"super+enter", "<Super>Return"}, {"ctrl+,", "<Control>comma"}, {"f12", "F12"}, {"ctrl+page_down", "<Control>Page_Down"},
    };
    for (guint i = 0; i < G_N_ELEMENTS(pairs); i++) {
        g_autofree char *accel = tui_key_accel(pairs[i][0]);
        g_assert_cmpstr(accel, ==, pairs[i][1]);
    }
    g_assert_null(tui_key_accel(""));
    g_assert_null(tui_key_accel("ctrl+f13"));
}

/* ---- close flow ---- */

static void test_close_accepted(void)
{
    TuiCloseFlow flow = {0};
    g_assert_cmpint(tui_close_requested(&flow, TRUE), ==, TUI_DO_SEND_CLOSE);
    g_assert_cmpint(tui_close_requested(&flow, TRUE), ==, TUI_DO_NOTHING);
    g_assert_cmpint(tui_close_reply(&flow, TRUE), ==, TUI_DO_WAIT_EXIT);
    g_assert_cmpint(tui_close_bye(&flow), ==, TUI_DO_WAIT_EXIT);
    g_assert_cmpint(tui_close_exited(&flow), ==, TUI_DO_CLOSE_WINDOW);
    g_assert_cmpint(tui_close_requested(&flow, FALSE), ==, TUI_DO_CLOSE_WINDOW);
}

static void test_close_refused_then_timed_out(void)
{
    TuiCloseFlow flow = {0};
    g_assert_cmpint(tui_close_requested(&flow, TRUE), ==, TUI_DO_SEND_CLOSE);
    g_assert_cmpint(tui_close_reply(&flow, FALSE), ==, TUI_DO_KEEP_OPEN);
    g_assert_cmpint(tui_close_timeout(&flow), ==, TUI_DO_NOTHING);
    g_assert_cmpint(tui_close_requested(&flow, TRUE), ==, TUI_DO_SEND_CLOSE);
    g_assert_cmpint(tui_close_timeout(&flow), ==, TUI_DO_ASK_FORCE);
    g_assert_cmpint(tui_close_force_answer(&flow, FALSE), ==, TUI_DO_KEEP_OPEN);
    g_assert_cmpint(tui_close_requested(&flow, TRUE), ==, TUI_DO_SEND_CLOSE);
    g_assert_cmpint(tui_close_reply(&flow, TRUE), ==, TUI_DO_WAIT_EXIT);
    g_assert_cmpint(tui_close_timeout(&flow), ==, TUI_DO_ASK_FORCE);
    g_assert_cmpint(tui_close_force_answer(&flow, TRUE), ==, TUI_DO_KILL);
    g_assert_cmpint(tui_close_exited(&flow), ==, TUI_DO_CLOSE_WINDOW);
}

static void test_an_app_that_quits_by_itself(void)
{
    TuiCloseFlow flow = {0};
    g_assert_cmpint(tui_close_bye(&flow), ==, TUI_DO_WAIT_EXIT);
    g_assert_cmpint(tui_close_timeout(&flow), ==, TUI_DO_ASK_FORCE);
    TuiCloseFlow quiet = {0};
    g_assert_cmpint(tui_close_exited(&quiet), ==, TUI_DO_CLOSE_WINDOW);
    TuiCloseFlow unconnected = {0};
    g_assert_cmpint(tui_close_requested(&unconnected, FALSE), ==, TUI_DO_KILL);
}

/* ---- cells and geometry ---- */

/* pointer: the cell under the mouse, rounded down past the edges; the wheel in notches or pixels per cell */
static void test_pointer_cells_and_wheel(void)
{
    gint64 row, column, rows, columns;
    double kept_rows = 0, kept_columns = 0;
    tui_pointer_cell(15, 33, 10, 22, &row, &column);
    g_assert_cmpint(row, ==, 1);
    g_assert_cmpint(column, ==, 1);
    tui_pointer_cell(-0.5, -23, 10, 22, &row, &column);
    g_assert_cmpint(row, ==, -2);
    g_assert_cmpint(column, ==, -1);

    /* A notch is a row, down positive */
    g_assert_true(tui_wheel_add(&kept_rows, &kept_columns, 0, 3, TRUE, 10, 22, &rows, &columns));
    g_assert_cmpint(rows, ==, 3);
    g_assert_cmpint(columns, ==, 0);
    g_assert_true(tui_wheel_add(&kept_rows, &kept_columns, -1, -1, TRUE, 10, 22, &rows, &columns));
    g_assert_cmpint(rows, ==, -1);
    g_assert_cmpint(columns, ==, -1);
    /* A high-resolution wheel's half notches add up */
    g_assert_false(tui_wheel_add(&kept_rows, &kept_columns, 0, 0.5, TRUE, 10, 22, &rows, &columns));
    g_assert_true(tui_wheel_add(&kept_rows, &kept_columns, 0, 0.5, TRUE, 10, 22, &rows, &columns));
    g_assert_cmpint(rows, ==, 1);
    /* Half a notch down, then a notch up: a row up, the half down dropped */
    g_assert_false(tui_wheel_add(&kept_rows, &kept_columns, 0, 0.5, TRUE, 10, 22, &rows, &columns));
    g_assert_true(tui_wheel_add(&kept_rows, &kept_columns, 0, -1, TRUE, 10, 22, &rows, &columns));
    g_assert_cmpint(rows, ==, -1);
    g_assert_cmpfloat(kept_rows, ==, 0);

    /* Pixels: sent once they make a cell, the rest kept */
    kept_rows = kept_columns = 0;
    g_assert_false(tui_wheel_add(&kept_rows, &kept_columns, 4, 15, FALSE, 10, 22, &rows, &columns));
    g_assert_true(tui_wheel_add(&kept_rows, &kept_columns, 4, 15, FALSE, 10, 22, &rows, &columns));
    g_assert_cmpint(rows, ==, 1);
    g_assert_cmpint(columns, ==, 0);
    g_assert_cmpfloat_with_epsilon(kept_rows, 8.0 / 22, 1e-9);
    g_assert_true(tui_wheel_add(&kept_rows, &kept_columns, 4, -60, FALSE, 10, 22, &rows, &columns));
    /* Turned up: the 8 px kept down are dropped, -60 px is 2 rows up and 16 px kept */
    g_assert_cmpint(rows, ==, -2);
    g_assert_cmpint(columns, ==, 1);
    g_assert_cmpfloat_with_epsilon(kept_rows, (-60.0 + 44) / 22, 1e-9);
    g_assert_cmpfloat_with_epsilon(kept_columns, 0.2, 1e-9);
}

static void test_cells(void)
{
    gint64 columns;
    gint64 rows;
    gint64 rect[4];

    /* The hello fixture: 840 x 510 px of 8.4 x 17 px cells. */
    tui_grid_size(840, 510, 8.4, 17, &columns, &rows);
    g_assert_cmpint(columns, ==, 100);
    g_assert_cmpint(rows, ==, 30);
    tui_grid_size(3, 3, 8.4, 17, &columns, &rows);
    g_assert_cmpint(columns, ==, 1);
    g_assert_cmpint(rows, ==, 1);
    tui_rect_cells(10, 20, 100, 18, 8.4, 17, rect);
    g_assert_cmpint(rect[0], ==, 1);
    g_assert_cmpint(rect[1], ==, 1);
    g_assert_cmpint(rect[2], ==, 12);
    g_assert_cmpint(rect[3], ==, 2);
    tui_rect_cells(0, 0, 840, 510, 8.4, 17, rect);
    g_assert_cmpint(rect[2], ==, 100);
    g_assert_cmpint(rect[3], ==, 30);
}

static void test_geometry_round_trips(void)
{
    g_autofree char *json = tui_geometry_to_json(1024, 700, TRUE);
    gint64 width;
    gint64 height;
    gboolean maximized;

    g_assert_true(tui_geometry_parse(json, &width, &height, &maximized));
    g_assert_cmpint(width, ==, 1024);
    g_assert_cmpint(height, ==, 700);
    g_assert_true(maximized);
    g_assert_false(tui_geometry_parse("{\"width\":0,\"height\":5}", &width, &height, &maximized));
    g_assert_false(tui_geometry_parse("garbage", &width, &height, &maximized));
}

/* ---- styling ---- */

static void test_runs_become_markup(void)
{
    g_autoptr(JsonNode) runs = parse_json("[{\"t\":\"plain \"},{\"t\":\"bold\",\"b\":true},{\"t\":\" red\",\"fg\":\"#ff0000\",\"u\":true},"
                                          "{\"t\":\"<&>\",\"d\":true,\"i\":true,\"s\":true},{\"t\":\"rev\",\"r\":true},{\"t\":\"bad\",\"fg\":\"red\"}]");
    g_autofree char *markup = tui_runs_markup(json_node_get_array(runs), "#000000", "#ffffff");

    g_assert_cmpstr(markup, ==,
        "plain <span weight=\"bold\">bold</span><span foreground=\"#ff0000\" underline=\"single\"> red</span>"
        "<span alpha=\"60%\" style=\"italic\" strikethrough=\"true\">&lt;&amp;&gt;</span>"
        "<span foreground=\"#ffffff\" background=\"#000000\">rev</span>bad");
}

static void test_style_becomes_css(void)
{
    g_autoptr(JsonNode) style = parse_json("{\"fg\":\"#00ff00\",\"bold\":true,\"underline\":true,\"strike\":true,\"bg\":\"nope\"}");
    g_autofree char *css = tui_style_css(2, json_node_get_object(style));
    g_autofree char *none = tui_style_css(3, NULL);

    g_assert_cmpstr(css, ==, ".tui-n2 { color: #00ff00; font-weight: bold; text-decoration-line: underline line-through; }\n");
    g_assert_cmpstr(none, ==, "");
}

/* ---- packaging ---- */

static void test_desktop_entry(void)
{
    g_autoptr(TuiManifest) manifest = tui_manifest_parse(system_manifest, -1, "/", NULL);
    g_autofree char *entry = tui_desktop_entry(manifest, "org.example.Fake");
    g_autofree char *xml = tui_mime_xml(manifest);
    g_autoptr(GKeyFile) file = g_key_file_new();
    g_autoptr(GError) error = NULL;

    g_assert_true(g_key_file_load_from_data(file, entry, -1, G_KEY_FILE_NONE, &error));
    g_assert_no_error(error);
    g_assert_cmpstr(g_key_file_get_string(file, "Desktop Entry", "Name", NULL), ==, "Fake; app");
    g_assert_cmpstr(g_key_file_get_string(file, "Desktop Entry", "Comment", NULL), ==, "Line\none");
    g_assert_cmpstr(g_key_file_get_string(file, "Desktop Entry", "Exec", NULL), ==, "org.example.Fake -- %U");
    g_assert_cmpstr(g_key_file_get_string(file, "Desktop Entry", "Icon", NULL), ==, "org.example.Fake");
    g_assert_cmpstr(g_key_file_get_string(file, "Desktop Entry", "Categories", NULL), ==, "Office;Finance;");
    g_assert_cmpstr(g_key_file_get_string(file, "Desktop Entry", "MimeType", NULL), ==,
        "application/x-org.example.Fake-fake;text/markdown;x-scheme-handler/fake;x-scheme-handler/fake-two;");
    /* Only the type the manifest left null gets a shared-mime-info entry. */
    g_assert_nonnull(strstr(xml, "<mime-type type=\"application/x-org.example.Fake-fake\">"));
    g_assert_nonnull(strstr(xml, "<comment>Fake doc</comment>"));
    g_assert_nonnull(strstr(xml, "<glob pattern=\"*.fake\"/>"));
    g_assert_null(strstr(xml, "markdown"));

    g_autofree char *quoted = tui_desktop_entry(manifest, "/opt/My App/bin/x");
    g_assert_nonnull(strstr(quoted, "Exec=\"/opt/My App/bin/x\" -- %U\n"));

    char *command[] = {"php", NULL};
    g_autoptr(TuiManifest) bare = tui_manifest_dev(command);
    g_autofree char *plain = tui_desktop_entry(bare, "x");
    g_assert_nonnull(strstr(plain, "Categories=Utility;\n"));
    g_assert_null(strstr(plain, "MimeType"));
    g_assert_null(tui_mime_xml(bare));
}

static JsonObject *props_of(const char *json)
{
    g_autoptr(JsonParser) parser = json_parser_new();
    g_assert_true(json_parser_load_from_data(parser, json, -1, NULL));
    return json_object_ref(json_node_get_object(json_parser_get_root(parser)));
}

static void test_max_length(void)
{
    static const struct {
        const char *json;
        gint64 limit;
    } limits[] = {{"{\"maxLength\": 3}", 3}, {"{\"maxLength\": 0}", 0}, {"{}", -1}, {"{\"maxLength\": null}", -1}, {"{\"maxLength\": -1}", -1}};
    for (guint i = 0; i < G_N_ELEMENTS(limits); i++) {
        g_autoptr(JsonObject) props = props_of(limits[i].json);
        g_assert_cmpint(tui_max_length(props), ==, limits[i].limit);
    }
    /* Typing stops at the limit; 0 allows nothing; -1 is no limit */
    g_assert_cmpuint(tui_max_length_keep(2, 0, "c", -1, 3), ==, 1);
    g_assert_cmpuint(tui_max_length_keep(3, 0, "d", -1, 3), ==, 0);
    g_assert_cmpuint(tui_max_length_keep(0, 0, "a", -1, 0), ==, 0);
    g_assert_cmpuint(tui_max_length_keep(3, 0, "d", -1, -1), ==, 1);
    /* A paste is cut; the selection it replaces makes room */
    g_assert_cmpuint(tui_max_length_keep(1, 0, "bcdef", -1, 3), ==, 2);
    g_assert_cmpuint(tui_max_length_keep(3, 1, "XYZ", -1, 3), ==, 1);
    g_assert_cmpuint(tui_max_length_keep(1, 0, "bcdef", 3, 9), ==, 3);
    /* Characters are code points, like mb_strlen: é and 日 one each, 😀 one of four bytes, e + combining acute two */
    g_assert_cmpuint(tui_max_length_keep(2, 0, "\xf0\x9f\x98\x80x", -1, 3), ==, 4);
    g_assert_cmpuint(tui_max_length_keep(0, 0, "e\xcc\x81" "e\xcc\x81", -1, 3), ==, 4);
    g_assert_cmpuint(tui_max_length_keep(0, 0, "\xc3\xa9\xe6\x97\xa5x", -1, 2), ==, 5);
    /* A value past the limit may change but not grow */
    g_assert_cmpuint(tui_max_length_keep(4, 1, "X", -1, 2), ==, 1);
    g_assert_cmpuint(tui_max_length_keep(4, 1, "XY", -1, 2), ==, 1);
    g_assert_cmpuint(tui_max_length_keep(4, 0, "e", -1, 2), ==, 0);
}

int main(int argc, char **argv)
{
    g_test_init(&argc, &argv, NULL);
    g_test_add_func("/frames/encode", test_frames_encode_byte_for_byte);
    g_test_add_func("/frames/decode", test_frames_decode_byte_for_byte_even_split);
    g_test_add_func("/frames/oversized", test_an_oversized_frame_is_refused);
    g_test_add_func("/messages/round-trip", test_messages_round_trip);
    g_test_add_func("/messages/json-equal", test_json_equality_is_structural);
    g_test_add_func("/messages/bad", test_bad_messages_are_refused_and_unknown_ones_kept);
    g_test_add_func("/messages/pointer", test_pointer_messages);
    g_test_add_func("/messages/cursor", test_cursor_messages);
    g_test_add_func("/messages/contextmenu-clipboard", test_contextmenu_and_clipboard_messages);
    g_test_add_func("/session/trees", test_session_trees);
    g_test_add_func("/session/remove-descendants", test_removing_a_node_removes_its_descendants);
    g_test_add_func("/session/move-and-null", test_moves_keep_the_index_and_null_resets_a_prop);
    g_test_add_func("/manifest/parses", test_manifest_parses);
    g_test_add_func("/manifest/bad", test_bad_manifests_are_refused);
    g_test_add_func("/manifest/system", test_system_mode_runs_php_with_resolved_args);
    g_test_add_func("/manifest/versions", test_versions_compare_numerically);
    g_test_add_func("/env/allowlist", test_the_environment_keeps_the_allowlist_only);
    g_test_add_func("/env/dev", test_dev_mode_keeps_the_environment_and_command);
    g_test_add_func("/token", test_tokens);
    g_test_add_func("/keys/ids", test_key_ids);
    g_test_add_func("/keys/committed", test_committed_text_is_typed_keys);
    g_test_add_func("/keys/accelerators", test_shortcuts_become_accelerators);
    g_test_add_func("/close/accepted", test_close_accepted);
    g_test_add_func("/close/refused-timeout", test_close_refused_then_timed_out);
    g_test_add_func("/close/by-itself", test_an_app_that_quits_by_itself);
    g_test_add_func("/cells", test_cells);
    g_test_add_func("/cells/pointer", test_pointer_cells_and_wheel);
    g_test_add_func("/geometry", test_geometry_round_trips);
    g_test_add_func("/style/runs", test_runs_become_markup);
    g_test_add_func("/style/css", test_style_becomes_css);
    g_test_add_func("/packaging/desktop-entry", test_desktop_entry);
    g_test_add_func("/max-length", test_max_length);
    g_test_add_func("/tree/acknowledge", test_acknowledged_edits_are_kept);
    return g_test_run();
}
