# Грабли деплоя на общий сервер (рядом с `dubli`)

Сервер `135.106.145.98` · `raspredelenie.koagency.ru` · app на `127.0.0.1:3001`.

---

## 1. Как НЕ уронить `dubli` при правке nginx (главное)

- `nginx -t` проверяет **все** сайты разом. Если наш файл с ошибкой — команда
  падает, и `systemctl reload nginx` **не применит** изменения → `dubli`
  продолжает работать на старом (валидном) конфиге. Reload безопасен.
- ⚠️ **Никогда не делай `systemctl restart nginx` при непроверенном конфиге.**
  При restart с ошибкой nginx не поднимется вообще → упадёт и `dubli`.
  Только так:
  ```bash
  nginx -t && systemctl reload nginx
  ```
- Наш сайт лежит в **отдельном** файле `sites-available/raspredelenie`.
  Файл `dubli` не редактируем — пересечений нет.
- Проверь, что нет второго `default_server` и наш `server_name` не
  перехватывает чужой поддомен:
  ```bash
  grep -R "server_name" /etc/nginx/sites-enabled/
  ```

## 2. Порт 3001 должен быть свободен

```bash
ss -tlnp | grep 3001            # пусто = свободен
```
Если занят — поменяй порт в двух местах: `docker-compose.shared.yml`
(`127.0.0.1:XXXX:80`) и `deploy/nginx-host/raspredelenie.conf` (`proxy_pass`).

## 3. Firewall: 3001 НЕ открывать наружу

Порт слушается только на `127.0.0.1`, публичный доступ не нужен и опасен.
Открытыми во вне остаются лишь 80/443 (уже открыты для `dubli`). Ничего в
UFW добавлять не надо.

## 4. TLS через хостовый certbot

```bash
certbot --nginx -d raspredelenie.koagency.ru --non-interactive --agree-tos -m partnerskoda@gmail.com
```
- Certbot выбирает нужный `server{}` по `-d` (`server_name`) — правит **только**
  наш блок, `dubli` не трогает.
- Порт 80 должен быть доступен снаружи для HTTP-01 проверки — он уже открыт.
- Автопродление подхватит **существующий** systemd-таймер certbot (тот же, что
  у `dubli`). Отдельный ничего настраивать не нужно. Проверить:
  ```bash
  systemctl list-timers | grep certbot
  certbot certificates            # увидишь оба домена
  ```

## 5. Правка `.env` — применяется по `restart`

`.env` примонтирован в контейнер файлом (`./server/.env:/app/.env:ro`) и читается
приложением через Dotenv из `/app/.env`:
```bash
nano server/.env
docker compose -p raspredelenie -f docker-compose.shared.yml restart app
```
достаточно, **пересборка не нужна**.

> **Почему раньше «залипало».** В compose был `env_file: ./server/.env`.
> Переменные из `env_file` бэкаются в окружение контейнера **в момент его
> создания**, а `docker compose restart` их не перечитывает; `Dotenv::createImmutable`
> при этом не перезаписывает уже заданные `$_ENV`. Итог: после правки `.env`
> контейнер продолжал работать на старом `AMO_CLIENT_ID`/секрете (симптом amo:
> `Check the client_id parameter`), и приходилось делать `up -d --force-recreate`.
> Теперь `env_file` убран — `.env` монтируется только файлом и является источником
> истины для прикладных переменных, поэтому обычного `restart` достаточно.
>
> Если по какой-то причине `env_file` вернут — правки `.env` применять так:
> `docker compose -p raspredelenie -f docker-compose.shared.yml up -d --force-recreate app`.

После обновления **кода** — с пересборкой: `up -d --build`.

## 6. Права на хранилище

Том `raspredelenie_storage` создаётся из образа (там `/storage` уже принадлежит
`www-data`). Если в логах «permission denied» на запись:
```bash
docker compose -p raspredelenie -f docker-compose.shared.yml exec -u root app \
  chown -R www-data:www-data /storage
```

## 7. amoCRM: redirect_uri должен совпадать ТОЧНО

В интеграции amoCRM: `https://raspredelenie.koagency.ru/oauth/callback`
— ровно так, **https**, без слэша в конце. Малейшее расхождение → ошибка OAuth
при установке виджета.

## 8. Server URL в настройках виджета

В настройках виджета (в amoCRM) поле **Server URL** = `https://raspredelenie.koagency.ru`
(без `/` в конце; впрочем, `widget.js` его сам обрезает).

## 9. Быстрая диагностика «виджет не отвечает»

```bash
# 1. контейнеры живы?
docker compose -p raspredelenie -f docker-compose.shared.yml ps
# 2. приложение отвечает локально?
curl -s http://127.0.0.1:3001/health          # {"status":"ok",...}
# 3. через хостовый nginx по https?
curl -si https://raspredelenie.koagency.ru/health | head -20
# 4. логи приложения и nginx
docker compose -p raspredelenie -f docker-compose.shared.yml logs --tail=50
tail -n 50 /var/log/nginx/error.log
```
- `curl 3001/health` не отвечает → проблема в контейнере (смотри `logs`).
- `3001` отвечает, а `https` нет → проблема в хостовом nginx/сертификате.
