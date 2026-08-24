/**
 * 逆走査の候補走査 (`collectTsCandidates`) の正例・負例の見本。
 *
 * **`fixtures/` は本番の母集団に入る** (除外は `candidates-broken/` だけ)。したがって
 * 見本の値は**現物の PHP 列挙と交差しない綴り** (`"zzz-…"` など) にすること。
 * 交差する値を書くと本番の逆走査が鳴る。
 */

// --- literal-union -------------------------------------------------------
export type LiteralUnionCandidate = "a" | "b";
export type SingleLiteralCandidate = "only";
export type NotAUnionCandidate = string;
export type NumberCandidate = 1 | 2;
export type ExplicitAnyCandidate = any;
export type ExplicitUnknownCandidate = unknown;
type IndirectAny = any;
export type IndirectAnyCandidate = IndirectAny;

// --- const-array ---------------------------------------------------------
export const ConstArrayCandidate = ["zzz-sample-1", "zzz-sample-2"];
export const ConstArrayAsConst = ["zzz-sample-3"] as const;
export const ConstArraySatisfies = ["zzz-sample-4"] satisfies readonly string[];
export const ConstArrayParenthesized = (["zzz-sample-5"] as const);
export let LetArrayCandidate = ["zzz-sample-6"];
export const MixedArrayCandidate = ["zzz-sample-7", LetArrayCandidate[0]];
export const EmptyArrayCandidate: readonly string[] = [];

// --- object-keys ---------------------------------------------------------
export const ObjectKeysCandidate = { "zzz-key-1": 1, zzzKey2: 2 };
export const ObjectKeysWithIndexSignature: Record<string, number> = { "zzz-key-3": 1 };
export const ObjectSpreadCandidate = { ...ObjectKeysCandidate };
const computedKey = "zzz-key-4" as const;
export const ObjectComputedKeyCandidate = { [computedKey]: 1 };
const anyKey: any = "zzz-key-5";
export const ObjectAnyComputedKeyCandidate = { [anyKey]: 1 };

// --- switch-cases --------------------------------------------------------
export const switchCandidate = (value: LiteralUnionCandidate): number => {
    switch (value) {
        case "a":
            return 1;
        case "b":
            return 2;
        default:
            return 0;
    }
};

// --- 入れ子の同名宣言 (locator の一意性の見本) -----------------------------
export function nestedA(): string {
    type NestedShadow = "zzz-nested-1";
    const value: NestedShadow = "zzz-nested-1";
    return value;
}

export function nestedB(): string {
    type NestedShadow = "zzz-nested-2";
    const value: NestedShadow = "zzz-nested-2";
    return value;
}
