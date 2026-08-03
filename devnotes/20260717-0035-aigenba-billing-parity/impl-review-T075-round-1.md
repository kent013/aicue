**全体判定**
- **CHANGES_REQUESTED**

**総評（設計適合の確認）**
- `BillingAccess` の移行 OR 削除と `state()->grantsAccess()` 一本化は設計どおりです（`app/Services/Billing/BillingAccess.php:46`）。
- `plan_code` 非 null の結論を P4 で変えない、という意図は実装上は守られています（同上）。
- backfill の対象導出を SQL 述語でなく `deriveEntitlement()` に寄せた点は、D22 の思想に整合しています（`database/migrations/2026_07_17_000300_backfill_grandfathered_free_plan_code.php:82`）。

**指摘**
- [Critical] **「backfill 失敗時にゲート反転しない」保証がコード単体では未充足です。**  
  `BillingAccess` 反転（`app/Services/Billing/BillingAccess.php:46`）と backfill migration（`database/migrations/2026_07_17_000300_backfill_grandfathered_free_plan_code.php:49`）が同一リリースに同居しており、配備順が「新コード有効化→migrate」だと一時締め出しが発生し得ます。  
  **修正案**:  
  1) デプロイを2段階化（先に backfill 完了確認、次リリースで OR 削除）、または  
  2) migration 完了マーカー（例: 設定テーブル）を `BillingAccess` 側で確認し、完了前は旧判定を維持。

- [Warning] **declarer-less を“前提”にしており、migration 自体で不変化していません。**  
  更新時に `personal_declared_by_user_id` / `personal_declared_at` を明示的に `NULL` 化していないため、既存データに揺れがあると「declarer-less grandfathering」を構成的に保証できません（`database/migrations/2026_07_17_000300_backfill_grandfathered_free_plan_code.php:56`）。  
  **修正案**: update 配列に  
  - `personal_declared_by_user_id => null`  
  - `personal_declared_at => null`  
  を明示追加して、仕様をデータで固定してください。

- [Warning] **fixture 既定変更（grandfather 既定 true）が不変条件テストを弱めるリスクがあります。**  
  `tests/Pest.php` の既定値変更は妥当ですが、「未契約を検証すべきテスト」が暗黙 grandfather で通ってしまう穴が生まれやすいです（`tests/Pest.php:118`）。  
  **修正案**:  
  - gate/onboarding 系で `grandfatherFreePlan: false` を必須化するヘルパーを分離  
  - もしくは “未契約前提テスト” を列挙する回帰テストを1本追加して固定。

- [Suggestion] `routes/web.php` のコメントに旧挙動（billing redirect + error flash）由来の文言が残っており、現実装（onboarding 遷移）とやや不整合です（`routes/web.php:376`）。コメントを現行仕様に寄せると保守性が上がります。