# デプロイ runbook (Deployer / 開発-staging サーバー)

本書は **AI-CUE の実サーバーへのデプロイ手順の正本**である。
デプロイ定義そのものはリポジトリルートの **`deploy.php` 1 枚**(deployphp/deployer 8.x)にあり、
本書はそれを「どのサーバーへ、どういう前提で、どの順に使うか」を書く。

> **経路キャッシュを打たない契約**: `deploy.php` が焼くのは **config / event / view の 3 つだけ**で、
> routing の cache は**生成しない**。このアプリは vendor route への middleware を
> `RouteThrottleBinder` / `RouteMiddlewareBinder` が起動後に後付けしており、
> 経路キャッシュを焼いた起動では throttle / recent-auth / ensure-login-method / no-store が
> **1 本も効かない**。手で焼いてもいけない。機序は `docs/app-integration-guide.md` §7c、
> 運用要件は `AGENTS.md` 「運用要件 (route:cache)」、逸脱の登録は
> `docs/template-divergence.md` **D19**。

---

## 1. サーバー構成

**1 台構成の開発 / staging サーバー**である。production 環境はまだ無い。

| 項目 | 値 |
|---|---|
| 用途 | 開発 / staging (`APP_ENV=staging`) |
| ホスティング | AWS Lightsail (1 インスタンス) |
| hostname (IP) | `13.192.189.252` |
| ドメイン | **未取得** (`aicue.jp` を予定。§8 で切替) |
| TLS | **未導入** (ドメイン取得後に certbot。§8) |
| SSH ユーザー | `ec2-user` |
| deploy_path | `/var/www/aicue` |
| Deployer の host alias | `aicue` (`deploy.php` の `host('aicue')`) |

### 1.1 インストール済みソフトウェア

サーバー側のプロビジョニングは**別途行われている**。本書はその結果を前提とする。

| ソフト | 想定 | 備考 |
|---|---|---|
| nginx | 前段の HTTP サーバー | php-fpm と**同一ホスト**。UNIX socket で接続するため proxy hop は増えない (`docs/trusted-proxies-runbook.md` §3.1) |
| php-fpm | 8.4 / socket `/run/php-fpm/aicue.sock` | pool は aicue 専用 |
| php CLI | `/usr/bin/php8.4` (想定) | `deploy.php` の `bin/php`。**実測値と食い違ったら `deploy.php` を直す** |
| PostgreSQL | 18 / **同一ホスト (localhost)** | 外部 RDS ではない。バックアップは §11 の未対応事項 |
| Node.js | 22 | フロントを**サーバー上でビルド**するため必要 |
| pnpm | 11.9.0 (corepack 経由) | `package.json` の `packageManager` が正本。`deploy.php` は `corepack pnpm` で呼ぶ |
| ffmpeg / ffprobe | 静的ビルド | レンダ / サムネイル生成に必須。`RENDER_FFMPEG_BINARY` / `RENDER_FFPROBE_BINARY` で明示できる |
| git | update_code がサーバー上で `git` を使う | GitHub への deploy key が必要 (§5) |
| composer | 任意 | 無ければ Deployer が `{{deploy_path}}/.dep/composer.phar` へ自動導入する |

> **OS / インスタンスサイズ / ディスク容量は本書では未記入**である (プロビジョニング担当が確定させる)。
> `ec2-user` と `/run/php-fpm` の配置から Amazon Linux 系と推測しているが、**推測を確定として書かない**。
> 初回デプロイ時に `ssh aicue 'cat /etc/os-release; nproc; free -m; df -h /'` の結果でここを埋めること。
> フロントビルド (vite) と ffmpeg レンダはどちらもメモリを食うため、サイズの実測は重要である。

### 1.2 systemd unit

プロビジョニングで **enable 済み・未 start** の状態で用意される。

| unit | 役割 | デプロイ時の扱い |
|---|---|---|
| `aicue-queue-default.service` | `database` 接続のワーカー | 毎デプロイ **restart** |
| `aicue-queue-analysis.service` | `database-analysis` 接続のワーカー | 毎デプロイ **restart** |
| `aicue-queue-render.service` | `database-render` 接続のワーカー | 毎デプロイ **restart** |
| `aicue-queue-media.service` | `database-media` 接続のワーカー | 毎デプロイ **restart** |
| `aicue-scheduler.timer` | `schedule:run` の定期起動 | **触らない** (起動ごとに新プロセスなので次回起動から新コードになる) |
| `php-fpm.service` | FPM | 毎デプロイ **reload** (graceful) |

ワーカーは reload では新しいコードを読まないため **restart** である。
4 本を 1 コマンドでまとめて再起動するのは、「1 本だけ古いコードのまま残る」状態を作らないため。

#### ワーカーの `--timeout`

**値の正本は `docs/architecture.md` §キューのリース期間とワーカー制限時間の規約 の値表**である。
本書にも `deploy.php` にも値を**転記しない** — 2 か所に置くと必ず食い違い、
リース切れによるジョブの二重実行を生む。

- 規則 1 (無条件): ワーカーの `--timeout` は、その接続の `retry_after` を**下回る**。
- systemd unit の `ExecStart` に書く `--timeout` は、上記値表の「ワーカー `--timeout`」列と一致させる。
- 値を変えるときは **値表 → systemd unit** の順に直す。`deploy.php` は unit を restart するだけで
  `--timeout` を持たないため、デプロイ側の変更は不要である。
- `config/queue.php` の `retry_after` を変える PR では
  `tests/Architecture/QueueWorkerLeaseInvariantTest.php` が dev 側 (`mprocs.yaml`) を検査するが、
  **サーバーの systemd unit はリポジトリ外なので CI は検知しない**。人手で揃えること。

---

## 2. `/var/www/aicue` のレイアウト

Deployer の標準レイアウトである。

```
/var/www/aicue/
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

- **nginx の `root` は `/var/www/aicue/current/public`** を指す。`current` は symlink なので、
  nginx / php-fpm の `realpath` キャッシュを捨てさせるために毎デプロイ php-fpm を reload する。
- `keep_releases = 5`。ディスクの小さい 1 台構成なので既定 (10) より絞っている。
- `storage` が shared なので、`storage/logs` と `storage/app/public` は release を跨いで残る。
- `public/storage` (→ `storage/app/public`) は `artisan storage:link` が各 release で張る。

---

## 3. `shared/.env` のキー一覧

**このファイルが staging の設定の唯一の正本**である。git には入らず、`deploy.php` は
`deploy:check_env` で「存在して空でないこと」だけを検査する (無ければデプロイを止める)。
雛形は `.env.example`。**実値 (秘密) は本書に書かない。**

### 3.1 必ず設定するもの

| キー | 意味 / 決め方 |
|---|---|
| `APP_NAME` | 表示名。`AI-CUE` 等 |
| `APP_ENV` | **`staging`**。`production` にすると `ProductionEnvGuard` の起動時 fail-fast が全項目で効くようになる (§8 で production 化するときに切り替える) |
| `APP_KEY` | `php artisan key:generate --show` で生成して貼る。**ローテートすると暗号化列が読めなくなる** |
| `APP_DEBUG` | **`false`**。true は stack trace と設定を露出する |
| `APP_URL` | 外部から見える URL。現状は `http://13.192.189.252`、ドメイン取得後は `https://aicue.jp` |
| `APP_LOCALE` | `ja` |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stack` / `info` 程度 (staging で `debug` は出力量が多い) |
| `DB_CONNECTION` | **`pgsql`** |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | 同一ホストの PostgreSQL 18。`127.0.0.1` / `5432` / 専用 DB / 専用ロール |
| `SESSION_DRIVER` | `database` |
| `SESSION_ENCRYPT` | `true` |
| `SESSION_SECURE_COOKIE` | **TLS 導入まで `false`、TLS 導入と同時に `true`**。`true` のまま http で開くとログインできない (Cookie が送られない)。production では `true` が必須 |
| `QUEUE_CONNECTION` | **`database`**。`sync` はテストと local 専用。`QueueDispatchAtomicityGuard` が全環境の起動時に driver を検査する |
| `CACHE_STORE` | `database` |
| `FILESYSTEM_DISK` | `local` (既定のまま)。テイク / レンダ成果物は `s3` disk を**名指しで**使うのでここは変えなくてよい |
| `CIPHERSWEET_KEY` | PII (users.email / name) の暗号化キー。`php artisan ciphersweet:generate-key` で生成。**失うと既存の PII が復号できない** |
| `PRIMARY_HOST` | Host header injection 防御の allowlist。現状は IP、ドメイン取得後は `aicue.jp` |
| `TRUSTED_PROXIES` | **`none`**。前段プロキシが無い構成の明示宣言。理由と将来の変更条件は `docs/trusted-proxies-runbook.md` §3 |
| `PASSKEYS_USER_HANDLE_SECRET` | 32 文字以上のランダム値 (`php -r "echo bin2hex(random_bytes(32));"`)。**未宣言だと `APP_KEY` 由来になり、`APP_KEY` ローテートで登録済みパスキーが全件無効になる** |
| `SECURITY_HSTS_ENABLED` | TLS 導入までは `false` (http で HSTS を送っても意味が無い)。**TLS と同時に `true`**。production では `true` 必須 |
| `SECURITY_CSP_ENABLED` | `true` |
| `ADMIN_MFA_REQUIRED` | `true` (管理画面の TOTP 必須化) |
| `MAIL_MAILER` ほか `MAIL_*` | staging で実送信しないなら `log`。実送信するなら SES (`docs/ses-mail-runbook.md`) |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_DEFAULT_REGION` / `AWS_BUCKET` | テイク動画・レンダ成果物の S3。作り方は §9 |
| `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` / `GEMINI_API_KEY` | 使う provider のものだけ (§10) |
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
| `RENDER_FFMPEG_BINARY` / `RENDER_FFPROBE_BINARY` / `RENDER_SUBTITLE_FONT` | 静的ビルド ffmpeg をパス指定する場合 / 字幕焼き込みフォント |
| `INQUIRY_RECIPIENT` / `LEGAL_CONSENT_VERSION` | 問い合わせ通知先 / 規約バージョン |
| `GTM_CONTAINER_ID` | Google Tag Manager (production かつ非空のときだけ描画される二重ゲート) |

`.env.example` の各行に日本語のコメントが付いているので、判断に迷ったらそちらを読むこと。

---

## 4. 開発機 (devcontainer) 側の前提

Deployer は**開発機から**実行する。この Laravel プロジェクトの PHP は devcontainer の中にしか
無いため、コマンドは常に `docker exec -w /workspace aicue …` 経由になる。

| 前提 | 状態 |
|---|---|
| `vendor/bin/dep` | `composer require --dev deployer/deployer` で導入済み |
| SSH 秘密鍵 | `docker-compose.yml` の `app` サービスがホストの `~/.ssh/github` を `/home/vscode/.ssh/aicue_deploy` へ **読み取り専用**でマウントする。`deploy.php` の `identity_file` がこのパスを指す |
| `~/.ssh/config` | devcontainer には無い。だから `deploy.php` は hostname / user / 鍵を**すべて明示**している |

> ⚠ **マウントは次回のコンテナ作成以降に効く。** 既に動いているコンテナへは
> `docker cp ~/.ssh/github aicue:/home/vscode/.ssh/aicue_deploy` (+ `.pub` も同様) で置き、
> `chmod 600` / owner を `vscode` にすれば今すぐ使える。
>
> ⚠ **鍵にパスフレーズが付いている場合、非対話の `dep deploy` は通らない。**
> ssh-agent をコンテナへ転送するか、デプロイ専用のパスフレーズ無し鍵を用意すること。

---

## 5. 初回デプロイ手順

`dep deploy` が通る前に**サーバー側で一度だけ**必要な作業がある。

1. **サーバーの実パスを `deploy.php` に反映する**

   ```
   ssh aicue 'command -v php8.4 php; php -v; id; systemctl is-enabled php-fpm'
   ```

   `bin/php` が `/usr/bin/php8.4` でなければ `deploy.php` の該当行を直す
   (該当箇所にその旨のコメントを置いてある)。

2. **php-fpm pool の実行ユーザーを確認する**

   ```
   ssh aicue 'ps axo user,comm | grep php-fpm'
   ```

   `deploy.php` は `writable_mode = 'chmod'` (単一ユーザー運用) を前提にしている。
   pool のユーザーが `ec2-user` と違う場合は `chgrp` + `http_group` へ切り替える必要がある。

3. **sudoers に NOPASSWD を足す** — `deploy.php` は次の 2 つを `sudo` で叩く。

   ```
   systemctl reload php-fpm
   systemctl restart aicue-queue-default aicue-queue-analysis aicue-queue-render aicue-queue-media
   ```

   パスワードを聞かれる状態だと非対話デプロイが止まる。`ec2-user` に対して
   この 2 コマンドだけを NOPASSWD で許可するのが最小権限である
   (`ALL=(ALL) NOPASSWD: ALL` にはしない)。

4. **GitHub の deploy key を登録する** — `deploy:update_code` は**サーバー上で** `git clone` する。

   ```
   ssh aicue 'ssh-keygen -t ed25519 -C "aicue-deploy" -f ~/.ssh/id_ed25519 -N ""; cat ~/.ssh/id_ed25519.pub'
   ssh aicue 'ssh -o StrictHostKeyChecking=accept-new -T git@github.com'
   ```

   出た公開鍵を GitHub の `kent013/aicue` → Settings → Deploy keys に **read-only** で登録する。

5. **DB とロールを作る** — PostgreSQL 18 は同一ホストにある。

   ```
   ssh aicue "sudo -u postgres psql -c \"CREATE ROLE aicue LOGIN PASSWORD '***';\" -c 'CREATE DATABASE aicue OWNER aicue;'"
   ```

6. **`shared/.env` を置く** (§3)。`deploy_path` が無ければ先に作る。

   ```
   ssh aicue 'sudo mkdir -p /var/www/aicue/shared && sudo chown -R ec2-user:ec2-user /var/www/aicue'
   scp .env.example aicue:/var/www/aicue/shared/.env   # 置いてから中身を編集する
   ssh aicue 'chmod 600 /var/www/aicue/shared/.env'
   ```

7. **S3 と LLM キーを用意する** (§9 / §10)。テイク撮影とシナリオ生成はこれが無いと動かない。

8. **デプロイする**

   ```
   docker exec -w /workspace aicue vendor/bin/dep deploy
   ```

9. **nginx を staging へ向ける** — `root /var/www/aicue/current/public;` /
   `fastcgi_pass unix:/run/php-fpm/aicue.sock;`。初回デプロイで `current` が出来てから reload する。

10. **ワーカーと scheduler を start する** (プロビジョニングでは enable のみ)。

    ```
    ssh aicue 'sudo systemctl start aicue-queue-default aicue-queue-analysis aicue-queue-render aicue-queue-media aicue-scheduler.timer'
    ```

11. **管理者を発行する** (§10.2)。

---

## 6. 通常デプロイ手順

```
docker exec -w /workspace aicue vendor/bin/dep deploy
```

`deploy` タスクの構成は `deploy.php` が正本で、`vendor/bin/dep tree deploy` で確認できる。順序:

| # | タスク | すること |
|---|---|---|
| 1 | `deploy:info` | 対象 host とリビジョンの表示 |
| 2 | `deploy:setup` | `releases` / `shared` / `.dep` の作成 (初回のみ実質的な作業) |
| 3 | `deploy:lock` | 同時デプロイの排他 |
| 4 | `deploy:release` | 新しい release ディレクトリの確保 |
| 5 | `deploy:update_code` | サーバー上で `git` から `main` を取得 |
| 6 | `deploy:shared` | `.env` / `storage` を shared へ symlink |
| 7 | `deploy:check_env` | **`shared/.env` が空でないことを確認** (無ければここで止める) |
| 8 | `deploy:writable` | `bootstrap/cache` / `storage` を書き込み可能にする |
| 9 | `deploy:vendors` | `composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction` |
| 10 | `deploy:frontend` | `corepack pnpm install --frozen-lockfile` → `corepack pnpm run build` |
| 11 | `artisan:storage:link` | `public/storage` → `storage/app/public` |
| 12 | `deploy:app_caches` | **config / event / view の 3 つだけ**を焼く (routing は焼かない) |
| 13 | `artisan:production_preflight` | `production:preflight` (staging では skip 扱いで通る) |
| 14 | `artisan:migrate` | `migrate --force` |
| 15 | `deploy:symlink` | `current` を新 release へ切り替え (原子的) |
| 16 | `deploy:reload_services` | php-fpm を **reload** → ワーカー 4 本を **restart** |
| 17 | `deploy:unlock` / `deploy:cleanup` / `deploy:success` | lock 解除 / 古い release の削除 / 完了表示 |

補足:

- **キャッシュ生成が composer install の後にある理由**: `composer install` の
  `post-autoload-dump` が `filament:upgrade` を走らせ、その中で config / view のキャッシュが
  clear される。前に置くと消える。
- **`production:preflight` に `--strict` を付けていない理由**: `--strict` は `APP_ENV` が
  `production` でないと fail する。現サーバーは `staging` なので必ず落ちる。
  そのため staging では「production 専用検査を skip した」warning が出て通る。
  **production 環境を作るときは `--strict` を付けた別 host 定義にすること。**
- **migrate が symlink 切替の前にある**ので、`current` が切り替わった瞬間には schema が既に
  新しい。逆に**旧コードが新 schema を読む窓**が存在する。列を落とす等のローリング非互換な
  migration はメンテナンスモード (`dep artisan:down` → デプロイ → `dep artisan:up`) を挟むこと。
- 一部だけ実行したいときは `dep <タスク名>` でよい (例: `dep deploy:reload_services`)。

---

## 7. ロールバック手順

```
docker exec -w /workspace aicue vendor/bin/dep rollback
```

- Deployer が 1 つ前の release へ `current` を張り替え、現 release に `BAD_RELEASE` を置く。
- `deploy.php` は `after('rollback', 'deploy:reload_services')` を張っているので、
  **php-fpm の reload とワーカー 4 本の restart も自動で走る**
  (これが無いと「コードは戻ったがワーカーは新コードのまま」になる)。
- 戻り先を指定するなら `dep rollback -o rollback_candidate=<release 番号>`。

> ⚠ **`rollback` は DB migration を戻さない。** 破壊的な migration を含むデプロイの後は
> コードだけ戻しても整合しない。`migrate:rollback` を人手で判断すること
> (`docs/billing-retention-runbook.md` のように、down で値が復元されない migration がある)。
>
> ⚠ **release が 1 つしか無い状態では rollback できない** (戻り先が無い)。

---

## 8. `aicue.jp` 取得後の HTTPS 切替手順

hop が増えない前提 (同一ホストの nginx で TLS 終端する) なら `TRUSTED_PROXIES` は `none` のまま。
**CDN / LB を前段に置く場合は `docs/trusted-proxies-runbook.md` §3 を先に書き換えること。**

1. **DNS** — `aicue.jp` の A レコードを `13.192.189.252` へ。
2. **nginx** — `server_name aicue.jp;` に変更して reload (まだ http)。
3. **証明書** — certbot で取得し、自動更新を有効にする。

   ```
   ssh aicue 'sudo certbot --nginx -d aicue.jp --agree-tos -m <運用者メール> --redirect'
   ssh aicue 'systemctl list-timers | grep certbot'
   ```

4. **`shared/.env` を書き換える** (この 4 つは**同時に**変える)。

   | キー | 変更後 |
   |---|---|
   | `APP_URL` | `https://aicue.jp` |
   | `PRIMARY_HOST` | `aicue.jp` |
   | `SESSION_SECURE_COOKIE` | `true` |
   | `SECURITY_HSTS_ENABLED` | `true` |

5. **設定キャッシュを作り直す** — `.env` の変更は焼いた config には反映されない。

   ```
   docker exec -w /workspace aicue vendor/bin/dep artisan:config:cache
   docker exec -w /workspace aicue vendor/bin/dep deploy:reload_services
   ```

   (経路キャッシュは焼いていないので、routing 側の再生成は無い。)

6. **パスキーの RP ID が変わる影響を確認する** — ここが最も壊れやすい。

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

7. **`APP_ENV` を `production` にするなら**、`ProductionEnvGuard` の必須項目が**すべて**
   揃っていることを先に確認する (`APP_KEY` / `CIPHERSWEET_KEY` / `STRIPE_WEBHOOK_SECRET` /
   `SESSION_SECURE_COOKIE=true` / `APP_DEBUG=false` / HSTS / CSP / `TRUSTED_PROXIES` /
   パスキー設定 / 偽の外部サービスのフラグが空)。1 つでも欠けると**起動しない**。
   併せて `deploy.php` の preflight タスクに `--strict` を付けた production 用 host を定義すること。

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
  ドメイン取得前に staging で試すなら `http://13.192.189.252` を入れ、切替時に差し替える。
- S3 は本文がチェックサムと一致しない PUT を拒否する。したがってこの署名付き URL で置ける
  内容は申告ハッシュの 1 通りに固定される (登録後の再 PUT 差し替え防止)。
  CORS を緩めてもこの保証は変わらないが、緩めると別オリジンから署名を使い回せる余地が増える。

### 9.3 最小権限の IAM ポリシー例

アプリが使う操作は PutObject / GetObject / HeadObject / DeleteObject / CopyObject
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

### 10.1 LLM API キー

シナリオ生成は Prism 経由で LLM を呼ぶ (`config/prism.php`)。**使う provider のキーだけ**を
`shared/.env` に置く。

| キー | provider |
|---|---|
| `OPENAI_API_KEY` | OpenAI |
| `ANTHROPIC_API_KEY` | Anthropic |
| `GEMINI_API_KEY` | Google Gemini |

- キーを置いたら `dep artisan:config:cache` → `dep deploy:reload_services` で反映する。
- **キーは組織ごとの費用計上に影響しない** (帰属は `LlmCallContextData` がアプリ側で付ける)。
- キーが空のまま生成を叩くと provider 側の認証エラーでジョブが failed になる。

### 10.2 管理者の発行

```
ssh aicue 'cd /var/www/aicue/current && /usr/bin/php8.4 artisan admin:create'
```

- 対話で email / name / password を聞く (`--email=` / `--name=` / `--password=` でも渡せるが、
  シェル履歴にパスワードが残るので**対話を推奨**)。
- `ADMIN_MFA_REQUIRED=true` なので、初回ログイン時に TOTP の登録を求められる。
  端末を失った場合の復旧は
  `php artisan admin:reset-mfa <AdminUser の id> --reason="<10 文字以上の理由>"`
  (`ResetAdminMfaCommand`。理由は監査証跡に残るので必須)。
- 本番相当の環境で `AdminUserSeeder` は使わない (local 専用)。

---

## 11. 既知の未対応事項

デプロイ定義を入れた時点で**まだ手当てされていないもの**。先回りして作らない代わりに、
ここに列挙して見えるようにしておく。

| 項目 | 現状 |
|---|---|
| production 環境 | **無い**。現サーバーは `APP_ENV=staging` の 1 台のみ。`production:preflight --strict` を使う host 定義もまだ無い |
| ドメイン / TLS | `aicue.jp` 未取得、TLS 未導入 (§8) |
| CI からの自動デプロイ | **入れていない**。`.github/workflows` にデプロイ job は無く、デプロイは開発機から手で叩く。入れる PR は `AGENTS.md` の運用要件 2 つ (route:cache / TRUSTED_PROXIES) を再確認すること |
| DB バックアップ | **未設定**。PostgreSQL は同一ホストにあり、インスタンスが失われるとデータも失われる。`pg_dump` の定期取得と保管先が要る |
| S3 のライフサイクル / 保持期限 | 未設定。テイク動画は容量が大きいので、保持方針を決めるまで課金が読めない |
| 監視 / アラート | 無し。`event = job_ownership_lost` の連続発生の監視 (`docs/architecture.md` の運用契約) も未配線 |
| worker のメモリ制限 | 無し (cgroup 制限を置いていない)。ffmpeg の `-max_alloc` は 1 回の heap 確保の上限で、プロセス全体の RSS 上限ではない (`app/Support/Media/FfmpegSafetyArguments.php`) |
| systemd unit の `--timeout` の機械検査 | 無し。リポジトリ外にあるため CI では検知できない (§1.2) |
| メンテナンスモードの自動化 | `deploy` に組み込んでいない。ローリング非互換な migration のときだけ `dep artisan:down` / `dep artisan:up` を人手で挟む |
| Stripe / SES / reCAPTCHA / MCP | staging では未設定でも起動する。使うときに §3.3 のキーを入れる |

---

## 関連

- `deploy.php` — デプロイ定義そのもの (タスク構成の正本)
- `AGENTS.md` 「運用要件 (route:cache)」 / 「運用要件 (T108)」 — デプロイ基盤にかかる規約
- `docs/template-divergence.md` **D19** — 経路キャッシュを打たない判断の登録
- `docs/trusted-proxies-runbook.md` — client IP の信頼境界 (§3 が実インフラの記入欄)
- `docs/architecture.md` §キューのリース期間とワーカー制限時間の規約 — worker `--timeout` の正本
- `docs/app-integration-guide.md` §7c — 後付け middleware の機序
- `docs/ses-mail-runbook.md` — 実メール送信を有効にするとき
- `docs/rollout-checklists.md` — 機能を段階的に有効化するときの手順
