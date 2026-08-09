<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Models\Cut;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\Take;
use App\Models\VideoManual;

/*
|--------------------------------------------------------------------------
| 撮影ナビ: カット選択時の視点/フォーカス移送 (bug-hunt F-1-03)
|--------------------------------------------------------------------------
|
| 1 カラム (モバイル) ではシナリオ一覧の下に撮影パネルが縦積みされるため、カットを
| タップしても撮影パネルが viewport に入らず、ユーザーが毎回手動スクロールしていた。
| 「視点」だけ運んでフォーカスを一覧側に残すと a11y 欠落を作るので、両方運ぶことを固定する。
|
| 受入条件 4 (captureActive 中は動かない) は CI に実カメラが無いため
| tests/js/lib/capture/panel-navigation.test.ts (抑止契約) と
| tests/js/pages/CaptureShow.test.ts (ページ配線) の 2 段で固定している。
|
*/

/**
 * 撮影ナビの前提を一式作る。
 *
 * 撮影 PWA は require-active-subscription group 内 (AGENTS.md ドメイン規約 4) なので
 * contractPaidPlan を通さないと /billing-required に着地する。
 *
 * cuts の件数は「viewport 外にするための手段」であって条件ではない。
 * 実際に viewport 外であることは各テストがクリック前に assert する。
 *
 * @return array{0: Project, 1: VideoManual}
 */
function captureNavigationFixture(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization);

    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()
        ->forProject($project)
        ->create(['created_by' => $owner->id, 'status' => VideoManualStatus::Ready->value]);

    foreach (range(1, 20) as $index) {
        Cut::factory()->forManual($manual)->create(['sort_order' => $index]);
    }

    test()->actingAs($owner);

    return [$project, $manual];
}

/** capture.manuals.show の URL */
function captureShowUrl(Project $project, VideoManual $manual): string
{
    return "/app/projects/{$project->id}/manuals/{$manual->id}";
}

/**
 * 指定 testid の要素が「矩形全体として」viewport 内に入るまで上限付きで polling する。
 *
 * smooth scroll は非同期なので、クリック直後に測ると移動途中の座標を拾って flaky になる。
 * scrollY の静止判定ではなく「目的の状態になったか」を直接見る (慣性で一瞬止まって見える
 * ケースを踏まないため)。上限を超えたら false を返し、呼び出し側が
 * 「待機 timeout」として明示的に落とす (無限待ちにしない / 失敗理由を曖昧にしない)。
 */
function waitUntilInViewport(mixed $page, string $testId, int $attempts = 40): bool
{
    for ($i = 0; $i < $attempts; $i++) {
        $inside = $page->script(<<<JS
            (() => {
                const el = document.querySelector('[data-testid="{$testId}"]');
                if (el === null) return false;
                const r = el.getBoundingClientRect();
                return r.top >= 0 && r.bottom <= window.innerHeight;
            })()
        JS);

        if ($inside === true) {
            return true;
        }

        usleep(100_000);
    }

    return false;
}

test('モバイル幅ではカット選択で撮影パネルが viewport に入りフォーカスも移る', function (): void {
    [$project, $manual] = captureNavigationFixture();
    $firstCutId = $manual->cuts()->orderBy('sort_order')->value('id');

    // ★ on()->mobile() が返す On は __call のたびに新しいページを作るため、
    //   ここで 1 度だけ materialize して以降は同じ Webpage を使い回す。
    $page = visit(captureShowUrl($project, $manual))->on()->mobile()
        ->assertPathIs(captureShowUrl($project, $manual));

    // 前提: この時点で撮影パネルは viewport の外にある。
    // これが成り立たないとテストは何も証明しない (修正前でも緑になってしまう)。
    expect($page->script(<<<'JS'
        (() => {
            const el = document.querySelector('[data-testid="capture-right-pane"]');
            return el.getBoundingClientRect().top >= window.innerHeight;
        })()
    JS))->toBeTrue();

    $page->click("[data-testid=\"cut-row-{$firstCutId}\"]");

    // 受入条件 1: 見出しが「矩形全体として」viewport 内 (1px 交差では不可)。
    // 待機 timeout か座標不一致かが失敗メッセージで分かるようにする。
    expect(waitUntilInViewport($page, 'capture-recording-heading'))
        ->toBeTrue('撮影パネル見出しが viewport 内に入らなかった (待機 timeout)');

    // 受入条件 2: フォーカスも撮影パネル先頭へ移る
    expect($page->script(
        'document.activeElement?.dataset?.testid ?? null'
    ))->toBe('capture-recording-heading');
});

test('デスクトップ幅ではカット選択でスクロールも撮影パネルへのフォーカスも起きない', function (): void {
    [$project, $manual] = captureNavigationFixture();
    $firstCutId = $manual->cuts()->orderBy('sort_order')->value('id');

    $page = visit(captureShowUrl($project, $manual))->on()->desktop()
        ->assertPathIs(captureShowUrl($project, $manual));

    $before = $page->script('window.scrollY');

    $page->click("[data-testid=\"cut-row-{$firstCutId}\"]");

    // 「動かない」ことの観測: 一定回数 polling して scrollY が一度も変わらないことを見る。
    // 単発 sleep だと「まだ動いていないだけ」と区別できないため、区間で観測する。
    for ($i = 0; $i < 10; $i++) {
        usleep(50_000);
        expect($page->script('window.scrollY'))
            ->toBe($before, 'デスクトップ幅でスクロールが発生した');
    }

    // 「activeElement が変化しない」ではない: クリックした <button> にブラウザが
    // フォーカスを移すのは通常挙動であり本実装の副作用ではない。
    // 検証すべきは「撮影パネル見出しへプログラムフォーカスしない」こと。
    expect($page->script(
        'document.activeElement?.dataset?.testid ?? null'
    ))->not->toBe('capture-recording-heading');
});

test('モバイル幅では撮影パネルからカット一覧へ視点とフォーカスの両方が戻る', function (): void {
    [$project, $manual] = captureNavigationFixture();
    $firstCutId = $manual->cuts()->orderBy('sort_order')->value('id');

    $page = visit(captureShowUrl($project, $manual))->on()->mobile()
        ->assertPathIs(captureShowUrl($project, $manual));
    $page->click("[data-testid=\"cut-row-{$firstCutId}\"]");
    expect(waitUntilInViewport($page, 'capture-recording-heading'))
        ->toBeTrue('撮影パネル見出しが viewport 内に入らなかった (待機 timeout)');

    $page->click('[data-testid="back-to-cut-list"]');

    expect(waitUntilInViewport($page, 'capture-cut-list-heading'))
        ->toBeTrue('カット一覧見出しが viewport 内に戻らなかった (待機 timeout)');

    expect($page->script(
        'document.activeElement?.dataset?.testid ?? null'
    ))->toBe('capture-cut-list-heading');

    // TextLink のボタンモード (href なし) なので URL に # が付かない
    expect($page->script('window.location.hash'))->toBe('');
});

test('テイク再生の video のアクセシブルネームに手順ラベルが入る (F-1-02)', function (): void {
    [$project, $manual] = captureNavigationFixture();
    $firstCut = $manual->cuts()->orderBy('sort_order')->first();
    $take = Take::factory()->forCut($firstCut)->create();

    $page = visit(captureShowUrl($project, $manual))->on()->desktop()
        ->assertPathIs(captureShowUrl($project, $manual));
    $page->click("[data-testid=\"cut-row-{$firstCut->id}\"]");
    $page->click("[data-testid=\"take-preview-{$take->id}\"]");

    // ダイアログ内の video が描画されるまで待つ
    for ($i = 0; $i < 40; $i++) {
        $exists = $page->script(
            'document.querySelector(\'[data-testid="take-preview-video"]\') !== null'
        );

        if ($exists === true) {
            break;
        }

        usleep(100_000);
    }

    // 受入条件 8: 非空ではなく「どのカットのテイクか」が分かる意味内容を固定する
    // (完全一致は i18n 変更に脆いので必要語の包含で見る)
    expect($page->attribute('[data-testid="take-preview-video"]', 'aria-label'))
        ->toContain('手順 1');
});

test('プレビュー動画の video にアクセシブルネームがある (F-1-02)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization);

    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()
        ->forProject($project)
        ->create(['created_by' => $owner->id, 'status' => VideoManualStatus::Ready->value]);
    Cut::factory()->forManual($manual)->create(['sort_order' => 1]);

    // playbackJobId は「kind=Preview ∧ status=Succeeded ∧ output_path 非 null」でのみ引かれる
    RenderJob::factory()
        ->forManual($manual)
        ->preview()
        ->create([
            'status' => JobStatus::Succeeded->value,
            'output_path' => 'renders/preview-fixture.mp4',
        ]);

    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/manuals/{$manual->id}")->on()->desktop()
        ->assertPathIs("/projects/{$project->id}/manuals/{$manual->id}");

    for ($i = 0; $i < 40; $i++) {
        $exists = $page->script(
            'document.querySelector(\'[data-testid="preview-video"]\') !== null'
        );

        if ($exists === true) {
            break;
        }

        usleep(100_000);
    }

    // 受入条件 7: 固定文言「プレビュー動画」(playbackId は常に preview 由来なので
    // 完成動画と取り違える経路が無く、状態分岐を持たない)
    expect($page->attribute('[data-testid="preview-video"]', 'aria-label'))
        ->toContain('プレビュー');
});
