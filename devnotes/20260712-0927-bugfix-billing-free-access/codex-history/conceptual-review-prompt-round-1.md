【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

【役割】
あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足コンテキスト】
- 関連コード (必要なら読んでよい): app/Services/Billing/BillingAccess.php, app/Http/Middleware/RequireActiveSubscription.php, routes/web.php (L300-500), config/quota.php, database/seeders/PlanSeeder.php, app/Services/Billing/StripeWebhookProcessor.php (plan_code 同期), app/Services/Dashboard/DashboardService.php, app/DataTransferObjects/Dashboard/BillingSummaryData.php, resources/js/pages/Dashboard.svelte, tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php, tests/Pest.php
- 本設計は bug-hunt finding F-07 (Critical) への対応。レポート: devnotes/20260712-075854-bug-hunt/shard-0/shard-report.md

---

## 概念設計

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

### UX: 残る遮断ケースに理由 flash を明示 (禁止事項 8 / H1 対応)

遮断が残るのは「有償プラン契約中に支払いが不健全」のケースのみ。このとき middleware の
ブラウザ向け redirect に error flash を付与する
(例: 「サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。」)。
`HandleInertiaRequests` が `flash.error` を共有済みなので、既存の flash-to-toast 基盤に乗るだけで表示される。
既存の `session()->reflash()` (直前 hop の flash 延命) はそのまま維持する。
JSON/XHR の 402 応答は現行メッセージを維持する。

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

### テスト計画 (概要)

- **再現テスト (fail first)**: 未契約 (free) 組織が `/projects`・`/projects/create` に 200 到達、
  `POST /projects` でプロジェクト作成成功、`/app` が capture.manuals.index へ 302 (課金 redirect でない)
- 有償契約状態 (plan_code set) + past_due/canceled/incomplete/unpaid/行不在 → billing redirect + error flash / JSON 402
- 有償契約状態 + active/trialing → 200 (回帰なし)
- BillingAccess 単体: 上記マトリクスの網羅
- Free 組織 (残高 0) の analyze が InsufficientTickets で gate される (既存テストの確認/補強)
- ダッシュボード: Free 組織で callout 非表示 / 有償支払い不健全で表示 (`has_billing_access`)
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
