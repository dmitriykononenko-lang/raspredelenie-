# Настройка распределения через API (без UI виджета)

Когда UI виджета недоступен (например, публичная интеграция ещё не установлена,
или баг UI-загрузчика архива — см. `AMOCRM-WIDGET-GOTCHAS.md`), все настройки
можно задать напрямую HTTP-запросами к бэкенду. Эндпоинты те же, что использует
виджет.

- **Базовый URL:** `https://raspredelenie.koagency.ru`
- **account_id:** передаётся заголовком `X-Account-Id: <id>` **или** query-
  параметром `?account_id=<id>` (для `/api/settings` и `/api/distribute` — ещё и
  в теле, поле `account_id`). `<id>` — числовой ID аккаунта amoCRM (например
  `33022710`).
- **Безопасность:** если на сервере включён `WIDGET_SECURITY_ENFORCE=true`, ко
  всем `/api/*` нужно добавлять заголовок `X-Security-Key: <WIDGET_SECRET>`
  (см. T5 в чеклисте деплоя). `/webhook/leads`, `/health`, `/oauth/callback`
  ключ не требуют.

## Порядок фильтров при распределении
```
правило (воронка+этап+фильтры) → онлайн-статус (opt-out, дефолт online)
→ график (check_schedule) → выбор (round_robin | workload)
→ смена ответственного в amoCRM → запись в лог
```
Крона нет: `ScheduleChecker` вызывается в МОМЕНТ распределения. «По графику»
означает, что вне рабочих часов менеджер не получает сделку. Триггеры
распределения: DP-вебхук `POST /webhook/leads` или ручной `POST /api/distribute`.

---

## 1. Настройки и правила — `PUT /api/settings`
Тело: `{account_id, settings}`. `settings` полностью заменяет сохранённые.

```bash
curl -sS -X PUT https://raspredelenie.koagency.ru/api/settings \
  -H 'Content-Type: application/json' \
  -d '{
    "account_id": "33022710",
    "settings": {
      "server_url": "https://raspredelenie.koagency.ru",
      "distribution_method": "round_robin",
      "rules": [
        {
          "pipeline_id": 1234567,
          "stage_id": 7654321,
          "check_history": false,
          "check_schedule": true,
          "managers": [ { "id": 501 }, { "id": 502 } ],
          "filters": {
            "budget_min": 0,
            "budget_max": null,
            "name_contains": "",
            "tags": [],
            "custom_fields": []
          }
        }
      ]
    }
  }'
```

**Объект правила (`rules[]`):**

| Поле | Тип | Смысл |
|---|---|---|
| `pipeline_id` | int/null | Воронка. `null` → любая. Правило срабатывает только на совпадении. |
| `stage_id` | int/null | Этап. `null` → любой этап воронки. |
| `check_history` | bool | Если по контакту/компании уже есть ответственный из списка — назначить его. |
| `check_schedule` | bool | Учитывать рабочее расписание менеджера. |
| `managers` | `[{id}]` | Кандидаты. Пустой список → правило пропускается (`skipped_no_managers`). |
| `filters.budget_min/max` | int/null | Диапазон бюджета сделки. |
| `filters.name_contains` | string | Подстрока в названии сделки. |
| `filters.tags` | string[] | Сделка должна содержать ВСЕ теги. |
| `filters.custom_fields` | `[{field_id,operator,value}]` | Условия по доп. полям. |

`distribution_method`: `round_robin` (по очереди) или `workload` (по числу
открытых сделок).

### Прочитать текущие настройки — `GET /api/settings`
```bash
curl -sS 'https://raspredelenie.koagency.ru/api/settings?account_id=33022710'
```

---

## 2. Онлайн-статусы менеджеров — `PUT /api/status/{userId}`
Модель opt-out: по умолчанию менеджер **включён**. Тело: `{online, actor_id?}`.

```bash
# выключить менеджера 501 из распределения
curl -sS -X PUT 'https://raspredelenie.koagency.ru/api/status/501?account_id=33022710' \
  -H 'Content-Type: application/json' \
  -d '{ "online": false, "actor_id": 100 }'
```

### Все статусы / история
```bash
curl -sS 'https://raspredelenie.koagency.ru/api/status?account_id=33022710'
curl -sS 'https://raspredelenie.koagency.ru/api/status/history?account_id=33022710&limit=50'
```

---

## 3. Рабочие расписания — `PUT /api/schedules/{userId}`
Тело — объект расписания. `timezone` обязателен; в `days` день можно опустить
(без ограничения) или задать `null` (выходной) либо `{start,end}` в формате
`HH:MM`.

```bash
curl -sS -X PUT 'https://raspredelenie.koagency.ru/api/schedules/501?account_id=33022710' \
  -H 'Content-Type: application/json' \
  -d '{
    "timezone": "Europe/Moscow",
    "days": {
      "mon": { "start": "09:00", "end": "18:00" },
      "tue": { "start": "09:00", "end": "18:00" },
      "wed": { "start": "09:00", "end": "18:00" },
      "thu": { "start": "09:00", "end": "18:00" },
      "fri": { "start": "09:00", "end": "18:00" },
      "sat": null,
      "sun": null
    }
  }'
```

### Прочитать / список / удалить
```bash
curl -sS 'https://raspredelenie.koagency.ru/api/schedules/501?account_id=33022710'
curl -sS 'https://raspredelenie.koagency.ru/api/schedules?account_id=33022710'
curl -sS -X DELETE 'https://raspredelenie.koagency.ru/api/schedules/501?account_id=33022710'
```
Нет расписания → менеджер доступен всегда.

---

## 4. Ручной запуск распределения — `POST /api/distribute`
Полезно для теста без движения сделки в воронке. Тело: `account_id`, `lead_id`
обязательны; `pipeline_id`/`stage_id` подтянутся из сделки, если не заданы;
`rules`/`distribution_method` — если не передать, берутся из `/api/settings`.

```bash
curl -sS -X POST https://raspredelenie.koagency.ru/api/distribute \
  -H 'Content-Type: application/json' \
  -d '{ "account_id": "33022710", "lead_id": 987654 }'
```
Ответы: `{"status":"ok","assigned_to":...,"user_id":...}` или
`{"status":"skipped","reason":"no_matching_rule_or_available_manager"}`.

> Готовый сквозной пример (правило + график + тест) — `bin/configure-example.sh`.
