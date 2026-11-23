package org.symfony.tui;

import android.content.Context;
import android.os.Handler;
import android.os.Looper;
import java.io.*;
import java.net.*;
import java.security.SecureRandom;
import java.util.concurrent.*;
import java.util.concurrent.atomic.AtomicInteger;
import org.json.*;

final class HostSession {
  static {
    System.loadLibrary("tuiapp");
  }

  static HostSession instance;

  private static native int setIni(byte[] name, byte[] value);

  static native void setForeground(boolean foreground);

  volatile boolean foreground = true;

  private static native int execute(String entry, String address, String token, String temporary);

  final NodeTree tree = new NodeTree();
  final Handler main = new Handler(Looper.getMainLooper());
  final ExecutorService writes = Executors.newSingleThreadExecutor();
  final AtomicInteger queued = new AtomicInteger();
  volatile Socket peer;
  HostActivity activity;
  JSONObject manifest;
  String error;
  boolean ended;
  boolean closing;
  int columns = 40, rows = 30;
  float cellWidth = 8, cellHeight = 18, scale = 1;
  String appearance = "light";

  void attach(HostActivity activity) {
    this.activity = activity;
    activity.render(null);
    if (error != null) activity.fail(error);
  }

  void detach(HostActivity activity) {
    if (this.activity == activity) this.activity = null;
  }

  void fail(Throwable failure) {
    main.post(
        () -> {
          if (error != null) return;
          error = failure.toString();
          if (activity != null) activity.fail(error);
        });
  }

  static File copy(Context context, String asset, File destination) throws IOException {
    String[] children = context.getAssets().list(asset);
    if (children != null && children.length != 0) {
      if (!destination.isDirectory() && !destination.mkdirs())
        throw new IOException("Cannot create " + destination);
      for (String child : children)
        copy(context, asset + "/" + child, new File(destination, child));
    } else {
      File parent = destination.getParentFile();
      if (!parent.isDirectory() && !parent.mkdirs())
        throw new IOException("Cannot create " + parent);
      try (InputStream input = context.getAssets().open(asset);
          OutputStream output = new FileOutputStream(destination)) {
        byte[] bytes = new byte[32768];
        for (int n; (n = input.read(bytes)) != -1; ) output.write(bytes, 0, n);
      }
    }
    return destination;
  }

  static String read(Context context, String asset) throws IOException {
    try (InputStream input = context.getAssets().open(asset);
        ByteArrayOutputStream output = new ByteArrayOutputStream()) {
      byte[] bytes = new byte[8192];
      for (int n; (n = input.read(bytes)) != -1; ) output.write(bytes, 0, n);
      return new String(output.toByteArray(), java.nio.charset.StandardCharsets.UTF_8);
    }
  }

  static JSONObject parseManifest(String source) throws JSONException {
    JSONObject manifest = new JSONObject(source);
    if (manifest.getInt("manifest") != 1) throw new JSONException("Unsupported manifest version");
    if (!manifest.getJSONObject("launch").getString("mode").equals("embedded"))
      throw new JSONException("Mobile apps require the embedded runtime");
    manifest.getJSONObject("app").getString("id");
    manifest.getJSONObject("app").getString("name");
    return manifest;
  }

  void start(Context context) {
    new Thread(
            () -> {
              try {
                new File(context.getFilesDir(), "host-error.txt").delete();
                manifest = parseManifest(read(context, "app.manifest.json"));
                String digest = read(context, "payload.sha256").trim();
                if (!digest.matches("[0-9a-f]{64}"))
                  throw new IOException("Invalid payload digest");
                File payload = new File(context.getFilesDir(), "tui-payload-" + digest);
                File complete = new File(payload, ".complete");
                if (!complete.isFile()) {
                  copy(context, "payload", payload);
                  if (!complete.createNewFile())
                    throw new IOException("Cannot finish payload installation");
                }
                JSONArray arguments = manifest.getJSONObject("launch").getJSONArray("args");
                for (int i = 0; i < arguments.length(); ++i) {
                  if (arguments.getString(i).startsWith("./app/")) break;
                  if (arguments.getString(i).equals("-d")) {
                    if (++i >= arguments.length()) throw new IOException("Missing PHP INI value");
                    String setting = arguments.getString(i);
                    int equals = setting.indexOf('=');
                    if (equals < 1
                        || setIni(
                                setting
                                    .substring(0, equals)
                                    .getBytes(java.nio.charset.StandardCharsets.UTF_8),
                                setting
                                    .substring(equals + 1)
                                    .getBytes(java.nio.charset.StandardCharsets.UTF_8))
                            != 0) throw new IOException("Cannot configure PHP INI setting");
                  }
                }
                String relative = read(context, "mobile.entry").trim();
                File entry = new File(payload, relative).getCanonicalFile();
                if (!entry.getPath().startsWith(payload.getCanonicalPath() + File.separator)
                    || !entry.isFile()) throw new IOException("Invalid application entry");
                byte[] random = new byte[16];
                new SecureRandom().nextBytes(random);
                StringBuilder hex = new StringBuilder();
                for (byte value : random)
                  hex.append(String.format(java.util.Locale.ROOT, "%02x", value & 255));
                String token = hex.toString();
                try (ServerSocket listener = new ServerSocket()) {
                  listener.bind(new InetSocketAddress(InetAddress.getByName("127.0.0.1"), 0), 4);
                  long deadline = System.nanoTime() + TimeUnit.SECONDS.toNanos(10);
                  String address = "tcp://127.0.0.1:" + listener.getLocalPort();
                  new Thread(
                          () -> {
                            int status =
                                execute(
                                    entry.getPath(),
                                    address,
                                    token,
                                    context.getCacheDir().getPath());
                            main.post(
                                () -> {
                                  ended = true;
                                  if (closing || (status == 0 && peer != null)) {
                                    if (activity != null) {
                                      activity.receipt("host-ended.txt", "PHP session ended\n");
                                      activity.finishAndRemoveTask();
                                    }
                                    android.os.Process.killProcess(android.os.Process.myPid());
                                    return;
                                  }
                                  if ((status != 0 || peer == null) && error == null) {
                                    String detail = "PHP exited with status " + status;
                                    try {
                                      detail +=
                                          "\n"
                                              + new String(
                                                  java.nio.file.Files.readAllBytes(
                                                      new File(context.getCacheDir(), "tui-php.log")
                                                          .toPath()),
                                                  java.nio.charset.StandardCharsets.UTF_8);
                                    } catch (IOException ignored) {
                                    }
                                    fail(new IOException(detail));
                                  }
                                });
                          },
                          "tui-php")
                      .start();
                  while (peer == null) {
                    int remaining =
                        (int)
                            Math.max(
                                1, TimeUnit.NANOSECONDS.toMillis(deadline - System.nanoTime()));
                    if (System.nanoTime() >= deadline)
                      throw new SocketTimeoutException(
                          "PHP did not authenticate within 10 seconds");
                    listener.setSoTimeout(remaining);
                    Socket candidate = listener.accept();
                    candidate.setSoTimeout(remaining);
                    try {
                      JSONObject auth = Frames.read(candidate.getInputStream());
                      if (!"auth".equals(auth.optString("type"))
                          || auth.optInt("protocol") != 1
                          || !Frames.matches(auth.optString("token"), token)) {
                        candidate.close();
                        continue;
                      }
                      peer = candidate;
                    } catch (Exception failure) {
                      candidate.close();
                    }
                  }
                }
                peer.setSoTimeout(0);
                peer.setTcpNoDelay(true);
                send(
                    new JSONObject()
                        .put("type", "hello")
                        .put("protocol", 1)
                        .put("os", "android")
                        .put("columns", columns)
                        .put("rows", rows)
                        .put("scale", scale)
                        .put("appearance", appearance)
                        .put(
                            "cell",
                            new JSONObject().put("width", cellWidth).put("height", cellHeight))
                        .put(
                            "capabilities",
                            new JSONArray(
                                new String[] {
                                  "appearance",
                                  "canvas-controls",
                                  "pointer",
                                  "touch",
                                  "clipboard",
                                  "password",
                                  "number",
                                  "slider",
                                  "listbox"
                                })));
                if (!foreground) setForeground(false);
                while (!peer.isClosed()) {
                  JSONObject message = Frames.read(peer.getInputStream());
                  main.post(
                      () -> {
                        try {
                          if ("patch".equals(message.optString("type"))) {
                            java.util.Set<Integer> changed =
                                tree.apply(message.getJSONArray("ops"));
                            if (activity != null) activity.render(changed);
                          } else if (activity != null) activity.message(message);
                        } catch (Exception failure) {
                          fail(failure);
                          close();
                        }
                      });
                }
              } catch (EOFException end) {
                main.post(
                    () -> {
                      ended = true;
                      if (activity != null) activity.ended();
                    });
              } catch (Exception failure) {
                fail(failure);
              } finally {
                close();
              }
            },
            "tui-host")
        .start();
  }

  void send(JSONObject message) {
    if (peer == null || peer.isClosed()) return;
    byte[] payload = message.toString().getBytes(java.nio.charset.StandardCharsets.UTF_8);
    int pending = queued.addAndGet(payload.length + 4);
    if (pending > Frames.LIMIT) {
      queued.addAndGet(-payload.length - 4);
      fail(new IOException("Host write buffer exceeded 16 MiB"));
      close();
      return;
    }
    writes.execute(
        () -> {
          try {
            java.io.DataOutputStream output = new java.io.DataOutputStream(peer.getOutputStream());
            output.writeInt(payload.length);
            output.write(payload);
            output.flush();
          } catch (IOException failure) {
            if (!ended) fail(failure);
            close();
          } finally {
            queued.addAndGet(-payload.length - 4);
          }
        });
  }

  void event(int node, String name, Object value) {
    try {
      JSONObject message =
          new JSONObject().put("type", "event").put("node", node).put("name", name);
      if (value != null) message.put("value", value);
      send(message);
    } catch (JSONException failure) {
      fail(failure);
    }
  }

  void close() {
    setForeground(true);
    Socket socket = peer;
    if (socket != null)
      try {
        socket.close();
      } catch (IOException ignored) {
      }
  }
}
