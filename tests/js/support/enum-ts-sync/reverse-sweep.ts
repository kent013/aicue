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
