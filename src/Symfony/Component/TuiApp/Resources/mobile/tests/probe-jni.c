#include <jni.h>
#include <stdlib.h>
int tui_mobile_probe(const char *path);
JNIEXPORT jint JNICALL Java_org_symfony_tui_runtimeprobe_ProbeActivity_probe(JNIEnv *env, jclass type, jstring entry, jstring temporary)
{
    (void) type;
    const char *path = (*env)->GetStringUTFChars(env, entry, NULL);
    if (!path) return -1;
    const char *cache = (*env)->GetStringUTFChars(env, temporary, NULL);
    if (!cache) {
        (*env)->ReleaseStringUTFChars(env, entry, path);
        return -1;
    }
    int status = setenv("TMPDIR", cache, 1) ? -1 : tui_mobile_probe(path);
    (*env)->ReleaseStringUTFChars(env, temporary, cache);
    (*env)->ReleaseStringUTFChars(env, entry, path);
    return status;
}
