## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

## 禁止事項（全レビュー観点に適用）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

```
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。
```

---

## system: レビュアーの役割

あなたは Laravel + Svelte アプリの改善実装をレビューするシニアコードレビュアーである。以下の観点で本実装差分をレビューせよ:

- **設計との一致性**: 詳細設計書のとおり実装されているか
- **正確性**: バグ(特に `$effect` の無限ループ・過剰発火・stale クロージャ)がないか
- **Svelte 5 runes の妥当性**: `$effect` の使用が idiomatic か、`$derived` で表せる純粋派生に誤用していないか、収束保証があるか
- **テスト網羅性**: 再現テスト(バグの Before/After 固定)・回帰防止・serverErrors 非退行が揃っているか、既存テストを壊していないか
- **DESIGN.md 準拠 / 禁止事項#8**: 「押下時にエラー表示」契約(disabled 不使用)を壊していないか
- **Atomic Design 準拠**: pages 層のロジック追加に留まり import 階層を逆流していないか
- **a11y**: `aria-invalid` の解除が正しく検証されているか

出力形式: ファイルごとに判定。指摘は Critical / Warning / Suggestion に分類。最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示せよ。

---

## user: 詳細設計書

本修正は bug-hunt F-3-01(Medium)。`/purchase-tickets` で範囲外入力を押下しエラー表示した後、有効値へ修正してもエラー文言と `aria-invalid` が残留する問題を修正する。

根本原因: `clientError`(`$state<string|null>`)は `submit()` 押下時にのみ設定/リセットされる独立 state で、入力の派生チェーン(`countText`→`parsedCount`→`isValidCount`)に追従しない。

修正方針: `isValidCount` が true に復帰した時点で `clientError` を自動クリアする `$effect` を追加する。
- クリア条件は `clientError !== null && isValidCount` のみ(無効→無効ではクリアしない=「押下時にエラー表示」契約維持)。
- serverErrors(full POST 往復由来)は対象外(`clientError` のみ扱う)。
- 収束保証: effect は `clientError` と `isValidCount` を読み、`clientError = null` を書く。書込後 `clientError !== null` が false になり次回は代入スキップで収束。

代替案 A(oninput 毎回クリア)/ B(submitAttempted フラグ + $derived 純粋導出)は「押下時にのみエラー表示」契約を壊すため不採用。discriminated union 種別化は現状単一用途 state のためオーバーエンジニアリングとして不採用(コメントで不変条件を明記)。

テスト: (1) 範囲外送信→有効値修正でエラー消失 + aria-invalid 解除 + 合計再計算、(2) 無効→無効ではエラー残留(過剰クリアの回帰防止)、(3) serverErrors.count 有り時は有効値でもエラー/invalid 残留(serverErrors 非退行)。既存6テストは `page` モック化後も挙動不変。

## user: 実装差分（git diff）

```diff
diff --git a/resources/js/pages/Billing/PurchaseTickets.svelte b/resources/js/pages/Billing/PurchaseTickets.svelte
@@ -48,6 +48,17 @@
         parsedCount !== null && parsedCount >= page.minCount && parsedCount <= page.maxCount,
     );
 
+    // clientError は「購入枚数の範囲バリデーション」専用の transient state。押下時にのみ設定され、
+    // 値が有効へ復帰した時点で自動解消する (「押下時にエラー表示」契約は維持: 無効のままなら残す)。
+    // serverErrors (full POST 往復由来) は本 effect の対象外で別経路。
+    // ※不変条件: 将来 clientError に別種のメッセージを載せる場合はこのクリア条件の再検討が必要。
+    // clientError の有無も条件に含めることで不要な代入を避け、意図を明確化する。
+    $effect(() => {
+        if (clientError !== null && isValidCount) {
+            clientError = null;
+        }
+    });
+
     // 適用単価: tiers (minCount 昇順) から minCount <= count の最大段を選ぶ
     const appliedUnit = $derived.by<number | null>(() => {

diff --git a/tests/js/pages/PurchaseTickets.test.ts b/tests/js/pages/PurchaseTickets.test.ts
@@ -3,9 +3,11 @@
 import PurchaseTickets from "@/pages/Billing/PurchaseTickets.svelte";
 import type { PurchaseTicketsPageProps } from "@/types/billing";
 
-// router.post をモックし page state は実物を使う (props 未設定の空オブジェクト)
-const { routerPostMock } = vi.hoisted(() => ({
+// router.post をモックする。page (Inertia store) も hoisted fake でモックし、
+// props.errors を注入して serverErrors 経路を検証できるようにする (既定は空 = 従来挙動)。
+const { routerPostMock, pageState } = vi.hoisted(() => ({
     routerPostMock: vi.fn(),
+    pageState: { props: {} as Record<string, unknown> },
 }));
 
 vi.mock("@inertiajs/svelte", async (importOriginal) => ({
@@ -13,6 +15,7 @@
     router: {
         post: routerPostMock,
     },
+    page: pageState,
 }));
@@ -42,6 +45,7 @@ const basePage: PurchaseTicketsPageProps = {
 afterEach(() => {
     cleanup();
     routerPostMock.mockReset();
+    pageState.props = {}; // errors 注入をリセット (テスト間の汚染防止)
 });
@@ describe("Billing/PurchaseTickets", () => {
+    it("範囲外送信でエラー表示後、有効値に修正するとエラーが消え invalid が外れる", async () => {
+        render(PurchaseTickets, { props: { page: basePage } });
+        await setCount("1001");
+        await fireEvent.click(screen.getByTestId("purchase-submit"));
+        expect(routerPostMock).not.toHaveBeenCalled();
+        expect(screen.getByText("購入枚数は 1〜1000 の整数で入力してください")).toBeInTheDocument();
+        expect(screen.getByTestId("ticket-count-input")).toHaveAttribute("aria-invalid", "true");
+        await setCount("20");
+        expect(screen.queryByText("購入枚数は 1〜1000 の整数で入力してください")).toBeNull();
+        expect(screen.getByTestId("ticket-count-input")).not.toHaveAttribute("aria-invalid");
+        expect(screen.getByTestId("purchase-total")).toHaveTextContent("単価 ¥80 × 20 枚 = 合計 ¥1,600");
+    });
+
+    it("無効値のまま別の無効値へ変えてもエラーは残る (過剰クリアしない)", async () => {
+        render(PurchaseTickets, { props: { page: basePage } });
+        await setCount("1001");
+        await fireEvent.click(screen.getByTestId("purchase-submit"));
+        expect(screen.getByText("購入枚数は 1〜1000 の整数で入力してください")).toBeInTheDocument();
+        await setCount("2002");
+        expect(screen.getByText("購入枚数は 1〜1000 の整数で入力してください")).toBeInTheDocument();
+        expect(screen.getByTestId("purchase-total")).toHaveTextContent("合計 —");
+    });
+
+    it("serverErrors.count がある場合は有効値に修正してもエラー表示が残る", async () => {
+        pageState.props = { errors: { count: "サーバ側で拒否されました" } };
+        render(PurchaseTickets, { props: { page: basePage } });
+        await setCount("20");
+        expect(screen.getByText("サーバ側で拒否されました")).toBeInTheDocument();
+        expect(screen.getByTestId("ticket-count-input")).toHaveAttribute("aria-invalid", "true");
+    });
```

## user: 参考(既存の関連コード)

- `PurchaseTickets.svelte` の FormField error prop: `error={clientError ?? serverErrors.count ?? serverErrors.attempt_token ?? null}`
- `submit()`: `clientError = null;` の後 `if (!isValidCount || parsedCount === null) { clientError = ...; return; }` で押下時にのみ設定(この既存ロジックは変更なし)。
- `Input.svelte` は `aria-invalid={error || undefined}` を出力(error=false で属性消滅)。
- `serverErrors` は `(inertiaPage.props.errors ?? {})` から派生。テストの `page` モックは `pageState.props` を注入する。

## user: テスト結果

- 対象ファイル `tests/js/pages/PurchaseTickets.test.ts`: 9 passed (既存6 + 新規3)。
- `pnpm typecheck`(tsc/svelte-check): OK。`pnpm lint`(eslint/ds-purity): OK。`pnpm build`: OK。
- PHP 変更なし(差分は上記フロント2ファイルのみ)のため composer test/phpstan は非該当。
- フル vitest は並列実装の負荷で無関係ファイルが timeout したが、対象テストと当該 timeout ファイル(ScenarioEditor 30/30)はいずれも単体実行で pass することを確認済み。

上記実装をレビューし、全体判定(APPROVED / CHANGES_REQUESTED)を示せ。
