**総評**
- 実装の大枠は P8b の所管境界に沿っており、`Billing/Plans` 新設・`PurchaseTickets` 状態機械・`Billing/Index` の DTO 化・`Guest/Pricing` 移設は概ね設計どおりです。
- ただし、**不変条件をテストで固定し切れていない点が1件（Critical）**、および将来の回帰を招きやすい設計ズレが複数あります。

**[Critical]**
- `billing.plans` の課金ゲート allowlist 不変条件がテストで固定されていない  
  - 根拠: `routes/web.php:321` に `GET /billing/plans` 追加。`tests/Feature/Billing/BillingPlansPageTest.php` は free 既定組織中心で、`NoSubscription` での 200 を固定していません（提示文中の指摘どおり `GateInversionF07RegressionTest` dataset 未追加）。  
    設計根拠: P8b「主要な契約（allowlist 内、group 外構造）」「レビュー観点 6（不変条件テスト固定）」。  
  - 失敗シナリオ: 将来 `/billing/plans` が誤って購読必須側へ移動しても、free 既定テストは通過し、`no_subscription` 組織だけ本番で 302/403 になります。  
  - 修正案:  
    - `GateInversionF07RegressionTest` の allowlist dataset に `billing.plans` を追加。  
    - `BillingPlansPageTest` に `createOrganizationWithOwner(grandfatherFreePlan: false)` で `GET /billing/plans` が 200 のケースを追加。

**[Warning]**
- `resolveResumablePurchase` が `isLivePending()` を使わず条件を再実装  
  - 根拠: `app/Services/Billing/TicketCheckoutService.php:99` 以降で `status/expires_at/checkout_url` 条件を直書き。  
    設計根拠: P8b(b)「live pending 判定は既存 `TicketCheckoutSession::isLivePending()` を使う」。  
  - 失敗シナリオ: モデル側判定が将来更新された際にここだけ乖離し、`resume` 判定だけ古い仕様で動く。  
  - 修正案: `isLivePending()`（または同等の model scope）を単一出典にして参照。

- `purchased` 成功バナーと `formState='resume'` が同時表示されうる  
  - 根拠: `app/Http/Controllers/Billing/TicketPurchaseController.php:69`（`purchased` 判定）と同ファイル `:78` 以降（`formState` 判定）が独立。`resources/js/pages/Billing/PurchaseTickets.svelte:140` 以降で双方表示。  
    設計根拠: P8b tc-5 状態機械（相互作用未規定）、P9 所管分離。  
  - 失敗シナリオ: 支払い直後 webhook 未反映時に「購入完了」と「決済を続ける」が同時に出て誤誘導。  
  - 修正案: 暫定で `purchased=true` を優先して `completed` 扱いに寄せる（または `resume` バナー抑制）し、P9 で正式統合。

- 現在プラン解決が「公開プラン一覧のみ」依存で、契約中なのに `plan=null` 表示になりうる  
  - 根拠: `app/Http/Controllers/Billing/BillingController.php:305` `resolveCurrentPlan()` が `PricingService::listPublicPlans()` 線形探索のみ。`resources/js/pages/Billing/Index.svelte:110` で `plan=null` を未契約文言表示。  
    設計根拠: P8b(d) 現在プラン表示契約。  
  - 失敗シナリオ: 将来 `is_active=false` 化した旧プラン契約組織に「未契約」と誤表示。  
  - 修正案: 表示用は `plan_code` 直接解決（非公開含む）にフォールバックを持たせる。

- `Billing/Plans` の validation エラーが別プラン操作へ残留  
  - 根拠: `resources/js/pages/Billing/Plans.svelte:30` で `errors.plan_code` をグローバル derive。  
    設計根拠: P8b(a)「validation error は dialog 内表示」。  
  - 失敗シナリオ: プランA失敗後、プランBダイアログを開いても古いエラーが即表示。  
  - 修正案: `onError` でローカル state に写し、`openConfirm/closeConfirm` で明示クリア。

**[Suggestion]**
- `月額 ¥—` 表示の文言改善  
  - 根拠: `resources/js/pages/Billing/Index.svelte:93`（`formatYen(null)` が `—`）。  
  - 失敗シナリオ: 価格不明時に不自然な表示。  
  - 修正案: `baseAmountJpy===null` 時は「料金はお問い合わせください」等に分岐。

- `ConfirmDialog` `banner` 追加の DESIGN.md 同期  
  - 根拠: `resources/js/components/organisms/ConfirmDialog.types.ts:17` 追加、DESIGN.md更新差分なし。  
  - 失敗シナリオ: 仕様の正本が分散し、利用側が機能を見落とす。  
  - 修正案: DESIGN.md の ConfirmDialog 節に 1 行追記。

- bug-hunt ストーリー文言の陳腐化解消  
  - 根拠: `.claude/skills/app-bug-hunt/screens.md:18` は更新済みだが、提示どおり `stories/S5-billing.md` が旧「月次付与枚数」前提。  
  - 失敗シナリオ: 探索観点が実装とズレ、誤検知/見落とし増加。  
  - 修正案: S5 を P8b/D28 後の語彙（per-bucket 残高・プラン比較導線）へ更新。

**実装者の9論点 判定**
- 1) `purchased` × `resume` 衝突: **Warning**（UX誤誘導、P9まで完全解決待ちでも暫定抑制推奨）
- 2) 2段目 completed 窓: **問題なし**（今回の正本P8b準拠を優先）
- 3) `isLivePending()` 不使用: **Warning**（単一出典違反）
- 4) `billing.plans` allowlist 固定テスト不足: **Critical**
- 5) `resolveCurrentPlan()` 線形探索: **Warning**（将来の誤表示リスク）
- 6) `月額 ¥—`: **Suggestion**
- 7) エラー残留＋空プラン名瞬間表示: **Warning**（前者）+ **Suggestion**（後者微小 flicker）
- 8) `ConfirmDialog.banner` additive: **Suggestion**（実装は妥当、文書同期のみ）
- 9) bug-hunt inventory/stories ドリフト: **Suggestion**（運用品質の問題）

必要なら、このまま **「最小修正パッチ案（テスト追加 + Plansエラーstate局所化 + purchased/resume優先ルール）」** まで具体化して提案します。