package org.symfony.tui;

import android.app.*;
import android.content.*;
import android.content.res.Configuration;
import android.graphics.*;
import android.os.*;
import android.text.*;
import android.text.method.PasswordTransformationMethod;
import android.view.*;
import android.view.inputmethod.*;
import android.widget.*;
import java.io.*;
import java.util.*;
import org.json.*;

public final class HostActivity extends Activity {
  HostSession session;
  FrameLayout root;
  ScrollView scroll;
  final Map<Integer, View> views = new LinkedHashMap<>();
  final Map<String, SettingRow> settingRows = new LinkedHashMap<>();
  float cw, ch, canvasSize;
  boolean applying;
  boolean testedInput;
  Boolean forcedDark;
  JSONObject launchManifest;

  @Override
  public void onCreate(Bundle state) {
    try {
      launchManifest = new JSONObject(HostSession.read(this, "app.manifest.json"));
      String theme = launchManifest.optJSONObject("window").optString("theme", "system");
      forcedDark =
          theme.equals("dark") ? Boolean.TRUE : theme.equals("light") ? Boolean.FALSE : null;
    } catch (Exception ignored) {
      launchManifest = new JSONObject();
    }
    setTheme(
        dark()
            ? android.R.style.Theme_Material_NoActionBar
            : android.R.style.Theme_Material_Light_NoActionBar);
    super.onCreate(state);
    getWindow().setSoftInputMode(WindowManager.LayoutParams.SOFT_INPUT_ADJUST_RESIZE);
    Paint font = new Paint();
    font.setTypeface(Typeface.MONOSPACE);
    JSONObject window = launchManifest.optJSONObject("window"),
        canvasFont = window == null ? null : window.optJSONObject("canvasFont");
    canvasSize =
        (float) (canvasFont == null ? 13 : canvasFont.optDouble("size", 13))
            * getResources().getDisplayMetrics().scaledDensity;
    font.setTextSize(canvasSize);
    cw = font.measureText("M");
    ch =
        Math.max(
            (float) Math.ceil(font.getFontSpacing()),
            40 * getResources().getDisplayMetrics().density);
    root = new FrameLayout(this);
    scroll = new ScrollView(this);
    scroll.setFillViewport(true);
    root.addView(scroll, new FrameLayout.LayoutParams(-1, -1));
    setContentView(root);
    if (Build.VERSION.SDK_INT >= 30) {
      getWindow().setDecorFitsSystemWindows(false);
      root.setOnApplyWindowInsetsListener(
          (v, insets) -> {
            android.graphics.Insets safe =
                insets.getInsets(
                    WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout());
            android.graphics.Insets keyboard = insets.getInsets(WindowInsets.Type.ime());
            v.setPadding(safe.left, safe.top, safe.right, Math.max(safe.bottom, keyboard.bottom));
            return insets;
          });
      android.view.WindowInsetsController controller = getWindow().getInsetsController();
      if (controller != null) {
        int mask =
            WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS
                | WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS;
        controller.setSystemBarsAppearance(dark() ? 0 : mask, mask);
      }
    }
    if (getIntent().getBooleanExtra("selftest", false)) {
      try {
        Class<?> tests = Class.forName("org.symfony.tui.HostTests");
        tests
            .getDeclaredMethod("run", android.content.res.AssetManager.class)
            .invoke(null, getAssets());
        receipt("tests.txt", "HOST_TESTS=0\n");
        TextView text = new TextView(this);
        text.setText("HOST_TESTS=0");
        root.addView(text);
      } catch (Throwable failure) {
        receipt("tests.txt", failure.toString());
        fail(failure.toString());
      }
      try {
        Class.forName("org.symfony.tui.HostTests")
            .getDeclaredMethod("runControls", HostActivity.class)
            .invoke(null, this);
      } catch (Throwable failure) {
        receipt(
            "controls-tests.txt",
            (failure.getCause() == null ? failure : failure.getCause()).toString());
      }
      return;
    }
    if (HostSession.instance == null) {
      HostSession.instance = new HostSession();
      session = HostSession.instance;
      session.cellWidth = cw;
      session.cellHeight = ch;
      session.scale = getResources().getDisplayMetrics().density;
      session.appearance = dark() ? "dark" : "light";
      session.attach(this);
      session.start(getApplicationContext());
    } else {
      session = HostSession.instance;
      session.attach(this);
    }
    scroll.addOnLayoutChangeListener(
        (v, l, t, r, b, ol, ot, or, ob) -> {
          int columns = Math.max(1, (int) (scroll.getWidth() / cw)),
              rows = Math.max(1, (int) (scroll.getHeight() / ch));
          if (columns != session.columns || rows != session.rows) {
            session.columns = columns;
            session.rows = rows;
            send(object("type", "resize", "columns", columns, "rows", rows));
            render(Collections.emptySet());
          }
        });
  }

  @Override
  protected void onStart() {
    super.onStart();
    if (session != null) {
      session.foreground = true;
      HostSession.setForeground(true);
    }
  }

  @Override
  protected void onStop() {
    if (session != null) {
      session.foreground = false;
      if (session.peer != null && !session.closing && !session.ended)
        HostSession.setForeground(false);
    }
    super.onStop();
  }

  @Override
  protected void onDestroy() {
    if (session != null) session.detach(this);
    super.onDestroy();
  }

  @Override
  public void onConfigurationChanged(Configuration configuration) {
    super.onConfigurationChanged(configuration);
    if (session != null) {
      session.appearance = dark() ? "dark" : "light";
      send(object("type", "appearance", "value", session.appearance));
      render(new LinkedHashSet<>(session.tree.nodes.keySet()));
    }
  }

  @Override
  public void onBackPressed() {
    View focus = getCurrentFocus();
    if (focus instanceof EditText) {
      focus.clearFocus();
      ((InputMethodManager) getSystemService(INPUT_METHOD_SERVICE))
          .hideSoftInputFromWindow(focus.getWindowToken(), 0);
    } else if (session != null && !session.ended) send(object("type", "close"));
    else {
      finishAndRemoveTask();
      android.os.Process.killProcess(android.os.Process.myPid());
    }
  }

  boolean dark() {
    if (forcedDark != null) return forcedDark;
    return (getResources().getConfiguration().uiMode & Configuration.UI_MODE_NIGHT_MASK)
        == Configuration.UI_MODE_NIGHT_YES;
  }

  void receipt(String name, String text) {
    try (FileOutputStream output = openFileOutput(name, MODE_PRIVATE)) {
      output.write(text.getBytes(java.nio.charset.StandardCharsets.UTF_8));
    } catch (IOException ignored) {
    }
  }

  static JSONObject object(Object... values) {
    JSONObject object = new JSONObject();
    try {
      for (int i = 0; i < values.length; i += 2) object.put((String) values[i], values[i + 1]);
    } catch (JSONException failure) {
      throw new IllegalArgumentException(failure);
    }
    return object;
  }

  void send(JSONObject message) {
    if (session != null) session.send(message);
  }

  void event(int id, String name, Object value) {
    if (!applying && enabled(id)) session.event(id, name, value);
  }

  boolean enabled(int id) {
    Integer next = id;
    while (next != null) {
      NodeTree.Node node = session.tree.nodes.get(next);
      if (node == null || node.props.optBoolean("disabled") || node.props.optBoolean("hidden"))
        return false;
      next = node.parent;
    }
    return true;
  }

  void fail(String text) {
    TextView message = new TextView(this);
    message.setText(text);
    message.setTextIsSelectable(true);
    root.removeAllViews();
    root.addView(message);
    receipt("host-error.txt", text);
  }

  void ended() {
    receipt("host-ended.txt", "PHP session ended\n");
  }

  void message(JSONObject message) throws JSONException {
    switch (message.optString("type")) {
      case "focus":
        {
          View view = views.get(message.optInt("node", -1));
          if (view != null) view.requestFocus();
          else if (message.isNull("node")) root.requestFocus();
          break;
        }
      case "clipboard":
        ((android.content.ClipboardManager) getSystemService(CLIPBOARD_SERVICE))
            .setPrimaryClip(ClipData.newPlainText("", message.optString("text")));
        break;
      case "window":
        if (!message.isNull("title")) setTitle(message.optString("title"));
        break;
      case "close-reply":
        if (message.optBoolean("accept")) {
          session.closing = true;
          session.close();
          root.postDelayed(
              () -> {
                finishAndRemoveTask();
                android.os.Process.killProcess(android.os.Process.myPid());
              },
              2000);
        }
        break;
      case "bye":
        session.ended = true;
        session.close();
        ended();
        break;
      default:
        break;
    }
  }

  LinearLayout column() {
    LinearLayout layout = new LinearLayout(this);
    layout.setOrientation(LinearLayout.VERTICAL);
    return layout;
  }

  TextView label(String text) {
    TextView label = new TextView(this);
    label.setText(text);
    label.setTextSize(14);
    return label;
  }

  static void detach(View view) {
    if (view.getParent() instanceof ViewGroup) ((ViewGroup) view.getParent()).removeView(view);
  }

  void attach(ViewGroup parent, View child, int index, ViewGroup.LayoutParams params) {
    if (child.getParent() != parent || parent.indexOfChild(child) != index) {
      detach(child);
      parent.addView(child, Math.min(index, parent.getChildCount()), params);
    } else child.setLayoutParams(params);
  }

  View make(NodeTree.Node node) {
    final int id = node.id;
    switch (node.kind) {
      case "label":
        {
          TextView label = label("");
          label.setTextIsSelectable(true);
          return label;
        }
      case "textfield":
      case "password":
      case "textarea":
        return new Input(id, node.kind);
      case "number":
        return new NumberField(id);
      case "button":
        {
          Button button = new Button(this);
          button.setOnClickListener(v -> event(id, "press", null));
          return button;
        }
      case "checkbox":
        {
          CompoundButton box =
              node.props.optString("group").isEmpty() ? new CheckBox(this) : new RadioButton(this);
          box.setOnCheckedChangeListener(
              (v, checked) -> {
                if (!applying) {
                  put(node.props, "checked", checked);
                  event(id, "toggle", checked);
                }
              });
          return box;
        }
      case "popup":
        {
          Spinner spinner = new Spinner(this);
          spinner.setOnItemSelectedListener(
              new AdapterView.OnItemSelectedListener() {
                public void onNothingSelected(AdapterView<?> parent) {}

                public void onItemSelected(
                    AdapterView<?> parent, View view, int position, long row) {
                  if (!applying && node.props.optInt("selected", -1) != position) {
                    put(node.props, "selected", position);
                    event(id, "select", position);
                  }
                }
              });
          return spinner;
        }
      case "slider":
        {
          SeekBar bar = new SeekBar(this);
          bar.setMax(10000);
          bar.setOnSeekBarChangeListener(
              new SeekBar.OnSeekBarChangeListener() {
                public void onStartTrackingTouch(SeekBar view) {}

                public void onStopTrackingTouch(SeekBar view) {}

                public void onProgressChanged(SeekBar view, int progress, boolean user) {
                  if (user) {
                    double min = node.props.optDouble("min", 0),
                        max = node.props.optDouble("max", 100),
                        step = node.props.optDouble("step", 1);
                    double value =
                        Stepping.snap(min + progress / 10000.0 * (max - min), min, max, step);
                    put(node.props, "value", value);
                    event(id, "change", value);
                  }
                }
              });
          return bar;
        }
      case "canvas":
        return new Surface(id);
      default:
        return column();
    }
  }

  static void put(JSONObject object, String key, Object value) {
    try {
      object.put(key, value);
    } catch (JSONException failure) {
      throw new IllegalArgumentException(failure);
    }
  }

  void render(Set<Integer> changed) {
    if (session == null) return;
    applying = true;
    try {
      Iterator<Map.Entry<Integer, View>> iterator = views.entrySet().iterator();
      while (iterator.hasNext()) {
        Map.Entry<Integer, View> item = iterator.next();
        if (!session.tree.nodes.containsKey(item.getKey())) {
          detach(item.getValue());
          iterator.remove();
        }
      }
      settingRows.values().removeIf(row -> !session.tree.nodes.containsKey(row.node));
      for (int id : session.tree.recreated) {
        View old = views.remove(id);
        if (old != null) detach(old);
        settingRows.values().removeIf(row -> row.node == id);
      }
      session.tree.recreated.clear();
      for (NodeTree.Node node : session.tree.nodes.values()) {
        View view = views.get(node.id);
        if (view == null) {
          view = make(node);
          view.setTag(node.id);
          views.put(node.id, view);
          update(node, view);
        } else if (changed == null || changed.contains(node.id)) update(node, view);
        view.setVisibility(node.props.optBoolean("hidden") ? View.GONE : View.VISIBLE);
        enable(view, enabled(node.id));
        if (node.props.has("label")) view.setContentDescription(node.props.optString("label"));
        if (node.props.optBoolean("focusable")) view.setFocusableInTouchMode(true);
      }
      for (NodeTree.Node node : session.tree.nodes.values()) structure(node);
      if (session.tree.root != null) {
        View view = views.get(session.tree.root);
        attach(scroll, view, 0, new android.widget.FrameLayout.LayoutParams(-1, -2));
      }
      root.post(this::rects);
    } catch (Exception failure) {
      fail(failure.toString());
    } finally {
      applying = false;
    }
    if (getIntent().getBooleanExtra("htmltest", false)) {
      if (!testedInput) {
        Input input = null;
        Button submit = null;
        for (NodeTree.Node node : session.tree.nodes.values()) {
          if (node.kind.equals("textfield")
              && node.props.optString("label").equals("Name")
              && views.get(node.id) instanceof Input) input = (Input) views.get(node.id);
          if (node.kind.equals("button")
              && node.props.optString("text").equals("Greet")
              && views.get(node.id) instanceof Button) submit = (Button) views.get(node.id);
        }
        if (input != null && submit != null) {
          testedInput = true;
          input.setText("Ada");
          input.commit();
          submit.performClick();
        }
      }
      for (NodeTree.Node node : session.tree.nodes.values())
        if (node.kind.equals("canvas") && node.props.toString().contains("Hello Ada!"))
          receipt("html-test.txt", "HTML_TEST=0\n");
    }
    if (getIntent().getBooleanExtra("uitest", false)) {
      if (!testedInput && views.get(3) instanceof Input) {
        testedInput = true;
        Input input = (Input) views.get(3);
        input.setText("Ada");
        input.commit();
        input.onEditorAction(EditorInfo.IME_ACTION_DONE);
      }
      View title = views.get(2);
      if (title instanceof TextView && ((TextView) title).getText().toString().equals("Hello Ada!"))
        receipt("ui-test.txt", "UI_TEST=0\n");
    }
  }

  void enable(View view, boolean enabled) {
    view.setEnabled(enabled && !Boolean.TRUE.equals(view.getTag(0x7f000001)));
    if (view instanceof ViewGroup)
      for (int i = 0; i < ((ViewGroup) view).getChildCount(); ++i)
        enable(((ViewGroup) view).getChildAt(i), enabled);
  }

  void update(NodeTree.Node node, View view) throws JSONException {
    JSONObject p = node.props;
    if (view instanceof Input) ((Input) view).update(p);
    else if (view instanceof NumberField) ((NumberField) view).input.update(p);
    else if (view instanceof CompoundButton) {
      ((CompoundButton) view).setText(p.optString("text"));
      ((CompoundButton) view).setChecked(p.optBoolean("checked"));
    } else if (view instanceof Button) ((Button) view).setText(p.optString("text"));
    else if (view instanceof TextView) {
      TextView text = (TextView) view;
      text.setText(runs(p.optJSONArray("runs"), p.optString("text")));
      JSONObject style = p.optJSONObject("style");
      text.setTextColor(
          color(style == null ? null : style.optString("fg"), dark() ? Color.WHITE : Color.BLACK));
      int traits =
          style == null
              ? Typeface.NORMAL
              : (style.optBoolean("bold") ? Typeface.BOLD : 0)
                  | (style.optBoolean("italic") ? Typeface.ITALIC : 0);
      text.setTypeface(Typeface.create(Typeface.DEFAULT, traits));
      int flags = text.getPaintFlags() & ~(Paint.UNDERLINE_TEXT_FLAG | Paint.STRIKE_THRU_TEXT_FLAG);
      if (style != null && style.optBoolean("underline")) flags |= Paint.UNDERLINE_TEXT_FLAG;
      if (style != null && style.optBoolean("strike")) flags |= Paint.STRIKE_THRU_TEXT_FLAG;
      text.setPaintFlags(flags);
      if (style != null && style.has("bg"))
        text.setBackgroundColor(color(style.optString("bg"), Color.TRANSPARENT));
      text.setGravity(
          Gravity.CENTER_VERTICAL
              | (p.optString("align").equals("center")
                  ? Gravity.CENTER_HORIZONTAL
                  : p.optString("align").equals("right") ? Gravity.RIGHT : Gravity.LEFT));
    } else if (view instanceof Spinner) {
      ArrayAdapter<String> adapter =
          new ArrayAdapter<>(
              this,
              android.R.layout.simple_spinner_dropdown_item,
              strings(p.optJSONArray("choices")));
      ((Spinner) view).setAdapter(adapter);
      ((Spinner) view).setSelection(Math.max(0, p.optInt("selected")), false);
    } else if (view instanceof SeekBar) {
      double min = p.optDouble("min", 0), max = p.optDouble("max", 100);
      ((SeekBar) view)
          .setProgress(
              max <= min
                  ? 0
                  : (int) Math.round((p.optDouble("value", min) - min) / (max - min) * 10000));
    } else if (view instanceof Surface) {
      ((Surface) view).props = p;
      view.setFocusableInTouchMode(p.optBoolean("focusable"));
      view.invalidate();
    } else if (node.kind.equals("stack")) {
      LinearLayout stack = (LinearLayout) view;
      stack.setOrientation(
          p.optString("direction").equals("horizontal")
              ? LinearLayout.HORIZONTAL
              : LinearLayout.VERTICAL);
      int padding = (int) (p.optDouble("padding", 0) * cw);
      stack.setPadding(padding, padding, padding, padding);
    } else if (node.kind.equals("tabs") || node.kind.equals("disclosure")) {
      LinearLayout layout = (LinearLayout) view;
      while (layout.getChildCount() > 0 && !(layout.getChildAt(0).getTag() instanceof Integer))
        layout.removeViewAt(0);
      if (node.kind.equals("tabs")) {
        LinearLayout tabs = new LinearLayout(this);
        String[] titles = strings(p.optJSONArray("tabs"));
        for (int i = 0; i < titles.length; ++i) {
          final int index = i;
          Button button = new Button(this);
          button.setText(titles[i]);
          button.setSelected(i == p.optInt("active"));
          button.setOnClickListener(
              v -> {
                put(p, "active", index);
                structure(node);
                event(node.id, "tab", index);
              });
          tabs.addView(button, new LinearLayout.LayoutParams(0, -2, 1));
        }
        layout.addView(tabs, 0);
      } else {
        Button button = new Button(this);
        button.setText(p.optString("summary"));
        button.setOnClickListener(
            v -> {
              boolean expanded = !p.optBoolean("expanded");
              put(p, "expanded", expanded);
              structure(node);
              event(node.id, "toggle", expanded);
            });
        layout.addView(button, 0);
      }
    } else if (node.kind.equals("list") || node.kind.equals("listbox")) {
      LinearLayout list = (LinearLayout) view;
      list.removeAllViews();
      JSONArray items = p.optJSONArray(node.kind.equals("list") ? "items" : "choices");
      if (items != null)
        for (int i = 0; i < items.length(); ++i) {
          final int index = i;
          Object item = items.get(i);
          String title =
              item instanceof JSONObject
                  ? ((JSONObject) item).optString("label")
                      + (((JSONObject) item).isNull("description")
                          ? ""
                          : "\n" + ((JSONObject) item).optString("description"))
                  : String.valueOf(item);
          if (p.optBoolean("multiple") || node.kind.equals("listbox")) {
            CheckBox box = new CheckBox(this);
            box.setText(title);
            String key = node.kind.equals("list") ? "checked" : "selected";
            box.setChecked(contains(p.optJSONArray(key), index));
            box.setOnClickListener(
                v -> {
                  if (!applying) {
                    JSONArray selected =
                        p.optBoolean("multiple")
                            ? toggle(p.optJSONArray(key), index, box.isChecked())
                            : new JSONArray().put(index);
                    put(p, key, selected);
                    for (int choice = 0; choice < list.getChildCount(); ++choice) {
                      ((CheckBox) list.getChildAt(choice)).setChecked(contains(selected, choice));
                    }
                    event(node.id, "select", selected);
                  }
                });
            list.addView(box);
          } else {
            Button button = new Button(this);
            button.setText(title);
            button.setSelected(p.optInt("selected", -1) == i);
            button.setOnClickListener(
                v -> {
                  put(p, "selected", index);
                  event(node.id, "select", index);
                });
            list.addView(button);
          }
        }
    } else if (node.kind.equals("form")) {
      updateForm(node, (LinearLayout) view);
    } else if (node.kind.equals("progress") || node.kind.equals("spinner")) {
      LinearLayout layout = (LinearLayout) view;
      layout.removeAllViews();
      ProgressBar bar =
          new ProgressBar(
              this,
              null,
              node.kind.equals("progress")
                  ? android.R.attr.progressBarStyleHorizontal
                  : android.R.attr.progressBarStyle);
      bar.setIndeterminate(p.isNull("progress"));
      bar.setMax(10000);
      bar.setProgress((int) (p.optDouble("progress", 0) * 10000));
      if (!node.kind.equals("spinner") || p.optBoolean("running", true)) layout.addView(bar);
      layout.addView(label(p.optString("text")));
      if (p.optBoolean("cancellable")) {
        Button cancel = new Button(this);
        cancel.setText("Cancel");
        cancel.setOnClickListener(v -> event(node.id, "cancel", null));
        layout.addView(cancel);
      }
    }
  }

  JSONObject setting(int node, String row) {
    NodeTree.Node model = session.tree.nodes.get(node);
    JSONArray rows = model == null ? null : model.props.optJSONArray("rows");
    if (rows != null)
      for (int i = 0; i < rows.length(); ++i) {
        JSONObject item = rows.optJSONObject(i);
        if (item != null && item.optString("id").equals(row)) return item;
      }
    return null;
  }

  void commitSetting(SettingRow row, String value) {
    JSONObject data = setting(row.node, row.id);
    if (data == null
        || data.optBoolean("readOnly")
        || !enabled(row.node)
        || data.optString("value").equals(value)) return;
    put(data, "value", value);
    row.value = value;
    event(row.node, "setting", object("id", row.id, "value", value));
  }

  final class SettingRow {
    final int node;
    final String id;
    final LinearLayout line;
    final TextView title;
    View control;
    String value, choices;
    String[] options = new String[0];
    boolean user;

    SettingRow(int node, String id, boolean choice) {
      this.node = node;
      this.id = id;
      line = new LinearLayout(HostActivity.this);
      line.setOrientation(LinearLayout.HORIZONTAL);
      title = label("");
      line.addView(title);
      if (choice) {
        Spinner spinner = new Spinner(HostActivity.this);
        control = spinner;
        spinner.setOnTouchListener(
            (v, event) -> {
              if (event.getActionMasked() == android.view.MotionEvent.ACTION_DOWN) user = true;
              return false;
            });
        spinner.setOnKeyListener(
            (v, key, event) -> {
              if (event.getAction() == KeyEvent.ACTION_DOWN) user = true;
              return false;
            });
        spinner.setOnItemSelectedListener(
            new AdapterView.OnItemSelectedListener() {
              public void onNothingSelected(AdapterView<?> parent) {}

              public void onItemSelected(AdapterView<?> parent, View view, int index, long row) {
                if (!applying && user && index >= 0 && index < options.length) {
                  user = false;
                  commitSetting(SettingRow.this, options[index]);
                }
              }
            });
      } else {
        EditText input = new EditText(HostActivity.this);
        control = input;
        input.setSingleLine();
        input.setImeOptions(EditorInfo.IME_ACTION_DONE);
        input.setOnEditorActionListener(
            (v, action, key) -> {
              if (key == null || key.getAction() == KeyEvent.ACTION_DOWN)
                commitSetting(this, input.getText().toString());
              return true;
            });
        input.setOnFocusChangeListener(
            (v, focus) -> {
              if (!focus) commitSetting(this, input.getText().toString());
            });
      }
      line.addView(control, new LinearLayout.LayoutParams(0, -2, 1));
    }
  }

  void updateForm(NodeTree.Node node, LinearLayout form) {
    JSONArray rows = node.props.optJSONArray("rows");
    List<SettingRow> desired = new ArrayList<>();
    Set<String> keys = new HashSet<>();
    int width = 0;
    if (rows != null)
      for (int i = 0; i < rows.length(); ++i) {
        JSONObject data = rows.optJSONObject(i);
        if (data == null) continue;
        String id = data.optString("id"), key = node.id + ":" + id;
        keys.add(key);
        JSONArray choices = data.optJSONArray("choices");
        SettingRow row = settingRows.get(key);
        if (row == null || (row.control instanceof Spinner) != (choices != null)) {
          row = new SettingRow(node.id, id, choices != null);
          settingRows.put(key, row);
        }
        row.title.setText(data.optString("label"));
        width =
            Math.max(
                width, (int) Math.ceil(row.title.getPaint().measureText(data.optString("label"))));
        String value = data.optString("value");
        if (choices != null) {
          String encoded = choices.toString();
          if (!encoded.equals(row.choices)) {
            row.options = strings(choices);
            row.user = false;
            ((Spinner) row.control)
                .setAdapter(
                    new ArrayAdapter<>(
                        this, android.R.layout.simple_spinner_dropdown_item, row.options));
            row.choices = encoded;
          }
          if (!Objects.equals(value, row.value)) {
            row.user = false;
            ((Spinner) row.control)
                .setSelection(Math.max(0, Arrays.asList(row.options).indexOf(value)), false);
          }
        } else if (!Objects.equals(value, row.value)) ((EditText) row.control).setText(value);
        row.value = value;
        row.control.setTag(0x7f000001, data.optBoolean("readOnly"));
        row.control.setEnabled(enabled(node.id) && !data.optBoolean("readOnly"));
        row.control.setContentDescription(data.optString("label"));
        desired.add(row);
      }
    width = Math.min(width, (int) (getResources().getDisplayMetrics().widthPixels * 0.45));
    Set<View> keep = new HashSet<>();
    for (SettingRow row : desired) keep.add(row.line);
    for (int i = form.getChildCount() - 1; i >= 0; --i)
      if (!keep.contains(form.getChildAt(i))) form.removeViewAt(i);
    for (int i = 0; i < desired.size(); ++i) {
      SettingRow row = desired.get(i);
      row.title.setLayoutParams(new LinearLayout.LayoutParams(width + (int) cw, -2));
      attach(form, row.line, i, new LinearLayout.LayoutParams(-1, -2));
    }
    Iterator<Map.Entry<String, SettingRow>> iterator = settingRows.entrySet().iterator();
    while (iterator.hasNext()) {
      Map.Entry<String, SettingRow> item = iterator.next();
      if (!session.tree.nodes.containsKey(item.getValue().node)
          || (item.getValue().node == node.id && !keys.contains(item.getKey()))) iterator.remove();
    }
  }

  void structure(NodeTree.Node node) {
    View view = views.get(node.id);
    if (!(view instanceof ViewGroup)
        || !(node.kind.equals("stack")
            || node.kind.equals("tabs")
            || node.kind.equals("disclosure")
            || node.kind.equals("canvas"))) return;
    ViewGroup group = (ViewGroup) view;
    int start = node.kind.equals("tabs") || node.kind.equals("disclosure") ? 1 : 0;
    for (int i = 0; i < node.children.size(); ++i) {
      NodeTree.Node child = session.tree.nodes.get(node.children.get(i));
      View control = views.get(child.id);
      if (group instanceof Surface) {
        JSONArray place = child.props.optJSONArray("place"),
            clip = child.props.optJSONArray("clip");
        if (place == null || place.length() < 4) continue;
        FrameLayout.LayoutParams params =
            new FrameLayout.LayoutParams(
                Math.max(1, (int) (place.optDouble(2) * cw)),
                Math.max(1, (int) (place.optDouble(3) * ch)));
        params.leftMargin = (int) (place.optDouble(1) * cw);
        params.topMargin = (int) (place.optDouble(0) * ch);
        attach(group, control, i, params);
        if (clip != null && clip.length() >= 4)
          control.setClipBounds(
              new Rect(
                  (int) ((clip.optDouble(1) - place.optDouble(1)) * cw),
                  (int) ((clip.optDouble(0) - place.optDouble(0)) * ch),
                  (int) ((clip.optDouble(1) + clip.optDouble(2) - place.optDouble(1)) * cw),
                  (int) ((clip.optDouble(0) + clip.optDouble(3) - place.optDouble(0)) * ch)));
        else control.setClipBounds(null);
      } else {
        LinearLayout stack = (LinearLayout) group;
        boolean horizontal = stack.getOrientation() == LinearLayout.HORIZONTAL;
        float grow = (float) child.props.optDouble("grow", 0);
        LinearLayout.LayoutParams params =
            new LinearLayout.LayoutParams(
                horizontal ? (grow > 0 ? 0 : -2) : -1, horizontal ? -1 : (grow > 0 ? 0 : -2), grow);
        if (i > 0) {
          if (horizontal) params.leftMargin = (int) (node.props.optDouble("gap", 0) * cw);
          else params.topMargin = (int) (node.props.optDouble("gap", 0) * ch);
        }
        attach(group, control, start + i, params);
        boolean visible =
            !child.props.optBoolean("hidden")
                && (!node.kind.equals("tabs") || i == node.props.optInt("active"))
                && (!node.kind.equals("disclosure") || node.props.optBoolean("expanded"));
        control.setVisibility(visible ? View.VISIBLE : View.GONE);
      }
    }
  }

  void rects() {
    if (session == null || session.tree.root == null) return;
    JSONObject rectangles = new JSONObject();
    int[] origin = new int[2];
    scroll.getLocationOnScreen(origin);
    for (Map.Entry<Integer, View> item : views.entrySet()) {
      View view = item.getValue();
      if (view.getVisibility() != View.VISIBLE) continue;
      int[] position = new int[2];
      view.getLocationOnScreen(position);
      put(
          rectangles,
          String.valueOf(item.getKey()),
          new JSONArray()
              .put((int) Math.floor((position[1] - origin[1]) / ch))
              .put((int) Math.floor((position[0] - origin[0]) / cw))
              .put((int) Math.ceil(view.getWidth() / cw))
              .put((int) Math.ceil(view.getHeight() / ch)));
    }
    send(object("type", "rects", "rects", rectangles));
  }

  static String[] strings(JSONArray values) {
    if (values == null) return new String[0];
    String[] result = new String[values.length()];
    for (int i = 0; i < result.length; ++i) result[i] = values.optString(i);
    return result;
  }

  static boolean contains(JSONArray values, int value) {
    if (values != null)
      for (int i = 0; i < values.length(); ++i) if (values.optInt(i, -1) == value) return true;
    return false;
  }

  static JSONArray toggle(JSONArray values, int value, boolean selected) {
    JSONArray result = new JSONArray();
    if (values != null)
      for (int i = 0; i < values.length(); ++i)
        if (values.optInt(i, -1) != value) result.put(values.optInt(i));
    if (selected) result.put(value);
    return result;
  }

  static int color(String value, int fallback) {
    try {
      return Color.parseColor(value);
    } catch (Exception ignored) {
      return fallback;
    }
  }

  CharSequence runs(JSONArray runs, String fallback) {
    if (runs == null) return fallback;
    SpannableStringBuilder text = new SpannableStringBuilder();
    for (int i = 0; i < runs.length(); ++i) {
      JSONObject run = runs.optJSONObject(i);
      if (run == null) continue;
      int start = text.length();
      text.append(run.optString("t"));
      int end = text.length();
      if (run.has("fg"))
        text.setSpan(
            new android.text.style.ForegroundColorSpan(color(run.optString("fg"), Color.BLACK)),
            start,
            end,
            33);
      if (run.has("bg"))
        text.setSpan(
            new android.text.style.BackgroundColorSpan(
                color(run.optString("bg"), Color.TRANSPARENT)),
            start,
            end,
            33);
      if (run.optBoolean("b") || run.optBoolean("i"))
        text.setSpan(
            new android.text.style.StyleSpan(
                (run.optBoolean("b") ? Typeface.BOLD : 0)
                    | (run.optBoolean("i") ? Typeface.ITALIC : 0)),
            start,
            end,
            33);
      if (run.optBoolean("u")) text.setSpan(new android.text.style.UnderlineSpan(), start, end, 33);
    }
    return text;
  }

  static String limit(String before, String after, int max) {
    int[] old = before.codePoints().toArray(), next = after.codePoints().toArray();
    if (max < 0 || next.length <= Math.max(max, old.length)) return after;
    int prefix = 0, suffix = 0;
    while (prefix < old.length && prefix < next.length && old[prefix] == next[prefix]) ++prefix;
    while (suffix < old.length - prefix
        && suffix < next.length - prefix
        && old[old.length - 1 - suffix] == next[next.length - 1 - suffix]) ++suffix;
    int allowed = Math.max(0, Math.max(max, old.length) - prefix - suffix);
    return new String(next, 0, prefix + allowed) + new String(next, next.length - suffix, suffix);
  }

  final class Input extends EditText {
    final int node;
    String committed = "";
    int max = -1;
    boolean programmatic;

    Input(int node, String kind) {
      super(HostActivity.this);
      this.node = node;
      boolean multiline = kind.equals("textarea");
      setSingleLine(!multiline);
      setMinLines(multiline ? 3 : 1);
      setGravity(Gravity.TOP | Gravity.START);
      setTextSize(14);
      if (kind.equals("password")) {
        setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        setTransformationMethod(PasswordTransformationMethod.getInstance());
      }
      if (kind.equals("number"))
        setRawInputType(
            InputType.TYPE_CLASS_NUMBER
                | InputType.TYPE_NUMBER_FLAG_DECIMAL
                | InputType.TYPE_NUMBER_FLAG_SIGNED);
      setImeOptions(multiline ? EditorInfo.IME_ACTION_NONE : EditorInfo.IME_ACTION_DONE);
      addTextChangedListener(
          new TextWatcher() {
            public void beforeTextChanged(CharSequence s, int start, int count, int after) {}

            public void onTextChanged(CharSequence s, int start, int before, int count) {}

            public void afterTextChanged(Editable text) {
              if (!programmatic) post(() -> commit());
            }
          });
      setOnFocusChangeListener(
          (v, focus) -> {
            if (focus) event(node, "focus", null);
            else commit();
          });
      setOnEditorActionListener(
          (v, action, key) -> {
            if (key != null && key.getAction() != KeyEvent.ACTION_DOWN) return true;
            if (multiline && (key == null || (!key.isCtrlPressed() && !key.isMetaPressed())))
              return false;
            commit();
            event(node, "submit", getText().toString());
            return true;
          });
      setOnKeyListener(
          (v, key, event) -> {
            if (event.getAction() == KeyEvent.ACTION_DOWN && key == KeyEvent.KEYCODE_ESCAPE) {
              HostActivity.this.event(node, "cancel", null);
              return true;
            }
            if (event.getAction() == KeyEvent.ACTION_DOWN
                && key == KeyEvent.KEYCODE_ENTER
                && (event.isCtrlPressed() || event.isMetaPressed())) {
              commit();
              HostActivity.this.event(node, "submit", getText().toString());
              return true;
            }
            return false;
          });
    }

    void update(JSONObject props) {
      max = props.optInt("maxLength", -1);
      setHint(props.optString("prompt"));
      String value = props.optString("value");
      if (!getText().toString().equals(value)) {
        programmatic = true;
        committed = value;
        setText(value);
        setSelection(value.length());
        programmatic = false;
      } else committed = value;
      setMinLines(props.optInt("minLines", 1));
      setMaxLines(props.optInt("maxLines", Integer.MAX_VALUE));
    }

    void commit() {
      if (programmatic || BaseInputConnection.getComposingSpanStart(getText()) >= 0) return;
      String value = limit(committed, getText().toString(), max);
      if (!value.equals(getText().toString())) {
        programmatic = true;
        setText(value);
        setSelection(value.length());
        programmatic = false;
      }
      if (!value.equals(committed)) {
        committed = value;
        NodeTree.Node model = session.tree.nodes.get(node);
        if (model != null) put(model.props, "value", value);
        event(node, "change", value);
      }
    }
  }

  final class NumberField extends LinearLayout {
    final Input input;

    NumberField(int node) {
      super(HostActivity.this);
      setOrientation(HORIZONTAL);
      input = new Input(node, "number");
      addView(input, new LinearLayout.LayoutParams(0, -1, 1));
      for (int direction : new int[] {-1, 1}) {
        Button button = new Button(HostActivity.this);
        button.setText(direction < 0 ? "−" : "+");
        button.setMinWidth(0);
        button.setMinimumWidth(0);
        button.setPadding(0, 0, 0, 0);
        button.setOnClickListener(
            v -> {
              NodeTree.Node model = session.tree.nodes.get(node);
              if (model == null) return;
              JSONObject p = model.props;
              String value =
                  Stepping.step(
                      input.getText().toString(),
                      direction,
                      p.isNull("min") ? null : p.optDouble("min"),
                      p.isNull("max") ? null : p.optDouble("max"),
                      p.optDouble("step", 1));
              input.setText(value);
              input.commit();
            });
        addView(
            button,
            new LinearLayout.LayoutParams(
                (int) (40 * getResources().getDisplayMetrics().density), -1));
      }
    }

    @Override
    public boolean requestFocus(int direction, Rect rectangle) {
      return input.requestFocus(direction, rectangle);
    }
  }

  final class Surface extends FrameLayout {
    final int node;
    JSONObject props = new JSONObject();
    final Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG);
    float lastX, lastY, startX, startY;
    boolean dragging, wheeling;

    Surface(int id) {
      super(HostActivity.this);
      node = id;
      setWillNotDraw(false);
      setFocusableInTouchMode(true);
      setClipChildren(true);
      setOnFocusChangeListener(
          (v, focus) -> {
            if (focus) event(node, "focus", null);
          });
    }

    @Override
    protected void onMeasure(int width, int height) {
      int desiredWidth = Math.max(1, (int) (props.optInt("columns", 40) * cw));
      JSONArray lines = props.optJSONArray("lines");
      int desiredHeight = Math.max(1, (int) ((lines == null ? 1 : lines.length()) * ch));
      setMeasuredDimension(resolveSize(desiredWidth, width), resolveSize(desiredHeight, height));
      for (int i = 0; i < getChildCount(); ++i) {
        View child = getChildAt(i);
        ViewGroup.LayoutParams p = child.getLayoutParams();
        child.measure(
            MeasureSpec.makeMeasureSpec(Math.max(0, p.width), MeasureSpec.EXACTLY),
            MeasureSpec.makeMeasureSpec(Math.max(0, p.height), MeasureSpec.EXACTLY));
      }
    }

    @Override
    protected void onDraw(Canvas canvas) {
      super.onDraw(canvas);
      paint.setTypeface(Typeface.MONOSPACE);
      paint.setTextSize(canvasSize);
      JSONArray lines = props.optJSONArray("lines");
      if (lines == null) return;
      for (int row = 0; row < lines.length(); ++row) {
        JSONArray line = lines.optJSONArray(row);
        float x = 0;
        if (line == null) continue;
        for (int i = 0; i < line.length(); ++i) {
          JSONObject run = line.optJSONObject(i);
          if (run == null) continue;
          String text = run.optString("t");
          float width = paint.measureText(text);
          if (run.has("bg")) {
            paint.setColor(color(run.optString("bg"), Color.TRANSPARENT));
            canvas.drawRect(x, row * ch, x + width, (row + 1) * ch, paint);
          }
          paint.setColor(color(run.optString("fg"), dark() ? Color.WHITE : Color.BLACK));
          paint.setTypeface(
              Typeface.create(
                  Typeface.MONOSPACE,
                  (run.optBoolean("b") ? Typeface.BOLD : 0)
                      | (run.optBoolean("i") ? Typeface.ITALIC : 0)));
          paint.setUnderlineText(run.optBoolean("u"));
          canvas.drawText(text, x, row * ch - paint.ascent(), paint);
          x += width;
        }
      }
      paint.setColor(dark() ? Color.BLACK : Color.WHITE);
      for (int i = 0; i < getChildCount(); ++i) {
        View child = getChildAt(i);
        Rect visible = new Rect(0, 0, child.getWidth(), child.getHeight());
        Rect clip = child.getClipBounds();
        if (clip != null && !visible.intersect(clip)) continue;
        visible.offset(child.getLeft(), child.getTop());
        canvas.drawRect(visible, paint);
      }
    }

    @Override
    public boolean onTouchEvent(android.view.MotionEvent motion) {
      if (!isEnabled()) return false;
      int action = motion.getActionMasked();
      String name;
      if (action == MotionEvent.ACTION_DOWN) {
        if (isFocusable()) requestFocus();
        lastX = startX = motion.getX();
        lastY = startY = motion.getY();
        dragging = true;
        wheeling = false;
        getParent().requestDisallowInterceptTouchEvent(true);
        name = "press";
      } else if (action == MotionEvent.ACTION_MOVE) {
        float dx = motion.getX() - startX, dy = motion.getY() - startY;
        if (!wheeling && Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > ch / 2) {
          wheeling = true;
          send(
              object(
                  "type",
                  "pointer",
                  "node",
                  node,
                  "action",
                  "leave",
                  "row",
                  0,
                  "column",
                  0,
                  "button",
                  JSONObject.NULL,
                  "modifiers",
                  new JSONArray()));
        }
        if (wheeling) {
          int rows = (int) ((lastY - motion.getY()) / ch),
              columns = motion.getPointerCount() > 1 ? (int) ((lastX - motion.getX()) / cw) : 0;
          if (rows != 0 || columns != 0) {
            lastY -= rows * ch;
            lastX -= columns * cw;
            send(
                object(
                    "type",
                    "pointer",
                    "node",
                    node,
                    "action",
                    "wheel",
                    "row",
                    (int) Math.floor(motion.getY() / ch),
                    "column",
                    (int) Math.floor(motion.getX() / cw),
                    "button",
                    JSONObject.NULL,
                    "modifiers",
                    new JSONArray(),
                    "rows",
                    rows,
                    "columns",
                    columns));
          }
          return true;
        }
        name = "move";
      } else if (action == MotionEvent.ACTION_UP || action == MotionEvent.ACTION_CANCEL) {
        if (wheeling) {
          dragging = false;
          getParent().requestDisallowInterceptTouchEvent(false);
          return true;
        }
        name = action == MotionEvent.ACTION_CANCEL ? "leave" : "release";
        dragging = false;
        getParent().requestDisallowInterceptTouchEvent(false);
        performClick();
        if (!props.isNull("cursor"))
          ((InputMethodManager) getSystemService(INPUT_METHOD_SERVICE))
              .showSoftInput(this, InputMethodManager.SHOW_IMPLICIT);
      } else return false;
      send(
          object(
              "type",
              "pointer",
              "node",
              node,
              "action",
              name,
              "row",
              (int) Math.floor(motion.getY() / ch),
              "column",
              (int) Math.floor(motion.getX() / cw),
              "button",
              "left",
              "modifiers",
              new JSONArray()));
      if (action == MotionEvent.ACTION_UP) {
        send(
            object(
                "type",
                "pointer",
                "node",
                node,
                "action",
                "leave",
                "row",
                (int) Math.floor(motion.getY() / ch),
                "column",
                (int) Math.floor(motion.getX() / cw),
                "button",
                JSONObject.NULL,
                "modifiers",
                new JSONArray()));
      }
      return true;
    }

    @Override
    public boolean performClick() {
      super.performClick();
      return true;
    }

    boolean key(KeyEvent event) {
      if (event.getAction() != KeyEvent.ACTION_DOWN) return false;
      String name;
      switch (event.getKeyCode()) {
        case KeyEvent.KEYCODE_ENTER:
          name = "enter";
          break;
        case KeyEvent.KEYCODE_TAB:
          name = "tab";
          break;
        case KeyEvent.KEYCODE_ESCAPE:
          name = "escape";
          break;
        case KeyEvent.KEYCODE_DEL:
          name = "backspace";
          break;
        case KeyEvent.KEYCODE_FORWARD_DEL:
          name = "delete";
          break;
        case KeyEvent.KEYCODE_DPAD_LEFT:
          name = "left";
          break;
        case KeyEvent.KEYCODE_DPAD_RIGHT:
          name = "right";
          break;
        case KeyEvent.KEYCODE_DPAD_UP:
          name = "up";
          break;
        case KeyEvent.KEYCODE_DPAD_DOWN:
          name = "down";
          break;
        case KeyEvent.KEYCODE_MOVE_HOME:
          name = "home";
          break;
        case KeyEvent.KEYCODE_MOVE_END:
          name = "end";
          break;
        case KeyEvent.KEYCODE_PAGE_UP:
          name = "page_up";
          break;
        case KeyEvent.KEYCODE_PAGE_DOWN:
          name = "page_down";
          break;
        default:
          if (event.getKeyCode() >= KeyEvent.KEYCODE_F1
              && event.getKeyCode() <= KeyEvent.KEYCODE_F12)
            name = "f" + (event.getKeyCode() - KeyEvent.KEYCODE_F1 + 1);
          else if (event.isCtrlPressed() || event.isAltPressed() || event.isMetaPressed())
            name =
                KeyEvent.keyCodeToString(event.getKeyCode())
                    .replace("KEYCODE_", "")
                    .toLowerCase(java.util.Locale.ROOT);
          else return false;
      }
      String modifiers =
          (event.isCtrlPressed() ? "ctrl+" : "")
              + (event.isShiftPressed() ? "shift+" : "")
              + (event.isAltPressed() ? "alt+" : "")
              + (event.isMetaPressed() ? "super+" : "");
      send(object("type", "key", "key", modifiers + name));
      return true;
    }

    @Override
    public boolean onKeyDown(int key, KeyEvent event) {
      return key(event) || super.onKeyDown(key, event);
    }

    @Override
    public boolean onCheckIsTextEditor() {
      return true;
    }

    @Override
    public InputConnection onCreateInputConnection(EditorInfo info) {
      info.inputType = InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE;
      info.imeOptions = EditorInfo.IME_ACTION_DONE;
      return new BaseInputConnection(this, true) {
        final Editable composing = new SpannableStringBuilder();

        @Override
        public Editable getEditable() {
          return composing;
        }

        void emit() {
          if (BaseInputConnection.getComposingSpanStart(composing) >= 0) return;
          String value = composing.toString();
          composing.clear();
          if (value.length() > 1 && (value.indexOf(10) >= 0 || value.indexOf(9) >= 0)) {
            send(object("type", "paste", "text", value));
            return;
          }
          value
              .codePoints()
              .filter(cp -> cp >= 32 && cp != 127)
              .forEach(
                  cp -> {
                    String character = new String(Character.toChars(cp));
                    send(
                        object(
                            "type",
                            "key",
                            "key",
                            cp == 32 ? "space" : character,
                            "text",
                            character));
                  });
        }

        @Override
        public boolean sendKeyEvent(KeyEvent event) {
          if (BaseInputConnection.getComposingSpanStart(composing) < 0 && key(event)) return true;
          return super.sendKeyEvent(event);
        }

        @Override
        public boolean commitText(CharSequence text, int cursor) {
          super.commitText(text, cursor);
          emit();
          return true;
        }

        @Override
        public boolean finishComposingText() {
          super.finishComposingText();
          emit();
          return true;
        }

        @Override
        public boolean deleteSurroundingText(int before, int after) {
          for (int i = 0; i < before; ++i) send(object("type", "key", "key", "backspace"));
          for (int i = 0; i < after; ++i) send(object("type", "key", "key", "delete"));
          return true;
        }

        @Override
        public boolean performEditorAction(int action) {
          send(object("type", "key", "key", "enter"));
          return true;
        }
      };
    }
  }
}
