全体判定は **CHANGES_REQUESTED** です。提示テキストのみを対象にレビューしました（コマンド実行・追加ファイル読み込みは行っていません）。

## ファイル別判定

### `app/Services/Billing/Retention/TicketLedgerCarryForwardService.php`

- [Critical] `settlementPredicate()` と実際に削除される集合が一致していません。

  例えば、同じ集約キーに「寄与中の既存 carry-forward 1 行」と「期限超過の通常明細 1 行」がある場合:

  - `candidates` は通常明細だけを数えて `1`
  - `groupScope()` は両方を削除して `processed = 2`

  となります。設計中の `processed >= candidates` はこの不一致を意図的に受け入れていますが、レビュー要件の「処理されるのに数えられていない行を作らない」と両立しません。残高自体は保存されても、監視値の母集団が壊れています。

  既存 carry-forward を集計へ含めること自体は必要なので、例えば集計結果に「決着対象行数」を別途持ち、件数照合には全削除行数、`processed` には決着対象行数を使うなど、意味を分離する必要があります。

- [Warning] `expires_at = $now` の境界について、現在の実装は正しく次の厳密な補集合になっています。

  - 削除: `expires_at IS NOT NULL AND expires_at <= now`
  - 寄与: `expires_at IS NULL OR expires_at > now`

  また、`settlementScope()` の nested closure により `created_at <= threshold AND (...)` の括弧も正しく閉じています。ただし、この比較演算子そのものを固定するテストがありません。

- [Suggestion] `selectRaw()` の binding は問題ありません。Laravel は binding を句別に保持し、SQL上の `SELECT`、`WHERE` の順で結合するため、`kind = ?` の binding が `$now` より前に配置されます。

- [Suggestion] トランザクション内の順序、削除件数照合、組織単位のロールバックは妥当です。

### `database/migrations/2026_08_24_100000_drop_carried_forward_through_from_ticket_ledger_entries.php`

- [Critical] v0 が既に生成した carry-forward 行の移行がありません。

  v0 行には以下の状態が残っています。

  - `created_at` は畳み込み実行時刻で、保持閾値より新しい
  - `idempotency_key` は非 NULL
  - description は旧固定文言
  - 集約範囲は `carried_forward_through` にしか残っていない

  このまま列を drop すると、既存 v0 行は最長で約7年間 `contributingGroups()` に入らず、新しい v1 繰越行と同じ集約キーで並存します。「集約キーごとに1行へ収束」「繰越行は `idempotency_key = NULL`」という新しい不変条件が、アップグレード済み環境では成立しません。しかも drop 後は移行に利用できる `carried_forward_through` を失います。

  既存 v0 行をどう正規化するかを drop 前に決め、実データ移行とアップグレード回帰テストを追加する必要があります。

- [Warning] migration が参照する runbook の新節が差分にありません。破壊的な順序制約の唯一の運用担保が未実装です。

### `app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php`

- [Critical] `candidates` を「決着対象」、`processed` を「削除した行数」と定義した結果、上記の carry-forward 再集約時に同じ母集団を表しません。DTOの件数契約として明文化するほど、この不一致が固定されてしまいます。

### `app/DataTransferObjects/Billing/CarryForwardGroup.php`

- 指摘なしです。

  `withinLimit()` は、許容する文法の範囲では正しく動作します。符号、先頭ゼロ、`-0`、正負で異なる限界値、桁数、同桁の辞書順比較に穴は見当たりません。`int` キャスト前の範囲検査も成立しています。

### `tests/Feature/Billing/TicketLedgerCarryForwardTest.php`

- [Critical] v0 carry-forward 行を残した状態で migration・v1処理を行うアップグレードテストがありません。新規生成された v1 行だけを検査しているため、実データ移行の欠落が偽グリーンになります。

- [Warning] `processed >= candidates` の assertion が、サービス側の集合不一致を正しい契約として固定しています。

- [Warning] `expires_at` が `$now` と完全一致するケースがありません。少なくとも次を変異として赤にする必要があります。

  - 削除側の `<=` を `<` にする
  - 寄与側の `>` を `>=` にする

  現在の N1 と N18 は、いずれも失効時刻を現在より明確に過去へ置くため、境界演算子の変異を検出しません。

- [Suggestion] N10 は真の別 connection 並行実行ではありませんが、件数不一致からトランザクション全体を戻す分岐の検証としては有効です。保証範囲の記載とも整合しています。

### `tests/Support/Architecture/TicketLedgerMutationScanner.php`

- [Warning] AGENTS.md 規約 (a) に反し、別クラスの短名 `TicketLedgerEntry` も「台帳モデル参照」として解決済み結果へ混ぜています。拾いすぎであっても「完全修飾名で突き合わせる」という規約には適合しません。未解決・過剰一致を正しいFQCN参照と同じ `bool` に潰さず、別結果として gate を失敗させるべきです。

- [Warning] TLM-5 が主張する検出力を実装できていません。

  - `parenRange()` が取っているのは transaction callback の本体ではなく、`DB::transaction(...)` の引数全体
  - `lockForUpdate()` の受け手が `Organization` か確認していない
  - 2件の `delete()` がチケット台帳を対象にしているか確認していない

  そのため、別の transaction 引数や別モデルへの `lockForUpdate()`・`delete()` でも条件を満たせます。「同一 callback 内で組織行を先にロックし、台帳を変更する」とはまだ証明できません。

- [Warning] `literalValue()` は引用符を除くだけで、PHP文字列リテラルのエスケープを評価していません。「リテラルの値の完全一致」という説明より検出範囲が狭いため、docblockで保証範囲を狭めるか解析を合わせる必要があります。

### `tests/Architecture/TicketLedgerMutationSiteGateTest.php`

- [Warning] 「グローバル関数を1つも宣言しない」と記述しながら、実際には次のグローバル関数を宣言しています。

  - `ticketLedgerMutationScan()`
  - `ticketLedgerMutationExpected()`
  - `ticketLedgerCarryForwardSource()`
  - `ticketLedgerLockOrderViolations()`

  現時点で名前衝突していなくても、設計とコメントの明白な不一致です。support classへ移してください。

- [Warning] TLM-5 の正例・7負例は、上記の「transaction の別引数」「別モデルのロック」「無関係な delete による置換」を検査していないため、主張する検出力の裏取りとして不足しています。

### `tests/Unit/Architecture/TicketLedgerMutationScannerTest.php`

- [Warning] FQCN規約に対して「同名の別クラスも ledger として扱う」を正例化しており、AGENTS.md (a) と逆方向です。
- [Warning] TLM-5 の未検出形を固定する負例が不足しています。

### `tests/Support/Architecture/TicketLedgerMutationInventory.php`

- [Suggestion] 現在の実装件数との exact-fit は妥当に見えます。ただし scanner の検出意味を修正した後、再実測が必要です。

### `app/Enums/Billing/BillingRetentionTarget.php`

- [Warning] 「決着は物理削除ではなく畳み込み」は、失効済み行を物理削除するv1実装と食い違います。「単純削除ではなく二段判定」等へ直す必要があります。

### `app/Models/Billing/TicketLedgerEntry.php`

- [Warning] 同様に「保持期間の決着は物理削除ではなく畳み込み」という冒頭が、その直後の失効済み物理削除と矛盾しています。

### `tests/Architecture/TicketLedgerReaderInventoryTest.php`

- 指摘なしです。移設とDTOの走査域追加は設計どおりです。

### `tests/Unit/Billing/CarryForwardGroupTest.php`

- 指摘なしです。キャスト前の範囲検査、型拒否、集約不変条件を十分に固定しています。

### `app/Services/Billing/Retention/TicketLedgerEntryPurger.php`

- 指摘なしです。

### `tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php`

- 指摘なしです。

### `tests/Support/InitialState/NullableStateColumnRegistry.php`

- 指摘なしです。

### 差分に存在しない必須ファイル

- [Warning] 施策9の `AGENTS.md`、`docs/architecture.md`、`docs/billing-retention-runbook.md` が未変更です。
- [Warning] 施策10の `devnotes/20260824-1019-ticket-ledger-carry-forward-v1/mutation-evidence.md` が差分にありません。本文中の表だけではリポジトリ内の実測証跡になりません。
- [Warning] `composer test` が実行中で、残りの規定検証コマンドの完了結果も提示されていません。現時点では「全 green で完了」の条件を満たしません。

## セキュリティ判定

`withTrashed()` の2箇所は、外部入力のIDではなく列挙済み `Organization` モデルの主キーを使用しており、テナント越境経路にはなっていません。`DirectFetchInventory` に登録しない判断は妥当です。

## 全体判定

**CHANGES_REQUESTED**

特に、既存v0繰越行のデータ移行欠落と、`candidates`／実削除集合の不一致は修正必須です。