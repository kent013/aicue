**詳細設計書**

[Suggestion] 指摘なし。RC-8 の保証範囲が「総件数と未確定表名の exact pin」に統一され、実装・gate docblock・`docs/architecture.md` と一致した。区分ごとの件数を pin しない理由も明示されており、設計と実装の不一致は解消されている。

**tests/Feature/Retention/RetentionTableClassificationTest.php**

[Suggestion] 指摘なし。RC-6 の正の自己検証は、宣言の形だけでなく実スキーマの外部キーを参照し、`ownerClass === null` の `DeletedWithParent` が実際に `cascade` を持つことを確認している。これにより、RC-6 の通り道 (a) が実データで評価されていることを固定できている。

RC-6 / RC-7 の純関数にも新たな fail-open は見当たらない。表・外部キー・列情報の欠落は違反側へ倒れ、`set null` の例外も「参照先が分類済み」「列一覧が非空」「全列 nullable」の全条件を要求している。

**tests/Support/Retention/RetentionClass.php**

[Suggestion] 指摘なし。`hasHorizon()` の分類と RC-7 の用途が一致しており、`Undecided` も保守的に horizon 側へ分類されている。

**tests/Support/Retention/RetentionTableEntry.php**

[Suggestion] 指摘なし。不正な区分固有状態を名前付き生成子で閉じており、型の widen もない。

**tests/Support/Retention/RetentionTableRegistry.php**

[Suggestion] 指摘なし。63 表の分類、未確定表の明示、保持期限の値を重複管理しない責務境界は維持されている。

**tests/Architecture/BillingRetentionTargetInventoryTest.php**

[Suggestion] 指摘なし。課金保持 gate との責務境界は明確で、検査ロジックへの変更もない。

**docs/architecture.md / AGENTS.md**

[Suggestion] 指摘なし。保証する範囲と保証しない範囲は実装と整合しており、Schedule 配線や実削除まで保証するという誇張もない。

負のコントロールは RC-1、RC-2、RC-3 の二重宣言、RC-6、RC-7 の競合動作・nullable 境界・情報欠落を直接点灯させている。正のコントロールも `set null` と全列 nullable の許容境界を固定しており、検査が「外部キーがあれば一律違反」へ退化した場合を検出できる。

全体判定: **APPROVED**