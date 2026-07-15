# 対応マトリクス: design-review Round 1

## [Critical] reading 中のボタン表示条件の責務分離不足 + focus timing
- 判断: 対応
- 対応内容: 表示条件を明示 derived `showReadButton = unread || reading` に分離(暗黙依存排除)。
  focus 移動は `onFinish` で `await tick()` により DOM 確定後に `contentButton?.focus()`。
  (Codex の `unread && !opening` 案は in-flight 中に消えて aria-busy 不達になるため不採用。
  「未読 or in-flight は残す」が正しい要件。名前付き derived で意図を明確化。)

## [Critical] in-flight 二重送信防止テストの欠落
- 判断: 対応
- 対応内容: onStart のみ発火状態で read ボタン 2 回クリック → router.post 1 回のみを検証する
  テストを追加。

## [Warning] onError 後の状態復元が暗黙的
- 判断: 対応
- 対応内容: onError に `optimisticallyRead = false` を defensive reset として明示。

## [Warning] aria-busy の対象が伝わりづらい
- 判断: 対応
- 対応内容: aria-label を `reading ? "既読処理中" : "既読にする"` に切替。

## [Warning] フォーカス移動の回帰テスト欠落
- 判断: 対応
- 対応内容: success+finish 後 `document.activeElement === getByTestId('notification-item')` を検証。

## [Warning] Inertia オプション検証の粒度不足
- 判断: 対応
- 対応内容: `preserveScroll:true` に加え onStart/onSuccess/onError/onFinish を `expect.any(Function)` で固定。

## [Suggestion] イベント競合の明示防止
- 判断: 対応
- 対応内容: `markRead(event)` 冒頭で `event.stopPropagation()`、markup は `onclick={(e) => markRead(e)}`。

## [Suggestion] open/read の排他テスト(逆方向)
- 判断: 対応
- 対応内容: open クリックで read URL が呼ばれないテストを追加。
