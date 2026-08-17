/**
 * TS 側の値集合を**型情報から**読む。
 *
 * 受理する形 (**解決・正規化された後の型**についての条件である):
 *   1. 対象ファイルのトップレベルに、その名前の**型別名の宣言**が**ちょうど 1 つ**あること。
 *   2. その宣言が解決する型が、**文字列リテラル型だけ**の union か、単独の文字列リテラル型であること。
 *   3. `ts.TypeFlags.EnumLiteral` を持つ構成要素は**受理しない** (本リポジトリに TypeScript の
 *      `enum` は 1 件も無く、文字列リテラル型と同じ契約ではないため。必要になってから広げる)。
 *
 * **重複は検出しない**。`"a" | "a"` は型検査器が `"a"` へ正規化するため、値集合の側からは
 * 元の重複を観測できない (union の中の `never` も同じく正規化で消える)。
 * **意味の診断は見ない** — 型検査そのものは `pnpm typecheck` の担当で、同じことを 2 箇所で見ない。
 */
import ts from "typescript";
import path from "node:path";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT, type MirrorProgram } from "./program";

export const readTsUnionValues = (
    { program, checker }: MirrorProgram,
    tsFile: string,
    declaration: string,
): ReadonlySet<string> => {
    const where = `${tsFile}::${declaration}`;
    const source = program.getSourceFile(path.join(REPO_ROOT, tsFile));
    if (source === undefined) throw new EnumTsSyncError(where, "TS ファイルが program に載っていません");

    // 構文が壊れていると型解決が黙って縮むので、構文の診断だけは見る。
    if (program.getSyntacticDiagnostics(source).length > 0) {
        throw new EnumTsSyncError(where, "TS ファイルの構文が壊れています");
    }

    const aliases = source.statements
        .filter(ts.isTypeAliasDeclaration)
        .filter((statement) => statement.name.text === declaration);
    if (aliases.length === 0) {
        throw new EnumTsSyncError(
            where,
            "型別名の宣言が見つかりません (受理するのは `type X = …` だけ。定数配列・switch の case ラベル・.svelte 内の宣言は読みません)",
        );
    }
    if (aliases.length > 1) {
        throw new EnumTsSyncError(where, `同名の型別名が ${aliases.length} 件あります`);
    }

    const symbol = checker.getSymbolAtLocation(aliases[0].name);
    if (symbol === undefined) throw new EnumTsSyncError(where, "宣言の記号を解決できません");

    const declared = checker.getDeclaredTypeOfSymbol(symbol);
    const parts = declared.isUnion() ? declared.types : [declared];

    const values = new Set<string>();
    for (const part of parts) {
        if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) {
            throw new EnumTsSyncError(where, `TypeScript の enum の値は受理しません: ${checker.typeToString(part)}`);
        }
        if (!part.isStringLiteral()) {
            throw new EnumTsSyncError(
                where,
                `文字列リテラル型でない構成要素があります: ${checker.typeToString(part)}`,
            );
        }
        values.add(part.value);
    }
    if (values.size === 0) throw new EnumTsSyncError(where, "値を 1 つも取り出せません");

    return values;
};
