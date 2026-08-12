#!/usr/bin/env bash
# =============================================================================
# Пример настройки распределения через API (без UI виджета).
# Полная документация эндпоинтов: docs/CONFIGURE-VIA-API.md
#
# Задаёт: одно правило по воронке/этапу + рабочее расписание менеджеру,
# затем (опционально) пробует тестовое распределение сделки.
#
# Все параметры — через окружение (ничего не хардкодим):
#   BASE_URL      адрес бэкенда         (по умолчанию https://raspredelenie.koagency.ru)
#   ACCOUNT_ID    ID аккаунта amoCRM    (обязательно, напр. 33022710)
#   PIPELINE_ID   ID воронки            (обязательно)
#   STAGE_ID      ID этапа              (опц.; пусто → любой этап воронки)
#   MANAGER_IDS   ID менеджеров, через запятую (обязательно, напр. 501,502)
#   METHOD        round_robin|workload  (по умолчанию round_robin)
#   TZ            таймзона расписания   (по умолчанию Europe/Moscow)
#   SCHEDULE_USER менеджер для графика  (опц.; по умолчанию первый из MANAGER_IDS)
#   TEST_LEAD_ID  сделка для теста      (опц.; если задано → POST /api/distribute)
#   WIDGET_SECRET значение X-Security-Key (опц.; если сервер с enforce=true)
#
# Пример:
#   ACCOUNT_ID=33022710 PIPELINE_ID=1234567 MANAGER_IDS=501,502 \
#   TEST_LEAD_ID=987654 ./bin/configure-example.sh
# =============================================================================

set -euo pipefail

BASE_URL="${BASE_URL:-https://raspredelenie.koagency.ru}"
METHOD="${METHOD:-round_robin}"
TZ="${TZ:-Europe/Moscow}"

: "${ACCOUNT_ID:?ACCOUNT_ID обязателен (ID аккаунта amoCRM)}"
: "${PIPELINE_ID:?PIPELINE_ID обязателен (ID воронки)}"
: "${MANAGER_IDS:?MANAGER_IDS обязателен (ID менеджеров через запятую, напр. 501,502)}"
STAGE_ID="${STAGE_ID:-}"
SCHEDULE_USER="${SCHEDULE_USER:-${MANAGER_IDS%%,*}}"   # первый из списка

# Заголовки (+ X-Security-Key, если задан WIDGET_SECRET)
HDRS=(-H 'Content-Type: application/json' -H "X-Account-Id: ${ACCOUNT_ID}")
[[ -n "${WIDGET_SECRET:-}" ]] && HDRS+=(-H "X-Security-Key: ${WIDGET_SECRET}")

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }

# managers JSON-массив из "501,502" → [{"id":501},{"id":502}]
managers_json() {
    python3 - "$MANAGER_IDS" <<'PY'
import json, sys
ids = [int(x) for x in sys.argv[1].split(',') if x.strip()]
print(json.dumps([{"id": i} for i in ids]))
PY
}

# ── 1. Правило + метод ────────────────────────────────────────────────────────
say "1) PUT /api/settings — правило (pipeline=${PIPELINE_ID} stage=${STAGE_ID:-любой}) + метод ${METHOD}"
SETTINGS=$(python3 - "$ACCOUNT_ID" "$PIPELINE_ID" "$STAGE_ID" "$METHOD" "$(managers_json)" "$BASE_URL" <<'PY'
import json, sys
account, pipeline, stage, method, managers, base = sys.argv[1:7]
rule = {
    "pipeline_id": int(pipeline),
    "stage_id": int(stage) if stage else None,
    "check_history": False,
    "check_schedule": True,
    "managers": json.loads(managers),
    "filters": {"budget_min": 0, "budget_max": None, "name_contains": "", "tags": [], "custom_fields": []},
}
print(json.dumps({
    "account_id": account,
    "settings": {"server_url": base, "distribution_method": method, "rules": [rule]},
}, ensure_ascii=False))
PY
)
curl -sS -X PUT "${BASE_URL}/api/settings" "${HDRS[@]}" -d "$SETTINGS"; echo

# ── 2. Расписание менеджеру ───────────────────────────────────────────────────
say "2) PUT /api/schedules/${SCHEDULE_USER} — Пн–Пт 09:00–18:00 (${TZ})"
SCHEDULE=$(python3 - "$TZ" <<'PY'
import json, sys
tz = sys.argv[1]
work = {"start": "09:00", "end": "18:00"}
print(json.dumps({"timezone": tz, "days": {
    "mon": work, "tue": work, "wed": work, "thu": work, "fri": work,
    "sat": None, "sun": None,
}}, ensure_ascii=False))
PY
)
curl -sS -X PUT "${BASE_URL}/api/schedules/${SCHEDULE_USER}?account_id=${ACCOUNT_ID}" \
     "${HDRS[@]}" -d "$SCHEDULE"; echo

# ── 3. Проверка сохранённого ──────────────────────────────────────────────────
say "3) GET /api/settings — что сохранилось"
curl -sS "${BASE_URL}/api/settings?account_id=${ACCOUNT_ID}" "${HDRS[@]}"; echo

# ── 4. (опц.) Тестовое распределение ──────────────────────────────────────────
if [[ -n "${TEST_LEAD_ID:-}" ]]; then
    say "4) POST /api/distribute — тест на сделке ${TEST_LEAD_ID}"
    curl -sS -X POST "${BASE_URL}/api/distribute" "${HDRS[@]}" \
        -d "{\"account_id\":\"${ACCOUNT_ID}\",\"lead_id\":${TEST_LEAD_ID}}"; echo
else
    say "4) Тест пропущен (задай TEST_LEAD_ID=<id сделки>, чтобы прогнать POST /api/distribute)"
fi

say "Готово. Подробности по эндпоинтам — docs/CONFIGURE-VIA-API.md"
