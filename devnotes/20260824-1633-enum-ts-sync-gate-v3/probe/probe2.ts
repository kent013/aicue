/**
 * 設計用の実測プローブ v2 (devnotes 配下の一時スクリプト。実装物ではない)。
 *
 * 詳細設計レビュー Round 1 の指摘を反映した形で数え直す:
 *  - `.svelte` は **1 ファイルにつき 1 つの仮想 TS** (module と実体の両方を含む) にし、
 *    末尾へ `export {};` を足して**モジュール文脈**にする (成分の大域漏れを塞ぐ)
 *  - **パッケージごとに自前の tsconfig で program を作る** (本番と同じ型世界)
 *  - `as const` / `satisfies` / 丸括弧の包みを剥がす
 *  - 定数配列は `const` 束縛に限る
 *  - 分岐の判定対象は識別子 / プロパティ参照の連なりに限る (それ以外は解析不能)
 *  - 派生除外は「書かれたキー == 明示型の必須プロパティ == 証人の値」の 3 集合一致
 *  - 単数化の規則を修正 (status/statuses/class/classes/policy/policies)
 *  - 型が解決できない (any/unknown なのに構文はそうでない) 候補は**解析不能**として数える
 *
 * 実行: node_modules/.bin/tsx devnotes/20260824-1633-enum-ts-sync-gate-v3/probe/probe2.ts
 */
import ts from "typescript";
import fs from "node:fs";
import path from "node:path";
import { execFileSync } from "node:child_process";
import { parse } from "svelte/compiler";

const REPO_ROOT = path.resolve(import.meta.dirname, "../../..");

const catalogModule = await import(path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/php-enum-catalog.ts"));
const catalog = catalogModule.buildPhpEnumCatalog() as {
    resolved: readonly { path: string; name: string; values: ReadonlySet<string> }[];
    unresolvable: readonly { path: string; reason: string }[];
};
const inventoryModule = await import(path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/mirror-inventory.ts"));
const registeredTs = inventoryModule.registeredTsKeys() as ReadonlySet<string>;

// ---------------------------------------------------------------- 母集団
const tracked = (pattern: string): string[] =>
    execFileSync("git", ["-C", REPO_ROOT, "ls-files", "-z", "--", pattern], { encoding: "utf-8" })
        .split("\0")
        .map((l) => l.trim())
        .filter((l) => l !== "")
        .sort();

const EXCLUDED_ROOTS = ["tests/js/support/enum-ts-sync/fixtures/candidates-broken"];
const isExcluded = (rel: string): boolean =>
    EXCLUDED_ROOTS.some((r) => rel === r || rel.startsWith(`${r}/`));

const trackedTs = tracked("*.ts").filter((f) => !isExcluded(f));
const candidateTs = trackedTs.filter((f) => !f.endsWith(".d.ts"));
const candidateSvelte = tracked("*.svelte").filter((f) => !isExcluded(f));

console.log(`population .ts=${candidateTs.length} (tracked .ts incl .d.ts=${trackedTs.length}) .svelte=${candidateSvelte.length}`);
console.log(`php resolved=${catalog.resolved.length} unresolvable=${catalog.unresolvable.length}`);

// ---------------------------------------------------------------- .svelte → 仮想 TS
const VIRTUAL_SUFFIX = ".__enum_ts_sync_virtual__.ts";
const LINE_TERMINATORS = new Set(["\n", "\r", " ", " "]);
const ALLOWED_SCRIPT_ATTRS = new Set(["lang", "module"]);

const toVirtual = (rel: string, source: string): string => {
    const root = parse(source, { modern: true }) as {
        instance?: { attributes?: { name: string }[]; content?: { start: number; end: number } };
        module?: { attributes?: { name: string }[]; content?: { start: number; end: number } };
    };
    const ranges: [number, number][] = [];
    for (const key of ["module", "instance"] as const) {
        const node = root[key];
        if (node === undefined) continue;
        for (const attr of node.attributes ?? []) {
            if (!ALLOWED_SCRIPT_ATTRS.has(attr.name)) throw new Error(`${rel}: 受理しない script 属性 ${attr.name}`);
        }
        if (node.content === undefined) throw new Error(`${rel}: script の中身の範囲を取れません`);
        ranges.push([node.content.start, node.content.end]);
    }
    const keep = new Uint8Array(source.length);
    for (const [start, end] of ranges) for (let i = start; i < end; i++) keep[i] = 1;
    let out = "";
    for (let i = 0; i < source.length; i++) {
        const ch = source[i];
        out += keep[i] === 1 || LINE_TERMINATORS.has(ch) ? ch : " ";
    }
    // モジュール文脈にして成分の大域漏れを塞ぐ (末尾へ足すので既存の行・列は動かない)。
    return `${out}\nexport {};\n`;
};

const virtualSources = new Map<string, string>();
const virtualToReal = new Map<string, string>();
for (const rel of candidateSvelte) {
    const abs = path.join(REPO_ROOT, rel);
    const virtual = abs + VIRTUAL_SUFFIX;
    virtualSources.set(virtual, toVirtual(rel, fs.readFileSync(abs, "utf-8")));
    virtualToReal.set(virtual, rel);
}

// ---------------------------------------------------------------- program 群
const parseTsconfig = (configPath: string): ts.ParsedCommandLine => {
    const host: ts.ParseConfigFileHost = {
        useCaseSensitiveFileNames: ts.sys.useCaseSensitiveFileNames,
        readDirectory: ts.sys.readDirectory,
        fileExists: ts.sys.fileExists,
        readFile: ts.sys.readFile,
        getCurrentDirectory: () => path.dirname(configPath),
        onUnRecoverableConfigFileDiagnostic: (d) => {
            throw new Error(ts.flattenDiagnosticMessageText(d.messageText, " "));
        },
    };
    const parsed = ts.getParsedCommandLineOfConfigFile(configPath, {}, host);
    if (parsed === undefined) throw new Error(`cannot parse ${configPath}`);
    return parsed;
};

const packageDirs = fs
    .readdirSync(path.join(REPO_ROOT, "packages"), { withFileTypes: true })
    .filter((e) => e.isDirectory() && fs.existsSync(path.join(REPO_ROOT, "packages", e.name, "tsconfig.json")))
    .map((e) => `packages/${e.name}`)
    .sort();

const ownerOf = (rel: string): string => packageDirs.find((d) => rel.startsWith(`${d}/`)) ?? "<root>";

const buildProgram = (
    parsed: ts.ParsedCommandLine,
    extraRoots: readonly string[],
    virtuals: ReadonlyMap<string, string>,
): { program: ts.Program; checker: ts.TypeChecker } => {
    const options: ts.CompilerOptions = {
        ...parsed.options,
        noEmit: true,
        // 出力しないので出力系の設定は落とす (rootDir の外を起点に足せるようにする)。
        rootDir: undefined,
        outDir: undefined,
        declaration: false,
        declarationMap: false,
        composite: false,
        sourceMap: false,
    };
    const base = ts.createCompilerHost(options, true);
    const host: ts.CompilerHost = {
        ...base,
        fileExists: (f) => virtuals.has(f) || base.fileExists(f),
        readFile: (f) => virtuals.get(f) ?? base.readFile(f),
        getSourceFile: (f, lv, onError, shouldCreate) => {
            const v = virtuals.get(f);
            return v !== undefined ? ts.createSourceFile(f, v, lv, true, ts.ScriptKind.TS) : base.getSourceFile(f, lv, onError, shouldCreate);
        },
    };
    const rootNames = [...new Set([...parsed.fileNames, ...extraRoots, ...virtuals.keys()])];
    const program = ts.createProgram({ rootNames, options, host });
    return { program, checker: program.getTypeChecker() };
};

const t0 = Date.now();
const programs = new Map<string, { program: ts.Program; checker: ts.TypeChecker }>();
programs.set(
    "<root>",
    buildProgram(
        parseTsconfig(path.join(REPO_ROOT, "tsconfig.json")),
        trackedTs.filter((f) => ownerOf(f) === "<root>").map((f) => path.join(REPO_ROOT, f)),
        virtualSources,
    ),
);
for (const dir of packageDirs) {
    programs.set(
        dir,
        buildProgram(
            parseTsconfig(path.join(REPO_ROOT, dir, "tsconfig.json")),
            trackedTs.filter((f) => ownerOf(f) === dir).map((f) => path.join(REPO_ROOT, f)),
            new Map(),
        ),
    );
}
console.log(`programs=${[...programs.keys()].join(",")} build ms=${Date.now() - t0}`);

// ---------------------------------------------------------------- 候補抽出
type Shape = "literal-union" | "const-array" | "object-keys" | "switch-cases";
interface Candidate { file: string; name: string; shape: Shape; values: Set<string>; line: number; nameResolved: boolean }

const unresolvable: string[] = [];
const candidates: Candidate[] = [];
const pending: { file: string; name: string; line: number; written: Set<string>; required: Set<string> }[] = [];

const unwrap = (node: ts.Expression): { expr: ts.Expression; satisfiesType: ts.TypeNode | undefined } => {
    let expr = node;
    let satisfiesType: ts.TypeNode | undefined;
    for (;;) {
        if (ts.isParenthesizedExpression(expr)) { expr = expr.expression; continue; }
        if (ts.isAsExpression(expr)) { expr = expr.expression; continue; }
        if (ts.isSatisfiesExpression(expr)) { satisfiesType ??= expr.type; expr = expr.expression; continue; }
        return { expr, satisfiesType };
    }
};

const isUnresolvedType = (t: ts.Type, node: ts.Node | undefined): boolean =>
    (t.flags & (ts.TypeFlags.Any | ts.TypeFlags.Unknown)) !== 0
    && node !== undefined
    && node.kind !== ts.SyntaxKind.AnyKeyword
    && node.kind !== ts.SyntaxKind.UnknownKeyword;

const stringLiterals = (t: ts.Type): Set<string> | undefined => {
    const parts = t.isUnion() ? t.types : [t];
    const out = new Set<string>();
    for (const p of parts) {
        if ((p.flags & ts.TypeFlags.EnumLiteral) !== 0) return undefined;
        if (!p.isStringLiteral()) return undefined;
        out.add(p.value);
    }
    return out.size === 0 ? undefined : out;
};

const subjectName = (checker: ts.TypeChecker, expr: ts.Expression, source: ts.SourceFile): string | null => {
    const type = checker.getTypeAtLocation(expr);
    const alias = type.aliasSymbol?.name
        ?? (type.isUnion() ? type.types.map((t) => t.aliasSymbol?.name).find((n) => n !== undefined) : undefined);
    if (alias !== undefined) return alias;
    const acceptable = (e: ts.Expression): boolean =>
        ts.isIdentifier(e) || e.kind === ts.SyntaxKind.ThisKeyword
        || (ts.isPropertyAccessExpression(e) && acceptable(e.expression));
    if (!acceptable(expr)) return null;
    return expr.getText(source);
};

const relOf = (fileName: string): string =>
    virtualToReal.get(fileName) ?? path.relative(REPO_ROOT, fileName).split(path.sep).join("/");

const scanned = new Set<string>();

for (const [owner, { program, checker }] of programs) {
    const targets = new Set<string>([
        ...candidateTs.filter((f) => ownerOf(f) === owner).map((f) => path.join(REPO_ROOT, f)),
        ...(owner === "<root>" ? [...virtualSources.keys()] : []),
    ]);
    for (const source of program.getSourceFiles()) {
        if (!targets.has(source.fileName)) continue;
        const rel = relOf(source.fileName);
        scanned.add(rel);
        if (program.getSyntacticDiagnostics(source).length > 0) throw new Error(`${rel}: 構文が壊れています`);
        const lineOf = (n: ts.Node): number => source.getLineAndCharacterOfPosition(n.getStart(source)).line + 1;

        const visit = (node: ts.Node): void => {
            if (ts.isTypeAliasDeclaration(node)) {
                const sym = checker.getSymbolAtLocation(node.name);
                if (sym !== undefined) {
                    const t = checker.getDeclaredTypeOfSymbol(sym);
                    if (isUnresolvedType(t, node.type)) {
                        unresolvable.push(`${rel}:${lineOf(node)}::${node.name.text} (型別名が解決できない)`);
                    } else {
                        const v = stringLiterals(t);
                        if (v !== undefined) candidates.push({ file: rel, name: node.name.text, shape: "literal-union", values: v, line: lineOf(node), nameResolved: true });
                    }
                }
            }
            if (ts.isVariableDeclaration(node) && ts.isIdentifier(node.name) && node.initializer !== undefined) {
                const { expr, satisfiesType } = unwrap(node.initializer);
                const isConst = (node.parent.flags & ts.NodeFlags.Const) !== 0;
                if (ts.isArrayLiteralExpression(expr) && isConst) {
                    const v = new Set<string>();
                    let ok = expr.elements.length > 0;
                    for (const el of expr.elements) {
                        const inner = unwrap(el).expr;
                        if (ts.isStringLiteral(inner)) v.add(inner.text);
                        else ok = false;
                    }
                    if (ok) candidates.push({ file: rel, name: node.name.text, shape: "const-array", values: v, line: lineOf(node), nameResolved: true });
                } else if (ts.isObjectLiteralExpression(expr)) {
                    const written = new Set<string>();
                    let ok = expr.properties.length > 0;
                    for (const prop of expr.properties) {
                        if (!ts.isPropertyAssignment(prop)) { ok = false; continue; }
                        const key = prop.name;
                        if (ts.isStringLiteral(key) || ts.isIdentifier(key)) written.add(key.text);
                        else if (ts.isComputedPropertyName(key)) {
                            const kt = checker.getTypeAtLocation(key.expression);
                            if (isUnresolvedType(kt, key.expression)) {
                                unresolvable.push(`${rel}:${lineOf(node)}::${node.name.text} (計算キーが解決できない)`);
                                ok = false;
                            } else if (kt.isStringLiteral()) written.add(kt.value);
                            else ok = false;
                        } else ok = false;
                    }
                    if (ok) {
                        const typeNode = node.type ?? satisfiesType;
                        let required: Set<string> | undefined;
                        if (typeNode !== undefined) {
                            const bound = checker.getTypeFromTypeNode(typeNode);
                            if (!isUnresolvedType(bound, typeNode)
                                && checker.getIndexInfoOfType(bound, ts.IndexKind.String) === undefined) {
                                const props = checker.getPropertiesOfType(bound);
                                if (props.length > 0 && props.every((p) => (p.flags & ts.SymbolFlags.Optional) === 0)) {
                                    required = new Set(props.map((p) => p.name));
                                }
                            }
                        }
                        if (required !== undefined) pending.push({ file: rel, name: node.name.text, line: lineOf(node), written, required });
                        else candidates.push({ file: rel, name: node.name.text, shape: "object-keys", values: written, line: lineOf(node), nameResolved: true });
                    }
                }
            }
            if (ts.isSwitchStatement(node)) {
                const v = new Set<string>();
                let ok = true;
                for (const clause of node.caseBlock.clauses) {
                    if (ts.isDefaultClause(clause)) continue;
                    const t = checker.getTypeAtLocation(clause.expression);
                    if (isUnresolvedType(t, clause.expression)) {
                        unresolvable.push(`${rel}:${lineOf(node)} (case の式が解決できない)`);
                        ok = false;
                    } else if (t.isStringLiteral()) v.add(t.value);
                    else ok = false;
                }
                if (ok && v.size > 0) {
                    const subject = subjectName(checker, node.expression, source);
                    candidates.push({
                        file: rel,
                        name: `switch:${subject ?? node.expression.getText(source).replace(/\s+/g, " ")}`,
                        shape: "switch-cases",
                        values: v,
                        line: lineOf(node),
                        nameResolved: subject !== null,
                    });
                }
            }
            ts.forEachChild(node, visit);
        };
        visit(source);
    }
}

const missing = [...candidateTs, ...candidateSvelte].filter((f) => !scanned.has(f));
if (missing.length > 0) throw new Error(`母集団のうち走査されなかったファイル ${missing.length} 件: ${missing.slice(0, 5).join(", ")}`);

// 派生の除外 (3 集合一致 + 証人は object-keys 以外)
const keyOf = (v: ReadonlySet<string>): string => [...v].sort().join(" ");
const witnessIndex = new Set(candidates.filter((c) => c.shape !== "object-keys").map((c) => keyOf(c.values)));
let excluded = 0;
for (const row of pending) {
    const sameWrittenRequired = keyOf(row.written) === keyOf(row.required);
    if (sameWrittenRequired && witnessIndex.has(keyOf(row.required))) { excluded++; continue; }
    candidates.push({ file: row.file, name: row.name, shape: "object-keys", values: row.written, line: row.line, nameResolved: true });
}

const byShape = new Map<Shape, number>();
for (const c of candidates) byShape.set(c.shape, (byShape.get(c.shape) ?? 0) + 1);
const sum = [...byShape.values()].reduce((a, b) => a + b, 0);
if (sum !== candidates.length) throw new Error("集計不整合");
console.log(`scanned files=${scanned.size} unresolvable=${unresolvable.length}`);
for (const u of unresolvable.slice(0, 20)) console.log(`  [unresolvable] ${u}`);
console.log(`derived pending=${pending.length} excluded(witnessed)=${excluded} kept=${pending.length - excluded}`);
console.log(`candidates total=${candidates.length}`, JSON.stringify(Object.fromEntries(byShape)));

// ---------------------------------------------------------------- 判定式
const strictName = (name: string, enumName: string): string | null => {
    const c = name.toLowerCase();
    const t = enumName.toLowerCase();
    if (c === t) return `厳密名対応 (${t} = ${c})`;
    for (const s of ["s", "es", "values"]) if (c === `${t}${s}`) return `厳密名対応 (${t} + "${s}")`;
    return null;
};

/** 語の候補形の集合。1 つの正規形へ畳まない (誤った語幹を正規形にしないため)。 */
const forms = (w: string): Set<string> => {
    const out = new Set<string>([w]);
    if (w.endsWith("ies") && w.length > 3) out.add(`${w.slice(0, -3)}y`);
    if (w.length > 2 && /(?:s|x|z|ch|sh)es$/.test(w)) out.add(w.slice(0, -2));
    if (w.endsWith("s") && !w.endsWith("ss") && w.length > 1) out.add(w.slice(0, -1));
    return out;
};

/** 2 つの語が対応するか (候補形の集合が交わるか)。 */
const correspond = (a: string, b: string): boolean => {
    const fa = forms(a);
    for (const f of forms(b)) if (fa.has(f)) return true;
    return false;
};

const words = (id: string): string[] =>
    id
        .replace(/^switch:/, "")
        .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
        .replace(/([A-Z]+)([A-Z][a-z])/g, "$1 $2")
        .replace(/([A-Za-z])([0-9])/g, "$1 $2")
        .replace(/([0-9])([A-Za-z])/g, "$1 $2")
        .split(/[^A-Za-z0-9]+/)
        .map((w) => w.toLowerCase())
        .filter((w) => w !== "");

/** 列挙側の語と候補側の語袋の**最大マッチング** (候補側の 1 語を 2 回使わない)。 */
const maxMatching = (enumWords: readonly string[], bag: readonly string[]): number => {
    const matchOf = new Array<number>(bag.length).fill(-1);
    const tryAssign = (i: number, seen: boolean[]): boolean => {
        for (let j = 0; j < bag.length; j++) {
            if (seen[j] || !correspond(enumWords[i], bag[j])) continue;
            seen[j] = true;
            if (matchOf[j] === -1 || tryAssign(matchOf[j], seen)) {
                matchOf[j] = i;
                return true;
            }
        }
        return false;
    };
    let n = 0;
    for (let i = 0; i < enumWords.length; i++) if (tryAssign(i, new Array<boolean>(bag.length).fill(false))) n++;
    return n;
};

// 語の対応の期待値 (対応する 8 組 / 対応しない 2 組)
for (const [a, b, expected] of [
    ["status", "statuses", true], ["class", "classes", true], ["policy", "policies", true],
    ["value", "values", true], ["kind", "kinds", true], ["case", "cases", true],
    ["response", "responses", true], ["use", "uses", true],
    ["status", "state", false], ["code", "codec", false],
] as const) {
    if (correspond(a, b) !== expected) console.log(`  [語の対応 NG] ${a} <-> ${b} = ${correspond(a, b)} (期待 ${expected})`);
}

const baseName = (rel: string): string => (rel.split("/").pop() ?? rel).replace(/\.(ts|svelte|php)$/, "");

const wordName = (c: Candidate, enumName: string): string | null => {
    const decl = words(c.name);
    if (decl.length === 0) throw new Error(`${c.file}::${c.name}: 宣言名から語を取り出せません`);
    const bag = [...new Set([...decl, ...words(baseName(c.file))])];
    const en = words(enumName);
    if (en.length === 0) return null;
    if (!correspond(decl[decl.length - 1], en[en.length - 1])) return null;
    const shared = maxMatching(en, bag);
    if (shared < Math.min(2, en.length)) return null;
    return `語対応 ${shared}/${en.length} 語 主要語=${en[en.length - 1]}`;
};

const inter = (a: ReadonlySet<string>, b: ReadonlySet<string>): number => {
    let n = 0;
    for (const v of a) if (b.has(v)) n++;
    return n;
};

const undecidable: string[] = [];
const hits: { rule: string; php: string; file: string; name: string; shape: Shape; line: number; detail: string }[] = [];
for (const c of candidates) {
    if (registeredTs.has(`${c.file}::${c.name}`)) continue;
    for (const e of catalog.resolved) {
        const n = inter(e.values, c.values);
        if (n === e.values.size && n === c.values.size) {
            hits.push({ rule: "1", php: e.path, file: c.file, name: c.name, shape: c.shape, line: c.line, detail: "完全一致" });
            continue;
        }
        if (n === 0) continue;
        if (!c.nameResolved) {
            undecidable.push(`${e.path} <-> ${c.file}:${c.line}::${c.name} (交差 ${n} 値。判定対象の名前を解決できないので規則 2 を判定できない)`);
            continue;
        }
        const s = strictName(c.name.replace(/^switch:/, ""), e.name);
        if (s !== null) { hits.push({ rule: "2a", php: e.path, file: c.file, name: c.name, shape: c.shape, line: c.line, detail: `${s} / 交差 ${n} 値` }); continue; }
        if (!(n * 2 >= e.values.size && n * 2 >= c.values.size)) continue;
        const w = wordName(c, e.name);
        if (w === null) continue;
        hits.push({ rule: "2b", php: e.path, file: c.file, name: c.name, shape: c.shape, line: c.line, detail: `${w} / 交差 ${n} 値` });
    }
}

const byRule = new Map<string, number>();
for (const h of hits) byRule.set(h.rule, (byRule.get(h.rule) ?? 0) + 1);
console.log(`undecidable(名前解決不能かつ交差あり)=${undecidable.length}`);
for (const u of undecidable) console.log(`  [undecidable] ${u}`);
console.log(`hits total=${hits.length}`, JSON.stringify(Object.fromEntries(byRule)));
for (const h of hits.sort((a, b) => a.rule.localeCompare(b.rule) || a.file.localeCompare(b.file))) {
    console.log(`  [規則${h.rule}] ${h.php} <-> ${h.file}:${h.line}::${h.name} (${h.shape}) ${h.detail}`);
}
