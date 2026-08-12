# Round 2: Round 1 指摘への対応と再レビュー依頼

Critical 1 件・Warning 7 件・Suggestion 3 件を**すべて対応**しました。反論はありません。
特に Critical (破棄時の selection) は当初案を撤回し、所有権判定つきの解除に変えています。

再レビューの観点:
- Critical への対応 (所有権判定つき `clearOwnSelection`) に穴は無いか
- legacy 成功時に選択を畳む変更が、別の不整合を生んでいないか
- 修正後の mutation 計画 M1〜M9 の予測は妥当か
- 契約 8 / 9 (保留 Promise を使う中間状態の検査) は jsdom で本当に書けるか
- 新たに生じた問題は無いか

---

# 対応マトリクス: design-review Round 1

全体判定 CHANGES_REQUESTED。Critical 1 / Warning 7 / Suggestion 3。**すべて対応**(反論なし)。

## [Critical] component 破棄時に追加コード不要という前提は危険

- 判断: **対応する**(当初案を撤回)
- 根拠: 「DOM が消えれば Selection も畳まれる」はブラウザ差を含めて言い切れない。
  detached node を指す Selection が残りうる、という指摘は妥当。
- 対応内容: `onDestroy` で `clearOwnSelection()` を呼ぶ。ただし**無条件の
  `removeAllRanges()` にはしない** — `selectionOwned` フラグと
  「現在の選択が自分の `codeEl` を指しているか」の 2 条件を満たすときだけ畳む
  (利用者がその後に別の場所を選択していたら奪わない)。契約 10 / 11 とテストで固定する。

## [Warning] `removeAllRanges()` の副作用説明が不正確 (legacy 成功時にも奪う)

- 判断: **対応する**
- 対応内容: (a) リスク欄の「失敗時にしか起きない」を削除し、**legacy 成功時にも起きる**と
  正しく書き直した。(b) 加えて **legacy 成功時は自分が張った選択を畳む**設計にした。
  これで「選択が残っているのは手動コピーを促しているときだけ」という不変条件が 1 本で言える。
  契約 2 で固定する。

## [Warning] `addRange` が投げると既存選択だけ失われる

- 判断: **対応する**(ただし完全には塞がない。正直に残留リスクとして書く)
- 対応内容: range 構築 (`createRange` + `selectNodeContents`) を
  `removeAllRanges()` **より前**に済ませる順序へ変更し、そこで失敗した場合は既存選択に触らない。
  `removeAllRanges()` 後の `addRange()` が投げる窓は塞げないため、
  **残留リスクとして明記**した (塞ぐには旧選択の退避・復元機構がもう 1 つ要る = 思考原則 2)。

## [Warning] 連打・遅延解決の競合

- 判断: **対応する**
- 対応内容: `attemptId` の単調増加を導入し、`await` 後に最新試行でなければ状態を更新しない。
  契約 9 とテストで固定し、mutation M7 で赤化を実測する。

## [Warning] mutation M2 は赤くならない可能性が高い

- 判断: **対応する**
- 根拠: そのとおり。`typeof` 検査を外しても `try/catch` が `undefined is not a function` を
  拾うため案内へ落ちる = テストは緑のまま。
- 対応内容: M2 を「**try/catch を外す**」に変更し、あわせて
  「`typeof` 検査だけ外しても赤くならないこと」も実測して記録する、とした。

## [Warning] mutation M3 の予測が弱い

- 判断: **対応する**
- 根拠: `status = "idle"` を消しても成功時は `markCopied()` が上書きするので契約 7 は緑のまま。
- 対応内容: M3 の予測を「**契約 8 (再試行中の中間状態) のみ**」に修正し、契約 7 が
  赤くならないことも実測対象にした。契約 8 は **解決を保留した Promise** で await 中を観測する。

## [Warning] mutation の「何本赤くなるか」は厳密でない

- 判断: **対応する**
- 対応内容: mutation 計画の見出しに「**赤くなる本数を完了条件にしない**」と明記し、
  各行を「最低これが赤くなるはず」に書き換えた。

## [Warning] jsdom の Selection / execCommand はグローバル状態でテスト間リークする

- 判断: **対応する**
- 対応内容: `afterEach` に `window.getSelection()?.removeAllRanges()` /
  `Reflect.deleteProperty(document, "execCommand")` / `vi.restoreAllMocks()` を追加した。

## [Warning] 破棄時の selection 解除テストが無い

- 判断: **対応する**
- 対応内容: 契約 10 (unmount で自分の選択を畳む) と契約 11 (利用者の別選択は奪わない) を新設。

## [Suggestion] `execCommand` が「その選択で」呼ばれることまで固定せよ

- 判断: **対応する**
- 対応内容: 契約 1 を「stub の**中で**、その時点の選択が code を指していることを assert する」形にした
  (呼び出し後に見るだけでは順序を固定できないため)。

## [Suggestion] 「スマートフォンでは長押し」は実機未確認の割に強い

- 判断: **対応する**
- 対応内容: 文面を「(スマートフォンでは端末のコピー操作)」に変更した。

## [Suggestion] 4 値 enum は妥当 / DESIGN.md・Atomic Design・セキュリティに問題なし

- 判断: **対応不要**(肯定的評価)


---

## 修正後の詳細設計書 (全文)

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
/** この component が張った選択が生きているか (自分が作った選択だけを畳むため) */
let selectionOwned = false;
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
    // 再試行中に古い案内を残さない (押したのに前回の失敗文が出たままにならない)
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

    // range の構築までは**既存選択に触らない** (ここで失敗しても利用者の選択を壊さない)
    let range: Range;
    try {
        range = document.createRange();
        range.selectNodeContents(codeEl);
    } catch {
        return false;
    }

    try {
        selection.removeAllRanges();
        selection.addRange(range);
    } catch {
        // ここで投げると**既存選択だけが失われる**。稀だが起こりうる残留リスクとして明記する
        selectionOwned = false;

        return false;
    }
    selectionOwned = true;

    return true;
}

/**
 * **この component が張った選択だけ**を畳む。利用者がその後に別の場所を選択し直していたら
 * 触らない (勝手に選択を奪わない)。legacy コピー成功時と component 破棄時に呼ぶ。
 */
function clearOwnSelection(): void {
    if (!selectionOwned) return;
    selectionOwned = false;

    const selection = window.getSelection();
    if (selection === null || codeEl === null || selection.rangeCount === 0) return;
    // 現在の選択が自分の code を指しているときだけ畳む
    if (!codeEl.contains(selection.getRangeAt(0).commonAncestorContainer)) return;
    selection.removeAllRanges();
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
- **案内の解除は 3 つ**: 次のコピー試行(`copy()` 冒頭で `idle` へ)/ 成功(`markCopied`)/
  component 破棄(`onDestroy` で `clearOwnSelection()`)。
  **破棄時に追加コードを書かないという当初案は撤回した** — DOM が消えれば選択も畳まれる、は
  ブラウザ差を含めて言い切れない(detached node を指す Selection が残りうる)。
  ただし**無条件に `removeAllRanges()` はしない**。利用者がその後で別の場所を選択していたら
  それを奪うことになるため、**現在の選択が自分の `codeEl` を指しているときだけ**畳む。
- **legacy コピーが成功したときも、自分が張った選択は畳む**。手動コピー用に残す理由が
  無くなるためで、これにより「**選択が残っているのは手動コピーを促しているときだけ**」という
  不変条件が 1 本で言えるようになる。
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

| # | 契約 | 検査内容 |
|---|---|---|
| 1 | Clipboard 失敗 → 選択 → legacy 成功 | `execCommand` が `"copy"` で呼ばれ、**その呼び出し時点で選択が code を指している**ことを stub 内で assert。表示は「コピー完了」。案内は出ない |
| 2 | legacy 成功時は選択を残さない | 呼び出し後に `selectedText()` が空 (手動コピー用の選択は不要になったため) |
| 3 | Clipboard 失敗 + legacy false → 案内 + 選択が残る | 案内文に「選択したので」が含まれ、`selectedText()` が `code` と一致 |
| 4 | 案内は 2 秒経っても消えない | fake timer で 2100ms 進めても案内が残る (成功表示との非対称) |
| 5 | clipboard API 非対応でも同じ段階を通る | `navigator.clipboard` undefined で 3 と同じ結末 |
| 6 | `document.execCommand` 未定義でも例外を投げない | stub せずに実行し、案内へ落ちる (`typeof` 検査の固定) |
| 7 | 失敗案内が出た後に成功すると案内が消える | 1 回目失敗 → 2 回目成功 → 案内が DOM から消える |
| 8 | 再試行中は古い案内が出たままにならない | 1 回目失敗後、**解決を保留した** `writeText` で 2 回目を開始し、await 中に案内が消えていることを見る |
| 9 | 遅延解決した古い試行は新しい結果を上書きしない | 1 回目を保留 → 2 回目成功 → その後 1 回目を reject させ、表示が「コピー完了」のままであることを見る |
| 10 | unmount で自分が張った選択を畳む | 案内状態で `unmount` し、`selectedText()` が空になる |
| 11 | 利用者が別の場所を選択していたら unmount で奪わない | 案内状態のあと別要素を選択 → `unmount` → その選択が残る |

既存 5 本のうち 3 本 (描画 / 成功表示 / 2 秒で消える) は**無変更**、失敗系 2 本は
**期待値を新契約へ更新**する (削除しない = 禁止事項 3)。

### fail 先行の確認手順

1. 施策 2 のテストだけを先に置いて `pnpm test` を実行し、**新規 11 本と更新 2 本が落ちる**ことを
   確認する (実際の本数は実装時に実測して記録する)。
2. 施策 1 を実装して緑にする。

### mutation 計画

**赤くなる本数を完了条件にしない** (1 つの mutation が複数契約を同時に壊すのは自然なため)。
各 mutation について「**最低でもこの契約が赤くなる**」を予測し、実測と突き合わせて記録する。

| # | mutation | 最低これが赤くなるはず |
|---|---|---|
| M1 | `selectCode()` が常に `false` を返す | 契約 3 (選択が残らない) と 12 (文面が `manual-unselected` になる) |
| M2 | `tryLegacyCopy()` の **try/catch を外す** | 契約 6 (未定義環境で例外が漏れる)。`typeof` 検査だけ外しても catch が拾うので**赤くならない**ことも併せて実測する |
| M3 | `copy()` 冒頭の `status = "idle"` を削除 | 契約 8 のみ (契約 7 は `markCopied()` が上書きするため**赤くならない**。これも実測する) |
| M4 | 案内にも 2 秒タイマーを付ける | 契約 4 |
| M5 | `manual-unselected` の文面を `manual-selected` と同一にする | 契約 12 |
| M6 | `execCommand` の戻り値を無視して常に成功扱い | 契約 3 |
| M7 | `attemptId` の比較を削除 | 契約 9 |
| M8 | `clearOwnSelection()` の「自分の選択か」判定を外す | 契約 11 |
| M9 | `onDestroy` の `clearOwnSelection()` を削除 | 契約 10 |

契約 12 = 「選択できなかった場合は『選択したので』と書かない」
(`window.getSelection` を null 返しに差し替えて `manual-unselected` の文面を固定する)。

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
