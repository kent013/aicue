/**
 * shell スクリプトの契約テストで共有する純ヘルパ。
 *
 * **test ファイルから import しない** (test ファイルを import すると、その describe が
 * import 元でも二重登録される)。そのため非 test の module として置く。
 * 本モジュール自身の保証は tests/js/support/shell-contract.test.ts が持つ。
 */

/**
 * 行頭 (空白除く) が `#` の行を落とす。方針説明コメントで偽赤にしないため
 * (tests/Architecture/GlobalTestLockInventoryTest.php の globalTestLockCodeLines と同方針)。
 */
export function codeLines(source: string): string {
    return source
        .split("\n")
        .filter((line) => !/^\s*#/.test(line))
        .join("\n");
}

/** 元ソースの `from` を `to` に 1 箇所だけ置換する。置換が成立しなければ throw (空振り防止)。 */
export function mutate(source: string, from: string, to: string): string {
    const occurrences = source.split(from).length - 1;
    if (occurrences !== 1) {
        throw new Error(`mutation target must appear exactly once (found ${occurrences}): ${from}`);
    }
    const mutated = source.replace(from, to);
    if (mutated === source) throw new Error(`mutation did not change the source: ${from}`);
    if (!mutated.includes(to)) throw new Error(`mutated source lacks the expected token: ${to}`);
    return mutated;
}

/**
 * `source` の中で `needle` を含む最初の行の 0 始まり index を返す (無ければ -1)。
 *
 * 「A が B より前にあること」という順序契約を、行番号の比較で書けるようにするためのもの。
 */
export function lineIndexOf(source: string, needle: string): number {
    return source.split("\n").findIndex((line) => line.includes(needle));
}
