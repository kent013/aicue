全体判定: **APPROVED**

## 1. 使命との整合性

[Suggestion]  
使命との整合性は高いです。SOP 起点の動画シナリオ編集画面で、日本語入力中に別行へ削除・追加が当たる欠陥は、現場利用者にとって発見しづらいデータ喪失です。安定キーで対象を再解決する方針は、AI-CUE の「編集判断の負担を下げる」前提に直接貢献します。

## 2. 禁止事項違反

[Suggestion]  
禁止事項への明確な抵触はありません。サーバ側変更なし、`response()->json()` なし、LLM 経路なし、UI disabled 化なし、Artifact なしという整理で妥当です。

## 3. 実現可能性

[Warning]  
`confirmingStepIndex` を `confirmingStepKey` に替える方針は妥当ですが、ダイアログ表示側が現在 `steps[confirmingStepIndex]` に依存している場合、表示文言・確定ボタン・キャンセル時の状態リセットも同時に見直す必要があります。

修正提案:  
実装方針に「確認ダイアログ表示時は `confirmingStepKey` から現在の step を derived / helper で解決し、存在しなければダイアログを閉じる、または確定時 no-op にする」と明記してください。削除関数だけ key 化しても、表示側に index 依存が残ると同種のズレが残ります。

## 4. 期待効果の妥当性

[Warning]  
「本変更で唯一の throw 経路が消えるため、drain ループへの try/catch は不要」という主張は少し強いです。提示された 3 経路の `steps[stepIndex]` / `points[pointIndex]` 由来の throw は消せますが、`pendingActions` 内の全 closure が将来も例外を投げないことまでは保証していません。

修正提案:  
表現を「今回確認した既存の index ずれ由来の throw 経路は消える」に弱めてください。`try/catch` を足さない判断自体は、スコープを絞る設計として妥当です。

## 5. リスク

[Warning]  
対象が存在しない場合の no-op は安全側ですが、削除確認ダイアログ経由では「開いたときの対象がすでに別操作で消えた」状態があり得ます。この場合にモーダルが開いたまま残ると、ユーザーには stale な確認 UI に見える可能性があります。

修正提案:  
`confirmingStepKey` の対象が解決不能になった場合の UI 状態を決めてください。候補は「compositionend 後、対象解決不能なら `confirmingStepKey = null` にして閉じる」または「確定時 no-op して閉じる」です。後者のほうが局所変更で済みます。

## 6. スコープの適切さ

[Suggestion]  
スコープは適切です。`runSettled` / queue / history を作り替えず、捕捉値だけを index から `clientKey` に替えるのは、T185 の既存判断と揃っています。`save()` の IME gate を別論点として外す判断も妥当です。

## 7. 型安全性

[Warning]  
TypeScript 面では、`findIndex` 後の `-1` 分岐を確実に return し、以降で `steps[index]` / `points[index]` を使う形にする必要があります。Svelte テンプレート側でも `step.clientKey` / `point.clientKey` を渡すように統一しないと、関数シグネチャ変更後に曖昧な `number` 経路が残ります。

修正提案:  
`addPoint(stepKey: string)`, `removeStep(stepKey: string)`, `removePoint(stepKey: string, pointKey: string)` のように関数名・引数名で key 前提を明示してください。テストに加えて `pnpm typecheck` で markup 側の呼び出し漏れを検出する方針で十分です。

## 総評

設計の方向は承認できます。特に「遅延実行される構造操作は安定キーで対象を捕捉し、実行時に解決し直す」という不変条件への統一は筋が良いです。

実装前に補うべき点は、`confirmingStepKey` に伴うダイアログ表示側の扱いと、「throw 経路が消える」という主張の範囲を少し正確にすることです。そこを明記すれば、このまま詳細設計・実装へ進めてよい内容です。