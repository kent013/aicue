# design-review Round 2: Round 1 指摘への対応報告

全 Critical / Warning に対応（1 件は根拠つきで見送り）。detailed-design.md へ反映済み。再判定を依頼する。

## 対応マトリクス

### [Critical] 施策3: DatabaseSeeder への常時登録の副作用管理 → 対応（登録維持 + 同 PR で棚卸し確約）
- Seeder 登録はアプリ機能要件（購入 tier 解決・dev/prod bootstrap）で、seeder docblock が定める「傾斜単価を使う派生アプリが DatabaseSeeder へ追加する」正規オプトイン経路のためテスト側 seed 方式は採らない（production bootstrap の二重管理になる）。
- 代わりに修正案の後段を採用: **Seeder 登録を単独コミットにし、その時点で `composer test` 全走 → `ticket_volume_prices` 0 行を暗黙前提にする既存テストを棚卸しして期待値更新（削除・上書きはしない）** を施策3 のテスト計画・完了条件に明記。`TicketVolumeTierTest` の自前 seed と partial UNIQUE の衝突確認も明記。

### [Critical] 施策4: re-read クエリの orWhere 括弧化事故リスク → 対応
- orWhere を廃し **2 段の確定クエリ** を設計書に確定コードで記載: (1) `where(organization_id)->where(attempt_token)`（UNIQUE で高々 1 行）→ (2) 無ければ `where(stripe_session_id)`（global UNIQUE）で引き、**organization_id ≠ 自 org なら null 扱い（絶対に replay しない）**。cross-org 混線を構造的に防止。

### [Critical] 施策4: metadata `organization_id` が tenant キー不信と誤読される → 対応
- metadata キーを **`org_ref`** に改名。「照合専用・認可や org 解決の判断には一切使わない（真実源は ticket_checkout_sessions 行 → organization relation）」を Service / webhook 双方の設計コードコメントに明文化。webhook 照合も `metadata.org_ref` に変更。

### [Warning] 施策2: contactUrl の `?source=` 直結が既存 query を壊す → 対応
- `ContactUrl::resolveForSource(InquirySource $source): string` を追加（内部 path のみ `str_contains($url, '?')` で `?`/`&` を使い分けて付与。外部/mailto は `resolve()` と同値）。Home/Pricing 両 controller で使用。ContactUrl テストに 3 ケース追加（既定 / query 既存 / 外部・mailto）を波及変更へ明記。

### [Warning] 施策3: maxStorageGb 変換規則未定義 → 対応
- `intdiv($bytes, 1024 ** 3)`（GiB 切り捨て）で確定。free=1 / standard=50 を Feature/Vitest で固定。

### [Warning] 施策4: attempt_token の render 毎再発行の stale UX → 見送り（根拠）
- 別 token でも live pending dedup が同 count なら同一 session を replay するため、再描画後も直前の購入は正しく再開される。stale に入るのは「同一 token のまま count を変えて再送」のみで、エラーメッセージが再読み込みを明示誘導する。aigenba の resume window（token 安定化）は概念レビューで v1 スコープ外（UX 最適化であり冪等性の必要条件ではない）と合意済み。

### [Warning] 施策5: amount_subtotal 照合の将来破綻 → 対応
- gateway の payload 構築を `buildSessionPayload()` に分離し、**`allow_promotion_codes` / `automatic_tax` を含まないことをユニットテストで invariant 固定**。移行条件を設計に追記: 「promo/tax を有効化する場合は照合式を amount_total 系へ移行し、この invariant テストの更新が変更の入口」。

### [Warning] 施策7: PricingPageTest 期待値のコミット境界依存 → 対応
- `PricingPageTest` の standard 価格検証を **seed 済み `Plan::currentPrice(Base)->amount` からの導出**（リテラル非依存）に変更。施策7 コミットで PricingPageTest の修正が不要。

### [Suggestion] TS readonly 化 → 対応（marketing.ts / billing.ts の全 interface プロパティを readonly）
### [Suggestion] PricingService メモ化の singleton 注記 → 対応（docblock に「非 singleton 前提・singleton 登録禁止」を明記）
### [Suggestion] terminal-ack 運用 Runbook → 対応（docs/architecture.md 課金節に failure_reason 確認 → 手動 grantPurchased 判断の手順を 1 段追記、施策4 のドキュメント変更に含めた）
### [Suggestion] type guard の境界 → 対応（`components/features/manual/insufficient-tickets.ts` に配置。features/{domain} は atomic-import-graph で他ドメイン import が機械禁止であることを設計に注記）

再判定（APPROVED / CHANGES_REQUESTED）を依頼する。残指摘があれば [Critical]/[Warning]/[Suggestion] で提示してほしい。
