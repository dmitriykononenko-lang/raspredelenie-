#!/usr/bin/env bash
# =============================================================================
# KO: WORKS — AmoCRM Deal Distribution Widget
# Скрипт полной установки на чистый Ubuntu 22.04 LTS
#
# Использование (от root или через sudo):
#   curl -sSL https://raw.githubusercontent.com/dmitriykononenko-lang/Basa/main/deploy.sh | bash
#
# Или вручную:
#   chmod +x deploy.sh && sudo ./deploy.sh
# =============================================================================

set -euo pipefail

DOMAIN="dist.koagency.me"
EMAIL="admin@koagency.me"          # email для Let's Encrypt уведомлений
REPO="https://github.com/dmitriykononenko-lang/Basa.git"
BRANCH="claude/amocrm-deal-distribution-widget-ceg7R"
APP_DIR="/opt/deal-dist"
STORAGE_DIR="/var/lib/deal-dist"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

log()  { echo -e "${GREEN}▶${NC} $*"; }
warn() { echo -e "${YELLOW}⚠${NC}  $*"; }
err()  { echo -e "${RED}✗${NC}  $*" >&2; exit 1; }
sep()  { echo "────────────────────────────────────────────"; }

# ── Проверки ──────────────────────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && err "Запустите скрипт от root: sudo ./deploy.sh"
[[ $(lsb_release -rs 2>/dev/null) != "22.04" ]] && warn "Рекомендуется Ubuntu 22.04. Продолжаем..."

sep
echo "  KO: WORKS — Deploy Script"
echo "  Домен:   $DOMAIN"
echo "  Каталог: $APP_DIR"
sep

# ── 1. Системные пакеты ────────────────────────────────────────────────────────
log "Обновление системы..."
apt-get update -qq
apt-get upgrade -y -qq

log "Установка базовых пакетов..."
apt-get install -y -qq \
    curl wget git unzip ca-certificates \
    gnupg lsb-release ufw fail2ban

# ── 2. Docker ─────────────────────────────────────────────────────────────────
if ! command -v docker &>/dev/null; then
    log "Установка Docker..."
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg

    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" \
        > /etc/apt/sources.list.d/docker.list

    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin
    systemctl enable --now docker
else
    log "Docker уже установлен: $(docker --version)"
fi

# ── 3. Клонирование репозитория ───────────────────────────────────────────────
log "Клонирование репозитория..."
if [[ -d "$APP_DIR" ]]; then
    warn "$APP_DIR уже существует — обновляем..."
    git -C "$APP_DIR" fetch origin
    git -C "$APP_DIR" checkout "$BRANCH"
    git -C "$APP_DIR" pull origin "$BRANCH"
else
    git clone --branch "$BRANCH" "$REPO" "$APP_DIR"
fi

# ── 4. Хранилище данных ───────────────────────────────────────────────────────
log "Создание директорий хранилища..."
mkdir -p "$STORAGE_DIR"/{tokens,queues,schedules,settings,logs}
chown -R 82:82 "$STORAGE_DIR"   # uid 82 = www-data в alpine

# ── 5. Переменные окружения ───────────────────────────────────────────────────
ENV_FILE="$APP_DIR/server/.env"
if [[ ! -f "$ENV_FILE" ]]; then
    log "Создание .env файла..."
    cp "$APP_DIR/server/.env.example" "$ENV_FILE"

    # Генерируем случайный WIDGET_SECRET
    WIDGET_SECRET=$(openssl rand -hex 32)

    sed -i "s|STORAGE_PATH=.*|STORAGE_PATH=$STORAGE_DIR|"      "$ENV_FILE"
    sed -i "s|WIDGET_SECRET=.*|WIDGET_SECRET=$WIDGET_SECRET|"  "$ENV_FILE"

    echo ""
    warn "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    warn "Заполните AMO_CLIENT_ID и AMO_CLIENT_SECRET в:"
    warn "  $ENV_FILE"
    warn ""
    warn "Получить их можно на:"
    warn "  https://www.amocrm.ru/developers/content/oauth/oauth"
    warn ""
    warn "OAuth Redirect URI для регистрации интеграции:"
    warn "  https://$DOMAIN/oauth/callback"
    warn "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    read -rp "  Нажмите Enter когда заполните .env, или Ctrl+C для отмены..."
else
    log ".env уже существует — пропускаем"
fi

# ── 6. Монтируем хранилище в docker-compose ───────────────────────────────────
log "Настройка storage path в docker-compose..."
# Переопределяем через docker-compose override
cat > "$APP_DIR/docker-compose.override.yml" << EOF
services:
  app:
    env_file: ./server/.env
    environment:
      STORAGE_PATH: $STORAGE_DIR
    volumes:
      - $STORAGE_DIR:$STORAGE_DIR
EOF

# ── 7. Firewall ───────────────────────────────────────────────────────────────
log "Настройка UFW firewall..."
ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow ssh
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# ── 8. Fail2ban ───────────────────────────────────────────────────────────────
log "Настройка Fail2ban..."
systemctl enable --now fail2ban

# ── 9. SSL сертификат (Let's Encrypt) ─────────────────────────────────────────
log "Получение SSL сертификата для $DOMAIN..."

# Сначала поднимаем nginx без SSL (только HTTP) для прохождения ACME challenge
cd "$APP_DIR"

# Временный nginx только для ACME
docker run --rm -d --name nginx-acme \
    -p 80:80 \
    -v "$APP_DIR/docker/nginx/acme-only.conf:/etc/nginx/conf.d/default.conf:ro" \
    -v "deal-dist_certbot_webroot:/var/www/certbot" \
    nginx:1.27-alpine 2>/dev/null || true

# Создаём минимальный nginx конфиг для ACME
mkdir -p "$APP_DIR/docker/nginx"
cat > "$APP_DIR/docker/nginx/acme-only.conf" << 'NGINXEOF'
server {
    listen 80;
    server_name _;
    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }
    location / { return 200 'ok'; }
}
NGINXEOF

# Пересоздаём контейнер с правильным конфигом
docker stop nginx-acme 2>/dev/null || true
docker run --rm -d --name nginx-acme \
    -p 80:80 \
    -v "$APP_DIR/docker/nginx/acme-only.conf:/etc/nginx/conf.d/default.conf:ro" \
    -v "deal-dist_certbot_webroot:/var/www/certbot" \
    nginx:1.27-alpine

sleep 2

# Получаем сертификат
docker run --rm \
    -v "deal-dist_letsencrypt:/etc/letsencrypt" \
    -v "deal-dist_certbot_webroot:/var/www/certbot" \
    certbot/certbot certonly \
    --webroot -w /var/www/certbot \
    --email "$EMAIL" \
    --agree-tos --no-eff-email \
    -d "$DOMAIN" \
    --non-interactive

docker stop nginx-acme

# ── 10. Сборка и запуск ───────────────────────────────────────────────────────
log "Сборка Docker образа..."
cd "$APP_DIR"
docker compose build --no-cache app

log "Запуск сервисов..."
docker compose up -d

# Авторенью сертификата
log "Запуск Certbot авторенью..."
docker compose --profile certbot up -d certbot

# ── 11. Systemd для автозапуска ───────────────────────────────────────────────
log "Настройка автозапуска через systemd..."
cat > /etc/systemd/system/deal-dist.service << EOF
[Unit]
Description=KO: WORKS — AmoCRM Deal Distribution Widget
Requires=docker.service
After=docker.service network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=$APP_DIR
ExecStart=/usr/bin/docker compose up -d
ExecStop=/usr/bin/docker compose down
TimeoutStartSec=120

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable deal-dist

# ── Финал ─────────────────────────────────────────────────────────────────────
sep
echo ""
echo -e "  ${GREEN}✓ Деплой завершён успешно!${NC}"
echo ""
echo "  Сервис запущен:  https://$DOMAIN"
echo "  OAuth callback:  https://$DOMAIN/oauth/callback"
echo "  Логи:            docker compose -f $APP_DIR/docker-compose.yml logs -f"
echo "  Статус:          docker compose -f $APP_DIR/docker-compose.yml ps"
echo ""
echo "  Следующий шаг:"
echo "  Зарегистрируйте интеграцию на amocrm.ru/developers"
echo "  и укажите Redirect URI: https://$DOMAIN/oauth/callback"
echo ""
sep
