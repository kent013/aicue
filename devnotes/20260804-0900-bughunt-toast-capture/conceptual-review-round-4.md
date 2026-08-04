全体判定: **CHANGES_REQUESTED**

### 1. 使命との整合性

[Suggestion] 問題なし。誤検知削減へ直接寄与する。

### 2. 禁止事項違反

[Suggestion] 抵触なし。

### 3. 実現可能性

[Suggestion] 応答統合と未解決時の扱いが明確になり、Round 3 の懸念は解消された。

### 4. 期待効果の妥当性

[Suggestion] L1/L2 が `visible:true` を要求するため、期待効果を実ブラウザで検証できる。

### 5. リスク

[Suggestion] `pending` 継続時を未検証に倒すことで、新たな誤検知を避けている。

### 6. スコープの適切さ

[Suggestion] 適切。

### 7. 検証計画の誠実さ

[Warning] `seen` の契約に記述不整合が残っている。実装方針では `visible:false` / `"gone"` も「人が事情を読めるように `seen` へ返す」としている一方、検証表では「不可視 live region が `seen` に入らない」としている。参照実装も前者の挙動である。

修正提案: 検証表を「不可視 entry も診断情報として `seen` に入るが、証拠集合には入らない」に変更し、テストを次の2点に分ける。

- `seen` には `visible:false` / `"gone"` が診断情報として保持される。
- finding 抑止判定では `visible:true` のみが採用される。