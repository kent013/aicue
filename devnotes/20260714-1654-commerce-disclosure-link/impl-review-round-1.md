**レビュー結果**

- `resources/js/pages/Welcome.svelte` → **OK**
  - 設計どおり `footerLinks` に `"/commerce-disclosure"` を追加。
  - 位置も **privacy の直後 / contact の直前** で一致。
  - 文言 `特定商取引法に基づく表記` も設計・blade 見出し方針と整合。
  - `class="hover:text-primary"` 踏襲で DESIGN.md 観点（token 経由・hex 直書きなし）に抵触なし。

- `resources/js/pages/Pricing.svelte` → **OK**
  - Welcome 同様、追加先・href・文言・順序が設計どおり。
  - 既存の path 直書き方針（legal link 群）を維持し、一貫性あり。
  - PHP / 認可 / DB / DTO への波及なし（静的リンク追加のみ）。

- `tests/js/pages/Welcome.test.ts` → **OK**
  - `getByRole("contentinfo")` で footer スコープ限定できており、ナビリンク混入を回避。
  - 3リンクの **存在 + href 契約** を個別に検証。
  - 法的リンクのみ抽出して DOM 順固定しており、terms/privacy 欠落や順序変更も検知可能。
  - テストなし実装にはなっていない（禁止事項クリア）。

- `tests/js/pages/Pricing.test.ts` → **OK**
  - `within` import 追加を含め、typecheck 破綻要因を解消。
  - Welcome と同等の契約テストで、欠落・誤href・順序崩れを検知可能。
  - 実装意図（F-2-01 reachability 欠落の再発防止）に対して十分。

**指摘事項**

- **Critical**: なし
- **Warning**: なし
- **Suggestion**: なし（今回スコープでは過不足なし）

**総合判定**

- **APPROVED**