# 対応マトリクス: design-review Round 2

## [Critical] M9 の掲載コードが固定値 `>= 2` のまま / `capture()` が baseline を記録していない

- 判断: **対応する (指摘は正しい。Round 1 で本文だけ直して掲載コードを直し忘れていた)**
- 対応内容:
  - サンプルコードを `$baseline = DB::transactionLevel();` →
    `toBeGreaterThanOrEqual($baseline + 1)` に修正
  - **対象ジョブクラスで filter してから assert する**設計に変更し、
    `RecordsJobQueueingTransactionLevel::only(array $records, string $jobClass)` を新設
    (action 中に付随ジョブが増えても無関係な理由で壊れない)
  - `after_commit=false` 前提を**テスト内で assert** する行を追加
    (`after_commit=true` だと `JobQueueing` が commit 後 callback で発火し level が baseline に落ちる)

## [Critical] `Event::swap($original)` では listener が除去されない

- 判断: **対応する (指摘は正しい。`listen()` は同じ dispatcher インスタンスを書き換えている)**
- 対応内容: `finally` を `Event::forget(JobQueueing::class)` に変更した。
  Codex の第 2 案 (テストごとに独立 dispatcher へ swap) は、capture 中に
  **既存の listener (model observer など) がすべて無効化される**副作用があるため採らない。
  `forget` は `JobQueueing` の listener を全消しするが、**本アプリは `JobQueueing` の listener を
  1 つも登録していない**ため安全。実装時に
  `grep -rn "JobQueueing" app/ bootstrap/` で 0 件を確認する手順を docblock に書いた。

## [Warning] M2 の削除経路・finalize に tx level テストが無い

- 判断: **対応する**
- 対応内容: `TakeDeletionQueueAtomicityTest` / `VideoManualDeletionQueueAtomicityTest` に
  「対象ジョブクラスに限定した `baseline + 1`」の主契約テストを追加し、
  `RenderFinalizeQueueAtomicityTest` (`DeleteRenderOutputsJob`) を新設した。
  rollback テストは全経路で「補助」に降格。

## [Warning] M6 の個別接続設定の fail-closed が不十分

- 判断: **対応する**
- 対応内容: 疑似コードを書き直した。
  - `queue.connections` が非配列なら即 return (以降の offset 参照をしない)
  - `queue.default` / `database.default` が非空 string でなければ違反 → **そこで打ち切る**
  - `connections['sync']` が非配列なら R4 違反
  - 接続定義が欠落・非配列なら R1 違反として **`continue`** (offset 参照をしない)
  - **R2 は三分岐**: `null` = 許可 / 非空 `string` = `database.default` と一致要求 /
    それ以外 = 違反
  - テスト計画に fail-closed の 7 ケースを追加

## [Warning] `PINNED_CONNECTIONS` 対称差テストの「明示接続集合」の抽出規則が未定義

- 判断: **対応する**
- 対応内容: 抽出規則を設計に固定した — `QUEUED_JOB_LEASE_INVENTORY` の**値**のうち
  (1) `null` 除外、(2) `array_unique`、(3) `sort`。`sync` と既定接続名は含めない。
  負のコントロールとして mutation #15 (inventory に架空接続 `'database-imaginary'` を足す) を追加。

## [Warning] M7 の `RUNTIME_ROOTS` を両方の列挙が参照すると exact-fit にならない

- 判断: **対応する (良い指摘。自分の設計の穴だった)**
- 根拠: 定数から `routes` を消すと実装列挙と Finder 列挙が**同時に**狭まり、
  対称差 0 もルート単位 0 件 fail も通ってしまう。
- 対応内容: Architecture テスト側に**期待ルート集合をリテラルで独立に固定**する
  テスト 5b を追加 (`toEqualCanonicalizing(['app','routes','bootstrap','database','config'])`)。
  テスト 6 のループも**テスト側リテラル**を回す (定数を回さない) ことを明記。
  mutation #16 (`RUNTIME_ROOTS` から `routes` を消す) を追加し、
  「テスト 5 と 6 だけでは落ちない」ことも確認手順に含めた。

## [Warning] fixture 列挙と `runtimePhpFiles()` の API が一致していない

- 判断: **対応する**
- 対応内容: 列挙純関数を `phpFilesUnder(list<string> $roots)` として切り出し、
  本番は `RUNTIME_ROOTS`、負のコントロールは fixture root を渡す形にした
  (`detectInFiles()` へ直接パスを渡すだけでは列挙部分が検証されない、という指摘に対応)。

## [Warning] M8 の mutation #13 の期待先が誤り

- 判断: **対応する**
- 対応内容: #13 の落ちるべきテストを rollback テストから **tx level テスト**
  (`AutoRechargeAttemptDispatchAtomicityTest`) へ修正し、
  「rollback テストは落ちない」ことを注記した。

## [Warning] M10 / 保証しないもの の補正 4 点

- 判断: **すべて対応する**
- 対応内容:
  - 項目 1 を分割して書き直した (「commit 前障害は両方 rollback = 不整合窓ではない」/
    「commit 後は jobs 行が残るが worker の処理は保証しない」/「DB の commit 結果不明は対象外」)
  - 13 番: tx level 観測は `database` 接続かつ `after_commit=false` のテスト構成に依存する
  - 14 番: pinned connection 集合の完全性は `QueuedJobLeaseInventoryTest` の抽出能力に依存する
  - 15 番: D1/D2 はコメント・文字列リテラルをコードとして誤検出しうる

## [Suggestion] `DB::transaction()` の戻り値型伝播の説明が強い

- 判断: **対応する**
- 対応内容: 「戻り値は `mixed` のため注釈が必須」→
  「戻り値型を伝播できるが、解析結果が十分に具体化されない場合に備えて shape を明示する」へ修正。
