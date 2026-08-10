提供差分のみでレビューしました。コマンド実行・追加ファイル読み込みはしていません。

**指摘**

`app/Services/Billing/TicketLedgerCarryForwardService.php`

- [Warning] `carryForwardGroup()` が `sum()` / `max()` / `delete()` を同じ条件で再発行しており、削除対象 ID を固定していません。  
  `organizations` 行ロックを取る設計は `reserve` / `commit` と同じロック順序に乗る限り有効ですが、提示文でも触れている `grantMonthly` のようなロックを取らない insert 経路、または将来の backfill/import が `created_at <= threshold` の行を同一 group に挿入すると、`sum()` に含まれていない行を後段の `delete()` が巻き込む余地があります。発生するとチケット残高が減ります。  
  PR-C2 の最重要条件は「1 枚でも増減しない」なので、削除は集計時に確定した ID 集合に限定するか、台帳側の該当行を `lockForUpdate()` で固定する設計に寄せるのが安全です。

`app/Enums/Billing/TicketLedgerKind.php`

- [Warning] PHP enum に `CarryForward` を追加していますが、PR-C2 の波及変更にある TypeScript 型定義 / 台帳表示 UI / enum 同期テストの確認結果が差分上にありません。  
  `resources/js` 差分が無いこと自体は DESIGN/Atomic 観点では問題外ですが、C2b の「TicketLedgerKind に case を足すため TS 側の対応型と表示分岐を確認する」は PR-C2 スコープ内です。該当 UI/型が存在しないなら、その確認をテストまたは PR 説明に明記した方がよいです。

`database/factories/Billing/TicketLedgerEntryFactory.php`

- [Suggestion] `legacy()` のコメントが「purchased バケットへ畳まれる legacy 行」と読めます。実装・設計では `source IS NULL` は Purchased に寄せず独立 group として畳み込むため、コメントは「表示残高上は purchased に含まれるが、畳み込み group としては null のまま扱う」程度に直すと誤読が減ります。

**ファイル別判定**

- `PurgeBillingRetentionCommand.php`: OK。`--apply`、dry-run、horizon 出力、PII を出さない方針は PR-C2 と一致。
- `BillingRetentionTarget.php`: OK。C2 で pending を外す変更は妥当。
- `TicketLedgerKind.php`: Warning。TS 側確認の証跡不足。
- `TicketLedgerEntry.php`: OK。cast / factory 追加 / `carried_forward_through` は妥当。
- `BillingRetentionPurgerRegistry.php`: OK。7 purger 登録、子→親順維持。
- `TicketLedgerEntryPurger.php`: OK。薄い adapter として適切。
- `TicketLedgerCarryForwardService.php`: Warning。残高保存ロジックの主線は設計に合うが、集計対象と削除対象の固定が弱い。
- `TicketLedgerEntryFactory.php`: Suggestion。コメントのみ。
- migration: OK。nullable 追加で既存行影響は限定的。
- docs / runbook / routes: OK。C2c/C2d の日次 apply、C3 前提、failClosed の扱いは誇張なく書けています。
- Architecture / Feature tests: OK。mutation 記録込みで、group key・horizon・目録の空振り検知はかなり強いです。

**全体判定: CHANGES_REQUESTED**

主理由は `TicketLedgerCarryForwardService` の「集計した集合」と「削除する集合」が固定されていない点です。PR-C2 の最重要不変条件に直接触れるため、ここは塞いでから承認が妥当です。