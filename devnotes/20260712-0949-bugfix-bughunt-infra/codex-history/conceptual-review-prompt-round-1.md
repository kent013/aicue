# Codex 概念設計レビュー依頼: bugfix-bughunt-infra (Round 1)

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件 (アプリ都合で緩めない)

1. tenant キー不信 (ownership/actor/tenant キーを payload から受け取らない)
2. 子は親に属する (nested route の不整合は認可より前に 404)
3. cross-org 不可
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる
5. 権限判定は常に laratrust_team_id を明示
6. PII は CipherSweet、検索は whereBlind
7. 課金の冪等性: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【前提補足】
- 本設計は「bug-hunt (LLM 探索的バグハント) 専用環境の整備」であり、UI 変更を含まない。
- bug-hunt 環境: APP_ENV=bughunt.local / DB=bug_hunt(_N) / TESTING_FAKE_EXTERNALS=true /
  scripts/bug-hunt-shard.sh が provision (createdb→migrate:fresh+seed→serve) を機械的に行う。
  worktree から走行し、worktree は `composer install --no-scripts` でセットアップされる。
- 参照可能な関連ファイル (読み込み許可): app/Providers/AppServiceProvider.php,
  app/Services/Billing/ (TicketCheckoutGateway, CashierTicketCheckoutGateway, BillingAccess,
  TicketCheckoutService, TicketLedgerService), app/Http/Controllers/Billing/,
  database/seeders/ (ManualTestSeeder, AdminUserSeeder, BughuntOAuthSeeder, DatabaseSeeder),
  scripts/bug-hunt-shard.sh, tests/Pest.php, app/Support/ProductionEnvGuard.php,
  devnotes/20260712-075854-bug-hunt/shard-0/shard-report.md (F-05/F-13 の finding 詳細)

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか（特に fake が production へ漏れるリスク、dev/prod seed 方針の破壊、既存テスト・self-test の後退）
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

(以下、devnotes/20260712-0949-bugfix-bughunt-infra/conceptual-design.md の全文)

# 概念設計: bugfix-bughunt-infra (bug-hunt 基盤整備: F-05 Stripe fake 配線 / F-13 Filament アセット / seeder subscription)

## 背景・課題

bug-hunt run `20260712-075854` (shard-0) で Critical 3 件のうち 2 件 (F-05 / F-13) と副次発見
(seeder に active subscription が無い) が「製品ロジックのバグ」ではなく **bug-hunt 環境の整備不足**
と判明した。これらが未修修のままだと中核ジャーニー (S3: SOP→AI 解析→撮影→レンダ) が入口で詰み、
bug-hunt の再走行 (回帰検証・未走行ストーリーの消化) ができない。

コード調査で特定した根本原因:

### F-05 (Stripe 連携全操作が 500)

1. `TicketCheckoutGateway` は `AppServiceProvider::register()` で常に実 Stripe 実装
   (`CashierTicketCheckoutGateway`) に bind される。fake (`Tests\Support\FakeTicketCheckoutGateway`)
   は phpunit テスト内で手動 swap されるのみで、**bughunt serve (APP_ENV=bughunt.local /
   TESTING_FAKE_EXTERNALS=true) では Stripe 未設定のまま実装が呼ばれ 500** になる。
2. `BillingController::checkout()` / `portal()` は gateway 抽象すら経由せず
   `$organization->newSubscription(...)->checkout(...)` / `billingPortalUrl(...)` を **Cashier 直呼び**
   しており、fake に差し替える継ぎ目が存在しない。
3. さらに **`config/testing.php` がリポジトリに存在しない**。`.env.bughunt.local` の
   `TESTING_FAKE_EXTERNALS=true` はどの config にもマップされておらず、
   `config('testing.fake_externals')` は常に null。この結果、同 config を第 1 ガードにする
   `BughuntOAuthSeeder` も**現状は常に skip している** (fake externals 基盤自体が未導入)。

### 副次発見 (seeder に active subscription が無い → F-07 の環境要因側)

- `ManualTestSeeder` は組織 (free / standard) を作るが Cashier subscription 行を作らないため、
  `BillingAccess::hasActiveAccess()` (subscription('default') が active/trialing のみ許可、
  fail-closed) が全組織で false → `/projects` 等の業務ルートが `/billing` へ redirect され走行不能。
- free プランでもゲートを通すべきかは製品仕様の問題 (G1: free ゲート整合) で**本設計のスコープ外**。
  本設計は「bughunt 環境で standard 相当の走行状態を seed で作る」ことに限定する。
  standard 組織の subscription 検証 (active sub がある状態の挙動) のためにも active sub を張る。

### F-13 (Filament 管理画面のアセット全滅 + admin ログイン失敗)

1. **アセット 404**: `public/{css,js,fonts}/filament/` は gitignore 済みで、
   `composer.json` の post-autoload-dump `filament:upgrade` が生成する。しかし bug-hunt は
   worktree 走行が既定で、`scripts/setup-worktree.sh` は `composer install --no-scripts` のため
   **worktree では filament アセットが一度も publish されない** → `/admin` が無スタイル。
2. **admin ログイン失敗**: provision は `db:seed --class=AdminUserSeeder` を明示実行しているが、
   `AdminUserSeeder` 自身が `app()->environment('local')` 以外では skip する guard を持つため
   **bughunt.local では admin@example.com が作成されない** → 「認証に失敗しました。」

## 改善アイデア

「外部 fake の capability flag (`config('testing.fake_externals')`)」を正式に導入し、
その flag の下で Stripe 到達点 2 箇所 (チケット checkout / サブスク checkout+portal) を
fake に差し替える。seed とprovision の欠落 (subscription / filament assets / admin user) を
bughunt 経路限定で埋める。**製品の課金ロジック (BillingAccess 判定・webhook・台帳) は一切変えない**。

### 施策 1: `config/testing.php` の新設 (fake externals 基盤の正式導入)

```php
return [
    // 外部サービス (Stripe 等) を fake 化する capability flag。
    // .env.bughunt.local が true にする。production では ProductionEnvGuard が拒否する。
    'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false),
];
```

- これにより `BughuntOAuthSeeder` の第 1 ガードも設計どおり機能し始める
  (現在は常に skip = OAuth 系の探索カバレッジも死んでいる)。

### 施策 2: サブスク checkout / portal の gateway 抽象化 (継ぎ目の新設)

既存の `TicketCheckoutGateway` / `CashierTicketCheckoutGateway` の流儀に合わせ、
`SubscriptionCheckoutGateway` interface + `CashierSubscriptionCheckoutGateway` 実装を新設し、
`BillingController::checkout()` / `portal()` の Cashier 直呼び部分を移す
(ロジックは移動のみ。挙動不変のリファクタ)。`AppServiceProvider` で bind する。

- 副次効果: これまで Stripe 未到達でテスト不能だった checkout / portal の happy path
  (Inertia::location での外部遷移) が fake bind でテスト可能になる。

### 施策 3: `FakeExternalsServiceProvider` の新設 (fake の配線 + production ガード)

`bootstrap/providers.php` に `AppServiceProvider` **より後** に登録し (後勝ち rebind)、
`register()` で以下の条件が全て成立するときのみ fake を bind する:

- `config('testing.fake_externals') === true`
- `app()->environment()` が **allowlist** (`local` / `testing` / `bughunt.local`) に含まれる
  (「production でない」の denylist ではなく allowlist で fail-secure に倒す。
  staging 等の未知環境で flag が誤設定されても fake しない)

bind 対象 (runtime fake は `app/Services/Billing/Fakes/` に新設。テスト専用 spy である
`Tests\Support\FakeTicketCheckoutGateway` は record/failure-injection 用としてそのまま残す):

- `TicketCheckoutGateway` → `FakeTicketCheckoutGateway` (決定論 session id、URL は cancel_url に
  帰還 = 「Stripe へ行って戻ってきた」体験。付与は行わない。「購入完了」と偽装しない)
- `SubscriptionCheckoutGateway` → `FakeSubscriptionCheckoutGateway` (checkout は cancel_url、
  portal は return_url へ帰還)

さらに `ProductionEnvGuard::violations()` に「production で `testing.fake_externals` が true なら
違反」を追加する (deploy 時 fail-fast。DEBUG_LOGIN_* と同じ路線)。

### 施策 4: `BughuntBillingSeeder` の新設 (active subscription + 初期チケット)

`ManualTestSeeder` は dev の手動テストでも使われるため触らず、`BughuntOAuthSeeder` と同じ
**三重 fail-secure guard** (fake_externals === true ∧ env=bughunt.local ∧ DB 名 `^bug_hunt(_[1-8])?$`)
を持つ専用 seeder を新設し、`scripts/bug-hunt-shard.sh` の provision / reseed の seed 列に追加する:

- 全 Organization に active な `default` subscription 行を冪等付与
  (決定論 `stripe_id` (例: `sub_bughunt_{org id}`)。Stripe には到達しない。
  tests/Pest.php の `createFakeSubscription()` と同じ作り方)
- 全 Organization に初期チケットを冪等付与
  (`TicketLedgerService::grantMonthly()` を冪等キー `bughunt:initial-grant:{org id}` で呼ぶ。
  枚数は固定 100 = S3 の AI 解析/レンダ探索に十分な決定論値)

これで `/projects` `/app` 等の業務ルートが bughunt で走行可能になり (F-07 の環境要因解消)、
チケット消費系ジャーニー (解析・レンダ) も残高ゼロで詰まない。

### 施策 5: `AdminUserSeeder` の bughunt.local 対応

guard を `app()->environment('local')` → `app()->environment(['local', 'bughunt.local'])` に拡張
(どちらも非本番。production/staging/CI の防御は不変)。

### 施策 6: provision への Filament アセット publish 追加

`scripts/bug-hunt-shard.sh` の `cmd_provision()` の seed ブロック直後に
`artisan_for_shard "${db}" "${url}" filament:assets` を追加する
(既存の用途別 wrapper 経由 = 生 artisan 禁止規約を維持。DB には触れないコマンドだが
env -i + bughunt env で実行して dev env の混入を防ぐ)。`public/{css,js,fonts}/filament/` が
worktree に生成され、`/admin` のスタイルが復旧する。
`cmd_assets_check` (read-only ゲート) と self-test のフィクスチャは変更しない
(self-test の pass を維持。filament アセットは content-hash 依存が無く provision 毎の
再 publish で常に最新になるため、鮮度ゲートに組み込む必要がない)。

## 期待効果

- **使命への貢献**: North Star フロー (SOP→AI カット設計→撮影→レンダ) を bug-hunt が
  端から端まで実走できるようになり、S3/S7 の未走行領域 (manual/cut/take/IDOR 検証) の
  発見能力が回復する。F-05/F-13 という「環境ノイズの Critical」が消え、以後の run の
  finding が製品の実バグに集中する。
- fake externals 基盤 (config/testing.php) の正式導入により、既存の `BughuntOAuthSeeder` が
  設計どおり動き出す (OAuth/CLI セッション系カバレッジの回復)。
- サブスク checkout/portal の gateway 抽象化により、従来テスト不能だった happy path に
  テストが付く (テスト資産の純増)。
- production への安全性はむしろ向上する (ProductionEnvGuard に fake flag 検査を追加)。

## 実装方針（概要）

| # | 変更 | ファイル |
|---|------|---------|
| 1 | config 新設 | `config/testing.php` (新規) |
| 2 | サブスク gateway 抽象 | `app/Services/Billing/SubscriptionCheckoutGateway.php` (新規 interface)、`app/Services/Billing/CashierSubscriptionCheckoutGateway.php` (新規)、`app/Http/Controllers/Billing/BillingController.php` (直呼び → gateway 経由)、`app/Providers/AppServiceProvider.php` (bind 追加) |
| 3 | fake 配線 | `app/Providers/FakeExternalsServiceProvider.php` (新規)、`app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php` (新規)、`app/Services/Billing/Fakes/FakeSubscriptionCheckoutGateway.php` (新規)、`bootstrap/providers.php`、`app/Support/ProductionEnvGuard.php` |
| 4 | bughunt seed | `database/seeders/BughuntBillingSeeder.php` (新規)、`scripts/bug-hunt-shard.sh` (provision / reseed の seed 列に追加) |
| 5 | admin seeder | `database/seeders/AdminUserSeeder.php` (guard 拡張) |
| 6 | filament assets | `scripts/bug-hunt-shard.sh` (`cmd_provision` に filament:assets) |

テスト (Pest / RefreshDatabase グローバル):

- `FakeExternalsServiceProviderTest` (新規): flag=true ∧ allowlist env で fake が解決される /
  production env では flag=true でも実装のまま / flag=false は no-op
- `ProductionEnvGuardTest` (既存に追記): production ∧ fake_externals=true が violation になる
- `BughuntBillingSeederTest` (新規): guard 不成立 (testing env / 非 bughunt DB) で no-op、
  付与ロジックが active sub + チケット 100 を冪等付与 (再実行で増えない)
- `AdminUserSeederTest` (既存に追記): bughunt.local でも作成される
- `BillingPageTest` (既存に追記) または新規: fake `SubscriptionCheckoutGateway` bind での
  checkout / portal happy path (Inertia::location の遷移先検証)
- 既存テスト (TicketCheckoutTest 等) は無変更で green を維持
- `scripts/bug-hunt-shard.sh self-test` の pass 維持 (フィクスチャ非接触)

## 制約・前提

- **製品の課金ロジック不変**: `BillingAccess` / webhook 冪等マシン / チケット台帳
  (reserve→commit/release) / plan_code 同期には触れない。free ゲートの製品仕様 (G1) は別設計。
- **fake は絶対に production に漏らさない**: allowlist env 判定 + ProductionEnvGuard の
  二重防御。fake 実装は「付与しない・偽装しない」(cancel 帰還) に徹し、fake 経由で
  台帳・subscription 状態を書かない (状態は seeder が正本)。
- **dev/prod の seed 方針不変**: `DatabaseSeeder` / `ManualTestSeeder` の投入内容は変えない。
  subscription/チケット付与は三重 guard 付き `BughuntBillingSeeder` に隔離。
  `AdminUserSeeder` の guard 拡張は bughunt.local という非本番環境の追加のみ。
- bug-hunt の DB 防御規約 (用途別 wrapper / 生 artisan 禁止 / orchestrator gate) を維持する。
- 既存 Architecture テスト (MassAssignmentSafetyTest 等) との整合: subscription 行の作成は
  billable relation (`$organization->subscriptions()->create()`) 経由 (organization_id は
  FK 自動設定、guarded を侵さない)。

## スコープ外

- G1: free プランで課金ゲートを通す製品仕様の整合 (別設計で扱う)
- LLM (Prism) / Captcha / SSO 等、Stripe 以外の外部 fake の拡充 (S3 の AI 解析実走には
  別途 LLM fake が必要になり得るが、本設計は F-05/F-13/seeder の 3 点に限定)
- fake checkout の「決済完了シミュレーション」(webhook 発火・チケット付与の擬似再現)。
  残高・subscription は seeder が決定論に用意する方針で代替する
- F-05 で露出した UX 改善 (エラーメッセージ等) や他 finding (F-01〜F-14) の対応
- `/admin` の bug-hunt 探索対象化 (対象外 prefix のまま。provision の健全性のみ整備)
