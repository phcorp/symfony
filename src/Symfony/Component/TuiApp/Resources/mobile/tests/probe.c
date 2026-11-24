#include "../runtime.h"
#include <arpa/inet.h>
#include <pthread.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <unistd.h>

static char address[80];
static const char *entry;
static const char *token = "0123456789abcdef0123456789abcdef";
static int result = -1;

static void *run_php(void *unused)
{
    (void) unused;
    result = tui_mobile_run(entry, address, token);
    return NULL;
}

static int transfer(int socket, void *buffer, size_t length, int writing)
{
    unsigned char *bytes = buffer;
    while (length) {
        ssize_t count = writing ? send(socket, bytes, length, 0) : recv(socket, bytes, length, 0);
        if (count <= 0) return -1;
        bytes += count;
        length -= (size_t) count;
    }
    return 0;
}

static int receive_frame(int socket, char *buffer, size_t capacity)
{
    uint32_t size;
    if (transfer(socket, &size, 4, 0)) return -1;
    size = ntohl(size);
    if (!size || size >= capacity) return -1;
    if (transfer(socket, buffer, size, 0)) return -1;
    buffer[size] = 0;
    return 0;
}

int tui_mobile_probe(const char *path)
{
    entry = path;
    int listener = socket(AF_INET, SOCK_STREAM, 0);
    if (listener < 0) return 1;
    struct sockaddr_in endpoint = {0};
    endpoint.sin_family = AF_INET;
    endpoint.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    if (bind(listener, (struct sockaddr *) &endpoint, sizeof(endpoint)) || listen(listener, 1)) return 2;
    socklen_t size = sizeof(endpoint);
    if (getsockname(listener, (struct sockaddr *) &endpoint, &size)) return 3;
    snprintf(address, sizeof(address), "tcp://127.0.0.1:%u", ntohs(endpoint.sin_port));
    pthread_t worker;
    if (pthread_create(&worker, NULL, run_php, NULL)) return 4;
    fd_set ready;
    FD_ZERO(&ready);
    FD_SET(listener, &ready);
    struct timeval timeout = {10, 0};
    if (select(listener + 1, &ready, NULL, NULL, &timeout) != 1) return 5;
    int peer = accept(listener, NULL, NULL);
    close(listener);
    if (peer < 0) return 6;
    setsockopt(peer, SOL_SOCKET, SO_RCVTIMEO, &timeout, sizeof(timeout));
    setsockopt(peer, SOL_SOCKET, SO_SNDTIMEO, &timeout, sizeof(timeout));
    char buffer[4096];
    if (receive_frame(peer, buffer, sizeof(buffer)) || !strstr(buffer, token) || !strstr(buffer, "\"auth\"")) return 7;
    char hello[] = "{\"type\":\"hello\",\"protocol\":1,\"columns\":40,\"rows\":20,\"appearance\":\"light\"}";
    uint32_t length = htonl(sizeof(hello) - 1);
    if (transfer(peer, &length, 4, 1) || transfer(peer, hello, sizeof(hello) - 1, 1)) return 8;
    if (receive_frame(peer, buffer, sizeof(buffer)) || !strstr(buffer, "PHP mobile runtime passed")) return 9;
    tui_mobile_set_foreground(0);
    char resume = 'r';
    if (transfer(peer, &resume, 1, 1)) return 10;
    FD_ZERO(&ready);
    FD_SET(peer, &ready);
    struct timeval paused = {0, 250000};
    if (select(peer + 1, &ready, NULL, NULL, &paused) != 0) return 11;
    tui_mobile_set_foreground(1);
    if (receive_frame(peer, buffer, sizeof(buffer)) || !strstr(buffer, "resumed")) return 12;
    close(peer);
    pthread_join(worker, NULL);
    return result;
}

#ifdef TUI_PROBE_MAIN
int main(int argc, char **argv)
{
    if (argc != 2) return 64;
    int status = tui_mobile_probe(argv[1]);
    printf("TUI_MOBILE_PROBE=%d\n", status);
    return status;
}
#endif
