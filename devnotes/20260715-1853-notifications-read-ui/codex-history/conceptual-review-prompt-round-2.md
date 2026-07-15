Round 1 の指摘への対応を報告します。対応マトリクスと修正後の該当セクションを提示します。
再レビューし、全体判定(APPROVED / CHANGES_REQUESTED)を返してください。

## 対応マトリクス（要点）

### [Critical] 状態責務の二重化
- 対応 + 一部反論。source of truth はサーバ props に固定。子の楽観 state は「単調(未読→既読方向のみ)・onError 復帰」に限定し、unread 判定を `notification.read_at === null && !optimisticallyRead` として **prop を常に優先**。read-all 等が prop.read_at を設定すれば楽観 state に関わらず既読表示になり乖離しない。
- 反論根拠: 既存 open / read-all も「POST→back() 再読込→props 再確定」で更新しており、親 Index に可変 item state を持たせていない。Index に可変コピー+コールバックを持たせるのは既存流儀からの逸脱でオーバーエンジニアリング。子に閉じた単調楽観 state で整合は保てる。

### [Critical] DOM 再設計による主導線・a11y 後退
- 対応。操作モデルを明文化: open=主操作(メイン content ボタンが行 hit area・flex-1・focus ring・testid notification-item/data-unread/onclick open を保持)、read=副操作(右上に絶対配置、独自 aria-label="既読にする"・focus ring、Tab 順は content の次)。レイアウトシフト回避はメインボタンに右パディングを常時確保し既読ボタンをその予約領域に絶対配置(未読→既読で本文 reflow しない)。

### [Warning] in-flight フィードバック
- 対応。in-flight 中は aria-busy + 送信ガードで no-op。

### [Warning] route helper (Ziggy)
- 反論。既存 open も文字列直書き。1 箇所だけ Ziggy 導入は一貫性を損なうため既存記法に揃える。

### [Warning] 同期 UI 範囲 / 失敗フィードバック / レイアウトシフト
- 対応。件数・一括既読状態はサーバ再読込の shared props で追随と明記。失敗時は楽観既読を未読に復帰(=行が未読に戻る視覚フィードバック)しボタンは残り再試行可能。レイアウトシフトは上記絶対配置で回避。

## 修正後の該当セクション

### 実装方針
- NotificationListItem.svelte を「open ボタン + 個別既読ボタン」の 2 ボタン構成へ。外側 div ラッパで兄弟化(button ネスト回避)。
- 操作モデル: open=主操作(content ボタンが hit area・flex-1・focus ring・testid notification-item/data-unread/onclick open 保持)。read=副操作(右上絶対配置アイコンボタン、aria-label="既読にする"、focus ring、Tab 順は content の次)。レイアウトシフト回避=メインボタン右パディング常時確保+既読ボタン絶対配置。
- 未読時のみ既読ボタン(Lucide Check、data-testid=notification-read-button、disabled 不使用)。
- 既読ハンドラ: router.post('/notifications/{id}/read', {}, { preserveScroll: true })。遷移しない。in-flight は aria-busy + 送信ガード。
- source of truth はサーバ props。unread = notification.read_at === null && !optimisticallyRead(prop 優先)。楽観 state は単調(未読→既読)・onError 復帰。件数は shared props(NotificationBell)がサーバ確定で追随。
- Index.svelte 変更不要。純フロント(backend/route/DTO/型変更なし)。

### 対象 UI
通知センター一覧(/notifications)。Bell は未読件数バッジ + 導線で、行操作は一覧に集約。Bell への個別既読導線追加はスコープ外(将来対応)。
