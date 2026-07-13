# 概念設計: seeder-free-plan-billing

## 背景・課題

bug-hunt finding **F-C3 (Critical)** (統合前は shard1 F-00d/High + shard3 F-3-03/Critical)。

**症状**: Free プラン組織の全ユーザー (owner-free / admin-free / member-free) が `/projects` 等の
課金ゲート対象ルートで `/billing` へ強制リダイレクトされ、「サブスクリプションのお支払いが
確認できないため、ご利用を一時停止しています」という**誤った遮断メッセージ**で中核機能
(プロジェクト管理・撮影 PWA) が完全に使用不能になる。

**根本原因**: `database/seeders/ManualTestSeeder.php` の `createOrganization()` が、
Stripe Price を持たない **Free プランに対してまで** `forceFill(['plan_code' => 'free'])->save()`
してしまう。一方 `app/Services/Billing/BillingAccess.php` の entitlement 契約は:

- `plan_code === null` (未契約 = free tier) → **無条件で許可**
- `plan_code !== null` (有償プラン契約状態) → active/trialing な Stripe subscription を要求。
  行不在は fail-closed で**不許可**

Free 組織には subscription 行が存在しないため、`plan_code = 'free'` が載った瞬間に
「有償契約中だが支払い不健全」と誤判定され、fail-closed で締め出される。

**これは regression**。`devnotes/20260712-0927-bugfix-billing-free-access` で BillingAccess を
「plan_code null = free tier 許可」へ書き換えた際、その前提となる**不変条件**
(`organizations.plan_code` は Stripe Price を持つ有償プランの契約時のみ set される。
Price を持たない free が plan_code に載る経路はない) に **seeder が追従していなかった**。
`PlanSeeder` / `StripeWebhookProcessor` / `Organization` model / `BillingAccess` の docblock は
すべてこの不変条件を明記しているが、`ManualTestSeeder` だけが `$plan->code` を無差別に
`plan_code` へ書き込むことで不変条件を破っていた。

## 改善アイデア

seeder が **plan_code の不変条件を尊重する**よう是正する。すなわち
「plan_code は Stripe Price を持つ有償プランの契約状態でのみ set される」を seeder でも守る。

1. **Free (Stripe Price を持たないプラン) は `plan_code` を null のままにする** (`forceFill` をスキップ)。
   これで Free 組織は BillingAccess の free-tier 分岐で正しく許可される。
2. **有償プラン (Stripe Price を持つプラン) には plan_code を set し、かつ fake active subscription を
   投入する**。現状の seeder は有償 (`standard`) 組織にも subscription 行を作らないため、
   owner-standard 等も同じ課金ゲートで締め出されている (Free ほど致命的ではないが、
   「各ロール×プランを手動テストできる」という seeder の役割を果たせていない潜在バグ)。
   plan_code と subscription を**セットで**投入することで不変条件 (plan_code 非 null ⇒ 契約行あり)
   を seeder レベルでも満たす。

**プラン名 (code) での分岐は書かない** (AGENTS.md ドメイン規約: 「コードにプラン名で分岐を書かない。
能力は値で表現する」)。「有償か否か」は **plan_prices に current な Stripe Price が存在するか**
(`$plan->currentPrice(PlanPriceKind::Base) !== null`) という**データの値**で判定する。これは
StripeWebhookProcessor が plan_code を set する条件 (Stripe Price → Plan 解決が成立する) と
同じ意味論であり、本番の webhook 経路と seeder の投入経路の意味を一致させる。

## 期待効果

- **使命への貢献**: AI-CUE の中核 (SOP → シナリオ → PWA 撮影) を担う `/projects`・`/app` 等の
  業務ルートが Free 組織で再び到達可能になり、手動テスト・bug-hunt 環境で中核機能を
  正しく検証できる状態に戻す。Critical な機能全損を解消する。
- **不変条件の一貫性回復**: 「plan_code 非 null ⇔ Stripe Price を持つ有償プランの契約行あり」を
  本番 (webhook)・テスト helper (`contractPaidPlan`)・seeder の三経路すべてで一致させ、
  同種 regression の再発を防ぐ。
- **手動テストデータの健全化**: Free・有償の両組織が期待どおりの entitlement 状態
  (Free = 素通り、有償 = active 契約で素通り) で投入され、全ロール×プランが実際に操作可能になる。

## 実装方針（概要）

- **`ManualTestSeeder::createOrganization()`**: `provision()` 後、`$plan` が current な base Price を
  持つ場合のみ `plan_code` を forceFill し、あわせて fake active Cashier subscription 行を
  `$organization->subscriptions()->create([...])` で投入する。Price を持たない (=Free) 場合は
  plan_code を null のまま (forceFill しない)。
- **`app/Services/Billing/BillingAccess.php`**: **変更なし**。既存の free-tier 判定 (plan_code===null 許可)
  は仕様どおり正しく機能しており、根本原因は seeder の不変条件違反にある。BillingAccess 側に
  「plan_code はあるが Price 無し = free」という防御ガードを足すと、(a) plan_code から Plan+prices を
  ロードする結合が増え、(b) 「plan_code 非 null ⇒ 有償契約」という単一の不変条件を二重定義する
  ことになり、AGENTS.md 禁止事項「やたらに複雑な案」に抵触する。不変条件は seeder を正すことで
  単一箇所で守るのが正道。ただし本判定が仕様どおりであることを回帰テストで固定する。
- **`app/Http/Middleware/RequireActiveSubscription.php`**: **変更なし** (BillingAccess に委譲済み。
  seeder 修正で正しい入力が入れば期待どおり素通しする)。

## 制約・前提

- `plan_code` は `$fillable` 外の状態キー。seeder では従来どおり `forceFill` で明示代入する。
- fake subscription は Cashier の `subscriptions` テーブル行を直接作成する
  (Stripe API には到達しない)。テストの `createFakeSubscription` helper と同じ構造。
- 「有償か否か」の判定に `$plan->code === 'free'` を使わない (プラン名分岐禁止)。
  `currentPrice(PlanPriceKind::Base)` の有無で判定する。
- seeder の冪等性 (再実行で増えない) を維持する。subscription 投入も冪等チェックの内側で行う。

## スコープ外

- BillingAccess / RequireActiveSubscription のロジック変更 (現行仕様が正しいため触らない)。
- 本番の Stripe webhook 経路・Checkout フローの変更。
- `PlanSeeder` / `config/quota.php` のプラン定義変更。
- Free プランに Stripe Price を持たせる方向の変更 (不変条件を崩すため採らない)。
