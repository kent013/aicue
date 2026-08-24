/**
 * `.svelte` を第一級の解析対象にする (正典 v3 の i6)。
 *
 * `svelte/compiler` の `parse` (解析ツール向けの入口) で script の範囲を取り、
 * **script の中身以外を空白で潰した**仮想 TypeScript を **1 ファイルにつき 1 本**作る。
 * 潰すときに **UTF-16 の符号単位の数を変えない**ので、行も列も元ファイルと一致する。
 * 改行と認識される文字 (LF / CR / U+2028 / U+2029) はそのまま残す。
 *
 * 末尾に `\nexport {};\n` を足して**モジュール文脈**にする。付けないと仮想ファイルが
 * 大域スクリプトになり、取り込みも書き出しも無いコンポーネント同士の宣言が**混ざる**
 * (実測は `devnotes/20260824-1633-enum-ts-sync-gate-v3/probe/measurements.md`)。
 * 必ず `\n` を前に付けるのは、元のソースが改行で終わらない / 末尾が行注釈のときに
 * `export {};` が注釈へ吸われるのを防ぐためである。末尾へ足すので既存の行も列も動かない。
 *
 * **文脈ごとに別ファイルへ割らない**。割ると module の宣言を実体側から参照できなくなる
 * (Svelte では参照できる)。代わりに、1 本へ平坦化すると再現できない 2 つを
 * **保証外にせず不合格にする**:
 *
 * | 食い違い | Svelte 本来 | 平坦化した TS | 対処 |
 * |---|---|---|---|
 * | module から実体側の宣言を参照 | 見えない | 前方参照として解決する | 不合格 (検査 B) |
 * | module と実体に同名の最上位束縛 | 実体側が覆う | 重複宣言になる | 不合格 (検査 A) |
 * | 実体から module の宣言を参照 | 見える | 解決する | 正しいので許す |
 *
 * **不合格にするもの (fail-closed)**:
 * - `parse` が失敗した (`.svelte` 全体の構文が壊れている)。
 *   script の外 (目印・制御構文・スタイル) は候補にしないが、
 *   **ファイル全体が `parse` できることは前提**である
 * - script の属性が受理表 (`describeScriptAttributes`) の外
 * - script の中身の範囲を取れない
 * - module と実体に同名の最上位束縛がある (検査 A)
 * - module 側が実体側の宣言を参照している (検査 B。program 構築の一本道で必ず走る)
 *
 * **検査 B の呼び出し義務は利用側に無い** — `createMirrorPrograms()` と
 * `createFixtureProgram()` が program を組んだ直後に全仮想単位へ必ず走らせる
 * (低層の組み立て関数は輸出しないので、検査を飛ばした program を外から作れない)。
 *
 * **保証しないもの**: 目印の中の式 (`{…}`)、`{#if}` などの制御構文の中、
 * スタイルの中は候補にしない。`lang="js"` は TS として読む (過剰検出の向き)。
 */
import ts from "typescript";
import path from "node:path";
import { parse } from "svelte/compiler";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT } from "./repo-root";

/**
 * 仮想ファイルの綴り。素朴な `.ts` の付加は採らない
 * (`*.svelte.ts` が実在し得るため実在ファイルと衝突する)。
 */
export const VIRTUAL_SUFFIX = ".__enum_ts_sync_virtual__.ts";

export interface SvelteVirtualUnit {
    /** 元の `.svelte` のリポジトリ相対パス。 */
    readonly source: string;
    /** program に載せる仮想の絶対パス。 */
    readonly virtualPath: string;
    /** 行・列を保った仮想 TS。 */
    readonly text: string;
    readonly moduleRange: readonly [number, number] | null;
    readonly instanceRange: readonly [number, number] | null;
}

/** 改行と認識される文字 (潰さずに残す)。 */
const LINE_TERMINATORS = new Set(["\n", "\r", "\u2028", "\u2029"]);

interface ParsedScript {
    readonly attributes?: readonly { readonly name: string; readonly value: unknown }[];
    readonly content?: { readonly start: number; readonly end: number };
}

interface ParsedSvelte {
    readonly module?: ParsedScript;
    readonly instance?: ParsedScript;
}

/** 受理する `lang` の値 (`js` も TS として読む = 過剰検出の向き)。 */
const ACCEPTED_LANGS = new Set(["ts", "js"]);

const attributeText = (value: unknown): string | true | null => {
    if (value === true) return true;
    if (!Array.isArray(value) || value.length !== 1) return null;
    const first: unknown = value[0];
    if (typeof first !== "object" || first === null) return null;
    const data = (first as { readonly data?: unknown }).data;
    return typeof data === "string" ? data : null;
};

/**
 * script の属性の受理表。受理するのは `lang="ts"` / `lang="js"` / 属性なし /
 * module 文脈での値なし `module` だけである。
 */
const assertAcceptedAttributes = (where: string, context: "module" | "instance", script: ParsedScript): void => {
    for (const attribute of script.attributes ?? []) {
        const value = attributeText(attribute.value);
        if (attribute.name === "lang") {
            if (typeof value !== "string" || !ACCEPTED_LANGS.has(value)) {
                throw new EnumTsSyncError(where, `受理しない script の lang です: ${String(value)}`);
            }
            continue;
        }
        if (attribute.name === "module") {
            if (context !== "module") throw new EnumTsSyncError(where, "実体の script に module 属性は受理しません");
            if (value !== true) throw new EnumTsSyncError(where, "値つきの module 属性は受理しません");
            continue;
        }
        throw new EnumTsSyncError(where, `受理しない script 属性です: ${attribute.name}`);
    }
};

/** 仮想パス → 元の `.svelte` のリポジトリ相対パス。仮想でなければ `undefined`。 */
export const realPathOfVirtual = (virtualPath: string): string | undefined => {
    if (!virtualPath.endsWith(VIRTUAL_SUFFIX)) return undefined;
    const absolute = virtualPath.slice(0, -VIRTUAL_SUFFIX.length);
    return path.relative(REPO_ROOT, absolute).split(path.sep).join("/");
};

/** 最上位の束縛名を集める (束縛を作る構文を網羅する)。 */
export const topLevelBindingNames = (statements: readonly ts.Statement[]): ReadonlySet<string> => {
    const names = new Set<string>();

    const addBindingName = (name: ts.BindingName): void => {
        if (ts.isIdentifier(name)) {
            names.add(name.text);
            return;
        }
        for (const element of name.elements) {
            if (ts.isBindingElement(element)) addBindingName(element.name);
        }
    };

    for (const statement of statements) {
        if (ts.isVariableStatement(statement)) {
            for (const declaration of statement.declarationList.declarations) addBindingName(declaration.name);
            continue;
        }
        if (
            ts.isFunctionDeclaration(statement)
            || ts.isClassDeclaration(statement)
            || ts.isEnumDeclaration(statement)
            || ts.isInterfaceDeclaration(statement)
            || ts.isTypeAliasDeclaration(statement)
            || ts.isModuleDeclaration(statement)
        ) {
            const name = statement.name;
            if (name !== undefined && ts.isIdentifier(name)) names.add(name.text);
            continue;
        }
        if (ts.isImportEqualsDeclaration(statement)) {
            names.add(statement.name.text);
            continue;
        }
        if (ts.isImportDeclaration(statement)) {
            const clause = statement.importClause;
            if (clause === undefined) continue;
            if (clause.name !== undefined) names.add(clause.name.text);
            const bindings = clause.namedBindings;
            if (bindings === undefined) continue;
            if (ts.isNamespaceImport(bindings)) names.add(bindings.name.text);
            else for (const element of bindings.elements) names.add(element.name.text);
        }
    }

    return names;
};

const withinRange = (position: number, range: readonly [number, number] | null): boolean =>
    range !== null && position >= range[0] && position < range[1];

/**
 * `.svelte` の中身を仮想 TS 単位へ変換する**純関数**。
 *
 * @param relativePath 元の `.svelte` のリポジトリ相対パス
 * @param source       元の `.svelte` の中身
 */
export const toVirtualUnit = (relativePath: string, source: string): SvelteVirtualUnit => {
    const where = relativePath;

    let root: ParsedSvelte;
    try {
        root = parse(source, { modern: true }) as unknown as ParsedSvelte;
    } catch (error) {
        throw new EnumTsSyncError(where, `.svelte の構文を読めません: ${error instanceof Error ? error.message : String(error)}`);
    }

    const ranges: { readonly context: "module" | "instance"; readonly range: readonly [number, number] }[] = [];
    for (const context of ["module", "instance"] as const) {
        const script = root[context];
        if (script === undefined) continue;
        assertAcceptedAttributes(where, context, script);
        if (script.content === undefined) throw new EnumTsSyncError(where, `${context} の script の中身の範囲を取れません`);
        ranges.push({ context, range: [script.content.start, script.content.end] });
    }

    const keep = new Uint8Array(source.length);
    for (const { range } of ranges) {
        for (let index = range[0]; index < range[1]; index += 1) keep[index] = 1;
    }

    let blanked = "";
    for (let index = 0; index < source.length; index += 1) {
        const character = source[index];
        blanked += keep[index] === 1 || LINE_TERMINATORS.has(character) ? character : " ";
    }
    // モジュール文脈にする (末尾へ足すので既存の行も列も動かない)。
    const text = `${blanked}\nexport {};\n`;

    const moduleRange = ranges.find((r) => r.context === "module")?.range ?? null;
    const instanceRange = ranges.find((r) => r.context === "instance")?.range ?? null;

    // 検査 A: module と実体に同名の最上位束縛があると shadowing を再現できない。
    const virtualPath = path.join(REPO_ROOT, relativePath) + VIRTUAL_SUFFIX;
    const parsed = ts.createSourceFile(virtualPath, text, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
    const moduleStatements: ts.Statement[] = [];
    const instanceStatements: ts.Statement[] = [];
    for (const statement of parsed.statements) {
        const start = statement.getStart(parsed);
        if (withinRange(start, moduleRange)) moduleStatements.push(statement);
        else if (withinRange(start, instanceRange)) instanceStatements.push(statement);
    }
    const moduleNames = topLevelBindingNames(moduleStatements);
    const shared = [...topLevelBindingNames(instanceStatements)].filter((name) => moduleNames.has(name)).sort();
    if (shared.length > 0) {
        throw new EnumTsSyncError(
            where,
            `module と実体に同名の最上位束縛があります (平坦化では shadowing を再現できません): ${shared.join(", ")}`,
        );
    }

    return { source: relativePath, virtualPath, text, moduleRange, instanceRange };
};

/**
 * 検査 B: module 範囲の中の識別子が実体範囲の宣言を指していないこと。
 * **`createMirrorPrograms()` / `createFixtureProgram()` が内部で必ず実行する**。
 */
export const assertNoModuleToInstanceReference = (
    checker: ts.TypeChecker,
    file: ts.SourceFile,
    unit: SvelteVirtualUnit,
): void => {
    const { moduleRange, instanceRange } = unit;
    if (moduleRange === null || instanceRange === null) return;

    const declaredInInstance = (symbol: ts.Symbol | undefined): boolean =>
        (symbol?.declarations ?? []).some(
            (declaration) =>
                declaration.getSourceFile() === file && withinRange(declaration.getStart(file), instanceRange),
        );

    const visit = (node: ts.Node): void => {
        if (ts.isIdentifier(node)) {
            const symbol = checker.getSymbolAtLocation(node);
            const aliased =
                symbol !== undefined && (symbol.flags & ts.SymbolFlags.Alias) !== 0
                    ? checker.getAliasedSymbol(symbol)
                    : undefined;
            if (declaredInInstance(symbol) || declaredInInstance(aliased)) {
                throw new EnumTsSyncError(
                    unit.source,
                    `module の script が実体側の宣言 (${node.text}) を参照しています (Svelte では見えないので平坦化を認めません)`,
                );
            }
        }
        ts.forEachChild(node, visit);
    };

    for (const statement of file.statements) {
        if (withinRange(statement.getStart(file), moduleRange)) visit(statement);
    }
};

/**
 * 仮想パスの綴りが**版管理下に実在しない**ことを検査する。
 * 実在すると仮想単位が本物のファイルを覆い隠す (または逆に覆われる)。
 * `*.svelte.ts` が実在し得るので素朴な `.ts` の付加を採らないのはこのためである。
 */
export const assertNoVirtualPathCollision = (
    units: readonly SvelteVirtualUnit[],
    trackedFiles: readonly string[],
): void => {
    const tracked = new Set(trackedFiles);
    for (const unit of units) {
        const relative = `${unit.source}${VIRTUAL_SUFFIX}`;
        if (tracked.has(relative)) {
            throw new EnumTsSyncError(unit.source, `仮想パスの綴りが版管理下のファイルと衝突しています: ${relative}`);
        }
    }
};
