/**
 * 採番が**三値をまたぐ**ことの見本 (レビュー Round 4 の Critical / 実装レビュー Round 1)。
 *
 * `occurrence` は候補・判定保留・非候補の**同じ採番空間**で振る。したがって
 * 「判定保留が先・候補が後」「非候補が先・候補が後」のどちらでも、後の候補は
 * `occurrence: 1` になる (片方の申告がもう片方へ効かない)。
 *
 * 値は現物の列挙と交差しない綴りにすること (`fixtures/` は母集団に入る)。
 * **型検査は通らなくてよい** (`fixtures/**` は `pnpm typecheck` の対象外)。
 */
type IndirectAnyForStaged = any;

/** 判定保留 (別名越しの明示 `any`) が先。 */
export function stagedPendingFirst(): void {
    type StagedShadow = IndirectAnyForStaged;
    const value: StagedShadow = "zzz-staged-0";
    void value;
}

/** 候補が後 (occurrence: 1)。 */
export type StagedShadow = "zzz-staged-1";

/** 非候補 (開いた文字列) が先。 */
export function stagedNonCandidateFirst(): void {
    type MixedShadow = string;
    const value: MixedShadow = "zzz-mixed-0";
    void value;
}

/** 候補が後 (occurrence: 1)。 */
export type MixedShadow = "zzz-mixed-1";
