#!/usr/bin/env bash
# =============================================================================
# OAuth bootstrap: обмен «Кода авторизации» (вкладка «Ключи и доступы») на токены
# без установки виджета. Обмен выполняет САМ бэкенд в /oauth/callback на паре
# client_id/client_secret из своего .env — этот скрипт лишь инициирует вызов.
# Секрет здесь не читается и не печатается.
#
# Когда полезно: первичная авторизация техаккаунта, повторная авторизация, CI.
# Код авторизации живёт ~20 минут — используй сразу.
#
# Параметры (env или флаги):
#   AUTH_CODE   код авторизации из кабинета     (обязательно)   | --code <...>
#   REFERER     поддомен аккаунта amocrm.ru     (обязательно)   | --referer <sub.amocrm.ru>
#   BASE_URL    адрес бэкенда                   (опц.; иначе из server/.env
#               AMO_REDIRECT_URI, иначе https://raspredelenie.koagency.ru)
#
# Примеры:
#   AUTH_CODE=def502... REFERER=partnerskoda.amocrm.ru ./bin/oauth-bootstrap.sh
#   ./bin/oauth-bootstrap.sh --code def502... --referer partnerskoda.amocrm.ru
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$ROOT_DIR/server/.env"

# ── Разбор флагов ─────────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        --code)    AUTH_CODE="${2:-}"; shift 2 ;;
        --referer) REFERER="${2:-}";   shift 2 ;;
        --base)    BASE_URL="${2:-}";  shift 2 ;;
        *) echo "Неизвестный аргумент: $1" >&2; exit 1 ;;
    esac
done

# ── BASE_URL из .env (AMO_REDIRECT_URI без /oauth/callback), если не задан ─────
# Читаем ТОЛЬКО нужные переменные, секрет (AMO_CLIENT_SECRET) не выводим.
env_get() { [[ -f "$ENV_FILE" ]] && grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' || true; }

if [[ -z "${BASE_URL:-}" ]]; then
    REDIRECT="$(env_get AMO_REDIRECT_URI)"
    if [[ -n "$REDIRECT" ]]; then
        BASE_URL="${REDIRECT%/oauth/callback}"
    else
        BASE_URL="https://raspredelenie.koagency.ru"
    fi
fi

: "${AUTH_CODE:?AUTH_CODE обязателен (Код авторизации из кабинета) — env или --code}"
: "${REFERER:?REFERER обязателен (поддомен, напр. partnerskoda.amocrm.ru) — env или --referer}"

# ── Предпроверка кредов (без вывода значений) ─────────────────────────────────
CID="$(env_get AMO_CLIENT_ID)"
CSECRET_SET="нет"; [[ -n "$(env_get AMO_CLIENT_SECRET)" ]] && CSECRET_SET="да"
LONGTERM_SET="нет"; [[ -n "$(env_get AMO_LONG_TERM_TOKEN)" ]] && LONGTERM_SET="да"

echo "────────────────────────────────────"
echo "  OAuth bootstrap"
echo "  base_url:          $BASE_URL"
echo "  referer:           $REFERER"
echo "  client_id:         ${CID:0:8}${CID:+…}"
echo "  client_secret set: $CSECRET_SET"
echo "  long_term set:     $LONGTERM_SET"
echo "────────────────────────────────────"

if [[ -n "$LONGTERM_SET" && "$LONGTERM_SET" == "да" ]]; then
    echo "⚠ AMO_LONG_TERM_TOKEN задан → callback уйдёт в confirm-only (обмена не будет)." >&2
fi
if [[ "$CSECRET_SET" == "нет" || -z "$CID" ]]; then
    echo "⚠ client_id/secret не заданы в .env → confirm-only, обмена не будет." >&2
fi

# ── Вызов callback (обмен делает бэкенд) ──────────────────────────────────────
URL="${BASE_URL%/}/oauth/callback?code=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$AUTH_CODE")&referer=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$REFERER")"

echo "→ GET /oauth/callback (code скрыт)"
HTTP_CODE=$(curl -sS -o /tmp/oauth_boot_resp.$$ -w '%{http_code}' "$URL" || true)
echo "HTTP $HTTP_CODE"
echo "--- ответ ---"; cat /tmp/oauth_boot_resp.$$ 2>/dev/null; echo; rm -f /tmp/oauth_boot_resp.$$

echo "────────────────────────────────────"
echo "Проверь результат в логах и хранилище токенов:"
echo "  docker compose -p raspredelenie -f docker-compose.shared.yml logs --tail=50 | grep -iE 'OAuth|токен|amo_response'"
echo "  ls -la server/storage/tokens/    # ожидается <account_id>.json"
