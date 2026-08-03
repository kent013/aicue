【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク (フロントは vitest + @testing-library/svelte + jsdom)
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- Tailwind CSS v4 (design token は resources/css/tokens.css、canonical は /DESIGN.md)

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか
11. Atomic Design準拠: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【本件の性質】
- **フロントエンドのみの変更**で PHP 側 (Controller / FormRequest / DTO / Service) を一切変更しない。
  よって観点 3/5/6 は実質非該当。観点 1/2/4/7/8/10/11 を厚く見てほしい。
- 出典は LLM 探索的バグハントの Medium 3 件 (`devnotes/20260803-203721-bug-hunt/report.md`)。
- リポジトリ (/workspace) のファイルは読んでよい。判断に直結するのは:
  `AGENTS.md`, `DESIGN.md`,
  `resources/js/components/atoms/{Input,Textarea,Select}.svelte`, `atoms/input-state.ts`,
  `resources/js/components/molecules/FormField.svelte`,
  `resources/js/components/features/billing/{AutoRechargeCard,BillingContactForm}.svelte`,
  `resources/js/pages/Billing/PurchaseTickets.svelte` (T041 先行実装),
  `resources/js/pages/Organizations/Settings.svelte` (T044 先行実装),
  `resources/js/pages/Auth/Register.svelte`,
  `tests/js/architecture/ds-purity.test.ts`, `tests/js/support/ds-purity.ts`,
  `tests/js/components/features/billing/AutoRechargeCard.test.ts`,
  `tests/js/pages/Billing/BillingContactForm.test.ts`

【特に判定してほしい論点】
1. 施策 2 で `inputError` を `$state` → 「boolean フラグ + `$derived`」に変える設計は、
   先行実装 (T041/T044 の `$effect` クリア) と**形が違う**。詳細設計はその理由 (本カードは
   エラー文言が 3 種類あるため「有効時のみクリア」では別の stale が残る) を述べ、
   先行 2 実装は書き換えないと判断している。この判断は妥当か。
   (規約と実装の乖離を作らないか / 逆に churn を避ける判断として正しいか)
2. 施策 1 で `bg-surface` を `INPUT_BASE_CLASSES` から `inputStateClass()` の返り値へ移す変更は、
   Tailwind v4 の同一プロパティ競合 (class 属性の順ではなく生成 CSS の順で勝敗が決まる) を
   避けるためのもの。この理解と対処は正しいか。より良い手はあるか。
3. 施策 3 の「`novalidate` を `<form` の直後に書く」書式規約 + それを前提にした architecture テストの
   実装方針 (開始タグ全体を正規表現で切り出さない) は妥当か。テストの偽陰性・偽陽性の余地はないか。
4. テスト計画は AGENTS.md 禁止事項 1 (テストなしの実装完了報告) に耐えるか。
   特に「jsdom では native validation のブロックを再現できないため属性で固定する」という
   割り切りは妥当か。ほかに固定すべき不変条件の取りこぼしはないか。

---

## 詳細設計書

# 詳細設計: billing-input-ux (入力 UX 規約の是正 — F-3-05 / F-3-02 / F-3-03)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を
生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

> 本タスクは **フロントエンドのみ**の変更で PHP 側を一切変更しない。よって 2/3/4/5/6/7 は
> 構造的に非該当。**8 は本タスクの中心論点**(規約 1 は禁止事項 8 の対として設計される)。
> 1 は施策ごとにテストを定義して満たす(下記 §テスト計画)。

### コーディングルール

- **PHPStan level 10** 必須(`composer phpstan`) — 本タスクは PHP 無変更のため実質 no-op だが、
  検証コマンドとしては流す
- **Pest** テストフレームワーク(`composer test`)。フロントは **vitest**(`pnpm test`)
- **RefreshDatabase** + `--parallel`(個別 `DatabaseTransactions` 禁止) — 本タスクは DB テストを増やさない
- **テストデータは必ず Factory で生成** — 本タスクの vitest は
  `tests/js/support/autoRechargeProps.ts` の既存 builder を使う
- **DTO + JsonResource** パターン — PHP 無変更のため非該当
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`(Pint)/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロント固有: Svelte 5 runes + DS token/ramp のみ(`DESIGN.md` が canonical、ds-purity テストが検出)。
  component 階層は単方向 import(`atoms → molecules → organisms → features → templates → pages`)

## 概念設計リファレンス

`devnotes/20260804-0021-billing-input-ux/conceptual-design.md`
(Codex conceptual-review Round 1 = **APPROVED**、Warning 2 件反映済み)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 入力系 atom に readonly の視覚状態を持たせる (F-3-03 の根治) | `components/atoms/input-state.ts`, `Input.svelte`, `Textarea.svelte` | 高 |
| 2 | 押下時 client エラーを入力に追随させる (F-3-05) | `components/features/billing/AutoRechargeCard.svelte` | 高 |
| 3 | 全 `<form>` に `novalidate` を付け、検証 UX を日本語に一本化 (F-3-02) | `resources/js` の 26 ファイル / 33 form | 高 |
| 4 | 規約を DESIGN.md (canonical) へ昇格 | `DESIGN.md` | 高 |
| 5 | 不変条件を固定するテスト | atom 単体 / component 単体 / architecture テスト | 高 |

---

## 施策 1: 入力系 atom に readonly の視覚状態を持たせる

### 変更箇所

- `resources/js/components/atoms/input-state.ts` (L6-18 全体)
- `resources/js/components/atoms/Input.svelte` (L15-34, L37-44)
- `resources/js/components/atoms/Textarea.svelte` (L11-35, L38-47)
- `resources/js/components/atoms/Select.svelte` — **変更しない**(HTML 仕様上 `<select>` に
  `readonly` 属性は存在せず、Props が `HTMLSelectAttributes` 由来のため型でも受け取れない = 既に境界)

### 波及変更

- TypeScript 型定義: `Input.svelte` / `Textarea.svelte` の Props で `readonly` を
  `HTMLInputAttributes` / `HTMLTextareaAttributes` から `Omit` して `readonly?: boolean` に狭める
  (現行は restProps 経由の透過。**型としては widen ではなく narrow**)
- API Resource/DTO: なし (PHP 無変更)
- 呼び出し側: `AutoRechargeCard.svelte:336,356` / `Auth/Register.svelte:113` は
  **記述変更なし**で新しい見た目が適用される (prop 名は同じ `readonly`)
- テストファイル: `tests/js/components/atoms/Input.test.ts` / `Textarea.test.ts` (施策 5)

### 現行コード

```ts
// resources/js/components/atoms/input-state.ts
export const INPUT_BASE_CLASSES = [
    "w-full rounded-sm border bg-surface text-body text-text",
    "px-3 py-1.5",
    "transition-colors duration-150",
    "placeholder:text-text-secondary/70",
    "focus:border-primary focus:ring-3 focus:ring-primary/20 focus:outline-none",
    "disabled:cursor-not-allowed disabled:bg-neutral disabled:text-text-secondary",
].join(" ");

/** error の有無で border 色を切り替える */
export function inputStateClass(error: boolean): string {
    return error ? "border-danger ring-3 ring-danger/15" : "border-border-strong";
}
```

```svelte
<!-- Input.svelte (抜粋) -->
interface Props extends Omit<HTMLInputAttributes, "type" | "value" | "class"> { … }
const computedClass = $derived(
    [INPUT_BASE_CLASSES, inputStateClass(error), extraClass].filter(Boolean).join(" "),
);
…
<input {type} bind:value class={computedClass} aria-invalid={error || undefined}
       data-testid={testId} {...rest} />
```

### 変更後コード

```ts
// resources/js/components/atoms/input-state.ts
/**
 * 入力系 atom (Input / Textarea / Select) の共通スタイル定義。
 * 見た目の真実は DESIGN.md §Components。変更時は全入力 atom に波及することに注意。
 */

// 背景色は inputStateClass 側で確定させる (readonly と競合させないため base に置かない。
// Tailwind は同一プロパティの utility が並んだ場合、勝敗が class 属性の順ではなく
// 生成 CSS の順で決まるため、bg は常に 1 つだけ出力する)。
export const INPUT_BASE_CLASSES = [
    "w-full rounded-sm border text-body text-text",
    "px-3 py-1.5",
    "transition-colors duration-150",
    "placeholder:text-text-secondary/70",
    "focus:border-primary focus:ring-3 focus:ring-primary/20 focus:outline-none",
    "disabled:cursor-not-allowed disabled:bg-neutral disabled:text-text-secondary",
].join(" ");

/**
 * error / readonly の状態クラス。
 *
 * - error: border を danger 化する (readonly でも維持する = どのフィールドが不正か分かる)
 * - readonly: **編集できないことを面で示す**。ただし disabled とは意味が違うので同一にしない —
 *   readonly の値は生きている (送信される・選択してコピーできる・フォーカスできる) ため、
 *   文字色は通常のまま (`text-text`)、カーソルは `cursor-default`、focus ring は base のまま維持する。
 *   disabled は `text-text-secondary` + `cursor-not-allowed` + フォーカス不可 (base の disabled: 側)。
 *   `<select>` は HTML 仕様上 readonly を持たないため呼び出さない (既定 false)。
 */
export function inputStateClass(error: boolean, readonly = false): string {
    const border = error ? "border-danger ring-3 ring-danger/15" : "border-border-strong";
    return readonly ? `${border} bg-neutral cursor-default` : `${border} bg-surface`;
}
```

```svelte
<!-- resources/js/components/atoms/Input.svelte -->
<script lang="ts">
    import type { HTMLInputAttributes } from "svelte/elements";
    import { INPUT_BASE_CLASSES, inputStateClass } from "./input-state";

    type InputType =
        | "text" | "email" | "password" | "tel" | "url" | "number" | "search" | "date";

    // type は「入力補助 (モバイルキーボード / autofill / 型のアナウンス)」のための意味付けであり、
    // 検証手段ではない。検証の正本はサーバ (日本語) + 押下時の client エラーで、
    // native constraint validation には依存しない (form 側の novalidate。DESIGN.md §Do's and Don'ts)。
    interface Props extends Omit<HTMLInputAttributes, "type" | "value" | "class" | "readonly"> {
        type?: InputType;
        value?: string;
        error?: boolean;
        /** 編集不可だが値は生きている (送信される・コピー/フォーカス可)。disabled とは意味が違う */
        readonly?: boolean;
        testId?: string;
        class?: string;
    }

    let {
        type = "text",
        value = $bindable(""),
        error = false,
        readonly = false,
        testId,
        class: extraClass = "",
        ...rest
    }: Props = $props();

    const computedClass = $derived(
        [INPUT_BASE_CLASSES, inputStateClass(error, readonly), extraClass]
            .filter(Boolean)
            .join(" "),
    );
</script>

<input
    {type}
    {readonly}
    bind:value
    class={computedClass}
    aria-invalid={error || undefined}
    data-testid={testId}
    {...rest}
/>
```

```svelte
<!-- resources/js/components/atoms/Textarea.svelte (差分のみ) -->
    } & Omit<HTMLTextareaAttributes, "value" | "class" | "readonly">;

    let {
        value = $bindable(),
        error = false,
        disabled = false,
        readonly = false,
        rows = 4,
        …
    }: Props = $props();

    const computedClass = $derived(
        [INPUT_BASE_CLASSES, inputStateClass(error, readonly), extraClass]
            .filter(Boolean)
            .join(" "),
    );
…
<textarea {...restProps} bind:value {id} {rows} {placeholder} {disabled} {readonly}
          aria-invalid={error || undefined} data-testid={testId} class={computedClass}></textarea>
```

Props 側にも `readonly?: boolean` を明示追加する(Textarea は type alias 形式のため
`error` / `testId` と同じ位置に追記)。

### なぜ Tailwind の `read-only:` バリアントを使わないか

CSS の `:read-only` 疑似クラスは **`readonly` 属性を持つ input だけでなく、disabled な input と
`<select>` 全般にもマッチする**(HTML 仕様上 `<select>` は `:read-write` にならない)。
共通の `INPUT_BASE_CLASSES` に `read-only:` を混ぜると **すべての Select が常時 muted になる**。
よって明示 prop から class を計算する(jsdom でも決定的に検証できるという副次的利点もある)。

### PHPStan 適合チェック

- [x] PHP 変更なし(施策 1〜5 すべて)。`composer phpstan` は既存 green を維持するだけ
- [x] TypeScript: `pnpm typecheck` で `readonly` の型 narrow が既存 call site を壊さないことを確認
      (`AutoRechargeCard` は `boolean` 式、`Register` は `boolean` 変数を渡しており適合)

### リスク

- **bg の重複出力**: `bg-surface` を base から state 側へ移すため、`extraClass` で `bg-*` を
  渡している call site があると挙動が変わりうる。→ grep 済み: `Input` / `Textarea` / `Select` の
  `class` prop に `bg-*` を渡している箇所は存在しない(`PasswordInput.svelte:47` の `class="pr-10"` のみ)
- **Textarea の readonly は現状 call site が無い**。ただし native 属性として素通りする現行実装は
  「readonly を渡せるのに見た目が変わらない」同じ穴を持つため、atom として閉じる
  (投機的な機能追加ではなく、既存の穴の封鎖)

---

## 施策 2: 押下時 client エラーを入力に追随させる (F-3-05)

### 変更箇所

- `resources/js/components/features/billing/AutoRechargeCard.svelte`
  L45-46 (`inputError` の宣言) / L160-164 (`ensureValidRange`) / L149-153 (`onSuccess`) /
  L208-215 (`handleDisable`) / L375-383 (テンプレート)

### 波及変更

- TypeScript 型定義: なし (component 内部 state のみ)
- API Resource/DTO: なし
- テストファイル: `tests/js/components/features/billing/AutoRechargeCard.test.ts` (施策 5)
- **`data-testid="auto-recharge-range-error"` と表示文言は変えない** (既存テスト・bug-hunt 手順との互換)

### 現行コード

```svelte
/** 押下時に初めて出す入力エラー (disabled でブロックしない代わりの提示点) */
let inputError = $state<string | null>(null);
…
/** 入力値の妥当性を押下時に確定する (disabled でブロックしない = 禁止事項 #8)。 */
function ensureValidRange(): boolean {
    inputError = rangeError;
    return rangeError === null;
}
```

`inputError` は押下ハンドラ経由でしか更新されないため、値を有効へ直しても文言が残る (stale invalid)。

### 変更後コード

```svelte
/**
 * 押下時に初めてエラーを提示する (disabled でブロックしない = 禁止事項 #8) が、
 * 一度提示したら以後は現在の入力に追随させる (stale invalid を残さない = DESIGN.md §FormField)。
 * 文言そのものを state で持たず「提示を開始したか」だけを持つことで、
 * rangeError との同期漏れが構造的に起きない ($effect による状態同期を避ける)。
 */
let inputErrorShown = $state(false);
/** 表示中の入力エラー。提示開始後は rangeError に完全追随する (有効化で消え、理由が変われば文言も変わる) */
const inputError = $derived(inputErrorShown ? rangeError : null);
…
/** 入力値の妥当性を押下時に確定する (disabled でブロックしない = 禁止事項 #8)。 */
function ensureValidRange(): boolean {
    inputErrorShown = true;
    return rangeError === null;
}
```

併せて `inputError = null` を書いていた 2 箇所を `inputErrorShown = false` に置き換える:

- `post()` の `onSuccess` (L149-153): `inputError = null;` → `inputErrorShown = false;`
- `handleDisable()` (L210): `inputError = null;` → `inputErrorShown = false;`
  (停止は入力値が壊れていても成立させる契約なので、提示自体を畳む)

テンプレート (L375-383) は `{#if inputError !== null}` のままで変更なし
(`inputError` が `$state` から `$derived` になるだけ)。

### 先行実装 (T041 / T044) との関係 — 明示的な判断

同じ不変条件を、T041 (`PurchaseTickets.svelte:59-63`) と T044 (`Organizations/Settings.svelte:112-115`) は
**`$effect` による連動クリア**で満たしている。本カードで同型を採らない理由と、先行 2 実装を
書き換えない理由を明記する:

- **同型を採れない**: T041/T044 は client エラーの文言が 1 種類なので「有効へ復帰したらクリア」で
  十分だが、本カードの `rangeError` は **3 種類**(開始残高が不正 / 補充枚数が範囲外 / 大小関係が逆)。
  「有効時のみクリア」では、無効理由が A から B に変わったときに **A の文言が残る**
  (= 別の stale invalid を作る)。したがって「現在値に追随」が必要。
- **`$derived` を選ぶ**: 追随を `$effect` で書くと「自分が読む state に自分で書く」形になる。
  Svelte 公式は状態同期に `$effect` を使わず `$derived` を使うことを明示しており、
  フレームワークのレンジ内でやる (思考原則 1) 判断として `$derived` を採る。
- **先行 2 実装は書き換えない**: 両者は同じ不変条件を満たしており、テストで固定され、
  実際に機能している。「仕組みが機能していない段階で値を弄るな」に従い、churn を作らない。
  DESIGN.md には**不変条件を canonical として書き**、実装形は「新規は `$derived` 形」と併記する
  (規約と実装の乖離を作らないため、先行 2 実装が同じ不変条件を満たしている事実も明記する)。

### PHPStan 適合チェック

- [x] PHP 変更なし
- [x] `$derived` の型は `string | null` に推論される(テンプレートの `!== null` 判定と整合)

### リスク

- `inputError` が `$derived` になるため、`inputError` への代入が残っていると
  Svelte のコンパイルエラー(または runtime warning)になる。→ 代入箇所は grep 済みで
  `ensureValidRange` / `onSuccess` / `handleDisable` の 3 箇所のみ、すべて置換対象
- サーバ 422 の `serverError` は別 state のまま。入力変更で消さない挙動を維持する(非退行)

---

## 施策 3: 全 `<form>` に `novalidate` を付ける (F-3-02)

### 変更箇所 (33 form / 26 ファイル、すべて `<form` の直後に `novalidate` を追加)

| ファイル | 行 |
|---|---|
| `components/features/billing/BillingContactForm.svelte` | 69 |
| `components/features/manual/SourceDocumentUpload.svelte` | 33 |
| `components/features/manual/DuplicateManualDialog.svelte` | 92 |
| `components/organisms/RecentAuthModal.svelte` | 102 |
| `pages/Organizations/Create.svelte` | 35 |
| `pages/Organizations/Settings.svelte` | 183, 289 |
| `pages/Organizations/ApiKeys/Index.svelte` | 252 |
| `pages/Settings/Index.svelte` | 163, 203 |
| `pages/Settings/Security.svelte` | 365 |
| `pages/Capture/Index.svelte` | 60 |
| `pages/Contact/Index.svelte` | 100 |
| `pages/Auth/Login.svelte` | 33 |
| `pages/Auth/Register.svelte` | 85 |
| `pages/Auth/ForgotPassword.svelte` | 31 |
| `pages/Auth/ResetPassword.svelte` | 31 |
| `pages/Auth/TwoFactorChallenge.svelte` | 53 |
| `pages/Auth/ConfirmRecentAuth.svelte` | 50 |
| `pages/Auth/VerifyEmail.svelte` | 49 |
| `pages/Projects/Create.svelte` | 40 |
| `pages/Projects/Edit.svelte` | 45 |
| `pages/Projects/Show.svelte` | 343, 557, 668, 720 |
| `pages/Manuals/Create.svelte` | 62 |
| `pages/Manuals/Edit.svelte` | 83 |
| `pages/Admin/Categories.svelte` | 130, 231 |
| `pages/Admin/Users.svelte` | 377, 486 |
| `pages/Invitations/Accept.svelte` | 40 |

### 波及変更

- TypeScript 型定義 / API Resource / DTO: なし
- テストファイル: `tests/js/architecture/form-novalidate.test.ts` (新規) /
  `tests/js/pages/Billing/BillingContactForm.test.ts` (追加ケース)

### 変更後コード (例)

```svelte
<!-- 単一行の form -->
<form novalidate onsubmit={submit} class="flex flex-col gap-4">

<!-- 複数行の form (Capture/Index.svelte:60) -->
<form
    novalidate
    class="flex min-w-0 flex-1 items-center gap-2"
    onsubmit={(event) => {
        event.preventDefault();
        applyFilters();
    }}
>
```

**書式規約**: `novalidate` は `<form` の**直後(最初の属性)**に書く。理由は 2 つ —
(a) 「このフォームの検証はサーバ/明示 client エラーが正本」という宣言を先頭で読ませる、
(b) architecture テストが `<form` の直後を見るだけで機械判定できる(属性値に `=>` を含む
onsubmit ハンドラがあるため、`<form ... >` 全体を正規表現で切り出す方式は壊れやすい)。

### 前提の裏取り (後退防止)

- 33 form すべてが `onsubmit` で `preventDefault` する JS ハンドラ形式であり、
  **native の form 送信に依存しているものはゼロ**(実測)。`novalidate` は
  「ブラウザが submit を横取りしなくなる」以外の作用を持たない
- `required` / `pattern` / `minlength` を native 属性として入力に渡している箇所はゼロ
  (`FormField` の `required` はラベルの `*` 表示のみ、`Checkbox.svelte` の `required` prop は未使用)。
  → native validation に依存している機能は現状存在せず、無効化で失われる検証はない
- `type="email"` を持つ 8 form はすべてサーバ errors を `FormField error={…}` に配線済み
  (`Auth/Login:34` / `Auth/Register:102` / `Auth/ForgotPassword:32` / `Auth/ResetPassword:32` /
  `Settings/Index:179` / `Contact/Index:129` / `Admin/Users:381` / `BillingContactForm:81`)
- `maxlength` などの「入力を制限する」属性は constraint validation ではないため影響なし

### リスク

- クライアント側の即時ブロックが無くなる分、不正値でも 1 往復サーバに飛ぶ。
  → 意図した設計(検証の正本はサーバ、日本語文言で返す)。負荷影響は無視できる
- ブラウザ既定の「必須項目にジャンプ+フォーカス」が無くなる。
  → 現状 `required` 属性を使っていないため既に発生しておらず、非退行

---

## 施策 4: 規約を DESIGN.md (canonical) へ昇格

### 変更箇所

- `DESIGN.md` §Input / Textarea / Select (L184-193)
- `DESIGN.md` §FormField (L301-308)
- `DESIGN.md` §Do's and Don'ts (L428-443)

### 波及変更

- `resources/css/tokens.css`: **変更なし**(新規トークンを足さない)。よって
  `tests/js/styles/canonical-source-parity.test.ts`(色/radius/typography の集合一致)は無影響
- `docs/design-system.md`: **変更なし**(トークン変更時の運用契約・allowlist 運用に触れないため)
- `tests/js/support/ds-purity.ts`: **変更なし**(禁止パターンを増減しない)

### 追記内容 (案)

**§Input / Textarea / Select** の末尾に追記:

```markdown
- **`type` は入力補助であって検証手段ではない**。`email` / `tel` / `url` / `number` 等は
  モバイルキーボード・autofill・スクリーンリーダーの型アナウンスのために付ける。
  検証の正本はサーバ(日本語)と押下時の client エラーで、native constraint validation には
  依存しない(form 側で `novalidate`。§Do's and Don'ts)。`inputmode` は restProps で透過する
- **readonly は「編集できない」ことを面で示す**(`Input` / `Textarea` の `readonly` prop)。
  `bg-neutral` + `cursor-default`。ただし **disabled と同じ見た目にしない** — readonly の値は
  生きている(送信される・選択してコピーできる・フォーカスできる)ので、文字色は `text-text` の
  ままにし focus ring も維持する。disabled は `text-text-secondary` + `cursor-not-allowed` +
  フォーカス不可。`<select>` は HTML 仕様上 readonly を持たない(編集させないなら値を
  読み取り表示にする)
- 「編集させない値」の表現は 2 通り。**そのフォームの送信対象に含む / コピーさせたい**なら
  readonly input(例: 招待 email の prefill、権限が無い閲覧者への設定値提示)、
  **編集手段自体を出さない**なら読み取り表示(`<dl>` 等。例: 請求先情報カードの非管理者表示)。
  readonly input を選んだ場合、上記の見た目が付くことは atom が保証する
```

**§FormField** の末尾に追記:

```markdown
- **押下時に出した client エラーは、その後の入力に追随させる**(stale invalid を残さない)。
  ボタンを disabled にしない(§Do's and Don'ts / AGENTS.md 禁止事項 8)代わりに押下時にエラーを
  出すのだから、そのエラーは常に「今の入力」を説明していなければならない — 有効に戻ったら消え、
  無効の理由が変わったら文言も変わる。押下前には出さない。
  実装は **「提示を開始したかの boolean」+ 文言は `$derived`** で組む(文言を `$state` で持つと
  同期漏れが起きる。`$effect` での状態同期はしない)。先行実装
  (`Billing/PurchaseTickets.svelte` / `Organizations/Settings.svelte`)は `$effect` による
  連動クリアで同じ不変条件を満たしている。**新規は `$derived` 形で書く**
- サーバ由来の errors(`form.errors.*`)はこの追随の対象外。入力の変更で消さない
```

**§Do's and Don'ts の Don't** に追記:

```markdown
- **native の constraint validation に検証を任せない**。`<form>` には `novalidate` を付け
  (`<form` の直後に書く)、検証文言はサーバ(日本語)と押下時の client エラーに一本化する。
  native validation は submit より先に発火してブラウザロケール依存の文言で送信を止めるため、
  日本語 UI の検証経路に到達できなくなる
```

### リスク

- DESIGN.md は canonical のため、記述と実装が乖離すると次の読者を誤らせる。
  → 施策 5 の architecture テストで `novalidate` を、atom 単体テストで readonly の見た目を、
  component 単体テストで stale-invalid を機械固定し、乖離を検出可能にする

---

## 施策 5: 不変条件を固定するテスト

「どの層のどのテストで不変条件を固定するか」を施策ごとに対応付ける
(AGENTS.md 禁止事項 1: テストなしの実装完了報告をしない)。

### 5-1. architecture テスト (新規): `tests/js/architecture/form-novalidate.test.ts`

```ts
import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";

/**
 * resources/js 配下の全 <form> が novalidate を持つことを機械検証する。
 *
 * 検証 UX の正本はサーバ (日本語) + 押下時の client エラー (DESIGN.md §Do's and Don'ts)。
 * native constraint validation は submit より先に発火し、ブラウザロケール依存の文言で
 * 送信自体を止めるため、日本語の検証経路に到達できなくなる (bug-hunt F-3-02)。
 *
 * `novalidate` は <form の直後 (最初の属性) に書く。onsubmit ハンドラの属性値に `=>` を
 * 含みうるため、開始タグ全体を正規表現で切り出す方式は壊れやすい。
 *
 * 例外を足したくなったら allowlist を作る前に、「なぜ日本語のエラー経路では足りないのか」を疑うこと。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");

// (listFiles は ds-purity.test.ts と同じ recursive readdir。.svelte のみ走査)

describe("form validation policy", () => {
    it("resources/js の全 <form> が novalidate を持つ (native validation に依存しない)", () => {
        const violations: string[] = [];
        for (const file of svelteFiles) {
            const content = fs.readFileSync(file, "utf-8");
            const total = [...content.matchAll(/<form\b/g)];
            for (const m of total) {
                const rest = content.slice(m.index);
                if (!/^<form\s+novalidate\b/.test(rest)) {
                    const line = content.slice(0, m.index).split("\n").length;
                    violations.push(`${relPath(file)}:${line}`);
                }
            }
        }
        expect(violations).toEqual([]);
    });
});
```

- 固定する不変条件: **native constraint validation に依存しない**(施策 3 / 規約 2)
- 失敗の仕方: 新規フォームを `novalidate` なしで足すと即 fail(ファイル:行 を提示)

### 5-2. atom 単体テスト: `tests/js/components/atoms/Input.test.ts` (追加 3 ケース)

| ケース | 検証 |
|---|---|
| `readonly` で native 属性 + muted 面が付く | `toHaveAttribute("readonly")` / className に `bg-neutral` と `cursor-default` |
| `readonly` でも文字色は落とさない (disabled と区別する) | className に `text-text` を含み `text-text-secondary` を**含まない** |
| `readonly` 既定 (false) では `bg-surface` | className に `bg-surface`、`readonly` 属性なし |
| `readonly` + `error` で danger border が残る | className に `border-danger` と `bg-neutral` の両方 |

- 固定する不変条件: **編集不可は面で示す / readonly ≠ disabled**(施策 1 / 規約 3)

### 5-3. atom 単体テスト: `tests/js/components/atoms/Textarea.test.ts` (追加 1 ケース)

`readonly` で native 属性 + `bg-neutral cursor-default` が付くこと(Input と同じ規約が
入力系 atom 横断で成立していることの固定)。

### 5-4. component 単体テスト: `tests/js/components/features/billing/AutoRechargeCard.test.ts` (追加 5 ケース)

| ケース | 検証 (F-番号) |
|---|---|
| 押下前は範囲エラーを出さない | 無効値を入力しただけでは `auto-recharge-range-error` が無い(禁止事項 8 の契約維持) |
| 押下でエラー、値を有効に直すとエラーが消える | **F-3-05 回帰**。`auto-recharge-range-error` が消え、`invalid` も外れる |
| 無効のまま別の無効理由に変えると文言が追随する | 「開始残高より大きい値」→「範囲外」等、現在の理由に一致(過剰クリアも stale も無い) |
| `canManage=false` で両入力が readonly かつ muted | **F-3-03 回帰**。`auto-recharge-threshold-input` / `-max-input` に `readonly` 属性と `bg-neutral` |
| `canManage=true` では readonly でない | 非退行(管理者の編集可否が壊れていない) |

- 固定する不変条件: **stale invalid を残さない**(施策 2 / 規約 1)、
  **編集不可の可視化**(施策 1 / 規約 3)

### 5-5. component 単体テスト: `tests/js/pages/Billing/BillingContactForm.test.ts` (追加 3 ケース)

| ケース | 検証 |
|---|---|
| form が `novalidate` を持つ | `billing-contact-form` の `noValidate === true`(**F-3-02 の call site 回帰**) |
| email 入力は `type="email"` のまま | 入力補助(モバイルキーボード)を落としていないこと |
| 不正な email でも submit で `router.patch` が飛ぶ | 既存ケースの拡張。値 `not-an-email` で `routerPatchMock` が呼ばれる |

> **注意 (テスト設計上の限界を明示)**: jsdom + `fireEvent` は submit イベントを直接発火するため
> **native validation のブロックそのものは再現できない**(既存テストがこのバグを見逃した理由)。
> よって behavior ではなく **`novalidate` 属性の存在**という構造で固定する(5-1 + 5-5)。
> Browser テスト(Chromium/WebKit の 2 レーン)は現在 bfcache/smoke のみで、
> このためだけにレーンを増やすのは費用対効果が合わない。

### 5-6. 既存テストの非退行確認 (削除・上書きをしない)

- `tests/js/pages/PurchaseTickets.test.ts` (T041 の 4 ケース) / `OrganizationsSettings.test.ts`
  (T044 の 3 ケース) は**触らない**(先行実装を変更しないため)
- `tests/js/architecture/ds-purity.test.ts` / `styles/canonical-source-parity.test.ts` は
  トークン非変更のため green のまま
- `pnpm test` / `pnpm typecheck` / `pnpm lint` / `pnpm build` の 4 点で確認する
  (PHP 側は無変更だが `composer test` / `composer phpstan` / `vendor/bin/pint --test` も流す)

---

## 使命・禁止事項の最終チェック

- 使命への寄与: 入力欄が嘘をつかないことは「思考ゼロ」で使い続けられる前提。
  課金・登録・パスワード再設定という**継続利用の生命線**の導線で嘘が消える
- 禁止事項 8: 本設計は disabled を一切導入しない。むしろ「disabled にしない代償」を
  正しく払うための規約 (押下時エラーの追随) を canonical 化する
- 禁止事項 1: 施策 1/2/3 それぞれに、対応するテスト (5-1 〜 5-5) を割り当てた
- 思考原則 1: `novalidate` / `readonly` はいずれも HTML 標準属性、`$derived` は Svelte 公式の
  状態導出手段。自前機構をひとつも作らない
- 思考原則 2: `Form` molecule の新設・allowlist 機構・`<dl>` への作り替え・
  先行実装の refactor をいずれも見送った
- 思考原則 3: 旧実装の並走を残さない (`inputError` の `$state` は削除し `$derived` に置き換える。
  `bg-surface` は base から state 側へ移動し二重定義を残さない)

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1 が `atoms/input-state.ts` (全入力 atom の共通スタイル) を変更し、施策 3 が 26 ファイルに触れるため、他タスクの実装と同時進行すると衝突しやすい。逆に本タスク内の 5 施策は同一の規約を構成する不可分な単位で、分割すると DESIGN.md と実装が一時的に乖離する |
| 競合リスク | 同 bug-hunt 由来の他タスク (F-4-01 = `AppLayout` / bfcache、F-3-01 = `SubscriptionService` / `Plans.svelte`、F-3-04 = `Billing/Index.svelte`) とはファイルが重ならない。ただし **`Plans.svelte` / `Billing/Index.svelte` に `<form>` が増える変更が同時に入ると施策 3 の architecture テストが後から fail しうる** (規約としては正しい fail)。main へのマージ順で解消する |


---

## 関連する現行コード

### resources/js/components/atoms/input-state.ts (全文)

```ts
/**
 * 入力系 atom (Input / Textarea / Select) の共通スタイル定義。
 * 見た目の真実は DESIGN.md §Components。変更時は全入力 atom に波及することに注意。
 */

export const INPUT_BASE_CLASSES = [
    "w-full rounded-sm border bg-surface text-body text-text",
    "px-3 py-1.5",
    "transition-colors duration-150",
    "placeholder:text-text-secondary/70",
    "focus:border-primary focus:ring-3 focus:ring-primary/20 focus:outline-none",
    "disabled:cursor-not-allowed disabled:bg-neutral disabled:text-text-secondary",
].join(" ");

/** error の有無で border 色を切り替える */
export function inputStateClass(error: boolean): string {
    return error ? "border-danger ring-3 ring-danger/15" : "border-border-strong";
}
```

### resources/js/components/atoms/Input.svelte (全文)

```svelte
<script lang="ts">
    import type { HTMLInputAttributes } from "svelte/elements";
    import { INPUT_BASE_CLASSES, inputStateClass } from "./input-state";

    type InputType =
        | "text"
        | "email"
        | "password"
        | "tel"
        | "url"
        | "number"
        | "search"
        | "date";

    interface Props extends Omit<HTMLInputAttributes, "type" | "value" | "class"> {
        type?: InputType;
        value?: string;
        error?: boolean;
        testId?: string;
        class?: string;
    }

    let {
        type = "text",
        value = $bindable(""),
        error = false,
        testId,
        class: extraClass = "",
        ...rest
    }: Props = $props();

    const computedClass = $derived(
        [INPUT_BASE_CLASSES, inputStateClass(error), extraClass].filter(Boolean).join(" "),
    );
</script>

<input
    {type}
    bind:value
    class={computedClass}
    aria-invalid={error || undefined}
    data-testid={testId}
    {...rest}
/>
```

### resources/js/components/features/billing/AutoRechargeCard.svelte (script 部 L38-215)

```svelte
    let { autoRecharge, updateUrl, setupUrl, setupAttemptToken }: Props = $props();

    // 一方向 value + oninput (type=number への two-way bind 禁止規約)。props 更新で正準値へ再同期。
    let thresholdText = $derived(String(autoRecharge.thresholdCount));
    let maxText = $derived(String(autoRecharge.maxCount));
    let submitting = $state(false);
    let showConsent = $state(false);
    /** 押下時に初めて出す入力エラー (disabled でブロックしない代わりの提示点) */
    let inputError = $state<string | null>(null);
    /** サーバ 422 の可視化 (flash toast は errors bag を運ばないため silent failure を防ぐ) */
    let serverError = $state<string | null>(null);

    const pickServerError = (errors: Record<string, string>): string | null => {
        for (const key of [
            "enabled",
            "consent_version",
            "threshold_count",
            "max_count",
            "attempt_token",
        ]) {
            const message = errors[key];
            if (typeof message === "string" && message !== "") return message;
        }
        return Object.values(errors).find((v) => typeof v === "string" && v !== "") ?? null;
    };

    const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);

    const parseIntStrict = (raw: string): number | null => {
        const trimmed = raw.trim();
        if (trimmed === "" || !/^\d+$/.test(trimmed)) return null;
        const n = Number.parseInt(trimmed, 10);
        return Number.isNaN(n) ? null : n;
    };

    const parsedThreshold = $derived.by<number | null>(() => {
        const n = parseIntStrict(thresholdText);
        return n === null || n < 0 ? null : n;
    });

    const parsedMax = $derived.by<number | null>(() => {
        const n = parseIntStrict(maxText);
        if (n === null || n < autoRecharge.minCount || n > autoRecharge.maxCountLimit) return null;
        return n;
    });

    const rangeError = $derived.by<string | null>(() => {
        if (parsedThreshold === null) {
            return "リチャージ開始残高は 0 以上の整数で入力してください";
        }
        if (parsedMax === null) {
            return `リチャージ後の残高は ${autoRecharge.minCount} 〜 ${autoRecharge.maxCountLimit} の整数で入力してください`;
        }
        if (parsedMax <= parsedThreshold) {
            return "リチャージ後の残高は開始残高より大きい値を指定してください";
        }
        return null;
    });

    // 適用単価: Max 枚をまとめ買いした場合の tier 単価 (同意文言の上限額と同じ計算)。
    const appliedUnit = $derived.by<number>(() => {
        const c = parsedMax;
        if (c === null) return autoRecharge.baseUnitAmountJpy;
        let unit = autoRecharge.tiers[0]?.unitAmount ?? autoRecharge.baseUnitAmountJpy;
        for (const t of autoRecharge.tiers) {
            if (c >= t.minCount) unit = t.unitAmount;
        }
        return unit;
    });

    const maxChargeAmount = $derived(
        parsedMax !== null && rangeError === null ? parsedMax * appliedUnit : null,
    );

    const consentLines = $derived.by<string[]>(() => {
        const lines = [
            `残高が ${parsedThreshold ?? autoRecharge.thresholdCount} 枚を下回ると、登録済みのカードで不足分をまとめて購入し、${parsedMax ?? autoRecharge.maxCount} 枚まで補充します。`,
        ];
        if (maxChargeAmount !== null) {
            lines.push(`1 回の自動購入の上限額は ¥${formatYen(maxChargeAmount)} (税込) です。`);
        }
        lines.push("この設定はあとからいつでも変更・停止できます。");
        return lines;
    });

    const stateBadge = $derived.by<{ label: string; tone: "success" | "danger" | "neutral" }>(
        () => {
            if (autoRecharge.enabled) return { label: "有効", tone: "success" };
            if (autoRecharge.disabledReason === "payment_failures") {
                return { label: "自動停止中", tone: "danger" };
            }
            return { label: "無効", tone: "neutral" };
        },
    );

    interface UpdatePayload {
        enabled: boolean;
        threshold_count: number;
        max_count: number;
        consent_version?: string;
        [key: string]: boolean | number | string | undefined;
    }

    function post(payload: UpdatePayload): void {
        submitting = true;
        serverError = null;
        router.post(updateUrl, payload, {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                serverError = pickServerError(errors);
            },
            onSuccess: () => {
                serverError = null;
                inputError = null;
                showConsent = false;
            },
            onFinish: () => {
                submitting = false;
            },
        });
    }

    /** 入力値の妥当性を押下時に確定する (disabled でブロックしない = 禁止事項 #8)。 */
    function ensureValidRange(): boolean {
        inputError = rangeError;
        return rangeError === null;
    }

    function openConsent(): void {
        if (submitting) return;
        if (!ensureValidRange()) return;
        showConsent = true;
    }

    function confirmEnable(): void {
        if (submitting) return;
        if (!ensureValidRange() || parsedThreshold === null || parsedMax === null) return;
        post({
            enabled: true,
            threshold_count: parsedThreshold,
            max_count: parsedMax,
            // 同意文言バージョンのみ送る。金額はサーバが現行カタログで再計算する。
            consent_version: autoRecharge.consentVersion,
        });
    }

    /** 有効のまま閾値/Max を更新。上限引き上げ・再同意要求時は同意パネルを経由する。 */
    function handleUpdate(): void {
        if (submitting) return;
        if (!ensureValidRange() || parsedThreshold === null || parsedMax === null) return;
        if (autoRecharge.requiresReconsent || parsedMax > autoRecharge.maxCount) {
            showConsent = true;
            return;
        }
        post({
            enabled: true,
            threshold_count: parsedThreshold,
            max_count: parsedMax,
            consent_version: autoRecharge.consentVersion,
        });
    }

    /** カード未登録時の設定保存 (enabled=false の upsert)。有効化はしない。 */
    function handleSaveDraft(): void {
        if (submitting) return;
        if (!ensureValidRange() || parsedThreshold === null || parsedMax === null) return;
        post({ enabled: false, threshold_count: parsedThreshold, max_count: parsedMax });
    }

    /** 停止は常に成立させる (入力値が壊れていても現在値で送る = ワンクリック停止の保証)。 */
    function handleDisable(): void {
        if (submitting) return;
        inputError = null;
        const threshold = parsedThreshold ?? autoRecharge.thresholdCount;
        const max =
            parsedMax !== null && parsedMax > threshold ? parsedMax : autoRecharge.maxCount;
        post({ enabled: false, threshold_count: threshold, max_count: max });
    }
```

### AutoRechargeCard.svelte (入力欄とエラー表示 L325-383)

```svelte
    <div class="mt-4 grid gap-4 md:grid-cols-2">
        <FormField label="リチャージ開始残高 (残りがこの枚数を下回ったら購入)" id="auto-recharge-threshold">
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="number"
                    min="0"
                    step="1"
                    value={thresholdText}
                    error={invalid}
                    aria-describedby={describedBy}
                    readonly={!autoRecharge.canManage}
                    testId="auto-recharge-threshold-input"
                    oninput={(e: Event) => {
                        const t = e.currentTarget;
                        if (t instanceof HTMLInputElement) thresholdText = t.value;
                    }}
                />
            {/snippet}
        </FormField>
        <FormField label="リチャージ後の残高 (この枚数まで補充)" id="auto-recharge-max">
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="number"
                    min={autoRecharge.minCount}
                    max={autoRecharge.maxCountLimit}
                    step="1"
                    value={maxText}
                    error={invalid}
                    aria-describedby={describedBy}
                    readonly={!autoRecharge.canManage}
                    testId="auto-recharge-max-input"
                    oninput={(e: Event) => {
                        const t = e.currentTarget;
                        if (t instanceof HTMLInputElement) maxText = t.value;
                    }}
                />
            {/snippet}
        </FormField>
    </div>

    {#if maxChargeAmount !== null}
        <p class="mt-2 text-body text-text-secondary" data-testid="auto-recharge-max-amount">
            1 回の自動購入の上限額: ¥{formatYen(maxChargeAmount)} (税込・1 枚あたり ¥{formatYen(
                appliedUnit,
            )})
        </p>
    {/if}

    {#if inputError !== null}
        <p
            class="mt-2 text-caption text-danger"
            aria-live="polite"
            data-testid="auto-recharge-range-error"
        >
            {inputError}
        </p>
    {/if}
```

### resources/js/components/features/billing/BillingContactForm.svelte (L62-113)

```svelte
<Card padding="lg" testId="billing-contact-card">
    <div class="flex items-center gap-2">
        <Receipt class="size-5 text-text-secondary" aria-hidden="true" />
        <h2 class="text-h3">請求先情報</h2>
    </div>

    {#if canManage}
        <form
            class="mt-4 flex flex-col gap-4"
            data-testid="billing-contact-form"
            onsubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <FormField
                label="請求先メールアドレス"
                id="billing-contact-email"
                required
                error={emailError}
                help={helpText}
            >
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        type="email"
                        bind:value={emailText}
                        error={invalid}
                        aria-describedby={describedBy}
                        testId="billing-contact-email-input"
                    />
                {/snippet}
            </FormField>

            <FormField label="宛名 (任意)" id="billing-contact-name" error={nameError}>
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        bind:value={nameText}
                        error={invalid}
                        aria-describedby={describedBy}
                        testId="billing-contact-name-input"
                    />
                {/snippet}
            </FormField>

            <div>
                <Button type="submit" loading={submitting} testId="billing-contact-submit">
                    請求先情報を保存
                </Button>
            </div>
        </form>
```

### 先行実装 T041: resources/js/pages/Billing/PurchaseTickets.svelte (L40-101)

```svelte
    // props から一度だけ seed する (以後はユーザー入力が真実)
    // svelte-ignore state_referenced_locally
    let countText = $state<string | number>(String(page.defaultCount));
    let submitting = $state(false);
    let clientError = $state<string | null>(null);

    // 生入力を整数として厳格に解釈する (clamp / floor の暗黙補正をしない)。
    // 解釈規則は pages/Billing/ticketCount.ts が単一出典。
    const parsedCount = $derived.by<number | null>(() => parseTicketCount(countText));

    const isValidCount = $derived(
        parsedCount !== null && parsedCount >= page.minCount && parsedCount <= page.maxCount,
    );

    // clientError は「購入枚数の範囲バリデーション」専用の transient state。押下時にのみ設定され、
    // 値が有効へ復帰した時点で自動解消する (「押下時にエラー表示」契約は維持: 無効のままなら残す)。
    // serverErrors (full POST 往復由来) は本 effect の対象外で別経路。
    // ※不変条件: 将来 clientError に別種のメッセージを載せる場合はこのクリア条件の再検討が必要。
    // clientError の有無も条件に含めることで不要な代入を避け、意図を明確化する。
    $effect(() => {
        if (clientError !== null && isValidCount) {
            clientError = null;
        }
    });

    // 適用単価: tiers (minCount 昇順) から minCount <= count の最大段を選ぶ
    const appliedUnit = $derived.by<number | null>(() => {
        if (parsedCount === null) return null;
        let unit: number | null = null;
        for (const tier of page.tiers) {
            if (parsedCount >= tier.minCount) unit = tier.unitAmount;
        }
        return unit;
    });

    // 合計は妥当時のみ金額表示 (範囲外は — 表示で誤認を防ぐ)
    const totalAmount = $derived(
        isValidCount && parsedCount !== null && appliedUnit !== null
            ? parsedCount * appliedUnit
            : null,
    );

    const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);

    // 傾斜表の帯表示 (Pricing と同じ変換規則。表示都合が異なるため molecule 共有はしない)
    const tierRows = $derived(
        page.tiers.map((tier, i) => {
            const next = page.tiers[i + 1];
            return {
                label: next ? `${tier.minCount}〜${next.minCount - 1} 枚` : `${tier.minCount} 枚以上`,
                unitAmount: tier.unitAmount,
            };
        }),
    );

    function submit(): void {
        if (submitting) return; // 多重送信ガード (disabled にはしない)
        clientError = null;
        if (!isValidCount || parsedCount === null) {
            clientError = `購入枚数は ${page.minCount}〜${page.maxCount} の整数で入力してください`;
            return;
        }
```

### 先行実装 T044: resources/js/pages/Organizations/Settings.svelte (L90-140)

```svelte

    /* ---- オーナー移譲 (recent-auth 必須。precheck で鮮度を確認してから送る) ---- */
    const transferForm = useForm({ user_id: "" });
    let transferDialogOpen = $state(false);
    // client precheck 専用の transient error。serverErrors (transferForm.errors) とは分離し、
    // 有効値復帰で自動解消する (「押下時にエラー表示」契約は維持: 無効のままなら残す)。
    let transferClientError = $state<string | null>(null);

    const transferCandidates = $derived(members.filter((member) => member.id !== myId));
    const transferTargetName = $derived(
        transferCandidates.find((member) => String(member.id) === transferForm.user_id)?.name ??
            "",
    );

    // precheck 合格条件 = 選択値が実在候補に一致すること。エラー条件はこの否定。
    const isValidTransferTarget = $derived(
        transferCandidates.some((member) => String(member.id) === transferForm.user_id),
    );

    // 有効候補へ復帰した時点で client error を連動クリア (過剰クリア防止: clientError!=null かつ有効時のみ)。
    // 候補 0 人ケースのエラーは isValidTransferTarget が常に false のため残留する = 選択では直せないので正しい。
    // serverErrors (transferForm.errors) はこの effect の対象外 = 非退行。
    $effect(() => {
        if (transferClientError !== null && isValidTransferTarget) {
            transferClientError = null;
        }
    });

    /** 候補 0 人時の共通文言 (案内文と押下時エラーで揺れないよう単一定義。テストも本文言を検証) */
    const NO_TRANSFER_CANDIDATES = "移譲先にできるメンバーがいません。";

    /**
     * 移譲確認ダイアログを開く。成立し得ない操作は ConfirmDialog まで進めず、
     * 押下時にエラー表示する (disabled 禁止 = AGENTS.md 8)。
     * 選択値の実在検証は DOM 改変・stale 値の早期排除で、最終ゲートはサーバ
     * (Policy + exists:users,id + Service のメンバーシップ検証)。
     * select の value は string のため、Member.id (number) は String() に揃えて比較する。
     */
    function openTransferDialog(event: SubmitEvent): void {
        event.preventDefault();
        if (transferCandidates.length === 0) {
            transferClientError = `${NO_TRANSFER_CANDIDATES}先にメンバーを招待してください。`;
            return;
        }
        if (!isValidTransferTarget) {
            transferClientError = "移譲先のメンバーを選択してください。";
            return;
        }
        transferDialogOpen = true;
    }

```

### DESIGN.md 該当節 (L184-193 / L301-308 / L428-443)

```markdown
### Input / Textarea / Select(入力系 atom)

実装: `components/atoms/Input.svelte` / `Textarea.svelte` / `Select.svelte`。
見た目は `components/atoms/input-state.ts`(`INPUT_BASE_CLASSES` + `inputStateClass`)に集約し、
入力系 atom 間で統一する。`error` prop で danger 枠と `aria-invalid` が連動する。
`aria-describedby` 等は restProps で透過。Select の `<option>` 群は呼び出し側が
children snippet として記述する。Input の `type` は text 系に限定した union。
ラベル・エラー文言・`aria-describedby` の配線は FormField molecule の責務
(入力 atom は最小責務に保つ)。パスワード入力は素の `Input type="password"` ではなく
PasswordInput molecule を使う。

### FormField

実装: `components/molecules/FormField.svelte`。ラベル + 入力 + エラー(FormError)+
ヘルプの複合 molecule。入力 atom を最小責務に保つため、ラベル・エラー文言・
`aria-describedby` の配線は本 molecule が担う(関心分離)。children snippet に
`{ id, describedBy, invalid }` を渡すので、呼び出し側はそれを入力 atom へ流し込む。
`required` は `*`(danger 色、`aria-hidden`)をラベルに付与する。フォームの入力欄は
本 molecule 経由で組む(AGENTS.md 実装規約)。

## Do's and Don'ts

**Do**

- 背景は常に neutral、浮いた要素は surface(逆に使わない)
- 余白を多めにとる。色は Primary / Tertiary / 状態色 1 種までを目安に
- 操作の可否は**押した後のフィードバック**で伝える(バリデーションエラー表示+フォーカス移動)

**Don't**

- グラデーション・ドロップシャドウ・scale 効果を使わない
- Danger と Tertiary を同一 action cluster・隣接 CTA 群で併置しない(赤系・強調系の意味が混ざる)
- **必須条件未充足を理由にボタンを disabled でブロックしない**。ボタンは活性のまま、
  押下時に何が足りないかをエラー表示する(例: 利用規約同意チェック。
  disabled はユーザーに「なぜ押せないか」を伝えられない)
- ページ内で素の `<input>` / `<table>` / リンク風 `<a>` 手書きをしない(対応する atom/molecule を使う)
```
