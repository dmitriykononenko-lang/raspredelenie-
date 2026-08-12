#!/usr/bin/env bash
# =============================================================================
# KO: WORKS — AmoCRM Deal Distribution Widget — Build script
#
# Usage:
#   ./build.sh              # build widget ZIP (default)
#   ./build.sh --version    # print current version
#   ./build.sh --bump patch # bump patch version and build
#   ./build.sh --bump minor # bump minor version and build
#   ./build.sh --bump major # bump major version and build
# =============================================================================

set -euo pipefail

WIDGET_DIR="$(cd "$(dirname "$0")" && pwd)"
DIST_DIR="$WIDGET_DIR/dist"
MANIFEST="$WIDGET_DIR/manifest.json"
PUBLIC_MANIFEST="$WIDGET_DIR/manifest.public.json"

# ── Helpers ───────────────────────────────────────────────────────────────────

log()  { echo "  $*"; }
ok()   { echo "✓ $*"; }
err()  { echo "✗ $*" >&2; exit 1; }
sep()  { echo "────────────────────────────────────"; }

require_cmd() { command -v "$1" &>/dev/null || err "Required command not found: $1"; }

# ── Read version from manifest ────────────────────────────────────────────────

get_version() {
    python3 -c "import json,sys; print(json.load(open('$MANIFEST'))['widget']['version'])"
}

# ── Bump semantic version ─────────────────────────────────────────────────────

bump_version() {
    local part="$1"
    local current
    current=$(get_version)
    IFS='.' read -r major minor patch <<< "$current"

    case "$part" in
        major) major=$((major + 1)); minor=0; patch=0 ;;
        minor) minor=$((minor + 1)); patch=0 ;;
        patch) patch=$((patch + 1)) ;;
        *) err "Unknown version part: $part (use major|minor|patch)" ;;
    esac

    local new_ver="$major.$minor.$patch"
    python3 - "$MANIFEST" "$new_ver" <<'PYEOF'
import json, sys
path, ver = sys.argv[1], sys.argv[2]
data = json.load(open(path))
data['widget']['version'] = ver
json.dump(data, open(path, 'w'), ensure_ascii=False, indent=2)
print(ver)
PYEOF
}

# ── Validate required files ───────────────────────────────────────────────────

validate() {
    local required=(
        manifest.json
        script.js
        css/widget.css
        i18n/ru.json
        i18n/en.json
        images/logo.png
        images/logo_medium.png
    )
    local missing=0
    for f in "${required[@]}"; do
        if [[ ! -f "$WIDGET_DIR/$f" ]]; then
            log "MISSING: $f"
            missing=$((missing + 1))
        fi
    done
    [[ $missing -eq 0 ]] || err "$missing required file(s) missing — aborting."
}

# ── Build ─────────────────────────────────────────────────────────────────────

build() {
    require_cmd python3
    require_cmd zip

    local version
    version=$(get_version)
    local out_name="deal-distribution-widget-v${version}.zip"
    local out_path="$DIST_DIR/$out_name"

    sep
    echo "  KO: WORKS — AmoCRM Deal Distribution Widget"
    echo "  Version: $version"
    sep

    # Validate source files
    log "Validating source files..."
    validate
    ok "All required files present"

    # Prepare dist dir
    mkdir -p "$DIST_DIR"
    rm -f "$DIST_DIR"/deal-distribution-widget-*.zip

    # Build ZIP (AmoCRM expects files at the root of the archive)
    log "Building $out_name..."
    cd "$WIDGET_DIR"

    zip -r "$out_path" \
        manifest.json \
        script.js \
        css/ \
        i18n/ \
        images/ \
        --exclude "*.DS_Store" \
        --exclude "*Thumbs.db" \
        -q

    local size
    size=$(du -sh "$out_path" | cut -f1)
    ok "Built: dist/$out_name ($size)"

    sep
    echo ""
    echo "  Next steps:"
    echo "  1. Log in to AmoCRM → Settings → Integrations → Widgets"
    echo "  2. Click 'Create widget' and upload: dist/$out_name"
    echo "  3. Set the OAuth Redirect URI to:"
    echo "     https://YOUR_DOMAIN/oauth/callback"
    echo ""
}

# ── Public build (маркетплейс / левое меню) ───────────────────────────────────
#
# Собирает архив из manifest.public.json (locations включает widget_page +
# left_menu). code/secret_key НЕ хранятся в git — подставляются из окружения:
#   AMO_WIDGET_CODE   — «Код виджета» из кабинета публичной интеграции
#   AMO_CLIENT_SECRET — «Секретный ключ» из кабинета
#
# Пример:
#   AMO_WIDGET_CODE=raspredelenie_ko AMO_CLIENT_SECRET=*** ./build.sh --public
#
build_public() {
    require_cmd python3
    require_cmd zip

    : "${AMO_WIDGET_CODE:?Не задан AMO_WIDGET_CODE (Код виджета из кабинета интеграции)}"
    : "${AMO_CLIENT_SECRET:?Не задан AMO_CLIENT_SECRET (Секретный ключ из кабинета интеграции)}"

    [[ -f "$PUBLIC_MANIFEST" ]] || err "Не найден $PUBLIC_MANIFEST"

    local version
    version=$(python3 -c "import json; print(json.load(open('$PUBLIC_MANIFEST'))['widget']['version'])")
    local out_name="deal-distribution-widget-public-v${version}.zip"
    local out_path="$DIST_DIR/$out_name"

    sep
    echo "  KO: WORKS — ПУБЛИЧНАЯ сборка (левое меню / маркетплейс)"
    echo "  Version:     $version"
    echo "  widget.code: $AMO_WIDGET_CODE"
    sep

    # Проверка исходных файлов (manifest.json генерируется, поэтому не в списке)
    log "Проверка исходников..."
    local required=(
        script.js
        css/widget.css
        i18n/ru.json
        i18n/en.json
        images/logo.png
        images/logo_medium.png
        images/menu_light.svg
        images/menu_dark.svg
    )
    local missing=0
    for f in "${required[@]}"; do
        [[ -f "$WIDGET_DIR/$f" ]] || { log "MISSING: $f"; missing=$((missing + 1)); }
    done
    [[ $missing -eq 0 ]] || err "$missing файл(ов) отсутствует — сборка прервана."
    ok "Все исходники на месте"

    mkdir -p "$DIST_DIR"
    rm -f "$DIST_DIR"/deal-distribution-widget-public-*.zip

    # Staging: генерируем manifest.json (из public), кладём рядом остальные файлы.
    # Секрет живёт только во временном каталоге и в самом zip (dist/ в .gitignore).
    local stage
    stage=$(mktemp -d)
    # Гарантированная очистка staging (в нём manifest.json с секретом) при любом
    # выходе — успех, ошибка или прерывание. build_public — терминальная команда
    # скрипта, поэтому EXIT-trap здесь надёжнее RETURN.
    # shellcheck disable=SC2064
    trap "rm -rf '$stage'" EXIT INT TERM

    log "Генерация manifest.json из manifest.public.json..."
    AMO_WIDGET_CODE="$AMO_WIDGET_CODE" AMO_CLIENT_SECRET="$AMO_CLIENT_SECRET" \
    python3 - "$PUBLIC_MANIFEST" "$stage/manifest.json" <<'PYEOF'
import json, os, sys
src, dst = sys.argv[1], sys.argv[2]
data = json.load(open(src))
data.pop('_comment', None)                       # убрать пояснительный комментарий
code   = os.environ['AMO_WIDGET_CODE']
secret = os.environ['AMO_CLIENT_SECRET']
data['widget']['code']       = code
data['widget']['secret_key'] = secret
json.dump(data, open(dst, 'w', encoding='utf-8'), ensure_ascii=False, indent=2)

# Валидация сгенерированного манифеста
blob = json.dumps(data, ensure_ascii=False)
assert '_comment' not in data,                'внутренний: _comment не удалён'
assert 'REPLACE_WITH_' not in blob,           'остались плейсхолдеры REPLACE_WITH_*'
assert data['widget']['code'],                'widget.code пуст'
assert data['widget']['secret_key'],          'widget.secret_key пуст'
print('  ✓ manifest.json: code=%s secret=%s(len=%d)'
      % (code, '*' * min(4, len(secret)), len(secret)))
PYEOF
    ok "manifest.json сгенерирован (плейсхолдеров нет)"

    # Копируем остальные файлы в staging и собираем архив с корнем = staging,
    # чтобы manifest.json лёг ПЕРВЫМ и без родительской папки.
    cp "$WIDGET_DIR/script.js" "$stage/script.js"
    cp -r "$WIDGET_DIR/css" "$WIDGET_DIR/i18n" "$WIDGET_DIR/images" "$stage/"

    log "Сборка $out_name..."
    ( cd "$stage" && zip -r "$out_path" \
        manifest.json \
        script.js \
        css/ \
        i18n/ \
        images/ \
        --exclude "*.DS_Store" \
        --exclude "*Thumbs.db" \
        -q )

    local size
    size=$(du -sh "$out_path" | cut -f1)
    ok "Готово: dist/$out_name ($size)"

    log "Содержимое архива (manifest.json должен быть первым, без папки):"
    python3 -c "import zipfile,sys; [print('    '+n) for n in zipfile.ZipFile(sys.argv[1]).namelist()[:5]]" "$out_path"

    sep
    echo ""
    echo "  Загрузка: amoМаркет → публичная интеграция → загрузить архив."
    echo "  (Про баг UI-загрузки архива см. docs/AMOCRM-WIDGET-GOTCHAS.md.)"
    echo ""
}

# ── Entry point ───────────────────────────────────────────────────────────────

case "${1:-}" in
    --version)
        echo "$(get_version)"
        ;;
    --bump)
        [[ -n "${2:-}" ]] || err "Specify version part: major | minor | patch"
        new_ver=$(bump_version "$2")
        ok "Version bumped to $new_ver"
        build
        ;;
    ""|--build)
        build
        ;;
    --public)
        build_public
        ;;
    *)
        echo "Usage: $0 [--build | --public | --version | --bump major|minor|patch]"
        echo ""
        echo "  --build            приватная сборка из manifest.json (по умолчанию)"
        echo "  --public           публичная сборка из manifest.public.json;"
        echo "                     требует AMO_WIDGET_CODE и AMO_CLIENT_SECRET в окружении"
        echo "  --version          вывести текущую версию (приватного манифеста)"
        echo "  --bump PART        поднять версию (major|minor|patch) и собрать приватную"
        exit 1
        ;;
esac
