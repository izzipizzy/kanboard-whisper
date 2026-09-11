#!/usr/bin/env python3
"""Generate a reviewable Kanboard directory entry after choosing the public URL."""
import argparse
import datetime
import json
import pathlib
import re

parser = argparse.ArgumentParser()
parser.add_argument('repository', help='GitHub owner/repository')
parser.add_argument('--author', required=True)
parser.add_argument('--date', default=datetime.date.today().isoformat())
args = parser.parse_args()
if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', args.repository):
    parser.error('Expected owner/repository')
plugin = (pathlib.Path(__file__).resolve().parent.parent / 'Whisper/Plugin.php').read_text()
version = re.search(r"getPluginVersion\(\).*?return '([^']+)'", plugin).group(1)
compatible = re.search(r"getCompatibleVersion\(\).*?return '([^']+)'", plugin).group(1)
home = 'https://github.com/' + args.repository
print(json.dumps({'Whisper': {
    'author': args.author, 'compatible_version': compatible,
    'description': 'Create Kanboard tasks from Telegram voice notes using local Whisper, with optional DeepSeek/OpenRouter normalization. Requires companion speech and polling services; see installation instructions.',
    'download': f'{home}/releases/download/v{version}/Whisper-{version}.zip',
    'has_hooks': True, 'has_overrides': False, 'has_schema': True,
    'homepage': home, 'is_type': 'connector', 'last_updated': args.date,
    'license': 'MIT', 'readme': f'{home}/blob/main/README.md',
    'remote_install': False, 'title': 'Telegram Whisper', 'version': version,
}}, indent=4))
