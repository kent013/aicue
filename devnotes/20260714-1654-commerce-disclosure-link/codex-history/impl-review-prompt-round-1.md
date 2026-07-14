## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## 役割

あなたはコードレビュアーとして Laravel + Svelte の改善実装をレビューする。

### レビュー観点
- **設計との一致性**: 詳細設計書どおりに実装されているか
- **正確性**: リンク href / 文言 / 配置順が仕様どおりか
- **PHPStan 適合性**: 本施策は PHP 非変更（対象外だが、PHP へ波及していないことを確認）
- **DTO/JsonResource パターン**: 該当なし（PHP 非変更）を確認
- **テスト網羅性**: 追加テストがバグ（特商法リンク欠落）を検知でき、terms/privacy の欠落・順序変更も捉えるか。テストなし実装になっていないか
- **セキュリティ**: 認可・入力・テナント境界に触れていないか
- **DESIGN.md 準拠**: color/radius/typography を token 経由で参照し hex 直書きを増やしていないか。本施策は既存ユーティリティ `hover:text-primary` 踏襲のみ
- **Atomic Design 準拠**: `resources/js/components/` の atoms/molecules/organisms/templates 責務分離。本施策は page 内 snippet への 1 行追加のみで component 新設・階層変更なし。アイコンは Lucide のみ（本施策アイコン非追加）

### 出力形式
- ファイルごとに判定
- 指摘は Critical / Warning / Suggestion に分類
- 最後に全体判定 **APPROVED** または **CHANGES_REQUESTED** を明記

---

## 詳細設計書（要約）

TODO T045「特定商取引法ページ(commerce-disclosure)へのサイト内リンク追加」。bug-hunt real-llm 2nd run F-2-01 (Low) 対応。

- 既存ルート `Route::view('/commerce-disclosure', 'legal.commerce-disclosure')->name('legal.commerce-disclosure')` は存在するが、フッターからのサイト内リンクが無く到達不能（reachability 欠落）。
- 対策: ゲストページ `Welcome.svelte` / `Pricing.svelte` の `footerLinks` snippet に `<a href="/commerce-disclosure" class="hover:text-primary">特定商取引法に基づく表記</a>` を **プライバシーポリシーの直後・お問い合わせの直前** に追加。
- 文言「特定商取引法に基づく表記」は blade（`resources/views/legal/commerce-disclosure.blade.php` の `<h1>`）と一致させる。
- href は既存法的リンク同様 path 直書き（route helper 未使用が既存踏襲）。
- 契約テスト（vitest）を Welcome/Pricing 各 1 件追加。`getByRole("contentinfo")` で footer に限定し、terms→privacy→commerce の href と DOM 順を固定。順序検証は法的リンクのみ filter して非法的リンクの増減に影響されない設計。
- Pricing.test.ts は `within` を testing-library import に追加（未追加だと typecheck エラー）。
- 施策一覧: (1) Welcome フッター追加 (2) Pricing フッター追加 (3) Welcome 契約テスト (4) Pricing 契約テスト。
- PHP / DTO / prompt / DB / 認可のいずれにも触れない静的リンク追加のみ。

詳細設計は detailed-review Round 2 で全施策 APPROVE 済み。

---

## 実装差分（git diff）

```diff
diff --git a/resources/js/pages/Pricing.svelte b/resources/js/pages/Pricing.svelte
index 9bf2f0b..ed84105 100644
--- a/resources/js/pages/Pricing.svelte
+++ b/resources/js/pages/Pricing.svelte
@@ -226,6 +226,7 @@
         <a href="/" class="hover:text-primary">トップ</a>
         <a href="/terms" class="hover:text-primary">利用規約</a>
         <a href="/privacy" class="hover:text-primary">プライバシーポリシー</a>
+        <a href="/commerce-disclosure" class="hover:text-primary">特定商取引法に基づく表記</a>
         <a href={page.contactUrl} class="hover:text-primary">お問い合わせ</a>
     {/snippet}
 </GuestLayout>
diff --git a/resources/js/pages/Welcome.svelte b/resources/js/pages/Welcome.svelte
index 6fc8659..2ee10af 100644
--- a/resources/js/pages/Welcome.svelte
+++ b/resources/js/pages/Welcome.svelte
@@ -389,6 +389,7 @@
         <a href="/pricing" class="hover:text-primary">料金プラン</a>
         <a href="/terms" class="hover:text-primary">利用規約</a>
         <a href="/privacy" class="hover:text-primary">プライバシーポリシー</a>
+        <a href="/commerce-disclosure" class="hover:text-primary">特定商取引法に基づく表記</a>
         <a href={page.contactUrl} class="hover:text-primary">お問い合わせ</a>
     {/snippet}
 </GuestLayout>
diff --git a/tests/js/pages/Pricing.test.ts b/tests/js/pages/Pricing.test.ts
index 7d24cb6..336cf34 100644
--- a/tests/js/pages/Pricing.test.ts
+++ b/tests/js/pages/Pricing.test.ts
@@ -1,5 +1,5 @@
 import { describe, expect, it } from "vitest";
-import { fireEvent, render, screen } from "@testing-library/svelte";
+import { fireEvent, render, screen, within } from "@testing-library/svelte";
 import Pricing from "@/pages/Pricing.svelte";
 import type { PricingPageProps } from "@/types/marketing";
 
@@ -112,4 +112,31 @@ describe("Pricing", () => {
 
         expect(container.querySelectorAll("button[disabled]")).toHaveLength(0);
     });
+
+    it("フッターに法的リンク3件 (利用規約→プライバシー→特商法) を href と順序どおり出す", () => {
+        render(Pricing, { props: { page: basePage } });
+
+        const footer = screen.getByRole("contentinfo");
+
+        // (a) 法的3リンクを名前で個別取得し href を契約化。
+        expect(within(footer).getByRole("link", { name: "利用規約" })).toHaveAttribute(
+            "href",
+            "/terms",
+        );
+        expect(
+            within(footer).getByRole("link", { name: "プライバシーポリシー" }),
+        ).toHaveAttribute("href", "/privacy");
+        expect(
+            within(footer).getByRole("link", { name: "特定商取引法に基づく表記" }),
+        ).toHaveAttribute("href", "/commerce-disclosure");
+
+        // (b) 法的リンクのみを DOM 順で抽出し表示順を固定 (非法的リンクは filter で除外)。
+        const legalHrefs = within(footer)
+            .getAllByRole("link")
+            .map((a) => a.getAttribute("href"))
+            .filter((href) =>
+                ["/terms", "/privacy", "/commerce-disclosure"].includes(href ?? ""),
+            );
+        expect(legalHrefs).toEqual(["/terms", "/privacy", "/commerce-disclosure"]);
+    });
 });
diff --git a/tests/js/pages/Welcome.test.ts b/tests/js/pages/Welcome.test.ts
index 10e0599..798309c 100644
--- a/tests/js/pages/Welcome.test.ts
+++ b/tests/js/pages/Welcome.test.ts
@@ -126,4 +126,34 @@ describe("Welcome (LP)", () => {
         await fireEvent.click(within(panel).getByRole("link", { name: "料金プラン" }));
         expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
     });
+
+    it("フッターに法的リンク3件 (利用規約→プライバシー→特商法) を href と順序どおり出す", () => {
+        render(Welcome, { props: baseProps });
+
+        // <footer> は contentinfo landmark。nav 側リンクと混ざらないよう footer に限定する。
+        const footer = screen.getByRole("contentinfo");
+
+        // (a) 法的3リンクを名前で個別取得し href を契約化。
+        //     commerce は本バグ F-2-01 の主対象。terms/privacy も欠落検知のため個別検証する。
+        expect(within(footer).getByRole("link", { name: "利用規約" })).toHaveAttribute(
+            "href",
+            "/terms",
+        );
+        expect(
+            within(footer).getByRole("link", { name: "プライバシーポリシー" }),
+        ).toHaveAttribute("href", "/privacy");
+        expect(
+            within(footer).getByRole("link", { name: "特定商取引法に基づく表記" }),
+        ).toHaveAttribute("href", "/commerce-disclosure");
+
+        // (b) 法的リンクだけを DOM 順で抽出し表示順を固定 (非法的リンクは filter で除外済み
+        //     なので、料金プラン/お問い合わせ等の増減では壊れない = ノイズ耐性あり)。
+        const legalHrefs = within(footer)
+            .getAllByRole("link")
+            .map((a) => a.getAttribute("href"))
+            .filter((href) =>
+                ["/terms", "/privacy", "/commerce-disclosure"].includes(href ?? ""),
+            );
+        expect(legalHrefs).toEqual(["/terms", "/privacy", "/commerce-disclosure"]);
+    });
 });
```

## テスト結果

- 対象2ファイルの vitest: Test Files 2 passed (2) / Tests 16 passed (16)
- pnpm typecheck: 0 errors
- pnpm lint: 0 errors
- pnpm build: 成功（built in 21s）
- pnpm test（全 vitest スイート, testTimeout=30000）: 実行結果は別途確認（本施策差分の 2 テストは pass 済み）
- PHP は非変更（app/ に diff 無し）のため composer test / phpstan は対象外

## design system 参照

- 触れた atomic ディレクトリ: `resources/js/pages/`（pages 層。footerLinks snippet は各ゲストページが定義し `templates/GuestLayout.svelte` の `{@render footerLinks()}` に供給される既存構造）
- 追加リンクの class は既存踏襲の `hover:text-primary`（DS token 経由のユーティリティ。hex 直書きなし・新規 token なし）
- アイコン追加なし（Lucide/SVG allowlist 非該当）
