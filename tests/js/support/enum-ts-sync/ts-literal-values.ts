/**
 * 値集合の読み取りの最下層。**逆走査と前向きの検査が共有する唯一の抽出器**である
 * (正典 v3 の i4「抽出器を 2 本持たない」)。
 *
 * - `unwrapInitializer` … 丸括弧 / `as` / `satisfies` の包みを剥がし、
 *   値の構文と**明示の型ノード**を別々に返す
 * - `readConstArrayLiteralValues` … `const` 束縛の配列リテラルから値を**構文で**読む
 *   (型検査器の配列型は使わない。素の配列は `string[]` に広げられるため)
 * - `readResolvedStringLiteralUnion` … 型を**型検査器で**解決し、
 *   文字列リテラル型だけの合併なら値集合を返す
 * - `readObjectLiteralKeys` … オブジェクトリテラルのキーを読む
 *   (文字列リテラル / 識別子 / 型検査器が文字列リテラルへ解決する計算キー)
 * - `readSwitchCaseValues` … `default` を除く `case` の式の値を読む
 *
 * どれも**「1 つでも読めない要素があれば読めなかったことにする」**。
 * 「読めない」には 2 種類あり、**形ごとに境界が違う**:
 *
 * | 形 | 受理に型解決が要るか | `not-a-catalogue` | `indeterminate` |
 * |---|---|---|---|
 * | `const-array` | 要らない (構文だけ) | `const` でない / 空配列 / 要素に構文上の文字列リテラル以外がある | **無い** |
 * | `object-keys` | 計算キーだけ要る | 通常の代入でないプロパティ / 計算キーが文字列リテラル型以外へ正常に解決 / 空 | 計算キーの型が `any` / `unknown` |
 * | `literal-union` | 要る | 文字列リテラル型でない構成要素 / `EnumLiteral` を含む | 解決した型が `any` / `unknown` |
 * | `switch-cases` | 要る | `case` の式が文字列リテラル型以外へ正常に解決 / `case` が 0 件 | `case` の式の型が `any` / `unknown` |
 *
 * **定数の配列は構文だけで判定する**ので、識別子や呼び出し式が混ざったら
 * 型解決の成否によらず `not-a-catalogue` である (保留にしない)。
 *
 * `ts.TypeFlags.EnumLiteral` は 4 形すべてで拒否する (本リポジトリに TypeScript の
 * `enum` は 1 件も無く、文字列リテラル型と同じ契約ではない)。
 */
import ts from "typescript";

export type LiteralValuesResult =
    /** 値集合を読めた。 */
    | { readonly kind: "values"; readonly values: ReadonlySet<string> }
    /** 正常に非候補 (読めたうえで対象ではない)。 */
    | { readonly kind: "not-a-catalogue" }
    /** 候補かどうかを決められない (判定保留)。 */
    | { readonly kind: "indeterminate"; readonly reason: string };

const NOT_A_CATALOGUE: LiteralValuesResult = { kind: "not-a-catalogue" };

const values = (set: ReadonlySet<string>): LiteralValuesResult =>
    set.size === 0 ? NOT_A_CATALOGUE : { kind: "values", values: set };

/**
 * 「解決できなかった」だけでなく「明示的に `any` へ正しく解決した」場合も含めて
 * **候補かどうかを確定できない**ことを表す。両者を機械で見分けるには TypeScript の
 * 内部表現へ踏み込む必要があるので、踏み込まずに契約の側を広げてある。
 * **構文が `any` / `unknown` そのものなら正常な非候補**である。
 */
export const isIndeterminateType = (type: ts.Type, node: ts.Node | undefined): boolean =>
    (type.flags & (ts.TypeFlags.Any | ts.TypeFlags.Unknown)) !== 0
    && node !== undefined
    && node.kind !== ts.SyntaxKind.AnyKeyword
    && node.kind !== ts.SyntaxKind.UnknownKeyword;

export interface UnwrappedInitializer {
    /** 包みを剥がした値の構文。 */
    readonly expression: ts.Expression;
    /** `satisfies` の型ノード (一番外側のものを優先)。 */
    readonly satisfiesType: ts.TypeNode | undefined;
}

/** 丸括弧 / `as` / `satisfies` の包みを剥がす。 */
export const unwrapInitializer = (node: ts.Expression): UnwrappedInitializer => {
    let expression = node;
    let satisfiesType: ts.TypeNode | undefined;
    for (;;) {
        if (ts.isParenthesizedExpression(expression)) {
            expression = expression.expression;
            continue;
        }
        if (ts.isAsExpression(expression)) {
            expression = expression.expression;
            continue;
        }
        if (ts.isSatisfiesExpression(expression)) {
            satisfiesType ??= expression.type;
            expression = expression.expression;
            continue;
        }
        return { expression, satisfiesType };
    }
};

/** 解決済みの型から文字列リテラル値の集合を読む。 */
const stringLiteralValues = (type: ts.Type): ReadonlySet<string> | undefined => {
    const parts = type.isUnion() ? type.types : [type];
    const out = new Set<string>();
    for (const part of parts) {
        if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) return undefined;
        if (!part.isStringLiteral()) return undefined;
        out.add(part.value);
    }
    return out;
};

/**
 * `const` 束縛の配列リテラルから値を**構文で**読む。
 * `const X = ["a", "b"];` は型検査器の上では `string[]` に広げられるので、
 * 型から要素を復元してはいけない。
 */
export const readConstArrayLiteralValues = (declaration: ts.VariableDeclaration): LiteralValuesResult => {
    if ((declaration.parent.flags & ts.NodeFlags.Const) === 0) return NOT_A_CATALOGUE;
    if (declaration.initializer === undefined) return NOT_A_CATALOGUE;
    const { expression } = unwrapInitializer(declaration.initializer);
    if (!ts.isArrayLiteralExpression(expression)) return NOT_A_CATALOGUE;
    if (expression.elements.length === 0) return NOT_A_CATALOGUE;

    const out = new Set<string>();
    for (const element of expression.elements) {
        const inner = unwrapInitializer(element).expression;
        if (!ts.isStringLiteral(inner)) return NOT_A_CATALOGUE;
        out.add(inner.text);
    }
    return values(out);
};

/** 型別名の宣言を型検査器で解決し、文字列リテラル型だけの合併なら値集合を返す。 */
export const readResolvedStringLiteralUnion = (
    checker: ts.TypeChecker,
    alias: ts.TypeAliasDeclaration,
): LiteralValuesResult => {
    const symbol = checker.getSymbolAtLocation(alias.name);
    if (symbol === undefined) return { kind: "indeterminate", reason: "型別名の記号を解決できません" };

    const declared = checker.getDeclaredTypeOfSymbol(symbol);
    if (isIndeterminateType(declared, alias.type)) {
        return { kind: "indeterminate", reason: "型別名が any / unknown へ解決しました (構文はその綴りではありません)" };
    }
    const read = stringLiteralValues(declared);
    if (read === undefined) return NOT_A_CATALOGUE;
    return values(read);
};

/** オブジェクトリテラルのキーを読む。 */
export const readObjectLiteralKeys = (
    checker: ts.TypeChecker,
    object: ts.ObjectLiteralExpression,
): LiteralValuesResult => {
    if (object.properties.length === 0) return NOT_A_CATALOGUE;

    const out = new Set<string>();
    let notACatalogue = false;
    for (const property of object.properties) {
        if (!ts.isPropertyAssignment(property)) {
            notACatalogue = true;
            continue;
        }
        const key = property.name;
        if (ts.isStringLiteral(key) || ts.isIdentifier(key)) {
            out.add(key.text);
            continue;
        }
        if (!ts.isComputedPropertyName(key)) {
            notACatalogue = true;
            continue;
        }
        const type = checker.getTypeAtLocation(key.expression);
        if (isIndeterminateType(type, key.expression)) {
            return { kind: "indeterminate", reason: "計算キーが any / unknown へ解決しました" };
        }
        if ((type.flags & ts.TypeFlags.EnumLiteral) !== 0 || !type.isStringLiteral()) {
            notACatalogue = true;
            continue;
        }
        out.add(type.value);
    }
    if (notACatalogue) return NOT_A_CATALOGUE;
    return values(out);
};

/** `default` を除く `case` の式の値を読む。 */
export const readSwitchCaseValues = (checker: ts.TypeChecker, statement: ts.SwitchStatement): LiteralValuesResult => {
    const out = new Set<string>();
    let notACatalogue = false;
    let seen = 0;
    for (const clause of statement.caseBlock.clauses) {
        if (ts.isDefaultClause(clause)) continue;
        seen += 1;
        const type = checker.getTypeAtLocation(clause.expression);
        if (isIndeterminateType(type, clause.expression)) {
            return { kind: "indeterminate", reason: "case の式が any / unknown へ解決しました" };
        }
        if ((type.flags & ts.TypeFlags.EnumLiteral) !== 0 || !type.isStringLiteral()) {
            notACatalogue = true;
            continue;
        }
        out.add(type.value);
    }
    if (seen === 0 || notACatalogue) return NOT_A_CATALOGUE;
    return values(out);
};
