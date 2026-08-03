# Round 3: Round 2 の [Warning] (属性値の判定不足) への対応

## 対応マトリクス

### [Warning] (施策 5) `novalidate={false}` / `novalidate={cond}` が合格してしまう → **対応した**

- 判定条件に `a.value === true` を追加し、**静的な boolean shorthand のみ**を合格とした。
- 検査を `formViolationsInSource(source, label)` として **source ベースに分離**した (提案どおり)。
- **検出器の自己テスト**を `it.each` で追加した (提案の 4 ケース + `<script>` 内文字列の誤検出なし)。
- 属性値の形を svelte 5.56.3 で**実測**し、詳細設計に根拠として記載した:

  | 記述 | `Attribute.value` |
  |---|---|
  | `<form novalidate>` | `true` (boolean shorthand) |
  | `<form novalidate={false}>` | 式ノード (object) |
  | `<form novalidate={cond}>` | 式ノード (object) |
  | `<form novalidate="novalidate">` | `[Text]` |

## 変更後の 5-1 (全文)

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
- **属性値の形も実測済み**(上表)。`value === true` の一致で「静的に必ず付く」ものだけを合格にできる

---

施策 5 と全体判定の再判定をお願いします。
