## Round 3 残 Warning 2 + Suggestion 1 への対応 (施策4 live 観測の証拠完成)

Round 3 で JS collector は承認された。残る live 観測の空振り防止・response 観測・origin 判定に対応した。

### 対応
1. [Warning] fetch/XHR 母集団非空: 受動観測 (Performance API) に母集団を依存させるのをやめ、
   **reloadManual が叩く現 URL の Inertia visit を能動 fetch して実 response を 1 件確実に観測**する
   形にした (母集団非空を構造的に保証)。
2. [Warning] response status/ヘッダ観測: 同一オリジンの能動 fetch (`X-Inertia:true`) で reload endpoint の
   実 response を読み、**status (200 部分リロード / 409 現 URL ハードリロード)・`X-Inertia`・
   `X-Inertia-Location` 実値**を assert。`X-Inertia-Location` と最終 URL が現 origin の /app 配下である
   (= /app 外 redirect でない) ことを固定。これで「Performance API 観測」ではなく実 response 観測が
   証拠の正本になった。
3. [Suggestion] origin 判定: 最初の visit 直後に `window.location.origin` を保存し、以降の
   navigation/resource/location 判定と能動 fetch の in-app 判定すべてをこの固定 origin で行う
   (遷移後 origin を正解にしない)。

### 観測範囲の正直な限定 (誇張しない)
能動 fetch は reloadManual と同じ現 URL・同じ Inertia ヘッダで叩くため、アプリの唯一の programmatic
navigation target の実 response を観測する。version 一致なら 200+X-Inertia、不一致なら 409+
X-Inertia-Location(=現 URL) になるが、どちらでも X-Inertia-Location は現 origin の /app 配下であり
/app 外 redirect ではないことを固定する。CDP の厳密 initiator には依存しない (設計どおり)。

### 検証結果
- `tests/Browser/CaptureAppBoundaryTest.php`: Chromium/WebKit 両レーン passed (各 2 tests / 13 assertions)。
- pint: passed。

## 更新後の live 観測テスト全文 (tests/Browser/CaptureAppBoundaryTest.php)
```php
<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\VideoManual;

/*
|--------------------------------------------------------------------------
| F-1-02 Phase A: 撮影 PWA の /app 離脱を実ブラウザで観測する (bug-hunt)
|--------------------------------------------------------------------------
|
| 設計 (devnotes/20260821-1517-bughunt-capture-manual/detailed-design.md 施策4) は
| 「証拠の正本はネットワーク上の最終 response」であり、クリーンな単一セッションを実ブラウザで
| 走らせて「撮影 PWA のアプリ自コードが /app 外への遷移を起こすか」を確定することを Phase A の
| 必須手順としている。本テストがその live 観測を担う (Playwright ハーネス = pest-plugin-browser)。
|
| 取れる観測は Performance API の範囲に限る (設計どおり CDP の厳密 initiator には依存しない):
|   - navigation entry (document 遷移) の URL 集合
|   - resource entry のうち fetch/xmlhttprequest の URL 集合
|   - 遷移後の window.location
| ヘッダ (X-Inertia-Location) は page JS から読めないため、document 遷移の「発生有無と行き先」で
| /app 離脱を判定する (離脱が起きれば navigation entry か location に現れる)。
|
| 判定: クリーンな単一セッションで撮影画面をマウントし、reloadManual / auto-download /
| サムネイル scheduler が一巡する時間を与えても、
|   (1) document は /app 配下に留まる (自動 /app 離脱が起きない)
|   (2) fetch/XHR は同一オリジンの /app 配下のみ (外部 programmatic visit が無い)
| ことを固定する。唯一の /app 外遷移は利用者クリックの明示リンク (PC 詳細 = T155) で、
| これは anchor の href として存在するが自動遷移しないことを併せて確認する。
|
| 結論の分岐 (設計 施策4 の (a)/(b)/(c)): 本観測でアプリ起因の /app 離脱は再現しない = (c)。
| ハーネス起因 (二重 fan-out) とは断定しない。
*/

/**
 * @return array{0: Project, 1: VideoManual}
 */
function captureBoundaryFixture(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization);

    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()
        ->forProject($project)
        ->create(['created_by' => $owner->id, 'status' => VideoManualStatus::Ready->value]);

    foreach (range(1, 3) as $index) {
        $cut = Cut::factory()->forManual($manual)->create(['sort_order' => $index]);
        Take::factory()->forCut($cut)->create();
    }

    test()->actingAs($owner);

    return [$project, $manual];
}

function captureBoundaryUrl(Project $project, VideoManual $manual): string
{
    return "/app/projects/{$project->id}/manuals/{$manual->id}";
}

test('クリーンな単一セッションで撮影画面は /app 配下に留まり自動 /app 離脱が起きない (F-1-02 Phase A)', function (): void {
    [$project, $manual] = captureBoundaryFixture();
    $url = captureBoundaryUrl($project, $manual);
    $firstCutId = $manual->cuts()->orderBy('sort_order')->value('id');

    $page = visit($url)->on()->desktop()->assertPathIs($url);

    // 観測開始時の期待 origin を保存し、以降の判定はこの固定値で行う
    // (遷移後 origin を正解にすると別 origin の /app へ移った場合を見逃すため。Codex Round3 [Suggestion])。
    $expectedOrigin = $page->script('window.location.origin');
    expect($expectedOrigin)->toBeString();
    // JS へ埋め込む期待 origin のリテラル (二重引用符・エスケープを json_encode に任せる)
    $phpJson = json_encode($expectedOrigin);

    // 通常フロー: カットを選び、mount 後の reloadManual / auto-download / scheduler が
    // 一巡する時間を与える (クリーンな単一セッション)。
    $page->click("[data-testid=\"cut-row-{$firstCutId}\"]");
    usleep(1_500_000);

    // (1) document は /app 配下に留まる (自動 /app 離脱が起きていない)
    $page->assertPathIs($url);
    expect($page->script("window.location.origin === {$phpJson} && window.location.pathname.startsWith('/app/')"))
        ->toBeTrue('document が期待 origin の /app 配下から外れた');

    // navigation entry (document 遷移) は当初の /app ドキュメントのみで、/app 外の
    // document 遷移が観測されない (期待 origin 固定で判定)。
    $externalDocNavigations = $page->script(<<<JS
        (() => {
            const origin = {$phpJson};
            return performance.getEntriesByType("navigation")
                .map((e) => e.name)
                .filter((name) => {
                    try {
                        const u = new URL(name, window.location.href);
                        if (u.origin !== origin) return true;
                        return !(u.pathname === "/app" || u.pathname.startsWith("/app/"));
                    } catch { return true; }
                });
        })()
    JS);
    expect($externalDocNavigations)->toBe([], '自動で /app 外へ document 遷移した');

    // (2) 受動観測: fetch/XHR resource entry のうち /app 外オリジン・パスは 0 件
    // (期待 origin 固定で判定)。
    $externalXhr = $page->script(<<<JS
        (() => {
            const origin = {$phpJson};
            return performance.getEntriesByType("resource")
                .filter((e) => e.initiatorType === "fetch" || e.initiatorType === "xmlhttprequest")
                .map((e) => e.name)
                .filter((name) => {
                    try {
                        const u = new URL(name, window.location.href);
                        if (u.origin !== origin) return true;
                        return !(u.pathname === "/app" || u.pathname.startsWith("/app/"));
                    } catch { return true; }
                });
        })()
    JS);
    expect($externalXhr)->toBe([], 'fetch/XHR が /app 外オリジン・パスへ飛んだ');

    // (3) 能動観測 = 証拠の正本 (ネットワーク最終 response の status + X-Inertia 系ヘッダ実値)。
    // reloadManual が叩く現 URL の Inertia visit を実 fetch し、サーバ応答を直接読む
    // (母集団非空を保証。Performance API では取れない status / ヘッダを観測する。Codex Round3 [Warning])。
    // - version 一致 → 200 + X-Inertia:true (部分リロード)
    // - version 不一致 → 409 + X-Inertia-Location (現 URL のハードリロード)
    // どちらでも X-Inertia-Location は**現 origin の /app 配下**でなければならない
    // (/app 外への redirect が起きれば離脱)。
    $reloadResponse = $page->script(<<<JS
        (async () => {
            const res = await fetch(window.location.href, {
                headers: {
                    "X-Inertia": "true",
                    "X-Requested-With": "XMLHttpRequest",
                    "Accept": "text/html, application/xhtml+xml",
                },
                credentials: "include",
                redirect: "follow",
            });
            const loc = res.headers.get("x-inertia-location");
            const origin = {$phpJson};
            const inApp = (raw) => {
                if (raw === null) return true; // ヘッダ無し = redirect 指示なし
                try {
                    const u = new URL(raw, window.location.href);
                    return u.origin === origin && (u.pathname === "/app" || u.pathname.startsWith("/app/"));
                } catch { return false; }
            };
            return {
                status: res.status,
                xInertia: res.headers.get("x-inertia"),
                xInertiaLocation: loc,
                finalUrl: res.url,
                locationInApp: inApp(loc),
                finalUrlInApp: inApp(res.url),
            };
        })()
    JS);

    // 母集団非空 (実 response を 1 件観測した)
    expect($reloadResponse)->toBeArray();
    // status は 200 (部分リロード) か 409 (現 URL ハードリロード) のいずれか (302 で /app 外へ飛んでいない)
    expect(in_array($reloadResponse['status'], [200, 409], true))
        ->toBeTrue('reload response status が 200/409 でない: '.json_encode($reloadResponse));
    // X-Inertia-Location が付く場合も現 origin の /app 配下 = /app 離脱の redirect ではない
    expect($reloadResponse['locationInApp'])->toBeTrue(
        'X-Inertia-Location が /app 外を指した (離脱 redirect): '.json_encode($reloadResponse)
    );
    expect($reloadResponse['finalUrlInApp'])->toBeTrue(
        'reload の最終 URL が /app 外だった: '.json_encode($reloadResponse)
    );
});

test('唯一の /app 外遷移は利用者クリックの明示リンク (PC 詳細 = T155) であり自動遷移しない', function (): void {
    [$project, $manual] = captureBoundaryFixture();
    $url = captureBoundaryUrl($project, $manual);

    $page = visit($url)->on()->desktop()->assertPathIs($url);
    usleep(500_000);

    // PC 詳細への明示リンクは anchor の href として存在する (= /app 外だが利用者主導)
    $href = $page->attribute('[data-testid="manual-detail-link"]', 'href');
    expect($href)->toContain("/projects/{$project->id}/manuals/{$manual->id}");

    // ただし待機しても自動では遷移せず /app に留まる (リンクは押されて初めて遷移する)
    $page->assertPathIs($url);
});

```

施策4 の証拠 (live 観測の母集団非空 + 実 response の status/X-Inertia/X-Inertia-Location 観測 +
origin 固定判定) が揃った。施策4 完了・施策5 条件付きスキップ成立・全体判定を再評価してほしい。
