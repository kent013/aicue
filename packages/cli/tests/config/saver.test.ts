import {
    existsSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    readdirSync,
    rmSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { saveConfigToPath } from "../../src/config/saver.js";
import { loadConfigFromPath } from "../../src/config/loader.js";

let tmp: string;
let configPath: string;
/** atomicWriteFile が使う一時パス (pid 依存)。 */
let tmpWritePath: string;

beforeEach(() => {
    tmp = mkdtempSync(join(tmpdir(), "cli-saver-"));
    configPath = join(tmp, "config.yaml");
    tmpWritePath = `${configPath}.${String(process.pid)}.tmp`;
});

afterEach(() => {
    // 失敗注入用ディレクトリを必ず除去する。残すと **同一 pid の後続テストの
    // atomicWriteFile を巻き添えで失敗させる** (vitest は同一プロセスで
    // 複数テストファイルを走らせうる)。
    rmSync(tmpWritePath, { recursive: true, force: true });
    rmSync(tmp, { recursive: true, force: true });
});

describe("saveConfigToPath — atomic replacement", () => {
    it("一時ファイル書き込みが失敗しても既存 config が旧内容のまま残る", () => {
        saveConfigToPath(configPath, {
            default_profile: "prod",
            profiles: { prod: { api_url: "https://a.example.com" } },
        });
        const before = readFileSync(configPath, "utf-8");

        // 一時パスをディレクトリとして先に作ると tmp 書き込みが必ず失敗する
        // (EISDIR)。決定的に再現できる失敗注入。
        mkdirSync(tmpWritePath, { recursive: true });

        expect(() =>
            saveConfigToPath(configPath, {
                default_profile: "staging",
                profiles: { staging: { api_url: "https://b.example.com" } },
            }),
        ).toThrow();

        // 直接上書き実装 (旧実装) ではここで新内容に化けて赤くなる。
        expect(readFileSync(configPath, "utf-8")).toBe(before);
        const reloaded = loadConfigFromPath(configPath);
        expect(reloaded?.default_profile).toBe("prod");
    });

    it("正常保存後に .tmp 残骸が無く、内容が読み戻せる", () => {
        saveConfigToPath(configPath, {
            default_profile: "prod",
            profiles: { prod: { api_url: "https://a.example.com" } },
        });
        expect(existsSync(tmpWritePath)).toBe(false);
        expect(readdirSync(tmp).filter((f) => f.endsWith(".tmp"))).toEqual([]);
        expect(loadConfigFromPath(configPath)?.default_profile).toBe("prod");
    });
});

describe("saveConfigToPath — 構造ガード", () => {
    it("saver.ts は writeFileSync を直接使わず atomicWriteFile 経由である", () => {
        const src = readFileSync(
            new URL("../../src/config/saver.ts", import.meta.url),
            "utf-8",
        );
        expect(src).toContain("atomicWriteFile");
        expect(src).not.toContain("writeFileSync");
    });
});
