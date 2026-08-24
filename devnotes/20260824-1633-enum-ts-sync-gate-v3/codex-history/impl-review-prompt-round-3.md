# Round 3: Round 2 の指摘への対応

Round 2 の Critical 1 件と Warning 6 件すべてに対応した。再レビューをお願いする。

とくに次を見てほしい:

1. Critical (列挙名側の語の非空) の直し方で、規則 2b から「黙って消える」経路が残っていないか
2. 所属 (`ownerNameOf`) と解決 (`resolveOwner`) の分離が、回帰を実際に検出できる形か
3. `docs/architecture.md` の主張が実装と 1:1 になったか (誇張が残っていないか)

---

## 対応マトリクス

# 実装レビュー Round 2 の対応マトリクス (Claude 側)

| # | 区分 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Critical | 語へ分割できない **PHP 列挙名**が規則 2b から黙って消える (`enumWords.length === 0` が `null` を返す) | **対応する** | `wordNameCorrespondence()` の `enumWords` 空も**例外**にし、`matchReverseRule()` では**候補側と列挙側の両方**を交差条件の早期 return より前で見るようにした。`ReverseSweepNameError` に対象 (`宣言名` / `列挙名`) を持たせ、交差が半分未満・半分以上の**両方**を負例で固定した |
| 2 | Warning | 内部矛盾の分岐 (`nameResolved === true && correspondenceName === null`) に負例が無い | **対応する** | 手組みの候補で例外になることを固定した |
| 3 | Warning | `program.ts` の冒頭 docblock が「直和検査が赤くなる」のまま | **対応する** | 「所有者の解決 (`resolveOwner()`) で例外になる。起点の重複・欠落は別の検査」へ書き直した |
| 4 | Warning | `findExcludedSurvivors()` が `.svelte` の**読み込み失敗**まで「期待した構文不正」として吸収する | **対応する** | `fs.readFileSync()` を `try` の外へ出し、捕捉するのは `toVirtualUnit()` の拒否だけにした |
| 5 | Warning | tsconfig なしパッケージの「所有者解決で落ちる分岐」を直接試験していない (所属を tsconfig で絞る回帰を検出できない) | **対応する** | 所属と解決を純関数 `ownerNameOf()` / `resolveOwner()` へ切り出し、`createMirrorPrograms()` はそれを呼ぶだけにした。「所属は `packages/without-config` と決まるが program が無いので例外」を直接固定した (所属を tsconfig で絞る実装へ戻すと `ownerNameOf` が `<root>` を返してこの試験が赤くなる) |
| 6 | Warning | `docs/architecture.md` に弱めた主張と強い旧主張が同居している | **対応する** | 節の冒頭を「ルート設定で読むと `any` へ落ちる**恐れ**がある。ただしこの解決の失敗は現物では観測されていない。機械が固定するのは (a) パッケージの設定で組まれた program に載ること (b) どのファイルもちょうど 1 本の起点に載ること の 2 つ」へ統一し、重複していた後段の記述を畳んだ。「保証しないもの」側も「tsconfig なしは**所有者の解決時**に落ちる / 直和検査は別」へ直した |
| 7 | Warning | `composer test` のクリーンなフル実行が未完了 | **対応する** | 他のレーンを止めて再実行した (結果は最終報告に記載) |

## `tests/js/support/enum-ts-sync/reverse-sweep.ts` (全文)

```ts
/**
 * 逆走査の突き合わせ (正典 v3 の i10)。
 *
 * `enum-ts-sync.test.ts` は「目録に登録した関係について PHP → TS を見る」向きの検査なので、
 * **登録し忘れた写し**は素通りする。本モジュールは向きを変え、TS 側の候補
 * (`collectTsCandidates`) と PHP の文字列付き列挙の母集団 (`buildPhpEnumCatalog`) を
 * 突き合わせ、次の規則で「未登録だが対応していそうな組」を検出する。
 *
 * - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全に一致する未登録の宣言
 * - **規則 2a (厳密名対応 + 1 値以上の交差)**: 名前が小文字化して一致 / `+s` / `+es` /
 *   `+values` で対応し、値集合が交差するが完全一致ではない宣言
 * - **規則 2b (語分割名対応 + 両側から見て半分以上の交差)**: 名前を語に割って対応を見る
 *
 * **規則 2 は 2a と 2b の論理和**であり、**どちらの式も他方を包含しない**
 * (家系の未決論点 q2 に対する本リポジトリの一次観測。実測は
 * `devnotes/20260824-1633-enum-ts-sync-gate-v3/probe/measurements.md`)。
 *
 * **判定の順序 (排他)**:
 * 1. 値集合が完全一致 → 規則 1
 * 2. 交差が 0 なら何もしない
 * 3. 名前を決められない (`nameResolved` が偽) → **判定不能** (gate を赤くする)
 * 4. 2a の名前対応が成立 → 規則 2a
 * 5. 2b の名前対応と交差条件を満たす → 規則 2b
 * 6. どれでもなければ鳴らさない
 *
 * **語の区切りの宣言 (AGENTS.md §共通規約 (e))**: 語に割る文字は `_` `-` `.` `:` `$` と
 * 空白類。加えて**大文字の境界**(小文字または数字 → 大文字 / 大文字の連なり → 大文字 + 小文字)
 * と**数字の境界**(英字 ↔ 数字) でも割る。割った後は空の要素を捨て、すべて小文字化する。
 *
 * **正規化は「1 つの正規形へ畳む」形を採らない**。接尾辞だけで畳むと
 * `cases → cas` / `uses → us` のように誤った語幹を正規形にしてしまう。代わりに
 * 語ごとに候補形の集合 (`wordForms`) を作り、**集合が交われば同じ語**とみなす。
 * これは過剰検出の向きへ倒した判定であり、鳴った先は申告で逃がせる。
 * **これ以上の語形変化 (不規則変化・語幹の交替) は扱わない**。
 *
 * **これは「登録漏れが無いことの証明」ではなく「候補の検出」である**。
 * 名前も対応せず値も半分未満しか交差しない drift 済みの写しは検出できない (意図した限界)。
 */
import { EnumTsSyncError } from "./errors";
import type { ResolvedPhpEnum } from "./php-enum-catalog";
import type { TsCandidateLocator, TsUnionCandidate } from "./ts-candidates";
import { locatorKey } from "./ts-candidates";

/** 適用した規則。申告の同一性に含める (規則が変わったら申告は stale になる)。 */
export type ReverseSweepRule = "1" | "2a" | "2b";

export interface UnregisteredMirrorCandidate {
    readonly rule: ReverseSweepRule;
    readonly php: ResolvedPhpEnum;
    readonly candidate: TsUnionCandidate;
    /** 鳴った理由 (どの規則・どの語・どの値の交差で鳴ったか)。 */
    readonly reason: string;
    readonly onlyInPhp: readonly string[];
    readonly onlyInTs: readonly string[];
}

/** 名前を決められないので規則 2 を判定できなかった組 (gate を赤くする)。 */
export interface UndecidableMirrorPair {
    readonly php: ResolvedPhpEnum;
    readonly candidate: TsUnionCandidate;
    readonly intersectionSize: number;
}

export interface ReverseSweepResult {
    readonly found: readonly UnregisteredMirrorCandidate[];
    readonly undecidable: readonly UndecidableMirrorPair[];
}

export type ReverseRuleOutcome =
    | { readonly kind: "match"; readonly rule: ReverseSweepRule; readonly reason: string }
    | { readonly kind: "undecidable"; readonly intersectionSize: number }
    | { readonly kind: "none" };

/** ファイル名の語幹を取る (テストの見本構築用。判定本体は `ResolvedPhpEnum.name` を使う)。 */
export const shortEnumName = (path: string): string => {
    const base = path.split("/").pop() ?? path;
    return base.endsWith(".php") ? base.slice(0, -".php".length) : base;
};

/** 分岐のラベルの `switch:` は**両規則の共通の前処理**で外す。 */
const stripSwitchPrefix = (name: string): string => name.replace(/^switch:/, "");

/**
 * 厳密な名前対応 (一致 / `+s` / `+es` / `+values`)。
 * 小文字化して比較し、**英数字以外は除去しない**
 * (`_` や `$` まで消すと `Foo_Bar` と `FooBar` を同一視してしまう)。
 */
export const strictNameCorrespondence = (candidateName: string, enumName: string): string | null => {
    const candidate = candidateName.toLowerCase();
    const target = enumName.toLowerCase();
    if (candidate === target) return `厳密名対応 (${target} = ${candidate})`;
    for (const suffix of ["s", "es", "values"]) {
        if (candidate === `${target}${suffix}`) return `厳密名対応 (${target} + "${suffix}" = ${candidate})`;
    }
    return null;
};

/** 語の候補形の集合。**1 つの正規形へ畳まない** (誤った語幹を正規形にしないため)。 */
export const wordForms = (word: string): ReadonlySet<string> => {
    const forms = new Set<string>([word]);
    if (word.endsWith("ies") && word.length > 3) forms.add(`${word.slice(0, -3)}y`);
    if (word.length > 2 && /(?:s|x|z|ch|sh)es$/.test(word)) forms.add(word.slice(0, -2));
    if (word.endsWith("s") && !word.endsWith("ss") && word.length > 1) forms.add(word.slice(0, -1));
    return forms;
};

/** 2 つの語が対応するか (候補形の集合が交わるか)。**推移律は持たない**。 */
export const correspondWords = (a: string, b: string): boolean => {
    const formsOfA = wordForms(a);
    for (const form of wordForms(b)) if (formsOfA.has(form)) return true;
    return false;
};

/** 識別子を語に割る (区切りの宣言は本モジュールの docblock)。 */
export const splitWords = (identifier: string): readonly string[] =>
    stripSwitchPrefix(identifier)
        .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
        .replace(/([A-Z]+)([A-Z][a-z])/g, "$1 $2")
        .replace(/([A-Za-z])([0-9])/g, "$1 $2")
        .replace(/([0-9])([A-Za-z])/g, "$1 $2")
        .split(/[^A-Za-z0-9]+/)
        .map((word) => word.toLowerCase())
        .filter((word) => word !== "");

/**
 * 列挙側の語と候補側の語袋の**最大マッチング** (候補側の 1 語を 2 回使わない)。
 * 「列挙の各語について語袋のどれかと対応するか」を単純に数えると候補側の 1 語が
 * 使い回されるが、`correspondWords` は推移律を持たないので同値類にも畳めない。
 */
export const maxWordMatching = (enumWords: readonly string[], bag: readonly string[]): number => {
    const matchOf = new Array<number>(bag.length).fill(-1);
    const tryAssign = (index: number, seen: boolean[]): boolean => {
        for (let j = 0; j < bag.length; j += 1) {
            if (seen[j] || !correspondWords(enumWords[index], bag[j])) continue;
            seen[j] = true;
            if (matchOf[j] === -1 || tryAssign(matchOf[j], seen)) {
                matchOf[j] = index;
                return true;
            }
        }
        return false;
    };
    let matched = 0;
    for (let i = 0; i < enumWords.length; i += 1) {
        if (tryAssign(i, new Array<boolean>(bag.length).fill(false))) matched += 1;
    }
    return matched;
};

/** ファイル名の語幹 (拡張子を除いた basename)。 */
const baseNameOf = (relative: string): string =>
    (relative.split("/").pop() ?? relative).replace(/\.(ts|svelte|php)$/, "");

/**
 * 語に割れない名前を**静かに名前不一致へ混ぜない**ための例外。
 * 候補側 (宣言名) と PHP 側 (列挙名) の**両方**が対象である
 * (どちらか片方だけを見ると、もう片方が空のときに規則 2b から黙って消える)。
 */
export class ReverseSweepNameError extends Error {
    constructor(where: string, subject: "宣言名" | "列挙名") {
        super(`${where}: ${subject}から語を 1 つも取り出せません`);
        this.name = "ReverseSweepNameError";
    }
}

/**
 * 語に分けた名前対応 (2b)。
 * 候補側の語袋 = 宣言名の語 ∪ ファイル名の語。**主要語は宣言名の語列の末尾**
 * (ファイル名の語は主要語に使わない)。列挙の語と語袋の最大マッチングが
 * `min(2, 列挙の語数)` 以上であることを要求する。
 */
export const wordNameCorrespondence = (
    candidateName: string,
    candidateFile: string,
    enumName: string,
    where: string,
): string | null => {
    const declarationWords = splitWords(candidateName);
    if (declarationWords.length === 0) throw new ReverseSweepNameError(where, "宣言名");

    const bag = [...new Set([...declarationWords, ...splitWords(baseNameOf(candidateFile))])];
    const enumWords = splitWords(enumName);
    // **列挙名が語に割れないことも例外にする** — `null` を返すと「名前が対応しない」と
    // 区別できず、規則 2b から黙って消える (Codex 実装レビュー Round 2 の Critical)。
    if (enumWords.length === 0) throw new ReverseSweepNameError(where, "列挙名");

    const candidateHead = declarationWords[declarationWords.length - 1];
    const enumHead = enumWords[enumWords.length - 1];
    if (!correspondWords(candidateHead, enumHead)) return null;

    const shared = maxWordMatching(enumWords, bag);
    if (shared < Math.min(2, enumWords.length)) return null;
    return `語対応 ${shared}/${enumWords.length} 語 主要語=${enumHead}`;
};

const intersectionSizeOf = (a: ReadonlySet<string>, b: ReadonlySet<string>): number => {
    let size = 0;
    for (const value of a) if (b.has(value)) size += 1;
    return size;
};

/** 1 組の突き合わせ (自己検査の対象になる純関数)。 */
export const matchReverseRule = (php: ResolvedPhpEnum, candidate: TsUnionCandidate): ReverseRuleOutcome => {
    const size = intersectionSizeOf(php.values, candidate.values);
    if (size === php.values.size && size === candidate.values.size) {
        return { kind: "match", rule: "1", reason: "完全一致" };
    }
    if (size === 0) return { kind: "none" };
    if (!candidate.nameResolved) return { kind: "undecidable", intersectionSize: size };

    const where = `${candidate.locator.file}::${candidate.locator.name}`;
    const name = candidate.correspondenceName;
    if (name === null) {
        // `nameResolved` が真なのに名前が無いのは候補の作り方の内部矛盾である
        // (無言で名前不一致へ混ぜない)。
        throw new EnumTsSyncError(where, "nameResolved が真なのに名前対応に使う名前がありません");
    }

    const stripped = stripSwitchPrefix(name);
    const strict = strictNameCorrespondence(stripped, php.name);
    if (strict !== null) return { kind: "match", rule: "2a", reason: `${strict} / 交差 ${size} 値` };

    // **語の非空は交差条件より前に、候補側と列挙側の両方を見る** — 後ろに置くと、
    // 交差が半分未満の組では「語を 1 つも取り出せない名前」が判定されないまま
    // 黙って通ってしまう (Codex 実装レビュー Round 1 / Round 2 の Critical)。
    if (splitWords(stripped).length === 0) throw new ReverseSweepNameError(where, "宣言名");
    if (splitWords(php.name).length === 0) throw new ReverseSweepNameError(where, "列挙名");

    // 交差条件 (両側それぞれの要素数の半分以上。ceil 側で切り上げ)。
    if (!(size * 2 >= php.values.size && size * 2 >= candidate.values.size)) return { kind: "none" };

    const words = wordNameCorrespondence(stripped, candidate.locator.file, php.name, where);
    if (words === null) return { kind: "none" };
    return { kind: "match", rule: "2b", reason: `${words} / 交差 ${size} 値` };
};

const difference = (a: ReadonlySet<string>, b: ReadonlySet<string>): readonly string[] =>
    [...a].filter((value) => !b.has(value)).sort();

/**
 * 未登録の関係の候補を検出する。
 *
 * @param phpEnums     母集団のうち値集合が読めた PHP 列挙 (`resolved`)
 * @param candidates   TS 側の候補
 * @param isRegistered locator が既に目録へ登録済みかを判定する述語
 */
export const findUnregisteredMirrorCandidates = (
    phpEnums: readonly ResolvedPhpEnum[],
    candidates: readonly TsUnionCandidate[],
    isRegistered: (locator: TsCandidateLocator) => boolean,
): ReverseSweepResult => {
    const found: UnregisteredMirrorCandidate[] = [];
    const undecidable: UndecidableMirrorPair[] = [];

    for (const candidate of candidates) {
        if (isRegistered(candidate.locator)) continue;

        for (const php of phpEnums) {
            const outcome = matchReverseRule(php, candidate);
            if (outcome.kind === "none") continue;
            if (outcome.kind === "undecidable") {
                undecidable.push({ php, candidate, intersectionSize: outcome.intersectionSize });
                continue;
            }
            found.push({
                rule: outcome.rule,
                php,
                candidate,
                reason: outcome.reason,
                onlyInPhp: difference(php.values, candidate.values),
                onlyInTs: difference(candidate.values, php.values),
            });
        }
    }

    return { found, undecidable };
};

/** 申告の同一性 (`php` + 候補の locator + `rule`)。 */
export interface ReverseSweepExemptionKeyParts {
    readonly php: string;
    readonly locator: TsCandidateLocator;
    readonly rule: ReverseSweepRule;
}

export const reverseSweepKey = (parts: ReverseSweepExemptionKeyParts): string =>
    `${parts.php}|${locatorKey(parts.locator)}|${parts.rule}`;

export interface ReverseSweepAudit<E extends ReverseSweepExemptionKeyParts> {
    /** 申告で逃がせていない候補。 */
    readonly unexempted: readonly UnregisteredMirrorCandidate[];
    /** 実態と食い違った申告 (今はもう候補として鳴らない)。 */
    readonly stale: readonly E[];
}

/**
 * 申告の突き合わせ。**生死の判定は「免除を適用する前」の候補集合に対して行う**
 * (免除適用後の集合で判定すると、申告が自分自身を根拠にして永久に生き続ける)。
 */
export const auditReverseSweepExemptions = <E extends ReverseSweepExemptionKeyParts>(
    found: readonly UnregisteredMirrorCandidate[],
    exemptions: readonly E[],
): ReverseSweepAudit<E> => {
    const exemptKeys = new Set(exemptions.map(reverseSweepKey));
    const foundKeys = new Set(
        found.map((hit) => reverseSweepKey({ php: hit.php.path, locator: hit.candidate.locator, rule: hit.rule })),
    );

    return {
        unexempted: found.filter(
            (hit) =>
                !exemptKeys.has(
                    reverseSweepKey({ php: hit.php.path, locator: hit.candidate.locator, rule: hit.rule }),
                ),
        ),
        stale: exemptions.filter((entry) => !foundKeys.has(reverseSweepKey(entry))),
    };
};
```

## `tests/js/support/enum-ts-sync/program.ts` (全文)

```ts
/**
 * 型情報の入口 (TypeScript の program と型検査器を作る)。
 *
 * **program は 1 本ではなくパッケージごとに作る** (正典 v3 の i5)。
 * `packages/cli` をルートの設定 (bundler / ESNext) で読むと NodeNext 前提の取り込みが
 * 解決できず、型が `any` に落ちた宣言が「文字列リテラル型ではない = 非候補」として
 * **静かに消える**。i5 が言う「本番と同じ型世界」は、道具パッケージにとっては
 * **そのパッケージ自身の tsconfig** である。
 *
 * | program | 起点 |
 * |---|---|
 * | `<root>` | ルート `tsconfig.json` の全ファイル ∪ どのパッケージにも属さない版管理下の `*.ts` ∪ 仮想 `.svelte` |
 * | `packages/<name>` | そのパッケージの `tsconfig.json` の全ファイル ∪ 配下の版管理下の `*.ts` ∪ 配下の仮想 `.svelte` |
 *
 * **所有者の判定は `.ts` と `.svelte` で同じ規則を使う** (現時点で `packages/` の下に
 * `.svelte` は無いが、足されたときにルートの設定で読まれてしまうのを防ぐ)。
 * **所属は `packages/<名前>/` の配下かどうかだけで決める** (tsconfig の有無で決めない)。
 * 自前の tsconfig を持たないパッケージのファイルは**所有者の program が無い**ので
 * `resolveOwner()` が例外にする (fail-closed。そのとき扱いを判断させる)。
 * 起点が 2 本以上の program に重複して載っていないことは**別の検査**
 * (母集団の直和) が見る。
 *
 * 出力はしないので、起点を `rootDir` の外へ足せるよう `rootDir` / `outDir` /
 * `declaration` / `declarationMap` / `composite` / `sourceMap` は落として組む。
 *
 * **`createMirrorProgram(tsFiles)` は廃止した** (2 つの program の作り方を残さない)。
 */
import ts from "typescript";
import fs from "node:fs";
import path from "node:path";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT } from "./repo-root";
import {
    listCandidateSvelteFiles,
    listCandidateTsFiles,
    listProgramTsFiles,
    validateExcludedRoots,
} from "./population";
import {
    assertNoModuleToInstanceReference,
    assertNoVirtualPathCollision,
    realPathOfVirtual,
    toVirtualUnit,
    type SvelteVirtualUnit,
} from "./svelte-source";

export { REPO_ROOT } from "./repo-root";

/** ルートの program の所有者名 (パッケージのディレクトリ名と衝突しない綴り)。 */
export const ROOT_OWNER = "<root>";

export interface MirrorProgram {
    readonly owner: string;
    readonly program: ts.Program;
    readonly checker: ts.TypeChecker;
    /** この program の起点 (`rootNames`) をリポジトリ相対で表したもの。 */
    readonly rootRelatives: ReadonlySet<string>;
}

/** 見本専用の**起点を縮めた** program。**本番の gate では使わない**。 */
export interface FixtureProgram extends MirrorProgram {
    readonly fixture: true;
}

export interface MirrorPrograms {
    /** 所有者 (`<root>` またはパッケージのディレクトリ) → program。 */
    readonly byOwner: ReadonlyMap<string, MirrorProgram>;
    /** 候補走査の母集団 (リポジトリ相対)。 */
    readonly population: {
        readonly ts: readonly string[];
        readonly svelte: readonly string[];
    };
    /** 母集団の相対パス → 所有者。 */
    ownerOf(relativePath: string): string;
    /** 母集団の相対パス → それを載せている program。 */
    programOf(relativePath: string): MirrorProgram;
    /** 相対パス → その program 上の SourceFile (`.svelte` は仮想単位)。 */
    sourceOf(relativePath: string): ts.SourceFile;
}

const formatHost: ts.FormatDiagnosticsHost = {
    getCanonicalFileName: (fileName) => fileName,
    getCurrentDirectory: () => REPO_ROOT,
    getNewLine: () => "\n",
};

/** tsconfig.json を読む。回復可能な診断も含めて 1 件でもあれば例外にする。 */
const parseTsconfig = (configPath: string): ts.ParsedCommandLine => {
    const where = path.relative(REPO_ROOT, configPath).split(path.sep).join("/");
    const host: ts.ParseConfigFileHost = {
        useCaseSensitiveFileNames: ts.sys.useCaseSensitiveFileNames,
        readDirectory: ts.sys.readDirectory,
        fileExists: ts.sys.fileExists,
        readFile: ts.sys.readFile,
        getCurrentDirectory: () => path.dirname(configPath),
        onUnRecoverableConfigFileDiagnostic: (d) => {
            throw new EnumTsSyncError(where, ts.flattenDiagnosticMessageText(d.messageText, " "));
        },
    };
    const parsed = ts.getParsedCommandLineOfConfigFile(configPath, {}, host);
    if (parsed === undefined) throw new EnumTsSyncError(where, "読み込みに失敗しました");
    if (parsed.errors.length > 0) {
        throw new EnumTsSyncError(where, ts.formatDiagnostics(parsed.errors, formatHost));
    }
    if (parsed.fileNames.length === 0) {
        throw new EnumTsSyncError(where, "対象ファイルが 0 件です (gate が空振りしている)");
    }
    return parsed;
};

const relativeOf = (fileName: string): string =>
    realPathOfVirtual(fileName) ?? path.relative(REPO_ROOT, fileName).split(path.sep).join("/");

/**
 * program を 1 本組み、仮想単位に対して**検査 B を必ず走らせる**。
 * **この関数は輸出しない** — 検査を飛ばした program を外から作る経路を型で消すためである。
 */
const buildProgram = (
    owner: string,
    parsed: ts.ParsedCommandLine,
    rootNames: readonly string[],
    virtualUnits: readonly SvelteVirtualUnit[],
): MirrorProgram => {
    const options: ts.CompilerOptions = {
        ...parsed.options,
        noEmit: true,
        rootDir: undefined,
        outDir: undefined,
        declaration: false,
        declarationMap: false,
        composite: false,
        sourceMap: false,
    };
    const base = ts.createCompilerHost(options, true);
    const virtualText = new Map(virtualUnits.map((unit) => [unit.virtualPath, unit.text]));
    const host: ts.CompilerHost = {
        ...base,
        fileExists: (fileName) => virtualText.has(fileName) || base.fileExists(fileName),
        readFile: (fileName) => virtualText.get(fileName) ?? base.readFile(fileName),
        getSourceFile: (fileName, languageVersion, onError, shouldCreate) => {
            const text = virtualText.get(fileName);
            return text !== undefined
                ? ts.createSourceFile(fileName, text, languageVersion, true, ts.ScriptKind.TS)
                : base.getSourceFile(fileName, languageVersion, onError, shouldCreate);
        },
    };

    const roots = [...new Set([...rootNames, ...virtualText.keys()])];
    // `projectReferences` を落とすと「本番と同じ型世界」でなくなる (参照先が欠ける)。
    const program = ts.createProgram({
        rootNames: roots,
        options,
        host,
        projectReferences: parsed.projectReferences,
    });
    const optionsDiagnostics = program.getOptionsDiagnostics();
    if (optionsDiagnostics.length > 0) {
        throw new EnumTsSyncError(owner, ts.formatDiagnostics(optionsDiagnostics, formatHost));
    }
    const checker = program.getTypeChecker();
    const canonical = host.getCanonicalFileName.bind(host);

    // 検査 B は program を組んだ直後に必ず走らせる (呼び出し義務を利用側へ渡さない)。
    for (const unit of virtualUnits) {
        const source = program.getSourceFile(unit.virtualPath);
        if (source === undefined) {
            throw new EnumTsSyncError(unit.source, "仮想単位が program に載っていません");
        }
        if (canonical(source.fileName) !== canonical(unit.virtualPath)) {
            throw new EnumTsSyncError(unit.source, "仮想単位の綴りが正規化の規則と食い違っています");
        }
        assertNoModuleToInstanceReference(checker, source, unit);
    }

    return {
        owner,
        program,
        checker,
        rootRelatives: new Set(roots.map(relativeOf)),
    };
};

/**
 * `packages/` 直下のディレクトリ全数 (リポジトリ相対・綴り順)。
 * **tsconfig の有無で絞らない** — 所属の判定と「解析できる program があるか」の判定を
 * 分けるためである。絞ると tsconfig を持たないパッケージのファイルが黙って `<root>` へ
 * 落ち、そのパッケージ用の設定ではない型世界で解析されてしまう (偽陰性)。
 */
export const listPackageDirectories = (root: string = REPO_ROOT): readonly string[] => {
    const packagesDir = path.join(root, "packages");
    if (!fs.existsSync(packagesDir) || !fs.lstatSync(packagesDir).isDirectory()) return [];
    return fs
        .readdirSync(packagesDir, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => `packages/${entry.name}`)
        .sort();
};

/** そのパッケージが自前の tsconfig を持つか (program を作れるか)。 */
export const hasPackageTsconfig = (packageDir: string, root: string = REPO_ROOT): boolean =>
    fs.existsSync(path.join(root, packageDir, "tsconfig.json"));

/**
 * 所属だけを決める純関数。**tsconfig の有無で決めない**
 * (絞ると、自前の設定を持たないパッケージのファイルが黙って `<root>` の型世界へ落ちる)。
 */
export const ownerNameOf = (relative: string, packageDirs: readonly string[]): string =>
    packageDirs.find((dir) => relative.startsWith(`${dir}/`)) ?? ROOT_OWNER;

/**
 * 所有者を解決する純関数。**所属と「その所有者の program があるか」を分けて見る**
 * — 所属が `packages/<名前>` なのに program が無ければ例外にする (fail-closed)。
 *
 * @param packageDirs     `packages/` 直下のディレクトリ全数 (tsconfig の有無で絞らない)
 * @param availableOwners 実際に program を組めた所有者
 */
export const resolveOwner = (
    relative: string,
    packageDirs: readonly string[],
    availableOwners: ReadonlySet<string>,
): string => {
    const owner = ownerNameOf(relative, packageDirs);
    if (!availableOwners.has(owner)) {
        throw new EnumTsSyncError(
            relative,
            `所有者 ${owner} の program がありません (自前の tsconfig.json を持たないパッケージです。ルートの設定で読むと型が縮んで候補が静かに消えるので、扱いを決めてから走らせること)`,
        );
    }
    return owner;
};

/**
 * 逆走査と前向きの検査が共通で使う program 群を作る。
 * 目録のファイルも母集団の一部なので所有者の program へ載る。
 */
export const createMirrorPrograms = (): MirrorPrograms => {
    validateExcludedRoots();

    const programTs = listProgramTsFiles();
    const candidateTs = listCandidateTsFiles();
    const candidateSvelte = listCandidateSvelteFiles();
    const packageDirs = listPackageDirectories();

    const ownerOfRelative = (relative: string): string => ownerNameOf(relative, packageDirs);

    const units = candidateSvelte.map((relative) =>
        toVirtualUnit(relative, fs.readFileSync(path.join(REPO_ROOT, relative), "utf-8")),
    );
    assertNoVirtualPathCollision(units, programTs);
    const virtualByReal = new Map(units.map((unit) => [unit.source, unit]));

    const absolute = (relative: string): string => path.join(REPO_ROOT, relative);

    const rootParsed = parseTsconfig(path.join(REPO_ROOT, "tsconfig.json"));
    const byOwner = new Map<string, MirrorProgram>();
    byOwner.set(
        ROOT_OWNER,
        buildProgram(
            ROOT_OWNER,
            rootParsed,
            [...rootParsed.fileNames, ...programTs.filter((file) => ownerOfRelative(file) === ROOT_OWNER).map(absolute)],
            units.filter((unit) => ownerOfRelative(unit.source) === ROOT_OWNER),
        ),
    );
    for (const dir of packageDirs) {
        if (!hasPackageTsconfig(dir)) continue;
        const parsed = parseTsconfig(path.join(REPO_ROOT, dir, "tsconfig.json"));
        byOwner.set(
            dir,
            buildProgram(
                dir,
                parsed,
                [...parsed.fileNames, ...programTs.filter((file) => ownerOfRelative(file) === dir).map(absolute)],
                units.filter((unit) => ownerOfRelative(unit.source) === dir),
            ),
        );
    }

    const availableOwners = new Set(byOwner.keys());
    const ownerOf = (relative: string): string => resolveOwner(relative, packageDirs, availableOwners);

    const programOf = (relative: string): MirrorProgram => {
        const program = byOwner.get(ownerOf(relative));
        if (program === undefined) throw new EnumTsSyncError(relative, "所有者の program を解決できません");
        return program;
    };

    const sourceOf = (relative: string): ts.SourceFile => {
        const mirror = programOf(relative);
        let fileName = absolute(relative);
        if (relative.endsWith(".svelte")) {
            const unit = virtualByReal.get(relative);
            if (unit === undefined) throw new EnumTsSyncError(relative, ".svelte が仮想化されていません");
            fileName = unit.virtualPath;
        }
        const source = mirror.program.getSourceFile(fileName);
        if (source === undefined) throw new EnumTsSyncError(relative, `所有者 ${mirror.owner} の program に載っていません`);
        return source;
    };

    return {
        byOwner,
        population: { ts: candidateTs, svelte: candidateSvelte },
        ownerOf,
        programOf,
        sourceOf,
    };
};

/**
 * 見本 (fixture) 専用の**起点を縮めた** program。**本番の gate では使わない**。
 * リポジトリの `compilerOptions` (`paths` を含む) はそのまま使い、起点だけを明示する。
 * 仮想単位を渡した場合も**検査 B は必ず走る** (本番と同じ一本道)。
 *
 * @param absoluteFiles 絶対パス
 * @param virtualUnits  仮想 `.svelte` 単位 (省略可)
 */
export const createFixtureProgram = (
    absoluteFiles: readonly string[],
    virtualUnits: readonly SvelteVirtualUnit[] = [],
): FixtureProgram => {
    for (const file of absoluteFiles) {
        if (!fs.existsSync(file)) throw new EnumTsSyncError(file, "見本ファイルが実在しません");
    }
    // 起点は明示したものだけにする (tsconfig の全ファイルは載せない = 縮めた program)。
    const parsed = parseTsconfig(path.join(REPO_ROOT, "tsconfig.json"));
    return { ...buildProgram("<fixture>", parsed, absoluteFiles, virtualUnits), fixture: true };
};

/**
 * 除外根の**自己点検**: 与えられたファイルのうち「本番と同じ入口で落ちないもの」を返す。
 *
 * 除外根に置いてよいのは**わざと壊した見本**だけである。正常なファイルを置くと
 * 母集団から静かに消えるので、それを検出する受け皿がこの関数である。
 * **拡張子ごとに本番と同じ入口を使う** — `.ts` は TypeScript の構文診断、
 * `.svelte` は `toVirtualUnit()` の失敗で見る。どちらでもない拡張子は
 * 「本番の入口を持たない」ので落ちない側 (= 生き残り) として返す。
 *
 * @param files 除外根の配下のファイル (`root` からの相対パス)
 * @param root  走査根 (負のコントロールが一時ディレクトリを渡すためだけに引数化してある)
 */
export const findExcludedSurvivors = (
    files: readonly string[],
    root: string = REPO_ROOT,
): readonly string[] => {
    const survivors: string[] = [];
    for (const file of files) {
        const absolute = path.join(root, file);
        if (file.endsWith(".svelte")) {
            // 読み込みは try の外で行う (I/O の失敗を「期待した構文不正」として
            // 吸収しない。捕捉するのは `toVirtualUnit()` の拒否だけである)。
            const source = fs.readFileSync(absolute, "utf-8");
            try {
                toVirtualUnit(file, source);
            } catch {
                continue;
            }
            survivors.push(file);
            continue;
        }
        if (!file.endsWith(".ts")) {
            survivors.push(file);
            continue;
        }
        const fixture = createFixtureProgram([absolute]);
        const source = fixture.program.getSourceFile(absolute);
        if (source === undefined || fixture.program.getSyntacticDiagnostics(source).length === 0) {
            survivors.push(file);
        }
    }
    return survivors;
};
```

## 追加・変更したテストと文書の差分

```diff
diff --git a/docs/architecture.md b/docs/architecture.md
index df63533c..bb8738e3 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -3142,10 +3142,16 @@ ### 目録 (単一の出典) と関係の 2 値
 
 ### program はパッケージごとに作る
 
-**`packages/<名前>` をルートの設定 (bundler / ESNext) で読まない**。読むと NodeNext 前提の
-取り込みが解決できず、型が `any` に落ちた宣言が「文字列リテラル型ではない = 非候補」として
-**静かに消える**。「本番と同じ型世界」は、道具パッケージにとっては
-**そのパッケージ自身の tsconfig** である。したがって program を複数本持つ。
+**`packages/<名前>` をルートの設定 (bundler / ESNext) で読まない**。「本番と同じ型世界」は、
+道具パッケージにとっては**そのパッケージ自身の tsconfig** だからである。
+ルートの設定で読むと、NodeNext 前提の取り込みが解決できず型が `any` へ落ちた宣言が
+「文字列リテラル型ではない = 非候補」として静かに消える**恐れ**がある。
+**ただしこの解決の失敗は現物では観測されていない** — 現時点の `packages/cli` の取り込みは
+bundler 解決でも通る (実測: 両方の設定で意味診断 0 件)。つまりこの方式は
+**偽陰性を作らない側の予防**であって、現に偽陰性が起きていたことの証拠ではない。
+機械で固定しているのは「パッケージのファイルはそのパッケージの設定で組まれた program に
+載る」ことと「どのファイルもちょうど 1 本の program の起点に載る」ことの 2 つである。
+したがって program を複数本持つ。
 
 | program | 起点 |
 |---|---|
@@ -3164,12 +3170,6 @@ ### program はパッケージごとに作る
 - **候補走査は「所有者の program 上の `SourceFile`」だけを使う**
   (`program.getSourceFiles()` 全体は依存ライブラリ・推移的な取り込み・JSON が載るので
   母集団の一致根拠にしない)。
-- **「型が `any` へ落ちて候補が静かに消える」は現物では観測されていない** —
-  現時点の `packages/cli` の取り込みはルートの設定 (bundler) でも解決できてしまう。
-  gate が機械で固定しているのは「どのファイルもちょうど 1 本の program に載る」ことと
-  「パッケージのファイルはそのパッケージの設定 (NodeNext) で組まれた program に載る」
-  ところまでである。**この方式は偽陰性を作らない側の予防であって、
-  現に偽陰性が起きていたことの証拠ではない**。
 
 ### `.svelte` は 1 つの仮想 TS へ平坦化する
 
@@ -3293,8 +3293,11 @@ ### 保証しないもの (誇張しない。前向き・発見の段・逆走
   証人が無ければ候補として残る (fail-closed)。
 - **分岐のラベルと対応表のキーは登録できない**。写しなら型別名か定数の配列へ切り出す。
 - パッケージの型は**そのパッケージ自身の tsconfig** で解決する
-  (ルートの設定で解決するわけではない)。tsconfig を持たないパッケージは
-  どの program にも載らず、母集団の直和検査が赤くなる。
+  (ルートの設定で解決するわけではない)。ただし**設定差による現物の解決失敗は
+  観測されていない** — 機械が固定するのは「そのパッケージの設定で組まれた program に
+  載っていること」までである。自前の tsconfig を持たないパッケージは
+  **所有者の program の解決時**に落ちる (直和検査は「起点が重複・欠落していないこと」を
+  見る別の検査である)。
 - **除外根の中は見ない**。`fixtures/` の残りは**見る**ので、見本を書き換えると
   本番の候補集合も動く (過剰検出の向きなので許容する)。
 - **`subset` の妥当性は機械では見分けられない** (完全一致の写しを `subset` と偽れば緩む)。
diff --git a/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts b/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts
index eb7a992c..b86b0809 100644
--- a/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts
+++ b/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts
@@ -33,6 +33,8 @@ import {
     findExcludedSurvivors,
     hasPackageTsconfig,
     listPackageDirectories,
+    ownerNameOf,
+    resolveOwner,
     REPO_ROOT,
     type MirrorPrograms,
 } from "../support/enum-ts-sync/program";
@@ -293,6 +295,19 @@ describe("population.ts (逆走査の母集団と唯一の除外)", () => {
         }
     });
 
+    it("所属が package なのに program が無ければ所有者の解決で落ちる (fail-closed)", () => {
+        const dirs = ["packages/with-config", "packages/without-config"] as const;
+        const available = new Set(["<root>", "packages/with-config"]);
+
+        expect(resolveOwner("packages/with-config/src/x.ts", dirs, available)).toBe("packages/with-config");
+        expect(resolveOwner("resources/js/types/x.ts", dirs, available)).toBe("<root>");
+        // **所属は tsconfig の有無で決めない**ので、`<root>` へ静かに落ちずに例外になる。
+        expect(ownerNameOf("packages/without-config/src/x.ts", dirs)).toBe("packages/without-config");
+        expect(() => resolveOwner("packages/without-config/src/x.ts", dirs, available)).toThrow(
+            "の program がありません",
+        );
+    });
+
     it("実リポジトリのパッケージはすべて自前の tsconfig を持つ (持たなければ program の解決で落ちる)", () => {
         const withoutConfig = listPackageDirectories().filter((dir) => !hasPackageTsconfig(dir));
         expect(
@@ -960,6 +975,30 @@ describe("規則 2 の論理和 (2a ∨ 2b)", () => {
         expect(silent.kind).toBe("none");
     });
 
+    it("列挙名から語が取れない組も、交差が半分未満でも例外になる (黙って通さない)", () => {
+        // 候補側だけを見ていると、PHP 側の列挙名が語に割れないときに規則 2b から
+        // 黙って消える (Codex 実装レビュー Round 2 の Critical)。
+        for (const values of [["a", "x", "y"], ["a", "b", "x"]]) {
+            expect(() =>
+                matchReverseRule(
+                    { path: "app/Enums/___.php", name: "___", line: 1, values: new Set(["a", "b", "c", "d"]) },
+                    tsCandidate("resources/js/types/x.ts", "JobStatus", values),
+                ),
+            ).toThrow("列挙名から語を 1 つも取り出せません");
+        }
+    });
+
+    it("nameResolved が真なのに名前が無い候補は内部矛盾として例外になる", () => {
+        const broken: TsUnionCandidate = {
+            ...tsCandidate("resources/js/types/x.ts", "Foo", ["a", "z"]),
+            correspondenceName: null,
+            nameResolved: true,
+        };
+        expect(() => matchReverseRule(phpEnum("app/Enums/Foo.php", ["a", "b"]), broken)).toThrow(
+            "nameResolved が真なのに名前対応に使う名前がありません",
+        );
+    });
+
     it("宣言名から語が取れない候補は、交差が半分未満でも例外になる (黙って通さない)", () => {
         // 交差率の早期 return より前に語の非空を見ていないと、この組は `none` で
         // 黙って通ってしまう (Codex 実装レビュー Round 1 の Critical)。
```

## テスト結果 (Round 3 時点)

- enum-ts-sync 系 4 ファイル: 289 tests passed (Round 2 から +3)
- pnpm typecheck: green
- `composer test` のクリーンなフル実行は本レビューと並行して実行中 (結果は最終報告に載せる)
