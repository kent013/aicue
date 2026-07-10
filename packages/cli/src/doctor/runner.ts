/**
 * `doctor` runner — 環境の自己診断。
 *
 * `envinfo` の薄いラッパ + v1 運用者が実際に必要とする最小チェック:
 *
 *   1. CLI パッケージ名 + version (tarball の `name` 改ざんを可視化する)。
 *   2. OS / Node / npm / pnpm version (`envinfo` 経由)。
 *   3. 資格情報バックエンドのラベル (`CredentialStore.backend()` に委譲)。
 *
 * 意図的な非対応: CLI sha256 自己整合性、sigstore 検証、--fix 自動修復、
 * JSON エビデンスファイル。v1 のユーザー価値に対し重すぎるため。署名検証は
 * `npm audit signatures <pkg>` を正規経路とする。
 */

import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import envinfo from "envinfo";
import { CLI_PACKAGE_NAME, DISPLAY_NAME, ENV } from "../branding.js";
import { CredentialStore, type StoreBackend } from "../credential/store.js";

const EXPECTED_CLI_NAME = CLI_PACKAGE_NAME;

export type DoctorRunOpts = {
    readonly json: boolean;
    readonly envinfoRun?: (json: boolean) => Promise<string>;
    readonly credentialStore?: CredentialStore | null;
    readonly packageJsonPath?: string;
    readonly stdout?: (s: string) => void;
};

export type DoctorRunResult = {
    readonly exitCode: number;
    readonly cliName: string;
    readonly cliVersion: string | null;
    readonly credentialBackend: StoreBackend;
};

type PackageJson = {
    readonly name?: unknown;
    readonly version?: unknown;
};

function defaultPackageJsonPath(): string {
    // src/doctor/runner.ts -> dist/doctor/runner.js -> ../../package.json
    const here = dirname(fileURLToPath(import.meta.url));
    return join(here, "..", "..", "package.json");
}

function readCliPkg(path: string): {
    name: string | null;
    version: string | null;
} {
    try {
        const parsed = JSON.parse(readFileSync(path, "utf-8")) as PackageJson;
        return {
            name: typeof parsed.name === "string" ? parsed.name : null,
            version: typeof parsed.version === "string" ? parsed.version : null,
        };
    } catch {
        return { name: null, version: null };
    }
}

async function defaultEnvinfo(json: boolean): Promise<string> {
    return envinfo.run(
        {
            System: ["OS"],
            Binaries: ["Node", "npm", "pnpm"],
        },
        { json, showNotFound: true },
    );
}

function credentialBackendLabel(backend: StoreBackend): string {
    switch (backend) {
        case "keychain":
            return "OS keychain (@napi-rs/keyring)";
        case "file-encrypted":
            return "encrypted file store";
        case "file-plaintext":
            return "plaintext file store (opt-in)";
        case "unavailable":
            return "unavailable";
    }
}

export async function runDoctor(
    opts: DoctorRunOpts,
): Promise<DoctorRunResult> {
    const pkgPath = opts.packageJsonPath ?? defaultPackageJsonPath();
    const pkg = readCliPkg(pkgPath);
    const cliName = pkg.name ?? EXPECTED_CLI_NAME;
    const nameMatches = pkg.name === EXPECTED_CLI_NAME;

    const envinfoRun = opts.envinfoRun ?? defaultEnvinfo;
    let sysInfo: string | null;
    try {
        sysInfo = await envinfoRun(opts.json);
    } catch {
        sysInfo = null;
    }

    const store =
        opts.credentialStore === undefined
            ? new CredentialStore()
            : opts.credentialStore;
    const credBackend: StoreBackend =
        store === null ? "unavailable" : store.backend();

    const write = opts.stdout ?? ((s: string) => process.stdout.write(s));

    if (opts.json) {
        let system: unknown = null;
        if (sysInfo !== null) {
            try {
                system = JSON.parse(sysInfo);
            } catch {
                system = null;
            }
        }
        const payload = {
            cli: {
                name: cliName,
                version: pkg.version,
                name_matches: nameMatches,
            },
            system,
            credential_backend: credBackend,
            signature_verification_hint: `npm audit signatures ${EXPECTED_CLI_NAME}`,
        };
        write(JSON.stringify(payload, null, 2) + "\n");
    } else {
        const lines: string[] = [];
        lines.push(`${DISPLAY_NAME} doctor`);
        lines.push("======================");
        lines.push(`CLI: ${cliName}@${pkg.version ?? "?"}`);
        if (!nameMatches) {
            lines.push(
                `  warning: package name does not match ${EXPECTED_CLI_NAME}`,
            );
        }
        lines.push("");
        lines.push(
            sysInfo === null ? "(system info unavailable)" : sysInfo.trimEnd(),
        );
        lines.push("");
        lines.push(`Credential backend: ${credentialBackendLabel(credBackend)}`);
        if (credBackend === "unavailable") {
            lines.push(
                `  hint: set ${ENV.MASTER_PASSWORD} or ${ENV.CREDENTIAL_KEY}, `
                    + "or pass --allow-plaintext-credentials to enable the file store",
            );
        }
        lines.push("");
        lines.push("Signature verification (run manually):");
        lines.push(`  npm audit signatures ${EXPECTED_CLI_NAME}`);
        write(lines.join("\n") + "\n");
    }

    // 資格情報バックエンド unavailable / 名前不一致のみ exit 1。
    const exitCode = credBackend === "unavailable" || !nameMatches ? 1 : 0;

    return {
        exitCode,
        cliName,
        cliVersion: pkg.version,
        credentialBackend: credBackend,
    };
}
