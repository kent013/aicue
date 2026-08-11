提供テキストのみでレビューしました。コマンド実行・ファイル書き込み・追加ファイル読み込みはしていません。

**全体判定**
CHANGES_REQUESTED

主因は 2 点です。`billing_checkout_sessions` の replay は同時に複数の unique 制約へ当たり得るため、「PostgreSQL が報告した制約名 1 本だけ」で握る設計は正規 replay を壊す可能性があります。また施策 1c は、提示された現行コードを見る限り「修正前に赤くなる」という前提が成立していません。

**施策 1a**
判定: REQUEST_CHANGES

[Critical] 同一 `attempt_token` replay は 1 本の unique 制約だけに当たらない  
`billing_checkout_sessions` には少なくとも `org_intent_attempt_unique` / `idempotency_key_unique` / `stripe_session_id_unique` があり、同じ token で再実行すると 3 本が同時に衝突し得ます。PostgreSQL がどの unique index 名を例外に載せるかは、アプリの意味論として依存してよい契約ではありません。

そのため、変更後コードの

```php
if ($e->index !== self::CHECKOUT_ATTEMPT_TOKEN_UNIQUE) {
    throw $e;
}
```

は、正規 replay でも `idempotency_key_unique` や `stripe_session_id_unique` が報告された場合に fail-closed してしまいます。

修正案: 制約名だけで握るのではなく、例外後に `organization_id + intent + attempt_token` で既存行を再読込し、既存行の `stripe_session_id` / `idempotency_key` / `checkout_url` が今回の `$result` / `$idempotencyKey` と一致する場合だけ replay として握る。既存行が無い、または値が一致しない場合は再送出する。

[Warning] M-1 の mutation は「const が load-bearing」を十分に証明しない  
正規 replay が複数制約に当たるため、const を壊した時に replay テストが赤くなっても、それは「期待制約が守っている」証明ではなく「たまたま報告制約名に依存している」証明に留まります。

修正案: mutation は「replay 判定の既存行照合を外すと stripe_session_id-only テストが赤くなる」「既存行照合を過剰に厳しくすると正規 replay が赤くなる」のように、意味論側の非対称を確認する形へ変える。

**施策 1b**
判定: APPROVE

この施策は、期待する部分 unique `tar_attempts_org_pending_unique` と、別制約 `attempt_ulid_unique` を分けて観測できており、設計意図に合っています。`catch (UniqueConstraintViolationException)` へ狭め、`$e->index === self::ATTEMPT_ORG_PENDING_UNIQUE` の時だけ `null` に収束する方針も妥当です。

[Suggestion] テスト名またはコメントに「このテストでは pending 検査後の race を model event で作る」と明記しておくと、後続レビューで意図が読み取りやすいです。現行案のコメントで概ね足りています。

**施策 1c**
判定: REQUEST_CHANGES

[Critical] 「修正前に赤くなる」前提が、提示された現行コードと矛盾しています  
設計本文では「`catch` が `UniqueConstraintViolationException` なので `isUniqueViolation()` は常に true」としていますが、提示された現行コードの `isUniqueViolation(QueryException $e)` は SQLSTATE だけでなく message も見ています。

```php
return str_contains($message, 'billing_checkout_sessions_org_intent_attempt_unique')
    || (str_contains($message, 'billing_checkout_sessions.organization_id')
        && str_contains($message, 'attempt_token'));
```

このため、R-1c の `stripe_session_id` だけを衝突させるテストは、現行実装でも `UniqueConstraintViolationException` として再送出される可能性が高く、「修正前は `StaleCheckoutAttemptException` で赤」という説明が成立しません。

修正案: まず R-1c を現行実装に当てた時の期待を設計し直してください。現行で既に緑なら、この施策は bug fix ではなく「Laravel 13 の `$e->index` を使う簡素化」です。その場合、fail-first テストではなく既存挙動固定 + mutation 確認として扱うべきです。

[Critical] 1a と同じく、正規 replay が複数 unique 制約に当たり得る  
`SubscriptionService::startCheckout()` も同じ `billing_checkout_sessions` に insert するため、同一 `attempt_token` replay では `org_intent_attempt_unique` 以外が報告される可能性があります。`$e->index` 1 本だけで replay 分岐へ入れる設計は脆いです。

修正案: 例外後に `subscriptionAttemptQuery($org)->where('attempt_token', $attemptToken)` を再読込し、既存行が今回の `$created->sessionId` / `idempotency_key` / `plan_code` / intent と整合する場合だけ `replayCheckout` または stale 判定へ進める。既存行が無い、または値が不整合なら `UniqueConstraintViolationException` を再送出する。

**施策 2**
判定: APPROVE

`status` を `$fillable` に入れず、保護状態列として `forceFill()` で初期代入する方針は既存規約と整合しています。enum cast へ enum インスタンスを渡す点も Laravel の enum cast と整合します。R-2 は DB default では検出できない in-memory 欠落を狙えており、再現テストとして適切です。

[Suggestion] `created` フックで捕まえるテストは意図がやや特殊なので、コメントに「Eloquent の created event で save() した同一インスタンスを捕捉している」と一言あると保守しやすいです。

**施策 3**
判定: APPROVE

文書化の範囲は適切です。新しい横断 gate / inventory を作らない判断も、今回のスコープと申し送りに合っています。

[Warning] 「保証しないもの」の exclusion 制約の記述は正確性を直した方がよいです  
PostgreSQL の exclusion constraint violation は通常 `23P01` であり、Laravel の `PostgresConnection::isUniqueConstraintError()` が見る `23505` ではありません。したがって `UniqueConstraintViolationException` ではなく通常の `QueryException` として catch 外へ出る理解が正確です。

修正案: 「exclusion 制約は通常 `23P01` なので本 catch には入らず再送出される。`$e->index === null` の fail-closed は unique 違反だが Laravel が index を抽出できなかった場合の話」と書き分けてください。

**補足**
DTO / JsonResource / Inertia Props / TypeScript への波及なし、UI 変更なし、DESIGN.md / Atomic Design は該当なしで問題ありません。セキュリティ面では、tenant / actor キーを payload から取らず relation / `forceFill` で明示代入する方針は AGENTS.md の不変条件に沿っています。