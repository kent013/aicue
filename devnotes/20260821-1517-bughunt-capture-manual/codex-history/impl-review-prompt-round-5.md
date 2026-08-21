## Round 4 残 Warning 2 + Suggestion 1 への対応 (施策4 の response 契約を精密化)

origin 固定は承認された。残る「X-Inertia 未 assert / inApp(null)=>true の抜け」「能動 fetch が partial
reload と別 request」「stale コメント」に対応した。

### 対応
1. [Warning] X-Inertia を assert + 409 欠落を弾く:
   - good(200): `X-Inertia === "true"` と `X-Inertia-Location === null` を assert。加えて 200 本文の
     component をサーバ応答から読み `Capture/Show` を裏取り (注入値の echo でないこと)。
   - bad(409): `X-Inertia-Location` が非 null・非空・現 origin の /app 配下であることを assert。
   - `inApp()` は string 非空でなければ false を返す (409 でヘッダ欠落なら落ちる。inApp(null)=>true を廃止)。
2. [Warning] 実 reload と同一 request:
   partial-reload ヘッダを完全再現 — `X-Inertia-Version` (サーバ側で確定した Vite manifest 由来の実
   version を PHP から注入)、`X-Inertia-Partial-Component: Capture/Show`、`X-Inertia-Partial-Data: manual`、
   `X-Requested-With`。version 一致 (good) → 200 経路、故意の version 不一致 (bad) → 409 経路の**両方**を観測。
   これで「version 不一致 409 の X-Inertia-Location 実値が現 URL(/app 配下) を指す」= 現 URL ハードリロード
   であって /app 外離脱でないことを実 response で固定できた。
3. [Suggestion] 先頭コメントを訂正: 「受動 Performance API では読めないが、同一オリジンの能動 fetch では
   X-Inertia-Location を読める」。version/component 取得の stale コメントもサーバ注入に合わせ訂正。

### 検証結果
- tests/Browser/CaptureAppBoundaryTest.php: Chromium/WebKit 両レーン passed (Phase A 単独 16 assertions)。

## 更新後テスト全文 (tests/Browser/CaptureAppBoundaryTest.php)
```php
<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Http\Middleware\HandleInertiaRequests;
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
| 観測は 2 種を併用する (設計どおり CDP の厳密 initiator には依存しない):
|   - **受動 (Performance API)**: navigation entry (document 遷移) / resource entry (fetch・xhr) の
|     URL 集合、遷移後 window.location。ここでは response の status/ヘッダは取れない。
|   - **能動 (同一オリジン fetch) = 証拠の正本**: reloadManual と同じ partial-reload ヘッダで現 URL を
|     叩き、response の status・X-Inertia・X-Inertia-Location 実値を読む。同一オリジンの fetch なので
|     Performance API では読めないヘッダ実値まで観測できる (受動では読めないが能動では読める)。
| 判定は「document 遷移の発生有無と行き先」と「reload response のヘッダ実値」の両面で行う。
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

    // 能動観測の partial-reload ヘッダに使う Inertia version / component をサーバ側で確定する。
    // version は Vite manifest 由来でリクエスト非依存 (ブラウザの実 request でも同値になる)。
    // component は撮影詳細の Inertia ページ名。DOM の data-page は hydration 後に外れ、PWA の SW が
    // HTML fetch を横取りし得るため、クライアントから読まずサーバ側の確定値を注入する。
    $inertiaVersion = app(HandleInertiaRequests::class)->version(request());
    $versionJson = json_encode($inertiaVersion ?? '');
    $componentJson = json_encode('Capture/Show');

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
    // reloadManual (= router.reload({only:['manual']})) と**同じ partial-reload ヘッダ**で現 URL を
    // 実 fetch し、サーバ応答を直接読む (Performance API では取れない status/ヘッダを観測。母集団非空を保証)。
    // version・component はサーバ側で確定した値 (上記) を注入し、実 reload と一致させる。
    //   - good: 正しい version → 200 + X-Inertia:true (部分リロード成立)
    //   - bad : version 不一致を強制 → 409 + X-Inertia-Location (現 URL のハードリロード)
    // どちらでも X-Inertia-Location / 最終 URL は**現 origin の /app 配下**でなければならない
    // (/app 外への redirect が起きれば離脱)。
    $reloadResponse = $page->script(<<<JS
        (async () => {
            const origin = {$phpJson};
            const version = {$versionJson};
            const component = {$componentJson};
            const inApp = (raw) => {
                if (typeof raw !== "string" || raw === "") return false;
                try {
                    const u = new URL(raw, window.location.href);
                    return u.origin === origin && (u.pathname === "/app" || u.pathname.startsWith("/app/"));
                } catch { return false; }
            };
            const partialHeaders = (ver) => ({
                "X-Inertia": "true",
                "X-Inertia-Version": ver,
                "X-Inertia-Partial-Component": component,
                "X-Inertia-Partial-Data": "manual",
                "X-Requested-With": "XMLHttpRequest",
                "Accept": "text/html, application/xhtml+xml",
            });
            const good = await fetch(window.location.href, {
                headers: partialHeaders(version), credentials: "include", redirect: "follow",
            });
            // 200 の本文は Inertia partial の JSON。component をサーバ応答から読み、実 reload endpoint に
            // 当たったこと (= 注入 component の echo でなく) を裏取りする。
            let goodComponent = null;
            const goodXInertia = good.headers.get("x-inertia");
            if (goodXInertia === "true") {
                try { goodComponent = (await good.clone().json()).component; } catch { goodComponent = null; }
            }
            const bad = await fetch(window.location.href, {
                headers: partialHeaders("stale-version-forced-mismatch"),
                credentials: "include", redirect: "manual",
            });
            const badLoc = bad.headers.get("x-inertia-location");
            return {
                sentComponent: component,
                good: {
                    status: good.status,
                    xInertia: goodXInertia,
                    loc: good.headers.get("x-inertia-location"),
                    url: good.url,
                    urlInApp: inApp(good.url),
                    bodyComponent: goodComponent,
                },
                bad: {
                    status: bad.status,
                    loc: badLoc,
                    locInApp: inApp(badLoc),
                },
            };
        })()
    JS);

    // 母集団非空 (実 response を観測した)
    expect($reloadResponse)->toBeArray();

    // good: version 一致の部分リロードは 200 + X-Inertia:true + X-Inertia-Location 無し (離脱 redirect でない)。
    // 本文の component が Capture/Show = 実 reload endpoint に当たった裏取り (注入値の echo ではない)。
    expect($reloadResponse['good']['status'])->toBe(200);
    expect($reloadResponse['good']['xInertia'])->toBe('true', '部分リロードが Inertia 応答でない: '.json_encode($reloadResponse));
    expect($reloadResponse['good']['bodyComponent'])->toBe('Capture/Show', 'reload 応答が撮影画面の Inertia partial でない: '.json_encode($reloadResponse));
    expect($reloadResponse['good']['loc'])->toBeNull('200 応答に X-Inertia-Location が付いた: '.json_encode($reloadResponse));
    expect($reloadResponse['good']['urlInApp'])->toBeTrue('reload の最終 URL が /app 外だった: '.json_encode($reloadResponse));

    // bad: version 不一致の 409 は X-Inertia-Location 実値を持ち、それは現 origin の /app 配下
    // (= 現 URL のハードリロードであって /app 外への離脱 redirect ではない)
    expect($reloadResponse['bad']['status'])->toBe(409);
    expect($reloadResponse['bad']['loc'])->toBeString('409 に X-Inertia-Location 実値が無い: '.json_encode($reloadResponse));
    expect($reloadResponse['bad']['loc'])->not->toBe('');
    expect($reloadResponse['bad']['locInApp'])->toBeTrue('409 の X-Inertia-Location が /app 外を指した: '.json_encode($reloadResponse));
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

施策4 の Phase A live 観測 (母集団非空 + 実 response の status/X-Inertia/X-Inertia-Location 実値 +
200/409 両経路 + origin 固定) が揃った。施策4 完了・施策5 条件付きスキップ成立・全体判定を再評価してほしい。
