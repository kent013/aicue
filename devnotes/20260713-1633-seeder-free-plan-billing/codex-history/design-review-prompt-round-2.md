# design-review Round 2: Round 1 指摘への対応報告

Round 1 の指摘に対し詳細設計を更新しました。以下の対応で全 Critical/Warning を解消したか確認し、
再判定 (施策別 + 全体) をお願いします。

## [Critical] attachFakeActiveSubscription() の冪等化 — 対応済み
メソッド単体で冪等にしました (run() の冪等 guard に依存しない):
```php
private function attachFakeActiveSubscription(Organization $organization): void
{
    if ($organization->subscription('default') !== null) {
        return; // 冪等: 既存の default subscription を尊重する
    }

    $organization->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_seed_'.Str::random(24),
        'stripe_status' => 'active',
        'quantity' => 1,
    ]);
}
```

## [Warning] 有償プランの current base Price 前提の明文化 — 対応済み
施策1 リスク節に「有償プランは必ず current base Price を持つ」を seed データ不変条件として明記。
PlanSeeder が standard に base Price を bootstrap 投入して保証し、施策2 が standard 組織の
plan_code + active subscription を検証することで drift を検知する (base Price 欠落なら施策2 が fail)。
seeder への hard fail 追加は Critical 修正の責務外の over-engineering として見送り。

## [Warning] ManualTestSeederTest の isPaid 変数化 — 対応済み
```php
foreach (Plan::query()->orderBy('sort_order')->get() as $plan) {
    $organization = Organization::query()->where('name', "{$plan->name}プラン組織")->firstOrFail();
    $isPaid = $plan->currentPrice(PlanPriceKind::Base) !== null; // ループ先頭で 1 回算出
    if ($isPaid) {
        expect($organization->plan_code)->toBe($plan->code);
        expect($organization->subscription('default')?->stripe_status)->toBe('active');
    } else {
        expect($organization->plan_code)->toBeNull();
        expect($organization->subscription('default'))->toBeNull(); // free には契約行が無い
    }
    expect(app(BillingAccess::class)->hasActiveAccess($organization))->toBeTrue(); // 両 tier 共通
    // ... 既存のロール/current_organization/パスワード検証はそのまま
}
```

## [Warning] 施策3 で Inertia コンポーネント名を検証 — 対応済み
`assertOk()` に加え、コンポーネント名を検証して「200 だが別画面」を塞ぐ。実 component 名は
ProjectController@index の `Inertia::render('Projects/Index', [...])` で確認済み:
```php
$this->actingAs($user)->get('/projects')
    ->assertOk()
    ->assertInertia(fn ($page) => $page->component('Projects/Index'));
```

## [Suggestion] stripe_id 命名 — 見送り
`sub_seed_` prefix は seeder 由来と識別でき、テスト helper の `sub_test_` と区別できる方が保守的に良い
と判断し、揃えませんでした。

## [Suggestion] free 側 subscription 不在の明示検証 — 対応済み (施策2 に `->toBeNull()` を明示)。
## [Suggestion] laratrust_team_id 明示 — 既存 ManualTestSeeder / ManualTestSeederTest で担保済み。

---

以上の更新で残課題が無ければ全体 APPROVED をお願いします。追加の Critical/Warning があれば指摘してください。
