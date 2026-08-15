FROM php:8.4-cli

# ロケール
ENV LC_ALL=C.UTF-8
ENV LANG=C.UTF-8

# システムパッケージ (ffmpeg = v1 動画合成 FfmpegVideoComposer の render runtime 依存。ffprobe 同梱)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    ffmpeg \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    libicu-dev \
    zip \
    unzip \
    sudo \
    locales \
    postgresql-client \
    procps \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# PHP 拡張
# sockets: pest-plugin-browser (in-process amphp サーバ) が必須
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl \
    sockets

# pcov: bug-hunt の実装到達カバレッジ (Stage3) 収集に使う。
# BughuntCoverageMiddleware は env(BUGHUNT_PCOV) と function_exists('\pcov\start') の
# 二重 guard なので、拡張を入れただけでは本番/CI/dev の挙動は変わらない (常に no-op)。
# pcov.directory を /workspace に絞り、vendor/ を除外して収集コストを抑える。
RUN pecl install pcov \
    && docker-php-ext-enable pcov \
    && printf 'pcov.enabled=1\npcov.directory=/workspace\npcov.exclude="~vendor~"\n' \
       > /usr/local/etc/php/conf.d/zz-pcov.ini

# 開発用 php.ini (opcache.enable_cli=1 を含む。pest --parallel が同一ファイルを
# 多数プロセスで繰り返し include するため、CLI でも opcode キャッシュが効く)
COPY .devcontainer/php.ini /usr/local/etc/php/conf.d/zz-dev.ini

# 日本語フォント (Browser テストのスクリーンショット・PDF 用)。
# chromium 本体は Playwright が独自 DL する (`pnpm exec playwright install chromium`)
# ため system chromium / chromium-driver は入れない (旧 Dusk 構成は撤去済み)。
RUN apt-get update && apt-get install -y --no-install-recommends \
    fonts-noto-cjk \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer
RUN curl -sS https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer

# mise (dev tool version manager)。Node/pnpm の版は mise.toml に集約し、
# Dockerfile では版を持たない。
RUN curl -fsSL https://mise.run | MISE_INSTALL_PATH=/usr/local/bin/mise sh

# Stripe CLI (課金・Webhook 開発用)
ARG STRIPE_CLI_VERSION=1.40.9
RUN ARCH=$(dpkg --print-architecture) \
    && curl -fsSL "https://github.com/stripe/stripe-cli/releases/download/v${STRIPE_CLI_VERSION}/stripe_${STRIPE_CLI_VERSION}_linux_${ARCH}.tar.gz" -o /tmp/stripe.tar.gz \
    && tar -xzf /tmp/stripe.tar.gz -C /usr/local/bin stripe \
    && rm /tmp/stripe.tar.gz \
    && stripe version

# 非 root ユーザー
RUN useradd -ms /bin/bash vscode \
    && usermod -aG sudo vscode \
    && echo "vscode ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers

WORKDIR /workspace

# named volume (vendor / node_modules) を vscode 所有で初期化するため事前作成
RUN mkdir -p /workspace/vendor /workspace/node_modules \
    && chown -R vscode:vscode /workspace

USER vscode

# mise activate (対話 shell) + shims (非対話 shell でも node/pnpm を解決)
ENV PATH="/home/vscode/.local/share/mise/shims:$PATH"
RUN echo 'eval "$(mise activate bash)"' >> /home/vscode/.bashrc

# mise.toml のツールを image にプリインストールしてビルドキャッシュに乗せる。
# bind mount で /workspace は上書きされるが、ツール本体は
# /home/vscode/.local/share/mise/installs に残るため起動直後から使える。
COPY --chown=vscode:vscode mise.toml /workspace/mise.toml
RUN mise trust /workspace/mise.toml && mise install

# コード索引 (code-review-graph)。AGENTS.md がコードベース探索の第一選択と定めており、
# .claude/settings.json の PostToolUse hook が差分更新を回すため、版を固定して導入する。
# 版を上げるときは scripts/code-review-graph-update-hook.sh の対象外拡張子の棚卸しも行うこと。
#
# 導入先は USER に依存させず明示する (HOME 由来だと RUN の位置を動かしただけで
# /root/.local/bin へ落ち、hook が「未導入」と告知するようになるため)。
ENV UV_TOOL_DIR=/home/vscode/.local/share/uv/tools
ENV UV_TOOL_BIN_DIR=/home/vscode/.local/bin
RUN uv tool install code-review-graph==2.3.7

# 導入先を検索パスへ載せる (hook 側は導入先を知らない = 環境固有の知識を持たせない)
ENV PATH="/home/vscode/.local/bin:$PATH"
