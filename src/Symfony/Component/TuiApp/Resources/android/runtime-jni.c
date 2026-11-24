#include <jni.h>
#include <stdlib.h>
#include <unistd.h>
#include <string.h>
#include "../mobile/runtime.h"
JNIEXPORT jint JNICALL Java_org_symfony_tui_HostSession_execute(JNIEnv *env, jclass type, jstring entry, jstring address, jstring token, jstring temporary)
{
    (void) type;
    const char *path = (*env)->GetStringUTFChars(env, entry, NULL);
    const char *addr = (*env)->GetStringUTFChars(env, address, NULL);
    const char *secret = (*env)->GetStringUTFChars(env, token, NULL);
    const char *cache = (*env)->GetStringUTFChars(env, temporary, NULL);
    int status = -1;
    if (path && addr && secret && cache && !setenv("TMPDIR", cache, 1)) {
        char *directory = strdup(path);
        if (directory) {
            char *slash = strrchr(directory, '/');
            if (slash) *slash = '\0';
            if (!chdir(directory)) status = tui_mobile_run(path, addr, secret);
            free(directory);
        }
    }
    if (path) (*env)->ReleaseStringUTFChars(env, entry, path);
    if (addr) (*env)->ReleaseStringUTFChars(env, address, addr);
    if (secret) (*env)->ReleaseStringUTFChars(env, token, secret);
    if (cache) (*env)->ReleaseStringUTFChars(env, temporary, cache);
    return status;
}

JNIEXPORT void JNICALL Java_org_symfony_tui_HostSession_setForeground(JNIEnv *env, jclass type, jboolean foreground)
{
    (void) env;
    (void) type;
    tui_mobile_set_foreground(foreground);
}

JNIEXPORT jint JNICALL Java_org_symfony_tui_HostSession_setIni(JNIEnv *env, jclass type, jbyteArray name, jbyteArray value)
{
    (void) type;
    jsize name_length = (*env)->GetArrayLength(env, name);
    jsize value_length = (*env)->GetArrayLength(env, value);
    char *key = calloc((size_t) name_length + 1, 1);
    char *text = calloc((size_t) value_length + 1, 1);
    if (!key || !text) { free(key); free(text); return -1; }
    (*env)->GetByteArrayRegion(env, name, 0, name_length, (jbyte *) key);
    (*env)->GetByteArrayRegion(env, value, 0, value_length, (jbyte *) text);
    int result = -1;
    if (!(*env)->ExceptionCheck(env) && !memchr(key, 0, (size_t) name_length) && !memchr(text, 0, (size_t) value_length)) result = tui_mobile_set_ini(key, text);
    free(key); free(text);
    return result;
}
