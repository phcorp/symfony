/* What the Linux host decides without a window: GLib and json-glib only, and
 * every function here is unit tested against contract/fixtures. */
#pragma once

#include <glib.h>
#include <json-glib/json-glib.h>

#define TUI_PROTOCOL 1
#define TUI_MAX_FRAME_BYTES (16u * 1024u * 1024u)

/* ---- Frame codec: a u32 big-endian length, then that many bytes of UTF-8 JSON. ---- */

GBytes *tui_frame_encode(const char *json, gsize length);

typedef struct TuiDecoder TuiDecoder;

TuiDecoder *tui_decoder_new(void);
void tui_decoder_free(TuiDecoder *decoder);
/* Appends every complete frame's JSON (a NUL-terminated string, g_free) to
 * `frames`. FALSE when a frame announces more than TUI_MAX_FRAME_BYTES. */
gboolean tui_decoder_feed(TuiDecoder *decoder, const guint8 *bytes, gsize length, GPtrArray *frames, GError **error);

/* ---- Messages ---- */

typedef enum {
    TUI_MSG_UNKNOWN,
    TUI_MSG_AUTH,
    TUI_MSG_HELLO,
    TUI_MSG_PATCH,
    TUI_MSG_FOCUS,
    TUI_MSG_WINDOW,
    TUI_MSG_NOTIFY,
    TUI_MSG_BEEP,
    TUI_MSG_MENU,
    TUI_MSG_CLOSE_REPLY,
    TUI_MSG_BYE,
    TUI_MSG_RESIZE,
    TUI_MSG_EVENT,
    TUI_MSG_KEY,
    TUI_MSG_PASTE,
    TUI_MSG_RECTS,
    TUI_MSG_MENU_SELECT,
    TUI_MSG_APPEARANCE,
    TUI_MSG_OPEN,
    TUI_MSG_CLOSE,
    TUI_MSG_POINTER,
    TUI_MSG_CURSOR,
    TUI_MSG_CONTEXTMENU,
    TUI_MSG_CONTEXTMENU_SELECT,
    TUI_MSG_CLIPBOARD,
} TuiMessageType;

typedef enum { TUI_OP_CREATE, TUI_OP_UPDATE, TUI_OP_MOVE, TUI_OP_REMOVE } TuiOpKind;

typedef struct {
    TuiOpKind op;
    gint64 id;
    char *kind;       /* create */
    gint64 parent;    /* create, move; 0 is null (the root) */
    gint64 index;     /* create, move */
    JsonObject *props; /* create, update */
} TuiOp;

typedef struct {
    char *id;
    char *label;
    gboolean has_shortcut;
    char *shortcut; /* may be NULL with has_shortcut: null */
    gboolean has_separator, separator;
    gboolean has_enabled, enabled;
    gboolean has_checked, checked_null, checked;
    char *role; /* contextmenu: "paste", or NULL */
} TuiMenuItem;

typedef struct {
    char *title;
    GPtrArray *items; /* TuiMenuItem */
} TuiMenu;

typedef struct {
    gint64 id;
    gint64 rect[4];
} TuiRect;

typedef struct {
    TuiMessageType type;
    char *type_name;     /* as received, for unknown types */
    /* auth, hello */
    char *token;
    gint64 protocol;
    char *os;
    gint64 columns, rows;
    double cell_width, cell_height;
    gboolean has_scale;
    double scale;
    char *appearance;    /* hello, appearance (value) */
    gboolean has_capabilities;
    char **capabilities;
    /* patch */
    GPtrArray *ops;      /* TuiOp */
    /* focus, event */
    gint64 node;         /* 0 is null */
    /* window */
    gboolean has_title, title_null; /* window: title null gives back the title of the manifest */
    char *title;         /* window, notify */
    gboolean has_progress, progress_null;
    char *progress_state;
    gboolean has_progress_value, progress_value_null;
    double progress_value;
    /* notify */
    gboolean has_body;
    char *body;
    /* menu */
    GPtrArray *menus;    /* TuiMenu */
    /* close-reply */
    gboolean accept;
    /* bye */
    gboolean has_status;
    gint64 status;
    /* event */
    char *name;
    JsonNode *value;     /* NULL when absent */
    /* key, paste */
    char *key;
    gboolean has_text;
    char *text;          /* key: NULL with has_text is null; paste, clipboard: the text */
    /* rects */
    GArray *rects;       /* TuiRect */
    /* menu-select, contextmenu-select */
    char *id;
    /* contextmenu (node: the canvas; row and column: the cell) */
    GPtrArray *items;      /* TuiMenuItem: a separator, or an id and a label */
    /* open */
    char **paths;
    /* pointer (node: the canvas) */
    char *action;          /* press, release, move, leave, wheel */
    gint64 row, column;    /* the cell under the pointer */
    char *button;          /* left, middle, right; NULL is null */
    char **modifiers;      /* ctrl, shift, alt, super */
    gboolean has_scroll;   /* rows and columns, with wheel only */
    gint64 scroll_rows, scroll_columns;
    /* cursor (node: the canvas): a CSS cursor keyword of the contract, "default" for one it does not list */
    char *shape;
} TuiMessage;

/* TRUE for a CSS cursor keyword the contract lists ($defs.cursor) */
gboolean tui_cursor_shape_known(const char *shape);

TuiMessage *tui_message_new(TuiMessageType type);
void tui_message_free(TuiMessage *message);
G_DEFINE_AUTOPTR_CLEANUP_FUNC(TuiMessage, tui_message_free)

/* NULL with error when the JSON is not an object with a string type, or a
 * known type misses a required member. Unknown types parse as TUI_MSG_UNKNOWN. */
TuiMessage *tui_message_parse(const char *json, gssize length, GError **error);
TuiMessage *tui_message_from_node(JsonNode *node, GError **error);
JsonNode *tui_message_to_node(const TuiMessage *message);
char *tui_message_to_json(const TuiMessage *message);
GBytes *tui_message_to_frame(const TuiMessage *message);

/* Structural JSON equality; numbers compare by value (17 equals 17.0). */
gboolean tui_json_equal(JsonNode *a, JsonNode *b);

/* ---- Node tree ---- */

typedef struct TuiNode TuiNode;
struct TuiNode {
    gint64 id;
    char *kind;
    JsonObject *props;
    TuiNode *parent;
    GPtrArray *children; /* TuiNode, not owned */
};

typedef struct {
    GHashTable *nodes; /* gint64 id -> TuiNode, owned */
    TuiNode *root;
} TuiTree;

/* What a patch changed, for the reconciler. Ids are gint64. */
typedef struct {
    GArray *created;
    GArray *updated;  /* nodes whose props changed, not counting created ones */
    GArray *removed;  /* every removed node, descendants included */
    GArray *relinked; /* parents whose list of children changed */
} TuiChanges;

TuiTree *tui_tree_new(void);
void tui_tree_free(TuiTree *tree);
TuiNode *tui_tree_get(TuiTree *tree, gint64 id);
void tui_changes_init(TuiChanges *changes);
void tui_changes_clear(TuiChanges *changes);
/* Applies the ops in order. Ops on unknown nodes are skipped and reported in
 * `error` (the rest still apply); returns FALSE then. */
gboolean tui_tree_apply(TuiTree *tree, const TuiMessage *patch, TuiChanges *changes, GError **error);
/* The prop a user event changes on a control of a kind, as the application takes it: it does not send it back
 * (TuiApp's HostEvent::acknowledge), so an update of the node's other props must not put the old one back.
 * change and submit: the value of a textfield, password, textarea or number; change: the value of a slider;
 * toggle: checked (checkbox), expanded (disclosure); select: selected (popup, listbox, list), checked (multiple
 * list, an array); tab: active. NULL for any other event. */
const char *tui_acknowledged_prop(const char *kind, const char *event, JsonNode *value);
/* Sets that prop of the node to the value (a copy); TRUE when it changed. */
gboolean tui_tree_acknowledge(TuiTree *tree, gint64 id, const char *event, JsonNode *value);
/* {id, kind, props (keys sorted, null props dropped), children}, or null without a root. */
JsonNode *tui_tree_to_node(TuiTree *tree);

/* Props helpers: defaults when absent or null. */
const char *tui_prop_string(JsonObject *props, const char *name, const char *fallback);
gint64 tui_prop_int(JsonObject *props, const char *name, gint64 fallback);
double tui_prop_double(JsonObject *props, const char *name, double fallback);
gboolean tui_prop_bool(JsonObject *props, const char *name, gboolean fallback);
gboolean tui_prop_is_null(JsonObject *props, const char *name);
JsonArray *tui_prop_array(JsonObject *props, const char *name);
JsonObject *tui_prop_object(JsonObject *props, const char *name);

/* ---- maxLength ---- */

/* The maxLength of a textfield or textarea in characters (code points, like PHP's mb_strlen), or -1
 * for none: absent, null or negative. */
gint64 tui_max_length(JsonObject *props);
/* How many bytes of `text` (`len` bytes, -1: up to its NUL) an edit may insert under `limit`
 * (-1: no limit): all of it, else its first characters up to the limit. The value had `length`
 * characters, of which the edit replaces `removed`; a value already past the limit may be edited as
 * long as it gets no longer, as the application allows. */
gsize tui_max_length_keep(glong length, glong removed, const char *text, gssize len, gint64 limit);

/* ---- Keys ---- */

enum { TUI_MOD_CTRL = 1, TUI_MOD_SHIFT = 2, TUI_MOD_ALT = 4, TUI_MOD_SUPER = 8 };

/* The Tui name of a named key's GDK keyval (enter, page_up, f5...), else NULL. */
const char *tui_keyval_name(guint keyval);
gboolean tui_keyval_is_modifier(guint keyval);
/* The Tui key id for a key press, with `unicode` what gdk_keyval_to_unicode
 * gives. NULL for a lone modifier or a key without a name. `text` gets the
 * typed text when no ctrl, alt or super is held, else NULL. */
char *tui_key_id(guint keyval, gunichar unicode, guint mods, char **text);
/* A Tui key id as a GTK accelerator ("ctrl+s" -> "<Control>s"), or NULL. */
char *tui_key_accel(const char *key_id);
/* What an input method commits, as typed keys: one per character (a base
 * character with the marks, joiners, variation selectors and modifiers that
 * go with it), the key its lowercase with shift+ for an uppercase letter,
 * space for a space, and the text the character. Control characters are
 * dropped. A flat array: key, text, key, text..., NULL-terminated. */
char **tui_committed_keys(const char *text);

/* ---- Manifest and launch ---- */

typedef struct {
    char *extension;   /* without the dot */
    char *name;
    char *mime_type;   /* application/x-<id>-<extension> when the manifest has null */
    gboolean generated; /* the mime type is ours: package.sh installs shared-mime-info XML for it */
    char *role;        /* editor, viewer */
} TuiFileAssociation;

typedef struct {
    char *dir; /* the directory holding the manifest */
    char *name, *id, *version, *description, *publisher, *copyright, *homepage;
    gboolean single_instance;
    char *title;
    gint64 width, height, min_width, min_height;
    gboolean resizable, remember_geometry;
    char *theme; /* system, light, dark */
    char *font_family; /* NULL: the platform's monospace */
    double font_size;
    gboolean menu_native, menu_about, menu_quit;
    char *mode; /* embedded, custom, system, elephc, dev */
    char *command;
    char **args;
    char *php_path, *php_min_version;
    char **env_pass;
    char **env_set; /* KEY=VALUE */
    char *log_file, *log_level;
    /* app.category as freedesktop Categories; app.fileAssociations; app.urlSchemes.
     * The icon is a build input (package.sh --icon), not a manifest field. */
    char **categories;
    GPtrArray *file_associations; /* TuiFileAssociation */
    char **url_schemes;
    gboolean keep_environment; /* --dev: the developer's whole environment */
} TuiManifest;

TuiManifest *tui_manifest_parse(const char *json, gssize length, const char *dir, GError **error);
TuiManifest *tui_manifest_load(const char *path, GError **error);
/* For --dev: no manifest, run `command` as given. */
TuiManifest *tui_manifest_dev(char **command);
void tui_manifest_free(TuiManifest *manifest);
G_DEFINE_AUTOPTR_CLEANUP_FUNC(TuiManifest, tui_manifest_free)

/* -1, 0 or 1, comparing dotted numeric versions ("8.2.10" > "8.2.9"). */
int tui_version_compare(const char *a, const char *b);
/* `php` is the php found for system mode, ignored otherwise. */
char **tui_launch_argv(const TuiManifest *manifest, const char *php, GError **error);
gboolean tui_env_allowed(const char *name, char **pass);
char **tui_launch_env(char **inherited, const TuiManifest *manifest, const char *address, const char *token);

char *tui_new_token(void);
gboolean tui_token_equal(const char *expected, const char *given);

/* ---- Close flow ---- */

typedef enum {
    TUI_CLOSE_IDLE,
    TUI_CLOSE_ASKED,    /* close sent, waiting 4 s for close-reply */
    TUI_CLOSE_ACCEPTED, /* accepted, waiting for bye and exit */
    TUI_CLOSE_BYE,      /* bye received, waiting 15 s for exit */
    TUI_CLOSE_FORCING,  /* the force-quit question is shown */
    TUI_CLOSE_DONE,
} TuiCloseState;

typedef enum {
    TUI_DO_NOTHING,
    TUI_DO_SEND_CLOSE,   /* send close, start the 4 s reply timer */
    TUI_DO_KEEP_OPEN,    /* stop the timers, keep the window */
    TUI_DO_WAIT_EXIT,    /* (re)start the 15 s exit timer */
    TUI_DO_ASK_FORCE,    /* stop the timers, ask "<name> is not responding. Force quit?" */
    TUI_DO_KILL,         /* force quit; the exit then closes the window */
    TUI_DO_CLOSE_WINDOW, /* the process is gone: close */
} TuiCloseAction;

typedef struct {
    TuiCloseState state;
} TuiCloseFlow;

TuiCloseAction tui_close_requested(TuiCloseFlow *flow, gboolean connected);
TuiCloseAction tui_close_reply(TuiCloseFlow *flow, gboolean accept);
TuiCloseAction tui_close_bye(TuiCloseFlow *flow);
TuiCloseAction tui_close_timeout(TuiCloseFlow *flow);
TuiCloseAction tui_close_force_answer(TuiCloseFlow *flow, gboolean force);
TuiCloseAction tui_close_exited(TuiCloseFlow *flow);

/* ---- Geometry and cells ---- */

void tui_grid_size(double width, double height, double cell_width, double cell_height, gint64 *columns, gint64 *rows);
void tui_rect_cells(double x, double y, double width, double height, double cell_width, double cell_height, gint64 out[4]);
/* The cell a point of a canvas falls in, from its top-left corner, rounded down (also past its edges) */
void tui_pointer_cell(double x, double y, double cell_width, double cell_height, gint64 *row, gint64 *column);
/* Adds a wheel delta (positive down and right) to what is kept (*rows, *columns): notches, a row or a column each,
 * else pixels, per cell. TRUE with the whole cells to send in *out_rows, *out_columns, the rest kept; what was kept
 * one way is dropped when the wheel turns the other way. */
gboolean tui_wheel_add(double *rows, double *columns, double dx, double dy, gboolean notches, double cell_width, double cell_height,
    gint64 *out_rows, gint64 *out_columns);
char *tui_geometry_to_json(gint64 width, gint64 height, gboolean maximized);
gboolean tui_geometry_parse(const char *json, gint64 *width, gint64 *height, gboolean *maximized);

/* ---- Styling ---- */

/* Pango markup for label runs; `fg` and `bg` are the colours reverse swaps in when a run has none. */
char *tui_runs_markup(JsonArray *runs, const char *fg, const char *bg);
/* CSS for a node's style prop, scoped to `.tui-n<id>`; "" when it has none. */
char *tui_style_css(gint64 id, JsonObject *style);
gboolean tui_color_parse(const char *color, double rgb[3]);

/* ---- Packaging ---- */

char *tui_desktop_entry(const TuiManifest *manifest, const char *exec);
/* freedesktop Categories for a contract category ("developer-tools" -> "Development;"). */
const char *tui_desktop_categories(const char *category);
/* shared-mime-info XML for the mime types the manifest left null, or NULL when there are none. */
char *tui_mime_xml(const TuiManifest *manifest);
