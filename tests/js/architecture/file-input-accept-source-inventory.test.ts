import { describe, expect, it } from "vitest";
import path from "path";
import { scanFileInputs } from "../support/file-input-scan";
import {
    FILE_INPUT_ACCEPT_INVENTORY,
    FILE_INPUT_COUNT,
    RAW_HTML_EXEMPTION_COUNT,
    RAW_HTML_EXEMPTIONS,
    UNRESOLVED_FORM_EXEMPTION_COUNT,
    UNRESOLVED_FORM_EXEMPTIONS,
    evaluateFileInputInventory,
} from "../support/file-input-accept-inventory";

/**
 * file input の `accept` 供給元目録 (deny-by-default)。
 *
 * 新しいアップロード面を足したときに「受理形式の単一の情報源へ繋ぐ」判断が
 * レビューに見えないまま漏れるのを止める。**止めるのは供給元の宣言の漏れだけ**で、
 * 宣言した供給元が本当にサーバ由来かは検証しない (保証範囲は走査器と目録の docblock が正本)。
 *
 * gate 本体がすることは 2 つだけである:
 *   1. 実リポジトリの `resources/js` 配下の `.svelte` を走査する
 *   2. 判定関数へ渡して戻り値が空配列であることを確かめる
 *
 * 判定を 1 関数へ集約しているのは、母集団非空や診断の扱いを gate 側の assert へ散らすと
 * その分岐に負例が付かず「走査器は診断を集めたのに gate が無視する」実装ミスを
 * 自己検査できなくなるためである (負例は `file-input-scan.test.ts` が持つ)。
 *
 * **正本のレーンは `pnpm test`** である (`composer test` では JS の gate は走らない)。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");

describe("file input accept source inventory", () => {
    it("resources/js 配下の file input はすべて供給元が目録に宣言されている", async () => {
        const scan = await scanFileInputs(JS_ROOT);

        const violations = evaluateFileInputInventory(scan, {
            inventory: FILE_INPUT_ACCEPT_INVENTORY,
            countPin: FILE_INPUT_COUNT,
            rawHtmlExemptions: RAW_HTML_EXEMPTIONS,
            rawHtmlExemptionCountPin: RAW_HTML_EXEMPTION_COUNT,
            unresolvedFormExemptions: UNRESOLVED_FORM_EXEMPTIONS,
            unresolvedFormExemptionCountPin: UNRESOLVED_FORM_EXEMPTION_COUNT,
        });

        expect(
            violations,
            `file input の accept 供給元目録が実測と一致しません。\n` +
                `tests/js/support/file-input-accept-inventory.ts を更新してください:\n${violations.join("\n")}`,
        ).toEqual([]);
    });
});
