# Задача для Claude Code — консолидация правок (deal-distribution widget)

Контекст: за сессию развёртывания/отладки публичной интеграции amoCRM «Распределение
сделок» выявлен ряд правок. Часть уже в коде (коммит `eea44ce` — терпимый OAuth-callback),
остальное — ниже. Реализуй как отдельные атомарные коммиты в ветке
`claude/deal-distribution-widget-tzy3th`. **Секреты не коммитить** (только через env/аргументы сборки).

Ключевые факты окружения (для проверки, НЕ хардкодить):
- Сервер: Docker, проект `/opt/raspredelenie`, compose `docker compose -p raspredelenie -f docker-compose.shared.yml`.
- Приложение (Slim/PHP-FPM) читает `$_ENV[...]`; `.env` монтируется файлом в `/app/.env`.
- Публичная интеграция partnerskoda: `client_id (ID интеграции) = a9b5cbba-...`, `widget_code = raspredelenie_ko` (БЕЗ суффикса, подтверждено через `/ajax/v3/public_clients/{uuid}`), секрет — в `server/.env`.
- OAuth-обмен подтверждён живым тестом (токен сохранён `storage/tokens/33022710.json`); распределение по графику подтверждено (см. T4).

---

## T1 — build.sh: публичная сборка архива (сейчас пакует приватный манифест)

Проблема: `build.sh` собирает zip из `manifest.json` (приватный, без `code`/`secret_key`,
без `widget_page`/`left_menu`). Для публичной интеграции нужен архив из `manifest.public.json`.

Сделать:
- Добавить режим `./build.sh --public` (рядом с текущим приватным).
- Публичная сборка: взять `manifest.public.json`, убрать ключ `_comment`, подставить
  `widget.code` и `widget.secret_key` из переменных окружения (`AMO_WIDGET_CODE`,
  `AMO_CLIENT_SECRET`) на этапе сборки — **не** из закоммиченного файла и **не** коммитить значения.
- В архив класть `manifest.json` (переименованный из public) в КОРЕНЬ + `script.js`, `css/`,
  `i18n/`, `images/`. Имя: `deal-distribution-widget-public-v${version}.zip`.
- Валидация: JSON валиден, плейсхолдеров `REPLACE_WITH_*` не осталось, `manifest.json` в корне.

Критерий приёмки: `AMO_WIDGET_CODE=raspredelenie_ko AMO_CLIENT_SECRET=*** ./build.sh --public`
даёт корректный zip; `python3 -m zipfile -l` показывает `manifest.json` первым, без родительской папки.

## T2 — Надёжное чтение .env (env_file vs Dotenv) — корневая причина «залипшего client_id»

Проблема: `docker-compose.shared.yml` объявляет `env_file: ./server/.env`. Переменные
окружения бэкятся в контейнер В МОМЕНТ СОЗДАНИЯ; `docker compose restart` их НЕ перечитывает,
а Dotenv не перезаписывает уже заданные `$_ENV`. Итог: после правок `.env` контейнер работал
на старом `client_id`/секрете (симптом: amo `Check the client_id parameter`). Обходили `up -d --force-recreate`.

Сделать (выбрать A, это чище):
- **A.** Убрать `env_file: ./server/.env` из `docker-compose.shared.yml` (оставить mount
  `./server/.env:/app/.env:ro`) и гарантировать, что бутстрап приложения грузит `/app/.env`
  через `Dotenv` (safeLoad + при необходимости `->overwriteEnv()` / `populate($_ENV, override)`),
  чтобы источником истины был именно монтируемый файл. Тогда обычный `restart` применяет правки.
- **B (запасной).** Если env_file оставляем — включить в бутстрапе `Dotenv::createMutable(...)->load()`
  с перезаписью, чтобы файл побеждал baked-env.
- Обновить `DEPLOY-SHARED.md`/`TROUBLESHOOTING.md`: правки `.env` применять `up -d --force-recreate app`
  (если по какой-то причине env_file остаётся).

Критерий приёмки: меняем `AMO_CLIENT_SECRET` в `server/.env`, делаем `restart app` — приложение
использует новое значение (проверка через `/oauth/callback` лог `client_id`/успешный обмен).

## T3 — docs/AMOCRM-WIDGET-GOTCHAS.md: добавить находки

Дописать разделы (файл уже существует):
- **Баг загрузчика amoМаркета.** UI шлёт `POST /ajax/v3/public_clients/{uuid}/{app_id}/archive/`
  с полем файла `archive` (+`_archive`), а сервер требует `widget`+`secret` → ответ
  `"widget" and "secret" params are required` / в UI «Secret key for this widget code is not correct».
  Т.е. код (`raspredelenie_ko`) и секрет верны (секрет принят при 