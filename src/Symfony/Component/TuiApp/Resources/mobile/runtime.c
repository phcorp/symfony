#include "runtime.h"
#include <php_embed.h>
#include <stdlib.h>
#include <stdatomic.h>
#include <string.h>
#include <pthread.h>

#ifdef ZTS
#error "The mobile host requires a non-thread-safe embedded PHP build"
#endif

static atomic_flag started = ATOMIC_FLAG_INIT;
static pthread_mutex_t lifecycle_mutex = PTHREAD_MUTEX_INITIALIZER;
static pthread_cond_t lifecycle_changed = PTHREAD_COND_INITIALIZER;
static int background;
static int initialized;
static void (*previous_interrupt)(zend_execute_data *);
static int configured;
struct ini_option { char *name; char *value; struct ini_option *next; };
static struct ini_option *options;

int tui_mobile_set_ini(const char *name, const char *value)
{
    if (!name || !*name || !value) return -1;
    pthread_mutex_lock(&lifecycle_mutex);
    if (configured) { pthread_mutex_unlock(&lifecycle_mutex); return -1; }
    for (struct ini_option *existing = options; existing; existing = existing->next) {
        if (!strcmp(existing->name, name)) {
            char *replacement = strdup(value);
            if (!replacement) { pthread_mutex_unlock(&lifecycle_mutex); return -1; }
            free(existing->value);
            existing->value = replacement;
            pthread_mutex_unlock(&lifecycle_mutex);
            return 0;
        }
    }
    struct ini_option *option = malloc(sizeof(*option));
    if (!option) { pthread_mutex_unlock(&lifecycle_mutex); return -1; }
    option->name = strdup(name);
    option->value = strdup(value);
    if (!option->name || !option->value) {
        free(option->name); free(option->value); free(option);
        pthread_mutex_unlock(&lifecycle_mutex);
        return -1;
    }
    option->next = options;
    options = option;
    pthread_mutex_unlock(&lifecycle_mutex);
    return 0;
}


static void mobile_interrupt(zend_execute_data *execute_data)
{
    pthread_mutex_lock(&lifecycle_mutex);
    while (background) pthread_cond_wait(&lifecycle_changed, &lifecycle_mutex);
    pthread_mutex_unlock(&lifecycle_mutex);
    if (previous_interrupt) previous_interrupt(execute_data);
}

void tui_mobile_set_foreground(int foreground)
{
    pthread_mutex_lock(&lifecycle_mutex);
    background = !foreground;
    if (foreground) pthread_cond_broadcast(&lifecycle_changed);
    else if (initialized) zend_atomic_bool_store_ex(&EG(vm_interrupt), true);
    pthread_mutex_unlock(&lifecycle_mutex);
}


static void ini_default(HashTable *configuration, const char *name, const char *text)
{
    zval value;
    ZVAL_NEW_STR(&value, zend_string_init(text, strlen(text), 1));
    zend_hash_str_update(configuration, name, strlen(name), &value);
}

static void mobile_ini_defaults(HashTable *configuration)
{
    zval value;
    ZVAL_NEW_STR(&value, zend_string_init("0", 1, 1));
    zend_hash_str_update(configuration, "opcache.enable", sizeof("opcache.enable") - 1, &value);
    ini_default(configuration, "log_errors", "1");
    ini_default(configuration, "display_errors", "0");
    const char *temporary = getenv("TMPDIR");
    if (temporary) {
        ZVAL_NEW_STR(&value, zend_string_init(temporary, strlen(temporary), 1));
        zend_hash_str_update(configuration, "opcache.lockfile_path", sizeof("opcache.lockfile_path") - 1, &value);
        size_t length = strlen(temporary) + sizeof("/tui-php.log");
        char *log = malloc(length);
        if (log) {
            snprintf(log, length, "%s/tui-php.log", temporary);
            ini_default(configuration, "error_log", log);
            free(log);
        }
    }
    for (struct ini_option *option = options; option; option = option->next) {
        ini_default(configuration, option->name, option->value);
    }
}

int tui_mobile_run(const char *entry, const char *address, const char *token)
{
    if (!entry || !address || !token || atomic_flag_test_and_set(&started)) {
        return -1;
    }
    if (setenv("TUI_APP_ADDR", address, 1) || setenv("TUI_APP_TOKEN", token, 1)) {
        unsetenv("TUI_APP_ADDR");
        unsetenv("TUI_APP_TOKEN");
        return -1;
    }
    pthread_mutex_lock(&lifecycle_mutex);
    configured = 1;
    pthread_mutex_unlock(&lifecycle_mutex);
    char *argv[] = {"tui-app", (char *) entry, NULL};
    php_embed_module.php_ini_ignore = 1;
    php_embed_module.ini_defaults = mobile_ini_defaults;
    if (php_embed_init(2, argv) == FAILURE) {
        unsetenv("TUI_APP_ADDR");
        unsetenv("TUI_APP_TOKEN");
        return -1;
    }
    pthread_mutex_lock(&lifecycle_mutex);
    previous_interrupt = zend_interrupt_function;
    zend_interrupt_function = mobile_interrupt;
    initialized = 1;
    if (background) zend_atomic_bool_store_ex(&EG(vm_interrupt), true);
    pthread_mutex_unlock(&lifecycle_mutex);
    int status = 0;
    zend_first_try {
        zend_file_handle file;
        zend_stream_init_filename(&file, entry);
        if (!php_execute_script(&file)) {
            status = 1;
        }
        zend_destroy_file_handle(&file);
    } zend_catch {
        status = EG(exit_status);
    } zend_end_try();
    pthread_mutex_lock(&lifecycle_mutex);
    initialized = 0;
    zend_interrupt_function = previous_interrupt;
    pthread_mutex_unlock(&lifecycle_mutex);
    php_embed_shutdown();
    while (options) {
        struct ini_option *option = options;
        options = option->next;
        free(option->name); free(option->value); free(option);
    }
    unsetenv("TUI_APP_ADDR");
    unsetenv("TUI_APP_TOKEN");
    return status;
}
