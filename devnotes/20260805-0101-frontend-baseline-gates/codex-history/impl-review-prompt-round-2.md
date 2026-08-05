Round 1 の指摘への対応が完了した。全体判定は APPROVED だったが、
[Warning] と [Suggestion] の両方に対応したので確認してほしい。

## 対応マトリクス

### [Warning] `fg: string` / `bg: string` 注釈による局所的 widen

**対応した。代替案 (filter 削除 + 専用テスト) を採用。**

理由: 指摘のとおり、あの filter は現時点で両集合が素なため実行時に一度も枝刈りしていない
no-op であり、「将来の防御」を型 widen と引き換えに買っているのが割に合わない。
そこで暗黙の防御を **名前の付いた不変条件へ格上げ**した:

1. `PAIRS` から自己ペア除外 filter を削除 → 素直な直積に。型注釈が一切不要になり widen 消滅
2. 「面ロールとテキストロールが素である」を独立テストとして追加。
   `new Set<string>(SURFACE_ROLE_TOKENS)` + `.has()` なのでキャストも widen も無し
3. 負のコントロール実測: `TEXT_ON_SURFACE_TOKENS` に `surface` を混ぜると
   「SURFACE_ROLE_TOKENS と TEXT_ON_SURFACE_TOKENS が重複している: surface」で fail することを確認済み

### [Suggestion] mail theme を対象外と明記

**対応した。** 設計書は APPROVED 済みの履歴文書なので遡って書き換えず、
正しい理由をコード側 (`contrast-invariant.test.ts` の「検査しないもの」節) に残した。
(a) Laravel 同梱の独立パレットで DESIGN.md トークンの写像ではない
(b) メール HTML は CSS 変数非対応クライアントが多く DS token 化には別設計が要る
(c) 設計書の「danger は含まれない」は事実誤認だが結論は変わらない

## 修正後の実測

- `pnpm typecheck` = exit 0 (**widen 無しで通過**)
- `pnpm lint` = exit 0
- `pnpm test` = **108 files / 1000 tests passed, 0 failed** (contrast-invariant は 25 → 26 tests)

## 修正差分 (contrast-invariant.test.ts のみ)

```diff
diff --git a/tests/js/architecture/contrast-invariant.test.ts b/tests/js/architecture/contrast-invariant.test.ts
new file mode 100644
index 0000000..72e733e
--- /dev/null
+++ b/tests/js/architecture/contrast-invariant.test.ts
@@ -0,0 +1,154 @@
+import { describe, it, expect } from "vitest";
+import { designColors } from "../styles/design-md";
+import {
+    COLOR_TOKEN_MAP,
+    CONTRAST_EXEMPT_TOKENS,
+    FILL_LABEL_TOKENS,
+    FILL_TOKENS,
+    PENDING_CONTRAST_PAIRS,
+    SURFACE_ROLE_TOKENS,
+    TEXT_ON_SURFACE_TOKENS,
+} from "../styles/inventory";
+
+/*
+ * contrast-invariant — DESIGN.md のテーマ色が読める組合せであることを機械検証する。
+ *
+ * 【検査範囲】不透明 (opaque) なテキストペアのみ。
+ *   - 面 (neutral / surface) の上のテキスト色
+ *   - 塗り面 (primary / danger 等) の上のラベル色 (DESIGN.md §Components: bg-* + text-neutral)
+ *
+ * 【閾値】一律 4.5:1。
+ *   WCAG 2.2 SC 1.4.3 (AA) には「大きな文字は 3:1」の緩和があるが、
+ *   **トークン単位の gate は文字サイズを知り得ない**ため緩和は採らず、
+ *   厳しい側 (通常文字基準) を一律適用する。これは WCAG の要求そのものではなく
+ *   本プロジェクトの設計判断である。
+ *
+ * 【検査しないもの】inventory.ts の PENDING_CONTRAST_PAIRS を参照
+ *   (非テキスト 1.4.11 / alpha 合成)。「gate があるからコントラストは守られている」
+ *   という誤読を作らないため、未検査であることを明示宣言してある。
+ *
+ *   加えて `resources/views/vendor/mail/html/themes/template.css` は**対象外**。
+ *   同ファイルは Laravel 同梱メールテーマの独立パレット (`.button-red` = #dc2626、
+ *   `.button-green` = #16a34a 等) を直書きしており、DESIGN.md トークンの写像ではない。
+ *   メール HTML は CSS 変数を使えないクライアントが多く、DS token 化するなら
+ *   ビルド時展開の設計が別途要る (本バッチのスコープ外)。
+ *   なお詳細設計 §施策 6 のリスク表は「メールテンプレに danger は含まれない」と
+ *   書いているが、これは事実誤認 (実際は #dc2626 を直書きしている)。
+ *   対象外という結論は変わらないため据え置いた。
+ *
+ * 色値そのものを変えるときは DESIGN.md / tokens.css を同一 PR で更新すること
+ * (canonical-source-parity が drift を検出する)。
+ */
+
+const AA_NORMAL_TEXT = 4.5;
+
+/** sRGB チャンネルの線形化 (WCAG 2.x 相対輝度の定義) */
+function linearize(channel: number): number {
+    const c = channel / 255;
+    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
+}
+
+/** #rrggbb → 相対輝度 (WCAG 2.x) */
+function relativeLuminance(hex: string): number {
+    const r = linearize(parseInt(hex.slice(1, 3), 16));
+    const g = linearize(parseInt(hex.slice(3, 5), 16));
+    const b = linearize(parseInt(hex.slice(5, 7), 16));
+    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
+}
+
+/** コントラスト比 (WCAG 2.x)。1.0〜21.0 */
+export function contrastRatio(a: string, b: string): number {
+    const [l1, l2] = [relativeLuminance(a), relativeLuminance(b)];
+    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
+}
+
+const colors = designColors();
+
+function hex(token: string): string {
+    const value = colors.get(token);
+    if (value === undefined) throw new Error(`DESIGN.md colors に ${token} が無い`);
+    return value;
+}
+
+/** 検査対象ペア: [前景トークン, 背景トークン, 文脈] */
+const PAIRS: readonly (readonly [string, string, string])[] = [
+    // 面ロールとテキストロールは素 (下の「両集合が素」テストが固定する) なので、
+    // 自己ペア (同一トークン同士 = 比 1.0) は構造上生じない。
+    // 素であることを型の widen による自己ペア除外 filter で暗黙に扱わず、
+    // 独立した不変条件として明示的に検査する。
+    ...TEXT_ON_SURFACE_TOKENS.flatMap((fg) =>
+        SURFACE_ROLE_TOKENS.map((bg) => [fg, bg, "面上のテキスト"] as const),
+    ),
+    ...FILL_LABEL_TOKENS.flatMap((fg) =>
+        FILL_TOKENS.map((bg) => [fg, bg, "塗り面のラベル"] as const),
+    ),
+];
+
+describe("architecture/contrast-invariant: 不透明ペアのテキストコントラスト (一律 4.5:1)", () => {
+    it("役割宣言が DESIGN.md の全色トークンを覆う (deny-by-default)", () => {
+        const classified = new Set<string>([
+            ...SURFACE_ROLE_TOKENS,
+            ...TEXT_ON_SURFACE_TOKENS,
+            ...FILL_TOKENS,
+            ...FILL_LABEL_TOKENS,
+            ...Object.keys(CONTRAST_EXEMPT_TOKENS),
+        ]);
+        const unclassified = Object.keys(COLOR_TOKEN_MAP).filter((t) => !classified.has(t));
+        expect(
+            unclassified.sort(),
+            `未分類の色トークンがある。tests/js/styles/inventory.ts で ` +
+                `SURFACE_ROLE / TEXT_ON_SURFACE / FILL / FILL_LABEL / CONTRAST_EXEMPT の ` +
+                `いずれかに分類すること (免除するなら理由を書くこと): ${unclassified.join(", ")}`,
+        ).toEqual([]);
+
+        // 逆向き: 宣言に DESIGN.md に無いトークンが紛れていないか
+        const unknown = [...classified].filter((t) => !(t in COLOR_TOKEN_MAP));
+        expect(unknown.sort(), `DESIGN.md に存在しないトークンが宣言されている`).toEqual([]);
+    });
+
+    it("検査対象ペアが 0 件でない (空振り防止)", () => {
+        expect(PAIRS.length).toBeGreaterThan(0);
+    });
+
+    it("面ロールとテキストロールが素である (自己ペア = 比 1.0 が混入しない)", () => {
+        // PAIRS は両集合の直積を取るので、重複トークンがあると
+        // 「自分自身の上の自分」という無意味なペア (常に 1.0 で必ず fail) が生まれる。
+        // 将来あるトークンが面とテキストの両方の役割を持つなら、
+        // PAIRS の作り方 (直積) の見直しが要る — それをここで検知する。
+        const surfaces = new Set<string>(SURFACE_ROLE_TOKENS);
+        const overlap = TEXT_ON_SURFACE_TOKENS.filter((t) => surfaces.has(t));
+        expect(
+            overlap,
+            `SURFACE_ROLE_TOKENS と TEXT_ON_SURFACE_TOKENS が重複している: ${overlap.join(", ")}。` +
+                `直積で自己ペアが生じるので PAIRS の構築方法を見直すこと`,
+        ).toEqual([]);
+    });
+
+    it("未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない", () => {
+        // 「gate があるからコントラストは守られている」という誤読を防ぐ宣言そのものが
+        // 消し飛ばされないよう固定する。
+        // 出口: 1.4.11 / alpha 合成に対応して pending が空になったら、
+        // inventory.ts の宣言と本 it を **同時に削除**すること
+        // (空の宣言と、空でないことを確かめるテストを残すと形骸化する)。
+        expect(PENDING_CONTRAST_PAIRS.length).toBeGreaterThan(0);
+    });
+
+    it.each(PAIRS)("[opaque text] %s on %s (%s) が 4.5:1 以上", (fg, bg, context) => {
+        const ratio = contrastRatio(hex(fg), hex(bg));
+        expect(
+            ratio,
+            `${context}: text-${fg} on bg-${bg} = ${ratio.toFixed(2)}:1。` +
+                `DESIGN.md の色値を見直すこと (ペア集合を縮めて green にしないこと)`,
+        ).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
+    });
+
+    /* 負のコントロール: 計算器が実際に点灯することを既知値で確認する */
+    it("負のコントロール: 既知の低コントラスト対を検出し、既知の高コントラスト対は通す", () => {
+        expect(contrastRatio("#ffffff", "#ffffff")).toBeCloseTo(1, 5);
+        expect(contrastRatio("#000000", "#ffffff")).toBeCloseTo(21, 5);
+        // red-600 (#dc2626) on neutral (#f4f4f5) = 4.39 — 是正前の実測値。4.5 を割る
+        expect(contrastRatio("#dc2626", "#f4f4f5")).toBeLessThan(AA_NORMAL_TEXT);
+        // red-700 (#b91c1c) on neutral = 5.89 — 是正後
+        expect(contrastRatio("#b91c1c", "#f4f4f5")).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
+    });
+});
```

上記の対応で Warning が解消できているか、また
「素であること」を直積の前提として独立検査する形が
元の filter による暗黙の防御より健全と言えるか判定してほしい。
全体判定 (APPROVED / CHANGES_REQUESTED) を再度明記すること。
