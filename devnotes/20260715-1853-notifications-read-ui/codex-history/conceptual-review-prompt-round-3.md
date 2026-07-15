Round 2 の残 2 Warning への対応を報告します。再レビューし全体判定を返してください。

## [Warning] 楽観更新でボタン即消え → aria-busy 不達・フォーカスロスト
対応: 既読ボタンの描画条件を `unread || reading` にし、in-flight 中は DOM に残して `aria-busy={reading}` を提示。成功確定でボタンが消える際は同一行の open(content)ボタンへ `focus()` を移す(content ボタンを `bind:this`)。

## [Warning] 通信失敗の明示通知
対応: 既存 toast 基盤(`@/lib/stores/toast` の `addToast`)を再利用。onError で `addToast('error', '既読にできませんでした。再試行してください。')`。error toast は自動消去されず ToastContainer が aria-live で読み上げる。併せて楽観既読を未読へ復帰しボタンを残す(再試行可能)。

これで残件は解消したと考えます。APPROVED 可否を判定してください。
