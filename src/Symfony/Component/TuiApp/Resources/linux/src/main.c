/* The TuiApp host for Linux: one GTK 4 window per process, drawing the node
 * tree of the application it starts as native widgets. See HOSTS.md. */
#include "core.h"
#include "stack_layout.h"

#include <errno.h>
#include <glib/gstdio.h>
#include <gtk/gtk.h>
#ifdef GDK_WINDOWING_X11
#include <gdk/x11/gdkx.h>
#endif
#include <math.h>
#include <signal.h>
#include <stdio.h>
#include <string.h>

#define ACCEPT_SECONDS 10
#define REPLY_SECONDS 4
#define EXIT_SECONDS 15
#define RESIZE_MS 50
#define SELF_TEST_SECONDS 20
#define FEATURE_TEST_SECONDS 60
/* The whole canvas test, a backstop: each step waits 5 s at most; built with AddressSanitizer on a loaded machine (stress-ng --cpu 60) it takes over 60 s */
#define CANVAS_TEST_SECONDS 180
#define LABEL_WAIT_US (5 * G_USEC_PER_SEC)
#define STDERR_LINES 20

typedef struct View View;

/* What a peer connection is reading: a candidate until it authenticates. */
typedef struct {
    GSocketConnection *connection;
    TuiDecoder *decoder;
    GCancellable *cancellable;
    gboolean authed;
} Peer;

typedef void (*FeatureStep)(void);

typedef struct {
    GtkApplication *app;
    TuiManifest *manifest;
    char *manifest_path;
    gboolean unique;
    gboolean self_test;
    gboolean feature_test;
    gboolean canvas_test;
    GPtrArray *pending_paths;

    GtkWindow *window;
    GtkWidget *menubar;
    GtkWidget *holder; /* the content area */
    gboolean allow_close;
    gboolean started;

    /* Connection */
    GSocketService *service;
    char *token;
    char *address;
    Peer *peer; /* the authenticated one */
    GQueue *out;
    gboolean writing;
    guint accept_timer;

    /* Child */
    GSubprocess *child;
    gboolean exited;
    int exit_status;
    gboolean exited_normally;
    GQueue *stderr_tail;
    FILE *log;

    /* Tree */
    TuiTree *tree;
    GHashTable *views; /* gint64 id -> View */
    int suppress;      /* >0 while the host itself changes controls, or moves the focus */
    int setting;       /* >0 while the host sets the text of a text field, a password or a text area */
    GtkCssProvider *node_css;

    /* Cells */
    PangoFontDescription *font;
    double cell_width, cell_height;
    int content_width, content_height;
    gint64 sent_columns, sent_rows;
    guint resize_timer;
    gboolean resize_pending;
    gboolean rects_pending;
    gboolean patch_drawn;
    char *last_rects;

    /* Menus */
    GSimpleActionGroup *menu_actions;
    GPtrArray *menu_ids; /* index -> item id */
    GPtrArray *accel_actions;
    GHashTable *shortcuts; /* Tui key ids bound to menu items */
    TuiMessage *last_menu;
    GtkWidget *context_menu; /* the contextmenu shown, a GtkPopoverMenu parented to its canvas, or NULL */
    guint context_menus_shown; /* how many were shown, for the canvas test (a popover's address may be reused) */
    GPtrArray *context_menus_closed; /* closed, still on their canvas until an idle lets go of them (a ref each) */

    /* Appearance */
    gboolean dark;
    GDBusProxy *portal;
    gboolean portal_scheme; /* the portal says light or dark: it decides */
    gboolean user_prefers_dark; /* gtk-application-prefer-dark-theme before the host sets it */

    /* Close */
    TuiCloseFlow flow;
    guint close_timer;
    gboolean bye_seen;

    /* Self-test */
    int test_stage;
    gint64 test_deadline;
    guint test_timer;

    /* Feature test: what the application sent, and what came of it */
    const char *ft_stage;
    gint64 ft_begin;
    gboolean ft_done;
    int ft_notifies;
    int ft_beeps;
    gboolean ft_progress_emitted;
    double ft_progress;
    char *ft_progress_state;
    char *ft_last_open;
    char *ft_pid;
    int ft_alerts;
    int ft_signal;
    char *ft_wait_for;
    gint64 ft_wait_until;
    FeatureStep ft_then;
    char *ft_theme;

    /* Canvas test */
    const char *ct_stage;
    gint64 ct_begin;
    gboolean ct_done;
    gboolean ct_passed;
    GString *ct_focus; /* the nodes that took the focus, in order */
    int ct_keys;       /* the key messages sent */
    GString *ct_events; /* the events sent, "<node> <name> <value as JSON>; " (no value for the password field 36) */

    int status; /* the process exit status */
} Host;

static Host H;

struct View {
    gint64 id;
    char *kind;
    GtkWidget *outer;   /* what sits in the parent's container */
    GtkWidget *control; /* the native control */
    GtkWidget *content; /* where children go, if any */
    GtkWidget *part[3]; /* kind-specific parts */
    View *holder;       /* the parent view; NULL in the content area */
    GtkWidget *placer;  /* over a canvas: the box, cut to clip, that places the control */
    GtkIMContext *im;   /* a canvas's input method */
    char *preedit;      /* what the input method is composing in a canvas */
    gboolean im_resetting; /* resetting it after a commit: what it commits again is dropped, see canvas_commit() */
    /* A canvas's mouse (pointer): where it is, the cell last sent, the button held, the wheel not yet sent */
    gboolean has_pointer, has_pointer_cell;
    double pointer_x, pointer_y;
    double drag_x, drag_y; /* where the button held was pressed */
    gboolean leave_held;   /* the pointer left the drawing while a button was held: leave goes after the release */
    gint64 pointer_row, pointer_column;
    const char *pointer_button;
    double wheel_rows, wheel_columns;
    gboolean keep_text;    /* a number field: its text is not a number, which it keeps as typed (number_output) */
    gboolean edited;       /* an edit not the host's own while it changes controls: sent all the same */
    double sent;           /* a slider: the value last sent */
    char *cache;
    guint pulse;
    gint64 shown_class;
};

static void send_message(TuiMessage *message);
static void close_action(TuiCloseAction action);
static void self_test_fail(const char *format, ...) G_GNUC_PRINTF(1, 2);
static void self_test_patch_drawn(void);
static void ft_fail(const char *format, ...) G_GNUC_PRINTF(1, 2);
static void ft_patch_drawn(void);
static void ft_force_quit_offered(void);
static void ft_process_exited(void);
static void ft_window_closed(void);
static void ct_fail(const char *format, ...) G_GNUC_PRINTF(1, 2);
static void ct_patch_drawn(void);
static void apply_props(View *view);

/* ======================================================================== */
/* Logging                                                                  */
/* ======================================================================== */

static void log_line(const char *stream, const char *line)
{
    if (NULL != H.log) {
        g_autoptr(GDateTime) now = g_date_time_new_now_local();
        g_autofree char *stamp = g_date_time_format(now, "%F %T");
        fprintf(H.log, "%s [%s] %s\n", stamp, stream, line);
        fflush(H.log);
    } else {
        /* The platform log: the journal when started from the desktop, else the terminal. */
        fprintf(stderr, "%s: %s\n", stream, line);
    }
}

/* ======================================================================== */
/* Sending                                                                  */
/* ======================================================================== */

static void pump(void);

/* G_MESSAGES_DEBUG names this domain (tui-app, or all): the debug lines are written */
static gboolean debug_enabled(void)
{
    return !g_log_writer_default_would_drop(G_LOG_LEVEL_DEBUG, G_LOG_DOMAIN);
}

static void written(GObject *source, GAsyncResult *result, gpointer data)
{
    g_autoptr(GError) error = NULL;
    (void) data;
    g_output_stream_write_all_finish(G_OUTPUT_STREAM(source), result, NULL, &error);
    g_bytes_unref(g_queue_pop_head(H.out));
    H.writing = FALSE;
    if (NULL != error) {
        if (!g_error_matches(error, G_IO_ERROR, G_IO_ERROR_CANCELLED)) {
            log_line("host", error->message);
        }
        while (!g_queue_is_empty(H.out)) {
            g_bytes_unref(g_queue_pop_head(H.out));
        }
        return;
    }
    pump();
}

static void pump(void)
{
    if (H.writing || g_queue_is_empty(H.out) || NULL == H.peer) {
        return;
    }
    GBytes *bytes = g_queue_peek_head(H.out);
    gsize size;
    const void *data = g_bytes_get_data(bytes, &size);
    H.writing = TRUE;
    g_output_stream_write_all_async(g_io_stream_get_output_stream(G_IO_STREAM(H.peer->connection)), data, size, G_PRIORITY_DEFAULT, NULL,
        written, NULL);
}

static void send_message(TuiMessage *message)
{
    if (NULL == H.peer) {
        tui_message_free(message);
        return;
    }
    if (TUI_MSG_KEY == message->type) {
        H.ct_keys++;
    }
    if (NULL != H.ct_events && TUI_MSG_CONTEXTMENU_SELECT == message->type) {
        g_string_append_printf(H.ct_events, "contextmenu-select %s; ", message->id);
    } else if (NULL != H.ct_events && TUI_MSG_PASTE == message->type) {
        g_autoptr(JsonNode) text = json_node_init_string(json_node_alloc(), message->text);
        g_autofree char *json = json_to_string(text, FALSE);
        g_string_append_printf(H.ct_events, "paste %s; ", json);
    }
    /* G_MESSAGES_DEBUG=tui-app (linux/contextmenu.sh): what a script driving a real application waits for, never the
     * text of a key or an event (a password) */
    if (TUI_MSG_POINTER == message->type) {
        g_debug("sent pointer %s %" G_GINT64_FORMAT " %" G_GINT64_FORMAT, message->action, message->row, message->column);
    } else if (TUI_MSG_CONTEXTMENU_SELECT == message->type) {
        g_debug("sent contextmenu-select %s", message->id);
    } else if (TUI_MSG_PASTE == message->type) {
        g_debug("sent paste (%ld characters)", g_utf8_strlen(message->text, -1));
    } else if (TUI_MSG_HELLO == message->type) {
        g_debug("sent hello, cell %gx%g", message->cell_width, message->cell_height);
    }
    g_queue_push_tail(H.out, tui_message_to_frame(message));
    tui_message_free(message);
    pump();
}

static void send_event_now(gint64 node, const char *name, JsonNode *value)
{
    /* The control shows it, and the application takes it: an update of its other props keeps it */
    tui_tree_acknowledge(H.tree, node, name, value);
    if (NULL != H.ct_events) {
        g_autofree char *json = NULL != value && 36 != node ? json_to_string(value, FALSE) : NULL;
        g_string_append_printf(H.ct_events, "%" G_GINT64_FORMAT " %s%s%s; ", node, name, NULL != json ? " " : "", NULL != json ? json : "");
    }
    TuiMessage *message = tui_message_new(TUI_MSG_EVENT);
    message->node = node;
    message->name = g_strdup(name);
    message->value = value;
    send_message(message);
}

static void send_event(gint64 node, const char *name, JsonNode *value)
{
    if (H.suppress > 0) {
        if (NULL != value) {
            json_node_unref(value);
        }
        return;
    }
    send_event_now(node, name, value);
}

static JsonNode *string_node(const char *text)
{
    JsonNode *node = json_node_new(JSON_NODE_VALUE);
    json_node_set_string(node, NULL != text ? text : "");
    return node;
}

static JsonNode *int_node(gint64 value)
{
    JsonNode *node = json_node_new(JSON_NODE_VALUE);
    json_node_set_int(node, value);
    return node;
}

static JsonNode *bool_node(gboolean value)
{
    JsonNode *node = json_node_new(JSON_NODE_VALUE);
    json_node_set_boolean(node, value);
    return node;
}

static void send_open(void)
{
    if (NULL == H.peer || 0 == H.pending_paths->len) {
        return;
    }
    TuiMessage *message = tui_message_new(TUI_MSG_OPEN);
    g_ptr_array_add(H.pending_paths, NULL);
    message->paths = (char **) g_ptr_array_free(H.pending_paths, FALSE);
    if (H.feature_test) {
        g_free(H.ft_last_open);
        H.ft_last_open = g_strjoinv(" | ", message->paths);
    }
    H.pending_paths = g_ptr_array_new_with_free_func(g_free);
    send_message(message);
}

static void add_open_files(GFile **files, int count)
{
    for (int i = 0; i < count; i++) {
        char *path = g_file_get_path(files[i]);
        g_ptr_array_add(H.pending_paths, NULL != path ? path : g_file_get_uri(files[i]));
    }
    send_open();
}

/* ======================================================================== */
/* Cells                                                                    */
/* ======================================================================== */

static void measure_cells(void)
{
    PangoContext *context = gtk_widget_get_pango_context(GTK_WIDGET(H.window));
    g_autoptr(PangoLayout) layout = pango_layout_new(context);
    PangoRectangle logical;

    H.font = pango_font_description_from_string(NULL != H.manifest->font_family ? H.manifest->font_family : "Monospace");
    pango_font_description_set_size(H.font, (int) (H.manifest->font_size * PANGO_SCALE));
    pango_layout_set_font_description(layout, H.font);
    pango_layout_set_text(layout, "MMMMMMMMMM", -1);
    pango_layout_get_extents(layout, NULL, &logical);
    H.cell_width = logical.width / 10.0 / PANGO_SCALE;
    H.cell_height = ceil((double) logical.height / PANGO_SCALE);
    if (H.cell_width <= 0 || H.cell_height <= 0) {
        H.cell_width = 8;
        H.cell_height = 17;
    }
}

static void grid(gint64 *columns, gint64 *rows)
{
    tui_grid_size(H.content_width, H.content_height, H.cell_width, H.cell_height, columns, rows);
}

static void send_resize(void)
{
    gint64 columns;
    gint64 rows;
    grid(&columns, &rows);
    if (columns == H.sent_columns && rows == H.sent_rows) {
        return;
    }
    H.sent_columns = columns;
    H.sent_rows = rows;
    TuiMessage *message = tui_message_new(TUI_MSG_RESIZE);
    message->columns = columns;
    message->rows = rows;
    send_message(message);
}

static gboolean resize_tick(gpointer data)
{
    (void) data;
    if (H.resize_pending) {
        H.resize_pending = FALSE;
        send_resize();
        return G_SOURCE_CONTINUE;
    }
    H.resize_timer = 0;
    return G_SOURCE_REMOVE;
}

static void start_app(void);

static void content_allocated(GtkWidget *widget, int width, int height, gpointer data)
{
    (void) widget;
    (void) data;
    if (width == H.content_width && height == H.content_height) {
        return;
    }
    H.content_width = width;
    H.content_height = height;
    H.rects_pending = TRUE;
    if (!H.started) {
        H.started = TRUE;
        g_idle_add_once((GSourceOnceFunc) start_app, NULL);
        return;
    }
    if (NULL == H.peer) {
        return;
    }
    /* At most one resize per 50 ms while the size changes, and the last one always. */
    if (0 != H.resize_timer) {
        H.resize_pending = TRUE;
    } else {
        send_resize();
        H.resize_timer = g_timeout_add(RESIZE_MS, resize_tick, NULL);
    }
}

static void send_rects(void)
{
    TuiMessage *message = tui_message_new(TUI_MSG_RECTS);
    GHashTableIter iter;
    gpointer value;

    message->rects = g_array_new(FALSE, TRUE, sizeof(TuiRect));
    g_hash_table_iter_init(&iter, H.views);
    while (g_hash_table_iter_next(&iter, NULL, &value)) {
        View *view = value;
        graphene_rect_t bounds;
        if (!gtk_widget_get_mapped(view->outer) || !gtk_widget_compute_bounds(view->outer, H.holder, &bounds)) {
            continue; /* hidden, or in a tab that is not shown */
        }
        if (NULL != view->placer) {
            /* Over a canvas: its place cut to its clip (the placer), not a control taller than its cells and centred on them */
            graphene_rect_t clip;
            int width;
            int height;
            if (!gtk_widget_get_mapped(view->placer) || !gtk_widget_compute_bounds(view->placer, H.holder, &clip)) {
                continue;
            }
            gtk_widget_get_size_request(view->outer, &width, &height);
            graphene_rect_t place = GRAPHENE_RECT_INIT(clip.origin.x + GPOINTER_TO_INT(g_object_get_data(G_OBJECT(view->placer), "tui-dx")),
                clip.origin.y + GPOINTER_TO_INT(g_object_get_data(G_OBJECT(view->placer), "tui-dy")), (float) MAX(0, width), (float) MAX(0, height));
            if (!graphene_rect_intersection(&clip, &place, &bounds)) {
                continue;
            }
        }
        TuiRect rect = {.id = view->id};
        tui_rect_cells(bounds.origin.x, bounds.origin.y, bounds.size.width, bounds.size.height, H.cell_width, H.cell_height, rect.rect);
        g_array_append_val(message->rects, rect);
    }
    /* The same frames again tell the application nothing. */
    g_autofree char *json = tui_message_to_json(message);
    if (0 == g_strcmp0(json, H.last_rects)) {
        tui_message_free(message);
        return;
    }
    g_free(H.last_rects);
    H.last_rects = g_steal_pointer(&json);
    send_message(message);
}

static void after_paint(GdkFrameClock *clock, gpointer data)
{
    (void) clock;
    (void) data;
    if (NULL == H.peer || !H.rects_pending) {
        return;
    }
    H.rects_pending = FALSE;
    send_rects();
    if (H.patch_drawn) {
        H.patch_drawn = FALSE;
        self_test_patch_drawn();
        ft_patch_drawn();
        ct_patch_drawn();
    }
}

/* ======================================================================== */
/* Styling                                                                  */
/* ======================================================================== */

static const char *base_css = ".tui-border { border: 1px solid alpha(currentColor, 0.3); border-radius: 6px; }\n"
                              ".tui-secondary { opacity: 0.65; font-size: smaller; }\n"
                              ".tui-list row { padding: 4px 8px; }\n"
                              ".tui-unknown { min-height: 4px; }\n"
                              ".tui-content { padding: 6px 8px 8px 8px; }\n"
                              /* Controls over a canvas fill their cells: no room kept around them. */
                              /* outer is the control itself but for a text field, a box around its entry. */
                              ".tui-placed, .tui-placed entry, button.tui-placed, checkbutton.tui-placed, dropdown.tui-placed,"
                              " dropdown.tui-placed > button, scrolledwindow.tui-placed, entry.tui-placed, spinbutton.tui-placed,"
                              " spinbutton.tui-placed > button, spinbutton.tui-placed > text, scale.tui-placed, .tui-placed.tui-listbox row"
                              " { min-height: 0; min-width: 0; margin: 0; }\n"
                              ".tui-placed entry, button.tui-placed, dropdown.tui-placed > button, entry.tui-placed, spinbutton.tui-placed > button, spinbutton.tui-placed > text,"
                              " scale.tui-placed, .tui-placed.tui-listbox row { padding-top: 0; padding-bottom: 0; }\n"
                              "checkbutton.tui-placed { padding: 0; }\n"
                              /* A text field, a password or a number field one cell high: its text is, its frame's top and bottom
                               * lines would take it past its cells, out of the row it is placed on */
                              ".tui-one-row entry, entry.tui-one-row, spinbutton.tui-one-row, scrolledwindow.tui-one-row { border-top-width: 0; border-bottom-width: 0; }\n"
                              /* On the canvas colour, over the canvas text the control stands for. */
                              ".tui-placer { background-color: @theme_bg_color; }\n";

static void load_css(GtkCssProvider *provider, const char *css)
{
#if GTK_CHECK_VERSION(4, 12, 0)
    gtk_css_provider_load_from_string(provider, css);
#else
    gtk_css_provider_load_from_data(provider, css, -1);
#endif
}

/* One stylesheet for every node's style and padding, rebuilt after each patch. */
static void refresh_node_css(void)
{
    GString *css = g_string_new(NULL);
    GHashTableIter iter;
    gpointer value;

    g_hash_table_iter_init(&iter, H.tree->nodes);
    while (g_hash_table_iter_next(&iter, NULL, &value)) {
        TuiNode *node = value;
        g_autofree char *style = tui_style_css(node->id, tui_prop_object(node->props, "style"));
        JsonArray *padding = tui_prop_array(node->props, "padding");
        g_string_append(css, style);
        if (NULL != padding && 4 == json_array_get_length(padding)) {
            g_string_append_printf(css, ".tui-n%" G_GINT64_FORMAT " { padding: %dpx %dpx %dpx %dpx; }\n", node->id,
                (int) round(json_array_get_int_element(padding, 0) * H.cell_height), (int) round(json_array_get_int_element(padding, 1) * H.cell_width),
                (int) round(json_array_get_int_element(padding, 2) * H.cell_height), (int) round(json_array_get_int_element(padding, 3) * H.cell_width));
        }
    }
    /* The placers of the controls over a canvas with its own background: on it. */
    g_hash_table_iter_init(&iter, H.tree->nodes);
    while (g_hash_table_iter_next(&iter, NULL, &value)) {
        TuiNode *node = value;
        const char *bg = tui_prop_string(tui_prop_object(node->props, "style"), "bg", NULL);
        double rgb[3];
        if (0 == g_strcmp0(node->kind, "canvas") && tui_color_parse(bg, rgb)) {
            g_string_append_printf(css, ".tui-n%" G_GINT64_FORMAT " > overlay > .tui-placer { background-color: rgb(%d, %d, %d); }\n", node->id,
                (int) round(rgb[0] * 255), (int) round(rgb[1] * 255), (int) round(rgb[2] * 255));
        }
    }
    /* Text over a canvas is in the canvas font. */
    if (NULL != H.font) {
        const char *family = pango_font_description_get_family(H.font);
        char size[G_ASCII_DTOSTR_BUF_SIZE];
        /* Whatever the locale's decimal separator */
        g_ascii_formatd(size, sizeof size, "%g", (double) pango_font_description_get_size(H.font) / PANGO_SCALE);
        g_string_append_printf(css, ".tui-placed entry, .tui-placed textview, entry.tui-placed, spinbutton.tui-placed, .tui-placed.tui-listbox row label { font-family: \"%s\"; font-size: %spt; }\n",
            NULL != family && NULL == strpbrk(family, "\"\\") ? family : "Monospace", size);
    }
    load_css(H.node_css, css->str);
    g_string_free(css, TRUE);
}

static void color_hex(GtkWidget *widget, char out[8])
{
    GdkRGBA rgba = {0, 0, 0, 1};
#if GTK_CHECK_VERSION(4, 10, 0)
    gtk_widget_get_color(widget, &rgba);
#endif
    g_snprintf(out, 8, "#%02x%02x%02x", (int) round(rgba.red * 255), (int) round(rgba.green * 255), (int) round(rgba.blue * 255));
}

/* ======================================================================== */
/* Views                                                                    */
/* ======================================================================== */

static View *view_get(gint64 id)
{
    return g_hash_table_lookup(H.views, &id);
}

static TuiNode *node_of(View *view)
{
    return tui_tree_get(H.tree, view->id);
}

static void detach_view(View *view)
{
    GtkWidget *parent = gtk_widget_get_parent(view->outer);

    if (NULL != view->placer) {
        /* Over a canvas: out of its placer, and the placer off the canvas. */
        GtkWidget *overlay = gtk_widget_get_parent(view->placer);
        if (parent == view->placer) {
            gtk_box_remove(GTK_BOX(view->placer), view->outer);
        }
        if (GTK_IS_OVERLAY(overlay)) {
            gtk_overlay_remove_overlay(GTK_OVERLAY(overlay), view->placer);
        }
        g_clear_object(&view->placer);
        /* Its cells, left undrawn under it (canvas_covered_cells), drawn again */
        if (NULL != view->holder) {
            gtk_widget_queue_draw(view->holder->control);
        }
        gtk_widget_remove_css_class(view->outer, "tui-placed");
        gtk_widget_remove_css_class(view->outer, "tui-one-row");
        if (GTK_IS_SPIN_BUTTON(view->control)) {
            gtk_editable_set_width_chars(GTK_EDITABLE(view->control), -1); /* as wide as GTK makes it, not its place */
        }
        if (0 == g_strcmp0(view->kind, "textarea")) {
            /* Its scrollbar, which a place one row high took away */
            gtk_scrolled_window_set_policy(GTK_SCROLLED_WINDOW(view->outer), GTK_POLICY_NEVER, GTK_POLICY_AUTOMATIC);
        }
        /* Laid out again by its new parent; apply_props() shows its prompt and grows it again. */
        gtk_widget_set_size_request(view->outer, -1, -1);
        gtk_widget_set_hexpand_set(view->outer, FALSE);
        gtk_widget_set_vexpand_set(view->outer, FALSE);
        view->holder = NULL;
        return;
    }

    if (NULL != view->holder && GTK_IS_NOTEBOOK(view->holder->content)) {
        int page = gtk_notebook_page_num(GTK_NOTEBOOK(view->holder->content), view->outer);
        if (page >= 0) {
            gtk_notebook_remove_page(GTK_NOTEBOOK(view->holder->content), page);
        }
    } else if (NULL != parent && GTK_IS_BOX(parent)) {
        gtk_box_remove(GTK_BOX(parent), view->outer);
    } else if (NULL != parent) {
        gtk_widget_unparent(view->outer);
    }
    view->holder = NULL;
}

static void context_menu_close(void);
static void context_menu_let_go(GtkWidget *popover);

static void view_free(gpointer data)
{
    View *view = data;
    if (NULL != H.context_menu && gtk_widget_is_ancestor(H.context_menu, view->outer)) {
        context_menu_close(); /* its canvas goes */
    }
    for (guint i = NULL != H.context_menus_closed ? H.context_menus_closed->len : 0; i > 0; i--) {
        GtkWidget *closed = H.context_menus_closed->pdata[i - 1];
        if (gtk_widget_is_ancestor(closed, view->outer)) {
            context_menu_let_go(closed);
        }
    }
    GtkWidget *focus = NULL != H.window ? gtk_root_get_focus(GTK_ROOT(H.window)) : NULL;
    if (NULL != focus && (focus == view->outer || gtk_widget_is_ancestor(focus, view->outer))) {
        gtk_root_set_focus(GTK_ROOT(H.window), NULL);
    }
    detach_view(view);
    if (0 != view->pulse) {
        g_source_remove(view->pulse);
    }
    if (NULL != view->im) {
        /* Before its client widget goes */
        gtk_im_context_set_client_widget(view->im, NULL);
        g_object_unref(view->im);
    }
    g_object_unref(view->outer);
    g_free(view->preedit);
    g_free(view->kind);
    g_free(view->cache);
    g_free(view);
}

static gint64 view_id(GtkWidget *widget)
{
    return (gint64) GPOINTER_TO_SIZE(g_object_get_data(G_OBJECT(widget), "tui-id"));
}

static void on_focus_enter(GtkEventControllerFocus *controller, gpointer data)
{
    (void) controller;
    if (NULL != H.ct_focus) {
        g_string_append_printf(H.ct_focus, " %" G_GINT64_FORMAT, (gint64) GPOINTER_TO_SIZE(data));
    }
    send_event(GPOINTER_TO_SIZE(data), "focus", NULL);
}

static void watch_focus(View *view, GtkWidget *widget)
{
    GtkEventController *focus = gtk_event_controller_focus_new();
    g_signal_connect(focus, "enter", G_CALLBACK(on_focus_enter), GSIZE_TO_POINTER(view->id));
    gtk_widget_add_controller(widget, focus);
}

static GtkWidget *secondary_label(void)
{
    GtkWidget *label = gtk_label_new(NULL);
    gtk_label_set_xalign(GTK_LABEL(label), 0);
    gtk_label_set_wrap(GTK_LABEL(label), TRUE);
    gtk_widget_add_css_class(label, "tui-secondary");
    return label;
}

static GtkWidget *stack_box(void)
{
    GtkWidget *box = gtk_box_new(GTK_ORIENTATION_VERTICAL, 0);
    gtk_widget_set_layout_manager(box, tui_stack_layout_new(GTK_ORIENTATION_VERTICAL));
    return box;
}

/* ---- maxLength (textfield, textarea) ---- */

/* GTK replaces a selection by deleting it, then inserting: the length before the delete is kept
 * until the main loop goes on, so that an edit of a value past maxLength may replace what it
 * deletes (tui_max_length_keep). */
static gboolean max_length_forget(gpointer data)
{
    g_object_set_data(G_OBJECT(data), "tui-length-before", NULL);
    return G_SOURCE_REMOVE;
}

static void max_length_note(GObject *object, glong length)
{
    if (H.setting > 0 || NULL != g_object_get_data(object, "tui-length-before")) {
        return;
    }
    g_object_set_data(object, "tui-length-before", GSIZE_TO_POINTER((gsize) length + 1));
    g_idle_add_full(G_PRIORITY_HIGH, max_length_forget, g_object_ref(object), g_object_unref);
}

/* How many bytes of an insertion of `len` bytes into a value of `length` characters the node's
 * maxLength lets in; rings the bell when it cuts. The host's own values are not limited; an edit while the host
 * changes controls is the user's all the same (an input method commits as the focus leaves, the field is hidden),
 * and is sent (edited). */
static gsize max_length_keep(GObject *object, gint64 id, glong length, const char *text, gssize len)
{
    gsize bytes = len < 0 ? strlen(text) : (gsize) len;
    View *view = view_get(id);
    if (H.setting > 0 || NULL == view || NULL == node_of(view)) {
        return bytes;
    }
    view->edited = H.suppress > 0;
    gsize noted = GPOINTER_TO_SIZE(g_object_get_data(object, "tui-length-before"));
    glong before = 0 != noted ? (glong) (noted - 1) : length;
    gsize keep = tui_max_length_keep(before, before - length, text, (gssize) bytes, tui_max_length(node_of(view)->props));
    if (keep < bytes) {
        gtk_widget_error_bell(view->control);
    }
    return keep;
}

/* IBus 1.5.29 (Ubuntu 24.04) drops the hiding of the preedit that comes with a key it takes synchronously
 * (PostProcessKeyEvent; ibus e0595365c and 719792d30, in 1.5.30, fix it): after Enter commits what Mozc composed,
 * its GTK module still has the preedit, shows it, and commits it again when the focus leaves. A commit of the whole
 * preedit ends the composition here: the input method is reset, and what it commits then (the same text again) is
 * dropped. */
static gboolean im_settle(gpointer data)
{
    View *view = view_get((gint64) GPOINTER_TO_SIZE(data));
    if (NULL == view || NULL != view->preedit) {
        return G_SOURCE_REMOVE; /* gone, or composing again */
    }
    view->im_resetting = TRUE;
    if (NULL != view->im) {
        gtk_im_context_reset(view->im);
    } else if (GTK_IS_ENTRY(view->control)) {
        gtk_entry_reset_im_context(GTK_ENTRY(view->control));
    } else if (GTK_IS_EDITABLE(view->control) && GTK_IS_TEXT(gtk_editable_get_delegate(GTK_EDITABLE(view->control)))) {
        /* The GtkText of a password entry or a spin button, which has no reset of its own: setting its selection
         * resets its input method */
        GtkEditable *text = gtk_editable_get_delegate(GTK_EDITABLE(view->control));
        int start = 0;
        int end = 0;
        gtk_editable_get_selection_bounds(text, &start, &end);
        gtk_editable_select_region(text, start, end);
    } else if (GTK_IS_TEXT_VIEW(view->control)) {
        gtk_text_view_reset_im_context(GTK_TEXT_VIEW(view->control));
    }
    view->im_resetting = FALSE;
    return G_SOURCE_REMOVE;
}

/* What the input method commits, `bytes` of `text`: whether to drop it, as the stale commit of a reset. */
static gboolean im_committed(View *view, const char *text, gsize bytes)
{
    if (NULL == view) {
        return FALSE;
    }
    if (view->im_resetting) {
        return TRUE;
    }
    if (NULL != view->preedit && strlen(view->preedit) == bytes && 0 == strncmp(view->preedit, text, bytes)) {
        g_clear_pointer(&view->preedit, g_free);
        if (NULL != view->im) {
            gtk_widget_queue_draw(view->control);
        }
        g_idle_add_full(G_PRIORITY_DEFAULT, im_settle, GSIZE_TO_POINTER((gsize) view->id), NULL);
    }
    return FALSE;
}

/* The preedit of a native text field or area, as their input method composes it */
static void text_preedit_changed(GtkWidget *widget, const char *preedit, gpointer data)
{
    View *view = view_get((gint64) GPOINTER_TO_SIZE(data));
    (void) widget;
    if (NULL != view) {
        g_free(view->preedit);
        view->preedit = NULL != preedit && '\0' != *preedit ? g_strdup(preedit) : NULL;
    }
}

/* What an input method replaces as it commits while the host changes controls: sent (see max_length_keep) */
static void edit_noted(gint64 id)
{
    View *view = view_get(id);
    if (NULL != view && 0 == H.setting && H.suppress > 0) {
        view->edited = TRUE;
    }
}

static void textfield_delete(GtkEditable *editable, int start, int end, gpointer data)
{
    (void) start;
    (void) end;
    edit_noted(GPOINTER_TO_SIZE(data));
    max_length_note(G_OBJECT(editable), g_utf8_strlen(gtk_editable_get_text(editable), -1));
}

/* On the GtkText of the entry, which inserts what is typed and pasted. */
static void textfield_insert(GtkEditable *editable, const char *text, int length, int *position, gpointer data)
{
    gsize bytes = length < 0 ? strlen(text) : (gsize) length;
    if (im_committed(view_get((gint64) GPOINTER_TO_SIZE(data)), text, bytes)) {
        g_signal_stop_emission_by_name(editable, "insert-text");
        return;
    }
    gsize keep = max_length_keep(G_OBJECT(editable), GPOINTER_TO_SIZE(data), g_utf8_strlen(gtk_editable_get_text(editable), -1), text, length);
    if (keep == bytes) {
        return;
    }
    g_signal_stop_emission_by_name(editable, "insert-text");
    if (keep > 0) {
        g_signal_handlers_block_by_func(editable, textfield_insert, data);
        gtk_editable_insert_text(editable, text, (int) keep, position);
        g_signal_handlers_unblock_by_func(editable, textfield_insert, data);
    }
}

/* On the GtkText of a number field: no maxLength, but what an input method commits as the application moves the
 * focus is the user's edit (edit_noted), and a stale commit is dropped (im_committed). The host's own texts are set
 * with H.setting (apply_props, number_output). */
static void number_insert(GtkEditable *editable, const char *text, int length, int *position, gpointer data)
{
    (void) position;
    if (im_committed(view_get((gint64) GPOINTER_TO_SIZE(data)), text, length < 0 ? strlen(text) : (gsize) length)) {
        g_signal_stop_emission_by_name(editable, "insert-text");
        return;
    }
    edit_noted(GPOINTER_TO_SIZE(data));
}

static void number_delete(GtkEditable *editable, int start, int end, gpointer data)
{
    (void) editable;
    (void) start;
    (void) end;
    edit_noted(GPOINTER_TO_SIZE(data));
}

static void textarea_delete(GtkTextBuffer *buffer, GtkTextIter *start, GtkTextIter *end, gpointer data)
{
    (void) start;
    (void) end;
    edit_noted(GPOINTER_TO_SIZE(data));
    max_length_note(G_OBJECT(buffer), gtk_text_buffer_get_char_count(buffer));
}

static void textarea_insert(GtkTextBuffer *buffer, GtkTextIter *location, const char *text, int length, gpointer data)
{
    gsize bytes = length < 0 ? strlen(text) : (gsize) length;
    if (im_committed(view_get((gint64) GPOINTER_TO_SIZE(data)), text, bytes)) {
        g_signal_stop_emission_by_name(buffer, "insert-text");
        return;
    }
    gsize keep = max_length_keep(G_OBJECT(buffer), GPOINTER_TO_SIZE(data), gtk_text_buffer_get_char_count(buffer), text, length);
    if (keep == bytes) {
        return;
    }
    g_signal_stop_emission_by_name(buffer, "insert-text");
    if (keep > 0) {
        /* Revalidates location for the caller, as the default handler would. */
        g_signal_handlers_block_by_func(buffer, textarea_insert, data);
        gtk_text_buffer_insert(buffer, location, text, (int) keep);
        g_signal_handlers_unblock_by_func(buffer, textarea_insert, data);
    }
}

/* ---- textfield ---- */

/* A change, sent unless the host made it (see max_length_keep for one made while it changes controls) */
static void send_change(gint64 id, const char *text)
{
    View *view = view_get(id);
    gboolean edited = NULL != view && view->edited && 0 == H.setting;
    if (NULL != view) {
        view->edited = FALSE;
    }
    if (edited) {
        send_event_now(id, "change", string_node(text));
        return;
    }
    send_event(id, "change", string_node(text));
}

static void textfield_changed(GtkEditable *editable, gpointer data)
{
    (void) data;
    send_change(view_id(GTK_WIDGET(editable)), gtk_editable_get_text(editable));
}

static void textfield_activate(GtkEntry *entry, gpointer data)
{
    (void) data;
    send_event(view_id(GTK_WIDGET(entry)), "submit", string_node(gtk_editable_get_text(GTK_EDITABLE(entry))));
}

static gboolean textfield_key(GtkEventControllerKey *controller, guint keyval, guint keycode, GdkModifierType state, gpointer data)
{
    (void) controller;
    (void) keycode;
    (void) state;
    if (GDK_KEY_Escape == keyval) {
        send_event(GPOINTER_TO_SIZE(data), "cancel", NULL);
        return TRUE;
    }
    return FALSE;
}

/* ---- textarea ---- */

static char *buffer_text(GtkTextBuffer *buffer)
{
    GtkTextIter start;
    GtkTextIter end;
    gtk_text_buffer_get_bounds(buffer, &start, &end);
    return gtk_text_buffer_get_text(buffer, &start, &end, FALSE);
}

static void textarea_changed(GtkTextBuffer *buffer, gpointer data)
{
    g_autofree char *text = buffer_text(buffer);
    send_change(GPOINTER_TO_SIZE(data), text);
}

static gboolean textarea_key(GtkEventControllerKey *controller, guint keyval, guint keycode, GdkModifierType state, gpointer data)
{
    GtkTextView *view = GTK_TEXT_VIEW(gtk_event_controller_get_widget(GTK_EVENT_CONTROLLER(controller)));
    (void) keycode;
    if ((GDK_KEY_Return == keyval || GDK_KEY_KP_Enter == keyval) && (state & (GDK_CONTROL_MASK | GDK_META_MASK | GDK_SUPER_MASK))) {
        g_autofree char *text = buffer_text(gtk_text_view_get_buffer(view));
        send_event(GPOINTER_TO_SIZE(data), "submit", string_node(text));
        return TRUE;
    }
    return FALSE;
}

/* ---- list ---- */

static void list_activated(GtkListBox *box, GtkListBoxRow *row, gpointer data)
{
    View *view = data;
    (void) box;
    if (tui_prop_bool(node_of(view)->props, "multiple", FALSE)) {
        GtkWidget *check = g_object_get_data(G_OBJECT(row), "tui-check");
        if (NULL != check) {
            gtk_check_button_set_active(GTK_CHECK_BUTTON(check), !gtk_check_button_get_active(GTK_CHECK_BUTTON(check)));
        }
        return;
    }
    send_event(view->id, "select", int_node(gtk_list_box_row_get_index(row)));
}

static void list_checked(GtkCheckButton *button, gpointer data)
{
    View *view = data;
    JsonArray *checked = json_array_new();
    (void) button;
    for (int i = 0;; i++) {
        GtkListBoxRow *row = gtk_list_box_get_row_at_index(GTK_LIST_BOX(view->control), i);
        if (NULL == row) {
            break;
        }
        GtkWidget *check = g_object_get_data(G_OBJECT(row), "tui-check");
        if (NULL != check && gtk_check_button_get_active(GTK_CHECK_BUTTON(check))) {
            json_array_add_int_element(checked, i);
        }
    }
    JsonNode *value = json_node_new(JSON_NODE_ARRAY);
    json_node_take_array(value, checked);
    send_event(view->id, "select", value);
}

static void list_rebuild(View *view, JsonObject *props)
{
    GtkListBox *box = GTK_LIST_BOX(view->control);
    JsonArray *items = tui_prop_array(props, "items");
    gboolean multiple = tui_prop_bool(props, "multiple", FALSE);
    g_autoptr(JsonNode) items_node = json_node_new(JSON_NODE_ARRAY);
    json_node_set_array(items_node, NULL != items ? items : json_array_new());
    g_autofree char *serialized = json_to_string(items_node, FALSE);
    g_autofree char *key = g_strdup_printf("%d%s", multiple, serialized);

    if (0 != g_strcmp0(key, view->cache)) {
        g_free(view->cache);
        view->cache = g_steal_pointer(&key);
        gtk_list_box_remove_all(box);
        for (guint i = 0; NULL != items && i < json_array_get_length(items); i++) {
            JsonObject *item = json_array_get_object_element(items, i);
            GtkWidget *row = gtk_list_box_row_new();
            GtkWidget *line = gtk_box_new(GTK_ORIENTATION_HORIZONTAL, 8);
            GtkWidget *texts = gtk_box_new(GTK_ORIENTATION_VERTICAL, 0);
            GtkWidget *label = gtk_label_new(tui_prop_string(item, "label", ""));
            const char *description = tui_prop_string(item, "description", NULL);

            gtk_label_set_xalign(GTK_LABEL(label), 0);
            gtk_box_append(GTK_BOX(texts), label);
            if (NULL != description) {
                GtkWidget *secondary = secondary_label();
                gtk_label_set_text(GTK_LABEL(secondary), description);
                gtk_box_append(GTK_BOX(texts), secondary);
            }
            if (multiple) {
                GtkWidget *check = gtk_check_button_new();
                gtk_box_append(GTK_BOX(line), check);
                g_object_set_data(G_OBJECT(row), "tui-check", check);
                g_signal_connect(check, "toggled", G_CALLBACK(list_checked), view);
            }
            gtk_box_append(GTK_BOX(line), texts);
            gtk_list_box_row_set_child(GTK_LIST_BOX_ROW(row), line);
            gtk_list_box_append(box, row);
        }
    }
    JsonArray *checked = tui_prop_array(props, "checked");
    for (int i = 0; multiple; i++) {
        GtkListBoxRow *row = gtk_list_box_get_row_at_index(box, i);
        if (NULL == row) {
            break;
        }
        gboolean on = FALSE;
        for (guint j = 0; NULL != checked && j < json_array_get_length(checked); j++) {
            on = on || json_array_get_int_element(checked, j) == i;
        }
        gtk_check_button_set_active(GTK_CHECK_BUTTON(g_object_get_data(G_OBJECT(row), "tui-check")), on);
    }
    gint64 selected = tui_prop_int(props, "selected", -1);
    GtkListBoxRow *row = selected >= 0 ? gtk_list_box_get_row_at_index(box, (int) selected) : NULL;
    if (NULL != row) {
        gtk_list_box_select_row(box, row);
    } else {
        gtk_list_box_unselect_all(box);
    }
}

/* ---- form ---- */

/* A value of a row the user committed. The host sets "tui-committed" before it sets a value (form_rebuild), so that
 * a value that differs from it is the user's, also when the host changes controls: the focus leaving an entry the
 * application moves the focus from, hides, disables or rebuilds commits its edit, which is sent. */
static void form_commit(GtkWidget *field, const char *value)
{
    const char *row_id = g_object_get_data(G_OBJECT(field), "tui-row");
    const char *committed = g_object_get_data(G_OBJECT(field), "tui-committed");
    if (0 == g_strcmp0(committed, value) || (GTK_IS_EDITABLE(field) && !gtk_editable_get_editable(GTK_EDITABLE(field)))) {
        return;
    }
    g_object_set_data_full(G_OBJECT(field), "tui-committed", g_strdup(value), g_free);
    JsonObject *setting = json_object_new();
    json_object_set_string_member(setting, "id", row_id);
    json_object_set_string_member(setting, "value", value);
    JsonNode *node = json_node_new(JSON_NODE_OBJECT);
    json_node_take_object(node, setting);
    send_event_now(view_id(field), "setting", node);
}

static void form_dropdown_changed(GtkDropDown *dropdown, GParamSpec *pspec, gpointer data)
{
    GtkStringObject *item = gtk_drop_down_get_selected_item(dropdown);
    (void) pspec;
    (void) data;
    if (NULL != item) {
        form_commit(GTK_WIDGET(dropdown), gtk_string_object_get_string(item));
    }
}

static void form_entry_activate(GtkEntry *entry, gpointer data)
{
    (void) data;
    form_commit(GTK_WIDGET(entry), gtk_editable_get_text(GTK_EDITABLE(entry)));
}

static void form_entry_leave(GtkEventControllerFocus *controller, gpointer data)
{
    GtkWidget *entry = gtk_event_controller_get_widget(GTK_EVENT_CONTROLLER(controller));
    (void) data;
    form_commit(entry, gtk_editable_get_text(GTK_EDITABLE(entry)));
}

/* The rows without their values: when only values change, the fields stay and keep the focus. */
static char *form_shape(JsonArray *rows)
{
    GString *shape = g_string_new(NULL);
    for (guint i = 0; NULL != rows && i < json_array_get_length(rows); i++) {
        JsonObject *row = json_array_get_object_element(rows, i);
        JsonArray *choices = tui_prop_array(row, "choices");
        g_string_append_printf(shape, "%s\x1f%s\x1f%s\x1f", tui_prop_string(row, "id", ""), tui_prop_string(row, "label", ""),
            tui_prop_string(row, "description", ""));
        for (guint j = 0; NULL != choices && j < json_array_get_length(choices); j++) {
            g_string_append_printf(shape, "%s\x1d", json_array_get_string_element(choices, j));
        }
        g_string_append_c(shape, NULL != choices ? '\x1e' : '\x1c');
    }
    return g_string_free(shape, FALSE);
}

static void form_rebuild(View *view, JsonObject *props)
{
    GtkGrid *grid = GTK_GRID(view->control);
    JsonArray *rows = tui_prop_array(props, "rows");
    g_autofree char *shape = form_shape(rows);
    gboolean rebuild = 0 != g_strcmp0(shape, view->cache);
    int line = 0;

    if (rebuild) {
        g_free(view->cache);
        view->cache = g_steal_pointer(&shape);
        for (GtkWidget *child = gtk_widget_get_first_child(GTK_WIDGET(grid)); NULL != child;) {
            GtkWidget *next = gtk_widget_get_next_sibling(child);
            gtk_grid_remove(grid, child);
            child = next;
        }
    }
    for (guint i = 0; NULL != rows && i < json_array_get_length(rows); i++) {
        JsonObject *row = json_array_get_object_element(rows, i);
        JsonArray *choices = tui_prop_array(row, "choices");
        const char *value = tui_prop_string(row, "value", "");
        const char *description = tui_prop_string(row, "description", NULL);
        GtkWidget *field;

        if (rebuild) {
            GtkWidget *label = gtk_label_new(tui_prop_string(row, "label", ""));
            gtk_label_set_xalign(GTK_LABEL(label), 0);
            gtk_grid_attach(grid, label, 0, line, 1, 1);
            if (NULL != choices) {
                g_auto(GStrv) strings = g_new0(char *, json_array_get_length(choices) + 1);
                for (guint j = 0; j < json_array_get_length(choices); j++) {
                    strings[j] = g_strdup(json_array_get_string_element(choices, j));
                }
                field = gtk_drop_down_new_from_strings((const char *const *) strings);
                g_signal_connect(field, "notify::selected", G_CALLBACK(form_dropdown_changed), NULL);
            } else {
                field = gtk_entry_new();
                GtkEventController *focus = gtk_event_controller_focus_new();
                g_signal_connect(focus, "leave", G_CALLBACK(form_entry_leave), NULL);
                gtk_widget_add_controller(field, focus);
                g_signal_connect(field, "activate", G_CALLBACK(form_entry_activate), NULL);
            }
            gtk_widget_set_hexpand(field, TRUE);
            gtk_accessible_update_relation(GTK_ACCESSIBLE(field), GTK_ACCESSIBLE_RELATION_LABELLED_BY, label, NULL, -1);
            g_object_set_data(G_OBJECT(field), "tui-id", GSIZE_TO_POINTER(view->id));
            g_object_set_data_full(G_OBJECT(field), "tui-row", g_strdup(tui_prop_string(row, "id", "")), g_free);
            g_object_set_data(G_OBJECT(field), "tui-line", GINT_TO_POINTER(line));
            gtk_grid_attach(grid, field, 1, line, 1, 1);
            if (NULL != description) {
                GtkWidget *secondary = secondary_label();
                gtk_label_set_text(GTK_LABEL(secondary), description);
                gtk_grid_attach(grid, secondary, 1, line + 1, 1, 1);
            }
        }
        field = gtk_grid_get_child_at(grid, 1, line);
        g_object_set_data_full(G_OBJECT(field), "tui-committed", g_strdup(value), g_free);
        if (GTK_IS_DROP_DOWN(field)) {
            for (guint j = 0; NULL != choices && j < json_array_get_length(choices); j++) {
                if (0 == g_strcmp0(json_array_get_string_element(choices, j), value)) {
                    gtk_drop_down_set_selected(GTK_DROP_DOWN(field), j);
                }
            }
        } else {
            /* A row the keyboard cannot change either, like a submenu: shown, selectable, not edited */
            gtk_editable_set_editable(GTK_EDITABLE(field), !tui_prop_bool(row, "readOnly", FALSE));
            if (0 != g_strcmp0(gtk_editable_get_text(GTK_EDITABLE(field)), value)) {
                gtk_editable_set_text(GTK_EDITABLE(field), value);
            }
        }
        line += NULL != description ? 2 : 1;
    }
}

/* ---- tabs ---- */

static void tabs_switched(GtkNotebook *notebook, GtkWidget *page, guint index, gpointer data)
{
    (void) notebook;
    (void) page;
    send_event(GPOINTER_TO_SIZE(data), "tab", int_node(index));
}

static void tabs_refresh(View *view)
{
    GtkNotebook *notebook = GTK_NOTEBOOK(view->content);
    JsonObject *props = node_of(view)->props;
    JsonArray *labels = tui_prop_array(props, "tabs");
    const char *position = tui_prop_string(props, "position", "top");

    for (int i = 0; i < gtk_notebook_get_n_pages(notebook); i++) {
        const char *text = NULL != labels && (guint) i < json_array_get_length(labels) ? json_array_get_string_element(labels, (guint) i) : "";
        gtk_notebook_set_tab_label_text(notebook, gtk_notebook_get_nth_page(notebook, i), text);
    }
    gtk_notebook_set_tab_pos(notebook, 0 == strcmp(position, "bottom") ? GTK_POS_BOTTOM
            : 0 == strcmp(position, "left")                            ? GTK_POS_LEFT
            : 0 == strcmp(position, "right")                           ? GTK_POS_RIGHT
                                                                       : GTK_POS_TOP);
    gint64 active = tui_prop_int(props, "active", 0);
    if (active >= 0 && active < gtk_notebook_get_n_pages(notebook) && active != gtk_notebook_get_current_page(notebook)) {
        gtk_notebook_set_current_page(notebook, (int) active);
    }
}

/* ---- disclosure ---- */

static void disclosure_toggled(GtkExpander *expander, GParamSpec *pspec, gpointer data)
{
    (void) pspec;
    send_event(GPOINTER_TO_SIZE(data), "toggle", bool_node(gtk_expander_get_expanded(expander)));
}

/* ---- progress ---- */

static gboolean progress_pulse(gpointer data)
{
    gtk_progress_bar_pulse(GTK_PROGRESS_BAR(data));
    return G_SOURCE_CONTINUE;
}

/* ---- spinner ---- */

static void spinner_cancel(GtkButton *button, gpointer data)
{
    (void) button;
    send_event(GPOINTER_TO_SIZE(data), "cancel", NULL);
}

/* ---- button, checkbox, popup ---- */

static void button_clicked(GtkButton *button, gpointer data)
{
    (void) button;
    send_event(GPOINTER_TO_SIZE(data), "press", NULL);
}

static void checkbox_toggled(GtkCheckButton *button, gpointer data)
{
    TuiNode *node = tui_tree_get(H.tree, GPOINTER_TO_SIZE(data));
    gboolean active = gtk_check_button_get_active(button);
    /* A radio button unchecked by the one checked in its group: the application unchecks the others itself. */
    if (!active && NULL != node && NULL != tui_prop_string(node->props, "group", NULL)) {
        return;
    }
    send_event(GPOINTER_TO_SIZE(data), "toggle", bool_node(active));
}

static void popup_selected(GtkDropDown *dropdown, GParamSpec *pspec, gpointer data)
{
    guint selected = gtk_drop_down_get_selected(dropdown);
    (void) pspec;
    if (GTK_INVALID_LIST_POSITION != selected) {
        send_event(GPOINTER_TO_SIZE(data), "select", int_node(selected));
    }
}

/* ---- password, number, slider, listbox ---- */

static void entry_activate(GtkWidget *widget, gpointer data)
{
    (void) data;
    send_event(view_id(widget), "submit", string_node(gtk_editable_get_text(GTK_EDITABLE(widget))));
}

static void spin_activate(GtkWidget *spin)
{
    send_event(view_id(spin), "submit", string_node(gtk_editable_get_text(GTK_EDITABLE(spin))));
}

/* The decimals of a step, as HTML writes the values: 0.5 has one, 1 none, 1e-07 seven */
static int step_digits(double step)
{
    char text[G_ASCII_DTOSTR_BUF_SIZE];
    char *exponent = strpbrk(g_ascii_formatd(text, sizeof text, "%.10g", step), "eE");
    int shift = NULL != exponent ? (int) g_ascii_strtoll(exponent + 1, NULL, 10) : 0;
    if (NULL != exponent) {
        *exponent = '\0';
    }
    const char *dot = strchr(text, '.');
    return CLAMP((NULL != dot ? (int) strlen(dot + 1) : 0) - shift, 0, 20);
}

/* A value with the decimals of the step, trailing zeros dropped, -0 as 0 */
static char *format_step(double value, double step)
{
    char *text = g_strdup_printf("%.*f", step_digits(step), value);
    if (NULL != strchr(text, '.')) {
        char *end = text + strlen(text) - 1;
        while ('0' == *end) {
            *end-- = '\0';
        }
        if ('.' == *end) {
            *end = '\0';
        }
    }
    if (0 == strcmp(text, "-0")) {
        text[0] = '0';
        text[1] = '\0';
    }
    return text;
}

/* What is typed in a number field, which GtkSpinButton reads before it steps and when the text is committed (Enter,
 * the focus leaving): the value it steps from, within min and max (GTK would put the last value back past them), or
 * the last one when the text is not a number. The text stays as typed (number_output): GTK would clamp it, snap it to
 * the steps and write it again. */
static int number_input(GtkSpinButton *spin, double *value, gpointer data)
{
    View *view = view_get((gint64) GPOINTER_TO_SIZE(data));
    GtkAdjustment *adjustment = gtk_spin_button_get_adjustment(spin);
    const char *text = gtk_editable_get_text(GTK_EDITABLE(spin));
    char *end = NULL;
    double parsed = g_ascii_strtod(text, &end);
    if (NULL == view) {
        return FALSE;
    }
    /* Spaces after the number, not in the text of the field, which is not ours to change */
    while (NULL != end && g_ascii_isspace(*end)) {
        end++;
    }
    view->keep_text = TRUE;
    if ('\0' == *text || NULL == end || '\0' != *end || !isfinite(parsed)) {
        *value = gtk_spin_button_get_value(spin);
        return TRUE;
    }
    /* On a step within the bounds, as GTK snaps it (snap_to_ticks), not above max: GTK would put it back in them
     * without a word, and the text would never be written for the next step */
    double lower = gtk_adjustment_get_lower(adjustment);
    double upper = gtk_adjustment_get_upper(adjustment);
    double step = gtk_adjustment_get_step_increment(adjustment);
    double snapped = step > 0 ? lower + round((CLAMP(parsed, lower, upper) - lower) / step) * step : CLAMP(parsed, lower, upper);
    while (step > 0 && snapped > upper + 1e-9) {
        snapped -= step;
    }
    *value = MAX(lower, snapped);
    return TRUE;
}

static gboolean number_output(GtkSpinButton *spin, gpointer data)
{
    View *view = view_get((gint64) GPOINTER_TO_SIZE(data));
    GtkAdjustment *adjustment = gtk_spin_button_get_adjustment(spin);
    if (NULL != view && view->keep_text) {
        view->keep_text = FALSE;
        return TRUE; /* the text as typed */
    }
    g_autofree char *text = format_step(gtk_adjustment_get_value(adjustment), gtk_adjustment_get_step_increment(adjustment));
    if (0 != strcmp(text, gtk_editable_get_text(GTK_EDITABLE(spin)))) {
        /* One change: setting the text deletes it, then inserts the new one, a change each (the first "") */
        H.setting++;
        g_signal_handlers_block_by_func(spin, textfield_changed, NULL);
        gtk_editable_set_text(GTK_EDITABLE(spin), text);
        g_signal_handlers_unblock_by_func(spin, textfield_changed, NULL);
        H.setting--;
        if (NULL != view) {
            send_change(view->id, text);
        }
    }
    return TRUE;
}

/* The step Up or Down (count steps, Page Up and Page Down 10) goes to, as HTML's stepUp and stepDown (HOSTS.md):
 * from a number off the steps from min, the nearest step that way, then count - 1 steps on; from one on a step, count
 * steps on; then below min, min, and above max, the highest step at or below max. A step that would go the other way
 * (Up from above max, Down from below min), or none at all (Up at the highest step), leaves the number as it is:
 * *target NAN, nothing sent. Every step of a number is the host's: GTK's goes to max off the steps, and a held key
 * climbs off them (climb rate). FALSE for GTK to step it: no number. */
static gboolean number_step_target(GtkSpinButton *spin, int direction, double count, double *target)
{
    GtkAdjustment *adjustment = gtk_spin_button_get_adjustment(spin);
    const char *text = gtk_editable_get_text(GTK_EDITABLE(spin));
    double lower = gtk_adjustment_get_lower(adjustment);
    double upper = gtk_adjustment_get_upper(adjustment);
    double step = gtk_adjustment_get_step_increment(adjustment);
    char *end = NULL;
    double parsed = g_ascii_strtod(text, &end);
    while (NULL != end && g_ascii_isspace(*end)) {
        end++;
    }
    if ('\0' == *text || NULL == end || '\0' != *end || !isfinite(parsed) || step <= 0 || lower > upper) {
        return FALSE;
    }
    double steps = (parsed - lower) / step;
    gboolean on_step = fabs(steps - round(steps)) < 1e-9;
    double top = MAX(lower, lower + floor((upper - lower) / step + 1e-9) * step); /* the highest step at or below max */
    double next = lower + (on_step ? round(steps) + direction * count : direction > 0 ? floor(steps) + count : ceil(steps) - count) * step;
    if (next < lower) {
        next = lower;
    }
    if (next > upper) {
        next = top;
    }
    *target = (direction > 0 && next <= parsed) || (direction < 0 && next >= parsed) ? NAN : next;
    return TRUE;
}

/* Steps a number field typed off the steps or past min or max (number_step_target), or leaves it as it is; FALSE
 * for GTK to step it */
static gboolean number_step(GtkSpinButton *spin, int direction, double count)
{
    View *view = view_get(view_id(GTK_WIDGET(spin)));
    double target = NAN;
    if (NULL == view || !number_step_target(spin, direction, count, &target)) {
        return FALSE;
    }
    if (!isnan(target)) {
        view->keep_text = FALSE; /* written for the step */
        gtk_spin_button_set_value(spin, target);
    }
    return TRUE;
}

/* Up, Down, Page Up and Page Down (GtkSpinButton::change-value, before GTK's own) */
static void number_change_value(GtkSpinButton *spin, GtkScrollType scroll, gpointer data)
{
    int direction = 0;
    double count = 1;
    (void) data;
    switch (scroll) {
    case GTK_SCROLL_PAGE_UP:
    case GTK_SCROLL_PAGE_FORWARD:
    case GTK_SCROLL_PAGE_RIGHT:
        count = 10;
        /* fall through */
    case GTK_SCROLL_STEP_UP:
    case GTK_SCROLL_STEP_FORWARD:
    case GTK_SCROLL_STEP_RIGHT:
        direction = 1;
        break;
    case GTK_SCROLL_PAGE_DOWN:
    case GTK_SCROLL_PAGE_BACKWARD:
    case GTK_SCROLL_PAGE_LEFT:
        count = 10;
        /* fall through */
    case GTK_SCROLL_STEP_DOWN:
    case GTK_SCROLL_STEP_BACKWARD:
    case GTK_SCROLL_STEP_LEFT:
        direction = -1;
        break;
    default:
        return;
    }
    if (gtk_editable_get_editable(GTK_EDITABLE(spin)) && number_step(spin, direction, count)) {
        g_signal_stop_emission_by_name(spin, "change-value");
    }
}

/* An arrow of the stepper held: after 500 ms, a step every 50 ms, as GTK's own, until it is let go */
static struct {
    GtkSpinButton *spin;
    int direction;
    double count;
    guint source;
} number_held;

static void number_hold_stop(void)
{
    if (0 != number_held.source) {
        g_source_remove(number_held.source);
        number_held.source = 0;
    }
    g_clear_object(&number_held.spin);
}

static gboolean number_hold_repeat(gpointer data)
{
    (void) data;
    if (NULL == number_held.spin || !gtk_widget_get_mapped(GTK_WIDGET(number_held.spin))
        || !number_step(number_held.spin, number_held.direction, number_held.count)) {
        number_held.source = 0;
        number_hold_stop();
        return G_SOURCE_REMOVE;
    }
    return G_SOURCE_CONTINUE;
}

static gboolean number_hold_first(gpointer data)
{
    number_held.source = 0;
    if (G_SOURCE_CONTINUE == number_hold_repeat(data)) {
        number_held.source = g_timeout_add(50, number_hold_repeat, NULL);
    }
    return G_SOURCE_REMOVE;
}

static void number_released(GtkGesture *gesture, GdkEventSequence *sequence, gpointer data)
{
    (void) gesture;
    (void) sequence;
    (void) data;
    number_hold_stop();
}

/* A press on the stepper's arrows (before GTK's, which would snap the number to the nearest step, then step), held:
 * the host repeats it (number_held) */
static void number_pressed(GtkGestureClick *gesture, int presses, double x, double y, gpointer data)
{
    GtkWidget *spin = gtk_event_controller_get_widget(GTK_EVENT_CONTROLLER(gesture));
    guint button = gtk_gesture_single_get_current_button(GTK_GESTURE_SINGLE(gesture));
    int direction = 0;
    (void) presses;
    (void) data;
    for (GtkWidget *picked = gtk_widget_pick(spin, x, y, GTK_PICK_DEFAULT); NULL != picked && picked != spin; picked = gtk_widget_get_parent(picked)) {
        if (GTK_IS_BUTTON(picked)) {
            direction = gtk_widget_has_css_class(picked, "up") ? 1 : gtk_widget_has_css_class(picked, "down") ? -1 : 0;
            break;
        }
    }
    if (0 == direction || (GDK_BUTTON_PRIMARY != button && GDK_BUTTON_MIDDLE != button) || !gtk_editable_get_editable(GTK_EDITABLE(spin))) {
        return;
    }
    gtk_widget_grab_focus(spin);
    if (number_step(GTK_SPIN_BUTTON(spin), direction, GDK_BUTTON_MIDDLE == button ? 10 : 1)) {
        gtk_gesture_set_state(GTK_GESTURE(gesture), GTK_EVENT_SEQUENCE_CLAIMED);
        number_hold_stop();
        number_held.spin = g_object_ref(GTK_SPIN_BUTTON(spin));
        number_held.direction = direction;
        number_held.count = GDK_BUTTON_MIDDLE == button ? 10 : 1;
        number_held.source = g_timeout_add(500, number_hold_first, NULL);
    }
}

/* A slider moves by its step, from min: a value off it goes to the nearest one */
static void slider_moved(GtkRange *range, gpointer data)
{
    View *view = view_get((gint64) GPOINTER_TO_SIZE(data));
    GtkAdjustment *adjustment = gtk_range_get_adjustment(range);
    double min = gtk_adjustment_get_lower(adjustment);
    double max = gtk_adjustment_get_upper(adjustment);
    double step = gtk_adjustment_get_step_increment(adjustment);
    double value = gtk_range_get_value(range);
    double snapped = step > 0 ? min + round((value - min) / step) * step : value;
    while (snapped > max + 1e-9) {
        snapped -= step;
    }
    snapped = round(snapped * 1e10) / 1e10;
    if (NULL == view) {
        return;
    }
    if (fabs(snapped - value) > 1e-12) {
        gtk_range_set_value(range, snapped); /* comes back here */
        return;
    }
    if (fabs(snapped - view->sent) < 1e-12) {
        return;
    }
    view->sent = snapped;
    JsonNode *node = json_node_new(JSON_NODE_VALUE);
    if (snapped == round(snapped) && fabs(snapped) < 9e15) {
        json_node_set_int(node, (gint64) snapped);
    } else {
        json_node_set_double(node, snapped);
    }
    send_event(view->id, "change", node);
}

static void listbox_selected(GtkListBox *box, gpointer data)
{
    JsonArray *indexes = json_array_new();
    GtkListBoxRow *row;
    for (int i = 0; NULL != (row = gtk_list_box_get_row_at_index(box, i)); i++) {
        if (gtk_list_box_row_is_selected(row)) {
            json_array_add_int_element(indexes, i);
        }
    }
    JsonNode *node = json_node_new(JSON_NODE_ARRAY);
    json_node_take_array(node, indexes);
    send_event(GPOINTER_TO_SIZE(data), "select", node);
}

/* ---- keys ---- */

static guint tui_mods(GdkModifierType state)
{
    guint mods = 0;
    if (state & GDK_CONTROL_MASK) {
        mods |= TUI_MOD_CTRL;
    }
    if (state & GDK_SHIFT_MASK) {
        mods |= TUI_MOD_SHIFT;
    }
    if (state & GDK_ALT_MASK) {
        mods |= TUI_MOD_ALT;
    }
    if (state & (GDK_SUPER_MASK | GDK_META_MASK)) {
        mods |= TUI_MOD_SUPER;
    }
    return mods;
}

/* TRUE when the key went out (or is not to go anywhere). */
static gboolean send_key(guint keyval, GdkModifierType state)
{
    g_autofree char *text = NULL;
    g_autofree char *id = tui_key_id(keyval, gdk_keyval_to_unicode(keyval), tui_mods(state), &text);

    if (NULL == id || 0 == strcmp(id, "tab") || 0 == strcmp(id, "shift+tab")) {
        return FALSE;
    }
    if (g_hash_table_contains(H.shortcuts, id)) {
        return FALSE; /* a menu item takes it */
    }
    TuiMessage *message = tui_message_new(TUI_MSG_KEY);
    message->key = g_steal_pointer(&id);
    message->has_text = TRUE;
    message->text = g_steal_pointer(&text);
    send_message(message);
    return TRUE;
}

static void send_key_id(const char *id, const char *text)
{
    TuiMessage *message = tui_message_new(TUI_MSG_KEY);
    message->key = g_strdup(id);
    message->has_text = TRUE;
    message->text = g_strdup(text);
    send_message(message);
}

static gboolean window_key(GtkEventControllerKey *controller, guint keyval, guint keycode, GdkModifierType state, gpointer data)
{
    (void) controller;
    (void) keycode;
    (void) data;
    return send_key(keyval, state);
}

/* ---- canvas ---- */

static void canvas_pasted(GObject *source, GAsyncResult *result, gpointer data)
{
    g_autofree char *text = gdk_clipboard_read_text_finish(GDK_CLIPBOARD(source), result, NULL);
    (void) data;
    if (NULL != text) {
        TuiMessage *message = tui_message_new(TUI_MSG_PASTE);
        message->text = g_steal_pointer(&text);
        message->has_text = TRUE;
        send_message(message);
    }
}

/* ---- contextmenu ---- */

/* The paste item of a context menu: the clipboard's text in a paste message, nothing when it holds none */
static void context_menu_pasted(GObject *source, GAsyncResult *result, gpointer data)
{
    g_autofree char *text = gdk_clipboard_read_text_finish(GDK_CLIPBOARD(source), result, NULL);
    (void) data;
    if (NULL != text && '\0' != *text) {
        TuiMessage *message = tui_message_new(TUI_MSG_PASTE);
        message->text = g_steal_pointer(&text);
        message->has_text = TRUE;
        send_message(message);
    }
}

/* An item chosen: contextmenu-select with its id, or for the paste role what Paste does */
static void context_menu_activated(GSimpleAction *action, GVariant *parameter, gpointer data)
{
    (void) parameter;
    (void) data;
    if (0 == g_strcmp0(g_object_get_data(G_OBJECT(action), "tui-role"), "paste")) {
        gdk_clipboard_read_text_async(gdk_display_get_clipboard(gtk_widget_get_display(GTK_WIDGET(H.window))), NULL, context_menu_pasted, NULL);
        return;
    }
    TuiMessage *message = tui_message_new(TUI_MSG_CONTEXTMENU_SELECT);
    message->id = g_strdup(g_object_get_data(G_OBJECT(action), "tui-id"));
    send_message(message);
}

/* A closed menu off its canvas: on idle, after GTK activated the item chosen, or at once when its canvas goes
 * (view_free), which would leave it on a freed parent */
static void context_menu_let_go(GtkWidget *popover)
{
    if (NULL == H.context_menus_closed || !g_ptr_array_remove(H.context_menus_closed, popover)) {
        return; /* let go of already */
    }
    if (NULL != gtk_widget_get_parent(popover)) {
        gtk_widget_unparent(popover);
    }
    g_object_unref(popover);
}

static gboolean context_menu_unparent(gpointer data)
{
    context_menu_let_go(data);
    return G_SOURCE_REMOVE;
}

/* Dismissed or an item chosen (GTK activates the item before it closes the menu): nothing more is sent */
static void context_menu_closed(GtkPopover *popover, gpointer data)
{
    (void) data;
    if (GTK_WIDGET(popover) == H.context_menu) {
        H.context_menu = NULL;
    }
    if (NULL == H.context_menus_closed) {
        H.context_menus_closed = g_ptr_array_new();
    }
    g_ptr_array_add(H.context_menus_closed, g_object_ref(popover));
    /* A ref of its own: let go of already when its canvas went (view_free), it is still this popover, not another
     * one at its freed address */
    g_idle_add_full(G_PRIORITY_DEFAULT_IDLE, context_menu_unparent, g_object_ref(popover), g_object_unref);
}

static void context_menu_close(void)
{
    GtkWidget *popover = H.context_menu;
    if (NULL == popover) {
        return;
    }
    H.context_menu = NULL;
    g_signal_handlers_disconnect_by_func(popover, context_menu_closed, NULL);
    gtk_popover_popdown(GTK_POPOVER(popover));
    /* Taken off at once (another menu, or its canvas going): the focus, in it, back on its canvas, as closing it
     * does, not left on a button off the window for GTK to move later to the first control it finds */
    GtkWidget *focus = gtk_root_get_focus(GTK_ROOT(H.window));
    GtkWidget *canvas = gtk_widget_get_parent(popover);
    if (NULL != focus && NULL != canvas && (focus == popover || gtk_widget_is_ancestor(focus, popover))) {
        gtk_widget_grab_focus(canvas);
    }
    gtk_widget_unparent(popover);
}


/* For a script driving the menu with keys (linux/contextmenu.sh): once it is mapped, and the focus is in it */
static gboolean context_menu_focus_check(gpointer data)
{
    GtkWidget *popover = data;
    GtkWidget *focus = gtk_root_get_focus(GTK_ROOT(H.window));
    gboolean in = NULL != focus && (focus == popover || gtk_widget_is_ancestor(focus, popover));
    if (gtk_widget_get_mapped(popover) && in) {
        g_debug("context menu mapped, the focus in it");
        return G_SOURCE_REMOVE;
    }
    guint checks = GPOINTER_TO_UINT(g_object_get_data(G_OBJECT(popover), "tui-focus-checks")) + 1;
    g_object_set_data(G_OBJECT(popover), "tui-focus-checks", GUINT_TO_POINTER(checks));
    if (!gtk_widget_get_mapped(popover) || NULL == gtk_widget_get_parent(popover) || checks > 250) {
        g_debug("context menu %s, the focus not in it", gtk_widget_get_mapped(popover) ? "mapped for 5 s" : "closed");
        return G_SOURCE_REMOVE;
    }
    return G_SOURCE_CONTINUE;
}

static void context_menu_mapped(GtkWidget *popover, gpointer data)
{
    (void) data;
    if (debug_enabled()) {
        g_timeout_add_full(G_PRIORITY_DEFAULT, 20, context_menu_focus_check, g_object_ref(popover), g_object_unref);
    }
}

/* contextmenu: a GtkPopoverMenu at the cell of the canvas, its items in order, separators as sections, an item with
 * enabled false greyed out (its action disabled); it replaces one shown */
static void show_context_menu(const TuiMessage *message)
{
    View *view = view_get(message->node);
    if (NULL == view || 0 != g_strcmp0(view->kind, "canvas") || NULL == view->control || !gtk_widget_get_mapped(view->control)) {
        return; /* an unknown node, not a canvas, or not shown */
    }
    context_menu_close();
    GSimpleActionGroup *actions = g_simple_action_group_new();
    GMenu *menu = g_menu_new();
    GMenu *section = g_menu_new();
    for (guint i = 0; i < message->items->len; i++) {
        TuiMenuItem *item = message->items->pdata[i];
        if (item->has_separator && item->separator) {
            if (g_menu_model_get_n_items(G_MENU_MODEL(section)) > 0) {
                g_menu_append_section(menu, NULL, G_MENU_MODEL(section));
            }
            g_object_unref(section);
            section = g_menu_new();
            continue;
        }
        g_autofree char *name = g_strdup_printf("i%u", i);
        g_autofree char *detailed = g_strdup_printf("contextmenu.%s", name);
        GSimpleAction *action = g_simple_action_new(name, NULL);
        g_object_set_data_full(G_OBJECT(action), "tui-id", g_strdup(item->id), g_free);
        g_object_set_data_full(G_OBJECT(action), "tui-role", g_strdup(item->role), g_free);
        g_simple_action_set_enabled(action, !item->has_enabled || item->enabled);
        g_signal_connect(action, "activate", G_CALLBACK(context_menu_activated), NULL);
        g_action_map_add_action(G_ACTION_MAP(actions), G_ACTION(action));
        g_object_unref(action);
        /* As given: GtkPopoverMenu reads an underscore as a mnemonic, "__" as one underscore */
        g_auto(GStrv) parts = g_strsplit(item->label, "_", -1);
        g_autofree char *label = g_strjoinv("__", parts);
        g_menu_append(section, label, detailed);
    }
    if (g_menu_model_get_n_items(G_MENU_MODEL(section)) > 0) {
        g_menu_append_section(menu, NULL, G_MENU_MODEL(section));
    }
    g_object_unref(section);
    GtkWidget *popover = gtk_popover_menu_new_from_model(G_MENU_MODEL(menu));
    g_object_unref(menu);
    gtk_widget_insert_action_group(popover, "contextmenu", G_ACTION_GROUP(actions));
    g_object_set_data_full(G_OBJECT(popover), "tui-actions", actions, g_object_unref); /* for the canvas test */
    GdkRectangle cell = {(int) floor(message->column * H.cell_width), (int) floor(message->row * H.cell_height), MAX(1, (int) ceil(H.cell_width)),
        MAX(1, (int) ceil(H.cell_height))};
    gtk_widget_set_parent(popover, view->control);
    gtk_popover_set_pointing_to(GTK_POPOVER(popover), &cell);
    gtk_popover_set_has_arrow(GTK_POPOVER(popover), FALSE);
    gtk_widget_set_halign(popover, GTK_ALIGN_START);
    g_signal_connect(popover, "closed", G_CALLBACK(context_menu_closed), NULL);
    g_signal_connect(popover, "map", G_CALLBACK(context_menu_mapped), NULL);
    H.context_menu = popover;
    H.context_menus_shown++;
    gtk_popover_popup(GTK_POPOVER(popover));
    if (debug_enabled()) {
        GString *items = g_string_new(NULL);
        for (guint i = 0; i < message->items->len; i++) {
            TuiMenuItem *item = message->items->pdata[i];
            g_string_append_printf(items, "%s%s", 0 == i ? "" : "; ", NULL != item->id ? item->id : "-");
            if (NULL != item->label) {
                g_string_append_printf(items, "=%s", item->label);
            }
        }
        g_debug("context menu shown on canvas %" G_GINT64_FORMAT " at %" G_GINT64_FORMAT " %" G_GINT64_FORMAT ": %s", message->node, message->row, message->column,
            items->str);
        g_string_free(items, TRUE);
    }
}

/* clipboard: the text on the clipboard, as Copy */
static void set_clipboard(const char *text)
{
    g_debug("clipboard: %ld characters", g_utf8_strlen(text, -1));
    gdk_clipboard_set_text(gdk_display_get_clipboard(gtk_widget_get_display(GTK_WIDGET(H.window))), text);
}

static gboolean canvas_key(GtkEventControllerKey *controller, guint keyval, guint keycode, GdkModifierType state, gpointer data)
{
    GtkWidget *widget = gtk_event_controller_get_widget(GTK_EVENT_CONTROLLER(controller));
    GdkModifierType mods = state & (GDK_CONTROL_MASK | GDK_SHIFT_MASK | GDK_ALT_MASK | GDK_SUPER_MASK);
    (void) keycode;
    (void) data;
    if (((GDK_KEY_v == keyval || GDK_KEY_V == keyval) && (GDK_CONTROL_MASK | GDK_SHIFT_MASK) == mods)
        || (GDK_KEY_Insert == keyval && GDK_SHIFT_MASK == mods)) {
        gdk_clipboard_read_text_async(gtk_widget_get_clipboard(widget), NULL, canvas_pasted, NULL);
        return TRUE;
    }
    return send_key(keyval, state);
}

/* The canvas, then its controls in order: what Tab goes through (HOSTS.md), placers that are shown (not hidden, not
 * clipped to nothing) */
static GPtrArray *canvas_chain(View *view, TuiNode *node)
{
    GPtrArray *chain = g_ptr_array_new();
    g_ptr_array_add(chain, view->control);
    for (guint i = 0; i < node->children->len; i++) {
        View *child = view_get(((TuiNode *) node->children->pdata[i])->id);
        if (NULL != child && NULL != child->placer && gtk_widget_get_parent(child->placer) == view->content) {
            g_ptr_array_add(chain, child->placer);
        }
    }
    return chain;
}

/* Whether an input method composes in the canvas or in its control that has the focus: the keys are its own then */
static gboolean canvas_composing(View *view, TuiNode *node)
{
    if (NULL != view->preedit) {
        return TRUE;
    }
    GtkWidget *focus = gtk_root_get_focus(GTK_ROOT(H.window));
    for (guint i = 0; NULL != focus && i < node->children->len; i++) {
        View *child = view_get(((TuiNode *) node->children->pdata[i])->id);
        if (NULL != child && NULL != child->preedit && (focus == child->control || gtk_widget_is_ancestor(focus, child->control))) {
            return TRUE;
        }
    }
    return FALSE;
}

/* The canvas in the window, around its overlay: Tab and Shift+Tab go through the canvas and its controls in the order
 * of the nodes, not where GtkOverlay's children are, then on out of it. */
#define TUI_TYPE_CANVAS_BOX (tui_canvas_box_get_type())
G_DECLARE_FINAL_TYPE(TuiCanvasBox, tui_canvas_box, TUI, CANVAS_BOX, GtkWidget)

struct _TuiCanvasBox {
    GtkWidget parent_instance;
};

G_DEFINE_FINAL_TYPE(TuiCanvasBox, tui_canvas_box, GTK_TYPE_WIDGET)

static gboolean canvas_box_focus(GtkWidget *widget, GtkDirectionType direction)
{
    View *view = view_get(view_id(widget));
    TuiNode *node = NULL != view ? node_of(view) : NULL;
    if (NULL == node || (GTK_DIR_TAB_FORWARD != direction && GTK_DIR_TAB_BACKWARD != direction)) {
        return GTK_WIDGET_CLASS(tui_canvas_box_parent_class)->focus(widget, direction);
    }
    int step = GTK_DIR_TAB_FORWARD == direction ? 1 : -1;
    GPtrArray *chain = canvas_chain(view, node);
    GtkWidget *focus = gtk_root_get_focus(gtk_widget_get_root(widget));
    int at = -1;
    for (guint i = 0; NULL != focus && i < chain->len; i++) {
        if (focus == chain->pdata[i] || gtk_widget_is_ancestor(focus, chain->pdata[i])) {
            at = (int) i;
        }
    }
    /* Within the focused one first (its parts), then the next ones; from outside, the first or the last one */
    gboolean moved = at >= 0 && gtk_widget_child_focus(chain->pdata[at], direction);
    for (int i = at >= 0 ? at + step : step > 0 ? 0 : (int) chain->len - 1; !moved && i >= 0 && i < (int) chain->len; i += step) {
        moved = gtk_widget_child_focus(chain->pdata[i], direction);
    }
    g_ptr_array_free(chain, TRUE);
    return moved; /* past the ends: on out of the canvas */
}

static void canvas_box_dispose(GObject *object)
{
    GtkWidget *child;
    while (NULL != (child = gtk_widget_get_first_child(GTK_WIDGET(object)))) {
        gtk_widget_unparent(child);
    }
    G_OBJECT_CLASS(tui_canvas_box_parent_class)->dispose(object);
}

static void tui_canvas_box_class_init(TuiCanvasBoxClass *klass)
{
    G_OBJECT_CLASS(klass)->dispose = canvas_box_dispose;
    GTK_WIDGET_CLASS(klass)->focus = canvas_box_focus;
    gtk_widget_class_set_layout_manager_type(GTK_WIDGET_CLASS(klass), GTK_TYPE_BIN_LAYOUT);
}

static void tui_canvas_box_init(TuiCanvasBox *self)
{
    (void) self;
}

/* The keys a canvas takes for the application (`keys`), from the canvas or a control over it, unless an input method
 * composes there; and Tab through its chain otherwise (canvas_box_focus), which a text area does not take as text. */
static gboolean canvas_capture_key(GtkEventControllerKey *controller, guint keyval, guint keycode, GdkModifierType state, gpointer data)
{
    View *view = data;
    TuiNode *node = node_of(view);
    g_autofree char *text = NULL;
    g_autofree char *id = tui_key_id(keyval, gdk_keyval_to_unicode(keyval), tui_mods(state), &text);
    (void) controller;
    (void) keycode;

    if (NULL == id || NULL == node) {
        return FALSE;
    }
    /* GtkIMContextSimple would commit Super+A as the text "a": the key itself, before the input method sees it */
    if (state & (GDK_SUPER_MASK | GDK_META_MASK | GDK_HYPER_MASK)) {
        return send_key(keyval, state);
    }
    if (canvas_composing(view, node)) {
        return FALSE;
    }
    JsonArray *keys = tui_prop_array(node->props, "keys");
    for (guint i = 0; NULL != keys && i < json_array_get_length(keys); i++) {
        if (0 == g_strcmp0(json_array_get_string_element(keys, i), id)) {
            send_key_id(id, text);
            return TRUE;
        }
    }
    gboolean forward = 0 == strcmp(id, "tab");
    if (!forward && 0 != strcmp(id, "shift+tab")) {
        return FALSE;
    }
    /* What GtkWindow does with Tab: on from the focus, else around from the start */
    GtkDirectionType direction = forward ? GTK_DIR_TAB_FORWARD : GTK_DIR_TAB_BACKWARD;
    if (!gtk_widget_child_focus(GTK_WIDGET(H.window), direction)) {
        gtk_window_set_focus(H.window, NULL);
        gtk_widget_child_focus(GTK_WIDGET(H.window), direction);
    }
    return TRUE;
}

/* Committed text goes out as typed keys; see im_committed() for the commit a reset makes again */
static void canvas_commit(GtkIMContext *context, const char *text, gpointer data)
{
    View *view = data;
    g_auto(GStrv) keys = NULL;
    (void) context;
    if (im_committed(view, text, strlen(text))) {
        return;
    }
    keys = tui_committed_keys(text);
    for (guint i = 0; NULL != keys[i] && NULL != keys[i + 1]; i += 2) {
        send_key_id(keys[i], keys[i + 1]);
    }
}

static void canvas_preedit_changed(GtkIMContext *context, gpointer data)
{
    View *view = data;
    char *preedit = NULL;
    gtk_im_context_get_preedit_string(context, &preedit, NULL, NULL);
    g_free(view->preedit);
    view->preedit = NULL != preedit && '\0' != *preedit ? preedit : (g_free(preedit), NULL);
    gtk_widget_queue_draw(view->control);
}

/* The text around the cursor, for input methods that look at it (IBus asks): a canvas has none */
static gboolean canvas_retrieve_surrounding(GtkIMContext *context, gpointer data)
{
    (void) data;
    gtk_im_context_set_surrounding_with_selection(context, "", 0, 0, 0);
    return TRUE;
}

/* Where the input method composes, for its candidate window: the cursor cell, or the first one. */
static void canvas_im_location(View *view)
{
    TuiNode *node = node_of(view);
    JsonArray *cursor = NULL != node ? tui_prop_array(node->props, "cursor") : NULL;
    gboolean has = NULL != cursor && 2 == json_array_get_length(cursor);
    GdkRectangle area = {
        .x = has ? (int) round(json_array_get_int_element(cursor, 1) * H.cell_width) : 0,
        .y = has ? (int) round(json_array_get_int_element(cursor, 0) * H.cell_height) : 0,
        .width = (int) ceil(H.cell_width),
        .height = (int) ceil(H.cell_height),
    };
    if (NULL != view->im) {
        gtk_im_context_set_cursor_location(view->im, &area);
    }
}

/* ---- the mouse on a canvas (pointer) ---- */

/* What the mouse does on the drawing of a canvas, in its cells from its top-left corner, rounded down (a drag goes on
 * past its edges). The controls placed over it are siblings of the drawing in its overlay: what the mouse does on
 * them is theirs, not the canvas's. */
static void send_pointer(View *view, const char *action, double x, double y, const char *button, GdkModifierType state, gboolean wheel,
    gint64 rows, gint64 columns)
{
    TuiMessage *message = tui_message_new(TUI_MSG_POINTER);
    GPtrArray *modifiers = g_ptr_array_new();
    message->node = view->id;
    message->action = g_strdup(action);
    tui_pointer_cell(x, y, H.cell_width, H.cell_height, &message->row, &message->column);
    message->button = g_strdup(button);
    if (state & GDK_CONTROL_MASK) {
        g_ptr_array_add(modifiers, g_strdup("ctrl"));
    }
    if (state & GDK_SHIFT_MASK) {
        g_ptr_array_add(modifiers, g_strdup("shift"));
    }
    if (state & GDK_ALT_MASK) {
        g_ptr_array_add(modifiers, g_strdup("alt"));
    }
    /* GDK 4 on X11 gives a button's Super as the core Mod4 bit (1 << 6, which GdkModifierType leaves unnamed), not
     * GDK_SUPER_MASK; Meta with Alt is Alt (Meta_L shares Mod1 with Alt_L in the usual layouts) */
    if ((state & (GDK_SUPER_MASK | (1 << 6))) || ((state & GDK_META_MASK) && !(state & GDK_ALT_MASK))) {
        g_ptr_array_add(modifiers, g_strdup("super"));
    }
    g_ptr_array_add(modifiers, NULL);
    message->modifiers = (char **) g_ptr_array_free(modifiers, FALSE);
    message->has_scroll = wheel;
    message->scroll_rows = rows;
    message->scroll_columns = columns;
    view->has_pointer_cell = TRUE;
    view->pointer_row = message->row;
    view->pointer_column = message->column;
    if (NULL != H.ct_events) {
        g_string_append_printf(H.ct_events, "%" G_GINT64_FORMAT " pointer %s %" G_GINT64_FORMAT " %" G_GINT64_FORMAT "%s%s", view->id, action, message->row,
            message->column, NULL != button ? " " : "", NULL != button ? button : "");
        if (wheel) {
            g_string_append_printf(H.ct_events, " %" G_GINT64_FORMAT " %" G_GINT64_FORMAT, rows, columns);
        }
        g_string_append(H.ct_events, "; ");
    }
    send_message(message);
}

/* A move to another cell; within the cell last sent, nothing */
static void canvas_pointer_move(View *view, GtkEventController *controller, double x, double y, const char *button)
{
    gint64 row, column;
    view->has_pointer = TRUE;
    view->pointer_x = x;
    view->pointer_y = y;
    tui_pointer_cell(x, y, H.cell_width, H.cell_height, &row, &column);
    if (view->has_pointer_cell && view->pointer_row == row && view->pointer_column == column) {
        return;
    }
    send_pointer(view, "move", x, y, button, gtk_event_controller_get_current_event_state(controller), FALSE, 0, 0);
}

static const char *canvas_button(GtkGestureDrag *gesture)
{
    switch (gtk_gesture_single_get_current_button(GTK_GESTURE_SINGLE(gesture))) {
    case GDK_BUTTON_PRIMARY:
        return "left";
    case GDK_BUTTON_MIDDLE:
        return "middle";
    case GDK_BUTTON_SECONDARY:
        return "right";
    default:
        return NULL;
    }
}

/* A press: the canvas focused first, so that its event focus goes before the press. From the press to the release
 * the drag is the canvas's (GTK's implicit grab), wherever the pointer goes. */
static void canvas_drag_begin(GtkGestureDrag *gesture, double x, double y, gpointer data)
{
    View *view = data;
    GtkWidget *widget = gtk_event_controller_get_widget(GTK_EVENT_CONTROLLER(gesture));
    const char *button = canvas_button(gesture);
    /* The left button focuses it, as before; its event focus goes first */
    if (GDK_BUTTON_PRIMARY == gtk_gesture_single_get_current_button(GTK_GESTURE_SINGLE(gesture)) && gtk_widget_get_focusable(widget)) {
        gtk_widget_grab_focus(widget);
    }
    if (NULL == button || NULL != view->pointer_button) {
        return;
    }
    view->leave_held = FALSE;
    gtk_gesture_set_state(GTK_GESTURE(gesture), GTK_EVENT_SEQUENCE_CLAIMED);
    view->pointer_button = button;
    view->has_pointer = TRUE;
    view->pointer_x = view->drag_x = x;
    view->pointer_y = view->drag_y = y;
    send_pointer(view, "press", x, y, button, gtk_event_controller_get_current_event_state(GTK_EVENT_CONTROLLER(gesture)), FALSE, 0, 0);
}

static void canvas_drag_update(GtkGestureDrag *gesture, double dx, double dy, gpointer data)
{
    View *view = data;
    if (NULL != view->pointer_button) {
        canvas_pointer_move(view, GTK_EVENT_CONTROLLER(gesture), view->drag_x + dx, view->drag_y + dy, view->pointer_button);
    }
}

static void canvas_release(View *view, GtkEventController *controller, double x, double y)
{
    const char *button = view->pointer_button;
    if (NULL == button) {
        return;
    }
    GtkWidget *widget = gtk_event_controller_get_widget(controller);
    GdkModifierType state = gtk_event_controller_get_current_event_state(controller);
    view->pointer_button = NULL;
    view->pointer_x = x;
    view->pointer_y = y;
    send_pointer(view, "release", x, y, button, state, FALSE, 0, 0);
    /* Released away from the drawing (past its edges, or where GTK told of a leave the drag held back): it left */
    if (view->leave_held || x < 0 || y < 0 || x >= gtk_widget_get_width(widget) || y >= gtk_widget_get_height(widget)) {
        view->leave_held = FALSE;
        view->has_pointer = FALSE;
        send_pointer(view, "leave", x, y, NULL, state, FALSE, 0, 0);
        view->has_pointer_cell = FALSE;
    }
}

static void canvas_drag_end(GtkGestureDrag *gesture, double dx, double dy, gpointer data)
{
    View *view = data;
    canvas_release(view, GTK_EVENT_CONTROLLER(gesture), view->drag_x + dx, view->drag_y + dy);
}

/* GTK gave up the drag: a motion with no button held (it takes it for a lost release, as one from xdotool can be),
 * or a grab. The button is the application's no more: released where the pointer was last. */
static void canvas_drag_cancel(GtkGesture *gesture, GdkEventSequence *sequence, gpointer data)
{
    View *view = data;
    (void) sequence;
    canvas_release(view, GTK_EVENT_CONTROLLER(gesture), view->pointer_x, view->pointer_y);
}

static void canvas_motion_enter(GtkEventControllerMotion *controller, double x, double y, gpointer data)
{
    View *view = data;
    (void) controller;
    view->leave_held = FALSE;
    view->has_pointer = TRUE;
    view->pointer_x = x;
    view->pointer_y = y;
}

/* A move with no button held, only while the window is the active one (a drag's moves are canvas_drag_update's) */
static void canvas_motion(GtkEventControllerMotion *controller, double x, double y, gpointer data)
{
    View *view = data;
    GtkWidget *widget = gtk_event_controller_get_widget(GTK_EVENT_CONTROLLER(controller));
    GtkRoot *root = gtk_widget_get_root(widget);
    if (NULL != view->pointer_button) {
        return;
    }
    if (!GTK_IS_WINDOW(root) || !gtk_window_is_active(GTK_WINDOW(root))) {
        view->has_pointer = TRUE;
        view->pointer_x = x;
        view->pointer_y = y;
        return;
    }
    canvas_pointer_move(view, GTK_EVENT_CONTROLLER(controller), x, y, NULL);
}

/* Out of the drawing (also onto a control placed over it) with no button held: at the cell last sent */
static void canvas_motion_leave(GtkEventControllerMotion *controller, gpointer data)
{
    View *view = data;
    if (NULL != view->pointer_button) {
        view->leave_held = TRUE;
        return;
    }
    view->has_pointer = FALSE;
    if (!view->has_pointer_cell) {
        return;
    }
    send_pointer(view, "leave", (view->pointer_column + 0.5) * H.cell_width, (view->pointer_row + 0.5) * H.cell_height, NULL,
        gtk_event_controller_get_current_event_state(GTK_EVENT_CONTROLLER(controller)), FALSE, 0, 0);
    view->has_pointer_cell = FALSE;
}

/* A new gesture of a touchpad: what was left of the last one is dropped */
static void canvas_scroll_begin(GtkEventControllerScroll *controller, gpointer data)
{
    View *view = data;
    (void) controller;
    view->wheel_rows = 0;
    view->wheel_columns = 0;
}

/* The wheel where the mouse is: a notch is a row (or a column); pixels (a touchpad) are added up per cell, and sent once
 * they make whole cells, the rest kept. GDK's deltas are positive down and right, natural scrolling applied. */
static gboolean canvas_scroll(GtkEventControllerScroll *controller, double dx, double dy, gpointer data)
{
    View *view = data;
    gboolean notches = GDK_SCROLL_UNIT_WHEEL == gtk_event_controller_scroll_get_unit(controller);
    gint64 rows, columns;
    if (!tui_wheel_add(&view->wheel_rows, &view->wheel_columns, dx, dy, notches, H.cell_width, H.cell_height, &rows, &columns)) {
        return TRUE;
    }
    double x = view->pointer_x, y = view->pointer_y;
    if (!view->has_pointer) {
        /* No enter nor motion seen (the window mapped under a still pointer): where the device is */
        GtkWidget *widget = gtk_event_controller_get_widget(GTK_EVENT_CONTROLLER(controller));
        GtkNative *native = gtk_widget_get_native(widget);
        GdkEvent *event = gtk_event_controller_get_current_event(GTK_EVENT_CONTROLLER(controller));
        GdkDevice *device = NULL != event ? gdk_event_get_device(event) : NULL;
        double sx, sy, nx, ny;
        graphene_point_t at, in_area;
        if (NULL == native || NULL == device || !gdk_surface_get_device_position(gtk_native_get_surface(native), device, &sx, &sy, NULL)) {
            return TRUE;
        }
        gtk_native_get_surface_transform(native, &nx, &ny);
        at = GRAPHENE_POINT_INIT((float) (sx - nx), (float) (sy - ny));
        if (!gtk_widget_compute_point(GTK_WIDGET(native), widget, &at, &in_area)) {
            return TRUE;
        }
        x = in_area.x;
        y = in_area.y;
    }
    send_pointer(view, "wheel", x, y, NULL, gtk_event_controller_get_current_event_state(GTK_EVENT_CONTROLLER(controller)), TRUE, rows, columns);
    return TRUE;
}

/* The cells of the controls shown over a canvas, [row, column, columns, rows] (their clip, else their place), where
 * it draws no characters: a glyph like ─ or → reaches past its cell, into the one before the control, where the
 * control's opaque box does not hide it (dots at the left edge of the control). */
static GArray *canvas_covered_cells(View *view, TuiNode *node)
{
    GArray *boxes = g_array_new(FALSE, FALSE, sizeof(gint64[4]));
    for (guint i = 0; NULL != node && i < node->children->len; i++) {
        TuiNode *child_node = node->children->pdata[i];
        View *child = view_get(child_node->id);
        JsonArray *box = tui_prop_array(child_node->props, "clip");
        box = NULL != box ? box : tui_prop_array(child_node->props, "place");
        if (NULL == child || NULL == child->placer || gtk_widget_get_parent(child->placer) != view->content || !gtk_widget_get_visible(child->placer)
            || NULL == box || 4 != json_array_get_length(box)) {
            continue;
        }
        gint64 cells[4];
        for (guint j = 0; j < 4; j++) {
            cells[j] = json_array_get_int_element(box, j);
        }
        g_array_append_val(boxes, cells);
    }
    return boxes;
}

static gboolean canvas_covered(GArray *boxes, gint64 row, gint64 column)
{
    for (guint i = 0; i < boxes->len; i++) {
        const gint64 *box = (const gint64 *) (void *) boxes->data + 4 * i;
        if (row >= box[0] && row < box[0] + box[3] && column >= box[1] && column < box[1] + box[2]) {
            return TRUE;
        }
    }
    return FALSE;
}

static void canvas_draw(GtkDrawingArea *area, cairo_t *cr, int width, int height, gpointer data)
{
    View *view = data;
    TuiNode *node = node_of(view);
    JsonArray *lines = NULL != node ? tui_prop_array(node->props, "lines") : NULL;
    JsonArray *cursor = NULL != node ? tui_prop_array(node->props, "cursor") : NULL;
    GdkRGBA fg = {0, 0, 0, 1};
    char fg_hex[8];
    char bg_hex[8];
    g_autoptr(PangoLayout) layout = gtk_widget_create_pango_layout(GTK_WIDGET(area), NULL);

    (void) width;
    (void) height;
#if GTK_CHECK_VERSION(4, 10, 0)
    gtk_widget_get_color(GTK_WIDGET(area), &fg);
#endif
    color_hex(GTK_WIDGET(area), fg_hex);
    g_strlcpy(bg_hex, H.dark ? "#1e1e1e" : "#ffffff", sizeof bg_hex);
    pango_layout_set_font_description(layout, H.font);
    g_autoptr(GArray) covered = canvas_covered_cells(view, node);
    for (guint row = 0; NULL != lines && row < json_array_get_length(lines); row++) {
        JsonNode *line_node = json_array_get_element(lines, row);
        JsonArray *runs = JSON_NODE_ARRAY == json_node_get_node_type(line_node) ? json_node_get_array(line_node) : NULL;
        int column = 0;
        for (guint i = 0; NULL != runs && i < json_array_get_length(runs); i++) {
            JsonObject *run = json_array_get_object_element(runs, i);
            const char *text = tui_prop_string(run, "t", "");
            const char *run_fg = tui_prop_string(run, "fg", NULL);
            const char *run_bg = tui_prop_string(run, "bg", NULL);
            double rgb[3];
            int cells = 0;

            if (tui_prop_bool(run, "r", FALSE)) {
                const char *swap = run_fg;
                run_fg = NULL != run_bg ? run_bg : bg_hex;
                run_bg = NULL != swap ? swap : fg_hex;
            }
            for (const char *c = text; '\0' != *c; c = g_utf8_next_char(c)) {
                cells += g_unichar_iswide(g_utf8_get_char(c)) ? 2 : 1;
            }
            double x = column * H.cell_width;
            double y = row * H.cell_height;
            if (tui_color_parse(run_bg, rgb)) {
                cairo_set_source_rgb(cr, rgb[0], rgb[1], rgb[2]);
                cairo_rectangle(cr, x, y, cells * H.cell_width, H.cell_height);
                cairo_fill(cr);
            }
            /* Cell by cell, so wide characters and the font's advance never drift off the grid. */
            PangoAttrList *attributes = pango_attr_list_new();
            if (tui_prop_bool(run, "b", FALSE)) {
                pango_attr_list_insert(attributes, pango_attr_weight_new(PANGO_WEIGHT_BOLD));
            }
            if (tui_prop_bool(run, "i", FALSE)) {
                pango_attr_list_insert(attributes, pango_attr_style_new(PANGO_STYLE_ITALIC));
            }
            if (tui_prop_bool(run, "u", FALSE)) {
                pango_attr_list_insert(attributes, pango_attr_underline_new(PANGO_UNDERLINE_SINGLE));
            }
            if (tui_prop_bool(run, "s", FALSE)) {
                pango_attr_list_insert(attributes, pango_attr_strikethrough_new(TRUE));
            }
            pango_layout_set_attributes(layout, attributes);
            pango_attr_list_unref(attributes);
            double alpha = tui_prop_bool(run, "d", FALSE) ? 0.6 : 1.0;
            if (tui_color_parse(run_fg, rgb)) {
                cairo_set_source_rgba(cr, rgb[0], rgb[1], rgb[2], alpha);
            } else {
                cairo_set_source_rgba(cr, fg.red, fg.green, fg.blue, fg.alpha * alpha);
            }
            int at = column;
            for (const char *c = text; '\0' != *c; c = g_utf8_next_char(c)) {
                gunichar character = g_utf8_get_char(c);
                if (canvas_covered(covered, (gint64) row, at)) {
                    at += g_unichar_iswide(character) ? 2 : 1;
                    continue;
                }
                pango_layout_set_text(layout, c, (int) (g_utf8_next_char(c) - c));
                cairo_move_to(cr, at * H.cell_width, y);
                pango_cairo_show_layout(cr, layout);
                at += g_unichar_iswide(character) ? 2 : 1;
            }
            column += cells;
        }
    }
    if (NULL != cursor && 2 == json_array_get_length(cursor)) {
        double y = json_array_get_int_element(cursor, 0) * H.cell_height;
        double x = json_array_get_int_element(cursor, 1) * H.cell_width;
        cairo_set_source_rgba(cr, fg.red, fg.green, fg.blue, 0.5);
        if (gtk_widget_has_focus(GTK_WIDGET(area))) {
            cairo_rectangle(cr, x, y, H.cell_width, H.cell_height);
            cairo_fill(cr);
        } else {
            cairo_rectangle(cr, x + 0.5, y + 0.5, H.cell_width - 1, H.cell_height - 1);
            cairo_set_line_width(cr, 1);
            cairo_stroke(cr);
        }
    }
    /* What the input method composes: drawn here only, underlined, from the cursor cell. */
    if (NULL != view->preedit) {
        gboolean has = NULL != cursor && 2 == json_array_get_length(cursor);
        double y = has ? json_array_get_int_element(cursor, 0) * H.cell_height : 0;
        double x = has ? json_array_get_int_element(cursor, 1) * H.cell_width : 0;
        int cells = 0;
        double rgb[3];
        for (const char *c = view->preedit; '\0' != *c; c = g_utf8_next_char(c)) {
            cells += g_unichar_iswide(g_utf8_get_char(c)) ? 2 : 1;
        }
        if (tui_color_parse(bg_hex, rgb)) {
            cairo_set_source_rgb(cr, rgb[0], rgb[1], rgb[2]);
            cairo_rectangle(cr, x, y, cells * H.cell_width, H.cell_height);
            cairo_fill(cr);
        }
        PangoAttrList *attributes = pango_attr_list_new();
        pango_attr_list_insert(attributes, pango_attr_underline_new(PANGO_UNDERLINE_SINGLE));
        pango_layout_set_attributes(layout, attributes);
        pango_attr_list_unref(attributes);
        pango_layout_set_text(layout, view->preedit, -1);
        cairo_set_source_rgba(cr, fg.red, fg.green, fg.blue, fg.alpha);
        cairo_move_to(cr, x, y);
        pango_cairo_show_layout(cr, layout);
    }
}

static void canvas_focus_in(GtkEventControllerFocus *controller, gpointer data)
{
    View *view = data;
    gtk_widget_queue_draw(gtk_event_controller_get_widget(GTK_EVENT_CONTROLLER(controller)));
    /* The key controller that holds the input method focuses it */
    canvas_im_location(view);
}

static void canvas_focus_out(GtkEventControllerFocus *controller, gpointer data)
{
    (void) data;
    gtk_widget_queue_draw(gtk_event_controller_get_widget(GTK_EVENT_CONTROLLER(controller)));
}

/* ---- building ---- */

static View *create_view(TuiNode *node)
{
    View *view = g_new0(View, 1);
    const char *kind = node->kind;
    gpointer id = GSIZE_TO_POINTER(node->id);

    view->id = node->id;
    view->kind = g_strdup(kind);
    if (0 == strcmp(kind, "stack")) {
        view->outer = view->control = view->content = stack_box();
    } else if (0 == strcmp(kind, "label")) {
        GtkWidget *label = gtk_label_new(NULL);
        gtk_label_set_wrap(GTK_LABEL(label), TRUE);
        gtk_label_set_wrap_mode(GTK_LABEL(label), PANGO_WRAP_WORD_CHAR);
        gtk_label_set_selectable(GTK_LABEL(label), TRUE);
        gtk_label_set_xalign(GTK_LABEL(label), 0);
        view->outer = view->control = label;
    } else if (0 == strcmp(kind, "textfield")) {
        GtkWidget *box = gtk_box_new(GTK_ORIENTATION_HORIZONTAL, 6);
        GtkWidget *prompt = gtk_label_new(NULL);
        GtkWidget *entry = gtk_entry_new();
        GtkEventController *keys = gtk_event_controller_key_new();
        gtk_widget_set_hexpand(entry, TRUE);
        gtk_box_append(GTK_BOX(box), prompt);
        gtk_box_append(GTK_BOX(box), entry);
        g_signal_connect(entry, "changed", G_CALLBACK(textfield_changed), NULL);
        g_signal_connect(entry, "activate", G_CALLBACK(textfield_activate), NULL);
        g_signal_connect(gtk_editable_get_delegate(GTK_EDITABLE(entry)), "delete-text", G_CALLBACK(textfield_delete), id);
        g_signal_connect(gtk_editable_get_delegate(GTK_EDITABLE(entry)), "insert-text", G_CALLBACK(textfield_insert), id);
        g_signal_connect(gtk_editable_get_delegate(GTK_EDITABLE(entry)), "preedit-changed", G_CALLBACK(text_preedit_changed), id);
        g_signal_connect(keys, "key-pressed", G_CALLBACK(textfield_key), id);
        gtk_widget_add_controller(entry, keys);
        gtk_accessible_update_relation(GTK_ACCESSIBLE(entry), GTK_ACCESSIBLE_RELATION_LABELLED_BY, prompt, NULL, -1);
        view->outer = box;
        view->control = entry;
        view->part[0] = prompt;
    } else if (0 == strcmp(kind, "textarea")) {
        GtkWidget *scrolled = gtk_scrolled_window_new();
        GtkWidget *text = gtk_text_view_new();
        GtkEventController *keys = gtk_event_controller_key_new();
        gtk_text_view_set_wrap_mode(GTK_TEXT_VIEW(text), GTK_WRAP_WORD_CHAR);
        gtk_text_view_set_left_margin(GTK_TEXT_VIEW(text), 4);
        gtk_text_view_set_right_margin(GTK_TEXT_VIEW(text), 4);
        gtk_scrolled_window_set_child(GTK_SCROLLED_WINDOW(scrolled), text);
        gtk_scrolled_window_set_policy(GTK_SCROLLED_WINDOW(scrolled), GTK_POLICY_NEVER, GTK_POLICY_AUTOMATIC);
        gtk_scrolled_window_set_has_frame(GTK_SCROLLED_WINDOW(scrolled), TRUE);
        gtk_scrolled_window_set_propagate_natural_height(GTK_SCROLLED_WINDOW(scrolled), TRUE);
        g_signal_connect(gtk_text_view_get_buffer(GTK_TEXT_VIEW(text)), "changed", G_CALLBACK(textarea_changed), id);
        g_signal_connect(gtk_text_view_get_buffer(GTK_TEXT_VIEW(text)), "delete-range", G_CALLBACK(textarea_delete), id);
        g_signal_connect(gtk_text_view_get_buffer(GTK_TEXT_VIEW(text)), "insert-text", G_CALLBACK(textarea_insert), id);
        g_signal_connect(text, "preedit-changed", G_CALLBACK(text_preedit_changed), id);
        gtk_event_controller_set_propagation_phase(keys, GTK_PHASE_CAPTURE);
        g_signal_connect(keys, "key-pressed", G_CALLBACK(textarea_key), id);
        gtk_widget_add_controller(text, keys);
        view->outer = scrolled;
        view->control = text;
    } else if (0 == strcmp(kind, "list")) {
        GtkWidget *frame = gtk_frame_new(NULL);
        GtkWidget *list = gtk_list_box_new();
        gtk_list_box_set_activate_on_single_click(GTK_LIST_BOX(list), FALSE);
        gtk_list_box_set_selection_mode(GTK_LIST_BOX(list), GTK_SELECTION_SINGLE);
        gtk_widget_add_css_class(list, "tui-list");
        gtk_frame_set_child(GTK_FRAME(frame), list);
        g_signal_connect(list, "row-activated", G_CALLBACK(list_activated), view);
        view->outer = frame;
        view->control = list;
    } else if (0 == strcmp(kind, "form")) {
        GtkWidget *form = gtk_grid_new();
        gtk_grid_set_column_spacing(GTK_GRID(form), 12);
        gtk_grid_set_row_spacing(GTK_GRID(form), 6);
        view->outer = view->control = form;
    } else if (0 == strcmp(kind, "tabs")) {
        GtkWidget *notebook = gtk_notebook_new();
        g_signal_connect(notebook, "switch-page", G_CALLBACK(tabs_switched), id);
        view->outer = view->control = view->content = notebook;
    } else if (0 == strcmp(kind, "disclosure")) {
        GtkWidget *expander = gtk_expander_new(NULL);
        GtkWidget *title = gtk_box_new(GTK_ORIENTATION_VERTICAL, 0);
        GtkWidget *summary = gtk_label_new(NULL);
        GtkWidget *description = secondary_label();
        gtk_label_set_xalign(GTK_LABEL(summary), 0);
        gtk_box_append(GTK_BOX(title), summary);
        gtk_box_append(GTK_BOX(title), description);
        gtk_expander_set_label_widget(GTK_EXPANDER(expander), title);
        view->content = stack_box();
        gtk_widget_set_margin_start(view->content, 18);
        gtk_expander_set_child(GTK_EXPANDER(expander), view->content);
        g_signal_connect(expander, "notify::expanded", G_CALLBACK(disclosure_toggled), id);
        view->outer = view->control = expander;
        view->part[0] = summary;
        view->part[1] = description;
    } else if (0 == strcmp(kind, "progress")) {
        GtkWidget *bar = gtk_progress_bar_new();
        view->outer = view->control = bar;
    } else if (0 == strcmp(kind, "spinner")) {
        GtkWidget *box = gtk_box_new(GTK_ORIENTATION_HORIZONTAL, 8);
        GtkWidget *spinner = gtk_spinner_new();
        GtkWidget *label = gtk_label_new(NULL);
        GtkWidget *cancel = gtk_button_new_with_label("Cancel");
        gtk_label_set_xalign(GTK_LABEL(label), 0);
        gtk_widget_set_hexpand(label, TRUE);
        gtk_box_append(GTK_BOX(box), spinner);
        gtk_box_append(GTK_BOX(box), label);
        gtk_box_append(GTK_BOX(box), cancel);
        g_signal_connect(cancel, "clicked", G_CALLBACK(spinner_cancel), id);
        view->outer = box;
        view->control = spinner;
        view->part[0] = label;
        view->part[1] = cancel;
    } else if (0 == strcmp(kind, "canvas")) {
        /* The drawing, and the native controls placed over it (canvas-controls). */
        GtkWidget *overlay = gtk_overlay_new();
        GtkWidget *area = gtk_drawing_area_new();
        GtkEventController *keys = gtk_event_controller_key_new();
        GtkEventController *taken = gtk_event_controller_key_new();
        GtkEventController *focus = gtk_event_controller_focus_new();
        GtkGesture *drag = gtk_gesture_drag_new();
        GtkEventController *motion = gtk_event_controller_motion_new();
        GtkEventController *scroll = gtk_event_controller_scroll_new(GTK_EVENT_CONTROLLER_SCROLL_BOTH_AXES);
        gtk_overlay_set_child(GTK_OVERLAY(overlay), area);
        gtk_drawing_area_set_draw_func(GTK_DRAWING_AREA(area), canvas_draw, view, NULL);
        /* Input methods (dead keys, Ctrl+Shift+U, CJK, IBus emoji): what they commit is typed, the keys they do not use go out. */
        view->im = gtk_im_multicontext_new();
        gtk_im_context_set_client_widget(view->im, area);
        g_signal_connect(view->im, "commit", G_CALLBACK(canvas_commit), view);
        g_signal_connect(view->im, "preedit-changed", G_CALLBACK(canvas_preedit_changed), view);
        g_signal_connect(view->im, "preedit-end", G_CALLBACK(canvas_preedit_changed), view);
        g_signal_connect(view->im, "retrieve-surrounding", G_CALLBACK(canvas_retrieve_surrounding), NULL);
        gtk_event_controller_key_set_im_context(GTK_EVENT_CONTROLLER_KEY(keys), view->im);
        g_signal_connect(keys, "key-pressed", G_CALLBACK(canvas_key), id);
        gtk_widget_add_controller(area, keys);
        gtk_event_controller_set_propagation_phase(taken, GTK_PHASE_CAPTURE);
        g_signal_connect(taken, "key-pressed", G_CALLBACK(canvas_capture_key), view);
        gtk_widget_add_controller(overlay, taken);
        g_signal_connect(focus, "enter", G_CALLBACK(canvas_focus_in), view);
        g_signal_connect(focus, "leave", G_CALLBACK(canvas_focus_out), view);
        gtk_widget_add_controller(area, focus);
        /* The mouse (pointer): on the drawing, not on the controls placed over it, which handle it themselves */
        gtk_gesture_single_set_button(GTK_GESTURE_SINGLE(drag), 0);
        g_signal_connect(drag, "drag-begin", G_CALLBACK(canvas_drag_begin), view);
        g_signal_connect(drag, "drag-update", G_CALLBACK(canvas_drag_update), view);
        g_signal_connect(drag, "drag-end", G_CALLBACK(canvas_drag_end), view);
        g_signal_connect(drag, "cancel", G_CALLBACK(canvas_drag_cancel), view);
        gtk_widget_add_controller(area, GTK_EVENT_CONTROLLER(drag));
        g_signal_connect(motion, "enter", G_CALLBACK(canvas_motion_enter), view);
        g_signal_connect(motion, "motion", G_CALLBACK(canvas_motion), view);
        g_signal_connect(motion, "leave", G_CALLBACK(canvas_motion_leave), view);
        gtk_widget_add_controller(area, motion);
        g_signal_connect(scroll, "scroll-begin", G_CALLBACK(canvas_scroll_begin), view);
        g_signal_connect(scroll, "scroll", G_CALLBACK(canvas_scroll), view);
        gtk_widget_add_controller(area, scroll);
        gtk_widget_add_css_class(area, "tui-canvas");
        watch_focus(view, area);
        view->outer = g_object_new(TUI_TYPE_CANVAS_BOX, NULL);
        gtk_widget_set_parent(overlay, view->outer);
        view->content = overlay;
        view->control = area;
    } else if (0 == strcmp(kind, "button")) {
        GtkWidget *button = gtk_button_new();
        g_signal_connect(button, "clicked", G_CALLBACK(button_clicked), id);
        view->outer = view->control = button;
    } else if (0 == strcmp(kind, "checkbox")) {
        GtkWidget *check = gtk_check_button_new();
        g_signal_connect(check, "toggled", G_CALLBACK(checkbox_toggled), id);
        view->outer = view->control = check;
    } else if (0 == strcmp(kind, "popup")) {
        GtkWidget *dropdown = gtk_drop_down_new_from_strings((const char *const[]) {NULL});
        g_signal_connect(dropdown, "notify::selected", G_CALLBACK(popup_selected), id);
        view->outer = view->control = dropdown;
    } else if (0 == strcmp(kind, "password")) {
        /* Like a text field, in a secure entry: bullets, nothing of it on the clipboard; its value is never logged */
        GtkWidget *entry = gtk_password_entry_new();
        GtkEventController *keys = gtk_event_controller_key_new();
        g_signal_connect(entry, "changed", G_CALLBACK(textfield_changed), NULL);
        g_signal_connect(entry, "activate", G_CALLBACK(entry_activate), NULL);
        g_signal_connect(gtk_editable_get_delegate(GTK_EDITABLE(entry)), "delete-text", G_CALLBACK(textfield_delete), id);
        g_signal_connect(gtk_editable_get_delegate(GTK_EDITABLE(entry)), "insert-text", G_CALLBACK(textfield_insert), id);
        g_signal_connect(gtk_editable_get_delegate(GTK_EDITABLE(entry)), "preedit-changed", G_CALLBACK(text_preedit_changed), id);
        g_signal_connect(keys, "key-pressed", G_CALLBACK(textfield_key), id);
        gtk_widget_add_controller(entry, keys);
        gtk_widget_set_hexpand(entry, TRUE);
        view->outer = view->control = entry;
    } else if (0 == strcmp(kind, "number")) {
        /* A spin button: Up and Down, and its buttons, step it (snapped to the step from min); what is typed is sent as
         * it is, and kept when it is not a number */
        GtkWidget *spin = gtk_spin_button_new(gtk_adjustment_new(0, -1e12, 1e12, 1, 10, 0), 1, 0);
        GtkEventController *keys = gtk_event_controller_key_new();
        GtkGesture *press = gtk_gesture_click_new();
        gtk_spin_button_set_numeric(GTK_SPIN_BUTTON(spin), FALSE);
        gtk_spin_button_set_update_policy(GTK_SPIN_BUTTON(spin), GTK_UPDATE_IF_VALID);
        gtk_spin_button_set_snap_to_ticks(GTK_SPIN_BUTTON(spin), TRUE);
        g_signal_connect(spin, "input", G_CALLBACK(number_input), id);
        g_signal_connect(spin, "output", G_CALLBACK(number_output), id);
        g_signal_connect(spin, "changed", G_CALLBACK(textfield_changed), NULL);
        g_signal_connect(spin, "change-value", G_CALLBACK(number_change_value), NULL);
        gtk_gesture_single_set_button(GTK_GESTURE_SINGLE(press), 0);
        gtk_event_controller_set_propagation_phase(GTK_EVENT_CONTROLLER(press), GTK_PHASE_CAPTURE);
        g_signal_connect(press, "pressed", G_CALLBACK(number_pressed), NULL);
        g_signal_connect(press, "end", G_CALLBACK(number_released), NULL);
        g_signal_connect(press, "cancel", G_CALLBACK(number_released), NULL);
        gtk_widget_add_controller(spin, GTK_EVENT_CONTROLLER(press));
        /* Enter on its text, which GtkSpinButton handles (it updates the value) without a signal of its own before GTK 4.14 */
        g_signal_connect_swapped(gtk_editable_get_delegate(GTK_EDITABLE(spin)), "activate", G_CALLBACK(spin_activate), spin);
        g_signal_connect(gtk_editable_get_delegate(GTK_EDITABLE(spin)), "preedit-changed", G_CALLBACK(text_preedit_changed), id);
        g_signal_connect(gtk_editable_get_delegate(GTK_EDITABLE(spin)), "insert-text", G_CALLBACK(number_insert), id);
        g_signal_connect(gtk_editable_get_delegate(GTK_EDITABLE(spin)), "delete-text", G_CALLBACK(number_delete), id);
        g_signal_connect(keys, "key-pressed", G_CALLBACK(textfield_key), id);
        gtk_widget_add_controller(spin, keys);
        view->outer = view->control = spin;
    } else if (0 == strcmp(kind, "slider")) {
        GtkWidget *scale = gtk_scale_new(GTK_ORIENTATION_HORIZONTAL, gtk_adjustment_new(50, 0, 100, 1, 10, 0));
        gtk_scale_set_draw_value(GTK_SCALE(scale), FALSE);
        gtk_widget_set_hexpand(scale, TRUE);
        g_signal_connect(scale, "value-changed", G_CALLBACK(slider_moved), id);
        view->sent = NAN;
        view->outer = view->control = scale;
    } else if (0 == strcmp(kind, "listbox")) {
        GtkWidget *scrolled = gtk_scrolled_window_new();
        GtkWidget *box = gtk_list_box_new();
        gtk_list_box_set_activate_on_single_click(GTK_LIST_BOX(box), FALSE);
        gtk_scrolled_window_set_child(GTK_SCROLLED_WINDOW(scrolled), box);
        gtk_scrolled_window_set_policy(GTK_SCROLLED_WINDOW(scrolled), GTK_POLICY_NEVER, GTK_POLICY_AUTOMATIC);
        g_signal_connect(box, "selected-rows-changed", G_CALLBACK(listbox_selected), id);
        view->outer = scrolled;
        view->control = box;
    } else {
        /* Unknown kinds draw as an empty box; their children still go in it. */
        view->outer = view->control = view->content = stack_box();
        gtk_widget_add_css_class(view->outer, "tui-unknown");
    }
    g_object_ref_sink(view->outer);
    g_object_set_data(G_OBJECT(view->control), "tui-id", id);
    g_object_set_data(G_OBJECT(view->outer), "tui-id", id);
    g_autofree char *node_class = g_strdup_printf("tui-n%" G_GINT64_FORMAT, node->id);
    g_autofree char *kind_class = g_strdup_printf("tui-%s", kind);
    gtk_widget_add_css_class(view->outer, node_class);
    gtk_widget_add_css_class(view->outer, kind_class);
    if (0 != strcmp(kind, "stack") && 0 != strcmp(kind, "tabs") && 0 != strcmp(kind, "disclosure") && NULL == view->content) {
        watch_focus(view, view->outer);
    }
    g_hash_table_insert(H.views, &view->id, view);
    return view;
}

static int textarea_line_height(GtkWidget *text)
{
    PangoContext *context = gtk_widget_get_pango_context(text);
    PangoFontMetrics *metrics = pango_context_get_metrics(context, NULL, NULL);
    int height = MAX(1, PANGO_PIXELS(pango_font_metrics_get_height(metrics)));
    pango_font_metrics_unref(metrics);
    return height;
}

/* The placer of a control over a canvas: exactly as big as its size request (the clip), the control at its
 * offset (the place), as big as the place or, when it cannot be that small, centered on it and cut. */
static void placer_measure(GtkWidget *widget, GtkOrientation orientation, int for_size, int *minimum, int *natural, int *minimum_baseline,
    int *natural_baseline)
{
    int width;
    int height;
    (void) for_size;
    gtk_widget_get_size_request(widget, &width, &height);
    *minimum = *natural = MAX(0, GTK_ORIENTATION_HORIZONTAL == orientation ? width : height);
    *minimum_baseline = *natural_baseline = -1;
}

static void placer_allocate(GtkWidget *widget, int width, int height, int baseline)
{
    GtkWidget *child = gtk_widget_get_first_child(widget);
    (void) width;
    (void) height;
    (void) baseline;
    if (NULL == child || !gtk_widget_should_layout(child)) {
        return;
    }
    int place_width;
    int place_height;
    int minimum_width;
    int minimum_height;
    gtk_widget_get_size_request(child, &place_width, &place_height);
    gtk_widget_measure(child, GTK_ORIENTATION_HORIZONTAL, -1, &minimum_width, NULL, NULL, NULL);
    gtk_widget_measure(child, GTK_ORIENTATION_VERTICAL, MAX(place_width, minimum_width), &minimum_height, NULL, NULL, NULL);
    int child_width = MAX(place_width, minimum_width);
    int child_height = MAX(place_height, minimum_height);
    float x = (float) GPOINTER_TO_INT(g_object_get_data(G_OBJECT(widget), "tui-dx")) - (float) (child_width - place_width) / 2;
    float y = (float) GPOINTER_TO_INT(g_object_get_data(G_OBJECT(widget), "tui-dy")) - (float) (child_height - place_height) / 2;
    gtk_widget_allocate(child, child_width, child_height, -1, gsk_transform_translate(NULL, &GRAPHENE_POINT_INIT(x, y)));
}

static GtkWidget *placer_new(void)
{
    GtkWidget *placer = gtk_box_new(GTK_ORIENTATION_HORIZONTAL, 0);
    gtk_widget_set_layout_manager(placer, gtk_custom_layout_new(NULL, placer_measure, placer_allocate));
    gtk_widget_set_overflow(placer, GTK_OVERFLOW_HIDDEN);
    gtk_widget_add_css_class(placer, "tui-placer");
    gtk_widget_set_halign(placer, GTK_ALIGN_START);
    gtk_widget_set_valign(placer, GTK_ALIGN_START);
    return placer;
}

/* A control over a canvas: at `place`, cut to `clip`, in cells of the canvas. */
static void place_child(View *view, JsonObject *props)
{
    JsonArray *place = tui_prop_array(props, "place");
    JsonArray *clip = tui_prop_array(props, "clip");
    gint64 p[4] = {0, 0, 0, 0};
    gint64 c[4];

    if (NULL == place || 4 != json_array_get_length(place)) {
        gtk_widget_set_visible(view->placer, FALSE);
        return;
    }
    for (guint i = 0; i < 4; i++) {
        p[i] = json_array_get_int_element(place, i);
        c[i] = NULL != clip && 4 == json_array_get_length(clip) ? json_array_get_int_element(clip, i) : p[i];
    }
    /* Cells to pixels on the grid of the canvas: the edges of cells, so that neighbours meet. */
    int x = (int) round(p[1] * H.cell_width);
    int y = (int) round(p[0] * H.cell_height);
    int width = (int) round((p[1] + p[2]) * H.cell_width) - x;
    int height = (int) round((p[0] + p[3]) * H.cell_height) - y;
    int cx = (int) round(c[1] * H.cell_width);
    int cy = (int) round(c[0] * H.cell_height);
    int cwidth = (int) round((c[1] + c[2]) * H.cell_width) - cx;
    int cheight = (int) round((c[0] + c[3]) * H.cell_height) - cy;

    gtk_widget_set_margin_start(view->placer, cx);
    gtk_widget_set_margin_top(view->placer, cy);
    gtk_widget_set_size_request(view->placer, MAX(0, cwidth), MAX(0, cheight));
    gtk_widget_set_size_request(view->outer, MAX(0, width), MAX(0, height));
    /* One row high (place_child's own class): see the stylesheet */
    if (1 == p[3]) {
        gtk_widget_add_css_class(view->outer, "tui-one-row");
    } else {
        gtk_widget_remove_css_class(view->outer, "tui-one-row");
    }
    if (0 == g_strcmp0(view->kind, "textarea")) {
        /* Its cells, not minLines (3 lines by default), make its height; it scrolls in them. One row high, with no
         * scrollbar, whose own height (46 px) would take it past its cell: it scrolls as its cursor moves. */
        gtk_scrolled_window_set_min_content_height(GTK_SCROLLED_WINDOW(view->outer), -1);
        gtk_scrolled_window_set_policy(GTK_SCROLLED_WINDOW(view->outer), GTK_POLICY_NEVER, 1 == p[3] ? GTK_POLICY_EXTERNAL : GTK_POLICY_AUTOMATIC);
    }
    if (GTK_IS_SPIN_BUTTON(view->control)) {
        /* Its text as narrow as one character, not as wide as GTK would make it (122 px): with its two buttons it fits
         * its cells, both arrows on them, where a click is the field's and not the canvas's (HOSTS.md) */
        gtk_editable_set_width_chars(GTK_EDITABLE(view->control), 1);
    }
    g_object_set_data(G_OBJECT(view->placer), "tui-dx", GINT_TO_POINTER(x - cx));
    g_object_set_data(G_OBJECT(view->placer), "tui-dy", GINT_TO_POINTER(y - cy));
    gtk_widget_queue_allocate(view->placer);
    gtk_widget_set_visible(view->placer, cwidth > 0 && cheight > 0 && !tui_prop_bool(props, "hidden", FALSE));
    /* Which leaves its cells undrawn, or drawn again (canvas_covered_cells) */
    if (NULL != view->holder) {
        gtk_widget_queue_draw(view->holder->control);
    }
    /* Its own size only: it does not make the canvas grow. */
    gtk_widget_set_hexpand(view->outer, FALSE);
    gtk_widget_set_vexpand(view->outer, FALSE);
}

/* A radio button: one with a group. */
static void checkbox_group(View *view, JsonObject *props)
{
    GtkCheckButton *partner = NULL;
    /* A partner of its own, never shown: GTK draws it as a radio, and never unchecks a sibling by itself. The
     * application says which one of the group is checked. */
    if (NULL != tui_prop_string(props, "group", NULL)) {
        partner = g_object_get_data(G_OBJECT(view->control), "tui-radio-partner");
        if (NULL == partner) {
            partner = GTK_CHECK_BUTTON(g_object_ref_sink(gtk_check_button_new()));
            g_object_set_data_full(G_OBJECT(view->control), "tui-radio-partner", partner, g_object_unref);
        }
    }
    gtk_check_button_set_group(GTK_CHECK_BUTTON(view->control), partner);
}

static void apply_props(View *view)
{
    TuiNode *node = node_of(view);
    JsonObject *props = node->props;
    const char *kind = view->kind;

    H.suppress++;
    gtk_widget_set_visible(view->outer, !tui_prop_bool(props, "hidden", FALSE));
    gtk_widget_set_sensitive(view->outer, !tui_prop_bool(props, "disabled", FALSE));
    gint64 grow = tui_prop_int(props, "grow", 0);
    tui_stack_layout_set_grow(view->outer, (int) grow);
    gtk_widget_set_hexpand(view->outer, grow > 0 || GTK_IS_NOTEBOOK(view->outer) || GTK_IS_FRAME(view->outer));
    gtk_widget_set_vexpand(view->outer, grow > 0);
    if (tui_prop_bool(props, "border", FALSE)) {
        gtk_widget_add_css_class(view->outer, "tui-border");
    } else {
        gtk_widget_remove_css_class(view->outer, "tui-border");
    }
    const char *accessible = tui_prop_string(props, "label", NULL);
    if (NULL != accessible) {
        gtk_accessible_update_property(GTK_ACCESSIBLE(view->control), GTK_ACCESSIBLE_PROPERTY_LABEL, accessible, -1);
    } else {
        gtk_accessible_reset_property(GTK_ACCESSIBLE(view->control), GTK_ACCESSIBLE_PROPERTY_LABEL);
    }
    gboolean focusable = tui_prop_bool(props, "focusable", TRUE);
    gtk_widget_set_focusable(view->control, focusable);
    /* The GtkText inside an entry, a password entry or a spin button is what takes the focus */
    if (GTK_IS_EDITABLE(view->control) && NULL != gtk_editable_get_delegate(GTK_EDITABLE(view->control))) {
        gtk_widget_set_focusable(GTK_WIDGET(gtk_editable_get_delegate(GTK_EDITABLE(view->control))), focusable);
    }

    if (0 == strcmp(kind, "stack") || NULL != view->content) {
        if (GTK_IS_BOX(view->content)) {
            gboolean horizontal = 0 == g_strcmp0(tui_prop_string(props, "direction", "vertical"), "horizontal");
            gint64 gap = tui_prop_int(props, "gap", 0);
            tui_stack_layout_configure(TUI_STACK_LAYOUT(gtk_widget_get_layout_manager(view->content)),
                horizontal ? GTK_ORIENTATION_HORIZONTAL : GTK_ORIENTATION_VERTICAL, (int) round(gap * (horizontal ? H.cell_width : H.cell_height)));
        }
    }
    if (0 == strcmp(kind, "label")) {
        JsonArray *runs = tui_prop_array(props, "runs");
        const char *align = tui_prop_string(props, "align", "left");
        float x = 0 == strcmp(align, "center") ? 0.5f : 0 == strcmp(align, "right") ? 1.0f : 0.0f;
        gtk_label_set_xalign(GTK_LABEL(view->control), x);
        gtk_label_set_justify(GTK_LABEL(view->control), x > 0.9f ? GTK_JUSTIFY_RIGHT : x > 0.4f ? GTK_JUSTIFY_CENTER : GTK_JUSTIFY_LEFT);
        if (NULL != runs) {
            char fg[8];
            color_hex(view->control, fg);
            g_autofree char *markup = tui_runs_markup(runs, fg, H.dark ? "#1e1e1e" : "#ffffff");
            gtk_label_set_markup(GTK_LABEL(view->control), markup);
        } else {
            gtk_label_set_text(GTK_LABEL(view->control), tui_prop_string(props, "text", ""));
        }
    } else if (0 == strcmp(kind, "button")) {
        gtk_button_set_label(GTK_BUTTON(view->control), tui_prop_string(props, "text", ""));
    } else if (0 == strcmp(kind, "checkbox")) {
        const char *text = tui_prop_string(props, "text", NULL);
        gtk_check_button_set_label(GTK_CHECK_BUTTON(view->control), NULL != text && '\0' != *text ? text : NULL);
        checkbox_group(view, props);
        JsonNode *checked = json_object_get_member(props, "checked");
        gboolean on = NULL != checked && JSON_NODE_VALUE == json_node_get_node_type(checked) && json_node_get_boolean(checked);
        gtk_check_button_set_active(GTK_CHECK_BUTTON(view->control), on);
    } else if (0 == strcmp(kind, "popup")) {
        JsonArray *choices = tui_prop_array(props, "choices");
        g_autoptr(JsonNode) choices_node = json_node_new(JSON_NODE_ARRAY);
        json_node_set_array(choices_node, NULL != choices ? choices : json_array_new());
        g_autofree char *key = json_to_string(choices_node, FALSE);
        if (0 != g_strcmp0(key, view->cache)) {
            g_free(view->cache);
            view->cache = g_steal_pointer(&key);
            GtkStringList *model = gtk_string_list_new(NULL);
            for (guint i = 0; NULL != choices && i < json_array_get_length(choices); i++) {
                gtk_string_list_append(model, json_array_get_string_element(choices, i));
            }
            gtk_drop_down_set_model(GTK_DROP_DOWN(view->control), G_LIST_MODEL(model));
            g_object_unref(model);
        }
        gint64 selected = tui_prop_int(props, "selected", -1);
        guint count = NULL != choices ? json_array_get_length(choices) : 0;
        gtk_drop_down_set_selected(GTK_DROP_DOWN(view->control), selected >= 0 && selected < count ? (guint) selected : GTK_INVALID_LIST_POSITION);
    } else if (0 == strcmp(kind, "textfield")) {
        const char *prompt = NULL != view->placer ? NULL : tui_prop_string(props, "prompt", NULL);
        const char *value = tui_prop_string(props, "value", "");
        gtk_label_set_text(GTK_LABEL(view->part[0]), NULL != prompt ? prompt : "");
        gtk_widget_set_visible(view->part[0], NULL != prompt && '\0' != *prompt);
        if (0 != strcmp(gtk_editable_get_text(GTK_EDITABLE(view->control)), value)) {
            H.setting++;
            gtk_editable_set_text(GTK_EDITABLE(view->control), value);
            H.setting--;
        }
    } else if (0 == strcmp(kind, "password")) {
        const char *value = tui_prop_string(props, "value", "");
        if (0 != strcmp(gtk_editable_get_text(GTK_EDITABLE(view->control)), value)) {
            H.setting++;
            gtk_editable_set_text(GTK_EDITABLE(view->control), value);
            H.setting--;
        }
        g_object_set(view->control, "placeholder-text", tui_prop_string(props, "label", NULL), NULL);
    } else if (0 == strcmp(kind, "number")) {
        GtkSpinButton *spin = GTK_SPIN_BUTTON(view->control);
        GtkAdjustment *adjustment = gtk_spin_button_get_adjustment(spin);
        double step = tui_prop_double(props, "step", 1);
        step = step > 0 ? step : 1;
        /* Without bounds, far ones on the steps: GTK snaps to the steps from the lower one */
        double min = tui_prop_double(props, "min", NAN);
        double max = tui_prop_double(props, "max", NAN);
        min = isnan(min) ? -step * 1e12 : min;
        max = isnan(max) ? step * 1e12 : MAX(min, max);
        const char *value = tui_prop_string(props, "value", "");
        char *end = NULL;
        double number = g_ascii_strtod(value, &end);
        H.setting++;
        gtk_adjustment_configure(adjustment, '\0' != *value && NULL != end && '\0' == *end ? CLAMP(number, min, max) : gtk_adjustment_get_value(adjustment), min, max, step, step * 10, 0);
        gtk_spin_button_set_digits(spin, (guint) step_digits(step));
        /* The text as the application has it, a number or not */
        if (0 != strcmp(gtk_editable_get_text(GTK_EDITABLE(spin)), value)) {
            gtk_editable_set_text(GTK_EDITABLE(spin), value);
        }
        H.setting--;
        view->keep_text = FALSE;
        g_object_set(gtk_editable_get_delegate(GTK_EDITABLE(spin)), "placeholder-text", tui_prop_string(props, "label", NULL), NULL);
    } else if (0 == strcmp(kind, "slider")) {
        GtkAdjustment *adjustment = gtk_range_get_adjustment(GTK_RANGE(view->control));
        double step = tui_prop_double(props, "step", 1);
        step = step > 0 ? step : 1;
        double min = tui_prop_double(props, "min", 0);
        double max = MAX(min, tui_prop_double(props, "max", 100));
        double value = tui_prop_double(props, "value", min + (max - min) / 2);
        gtk_adjustment_configure(adjustment, CLAMP(value, min, max), min, max, step, MAX(step, (max - min) / 10), 0);
        gtk_range_set_round_digits(GTK_RANGE(view->control), step_digits(step));
        view->sent = gtk_range_get_value(GTK_RANGE(view->control));
    } else if (0 == strcmp(kind, "listbox")) {
        GtkListBox *box = GTK_LIST_BOX(view->control);
        JsonArray *choices = tui_prop_array(props, "choices");
        JsonArray *selected = tui_prop_array(props, "selected");
        g_autoptr(JsonNode) choices_node = json_node_new(JSON_NODE_ARRAY);
        json_node_set_array(choices_node, NULL != choices ? choices : json_array_new());
        g_autofree char *key = json_to_string(choices_node, FALSE);
        gtk_list_box_set_selection_mode(box, tui_prop_bool(props, "multiple", FALSE) ? GTK_SELECTION_MULTIPLE : GTK_SELECTION_SINGLE);
        if (0 != g_strcmp0(key, view->cache)) {
            g_free(view->cache);
            view->cache = g_steal_pointer(&key);
            GtkWidget *row;
            /* gtk_list_box_remove_all() is GTK 4.12 */
            while (NULL != (row = gtk_widget_get_first_child(GTK_WIDGET(box)))) {
                gtk_list_box_remove(box, row);
            }
            for (guint i = 0; NULL != choices && i < json_array_get_length(choices); i++) {
                GtkWidget *label = gtk_label_new(json_array_get_string_element(choices, i));
                gtk_label_set_xalign(GTK_LABEL(label), 0);
                gtk_label_set_ellipsize(GTK_LABEL(label), PANGO_ELLIPSIZE_END);
                gtk_list_box_append(box, label);
            }
        }
        gtk_list_box_unselect_all(box);
        for (guint i = 0; NULL != selected && i < json_array_get_length(selected); i++) {
            GtkListBoxRow *row = gtk_list_box_get_row_at_index(box, (int) json_array_get_int_element(selected, i));
            if (NULL != row) {
                gtk_list_box_select_row(box, row);
            }
        }
    } else if (0 == strcmp(kind, "textarea")) {
        GtkTextBuffer *buffer = gtk_text_view_get_buffer(GTK_TEXT_VIEW(view->control));
        g_autofree char *current = buffer_text(buffer);
        const char *value = tui_prop_string(props, "value", "");
        int line = textarea_line_height(view->control);
        gint64 min_lines = MAX(1, tui_prop_int(props, "minLines", 3));
        gint64 max_lines = MAX(min_lines, tui_prop_int(props, "maxLines", min_lines > 10 ? min_lines : 10));
        if (0 != strcmp(current, value)) {
            H.setting++;
            gtk_text_buffer_set_text(buffer, value, -1);
            H.setting--;
        }
        /* Max first: GTK refuses a max below the current min. */
        gtk_scrolled_window_set_max_content_height(GTK_SCROLLED_WINDOW(view->outer), (int) (max_lines * line + 8));
        gtk_scrolled_window_set_min_content_height(GTK_SCROLLED_WINDOW(view->outer), (int) (min_lines * line + 8));
    } else if (0 == strcmp(kind, "list")) {
        list_rebuild(view, props);
    } else if (0 == strcmp(kind, "form")) {
        form_rebuild(view, props);
    } else if (0 == strcmp(kind, "tabs")) {
        tabs_refresh(view);
    } else if (0 == strcmp(kind, "disclosure")) {
        const char *description = tui_prop_string(props, "description", NULL);
        gtk_label_set_text(GTK_LABEL(view->part[0]), tui_prop_string(props, "summary", ""));
        gtk_label_set_text(GTK_LABEL(view->part[1]), NULL != description ? description : "");
        gtk_widget_set_visible(view->part[1], NULL != description);
        gtk_expander_set_expanded(GTK_EXPANDER(view->control), tui_prop_bool(props, "expanded", FALSE));
    } else if (0 == strcmp(kind, "progress")) {
        GtkProgressBar *bar = GTK_PROGRESS_BAR(view->control);
        const char *text = tui_prop_string(props, "text", NULL);
        gtk_progress_bar_set_show_text(bar, NULL != text);
        gtk_progress_bar_set_text(bar, text);
        if (tui_prop_is_null(props, "progress")) {
            if (0 == view->pulse) {
                gtk_progress_bar_set_pulse_step(bar, 0.1);
                view->pulse = g_timeout_add(100, progress_pulse, bar);
            }
        } else {
            if (0 != view->pulse) {
                g_source_remove(view->pulse);
                view->pulse = 0;
            }
            gtk_progress_bar_set_fraction(bar, CLAMP(tui_prop_double(props, "progress", 0), 0, 1));
        }
    } else if (0 == strcmp(kind, "spinner")) {
        gboolean running = tui_prop_bool(props, "running", FALSE);
        gtk_spinner_set_spinning(GTK_SPINNER(view->control), running);
        gtk_label_set_text(GTK_LABEL(view->part[0]), tui_prop_string(props, "text", ""));
        gtk_widget_set_visible(view->part[1], tui_prop_bool(props, "cancellable", FALSE));
    } else if (0 == strcmp(kind, "canvas")) {
        JsonArray *lines = tui_prop_array(props, "lines");
        gint64 columns = tui_prop_int(props, "columns", 0);
        guint rows = NULL != lines ? json_array_get_length(lines) : 0;
        for (guint i = 0; 0 == columns && i < rows; i++) {
            JsonNode *line = json_array_get_element(lines, i);
            JsonArray *runs = JSON_NODE_ARRAY == json_node_get_node_type(line) ? json_node_get_array(line) : NULL;
            gint64 width = 0;
            for (guint j = 0; NULL != runs && j < json_array_get_length(runs); j++) {
                width += g_utf8_strlen(tui_prop_string(json_array_get_object_element(runs, j), "t", ""), -1);
            }
            columns = MAX(columns, width);
        }
        gtk_drawing_area_set_content_width(GTK_DRAWING_AREA(view->control), (int) ceil(MAX(1, columns) * H.cell_width));
        gtk_drawing_area_set_content_height(GTK_DRAWING_AREA(view->control), (int) ceil(MAX(1u, rows) * H.cell_height));
        gtk_widget_set_focusable(view->control, focusable);
        gtk_widget_set_focus_on_click(view->control, focusable);
        gtk_widget_queue_draw(view->control);
        canvas_im_location(view);
        /* The text of the canvas, which may be a secret: in a test run only (TUI_APP_TEST=1, linux/contextmenu.sh) */
        for (guint i = 0; debug_enabled() && 0 == g_strcmp0(g_getenv("TUI_APP_TEST"), "1") && i < rows; i++) {
            JsonNode *line = json_array_get_element(lines, i);
            JsonArray *runs = JSON_NODE_ARRAY == json_node_get_node_type(line) ? json_node_get_array(line) : NULL;
            GString *text = g_string_new(NULL);
            for (guint j = 0; NULL != runs && j < json_array_get_length(runs); j++) {
                g_string_append(text, tui_prop_string(json_array_get_object_element(runs, j), "t", ""));
            }
            g_debug("canvas %" G_GINT64_FORMAT " row %u: %s", view->id, i, text->str);
            g_string_free(text, TRUE);
        }
    }
    if (NULL != view->placer) {
        place_child(view, props);
    }
    H.suppress--;
}

/* Puts the children's widgets in the container, in order. */
static void sync_children(View *view)
{
    TuiNode *node = node_of(view);

    if (NULL == view->content || NULL == node) {
        return;
    }
    H.suppress++;
    if (GTK_IS_NOTEBOOK(view->content)) {
        GtkNotebook *notebook = GTK_NOTEBOOK(view->content);
        for (guint i = 0; i < node->children->len; i++) {
            View *child = view_get(((TuiNode *) node->children->pdata[i])->id);
            if (NULL == child) {
                continue;
            }
            if (child->holder != view || gtk_notebook_page_num(notebook, child->outer) < 0) {
                gboolean placed = NULL != child->placer;
                detach_view(child);
                gtk_notebook_insert_page(notebook, child->outer, NULL, (int) i);
                child->holder = view;
                if (placed) {
                    apply_props(child);
                }
            } else {
                gtk_notebook_reorder_child(notebook, child->outer, (int) i);
            }
        }
        tabs_refresh(view);
    } else if (GTK_IS_OVERLAY(view->content)) {
        /* Over a canvas: each control in a placer above the drawing, in order, for the focus and what is on top. */
        GtkWidget *previous = view->control;
        for (guint i = 0; i < node->children->len; i++) {
            View *child = view_get(((TuiNode *) node->children->pdata[i])->id);
            if (NULL == child) {
                continue;
            }
            if (child->holder != view || NULL == child->placer || gtk_widget_get_parent(child->placer) != view->content) {
                detach_view(child);
                child->placer = g_object_ref_sink(placer_new());
                gtk_widget_add_css_class(child->outer, "tui-placed");
                gtk_box_append(GTK_BOX(child->placer), child->outer);
                gtk_overlay_add_overlay(GTK_OVERLAY(view->content), child->placer);
                child->holder = view;
                /* All of its props, as placed: a text field moved in loses its prompt */
                if (NULL != node_of(child)) {
                    apply_props(child);
                }
            }
            gtk_widget_insert_after(child->placer, view->content, previous);
            previous = child->placer;
        }
    } else {
        GtkWidget *previous = NULL;
        for (guint i = 0; i < node->children->len; i++) {
            View *child = view_get(((TuiNode *) node->children->pdata[i])->id);
            if (NULL == child) {
                continue;
            }
            if (child->holder != view || gtk_widget_get_parent(child->outer) != view->content) {
                gboolean placed = NULL != child->placer;
                detach_view(child);
                gtk_box_append(GTK_BOX(view->content), child->outer);
                child->holder = view;
                if (placed) {
                    apply_props(child);
                }
            }
            gtk_box_reorder_child_after(GTK_BOX(view->content), child->outer, previous);
            previous = child->outer;
        }
    }
    H.suppress--;
}

static gboolean in_array(GArray *ids, gint64 id)
{
    for (guint i = 0; i < ids->len; i++) {
        if (g_array_index(ids, gint64, i) == id) {
            return TRUE;
        }
    }
    return FALSE;
}

static void apply_patch(TuiMessage *message)
{
    TuiChanges changes;
    g_autoptr(GError) error = NULL;

    tui_changes_init(&changes);
    if (!tui_tree_apply(H.tree, message, &changes, &error)) {
        log_line("host", error->message);
    }
    /* A child moved out of a view this patch removes (or makes again), or removed after it, no longer has it as
     * holder: one pass over the views */
    GHashTable *going = g_hash_table_new(NULL, NULL);
    for (guint i = 0; i < changes.removed->len; i++) {
        View *view = view_get(g_array_index(changes.removed, gint64, i));
        if (NULL != view) {
            g_hash_table_add(going, view);
        }
    }
    for (guint i = 0; i < changes.created->len; i++) {
        View *view = view_get(g_array_index(changes.created, gint64, i));
        if (NULL != view) {
            g_hash_table_add(going, view);
        }
    }
    if (g_hash_table_size(going) > 0) {
        GHashTableIter iter;
        gpointer other;
        g_hash_table_iter_init(&iter, H.views);
        while (g_hash_table_iter_next(&iter, NULL, &other)) {
            if (g_hash_table_contains(going, ((View *) other)->holder)) {
                ((View *) other)->holder = NULL;
            }
        }
    }
    g_hash_table_unref(going);
    for (guint i = 0; i < changes.removed->len; i++) {
        gint64 id = g_array_index(changes.removed, gint64, i);
        g_hash_table_remove(H.views, &id);
    }
    for (guint i = 0; i < changes.created->len; i++) {
        TuiNode *node = tui_tree_get(H.tree, g_array_index(changes.created, gint64, i));
        if (NULL != node) {
            g_hash_table_remove(H.views, &node->id); /* re-created with the same id */
            create_view(node);
        }
    }
    /* Children before props: tabs label pages that exist. */
    for (guint i = 0; i < changes.relinked->len; i++) {
        View *view = view_get(g_array_index(changes.relinked, gint64, i));
        if (NULL != view) {
            sync_children(view);
        }
    }
    for (guint i = 0; i < changes.created->len; i++) {
        View *view = view_get(g_array_index(changes.created, gint64, i));
        if (NULL != view) {
            apply_props(view);
        }
    }
    for (guint i = 0; i < changes.updated->len; i++) {
        gint64 id = g_array_index(changes.updated, gint64, i);
        View *view = view_get(id);
        if (NULL != view && !in_array(changes.created, id)) {
            apply_props(view);
        }
    }
    if (NULL != H.tree->root) {
        View *root = view_get(H.tree->root->id);
        if (NULL != root && gtk_widget_get_parent(root->outer) != H.holder) {
            for (GtkWidget *child = gtk_widget_get_first_child(H.holder); NULL != child; child = gtk_widget_get_first_child(H.holder)) {
                gtk_box_remove(GTK_BOX(H.holder), child);
            }
            detach_view(root);
            gtk_box_append(GTK_BOX(H.holder), root->outer);
        }
        if (NULL != root) {
            tui_stack_layout_set_grow(root->outer, 1); /* the root fills the window */
        }
    }
    refresh_node_css();
    tui_changes_clear(&changes);
    gtk_widget_queue_resize(H.holder);
    H.rects_pending = TRUE;
    if (0 == H.test_stage) {
        H.patch_drawn = TRUE;
    }
}

/* ======================================================================== */
/* Menus                                                                    */
/* ======================================================================== */

static void menu_activated(GSimpleAction *action, GVariant *parameter, gpointer data)
{
    guint index = GPOINTER_TO_UINT(data);
    (void) parameter;
    GVariant *state = g_action_get_state(G_ACTION(action));
    if (NULL != state) {
        g_simple_action_set_state(action, g_variant_new_boolean(!g_variant_get_boolean(state)));
        g_variant_unref(state);
    }
    TuiMessage *message = tui_message_new(TUI_MSG_MENU_SELECT);
    message->id = g_strdup(H.menu_ids->pdata[index]);
    send_message(message);
}

static void build_menus(void)
{
    GMenu *bar = g_menu_new();
    const char *const no_accels[] = {NULL};

    for (guint i = 0; i < H.accel_actions->len; i++) {
        gtk_application_set_accels_for_action(H.app, H.accel_actions->pdata[i], no_accels);
    }
    g_ptr_array_set_size(H.accel_actions, 0);
    g_ptr_array_set_size(H.menu_ids, 0);
    g_hash_table_remove_all(H.shortcuts);
    g_clear_object(&H.menu_actions);
    H.menu_actions = g_simple_action_group_new();

    GPtrArray *menus = NULL != H.last_menu ? H.last_menu->menus : NULL;
    guint count = NULL != menus ? menus->len : 0;
    gboolean has_help = FALSE;
    for (guint m = 0; m < count; m++) {
        TuiMenu *menu = menus->pdata[m];
        GMenu *submenu = g_menu_new();
        GMenu *section = g_menu_new();
        has_help = has_help || 0 == g_ascii_strcasecmp(menu->title, "Help");
        for (guint i = 0; i < menu->items->len; i++) {
            TuiMenuItem *item = menu->items->pdata[i];
            if (item->separator) {
                g_menu_append_section(submenu, NULL, G_MENU_MODEL(section));
                g_object_unref(section);
                section = g_menu_new();
                continue;
            }
            if (NULL == item->id) {
                continue;
            }
            guint index = H.menu_ids->len;
            g_autofree char *name = g_strdup_printf("m%u", index);
            g_autofree char *detailed = g_strdup_printf("tui.%s", name);
            GSimpleAction *action = item->has_checked && !item->checked_null
                ? g_simple_action_new_stateful(name, NULL, g_variant_new_boolean(item->checked))
                : g_simple_action_new(name, NULL);
            g_ptr_array_add(H.menu_ids, g_strdup(item->id));
            g_simple_action_set_enabled(action, !item->has_enabled || item->enabled);
            g_signal_connect(action, "activate", G_CALLBACK(menu_activated), GUINT_TO_POINTER(index));
            g_action_map_add_action(G_ACTION_MAP(H.menu_actions), G_ACTION(action));
            g_object_unref(action);
            g_menu_append(section, NULL != item->label ? item->label : item->id, detailed);
            g_autofree char *accel = tui_key_accel(item->shortcut);
            if (NULL != accel) {
                const char *accels[] = {accel, NULL};
                gtk_application_set_accels_for_action(H.app, detailed, accels);
                g_ptr_array_add(H.accel_actions, g_strdup(detailed));
                g_hash_table_add(H.shortcuts, g_strdup(item->shortcut));
            }
        }
        if (0 == m && H.manifest->menu_quit) {
            g_menu_append_section(submenu, NULL, G_MENU_MODEL(section));
            g_object_unref(section);
            section = g_menu_new();
            g_menu_append(section, "Quit", "app.quit");
        }
        if (has_help && 0 == g_ascii_strcasecmp(menu->title, "Help") && H.manifest->menu_about) {
            g_autofree char *about = g_strdup_printf("About %s", H.manifest->name);
            g_menu_append_section(submenu, NULL, G_MENU_MODEL(section));
            g_object_unref(section);
            section = g_menu_new();
            g_menu_append(section, about, "app.about");
        }
        g_menu_append_section(submenu, NULL, G_MENU_MODEL(section));
        g_object_unref(section);
        g_menu_append_submenu(bar, menu->title, G_MENU_MODEL(submenu));
        g_object_unref(submenu);
    }
    if (0 == count && H.manifest->menu_quit) {
        GMenu *file = g_menu_new();
        g_menu_append(file, "Quit", "app.quit");
        g_menu_append_submenu(bar, "File", G_MENU_MODEL(file));
        g_object_unref(file);
    }
    if (!has_help && H.manifest->menu_about) {
        GMenu *help = g_menu_new();
        g_autofree char *about = g_strdup_printf("About %s", H.manifest->name);
        g_menu_append(help, about, "app.about");
        g_menu_append_submenu(bar, "Help", G_MENU_MODEL(help));
        g_object_unref(help);
    }
    gtk_widget_insert_action_group(GTK_WIDGET(H.window), "tui", G_ACTION_GROUP(H.menu_actions));
    gtk_popover_menu_bar_set_menu_model(GTK_POPOVER_MENU_BAR(H.menubar), G_MENU_MODEL(bar));
    gtk_widget_set_visible(H.menubar, H.manifest->menu_native && g_menu_model_get_n_items(G_MENU_MODEL(bar)) > 0);
    g_object_unref(bar);
}

/* ======================================================================== */
/* Window, system                                                           */
/* ======================================================================== */

static void set_window_progress(TuiMessage *message)
{
    GDBusConnection *bus = g_application_get_dbus_connection(G_APPLICATION(H.app));
    if (NULL == bus) {
        return;
    }
    /* The Unity launcher API, which docks and taskbars on Linux read. */
    GVariantBuilder props;
    gboolean visible = !message->progress_null && NULL != message->progress_state;
    double value = message->has_progress_value && !message->progress_value_null ? message->progress_value : 0;
    g_variant_builder_init(&props, G_VARIANT_TYPE("a{sv}"));
    g_variant_builder_add(&props, "{sv}", "progress-visible", g_variant_new_boolean(visible));
    g_variant_builder_add(&props, "{sv}", "progress", g_variant_new_double(CLAMP(value, 0, 1)));
    g_variant_builder_add(&props, "{sv}", "urgent", g_variant_new_boolean(visible && 0 == g_strcmp0(message->progress_state, "error")));
    g_autofree char *uri = g_strdup_printf("application://%s.desktop", H.manifest->id);
    g_autofree char *path = g_strdup_printf("/com/canonical/unity/launcherentry/%u", g_str_hash(uri));
    gboolean emitted = g_dbus_connection_emit_signal(bus, NULL, path, "com.canonical.Unity.LauncherEntry", "Update", g_variant_new("(sa{sv})", uri, &props), NULL);
    if (H.feature_test) {
        H.ft_progress_emitted = emitted && visible;
        H.ft_progress = value;
        g_free(H.ft_progress_state);
        H.ft_progress_state = g_strdup(message->progress_state);
    }
}

static void notify(TuiMessage *message)
{
    g_autoptr(GNotification) notification = g_notification_new(message->title);
    if (NULL != message->body) {
        g_notification_set_body(notification, message->body);
    }
    g_notification_set_default_action(notification, "app.present");
    g_application_send_notification(G_APPLICATION(H.app), NULL, notification);
}

static void focus_node(gint64 id)
{
    if (0 == id) {
        gtk_window_set_focus(H.window, NULL);
        return;
    }
    View *view = view_get(id);
    if (NULL != view) {
        H.suppress++;
        gtk_widget_grab_focus(view->control);
        H.suppress--;
    }
}

/* ---- appearance ---- */

static void apply_theme(void)
{
    g_object_set(gtk_settings_get_default(), "gtk-application-prefer-dark-theme", H.dark, NULL);
}

static void appearance_changed(gboolean dark)
{
    if (dark == H.dark) {
        return;
    }
    H.dark = dark;
    apply_theme();
    if (NULL != H.peer) {
        TuiMessage *message = tui_message_new(TUI_MSG_APPEARANCE);
        message->appearance = g_strdup(dark ? "dark" : "light");
        send_message(message);
    }
}

static gboolean scheme_is_dark(GVariant *value, gboolean *dark)
{
    g_autoptr(GVariant) inner = g_variant_is_of_type(value, G_VARIANT_TYPE_VARIANT) ? g_variant_get_variant(value) : g_variant_ref(value);
    while (g_variant_is_of_type(inner, G_VARIANT_TYPE_VARIANT)) {
        GVariant *deeper = g_variant_get_variant(inner);
        g_variant_unref(inner);
        inner = deeper;
    }
    if (!g_variant_is_of_type(inner, G_VARIANT_TYPE_UINT32)) {
        return FALSE;
    }
    guint32 scheme = g_variant_get_uint32(inner);
    if (1 != scheme && 2 != scheme) {
        return FALSE; /* 0: no preference */
    }
    *dark = 1 == scheme;
    return TRUE;
}

static void portal_signal(GDBusProxy *proxy, const char *sender, const char *signal, GVariant *parameters, gpointer data)
{
    const char *scope = NULL;
    const char *key = NULL;
    g_autoptr(GVariant) value = NULL;
    gboolean dark;
    (void) proxy;
    (void) sender;
    (void) data;
    if (0 != g_strcmp0(signal, "SettingChanged")) {
        return;
    }
    g_variant_get(parameters, "(&s&sv)", &scope, &key, &value);
    if (0 == g_strcmp0(scope, "org.freedesktop.appearance") && 0 == g_strcmp0(key, "color-scheme") && scheme_is_dark(value, &dark)) {
        H.portal_scheme = TRUE;
        appearance_changed(dark);
    }
}

static gboolean theme_name_is_dark(void)
{
    g_autofree char *theme = NULL;
    gboolean prefer = FALSE;
    g_object_get(gtk_settings_get_default(), "gtk-theme-name", &theme, "gtk-application-prefer-dark-theme", &prefer, NULL);
    g_autofree char *lower = NULL != theme ? g_ascii_strdown(theme, -1) : NULL;
    return prefer || (NULL != lower && NULL != strstr(lower, "dark"));
}

/* Without a portal that knows the color scheme, a theme switch shows in the theme name, set by XSETTINGS. */
static void theme_name_changed(GtkSettings *settings, GParamSpec *pspec, gpointer data)
{
    g_autofree char *theme = NULL;
    (void) pspec;
    (void) data;
    if (H.portal_scheme) {
        return;
    }
    g_object_get(settings, "gtk-theme-name", &theme, NULL);
    g_autofree char *lower = NULL != theme ? g_ascii_strdown(theme, -1) : NULL;
    /* The same rule as at start, with the user's own preference, not the one the host set since. */
    appearance_changed(H.user_prefers_dark || (NULL != lower && NULL != strstr(lower, "dark")));
}

static void follow_appearance(void)
{
    const char *theme = H.manifest->theme;
    if (0 == g_strcmp0(theme, "dark") || 0 == g_strcmp0(theme, "light")) {
        H.dark = 0 == g_strcmp0(theme, "dark");
        apply_theme();
        return;
    }
    g_object_get(gtk_settings_get_default(), "gtk-application-prefer-dark-theme", &H.user_prefers_dark, NULL);
    H.dark = theme_name_is_dark();
    g_signal_connect(gtk_settings_get_default(), "notify::gtk-theme-name", G_CALLBACK(theme_name_changed), NULL);
    H.portal = g_dbus_proxy_new_for_bus_sync(G_BUS_TYPE_SESSION, G_DBUS_PROXY_FLAGS_DO_NOT_AUTO_START_AT_CONSTRUCTION, NULL,
        "org.freedesktop.portal.Desktop", "/org/freedesktop/portal/desktop", "org.freedesktop.portal.Settings", NULL, NULL);
    if (NULL == H.portal) {
        return;
    }
    g_autoptr(GVariant) answer = g_dbus_proxy_call_sync(H.portal, "ReadOne", g_variant_new("(ss)", "org.freedesktop.appearance", "color-scheme"),
        G_DBUS_CALL_FLAGS_NONE, 1000, NULL, NULL);
    if (NULL == answer) {
        answer = g_dbus_proxy_call_sync(H.portal, "Read", g_variant_new("(ss)", "org.freedesktop.appearance", "color-scheme"),
            G_DBUS_CALL_FLAGS_NONE, 1000, NULL, NULL);
    }
    gboolean dark;
    if (NULL != answer) {
        g_autoptr(GVariant) value = g_variant_get_child_value(answer, 0);
        if (scheme_is_dark(value, &dark)) {
            H.dark = dark;
            H.portal_scheme = TRUE;
        }
    }
    apply_theme();
    g_signal_connect(H.portal, "g-signal", G_CALLBACK(portal_signal), NULL);
}

/* ---- geometry ---- */

static char *geometry_path(void)
{
    return g_build_filename(g_get_user_config_dir(), H.manifest->id, "geometry.json", NULL);
}

static void save_geometry(void)
{
    if (!H.manifest->remember_geometry || NULL == H.window) {
        return;
    }
    g_autofree char *path = geometry_path();
    g_autofree char *dir = g_path_get_dirname(path);
    int width = 0;
    int height = 0;
    gtk_window_get_default_size(H.window, &width, &height);
    if (width <= 0 || height <= 0) {
        width = gtk_widget_get_width(GTK_WIDGET(H.window));
        height = gtk_widget_get_height(GTK_WIDGET(H.window));
    }
    g_autofree char *json = tui_geometry_to_json(width, height, gtk_window_is_maximized(H.window));
    g_mkdir_with_parents(dir, 0700);
    g_file_set_contents(path, json, -1, NULL);
}

/* ======================================================================== */
/* Dialogs                                                                  */
/* ======================================================================== */

static void quit_with(int status)
{
    H.status = status;
    H.allow_close = TRUE;
    if (NULL != H.child && !H.exited) {
        g_subprocess_force_exit(H.child);
    }
    g_application_quit(G_APPLICATION(H.app));
}

static void fatal_dismissed(GObject *source, GAsyncResult *result, gpointer data)
{
    (void) data;
    gtk_alert_dialog_choose_finish(GTK_ALERT_DIALOG(source), result, NULL);
    quit_with(1);
}

/* A native error dialog, then the host quits. In the self-test, the reason is printed instead. */
static void fatal(const char *message, const char *detail)
{
    if (H.self_test) {
        self_test_fail("%s%s%s", message, NULL != detail ? "\n" : "", NULL != detail ? detail : "");
        return;
    }
    if (H.feature_test) {
        ft_fail("%s%s%s", message, NULL != detail ? "\n" : "", NULL != detail ? detail : "");
        return;
    }
    if (H.canvas_test) {
        ct_fail("%s%s%s", message, NULL != detail ? "\n" : "", NULL != detail ? detail : "");
        return;
    }
    log_line("host", message);
    GtkAlertDialog *dialog = gtk_alert_dialog_new("%s", message);
    const char *buttons[] = {"Close", NULL};
    if (NULL != detail) {
        gtk_alert_dialog_set_detail(dialog, detail);
    }
    gtk_alert_dialog_set_buttons(dialog, buttons);
    gtk_alert_dialog_choose(dialog, H.window, NULL, fatal_dismissed, NULL);
    g_object_unref(dialog);
}

static void force_answered(GObject *source, GAsyncResult *result, gpointer data)
{
    int button = gtk_alert_dialog_choose_finish(GTK_ALERT_DIALOG(source), result, NULL);
    (void) data;
    close_action(tui_close_force_answer(&H.flow, 1 == button));
}

static void ask_force_quit(void)
{
    g_autofree char *question = g_strdup_printf("%s is not responding. Force quit?", H.manifest->name);
    if (H.self_test) {
        self_test_fail("%s", question);
        return;
    }
    if (H.canvas_test) {
        ct_fail("%s", question);
        return;
    }
    GtkAlertDialog *dialog = gtk_alert_dialog_new("%s", question);
    const char *buttons[] = {"Wait", "Force Quit", NULL};
    gtk_alert_dialog_set_buttons(dialog, buttons);
    gtk_alert_dialog_set_cancel_button(dialog, 0);
    gtk_alert_dialog_set_default_button(dialog, 1);
    gtk_alert_dialog_choose(dialog, H.window, NULL, force_answered, NULL);
    g_object_unref(dialog);
    if (H.feature_test) {
        ft_force_quit_offered();
    }
}

/* ======================================================================== */
/* Close flow                                                               */
/* ======================================================================== */

static gboolean close_timed_out(gpointer data)
{
    (void) data;
    H.close_timer = 0;
    close_action(tui_close_timeout(&H.flow));
    return G_SOURCE_REMOVE;
}

static void restart_close_timer(guint seconds)
{
    if (0 != H.close_timer) {
        g_source_remove(H.close_timer);
        H.close_timer = 0;
    }
    if (seconds > 0) {
        H.close_timer = g_timeout_add_seconds(seconds, close_timed_out, NULL);
    }
}

static void close_window_now(void)
{
    restart_close_timer(0);
    save_geometry();
    H.allow_close = TRUE;
    if (NULL != H.window) {
        gtk_window_destroy(H.window);
        H.window = NULL;
        if (H.feature_test) {
            ft_window_closed();
        }
    }
}

static void close_action(TuiCloseAction action)
{
    switch (action) {
    case TUI_DO_NOTHING:
        break;
    case TUI_DO_SEND_CLOSE:
        send_message(tui_message_new(TUI_MSG_CLOSE));
        restart_close_timer(REPLY_SECONDS);
        break;
    case TUI_DO_KEEP_OPEN:
        restart_close_timer(0);
        break;
    case TUI_DO_WAIT_EXIT:
        restart_close_timer(EXIT_SECONDS);
        break;
    case TUI_DO_ASK_FORCE:
        restart_close_timer(0);
        ask_force_quit();
        break;
    case TUI_DO_KILL:
        if (NULL != H.child && !H.exited) {
            g_subprocess_force_exit(H.child); /* its exit closes the window */
        } else {
            close_window_now();
        }
        break;
    case TUI_DO_CLOSE_WINDOW:
        close_window_now();
        break;
    }
}

static gboolean close_requested(GtkWindow *window, gpointer data)
{
    (void) window;
    (void) data;
    if (H.allow_close) {
        return FALSE;
    }
    close_action(tui_close_requested(&H.flow, NULL != H.peer && !H.exited));
    return TRUE;
}

/* ======================================================================== */
/* Messages from the application                                            */
/* ======================================================================== */

/* The pointer over the drawing of a canvas (contract $defs.cursor). On the drawing area only: the controls placed
 * over it are its siblings in the overlay and keep their own, and GTK shows its own again off the drawing. GDK takes
 * CSS cursor names and falls back to the arrow for a name the theme has none for; "default" is the arrow GTK
 * shows anyway. The widget keeps the shape after the pointer left, which the application does not send again. */
static void set_canvas_cursor(gint64 id, const char *shape)
{
    View *view = view_get(id);
    if (NULL == view || 0 != g_strcmp0(view->kind, "canvas") || NULL == view->control) {
        return; /* an unknown node, or not a canvas */
    }
    if (!tui_cursor_shape_known(shape)) {
        shape = "default";
    }
    g_debug("canvas %" G_GINT64_FORMAT ": cursor %s", id, shape);
    gtk_widget_set_cursor_from_name(view->control, 0 == strcmp(shape, "default") ? NULL : shape);
}

static void received(TuiMessage *message)
{
    switch (message->type) {
    case TUI_MSG_PATCH:
        apply_patch(message);
        break;
    case TUI_MSG_FOCUS:
        focus_node(message->node);
        break;
    case TUI_MSG_CURSOR:
        set_canvas_cursor(message->node, message->shape);
        break;
    case TUI_MSG_CONTEXTMENU:
        show_context_menu(message);
        break;
    case TUI_MSG_CLIPBOARD:
        set_clipboard(message->text);
        break;
    case TUI_MSG_WINDOW:
        if (message->has_title) {
            gtk_window_set_title(H.window, message->title_null ? H.manifest->title : message->title);
        }
        if (message->has_progress) {
            set_window_progress(message);
        }
        break;
    case TUI_MSG_NOTIFY:
        H.ft_notifies++;
        notify(message);
        break;
    case TUI_MSG_BEEP:
        H.ft_beeps++;
        gdk_display_beep(gtk_widget_get_display(GTK_WIDGET(H.window)));
        break;
    case TUI_MSG_MENU:
        tui_message_free(H.last_menu);
        H.last_menu = message;
        build_menus();
        return; /* kept */
    case TUI_MSG_CLOSE_REPLY:
        close_action(tui_close_reply(&H.flow, message->accept));
        break;
    case TUI_MSG_BYE:
        H.bye_seen = TRUE;
        close_action(tui_close_bye(&H.flow));
        break;
    default:
        break; /* unknown or host-to-app types: ignored */
    }
    tui_message_free(message);
}

static void send_hello(void)
{
    TuiMessage *hello = tui_message_new(TUI_MSG_HELLO);
    gint64 columns;
    gint64 rows;
    grid(&columns, &rows);
    H.sent_columns = columns;
    H.sent_rows = rows;
    hello->os = g_strdup("linux");
    hello->columns = columns;
    hello->rows = rows;
    hello->cell_width = H.cell_width;
    hello->cell_height = H.cell_height;
    hello->has_scale = TRUE;
    hello->scale = gtk_widget_get_scale_factor(GTK_WIDGET(H.window));
    hello->appearance = g_strdup(H.dark ? "dark" : "light");
    hello->has_capabilities = TRUE;
    const char *capabilities[] = {"menu", "notify", "beep", "progress", "open", "appearance", "canvas-controls", "password", "number", "slider", "listbox",
        "pointer", "contextmenu", "clipboard", NULL};
    hello->capabilities = g_strdupv((char **) capabilities);
    send_message(hello);
}

static void peer_free(Peer *peer)
{
    if (NULL == peer) {
        return;
    }
    g_cancellable_cancel(peer->cancellable);
    g_object_unref(peer->cancellable);
    g_io_stream_close(G_IO_STREAM(peer->connection), NULL, NULL);
    g_object_unref(peer->connection);
    tui_decoder_free(peer->decoder);
    g_free(peer);
}

static void read_more(Peer *peer);

/* FALSE when the peer is to be dropped. */
static gboolean peer_frame(Peer *peer, const char *json)
{
    g_autoptr(GError) error = NULL;
    TuiMessage *message = tui_message_parse(json, -1, &error);

    if (!peer->authed) {
        gboolean ok = NULL != message && TUI_MSG_AUTH == message->type && TUI_PROTOCOL == message->protocol
            && tui_token_equal(H.token, message->token) && NULL == H.peer;
        tui_message_free(message);
        if (!ok) {
            log_line("host", "a connection did not authenticate; closed");
            return FALSE;
        }
        peer->authed = TRUE;
        H.peer = peer;
        if (0 != H.accept_timer) {
            g_source_remove(H.accept_timer);
            H.accept_timer = 0;
        }
        g_socket_service_stop(H.service);
        g_socket_listener_close(G_SOCKET_LISTENER(H.service));
        send_hello();
        send_open();
        return TRUE;
    }
    if (NULL == message) {
        log_line("host", error->message);
        return TRUE; /* a bad message is skipped */
    }
    received(message);
    return TRUE;
}

static void got_bytes(GObject *source, GAsyncResult *result, gpointer data)
{
    Peer *peer = data;
    g_autoptr(GError) error = NULL;
    g_autoptr(GBytes) bytes = g_input_stream_read_bytes_finish(G_INPUT_STREAM(source), result, &error);

    if (g_error_matches(error, G_IO_ERROR, G_IO_ERROR_CANCELLED)) {
        return; /* freed */
    }
    if (NULL == bytes || 0 == g_bytes_get_size(bytes)) {
        if (peer == H.peer) {
            log_line("host", "the application closed the connection");
            H.peer = NULL;
            peer_free(peer);
            if (!H.exited && H.flow.state == TUI_CLOSE_IDLE) {
                /* Nothing more to say to it: closing now waits for its exit. */
            }
        } else {
            peer_free(peer);
        }
        return;
    }
    g_autoptr(GPtrArray) frames = g_ptr_array_new_with_free_func(g_free);
    gsize size;
    const guint8 *data_bytes = g_bytes_get_data(bytes, &size);
    gboolean keep = tui_decoder_feed(peer->decoder, data_bytes, size, frames, &error);
    if (!keep) {
        log_line("host", error->message);
    }
    for (guint i = 0; keep && i < frames->len; i++) {
        keep = peer_frame(peer, frames->pdata[i]);
    }
    if (!keep) {
        if (peer == H.peer) {
            H.peer = NULL;
        }
        peer_free(peer);
        return;
    }
    read_more(peer);
}

static void read_more(Peer *peer)
{
    g_input_stream_read_bytes_async(g_io_stream_get_input_stream(G_IO_STREAM(peer->connection)), 65536, G_PRIORITY_DEFAULT, peer->cancellable,
        got_bytes, peer);
}

static gboolean incoming(GSocketService *service, GSocketConnection *connection, GObject *source, gpointer data)
{
    (void) service;
    (void) source;
    (void) data;
    if (NULL != H.peer) {
        return FALSE; /* one application per host */
    }
    Peer *peer = g_new0(Peer, 1);
    peer->connection = g_object_ref(connection);
    peer->decoder = tui_decoder_new();
    peer->cancellable = g_cancellable_new();
    read_more(peer);
    return TRUE;
}

static gboolean accept_timed_out(gpointer data)
{
    (void) data;
    H.accept_timer = 0;
    if (NULL == H.peer) {
        fatal("The application did not connect within 10 seconds.", NULL);
    }
    return G_SOURCE_REMOVE;
}

/* ======================================================================== */
/* The child process                                                        */
/* ======================================================================== */

static void read_lines(GDataInputStream *stream, const char *name);

static void got_line(GObject *source, GAsyncResult *result, gpointer data)
{
    const char *name = data;
    g_autofree char *line = g_data_input_stream_read_line_finish_utf8(G_DATA_INPUT_STREAM(source), result, NULL, NULL);
    if (NULL == line) {
        g_object_unref(source);
        return;
    }
    log_line(name, line);
    if (0 == strcmp(name, "stderr")) {
        g_queue_push_tail(H.stderr_tail, g_strdup(line));
        while (g_queue_get_length(H.stderr_tail) > STDERR_LINES) {
            g_free(g_queue_pop_head(H.stderr_tail));
        }
    }
    read_lines(G_DATA_INPUT_STREAM(source), name);
}

static void read_lines(GDataInputStream *stream, const char *name)
{
    g_data_input_stream_read_line_async(stream, G_PRIORITY_DEFAULT, NULL, got_line, (gpointer) name);
}

static void child_exited(GObject *source, GAsyncResult *result, gpointer data)
{
    (void) data;
    g_subprocess_wait_finish(G_SUBPROCESS(source), result, NULL);
    H.exited = TRUE;
    H.exited_normally = g_subprocess_get_if_exited(H.child);
    H.exit_status = H.exited_normally ? g_subprocess_get_exit_status(H.child) : -1;
    g_autofree char *note = g_strdup_printf("the application exited (%s %d)", H.exited_normally ? "status" : "signal",
        H.exited_normally ? H.exit_status : g_subprocess_get_term_sig(H.child));
    log_line("host", note);

    if (NULL == H.peer && !H.bye_seen && H.flow.state == TUI_CLOSE_IDLE && 0 == H.test_stage) {
        /* It never authenticated: its last stderr lines say why. */
        GString *tail = g_string_new(NULL);
        for (GList *line = H.stderr_tail->head; NULL != line; line = line->next) {
            g_string_append_printf(tail, "%s\n", (const char *) line->data);
        }
        g_autofree char *message = g_strdup_printf("%s stopped before it started.", H.manifest->name);
        fatal(message, tail->len > 0 ? tail->str : note);
        g_string_free(tail, TRUE);
        return;
    }
    if (H.feature_test) {
        H.ft_signal = H.exited_normally ? 0 : g_subprocess_get_term_sig(H.child);
        ft_process_exited();
        if (H.ft_done) {
            return;
        }
    }
    if (H.canvas_test && !H.ct_done) {
        if (0 == g_strcmp0(H.ct_stage, "closing") && H.bye_seen && H.exited_normally && 0 == H.exit_status) {
            printf("canvas-test: the application sent bye and exited 0\ncanvas-test: PASS\n");
            fflush(stdout);
            H.ct_done = TRUE;
            H.ct_passed = TRUE;
            H.status = 0;
        } else {
            ct_fail("the application exited while %s (bye %s, %s)", NULL != H.ct_stage ? H.ct_stage : "starting", H.bye_seen ? "seen" : "not seen", note);
            return;
        }
    }
    if (H.self_test) {
        if (3 == H.test_stage && H.bye_seen && H.exited_normally && 0 == H.exit_status) {
            fprintf(stderr, "self-test: the application sent bye and exited 0\nself-test: PASS\n");
            H.status = 0;
        } else {
            self_test_fail("the application exited at stage %d (bye %s, %s)", H.test_stage, H.bye_seen ? "seen" : "not seen", note);
            return;
        }
    }
    close_action(tui_close_exited(&H.flow));
}

static char *find_php(GError **error)
{
    const char *configured = H.manifest->php_path;
    g_autofree char *php = NULL;

    if (NULL != configured) {
        php = g_path_is_absolute(configured) || NULL == strchr(configured, '/') ? g_strdup(configured) : g_build_filename(H.manifest->dir, configured, NULL);
        if (NULL == strchr(php, '/')) {
            char *found = g_find_program_in_path(php);
            g_free(php);
            php = found;
        }
    } else {
        php = g_find_program_in_path("php");
    }
    const char *minimum = NULL != H.manifest->php_min_version ? H.manifest->php_min_version : NULL;
    g_autofree char *need = NULL != minimum ? g_strdup_printf("PHP %s or newer", minimum) : g_strdup("PHP");
    if (NULL == php || !g_file_test(php, G_FILE_TEST_IS_EXECUTABLE)) {
        g_set_error(error, G_IO_ERROR, G_IO_ERROR_NOT_FOUND,
            "%s needs %s, and no php was found%s%s. Install it (for example: sudo apt install php-cli), then start %s again.", H.manifest->name, need,
            NULL != configured ? " at " : " on the PATH", NULL != configured ? configured : "", H.manifest->name);
        return NULL;
    }
    char *argv[] = {php, "-r", "echo PHP_VERSION;", NULL};
    g_autofree char *version = NULL;
    int wait = 0;
    if (!g_spawn_sync(NULL, argv, NULL, G_SPAWN_STDERR_TO_DEV_NULL, NULL, NULL, &version, NULL, &wait, NULL) || !g_spawn_check_wait_status(wait, NULL)
        || NULL == version || '\0' == *version) {
        g_set_error(error, G_IO_ERROR, G_IO_ERROR_FAILED, "%s needs %s, and %s does not run.", H.manifest->name, need, php);
        return NULL;
    }
    g_strstrip(version);
    if (NULL != minimum && tui_version_compare(version, minimum) < 0) {
        g_set_error(error, G_IO_ERROR, G_IO_ERROR_FAILED, "%s needs %s; %s is PHP %s. Install a newer php, then start %s again.", H.manifest->name, need,
            php, version, H.manifest->name);
        return NULL;
    }
    return g_steal_pointer(&php);
}

static void start_app(void)
{
    g_autoptr(GError) error = NULL;
    g_autofree char *php = NULL;

    if (0 == g_strcmp0(H.manifest->mode, "system")) {
        php = find_php(&error);
        if (NULL == php) {
            fatal("PHP is missing", error->message);
            return;
        }
    }
    g_auto(GStrv) argv = tui_launch_argv(H.manifest, php, &error);
    if (NULL == argv) {
        fatal("The application cannot start.", error->message);
        return;
    }
    if (NULL == strchr(argv[0], '/')) {
        char *found = g_find_program_in_path(argv[0]);
        if (NULL == found) {
            g_autofree char *detail = g_strdup_printf("%s is not on the PATH.", argv[0]);
            fatal("The application cannot start.", detail);
            return;
        }
        g_free(argv[0]);
        argv[0] = found;
    }

    /* Listen on 127.0.0.1, port 0. */
    H.service = g_socket_service_new();
    g_autoptr(GInetAddress) loopback = g_inet_address_new_loopback(G_SOCKET_FAMILY_IPV4);
    g_autoptr(GSocketAddress) address = g_inet_socket_address_new(loopback, 0);
    g_autoptr(GSocketAddress) effective = NULL;
    if (!g_socket_listener_add_address(G_SOCKET_LISTENER(H.service), address, G_SOCKET_TYPE_STREAM, G_SOCKET_PROTOCOL_TCP, NULL, &effective, &error)) {
        fatal("The host cannot listen on 127.0.0.1.", error->message);
        return;
    }
    g_signal_connect(H.service, "incoming", G_CALLBACK(incoming), NULL);
    g_socket_service_start(H.service);
    H.token = tui_new_token();
    H.address = g_strdup_printf("tcp://127.0.0.1:%u", g_inet_socket_address_get_port(G_INET_SOCKET_ADDRESS(effective)));

    g_auto(GStrv) inherited = g_get_environ();
    g_auto(GStrv) env = tui_launch_env(inherited, H.manifest, H.address, H.token);
    g_autoptr(GSubprocessLauncher) launcher = g_subprocess_launcher_new(G_SUBPROCESS_FLAGS_STDOUT_PIPE | G_SUBPROCESS_FLAGS_STDERR_PIPE);
    g_subprocess_launcher_set_environ(launcher, env);
    g_subprocess_launcher_set_cwd(launcher, H.manifest->dir);
    H.child = g_subprocess_launcher_spawnv(launcher, (const char *const *) argv, &error);
    if (NULL == H.child) {
        fatal("The application cannot start.", error->message);
        return;
    }
    read_lines(g_data_input_stream_new(g_subprocess_get_stdout_pipe(H.child)), "stdout");
    read_lines(g_data_input_stream_new(g_subprocess_get_stderr_pipe(H.child)), "stderr");
    g_subprocess_wait_async(H.child, NULL, child_exited, NULL);
    H.accept_timer = g_timeout_add_seconds(ACCEPT_SECONDS, accept_timed_out, NULL);
}

/* ======================================================================== */
/* Self-test                                                                */
/* ======================================================================== */

static void self_test_fail(const char *format, ...)
{
    va_list args;
    va_start(args, format);
    g_autofree char *reason = g_strdup_vprintf(format, args);
    va_end(args);
    if (!H.self_test || 99 == H.test_stage) {
        return;
    }
    fprintf(stderr, "self-test: FAIL: %s\n", reason);
    H.test_stage = 99;
    quit_with(1);
}

static gboolean self_test_timed_out(gpointer data)
{
    (void) data;
    H.test_timer = 0;
    self_test_fail("not done after %d s (stage %d)", SELF_TEST_SECONDS, H.test_stage);
    return G_SOURCE_REMOVE;
}

static gboolean control_matches(const char *kind, GtkWidget *control)
{
    static const struct {
        const char *kind;
        GType (*type)(void);
    } expected[] = {
        {"stack", gtk_box_get_type}, {"label", gtk_label_get_type}, {"textfield", gtk_entry_get_type}, {"textarea", gtk_text_view_get_type},
        {"list", gtk_list_box_get_type}, {"form", gtk_grid_get_type}, {"tabs", gtk_notebook_get_type}, {"disclosure", gtk_expander_get_type},
        {"progress", gtk_progress_bar_get_type}, {"spinner", gtk_spinner_get_type}, {"canvas", gtk_drawing_area_get_type},
        {"button", gtk_button_get_type}, {"checkbox", gtk_check_button_get_type}, {"popup", gtk_drop_down_get_type},
        {"password", gtk_password_entry_get_type}, {"number", gtk_spin_button_get_type}, {"slider", gtk_scale_get_type},
        {"listbox", gtk_list_box_get_type},
    };
    for (guint i = 0; i < G_N_ELEMENTS(expected); i++) {
        if (0 == strcmp(kind, expected[i].kind)) {
            return G_TYPE_CHECK_INSTANCE_TYPE(control, expected[i].type());
        }
    }
    return GTK_IS_BOX(control); /* unknown kinds: an empty box */
}

static gboolean self_test_close(gpointer data)
{
    (void) data;
    close_requested(H.window, NULL);
    return G_SOURCE_REMOVE;
}

static gboolean self_test_poll(gpointer data)
{
    (void) data;
    View *label = view_get(2);
    const char *text = NULL != label && GTK_IS_LABEL(label->control) ? gtk_label_get_text(GTK_LABEL(label->control)) : NULL;

    if (0 != g_strcmp0(text, "Hello Ada!")) {
        if (g_get_monotonic_time() > H.test_deadline) {
            self_test_fail("label 2 reads \"%s\", not \"Hello Ada!\", after 5 s", NULL != text ? text : "(no label)");
            return G_SOURCE_REMOVE;
        }
        return G_SOURCE_CONTINUE;
    }
    fprintf(stderr, "self-test: label 2 reads \"Hello Ada!\"\n");

    /* A native control for every node of the tree. */
    GHashTableIter iter;
    gpointer value;
    guint count = 0;
    GString *kinds = g_string_new(NULL);
    g_hash_table_iter_init(&iter, H.tree->nodes);
    while (g_hash_table_iter_next(&iter, NULL, &value)) {
        TuiNode *node = value;
        View *view = view_get(node->id);
        if (NULL == view || NULL == view->control || !control_matches(node->kind, view->control)) {
            self_test_fail("node %" G_GINT64_FORMAT " (%s) has no native control", node->id, node->kind);
            g_string_free(kinds, TRUE);
            return G_SOURCE_REMOVE;
        }
        if (NULL == strstr(kinds->str, node->kind)) {
            g_string_append_printf(kinds, "%s%s", kinds->len > 0 ? " " : "", node->kind);
        }
        count++;
    }
    fprintf(stderr, "self-test: %u nodes, each with its native control (%s)\n", count, kinds->str);
    g_string_free(kinds, TRUE);
    if (NULL != g_getenv("TUI_APP_SELF_TEST_HOLD")) {
        /* For screenshots: stay up for the given seconds before closing. */
        guint seconds = (guint) g_ascii_strtoull(g_getenv("TUI_APP_SELF_TEST_HOLD"), NULL, 10);
        H.test_stage = 3;
        g_timeout_add_seconds(seconds, self_test_close, NULL);
        if (0 != H.test_timer) {
            g_source_remove(H.test_timer);
            H.test_timer = g_timeout_add_seconds(SELF_TEST_SECONDS + seconds, self_test_timed_out, NULL);
        }
        return G_SOURCE_REMOVE;
    }
    H.test_stage = 3;
    close_requested(H.window, NULL);
    return G_SOURCE_REMOVE;
}

static void self_test_patch_drawn(void)
{
    if (!H.self_test || 0 != H.test_stage) {
        return;
    }
    H.test_stage = 1;
    View *field = view_get(3);
    if (NULL == field || !GTK_IS_ENTRY(field->control)) {
        self_test_fail("no text field for node 3 after the first patch");
        return;
    }
    fprintf(stderr, "self-test: first patch drawn; typing \"Ada\" into node 3\n");
    /* As a user would: through the toolkit, so the change handler runs, then the entry's activation. */
    gtk_widget_grab_focus(field->control);
    gtk_editable_set_text(GTK_EDITABLE(field->control), "Ada");
    /* Enter lands in the entry's GtkText, whose activation the entry passes on as its own. */
    if (!gtk_widget_activate(GTK_WIDGET(gtk_editable_get_delegate(GTK_EDITABLE(field->control))))) {
        g_signal_emit_by_name(field->control, "activate");
    }
    H.test_stage = 2;
    H.test_deadline = g_get_monotonic_time() + LABEL_WAIT_US;
    g_timeout_add(50, self_test_poll, NULL);
}

/* ======================================================================== */
/* Feature test                                                             */
/* ======================================================================== */

/* --feature-test (HOSTS.md): notify, progress, beep, menus, appearance, open and the close flow
 * down to the force-quit question, each through GTK, GIO or D-Bus as a user or the desktop would,
 * against an application playing the feature scenario. Label node 2 shows what it got. */

static void ft_say(const char *format, ...) G_GNUC_PRINTF(1, 2);

static void ft_say(const char *format, ...)
{
    va_list args;
    va_start(args, format);
    g_autofree char *text = g_strdup_vprintf(format, args);
    va_end(args);
    printf("feature-test [%.2fs]: %s\n", (double) (g_get_monotonic_time() - H.ft_begin) / G_USEC_PER_SEC, text);
    fflush(stdout);
}

static void ft_fail(const char *format, ...)
{
    va_list args;
    va_start(args, format);
    g_autofree char *reason = g_strdup_vprintf(format, args);
    va_end(args);
    if (!H.feature_test || H.ft_done) {
        return;
    }
    H.ft_done = TRUE;
    fflush(stdout);
    fprintf(stderr, "feature-test: FAILED: %s\n", reason);
    quit_with(1);
}

static gboolean ft_timed_out(gpointer data)
{
    (void) data;
    ft_fail("no result after %d s, while %s", FEATURE_TEST_SECONDS, NULL != H.ft_stage ? H.ft_stage : "waiting for the first patch");
    return G_SOURCE_REMOVE;
}

static const char *ft_label(void)
{
    View *label = view_get(2);
    return NULL != label && GTK_IS_LABEL(label->control) ? gtk_label_get_text(GTK_LABEL(label->control)) : NULL;
}

static gboolean ft_poll(gpointer data)
{
    (void) data;
    if (H.ft_done || NULL == H.ft_wait_for) {
        return G_SOURCE_REMOVE;
    }
    if (0 == g_strcmp0(ft_label(), H.ft_wait_for)) {
        ft_say("node 2 reads \"%s\"", H.ft_wait_for);
        g_clear_pointer(&H.ft_wait_for, g_free);
        H.ft_then();
        return G_SOURCE_REMOVE;
    }
    if (g_get_monotonic_time() > H.ft_wait_until) {
        ft_fail("waited 5 s for node 2 to read \"%s\"; it reads \"%s\"", H.ft_wait_for, NULL != ft_label() ? ft_label() : "(no label)");
        return G_SOURCE_REMOVE;
    }
    return G_SOURCE_CONTINUE;
}

static void ft_wait_label(const char *text, FeatureStep then)
{
    g_free(H.ft_wait_for);
    H.ft_wait_for = g_strdup(text);
    H.ft_wait_until = g_get_monotonic_time() + 5 * G_USEC_PER_SEC;
    H.ft_then = then;
    g_timeout_add(50, ft_poll, NULL);
}

static void ft_run(gpointer data)
{
    FeatureStep step;
    memcpy(&step, data, sizeof step);
    g_free(data);
    if (!H.ft_done) {
        step();
    }
}

static void ft_after(guint ms, FeatureStep step)
{
    g_timeout_add_once(ms, ft_run, g_memdup2(&step, sizeof step));
}

/* What GTK drew for a widget, as a PNG in a directory. */
static gboolean save_png(GtkWidget *widget, const char *dir, const char *name)
{
    int width = gtk_widget_get_width(widget);
    int height = gtk_widget_get_height(widget);
    if (NULL == dir || '\0' == *dir || width <= 0 || height <= 0) {
        return FALSE;
    }
    g_autoptr(GdkPaintable) paintable = gtk_widget_paintable_new(widget);
    GtkSnapshot *snapshot = gtk_snapshot_new();
    gdk_paintable_snapshot(paintable, GDK_SNAPSHOT(snapshot), width, height);
    g_autoptr(GskRenderNode) node = gtk_snapshot_free_to_node(snapshot);
    if (NULL == node) {
        return FALSE;
    }
    graphene_rect_t bounds = GRAPHENE_RECT_INIT(0, 0, (float) width, (float) height);
    g_autoptr(GdkTexture) texture = gsk_renderer_render_texture(gtk_native_get_renderer(gtk_widget_get_native(widget)), node, &bounds);
    g_autofree char *path = g_build_filename(dir, name, NULL);
    return gdk_texture_save_to_png(texture, path);
}

/* What GTK drew for a widget, as a PNG in $TUI_APP_FEATURE_CAPTURE. */
static void ft_save(GtkWidget *widget, const char *name)
{
    if (save_png(widget, g_getenv("TUI_APP_FEATURE_CAPTURE"), name)) {
        ft_say("drew %s (%dx%d px)", name, gtk_widget_get_width(widget), gtk_widget_get_height(widget));
    }
}

static void ft_close_kept_open(void);
static void ft_open_while_running(void);
static void ft_appearance(void);
static void ft_menu(void);

/* 1. notify, progress and beep came before "ready". */
static void ft_system_messages(void)
{
    H.ft_stage = "checking notify, progress and beep";
    if (1 != H.ft_notifies) {
        ft_fail("expected 1 notify, got %d", H.ft_notifies);
        return;
    }
    ft_say("notify received and handed to GNotification (g_application_send_notification)");
    if (!H.ft_progress_emitted || 0 != g_strcmp0(H.ft_progress_state, "normal") || fabs(H.ft_progress - 0.5) > 1e-9) {
        ft_fail("expected the launcher progress normal 0.5 on D-Bus, got %s %s %g", H.ft_progress_emitted ? "emitted" : "not emitted",
            NULL != H.ft_progress_state ? H.ft_progress_state : "(no state)", H.ft_progress);
        return;
    }
    ft_say("progress normal 0.5 emitted as com.canonical.Unity.LauncherEntry.Update");
    if (1 != H.ft_beeps) {
        ft_fail("expected 1 beep, got %d", H.ft_beeps);
        return;
    }
    ft_say("beep received and played with gdk_display_beep()");
    ft_save(GTK_WIDGET(H.window), "window.png");
    ft_menu();
}

static void ft_menu_wrap(void);

/* 2. File > Save through the accelerator of its shortcut, then File > Wrap lines from the menu model. */
static void ft_menu(void)
{
    H.ft_stage = "menu";
    g_auto(GStrv) actions = gtk_application_get_actions_for_accel(H.app, "<Control>s");
    if (NULL == actions || NULL == actions[0]) {
        ft_fail("ctrl+s is not the accelerator of any action");
        return;
    }
    if (!gtk_widget_activate_action(GTK_WIDGET(H.window), actions[0], NULL)) {
        ft_fail("the window cannot activate %s, the action of ctrl+s", actions[0]);
        return;
    }
    ft_say("pressed ctrl+s: its accelerator activated %s", actions[0]);
    ft_wait_label("menu: file.save", ft_menu_wrap);
}

/* The detailed action of the menu item with this label, searched through sections and submenus. */
static char *ft_menu_action(GMenuModel *model, const char *label)
{
    for (int i = 0; NULL != model && i < g_menu_model_get_n_items(model); i++) {
        g_autofree char *text = NULL;
        g_autofree char *action = NULL;
        if (g_menu_model_get_item_attribute(model, i, G_MENU_ATTRIBUTE_LABEL, "s", &text) && 0 == g_strcmp0(text, label)
            && g_menu_model_get_item_attribute(model, i, G_MENU_ATTRIBUTE_ACTION, "s", &action)) {
            return g_steal_pointer(&action);
        }
        const char *links[] = {G_MENU_LINK_SECTION, G_MENU_LINK_SUBMENU};
        for (guint l = 0; l < G_N_ELEMENTS(links); l++) {
            g_autoptr(GMenuModel) inner = g_menu_model_get_item_link(model, i, links[l]);
            char *found = ft_menu_action(inner, label);
            if (NULL != found) {
                return found;
            }
        }
    }
    return NULL;
}

static void ft_menu_wrap(void)
{
    g_autofree char *action = ft_menu_action(gtk_popover_menu_bar_get_menu_model(GTK_POPOVER_MENU_BAR(H.menubar)), "Wrap lines");
    if (NULL == action || !g_str_has_prefix(action, "tui.")) {
        ft_fail("no File > Wrap lines item in the menu bar");
        return;
    }
    g_autoptr(GVariant) state = g_action_group_get_action_state(G_ACTION_GROUP(H.menu_actions), action + 4);
    if (NULL == state || !g_variant_get_boolean(state)) {
        ft_fail("File > Wrap lines is not checked");
        return;
    }
    gtk_widget_activate_action(GTK_WIDGET(H.window), action, NULL);
    ft_say("chose File > Wrap lines (checked)");
    ft_wait_label("menu: file.wrap", ft_appearance);
}

/* 3. The appearance flips, then comes back: through the portal when it decides, else through the GTK theme name, as XSETTINGS sets it. */
static gboolean ft_original_dark;

/* FALSE when the stage is over: skipped or failed. */
static gboolean ft_set_appearance(gboolean dark)
{
    if (H.portal_scheme) {
        /* Only tests/fake-desktop.py has this method: a real portal cannot be flipped from here. */
        g_autoptr(GError) error = NULL;
        g_autoptr(GVariant) answer = g_dbus_proxy_call_sync(H.portal, "org.tuiapp.test.FakeDesktop.SetColorScheme",
            g_variant_new("(u)", dark ? 1u : 2u), G_DBUS_CALL_FLAGS_NONE, 2000, NULL, &error);
        if (NULL == answer) {
            if (g_error_matches(error, G_DBUS_ERROR, G_DBUS_ERROR_UNKNOWN_METHOD) || g_error_matches(error, G_DBUS_ERROR, G_DBUS_ERROR_UNKNOWN_INTERFACE)
                || g_error_matches(error, G_DBUS_ERROR, G_DBUS_ERROR_UNKNOWN_OBJECT)) {
                ft_say("the desktop's portal decides the appearance, and a test cannot switch it; skipped");
                ft_open_while_running();
            } else {
                ft_fail("cannot switch the portal's color-scheme: %s", error->message);
            }
            return FALSE;
        }
        ft_say("the portal switched color-scheme to %s", dark ? "dark" : "light");
        return TRUE;
    }
    if (NULL == H.ft_theme) {
        g_object_get(gtk_settings_get_default(), "gtk-theme-name", &H.ft_theme, NULL);
    }
    const char *theme = dark == ft_original_dark ? H.ft_theme : (dark ? "Adwaita-dark" : "Adwaita");
    if (H.user_prefers_dark && !dark) {
        ft_say("gtk-application-prefer-dark-theme is set: a theme cannot make it light; skipped");
        ft_open_while_running();
        return FALSE;
    }
    g_object_set(gtk_settings_get_default(), "gtk-theme-name", theme, NULL);
    ft_say("set the GTK theme to %s, as XSETTINGS does", theme);
    return TRUE;
}

static void ft_appearance_back(void)
{
    if (!ft_set_appearance(ft_original_dark)) {
        return;
    }
    ft_wait_label(ft_original_dark ? "appearance: dark" : "appearance: light", ft_open_while_running);
}

static void ft_appearance(void)
{
    H.ft_stage = "appearance";
    if (0 != g_strcmp0(H.manifest->theme, "system") && NULL != H.manifest->theme) {
        ft_say("window.theme is %s: appearance changes are not sent; skipped", H.manifest->theme);
        ft_open_while_running();
        return;
    }
    ft_original_dark = H.dark;
    if (!ft_set_appearance(!ft_original_dark)) {
        return;
    }
    ft_wait_label(ft_original_dark ? "appearance: light" : "appearance: dark", ft_appearance_back);
}

/* 4. A second launch hands a file and a URL to this instance through D-Bus, as the desktop entry's -- %U passes them. */
static char *ft_expected_open;
static char *ft_open_file;

static void ft_second_launch_done(GObject *source, GAsyncResult *result, gpointer data)
{
    g_autoptr(GError) error = NULL;
    (void) data;
    if (!g_subprocess_wait_check_finish(G_SUBPROCESS(source), result, &error)) {
        ft_fail("the second launch failed: %s", error->message);
        return;
    }
    ft_say("the second launch exited 0");
}

static void ft_opened(void)
{
    g_autofree char *dir = g_path_get_dirname(ft_open_file);
    g_unlink(ft_open_file);
    g_rmdir(dir);
    ft_close_kept_open();
}

static void ft_open_while_running(void)
{
    H.ft_stage = "open while running";
    if (!H.unique || NULL == H.manifest_path) {
        ft_say("open while running: skipped, a second launch hands its files over only with singleInstance and a manifest");
        ft_close_kept_open();
        return;
    }
    g_autofree char *dir = g_dir_make_tmp("tui-feature-XXXXXX", NULL);
    g_autofree char *file = g_build_filename(dir, "-feature-test.fake", NULL);
    g_file_set_contents(file, "x", 1, NULL);
    const char *url = "fake-tuiapp://feature?id=42";
    g_autoptr(GSubprocessLauncher) launcher = g_subprocess_launcher_new(G_SUBPROCESS_FLAGS_NONE);
    g_subprocess_launcher_set_cwd(launcher, dir);
    g_autofree char *self = g_file_read_link("/proc/self/exe", NULL);
    g_autoptr(GError) error = NULL;
    /* A relative name that looks like an option, after --: still a file. */
    g_autoptr(GSubprocess) second = g_subprocess_launcher_spawn(launcher, &error, self, "--manifest", H.manifest_path, "--", "-feature-test.fake", url, NULL);
    if (NULL == second) {
        ft_fail("cannot launch a second instance: %s", error->message);
        return;
    }
    ft_say("launched a second instance: %s --manifest … -- -feature-test.fake %s", self, url);
    g_subprocess_wait_check_async(second, NULL, ft_second_launch_done, NULL);
    g_free(ft_expected_open);
    ft_expected_open = g_strdup_printf("open: %s | %s", file, url);
    g_free(ft_open_file);
    ft_open_file = g_strdup(file);
    ft_wait_label(ft_expected_open, ft_opened);
}

/* 5. The close button: the application refuses. 6. No answer: Wait. 7. It hangs: Force Quit. */
static void ft_still_open(void);

static void ft_close_kept_open(void)
{
    H.ft_stage = "close, kept open";
    gtk_window_close(H.window);
    ft_say("clicked the close button");
    ft_wait_label("close: kept open", ft_still_open);
}

static void ft_close_unanswered(void)
{
    H.ft_stage = "close, no answer";
    gtk_window_close(H.window);
    ft_say("clicked the close button; the application does not answer");
}

static void ft_check_open(void)
{
    if (NULL == H.window || !gtk_widget_get_visible(GTK_WIDGET(H.window))) {
        ft_fail("the window closed while %s", H.ft_stage);
    }
}

static void ft_still_open(void)
{
    ft_after(500, ft_check_open);
    ft_after(600, ft_close_unanswered);
    ft_say("the application kept the window open (close-reply false)");
}

static void ft_close_hung(void)
{
    ft_check_open();
    if (H.ft_done) {
        return;
    }
    ft_say("Wait kept the window open");
    H.ft_stage = "close, hung";
    gtk_window_close(H.window);
    ft_say("clicked the close button; the application hangs and ignores SIGTERM");
}

static GtkWidget *ft_find_button(GtkWidget *widget, const char *label)
{
    if (GTK_IS_BUTTON(widget) && 0 == g_strcmp0(gtk_button_get_label(GTK_BUTTON(widget)), label)) {
        return widget;
    }
    for (GtkWidget *child = gtk_widget_get_first_child(widget); NULL != child; child = gtk_widget_get_next_sibling(child)) {
        GtkWidget *found = ft_find_button(child, label);
        if (NULL != found) {
            return found;
        }
    }
    return NULL;
}

/* The force-quit dialog, a toplevel on top of the window: answered as a user would, by clicking. */
static void ft_answer_dialog(void)
{
    GListModel *toplevels = gtk_window_get_toplevels();
    GtkWindow *dialog = NULL;
    for (guint i = 0; i < g_list_model_get_n_items(toplevels); i++) {
        g_autoptr(GtkWindow) window = g_list_model_get_item(toplevels, i);
        if (window != H.window && gtk_widget_get_visible(GTK_WIDGET(window)) && gtk_window_get_transient_for(window) == H.window) {
            dialog = window;
        }
    }
    if (NULL == dialog) {
        ft_fail("no force-quit dialog on the window");
        return;
    }
    g_autofree char *name = g_strdup_printf("alert-%d.png", H.ft_alerts);
    ft_save(GTK_WIDGET(dialog), name);
    const char *answer = 1 == H.ft_alerts ? "Wait" : "Force Quit";
    GtkWidget *button = ft_find_button(GTK_WIDGET(dialog), answer);
    if (NULL == button) {
        ft_fail("the force-quit dialog has no %s button", answer);
        return;
    }
    ft_say("clicking %s", answer);
    if (1 == H.ft_alerts) {
        ft_after(500, ft_close_hung);
    } else {
        H.ft_stage = "force quit";
    }
    g_signal_emit_by_name(button, "clicked");
}

static void ft_force_quit_offered(void)
{
    H.ft_alerts++;
    g_autofree char *question = g_strdup_printf("%s is not responding. Force quit?", H.manifest->name);
    ft_say("the force-quit dialog is up: \"%s\"", question);
    ft_after(500, ft_answer_dialog);
}

static void ft_process_exited(void)
{
    if (H.ft_done) {
        return;
    }
    if (0 != g_strcmp0(H.ft_stage, "force quit")) {
        ft_fail("the application exited (%s %d) while %s", H.exited_normally ? "status" : "signal", H.exited_normally ? H.exit_status : H.ft_signal, H.ft_stage);
        return;
    }
    if (SIGKILL != H.ft_signal) {
        ft_fail("the force-quit exit is %s %d, not SIGKILL", H.exited_normally ? "status" : "signal", H.exited_normally ? H.exit_status : H.ft_signal);
        return;
    }
    ft_say("the application was killed (SIGKILL); no error dialog");
}

static void ft_window_closed(void)
{
    if (H.ft_done) {
        return;
    }
    if (0 != g_strcmp0(H.ft_stage, "force quit") || !H.exited) {
        ft_fail("the window closed while %s", H.ft_stage);
        return;
    }
    gint64 pid = NULL != H.ft_pid ? g_ascii_strtoll(H.ft_pid, NULL, 10) : 0;
    if (pid <= 0 || 0 == kill((pid_t) pid, 0) || ESRCH != errno) {
        ft_fail("process %s is still there", NULL != H.ft_pid ? H.ft_pid : "(unknown)");
        return;
    }
    ft_say("the window closed; process %s is gone", H.ft_pid);
    ft_say("ok");
    H.ft_done = TRUE;
    H.status = 0;
}

static void ft_patch_drawn(void)
{
    if (!H.feature_test || NULL != H.ft_stage) {
        return;
    }
    H.ft_pid = NULL != H.child ? g_strdup(g_subprocess_get_identifier(H.child)) : NULL;
    if (NULL != H.ft_last_open) {
        ft_say("open at launch: %s", H.ft_last_open);
    }
    H.ft_stage = "waiting for \"ready\"";
    static char *expected;
    g_free(expected);
    expected = NULL != H.ft_last_open ? g_strdup_printf("open: %s", H.ft_last_open) : g_strdup("ready");
    ft_wait_label(expected, ft_system_messages);
}


/* ======================================================================== */
/* Canvas test                                                              */
/* ======================================================================== */

/* --canvas-test (HOSTS.md): native controls over a canvas, Tab handed to the application, input methods
 * and rects after a resize, against tools/fake-app.php with FAKE_APP_CANVAS=1. Keys are real X key
 * presses (XTEST, through xdotool) that GTK and its input method get like any other. */

static void ct_say(const char *format, ...) G_GNUC_PRINTF(1, 2);

static void ct_say(const char *format, ...)
{
    va_list args;
    va_start(args, format);
    g_autofree char *text = g_strdup_vprintf(format, args);
    va_end(args);
    printf("canvas-test [%.2fs]: %s\n", (double) (g_get_monotonic_time() - H.ct_begin) / G_USEC_PER_SEC, text);
    fflush(stdout);
}

static void ct_fail(const char *format, ...)
{
    va_list args;
    va_start(args, format);
    g_autofree char *reason = g_strdup_vprintf(format, args);
    va_end(args);
    if (!H.canvas_test || H.ct_done) {
        return;
    }
    H.ct_done = TRUE;
    fflush(stdout);
    fprintf(stderr, "canvas-test: FAILED: %s\n", reason);
    quit_with(1);
}

static gboolean ct_timed_out(gpointer data)
{
    (void) data;
    ct_fail("not done after %d s, while %s", CANVAS_TEST_SECONDS, NULL != H.ct_stage ? H.ct_stage : "waiting for the first patch");
    return G_SOURCE_REMOVE;
}

static char *ct_wait_for;
static const char *ct_wait_also; /* another way the application may say it */
static gint64 ct_wait_until;
static FeatureStep ct_then;

static gboolean ct_poll(gpointer data)
{
    (void) data;
    if (H.ct_done || NULL == ct_wait_for) {
        return G_SOURCE_REMOVE;
    }
    if (0 == g_strcmp0(ft_label(), ct_wait_for) || (NULL != ct_wait_also && 0 == g_strcmp0(ft_label(), ct_wait_also))) {
        ct_say("node 2 reads \"%s\"", ft_label());
        ct_wait_also = NULL;
        g_clear_pointer(&ct_wait_for, g_free);
        ct_then();
        return G_SOURCE_REMOVE;
    }
    if (g_get_monotonic_time() > ct_wait_until) {
        ct_fail("waited 5 s for node 2 to read \"%s\" while %s; it reads \"%s\"", ct_wait_for, H.ct_stage, NULL != ft_label() ? ft_label() : "(no label)");
        return G_SOURCE_REMOVE;
    }
    return G_SOURCE_CONTINUE;
}

static void ct_wait_label(const char *text, FeatureStep then)
{
    g_free(ct_wait_for);
    ct_wait_for = g_strdup(text);
    ct_wait_until = g_get_monotonic_time() + 5 * G_USEC_PER_SEC;
    ct_then = then;
    g_timeout_add(50, ct_poll, NULL);
}

static void ct_run(gpointer data)
{
    FeatureStep step;
    memcpy(&step, data, sizeof step);
    g_free(data);
    if (!H.ct_done) {
        step();
    }
}

static void ct_after(guint ms, FeatureStep step)
{
    g_timeout_add_once(ms, ct_run, g_memdup2(&step, sizeof step));
}

static char *ct_xid(void)
{
#ifdef GDK_WINDOWING_X11
    GdkSurface *surface = gtk_native_get_surface(GTK_NATIVE(H.window));
    if (GDK_IS_X11_SURFACE(surface)) {
        return g_strdup_printf("%lu", (unsigned long) gdk_x11_surface_get_xid(surface));
    }
#endif
    return NULL;
}

/* Real key presses on the window, through the X server: xdotool, with the window focused. */
static gboolean ct_xdotool(const char *const *args)
{
    g_autofree char *xid = ct_xid();
    g_autoptr(GError) error = NULL;
    int status = 0;
    if (NULL == xid) {
        ct_fail("not on X11: the canvas test types with xdotool (run it with GDK_BACKEND=x11)");
        return FALSE;
    }
    GPtrArray *argv = g_ptr_array_new();
    g_ptr_array_add(argv, "xdotool");
    g_ptr_array_add(argv, "windowfocus");
    g_ptr_array_add(argv, "--sync");
    g_ptr_array_add(argv, xid);
    for (guint i = 0; NULL != args[i]; i++) {
        g_ptr_array_add(argv, (char *) args[i]);
    }
    g_ptr_array_add(argv, NULL);
    gboolean ran = g_spawn_sync(NULL, (char **) argv->pdata, NULL, G_SPAWN_SEARCH_PATH | G_SPAWN_STDOUT_TO_DEV_NULL, NULL, NULL, NULL, NULL, &status, &error);
    g_ptr_array_free(argv, TRUE);
    if (!ran || !g_spawn_check_wait_status(status, NULL)) {
        ct_fail("xdotool failed: %s", NULL != error ? error->message : "it exited non-zero");
        return FALSE;
    }
    return TRUE;
}

static void ct_keys(const char *const *keys)
{
    GPtrArray *args = g_ptr_array_new();
    g_ptr_array_add(args, "key");
    g_ptr_array_add(args, "--clearmodifiers");
    for (guint i = 0; NULL != keys[i]; i++) {
        g_ptr_array_add(args, (char *) keys[i]);
    }
    g_ptr_array_add(args, NULL);
    ct_xdotool((const char *const *) args->pdata);
    g_ptr_array_free(args, TRUE);
}

static void ct_capture(GtkWidget *widget, const char *name)
{
    const char *dir = g_getenv("TUI_APP_CANVAS_CAPTURE");
    if (NULL == dir || '\0' == *dir) {
        return;
    }
    /* What GTK drew, and what the X server shows of the window. */
    if (save_png(widget, dir, name)) {
        ct_say("drew %s (%dx%d px)", name, gtk_widget_get_width(widget), gtk_widget_get_height(widget));
    }
    g_autofree char *xid = ct_xid();
    g_autofree char *base = g_strndup(name, strlen(name) - (g_str_has_suffix(name, ".png") ? 4 : 0));
    g_autofree char *file = g_strdup_printf("%s/%s-x11.png", dir, base);
    const char *argv[] = {"import", "-window", xid, file, NULL};
    int status = 0;
    if (NULL != xid && g_spawn_sync(NULL, (char **) argv, NULL, G_SPAWN_SEARCH_PATH | G_SPAWN_STDOUT_TO_DEV_NULL | G_SPAWN_STDERR_TO_DEV_NULL, NULL, NULL, NULL, NULL,
                           &status, NULL)
        && g_spawn_check_wait_status(status, NULL)) {
        ct_say("captured the X window as %s", file);
    }
}

/* The mouse away from canvas 21: over text field 3, above it */
static gboolean ct_mouse_at(GtkWidget *widget, double x, double y, const char *const *then);

static void ct_park_mouse(void)
{
    GtkWidget *field = view_get(3)->control;
    ct_mouse_at(field, gtk_widget_get_width(field) / 2.0, gtk_widget_get_height(field) / 2.0, NULL);
}

static View *ct_view(gint64 id, const char *kind)
{
    View *view = view_get(id);
    if (NULL == view || 0 != g_strcmp0(view->kind, kind)) {
        ct_fail("node %" G_GINT64_FORMAT " is not a %s", id, kind);
        return NULL;
    }
    return view;
}

/* The events sent from here on (H.ct_events), to check that a step sends one change, and nothing before it */
static gsize ct_events_mark;

static void ct_events_from_here(void)
{
    ct_events_mark = H.ct_events->len;
}

static gboolean ct_events_are(const char *expected, const char *what)
{
    const char *sent = H.ct_events->str + MIN(ct_events_mark, H.ct_events->len);
    if (0 != strcmp(sent, expected)) {
        ct_fail("%s sent \"%s\", not \"%s\"", what, sent, expected);
        return FALSE;
    }
    return TRUE;
}

/* Retypes the text of node 37 (keys, NULL-terminated, at most 7), then waits for node 2 to read <label>. The text is
 * selected through GtkEditable, not Ctrl+A, whose Ctrl a loaded X server can let go before the A; and when what is
 * typed does not come out as <label> within 3 s (a key lost), it is selected and typed again, three times at most. */
static const char *ct_retype_keys[8];
static char *ct_retype_label;
static FeatureStep ct_retype_then;
static int ct_retype_tries;
static gint64 ct_retype_until;

static gboolean ct_retype_poll(gpointer data);

static void ct_retype_once(void)
{
    GtkEditable *field = GTK_EDITABLE(view_get(37)->control);
    int start = 0;
    int end = 0;
    gtk_widget_grab_focus(GTK_WIDGET(field));
    gtk_editable_select_region(field, 0, -1);
    if (!gtk_editable_get_selection_bounds(field, &start, &end) || 0 != start || g_utf8_strlen(gtk_editable_get_text(field), -1) != end) {
        if ('\0' != *gtk_editable_get_text(field)) {
            ct_fail("node 37 does not select its text \"%s\" to retype it", gtk_editable_get_text(field));
            return;
        }
    }
    ct_retype_tries++;
    ct_retype_until = g_get_monotonic_time() + 3 * G_USEC_PER_SEC;
    ct_keys(ct_retype_keys);
    g_timeout_add(50, ct_retype_poll, NULL);
}

static gboolean ct_retype_poll(gpointer data)
{
    (void) data;
    if (H.ct_done) {
        return G_SOURCE_REMOVE;
    }
    if (0 == g_strcmp0(ft_label(), ct_retype_label)) {
        if (ct_retype_tries > 1) {
            ct_say("typed again (%d tries) for \"%s\"", ct_retype_tries, ct_retype_label);
        }
        ct_retype_then();
        return G_SOURCE_REMOVE;
    }
    if (g_get_monotonic_time() > ct_retype_until) {
        if (ct_retype_tries >= 3) {
            ct_fail("typed three times for node 2 to read \"%s\" while %s; it reads \"%s\"", ct_retype_label, H.ct_stage, NULL != ft_label() ? ft_label() : "(no label)");
        } else {
            ct_retype_once();
        }
        return G_SOURCE_REMOVE;
    }
    return G_SOURCE_CONTINUE;
}

static void ct_retype(const char *const *keys, const char *label, FeatureStep then)
{
    guint n = 0;
    for (; NULL != keys[n] && n < G_N_ELEMENTS(ct_retype_keys) - 1; n++) {
        ct_retype_keys[n] = keys[n];
    }
    ct_retype_keys[n] = NULL;
    g_free(ct_retype_label);
    ct_retype_label = g_strdup(label);
    ct_retype_then = then;
    ct_retype_tries = 0;
    ct_retype_once();
}

static void ct_close(void);
static void ct_menu_canvas_removed(void);
static void ct_menu_open_canvas(void);
static void ct_resize(void);
static void ct_ime(void);
static void ct_tab(void);
static void ct_toggle(void);
static void ct_radio(void);
static void ct_select(void);
static void ct_press(void);
static void ct_max_length(void);

/* 1. The controls over the canvas, where the application placed them. */
static void ct_check_controls(void);

static void ct_laid_out(void)
{
    ct_after(300, ct_check_controls);
}

static void ct_check_controls(void)
{
    H.ct_stage = "checking the controls over canvas 21";
    View *canvas = ct_view(21, "canvas");
    static const struct {
        gint64 id;
        const char *kind;
    } children[] = {{30, "textfield"}, {31, "checkbox"}, {32, "checkbox"}, {33, "popup"}, {34, "button"}, {35, "textarea"}};
    if (NULL == canvas) {
        return;
    }
    for (guint i = 0; i < G_N_ELEMENTS(children); i++) {
        View *child = ct_view(children[i].id, children[i].kind);
        if (NULL == child) {
            return;
        }
        if (!control_matches(children[i].kind, child->control) || NULL == child->placer || gtk_widget_get_parent(child->placer) != canvas->content) {
            ct_fail("node %" G_GINT64_FORMAT " has no native control over the canvas", children[i].id);
            return;
        }
        JsonArray *place = tui_prop_array(node_of(child)->props, "place");
        graphene_rect_t bounds;
        if (!gtk_widget_get_mapped(child->outer) || !gtk_widget_compute_bounds(child->outer, canvas->control, &bounds)) {
            ct_fail("node %" G_GINT64_FORMAT " is not shown", children[i].id);
            return;
        }
        double x = json_array_get_int_element(place, 1) * H.cell_width;
        double y = json_array_get_int_element(place, 0) * H.cell_height;
        double width = json_array_get_int_element(place, 2) * H.cell_width;
        double height = json_array_get_int_element(place, 3) * H.cell_height;
        /* A control that cannot be as low as its cells is centered on them */
        double top = bounds.origin.y + (bounds.size.height - height) / 2;
        if (fabs(bounds.origin.x - x) > 1 || fabs(top - y) > 1 || fabs(bounds.size.width - width) > 1) {
            ct_fail("node %" G_GINT64_FORMAT " is at %.0f,%.0f, %.0f px wide, not at its place %.0f,%.0f, %.0f px wide", children[i].id, bounds.origin.x,
                bounds.origin.y, bounds.size.width, x, y, width);
            return;
        }
        ct_say("node %" G_GINT64_FORMAT " (%s) is a %s over the canvas at %.0f,%.0f, %.0fx%.0f px", children[i].id, children[i].kind,
            G_OBJECT_TYPE_NAME(child->control), bounds.origin.x, bounds.origin.y, bounds.size.width, bounds.size.height);
    }
    /* Textarea 35 is 3 rows high and clipped to its first one. */
    View *area = view_get(35);
    graphene_rect_t shown;
    if (!gtk_widget_compute_bounds(area->placer, canvas->control, &shown) || fabs(shown.size.height - round(9 * H.cell_height) + round(8 * H.cell_height)) > 1
        || gtk_widget_get_overflow(area->placer) != GTK_OVERFLOW_HIDDEN || gtk_widget_get_height(area->outer) < 2 * H.cell_height) {
        ct_fail("node 35 is not cut to its first row (shown %.0f px high, control %d px high)", shown.size.height, gtk_widget_get_height(area->outer));
        return;
    }
    ct_say("node 35 is %d px high, shown on its first row only (%.0f px)", gtk_widget_get_height(area->outer), shown.size.height);
    H.ct_stage = "typing into node 30";
    /* As a user would, through the toolkit: its value is Ada already, so it is cleared first. */
    gtk_editable_set_text(GTK_EDITABLE(view_get(30)->control), "");
    gtk_editable_set_text(GTK_EDITABLE(view_get(30)->control), "Ada");
    ct_wait_label("event 30 change \"Ada\"", ct_toggle);
}

/* 3d. A patch as if the application sent it */
static void ct_patch(const char *json)
{
    g_autoptr(GError) error = NULL;
    g_autoptr(TuiMessage) message = tui_message_parse(json, -1, &error);
    if (NULL == message) {
        ct_fail("the test's patch does not parse: %s", error->message);
        return;
    }
    apply_patch(message);
}

/* Tab goes through the canvas and its controls in the order of the nodes, not where they are: the test moves button
 * 34 above the others and clips popup 33 to nothing, as a patch would, goes through them as GTK's Tab does (the
 * canvas takes tab, `keys`, for the application), then puts them back. */
static void ct_key_loop_walk(void);

static void ct_key_loop(void)
{
    H.ct_stage = "walking the focus chain of canvas 21";
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":34,\"props\":{\"place\":[1,0,8,1]}},"
             "{\"op\":\"update\",\"id\":33,\"props\":{\"clip\":[5,10,0,1]}}]}");
    ct_after(300, ct_key_loop_walk);
}

/* The nodes over canvas 21 that Tab goes through from it, as GtkWindow does with Tab, and the node it leaves to */
static char *ct_walk(gint64 *left)
{
    View *canvas = view_get(21);
    GString *walked = g_string_new(NULL);
    gint64 last = 0;
    *left = 0;
    gtk_widget_grab_focus(canvas->control);
    for (int i = 0; i < 30; i++) {
        /* What GtkWindow does with Tab: on from the focus, else around from the start */
        if (!gtk_widget_child_focus(GTK_WIDGET(H.window), GTK_DIR_TAB_FORWARD)) {
            gtk_window_set_focus(H.window, NULL);
            gtk_widget_child_focus(GTK_WIDGET(H.window), GTK_DIR_TAB_FORWARD);
        }
        GtkWidget *focus = gtk_root_get_focus(GTK_ROOT(H.window));
        gint64 id = 0;
        for (GtkWidget *w = focus; NULL != w && 0 == id; w = gtk_widget_get_parent(w)) {
            id = view_id(w);
        }
        if (id < 30 || id > 39) {
            *left = id;
            break;
        }
        /* GtkListBox takes Tab row by row */
        if (id != last) {
            g_string_append_printf(walked, " %" G_GINT64_FORMAT, id);
        }
        last = id;
    }
    return g_string_free(walked, FALSE);
}

static void ct_key_loop_unfocusable(void);

static void ct_key_loop_walk(void)
{
    gint64 left = 0;
    g_autofree char *walked = ct_walk(&left);
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":34,\"props\":{\"place\":[7,10,8,1]}},"
             "{\"op\":\"update\",\"id\":33,\"props\":{\"clip\":null}}]}");
    if (0 != strcmp(walked, " 30 31 32 34 35 36 37 38 39") || 0 == left || 21 == left) {
        ct_fail("Tab from the canvas went through nodes%s, then %" G_GINT64_FORMAT ", not 30 31 32 34 35 36 37 38 39, then out of the canvas", walked, left);
        return;
    }
    ct_say("Tab from the canvas went through nodes%s in node order, skipping 33 clipped to nothing, then out of the canvas to node %" G_GINT64_FORMAT,
        walked, left);
    /* focusable false keeps the password field and the number field out of it: their GtkText, which takes the focus,
     * too */
    H.ct_stage = "walking the focus chain of canvas 21, 36 and 37 not focusable";
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":36,\"props\":{\"focusable\":false}},"
             "{\"op\":\"update\",\"id\":37,\"props\":{\"focusable\":false}}]}");
    ct_after(300, ct_key_loop_unfocusable);
}

static void ct_covered(void);

static void ct_key_loop_unfocusable(void)
{
    gint64 left = 0;
    g_autofree char *walked = ct_walk(&left);
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":36,\"props\":{\"focusable\":true}},"
             "{\"op\":\"update\",\"id\":37,\"props\":{\"focusable\":true}}]}");
    if (0 != strcmp(walked, " 30 31 32 33 34 35 38 39") || 0 == left || 21 == left) {
        ct_fail("Tab from the canvas went through nodes%s, then %" G_GINT64_FORMAT ", not 30 31 32 33 34 35 38 39 (36 and 37 not focusable), then out of the canvas", walked, left);
        return;
    }
    ct_say("with focusable false on 36 and 37, Tab from the canvas went through nodes%s", walked);
    ct_covered();
}

/* 3f. The canvas draws no characters in the cells of the controls shown over it: ─ reaches past its cell, into the
 * one before the control. Canvas 21 drawn into an image, with ─ all along row 2, where text field 30 is (columns 10
 * to 29): ink in columns 0 to 8, none in 10 to 29. */
static gboolean ct_ink(cairo_surface_t *surface, double x0, double x1, double y0, double y1)
{
    unsigned char *data = cairo_image_surface_get_data(surface);
    int stride = cairo_image_surface_get_stride(surface);
    for (int y = (int) ceil(y0); y < (int) floor(y1); y++) {
        for (int x = (int) ceil(x0); x < (int) floor(x1); x++) {
            if (0 != ((guint32 *) (void *) (data + y * stride))[x] >> 24) {
                return TRUE;
            }
        }
    }
    return FALSE;
}

static void ct_moved(void);

static void ct_covered(void)
{
    View *canvas = view_get(21);
    H.ct_stage = "drawing canvas 21 with a line under text field 30";
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":21,\"props\":{\"lines\":[[],[],[{\"t\":\"────────────────────────────────────────\"}]]}}]}");
    int width = (int) ceil(40 * H.cell_width);
    int height = (int) ceil(3 * H.cell_height);
    cairo_surface_t *surface = cairo_image_surface_create(CAIRO_FORMAT_ARGB32, width, height);
    cairo_t *cr = cairo_create(surface);
    canvas_draw(GTK_DRAWING_AREA(canvas->control), cr, width, height, canvas);
    cairo_destroy(cr);
    cairo_surface_flush(surface);
    gboolean before = ct_ink(surface, 0, 9 * H.cell_width, 2 * H.cell_height, 3 * H.cell_height);
    gboolean under = ct_ink(surface, 10 * H.cell_width + 1, 30 * H.cell_width - 1, 2 * H.cell_height + 1, 3 * H.cell_height - 1);
    gboolean other = ct_ink(surface, 0, width, 0, 2 * H.cell_height - 1);
    cairo_surface_destroy(surface);
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":21,\"props\":{\"lines\":"
             "[[{\"t\":\"# Title\",\"fg\":\"#ffaa00\",\"b\":true}],[],[{\"t\":\"• \"},{\"t\":\"item\",\"i\":true}]]}}]}");
    if (!before || under || other) {
        ct_fail("canvas 21 drew ─ %s before text field 30, %s under it, and %s on the rows above", before ? "ink" : "nothing", under ? "ink" : "nothing", other ? "ink" : "nothing");
        return;
    }
    ct_say("canvas 21 draws ─ up to text field 30, and nothing in its cells");
    ct_moved();
}

/* 3g. A text field moved into the canvas loses its prompt, and shows it again moved out; a node moved out of a
 * container that the same patch removes (a stack, tabs, a canvas) stays, and is updated, moved and removed after it:
 * the AddressSanitizer build of linux/smoke.sh catches a read of the removed container. */
static void ct_moved_in(void);
static void ct_moved_back(void);

static void ct_moved(void)
{
    H.ct_stage = "moving text field 3 into canvas 21";
    /* Its place first, then the move alone: no update of its props comes with it */
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":3,\"props\":{\"place\":[0,20,10,1]}}]}");
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"move\",\"id\":3,\"parent\":21,\"index\":0}]}");
    ct_after(300, ct_moved_in);
}

static void ct_moved_in(void)
{
    View *field = view_get(3);
    if (NULL == field->placer || gtk_widget_get_visible(field->part[0])) {
        ct_fail("text field 3 moved into canvas 21 %s, its prompt %s", NULL != field->placer ? "is over it" : "is not over it",
            gtk_widget_get_visible(field->part[0]) ? "shown" : "hidden");
        return;
    }
    H.ct_stage = "moving text field 3 back out of canvas 21";
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"move\",\"id\":3,\"parent\":1,\"index\":1}]}");
    ct_after(300, ct_moved_back);
}

static void ct_moved_out(void);

static void ct_moved_back(void)
{
    View *field = view_get(3);
    if (NULL != field->placer || !gtk_widget_get_visible(field->part[0]) || 0 != strcmp(gtk_label_get_text(GTK_LABEL(field->part[0])), "> ")) {
        ct_fail("text field 3 moved out of canvas 21 does not show its prompt \"> \"");
        return;
    }
    ct_say("text field 3 lost its prompt moved into canvas 21, and showed it again moved out");
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":3,\"props\":{\"place\":null}}]}");
    H.ct_stage = "moving nodes out of containers the same patch removes";
    ct_patch("{\"type\":\"patch\",\"ops\":["
             "{\"op\":\"create\",\"id\":900,\"kind\":\"stack\",\"parent\":1,\"index\":0,\"props\":{}},"
             "{\"op\":\"create\",\"id\":901,\"kind\":\"label\",\"parent\":900,\"index\":0,\"props\":{\"text\":\"out of a stack\"}},"
             "{\"op\":\"create\",\"id\":902,\"kind\":\"tabs\",\"parent\":1,\"index\":0,\"props\":{\"tabs\":[\"a\"]}},"
             "{\"op\":\"create\",\"id\":903,\"kind\":\"label\",\"parent\":902,\"index\":0,\"props\":{\"text\":\"out of tabs\"}},"
             "{\"op\":\"create\",\"id\":904,\"kind\":\"canvas\",\"parent\":1,\"index\":0,\"props\":{\"lines\":[[{\"t\":\"canvas\"}]]}},"
             "{\"op\":\"create\",\"id\":905,\"kind\":\"textfield\",\"parent\":904,\"index\":0,\"props\":{\"value\":\"x\",\"place\":[0,0,5,1]}}]}");
    ct_patch("{\"type\":\"patch\",\"ops\":["
             "{\"op\":\"move\",\"id\":901,\"parent\":1,\"index\":0},{\"op\":\"remove\",\"id\":900},"
             "{\"op\":\"move\",\"id\":903,\"parent\":1,\"index\":0},{\"op\":\"remove\",\"id\":902},"
             "{\"op\":\"move\",\"id\":905,\"parent\":1,\"index\":0},{\"op\":\"remove\",\"id\":904}]}");
    ct_after(200, ct_moved_out);
}

static void ct_moved_out(void)
{
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":901,\"props\":{\"text\":\"still here\"}},"
             "{\"op\":\"move\",\"id\":903,\"parent\":1,\"index\":2},{\"op\":\"move\",\"id\":905,\"parent\":1,\"index\":1}]}");
    for (gint64 id = 901; id <= 905; id += 2) {
        View *view = view_get(id);
        if (NULL == view || NULL == view->holder || view->holder->id != 1 || gtk_widget_get_parent(view->outer) != view_get(1)->content) {
            ct_fail("node %" G_GINT64_FORMAT ", moved out of a container the patch removed, is not in node 1", id);
            return;
        }
    }
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"remove\",\"id\":901},{\"op\":\"remove\",\"id\":903},{\"op\":\"remove\",\"id\":905}]}");
    ct_say("nodes moved out of a stack, tabs and a canvas removed by the same patch stayed in node 1, updated, moved, removed");
    ct_tab();
}

/* 2. The other controls, the way GTK changes them on a click. */
static void ct_toggle(void)
{
    H.ct_stage = "clicking node 31";
    gtk_widget_activate(view_get(31)->control);
    ct_wait_label("event 31 toggle false", ct_radio);
}

static void ct_radio(void)
{
    H.ct_stage = "clicking node 32";
    gtk_widget_activate(view_get(32)->control);
    ct_wait_label("event 32 toggle true", ct_select);
}

static void ct_select(void)
{
    H.ct_stage = "choosing the third choice of node 33";
    gtk_drop_down_set_selected(GTK_DROP_DOWN(view_get(33)->control), 2);
    ct_wait_label("event 33 select 2", ct_press);
}

static void ct_press(void)
{
    H.ct_stage = "clicking node 34";
    gtk_widget_activate(view_get(34)->control);
    /* tools/fake-app.php writes the missing value of press as JSON: null */
    ct_wait_label("event 34 press", ct_max_length);
    ct_wait_also = "event 34 press null";
}

/* 3. maxLength: a paste is cut and typing stops at it. Node 30 takes 40 characters (the fixture), node 35
 * takes 8 (the fake app). Real key presses, and the clipboard of the window. */
#define CT_DIGITS "0123456789012345678901234567890123456789"
#define CT_KEPT_30 "event 30 change \"Ada0123456789012345678901234567890123456\""
#define CT_KEPT_35 "event 35 change \"Notesabc\""

static void ct_max_length_pasted(void);
static void ct_max_length_typed(void);
static void ct_over_limit(void);
static void ct_native_inputs(void);
static void ct_native_inputs_start(void);
typedef gboolean (*CtCondition)(void);
static void ct_wait_for_condition(CtCondition condition, FeatureStep then);
static void ct_max_length_area(void);
static void ct_max_length_area_typed(void);
static void ct_max_length_area_done(void);

static void ct_max_length(void)
{
    H.ct_stage = "pasting past the maxLength of node 30";
    GtkWidget *entry = view_get(30)->control;
    gdk_clipboard_set_text(gtk_widget_get_clipboard(entry), CT_DIGITS);
    gtk_widget_grab_focus(entry);
    ct_keys((const char *const[]) {"End", "ctrl+v", NULL});
    ct_wait_label(CT_KEPT_30, ct_max_length_pasted);
}

static void ct_max_length_pasted(void)
{
    H.ct_stage = "typing past the maxLength of node 30";
    ct_keys((const char *const[]) {"z", NULL});
    ct_after(300, ct_max_length_typed);
}

static void ct_max_length_typed(void)
{
    const char *text = gtk_editable_get_text(GTK_EDITABLE(view_get(30)->control));
    if (0 != strcmp(text, "Ada" "0123456789012345678901234567890123456") || 0 != g_strcmp0(ft_label(), CT_KEPT_30)) {
        ct_fail("typing z at the maxLength of node 30 made it \"%s\"; node 2 reads \"%s\"", text, ft_label());
        return;
    }
    ct_say("node 30 kept 40 characters of a paste, and refused a typed z");
    ct_max_length_area();
}

static void ct_max_length_area(void)
{
    H.ct_stage = "typing past the maxLength of node 35";
    gtk_widget_grab_focus(view_get(35)->control);
    ct_keys((const char *const[]) {"ctrl+End", "a", "b", "c", "d", NULL});
    ct_wait_label(CT_KEPT_35, ct_max_length_area_typed);
}

static void ct_max_length_area_typed(void)
{
    ct_after(300, ct_max_length_area_done);
}

static void ct_max_length_area_done(void)
{
    g_autofree char *text = buffer_text(gtk_text_view_get_buffer(GTK_TEXT_VIEW(view_get(35)->control)));
    if (0 != strcmp(text, "Notesabc") || 0 != g_strcmp0(ft_label(), CT_KEPT_35)) {
        ct_fail("typing abcd into node 35 (Notes, maxLength 8) made it \"%s\"; node 2 reads \"%s\"", text, ft_label());
        return;
    }
    ct_say("node 35 took abc of a typed abcd: 8 characters");
    ct_over_limit();
}

/* 3b. A value past maxLength: the application lowered node 30's to 30 (it has 40 characters). A selection may be
 * replaced as long as the value gets no longer, and a key at the end is refused. GTK replaces a selection by
 * deleting it, then inserting. */
#define CT_OVER_KEPT "Ada" "01234567890123456789012345678901"
static void ct_over_limit_lowered(void);
static void ct_over_limit_replaced(void);
static void ct_over_limit_cut(void);
static void ct_over_limit_typed(void);

static gboolean ct_limit_lowered(void)
{
    return 30 == tui_prop_int(node_of(view_get(30))->props, "maxLength", -1);
}

static void ct_over_limit(void)
{
    H.ct_stage = "waiting for the maxLength of node 30 to go down to 30";
    ct_wait_for_condition(ct_limit_lowered, ct_over_limit_lowered);
}

static void ct_over_limit_lowered(void)
{
    if (!ct_limit_lowered()) {
        ct_fail("the maxLength of node 30 did not go down to 30");
        return;
    }
    H.ct_stage = "pasting XYZ over the last 5 of the 40 characters of node 30";
    GtkWidget *entry = view_get(30)->control;
    gdk_clipboard_set_text(gtk_widget_get_clipboard(entry), "XYZ");
    gtk_widget_grab_focus(entry);
    ct_keys((const char *const[]) {"End", "shift+Left", "shift+Left", "shift+Left", "shift+Left", "shift+Left", "ctrl+v", NULL});
    ct_wait_label("event 30 change \"" CT_OVER_KEPT "XYZ\"", ct_over_limit_replaced);
}

static void ct_over_limit_replaced(void)
{
    H.ct_stage = "pasting LMNOP over the last 2 of the 38 characters of node 30";
    gdk_clipboard_set_text(gtk_widget_get_clipboard(view_get(30)->control), "LMNOP");
    ct_keys((const char *const[]) {"End", "shift+Left", "shift+Left", "ctrl+v", NULL});
    ct_wait_label("event 30 change \"" CT_OVER_KEPT "XLM\"", ct_over_limit_cut);
}

static void ct_over_limit_cut(void)
{
    H.ct_stage = "typing past the maxLength of node 30, its value past it";
    ct_keys((const char *const[]) {"End", "z", NULL});
    ct_after(300, ct_over_limit_typed);
}

static void ct_over_limit_typed(void)
{
    const char *text = gtk_editable_get_text(GTK_EDITABLE(view_get(30)->control));
    if (0 != strcmp(text, CT_OVER_KEPT "XLM") || 0 != g_strcmp0(ft_label(), "event 30 change \"" CT_OVER_KEPT "XLM\"")) {
        ct_fail("typing z at the end of node 30 (38 characters, maxLength 30) made it \"%s\"; node 2 reads \"%s\"", text, ft_label());
        return;
    }
    ct_say("node 30, past its maxLength: XYZ replaced 5 characters, LM of LMNOP 2, a typed z nothing");
    ct_native_inputs_start();
}

/* 3c. The password field 36, number field 37, slider 38 and list box 39 (patch-native-inputs), through the toolkit
 * as a user would. The password is never printed. */
static void ct_password_pasted(void);
static void ct_number(void);
static void ct_number_up(void);
static void ct_number_down(void);
static void ct_number_spun(void);
static void ct_number_typed(void);
static void ct_number_past(void);
static void ct_number_off_step(void);
static void ct_slider(void);
static void ct_slider_keyed(void);
static void ct_slider_dragged(void);
static void ct_listbox(void);
static void ct_listbox_selected(void);
static void ct_listbox_moved(void);
static void ct_form(void);

/* Every control placed over canvas 21 (30 to 39) fits its place: its widget's bounds within its cells, as GTK
 * allocates it (a control that cannot be that small is centred on them and cut, its edges, an arrow say, over the
 * canvas, where a click is the canvas's) */
static gboolean ct_controls_fit(void)
{
    GString *over = g_string_new(NULL);
    GString *fit = g_string_new(NULL);
    for (gint64 id = 30; id <= 39; id++) {
        View *view = view_get(id);
        graphene_rect_t clip;
        graphene_rect_t bounds;
        int width;
        int height;
        if (NULL == view || NULL == view->placer || !gtk_widget_get_mapped(view->outer) || !gtk_widget_compute_bounds(view->placer, GTK_WIDGET(H.window), &clip)
            || !gtk_widget_compute_bounds(view->outer, GTK_WIDGET(H.window), &bounds)) {
            continue;
        }
        gtk_widget_get_size_request(view->outer, &width, &height);
        float x = clip.origin.x + GPOINTER_TO_INT(g_object_get_data(G_OBJECT(view->placer), "tui-dx"));
        float y = clip.origin.y + GPOINTER_TO_INT(g_object_get_data(G_OBJECT(view->placer), "tui-dy"));
        if (bounds.origin.x < x - 0.5f || bounds.origin.y < y - 0.5f || bounds.origin.x + bounds.size.width > x + width + 0.5f
            || bounds.origin.y + bounds.size.height > y + height + 0.5f) {
            g_string_append_printf(over, "%s%" G_GINT64_FORMAT " (%s) %.0fx%.0f at %.0f,%.0f over its %dx%d at %.0f,%.0f", 0 == over->len ? "" : "; ", id, view->kind,
                bounds.size.width, bounds.size.height, bounds.origin.x, bounds.origin.y, width, height, x, y);
        } else {
            g_string_append_printf(fit, " %" G_GINT64_FORMAT, id);
        }
    }
    gboolean ok = 0 == over->len;
    if (ok) {
        ct_say("the placed controls%s fit their cells", fit->str);
    } else {
        ct_fail("placed controls not in their cells: %s", over->str);
    }
    g_string_free(over, TRUE);
    g_string_free(fit, TRUE);
    return ok;
}

/* The top and bottom border of text field 30's entry, in px */
static int ct_entry_border(void)
{
    GtkBorder border = {0};
    G_GNUC_BEGIN_IGNORE_DEPRECATIONS
    gtk_style_context_get_border(gtk_widget_get_style_context(view_get(30)->control), &border);
    G_GNUC_END_IGNORE_DEPRECATIONS
    return border.top + border.bottom;
}

static void ct_two_rows(void);
static void ct_one_row_again(void);

/* Only a text field one row high loses its frame's top and bottom lines: two rows high, it keeps them */
static void ct_native_inputs_start(void)
{
    if (!ct_controls_fit()) {
        return;
    }
    H.ct_stage = "placing text field 30 on two rows";
    if (0 != ct_entry_border()) {
        ct_fail("text field 30, one row high, has a top and bottom border of %d px", ct_entry_border());
        return;
    }
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":30,\"props\":{\"place\":[2,10,20,2]}}]}");
    ct_after(300, ct_two_rows);
}

static void ct_two_rows(void)
{
    if (ct_entry_border() < 2) {
        ct_fail("text field 30, two rows high, has a top and bottom border of %d px, not its frame's", ct_entry_border());
        return;
    }
    ct_say("text field 30 two rows high keeps the top and bottom border of its frame, %d px", ct_entry_border());
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":30,\"props\":{\"place\":[2,10,20,1]}}]}");
    ct_after(300, ct_one_row_again);
}

static void ct_native_inputs(void);

static void ct_textarea_one_row(void);
static void ct_textarea_three_rows(void);

static void ct_one_row_again(void)
{
    if (0 != ct_entry_border()) {
        ct_fail("text field 30 back on one row has a top and bottom border of %d px", ct_entry_border());
        return;
    }
    /* A text area one row high fits it too: no scrollbar's height (46 px) past its cell */
    H.ct_stage = "placing text area 35 on one row";
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":35,\"props\":{\"place\":[8,10,30,1]}}]}");
    ct_after(300, ct_textarea_one_row);
}

static void ct_textarea_one_row(void)
{
    if (!ct_controls_fit()) {
        return;
    }
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":35,\"props\":{\"place\":[8,10,30,3]}}]}");
    ct_after(300, ct_textarea_three_rows);
}

static void ct_textarea_three_rows(void)
{
    GtkScrolledWindow *scrolled = GTK_SCROLLED_WINDOW(view_get(35)->outer);
    GtkPolicyType vertical = GTK_POLICY_NEVER;
    gtk_scrolled_window_get_policy(scrolled, NULL, &vertical);
    /* Three rows high again, its scrollbar back */
    if (GTK_POLICY_AUTOMATIC != vertical) {
        ct_fail("text area 35, three rows high again, has no scrollbar when it needs one (policy %d)", vertical);
        return;
    }
    ct_say("text area 35 one row high fits its cell, and three rows high has its scrollbar again");
    ct_native_inputs();
}

static void ct_native_inputs(void)
{
    if (!ct_controls_fit()) {
        return;
    }
    static const struct {
        gint64 id;
        const char *kind;
        GType (*type)(void);
    } inputs[] = {{36, "password", gtk_password_entry_get_type}, {37, "number", gtk_spin_button_get_type}, {38, "slider", gtk_scale_get_type},
        {39, "listbox", gtk_list_box_get_type}};
    H.ct_stage = "checking the native inputs";
    for (guint i = 0; i < G_N_ELEMENTS(inputs); i++) {
        View *view = ct_view(inputs[i].id, inputs[i].kind);
        if (NULL == view) {
            return;
        }
        if (!G_TYPE_CHECK_INSTANCE_TYPE(view->control, inputs[i].type()) || NULL == view->placer || !gtk_widget_get_visible(view->placer)) {
            ct_fail("node %" G_GINT64_FORMAT " is not a placed %s over the canvas", inputs[i].id, g_type_name(inputs[i].type()));
            return;
        }
        ct_say("node %" G_GINT64_FORMAT " (%s) is a %s over the canvas", inputs[i].id, inputs[i].kind, G_OBJECT_TYPE_NAME(view->control));
    }
    H.ct_stage = "typing a password into node 36";
    gtk_widget_grab_focus(view_get(36)->control);
    ct_keys((const char *const[]) {"s", "3", "c", "r", "e", "t", NULL});
    ct_wait_label("event 36 change (6 characters)", ct_password_pasted);
}

static void ct_password_pasted_done(void);

static void ct_password_pasted(void)
{
    H.ct_stage = "pasting past the maxLength of node 36";
    gdk_clipboard_set_text(gtk_widget_get_clipboard(view_get(36)->control), "0123456789");
    ct_keys((const char *const[]) {"End", "ctrl+v", NULL});
    ct_wait_label("event 36 change (8 characters)", ct_password_pasted_done);
}

static void ct_password_pasted_done(void)
{
    if (8 != g_utf8_strlen(gtk_editable_get_text(GTK_EDITABLE(view_get(36)->control)), -1)) {
        ct_fail("node 36 does not have 8 characters");
        return;
    }
    ct_say("node 36 is a secure field, cut to its maxLength 8");
    ct_number();
}

static void ct_number(void)
{
    H.ct_stage = "stepping node 37 with Up";
    gtk_widget_grab_focus(view_get(37)->control);
    ct_events_from_here();
    ct_keys((const char *const[]) {"Up", NULL});
    ct_wait_label("event 37 change \"1.5\"", ct_number_up);
}

static void ct_number_up(void)
{
    /* The new text only: not the "" of setting it */
    if (!ct_events_are("37 change \"1.5\"; ", "Up in node 37")) {
        return;
    }
    H.ct_stage = "stepping node 37 with Down";
    ct_keys((const char *const[]) {"Down", "Down", NULL});
    ct_wait_label("event 37 change \"0.5\"", ct_number_down);
}

static void ct_number_down(void)
{
    H.ct_stage = "stepping node 37 as its + button does";
    gtk_spin_button_spin(GTK_SPIN_BUTTON(view_get(37)->control), GTK_SPIN_STEP_FORWARD, 0);
    ct_wait_label("event 37 change \"1\"", ct_number_spun);
}

static void ct_number_spun(void)
{
    H.ct_stage = "typing an invalid number into node 37, then Enter";
    ct_keys((const char *const[]) {"End", "x", "Return", NULL});
    ct_wait_label("event 37 submit \"1x\"", ct_number_typed);
}

static void ct_number_typed(void)
{
    if (0 != strcmp(gtk_editable_get_text(GTK_EDITABLE(view_get(37)->control)), "1x")) {
        ct_fail("node 37 has \"%s\" after Enter, not the 1x typed", gtk_editable_get_text(GTK_EDITABLE(view_get(37)->control)));
        return;
    }
    ct_say("node 37 stepped 1.5, 1, 0.5 with Up and Down, 1 with its + button, and kept 1x as typed");
    H.ct_stage = "typing 12, past the max of node 37, then Enter";
    ct_retype((const char *const[]) {"1", "2", "Return", NULL}, "event 37 submit \"12\"", ct_number_past);
}

/* A number past max, or off the steps, is sent and kept as typed: GtkSpinButton would clamp it and snap it to the
 * steps when it is committed (Enter, the focus leaving) */
static void ct_number_left(void);
static void ct_number_off_up(void);
static void ct_number_off_typed(void);
static void ct_number_off_down(void);

/* Past max or min, Up and Down step as HTML's stepUp and stepDown: count steps on, then back within min and max, on
 * a step from min; a step the other way leaves the number as it is, nothing sent (GTK would clamp it, then step) */
static void ct_number_step_from(const char *text, const char *key, const char *expected, FeatureStep then);
static void ct_number_past_up(void);
static void ct_number_past_down(void);
static void ct_number_past_page(void);
static void ct_number_below_down(void);
static void ct_number_below_up(void);
static void ct_number_below_page(void);
static void ct_number_stepped_past(void);
static void ct_number_coarse(void);
static void ct_number_hold(void);
static void ct_number_moved_out(void);
static void ct_textarea_moved_out(void);
static gboolean ct_controls_fit(void);
static void ct_wait_sent(const char *expected, FeatureStep then);

/* The test of one press: text typed (NULL: as it is), key pressed, expected the change sent (NULL: none) */
static char *ct_step_before; /* the text before the key */
static const char *ct_step_key;
static const char *ct_step_expected;
static FeatureStep ct_step_then;
static void ct_number_step_typed(void);
static void ct_number_step_pressed(void);
static void ct_number_step_settled(void);

static void ct_number_step_from(const char *text, const char *key, const char *expected, FeatureStep then)
{
    ct_step_key = key;
    ct_step_expected = expected;
    ct_step_then = then;
    g_free(ct_step_before);
    ct_step_before = g_strdup(NULL != text ? text : gtk_editable_get_text(GTK_EDITABLE(view_get(37)->control)));
    if (NULL == text) {
        ct_number_step_typed();
        return;
    }
    g_autofree char *label = g_strdup_printf("event 37 change \"%s\"", text);
    static const char *const digits[] = {"0", "1", "2", "3", "4", "5", "6", "7", "8", "9"};
    const char *keys[8] = {NULL};
    guint n = 0;
    for (const char *c = text; '\0' != *c && n < G_N_ELEMENTS(keys) - 1; c++) {
        keys[n++] = '-' == *c ? "minus" : digits[*c - '0'];
    }
    keys[n] = NULL;
    H.ct_stage = "typing a number past the min or max of node 37";
    ct_retype(keys, label, ct_number_step_typed);
}

static void ct_number_step_typed(void)
{
    if (0 != strcmp(gtk_editable_get_text(GTK_EDITABLE(view_get(37)->control)), ct_step_before)) {
        ct_fail("node 37 has \"%s\" before %s, not %s", gtk_editable_get_text(GTK_EDITABLE(view_get(37)->control)), ct_step_key, ct_step_before);
        return;
    }
    H.ct_stage = "pressing a key in node 37 past its min or max";
    ct_events_from_here();
    ct_keys((const char *const[]) {ct_step_key, NULL});
    if (NULL != ct_step_expected) {
        g_autofree char *label = g_strdup_printf("event 37 change \"%s\"", ct_step_expected);
        ct_wait_label(label, ct_number_step_pressed);
    } else {
        ct_after(500, ct_number_step_pressed);
    }
}

static void ct_number_step_pressed(void)
{
    ct_after(200, ct_number_step_settled);
}

static void ct_number_step_settled(void)
{
    const char *before = ct_step_before;
    const char *text = gtk_editable_get_text(GTK_EDITABLE(view_get(37)->control));
    g_autofree char *expected = NULL != ct_step_expected ? g_strdup_printf("37 change \"%s\"; ", ct_step_expected) : g_strdup("");
    g_autofree char *what = g_strdup_printf("%s at %s in node 37", ct_step_key, before);
    if (!ct_events_are(expected, what)) {
        return;
    }
    if (0 != strcmp(text, NULL != ct_step_expected ? ct_step_expected : before)) {
        ct_fail("%s left \"%s\", not \"%s\"", what, text, NULL != ct_step_expected ? ct_step_expected : before);
        return;
    }
    ct_step_then();
}

static void ct_number_past(void)
{
    /* 12, above max 10, after Enter: Up would go below it, so nothing; Down, max */
    if (0 != strcmp(gtk_editable_get_text(GTK_EDITABLE(view_get(37)->control)), "12")) {
        ct_fail("node 37 has \"%s\" after Enter, not the 12 typed", gtk_editable_get_text(GTK_EDITABLE(view_get(37)->control)));
        return;
    }
    ct_number_step_from(NULL, "Up", NULL, ct_number_past_up);
}

static void ct_number_past_up(void)
{
    ct_number_step_from(NULL, "Down", "10", ct_number_past_down);
}

static void ct_number_past_down(void)
{
    ct_number_step_from("12", "Page_Down", "7", ct_number_past_page);
}

static void ct_number_past_page(void)
{
    ct_number_step_from("-3", "Down", NULL, ct_number_below_down);
}

static void ct_number_below_down(void)
{
    ct_number_step_from(NULL, "Up", "0", ct_number_below_up);
}

static void ct_number_below_up(void)
{
    ct_number_step_from("-3", "Page_Up", "2", ct_number_below_page);
}

static void ct_number_below_page(void)
{
    ct_number_stepped_past();
}

static void ct_number_stepped_past(void)
{
    ct_say("node 37 at 12, above its max: Up left it, Down 10, Page Down 7; at -3, below its min: Down left it, Up 0, Page Up 2");
    H.ct_stage = "typing 2.3, off the steps of node 37";
    ct_retype((const char *const[]) {"2", "period", "3", NULL}, "event 37 change \"2.3\"", ct_number_off_step);
}

static void ct_number_off_step(void)
{
    H.ct_stage = "moving the focus out of node 37";
    gtk_widget_grab_focus(view_get(34)->control);
    ct_after(300, ct_number_left);
}

static void ct_number_left(void)
{
    const char *text = gtk_editable_get_text(GTK_EDITABLE(view_get(37)->control));
    if (0 != strcmp(text, "2.3") || 0 != g_strcmp0(ft_label(), "event 37 change \"2.3\"")) {
        ct_fail("node 37 has \"%s\" once the focus left it, not the 2.3 typed; node 2 reads \"%s\"", text, ft_label());
        return;
    }
    ct_say("node 37 sent and kept 12, past its max, and 2.3, off its steps, when the focus left it");
    H.ct_stage = "pressing Up in node 37, at 2.3, off its steps";
    gtk_widget_grab_focus(view_get(37)->control);
    ct_events_from_here();
    ct_keys((const char *const[]) {"Up", NULL});
    ct_wait_label("event 37 change \"2.5\"", ct_number_off_up);
}

/* Up and Down from a number off the steps go to the nearest step that way, in one press: from 2.3, Up 2.5, and from
 * 2.7, the stepper's down arrow 2.5 (GTK would snap them to 2.5 first, then step: 3 and 2) */
static void ct_number_off_up(void)
{
    if (!ct_events_are("37 change \"2.5\"; ", "Up at 2.3 in node 37")) {
        return;
    }
    H.ct_stage = "typing 2.7 into node 37";
    ct_retype((const char *const[]) {"2", "period", "7", NULL}, "event 37 change \"2.7\"", ct_number_off_typed);
}

static void ct_number_off_click(void);

static void ct_number_off_typed(void)
{
    /* Its row is below the window until the window grows (6.): up to row 1, as a patch would, for the click */
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":37,\"props\":{\"place\":[1,14,12,1]}}]}");
    ct_after(300, ct_number_off_click);
}

static void ct_number_off_click(void)
{
    View *view = view_get(37);
    GtkWidget *down = NULL;
    for (GtkWidget *child = gtk_widget_get_first_child(view->control); NULL != child; child = gtk_widget_get_next_sibling(child)) {
        if (GTK_IS_BUTTON(child) && gtk_widget_has_css_class(child, "down")) {
            down = child;
        }
    }
    graphene_point_t at;
    double dx = 0;
    double dy = 0;
    if (NULL == down || !gtk_widget_compute_point(down, GTK_WIDGET(H.window), &GRAPHENE_POINT_INIT(gtk_widget_get_width(down) / 2.0f, gtk_widget_get_height(down) / 2.0f), &at)) {
        ct_fail("node 37 has no down arrow shown");
        return;
    }
    gtk_native_get_surface_transform(GTK_NATIVE(H.window), &dx, &dy);
    if (at.x + dx < 0 || at.y + dy < 0 || at.x + dx >= gtk_widget_get_width(GTK_WIDGET(H.window)) || at.y + dy >= gtk_widget_get_height(GTK_WIDGET(H.window))) {
        ct_fail("the down arrow of node 37 is out of the window, at %.0f,%.0f", at.x + dx, at.y + dy);
        return;
    }
    H.ct_stage = "clicking the down arrow of node 37, at 2.7";
    g_autofree char *xid = ct_xid();
    g_autofree char *x = g_strdup_printf("%d", (int) round(at.x + dx));
    g_autofree char *y = g_strdup_printf("%d", (int) round(at.y + dy));
    ct_events_from_here();
    if (ct_xdotool((const char *const[]) {"mousemove", "--window", NULL != xid ? xid : "", x, y, "click", "1", NULL})) {
        ct_wait_label("event 37 change \"2.5\"", ct_number_off_down);
    }
}

static void ct_number_off_down(void)
{
    /* The mouse off the canvas again: once 37 moves, it would be over the canvas (pointer) */
    ct_park_mouse();
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":37,\"props\":{\"place\":[13,10,12,1]}}]}");
    if (!ct_events_are("37 change \"2.5\"; ", "the down arrow at 2.7 in node 37")) {
        return;
    }
    ct_say("node 37 went from 2.3, off its steps, to 2.5 with Up, and from 2.7 to 2.5 with its down arrow, one change each");
    ct_number_coarse();
}

/* With max off the steps (0 to 10 by 3), the steps stay on the steps from min: from 9, the highest at or below max,
 * Up and Page Up leave it; from 6, Up and Page Up go to 9, never 10 (as the application would have it, then back) */
static void ct_number_coarse_9(void);
static void ct_number_coarse_9_up(void);
static void ct_number_coarse_6_up(void);
static void ct_number_coarse_9_again(void);
static void ct_number_coarse_done(void);

static void ct_number_coarse(void)
{
    H.ct_stage = "giving node 37 a step of 3, max 10 off its steps";
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":37,\"props\":{\"step\":3,\"value\":\"9\"}}]}");
    gtk_widget_grab_focus(view_get(37)->control);
    ct_after(300, ct_number_coarse_9);
}

static void ct_number_coarse_9(void)
{
    ct_number_step_from(NULL, "Up", NULL, ct_number_coarse_9_up);
}

static void ct_number_coarse_9_up(void)
{
    ct_number_step_from(NULL, "Page_Up", NULL, ct_number_coarse_6_up);
}

static void ct_number_coarse_6_up(void)
{
    ct_number_step_from("6", "Up", "9", ct_number_coarse_9_again);
}

static void ct_number_coarse_9_again(void)
{
    ct_number_step_from(NULL, "Up", NULL, ct_number_coarse_done);
}

static void ct_number_coarse_page(void);

static void ct_number_coarse_done(void)
{
    ct_number_step_from("6", "Page_Up", "9", ct_number_coarse_page);
}

static void ct_number_coarse_page(void)
{
    ct_say("node 37 by 3 up to 10: at 9, Up and Page Up left it; from 6, Up and Page Up went to 9, not 10");
    ct_number_hold();
}

/* Up held, and the stepper's up arrow held, repeat: 1 s from 0 by 3 up to max 100, off the steps (the host steps it,
 * not GTK), and up to max 99, on them (GTK); then back */
static const char *ct_hold_max;
static guint ct_hold_key_changes;
static char *ct_hold_key_off;
static void ct_number_hold_start(void);
static void ct_number_hold_key_up(void);
static void ct_number_hold_key_done(void);
static void ct_number_hold_arrow(void);
static void ct_number_hold_arrow_up(void);
static void ct_number_hold_arrow_done(void);

/* The changes of node 37 sent from the mark; *off_step the first one off the steps of 3, if any */
static guint ct_changes_since_mark(char **off_step)
{
    guint changes = 0;
    const char *prefix = "37 change \"";
    for (const char *at = H.ct_events->str + MIN(ct_events_mark, H.ct_events->len); NULL != (at = strstr(at, prefix)); at++) {
        double value = g_ascii_strtod(at + strlen(prefix), NULL);
        changes++;
        if (NULL != off_step && NULL == *off_step && fabs(fmod(value, 3)) > 1e-9) {
            *off_step = g_strdup_printf("%g", value);
        }
    }
    return changes;
}

static void ct_number_hold(void)
{
    ct_hold_max = "100";
    ct_number_hold_start();
}

static void ct_number_hold_start(void)
{
    g_autofree char *patch = g_strdup_printf("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":37,\"props\":{\"step\":3,\"max\":%s,"
        "\"value\":\"0\",\"place\":[1,14,12,1]}}]}", ct_hold_max);
    H.ct_stage = "holding Up in node 37";
    ct_patch(patch);
    gtk_widget_grab_focus(view_get(37)->control);
    ct_after(300, ct_number_hold_key_up);
}

static void ct_number_hold_key_up(void)
{
    ct_events_from_here();
    if (ct_xdotool((const char *const[]) {"keydown", "Up", NULL})) {
        ct_after(1000, ct_number_hold_key_done);
    }
}

static void ct_number_hold_key_counted(void);

static void ct_number_hold_key_done(void)
{
    ct_xdotool((const char *const[]) {"keyup", "Up", NULL});
    /* The repeats the X server queued before the key went up, handled */
    ct_after(300, ct_number_hold_key_counted);
}

static void ct_number_hold_key_counted(void)
{
    g_clear_pointer(&ct_hold_key_off, g_free);
    ct_hold_key_changes = ct_changes_since_mark(&ct_hold_key_off);
    /* The down arrow: the up one is past the cells of the field, over the canvas */
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":37,\"props\":{\"value\":\"60\"}}]}");
    H.ct_stage = "holding the down arrow of node 37";
    ct_after(500, ct_number_hold_arrow);
}

/* The arrow of node 37 ("up" or "down"); its middle in the window's X coordinates; NULL (failed) when it is not
 * inside the cells of the field's place, where a click goes to the canvas instead */
static GtkWidget *ct_number_arrow(const char *which, char **x, char **y)
{
    View *view = view_get(37);
    GtkWidget *arrow = NULL;
    graphene_rect_t cells;
    graphene_rect_t bounds;
    graphene_point_t at;
    double dx = 0;
    double dy = 0;
    for (GtkWidget *child = gtk_widget_get_first_child(view->control); NULL != child; child = gtk_widget_get_next_sibling(child)) {
        if (GTK_IS_BUTTON(child) && gtk_widget_has_css_class(child, which)) {
            arrow = child;
        }
    }
    if (NULL == arrow || !gtk_widget_compute_bounds(view->placer, GTK_WIDGET(H.window), &cells) || !gtk_widget_compute_bounds(arrow, GTK_WIDGET(H.window), &bounds)
        || !gtk_widget_compute_point(arrow, GTK_WIDGET(H.window), &GRAPHENE_POINT_INIT(gtk_widget_get_width(arrow) / 2.0f, gtk_widget_get_height(arrow) / 2.0f), &at)) {
        ct_fail("node 37 has no %s arrow shown", which);
        return NULL;
    }
    if (bounds.origin.x < cells.origin.x - 0.5f || bounds.origin.y < cells.origin.y - 0.5f
        || bounds.origin.x + bounds.size.width > cells.origin.x + cells.size.width + 0.5f
        || bounds.origin.y + bounds.size.height > cells.origin.y + cells.size.height + 0.5f) {
        ct_fail("the %s arrow of node 37, at %.0f,%.0f %.0fx%.0f, is not inside its place, %.0f,%.0f %.0fx%.0f", which, bounds.origin.x, bounds.origin.y,
            bounds.size.width, bounds.size.height, cells.origin.x, cells.origin.y, cells.size.width, cells.size.height);
        return NULL;
    }
    gtk_native_get_surface_transform(GTK_NATIVE(H.window), &dx, &dy);
    *x = g_strdup_printf("%d", (int) round(at.x + dx));
    *y = g_strdup_printf("%d", (int) round(at.y + dy));
    return arrow;
}

static void ct_number_hold_clicked(void);

/* Both arrows inside the place; a real click on the up one steps up, from 60 to 63 */
static void ct_number_hold_arrow(void)
{
    g_autofree char *x = NULL;
    g_autofree char *y = NULL;
    g_autofree char *down_x = NULL;
    g_autofree char *down_y = NULL;
    g_autofree char *xid = ct_xid();
    if (NULL == ct_number_arrow("down", &down_x, &down_y) || NULL == ct_number_arrow("up", &x, &y)) {
        return;
    }
    H.ct_stage = "clicking the up arrow of node 37, at 60";
    ct_events_from_here();
    if (ct_xdotool((const char *const[]) {"mousemove", "--window", NULL != xid ? xid : "", x, y, "click", "1", NULL})) {
        ct_wait_sent("37 change \"63\"; ", ct_number_hold_clicked);
    }
}

static void ct_number_hold_clicked(void)
{
    g_autofree char *x = NULL;
    g_autofree char *y = NULL;
    g_autofree char *xid = ct_xid();
    H.ct_stage = "holding the down arrow of node 37";
    if (NULL == ct_number_arrow("down", &x, &y)) {
        return;
    }
    ct_events_from_here();
    if (ct_xdotool((const char *const[]) {"mousemove", "--window", NULL != xid ? xid : "", x, y, "mousedown", "1", NULL})) {
        ct_after(1000, ct_number_hold_arrow_up);
    }
}

static void ct_number_hold_arrow_up(void)
{
    ct_xdotool((const char *const[]) {"mouseup", "1", NULL});
    ct_after(300, ct_number_hold_arrow_done);
}

static void ct_number_hold_arrow_done(void)
{
    g_autofree char *arrow_off = NULL;
    guint arrow = ct_changes_since_mark(&arrow_off);
    /* Held 1 s, a key or an arrow repeats (GTK: 500 ms, then every 50 ms), on the steps (GTK's climb rate would leave
     * them) */
    if (ct_hold_key_changes < 3 || arrow < 3 || NULL != ct_hold_key_off || NULL != arrow_off) {
        ct_fail("with max %s, Up held 1 s sent %u changes%s%s, the down arrow held 1 s from 63 %u%s%s", ct_hold_max, ct_hold_key_changes,
            NULL != ct_hold_key_off ? ", one off the steps: " : "", NULL != ct_hold_key_off ? ct_hold_key_off : "", arrow,
            NULL != arrow_off ? ", one off the steps: " : "", NULL != arrow_off ? arrow_off : "");
        return;
    }
    ct_say("with max %s, %s the steps, Up held 1 s sent %u changes; both arrows inside the place, a click on the up one 63, the down one held 1 s %u changes, all on the steps", ct_hold_max,
        0 == strcmp(ct_hold_max, "100") ? "off" : "on", ct_hold_key_changes, arrow);
    ct_park_mouse();
    if (0 == strcmp(ct_hold_max, "100")) {
        ct_hold_max = "99";
        ct_number_hold_start();
        return;
    }
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":37,\"props\":{\"step\":0.5,\"max\":10,\"value\":\"1\",\"place\":[13,10,12,1]}}]}");
    ct_number_moved_out();
}

/* Moved off the canvas into stack 1, node 37 is as wide as GTK makes a number field again, not one character; put
 * back, it fits its cells again */
static void ct_number_moved_check(void);
static void ct_number_moved_back(void);

static int ct_number_placed_width;

static void ct_number_moved_out(void)
{
    /* The width of its text placed: one character */
    gtk_widget_measure(GTK_WIDGET(gtk_editable_get_delegate(GTK_EDITABLE(view_get(37)->control))), GTK_ORIENTATION_HORIZONTAL, -1, &ct_number_placed_width, NULL, NULL,
        NULL);
    H.ct_stage = "moving node 37 off the canvas into stack 1";
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"move\",\"id\":37,\"parent\":1,\"index\":11}]}");
    ct_after(300, ct_number_moved_check);
}

static void ct_number_moved_check(void)
{
    View *view = view_get(37);
    int width = 0;
    if (NULL == view || NULL != view->placer || !gtk_widget_get_mapped(view->control)) {
        ct_fail("node 37 is not shown in stack 1 once moved there");
        return;
    }
    gtk_widget_measure(GTK_WIDGET(gtk_editable_get_delegate(GTK_EDITABLE(view->control))), GTK_ORIENTATION_HORIZONTAL, -1, &width, NULL, NULL, NULL);
    if (-1 != gtk_editable_get_width_chars(GTK_EDITABLE(view->control)) || width <= ct_number_placed_width) {
        ct_fail("node 37 in stack 1 keeps the width of its place: %d characters, its text at least %d px (%d px placed)", gtk_editable_get_width_chars(GTK_EDITABLE(view->control)),
            width, ct_number_placed_width);
        return;
    }
    ct_say("node 37 moved into stack 1 is as wide as GTK makes it, its text at least %d px, not %d px as placed", width, ct_number_placed_width);
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"move\",\"id\":37,\"parent\":21,\"index\":7}]}");
    ct_after(300, ct_number_moved_back);
}

static void ct_number_moved_back(void)
{
    if (NULL == view_get(37)->placer || !ct_controls_fit()) {
        if (NULL == view_get(37)->placer) {
            ct_fail("node 37 moved back onto canvas 21 is not placed");
        }
        return;
    }
    ct_textarea_moved_out();
}

/* Text area 35 placed one row high (no scrollbar), then moved into stack 1: its scrollbar back, as GTK shows it when
 * its text is taller than it; put back, three rows high, it fits again */
static void ct_textarea_moved_check(void);
static void ct_textarea_moved_back(void);
static void ct_textarea_one_row_placed(void);

static void ct_textarea_moved_out(void)
{
    H.ct_stage = "moving text area 35, one row high, off the canvas into stack 1";
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":35,\"props\":{\"place\":[8,10,30,1]}}]}");
    ct_after(300, ct_textarea_one_row_placed);
}

static void ct_textarea_one_row_placed(void)
{
    GtkPolicyType vertical = GTK_POLICY_AUTOMATIC;
    gtk_scrolled_window_get_policy(GTK_SCROLLED_WINDOW(view_get(35)->outer), NULL, &vertical);
    if (GTK_POLICY_AUTOMATIC == vertical) {
        ct_fail("text area 35 one row high has a vertical scrollbar");
        return;
    }
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"move\",\"id\":35,\"parent\":1,\"index\":11}]}");
    ct_after(300, ct_textarea_moved_check);
}

static void ct_textarea_moved_check(void)
{
    View *view = view_get(35);
    GtkPolicyType vertical = GTK_POLICY_NEVER;
    if (NULL == view || NULL != view->placer || !gtk_widget_get_mapped(view->outer)) {
        ct_fail("text area 35 is not shown in stack 1 once moved there");
        return;
    }
    gtk_scrolled_window_get_policy(GTK_SCROLLED_WINDOW(view->outer), NULL, &vertical);
    if (GTK_POLICY_AUTOMATIC != vertical || gtk_widget_has_css_class(view->outer, "tui-one-row")) {
        ct_fail("text area 35 in stack 1 keeps what its one-row place gave it: vertical scrollbar policy %d, one-row class %s", vertical,
            gtk_widget_has_css_class(view->outer, "tui-one-row") ? "kept" : "dropped");
        return;
    }
    ct_say("text area 35, one row high, moved into stack 1, has its scrollbar again");
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"move\",\"id\":35,\"parent\":21,\"index\":5},{\"op\":\"update\",\"id\":35,\"props\":{\"place\":[8,10,30,3]}}]}");
    ct_after(300, ct_textarea_moved_back);
}

static void ct_textarea_moved_back(void)
{
    if (NULL == view_get(35)->placer || !ct_controls_fit()) {
        if (NULL == view_get(35)->placer) {
            ct_fail("text area 35 moved back onto canvas 21 is not placed");
        }
        return;
    }
    ct_slider();
}

static void ct_slider(void)
{
    H.ct_stage = "moving the slider 38 with Right";
    gtk_widget_grab_focus(view_get(38)->control);
    ct_keys((const char *const[]) {"Right", NULL});
    ct_wait_label("event 38 change 55", ct_slider_keyed);
}

static void ct_slider_keyed(void)
{
    H.ct_stage = "dragging the slider 38 to 73";
    gtk_range_set_value(GTK_RANGE(view_get(38)->control), 73);
    ct_wait_label("event 38 change 75", ct_slider_dragged);
}

static void ct_slider_dragged(void)
{
    ct_say("node 38 went to 55 with Right, and to 75, its step, when dragged to 73");
    ct_listbox();
}

static void ct_listbox(void)
{
    GtkListBox *box = GTK_LIST_BOX(view_get(39)->control);
    H.ct_stage = "selecting rows 1 and 2 of the list box 39";
    if (GTK_SELECTION_MULTIPLE != gtk_list_box_get_selection_mode(box) || NULL == gtk_list_box_get_row_at_index(box, 3) || !gtk_list_box_row_is_selected(gtk_list_box_get_row_at_index(box, 0))) {
        ct_fail("node 39 is not a multiple list of 4 rows, the first selected");
        return;
    }
    /* What clicking row 1, then Ctrl-clicking row 2, does */
    gtk_list_box_unselect_all(box);
    gtk_list_box_select_row(box, gtk_list_box_get_row_at_index(box, 1));
    gtk_list_box_select_row(box, gtk_list_box_get_row_at_index(box, 2));
    ct_wait_label("event 39 select [1,2]", ct_listbox_selected);
}

static void ct_listbox_selected(void)
{
    GtkListBox *box = GTK_LIST_BOX(view_get(39)->control);
    H.ct_stage = "moving down in the list box 39";
    gtk_widget_grab_focus(GTK_WIDGET(gtk_list_box_get_row_at_index(box, 2)));
    ct_keys((const char *const[]) {"Down", NULL});
    ct_wait_label("event 39 select [3]", ct_listbox_moved);
}

static void ct_listbox_moved(void)
{
    ct_say("node 39 sent [1,2], then [3] for Down");
    ct_capture(GTK_WIDGET(H.window), "inputs.png");
    ct_form();
}

/* 3e. An edit in a form's entry, committed as the focus leaves it, is sent also when the application moves the
 * focus (`focus`): form 12 of the fixtures, its row "name" (Ada) */
static void ct_form_left(void);
static gboolean ct_form_typed(gpointer data);
static gint64 ct_form_until;

static void ct_form(void)
{
    View *form = ct_view(12, "form");
    GtkWidget *entry = NULL != form ? gtk_grid_get_child_at(GTK_GRID(form->control), 1, 1) : NULL;
    if (NULL == form) {
        return;
    }
    if (!GTK_IS_ENTRY(entry) || 0 != g_strcmp0(g_object_get_data(G_OBJECT(entry), "tui-row"), "name")) {
        ct_fail("form 12 has no entry for its row \"name\" on its second line");
        return;
    }
    /* Its row "plugins" is readOnly: shown, not edited */
    GtkWidget *shown = gtk_grid_get_child_at(GTK_GRID(form->control), 1, 3);
    if (!GTK_IS_ENTRY(shown) || 0 != g_strcmp0(g_object_get_data(G_OBJECT(shown), "tui-row"), "plugins") || gtk_editable_get_editable(GTK_EDITABLE(shown))) {
        ct_fail("form 12 has no entry the user cannot edit for its readOnly row \"plugins\" on its fourth line");
        return;
    }
    H.ct_stage = "typing x at the end of the row \"name\" of form 12, then the application moves the focus";
    gtk_widget_grab_focus(entry);
    ct_keys((const char *const[]) {"End", "x", NULL});
    /* Once the entry has the x: on a loaded machine GTK handles the key after a fixed wait, and the application's
     * focus would take it */
    ct_form_until = g_get_monotonic_time() + 5 * G_USEC_PER_SEC;
    g_timeout_add(50, ct_form_typed, entry);
}

static gboolean ct_form_typed(gpointer data)
{
    if (H.ct_done) {
        return G_SOURCE_REMOVE;
    }
    if (0 == strcmp(gtk_editable_get_text(GTK_EDITABLE(data)), "Adax")) {
        ct_form_left();
        return G_SOURCE_REMOVE;
    }
    if (g_get_monotonic_time() > ct_form_until) {
        ct_fail("the row \"name\" of form 12 has \"%s\" 5 s after End and x, not Adax; node 2 reads \"%s\"", gtk_editable_get_text(GTK_EDITABLE(data)), ft_label());
        return G_SOURCE_REMOVE;
    }
    return G_SOURCE_CONTINUE;
}

static void ct_form_left(void)
{
    if (0 != g_strcmp0(ft_label(), "event 39 select [3]")) {
        ct_fail("typing into form 12 sent an event before it was committed: node 2 reads \"%s\"", ft_label());
        return;
    }
    focus_node(34);
    ct_wait_label("event 12 setting {\"id\":\"name\",\"value\":\"Adax\"}", ct_key_loop);
}

/* 4. Tab in the text field goes to the application, which moves the focus. */
static void ct_tab_done(void);
typedef struct CtRealIme CtRealIme;
static const CtRealIme *ct_real_ime(void);

static void ct_tab_sent(void)
{
    ct_after(300, ct_tab_done);
}

/* Tab while an input method composes in text field 30 is the input method's, not the application's (`keys`): GTK's
 * own swallows it in a Ctrl+Shift+U sequence, which Escape then drops. */
static void ct_tab_composing(void);
static void ct_tab_composed(void);
static void ct_tab_escaped(void);
static int ct_keys_then;

static void ct_tab(void)
{
    gtk_widget_grab_focus(view_get(30)->control);
    if (NULL != ct_real_ime()) {
        ct_tab_escaped(); /* IBus, which ime.sh tests, does not compose with Ctrl+Shift+U in the plain stages */
        return;
    }
    H.ct_stage = "pressing Tab in node 30 while an input method composes in it";
    /* At its end: GTK replaces a selection with what is composed */
    ct_keys((const char *const[]) {"End", "ctrl+shift+u", "e", NULL});
    ct_after(300, ct_tab_composing);
}

static void ct_tab_composing(void)
{
    if (NULL == view_get(30)->preedit) {
        ct_fail("Ctrl+Shift+U shows no preedit in node 30");
        return;
    }
    ct_keys_then = H.ct_keys;
    g_string_truncate(H.ct_focus, 0);
    ct_keys((const char *const[]) {"Tab", NULL});
    ct_after(300, ct_tab_composed);
}

static void ct_tab_composed(void)
{
    GtkWidget *focus = gtk_root_get_focus(GTK_ROOT(H.window));
    if (H.ct_keys != ct_keys_then || '\0' != H.ct_focus->str[0] || NULL == focus || !gtk_widget_is_ancestor(focus, view_get(30)->control)) {
        ct_fail("Tab while composing in node 30 sent %d keys and moved the focus to nodes%s", H.ct_keys - ct_keys_then, H.ct_focus->str);
        return;
    }
    ct_say("Tab while composing in node 30 (preedit \"%s\") went to the input method: no key, the focus stayed", view_get(30)->preedit);
    ct_keys((const char *const[]) {"Escape", NULL});
    ct_after(300, ct_tab_escaped);
}

static void ct_tab_escaped(void)
{
    if (NULL != view_get(30)->preedit) {
        ct_fail("Escape left the preedit \"%s\" in node 30", view_get(30)->preedit);
        return;
    }
    H.ct_stage = "pressing Tab in node 30";
    g_string_truncate(H.ct_focus, 0);
    ct_keys((const char *const[]) {"Tab", NULL});
    ct_wait_label("key tab", ct_tab_sent);
}

static void ct_tab_done(void)
{
    GtkWidget *focus = gtk_root_get_focus(GTK_ROOT(H.window));
    View *button = view_get(34);
    if (NULL == focus || (focus != button->control && !gtk_widget_is_ancestor(focus, button->control))) {
        ct_fail("after focus 34, the focus is on %s", NULL != focus ? G_OBJECT_TYPE_NAME(focus) : "nothing");
        return;
    }
    if (0 != strcmp(H.ct_focus->str, " 34")) {
        ct_fail("the focus went through nodes%s, not straight to 34: Tab moved it natively", H.ct_focus->str);
        return;
    }
    ct_say("Tab went out as key tab; the focus moved only by focus 34 (focus went to node%s), and button 34 has it", H.ct_focus->str);
    ct_ime();
}

/* 5. The input method of the canvas, GTK's own (GtkIMContextSimple): Ctrl+Shift+U and code points compose, shown
 * as preedit; Space commits. Dead keys cannot be typed on Xvfb, whose keymap does not change (xmodmap, xkbcomp and
 * setxkbmap leave it as it is), so the composing goes through Ctrl+Shift+U. */
static void ct_ime_composing(void);
static void ct_ime_tab(void);
static void ct_ime_commit(void);
static void ct_ime_committed(void);
static void ct_ime_second(void);
static void ct_ime_done(void);

static const char *ct_composed_label;

/* TUI_APP_CANVAS_IME: a real input method of IBus instead of GTK's own, which linux/ime.sh sets up:
 * - mozc: Japanese, Mozc in Hiragana; "nihon" is composed (にほん), Space converts it (日本), Enter commits it;
 * - dead: the dead keys of US international, as m17n's latn-pre composes them: ' then e is é, " then u is ü.
 * Each in the canvas, then in the native text field 30. */
struct CtRealIme {
    const char *name;
    const char *engine;         /* the IBus engine, taken for this stage only: the others type plain keys */
    const char *const *compose; /* typed to compose: a preedit shows, nothing goes out */
    const char *preedit;        /* the start of the preedit then */
    const char *const *commit;  /* typed to commit */
    const char *committed;      /* the text committed */
};

static const CtRealIme *ct_real_ime(void)
{
    static const char *const mozc_compose[] = {"n", "i", "h", "o", "n", NULL};
    static const char *const mozc_commit[] = {"space", "Return", NULL};
    static const char *const dead_compose[] = {"apostrophe", NULL};
    static const char *const dead_commit[] = {"e", "quotedbl", "u", NULL};
    static const CtRealIme mozc = {"mozc", "mozc-jp", mozc_compose, "にほ", mozc_commit, "日本"};
    static const CtRealIme dead = {"dead", "m17n:t:latn-pre", dead_compose, "'", dead_commit, "éü"};
    const char *name = g_getenv("TUI_APP_CANVAS_IME");
    return 0 == g_strcmp0(name, "mozc") ? &mozc : 0 == g_strcmp0(name, "dead") ? &dead : NULL;
}

static void ct_real_ime_type(void);
static void ct_real_ime_composing(void);
static void ct_real_ime_committed(void);
static void ct_real_ime_once(void);
static void ct_real_ime_field(void);
static void ct_real_ime_field_cleared(void);
static void ct_real_ime_field_type(void);
static void ct_real_ime_field_composing(void);
static void ct_real_ime_field_committed(void);
static void ct_real_ime_field_once(void);
static void ct_real_ime_field_left(void);
static void ct_real_ime_field_moved(void);
static void ct_real_ime_field_sent(void);
static void ct_real_ime_number(void);

static int ct_keys_before;
static char *ct_expected;

/* Waits up to 5 s for a condition, then goes on: the input method takes the keys at its own pace */
static CtCondition ct_condition;
static FeatureStep ct_condition_then;
static gint64 ct_condition_until;

static gboolean ct_condition_poll(gpointer data)
{
    (void) data;
    if (H.ct_done) {
        return G_SOURCE_REMOVE;
    }
    if (ct_condition() || g_get_monotonic_time() > ct_condition_until) {
        ct_condition_then(); /* which says what is wrong when it timed out */
        return G_SOURCE_REMOVE;
    }
    return G_SOURCE_CONTINUE;
}

static void ct_wait_for_condition(CtCondition condition, FeatureStep then)
{
    ct_condition = condition;
    ct_condition_then = then;
    ct_condition_until = g_get_monotonic_time() + 5 * G_USEC_PER_SEC;
    g_timeout_add(50, ct_condition_poll, NULL);
}

static gboolean ct_canvas_composed(void)
{
    const char *preedit = view_get(21)->preedit;
    return NULL != preedit && g_str_has_prefix(preedit, ct_real_ime()->preedit) && g_utf8_strlen(preedit, -1) >= g_utf8_strlen(ct_real_ime()->preedit, -1) + (0 == strcmp(ct_real_ime()->name, "mozc") ? 1 : 0);
}

/* ibus engine <name>, which exits 1 even when it took it: what ibus engine says then */
static gboolean ct_ibus_engine(const char *engine)
{
    const char *set[] = {"ibus", "engine", engine, NULL};
    const char *get[] = {"ibus", "engine", NULL};
    g_autofree char *now = NULL;
    g_autoptr(GError) error = NULL;
    if (!g_spawn_sync(NULL, (char **) set, NULL, G_SPAWN_SEARCH_PATH | G_SPAWN_STDOUT_TO_DEV_NULL | G_SPAWN_STDERR_TO_DEV_NULL, NULL, NULL, NULL, NULL, NULL, &error)
        || !g_spawn_sync(NULL, (char **) get, NULL, G_SPAWN_SEARCH_PATH | G_SPAWN_STDERR_TO_DEV_NULL, NULL, NULL, &now, NULL, NULL, &error)) {
        ct_fail("ibus engine %s: %s", engine, error->message);
        return FALSE;
    }
    if (0 != g_strcmp0(g_strstrip(now), engine)) {
        ct_fail("IBus did not take the engine %s: it has %s", engine, now);
        return FALSE;
    }
    return TRUE;
}

static void ct_real_ime_type(void)
{
    H.ct_stage = "composing with the input method in the canvas";
    ct_keys_before = H.ct_keys;
    ct_keys(ct_real_ime()->compose);
    ct_wait_for_condition(ct_canvas_composed, ct_real_ime_composing);
}

static void ct_real_ime_composing(void)
{
    const CtRealIme *ime = ct_real_ime();
    View *canvas = view_get(21);
    if (NULL == canvas->preedit || !g_str_has_prefix(canvas->preedit, ime->preedit)) {
        ct_fail("the %s input method shows the preedit \"%s\" in the canvas, not one starting with \"%s\"", ime->name, NULL != canvas->preedit ? canvas->preedit : "", ime->preedit);
        return;
    }
    if (H.ct_keys != ct_keys_before || 0 != g_strcmp0(ft_label(), "key tab")) {
        ct_fail("composing sent %d keys; node 2 reads \"%s\"", H.ct_keys - ct_keys_before, ft_label());
        return;
    }
    ct_say("%s: the canvas shows the preedit \"%s\", nothing sent", ime->name, canvas->preedit);
    ct_capture(GTK_WIDGET(H.window), "canvas-preedit.png");
    H.ct_stage = "committing with the input method in the canvas";
    ct_keys(ime->commit);
    g_free(ct_expected);
    ct_expected = g_strdup_printf("typed %s", ime->committed);
    ct_wait_label(ct_expected, ct_real_ime_committed);
}

static void ct_real_ime_committed(void)
{
    ct_after(500, ct_real_ime_once);
}

static void ct_real_ime_once(void)
{
    glong characters = g_utf8_strlen(ct_real_ime()->committed, -1);
    if (NULL != view_get(21)->preedit) {
        ct_fail("the preedit \"%s\" stays after the commit", view_get(21)->preedit);
        return;
    }
    if (0 != g_strcmp0(ft_label(), ct_expected) || H.ct_keys - ct_keys_before != characters) {
        ct_fail("the commit went out as %d keys, not %ld; node 2 reads \"%s\"", H.ct_keys - ct_keys_before, characters, ft_label());
        return;
    }
    ct_say("%s: the committed text went out once, one key per character", ct_real_ime()->name);
    ct_real_ime_field();
}

/* The same in the native text field 30, emptied first */
static void ct_real_ime_field(void)
{
    H.ct_stage = "emptying node 30";
    gtk_editable_set_text(GTK_EDITABLE(view_get(30)->control), "");
    ct_wait_label("event 30 change \"\"", ct_real_ime_field_cleared);
}

static void ct_real_ime_field_cleared(void)
{
    gtk_widget_grab_focus(view_get(30)->control);
    ct_after(1000, ct_real_ime_field_type);
}

static void ct_real_ime_field_type(void)
{
    H.ct_stage = "composing with the input method in node 30";
    ct_keys(ct_real_ime()->compose);
    ct_after(1500, ct_real_ime_field_composing);
}

static void ct_real_ime_field_composing(void)
{
    const char *text = gtk_editable_get_text(GTK_EDITABLE(view_get(30)->control));
    if (0 != g_strcmp0(ft_label(), "event 30 change \"\"")) {
        ct_fail("composing in node 30 sent an event: node 2 reads \"%s\" (the field has \"%s\")", ft_label(), text);
        return;
    }
    ct_say("%s: composing in node 30 sent nothing", ct_real_ime()->name);
    ct_capture(GTK_WIDGET(H.window), "field-preedit.png");
    H.ct_stage = "committing with the input method in node 30";
    ct_keys(ct_real_ime()->commit);
    g_free(ct_expected);
    ct_expected = g_strdup_printf("event 30 change \"%s\"", ct_real_ime()->committed);
    ct_wait_label(ct_expected, ct_real_ime_field_committed);
}

static void ct_real_ime_field_committed(void)
{
    ct_after(500, ct_real_ime_field_once);
}

static void ct_real_ime_field_once(void)
{
    const char *text = gtk_editable_get_text(GTK_EDITABLE(view_get(30)->control));
    if (0 != strcmp(text, ct_real_ime()->committed) || 0 != g_strcmp0(ft_label(), ct_expected)) {
        ct_fail("node 30 has \"%s\" after the commit, not \"%s\"; node 2 reads \"%s\"", text, ct_real_ime()->committed, ft_label());
        return;
    }
    ct_say("%s: node 30 has \"%s\", committed once", ct_real_ime()->name, text);
    ct_capture(GTK_WIDGET(H.window), "field-committed.png");
    /* The focus leaving it commits nothing again */
    gtk_widget_grab_focus(view_get(34)->control);
    ct_after(800, ct_real_ime_field_left);
}

static void ct_real_ime_field_left(void)
{
    const char *text = gtk_editable_get_text(GTK_EDITABLE(view_get(30)->control));
    if (0 != strcmp(text, ct_real_ime()->committed) || 0 != g_strcmp0(ft_label(), ct_expected)) {
        ct_fail("node 30 has \"%s\" once the focus left it, not \"%s\"; node 2 reads \"%s\"", text, ct_real_ime()->committed, ft_label());
        return;
    }
    ct_say("%s: the focus left node 30, which kept \"%s\"", ct_real_ime()->name, text);
    /* Composing again, one character short of its maxLength (30), and the application moves the focus (`focus`): what
     * the input method commits then is the user's, cut to maxLength and sent */
    H.ct_stage = "composing in node 30 again, one character short of its maxLength";
    g_autofree char *short_of = g_strdup_printf("%s%.*s", text, (int) (29 - g_utf8_strlen(text, -1)), "xxxxxxxxxxxxxxxxxxxxxxxxxxxxx");
    gtk_editable_set_text(GTK_EDITABLE(view_get(30)->control), short_of);
    gtk_widget_grab_focus(view_get(30)->control);
    ct_keys((const char *const[]) {"End", NULL});
    ct_keys(ct_real_ime()->compose);
    ct_after(1500, ct_real_ime_field_moved);
}

static void ct_real_ime_field_moved(void)
{
    if (NULL == view_get(30)->preedit) {
        ct_fail("%s: composing again in node 30 shows no preedit", ct_real_ime()->name);
        return;
    }
    ct_say("%s: node 30 composes \"%s\"; the application moves the focus to node 34", ct_real_ime()->name, view_get(30)->preedit);
    H.ct_stage = "moving the focus out of node 30 as focus 34 does, while composing in it";
    focus_node(34);
    ct_after(1500, ct_real_ime_field_sent);
}

static void ct_real_ime_field_sent(void)
{
    const char *text = gtk_editable_get_text(GTK_EDITABLE(view_get(30)->control));
    g_autofree char *sent = g_strdup_printf("event 30 change \"%s\"", text);
    if (0 != g_strcmp0(ft_label(), sent)) {
        ct_fail("%s: node 30 has \"%s\" once the application moved the focus, but node 2 reads \"%s\"", ct_real_ime()->name, text, ft_label());
        return;
    }
    if (30 != g_utf8_strlen(text, -1)) {
        ct_fail("%s: node 30 has %ld characters once the application moved the focus, not its maxLength 30: \"%s\"", ct_real_ime()->name, g_utf8_strlen(text, -1), text);
        return;
    }
    ct_say("%s: what the input method committed as the application moved the focus was cut to maxLength and sent: \"%s\"", ct_real_ime()->name, text);
    ct_real_ime_number();
}

/* The same in the number field 37, emptied first: what the input method commits as the application moves the focus
 * is sent, and kept as typed, a number or not */
static void ct_real_ime_number_cleared(void);
static void ct_real_ime_number_type(void);
static void ct_real_ime_number_moved(void);
static void ct_real_ime_number_sent(void);

static void ct_real_ime_number(void)
{
    H.ct_stage = "emptying node 37";
    gtk_editable_set_text(GTK_EDITABLE(view_get(37)->control), "");
    ct_wait_label("event 37 change \"\"", ct_real_ime_number_cleared);
}

static void ct_real_ime_number_cleared(void)
{
    /* Its row is below the window until the window grows: up to row 1, as a patch would */
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":37,\"props\":{\"place\":[1,14,12,1]}}]}");
    gtk_widget_grab_focus(view_get(37)->control);
    ct_after(1000, ct_real_ime_number_type);
}

static void ct_real_ime_number_type(void)
{
    H.ct_stage = "composing in node 37";
    ct_keys(ct_real_ime()->compose);
    ct_after(1500, ct_real_ime_number_moved);
}

static void ct_real_ime_number_moved(void)
{
    if (NULL == view_get(37)->preedit || 0 != g_strcmp0(ft_label(), "event 37 change \"\"")) {
        ct_fail("%s: composing in node 37 shows %s; node 2 reads \"%s\"", ct_real_ime()->name, NULL != view_get(37)->preedit ? "a preedit" : "no preedit", ft_label());
        return;
    }
    ct_say("%s: node 37 composes \"%s\", nothing sent; the application moves the focus to node 34", ct_real_ime()->name, view_get(37)->preedit);
    H.ct_stage = "moving the focus out of node 37 as focus 34 does, while composing in it";
    ct_events_from_here();
    focus_node(34);
    ct_after(1500, ct_real_ime_number_sent);
}

static void ct_real_ime_number_sent(void)
{
    const char *text = gtk_editable_get_text(GTK_EDITABLE(view_get(37)->control));
    g_autofree char *sent = g_strdup_printf("event 37 change \"%s\"", text);
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"update\",\"id\":37,\"props\":{\"place\":[13,10,12,1]}}]}");
    if ('\0' == *text || 0 != g_strcmp0(ft_label(), sent)) {
        ct_fail("%s: node 37 has \"%s\" once the application moved the focus, but node 2 reads \"%s\" (sent: %s)", ct_real_ime()->name, text, ft_label(),
            H.ct_events->str + ct_events_mark);
        return;
    }
    ct_say("%s: what the input method committed into node 37 as the application moved the focus was sent and kept: \"%s\"", ct_real_ime()->name, text);
    if (ct_ibus_engine("xkb:us::eng")) {
        ct_ime_done();
    }
}

static void ct_ime(void)
{
    H.ct_stage = "composing é in the canvas";
    View *canvas = view_get(21);
    if (!tui_prop_bool(node_of(canvas)->props, "focusable", TRUE)) {
        /* The fixture's canvas says focusable: false; keys reach a canvas only with the focus. */
        ct_say("canvas 21 is focusable: false in the fixtures; the test lets it take the focus");
        gtk_widget_set_focusable(canvas->control, TRUE);
    }
    gtk_widget_grab_focus(canvas->control);
    if (!gtk_widget_has_focus(canvas->control)) {
        ct_fail("canvas 21 does not take the focus");
        return;
    }
    if (NULL != ct_real_ime()) {
        if (ct_ibus_engine(ct_real_ime()->engine)) {
            /* The input method takes the focus of the canvas a moment after it */
            ct_say("%s: the IBus engine is %s", ct_real_ime()->name, ct_real_ime()->engine);
            ct_after(1000, ct_real_ime_type);
        }
        return;
    }
    ct_composed_label = "key tab";
    ct_keys((const char *const[]) {"ctrl+shift+u", "e", "9", NULL});
    ct_after(400, ct_ime_composing);
}

static void ct_ime_composing(void)
{
    View *canvas = view_get(21);
    if (NULL == canvas->preedit) {
        ct_fail("Ctrl+Shift+U shows no preedit in the canvas");
        return;
    }
    if (0 != g_strcmp0(ft_label(), ct_composed_label)) {
        ct_fail("composing sent something: node 2 reads \"%s\"", ft_label());
        return;
    }
    ct_say("composing: the canvas shows the preedit \"%s\", nothing sent", canvas->preedit);
    if (0 == strcmp(ct_composed_label, "typed é")) {
        ct_capture(GTK_WIDGET(H.window), "canvas-preedit.png");
    } else {
        /* Tab, which the canvas takes (`keys`), is the input method's while it composes */
        ct_keys_then = H.ct_keys;
        ct_keys((const char *const[]) {"Tab", NULL});
        ct_after(300, ct_ime_tab);
        return;
    }
    ct_ime_commit();
}

static void ct_ime_tab(void)
{
    View *canvas = view_get(21);
    if (H.ct_keys != ct_keys_then || !gtk_widget_has_focus(canvas->control) || NULL == canvas->preedit) {
        ct_fail("Tab while composing in the canvas sent %d keys (the canvas has the focus: %d, preedit: %s)", H.ct_keys - ct_keys_then,
            gtk_widget_has_focus(canvas->control), NULL != canvas->preedit ? canvas->preedit : "none");
        return;
    }
    ct_say("Tab while composing in the canvas went to the input method: no key, the focus stayed");
    ct_ime_commit();
}

static void ct_ime_commit(void)
{
    ct_keys((const char *const[]) {"space", NULL});
    ct_wait_label(0 == strcmp(ct_composed_label, "key tab") ? "typed é" : "typed é日", ct_ime_committed);
}

static void ct_ime_committed(void)
{
    if (NULL != view_get(21)->preedit) {
        ct_fail("the preedit \"%s\" stays after the commit", view_get(21)->preedit);
        return;
    }
    if (0 == strcmp(ct_composed_label, "key tab")) {
        H.ct_stage = "composing 日 in the canvas";
        ct_composed_label = "typed é";
        ct_keys((const char *const[]) {"ctrl+shift+u", "6", "5", "e", "5", NULL});
        ct_after(400, ct_ime_composing);
        return;
    }
    H.ct_stage = "composing 本 in the canvas";
    ct_keys((const char *const[]) {"ctrl+shift+u", "6", "7", "2", "c", "space", NULL});
    ct_wait_label("typed é日本", ct_ime_second);
}

static void ct_plain_once(void);
static void ct_plain_typed(void);
static void ct_super(void);
static void ct_super_sent(void);
static void ct_super_once(void);
static void ct_context_menu(void);

static void ct_ime_second(void)
{
    ct_say("the committed text went out as typed keys, one per character");
    /* A plain key after it: typed once, whether the input method commits it or it goes out as a key */
    H.ct_stage = "typing a plain a in the canvas";
    ct_keys((const char *const[]) {"a", NULL});
    ct_wait_label("typed é日本a", ct_plain_typed);
}

static void ct_plain_typed(void)
{
    ct_after(300, ct_plain_once);
}

static void ct_plain_once(void)
{
    if (0 != g_strcmp0(ft_label(), "typed é日本a")) {
        ct_fail("a plain a went out more than once: node 2 reads \"%s\"", ft_label());
        return;
    }
    ct_say("a plain a went out once");
    ct_super();
}

/* Super+A with the canvas itself focused, through the X server: the key super+a, no text (GtkIMContextSimple would
 * commit it as "a"), once */
static int ct_super_keys;

static void ct_super(void)
{
    View *canvas = view_get(21);
    H.ct_stage = "pressing Super+A in canvas 21";
    gtk_widget_grab_focus(canvas->control);
    if (!gtk_widget_has_focus(canvas->control)) {
        ct_fail("canvas 21 does not take the focus for Super+A");
        return;
    }
    ct_super_keys = H.ct_keys;
    ct_keys((const char *const[]) {"super+a", NULL});
    ct_wait_label("key super+a", ct_super_sent);
}

static void ct_super_sent(void)
{
    ct_after(300, ct_super_once);
}

static void ct_super_once(void)
{
    if (0 != g_strcmp0(ft_label(), "key super+a") || 1 != H.ct_keys - ct_super_keys || !gtk_widget_has_focus(view_get(21)->control)) {
        ct_fail("Super+A in canvas 21 sent %d keys; node 2 reads \"%s\"", H.ct_keys - ct_super_keys, ft_label());
        return;
    }
    ct_say("Super+A in canvas 21, which has the focus, went out once as the key super+a, no text");
    ct_context_menu();
}

/* contextmenu and clipboard: a menu at cell (1, 2) of canvas 21 as the application sends it after a right press, its
 * items activated through their actions as the menu's buttons do; the clipboard set and read through the X server
 * by xclip, another client */
static void ct_receive(const char *json)
{
    g_autoptr(GError) error = NULL;
    TuiMessage *message = tui_message_parse(json, -1, &error);
    if (NULL == message) {
        ct_fail("the test's message does not parse: %s", error->message);
        return;
    }
    received(message);
}

#define CT_CONTEXT_MENU                                                                                                                                       \
    "{\"type\":\"contextmenu\",\"node\":21,\"row\":1,\"column\":2,\"items\":[{\"id\":\"open\",\"label\":\"Open_Link\"},{\"id\":\"copy\",\"label\":" \
    "\"Copy Link Address\"},{\"separator\":true},{\"id\":\"paste\",\"label\":\"Paste\",\"role\":\"paste\"},{\"id\":\"undo\",\"label\":"            \
    "\"Nothing to undo\",\"enabled\":false}]}"

static guint ct_menus_shown; /* H.context_menus_shown then: not a popover's pointer, which may be freed and reused */

/* Waits for the messages sent from the mark to be <expected> (contextmenu-select and paste, which the application
 * does not show), then <then>; fails after 5 s */
static const char *ct_sent_expected;
static FeatureStep ct_sent_then;
static gint64 ct_sent_until;

static gboolean ct_sent_poll(gpointer data)
{
    (void) data;
    if (H.ct_done) {
        return G_SOURCE_REMOVE;
    }
    if (0 == strcmp(H.ct_events->str + MIN(ct_events_mark, H.ct_events->len), ct_sent_expected)) {
        ct_sent_then();
        return G_SOURCE_REMOVE;
    }
    if (g_get_monotonic_time() > ct_sent_until) {
        ct_events_are(ct_sent_expected, H.ct_stage); /* fails, saying what was sent */
        return G_SOURCE_REMOVE;
    }
    return G_SOURCE_CONTINUE;
}

static void ct_wait_sent(const char *expected, FeatureStep then)
{
    ct_sent_expected = expected;
    ct_sent_then = then;
    ct_sent_until = g_get_monotonic_time() + 5 * G_USEC_PER_SEC;
    g_timeout_add(50, ct_sent_poll, NULL);
}
static void ct_context_menu_shown(void);
static void ct_context_menu_copied(void);
static void ct_context_menu_disabled(void);
static void ct_clipboard_set(void);
static void ct_clipboard_read(GObject *source, GAsyncResult *result, gpointer data);
static void ct_clipboard_owned(void);
static void ct_context_menu_pasted(void);
static void ct_context_menu_empty(void);
static void ct_context_menu_empty_done(void);
static void ct_context_menu_dismissed(void);
static void ct_context_menu_replaced(void);
static void ct_context_menu_done(void);
static void ct_clipboard_from_x(void);
static void ct_context_menu_dismiss(void);

static void ct_context_menu(void)
{
    H.ct_stage = "showing a context menu at cell (1, 2) of canvas 21";
    ct_events_from_here();
    ct_receive(CT_CONTEXT_MENU);
    ct_after(300, ct_context_menu_shown);
}

/* Activates item <index> of the menu shown, as its button does; FALSE (failed) when there is none */
static gboolean ct_activate(guint index)
{
    g_autofree char *name = g_strdup_printf("i%u", index);
    GActionGroup *actions = NULL != H.context_menu ? g_object_get_data(G_OBJECT(H.context_menu), "tui-actions") : NULL;
    if (NULL == actions || !g_action_group_has_action(actions, name)) {
        ct_fail("no context menu with an item %u is shown", index);
        return FALSE;
    }
    ct_events_from_here();
    g_action_group_activate_action(actions, name, NULL);
    return TRUE;
}

/* The labels a menu shows, in order */
static void ct_menu_shown_labels(GtkWidget *widget, GPtrArray *labels)
{
    if (GTK_IS_LABEL(widget) && gtk_widget_get_mapped(widget) && '\0' != *gtk_label_get_text(GTK_LABEL(widget))) {
        g_ptr_array_add(labels, (gpointer) gtk_label_get_text(GTK_LABEL(widget)));
    }
    for (GtkWidget *child = gtk_widget_get_first_child(widget); NULL != child; child = gtk_widget_get_next_sibling(child)) {
        ct_menu_shown_labels(child, labels);
    }
}

/* What a menu shows: its labels as drawn, ", " between them, " | " between its sections (its model's) */
static void ct_menu_labels(GtkWidget *menu, GString *out)
{
    GPtrArray *labels = g_ptr_array_new();
    GMenuModel *model = gtk_popover_menu_get_menu_model(GTK_POPOVER_MENU(menu));
    guint next = 0;
    ct_menu_shown_labels(menu, labels);
    for (int i = 0; i < g_menu_model_get_n_items(model); i++) {
        g_autoptr(GMenuModel) section = g_menu_model_get_item_link(model, i, G_MENU_LINK_SECTION);
        g_string_append(out, 0 == i ? "" : " | ");
        for (int j = 0; NULL != section && j < g_menu_model_get_n_items(section); j++) {
            g_string_append_printf(out, "%s%s", 0 == j ? "" : ", ", next < labels->len ? (const char *) labels->pdata[next] : "?");
            next++;
        }
    }
    for (; next < labels->len; next++) {
        g_string_append_printf(out, ", %s", (const char *) labels->pdata[next]);
    }
    g_ptr_array_free(labels, TRUE);
}

static void ct_context_menu_shown(void)
{
    GtkWidget *menu = H.context_menu;
    GtkWidget *canvas = view_get(21)->control;
    GdkRectangle at = {0};
    if (NULL == menu || !gtk_widget_get_mapped(menu) || gtk_widget_get_parent(menu) != canvas || !gtk_popover_get_pointing_to(GTK_POPOVER(menu), &at)) {
        ct_fail("no context menu is shown over canvas 21");
        return;
    }
    if (at.x != (int) floor(2 * H.cell_width) || at.y != (int) floor(H.cell_height) || at.width < 1 || at.height < 1) {
        ct_fail("the context menu points to %d,%d %dx%d, not cell (1, 2) at %d,%d", at.x, at.y, at.width, at.height, (int) floor(2 * H.cell_width),
            (int) floor(H.cell_height));
        return;
    }
    /* Two sections (the separator), the labels as given, shown as they are (an underscore is not a mnemonic), the
     * last item greyed out */
    GString *labels = g_string_new(NULL);
    ct_menu_labels(menu, labels);
    GActionGroup *actions = g_object_get_data(G_OBJECT(menu), "tui-actions");
    gboolean enabled_ok = g_action_group_get_action_enabled(actions, "i0") && g_action_group_get_action_enabled(actions, "i3")
        && !g_action_group_get_action_enabled(actions, "i4");
    if (0 != strcmp(labels->str, "Open_Link, Copy Link Address | Paste, Nothing to undo") || !enabled_ok) {
        ct_fail("the context menu reads \"%s\" (Nothing to undo greyed out: %s)", labels->str, enabled_ok ? "yes" : "no");
        g_string_free(labels, TRUE);
        return;
    }
    g_string_free(labels, TRUE);
    if (!ct_events_are("", "showing the context menu")) {
        return;
    }
    ct_say("contextmenu showed a menu at cell (1, 2) of canvas 21: Open_Link, Copy Link Address | Paste, Nothing to undo (greyed out)");
    H.ct_stage = "choosing Copy Link Address in the context menu";
    if (ct_activate(1)) {
        gtk_popover_popdown(GTK_POPOVER(menu)); /* as the menu does once an item is chosen */
        ct_after(300, ct_context_menu_copied);
    }
}

static void ct_context_menu_copied(void)
{
    if (!ct_events_are("contextmenu-select copy; ", "choosing Copy Link Address")) {
        return;
    }
    if (NULL != H.context_menu) {
        ct_fail("the context menu is still there once an item was chosen");
        return;
    }
    H.ct_stage = "choosing the greyed out item of the context menu";
    ct_receive(CT_CONTEXT_MENU);
    if (ct_activate(4)) {
        ct_after(300, ct_context_menu_disabled);
    }
}

static void ct_context_menu_disabled(void)
{
    if (!ct_events_are("", "choosing Nothing to undo, greyed out,")) {
        return;
    }
    ct_say("choosing Copy Link Address sent contextmenu-select copy, the greyed out Nothing to undo nothing");
    /* The application copies: the clipboard, read by another X client */
    H.ct_stage = "putting text on the clipboard (clipboard), then reading it with xclip";
    ct_receive("{\"type\":\"clipboard\",\"text\":\"https://example.com/docs\"}");
    ct_after(200, ct_clipboard_set);
}

static void ct_clipboard_set(void)
{
    g_autoptr(GError) error = NULL;
    GSubprocess *xclip = g_subprocess_new(G_SUBPROCESS_FLAGS_STDOUT_PIPE | G_SUBPROCESS_FLAGS_STDERR_SILENCE, &error, "timeout", "10", "xclip", "-o",
        "-selection", "clipboard", NULL);
    if (NULL == xclip) {
        ct_fail("cannot run xclip, which the test reads the clipboard with: %s", error->message);
        return;
    }
    /* Not waited for in the main loop's place: GTK hands the text over from it */
    g_subprocess_communicate_utf8_async(xclip, NULL, NULL, ct_clipboard_read, NULL);
}

static void ct_clipboard_read(GObject *source, GAsyncResult *result, gpointer data)
{
    g_autoptr(GSubprocess) xclip = G_SUBPROCESS(source);
    g_autofree char *out = NULL;
    (void) data;
    if (H.ct_done) {
        return;
    }
    if (!g_subprocess_communicate_utf8_finish(xclip, result, &out, NULL, NULL) || !g_subprocess_get_if_exited(xclip) || 0 != g_subprocess_get_exit_status(xclip)
        || 0 != g_strcmp0(out, "https://example.com/docs")) {
        ct_fail("xclip read \"%s\" from the clipboard, not https://example.com/docs", NULL != out ? out : "(nothing)");
        return;
    }
    ct_say("clipboard put https://example.com/docs on the clipboard, as xclip reads it");
    ct_clipboard_from_x();
}

/* Another client owns the clipboard: the paste item reads it through the X server */
static void ct_clipboard_from_x(void)
{
    H.ct_stage = "pasting what xclip put on the clipboard with the context menu's Paste";
    if (!g_spawn_command_line_sync("sh -c 'printf %s \"pasted from X\" | xclip -selection clipboard -i'", NULL, NULL, NULL, NULL)) {
        ct_fail("cannot run xclip to put text on the clipboard");
        return;
    }
    ct_after(300, ct_clipboard_owned);
}

static void ct_clipboard_owned(void)
{
    ct_receive(CT_CONTEXT_MENU);
    if (ct_activate(3)) {
        gtk_popover_popdown(GTK_POPOVER(H.context_menu));
        ct_wait_sent("paste \"pasted from X\"; ", ct_context_menu_pasted);
    }
}

static void ct_context_menu_pasted(void)
{
    if (!ct_events_are("paste \"pasted from X\"; ", "Paste in the context menu")) {
        return;
    }
    ct_say("Paste in the context menu sent paste with the clipboard's text, \"pasted from X\", not contextmenu-select");
    H.ct_stage = "pasting an empty clipboard with the context menu's Paste";
    ct_receive("{\"type\":\"clipboard\",\"text\":\"\"}");
    ct_after(200, ct_context_menu_empty);
}

static void ct_context_menu_empty(void)
{
    ct_receive(CT_CONTEXT_MENU);
    if (ct_activate(3)) {
        gtk_popover_popdown(GTK_POPOVER(H.context_menu));
        ct_after(500, ct_context_menu_empty_done);
    }
}

static void ct_context_menu_empty_done(void)
{
    if (!ct_events_are("", "Paste in the context menu with an empty clipboard")) {
        return;
    }
    ct_say("Paste in the context menu with an empty clipboard sent nothing");
    ct_context_menu_dismiss();
}

static void ct_context_menu_dismiss(void)
{
    H.ct_stage = "dismissing the context menu";
    ct_receive(CT_CONTEXT_MENU);
    ct_events_from_here();
    if (NULL == H.context_menu) {
        ct_fail("no context menu is shown to dismiss");
        return;
    }
    gtk_popover_popdown(GTK_POPOVER(H.context_menu));
    ct_after(300, ct_context_menu_dismissed);
}

static void ct_context_menu_dismissed(void)
{
    if (!ct_events_are("", "dismissing the context menu") || NULL != H.context_menu) {
        if (NULL != H.context_menu) {
            ct_fail("the context menu stays once dismissed");
        }
        return;
    }
    H.ct_stage = "sending a second context menu over the first";
    ct_receive(CT_CONTEXT_MENU);
    ct_menus_shown = H.context_menus_shown;
    ct_receive("{\"type\":\"contextmenu\",\"node\":21,\"row\":0,\"column\":0,\"items\":[{\"id\":\"only\",\"label\":\"Only\"}]}");
    ct_after(300, ct_context_menu_replaced);
}

static void ct_context_menu_real(void);

static void ct_context_menu_done(void)
{
    ct_say("a menu dismissed sent nothing, and a second one replaced the first: its item sent contextmenu-select only");
    ct_context_menu_real();
}

/* Real input on the menu, through the X server: a click on an item, Escape, a click outside it */
static GtkWidget *ct_menu_item(GtkWidget *widget, const char *label)
{
    if (GTK_IS_LABEL(widget) && gtk_widget_get_mapped(widget) && 0 == g_strcmp0(gtk_label_get_text(GTK_LABEL(widget)), label)) {
        return widget;
    }
    for (GtkWidget *child = gtk_widget_get_first_child(widget); NULL != child; child = gtk_widget_get_next_sibling(child)) {
        GtkWidget *found = ct_menu_item(child, label);
        if (NULL != found) {
            return found;
        }
    }
    return NULL;
}

/* The middle of the item <label> of the menu shown, in the main window's X coordinates (xdotool --window) */
static gboolean ct_menu_item_at(const char *label, char **x, char **y)
{
    GtkWidget *menu = H.context_menu;
    GtkWidget *item = NULL != menu ? ct_menu_item(menu, label) : NULL;
    GdkSurface *surface = NULL != menu ? gtk_native_get_surface(GTK_NATIVE(menu)) : NULL;
    graphene_point_t at;
    double dx = 0;
    double dy = 0;
    double wx = 0;
    double wy = 0;
    if (NULL == item || !GDK_IS_POPUP(surface)
        || !gtk_widget_compute_point(item, menu, &GRAPHENE_POINT_INIT(gtk_widget_get_width(item) / 2.0f, gtk_widget_get_height(item) / 2.0f), &at)) {
        ct_fail("the context menu shows no item %s", label);
        return FALSE;
    }
    gtk_native_get_surface_transform(GTK_NATIVE(menu), &dx, &dy);
    gtk_native_get_surface_transform(GTK_NATIVE(H.window), &wx, &wy);
    (void) wx;
    (void) wy;
    *x = g_strdup_printf("%d", (int) round(gdk_popup_get_position_x(GDK_POPUP(surface)) + at.x + dx));
    *y = g_strdup_printf("%d", (int) round(gdk_popup_get_position_y(GDK_POPUP(surface)) + at.y + dy));
    return TRUE;
}

static void ct_menu_real_shown(void);
static void ct_menu_real_clicked(void);
static void ct_menu_escape_shown(void);
static void ct_menu_escaped(void);
static void ct_menu_outside_shown(void);
static void ct_menu_outside(void);

static void ct_context_menu_real(void)
{
    H.ct_stage = "clicking Copy Link Address in the context menu with xdotool";
    ct_receive(CT_CONTEXT_MENU);
    ct_after(400, ct_menu_real_shown);
}

static void ct_menu_real_shown(void)
{
    g_autofree char *x = NULL;
    g_autofree char *y = NULL;
    g_autofree char *xid = ct_xid();
    if (!ct_menu_item_at("Copy Link Address", &x, &y)) {
        return;
    }
    ct_events_from_here();
    if (ct_xdotool((const char *const[]) {"mousemove", "--window", NULL != xid ? xid : "", x, y, "click", "1", NULL})) {
        ct_wait_sent("contextmenu-select copy; ", ct_menu_real_clicked);
    }
}

static void ct_menu_real_clicked(void)
{
    ct_after(300, ct_menu_escape_shown);
}

static void ct_menu_escape_shown(void)
{
    if (!ct_events_are("contextmenu-select copy; ", "a click on Copy Link Address") || NULL != H.context_menu) {
        if (NULL != H.context_menu) {
            ct_fail("the context menu stays once an item was clicked");
        }
        return;
    }
    ct_say("a click on Copy Link Address (xdotool) sent contextmenu-select copy and closed the menu");
    H.ct_stage = "pressing Escape in the context menu";
    ct_park_mouse();
    ct_receive(CT_CONTEXT_MENU);
    ct_events_from_here();
    ct_after(400, ct_menu_escaped);
}

static void ct_menu_escaped(void)
{
    static gboolean pressed;
    if (!pressed) {
        pressed = TRUE;
        if (NULL == H.context_menu) {
            ct_fail("no context menu is shown for Escape");
            return;
        }
        ct_keys((const char *const[]) {"Escape", NULL});
        ct_after(400, ct_menu_escaped);
        return;
    }
    if (NULL != H.context_menu || !ct_events_are("", "Escape in the context menu")) {
        if (NULL != H.context_menu) {
            ct_fail("Escape left the context menu open");
        }
        return;
    }
    ct_say("Escape closed the context menu and sent nothing");
    H.ct_stage = "clicking outside the context menu";
    ct_receive(CT_CONTEXT_MENU);
    ct_after(400, ct_menu_outside_shown);
}

static void ct_menu_outside_shown(void)
{
    /* Cell (0, 60) of canvas 21, far from the menu at (1, 2) */
    graphene_point_t at;
    double dx = 0;
    double dy = 0;
    g_autofree char *xid = ct_xid();
    if (NULL == H.context_menu
        || !gtk_widget_compute_point(view_get(21)->control, GTK_WIDGET(H.window), &GRAPHENE_POINT_INIT(60.5f * (float) H.cell_width, 0.5f * (float) H.cell_height), &at)) {
        ct_fail("no context menu is shown to click outside of");
        return;
    }
    gtk_native_get_surface_transform(GTK_NATIVE(H.window), &dx, &dy);
    g_autofree char *x = g_strdup_printf("%d", (int) round(at.x + dx));
    g_autofree char *y = g_strdup_printf("%d", (int) round(at.y + dy));
    ct_events_from_here();
    if (ct_xdotool((const char *const[]) {"mousemove", "--window", NULL != xid ? xid : "", x, y, "click", "1", NULL})) {
        ct_after(500, ct_menu_outside);
    }
}

static void ct_menu_outside(void)
{
    /* Closed, the click taken by the menu: no pointer press on the canvas under it */
    if (NULL != H.context_menu || !ct_events_are("", "a click outside the context menu, on cell (0, 60) of canvas 21,")) {
        if (NULL != H.context_menu) {
            ct_fail("a click outside the context menu left it open");
        }
        return;
    }
    ct_say("a click outside the context menu, on canvas 21, closed it and sent nothing, not even the click");
    ct_park_mouse();
    ct_ime_done();
}

static void ct_context_menu_replaced(void)
{
    GtkWidget *menu = H.context_menu;
    guint popovers = 0;
    for (GtkWidget *child = gtk_widget_get_first_child(view_get(21)->control); NULL != child; child = gtk_widget_get_next_sibling(child)) {
        popovers += GTK_IS_POPOVER(child) ? 1 : 0;
    }
    /* The second one shown, the only one on the canvas */
    if (NULL == menu || ct_menus_shown + 1 != H.context_menus_shown || 1 != popovers || !gtk_widget_get_mapped(menu)) {
        ct_fail("a second context menu did not replace the first: %u shown since, %u on canvas 21", H.context_menus_shown - ct_menus_shown, popovers);
        return;
    }
    if (ct_activate(0)) {
        gtk_popover_popdown(GTK_POPOVER(menu));
        ct_wait_sent("contextmenu-select only; ", ct_context_menu_done);
    }
}

static void ct_pointer(void);

static void ct_ime_done(void)
{
    ct_capture(GTK_WIDGET(H.window), "canvas.png");
    ct_pointer();
}

/* 6. The mouse on canvas 21, real X pointer events (XTEST, through xdotool) that GTK hands to the canvas's gesture
 * and controllers: a click, a drag, the wheel and a move are sent as `pointer`; a click on a control placed over it
 * is the control's. */
static gboolean ct_mouse_at(GtkWidget *widget, double x, double y, const char *const *then)
{
    graphene_point_t at = GRAPHENE_POINT_INIT((float) x, (float) y), in_window;
    double dx, dy;
    if (!gtk_widget_compute_point(widget, GTK_WIDGET(H.window), &at, &in_window)) {
        ct_fail("no point of the window at %.0f,%.0f of a %s", x, y, G_OBJECT_TYPE_NAME(widget));
        return FALSE;
    }
    /* From the window to its X window, around the window's shadow if any */
    gtk_native_get_surface_transform(GTK_NATIVE(H.window), &dx, &dy);
    g_autofree char *xid = ct_xid();
    g_autofree char *sx = g_strdup_printf("%d", (int) round(in_window.x + dx));
    g_autofree char *sy = g_strdup_printf("%d", (int) round(in_window.y + dy));
    GPtrArray *args = g_ptr_array_new();
    g_ptr_array_add(args, "mousemove");
    g_ptr_array_add(args, "--sync");
    g_ptr_array_add(args, "--window");
    g_ptr_array_add(args, NULL != xid ? xid : "");
    g_ptr_array_add(args, sx);
    g_ptr_array_add(args, sy);
    for (guint i = 0; NULL != then && NULL != then[i]; i++) {
        g_ptr_array_add(args, (char *) then[i]);
    }
    g_ptr_array_add(args, NULL);
    gboolean done = ct_xdotool((const char *const *) args->pdata);
    g_ptr_array_free(args, TRUE);
    return done;
}

/* The middle of a cell of canvas 21, a pixel to the right with `nudge` */
static gboolean ct_mouse_cell(gint64 row, gint64 column, int nudge, const char *const *then)
{
    return ct_mouse_at(view_get(21)->control, (column + 0.5) * H.cell_width + nudge, (row + 0.5) * H.cell_height, then);
}

static gboolean ct_canvas_events(const char *expected, const char *what)
{
    if (!ct_events_are(expected, what)) {
        return FALSE;
    }
    ct_say("%s sent: %s", what, expected);
    return TRUE;
}

static void ct_pointer_clicked(void);
static void ct_pointer_dragged(void);
static void ct_pointer_wheeled(void);
static void ct_pointer_wheel_counted(void);
static void ct_pointer_moved(void);
static void ct_pointer_left(void);
static gboolean ct_pointer_field_focused(void);
static void ct_pointer_field_clicked(void);
static void ct_cursor(void);
static void ct_release_on_field(void);

static void ct_pointer_hovered(void)
{
    /* The click focuses the canvas, which another control has: its event focus first */
    gtk_widget_grab_focus(view_get(34)->control);
    H.ct_stage = "clicking cell (0, 1) of canvas 21";
    ct_events_from_here();
    ct_xdotool((const char *const[]) {"click", "1", NULL});
    ct_wait_label("pointer release 0 1 left", ct_pointer_clicked);
}

static void ct_pointer(void)
{
    H.ct_stage = "moving the mouse to cell (0, 1) of canvas 21";
    if (!ct_mouse_cell(0, 1, 0, NULL)) {
        return;
    }
    ct_wait_label("pointer move 0 1", ct_pointer_hovered);
}

static void ct_pointer_clicked(void)
{
    if (!ct_canvas_events("21 focus; 21 pointer press 0 1 left; 21 pointer release 0 1 left; ", "a click on cell (0, 1), the canvas focused first,")) {
        return;
    }
    if (!gtk_widget_has_focus(view_get(21)->control)) {
        ct_fail("the click did not focus canvas 21");
        return;
    }
    H.ct_stage = "dragging in canvas 21";
    ct_events_from_here();
    /* Twice in cell (1, 3): one move. ct_xdotool fails the test when xdotool fails; the button is let go then. */
    if (!ct_xdotool((const char *const[]) {"mousedown", "1", NULL}) || !ct_mouse_cell(1, 3, 0, NULL)
        || !ct_mouse_cell(1, 3, 1, (const char *const[]) {"mouseup", "1", NULL})) {
        g_spawn_command_line_sync("xdotool mouseup 1", NULL, NULL, NULL, NULL);
        return;
    }
    ct_wait_label("pointer release 1 3 left", ct_pointer_dragged);
}

/* The rows of the wheel sent from the mark, at cell (1, 3) where the mouse is; -1 for anything else sent */
static gint64 ct_wheel_rows(void)
{
    g_auto(GStrv) sent = g_strsplit(H.ct_events->str + MIN(ct_events_mark, H.ct_events->len), "; ", -1);
    gint64 rows = 0;
    for (guint i = 0; NULL != sent[i] && '\0' != sent[i][0]; i++) {
        gint64 notch = 0;
        if (!g_str_has_prefix(sent[i], "21 pointer wheel 1 3 ") || !g_str_has_suffix(sent[i], " 0")) {
            return -1;
        }
        notch = g_ascii_strtoll(sent[i] + strlen("21 pointer wheel 1 3 "), NULL, 10);
        if (notch <= 0) {
            return -1;
        }
        rows += notch;
    }
    return rows;
}

static gboolean ct_pointer_wheel_done(void)
{
    gint64 rows = ct_wheel_rows();
    return rows < 0 || rows >= 3;
}

static void ct_pointer_dragged(void)
{
    if (!ct_canvas_events("21 pointer press 0 1 left; 21 pointer move 1 3 left; 21 pointer release 1 3 left; ", "a drag from (0, 1) to (1, 3) and twice in it")) {
        return;
    }
    H.ct_stage = "turning the mouse wheel down over canvas 21";
    ct_events_from_here();
    ct_xdotool((const char *const[]) {"click", "--repeat", "3", "--delay", "1", "5", NULL});
    ct_wait_for_condition(ct_pointer_wheel_done, ct_pointer_wheel_counted);
}

static void ct_pointer_wheel_settled(void)
{
    gint64 rows = ct_wheel_rows();
    const char *sent = H.ct_events->str + MIN(ct_events_mark, H.ct_events->len);
    const char *last = g_strrstr(H.ct_events->str, "21 pointer wheel ");
    g_autofree char *label = NULL != last ? g_strndup(last + strlen("21 "), strcspn(last, ";") - strlen("21 ")) : NULL;
    if (3 != rows) {
        ct_fail("3 notches of the wheel down at cell (1, 3) sent \"%s\", not 3 rows there", sent);
        return;
    }
    /* X sends a notch at a time (buttons 4 and 5): a row each, unless GDK merged them */
    ct_say("3 notches of the wheel down, where the mouse is, sent 3 rows at cell (1, 3): %s", sent);
    ct_wait_label(label, ct_pointer_wheeled);
}

static void ct_pointer_wheel_counted(void)
{
    /* Nothing more after a moment */
    ct_after(300, ct_pointer_wheel_settled);
}

static void ct_pointer_wheeled(void)
{
    H.ct_stage = "moving the mouse to cell (2, 4) of canvas 21";
    ct_events_from_here();
    if (ct_mouse_cell(2, 4, 0, NULL)) {
        ct_wait_label("pointer move 2 4", ct_pointer_moved);
    }
}

static void ct_pointer_moved(void)
{
    if (!ct_canvas_events("21 pointer move 2 4; ", "a move with no button to (2, 4)")) {
        return;
    }
    /* Onto text field 30, placed over the canvas: the mouse leaves the drawing, at the cell last sent */
    H.ct_stage = "moving the mouse onto text field 30";
    ct_events_from_here();
    GtkWidget *field = view_get(30)->control;
    if (ct_mouse_at(field, gtk_widget_get_width(field) / 2.0, gtk_widget_get_height(field) / 2.0, NULL)) {
        ct_wait_label("pointer leave 2 4", ct_pointer_left);
    }
}

static void ct_pointer_left(void)
{
    if (!ct_canvas_events("21 pointer leave 2 4; ", "the mouse onto text field 30")) {
        return;
    }
    H.ct_stage = "clicking text field 30 over canvas 21";
    ct_events_from_here();
    ct_xdotool((const char *const[]) {"click", "1", NULL});
    ct_wait_for_condition(ct_pointer_field_focused, ct_pointer_field_clicked);
}

static gboolean ct_pointer_field_focused(void)
{
    GtkWidget *focus = gtk_root_get_focus(GTK_ROOT(H.window));
    return NULL != focus && (focus == view_get(30)->control || gtk_widget_is_ancestor(focus, view_get(30)->control));
}

static void ct_pointer_field_settled(void)
{
    if (NULL != strstr(H.ct_events->str + MIN(ct_events_mark, H.ct_events->len), " pointer ")) {
        ct_fail("a click on text field 30 sent \"%s\"", H.ct_events->str + MIN(ct_events_mark, H.ct_events->len));
        return;
    }
    ct_say("a click on text field 30 focused it and sent no pointer: \"%s\"", H.ct_events->str + MIN(ct_events_mark, H.ct_events->len));
    ct_capture(GTK_WIDGET(H.window), "canvas-pointer.png");
    ct_cursor();
}

/* A drag from the canvas onto text field 30, placed over it, released there: the drag is the canvas's (GTK's implicit
 * grab) up to the release, which is over the field, so the canvas sends leave after it (contract $defs.pointer); then
 * nothing while the mouse moves over the field with no button held. */
static double ct_field_x, ct_field_y;
static char *ct_field_cell; /* "<row> <column>" of canvas 21 under the middle of text field 30 */
static char *ct_field_leave;

static void ct_release_on_field_dragged(void);
static void ct_release_on_field_settled(void);
static void ct_release_on_field_hovered(void);
static void ct_release_on_field_held(void);

static void ct_release_on_field(void)
{
    H.ct_stage = "moving the mouse to cell (0, 1) of canvas 21, from text field 30";
    if (ct_mouse_cell(0, 1, 0, NULL)) {
        ct_wait_label("pointer move 0 1", ct_release_on_field_hovered);
    }
}

static void ct_release_on_field_hovered(void)
{
    GtkWidget *field = view_get(30)->control;
    graphene_point_t middle, in_canvas;
    gint64 row, column;
    ct_field_x = gtk_widget_get_width(field) / 2.0;
    ct_field_y = gtk_widget_get_height(field) / 2.0;
    middle = GRAPHENE_POINT_INIT((float) ct_field_x, (float) ct_field_y);
    if (!gtk_widget_compute_point(field, view_get(21)->control, &middle, &in_canvas)) {
        ct_fail("text field 30 has no point in canvas 21");
        return;
    }
    tui_pointer_cell(in_canvas.x, in_canvas.y, H.cell_width, H.cell_height, &row, &column);
    g_free(ct_field_cell);
    ct_field_cell = g_strdup_printf("%" G_GINT64_FORMAT " %" G_GINT64_FORMAT, row, column);
    g_free(ct_field_leave);
    ct_field_leave = g_strdup_printf("pointer leave %s", ct_field_cell);
    H.ct_stage = "dragging from cell (0, 1) of canvas 21 onto text field 30 and releasing there";
    ct_events_from_here();
    /* To (1, 3) first, and onto the field once that move went out: GDK merges the moves of a frame */
    if (!ct_xdotool((const char *const[]) {"mousedown", "1", NULL}) || !ct_mouse_cell(1, 3, 0, NULL)) {
        g_spawn_command_line_sync("xdotool mouseup 1", NULL, NULL, NULL, NULL);
        return;
    }
    ct_wait_label("pointer move 1 3 left", ct_release_on_field_held);
}

static void ct_release_on_field_held(void)
{
    if (!ct_mouse_at(view_get(30)->control, ct_field_x, ct_field_y, (const char *const[]) {"mouseup", "1", NULL})) {
        g_spawn_command_line_sync("xdotool mouseup 1", NULL, NULL, NULL, NULL);
        return;
    }
    ct_wait_label(ct_field_leave, ct_release_on_field_dragged);
}

static void ct_release_on_field_dragged(void)
{
    g_autofree char *expected = g_strdup_printf("21 focus; 21 pointer press 0 1 left; 21 pointer move 1 3 left; 21 pointer move %s left; "
                                                "21 pointer release %s left; 21 pointer leave %s; ", ct_field_cell, ct_field_cell, ct_field_cell);
    if (!ct_canvas_events(expected, "a drag from (0, 1) onto text field 30, released there,")) {
        return;
    }
    H.ct_stage = "moving the mouse over text field 30 with no button held";
    ct_events_from_here();
    if (ct_mouse_at(view_get(30)->control, ct_field_x + 2 * H.cell_width, ct_field_y, NULL)
        && ct_mouse_at(view_get(30)->control, ct_field_x - 2 * H.cell_width, ct_field_y, NULL)) {
        ct_after(300, ct_release_on_field_settled);
    }
}

static void ct_release_on_field_settled(void)
{
    if (!ct_events_are("", "moves over text field 30 with no button held")) {
        return;
    }
    ct_say("moves over text field 30 with no button held sent nothing");
    ct_resize();
}

/* A cursor message as the application sends it, through what receives it */
static void ct_cursor_message(gint64 node, const char *shape)
{
    g_autofree char *json = g_strdup_printf("{\"type\":\"cursor\",\"node\":%" G_GINT64_FORMAT ",\"shape\":\"%s\"}", node, shape);
    g_autoptr(GError) error = NULL;
    TuiMessage *message = tui_message_parse(json, -1, &error);
    if (NULL == message) {
        ct_fail("the test's cursor message does not parse: %s", error->message);
        return;
    }
    received(message);
}

/* The name of the cursor a widget shows: its own, else the first of its ancestors' as GTK looks for it; NULL is the arrow */
static const char *ct_cursor_name(GtkWidget *widget, gboolean inherited)
{
    for (; NULL != widget; widget = inherited ? gtk_widget_get_parent(widget) : NULL) {
        GdkCursor *cursor = gtk_widget_get_cursor(widget);
        if (NULL != cursor) {
            return NULL != gdk_cursor_get_name(cursor) ? gdk_cursor_get_name(cursor) : "(a texture)";
        }
    }
    return NULL;
}

static gboolean ct_cursor_is(const char *sent, const char *expected)
{
    const char *canvas = ct_cursor_name(view_get(21)->control, TRUE);
    const char *field = ct_cursor_name(view_get(30)->control, TRUE);
    if (0 != g_strcmp0(canvas, expected)) {
        ct_fail("after %s, canvas 21 shows the cursor %s, not %s", sent, NULL != canvas ? canvas : "(the arrow)", NULL != expected ? expected : "(the arrow)");
        return FALSE;
    }
    /* Text field 30, placed over the canvas, keeps its own: GTK's, none set on it or on what holds it */
    if (NULL != field) {
        ct_fail("after %s, text field 30 over canvas 21 shows the cursor %s", sent, field);
        return FALSE;
    }
    return TRUE;
}

/* The cursor the application sends for canvas 21: on its drawing, not on text field 30 placed over it; the closest
 * for a keyword, the arrow for none; ignored for a node that is not a canvas or not there. */
static void ct_cursor(void)
{
    H.ct_stage = "sending cursor messages for canvas 21";
    ct_cursor_message(21, "pointer");
    if (!ct_cursor_is("cursor pointer", "pointer")) {
        return;
    }
    ct_cursor_message(30, "text");
    if (NULL != ct_cursor_name(view_get(30)->control, TRUE)) {
        ct_fail("a cursor message for text field 30, not a canvas, set its cursor to %s", ct_cursor_name(view_get(30)->control, TRUE));
        return;
    }
    ct_cursor_message(999, "wait");
    if (!ct_cursor_is("cursor text for field 30 and wait for node 999", "pointer")) {
        return;
    }
    ct_cursor_message(21, "none");
    if (!ct_cursor_is("cursor none", "none")) {
        return;
    }
    ct_cursor_message(21, "hand2"); /* not a CSS keyword: the arrow */
    if (!ct_cursor_is("cursor hand2", NULL)) {
        return;
    }
    ct_cursor_message(21, "text");
    if (!ct_cursor_is("cursor text", "text")) {
        return;
    }
    ct_cursor_message(21, "default");
    if (!ct_cursor_is("cursor default", NULL)) {
        return;
    }
    ct_cursor_message(21, "pointer");
    if (!ct_cursor_is("cursor pointer", "pointer")) {
        return;
    }
    ct_say("cursor messages: canvas 21 shows pointer, none, the arrow for hand2 and default, text; text field 30 over it none of "
           "them; field 30 and node 999 ignored");
    ct_release_on_field();
}

static void ct_pointer_field_clicked(void)
{
    if (!ct_pointer_field_focused()) {
        ct_fail("a click on text field 30 did not focus it");
        return;
    }
    ct_after(300, ct_pointer_field_settled);
}

/* 7. Ten cells wider: the rects of the new layout go out. */
static gint64 ct_columns(void)
{
    graphene_rect_t bounds;
    gint64 rect[4] = {0, 0, 0, 0};
    View *canvas = view_get(21);
    if (!gtk_widget_compute_bounds(canvas->outer, H.holder, &bounds)) {
        return -1;
    }
    tui_rect_cells(bounds.origin.x, bounds.origin.y, bounds.size.width, bounds.size.height, H.cell_width, H.cell_height, rect);
    return rect[2];
}

static gint64 ct_old_columns;
static char *ct_expected_rects;

/* The window once resized, canvas 21 in it: before the last steps remove it */
static void ct_resized_drawn(void)
{
    ct_capture(GTK_WIDGET(H.window), "canvas-resized.png");
    ct_menu_open_canvas();
}

static void ct_resized(void)
{
    gint64 columns = ct_columns();
    if (columns <= ct_old_columns) {
        ct_fail("canvas 21 is %" G_GINT64_FORMAT " cells wide after the resize, %" G_GINT64_FORMAT " before", columns, ct_old_columns);
        return;
    }
    g_free(ct_expected_rects);
    ct_expected_rects = g_strdup_printf("rects %" G_GINT64_FORMAT, columns);
    ct_wait_label(ct_expected_rects, ct_resized_drawn);
}

static void ct_resize(void)
{
    H.ct_stage = "resizing the window";
    ct_old_columns = ct_columns();
    g_autofree char *width = g_strdup_printf("%d", gtk_widget_get_width(GTK_WIDGET(H.window)) + (int) round(10 * H.cell_width));
    /* Taller too, so that the capture shows the controls placed below the fixture's short canvas. */
    g_autofree char *height = g_strdup_printf("%d", gtk_widget_get_height(GTK_WIDGET(H.window)) + 260);
    g_autofree char *xid = ct_xid();
    if (ct_xdotool((const char *const[]) {"windowsize", "--sync", NULL != xid ? xid : "", width, height, NULL})) {
        ct_say("the window is now %s px wide (canvas 21 was %" G_GINT64_FORMAT " cells)", width, ct_old_columns);
        ct_after(500, ct_resized);
    }
}

/* 8. Closing. */
/* Last, as canvas 21 goes: an item of a context menu chosen, then the canvas removed in the same turn, before the
 * menu, closed, lets go of it (built with AddressSanitizer, smoke.sh, a read of the freed canvas aborts) */
static void ct_menu_canvas_gone(void);

/* A canvas removed while its context menu is open: canvas 900, made for it in stack 1 */
static void ct_menu_open_removed(void);
static void ct_menu_open_gone(void);

static char *ct_removed_sent; /* what removing focused canvas 901, with no menu, sent */
static void ct_menu_plain_removed(void);
static void ct_menu_plain_gone(void);

/* First, what removing a focused canvas sends with no menu (the focus going on), canvas 901 */
static void ct_menu_open_canvas(void)
{
    H.ct_stage = "removing canvas 901, focused, with no context menu";
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"create\",\"id\":901,\"kind\":\"canvas\",\"parent\":1,\"index\":11,\"props\":{\"lines\":[\"no menu\"]}}]}");
    ct_after(400, ct_menu_plain_removed);
}

static void ct_menu_plain_removed(void)
{
    gtk_widget_grab_focus(view_get(901)->control);
    ct_events_from_here();
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"remove\",\"id\":901}]}");
    ct_after(500, ct_menu_plain_gone);
}

static void ct_menu_plain_gone(void)
{
    g_free(ct_removed_sent);
    ct_removed_sent = g_strdup(H.ct_events->str + MIN(ct_events_mark, H.ct_events->len));
    H.ct_stage = "removing canvas 900 while its context menu is open";
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"create\",\"id\":900,\"kind\":\"canvas\",\"parent\":1,\"index\":11,\"props\":{\"lines\":[\"menu here\"]}}]}");
    ct_after(400, ct_menu_open_removed);
}

static void ct_menu_open_removed(void)
{
    /* Focused first, as the right press that asks for the menu focuses it */
    gtk_widget_grab_focus(view_get(900)->control);
    ct_receive("{\"type\":\"contextmenu\",\"node\":900,\"row\":0,\"column\":1,\"items\":[{\"id\":\"a\",\"label\":\"A\"},{\"id\":\"p\",\"label\":\"Paste\",\"role\":\"paste\"}]}");
    if (NULL == H.context_menu || !gtk_widget_get_mapped(H.context_menu)) {
        ct_fail("no context menu is shown over canvas 900");
        return;
    }
    g_autoptr(GtkWidget) menu = g_object_ref(H.context_menu);
    ct_events_from_here();
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"remove\",\"id\":900}]}");
    if (NULL != H.context_menu || NULL != gtk_widget_get_parent(menu) || gtk_widget_get_visible(menu)) {
        ct_fail("the context menu of canvas 900 stays (%s, %s) once the canvas is removed", NULL != gtk_widget_get_parent(menu) ? "on it" : "off it",
            gtk_widget_get_visible(menu) ? "shown" : "hidden");
        return;
    }
    ct_after(500, ct_menu_open_gone);
}

static void ct_menu_open_gone(void)
{
    /* No item chosen, nothing of the menu: what removing a focused canvas sends, no more */
    const char *sent = H.ct_events->str + MIN(ct_events_mark, H.ct_events->len);
    if (NULL != view_get(900) || 0 != g_strcmp0(sent, ct_removed_sent)) {
        ct_fail("removing canvas 900 with its context menu open sent \"%s\", not \"%s\" as with no menu%s", sent, NULL != ct_removed_sent ? ct_removed_sent : "",
            NULL != view_get(900) ? "; canvas 900 is still there" : "");
        return;
    }
    ct_say("canvas 900 removed while its context menu was open: the menu off it and closed, and sent \"%s\", as with no menu", sent);
    ct_menu_canvas_removed();
}

static void ct_menu_canvas_removed(void)
{
    H.ct_stage = "choosing an item of a context menu, then removing canvas 21 in the same turn";
    ct_receive(CT_CONTEXT_MENU);
    GActionGroup *actions = NULL != H.context_menu ? g_object_get_data(G_OBJECT(H.context_menu), "tui-actions") : NULL;
    if (NULL == actions) {
        ct_fail("no context menu is shown over canvas 21");
        return;
    }
    g_autoptr(GtkWidget) menu = g_object_ref(H.context_menu);
    ct_events_from_here();
    g_action_group_activate_action(actions, "i1", NULL);
    gtk_popover_popdown(GTK_POPOVER(menu));
    ct_patch("{\"type\":\"patch\",\"ops\":[{\"op\":\"remove\",\"id\":21}]}");
    /* Off the canvas as it goes, not on a freed parent until the idle that lets go of it */
    if (NULL != gtk_widget_get_parent(menu)) {
        ct_fail("the closed context menu is still on canvas 21 once the canvas is removed");
        return;
    }
    ct_after(500, ct_menu_canvas_gone);
}

static void ct_menu_canvas_gone(void)
{
    /* Then, what removing its controls sends (the list box's selection, the focus) */
    const char *sent = H.ct_events->str + MIN(ct_events_mark, H.ct_events->len);
    if (NULL != view_get(21) || NULL != H.context_menu || !g_str_has_prefix(sent, "contextmenu-select copy; ") || NULL != strstr(sent + 1, "contextmenu-select")) {
        ct_fail("canvas 21 removed as Copy Link Address was chosen: canvas %s, menu %s, sent \"%s\"", NULL != view_get(21) ? "still there" : "gone",
            NULL != H.context_menu ? "still there" : "gone", sent);
        return;
    }
    ct_say("an item of a context menu chosen, then canvas 21 removed in the same turn: contextmenu-select copy, and the menu let go");
    ct_close();
}

static void ct_close(void)
{
    H.ct_stage = "closing";
    close_requested(H.window, NULL);
}

static void ct_patch_drawn(void)
{
    if (!H.canvas_test || NULL != H.ct_stage) {
        return;
    }
    H.ct_focus = g_string_new(NULL);
    H.ct_events = g_string_new(NULL);
    H.ct_stage = "waiting for \"canvas ready\"";
    ct_say("hello listed canvas-controls");
    ct_wait_label("canvas ready", ct_laid_out);
}

/* ======================================================================== */
/* Application                                                              */
/* ======================================================================== */

static void window_realized(GtkWidget *widget, gpointer data)
{
    (void) data;
    g_signal_connect(gtk_widget_get_frame_clock(widget), "after-paint", G_CALLBACK(after_paint), NULL);
}

static gboolean dropped(GtkDropTarget *target, const GValue *value, double x, double y, gpointer data)
{
    (void) target;
    (void) x;
    (void) y;
    (void) data;
    if (!G_VALUE_HOLDS(value, GDK_TYPE_FILE_LIST)) {
        return FALSE;
    }
    GSList *files = gdk_file_list_get_files(g_value_get_boxed(value));
    guint count = g_slist_length(files);
    g_autofree GFile **array = g_new0(GFile *, MAX(1u, count));
    guint i = 0;
    for (GSList *file = files; NULL != file; file = file->next) {
        array[i++] = file->data;
    }
    add_open_files(array, (int) count);
    g_slist_free(files);
    return TRUE;
}

static void create_window(void)
{
    GtkWidget *window = gtk_application_window_new(H.app);
    GtkWidget *column = gtk_box_new(GTK_ORIENTATION_VERTICAL, 0);
    GtkEventController *keys = gtk_event_controller_key_new();
    GtkDropTarget *drop = gtk_drop_target_new(GDK_TYPE_FILE_LIST, GDK_ACTION_COPY);
    gint64 width = H.manifest->width;
    gint64 height = H.manifest->height;
    gboolean maximized = FALSE;

    H.window = GTK_WINDOW(window);
    gtk_window_set_title(H.window, H.manifest->title);
    if (H.manifest->remember_geometry) {
        g_autofree char *path = geometry_path();
        g_autofree char *json = NULL;
        if (g_file_get_contents(path, &json, NULL, NULL)) {
            tui_geometry_parse(json, &width, &height, &maximized);
        }
    }
    gtk_window_set_default_size(H.window, (int) width, (int) height);
    if (maximized) {
        gtk_window_maximize(H.window);
    }
    gtk_window_set_resizable(H.window, H.manifest->resizable);
    if (H.manifest->min_width > 0 || H.manifest->min_height > 0) {
        gtk_widget_set_size_request(window, (int) MAX(-1, H.manifest->min_width), (int) MAX(-1, H.manifest->min_height));
    }
    gtk_application_window_set_show_menubar(GTK_APPLICATION_WINDOW(window), FALSE);

    H.menubar = gtk_popover_menu_bar_new_from_model(NULL);
    H.holder = gtk_box_new(GTK_ORIENTATION_VERTICAL, 0);
    GtkLayoutManager *layout = tui_stack_layout_new(GTK_ORIENTATION_VERTICAL);
    tui_stack_layout_on_allocated(TUI_STACK_LAYOUT(layout), content_allocated, NULL);
    gtk_widget_set_layout_manager(H.holder, layout);
    gtk_widget_set_vexpand(H.holder, TRUE);
    gtk_widget_set_hexpand(H.holder, TRUE);
    gtk_widget_add_css_class(H.holder, "tui-content");
    gtk_box_append(GTK_BOX(column), H.menubar);
    gtk_box_append(GTK_BOX(column), H.holder);
    gtk_window_set_child(H.window, column);

    g_signal_connect(keys, "key-pressed", G_CALLBACK(window_key), NULL);
    gtk_widget_add_controller(window, keys);
    g_signal_connect(drop, "drop", G_CALLBACK(dropped), NULL);
    gtk_widget_add_controller(window, GTK_EVENT_CONTROLLER(drop));
    g_signal_connect(window, "close-request", G_CALLBACK(close_requested), NULL);
    g_signal_connect(window, "realize", G_CALLBACK(window_realized), NULL);

    measure_cells();
    build_menus();
    gtk_window_present(H.window);
}

static void about(GSimpleAction *action, GVariant *parameter, gpointer data)
{
    (void) action;
    (void) parameter;
    (void) data;
    gtk_show_about_dialog(H.window, "program-name", H.manifest->name, "version", H.manifest->version, "comments", H.manifest->description,
        "copyright", H.manifest->copyright, "website", H.manifest->homepage, "logo-icon-name", H.manifest->id, NULL);
}

static void quit(GSimpleAction *action, GVariant *parameter, gpointer data)
{
    (void) action;
    (void) parameter;
    (void) data;
    if (NULL != H.window) {
        close_requested(H.window, NULL);
    }
}

static void present(GSimpleAction *action, GVariant *parameter, gpointer data)
{
    (void) action;
    (void) parameter;
    (void) data;
    if (NULL != H.window) {
        gtk_window_present(H.window);
    }
}

static void startup(GApplication *application, gpointer data)
{
    static const GActionEntry actions[] = {{.name = "about", .activate = about}, {.name = "quit", .activate = quit}, {.name = "present", .activate = present}};
    GtkCssProvider *base = gtk_css_provider_new();
    const char *const quit_accels[] = {"<Control>q", NULL};

    (void) data;
    g_action_map_add_action_entries(G_ACTION_MAP(application), actions, G_N_ELEMENTS(actions), NULL);
    if (H.manifest->menu_quit) {
        gtk_application_set_accels_for_action(H.app, "app.quit", quit_accels);
        g_hash_table_add(H.shortcuts, g_strdup("ctrl+q"));
    }
    load_css(base, base_css);
    gtk_style_context_add_provider_for_display(gdk_display_get_default(), GTK_STYLE_PROVIDER(base), GTK_STYLE_PROVIDER_PRIORITY_APPLICATION);
    g_object_unref(base);
    H.node_css = gtk_css_provider_new();
    gtk_style_context_add_provider_for_display(gdk_display_get_default(), GTK_STYLE_PROVIDER(H.node_css), GTK_STYLE_PROVIDER_PRIORITY_APPLICATION + 1);
    follow_appearance();
    if (H.self_test) {
        H.test_timer = g_timeout_add_seconds(SELF_TEST_SECONDS, self_test_timed_out, NULL);
    }
    if (H.feature_test) {
        H.ft_begin = g_get_monotonic_time();
        H.ft_stage = NULL;
        ft_say("starting");
        g_timeout_add_seconds(FEATURE_TEST_SECONDS, ft_timed_out, NULL);
    }
    if (H.canvas_test) {
        H.ct_begin = g_get_monotonic_time();
        g_timeout_add_seconds(CANVAS_TEST_SECONDS, ct_timed_out, NULL);
    }
}

static void activate(GApplication *application, gpointer data)
{
    (void) application;
    (void) data;
    if (NULL == H.window) {
        create_window();
    } else {
        gtk_window_present(H.window);
    }
}

static void open_files(GApplication *application, GFile **files, int count, const char *hint, gpointer data)
{
    (void) hint;
    add_open_files(files, count);
    activate(application, data);
}

static void usage(void)
{
    fprintf(stderr, "usage: tui-app-host [--manifest <file>] [--self-test|--feature-test|--canvas-test] [files or URLs...] [--dev -- <command> [args...] | -- files or URLs...]\n");
}

static char *find_manifest(void)
{
    /* <prefix>/bin/<id> reads <prefix>/share/<id>/app.manifest.json. */
    g_autofree char *self = g_file_read_link("/proc/self/exe", NULL);
    if (NULL == self) {
        return NULL;
    }
    g_autofree char *name = g_path_get_basename(self);
    g_autofree char *bin = g_path_get_dirname(self);
    g_autofree char *prefix = g_path_get_dirname(bin);
    char *path = g_build_filename(prefix, "share", name, "app.manifest.json", NULL);
    if (g_file_test(path, G_FILE_TEST_IS_REGULAR)) {
        return path;
    }
    g_free(path);
    /* Next to the executable, as in a development tree. */
    path = g_build_filename(bin, "app.manifest.json", NULL);
    if (g_file_test(path, G_FILE_TEST_IS_REGULAR)) {
        return path;
    }
    g_free(path);
    return NULL;
}

int main(int argc, char **argv)
{
    g_autofree char *manifest_path = NULL;
    GPtrArray *command = g_ptr_array_new();
    /* Files and URLs to open: GFiles, made here rather than by GApplication, which would keep a -- before a name starting with - as a file. */
    g_autoptr(GPtrArray) files = g_ptr_array_new_with_free_func(g_object_unref);
    gboolean dev = FALSE;
    g_autoptr(GError) error = NULL;

    for (int i = 1; i < argc; i++) {
        if (0 == strcmp(argv[i], "--self-test")) {
            H.self_test = TRUE;
        } else if (0 == strcmp(argv[i], "--feature-test")) {
            H.feature_test = TRUE;
        } else if (0 == strcmp(argv[i], "--canvas-test")) {
            H.canvas_test = TRUE;
        } else if (0 == strcmp(argv[i], "--manifest")) {
            if (i + 1 >= argc) {
                fprintf(stderr, "tui-app-host: --manifest needs a file\n");
                return 2;
            }
            manifest_path = g_strdup(argv[++i]);
        } else if (0 == strcmp(argv[i], "--dev")) {
            dev = TRUE;
            if (i + 1 < argc && 0 == strcmp(argv[i + 1], "--")) {
                i++;
            }
            for (i++; i < argc; i++) {
                g_ptr_array_add(command, argv[i]);
            }
        } else if (0 == strcmp(argv[i], "--")) {
            /* The end of the options: files and URLs from the desktop entry, even ones that look like options. */
            for (i++; i < argc; i++) {
                g_ptr_array_add(files, g_file_new_for_commandline_arg(argv[i]));
            }
        } else if (0 == strcmp(argv[i], "--help") || 0 == strcmp(argv[i], "-h")) {
            usage();
            return 0;
        } else if ('-' == argv[i][0] && '\0' != argv[i][1]) {
            fprintf(stderr, "tui-app-host: unknown option %s\n", argv[i]);
            usage();
            return 2;
        } else {
            g_ptr_array_add(files, g_file_new_for_commandline_arg(argv[i]));
        }
    }
    g_ptr_array_add(command, NULL);

    if (dev) {
        if (NULL == command->pdata[0]) {
            usage();
            return 2;
        }
        H.manifest = tui_manifest_dev((char **) command->pdata);
    } else {
        if (NULL == manifest_path) {
            manifest_path = find_manifest();
        }
        if (NULL == manifest_path) {
            fprintf(stderr, "tui-app-host: no app.manifest.json found; use --manifest <file> or --dev -- <command>\n");
            return 2;
        }
        H.manifest = tui_manifest_load(manifest_path, &error);
        H.manifest_path = g_canonicalize_filename(manifest_path, NULL);
        if (NULL == H.manifest) {
            fprintf(stderr, "tui-app-host: %s: %s\n", manifest_path, error->message);
            return 2;
        }
    }
    g_ptr_array_free(command, TRUE);

    if (NULL != H.manifest->log_file) {
        g_autofree char *log = g_path_is_absolute(H.manifest->log_file) ? g_strdup(H.manifest->log_file)
                                                                         : g_build_filename(H.manifest->dir, H.manifest->log_file, NULL);
        H.log = fopen(log, "a");
    }
    H.out = g_queue_new();
    H.stderr_tail = g_queue_new();
    H.pending_paths = g_ptr_array_new_with_free_func(g_free);
    H.tree = tui_tree_new();
    H.views = g_hash_table_new_full(g_int64_hash, g_int64_equal, NULL, view_free);
    H.menu_ids = g_ptr_array_new_with_free_func(g_free);
    H.accel_actions = g_ptr_array_new_with_free_func(g_free);
    H.shortcuts = g_hash_table_new_full(g_str_hash, g_str_equal, g_free, NULL);

    /* One process per window; with singleInstance, a second launch hands its paths to the first. */
    H.unique = H.manifest->single_instance && !dev && !H.self_test && !H.canvas_test && g_application_id_is_valid(H.manifest->id);
    GApplicationFlags flags = G_APPLICATION_HANDLES_OPEN | (H.unique ? G_APPLICATION_DEFAULT_FLAGS : G_APPLICATION_NON_UNIQUE);
    g_set_prgname(H.manifest->id);
    g_set_application_name(H.manifest->name);
    H.app = gtk_application_new(g_application_id_is_valid(H.manifest->id) ? H.manifest->id : NULL, flags);
    g_signal_connect(H.app, "startup", G_CALLBACK(startup), NULL);
    g_signal_connect(H.app, "activate", G_CALLBACK(activate), NULL);
    g_signal_connect(H.app, "open", G_CALLBACK(open_files), NULL);

    if (!g_application_register(G_APPLICATION(H.app), NULL, &error)) {
        fprintf(stderr, "tui-app-host: %s\n", error->message);
        return 1;
    }
    if (g_application_get_is_remote(G_APPLICATION(H.app)) && H.feature_test) {
        fprintf(stderr, "feature-test: FAILED: %s already runs: quit it first, it is the single instance\n", H.manifest->name);
        return 1;
    }
    if (g_application_get_is_remote(G_APPLICATION(H.app))) {
        /* Another instance has the window: it opens the files, or comes forward. */
        if (files->len > 0) {
            g_application_open(G_APPLICATION(H.app), (GFile **) files->pdata, (int) files->len, "");
        } else {
            g_application_activate(G_APPLICATION(H.app));
        }
        /* The call is only queued: it must leave before this process does. */
        g_dbus_connection_flush_sync(g_application_get_dbus_connection(G_APPLICATION(H.app)), NULL, NULL);
        return 0;
    }
    add_open_files((GFile **) files->pdata, (int) files->len);
    char *no_arguments[] = {argv[0], NULL};
    int status = g_application_run(G_APPLICATION(H.app), 1, no_arguments);
    if (NULL != H.child && !H.exited) {
        g_subprocess_force_exit(H.child);
    }
    if (H.self_test && 0 == H.status && 3 != H.test_stage) {
        fprintf(stderr, "self-test: FAIL: the window closed at stage %d\n", H.test_stage);
        H.status = 1;
    }
    if (H.feature_test && !H.ft_done) {
        fprintf(stderr, "feature-test: FAILED: the host ended while %s\n", NULL != H.ft_stage ? H.ft_stage : "starting");
        H.status = 1;
    }
    if (H.canvas_test && !H.ct_passed) {
        if (!H.ct_done) {
            fprintf(stderr, "canvas-test: FAILED: the host ended while %s\n", NULL != H.ct_stage ? H.ct_stage : "starting");
        }
        H.status = 1;
    }
    return 0 != status ? status : H.status;
}
