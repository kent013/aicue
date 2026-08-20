/** 逆走査の候補走査 (collectTsUnionCandidates) の負のコントロール専用の見本。 */
export type LiteralUnionCandidate = "a" | "b";
export type SingleLiteralCandidate = "only";
export type NotAUnionCandidate = string;
export type NumberCandidate = 1 | 2;
