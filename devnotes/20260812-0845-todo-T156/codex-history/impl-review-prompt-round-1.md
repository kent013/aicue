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

あなたは経験豊富なコードレビュアーです。Laravel + Svelte アプリケーションの改善実装をレビューしてください。

【レビュー観点】
1. 詳細設計との一致性 2. コードの正確性 (エッジケース・null 安全性)
3. 型安全性 4. テストが**実際に退行を検出できるか** (mutation の実測結果を添付)
5. 副作用・後退リスク 6. セキュリティ
7. DESIGN.md 準拠 (token 経由か、hex 直書きを増やしていないか)
8. Atomic Design 準拠 (molecule の責務、Lucide 以外の SVG 直書きが無いか)

【特に見てほしい点】
- 設計からの乖離 3 点 (下記) の判断は妥当か
- mutation で予測が外れた 1 件への対処 (契約の補強) は正しいか。テストが実装詳細に
  寄りすぎていないか
- jsdom の live range 挙動に依存した契約が、実ブラウザの契約として誤っていないか

【出力形式】
- ファイルごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書 (APPROVED 済み)

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
| 2 | 契約をテストで固定(契約 16 件 = 既存 2 本の期待値更新 + 新規 14 本) | `tests/js/components/molecules/CodeSnippet.test.ts` | High |

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
 * この component が張った Range の複製。**「code の中か」ではなく「自分が作った Range と
 * 完全一致か」で所有を判定する** (利用者が同じ code 内を部分選択し直した場合に、
 * その選択を奪わないため)。
 */
let ownedRange: Range | null = null;
/** 連打・遅延解決の競合よけ。await 後に最新試行でなければ状態を触らない */
let attemptId = 0;

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
    const attempt = ++attemptId;
    // 前回の試行の痕跡を両方消す: 所有 Selection と表示状態
    // (不変条件「選択が残っているのは手動コピーを促しているときだけ」を再試行中も保つ)
    clearOwnSelection();
    status = "idle";

    const written = await writeViaClipboardApi();
    // 遅延解決した古い試行は、新しい試行の結果を上書きしない
    if (attempt !== attemptId) return;

    if (written) {
        markCopied();

        return;
    }

    const selected = selectCode();
    if (selected && tryLegacyCopy()) {
        // コピーできた以上、手動コピー用の選択は不要 = 自分が張った選択だけ畳む
        clearOwnSelection();
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
    if (codeEl === null) return false;
    const selection = window.getSelection();
    if (selection === null) return false;

    // range の構築と**所有記録用の複製まで**を、既存選択に触る前に済ませる
    // (ここで失敗しても利用者の選択を壊さない / 選択を張った後に cloneRange が投げて
    //  「所有していない選択だけが残る」状態を作らない)
    let range: Range;
    let owned: Range;
    try {
        range = document.createRange();
        range.selectNodeContents(codeEl);
        owned = range.cloneRange();
    } catch {
        return false;
    }

    try {
        selection.removeAllRanges();
        selection.addRange(range);
    } catch {
        // ここで投げると**既存選択だけが失われる**。稀だが起こりうる残留リスクとして明記する
        return false;
    }
    ownedRange = owned;

    return true;
}

/**
 * **この component が張った選択だけ**を畳む。利用者がその後に選択し直していたら触らない
 * (別要素でも、**同じ code 内の部分選択でも**奪わない = Range の境界 4 点で完全一致を見る)。
 * 再試行の冒頭 / legacy コピー成功時 / component 破棄時に呼ぶ。
 * **例外を投げない** (これは後処理であり、コピー成功を覆してはならない)。
 */
function clearOwnSelection(): void {
    const expected = ownedRange;
    // 所有状態は Selection 操作より先に手放す (途中で投げても所有が残らない)
    ownedRange = null;
    if (expected === null) return;

    try {
        const selection = window.getSelection();
        if (selection === null || selection.rangeCount !== 1) return;

        const current = selection.getRangeAt(0);
        const isOwned =
            current.startContainer === expected.startContainer &&
            current.startOffset === expected.startOffset &&
            current.endContainer === expected.endContainer &&
            current.endOffset === expected.endOffset;

        if (isOwned) selection.removeAllRanges();
    } catch {
        // 後処理の失敗はコピーの成否に影響させない
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

onDestroy(() => {
    // 保留中の試行を無効化する (破棄後に解決した writeText が markCopied を呼び、
    // 新しいタイマーを登録してしまうのを防ぐ)
    attemptId++;
    clearOwnSelection();
    if (timeoutId !== undefined) {
        clearTimeout(timeoutId);
        timeoutId = undefined;
    }
});
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
            ? "コピーできませんでした。上のテキストを選択したので、⌘C / Ctrl+C (スマートフォンでは端末のコピー操作) でコピーしてください。"
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
- **不変条件は 1 本**: 「**選択が残っているのは、手動コピーを促している間だけ**」。
  これを保つために、次の 2 つを**別々の解除**として持つ(混ぜて書かない):

  | 解除するもの | いつ | 手段 |
  |---|---|---|
  | **表示状態** (`status`) | 次の試行の冒頭 / 成功時 / component 破棄 | `status = "idle"` / `markCopied()` / DOM ごと消える |
  | **所有 Selection** | 次の試行の冒頭 / legacy 成功時 / component 破棄 | `clearOwnSelection()` |

  案内の DOM は component 破棄で自然に消えるので、`onDestroy` で追加処理が要るのは
  **Selection だけ**である(当初「破棄時に何もしなくてよい」と書いたのは撤回した。
  detached node を指す Selection が残りうるため)。
- **所有の判定は「code の中か」ではなく「自分が作った Range と完全一致か」**。
  前者だと、利用者が**同じ code 内の一部だけ**を選び直したときにその選択を奪う。
  `startContainer` / `startOffset` / `endContainer` / `endOffset` の 4 点一致で見る。
- **legacy コピーが成功したときも、自分が張った選択は畳む**。手動コピー用に残す理由が
  無くなるためで、上の不変条件が 1 本で言えるようになる。
- **`clearOwnSelection()` は例外を投げない**。これは後処理であり、
  ここでの失敗が **legacy コピーの成功を覆して案内表示に化ける**のは誤りだからである。
- **`onDestroy` は `attemptId` を進める**。破棄後に保留中の `writeText` が解決して
  `markCopied()` が走り、新しい 2 秒タイマーを登録するのを止める。
- **`aria-live` は足さない**。`role="status"` が暗黙に `aria-live="polite"` を持つため
  (既存 span と同じ流儀)。
- **ボタンは disabled にしない**(禁止事項 8)。押下してから結果を示す現行の形を保つ。
- **色は DS token のみ**(`text-danger` / `text-caption`)。hex 直書きを増やさない。

### テスト計画

施策 2 で固定する。

### リスク

- **`execCommand` は非推奨で、将来ブラウザから消えうる**。消えても `typeof` 検査で
  false を返して案内段へ落ちるだけなので、**壊れ方が安全側**である。
- **選択を奪う副作用**。Clipboard API が失敗した時点でページ内の既存選択を
  `removeAllRanges()` で消す。**これは legacy コピーが成功する場合にも起きる**
  (選択を作ってから execCommand を呼ぶ順序のため)。コピー操作の直接の結果として
  利用者が期待できる範囲だが、「失敗時にしか起きない」は不正確なのでそう書かない。
- **`addRange()` が例外を投げると、既存選択だけが失われる**。range 構築までは既存選択に
  触らない順序にしてこの窓を最小化したが、`removeAllRanges()` の後に `addRange()` が
  投げるケースは塞げない。**残留リスクとして受容する**(発生条件が非常に稀で、
  塞ぐには旧選択の退避・復元という機構がもう 1 つ要るため。思考原則 2)。
- **連打・遅延解決の競合**。1 回目の `writeText` が遅延して 2 回目より後に解決すると、
  古い結果で状態を上書きしうる。`attemptId` の単調増加で最新試行以外の状態更新を止める。
  破棄後の解決も同じ機構で止める(`onDestroy` が `attemptId` を進める)。
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
/** document.execCommand を差し替える (jsdom は未実装)。復元は afterEach が行う */
function stubExecCommand(impl: () => boolean): ReturnType<typeof vi.fn> {
    const spy = vi.fn(impl);
    Object.defineProperty(document, "execCommand", { value: spy, configurable: true });

    return spy;
}

/** 現在の選択が指している文字列 (jsdom の Selection 実装差を避けて Range 側から見る) */
function selectedText(): string {
    const selection = window.getSelection();

    return selection === null || selection.rangeCount === 0
        ? ""
        : selection.getRangeAt(0).toString();
}

afterEach(() => {
    removeClipboard();
    window.getSelection()?.removeAllRanges(); // Selection は document グローバル = 明示的に戻す
    Reflect.deleteProperty(document, "execCommand"); // stub を次テストへ漏らさない
    vi.restoreAllMocks();
    vi.useRealTimers();
});
```

**契約 16 件とテストケースを 1 対 1 に対応させる**。既存 5 本のうち 3 本(描画 / 成功表示 /
2 秒で消える)は**無変更**、失敗系 2 本は契約 3 / 5 へ**期待値を更新**して充当する。
よって新規追加は 14 本で、**ファイル全体は 5 + 14 = 19 本**になる。

| # | 契約 | 検査内容 |
|---|---|---|
| 1 | Clipboard 失敗 → 選択 → legacy 成功 | `execCommand` が `"copy"` で呼ばれ、**その呼び出し時点で選択が code を指している**ことを stub の中で assert。表示は「コピー完了」・案内なし |
| 2 | legacy 成功時は選択を残さない | 呼び出し後に `selectedText()` が空 |
| 3 | Clipboard 失敗 + legacy false → 案内 + 選択が残る | 案内文に「選択したので」が含まれ、`selectedText()` が `code` と一致(**既存「コピー失敗」テストの更新先**) |
| 4 | 案内は 2 秒経っても消えない | fake timer で 2100ms 進めても案内が残る |
| 5 | clipboard API 非対応でも同じ段階を通る | `navigator.clipboard` undefined で 3 と同じ結末(**既存「非対応環境」テストの更新先**) |
| 6 | `document.execCommand` **未定義**でも例外を投げず案内へ落ちる | stub せず実行(`typeof` ガードの固定) |
| 7 | `document.execCommand` が**例外を投げても**案内へ落ちる | throwing stub(`try/catch` の固定。契約 6 とは責務が別) |
| 8 | 失敗案内が出た後に成功すると案内が消え、選択も残らない | 1 回目失敗 → 2 回目成功 → 案内が消え `selectedText()` も空 |
| 9 | 再試行中は古い案内も古い選択も残らない | 1 回目失敗後、**解決を保留した** `writeText` で 2 回目を開始し、await 中に案内が消え `selectedText()` も空であることを見る |
| 10 | 遅延解決した古い試行は新しい結果を上書きしない | 1 回目を保留 → 2 回目成功 → その後 1 回目を reject → 表示は「コピー完了」のまま |
| 11 | unmount で自分が張った選択を畳む | 案内状態で `unmount` → `selectedText()` が空 |
| 12 | 利用者が**別要素**を選択していたら unmount で奪わない | 案内状態のあと `document.body` 直下の別要素を選択 → `unmount` → その選択が残る |
| 13 | 利用者が**同じ code 内を部分選択**し直していたら奪わない | 案内状態のあと code の一部だけを選択 → `unmount` → その選択が残る |
| 14 | 選択できなかった場合は「選択したので」と書かない | `window.getSelection` を null 返しに差し替え → `manual-unselected` の文面 |
| 15 | 保留中に unmount → その後 Promise が解決してもタイマーを登録しない | `setTimeout` を spy し、**unmount 直前の呼び出し回数からの差分が 0** であることを見る(Svelte / テスト環境も内部でタイマーを使いうるため総数を 0 と断定しない) |
| 16 | 選択解除が例外を投げても legacy 成功表示は覆らない | `removeAllRanges` を呼び出し回数で分岐させ、`selectCode()` 時は成功・`clearOwnSelection()` 時だけ throw させて「コピー完了」が出ることを見る |

### テスト実装上の注意 (Codex Round 2 の助言を反映)

- **保留 Promise は手動 deferred で作る**。`fireEvent.click()` の返り値を await しても
  保留中の Promise 自体は待たないよう、`act()` で DOM 更新だけ flush する。
  契約 10 は呼び出し回数ごとに別の Promise を返す mock が要る。
  **各 deferred はテスト終了前に必ず resolve / reject する**(未処理 rejection を残さない)。
- **契約 12 の外部選択対象は `document.body` 直下に置く**(component の unmount に
  巻き込まれると、選択が残らないのが実装の正否と無関係になるため)。
- **Selection / `execCommand` は document グローバル**なので `afterEach` で必ず戻す。

```ts
afterEach(() => {
    removeClipboard();
    window.getSelection()?.removeAllRanges();
    Reflect.deleteProperty(document, "execCommand");
    vi.restoreAllMocks();
    vi.useRealTimers();
});
```

### fail 先行の確認手順

1. 施策 2 のテストだけを先に置いて `pnpm test` を実行し、**未実装の契約が期待どおり赤になる**ことを
   確認する(本数は実測して記録する。既存 3 本は緑のままであることも見る)。
2. 施策 1 を実装して緑にする。

### mutation 計画

**赤くなる本数を完了条件にしない**(1 つの mutation が複数契約を同時に壊すのは自然)。
各行は「**最低これが赤くなるはず**」であり、実測と突き合わせて差異は全件記録する。

| # | mutation | 最低これが赤くなるはず |
|---|---|---|
| M1 | `selectCode()` が常に `false` を返す | 契約 3。**契約 14 は赤くならない**(契約 14 は最初から `getSelection` を null にしており、正常実装でも `selectCode()` は false のため。文面分岐の固定は M7 が担う) |
| M2 | `tryLegacyCopy()` の **try/catch を外す** | 契約 7。**契約 6 は `typeof` ガードで守られるので赤くならない**ことも実測する |
| M4 | `copy()` 冒頭の `status = "idle"` を削除 | 契約 9 のみ。**契約 8 は `markCopied()` が上書きするので赤くならない**ことも実測する |
| M5 | `copy()` 冒頭の `clearOwnSelection()` を削除 | 契約 9(選択が残る) |
| M6 | 案内にも 2 秒タイマーを付ける | 契約 4 |
| M7 | `manual-unselected` の文面を `manual-selected` と同一にする | 契約 14 |
| M8 | `execCommand` の戻り値を無視して常に成功扱い | 契約 3 |
| M9 | `attemptId` の比較を削除 | 契約 10 |
| M10 | `onDestroy` の `attemptId++` を削除 | 契約 15 |
| M11 | `clearOwnSelection()` の所有判定を**完全に外す** | 契約 12・13 |
| M12 | 所有判定を Range 完全一致から `codeEl.contains(...)` に**弱める** | 契約 13 のみ(契約 12 は通ってしまう = 弱めた判定の穴がそこにあることの実測) |
| M13 | legacy 成功時の `clearOwnSelection()` を削除 | 契約 2 |
| M14 | `onDestroy` の `clearOwnSelection()` を削除 | 契約 11 |
| M15 | `clearOwnSelection()` の try/catch を削除 | 契約 16 |

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

## テスト結果 (すべて worktree 内)

`pnpm test` 1343 passed / `composer test` 4511 passed・2 skipped / `composer phpstan` No errors /
`pnpm lint` / `pnpm typecheck` / `vendor/bin/pint --test` / `pnpm build` / packages 3 種: 全緑。

### fail 先行

テストだけを先に置いた時点で **19 件中 15 件が赤** (4 件は既存の描画/成功系)。

### 設計からの乖離 3 点 (実装時に判明。すべてテスト側)

1. **契約 13 の観測点を unmount から「再試行」へ変更した。**
   jsdom は DOM から取り除かれたノードを指す live range を自分で畳むため、
   unmount 後の選択は**実装の正否と無関係に空**になる (= unmount では本契約を観測できない)。
   DOM が残る再試行の冒頭で観測する形に変えた。mutation M12 で赤化を実測済み。
2. **afterEach の順序を変えた** (`vi.restoreAllMocks()` を Selection の後始末より前へ)。
   契約 16 の throwing stub を張ったままだと、後始末の `removeAllRanges()` 自体が
   その stub を踏んでテストが落ちるため。
3. **契約 11 / 12 に「removeAllRanges が呼ばれた / 呼ばれない」の assert を追加した。**
   理由は下記 mutation M14 の実測。

### mutation の実測 (予測との対比)

| # | mutation | 予測 | 実測 |
|---|---|---|---|
| M1 | `selectCode()` が常に false | 最低でも契約 3 | **一致** (契約 1/3/5/11/16 の 5 件が赤。契約 14 は予測どおり赤くならず) |
| M2 | `tryLegacyCopy()` の try/catch 除去 | 契約 7。契約 6 は赤くならない | **一致** (契約 7 のみ 1 件) |
| M4 | `copy()` 冒頭の `status = "idle"` 削除 | 契約 9 のみ (契約 8 は赤くならない) | **一致** (契約 9 のみ) |
| M5 | `copy()` 冒頭の `clearOwnSelection()` 削除 | 契約 9 | **一致** (契約 8・9 の 2 件) |
| M6 | 案内にも 2 秒タイマー | 契約 4 | **一致** |
| M7 | `manual-unselected` の文面を同一化 | 契約 14 | **一致** |
| M8 | `execCommand` の戻り値を無視 | 契約 3 | **一致** (8 件が赤) |
| M9 | `attemptId` の比較削除 | 契約 10 | **一致** (契約 10・15) |
| M10 | `onDestroy` の `attemptId++` 削除 | 契約 15 | **一致** |
| M11 | 所有判定を完全に外す | 契約 12・13 | **一致** |
| M12 | 所有判定を `codeEl.contains(...)` へ弱める | 契約 13 のみ | **一致** (Critical 修正の価値が実測できた) |
| M13 | legacy 成功時の `clearOwnSelection()` 削除 | 契約 2 | **一致** |
| M14 | `onDestroy` の `clearOwnSelection()` 削除 | 契約 11 | **不一致 — 19 件すべて緑のままだった** |
| M15 | `clearOwnSelection()` の try/catch 削除 | 契約 16 | **一致** |

**M14 が赤化しなかった原因**: 契約 11 は結果 (`selectedText()` が空) だけを見ていたが、
jsdom は unmount による DOM 削除で live range を**自分で畳む**ため、破棄時の解除を
実装から外しても空になる。つまり Round 2 の Critical 修正 (`onDestroy` での解除) は
**どのテストにも守られていなかった**。
対処として契約 11 に「`removeAllRanges` が**呼ばれたこと**」、契約 12 に
「**呼ばれないこと**」の assert を追加し、**M14 と M11 の再実測で赤化を確認した**。
実ブラウザでは DOM 削除で選択が畳まれる保証が無いため、この解除自体は残す。

---

## 実装差分 (git diff)

```diff
diff --git a/resources/js/components/molecules/CodeSnippet.svelte b/resources/js/components/molecules/CodeSnippet.svelte
index 7c6767e..9f5f4b0 100644
--- a/resources/js/components/molecules/CodeSnippet.svelte
+++ b/resources/js/components/molecules/CodeSnippet.svelte
@@ -5,8 +5,8 @@
     /**
      * コピー付きコードブロック molecule (API キー・リカバリコード・CLI コマンド等の表示用)。
      *
-     * コピー処理は component 内に内包する (navigator.clipboard 直呼び。
-     * 非対応環境・拒否時は「コピー失敗」を表示して手動コピーを促す)。
+     * コピー処理は component 内に内包する。Clipboard API が使えないとき (非対応環境・拒否・
+     * フォーカス喪失など) は、**コード文字列を選択状態にして手動コピーへ進める道を残す**。
      * `<pre>` は中間 box なので rounded-md、コードは font-mono (DESIGN.md §Shapes / §Typography)。
      */
     interface Props {
@@ -18,32 +18,167 @@
 
     let { code, language = "plaintext", testId, class: extraClass = "" }: Props = $props();
 
-    let copied = $state(false);
-    let failed = $state(false);
+    /**
+     * 表示状態。**boolean 2 本ではなく 4 値で持つ**
+     * (「失敗したが選択はできた」と「選択もできなかった」を取り違えないため。
+     *  boolean の組み合わせだと不能な状態が型の上で作れてしまう)。
+     */
+    type CopyStatus = "idle" | "copied" | "manual-selected" | "manual-unselected";
+
+    let status = $state<CopyStatus>("idle");
+    let codeEl = $state<HTMLElement | null>(null);
     let timeoutId: ReturnType<typeof setTimeout> | undefined;
+    /**
+     * この component が張った Range の複製。**「code の中か」ではなく「自分が作った Range と
+     * 完全一致か」で所有を判定する** (利用者が同じ code 内を部分選択し直した場合に、
+     * その選択を奪わないため)。
+     */
+    let ownedRange: Range | null = null;
+    /** 連打・遅延解決の競合よけ。await 後に最新試行でなければ状態を触らない */
+    let attemptId = 0;
 
+    /**
+     * コピー。段階は次のとおりで、**主役は 2 の「選択を残すこと」**である
+     * (3 は選択のついでに試す legacy fallback にすぎない)。
+     * 1. Clipboard API → 通れば成功
+     * 2. コード文字列を選択状態にする (手動コピーへ進める状態を手元に残す)
+     * 3. その選択で document.execCommand("copy") を試す (非推奨 API。通れば成功)
+     * 4. 通らなければ案内を出す。**この案内は自動では消さない** (手動コピーには時間が要る)
+     *
+     * 不変条件: **選択が残っているのは手動コピーを促している間だけ**。
+     */
     async function copy(): Promise<void> {
         if (timeoutId) clearTimeout(timeoutId);
+        const attempt = ++attemptId;
+        // 前回の試行の痕跡を両方消す (所有 Selection と表示状態)
+        clearOwnSelection();
+        status = "idle";
+
+        const written = await writeViaClipboardApi();
+        // 遅延解決した古い試行・破棄後の解決は、新しい状態を上書きしない
+        if (attempt !== attemptId) return;
+
+        if (written) {
+            markCopied();
+
+            return;
+        }
+
+        const selected = selectCode();
+        if (selected && tryLegacyCopy()) {
+            // コピーできた以上、手動コピー用の選択は不要 = 自分が張った選択だけ畳む
+            clearOwnSelection();
+            markCopied();
+
+            return;
+        }
+
+        status = selected ? "manual-selected" : "manual-unselected";
+    }
+
+    /** Clipboard API での書き込み。非対応環境・拒否・フォーカス喪失はすべて false */
+    async function writeViaClipboardApi(): Promise<boolean> {
         try {
-            // clipboard 非対応環境 (insecure context 等) は writeText が無いため失敗扱い
-            if (!navigator.clipboard?.writeText) {
-                throw new Error("clipboard unavailable");
-            }
+            if (!navigator.clipboard?.writeText) return false;
             await navigator.clipboard.writeText(code);
-            copied = true;
-            failed = false;
+
+            return true;
+        } catch {
+            return false;
+        }
+    }
+
+    /** コード文字列を選択状態にする (成否を返す)。**これが本命の受け皿** */
+    function selectCode(): boolean {
+        if (codeEl === null) return false;
+        const selection = window.getSelection();
+        if (selection === null) return false;
+
+        // range の構築と所有記録用の複製までを、既存選択に触る前に済ませる
+        // (ここで失敗しても利用者の選択を壊さない / 選択を張った後に cloneRange が投げて
+        //  「所有していない選択だけが残る」状態を作らない)
+        let range: Range;
+        let owned: Range;
+        try {
+            range = document.createRange();
+            range.selectNodeContents(codeEl);
+            owned = range.cloneRange();
+        } catch {
+            return false;
+        }
+
+        try {
+            selection.removeAllRanges();
+            selection.addRange(range);
+        } catch {
+            // ここで投げると既存選択だけが失われる (稀。塞ぐには旧選択の退避機構が要るため受容)
+            return false;
+        }
+        ownedRange = owned;
+
+        return true;
+    }
+
+    /**
+     * **この component が張った選択だけ**を畳む。利用者がその後に選択し直していたら触らない
+     * (別要素でも、同じ code 内の部分選択でも奪わない = Range の境界 4 点で完全一致を見る)。
+     * **例外を投げない** — これは後処理であり、ここでの失敗がコピー成功を覆してはならない。
+     */
+    function clearOwnSelection(): void {
+        const expected = ownedRange;
+        // 所有状態は Selection 操作より先に手放す (途中で投げても所有が残らない)
+        ownedRange = null;
+        if (expected === null) return;
+
+        try {
+            const selection = window.getSelection();
+            if (selection === null || selection.rangeCount !== 1) return;
+
+            const current = selection.getRangeAt(0);
+            const isOwned =
+                current.startContainer === expected.startContainer &&
+                current.startOffset === expected.startOffset &&
+                current.endContainer === expected.endContainer &&
+                current.endOffset === expected.endOffset;
+
+            if (isOwned) selection.removeAllRanges();
         } catch {
-            copied = false;
-            failed = true;
+            // 後処理の失敗はコピーの成否に影響させない
         }
+    }
+
+    /**
+     * 非推奨 API による最後の試行。**主役ではない** —
+     * 選択状態をどのみち作るので、そのついでに試すだけの補助経路である。
+     * これが通っても「Clipboard API の代替が保証された」わけではない
+     * (execCommand も document のフォーカスを要求するため、フォーカス起因なら同じく失敗する)。
+     */
+    function tryLegacyCopy(): boolean {
+        if (typeof document.execCommand !== "function") return false;
+        try {
+            return document.execCommand("copy");
+        } catch {
+            return false;
+        }
+    }
+
+    /** 成功表示 (2 秒で消える。一過性の確認なので消えてよい) */
+    function markCopied(): void {
+        status = "copied";
         timeoutId = setTimeout(() => {
-            copied = false;
-            failed = false;
+            status = "idle";
         }, 2000);
     }
 
     onDestroy(() => {
-        if (timeoutId) clearTimeout(timeoutId);
+        // 保留中の試行を無効化する (破棄後に解決した writeText が markCopied を呼び、
+        // 新しいタイマーを登録してしまうのを防ぐ)
+        attemptId++;
+        clearOwnSelection();
+        if (timeoutId !== undefined) {
+            clearTimeout(timeoutId);
+            timeoutId = undefined;
+        }
     });
 </script>
 
@@ -53,12 +188,10 @@
         data-testid={testId ? `${testId}-body` : undefined}
         data-language={language}
         class="overflow-x-auto rounded-md border border-border bg-neutral p-4 pr-24 text-caption font-mono text-text"><code
-        >{code}</code></pre>
+        bind:this={codeEl}>{code}</code></pre>
     <div class="absolute top-2 right-2 flex items-center gap-2">
-        {#if copied}
+        {#if status === "copied"}
             <span role="status" class="text-caption text-success">コピー完了</span>
-        {:else if failed}
-            <span role="status" class="text-caption text-danger">コピー失敗</span>
         {/if}
         <Button
             variant="neutral"
@@ -69,4 +202,17 @@
             コピー
         </Button>
     </div>
+    {#if status === "manual-selected" || status === "manual-unselected"}
+        <!-- 案内はブロック下部の通常フローに出す (ボタン横の absolute 領域に長文を置くと破綻する)。
+             文面は**実際にやったことをそのまま言う** = 選択できたときだけ「選択した」と書く。 -->
+        <p
+            role="status"
+            class="mt-2 text-caption text-danger"
+            data-testid={testId ? `${testId}-manual-copy` : undefined}
+        >
+            {status === "manual-selected"
+                ? "コピーできませんでした。上のテキストを選択したので、⌘C / Ctrl+C (スマートフォンでは端末のコピー操作) でコピーしてください。"
+                : "コピーできませんでした。上のテキストを選択して手動でコピーしてください。"}
+        </p>
+    {/if}
 </div>
diff --git a/tests/js/components/molecules/CodeSnippet.test.ts b/tests/js/components/molecules/CodeSnippet.test.ts
index cf62da8..2c1617f 100644
--- a/tests/js/components/molecules/CodeSnippet.test.ts
+++ b/tests/js/components/molecules/CodeSnippet.test.ts
@@ -17,8 +17,53 @@ function removeClipboard(): void {
     });
 }
 
+/**
+ * document.execCommand を差し替える (jsdom は未実装 = undefined)。
+ * 復元は afterEach の deleteProperty が行う。
+ */
+function stubExecCommand(impl: (command: string) => boolean): ReturnType<typeof vi.fn> {
+    const spy = vi.fn(impl);
+    Object.defineProperty(document, "execCommand", { value: spy, configurable: true });
+
+    return spy;
+}
+
+/**
+ * 現在の選択が指している文字列。jsdom の Selection.toString() は実装差があるため
+ * Range 側から見る (どの環境でも Range#toString は範囲のテキストを返す)。
+ */
+function selectedText(): string {
+    const selection = window.getSelection();
+
+    return selection === null || selection.rangeCount === 0
+        ? ""
+        : selection.getRangeAt(0).toString();
+}
+
+/** 手動 deferred (保留中の writeText を作るため) */
+function deferred(): {
+    promise: Promise<void>;
+    resolve: () => void;
+    reject: (reason?: unknown) => void;
+} {
+    let resolve!: () => void;
+    let reject!: (reason?: unknown) => void;
+    const promise = new Promise<void>((res, rej) => {
+        resolve = res;
+        reject = rej;
+    });
+
+    return { promise, resolve, reject };
+}
+
 afterEach(() => {
     removeClipboard();
+    // **spy の復元が先**。Selection の spy を張ったままだと、後始末の removeAllRanges 自体が
+    // 契約 16 の throwing stub を踏んでテストを落とす。
+    vi.restoreAllMocks();
+    // Selection / execCommand は document グローバル = 明示的に戻さないと次テストを汚染する
+    window.getSelection()?.removeAllRanges();
+    Reflect.deleteProperty(document, "execCommand");
     vi.useRealTimers();
 });
 
@@ -64,22 +109,337 @@ describe("CodeSnippet", () => {
         });
         expect(screen.queryByText("コピー完了")).toBeNull();
     });
+});
 
-    it("コピー失敗で「コピー失敗」を表示する", async () => {
+/*
+ * コピー失敗時のフォールバック (T156 / bug-hunt F-3-01)。
+ *
+ * 契約の骨子:
+ * - 失敗したら**コード文字列を選択状態にして手動コピーへ進める道を残す**のが本命。
+ *   document.execCommand は「どのみち選択を作るのでついでに試す」補助にすぎない。
+ * - **選択が残っているのは手動コピーを促している間だけ** (legacy 成功時・再試行の冒頭・
+ *   component 破棄では、自分が張った選択だけを畳む)。
+ * - 案内は自動で消さない (手動コピーには時間が要る)。成功表示が 2 秒で消えるのとは
+ *   意図的に非対称。
+ */
+describe("CodeSnippet コピー失敗時のフォールバック (T156)", () => {
+    /** Clipboard API が必ず失敗する状態にする */
+    function stubFailingClipboard(): void {
         stubClipboard(vi.fn().mockRejectedValue(new Error("denied")));
+    }
+
+    it("契約 1: Clipboard 失敗 → 選択 → legacy コピー成功で「コピー完了」", async () => {
+        stubFailingClipboard();
+        let selectionAtCall = "";
+        const execCommand = stubExecCommand(() => {
+            // **呼ばれた時点で**選択が code を指していること (順序まで固定する)
+            selectionAtCall = selectedText();
+
+            return true;
+        });
+        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+
+        expect(execCommand).toHaveBeenCalledWith("copy");
+        expect(selectionAtCall).toBe("abc-123");
+        expect(await screen.findByText("コピー完了")).toBeInTheDocument();
+        expect(screen.queryByTestId("snippet-manual-copy")).toBeNull();
+    });
+
+    it("契約 2: legacy コピー成功時は選択を残さない", async () => {
+        stubFailingClipboard();
+        stubExecCommand(() => true);
+        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+        await act(async () => {
+            await Promise.resolve();
+        });
+
+        expect(selectedText()).toBe("");
+    });
+
+    it("契約 3: legacy も失敗したら案内を出し、選択は残す", async () => {
+        stubFailingClipboard();
+        stubExecCommand(() => false);
+        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+
+        const notice = await screen.findByTestId("snippet-manual-copy");
+        expect(notice.textContent).toContain("選択したので");
+        expect(selectedText()).toBe("abc-123");
+    });
+
+    it("契約 4: 案内は 2 秒経っても消えない (成功表示との非対称)", async () => {
+        vi.useFakeTimers();
+        stubFailingClipboard();
+        stubExecCommand(() => false);
         render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });
 
         await fireEvent.click(screen.getByTestId("snippet-copy"));
+        await act(async () => {
+            await Promise.resolve();
+        });
+        expect(screen.getByTestId("snippet-manual-copy")).toBeInTheDocument();
 
-        expect(await screen.findByText("コピー失敗")).toBeInTheDocument();
+        await act(async () => {
+            await vi.advanceTimersByTimeAsync(2100);
+        });
+        expect(screen.getByTestId("snippet-manual-copy")).toBeInTheDocument();
     });
 
-    it("clipboard API 非対応環境では「コピー失敗」を表示する", async () => {
+    it("契約 5: clipboard API 非対応環境でも同じ段階を通る", async () => {
         removeClipboard();
+        stubExecCommand(() => false);
+        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+
+        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();
+        expect(selectedText()).toBe("abc-123");
+    });
+
+    it("契約 6: document.execCommand が未定義でも例外を投げず案内へ落ちる", async () => {
+        stubFailingClipboard();
+        // execCommand は stub しない (jsdom 既定 = 未定義)
+        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+
+        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();
+    });
+
+    it("契約 7: document.execCommand が例外を投げても案内へ落ちる", async () => {
+        stubFailingClipboard();
+        stubExecCommand(() => {
+            throw new Error("execCommand exploded");
+        });
+        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+
+        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();
+    });
+
+    it("契約 8: 案内が出た後に成功すると案内が消え、選択も残らない", async () => {
+        const writeText = vi
+            .fn()
+            .mockRejectedValueOnce(new Error("denied"))
+            .mockResolvedValueOnce(undefined);
+        stubClipboard(writeText);
+        stubExecCommand(() => false);
+        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+        await act(async () => {
+            await Promise.resolve();
+        });
+
+        expect(screen.queryByTestId("snippet-manual-copy")).toBeNull();
+        expect(selectedText()).toBe("");
+    });
+
+    it("契約 9: 再試行中は古い案内も古い選択も残らない", async () => {
+        const pending = deferred();
+        const writeText = vi
+            .fn()
+            .mockRejectedValueOnce(new Error("denied"))
+            .mockReturnValueOnce(pending.promise);
+        stubClipboard(writeText);
+        stubExecCommand(() => false);
+        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();
+
+        // 2 回目は解決を保留する = await 中の状態を観測する
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+        await act(async () => {
+            await Promise.resolve();
+        });
+
+        expect(screen.queryByTestId("snippet-manual-copy")).toBeNull();
+        expect(selectedText()).toBe("");
+
+        // 未処理の Promise を残さない
+        pending.resolve();
+        await act(async () => {
+            await pending.promise;
+        });
+    });
+
+    it("契約 10: 遅延解決した古い試行は新しい結果を上書きしない", async () => {
+        const slow = deferred();
+        const writeText = vi
+            .fn()
+            .mockReturnValueOnce(slow.promise)
+            .mockResolvedValueOnce(undefined);
+        stubClipboard(writeText);
+        stubExecCommand(() => false);
+        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });
+
+        // 1 回目 (保留) → 2 回目 (即成功)
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+        await act(async () => {
+            await Promise.resolve();
+        });
+        expect(screen.getByText("コピー完了")).toBeInTheDocument();
+
+        // 後から 1 回目が失敗しても、新しい結果を覆さない
+        slow.reject(new Error("late failure"));
+        await act(async () => {
+            await slow.promise.catch(() => undefined);
+        });
+
+        expect(screen.getByText("コピー完了")).toBeInTheDocument();
+        expect(screen.queryByTestId("snippet-manual-copy")).toBeNull();
+    });
+
+    it("契約 11: unmount で自分が張った選択を畳む", async () => {
+        stubFailingClipboard();
+        stubExecCommand(() => false);
+        const { unmount } = render(CodeSnippet, {
+            props: { code: "abc-123", testId: "snippet" },
+        });
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();
+        expect(selectedText()).toBe("abc-123");
+
+        // **結果 (selectedText が空) だけでは実装の有無を区別できない**。jsdom は DOM から
+        // 取り除かれたノードを指す live range を自分で畳むため、破棄時の解除を実装から
+        // 外しても空になる (mutation で実測済み)。よって「解除を試みたこと」も併せて見る。
+        const selection = window.getSelection();
+        expect(selection).not.toBeNull();
+        const removeAllRanges = vi.spyOn(selection as Selection, "removeAllRanges");
+
+        unmount();
+
+        expect(removeAllRanges).toHaveBeenCalled();
+        expect(selectedText()).toBe("");
+    });
+
+    it("契約 12: 利用者が別要素を選択していたら unmount で奪わない", async () => {
+        // 「奪わない」= unmount 時に removeAllRanges を呼ばないことも併せて見る
+        stubFailingClipboard();
+        stubExecCommand(() => false);
+        const { unmount } = render(CodeSnippet, {
+            props: { code: "abc-123", testId: "snippet" },
+        });
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();
+
+        // component の unmount に巻き込まれないよう body 直下に置く
+        const outside = document.createElement("p");
+        outside.textContent = "利用者が選んだ別のテキスト";
+        document.body.appendChild(outside);
+        const range = document.createRange();
+        range.selectNodeContents(outside);
+        const selection = window.getSelection();
+        selection?.removeAllRanges();
+        selection?.addRange(range);
+
+        const removeAllRanges = vi.spyOn(selection as Selection, "removeAllRanges");
+
+        unmount();
+
+        expect(removeAllRanges).not.toHaveBeenCalled();
+        expect(selectedText()).toBe("利用者が選んだ別のテキスト");
+        outside.remove();
+    });
+
+    it("契約 13: 利用者が同じ code 内を部分選択し直していたら奪わない", async () => {
+        // 観測点は **unmount ではなく再試行**である。jsdom は DOM から取り除かれたノードを指す
+        // live range を畳むため、unmount 後の選択は実装の正否と無関係に空になる
+        // (= unmount では本契約を観測できない)。DOM が残る再試行の冒頭で見る。
+        const writeText = vi
+            .fn()
+            .mockRejectedValueOnce(new Error("denied"))
+            .mockResolvedValueOnce(undefined);
+        stubClipboard(writeText);
+        stubExecCommand(() => false);
+        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();
+
+        // 同じ code の一部だけを選び直す (contains 判定だと奪われてしまうケース)
+        const textNode = screen.getByTestId("snippet-body").querySelector("code")?.firstChild;
+        expect(textNode).not.toBeNull();
+        const partial = document.createRange();
+        partial.setStart(textNode as Node, 0);
+        partial.setEnd(textNode as Node, 3);
+        const selection = window.getSelection();
+        selection?.removeAllRanges();
+        selection?.addRange(partial);
+        expect(selectedText()).toBe("abc");
+
+        // 再試行 (成功) 。冒頭の clearOwnSelection が利用者の部分選択を奪ってはならない
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+        await act(async () => {
+            await Promise.resolve();
+        });
+
+        expect(selectedText()).toBe("abc");
+    });
+
+    it("契約 14: 選択できなかった場合は「選択したので」と書かない", async () => {
+        stubFailingClipboard();
+        stubExecCommand(() => false);
+        vi.spyOn(window, "getSelection").mockReturnValue(null);
+        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+
+        const notice = await screen.findByTestId("snippet-manual-copy");
+        expect(notice.textContent).not.toContain("選択したので");
+        expect(notice.textContent).toContain("選択して手動でコピー");
+    });
+
+    it("契約 15: 保留中に unmount したら、その後の解決でタイマーを登録しない", async () => {
+        const pending = deferred();
+        stubClipboard(vi.fn().mockReturnValue(pending.promise));
+        const setTimeoutSpy = vi.spyOn(globalThis, "setTimeout");
+        const { unmount } = render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });
+
+        await fireEvent.click(screen.getByTestId("snippet-copy"));
+        unmount();
+
+        // Svelte / テスト環境も内部でタイマーを使いうるので、総数ではなく**差分**で見る
+        const countBeforeResolve = setTimeoutSpy.mock.calls.length;
+        pending.resolve();
+        await act(async () => {
+            await pending.promise;
+        });
+
+        expect(setTimeoutSpy).toHaveBeenCalledTimes(countBeforeResolve);
+    });
+
+    it("契約 16: 選択解除が例外を投げても legacy 成功表示は覆らない", async () => {
+        stubFailingClipboard();
+        stubExecCommand(() => true);
+        const selection = window.getSelection();
+        expect(selection).not.toBeNull();
+        let removeCalls = 0;
+        vi.spyOn(selection as Selection, "removeAllRanges").mockImplementation(function (
+            this: Selection,
+        ): void {
+            removeCalls += 1;
+            // 1 回目 (selectCode) は成功、2 回目 (clearOwnSelection) だけ throw させる
+            if (removeCalls >= 2) throw new Error("removeAllRanges exploded");
+        });
         render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });
 
         await fireEvent.click(screen.getByTestId("snippet-copy"));
 
-        expect(await screen.findByText("コピー失敗")).toBeInTheDocument();
+        expect(await screen.findByText("コピー完了")).toBeInTheDocument();
+        expect(screen.queryByTestId("snippet-manual-copy")).toBeNull();
     });
 });
```
