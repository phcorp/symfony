package org.symfony.tui.runtimeprobe;

import android.app.Activity;
import android.os.Bundle;
import android.widget.TextView;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;

public final class ProbeActivity extends Activity {
  static {
    System.loadLibrary("tuiprobe");
  }

  private static native int probe(String entry, String temporary);

  @Override
  public void onCreate(Bundle state) {
    super.onCreate(state);
    TextView label = new TextView(this);
    label.setText("Checking embedded PHP…");
    setContentView(label);
    new Thread(
            () -> {
              String receipt;
              try {
                File entry = new File(getFilesDir(), "probe.php");
                try (InputStream input = getAssets().open("probe.php");
                    FileOutputStream output = new FileOutputStream(entry)) {
                  byte[] buffer = new byte[8192];
                  int count;
                  while ((count = input.read(buffer)) != -1) output.write(buffer, 0, count);
                }
                receipt =
                    "TUI_MOBILE_PROBE="
                        + probe(entry.getAbsolutePath(), getCacheDir().getAbsolutePath())
                        + "\n";
                try (FileOutputStream output = openFileOutput("probe.txt", MODE_PRIVATE)) {
                  output.write(receipt.getBytes(StandardCharsets.UTF_8));
                }
              } catch (Exception error) {
                receipt = error.toString();
              }
              String text = receipt;
              runOnUiThread(() -> label.setText(text));
            },
            "tui-php-probe")
        .start();
  }
}
