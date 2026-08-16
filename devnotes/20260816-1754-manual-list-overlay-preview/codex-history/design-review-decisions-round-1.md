# 対応マトリクス: design-review Round 1

Codex 全体判定: **CHANGES_REQUESTED** (Critical 2 / Warning 6 / Suggestion 2)。

## [Critical] 施策 1: DTO が `latestSucceededRender` + `output_path` で id を決めるのは T154 違反
- 判断: **対応する**
- 根拠: 指摘が正しい。bool を返す間は「受け取れるかの副次判定」に見えたが、**id を返す瞬間に
  「どの行か」を選んでいる**。`RenderArtifactSelectionKind::EagerLoadCandidate` の docblock 自身が
  「決定は Canonical に残る」と書いており、実装をその明言へ合わせる変更でもある。
- 対応内容: `CurrentRenderArtifact` に**一覧向けの入口**
  `fromLoadedRenderCandidate(VideoManual): ?RenderJob` を追加し、
  「実体が残っている行だけを返す」規則を private `receivable()` に 1 本化した
  (`currentSucceeded()` も同じ helper を通す = 規則が 2 度書かれない)。
  DTO からは `output_path` / `latestSucceededRender` の参照が消え、残るのは Canonical が
  持たない責務 (published 判定 / ability 判定) だけになった。
  クエリは撃たない (eager load 済み relation を読むだけ) ので一覧のクエリ本数は不変。
  T154 目録の登録変更は不要 (Canonical は登録済み / DTO は母集団に入らない) ことも設計に明記した。

## [Critical] 施策 5: 「選択が Canonical 経由であること」を固定するテストが無い
- 判断: **対応する**
- 根拠: parity だけでは複製しても緑になる、というのは事実。ただし新しい目録を作るのは過剰なので、
  既存の T154 Architecture テストにケースを 1 本足す形にする。
- 対応内容: `tests/Architecture/CurrentRenderArtifactInventoryTest.php` に
  「一覧行 DTO は受け取り可否の規則を自前で持たず Canonical へ委譲する」を追加
  (`ManualListItemData.php` の token に `output_path` / `latestSucceededRender` が現れず、
  `CurrentRenderArtifact` が現れることを既存の `PhpTokenScan::normalize()` で検査)。
  **保証範囲を誇張しない**注記 (このファイル 1 本にしか効かない) も設計へ書いた。

## [Warning] 施策 1: `current_finished_render_job_id` は kind=render 専用であることが伝わりにくい
- 判断: **対応する**
- 根拠: 一覧から preview を返さないことは重要な契約で、テスト名に出しておく価値がある。
- 対応内容: Feature テスト名に kind=render を明示し、「preview の succeeded しか無い行は null」の
  ケースを維持することをテスト計画に明記した。加えて
  `fromLoadedRenderCandidate()` は **kind 引数を取らない** 設計にして
  (候補 relation が kind=render 固定)、「一覧から preview を選べる」誤読を型で消した。

## [Warning] 施策 2: `preload="metadata"` は開いた時点で playback へ GET する
- 判断: **対応する (仕様として引き受け、契約化する)**
- 根拠: 発行の契機はユーザーが「プレビュー」を押した 1 回だけで、一覧描画では 0 件。
  `preload="none"` にすると「プレビューを押したのに黒い箱が出てもう一度再生を押す」二度手間になり、
  導線の名前 (プレビュー) と挙動が食い違う。`RenderPanel` が `none` なのは
  「画面を開くたびに自動で走る」のを避けるためで前提が違う。
- 対応内容: 設計に「`preload="metadata"` の契約」節を追加し、Vitest で
  `preload="metadata"` を固定するケースを計画に足した。

## [Warning] 施策 3: 別経路から `onRequestPreview` が呼ばれたときモーダルが null id を握り潰すだけ
- 判断: **対応する (計画済みテストの必須化)**
- 根拠: 指摘どおり現状のままでよい。守りは「モーダル側でも id が null なら video を描画しない」。
- 対応内容: 該当 Vitest ケースを必須として明記 (既に計画済み。文言を「必須」に更新)。

## [Warning] 施策 4: 閉じた後も `previewManualTarget` を保持する
- 判断: **一部対応 (テスト維持で受ける。reset handler は見送る)**
- 根拠: 描画は `open === true` の間だけで、開く操作は必ず対象を入れ替える。
  部分再読込後に古い行が残っても、最終判断は endpoint (旧世代 404 / 権限喪失 403) であり
  props は秘匿境界ではない。reset 配線は消せるリスクが無い分の追加。
- 対応内容: 設計に「閉じた後に対象行を保持することについて」節を追加して根拠を残し、
  「open=false では video が DOM から消える」Vitest ケースを維持する。

## [Warning] 施策 5: 撮影者ケースは 403 と 404 が混ざらないデータにすべき
- 判断: **対応する**
- 根拠: playback は「nested 404 → authorize 403 → published/現行世代 404」の順なので、
  データが不備だと 403 を見たつもりで 404 を見る。
- 対応内容: 撮影者ケースを「published + 現行世代 succeeded + output_path あり
  (= 編集者なら 302 になる状態)」で組み、編集者の 302 を対照として同じテストに置いた。

## [Warning] 施策 6: disabled 禁止テストが fixture 既定に依存する
- 判断: **対応する**
- 対応内容: 当該ケースで `current_finished_render_job_id: 9, deletable: true` を明示するようにした。

## [Suggestion] 施策 2: `{#if manual !== null && playbackSrc !== null}` にして型と意図を揃える
- 判断: **対応する**
- 対応内容: 条件を 2 つに分け、`aria-label` を `manual.title` (optional chain 無し) にした。

## [Suggestion] 施策 3: 狭幅の見た目は Playwright で 1 ケース見ると安心
- 判断: **見送る (根拠を設計に残す)**
- 根拠: Browser レーンは Chromium + WebKit の 2 レーン契約で、ログアウト復元など**恒久回帰**の
  検証に使っている。スクリーンショット比較の基盤は持っておらず、「窮屈さ」を機械判定できない。
  ボタン 1 つの追加のためにレーンを増やす費用は見合わない (思考原則: 今必要なものだけ作る)。
  縦積みへ逃がすレイアウト規則は T182 で導入済みで、追加ボタンはその内側に収まる。
- 対応内容: 施策 3 のリスク節にこの判断と根拠を明記した。

## 追加で自発的に直した点 (レビュー指摘外)
- rename (`ManualRowDownloadableParityTest` → `ManualRowFinishedVideoParityTest`) に伴い、
  **追跡下のコード 2 箇所**の参照を更新する必要があることを波及変更へ追加した:
  `app/Models/VideoManual.php` の docblock と
  `app/Support/Security/RenderArtifactSelectionInventory.php` の `EagerLoadCandidate` 根拠文。
  `docs/TODO-closed.md` の T182 行は**過去の記録なので書き換えない**ことも明記した。
