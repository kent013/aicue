<?php

declare(strict_types=1);

use App\Models\Project;
use Pest\Browser\Api\PendingAwaitablePage;

/*
|--------------------------------------------------------------------------
| ダッシュボード課金 callout の状態別出し分け (bug-hunt 20260811-003230 F-2-01)
|--------------------------------------------------------------------------
|
| 一度も契約していない組織 (NoSubscription) のダッシュボードに
| 「サブスクリプションのお支払いが確認できないため…」が出ていた。支払い失敗と同じ文言で、
| S1 登録ファネルを通る全新規ユーザーが最初に目にする。原因は props が
| hasBillingAccess の真偽値で「未契約」と「支払い不健全」を潰していたこと。
|
| 実ブラウザで再現された finding なので、実ブラウザで是正を固定する
| (Chromium + WebKit の 2 レーンが契約。docs/testing-browser.md)。
|
| テストは 1 本に統合する: 同一 fixture・同一画面を分けても検出力は変わらず、
| グローバルテストロック下の実行時間だけが増えるため。
|
| fixture 注意: createOrganizationWithOwner() は既定で free_plan_code='personal' を立て
| ActiveFreePlan (= callout なし) になる。grandfatherFreePlan: false が必須。
|
| 実行: composer test:browser。前提: pnpm build 済み。
*/

/**
 * Inertia のクライアント遷移が完了するまで待つ。
 *
 * `assertPathIs()` は現在 URL を**即座に**読むだけで待たないため、click 直後に呼ぶと
 * 遷移前の path を見て落ちる。`script()` も自動待機しないので、ここで明示的に回す
 * (script() 呼び出しが in-process サーバの event loop を回す = 他の Browser テストと同じ流儀)。
 */
function waitForPath(PendingAwaitablePage $page, string $expected, int $attempts = 40): void
{
    for ($i = 0; $i < $attempts; $i++) {
        if ($page->script('window.location.pathname') === $expected) {
            return;
        }

        usleep(100_000);
    }
}

test('未契約 org の dashboard は「プランを選ぶ」callout を出し、旧「支払いが確認できない」文言を出さず、CTA でプラン選択に着地する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner);

    $page = visit("/organizations/{$organization->slug}/dashboard");

    // (1) 未契約に対しては「プランの選択が必要」— 支払い失敗ではない。
    //     locator 経由なので hydration 完了まで自動待機する (以降の非待機 API の前提になる)
    $body = $page->text('[data-testid="billing-callout-body"]');
    expect($body)->toContain('ご利用にはプランの選択が必要です。');

    // (2) 旧文言がページのどこにも可視で出ていないこと (F-2-01 の本体)
    $page->assertDontSee('お支払いが確認できないため');

    // (3) CTA が行き先のない詰みを作らないこと。着地先はサーバが決める
    //     (manageBilling 保持者 = owner なので /onboarding/checkout に留まる)
    $page->click('[data-testid="billing-callout"] a');
    waitForPath($page, "/organizations/{$organization->slug}/onboarding/checkout");
    $page->assertPathIs("/organizations/{$organization->slug}/onboarding/checkout");
});
