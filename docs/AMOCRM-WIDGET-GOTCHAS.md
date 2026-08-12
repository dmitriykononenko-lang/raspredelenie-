# amoCRM widget — критичные грабли запуска (v2, приватная интеграция)

Находки из отладки установки виджета «Распределение сделок». Дополняет
`AMOCRM-WIDGET-BILLING-LESSONS.md`. Всё проверено на практике.

## Фатальные (виджет не запустится)

1. **Файл входа обязан называться `script.js`.** amoCRM грузит `<path>/script.js`
   по соглашению. `widget.js` → 404 → виджет не стартует. (Исправлено: у нас
   теперь `script.js`.)
2. **`self.system()` нельзя вызывать в конструкторе** — инжектится загрузчиком
   позже. Иначе `Cannot read properties of undefined (reading 'onSave')`.
   Обёртка: `function amoSystem(){ try { return self.system()||{}; } catch(e){ return {}; } }`.
3. **Колбэки — только в `self.callbacks = {…}`**, не на `self` напрямую.
4. **CSS не подключается сам.** Виджет вставляет `<link>` на `css/widget.css`
   через `self.params.path` (у нас — `injectCss()` в init/settings/advancedSettings).

## Манифест (v2)

- `interface_version: 2`, `installation: true`, `oauth: "Y"`.
- Блок `settings` — на **верхнем уровне**, не внутри `widget`.
- `code` / `secret_key` — генерирует кабинет («Ключи и доступы»); нужны для
  **обновления на месте** (иначе `Upload and manifest codes not equal`).
- Dropdown-поля — тип `list`; лишние легаси-поля (`free`, `countries`) удалять.

## Установка приватной интеграции

- amoМаркет → «…» → «Создать интеграцию» → «Приватная интеграция» → «+ Создать».
- Redirect: `https://raspredelenie.koagency.ru/oauth/callback`; загрузить архив;
  сохранить → кабинет генерирует `code`/`secret_key`.
- **Обновление на месте** требует совпадения `code`. Если не совпадает —
  **удалить интеграцию и создать заново** (новый код генерируется, конфликта нет).
- Полноэкранный UI (Статусы/Шаблоны/Правила) рендерится через `advanced_settings`
  и **работает в приватной интеграции**.

## Левое меню (`left_menu` + `widget_page` + `initMenuPage`)

- Инициализируется **только у публичной интеграции**. У приватной — иконка
  либо не появляется, либо страница пустая.
- Публичная загрузка может блокироваться на стороне amoCRM ошибкой
  `Secret key for this widget code is not correct` → тикет в поддержку amoCRM.
- Для приватной интеграции это некритично — весь функционал доступен через
  `advanced_settings`.

## Логотипы / файлы

- `manifest.json` — в корне архива; без `__MACOSX`/`.DS_Store`.
- Иконки левого меню (для публичной): `images/menu_light.svg`, `images/menu_dark.svg`.

## Бэкенд

- `GET /health` → `{"status":"ok","service":"deal-distribution"}`.
- `/oauth/callback` в режиме долгосрочного токена возвращает 200 «Установка
  завершена» (полный OAuth-обмен не нужен).

## Две сборки: приватная (рабочая) и публичная (левое меню)

Одна кодовая база, два манифеста:

- **`manifest.json`** — ПРИВАТНАЯ интеграция. Ставится и работает сейчас; весь
  UI через `advanced_settings`. Это дефолтный архив для загрузки.
- **`manifest.public.json`** — ПУБЛИЧНАЯ (амоМаркет) с левым меню
  (`left_menu` + `widget_page` + колбэк `initMenuPage`, иконки
  `images/menu_light.svg` / `menu_dark.svg`). Собирается командой
  `AMO_WIDGET_CODE=<код> AMO_CLIENT_SECRET=<секрет> ./build.sh --public`
  — она сама подставит `code`/`secret_key` из окружения и положит
  `manifest.json` в корень архива (значения в git не хранятся).

`script.js` общий — содержит `initMenuPage` (в приватной просто не вызывается).

### Про поля `free` и `countries`
Это реальные маркетплейс-настройки: `free: "N"` — платный виджет,
`countries: ["RU","KZ","BY"]` — страны доступности. НО в `manifest.json` они
вызывают ошибку «Unknown field(s)». Их место — **форма публикации в кабинете
amoМаркета** (платный/бесплатный, страны), а не манифест. Поэтому в JSON их нет.

## Публичная интеграция: находки при установке (партнёрский аккаунт)

### Баг UI-загрузчика архива в amoМаркете
Интерфейс загрузки архива публичной интеграции шлёт
`POST /ajax/v3/public_clients/{uuid}/{app_id}/archive/` с полем файла `archive`
(плюс `_archive`), тогда как **сервер ожидает поля `widget` + `secret`**. В итоге
ответ:
```
"widget" and "secret" params are required
```
а в UI показывается «**Secret key for this widget code is not correct**».

Важно: это **НЕ значит, что код/секрет неверные.** Тот же `code`
(`raspredelenie_ko`) и секрет прекрасно принимаются при OAuth-обмене (токен
успешно сохраняется). Проблема именно в UI-загрузчике архива amoМаркета.

Обходы:
- (а) установить как **приватную** интеграцию (архив из `manifest.json`);
- (б) обратиться в поддержку amoCRM, приложив этот request/response
  (несоответствие полей `archive` vs `widget`+`secret`).

### `widget_code` — БЕЗ суффикса
Вопреки части документации, кабинет **не добавляет** суффикс к коду виджета:
`code` = ровно то, что введено в поле «Код виджета». Подтверждено запросом
`GET /ajax/v3/public_clients/{uuid}` → поле `widget_code` = `raspredelenie_ko`
(без суффикса). Именно это значение передаём в `AMO_WIDGET_CODE` при
`./build.sh --public`.

### Bootstrap токена без установки виджета («Код авторизации»)
На вкладке «Ключи и доступы» публичной интеграции есть **«Код авторизации»**
(живёт ~20 минут). Его можно обменять на токены вручную, не проходя UI-установку:
```
GET https://raspredelenie.koagency.ru/oauth/callback?code=<authcode>&referer=<subdomain>.amocrm.ru
```
Бэкенд сам выполнит обмен на паре `client_id`/`client_secret` из `.env` и сохранит
токен (`storage/tokens/<account_id>.json`). Удобно для тестов и первичной
авторизации техаккаунта. См. также `bin/oauth-bootstrap.sh`.

### `.env` и `env_file` (почему «залипал» client_id)
Правки `.env` могут не применяться, если в compose есть `env_file` — переменные
бэкаются в контейнер при создании и не перечитываются на `restart` (симптом amo:
`Check the client_id parameter`). В этом проекте `env_file` **убран** — `.env`
монтируется файлом и читается через Dotenv. Подробно: `TROUBLESHOOTING.md` §5.
