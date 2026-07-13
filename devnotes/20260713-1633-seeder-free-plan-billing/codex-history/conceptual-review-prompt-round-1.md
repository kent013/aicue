# 前提: アプリの使命・禁止事項・思考原則

## アプリの使命 (North Star / AGENTS.md より)

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、
そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化された
マニュアル動画を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 (AGENTS.md より)

1. テストなしの実装完了報告
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

## ドメイン規約 (AGENTS.md より、本設計に関連)

- コードにプラン名 (code) で分岐を書かない。能力は monthly_ticket_grant と config/quota.php の
  limits の「値」で表現する。
- `organizations.plan_code` は Stripe Price を持つ有償プランの契約 (active/trialing) 時のみ set され、
  subscription.deleted で null に戻る。null = 未契約 = 支払い不要の free tier。
- 課金の冪等性、tenant キー不信などのセキュリティ不変条件。

## 思考原則

まず仮説を立てろ。ユーザー視点で考えろ。先人の知恵 (Laravel/Cashier) を探せ。
機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: レビュアーの役割

あなたは Web アプリケーション (Laravel + Svelte) の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命 (North Star) に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か (Laravel 12 + Svelte 5 + Inertia.js + Cashier)
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

# user: 概念設計

（以下は本レビュー対象の概念設計書。関連する現行コードの要点も併記する）

## 関連する現行コードの要点

### app/Services/Billing/BillingAccess.php (現行・変更しない想定)
```php
public function hasActiveAccess(Organization $organization): bool
{
    // 未契約 (plan_code null) = fallback free プラン。支払い不要 tier として許可
    if ($organization->plan_code === null) {
        return true;
    }
    // 有償プラン契約状態: 支払い健全性 (active/trialing) を要求。行不在も fail-closed で不許可
    $subscription = $organization->subscription('default');
    return $subscription !== null
        && in_array($subscription->stripe_status, ['active', 'trialing'], true);
}
```

### database/seeders/ManualTestSeeder.php::createOrganization() (現行・バグ箇所)
```php
private function createOrganization(User $owner, Plan $plan): Organization
{
    $organization = app(OrganizationProvisioningService::class)
        ->provision($owner, "{$plan->name}プラン組織");
    $organization->forceFill(['plan_code' => $plan->code])->save(); // ← Free にも plan_code='free' を載せてしまう
    return $organization;
}
```

### app/Models/Billing/Plan.php (currentPrice で有償判定に使う)
```php
public function currentPrice(PlanPriceKind $kind): ?PlanPrice
{
    return $this->prices()->where('kind', $kind->value)->where('is_current', true)->first();
}
```

### PlanSeeder: free プランは plan_prices を持たない (Stripe Price 無し)。standard は base Price を持つ。

## 概念設計書

（本文は conceptual-design.md を参照。要旨を以下に転記）

【背景】bug-hunt F-C3 (Critical)。ManualTestSeeder が Free プランにも plan_code='free' を forceFill する
ため、Free 組織が課金ゲートで /billing へ誤リダイレクトされ中核機能が全損。plan_code 非 null は
BillingAccess が active subscription を要求するが Free には subscription 行が無く fail-closed。
これは devnotes/20260712-0927 の BillingAccess 書き換えに seeder が追従しなかった regression。

【修正方針】
1. ManualTestSeeder::createOrganization() で、plan が current な base Price を持つ有償プランのときのみ
   plan_code を set し、かつ fake active Cashier subscription を投入する。Price を持たない Free は
   plan_code を null のまま (forceFill しない)。有償/Free の判定は $plan->currentPrice(Base)!==null という
   「データの値」で行い、$plan->code==='free' のプラン名分岐はしない。
2. BillingAccess は変更しない (free-tier 判定は仕様どおり正しい。防御ガード追加は結合増と不変条件の
   二重定義になり「複雑な案」に抵触するため採らない)。回帰テストで固定する。
3. RequireActiveSubscription も変更しない。

【期待効果】Free 組織で /projects・/app 等が再到達可能に。plan_code 非 null ⇔ 有償契約行あり の不変条件を
本番(webhook)・テストhelper(contractPaidPlan)・seeder の三経路で一致させ再発防止。

【テスト計画(概要)】ManualTestSeeder を seed し、free 組織 owner/admin/member が /projects に到達できる
(billing へ redirect されない) Feature 回帰テスト。ManualTestSeederTest の plan_code アサーション更新
(free→null, 有償→code かつ subscription あり)。

【スコープ外】BillingAccess/Middleware のロジック変更、本番 Stripe 経路、Free に Price を持たせる変更。

---

上記の概念設計をレビューし、判定と指摘を出してください。
