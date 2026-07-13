# 前提: アプリの使命・禁止事項・思考原則

## アプリの使命 (North Star / AGENTS.md より)
**AI-CUE** は現場の作業手順書(SOP)を起点に AI が動画シナリオを生成し、スマホ(PWA)ナビ撮影で
標準化マニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。標準作業起点で AI が教材設計。SECI 変換装置。
v1: 字幕のみ / PWA(同一オリジン・セッション認証) / 自前 ffmpeg / 単一 Default Project。

## 禁止事項 (AGENTS.md より)
1. テストなしの実装完了報告 / 2. PHPStan widen・baseline 化 / 3. dev DB 破壊操作 /
4. `response()->json()` 直書き / 5. Prism 直呼び / 6. prompt 直書き /
7. 操作系 POST での `redirect()->intended()` / 8. 必須未充足でボタン disabled。

## ドメイン規約 (関連)
- コードにプラン名 (code) で分岐を書かない。能力は値で表現する。
- `organizations.plan_code` は Stripe Price を持つ有償プラン契約時のみ set (null = 未契約 = free tier)。
- テストは Factory 生成 / RefreshDatabase グローバル / 個別 DatabaseTransactions 禁止 / PHPStan level 10。

## 思考原則
仮説を立てろ。先人の知恵 (Laravel/Cashier) を探せ。仕組みが機能していない段階で値を弄るな。

## ツール使用制限
コマンド実行・ファイル書き込みは行わず、テキスト分析に集中。ファイル読み込みは許可。

---

# system: レビュアーの役割
あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript + Cashier / PHPStan level 10 /
Pest / DTO + JsonResource / Laratrust RBAC (Organization → Team → Project)。

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性 (命名規約、パターン、API)
3. PHPStan level 10 適合性 (型安全性、generics、Assert 使用)
4. テスト計画の網羅性 (各施策に Pest テスト、RefreshDatabase グローバル)
5. DTO/JsonResource パターン遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性 (TS 型定義、API Resource、テストが変更対象に含まれるか)
9. セキュリティ (認可、入力バリデーション、OWASP、AGENTS.md セキュリティ不変条件)
10. DESIGN.md 準拠 (UI 変更を含む場合) — 本設計は UI 変更なし
11. Atomic Design 準拠 (UI 変更を含む場合) — 本設計は UI 変更なし

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

# user: 詳細設計書 + 関連現行コード

## 関連する現行コード

### app/Services/Billing/BillingAccess.php (変更しない)
```php
public function hasActiveAccess(Organization $organization): bool
{
    if ($organization->plan_code === null) {
        return true; // free tier
    }
    $subscription = $organization->subscription('default');
    return $subscription !== null
        && in_array($subscription->stripe_status, ['active', 'trialing'], true);
}
```

### database/seeders/ManualTestSeeder.php (現行 createOrganization、バグ箇所)
```php
private function createOrganization(User $owner, Plan $plan): Organization
{
    $organization = app(OrganizationProvisioningService::class)
        ->provision($owner, "{$plan->name}プラン組織");
    $organization->forceFill(['plan_code' => $plan->code])->save();
    return $organization;
}
```
- run() 内で冪等 guard あり (最初のプランの Owner 存在で skip)。foreach ($plans as $plan) の最初の
  role で createOrganization、以降 addToOrganization。

### app/Models/Billing/Plan.php
```php
public function currentPrice(PlanPriceKind $kind): ?PlanPrice
{
    return $this->prices()->where('kind', $kind->value)->where('is_current', true)->first();
}
```
- PlanSeeder: free は plan_prices を持たない (Price 無し)。standard は base Price を bootstrap 投入。

### tests/Pest.php の既存 helper (参考: 同じ subscription 生成経路)
```php
function createFakeSubscription(Organization $organization, string $status = 'active', string $type = 'default'): Subscription {
    return $organization->subscriptions()->create([
        'type' => $type,
        'stripe_id' => 'sub_test_'.Str::random(24),
        'stripe_status' => $status,
        'quantity' => 1,
    ]);
}
```

### app/Enums/OrganizationRole.php
```php
enum OrganizationRole: string {
    case Owner = 'organization_owner';
    case Admin = 'organization_admin';
    case Member = 'organization_member';
}
```

### tests/Feature/Database/ManualTestSeederTest.php (現行アサーション、施策2で更新)
```php
foreach (Plan::query()->orderBy('sort_order')->get() as $plan) {
    $organization = Organization::query()->where('name', "{$plan->name}プラン組織")->first();
    expect($organization?->plan_code)->toBe($plan->code); // ← バグ挙動を固定
    ...
}
```

## 詳細設計書 (全文)

（以下、detailed-design.md の本文を参照。要旨を転記）

### 施策1: ManualTestSeeder::createOrganization() を書き換え
- provision 後、$plan->currentPrice(PlanPriceKind::Base) !== null (有償) のときのみ plan_code を
  forceFill し、attachFakeActiveSubscription() で active subscription 行を投入。
- Free (Price 無し) は plan_code null のまま (forceFill しない)。
- 有償判定は Plan の値 (currentPrice) から導出。$plan->code の文字列比較はしない。
- attachFakeActiveSubscription(): $organization->subscriptions()->create(['type'=>'default',
  'stripe_id'=>'sub_seed_'.Str::random(24), 'stripe_status'=>'active', 'quantity'=>1])。
- import 追加: PlanPriceKind。冪等 guard の内側で 1 回のみ実行。

### 施策2: ManualTestSeederTest 更新
- plan_code アサーションを Free/有償で分岐: 有償 (currentPrice(Base)!==null) は plan_code=code +
  subscription active + hasActiveAccess true。Free は plan_code null + subscription null +
  hasActiveAccess true。
- $organization を firstOrFail() で non-null narrow。import: PlanPriceKind, BillingAccess。
- 既存の複数組織・冪等テストは変更なし。

### 施策3: 新規 tests/Feature/Billing/SeededFreePlanBillingAccessTest.php
- ManualTestSeeder を seed。Free 組織の owner/admin/member (dataset) が GET /projects で assertOk、
  billing へ redirect されない。plan_code null を確認。有償組織は plan_code + active subscription 確認 +
  owner が /projects 到達。
- firstOrFail で narrow。クロージャに型注釈。?? throw で non-null 確定。

### BillingAccess / RequireActiveSubscription は変更しない (根本原因は seeder の不変条件違反)。

### 実装モード: standalone (seeder 1 + テスト 2 に閉じる 1 論理単位)。

---

上記の詳細設計を、正確性・PHPStan level 10 適合・テスト網羅性・副作用・波及変更・既存整合の観点で
レビューし、施策別判定と全体判定を出してください。特に (a) 冪等性 (再 seed で subscription 重複しないか)、
(b) currentPrice(Base) 判定の妥当性、(c) 既存テスト是正が「上書き禁止」に抵触しないか、
(d) 回帰テストが修正前 fail・修正後 pass として機能するか、を重点的に確認してください。
