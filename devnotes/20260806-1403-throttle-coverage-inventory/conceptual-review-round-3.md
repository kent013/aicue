全体判定: **CHANGES_REQUESTED**

Round 2 の Critical は解消されています。残件は binder の Redis 対応と、メールキーのハッシュ保証です。

### 1. 使命との整合性

[Suggestion] 顧客資産を守る基盤要件として適切です。

### 2. 禁止事項・セキュリティ不変条件

[Warning] キー規約テストは形式しか検証せず、「メールはハッシュ化する」という与件を保証していません。例えば `login:email:user@example.com` も正規表現を通過します。

修正提案: メールを扱う limiter について、キーに平文・正規化済み email が含まれず、期待する `EmailHash::compute()` の値が含まれることを scenario ごとに検証してください。

### 3. Laravel 12 での実現可能性

[Warning] binder の完全一致条件が `ThrottleRequests::class.':'.$limiter` に固定されており、方針Aの `ThrottleRequestsWithRedis` 対応と矛盾します。Redis版で焼かれた route cache は同じ limiter でも「別 limiter」と判定され、cached 起動が失敗します。

修正提案: 完全一致ではなく、entry を次の2要素に分解して意味的に比較してください。

- class 部が `is_a(..., ThrottleRequests::class, true)`
- parameter 部が期待する limiter 名と完全一致

これにより基底版・Redis版の双方で冪等 no-op が成立します。

### 4. 期待効果

[Suggestion] 付与漏れ、二重付与、credential 面、Unicode email の巻き添えをそれぞれ検出・是正でき、効果は妥当です。

### 5. リスク

[Suggestion] webhook の断定修正と監視項目追加により、Round 2 の懸念は解消されています。

なお「全分岐」という表現は、scenario が実行した分岐を意味します。未実行の分岐を自動発見する仕組みではないため、「inventory で宣言した全 scenario」と表現すると保証範囲が正確です。

### 6. スコープ

[Suggestion] 各除外判断は妥当です。`storage.local.upload` も防御前提を Feature テストで固定するため、exemption が事実依存の放置になっていません。

### 7. 型安全性

[Suggestion] token scanner、`?Closure` の検査、`array<int, Limit>` への絞り込みは PHPStan level 10 と両立可能です。

### 8. 目録検査

[Suggestion] token scannerによる全呼び出し分類、非リテラルの fail、inventory 集合一致、floor、stale 検出、exemption cap の組み合わせで、すり抜けと形骸化の双方を十分抑制しています。

上記2件を本文へ反映すれば、概念設計として承認可能です。