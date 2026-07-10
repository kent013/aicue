import { describe, it, expect } from 'vitest';
import fs from 'fs/promises';
import path from 'path';
import { parse } from 'svelte/compiler';

/**
 * shape ramp 厳密 invariant (DESIGN.md §Shapes 機械強制)。
 *
 * components + pages 全 .svelte を svelte/compiler の AST で解析し、
 * **template fragment の class 属性 / class: directive** のみを検査対象にする
 * (script / style / コメントは AST 上 fragment 外 or 別ノードのため自動除外 = 誤検知ゼロ)。
 *
 * ramp は `rounded-sm` / `rounded-md` / `rounded-lg` (DESIGN.md §Shapes)。本 invariant は
 *   - 素の `rounded` (suffix なし)
 *   - 任意値 `rounded-[...]`
 *   - 方向別 `rounded-t-*` / `rounded-b-*` / `rounded-l-*` 等
 *   - shape を `class:rounded*` directive で条件切り替えすること (静的に読めないため)
 * を禁止する。完全円 (`rounded-full`) は **THEME_PATTERNS + FILE_SCOPED_ALLOWLIST**
 * (tests/js/support/ds-purity.ts) が統制するため本 test では扱わない (= 二重統制・矛盾回避)。
 *
 * 既知の制約: `class={computedClass}` のように script 側変数へ逃がした token は
 * 静的に追えない (= class context の静的 token 検査スコープ外)。
 */
const REPO = path.resolve(__dirname, '../../../');

// 抽出: 全 `rounded` 系 token
const ROUNDED_TOKEN = /\brounded(-[A-Za-z0-9[\]./-]+)?\b/g;
// 本 invariant が違反とみなすもの:
//   1. 素の `rounded` (= suffix なし)
//   2. 任意値 rounded-[...]
//   3. 方向別 rounded-{t,b,l,r,tl,tr,bl,br,s,e,ss,se,ee,es}-*
const DIRECTIONAL = /^rounded-(t|b|l|r|tl|tr|bl|br|s|e|ss|se|ee|es)(-|$)/;
function isViolation(token: string): boolean {
    if (token === 'rounded') return true; // 素の rounded
    if (token.startsWith('rounded-[')) return true; // 任意値
    if (DIRECTIONAL.test(token)) return true; // 方向別
    return false;
}

/** glob を top-level dep に追加せず Node.js 標準 fs.readdir { recursive: true } で .svelte を列挙 */
async function listSvelte(...dirs: string[]): Promise<string[]> {
    const out: string[] = [];
    for (const dir of dirs) {
        try {
            await fs.access(dir);
        } catch {
            continue;
        }
        const entries = await fs.readdir(dir, { recursive: true, withFileTypes: true });
        for (const e of entries) {
            if (e.isFile() && e.name.endsWith('.svelte')) {
                const base = (e as unknown as { parentPath?: string; path?: string }).parentPath
                    ?? (e as unknown as { path?: string }).path
                    ?? dir;
                out.push(path.join(base, e.name));
            }
        }
    }
    return out;
}

/** AST を再帰 walk し、class Attribute の値文字列群 と class: directive 名を収集 */
function collectClassStrings(node: unknown, source: string, out: { classText: string[]; directives: string[] }): void {
    if (node === null || typeof node !== 'object') return;
    const n = node as Record<string, unknown>;

    if (n.type === 'Attribute' && n.name === 'class') {
        // value は class="..." → Text[] / class={...} → 単一 ExpressionTag / true(boolean attr) の 3 形態。
        const value = n.value;
        const parts = Array.isArray(value) ? value : value && typeof value === 'object' ? [value] : [];
        for (const part of parts as Array<Record<string, unknown>>) {
            if (part.type === 'Text' && typeof part.data === 'string') {
                out.classText.push(part.data);
            } else if (part.type === 'ExpressionTag' || part.type === 'MustacheTag') {
                const expr = part.expression as Record<string, number> | undefined;
                if (expr && typeof expr.start === 'number' && typeof expr.end === 'number') {
                    out.classText.push(source.slice(expr.start, expr.end));
                }
            }
        }
    }
    if (n.type === 'ClassDirective' && typeof n.name === 'string') {
        out.directives.push(n.name);
    }

    for (const key of ['fragment', 'nodes', 'children', 'value', 'consequent', 'alternate', 'body', 'attributes']) {
        const child = n[key];
        if (Array.isArray(child)) child.forEach((c) => collectClassStrings(c, source, out));
        else if (child && typeof child === 'object') collectClassStrings(child, source, out);
    }
}

function disallowedRoundedTokens(source: string): string[] {
    const ast = parse(source, { modern: true });
    const out = { classText: [] as string[], directives: [] as string[] };
    collectClassStrings(ast.fragment, source, out);

    const violations: string[] = [];
    for (const text of out.classText) {
        const matches = text.match(ROUNDED_TOKEN);
        if (!matches) continue;
        for (const m of matches) {
            if (isViolation(m)) violations.push(m);
        }
    }
    // shape を class: directive で条件切り替えするのは静的に読めないため禁止
    for (const d of out.directives) {
        if (/^rounded(-|$)/.test(d)) violations.push(`class:${d}`);
    }
    return violations;
}

describe('shape ramp purity (DESIGN.md §Shapes)', () => {
    it('forbids bare / arbitrary / directional rounded tokens (ramp = sm/md/lg)', async () => {
        const files = await listSvelte(
            path.join(REPO, 'resources/js/components'),
            path.join(REPO, 'resources/js/pages'),
        );
        expect(files.length).toBeGreaterThan(0);

        const offenders: Record<string, string[]> = {};
        for (const file of files) {
            const source = await fs.readFile(file, 'utf-8');
            const v = disallowedRoundedTokens(source);
            if (v.length > 0) offenders[path.relative(REPO, file)] = [...new Set(v)];
        }

        expect(
            offenders,
            `禁止 rounded token 検出 (素の rounded / 任意値 / 方向別)。 ramp は rounded-sm/md/lg を使う:\n${JSON.stringify(offenders, null, 2)}`,
        ).toEqual({});
    });

    it('walker sanity: 検知ロジックが素の rounded / 任意値 / 方向別を捕捉する', () => {
        const bad = '<div class="rounded border">a</div>'
            + '<a class={cond ? "rounded-t-md" : "rounded-sm"}>b</a>'
            + '<i class="rounded-[3px]"></i>';
        const v = disallowedRoundedTokens(bad);
        expect(v).toContain('rounded');
        expect(v).toContain('rounded-t-md');
        expect(v.some((x) => x.startsWith('rounded-['))).toBe(true);
        // class:rounded directive も検知 (shape を条件 directive で切り替えるのを禁止)
        expect(disallowedRoundedTokens('<div class:rounded={x}>d</div>')).toContain('class:rounded');
        // ramp は誤検知しない
        expect(disallowedRoundedTokens('<div class="rounded-sm rounded-lg rounded-md">x</div>')).toEqual([]);
    });
});
