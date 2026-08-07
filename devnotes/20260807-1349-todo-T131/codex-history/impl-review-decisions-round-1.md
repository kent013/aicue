# 対応マトリクス: impl-review Round 1

## [Critical] `writeProgress()` の mass update が `result_json` の cast を通らない

- 判断: **対応する**
- 根拠: 事実確認した結果、**指摘の半分は正しく、半分は誤り**だった。
  - 誤り: 「runtime で失敗しうる / 不正値が入りうる」— 本アプリの DB は pgsql で、
    `PostgresGrammar::prepareBindingsForUpdate()` が `is_array($value)` の値を
    `json_encode()` するため、実際には正しい JSON が入る
    (既存の成功パステスト `expect($job->result_json)->toHaveKey('steps')` が
    round-trip を behavioral に固定しており green)。
  - 正しい: `Illuminate\Database\Eloquent\Builder::update()` は
    `addUpdatedAtColumn()` **だけ**が cast を通す実装で、それ以外の列に
    モデルの cast (`castAttributeAsJson` / `getJsonCastFlags`) を適用しない。
    つまり `save()` 経路と**エンコード主体が違う**。将来 cast を
    `AsArrayObject` / 暗号化 cast などへ変えたり、driver が変わったりすると静かにずれる。
    「素の save() と同じ表現で書く」という意図がコードから読めないのも良くない。
- 対応内容: `writeProgress()` で `(new AnalysisJob)->forceFill($attributes)->getAttributes()` を
  経由し、**cast 済みの生値**を条件付き UPDATE に渡すようにした
  (Laravel 自身が `addUpdatedAtColumn()` で使っている手口と同じ)。
  条件付き UPDATE (`where status=running`) はそのまま維持。
  `RenderPipeline::updateProgress()` は書く 2 列が「cast 適用後と同一表現のスカラー」
  (enum の backing value と int) のみなので正規化は挟まず、**その理由と、
  配列 / 日時列を足すときは同じ処理を通すこと**を docblock に明記した
  (今必要のない churn を作らない = AGENTS.md 思考原則 2)。
- 再検証: `composer test -- tests/Feature/Projects/AnalysisPipelineTest.php` 40 passed /
  `composer phpstan` OK / mutation **M11 の赤化を再確認**。

## [Warning] `JobExclusionOrderingInvariantTest` が `queue.connections.database.retry_after` をハードコードしている

- 判断: **対応する (ただし提案された実装 (`config('queue.default')` を読む) は採らない)**
- 根拠: 指摘の懸念 (「既定接続が変わっても gate が green のまま別レーンと比較する」) は正しい。
  一方、提案どおり実行時の `config('queue.default')` を読むと**壊れる**:
  テストレーンは `phpunit.xml` が `QUEUE_CONNECTION=sync` を force しており、
  `sync` 接続は `retry_after` を持たないため gate 自体が error になる (実測で確認した)。
  「本番の既定接続」は `config/queue.php` の `env('QUEUE_CONNECTION', 'database')` の
  **フォールバック値**であって実行時の値ではない。
- 対応内容: 比較先は `database` のまま (定数 `JOB_EXCLUSION_DEFAULT_CONNECTION` に切り出し)、
  前提を固定するテストを 1 本追加した:
  `入口の排他: 比較先の前提 — 本番の既定キュー接続は database である`。
  `config/queue.php` のソースに `'default' => env('QUEUE_CONNECTION', 'database')` が
  あることを検査する (既存 `QueueWorkerLeaseInvariantTest` が「env 上書きを残すと
  gate が嘘をつく」として retry_after をリテラルで持たせているのと同じ発想)。
  加えて比較先が実在すること (`> 0`) も確認し degenerate PASS を防ぐ。
  なぜ実行時 `queue.default` を使えないかを関数の docblock に残した。
- 再検証: `composer test -- tests/Architecture/JobExclusionOrderingInvariantTest.php` 5 passed /
  mutation **M5 / M6 の赤化を再確認**。

## [Warning] `terminateInvoiceBestEffort()` が `$exception->getMessage()` を構造化ログへ入れている

- 判断: **反論する (現状維持)**
- 根拠:
  1. **PII は入らない**。この経路の例外は (a) `CashierAutoRechargeGateway::terminateInvoice()` の
     `Assert` (メッセージは invoice id と status のみ)、(b) Stripe SDK の
     `InvalidRequestException` (「No such invoice: in_xxx」等の API エラー文言) の 2 種で、
     顧客の email / name / カード情報を含まない。ログの PII 禁止契約は
     `LOG_EVENT` (抑止ログ) の 7 キー schema が担っており、そちらには一切入っていない
     (`JobOwnershipLostContextTest` が固定)。
  2. **`error` は運用契約上 load-bearing である**。`docs/architecture.md`
     §ジョブの重複実行と結果の一回性 で「恒久回収を持たない open invoice」の検知シグナルを
     **`event = job_ownership_lost_cleanup` かつ `terminated=false`** と定義し、
     その原因判別に `error` を使うと明記した。カテゴリだけに丸めると
     「なぜ void できなかったのか」が失われ、手動収束の手順が成立しなくなる。
  3. **同一クラスの既存実装と揃っている**。すぐ隣の `tryTerminateInvoice()` が
     以前から `'error' => $e->getMessage()` を出しており、片方だけ丸めると
     同じ事象の観測が 2 系統に割れる (後方互換の並走を残さない = 思考原則 3 に反する)。
  4. 詳細設計 (design-review Round 7 APPROVED) が cleanup ログの 7 キー schema を確定しており、
     `後始末ログは別 event 名 job_ownership_lost_cleanup を使い独自 schema を持つ` テストが
     キー集合を固定している。schema 変更は設計の再合議が要る規模である。
- 対応内容: 変更しない。Round 2 で上記を提示して合意を取る。

## [Warning] `docs/architecture.md` の S7 差分が見当たらない

- 判断: **見送る (指摘は差分の切り出し範囲に起因する誤認)**
- 根拠: Round 1 で渡した diff は `app/ resources/ tests/ routes/ config/ bootstrap/` に
  スコープしていた (app-implement スキル A-2 の規定)。`docs/` と `AGENTS.md` は
  この範囲外なので写っていないだけで、S7 は実装済みである。
- 対応内容: Round 2 のプロンプトに `docs/architecture.md` と `AGENTS.md` の diff を添付して
  実在を示し、内容 (規約 ↔ テスト対応表 / 閉じない窓 / 運用所有者) をレビューしてもらう。
