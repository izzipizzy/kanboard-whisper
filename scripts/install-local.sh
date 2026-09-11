#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
container="${1:-kanboard-kanboard-1}"
command -v docker >/dev/null
command -v python3 >/dev/null
# Validate database location before starting a worker against shared volumes.
docker exec "$container" php -r 'require "/var/www/app/app/common.php"; if (DB_DRIVER !== "sqlite" || realpath(DB_FILENAME) !== "/var/www/app/data/db.sqlite") { fwrite(STDERR, "Automatic installer requires SQLite at /var/www/app/data/db.sqlite. See README for custom database deployments.\n"); exit(1); }'
# Read mount metadata only; do not copy or print the container's secret environment.
container="$container" python3 - <<'PY'
import json, os, pathlib, subprocess
name = os.environ['container']
info = json.loads(subprocess.check_output(['docker', 'inspect', name]))[0]
for dest in ['/var/www/app/plugins', '/var/www/app/data']:
    if not any(m['Destination'] == dest for m in info['Mounts']):
        raise SystemExit(f'Kanboard needs a persistent {dest} mount first.')
networks = list(info['NetworkSettings']['Networks'])
if not networks:
    raise SystemExit('Kanboard must be attached to a Docker network.')
# Pin worker to the EXACT running image, avoiding PHP/application mismatches.
values = {'KANBOARD_CONTAINER': name, 'KANBOARD_IMAGE': info['Image'], 'KANBOARD_NETWORK': networks[0]}
path = pathlib.Path('.env.local')
if path.exists():
    current = dict(line.split('=',1) for line in path.read_text().splitlines() if '=' in line and not line.startswith('#'))
    if any(current.get(k) != v for k,v in values.items()):
        raise SystemExit('.env.local targets a different container/image/network. Review and update it first.')
else:
    path.write_text(''.join(f'{k}={v}\n' for k,v in values.items()))
    path.chmod(0o600)
PY
if docker exec "$container" test -e /var/www/app/plugins/Whisper; then
  # Also supports users who first installed the PHP ZIP from the plugin directory.
  mkdir -p .backups
  docker cp "$container:/var/www/app/plugins/Whisper" ".backups/Whisper-$(date +%Y%m%d-%H%M%S)"
  docker compose --env-file .env.local stop bot
  backup_name="whisper-before-$(date +%Y%m%d-%H%M%S).sqlite"
  docker exec "$container" php -r 'require "/var/www/app/app/common.php"; $pdo = new PDO("sqlite:".DB_FILENAME); $pdo->exec("VACUUM INTO ".$pdo->quote("/tmp/".$argv[1]));' "$backup_name"
  docker cp "$container:/tmp/$backup_name" ".backups/$backup_name"
  chmod 600 ".backups/$backup_name"
  docker exec "$container" rm "/tmp/$backup_name"
  docker cp Whisper/. "$container:/var/www/app/plugins/Whisper/"
else
  docker cp Whisper "$container:/var/www/app/plugins/Whisper"
fi
docker compose --env-file .env.local up -d --build
echo 'Installed. Open Kanboard > Settings > Telegram Whisper.'
