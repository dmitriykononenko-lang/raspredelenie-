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
        widget.js
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
        widget.js \
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
    *)
        echo "Usage: $0 [--version | --bump major|minor|patch]"
        exit 1
        ;;
esac
