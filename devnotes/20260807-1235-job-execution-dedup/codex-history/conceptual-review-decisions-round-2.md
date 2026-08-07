# 対応マトリクス: conceptual-review Round 2

## [Critical] 「CAS でも窓の幅は同じ」という反論は成立しない (`running --CAS--> sending` と `running --CAS--> failed` は DB 上で直列化される)

- 判断: **対応する (反論を撤回し、主張を書き換える)**
- 根拠: Codex が正しい。両者の WHERE 条件を `status = running` にすれば成功するのは一方だけであり、
  worker が `sending` を獲得した後に cron は失敗させられない。**私の反論は
  「CAS 後に cron が failed 化できる」という誤った前提に立っていた**。撤回する。
  ただし、撤回のうえで次の 2 点は設計判断として残る (これは反論ではなく費用対効果の話):
  1. `sending` を導入すると、**その状態は recoverStale が回収できなくなる**。回収しなければ
     ワーカー死亡時にマニュアルが `analyzing` のまま固着する (ユーザーの詰み)。したがって
     `sending` にも回収閾値が要り、その閾値で**同じ形の競合が再び現れる**
     (幅は変わるが窓は消えない)。
  2. aicue では `running` が実質その役割を果たしている — 1 行が `running` になるのは高々 1 回
     (`startJob()` の `lockForUpdate + status === Queued` guard)、その保持者は常に単一のワーカー、
     回収閾値は `$timeout (1,560s) < stale (1,800s)` で既に CI 固定。
     `sending` を足して得られるのは「回収閾値をより細かく分ける」ことだけである。
- 対応内容:
  - 概念設計の §閉じない窓 1 を全面的に書き換え、「CAS は recoverStale との競合を閉じられる。
    閉じられないのは送信開始後の結果不明だけ」と正しく記述する。
  - 採らない理由を「窓が縮まらないから」→ **「既存の timeout 序列の下では発生可能性が十分低く、
    状態追加のコスト (回収閾値の再設計・UI/TS 型・recoverStale 改修) が便益を上回るというリスク受容」**
    へ変更する。
  - 家系への還流の位置づけを「CAS と同等」→ **「限定的な terminal 検出策 (preflight suppression)」** へ改める。

## [Warning] 「生きている worker は recoverStale の母集団に入らない」が強すぎる / OOM kill・deploy・host death は「復帰する worker」ではない

- 判断: **対応する**
- 根拠: 妥当。前者は成立条件 (SIGALRM が実際に有効・遅延しない、時計ずれ、supervisor の排除) に
  依存する。後者は私の分類ミスで、OOM kill / deploy / host death は
  「ワーカーが**居なくなる**」経路であり、そもそも競合しない (本設計が最も綺麗に扱えるケース)。
- 対応内容: 「入ることはない」→「**通常運用条件下では先に自分の SIGALRM で終了する**」へ弱め、
  成立条件を箇条書きで明示。「復帰する worker」の例を SIGSTOP / VM suspend /
  シグナル配送遅延に限定し、OOM kill / deploy / host death は「復帰しない = 競合しない」側へ移す。

## [Warning] `JobOwnershipLostException` を `App\Exceptions\Manual` に置く責務境界が曖昧

- 判断: **対応する (詳細設計で根拠を示す)**
- 根拠: 共用するのは `App\Services\Manual\AnalysisPipeline` と
  `App\Services\Manual\RenderPipeline` の 2 つで、**どちらも Manual ドメイン**。
  AutoRecharge 側は例外を投げず structured return で閉じるため、この例外を跨いで使わない。
- 対応内容: 詳細設計に「利用者は Manual ドメインの 2 パイプラインのみ。Billing は
  structured return で閉じるため共用しない」と明記する。

## [Warning] 「保証は外部呼び出し直前の所有権再検証が担う」が強すぎる

- 判断: **対応する**
- 根拠: 妥当。再読込が担うのは「既に失われた所有権の**検出**」であり、結果の一回性そのものではない。
- 対応内容: 改善アイデアの主張を
  **「結果の一回性は永続状態遷移 (条件付き UPDATE / 悲観ロック + status guard) と
  外部側の冪等性が担う。直前再検証はその手前に置く preflight suppression であり、
  既知の所有権喪失後の外部送信を抑止する」** へ書き換える。
  「結果の一回性」と「外部呼び出し回数の一回性」を分離して記述する。

## [Warning] S4 の enum で `Guarantee` と `PreflightSuppression` を同じ分類にしない

- 判断: **対応する**
- 根拠: 妥当。別概念 (AGENTS.md 思考原則 4)。
- 対応内容: `GuaranteeEntry` を 2 フィールドに分ける —
  `mechanism: JobDedupGuarantee` (永続状態遷移の機構) と
  `preflight: ?PreflightCheckpoint` (外部呼び出し直前の再検証点。無い = 外部呼び出しを持たない)。
  enum を混ぜず、目録の読み手が両者を別々に確認できる形にする。

## [Warning] 配信系の免除根拠を「domain state write が無い」だけにしない

- 判断: **対応する**
- 根拠: 妥当。メール / 通知の重複配信は立派な外部副作用である。
- 対応内容: case 名を `OutboundDeliveryWithoutDomainStateWrite` から
  **`DuplicateDeliveryAccepted`** へ変更し、case の docblock に適用条件
  「(1) ドメイン状態を書かない (2) 重複配信がユーザーに実害を与えない
  (3) `$tries` / retry 契約上 at-least-once を受容済み」を書く。
  **各クラスの 30 文字以上の根拠には、そのクラス固有の裁定
  (何が重複配信されうるか・なぜ受容できるか) を書かせる**。

## [Warning] ログは固定 event 名が必要。`jobType` / `stage` / `externalCall` は enum 化を検討

- 判断: **一部対応**
- 根拠: 固定 event 名は妥当 (集計可能性)。enum 化は `externalCall` について妥当だが、
  `stage` は既存ドメイン enum (`AnalysisStep` / `RenderStep`) の値そのものであり、
  新 enum を作ると同じ語彙が 2 本になる (AGENTS.md 思考原則 4 に反する)。
- 対応内容:
  - ログに固定識別子 `event = 'job_ownership_lost'` を含める。
  - `App\Enums\Security\ExternalCallKind` を新設 (`LlmCompletion` / `ObjectStoragePut` /
    `StripeInvoiceCreate` / `StripeInvoicePay`)。目録・例外・ログで同じ語彙を共有する。
  - `expectedStatus` / `actualStatus` は `App\Enums\Manual\JobStatus` をそのまま使う。
  - `stage` は既存ドメイン step enum の `->value` を渡す `non-empty-string` とし、
    「新 enum を作らない」理由をコメントに残す。
  - コンテキストに PII (email / name) と外部 payload を含めないことを設計で固定し、
    テストで検証する。

## [Warning] AutoRecharge の 2 回目の再検証で停止を検出した場合、invoice が作成済み・DB 保存済みで残る。収束が確認できない

- 判断: **対応する (設計を追加)**
- 根拠: 妥当。しかも実査すると穴がある — `terminateAndCancel()` の
  `tryTerminateInvoice()` は `stripe_invoice_id === null` のとき
  「invoice 未作成 = 課金され得ない」として true を返す。停止側が
  **こちらの `stripe_invoice_id` 保存より先に**走ると、停止側は invoice を void せず canceled にし、
  こちらは後から invoice_id を保存して no-op する → **誰も void しない open invoice が残る**。
  `reconcile()` の 5 分岐は pending attempt を走査するため、terminal 化済みのこれを拾わない。
- 対応内容: 2 回目の再検証で `Canceled` を検出したときは **pure no-op ではなく
  invoice の best-effort 終端**を行う (Paid / Failed のときは行わない —
  Paid は void できず、Failed は `terminateAndFail` が既に終端済み)。
  終端失敗はログのみ (課金はされていないため実害は Stripe 上の open invoice 1 件)。
  reconcile 5 分岐 (`recovered_paid` / `retried` / `sca_reminded` / `expired` / `triggered`) との
  対応表を詳細設計に書き、Feature テストで収束を固定する。

## [Suggestion] Stripe の各操作に安定した別キーが導出されていることをテストで固定せよ

- 判断: **対応する**
- 根拠: 実装は既に満たしている — `CashierAutoRechargeGateway` が `{base}:invoice` /
  `{base}:item` / `{base}:finalize` / `{base}:pay` と suffix で分離している。
  ただしこれを固定するテストが無い。
- 対応内容: 詳細設計のテスト計画に「4 つの派生キーが互いに異なり、attempt_ulid に pin されている」
  ことを固定する Feature テストを追加する。

## [Suggestion] S5 の AGENTS.md 規約が表す不変条件を、どのテストが保証するか対応付けよ

- 判断: **対応する (詳細設計で)**
- 根拠: 妥当。規約とテストの対応が無い規約は形骸化する。
- 対応内容: 詳細設計の S5 に「規約の各文 → 保証するテスト」の対応表を置く。
