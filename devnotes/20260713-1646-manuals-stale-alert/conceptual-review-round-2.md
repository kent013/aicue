全体判定: **CHANGES_REQUESTED**

## 1. 使命との整合性

[Suggestion] SOP アップロード後の操作詰まりを解消するため、North Star との整合性は高い。スコープも中核導線の信頼性改善に限定されている。

## 2. 禁止事項違反

[Warning] acceptance criteria は既存テスト群の green のみで、今回の回帰を固定する新規テストが明記されていない。テストファースト原則および禁止事項 #1 を満たすには不十分。

修正提案: `AnalysisPanel` のコンポーネントテストを追加し、少なくとも「422 表示後に `hasDocument: false → true` となると alert が消える」「failedJob 表示とポーリング state は維持される」を検証対象として明記する。

## 3. 実現可能性

[Warning] 署名は「新しいサーバスナップショット」を識別していない。監視対象の値がすべて同じなら、Inertia により新しい props が届いても effect は no-op になる。

例えば、手順書が既に存在する状態で 402 が表示され、その後 SOP を差し替えても、`hasDocument/job/status/manualStatus` が変わらなければ overlay は残る。これは明文化した「新しいスナップショットで無条件破棄」という原則と実装が一致しない。

修正提案: 次のいずれかに設計を統一する。

- 本 finding に限定し、`hasDocument: false → true` の遷移時だけ「手順書なし」422 overlay を消す。
- 全 Inertia スナップショットを契機にするなら、署名ではなくページ更新を確実に識別できる revision/key を Show から渡す。

前者の方が変更範囲と副作用が小さく、この finding には適切。

## 4. 期待効果の妥当性

[Suggestion] 対象を「Show 内の SOP アップロード後に残る 422」に限定すれば、期待効果は合理的。現在の「transient overlay を無条件破棄」という一般化は、署名方式では保証できないため表現を狭める必要がある。

## 5. リスク

[Suggestion] `currentJob/status` を再同期対象から外した判断は妥当。ポーリングとの競合リスクが大きく低下している。

## 6. スコープの適切さ

[Suggestion] 1 ファイルの修正で十分。ただし「全 overlay の一般的な破棄」より「手順書なし 422 の解消」に責務を限定した方が、機能名と根本原因に忠実。

## 7. 型安全性

[Suggestion] PHP/DTO/JsonResource への影響はなく、PHPStan level 10 に関する問題はない。可能なら `errorMessage` の文字列判定ではなく、エラー種別またはステータスをローカル state として保持すると、422 だけを型安全に消去できる。