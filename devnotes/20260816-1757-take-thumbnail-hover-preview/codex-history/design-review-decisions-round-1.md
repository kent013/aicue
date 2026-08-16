# 対応マトリクス: design-review Round 1

Codex 全体判定: CHANGES_REQUESTED (施策 1 / 2 / 4 が REQUEST_CHANGES、施策 3 は APPROVE)

## [Warning] 施策 1: `AdoptedTakeReferenceInventory` の根拠文が事実とずれる

- 判断: **対応する**
- 根拠: 目録の根拠文はレビューで読まれる説明であり、`thumbnail_path` を読むようになった後も
  「id と status を表示するため」のままだと実態と食い違う。AGENTS.md の目録運用の趣旨に反する。
- 対応内容: 提示された修正案をほぼそのまま採用し、詳細設計の波及変更に
  `app/Support/Security/AdoptedTakeReferenceInventory.php` を**変更ファイルとして追加**した。
  施策一覧の表と施策 4 の「新規登録不要」の記述も「**区分は維持・根拠文は更新**」へ直した。

## [Warning] 施策 1: `status === "ready" && has_thumbnail` の語彙が充足判定と混ざらないようにする

- 判断: **対応する**
- 根拠: ドメイン規約 12 は判定式の単一化を求めており、語彙が混ざると次に読む人が
  `AdoptedReadyTakeCoverage` と同一概念だと誤解する。区分自体は変えなくてよいという判断も一致した。
- 対応内容: 「これは**サムネイル表示条件 / 404 を踏まない条件**であって充足判定ではない」と明記し、
  **`coverage` / `充足` / `ready coverage` の語をコメント・変数名・テスト名に使わない**と規定した。
  変数名は `previewable` のまま (充足を連想させない)。

## [Warning] 施策 2: `onDestroy` の `document.removeEventListener` は SSR 耐性が弱い

- 判断: **対応する (ただし提示案とは別の手段を採る)**
- 根拠: 指摘の懸念自体は正しい。ただし `typeof document !== "undefined"` の自前 guard は、
  **このリポジトリに SSR レーンが存在しない**(`vite.config.ts` / `package.json` に ssr entry が無く
  `config/inertia.php` も無い = 実読で確認) 状況では、存在しない実行環境のための分岐になる
  (思考原則 2)。より根本的なのは**登録と解除が別のライフサイクルに分かれていること**である。
- 対応内容: `onMount` の**返り値 (後始末)** で解除する形に変えた。`onMount` は非ブラウザでは走らず、
  返した後始末もブラウザでしか走らないため、**guard を書かずに対称**になる
  (フレームワークのレンジ内でやる = 思考原則 1)。`onDestroy` には document に触らない
  `clearDwell` だけを残した。

## [Warning] 施策 2: `startPreview()` で `playbackUrl !== null` を再確認していない

- 判断: **対応する**
- 根拠: 「満了時に現在の条件を再確認する」と設計に書いた以上、URL の有無だけ入口の 1 回で
  済ませるのは一貫していない。
- 対応内容: `startPreview()` に `if (playbackUrl === null) return;` を追加した。

## [Warning] 施策 4: テスト計画に SSR / 非ブラウザ耐性が無い

- 判断: **対応する**
- 対応内容: テスト計画に「listener の対称性」ケース (unmount 後に `visibilitychange` を発火しても
  `stopPreview()` が呼ばれない) を追加し、**SSR レーンがリポジトリに存在しないことを実読で確認した**
  事実と、非ブラウザ実行は**テストではなく `onMount` の対で書く構造**で担保することを明記した。

## [Suggestion] 施策 3: 非 ready のとき `/thumbnail` `/playback` の文字列が DOM に無いことも固定する

- 判断: **対応する**
- 対応内容: ScenarioEditor のテスト計画に 1 ケース追加した。

## [Suggestion] `prefersReducedMotion` を `@/lib/browser/motion` 等へ移す

- 判断: **見送る**
- 根拠: (1) 既に `features/manual` の `TakePickerList.svelte` / `TakePreviewPanel.svelte` が
  `@/lib/capture/http` / `@/lib/capture/take-endpoints` を import しており、**先例がある**。
  (2) 移設は既存の呼び出し元 (`pages/Capture/Show.svelte` / `CutSwipeBar.svelte` ほか) を巻き込む
  改名であり、本施策の目的と無関係な差分を増やす。
  (3) AGENTS.md 思考原則 3 (後方互換の並走を残さない) により、移すなら同じ PR で旧経路を消す必要があり、
  小さな施策の範囲を超える。
- 対応内容: 変更しない。指摘は妥当な観測なので、この判断を対応マトリクスに残しておく。
