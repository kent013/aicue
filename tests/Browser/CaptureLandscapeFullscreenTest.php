<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\VideoManual;

/*
|--------------------------------------------------------------------------
| 撮影ナビ: 横持ち全画面撮影とカット間スワイプ (T186)
|--------------------------------------------------------------------------
|
| 固定するのは **DOM 契約と条件分岐** だけである。
| 実カメラを伴う挙動 (録画継続・プレビューの見え方・iOS の動的ツールバー・
| 端末の戻るジェスチャ・inert 非対応環境) は Chromium でも WebKit でも再現していない。
| 保証範囲は docs/supported-browsers.md の「未対応事項」が正本。
|
| 負のコントロールを 3 本置くのは、「全画面にならない」だけを観測すると
| ハーネスの context 設定が変わって前提が崩れたのか実装が壊れたのかを区別できないためである。
| そのため各ケースの冒頭で (pointer: coarse) と対象 query の評価結果を assert する。
|
*/

/**
 * LANDSCAPE_CAPTURE_MEDIA_QUERY と同一文字列。
 *
 * **式そのものの正本は `resources/js/lib/capture/landscape-capture.ts` で、
 * 3 条件が揃っていることを機械固定するのは `tests/js/lib/capture/landscape-capture.test.ts`
 * (完全一致の assertion) である**。ここの複製は「このハーネスの context で条件が
 * 成立しているか」という**前提の観測**にしか使わず、式の正しさは主張しない
 * (PHP から TS の定数を読む経路が無いため複製は避けられないが、
 * 役割を分けて二重の正本を作らない)。
 */
function landscapeCaptureMediaQuery(): string
{
    return '(orientation: landscape) and (max-height: 540px) and (pointer: coarse)';
}

/**
 * 横持ち全画面の前提を一式作る。
 *
 * 撮影 PWA は require-active-subscription group 内 (AGENTS.md ドメイン規約 4) なので
 * contractPaidPlan を通さないと /billing-required に着地する。
 *
 * @return array{0: Project, 1: VideoManual}
 */
function landscapeCaptureFixture(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization);

    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()
        ->forProject($project)
        ->create(['created_by' => $owner->id, 'status' => VideoManualStatus::Ready->value]);

    foreach (range(1, 3) as $index) {
        Cut::factory()->forManual($manual)->create([
            'sort_order' => $index,
            // ★ 撮影ガイドは**わざと長くする**。1 行に収まる短文だと帯の高さが最小になり、
            //   「交差しない」がほぼ自明に成立してしまう (レーンの分離を実質検査しない)。
            //   **ただしこれは行数制限 (line-clamp) の実装位置を検査するものではない** —
            //   そちらは ShootingGuideOverlay.test.ts が構造として固定する
            //   (実測: line-clamp を flex と同じ要素へ戻しても本テストは緑のままだった)。
            'shooting_point' => "工程 {$index} は手元を寄りで撮る。".str_repeat(
                'カメラを被写体の正面に据えて手の動きが切れないように構図を取り、',
                6
            ),
            'subtitle_primary' => "工程 {$index}",
            'subtitle_secondary' => "工程 {$index} の説明字幕",
        ]);
    }

    test()->actingAs($owner);

    return [$project, $manual];
}

/** capture.manuals.show の URL */
function landscapeCaptureShowUrl(Organization $organization, Project $project, VideoManual $manual): string
{
    return "/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}";
}

/**
 * 指定 testid の要素の属性が期待値になるまで上限付きで polling する。
 *
 * resize() 後は media query の再評価と Svelte の再描画が非同期なので、
 * 直後に測ると移行途中を拾って flaky になる。固定 sleep にはしない
 * (「目的の状態になったか」を直接見る)。上限を超えたら false を返し、
 * 呼び出し側が「待機 timeout」として明示的に落とす。
 */
function waitForTestIdAttribute(
    mixed $page,
    string $testId,
    string $attribute,
    string $expected,
    int $attempts = 40,
): bool {
    for ($i = 0; $i < $attempts; $i++) {
        $actual = $page->script(<<<JS
            (() => {
                const el = document.querySelector('[data-testid="{$testId}"]');
                return el === null ? null : el.getAttribute('{$attribute}');
            })()
        JS);

        if ($actual === $expected) {
            return true;
        }

        usleep(100_000);
    }

    return false;
}

/** 指定 testid のテキストが期待値を含むまで上限付きで polling する */
function waitForTestIdText(mixed $page, string $testId, string $expected, int $attempts = 40): bool
{
    $needle = json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    for ($i = 0; $i < $attempts; $i++) {
        $found = $page->script(<<<JS
            (() => {
                const el = document.querySelector('[data-testid="{$testId}"]');
                return el !== null && (el.textContent ?? '').includes({$needle});
            })()
        JS);

        if ($found === true) {
            return true;
        }

        usleep(100_000);
    }

    return false;
}

/** 対象 media query と (pointer: coarse) の評価結果を返す (ケースの前提の明示) */
function landscapeMediaState(mixed $page): array
{
    $query = landscapeCaptureMediaQuery();

    return [
        'coarse' => $page->script("window.matchMedia('(pointer: coarse)').matches"),
        'target' => $page->script("window.matchMedia('{$query}').matches"),
    ];
}

test('横持ちスマホ相当の context では撮影パネルが全画面へ切り替わる (ケース 0)', function (): void {
    [$project, $manual] = landscapeCaptureFixture();

    $page = visit(landscapeCaptureShowUrl($organization, $project, $manual))->on()->mobile()
        ->assertPathIs(landscapeCaptureShowUrl($organization, $project, $manual))
        ->resize(844, 390);

    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'true'))
        ->toBeTrue('横持ちスマホ相当でも全画面へ切り替わらなかった (待機 timeout)');

    // 前提の明示: 条件が満たされていることを実測で残す
    // (これが無いと、ハーネスの context 設定が変わって前提が崩れたときに
    //  「全画面にならない」だけが観測され、実装の回帰と区別できない)
    expect(landscapeMediaState($page))->toBe(['coarse' => true, 'target' => true]);
});

test('全画面の前後ボタンでカットが往復する (ケース 0 の続き)', function (): void {
    [$project, $manual] = landscapeCaptureFixture();

    $page = visit(landscapeCaptureShowUrl($organization, $project, $manual))->on()->mobile()
        ->assertPathIs(landscapeCaptureShowUrl($organization, $project, $manual))
        ->resize(844, 390);

    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'true'))
        ->toBeTrue('全画面へ切り替わらなかった (待機 timeout)');

    // 自動選択で先頭カットが選ばれている
    expect(waitForTestIdText($page, 'cut-swipe-label', '手順 1'))
        ->toBeTrue('先頭カットが自動選択されなかった (待機 timeout)');

    $page->click('[data-testid="cut-swipe-next"]');
    expect(waitForTestIdText($page, 'cut-swipe-label', '手順 2'))
        ->toBeTrue('次のカットへ移動しなかった (待機 timeout)');

    $page->click('[data-testid="cut-swipe-previous"]');
    expect(waitForTestIdText($page, 'cut-swipe-label', '手順 1'))
        ->toBeTrue('前のカットへ戻らなかった (待機 timeout)');
});

test('全画面は終了でき、再入路のボタンから戻れる (行き止まりを作らない)', function (): void {
    [$project, $manual] = landscapeCaptureFixture();

    $page = visit(landscapeCaptureShowUrl($organization, $project, $manual))->on()->mobile()
        ->assertPathIs(landscapeCaptureShowUrl($organization, $project, $manual))
        ->resize(844, 390);

    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'true'))
        ->toBeTrue('全画面へ切り替わらなかった (待機 timeout)');

    $page->click('[data-testid="exit-fullscreen-capture"]');
    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'false'))
        ->toBeTrue('全画面を終了できなかった (待機 timeout)');
    $page->assertVisible('[data-testid="enter-fullscreen-capture"]');

    $page->click('[data-testid="enter-fullscreen-capture"]');
    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'true'))
        ->toBeTrue('全画面へ戻れなかった (待機 timeout)');
});

test('デスクトップ相当では全画面にならない (負のコントロール 1: 全条件)', function (): void {
    [$project, $manual] = landscapeCaptureFixture();

    $page = visit(landscapeCaptureShowUrl($organization, $project, $manual))->on()->desktop()
        ->assertPathIs(landscapeCaptureShowUrl($organization, $project, $manual));

    expect(landscapeMediaState($page))->toBe(['coarse' => false, 'target' => false]);
    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'false'))
        ->toBeTrue('デスクトップ相当で全画面になった');
});

test('粗いポインタでも高さが超えると全画面にならない (負のコントロール 2: max-height の欠落)', function (): void {
    [$project, $manual] = landscapeCaptureFixture();

    $page = visit(landscapeCaptureShowUrl($organization, $project, $manual))->on()->mobile()
        ->assertPathIs(landscapeCaptureShowUrl($organization, $project, $manual))
        ->resize(1024, 900);

    expect(landscapeMediaState($page))->toBe(['coarse' => true, 'target' => false]);
    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'false'))
        ->toBeTrue('高さが 540px を超えているのに全画面になった');
});

test('横長でも細いポインタなら全画面にならない (負のコントロール 3: pointer: coarse の欠落)', function (): void {
    [$project, $manual] = landscapeCaptureFixture();

    $page = visit(landscapeCaptureShowUrl($organization, $project, $manual))->on()->desktop()
        ->assertPathIs(landscapeCaptureShowUrl($organization, $project, $manual))
        ->resize(844, 390);

    expect(landscapeMediaState($page))->toBe(['coarse' => false, 'target' => false]);
    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'false'))
        ->toBeTrue('細いポインタなのに全画面になった');
});

test('撮影ガイドの矩形が上下の字幕帯のどちらとも交差しない', function (): void {
    [$project, $manual] = landscapeCaptureFixture();

    $page = visit(landscapeCaptureShowUrl($organization, $project, $manual))->on()->mobile()
        ->assertPathIs(landscapeCaptureShowUrl($organization, $project, $manual))
        ->resize(844, 390);

    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'true'))
        ->toBeTrue('全画面へ切り替わらなかった (待機 timeout)');

    // ★ レーン依存の前提: overlay は撮影パネル (CameraRecorder) の中にあり、
    //   撮影パネルが出るのは MediaRecorder がある環境だけである。
    //   **Playwright WebKit (Linux) には MediaRecorder が無く** (実測: typeof が "undefined")、
    //   撮影パネルはファイル選択フォールバックへ倒れるため overlay が 1 つも描画されない。
    //   これは実装の回帰ではなくレーンの能力差なので、条件を明示して skip する
    //   (無条件に緑にする / 前提を assert せずに「交差しない」と主張する、はどちらもしない)。
    //   保証範囲は docs/supported-browsers.md の「未対応事項」が正本。
    if ($page->script('typeof window.MediaRecorder === "undefined"') === true) {
        test()->markTestSkipped(
            'このレーンには MediaRecorder が無く撮影パネルが描画されない (Playwright WebKit)'
        );
    }

    // 前提: 撮影ガイドと上下の字幕帯が 3 つとも描画されている
    // (どれかが欠けていると「交差しない」は自明に成立してしまう)
    expect($page->script(<<<'JS'
        (() => {
            const ids = ['shooting-guide-overlay', 'subtitle-primary', 'subtitle-secondary'];
            return ids.every((id) => document.querySelector(`[data-testid="${id}"]`) !== null);
        })()
    JS))->toBeTrue('撮影ガイドまたは字幕帯が描画されていない (前提が成立していない)');

    // primary × secondary は本設計が触っていない既存 component 内部の配置なので検査しない
    // (主張と機械保証の範囲を一致させる)
    expect($page->script(<<<'JS'
        (() => {
            const rect = (id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect();
            const intersects = (a, b) =>
                a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;
            const guide = rect('shooting-guide-overlay');
            return {
                primary: intersects(guide, rect('subtitle-primary')),
                secondary: intersects(guide, rect('subtitle-secondary')),
            };
        })()
    JS))->toBe(['primary' => false, 'secondary' => false]);
});
