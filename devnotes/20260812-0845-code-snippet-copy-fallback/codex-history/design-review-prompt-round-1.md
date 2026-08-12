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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

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
PHP 8.4 + Laravel 13.18 + Svelte 5 (runes) + Inertia.js + TypeScript / PHPStan level 10 /
Pest / Vitest + @testing-library/svelte (jsdom)

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性 3. 型安全性 4. テスト計画の網羅性
5. 副作用・後退リスク 6. 波及変更の網羅性
7. セキュリティ 8. DESIGN.md 準拠 (token 経由か、hex 直書きを増やしていないか)
9. Atomic Design 準拠 (molecule の責務を超えていないか)

【この設計に固有の、特に厳しく見てほしい点】
- 状態を boolean 2 本から 4 値 enum にする判断は妥当か。過剰ではないか。
- 失敗時に `removeAllRanges()` でページ内の既存選択を奪う副作用は許容できるか。
- 「component 破棄時の解除には追加コードを書かない (DOM ごと消えるため)」という判断は正しいか。
  Svelte 5 の破棄順序で、選択範囲が残るケースは無いか。
- mutation 計画 M1〜M6 は、それぞれ本当にその本数だけを赤くできるか。
- jsdom の Selection / Range 実装差でテストが偽陽性・偽陰性になる危険は無いか。
- 案内文の日本語は、実際にやったことを正確に述べているか (誇張・嘘が無いか)。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: code-snippet-copy-fallback

## 使命・制約(絶対遵守)

### アプリの使命(North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告 2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作 4. `response()->json()` の直書き 5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き 7. 操作系 POST の `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI** 9. Artifact の使用

### コーディングルール

- フロントは Svelte 5 runes + DS token/ramp のみ(`DESIGN.md` が canonical、ds-purity テストが検出)
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の
  単方向 import のみ。アイコンは `@lucide/svelte` のみ
- TypeScript(JS 禁止)。`pnpm lint` / `pnpm typecheck` / `pnpm test` が green
- 既存テストの削除・上書き禁止(**期待値の更新は可**。意図的な挙動変更として理由を残す)

## 概念設計リファレンス

- `devnotes/20260812-0845-code-snippet-copy-fallback/conceptual-design.md`(Round 1 APPROVED)
- 合議履歴: `conceptual-review-round-1.md` / `codex-history/conceptual-review-decisions-round-1.md`

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 失敗経路を段階化(選択 → legacy fallback → 案内)し、状態を 4 値の enum へ一本化 | `resources/js/components/molecules/CodeSnippet.svelte` | High |
| 2 | 契約をテストで固定(既存 2 本の期待値更新 + 新規 5 本) | `tests/js/components/molecules/CodeSnippet.test.ts` | High |

**サーバ側の変更は 0 件**。props インターフェース(`code` / `language` / `testId` / `class`)も
不変で、**呼び出し 7 箇所は 1 行も変えない**。

`navigator.clipboard` を直接呼ぶ箇所は**リポジトリ全体で `CodeSnippet.svelte` の 1 つだけ**
(`grep -rn "navigator.clipboard" resources/js` で実査済み。他は 0 件)。よって本 component 内で
閉じれば、アプリのコピー UI 全体が一度に直る。

---

## 施策 1: 失敗経路の段階化と状態の一本化

### 変更箇所

- ファイル: `resources/js/components/molecules/CodeSnippet.svelte`(全体で ~40 行の変更)

### 波及変更

- TypeScript 型定義: **なし**(props 不変)
- API Resource/DTO: **なし**(サーバ応答に関係しない)
- テストファイル: 施策 2

### 現行コード(要点)

```ts
let copied = $state(false);
let failed = $state(false);

async function copy(): Promise<void> {
    if (timeoutId) clearTimeout(timeoutId);
    try {
        if (!navigator.clipboard?.writeText) throw new Error("clipboard unavailable");
        await navigator.clipboard.writeText(code);
        copied = true; failed = false;
    } catch {
        copied = false; failed = true;
    }
    timeoutId = setTimeout(() => { copied = false; failed = false; }, 2000);
}
```

```svelte
{#if copied}
    <span role="status" class="text-caption text-success">コピー完了</span>
{:else if failed}
    <span role="status" class="text-caption text-danger">コピー失敗</span>
{/if}
```

### 変更後コード

```ts
/**
 * 表示状態。**boolean 2 本ではなく 4 値の enum で持つ**
 * (「成功でも失敗でもない」「失敗したが選択はできた」「選択もできなかった」を
 *  取り違えないため。組み合わせで表現すると不能な状態が作れてしまう)。
 */
type CopyStatus = "idle" | "copied" | "manual-selected" | "manual-unselected";

let status = $state<CopyStatus>("idle");
let codeEl = $state<HTMLElement | null>(null);
let timeoutId: ReturnType<typeof setTimeout> | undefined;

/**
 * コピー。段階は次のとおりで、**主役は 2 の「選択を残すこと」**である
 * (3 は選択のついでに試す legacy fallback にすぎない)。
 * 1. Clipboard API → 通れば成功
 * 2. コード文字列を選択状態にする (手動コピーへ進める状態を手元に残す)
 * 3. その選択で document.execCommand("copy") を試す (非推奨 API。通れば成功)
 * 4. 通らなければ案内を出す。**この案内は自動では消さない** (手動コピーには時間が要る)
 */
async function copy(): Promise<void> {
    if (timeoutId) clearTimeout(timeoutId);
    // 前回の案内は必ず解除する (古い失敗案内が成功後に残ってはならない)
    status = "idle";

    if (await writeViaClipboardApi()) {
        markCopied();

        return;
    }

    const selected = selectCode();
    if (selected && tryLegacyCopy()) {
        markCopied();

        return;
    }

    status = selected ? "manual-selected" : "manual-unselected";
}

/** Clipboard API での書き込み。非対応環境・拒否・フォーカス喪失はすべて false */
async function writeViaClipboardApi(): Promise<boolean> {
    try {
        if (!navigator.clipboard?.writeText) return false;
        await navigator.clipboard.writeText(code);

        return true;
    } catch {
        return false;
    }
}

/** コード文字列を選択状態にする (成否を返す)。**これが本命の受け皿** */
function selectCode(): boolean {
    const selection = window.getSelection();
    if (codeEl === null || selection === null) return false;
    try {
        const range = document.createRange();
        range.selectNodeContents(codeEl);
        selection.removeAllRanges();
        selection.addRange(range);

        return true;
    } catch {
        return false;
    }
}

/**
 * 非推奨 API による最後の試行。**主役ではない** —
 * 選択状態をどのみち作るので、そのついでに試すだけの補助経路である。
 * これが通っても「Clipboard API の代替が保証された」わけではない
 * (execCommand も document のフォーカスを要求するため、フォーカス起因なら同じく失敗する)。
 */
function tryLegacyCopy(): boolean {
    if (typeof document.execCommand !== "function") return false;
    try {
        return document.execCommand("copy");
    } catch {
        return false;
    }
}

/** 成功表示 (2 秒で消える。一過性の確認なので消えてよい) */
function markCopied(): void {
    status = "copied";
    timeoutId = setTimeout(() => {
        status = "idle";
    }, 2000);
}
```

markup:

```svelte
<pre …><code bind:this={codeEl}>{code}</code></pre>
<div class="absolute top-2 right-2 flex items-center gap-2">
    {#if status === "copied"}
        <span role="status" class="text-caption text-success">コピー完了</span>
    {/if}
    <Button variant="neutral" size="sm" onclick={copy} testId={testId ? `${testId}-copy` : undefined}>
        コピー
    </Button>
</div>
{#if status === "manual-selected" || status === "manual-unselected"}
    <!-- 案内はブロック下部の通常フローに出す (ボタン横の absolute 領域に長文を置くと破綻する)。
         文面は**実際にやったことをそのまま言う** = 選択できたときだけ「選択した」と書く。 -->
    <p
        role="status"
        class="mt-2 text-caption text-danger"
        data-testid={testId ? `${testId}-manual-copy` : undefined}
    >
        {status === "manual-selected"
            ? "コピーできませんでした。上のテキストを選択したので、⌘C / Ctrl+C (スマートフォンでは長押し) でコピーしてください。"
            : "コピーできませんでした。上のテキストを選択して手動でコピーしてください。"}
    </p>
{/if}
```

### 設計判断

- **状態は 4 値 enum**。`copied` / `failed` の boolean 2 本だと「両方 true」という
  不能な状態が型の上で作れてしまう。加えて今回は失敗が 2 種類(選択できた / できなかった)に
  分かれるため、boolean を 3 本に増やすより enum 1 本が正しい。
- **文面は実際にやったことをそのまま言う**。選択に失敗したのに「選択したので」と書くのは
  嘘になる(aicue:T148 の「告知文は述語の意味をそのまま言う」と同じ原則)。だから 2 文用意する。
- **成功は 2 秒で消し、案内は消さない**。成功表示は一過性の確認、案内は作業指示であり、
  読んで実行する時間が要る。**これは正しい非対称**である。
- **案内の解除は 3 つ**: 次のコピー試行(`copy()` 冒頭で `idle` へ)/ 成功(`markCopied`)/
  component 破棄。破棄については**追加コードを書かない** — 案内も選択範囲も破棄される DOM に
  紐づいており、ノードが消えれば選択も畳まれるためである(既存の `onDestroy` は
  タイマー解除のみを続ける)。
- **`aria-live` は足さない**。`role="status"` が暗黙に `aria-live="polite"` を持つため
  (既存 span と同じ流儀)。
- **ボタンは disabled にしない**(禁止事項 8)。押下してから結果を示す現行の形を保つ。
- **色は DS token のみ**(`text-danger` / `text-caption`)。hex 直書きを増やさない。

### テスト計画

施策 2 で固定する。

### リスク

- **`execCommand` は非推奨で、将来ブラウザから消えうる**。消えても `typeof` 検査で
  false を返して案内段へ落ちるだけなので、**壊れ方が安全側**である。
- **選択を奪う副作用**。失敗時にページ内の既存選択を `removeAllRanges()` で消す。
  これはコピー操作の直接の結果として利用者が期待する挙動であり、失敗時にしか起きない。
- 既存の「コピー失敗」という**文言は消える**。これは意図した挙動変更で、施策 2 で
  既存テスト 2 本の期待値を更新する(削除はしない)。

---

## 施策 2: 契約をテストで固定

### 変更箇所

- ファイル: `tests/js/components/molecules/CodeSnippet.test.ts`

### 既存テストの扱い(削除しない)

| 既存テスト | 扱い |
|---|---|
| `code を <pre><code> に描画し data-language を付ける` | **無変更**(回帰確認) |
| `コピー成功でクリップボードに書き込み「コピー完了」を表示する` | **無変更**(成功経路は変えない) |
| `コピー完了表示は 2 秒後に消える` | **無変更**(成功表示の一過性は維持) |
| `コピー失敗で「コピー失敗」を表示する` | **期待値を更新**(新契約 = 選択 + 案内)。名前も実態に合わせる |
| `clipboard API 非対応環境では「コピー失敗」を表示する` | **期待値を更新**(同上) |

### 新規/更新テストの契約

```ts
/** document.execCommand を差し替える (jsdom は未実装 = undefined) */
function stubExecCommand(result: boolean | (() => boolean)): ReturnType<typeof vi.fn> {
    const spy = vi.fn(typeof result === "function" ? result : () => result);
    Object.defineProperty(document, "execCommand", { value: spy, configurable: true });

    return spy;
}
```

1. **Clipboard 失敗 → 選択が張られ、legacy fallback が試される → 成功なら「コピー完了」**
   - `execCommand` spy が `"copy"` で呼ばれたこと、表示が「コピー完了」であること、
     **案内が出ていないこと**を見る。
2. **Clipboard 失敗 + `execCommand` が false → 案内が出て、選択は残る**
   - `${testId}-manual-copy` の文言に「選択したので」が含まれること。
   - `window.getSelection()` の range が `<code>` の内容を指していること
     (jsdom の Selection 実装差を踏まえ、**`rangeCount` と `range.toString()`** で見る。
     `selection.toString()` が jsdom で空になる場合があるため実装時に実測して確定する)。
3. **案内は 2 秒経っても消えない**(成功表示との非対称の固定。fake timer で 2100ms 進める)
4. **clipboard API 非対応(`navigator.clipboard` undefined)でも同じ段階を通る**
5. **`document.execCommand` が未定義でも例外を投げず案内へ落ちる**
   (`typeof` 検査の固定。`delete` して呼ぶ)
6. **失敗案内が出た後に成功すると案内が消える**(Codex Round 1 [Warning] の解除条件)
7. **選択できなかった場合は「選択したので」と書かない**
   (`window.getSelection` を null 返しに差し替え → `manual-unselected` の文面になる)

### fail 先行の確認手順

1. 施策 2 のテストだけを先に置いて `pnpm test` を実行し、**新規 7 本が落ちる**ことを確認する
   (更新した 2 本も新契約では落ちる)。
2. 施策 1 を実装して緑にする。

### mutation 計画

| # | mutation | 予測 |
|---|---|---|
| M1 | `selectCode()` を常に `false` を返すよう変更 | 2/7 が赤(選択の契約と文面の出し分け) |
| M2 | `tryLegacyCopy()` の `typeof` 検査を外す | 5 が赤(未定義環境で TypeError) |
| M3 | `copy()` 冒頭の `status = "idle"` を削除 | 6 が赤(古い案内が残る) |
| M4 | 案内にも 2 秒タイマーを付ける | 3 が赤(消えてはいけない案内が消える) |
| M5 | `manual-unselected` の文面を `manual-selected` と同一にする | 7 が赤(嘘の告知) |
| M6 | `execCommand` の戻り値を無視して常に成功扱いにする | 2 が赤(false なのに成功表示) |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 1 component + そのテストで閉じる。サーバ側 0 行、props 不変、呼び出し側無変更 |
| 競合リスク | `CodeSnippet.svelte` を触る他 TODO は現在なし |

## 検証コマンド

`pnpm test` / `pnpm lint` / `pnpm typecheck` / `pnpm build` / `composer test` /
`composer phpstan` / `vendor/bin/pint --test` / packages 3 種。

## 保証しないもの(誇張しない)

- **bug-hunt が観測した失敗の原因は特定していない**。本 TODO は失敗時の受け皿を作るだけで、
  「もう失敗しなくなる」とは言わない。
- **`execCommand` が成功することを保証しない**。フォーカス起因なら同じく失敗する。
  確実に効くのは最終段(選択 + 案内)だけである。
- **Vitest は DOM 契約と分岐だけを見る**。実ブラウザで実際にクリップボードへ入ること、
  iOS Safari で選択範囲からコピーメニューへ到達できることは**確認しない**。
  完了条件は Vitest の DOM 契約までとする。実機 / Browser lane で見るなら観点は
  (a) 実ブラウザで失敗時に選択範囲が実際に張られるか (b) iOS Safari のコピーメニュー到達
  (c) `execCommand` が通る環境の広さ の 3 つ。
- **モバイルでワンタップコピーができるようにはならない**。


---

## 関連する現行コード

### resources/js/components/molecules/CodeSnippet.svelte (全文)

```svelte
<script lang="ts">
    import { onDestroy } from "svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * コピー付きコードブロック molecule (API キー・リカバリコード・CLI コマンド等の表示用)。
     *
     * コピー処理は component 内に内包する (navigator.clipboard 直呼び。
     * 非対応環境・拒否時は「コピー失敗」を表示して手動コピーを促す)。
     * `<pre>` は中間 box なので rounded-md、コードは font-mono (DESIGN.md §Shapes / §Typography)。
     */
    interface Props {
        code: string;
        language?: string;
        testId?: string;
        class?: string;
    }

    let { code, language = "plaintext", testId, class: extraClass = "" }: Props = $props();

    let copied = $state(false);
    let failed = $state(false);
    let timeoutId: ReturnType<typeof setTimeout> | undefined;

    async function copy(): Promise<void> {
        if (timeoutId) clearTimeout(timeoutId);
        try {
            // clipboard 非対応環境 (insecure context 等) は writeText が無いため失敗扱い
            if (!navigator.clipboard?.writeText) {
                throw new Error("clipboard unavailable");
            }
            await navigator.clipboard.writeText(code);
            copied = true;
            failed = false;
        } catch {
            copied = false;
            failed = true;
        }
        timeoutId = setTimeout(() => {
            copied = false;
            failed = false;
        }, 2000);
    }

    onDestroy(() => {
        if (timeoutId) clearTimeout(timeoutId);
    });
</script>

<div class={["relative", extraClass].filter(Boolean).join(" ")} data-testid={testId}>
    <!-- pr-24 でコピー UI 分の余白を確保する -->
    <pre
        data-testid={testId ? `${testId}-body` : undefined}
        data-language={language}
        class="overflow-x-auto rounded-md border border-border bg-neutral p-4 pr-24 text-caption font-mono text-text"><code
        >{code}</code></pre>
    <div class="absolute top-2 right-2 flex items-center gap-2">
        {#if copied}
            <span role="status" class="text-caption text-success">コピー完了</span>
        {:else if failed}
            <span role="status" class="text-caption text-danger">コピー失敗</span>
        {/if}
        <Button
            variant="neutral"
            size="sm"
            onclick={copy}
            testId={testId ? `${testId}-copy` : undefined}
        >
            コピー
        </Button>
    </div>
</div>
```

### tests/js/components/molecules/CodeSnippet.test.ts (全文)

```ts
import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/svelte";
import CodeSnippet from "@/components/molecules/CodeSnippet.svelte";

/** navigator.clipboard を書き換え可能な形で差し込む (jsdom 既定では未定義) */
function stubClipboard(writeText: (text: string) => Promise<void>): void {
    Object.defineProperty(window.navigator, "clipboard", {
        value: { writeText },
        configurable: true,
    });
}

function removeClipboard(): void {
    Object.defineProperty(window.navigator, "clipboard", {
        value: undefined,
        configurable: true,
    });
}

afterEach(() => {
    removeClipboard();
    vi.useRealTimers();
});

describe("CodeSnippet", () => {
    it("code を <pre><code> に描画し data-language を付ける", () => {
        render(CodeSnippet, {
            props: { code: "php artisan migrate", language: "shell", testId: "snippet" },
        });

        const pre = screen.getByTestId("snippet-body");
        expect(pre.tagName).toBe("PRE");
        expect(pre).toHaveAttribute("data-language", "shell");
        expect(pre).toHaveTextContent("php artisan migrate");
        expect(pre.className).toContain("font-mono");
    });

    it("コピー成功でクリップボードに書き込み「コピー完了」を表示する", async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        stubClipboard(writeText);
        render(CodeSnippet, { props: { code: "secret-token", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));

        expect(writeText).toHaveBeenCalledWith("secret-token");
        expect(await screen.findByText("コピー完了")).toBeInTheDocument();
    });

    it("コピー完了表示は 2 秒後に消える", async () => {
        // setTimeout の登録前に fake timer 化する (登録後だと advance が効かない)
        vi.useFakeTimers();
        stubClipboard(vi.fn().mockResolvedValue(undefined));
        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));
        // clipboard resolve (microtask) 後の再描画を flush する
        await act(async () => {
            await Promise.resolve();
        });
        expect(screen.getByText("コピー完了")).toBeInTheDocument();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(2100);
        });
        expect(screen.queryByText("コピー完了")).toBeNull();
    });

    it("コピー失敗で「コピー失敗」を表示する", async () => {
        stubClipboard(vi.fn().mockRejectedValue(new Error("denied")));
        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));

        expect(await screen.findByText("コピー失敗")).toBeInTheDocument();
    });

    it("clipboard API 非対応環境では「コピー失敗」を表示する", async () => {
        removeClipboard();
        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));

        expect(await screen.findByText("コピー失敗")).toBeInTheDocument();
    });
});
```
