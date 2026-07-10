import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { CLI_PACKAGE_NAME, DISPLAY_NAME, ENV } from "../../../src/branding.js";
import type {
    CredentialStore,
    StoreBackend,
} from "../../../src/credential/store.js";
import { runDoctor } from "../../../src/doctor/runner.js";

function fakeStore(backend: StoreBackend): CredentialStore {
    return { backend: () => backend } as unknown as CredentialStore;
}

const fakeEnvinfoText = [
    "",
    "  System:",
    "    OS: Linux 6.17 Ubuntu 24.04",
    "  Binaries:",
    "    Node: 22.14.0",
    "    npm: 11.5.1",
    "",
].join("\n");

const fakeEnvinfoJson = JSON.stringify({
    System: { OS: "Linux 6.17 Ubuntu 24.04" },
    Binaries: {
        Node: { version: "22.14.0", path: "/usr/bin/node" },
        npm: { version: "11.5.1", path: "/usr/bin/npm" },
    },
});

let tmp: string;
let pkgPath: string;

beforeEach(() => {
    tmp = mkdtempSync(join(tmpdir(), "cli-doctor-"));
    pkgPath = join(tmp, "package.json");
    writeFileSync(
        pkgPath,
        JSON.stringify({ name: CLI_PACKAGE_NAME, version: "0.1.0" }),
    );
});

afterEach(() => {
    rmSync(tmp, { recursive: true, force: true });
});

function healthyOpts(
    overrides: Partial<Parameters<typeof runDoctor>[0]> = {},
): Parameters<typeof runDoctor>[0] {
    return {
        json: false,
        envinfoRun: async (json: boolean) =>
            json ? fakeEnvinfoJson : fakeEnvinfoText,
        credentialStore: fakeStore("keychain"),
        packageJsonPath: pkgPath,
        ...overrides,
    };
}

describe("runDoctor (text)", () => {
    it("reports CLI name, envinfo block and backend, and exits 0", async () => {
        let out = "";
        const result = await runDoctor(
            healthyOpts({ stdout: (s) => (out += s) }),
        );
        expect(out).toContain(`${DISPLAY_NAME} doctor`);
        expect(out).toContain(`CLI: ${CLI_PACKAGE_NAME}@0.1.0`);
        expect(out).toContain("OS: Linux");
        expect(out).toContain(`npm audit signatures ${CLI_PACKAGE_NAME}`);
        expect(result.exitCode).toBe(0);
        expect(result.credentialBackend).toBe("keychain");
    });

    it("prints a plaintext hint and exits 1 when the backend is unavailable", async () => {
        let out = "";
        const result = await runDoctor(
            healthyOpts({
                credentialStore: fakeStore("unavailable"),
                stdout: (s) => (out += s),
            }),
        );
        expect(out).toContain(ENV.MASTER_PASSWORD);
        expect(out).toContain(ENV.CREDENTIAL_KEY);
        expect(result.exitCode).toBe(1);
    });

    it("warns and exits 1 when the package name is tampered", async () => {
        writeFileSync(
            pkgPath,
            JSON.stringify({ name: "@evil/cli", version: "0.1.0" }),
        );
        let out = "";
        const result = await runDoctor(
            healthyOpts({ stdout: (s) => (out += s) }),
        );
        expect(out).toContain(
            `package name does not match ${CLI_PACKAGE_NAME}`,
        );
        expect(result.exitCode).toBe(1);
    });

    it("tolerates a missing version field (renders @?)", async () => {
        writeFileSync(pkgPath, JSON.stringify({ name: CLI_PACKAGE_NAME }));
        let out = "";
        await runDoctor(healthyOpts({ stdout: (s) => (out += s) }));
        expect(out).toContain(`CLI: ${CLI_PACKAGE_NAME}@?`);
    });
});

describe("runDoctor (json)", () => {
    it("emits a machine-readable payload", async () => {
        let out = "";
        await runDoctor(
            healthyOpts({ json: true, stdout: (s) => (out += s) }),
        );
        const parsed = JSON.parse(out) as {
            cli: { name: string; name_matches: boolean };
            credential_backend: string;
            signature_verification_hint: string;
        };
        expect(parsed.cli.name).toBe(CLI_PACKAGE_NAME);
        expect(parsed.cli.name_matches).toBe(true);
        expect(parsed.credential_backend).toBe("keychain");
        expect(parsed.signature_verification_hint).toContain(
            CLI_PACKAGE_NAME,
        );
    });
});
