以下、設計書（施策1〜8）との照合結果です。結論から言うと **完了扱いは不可** です。

- [Critical] (a) `tests/Browser/AuthenticatedPageBfcacheTest.php:79`, `tests/Browser/AuthenticatedPageBfcacheTest.php:303` (b) 施策8の必須条件「WebKitでシナリオ2/3/4を恒久自動回帰として成立」に対し、`bfcacheSkipUnlessRestoreIsReproducible()` で3シナリオがskip前提になっており、実装完了条件を満たしていません。これは空振りgreenの回避ではあるが、**必須条件の未達**です。 (c) 修正方針は二択のみ: ① WebKitで`pageshow.persisted===true`を継続観測できるハーネスに変更してskip撤廃、② 詳細設計を再レビューで改定し、施策8完了条件を正式に変更（T082は未完了のまま分割）。

- [Critical] (a) `docs/supported-browsers.md:25`, `docs/supported-browsers.md:76` (b) 文書が「復元シナリオは自動回帰で担保できていない」「実機記録なし」と明記しており、施策7の「マージ後Currentは実態を書く」には整合していますが、同時に施策8必須条件の未達を自己申告しています。**この状態でT082完了は不可**です。 (c) 施策8達成後にCurrentを再記述するか、未達を前提にタスクを分割して完了判定を切り離してください。

- [Warning] (a) `app/Http/Routing/RouteBindingTypes.php:84`, `tests/Architecture/RouteBindingTypeConstraintInventoryTest.php:286` (b) `MANUALLY_RESOLVED` 新設でIV-9(a)をparam単位で除外しており、設計書の原則（型一致必須）から逸脱。`notification`を使う将来route全体が同時に免除されるため、deny-by-defaultの穴になり得ます。 (c) 免除は `route identity + param` 単位へ縮小し、`notifications.open/read`以外の新規免除はfailするテストを追加してください。

- [Warning] (a) `tests/Architecture/RouteBindingTypeConstraintInventoryTest.php:56` (b) Livewireの`method:uri`突合に`livewire-<hash>/`正規化を追加したのは実務上妥当ですが、設計書規約（name or method:uri）への未記載拡張です。将来prefix衝突時の誤同一視リスクがあります。 (c) 詳細設計へ明記し、正規化対象/非対象の負のコントロールを追加して仕様化してください。

- [Warning] (a) `tests/Feature/Security/ExistingNoStoreContractTest.php:79`, `tests/Feature/Security/ExistingNoStoreContractTest.php:90` (b) 施策5の設計表では既存3経路の期待完全値が`no-store`でしたが、実装は`no-store, private`でピン。強化の可能性はあるものの、**設計との差分が未解決**です。 (c) 実測根拠で設計表を更新するか、期待値を設計どおりに戻して「untouched契約」の意味を揃えてください。

- [Suggestion] (a) `app/Http/Routing/RouteBindingTypes.php:129` (b) `NON_MODEL`縮小（7→3）は実route実態に沿う可能性が高く、保証弱化とは断定しませんが、設計書との差分説明が不足。 (c) 設計書/`docs/architecture.md`に「削除したキーと根拠（route走査結果）」を追記してください。

**申告4点の判定**
- 1) `MANUALLY_RESOLVED` 新設: 条件付きで妥当だが現状は広すぎ（要修正）
- 2) `NON_MODEL` 縮小: おおむね妥当（要ドキュメント同期）
- 3) Livewire正規化: 実務上妥当（要仕様化）
- 4) `whereNumber`削除: 設計整合（問題なし）

**VERDICT: CHANGES_REQUESTED**