## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
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

# system: 実装レビュー (Laravel + Svelte)

あなたはコードレビュアーとして、TODO T094 の実装差分をレビューする。

## レビュー観点

1. **設計との一致性**: 詳細設計書の施策 1〜5 が過不足なく実装されているか。設計に無いものを足していないか (オーバーエンジニアリング)
2. **正確性**: Svelte 5 runes の使い方 ($state / $derived / $props / $bindable) が正しいか。リアクティビティの穴・無限ループ・stale 参照が無いか
3. **PHPStan 適合性**: 本タスクは PHP 無変更。該当なしなら「該当なし」でよい
4. **DTO/JsonResource パターン**: 同上 (PHP 無変更)
5. **テスト網羅性**: 各施策に対応するテストがあるか。テストが実装を「後追いで追認するだけ」になっていないか (偽陰性)。architecture テストの検出器に穴が無いか
6. **セキュリティ**: readonly はあくまで UI 表現であり認可境界ではない (サーバ側 Gate が正本)。この前提が崩れていないか。novalidate 化でサーバ検証に到達できない経路が生まれていないか
7. **DESIGN.md 準拠**: `/DESIGN.md` が design token の canonical source。color / radius / typography は token 経由で参照し hex 直書き (`#RRGGBB`) を増やさない。token 値を変更する diff は `resources/css/tokens.css` と同一 diff 内で同期しているか (運用契約は `docs/design-system.md`)
8. **Atomic Design 準拠**: `resources/js/components/` は `atoms/molecules/organisms/features/templates/pages` の責務分離と単方向 import に従う。atom は単機能・状態を持たない、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide を使い、SVG 直書きを増やさない

## 出力形式

- ファイルごとに判定 (APPROVE / REQUEST_CHANGES) を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する

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

## レビュー履歴

| フェーズ | model / effort | ラウンド | 結果 | 主な指摘と対応 |
|---|---|---|---|---|
| 概念設計 | `gpt-5.4` / medium | 1 | **APPROVED** | [W] `novalidate` 適用の受入条件明記 → 8 form の errors 配線を実測して受入条件節を追加 / [W] readonly を disabled と同一視しない → 文字色・カーソル・フォーカスで差を付ける仕様に修正 |
| 詳細設計 | `gpt-5.3-codex` / high | 1 | CHANGES_REQUESTED (施策 3/5) | [W] `novalidate` テストの正規表現走査 → `svelte/compiler` の AST 走査へ変更 (実測で 99 ファイル parse / form 33 検出を確認)。`bg-*` 禁止 lint と Browser E2E の 2 Suggestion は根拠付きで見送り |
| 詳細設計 | `gpt-5.3-codex` / high | 2 | CHANGES_REQUESTED (施策 5) | [W] `novalidate={false}` / `={cond}` が合格する偽陰性 → `value === true` の静的 shorthand 限定 + source ベース分離 + 検出器の自己テスト追加。見送り 2 件は「妥当」と追認 |
| 詳細設計 | `gpt-5.3-codex` / high | 3 | **APPROVED** | 指摘なし |

機械出力は `codex-history/` (プロンプト・対応マトリクス) と
`conceptual-review-round-1.md` / `detailed-review-round-{1,2,3}.md` (Codex の返答)。

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
- **「入力 atom の `class` prop に `bg-*` を渡すことを禁じる lint / architecture ルール」は
  今回作らない** (Codex Round 1 [Suggestion] への回答)。現に違反 call site はゼロで、
  存在しない問題に機構を足すのはオーバーエンジニアリング (思考原則 2)。必要になるのは
  「background を上書きしたい call site が実際に現れたとき」で、そのときは
  ルールを足すより「なぜ atom の面を上書きしたいのか」を先に問うべき
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

**書式**: `novalidate` は `<form` の直後(最初の属性)に書く。「このフォームの検証はサーバと
明示 client エラーが正本」という宣言を先頭で読ませるための**可読性上の慣習**であり、
**機械強制はしない**(施策 5-1 の architecture テストは AST で `novalidate` の**有無**だけを見る。
位置まで縛るのは機械可読性の都合が消えた今、根拠のない追加制約になる — Codex Round 1 の
AST 化指摘を受けた判断)。

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
  **canonical なのはこの不変条件であって実装形ではない**。実装は
  **「提示を開始したかの boolean」+ 文言は `$derived`** で組むのが既定(文言を `$state` で
  持つと同期漏れが起きる。`$effect` での状態同期はしない = Svelte 公式の指針)。
  先行実装(`Billing/PurchaseTickets.svelte` / `Organizations/Settings.svelte`)は `$effect` に
  よる連動クリアで**同じ不変条件を満たしており、そのまま許容する**(動いている仕組みを
  churn させない)。**新規は `$derived` 形で書く**
- サーバ由来の errors(`form.errors.*`)はこの追随の対象外。入力の変更で消さない
```

**§Do's and Don'ts の Don't** に追記:

```markdown
- **native の constraint validation に検証を任せない**。`<form>` には `novalidate` を付け、
  検証文言はサーバ(日本語)と押下時の client エラーに一本化する。
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

**実装は `svelte/compiler` の AST 走査で行う**(Codex Round 1 [Warning] 反映)。生テキストの
正規表現走査は、`<script>` 内の文字列・コメント中の `<form` を誤検出し(偽陽性)、
属性値に `=>` を含む onsubmit ハンドラで開始タグの切り出しに失敗しうる(偽陰性)。

```ts
import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { parse } from "svelte/compiler";

/**
 * resources/js 配下の全 <form> が novalidate を持つことを機械検証する。
 *
 * 検証 UX の正本はサーバ (日本語) + 押下時の client エラー (DESIGN.md §Do's and Don'ts)。
 * native constraint validation は submit より先に発火し、ブラウザロケール依存の文言で
 * 送信自体を止めるため、日本語の検証経路に到達できなくなる (bug-hunt F-3-02)。
 *
 * 判定は svelte/compiler の AST (modern) で行う。テキスト走査では <script> 内の文字列や
 * コメント中の "<form" を誤検出するため。
 *
 * 例外を足したくなったら allowlist を作る前に、「なぜ日本語のエラー経路では足りないのか」を疑うこと。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");

// listFiles / relPath は ds-purity.test.ts と同形 (recursive readdir、.svelte のみ)

interface AttributeNode {
    type?: string;
    name?: string;
    value?: unknown;
}

/**
 * source 文字列に対する検査 (ファイル I/O から分離 = 自己テスト可能にする)。
 * `novalidate` は **静的な boolean shorthand のみ**を合格とする。
 * `novalidate={false}` / `novalidate={cond}` は実行時に属性が消えうるため違反扱い
 * (Svelte の AST では shorthand のときだけ `value === true` になる)。
 */
export function formViolationsInSource(source: string, label: string): string[] {
    const ast = parse(source, { modern: true, filename: label });
    const out: string[] = [];
    const visit = (node: unknown): void => {
        if (node === null || typeof node !== "object") return;
        if (Array.isArray(node)) {
            node.forEach(visit);
            return;
        }
        const n = node as {
            type?: string;
            name?: string;
            start?: number;
            attributes?: AttributeNode[];
        };
        if (n.type === "RegularElement" && n.name === "form") {
            const hasNoValidate = (n.attributes ?? []).some(
                (a) => a.type === "Attribute" && a.name === "novalidate" && a.value === true,
            );
            if (!hasNoValidate) {
                const line = source.slice(0, n.start ?? 0).split("\n").length;
                out.push(`${label}:${line}`);
            }
        }
        for (const [key, value] of Object.entries(n)) {
            if (key === "parent") continue; // 循環参照を踏まない
            visit(value);
        }
    };
    visit((ast as { fragment: unknown }).fragment);
    return out;
}

describe("form validation policy", () => {
    it("resources/js の全 <form> が novalidate を持つ (native validation に依存しない)", () => {
        const violations = svelteFiles.flatMap((file) =>
            formViolationsInSource(fs.readFileSync(file, "utf-8"), relPath(file)),
        );
        expect(violations).toEqual([]);
    });

    // 検出器そのものの自己テスト (偽陰性を作らないことを固定する)
    it.each([
        ["<form novalidate></form>", 0],
        ["<form></form>", 1],
        ["<form novalidate={false}></form>", 1],
        ["<form novalidate={cond}></form>", 1],
        ['<script>const s = "<form>";</script><form novalidate></form>', 0],
    ])("検出器: %s → 違反 %i 件", (source, expected) => {
        expect(formViolationsInSource(source, "inline.svelte")).toHaveLength(expected);
    });
});
```

- 固定する不変条件: **native constraint validation に依存しない**(施策 3 / 規約 2)
- 失敗の仕方: 新規フォームを `novalidate` なしで足すと即 fail(ファイル:行 を提示)
- **実現性を実測済み** (svelte 5.56.3): `resources/js` の 99 個の `.svelte` が全て parse でき、
  `RegularElement`/`name === "form"` の検出数は **33** で grep 結果と完全一致した
  (= 走査漏れ・重複なし)。`parent` を辿らない再帰で循環参照も踏まない
- **属性値の形も実測済み**: `<form novalidate>` は `Attribute.value === true`、
  `novalidate={false}` / `novalidate={cond}` は式ノード (object)、`novalidate="novalidate"` は
  `[Text]` になる。よって `value === true` の一致で「静的に必ず付く」ものだけを合格にできる
  (Codex Round 2 [Warning] 反映)

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
>
> **Codex Round 1 [Suggestion]「E2E 1 本の最小補強」への回答**: 見送る。理由は
> (a) `tests/Browser` は現在 2 本 (bfcache / smoke) で、両レーン (Chromium + WebKit) が契約
> (`docs/testing-browser.md`)。1 本の追加が実行時間としては 2 レーン分になる、
> (b) 守りたい不変条件は「全 form に `novalidate` がある」であり、1 画面の E2E では
> **他の 32 form を守れない** — 網羅的に守れるのは 5-1 の architecture テストの方。
> E2E は「ブラウザが本当にブロックしないか」の一点だけを担保するが、これは HTML 仕様
> (`novalidate` の定義) に属する事実で、アプリ側の回帰点ではない。

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

## design system 参照 (DESIGN.md 関連節・**本 diff 適用後**)

### Input / Textarea / Select(入力系 atom)

実装: `components/atoms/Input.svelte` / `Textarea.svelte` / `Select.svelte`。
見た目は `components/atoms/input-state.ts`(`INPUT_BASE_CLASSES` + `inputStateClass`)に集約し、
入力系 atom 間で統一する。`error` prop で danger 枠と `aria-invalid` が連動する。
`aria-describedby` 等は restProps で透過。Select の `<option>` 群は呼び出し側が
children snippet として記述する。Input の `type` は text 系に限定した union。
ラベル・エラー文言・`aria-describedby` の配線は FormField molecule の責務
(入力 atom は最小責務に保つ)。パスワード入力は素の `Input type="password"` ではなく
PasswordInput molecule を使う。

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

### FormField

実装: `components/molecules/FormField.svelte`。ラベル + 入力 + エラー(FormError)+
ヘルプの複合 molecule。入力 atom を最小責務に保つため、ラベル・エラー文言・
`aria-describedby` の配線は本 molecule が担う(関心分離)。children snippet に
`{ id, describedBy, invalid }` を渡すので、呼び出し側はそれを入力 atom へ流し込む。
`required` は `*`(danger 色、`aria-hidden`)をラベルに付与する。フォームの入力欄は
本 molecule 経由で組む(AGENTS.md 実装規約)。

- **押下時に出した client エラーは、その後の入力に追随させる**(stale invalid を残さない)。
  ボタンを disabled にしない(§Do's and Don'ts / AGENTS.md 禁止事項 8)代わりに押下時にエラーを
  出すのだから、そのエラーは常に「今の入力」を説明していなければならない — 有効に戻ったら消え、
  無効の理由が変わったら文言も変わる。押下前には出さない。
  **canonical なのはこの不変条件であって実装形ではない**。実装は
  **「提示を開始したかの boolean」+ 文言は `$derived`** で組むのが既定(文言を `$state` で
  持つと同期漏れが起きる。`$effect` での状態同期はしない = Svelte 公式の指針)。
  先行実装(`Billing/PurchaseTickets.svelte` / `Organizations/Settings.svelte`)は `$effect` に
  よる連動クリアで**同じ不変条件を満たしており、そのまま許容する**(動いている仕組みを
  churn させない)。**新規は `$derived` 形で書く**
- サーバ由来の errors(`form.errors.*`)はこの追随の対象外。入力の変更で消さない

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
- **native の constraint validation に検証を任せない**。`<form>` には `novalidate` を付け、
  検証文言はサーバ(日本語)と押下時の client エラーに一本化する。
  native validation は submit より先に発火してブラウザロケール依存の文言で送信を止めるため、
  日本語 UI の検証経路に到達できなくなる(`tests/js/architecture/form-novalidate.test.ts` が機械検証)

### 触れた atomic ディレクトリ構造

```
resources/js/components/atoms:
Alert.svelte
Avatar.svelte
Badge.svelte
Badge.types.ts
Button.svelte
Button.types.ts
Card.svelte
Checkbox.svelte
FormError.svelte
Input.svelte
Select.svelte
Spinner.svelte
TextLink.svelte
TextLink.types.ts
Textarea.svelte
Toggle.svelte
Toggle.types.ts
icons
input-state.ts

resources/js/components/molecules:
ApiKeyTabNav.svelte
Breadcrumb.svelte
CodeSnippet.svelte
DangerZone.svelte
Divider.svelte
EmptyState.svelte
FormField.svelte
NotificationBell.svelte
PageHeader.svelte
PageHeaderSection.svelte
Pagination.svelte
PasswordInput.svelte
PricingPlanCard.svelte
PricingPlanCard.types.ts
StatCard.svelte
Tabs.svelte

```

---

## 実装差分 (git diff)

```diff
diff --git a/DESIGN.md b/DESIGN.md
index 85f11a8..cfe7cc6 100644
--- a/DESIGN.md
+++ b/DESIGN.md
@@ -192,6 +192,21 @@ ### Input / Textarea / Select(入力系 atom)
 (入力 atom は最小責務に保つ)。パスワード入力は素の `Input type="password"` ではなく
 PasswordInput molecule を使う。
 
+- **`type` は入力補助であって検証手段ではない**。`email` / `tel` / `url` / `number` 等は
+  モバイルキーボード・autofill・スクリーンリーダーの型アナウンスのために付ける。
+  検証の正本はサーバ(日本語)と押下時の client エラーで、native constraint validation には
+  依存しない(form 側で `novalidate`。§Do's and Don'ts)。`inputmode` は restProps で透過する
+- **readonly は「編集できない」ことを面で示す**(`Input` / `Textarea` の `readonly` prop)。
+  `bg-neutral` + `cursor-default`。ただし **disabled と同じ見た目にしない** — readonly の値は
+  生きている(送信される・選択してコピーできる・フォーカスできる)ので、文字色は `text-text` の
+  ままにし focus ring も維持する。disabled は `text-text-secondary` + `cursor-not-allowed` +
+  フォーカス不可。`<select>` は HTML 仕様上 readonly を持たない(編集させないなら値を
+  読み取り表示にする)
+- 「編集させない値」の表現は 2 通り。**そのフォームの送信対象に含む / コピーさせたい**なら
+  readonly input(例: 招待 email の prefill、権限が無い閲覧者への設定値提示)、
+  **編集手段自体を出さない**なら読み取り表示(`<dl>` 等。例: 請求先情報カードの非管理者表示)。
+  readonly input を選んだ場合、上記の見た目が付くことは atom が保証する
+
 ### Checkbox
 
 実装: `components/atoms/Checkbox.svelte`。インラインラベル(右側)とエラー表示
@@ -307,6 +322,18 @@ ### FormField
 `required` は `*`(danger 色、`aria-hidden`)をラベルに付与する。フォームの入力欄は
 本 molecule 経由で組む(AGENTS.md 実装規約)。
 
+- **押下時に出した client エラーは、その後の入力に追随させる**(stale invalid を残さない)。
+  ボタンを disabled にしない(§Do's and Don'ts / AGENTS.md 禁止事項 8)代わりに押下時にエラーを
+  出すのだから、そのエラーは常に「今の入力」を説明していなければならない — 有効に戻ったら消え、
+  無効の理由が変わったら文言も変わる。押下前には出さない。
+  **canonical なのはこの不変条件であって実装形ではない**。実装は
+  **「提示を開始したかの boolean」+ 文言は `$derived`** で組むのが既定(文言を `$state` で
+  持つと同期漏れが起きる。`$effect` での状態同期はしない = Svelte 公式の指針)。
+  先行実装(`Billing/PurchaseTickets.svelte` / `Organizations/Settings.svelte`)は `$effect` に
+  よる連動クリアで**同じ不変条件を満たしており、そのまま許容する**(動いている仕組みを
+  churn させない)。**新規は `$derived` 形で書く**
+- サーバ由来の errors(`form.errors.*`)はこの追随の対象外。入力の変更で消さない
+
 ### DangerZone
 
 実装: `components/molecules/DangerZone.svelte`。破壊的・取り返しのつかない操作
@@ -441,6 +468,10 @@ ## Do's and Don'ts
   押下時に何が足りないかをエラー表示する(例: 利用規約同意チェック。
   disabled はユーザーに「なぜ押せないか」を伝えられない)
 - ページ内で素の `<input>` / `<table>` / リンク風 `<a>` 手書きをしない(対応する atom/molecule を使う)
+- **native の constraint validation に検証を任せない**。`<form>` には `novalidate` を付け、
+  検証文言はサーバ(日本語)と押下時の client エラーに一本化する。
+  native validation は submit より先に発火してブラウザロケール依存の文言で送信を止めるため、
+  日本語 UI の検証経路に到達できなくなる(`tests/js/architecture/form-novalidate.test.ts` が機械検証)
 
 ## 色の意味的割り当てルール
 
diff --git a/resources/js/components/atoms/Input.svelte b/resources/js/components/atoms/Input.svelte
index 681dfb6..dfdf35e 100644
--- a/resources/js/components/atoms/Input.svelte
+++ b/resources/js/components/atoms/Input.svelte
@@ -12,10 +12,15 @@
         | "search"
         | "date";
 
-    interface Props extends Omit<HTMLInputAttributes, "type" | "value" | "class"> {
+    // type は「入力補助 (モバイルキーボード / autofill / 型のアナウンス)」のための意味付けであり、
+    // 検証手段ではない。検証の正本はサーバ (日本語) + 押下時の client エラーで、
+    // native constraint validation には依存しない (form 側の novalidate。DESIGN.md §Do's and Don'ts)。
+    interface Props extends Omit<HTMLInputAttributes, "type" | "value" | "class" | "readonly"> {
         type?: InputType;
         value?: string;
         error?: boolean;
+        /** 編集不可だが値は生きている (送信される・コピー/フォーカス可)。disabled とは意味が違う */
+        readonly?: boolean;
         testId?: string;
         class?: string;
     }
@@ -24,18 +29,22 @@
         type = "text",
         value = $bindable(""),
         error = false,
+        readonly = false,
         testId,
         class: extraClass = "",
         ...rest
     }: Props = $props();
 
     const computedClass = $derived(
-        [INPUT_BASE_CLASSES, inputStateClass(error), extraClass].filter(Boolean).join(" "),
+        [INPUT_BASE_CLASSES, inputStateClass(error, readonly), extraClass]
+            .filter(Boolean)
+            .join(" "),
     );
 </script>
 
 <input
     {type}
+    {readonly}
     bind:value
     class={computedClass}
     aria-invalid={error || undefined}
diff --git a/resources/js/components/atoms/Textarea.svelte b/resources/js/components/atoms/Textarea.svelte
index 6740172..6f9425a 100644
--- a/resources/js/components/atoms/Textarea.svelte
+++ b/resources/js/components/atoms/Textarea.svelte
@@ -12,15 +12,18 @@
         value?: string;
         /** true で枠線を danger 化し aria-invalid を立てる */
         error?: boolean;
+        /** 編集不可だが値は生きている (送信される・コピー/フォーカス可)。disabled とは意味が違う */
+        readonly?: boolean;
         /** data-testid に反映するテスト用 ID */
         testId?: string;
         class?: string;
-    } & Omit<HTMLTextareaAttributes, "value" | "class">;
+    } & Omit<HTMLTextareaAttributes, "value" | "class" | "readonly">;
 
     let {
         value = $bindable(),
         error = false,
         disabled = false,
+        readonly = false,
         rows = 4,
         id,
         placeholder,
@@ -29,9 +32,11 @@
         ...restProps
     }: Props = $props();
 
-    // マージ順: 共通 base → error 状態 → 外部 class (外部後勝ち)
+    // マージ順: 共通 base → error/readonly 状態 → 外部 class (外部後勝ち)
     const computedClass = $derived(
-        [INPUT_BASE_CLASSES, inputStateClass(error), extraClass].filter(Boolean).join(" "),
+        [INPUT_BASE_CLASSES, inputStateClass(error, readonly), extraClass]
+            .filter(Boolean)
+            .join(" "),
     );
 </script>
 
@@ -43,6 +48,7 @@
     {rows}
     {placeholder}
     {disabled}
+    {readonly}
     aria-invalid={error || undefined}
     data-testid={testId}
     class={computedClass}
diff --git a/resources/js/components/atoms/input-state.ts b/resources/js/components/atoms/input-state.ts
index 711f165..f338bcb 100644
--- a/resources/js/components/atoms/input-state.ts
+++ b/resources/js/components/atoms/input-state.ts
@@ -3,8 +3,11 @@
  * 見た目の真実は DESIGN.md §Components。変更時は全入力 atom に波及することに注意。
  */
 
+// 背景色は inputStateClass 側で確定させる (readonly と競合させないため base に置かない。
+// Tailwind は同一プロパティの utility が並んだ場合、勝敗が class 属性の順ではなく
+// 生成 CSS の順で決まるため、bg は常に 1 つだけ出力する)。
 export const INPUT_BASE_CLASSES = [
-    "w-full rounded-sm border bg-surface text-body text-text",
+    "w-full rounded-sm border text-body text-text",
     "px-3 py-1.5",
     "transition-colors duration-150",
     "placeholder:text-text-secondary/70",
@@ -12,7 +15,17 @@ export const INPUT_BASE_CLASSES = [
     "disabled:cursor-not-allowed disabled:bg-neutral disabled:text-text-secondary",
 ].join(" ");
 
-/** error の有無で border 色を切り替える */
-export function inputStateClass(error: boolean): string {
-    return error ? "border-danger ring-3 ring-danger/15" : "border-border-strong";
+/**
+ * error / readonly の状態クラス。
+ *
+ * - error: border を danger 化する (readonly でも維持する = どのフィールドが不正か分かる)
+ * - readonly: **編集できないことを面で示す**。ただし disabled とは意味が違うので同一にしない —
+ *   readonly の値は生きている (送信される・選択してコピーできる・フォーカスできる) ため、
+ *   文字色は通常のまま (`text-text`)、カーソルは `cursor-default`、focus ring は base のまま維持する。
+ *   disabled は `text-text-secondary` + `cursor-not-allowed` + フォーカス不可 (base の disabled: 側)。
+ *   `<select>` は HTML 仕様上 readonly を持たないため呼び出さない (既定 false)。
+ */
+export function inputStateClass(error: boolean, readonly = false): string {
+    const border = error ? "border-danger ring-3 ring-danger/15" : "border-border-strong";
+    return readonly ? `${border} bg-neutral cursor-default` : `${border} bg-surface`;
 }
diff --git a/resources/js/components/features/billing/AutoRechargeCard.svelte b/resources/js/components/features/billing/AutoRechargeCard.svelte
index f8b0fb2..6b6ed00 100644
--- a/resources/js/components/features/billing/AutoRechargeCard.svelte
+++ b/resources/js/components/features/billing/AutoRechargeCard.svelte
@@ -42,8 +42,13 @@
     let maxText = $derived(String(autoRecharge.maxCount));
     let submitting = $state(false);
     let showConsent = $state(false);
-    /** 押下時に初めて出す入力エラー (disabled でブロックしない代わりの提示点) */
-    let inputError = $state<string | null>(null);
+    /**
+     * 押下時に初めてエラーを提示する (disabled でブロックしない = 禁止事項 #8) が、
+     * 一度提示したら以後は現在の入力に追随させる (stale invalid を残さない = DESIGN.md §FormField)。
+     * 文言そのものを state で持たず「提示を開始したか」だけを持つことで、
+     * rangeError との同期漏れが構造的に起きない ($effect による状態同期を避ける)。
+     */
+    let inputErrorShown = $state(false);
     /** サーバ 422 の可視化 (flash toast は errors bag を運ばないため silent failure を防ぐ) */
     let serverError = $state<string | null>(null);
 
@@ -94,6 +99,9 @@
         return null;
     });
 
+    /** 表示中の入力エラー。提示開始後は rangeError に完全追随する (有効化で消え、理由が変われば文言も変わる) */
+    const inputError = $derived(inputErrorShown ? rangeError : null);
+
     // 適用単価: Max 枚をまとめ買いした場合の tier 単価 (同意文言の上限額と同じ計算)。
     const appliedUnit = $derived.by<number>(() => {
         const c = parsedMax;
@@ -148,7 +156,7 @@
             },
             onSuccess: () => {
                 serverError = null;
-                inputError = null;
+                inputErrorShown = false;
                 showConsent = false;
             },
             onFinish: () => {
@@ -159,7 +167,7 @@
 
     /** 入力値の妥当性を押下時に確定する (disabled でブロックしない = 禁止事項 #8)。 */
     function ensureValidRange(): boolean {
-        inputError = rangeError;
+        inputErrorShown = true;
         return rangeError === null;
     }
 
@@ -207,7 +215,8 @@
     /** 停止は常に成立させる (入力値が壊れていても現在値で送る = ワンクリック停止の保証)。 */
     function handleDisable(): void {
         if (submitting) return;
-        inputError = null;
+        // 停止は入力値が壊れていても成立させる契約なので、提示自体を畳む
+        inputErrorShown = false;
         const threshold = parsedThreshold ?? autoRecharge.thresholdCount;
         const max =
             parsedMax !== null && parsedMax > threshold ? parsedMax : autoRecharge.maxCount;
diff --git a/resources/js/components/features/billing/BillingContactForm.svelte b/resources/js/components/features/billing/BillingContactForm.svelte
index 3c12fd3..9fb6755 100644
--- a/resources/js/components/features/billing/BillingContactForm.svelte
+++ b/resources/js/components/features/billing/BillingContactForm.svelte
@@ -67,6 +67,7 @@
 
     {#if canManage}
         <form
+            novalidate
             class="mt-4 flex flex-col gap-4"
             data-testid="billing-contact-form"
             onsubmit={(event) => {
diff --git a/resources/js/components/features/manual/DuplicateManualDialog.svelte b/resources/js/components/features/manual/DuplicateManualDialog.svelte
index b75087b..a8f946a 100644
--- a/resources/js/components/features/manual/DuplicateManualDialog.svelte
+++ b/resources/js/components/features/manual/DuplicateManualDialog.svelte
@@ -89,7 +89,7 @@
 </script>
 
 <Modal bind:open title="動画マニュアルを複製" size="sm" processing={form.processing} testId="duplicate-manual-dialog">
-    <form id="duplicate-manual-form" onsubmit={onFormSubmit} class="flex flex-col gap-4">
+    <form novalidate id="duplicate-manual-form" onsubmit={onFormSubmit} class="flex flex-col gap-4">
         <p class="text-caption text-text-secondary">
             シナリオ（カット）を引き継いだ新しい動画マニュアルを作成します。撮影データ・手順書（SOP）は引き継がれません。
         </p>
diff --git a/resources/js/components/features/manual/SourceDocumentUpload.svelte b/resources/js/components/features/manual/SourceDocumentUpload.svelte
index 369cbb4..eb00b2d 100644
--- a/resources/js/components/features/manual/SourceDocumentUpload.svelte
+++ b/resources/js/components/features/manual/SourceDocumentUpload.svelte
@@ -30,7 +30,7 @@
     }
 </script>
 
-<form onsubmit={submit} class="flex flex-col gap-3" data-testid="source-document-upload">
+<form novalidate onsubmit={submit} class="flex flex-col gap-3" data-testid="source-document-upload">
     <FormField
         label={hasDocument ? "手順書を差し替える" : "手順書 (SOP) をアップロード"}
         id="source-document"
diff --git a/resources/js/components/organisms/RecentAuthModal.svelte b/resources/js/components/organisms/RecentAuthModal.svelte
index a136bf9..dbe5d51 100644
--- a/resources/js/components/organisms/RecentAuthModal.svelte
+++ b/resources/js/components/organisms/RecentAuthModal.svelte
@@ -99,7 +99,7 @@
         </div>
 
         {#if passwordSet}
-            <form onsubmit={submitPassword} class="flex flex-col gap-3">
+            <form novalidate onsubmit={submitPassword} class="flex flex-col gap-3">
                 <FormField label="現在のパスワード" id="recent-auth-password" error={error}>
                     {#snippet children({ id, describedBy, invalid })}
                         <Input
diff --git a/resources/js/pages/Admin/Categories.svelte b/resources/js/pages/Admin/Categories.svelte
index e054bb8..f0971da 100644
--- a/resources/js/pages/Admin/Categories.svelte
+++ b/resources/js/pages/Admin/Categories.svelte
@@ -127,7 +127,7 @@
             <div class="flex min-w-0 flex-col gap-10">
                 <Card padding="lg">
                     <h2 class="text-h3">カテゴリを追加</h2>
-                    <form onsubmit={submitAddCategory} class="mt-4 flex items-start gap-2">
+                    <form novalidate onsubmit={submitAddCategory} class="mt-4 flex items-start gap-2">
                         <div class="grow">
                             <FormField
                                 label="カテゴリ名"
@@ -228,7 +228,7 @@
             processing={editCategoryForm.processing}
             testId="edit-category-modal"
         >
-            <form onsubmit={submitEditCategory} class="flex flex-col gap-4">
+            <form novalidate onsubmit={submitEditCategory} class="flex flex-col gap-4">
                 <FormField
                     label="カテゴリ名"
                     id="edit-category-name"
diff --git a/resources/js/pages/Admin/Users.svelte b/resources/js/pages/Admin/Users.svelte
index 59fd677..9cd50e5 100644
--- a/resources/js/pages/Admin/Users.svelte
+++ b/resources/js/pages/Admin/Users.svelte
@@ -374,7 +374,7 @@
                     <p class="mt-1 text-caption text-text-secondary">
                         招待メールを送信します。招待の有効期限は 7 日間です。
                     </p>
-                    <form onsubmit={submitInvite} class="mt-4 flex flex-col gap-4">
+                    <form novalidate onsubmit={submitInvite} class="mt-4 flex flex-col gap-4">
                         <FormField
                             label="メールアドレス"
                             id="invite-email"
@@ -483,7 +483,7 @@
             title="メンバーの 2FA を解除"
             testId="reset-two-factor-modal"
         >
-            <form onsubmit={submitResetTwoFactor} class="flex flex-col gap-4">
+            <form novalidate onsubmit={submitResetTwoFactor} class="flex flex-col gap-4">
                 <p class="text-body">
                     {resetTwoFactorTarget?.name ?? ""} さんの 2 段階認証を解除します。
                     解除はこのアカウント全体に及び、本人へセキュリティ通知が送信されます。
diff --git a/resources/js/pages/Auth/ConfirmRecentAuth.svelte b/resources/js/pages/Auth/ConfirmRecentAuth.svelte
index c46e008..1594b2b 100644
--- a/resources/js/pages/Auth/ConfirmRecentAuth.svelte
+++ b/resources/js/pages/Auth/ConfirmRecentAuth.svelte
@@ -47,7 +47,7 @@
     </p>
 
     {#if passwordSet}
-        <form onsubmit={submit} class="flex flex-col gap-4">
+        <form novalidate onsubmit={submit} class="flex flex-col gap-4">
             <FormField label="現在のパスワード" id="password" error={form.errors.password}>
                 {#snippet children({ id, describedBy, invalid })}
                     <PasswordInput
diff --git a/resources/js/pages/Auth/ForgotPassword.svelte b/resources/js/pages/Auth/ForgotPassword.svelte
index cebbfcc..bbaea02 100644
--- a/resources/js/pages/Auth/ForgotPassword.svelte
+++ b/resources/js/pages/Auth/ForgotPassword.svelte
@@ -28,7 +28,7 @@
         ご登録のメールアドレスを入力してください。パスワードリセット用のリンクをお送りします。
     </p>
 
-    <form onsubmit={submit} class="flex flex-col gap-4">
+    <form novalidate onsubmit={submit} class="flex flex-col gap-4">
         <FormField label="メールアドレス" id="email" error={form.errors.email}>
             {#snippet children({ id, describedBy, invalid })}
                 <Input
diff --git a/resources/js/pages/Auth/Login.svelte b/resources/js/pages/Auth/Login.svelte
index 01e297c..9ff89fb 100644
--- a/resources/js/pages/Auth/Login.svelte
+++ b/resources/js/pages/Auth/Login.svelte
@@ -30,7 +30,7 @@
 </script>
 
 <AuthLayout title="ログイン" {appName}>
-    <form onsubmit={submit} class="flex flex-col gap-4">
+    <form novalidate onsubmit={submit} class="flex flex-col gap-4">
         <FormField label="メールアドレス" id="email" error={form.errors.email}>
             {#snippet children({ id, describedBy, invalid })}
                 <Input
diff --git a/resources/js/pages/Auth/Register.svelte b/resources/js/pages/Auth/Register.svelte
index 7ba3e84..aa88ad5 100644
--- a/resources/js/pages/Auth/Register.svelte
+++ b/resources/js/pages/Auth/Register.svelte
@@ -82,7 +82,7 @@
 </script>
 
 <AuthLayout title="アカウント登録" {appName}>
-    <form onsubmit={submit} class="flex flex-col gap-4">
+    <form novalidate onsubmit={submit} class="flex flex-col gap-4">
         <FormField label="名前" id="name" error={form.errors.name}>
             {#snippet children({ id, describedBy, invalid })}
                 <Input
diff --git a/resources/js/pages/Auth/ResetPassword.svelte b/resources/js/pages/Auth/ResetPassword.svelte
index 73cd424..5d41189 100644
--- a/resources/js/pages/Auth/ResetPassword.svelte
+++ b/resources/js/pages/Auth/ResetPassword.svelte
@@ -28,7 +28,7 @@
 </script>
 
 <AuthLayout title="パスワードリセット" {appName}>
-    <form onsubmit={submit} class="flex flex-col gap-4">
+    <form novalidate onsubmit={submit} class="flex flex-col gap-4">
         <FormField label="メールアドレス" id="email" error={form.errors.email}>
             {#snippet children({ id, describedBy, invalid })}
                 <Input
diff --git a/resources/js/pages/Auth/TwoFactorChallenge.svelte b/resources/js/pages/Auth/TwoFactorChallenge.svelte
index 3f36424..3c6d6c4 100644
--- a/resources/js/pages/Auth/TwoFactorChallenge.svelte
+++ b/resources/js/pages/Auth/TwoFactorChallenge.svelte
@@ -50,7 +50,7 @@
         testId="two-factor-tabs"
     />
 
-    <form onsubmit={submit} class="mt-6 flex flex-col gap-4">
+    <form novalidate onsubmit={submit} class="mt-6 flex flex-col gap-4">
         {#if mode === "recovery"}
             <div
                 id="two-factor-panel-recovery"
diff --git a/resources/js/pages/Auth/VerifyEmail.svelte b/resources/js/pages/Auth/VerifyEmail.svelte
index b3361b8..5a259a5 100644
--- a/resources/js/pages/Auth/VerifyEmail.svelte
+++ b/resources/js/pages/Auth/VerifyEmail.svelte
@@ -46,7 +46,7 @@
         メールが届かない場合は、再送信できます。
     </p>
 
-    <form onsubmit={resend} class="flex flex-col gap-3">
+    <form novalidate onsubmit={resend} class="flex flex-col gap-3">
         <Button type="submit" loading={form.processing} fullWidth>認証メールを再送信</Button>
         {#if continueUrl !== null}
             <Button
diff --git a/resources/js/pages/Capture/Index.svelte b/resources/js/pages/Capture/Index.svelte
index 2e0e941..3f27b52 100644
--- a/resources/js/pages/Capture/Index.svelte
+++ b/resources/js/pages/Capture/Index.svelte
@@ -58,6 +58,7 @@
         <PageContent>
             <div class="flex flex-col gap-2 sm:flex-row">
                 <form
+                    novalidate
                     class="flex min-w-0 flex-1 items-center gap-2"
                     onsubmit={(event) => {
                         event.preventDefault();
diff --git a/resources/js/pages/Contact/Index.svelte b/resources/js/pages/Contact/Index.svelte
index 013708b..033b967 100644
--- a/resources/js/pages/Contact/Index.svelte
+++ b/resources/js/pages/Contact/Index.svelte
@@ -97,7 +97,7 @@
         </p>
 
         <Card padding="lg" class="mt-6">
-            <form onsubmit={submit} class="flex flex-col gap-4" data-testid="contact-form">
+            <form novalidate onsubmit={submit} class="flex flex-col gap-4" data-testid="contact-form">
                 <FormField label="お問い合わせ種別" id="type" required error={form.errors.type}>
                     {#snippet children({ id, describedBy, invalid })}
                         <Select
diff --git a/resources/js/pages/Invitations/Accept.svelte b/resources/js/pages/Invitations/Accept.svelte
index 8529ea4..0d2fa6e 100644
--- a/resources/js/pages/Invitations/Accept.svelte
+++ b/resources/js/pages/Invitations/Accept.svelte
@@ -37,7 +37,7 @@
         />
         <PageContent>
             <Card padding="lg">
-                <form onsubmit={submit}>
+                <form novalidate onsubmit={submit}>
                     <Button type="submit" loading={form.processing} testId="accept-invitation-button">
                         招待を受諾する
                     </Button>
diff --git a/resources/js/pages/Manuals/Create.svelte b/resources/js/pages/Manuals/Create.svelte
index 0768c75..f300e46 100644
--- a/resources/js/pages/Manuals/Create.svelte
+++ b/resources/js/pages/Manuals/Create.svelte
@@ -59,7 +59,7 @@
         />
         <PageContent>
             <Card padding="lg">
-                <form onsubmit={submit} class="flex flex-col gap-4">
+                <form novalidate onsubmit={submit} class="flex flex-col gap-4">
                     <FormField label="タイトル" id="manual-title" error={form.errors.title} required>
                         {#snippet children({ id, describedBy, invalid })}
                             <Input
diff --git a/resources/js/pages/Manuals/Edit.svelte b/resources/js/pages/Manuals/Edit.svelte
index e756c33..eb084a4 100644
--- a/resources/js/pages/Manuals/Edit.svelte
+++ b/resources/js/pages/Manuals/Edit.svelte
@@ -80,7 +80,7 @@
             <div class="max-w-2xl">
             <Card padding="lg">
                 <h2 class="text-h3">基本情報</h2>
-                <form onsubmit={submit} class="mt-4 flex flex-col gap-4">
+                <form novalidate onsubmit={submit} class="mt-4 flex flex-col gap-4">
                     <FormField label="タイトル" id="manual-title" error={form.errors.title} required>
                         {#snippet children({ id, describedBy, invalid })}
                             <Input
diff --git a/resources/js/pages/Organizations/ApiKeys/Index.svelte b/resources/js/pages/Organizations/ApiKeys/Index.svelte
index 84dcfeb..b0cab5f 100644
--- a/resources/js/pages/Organizations/ApiKeys/Index.svelte
+++ b/resources/js/pages/Organizations/ApiKeys/Index.svelte
@@ -249,7 +249,7 @@
         </div>
 
         <Modal bind:open={issueModalOpen} title="API キーを発行" testId="issue-api-key-modal">
-            <form onsubmit={submitIssue} class="flex flex-col gap-4">
+            <form novalidate onsubmit={submitIssue} class="flex flex-col gap-4">
                 <FormField label="キー名" id="api-key-name" error={issueForm.errors.name}>
                     {#snippet children({ id, describedBy, invalid })}
                         <Input
diff --git a/resources/js/pages/Organizations/Create.svelte b/resources/js/pages/Organizations/Create.svelte
index 47d74d0..c2f9be5 100644
--- a/resources/js/pages/Organizations/Create.svelte
+++ b/resources/js/pages/Organizations/Create.svelte
@@ -32,7 +32,7 @@
         />
         <PageContent>
             <Card padding="lg">
-                <form onsubmit={submit} class="flex flex-col gap-4">
+                <form novalidate onsubmit={submit} class="flex flex-col gap-4">
                     <FormField label="組織名" id="organization-name" error={form.errors.name} required>
                         {#snippet children({ id, describedBy, invalid })}
                             <Input
diff --git a/resources/js/pages/Organizations/Settings.svelte b/resources/js/pages/Organizations/Settings.svelte
index 4e12c18..0fdf937 100644
--- a/resources/js/pages/Organizations/Settings.svelte
+++ b/resources/js/pages/Organizations/Settings.svelte
@@ -180,7 +180,7 @@
             <Card padding="lg">
                 <h2 class="text-h3">組織名</h2>
                 {#if canManage}
-                    <form onsubmit={submitName} class="mt-4 flex flex-col gap-4">
+                    <form novalidate onsubmit={submitName} class="mt-4 flex flex-col gap-4">
                         <FormField label="組織名" id="organization-name" error={nameForm.errors.name}>
                             {#snippet children({ id, describedBy, invalid })}
                                 <Input
@@ -286,7 +286,7 @@
                             {/if}
                         </p>
                     {/if}
-                    <form onsubmit={openTransferDialog} class="flex flex-col gap-4">
+                    <form novalidate onsubmit={openTransferDialog} class="flex flex-col gap-4">
                         <FormField
                             label="移譲先のメンバー"
                             id="transfer-target"
diff --git a/resources/js/pages/Projects/Create.svelte b/resources/js/pages/Projects/Create.svelte
index 75c8529..e943ae9 100644
--- a/resources/js/pages/Projects/Create.svelte
+++ b/resources/js/pages/Projects/Create.svelte
@@ -37,7 +37,7 @@
         />
         <PageContent>
             <Card padding="lg">
-                <form onsubmit={submit} class="flex flex-col gap-4">
+                <form novalidate onsubmit={submit} class="flex flex-col gap-4">
                     <FormField label="プロジェクト名" id="project-name" error={form.errors.name} required>
                         {#snippet children({ id, describedBy, invalid })}
                             <Input
diff --git a/resources/js/pages/Projects/Edit.svelte b/resources/js/pages/Projects/Edit.svelte
index 00f68c3..06b6bae 100644
--- a/resources/js/pages/Projects/Edit.svelte
+++ b/resources/js/pages/Projects/Edit.svelte
@@ -42,7 +42,7 @@
         />
         <PageContent>
             <Card padding="lg">
-                <form onsubmit={submit} class="flex flex-col gap-4">
+                <form novalidate onsubmit={submit} class="flex flex-col gap-4">
                     <FormField label="プロジェクト名" id="project-name" error={form.errors.name} required>
                         {#snippet children({ id, describedBy, invalid })}
                             <Input
diff --git a/resources/js/pages/Projects/Show.svelte b/resources/js/pages/Projects/Show.svelte
index bb7c474..84bce94 100644
--- a/resources/js/pages/Projects/Show.svelte
+++ b/resources/js/pages/Projects/Show.svelte
@@ -341,6 +341,7 @@
 
                 <!-- フィルタ (カテゴリ / 状態 / キーワード)。GET クエリで manuals のみ部分更新する -->
                 <form
+                    novalidate
                     onsubmit={applyManualFilters}
                     class="mt-4 flex flex-wrap items-end gap-3"
                     data-testid="manual-filter-form"
@@ -555,6 +556,7 @@
 
                     <!-- 追加フォーム -->
                     <form
+                        novalidate
                         onsubmit={submitAddMember}
                         class="mt-6 flex flex-col gap-4"
                         data-testid="project-member-add-form"
@@ -665,7 +667,7 @@
             {#if canManage}
                 <Card padding="lg">
                     <h2 class="text-h3">アイテムを追加</h2>
-                    <form onsubmit={submitAdd} class="mt-4 flex flex-col gap-4">
+                    <form novalidate onsubmit={submitAdd} class="mt-4 flex flex-col gap-4">
                         <FormField label="名前" id="item-name" error={addForm.errors.name} required>
                             {#snippet children({ id, describedBy, invalid })}
                                 <Input
@@ -717,7 +719,7 @@
             processing={editForm.processing}
             testId="edit-item-modal"
         >
-            <form onsubmit={submitEdit} class="flex flex-col gap-4">
+            <form novalidate onsubmit={submitEdit} class="flex flex-col gap-4">
                 <FormField label="名前" id="edit-item-name" error={editForm.errors.name} required>
                     {#snippet children({ id, describedBy, invalid })}
                         <Input
diff --git a/resources/js/pages/Settings/Index.svelte b/resources/js/pages/Settings/Index.svelte
index 32dffc2..45f0351 100644
--- a/resources/js/pages/Settings/Index.svelte
+++ b/resources/js/pages/Settings/Index.svelte
@@ -160,7 +160,7 @@
             <Card padding="lg">
                 <h2 class="text-h3">プロフィール</h2>
                 <p class="mt-1 text-caption text-text-secondary">名前とメールアドレスを更新します。</p>
-                <form onsubmit={submitProfile} class="mt-4 flex flex-col gap-4">
+                <form novalidate onsubmit={submitProfile} class="mt-4 flex flex-col gap-4">
                     <FormField label="名前" id="profile-name" error={profileForm.errors.name}>
                         {#snippet children({ id, describedBy, invalid })}
                             <Input
@@ -200,7 +200,7 @@
                 <p class="mt-1 text-caption text-text-secondary">
                     現在のパスワードを確認のうえ、新しいパスワードに変更します。
                 </p>
-                <form onsubmit={submitPassword} class="mt-4 flex flex-col gap-4">
+                <form novalidate onsubmit={submitPassword} class="mt-4 flex flex-col gap-4">
                     <FormField
                         label="現在のパスワード"
                         id="current-password"
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index cbc015b..96199b7 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -362,7 +362,7 @@
                                 {@html qrSvg}
                             </div>
                         {/if}
-                        <form onsubmit={confirmTwoFactor} class="flex flex-col gap-4">
+                        <form novalidate onsubmit={confirmTwoFactor} class="flex flex-col gap-4">
                             <FormField
                                 label="認証コード"
                                 id="two-factor-code"
diff --git a/tests/js/architecture/form-novalidate.test.ts b/tests/js/architecture/form-novalidate.test.ts
new file mode 100644
index 0000000..ccf49ac
--- /dev/null
+++ b/tests/js/architecture/form-novalidate.test.ts
@@ -0,0 +1,103 @@
+import { describe, expect, it } from "vitest";
+import fs from "node:fs";
+import path from "node:path";
+import { parse } from "svelte/compiler";
+
+/**
+ * resources/js 配下の全 <form> が novalidate を持つことを機械検証する。
+ *
+ * 検証 UX の正本はサーバ (日本語) + 押下時の client エラー (DESIGN.md §Do's and Don'ts)。
+ * native constraint validation は submit より先に発火し、ブラウザロケール依存の文言で
+ * 送信自体を止めるため、日本語の検証経路に到達できなくなる (bug-hunt F-3-02)。
+ *
+ * 判定は svelte/compiler の AST (modern) で行う。テキスト走査では <script> 内の文字列や
+ * コメント中の "<form" を誤検出するため。
+ *
+ * 例外を足したくなったら allowlist を作る前に、「なぜ日本語のエラー経路では足りないのか」を疑うこと。
+ */
+
+const JS_ROOT = path.resolve(__dirname, "../../../resources/js");
+
+function listSvelteFiles(dir: string): string[] {
+    if (!fs.existsSync(dir)) return [];
+    const files: string[] = [];
+    for (const entry of fs.readdirSync(dir, { withFileTypes: true, recursive: true })) {
+        if (!entry.isFile()) continue;
+        if (path.extname(entry.name) !== ".svelte") continue;
+        files.push(path.join(entry.parentPath, entry.name));
+    }
+    return files;
+}
+
+function relPath(file: string): string {
+    return path.relative(JS_ROOT, file);
+}
+
+interface AttributeNode {
+    type?: string;
+    name?: string;
+    value?: unknown;
+}
+
+/**
+ * source 文字列に対する検査 (ファイル I/O から分離 = 自己テスト可能にする)。
+ * `novalidate` は **静的な boolean shorthand のみ**を合格とする。
+ * `novalidate={false}` / `novalidate={cond}` は実行時に属性が消えうるため違反扱い
+ * (Svelte の AST では shorthand のときだけ `value === true` になる)。
+ */
+export function formViolationsInSource(source: string, label: string): string[] {
+    const ast = parse(source, { modern: true, filename: label });
+    const out: string[] = [];
+    const visit = (node: unknown): void => {
+        if (node === null || typeof node !== "object") return;
+        if (Array.isArray(node)) {
+            node.forEach(visit);
+            return;
+        }
+        const n = node as {
+            type?: string;
+            name?: string;
+            start?: number;
+            attributes?: AttributeNode[];
+        };
+        if (n.type === "RegularElement" && n.name === "form") {
+            const hasNoValidate = (n.attributes ?? []).some(
+                (a) => a.type === "Attribute" && a.name === "novalidate" && a.value === true,
+            );
+            if (!hasNoValidate) {
+                const line = source.slice(0, n.start ?? 0).split("\n").length;
+                out.push(`${label}:${line}`);
+            }
+        }
+        for (const [key, value] of Object.entries(n)) {
+            if (key === "parent") continue; // 循環参照を踏まない
+            visit(value);
+        }
+    };
+    visit((ast as unknown as { fragment: unknown }).fragment);
+    return out;
+}
+
+const svelteFiles = listSvelteFiles(JS_ROOT);
+
+describe("form validation policy", () => {
+    it("resources/js の全 <form> が novalidate を持つ (native validation に依存しない)", () => {
+        const violations = svelteFiles.flatMap((file) =>
+            formViolationsInSource(fs.readFileSync(file, "utf-8"), relPath(file)),
+        );
+        expect(violations).toEqual([]);
+    });
+
+    // 検出器そのものの自己テスト (偽陰性を作らないことを固定する)
+    it.each([
+        ["<form novalidate></form>", 0],
+        ["<form></form>", 1],
+        ["<form novalidate={false}></form>", 1],
+        ["<form novalidate={cond}></form>", 1],
+        ['<script>const s = "<form>";</script><form novalidate></form>', 0],
+    ])("検出器: %s → 違反 %i 件", (source, expected) => {
+        expect(formViolationsInSource(source as string, "inline.svelte")).toHaveLength(
+            expected as number,
+        );
+    });
+});
diff --git a/tests/js/components/atoms/Input.test.ts b/tests/js/components/atoms/Input.test.ts
index 6f97e0b..df110ad 100644
--- a/tests/js/components/atoms/Input.test.ts
+++ b/tests/js/components/atoms/Input.test.ts
@@ -22,4 +22,49 @@ describe("Input", () => {
 
         expect(screen.getByTestId("input")).toHaveAttribute("aria-describedby", "name-error");
     });
+
+    // 編集不可は「面」で示す (DESIGN.md §Input / Textarea / Select)。bug-hunt F-3-03 の根治。
+    it("readonly で native 属性と muted な面が付く", () => {
+        render(Input, { props: { readonly: true, testId: "input" } });
+
+        const input = screen.getByTestId("input");
+        expect(input).toHaveAttribute("readonly");
+        // token 単位で見る (disabled:bg-neutral 等のバリアントを substring で拾わないため)
+        const tokens = input.className.split(/\s+/);
+        expect(tokens).toContain("bg-neutral");
+        expect(tokens).toContain("cursor-default");
+        expect(tokens).not.toContain("bg-surface");
+    });
+
+    it("readonly でも文字色は落とさない (disabled と意味が違う)", () => {
+        render(Input, { props: { readonly: true, testId: "input" } });
+
+        const input = screen.getByTestId("input");
+        // class token 単位で見る (text-text-secondary は disabled: バリアント側にしか無いこと)
+        const tokens = input.className.split(/\s+/);
+        expect(tokens).toContain("text-text");
+        expect(tokens).not.toContain("text-text-secondary");
+        expect(tokens).not.toContain("cursor-not-allowed");
+        // フォーカス可能なまま (値の選択・コピーができる)
+        expect(input).not.toBeDisabled();
+    });
+
+    it("readonly 既定 (false) では通常の面のまま", () => {
+        render(Input, { props: { testId: "input" } });
+
+        const input = screen.getByTestId("input");
+        expect(input).not.toHaveAttribute("readonly");
+        const tokens = input.className.split(/\s+/);
+        expect(tokens).toContain("bg-surface");
+        expect(tokens).not.toContain("bg-neutral");
+        expect(tokens).not.toContain("cursor-default");
+    });
+
+    it("readonly + error では danger border と muted 面が両立する", () => {
+        render(Input, { props: { readonly: true, error: true, testId: "input" } });
+
+        const tokens = screen.getByTestId("input").className.split(/\s+/);
+        expect(tokens).toContain("border-danger");
+        expect(tokens).toContain("bg-neutral");
+    });
 });
diff --git a/tests/js/components/atoms/Textarea.test.ts b/tests/js/components/atoms/Textarea.test.ts
index f6ce2a5..19246bb 100644
--- a/tests/js/components/atoms/Textarea.test.ts
+++ b/tests/js/components/atoms/Textarea.test.ts
@@ -41,6 +41,18 @@ describe("Textarea", () => {
         expect(textarea.className).toContain("border-border-strong");
     });
 
+    // 入力系 atom 横断で同じ規約が成立していることの固定 (DESIGN.md §Input / Textarea / Select)
+    it("readonly で native 属性と muted な面が付く (Input と同じ規約)", () => {
+        render(Textarea, { props: { readonly: true, testId: "ta" } });
+
+        const textarea = screen.getByTestId("ta");
+        expect(textarea).toHaveAttribute("readonly");
+        const tokens = textarea.className.split(/\s+/);
+        expect(tokens).toContain("bg-neutral");
+        expect(tokens).toContain("cursor-default");
+        expect(tokens).toContain("text-text");
+    });
+
     it("disabled と aria-describedby (restProps) を透過する", () => {
         render(Textarea, {
             props: { disabled: true, "aria-describedby": "memo-error", testId: "ta" },
diff --git a/tests/js/components/features/billing/AutoRechargeCard.test.ts b/tests/js/components/features/billing/AutoRechargeCard.test.ts
index 3b2306b..a29aac5 100644
--- a/tests/js/components/features/billing/AutoRechargeCard.test.ts
+++ b/tests/js/components/features/billing/AutoRechargeCard.test.ts
@@ -124,4 +124,67 @@ describe("AutoRechargeCard", () => {
         // エラー時は同意パネルを開かない
         expect(screen.queryByTestId("auto-recharge-consent")).toBeNull();
     });
+
+    it("押下前は範囲エラーを出さない (禁止事項 #8 の契約: 押下時に初めて提示する)", async () => {
+        renderCard({ hasPaymentMethod: true });
+
+        await fireEvent.input(screen.getByTestId("auto-recharge-max-input"), {
+            target: { value: "0" },
+        });
+
+        expect(screen.queryByTestId("auto-recharge-range-error")).toBeNull();
+    });
+
+    it("押下後に値を有効へ直すと範囲エラーが消える (F-3-05: stale invalid を残さない)", async () => {
+        renderCard({ hasPaymentMethod: true });
+
+        const maxInput = screen.getByTestId("auto-recharge-max-input");
+        await fireEvent.input(maxInput, { target: { value: "0" } });
+        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
+        expect(screen.getByTestId("auto-recharge-range-error")).not.toBeNull();
+
+        // 値を有効な組み合わせへ直す → 表示中のエラーは現在の入力に追随して消える
+        await fireEvent.input(maxInput, { target: { value: "50" } });
+        expect(screen.queryByTestId("auto-recharge-range-error")).toBeNull();
+    });
+
+    it("無効のまま別の無効理由に変えると文言が現在の理由へ追随する", async () => {
+        renderCard({ hasPaymentMethod: true });
+
+        const maxInput = screen.getByTestId("auto-recharge-max-input");
+        // 範囲外 (minCount 未満)
+        await fireEvent.input(maxInput, { target: { value: "0" } });
+        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
+        expect(screen.getByTestId("auto-recharge-range-error").textContent).toContain(
+            "リチャージ後の残高は",
+        );
+
+        // 開始残高 (既定 5) 以下 = 大小関係の違反へ理由が変わる
+        await fireEvent.input(maxInput, { target: { value: "5" } });
+        expect(screen.getByTestId("auto-recharge-range-error").textContent).toContain(
+            "開始残高より大きい値",
+        );
+    });
+
+    it("canManage=false では両入力が readonly かつ muted になる (F-3-03)", () => {
+        renderCard({ canManage: false, hasPaymentMethod: true });
+
+        for (const testId of ["auto-recharge-threshold-input", "auto-recharge-max-input"]) {
+            const input = screen.getByTestId(testId);
+            expect(input).toHaveAttribute("readonly");
+            const tokens = input.className.split(/\s+/);
+            expect(tokens).toContain("bg-neutral");
+            expect(tokens).toContain("cursor-default");
+        }
+    });
+
+    it("canManage=true では入力は readonly でない (非退行)", () => {
+        renderCard({ canManage: true, hasPaymentMethod: true });
+
+        for (const testId of ["auto-recharge-threshold-input", "auto-recharge-max-input"]) {
+            const input = screen.getByTestId(testId);
+            expect(input).not.toHaveAttribute("readonly");
+            expect(input.className.split(/\s+/)).toContain("bg-surface");
+        }
+    });
 });
diff --git a/tests/js/pages/Billing/BillingContactForm.test.ts b/tests/js/pages/Billing/BillingContactForm.test.ts
index 2e005d2..6047a94 100644
--- a/tests/js/pages/Billing/BillingContactForm.test.ts
+++ b/tests/js/pages/Billing/BillingContactForm.test.ts
@@ -49,6 +49,47 @@ describe("BillingContactForm", () => {
         expect(payload).toEqual({ billing_contact_email: "", billing_contact_name: "" });
     });
 
+    /*
+     * F-3-02: native constraint validation に検証を奪われない (DESIGN.md §Do's and Don'ts)。
+     * jsdom + fireEvent は submit を直接発火するため native のブロック自体は再現できない。
+     * よって behavior ではなく `novalidate` 属性という構造で固定する
+     * (全 form の網羅は tests/js/architecture/form-novalidate.test.ts が担う)。
+     */
+    it("form は novalidate を持つ (検証はサーバ日本語文言に一本化する)", () => {
+        render(BillingContactForm, {
+            props: { billingContact: baseContact, updateUrl: "/billing/contact", canManage: true },
+        });
+
+        const form = screen.getByTestId("billing-contact-form") as HTMLFormElement;
+        expect(form.noValidate).toBe(true);
+    });
+
+    it("email 入力は type=email のまま (モバイルキーボード等の入力補助を落とさない)", () => {
+        render(BillingContactForm, {
+            props: { billingContact: baseContact, updateUrl: "/billing/contact", canManage: true },
+        });
+
+        expect(screen.getByTestId("billing-contact-email-input")).toHaveAttribute("type", "email");
+    });
+
+    it("不正な形式の email でも submit で PATCH が飛ぶ (native にブロックされない)", async () => {
+        render(BillingContactForm, {
+            props: { billingContact: baseContact, updateUrl: "/billing/contact", canManage: true },
+        });
+
+        await fireEvent.input(screen.getByTestId("billing-contact-email-input"), {
+            target: { value: "not-an-email" },
+        });
+        await fireEvent.click(screen.getByTestId("billing-contact-submit"));
+
+        expect(routerPatchMock).toHaveBeenCalledTimes(1);
+        const [, payload] = routerPatchMock.mock.calls[0] as [string, Record<string, unknown>];
+        expect(payload).toEqual({
+            billing_contact_email: "not-an-email",
+            billing_contact_name: "",
+        });
+    });
+
     it("サーバ 422 の errors.billing_contact_email を表示する", () => {
         pageState.props = {
             errors: { billing_contact_email: "請求先メールアドレスは、有効なメールアドレス形式で指定してください。" },

```

---

## テスト結果

- `pnpm test`: **99 files / 913 tests passed, 0 failed** (新規 architecture テスト 1 ファイル含む)
- `pnpm typecheck` (tsc --noEmit): OK
- `pnpm lint` (eslint resources/js): OK
- `pnpm build` (vite build): OK
- `vendor/bin/pint --test`: passed
- `composer phpstan` (level 10): No errors
- `composer test` (Pest): 実行中 (PHP 無変更のため既存 green を維持する想定)

### test-first の red 確認記録 (実装前)

- `tests/js/architecture/form-novalidate.test.ts`: 違反 **33 件** で red (設計の対象リスト 33 form と完全一致)。検出器の自己テスト 5 ケースは実装前から green
- `Input.test.ts` / `Textarea.test.ts` / `AutoRechargeCard.test.ts` / `BillingContactForm.test.ts`: 新規 8 ケースが red
  - 特に `不正な形式の email でも submit で PATCH が飛ぶ` は jsdom の constraint validation により実装前 red → novalidate 付与で green (native ブロックを実際に踏んでいたことの裏取りになった)
