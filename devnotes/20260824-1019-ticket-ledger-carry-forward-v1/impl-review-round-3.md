残る [Critical] はありません。主要な正確性問題は解消されています。ただし、静的 gate の必須規約に関する [Warning] が残るため、現時点では **CHANGES_REQUESTED** です。

## `tests/Architecture/TicketLedgerMutationSiteGateTest.php`

- [Warning] 新設した TLM-2b の判定分岐に、gate側の負例がありません。

  `TicketLedgerMutationScannerTest` は `fqcn=false / shortName=true` を抽出できることまでは確認していますが、その結果と変更語彙を組み合わせたとき、TLM-2b が実際に失敗することは固定していません。実コードの母集団が現在たまたま空なので、TLM-2b の条件を反転・削除してもテストが緑になる可能性があります。

  これは AGENTS.md 共通規約 (c) と「新設・変更時の4点」の直接の対象です。例えば判定を小さな純関数へ切り出し、次を固定すれば十分です。

  - `shortName=true / fqcn=false / mutations=1` → 違反
  - `shortName=true / fqcn=true / mutations=1` → 適合
  - `shortName=true / fqcn=false / mutations=0` → 対象外

  将来構文への対応ではなく、今回追加した判定分岐の負例なので、思考原則2とも矛盾しません。

- [Warning] docblock は「負例8変異」のままですが、実テストは9変異です。

- [Warning] TLM-5正例のテスト名がまだ「transaction closure の内側」と主張しています。実際の保証は docblockどおり「`DB::transaction()` の引数範囲」です。テスト名も同じ範囲へ狭めてください。

## `tests/Support/Architecture/TicketLedgerMutationScanner.php`

- [Warning] TLM-5の内部メッセージとコメントに「closure の内側」が残っています。

  実際には第2引数を含む transaction 引数全体を数えるため、次の文言は保証より強いです。

  - 「closure の内側にロックがある」
  - 「closure 内の最初の変更操作」
  - 「closure の外側に変更操作がない」

  `transaction 引数範囲` に統一してください。保証範囲を狭めた判断自体は妥当ですが、利用側の名前・エラーメッセージも含めて狭めることが AGENTS.md (b) の要件です。

- `startsClosure()` の修正は妥当です。`static` 単独の負例と `static function` / `static fn` の正方向も固定されています。

## `tests/Support/Architecture/TicketLedgerMutationInventory.php`

- [Warning] `APPEND_CALL` のコメントが「closure の内側」となっています。実際のTLM-5の保証に合わせて「transaction 引数範囲の内側」へ修正してください。

## `app/Services/Billing/Retention/TicketLedgerCarryForwardService.php`

- [Warning] docblock末尾の次の主張は、TLM-5を狭めた結果と矛盾しています。

  > 「既存経路と同じ組織行ロックを取ること」を静的に pin する

  TLM-5は `lockForUpdate()` の受け手が `Organization` であることを検査しないため、静的にpinできるのは「ロック語彙が最初の変更語彙より前にある」ことまでです。AGENTS.mdとscanner側は正しく狭められているので、サービスdocblockも追随が必要です。

- [Warning] 「どちらの枝にも入らない行が無い」は、同一時点の述語については正しいものの、N1cでは実行中に追加された行が今回の両処理から外れることを意図的に確認しています。「同じスナップショット上の述語は厳密な補集合」と限定すると正確です。

  実装そのものとN1cの追加は妥当です。

## `tests/Feature/Billing/TicketLedgerCarryForwardTest.php`

- N1cは適切です。`>` → `>=` の非等価性を、既存のN10と同じ差し込み方式で固定できています。
- [Suggestion] N1cで `candidates=1 / processed=1 / expiredRemaining=1` も固定すると、境界行が「手つかずで残る」だけでなく、監視値へ現れることまで明確になります。現状の行状態assertだけでも変異検出力はあるため必須ではありません。

## `devnotes/20260824-1019-ticket-ledger-carry-forward-v1/mutation-evidence.md`

- 境界変異の訂正内容は妥当です。
- [Suggestion] 「結果（7変異）」と末尾の「9形」が混在しています。前者を「基本7変異」、後者を「境界2変異を含む全9変異」とすると読み違いがありません。

## migration / v0行

Round 1の [Critical] は撤回します。

通常のアプリ経路、保証外となる手動投入・復元、自己修復まで分けて記述されており、存在しない可能性が高いデータの移行を先回りして作らない判断は思考原則2と両立しています。

## その他

以下は承認可能です。

- `processed` と削除件数照合の分離
- DTOの恒等式に静止集合の前提を追加したこと
- `expires_at` の両境界テスト
- int4のキャスト前検査
- `withTrashed()` とDirectFetchInventory非登録
- DTO / Reader inventory / nullable state対応
- runbookとarchitectureの正本関係

`pnpm test` / `pnpm test:packages` はまだ実行中なので、最終完了報告は結果確定後にしてください。Pintの既存failは、mainでも再現し本PR非変更であることが記録されるなら、本件固有の実装不備とは判定しません。

## 全体判定

**CHANGES_REQUESTED**

残る修正は静的gateの規約適合と文言整合です。TLM-2bの負例追加後、TLM-5の保証表現を「transaction引数範囲」へ統一できれば、コード上の重大な阻害要因はありません。