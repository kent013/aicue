<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\VideoManual;
use Pest\Browser\Api\PendingAwaitablePage;

/*
|--------------------------------------------------------------------------
| flash → toast の end-to-end (bug-hunt F-1-02 の再現/反証)
|--------------------------------------------------------------------------
|
| 破壊的操作が別画面へリダイレクトしたとき、着地先で成功 toast が可視であることを固定する。
| 2 本で 2 種類の遷移を覆う:
|   1. projects.manuals.destroy → projects.show  (AppLayout → AppLayout)
|   2. settings.account.destroy → home           (AppLayout → GuestLayout。施策 A-1 の受入)
|
| サーバ側 flash は VideoManualCrudTest / AccountDeletionTest が、flash→toast 変換は
| tests/js/lib/flash-to-toast.test.ts が既に固定している。本テストが担うのは
| **Inertia のページ再生成をまたいで toast が生き残るか**という結合のみ。
|
| 判定の射程 (devnotes/20260804-0021-ux-small-gaps/conceptual-design.md):
|   - 制御条件 (flash 有り / 着地ページ mount 済み / 3 秒以内に検査) を満たして
|     一度も可視にならない → H-a (ToastContainer のライフサイクル依存) を支持
|   - その他の fail → 原因判定不能。テスト条件を調査する (実装を変えない)
|   - pass → 「自動テスト条件では未再現」まで (bug-hunt 観測が artifact だったことの確定ではない)
|
| 実行: composer test:browser (Chromium / WebKit の両レーン)。前提: pnpm build 済み。
*/

/**
 * 「着地マーカー」と「成功 toast」を**同一の時間窓で同時に**観測する。
 *
 * 2 つを直列に待つと、着地判定が deadline を越えた場合に
 * 「4 秒目に着地したのに『3 秒以内に着地済み』と誤分類する」ため、必ず同一ループで見る。
 * deadline は toast の auto-dismiss (4 秒) より短く取り、「見えなかった」を
 * auto-dismiss と混同しないようにする (制御条件 (iii))。
 *
 * 存在 (querySelector != null) ではなく**実可視**で判定する (レンダ順によっては
 * 非表示のまま DOM に居る瞬間がありうる)。
 * script() 呼び出しが in-process サーバの event loop を回す (bfcache テストと同じ流儀)。
 *
 * @return array{toastVisible: bool, landedWithinDeadline: bool, elapsedMs: int}
 */
function observeLandingAndToast(PendingAwaitablePage $page, string $landingSelector, int $timeoutMs = 3000): array
{
    $startedAt = hrtime(true);
    $expression = sprintf(<<<'JS'
        (() => {
            const visible = (selector) => {
                const el = document.querySelector(selector);
                if (el === null) return false;
                const style = getComputedStyle(el);
                return style.visibility !== 'hidden'
                    && style.display !== 'none'
                    && el.getClientRects().length > 0;
            };

            return {
                landed: visible(%s),
                toast: visible('[data-testid="toast-success"]'),
            };
        })()
        JS, json_encode($landingSelector, JSON_THROW_ON_ERROR));

    $landed = false;

    while (true) {
        $state = $page->script($expression);
        $landed = $landed || (is_array($state) && ($state['landed'] ?? false) === true);

        if (is_array($state) && ($state['toast'] ?? false) === true) {
            return [
                'toastVisible' => true,
                'landedWithinDeadline' => $landed,
                'elapsedMs' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ];
        }

        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        if ($elapsedMs >= $timeoutMs) {
            return ['toastVisible' => false, 'landedWithinDeadline' => $landed, 'elapsedMs' => $elapsedMs];
        }

        usleep(50_000);
    }
}

/**
 * ブラウザ側の条件が満たされるまで待つ (plugin の assertion は auto-retry するが、
 * in-process サーバは script() 呼び出しで event loop を回さないと保留リクエストを
 * 処理できないため、遷移待ちは script() polling で行う)。
 */
function waitForBrowserCondition(PendingAwaitablePage $page, string $expression, string $message, int $attempts = 100): void
{
    for ($i = 0; $i < $attempts; $i++) {
        if ($page->script("Boolean({$expression})") === true) {
            expect(true)->toBeTrue();

            return;
        }
        usleep(50_000);
    }

    throw new RuntimeException("条件が満たされませんでした: {$message} (式: {$expression})");
}

/**
 * fail 時の分類を message に載せる (制御条件つき fail かどうかを人が判断できるように)。
 *
 * @param  array{toastVisible: bool, landedWithinDeadline: bool, elapsedMs: int}  $observed
 */
function assertToastObserved(array $observed, string $what): void
{
    if ($observed['toastVisible']) {
        expect(true)->toBeTrue();

        return;
    }

    expect($observed['landedWithinDeadline'])->toBeTrue(
        "{$what}: deadline ({$observed['elapsedMs']}ms) 内に着地マーカーが可視にならなかった "
        .'= 「その他の fail」。原因判定不能なので実装を変えずにテスト条件を調査すること',
    );

    expect($observed['toastVisible'])->toBeTrue(
        "{$what}: 着地は deadline 内に確認できたが成功 toast が可視にならなかった "
        .'= 制御条件を満たした fail → H-a を支持 (conceptual-design.md の判定表を参照)',
    );
}

test('動画マニュアル削除後、リダイレクト先 (AppLayout) で成功 toast が表示される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['title' => '組立手順']);

    $this->actingAs($owner);

    $page = visit("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}");
    $page->assertSee('組立手順');

    // DangerZone → 確認ダイアログ → 削除実行 (testId 指定で text の曖昧一致を避ける)
    $page->click('@delete-manual-button');
    $page->assertSee('削除する');
    $page->click('削除する');

    assertToastObserved(
        observeLandingAndToast($page, '[data-testid="project-show-heading"]'),
        'manuals.destroy → projects.show',
    );

    $page->assertPathIs("/organizations/{$organization->slug}/projects/{$project->id}")
        ->assertSeeIn('[data-testid="toast-success"]', '動画マニュアルを削除しました')
        ->assertNoJavaScriptErrors();
});

test('アカウント削除後、未認証面 (GuestLayout) で成功 toast が表示される', function (): void {
    // recent-auth は Login イベントで stamp される (StampRecentAuthOnLogin)。
    // actingAs() は Login を発火しないため、**この 1 本だけ UI ログイン**から始める
    // (ハーネス内部仕様への依存を作らない)。
    // createOrganizationWithOwner は free plan を grandfather するため課金ゲートに掛からない
    [$organization, $owner] = createOrganizationWithOwner();

    $page = visit('/login');
    // 「ログイン」という文言は AuthLayout の見出し h1 とも一致するため text locator は使わない。
    // login フォームの submit ボタンは 1 つだけ (SSO 導線は <a href>) なので構造 selector で指す。
    $page->fill('email', $owner->email)   // email は CipherSweet 暗号化だがモデル経由は平文
        ->fill('password', 'password')    // UserFactory の既定パスワード
        ->click('form button[type="submit"]');

    waitForBrowserCondition(
        $page,
        'window.location.pathname === '.json_encode("/organizations/{$organization->slug}/dashboard"),
        'ログイン後に組織のダッシュボードへ着地しない',
    );

    $page = visit('/settings');
    $page->click('@delete-account-button');
    $page->assertSee('本当にアカウントを削除しますか？');
    $page->click('削除する');

    assertToastObserved(
        observeLandingAndToast($page, '[data-testid="landing-hero"]'), // Welcome.svelte:142
        'settings.account.destroy → home (GuestLayout)',
    );

    $page->assertPathIs('/')
        ->assertSeeIn('[data-testid="toast-success"]', 'アカウントを削除しました')
        ->assertNoJavaScriptErrors();
});
