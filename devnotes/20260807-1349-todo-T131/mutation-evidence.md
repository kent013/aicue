# T131 mutation 赤化確認 (詳細設計 S6 の mutation 表 M1〜M17)

詳細設計 `devnotes/20260807-1235-job-execution-dedup/detailed-design.md` §S6
「テスト計画 (gate 自身の受け入れ = mutation 手順)」の全 17 件を **1 つずつ手で入れて赤化を確認し、
必ず元へ戻した**。実施日: 2026-08-07 (JST) / ブランチ `todo/T131`。

- ドライバ: `mutate.py` (scratchpad の一時スクリプト。各 mutation は適用 → 対象テスト実行 →
  バックアップから復元、を 1 件ずつ直列に行う)。**恒久スクリプトへは昇格させない**
  (`scripts/README.md` の台帳対象外)。
- 実行コマンドはすべて `composer test -- <テストファイル>`
  (`composer test` は `"$@"` を `artisan test` へ透過する。全件実行を 17 回やらない)。
- 最終確認: `git diff` / `grep -rn 'MUTATION|MutationRemoved|assertStillOwnedX|mutationEvent|AlwaysSatisfied' app/ tests/`
  で mutation の残留が 0 件であること、`LOCK_TTL_SECONDS = 180` / `uniqueFor = 30` が
  元の値に戻っていることを確認済み。
- **impl-review (Codex) の指摘対応で実装を触ったため、影響のある mutation は再実施した**:
  - Round 1 対応 (`writeProgress` の cast 正規化 / `JobExclusionOrderingInvariantTest` の前提追加)
    → **M5 / M6 / M11 を再実施し、いずれも赤化を再確認**。
  - Round 2 対応 (`terminateInvoiceBestEffort` の `error` を例外クラス名へ)
    → **M13 / M14 / M15 / M16 / M17 を再実施し、いずれも赤化を再確認**
      (M15 / M16 / M17 は新規追加した
      `後始末ログの error は例外クラス名のみで、外部由来のメッセージを含まない` も巻き込んで赤くなる)。
  - Round 3 対応 (原例外を `report()` せずサニタイズ済み例外を報告する形へ)
    → **M13 / M14 / M15 / M16 / M17 をもう一度再実施し、いずれも赤化を再確認**
      (M17 は新設の `後始末の例外報告にも外部由来のメッセージを渡さない` も巻き込む)。

## 結果一覧

| # | mutation の内容 | 実行コマンド | 赤化したテスト名 |
|---|---|---|---|
| M1 | `jobDedupGuarantees()` の `RunManualAnalysis` entry のキーを別名へ差し替え (= 目録から削除) | `composer test -- tests/Architecture/JobExecutionDedupInventoryTest.php` | `キューに載る全クラスが保証側 or 免除に分類されている (未分類は fail)` (「未分類の ShouldQueue 実装がある: App\Jobs\Manual\RunManualAnalysis」) / `期待する外部呼び出し種別が全ジョブ分宣言されている` / `登録済み checkpoint の種別集合が期待集合と一致する` |
| M2 | `AnalysisPipeline::assertStillOwned` を `assertStillOwnedX` にリネーム | 同上 | `preflight の再検証点が実在し、登録された制御方式に一致する戻り型を持つ` (「…assertStillOwned が実在しません」) |
| M3 | `AnalysisPipeline::assertStillOwned` の戻り型を `void` → `bool` | 同上 | `preflight の再検証点が実在し、…戻り型を持つ` (「戻り型が目録の制御方式 (void) と一致しません」) |
| M3b | `AttemptOwnershipPreflight::stillPending` の戻り型を `bool` → `void` | 同上 | `preflight の再検証点が実在し、…戻り型を持つ` (「戻り型が目録の制御方式 (bool) と一致しません」) |
| M3c | `ExecuteAutoRechargeAttemptJob` の `mechanisms` を空配列にする | 同上 | `GuaranteeEntry` の `Assert::notEmpty`「保証機構を 1 つ以上登録すること」で 7 テストが error (`保証機構は 1 つ以上・重複なしで登録されている` を含む) |
| M4 | `NoExternalCall` の根拠を 10 文字にする | 同上 | constructor の `Assert`「「外部呼び出しなし」の根拠は 30 文字以上で書くこと」で 7 テストが error (`目録の根拠は 30 文字以上 (constructor と gate の二重固定)` を含む) |
| M5 | `AutoRechargeService::LOCK_TTL_SECONDS` を 700 にする | `composer test -- tests/Architecture/JobExclusionOrderingInvariantTest.php` | `入口の排他: auto-recharge の org lock TTL は既定接続の retry_after を下回る` |
| M6 | `AutoRechargeTriggerJob::$uniqueFor` を 0 にする | 同上 | `入口の排他: uniqueFor は正の値である (実質無効化の検出)` |
| M7 | `tests/Support/JobDedup/AlwaysSatisfied.php` (3 つ目の `PreflightRequirement` 実装) を追加 | `composer test -- tests/Architecture/JobExecutionDedupInventoryTest.php` | `PreflightRequirement の実装は 2 種類に閉じている (sealed 相当)` |
| M8 | `AnalysisPipeline` に `'job_ownership_lost'` を **single quote** で直書き | 同上 | `固定 event 名の literal は ExternalCallKind 以外に直書きされていない` |
| M8b | 同じく **double quote** `"job_ownership_lost"` を直書き | 同上 | `固定 event 名の literal は ExternalCallKind 以外に直書きされていない` (quote 種別の取りこぼし無し) |
| M8c | `ExecuteAutoRechargeAttemptJob` の `preflights` から `StripeInvoiceCreate` の checkpoint を削除 | 同上 | `登録済み checkpoint の種別集合が期待集合と一致する (登録漏れ / 余剰の検出)` |
| M8d | `jobDedupRequiredExternalCalls()` から `RunManualRender` を丸ごと削除 | 同上 | `期待する外部呼び出し種別が全ジョブ分宣言されている (期待値の書き忘れ検出)` / `登録済み checkpoint の種別集合が期待集合と一致する` |
| M9 | 目録の免除を 1 件増やす (`RunManualAnalysis` を exemptions へ追加 = 15 件) | 同上 | `免除件数が全体 cap / case 別 cap と一致する (形骸化ガード)` / `保証側と免除は排他 (同じクラスが両方に居ない)` |
| M10 | `QUEUED_JOB_LEASE_INVENTORY` から `SyncBillingCustomerDetails` を削除 | `composer test -- tests/Architecture/QueuedJobLeaseInventoryTest.php` | `接続経路: キューに載る全クラスが目録に登録されている` (**走査を `QueuedJobPopulation` へ委譲した後も従来どおり検出できる**ことの確認) |
| M11 | `AnalysisPipeline::writeProgress()` の `where('status', running)` を外す | `composer test -- tests/Feature/Projects/AnalysisPipelineTest.php` | `preflight: cron failed 後に step / progress が旧ワーカーから書き戻されない` (step が Extract → Generate に書き戻された) |
| M12 | `RenderPipeline::updateProgress()` の `where('status', running)` を外す | `composer test -- tests/Feature/Manual/RenderPipelineTest.php` | `preflight: cron failed 後に step / progress が旧ワーカーから書き戻されない` (step が Compose → Concat に書き戻された) |
| M13 | `stripe_invoice_id` の永続化を素の `save()` に戻す (attach 判定を常に 1 にする) | `composer test -- tests/Feature/Billing/AutoRechargeServiceTest.php` | `attach 0 行: invoice 作成成功と同時に canceled 化 → invoice_id を書かず invoice を終端する` / `attach 0 行: failed へ遷移していた場合も invoice を終端する (status を問わない)` |
| M14 | `terminateUnattachedInvoice()` を `Canceled` 限定にする | 同上 | `attach 0 行: failed へ遷移していた場合も invoice を終端する (status を問わない)` |
| M15 | cleanup ログの event 名を `CLEANUP_LOG_EVENT` → `LOG_EVENT` に戻す | 同上 | `後始末ログは別 event 名 job_ownership_lost_cleanup を使い独自 schema を持つ` |
| **M16** | `createAutoRechargeInvoice()` 直前の `stillPending()` 呼び出しを削除 | 同上 | `配置: create の直前に preflight がある (terminalizeAt=create で invoice を作らない)` (シームの terminal 化が発火せず invoice が実際に作成され、pay まで到達して amount mismatch で error) / `配置: pay の直前に preflight がある` / `配置: 行が Pending のままなら create → pay が従来どおり進む (回帰)` |
| **M17** | `payOffSessionInvoice()` 直前の `stillPending()` 呼び出しを削除 | 同上 | `配置: 行が Pending のままなら create → pay が従来どおり進む (回帰)` (`calls` が create の 1 件だけになる) ほか 5 件 (`配置: pay の直前に preflight がある` / `後始末: terminalStatus=failed…` / `後始末: terminalStatus=paid…` / `preflight 2: terminateInvoice が例外を投げても課金処理へ進まない` / `後始末ログは別 event 名…`) |

## M16 / M17 についての補足 (設計で最後まで争点だった箇所)

`FakeAttemptOwnershipPreflight` は **verdict を差し替えない**。checkpoint の直前に
attempt 行を terminal 化して `parent::stillPending()` へ委譲するだけの「競合注入シーム」である。
そのため preflight 呼び出しを削除すると:

- シームが呼ばれない → **terminal 化そのものが起きない** → 行は Pending のままで外部呼び出しが走る
- `calls` (到達した checkpoint の記録) から当該 checkpoint が消える

の 2 系統で観測が崩れ、必ず赤くなる。**判定を fake に置き換えていたらこの論法は成立しない**
(fake が false を返すだけになり、削除しても「呼ばれなくなった」以外の差分が出ない)。
この性質は今後の変更でも壊さないこと。

## 「宣言的 gate が検出しないこと」(受容済み・設計 Round 4 の裁定)

`jobDedupRequiredExternalCalls()` (期待集合) と `jobDedupGuarantees()` (目録) を
**同時に**書き換えれば gate は green のままになる。これは宣言的 gate の性質であり、
目的は「1 箇所の削除では通らない = レビューで必ず 2 箇所の差分が見える」こと。
ソース走査による期待集合の導出は、preflight を意図的に持たない外部呼び出し
(所有権喪失**後**の後始末である `terminateInvoice`) の別分類が必要で複雑さが跳ねるため採らない。
