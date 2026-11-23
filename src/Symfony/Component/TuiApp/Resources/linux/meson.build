project('tui-app-host', 'c', version: '0.1.0', default_options: ['c_std=c11', 'warning_level=2'], meson_version: '>=1.1')

glib = dependency('glib-2.0', version: '>=2.74')
json = dependency('json-glib-1.0', version: '>=1.6')
math = meson.get_compiler('c').find_library('m', required: false)
# GNU extensions for realpath and friends while keeping C11.
add_project_arguments('-D_GNU_SOURCE', language: 'c')

core = static_library('tui-app-core', 'src/core.c', dependencies: [glib, json, math])

# Reads a manifest for package.sh: the desktop entry, the mime XML and single fields. No GTK.
executable('tui-app-manifest', 'src/manifest_tool.c', link_with: core, dependencies: [glib, json, math])

if get_option('app')
  executable('tui-app-host', 'src/main.c', 'src/stack_layout.c',
    link_with: core,
    dependencies: [
      glib,
      dependency('gio-2.0'),
      dependency('gtk4', version: '>=4.10'),
      json,
      math,
    ],
    c_args: ['-DG_LOG_DOMAIN="tui-app"'],
    install: true,
  )
endif

test('core', executable('test-core', 'tests/test_core.c',
  link_with: core,
  dependencies: [glib, json, math],
  c_args: ['-DCONTRACT_DIR="' + meson.project_source_root() / '..' / 'contract' + '"'],
))
