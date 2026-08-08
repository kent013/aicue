# 対応マトリクス: design-review Round 1

## [Critical] M9 の rollback テストは赤化保証になっていない

- 判断: **対応する (指摘は完全に正しい。設計の穴だった)**
- 根拠: 旧実装 (`AnalysisJobService::trigger`) は service 内 tx の commit **後**に dispatch するが、
  テストが外側 `DB::transaction()` で包むとその dispatch も外側 tx の内側に入る。
  よって **旧実装でも jobs 行は外側 rollback で消える** = rollback テストでは
  「業務 tx 内移設」を検出できない。`CaptureTakeService::delete` / `VideoManualService::delete` も同型。
- 対応内容: M9 の**主契約を tx level 観測に変更**した。action 直前の
  `DB::transactionLevel()` を `baseline` として記録し、`JobQueueing` 時点の level が
  **`baseline + 1` 以上**であることを assert する (固定値 `>= 2` もやめた)。
  rollback テストは補助へ降格し、「赤化必須」の主張から外した。
  §保証しないもの に 12 番として明記した。

## [Critical] M6 の `PINNED_CONNECTIONS` が hard-coded で drift する

- 判断: **対応する**
- 根拠: `QueuedJobLeaseInventoryTest` の `QUEUED_JOB_LEASE_INVENTORY` が接続の SSOT
  (全 `ShouldQueue` クラスに対し deny-by-default で対称差 0)。guard 側の定数と繋がなければ、
  新しい pinned connection が増えたときに guard が黙って見落とす。
- 対応内容: **既存テストへの追加**として `QueuedJobLeaseInventoryTest` に 1 テスト追加する設計にした
  — `QueueDispatchAtomicityGuard::PINNED_CONNECTIONS` と `QUEUED_JOB_LEASE_INVENTORY` の
  明示接続集合の**対称差 0**。const が同ファイルにあるため、別ファイルから読む仕掛けを作らずに済む。
  既存テストの削除・書き換えは行わない (追加のみ)。guard 側の定数 docblock にもこの契約を書いた。

## [Warning] M3 の `&$crossing` (参照渡し) を避けよ

- 判断: **対応する**
- 根拠: 正しい。設計自体が「attempts は固定しない (機械固定しない)」と明記しているのに、
  retry で副作用が外に残る形を新規に増やすのは一貫しない。
- 対応内容: クロージャの戻り値を
  `array{reservation: TicketReservation, crossing: array{balance: int, threshold: int}|null}` に変更し、
  参照渡しを廃止した。PHPStan 適合チェックとリスク欄も更新した。
  小さな readonly DTO 案は「メソッド内で閉じた公開されない一時値」のため見送り (思考原則 2)。

## [Warning] M2 のコメントが保証過剰 (S3 孤児が構造的に発生しない)

- 判断: **対応する**
- 対応内容: コメントを
  「保証するのは『take 行を消したのに削除 job が投入されない窓』の解消だけである
  (worker 停止 / job 失敗 / ストレージ失敗ではオブジェクトは残る = 誇張しない)」へ弱めた。

## [Warning] M6 の config 想定外型の fail-closed が甘い

- 判断: **対応する**
- 根拠: 正しい。`database.default` を空文字へ丸めて比較を続けると、
  「接続の `connection` が null = 既定 DB なので OK」の R2 判定が比較対象不在のまま通る。
- 対応内容: `queue.default` / `database.default` がともに **非空 string でなければ独立した違反**
  として報告する形に変更し、テスト計画にも 2 ケース追加した。

## [Warning] M7 の D1/D2 母集団が `app/` のみで狭い

- 判断: **対応する**
- 根拠: 正しい。`DB::afterCommit` は `routes/console.php` や `bootstrap/app.php` にも書ける。
- 対応内容: D1/D2 の母集団を `QueueDispatchDeferralInventory::RUNTIME_ROOTS`
  (`app` / `routes` / `bootstrap` / `database` / `config`) に広げ、
  `runtimePhpFiles()` を新設した。対象外 (`vendor` / `tests` / `storage`) の根拠も定数 docblock に書いた。
  母集団 0 件 fail は**ルート単位**に変更 (全体件数だけ見ると「`routes/` だけ脱落」が通るため)。
  **D3 の母集団は `shouldQueueClasses()` (app/ 配下) のまま**でよい根拠
  (`ShouldQueueAfterCommit` を implement できるのはクラスで、`routes/` にクラス定義は置かない) も明記した。

## [Warning] M9 の listener 寿命が閉じていない

- 判断: **対応する**
- 対応内容: ヘルパを `try/finally` で dispatcher を復元する形にし、
  docblock に「**1 テスト 1 capture**」の規約を明記した。

## [Suggestion] guard を container 解決にする

- 判断: **対応する**
- 対応内容: `AppServiceProvider::boot()` を
  `$this->app->make(QueueDispatchAtomicityGuard::class)->enforce(...)` に変更した
  (boot からの呼び出しをテストで spy できる)。

## [Suggestion] AGENTS.md の保証しないものに 1 項追加

- 判断: **対応する**
- 対応内容: 「**dispatch が業務 tx 内にあることの静的完全性は保証しない**。gate が固定するのは
  commit 後ずらしの機構を使っていないことまでで、既知経路は behavioral test が固定する」を
  M10 の追記案と §保証しないもの (11 番) に追加した。

## [Suggestion] `isUniqueViolation()` の追跡先を残せ

- 判断: **対応する**
- 対応内容: §未解決事項 1 に「実装時に `docs/TODO.md` へ Low で起票し本 devnotes へリンクする」を追記した。
