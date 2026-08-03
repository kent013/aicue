# 対応マトリクス: design-review Round 1

判定: B-1 APPROVE / C REQUEST_CHANGES / A-1・A-2 APPROVE / B-2 REQUEST_CHANGES
→ 全体 CHANGES_REQUESTED。以下すべてに対応または根拠つきで反論する。

## [Critical] C-1: 取得中でも `qr-unavailable` / `setup-key-unavailable` が先に表示される

- 判断: **対応する**
- 根拠: 決定的に正しい。`enableTwoFactor` の onSuccess で `confirming = true` にした直後は
  `qrSvg` / `setupKey` とも null で、fetch 解決前に「表示できませんでした」が出てしまう
  (失敗前に失敗文言が出る = 明確な UX バグ)。
- 対応内容: `loadingEnrollmentAssets === true` の間は取得中プレースホルダのみを描画し、
  警告 Alert は**取得完了後に欠損が確定した場合のみ**表示する分岐に変更。
  取得中コンテナに `aria-busy="true"` を付ける (Suggestion C-3 も同時採用)。

## [Warning] C-2: 再試行連打時のレスポンス逆転で古い結果が上書きされうる

- 判断: **対応する (ただし requestId ではなく in-flight ガードで)**
- 根拠: 競合の指摘は妥当。ただし本画面で並行実行が起きうる経路は
  「再試行ボタン連打」と「取得中に有効化を再実行」の 2 つだけで、
  どちらも**同時に 1 本しか走らせない**ことで解消できる。requestId による
  「後着優先」の一般化は今必要ない (思考原則 2)。
- 対応内容: `loadEnrollmentAssets()` の冒頭で `if (loadingEnrollmentAssets) return;` の
  早期リターンを置く (アーリーリターン推奨にも合致)。再試行ボタンは `loading` 表示で
  進行中を示す (必須条件による disabled ではないため禁止事項 8 に抵触しない)。

## [Warning] B-1-1: `querySelector` の存在判定は「可視」を担保しない

- 判断: **対応する**
- 対応内容: 判定式を実可視 (`getClientRects().length > 0` かつ
  `getComputedStyle` の `visibility !== "hidden"` / `display !== "none"`) に変更する。

## [Suggestion] B-1-2: 3 秒固定は flaky になりうる (3500ms へ緩和を検討)

- 判断: **別の形で対応する (待機順序を変えて余裕を作る)**
- 根拠: 上限は auto-dismiss の 4 秒で頭打ちなので、閾値を 3500ms に上げても
  余裕は 500ms しか増えない。真の対策は**計測開始を早めること**。
- 対応内容: 待機順序を「着地 → toast」から**「toast → (失敗時のみ) 着地の確認」**へ変更する。
  toast は着地ページの mount と同時に enqueue されるため、先に toast を待てば
  auto-dismiss までの猶予を最大化できる。着地確認は fail の**分類**にのみ使う
  (制御条件 (ii) の判定材料)。閾値は 3000ms を維持する。

## [Warning] A-2-1: 「ToastContainer はアプリで 1 箇所のみ mount」との整合が未検証

- 判断: **事実確認のうえ対応 (整合する)**
- 根拠: 実ファイルを確認した。`ToastContainer` を描画しているのは
  `AppLayout.svelte:190` と `AuthLayout.svelte:28` のみで、root
  (`resources/js/app.ts` / `resources/views/app.blade.php`) には無い。
  layout を 2 つ使うページも存在しない (`AuthLayout` 利用 8 ページのいずれも
  `AppLayout` / `GuestLayout` を import していない)。したがって GuestLayout に足しても
  同時 mount は発生しない。
- 対応内容: この確認結果を詳細設計のリスク節に事実として記載する。

## [Suggestion] A-1-1: Feature テストで文言も 1 件固定する

- 判断: **対応する**
- 対応内容: `AccountDeletionTest` の 1 件のみ
  `assertSessionHas('success', 'アカウントを削除しました')` と文言まで固定する
  (他 2 件はキー存在のみ = 文言変更の巻き添え更新を増やさない)。

## [Warning] B-2-1: 「全ページ遷移で unmount される」が未検証で根因仮説として弱い

- 判断: **一部反論 + 一部対応**
- 反論 (事実): 「全ページ遷移で unmount される」は Claude 側が一次ソースで確認済み。
  `node_modules/@inertiajs/svelte/dist/components/App.svelte` の `swapComponent` が
  非 preserveState visit で `key = Date.now()` を更新し、`Render.svelte` が
  `{#key children?.length === 0 ? key : null}` でページ配下を丸ごと作り直す。
  本アプリは layout をページ component 内で描画するため、`ToastContainer` も毎回作り直される。
- 対応 (射程): ただし Codex の言うとおり「unmount が F-1-02 の**根因**である」は未確定であり、
  そこは概念設計の判定表 (制御条件つき fail のときだけ H-a を支持) がすでに担保している。
  B-2 の見出しと本文に「根因確定ではなく、制御条件つき fail のときのみ適用」を再掲する。

## [Warning] B-2-2: cleanup 境界が未認証 layout 初期化に偏る / 明文化して固定せよ

- 判断: **対応する**
- 対応内容: 「消去境界の正本 = 未認証 layout の初期化」を
  `DESIGN.md` §Toast に明記 (既に A-2 で計画済み) し、
  **JS テストで固定**することを B-2 の受入条件にも明示する
  (`GuestLayout.test.ts` / `AuthLayout.test.ts` の「着地前の toast は描画されない」ケース)。

## [Suggestion] C-3 / 補足 (PHPStan / Inertia / DESIGN / Atomic)

- C-3 (`aria-busy`) は C-1 の対応に含めて採用。
- 補足の各観点は「逸脱なし」の確認であり対応不要。
- `{@html qrSvg}` の信頼境界は既存実装のまま (サーバ生成 SVG)。本設計で新たな
  ユーザー入力を混ぜないため信頼境界は変わらない旨を明記する。
