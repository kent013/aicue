# 概念設計: notifications-read-all-empty-state

bug-hunt run 20260715-213842 / finding F-4-01 (Low, H12)

## 背景・課題

`/notifications` (`notifications.index`) は未読が 0 件のときも「すべて既読にする」
(read-all) ボタンが常時活性のまま表示され、無意味な `notifications.read-all`
POST を発火できる。無害だが、既読化する対象が無い状態で「すべて既読にする」を
押せるのは操作として意味が無く、UX ノイズになっている。

## 改善アイデア

未読が 0 件のとき read-all ボタンを**非表示**にする。未読が 1 件以上あるときは
従来どおり表示する。

- AGENTS.md 禁止事項 #8「必須条件未充足を理由にボタンを disabled にする UI」に留意し、
  **disabled で無反応にするのではなく、意味の無い操作は非表示にする**のが自然
  (押しても意味が無い = そもそも押させない = 非表示。disabled で灰色固定して無反応に
  するのとは異なる。禁止事項 #8 は「押下時にエラー表示する」文脈であり、押す意味自体が
  無い操作は非表示が適切)。

## 期待効果

- 使命への貢献 (周辺 UX の摩擦低減): 現場作業者が迷わず使える UI (思考ゼロ) の一貫性。
  意味の無い操作要素を排し、通知センターの操作導線を明確化する (本質機能の強化ではなく
  周辺 UX ノイズ低減という位置づけ)。
- 具体的な改善見込み: 未読 0 件時の**通常操作経路からの**空振り read-all リクエストを排除。
  (別タブの古い画面や手動 POST では依然到達可能。UI 導線としての空振りを無くす。) UX ノイズ解消。

## 実装方針（概要）

未読件数の正しいソースを確定した上で条件描画する。

1. **サーバ (`NotificationController::index`)**: Inertia props に明示的な `unreadCount`
   を追加する。値は既存の `NotificationCenterService::unreadCountFor(User): int`
   (全 org 横断・自分宛のみ) を使う。
   - 理由: `HandleInertiaRequests` の shared prop `notifications.unreadCount` は、
     Index ページ固有 prop `notifications` (`NotificationItem[]`) と**同一キー衝突**し
     配列で上書きされるため、Index ページからは shared の unreadCount を読めない。
     ページ固有 prop として明示的に別キー `unreadCount` を渡すのが正。
   - グローバルな未読数を使う理由: ページャの現在ページのリストだけから未読有無を
     判定すると、2 ページ目以降に未読があるのに 1 ページ目が全既読のときボタンが
     消えてしまう。全体の未読数が read-all の対象そのものなので正しいソースとなる。

2. **フロント (`resources/js/pages/Notifications/Index.svelte`)**: Props に
   `unreadCount: number` を追加し、read-all ボタンを `{#if unreadCount > 0}` で条件描画。
   可視時の in-flight 連打ガード (markingAll) はそのまま維持する。
   JSDoc の「未読 0 でも disabled にしない」記述を「未読 0 では非表示 (禁止事項 #8 準拠で
   hide であって disable ではない)」に更新する。
   併せて JSDoc に「Index ページは shared `notifications.unreadCount` を参照しない
   (ページ固有 prop `notifications` 配列とキー衝突するため。専用 `unreadCount` prop を使う)」
   旨を短く明記し、将来の保守者が shared prop を誤参照しないようにする。

3. **整合の前提**: 一覧取得 (`paginateFor`) と件数取得 (`unreadCountFor`) は同一リクエスト内の
   2 クエリだが、厳密なトランザクション整合は要求しない (リクエスト時点のスナップショット差分は
   許容)。read-all ボタン表示の可否という UI 判断であり、境界ケースの一瞬のズレは無害。

## 制約・前提

- DTO/JsonResource 契約は変更しない (Inertia props への scalar 追加のみ。`notifications`/`meta`
  の shape は不変)。
- read-all の POST 挙動・in-flight ガードは変更しない。
- `unreadCountFor` は既存メソッドで追加クエリ 1 本 (count)。index の負荷影響は軽微。

## スコープ外

- shared prop `notifications` と Index ページ prop `notifications` のキー衝突そのものの
  是正 (AppLayout の通知ベルが Index ページで unreadCount を読めない件) は本 finding の
  対象外。今回はページ固有 `unreadCount` prop 追加で read-all ボタン条件描画のみ解決する。
- read-all ボタンの文言・スタイル変更はしない。
- 1 件既読 (`notifications.read`) / open 遷移の挙動は変更しない。
