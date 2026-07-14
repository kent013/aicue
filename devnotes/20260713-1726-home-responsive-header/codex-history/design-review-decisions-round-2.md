# 対応マトリクス: design-review Round 2

Round 2 全体判定: **CHANGES_REQUESTED**（施策1 REQUEST_CHANGES / 施策2 REQUEST_CHANGES /
施策3 APPROVE）。Critical 1 / Warning 1。両方「対応する」。

## [Critical] 施策1: anchor 分岐に never 補完が無いと分割代入で TS エラー
- 判断: 対応する
- 根拠: `ButtonProps` union の button 分岐にのみ ariaExpanded/ariaControls/element を足すと、
  `$props()` 分割代入が anchor メンバーに存在しないプロパティ参照になり TS エラーになりうる。
- 対応内容: anchor モード union member に `ariaExpanded?: never; ariaControls?: never; element?: never;`
  を追加。分割代入可能 + anchor での誤用を型で禁止の両立。設計書に重要注記を追加。

## [Warning] 施策2: event.defaultPrevented ガード未反映
- 判断: 対応する
- 根拠: 他ハンドラが Escape を処理済みの場合の二重処理を防ぐ。
- 対応内容: `handleKeydown` 冒頭を
  `if (event.defaultPrevented || event.key !== "Escape" || !menuOpen) return;` に変更。

## 施策3: APPROVE
- 対応不要（focus 復帰・nav 未指定・within(panel) 使い分けを肯定）。
