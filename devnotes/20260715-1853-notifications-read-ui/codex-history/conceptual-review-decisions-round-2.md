# 対応マトリクス: conceptual-review Round 2

## [Warning] 楽観更新でボタンが即消え → aria-busy 不達・フォーカスロスト
- 判断: 対応
- 対応内容: 既読ボタンの描画条件を `unread || reading` にし、in-flight 中は DOM に残して
  `aria-busy={reading}` を提示。成功確定でボタンが消える際は同一行の open(content)ボタンへ
  `focus()` を移してフォーカスロストを防ぐ(content ボタンを `bind:this`)。

## [Warning] 通信失敗の明示通知(toast / aria-live)
- 判断: 対応
- 対応内容: 既存 toast 基盤(`@/lib/stores/toast#addToast`)を再利用し、onError で
  `addToast('error', '既読にできませんでした。再試行してください。')` を出す。error toast は
  自動消去されず ToastContainer が aria-live で読み上げる。楽観既読は未読へ復帰しボタンを残す。

## [Suggestion] 各種
- Round 1 の 2 Critical は解消済みと確認。方針(prop 優先の単調楽観 state、既存記法統一、
  主/副操作の a11y 明文化)を維持。
