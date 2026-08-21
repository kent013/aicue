# 詳細設計レビュー Round 4 — 施策2-T の最終指摘への対応

Round 3 で施策1/1-T/2/横断は APPROVE をいただきました。施策2-T の 2 Warning を反映しました。

## 施策2-T [Warning] `aria-live="polite"` 自体の回帰テストが抜けている
→ 対応。状態遷移テストの初期段で、同一参照 `liveRegion = getByTestId("auto-recharge-range-error")` に対し:
- 押下前: `toHaveClass("sr-only")` / `toHaveAttribute("aria-live","polite")` / `toBeEmptyDOMElement()`
- max "0" 押下後: `toHaveTextContent(/リチャージ後の残高は 1 〜 1000 の整数/)`
- max "50" 訂正後: `toBeEmptyDOMElement()`
同一参照を使い続けるため、将来 `{#if}` に戻って要素差し替えになった場合も検出できます。

## 施策2-T [Warning] live region の threshold 側経路も固定
→ 対応。threshold 不正テスト (ケース(1)) で同じ live region が
`toHaveTextContent(/リチャージ開始残高は 0 以上の整数/)` を持つ assert を追加。`{maxError ?? ""}` のような
threshold を無視する誤実装を落とせます。

## 施策2 [Suggestion] 「確実に通知」等の表現が強すぎる
→ 対応。リスク節を「自動テストは DOM 構造と状態遷移 (属性・sr-only・本文の出入り) を保証し、実際の
読み上げ挙動はブラウザ・支援技術に依存する」「同一画面への**可視**の重複は作らない」と保証範囲を明確化。

## 施策2-T [Suggestion] 非空→非空の切替も固定
→ 見送り (Codex が承認必須条件でないと明記)。既存の可視側「無効理由の追随」テストが文言追随を担保し、
live region 側は threshold/max の 2 経路 + 有効値クリアで十分と判断 (over-test 回避)。

---

更新後の施策2-T セクション (全文) を添付します。他セクションは Round 3 承認版から不変です。

## 施策2-T セクション (更新後・全文)

## 施策2-T: JS コンポーネントテスト更新

対象: `tests/js/components/features/billing/AutoRechargeCard.test.ts`。既存 `auto-recharge-range-error`
testId 参照 (6 箇所, L123/135/144/148/158/164) を「利用者視点」の assert に更新 (カバレッジは削除せず移設)。
入力取得は `getByRole("spinbutton", { name: ... })` (label と input の配線も同時に回帰検査)。
props 既定は `autoRechargeProps` (thresholdCount=5, minCount=1, maxCountLimit=1000)。

3 分岐を**別個の値で**区別して固定する:
- [ ] **(2) max 解析/範囲エラー → max のみ invalid (F-3-01 本体)**: `hasPaymentMethod:true` で render →
      max spinbutton に "0" (< minCount 1 → parsedMax null) → enable 押下 →
      max spinbutton が `toHaveAttribute("aria-invalid","true")` かつ
      `toHaveAccessibleDescription(/リチャージ後の残高は 1 〜 1000 の整数/)` (describedby 関連付けまで検査)。
      threshold spinbutton は `not.toHaveAttribute("aria-invalid")` (値指定なし。Input は false 時に属性省略)。
- [ ] **(1) threshold 解析/範囲エラー → threshold のみ invalid**: threshold spinbutton に "-1" (負数。
      非数値文字列 "abc" は `type=number` の sanitize が DOM 依存のため使わない) → 押下 → threshold spinbutton
      が `aria-invalid=true` + `toHaveAccessibleDescription(/リチャージ開始残高は 0 以上の整数/)`、
      max spinbutton は **`not.toHaveAttribute("aria-invalid")`** (値指定なし。Input は false 時に属性省略)。
- [ ] **(3) 個別有効だが max<=threshold → max のみ invalid**: threshold spinbutton="5"(既定)・
      max spinbutton="3" (3 は minCount..limit で個別有効かつ 3<=5) → 押下 → max spinbutton が
      `aria-invalid=true` + `toHaveAccessibleDescription(/開始残高より大きい値/)`、threshold は
      `not.toHaveAttribute("aria-invalid")`。(この具体値で `parsedMax===null` 分岐を踏むだけの false pass を防ぐ。)
- [ ] **押下前は aria-invalid が付かない (禁止事項 #8)**: max spinbutton に "0" 入力しても押下前は
      `expect(maxInput).not.toHaveAttribute("aria-invalid")` (既存 L128 の意図を aria-invalid で再表現)。
- [ ] **有効値へ直すと aria-invalid が消える (既存 F-3-05 の意図)**: max "0" → 押下 (invalid) → max "50" →
      max spinbutton が `not.toHaveAttribute("aria-invalid")` (既存 L138 の移設)。
- [ ] **sr-only live region の属性と状態遷移** (可視 `<p>` 撤去の後退防止): 同一 live region 要素
      (`const liveRegion = screen.getByTestId("auto-recharge-range-error")`) について、
      **属性の回帰** (`aria-live` が消えても素通りしないため):
      (a) 押下前に `expect(liveRegion).toHaveClass("sr-only")` / `toHaveAttribute("aria-live","polite")` /
      `toBeEmptyDOMElement()` →
      **本文の状態遷移** (同一参照を使い続け、将来 `{#if}` に戻って要素差し替えになった場合も検出):
      (b) max "0" 入力 + 押下後、`expect(liveRegion).toHaveTextContent(/リチャージ後の残高は 1 〜 1000 の整数/)` →
      (c) max "50" へ訂正後、`expect(liveRegion).toBeEmptyDOMElement()`。
- [ ] **live region の threshold 側経路も固定** (`{maxError ?? ""}` のような誤実装を落とすため):
      threshold 不正テスト (上記(1)) で、同じ live region が
      `expect(liveRegion).toHaveTextContent(/リチャージ開始残高は 0 以上の整数/)` を持つことを assert する。
- 既存の他 assert (`auto-recharge-consent` を開かない 等) は据え置き。

---

## 横断: テストファースト順序と検証コマンド

## 施策2 リスク節 (更新後)

- 可視統合 `<p>` 撤去で `auto-recharge-range-error` testId を参照する箇所が壊れる → testId は sr-only live
  region に付け替えて残す (施策2-T で状態遷移テストへ更新)。他 (Pest/Browser) からの参照が無いことは確認済み。
- FormField 内 FormError には testId が無い → 可視エラーのテストは `getByRole("spinbutton", {name})` の
  `aria-invalid` + `toHaveAccessibleDescription` で assert (testId 非依存。FormField/FormError は不変)。
- 保証範囲の明確化: live region は一般的な推奨構造 (常在要素の本文更新) にする。自動テストは DOM 構造と
  状態遷移 (属性・sr-only・本文の出入り) を保証し、実際の読み上げ挙動はブラウザ・支援技術に依存する
  (「確実に読み上げる」ことまでは保証しない)。sr-only live region と可視 FormError は別タイミング
  (focus 時の describedby / 変化時の live) で機能することを意図しており、同一画面への**可視**の重複は作らない。

---
