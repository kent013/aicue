# 概念設計: bugfix-billing-free-access (F-07: Free 課金ゲート矛盾の解消)

## 背景・課題

bug-hunt finding **F-07 (Critical)** — `devnotes/20260712-075854-bug-hunt/shard-0/shard-report.md` 参照。

### 症状

Free プラン (= 未契約) の組織で `/projects`・`/projects/create`・`/app` (撮影 PWA) 等の
業務ルートにアクセスすると、`require-active-subscription` middleware が
**理由の説明なく `/billing` へサイレントリダイレクト**し、プロジェクト作成に一切到達できない。

- LP (`Welcome.svelte`) は「Free プランで今すぐ試せます」「無料で始める」、
  料金表 (`Pricing.svelte`) は「Free プランは基本料金なしでご利用いただけます」と謳っており、
  **マーケティング文言と実挙動が真っ向から矛盾**している。
- `/billing` 上では「現在のプラン: Free」と表示されるため、ユーザーは
  「契約済みなのになぜ billing に戻されるのか」を理解できない (H1: 説明なしリダイレクト)。
- F-05 (Stripe checkout 500 — 別 TODO) と合わさると、**全アカウントが永久にプロジェクトを
  作成できない詰み**になる (H2: 詰み)。

### 根本原因

テンプレート既定の `BillingAccess::hasActiveAccess()` は
「Cashier `subscription('default')` が active / trialing」のときのみ true を返す最小実装
(未契約は fail-closed)。一方 AI-CUE のプラン設計では:

- `PlanSeeder`: **free プランは Stripe Price を持たない (Checkout 対象外。未契約の既定)**
- `config/quota.php`: `fallback_plan = 'free'` — `plan_code` null (未契約) の組織は
  free プランの limits (max_projects=1, max_members=3, storage 1GiB) が適用される
- `organizations.plan_code` は **webhook 同期でのみ** 書かれる状態キー
  (有償プラン契約時に set、`customer.subscription.deleted` で null に戻る)

つまり **AI-CUE の「Free 組織」は構造的に Cashier subscription を持たない**ため、
テンプレート既定の判定では業務ルートから恒久的に締め出される。
「Free で試せる」という v1 プロダクト方針 (AGENTS.md v1 スコープ / doc/01 / LP 文言) と
課金ゲートの判定基準が乖離していることが本質的な原因。

## 改善アイデア

### 設計原則: 「業務ルートの利用権 (entitlement)」と「有償リソースの消費」を分離する

AI-CUE の課金モデルでは、有償価値はすでに別レイヤで gate されている:

1. **チケット消費** (AI 解析 = analyze / レンダ = render): `AnalysisJobService` 等が
   残高事前チェック (`InsufficientTicketsException`) + reserve→commit/release の 2 フェーズで gate 済み
2. **多次元 Quota** (max_projects / max_members / max_storage_bytes): `QuotaService` が
   plan_code (null は fallback=free) の limits で gate 済み

したがって業務ルート自体を subscription の有無で塞ぐ必要はなく、
**課金ゲートの役割は「有償プランを契約している組織の支払い健全性の担保」に限定**するのが正しい。

### 変更の中核: `BillingAccess::hasActiveAccess()` を entitlement 判定に書き換える

テンプレートの `BillingAccess` docblock が明示的に想定している拡張ポイント
(「アプリ側は本クラスの書き換えだけで gate 方針を変更できる (例: entitlement 導出への差し替え)」)
をそのまま使う。判定を次のように変更する:

```
hasActiveAccess(Organization):
    plan_code === null (未契約 = fallback free プラン)
        → true   (free tier のベースライン利用を許可)
    plan_code !== null (有償プラン契約状態)
        → subscription('default') が active / trialing のときのみ true
          (従来どおり fail-closed。past_due / canceled / incomplete / 行不在は false)
```

- **プラン名 (code) での分岐を書かない**規約を守る: 判定材料は「有償プラン契約状態か
  (plan_code の null / 非 null)」と「subscription status」のみ。`plan_code` は
  webhook が有償プラン契約時にのみ set する状態キーなので、
  「null = 支払い不要の free tier」はデータモデル上の定義そのもの。
- **課金基盤の不変条件は維持**: 判定は引き続き BillingAccess 単一根拠
  (middleware / controller / service での subscription 直参照禁止)。
  `RequireActiveSubscription` middleware・route group 構成・構造的 allowlist は変更しない。

### ライフサイクル整合 (webhook との噛み合わせ)

| 状態 | plan_code | subscription | 判定 | 挙動 |
|------|-----------|--------------|------|------|
| 新規登録 / Free | null | なし | 許可 | 業務ルート利用可 (quota=free, チケットは残高 gate) |
| 有償契約 (active/trialing) | set | active/trialing | 許可 | フル利用 |
| 支払い遅延 (past_due 等) | set (維持) | past_due 等 | 遮断 | billing へ redirect + **理由 flash** |
| 解約完了 (subscription.deleted) | null (webhook が解除) | canceled | 許可 | free tier へ自然降格 (quota fallback) |
| webhook 順序逆転 (plan_code set・subscription 行なし) | set | なし | 遮断 | fail-closed 維持 |

解約 → free 降格 → 利用継続、という一貫したストーリーになり、
「Free で始めて必要になったら有償化」というマーケ文言と完全に整合する。

### 新規不変条件の明文化 (Codex Round 1 Warning 反映)

本判定は「**`organizations.plan_code` は有償プラン (Stripe Price を持つプラン) の契約時のみ
webhook が set する状態キーであり、支払い不要のプランは plan_code に載せない
(null = 支払い不要 tier)**」というデータモデル契約に依存する。これを暗黙にせず:

- `BillingAccess` / `StripeWebhookProcessor` / `PlanSeeder` の docblock と
  `docs/architecture.md` に不変条件として明記する
- Feature テストで両側 (plan_code null → 許可 / plan_code set + subscription 不健全・行不在 → 遮断)
  を固定する

将来「請求不要の特別プラン」を plan_code に載せたくなった場合は、この不変条件と
BillingAccess の判定をセットで見直す (その検出をテストが担う)。

### UX: 残る遮断ケースに理由 flash を明示 (禁止事項 8 / H1 対応)

遮断が残るのは「有償プラン契約中に支払いが不健全」のケースのみ。このとき middleware の
ブラウザ向け redirect に error flash を付与する。
`HandleInertiaRequests` が `flash.error` を共有済みなので、既存の flash-to-toast 基盤に乗るだけで表示される。
既存の `session()->reflash()` (直前 hop の flash 延命) はそのまま維持する。

**HTML / JSON の両経路で理由の意味論を統一する** (Codex Round 1 Warning 反映):
遮断理由は middleware 内の単一定数
「サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。」
に寄せ、ブラウザは flash.error、JSON/XHR は 402 の message として同一文言を返す
(現行の「有効なサブスクリプションがありません」は free 組織を誤解させる文言のため廃止)。
両経路とも Feature テストで文言・挙動を固定する。

### 波及: ダッシュボードの課金 callout の整合

`DashboardService::billingSummary()` → `BillingSummaryData::hasActiveSubscription`
(`has_active_subscription`) は `BillingAccess::hasActiveAccess` をそのまま流しており、
現状 Free 組織のダッシュボードに「有効なサブスクリプションがありません。プランを契約すると、
マニュアルの作成・撮影を再開できます」という**誤った callout** が常時出る (F-07 と同根の矛盾)。

- 判定変更により Free 組織では自動的に callout が消え、
  遮断対象 (有償プラン支払い不健全) のみに出るようになる — callout 文言はそのケースに正しく適合する。
- フィールド名 `has_active_subscription` は新セマンティクス (「課金ゲートを通過できるか」)
  に対して嘘になるため、`has_billing_access` へリネームする
  (DTO / DashboardService / TS 型 / Dashboard.svelte / 両テストの機械的リネーム。
  後方互換の並走は残さない)。

### マーケ文言との整合確認

`Welcome.svelte` / `Pricing.svelte` の「Free で始められる」文言は本修正後に事実となるため
**文言変更は不要** (確認のみ)。

## 期待効果

- **使命への貢献**: North Star フロー (SOP → AI カット設計 → 撮影 → 完成動画) の入口
  (プロジェクト作成 → マニュアル作成 → 撮影 PWA) が Free 組織で開通し、
  「Free で試せる」オンボーディングが成立する。F-07 (Critical) の解消。
- 説明なしリダイレクト (H1) の解消: 遮断が残る唯一のケースに理由 flash を明示。
- ダッシュボードの誤 callout 解消 (Free 組織での「サブスクリプションがない」表示)。
- 課金ゲートの意味論が「支払い健全性の担保」に純化され、webhook ライフサイクル
  (契約 → 遅延 → 解約 → free 降格) と一貫する。

## 実装方針（概要）

| # | 変更 | ファイル |
|---|------|---------|
| 1 | entitlement 判定への書き換え | `app/Services/Billing/BillingAccess.php` |
| 2 | 遮断時 redirect に理由 flash 付与 + docblock 更新 | `app/Http/Middleware/RequireActiveSubscription.php` |
| 3 | ダッシュボード DTO フィールドのリネーム (`has_billing_access`) | `app/DataTransferObjects/Dashboard/BillingSummaryData.php`, `app/Services/Dashboard/DashboardService.php`, `resources/js/types/dashboard.ts`, `resources/js/pages/Dashboard.svelte` |
| 4 | テスト更新 + 追加 | `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`, `tests/Feature/DashboardTest.php`, `tests/js/pages/Dashboard.test.ts`, `tests/Pest.php` (有償契約状態ヘルパ) |
| 5 | ドキュメント/コメント同期 | `docs/architecture.md`, `docs/app-integration-guide.md`, `routes/web.php` コメント |

middleware のクラス名 / alias (`require-active-subscription`) は**変更しない**:
テンプレートの設計意図 (BillingAccess の書き換えのみで gate 方針を変更する) に沿った
最小差分とし、テンプレート更新の取り込み容易性を保つ。middleware は引き続き
「(有償プラン契約組織に) active な subscription を要求する」ものであり名前は実態と整合する。

### 受け入れ条件 (実装後の判定基準)

1. Free (未契約) 組織で `GET /projects`・`GET /projects/create` が 200、`POST /projects` で
   プロジェクト作成が成功し、`GET /app` が capture.manuals.index へ 302 する (billing への redirect ではない)
2. entitlement と resource gate の分離が保たれる: Free 組織でも Quota 超過
   (max_projects=1 の 2 個目) とチケット残高不足 (analyze/render) は従来どおり gate される
3. 有償プラン契約中 (plan_code set) の支払い不健全のみが遮断され、HTML は billing redirect +
   理由 flash、JSON は同一文言の 402 になる
4. Inertia payload key は `dashboard.billing.has_billing_access` に統一され、旧キーは残らない

### テスト計画 (概要)

- **再現テスト (fail first)**: 受け入れ条件 1 (未契約 free 組織の /projects・/projects/create 200、
  POST /projects 成功、/app 302→capture.manuals.index)
- 有償契約状態 (plan_code set) + past_due/canceled/incomplete/unpaid/**subscription 行なし** →
  billing redirect + error flash (文言固定) / JSON 402 (同一文言)
- 有償契約状態 + active/trialing → 200 (回帰なし)
- BillingAccess 単体: 上記マトリクスの網羅 (plan_code null / set × subscription status)
- Free 組織 (残高 0) の analyze が InsufficientTickets で gate される (既存テストの確認/補強)
- Free 組織の 2 個目プロジェクト作成が Quota で gate される (既存 Quota テストの確認)
- ダッシュボード: Free 組織で callout 非表示 / 有償支払い不健全で表示。
  payload key `has_billing_access` を Feature テストで固定、Svelte 側は JS テストで固定
- cross-org・binder 回帰の defense-in-depth (404) は既存テスト維持 (挙動不変)

## 制約・前提

- **BillingAccess 単一根拠** (middleware/controller/service での subscription 直参照禁止) を維持
- **プラン名 (code) 分岐禁止** 規約を維持 (判定は plan_code の null / 非 null と subscription status のみ)
- `plan_code` は $fillable 外の状態キー (webhook 同期のみ) — テストでは forceFill で設定
- 構造的 allowlist (billing / purchase-tickets / webhook / 組織管理系 / 通知が gate 外) は不変
- セキュリティ不変条件 (cross-org 404、binder 回帰 defense-in-depth、tenant キー不信) は挙動不変
- free の quota (max_projects=1 等) と signup grant チケットは既存機構のまま (変更しない)

## スコープ外

- **F-05 (Stripe checkout / portal の 500)**: 別 TODO。Stripe checkout 実装には触れない
- middleware クラス名 / alias のリネーム
- LP / Pricing の文言変更 (整合確認のみで変更不要)
- プラン設計 (quota 値・チケット単価) の調整
- TODO 登録・実装 (本フェーズは設計のみ)
