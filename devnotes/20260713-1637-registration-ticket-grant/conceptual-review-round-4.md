全体判定: **APPROVED**

### 1. 使命との整合性

[Suggestion] 登録直後から主要機能を試せる状態を回復し、North Star に直接貢献します。

### 2. 禁止事項違反

[Suggestion] 課金冪等性を DB 制約、Architecture テスト、Feature テストの三層で保証しており、規約に適合します。

### 3. 実現可能性

[Suggestion] 重複監査を fail-closed とし、台帳を変更せず index 作成を停止する設計は安全かつ実現可能です。デプロイ順序も適切です。

### 4. 期待効果の妥当性

[Suggestion] 今後の自己登録者に対する残高ゼロ問題と Pricing の表記不整合を解消できます。backfill 対象外という効果範囲も明確です。

### 5. リスク

[Suggestion] 部分 UNIQUE index により、旧キー・新キー・並行処理を横断して「1組織1回」を原子的に保証できます。捨てアカウントについても残余リスクと受容根拠が明文化されています。

### 6. スコープの適切さ

[Suggestion] forward fix、原子的な冪等保証、必要な文言修正、対応テストに限定されており適切です。

### 7. 型安全性

[Suggestion] `grantSignupGrant(Organization $org): void` は外部キー注入を排除し、DTO/Props の変更もないため PHPStan level 10 と両立します。

詳細設計では、`pg_indexes.indexdef` が PostgreSQL により `LIKE` ではなく内部表現で返る可能性があるため、Architecture テストを完全一致文字列へ過度に依存させない点だけ留意してください。