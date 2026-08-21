# 対応マトリクス: design-review Round 2

施策1 / 1-T / 2 / 横断 は APPROVE。施策2b と 2-T の Warning に対応する (Critical は 0 件)。

## [Warning] 施策2b: `{#if message}` 内の aria-live は動的読み上げを安定保証しない
- 判断: 対応する (Codex 推奨の option 2 = 局所化を採用)
- 根拠: live region は「空の状態で先に DOM に存在し、その後本文が更新される」ときに最も確実に通知される。
  要素と本文の同時挿入は SR/ブラウザ組合せで読み上げられないことがある。また共有 atom (FormError) の
  グローバル変更は間接利用 (FormField/Checkbox 経由) がアプリ全体に広がり、複数エラー同時表示・再描画・
  入力中更新の通知頻度が変わりうる → 影響確認が過大。
- 対応内容: **施策 2b (FormError atom の変更) を撤回**。代わりに施策2 (AutoRechargeCard) 内に
  **常時 DOM に存在する visually-hidden (`sr-only`) の polite live region** を 1 つ置く。テキストは
  「押下後に提示中の単一アクティブエラー (`thresholdError ?? maxError`)、無ければ空文字」。要素は常在し
  本文だけが更新されるため確実に通知される。可視エラーは FormField(FormError) が per-field で表示し、
  sr-only 領域は読み上げ専用 (可視の重複は作らない)。変更は F-3-01 に完全局所化し、共有 atom を触らない。
  testId `auto-recharge-range-error` はこの sr-only live region に付け替えて残す。

## [Warning] 施策2b: 「全フォーム底上げ・後退なし」は現調査範囲で断定できない
- 判断: 対応する (施策2b 撤回により論点自体が消滅)
- 対応内容: 共有 atom を変更しないため、間接利用箇所への影響検証は不要になった。

## [Warning] 施策2-T: live region テストが静的属性確認だけ
- 判断: 対応する
- 対応内容: 状態遷移を検査する。(1) エラー無しの時点で空の live region 要素が DOM に存在
  (`getByTestId("auto-recharge-range-error")` が存在し textContent が空) / (2) 押下後、**同一要素**に
  エラー文言が入る / (3) 訂正後、同一要素の文言が消える。要素同一性を保ったまま本文が更新されることを固定。

## [Warning] 施策2-T: aria-invalid 不在は `not.toHaveAttribute("aria-invalid")` を使う
- 判断: 対応する
- 根拠: Input atom は false 時に属性を省略する契約 (`aria-invalid={error || undefined}`)。`("aria-invalid","true")`
  だと `aria-invalid="false"` が残っても通ってしまう。
- 対応内容: 「付かない」契約は `expect(input).not.toHaveAttribute("aria-invalid")` (値指定なし) で固定。

## [Suggestion] 施策2-T: threshold 不正値は "-1" に確定 ("abc" 削除)
- 判断: 対応する
- 対応内容: `type="number"` への非数値 sanitize は DOM 実装依存のため、負数 "-1" のみに確定。

## [Suggestion] 施策1-T: `Notification::assertSentTo($user, ...)` は fresh 不要
- 判断: 対応する
- 対応内容: `$user->fresh()` をやめ `$user` のまま (nullable を持ち込まない)。

## [Suggestion] 施策1-T: Inertia アサーションで component 名も固定
- 判断: 対応する
- 対応内容: `$page->component('Auth/VerifyEmail')->where('flash.success', EMAIL_CHANGED_MESSAGE)` として
  「正しい props だが誤った画面」の後退も検出する。
