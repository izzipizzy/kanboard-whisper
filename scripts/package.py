#!/usr/bin/env python3
"""Build the exact single-folder archive required by Kanboard's installer."""
import hashlib
import pathlib
import re
import zipfile

root = pathlib.Path(__file__).resolve().parent.parent
source = root / 'Whisper'
version = re.search(r"getPluginVersion\(\).*?return '([^']+)'", (source / 'Plugin.php').read_text()).group(1)
dist = root / 'dist'
dist.mkdir(exist_ok=True)
archive = dist / f'Whisper-{version}.zip'
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('Whisper/', '')
    for path in sorted(source.rglob('*')):
        if path.is_file() and path.suffix == '.php':
            z.write(path, 'Whisper/' + path.relative_to(source).as_posix())
    for name in ['README.md', 'LICENSE', 'CHANGELOG.md', 'SECURITY.md']:
        z.write(root / name, 'Whisper/' + name)
    for name in ['README.ru.md', 'RELEASING.md']:
        path = root / 'docs' / name
        z.write(path, 'Whisper/docs/' + name)
with zipfile.ZipFile(archive) as z:
    assert all(n.startswith('Whisper/') for n in z.namelist())
    assert 'Whisper/Plugin.php' in z.namelist()
    assert not any('.env' in n or '.sqlite' in n for n in z.namelist())
checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
(archive.with_suffix('.zip.sha256')).write_text(f'{checksum}  {archive.name}\n')
print(archive)
