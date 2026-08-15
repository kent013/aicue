**tests/Feature/Retention/RetentionTableClassificationTest.php**

[Warning] RC-8 は「区分ごとの件数」を保証すると docblock / architecture に書かれていますが、実装は `RETENTION_TABLE_COUNT` と `RETENTION_UNDECIDED_TABLES` だけを pin しています。詳細設計の後半では「区分ごとの件数は pin しない」と修正されているので、実装側ではなく説明側の誇張です。`RC-8: 総件数と未確定の表名...` に揃えるべきです。

[Suggestion] RC-6 の正の自己検証は「DeletedWithParent かつ ownerClass なしの表が 1 件以上ある」だけなので、実際に cascade 経路が現実スキーマで評価されたことの検証としては少し弱いです。ただし本体の RC-6 と NC-3 があるため、現状で致命的ではありません。より厳密にするなら、`ownerClass === null` の DeletedWithParent のうち `retentionForeignKeyMap()` 上で cascade を持つものが 1 件以上あることを見る形がよいです。

RC-6 / RC-7 の純関数自体は fail-closed 側に倒れています。特に RC-7 は参照先未分類、`on_delete` 不明、列一覧空、nullable 不明をすべて違反にしており、「緑になりやすい側」の穴は見当たりません。`set null + 全列 nullable` だけを非違反にする境界も設計と一致しています。

**tests/Support/Retention/RetentionClass.php**

[Suggestion] 問題なし。`Undecided` を `hasHorizon()` true 側に置く判断は、未確定な親を基準データ / framework managed が参照するケースを保守的に赤くするため、設計意図と一致しています。

**tests/Support/Retention/RetentionTableEntry.php**

[Suggestion] 問題なし。private constructor と名前付き生成子で、scheduled deletion の owner 宣言漏れを作りにくくしている点も設計通りです。`RATIONALE_MIN_LENGTH` の追加も RC-3 と噛み合っています。

**tests/Support/Retention/RetentionTableRegistry.php**

[Suggestion] 問題なし。`organizations` / `teams` / `oauth_*` を未確定に寄せ、`blind_indexes` だけ削除責務クラス付きの `DeletedWithParent` にしている分類は、提示された実測と整合しています。`blind_indexes` の Eloquent observer 限定の非対称も根拠に書かれており、保証の誇張はありません。

**tests/Architecture/BillingRetentionTargetInventoryTest.php**

[Suggestion] 問題なし。既存 gate との責務境界は明確で、年数・起算点・purger を T175 側へ写さない方針も維持されています。

**docs/architecture.md**

[Warning] ここも RC-8 の説明が「総件数と未確定の表名」となっていて実装と合っていますが、その直前の「検査が保証するもの」内で「総件数と未確定の表名」と書けているため、詳細設計本文側に残っている「区分ごとの件数」表現と食い違います。レビュー対象 diff としては大きな問題ではないものの、SoT を詳細設計に置くなら文言統一が必要です。

**AGENTS.md**

[Suggestion] 問題なし。外部キー条件を AGENTS.md に写さず docs/architecture.md へ委譲しているため、二重管理を避けられています。

**全体判定: CHANGES_REQUESTED**

実装ロジックにブロッカーは見当たりませんが、RC-8 の保証範囲について「区分ごとの件数を pin する」と読める記述が残っている点は、今回のレビュー観点「保証しないものの誇張」に該当します。実装に合わせて「総件数と未確定の表名のみ」に文言を統一すれば approved でよい内容です。