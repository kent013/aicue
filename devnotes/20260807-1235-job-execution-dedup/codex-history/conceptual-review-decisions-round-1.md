# 対応マトリクス: conceptual-review Round 1

## [Critical] 実現可能性: `status === Running` の再読込は排他でも CAS でもなく構造的保証にならない (再読込 → cron が failed 化 → 外部呼び出し、の順序が残る)

- 判断: **一部対応 + 一部反論**
- 根拠:
  - **反論 (中核)**: Codex が挙げた代替 (`running → sending_*` の条件付き UPDATE / `execution_token` の CAS)
    は**この窓を閉じない**。DB の決定 (commit) と外部 HTTP 送信は別リソースであり、
    両者を原子化する手段は分散トランザクションしかない。CAS でも
    「CAS 成功 → cron が failed 化 → 送信」の順序はそのまま残る (窓の幅も同じ)。
    CAS が優位なのは「**同一行を複数担当が同時に奪い合う**」場合だが、aicue では
    `startJob()` の `lockForUpdate() + status === Queued` guard により
    1 行が `Running` へ遷移するのは高々 1 回で、再実行は新しい行を起票する。
    つまり CAS が解く問題そのものが存在しない。
  - **反論 (窓の幅の実測)**: `recoverStale()` は `running` かつ `updated_at <= now - 30 分` を対象にする。
    一方 worker が張る SIGALRM は `RunManualAnalysis::$timeout = 1,560s (26 分)` /
    `RunManualRender::$timeout = 1,500s (25 分)` で、`startJob()` が `updated_at` を
    run() 入口で更新する。したがって **`$timeout < stale 閾値` が成り立つ限り、
    `queue:work` で生きているワーカーのジョブが recoverStale の母集団に入ることはない**。
    この序列は `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` が
    既に CI 固定している (1,560 < 1,680 < 1,800 ≤ 1,800)。
    残る窓は「ワーカーが自分の SIGALRM 予算を 240 秒以上超えて停止 (SIGSTOP / VM freeze) し、
    かつ再開する」という経路に限られる。
  - **対応**: この分析が概念設計に書かれていなかったのは事実で、Codex の指摘は
    「設計文書として保証範囲を明示していない」点で正しい。
    **概念設計に §「閉じる窓と閉じない窓」を新設**し、上記を明記する。
    あわせて `queue:listen` (dev / bug-hunt) ではジョブ側 `$timeout` が効かないため
    この序列が成立しない例外も記録する (`QueueWorkerLeaseInvariantTest` の docblock が根拠)。
- 対応内容: 概念設計に §「所有権再検証が閉じる窓・閉じない窓」を追加。
  claim token / sending 状態 CAS を採らない根拠を「窓を閉じないから」に更新
  (従来は「不要だから」だけだった)。

## [Critical] 期待効果が設計の保証能力を超えている (「最大 3 回 → 0 回」等)

- 判断: **対応する**
- 根拠: 妥当。「0 回」は「再検証時点で既に terminal であることを検出できたケースで 0 回」であり、
  無条件の 0 ではない。効果表現が保証範囲を超えると、次に読む人が残余窓を見落とす。
- 対応内容: 期待効果を Codex の提案どおり 3 層に分けて書き直す
  (再読込で防げるもの / 防げない race / 完全に防ぐために必要なもの)。
  数値は「**再検証時点で terminal を検出できた場合の後続外部呼び出し = 0**」へ改める。

## [Critical] `JobOwnershipLostException` を `Log::info + return` で握ると本物の実装ミスと区別できない

- 判断: **対応する**
- 根拠: 妥当。所有権喪失は「正常だが異常兆候」であり、無音で return すると
  頻度が観測できない (窓が実際に開いているかを運用で確かめられない)。
- 対応内容: 例外に構造化コンテキスト (`jobType` / `jobId` / `expectedStatus` / `actualStatus` /
  `stage` / `externalCall`) を持たせ、専用の `Log::warning` (info ではなく warning) で
  全項目を出す。Feature テストで「所有権喪失時に外部呼び出しが起きない」だけでなく
  「failJob が呼ばれない / 通知が飛ばない」まで固定する。

## [Warning] AutoRecharge の Pending 再確認にも同じ race。Stripe idempotency key を保証層に含めよ

- 判断: **対応する (既存機構の明記)**
- 根拠: aicue は既に `idempotencyKeyBase($attempt) = 'auto-recharge:'.$attempt->attempt_ulid` を
  `createAutoRechargeInvoice()` / `payOffSessionInvoice()` の両方へ渡している。
  Stripe 側の冪等は attempt 行に pin 済みで、**同一 attempt に対する二重課金は成立しない**。
  設計文書がこれを保証層として明示していなかったのが欠落。
- 対応内容: 概念設計の保証層を「(1) org lock (best-effort) / (2) 送信直前の Pending 再検証 /
  (3) 条件付き UPDATE / (4) Stripe idempotency key (attempt_ulid pin)」の 4 層として明記し、
  S4 の目録にも 4 層の対応を書く。

## [Warning] Render の upload 直前だけでは multipart / 途中死の孤児は残る。「発生源を 1 つ潰す」は言い過ぎ

- 判断: **対応する**
- 根拠: 妥当。加えて出力キーは `v{scenario_version}-{jobId}.mp4` と決定的なので
  重複 PUT は上書きであり「二重オブジェクト」は元々発生しない。問題は
  「terminal 済みジョブの出力が残る」ことである。
- 対応内容: 表現を「**terminal 済みと検出できたジョブの PUT を抑止する**」へ改め、
  残余 (PUT 中のプロセス死) を §閉じない窓へ記載する。

## [Warning] S4 の母集団 18 件は重い。Mailable / Notification まで含めると免除目録がノイズ化する

- 判断: **一部反論 + 一部対応**
- 根拠:
  - **反論**: 母集団を「外部副作用または課金に触れる queued class」へ絞ると、
    その**セレクタ自体が新しい判断点**になり、新規ジョブがセレクタから漏れて
    静かに未分類になる (deny-by-default が壊れる)。
    `QueuedJobLeaseInventoryTest` と母集団を完全一致させておくことには
    「片方だけ更新される drift が構造的に起きない」という別の価値もある。
  - **対応**: ノイズ懸念自体は正しい。配信系 (Mailable / Notification 8 件) を
    **1 つの免除 case に集約**し、case 別 cap を 8 (exact fit) で置く。
    これで 8 件は「1 個の裁定 + 8 行の登録」に圧縮され、増えたときだけ再検討が強制される。
- 対応内容: `JobDedupExemption::OutboundDeliveryWithoutDomainStateWrite` (配信系) を新設し、
  case 別 cap で膨張を検出する方式を概念設計へ明記。

## [Warning] `[class-string, method]` の配列 shape は PHPStan で緩みやすい

- 判断: **対応する**
- 根拠: 妥当。tuple 配列は shape を書けるが、目録が育つと読み手にも静的解析にも辛い。
- 対応内容: `tests/Support/JobDedup/` に `final readonly class GuaranteeEntry` /
  `ExemptionEntry` を置き、constructor で `class-string` / enum / `non-empty-string` を受ける形にする
  (アプリコードではなくテスト支援クラスとして置く = app/ を汚さない)。

## [Warning] 禁止事項: AGENTS.md 規約追加を含むなら Architecture テストまで含めないと「実装済み」にならない

- 判断: **対応する**
- 根拠: AGENTS.md 禁止事項 1 そのもの。
- 対応内容: 概念設計の実装方針に「S1/S2 は Feature テスト、S3/S4 は Architecture テストで
  登録まで行って初めて完了」と明記する。

## [Suggestion] 再検証メソッドの戻り型・例外型も固定せよ

- 判断: **対応する (詳細設計で)**
- 根拠: 低コストで効く。
- 対応内容: 詳細設計で `void` 返却 + `JobOwnershipLostException` throw に固定し、
  gate の Reflection 検査で「戻り型が void」まで見る。
