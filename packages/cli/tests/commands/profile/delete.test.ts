import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { BIN_NAME } from "../../../src/branding.js";
import type { RootConfigInput } from "../../../src/config/schema.js";
import { saveConfigToPath } from "../../../src/config/saver.js";
import { ExitCode } from "../../../src/exit-codes.js";
import ProfileDelete from "../../../src/oclif/commands/profile/delete.js";

/**
 * `profile:delete` の **CLI 契約** テスト。
 *
 * ロジック層 (tests/profile/delete.test.ts) では固定できない
 * 「事前検証がプロンプトより前にある」「exit code が `--yes` の有無で
 * 変わらない」といったコマンド層の約束を押さえる。
 *
 * backend は file-plaintext 1 本でよい (backend 別の網羅はロジック層の担当)。
 */

/** 確認プロンプトは spy 化する。既定は「拒否」= 非 TTY の実挙動と同じ。 */
const confirmSpy = vi.hoisted(() =>
    vi.fn<(msg: string) => Promise<boolean>>(async () => false),
);

vi.mock("../../../src/credential/prompt.js", async (importOriginal) => {
    const actual =
        await importOriginal<
            typeof import("../../../src/credential/prompt.js")
        >();
    return { ...actual, confirmPrompt: confirmSpy };
});

/** oclif Config のルート。dist をビルドしていなくても Config.load は通る。 */
const CLI_ROOT = fileURLToPath(new URL("../../../", import.meta.url));

const API_URL_A = "https://a.example.com";

let home: string;
let origHome: string | undefined;
let exitCodes: number[];

function seedConfig(config: RootConfigInput): void {
    saveConfigToPath(join(home, `.${BIN_NAME}`, "config.yaml"), config);
}

beforeEach(() => {
    origHome = process.env["HOME"];
    home = mkdtempSync(join(tmpdir(), "cli-pdel-cmd-"));
    process.env["HOME"] = home;
    exitCodes = [];
    confirmSpy.mockClear();
    confirmSpy.mockImplementation(async () => false);
    // `process.exit` の最初の記録だけが本当の意図。BaseCommand.catch が
    // その throw を拾って **もう一度** exit(1) を呼ぶため、単純な
    // rejects.toThrow では常に 1 を見てしまう。
    vi.spyOn(process, "exit").mockImplementation(((
        code?: string | number | null,
    ): never => {
        exitCodes.push(typeof code === "number" ? code : Number(code ?? 0));
        throw new Error(`EXIT:${String(code)}`);
    }) as (code?: string | number | null) => never);
    vi.spyOn(console, "error").mockImplementation(() => {});
});

afterEach(() => {
    vi.restoreAllMocks();
    rmSync(home, { recursive: true, force: true });
    if (origHome !== undefined) process.env["HOME"] = origHome;
    else delete process.env["HOME"];
});

async function runDelete(argv: readonly string[]): Promise<void> {
    await ProfileDelete.run([...argv], CLI_ROOT);
}

describe("profile:delete — CLI 契約", () => {
    it("1. 未登録プロファイルは exit 11", async () => {
        seedConfig({
            default_profile: "prod",
            profiles: { prod: { api_url: API_URL_A } },
        });
        await expect(runDelete(["ghost", "--yes"])).rejects.toThrow(/EXIT:/);
        expect(exitCodes[0]).toBe(ExitCode.ProfileNotFound);
    });

    it("2. default を --clear-default 無し・--yes 無しで消すと exit 10 (プロンプトに入らない)", async () => {
        seedConfig({
            default_profile: "prod",
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
        });
        await expect(runDelete(["prod"])).rejects.toThrow(/EXIT:/);
        expect(exitCodes[0]).toBe(ExitCode.ProfileConflict);
        // 事前検証が確認プロンプトより前にある証拠。
        expect(confirmSpy).not.toHaveBeenCalled();
    });

    it("2b. --yes を付けても exit code は同じ 10", async () => {
        seedConfig({
            default_profile: "prod",
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
        });
        await expect(runDelete(["prod", "--yes"])).rejects.toThrow(/EXIT:/);
        expect(exitCodes[0]).toBe(ExitCode.ProfileConflict);
    });

    it("3. 不正なプロファイル名は exit 13", async () => {
        seedConfig({ profiles: { prod: { api_url: API_URL_A } } });
        await expect(runDelete(["Prod", "--yes"])).rejects.toThrow(/EXIT:/);
        expect(exitCodes[0]).toBe(ExitCode.ProfileInvalidName);
    });

    it("4. 確認が取れなければ exit 1 で config は無傷", async () => {
        // 非 TTY の実 confirmPrompt は false を返す (credential/prompt.ts)。
        // spy の既定もそれに揃えている。
        seedConfig({
            default_profile: "staging",
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
        });
        await expect(runDelete(["prod"])).rejects.toThrow(/EXIT:/);
        expect(exitCodes[0]).toBe(ExitCode.GeneralError);
        expect(confirmSpy).toHaveBeenCalledTimes(1);

        const { FileProfileWriter } = await import(
            "../../../src/profile/writer.js"
        );
        expect(new FileProfileWriter().get("prod")).toBeDefined();
    });

    it("5. --yes 付きの正常削除では exit せず config から消える", async () => {
        seedConfig({
            default_profile: "staging",
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
        });
        await runDelete(["prod", "--yes"]);
        expect(exitCodes).toEqual([]);

        const { FileProfileWriter } = await import(
            "../../../src/profile/writer.js"
        );
        expect(new FileProfileWriter().get("prod")).toBeUndefined();
    });

    it("6. default を --clear-default --yes で消すと付け替え先が stdout に出る", async () => {
        seedConfig({
            default_profile: "prod",
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
        });
        // oclif の `this.log` は `process.stdout.write` への参照を module 読み込み
        // 時に束縛するため、stream への spy では捕捉できない。コマンドの出力
        // 契約そのものを見るために `log` を直接観測する。
        const lines: string[] = [];
        vi.spyOn(ProfileDelete.prototype, "log").mockImplementation(
            (message?: string): void => {
                lines.push(String(message));
            },
        );
        await runDelete(["prod", "--clear-default", "--yes"]);
        expect(lines).toContain("default_profile = staging");
    });
});
