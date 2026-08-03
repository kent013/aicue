**全体判定**
- **CHANGES_REQUESTED**

**施策別レビュー**
- **施策1（enum集約）: APPROVE**
  - [Suggestion] `BillingFeedbackDto` の `@phpstan-type` は手書き union ではなく `value-of<BillingFeedbackKind>` 化を検討（将来の enum 追加漏れ防止）。  
  - 参照: `app/Enums/Billing/BillingFeedbackKind.php:1`, `app/DataTransferObjects/Billing/BillingFeedbackDto.php:1`

- **施策2（canonical helper）: APPROVE**
  - [Suggestion] `PRESERVED_LANDING_QUERY` の意図（`highlight` 以外を保持しない理由）を docblock に1行追記すると将来の query 追加時に安全。  
  - 参照: `app/Http/Controllers/Billing/BillingController.php:378`

- **施策3（query→303+flash）: REQUEST_CHANGES**
  - [Warning] `?session_id=`（空）や `?session_id[]=`（不正型）で canonical 化されず、設計本文の「着地 query は必ず畳む」と不一致。  
    **修正案:** 判定を「有効値の有無」ではなく「キー存在」に変更（例: `$request->query->has('session_id')` で landing 判定し、kind 判定は別で fail-closed）。kind `null` でも 303 canonical は維持。  
  - [Warning] `error` 継続条件が `is_string(session('error'))` 固定だと、将来 string 以外のエラー表現へ拡張した際に取りこぼしうる。  
    **修正案:** `session()->has('error')` ベースで `keep(['error'])` し、表示可否は現行どおり feedback 抑止。  
  - 参照: `app/Http/Controllers/Billing/BillingController.php:442`

- **施策4（replayed/retry廃止）: APPROVE**
  - 指摘なし（one-shot 方針と整合、並走実装なし）。  
  - 参照: `app/Http/Controllers/Billing/BillingController.php:240`

- **施策5（Svelteコメント修正）: APPROVE**
  - 指摘なし（実装責務の説明として適切）。  
  - 参照: `resources/js/pages/Billing/Index.svelte:41`

- **施策6（architecture明記）: APPROVE**
  - 指摘なし（副作用境界・優先順位・one-shot定義が明文化されている）。  
  - 参照: `docs/architecture.md:286`

- **施策7（テスト固定）: REQUEST_CHANGES**
  - [Warning] 施策3の境界（空/不正型 `session_id` でも canonical 303）を固定するテストが未明記。  
    **修正案:** `BillingFeedbackTest` に dataset 追加（`session_id=`, `session_id[]=`）して `assertStatus(303)` + `assertRedirect('/billing')` + `assertSessionMissing(FLASH_KEY)`。  
  - [Suggestion] `?portal` + `error` ケースは redirect 後の Inertia props で `flash.error` 到達まで確認すると `keep()` の実効保証が強化できる。  
  - 参照: `tests/Feature/Billing/BillingFeedbackTest.php:1`

この2点（施策3/7）を直せば、設計全体はかなり堅く、AGENTS.md の one-shot / fail-closed / DTO 方針に合致しています。