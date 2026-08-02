<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Enums\BrowserType;
use Pest\Browser\Playwright\Playwright;

/*
|--------------------------------------------------------------------------
| bfcache 秘匿・再検証の Browser E2E (詳細設計 施策 8)
|--------------------------------------------------------------------------
|
| 4 シナリオ:
|   1. 撮影画面からの通常遷移   — 秘匿が誤発火しないこと (両レーン)
|   2. bfcache 復元 (一般)      — 秘匿 → 検証 → 復帰の状態遷移 (WebKit レーンが正本)
|   3. 未ログアウトでの復元      — 表示と未送信フォーム状態が正しく戻ること (同上)
|   4. ログアウト後の復元        — PII が出ないこと = 本来の目的 (同上)
|
| レーンの位置づけ (docs/supported-browsers.md):
|   - 復元シナリオ (2/3/4) の正本は **WebKit レーン**。Chromium は `no-store` ページを
|     bfcache から evict するため、そもそも復元を再現できない。
|   - **ただし実測結果**: **Chromium / WebKit のどちらのレーンでも bfcache 復元が起きない**
|     (`no-store` の無い公開ページ間ですら復元されないことを実測)。
|     Chromium の原因は特定済みで、**Playwright が既定の起動スイッチに
|     `--disable-back-forward-cache` を渡している**ため (pest-plugin-browser が launch-options を
|     ハードコードしており外せない)。WebKit 側の原因は未特定。
|     詳細と対処方針は docs/supported-browsers.md。復元シナリオはこのハーネスでは成立しない。
|   - そのため 2/3/4 は **ハーネスの bfcache 再現能力を毎回実測**し、再現できない環境では
|     skip する (= その環境では**自動回帰で担保されていない**ことを出力に明示する)。
|     再現できる環境では下記の正のコントロールが厳格に効く。
|
| **正のコントロール**: シナリオ 2/3/4 は `pageshow.persisted === true` を実際に観測できた
| 場合のみ有効。観測できなければテストを失敗させる (空振りを green にしない)。
| 分岐ロジック自体の網羅は tests/js/lib/bfcache-guard.test.ts (vitest) が担う。
|
| 実行: composer test:browser (Chromium / WebKit の両レーン)。
| 前提: pnpm build 済み + `pnpm exec playwright install chromium webkit` 済み。
*/

/** WebKit (Playwright の safari) レーンで走っているか。 */
function bfcacheLaneIsWebKit(): bool
{
    return Playwright::defaultBrowserType() === BrowserType::SAFARI;
}

/**
 * このハーネスで bfcache 復元が起きるかを**実測**する (プロセス内 1 回)。
 *
 * `no-store` の無い公開ページ間で「戻る」を行い、JS 実行コンテキストが生き残るか
 * (= bfcache 復元か、通常の再取得か) を見る。ブラウザ種別の決め打ちにしないのは、
 * 「このレーンなら再現できるはず」という**見込み**を成功条件にしないため。
 */
function bfcacheRestoreIsReproducible(): bool
{
    static $reproducible = null;

    if ($reproducible === null) {
        $page = visit('/pricing');
        $page->script("window.__bfcacheHarnessProbe = 'alive'; true");
        $page->navigate('/terms');
        $page->back();
        $reproducible = $page->script('window.__bfcacheHarnessProbe ?? null') === 'alive';
    }

    return $reproducible;
}

/**
 * 復元シナリオの前提チェック。再現できない環境では **skip し、その旨を明示**する
 * (green を装わない。skip は「担保されていない」の表明であり、合格ではない)。
 */
function bfcacheSkipUnlessRestoreIsReproducible(): void
{
    if (bfcacheRestoreIsReproducible()) {
        return;
    }

    $lane = bfcacheLaneIsWebKit() ? 'webkit' : 'chromium';

    test()->markTestSkipped(
        "このハーネス (lane={$lane}) は bfcache 復元を再現できない "
        .'(no-store の無い公開ページですら「戻る」で復元されないことを実測。'
        .'Chromium は Playwright 既定の --disable-back-forward-cache が原因、'
        .'WebKit は原因未特定。docs/supported-browsers.md 参照)。'
        .'=> このシナリオは自動回帰で担保されていない。分岐ロジックは '
        .'tests/js/lib/bfcache-guard.test.ts が、実機挙動は docs/supported-browsers.md の '
        .'実機受入確認が受け持つ。',
    );
}

/** @return array{User, Project} 撮影 PWA に到達できる owner + project */
function bfcacheCaptureContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    return [$owner, $project];
}

/**
 * bfcache 復元の観測レコーダーを現在のページへ仕込む。
 *
 * bfcache 復元では JS の実行コンテキストごと復元されるため、この listener は
 * 「本当に復元された場合にだけ」生き残って発火する = 正のコントロールになる
 * (通常の再取得では listener ごと消えるためレコードは残らない)。
 *
 * 併せて **pageshow 時点の秘匿状態**を記録する。guard の pageshow ハンドラは同期的に
 * 秘匿属性を立てた上で非同期プローブへ入るため、ここで `visibility: hidden` を観測できれば
 * 「復元直後に PII が描画されていない」ことの実測になる。
 */
function bfcacheInstallRestoreRecorder(PendingAwaitablePage $page): void
{
    $page->script(<<<'JS'
        (() => {
            sessionStorage.removeItem('bfcache-e2e-record');
            window.addEventListener('pageshow', (event) => {
                if (!event.persisted) return;
                const root = document.getElementById('app');
                sessionStorage.setItem('bfcache-e2e-record', JSON.stringify({
                    persisted: true,
                    hiddenState: document.documentElement.getAttribute('data-bfcache-hidden'),
                    appVisibility: root === null ? null : getComputedStyle(root).visibility,
                }));
            });
            return true;
        })()
    JS);
}

/**
 * レコーダーの観測結果を読む。観測できなければ **失敗させる** (空振りを green にしない)。
 *
 * @return array{persisted: bool, hiddenState: string|null, appVisibility: string|null}
 */
function bfcacheReadRestoreRecord(PendingAwaitablePage $page): array
{
    $raw = $page->script("sessionStorage.getItem('bfcache-e2e-record')");

    expect($raw)->toBeString(
        '正のコントロール失敗: pageshow.persisted === true を観測できなかった '
        .'(= bfcache 復元が起きていない。このレーンではシナリオが空振りしている)',
    );

    /** @var array{persisted: bool, hiddenState: string|null, appVisibility: string|null} $record */
    $record = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

    expect($record['persisted'])->toBeTrue('正のコントロール失敗: persisted が true でない');

    return $record;
}

/**
 * ブラウザ側の条件が満たされるまで待つ (plugin の assertion は auto-retry しないため)。
 * 各 script() 呼び出しが in-process サーバの event loop を回すので、待機中に
 * プローブ (/session/status) の応答も進む。
 */
function bfcacheWaitUntil(PendingAwaitablePage $page, string $expression, string $message, int $attempts = 100): void
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

/** ブラウザ内からログアウトし、セッションが本当に無効化されたことを確認する。 */
function bfcacheLogoutInBrowser(PendingAwaitablePage $page): void
{
    $authenticated = $page->script(<<<'JS'
        (async () => {
            const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
            const token = match ? decodeURIComponent(match[1]) : '';
            await fetch('/logout', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-XSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            });
            const status = await fetch('/session/status', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' },
            }).then((response) => response.json());
            return status.authenticated;
        })()
    JS);

    expect($authenticated)->toBeFalse('前提条件失敗: ブラウザ側のログアウトでセッションが無効化されていない');
}

/*
|--------------------------------------------------------------------------
| シナリオ 1: 撮影画面からの通常遷移 (両レーン)
|--------------------------------------------------------------------------
*/

test('撮影画面から通常遷移しても秘匿が誤発火しない', function (): void {
    [$owner, $project] = bfcacheCaptureContext();
    $this->actingAs($owner);

    $page = visit("/app/projects/{$project->id}/manuals");
    $page->assertSee('撮影するマニュアルを選ぶ');
    // 未送信のフォーム入力 (撮影 PWA の作業途中状態)
    $page->type('@capture-search', '未送信の入力');

    // 通常遷移 (cross-document)
    $page->navigate('/dashboard');

    $page->assertPathIs('/dashboard')
        ->assertScript('document.documentElement.hasAttribute("data-bfcache-hidden")', false)
        ->assertMissing('@bfcache-guard-overlay')
        ->assertNoJavaScriptErrors();

    // 戻り操作でも詰まない (再取得なら属性なし / 復元なら プローブ → 秘匿解除)
    $page->back();
    bfcacheWaitUntil(
        $page,
        '!document.documentElement.hasAttribute("data-bfcache-hidden")',
        '戻り操作後に秘匿が解除されない (詰み)',
    );
    $page->assertSee('撮影するマニュアルを選ぶ');
});

/*
|--------------------------------------------------------------------------
| 部分検証 (両レーン): 秘匿の配線 + プローブ発火を実ブラウザで確認する
|--------------------------------------------------------------------------
| bfcache 復元そのものはこのハーネスでは再現できないため、pagehide/pageshow を明示発火して
| 「秘匿属性が付く」「実描画が止まる (visibility: hidden)」「プローブが走って秘匿が解ける」
| の配線だけを実ブラウザで固定する (vitest では実描画を検証できない)。
| **これを復元シナリオの証明として扱わない**。
*/

test('pagehide で実描画が止まり pageshow のプローブで復帰する (配線の部分検証)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    $page = visit('/dashboard');
    $page->assertSee($owner->name);

    $hiddenVisibility = $page->script(<<<'JS'
        (() => {
            window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
            const root = document.getElementById('app');
            return {
                state: document.documentElement.getAttribute('data-bfcache-hidden'),
                appVisibility: getComputedStyle(root).visibility,
                overlayVisible: getComputedStyle(document.getElementById('bfcache-guard-overlay')).display !== 'none',
            };
        })()
    JS);

    expect($hiddenVisibility)->toBe([
        'state' => 'pending',
        'appVisibility' => 'hidden',
        'overlayVisible' => true,
    ]);

    // 秘匿属性が復元マーカー。pageshow でプローブが走り、有効なら秘匿だけ外れる
    $page->script("window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }))");
    bfcacheWaitUntil(
        $page,
        '!document.documentElement.hasAttribute("data-bfcache-hidden")',
        'プローブ成功後に秘匿が解除されない',
    );

    $page->assertSee($owner->name)->assertNoJavaScriptErrors();
});

/*
|--------------------------------------------------------------------------
| シナリオ 2: bfcache 復元 (一般)
|   復元を再現できないハーネスでは skip される (= 担保されていないことの明示)。
|--------------------------------------------------------------------------
*/

test('bfcache 復元では秘匿 → 検証 → 復帰の順で状態遷移する', function (): void {
    bfcacheSkipUnlessRestoreIsReproducible();

    [, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    $page = visit('/dashboard');
    $page->assertSee($owner->name);
    bfcacheInstallRestoreRecorder($page);

    $page->navigate('/pricing');
    $page->back();

    $record = bfcacheReadRestoreRecord($page);
    expect($record['hiddenState'])->not->toBeNull('復元時に秘匿属性が付いていない');
    expect($record['appVisibility'])->toBe('hidden', '復元直後に本文が描画されている (秘匿が効いていない)');

    bfcacheWaitUntil(
        $page,
        '!document.documentElement.hasAttribute("data-bfcache-hidden")',
        'セッション有効なのに秘匿が解除されない',
    );
    $page->assertPathIs('/dashboard')->assertSee($owner->name);
});

/*
|--------------------------------------------------------------------------
| シナリオ 3: 未ログアウトでの復元 — 表示と未送信フォーム状態が戻る
|--------------------------------------------------------------------------
*/

test('未ログアウトでの復元では表示も未送信フォーム状態も壊れない', function (): void {
    bfcacheSkipUnlessRestoreIsReproducible();

    [$owner, $project] = bfcacheCaptureContext();
    $this->actingAs($owner);

    $page = visit("/app/projects/{$project->id}/manuals");
    $page->assertSee('撮影するマニュアルを選ぶ');
    $page->type('@capture-search', '未送信メモ');
    bfcacheInstallRestoreRecorder($page);

    $page->navigate('/pricing');
    $page->back();

    bfcacheReadRestoreRecord($page);

    bfcacheWaitUntil(
        $page,
        '!document.documentElement.hasAttribute("data-bfcache-hidden")',
        'セッション有効なのに秘匿が解除されない (誤検知)',
    );

    // unhide のみ = DOM も未送信入力も破棄していない (hard reload なら消える)
    expect($page->value('@capture-search'))->toBe('未送信メモ');
    $page->assertSee('撮影するマニュアルを選ぶ')->assertNoJavaScriptErrors();
});

/*
|--------------------------------------------------------------------------
| シナリオ 4: ログアウト後の復元 — PII を出さない (本来の目的)
|--------------------------------------------------------------------------
*/

test('ログアウト後の復元では PII を再表示せず login へ倒す', function (): void {
    bfcacheSkipUnlessRestoreIsReproducible();

    [, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    $page = visit('/dashboard');
    $page->assertSee($owner->name);
    bfcacheInstallRestoreRecorder($page);

    $page->navigate('/pricing');
    bfcacheLogoutInBrowser($page);

    $page->back();

    $record = bfcacheReadRestoreRecord($page);
    expect($record['hiddenState'])->not->toBeNull('ログアウト後の復元で秘匿属性が付いていない');
    expect($record['appVisibility'])->toBe('hidden', 'ログアウト後の復元で PII が描画されている');

    bfcacheWaitUntil(
        $page,
        "window.location.pathname === '/login'",
        'セッション無効なのに login へ倒れない',
    );
    $page->assertDontSee($owner->name);
});
