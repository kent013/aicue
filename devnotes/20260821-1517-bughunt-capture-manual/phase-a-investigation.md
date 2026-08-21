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
- **(2) live 観測**: pest-plugin-browser (Playwright) の Chromium / WebKit 両レーンで
  クリーンな単一セッションを実走させて観測した。実装は `tests/Browser/CaptureAppBoundaryTest.php`
  (2 テスト・両レーンで green。実測 13 assertions/レーン)。観測は 2 種:
  - **受動観測 (Performance API)**: document 遷移 (navigation entry) と fetch/XHR (resource entry) の
    URL 集合、遷移後 location を、**観測開始時に保存した期待 origin** で /app 配下か判定する。
  - **能動観測 (証拠の正本 = ネットワーク最終 response の status + ヘッダ実値)**: reloadManual が叩く
    現 URL の Inertia visit を実 fetch し、**response の status・`X-Inertia`・`X-Inertia-Location` 実値**を
    直接読む。version 一致は 200+`X-Inertia:true`、不一致は 409+`X-Inertia-Location`(=現 URL の
    ハードリロード) だが、いずれも `X-Inertia-Location` と最終 URL が**現 origin の /app 配下**である
    (= /app 外への redirect ではない) ことを固定する。Performance API では取れない status/ヘッダを
    この能動観測で補い、母集団非空 (実 response 1 件) も保証する。CDP の厳密 initiator には依存しない
    (設計どおり)。

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
    fetch/XHR が /app 配下のみ (0 件外部) であること、reload endpoint の**実 response の
    status/`X-Inertia`/`X-Inertia-Location` が /app 配下に留まる**こと、唯一の /app 外リンクが
    利用者主導で自動遷移しないことを固定する (能動 fetch がネットワーク最終 response を実観測)。
  - **JS 配線回帰**: `tests/js/pages/CaptureShow.test.ts` の describe
    「Capture/Show の /app 離脱防止 (F-1-02)」。
    - router の programmatic 入口 (reload/visit/get/post) を 1 本の collector に集約して観測する。
    - 通常フロー (キュー再開→reload) で collector が最低 1 件 (現 URL reload) を捕まえる (母集団非空)。
    - reload は url を持たない (現 URL 固定)。`/app/` 外への programmatic visit は 0 件。
    - **負のコントロールは実 mock 入口 (`router.visit/get/post`) へ禁止 destination を注入し、
      mock→collect→判定の配線ごと検出する** (判定用純関数の直呼びにしない)。
    - 観測点の保証範囲は同 describe の docblock が明示する (実 `<Link>` / form helper は観測点外で、
      その唯一の /app 外 destination は意図的な PC 詳細リンク = T155。live 側で anchor の非自動遷移を固定)。
