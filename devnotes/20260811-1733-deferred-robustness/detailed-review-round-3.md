## 全体判定: CHANGES_REQUESTED

actor を同一性条件に入れない判断は妥当です。org 単位の操作であり、両ユーザーが `manageBilling` を持つこと、既存の attribution を書き換えないこと、現行の成功時挙動を維持することが明確です。テスト4と M-2c による契約固定も適切です。

残る修正必須事項は2件です。

## 施策 1a: APPROVE

自然キー判定は fail-closed として十分です。

- 自然キー行がない場合は再送出
- session ID が異なる場合は再送出
- idempotency key が異なる場合は再送出
- actor が異なる場合は、明示した契約に従って replay
- checkout URL は同一性に含めない

actor 非検証によって後続ユーザーが先行ユーザーの Checkout URL を受け取りますが、双方が同一 organization の `manageBilling` 保持者であり、操作対象も organization の Stripe customer です。この前提が維持される限り、権限昇格やテナント越境にはなりません。

テスト4では、単に別 User を作るだけでなく、そのユーザーへ対象 organization に対する `manageBilling` 権限を付与し、可能なら Controller 経由で両者の認可成功も固定してください。Service 直接呼び出しだけでも同一性契約は検査できますが、「両者とも認可済み」という設計根拠までは検査できません。これは Suggestion です。

## 施策 1b: REQUEST_CHANGES

### [Warning] ULID同時違反時に「必ず再送出される」とは言えない

次の記述は E-7 と矛盾します。

> ULID 衝突は数学的に不可能ではないが、その場合 pg は `attempt_ulid_unique` 側を報告して再送出される

複数 unique が同時に違反した場合、PostgreSQL が報告するのは1本だけで、その選択を意味論として保証できないことが E-7 の結論です。現在のOID順では `attempt_ulid_unique` が先でも、再作成順が変われば `tar_attempts_org_pending_unique` が報告され、ULID衝突を並行 pending race として握る可能性があります。

修正案:

- 選択規則の括弧書きから「その場合も報告制約が期待名と一致せず再送出」を削除する
- 施策一覧の1b行も同様に修正する
- 「保証しないもの」§3を次の趣旨に直す

> 通常のアプリ生成経路では別制約との同時違反を構成しないことを前提とする。ULID衝突やsequence drift等で複数制約が同時違反した場合、報告制約が pending unique になると別異常を no-op として握る可能性までは排除しない。

これは確率的に極めて小さい残留リスクであり、自然キー再照合や新gateを追加する必要まではありません。ただし「常に安全側」という保証はできません。

## 施策 1c（撤回）: APPROVE

撤回理由、残留リスク、スコープ外の扱いはいずれも妥当です。

## 施策 2: APPROVE

実装方針、enum cast、`forceFill`、R-2、M-6に問題はありません。

## 施策 3: REQUEST_CHANGES

### [Warning] mutation基準用コミットが検証前に置かれている

実装順序では、手順6で実装をコミットし、手順10で初めて次を実行します。

```bash
composer fix && composer phpstan && composer test
```

これは AGENTS.md の「全 green でコミット」と整合しません。また `composer fix` がコミット後に差分を生成する可能性があります。

修正案は、コミット前に少なくとも次を完了することです。

```bash
composer fix
composer phpstan
composer test
```

その後に基準コミットを作り、mutation/probeを実施し、復帰後に同じ検証を再実行してください。

順序は以下が適切です。

1. fail-first
2. 実装・文書変更
3. `composer fix && composer phpstan && composer test`
4. 基準コミット
5. mutation / probe
6. HEADとの差分が空であることを確認
7. 最終 `composer phpstan && composer test`

コミットをmutation基準として使う考え方自体は妥当です。

## DESIGN / Atomic Design

該当なし。DTO、JsonResource、Inertia Props、TypeScriptへの波及もありません。

上記2点、特に施策1bの「常に安全側」という過大な保証を修正すれば、全体を APPROVED にできます。