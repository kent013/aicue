# 概念設計: bugfix-bughunt-infra (bug-hunt 基盤整備: F-05 Stripe fake 配線 / F-13 Filament アセット / seeder subscription)

改訂: Round 1 レビュー反映版 (2026-07-12)

## 背景・課題

bug-hunt run `20260712-075854` (shard-0) で Critical 3 件のうち 2 件 (F-05 / F-13) と副次発見
(seeder に active subscription が無い) が「製品ロジックのバグ」ではなく **bug-hunt 環境の整備不足**
と判明した。これらが未修のままだと中核ジャーニー (S3: SOP→AI 解析→撮影→レンダ) が入口で詰み、
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
  本設計は「bughunt 環境に standard 相当の走行状態を seed で作る」ことに限定する。

### F-13 (Filament 管理画面のアセット全滅 + admin ログイン失敗)

1. **アセット 404**: `public/{css,js,fonts}/filament/` は gitignore 済みで、
   `composer.json` の post-autoload-dump `filament:upgrade` が生成する。しかし bug-hunt は
   worktree 走行が既定で、`scripts/setup-worktree.sh` は `composer install --no-scripts` のため
   **worktree では filament アセットが一度も publish されない** → `/admin` が無スタイル。
2. **admin ログイン失敗**: provision は `db:seed --class=AdminUserSeeder` を明示実行しているが、
   `AdminUserSeeder` 自身が `app()->environment('local')` 以外では skip する guard を持つため
   **bughunt.local では admin@example.com が作成されない** → 「認証に失敗しました。」

## 成功条件

1. bughunt 環境で `POST /purchase-tickets/checkout` / `POST /billing/checkout` /
   `POST /billing/portal` が 500 にならない (F-05 再現なし)
2. standard 組織のテストアカウントで `/projects` `/app` が 200 で走行できる
   (課金あり経路の回復)。**free 組織は従来どおり billing redirect のまま**
   (課金なし経路の検出能力を保持。G1 で仕様が変わるまで現状維持)
3. `/admin` がスタイル付きで表示され、admin@example.com / password12345 でログインできる
   (F-13 再現なし)
4. `composer test` / `phpstan` / self-test (`scripts/bug-hunt-shard.sh self-test`) が全て pass
5. production 挙動は不変 (fake bind されない・ProductionEnvGuard で flag 誤設定を fail-fast)

## 改善アイデア

「外部 fake の capability flag (`config('testing.fake_externals')`)」を正式に導入し、
その flag の下で Stripe 到達点 2 箇所 (チケット checkout / サブスク checkout+portal) を
fake に差し替える。seed と provision の欠落 (subscription / filament assets / admin user) を
bughunt 経路限定で埋める。**製品の課金ロジック (BillingAccess 判定・webhook・台帳) は一切変えない**。

変更は独立検証可能な 3 施策群に分ける (B / C は A の config 新設にのみ依存。実装順 A→B→C):

- **施策群 A: external fake wiring** (施策 1〜3)
- **施策群 B: bughunt billing fixtures** (施策 4)
- **施策群 C: admin/assets provisioning** (施策 5〜6)

各施策は **fail するテストを先に置いてから実装する** (テストファースト)。

### 施策 1 (A): `config/testing.php` の新設 (fake externals 基盤の正式導入)

```php
return [
    // 外部サービス (Stripe 等) を fake 化する capability flag。
    // .env.bughunt.local が true にする。production では ProductionEnvGuard が拒否する。
    'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false),
];
```

**影響範囲の明示**: この config 新設により、既存 `BughuntOAuthSeeder` の第 1 ガード
(`config('testing.fake_externals') === true`) が bughunt 環境で成立し始める
(現在は常に skip)。これは同 seeder の docblock が「外部 fake 基盤導入後に有効化される」と
明記する**意図された挙動**であり、同 seeder は三重 guard (fake_externals ∧ bughunt.local ∧
DB 名 `^bug_hunt(_[1-8])?$`) により bughunt DB の外では引き続き no-op。
guard の no-op 側 (testing env では実行されない) を固定する回帰テストを本設計で追加する。
flag の分離 (`bughunt_seed_oauth` 等) は config 面の増設に見合う必要が無く行わない。

### 施策 2 (A): サブスク checkout / portal の gateway 抽象化 (継ぎ目の新設)

既存の `TicketCheckoutGateway` / `CashierTicketCheckoutGateway` の流儀に合わせ、
`SubscriptionCheckoutGateway` interface + `CashierSubscriptionCheckoutGateway` 実装を新設し、
`BillingController::checkout()` / `portal()` の Cashier 直呼び部分を移す
(ロジックは移動のみ。挙動不変のリファクタ)。`AppServiceProvider` で bind する。

責務境界 (型で固定):

- gateway は **専用 DTO `ExternalBillingRedirect` (readonly `string $url`) を返す**。
  Laravel Response や生 string を返さない (fake/real 実装の戻り値ブレを型で禁止)。
  DTO コンストラクタで `Assert::stringNotEmpty($url)` (空 URL の意味的不正を fail-fast)
- `Inertia::location()` (外部遷移 Response 化) は **Controller 側に固定**
- 副次効果: これまで Stripe 未到達でテスト不能だった checkout / portal の happy path が
  fake bind でテスト可能になる (テスト資産の純増)

### 施策 3 (A): `FakeExternalsServiceProvider` の新設 (fake の配線 + production ガード)

`bootstrap/providers.php` に `AppServiceProvider` **より後** に登録し (後勝ち rebind)、
`register()` で以下の条件が全て成立するときのみ fake を bind する:

- `config('testing.fake_externals') === true`
- `app()->environment()` が **allowlist** (`local` / `testing` / `bughunt.local`) に含まれる
  (「production でない」の denylist ではなく allowlist で fail-secure に倒す。
  staging 等の未知環境で flag が誤設定されても fake しない)
- flag=true だが allowlist 外の場合は `Log::warning` で検出可能にする (bind はしない)

bind 対象 (runtime fake は `app/Services/Billing/Fakes/` に新設。テスト専用 spy である
`Tests\Support\FakeTicketCheckoutGateway` は record/failure-injection 用としてそのまま残す):

- `TicketCheckoutGateway` → `FakeTicketCheckoutGateway`
- `SubscriptionCheckoutGateway` → `FakeSubscriptionCheckoutGateway`

**fake の契約 = 「外部ステップを skip した中立帰還」** (決済成功とも中断とも解釈させない):

- 決定論 session id (`cs_bughuntfake_{attempt token}` 等) を返し、遷移先 URL は
  アプリ内の帰還画面 (チケット: `billing.tickets.show`、サブスク: cancel_url = `billing.index`、
  portal: return_url) に観測用 query marker `fake_external=stripe` を付けた URL とする。
  アプリはこの query を一切解釈しない (= `purchased=1` を付けず購入完了の偽装をしない・
  cancel の意味付けもしない)。決済・付与・subscription 状態の変更は一切行わない
  (走行状態の正本は施策 4 の seeder)
- 製品ルートは増やさない (bughunt 都合の専用 route を作らない)

さらに `ProductionEnvGuard::violations()` に「production で `testing.fake_externals` が true なら
違反」を追加する (deploy 時 fail-fast。DEBUG_LOGIN_* と同じ路線)。

### 施策 4 (B): `BughuntBillingSeeder` の新設 (standard 組織のみ active subscription + 初期チケット)

`ManualTestSeeder` は dev の手動テストでも使われるため触らず、`BughuntOAuthSeeder` と同じ
**三重 fail-secure guard** (fake_externals === true ∧ env=bughunt.local ∧ DB 名 `^bug_hunt(_[1-8])?$`)
を持つ専用 seeder を新設し、`scripts/bug-hunt-shard.sh` の provision / reseed の seed 列に追加する:

- **有料プラン組織 (plan の base price が存在する組織 = standard) のみ**を対象に:
  - active な `default` subscription 行を冪等付与 (決定論 `stripe_id` 例: `sub_bughunt_{org id}`。
    Stripe には到達しない。作成 payload は private helper に集約し、billable relation
    `$organization->subscriptions()->create()` 経由で organization_id は FK 自動設定)
  - 初期チケットを冪等付与 (`TicketLedgerService::grantMonthly()` を冪等キー
    `bughunt:initial-grant:{org id}` で呼ぶ。枚数は固定 100 = S3 の解析/レンダ探索に十分な決定論値)
- **free 組織には何も付与しない**: free/standard の差分 (課金ゲート・残高ゼロ経路) を
  bug-hunt 環境内に温存し、課金系バグの検出能力を落とさない

これで standard 組織の `/projects` `/app` 等が bughunt で走行可能になり (F-07 の環境要因解消)、
チケット消費系ジャーニー (解析・レンダ) も残高ゼロで詰まない。free 側の billing redirect は
仕様どおり残る (G1 の検証対象として観測可能なまま)。

### 施策 5 (C): `AdminUserSeeder` の bughunt.local 対応 (DB 名 guard 付き)

guard を「`local` は従来どおり無条件 / **`bughunt.local` は接続 DB 名が
`^bug_hunt(_[1-8])?$` に一致する場合のみ**」に拡張する。`APP_ENV=bughunt.local` が誤って
dev DB を向いた場合でも既知資格情報の admin を dev DB に作らない (bughunt seeder 群の
fail-secure 思想と強度を揃える)。DB 名 regex 判定は `BughuntOAuthSeeder` と重複するため
共通ヘルパに集約し、両 seeder から参照する。production/staging/CI の防御は不変。

### 施策 6 (C): provision への Filament アセット publish 追加 (冪等)

`scripts/bug-hunt-shard.sh` に `ensure_filament_assets()` helper を新設し
`cmd_provision()` の seed ブロック直後から呼ぶ:

- skip 条件は「composer.lock の filament/filament バージョンが marker ファイル
  (`public/js/filament/.bughunt-filament-version`) と一致 **かつ** 必須アセット
  (`public/js/filament/filament/app.js`・`public/css/filament/filament/app.css`) が実在」
  (marker だけの判定は部分生成・削除を fresh と誤判定するため不可)。
  marker は `filament:assets` の**成功後にのみ**書き込む (失敗時は marker を残さず次回再実行)
- skip 不成立時のみ `artisan_for_shard "${db}" "${url}" filament:assets` を実行
  (既存の用途別 wrapper 経由 = 生 artisan 禁止規約を維持。DB には触れないコマンドだが
  env -i + bughunt env で実行して dev env の混入を防ぐ)
- 並列 fan-out (`provision-all`) は shard 1..N を**直列ループ**で provision するため
  同時書き込み race は現行構造では発生しない。将来 provision を並列化する場合は
  本 helper を worktree 単位の事前フェーズへ移す (設計注記として明記)
- `cmd_assets_check` (read-only ゲート) と self-test のフィクスチャは変更しない
  (self-test の pass を維持)

## 期待効果

- **使命への貢献**: North Star フロー (SOP→AI カット設計→撮影→レンダ) を bug-hunt が
  端から端まで実走できるようになり、S3/S7 の未走行領域 (manual/cut/take/IDOR 検証) の
  発見能力が回復する。F-05/F-13 という「環境ノイズの Critical」が消え、以後の run の
  finding が製品の実バグに集中する。
- free/standard の差分温存により、課金ゲート・残高ゼロ経路の探索能力は維持される。
- fake externals 基盤 (config/testing.php) の正式導入により、既存の `BughuntOAuthSeeder` が
  設計どおり動き出す (OAuth/CLI セッション系カバレッジの回復)。
- サブスク checkout/portal の gateway 抽象化により、従来テスト不能だった happy path に
  テストが付く (テスト資産の純増)。
- production への安全性はむしろ向上する (ProductionEnvGuard に fake flag 検査を追加)。

## 実装方針（概要）

| # | 群 | 変更 | ファイル |
|---|----|------|---------|
| 1 | A | config 新設 | `config/testing.php` (新規) |
| 2 | A | サブスク gateway 抽象 + DTO | `app/Services/Billing/SubscriptionCheckoutGateway.php` (新規 interface)、`app/Services/Billing/CashierSubscriptionCheckoutGateway.php` (新規)、`app/DataTransferObjects/Billing/ExternalBillingRedirect.php` (新規 DTO)、`app/Http/Controllers/Billing/BillingController.php`、`app/Providers/AppServiceProvider.php` (bind 追加) |
| 3 | A | fake 配線 + production guard | `app/Providers/FakeExternalsServiceProvider.php` (新規)、`app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php` (新規)、`app/Services/Billing/Fakes/FakeSubscriptionCheckoutGateway.php` (新規)、`bootstrap/providers.php`、`app/Support/ProductionEnvGuard.php` |
| 4 | B | bughunt seed (standard のみ) | `database/seeders/BughuntBillingSeeder.php` (新規)、`scripts/bug-hunt-shard.sh` (provision / reseed の seed 列に追加) |
| 5 | C | admin seeder | `database/seeders/AdminUserSeeder.php` (guard 拡張) |
| 6 | C | filament assets (冪等) | `scripts/bug-hunt-shard.sh` (`ensure_filament_assets` + `cmd_provision`) |

テスト (Pest / RefreshDatabase グローバル。各施策とも fail するテストを先に置く):

- `FakeExternalsServiceProviderTest` (新規): flag=true ∧ allowlist env で fake が解決される /
  production env では flag=true でも実装のまま (warning ログ) / flag=false は no-op
- `ProductionEnvGuardTest` (既存に追記): production ∧ fake_externals=true が violation になる
- `BughuntBillingSeederTest` (新規): guard 不成立 (testing env / 非 bughunt DB) で no-op、
  付与ロジックが standard 組織のみに active sub + チケット 100 を冪等付与 (再実行で増えない・
  free 組織に付与しない)
- `BughuntOAuthSeeder` の guard no-op 回帰テスト (testing env では実行されない) を追加
- `AdminUserSeederTest` (既存に追記): bughunt.local ∧ bug_hunt DB 名で作成される
  (`DB::connection()->setDatabaseName()` で DB 名のみ差し替えて検証) /
  bughunt.local ∧ 非 bughunt DB 名では no-op (dev DB 防御)
- marker query 非解釈の固定: `billing.tickets.show?fake_external=stripe` で purchased
  バナーが出ない (purchased=false) ことを検証
- `BillingPageTest` (既存に追記) または新規: fake `SubscriptionCheckoutGateway` bind での
  checkout / portal happy path (`Inertia::location` の遷移先 = DTO url の検証)
- 既存テスト (TicketCheckoutTest 等) は無変更で green を維持
- `scripts/bug-hunt-shard.sh self-test` の pass 維持 (フィクスチャ非接触)

## 制約・前提

- **製品の課金ロジック不変**: `BillingAccess` / webhook 冪等マシン / チケット台帳
  (reserve→commit/release) / plan_code 同期には触れない。free ゲートの製品仕様 (G1) は別設計。
- **fake は絶対に production に漏らさない**: allowlist env 判定 + ProductionEnvGuard の
  二重防御。fake 実装は「付与しない・偽装しない」(中立帰還) に徹し、fake 経由で
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
