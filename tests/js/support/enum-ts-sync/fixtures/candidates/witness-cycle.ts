/**
 * 証人の資格を「対応表のキー形**以外**」に限ることの見本 (循環の遮断)。
 * どれも `object-keys` 形どうしなので互いの証人になれず、**候補として残る**。
 * 値は現物の列挙と交差しない綴りにすること。
 *
 * **型検査は通らなくてよい** (`fixtures/**` は `pnpm typecheck` の対象外)。
 */

/** 自己証人: 自分自身を根拠に消えてはならない。 */
export const SelfWitness: Record<"zzz-w-1" | "zzz-w-2", number> = { "zzz-w-1": 1, "zzz-w-2": 2 };

/** 2 件の相互証人: 互いを根拠に両方消えてはならない。 */
export const MutualWitnessA: Record<"zzz-w-3" | "zzz-w-4", number> = { "zzz-w-3": 1, "zzz-w-4": 2 };
export const MutualWitnessB: Record<"zzz-w-3" | "zzz-w-4", number> = { "zzz-w-3": 1, "zzz-w-4": 2 };

/** 3 件の循環証人: 巡回を根拠に全部消えてはならない。 */
export const CycleWitnessA: Record<"zzz-w-5" | "zzz-w-6", number> = { "zzz-w-5": 1, "zzz-w-6": 2 };
export const CycleWitnessB: Record<"zzz-w-5" | "zzz-w-6", number> = { "zzz-w-5": 1, "zzz-w-6": 2 };
export const CycleWitnessC: Record<"zzz-w-5" | "zzz-w-6", number> = { "zzz-w-5": 1, "zzz-w-6": 2 };
