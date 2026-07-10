# テスト戦略・ツールチェーン・設定/ルーティング/インフラ調査(視点⑧⑩)

> 対象: phpunit 構成・Dusk/E2E・worktree 戦略・CI・scripts・開発環境(mprocs/mise/docker)、
> bootstrap/app.php・routes 分割・ミドルウェア・config・.env テンプレート・i18n・法務・デプロイ・スケジューラ。

## 1. テストスイート構成

- aigenba: **6 層**(Unit / Feature / Architecture / Contract / Browser(Dusk) / E2E(CLI live))。
  Contract = validation↔runtime 契約テスト。E2E は phpunit.e2e.xml + sqlite_e2e + CLI build 強制
  (scripts/run-e2e.sh、「古い dist による嘘の green」防止)
- spirux: 4 層(Unit / Feature / Architecture / Browser)
- **テンプレ**: Unit/Feature/Architecture/Browser を標準、Contract と E2E は CLI を持つアプリ向けの
  オプション枠として phpunit 設定の雛形だけ同梱

要移植の仕組み:
- **tests/bootstrap.php**: worktree path の sha1 から `/tmp/{app}-test-<hash>.sqlite` を導出し
  $_SERVER/$_ENV/putenv の 3 経路注入 + paratest worker 2 段分離。
  ⚠ **spirux の bootstrap は DB 名が `aigenba-test-` のまま**(copy-paste 残骸)。テンプレでは
  APP_NAME 由来に必ず汎用化する(この事故自体が「テンプレ化すべき」根拠)
- **Pest.php**: suite 別 uses + RoleSeeder 等の seeding 管理(aigenba)+
  **参照テーブル不変検証**(spirux: roles/permissions/plans を tearDown で SHA256 検証)+
  **StrayLlmCallGuard**(fake 外の LLM 呼び出しを fail)→ 全部取り込む
- **scripts/run-test.sh**: flock 排他(同一 worktree 内の二重テスト起動防止、aigenba)
- Dusk: `.env.dusk.local` + `unset DB_*` での dev/dusk サーバー並走、DuskFakesServiceProvider
  (Prism canned / Stripe fake / PageRender fake)。ポートは spirux 系(dev 8001 / dusk 8000)に統一

## 2. worktree 戦略・CI・scripts

- worktree: vendor は worktree-local composer install、node_modules は pnpm Global Virtual Store 共有。
  **spirux の簡潔版(228 行 setup-worktree.sh、marker なし、`.claude/worktrees/tasks/<id>`)をドナー**に、
  aigenba の health check 7 項目を取り込む。install 許可の層別(L1 install 可 / L2 require はタスク時のみ / L3 禁止)も docs 化
- CI: aigenba ci.yml(PHP テスト sharding + 静的解析 + Node lint/test)+ dusk.yml をベースに、
  spirux の supply-chain.yml / secret-scan.yml / migration-mysql.yml を加えた分割構成
- scripts/: run-test.sh / dusk.sh / phpstan.sh / codex / setup・teardown-worktree.sh / audit-gate.ts を同梱。
  deploy.sh・CLI build 系はオプション
- mise.toml: node 22 / pnpm pin(11.x 固定)。docker-compose は **spirux 版がセキュア**
  (POSTGRES_PASSWORD を env-var 必須化、mailpit を 127.0.0.1 loopback bind=T-SEC-14)→ ドナー
- mprocs.yaml: server / dusk / queue(--timeout=0) / logs(pail) / vite。Stripe listen は課金検証時のみの行をコメントで

## 3. bootstrap/app.php・ルーティング骨格

- **trustProxies / trustHosts**: 両者同型(config/trusted_hosts.php 駆動、exact+wildcard suffix、
  起動時 fail-fast バリデーション)→ そのまま同梱
- **routes 分割は aigenba 方式がドナー**: `web.php` / `api.php` / `ai.php`(OAuth+MCP+well-known を分離)/
  `webhooks.php`(Stripe/SNS、CSRF 除外を境界ごと分離)/ `console.php`。
  route 名規約: web=`resource.action`、API=`api.v1.resource.action`
- rate limiter: aigenba は AppServiceProvider 集約 / spirux は route inline。
  **テンプレは「定義は 1 箇所(Provider)、適用は route」で統一**(名前付き limiter 一覧を雛形化:
  login / two-factor / forgot-password / inquiry / api-read / api-write / api-status / api-mcp)
- 機械可読 route(robots.txt / sitemap.xml / llms.txt / ai.txt)+ stateless onboarding route の
  パターン(cookie/session/CSRF 除外)も雛形に含める

## 4. ミドルウェア(汎用セット)

共通 7 種をそのまま同梱: LocalOnly / HandleInertiaRequests / SecurityHeaders(config/security.php 駆動) /
RequireRecentAuth / RequireActiveSubscription / VerifyMcpOrigin(spirux 版) / EnforceMcpTransport /
VerifySnsSignature。
加えて: CurrentOrganizationMiddleware(spirux) / IdempotentRequest(spirux 名称を採用) /
RequireApiKeyAbility(spirux) / NoIndex(spirux) / RedirectToHttps(spirux、Q9 の env フラグ付き) /
NoStoreCacheHeadersForTwoFactor(aigenba) / RequireRecentAuthOnFortifyRoutes(aigenba) /
AuthenticateSession(spirux T-SEC-11: password hash mismatch で logout)。
ドメイン固有(EncounterAudience / RequireCapability 等)は対象外。

## 5. config・.env

- 共通 config をそのまま同梱: trusted_hosts.php / security.php / mcp.php / laratrust / fortify /
  passport / ciphersweet / ssrf-pin / llm-pricing / seo.php(robots/llms/ai.txt)
- アプリ名 config は `config/template.php`(08 §5)+ `config/app-domain.php` 相当の空雛形
- **legal は spirux の `config/legal.php`**(consent_version / inquiry_retention / recipient)を採用し、
  **aigenba の LegalConsentVersionSingleSourceTest**(版ドリフト検出)を組み合わせる。
  法務ページ自体は aigenba の Inertia ページ(Terms/Privacy/CommerceDisclosure)をドナー
- **.env は aigenba 方式**: `.env.example`(dev)+ **`.env.production-template`**(本番、
  fail-fast チェック群と対応)の 2 本立て。雛形構成は調査済み(APP/DB/SESSION/CACHE/MAIL/AWS/
  PRISM+LLM keys/STRIPE/GOOGLE/TRUSTED_HOSTS/SECURITY_*/MCP_*/DEBUG_LOGIN/ドメイン固有コメント枠)。
  EnvExampleInvariantTest で .env.example と config 参照の drift を検出
- i18n: lang/ja 最小構成(auth/passwords/pagination/validation)。locale 既定は
  `APP_LOCALE=ja` / fallback=ja / faker=ja_JP(aigenba 系)に統一

## 6. スケジューラ(雛形に含める cron)

汎用分のみ: billing:reconcile-schedules(daily) / billing:release-stale-reservations(5min) /
billing:sweep-stale-webhooks(5min) / billing:send-reminders(daily, onOneServer) /
idempotency-keys:prune(daily) / inquiry:purge(daily)。
quota の stuck reservation repair(spirux 10min)は Quota モジュール側に同梱。
ドメイン系(encounter:recover-stale 等)は対象外、「長時間処理には必ず stale recovery cron を対で作る」
規約だけ docs に書く。

## 7. デプロイ・インフラ

- Deployer(deploy.php + hosts.yml)は aigenba 構成をドナーに汎用化(submodule 同期 / frontend build /
  health check)。terraform(infra/、ALB+ECS+RDS+Redis+SES 12 モジュール)は **オプション同梱**
  (Q9 の「ALB 構成レシピ」の実体として)。Dockerfile は dev 用 + production 用の 2 本(aigenba)を
  spirux のセキュア設定とマージ

## 8. テンプレ反映時の注意(発見事項)

1. spirux bootstrap.php の `aigenba-test-` 残骸 → アプリ名ハードコードの混入しやすさの実証。
   テンプレでは「アプリ名が現れてよいファイル」を config/template.php と .env に限定する
   architecture テスト(grep 型)を追加する価値がある
2. AGENTS.md 間で RefreshDatabase の扱いに差があるように見える(aigenba 禁止記述?/spirux 許可)。
   実態は Pest.php で両者 RefreshDatabase 使用のため、AGENTS.md の記述文脈(おそらく
   「dev DB に対する操作禁止」の文脈)を Phase 0 で実機確認してから雛形に書く
3. ポート・DB 名・state ファイルパス等の「環境座標」は 1 箇所(config/template.php + .env)に集約し、
   mprocs/scripts/bootstrap がそこから読む構造にする(現状は両アプリとも散在)
