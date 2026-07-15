# 概念設計: notifications-read-ui

## 背景・課題

`notifications.read`(POST `/notifications/{notification}/read`)は backend 実装済み
(`NotificationController::read()` が `back()` で完結し 1 件だけ既読化する)だが、
`resources/js` からの呼び出しが存在しない **dead surface** になっている。

現状の通知 UI の既読導線は 2 つだけ:

1. 行クリック = `open` (POST `/notifications/{id}/open`) — 既読化 **+ 遷移**
   (`NotificationListItem.svelte` の行全体が `<button>`)
2. 「すべて既読にする」= `read-all` (POST `/notifications/read-all`) — 一括既読

つまり「**開かずに 1 件だけ既読にする**」導線がユーザーに提供されていない。
通知を開くと必ず別画面へ遷移するため、「確認済みだが今は開きたくない」通知を
その場で片付ける手段がなく、未読バッジ(`NotificationBell`)を減らすには
全部開くか一括既読しかない。粒度の中間 (個別既読) が欠けている。

## 改善アイデア

未読通知の各行に **個別「既読」アクション**(小さなアイコンボタン)を追加する。
押下すると POST `notifications.read` を呼び、**遷移せずに**その 1 件だけを既読化し、
一覧の state に反映する(行が既読表示に変わり、未読ドット・ハイライトが消える)。

- 既存の `open`(行クリック=既読+遷移)は**維持**
- 既存の `read-all`(一括既読)は**維持**
- 個別既読ボタンは**未読行にのみ表示**(既読行には出さない = ノイズ削減)

## 期待効果

- 使命への貢献: 現場作業者が「通知を確認したが今は開かない」を最小操作で処理でき、
  未読ノイズを溜めずに本来の作業(マニュアル作成・撮影)に集中できる。
  通知センターの操作粒度(全既読 / 個別開封)の間の欠落を埋め、UX の一貫性を高める。
- backend 実装済み route の dead surface を解消し、UI と backend の対応を回復する。
- 成功時に同期される UI 範囲: 行表示(未読ドット/ハイライト)は楽観 state で即時、
  未読件数(`NotificationBell`)・一括既読状態はサーバ `back()` 再読込の shared props で追随。

## 対象 UI と主導線

今回の対象は**通知センター一覧**(`/notifications` = `Notifications/Index`)。ヘッダの
`NotificationBell` は未読件数バッジ + ドロップダウン導線であり、詳細な行操作は一覧側に集約
されている。個別既読の操作粒度は一覧で完結させるのが自然(Bell への個別既読導線追加は
今回スコープ外 = 将来対応。まず一覧の dead surface 解消を優先)。

## 実装方針（概要）

- `resources/js/components/features/notifications/NotificationListItem.svelte`:
  - 現状「行全体が 1 つの `<button>`(open)」の構造を、
    「open ボタン + 個別既読ボタン」の 2 ボタン構成へ変更する。
    **ネストした button(不正 HTML)を避ける**ため、外側を `<div>` ラッパにし、
    open ボタンと既読ボタンを兄弟要素にする。
  - **操作モデル(a11y 明文化)**:
    - open = 主操作。メイン content ボタンが行の hit area を保持(`flex-1`、focus ring、
      `hover:bg-neutral`、既存 testid `notification-item` / `data-unread` / onclick=open を保持)。
    - read = 副操作。行右上に**絶対配置**したアイコンボタン。独自の focus ring と
      `aria-label="既読にする"`。Tab 順は content ボタンの次(SR 読み上げ順も content→read)。
    - **レイアウトシフト回避**: メイン content ボタンに右パディングを**常時確保**し、
      既読ボタンはその予約領域に絶対配置する。未読→既読でボタンが消えても本文 text は
      reflow しない(予約パディングは一定)。
  - 未読時のみ既読アイコンボタン(`@lucide/svelte` の `Check`)を表示(禁止事項#8: disabled 不使用)。
    `data-testid="notification-read-button"`。
  - 既読ハンドラ: `router.post('/notifications/{id}/read', {}, { preserveScroll: true })`。
    遷移しない(read route は `back()` 完結)。文字列 route は既存 open と同一記法に揃える。
    連打ガードは in-flight 送信ガード(disabled 属性は使わない)。
  - **in-flight 中の可視性とフォーカス**: 既読ボタンの描画条件は `unread || reading`
    (楽観既読で即座に消さない)。in-flight 中は `aria-busy={reading}` を提示。
    成功確定(楽観既読で消える)時は、同一行の open(content)ボタンへ**フォーカスを移す**
    (`bind:this` した content ボタンに `focus()`。フォーカスロスト防止)。
  - **失敗フィードバック**: `onError` では既存の toast 基盤(`@/lib/stores/toast` の
    `addToast('error', '既読にできませんでした。再試行してください。')`)で明示通知する
    (error toast は自動消去されず、ToastContainer が `aria-live` で読み上げる)。
    併せて楽観既読を未読へ復帰しボタンを残す(再試行可能)。
  - **一覧 state 反映と source of truth**: source of truth は**サーバ props**。
    サーバ `back()` 再読込が最終的に prop(`read_at`)と shared props(`NotificationBell` の
    unreadCount)を確定させる。子の楽観 state (`optimisticallyRead`) は**単調(未読→既読方向のみ)**
    のアクセラレータで、`onError` で未読へ復帰する。unread 判定は
    `notification.read_at === null && !optimisticallyRead` とし、**prop が常に優先**する
    (read-all 等が prop.read_at を設定すれば楽観 state に関わらず既読表示となり乖離しない)。
- `resources/js/pages/Notifications/Index.svelte`: 変更不要
  (行の描画・既読 state は ListItem に閉じ、件数は shared props がサーバ確定で追随)。
- 純フロント。backend / route / DTO / 型定義の変更なし。

## 制約・前提

- 禁止事項#8: 必須条件未充足でボタンを disabled にしない — 既読ボタンは
  未読行にのみ**表示**する(表示/非表示で制御し disabled は使わない)。
- DESIGN.md: color/spacing は DS token 経由(既存 ListItem のクラス流儀を踏襲)。
  アイコンは Lucide のみ(`Check`)。
- Atomic Design: 変更は features/notifications 層に閉じる(既存位置)。
- 既存 vitest(`NotificationListItem.test.ts`)の open/未読表示アサートを壊さない
  (testid `notification-item` / `unread-dot` / `data-unread` を維持)。

## スコープ外

- backend / route / DTO / NotificationType の変更
- `open` / `read-all` の挙動変更
- 既読 → 未読へ戻す(unread 化)導線
- `NotificationBell`(ヘッダのベル)への個別既読導線追加
