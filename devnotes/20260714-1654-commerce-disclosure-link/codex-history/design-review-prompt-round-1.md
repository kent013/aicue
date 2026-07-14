# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

# 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作のエージェント判断実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（DESIGN.md）

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。方向性が間違っているなら設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策にテスト、RefreshDatabase グローバル適用）
5. DTO/JsonResource パターン遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript 型定義、API Resource、テストが変更対象に含まれるか）
9. セキュリティ（認可、入力バリデーション、OWASP、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md 準拠（UI/frontend 変更）: token 経由参照か、hex 直書きを増やさないか
11. Atomic Design 準拠: atoms/molecules/organisms/templates の責務分離、Lucide 前提

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

（本文は下記。footer リンク 1 行追加 ×2 ページ + vitest 契約テスト ×2 の最小変更。概念設計は conceptual-review Round1 APPROVED 済み）

@import: devnotes/20260714-1654-commerce-disclosure-link/detailed-design.md の全文を以下に転記

---DETAILED-DESIGN-START---

（施策一覧・変更前後コード・波及変更・テスト計画・実装モードを含む全文はリポジトリ内
 `devnotes/20260714-1654-commerce-disclosure-link/detailed-design.md` を参照。Codex はこのファイルを
 読み込み可能。要点を以下に再掲する）

### 施策一覧
1. Welcome フッターに特商法リンク追加 — `resources/js/pages/Welcome.svelte`
2. Pricing フッターに特商法リンク追加 — `resources/js/pages/Pricing.svelte`
3. Welcome フッター法的リンク契約テスト — `tests/js/pages/Welcome.test.ts`
4. Pricing フッター法的リンク契約テスト — `tests/js/pages/Pricing.test.ts`

### 施策1/2 変更（Welcome / Pricing 共通の追加行）
`<a href="/privacy" ...>` の直後に以下を追加:
```svelte
<a href="/commerce-disclosure" class="hover:text-primary">特定商取引法に基づく表記</a>
```
配置順: terms → privacy → commerce → お問い合わせ。ルート `legal.commerce-disclosure` は既存で追加不要。
GuestLayout の `{@render footerLinks()}` は変更不要。

### 施策3/4 追加テスト（Welcome / Pricing）
```ts
const footer = screen.getByRole("contentinfo");
const commerce = within(footer).getByRole("link", { name: "特定商取引法に基づく表記" });
expect(commerce).toHaveAttribute("href", "/commerce-disclosure");
const legalHrefs = within(footer).getAllByRole("link")
  .map((a) => a.getAttribute("href"))
  .filter((href) => ["/terms","/privacy","/commerce-disclosure"].includes(href ?? ""));
expect(legalHrefs).toEqual(["/terms","/privacy","/commerce-disclosure"]);
```
狙い: commerce の href/名だけでなく terms/privacy の欠落も検出する契約（ドリフト再発防止）。

### 波及変更
ルート/GuestLayout/TS 型/Props/DTO/DS token/Atomic 階層/アイコン いずれも変更なし。
Contact ページは footerLinks 未提供の既存設計につき対象外。

### 実装モード
incremental（既存 snippet への 1 行 + vitest 追加の最小差分）。

---DETAILED-DESIGN-END---

## 関連する現行コード（実測抜粋）

### routes/web.php（L130-135, legal ルート。既存）
```php
Route::get('/pricing', PricingController::class)->name('pricing');
Route::middleware(NoIndex::class)->group(function (): void {
    Route::view('/terms', 'legal.terms')->name('legal.terms');
    Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
    Route::view('/commerce-disclosure', 'legal.commerce-disclosure')->name('legal.commerce-disclosure');
});
```

### resources/js/components/templates/GuestLayout.svelte（footer 部, L104-113。変更なし）
```svelte
<footer class="border-t border-border bg-surface">
    <div class="mx-auto flex max-w-5xl items-center justify-between px-8 py-4 text-caption text-text-secondary">
        <span>&copy; {new Date().getFullYear()} {appName}</span>
        {#if footerLinks}
            <div class="flex items-center gap-4">
                {@render footerLinks()}
            </div>
        {/if}
    </div>
</footer>
```
Props: `footerLinks?: Snippet`。

### resources/js/pages/Welcome.svelte（L388-393, 現行 footerLinks）
```svelte
{#snippet footerLinks()}
    <a href="/pricing" class="hover:text-primary">料金プラン</a>
    <a href="/terms" class="hover:text-primary">利用規約</a>
    <a href="/privacy" class="hover:text-primary">プライバシーポリシー</a>
    <a href={page.contactUrl} class="hover:text-primary">お問い合わせ</a>
{/snippet}
```

### resources/js/pages/Pricing.svelte（L225-230, 現行 footerLinks）
```svelte
{#snippet footerLinks()}
    <a href="/" class="hover:text-primary">トップ</a>
    <a href="/terms" class="hover:text-primary">利用規約</a>
    <a href="/privacy" class="hover:text-primary">プライバシーポリシー</a>
    <a href={page.contactUrl} class="hover:text-primary">お問い合わせ</a>
{/snippet}
```

### tests/js/pages/Welcome.test.ts（import 部・既に within 使用済み）
```ts
import { describe, expect, it } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/svelte";
import Welcome from "@/pages/Welcome.svelte";
import type { LandingPageProps } from "@/types/marketing";
const baseProps = { appName: "AI-CUE", page: guestPage };
```

### tests/js/pages/Pricing.test.ts（import 部・within 未使用 → 追加要）
```ts
import { describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import Pricing from "@/pages/Pricing.svelte";
import type { PricingPageProps } from "@/types/marketing";
// render(Pricing, { props: { appName: "AI-CUE", page: basePage } })
```

### resources/views/legal/commerce-disclosure.blade.php（文言の正）
`<h1>特定商取引法に基づく表記</h1>` / `@section('title', '特定商取引法に基づく表記')`
（noindex プレースホルダ。姉妹の terms/privacy も同様の noindex プレースホルダで、
かつ既に Welcome/Pricing の footer からリンク済み ＝ 本追加はパリティ回復）
