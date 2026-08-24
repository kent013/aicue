/**
 * 設計用の実測プローブ (devnotes 配下の一時スクリプト。実装物ではない)。
 *
 * 目的:
 *  (1) 逆走査の母集団を「版管理下の *.ts / *.svelte 全数」へ広げ、候補の形を 4 種にしたとき、
 *      現物ツリーで候補が何件出るかを数える (未決論点 q2 の実測)。
 *  (2) 規則 2 を「厳密名対応 + 1 値交差」と「語分割名対応 + 両側半分以上交差」の
 *      論理和にしたとき、規則ごとに何件鳴るかを数える。
 *  (3) 見本 (fixtures) を母集団に含めたときに何が起きるかを確認する。
 *
 * 実行: node_modules/.bin/tsx devnotes/20260824-1633-enum-ts-sync-gate-v3/probe/probe.ts
 */
import ts from "typescript";
import fs from "node:fs";
import path from "node:path";
import { execFileSync } from "node:child_process";

const REPO_ROOT = path.resolve(import.meta.dirname, "../../..");

// ---------------------------------------------------------------- PHP 側 (既存走査器を再利用)
const catalogModule = await import(
    path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/php-enum-catalog.ts")
);
const catalog = catalogModule.buildPhpEnumCatalog() as {
    resolved: readonly { path: string; name: string; values: ReadonlySet<string> }[];
    unresolvable: readonly { path: string; reason: string }[];
};

const inventoryModule = await import(
    path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/mirror-inventory.ts")
);
const registeredTs = inventoryModule.registeredTsKeys() as ReadonlySet<string>;

// ---------------------------------------------------------------- 母集団
const tracked = (patterns: string[]): string[] =>
    execFileSync("git", ["-C", REPO_ROOT, "ls-files", "--", ...patterns], { encoding: "utf-8" })
        .split("\n")
        .map((l) => l.trim())
        .filter((l) => l !== "")
        .sort();

const trackedTs = tracked(["*.ts"]);
const trackedSvelte = tracked(["*.svelte"]);

const FIXTURE_ROOTS = ["tests/js/support/enum-ts-sync/fixtures/candidates-broken/"];
const isFixture = (rel: string): boolean => FIXTURE_ROOTS.some((r) => rel.startsWith(r));

const MODE = process.argv[2] ?? "excluded"; // "excluded" | "included"
const includeFixtures = MODE === "included";

const tsFiles = trackedTs.filter((f) => includeFixtures || !isFixture(f));
const svelteFiles = trackedSvelte.filter((f) => includeFixtures || !isFixture(f));

console.log(`# mode=${MODE}`);
console.log(`tracked .ts=${trackedTs.length} .svelte=${trackedSvelte.length}`);
console.log(`population .ts=${tsFiles.length} .svelte=${svelteFiles.length}`);
console.log(`php resolved=${catalog.resolved.length} unresolvable=${catalog.unresolvable.length}`);

// ---------------------------------------------------------------- .svelte → 仮想 TS (行番号保存)
/** <script> の中身だけを残し、他の行を空白で潰す (行番号は元ファイルと一致する)。 */
const svelteToVirtualTs = (source: string): string => {
    const out = new Array<string>(source.length).fill("");
    const chars = source.split("");
    const keep = new Array<boolean>(chars.length).fill(false);
    const re = /<script\b[^>]*>([\s\S]*?)<\/script>/gi;
    let m: RegExpExecArray | null;
    while ((m = re.exec(source)) !== null) {
        const bodyStart = m.index + m[0].indexOf(">") + 1;
        const bodyEnd = bodyStart + m[1].length;
        for (let i = bodyStart; i < bodyEnd; i++) keep[i] = true;
    }
    for (let i = 0; i < chars.length; i++) {
        out[i] = keep[i] ? chars[i] : chars[i] === "\n" ? "\n" : " ";
    }
    return out.join("");
};

const virtualSources = new Map<string, string>(); // absolute virtual path -> content
const virtualToReal = new Map<string, string>();
for (const rel of svelteFiles) {
    const abs = path.join(REPO_ROOT, rel);
    const virtual = `${abs}.ts`;
    virtualSources.set(virtual, svelteToVirtualTs(fs.readFileSync(abs, "utf-8")));
    virtualToReal.set(virtual, rel);
}

// ---------------------------------------------------------------- program
const parseTsconfig = (configPath: string): ts.ParsedCommandLine => {
    const host: ts.ParseConfigFileHost = {
        useCaseSensitiveFileNames: ts.sys.useCaseSensitiveFileNames,
        readDirectory: ts.sys.readDirectory,
        fileExists: ts.sys.fileExists,
        readFile: ts.sys.readFile,
        getCurrentDirectory: () => REPO_ROOT,
        onUnRecoverableConfigFileDiagnostic: (d) => {
            throw new Error(ts.flattenDiagnosticMessageText(d.messageText, " "));
        },
    };
    const parsed = ts.getParsedCommandLineOfConfigFile(configPath, {}, host);
    if (parsed === undefined) throw new Error(`cannot parse ${configPath}`);
    return parsed;
};

const parsed = parseTsconfig(path.join(REPO_ROOT, "tsconfig.json"));

const rootNames = [
    ...new Set([
        ...parsed.fileNames,
        ...tsFiles.map((f) => path.join(REPO_ROOT, f)),
        ...virtualSources.keys(),
    ]),
];

const baseHost = ts.createCompilerHost({ ...parsed.options, noEmit: true }, true);
const host: ts.CompilerHost = {
    ...baseHost,
    fileExists: (f) => virtualSources.has(f) || baseHost.fileExists(f),
    readFile: (f) => virtualSources.get(f) ?? baseHost.readFile(f),
    getSourceFile: (f, languageVersion, onError, shouldCreate) => {
        const virtual = virtualSources.get(f);
        if (virtual !== undefined) {
            return ts.createSourceFile(f, virtual, languageVersion, true, ts.ScriptKind.TS);
        }
        return baseHost.getSourceFile(f, languageVersion, onError, shouldCreate);
    },
};

const t0 = Date.now();
const program = ts.createProgram({ rootNames, options: { ...parsed.options, noEmit: true }, host });
const checker = program.getTypeChecker();
console.log(`program build ms=${Date.now() - t0} sourceFiles=${program.getSourceFiles().length}`);

// ---------------------------------------------------------------- 候補抽出 (4 種)
interface Candidate {
    file: string;
    name: string;
    shape: "union" | "const-array" | "object-keys" | "switch-cases";
    values: Set<string>;
    line: number;
}

const stringLiteralsOfType = (type: ts.Type): Set<string> | undefined => {
    const parts = type.isUnion() ? type.types : [type];
    const values = new Set<string>();
    for (const part of parts) {
        if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) return undefined;
        if (!part.isStringLiteral()) return undefined;
        values.add(part.value);
    }
    return values.size === 0 ? undefined : values;
};

const relOf = (fileName: string): string => {
    const virtualReal = virtualToReal.get(fileName);
    if (virtualReal !== undefined) return virtualReal;
    return path.relative(REPO_ROOT, fileName).split(path.sep).join("/");
};

const lineOf = (source: ts.SourceFile, node: ts.Node): number =>
    source.getLineAndCharacterOfPosition(node.getStart(source)).line + 1;

const candidates: Candidate[] = [];
const populationAbs = new Set<string>([
    ...tsFiles.map((f) => path.join(REPO_ROOT, f)),
    ...virtualSources.keys(),
]);

let brokenSyntax: string[] = [];
const derivedRows: { id: string; keys: Set<string>; file: string; name: string; line: number }[] = [];

for (const source of program.getSourceFiles()) {
    if (!populationAbs.has(source.fileName)) continue;
    if (source.isDeclarationFile) continue;
    const rel = relOf(source.fileName);
    if (program.getSyntacticDiagnostics(source).length > 0) {
        brokenSyntax.push(rel);
        continue;
    }

    const visit = (node: ts.Node): void => {
        // (1) 型別名 = リテラル型の合併
        if (ts.isTypeAliasDeclaration(node)) {
            const symbol = checker.getSymbolAtLocation(node.name);
            if (symbol !== undefined) {
                const values = stringLiteralsOfType(checker.getDeclaredTypeOfSymbol(symbol));
                if (values !== undefined) {
                    candidates.push({ file: rel, name: node.name.text, shape: "union", values, line: lineOf(source, node) });
                }
            }
        }
        // (2) 定数配列 / (3) オブジェクトのキー
        if (ts.isVariableDeclaration(node) && ts.isIdentifier(node.name) && node.initializer !== undefined) {
            const init = ts.isSatisfiesExpression(node.initializer) ? node.initializer.expression : node.initializer;
            const satisfiesType = ts.isSatisfiesExpression(node.initializer) ? node.initializer.type : undefined;
            if (ts.isArrayLiteralExpression(init)) {
                const values = new Set<string>();
                let ok = init.elements.length > 0;
                for (const el of init.elements) {
                    const inner = ts.isAsExpression(el) ? el.expression : el;
                    if (ts.isStringLiteral(inner)) values.add(inner.text);
                    else ok = false;
                }
                if (ok && values.size > 0) {
                    candidates.push({ file: rel, name: node.name.text, shape: "const-array", values, line: lineOf(source, node) });
                }
            } else if (ts.isObjectLiteralExpression(init)) {
                // 派生の除外: 明示の型 (注釈 / satisfies) があり、その型が文字列の
                // 添字シグネチャを持たない = キーが有限の名前付き型に束縛されている場合、
                // 値をその場で決めていないので候補にしない。
                const annotation = node.type ?? (ts.isSatisfiesExpression(init) ? undefined : undefined);
                let boundType: ts.Type | undefined;
                if (node.type !== undefined) boundType = checker.getTypeFromTypeNode(node.type);
                else if (satisfiesType !== undefined) boundType = checker.getTypeFromTypeNode(satisfiesType);
                if (boundType !== undefined && checker.getIndexInfoOfType(boundType, ts.IndexKind.String) === undefined) {
                    const props = checker.getPropertiesOfType(boundType);
                    const allRequired = props.length > 0 && props.every((p) => (p.flags & ts.SymbolFlags.Optional) === 0);
                    if (allRequired) {
                        const keys = new Set(props.map((p) => p.name));
                        derivedRows.push({ id: `${rel}::${node.name.text}`, keys, file: rel, name: node.name.text, line: lineOf(source, node) });
                        ts.forEachChild(node, visit);
                        return;
                    }
                }
                void annotation;
                const values = new Set<string>();
                let ok = init.properties.length > 0;
                for (const prop of init.properties) {
                    if (!ts.isPropertyAssignment(prop)) { ok = false; continue; }
                    const key = prop.name;
                    if (ts.isStringLiteral(key)) values.add(key.text);
                    else if (ts.isIdentifier(key)) values.add(key.text);
                    else if (ts.isComputedPropertyName(key)) {
                        const t = checker.getTypeAtLocation(key.expression);
                        if (t.isStringLiteral()) values.add(t.value);
                        else ok = false;
                    } else ok = false;
                }
                if (ok && values.size > 0) {
                    candidates.push({ file: rel, name: node.name.text, shape: "object-keys", values, line: lineOf(source, node) });
                }
            }
        }
        // (4) switch の case ラベル
        if (ts.isSwitchStatement(node)) {
            const values = new Set<string>();
            let ok = true;
            for (const clause of node.caseBlock.clauses) {
                if (ts.isDefaultClause(clause)) continue;
                const t = checker.getTypeAtLocation(clause.expression);
                if (t.isStringLiteral()) values.add(t.value);
                else ok = false;
            }
            if (ok && values.size > 0) {
                const subjectType = checker.getTypeAtLocation(node.expression);
                const aliasName = subjectType.aliasSymbol?.name
                    ?? (subjectType.isUnion() ? subjectType.types.map((t) => t.aliasSymbol?.name).find((n) => n !== undefined) : undefined);
                const subject = aliasName ?? node.expression.getText(source);
                candidates.push({ file: rel, name: `switch:${subject}`, shape: "switch-cases", values, line: lineOf(source, node) });
            }
        }
        ts.forEachChild(node, visit);
    };
    visit(source);
}

const keyOf = (v: ReadonlySet<string>): string => [...v].sort().join("\u0000");
// 証人は「対応表のキー形**以外**の候補」に限る (対応表同士が互いを証人にして
// 全件消える循環を構造で塞ぐ。単調な到達判定になる)。
const witnessIndex = new Set(candidates.filter((c) => c.shape !== "object-keys").map((c) => keyOf(c.values)));
const witnessed = derivedRows.filter((d) => witnessIndex.has(keyOf(d.keys)));
const witnessless = derivedRows.filter((d) => !witnessIndex.has(keyOf(d.keys)));
for (const w of witnessless) {
    candidates.push({ file: w.file, name: w.name, shape: "object-keys", values: w.keys, line: w.line });
}
console.log(`derived(object-keys)=${derivedRows.length} witnessed(excluded)=${witnessed.length} witnessless(kept)=${witnessless.length}`);

console.log(`broken syntax files=${brokenSyntax.length} ${brokenSyntax.join(",")}`);
const byShape = new Map<string, number>();
for (const c of candidates) byShape.set(c.shape, (byShape.get(c.shape) ?? 0) + 1);
const shapeSum = [...byShape.values()].reduce((a, b) => a + b, 0);
if (shapeSum !== candidates.length) throw new Error(`集計不整合: ${shapeSum} !== ${candidates.length}`);
console.log(`candidates total=${candidates.length}`, JSON.stringify(Object.fromEntries(byShape)));

// ---------------------------------------------------------------- 判定式
const normalize = (s: string): string => s.toLowerCase();

const strictNameCorrespondence = (candidateName: string, enumName: string): string | null => {
    const c = normalize(candidateName);
    const t = normalize(enumName);
    if (c === t) return `${t} = ${c}`;
    for (const suffix of ["s", "es", "values"]) if (c === `${t}${suffix}`) return `${t}+${suffix}`;
    return null;
};

const splitWords = (identifier: string): string[] =>
    identifier
        .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
        .replace(/([A-Z]+)([A-Z][a-z])/g, "$1 $2")
        .split(/[^A-Za-z0-9]+/)
        .map((w) => w.toLowerCase())
        .filter((w) => w !== "")
        .map((w) => (w.endsWith("ies") && w.length > 3 ? `${w.slice(0, -3)}y` : w.endsWith("es") && w.length > 2 && !w.endsWith("ses") ? w.slice(0, -2) : w.endsWith("s") && !w.endsWith("ss") && w.length > 1 ? w.slice(0, -1) : w));

const baseName = (rel: string): string => {
    const base = rel.split("/").pop() ?? rel;
    return base.replace(/\.(ts|svelte|php)$/, "");
};

const wordNameCorrespondence = (candidate: Candidate, enumName: string): string | null => {
    const declWords = splitWords(candidate.name.replace(/^switch:/, ""));
    if (declWords.length === 0) return null;
    const fileWords = splitWords(baseName(candidate.file));
    const bag = new Set([...declWords, ...fileWords]);
    const enumWords = splitWords(enumName);
    if (enumWords.length === 0) return null;

    const head = declWords[declWords.length - 1];
    const enumHead = enumWords[enumWords.length - 1];
    if (head !== enumHead) return null;

    const shared = enumWords.filter((w) => bag.has(w));
    const required = Math.min(2, enumWords.length);
    if (shared.length < required) return null;
    return `words[${shared.join("+")}] head=${head}`;
};

const intersectionSize = (a: ReadonlySet<string>, b: ReadonlySet<string>): number => {
    let n = 0;
    for (const v of a) if (b.has(v)) n++;
    return n;
};
const sameValueSet = (a: ReadonlySet<string>, b: ReadonlySet<string>): boolean =>
    a.size === b.size && intersectionSize(a, b) === a.size;

interface Hit { rule: string; php: string; file: string; name: string; shape: string; line: number; detail: string; }
const hits: Hit[] = [];

for (const candidate of candidates) {
    const registered = registeredTs.has(`${candidate.file}::${candidate.name}`);
    if (registered) continue;
    for (const phpEnum of catalog.resolved) {
        if (sameValueSet(phpEnum.values, candidate.values)) {
            hits.push({ rule: "1", php: phpEnum.path, file: candidate.file, name: candidate.name, shape: candidate.shape, line: candidate.line, detail: "exact" });
            continue;
        }
        const inter = intersectionSize(phpEnum.values, candidate.values);
        if (inter === 0) continue;

        const strict = strictNameCorrespondence(candidate.name.replace(/^switch:/, ""), phpEnum.name);
        if (strict !== null) {
            hits.push({ rule: "2a", php: phpEnum.path, file: candidate.file, name: candidate.name, shape: candidate.shape, line: candidate.line, detail: strict });
            continue;
        }
        const halfBoth = inter * 2 >= phpEnum.values.size && inter * 2 >= candidate.values.size;
        if (!halfBoth) continue;
        const words = wordNameCorrespondence(candidate, phpEnum.name);
        if (words === null) continue;
        hits.push({ rule: "2b", php: phpEnum.path, file: candidate.file, name: candidate.name, shape: candidate.shape, line: candidate.line, detail: words });
    }
}

const byRule = new Map<string, number>();
for (const h of hits) byRule.set(h.rule, (byRule.get(h.rule) ?? 0) + 1);
console.log(`hits total=${hits.length}`, JSON.stringify(Object.fromEntries(byRule)));
for (const h of hits.sort((a, b) => a.rule.localeCompare(b.rule) || a.file.localeCompare(b.file))) {
    console.log(`  [rule ${h.rule}] ${h.php} <-> ${h.file}:${h.line}::${h.name} (${h.shape}) ${h.detail}`);
}
