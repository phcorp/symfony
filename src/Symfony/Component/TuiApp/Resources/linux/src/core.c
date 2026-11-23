#include "core.h"

#include <math.h>
#include <stdio.h>
#include <string.h>

#define TUI_ERROR (g_quark_from_static_string("tui-core"))

/* ======================================================================== */
/* Frame codec                                                              */
/* ======================================================================== */

GBytes *tui_frame_encode(const char *json, gsize length)
{
    guint8 *frame = g_malloc(length + 4);

    frame[0] = (guint8) (length >> 24);
    frame[1] = (guint8) (length >> 16);
    frame[2] = (guint8) (length >> 8);
    frame[3] = (guint8) length;
    memcpy(frame + 4, json, length);
    return g_bytes_new_take(frame, length + 4);
}

struct TuiDecoder {
    GByteArray *buffer;
};

TuiDecoder *tui_decoder_new(void)
{
    TuiDecoder *decoder = g_new0(TuiDecoder, 1);
    decoder->buffer = g_byte_array_new();
    return decoder;
}

void tui_decoder_free(TuiDecoder *decoder)
{
    if (NULL != decoder) {
        g_byte_array_unref(decoder->buffer);
        g_free(decoder);
    }
}

gboolean tui_decoder_feed(TuiDecoder *decoder, const guint8 *bytes, gsize length, GPtrArray *frames, GError **error)
{
    g_byte_array_append(decoder->buffer, bytes, (guint) length);
    for (;;) {
        const guint8 *data = decoder->buffer->data;
        if (decoder->buffer->len < 4) {
            return TRUE;
        }
        guint32 size = ((guint32) data[0] << 24) | ((guint32) data[1] << 16) | ((guint32) data[2] << 8) | data[3];
        if (size > TUI_MAX_FRAME_BYTES) {
            g_set_error(error, TUI_ERROR, 1, "a frame of %u bytes is over the %u byte limit", size, TUI_MAX_FRAME_BYTES);
            return FALSE;
        }
        if (decoder->buffer->len - 4 < size) {
            return TRUE;
        }
        g_ptr_array_add(frames, g_strndup((const char *) data + 4, size));
        g_byte_array_remove_range(decoder->buffer, 0, size + 4);
    }
}

/* ======================================================================== */
/* JSON helpers                                                             */
/* ======================================================================== */

static gboolean is_number(JsonNode *node)
{
    if (JSON_NODE_VALUE != json_node_get_node_type(node)) {
        return FALSE;
    }
    GType type = json_node_get_value_type(node);
    return G_TYPE_INT64 == type || G_TYPE_DOUBLE == type;
}

gboolean tui_json_equal(JsonNode *a, JsonNode *b)
{
    if (NULL == a || NULL == b) {
        return a == b;
    }
    if (is_number(a) && is_number(b)) {
        return json_node_get_double(a) == json_node_get_double(b);
    }
    if (json_node_get_node_type(a) != json_node_get_node_type(b)) {
        return FALSE;
    }
    switch (json_node_get_node_type(a)) {
    case JSON_NODE_NULL:
        return TRUE;
    case JSON_NODE_VALUE:
        if (json_node_get_value_type(a) != json_node_get_value_type(b)) {
            return FALSE;
        }
        if (G_TYPE_STRING == json_node_get_value_type(a)) {
            return 0 == g_strcmp0(json_node_get_string(a), json_node_get_string(b));
        }
        if (G_TYPE_BOOLEAN == json_node_get_value_type(a)) {
            return json_node_get_boolean(a) == json_node_get_boolean(b);
        }
        return FALSE;
    case JSON_NODE_ARRAY: {
        JsonArray *x = json_node_get_array(a);
        JsonArray *y = json_node_get_array(b);
        if (json_array_get_length(x) != json_array_get_length(y)) {
            return FALSE;
        }
        for (guint i = 0; i < json_array_get_length(x); i++) {
            if (!tui_json_equal(json_array_get_element(x, i), json_array_get_element(y, i))) {
                return FALSE;
            }
        }
        return TRUE;
    }
    case JSON_NODE_OBJECT: {
        JsonObject *x = json_node_get_object(a);
        JsonObject *y = json_node_get_object(b);
        if (json_object_get_size(x) != json_object_get_size(y)) {
            return FALSE;
        }
        g_autoptr(GList) members = json_object_get_members(x);
        for (GList *m = members; NULL != m; m = m->next) {
            if (!json_object_has_member(y, m->data)
                || !tui_json_equal(json_object_get_member(x, m->data), json_object_get_member(y, m->data))) {
                return FALSE;
            }
        }
        return TRUE;
    }
    }
    return FALSE;
}

static JsonNode *member(JsonObject *object, const char *name)
{
    return NULL != object && json_object_has_member(object, name) ? json_object_get_member(object, name) : NULL;
}

static gboolean member_is_null(JsonObject *object, const char *name)
{
    JsonNode *node = member(object, name);
    return NULL != node && JSON_NODE_NULL == json_node_get_node_type(node);
}

static const char *member_string(JsonObject *object, const char *name)
{
    JsonNode *node = member(object, name);
    return NULL != node && JSON_NODE_VALUE == json_node_get_node_type(node) && G_TYPE_STRING == json_node_get_value_type(node)
        ? json_node_get_string(node)
        : NULL;
}

static gboolean member_number(JsonObject *object, const char *name, double *out)
{
    JsonNode *node = member(object, name);
    if (NULL == node || !is_number(node)) {
        return FALSE;
    }
    *out = json_node_get_double(node);
    return TRUE;
}

static gboolean member_int(JsonObject *object, const char *name, gint64 *out)
{
    double value;
    if (!member_number(object, name, &value)) {
        return FALSE;
    }
    *out = (gint64) value;
    return TRUE;
}

static gboolean member_bool(JsonObject *object, const char *name, gboolean *out)
{
    JsonNode *node = member(object, name);
    if (NULL == node || JSON_NODE_VALUE != json_node_get_node_type(node) || G_TYPE_BOOLEAN != json_node_get_value_type(node)) {
        return FALSE;
    }
    *out = json_node_get_boolean(node);
    return TRUE;
}

static JsonObject *member_object(JsonObject *object, const char *name)
{
    JsonNode *node = member(object, name);
    if (NULL != node && JSON_NODE_ARRAY == json_node_get_node_type(node) && 0 == json_array_get_length(json_node_get_array(node))) {
        /* PHP's json_encode writes an empty map as []: read it as {}. */
        JsonObject *empty = json_object_new();
        json_object_set_object_member(object, name, empty);
        return empty;
    }
    return NULL != node && JSON_NODE_OBJECT == json_node_get_node_type(node) ? json_node_get_object(node) : NULL;
}

static JsonArray *member_array(JsonObject *object, const char *name)
{
    JsonNode *node = member(object, name);
    return NULL != node && JSON_NODE_ARRAY == json_node_get_node_type(node) ? json_node_get_array(node) : NULL;
}

static char **string_array(JsonArray *array)
{
    GPtrArray *strings = g_ptr_array_new();
    for (guint i = 0; NULL != array && i < json_array_get_length(array); i++) {
        JsonNode *node = json_array_get_element(array, i);
        if (JSON_NODE_VALUE == json_node_get_node_type(node) && G_TYPE_STRING == json_node_get_value_type(node)) {
            g_ptr_array_add(strings, g_strdup(json_node_get_string(node)));
        }
    }
    g_ptr_array_add(strings, NULL);
    return (char **) g_ptr_array_free(strings, FALSE);
}

/* Prop accessors */

const char *tui_prop_string(JsonObject *props, const char *name, const char *fallback)
{
    const char *value = member_string(props, name);
    return NULL != value ? value : fallback;
}

gint64 tui_max_length(JsonObject *props)
{
    gint64 limit = tui_prop_int(props, "maxLength", -1);
    return limit >= 0 ? limit : -1;
}

gsize tui_max_length_keep(glong length, glong removed, const char *text, gssize len, gint64 limit)
{
    gsize bytes = len < 0 ? strlen(text) : (gsize) len;
    if (limit < 0) {
        return bytes;
    }
    gint64 room = MAX(limit, (gint64) length) - ((gint64) length - removed);
    if (room <= 0) {
        return 0;
    }
    if (g_utf8_strlen(text, (gssize) bytes) <= room) {
        return bytes;
    }
    return (gsize) (g_utf8_offset_to_pointer(text, (glong) room) - text);
}

gint64 tui_prop_int(JsonObject *props, const char *name, gint64 fallback)
{
    gint64 value;
    return member_int(props, name, &value) ? value : fallback;
}

double tui_prop_double(JsonObject *props, const char *name, double fallback)
{
    double value;
    return member_number(props, name, &value) ? value : fallback;
}

gboolean tui_prop_bool(JsonObject *props, const char *name, gboolean fallback)
{
    gboolean value;
    return member_bool(props, name, &value) ? value : fallback;
}

gboolean tui_prop_is_null(JsonObject *props, const char *name)
{
    JsonNode *node = member(props, name);
    return NULL == node || JSON_NODE_NULL == json_node_get_node_type(node);
}

JsonArray *tui_prop_array(JsonObject *props, const char *name)
{
    return member_array(props, name);
}

JsonObject *tui_prop_object(JsonObject *props, const char *name)
{
    return member_object(props, name);
}

/* ======================================================================== */
/* Messages                                                                 */
/* ======================================================================== */

static const char *const type_names[] = {
    [TUI_MSG_UNKNOWN] = NULL,
    [TUI_MSG_AUTH] = "auth",
    [TUI_MSG_HELLO] = "hello",
    [TUI_MSG_PATCH] = "patch",
    [TUI_MSG_FOCUS] = "focus",
    [TUI_MSG_WINDOW] = "window",
    [TUI_MSG_NOTIFY] = "notify",
    [TUI_MSG_BEEP] = "beep",
    [TUI_MSG_MENU] = "menu",
    [TUI_MSG_CLOSE_REPLY] = "close-reply",
    [TUI_MSG_BYE] = "bye",
    [TUI_MSG_RESIZE] = "resize",
    [TUI_MSG_EVENT] = "event",
    [TUI_MSG_KEY] = "key",
    [TUI_MSG_PASTE] = "paste",
    [TUI_MSG_RECTS] = "rects",
    [TUI_MSG_MENU_SELECT] = "menu-select",
    [TUI_MSG_APPEARANCE] = "appearance",
    [TUI_MSG_OPEN] = "open",
    [TUI_MSG_CLOSE] = "close",
    [TUI_MSG_POINTER] = "pointer",
    [TUI_MSG_CURSOR] = "cursor",
    [TUI_MSG_CONTEXTMENU] = "contextmenu",
    [TUI_MSG_CONTEXTMENU_SELECT] = "contextmenu-select",
    [TUI_MSG_CLIPBOARD] = "clipboard",
};

/* contract/protocol.json $defs.cursor shape */
static const char *const cursor_shapes[] = {"default", "none", "context-menu", "help", "pointer", "progress", "wait", "cell", "crosshair", "text",
    "vertical-text", "alias", "copy", "move", "no-drop", "not-allowed", "grab", "grabbing", "all-scroll", "col-resize", "row-resize", "n-resize",
    "e-resize", "s-resize", "w-resize", "ne-resize", "nw-resize", "se-resize", "sw-resize", "ew-resize", "ns-resize", "nesw-resize", "nwse-resize",
    "zoom-in", "zoom-out"};

gboolean tui_cursor_shape_known(const char *shape)
{
    for (guint i = 0; NULL != shape && i < G_N_ELEMENTS(cursor_shapes); i++) {
        if (0 == strcmp(shape, cursor_shapes[i])) {
            return TRUE;
        }
    }
    return FALSE;
}

static const char *const op_names[] = {"create", "update", "move", "remove"};

static void op_free(gpointer data)
{
    TuiOp *op = data;
    g_free(op->kind);
    if (NULL != op->props) {
        json_object_unref(op->props);
    }
    g_free(op);
}

static void menu_item_free(gpointer data)
{
    TuiMenuItem *item = data;
    g_free(item->id);
    g_free(item->label);
    g_free(item->shortcut);
    g_free(item->role);
    g_free(item);
}

static void menu_free(gpointer data)
{
    TuiMenu *menu = data;
    g_free(menu->title);
    g_ptr_array_unref(menu->items);
    g_free(menu);
}

TuiMessage *tui_message_new(TuiMessageType type)
{
    TuiMessage *message = g_new0(TuiMessage, 1);
    message->type = type;
    message->protocol = TUI_PROTOCOL;
    return message;
}

void tui_message_free(TuiMessage *message)
{
    if (NULL == message) {
        return;
    }
    g_free(message->type_name);
    g_free(message->token);
    g_free(message->os);
    g_free(message->appearance);
    g_strfreev(message->capabilities);
    if (NULL != message->ops) {
        g_ptr_array_unref(message->ops);
    }
    g_free(message->title);
    g_free(message->progress_state);
    g_free(message->body);
    if (NULL != message->menus) {
        g_ptr_array_unref(message->menus);
    }
    g_free(message->name);
    if (NULL != message->value) {
        json_node_unref(message->value);
    }
    g_free(message->key);
    g_free(message->text);
    if (NULL != message->rects) {
        g_array_unref(message->rects);
    }
    g_free(message->id);
    g_strfreev(message->paths);
    g_free(message->action);
    g_free(message->button);
    g_strfreev(message->modifiers);
    g_free(message->shape);
    if (NULL != message->items) {
        g_ptr_array_unref(message->items);
    }
    g_free(message);
}

static gboolean need(gboolean present, const char *type, const char *name, GError **error)
{
    if (!present) {
        g_set_error(error, TUI_ERROR, 2, "%s: %s is missing or has the wrong type", type, name);
    }
    return present;
}

/* A node id or null (0). */
static gboolean node_or_null(JsonObject *object, const char *name, gint64 *out)
{
    if (member_is_null(object, name)) {
        *out = 0;
        return TRUE;
    }
    return member_int(object, name, out);
}

static TuiOp *parse_op(JsonObject *object, GError **error)
{
    const char *name = member_string(object, "op");
    TuiOp *op = g_new0(TuiOp, 1);
    gboolean ok = need(NULL != name, "patch", "op", error) && need(member_int(object, "id", &op->id), "patch", "id", error);

    if (ok) {
        if (0 == g_strcmp0(name, "create")) {
            op->op = TUI_OP_CREATE;
            op->kind = g_strdup(member_string(object, "kind"));
            ok = need(NULL != op->kind, "create", "kind", error) && need(node_or_null(object, "parent", &op->parent), "create", "parent", error)
                && need(member_int(object, "index", &op->index), "create", "index", error);
        } else if (0 == g_strcmp0(name, "update")) {
            op->op = TUI_OP_UPDATE;
        } else if (0 == g_strcmp0(name, "move")) {
            op->op = TUI_OP_MOVE;
            ok = need(member_int(object, "parent", &op->parent), "move", "parent", error)
                && need(member_int(object, "index", &op->index), "move", "index", error);
        } else if (0 == g_strcmp0(name, "remove")) {
            op->op = TUI_OP_REMOVE;
        } else {
            g_set_error(error, TUI_ERROR, 2, "patch: unknown op %s", name);
            ok = FALSE;
        }
    }
    if (ok && (TUI_OP_CREATE == op->op || TUI_OP_UPDATE == op->op)) {
        JsonObject *props = member_object(object, "props");
        ok = need(NULL != props, op_names[op->op], "props", error);
        if (ok) {
            op->props = json_object_ref(props);
        }
    }
    if (!ok) {
        op_free(op);
        return NULL;
    }
    return op;
}

static TuiMenuItem *parse_menu_item(JsonObject *object)
{
    TuiMenuItem *item = g_new0(TuiMenuItem, 1);
    item->id = g_strdup(member_string(object, "id"));
    item->label = g_strdup(member_string(object, "label"));
    if (NULL != member(object, "shortcut")) {
        item->has_shortcut = TRUE;
        item->shortcut = g_strdup(member_string(object, "shortcut"));
    }
    item->has_separator = member_bool(object, "separator", &item->separator);
    item->has_enabled = member_bool(object, "enabled", &item->enabled);
    if (member_is_null(object, "checked")) {
        item->has_checked = item->checked_null = TRUE;
    } else {
        item->has_checked = member_bool(object, "checked", &item->checked);
    }
    item->role = g_strdup(member_string(object, "role"));
    return item;
}

TuiMessage *tui_message_from_node(JsonNode *root, GError **error)
{
    if (NULL == root || JSON_NODE_OBJECT != json_node_get_node_type(root)) {
        g_set_error(error, TUI_ERROR, 2, "a message is a JSON object");
        return NULL;
    }
    JsonObject *object = json_node_get_object(root);
    const char *type = member_string(object, "type");
    if (NULL == type) {
        g_set_error(error, TUI_ERROR, 2, "a message has a string type");
        return NULL;
    }

    TuiMessage *message = tui_message_new(TUI_MSG_UNKNOWN);
    message->type_name = g_strdup(type);
    for (guint i = 1; i < G_N_ELEMENTS(type_names); i++) {
        if (0 == strcmp(type, type_names[i])) {
            message->type = (TuiMessageType) i;
        }
    }

    gboolean ok = TRUE;
    switch (message->type) {
    case TUI_MSG_UNKNOWN:
    case TUI_MSG_BEEP:
    case TUI_MSG_CLOSE:
        break;
    case TUI_MSG_AUTH:
        message->token = g_strdup(member_string(object, "token"));
        ok = need(NULL != message->token, type, "token", error) && need(member_int(object, "protocol", &message->protocol), type, "protocol", error);
        break;
    case TUI_MSG_HELLO: {
        JsonObject *cell = member_object(object, "cell");
        message->os = g_strdup(member_string(object, "os"));
        message->appearance = g_strdup(member_string(object, "appearance"));
        ok = need(member_int(object, "protocol", &message->protocol), type, "protocol", error) && need(NULL != message->os, type, "os", error)
            && need(member_int(object, "columns", &message->columns), type, "columns", error)
            && need(member_int(object, "rows", &message->rows), type, "rows", error)
            && need(NULL != cell && member_number(cell, "width", &message->cell_width) && member_number(cell, "height", &message->cell_height), type, "cell", error)
            && need(NULL != message->appearance, type, "appearance", error);
        message->has_scale = member_number(object, "scale", &message->scale);
        if (NULL != member_array(object, "capabilities")) {
            message->has_capabilities = TRUE;
            message->capabilities = string_array(member_array(object, "capabilities"));
        }
        break;
    }
    case TUI_MSG_PATCH: {
        JsonArray *ops = member_array(object, "ops");
        ok = need(NULL != ops, type, "ops", error);
        message->ops = g_ptr_array_new_with_free_func(op_free);
        for (guint i = 0; ok && i < json_array_get_length(ops); i++) {
            JsonNode *node = json_array_get_element(ops, i);
            TuiOp *op = JSON_NODE_OBJECT == json_node_get_node_type(node) ? parse_op(json_node_get_object(node), error) : NULL;
            ok = NULL != op || (NULL != error && NULL != *error) ? NULL != op : need(FALSE, type, "op", error);
            if (ok) {
                g_ptr_array_add(message->ops, op);
            }
        }
        break;
    }
    case TUI_MSG_FOCUS:
        ok = need(node_or_null(object, "node", &message->node), type, "node", error);
        break;
    case TUI_MSG_WINDOW: {
        message->title = g_strdup(member_string(object, "title"));
        message->title_null = member_is_null(object, "title");
        message->has_title = NULL != message->title || message->title_null;
        if (member_is_null(object, "progress")) {
            message->has_progress = message->progress_null = TRUE;
        } else if (NULL != member_object(object, "progress")) {
            JsonObject *progress = member_object(object, "progress");
            message->has_progress = TRUE;
            message->progress_state = g_strdup(member_string(progress, "state"));
            ok = need(NULL != message->progress_state, type, "progress.state", error);
            if (member_is_null(progress, "value")) {
                message->has_progress_value = message->progress_value_null = TRUE;
            } else {
                message->has_progress_value = member_number(progress, "value", &message->progress_value);
            }
        }
        break;
    }
    case TUI_MSG_NOTIFY:
        message->title = g_strdup(member_string(object, "title"));
        message->has_title = NULL != message->title;
        message->body = g_strdup(member_string(object, "body"));
        message->has_body = NULL != message->body;
        ok = need(message->has_title, type, "title", error);
        break;
    case TUI_MSG_MENU: {
        JsonArray *menus = member_array(object, "menus");
        ok = need(NULL != menus, type, "menus", error);
        message->menus = g_ptr_array_new_with_free_func(menu_free);
        for (guint i = 0; ok && i < json_array_get_length(menus); i++) {
            JsonNode *node = json_array_get_element(menus, i);
            JsonObject *menu_object = JSON_NODE_OBJECT == json_node_get_node_type(node) ? json_node_get_object(node) : NULL;
            JsonArray *items = member_array(menu_object, "items");
            ok = need(NULL != member_string(menu_object, "title") && NULL != items, type, "menus[].title/items", error);
            if (!ok) {
                break;
            }
            TuiMenu *menu = g_new0(TuiMenu, 1);
            menu->title = g_strdup(member_string(menu_object, "title"));
            menu->items = g_ptr_array_new_with_free_func(menu_item_free);
            for (guint j = 0; j < json_array_get_length(items); j++) {
                JsonNode *item = json_array_get_element(items, j);
                if (JSON_NODE_OBJECT == json_node_get_node_type(item)) {
                    g_ptr_array_add(menu->items, parse_menu_item(json_node_get_object(item)));
                }
            }
            g_ptr_array_add(message->menus, menu);
        }
        break;
    }
    case TUI_MSG_CLOSE_REPLY:
        ok = need(member_bool(object, "accept", &message->accept), type, "accept", error);
        break;
    case TUI_MSG_BYE:
        message->has_status = member_int(object, "status", &message->status);
        break;
    case TUI_MSG_RESIZE:
        ok = need(member_int(object, "columns", &message->columns), type, "columns", error)
            && need(member_int(object, "rows", &message->rows), type, "rows", error);
        break;
    case TUI_MSG_EVENT:
        message->name = g_strdup(member_string(object, "name"));
        ok = need(member_int(object, "node", &message->node), type, "node", error) && need(NULL != message->name, type, "name", error);
        if (NULL != member(object, "value")) {
            message->value = json_node_copy(member(object, "value"));
        }
        break;
    case TUI_MSG_KEY:
        message->key = g_strdup(member_string(object, "key"));
        ok = need(NULL != message->key, type, "key", error);
        message->has_text = NULL != member(object, "text");
        message->text = g_strdup(member_string(object, "text"));
        break;
    case TUI_MSG_PASTE:
        message->text = g_strdup(member_string(object, "text"));
        message->has_text = NULL != message->text;
        ok = need(message->has_text, type, "text", error);
        break;
    case TUI_MSG_RECTS: {
        JsonObject *rects = member_object(object, "rects");
        ok = need(NULL != rects, type, "rects", error);
        message->rects = g_array_new(FALSE, TRUE, sizeof(TuiRect));
        g_autoptr(GList) members = ok ? json_object_get_members(rects) : NULL;
        for (GList *m = members; NULL != m; m = m->next) {
            JsonArray *values = member_array(rects, m->data);
            TuiRect rect = {.id = g_ascii_strtoll(m->data, NULL, 10)};
            ok = need(NULL != values && 4 == json_array_get_length(values), type, "rects[]", error);
            if (!ok) {
                break;
            }
            for (guint i = 0; i < 4; i++) {
                rect.rect[i] = json_array_get_int_element(values, i);
            }
            g_array_append_val(message->rects, rect);
        }
        break;
    }
    case TUI_MSG_MENU_SELECT:
        message->id = g_strdup(member_string(object, "id"));
        ok = need(NULL != message->id, type, "id", error);
        break;
    case TUI_MSG_APPEARANCE:
        message->appearance = g_strdup(member_string(object, "value"));
        ok = need(NULL != message->appearance, type, "value", error);
        break;
    case TUI_MSG_OPEN:
        ok = need(NULL != member_array(object, "paths"), type, "paths", error);
        message->paths = string_array(member_array(object, "paths"));
        break;
    case TUI_MSG_POINTER:
        message->action = g_strdup(member_string(object, "action"));
        message->button = g_strdup(member_string(object, "button"));
        ok = need(member_int(object, "node", &message->node), type, "node", error) && need(NULL != message->action, type, "action", error)
            && need(member_int(object, "row", &message->row), type, "row", error)
            && need(member_int(object, "column", &message->column), type, "column", error)
            && need(NULL != message->button || member_is_null(object, "button"), type, "button", error)
            && need(NULL != member_array(object, "modifiers"), type, "modifiers", error);
        message->modifiers = string_array(member_array(object, "modifiers"));
        message->has_scroll = member_int(object, "rows", &message->scroll_rows);
        message->has_scroll = member_int(object, "columns", &message->scroll_columns) && message->has_scroll;
        break;
    case TUI_MSG_CURSOR: {
        const char *shape = member_string(object, "shape");
        ok = need(member_int(object, "node", &message->node), type, "node", error) && need(NULL != shape, type, "shape", error);
        /* A keyword the contract does not list: the arrow, as for a shape the host has none for */
        message->shape = g_strdup(tui_cursor_shape_known(shape) ? shape : "default");
        break;
    }
    case TUI_MSG_CONTEXTMENU: {
        JsonArray *items = member_array(object, "items");
        ok = need(member_int(object, "node", &message->node), type, "node", error) && need(member_int(object, "row", &message->row), type, "row", error)
            && need(member_int(object, "column", &message->column), type, "column", error) && need(NULL != items, type, "items", error);
        message->items = g_ptr_array_new_with_free_func(menu_item_free);
        for (guint i = 0; ok && i < json_array_get_length(items); i++) {
            JsonNode *node = json_array_get_element(items, i);
            TuiMenuItem *item = JSON_NODE_OBJECT == json_node_get_node_type(node) ? parse_menu_item(json_node_get_object(node)) : NULL;
            /* A separator, or an item with its id and label */
            ok = need(NULL != item && ((item->has_separator && item->separator) || (NULL != item->id && NULL != item->label)), type, "items[]", error);
            if (NULL != item) {
                g_ptr_array_add(message->items, item);
            }
        }
        break;
    }
    case TUI_MSG_CONTEXTMENU_SELECT:
        message->id = g_strdup(member_string(object, "id"));
        ok = need(NULL != message->id, type, "id", error);
        break;
    case TUI_MSG_CLIPBOARD:
        message->text = g_strdup(member_string(object, "text"));
        message->has_text = NULL != message->text;
        ok = need(message->has_text, type, "text", error);
        break;
    }
    if (!ok) {
        tui_message_free(message);
        return NULL;
    }
    return message;
}

TuiMessage *tui_message_parse(const char *json, gssize length, GError **error)
{
    g_autoptr(JsonParser) parser = json_parser_new();
    if (!json_parser_load_from_data(parser, json, length, error)) {
        return NULL;
    }
    return tui_message_from_node(json_parser_get_root(parser), error);
}

static void add_menu_item(JsonBuilder *builder, const TuiMenuItem *item)
{
    json_builder_begin_object(builder);
    if (NULL != item->id) {
        json_builder_set_member_name(builder, "id");
        json_builder_add_string_value(builder, item->id);
    }
    if (NULL != item->label) {
        json_builder_set_member_name(builder, "label");
        json_builder_add_string_value(builder, item->label);
    }
    if (item->has_shortcut) {
        json_builder_set_member_name(builder, "shortcut");
        if (NULL != item->shortcut) {
            json_builder_add_string_value(builder, item->shortcut);
        } else {
            json_builder_add_null_value(builder);
        }
    }
    if (item->has_separator) {
        json_builder_set_member_name(builder, "separator");
        json_builder_add_boolean_value(builder, item->separator);
    }
    if (item->has_enabled) {
        json_builder_set_member_name(builder, "enabled");
        json_builder_add_boolean_value(builder, item->enabled);
    }
    if (item->has_checked) {
        json_builder_set_member_name(builder, "checked");
        if (item->checked_null) {
            json_builder_add_null_value(builder);
        } else {
            json_builder_add_boolean_value(builder, item->checked);
        }
    }
    if (NULL != item->role) {
        json_builder_set_member_name(builder, "role");
        json_builder_add_string_value(builder, item->role);
    }
    json_builder_end_object(builder);
}

static void add_op(JsonBuilder *builder, const TuiOp *op)
{
    json_builder_begin_object(builder);
    json_builder_set_member_name(builder, "op");
    json_builder_add_string_value(builder, op_names[op->op]);
    json_builder_set_member_name(builder, "id");
    json_builder_add_int_value(builder, op->id);
    if (TUI_OP_CREATE == op->op) {
        json_builder_set_member_name(builder, "kind");
        json_builder_add_string_value(builder, op->kind);
    }
    if (TUI_OP_CREATE == op->op || TUI_OP_MOVE == op->op) {
        json_builder_set_member_name(builder, "parent");
        if (0 == op->parent) {
            json_builder_add_null_value(builder);
        } else {
            json_builder_add_int_value(builder, op->parent);
        }
        json_builder_set_member_name(builder, "index");
        json_builder_add_int_value(builder, op->index);
    }
    if (NULL != op->props) {
        JsonNode *props = json_node_new(JSON_NODE_OBJECT);
        json_node_set_object(props, op->props);
        json_builder_set_member_name(builder, "props");
        json_builder_add_value(builder, props);
    }
    json_builder_end_object(builder);
}

#define MEMBER(name) json_builder_set_member_name(builder, name)

JsonNode *tui_message_to_node(const TuiMessage *message)
{
    g_autoptr(JsonBuilder) builder = json_builder_new();
    const char *type = TUI_MSG_UNKNOWN == message->type ? message->type_name : type_names[message->type];

    json_builder_begin_object(builder);
    MEMBER("type");
    json_builder_add_string_value(builder, NULL != type ? type : "unknown");
    switch (message->type) {
    case TUI_MSG_UNKNOWN:
    case TUI_MSG_BEEP:
    case TUI_MSG_CLOSE:
        break;
    case TUI_MSG_AUTH:
        MEMBER("token");
        json_builder_add_string_value(builder, message->token);
        MEMBER("protocol");
        json_builder_add_int_value(builder, message->protocol);
        break;
    case TUI_MSG_HELLO:
        MEMBER("protocol");
        json_builder_add_int_value(builder, message->protocol);
        MEMBER("os");
        json_builder_add_string_value(builder, message->os);
        MEMBER("columns");
        json_builder_add_int_value(builder, message->columns);
        MEMBER("rows");
        json_builder_add_int_value(builder, message->rows);
        MEMBER("cell");
        json_builder_begin_object(builder);
        MEMBER("width");
        json_builder_add_double_value(builder, message->cell_width);
        MEMBER("height");
        json_builder_add_double_value(builder, message->cell_height);
        json_builder_end_object(builder);
        if (message->has_scale) {
            MEMBER("scale");
            json_builder_add_double_value(builder, message->scale);
        }
        MEMBER("appearance");
        json_builder_add_string_value(builder, message->appearance);
        if (message->has_capabilities) {
            MEMBER("capabilities");
            json_builder_begin_array(builder);
            for (guint i = 0; NULL != message->capabilities && NULL != message->capabilities[i]; i++) {
                json_builder_add_string_value(builder, message->capabilities[i]);
            }
            json_builder_end_array(builder);
        }
        break;
    case TUI_MSG_PATCH:
        MEMBER("ops");
        json_builder_begin_array(builder);
        for (guint i = 0; NULL != message->ops && i < message->ops->len; i++) {
            add_op(builder, message->ops->pdata[i]);
        }
        json_builder_end_array(builder);
        break;
    case TUI_MSG_FOCUS:
        MEMBER("node");
        if (0 == message->node) {
            json_builder_add_null_value(builder);
        } else {
            json_builder_add_int_value(builder, message->node);
        }
        break;
    case TUI_MSG_WINDOW:
        if (message->has_title) {
            MEMBER("title");
            if (message->title_null) {
                json_builder_add_null_value(builder);
            } else {
                json_builder_add_string_value(builder, message->title);
            }
        }
        if (message->has_progress) {
            MEMBER("progress");
            if (message->progress_null) {
                json_builder_add_null_value(builder);
            } else {
                json_builder_begin_object(builder);
                MEMBER("state");
                json_builder_add_string_value(builder, message->progress_state);
                if (message->has_progress_value) {
                    MEMBER("value");
                    if (message->progress_value_null) {
                        json_builder_add_null_value(builder);
                    } else {
                        json_builder_add_double_value(builder, message->progress_value);
                    }
                }
                json_builder_end_object(builder);
            }
        }
        break;
    case TUI_MSG_NOTIFY:
        MEMBER("title");
        json_builder_add_string_value(builder, message->title);
        if (message->has_body) {
            MEMBER("body");
            json_builder_add_string_value(builder, message->body);
        }
        break;
    case TUI_MSG_MENU:
        MEMBER("menus");
        json_builder_begin_array(builder);
        for (guint i = 0; NULL != message->menus && i < message->menus->len; i++) {
            TuiMenu *menu = message->menus->pdata[i];
            json_builder_begin_object(builder);
            MEMBER("title");
            json_builder_add_string_value(builder, menu->title);
            MEMBER("items");
            json_builder_begin_array(builder);
            for (guint j = 0; j < menu->items->len; j++) {
                add_menu_item(builder, menu->items->pdata[j]);
            }
            json_builder_end_array(builder);
            json_builder_end_object(builder);
        }
        json_builder_end_array(builder);
        break;
    case TUI_MSG_CLOSE_REPLY:
        MEMBER("accept");
        json_builder_add_boolean_value(builder, message->accept);
        break;
    case TUI_MSG_BYE:
        if (message->has_status) {
            MEMBER("status");
            json_builder_add_int_value(builder, message->status);
        }
        break;
    case TUI_MSG_RESIZE:
        MEMBER("columns");
        json_builder_add_int_value(builder, message->columns);
        MEMBER("rows");
        json_builder_add_int_value(builder, message->rows);
        break;
    case TUI_MSG_EVENT:
        MEMBER("node");
        json_builder_add_int_value(builder, message->node);
        MEMBER("name");
        json_builder_add_string_value(builder, message->name);
        if (NULL != message->value) {
            MEMBER("value");
            json_builder_add_value(builder, json_node_copy(message->value));
        }
        break;
    case TUI_MSG_KEY:
        MEMBER("key");
        json_builder_add_string_value(builder, message->key);
        if (message->has_text) {
            MEMBER("text");
            if (NULL != message->text) {
                json_builder_add_string_value(builder, message->text);
            } else {
                json_builder_add_null_value(builder);
            }
        }
        break;
    case TUI_MSG_PASTE:
        MEMBER("text");
        json_builder_add_string_value(builder, message->text);
        break;
    case TUI_MSG_RECTS:
        MEMBER("rects");
        json_builder_begin_object(builder);
        for (guint i = 0; NULL != message->rects && i < message->rects->len; i++) {
            TuiRect *rect = &g_array_index(message->rects, TuiRect, i);
            char key[24];
            g_snprintf(key, sizeof key, "%" G_GINT64_FORMAT, rect->id);
            MEMBER(key);
            json_builder_begin_array(builder);
            for (guint j = 0; j < 4; j++) {
                json_builder_add_int_value(builder, rect->rect[j]);
            }
            json_builder_end_array(builder);
        }
        json_builder_end_object(builder);
        break;
    case TUI_MSG_MENU_SELECT:
        MEMBER("id");
        json_builder_add_string_value(builder, message->id);
        break;
    case TUI_MSG_APPEARANCE:
        MEMBER("value");
        json_builder_add_string_value(builder, message->appearance);
        break;
    case TUI_MSG_OPEN:
        MEMBER("paths");
        json_builder_begin_array(builder);
        for (guint i = 0; NULL != message->paths && NULL != message->paths[i]; i++) {
            json_builder_add_string_value(builder, message->paths[i]);
        }
        json_builder_end_array(builder);
        break;
    case TUI_MSG_POINTER:
        MEMBER("node");
        json_builder_add_int_value(builder, message->node);
        MEMBER("action");
        json_builder_add_string_value(builder, message->action);
        MEMBER("row");
        json_builder_add_int_value(builder, message->row);
        MEMBER("column");
        json_builder_add_int_value(builder, message->column);
        MEMBER("button");
        if (NULL != message->button) {
            json_builder_add_string_value(builder, message->button);
        } else {
            json_builder_add_null_value(builder);
        }
        MEMBER("modifiers");
        json_builder_begin_array(builder);
        for (guint i = 0; NULL != message->modifiers && NULL != message->modifiers[i]; i++) {
            json_builder_add_string_value(builder, message->modifiers[i]);
        }
        json_builder_end_array(builder);
        if (message->has_scroll) {
            MEMBER("rows");
            json_builder_add_int_value(builder, message->scroll_rows);
            MEMBER("columns");
            json_builder_add_int_value(builder, message->scroll_columns);
        }
        break;
    case TUI_MSG_CURSOR:
        MEMBER("node");
        json_builder_add_int_value(builder, message->node);
        MEMBER("shape");
        json_builder_add_string_value(builder, NULL != message->shape ? message->shape : "default");
        break;
    case TUI_MSG_CONTEXTMENU:
        MEMBER("node");
        json_builder_add_int_value(builder, message->node);
        MEMBER("row");
        json_builder_add_int_value(builder, message->row);
        MEMBER("column");
        json_builder_add_int_value(builder, message->column);
        MEMBER("items");
        json_builder_begin_array(builder);
        for (guint i = 0; NULL != message->items && i < message->items->len; i++) {
            add_menu_item(builder, message->items->pdata[i]);
        }
        json_builder_end_array(builder);
        break;
    case TUI_MSG_CONTEXTMENU_SELECT:
        MEMBER("id");
        json_builder_add_string_value(builder, message->id);
        break;
    case TUI_MSG_CLIPBOARD:
        MEMBER("text");
        json_builder_add_string_value(builder, message->text);
        break;
    }
    json_builder_end_object(builder);
    return json_builder_get_root(builder);
}

char *tui_message_to_json(const TuiMessage *message)
{
    g_autoptr(JsonNode) node = tui_message_to_node(message);
    return json_to_string(node, FALSE);
}

GBytes *tui_message_to_frame(const TuiMessage *message)
{
    g_autofree char *json = tui_message_to_json(message);
    return tui_frame_encode(json, strlen(json));
}

/* ======================================================================== */
/* Node tree                                                                */
/* ======================================================================== */

static void node_free(gpointer data)
{
    TuiNode *node = data;
    g_free(node->kind);
    json_object_unref(node->props);
    g_ptr_array_unref(node->children);
    g_free(node);
}

TuiTree *tui_tree_new(void)
{
    TuiTree *tree = g_new0(TuiTree, 1);
    tree->nodes = g_hash_table_new_full(g_int64_hash, g_int64_equal, NULL, node_free);
    return tree;
}

void tui_tree_free(TuiTree *tree)
{
    if (NULL != tree) {
        g_hash_table_unref(tree->nodes);
        g_free(tree);
    }
}

TuiNode *tui_tree_get(TuiTree *tree, gint64 id)
{
    return g_hash_table_lookup(tree->nodes, &id);
}

const char *tui_acknowledged_prop(const char *kind, const char *event, JsonNode *value)
{
    GType type = NULL != value && JSON_NODE_HOLDS_VALUE(value) ? json_node_get_value_type(value) : G_TYPE_INVALID;
    gboolean string = G_TYPE_STRING == type;
    gboolean integer = G_TYPE_INT64 == type;
    gboolean array = NULL != value && JSON_NODE_HOLDS_ARRAY(value);

    if (NULL == kind || NULL == event) {
        return NULL;
    }
    if ((0 == strcmp(event, "change") || 0 == strcmp(event, "submit")) && string
        && (0 == strcmp(kind, "textfield") || 0 == strcmp(kind, "password") || 0 == strcmp(kind, "textarea") || 0 == strcmp(kind, "number"))) {
        return "value";
    }
    if (0 == strcmp(event, "change") && (integer || G_TYPE_DOUBLE == type) && 0 == strcmp(kind, "slider")) {
        return "value";
    }
    if (0 == strcmp(event, "toggle") && G_TYPE_BOOLEAN == type) {
        return 0 == strcmp(kind, "checkbox") ? "checked" : 0 == strcmp(kind, "disclosure") ? "expanded" : NULL;
    }
    if (0 == strcmp(event, "select")) {
        if (integer && (0 == strcmp(kind, "popup") || 0 == strcmp(kind, "list"))) {
            return "selected";
        }
        if (array) {
            return 0 == strcmp(kind, "listbox") ? "selected" : 0 == strcmp(kind, "list") ? "checked" : NULL;
        }
    }
    if (0 == strcmp(event, "tab") && integer && 0 == strcmp(kind, "tabs")) {
        return "active";
    }
    return NULL;
}

gboolean tui_tree_acknowledge(TuiTree *tree, gint64 id, const char *event, JsonNode *value)
{
    TuiNode *node = tui_tree_get(tree, id);
    const char *name = NULL != node ? tui_acknowledged_prop(node->kind, event, value) : NULL;

    if (NULL == name || (json_object_has_member(node->props, name) && tui_json_equal(json_object_get_member(node->props, name), value))) {
        return FALSE;
    }
    json_object_set_member(node->props, name, json_node_copy(value));
    return TRUE;
}

void tui_changes_init(TuiChanges *changes)
{
    changes->created = g_array_new(FALSE, FALSE, sizeof(gint64));
    changes->updated = g_array_new(FALSE, FALSE, sizeof(gint64));
    changes->removed = g_array_new(FALSE, FALSE, sizeof(gint64));
    changes->relinked = g_array_new(FALSE, FALSE, sizeof(gint64));
}

void tui_changes_clear(TuiChanges *changes)
{
    g_clear_pointer(&changes->created, g_array_unref);
    g_clear_pointer(&changes->updated, g_array_unref);
    g_clear_pointer(&changes->removed, g_array_unref);
    g_clear_pointer(&changes->relinked, g_array_unref);
}

static void note(GArray *ids, gint64 id)
{
    if (NULL == ids) {
        return;
    }
    for (guint i = 0; i < ids->len; i++) {
        if (g_array_index(ids, gint64, i) == id) {
            return;
        }
    }
    g_array_append_val(ids, id);
}

static void detach(TuiTree *tree, TuiNode *node, TuiChanges *changes)
{
    if (NULL != node->parent) {
        g_ptr_array_remove(node->parent->children, node);
        note(NULL != changes ? changes->relinked : NULL, node->parent->id);
        node->parent = NULL;
    } else if (tree->root == node) {
        tree->root = NULL;
    }
}

static void attach(TuiTree *tree, TuiNode *node, TuiNode *parent, gint64 index, TuiChanges *changes)
{
    if (NULL == parent) {
        tree->root = node;
        return;
    }
    guint at = index < 0 ? 0 : MIN((guint) index, parent->children->len);
    g_ptr_array_insert(parent->children, (gint) at, node);
    node->parent = parent;
    note(NULL != changes ? changes->relinked : NULL, parent->id);
}

static void remove_subtree(TuiTree *tree, TuiNode *node, TuiChanges *changes)
{
    while (node->children->len > 0) {
        TuiNode *child = node->children->pdata[node->children->len - 1];
        g_ptr_array_remove_index(node->children, node->children->len - 1);
        child->parent = NULL;
        remove_subtree(tree, child, changes);
    }
    note(NULL != changes ? changes->removed : NULL, node->id);
    gint64 id = node->id;
    g_hash_table_remove(tree->nodes, &id);
}

static void merge_props(JsonObject *props, JsonObject *changes)
{
    g_autoptr(GList) members = json_object_get_members(changes);
    for (GList *m = members; NULL != m; m = m->next) {
        JsonNode *value = json_object_get_member(changes, m->data);
        if (JSON_NODE_NULL == json_node_get_node_type(value)) {
            json_object_remove_member(props, m->data);
        } else {
            json_object_set_member(props, m->data, json_node_copy(value));
        }
    }
}

static gboolean contains(TuiNode *ancestor, TuiNode *node)
{
    for (; NULL != node; node = node->parent) {
        if (node == ancestor) {
            return TRUE;
        }
    }
    return FALSE;
}

gboolean tui_tree_apply(TuiTree *tree, const TuiMessage *patch, TuiChanges *changes, GError **error)
{
    GString *problems = g_string_new(NULL);

    for (guint i = 0; NULL != patch->ops && i < patch->ops->len; i++) {
        TuiOp *op = patch->ops->pdata[i];
        TuiNode *node = tui_tree_get(tree, op->id);
        TuiNode *parent = 0 != op->parent ? tui_tree_get(tree, op->parent) : NULL;

        switch (op->op) {
        case TUI_OP_CREATE: {
            if (0 != op->parent && NULL == parent) {
                g_string_append_printf(problems, "create %" G_GINT64_FORMAT ": no parent %" G_GINT64_FORMAT "; ", op->id, op->parent);
                break;
            }
            if (NULL != node) {
                detach(tree, node, changes);
                remove_subtree(tree, node, changes);
            }
            if (0 == op->parent && NULL != tree->root) {
                TuiNode *old = tree->root;
                detach(tree, old, changes);
                remove_subtree(tree, old, changes);
            }
            node = g_new0(TuiNode, 1);
            node->id = op->id;
            node->kind = g_strdup(op->kind);
            node->props = json_object_new();
            node->children = g_ptr_array_new();
            merge_props(node->props, op->props);
            g_hash_table_insert(tree->nodes, &node->id, node);
            attach(tree, node, parent, op->index, changes);
            note(NULL != changes ? changes->created : NULL, node->id);
            break;
        }
        case TUI_OP_UPDATE:
            if (NULL == node) {
                g_string_append_printf(problems, "update %" G_GINT64_FORMAT ": no such node; ", op->id);
                break;
            }
            merge_props(node->props, op->props);
            note(NULL != changes ? changes->updated : NULL, node->id);
            break;
        case TUI_OP_MOVE:
            if (NULL == node || NULL == parent || contains(node, parent)) {
                g_string_append_printf(problems, "move %" G_GINT64_FORMAT ": bad node or parent; ", op->id);
                break;
            }
            detach(tree, node, changes);
            attach(tree, node, parent, op->index, changes);
            break;
        case TUI_OP_REMOVE:
            if (NULL == node) {
                g_string_append_printf(problems, "remove %" G_GINT64_FORMAT ": no such node; ", op->id);
                break;
            }
            detach(tree, node, changes);
            remove_subtree(tree, node, changes);
            break;
        }
    }
    if (problems->len > 0) {
        g_set_error(error, TUI_ERROR, 3, "%s", problems->str);
        g_string_free(problems, TRUE);
        return FALSE;
    }
    g_string_free(problems, TRUE);
    return TRUE;
}

static gint compare_strings(gconstpointer a, gconstpointer b)
{
    return strcmp(a, b);
}

static JsonNode *node_to_json(TuiNode *node)
{
    JsonObject *object = json_object_new();
    JsonObject *props = json_object_new();
    JsonArray *children = json_array_new();
    GList *members = g_list_sort(json_object_get_members(node->props), compare_strings);

    for (GList *m = members; NULL != m; m = m->next) {
        JsonNode *value = json_object_get_member(node->props, m->data);
        if (JSON_NODE_NULL != json_node_get_node_type(value)) {
            json_object_set_member(props, m->data, json_node_copy(value));
        }
    }
    g_list_free(members);
    for (guint i = 0; i < node->children->len; i++) {
        json_array_add_element(children, node_to_json(node->children->pdata[i]));
    }
    json_object_set_int_member(object, "id", node->id);
    json_object_set_string_member(object, "kind", node->kind);
    json_object_set_object_member(object, "props", props);
    json_object_set_array_member(object, "children", children);

    JsonNode *result = json_node_new(JSON_NODE_OBJECT);
    json_node_take_object(result, object);
    return result;
}

JsonNode *tui_tree_to_node(TuiTree *tree)
{
    return NULL != tree->root ? node_to_json(tree->root) : json_node_new(JSON_NODE_NULL);
}

/* ======================================================================== */
/* Keys                                                                     */
/* ======================================================================== */

/* GDK keyvals (gdk/gdkkeysyms.h), so this table needs no GTK. */
static const struct {
    guint keyval;
    const char *name;
} named_keys[] = {
    {0xff0d, "enter"}, {0xff8d, "enter"}, {0xff1b, "escape"}, {0xff09, "tab"}, {0xfe20, "tab"},
    {0x0020, "space"}, {0xff80, "space"}, {0xff08, "backspace"}, {0xffff, "delete"}, {0xff9f, "delete"},
    {0xff63, "insert"}, {0xff9e, "insert"}, {0xff50, "home"}, {0xff95, "home"}, {0xff57, "end"},
    {0xff9c, "end"}, {0xff55, "page_up"}, {0xff9a, "page_up"}, {0xff56, "page_down"}, {0xff9b, "page_down"},
    {0xff52, "up"}, {0xff97, "up"}, {0xff54, "down"}, {0xff99, "down"}, {0xff51, "left"},
    {0xff96, "left"}, {0xff53, "right"}, {0xff98, "right"},
    {0xffbe, "f1"}, {0xffbf, "f2"}, {0xffc0, "f3"}, {0xffc1, "f4"}, {0xffc2, "f5"}, {0xffc3, "f6"},
    {0xffc4, "f7"}, {0xffc5, "f8"}, {0xffc6, "f9"}, {0xffc7, "f10"}, {0xffc8, "f11"}, {0xffc9, "f12"},
};

/* The GDK keyval names GTK accelerators use for the Tui names above. */
static const struct {
    const char *tui;
    const char *gdk;
} accel_names[] = {
    {"enter", "Return"}, {"escape", "Escape"}, {"tab", "Tab"}, {"space", "space"}, {"backspace", "BackSpace"},
    {"delete", "Delete"}, {"insert", "Insert"}, {"home", "Home"}, {"end", "End"}, {"page_up", "Page_Up"},
    {"page_down", "Page_Down"}, {"up", "Up"}, {"down", "Down"}, {"left", "Left"}, {"right", "Right"},
    {",", "comma"}, {".", "period"}, {"/", "slash"}, {";", "semicolon"}, {"'", "apostrophe"},
    {"[", "bracketleft"}, {"]", "bracketright"}, {"\\", "backslash"}, {"-", "minus"}, {"=", "equal"},
    {"`", "grave"}, {"+", "plus"},
};

const char *tui_keyval_name(guint keyval)
{
    for (guint i = 0; i < G_N_ELEMENTS(named_keys); i++) {
        if (named_keys[i].keyval == keyval) {
            return named_keys[i].name;
        }
    }
    return NULL;
}

gboolean tui_keyval_is_modifier(guint keyval)
{
    return (keyval >= 0xffe1 && keyval <= 0xffee) /* Shift_L ... Hyper_R, Caps_Lock */
        || (keyval >= 0xfe01 && keyval <= 0xfe13) /* ISO_Lock ... ISO_Level5_Lock */
        || 0xff7e == keyval /* Mode_switch */
        || 0xff7f == keyval; /* Num_Lock */
}

char *tui_key_id(guint keyval, gunichar unicode, guint mods, char **text)
{
    const char *name = tui_keyval_name(keyval);
    char typed[8] = {0};
    gboolean printable = NULL == name && 0 != unicode && g_unichar_isprint(unicode);

    if (NULL != text) {
        *text = NULL;
    }
    if (tui_keyval_is_modifier(keyval) || (NULL == name && !printable)) {
        return NULL;
    }
    if (0xfe20 == keyval) {
        mods |= TUI_MOD_SHIFT; /* ISO_Left_Tab is Shift+Tab */
    }
    if (printable) {
        gunichar lower = g_unichar_tolower(unicode);
        g_unichar_to_utf8(unicode, typed);
        /* A capital letter carries its shift; other shifted characters (! @ ...) already say it. */
        if (lower == unicode && !g_unichar_isalpha(unicode)) {
            mods &= ~(guint) TUI_MOD_SHIFT;
        }
        if (lower != unicode) {
            mods |= TUI_MOD_SHIFT;
        }
    }
    GString *id = g_string_new(NULL);
    if (mods & TUI_MOD_CTRL) {
        g_string_append(id, "ctrl+");
    }
    if (mods & TUI_MOD_SHIFT) {
        g_string_append(id, "shift+");
    }
    if (mods & TUI_MOD_ALT) {
        g_string_append(id, "alt+");
    }
    if (mods & TUI_MOD_SUPER) {
        g_string_append(id, "super+");
    }
    if (NULL != name) {
        g_string_append(id, name);
    } else {
        g_string_append_unichar(id, g_unichar_tolower(unicode));
    }
    if (NULL != text && 0 == (mods & (TUI_MOD_CTRL | TUI_MOD_ALT | TUI_MOD_SUPER))) {
        if (printable) {
            *text = g_strdup(typed);
        } else if (0x20 == keyval || 0xff80 == keyval) {
            *text = g_strdup(" ");
        }
    }
    return g_string_free(id, FALSE);
}

/* Whether a code point joins the character before it rather than starting one. */
static gboolean tui_extends(gunichar previous, gunichar c, gboolean *pending_join, guint *regional)
{
    if (*pending_join) {
        *pending_join = FALSE;
        return TRUE; /* after a zero-width joiner */
    }
    if (0x200d == c) {
        *pending_join = TRUE;
        return TRUE;
    }
    if (g_unichar_ismark(c) || (c >= 0xfe00 && c <= 0xfe0f) || (c >= 0x1f3fb && c <= 0x1f3ff) || (c >= 0xe0020 && c <= 0xe007f)
        || (c >= 0xe0100 && c <= 0xe01ef)) {
        return TRUE;
    }
    /* Regional indicators go in pairs: a flag. */
    if (c >= 0x1f1e6 && c <= 0x1f1ff) {
        gboolean joins = previous >= 0x1f1e6 && previous <= 0x1f1ff && 1 == *regional % 2;
        (*regional)++;
        return joins;
    }
    *regional = 0;
    return FALSE;
}

char **tui_committed_keys(const char *text)
{
    GPtrArray *out = g_ptr_array_new();
    GPtrArray *characters = g_ptr_array_new_with_free_func(g_free);
    GString *current = NULL;
    gunichar previous = 0;
    gboolean pending_join = FALSE;
    guint regional = 0;

    if (NULL == text || !g_utf8_validate(text, -1, NULL)) {
        g_ptr_array_add(out, NULL);
        g_ptr_array_free(characters, TRUE);
        return (char **) g_ptr_array_free(out, FALSE);
    }
    for (const char *p = text; '\0' != *p; p = g_utf8_next_char(p)) {
        gunichar c = g_utf8_get_char(p);
        if (NULL != current && tui_extends(previous, c, &pending_join, &regional)) {
            g_string_append_unichar(current, c);
        } else {
            if (NULL != current) {
                g_ptr_array_add(characters, g_string_free(current, FALSE));
            }
            current = g_string_new(NULL);
            g_string_append_unichar(current, c);
            if (c >= 0x1f1e6 && c <= 0x1f1ff) {
                regional = 1;
            }
        }
        previous = c;
    }
    if (NULL != current) {
        g_ptr_array_add(characters, g_string_free(current, FALSE));
    }
    for (guint i = 0; i < characters->len; i++) {
        const char *character = characters->pdata[i];
        gunichar first = g_utf8_get_char(character);
        if (g_unichar_iscntrl(first) || 0x7f == first) {
            continue;
        }
        char *key;
        char *lower = g_utf8_strdown(character, -1);
        if (' ' == first && '\0' == character[1]) {
            key = g_strdup("space");
        } else if (0 != strcmp(lower, character)) {
            /* An uppercase letter, also one with combining marks (E and an acute) */
            key = g_strconcat("shift+", lower, NULL);
        } else {
            key = g_strdup(lower);
        }
        g_free(lower);
        g_ptr_array_add(out, key);
        g_ptr_array_add(out, g_strdup(character));
    }
    g_ptr_array_add(out, NULL);
    g_ptr_array_free(characters, TRUE);
    return (char **) g_ptr_array_free(out, FALSE);
}

char *tui_key_accel(const char *key_id)
{
    static const struct {
        const char *prefix;
        const char *accel;
    } modifiers[] = {{"ctrl+", "<Control>"}, {"shift+", "<Shift>"}, {"alt+", "<Alt>"}, {"super+", "<Super>"}};
    GString *accel = g_string_new(NULL);
    const char *rest = key_id;

    if (NULL == key_id || '\0' == *key_id) {
        g_string_free(accel, TRUE);
        return NULL;
    }
    for (gboolean found = TRUE; found;) {
        found = FALSE;
        for (guint i = 0; i < G_N_ELEMENTS(modifiers); i++) {
            gsize length = strlen(modifiers[i].prefix);
            if (0 == strncmp(rest, modifiers[i].prefix, length) && '\0' != rest[length]) {
                g_string_append(accel, modifiers[i].accel);
                rest += length;
                found = TRUE;
            }
        }
    }
    for (guint i = 0; i < G_N_ELEMENTS(accel_names); i++) {
        if (0 == strcmp(rest, accel_names[i].tui)) {
            g_string_append(accel, accel_names[i].gdk);
            return g_string_free(accel, FALSE);
        }
    }
    if ('f' == rest[0] && g_ascii_isdigit(rest[1])) {
        gint64 number = g_ascii_strtoll(rest + 1, NULL, 10);
        if (number >= 1 && number <= 12) {
            g_string_append_printf(accel, "F%" G_GINT64_FORMAT, number);
            return g_string_free(accel, FALSE);
        }
    }
    if (1 == g_utf8_strlen(rest, -1) && g_unichar_isalnum(g_utf8_get_char(rest))) {
        g_string_append(accel, rest);
        return g_string_free(accel, FALSE);
    }
    g_string_free(accel, TRUE);
    return NULL;
}

/* ======================================================================== */
/* Manifest and launch                                                      */
/* ======================================================================== */

static void file_association_free(gpointer data)
{
    TuiFileAssociation *association = data;
    g_free(association->extension);
    g_free(association->name);
    g_free(association->mime_type);
    g_free(association->role);
    g_free(association);
}

static TuiManifest *manifest_defaults(void)
{
    TuiManifest *manifest = g_new0(TuiManifest, 1);
    manifest->name = g_strdup("TuiApp");
    manifest->id = g_strdup("dev.tuiapp.Host");
    manifest->version = g_strdup("0.0.0");
    manifest->title = g_strdup("TuiApp");
    manifest->width = 900;
    manifest->height = 640;
    manifest->resizable = TRUE;
    manifest->theme = g_strdup("system");
    manifest->font_size = 13;
    manifest->menu_native = TRUE;
    manifest->menu_quit = TRUE;
    manifest->args = g_new0(char *, 1);
    manifest->env_pass = g_new0(char *, 1);
    manifest->env_set = g_new0(char *, 1);
    manifest->categories = g_new0(char *, 1);
    manifest->url_schemes = g_new0(char *, 1);
    manifest->file_associations = g_ptr_array_new_with_free_func(file_association_free);
    manifest->log_level = g_strdup("warning");
    return manifest;
}

static void replace(char **field, const char *value)
{
    g_free(*field);
    *field = g_strdup(value);
}

static void replace_strv(char ***field, char **value)
{
    g_strfreev(*field);
    *field = value;
}

TuiManifest *tui_manifest_parse(const char *json, gssize length, const char *dir, GError **error)
{
    g_autoptr(JsonParser) parser = json_parser_new();
    if (!json_parser_load_from_data(parser, json, length, error)) {
        return NULL;
    }
    JsonNode *root = json_parser_get_root(parser);
    JsonObject *object = JSON_NODE_OBJECT == json_node_get_node_type(root) ? json_node_get_object(root) : NULL;
    JsonObject *app = member_object(object, "app");
    JsonObject *window = member_object(object, "window");
    JsonObject *launch = member_object(object, "launch");
    gint64 version = 0;

    if (NULL == object || !member_int(object, "manifest", &version) || 1 != version) {
        g_set_error(error, TUI_ERROR, 4, "not a version 1 TuiApp manifest");
        return NULL;
    }
    if (NULL == app || NULL == member_string(app, "name") || NULL == member_string(app, "id") || NULL == member_string(app, "version")) {
        g_set_error(error, TUI_ERROR, 4, "manifest: app needs name, id and version");
        return NULL;
    }
    if (NULL == window || NULL == member_string(window, "title") || NULL == member(window, "width") || NULL == member(window, "height")) {
        g_set_error(error, TUI_ERROR, 4, "manifest: window needs title, width and height");
        return NULL;
    }
    if (NULL == member_object(object, "menu")) {
        g_set_error(error, TUI_ERROR, 4, "manifest: menu is missing");
        return NULL;
    }
    if (NULL == launch || NULL == member_string(launch, "mode") || NULL == member_array(launch, "args")) {
        g_set_error(error, TUI_ERROR, 4, "manifest: launch needs mode and args");
        return NULL;
    }

    TuiManifest *manifest = manifest_defaults();
    replace(&manifest->dir, dir);
    replace(&manifest->name, member_string(app, "name"));
    replace(&manifest->id, member_string(app, "id"));
    replace(&manifest->version, member_string(app, "version"));
    replace(&manifest->description, member_string(app, "description"));
    replace(&manifest->publisher, member_string(app, "publisher"));
    replace(&manifest->copyright, member_string(app, "copyright"));
    replace(&manifest->homepage, member_string(app, "homepage"));
    member_bool(app, "singleInstance", &manifest->single_instance);
    if (NULL != member_string(app, "category")) {
        replace_strv(&manifest->categories, g_strsplit(tui_desktop_categories(member_string(app, "category")), ";", -1));
        /* g_strsplit leaves an empty last element after the final ; */
        guint length = g_strv_length(manifest->categories);
        if (length > 0 && '\0' == manifest->categories[length - 1][0]) {
            g_free(manifest->categories[length - 1]);
            manifest->categories[length - 1] = NULL;
        }
    }

    replace(&manifest->title, member_string(window, "title"));
    member_int(window, "width", &manifest->width);
    member_int(window, "height", &manifest->height);
    member_int(window, "minWidth", &manifest->min_width);
    member_int(window, "minHeight", &manifest->min_height);
    member_bool(window, "resizable", &manifest->resizable);
    member_bool(window, "rememberGeometry", &manifest->remember_geometry);
    if (NULL != member_string(window, "theme")) {
        replace(&manifest->theme, member_string(window, "theme"));
    }
    JsonObject *font = member_object(window, "canvasFont");
    replace(&manifest->font_family, member_string(font, "family"));
    member_number(font, "size", &manifest->font_size);
    if (manifest->font_size <= 0) {
        manifest->font_size = 13;
    }

    JsonObject *menu = member_object(object, "menu");
    member_bool(menu, "native", &manifest->menu_native);
    member_bool(menu, "about", &manifest->menu_about);
    member_bool(menu, "quit", &manifest->menu_quit);

    replace(&manifest->mode, member_string(launch, "mode"));
    replace(&manifest->command, member_string(launch, "command"));
    replace_strv(&manifest->args, string_array(member_array(launch, "args")));
    JsonObject *php = member_object(launch, "php");
    replace(&manifest->php_path, member_string(php, "path"));
    replace(&manifest->php_min_version, member_string(php, "minVersion"));
    JsonObject *env = member_object(launch, "env");
    if (NULL != member_array(env, "pass")) {
        replace_strv(&manifest->env_pass, string_array(member_array(env, "pass")));
    }
    JsonObject *set = member_object(env, "set");
    if (NULL != set) {
        GPtrArray *pairs = g_ptr_array_new();
        g_autoptr(GList) names = json_object_get_members(set);
        for (GList *m = names; NULL != m; m = m->next) {
            const char *value = member_string(set, m->data);
            if (NULL != value) {
                g_ptr_array_add(pairs, g_strdup_printf("%s=%s", (const char *) m->data, value));
            }
        }
        g_ptr_array_add(pairs, NULL);
        replace_strv(&manifest->env_set, (char **) g_ptr_array_free(pairs, FALSE));
    }

    JsonObject *logging = member_object(object, "logging");
    replace(&manifest->log_file, member_string(logging, "file"));
    if (NULL != member_string(logging, "level")) {
        replace(&manifest->log_level, member_string(logging, "level"));
    }

    JsonArray *associations = member_array(app, "fileAssociations");
    for (guint i = 0; NULL != associations && i < json_array_get_length(associations); i++) {
        JsonNode *node = json_array_get_element(associations, i);
        JsonObject *entry = JSON_NODE_OBJECT == json_node_get_node_type(node) ? json_node_get_object(node) : NULL;
        const char *extension = member_string(entry, "extension");
        if (NULL == extension || '\0' == *extension) {
            continue;
        }
        TuiFileAssociation *association = g_new0(TuiFileAssociation, 1);
        association->extension = g_strdup('.' == *extension ? extension + 1 : extension);
        association->name = g_strdup(member_string(entry, "name"));
        association->role = g_strdup(NULL != member_string(entry, "role") ? member_string(entry, "role") : "editor");
        association->mime_type = g_strdup(member_string(entry, "mimeType"));
        if (NULL == association->mime_type) {
            association->mime_type = g_strdup_printf("application/x-%s-%s", manifest->id, association->extension);
            association->generated = TRUE;
        }
        g_ptr_array_add(manifest->file_associations, association);
    }
    if (NULL != member_array(app, "urlSchemes")) {
        replace_strv(&manifest->url_schemes, string_array(member_array(app, "urlSchemes")));
    }
    return manifest;
}

TuiManifest *tui_manifest_load(const char *path, GError **error)
{
    g_autofree char *contents = NULL;
    gsize length = 0;
    if (!g_file_get_contents(path, &contents, &length, error)) {
        return NULL;
    }
    g_autofree char *absolute = g_canonicalize_filename(path, NULL);
    g_autofree char *dir = g_path_get_dirname(absolute);
    return tui_manifest_parse(contents, (gssize) length, dir, error);
}

TuiManifest *tui_manifest_dev(char **command)
{
    TuiManifest *manifest = manifest_defaults();
    manifest->dir = g_get_current_dir();
    manifest->mode = g_strdup("dev");
    manifest->keep_environment = TRUE;
    if (NULL != command && NULL != command[0]) {
        manifest->command = g_strdup(command[0]);
        replace_strv(&manifest->args, g_strdupv(command + 1));
    }
    return manifest;
}

void tui_manifest_free(TuiManifest *manifest)
{
    if (NULL == manifest) {
        return;
    }
    g_free(manifest->dir);
    g_free(manifest->name);
    g_free(manifest->id);
    g_free(manifest->version);
    g_free(manifest->description);
    g_free(manifest->publisher);
    g_free(manifest->copyright);
    g_free(manifest->homepage);
    g_free(manifest->title);
    g_free(manifest->theme);
    g_free(manifest->font_family);
    g_free(manifest->mode);
    g_free(manifest->command);
    g_strfreev(manifest->args);
    g_free(manifest->php_path);
    g_free(manifest->php_min_version);
    g_strfreev(manifest->env_pass);
    g_strfreev(manifest->env_set);
    g_free(manifest->log_file);
    g_free(manifest->log_level);
    g_strfreev(manifest->categories);
    g_ptr_array_unref(manifest->file_associations);
    g_strfreev(manifest->url_schemes);
    g_free(manifest);
}

int tui_version_compare(const char *a, const char *b)
{
    const char *x = NULL != a ? a : "";
    const char *y = NULL != b ? b : "";

    while ('\0' != *x || '\0' != *y) {
        char *end_x;
        char *end_y;
        guint64 p = g_ascii_strtoull(x, &end_x, 10);
        guint64 q = g_ascii_strtoull(y, &end_y, 10);
        if (p != q) {
            return p < q ? -1 : 1;
        }
        /* Past the number, then past the dot; anything else ("-dev", "RC1") ends the comparison. */
        x = '.' == *end_x ? end_x + 1 : (end_x == x ? x + strlen(x) : end_x);
        y = '.' == *end_y ? end_y + 1 : (end_y == y ? y + strlen(y) : end_y);
        if (!g_ascii_isdigit(*x)) {
            x += strlen(x);
        }
        if (!g_ascii_isdigit(*y)) {
            y += strlen(y);
        }
    }
    return 0;
}

static char *resolve(const TuiManifest *manifest, const char *path)
{
    if (NULL == path) {
        return NULL;
    }
    if (g_str_has_prefix(path, "./") || g_str_has_prefix(path, "../")) {
        return g_build_filename(manifest->dir, path, NULL);
    }
    return g_strdup(path);
}

char **tui_launch_argv(const TuiManifest *manifest, const char *php, GError **error)
{
    GPtrArray *argv = g_ptr_array_new();

    if (0 == g_strcmp0(manifest->mode, "system")) {
        if (NULL == php) {
            g_set_error(error, TUI_ERROR, 5, "no php to run");
            g_ptr_array_free(argv, TRUE);
            return NULL;
        }
        g_ptr_array_add(argv, g_strdup(php));
    } else if (0 == g_strcmp0(manifest->mode, "dev")) {
        if (NULL == manifest->command) {
            g_set_error(error, TUI_ERROR, 5, "--dev needs a command after --");
            g_ptr_array_free(argv, TRUE);
            return NULL;
        }
        g_ptr_array_add(argv, g_strdup(manifest->command));
    } else {
        if (NULL == manifest->command) {
            g_set_error(error, TUI_ERROR, 5, "launch mode %s needs a command", manifest->mode);
            g_ptr_array_free(argv, TRUE);
            return NULL;
        }
        /* command is relative to the manifest, with or without ./ */
        g_ptr_array_add(argv, g_path_is_absolute(manifest->command) ? g_strdup(manifest->command)
                                                                     : g_build_filename(manifest->dir, manifest->command, NULL));
    }
    for (guint i = 0; NULL != manifest->args[i]; i++) {
        g_ptr_array_add(argv, 0 == g_strcmp0(manifest->mode, "dev") ? g_strdup(manifest->args[i]) : resolve(manifest, manifest->args[i]));
    }
    g_ptr_array_add(argv, NULL);
    return (char **) g_ptr_array_free(argv, FALSE);
}

gboolean tui_env_allowed(const char *name, char **pass)
{
    static const char *const always[] = {"PATH", "HOME", "USER", "LANG", "TMPDIR", "TEMP", "TMP", "SystemRoot", "APPDATA", "LOCALAPPDATA", "DISPLAY", "WAYLAND_DISPLAY"};

    for (guint i = 0; i < G_N_ELEMENTS(always); i++) {
        if (0 == strcmp(name, always[i])) {
            return TRUE;
        }
    }
    if (g_str_has_prefix(name, "LC_") || g_str_has_prefix(name, "XDG_")) {
        return TRUE;
    }
    for (guint i = 0; NULL != pass && NULL != pass[i]; i++) {
        if (0 == strcmp(name, pass[i])) {
            return TRUE;
        }
    }
    return FALSE;
}

char **tui_launch_env(char **inherited, const TuiManifest *manifest, const char *address, const char *token)
{
    char **env = g_new0(char *, 1);

    for (guint i = 0; NULL != inherited && NULL != inherited[i]; i++) {
        const char *equals = strchr(inherited[i], '=');
        if (NULL == equals) {
            continue;
        }
        g_autofree char *name = g_strndup(inherited[i], (gsize) (equals - inherited[i]));
        /* Like macOS: TUI_APP_* is kept like any other variable (all of them in --dev); the address and token
           are replaced below, never a parent host's */
        if (manifest->keep_environment || tui_env_allowed(name, manifest->env_pass)) {
            env = g_environ_setenv(env, name, equals + 1, TRUE);
        }
    }
    for (guint i = 0; NULL != manifest->env_set && NULL != manifest->env_set[i]; i++) {
        const char *equals = strchr(manifest->env_set[i], '=');
        g_autofree char *name = g_strndup(manifest->env_set[i], (gsize) (equals - manifest->env_set[i]));
        env = g_environ_setenv(env, name, equals + 1, TRUE);
    }
    env = g_environ_setenv(env, "TUI_APP_ADDR", address, TRUE);
    env = g_environ_setenv(env, "TUI_APP_TOKEN", token, TRUE);
    return env;
}

char *tui_new_token(void)
{
    GString *token = g_string_sized_new(32);
    guint8 bytes[16];
    gboolean filled = FALSE;
    FILE *random = fopen("/dev/urandom", "rb");

    if (NULL != random) {
        filled = sizeof bytes == fread(bytes, 1, sizeof bytes, random);
        fclose(random);
    }
    for (guint i = 0; i < sizeof bytes; i++) {
        g_string_append_printf(token, "%02x", filled ? bytes[i] : (guint8) g_random_int_range(0, 256));
    }
    return g_string_free(token, FALSE);
}

gboolean tui_token_equal(const char *expected, const char *given)
{
    if (NULL == expected || NULL == given) {
        return FALSE;
    }
    gsize length = strlen(expected);
    gsize other = strlen(given);
    guint8 difference = length != other;
    for (gsize i = 0; i < length; i++) {
        difference |= (guint8) expected[i] ^ (guint8) (i < other ? given[i] : 0);
    }
    return 0 == difference;
}

/* ======================================================================== */
/* Close flow                                                               */
/* ======================================================================== */

TuiCloseAction tui_close_requested(TuiCloseFlow *flow, gboolean connected)
{
    if (TUI_CLOSE_DONE == flow->state) {
        return TUI_DO_CLOSE_WINDOW;
    }
    if (!connected) {
        return TUI_DO_KILL; /* nobody to ask: stop the process, its exit closes the window */
    }
    if (TUI_CLOSE_IDLE != flow->state) {
        return TUI_DO_NOTHING; /* already asked */
    }
    flow->state = TUI_CLOSE_ASKED;
    return TUI_DO_SEND_CLOSE;
}

TuiCloseAction tui_close_reply(TuiCloseFlow *flow, gboolean accept)
{
    if (TUI_CLOSE_ASKED != flow->state && TUI_CLOSE_FORCING != flow->state) {
        return TUI_DO_NOTHING;
    }
    if (!accept) {
        flow->state = TUI_CLOSE_IDLE;
        return TUI_DO_KEEP_OPEN;
    }
    flow->state = TUI_CLOSE_ACCEPTED;
    return TUI_DO_WAIT_EXIT;
}

TuiCloseAction tui_close_bye(TuiCloseFlow *flow)
{
    if (TUI_CLOSE_DONE == flow->state) {
        return TUI_DO_NOTHING;
    }
    flow->state = TUI_CLOSE_BYE;
    return TUI_DO_WAIT_EXIT;
}

TuiCloseAction tui_close_timeout(TuiCloseFlow *flow)
{
    if (TUI_CLOSE_ASKED == flow->state || TUI_CLOSE_ACCEPTED == flow->state || TUI_CLOSE_BYE == flow->state) {
        flow->state = TUI_CLOSE_FORCING;
        return TUI_DO_ASK_FORCE;
    }
    return TUI_DO_NOTHING;
}

TuiCloseAction tui_close_force_answer(TuiCloseFlow *flow, gboolean force)
{
    if (TUI_CLOSE_FORCING != flow->state) {
        return TUI_DO_NOTHING;
    }
    if (force) {
        return TUI_DO_KILL;
    }
    flow->state = TUI_CLOSE_IDLE;
    return TUI_DO_KEEP_OPEN;
}

TuiCloseAction tui_close_exited(TuiCloseFlow *flow)
{
    flow->state = TUI_CLOSE_DONE;
    return TUI_DO_CLOSE_WINDOW;
}

/* ======================================================================== */
/* Geometry and cells                                                       */
/* ======================================================================== */

void tui_grid_size(double width, double height, double cell_width, double cell_height, gint64 *columns, gint64 *rows)
{
    *columns = cell_width > 0 ? MAX(1, (gint64) floor(width / cell_width)) : 1;
    *rows = cell_height > 0 ? MAX(1, (gint64) floor(height / cell_height)) : 1;
}

void tui_rect_cells(double x, double y, double width, double height, double cell_width, double cell_height, gint64 out[4])
{
    /* A hair of tolerance: 2 cells measured as 16.000001 px stays 2. */
    const double epsilon = 1e-6;
    out[0] = (gint64) floor(y / cell_height + epsilon);
    out[1] = (gint64) floor(x / cell_width + epsilon);
    out[2] = (gint64) ceil(width / cell_width - epsilon);
    out[3] = (gint64) ceil(height / cell_height - epsilon);
}

void tui_pointer_cell(double x, double y, double cell_width, double cell_height, gint64 *row, gint64 *column)
{
    *row = (gint64) floor(y / cell_height);
    *column = (gint64) floor(x / cell_width);
}

gboolean tui_wheel_add(double *rows, double *columns, double dx, double dy, gboolean notches, double cell_width, double cell_height,
    gint64 *out_rows, gint64 *out_columns)
{
    double rows_by = notches ? dy : dy / cell_height;
    double columns_by = notches ? dx : dx / cell_width;
    /* The other way: what was kept the first way is dropped, not taken off the new way */
    if (*rows * rows_by < 0) {
        *rows = 0;
    }
    if (*columns * columns_by < 0) {
        *columns = 0;
    }
    *rows += rows_by;
    *columns += columns_by;
    *out_rows = (gint64) trunc(*rows);
    *out_columns = (gint64) trunc(*columns);
    *rows -= (double) *out_rows;
    *columns -= (double) *out_columns;
    return 0 != *out_rows || 0 != *out_columns;
}

char *tui_geometry_to_json(gint64 width, gint64 height, gboolean maximized)
{
    return g_strdup_printf("{\"width\":%" G_GINT64_FORMAT ",\"height\":%" G_GINT64_FORMAT ",\"maximized\":%s}\n", width, height,
        maximized ? "true" : "false");
}

gboolean tui_geometry_parse(const char *json, gint64 *width, gint64 *height, gboolean *maximized)
{
    g_autoptr(JsonParser) parser = json_parser_new();
    if (NULL == json || !json_parser_load_from_data(parser, json, -1, NULL)) {
        return FALSE;
    }
    JsonNode *root = json_parser_get_root(parser);
    JsonObject *object = JSON_NODE_OBJECT == json_node_get_node_type(root) ? json_node_get_object(root) : NULL;
    gint64 w = 0;
    gint64 h = 0;
    if (!member_int(object, "width", &w) || !member_int(object, "height", &h) || w < 1 || h < 1) {
        return FALSE;
    }
    *width = w;
    *height = h;
    *maximized = FALSE;
    member_bool(object, "maximized", maximized);
    return TRUE;
}

/* ======================================================================== */
/* Styling                                                                  */
/* ======================================================================== */

gboolean tui_color_parse(const char *color, double rgb[3])
{
    if (NULL == color || 7 != strlen(color) || '#' != color[0]) {
        return FALSE;
    }
    for (guint i = 0; i < 3; i++) {
        int high = g_ascii_xdigit_value(color[1 + 2 * i]);
        int low = g_ascii_xdigit_value(color[2 + 2 * i]);
        if (high < 0 || low < 0) {
            return FALSE;
        }
        rgb[i] = (high * 16 + low) / 255.0;
    }
    return TRUE;
}

static gboolean valid_color(const char *color)
{
    double rgb[3];
    return tui_color_parse(color, rgb);
}

char *tui_runs_markup(JsonArray *runs, const char *fg, const char *bg)
{
    GString *markup = g_string_new(NULL);

    for (guint i = 0; NULL != runs && i < json_array_get_length(runs); i++) {
        JsonNode *node = json_array_get_element(runs, i);
        JsonObject *run = JSON_NODE_OBJECT == json_node_get_node_type(node) ? json_node_get_object(node) : NULL;
        const char *text = member_string(run, "t");
        if (NULL == text) {
            continue;
        }
        const char *run_fg = valid_color(member_string(run, "fg")) ? member_string(run, "fg") : NULL;
        const char *run_bg = valid_color(member_string(run, "bg")) ? member_string(run, "bg") : NULL;
        if (tui_prop_bool(run, "r", FALSE)) {
            const char *swap = run_fg;
            run_fg = NULL != run_bg ? run_bg : bg;
            run_bg = NULL != swap ? swap : fg;
        }
        GString *attributes = g_string_new(NULL);
        if (NULL != run_fg) {
            g_string_append_printf(attributes, " foreground=\"%s\"", run_fg);
        }
        if (NULL != run_bg) {
            g_string_append_printf(attributes, " background=\"%s\"", run_bg);
        }
        if (tui_prop_bool(run, "b", FALSE)) {
            g_string_append(attributes, " weight=\"bold\"");
        }
        if (tui_prop_bool(run, "d", FALSE)) {
            g_string_append(attributes, " alpha=\"60%\"");
        }
        if (tui_prop_bool(run, "i", FALSE)) {
            g_string_append(attributes, " style=\"italic\"");
        }
        if (tui_prop_bool(run, "u", FALSE)) {
            g_string_append(attributes, " underline=\"single\"");
        }
        if (tui_prop_bool(run, "s", FALSE)) {
            g_string_append(attributes, " strikethrough=\"true\"");
        }
        g_autofree char *escaped = g_markup_escape_text(text, -1);
        if (attributes->len > 0) {
            g_string_append_printf(markup, "<span%s>%s</span>", attributes->str, escaped);
        } else {
            g_string_append(markup, escaped);
        }
        g_string_free(attributes, TRUE);
    }
    return g_string_free(markup, FALSE);
}

char *tui_style_css(gint64 id, JsonObject *style)
{
    GString *rules = g_string_new(NULL);

    if (NULL == style) {
        return g_string_free(rules, FALSE);
    }
    const char *fg = member_string(style, "fg");
    const char *bg = member_string(style, "bg");
    if (valid_color(fg)) {
        g_string_append_printf(rules, " color: %s;", fg);
    }
    if (valid_color(bg)) {
        g_string_append_printf(rules, " background-color: %s;", bg);
    }
    if (tui_prop_bool(style, "bold", FALSE)) {
        g_string_append(rules, " font-weight: bold;");
    }
    if (tui_prop_bool(style, "dim", FALSE)) {
        g_string_append(rules, " opacity: 0.6;");
    }
    if (tui_prop_bool(style, "italic", FALSE)) {
        g_string_append(rules, " font-style: italic;");
    }
    if (tui_prop_bool(style, "underline", FALSE) || tui_prop_bool(style, "strike", FALSE)) {
        g_string_append_printf(rules, " text-decoration-line:%s%s;", tui_prop_bool(style, "underline", FALSE) ? " underline" : "",
            tui_prop_bool(style, "strike", FALSE) ? " line-through" : "");
    }
    if (0 == rules->len) {
        return g_string_free(rules, FALSE);
    }
    char *css = g_strdup_printf(".tui-n%" G_GINT64_FORMAT " {%s }\n", id, rules->str);
    g_string_free(rules, TRUE);
    return css;
}

/* ======================================================================== */
/* Packaging                                                                */
/* ======================================================================== */

/* A desktop entry string value: \s, \n, \t, \r and \\ escaped. */
static void append_desktop_value(GString *entry, const char *value)
{
    for (const char *c = value; '\0' != *c; c++) {
        switch (*c) {
        case '\n':
            g_string_append(entry, "\\n");
            break;
        case '\t':
            g_string_append(entry, "\\t");
            break;
        case '\r':
            g_string_append(entry, "\\r");
            break;
        case '\\':
            g_string_append(entry, "\\\\");
            break;
        default:
            g_string_append_c(entry, *c);
        }
    }
}

static void append_list_value(GString *entry, const char *value)
{
    for (const char *c = value; '\0' != *c; c++) {
        if (';' == *c) {
            g_string_append(entry, "\\;");
        } else if ('\n' == *c || '\r' == *c) {
            g_string_append_c(entry, ' ');
        } else {
            g_string_append_c(entry, *c);
        }
    }
    g_string_append_c(entry, ';');
}

char *tui_desktop_entry(const TuiManifest *manifest, const char *exec)
{
    GString *entry = g_string_new("[Desktop Entry]\nType=Application\nVersion=1.5\nName=");
    gboolean any_mime = FALSE;

    append_desktop_value(entry, manifest->name);
    g_string_append_c(entry, '\n');
    if (NULL != manifest->description && '\0' != *manifest->description) {
        g_string_append(entry, "Comment=");
        append_desktop_value(entry, manifest->description);
        g_string_append_c(entry, '\n');
    }
    /* Exec takes a quoted program when it holds anything outside [A-Za-z0-9._/-]. */
    gboolean plain = TRUE;
    for (const char *c = exec; '\0' != *c; c++) {
        plain = plain && (g_ascii_isalnum(*c) || NULL != strchr("._/-", *c));
    }
    g_string_append(entry, "Exec=");
    if (plain) {
        g_string_append(entry, exec);
    } else {
        g_string_append_c(entry, '"');
        for (const char *c = exec; '\0' != *c; c++) {
            if (NULL != strchr("\"`$\\", *c)) {
                g_string_append(entry, "\\\\");
            }
            g_string_append_c(entry, '%' == *c ? '%' : *c);
            if ('%' == *c) {
                g_string_append_c(entry, '%');
            }
        }
        g_string_append_c(entry, '"');
    }
    /* -- first: a file or URL that looks like an option stays a file. */
    g_string_append(entry, " -- %U\nIcon=");
    append_desktop_value(entry, manifest->id);
    g_string_append(entry, "\nTerminal=false\nStartupNotify=true\nStartupWMClass=");
    append_desktop_value(entry, manifest->id);
    g_string_append(entry, "\nCategories=");
    if (NULL != manifest->categories && NULL != manifest->categories[0]) {
        for (guint i = 0; NULL != manifest->categories[i]; i++) {
            append_list_value(entry, manifest->categories[i]);
        }
    } else {
        g_string_append(entry, "Utility;");
    }
    g_string_append_c(entry, '\n');

    GString *mime = g_string_new(NULL);
    for (guint i = 0; i < manifest->file_associations->len; i++) {
        TuiFileAssociation *association = manifest->file_associations->pdata[i];
        if (NULL != association->mime_type) {
            append_list_value(mime, association->mime_type);
            any_mime = TRUE;
        }
    }
    for (guint i = 0; NULL != manifest->url_schemes && NULL != manifest->url_schemes[i]; i++) {
        g_autofree char *handler = g_strdup_printf("x-scheme-handler/%s", manifest->url_schemes[i]);
        append_list_value(mime, handler);
        any_mime = TRUE;
    }
    if (any_mime) {
        g_string_append_printf(entry, "MimeType=%s\n", mime->str);
    }
    g_string_free(mime, TRUE);
    if (manifest->single_instance) {
        g_string_append(entry, "SingleMainWindow=true\n");
    }
    return g_string_free(entry, FALSE);
}

const char *tui_desktop_categories(const char *category)
{
    static const struct {
        const char *category;
        const char *desktop;
    } map[] = {
        {"business", "Office;"}, {"developer-tools", "Development;"}, {"education", "Education;"}, {"entertainment", "Game;Amusement;"},
        {"finance", "Office;Finance;"}, {"games", "Game;"}, {"graphics", "Graphics;"}, {"lifestyle", "Utility;"}, {"music", "AudioVideo;Audio;Music;"},
        {"news", "Network;News;"}, {"productivity", "Office;"}, {"social", "Network;Chat;"}, {"utilities", "Utility;"}, {"video", "AudioVideo;Video;"},
    };
    for (guint i = 0; NULL != category && i < G_N_ELEMENTS(map); i++) {
        if (0 == strcmp(category, map[i].category)) {
            return map[i].desktop;
        }
    }
    return "Utility;";
}

char *tui_mime_xml(const TuiManifest *manifest)
{
    GString *xml = g_string_new("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                                "<mime-info xmlns=\"http://www.freedesktop.org/standards/shared-mime-info\">\n");
    gboolean any = FALSE;

    for (guint i = 0; i < manifest->file_associations->len; i++) {
        TuiFileAssociation *association = manifest->file_associations->pdata[i];
        if (!association->generated) {
            continue; /* a type the system already knows */
        }
        g_autofree char *type = g_markup_escape_text(association->mime_type, -1);
        g_autofree char *glob = g_markup_escape_text(association->extension, -1);
        g_string_append_printf(xml, "  <mime-type type=\"%s\">\n", type);
        if (NULL != association->name) {
            g_autofree char *comment = g_markup_escape_text(association->name, -1);
            g_string_append_printf(xml, "    <comment>%s</comment>\n", comment);
        }
        g_string_append_printf(xml, "    <glob pattern=\"*.%s\"/>\n", glob);
        g_string_append(xml, "  </mime-type>\n");
        any = TRUE;
    }
    g_string_append(xml, "</mime-info>\n");
    if (!any) {
        g_string_free(xml, TRUE);
        return NULL;
    }
    return g_string_free(xml, FALSE);
}
