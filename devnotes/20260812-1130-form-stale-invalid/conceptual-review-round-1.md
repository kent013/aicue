全体判定: CHANGES_REQUESTED

[Critical] `<form>` 依存の再表示契機だけでは穴が残ります。  
設計自身が挙げている「同じ文言のエラーが 2 回続けて返るケース」は、`submit` イベントを拾える通常フォームでは閉じられますが、`<form>` を持たない XHR + button click、検索ボックス、プログラム的 submit、Inertia の部分再描画では閉じられません。その場合、`input` で一度 hidden になった error が、同じ文字列で再到着しても再表示されない可能性があります。

修正提案: `FormField` だけの暗黙推定で完結させるなら、`input` だけでなく `change` も拾い、さらに「送信開始/検証結果到着」を示す明示的な契機がない call site は対象外にするべきです。全域適用するなら props 追加なし方針を緩め、少なくとも `errorKey` / `errorVersion` / `touchedResetKey` のような再表示トークンを検討してください。props を増やさない制約を優先するなら、まず F-1-01 / F-3-02 の 2 箇所だけを直す方が安全です。

[Critical] `input` イベントだけでは変更種別を取りこぼします。  
`text input` は拾えますが、`select`、`checkbox`、`radio`、`file`、日付系 input、カスタムコンポーネント経由の変更は `change` 依存になるケースがあります。特に file は `input` より `change` を見る方が堅いです。「subtree の input で隠す」は名前に反してフォーム値変更全般を扱えていません。

修正提案: 少なくとも `input` と `change` の両方を契約にしてください。テストも text / select / checkbox / file 相当を分けて固定する必要があります。

[Warning] 65 箇所への一括波及は、現時点の根拠だと広すぎます。  
観測は 2 画面、原因仮説は妥当ですが、`FormField` は「単一テキスト入力だけを包む」とは限りません。複数入力を 1 つの `FormField` に入れている場合、片方を触っただけでフィールド全体の error が隠れます。入力を伴わない UI、カスタム picker、部分再描画、form 外利用もあります。component 1 つで 65 箇所を変えるなら、代表 2 画面の Vitest だけでは薄いです。

修正提案: まず `FormField` 利用箇所を分類してください。最低でも「form 内の単一 control」「form 内の複数 control」「form 外」「slot 内 custom component」「file/select/checkbox」を分け、危険な分類が少ないことを確認してから共通化すべきです。分類できないなら 2 箇所修正が妥当です。

[Warning] 既存 9 箇所の `clearErrors` 撤去はまだ承認できません。  
設計は「表示層だけ隠す」と言っていますが、既存の `clearErrors` は `form.errors` の値そのものを消します。これは `form.hasErrors`、送信ボタンの見た目、summary 表示、aria、別コンポーネントへの error 伝播に影響します。表示層と値の層を分離する方針自体は良いですが、既存箇所の意味が「表示だけ」だった証明が未完了です。

修正提案: 9 箇所は同じ PR で機械的に消さず、各 call site で `form.errors` / `form.hasErrors` / error summary / disabled 代替表現への依存を確認してから撤去してください。依存がある箇所は撤去しないか、表示専用 state へ置換してください。

[Warning] `aria-invalid` を隠し状態と同期する方針は妥当ですが、失敗時の再表示保証が前提です。  
隠している間に `aria-invalid=false` にするのは支援技術に stale error を読ませない点で筋が良いです。ただし、再送信後も同じ error が返ったのに再表示されないケースでは、視覚表示だけでなくアクセシビリティ上もエラーが消失します。

修正提案: 「submit または error version で必ず再表示される」ことを先に契約化してください。その契約なしに aria を同期すると、より深刻な見落としになります。

[Suggestion] 実装名は `input` より `userEdited` / `staleSuppressed` のように役割へ寄せるとよいです。  
この機能の本質は「値が正しいか判定する」ではなく「前回送信結果としての error 表示を、ユーザー編集後は stale とみなして抑制する」です。名前でそこを表すと、将来クライアントバリデーションと混同しにくくなります。

[Suggestion] テストは単体だけでなく契約パターンを増やしてください。  
最低限、`input` で隠れる、`change` で隠れる、submit で戻る、同じ error 文字列でも submit 後に戻る、form 外で壊れない、form 外では同一 error 再到着を保証しない/別手段で保証する、複数 FormField の片方だけ隠れる、を固定したいです。

結論として、課題設定と「呼び出し側の定型句忘れをなくす」方向は妥当です。ただし、現在の概念設計は `input + nearest form submit` に寄りすぎていて、form 外・非 text control・同一 error 再到着・既存 `clearErrors` の値層依存に対する詰めが不足しています。共通 component で 65 箇所へ広げる前に、適用対象の分類と再表示契機の設計をやり直すべきです。