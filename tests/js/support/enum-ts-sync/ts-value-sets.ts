/**
 * TS 側の値集合を**登録した 1 つの宣言について**読む (前向きの検査)。
 *
 * 受理する形は **2 つ**である:
 *   1. 対象ファイルの**最上位**にある**型別名の宣言** (解決した型が文字列リテラル型だけ)
 *   2. 対象ファイルの**最上位**にある **`const` 束縛の配列** (`as const` の有無を問わない)
 *
 * 同じ名前で受理できる宣言が**ちょうど 1 つ**あることを要求する (0 件・2 件以上は例外)。
 *
 * **値の読み取りは `ts-literal-values.ts` の共有抽出器を使う** (逆走査と同じ 1 本)。
 * とくに配列は**構文から読む** — `const X = ["a", "b"];` は型検査器の上では `string[]` に
 * 広げられるので、型から要素を復元してはいけない。`satisfies` を付けても対象型によって
 * 広げられ得るので、**受理の判断は常に配列リテラルの構文**から行う。
 *
 * **対応表のキーと分岐のラベルは登録できない**。写しとして扱うなら型別名か定数の配列へ
 * 切り出す (失敗メッセージにもそう書く)。
 *
 * **登録行の locator は AST から解決する** — 目録の行が持つのは `ts + declaration` だけで、
 * locator に要る `shape` と `occurrence` が無い。同名の入れ子の宣言が最上位より前にあると
 * 最上位でも `occurrence` は 0 とは限らないため、**逆走査と同じ採番器 (`buildScanIndex`)**
 * でその節の locator を求める (採番の実装を 2 本持たない)。
 *
 * **重複は検出しない**。`"a" | "a"` は型検査器が `"a"` へ正規化するため、値集合の側からは
 * 元の重複を観測できない。**意味の診断は見ない** — 型検査そのものは `pnpm typecheck` の担当。
 */
import ts from "typescript";
import path from "node:path";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT, type MirrorProgram, type MirrorPrograms } from "./program";
import { VIRTUAL_SUFFIX } from "./svelte-source";
import { buildScanIndex, type TsCandidateLocator } from "./ts-candidates";
import {
    readConstArrayLiteralValues,
    readResolvedStringLiteralUnion,
    unwrapInitializer,
} from "./ts-literal-values";

export interface ResolvedTsDeclaration {
    readonly locator: TsCandidateLocator;
    readonly values: ReadonlySet<string>;
}

/** 受理できる宣言の候補 (型別名 または最上位の配列束縛)。 */
type AcceptableDeclaration = ts.TypeAliasDeclaration | ts.VariableDeclaration;

const sourceFileOf = (mirror: MirrorProgram, tsFile: string, where: string): ts.SourceFile => {
    const absolute = path.join(REPO_ROOT, tsFile);
    if (tsFile.endsWith(".svelte")) {
        const virtual = absolute + VIRTUAL_SUFFIX;
        const source = mirror.program.getSourceFile(virtual);
        if (source === undefined) {
            throw new EnumTsSyncError(where, ".svelte の仮想単位が program にありません (仮想化されていません)");
        }
        return source;
    }
    const source = mirror.program.getSourceFile(absolute);
    if (source === undefined) throw new EnumTsSyncError(where, "TS ファイルが program に載っていません");
    return source;
};

const acceptableDeclarations = (source: ts.SourceFile, declaration: string): readonly AcceptableDeclaration[] => {
    const found: AcceptableDeclaration[] = [];
    for (const statement of source.statements) {
        if (ts.isTypeAliasDeclaration(statement) && statement.name.text === declaration) {
            found.push(statement);
            continue;
        }
        if (!ts.isVariableStatement(statement)) continue;
        for (const variable of statement.declarationList.declarations) {
            if (!ts.isIdentifier(variable.name) || variable.name.text !== declaration) continue;
            if (variable.initializer === undefined) continue;
            if (!ts.isArrayLiteralExpression(unwrapInitializer(variable.initializer).expression)) continue;
            found.push(variable);
        }
    }
    return found;
};

/**
 * 型別名が「正常な非候補」だったときに、**なぜ受理しないのか**を前向きの言葉にする。
 * **値集合は作らない** (値の読み取りは共有抽出器の 1 本だけが行う)。
 */
const diagnoseTypeAlias = (checker: ts.TypeChecker, alias: ts.TypeAliasDeclaration): string => {
    const symbol = checker.getSymbolAtLocation(alias.name);
    if (symbol === undefined) return "宣言の記号を解決できません";

    const declared = checker.getDeclaredTypeOfSymbol(symbol);
    const parts = declared.isUnion() ? declared.types : [declared];
    for (const part of parts) {
        if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) {
            return `TypeScript の enum の値は受理しません: ${checker.typeToString(part)}`;
        }
        if (!part.isStringLiteral()) {
            return `文字列リテラル型でない構成要素があります: ${checker.typeToString(part)}`;
        }
    }
    return "値を 1 つも取り出せません";
};

/**
 * 登録した 1 つの宣言の値集合と locator を解決する。
 * **値集合の比較より先に locator を解決する** — 値が食い違っていても登録済みの locator の
 * 母集団は変わらず、前向きの診断と逆走査が同じ解決結果を共有できる。
 */
export const resolveTsDeclaration = (
    mirror: MirrorProgram,
    tsFile: string,
    declaration: string,
): ResolvedTsDeclaration => {
    const where = `${tsFile}::${declaration}`;
    const source = sourceFileOf(mirror, tsFile, where);

    // 構文が壊れていると型解決が黙って縮むので、構文の診断だけは見る。
    if (mirror.program.getSyntacticDiagnostics(source).length > 0) {
        throw new EnumTsSyncError(where, "TS ファイルの構文が壊れています");
    }

    const found = acceptableDeclarations(source, declaration);
    if (found.length === 0) {
        throw new EnumTsSyncError(
            where,
            "受理できる宣言が見つかりません (受理するのは最上位の型別名の宣言か const の配列だけ。対応表のキーと分岐のラベルは登録できないので、写しなら型別名か定数の配列へ切り出すこと)",
        );
    }
    if (found.length > 1) {
        throw new EnumTsSyncError(where, `同名の受理できる宣言が ${found.length} 件あります`);
    }

    const node = found[0];
    const locator = buildScanIndex(source, mirror.checker, tsFile).locatorOf(node);

    if (ts.isTypeAliasDeclaration(node)) {
        // **値集合は共有抽出器だけが作る** (読み方を 2 本持たない)。
        // ここでやるのは前向き固有の診断への翻訳だけである。
        const result = readResolvedStringLiteralUnion(mirror.checker, node);
        if (result.kind === "values") return { locator, values: result.values };
        if (result.kind === "indeterminate") throw new EnumTsSyncError(where, result.reason);
        throw new EnumTsSyncError(where, diagnoseTypeAlias(mirror.checker, node));
    }

    const result = readConstArrayLiteralValues(node);
    if (result.kind !== "values") {
        throw new EnumTsSyncError(
            where,
            "const の配列として受理できません (const 束縛であり、要素が 1 件以上あり、すべて構文上の文字列リテラルであること)",
        );
    }
    return { locator, values: result.values };
};

/** 値集合だけを読む薄い入口 (負例行列が使う)。 */
export const readTsUnionValues = (
    mirror: MirrorProgram,
    tsFile: string,
    declaration: string,
): ReadonlySet<string> => resolveTsDeclaration(mirror, tsFile, declaration).values;

/** 目録の行を解決した結果 (前向きの判定と逆走査の登録済み判定が共有する)。 */
export interface ResolvedEnumTsRelation<E extends { readonly ts: string; readonly declaration: string }> {
    readonly entry: E;
    readonly tsLocator: TsCandidateLocator;
    readonly tsValues: ReadonlySet<string>;
}

/** 目録の全行を所有者の program 上で解決する。 */
export const resolveRelations = <E extends { readonly ts: string; readonly declaration: string }>(
    programs: MirrorPrograms,
    rows: readonly E[],
): readonly ResolvedEnumTsRelation<E>[] =>
    rows.map((entry) => {
        const resolved = resolveTsDeclaration(programs.programOf(entry.ts), entry.ts, entry.declaration);
        return { entry, tsLocator: resolved.locator, tsValues: resolved.values };
    });
