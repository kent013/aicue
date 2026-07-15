# 対応マトリクス: conceptual-review Round 1

## [Critical] 状態責務の二重化(子の楽観 state と親 props 再同期)
- 判断: 一部対応 + 反論
- 根拠: 既存の `open` / `read-all` はいずれも「サーバ POST → `back()` 再読込 → props 再確定」
  でのみ state を更新しており、親 Index に可変 item state を持たせていない。ここで
  「親を source of truth に」= Index が notifications の可変コピー + コールバックを持つ設計は
  既存流儀から逸脱し、配線が増える(オーバーエンジニアリング禁止)。
  楽観 state は **単調(未読→既読方向のみ)・onError で復帰**に限定するため、read-all 等が
  props を更新した際も「prop.read_at !== null なら常に既読」で prop が優先し、乖離しない。
- 対応内容: 概念設計に「source of truth はサーバ props。子の楽観 state は単調な既読方向の
  アクセラレータで onError 復帰、prop が最終確定」と明記。unread 判定式を
  `notification.read_at === null && !optimisticallyRead` と定義(prop が常に優先)。

## [Critical] DOM 再設計による既存「行クリックで開く」主導線・a11y の後退
- 判断: 対応
- 根拠: 妥当な懸念。操作モデルとアクセシビリティを明文化する。
- 対応内容: open=主操作(メイン content ボタンが行の hit area を保持、flex-1、focus ring、
  testid `notification-item`/`data-unread`/onclick open を維持)、read=副操作(右上に絶対配置の
  アイコンボタン、独自 aria-label / focus ring、Tab 順は content ボタンの次)。
  レイアウトシフト回避のためメインボタンに一定の右パディングを常時確保し、既読ボタンは
  その予約領域に絶対配置(表示/非表示で text が reflow しない)。

## [Warning] 二重送信防止と in-flight フィードバック不足
- 判断: 対応
- 対応内容: in-flight 中は `aria-busy` を付与し送信ガードで no-op(既存 open と同流儀)。

## [Warning] route 文字列直書き(Ziggy 推奨)
- 判断: 反論
- 根拠: 既存 `open` も `router.post(\`/notifications/${id}/open\`)` と文字列直書き。
  この 1 箇所だけ Ziggy を導入するのは既存規約からの逸脱で一貫性を損なう。
  既存 open と同一の記法に揃える。

## [Warning] 成功時に同期される UI 範囲(未読件数・一括既読状態)
- 判断: 対応(明記)
- 対応内容: サーバ `back()` 再読込で shared props(`NotificationBell` の unreadCount)も
  更新される旨を期待効果に明記。楽観 state は行表示のみ即時反映、件数はサーバ確定で追随。

## [Warning] 失敗時フィードバック
- 判断: 対応
- 対応内容: onError で楽観既読を未読へ復帰 = 行が未読に戻る = 視覚フィードバック。
  ボタンは残るため再試行可能。既存アプリの global flash に依存しない最小実装。

## [Warning] 未読時のみ表示によるレイアウトシフト/誤爆
- 判断: 対応
- 対応内容: メインボタン右パディングで領域を常時確保、既読ボタンは絶対配置(上記 Critical#2 対応と同一)。

## [Suggestion] 各種
- 判断: 参考として設計に反映済み。
