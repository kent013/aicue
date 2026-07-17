全体判定: **CHANGES_REQUESTED**

- `app/Services/Billing/StripeWebhookProcessor.php` — **REQUEST_CHANGES**
- `tests/Feature/Billing/SignupGrantOncePerOrgTest.php` — **REQUEST_CHANGES**

[Warning] 修正コードでは `claimSignupGrantMarker()` と `grantSignupGrant()` が同一トランザクション内であること、および org 行を `lockForUpdate()` していることを確認できません。これは `claimSignupGrantMarker()` 自身の契約にも反します。付与処理が失敗した場合、marker だけコミットされて再試行でも付与されない可能性があります。

修正案:

- webhook 経路を org 行ロックを含む単一 transaction に閉じる。
- marker claim 後の `grantSignupGrant()` が失敗した場合、marker も rollback されるテストを追加する。
- 既存の webhook 冪等マシンが既にこの transaction とロックを保証するなら、その実装箇所を示せれば本指摘は解消です。

成功時の整合性と `granted=false` への期待更新は正しく、Round 1 の問題自体は解消されています。残る確認点は失敗時の原子性だけです。