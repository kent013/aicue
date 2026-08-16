# 対応マトリクス: design-review Round 1

判定は **CHANGES_REQUESTED**（Critical 1 / Warning 8 / Suggestion 4）。
Critical と Warning はすべて処理した。1 件は事実確認の結果**一部反論**（ただし修正案自体は採用）。

## [Critical] 施策 4: `$effect` での controller 生成は多重生成リスクがある。`onMount` にせよ

- 判断: **対応する**
- 根拠: 指摘のとおり「effect 本体で `$state` を同期 read しなければ再実行されない」は
  **実装者の注意力に依存する不変条件**であり、設計としては脆い。
  D&D の controller は「マウント時に 1 度だけ作る browser-only の資源」なので、
  意図がそのまま型と API に出る `onMount` が正しい。
- 対応内容: 施策 4・5 の controller 生成を `onMount(() => { …; return () => { …destroy() }; })` に変更。
  `$effect` は使わない。あわせて「なぜ `$effect` ではないのか」を設計にコメントとして残した。

## [Warning] 施策 1: `const [moved] = next.splice(from, 1)` は `T | undefined` になり得る

- 判断: **一部反論しつつ、修正案は採用する**
- 根拠（反論部分）: 本リポジトリの `tsconfig.json` は `@tsconfig/svelte/tsconfig.json` を
  extends し、`strict: true` は有効だが **`noUncheckedIndexedAccess` は有効化されていない**
  （base・自前 config のどちらにも記載が無いことを実読で確認）。したがって現状の
  `pnpm typecheck` は指摘の形でも**落ちない**。「落ちる」という前提の説明は本リポジトリでは
  成立しない。
- 根拠（採用部分）: とはいえ、`from` の範囲検査と splice の戻り値の関係は**型では繋がっていない**。
  明示的に絞る形は (a) 将来 `noUncheckedIndexedAccess` を有効化しても壊れず、
  (b) 純関数の fail-safe 方針（範囲外は「動かさない」に倒す）をコードの形で示せる。ゼロコスト。
- 対応内容: `const moved = next[from]; if (moved === undefined) return next;` を先に置き、
  そのあと `splice` する形に変更。設計に「現行 config では型エラーにならないが、
  fail-safe の意図を型の外で担保するため明示的に絞る」と理由を明記した。

## [Warning] 施策 2: `destroy()` が `onCancel` を呼ぶため unmount 時に副作用が出る

- 判断: **対応する**
- 根拠: 妥当。unmount は「利用者が取り消した」わけではないので、同じ callback へ合流させると
  呼び出し側が両者を区別できない。将来 `onCancel` に告知や通信を足した瞬間にバグになる。
- 対応内容: `finish(commit: boolean, notify: boolean)` の 2 引数にし、
  `destroy()` は `finish(false, false)`（資源解放と `onState` のリセットのみ、`onCancel` を呼ばない）に変更。
  callback 契約に「`onCancel` は利用者由来の取消だけ。破棄では呼ばれない」と明記した。

## [Warning] 施策 2: 自動スクロール中に挿入位置が更新されず、古い位置で確定しうる

- 判断: **対応する**
- 根拠: 実害のある指摘。指を止めたまま端でスクロールさせると、行は動くのにポインタ座標は
  動かないため `pointermove` が来ない。スクロールした距離のぶんだけ挿入位置がずれた状態で
  drop できてしまう（iOS Safari で最も起きやすい）。
- 対応内容: 最後のポインタ Y (`lastClientY`) を保持し、`tickAutoScroll()` の各フレームで
  `insertionIndexFromRects(bounds(), lastClientY)` を再計算して `onState` も更新するよう変更。
  テスト計画に「自動スクロールの 1 フレームで挿入位置が更新される」ケースを追加した。

## [Warning] 施策 4: 急所 D&D の `onCommit` 後始末が本文コードに無い

- 判断: **対応する**
- 根拠: 設計書は実装の指示書なので、「実装では末尾でも同じ 2 行」という注釈で済ませたのは
  設計の欠落である。
- 対応内容: `onCommit` / `onCancel` の双方から呼ばれる `clearPointDragScope()` を定義し、
  `onCommit` は `try { … } finally { clearPointDragScope(); }` で必ず通す完成コードへ差し替えた。

## [Warning] 施策 4: `movePointTo` が `steps[stepIndex]` へ 2 回アクセスしている

- 判断: **対応する**
- 根拠: `?.` で undefined を弾いた事実は、その後の `steps[stepIndex].points` へは伝播しない。
  型の問題である以前に、間に `runSettled` の非同期な遅延実行が挟まりうる
  （IME 変換中はキューに積まれ、実行時には配列が変わっている可能性がある）ので、
  **実行時の再取得**が必要という意味でも指摘が正しい。
- 対応内容: `const step = steps[stepIndex]; if (step === undefined) return;` としたうえ、
  さらに `commitStructural` の中（= 実際に変異する時点）で**もう一度**取り直して範囲検査する形にした。
  これは Codex の指摘より一段強い対応である（IME キュー経由の遅延実行を考慮）。

## [Warning] 施策 5: PATCH の成否を待たずに「移動しました」と告知している

- 判断: **対応する**
- 根拠: 完全に正しい。失敗しても成功を読み上げるのは、スクリーンリーダ利用者にだけ
  嘘をつくことになる（視覚利用者は `role="alert"` のエラーを見る）。
- 対応内容: 既存 `run()` の戻り値を `Promise<void>` → `Promise<boolean>`（成功なら true）に変更し、
  `move()` もそれを返す。`reorderTo()` は `await` して**成功時のみ**告知する。
  失敗時は既存の `take-strip-error`（`role="alert"`）が担う（告知を二重に出さない）。
  既存の呼び出し側（`adopt` / `remove` / `downloadAndAck` / `confirmDelete`）は戻り値を
  無視するだけなので無変更で動く（`adoptFromPreview` の `error === null` 判定も現行のまま）。

## [Warning] 施策 5: 端での no-op PATCH 廃止は既存挙動変更なので期待値を明示せよ

- 判断: **対応する**
- 対応内容: 「端操作の期待 = **通信なし / busy なし / 再取得 (`onChanged`) なし / aria-live 告知あり**」
  を設計に表で固定し、同じ 4 点をテスト計画の assert に落とした。

## [Warning] 施策 6: テストで `setPointerCapture` を `undefined` にするのは型的に通りにくい

- 判断: **対応する**
- 根拠: `Element.prototype.setPointerCapture` の型は `(pointerId: number) => void` なので
  `undefined` の代入は型エラーになる。指摘のとおり。
- 対応内容: テスト側に型付き helper
  `withoutPointerCapture(run: () => void | Promise<void>): Promise<void>` を用意し、
  `Object.defineProperty(Element.prototype, "setPointerCapture", { value: undefined, configurable: true })`
  で外し、`finally` で元に戻す形へ変更した（`delete` も生の代入も使わない）。

## [Suggestion] 施策 2: drag 後に click が発火する経路を将来塞げるようにせよ

- 判断: **対応する**（型で塞ぐ）
- 対応内容: `DragHandleProps` に **`onclick` を定義しない**ことを明文化した。
  props に無いので呼び出し側は click ハンドラを付けられず、「ドラッグ後の誤 click」という
  経路自体が型で存在しない。設計に理由をコメントとして残した。

## [Suggestion] 施策 1: `toFinalIndex` の入力契約を書け

- 判断: **対応する**（契約の明記）
- 対応内容: 「`insertion` は `0..n`、`from` は `0..n-1` の正規化済み入力を前提とする。
  範囲外は下流の `moveItem` が clamp する（二重に clamp して意味を分散させない）」と doc に明記。

## [Suggestion] 施策 7: iOS 実機確認ファイルの存在を自動テストで強制するか

- 判断: **見送る**（強制しない。理由を明記する）
- 根拠: ファイルの存在は**実機で確認した事実の証明にならない**。存在チェックを緑にすると
  「機械が確認した」という誤った安心を作る（`docs/supported-browsers.md` が繰り返し戒めている
  「WebKit レーンの green を iOS Safari 対応の実証と言い換えない」と同型の誤り）。
- 対応内容: 設計に「自動テストでは強制せず、**人間のレビューで見る運用**である」と明記した。

## [Suggestion] 施策 3: ArrowUp/ArrowDown のコンポーネントテストは必須

- 判断: **対応済み**（既に施策 4・5 のテスト計画に含まれている。変更なし）
