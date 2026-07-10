#!/usr/bin/env bash
set -euo pipefail

# PHPStan を実 fs に複製した phar で実行する DX ラッパー。
#
# 背景: virtiofs マウント(Docker Desktop / OrbStack の host share)上で phpstan.phar を
# 並列 worker が同時に open/mmap するとレースで "Cannot open phar archive" が出ることがある。
# phar を実 fs の /tmp に内容ハッシュ鍵で複製してから実行すると並列フル活用のまま安定する。
# native fs では無害な 1 回コピーになるだけ。

SRC="${PHPSTAN_PHAR:-vendor/phpstan/phpstan/phpstan.phar}"

if [ ! -f "$SRC" ]; then
    echo "phpstan.phar が見つかりません: $SRC (composer install 済みか確認)" >&2
    exit 1
fi

HASH="$(shasum -a 1 "$SRC" | cut -d' ' -f1)"
CACHE_DIR="${TMPDIR:-/tmp}/app-phpstan"
CACHED="$CACHE_DIR/phpstan-$HASH.phar"

mkdir -p "$CACHE_DIR"

if [ ! -f "$CACHED" ]; then
    TMP="$CACHE_DIR/.phpstan-$HASH.$$.tmp"
    cp "$SRC" "$TMP"
    mv -f "$TMP" "$CACHED"
fi

exec php "$CACHED" "$@"
