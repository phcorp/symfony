#ifndef TUI_MOBILE_RUNTIME_H
#define TUI_MOBILE_RUNTIME_H
/* Startup settings are copied before the interpreter starts. */
int tui_mobile_set_ini(const char *name, const char *value);
void tui_mobile_set_foreground(int foreground);
/* Run once, on a dedicated worker thread. The entry must be bundled app code. */
int tui_mobile_run(const char *entry, const char *address, const char *token);
#endif
