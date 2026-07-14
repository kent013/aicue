以下、提示された詳細設計（最小差分: footerリンク1行追加×2 + Vitest契約テスト×2）に対するレビュー結果です。  
（※ご指定どおり、提示テキストのみを根拠に分析）

**全体判定**
- **CHANGES_REQUESTED**

**理由（要約）**
- 実装方針自体は妥当でリスクも低いですが、テスト設計に **順序依存の脆さ** と **Pricing側の import 変更漏れリスク** があり、運用上の安定性観点で修正を推奨します。

---

**施策1: Welcome フッターに特商法リンク追加**  
判定: **APPROVE**
- [Suggestion] `href="/commerce-disclosure"` の直書きは現状パターン（`/terms` `/privacy`）と整合。将来のルート変更耐性を上げるなら、既存方針が許せば共通定数化を検討。
- [Suggestion] DESIGN準拠観点は問題なし（token/class利用、hex直書き増加なし）。
- [Suggestion] Atomic Design責務にも抵触なし（page内 snippet の最小変更）。

**施策2: Pricing フッターに特商法リンク追加**  
判定: **APPROVE**
- [Suggestion] 施策1と同様、実装一貫性は良好。
- [Suggestion] `top -> terms -> privacy -> commerce -> contact` の順序は情報設計として自然。法的リンク群の連続性も確保。

---

**施策3: Welcome フッター法的リンク契約テスト追加**  
判定: **REQUEST_CHANGES**
- [Warning] `getAllByRole("link")` から `href` を抽出し、`["/terms","/privacy","/commerce-disclosure"]` と厳密一致する設計は、将来 footer に別リンクが追加された際に意図せず壊れやすい（ノイズリンク混入で順序や抽出結果が揺れる可能性）。  
  **修正案:**  
  - 法的リンクだけを名前で個別取得して `href` を検証し、  
  - 順序検証は「法的3リンクのDOM順」に限定して行う（例: footer内の法的リンク要素のみ配列化して比較）。
- [Warning] ラベル完全一致のみだと文言微調整（句読点・表記ゆれ）で不要に壊れる可能性。  
  **修正案:**  
  - 契約として文言固定が必要なら現状維持で可。  
  - 柔軟性を持たせるなら `name: /特定商取引法に基づく表記/` の正規表現一致を採用。
- [Suggestion] 「terms/privacy の欠落検知」という狙いは明確で、契約テストの意図は非常に良いです。

**施策4: Pricing フッター法的リンク契約テスト追加**  
判定: **REQUEST_CHANGES**
- [Warning] 提示抜粋上、`within` が未import。設計どおり追加しないとテストがコンパイルエラー。  
  **修正案:**  
  - `import { fireEvent, render, screen, within } from "@testing-library/svelte";` に統一。
- [Warning] 施策3と同じく順序依存の脆さあり。  
  **修正案:**  
  - 法的リンクのみを抽出対象に限定して順序を検証（または `toContain` 系で存在検証 + 最小限の順序検証）。
- [Suggestion] Welcome/Pricingで同一契約を持たせる方針は回帰防止として適切。

---

**レビュー観点別チェック**
- 正確性: ルート既存、リンク追加対象も妥当。null安全性の新規リスクなし。
- 既存整合性: 命名・実装スタイルとも整合。
- PHPStan Lv10: PHP変更なしのため影響軽微。
- テスト計画: 方針は良いが、上記 Warning 修正で安定化推奨。
- DTO/JsonResource: 対象外（フロントリンク追加のみ）で逸脱なし。
- Inertia Props vs API: 対象外、既存 `page.contactUrl` 利用を維持して良い。
- 副作用/後退: 低リスク。主にテスト脆さのみ注意。
- 波及変更: TS型/API Resource/DTO変更不要という判断は妥当。
- セキュリティ: 認可・入力処理・tenant境界に影響なし。AGENTS不変条件に抵触なし。
- DESIGN.md: token/classのみで問題なし。
- Atomic Design: テンプレート責務を壊さず適切。

必要なら次に、**“壊れにくい契約テスト” の具体的なテストコード案（Welcome/Pricing両方）**をそのまま貼れる形で作成します。