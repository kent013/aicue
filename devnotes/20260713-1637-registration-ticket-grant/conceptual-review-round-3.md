全体判定: **CHANGES_REQUESTED**

DB 制約による原子保証への変更は正しい方向です。ただし、既存データを含む migration の成立性が未解決です。

### 1. 使命との整合性

[Suggestion] 登録直後の試用導線を回復するため、North Star に整合しています。

### 2. 禁止事項違反

[Warning] 課金冪等性は Architecture テストで強制する規約ですが、追加予定が Feature テストのみです。

修正提案: 部分 UNIQUE index の存在・述語・対象列を検査する Architecture テストを追加してください。Feature テストは実際の競合抑止確認として併用します。

### 3. 実現可能性

[Critical] 既存データに同一組織の `signup_grant:%` 行が複数あれば、UNIQUE index の migration は失敗します。旧実装では再契約などにより異なる subscription ID の行が存在し得るため、理論上の問題に留まりません。

修正提案: migration 前に重複件数を非破壊で監査し、重複がある場合は migration を fail-closed で停止する手順を設計してください。台帳行の削除・書換えは行わず、既存重複の扱いは別途承認された補正手順へ分離します。

[Warning] index 作成とアプリ更新の順序が未定義です。

修正提案: 「重複監査 → index 追加 → 新コード展開」の順序を明記してください。index 作成中の書込みロック許容性も詳細設計で確認します。

### 4. 期待効果の妥当性

[Suggestion] 新規自己登録の残高ゼロと Pricing の誤表記は解消できます。backfill 対象外である点も明確です。

### 5. リスク

[Suggestion] 部分 UNIQUE index は旧キーと新キーを同じ制約で扱えるため、ローリングデプロイ時の二重付与対策として妥当です。

[Warning] 認証済み捨てアカウントの悪用価値を「小さい」とする根拠はまだ定量化されていません。

修正提案: 受容判断として、少なくとも登録レート、1チケットの原価、監視対象を詳細設計または運用メモに記録してください。

### 6. スコープの適切さ

[Suggestion] forward fix、DB制約、必要な文言修正に限定され、適切な範囲です。招待経由が対象外であることは、最終的な文言テストでも明確にしてください。

### 7. 型安全性

[Suggestion] `grantSignupGrant(Organization $org): void` は外部キー注入を構造的に排除でき、DTO/Props の変更もないため PHPStan level 10 と両立します。

既存重複データの非破壊監査と Architecture テストを設計へ追加すれば、承認可能です。