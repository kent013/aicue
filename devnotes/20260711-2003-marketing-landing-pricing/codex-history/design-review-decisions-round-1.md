# 対応マトリクス: design-review Round 1

## [Critical] 施策3: DatabaseSeeder への TicketVolumePriceSeeder 常時登録の副作用が広範囲
- 判断: 対応する（登録は維持 + 同 PR で全テスト前提の棚卸し・更新を確約）
- 根拠: Seeder 登録は表示だけでなくアプリ機能要件（購入 tier 解決・dev/prod bootstrap）で、seeder 自体が「傾斜単価を使う派生アプリが DatabaseSeeder へ追加して使う」設計のオプトイン正規経路（seeder docblock）。テスト側の明示 seed 方式だと production bootstrap が別途必要になり二重管理になる。
- 対応内容: 設計に「同 PR で `composer test` 全走 → ticket_volume_prices 0 行を暗黙前提にする既存テストを棚卸しし、期待値を更新（削除はしない）。特に `TicketVolumeTierTest` は自前 seed と seeder 行の衝突を確認し、必要なら seeder 呼び出しへ寄せる」を完了条件として明記。実装手順にも「Seeder 登録コミットは単独で全テスト green を確認」を追加。

## [Critical] 施策4: unique 違反後の re-read クエリの orWhere 括弧化漏れリスク
- 判断: 対応する
- 対応内容: 設計のクエリを「2 段の確定クエリ」に差し替え（orWhere を使わない）: (1) `where(org_id)->where(attempt_token)` で自 org スコープの行を引く（UNIQUE(org, attempt_token) で高々 1 行）。(2) 無ければ `where(stripe_session_id)`（global UNIQUE）で引き、**organization_id が自 org でなければ replay せず stale エラー**（cross-org 混線の構造的防止）。設計書に確定コードを記載。

## [Critical] 施策4: metadata の `organization_id` キー名が tenant キー不信と誤読されやすい
- 判断: 対応する
- 対応内容: metadata キーを `org_ref` に改名し、「照合専用。認可・org 解決の判断には一切使わない（真実源は ticket_checkout_sessions 行）」を設計・実装コメントの双方に明文化。webhook 側の照合コードも `metadata.org_ref` に変更。

## [Warning] 施策2: contactUrl への `?source=` 直結が既存 query を壊す
- 判断: 対応する
- 対応内容: `ContactUrl` サービスに `resolveForSource(InquirySource $source): string` を追加（内部 path のときのみ `?`/`&` を判定して source を安全に付与。外部/mailto は付与しない）。Home/Pricing 両 controller はこのメソッドを使う。`ContactUrl` の既存ユニットテストにケース追加（波及変更に追記）。

## [Warning] 施策3: maxStorageGb の変換規則未定義
- 判断: 対応する
- 対応内容: `intdiv($bytes, 1024 ** 3)`（GiB 切り捨て）で確定し設計に明記。Feature/Vitest で固定（free=1 / standard=50）。

## [Warning] 施策4: attempt_token の render 毎再発行で stale が増える UX 懸念
- 判断: 見送る（根拠つき）
- 根拠: 別 token でも live pending dedup が同 count なら同一 session を replay するため、再描画で新 token になっても直前の購入は正しく再開される。stale 経路に入るのは「同一 token のまま count を変えて再送」した場合のみで、エラーメッセージが再読み込みを明示誘導する。aigenba の resume window（token 安定化）は概念レビューで v1 スコープ外と合意済み（UX 最適化であり冪等性の必要条件ではない）。

## [Warning] 施策5: amount_subtotal 照合が promo/tax 有効化で将来破綻
- 判断: 対応する
- 対応内容: (1) `CashierTicketCheckoutGateway` の payload 構築を組み立てメソッドに分離し、「`allow_promotion_codes` / `automatic_tax` を含まない」ことをユニットテストで invariant 固定（tests/Feature/Billing/TicketCheckoutTest 内 or 専用ユニット）。(2) 設計に移行条件を追記: 「promo/tax を有効化する場合は照合式を amount_total 系へ移行し、この invariant テストを更新することが変更の入口」。

## [Warning] 施策7: PricingPageTest 期待値のコミット境界依存
- 判断: 対応する
- 対応内容: `PricingPageTest` の standard 価格期待値はリテラルではなく **seed 済み `plan_prices` 行から導出**（`Plan::currentPrice(Base)->amount` と props の一致を検証）に変更。施策7 のコミットで PricingPageTest の修正が不要になる（二重修正回避）。

## [Suggestion] TS interface の readonly 化 → 対応（`readonly` プロパティで定義。既存 types と流儀が揃う範囲で）
## [Suggestion] PricingService メモ化の singleton リーク注記 → 対応（「本サービスはデフォルト解決（非 singleton）。singleton 登録しないこと」をクラス docblock に明記）
## [Suggestion] terminal-ack 運用 Runbook → 対応（`docs/architecture.md` の課金節に「terminal failure の確認手順（failure_reason 参照 → 手動 grantPurchased 判断）」を 1 段追記）
## [Suggestion] insufficient-tickets type guard の境界 → 対応（`components/features/manual/` 配下に置き、atomic-import-graph の単方向規約でドメイン外 import を機械防止済みであることを設計に注記）
