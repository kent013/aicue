import { describe, expect, it } from "vitest";
import {
    OIDC_CONNECTION_STATUS_HINTS,
    OIDC_CONNECTION_STATUS_LABELS,
    OIDC_CONNECTION_STATUS_TONES,
    type OidcConnectionStatus,
} from "@/components/features/sso/oidc-connection";

/**
 * 企業 OIDC 接続の状態の値域と表示語彙。
 *
 * ★値集合が PHP 列挙と一致することは `tests/js/architecture/enum-ts-sync.test.ts` が
 *   目録経由で固定する。ここが見るのは「**4 値すべてに表示語彙が揃っている**」ことである
 *   (片方だけ足すと画面が undefined を描く)。
 */

const ALL_STATUSES: OidcConnectionStatus[] = ["draft", "verified", "active", "disabled"];

describe("OIDC 接続の状態の表示語彙", () => {
    it("4 値すべてにラベルがある", () => {
        for (const status of ALL_STATUSES) {
            expect(OIDC_CONNECTION_STATUS_LABELS[status]).toBeTruthy();
        }
        expect(Object.keys(OIDC_CONNECTION_STATUS_LABELS).sort()).toEqual([...ALL_STATUSES].sort());
    });

    it("4 値すべてにバッジの色調がある", () => {
        for (const status of ALL_STATUSES) {
            expect(OIDC_CONNECTION_STATUS_TONES[status]).toBeTruthy();
        }
        expect(Object.keys(OIDC_CONNECTION_STATUS_TONES).sort()).toEqual([...ALL_STATUSES].sort());
    });

    it("4 値すべてに「次に何をすればよいか」の説明がある", () => {
        for (const status of ALL_STATUSES) {
            expect(OIDC_CONNECTION_STATUS_HINTS[status]).toBeTruthy();
        }
        expect(Object.keys(OIDC_CONNECTION_STATUS_HINTS).sort()).toEqual([...ALL_STATUSES].sort());
    });

    it("有効な接続だけが「ログインできる」と言う", () => {
        expect(OIDC_CONNECTION_STATUS_HINTS.active).toContain("ログインできます");
        expect(OIDC_CONNECTION_STATUS_HINTS.draft).not.toContain("ログインできます");
        expect(OIDC_CONNECTION_STATUS_HINTS.verified).not.toContain("ログインできます。");
    });
});
