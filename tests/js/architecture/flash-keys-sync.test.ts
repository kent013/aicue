import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { FLASH_KEYS } from "@/lib/stores/flash-to-toast";

/**
 * 画面側の読み手 (flash-to-toast.ts の FLASH_KEYS) ⇔ PHP 側の SoT
 * (FlashNotificationRelay::NOTIFICATION_KEYS) のキー集合一致検査。
 *
 * flash キー集合は「書き手 / 中継 / 共有 / 画面側の読み手」の 4 箇所に現れる。PHP 側 3 箇所は
 * tests/Architecture/FlashNotificationRelayDriftTest.php が固定し、本テストが最後の鎖
 * (実際に画面へ出す側) を閉じる。片方だけキーを足すと、通知が誰にも読まれず消える
 * (または読み手のない共有キーが残る) ずれをここで落とす。
 *
 * PHP ソースからの抽出は正規表現で行う。定義形が変わって抽出できなくなった場合は
 * 0 キー = fail に倒す (0 件一致の偽の緑を作らない)。
 *
 * **保証範囲を広げて言わない**: 見るのはキー集合の一致だけである。共有 prop の名前 (`flash`) や
 * 見分け用キーの名前 (`visitKey`) のずれは見ない。
 */

const ROOT = path.resolve(__dirname, "../../..");
const relaySource = fs.readFileSync(
    path.join(ROOT, "app/Support/Http/FlashNotificationRelay.php"),
    "utf-8",
);

/** NOTIFICATION_KEYS 定義ブロックの self::CONST 参照を、string 定数の実値へ解決する */
function phpNotificationKeys(): string[] {
    const block = relaySource.match(
        /const array NOTIFICATION_KEYS = \[([\s\S]*?)\];/,
    );
    if (!block)
        throw new Error(
            "NOTIFICATION_KEYS definition not found in FlashNotificationRelay.php",
        );

    const constValues = new Map<string, string>();
    for (const m of relaySource.matchAll(
        /const string ([A-Z_]+) = '([a-z_]+)';/g,
    )) {
        constValues.set(m[1], m[2]);
    }

    const keys: string[] = [];
    for (const ref of block[1].matchAll(/self::([A-Z_]+)/g)) {
        const value = constValues.get(ref[1]);
        if (value === undefined) {
            throw new Error(
                `NOTIFICATION_KEYS references unresolvable const: ${ref[1]}`,
            );
        }
        keys.push(value);
    }
    return keys;
}

describe("flash keys sync (画面側の読み手 ⇔ PHP 側の SoT)", () => {
    it("PHP ソースから NOTIFICATION_KEYS を 1 件以上抽出できる (0 件一致は定義形の変更 = fail)", () => {
        expect(phpNotificationKeys().length).toBeGreaterThan(0);
    });

    it("flash-to-toast の FLASH_KEYS と NOTIFICATION_KEYS が集合一致する", () => {
        const php = [...phpNotificationKeys()].sort();
        const js = [...FLASH_KEYS].sort();

        expect(js).toEqual(php);
    });
});
