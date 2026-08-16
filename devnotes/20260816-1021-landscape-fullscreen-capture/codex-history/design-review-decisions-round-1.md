# 対応マトリクス: design-review Round 1

## [Critical] 施策 C: `max-w-[90%]` は design token ではない

- 判断: **対応する** (任意値そのものを消す)
- 根拠: ds-purity の禁止パターンに任意値の `max-w-` は含まれないため機械検査は通るが、
  「token 経由で参照する」という DESIGN.md の趣旨からは外れる。
  そして本件は**任意値を使わなくても同じ見た目になる** —
  overlay のコンテナが既に `px-3` を持つので、内側の `<p>` は `max-w-full` で十分に収まる。
  既存の `SubtitleOverlay` に倣うことより、新設分で任意値を増やさないことを優先する。
- 対応内容: `max-w-[90%]` → `max-w-full` に変更。既存 `SubtitleOverlay` は**触らない**
  (今回の目的外の改変を混ぜない)。

## [Critical] 施策 D: 初回描画で一瞬 inline レイアウトが出る (ちらつき)

- 判断: **対応する** (指摘のとおり。しかも修正すると設計が単純になる)
- 根拠: 元案は「購読で `landscapeMatches` を書く effect」と「先頭カットを自動選択する effect」を
  分けていた。2 つの effect は別々に走るため、`landscapeMatches=true` が反映された描画と
  `selectedCutId` が入った描画の間に 1 フレーム挟まる。
- 対応内容:
  1. **自動選択とラッチ解除を購読 callback の中へ移し、同じ同期ブロックで両方を書く**
     (Svelte 5 は同一同期ブロックの状態更新をまとめて 1 回描画する)。effect が 2 本から 1 本へ減る。
  2. `fullscreenActive` の条件を `selectedCut !== null` から
     **`manual.cuts.length > 0`** へ変える。「全画面に入る意図」を選択状態から切り離す。
  3. この変更で**別の詰みも塞がった**: 全画面中に `reload` で選択中カットが消えた場合、
     旧案では「全画面なのに終了ボタンが無い」状態になり得た。
     新案は終了ボタンを `fullscreenActive` の直下 (選択の有無に依らない位置) に置く。

## [Critical] 施策 D: `enterFullscreen` ボタンがカット 0 件でも出る

- 判断: **対応する**
- 根拠: 設計自身が「押しても何も起きない、を作らない」と書いているのに条件が緩かった。
- 対応内容: 可視条件を `landscapeMatches && !fullscreenActive && manual.cuts.length > 0` にした。
  カット 0 件のときの component テストを追加した。

## [Warning] 施策 A: `addEventListener` のみ前提。対象ブラウザの最低バージョンが設計内で閉じていない

- 判断: **対応する**
- 根拠: `docs/supported-browsers.md` の「対象ブラウザ」節は面 (撮影 PWA / 管理画面) と
  ブラウザ名までしか定めておらず、**最低バージョンは書かれていない**。
  設計側で前提を明示しないと、実装者が「古い Safari も拾うべきか」を判断できない。
- 対応内容: 施策 A に **「依存する Web 機能と最低バージョン前提」の表**を新設し、
  `MediaQueryList.addEventListener` / `inert` / `h-dvh` / Pointer Events /
  `pointer` `orientation` media feature の 5 つをまとめた。
  施策 F の `docs/supported-browsers.md` 追記からこの表を参照する形にして、
  版の情報が 2 か所に散らないようにした。テスト名にも
  「legacy MediaQueryList (addListener) は対象外」と残す。

## [Warning] 施策 B: `role="group"` + `tabindex="0"` で Tab 停止が増える

- 判断: **対応する**
- 根拠: バー自体をフォーカス可能にする必要は無い。**キーイベントは内側のボタンから
  バブルしてくる**ので、`tabindex` を外しても
  「前後ボタンにフォーカスがある状態で左右キー」は成立する。
  Tab 停止は「前のカット」「次のカット」の 2 つだけになり、操作が短くなる。
- 対応内容: `tabindex="0"` を削除。`role="group"` + `aria-label` は残す
  (2 つのボタンが 1 つの目的を持つことを伝えるため)。
  Svelte の a11y lint は非対話要素へのイベントを警告するため、
  `svelte-ignore` を**理由コメント付き**で置く (先例: 同ファイル群の `a11y_media_has_caption`)。
  テスト計画に「Tab で到達するのは前後ボタンの 2 つだけ (バー自体は停止しない)」を追加した。

## [Warning] 施策 B: `window.innerWidth` の直読み

- 判断: **対応する**
- 根拠: 非ブラウザ実行で壊れる形を新設する理由が無い。
- 対応内容: `viewportWidth()` の内部関数へ切り出し、
  `typeof window === "undefined" ? 0 : window.innerWidth` にした。
  幅 0 のときは `resolveSwipe` の右端除外が常に真になり **移動しない側へ倒れる**
  (安全側。`panel-navigation.ts` の `prefersReducedMotion()` が
  非対応環境で「動かさない」へ倒すのと同じ思想)。

## [Warning] 施策 C: `shootingGuideText` の trim 済み文字列を描画にも渡していてコメントと矛盾

- 判断: **対応する**
- 根拠: 指摘のとおり。`SubtitleOverlay` は「trim は空判定のみに使い、描画には元文字列」と
  明記して実際にそうしているのに、こちらは trim 済みを渡していた。
- 対応内容: `showShootingGuide` (空判定) と `text={shootingPoint ?? ""}` (描画) に分けた。

## [Warning] 施策 C: 全画面の操作行が `absolute bottom-0` で背景なし = 可読性リスクが受入基準に落ちていない

- 判断: **対応する** (ただし「背景色を足す」ではなく**重ねるのをやめる**)
- 根拠: 半透明の帯を足すと、その上に載るアイコン色 (`text-text-secondary`) の
  コントラストを別途担保する必要が生まれ、`contrast-invariant` の検査対象も増える。
  **仕組みが機能していない段階で値 (色) を弄るな** (思考原則)。
  操作行を映像の上に重ねる必然性は無い — 映像を `flex-1` にして操作行を
  不透明な `bg-surface` の帯として下に置けば、可読性の問題そのものが消える。
  doc/05 の「中央の赤い録画ボタン」も満たす。
- 対応内容: 全画面時も操作行を絶対配置にせず通常のフレックス子のままにした
  (`shrink-0` だけ足す)。あわせて `error` の `bottom-14` という経験値も消えた
  = 施策 C のリスク 2 件が両方とも設計から消えた。
  実機受入確認の項目 5 は「字幕・撮影ガイドの overlay が明るい被写体の上で読めるか」に絞った。

## [Warning] 施策 D: `inert` の範囲が背後全体を覆っていない

- 判断: **一部対応する + 残りは根拠を添えて反論する**
- 根拠:
  - **対応する部分**: このページ自身のコンテンツ (ヘッダ / アップロード状況 / 左 pane) は
    `inert` で覆える。加えて**全画面へ入った時にフォーカスを全画面内へ運ぶ**ことで、
    キーボード利用者の開始位置が背後に残る問題を消す
    (既存 `capture-recording-heading` と同じ `tabindex="-1"` 見出しの作法)。
  - **反論する部分**: 「背後領域を 1 つの wrapper にまとめ、全画面 section を wrapper の外へ置く」
    は**この設計では採れない**。`inert` は position に関係なく子孫へ伝播するため、
    全画面 section を wrapper の内側に置いたままでは無効化されてしまう。
    外へ出すには section を grid の外の兄弟にする必要があり、そうすると
    **通常時の 2 カラム grid の 2 番目のセルでなくなる** = 既存レイアウトが壊れる。
    セルを保ったまま外へ出す手段 (portal) を使うと `CameraRecorder` が
    **別ツリーへ再マウントされ、不変条件 1 (録画データの保護) を直接壊す**。
    つまりこの指摘の修正案は、本設計の最優先の不変条件と両立しない。
  - `AppLayout` の chrome (モバイルヘッダのメニューボタン / サイドバー) は
    **意図的に `inert` にしない**。全画面の描画が壊れたときに残る唯一の脱出路であり、
    覆ってしまうと「行き先のない詰み」を新設することになる。
    視覚的には z-40 の不透明な面が覆っているので情報は見えない。
- 対応内容:
  1. `inert` を「ヘッダ wrapper」「左 pane」に付ける (page 自身のコンテンツ)。
  2. 全画面に `tabindex="-1"` の見出しを置き、入った直後にフォーカスを運ぶ。
  3. テスト計画に **「全画面中に Tab で `cut-row-*` / `manual-detail-link` へ到達しない」**を追加。
  4. 「`AppLayout` の chrome は覆わない」を設計の不変条件 6 として明文化した
     (後から善意で塞がれないようにする)。

## [Warning] 施策 D: `navigationNotice` がカット移動以外で消えない

- 判断: **対応する** (ただし effect ではなく呼び出し側で消す)
- 根拠: 指摘のとおり、端の告知や録画中エラーが状況の変化後も残る。
  ただし依存を並べるだけの `$effect` は、Svelte 5 で「読んだことにする」ための
  不自然な式が要り、lint とも衝突しやすい。**告知を出す/消す契機はすべて関数呼び出しの中**に
  あるので、そこで消すのが素直で読める。
- 対応内容: 次の 4 か所で `navigationNotice = null` にする —
  `handleSelectCut` (カットを選び直した) / `enterFullscreen` / `exitFullscreen` /
  `onCaptureActiveChange` の callback (**録画開始でも停止でも消す**。
  停止したのに「録画中は移動できません」が残るのを防ぐ)。
  `handleCutNavigate` は既に移動成功時に消している。

## [Warning] 施策 E: 録画中の抑止のページ配線がテストされない

- 判断: **対応する** (Codex の案より強い形が取れることが分かった)
- 根拠: 既存 `tests/js/components/features/capture/CameraRecorder.test.ts` に
  **`FakeMediaRecorder` + `getUserMedia` stub の先例がある**。
  これを共有ヘルパへ切り出せば、`CameraRecorder` を**本物のまま**録画状態へ駆動できる。
  component を stub に差し替える案より、実際の `onCaptureActiveChange` 経路を通るぶん
  配線ミスの検出力が高い (2 段構成に逃げる必要がなくなる)。
- 対応内容: `tests/js/support/fake-media-recorder.ts` (新規) に既存 stub を移して共有し、
  `CaptureShow.test.ts` で「全画面 → 録画開始 → 次のカット」を実行して
  `cut-navigation-error` が出ること・`cut-swipe-label` が変わらないことを固定する。
  既存 `CameraRecorder.test.ts` は import 元が変わるだけで**テスト本体は書き換えない**。

## [Warning] 施策 E: `pointer: coarse` の前提が Playwright context 依存

- 判断: **対応する**
- 根拠: 前提が崩れたときに「全画面にならない」だけが観測され、原因が
  実装の回帰なのかハーネスの都合なのか区別できない (空振りするテストになる)。
- 対応内容: Browser テストの各ケースの冒頭で
  `matchMedia('(pointer: coarse)').matches` と `LANDSCAPE_CAPTURE_MEDIA_QUERY` の
  評価結果を assert する **正のコントロール**を置く。

## [Suggestion] 施策 A: 極小 viewport で右端除外が常に真になる

- 判断: **対応する**
- 根拠: 実害は小さいが、仕様として固定しておけば将来の「なぜ動かない」を防げる。
  かつ `viewportWidth()` が 0 を返す経路 (上記 B-W2 の対応) と同じ挙動になり、
  安全側へ倒れることを 1 件のテストで示せる。
- 対応内容: `resolveSwipe()` のテストに「viewport 幅が除外幅の 2 倍以下なら常に `none`」を追加。

## [Suggestion] 施策 F: 最低バージョン前提を 1 か所にまとめる

- 判断: **対応する** (施策 A の対応に含めた)
- 根拠・対応内容: 上記 [Warning] A-1 参照。
