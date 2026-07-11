全体判定: **APPROVED**

### 1. 使命との整合性

[Suggestion] 自動復旧により利用者へ組織選択を強制せず、「思考ゼロ」の導線を維持しています。

### 2. 禁止事項違反

[Suggestion] 通常系、dangling、競合、UPDATE不成立を含むテスト契約が定義され、テストなしの実装完了を防げています。

### 3. 実現可能性

[Suggestion] relation経由の所属確認、条件付きUPDATE、fresh再取得という構成はLaravel 12で実現可能です。

### 4. 期待効果の妥当性

[Suggestion] ダッシュボード集約による次アクションの明確化と、残高・容量への早期気づきは合理的に期待できます。

### 5. リスク

[Suggestion] concurrent switchの上書き、除名後のcross-org表示、無限再試行のリスクが設計上抑制されています。

### 6. スコープの適切さ

[Suggestion] Resolverを再利用可能にしつつ、v1の呼び出し元をdashboardに限定する範囲は適切です。

### 7. 型安全性

[Suggestion] `?Organization`、ブロック単位DTO、`DashboardProps & SharedProps`により、PHPStan level 10とTypeScriptの型契約を維持できます。

Critical / Warning はありません。詳細設計へ進める状態です。