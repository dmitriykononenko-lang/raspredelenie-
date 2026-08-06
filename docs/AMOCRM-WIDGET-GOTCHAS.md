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
