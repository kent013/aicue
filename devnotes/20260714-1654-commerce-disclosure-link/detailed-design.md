# 詳細設計: commerce-disclosure-link

bug-hunt (real-llm 2nd run) F-2-01 (Low) / 前回 run Q-01 と同観察。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg /
単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作（`migrate:fresh` 等）のエージェント判断実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び（`app/Prompts/` factory 経由のみ）
6. prompt 文字列のコード直書き（`resources/prompts/*.yaml` に置く）
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（DESIGN.md）

本施策はフロントの静的リンク追加 + vitest のみで、上記いずれにも抵触しない
（PHP / DTO / prompt / DB / 認可のいずれにも触れない）。

### コーディングルール

- **PHPStan level 10** 必須（本施策は PHP 非変更のため影響なし。`composer phpstan` は green 維持）
- **Pest**（本施策は PHP テスト非追加。フロントは **vitest**）
- **RefreshDatabase** + `--parallel`（本施策は DB 非依存）
- フロントは **Svelte 5 runes + DS token/ramp のみ**（`DESIGN.md` canonical、ds-purity テスト）
- component 階層は `atoms → molecules → organisms → features → templates → pages` の単方向 import
- アイコンは `@lucide/svelte` のみ（本施策はアイコン非追加）
- 検証コマンド: `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`（全 green でコミット）

## 概念設計リファレンス

`devnotes/20260714-1654-commerce-disclosure-link/conceptual-design.md`
（conceptual-review Round 1 で **APPROVED**。Warning 2 件を反映済み）

## 現状の事実確認（コード実測）

- ルートは既存: `routes/web.php` L134
  `Route::view('/commerce-disclosure', 'legal.commerce-disclosure')->name('legal.commerce-disclosure')`
  （`NoIndex` middleware group 内、noindex プレースホルダ）。**ルート追加は不要。**
- フッターは `resources/js/components/templates/GuestLayout.svelte` L104-113 の
  `<footer>` 内で `{@render footerLinks()}`（`footerLinks?: Snippet` prop）として描画。
  **テンプレートは変更不要。**
- 各ゲストページが `footerLinks` snippet を定義してリンク文言を供給する:
  - `resources/js/pages/Welcome.svelte` L388-393: 料金プラン / **利用規約** / **プライバシーポリシー** / お問い合わせ
  - `resources/js/pages/Pricing.svelte` L225-230: トップ / **利用規約** / **プライバシーポリシー** / お問い合わせ
- 既存法的リンクのパターン: `<a href="/terms" class="hover:text-primary">利用規約</a>`
  （生 `<a>` + Tailwind `hover:text-primary`。route helper でなく path 直書き）。
- Contact/Index・Contact/Thanks は GuestLayout を使うが `footerLinks` を **渡していない**
  （法的リンクを一切持たない）。本施策の対象外。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | Welcome フッターに特商法リンク追加 | `resources/js/pages/Welcome.svelte` | Low |
| 2 | Pricing フッターに特商法リンク追加 | `resources/js/pages/Pricing.svelte` | Low |
| 3 | Welcome フッター法的リンク契約テスト | `tests/js/pages/Welcome.test.ts` | Low |
| 4 | Pricing フッター法的リンク契約テスト | `tests/js/pages/Pricing.test.ts` | Low |

---

## 施策 1: Welcome フッターに特商法リンク追加

### 変更箇所
- ファイル: `resources/js/pages/Welcome.svelte`（`footerLinks` snippet, L388-393）

### 波及変更
- TypeScript 型定義: なし（Props / 型変更なし。静的マークアップの追加のみ）
- API Resource/DTO: なし（PHP 非変更）
- テストファイル: `tests/js/pages/Welcome.test.ts`（施策 3 で対応）

### 現行コード
```svelte
{#snippet footerLinks()}
    <a href="/pricing" class="hover:text-primary">料金プラン</a>
    <a href="/terms" class="hover:text-primary">利用規約</a>
    <a href="/privacy" class="hover:text-primary">プライバシーポリシー</a>
    <a href={page.contactUrl} class="hover:text-primary">お問い合わせ</a>
{/snippet}
```

### 変更後コード
```svelte
{#snippet footerLinks()}
    <a href="/pricing" class="hover:text-primary">料金プラン</a>
    <a href="/terms" class="hover:text-primary">利用規約</a>
    <a href="/privacy" class="hover:text-primary">プライバシーポリシー</a>
    <a href="/commerce-disclosure" class="hover:text-primary">特定商取引法に基づく表記</a>
    <a href={page.contactUrl} class="hover:text-primary">お問い合わせ</a>
{/snippet}
```

- 配置: `プライバシーポリシー` の直後（terms → privacy → commerce の順）。`お問い合わせ` は最後尾に維持。
- 文言「特定商取引法に基づく表記」は blade（`resources/views/legal/commerce-disclosure.blade.php`
  の `<h1>` / `@section('title')`）と一致させる。
- href は既存法的リンク同様 path 直書き `/commerce-disclosure`（route helper 未使用が既存踏襲）。

### PHPStan 適合チェック
- 本施策は PHP 非変更のため対象外（`composer phpstan` は現状 green を維持）。

### テスト計画（施策 3 と一体）
- [ ] バグ再現の観点: 変更前は footer に `/commerce-disclosure` リンクが存在せず、
      追加テストが fail することを確認（テストファースト）。
- [ ] 新規テスト: 「フッターの法的リンクが terms → privacy → commerce-disclosure の
      3 件・正しい href・表示順で揃う」。
- [ ] DB 非依存（vitest / DatabaseTransactions 無関係）。

### リスク
- 既存フッターの他リンク（料金プラン / お問い合わせ）の順序・文言は不変。
- モバイルパネル（nav snippet）とは無関係（footerLinks は footer のみで描画）。副作用なし。

---

## 施策 2: Pricing フッターに特商法リンク追加

### 変更箇所
- ファイル: `resources/js/pages/Pricing.svelte`（`footerLinks` snippet, L225-230）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/Pricing.test.ts`（施策 4 で対応）

### 現行コード
```svelte
{#snippet footerLinks()}
    <a href="/" class="hover:text-primary">トップ</a>
    <a href="/terms" class="hover:text-primary">利用規約</a>
    <a href="/privacy" class="hover:text-primary">プライバシーポリシー</a>
    <a href={page.contactUrl} class="hover:text-primary">お問い合わせ</a>
{/snippet}
```

### 変更後コード
```svelte
{#snippet footerLinks()}
    <a href="/" class="hover:text-primary">トップ</a>
    <a href="/terms" class="hover:text-primary">利用規約</a>
    <a href="/privacy" class="hover:text-primary">プライバシーポリシー</a>
    <a href="/commerce-disclosure" class="hover:text-primary">特定商取引法に基づく表記</a>
    <a href={page.contactUrl} class="hover:text-primary">お問い合わせ</a>
{/snippet}
```

### PHPStan 適合チェック
- 対象外（PHP 非変更）。

### テスト計画（施策 4 と一体）
- [ ] 施策 3 と同一契約を Pricing でも固定。

### リスク
- 既存リンク（トップ / お問い合わせ）不変。副作用なし。

---

## 施策 3: Welcome フッター法的リンク契約テスト

### 変更箇所
- ファイル: `tests/js/pages/Welcome.test.ts`（新規 `it` を 1 件追加）

### 波及変更
- TypeScript 型定義 / API Resource / DTO: なし

### 追加テスト（設計案）
```ts
it("フッターに法的リンク3件 (利用規約→プライバシー→特商法) を href と順序どおり出す", () => {
    render(Welcome, { props: baseProps });

    // <footer> は contentinfo landmark。nav 側リンクと混ざらないよう footer に限定する。
    const footer = screen.getByRole("contentinfo");

    // (a) 法的3リンクを名前で個別取得し href を契約化。
    //     commerce は本バグ F-2-01 の主対象。terms/privacy も欠落検知のため個別検証する。
    expect(
        within(footer).getByRole("link", { name: "利用規約" }),
    ).toHaveAttribute("href", "/terms");
    expect(
        within(footer).getByRole("link", { name: "プライバシーポリシー" }),
    ).toHaveAttribute("href", "/privacy");
    expect(
        within(footer).getByRole("link", { name: "特定商取引法に基づく表記" }),
    ).toHaveAttribute("href", "/commerce-disclosure");

    // (b) 法的リンクだけを DOM 順で抽出し表示順を固定 (非法的リンクは filter で除外済み
    //     なので、料金プラン/お問い合わせ等の増減では壊れない = ノイズ耐性あり)。
    const legalHrefs = within(footer)
        .getAllByRole("link")
        .map((a) => a.getAttribute("href"))
        .filter((href) =>
            ["/terms", "/privacy", "/commerce-disclosure"].includes(href ?? ""),
        );
    expect(legalHrefs).toEqual(["/terms", "/privacy", "/commerce-disclosure"]);
});
```

### 設計上の判断
- `getByRole("contentinfo")` で footer に限定 → nav（ヘッダ）リンクと衝突しない。
  jsdom では nav に `/terms` 等は無いが、将来の変更に対する頑健性のため footer scope を明示。
- (a) 個別 `getByRole` により commerce だけでなく **terms/privacy の欠落も個別に検出**。
- (b) の順序検証は `.filter(...)` で **法的リンクのみに絞ってから** DOM 順を比較するため、
  非法的リンク（料金プラン / お問い合わせ）の増減では壊れない（Codex Round1 Warning
  「順序依存の脆さ」への対応）。法的リンクが 4 件目に増えた場合に要更新となるのは
  「法的リンク集合の契約」として意図的（ドリフト検知の要）。
- 文言は完全一致（exact name）を維持し、blade（`legal/commerce-disclosure.blade.php` の
  `特定商取引法に基づく表記`）との一致を契約化する（表記ゆれ許容の正規表現化は見送り）。
- 既存テスト（`tests/js/pages/Welcome.test.ts` の他 `it`）は変更・削除しない（追加のみ）。

### PHPStan 適合チェック
- 対象外（TS テスト。`pnpm typecheck` は green を維持）。

### テスト計画
- [ ] 施策 1 適用前は fail（`/commerce-disclosure` 不在で `getByRole` が throw）→ 適用後 pass を確認。
- [ ] `pnpm test` green。

### リスク
- 既存 import（`within` は既存テストで使用済み ＝ import 追加不要）を前提。未 import なら
  `import { ... , within } from "@testing-library/svelte"` を補う（Welcome.test.ts は既に import 済み）。

---

## 施策 4: Pricing フッター法的リンク契約テスト

### 変更箇所
- ファイル: `tests/js/pages/Pricing.test.ts`
  - **import の差し替え（必須）**: `within` を testing-library import に追加する。
    現状 `import { fireEvent, render, screen } from "@testing-library/svelte";` を
    `import { fireEvent, render, screen, within } from "@testing-library/svelte";` にする
    （未追加だと `within` 参照で typecheck / vitest がコンパイルエラー ＝ Codex Round1 Warning 対応）。
  - 新規 `it` を 1 件追加。

### 波及変更
- なし（Props / 型変更なし。テストファイル内の import 追記のみ）

### 追加テスト（設計案）
```ts
it("フッターに法的リンク3件 (利用規約→プライバシー→特商法) を href と順序どおり出す", () => {
    render(Pricing, { props: { appName: "AI-CUE", page: basePage } });

    const footer = screen.getByRole("contentinfo");

    // (a) 法的3リンクを名前で個別取得し href を契約化。
    expect(
        within(footer).getByRole("link", { name: "利用規約" }),
    ).toHaveAttribute("href", "/terms");
    expect(
        within(footer).getByRole("link", { name: "プライバシーポリシー" }),
    ).toHaveAttribute("href", "/privacy");
    expect(
        within(footer).getByRole("link", { name: "特定商取引法に基づく表記" }),
    ).toHaveAttribute("href", "/commerce-disclosure");

    // (b) 法的リンクのみを DOM 順で抽出し表示順を固定 (非法的リンクは filter で除外)。
    const legalHrefs = within(footer)
        .getAllByRole("link")
        .map((a) => a.getAttribute("href"))
        .filter((href) =>
            ["/terms", "/privacy", "/commerce-disclosure"].includes(href ?? ""),
        );
    expect(legalHrefs).toEqual(["/terms", "/privacy", "/commerce-disclosure"]);
});
```

### 設計上の判断
- `Pricing.test.ts` の既存 render 呼び出し形（`props: { appName, page: basePage }`）に合わせる。
- `within` を testing-library import に追加する（上記「変更箇所」に格上げ済み）。
- 順序検証は施策3 と同じく法的リンクのみ filter して DOM 順比較（ノイズ耐性あり）。

### PHPStan 適合チェック
- 対象外。

### テスト計画
- [ ] 施策 2 適用前は fail → 適用後 pass。
- [ ] `pnpm test` green。

### リスク
- import 追加漏れ（`within`）で typecheck エラー → 実装時に import を必ず補う。

---

## 波及変更の網羅性（総括）

| 波及先 | 影響 |
|--------|------|
| ルート（`routes/web.php`） | なし（`legal.commerce-disclosure` は既存） |
| GuestLayout テンプレート | なし（`{@render footerLinks()}` は既存で汎用） |
| TypeScript 型 / Inertia Props | なし（Props インターフェース不変。`LandingPageProps` / `PricingPageProps` 変更なし） |
| API Resource / DTO | なし（PHP 非変更） |
| DS token / `resources/css/tokens.css` | なし（既存ユーティリティ `hover:text-primary` を踏襲、新 token 無し） |
| Atomic Design 階層 | なし（page 内 snippet へのリンク 1 行追加。component 新設・階層変更なし） |
| アイコン（`svg-inline-allowlist`） | なし（アイコン非追加） |
| Contact ページ | 対象外（footerLinks 未提供の既存設計。本バグは Welcome/Pricing で解消） |

## セキュリティ観点

- 認可・入力バリデーション・テナント境界に触れない（静的な内部リンク追加のみ）。
- 追加する href は固定文字列 `/commerce-disclosure`（ユーザー入力由来でない）。XSS/SSRF 無関係。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存フッター snippet への 1 行追加 + 対応 vitest 追加のみ。新規ファイル・新規 route・新規 component を作らず、既存挙動と独立に積み増す最小差分。 |
| 競合リスク | 極小。Welcome.svelte / Pricing.svelte の footerLinks 近傍のみ変更。他施策・他 worktree との干渉可能性は低い。 |

## 使命・禁止事項 最終チェック

- [x] 全施策が使命に反しない（公開 SaaS のコンプライアンス基盤の欠落を埋める。North Star の
      妨げにならない最小改善）。
- [x] 禁止事項（1〜8）いずれにも抵触しない（テスト付き / PHP・DTO・prompt・DB・認可 非変更 /
      disabled UI 非導入）。
- [x] コーディングルール反映: 各施策にテスト（vitest）を必須化、DS ユーティリティ踏襲、
      Atomic 階層維持、検証コマンド（lint/typecheck/test/build）green を完了条件に明記。
