全体判定: **CHANGES_REQUESTED**

1. 使命との整合性

[Suggestion] 組織の管理不能化を防ぐ改善であり、継続的なマニュアル運用を支える基盤保護として使命に整合しています。

2. 禁止事項違反

[Warning] テスト観点に不変条件を強制する Architecture テストがありません。AGENTS.md の「不変条件は Architecture/Feature テストへの登録まで含む」に未達です。

修正提案: Owner 数を変更・消滅させ得る全経路を inventory 化し、共通ロック規約への準拠を Architecture テストで強制してください。

3. 実現可能性

[Critical] `organization_user` 行の `lockForUpdate()` だけでは完全直列化できません。存在しない行や、判定後に追加されるメンバー行はロックできないため、同時 INSERT や新規所属による phantom を防げません。また、対象組織の列挙自体がロック前なら、同時に Owner 所属となった組織を見落とします。

修正提案: 組織行をロックの基点にし、Owner・membership を変更する全経路で「対象 `organizations` 行を先に `lockForUpdate()`」という共通プロトコルを採用してください。複数組織は ID 昇順でロックし、デッドロックを抑制します。`transferOwnership`、`changeRole`、`removeMember`、メンバー追加経路も同じ規約に統一する必要があります。

4. 期待効果の妥当性

[Warning] 現在の pivot 行ロック方式のままでは「新規 Owner 不在組織の発生を防止」は保証できません。

修正提案: 組織行を共有ロック境界としたうえで、自己削除と membership 追加・削除・ロール変更の競合テストを追加してください。

5. リスク

[Warning] `AccountDeleted` をトランザクション内の削除前に発行すると、その後のロールバックでも外部リスナーが削除済みとして処理する可能性があります。

修正提案: DB 内の監査レコードなら同一トランザクションで保存し、外部副作用を持つイベントならコミット後に dispatch してください。

[Warning] 成功時のログアウトとセッション無効化が設計から消えています。

修正提案: `deleteAccount()` 成功後に Controller でログアウト、セッション invalidate、CSRF token regenerate を行う順序を明記してください。

6. スコープの適切さ

[Suggestion] `changeRole` / `removeMember` まで直列化規約を統一する拡張は、不変条件を実効化するために必要であり、過剰ではありません。ただしメンバー追加・Owner 付与を含む全書き込み経路の棚卸しが必要です。

7. 型安全性

[Warning] `ValidationException(errors.account)` という表現は曖昧です。Laravelの validation error key は通常 `account` であり、Inertia 側で `errors.account` として公開されます。

修正提案: PHP側は `ValidationException::withMessages(['account' => '...'])`、Svelte側は `errors.account` と明記してください。props の `list<array{name:string,slug:string}>` は PHPStan level 10 に適合する方針です。