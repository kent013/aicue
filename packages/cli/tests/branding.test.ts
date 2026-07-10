import { describe, expect, it } from "vitest";
import {
    APP_SLUG,
    BIN_NAME,
    CLI_PACKAGE_NAME,
    CONFIG_DIR_NAME,
    ENV,
    ENV_PREFIX,
    KEYCHAIN_SERVICE,
    envName,
} from "../src/branding.js";

// ブランディングの単一ソースから、環境変数名・ディレクトリ名・bin 名などが
// slug から一貫して派生することを固定する。init.sh が slug を差し替えても
// この不変条件が壊れないことの回帰テスト。
describe("branding", () => {
    it("すべての識別子が単一の slug から派生する", () => {
        expect(BIN_NAME).toBe(APP_SLUG);
        expect(KEYCHAIN_SERVICE).toBe(APP_SLUG);
        expect(ENV_PREFIX).toBe(APP_SLUG.toUpperCase());
        expect(CONFIG_DIR_NAME).toBe(`.${APP_SLUG}`);
        expect(CLI_PACKAGE_NAME).toBe(`@${APP_SLUG}/cli`);
    });

    it("環境変数名は PREFIX_<suffix> 形式", () => {
        expect(envName("API_URL")).toBe(`${ENV_PREFIX}_API_URL`);
        expect(ENV.API_URL).toBe(`${ENV_PREFIX}_API_URL`);
        expect(ENV.CREDENTIAL_KEY).toBe(`${ENV_PREFIX}_CREDENTIAL_KEY`);
        expect(ENV.ALLOW_PLAINTEXT).toBe(
            `${ENV_PREFIX}_ALLOW_PLAINTEXT_CREDENTIALS`,
        );
    });

    it("派生アプリの識別子 (spirux/aigenba) を含まない", () => {
        const blob = JSON.stringify({
            APP_SLUG,
            BIN_NAME,
            ENV_PREFIX,
            CONFIG_DIR_NAME,
            CLI_PACKAGE_NAME,
            ENV,
        }).toLowerCase();
        expect(blob).not.toContain("spirux");
        expect(blob).not.toContain("aigenba");
    });
});
