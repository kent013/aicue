#!/bin/bash
#
# init.sh — テンプレートから新規アプリを初期化する。
# アプリ名・slug を対話で受け取り .env に反映してから composer setup を実行する。
# 非対話 (CI 等) では引数で渡せる: ./init.sh "My App" myapp

set -e

GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

step()    { echo -e "${BLUE}==>${NC} $1"; }
success() { echo -e "${GREEN}✓${NC} $1"; }

# .env の KEY=VALUE 行を書き換える (GNU/BSD sed 両対応)
set_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" .env; then
        sed -i.bak "s|^${key}=.*|${key}=${value}|" .env && rm -f .env.bak
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

[ -f .env ] || cp .env.example .env

APP_NAME_INPUT="${1:-}"
APP_SLUG_INPUT="${2:-}"

if [ -z "$APP_NAME_INPUT" ]; then
    read -r -p "アプリ表示名 (APP_NAME) [Laravel]: " APP_NAME_INPUT
    APP_NAME_INPUT="${APP_NAME_INPUT:-Laravel}"
fi
if [ -z "$APP_SLUG_INPUT" ]; then
    read -r -p "アプリ slug (小文字英数。API キー prefix 等に使用) [app]: " APP_SLUG_INPUT
    APP_SLUG_INPUT="${APP_SLUG_INPUT:-app}"
fi

if ! printf '%s' "$APP_SLUG_INPUT" | grep -Eq '^[a-z0-9]+$'; then
    echo "ERROR: slug は小文字英数のみです: $APP_SLUG_INPUT" >&2
    exit 1
fi

step ".env を設定"
set_env APP_NAME "\"${APP_NAME_INPUT}\""
set_env TEMPLATE_APP_SLUG "$APP_SLUG_INPUT"
success "APP_NAME=${APP_NAME_INPUT} / TEMPLATE_APP_SLUG=${APP_SLUG_INPUT}"

# packages/cli (first-party CLI) の slug をアプリ slug へ置換する。
# CLI は Laravel の config('template.slug') を参照できない standalone npm パッケージ
# なので、ブランディングの単一ソース (src/branding.ts の APP_SLUG) と package.json の
# name/bin/oclif を初期化時にここで書き換える。既定値 'app' のときは何もしない。
if [ "$APP_SLUG_INPUT" != "app" ] && [ -d packages/cli ]; then
    step "packages/cli の CLI slug を ${APP_SLUG_INPUT} へ置換"
    sed -i.bak "s|export const APP_SLUG = \"app\";|export const APP_SLUG = \"${APP_SLUG_INPUT}\";|" \
        packages/cli/src/branding.ts && rm -f packages/cli/src/branding.ts.bak
    sed -i.bak \
        -e "s|@app/cli|@${APP_SLUG_INPUT}/cli|g" \
        -e "s|\"app\": \"./bin/run.js\"|\"${APP_SLUG_INPUT}\": \"./bin/run.js\"|" \
        -e "s|\"bin\": \"app\"|\"bin\": \"${APP_SLUG_INPUT}\"|" \
        -e "s|\"dirname\": \"app\"|\"dirname\": \"${APP_SLUG_INPUT}\"|" \
        packages/cli/package.json && rm -f packages/cli/package.json.bak
    success "packages/cli を @${APP_SLUG_INPUT}/cli (bin ${APP_SLUG_INPUT}) に設定"
fi

step "composer setup (install / key / migrate / pnpm / build)"
composer setup

if ! grep -q '^CIPHERSWEET_KEY=.\+' .env; then
    step "CIPHERSWEET_KEY を生成 (PII 暗号化キー)"
    php artisan ciphersweet:generate-key
fi

echo ""
success "セットアップ完了"
echo "  - 開発開始: composer dev"
echo "  - 次にやること: AGENTS.md の「使命 (North Star)」を記述し、"
echo "    docs/app-integration-guide.md に従ってドメイン機能を実装する"
echo ""
echo "  ブランドアセットの差し替え (現在はニュートラルなプレースホルダ):"
echo "    - public/favicon.ico / favicon-16x16.png / favicon-32x32.png"
echo "    - public/apple-touch-icon.png / icon-192.png / icon-512.png"
echo "    - public/images/og-default.png (1200x630)"
echo "    - public/site.webmanifest (name / short_name / theme_color)"
echo "    - config/seo.php の theme_color (SEO_THEME_COLOR) を実ブランド色へ"
echo "    - resources/views/errors/partials/logo.blade.php のロゴ SVG"
echo "  法的ページ (プレースホルダ・現在は noindex):"
echo "    - resources/views/legal/{terms,privacy,commerce-disclosure}.blade.php を正式文面へ"
echo "      差し替え、公開時に routes/web.php の NoIndex と blade の meta robots を外す"
echo "  アクセス解析:"
echo "    - GTM を使う場合は .env に GTM_CONTAINER_ID を設定 (production でのみ描画)"
