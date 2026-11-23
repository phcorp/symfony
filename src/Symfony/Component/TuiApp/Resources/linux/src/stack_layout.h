/* The layout of a stack node: children in a row or column, `spacing` pixels
 * apart; children with a grow weight (set with tui_stack_layout_set_grow)
 * share the free space by weight, the others take their natural size. */
#pragma once

#include <gtk/gtk.h>

G_BEGIN_DECLS

#define TUI_TYPE_STACK_LAYOUT (tui_stack_layout_get_type())
G_DECLARE_FINAL_TYPE(TuiStackLayout, tui_stack_layout, TUI, STACK_LAYOUT, GtkLayoutManager)

typedef void (*TuiAllocatedFunc)(GtkWidget *widget, int width, int height, gpointer data);

GtkLayoutManager *tui_stack_layout_new(GtkOrientation orientation);
void tui_stack_layout_configure(TuiStackLayout *self, GtkOrientation orientation, int spacing);
/* Called after every allocation of the widget, with its size. */
void tui_stack_layout_on_allocated(TuiStackLayout *self, TuiAllocatedFunc func, gpointer data);
void tui_stack_layout_set_grow(GtkWidget *child, int grow);
int tui_stack_layout_get_grow(GtkWidget *child);

G_END_DECLS
