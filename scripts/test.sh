#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
image="${KANBOARD_TEST_IMAGE:-kanboard/kanboard:v1.2.54}"
docker run --rm --entrypoint sh \
  -v "$PWD/Whisper:/var/www/app/plugins/Whisper:ro" \
  -v "$PWD/tests:/tests:ro" "$image" \
  -c 'find /var/www/app/plugins/Whisper -name "*.php" -exec sh -c '"'"'for file; do php -l "$file" || exit 1; done'"'"' sh {} + && php /tests/integration.php'
web_container="whisper-http-test-$$"
trap 'docker rm -fv "$web_container" >/dev/null 2>&1 || true' EXIT
docker run -d --name "$web_container" -p 127.0.0.1::80 \
  -v "$PWD/Whisper:/var/www/app/plugins/Whisper:ro" -v "$PWD/tests:/tests:ro" "$image" >/dev/null
address="$(docker port "$web_container" 80)"
for attempt in $(seq 1 30); do
  if curl -fsS "http://$address/" >/dev/null 2>&1; then break; fi
  sleep 1
done
docker exec "$web_container" php /tests/http_fixture.php
python3 tests/http_smoke.py "http://$address"
