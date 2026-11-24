#!/usr/bin/env python3
import argparse
import base64
import hashlib
import json
from pathlib import Path
import shutil

parser = argparse.ArgumentParser()
parser.add_argument('manifest', type=Path)
parser.add_argument('payload', type=Path)
parser.add_argument('output', type=Path)
args = parser.parse_args()
manifest = json.loads(args.manifest.read_text())
launch = manifest['launch']
if launch['mode'] != 'embedded':
    parser.error('Mobile apps require the embedded runtime.')
arguments = launch['args']
ini = {}
entry = None
app_arguments = []
i = 0
while i < len(arguments):
    value = arguments[i]
    if entry is not None:
        app_arguments.append(value)
    elif value == '-n':
        pass
    elif value == '-d':
        i += 1
        name, separator, setting = arguments[i].partition('=')
        if not separator:
            parser.error('Invalid PHP INI argument.')
        ini[name] = setting
    elif value.startswith('./app/'):
        entry = value[6:]
    else:
        parser.error('Invalid embedded launch arguments.')
    i += 1
if not entry or Path(entry).is_absolute() or '..' in Path(entry).parts:
    parser.error('The application entry must be inside app/.')
output = args.output
output.mkdir(parents=True, exist_ok=True)
shutil.copytree(args.payload / 'app', output / 'payload' / 'app', dirs_exist_ok=True)
config = {'entry': entry, 'args': app_arguments, 'env': launch.get('env', {}).get('set', {})}
encoded = base64.b64encode(json.dumps(config, ensure_ascii=False).encode()).decode()
bootstrap = '''<?php
(static function (array $config): void {
chdir(__DIR__.'/app');
foreach (['STDIN' => ['php://stdin', 'r'], 'STDOUT' => ['php://stdout', 'w'], 'STDERR' => ['php://stderr', 'w']] as $name => [$path, $mode]) {
    if (!defined($name)) { define($name, fopen($path, $mode)); }
}
foreach ($config['env'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$GLOBALS['argv'] = [__DIR__.'/app/'.$config['entry'], ...$config['args']];
$GLOBALS['argc'] = count($GLOBALS['argv']);
$_SERVER['argv'] = $GLOBALS['argv'];
$_SERVER['argc'] = $GLOBALS['argc'];
})(json_decode(base64_decode('%s'), true, flags: JSON_THROW_ON_ERROR));
require $argv[0];
''' % encoded
(output / 'payload' / 'bootstrap.php').write_text(bootstrap)
(output / 'mobile.entry').write_text('bootstrap.php\n')
(output / 'app.manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
digest = hashlib.sha256()
for file in sorted((output / 'payload').rglob('*')):
    if file.is_file():
        digest.update(file.relative_to(output / 'payload').as_posix().encode() + b'\0')
        digest.update(file.read_bytes())
(output / 'payload.sha256').write_text(digest.hexdigest() + '\n')
