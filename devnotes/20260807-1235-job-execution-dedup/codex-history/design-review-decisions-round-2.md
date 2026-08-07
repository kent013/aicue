# 対応マトリクス: design-review Round 2

## S2 [Warning] `writeProgress(array<string, mixed>)` では `status` や保護列を渡せてしまう

- 判断: **対応する**
- 根拠: 妥当。「進捗書き込みは状態遷移を行わない」という境界が型で閉じていない。
- 対応内容: 引数を array shape
  `array{step: string, progress: int, result_json?: array<string, mixed>}` に固定する。
  PHPStan level 10 が `status` キーの混入を静的に弾く。

## S2 [Suggestion] 規約名「終端後の自前書き込みの禁止」は実態と合わない (`extracted_json` が例外)

- 判断: **対応する**
- 根拠: 妥当。例外を持つ規約に包括的な名前を付けると誤読される。
- 対応内容: 規約名を **「終端後のジョブ状態・進捗書き込みの禁止」** に改める
  (対象はジョブ行の `step` / `progress` / `result_json`。監査スナップショットは対象外と明示)。

## S4 / S6 [Critical] `PreflightCheckpoint` は `void` を要求するが `stillPending()` は `bool` を返す (gate が必ず落ちる)

- 判断: **対応する**
- 根拠: 完全に妥当。設計の**内部矛盾**であり、実装したら即赤になる。
  Manual (例外で中断) と Billing (structured return) を無理に統合しない方針は維持したいので、
  Codex 提案の前者 (制御方式を型として持つ) を採る。
- 対応内容: `Tests\Support\JobDedup\PreflightControlFlow` enum を新設し
  (`ThrowsOnLoss` / `ReturnsBoolean`)、`PreflightCheckpoint` に持たせる。
  gate の Reflection 検査を分岐させる:
  - `ThrowsOnLoss` → 戻り型が `void` であること
  - `ReturnsBoolean` → 戻り型が `bool` であること
  これで「どちらの制御方式かを目録が明示し、型がそれと一致する」ことまで固定できる。

## S4 [Critical] attach 失敗時に `Canceled` 以外だと新規 invoice が放置される

- 判断: **対応する**
- 根拠: 完全に妥当。`Failed` へ遷移させた経路は `stripe_invoice_id === null` を見ているため
  invoice ID を知らず終端できない。こちらも `Canceled` 限定にすると**誰も終端しない**。
- 対応内容: 2 つの後始末を**別メソッドに分ける**:
  - `terminateUnattachedInvoice($attempt, $invoiceId)` — **attach 失敗専用。status を問わず
    原則終端する** (この invoice ID を知っているのは自分だけだから)。
    `paid` の可能性は `CashierAutoRechargeGateway::terminateInvoice()` の状態検査が
    `Assert` で fail-closed に分類する (例外 → `terminated=false` としてログ)。
  - `terminateInvoiceAfterOwnershipLost($attempt, $invoiceId)` — **pay 前の所有権喪失専用。
    `Canceled` のみ終端**する (`Failed` は `terminateAndFail()` が invoice ID を
    DB 経由で見えている状態で終端済み)。

## S4 / S6 [Critical] `ExecuteAutoRechargeAttemptJob` の複合保証を単一 mechanism で表現できていない

- 判断: **対応する**
- 根拠: 完全に妥当。`ConditionalStatusUpdate` の適用条件は「0 行更新なら後続を行わない」だが、
  実際には付与が UPDATE より先に走る。**enum 自身の適用条件に一致しない登録**になっていた。
  反論 (順序は変えない) が受け入れられた以上、型がその複合構造を正確に持つべきである。
- 対応内容: `GuaranteeEntry::$mechanism` を **`$mechanisms: non-empty-list<JobDedupGuarantee>`**
  へ変更し、`ExecuteAutoRechargeAttemptJob` を
  `[JobDedupGuarantee::DatabaseUniqueConstraint, JobDedupGuarantee::ConditionalStatusUpdate]`
  で登録する (台帳付与の一回性 = invoice 単位 UNIQUE / attempt 遷移の一回性 = 条件付き UPDATE)。
  gate に「`mechanisms` が空でない」「重複がない」検査を足す。

## S4 [Warning] テスト手順が競合点と一致していない (invoice 作成の直前に canceled 化すると preflight 1 で止まる)

- 判断: **対応する**
- 根拠: 完全に妥当。私のテスト案では `attach 0 行` の経路を 1 度も通らない。
- 対応内容: `tests/Support/FakeAutoRechargeGateway` に
  `public ?Closure $duringCreateInvoice = null;` フックを追加し
  (`FakeRenderComposer::$duringCompose` と同じ作法)、
  **invoice ID を返す直前に attempt を terminal 化**させる。
  これで `preflight 1 成功 → Stripe 作成成功 → 並行 terminal 化 → attach 0 行 → 終端` を
  決定論的に再現する。`Canceled` と `Failed` の両方でケースを作る。

## S4 [Warning] cleanup ログが固定した最小 7 キーを満たしていない

- 判断: **対応する**
- 根拠: 妥当。「同じ event の全ログが同じ集計 schema」という説明と矛盾する。
- 対応内容: cleanup を**別 event 名**にする
  (`ExternalCallKind::CLEANUP_LOG_EVENT = 'job_ownership_lost_cleanup'`)。
  最小 7 キーは `LOG_EVENT` (= 送信抑止の記録) にのみ課し、
  cleanup は `event` / `job_type` / `job_id` / `attempt_ulid` / `invoice_id` / `terminated` /
  `error` の独自 schema とする。両者の schema をテストで固定する。

## S5 [Warning] 「上記 3 ケース」が実際は 4 ケース

- 判断: **対応する**
- 根拠: 事実誤り。
- 対応内容: 「上記 4 ケース」へ修正。

## S6 [Warning] 意味のない `foreach (QueuedJobPopulation::appPhpFiles() as $_)` ループ

- 判断: **対応する**
- 根拠: 妥当。走査コストだけが発生する死んだコード。
- 対応内容: 削除。sealed 検査は `tests/Support/JobDedup/` 配下だけを走査する。

## S7 [Warning] 規約↔テスト対応表が新しい型モデルと合っていない

- 判断: **対応する**
- 根拠: 妥当。「void を返す」は Billing に適用できない。
- 対応内容: 対応表の当該行を
  **「preflight の再検証点が実在し、登録された制御方式 (`PreflightControlFlow`) に
  一致する戻り型を持つ」** へ書き換える。
