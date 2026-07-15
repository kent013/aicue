**全体判定**: `CHANGES_REQUESTED`

**施策1: 個別既読ボタンの追加**: `REQUEST_CHANGES`
- [Critical] **`reading` 中にボタンが消える不整合**  
  現在の `unread = read_at === null && !optimisticallyRead` と `{#if unread || reading}` の組み合わせだと、`onSuccess` で `optimisticallyRead = true` になった直後に `onFinish` 前でも `unread=false` となり、`reading=true` でも描画条件は true ですが、`markRead` の早期 return 条件やフォーカス制御が状態遷移に強く依存します。実装ミスで `reading` 反映順が変わると消失・フォーカス漏れが起きやすい設計です。  
  **修正案**: 表示条件を `showReadButton = unread && !opening` など責務分離し、`reading` は `aria-busy` と連打防止専用に限定。フォーカス移動は `tick()` 後に実施して DOM 確定を待つ。
- [Warning] **`onError` 後の状態復元が暗黙的**  
  設計文では `onError で false 復帰` とある一方、提示コードは `optimisticallyRead` を戻していません（成功時のみ true）。現状は「成功時のみ true」なので実害は小さいですが、将来 `onStart` で optimistic 化に変更された時に事故ります。  
  **修正案**: `onError` に `optimisticallyRead = false` を明示し、コメントで「単調だが defensive reset」と残す。
- [Warning] **アクセシビリティ: 状態の可視化不足**  
  `aria-busy` は付いていますが、読み上げ上は「何が busy か」が伝わりづらいです。  
  **修正案**: `aria-label` を `reading ? "既読処理中" : "既読にする"` に切替、または `aria-live` トースト文言を成功時にも追加（`info/success`）。
- [Suggestion] **イベント競合の明示防止**  
  兄弟要素なので通常は open へ伝播しませんが、将来ラッパに click を置く変更に弱いです。  
  **修正案**: `markRead` 冒頭で `event?.stopPropagation()` 可能な形（`onclick={(e) => markRead(e)}`）にして防御的にしておく。

**施策2: vitest テスト追加**: `REQUEST_CHANGES`
- [Critical] **`reading`（in-flight）状態のテスト欠落**  
  現在計画は start/success/finish と error のみで、`onStart` 時の二重送信防止を検証していません。  
  **修正案**: `onStart` のみ発火させた状態で既読ボタン連打し、`router.post` が 1 回のみであることを追加。
- [Warning] **フォーカス移動の回帰テスト欠落**  
  DOM 削除後の `contentButton.focus()` は今回の主要副作用です。  
  **修正案**: success+finish 後に `document.activeElement === getByTestId('notification-item')` を検証。
- [Warning] **Inertia オプション検証の粒度不足**  
  `preserveScroll: true` だけでなく `onStart/onFinish/onError` が渡される契約確認があると回帰に強いです。  
  **修正案**: `expect.any(Function)` でオプション shape を固定。
- [Suggestion] **open/read の排他を明確化**  
  「read 押下時に open が呼ばれない」は良いですが、逆方向（item クリック時に read が呼ばれない）も追加するとより安全。

**観点別ショートレビュー**
- ロジック/エッジ/null 安全: 概ね良いが、状態責務の分離不足。
- 既存整合/命名: `notification-item` 等の温存方針は妥当。
- PHPStan L10: PHP変更なしで影響なし。
- DTO/JsonResource: 変更なしで準拠。
- Inertia Props vs API: `back()` 再描画前提 + optimistic は妥当。
- セキュリティ: 既存 `read` endpoint 利用で新規リスク小。
- DESIGN/Atomic: Lucide `Check` 使用は準拠、階層逸脱なし。

必要なら、この指摘を反映した**修正版のテストケース一覧（そのまま実装可能な粒度）**まで具体化します。