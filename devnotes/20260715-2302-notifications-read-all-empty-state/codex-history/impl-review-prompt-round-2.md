Round 1 の指摘への対応です。全体判定を再確認してください。

## [Critical] Index.svelte JSDoc が #8 準拠を断定しすぎ → 対応
JSDoc を修正 (要旨): 「未読 0 件で非表示にするのは bug-hunt F-4-01 のプロダクト判断。禁止事項 #8
『必須条件未充足を理由に disabled にしない』とも整合する (disabled で無反応にせず非表示) が、
#8 が常時非表示を要求するわけではない点に注意」。断定を避け根拠 (F-4-01) を明記。

## [Warning] Svelte 実行時値防御 (Math.max/Number) → 反論 (変更なし)
分岐は `{#if unreadCount > 0}` のみ。0・null・負値・NaN はいずれも `> 0` が false で安全に非表示へ倒れる
(表示すべき値=正の数のときだけ true)。Math.max/Number 変換は分岐結果を変えず冗長。詳細設計レビュー
Round 1/2 でも `> 0` 固定を「0件・異常な負値の双方で安全」と承認済み。JSDoc に
「0・null・負値・NaN は `> 0` false で安全に非表示」と意図を明記した。

## [Warning] vitest ケース数 6 vs 5 → 反論 (記載は正確)
NotificationsIndex.test.ts の it() は 5 件 (空状態 / 未読あり押下 / 未読0非表示 / 未読あり表示 / 一覧表示)。
「通知行押下系」は本ファイルに無く別ファイル (NotificationListItem.test.ts) が担当。5 passed が実測どおり正しい。

## [Warning] Controller コメントが長い → 対応
コメントを短縮:
```php
// 未読数をページ表示制御 (read-all ボタン表示可否) 用に渡す。専用 scalar なのは
// shared prop notifications.unreadCount がページ prop `notifications` (配列) と
// キー衝突するため (詳細は Index.svelte JSDoc)。
'unreadCount' => $this->notifications->unreadCountFor($user),
```

## [Suggestion] 全 org 横断 unreadCount テスト → 対応 (追加)
`index: unreadCount は全 org 横断で自分宛未読を数える` を追加。別組織由来の自分宛通知も
カウントされることを検証 (owner を第二組織に attach し TicketBalanceLow 通知 → unreadCount=2)。

## [Suggestion] ViewModel/Resource 化・0件でも一覧表示の組合せ → 見送り
payload 3 要素で過剰設計を避ける (禁止事項6)。一覧表示 (notifications 配列基準) と read-all
(unreadCount 基準) の独立は既存 + 新規テストで担保済み。

## 再テスト結果
- Feature: NotificationCenterTest 18 passed (110 assertions)。
- vitest: NotificationsIndex.test.ts 5 passed。
- composer phpstan: No errors。pint: passed。pnpm typecheck: green。
- 全 composer test 1793 passed / 2 skipped / 0 failed、全 vitest 780 passed は Round 1 実装時点で確認済み
  (今回の差分はコメント修正 + テスト 1 本追加のみでロジック不変)。

以上で Critical/Warning は解消 or 根拠反論済みです。全体判定を再判定してください。
