# 対応マトリクス: conceptual-review Round 2（CHANGES_REQUESTED / Critical 2・Warning 3）

## [Critical] 2-1 P6 が AGENTS.md の課金不変条件（reserve→commit/release の 2 フェーズ）に抵触

- 判断: **対応する（ただし指摘の前提となった私の設計文が事実誤認だった。訂正して衝突を消す）**
- **重大な自己訂正**: 概念設計の「source 分割台帳 + reserve/commit を撤去して単一合計へ移行」は**事実誤認**だった。
  監査 finding を私が要約する際に取り違え、誤った前提を設計に持ち込んだ。実コードで検証した事実:
  - **aigenba の `TicketService` は reserve/commit/release を持つ**（`reserve(Organization, encounterId)` L349 /
    `commit()` L465 / release / `TicketCommitResult` enum）。撤去などしていない。
  - **source 分割も aigenba にある**（`App\Enums\CreditSource: PlanMonthly / Purchased`。
    `countActiveReservations($org->id, CreditSource::PlanMonthly, $now)` 等）。
  - **AI-CUE にも両方ある**（`TicketSource::Monthly/Purchased`、`reserve()` L248 / `commit()` L287 / `release()` L311）。
  - **本当の差分は逆向き**: **単一 int 残高なのは AI-CUE の方**。`TicketLedgerService::balance()` は
    `SUM(未失効 delta) − SUM(reserved)` の単一 int で、**docblock 自身が「全額失効として保守的に働く」と近似を明記**。
    aigenba は per-bucket 会計（`TicketBalanceDto{monthlyRemaining, purchasedRemaining, totalAvailable,
    activeReservations, nextExpireAt}`、消費優先 monthly→purchased、reserve 時に consume_source/consume_expires_at を
    予約行へ固定、commit-wins セマンティクス）。
- 結論: **AGENTS.md 不変条件 #7 との衝突は存在しない**。両者とも 2 フェーズを持ち、本設計も**維持する**。
  Codex の懸念は正当（私の記述が本当なら不変条件違反だった）であり、指摘に感謝して**設計文を事実へ訂正**する。
- 対応内容: F3 の定義を「**aigenba 化 = 台帳の置換ではなく、残高会計の精緻化**」へ全面書き換え。
  「撤去」「単一合計へ移行」「台帳→単一合計の移行アルゴリズム」の記述を削除し、**reserve→commit/release の 2 フェーズ維持**を
  明文化する。

### 併せて確定する接地された例外（ドメイン境界）

`aigenba の reserve(encounterId) = 1 encounter 1 枚` は **AI-CUE に移植できない**。AI-CUE の消費は
`AnalysisPipeline` / `RenderPipeline` の **可変コスト（`reserve(org, $cost)`）** であり、監査 finding 自身が
「消費対象も aigenba=訓練 encounter、AI-CUE=manual 解析/レンダジョブ($cost 可変)で**製品ドメインが異なる**」と記録している。
1 encounter=1 枚を機械移植すると AI-CUE の課金が壊れる（解析とレンダで単価が異なる前提を潰す）。
→ **amount ベース reserve を維持する**（これは「無駄な独自実装」ではなく AI-CUE のプロダクト要件に由来する差分）。
本例外はユーザーへ明示報告する。

## [Critical] 3-1 P6 の rollback は成立しない（cutover 後 revert すると残高が失われる）

- 判断: **対応する（指摘は正しい。ただし 2-1 の訂正により前提そのものが消える）**
- 根拠: Codex の指摘「物理削除しないことと復帰可能であることは別」は完全に正しい。ただし 2-1 の事実訂正により
  **「台帳の置換 = cutover」自体が存在しない**。AI-CUE は既に source 列付き ledger + reserve/commit + 冪等キー +
  `ticket_purchases` 逆仕訳を持っており、aigenba とのギャップは**主に読み取り側の会計**（per-bucket 集計・
  per-source 失効・消費優先）+ 予約行への consume_source/consume_expires_at 固定（**additive な列追加**）である。
- 対応内容: 「残高移行 + 検証」→「書き込み切替（cutover）」の 2 フェーズ構成を**廃止**し、単一フェーズ
  「**チケット残高会計の精緻化**」へ再編。二重書き・差分再同期 runbook は**不要**（並走を残さない = 思考原則にも合致）。
  rollback は「additive 列 + 読み取り計算の変更」であるため**コード revert で復帰可能**（旧コードは新列を無視するだけ）。
  「revert で復帰可能」の主張は、この訂正後の構成に限って成立する（Codex の要求どおり、成立しない主張は残さない）。
- なお精緻化の方向は**ユーザー不利にならない**（現行は保守的近似 = 過小評価。正確化は残高が増える方向にのみ動く）ことを
  invariant テストで担保する。

## [Warning] 3-2 「同一 PR」は backfill がゲートコードより先に本番適用されることを保証しない

- 判断: 対応する
- 対応内容: P4 の DoD に**デプロイ順序**を固定: `列/index 追加 → marker/grandfather backfill 完了・件数検証 →
  ゲートコード deploy`。**backfill 失敗時はゲートを反転しない**ことも DoD に明記。

## [Warning] 4-1 grandfathered org の「自然収束」は保証されない

- 判断: 対応する（指摘どおり。既に業務ルートへ到達できるユーザーには再 activate の動機が無い）
- 対応内容: 「自然に収束する」を**削除**。濫用防止の主要効果は**新規 org に限定**と明記し、既存 grandfathered の収束は
  **将来タスクまたは明示的な再申告導線による**と限定する。

## [Warning] 5-1 free activate と paid webhook の競合で二重付与しうる

- 判断: 対応する（marker を真実源にするだけでは競合を防げない、は正しい）
- 対応内容: 不変条件を明記: **org 行の `lockForUpdate()`（または原子的な条件付き更新）を起点に、marker 更新と
  ticket 付与を同一 transaction に置く**。free/paid 競合テストを P1 と grant 契機変更フェーズへ追加。

## [Suggestion] 6-1 / 6-2 本文の不整合

- 判断: 対応する
- 対応内容: 「依存順の 7 フェーズ」→ 9 フェーズ（2-1/3-1 の再編で **8 フェーズ**へ変更）に修正。
  制約欄「既存ユーザーを締め出さない（P3 移行）」→ **P4 移行**の誤記を修正。

## [Suggestion] 7-1 grandfathered 状態を EffectivePlan variant に閉じ込める

- 判断: 対応する（方針維持）
- 対応内容: nullable 値の組合せを各所で解釈せず、`EffectivePlan` の variant（activated personal / paid subscription /
  grandfathered legacy free）として閉じ込める方針を P2 の DoD に維持・明記。
