# Деплой виджета «Распределение сделок» на общий сервер (рядом с `dubli`)

Сервер: `135.106.145.98` · поддомен: `raspredelenie.koagency.ru`

**Принцип (как у `dubli`):** приложение слушает только `127.0.0.1:3001`,
публичные `80/443` держит **хостовый** nginx и проксирует на него. Второй
публичный nginx не поднимаем, `dubli` не трогаем.

> **Про стек:** бэкенд этого виджета — **PHP + файловое хранилище**.
> Postgres, `DATABASE_SSL`, `env.schema.ts` — это про NestJS-бэкенд `dubli`,
> к распределению **не относятся**. Отдельная БД не нужна.

---

## 0. Предпосылки (уже есть)

- [x] DNS: `raspredelenie.koagency.ru` A → `135.106.145.98`
- [x] На сервере установлен Docker (на нём работает `dubli`)
- [x] Хостовый nginx на `80/443` + `certbot` (используются для `dubli`)

---

## 1. Код в отдельную папку (`/opt/raspredelenie`, `/opt/dubli` не трогаем)

```bash
ssh -i ~/.ssh/deploy_key root@135.106.145.98

git clone -b claude/deal-distribution-widget-tzy3th \
  https://github.com/dmitriykononenko-lang/raspredelenie-.git /opt/raspredelenie
cd /opt/raspredelenie
```

## 2. Настроить `.env`

```bash
cp server/.env.example server/.env
# WIDGET_SECRET — случайная строка:
sed -i "s|WIDGET_SECRET=.*|WIDGET_SECRET=$(openssl rand -hex 32)|" server/.env
nano server/.env
```

Заполнить доступы своей интеграции amoCRM:
```
AMO_CLIENT_ID=...
AMO_CLIENT_SECRET=...
```
`AMO_REDIRECT_URI` уже = `https://raspredelenie.koagency.ru/oauth/callback`.
`STORAGE_PATH` внутри контейнера переопределяется на `/storage` (том `raspredelenie_storage`) — менять не нужно.

## 3. Поднять контейнеры (свой project name, только `127.0.0.1:3001`)

```bash
docker compose -p raspredelenie -f docker-compose.shared.yml up -d --build
```

Проверка (наружу порт не виден, только на localhost):
```bash
docker compose -p raspredelenie -f docker-compose.shared.yml ps
curl -s http://127.0.0.1:3001/health      # → {"status":"ok","service":"deal-distribution"}
```
Контейнеры называются `raspredelenie-app-1`, `raspredelenie-web-1` — с `dubli` не пересекаются.

## 4. Хостовый nginx — добавить сайт (файл `dubli` не трогаем)

```bash
cp /opt/raspredelenie/deploy/nginx-host/raspredelenie.conf \
   /etc/nginx/sites-available/raspredelenie
ln -sf /etc/nginx/sites-available/raspredelenie \
   /etc/nginx/sites-enabled/raspredelenie
nginx -t && systemctl reload nginx
```

## 5. TLS (домен уже делегирован, A-запись есть)

```bash
certbot --nginx -d raspredelenie.koagency.ru \
  --non-interactive --agree-tos -m partnerskoda@gmail.com

curl -s https://raspredelenie.koagency.ru/health    # → {"status":"ok",...}
```

## 6. amoCRM

В личном кабинете разработчика у интеграции виджета распределения указать:
```
Redirect URI: https://raspredelenie.koagency.ru/oauth/callback
```

## 7. Упаковать и загрузить виджет

```bash
cd /opt/raspredelenie
zip -r /tmp/raspredelenie-widget.zip manifest.json widget.js css i18n images
```
Скачать `/tmp/raspredelenie-widget.zip`, загрузить в amoCRM
(**Настройки → Интеграции → Загрузить виджет**). В настройках виджета
**Server URL** = `https://raspredelenie.koagency.ru`.

---

## Обслуживание

```bash
# логи
docker compose -p raspredelenie -f docker-compose.shared.yml logs -f
# перезапуск
docker compose -p raspredelenie -f docker-compose.shared.yml restart
# обновление кода
cd /opt/raspredelenie && git pull \
  && docker compose -p raspredelenie -f docker-compose.shared.yml up -d --build
```

## Почему это не ломает `dubli`

| Ресурс | dubli | raspredelenie |
|---|---|---|
| Папка | `/opt/dubli` | `/opt/raspredelenie` |
| Docker project | `dubli` | `raspredelenie` |
| Локальный порт | свой | `127.0.0.1:3001` |
| Публичные 80/443 | **хостовый nginx (общий)** | тот же хостовый nginx, отдельный site-файл |
| nginx site | `.../sites-*/dubli` | `.../sites-*/raspredelenie` |
| SSL | свой сертификат | свой сертификат (`certbot --nginx`) |
