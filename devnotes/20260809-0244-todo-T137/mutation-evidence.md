# mutation 赤化確認 (M1〜M24)

対象: T137 / 詳細設計 `devnotes/20260809-0027-queue-dispatch-atomicity/detailed-design.md` §mutation 表。

**手順**: 変異は 1 個ずつ入れて対象テストを 1 回走らせ、**必ず元へ戻した**。
最後に `git diff` / `git status --short` で残留がないことを確認済み
(`app/Jobs/Billing/SyncBillingCustomerDetails.php` / `app/Mail/InquiryReceivedMail.php` /
`config/queue.php` / 各 Support は差分なし or 意図した差分のみ)。

**設計の予測とずれた点は辻褄を合わせず、そのまま記録している** (#1 / #3 / #4 / #16 / #20 / #22 / #24)。

凡例: ✅ = 意図した検査が赤化 / ⚠ = 設計の予測とずれた (内容を併記)

| # | 変異 | 結果 | 実測 |
|---|---|---|---|
| 1 | `config/queue.php` の `sync` から `after_commit => true` を削る | ⚠ | `QueueDispatchAtomicityGuardBootTest` の **3 本すべてが起動時例外で赤** (`queue.connections.sync は driver=sync かつ after_commit=true でなければなりません`)。**設計が予測した `QueueDispatchAtomicityGuardTest` (R4) は赤にならない** — 同テストは `config()->set()` で baseline 構成を自前で流し込む純関数テストで、`config/queue.php` の実値を読まないため。実値を見る検査点は boot テスト側にある |
| 2 | `database` の `after_commit` を `true` にする | ✅ | `QueueDispatchAtomicityInventoryTest` の **D4** が赤 (`sync 以外の接続で after_commit=true になっています: database`)。テストレーンの既定接続は `sync` のため R3 の参照集合に `database` は入らず、boot は落ちない (= D4 が唯一の検出点であることが実測で確認できた) |
| 3 | `database-render` の `connection` を別 DB 名 (`other_db`) にする | ⚠ | boot テスト 3 本が赤 (`キュー接続 database-render の DB 接続 (other_db) が業務 DB (pgsql) と異なります`)。#1 と同じ理由で `QueueDispatchAtomicityGuardTest` (R2) は赤にならない |
| 4 | production の R5 検査を潰す (`if ($isProduction)` → `if (false)`) | ⚠ | `QueueDispatchAtomicityGuardTest` の R5 系 **3 本**が赤。**設計の記述「production 判定時の既定接続を sync にする」はそのままでは変異にならない** (それは R5 テストが再現する構成そのもので、guard が正しければ赤にならない)。実装側を潰す形へ読み替えて実施した |
| 5 | `AnalysisJobService::trigger` の dispatch を tx の外へ戻す | ✅ | `QueueDispatchAtomicityTest` の `解析トリガの RunManualAnalysis は業務 tx の内側で投入される` **のみ**が赤 (rollback テストは緑のまま = 設計 §保証しないもの 12 の実測確認) |
| 6 | `BillingCustomerSynchronizer` に `->afterCommit()` を戻す | ✅ | `QueueDispatchAtomicityInventoryTest` の **D1** が赤 |
| 7 | `PaymentFailedNotification` に `ShouldQueueAfterCommit` を戻す | ✅ | 同 **D3** が赤 |
| 8 | `TicketLedgerService` に `DB::afterCommit` を戻す | ✅ | 同 **D2** が赤 + `TicketReserveDispatchAtomicityTest` の tx level テストが赤 (2 本同時) |
| 9 | 各検出器を「常に空配列を返す」に潰す (1 つずつ) | ✅ | `detectInFiles` → 負のコントロール D1 / D2 の 2 本<br>`detectAfterCommitInterfaces` → D3 の 2 本 (ShouldQueueAfterCommit / ShouldHandleEventsAfterCommit)<br>`detectAfterCommitEnabledConnections` → D4 の 1 本<br>`detectAfterCommitProperty` → D5(既定値) の 2 本 (job / Mailable)<br>`detectAfterCommitAssignments` → D5(代入) の 2 本 ($this-> / $job->) |
| 10a | `phpFilesUnder()` を空配列返しにする | ✅ | **8 本**が赤 (母集団の対称差 / ルート単位 0 件 fail / 負のコントロール 4 本 / 契約の固定 2 本) |
| 10b | `QueuedJobPopulation::shouldQueueClasses()` を空配列返しにする | ✅ | `QueueDispatchAtomicityInventoryTest` 2 本 + **既存の `QueuedJobLeaseInventoryTest` 2 本 / `JobExecutionDedupInventoryTest` 2 本**が赤 (巻き添えではなく意図した連動) |
| 11 | `phpFilesUnder()` の走査から `app/Jobs` を除外する | ✅ | 母集団の Finder 対称差テストが赤 |
| 12 | `AutoRechargeTriggerJob` に `ShouldBeUnique` を戻す | ✅ | `JobExclusionOrderingInvariantTest` の反転テスト **2 本** (`ShouldBeUnique を実装しない` / `uniqueFor・uniqueId を持たない`) が赤 |
| 13 | `reserve()` の `AutoRechargeTriggerJob::dispatch` を tx の外へ戻す | ✅ | `TicketReserveDispatchAtomicityTest` の tx level テストのみ赤。**`AutoRechargeAttemptDispatchAtomicityTest` は緑のまま** (設計が予告したとおり別ジョブを見ているため) = 別テストを分けた判断が実測で正当化された |
| 13b | `createAttemptLocked()` の `ExecuteAutoRechargeAttemptJob::dispatch` を tx の外へ戻す | ✅ | `AutoRechargeAttemptDispatchAtomicityTest` の tx level テストが赤 |
| 14 | `tar_attempts_org_pending_unique` を外す | ✅ | `AutoRechargeAttemptUniquenessTest` の **2 本** (`2 件目の pending 行を拒否する` / `unique violation は no-op へ収束する`) が赤。**変異の入れ方**: migration を書き換えると再 migrate が要るため、テストの `beforeEach` で `DROP INDEX`(RefreshDatabase の tx 内なので巻き戻る) を実行する形で行った |
| 15 | `QUEUED_JOB_LEASE_INVENTORY` に架空の接続 (`database-imaginary`) を入れる | ✅ | 新設した `PINNED_CONNECTIONS` 対称差テストを含む **3 本**が赤 (他 2 本は既存の目録整合テスト) |
| 16 | `RUNTIME_ROOTS` から `routes` を消す | ⚠ | **テスト 5b (ルート集合の独立 pin) と テスト 5 (Finder 対称差) の 2 本**が赤。設計は「5b だけが落ち、5 と 6 では落ちない」と予測していたが、実装では**テスト 5 の Finder 側もテスト側リテラル `QUEUE_DEFERRAL_EXPECTED_ROOTS` を回している**ため対称差も同時に赤くなる (設計より強い。テスト 6 は予測どおり緑のまま) |
| 17 | `sync` の `driver` を `database` に変える | ✅ | boot テスト 3 本が R4 で赤 (driver 検査が効いている) |
| 18 | `capture()` の `finally` (`$collector->active = false;`) を削る | ✅ | `RecordsJobQueueingTransactionLevelTest` の `capture 後に別ジョブを dispatch しても件数が増えない` が赤 (collector オブジェクト方式でなければ copy-on-write で空振りする点の実証) + 例外経路のテストも赤 |
| 19 | `database-analysis` の `driver` を `sync` に変える | ✅ | boot テスト 3 本が **R1** で赤 (`キュー接続 database-analysis の driver が database ではありません`)。**sync 除外を接続「名」で行っている**ことの実測確認 (driver で除外する実装ならここは全 skip されて緑になる) |
| 20 | 任意の job クラスに `$afterCommit = true;` を足す | ⚠ | `QueueDispatchAtomicityInventoryTest` の **D5(既定値)** のみが赤 (D1〜D4 は緑 = 設計の予告どおり)。ただし **`public bool $afterCommit = true;` は PHP の言語仕様上そのままでは書けない** — `Illuminate\Bus\Queueable` が `public $afterCommit;` を持つため trait composition が fatal になる。変異は `use Queueable;` を外し `public $afterCommit = true;` (型なし) を足す形で実施した |
| 21 | 任意の job のコンストラクタに `$this->afterCommit = true;` を足す | ✅ | 同 **D5(代入)** が赤 |
| 22 | `InquiryReceivedMail` から `implements ShouldQueue` を外し `$afterCommit = true` を足す | ✅⚠ | 同 **D5(既定値)** が赤。**母集団を `shouldQueueClasses()` だけに戻すと検出できない**ことも実測で確認した:<br>`detectAfterCommitProperty(shouldQueueClasses())` → `[]`<br>`detectAfterCommitProperty(merge(shouldQueue, mailables))` → `["App\Mail\InquiryReceivedMail"]`<br>(⚠ #20 と同じ理由で `use Queueable;` の除去が併せて必要) |
| 23 | ShouldQueue クラスに `implements ShouldHandleEventsAfterCommit` を足す | ✅ | 同 **D3** が赤。**`ShouldQueueAfterCommit` だけを見る実装では検出できない**ことも実測で確認した:<br>ShouldQueueAfterCommit のみ → `[]` / 両 interface → `["App\Jobs\Billing\SyncBillingCustomerDetails"]` |
| 24 | `deferralCandidateClasses()` を `shouldQueueClasses()` だけ返すよう潰す | ⚠ | **どのテストも赤にならなかった**。現状 `mailableClasses()` ⊆ `shouldQueueClasses()` (Mailable 2 クラスが `implements ShouldQueue` を併記している) のため、和集合を片側へ潰しても**結果が変わらない**。<br>**対応**: 和集合の生成を純関数 `mergeCandidateClasses(array, array)` へ切り出し、disjoint な 2 集合を食わせる負のコントロールを追加した。同関数を片側へ潰す変異 (#24b) で当該テストが赤になることを確認済み。<br>**残る穴 (誇張しない)**: `deferralCandidateClasses()` 自体を片側へ潰す変異は、併記が続くかぎり検出できない。併記が外れた瞬間 (= #22 の状態) には D5/D3 の 0 件 pin が実効を持ち検出できる |

## まとめ

- **意図した検査が赤化しなかったのは #24 のみ**で、原因 (母集団の包含関係による degenerate) を特定し、
  負のコントロールを 1 本追加して和集合ロジック自体は固定した。残る穴は上表に明記した。
- #1 / #3 は「設計が指した落ちるべきテスト」が実際には別 (boot テスト) だった。
  guard の純関数テストは config を自前で組み立てるため、`config/queue.php` の実値の変異は
  **boot 経路でのみ**観測できる。両方が揃っていることが実測で確認できた。
- #4 は設計の記述がそのままでは変異にならないため、実装側 (R5 検査) を潰す形へ読み替えた。
- #16 は設計の予測より**強い** (5b に加えて 5 も赤くなる)。
- #20 / #22 は PHP の trait composition 制約により、設計の書式 (`public bool $afterCommit = true;` を
  そのまま足す) では fatal になる。`use Queueable;` を外す形で実施した。
  裏を返すと、**`Queueable` を使うクラスでは D5(既定値) の迂回路そのものが書けない**
  (現実に書けるのは Mailable のように trait を使わないクラス) — Mailable を母集団へ足した
  設計判断の正しさが、この制約からも裏づけられた。

---

## 追記: Codex 実装レビューで検出器を広げた分の被覆 (Round 1〜6)

レビューで以下の迂回路が「設計の検出器では捕まらない」と判明したため、検出器を広げ
**それぞれに負のコントロールと偽陰性コントロールを追加した** (= 変異相当の赤化確認を
テストとして常設した)。

| 追加した検出 | 負のコントロール (検出すべき) | 偽陰性コントロール (検出してはいけない) |
|---|---|---|
| D5 既定値の truthy 判定 (`(bool)` = vendor と同じ) | `public $afterCommit = 1;` | `null` / `false` / `0` |
| D5 constructor promotion (値に依らず違反) | `__construct(public bool $afterCommit = true)` / `= false` | (該当なし。promoted は常に違反) |
| D5 代入の truthy リテラル判定 | `= 1` / `= 'yes'` / `= 2.5` | `false` / `null` / `0` / `''` / `'0'` / `$flag` |
| D5 代入の基数付き数値 | `0x1` / `0b1` / `0o1` / `010` / `1_000` | `0x0` / `0b0` / `0o0` / `000` / `0e5` / `"\x30"` (評価不能へ倒す) |
| **D6 `ShouldDispatchAfterCommit` (event 側)** | ダミー event クラス 1 件 | (母集団 = `app/` 全クラスで現行 0 件) |

いずれも「検出器を潰すと対応する負のコントロールが赤くなる」形 (mutation #9 と同型) であり、
検出器の生存は常設テストで担保されている。
