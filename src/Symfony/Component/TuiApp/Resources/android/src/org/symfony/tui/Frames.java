package org.symfony.tui;

import java.io.*;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import org.json.*;

final class Frames {
  static final int LIMIT = 16 * 1024 * 1024;

  static JSONObject read(InputStream stream) throws IOException, JSONException {
    DataInputStream input = new DataInputStream(stream);
    int size = input.readInt();
    if (size < 1 || size > LIMIT) throw new IOException("Invalid frame length: " + size);
    byte[] payload = new byte[size];
    input.readFully(payload);
    return new JSONObject(
        StandardCharsets.UTF_8
            .newDecoder()
            .onMalformedInput(java.nio.charset.CodingErrorAction.REPORT)
            .onUnmappableCharacter(java.nio.charset.CodingErrorAction.REPORT)
            .decode(java.nio.ByteBuffer.wrap(payload))
            .toString());
  }

  static void write(OutputStream stream, JSONObject message) throws IOException {
    byte[] payload = message.toString().getBytes(StandardCharsets.UTF_8);
    if (payload.length > LIMIT) throw new IOException("Frame too large");
    DataOutputStream output = new DataOutputStream(stream);
    output.writeInt(payload.length);
    output.write(payload);
    output.flush();
  }

  static boolean matches(String given, String expected) {
    return MessageDigest.isEqual(
        given.getBytes(StandardCharsets.UTF_8), expected.getBytes(StandardCharsets.UTF_8));
  }
}
