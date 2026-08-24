現状は **CHANGES_REQUESTED** です。Round 1 の主要な件数不一致は解消されていますが、まだ1件、設計上の最優先条件に関わる [Critical] が残っています。

## `app/Services/Billing/Retention/TicketLedgerCarryForwardService.php`

- [Critical] `expires_at > now` → `>= now` は等価変異ではありません。既に文書化されている非ロック insert の窓では挙動が変わります。

  次の順序が成立します。

  1. `expiredScope()` の DELETE が完了
  2. 組織行ロックを取らない経路が、`created_at <= threshold` かつ `expires_at = now` の行を追加
  3. `contributingGroups()` が実行される

  正しい `> now` なら、その行は今回の集約から除外され、`expiredRemaining` に残ります。`>= now` なら、その行は集約・削除されます。したがって両者は実際の許容並行モデル上で非等価です。

  これは「真の並行実行の排他をテストせよ」という射程拡大ではありません。既存の N10 と同じ `DB::listen` 差し込み方式で、「失効DELETE後・集約SELECT前」に境界行を入れれば、別 connection や barrier なしで分岐を固定できます。思考原則2と両立します。

- [Warning] 新しく宣言した恒等式も、非ロック insert がある以上、`unexpectedFailures === 0` だけでは成立しません。

  例えば初期 `candidates` 計測後に新しい集約キーの期限超過明細が追加されると、例外なしでその行が `processed` または `expiredRemaining` に入り得ます。

  次のどちらかが必要です。

  - 恒等式を「決着対象が実行中に変化しない場合」に限定する
  - 本当に常時保証するなら、候補集合を固定できる直列化へ設計変更する

  後者は現在の方針を大きく変えるため、今回なら前者が妥当です。「恒等式が崩れたら述語ずれ」と断定する runbook の記述も、並行追加の可能性を併記してください。

- Round 1 の `processed` 集合不一致は解消済みです。`rowCount - carryForwardRows` と、削除件数照合用の `$deleted` を分離した修正は妥当です。

## `tests/Feature/Billing/TicketLedgerCarryForwardTest.php`

- [Critical] N1b は静止した既存行しか扱わないため、上記の `>` → `>=` 変異を検出できません。「削除後・集約前に `expires_at = now` の行を差し込む」テストを追加してください。

- [Warning] `candidates = processed + expiredRemaining` のテストは、時計だけでなく決着対象集合も静止しているケースの恒等式です。その前提をテスト名またはコメントへ明記する必要があります。

## `devnotes/20260824-1019-ticket-ledger-carry-forward-v1/mutation-evidence.md`

- [Critical] `>` → `>=` を「等価変異」とした結論は訂正が必要です。削除と集約が別SQL文であり、その間に insert できることをサービス自身が認めているためです。

  「現在の静止 fixture では観測できなかった」が正確な記述です。

## `app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php`

- [Warning] 恒等式に「決着対象集合が実行中に変化しない場合」という前提が必要です。`unexpectedFailures === 0` だけでは十分ではありません。

  `processed` の新しい定義自体は妥当です。

## `docs/billing-retention-runbook.md`

- [Warning] 「恒等式が崩れていたら決着対象の定義と実処理がずれている合図」という記述は断定が強すぎます。非ロック insert による母集団変化でも崩れます。

- [Warning] v0 行について「どの環境にも存在しえない」は、migration 日付だけからは断定できません。古い `created_at` を持つ投入・復元・手動操作は理論上可能です。

  ただし、通常の本番経路ではv0行が生成されないという反論は合理的であり、Round 1 の「必ずデータ移行が必要」という [Critical] は撤回します。データ移行を追加する必要はありません。「通常のアプリ経路では生成されない」「手動投入等は保証外」と書くのが正確です。

## `database/migrations/2026_08_24_100000_drop_carried_forward_through_from_ticket_ledger_entries.php`

- [Warning] 同じく「どの環境にも存在しえず」は「通常のアプリ経路では存在しない」へ狭めてください。

- [Suggestion] v0行への対応説明が migration と runbook にかなり重複しています。migration は前提と判断の要約だけにして、限界・監視・自己修復の詳細は runbook を正本にすると、文書関係が明瞭です。

## `tests/Support/Architecture/TicketLedgerMutationScanner.php`

- [Warning] FQCNと短名を分離したことは改善ですが、gate は依然として両者の和を同じ候補判定に使い、短名のみの候補を失敗させません。

  登録済みファイル内で、本物の台帳モデル参照を同名の別クラスへ置き換えても、変更語彙数が同じなら exact-fit を通る可能性があります。TLM-7 の「全体でFQCN参照が1件以上」ではファイル単位の誤認を防げません。

  規約 (b) に沿う最小修正は、変更語彙を持つ `shortName=true / fqcn=false` のファイルを「曖昧な参照」として gate で失敗させることです。型宣言等も保証対象にしたい場合だけ、後から名前解決を拡張すれば足ります。

- [Warning] closure 判定で `T_STATIC` を単独で受理しています。`static function` / `static fn` であることまでは確認していないため、`DB::transaction(static $callback, ...)` のような形も第1引数closureとして通ります。`T_STATIC` の直後が `T_FUNCTION` または `T_FN` であることを確認してください。

## `tests/Architecture/TicketLedgerMutationSiteGateTest.php`

- [Warning] docblock は「負例7変異」と書いたままですが、テストは8変異です。
- [Warning] 「変更操作がすべて同一 transaction closure 内」という主張と、「後続引数も内側として数える」という走査器の限界がまだ一致していません。「transaction の引数範囲内」までに狭める必要があります。

## `tests/Unit/Architecture/TicketLedgerMutationScannerTest.php`

- [Warning] 次の負例が必要です。

  - `shortName=true / fqcn=false` かつ変更語彙ありを、gate が曖昧として失敗させる
  - `T_STATIC` の後ろが `function` / `fn` でない第1引数を拒否する

## その他のファイル

以下は今回の対応で問題ありません。

- `CarryForwardGroup.php` と単体テスト
- `BillingRetentionTarget.php`
- `TicketLedgerEntry.php`
- `TicketLedgerEntryPurger.php`
- `BillingRetentionPurgerRegistry.php`
- `TicketLedgerReaderInventoryTest.php`
- nullable state registry／件数pin
- `docs/architecture.md`
- `AGENTS.md`
- `withTrashed()` と DirectFetchInventory 非登録の判断

`composer test` と PHPStan の結果も確認材料として十分です。ただし、リポジトリ規約上の全検証完了を名乗るには、未提示の pnpm 系コマンドと、既存由来であっても赤のままの `pint --test` の扱いを最終報告で明示する必要があります。

## 全体判定

**CHANGES_REQUESTED**

残る [Critical] は、`>` → `>=` を等価変異として扱っている点です。これは将来構文まで網羅する要求ではなく、サービスが既に認めている「削除と集約の間の insert 窓」に対する既存ロジックの検証なので、思考原則2や「保証範囲外を主張しない」と矛盾しません。