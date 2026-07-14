全体判定: **CHANGES_REQUESTED**

Round 1 の主要指摘は概ね解消されています。ただし、操作不能につながる状態設計と IME の前提に残課題があります。

### 1. 使命との整合性

[Suggestion] 期待効果を「誤操作復旧コストの低減」とした修正は適切です。特に削除・並べ替えの復旧は、AI 生成シナリオを安全に調整する体験へ直接貢献します。

### 2. 禁止事項違反

[Warning] 編集中かつ `undoStack` が空の場合、Undo ボタンが disabled のままになる可能性があります。disabled なボタンはクリックもフォーカス移動も発生しないため、「押下で blur → pending 編集を確定 → undo」が成立しません。

修正提案: Undo の利用可能判定をスタックだけでなく、未確定編集の実変化も含めてください。

```ts
canUndo = undoStack.length > 0
    || (editBaseline !== null && editBaseline !== serializeSteps(steps));
```

これに対応する「初回セル編集の focus 中に Undo ボタンを押して戻せる」テストも必要です。

### 3. 実現可能性

[Warning] 「`focusout` は必ず IME 変換確定後に発火する」という前提は強すぎます。変換中のクリックやプログラム的フォーカス移動では、ブラウザ・IME により `compositionend` と `focusout` の順序が一定とは限りません。`keydown.isComposing` だけでは focusout commit を防げません。

修正提案: `compositionstart` / `compositionend` で編集セッションの composing 状態を管理し、composing 中の focusout は即時 commit せず、`compositionend` 後に確定する設計にしてください。少なくとも順序差を模したテストを追加してください。

[Suggestion] スナップショット方式、正規形デコーダ、冪等な `flushPendingEdit()` は Svelte 5 の構成として実現可能です。

### 4. 期待効果の妥当性

[Suggestion] native text undo と document undo のフォーカス文脈による分離は妥当です。Round 1 の UX リスクは解消されています。

### 5. リスク

[Warning] サイズ上限の記述が `undoStack` に限定されていますが、undo の連続実行では同量の履歴が `redoStack` に移ります。redo 側の上限・running total 更新・`resetHistory()` 時のリセットが未定義です。

修正提案: 上限を「両スタック合計」に適用するか、各スタックへ個別適用するかを明記し、push・pop・移送・redo クリア・履歴リセット時の文字数管理を純関数としてテストしてください。

[Suggestion] peek → validate → mutate の順序と、失敗時に現在状態を維持する方針は適切です。

### 6. スコープの適切さ

[Suggestion] クライアント内の保存前履歴に限定するスコープは適切です。

[Suggestion] スコープ外の「input 内ではアプリ層 undo を優先」という記述は、改訂後の設計と矛盾しています。「native undo に委ね、document undo との厳密な履歴統合は行わない」へ修正してください。

### 7. 型安全性

[Suggestion] `unknown → shape 検証 → 正規化 → DraftStep[]` は十分な方向性です。配列要素、points、全必須フィールドを検証し、単なる型アサーションを内部に残さなければ問題ありません。

承認に必要な残課題は、Undo ボタンの pending 編集対応、IME イベント順序への対応、redo を含む履歴容量管理の明文化です。