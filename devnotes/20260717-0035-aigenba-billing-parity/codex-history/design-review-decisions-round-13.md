# 対応マトリクス: design-review Round 13（CHANGES REQUESTED / Critical 2・Warning 1）

## [Critical] P2/P4 の同値性説明が誤り（`active|trialing + trial終了 + PM無し`）
- 判断: 対応する（**私の説明が誤り**。指摘どおり）
- 対応: **P2 を再生成**し、**DoD から「挙動不変」の主張を撤回**。P2 導入で結論が変わる cohort を **A〜I で全列挙**した:
  - **cohort C（`active/trialing` + `trial_ends_at <= now` + `has_payment_method=false`）: 現行=許可 → P2=遮断**（反転）
  - **cohort D（`past_due` + trial 未終了 or PM 有り）: 現行=遮断 → P2=許可**（反転）
  - 他は不変。`plan_code === null`（cohort I）は移行 OR が保存し **P4 で反転**する。
  → **「P2 の唯一の結論変更は past_due」「P4 分類2が反転の目的」という記述を削除**し、C/D は **P2 の成果物として
  DoD・分類表・テストに載せた**。**施策一覧の P2 行も「挙動不変」から訂正**。
- 併せて実データ露出を接地: `subscriptions.has_payment_method` は aigenba 既定 `false` だが、**既存行は backfill で `true`**
  （AI-CUE の subscription 生成経路は `newSubscription()->checkout()`（mode=subscription）のみで PM 収集必須 = 事実値 true）。
  → **P2 デプロイ時点の cohort C は空**（既存の有償 org は 1 件も締め出されない）。`recordPaymentMethodSnapshot()` は
  monotonic なので backfill 値は保存される。
- **P4 も再生成**し、P4 の判定変更は **移行 OR 1 行の削除（= cohort I の反転）だけ**であることを明示。分類表・DoD・テストを
  P2 の訂正後の事実に合わせた。backfill は **entitlement を PHP で評価して ID 集合を確定 → その集合で UPDATE**（P2 の
  `deriveEntitlement()` と同一定義）とし、**D22 の双方向 ID 集合一致が機械的に成立**する形にした。

## [Critical] stale 境界が重複（`isLivePending` は `>= threshold` / sweeper は `<= threshold`）
- 判断: 対応する（境界時刻ちょうどの行が live かつ Expired 化対象になる。指摘どおり）
- 対応: **sweeper を `< threshold` に変更**し、境界を排他に統一。`staleThresholdAt()` の直上に契約を明記:
  `live: created_at >= staleThresholdAt($now)` / `stale: created_at < staleThresholdAt($now)`（**両者は補集合**）。

## [Warning] v2 原則に反する未決事項が残存（P6 の paid grant 契機・subscription 行 marker / P8a の signup-funding・`ticket_purchases`・Gateway 粒度）
- 判断: 対応する
- 対応: **P6 / P8a を再生成**し、**未決事項を本文の決定へ昇格**。決定は **v2 原則で機械的に導出**した
  （aigenba にあるものは verbatim 移植 / 移植しないなら「**AI-CUE に対象が存在しない（原則 4）**」か
  「**AGENTS.md 抵触（原則 2）**」のいずれかを根拠として明記）。**「機能的に不要」「意味論が崩れる」といった
  私の設計判断は根拠から外した**。
