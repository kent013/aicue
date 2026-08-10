# T146 実装レビュー 対応マトリクス (Round 1)

Codex 全体判定: **APPROVED** ([Critical] 0 件 / [Warning] 0 件 / [Suggestion] 4 件)

| # | 対象 | 指摘 (Suggestion) | 判断 | 根拠 |
|---|---|---|---|---|
| 1 | `PurgeBillingRetentionCommand.php` | 「集計に失敗した target」は dry-run には正確だが、`--apply` 側の失敗は「決着処理の失敗」も含む。「処理または集計に失敗した」の方が正確 | **対応する** | 本 TODO は「人間が読む horizon 行が事実と違う」ことの是正そのもの。文言の不正確さは同じ欠陥の系統なので直す。文言を `処理または集計に失敗した target が N 件` へ変更し、テスト 3 本の期待文字列も追随させた |
| 2 | 同上 | 判定不能を NG より優先する実装は妥当 (指摘ではなく確認) | 変更なし | 実装の意図どおり。優先順位は「失敗と実在の残存が同時にあっても判定不能と報告する」テストが固定している |
| 3 | `BillingRetentionPurgeTest.php` | 空振り検知は十分 (失敗時に OK が出ない / 失敗+残存で NG に落ちない / 失敗なしで OK が出る) | 変更なし | 追加要求なし |
| 4 | `docs/billing-retention-runbook.md` | `remaining` の行は単独で読むと「決着後に残った件数」と読める。`unexpected_failures=0` のときに信用できる旨を明記すると読み間違いが減る | **対応する** | runbook は運用者が唯一読む契約。1 行で塞げるので反映した (`unexpected_failures=0` のときだけ信用できる、と明記) |

## 対応後の再検証

- `composer test -- tests/Feature/Billing` = 773 passed / 0 failed
- `composer phpstan` = No errors / `vendor/bin/pint --test` = passed

## 見送った拡張 (思考原則 2)

- horizon の 3 値を DTO / enum へ昇格させる案は取らなかった。判定はこのコマンド 1 箇所でしか
  行われず、機械が読む値は既に終了コード (`unexpected_failures > 0` で FAILURE) が担っている。
  今必要なのは「人間向けの 1 行が嘘をつかないこと」だけである。
