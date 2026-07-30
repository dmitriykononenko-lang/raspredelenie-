# Задание: подключить токен amoCRM и установить виджет «Распределение сделок»

**Для кого:** технический исполнитель / агент с SSH-доступом к серверу.
**Цель:** бэкенд начинает работать с amoCRM по долгосрочному токену, виджет
установлен и активен, экран «Расширенные настройки» открывается, статусы
менеджеров сохраняются.

**Время:** ~10 минут.

---

## Контекст

- Виджет amoCRM «Распределение сделок» (KO:AGENCY). Репозиторий:
  `github.com/dmitriykononenko-lang/raspredelenie-`, ветка
  `claude/deal-distribution-widget-tzy3th`.
- Сервер (тот же, где `dubli`): `135.106.145.98`, подключение
  `ssh -i ~/.ssh/deploy_key root@135.106.145.98`.
- Бэкенд развёрнут в `/opt/raspredelenie`, поднимается через
  `docker compose -p raspredelenie -f docker-compose.shared.yml`,
  слушает `127.0.0.1:3001`, наружу проксируется хостовым nginx на
  `https://raspredelenie.koagency.ru`. Виджет `dubli` НЕ трогаем.
- amoCRM: аккаунт `33022710` (`koagency.amocrm.ru`).

**Секрет (передаётся заказчиком отдельно, НЕ в этом файле):**
`AMO_LONG_TERM_TOKEN` — долгосрочный токен доступа amoCRM
(Настройки → Интеграции → «Распределение сделок» → «Ключи и доступы» →
Долгосрочный токен). Никогда не коммитить в git и не слать в переписку.

---

## Часть A. Сервер (SSH)

```bash
ssh -i ~/.ssh/deploy_key root@135.106.145.98

# 1. Обновить код
cd /opt/raspredelenie
git fetch origin claude/deal-distribution-widget-tzy3th
git checkout claude/deal-distribution-widget-tzy3th
git pull origin claude/deal-distribution-widget-tzy3th

# 2. Прописать токен в .env (ЗАМЕНИТЬ <ТОКЕН> на реальный долгосрочный токен).
#    Токен — это длинный JWT вида eyJ0eXAiOiJKV1Qi....
printf 'AMO_LONG_TERM_TOKEN=%s\nAMO_ACCOUNT_ID=33022710\nAMO_BASE_DOMAIN=amocrm.ru\n' '<ТОКЕН>' >> server/.env

#    Проверить, что переменные дописались (значение токена не выводим целиком):
grep -E '^AMO_(ACCOUNT_ID|BASE_DOMAIN)=' server/.env
grep -c '^AMO_LONG_TERM_TOKEN=' server/.env   # должно быть 1

#    Если AMO_LONG_TERM_TOKEN=1 вывело больше 1 — открыть server/.env (nano) и
#    удалить пустой/дублирующий AMO_LONG_TERM_TOKEN=, оставить один с токеном.

# 3. Пересобрать и перезапустить (dubli не затрагивается — свой project name)
docker compose -p raspredelenie -f docker-compose.shared.yml up -d --build

# 4. Проверка бэкенда
curl -s http://127.0.0.1:3001/health          # {"status":"ok",...}
curl -s https://raspredelenie.koagency.ru/health
```

**Критерий успеха части A:** оба `/health` вернули `{"status":"ok",...}`,
контейнеры `raspredelenie-app-1` и `raspredelenie-web-1` в статусе Up
(`docker compose -p raspredelenie -f docker-compose.shared.yml ps`).

---

## Часть B. amoCRM (браузер)

1. Настройки → Интеграции → раздел «Приватные интеграции» → «Распределение сделок».
2. **«Редактировать»** → загрузить актуальный zip виджета (v1.0.11+):
   собрать из репозитория —
   `zip -r widget.zip manifest.json widget.js css i18n images` (manifest в корне).
3. В форме установки указать **Server URL** = `https://raspredelenie.koagency.ru`
   → **«Установить»**.
4. Статус должен смениться c «Отключено» на активный.
5. Открыть виджет → **«Расширенные настройки»** → должен появиться экран с
   чёрным сайдбаром KO:AGENCY и таблицей менеджеров с тумблерами.

---

## Часть C. Смоук-тест

```bash
# Статусы (файловое хранилище; аккаунт 33022710)
curl -s https://raspredelenie.koagency.ru/api/status -H "X-Account-Id: 33022710"
```
- В экране «Расширенные настройки» переключить тумблер любого менеджера →
  повторный `curl` показывает изменившийся статус.
- Проверка распределения: создать тестовую сделку в воронке, где настроено
  правило/Digital Pipeline → сделка автоматически назначается менеджеру;
  запись видна в `curl -s https://raspredelenie.koagency.ru/api/log -H "X-Account-Id: 33022710"`.
- Негативная проверка токена: если API amoCRM отвечает `401` — токен неверный
  или не для аккаунта 33022710 (см. логи: `docker compose -p raspredelenie
  -f docker-compose.shared.yml logs --tail=50 app`).

---

## Безопасность

- `AMO_LONG_TERM_TOKEN` — только в `server/.env` на сервере. НЕ в git, НЕ в чат.
- Если токен где-то засветился — перевыпустить в «Ключи и доступы»
  (кнопка «Сгенерировать токен»), старый отзовётся, новый прописать в `.env`
  и повторить шаг A.3 (пересборка).

## Что вернуть заказчику (отчёт)

1. Вывод `curl .../health` и `curl .../api/status` (без значения токена).
2. Скрин: виджет в статусе активен (не «Отключено»).
3. Скрин: экран «Расширенные настройки» с таблицей менеджеров.
4. Результат смоук-теста распределения (назначилась ли тестовая сделка).
```
