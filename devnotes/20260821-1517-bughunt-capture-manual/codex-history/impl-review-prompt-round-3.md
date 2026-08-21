## Round 2 残 Warning 2 件への対応 (施策4)

Round 2 で施策1〜3 は承認された。残る施策4 の 2 Warning (JS collector が設計の観測範囲を狭める /
Playwright 実観測が未実施で分岐 (c) 未到達) に対応した。

### 対応の核: live 実ブラウザ観測を実施した
設計が「証拠の正本はネットワーク最終 response」「Playwright ハーネスで実観測」と要求する Phase A を、
実際に pest-plugin-browser (Playwright) で実施した。新規 `tests/Browser/CaptureAppBoundaryTest.php`
(Chromium / WebKit 両レーンで green。実測 2 tests・8 assertions/レーン)。

観測結果 (クリーンな単一セッション。カット選択後 reloadManual/auto-download/scheduler が一巡する時間を付与):
1. document は /app 配下に留まる (Performance navigation entry の /app 外オリジン・パスは 0 件)。
2. fetch/XHR は同一オリジンの /app 配下のみ (0 件外部)。reloadManual の部分リロードは /app 配下 XHR。
3. 唯一の /app 外遷移は利用者クリックの明示リンク (PC 詳細 = T155)。anchor href として存在するが
   待機しても自動遷移せず /app に留まる。

これにより:
- **観測範囲を狭めていない**: 実 `<Link>` を含む document 遷移もブラウザ実測で捕捉される
  (JS mock collector は「アプリ自コードの programmatic 入口」の回帰として残し、範囲は docblock で正直に明示)。
- **分岐 (c) に到達**: 静的走査 + live 両レーン観測の双方でアプリ起因の自動 /app 離脱が再現せず、
  ハーネス起因とは断定しない。分類基準は「離脱が現れた場合に区別する枠組み」として残し、本観測では
  0 件だった旨を明記。

観測の限界も誇張しない: Performance API の範囲 (navigation/resource entry・location) に限り、
X-Inertia-Location ヘッダは page JS から読めないため document 遷移の発生有無と行き先で判定する
(離脱が起きれば navigation entry か location に現れる)。CDP の厳密 initiator には依存しない (設計どおり)。

## 新規 live 観測テスト全文 (tests/Browser/CaptureAppBoundaryTest.php)
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

    // 通常フロー: カットを選び、mount 後の reloadManual / auto-download / scheduler が
    // 一巡する時間を与える (クリーンな単一セッション)。
    $page->click("[data-testid=\"cut-row-{$firstCutId}\"]");
    usleep(1_500_000);

    // (1) document は /app 配下に留まる (自動 /app 離脱が起きていない)
    $page->assertPathIs($url);
    expect($page->script('window.location.pathname.startsWith("/app/")'))->toBeTrue();

    // navigation entry (document 遷移) は当初の /app ドキュメントのみで、/app 外の
    // document 遷移が観測されない。
    $externalDocNavigations = $page->script(<<<'JS'
        (() => {
            const origin = window.location.origin;
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

    // (2) fetch/XHR は同一オリジンの /app 配下のみ (外部 programmatic visit が無い)。
    // reloadManual は現 URL への部分リロード = /app 配下の XHR として現れる。
    $externalXhr = $page->script(<<<'JS'
        (() => {
            const origin = window.location.origin;
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

## 更新後の Phase A 調査記録 (phase-a-investigation.md 全文)
# F-1-02 Phase A 調査結果 (施策4)

## 目的
撮影 PWA (Capture/Show) の**アプリ自コード**が `/app/` 外への programmatic Inertia visit を
起こすかを確定し、施策5 (Phase B 恒久ガード) の実施可否を判断する。

## 調査手段
本調査は **(1) アプリソースの静的走査** と **(2) 実ブラウザ (Playwright) による live 観測** の
2 段で行った。

- **(1) 静的走査**: capture 関連ディレクトリ全体の programmatic navigation 走査 (下表)、
  `CaptureManualController::show` が render のみで redirect を持たないことの確認
  (既存 `CaptureManualBrowsingTest` が 200 render を固定済み)。`window.location` 系 API・
  `router.visit/get` を capture コードは一切呼んでおらず、動的に文字列生成する遷移の起点自体が無い。
- **(2) live 観測 (証拠の正本)**: pest-plugin-browser (Playwright) の Chromium / WebKit 両レーンで
  クリーンな単一セッションを実走させ、Performance API で document 遷移・fetch/XHR の URL 集合と
  遷移後 location を観測した。実装は `tests/Browser/CaptureAppBoundaryTest.php`
  (2 テスト・両レーンで green。実測 8 assertions/レーン)。取れる観測は Performance API の範囲に限る
  (設計どおり CDP の厳密 initiator には依存しない)。`X-Inertia-Location` ヘッダは page JS から
  読めないため、document 遷移の**発生有無と行き先**で /app 離脱を判定する
  (離脱が起きれば navigation entry か location に現れる)。

## 静的一次調査 (grep によるアプリコード走査)
`resources/js/pages/Capture/`, `resources/js/lib/capture/`,
`resources/js/components/features/capture/` を対象に programmatic navigation を走査した:

| 呼出 | 箇所 | destination | 分類 |
|------|------|-------------|------|
| `router.reload({only:['manual']})` | `Capture/Show.svelte:132` (`reloadManual`) | 現 URL (url 引数なし) | 現 URL 部分リロード (許可) |
| `router.get('/app/projects/{id}/manuals', ...)` | `Capture/Index.svelte:52` | `/app/...` | in-app (許可)。Show ではない |
| `router.post(...)` | `Capture/Account.svelte:49` | ログアウト | 明示的な認証離脱 (意図)。Show ではない |
| `<Link href="/app/projects/{id}/manuals">` | `Capture/Show.svelte:482` | `/app/...` | in-app (許可) |
| `<Link href="/projects/{id}/manuals/{id}">` | `Capture/Show.svelte:489` | `/projects/...` (=/app 外) | **利用者クリックの明示リンク** (PC 詳細への復路 = T155。`docs/architecture.md §撮影 PWA の運用契約`) |

- `window.location` / `location.assign` / `location.replace` / `Inertia::location()` は
  capture コードに**存在しない** (grep で 0 件)。

## 遷移種別の分類基準 (観測時に区別する枠組み。設計 施策4 の調査手順)
live 観測 (上記 (2)) で /app 離脱が現れた場合に、以下を区別して記録する枠組み
(本観測では /app 離脱が 0 件だったため、いずれの分類にも該当する事象は現れなかった):

1. **アセット version 不一致による `409`**: 現在 URL のハードリロード。
2. **アプリが明示する `Inertia::location()`**: `X-Inertia-Location` ヘッダ**実値**の URL への
   ハードビジット。
3. **`window.location` / ハーネス操作**: Inertia 外の document navigation。
4. 記録手段は Playwright ハーネスで取れる範囲 (request の `resourceType` = document/xhr/fetch、
   URL、response の `X-Inertia` / `X-Inertia-Location` ヘッダ) に限定し、ステータスコードだけでなく
   `X-Inertia-Location` の実値を残す。`beforeunload` は補助観測に格下げ。

## live 観測の結果 (tests/Browser/CaptureAppBoundaryTest.php。Chromium/WebKit 両レーン green)
クリーンな単一セッションで撮影画面をマウントし、カット選択後に reloadManual / auto-download /
サムネイル scheduler が一巡する時間 (1.5s) を与えた上で観測した:

1. **document は /app 配下に留まる**: 遷移後 `window.location.pathname` は `/app/` 配下のまま。
   navigation entry のうち /app 外オリジン・パスへ向かうものは **0 件** (自動 /app 離脱なし)。
2. **fetch/XHR は同一オリジンの /app 配下のみ**: resource entry (fetch/xmlhttprequest) のうち
   /app 外オリジン・パスは **0 件**。reloadManual の部分リロードは /app 配下の XHR として現れる。
3. **唯一の /app 外遷移は利用者クリックの明示リンク**: PC 詳細リンク (`manual-detail-link`) は
   anchor の href として `/projects/{id}/manuals/{id}` を持つが、待機しても**自動遷移せず** /app に留まる
   (押されて初めて遷移する = T155 の意図的経路)。

## 結論 (設計の 3 分岐のうち (c))
静的走査と live ブラウザ観測 (両レーン) の双方で、**「Capture/Show のアプリ自コードが起こす
`/app/` 外への自動遷移 (document navigation / programmatic Inertia visit)」は再現しなかった。**
唯一の `/app/` 外遷移は利用者がクリックする明示 Inertia `<Link>` (PC 詳細への復路。運用契約で
意図済み = T155) であり、自動では発火しない。

- bug-hunt が観測した `/app/` 離脱の候補は、(i) この意図的な明示リンク、または
  (ii) ハードビジット (409 アセット version 不一致 / 認証失効 302→`X-Inertia-Location` /
  ブラウザ back/forward) が残るが、**本観測ではいずれも自動発火として再現しなかった**ため発生源は未確定。
- 設計の 3 分岐では **(c) アプリ起因を再現できず原因未確定** に該当する。
  **ハーネス起因 (二重 fan-out) とは断定しない** (分岐 (b) を主張する時系列対応データを取っていない)。

## 帰結
- **施策5 (Phase B 恒久ガード = navigation-guard.ts) は実装しない。** 静的走査でアプリ自コードの
  /app 外 programmatic visit が存在せず (再現できず)、単一事象へ包括ガードを足すのは過大
  (AGENTS.md 思考原則 2 / 設計 Codex Round2 総括)。設計の risk 節も「非再現時は結論として
  記録し回帰テストを恒久的に残す」を許容している。
- 施策4 の回帰テストは恒久的に残す (2 段):
  - **live 実ブラウザ観測**: `tests/Browser/CaptureAppBoundaryTest.php` (Chromium/WebKit 両レーン)。
    クリーンな単一セッションで document が /app 配下に留まり (navigation entry の /app 外 0 件)、
    fetch/XHR が /app 配下のみ (0 件外部) であること、唯一の /app 外リンクが利用者主導で自動遷移
    しないことを固定する。証拠の正本 (ネットワーク最終 response 相当の Performance API 観測)。
  - **JS 配線回帰**: `tests/js/pages/CaptureShow.test.ts` の describe
    「Capture/Show の /app 離脱防止 (F-1-02)」。
    - router の programmatic 入口 (reload/visit/get/post) を 1 本の collector に集約して観測する。
    - 通常フロー (キュー再開→reload) で collector が最低 1 件 (現 URL reload) を捕まえる (母集団非空)。
    - reload は url を持たない (現 URL 固定)。`/app/` 外への programmatic visit は 0 件。
    - **負のコントロールは実 mock 入口 (`router.visit/get/post`) へ禁止 destination を注入し、
      mock→collect→判定の配線ごと検出する** (判定用純関数の直呼びにしない)。
    - 観測点の保証範囲は同 describe の docblock が明示する (実 `<Link>` / form helper は観測点外で、
      その唯一の /app 外 destination は意図的な PC 詳細リンク = T155。live 側で anchor の非自動遷移を固定)。


## 検証結果
- `tests/Browser/CaptureAppBoundaryTest.php`: Chromium/WebKit 両レーン passed (各 2 tests / 8 assertions)。
- composer phpstan: No errors。pint --test: passed。他レーンは Round 2 対応前から緑を維持。

以上を踏まえ、施策4 の完了 (live 観測 + 空振りしない回帰 + 記録) と施策5 条件付きスキップの成立、
全体判定を再評価してほしい。
