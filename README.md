# laravel-claude-template

LLM 駆動開発のための Laravel + Svelte SaaS テンプレート。
兄弟プロダクト 2 つ(AI OJT シミュレーション / AI UX 評価)の実装から共通項を抽出し、
「ドメイン機能ゼロで起動する SaaS」+「LLM が正しく拡張するための規約・機械統制・運用スキル」を同梱する。

## スタック

Laravel 13 / PHP 8.4 / Inertia 3 + Svelte 5 (Runes) / Tailwind 4 (CSS-first) /
Pest 4 + PHPStan level 10 / Vitest / Fortify + Socialite / CipherSweet (PII 暗号化) /
Laratrust (teams, strict_check=true) / Cashier (Stripe) / Passport + laravel-mcp /
kent013/laravel-prism-prompt (LLM) / Filament 5 (管理画面)

## セットアップ

```bash
./init.sh          # composer setup (install / key:generate / migrate / pnpm install / build)
composer dev       # mprocs で dev server (8001) / queue / logs / vite を並走
```

Stripe 公式 skill(`skills-lock.json` に lock 済み)は `npx skills add docs.stripe.com` で
`.claude/skills/` 配下に導入する(git 管理外)。

コード索引ツール(`code-review-graph`)は開発コンテナに版を固定して同梱済みで、
`.claude/settings.json` の hook が編集のたびに差分更新する(`AGENTS.md` §常設 hook 配線)。
コンテナを作り直していない環境だけ `uv tool install code-review-graph==2.3.7` を 1 度実行し、
`code-review-graph build` で索引を初回ビルドする(未導入でもセッションごとに 1 行告知が出るだけで、
編集作業は止まらない)。

管理者(Filament `/admin`)の発行(env / seeder による本番初期投入は廃止済み):

```bash
php artisan admin:create                            # 本番の正式経路(対話式。--email / --name 指定可、
                                                    # --password は shell history に残るため非推奨)
php artisan db:seed --class=AdminUserSeeder         # local 開発専用の固定 admin(admin@example.com)
php artisan admin:reset-mfa {id} --reason="..."     # MFA 紛失時の break-glass(理由 10 文字以上必須・監査ログ記録)
```

検証コマンド(全 green でコミットする規約):

```bash
composer test      # Pest (parallel)
composer phpstan   # PHPStan level 10
vendor/bin/pint --test
pnpm lint && pnpm typecheck && pnpm test && pnpm build
```

## 同梱されているもの

- **認証・アカウント**: 登録(規約同意の証跡) / ログイン / 2FA / SSO (Google、GET anchor 開始) /
  メール変更(旧アドレス通知) / step-up 再認証 / アカウント削除
- **組織 3 階層**: Organization → CustomTeam → Project。部門が不要なアプリは
  **Default Team パターン**で表示上スキップ(`docs/default-team-pattern.md`)
- **メンバー管理**: 招待(back+flash 完結) / ロール変更 / オーナー移譲(行ロック+step-up) / 組織切替
- **課金**: Cashier(Organization billable) / Webhook 冪等マシン / チケット台帳(2 フェーズ消費) /
  多次元 Quota / プランシーダー
- **API**: API キー(flat ability) / REST API v1(統一エラー envelope + Idempotency-Key) / MCP
- **LLM**: prompt YAML + UserInput 型(injection 防御) / llm_call_logs 観測 / Prism 直呼び禁止 guardrail
- **管理画面**: Filament(AdminUser + 専用 guard)
- **問い合わせ・メール抑止**: 公開問い合わせフォーム(Contact→Inquiry、PII は CipherSweet 暗号化) /
  SES バウンス・苦情由来の送信抑止(EmailSuppression + SNS 署名検証)
- **SEO 基盤**: サーバ描画 `<head>`(SeoServiceProvider) + robots.txt / sitemap.xml /
  llms.txt / ai.txt(クローラ正本をサーバ側に固定)
- **デザインシステム**: `DESIGN.md`(canonical) + tokens.css + コンポーネント 27 種 +
  parity / ds-purity の機械統制(`docs/design-system.md`)
- **セキュリティ統制**: mass-assignment 二層防御 / URL 整合 guard(認可前 404) /
  Architecture テスト群(deny-by-default inventory) / production:preflight
- **運用スキル**: `.claude/skills/app-*`(自走ループ / 設計 / 実装 / TODO / レビュー)

## LLM 開発者へ

1. まず `AGENTS.md` を読む(規約の正本)
2. ドメイン機能の追加は `docs/app-integration-guide.md` に従う。**Item リソースが見本**
3. テンプレート構造からの意図的逸脱は `docs/template-divergence.md` に記録してから行う

## ドキュメント

| ファイル | 内容 |
|---|---|
| `AGENTS.md` | 開発規約の正本(使命はアプリ初期化時に記述) |
| `DESIGN.md` | デザインシステム canonical(テーマ差し替え手順は docs/design-system.md) |
| `docs/app-integration-guide.md` | ドメインロジックのマッピング規則 + Item 見本チェックリスト |
| `docs/default-team-pattern.md` | 組織 3 階層と Default Team の仕様 |
| `docs/template-divergence.md` | テンプレートからの逸脱レジストリ |
| `docs/auth-security-mechanisms.md` | 認証・セッション・パスキー・SSO・信頼境界の仕組みと不変条件 |
| `docs/trusted-proxies-runbook.md` | client IP の信頼境界(`TRUSTED_PROXIES`)の運用契約。production は明示宣言が必須(未設定は起動時 fail-fast) |
| `devnotes/20260611-template-extraction/` | テンプレート設計の調査・決定記録(01〜14) |
