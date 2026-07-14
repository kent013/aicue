全体判定: CHANGES_REQUESTED

### 1. 使命との整合性

[Suggestion] 保存確認とエラー帰属の改善は「思考ゼロ」の導線を補強しており、使命との整合性に問題ありません。

### 2. 禁止事項違反

[Suggestion] 禁止事項への抵触はありません。`scenario.update` の JsonResource/XHR・409 契約を維持し、backend flash を追加しない判断も妥当です。

### 3. 実現可能性

[Warning] `justSaved` の状態遷移記述が矛盾しています。

- 対応サマリー: `reseed(409/明示リロード)` で `false`
- 更新後抜粋: 「`reseed` 成功で `true`」

409後や明示リロード後の `reseed` で `true` にすると、今回保存していないのに「保存しました」と表示されます。

修正提案: `true` にする契機を「保存成功時の `applySaved()` のみ」と明記してください。`reseed()` は理由を問わず `false` とするのが安全です。

### 4. 期待効果の妥当性

[Suggestion] source別stateとphase-aware titleにより、起動失敗とジョブ失敗の帰属は一義になります。Round 1の懸念は解消されています。

### 5. リスク

[Warning] 上記の状態遷移矛盾を残すと、409競合後に偽の成功表示を出す可能性があります。

修正提案: 状態遷移を次のように固定してください。

- `applySaved()` 成功: `justSaved = true`
- save開始・save失敗・dirty転換・初期化・409後reseed・明示リロード: `justSaved = false`

### 6. スコープの適切さ

[Suggestion] frontend 2コンポーネントに限定したスコープは適切です。グローバルtoastポリシーやbackend契約を変更しない判断も妥当です。

### 7. 型安全性

[Suggestion] `StartError` の明示型とsource別の2 stateは局所的で型安全です。`showPurchaseLink` の誤帰属も防げます。

上記の `reseed` 記述を修正すれば、設計上の残存懸念はなく `APPROVED` と判断できます。