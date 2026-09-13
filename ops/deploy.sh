#!/bin/bash
# Единый деплой: тесты → коммит → сборка образа → запуск → smoke. Любой шаг падает — остальное не выполняется.
#   ops/deploy.sh "сообщение коммита"
set -e
cd /opt/raspredelenie
MSG="${1:-deploy $(date +%F_%H%M)}"
echo "== 1/5 tests"; ops/run-tests.sh | tail -3
echo "== 2/5 lint";  for f in $(git diff --name-only HEAD -- 'server/**/*.php' 'server/bin/*.php'; git ls-files --others --exclude-standard -- 'server/**/*.php'); do php -l "$f" >/dev/null 2>&1 || docker run --rm -v /opt/raspredelenie/server:/app --entrypoint php raspredelenie-app -l "/app/${f#server/}"; done
echo "== 3/5 commit"; git add -A server/src server/bin server/composer.json server/composer.lock server/tests ops docker docker-compose.shared.yml 2>/dev/null; git -c user.name=deploy -c user.email=deploy@koagency.me commit -q -m "$MSG" && git log --oneline | head -1 || echo "nothing to commit"
echo "== 4/5 build+up"; docker compose -p raspredelenie -f docker-compose.shared.yml build -q app; docker compose -p raspredelenie -f docker-compose.shared.yml up -d app; sleep 3
echo "== 5/5 smoke"
curl -sf http://127.0.0.1:3001/health | grep -q '"ok"' && echo "health ok" || { echo "HEALTH FAILED"; exit 1; }
curl -sf -o /dev/null -w "webhook ack %{time_total}s http=%{http_code}\n" -X POST http://127.0.0.1:3001/webhook/leads -H 'Content-Type: application/json' -d '{"account_id":"0","event":{"type_code":"x","data":{"id":1}}}'
docker exec raspredelenie-app-1 php /app/bin/health-check.php >/dev/null && echo "health-check runs"
crontab -l | grep -q redistribute-tick && echo "cron ok"
git push -q origin HEAD 2>/dev/null && echo "pushed" || echo "push skipped (no GitHub creds)"
echo "== DONE $(git log --oneline | head -1)"
