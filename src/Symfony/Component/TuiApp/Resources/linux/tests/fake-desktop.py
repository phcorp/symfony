#!/usr/bin/env python3
"""What a desktop provides on the session bus, for features.sh, in a session with nothing else.

    fake-desktop.py <log> [--portal light|dark]

- org.freedesktop.Notifications: each Notify call is logged as a JSON line.
- With --portal, org.freedesktop.portal.Desktop with the Settings interface: color-scheme
  starts as given, and org.tuiapp.test.FakeDesktop.SetColorScheme(u) changes it and emits
  SettingChanged, as a desktop does when the user switches between light and dark.

Writes {"ready": true} to the log once its names are owned.
"""
import json
import sys

from gi.repository import Gio, GLib

NOTIFICATIONS = """
<node>
  <interface name="org.freedesktop.Notifications">
    <method name="Notify">
      <arg type="s" direction="in"/><arg type="u" direction="in"/><arg type="s" direction="in"/>
      <arg type="s" direction="in"/><arg type="s" direction="in"/><arg type="as" direction="in"/>
      <arg type="a{sv}" direction="in"/><arg type="i" direction="in"/><arg type="u" direction="out"/>
    </method>
    <method name="CloseNotification"><arg type="u" direction="in"/></method>
    <method name="GetCapabilities"><arg type="as" direction="out"/></method>
    <method name="GetServerInformation">
      <arg type="s" direction="out"/><arg type="s" direction="out"/><arg type="s" direction="out"/><arg type="s" direction="out"/>
    </method>
    <signal name="ActionInvoked"><arg type="u"/><arg type="s"/></signal>
    <signal name="NotificationClosed"><arg type="u"/><arg type="u"/></signal>
  </interface>
</node>
"""

PORTAL = """
<node>
  <interface name="org.freedesktop.portal.Settings">
    <method name="ReadOne"><arg type="s" direction="in"/><arg type="s" direction="in"/><arg type="v" direction="out"/></method>
    <method name="Read"><arg type="s" direction="in"/><arg type="s" direction="in"/><arg type="v" direction="out"/></method>
    <signal name="SettingChanged"><arg type="s"/><arg type="s"/><arg type="v"/></signal>
    <property name="version" type="u" access="read"/>
  </interface>
  <interface name="org.tuiapp.test.FakeDesktop">
    <method name="SetColorScheme"><arg type="u" direction="in"/></method>
  </interface>
</node>
"""

log_path = sys.argv[1]
portal = sys.argv[3] if len(sys.argv) > 3 and sys.argv[2] == "--portal" else None
scheme = {"dark": 1, "light": 2}.get(portal, 0)
next_id = 0
pending = 2 if portal else 1


def log(entry):
    with open(log_path, "a") as f:
        f.write(json.dumps(entry) + "\n")


def notifications_call(connection, sender, path, interface, method, parameters, invocation):
    global next_id
    if method == "Notify":
        app, replaces, icon, summary, body, actions, hints, timeout = parameters.unpack()
        next_id += 1
        log({"notify": {"app": app, "summary": summary, "body": body, "actions": actions, "desktop-entry": hints.get("desktop-entry")}})
        invocation.return_value(GLib.Variant("(u)", (next_id,)))
    elif method == "GetCapabilities":
        invocation.return_value(GLib.Variant("(as)", (["actions", "body"],)))
    elif method == "GetServerInformation":
        invocation.return_value(GLib.Variant("(ssss)", ("fake-desktop", "tuiapp", "1", "1.2")))
    else:
        invocation.return_value(None)


def portal_call(connection, sender, path, interface, method, parameters, invocation):
    global scheme
    if method in ("ReadOne", "Read"):
        namespace, key = parameters.unpack()
        if (namespace, key) != ("org.freedesktop.appearance", "color-scheme"):
            invocation.return_dbus_error("org.freedesktop.portal.Error.NotFound", "Requested setting not found")
            return
        value = GLib.Variant("u", scheme)
        # Read, the older method, wraps the value once more.
        invocation.return_value(GLib.Variant("(v)", (value if method == "ReadOne" else GLib.Variant("v", value),)))
    elif method == "SetColorScheme":
        (scheme,) = parameters.unpack()
        log({"color-scheme": scheme})
        connection.emit_signal(None, "/org/freedesktop/portal/desktop", "org.freedesktop.portal.Settings", "SettingChanged",
                               GLib.Variant("(ssv)", ("org.freedesktop.appearance", "color-scheme", GLib.Variant("u", scheme))))
        invocation.return_value(None)


def portal_property(connection, sender, path, interface, name):
    return GLib.Variant("u", 2)


def acquired(connection, name):
    global pending
    pending -= 1
    if pending == 0:
        log({"ready": True})


def lost(connection, name):
    log({"lost": name})
    loop.quit()


bus = Gio.bus_get_sync(Gio.BusType.SESSION, None)
info = Gio.DBusNodeInfo.new_for_xml(NOTIFICATIONS)
bus.register_object("/org/freedesktop/Notifications", info.interfaces[0], notifications_call, None, None)
Gio.bus_own_name_on_connection(bus, "org.freedesktop.Notifications", Gio.BusNameOwnerFlags.NONE, acquired, lost)
if portal:
    info = Gio.DBusNodeInfo.new_for_xml(PORTAL)
    for interface in info.interfaces:
        bus.register_object("/org/freedesktop/portal/desktop", interface, portal_call, portal_property, None)
    Gio.bus_own_name_on_connection(bus, "org.freedesktop.portal.Desktop", Gio.BusNameOwnerFlags.NONE, acquired, lost)

loop = GLib.MainLoop()
loop.run()
