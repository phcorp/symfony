package org.symfony.tui;

import java.util.*;
import org.json.*;

final class NodeTree {
  static final class Node {
    final int id;
    final String kind;
    final JSONObject props = new JSONObject();
    Integer parent;
    final List<Integer> children = new ArrayList<>();

    Node(int id, String kind, Integer parent) {
      this.id = id;
      this.kind = kind;
      this.parent = parent;
    }
  }

  final Map<Integer, Node> nodes = new LinkedHashMap<>();
  Integer root;
  final Set<Integer> recreated = new LinkedHashSet<>();

  Set<Integer> apply(JSONArray operations) throws JSONException {
    recreated.clear();
    Set<Integer> changed = new LinkedHashSet<>();
    for (int i = 0; i < operations.length(); ++i) {
      JSONObject op = operations.getJSONObject(i);
      int id = op.getInt("id");
      String action = op.getString("op");
      Node node = nodes.get(id);
      if (action.equals("create")) {
        Integer parent = op.isNull("parent") ? null : op.getInt("parent");
        if (parent != null && (!nodes.containsKey(parent) || ancestor(id, parent))) continue;
        remove(id, changed);
        if (parent == null && root != null) remove(root, changed);
        node = new Node(id, op.getString("kind"), parent);
        nodes.put(id, node);
        recreated.add(id);
        update(node.props, properties(op));
        if (parent == null) root = id;
        else insert(parent, id, op.getInt("index"), changed);
        changed.add(id);
      } else if (action.equals("update") && node != null) {
        update(node.props, properties(op));
        changed.add(id);
      } else if (action.equals("remove")) remove(id, changed);
      else if (action.equals("move") && node != null) {
        int parent = op.getInt("parent");
        if (!nodes.containsKey(parent) || ancestor(id, parent)) continue;
        if (node.parent != null) {
          nodes.get(node.parent).children.remove((Integer) id);
          changed.add(node.parent);
        } else root = null;
        node.parent = parent;
        insert(parent, id, op.getInt("index"), changed);
        changed.add(id);
      }
    }
    return changed;
  }

  static JSONObject properties(JSONObject operation) throws JSONException {
    Object value = operation.get("props");
    if (value instanceof JSONObject) return (JSONObject) value;
    if (value instanceof JSONArray && ((JSONArray) value).length() == 0) return new JSONObject();
    throw new JSONException("Node props must be an object");
  }

  static void update(JSONObject props, JSONObject delta) throws JSONException {
    Iterator<String> keys = delta.keys();
    while (keys.hasNext()) {
      String key = keys.next();
      if (delta.isNull(key)) props.remove(key);
      else props.put(key, delta.get(key));
    }
  }

  boolean ancestor(int id, Integer child) {
    while (child != null) {
      if (child == id) return true;
      Node node = nodes.get(child);
      child = node == null ? null : node.parent;
    }
    return false;
  }

  void remove(int id, Set<Integer> changed) {
    Node node = nodes.get(id);
    if (node == null) return;
    for (int child : new ArrayList<>(node.children)) remove(child, changed);
    if (node.parent != null && nodes.containsKey(node.parent)) {
      nodes.get(node.parent).children.remove((Integer) id);
      changed.add(node.parent);
    }
    nodes.remove(id);
    if (Objects.equals(root, id)) root = null;
    changed.add(id);
  }

  void insert(int parent, int id, int index, Set<Integer> changed) {
    List<Integer> children = nodes.get(parent).children;
    children.add(Math.max(0, Math.min(index, children.size())), id);
    changed.add(parent);
  }

  Object snapshot() throws JSONException {
    return root == null ? JSONObject.NULL : snapshot(root);
  }

  JSONObject snapshot(int id) throws JSONException {
    Node node = nodes.get(id);
    JSONArray children = new JSONArray();
    for (int child : node.children) children.put(snapshot(child));
    return new JSONObject()
        .put("id", id)
        .put("kind", node.kind)
        .put("props", node.props)
        .put("children", children);
  }
}
