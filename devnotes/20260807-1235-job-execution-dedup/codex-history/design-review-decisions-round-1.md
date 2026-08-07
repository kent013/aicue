# 対応マトリクス: design-review Round 1

## S2 [Critical] LLM 成功後の自前 DB 書き込み (`extracted_json` 保存 / `updateProgress()`) に status guard が無く、terminal 化された行を汚染しうる

- 判断: **対応する**
- 根拠: 妥当。実装を確認した結果:
  - `updateProgress()` は Eloquent `save()` で `step` / `progress` / `updated_at` のみを dirty 更新するため
    **`status` の上書きは起きない** (in-memory の `status` は dirty ではない)。
    しかし `step` / `progress` / `updated_at` が **failed 行に書かれる**のは事実で、
    ジョブ一覧の表示が「failed なのに progress=65」という不整合を持つ。
  - `runDecomposeStep()` の `$job->result_json = …; $job->save();` も同じ。
  - しかも「preflight を置いた設計」が「終端後の自前書き込みを放置」しているのは一貫性を欠く。
- 対応内容: **ジョブ行への進捗書き込みを条件付き UPDATE 化**する。
  private `writeProgress()` を新設し
  `AnalysisJob::query()->whereKey(...)->where('status', running)->update([...])` にする
  (`Builder::update()` が `updated_at` を自動付与するため stale 判定の基準も従来どおり)。
  `runDecomposeStep()` の `result_json` 書き込みも同経路へ寄せる。
  `SourceDocument::extracted_json` は **guard しない** — これは write-only の監査スナップショットで
  状態機械の一部ではなく、guard には join が要る。理由を docblock に残す。
  テスト期待に「cron failed 後に status / step / progress / error が cron の値から変わらない」を追加。

## S2 [Warning] `Log::spy()` はメッセージ文字列依存で壊れやすい

- 判断: **対応する**
- 根拠: 妥当。既存の LLM 再試行 warning と混ざる。
- 対応内容: 検査を**メッセージではなく context の `event` キー**で行う形に明記
  (`shouldHaveReceived('warning')->withArgs(fn ($m, $c) => ($c['event'] ?? null) === ExternalCallKind::LOG_EVENT)`)。

## S3 [Warning] compose 中に cron が failed 化した後、`onClipComposed()` → `updateProgress()` が進捗を書き戻す

- 判断: **対応する**
- 根拠: S2 と同型。compose は長時間なのでむしろこちらの方が起きやすい。
- 対応内容: `RenderPipeline::updateProgress()` も同じ条件付き UPDATE 化。
  テストに「stale 先勝ち後に status / step / progress / error_code / error が cron の値から変わらない」を追加。

## S3 [Suggestion] `DeleteRenderOutputsJob が dispatch されない` は補助契約に留めるべき

- 判断: **対応する**
- 根拠: 妥当。主契約は「S3 に PUT していない」「`output_path` が null」であり、
  dispatch の有無は finalize 実装の内部事情に依存する。
- 対応内容: テスト計画で主契約 / 補助を明示的に分けた。

## S4 [Critical] terminal 行へ `stripe_invoice_id` を後から保存するのは状態機械の例外

- 判断: **対応する (設計変更)**
- 根拠: 完全に妥当。しかも提案の形にすると、当初「残余窓」として受容していた
  「停止が先・保存が後」のケースが**その場で検出できる**ようになる (窓が縮む)。
- 対応内容: `$attempt->forceFill([...])->save()` を
  `TicketAutoRechargeAttempt::query()->whereKey(...)->where('status','pending')
   ->update(['stripe_invoice_id' => $invoiceId, ...])` へ変更し、
  **0 行なら「attempt へ紐付けられなかった invoice」として `$invoiceId` を直接渡して終端**する。
  後始末メソッドのシグネチャを
  `terminateInvoiceAfterOwnershipLost(TicketAutoRechargeAttempt $attempt, string $invoiceId)` にし、
  **DB に保存済みであることに依存しない**形にする。

## S4 [Critical] `recordSuccessfulCharge()` が `grantAutoRecharge()` を先に実行しており「条件付き UPDATE が一回性を担う」と矛盾する

- 判断: **反論する (ただし「明記 + テストで固定」の代替案は採用する)**
- 根拠: 実装を確認した結果、**矛盾ではなく 2 つの異なる冪等キーによる 2 つの不変条件**である:
  - `TicketLedgerService::grantAutoRecharge()` は
    `insertIdempotent($organization, "recharge:{$stripeInvoiceId}", …)` を使う。
    冪等キーは **invoice 単位**で UNIQUE 制約が張られており、
    同じ invoice に対する付与は何度呼んでも 1 回しか入らない (戻り値 0 で検出)。
  - attempt の `where status=pending` conditional UPDATE は **attempt 単位**の 1 遷移を担う。
  - 順序を入れ替える (attempt 遷移を先にして 1 行のときだけ grant) と、
    「**Stripe で課金済みなのにチケット未付与**」という**より悪い**不整合が生じうる
    (canceled 化と実課金が競合した場合、金は取られているので付与が正しい)。
    現行の順序は「取られた金は必ず台帳に載せる」という意図的な設計である。
  したがって **順序は変更しない**。ただし Codex が併記した代替案
  「unique key が二重付与を拒否することを S4/S6 に明記し、テストで固定」は正しく、これを採る。
- 対応内容:
  - S4 の波及変更節に「台帳側の保証 = `recharge:{invoiceId}` の UNIQUE」を明記。
  - S6 の目録で `ExecuteAutoRechargeAttemptJob` を
    `JobDedupGuarantee::ConditionalStatusUpdate` で登録し、根拠文に
    **「付与の一回性は台帳の `recharge:{invoiceId}` UNIQUE、attempt 遷移の一回性は
    `where status=pending` の条件付き UPDATE、と冪等キーが 2 本ある」**ことを書く。
  - Feature テストを追加: 「同一 invoice で `recordSuccessfulCharge()` を 2 回呼んでも
    台帳エントリが 1 件しか増えない」。

## S4 [Warning] `stillPending()` のログ context が `logContext()` とキー集合が揃わない

- 判断: **対応する**
- 根拠: 妥当。集計語彙が割れると「頻度を測る」という目的が達成できない。
- 対応内容: **共通の最小キー集合**を
  `event / job_type / job_id / expected_status / actual_status / stage / external_call` に固定し、
  Billing 側の追加キーは `attempt_ulid` のみに限定する。
  `JobOwnershipLostContextTest` に **Billing 側のキー集合と PII 非包含**の検査も追加する。

## S5 [Warning] `AutoRechargeTriggerJob` が既定接続であることも固定すべき

- 判断: **対応する**
- 根拠: 妥当。将来 T127 で接続 pin が入ると `database.retry_after` との比較が嘘になる。
- 対応内容: `JobExclusionOrderingInvariantTest` に
  「`AutoRechargeTriggerJob` は既定接続である (`connection === null` かつ
  `QUEUED_JOB_LEASE_INVENTORY` の値が null)」という assert を追加し、
  接続 pin が入った瞬間に赤くなるようにする。

## S6 [Critical] `PreflightCheckpoint` の Reflection 検査では「外部呼び出し直前に呼ばれていること」を保証できない (空メソッドでも green)

- 判断: **対応する (主張を弱める)**
- 根拠: 完全に妥当。設計文の「gate が機械検査できる形に揃える」は言い過ぎだった。
  静的に「呼び出し位置」を検査するトークン解析は書けなくはないが、
  `QueuedJobLeaseInventoryTest` 級の複雑さを新たに 1 本抱えることになり、
  費用対効果が悪い (AGENTS.md 思考原則 2)。
- 対応内容:
  - S6 の docblock とテスト名を「**再検証点の存在と戻り型まで**を固定する」に限定して明記。
    「配置 (外部呼び出しの直前であること) の保証は Feature テストの担当」と gate 自身に書く。
  - S4 のリスク節から「gate が機械検査できる形に揃えるため」という表現を削除し、
    「読み手が目録から再検証点を辿れる形に揃えるため」へ改める。
  - S7 の規約↔テスト対応表で、配置の保証行を Feature テストへ割り当て直す。

## S6 [Warning] `class_exists()` による autoload 副作用

- 判断: **見送る (現状維持 + 明記)**
- 根拠: `QueuedJobLeaseInventoryTest` が既に同じ方式で稼働しており、S6 は**その実装を共有する**
  (むしろ 1 本化するのが目的)。ここで方式を変えると既存 gate の振る舞いまで変わり、
  本設計のリスクが増える。token parser への移行は独立した課題。
- 対応内容: `QueuedJobPopulation` の docblock に
  「`class_exists()` による autoload を伴う (既存 `QueuedJobLeaseInventoryTest` の方式を踏襲)」と明記。

## S6 [Warning] `mb_strlen()` の mbstring 前提

- 判断: **見送る (明記のみ)**
- 根拠: `ThrottleCoverageInventoryTest` が既に `mb_strlen()` を使っており、
  根拠文は日本語なので `strlen()` では文字数の意味が変わる (バイト数になる)。
  mbstring は Laravel の必須要件 (`composer.json` の `ext-mbstring`) であり前提を満たす。
- 対応内容: value object の docblock に「根拠文は日本語のため `mb_strlen()` で文字数を数える
  (mbstring は Laravel の必須拡張)」と明記。

## S1 / S7 [Suggestion]

- 判断: **対応する (S7) / 見送る (S1)**
- 根拠: S1 の namespace 移動は「将来 Billing でも例外を共有したくなったら」という条件付きで、
  現時点では Billing は例外を使わない設計なので不要 (AGENTS.md 思考原則 2)。
  S7 の「VERIFICATION_COMMANDS marker / numbering に触れない」は既にテスト計画に入れてあるが、
  AGENTS.md のセキュリティ不変条件の**番号を renumber しない**ことも明記する。
- 対応内容: S7 のテスト計画に「AGENTS.md の既存項番 (セキュリティ不変条件 / ドメイン固有規約 1-5) を
  renumber しない」を追加。
