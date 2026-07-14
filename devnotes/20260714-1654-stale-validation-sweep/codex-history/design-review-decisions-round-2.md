# 対応マトリクス: design-review Round 2

全体判定: **CHANGES_REQUESTED**。施策1・2 は APPROVE 継続。過剰クリア防止 2 件は解消。
serverErrors 非退行 2 件が「クリア分岐を実際に通していない」として REQUEST_CHANGES。

## [Warning] 施策3: serverErrors テストが $effect クリア分岐を通っていない
- 判断: 対応する（Codex 修正案を全面採用）
- 根拠: 鋭い指摘。server error 設定時 `transferClientError` は null のため、valid→valid の
  変更では `transferClientError !== null` を満たさずクリア分岐が走らない。「$effect が
  serverErrors を消さない」の直接検証になっていなかった。
- 対応内容: 操作列を「有効A送信で server error 設定 → 空選択送信で client error が覆う →
  有効B選択で $effect が client error をクリア（分岐を実通し）→ 背後の server error 再表示・
  残存を確認」に変更。これで (a) クリア分岐が実行され (b) server error が破壊されず下層に
  温存・再表示されることを同時に固定。fixture は有効候補 2 人以上を明記。

## [Warning] 施策4: 同上（add-member）
- 判断: 対応する（Codex 修正案を全面採用）
- 根拠: 施策3と同旨。
- 対応内容: 同じ 4 ステップ操作列に変更。`assignableUsers` に有効候補 2 人以上を前提へ明記。

## [Suggestion] 施策3/4: 有効候補 2 人 fixture を明記
- 判断: 対応する（反映済み）
- 根拠: 候補 A→B 切替に必須。テスト前提へ明記した。

## 解消済み（Round 1 由来）
- 過剰クリア防止テストの単一条件化（施策3・4）: Codex Round 2 で「解消」と明言。
