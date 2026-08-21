# 対応マトリクス: design-review Round 3

施策1 / 1-T / 2 / 横断 は APPROVE 維持。施策2-T の 2 Warning に対応 (Critical は 0 件)。

## [Warning] 施策2-T: `aria-live="polite"` 自体の回帰テストが抜けている
- 判断: 対応する
- 根拠: 状態遷移だけだと `aria-live` 属性が消えても素通りし、動的読み上げの中核契約を固定できない。
- 対応内容: 状態遷移テストの初期段で同一参照 `liveRegion` に対し `toHaveClass("sr-only")` /
  `toHaveAttribute("aria-live","polite")` / `toBeEmptyDOMElement()` を assert。押下後 `toHaveTextContent(...)`、
  訂正後 `toBeEmptyDOMElement()` を同一参照で検査 (将来 `{#if}` で要素差し替えになっても検出)。

## [Warning] 施策2-T: live region の threshold 側経路も固定
- 判断: 対応する
- 根拠: max だけだと `{maxError ?? ""}` のような threshold を無視する誤実装で通ってしまう。
- 対応内容: threshold 不正テストで同一 live region が `toHaveTextContent(/リチャージ開始残高は 0 以上の整数/)`
  を持つ assert を追加。

## [Suggestion] 施策2: 「確実に通知」「重複読み上げは起きない」の表現が強すぎる
- 判断: 対応する
- 対応内容: リスク節を「自動テストは DOM 構造と状態遷移を保証し、実読み上げはブラウザ/支援技術依存」
  「同一画面への可視の重複は作らない」という保証範囲を明確化した表現に修正。設計コメント文も同趣旨に緩和。

## [Suggestion] 施策2-T: 非空→非空 (範囲外→大小違反) の切替も固定するとより直接
- 判断: 見送る (承認必須条件ではないと Codex 明記。threshold/max 双方の live-region 経路 + 有効値クリアで十分)
- 根拠: 既存テスト (無効理由の追随) が可視側で文言追随を担保しており、live region 側は threshold/max の
  2 経路 + クリアで十分。over-test を避ける。
