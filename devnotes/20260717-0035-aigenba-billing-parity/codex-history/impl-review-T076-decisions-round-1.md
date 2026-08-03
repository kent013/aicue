# 対応マトリクス: impl-review-T076 Round 1

対象: `623e32d` (P5 本体) + `a613cee` (P4 残赤の後始末 + pint)
レビュー: `devnotes/20260717-0035-aigenba-billing-parity/impl-review-T076-round-1.md` (gpt-5.3-codex / reasoning=high)
Verdict (Codex): CHANGES_REQUESTED

---

## [Critical] `nearestMonthlyExpiry` 固定 + `amount > 1` で、月次複数期限時に over-grant / no-charge が起きる

- 判断: **一部対応 (可視化 + 機械固定) / アルゴリズム変更は反論して見送る**
- 事実確認: **指摘は factually 正しい**。追加した pin テスト 2 本で実機確認した。
  - `monthly +1 (now+10d)` / `monthly +100 (now+30d)` の org で `reserve(3)` → `consume_expires_at` は
    最短期限 (now+10d) に固定される。commit 後、失効前は `monthlyRemaining = 98` (正しい) だが、
    最短期限の到達で `+1` と `-3` が同時に落ち **`monthlyRemaining = 100`** になる
    (会計上の正値は 98 = 遠い期限バケットから 2 枚消費済)。**2 枚の over-grant**。
  - 同構成で最短期限を跨いで commit すると、遠い期限バケットに 100 枚生きていても
    `ReleasedExpired` で **3 枚まるごと no-charge**。
  - aigenba は amount=1 固定のためズレが最大 1 枚。AI-CUE の amount 一般化で
    最大 `amount − 最短期限バケットの残高` 枚まで**増幅する**という指摘も正しい。
- 根拠 (アルゴリズムを変えない理由):
  1. **実装は設計書どおり**。詳細設計 P5「reserve」節が
     `$consumeExpiresAt = $consumeSource === Monthly ? nearestMonthlyExpiry($org, $now) : null` を
     明示的に契約として書いており、実装はこれを 1 行も逸脱していない。
  2. **窓を閉じる唯一の手段が、設計で明示的に撤回された案**。expiry 粒度で配賦するには
     `consume_monthly_amount` の分割配賦が要るが、設計は「v1 の発明として撤回済み」と
     繰り返し宣言している (P5 冒頭 / reserve 節末尾)。これを復活させるのは設計改訂であり、
     本タスクの禁止事項 (「設計から逸脱しない。逸脱が必要なら勝手に変えず report する」) に当たる。
  3. **現行 AI-CUE では構造的に到達不能**。D28 で `PlanSeeder` の全 tier `monthly_ticket_grant = 0`
     (`PlanSeederPriceInvariantTest` が pin) のため、`StripeWebhookProcessor:347` の guard で
     `invoice.paid` の月次付与は発火しない。有限期限の monthly grant は **org 生涯 1 回の
     signup grant のみ** (`grantSignupGrant` は部分 UNIQUE index で org 単位 1 回)。
     `BughuntBillingSeeder` の 100 枚は `expires_at IS NULL` (無期限) で `nearestMonthlyExpiry` の
     `whereNotNull('expires_at')` に掛からない。よって「生きた有限期限 monthly が 2 本以上」は
     **Filament `PlanResource` で `monthly_ticket_grant` を 1 以上へ戻したときにのみ**成立する。
  4. Codex の「設計レベルの破綻」という位置づけには同意する。ただし直すべき層は実装ではなく
     設計であり、付与契機を触る **P6 (signup grant 契機変更) が自然な改訂点**。
- 対応内容:
  1. `TicketBalanceAccountingTest` に `[既知窓]` の pin テスト 2 本を追加し、
     over-grant (100 vs 正値 98) と ReleasedExpired no-charge を**機械的に固定**した。
     正しい会計値をコメントに併記し、「現行挙動の固定であって正しさの主張ではない」と明記。
  2. `nearestMonthlyExpiry()` の docblock に既知窓・増幅係数・到達条件
     (`monthly_ticket_grant` を戻すと窓が開く) を記載。
  3. 本レポートの deviations / risks に「設計改訂候補」として上申する。
- 未対応で残るもの: 会計そのものの修正 (expiry 粒度の配賦)。**設計改訂事項として P6 へ上申**。

## [Warning] 上記 Critical を固定するテストが不足しており、現テストは空振りし得る

- 判断: **対応する**
- 根拠: 指摘のとおり。追加テスト群は「単一 monthly grant」「単一 source」「TTL / 失効の個別枝」は
  押さえていたが、**複数 expiry を跨ぐ monthly 消費**を直接検証していなかった。
  実装を壊しても green のままになる領域が実在した。
- 対応内容: 上記 pin テスト 2 本で当該領域を塞いだ (10 tests / 25 assertions で green)。
  併せて既存の到達不能性の根拠 (`PlanSeederPriceInvariantTest` の D28 pin) をテストのコメントから
  参照できるようにした。

---

## 指摘されなかったが自己確認した項目 (レビュー観点の網羅確認)

| 観点 | 結果 |
|---|---|
| バケット定義 (`purchased = source='purchased' OR source IS NULL`) | 設計どおり。`sumBalance` の 1 箇所に閉じており、専用テストが「legacy 消費行が帳消しにならない」を固定 |
| clamp の適用順序 (hold 控除の**後**に clamp) | `availableBySource` が `max(sum − holds, 0)`。aigenba `:394-395` と一致 |
| `expires_at > now` の境界 (等号) | grant 側 `> now` / hold 失効側 `<= now` で aigenba と一致。相補で穴も重複も無い |
| `availableTrueBalance` の非負性 (P8a の `quantity <= max_count` の根拠) | `max(…,0)` 2 本の和で**構造的に非負**。docblock に P8a 契約への依存を明記済み |
| status guard 撤去の肩代わり (`consume:{id}` UNIQUE) | `insertIdempotent` が挿入行数を返し、0 行を `Log::warning`。再 commit で消費行 1 行のみをテストが固定 |
| `whereNot(closure)` の 3 値論理 | `whereNotNull('consume_expires_at')` で確定 boolean 化済 (aigenba `:613-623` 同型)。legacy 行 (`consume_source IS NULL`) が誤除外されないことを `TicketLegacyReservationTest` が固定 |
| 禁止事項 #2 (widen / baseline / @phpstan-ignore) | 差分に該当なし (grep 済)。phpstan level 10 は No errors |
| 禁止事項 #4 (`response()->json()` 直書き) | 該当なし。DTO / Inertia のまま |
| セキュリティ不変条件 #7 (2 フェーズ) | `TicketAmountBasedReserveTest` が amount ベース reserve と 2 フェーズの残存を回帰網として固定 |
| 台帳 append-only | commit の消費行は `insertOrIgnore` の insert のみ。update/delete 経路は増えていない |
| Inertia props 形状 | `int` のまま不変 (`->totalAvailable()` を噛ませただけ)。TS 型・`pnpm typecheck` に影響なし |
