# Round 2: Round 1 指摘への対応

Round 1 の判定 (CHANGES_REQUESTED / 施策 3・5 が REQUEST_CHANGES) を受けて詳細設計を更新しました。
対応マトリクスと、変更後の該当箇所の全文を示します。再判定をお願いします。

## 対応マトリクス

### [Warning] (施策 3/5) `form-novalidate` テストの正規表現走査は偽陽性/偽陰性を生む → **対応した**

- 生テキスト走査をやめ、`svelte/compiler` の `parse(source, { modern: true })` による
  **AST 走査**に変更した。`RegularElement` かつ `name === "form"` のノードで
  `attributes` に `Attribute` / `name === "novalidate"` があるかを判定し、
  `node.start` から行番号を算出する。`parent` キーを辿らない再帰で循環参照を回避する。
- **実現性を実測した** (svelte 5.56.3): `resources/js` 配下の `.svelte` 99 ファイルが
  すべて parse でき、検出された form 要素は **33 個**で grep 結果と完全一致した
  (走査漏れ・重複なし)。
- あわせて「`novalidate` は `<form` の直後 (最初の属性) に書く」という**書式規約の機械強制をやめた**。
  この位置指定は正規表現で判定可能にするための都合であり、AST 化で根拠が消えたため、
  可読性上の慣習に格下げした (機械が見るのは有無だけ)。DESIGN.md の追記文からも位置指定を削除した。

### [Suggestion] (施策 1) 入力 atom の `class` に `bg-*` を渡さない lint/architecture ルール → **見送る**

- 現に違反 call site はゼロ (`class` prop の実使用は `PasswordInput.svelte:47` の `pr-10` のみ)。
  存在しない問題への機構追加はオーバーエンジニアリング (思考原則 2)。
- 施策 1 の「リスク」節にこの判断と理由を明記した。

### [Suggestion] (施策 2) 「不変条件が同じなら既存実装は許容、新規は `$derived` 推奨」の明文化 → **対応した**

- DESIGN.md §FormField 追記文に「canonical なのはこの不変条件であって実装形ではない」
  「先行実装 (PurchaseTickets / Organizations Settings) は同じ不変条件を満たしておりそのまま許容する」
  「新規は `$derived` 形で書く」を明記した。

### [Suggestion] (施策 5) ブラウザ E2E 1 本の最小補強 → **見送る**

- (a) `tests/Browser` は現状 2 本で、Chromium + WebKit の 2 レーン実行が契約
  (`docs/testing-browser.md` / AGENTS.md ドメイン固有規約 3)。1 本追加は実行コスト 2 倍で入る。
- (b) 守りたい不変条件は「**全 33 form** が native validation に依存しない」であり、
  1 画面の E2E では他の 32 form を守れない。網羅的に守れるのは architecture テストの方。
- (c) E2E が担保するのは「`novalidate` が付いたブラウザは本当にブロックしないか」だが、
  これは HTML 仕様に属する事実であってアプリ側の回帰点ではない。
- 5-5 の注記にこの回答を明記した。

---

## 変更後の該当箇所 (全文)

### 施策 3 の書式に関する記述 (変更後)

> **書式**: `novalidate` は `<form` の直後(最初の属性)に書く。「このフォームの検証はサーバと
> 明示 client エラーが正本」という宣言を先頭で読ませるための**可読性上の慣習**であり、
> **機械強制はしない**(施策 5-1 の architecture テストは AST で `novalidate` の**有無**だけを見る。
> 位置まで縛るのは機械可読性の都合が消えた今、根拠のない追加制約になる — Codex Round 1 の
> AST 化指摘を受けた判断)。

### 施策 5-1 (変更後・全文)

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

function formViolations(file: string): string[] {
    const source = fs.readFileSync(file, "utf-8");
    const ast = parse(source, { modern: true, filename: file });
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
            attributes?: { type?: string; name?: string }[];
        };
        if (n.type === "RegularElement" && n.name === "form") {
            const hasNoValidate = (n.attributes ?? []).some(
                (a) => a.type === "Attribute" && a.name === "novalidate",
            );
            if (!hasNoValidate) {
                const line = source.slice(0, n.start ?? 0).split("\n").length;
                out.push(`${relPath(file)}:${line}`);
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
        const violations = svelteFiles.flatMap(formViolations);
        expect(violations).toEqual([]);
    });
});
```

- 固定する不変条件: **native constraint validation に依存しない**(施策 3 / 規約 2)
- 失敗の仕方: 新規フォームを `novalidate` なしで足すと即 fail(ファイル:行 を提示)
- **実現性を実測済み** (svelte 5.56.3): `resources/js` の 99 個の `.svelte` が全て parse でき、
  `RegularElement`/`name === "form"` の検出数は **33** で grep 結果と完全一致した
  (= 走査漏れ・重複なし)。`parent` を辿らない再帰で循環参照も踏まない

### 施策 4 (DESIGN.md §FormField 追記文・変更後)

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

### 施策 4 (DESIGN.md §Do's and Don'ts 追記文・変更後)

```markdown
- **native の constraint validation に検証を任せない**。`<form>` には `novalidate` を付け、
  検証文言はサーバ(日本語)と押下時の client エラーに一本化する。
  native validation は submit より先に発火してブラウザロケール依存の文言で送信を止めるため、
  日本語 UI の検証経路に到達できなくなる
```

### 施策 5-5 の注記 (変更後・末尾に追記した部分)

> **Codex Round 1 [Suggestion]「E2E 1 本の最小補強」への回答**: 見送る。理由は
> (a) `tests/Browser` は現在 2 本 (bfcache / smoke) で、両レーン (Chromium + WebKit) が契約
> (`docs/testing-browser.md`)。1 本の追加が実行時間としては 2 レーン分になる、
> (b) 守りたい不変条件は「全 form に `novalidate` がある」であり、1 画面の E2E では
> **他の 32 form を守れない** — 網羅的に守れるのは 5-1 の architecture テストの方。
> E2E は「ブラウザが本当にブロックしないか」の一点だけを担保するが、これは HTML 仕様
> (`novalidate` の定義) に属する事実で、アプリ側の回帰点ではない。

### 施策 1 のリスク節 (追記した部分)

> - **「入力 atom の `class` prop に `bg-*` を渡すことを禁じる lint / architecture ルール」は
>   今回作らない** (Codex Round 1 [Suggestion] への回答)。現に違反 call site はゼロで、
>   存在しない問題に機構を足すのはオーバーエンジニアリング (思考原則 2)。必要になるのは
>   「background を上書きしたい call site が実際に現れたとき」で、そのときは
>   ルールを足すより「なぜ atom の面を上書きしたいのか」を先に問うべき

---

上記を踏まえ、施策 3 / 施策 5 の再判定と全体判定をお願いします。
見送りとした 2 件の Suggestion について、判断が誤っていると考える場合はその根拠を示してください。
