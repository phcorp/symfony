package org.symfony.tui;

import android.content.res.AssetManager;
import java.io.*;
import java.nio.charset.StandardCharsets;
import java.util.*;
import org.json.*;

final class HostTests {
  static String text(AssetManager assets, String path) throws Exception {
    try (InputStream input = assets.open(path);
        ByteArrayOutputStream output = new ByteArrayOutputStream()) {
      byte[] bytes = new byte[8192];
      for (int n; (n = input.read(bytes)) != -1; ) output.write(bytes, 0, n);
      return new String(output.toByteArray(), StandardCharsets.UTF_8);
    }
  }

  static void check(boolean value, String message) {
    if (!value) throw new AssertionError(message);
  }

  static void equal(Object a, Object b) throws Exception {
    if (a instanceof JSONObject && b instanceof JSONObject) {
      JSONObject x = (JSONObject) a, y = (JSONObject) b;
      check(x.length() == y.length(), "Object size mismatch: " + x + " / " + y);
      Iterator<String> keys = x.keys();
      while (keys.hasNext()) {
        String key = keys.next();
        check(y.has(key), "Missing " + key);
        equal(x.get(key), y.get(key));
      }
    } else if (a instanceof JSONArray && b instanceof JSONArray) {
      JSONArray x = (JSONArray) a, y = (JSONArray) b;
      check(x.length() == y.length(), "Array size mismatch");
      for (int i = 0; i < x.length(); ++i) equal(x.get(i), y.get(i));
    } else if (a instanceof Number && b instanceof Number) {
      check(((Number) a).doubleValue() == ((Number) b).doubleValue(), "Number mismatch");
    } else check(Objects.equals(a, b), "Value mismatch: " + a + " / " + b);
  }

  static void run(AssetManager assets) throws Exception {
    JSONArray frames = new JSONObject(text(assets, "fixtures/frames.json")).getJSONArray("frames");
    for (int i = 0; i < frames.length(); ++i) {
      JSONObject fixture = frames.getJSONObject(i);
      String hex = fixture.getString("hex");
      byte[] bytes = new byte[hex.length() / 2];
      for (int j = 0; j < bytes.length; ++j)
        bytes[j] = (byte) Integer.parseInt(hex.substring(j * 2, j * 2 + 2), 16);
      ByteArrayOutputStream encoded = new ByteArrayOutputStream();
      Frames.write(encoded, new JSONObject(fixture.getString("json")));
      check(Arrays.equals(bytes, encoded.toByteArray()), "Frame encoding differs from fixture");
      equal(
          new JSONObject(fixture.getString("json")),
          Frames.read(
              new FilterInputStream(new ByteArrayInputStream(bytes)) {
                @Override
                public int read(byte[] buffer, int start, int count) throws IOException {
                  return super.read(buffer, start, Math.min(1, count));
                }
              }));
    }
    HostSession.parseManifest(text(assets, "fixtures/manifest.json"));
    try {
      HostSession.parseManifest(
          "{manifest:2,launch:{mode:'embedded'},app:{name:'Test',id:'com.acme.test'}}");
      throw new AssertionError("Unknown manifest accepted");
    } catch (JSONException expected) {
    }
    JSONArray messages = new JSONArray(text(assets, "fixtures/messages.json"));
    Map<String, JSONObject> patches = new HashMap<>();
    for (int i = 0; i < messages.length(); ++i) {
      JSONObject item = messages.getJSONObject(i);
      patches.put(item.getString("name"), item.getJSONObject("message"));
    }
    check(HostActivity.limit("😀", "😀é日", 2).equals("😀é"), "Emoji limit failed");
    check(HostActivity.limit("abc", "ab日c", 3).equals("abc"), "Replacement limit failed");
    check(HostActivity.limit("abcd", "abcde", 2).equals("abcd"), "Overlong value grew");
    NodeTree tree = new NodeTree();
    JSONArray steps = new JSONObject(text(assets, "fixtures/session.json")).getJSONArray("steps");
    for (int i = 0; i < steps.length(); ++i) {
      JSONObject step = steps.getJSONObject(i);
      tree.apply(patches.get(step.getString("patch")).getJSONArray("ops"));
      equal(step.get("tree"), tree.snapshot());
    }
    NodeTree empty = new NodeTree();
    empty.apply(new JSONArray("[{op:'create',id:1,kind:'label',parent:null,index:0,props:[]}]"));
    check(empty.nodes.get(1).props.length() == 0, "Empty-array props were rejected");
    NodeTree cycle = new NodeTree();
    cycle.apply(
        new JSONArray(
            "[{op:'create',id:1,kind:'stack',parent:null,index:0,props:{}},{op:'create',id:2,kind:'stack',parent:1,index:0,props:{}},{op:'move',id:1,parent:2,index:0}]"));
    check(cycle.root == 1 && cycle.nodes.get(2).parent == 1, "Cycle was accepted");
    cycle.apply(
        new JSONArray(
            "[{op:'update',id:2,props:{text:'x'}},{op:'update',id:2,props:{text:null}}]"));
    check(!cycle.nodes.get(2).props.has("text"), "Null property retained");
    for (int i = 0; i < messages.length(); ++i) {
      JSONObject message = messages.getJSONObject(i).getJSONObject("message");
      ByteArrayOutputStream output = new ByteArrayOutputStream();
      Frames.write(output, message);
      equal(message, Frames.read(new ByteArrayInputStream(output.toByteArray())));
    }
    try {
      Frames.read(new ByteArrayInputStream(new byte[] {1, 0, 0, 1}));
      throw new AssertionError("Oversized frame accepted");
    } catch (IOException expected) {
    }
    try {
      Frames.read(new ByteArrayInputStream(new byte[] {0, 0, 0, 4, '{'}));
      throw new AssertionError("Truncated frame accepted");
    } catch (IOException expected) {
    }
    check(
        Frames.matches("abc", "abc")
            && !Frames.matches("abc", "ab")
            && !Frames.matches("abc", "abd"),
        "Token comparison failed");
  }

  static void runControls(HostActivity activity) throws Exception {
    HostSession session = new HostSession();
    activity.session = session;
    session.tree.apply(
        new JSONArray(
            "[{op:'create',id:1,kind:'stack',parent:null,index:0,props:{}},{op:'create',id:2,kind:'textfield',parent:1,index:0,props:{value:'',maxLength:1}},{op:'create',id:3,kind:'password',parent:1,index:1,props:{value:''}},{op:'create',id:4,kind:'number',parent:1,index:2,props:{value:'1',min:0,max:10,step:0.5}},{op:'create',id:5,kind:'checkbox',parent:1,index:3,props:{text:'Disabled',disabled:true,checked:true}}]"));
    activity.render(null);
    HostActivity.Input field = (HostActivity.Input) activity.views.get(2);
    HostActivity.Input password = (HostActivity.Input) activity.views.get(3);
    check(
        password.getTransformationMethod()
            instanceof android.text.method.PasswordTransformationMethod,
        "Password is not secure");
    check(
        (password.getInputType() & android.text.InputType.TYPE_TEXT_VARIATION_PASSWORD) != 0,
        "Password IME mode is not secure");
    check(!activity.views.get(5).isEnabled(), "Disabled checkbox is interactive");
    HostActivity.NumberField number = (HostActivity.NumberField) activity.views.get(4);
    check(
        !(number.input.getKeyListener() instanceof android.text.method.DigitsKeyListener),
        "Number field discards composed text");
    number.getChildAt(2).performClick();
    check(
        session.tree.nodes.get(4).props.getString("value").equals("1.5"),
        "Number step did not reach model");
    check(Stepping.step("1", 1, 0.0, 1.1, 0.7).equals("1"), "Number step moved backwards");
    check(Stepping.snap(1.1, 0, 1.1, 0.7) == 0.7, "Slider is off its steps");
    session.tree.apply(
        new JSONArray(
            "[{op:'create',id:3,kind:'label',parent:1,index:1,props:{text:'Replacement'}}]"));
    activity.render(null);
    check(
        !(activity.views.get(3) instanceof HostActivity.Input),
        "Recreated node retained its old native control");
    session.tree.apply(
        new JSONArray(
            "[{op:'create',id:6,kind:'listbox',parent:1,index:4,props:{choices:['A','B'],selected:[0]}}]"));
    activity.render(null);
    android.widget.LinearLayout choices = (android.widget.LinearLayout) activity.views.get(6);
    choices.getChildAt(1).performClick();
    check(
        !((android.widget.CompoundButton) choices.getChildAt(0)).isChecked(),
        "Single selection retained previous checkmark");
    choices.getChildAt(1).performClick();
    check(
        ((android.widget.CompoundButton) choices.getChildAt(1)).isChecked(),
        "Single selection was unchecked");
    HostActivity.Surface surface = activity.new Surface(99);
    surface.setFocusable(false);
    activity.root.addView(surface);
    android.os.StrictMode.ThreadPolicy policy = android.os.StrictMode.getThreadPolicy();
    android.os.StrictMode.setThreadPolicy(
        new android.os.StrictMode.ThreadPolicy.Builder(policy).permitNetwork().build());
    try (java.net.ServerSocket listener = new java.net.ServerSocket(0);
        java.net.Socket sender = new java.net.Socket("127.0.0.1", listener.getLocalPort());
        java.net.Socket receiver = listener.accept()) {
      receiver.setSoTimeout(1000);
      session.peer = sender;
      for (int action :
          new int[] {android.view.MotionEvent.ACTION_DOWN, android.view.MotionEvent.ACTION_UP}) {
        android.view.MotionEvent motion = android.view.MotionEvent.obtain(0, 1, action, 1, 1, 0);
        surface.onTouchEvent(motion);
        motion.recycle();
      }
      for (String action : new String[] {"press", "release", "leave"}) {
        check(
            Frames.read(receiver.getInputStream()).getString("action").equals(action),
            "Touch sequence differs");
      }
      session.peer = null;
    }
    android.os.StrictMode.setThreadPolicy(policy);
    activity.root.removeView(surface);
    field.requestFocus();
    if (activity.getIntent().getBooleanExtra("drop-commits", false)) field.programmatic = true;
    android.view.inputmethod.InputConnection input =
        field.onCreateInputConnection(new android.view.inputmethod.EditorInfo());
    check(input != null, "No input connection");
    input.setComposingText("日本", 1);
    activity.root.post(
        () -> {
          try {
            check(field.getText().toString().equals("日本"), "Preedit was cut before commit");
            check(
                session.tree.nodes.get(2).props.optString("value").isEmpty(),
                "Preedit reached model");
            input.finishComposingText();
            activity.root.post(
                () -> {
                  try {
                    check(
                        session.tree.nodes.get(2).props.getString("value").equals("日"),
                        "Composition commit did not reach model once, within maxLength");
                    activity.receipt("controls-tests.txt", "CONTROLS_TESTS=0\n");
                  } catch (Throwable failure) {
                    activity.receipt("controls-tests.txt", failure.toString());
                  }
                });
          } catch (Throwable failure) {
            activity.receipt("controls-tests.txt", failure.toString());
          }
        });
  }
}
