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

function listSvelteFiles(dir: string): string[] {
    if (!fs.existsSync(dir)) return [];
    const files: string[] = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true, recursive: true })) {
        if (!entry.isFile()) continue;
        if (path.extname(entry.name) !== ".svelte") continue;
        files.push(path.join(entry.parentPath, entry.name));
    }
    return files;
}

function relPath(file: string): string {
    return path.relative(JS_ROOT, file);
}

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
    visit((ast as unknown as { fragment: unknown }).fragment);
    return out;
}

const svelteFiles = listSvelteFiles(JS_ROOT);

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
        expect(formViolationsInSource(source as string, "inline.svelte")).toHaveLength(
            expected as number,
        );
    });
});
