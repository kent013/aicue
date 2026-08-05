/**
 * scripts/audit-gate.sh の **取得契約** テスト。
 *
 * supply-chain gate を blocking へ昇格させる以上、「取得できなかった」を
 * 「advisory 0 件 = 安全」と読み替える経路を残せない。旧実装は空出力を
 * `{"advisories":{}}` で捏造して判定へ渡していたため、network 不通なら緑になった。
 *
 * 本テストが検証するのは **「判定に到達したか / 手前で止まったか」** に限定される。
 * 判定ロジック自体 (JSON 妥当性・schema・severity) は scripts/audit-gate.test.ts の
 * unit テストの責務であり、責務を混ぜない。
 *
 * 方式: mkdtemp の sandbox に audit-gate.sh を verbatim コピーし、
 * `PATH=$SANDBOX/bin:$PATH` で pnpm / composer / uv を引数分岐スタブへ差し替える。
 * `pnpm exec tsx ...` (判定) に到達したら $SANDBOX/judged が作られる。
 *
 * 実行: pnpm test (vitest の include に scripts/**\/*.test.ts が含まれる)
 */
import { describe, expect, it } from "vitest";
import { spawnSync } from "node:child_process";
import { chmodSync, copyFileSync, existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const REPO_ROOT = process.cwd();
const SCRIPT_PATH = resolve(REPO_ROOT, "scripts/audit-gate.sh");

/** 1 ツール分のスタブ挙動 (出力内容と exit code)。 */
interface StubBehaviour {
    /** stdout へ出す内容。空文字 = 何も出力しない (取得失敗の模擬)。 */
    stdout: string;
    /** exit code。 */
    exit: number;
}

interface Scenario {
    pnpmAudit?: StubBehaviour;
    composerAudit?: StubBehaviour;
    /** `uv export` の挙動 (pyproject.toml がある場合のみ使われる)。 */
    uvExport?: StubBehaviour;
    /** `uv tool run --from pip-audit ...` の挙動。 */
    pipAudit?: StubBehaviour;
    /** true なら sandbox に pyproject.toml を置く (pip 経路のオプトイン条件を実際に踏む)。 */
    pyproject?: boolean;
}

interface ContractRun {
    /** audit-gate.sh の終了コード。 */
    status: number;
    /** 判定 (`pnpm exec tsx scripts/audit-gate.ts`) に到達したか。 */
    judged: boolean;
    /** bin/uv が一度でも呼ばれたか (A10 用)。 */
    uvInvoked: boolean;
    stderr: string;
}

const OK_PNPM = '{"advisories":{}}';
const OK_COMPOSER = '{"advisories":{}}';
const OK_REQUIREMENTS = "requests==2.32.3\n";
const OK_PIP = '{"dependencies":[]}';

function writeExecutable(path: string, content: string): void {
    writeFileSync(path, content, "utf-8");
    chmodSync(path, 0o755);
}

/** stdout / exit code を返すだけの bash 分岐を組み立てる。 */
function emit(behaviour: StubBehaviour): string {
    const body =
        behaviour.stdout === ""
            ? "  : # 出力なし (取得失敗の模擬)"
            : `  cat <<'STUB_EOF'\n${behaviour.stdout}\nSTUB_EOF`;
    return `${body}\n  exit ${behaviour.exit}`;
}

function runScenario(scenario: Scenario): ContractRun {
    const sandbox = mkdtempSync(join(tmpdir(), "audit-gate-contract-"));
    try {
        mkdirSync(join(sandbox, "scripts"), { recursive: true });
        mkdirSync(join(sandbox, "bin"), { recursive: true });

        copyFileSync(SCRIPT_PATH, join(sandbox, "scripts/audit-gate.sh"));
        chmodSync(join(sandbox, "scripts/audit-gate.sh"), 0o755);

        if (scenario.pyproject) {
            writeFileSync(join(sandbox, "pyproject.toml"), "[project]\nname = \"x\"\n", "utf-8");
        }

        const judgedMarker = join(sandbox, "judged");
        const uvMarker = join(sandbox, "uv-invoked");

        // pnpm スタブ: `pnpm audit ...` と `pnpm exec tsx ...` を引数で分岐する
        const pnpmAudit = scenario.pnpmAudit ?? { stdout: OK_PNPM, exit: 0 };
        writeExecutable(
            join(sandbox, "bin/pnpm"),
            [
                "#!/usr/bin/env bash",
                'if [ "$1" = "audit" ]; then',
                emit(pnpmAudit),
                'elif [ "$1" = "exec" ]; then',
                `  touch "${judgedMarker}"`,
                "  exit 0",
                "fi",
                'echo "unexpected pnpm invocation: $*" >&2',
                "exit 99",
            ].join("\n"),
        );

        const composerAudit = scenario.composerAudit ?? { stdout: OK_COMPOSER, exit: 0 };
        writeExecutable(
            join(sandbox, "bin/composer"),
            [
                "#!/usr/bin/env bash",
                'if [ "$1" = "audit" ]; then',
                emit(composerAudit),
                "fi",
                'echo "unexpected composer invocation: $*" >&2',
                "exit 99",
            ].join("\n"),
        );

        // uv スタブ: `uv export ...` と `uv tool run --from pip-audit ...` を分岐する
        const uvExport = scenario.uvExport ?? { stdout: OK_REQUIREMENTS, exit: 0 };
        const pipAudit = scenario.pipAudit ?? { stdout: OK_PIP, exit: 0 };
        writeExecutable(
            join(sandbox, "bin/uv"),
            [
                "#!/usr/bin/env bash",
                `touch "${uvMarker}"`,
                'if [ "$1" = "export" ]; then',
                emit(uvExport),
                'elif [ "$1" = "tool" ]; then',
                emit(pipAudit),
                "fi",
                'echo "unexpected uv invocation: $*" >&2',
                "exit 99",
            ].join("\n"),
        );

        const result = spawnSync("bash", [join(sandbox, "scripts/audit-gate.sh")], {
            encoding: "utf-8",
            env: { ...process.env, PATH: `${join(sandbox, "bin")}:${process.env.PATH ?? ""}` },
        });

        return {
            status: result.status ?? -1,
            judged: existsSync(judgedMarker),
            uvInvoked: existsSync(uvMarker),
            stderr: result.stderr ?? "",
        };
    } finally {
        rmSync(sandbox, { recursive: true, force: true });
    }
}

describe("audit-gate.sh の取得契約: 取得失敗は fail-closed で止める", () => {
    it("A1: pnpm audit が空出力 + exit 0 なら判定へ進まず非ゼロ終了", () => {
        const run = runScenario({ pnpmAudit: { stdout: "", exit: 0 } });

        expect(run.status).not.toBe(0);
        expect(run.judged).toBe(false);
        expect(run.stderr).toContain("produced no output");
    });

    it("A2: pnpm audit が空出力 + exit 1 (ネットワーク失敗) なら判定へ進まず非ゼロ終了", () => {
        const run = runScenario({ pnpmAudit: { stdout: "", exit: 1 } });

        expect(run.status).not.toBe(0);
        expect(run.judged).toBe(false);
    });

    it("A6: composer 側だけ空出力でも止まる (片側の失敗でも fail-closed)", () => {
        const run = runScenario({ composerAudit: { stdout: "", exit: 0 } });

        expect(run.status).not.toBe(0);
        expect(run.judged).toBe(false);
    });
});

describe("audit-gate.sh の取得契約: 正常系を巻き込まない (負のコントロール)", () => {
    it("A3: 不正 JSON でも非空なら判定へ到達する (JSON 妥当性は判定層の責務)", () => {
        const run = runScenario({ pnpmAudit: { stdout: "not json", exit: 0 } });

        expect(run.judged).toBe(true);
    });

    it("A4: 有効 JSON + 非ゼロ exit (= 脆弱性検出の正常系) は判定へ到達する", () => {
        const run = runScenario({
            pnpmAudit: { stdout: '{"advisories":{"1":{"id":"GHSA-x","severity":"high"}}}', exit: 1 },
            composerAudit: { stdout: OK_COMPOSER, exit: 1 },
        });

        expect(run.judged).toBe(true);
    });

    it("A5: 本当に 0 件 ({\"advisories\":{}}) + exit 0 は判定へ到達する", () => {
        const run = runScenario({});

        expect(run.status).toBe(0);
        expect(run.judged).toBe(true);
    });

    it("A10: pyproject.toml が無ければ pip 経路を実行せず判定へ到達する", () => {
        const run = runScenario({ pyproject: false });

        expect(run.judged).toBe(true);
        expect(run.uvInvoked).toBe(false);
    });
});

describe("audit-gate.sh の取得契約: pip 経路 (pyproject.toml あり)", () => {
    it("A7a: uv export が空出力なら判定へ進まず非ゼロ終了", () => {
        const run = runScenario({ pyproject: true, uvExport: { stdout: "", exit: 0 } });

        expect(run.status).not.toBe(0);
        expect(run.judged).toBe(false);
    });

    it("A7b: uv export が非空出力 + exit 1 でも止まる (acquire_required の存在意義)", () => {
        // 部分的 / コメントだけの requirements を残して失敗する経路は A7a では捕まらない。
        // ここを通してしまうと、痩せた requirements に対する「advisory 0 件」で緑になる。
        const run = runScenario({
            pyproject: true,
            uvExport: { stdout: "# partial export\n", exit: 1 },
        });

        expect(run.status).not.toBe(0);
        expect(run.judged).toBe(false);
        expect(run.stderr).toContain("never 'findings'");
    });

    it("A8: pip-audit が空出力なら判定へ進まず非ゼロ終了", () => {
        const run = runScenario({ pyproject: true, pipAudit: { stdout: "", exit: 0 } });

        expect(run.status).not.toBe(0);
        expect(run.judged).toBe(false);
    });

    it("A9: pip-audit が有効 JSON + 非ゼロ exit なら判定へ到達する (検出の正常系を止めない)", () => {
        const run = runScenario({
            pyproject: true,
            pipAudit: {
                stdout: '{"dependencies":[{"name":"x","vulns":[{"id":"PYSEC-1"}]}]}',
                exit: 1,
            },
        });

        expect(run.judged).toBe(true);
    });
});
