#!/usr/bin/env python3
import json
import os
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tui-mobile-package-') as temporary:
    work = Path(temporary)
    source = work / 'source'
    (source / 'app/bin').mkdir(parents=True)
    (source / 'app/bin/main.php').write_text('''<?php echo json_encode(['args' => $argv, 'argc' => $argc, 'environment' => getenv('MOBILE_TEST'), 'pollution' => isset($config), 'cwd' => basename(getcwd()), 'stdio' => is_resource(STDOUT)], JSON_THROW_ON_ERROR);''')
    manifest = {'manifest': 1, 'app': {'id': 'com.acme.mobile', 'name': 'Mobile'}, 'launch': {'mode': 'embedded', 'command': None, 'args': ['-n', '-d', 'precision=12', './app/bin/main.php', 'é🙂', 'a $() ` literal', '-d', 'precision=3'], 'env': {'set': {'MOBILE_TEST': '日本\n$()'}}}}
    file = work / 'manifest.json'
    file.write_text(json.dumps(manifest))
    for name in ['first', 'second']:
        subprocess.run(['python3', str(root / 'mobile/prepare.py'), str(file), str(source), str(work / name)], check=True)
    assert (work / 'first/payload.sha256').read_text() == (work / 'second/payload.sha256').read_text()
    result = json.loads(subprocess.check_output(['php', '-n', str(work / 'first/payload/bootstrap.php')]))
    assert result['args'][1:] == ['é🙂', 'a $() ` literal', '-d', 'precision=3'], result
    assert result['argc'] == 5 and result['environment'] == '日本\n$()' and not result['pollution'] and result['cwd'] == 'app' and result['stdio'], result
    for mode, entry in [('system', './app/bin/main.php'), ('embedded', './app/../../escape.php')]:
        manifest['launch']['mode'] = mode
        manifest['launch']['args'] = [entry]
        file.write_text(json.dumps(manifest))
        result = subprocess.run(['python3', str(root / 'mobile/prepare.py'), str(file), str(source), str(work / 'invalid')], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        assert result.returncode != 0, (mode, entry)
print('Mobile payload tests passed')
