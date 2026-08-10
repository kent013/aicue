レビュー仮説: `unexpected_failures > 0` がある時点で `remaining` は完全な観測値ではないため、horizon は OK/NG 判定より先に「判定不能」へ倒すべき、という前提で見ています。

**app/Console/Commands/Billing/PurgeBillingRetentionCommand.php**

[Critical] なし

[Warning] なし

[Suggestion] `horizon: 判定不能 (集計に失敗した target...)` は dry-run の集計失敗には正確ですが、`--apply` 側の失敗は「決着処理に失敗」も含みます。運用文言としては `処理または集計に失敗した target` / `観測できなかった target` の方がより正確です。ただし、現在の修正目的である「OK と読ませない」は満たしています。

判定不能を NG より優先する実装は妥当です。NG は「観測できた結果として残存あり」、判定不能は「観測自体が欠けている」なので、今回の運用リスクでは判定不能優先が正しいです。

**tests/Feature/Billing/BillingRetentionPurgeTest.php**

[Critical] なし

[Warning] なし

[Suggestion] 空振り検知は十分です。失敗時に OK が出ないこと、失敗 + 実在残存でも NG に落とさないこと、失敗なしでは従来 OK が出ることを押さえています。`--apply` テストもあるため、dry-run 限定の修正になっていない点も確認できます。

**docs/architecture.md**

[Critical] なし

[Warning] なし

[Suggestion] 実装と一致しています。`unexpected_failures` がある target の件数は信用できない、という制約を誇張せず書けています。

**docs/billing-retention-runbook.md**

[Critical] なし

[Warning] なし

[Suggestion] `remaining` 行は、単独で読むとまだ「決着後に残った期限超過の件数」と読めます。直後の horizon 説明で補正されていますが、より堅くするなら「`unexpected_failures=0` のときに信用できる残存件数」と明記すると運用者の読み間違いをさらに減らせます。

**全体判定: APPROVED**

この diff は欠陥の焦点に対して十分に狭く、実装・テスト・runbook の向きも揃っています。PHPStan/DTO パターン上の問題も、提示 diff からは見当たりません。