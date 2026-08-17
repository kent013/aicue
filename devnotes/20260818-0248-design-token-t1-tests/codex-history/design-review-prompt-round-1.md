## 使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ / 撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解してから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。

本リポジトリ固有の原則: (1) フレームワークのレンジ内でやる (2) 今必要なものだけ作る (オーバーエンジニアリング禁止) (3) 後方互換の並走を残さない (4) 別物の概念を「似ているから」で統合しない (5) テストファースト (6) タコツボ実装を避ける

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript (strict)
- Tailwind CSS v4 (CSS-first `@theme` / `@utility`) + vitest 4 + postcss 8
- 本バッチは **フロントエンドの検査 (vitest) とドキュメントのみ**。PHP / DB / API の変更は 1 行も無い

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、既存テストの作法）
3. TypeScript strict 適合性
4. テスト計画の網羅性（Red を先に確認する形になっているか、空振り防止があるか）
5. 副作用・後退リスク（既存テストを壊さないか、実行時間、Tailwind の版依存）
6. 波及変更の網羅性
7. DESIGN.md 準拠 / design token 運用契約との整合
8. **検査が「守っていると言えること」を誇張していないか**（保証範囲の記述の正確さ）

【この詳細設計に固有の重要論点 — 必ず言及すること】
- `tokens.test.ts` の 2 層構成 (実 app.css の経路の層 / `source(none)` の密閉の層) が、
  それぞれ意図した壊れ方を本当に検出するか。抜けている壊れ方はないか
- postcss AST 走査ヘルパ (`cssVariables` / `declarationsOf`) の正確性。
  同名 selector が複数回現れる場合・`@media` 入れ子・`:root` 以外での変数宣言をどう扱うべきか
- Red 表 (R1〜R6) の「赤くなる assertion」の割り当てが正しいか。
  特に R2 (`@theme` → `:root`) で A の色が赤にならないという主張は正しいか
- 施策 4 の担当宣言テストを `canonical-source-parity.test.ts` に置く判断
- 施策 5 の集合一致が、実際に「検査を足したのに文書に書かない」「検査を消したのに行が残る」の
  両方を検出できるか
- D27 の登録内容が `docs/template-divergence.md` の書式規約 (9 行ちょうど・値域) に適合しているか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 設計時に実測で確かめた事実（前提として扱ってよい）

いずれも `/workspace` で postcss + @tailwindcss/postcss を実行して確認済み:

1. 文字列入力 + 実在しない `from` パスでも、相対 `@import "../../../resources/css/tokens.css"` は解決される
2. `@import "tailwindcss";` のままだと自動ソース走査が働き 46,667 文字を生成する。
   `@import "tailwindcss" source(none);` + `@source inline(...)` だと 7,682 文字になり、
   アプリ由来の class (`.flex` 等) は 1 件も混ざらない
3. `@source inline` に候補を与えないと utility は 1 つも生成されない
4. hover variant の出力は `.hover\:bg-primary-hover { &:hover { @media (hover: hover) { background-color: var(--color-primary-hover); } } }` の 2 段入れ子
5. 実 app.css のコンパイルは 832ms / 60,726 文字
6. app.css から `@import './tokens.css'` を外すと `--color-primary` / `.bg-primary` / `.text-body` が消える
7. `@import` の**順序**を入れ替えても生成物は壊れない
8. tokens.css を外しても Tailwind 既定テーマ由来の `--radius-sm: 0.25rem` / `--radius-md: 0.375rem` /
   `--radius-lg: 0.5rem` と `--font-sans` は残る (名前衝突)
9. `@tailwindcss/postcss` は `from` パスを鍵に結果をキャッシュする
10. 生成される宣言 (実測):
    `.bg-primary` → `background-color: var(--color-primary)` /
    `.text-primary` → `color: var(--color-primary)` /
    `.border-primary` → `border-color: var(--color-primary)` /
    `.rounded-md` → `border-radius: var(--radius-md)` /
    `.text-caption` → `font-family: var(--font-sans); font-size: 12px; font-weight: 400; line-height: 1.5`

---

## 関連する現行コード

### `tests/js/styles/design-md.ts` (全文)

```ts
/**
 * DESIGN.md (canonical source) の frontmatter パーサ — 検査テスト共有。
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const REPO_ROOT = path.resolve(HERE, "../../../");

const designMd = fs.readFileSync(path.join(REPO_ROOT, "DESIGN.md"), "utf-8");

/** DESIGN.md 冒頭の `---` で囲まれた frontmatter 本文 */
export const frontmatter: string = (() => {
    const m = designMd.match(/^---\n([\s\S]*?)\n---/);
    if (!m) throw new Error("DESIGN.md frontmatter not found");
    return m[1];
})();

/** frontmatter `colors:` → `{ トークン名 → "#rrggbb" (小文字) }` */
export function designColors(): Map<string, string> {
    const section = frontmatter.match(/^colors:\n((?: {4}\S[^\n]*\n)+)/m);
    if (!section) throw new Error("DESIGN.md colors section not found");
    const map = new Map<string, string>();
    for (const line of section[1].matchAll(/^ {4}([a-z-]+): "(#[0-9A-Fa-f]{6})"$/gm)) {
        map.set(line[1], line[2].toLowerCase());
    }
    return map;
}

/** frontmatter `rounded:` → `{ 段名 → "Npx" }` */
export function designRounded(): Map<string, string> {
    const section = frontmatter.match(/^rounded:\n((?: {4}\S[^\n]*\n)+)/m);
    if (!section) throw new Error("DESIGN.md rounded section not found");
    const map = new Map<string, string>();
    for (const m of section[1].matchAll(/^ {4}([a-z]+): (\d+px)$/gm)) {
        map.set(m[1], m[2]);
    }
    return map;
}

/** frontmatter `typography.<name>:` → `{ プロパティ名 → 値 }` */
export function designRamp(name: string): Record<string, string> {
    const m = frontmatter.match(new RegExp(`^ {4}${name}:\\n((?: {8}\\S[^\\n]*\\n)+)`, "m"));
    if (!m) throw new Error(`DESIGN.md typography ramp not found: ${name}`);
    const props: Record<string, string> = {};
    for (const line of m[1].matchAll(/^ {8}([a-zA-Z]+): "?([^"\n]+)"?$/gm)) {
        props[line[1]] = line[2];
    }
    return props;
}
```

### `tests/js/styles/inventory.ts` (既存部・抜粋)

```ts
/** DESIGN.md colors キー → tokens.css `--color-<suffix>` の対応 */
export const COLOR_TOKEN_MAP = {
    "primary": "primary",
    "primary-hover": "primary-hover",
    "tertiary": "tertiary",
    "tertiary-hover": "tertiary-hover",
    "neutral": "neutral",
    "surface": "surface",
    "border": "border",
    "border-strong": "border-strong",
    "text-primary": "text",
    "text-secondary": "text-secondary",
    "success": "success",
    "warning": "warning",
    "danger": "danger",
} as const;

/** DESIGN.md frontmatter に現れない派生トークン (rgba 等)。tokens.css にのみ存在してよい。 */
export const DERIVED_COLOR_TOKENS = [
    "primary-soft", // primary 12% — badge / focus ring 用
] as const;

export const RADIUS_TOKENS = ["sm", "md", "lg"] as const;
export const TYPOGRAPHY_RAMPS = ["display", "h1", "h2", "h3", "body", "caption"] as const;

// 以下、contrast-invariant 用の役割宣言 (SURFACE_ROLE_TOKENS / TEXT_ON_SURFACE_TOKENS /
// FILL_TOKENS / FILL_LABEL_TOKENS / CONTRAST_EXEMPT_TOKENS / PENDING_CONTRAST_PAIRS) が続く
```

### `tests/js/styles/canonical-source-parity.test.ts` (全文)

```ts
import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { COLOR_TOKEN_MAP, DERIVED_COLOR_TOKENS, RADIUS_TOKENS, TYPOGRAPHY_RAMPS } from "./inventory";
import { REPO_ROOT, designColors, designRamp, designRounded } from "./design-md";

const tokensCss = fs.readFileSync(path.join(REPO_ROOT, "resources/css/tokens.css"), "utf-8");

function cssColorTokens(): Map<string, string> {
    const map = new Map<string, string>();
    for (const m of tokensCss.matchAll(/--color-([a-z-]+):\s*([^;]+);/g)) {
        map.set(m[1], m[2].replace(/\/\*.*?\*\//g, "").trim().toLowerCase());
    }
    return map;
}

describe("canonical source parity: colors", () => {
    it("DESIGN.md の色集合と tokens.css の --color-* が一致する (set equality)", () => {
        const design = designColors();
        const css = cssColorTokens();
        const expected = [...Object.values(COLOR_TOKEN_MAP), ...DERIVED_COLOR_TOKENS].sort();
        expect([...css.keys()].sort()).toEqual(expected);
        expect([...design.keys()].sort()).toEqual(Object.keys(COLOR_TOKEN_MAP).sort());
    });

    it("DESIGN.md と tokens.css の色の値が一致する (value parity)", () => {
        const design = designColors();
        const css = cssColorTokens();
        for (const [designKey, cssSuffix] of Object.entries(COLOR_TOKEN_MAP)) {
            expect(css.get(cssSuffix), `--color-${cssSuffix}`).toBe(design.get(designKey));
        }
    });
});

describe("canonical source parity: radius", () => {
    it("DESIGN.md rounded と tokens.css の --radius-* が一致する", () => {
        const design = designRounded();
        const css = new Map<string, string>();
        for (const m of tokensCss.matchAll(/--radius-([a-z]+):\s*([^;]+);/g)) {
            css.set(m[1], m[2].trim());
        }
        expect([...css.keys()].sort()).toEqual([...RADIUS_TOKENS].sort());
        for (const key of RADIUS_TOKENS) {
            expect(css.get(key), `--radius-${key}`).toBe(design.get(key));
        }
    });
});

describe("canonical source parity: typography ramp", () => {
    function cssRamp(name: string): Record<string, string> {
        const m = tokensCss.match(new RegExp(`@utility text-${name} \\{([^}]+)\\}`));
        if (!m) throw new Error(`tokens.css @utility not found: text-${name}`);
        const props: Record<string, string> = {};
        for (const line of m[1].matchAll(/([a-z-]+):\s*([^;]+);/g)) {
            props[line[1]] = line[2].trim();
        }
        return props;
    }

    it.each([...TYPOGRAPHY_RAMPS])("text-%s の size/weight/line-height が DESIGN.md と一致する", (name) => {
        const design = designRamp(name);
        const css = cssRamp(name);
        expect(css["font-size"], "font-size").toBe(design["fontSize"]);
        expect(css["font-weight"], "font-weight").toBe(design["fontWeight"]);
        expect(css["line-height"], "line-height").toBe(design["lineHeight"]);
        if (design["letterSpacing"]) {
            expect(css["letter-spacing"], "letter-spacing").toBe(design["letterSpacing"]);
        }
    });

    it("ramp の font-weight は 400/500 のみ (DESIGN.md §Typography)", () => {
        for (const name of TYPOGRAPHY_RAMPS) {
            const css = cssRamp(name);
            expect(["400", "500"], `text-${name} font-weight`).toContain(css["font-weight"]);
        }
    });
});
```

### `DESIGN.md` frontmatter (抜粋)

```yaml
version: "1.0"
name: Slate × Blue (Neutral)
description: テンプレート既定のニュートラルテーマ。…
colors:
    primary: "#2563EB"
    primary-hover: "#1D4ED8"
    tertiary: "#0F766E"
    tertiary-hover: "#115E59"
    neutral: "#F4F4F5"
    surface: "#FFFFFF"
    border: "#E4E4E7"
    border-strong: "#A1A1AA"
    text-primary: "#18181B"
    text-secondary: "#52525B"
    success: "#15803D"
    warning: "#B45309"
    danger: "#B91C1C"
typography:
    display:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 48px
        fontWeight: 500
        lineHeight: 1.2
        letterSpacing: 0.02em
    # h1 / h2 / h3 / body / caption が同じ形で続く (h2 以降は letterSpacing 無し)
rounded:
    sm: 4px
    md: 6px
    lg: 8px
spacing:
    xs: 4px
    sm: 8px
    md: 16px
    lg: 24px
    xl: 40px
```

### `resources/css/tokens.css` (抜粋)

```css
@theme {
    --color-primary:         #2563eb;
    --color-primary-hover:   #1d4ed8;
    --color-primary-soft:    rgba(37, 99, 235, 0.12);  /* primary 12% */
    /* … tertiary / neutral / surface / border / text / success / warning / danger … */
    --font-sans:  'Noto Sans JP', 'Hiragino Sans', 'Yu Gothic UI', 'Segoe UI',
                  ui-sans-serif, system-ui, sans-serif, …;
    --radius-sm: 4px;
    --radius-md: 6px;
    --radius-lg: 8px;
}

@utility text-display {
    font-family: var(--font-sans);
    font-size: 48px;
    font-weight: 500;
    line-height: 1.2;
    letter-spacing: 0.02em;
}
/* h1 / h2 / h3 / body / caption が続く */
```

### `resources/css/app.css` (先頭)

```css
@import 'tailwindcss';
@import './tokens.css';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../**/*.blade.php';
@source '../**/*.ts';
@source '../**/*.svelte';

/* 以降 bfcache 秘匿オーバーレイの CSS が続く */
```

### `docs/design-system.md` の現行の節構成

```
## Canonical source の宣言        (表: /DESIGN.md, /resources/css/tokens.css,
                                   /resources/css/app.css (`@import "./tokens.css"`),
                                   /tests/js/support/ds-purity.ts, /docs/design-system.md)
## トークン変更時の運用契約        (チェックリスト 4 行 + 「片方だけ更新する PR は merge しない」)
## テーマの差し替え方(テンプレート派生アプリ向け)
## 新規 domain 色トークン追加の必須条件(4 条件)
## file-scoped allowlist の運用
## コンポーネント追加時のチェックリスト
```

### `docs/template-divergence.md` の書式規約 (要約)

- 冒頭に `登録エントリ: N 件` の行がある (現在 25 件)。件数は機械で突き合わせている
- 各エントリは `## D<n> <要約>` + **9 行ちょうど**のメタ表 (この順序):
  対象パス / 業務要件起因の説明 / 揃え続ける不変条件と保証機構 / 再判定の条件 /
  決めた日 / 決めた人 / 根拠 / 状態 / 見直し期限
- 対象パス: リポジトリ相対のファイルパスをバッククォート囲みで 1 件以上、区切りは ` / `。
  glob・絶対パス不可。**ファイルとして実在すること**。全登録の和集合で重複しないこと
- 決めた人: `オーナー` / `開発者`
- 根拠: `T<n>` (3 桁以上・TODO の表に実在) または `devnotes/<dir>/` (実在)
- 状態: `恒久` / `監視中`。見直し期限は `恒久` なら全角ダッシュ 1 文字
- セルの中に縦棒を書かない
- 番号 `D<n>` は再利用しない (現在の最大は D26)
- 形式は `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する

---

## 詳細設計書

<!-- DETAILED_DESIGN_BEGIN -->

# 詳細設計: design-token-t1-tests (機能 `design-token-system` の正典 t1 追従)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を
生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

> 本バッチは **フロントエンドの検査のみ**で、PHP / DB / LLM / 課金のいずれにも触れない。
> 4〜7 は構造的に該当しない。

### コーディングルール

- **TypeScript strict** 必須（`pnpm typecheck`）。`any` / 非 null 断定 / 型アサーションで黙らせない
- **vitest**（`pnpm test`）。既存 include (`tests/js/**/*.test.ts`) に自動で入るので
  `scripts/test-inventory-config.ts` の変更は不要
- **JavaScript を新規に足さない**（新規テストは `.ts`）
- **コメントは日本語**
- `pnpm lint` / `pnpm lint:fix`（eslint。走査対象は `resources/js` なのでテストは対象外だが、
  書式は既存テストに揃える = 4 スペースインデント・ダブルクォート）
- PHP 側の検証コマンド（`composer test` / `composer phpstan` / `vendor/bin/pint`）は
  本バッチでは変更が無いが、`docs/template-divergence.md` を触るため
  `TemplateDivergenceLedgerFormatTest` が回る → **`composer test` は必ず流す**

## 概念設計リファレンス

- `devnotes/20260818-0248-design-token-t1-tests/conceptual-design.md`（APPROVED / Round 5）
- 前提の実測: `devnotes/20260818-0248-design-token-t1-tests/probe-tailwind-compile.mjs`
  / `probe-appcss-compile.mjs` / `probe-utility-props.mjs`（設計時の一時スクリプト）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 共有パーサに frontmatter 節の取り出しを足す | `tests/js/styles/design-md.ts` | 高 (施策 2・3 の前提) |
| 2 | inventory へ 4 つの宣言を足す | `tests/js/styles/inventory.ts` | 高 (施策 3・4 の前提) |
| 3 | `tokens.test.ts` の新設（経路の層 + 密閉の層） | `tests/js/styles/tokens.test.ts` (新規) | 高 |
| 4 | frontmatter 節の担当宣言を機械で固定する | `tests/js/styles/canonical-source-parity.test.ts` | 中 |
| 5 | `design-system-docs.test.ts` の新設 | `tests/js/styles/design-system-docs.test.ts` (新規) | 中 |
| 6 | `docs/design-system.md` に §検査の責務境界 を新設 | `docs/design-system.md` | 中 (施策 5 の前提) |
| 7 | 逸脱登録 D27 | `docs/template-divergence.md` | 高 (施策 3 と同一 PR) |

### 波及変更（全施策まとめ）

- TypeScript 型定義: 追加は `tests/js/styles/inventory.ts` 内で閉じる（`resources/js` 側は無変更）
- API Resource / DTO: **なし**（サーバ側の変更が 1 行も無い）
- Inertia Props: **なし**
- Svelte component: **なし**（トークンの値も class の使い方も変えない）
- テストファイル: 新規 2 本 + 既存 1 本（`canonical-source-parity.test.ts`）へ追記
- ビルド設定 / 依存: **なし**（`postcss` `@tailwindcss/postcss` `tailwindcss` は既に devDependencies）

## 実装順序（テストファースト）

1. 施策 1・2（宣言と共有パーサ）→ 型が通ることだけ確認
2. **Red を作って確認する**（下の R1〜R6）
3. 施策 3・4・5 を実装して Green にする
4. 施策 6（文書）→ 施策 5 の目録検査が Green になる
5. 施策 7（D27）→ `composer test` の `TemplateDivergenceLedgerFormatTest` が Green になる

### 先に確認する Red（AGENTS.md 思考原則 5）

| # | Red の作り方 | 赤くなる assertion | 既存 2 本の反応 |
|---|---|---|---|
| R1 | `resources/css/app.css` から `@import './tokens.css'` を消す | 施策 3 の **F**（経路の層のアンカー 4 件と `.bg-primary` 規則）+ **G**（先頭 2 行） | 緑のまま |
| R2 | `tokens.css` の `@theme { … }` を `:root { … }` に書き換える | **C**（色 utility が生成されない）/ **D**（hover が生成されない）/ **E**（`rounded-*` は既定テーマから生成されるが値が `0.25rem` 等になり `var(--radius-*)` でなくなる）。**A の色は赤にならない**（生の CSS 変数として残るため）。**A の radius / font も赤にならない**（既定テーマの値が入るが、値は px と rem で違うので radius は赤になる。font は既定値になるので赤になる） | 緑のまま |
| R3 | `tokens.css` の `@utility text-body { … }` を消す | **B**（`.text-body` の宣言が空になる） | 緑のまま |
| R4 | `tokens.css` の `--color-danger` の値だけを変える | **A**（生成 CSS の値が DESIGN.md と食い違う） | **`canonical-source-parity` も赤**（同じ値の不一致を違う段で見ている。重複ではない） |
| R5 | `docs/design-system.md` から運用契約の 1 節を丸ごと消す | 施策 5 の「節の実在と本文の非空」 | 緑のまま |
| R6 | `tests/js/styles/` に検査ファイルを 1 本足して文書の表に書かない | 施策 5 の「双方向の集合一致」 | 緑のまま |

> R2 の「A の radius / font」は、Tailwind 既定テーマとの**名前衝突**があるため
> 「存在するか」では空振りし、**値**を見て初めて赤くなる（実測: 既定は
> `--radius-sm: 0.25rem` / `--radius-md: 0.375rem` / `--radius-lg: 0.5rem`）。
> このことを施策 3 の A のコメントに書く。

---

## 施策 1: 共有パーサに frontmatter 節の取り出しを足す

### 変更箇所

- ファイル: `tests/js/styles/design-md.ts`（末尾に 1 関数追加。既存の export は触らない）

### 波及変更

- TypeScript 型定義: なし（`readonly string[]` を返すだけ）
- API Resource/DTO: なし
- テストファイル: 施策 4（`canonical-source-parity.test.ts`）が新関数を使う

### 変更後コード（追記分）

```ts
/**
 * frontmatter の**最上位の節名**を宣言順で返す。
 *
 * 「どの節がどの検査の担当か」を既定拒否で宣言するための入力
 * (tests/js/styles/inventory.ts の FRONTMATTER_SECTION_OWNERS)。
 * 入れ子の子キー (typography.display 等) は含めない — 担当の宣言は節の粒度で行う。
 */
export function designFrontmatterSections(): readonly string[] {
    const sections: string[] = [];
    for (const m of frontmatter.matchAll(/^([a-zA-Z][a-zA-Z0-9-]*):/gm)) {
        sections.push(m[1]);
    }
    return sections;
}
```

### PHPStan 適合チェック

- 本施策は PHP を含まない（該当なし）

### TypeScript 適合チェック

- [x] 戻り値の型が明示されている（`readonly string[]`）
- [x] `any` / 非 null 断定を使っていない
- [x] `matchAll` の要素添字 `m[1]` は捕獲グループが必ず存在する形（`isolatedModules` / strict で問題なし）

### テスト計画

- [ ] 施策 4 の「宣言が frontmatter の全節を覆う」テストが、この関数の出力を母集団にする
- [ ] 空振り防止として「節が 0 件でない」を同テストに置く（正規表現が degrade したら赤になる）

### リスク

- frontmatter の書式が変わる（インデントや引用の付け方）と節の抽出が壊れる。
  ただし壊れれば節の集合が変わり、担当宣言との集合一致が**赤になる**ので黙って通らない

---

## 施策 2: inventory へ 4 つの宣言を足す

### 変更箇所

- ファイル: `tests/js/styles/inventory.ts`（末尾に追記。既存の export は 1 つも変えない）

### 波及変更

- テストファイル: 施策 3・4 が読む

### 変更後コード（追記分）

```ts
/*
 * ===== 生成 CSS 検査の入力 (tokens.test.ts) =====
 */

/**
 * tokens.css が持つ `--color-<suffix>` の全件。
 *
 * COLOR_TOKEN_MAP (DESIGN.md 由来) と DERIVED_COLOR_TOKENS (tokens.css 固有の派生) の和で、
 * これが tokens.css の `--color-*` 全件と一致することは
 * canonical-source-parity の集合一致テストが固定している。したがってこの配列は
 * 「定義上の全件」であり、tokens.test.ts はここから母集団を作る。
 */
export const CSS_COLOR_SUFFIXES = [
    ...Object.values(COLOR_TOKEN_MAP),
    ...DERIVED_COLOR_TOKENS,
] as const satisfies readonly string[];

/**
 * 生成 CSS で**値**の一致を検査しないトークン (理由必須)。
 * 出現そのものは検査するので、「見ていない」のは値だけである。
 */
export const COMPILED_VALUE_EXEMPT_TOKENS = {
    "primary-soft":
        "DESIGN.md frontmatter に現れない派生トークン (rgba)。期待値を正本から導出できないため" +
        "値の突き合わせは行わず、生成 CSS への出現までを検査する。値の正本は tokens.css で、" +
        "集合としての存在は canonical-source-parity が固定している",
} as const;

/**
 * 経路の層 (実 app.css のコンパイル) で**必ず現れることを求める**トークン。
 *
 * これは**アンカー集合であって全件ではない**。経路の層の生成物はアプリ側の class 使用状況に
 * 依存するため、全件の網羅は密閉の層が担う。ここに並べるのは画面の土台
 * (レイアウト・本文・面・主 CTA) が使う 4 件に限る。
 *
 * **アンカーが使われなくなったときの直し方**: テストを緩めるのではなく、
 * 土台に相当する別のトークンへ差し替える (集合を縮めて緑にしない)。
 */
export const ROUTE_LAYER_ANCHOR_TOKENS = ["primary", "text", "surface", "neutral"] as const;

/*
 * ===== DESIGN.md frontmatter の節ごとの担当宣言 (既定拒否) =====
 *
 * frontmatter の最上位の節は下の 3 分類の**いずれかに必ず属する**。
 * 未分類の節があれば canonical-source-parity が fail する
 * = 正本に節を足したのに誰も見ていない状態を作れない。
 */

/** 節を検査している gate の識別子 (人が読む名前。ファイル名の語幹に合わせる)。 */
export type DesignGateName =
    | "canonical-source-parity"
    | "tokens"
    | "contrast-invariant";

export type FrontmatterSectionOwner =
    /** 検査されている節。どの gate が見ているかを列挙する */
    | { readonly kind: "checked"; readonly by: readonly DesignGateName[] }
    /** 実装写像を持たないメタ情報 (理由必須) */
    | { readonly kind: "metadata"; readonly reason: string }
    /**
     * 未検査であることの明示宣言 (理由・解消条件・追跡先の 3 つが必須)。
     * 追跡先は `T<3 桁以上>` (TODO の表に実在する行) か `devnotes/<dir>/` (実在するディレクトリ)。
     */
    | {
          readonly kind: "pending";
          readonly reason: string;
          readonly exit: string;
          readonly tracking: string;
      };

export const FRONTMATTER_SECTION_OWNERS: Readonly<Record<string, FrontmatterSectionOwner>> = {
    version: { kind: "metadata", reason: "テーマの版。実装写像を持たない" },
    name: { kind: "metadata", reason: "テーマの名前。実装写像を持たない" },
    description: { kind: "metadata", reason: "テーマの説明文。実装写像を持たない" },
    colors: {
        kind: "checked",
        by: ["canonical-source-parity", "tokens", "contrast-invariant"],
    },
    typography: { kind: "checked", by: ["canonical-source-parity", "tokens"] },
    rounded: { kind: "checked", by: ["canonical-source-parity", "tokens"] },
    spacing: {
        kind: "pending",
        reason:
            "tokens.css に --spacing-* の写像が無く、どの検査も見ていない。" +
            "Tailwind 既定の spacing で足りているのか写像の作り忘れなのかが未決である",
        exit:
            "tokens.css に写像を作って canonical-source-parity と tokens の担当に移すか、" +
            "frontmatter から spacing を外すかを決めたら、本項目を削る",
        tracking: "devnotes/20260818-0248-design-token-t1-tests/",
    },
};
```

### TypeScript 適合チェック

- [x] 判別可能 union（`kind`）で分岐が型で保証される
- [x] `as const` + `satisfies` で綴りの取りこぼしを compile-time に検出
- [x] `any` / 非 null 断定なし

### テスト計画

- [ ] 施策 3・4 が本宣言を読む（宣言そのものに専用テストは持たない = 使われることが検査になる）

### リスク

- `CSS_COLOR_SUFFIXES` が `COLOR_TOKEN_MAP` の**値**（CSS 側 suffix）を使うため、
  DESIGN.md キーと CSS suffix が違うトークン（`text-primary` → `text`）で取り違えやすい。
  施策 3 の A では **キー側（DESIGN.md）から値側（CSS）へ写像して**突き合わせるので、
  取り違えると値が一致せず赤になる

---

## 施策 3: `tokens.test.ts` の新設（経路の層 + 密閉の層）

### 変更箇所

- ファイル: `tests/js/styles/tokens.test.ts`（新規）

### 波及変更

- TypeScript 型定義: postcss の公開型（`Root`）を import する。追加の型定義は作らない
- API Resource/DTO: なし
- テストファイル: 本ファイルが新規

### 変更後コード

```ts
import { beforeAll, describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import postcss, { type Root } from "postcss";
import tailwindcss from "@tailwindcss/postcss";
import { REPO_ROOT, designColors, designRamp, designRounded } from "./design-md";
import {
    COLOR_TOKEN_MAP,
    COMPILED_VALUE_EXEMPT_TOKENS,
    CSS_COLOR_SUFFIXES,
    RADIUS_TOKENS,
    ROUTE_LAYER_ANCHOR_TOKENS,
    TYPOGRAPHY_RAMPS,
} from "./inventory";

/*
 * tokens.test — tokens.css の宣言が Tailwind のコンパイルを通って生成 CSS に出ることを検査する。
 *
 * 【他の検査との境界】
 *   canonical-source-parity : DESIGN.md ⇔ tokens.css の**テキスト**一致 (正本 ⇔ 宣言)
 *   本ファイル              : tokens.css ⇒ **Tailwind 生成 CSS** (宣言 ⇒ 生成物)
 *   contrast-invariant      : DESIGN.md の色の可読性
 *   検査範囲は一部重なる。トークンの値を変えれば parity と本ファイルの双方が赤になり得るが、
 *   Tailwind の解釈が壊れる形 (`@theme` が効かなくなる等) は本ファイルだけが検出する。
 *
 * 【2 層に分ける理由】
 *   経路の層 (実 app.css) : アプリの入口から tokens.css へ実際に繋がっていることを見る。
 *                            取り込みを外すと赤になる。**アンカー集合であって全件ではない**
 *   密閉の層 (組み立て入力): `source(none)` で自動走査を止め、`@source inline` で候補を明示供給する。
 *                            アプリの class 使用状況に依存せず全件を見る
 *
 * 【保証しないもの】
 *   - Vite のビルド・アセット配信・ブラウザでの適用 (生成 CSS より先は見ていない)
 *   - `@import` の**順序**を入れ替えたときの破綻。実測では順序を入れ替えても生成物は壊れない。
 *     順序はリポジトリ規約であり、その固定は下の「取り込みの規約」が行う
 *   - 共有パーサ (design-md.ts) の**値の誤解析**。キーの取りこぼしは canonical-source-parity の
 *     集合一致が検出するが、値を誤って読む形は本ファイルも parity も同じ誤りを見るので検出できない
 */

/* ===== 検査対象の utility 候補 (注入と検査を同じ 1 つの値から作る) ===== */

const UTILITY_CANDIDATES = {
    color: CSS_COLOR_SUFFIXES.flatMap((s) => [`bg-${s}`, `text-${s}`, `border-${s}`]),
    radius: RADIUS_TOKENS.map((r) => `rounded-${r}`),
    ramp: TYPOGRAPHY_RAMPS.map((r) => `text-${r}`),
    hover: CSS_COLOR_SUFFIXES.filter((s) => s.endsWith("-hover")).map((s) => `hover:bg-${s}`),
} as const;

/**
 * 密閉入力を組み立てる。
 *
 * `source(none)` は Tailwind の自動ソース走査を止めるだけなので、
 * **候補を @source inline で与えないと utility は 1 つも生成されない**。
 * 注入する集合と検査する集合は UTILITY_CANDIDATES という 1 つの値から作る (2 か所に書かない)。
 */
function sealedInput(): string {
    const candidates = Object.values(UTILITY_CANDIDATES).flat().join(" ");
    return [
        '@import "tailwindcss" source(none);',
        '@import "../../../resources/css/tokens.css";',
        `@source inline("${candidates}");`,
    ].join("\n");
}

/*
 * @tailwindcss/postcss は `from` に渡したパスを鍵に結果をキャッシュする。
 * 1 つの `from` に 1 つの入力を対応させる (同じ from で別の入力を流さない)。
 * 密閉入力の from は実在しないパスでよい (相対 @import の解決にだけ使われる)。
 */
const SEALED_FROM = path.join(REPO_ROOT, "tests/js/styles/__sealed-tokens-input.css");
const APP_CSS_PATH = path.join(REPO_ROOT, "resources/css/app.css");

/** postcss + Tailwind でコンパイルする。失敗は握り潰さずそのまま伝播させる。 */
async function compile(css: string, from: string): Promise<Root> {
    const result = await postcss([tailwindcss()]).process(css, { from, to: undefined });
    return result.root;
}

/** 生成 CSS 内で宣言されている CSS 変数を集める (入れ子の中も見る)。値は小文字化する。 */
function cssVariables(root: Root): Map<string, string> {
    const vars = new Map<string, string>();
    root.walkDecls((decl) => {
        if (decl.prop.startsWith("--")) vars.set(decl.prop, decl.value.trim().toLowerCase());
    });
    return vars;
}

/**
 * selector が完全一致する規則の宣言を集める。
 * `&:hover` や `@media (hover: hover)` の入れ子の中も walkDecls が降りて拾う
 * (文字列の正規表現で入れ子の深さを仮定しない)。
 */
function declarationsOf(root: Root, selector: string): Map<string, string> {
    const decls = new Map<string, string>();
    root.walkRules((rule) => {
        if (rule.selector !== selector) return;
        rule.walkDecls((decl) => decls.set(decl.prop, decl.value.trim()));
    });
    return decls;
}

/** font-family 宣言の先頭 family を引用符抜きで取り出す。 */
function firstFamily(value: string): string {
    const head = value.split(",")[0].trim();
    return head.replace(/^['"]|['"]$/g, "");
}

let sealed: Root;
let routed: Root;

beforeAll(async () => {
    sealed = await compile(sealedInput(), SEALED_FROM);
    routed = await compile(fs.readFileSync(APP_CSS_PATH, "utf-8"), APP_CSS_PATH);
}, 60_000);

/* ===== 空振り防止 ===== */

describe("tokens: 空振り防止", () => {
    it.each(Object.entries(UTILITY_CANDIDATES))("utility 候補の区分 %s が 0 件でない", (_kind, list) => {
        // 注入と検査を 1 つの値から作るので、組み立てが壊れると両方が同時に空になり
        // 緑のまま通る。区分ごとに非空を確かめてそれを防ぐ。
        expect(list.length).toBeGreaterThan(0);
    });

    it("密閉入力のコンパイル結果が規則を持つ", () => {
        let rules = 0;
        sealed.walkRules(() => rules++);
        expect(rules).toBeGreaterThan(0);
    });

    it("負のコントロール: 実在しない utility の宣言は空になる", () => {
        // declarationsOf が「何にでも一致して緑になる」実装でないことを確かめる
        expect([...declarationsOf(sealed, ".bg-does-not-exist-token")]).toEqual([]);
    });
});

/* ===== A. CSS 変数 (密閉の層) ===== */

describe("tokens/A: @theme 由来の CSS 変数が生成 CSS に期待値で現れる", () => {
    it.each(Object.entries(COLOR_TOKEN_MAP))(
        "DESIGN.md colors.%s の値が --color-%s に届く",
        (designKey, cssSuffix) => {
            const expected = designColors().get(designKey);
            expect(expected, `DESIGN.md colors に ${designKey} が無い`).toBeDefined();
            expect(cssVariables(sealed).get(`--color-${cssSuffix}`)).toBe(expected);
        },
    );

    it.each(Object.entries(COMPILED_VALUE_EXEMPT_TOKENS))(
        "派生トークン --color-%s は出現までを検査する (値は免除)",
        (suffix, _reason) => {
            // 値の突き合わせを免除する理由は inventory.ts の COMPILED_VALUE_EXEMPT_TOKENS に書いてある。
            // 「見ていない」のは値だけで、出現そのものは見る。
            expect(cssVariables(sealed).has(`--color-${suffix}`)).toBe(true);
        },
    );

    it.each([...RADIUS_TOKENS])("DESIGN.md rounded.%s の値が --radius-%s に届く", (key) => {
        // ⚠ Tailwind の既定テーマにも --radius-sm/md/lg がある (0.25rem / 0.375rem / 0.5rem)。
        //    「存在するか」だけでは空振りするので、必ず値を突き合わせる。
        expect(cssVariables(sealed).get(`--radius-${key}`)).toBe(designRounded().get(key));
    });

    it("ramp の font-family が 1 つに揃っており、--font-sans の先頭 family と一致する", () => {
        // ⚠ --font-sans も Tailwind 既定テーマに存在する。ここも値で見る。
        const families = new Set(TYPOGRAPHY_RAMPS.map((r) => designRamp(r)["fontFamily"]));
        expect(families.size, "DESIGN.md の ramp が複数の fontFamily を宣言している").toBe(1);

        const declared = [...families][0];
        const fontSans = cssVariables(sealed).get("--font-sans");
        expect(fontSans, "--font-sans が生成 CSS に無い").toBeDefined();
        expect(firstFamily(fontSans ?? "")).toBe(firstFamily(declared).toLowerCase());
    });
});

/* ===== B. typography ramp utility (密閉の層) ===== */

describe("tokens/B: ramp utility が DESIGN.md の値で生成される", () => {
    it.each([...TYPOGRAPHY_RAMPS])("text-%s が 4 プロパティを持つ", (name) => {
        const design = designRamp(name);
        const decls = declarationsOf(sealed, `.text-${name}`);

        expect(decls.get("font-family")).toBe("var(--font-sans)");
        expect(decls.get("font-size")).toBe(design["fontSize"]);
        expect(decls.get("font-weight")).toBe(design["fontWeight"]);
        expect(decls.get("line-height")).toBe(design["lineHeight"]);
        if (design["letterSpacing"]) {
            expect(decls.get("letter-spacing")).toBe(design["letterSpacing"]);
        }
    });
});

/* ===== C. 色 utility (密閉の層) ===== */

describe("tokens/C: 色 utility が var(--color-*) を参照して生成される", () => {
    it.each([...CSS_COLOR_SUFFIXES])("bg-%s / text-%s / border-%s が解決する", (suffix) => {
        const token = `var(--color-${suffix})`;
        expect(declarationsOf(sealed, `.bg-${suffix}`).get("background-color")).toBe(token);
        expect(declarationsOf(sealed, `.text-${suffix}`).get("color")).toBe(token);
        expect(declarationsOf(sealed, `.border-${suffix}`).get("border-color")).toBe(token);
    });
});

/* ===== D. hover variant (密閉の層) ===== */

describe("tokens/D: hover variant が解決する", () => {
    it.each([...UTILITY_CANDIDATES.hover])("%s が hover 時の背景色になる", (utility) => {
        // Tailwind v4 は `.hover\:bg-x { &:hover { @media (hover: hover) { … } } }` と
        // 2 段入れ子で出す。selector の形を仮定せず AST を降りて宣言を拾う。
        const suffix = utility.replace("hover:bg-", "");
        const selector = `.hover\\:bg-${suffix}`;
        expect(declarationsOf(sealed, selector).get("background-color")).toBe(
            `var(--color-${suffix})`,
        );
    });
});

/* ===== E. radius utility (密閉の層) ===== */

describe("tokens/E: radius utility が var(--radius-*) を参照する", () => {
    it.each([...RADIUS_TOKENS])("rounded-%s が解決する", (key) => {
        expect(declarationsOf(sealed, `.rounded-${key}`).get("border-radius")).toBe(
            `var(--radius-${key})`,
        );
    });
});

/* ===== F. 経路の層 (実 app.css) ===== */

describe("tokens/F: 実 app.css のコンパイルで tokens.css が実際に効いている", () => {
    it.each([...ROUTE_LAYER_ANCHOR_TOKENS])(
        "アンカー --color-%s が DESIGN.md の値で現れる",
        (suffix) => {
            // アンカー集合であって全件ではない (全件は密閉の層が見る)。
            // アンカーが使われなくなったら、テストを緩めず土台の別トークンへ差し替える。
            const designKey = Object.entries(COLOR_TOKEN_MAP).find(([, v]) => v === suffix)?.[0];
            expect(designKey, `COLOR_TOKEN_MAP に --color-${suffix} の対応が無い`).toBeDefined();
            expect(cssVariables(routed).get(`--color-${suffix}`)).toBe(
                designColors().get(designKey ?? ""),
            );
        },
    );

    it("主 CTA の塗り (.bg-primary) が生成される", () => {
        expect(declarationsOf(routed, ".bg-primary").get("background-color")).toBe(
            "var(--color-primary)",
        );
    });

    it("生成された自前トークンの値はすべて DESIGN.md と一致する", () => {
        // アンカー以外にも出ているトークンがあれば、ついでに値を確かめる (母集団は要求しない)。
        const vars = cssVariables(routed);
        for (const [designKey, cssSuffix] of Object.entries(COLOR_TOKEN_MAP)) {
            const actual = vars.get(`--color-${cssSuffix}`);
            if (actual === undefined) continue;
            expect(actual, `--color-${cssSuffix}`).toBe(designColors().get(designKey));
        }
    });
});

/* ===== G. 取り込みの規約 (テキスト検査) ===== */

describe("tokens/G: app.css の入口 2 行の規約", () => {
    it("非空・非コメントの先頭 2 行が tailwindcss → ./tokens.css の順である", () => {
        // これは**規約**の固定であって動作の不変条件ではない。
        // 実測では @import の順序を入れ替えても Tailwind v4 の生成物は壊れなかった。
        // 取り込みが失われる形の破綻は F (経路の層) が検出する。
        const meaningful = fs
            .readFileSync(APP_CSS_PATH, "utf-8")
            .split(/\r?\n/)
            .map((line) => line.trim())
            .filter((line) => line !== "" && !/^(\/\*|\*|\*\/|\/\/)/.test(line));

        expect(meaningful.length).toBeGreaterThanOrEqual(2);
        expect(meaningful[0]).toMatch(/^@import\s+["']tailwindcss["']\s*;$/);
        expect(meaningful[1]).toMatch(/^@import\s+["']\.\/tokens\.css["']\s*;$/);
    });
});
```

### TypeScript 適合チェック

- [x] postcss の公開型 `Root` を使い、型アサーション・非 null 断定を使わない
- [x] `compile()` は `Promise<Root>` を明示
- [x] `beforeAll` で例外を握り潰さない（`try/catch` を書かない）
- [x] `Map#get` の戻りが `string | undefined` であることを前提に、`toBe(undefined)` で落ちる形にしている
      （`!` で潰さない）
- [x] `designKey ?? ""` は「見つからない」を先に `toBeDefined()` で落としてから使う

### テスト計画

- [ ] R1〜R4 を先に作って fail を確認してから実装する
- [ ] 実装後、既存の `canonical-source-parity` / `contrast-invariant` が緑のままであることを確認する
- [ ] `pnpm test` の実行時間の増分を測る（実測の見込み: 密閉 + 経路で 1 秒前後）
- [ ] 個別の `DatabaseTransactions` は使っていない（PHP テストではない）

### リスク

- **Tailwind の版が上がると生成 CSS の形が変わる**（`@media (hover: hover)` の有無など）。
  AST を降りて宣言を拾う形なので selector の入れ子には強いが、`background-color` を
  別プロパティ（例: `--tw-*` 経由）へ変える変更には追随が要る。
  そのときは「テストを緩める」のではなく**新しい出力形に合わせて読み方を直す**
- **経路の層はアプリの class 使用状況に依存する**。アンカー 4 件は画面の土台で使われているものに
  限っており（実測: `bg-primary` 17 / `text-text` 106 / `bg-surface` 47 / `bg-neutral` 35）、
  当面の変動には耐える。使われなくなったら差し替える（縮めない）
- **`@source inline` の候補文字列に空白以外の区切りを入れると壊れる**。候補は
  utility 名だけなので現状は問題ないが、将来 `/` 修飾（`bg-text/70` 等）を足すときは
  引用の扱いを確かめること（本バッチでは alpha 修飾を候補に入れない）

---

## 施策 4: frontmatter 節の担当宣言を機械で固定する

### 変更箇所

- ファイル: `tests/js/styles/canonical-source-parity.test.ts`（末尾に describe を 1 つ追加）

### 波及変更

- テストファイル: 本ファイルのみ（既存 assertion は 1 つも変えない）

### 変更後コード（追記分）

```ts
import { designFrontmatterSections } from "./design-md";
import { FRONTMATTER_SECTION_OWNERS } from "./inventory";

/**
 * DESIGN.md frontmatter の節が、どの検査の担当かを既定拒否で固定する。
 *
 * 正本に節を足したのに誰も見ていない、という状態を作れないようにするための宣言。
 * 未検査の節は kind: "pending" として理由・解消条件・追跡先つきで登録する
 * (「検査があるから守られている」という誤読を防ぐ明示宣言であって免罪符ではない)。
 */
describe("canonical source parity: frontmatter の節の担当宣言", () => {
    const sections = designFrontmatterSections();

    it("節が 0 件でない (抽出の空振り防止)", () => {
        expect(sections.length).toBeGreaterThan(0);
    });

    it("宣言と frontmatter の節が集合一致する (既定拒否)", () => {
        expect([...sections].sort(), "未宣言の節、または実在しない節の宣言がある").toEqual(
            Object.keys(FRONTMATTER_SECTION_OWNERS).sort(),
        );
    });

    it("pending 宣言は理由・解消条件・追跡先をすべて埋めている", () => {
        for (const [section, owner] of Object.entries(FRONTMATTER_SECTION_OWNERS)) {
            if (owner.kind !== "pending") continue;
            expect(owner.reason.length, `${section}: reason`).toBeGreaterThan(30);
            expect(owner.exit.length, `${section}: exit`).toBeGreaterThan(30);
            expect(owner.tracking.length, `${section}: tracking`).toBeGreaterThan(0);
        }
    });

    it("pending の追跡先が実在する (書式だけ整った死んだ参照を作らせない)", () => {
        const todo =
            fs.readFileSync(path.join(REPO_ROOT, "docs/TODO.md"), "utf-8") +
            fs.readFileSync(path.join(REPO_ROOT, "docs/TODO-closed.md"), "utf-8");

        for (const [section, owner] of Object.entries(FRONTMATTER_SECTION_OWNERS)) {
            if (owner.kind !== "pending") continue;
            const { tracking } = owner;
            if (/^T\d{3,}$/.test(tracking)) {
                expect(todo, `${section}: ${tracking} が TODO の表に無い`).toContain(tracking);
                continue;
            }
            expect(tracking, `${section}: 追跡先の書式`).toMatch(/^devnotes\/[\w.-]+\/$/);
            expect(
                fs.existsSync(path.join(REPO_ROOT, tracking)),
                `${section}: ${tracking} が実在しない`,
            ).toBe(true);
        }
    });

    it("metadata 宣言は理由を持つ", () => {
        for (const [section, owner] of Object.entries(FRONTMATTER_SECTION_OWNERS)) {
            if (owner.kind !== "metadata") continue;
            expect(owner.reason.length, `${section}: reason`).toBeGreaterThan(0);
        }
    });

    it("checked 宣言は担当 gate を 1 つ以上持つ", () => {
        for (const [section, owner] of Object.entries(FRONTMATTER_SECTION_OWNERS)) {
            if (owner.kind !== "checked") continue;
            expect(owner.by.length, `${section}: by`).toBeGreaterThan(0);
        }
    });
});
```

### TypeScript 適合チェック

- [x] `owner.kind !== "pending"` で早期 continue → union が絞られ、`owner.reason` に型が付く
- [x] `fs` / `path` / `REPO_ROOT` は同ファイルで既に import 済み（`REPO_ROOT` は `./design-md` から）

### テスト計画

- [ ] Red: `FRONTMATTER_SECTION_OWNERS` から `spacing` を消すと集合一致が赤になることを確認
- [ ] Red: `tracking` を実在しない `devnotes/no-such-dir/` にすると赤になることを確認

### リスク

- 本 describe を `canonical-source-parity.test.ts` に置くのは、同ファイルが既に
  「DESIGN.md ⇔ 実装」の関係を持っているためである。`tokens.test.ts` に置くと
  「生成 CSS の検査」という責務からはみ出す（施策 3 のファイル冒頭コメントと食い違う）

---

## 施策 5: `design-system-docs.test.ts` の新設

### 変更箇所

- ファイル: `tests/js/styles/design-system-docs.test.ts`（新規）

### 波及変更

- ドキュメント: 施策 6（`docs/design-system.md` に §検査の責務境界 を新設）が**前提**。
  施策 6 が無いと本テストは赤になる

### 変更後コード

```ts
import { beforeAll, describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { REPO_ROOT } from "./design-md";

/*
 * design-system-docs — docs/design-system.md の**構造**が壊れていないことを検査する。
 *
 * 【見るもの】節の実在と本文の非空 / 表に並ぶパスの実在 / 検査目録の双方向の集合一致
 * 【見ないもの】散文。言い回しの一致は検査しない (文章を良くする PR を止めないため)
 *
 * 【保証しないもの】
 *   本テストが守るのは「所定の文書構造と検査目録の同期」であって、
 *   **運用契約の意味が残っていること**ではない。節が空でなく表が実体と一致していても、
 *   中身が骨抜きになっていることは検出できない。
 */

const DOC_PATH = path.join(REPO_ROOT, "docs/design-system.md");

/** 本文を持つことを求める節 (見出し行そのもの)。 */
const REQUIRED_SECTIONS = [
    "## Canonical source の宣言",
    "## トークン変更時の運用契約",
    "## 検査の責務境界",
    "## 新規 domain 色トークン追加の必須条件(4 条件)",
    "## file-scoped allowlist の運用",
] as const;

/**
 * 責務境界表に載っていなければならない検査ファイルの母集団。
 * `tests/js/styles/*.test.ts` の実在分 + コントラスト検査 (置き場所だけ architecture 側)。
 */
function gateFiles(): readonly string[] {
    const stylesDir = path.join(REPO_ROOT, "tests/js/styles");
    const styles = fs
        .readdirSync(stylesDir)
        .filter((name) => name.endsWith(".test.ts"))
        .map((name) => `tests/js/styles/${name}`);
    return [...styles, "tests/js/architecture/contrast-invariant.test.ts"].sort();
}

/**
 * 見出しから、次の同レベル以上の見出しまでの本文を切り出す。
 * `## X` の中の `### Y` は同じ節の本文として残る。
 */
function extractSection(doc: string, heading: string): readonly string[] {
    const lines = doc.split(/\r?\n/);
    const start = lines.indexOf(heading);
    if (start < 0) return [];
    const level = (heading.match(/^#+/) ?? [""])[0].length;
    const rest = lines.slice(start + 1);
    const end = rest.findIndex((line) => new RegExp(`^#{1,${level}}\\s`).test(line));
    return end < 0 ? rest : rest.slice(0, end);
}

let doc: string;

beforeAll(() => {
    doc = fs.readFileSync(DOC_PATH, "utf-8");
});

describe("design-system-docs: 運用契約の節", () => {
    it.each([...REQUIRED_SECTIONS])("%s が存在し、本文を持つ", (heading) => {
        const body = extractSection(doc, heading);
        expect(body.length, `${heading} が見つからない`).toBeGreaterThan(0);
        expect(
            body.some((line) => line.trim() !== ""),
            `${heading} の本文が空`,
        ).toBe(true);
    });
});

describe("design-system-docs: Canonical source 表のパス", () => {
    it("表に並ぶリポジトリ相対パスがすべて実在する", () => {
        const body = extractSection(doc, "## Canonical source の宣言").join("\n");
        // バッククォート囲みのうち `/` 始まりのものだけをリポジトリ相対パスとして扱う
        // (同じ表には `@import "./tokens.css"` のようなコード片も入るため)。
        const paths = [...body.matchAll(/`(\/[^`]+)`/g)].map((m) => m[1].slice(1));

        expect(paths.length, "表からパスが 1 件も取れない (抽出の空振り)").toBeGreaterThan(0);
        for (const relative of paths) {
            expect(
                fs.existsSync(path.join(REPO_ROOT, relative)),
                `Canonical source 表の ${relative} が実在しない`,
            ).toBe(true);
        }
    });
});

describe("design-system-docs: 検査目録の同期", () => {
    it("責務境界表と実在する検査ファイルが集合一致する (双方向)", () => {
        // 片側だけでは足りない —
        //   実体 → 文書 だけ: 検査を消したのに表の行が残るのを止められない
        //   文書 → 実体 だけ: 検査を足したのに書かないのを止められない
        const body = extractSection(doc, "## 検査の責務境界").join("\n");
        const listed = [...body.matchAll(/`(tests\/[^`]+\.test\.ts)`/g)].map((m) => m[1]).sort();

        expect(listed.length, "責務境界表からパスが 1 件も取れない (抽出の空振り)").toBeGreaterThan(0);
        expect(listed, "文書の責務境界表と実在する検査ファイルが食い違っている").toEqual(
            [...gateFiles()],
        );
    });
});
```

### TypeScript 適合チェック

- [x] `readonly string[]` を戻り値に明示
- [x] `heading.match(/^#+/) ?? [""]` で `null` を型で潰す（`!` を使わない）
- [x] `it.each([...REQUIRED_SECTIONS])` で readonly tuple を展開（vitest の型に合う）

### テスト計画

- [ ] Red: `docs/design-system.md` から「## file-scoped allowlist の運用」を消すと赤
- [ ] Red: `tests/js/styles/` にダミーの `.test.ts` を置くと集合一致が赤
- [ ] Red: Canonical source 表のパスを 1 つ架空のものに変えると赤

### リスク

- **見出し文字列の完全一致に依存する**。文言を直すと赤になるが、それは
  「節を消す・改名する」ことを検出する仕組みそのものなので許容する
  （直す側は `REQUIRED_SECTIONS` を同じ PR で直す）
- `gateFiles()` は `tests/js/styles/*.test.ts` を FS 走査で拾う。
  この場所に**検査以外の `.test.ts` を置かない**ことが前提になる（現状そうなっている）

---

## 施策 6: `docs/design-system.md` に §検査の責務境界 を新設

### 変更箇所

- ファイル: `docs/design-system.md`（§Canonical source の宣言 の直後に節を追加 + チェックリストへ 1 行追加）

### 波及変更

- テストファイル: 施策 5 の集合一致テストが本節を読む

### 変更後コード（追記分）

````markdown
## 検査の責務境界

デザイントークンの検査は 4 本ある。**どれが何を見ているか**を混同しないこと
(見ている写像の段が違うので、片方を消すと別の壊れ方が見えなくなる)。

| 検査 | 見ている写像 | 代表的に検出する壊れ方 |
|------|------------|--------------------|
| `tests/js/styles/canonical-source-parity.test.ts` | DESIGN.md (正本) ⇔ tokens.css (宣言) のテキスト | 片方だけ更新した PR / トークンの増減 |
| `tests/js/styles/tokens.test.ts` | tokens.css (宣言) ⇒ Tailwind 生成 CSS | `@theme` が解釈されない / utility 名が解決しない / app.css が tokens.css を取り込んでいない |
| `tests/js/styles/design-system-docs.test.ts` | 本書の構造 ⇔ 検査ファイルの実体 | 運用契約の節の消失 / 表と実体の食い違い |
| `tests/js/architecture/contrast-invariant.test.ts` | DESIGN.md の色値 ⇒ コントラスト比 | 読めない色の組合せ |

**この表は機械で実体と突き合わせている**。`tests/js/styles/` に検査を足したら本表にも行を足す
(足さないと `design-system-docs.test.ts` が落ちる)。逆に検査を消したら行も消す。

保証しないもの: Vite のビルド・アセット配信・ブラウザでの適用は 4 本のどれも見ていない。
DESIGN.md frontmatter の `spacing:` も現在どの検査も見ていない
(未検査であることは `tests/js/styles/inventory.ts` の `FRONTMATTER_SECTION_OWNERS` に
理由・解消条件・追跡先つきで宣言してある)。
````

チェックリストへの追加（§トークン変更時の運用契約）:

```markdown
- [ ] `/tests/js/styles/inventory.ts`(トークンの追加・削除時。生成 CSS 検査の母集団も兼ねる)
```

> 既存行 `- [ ] /tests/js/styles/inventory.ts(トークンの追加・削除時)` の括弧内を上記へ差し替える。

### テスト計画

- [ ] 施策 5 が本節を読んで緑になることを確認

### リスク

- 本節と施策 5 は互いに依存する（節が無ければテストが赤、テストが無ければ節は放置される）。
  **同一 PR で入れる**

---

## 施策 7: 逸脱登録 D27

### 変更箇所

- ファイル: `docs/template-divergence.md`
  - 冒頭 L11 の `登録エントリ: 25 件` → `登録エントリ: 26 件`
  - 末尾に `## D27 …` を追加

### 変更後コード（追記分）

````markdown
## D27 デザイントークンの生成 CSS 検査を、値の写しを持たず実 app.css も通す形で実装する

| 行 | 内容 |
|---|---|
| 対象パス | `tests/js/styles/tokens.test.ts` / `tests/js/styles/design-system-docs.test.ts` |
| 業務要件起因の説明 | 撮影 PWA は現場作業者が屋外のスマホで使う面であり、状態色と本文が読めることが業務の前提になる。テンプレート家系の正典実装は期待値を検査側の表に literal で持つが、本アプリは DESIGN.md を唯一の正本と定めており、値の写しを 3 か所へ増やすと正本の一元化と衝突する |
| 揃え続ける不変条件と保証機構 | 「DESIGN.md の値と utility 名が Tailwind の生成 CSS まで届くこと」と「運用契約の文書が実体と同期していること」。`tests/js/styles/tokens.test.ts` (密閉の層 = 全件 / 経路の層 = 実 app.css) と `tests/js/styles/design-system-docs.test.ts` (双方向の集合一致) が保証する |
| 再判定の条件 | 正典が literal 期待値表の保持そのものを不変条件として明文化したとき。または Tailwind の生成 CSS の形が変わり AST 走査で読めなくなったとき |
| 決めた日 | 2026-08-18 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260818-0248-design-token-t1-tests/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 期待値の置き場所 | 検査側の inventory に literal の表 | DESIGN.md から共有パーサ経由で導出 |
| 入力 CSS | 静的な fixture ファイル | inventory から組み立てた文字列 |
| 自動ソース走査 | 止めていない (アプリ全体の class を拾う) | `source(none)` + `@source inline` で候補を明示供給 |
| 生成 CSS の読み方 | 文字列の正規表現 | postcss の AST 走査 |
| 実 app.css の検査 | 先頭 2 行のテキスト検査のみ | テキスト検査 + 実コンパイル (経路の層) |
| 文書の検査 | 散文の完全一致フレーズ | 節・表・パス・検査目録の構造検査 |

### なぜ正当な差分か(logic-driven)

家系の裁定 (機能 `design-token-system` / AG-022) は「**揃えるべきは検査の仕組みであり、
テーマ値やデザインシステムの中身はプロジェクト別カスタマイズ点で drift ではない**」と定めている。
正典の literal 表が持つ「DESIGN.md とは独立に値を pin する」性質は、この裁定に照らせば
**t1 の不変条件ではなく正典実装の副次的な性質**である。本アプリは DESIGN.md を唯一の正本と
しており、トークンの値の変更は「気付くべき事故」ではなく正規の変更手順であるため、
独立 pin を採らない。

静的 fixture を持たない判断も同様に、fixture の目的
(アプリ全体の class 変動から検査を独立させる) を `source(none)` + `@source inline` が満たす。
実測では、正典の fixture はこの目的を**満たしていなかった** (自動ソース走査が働き、
アプリ全体を拾った生成 CSS 46,667 文字に対して検査していた)。

### 揃えている不変条件(これは保証し続ける)

> 「DESIGN.md に書いた色・角丸・文字組が、Tailwind のコンパイルを通って生成 CSS に
> 同じ値で現れ、UI が使う utility 名がその値へ解決すること」

`tokens.test.ts` の密閉の層が全トークン・全 utility を、経路の層が実 app.css からの
到達を保証する。値の期待は DESIGN.md から導出するため、正本との drift は
`canonical-source-parity.test.ts` の集合一致・値一致が別段で保証する。

### 関連

- 実装: `tests/js/styles/tokens.test.ts` / `tests/js/styles/design-system-docs.test.ts` /
  `tests/js/styles/inventory.ts` / `tests/js/styles/design-md.ts` /
  `tests/js/styles/canonical-source-parity.test.ts` / `docs/design-system.md`
- 設計: devnotes/20260818-0248-design-token-t1-tests/
````

### テスト計画

- [ ] `composer test` の `TemplateDivergenceLedgerFormatTest` が緑（9 行・値域・件数の 3 点一致）
- [ ] 対象パスが**他のエントリと重複していない**ことを確認（新規 2 ファイルなので重複なし）

### リスク

- 件数行（`登録エントリ: 26 件`）の更新忘れで赤になる。**同一 PR で必ず直す**

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 7 施策が相互に前提関係を持ち（1・2 → 3・4・5、6 → 5、7 は 3 と同一 PR 必須）、分割すると中間状態が必ず赤になる。総変更量は新規 2 ファイル + 既存 3 ファイルへの追記で小さい |
| 競合リスク | `tests/js/styles/` と `docs/design-system.md` / `docs/template-divergence.md` に触る他バッチがあれば衝突する。特に `docs/template-divergence.md` の**件数行と D 番号**は他バッチと衝突しやすいので、マージ直前に採番を確認する |

## 完了条件（Definition of Done）

- [ ] R1〜R6 の Red を先に確認した記録が残っている
- [ ] `pnpm test` / `pnpm typecheck` / `pnpm lint` が緑
- [ ] `composer test`（`TemplateDivergenceLedgerFormatTest` を含む）が緑
- [ ] `pnpm build` が緑（CSS を触っていないが、tokens.css を読む経路が増えるため一度は通す）
- [ ] 既存の `canonical-source-parity` / `contrast-invariant` の assertion を 1 つも削っていない
- [ ] トークンの値を 1 つも変えていない
- [ ] 設計時の一時スクリプト（`probe-*.mjs`）は devnotes 配下に置いたまま（`scripts/` へ昇格しない）
