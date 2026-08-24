/**
 * 逆走査の候補走査 (正典 v3 の i7 / i9)。
 *
 * **母集団**は `population.ts` が決める版管理下の `*.ts` / `*.svelte` の全数で、
 * 走査は**所有者の program 上の `SourceFile`** だけを使う
 * (`program.getSourceFiles()` 全体は依存ライブラリ・推移的な取り込み・JSON が載るので
 * 母集団の一致根拠にしない)。
 *
 * **受理する 4 形 (i9)**:
 *
 * | 形 | 受理条件 | 値集合 |
 * |---|---|---|
 * | `literal-union` | 型別名の宣言 (**入れ子も含む**)。解決した型が文字列リテラル型だけ | リテラルの値 |
 * | `const-array` | `const` 束縛の変数宣言で、包みを剥がした初期化子が配列リテラル。要素がすべて文字列リテラル | 要素の値 |
 * | `object-keys` | 変数宣言で、包みを剥がした初期化子がオブジェクトリテラル。キーが読める | キーの綴り |
 * | `switch-cases` | `switch` 文で `default` を除く case がすべて文字列リテラル型へ解決 | case の値 |
 *
 * `object-keys` に `const` を要求しないのは、正典が「オブジェクト (対応表) のキー」としか
 * 言わないためである (`let` の対応表も写しになり得る)。`const-array` にだけ要求するのは
 * 正典の「**定数の**配列」という言い方に合わせたもので、この非対称は意図している。
 *
 * **三値にする (共通規約 (b))**: 「候補かどうかを決められない」を非候補と混ぜない。
 * 判定保留 (`indeterminate`) は候補にも非候補にもせず、利用側の gate が既定拒否の
 * 申告で受ける。
 *
 * **採番 → 分類の順序**: 候補の同一性は `(file, shape, name, occurrence)` の 4 つ組
 * (locator) で持つ。`occurrence` は**三値の分類より前に**、構文上の宣言の場所の全体に
 * 対して振る (候補だけを採番すると、同名で片方が判定保留・片方が候補のときに
 * どちらも 0 になり、非候補を外すと分類が変わっただけで番号が動く)。
 * **採番器 (`buildScanIndex`) は 1 本だけ持ち、逆走査と前向きの解決が共有する**。
 * 行は同一性に入れない (無関係な行移動で申告が一斉に stale になるのを避ける。
 * **行はメッセージにだけ使う**)。
 *
 * **派生の除外**: `object-keys` 形のうち、明示の型があり・文字列の添字シグネチャが無く・
 * プロパティが 1 件以上ですべて必須で・書かれたキーが必須プロパティと集合として一致し・
 * **`object-keys` 以外の形の候補に同じ値集合の証人がある**ものだけを外す。
 * 証人の資格を派生除外の対象になり得ない形に限るのは**循環の遮断**である
 * (任意の候補を証人にすると、同じキー集合の対応表 A と B が互いを証人にして両方消える)。
 *
 * **保証しないもの**: 版管理外のファイルは見ない。`.d.ts` は候補にしない。
 * `.svelte` は script の中だけを見る (目印の中・制御構文の中・スタイルは見ない)。
 * 分割代入の束縛には locator を作らない (4 形はどれも名前付きの 1 つの宣言を前提にする)。
 */
import ts from "typescript";
import { EnumTsSyncError } from "./errors";
import type { MirrorPrograms } from "./program";
import {
    isIndeterminateType,
    readConstArrayLiteralValues,
    readObjectLiteralKeys,
    readResolvedStringLiteralUnion,
    readSwitchCaseValues,
    unwrapInitializer,
} from "./ts-literal-values";

export type TsCandidateShape = "literal-union" | "const-array" | "object-keys" | "switch-cases";

export interface TsCandidateLocator {
    /** リポジトリルートからの相対パス (`.svelte` は仮想ではなく元のパス)。 */
    readonly file: string;
    readonly shape: TsCandidateShape;
    /** 宣言の名前。分岐のラベルは `switch:<判定対象の字面>`。 */
    readonly name: string;
    /** 同じ (file, shape, name) の中の出現順 (0 始まり)。 */
    readonly occurrence: number;
}

export interface TsUnionCandidate {
    readonly locator: TsCandidateLocator;
    /** 元ファイル上の行 (1 始まり)。**同一性には使わない** (メッセージ用)。 */
    readonly line: number;
    /** 最上位の宣言か (前向きの目録が指せるのは最上位だけ)。 */
    readonly topLevel: boolean;
    readonly values: ReadonlySet<string>;
    /** 規則 2 の名前対応に使える名前。決められなければ `null`。 */
    readonly correspondenceName: string | null;
    /** `correspondenceName !== null`。 */
    readonly nameResolved: boolean;
}

/** 候補かどうかを決められなかった宣言 (判定保留)。**同一性は候補と同じ locator**。 */
export interface IndeterminateTsDeclaration {
    readonly locator: TsCandidateLocator;
    readonly line: number;
    readonly reason: string;
}

export interface TsCandidateScan {
    readonly candidates: readonly TsUnionCandidate[];
    readonly indeterminate: readonly IndeterminateTsDeclaration[];
    /** 実際に走査したファイル (リポジトリ相対)。空振り検査に使う。 */
    readonly scannedFiles: ReadonlySet<string>;
}

/** locator の綴り (集合の鍵・メッセージ用)。 */
export const locatorKey = (locator: TsCandidateLocator): string =>
    `${locator.file}|${locator.shape}|${locator.name}|${locator.occurrence}`;

/** 値集合の鍵 (証人の索引に使う)。 */
export const valueSetKey = (set: ReadonlySet<string>): string => [...set].sort().join(" ");

export interface SwitchSubject {
    /** locator 専用。構文が正常なら必ず得られる。 */
    readonly siteName: string;
    /** 規則 2 の名前対応に使える場合だけ値を持つ。 */
    readonly correspondenceName: string | null;
}

/** 名前対応に使ってよい式の形 (識別子 / `this` / それらのプロパティ参照の連なり)。 */
const isNameableExpression = (expression: ts.Expression): boolean =>
    ts.isIdentifier(expression)
    || expression.kind === ts.SyntaxKind.ThisKeyword
    || (ts.isPropertyAccessExpression(expression) && isNameableExpression(expression.expression));

/**
 * 分岐の判定対象の名前。**locator 用の構文名と規則 2 用の解決名を分ける**。
 * locator の名前は必須なので、名前対応に使えない式でも `siteName` は必ず作る。
 */
export const switchSubject = (
    checker: ts.TypeChecker,
    expression: ts.Expression,
    source: ts.SourceFile,
    where: string,
): SwitchSubject => {
    const siteName = `switch:${expression.getText(source).replace(/\s+/g, " ").trim()}`;
    if (siteName === "switch:") throw new EnumTsSyncError(where, "分岐の判定対象の字面が空です");

    const type = checker.getTypeAtLocation(expression);
    const alias =
        type.aliasSymbol?.name
        ?? (type.isUnion()
            ? type.types.map((part) => part.aliasSymbol?.name).find((name) => name !== undefined)
            : undefined);
    if (alias !== undefined) return { siteName, correspondenceName: alias };

    if (isNameableExpression(expression)) return { siteName, correspondenceName: expression.getText(source) };

    return { siteName, correspondenceName: null };
};

/** 自己検査用の薄い入口 (名前対応に使える名前だけを返す)。 */
export const switchSubjectName = (
    checker: ts.TypeChecker,
    expression: ts.Expression,
    source: ts.SourceFile,
): string | null => switchSubject(checker, expression, source, "switch").correspondenceName;

export interface DeclarationSite {
    readonly node: ts.Node;
    readonly shape: TsCandidateShape;
    readonly name: string;
    readonly line: number;
    readonly topLevel: boolean;
    readonly correspondenceName: string | null;
}

export interface ScanIndex {
    readonly file: string;
    readonly sites: readonly DeclarationSite[];
    /** その場所の locator。採番は三値の分類より前に済んでいる。 */
    locatorOf(node: ts.Node): TsCandidateLocator;
}

const lineOf = (source: ts.SourceFile, node: ts.Node): number =>
    source.getLineAndCharacterOfPosition(node.getStart(source)).line + 1;

/** 変数宣言の形 (包みを剥がした初期化子で決まる)。候補にならない形は `undefined`。 */
const variableShape = (declaration: ts.VariableDeclaration): TsCandidateShape | undefined => {
    if (declaration.initializer === undefined) return undefined;
    const { expression } = unwrapInitializer(declaration.initializer);
    if (ts.isArrayLiteralExpression(expression)) return "const-array";
    if (ts.isObjectLiteralExpression(expression)) return "object-keys";
    return undefined;
};

/**
 * 1 ファイル分の宣言の場所を数え上げ、`(file, shape, name)` ごとに
 * **ソース位置の順**で `occurrence` を振る。**三値の判定はまだしない**。
 */
export const buildScanIndex = (source: ts.SourceFile, checker: ts.TypeChecker, file: string): ScanIndex => {
    const sites: DeclarationSite[] = [];

    const visit = (node: ts.Node): void => {
        if (ts.isTypeAliasDeclaration(node)) {
            sites.push({
                node,
                shape: "literal-union",
                name: node.name.text,
                line: lineOf(source, node),
                topLevel: node.parent === source,
                correspondenceName: node.name.text,
            });
        } else if (ts.isVariableDeclaration(node) && ts.isIdentifier(node.name)) {
            const shape = variableShape(node);
            if (shape !== undefined) {
                sites.push({
                    node,
                    shape,
                    name: node.name.text,
                    line: lineOf(source, node),
                    topLevel: node.parent.parent.parent === source,
                    correspondenceName: node.name.text,
                });
            }
        } else if (ts.isSwitchStatement(node)) {
            const subject = switchSubject(checker, node.expression, source, file);
            sites.push({
                node,
                shape: "switch-cases",
                name: subject.siteName,
                line: lineOf(source, node),
                topLevel: node.parent === source,
                correspondenceName: subject.correspondenceName,
            });
        }
        ts.forEachChild(node, visit);
    };
    visit(source);

    sites.sort((a, b) => a.node.getStart(source) - b.node.getStart(source));

    const counters = new Map<string, number>();
    const locators = new Map<ts.Node, TsCandidateLocator>();
    for (const site of sites) {
        const key = `${site.shape}|${site.name}`;
        const occurrence = counters.get(key) ?? 0;
        counters.set(key, occurrence + 1);
        locators.set(site.node, { file, shape: site.shape, name: site.name, occurrence });
    }

    return {
        file,
        sites,
        locatorOf: (node) => {
            const locator = locators.get(node);
            if (locator === undefined) {
                throw new EnumTsSyncError(file, "採番していない宣言の locator を求めました (採番器の母集団から漏れています)");
            }
            return locator;
        },
    };
};

/** 派生の除外に使う「事実」(述語ではなくデータを渡す = 自己検査できる形)。 */
export interface DerivedFacts {
    /** 明示の型 (型注釈 または `satisfies`) があるか。 */
    readonly hasExplicitType: boolean;
    /** その型を解決できたか。 */
    readonly explicitTypeResolved: boolean;
    /** 文字列の添字シグネチャを持つか。 */
    readonly hasStringIndexSignature: boolean;
    /** 任意プロパティを 1 つでも持つか。 */
    readonly hasOptionalProperty: boolean;
    /** 必須プロパティの名前。 */
    readonly requiredKeys: readonly string[];
    /** 実際に書かれたキー。 */
    readonly writtenKeys: readonly string[];
    /** `object-keys` 以外の形に同じ値集合の候補があるか。 */
    readonly witnessed: boolean;
}

const sameSet = (a: readonly string[], b: readonly string[]): boolean =>
    valueSetKey(new Set(a)) === valueSetKey(new Set(b));

/**
 * 対応表のキーを「派生」として候補から外してよいか。
 * **1 つでも欠けたら候補として残す** (fail-closed)。
 */
export const isDerivedObjectKeys = (facts: DerivedFacts): boolean =>
    facts.hasExplicitType
    && facts.explicitTypeResolved
    && !facts.hasStringIndexSignature
    && !facts.hasOptionalProperty
    && facts.requiredKeys.length > 0
    && sameSet(facts.writtenKeys, facts.requiredKeys)
    && facts.witnessed;

/** 証人の索引 (**`object-keys` 以外の形だけ**が証人になれる = 循環の遮断)。 */
export const buildWitnessIndex = (candidates: readonly TsUnionCandidate[]): ReadonlySet<string> =>
    new Set(
        candidates
            .filter((candidate) => candidate.locator.shape !== "object-keys")
            .map((candidate) => valueSetKey(candidate.values)),
    );

interface PendingDerived {
    readonly locator: TsCandidateLocator;
    readonly line: number;
    readonly topLevel: boolean;
    readonly values: ReadonlySet<string>;
    readonly facts: Omit<DerivedFacts, "witnessed">;
}

/** 明示の型から派生判定の事実を集める。 */
const derivedFactsOf = (
    checker: ts.TypeChecker,
    declaration: ts.VariableDeclaration,
    initializer: ts.Expression,
    writtenKeys: readonly string[],
): Omit<DerivedFacts, "witnessed"> => {
    const { satisfiesType } = unwrapInitializer(initializer);
    const typeNode = declaration.type ?? satisfiesType;
    const empty = {
        hasStringIndexSignature: false,
        hasOptionalProperty: false,
        requiredKeys: [] as readonly string[],
        writtenKeys,
    };
    if (typeNode === undefined) {
        return { hasExplicitType: false, explicitTypeResolved: false, ...empty };
    }
    const bound = checker.getTypeFromTypeNode(typeNode);
    if (isIndeterminateType(bound, typeNode)) {
        return { hasExplicitType: true, explicitTypeResolved: false, ...empty };
    }
    const properties = checker.getPropertiesOfType(bound);
    return {
        hasExplicitType: true,
        explicitTypeResolved: true,
        hasStringIndexSignature: checker.getIndexInfoOfType(bound, ts.IndexKind.String) !== undefined,
        hasOptionalProperty: properties.some((symbol) => (symbol.flags & ts.SymbolFlags.Optional) !== 0),
        requiredKeys: properties
            .filter((symbol) => (symbol.flags & ts.SymbolFlags.Optional) === 0)
            .map((symbol) => symbol.name),
        writtenKeys,
    };
};

/** 1 つの宣言の場所を三値へ分類する。 */
const classify = (
    checker: ts.TypeChecker,
    site: DeclarationSite,
): ReturnType<typeof readConstArrayLiteralValues> => {
    if (site.shape === "literal-union") {
        return readResolvedStringLiteralUnion(checker, site.node as ts.TypeAliasDeclaration);
    }
    if (site.shape === "const-array") {
        return readConstArrayLiteralValues(site.node as ts.VariableDeclaration);
    }
    if (site.shape === "switch-cases") {
        return readSwitchCaseValues(checker, site.node as ts.SwitchStatement);
    }
    const declaration = site.node as ts.VariableDeclaration;
    const initializer = declaration.initializer;
    if (initializer === undefined) return { kind: "not-a-catalogue" };
    const { expression } = unwrapInitializer(initializer);
    if (!ts.isObjectLiteralExpression(expression)) return { kind: "not-a-catalogue" };
    return readObjectLiteralKeys(checker, expression);
};

/**
 * 母集団の全ファイルから候補を拾う。**本番の入口**であり、
 * 戦略の差し替え口は持たない (自己検査は輸出した純関数へデータを渡して行う)。
 */
export const collectTsCandidates = (programs: MirrorPrograms): TsCandidateScan => {
    const candidates: TsUnionCandidate[] = [];
    const indeterminate: IndeterminateTsDeclaration[] = [];
    const pending: PendingDerived[] = [];
    const scannedFiles = new Set<string>();

    const population = [...programs.population.ts, ...programs.population.svelte];
    for (const file of population) {
        const mirror = programs.programOf(file);
        const source = programs.sourceOf(file);
        scannedFiles.add(file);

        if (mirror.program.getSyntacticDiagnostics(source).length > 0) {
            throw new EnumTsSyncError(file, "構文が壊れているため候補を読めません (無言で読み飛ばさない)");
        }

        const index = buildScanIndex(source, mirror.checker, file);
        for (const site of index.sites) {
            const locator = index.locatorOf(site.node);
            const result = classify(mirror.checker, site);

            if (result.kind === "not-a-catalogue") continue;
            if (result.kind === "indeterminate") {
                indeterminate.push({ locator, line: site.line, reason: result.reason });
                continue;
            }

            if (site.shape === "object-keys") {
                const declaration = site.node as ts.VariableDeclaration;
                const facts = derivedFactsOf(
                    mirror.checker,
                    declaration,
                    declaration.initializer as ts.Expression,
                    [...result.values],
                );
                if (
                    facts.hasExplicitType
                    && facts.explicitTypeResolved
                    && !facts.hasStringIndexSignature
                    && !facts.hasOptionalProperty
                    && facts.requiredKeys.length > 0
                    && sameSet([...result.values], facts.requiredKeys)
                ) {
                    pending.push({ locator, line: site.line, topLevel: site.topLevel, values: result.values, facts });
                    continue;
                }
            }

            candidates.push({
                locator,
                line: site.line,
                topLevel: site.topLevel,
                values: result.values,
                correspondenceName: site.correspondenceName,
                nameResolved: site.correspondenceName !== null,
            });
        }
    }

    // 第 2 パス: 証人のある派生だけを捨て、無いものは候補へ戻す。
    const witnessIndex = buildWitnessIndex(candidates);
    for (const row of pending) {
        const facts: DerivedFacts = { ...row.facts, witnessed: witnessIndex.has(valueSetKey(row.values)) };
        if (isDerivedObjectKeys(facts)) continue;
        candidates.push({
            locator: row.locator,
            line: row.line,
            topLevel: row.topLevel,
            values: row.values,
            correspondenceName: row.locator.name,
            nameResolved: true,
        });
    }

    return { candidates, indeterminate, scannedFiles };
};
