# shard-1 特化検証: F-1-02 再現確認

## 目的
撮影PWA (`capture.manuals.show`, `/app/projects/{p}/manuals/{m}`) で、カット選択・ファイル選択・
テイクアップロード・採用の直後にユーザー操作なしで `/app` 外へ「フルページ遷移」してしまう現象
(F-1-02, 20260821-095643-bug-hunt shard-1 report で High として記録) が、クリーンな単一
`playwright-cli -s=bughunt1` セッションで再現するか否かを確定する。

前回 (20260821-095643-bug-hunt) は shard-1 が二重走行していた形跡があり、ノイズ混入の疑いが
記録されている。T238 の Phase A 調査 (`devnotes/20260821-1517-bughunt-capture-manual/phase-a-investigation.md`)
では静的走査・自動ブラウザ観測 (pest-plugin-browser, Chromium/WebKit 両レーン) のいずれでも
アプリ自コード起因の `/app` 外自動遷移は再現せず、原因未確定 (分岐 c) と結論している。
本 run はその最終手動確認である。

## 環境
- shard: 1 / URL: http://127.0.0.1:8011 / session: bughunt1 (単一セッション厳守)
- db: bug_hunt_1 / users: 11 (db-check 確認済み)

## 手順ログ (逐次追記)

### セットアップ
- `--browser chromium` を明示しないと playwright-cli の既定チャンネル `chrome` (未インストール) で
  daemon が即死したため、`playwright-cli open --browser chromium ...` を使用 (bundled Chromium)。
- owner-personal@example.com / password123 でログイン。プロジェクト「F102 Verify Project」(id=1)、
  マニュアル「F102検証マニュアル」(id=1) を新規作成。既存 seed にマニュアルが無かったため、
  real-llm 解析を待たず `/projects/1/manuals/1/edit` のシナリオ編集で手動 4 手順 (材料を準備する/
  工具を準備する/組み立てる/仕上げを確認する) を追加して保存 (4 カット構成)。
- ヘッドレス Chromium に実カメラデバイスが無い (`navigator.mediaDevices.enumerateDevices()` → `[]`)
  ため、「録画開始」を押すと `onCameraUnavailable` が発火し `CaptureFileFallback` (ファイル選択) に
  自動的に切り替わる仕様を確認 (これ自体はアプリの意図した挙動)。`ffmpeg` で 1 秒のダミー mp4
  (`fixtures/take.mp4`, h264/yuv420p) を生成し、以降はファイル選択 (`capture-file-button` →
  `playwright-cli upload`) でテイクをアップロードした。

### 走行1周目: 4 カット全てで 選択→アップロード→採用 (計 8 回の書き込み操作)
`http://127.0.0.1:8011/app/projects/1/manuals/1` (単一 `bughunt1` セッション) で、カット1→2→3→4 の
順に「カット選択→ファイル選択ボタン→ファイル選択 (upload-url→PUT→POST takes)→採用ボタン
(POST adopt)」を実施。**各操作の直後に `playwright-cli requests` で直近の GET を確認したが、
`/app/projects/1/manuals/1` への GET は全て `type: xhr` (Inertia partial reload、
`x-inertia: true` / `x-inertia-partial-component: Capture/Show` ヘッダ付き、request 41/42/4/5/9/
14/18/23/27 で個別確認) であり、フルドキュメント navigation (`type: document`) は 1 件も
観測されなかった。`playwright-cli snapshot` の Page URL も毎回 `/app/projects/1/manuals/1` の
ままで、デスクトップの `/projects/1/manuals/1` や `/projects/1/manuals/1/edit` への遷移は
1 度も発生しなかった。4 カット × (選択+アップロード+採用) の 12 操作 (カット選択3回は明示クリック、
初回はデフォルト選択) を終えた時点で全 4 カットが「採用済」表示になった。

### 走行2周目: 各カットへの追加テイク (計 10 回超の追加書き込み) + 逸脱 (reload連打・go-back)
1周目に続けて、以下を単一セッション内で実施 (逸脱込みで write 操作は合計 20 回超):
- カット1へ2本目のテイクをアップロード→採用 (計10回目の write 操作)。
- カット2を選んだ直後に `playwright-cli reload` (フルブラウザリロード) を実行 → 撮影 PWA に留まる
  ことを確認 (Page URL 不変)。
- 「マニュアル詳細へ」リンク (`manual-detail-link`, `/projects/1/manuals/1`) を明示クリック →
  デスクトップの `projects.manuals.show` へ SPA 遷移 (これは意図された経路、T155)。
- `playwright-cli go-back` で撮影 PWA (`/app/projects/1/manuals/1`) へ復帰。以後、この
  「詳細へ→go-back」を撮影中に複数回繰り返す逸脱を行った。

### ★ 異常イベント (1回だけ観測): 無操作での desktop ページへの自動 Inertia visit
2周目のある回、「詳細へ→go-back で撮影 PWA に復帰→カット2選択→録画開始 (フォールバックへ切替)→
ファイル選択ボタンクリック→`playwright-cli upload`」という一連の操作の**直後**、
`playwright-cli requests --static` で以下の未クリックのリクエスト列を検出した
(採取した生ログをそのまま転記):

```
80. [GET] .../cuts/4/takes/.../? (テイク4 の署名URLダウンロード)
81. [POST] http://127.0.0.1:8011/app/projects/1/manuals/1/cuts/4/takes/4/downloaded => [200] OK
82. [GET] http://127.0.0.1:8011/app/projects/1/manuals/1 => [200] OK   ← 正常な reloadManual (type: xhr, x-inertia-partial-component: Capture/Show)
83. [GET] http://127.0.0.1:8011/projects/1/manuals/1 => [200] OK      ← ★ /app プレフィックスなし。x-inertia:true だが
                                                                          partial ヘッダ無し (= フル visit)。誰もクリックしていない
84-96. [GET] /build/assets/Show-CME-IASV.js, Input-j-kb4JEO.js, Select-7XUN0777.js,
       SourceDocumentUploadNotice-jZG7DFU2.js, DangerZone-DMArhjDb.js, FormField-1txEOg4i.js,
       FormError-CKQBwOuo.js, PageContent-DtPctIoT.js, format-bytes-B_LoiVdZ.js,
       manual-BUuxPwTv.js, sparkles-DUzULxNM.js, favicon-32x32.png ×2
       ← これらは **デスクトップの `Manuals/Show` ページ専用のコード分割チャンク**であり、
         Capture/Show では読み込まれない。実際に別ページのコンポーネントがマウントされようとした
         強い状況証拠
99. [POST] http://127.0.0.1:8011/app/projects/1/manuals/1/cuts/2/takes/upload-url => [200] OK  (自分がアップロードした操作の続き)
```

`request 83` のヘッダ詳細 (`playwright-cli request 83` で採取):
- `type: xhr` / `x-inertia: true` / `x-inertia-version: <一致>` / `x-requested-with: XMLHttpRequest`
- **`x-inertia-partial-component` / `x-inertia-partial-data` が無い** (= reloadManual のような
  partial reload ではなく、**フル Inertia visit**)
- `Purpose` / `Sec-Purpose: prefetch` ヘッダは**無い** (Chrome のネイティブ prefetch/prerender
  リクエストなら通常付与されるヘッダが無い一方、Inertia は `Purpose: prefetch` を明示的な
  prefetch visit にしか付けない実装であることを `public/build/assets/Icon-K-5HGNRc.js`
  (Inertia core が同梱された chunk) から確認済み。したがってこの1点だけでは「アプリの visit」か
  「Chromium 自身の投機的実行」かを判別できない)

**この直後に確認した `playwright-cli snapshot` の Page URL は `/app/projects/1/manuals/1` のまま**
で、タイトルも「...の撮影」のままだった (=可視画面は撮影 PWA に留まっていた。ユーザーから見える
形でデスクトップ画面に「取り残される」ところまでは確認できていない)。

### 再現の追試 (4回、いずれも上記アノマリーは再現せず)
上記が非決定的である可能性を踏まえ、`history.pushState/replaceState` フックと
`inertia:before/start/success/finish/popstate` イベントを `playwright-cli --raw eval` で
仕込んだ上で、以下の条件を変えた追試を計4回行った (詳細は各回とも同一パターン: 複数カットに
「採用済み・未DL」のテイクを作ってから「詳細へ→go-back」で撮影 PWA を remount させ、
その直後にカット選択→ファイル選択→アップロードを行う):
1. 未DL テイク1件 (カット4) の状態で remount → 再現せず (`inertia:*` の visitUrl は全て
   `/app/projects/1/manuals/1` に一致)。
2. 未DL テイク3件 (カット1・3・4) に増やして remount → 再現せず。
3. フル `playwright-cli reload` を挟んだ直後に「詳細へ→go-back→カット選択→アップロード」を
   実施 (異常イベント発生時の操作順に最も近づけた) → 再現せず。`inertia:before/start/success/
   finish` の `visitUrl` は毎回 `http://127.0.0.1:8011/app/projects/1/manuals/1` で一致していた。
4. 上記いずれの追試でも、`playwright-cli requests --static` に `/projects/1/manuals/1` への
   予期しない GET は 1 件も現れなかった。

### 考察
- 異常イベントの発生タイミング (「詳細へ→go-back」で直前に一度実際に訪問した
  `/projects/1/manuals/1` への、無クリックのフル visit + そのページ専用 JS チャンク読込) は、
  **Chromium の投機的実行 (Speculation Rules API ベースの back/forward 先読み prerender)**
  が疑わしい候補の1つである。理由: (1) `Purpose: prefetch` ヘッダが無いことは prerender
  (prefetch とは別処理) では付かない場合と整合する、(2) prerender は隠れフレームで対象ページの
  JS を実際に実行するため、Inertia 由来のヘッダ (`x-inertia`) 付きリクエストや同ページの
  コード分割チャンク読込が観測されうる、(3) 可視タブの URL/タイトルは最後まで撮影 PWA のまま
  だった (= 隠れコンテキストでの実行と整合)。**ただしこれは確証ではなく、Chromium の内部実装は
  playwright-cli からは直接観測できないため推測に留まる。**
- 一方、T238 Phase A の静的走査 (`resources/js/pages/Capture/`, `lib/capture/`,
  `components/features/capture/` 全域) は本 run でも独自に再確認したが、`window.location` /
  `location.assign` / `location.replace` / `Inertia::location()` の呼び出しは capture コードに
  存在せず、`router.visit/get/post` の呼び出し先も `reloadManual()` (url 引数なし = 現在地固定)
  以外に `/projects/{id}/manuals/{id}` を明示的に指定する経路は見当たらなかった。**アプリの
  programmatic な自動遷移コードが原因という仮説は今回も裏付けられなかった。**
- 上記を総合すると、観測した1回のアノマリーは「アプリが誤った URL へ意図的に visit した」証拠
  ではなく、「ブラウザ側の投機的実行 (または他の環境要因) によるバックグラウンドの取得」である
  可能性の方が高いと考えるが、**単発事象であり追試4回で再現しなかったため確証は得られていない**。

## 結論: **(C) 環境制約で判定不能 (ただし新規の手がかりあり)**

「撮影 PWA 内での操作直後に、ユーザー操作なくデスクトップ画面へフルページ遷移し、そこに
取り残される」という F-1-02 のオリジナルの記述 (画面が実際にデスクトップへ切り替わったまま
戻らない) は、本 run では**再現しなかった** (可視 URL/タイトルが撮影 PWA から動かなかったケースの
みで、B に近い)。

一方で、複数カットの選択・アップロード・採用・「詳細へ→go-back」を繰り返す 30 回超の書き込み
操作の中で **1 回だけ**、`/projects/1/manuals/1` (デスクトップ) への**未クリックのフル Inertia
visit + そのページ専用 JS チャンクの読込**という、F-1-02 と同系統の不可解なネットワーク挙動を
実際に観測した (証跡は上記「異常イベント」節)。これは無視できない事実であり、単純な
「ノイズだった」(B) とも断定できない。同一手順を条件を変えて4回追試したが再現せず、
Chromium 自身の投機的先読み (back/forward prerender heuristic) の可能性が高いと考えるが、
playwright-cli からはブラウザ内部の prerender 状態を直接観測できないため、アプリ起因か
ブラウザ起因かを本 run の手段だけでは切り分けきれなかった。

**推奨する次の一手 (親/実装セッションへの申し送り)**:
1. Chrome DevTools Protocol の `Preload.*` イベント (prerenderAttemptCompleted 等) を
   購読できるテストハーネス、または `chrome://net-export` の HAR ログで「該当リクエストに
   `Sec-Purpose: prefetch;prerender` 相当のフラグが付くか」を確認すれば、アプリ起因/
   ブラウザ起因を機械的に切り分けられる。
2. 応急処置として、`<meta name="speculationrules">` を明示的な空ルールで上書きするか、
   `Speculation-Rules` opt-out ヘッダ (もしあれば) を capture 系レスポンスに付与し、
   Chromium の自動 back/forward prerender を無効化した状態で同じ手順を再実行すれば、
   本件がブラウザ起因かどうかを確定できる。
3. 上記いずれも実施できるまでは、F-1-02 を「再現せず・クローズ」とはせず、
   「低頻度・原因未確定 (ブラウザ側投機的実行の疑い)」として観察を継続することを推奨する。

## findings
F-1-02 自体は「実バグとして断定的に再現」はしなかったため、severity 付きの finding としては
起票しない (誤検知をバグと断定しない、のスキル方針に従う)。ただし上記の異常イベントの証跡は
将来の追跡調査のために本レポートに残す。`findings.jsonl` は空 (該当なし)。

## 環境ハザード (EH)
なし (serve・DB とも走行中安定。`db-check` は終始 `bug_hunt_1` / `users: 11` で一致)。
