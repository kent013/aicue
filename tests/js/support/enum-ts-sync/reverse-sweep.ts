/**
 * 逆走査 (裁定 AG-099 後半)。
 *
 * `enum-ts-sync.test.ts` は「目録に登録した写しについて PHP → TS を見る」向きの検査なので、
 * **登録し忘れた写し**は素通りする。本モジュールは向きを変え、TS 側の型別名の候補
 * (`collectTsUnionCandidates`) と PHP の文字列付き列挙の母集団 (`buildPhpEnumCatalog`)
 * を突き合わせ、次の 2 規則で「未登録だが対応していそうな組」を検出する。
 *
 * - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全に一致する未登録の TS 宣言。
 *   これは「登録を忘れているだけ」の可能性が高い最有力候補である。
 * - **規則 2 (名前対応 + 値の交差)**: 型別名の名前が PHP 列挙名と厳密に対応し
 *   (一致 / 複数形接尾辞 `s` `es` `values` の付加)、かつ値集合が交差するが**完全一致ではない**
 *   未登録の TS 宣言。これは「かつて対応していたが、どちらか片方だけ値を足して
 *   ズレた写し」を拾うためのもので、規則 1 に緩い部分集合や名前無視の条件を混ぜると
 *   誤検出が支配的になる (家系の実測: 緩い形は偽陽性 80〜100%)。
 *
 * **これは「登録漏れが無いことの証明」ではなく「候補の検出」である**。
 * 名前も対応せず値も完全一致しない drift 済みの写しは検出できない (意図した限界)。
 */
import type { ResolvedPhpEnum } from "./php-enum-catalog";
import type { TsUnionCandidate } from "./ts-candidates";

export interface UnregisteredMirrorCandidate {
    readonly rule: 1 | 2;
    readonly php: ResolvedPhpEnum;
    readonly candidate: TsUnionCandidate;
    /** 規則 1 は `null`。規則 2 は名前の対応関係の説明 (メッセージ用)。 */
    readonly nameMatch: string | null;
}

/**
 * 大文字小文字の違いだけを吸収する。**英数字以外は除去しない**
 * (`_` や `$` まで消すと `Foo_Bar` と `FooBar` を同一視してしまい、
 * 「一致 / +s / +es / +values」という厳密な対応より緩くなる)。
 */
const normalizeName = (name: string): string => name.toLowerCase();

/** ファイル名の語幹を取る (テストの見本構築用のユーティリティ。判定本体は `ResolvedPhpEnum.name` を使う)。 */
export const shortEnumName = (path: string): string => {
    const base = path.split("/").pop() ?? path;
    return base.endsWith(".php") ? base.slice(0, -".php".length) : base;
};

/** 厳密な名前対応 (一致 / +s / +es / +values)。対応しなければ `null`。 */
const nameCorrespondence = (candidateName: string, enumName: string): string | null => {
    const candidate = normalizeName(candidateName);
    const target = normalizeName(enumName);
    if (candidate === target) return `${target} = ${candidate}`;
    for (const suffix of ["s", "es", "values"]) {
        if (candidate === `${target}${suffix}`) return `${target} + "${suffix}" = ${candidate}`;
    }
    return null;
};

const sameValueSet = (a: ReadonlySet<string>, b: ReadonlySet<string>): boolean => {
    if (a.size !== b.size) return false;
    for (const value of a) if (!b.has(value)) return false;
    return true;
};

const intersects = (a: ReadonlySet<string>, b: ReadonlySet<string>): boolean => {
    for (const value of a) if (b.has(value)) return true;
    return false;
};

/**
 * 未登録のミラー候補を検出する。
 *
 * @param phpEnums   母集団のうち値集合が読めた PHP 列挙 (`resolved`)。
 * @param candidates TS 側の型別名の候補。
 * @param isRegistered `(file, name)` の組が既に目録に登録済みかを判定する述語
 *                      (登録済みは検査対象から外す)。
 */
export const findUnregisteredMirrorCandidates = (
    phpEnums: readonly ResolvedPhpEnum[],
    candidates: readonly TsUnionCandidate[],
    isRegistered: (file: string, name: string) => boolean,
): readonly UnregisteredMirrorCandidate[] => {
    const found: UnregisteredMirrorCandidate[] = [];

    for (const candidate of candidates) {
        if (isRegistered(candidate.file, candidate.name)) continue;

        for (const phpEnum of phpEnums) {
            if (sameValueSet(phpEnum.values, candidate.values)) {
                found.push({ rule: 1, php: phpEnum, candidate, nameMatch: null });
                continue;
            }

            const correspondence = nameCorrespondence(candidate.name, phpEnum.name);
            if (correspondence === null) continue;
            if (!intersects(phpEnum.values, candidate.values)) continue;

            found.push({ rule: 2, php: phpEnum, candidate, nameMatch: correspondence });
        }
    }

    return found;
};
