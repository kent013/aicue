# デプロイ runbook (Deployer / 開発-staging サーバー)

本書は **AI-CUE の実サーバーへのデプロイ手順の正本**である。
デプロイ定義は家系正典 (`laravel-claude-template` の deployer-pipeline) と同じレイアウトで
`deploy/` 配下にあり、**配布の単一入口は `scripts/deploy.sh` 1 本**である。

```
deploy/
├── deploy.php            # Deployer の設定 (アプリ座標・既定値・本番ゲート・task の require)
├── hosts.example.yml     # host 座標の雛形 (placeholder のみ。追跡下)
├── hosts.yml             # ★実 host 座標 (.gitignore。実 IP / SSH ユーザー / deploy_path)
└── tasks/
    ├── check-env.php     # shared/.env の置き忘れで止める (deploy:shared より前)
    ├── frontend.php      # corepack pnpm で workspace package → アプリ資産をビルド
    ├── verify.php        # production:preflight (migrate より前)
    └── restart.php       # php-fpm reload + systemd queue worker restart (rollback にも配線)
scripts/deploy.sh         # ★配布の単一入口 (座標 fail-fast / git 前提 / 本番の人間ゲート / push)
```

> **実座標を追跡下に置かない**。実 IP・SSH ユーザー・PHP の実パス・鍵のパスは
> `deploy/hosts.yml` (gitignore) にだけ書く。追跡下のファイル
> (`deploy/**` / `scripts/deploy.sh` / `.claude/skills/app-deploy/**` / 本書) に実座標が
> 混ざっていないことは `tests/Architecture/DeployCoordinateHygieneTest.php` が機械検査する。
> 本書が host 名や IP を `<...>` の placeholder で書いているのはそのためである
> (**実値は `deploy/hosts.yml` を読む**)。

---

## 1. サーバー構成

**1 台構成の開発 / staging サーバー**である。production 環境はまだ無い。

| 項目 | 値 |
|---|---|
| 用途 | 開発 / staging (`APP_ENV=staging`) |
| ホスティング | AWS Lightsail (1 インスタンス。Amazon Linux 2023) |
| hostname (IP) | `deploy/hosts.yml` の `hostname` が正本 |
| ドメイン | **未取得** (`aicue.jp` を予定。§8 で切替) |
| TLS | **未導入** (ドメイン取得後に certbot。§8) |
| SSH ユーザー | `deploy/hosts.yml` の `remote_user` が正本 (NOPASSWD sudo 付き) |
| deploy_path | `deploy/hosts.yml` の `deploy_path` が正本 |
| Deployer の host 名 | `deploy/hosts.yml` の宣言が正本 (`stage: dev`) |

### 1.1 インストール済みソフトウェア

サーバー側のプロビジョニングは**手で行った** (Terraform 管理下ではない。§11)。
本書はその結果を前提とする。

| ソフト | 実測 | 備考 |
|---|---|---|
| nginx | 前段の HTTP サーバー | php-fpm と**同一ホスト**。UNIX socket で接続するため proxy hop は増えない (`docs/trusted-proxies-runbook.md` §3.1) |
| php-fpm | 8.4 / aicue 専用プール | プールの実行ユーザーは `remote_user` と同一 (単一ユーザー運用)。socket のパスは nginx 設定側の正本を見る |
| php CLI | `/usr/bin/php` | `deploy/hosts.yml` の `bin/php` に実測値を書く。**AL2023 の php8.4 パッケージは `/usr/bin/php8.4` を置かない** |
| composer | `/usr/local/bin/composer` | 無ければ Deployer が `{{deploy_path}}/.dep/composer.phar` を自動導入する |
| PostgreSQL | 18 / **同一ホスト (localhost)** | 外部 RDS ではない。バックアップは §11 の未対応事項 |
| Node.js | 22 系 | フロントを**サーバー上でビルド**するため必要 |
| pnpm | corepack 経由 | 版の正本は `package.json` の `packageManager`。§1.3 参照 |
| ffmpeg / ffprobe | **静的ビルド** | レンダ / サムネイル生成に必須。パスは `RENDER_FFMPEG_BINARY` / `RENDER_FFPROBE_BINARY` で明示できる (PATH 上に無い場所へ置いた場合は必須) |
| git | `deploy:update_code` がサーバー上で `git` を使う | GitHub への deploy key が必要 (§5) |

### 1.2 常駐プロセス (systemd)

**worker は supervisor ではなく systemd で常駐させる**。プロビジョニングで
**enable 済み・未 start** の状態で用意されている。

| unit | 役割 | デプロイ時の扱い |
|---|---|---|
| `aicue-queue-default.service` | `database` 接続のワーカー | 毎デプロイ **restart** |
| `aicue-queue-analysis.service` | `database-analysis` 接続のワーカー | 毎デプロイ **restart** |
| `aicue-queue-render.service` | `database-render` 接続のワーカー | 毎デプロイ **restart** |
| `aicue-queue-media.service` | `database-media` 接続のワーカー | 毎デプロイ **restart** |
| `aicue-scheduler.timer` | `schedule:run` の定期起動 (毎分) | **触らない** (起動ごとに新プロセスなので次回起動から新コードになる) |
| `php-fpm.service` | FPM | 毎デプロイ **reload** (graceful) |

- ワーカーは reload では新しいコードを読まないため **restart** である。
  4 本を 1 コマンドでまとめて再起動するのは「1 本だけ古いコードのまま残る」状態を作らないため。
- 再起動の有効化は host 単位のフラグ **`queue_worker_restart_enabled`** で宣言する
  (`deploy/hosts.yml`)。既定は `false` で、**worker を常駐させる host では必ず `true` を宣言する**。
  未宣言だと deploy 後も旧コードの worker が動き続ける (無言の劣化)。
  有効なのに unit 一覧が空の場合は例外で**止まる** (「再起動したことにして成功」にしない)。
- unit 名の既定は `deploy/deploy.php` の `queue_worker_units` が持つ。
  host 側の unit 構成が違うときだけ `deploy/hosts.yml` で上書きする。
- `sudo` を使うため、`remote_user` に対して次の 2 つを NOPASSWD で許可しておく (§5-3)。

#### ワーカーの `--timeout`

**値の正本は `docs/architecture.md` §キューのリース期間とワーカー制限時間の規約 の値表**である。
本書にも `deploy/` にも値を**転記しない** — 2 か所に置くと必ず食い違い、
リース切れによるジョブの二重実行を生む。

- 規則 1 (無条件): ワーカーの `--timeout` は、その接続の `retry_after` を**下回る**。
- systemd unit の `ExecStart` に書く `--timeout` は、上記値表の「ワーカー `--timeout`」列と一致させる。
- 値を変えるときは **値表 → systemd unit** の順に直す。デプロイ定義は unit を restart するだけで
  `--timeout` を持たないため、デプロイ側の変更は不要である。
- `config/queue.php` の `retry_after` を変える PR では
  `tests/Architecture/QueueWorkerLeaseInvariantTest.php` が dev 側 (`mprocs.yaml`) を検査するが、
  **サーバーの systemd unit はリポジトリ外なので CI は検知しない**。人手で揃えること。

### 1.3 ホスト側 pnpm の合わせ方

フロントは**サーバー上でビルド**する。`deploy/tasks/frontend.php` は
`corepack pnpm` で呼ぶため、ホストに pnpm を常設する必要はない
(corepack が `package.json` の `packageManager` を読んで解決する = 開発機と必ず同版)。

- `COREPACK_ENABLE_DOWNLOAD_PROMPT=0` を task 側で渡している。これが無いと corepack が
  未取得の pnpm を取る前に対話確認を求めて**固まる**。
- `pnpm install --frozen-lockfile` が lockfileVersion 非互換で落ちたら、**落ちるのが正しい**。
  ホストの corepack / Node を上げて `packageManager` に追従させる (lockfile を作り直さない)。
- CI 側は `pnpm/action-setup` に版を宣言せず `packageManager` を読ませている。
  版の SoT は `package.json` の 1 か所だけである (`mise.toml` の `"npm:pnpm"` はそれに追従する)。

---

## 2. `deploy_path` のレイアウト

Deployer の標準レイアウトである (パスは `deploy/hosts.yml` の `deploy_path` 配下)。

```
<deploy_path>/
├── current -> releases/N        # nginx の root が指す先 (current/public)
├── releases/
│   ├── 1/ ... N/               # keep_releases = 5 で古いものは cleanup が削除
│   └── N/
│       ├── .env    -> ../../shared/.env      (symlink)
│       ├── storage -> ../../shared/storage   (symlink)
│       ├── vendor/                            # composer install --no-dev
│       └── public/build/                      # サーバー上で vite build した成果物
├── shared/
│   ├── .env                    # ★秘密の正本。git に入れない。人手で配置する
│   └── storage/                # ログ / セッション / storage/app/public
└── .dep/                       # Deployer の作業領域 (lock / releases_log / composer.phar)
```

- **nginx の `root` は `<deploy_path>/current/public`** を指す。`current` は symlink なので、
  nginx / php-fpm の `realpath` キャッシュを捨てさせるために毎デプロイ php-fpm を reload する。
- `keep_releases = 5`。ディスクの小さい 1 台構成なので Deployer 既定 (10) より絞っている。
  **失敗した release も枠を消費する**ので、連続失敗の後はロールバック可能深度が浅くなる。
- `storage` が shared なので、`storage/logs` と `storage/app/public` は release を跨いで残る。
- `public/storage` (→ `storage/app/public`) は `artisan:storage:link` が各 release で張る。

---

## 3. `shared/.env` のキー一覧

**このファイルが staging の設定の唯一の正本**である。git には入らない。
雛形は `.env.example`。**実値 (秘密) は本書に書かない。**

> ⚠ **置き忘れると `.env.example` が「秘密の正本」に据わる**。Deployer は release に `.env` が
> 無ければ `.env.example` を複製し (`deploy:env`)、shared 側が空ならその実体を shared へ移す。
> これを止めるために `deploy:check_env` が `deploy:shared` より**前**で
> `shared/.env` の存在と非空を検査して fail する。初回はこれで止まるのが正常である。

### 3.1 必ず設定するもの

| キー | 意味 / 決め方 |
|---|---|
| `APP_NAME` | 表示名。`AI-CUE` 等 |
| `APP_ENV` | **`staging`**。`production` にすると `ProductionEnvGuard` の起動時 fail-fast が全項目で効くようになる (§8 で production 化するときに切り替える) |
| `APP_KEY` | `php artisan key:generate --show` で生成して貼る。**ローテートすると暗号化列が読めなくなる** |
| `APP_DEBUG` | **`false`**。true は stack trace と設定を露出する |
| `APP_URL` | 外部から見える URL。現状は IP 直打ちの http、ドメイン取得後は `https://aicue.jp` |
| `APP_LOCALE` | `ja` |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stack` / `info` 程度 (staging で `debug` は出力量が多い) |
| `DB_CONNECTION` | **`pgsql`** |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | 同一ホストの PostgreSQL 18。loopback / `5432` / 専用 DB / 専用ロール |
| `SESSION_DRIVER` | `database` |
| `SESSION_ENCRYPT` | `true` |
| `SESSION_SECURE_COOKIE` | **TLS 導入まで `false`、TLS 導入と同時に `true`**。`true` のまま http で開くとログインできない (Cookie が送られない)。production では `true` が必須 |
| `QUEUE_CONNECTION` | **`database`**。`sync` はテストと local 専用。`QueueDispatchAtomicityGuard` が全環境の起動時に driver を検査する。**ここを `sync` にすると worker が居ても何も処理されない** |
| `CACHE_STORE` | `database` |
| `FILESYSTEM_DISK` | `local` (既定のまま)。テイク / レンダ成果物は `s3` disk を**名指しで**使うのでここは変えなくてよい |
| `CIPHERSWEET_KEY` | PII (users.email / name) の暗号化キー。`php artisan ciphersweet:generate-key` で生成。**失うと既存の PII が復号できない** |
| `PRIMARY_HOST` | Host header injection 防御の allowlist。現状は IP、ドメイン取得後は `aicue.jp` |
| `TRUSTED_PROXIES` | **`none`**。前段プロキシが無い構成の明示宣言。理由と将来の変更条件は `docs/trusted-proxies-runbook.md` §3 |
| `PASSKEYS_USER_HANDLE_SECRET` | 32 文字以上のランダム値。**未宣言だと `APP_KEY` 由来になり、`APP_KEY` ローテートで登録済みパスキーが全件無効になる** |
| `SECURITY_HSTS_ENABLED` | TLS 導入までは `false` (http で HSTS を送っても意味が無い)。**TLS と同時に `true`**。production では `true` 必須 |
| `SECURITY_CSP_ENABLED` | `true` |
| `ADMIN_MFA_REQUIRED` | `true` (管理画面の TOTP 必須化) |
| `MAIL_MAILER` ほか `MAIL_*` | staging で実送信しないなら `log`。実送信するなら SES (`docs/ses-mail-runbook.md`) |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_DEFAULT_REGION` / `AWS_BUCKET` | テイク動画・レンダ成果物の S3。作り方は §9 |
| `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` / `GEMINI_API_KEY` | 使う provider のものだけ (§10.1) |
| `POSTGRES_PASSWORD` | **不要** (devcontainer の docker-compose 専用キー)。サーバーの `.env` には置かない |

### 3.2 空のままにしなければならないもの

| キー | 理由 |
|---|---|
| `DEBUG_LOGIN_USER` / `DEBUG_LOGIN_PASSWORD` | local 専用のデバッグログイン。残置は `ProductionEnvGuard` が違反として検出する |
| 偽の外部サービスのフラグ (`ExternalFakeDeclaration` が列挙) | 本番混入防止。`ProductionEnvGuard` が**設定値とプロセス環境変数の両方**を見る |

### 3.3 機能を使うときだけ設定するもの

| キー | 用途 |
|---|---|
| `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET` / `STRIPE_TAX_RATE_ID` / `STRIPE_PORTAL_CONFIGURATION_ID` | 課金。`STRIPE_WEBHOOK_SECRET` が空だと Cashier が**署名検証を黙ってスキップする** (production では起動時に拒否される) |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` | Google SSO |
| `RECAPTCHA_SITE_KEY` / `RECAPTCHA_SECRET_KEY` | reCAPTCHA (site_key 未設定なら captcha 無しで動く) |
| `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` | MCP の OAuth 2.1。鍵ファイルを commit しないため env で注入する |
| `MCP_ALLOWED_ORIGINS` | MCP エンドポイントの Origin allowlist (未設定 = 全拒否) |
| `RENDER_FFMPEG_BINARY` / `RENDER_FFPROBE_BINARY` / `RENDER_SUBTITLE_FONT` | 静的ビルド ffmpeg を PATH 外へ置いた場合のパス指定 / 字幕焼き込みフォント |
| `INQUIRY_RECIPIENT` / `LEGAL_CONSENT_VERSION` | 問い合わせ通知先 / 規約バージョン |
| `GTM_CONTAINER_ID` | Google Tag Manager (production かつ非空のときだけ描画される二重ゲート) |

`.env.example` の各行に日本語のコメントが付いているので、判断に迷ったらそちらを読むこと。

---

## 4. 開発機 (devcontainer) 側の前提

配布は**開発機から**実行する。このプロジェクトの PHP は devcontainer の中にしか無いため、
コマンドは常に devcontainer 経由になる (コンテナ名と作業ディレクトリの正本は
`docker-compose.yml`。以降は `<container>` と書く)。

| 前提 | 状態 |
|---|---|
| `vendor/bin/dep` | `deployer/deployer` は `require-dev`。`composer install` で入る (`--no-dev` の本番 host には載らない) |
| `deploy/hosts.yml` | `cp deploy/hosts.example.yml deploy/hosts.yml` して `<...>` を実値で埋める。**gitignore** |
| SSH 秘密鍵 | `docker-compose.yml` がホストの `~/.ssh/aicue_deploy` をコンテナの同名パスへ**読み取り専用**でマウントする。`deploy/hosts.yml` の `identity_file` を `~/` からの相対で書いてこれと一致させる |
| `~/.ssh/config` | devcontainer には無い。だから `deploy/hosts.yml` は hostname / user / 鍵をすべて明示する |

> ⚠ **鍵は passphrase 無しのデプロイ専用鍵**である。コンテナ内には keychain も ssh-agent も
> 無いため、passphrase 付きの鍵では非対話の配布が通らない。公開鍵はサーバーの
> `remote_user` の `authorized_keys` に登録済み。
>
> ⚠ **マウントは次回のコンテナ作成以降に効く。** 既に動いているコンテナへは
> `docker cp` で置き、`chmod 600` / owner をコンテナのユーザーに合わせれば今すぐ使える。

---

## 5. 初回デプロイ手順

配布が通る前に**サーバー側で一度だけ**必要な作業がある。

1. **`deploy/hosts.yml` を作る** — `deploy/hosts.example.yml` を複製して `<...>` を実値で埋める。
   `<...>` が 1 つでも残っていると `scripts/deploy.sh` が fail-fast する。
   `bin/php` は**必ず実測して**書く (`command -v php` の結果)。

2. **php-fpm プールの実行ユーザーを確認する** — `deploy/deploy.php` は
   `writable_mode = 'chmod'` (単一ユーザー運用) を前提にしている。
   プールのユーザーが `remote_user` と違う場合は、その host で `writable_mode: chgrp` と
   `http_group` を宣言する必要がある。

3. **sudoers に NOPASSWD を足す** — `deploy:restart` は次の 2 つを `sudo` で叩く。

   ```
   systemctl reload php-fpm
   systemctl restart aicue-queue-default aicue-queue-analysis aicue-queue-render aicue-queue-media
   ```

   パスワードを聞かれる状態だと非対話の配布が止まる。`remote_user` に対して
   この 2 つだけを NOPASSWD で許可するのが最小権限である (`ALL=(ALL) NOPASSWD: ALL` にしない)。

4. **GitHub の deploy key を登録する** — `deploy:update_code` は**サーバー上で** clone する。
   サーバー上で ed25519 鍵を作り、公開鍵を `kent013/aicue` の Settings → Deploy keys へ
   **read-only** で登録する。あわせてサーバーから `git@github.com` への疎通と
   host key の受け入れを済ませておく (初回の未知 host key で配布が止まらないようにする)。

5. **DB とロールを作る** — PostgreSQL 18 は同一ホストにある。専用ロールと専用 DB を作り、
   パスワードを `shared/.env` の `DB_PASSWORD` に入れる。

6. **`shared/.env` を置く** (§3)。`deploy_path` が無ければ先に作り、owner を `remote_user` にする。
   置いたら `chmod 600`。**ここを飛ばすと `deploy:check_env` で止まる** (それが正しい)。

7. **S3 と LLM キーを用意する** (§9 / §10.1)。テイク撮影とシナリオ生成はこれが無いと動かない。

8. **前提チェックだけを回す** (配布しない)。

   ```
   docker exec -w /workspace <container> bash scripts/deploy.sh <host> --check
   ```

9. **配布する** (§6)。

10. **nginx を staging へ向ける** — `root <deploy_path>/current/public;` と
    php-fpm socket への `fastcgi_pass`。初回配布で `current` が出来てから reload する。

11. **常駐プロセスを start する** (プロビジョニングでは enable のみ) — queue worker 4 本と
    scheduler timer (§12)。

12. **管理者と CLI OAuth client を発行する** (§10.2 / §10.3)。

---

## 6. 通常デプロイ手順

**配布の入口はこの 1 つだけである。**

```
docker exec -w /workspace <container> bash scripts/deploy.sh <host>
```

| オプション | 意味 |
|---|---|
| `--check` | 前提チェックだけを回して終了する (配布しない) |
| `--allow-dirty` | working tree が dirty でも続行する (配布物と無関係な untracked があるときだけ) |
| `--production` | 本番 host への配布であることの**人間の意思表示**。TTY + 算術確認ゲートを通る |

`scripts/deploy.sh` がやること (この順):

1. **座標の fail-fast 5 点** — `deploy/hosts.yml` の存在 / placeholder 残存 /
   `deploy/deploy.php` の `application` と `repository` / `vendor/bin/dep` の存在 /
   **host が解決できること** (`dep deploy --plan <host>` に判定させる。SSH しない)
2. **git 前提** — working tree が clean / ブランチが `main` / `origin/main` が先行していない
3. **本番の人間ゲート** — `--production` のときだけ。非 TTY は無条件拒否 + 算術チャレンジ
4. **stage と意思表示の整合** — `deploy:confirm-stage` を **push より前**に回す
5. **`git push origin main`** — Deployer は**リモートから clone** するため、push しないと
   古いコードが配られる
6. **配布** — `dep -f deploy/deploy.php deploy <host>`

> **直叩きは使わない。** `vendor/bin/dep deploy <host>` を直接叩いても
> `deploy:confirm-stage` が本番 host を fail-closed で止めるが、座標 fail-fast と push が
> 抜けるので「ローカルで見ているものと違うコードが配られる」。

### 6.1 `deploy` タスクの構成

正本は `deploy/deploy.php` で、次で確認できる (SSH しない)。

```
docker exec -w /workspace <container> vendor/bin/dep -f deploy/deploy.php tree deploy
docker exec -w /workspace <container> vendor/bin/dep -f deploy/deploy.php deploy --plan <host>
```

実測 (Deployer 8 / laravel recipe):

| # | タスク | すること |
|---|---|---|
| 1 | `deploy:confirm-stage` | 本番ゲート。**何かが動く前に**止まる (SSH もしない) |
| 2 | `deploy:info` 〜 `deploy:update_code` | 対象表示 / ディレクトリ準備 / 排他 lock / release 確保 / サーバー上で clone |
| 3 | `deploy:env` | recipe 既定。release に `.env` が無ければ `.env.example` を複製する (直後の `deploy:shared` が置き換えるので実質 no-op) |
| 4 | `deploy:check_env` | **`shared/.env` が空でないことを確認** (無ければここで止める) |
| 5 | `deploy:shared` | `.env` / `storage` を shared へ symlink |
| 6 | `deploy:writable` | `bootstrap/cache` と `storage` 配下を書き込み可能にする |
| 7 | `deploy:vendors` | `composer install --no-dev --optimize-autoloader` (recipe 既定を変更していない) |
| 8 | `build:frontend` | `corepack pnpm install --frozen-lockfile` → `build:packages` → アプリ資産の build |
| 9 | `artisan:storage:link` | `public/storage` → `storage/app/public` |
| 10 | `artisan:optimize` | 起動キャッシュの生成 (config / event / 経路 / view を一括) |
| 11 | `deploy:verify` | `production:preflight` (staging では production 専用検査を skip して通る) |
| 12 | `artisan:migrate` | `migrate --force`。`->once()` + `roles=db` で **1 host のみ** |
| 13 | `deploy:symlink` | `current` を新 release へ切り替え (原子的) |
| 14 | `deploy:unlock` / `deploy:cleanup` / `deploy:success` | lock 解除 / 古い release の削除 / 完了表示 |
| 15 | `artisan:reload` | recipe 既定 (`queue:restart` + `schedule:interrupt`)。次項と役割が重なるが害はない |
| 16 | `deploy:restart` | php-fpm を **reload** → systemd の worker 4 本を **restart** |

補足:

- **起動キャッシュの生成が composer install の後にある理由**: `composer install` の
  `post-autoload-dump` が `filament:upgrade` を走らせ、その中で config / view のキャッシュが
  clear される。前に置くと消える。
- **経路キャッシュは毎デプロイ再生成される** (`artisan:optimize` に含まれる)。
  起動後に経路へ後付けする機構がある場合、キャッシュされた起動でそれが効くことは
  アプリ側の責務である (`docs/app-integration-guide.md` / `AGENTS.md` の運用要件を参照)。
- **`production:preflight` の `--strict` は stage から導出される**。`--strict` は `APP_ENV` が
  `production` でないと fail するので、`stage: dev` の host では付かない
  (「production 専用検査を skip した」warning が出て通る)。`stage: production` を宣言した
  host では**必ず付く**ので、設定漏れがそこで止まる。
- **migrate が symlink 切替の前にある**ので、`current` が切り替わった瞬間には schema が既に
  新しい。逆に**旧コードが新 schema を読む窓**が存在する。列を落とす等のローリング非互換な
  migration はメンテナンスモード (`dep artisan:down` → 配布 → `dep artisan:up`) を挟むこと。

---

## 7. ロールバック手順

```
docker exec -w /workspace <container> vendor/bin/dep -f deploy/deploy.php rollback <host>
```

- Deployer が 1 つ前の release へ `current` を張り替え、現 release に `BAD_RELEASE` を置く。
- `deploy:restart` は **rollback にも配線してある**ので、php-fpm の reload と worker 4 本の
  restart も自動で走る (これが無いと「コードは戻ったが worker は新コードのまま」になる)。
- 戻り先を指定するなら `-o rollback_candidate=<release 番号>`。
- `after('rollback', ...)` に**他のタスクを安易に足さない**。release 非依存の副作用
  (DB 行の作り直しなど) を巻き戻すと、release と無関係な利用者に影響が出る。

> ⚠ **`rollback` は DB migration を戻さない。** migration は forward-only 規約 (`AGENTS.md`) なので、
> migrate 後の rollback は「新スキーマに旧コード」を作る。機械 gate を書けないため人間が判断する
> (`docs/billing-retention-runbook.md` のように down で値が復元されない migration がある)。
>
> ⚠ **release が 1 本しか無い状態では rollback できない** (戻り先が無い)。
> 連続失敗で枠を食っている場合も同様に浅くなる (`keep_releases = 5`)。

**反射的に rollback を打つのが最悪手**である。止まった位置ごとの一次対処は
`.claude/skills/app-deploy/SKILL.md` の Phase 6 の表が正本。

---

## 8. `aicue.jp` 取得後の HTTPS 切替手順

hop が増えない前提 (同一ホストの nginx で TLS 終端する) なら `TRUSTED_PROXIES` は `none` のまま。
**CDN / LB を前段に置く場合は `docs/trusted-proxies-runbook.md` §3 を先に書き換えること。**

1. **DNS** — `aicue.jp` の A レコードを `deploy/hosts.yml` の `hostname` へ向ける。
2. **nginx** — `server_name aicue.jp;` に変更して reload (まだ http)。
3. **証明書** — certbot で取得し、自動更新の timer が有効になっていることを確認する。
4. **`shared/.env` を書き換える** (この 4 つは**同時に**変える)。

   | キー | 変更後 |
   |---|---|
   | `APP_URL` | `https://aicue.jp` |
   | `PRIMARY_HOST` | `aicue.jp` |
   | `SESSION_SECURE_COOKIE` | `true` |
   | `SECURITY_HSTS_ENABLED` | `true` |

5. **再配布する** — `.env` の変更は焼いた設定キャッシュに反映されない。
   単発でキャッシュだけ焼き直す運用は取らず、**`scripts/deploy.sh <host>` をもう 1 回回す**
   (release ごとに焼き直す形に揃えているので、これが唯一の反映手段である)。
6. **S3 の CORS `AllowedOrigins` を新オリジンへ差し替える** (§9.2)。
7. **パスキーの RP ID が変わる影響を確認する** — ここが最も壊れやすい。

   - RP ID は未宣言なら `APP_URL` の host から導出される。つまり
     **`APP_URL` を IP からドメインへ変えると RP ID が変わり、
     それまでに登録されたパスキーは全件使えなくなる**
     (WebAuthn の資格情報は RP ID に紐付くため、後から移せない)。
   - staging で撮影 PWA の動作確認にパスキーを使っていた場合は、切替後に**登録し直す**。
     利用者から見ると「昨日まで通った生体認証が通らない」という症状になる。
   - `PASSKEYS_USER_HANDLE_SECRET` は RP ID とは独立なので、切替では触らない
     (触ると別の理由で全件無効になる)。
   - 別ホストから撮影 PWA を配信するなら `PASSKEYS_RELYING_PARTY_ID` /
     `PASSKEYS_ALLOWED_ORIGINS` を明示する。詳細は `docs/auth-security-mechanisms.md` §5。

8. **`APP_ENV` を `production` にするなら**、`ProductionEnvGuard` の必須項目が**すべて**
   揃っていることを先に確認する (`APP_KEY` / `CIPHERSWEET_KEY` / `STRIPE_WEBHOOK_SECRET` /
   `SESSION_SECURE_COOKIE=true` / `APP_DEBUG=false` / HSTS / CSP / `TRUSTED_PROXIES` /
   パスキー設定 / 偽の外部サービスのフラグが空)。1 つでも欠けると**起動しない**。
   あわせて `deploy/hosts.yml` の当該 host に `stage: production` を宣言する
   (これで `--strict` が付き、`--production` + TTY + 算術ゲートが必須になる)。

---

## 9. S3 バケットの用意

テイク動画 (撮影) とレンダ成果物は `s3` disk (`config/filesystems.php`) を名指しで使う。
ブラウザから **presigned PUT** で直接アップロードするため、CORS の設定が要る。

### 9.1 バケット作成

- リージョンはサーバーと同じにする (`AWS_DEFAULT_REGION` と一致させる)。
- **パブリックアクセスはすべてブロック**する。配信は署名付き URL (`temporaryUrl`) で行う。
- 未完了アップロードのゴミを掃除するため、マルチパートの中断を破棄するライフサイクルルールを置く。

### 9.2 CORS 設定 (presigned PUT が通るための必須項目)

presigned PUT の署名には **`ChecksumSHA256` が含まれている**
(`app/Services/Capture/TakeObjectStorage::presignUpload()`)。ブラウザは
`x-amz-checksum-sha256` ヘッダを**必ず送る**ため、`AllowedHeaders` にこれが無いと
preflight (OPTIONS) の段階で失敗する。これが一番よく踏む落とし穴である。

```json
[
  {
    "AllowedOrigins": ["https://aicue.jp"],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["content-type", "x-amz-checksum-sha256"],
    "ExposeHeaders": ["ETag", "x-amz-checksum-sha256"],
    "MaxAgeSeconds": 3000
  }
]
```

- `AllowedOrigins` は**アプリのオリジンだけ**にする (`*` にしない)。
  ドメイン取得前に staging で試すなら現行の IP 直打ちオリジンを入れ、切替時に差し替える (§8-6)。
- S3 は本文がチェックサムと一致しない PUT を拒否する。したがってこの署名付き URL で置ける
  内容は申告ハッシュの 1 通りに固定される (登録後の再 PUT 差し替え防止)。
  CORS を緩めてもこの保証は変わらないが、緩めると別オリジンから署名を使い回せる余地が増える。

### 9.3 最小権限の IAM ポリシー例

アプリが使う操作は PutObject / GetObject / HeadObject / DeleteObject
(+ ストリーム書き込みが使うマルチパート) である。

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ObjectRW",
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:GetObjectAttributes",
        "s3:PutObject",
        "s3:DeleteObject",
        "s3:AbortMultipartUpload"
      ],
      "Resource": "arn:aws:s3:::<BUCKET>/*"
    },
    {
      "Sid": "BucketList",
      "Effect": "Allow",
      "Action": ["s3:ListBucket", "s3:ListBucketMultipartUploads"],
      "Resource": "arn:aws:s3:::<BUCKET>"
    }
  ]
}
```

- `s3:PutBucketPolicy` や `s3:DeleteBucket` は**渡さない**。
- IAM ロールを使えるなら、アクセスキーを `.env` に置かずインスタンスプロファイルにする方が安全
  (その場合 `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` は空にして SDK の既定解決に任せる)。

### 9.4 `.env`

| キー | 値 |
|---|---|
| `AWS_ACCESS_KEY_ID` | 上記ポリシーを付けた IAM ユーザーのキー (ロール利用時は空) |
| `AWS_SECRET_ACCESS_KEY` | 同 (ロール利用時は空) |
| `AWS_DEFAULT_REGION` | バケットのリージョン |
| `AWS_BUCKET` | バケット名 |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` (S3 互換ストレージを使うときだけ `true`) |

---

## 10. アプリ側の初期投入

配布パイプラインには載せていない (release 非依存の副作用なので、rollback で巻き戻ると
困るものは deploy に入れない)。**人手で 1 回だけ**行う。

### 10.1 LLM API キー

シナリオ生成は Prism 経由で LLM を呼ぶ (`config/prism.php`)。**使う provider のキーだけ**を
`shared/.env` に置く。

| キー | provider |
|---|---|
| `OPENAI_API_KEY` | OpenAI |
| `ANTHROPIC_API_KEY` | Anthropic |
| `GEMINI_API_KEY` | Google Gemini |

- キーを置いたら**再配布する** (§8-5 と同じ理由。焼いた設定キャッシュには反映されない)。
- **キーは組織ごとの費用計上に影響しない** (帰属は `LlmCallContextData` がアプリ側で付ける)。
- キーが空のまま生成を叩くと provider 側の認証エラーでジョブが failed になる。

### 10.2 管理者の発行

サーバー上の `current` で `php artisan admin:create` を実行する。

- 対話で email / name / password を聞く (`--email=` / `--name=` / `--password=` でも渡せるが、
  シェル履歴にパスワードが残るので**対話を推奨**)。
- `ADMIN_MFA_REQUIRED=true` なので、初回ログイン時に TOTP の登録を求められる。
  端末を失った場合の復旧は
  `php artisan admin:reset-mfa <AdminUser の id> --reason="<10 文字以上の理由>"`
  (`ResetAdminMfaCommand`。理由は監査証跡に残るので必須)。
- 本番相当の環境で `AdminUserSeeder` は使わない (local 専用)。

### 10.3 CLI OAuth client の発行

サーバー上の `current` で `php artisan cli:client` を実行する (冪等。既存があれば再利用)。

- client id は秘密ではなく `/api/v1/version` の `cli_oauth_client_id` で公開される。
- **配布パイプラインには入れていない**。家系正典は deploy で冪等発行する task を持つが、
  その有効化の前提 (client_kind の部分 unique index / 「ちょうど 1 件」の厳密判定 /
  status・rotate の復旧手段) を aicue はまだ満たさない。前提を満たしたら
  `tests/Architecture/DeployPipelineWiringTest.php` の `DEPLOY_TASK_OMITTED` から行を消して
  band を登録する (不在は台帳で申告済み)。

---

## 11. 既知の未対応事項

先回りして作らない代わりに、ここに列挙して見えるようにしておく。

| 項目 | 現状 |
|---|---|
| **インフラのコード化 (Terraform)** | **未取り込み**。Lightsail / nginx / php-fpm / systemd unit / DNS はすべて**手で作った**。家系には `deploy-terraform` feature (`infra/terraform` + `.env.production-template` + 契約テスト) があるが、既に手で作った環境を後から取り込む作業が要るため今回は入れていない。起票は `docs/TODO.md` の T267 (Conditional)。座標 hygiene gate の走査根にも `infra` / `.env.production-template` を `required=false` + 反転条件付きで残してある (「不在の申告」) |
| production 環境 | **無い**。現サーバーは `APP_ENV=staging` の 1 台のみで、`stage: production` の host 定義もまだ無い |
| ドメイン / TLS | `aicue.jp` 未取得、TLS 未導入 (§8) |
| CI からの自動デプロイ | **入れていない**。`.github/workflows` にデプロイ job は無い。配布は開発機から人が叩く。入れる PR は本番ゲート (TTY 要求) と衝突するので、非本番 host に限る設計が必要 |
| DB バックアップ | **未設定**。PostgreSQL は同一ホストにあり、インスタンスが失われるとデータも失われる。`pg_dump` の定期取得と保管先が要る |
| S3 のライフサイクル / 保持期限 | 未設定。テイク動画は容量が大きいので、保持方針を決めるまで課金が読めない |
| 監視 / アラート | 無し。§12 の監視対象はどれも人が見に行く形である |
| worker のメモリ制限 | 無し (cgroup 制限を置いていない)。ffmpeg の `-max_alloc` は 1 回の heap 確保の上限で、プロセス全体の RSS 上限ではない (`app/Support/Media/FfmpegSafetyArguments.php`) |
| systemd unit の `--timeout` の機械検査 | 無し。リポジトリ外にあるため CI では検知できない (§1.2) |
| メンテナンスモードの自動化 | `deploy` に組み込んでいない。ローリング非互換な migration のときだけ `dep artisan:down` / `dep artisan:up` を人手で挟む |
| Stripe / SES / reCAPTCHA / MCP | staging では未設定でも起動する。使うときに §3.3 のキーを入れる |

---

## 12. 常駐プロセスの確認 (人手で行う運用確認)

**配布が緑でも常駐が欠けていれば何も処理されない**。出力からは判定できない静かな障害なので、
初回配布後と worker unit を触った後は人が確認する。

- queue worker 4 本が `active (running)` であること (§1.2 の unit 一覧)。
  `QUEUE_CONNECTION=database` なので、worker が居ないとジョブは `jobs` テーブルに溜まり続ける。
- `aicue-scheduler.timer` が有効で、毎分 `schedule:run` を起動していること。
  **これが止まると下表のすべてが動かない。**

### 12.1 scheduler が止まると動かないコマンド

`routes/console.php` が正本。ここは「cron が止まると何が動かなくなるか」の可視化である
(`tests/Architecture/DeployPipelineWiringTest.php` W30 が本表と `routes/console.php` の
突合を機械検査する)。

| コマンド | 間隔 | 止まると起きること |
|---|---|---|
| `work:recover-stuck --stream=` (系列ごとに 1 本) | 系列ごと (`RecoveryStream::cadenceMinutes`) | 滞留した仕事が前へ進まない。回収の唯一の経路 |
| `billing:reconcile-auto-recharge` | 15 分 | 「課金済み・チケット未付与」が滞留する (webhook が恒久 drop した分を回収する唯一の経路) |
| `render:reconcile-outputs` | 5 分 | 世代交代済みのレンダ出力が S3 に残り続ける |
| `billing:send-billing-reminders` | 日次 | 更新予告 (renewal 3 日前) が飛ばない |
| `billing:reconcile-schedules` | 日次 | Subscription Schedule の部分完了 / local-remote 差分が復旧しない |
| `billing:reconcile-subscription-status` | 日次 | webhook 欠落でローカルの契約状態が固まったままになる (支払い失敗の遮断も復旧も起きない) |
| `billing:detect-orphan-billing-organizations` | 日次 | Owner 不在かつ課金中の組織 (課金孤児) が検知されない |
| `billing:purge-retention-expired --apply` | 日次 | 課金記録の保持期限 (7 年) を超えた行が決着しない (規約違反の状態が続く) |
| `inquiry:purge --apply` | 日次 | 問い合わせの保持期限超過分が削除されない |
| `account:purge-deletion-requests --apply` | 日次 | 退会予約 (猶予期間つき削除) が執行されない |
| `capture:purge-upload-reservations` | 日次 | 撮影アップロード予約の古い行が溜まり続ける |
| `idempotency:prune` | 日次 | 冪等キーが単調増加する |
| `enterprise-sso:prune-login-attempts` | 日次 | 期限切れの SSO ログイン試行が溜まり続ける |
| `auth:prune-email-promotions` | 日次 | 期限切れのメール昇格確認待ちが残り、その利用者は**二度と昇格を始められない** |

各コマンドの監視対象 (出力のどこを見るか) は `routes/console.php` のコメントが正本。

### 12.2 配布出力から判定できること

- `deploy:verify` が通ったこと。
- `deploy:restart` が php-fpm reload と worker restart を**両方**実行したこと。
  `queue_worker_restart_enabled=false` の skip 表示が出ていたら、配布が緑でも
  **worker は旧コードのまま動き続けている**。

---

## 関連

- `deploy/deploy.php` — デプロイ定義そのもの (タスク構成と既定値の正本)
- `deploy/hosts.example.yml` — host 座標の雛形 (実座標は `deploy/hosts.yml`)
- `scripts/deploy.sh` — 配布の単一入口
- `.claude/skills/app-deploy/SKILL.md` — agent 向けの配布規約 (失敗位置ごとの一次対処表)
- `tests/Architecture/DeployPipelineWiringTest.php` — 配線位置の台帳 (band inventory)
- `tests/Architecture/DeployCoordinateHygieneTest.php` — 追跡下に実座標を置かない検査
- `AGENTS.md` — デプロイ基盤にかかる規約
- `docs/trusted-proxies-runbook.md` — client IP の信頼境界 (§3 が実インフラの記入欄)
- `docs/architecture.md` §キューのリース期間とワーカー制限時間の規約 — worker `--timeout` の正本
- `docs/ses-mail-runbook.md` — 実メール送信を有効にするとき
- `docs/rollout-checklists.md` — 機能を段階的に有効化するときの手順
