**全体判定: CHANGES_REQUESTED**

施策ごとにレビューします（設計品質は高く、特に冪等性と fail-closed 方針は良いです）。

**施策1: マーケ DTO 基盤 + InquirySource 拡張**  
判定: **APPROVE**
- [Suggestion] `resources/js/types/marketing.ts` の `PricingPageProps` は将来の accidental widening 防止のため `type` + `readonly` 化を検討（必須ではない）。

**施策2: LP（トップ）実装**  
判定: **APPROVE**
- [Warning] `contactUrl .= '?source=landing'` は既存 query がある内部URLで壊れる可能性。  
  修正案: 内部URLでも `parse_url` ベースで query をマージするヘルパ（または `UrlBuilder`）を利用し、`?`/`&` を安全に処理。
- [Suggestion] `lowPriceJpy=0` は要件整合しているが、`seo.default_description` とLP本文の表現乖離を避けるため文言同期チェックをテストに入れるとよい。

**施策3: 料金表実装**  
判定: **REQUEST_CHANGES**
- [Critical] `DatabaseSeeder` へ `TicketVolumePriceSeeder` 常時登録は、既存テストの暗黙前提を広範囲に変え副作用が大きい。  
  修正案: `DatabaseSeeder` 直追加ではなく、課金系 feature test 側で明示 seed（`$this->seed(TicketVolumePriceSeeder::class)`）に寄せるか、既存テスト棚卸し後に同PRで全前提更新を確約。
- [Warning] `maxStorageGb` の bytes→GiB 変換規則（切り上げ/切り捨て）が未定義。UIとテストでズレる。  
  修正案: 変換規則を設計書に明記（例: `intdiv(bytes, 1024**3)`）し、Feature/Vitest で固定。
- [Suggestion] `PricingService` の request 内メモ化は有効だが、singleton 化された場合のリーク防止コメントを追加すると保守しやすい。

**施策4: チケット購入バックエンド（冪等 Checkout）**  
判定: **REQUEST_CHANGES**
- [Critical] `UniqueConstraintViolationException` 捕捉後の再読クエリ例に `where(...)->orWhere(...)` の括弧化漏れ注意が記載されており、実装事故リスクが高い。  
  修正案: 設計段階で確定クエリを明示（`where(function($q){...})`）して cross-org 混線を防止。
- [Critical] metadata に `organization_id` を Stripe 側へ送る設計は「tenantキー不信」と矛盾しやすい（照合専用でも誤読されやすい）。  
  修正案: metadata の org 情報は `org_ref` 等の非権限キー名に変更し、「認可判断に使わない」注記を設計に明文化。真実源は `ticket_checkout_sessions` 固定。
- [Warning] `attempt_token` を show 時に毎回再発行すると、画面再描画で stale が増えるUX懸念。  
  修正案: 初回表示トークンを保持し、明示的再試行時のみ再発行（または stale 時に自動再読込導線）。

**施策5: webhook 冪等付与**  
判定: **APPROVE**
- [Warning] `amount_subtotal === count * unit_amount` は割引/税設定変更時に将来破綻しやすい。  
  修正案: 本設計で promo/tax 無効を invariant test 化し、もし有効化する将来は検証式を `amount_total` 系へ移行する移行条件を追記。
- [Suggestion] terminal-ack の `report()` 強化は妥当。運用Runbook（誰が何分以内に確認）も docs に1段追記推奨。

**施策6: 購入フロント + 残高不足導線**  
判定: **APPROVE**
- [Suggestion] `isInsufficientTickets` type guard を共通化する方針は良い。`components/features/manual/` 配下に置く場合、他ドメインからimportされないよう境界だけ注意。

**施策7: 価格改定（独立コミット）**  
判定: **APPROVE**
- [Warning] 施策3の `PricingPageTest` 期待値（1980→4980）の切替タイミングがコミット境界に依存。  
  修正案: テストを「施策7コミットで更新」と明記し、施策3時点は定数参照化して二重修正を回避。

**総評**
- 強み: 設計の一貫性、冪等性、fail-closed、Controller薄型、DTO/型意識は非常に高水準。  
- 変更要求の主眼: **施策3のSeeder副作用管理** と **施策4のクエリ安全性/tenantキー誤用防止**。  
この2点を設計に確定反映できれば、全体は **APPROVED 相当** まで上がります。