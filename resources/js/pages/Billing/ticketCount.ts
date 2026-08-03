// チケット購入枚数の「文字列 → 整数」変換を 1 箇所に集約した純関数 (aigenba verbatim 移植)。
//
// 型責務の分離:
//   - UI draft 型 = string (PurchaseTickets.svelte の countText は常に string で保持)
//   - domain value 型 = number | null (本関数の戻り値)
//
// `<Input type="number">` への two-way `bind:value` は Svelte 5 が値を number に強制するため、
// draft を string で保つ構造にしても本関数は防御的に `String(raw)` を噛ませ、万一 number が
// 渡っても throw しない。
//
// 許容形式は「符号付き整数のみ」に固定する。`1e3` (指数) / `0x10` (16進) / `1.5` (小数) /
// `Infinity` / `"-"` / `"1."` / 空文字 は全て null に倒し、暗黙補正 (clamp/floor) はしない。
// 範囲 (min/max) 検証は呼び出し側とサーバ validation の責務。

const INTEGER_RE = /^-?\d+$/;

export function parseTicketCount(raw: string | number): number | null {
    const trimmed = String(raw).trim();
    if (!INTEGER_RE.test(trimmed)) {
        return null;
    }
    const n = Number(trimmed);
    return Number.isInteger(n) ? n : null;
}
