/**
 * `resources/js/` 配下にある**文字列リテラル型だけの union に解決する型別名**を
 * 全数走査する (裁定 AG-099 後半 / 逆走査の入力)。
 *
 * `readTsUnionValues` (`ts-value-sets.ts`) は「目録に登録した 1 つの宣言」を読む検査で、
 * 受理できない形は例外にして呼び出し側の登録ミスを知らせる。本モジュールは向きが逆で、
 * **プログラム全体から候補を拾う**。**型別名 1 つずつの受理・拒否は黙って読み飛ばす**
 * (「型別名だが対象にならない」は前者では失敗、後者では単に非対象という違いである) が、
 * **ファイル単位の構文診断は無言で読み飛ばさない**。構文が壊れたファイルは中の型別名が
 * 正しく読めているか判別できないため、その 1 点だけは例外にして gate を失敗させる
 * (AGENTS.md §静的検査の共通規約 (b) fail-closed)。
 *
 * **母集団の実体**: `resources/js/` 配下の走査対象は `program.getSourceFiles()` から
 * `.ts` の**通常ファイルだけ**を取る (`source.isDeclarationFile` で `.d.ts` を除く)。
 * `program` は `createMirrorProgram()` が `tsconfig.json` の `include`/`exclude` から組むが、
 * **それだけを母集団の出典とは言わない** — `resources/js/` をプログラムを介さず
 * 直接再帰的に歩いた `*.ts` (`.d.ts` を除く) の集合と、program に載った集合が
 * **完全一致すること**を独立実装の回帰テストで固定しており、この一致こそが
 * 「呼び出し時に渡す `tsFiles` 引数に依存しない・`exclude` が意図せず広がっていない」
 * という不変条件の実体である (`enum-ts-sync-discovery-extractor.test.ts` の
 * 「走査した非宣言ファイルの集合は、ファイルシステムを直接歩いた集合と一致する」テスト)。
 *
 * **保証しないもの**: 対象は `resources/js/` 配下の `.ts` ファイルのトップレベルにある
 * `type X = …` 宣言だけ。`.svelte` の中の宣言・定数配列・switch の case ラベル・
 * ネストした (トップレベルでない) 型別名は対象外。**`.d.ts` (宣言ファイル) も対象外**
 * (`vite-env.d.ts` 以外に手書きの `.d.ts` が増えても、その中の literal union は読まない)。
 */
import ts from "typescript";
import path from "node:path";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT, type MirrorProgram } from "./program";

export interface TsUnionCandidate {
    /** リポジトリルートからの相対パス。 */
    readonly file: string;
    /** 型別名の名前。 */
    readonly name: string;
    readonly values: ReadonlySet<string>;
}

/** `root` の配下にあるか (区切り文字まで含めて見る。兄弟ディレクトリを通さない)。 */
const isUnder = (absolute: string, root: string): boolean => absolute === root || absolute.startsWith(root + path.sep);

/** 解決した型が文字列リテラル型だけの union (または単独) なら値集合を返す。それ以外は `undefined`。 */
const tryReadStringLiteralUnion = (checker: ts.TypeChecker, alias: ts.TypeAliasDeclaration): ReadonlySet<string> | undefined => {
    const symbol = checker.getSymbolAtLocation(alias.name);
    if (symbol === undefined) return undefined;

    const declared = checker.getDeclaredTypeOfSymbol(symbol);
    const parts = declared.isUnion() ? declared.types : [declared];

    const values = new Set<string>();
    for (const part of parts) {
        if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) return undefined;
        if (!part.isStringLiteral()) return undefined;
        values.add(part.value);
    }
    if (values.size === 0) return undefined;
    return values;
};

/**
 * `resources/js/` 配下の全 `.ts` ファイルから、文字列リテラル型だけの union に解決する
 * トップレベルの型別名をすべて拾う。
 *
 * @param jsRoot 走査根 (既定は `resources/js`。負のコントロール専用の引数)
 */
export const collectTsUnionCandidates = (
    { program, checker }: MirrorProgram,
    jsRoot: string = path.join(REPO_ROOT, "resources", "js"),
): readonly TsUnionCandidate[] => {
    const candidates: TsUnionCandidate[] = [];

    for (const source of program.getSourceFiles()) {
        if (source.isDeclarationFile) continue;
        if (!isUnder(source.fileName, jsRoot)) continue;

        const where = path.relative(REPO_ROOT, source.fileName).split(path.sep).join("/");
        if (program.getSyntacticDiagnostics(source).length > 0) {
            throw new EnumTsSyncError(where, "構文が壊れているため候補を読めません (無言で読み飛ばさない)");
        }

        for (const statement of source.statements) {
            if (!ts.isTypeAliasDeclaration(statement)) continue;
            const values = tryReadStringLiteralUnion(checker, statement);
            if (values === undefined) continue;
            candidates.push({
                file: where,
                name: statement.name.text,
                values,
            });
        }
    }

    return candidates;
};
