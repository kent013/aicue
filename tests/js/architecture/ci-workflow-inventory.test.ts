/**
 * CI workflow inventory gate — `.github/workflows/ci.yml` の構成を deny-by-default で固定する。
 *
 * なぜ必要か: scripts/run-browser-test.contract.test.ts は**スクリプトの契約**を守るが、
 * workflow 側で
 *   - `browser-tests` の env に `BROWSER_TEST_LANES: chromium` を足す
 *   - どこかの step に `continue-on-error: true` を足す
 *   - `pnpm test:packages` / `pnpm build:packages` の step を消す
 * といった退行は**スクリプトを一切壊さずに**実行できる。
 * 「レーンが CI で実際に走っている」を守るには workflow 自体を inventory 化する必要がある。
 *
 * W9 / W13 は「値が正しいこと」ではなく「**現れないこと**」を検査する。
 * 文字列 grep ではコメント内の言及で偽赤になるため、**YAML を parse した後の構造を歩く**
 * (コメントは parse 時に落ちるので、`BROWSER_TEST_LANES` を**コメントで説明する**ことは許される)。
 */
import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { parse as parseYaml } from "yaml";

/** ci.yml の最小構造 (検査に必要な範囲のみ)。 */
interface WorkflowStep {
    name?: string;
    uses?: string;
    with?: Record<string, unknown>;
    run?: string;
    env?: Record<string, unknown>;
}
interface WorkflowJob {
    "runs-on"?: string;
    if?: string;
    services?: Record<string, { image?: string }>;
    env?: Record<string, unknown>;
    steps?: WorkflowStep[];
}
interface Workflow {
    on?: Record<string, unknown>;
    jobs?: Record<string, WorkflowJob>;
}

const WORKFLOW_PATH = resolve(process.cwd(), ".github/workflows/ci.yml");

function loadWorkflow(): Workflow {
    return parseYaml(readFileSync(WORKFLOW_PATH, "utf-8")) as Workflow;
}

function job(workflow: Workflow, name: string): WorkflowJob {
    const found = workflow.jobs?.[name];
    if (!found) throw new Error(`job "${name}" が ci.yml に無い`);
    return found;
}

/** 全 run 文字列を job 単位で連結する (step の分割に依存せず「実行しているか」を見るため)。 */
function runScript(target: WorkflowJob): string {
    return (target.steps ?? []).map((s) => s.run ?? "").join("\n");
}

/** `run` 文字列を「空行とコメント行を除いた実行行」へ分解する。 */
function runLines(target: WorkflowJob): string[] {
    return (target.steps ?? [])
        .flatMap((s) => (s.run ?? "").split("\n"))
        .map((l) => l.trim())
        .filter((l) => l !== "" && !l.startsWith("#"));
}

/** 任意の深さのオブジェクト木に指定 **キー名** が現れる位置を返す純関数 (W9 / W13 用)。 */
export function findKeyPaths(node: unknown, key: string, path = "$"): string[] {
    const hits: string[] = [];
    if (Array.isArray(node)) {
        node.forEach((child, i) => hits.push(...findKeyPaths(child, key, `${path}[${i}]`)));
        return hits;
    }
    if (node && typeof node === "object") {
        for (const [k, v] of Object.entries(node as Record<string, unknown>)) {
            if (k === key) hits.push(`${path}.${k}`);
            hits.push(...findKeyPaths(v, key, `${path}.${k}`));
        }
    }
    return hits;
}

/**
 * 任意の深さの木を歩き、**scalar 文字列の中身**に needle を含む位置を返す純関数 (W9 用)。
 * `run: BROWSER_TEST_LANES=chromium composer test:browser` のような
 * 「キーではなく値として仕込む」骨抜きを検出するために必要 (キー走査だけでは素通りする)。
 */
export function findScalarValuePathsContaining(node: unknown, needle: string, path = "$"): string[] {
    const hits: string[] = [];
    if (typeof node === "string") {
        if (node.includes(needle)) hits.push(path);
        return hits;
    }
    if (Array.isArray(node)) {
        node.forEach((child, i) => hits.push(...findScalarValuePathsContaining(child, needle, `${path}[${i}]`)));
        return hits;
    }
    if (node && typeof node === "object") {
        for (const [k, v] of Object.entries(node as Record<string, unknown>)) {
            hits.push(...findScalarValuePathsContaining(v, needle, `${path}.${k}`));
        }
    }
    return hits;
}

/** action 名から `@version` を落とす (version 上げで偽赤にしない)。 */
function actionName(uses: string): string {
    return uses.split("@")[0];
}

/**
 * browser-tests job で使ってよい setup action (allowlist)。
 * ここに足すことは「その action が BROWSER_TEST_* を $GITHUB_ENV へ書かない」ことの表明である。
 */
const BROWSER_JOB_ALLOWED_USES = [
    "actions/checkout",
    "shivammathur/setup-php",
    "pnpm/action-setup",
    "actions/setup-node",
] as const;

/** browser-tests job で実行してよいコマンド行 (完全一致)。
 *  追加するときは「その行が BROWSER_TEST_* を設定しうるか」を必ず確認すること。 */
const BROWSER_JOB_ALLOWED_RUN_LINES = [
    "composer install --prefer-dist --no-progress --no-interaction",
    "pnpm install --frozen-lockfile",
    "cp .env.example .env",
    "php artisan key:generate",
    "php artisan passport:keys --force",
    "pnpm build",
    "pnpm exec playwright install --with-deps chromium webkit",
    "composer test:browser",
] as const;

const LANE_ENV_VARS = ["BROWSER_TEST_LANES", "BROWSER_TEST_PROCESSES"] as const;

describe("ci.yml inventory gate", () => {
    const workflow = loadWorkflow();

    it("W1: job 集合が完全一致すること (job を増やしたらここに登録させる)", () => {
        expect(Object.keys(workflow.jobs ?? {}).sort()).toEqual(
            ["browser-tests", "frontend", "php", "supply-chain-audit"].sort(),
        );
    });

    it("W2: php / browser-tests が postgres:18-alpine service を持つこと", () => {
        for (const name of ["php", "browser-tests"]) {
            expect(job(workflow, name).services?.postgres?.image).toBe("postgres:18-alpine");
        }
    });

    it("W3: php / browser-tests の setup-php が pdo_pgsql を含むこと", () => {
        for (const name of ["php", "browser-tests"]) {
            const setup = (job(workflow, name).steps ?? []).find(
                (s) => s.uses !== undefined && actionName(s.uses) === "shivammathur/setup-php",
            );
            expect(setup, `${name} に setup-php step が無い`).toBeDefined();
            expect(String(setup?.with?.extensions ?? "")).toContain("pdo_pgsql");
        }
    });

    it("W4: php が composer test と verify-global-test-lock.sh を実行すること", () => {
        const script = runScript(job(workflow, "php"));
        expect(script).toContain("composer test");
        expect(script).toContain("bash scripts/verify-global-test-lock.sh");
    });

    it("W5: php の ffmpeg provision と fc-match fail-fast が残っていること", () => {
        const script = runScript(job(workflow, "php"));
        for (const token of ["ffmpeg", "fonts-noto-cjk", "fontconfig"]) {
            expect(script).toContain(token);
        }
        expect(script).toContain("fc-match");
        // 解決 family が Noto CJK であることの機械判定 (代替フォントへのフォールバック検出)
        expect(script).toContain("Noto Sans CJK");
    });

    it("W6/W14c: browser-tests に composer test:browser 完全一致の run step がちょうど 1 つあること", () => {
        // `includes` 判定にしないのは `run: echo "composer test:browser"` が素通りするため。
        const exact = (job(workflow, "browser-tests").steps ?? []).filter(
            (s) => (s.run ?? "").trim() === "composer test:browser",
        );
        expect(exact).toHaveLength(1);
    });

    it("W7: browser-tests が playwright install --with-deps chromium webkit を実行すること", () => {
        expect(runScript(job(workflow, "browser-tests"))).toContain(
            "pnpm exec playwright install --with-deps chromium webkit",
        );
    });

    it("W8: browser-tests が pnpm build を実行すること (実ブラウザが public/build を読む)", () => {
        expect(runLines(job(workflow, "browser-tests"))).toContain("pnpm build");
    });

    it("W9: BROWSER_TEST_LANES / BROWSER_TEST_PROCESSES が workflow のどこにも現れないこと", () => {
        for (const name of LANE_ENV_VARS) {
            // キー名としても、あらゆる scalar 値の中身としても現れてはならない
            expect(findKeyPaths(workflow, name)).toEqual([]);
            expect(findScalarValuePathsContaining(workflow, name)).toEqual([]);
        }
    });

    it("W10: frontend が全レーンを実行すること", () => {
        const lines = runLines(job(workflow, "frontend"));
        for (const command of [
            "pnpm test",
            "pnpm test:packages",
            "pnpm typecheck:packages",
            "pnpm build:packages",
            "pnpm build",
            "pnpm lint",
            "pnpm typecheck",
        ]) {
            expect(lines, `frontend に "${command}" が無い`).toContain(command);
        }
    });

    it("W11: supply-chain-audit が pnpm run audit:gate を実行すること", () => {
        expect(runScript(job(workflow, "supply-chain-audit"))).toContain("pnpm run audit:gate");
    });

    it("W12: on.schedule (nightly) が存在すること", () => {
        expect(workflow.on?.schedule).toBeDefined();
    });

    it("W15: nightly (schedule) では supply-chain-audit だけが走ること", () => {
        // on.schedule は workflow 全体を起動する。docs (review-checklist §6) が
        // 「nightly は supply-chain gate の先行検知」と書いている以上、
        // 他 job は schedule から明示除外され、**gate 自身は除外されない**ことを固定する。
        for (const name of ["php", "frontend", "browser-tests"]) {
            expect(job(workflow, name).if, `${name} が schedule から除外されていない`).toBe(
                "github.event_name != 'schedule'",
            );
        }
        // gate を nightly から外す (= 先行検知を殺す) 退行を止める
        expect(job(workflow, "supply-chain-audit").if).toBeUndefined();
    });

    it("W13: continue-on-error が workflow のどこにも現れないこと (soft-fail 禁止)", () => {
        expect(findKeyPaths(workflow, "continue-on-error")).toEqual([]);
    });

    it("W14a: browser-tests の uses が信頼済み setup action の allowlist に限定されること", () => {
        const used = (job(workflow, "browser-tests").steps ?? [])
            .filter((s) => s.uses !== undefined)
            .map((s) => actionName(s.uses as string));
        for (const name of used) {
            expect(BROWSER_JOB_ALLOWED_USES, `allowlist 外の action: ${name}`).toContain(name);
        }
    });

    it("W14b: browser-tests の run 実行行が allowlist に完全一致すること", () => {
        for (const line of runLines(job(workflow, "browser-tests"))) {
            expect(BROWSER_JOB_ALLOWED_RUN_LINES, `allowlist 外の実行行: ${line}`).toContain(line);
        }
    });
});

describe("走査関数の負のコントロール (検出器が空振りしていないこと)", () => {
    it("continue-on-error を持つ step を検出する", () => {
        const fixture = { jobs: { php: { steps: [{ run: "x", "continue-on-error": true }] } } };
        expect(findKeyPaths(fixture, "continue-on-error")).toHaveLength(1);
    });

    it("env キーとしての BROWSER_TEST_LANES を検出する", () => {
        const fixture = { jobs: { "browser-tests": { env: { BROWSER_TEST_LANES: "chromium" } } } };
        expect(findKeyPaths(fixture, "BROWSER_TEST_LANES")).toHaveLength(1);
    });

    it("run 値に埋めた BROWSER_TEST_LANES を検出する (キー走査は 0 件 = 値走査が必要な証明)", () => {
        const fixture = {
            jobs: { "browser-tests": { steps: [{ run: "BROWSER_TEST_LANES=chromium composer test:browser" }] } },
        };
        expect(findKeyPaths(fixture, "BROWSER_TEST_LANES")).toEqual([]);
        expect(findScalarValuePathsContaining(fixture, "BROWSER_TEST_LANES")).toHaveLength(1);
    });

    it("複数行 scalar に埋めた BROWSER_TEST_PROCESSES を検出する", () => {
        const fixture = {
            jobs: {
                "browser-tests": { steps: [{ run: "export BROWSER_TEST_PROCESSES=4\ncomposer test:browser" }] },
            },
        };
        expect(findKeyPaths(fixture, "BROWSER_TEST_PROCESSES")).toEqual([]);
        expect(findScalarValuePathsContaining(fixture, "BROWSER_TEST_PROCESSES")).toHaveLength(1);
    });

    it("正常な fixture では両関数とも 0 件", () => {
        const fixture = { jobs: { "browser-tests": { steps: [{ run: "composer test:browser" }] } } };
        for (const name of LANE_ENV_VARS) {
            expect(findKeyPaths(fixture, name)).toEqual([]);
            expect(findScalarValuePathsContaining(fixture, name)).toEqual([]);
        }
        expect(findKeyPaths(fixture, "continue-on-error")).toEqual([]);
    });

    it("W14a: allowlist 外の composite action を検出する", () => {
        const steps: WorkflowStep[] = [
            { uses: "actions/checkout@v4" },
            { uses: "./.github/actions/setup-browser" },
        ];
        const outside = steps
            .map((s) => actionName(s.uses as string))
            .filter((n) => !(BROWSER_JOB_ALLOWED_USES as readonly string[]).includes(n));
        expect(outside).toEqual(["./.github/actions/setup-browser"]);
    });

    it("W14b: allowlist 外のローカルスクリプト実行行を検出する", () => {
        const fixture: WorkflowJob = {
            steps: [{ run: "bash scripts/prepare-browser-ci.sh" }, { run: "composer test:browser" }],
        };
        const outside = runLines(fixture).filter(
            (l) => !(BROWSER_JOB_ALLOWED_RUN_LINES as readonly string[]).includes(l),
        );
        expect(outside).toEqual(["bash scripts/prepare-browser-ci.sh"]);
    });

    it("W14c: echo で偽装した composer test:browser を検出する", () => {
        const fixture: WorkflowJob = { steps: [{ run: 'echo "composer test:browser"' }] };
        const exact = (fixture.steps ?? []).filter((s) => (s.run ?? "").trim() === "composer test:browser");
        // includes 判定なら素通りするが、完全一致では 0 件になる
        expect(runScript(fixture)).toContain("composer test:browser");
        expect(exact).toHaveLength(0);
    });

    it("W9 + W14b/W14c: 環境変数付与つき起動は 3 検査すべてが違反を返す", () => {
        const fixture: WorkflowJob = { steps: [{ run: "BROWSER_TEST_LANES=chromium composer test:browser" }] };
        expect(findScalarValuePathsContaining(fixture, "BROWSER_TEST_LANES")).toHaveLength(1);
        expect(
            runLines(fixture).filter((l) => !(BROWSER_JOB_ALLOWED_RUN_LINES as readonly string[]).includes(l)),
        ).toHaveLength(1);
        expect((fixture.steps ?? []).filter((s) => (s.run ?? "").trim() === "composer test:browser")).toHaveLength(0);
    });

    it("composite action へ移送すると W14a と W14c の両方が違反を返す", () => {
        const fixture: WorkflowJob = { steps: [{ uses: "./.github/actions/run-browser-lanes@v1" }] };
        const outside = (fixture.steps ?? [])
            .filter((s) => s.uses !== undefined)
            .map((s) => actionName(s.uses as string))
            .filter((n) => !(BROWSER_JOB_ALLOWED_USES as readonly string[]).includes(n));
        expect(outside).toHaveLength(1);
        expect((fixture.steps ?? []).filter((s) => (s.run ?? "").trim() === "composer test:browser")).toHaveLength(0);
    });
});
