# design-review Round 3: Round 2 残課題への対応報告

Round 2 の残 1 点 (有償プランの current base Price 不変条件を、判定式に依存しない独立テストで固定) に
対応しました。施策4 を新設しています。

## [Warning] 施策2 の drift 検知が同一判定式依存で成立しない — 施策4 新設で対応

施策2 は分岐選択と期待値がともに `currentPrice(Base)` に依存するため単独では drift を検知できない、
というご指摘を受け、**判定式に依存しない独立テスト**を新規追加しました。

### 施策4 (新規): tests/Feature/Billing/PlanSeederPriceInvariantTest.php
```php
<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;

test('有償プラン standard は current base Price を持つ (seed 不変条件)', function (): void {
    $standard = Plan::query()->where('code', 'standard')->firstOrFail();
    expect($standard->currentPrice(PlanPriceKind::Base))->not->toBeNull();
});

test('free プランは Stripe Price を持たない (Checkout 対象外の未契約既定)', function (): void {
    $free = Plan::query()->where('code', 'free')->firstOrFail();
    expect($free->currentPrice(PlanPriceKind::Base))->toBeNull();
    expect($free->prices()->count())->toBe(0);
});
```

- プラン名 (`'standard'` / `'free'`) を直接参照するが、これは**本番コードの能力分岐ではなく
  seed fixture 仕様の検証**であり、「code で能力分岐禁止」の規約 (本番ロジック向け) には抵触しない
  (Round2 でご容認いただいた点)。
- PlanSeeder は TestCase `$seed=true` で自動実行されるため明示 seed 不要。RefreshDatabase グローバル、
  個別 DatabaseTransactions 不使用。
- 施策1 のリスク節の記述も「施策4 が独立検証する」に更新済み。

施策1・施策3 は Round2 で APPROVE 済みのため変更していません。

---

以上で全 Critical/Warning が解消されたか確認し、全体判定をお願いします。
