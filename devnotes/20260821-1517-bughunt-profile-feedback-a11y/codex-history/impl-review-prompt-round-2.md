## Round 2: 対応マトリクスと修正内容

Round 1 の全体判定 CHANGES_REQUESTED を受けた対応です。実装本体 (ProfileUpdatedResponse.php / AutoRechargeCard.svelte) は Round 1 で「指摘なし」だったため変更していません。

### [Warning] 表示開始後に「無効なままエラー理由だけが変化する」回帰テストの復元

- 判断: 対応する
- 対応内容: `AutoRechargeCard.test.ts` に「無効のまま別の無効理由に変えると文言と aria-invalid が現在の理由へ追随する」テストを追加した。max を "0"(範囲外)で提示 → "5"(threshold 既定 5 以下=大小関係違反) へ変更し、無効のまま:
  - max spinbutton の accessible description が「開始残高より大きい値」へ更新される
  - 同一 live region 要素 (`getByTestId("auto-recharge-range-error")` を参照保持) の本文も同じ理由へ更新される
  - max は引き続き `aria-invalid="true"`、threshold は aria-invalid が付かない

これにより「初回提示後に文言・aria-invalid を固定してしまう回帰」を検出できる。

### 検証結果

- `pnpm exec vitest run AutoRechargeCard`: 21 passed (追加後)
- `pnpm lint`: 差分なし (整形済み)

### 追加テストの差分 (git diff, tests/js/.../AutoRechargeCard.test.ts)

```diff
diff --git a/tests/js/components/features/billing/AutoRechargeCard.test.ts b/tests/js/components/features/billing/AutoRechargeCard.test.ts
index a29aac5f..e4725662 100644
--- a/tests/js/components/features/billing/AutoRechargeCard.test.ts
+++ b/tests/js/components/features/billing/AutoRechargeCard.test.ts
@@ -110,60 +110,122 @@ describe("AutoRechargeCard", () => {
         expect(screen.getByTestId("auto-recharge-max-amount").textContent).toContain("¥3,500");
     });
 
-    it("不正な入力でもボタンは押せて、押下時にエラーを表示する (禁止事項 #8)", async () => {
+    // F-3-01: 範囲エラーは原因フィールドの spinbutton へ aria-invalid + aria-describedby を配線し、
+    // 巻き込みを避ける (両欄同時 invalid を作らない)。可視の統合 <p> は撤去し、読み上げは
+    // 常在の sr-only polite live region が担う。以下は testId 非依存の利用者視点 assert。
+    const thresholdInput = () =>
+        screen.getByRole("spinbutton", { name: /リチャージ開始残高/ });
+    const maxInput = () => screen.getByRole("spinbutton", { name: /リチャージ後の残高/ });
+
+    it("max の範囲エラーは max spinbutton だけを invalid にする (F-3-01・押下時に提示)", async () => {
         renderCard({ hasPaymentMethod: true });
 
-        const maxInput = screen.getByTestId("auto-recharge-max-input");
-        await fireEvent.input(maxInput, { target: { value: "0" } });
+        // minCount(1) 未満 → parsedMax=null
+        await fireEvent.input(maxInput(), { target: { value: "0" } });
 
         const enable = screen.getByTestId("auto-recharge-enable");
-        expect(enable.hasAttribute("disabled")).toBe(false);
-
+        expect(enable.hasAttribute("disabled")).toBe(false); // 押下でブロックしない (禁止事項 #8)
         await fireEvent.click(enable);
-        expect(screen.getByTestId("auto-recharge-range-error")).not.toBeNull();
+
+        expect(maxInput()).toHaveAttribute("aria-invalid", "true");
+        expect(maxInput()).toHaveAccessibleDescription(/リチャージ後の残高は 1 〜 1000 の整数/);
+        // threshold は巻き込まない (値指定なし。Input は false 時に属性省略)
+        expect(thresholdInput()).not.toHaveAttribute("aria-invalid");
         // エラー時は同意パネルを開かない
         expect(screen.queryByTestId("auto-recharge-consent")).toBeNull();
     });
 
-    it("押下前は範囲エラーを出さない (禁止事項 #8 の契約: 押下時に初めて提示する)", async () => {
+    it("threshold の解析エラーは threshold spinbutton だけを invalid にする", async () => {
         renderCard({ hasPaymentMethod: true });
 
-        await fireEvent.input(screen.getByTestId("auto-recharge-max-input"), {
-            target: { value: "0" },
-        });
+        // 負数 → parsedThreshold=null (非数値文字列は type=number の sanitize が DOM 依存なので使わない)
+        await fireEvent.input(thresholdInput(), { target: { value: "-1" } });
+        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
 
-        expect(screen.queryByTestId("auto-recharge-range-error")).toBeNull();
+        expect(thresholdInput()).toHaveAttribute("aria-invalid", "true");
+        expect(thresholdInput()).toHaveAccessibleDescription(/リチャージ開始残高は 0 以上の整数/);
+        expect(maxInput()).not.toHaveAttribute("aria-invalid");
     });
 
-    it("押下後に値を有効へ直すと範囲エラーが消える (F-3-05: stale invalid を残さない)", async () => {
+    it("個別有効だが max<=threshold のときは max spinbutton だけを invalid にする", async () => {
         renderCard({ hasPaymentMethod: true });
 
-        const maxInput = screen.getByTestId("auto-recharge-max-input");
-        await fireEvent.input(maxInput, { target: { value: "0" } });
+        // threshold=5(既定)・max=3 (1..1000 で個別有効かつ 3<=5) → 大小関係違反は max 側
+        await fireEvent.input(maxInput(), { target: { value: "3" } });
         await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
-        expect(screen.getByTestId("auto-recharge-range-error")).not.toBeNull();
+
+        expect(maxInput()).toHaveAttribute("aria-invalid", "true");
+        expect(maxInput()).toHaveAccessibleDescription(/開始残高より大きい値/);
+        expect(thresholdInput()).not.toHaveAttribute("aria-invalid");
+    });
+
+    it("押下前は aria-invalid が付かない (禁止事項 #8 の契約: 押下時に初めて提示する)", async () => {
+        renderCard({ hasPaymentMethod: true });
+
+        await fireEvent.input(maxInput(), { target: { value: "0" } });
+
+        expect(maxInput()).not.toHaveAttribute("aria-invalid");
+    });
+
+    it("押下後に値を有効へ直すと aria-invalid が消える (F-3-05: stale invalid を残さない)", async () => {
+        renderCard({ hasPaymentMethod: true });
+
+        await fireEvent.input(maxInput(), { target: { value: "0" } });
+        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
+        expect(maxInput()).toHaveAttribute("aria-invalid", "true");
 
         // 値を有効な組み合わせへ直す → 表示中のエラーは現在の入力に追随して消える
-        await fireEvent.input(maxInput, { target: { value: "50" } });
-        expect(screen.queryByTestId("auto-recharge-range-error")).toBeNull();
+        await fireEvent.input(maxInput(), { target: { value: "50" } });
+        expect(maxInput()).not.toHaveAttribute("aria-invalid");
     });
 
-    it("無効のまま別の無効理由に変えると文言が現在の理由へ追随する", async () => {
+    it("sr-only live region は常在し、押下後に本文が出て訂正で消える (可視 <p> 撤去の後退防止)", async () => {
         renderCard({ hasPaymentMethod: true });
 
-        const maxInput = screen.getByTestId("auto-recharge-max-input");
-        // 範囲外 (minCount 未満)
-        await fireEvent.input(maxInput, { target: { value: "0" } });
+        // 同一要素を使い続け、将来 {#if} に戻って要素差し替えになった場合も検出する
+        const liveRegion = screen.getByTestId("auto-recharge-range-error");
+        // (a) 押下前: 属性が生きていて本文は空 (aria-live が消えても素通りしない)
+        expect(liveRegion).toHaveClass("sr-only");
+        expect(liveRegion).toHaveAttribute("aria-live", "polite");
+        expect(liveRegion).toBeEmptyDOMElement();
+
+        // (b) max "0" + 押下後: 本文が単一アクティブ文言で出る
+        await fireEvent.input(maxInput(), { target: { value: "0" } });
         await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
-        expect(screen.getByTestId("auto-recharge-range-error").textContent).toContain(
-            "リチャージ後の残高は",
-        );
+        expect(liveRegion).toHaveTextContent(/リチャージ後の残高は 1 〜 1000 の整数/);
 
-        // 開始残高 (既定 5) 以下 = 大小関係の違反へ理由が変わる
-        await fireEvent.input(maxInput, { target: { value: "5" } });
-        expect(screen.getByTestId("auto-recharge-range-error").textContent).toContain(
-            "開始残高より大きい値",
-        );
+        // (c) 訂正後: 本文が消える
+        await fireEvent.input(maxInput(), { target: { value: "50" } });
+        expect(liveRegion).toBeEmptyDOMElement();
+    });
+
+    it("無効のまま別の無効理由に変えると文言と aria-invalid が現在の理由へ追随する (提示中の追随)", async () => {
+        renderCard({ hasPaymentMethod: true });
+
+        const liveRegion = screen.getByTestId("auto-recharge-range-error");
+        // 範囲外 (minCount 1 未満) → max のみ invalid・「範囲」文言
+        await fireEvent.input(maxInput(), { target: { value: "0" } });
+        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
+        expect(maxInput()).toHaveAccessibleDescription(/リチャージ後の残高は 1 〜 1000 の整数/);
+
+        // 個別有効だが threshold(既定 5) 以下 = 大小関係違反へ理由が変わる (無効のまま)
+        await fireEvent.input(maxInput(), { target: { value: "5" } });
+        expect(maxInput()).toHaveAttribute("aria-invalid", "true");
+        expect(maxInput()).toHaveAccessibleDescription(/開始残高より大きい値/);
+        // 同一 live region 要素の本文も同じ理由へ追随する
+        expect(liveRegion).toHaveTextContent(/開始残高より大きい値/);
+        // threshold は巻き込まない
+        expect(thresholdInput()).not.toHaveAttribute("aria-invalid");
+    });
+
+    it("sr-only live region は threshold 側経路の文言も運ぶ ({maxError ?? \"\"} 誤実装を落とす)", async () => {
+        renderCard({ hasPaymentMethod: true });
+
+        const liveRegion = screen.getByTestId("auto-recharge-range-error");
+        await fireEvent.input(thresholdInput(), { target: { value: "-1" } });
+        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
+
+        expect(liveRegion).toHaveTextContent(/リチャージ開始残高は 0 以上の整数/);
     });
 
     it("canManage=false では両入力が readonly かつ muted になる (F-3-03)", () => {

```
