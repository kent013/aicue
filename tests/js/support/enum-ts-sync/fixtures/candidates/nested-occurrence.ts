/**
 * 「入れ子が先・最上位が後」の見本。前向きの目録は**最上位の宣言**の locator へ
 * 解決し、入れ子の同名候補は逆走査に残る (`occurrence` が別なので申告も混ざらない)。
 * 値は現物の列挙と交差しない綴りにすること。
 */
function inner(): string {
    type NestedFirst = "zzz-nested-3";
    const value: NestedFirst = "zzz-nested-3";
    return value;
}

export type NestedFirst = "zzz-nested-4";

export const nestedFirstUser = (): string => inner();
