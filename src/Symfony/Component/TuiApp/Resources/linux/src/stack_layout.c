#include "stack_layout.h"

struct _TuiStackLayout {
    GtkLayoutManager parent_instance;
    GtkOrientation orientation;
    int spacing;
    TuiAllocatedFunc allocated;
    gpointer allocated_data;
};

G_DEFINE_FINAL_TYPE(TuiStackLayout, tui_stack_layout, GTK_TYPE_LAYOUT_MANAGER)

#define GROW_KEY "tui-grow"

void tui_stack_layout_set_grow(GtkWidget *child, int grow)
{
    g_object_set_data(G_OBJECT(child), GROW_KEY, GINT_TO_POINTER(MAX(0, grow)));
}

int tui_stack_layout_get_grow(GtkWidget *child)
{
    return GPOINTER_TO_INT(g_object_get_data(G_OBJECT(child), GROW_KEY));
}

static GPtrArray *laid_out_children(GtkWidget *widget)
{
    GPtrArray *children = g_ptr_array_new();
    for (GtkWidget *child = gtk_widget_get_first_child(widget); NULL != child; child = gtk_widget_get_next_sibling(child)) {
        if (gtk_widget_should_layout(child)) {
            g_ptr_array_add(children, child);
        }
    }
    return children;
}

static GtkOrientation other(GtkOrientation orientation)
{
    return GTK_ORIENTATION_HORIZONTAL == orientation ? GTK_ORIENTATION_VERTICAL : GTK_ORIENTATION_HORIZONTAL;
}

/* Sizes along the main axis for `available` pixels: everyone gets its minimum,
 * then the natural sizes as far as they fit, then the rest goes to the
 * growing children by weight. `cross` is the size across, or -1. */
static int *distribute(TuiStackLayout *self, GPtrArray *children, int available, int cross)
{
    guint count = children->len;
    int *sizes = g_new0(int, MAX(1u, count));
    GtkRequestedSize *requested = g_new0(GtkRequestedSize, MAX(1u, count));
    int extra = available - (count > 1 ? (int) (count - 1) * self->spacing : 0);
    int weights = 0;

    for (guint i = 0; i < count; i++) {
        int minimum = 0;
        int natural = 0;
        gtk_widget_measure(children->pdata[i], self->orientation, cross, &minimum, &natural, NULL, NULL);
        requested[i].data = children->pdata[i];
        requested[i].minimum_size = minimum;
        requested[i].natural_size = natural;
        extra -= minimum;
        weights += tui_stack_layout_get_grow(children->pdata[i]);
    }
    if (extra > 0) {
        extra = gtk_distribute_natural_allocation(extra, count, requested);
    }
    for (guint i = 0; i < count; i++) {
        sizes[i] = requested[i].minimum_size;
    }
    if (extra > 0 && weights > 0) {
        int left = extra;
        int last = -1;
        for (guint i = 0; i < count; i++) {
            int grow = tui_stack_layout_get_grow(children->pdata[i]);
            if (grow > 0) {
                int share = (int) ((gint64) extra * grow / weights);
                sizes[i] += share;
                left -= share;
                last = (int) i;
            }
        }
        sizes[last] += left; /* rounding goes to the last grower */
    }
    g_free(requested);
    return sizes;
}

static GtkSizeRequestMode get_request_mode(GtkLayoutManager *manager, GtkWidget *widget)
{
    (void) widget;
    return GTK_ORIENTATION_VERTICAL == TUI_STACK_LAYOUT(manager)->orientation ? GTK_SIZE_REQUEST_HEIGHT_FOR_WIDTH : GTK_SIZE_REQUEST_WIDTH_FOR_HEIGHT;
}

static void measure(GtkLayoutManager *manager, GtkWidget *widget, GtkOrientation orientation, int for_size, int *minimum, int *natural,
    int *minimum_baseline, int *natural_baseline)
{
    TuiStackLayout *self = TUI_STACK_LAYOUT(manager);
    g_autoptr(GPtrArray) children = laid_out_children(widget);
    int min = 0;
    int nat = 0;

    if (orientation == self->orientation) {
        for (guint i = 0; i < children->len; i++) {
            int child_min = 0;
            int child_nat = 0;
            gtk_widget_measure(children->pdata[i], orientation, for_size, &child_min, &child_nat, NULL, NULL);
            min += child_min;
            nat += child_nat;
        }
        if (children->len > 1) {
            min += (int) (children->len - 1) * self->spacing;
            nat += (int) (children->len - 1) * self->spacing;
        }
    } else {
        g_autofree int *sizes = for_size >= 0 ? distribute(self, children, for_size, -1) : NULL;
        for (guint i = 0; i < children->len; i++) {
            int child_min = 0;
            int child_nat = 0;
            gtk_widget_measure(children->pdata[i], orientation, NULL != sizes ? sizes[i] : -1, &child_min, &child_nat, NULL, NULL);
            min = MAX(min, child_min);
            nat = MAX(nat, child_nat);
        }
    }
    *minimum = min;
    *natural = nat;
    *minimum_baseline = -1;
    *natural_baseline = -1;
}

static void allocate(GtkLayoutManager *manager, GtkWidget *widget, int width, int height, int baseline)
{
    TuiStackLayout *self = TUI_STACK_LAYOUT(manager);
    g_autoptr(GPtrArray) children = laid_out_children(widget);
    gboolean vertical = GTK_ORIENTATION_VERTICAL == self->orientation;
    int main_size = vertical ? height : width;
    int cross = vertical ? width : height;
    g_autofree int *sizes = distribute(self, children, main_size, cross);
    int position = 0;

    (void) baseline;
    for (guint i = 0; i < children->len; i++) {
        GtkWidget *child = children->pdata[i];
        int cross_min = 0;
        gtk_widget_measure(child, other(self->orientation), sizes[i], &cross_min, NULL, NULL, NULL);
        GtkAllocation allocation = vertical ? (GtkAllocation) {0, position, MAX(cross, cross_min), sizes[i]}
                                            : (GtkAllocation) {position, 0, sizes[i], MAX(cross, cross_min)};
        gtk_widget_size_allocate(child, &allocation, -1);
        position += sizes[i] + self->spacing;
    }
    if (NULL != self->allocated) {
        self->allocated(widget, width, height, self->allocated_data);
    }
}

static void tui_stack_layout_class_init(TuiStackLayoutClass *klass)
{
    GtkLayoutManagerClass *manager = GTK_LAYOUT_MANAGER_CLASS(klass);
    manager->get_request_mode = get_request_mode;
    manager->measure = measure;
    manager->allocate = allocate;
}

static void tui_stack_layout_init(TuiStackLayout *self)
{
    self->orientation = GTK_ORIENTATION_VERTICAL;
}

GtkLayoutManager *tui_stack_layout_new(GtkOrientation orientation)
{
    TuiStackLayout *self = g_object_new(TUI_TYPE_STACK_LAYOUT, NULL);
    self->orientation = orientation;
    return GTK_LAYOUT_MANAGER(self);
}

void tui_stack_layout_configure(TuiStackLayout *self, GtkOrientation orientation, int spacing)
{
    if (self->orientation != orientation || self->spacing != spacing) {
        self->orientation = orientation;
        self->spacing = MAX(0, spacing);
        gtk_layout_manager_layout_changed(GTK_LAYOUT_MANAGER(self));
    }
}

void tui_stack_layout_on_allocated(TuiStackLayout *self, TuiAllocatedFunc func, gpointer data)
{
    self->allocated = func;
    self->allocated_data = data;
}
