/**
 * 対応表のキーの「派生」除外の見本 (証人つきでだけ外す)。
 * 値は現物の列挙と交差しない綴りにすること。
 *
 * **型検査は通らなくてよい** — `tsconfig.json` の `exclude` に `fixtures/**` があり、
 * 本 gate が見るのは構文診断だけである (意味の診断は `pnpm typecheck` の担当)。
 */
import type { ImportedDerivedKey } from "./derived-keys";

/** 証人になれる形 (型の合併)。 */
export type DerivedKey = "zzz-d-1" | "zzz-d-2";

/** 証人になれる形 (定数の配列)。取り込んだ型の見本の証人になる。 */
export const ImportedDerivedKeyList = ["zzz-i-1", "zzz-i-2"] as const;

/** 派生 (型注釈の `Record`)。証人があるので外れる。 */
export const DerivedRecord: Record<DerivedKey, number> = { "zzz-d-1": 1, "zzz-d-2": 2 };

/** 派生 (`satisfies`)。証人があるので外れる。 */
export const DerivedSatisfies = { "zzz-d-1": 1, "zzz-d-2": 2 } satisfies Record<DerivedKey, number>;

/** 派生 (型別名越しの `Record`)。証人があるので外れる。 */
type DerivedAlias = Record<DerivedKey, number>;
export const DerivedViaAlias: DerivedAlias = { "zzz-d-1": 1, "zzz-d-2": 2 };

/** 派生 (`keyof`)。証人があるので外れる。 */
interface DerivedShape {
    readonly "zzz-d-1": number;
    readonly "zzz-d-2": number;
}
export const DerivedViaKeyof: Record<keyof DerivedShape, number> = { "zzz-d-1": 1, "zzz-d-2": 2 };

/** 派生 (取り込んだ型)。証人 (`ImportedDerivedKeyList`) があるので外れる。 */
export const DerivedViaImport: Record<ImportedDerivedKey, number> = { "zzz-i-1": 1, "zzz-i-2": 2 };

/** `Partial` は過不足を落とさないので派生と認めない (候補として残る)。 */
export const DerivedPartial: Partial<Record<DerivedKey, number>> = { "zzz-d-1": 1, "zzz-d-2": 2 };

/** 文字列の添字シグネチャがあるので派生と認めない (候補として残る)。 */
export const DerivedIndexSignature: Record<string, number> = { "zzz-d-1": 1, "zzz-d-2": 2 };

/** 必須プロパティが書かれたキーより多い (欠落) ので派生と認めない。 */
export const DerivedMissingKey: Record<DerivedKey | "zzz-d-3", number> = { "zzz-d-1": 1, "zzz-d-2": 2 };

/** 書かれたキーが必須プロパティより多い (余剰) ので派生と認めない。 */
export const DerivedExtraKey: Record<"zzz-d-1", number> = { "zzz-d-1": 1, "zzz-d-2": 2 };

/** 合併の型は共通のプロパティしか必須にならないので派生と認めない。 */
export const DerivedUnionType: Record<DerivedKey, number> | Record<"zzz-d-1", number> = {
    "zzz-d-1": 1,
    "zzz-d-2": 2,
};

/** 交叉の型は必須プロパティが増えるので派生と認めない。 */
export const DerivedIntersectionType: Record<DerivedKey, number> & { readonly "zzz-d-3": number } = {
    "zzz-d-1": 1,
    "zzz-d-2": 2,
};

/** 明示の型が無いので派生と認めない。 */
export const DerivedNoExplicitType = { "zzz-d-1": 1, "zzz-d-2": 2 };

/** 証人が無い (鍵の型が対応表以外の候補にならない) ので派生と認めない。 */
interface WitnesslessShape {
    readonly "zzz-nw-1": number;
    readonly "zzz-nw-2": number;
}
export const DerivedWitnessless: WitnesslessShape = { "zzz-nw-1": 1, "zzz-nw-2": 2 };
