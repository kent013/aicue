/** 注釈の中の "ghost" という語は値ではない。 */
// もう 1 つの "phantom" も同じ。
export type X =
    // "decoy" を挟む
    | "a"
    | "b";
