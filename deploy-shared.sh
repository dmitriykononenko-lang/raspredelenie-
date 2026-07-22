#!/usr/bin/env bash
# =============================================================================
# KO: WORKS — Виджет «Распределение сделок»
# Автодеплой на ОБЩИЙ сервер рядом с dubli (Ubuntu, Docker уже установлен).
#
# Приложение слушает только 127.0.0.1:3001, публичные 80/443 держит ХОСТОВЫЙ
# nginx. dubli не трогается (свой project-name, своя папка, свой nginx-site).
#
# Запуск на сервере (НЕ через curl|bash — нужен обычный tty):
#   curl -sSL https://raw.githubusercontent.com/dmitriykononenko-lang/raspredelenie-/claude/deal-distribution-widget-tzy3th/deploy-shared.sh -o /tmp/deploy-shared.sh
#   sudo bash /tmp/deploy-shared.sh
#
# Скрипт идемпотентный: если .env ещё не заполнен — остановится с подсказкой,
# заполнишь доступы amoCRM и запустишь ещё раз, он продолжит с того же места.
# =============================================================================

set -euo pipefail

DOMAIN="raspredelenie.koagency.ru"
EMAIL="partnerskoda@gmail.com"
REPO="https://github.com/dmitriykononenko-lang/raspredelenie-.git"
BRANCH="claude/deal-distribution-widget-tzy3th"
APP_DIR="/opt/raspredelenie"
PROJECT="raspredelenie"
COMPOSE="docker-compose.shared.yml"
PORT="3001"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
log()  { echo -e "${GREEN}▶${NC} $*"; }
warn() { echo -e "${YELLOW}⚠${NC}  $*"; }
err()  { echo -e "${RED}✗${NC}  $*" >&2; exit 1; }
sep()  { echo "────────────────────────────────────────────"; }

[[ $EUID -ne 0 ]] && err "Запусти от root: sudo bash /tmp/deploy-shared.sh"
command -v docker >/dev/null 2>&1 || err "Docker не найден. На сервере с dubli он должен быть — проверь."
docker compose version >/dev/null 2>&1 || err "docker compose (плагин) не найден."
command -v nginx >/dev/null 2>&1 || err "Хостовый nginx не найден (а dubli им пользуется?). Проверь."

sep; echo "  Деплой виджета распределения → $DOMAIN"; echo "  Папка: $APP_DIR · порт: 127.0.0.1:$PORT"; sep

# ── 1. Проверка, что порт свободен ──────────────────────────────────────────
if ss -tlnp 2>/dev/null | grep -q "127.0.0.1:$PORT\b"; then
    warn "Порт $PORT уже слушается. Если это прошлый запуск этого же виджета — ок, продолжаем."
fi

# ── 2. Код ──────────────────────────────────────────────────────────────────
if [[ -d "$APP_DIR/.git" ]]; then
    log "Обновляю код в $APP_DIR..."
    git -C "$APP_DIR" fetch origin "$BRANCH" --quiet
    git -C "$APP_DIR" checkout "$BRANCH" --quiet
    git -C "$APP_DIR" reset --hard "origin/$BRANCH" --quiet
else
    log "Клонирую код в $APP_DIR..."
    git clone --branch "$BRANCH" "$REPO" "$APP_DIR" --quiet
fi
cd "$APP_DIR"

# ── 3. .env ─────────────────────────────────────────────────────────────────
ENV_FILE="$APP_DIR/server/.env"
if [[ ! -f "$ENV_FILE" ]]; then
    log "Создаю .env..."
    cp "$APP_DIR/server/.env.example" "$ENV_FILE"
    sed -i "s|WIDGET_SECRET=.*|WIDGET_SECRET=$(openssl rand -hex 32)|" "$ENV_FILE"
fi

# Проверяем, что доступы amoCRM заполнены (не плейсхолдеры)
if grep -q "your_integration_client_id" "$ENV_FILE"; then
    sep
    warn "Нужно вписать доступы твоей интеграции amoCRM."
    warn "Открой файл:   nano $ENV_FILE"
    warn "и заполни:"
    warn "   AMO_CLIENT_ID=..."
    warn "   AMO_CLIENT_SECRET=..."
    warn "(AMO_REDIRECT_URI и WIDGET_SECRET уже заполнены)"
    warn ""
    warn "Потом запусти этот скрипт ещё раз — он продолжит:"
    warn "   sudo bash /tmp/deploy-shared.sh"
    sep
    exit 0
fi

# ── 4. Контейнеры (свой project-name, только localhost:3001) ────────────────
log "Собираю и запускаю контейнеры (project: $PROJECT)..."
docker compose -p "$PROJECT" -f "$COMPOSE" up -d --build

log "Жду готовности приложения на 127.0.0.1:$PORT..."
ok=0
for i in $(seq 1 30); do
    if curl -fs "http://127.0.0.1:$PORT/health" >/dev/null 2>&1; then ok=1; break; fi
    sleep 2
done
[[ $ok -eq 1 ]] || { docker compose -p "$PROJECT" -f "$COMPOSE" logs --tail=40; err "Приложение не ответило на /health. Логи выше."; }
log "Приложение отвечает: $(curl -fs http://127.0.0.1:$PORT/health)"

# ── 5. Хостовый nginx (отдельный site-файл, dubli не трогаем) ───────────────
log "Настраиваю хостовый nginx..."
install -m 0644 "$APP_DIR/deploy/nginx-host/raspredelenie.conf" /etc/nginx/sites-available/raspredelenie
ln -sf /etc/nginx/sites-available/raspredelenie /etc/nginx/sites-enabled/raspredelenie
if nginx -t 2>/tmp/nginx-test.log; then
    systemctl reload nginx
    log "nginx перезагружен (reload) — dubli не затронут."
else
    cat /tmp/nginx-test.log
    err "nginx -t не прошёл. Символическую ссылку НЕ активирую, nginx не перезагружаю (dubli в безопасности)."
fi

# ── 6. TLS ──────────────────────────────────────────────────────────────────
if [[ -d "/etc/letsencrypt/live/$DOMAIN" ]]; then
    log "Сертификат для $DOMAIN уже есть — пропускаю выпуск."
else
    command -v certbot >/dev/null 2>&1 || err "certbot не найден. Поставь: apt-get install -y certbot python3-certbot-nginx"
    log "Выпускаю SSL-сертификат (Let's Encrypt)..."
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect
fi

# ── 7. Финальная проверка ───────────────────────────────────────────────────
sep
if curl -fs "https://$DOMAIN/health" >/dev/null 2>&1; then
    log "ГОТОВО ✓  https://$DOMAIN/health → $(curl -fs https://$DOMAIN/health)"
else
    warn "https://$DOMAIN/health пока не отвечает — проверь DNS/сертификат:"
    warn "   curl -si https://$DOMAIN/health | head"
fi
sep
echo ""
echo "  Дальше — в amoCRM:"
echo "  1. Redirect URI интеграции: https://$DOMAIN/oauth/callback"
echo "  2. Упакуй виджет:  cd $APP_DIR && zip -r /tmp/raspredelenie-widget.zip manifest.json widget.js css i18n images"
echo "  3. Загрузи zip в amoCRM → Настройки → Интеграции → Загрузить виджет"
echo "  4. В настройках виджета Server URL = https://$DOMAIN"
echo ""
echo "  Обновить код позже:  cd $APP_DIR && sudo bash /tmp/deploy-shared.sh"
sep
