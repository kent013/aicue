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
import { readdirSync, readFileSync } from "node:fs";
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
    // `on:` は map / 配列 / scalar の 3 形式が有効なため unknown で受け、triggerNames で正規化する。
    on?: unknown;
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

/**
 * workflow の `on:` に宣言されたトリガー名を昇順で返す純関数 (W12 / W17 用)。
 *
 * GitHub Actions の `on:` は **3 形式すべて有効**なので全部正規化する
 * (`Object.keys()` だけだと配列形式で `["0","1"]`、scalar 形式で文字位置のキーが返り、
 *  **schedule を素通りさせる偽グリーン**になる):
 *   - map 形式    `on:\n  push:\n  schedule:\n    - cron: "…"`
 *   - 配列形式    `on: [push, schedule]`
 *   - scalar 形式 `on: schedule`
 *
 * `on` が未定義なら空配列を返す。YAML 1.1 互換の parser が `on` を boolean `true` として
 * 解釈した場合もここが空配列になり **W12 が落ちる** (静かに素通りしない fail-safe な向き)。
 */
export function triggerNames(workflow: Workflow): string[] {
    const triggers = workflow.on;

    // scalar 形式: `on: schedule`
    if (typeof triggers === "string") return [triggers];

    // 配列形式: `on: [push, schedule]`
    if (Array.isArray(triggers)) {
        return triggers.filter((name): name is string => typeof name === "string").sort();
    }

    // map 形式: `on:\n  push:\n  pull_request:`
    if (triggers !== null && typeof triggers === "object") return Object.keys(triggers).sort();

    // 未定義 / boolean true として parse された場合 (静かに素通りさせず W12 を落とす)
    return [];
}

/**
 * job-level `if` を持つ job 名を宣言順で返す純関数 (W15 用)。
 * 値ではなく **`if` の有無**を見るので、条件式の言い換えでは逃げられない。
 */
export function jobsWithCondition(workflow: Workflow): string[] {
    return Object.entries(workflow.jobs ?? {})
        .filter(([, target]) => target.if !== undefined)
        .map(([name]) => name);
}

/**
 * workflow ファイル群のうち `on:` に `schedule` を持つものの**ファイル名**を返す純関数 (W17 用)。
 * 入力は「ファイル名 → YAML テキスト」の対にして、FS 走査と検査を分離する
 * (負のコントロールから FS に触れずに呼べるようにするため)。
 */
export function workflowsWithSchedule(files: ReadonlyArray<readonly [name: string, yaml: string]>): string[] {
    return files
        .filter(([, yaml]) => triggerNames(parseYaml(yaml) as Workflow).includes("schedule"))
        .map(([name]) => name)
        .sort(); // 診断メッセージを readdirSync の順序に依存させない
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

    it("W12: on のトリガー集合が push / pull_request と完全一致すること", () => {
        // **定期実行 (schedule) は持たない** — CI の責務は push / pull_request の同期検査に
        // 限るという裁定による (理由と受容した損失は ci.yml の on: 直上のコメントと
        // docs/supply-chain/review-checklist.md §6)。
        //
        // 「schedule が無いこと」ではなく **集合の完全一致** で固定するのは、
        // repository_dispatch を外部 cron から叩く等、別名で定期実行を復活させる経路を
        // 塞ぐため。トリガーを増やすときはこの配列に登録させる (W1 と同じ作法)。
        expect(triggerNames(workflow)).toEqual(["pull_request", "push"]);
    });

    it("W15: どの job も job-level if を持たないこと", () => {
        // 定期実行トリガーの除去に伴い `if: github.event_name != 'schedule'` を全廃した。
        // 値ではなく **`if` の有無** を見るのは、`!contains(github.event_name, 'schedule')`
        // のような言い換えを一網打尽にするため。
        //
        // job-level `if` は **deny-by-default**。条件付き job が必要になったら、
        // W14a / W14b と同じく「理由付きの allowlist」としてここへ登録すること
        // (黙って足すと、条件の形を変えた定期実行の復活を見逃す)。
        expect(jobsWithCondition(workflow), "job-level if は deny-by-default (登録が必要)").toEqual([]);
    });

    it("W17: .github/workflows のどの workflow も schedule トリガーを持たないこと", () => {
        // W12 は ci.yml 1 ファイルしか縛らない。裁定は「CI の定期実行トリガを持たない」で
        // あってファイル名の話ではないので、別 workflow を新設して定期実行を復活させる
        // 経路もここで塞ぐ。
        const dir = resolve(process.cwd(), ".github/workflows");
        const files = readdirSync(dir)
            .filter((name) => name.endsWith(".yml") || name.endsWith(".yaml"))
            .map((name) => [name, readFileSync(resolve(dir, name), "utf-8")] as [string, string]);

        // 空振り防止: workflow ファイルが 1 本も無い状態で green にならないこと
        expect(files.length, "workflow ファイルが 1 本も見つからない (走査パスの誤り)").toBeGreaterThanOrEqual(1);
        expect(workflowsWithSchedule(files), "schedule トリガーを持つ workflow").toEqual([]);
    });

    it("W16: php が bug-hunt インベントリの drift 検知を **実行行として** 持つこと", () => {
        // T106 (passkey 7 route) / T107 (settings.password.store) で 2 サイクル連続して
        // .claude/skills/app-bug-hunt/{screens,operations}.md がドリフトし、
        // 「認証系が bug-hunt のカバレッジから丸ごと落ちる」実害が出た。
        //
        // runScript ではなく runLines を使う: runScript はコメント行も連結するため
        // 「# bug-hunt-inventory-check.sh は将来入れる」というコメントで green になる
        // (既存 W14b/W14c と同じ「実行行だけを見る」方針)。
        const lines = runLines(job(workflow, "php"));
        const mentions = lines.filter((l) => l.includes("scripts/bug-hunt-inventory-check.sh"));
        expect(mentions, "php job に bug-hunt インベントリ drift 検知の実行行が無い").not.toEqual([]);

        // `includes` だけでは `... || true` / `echo "bash scripts/..."` の soft-fail 偽装が素通りする
        // (W6/W14c と同じ理由で完全一致を要求する)。continue-on-error 自体は W13 が別途禁じている。
        expect(mentions, "drift 検知が完全一致の実行行になっていない (soft-fail 偽装の疑い)").toEqual([
            "bash scripts/bug-hunt-inventory-check.sh",
        ]);
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

    it("W12: 復活した schedule トリガーを検出する", () => {
        const fixture = {
            on: { push: null, pull_request: null, schedule: [{ cron: "0 20 * * *" }] },
        } as Workflow;
        expect(triggerNames(fixture)).not.toEqual(["pull_request", "push"]);
        expect(triggerNames(fixture)).toContain("schedule");
    });

    it("W12: 別名トリガー (repository_dispatch) の追加も検出する", () => {
        const fixture = { on: { push: null, pull_request: null, repository_dispatch: null } } as Workflow;
        expect(triggerNames(fixture)).not.toEqual(["pull_request", "push"]);
    });

    it("W15: 復活した job-level if を条件式の形によらず検出する", () => {
        const fixture = {
            jobs: {
                php: { if: "github.event_name != 'schedule'" },
                frontend: { if: "!contains(github.event_name, 'schedule')" },
                "supply-chain-audit": {},
            },
        } as Workflow;
        // 言い換えた条件式も同じく検出される (値ではなく有無を見ているため)
        expect(jobsWithCondition(fixture)).toEqual(["php", "frontend"]);
    });

    it("W17: 別 workflow ファイルに新設された schedule を検出する (map 形式)", () => {
        const files: Array<[string, string]> = [
            ["ci.yml", "on:\n  push:\n    branches: [main]\n  pull_request:\n"],
            ["secret-scan.yml", "on:\n  pull_request:\n    branches:\n      - main\n"],
            ["scheduled-audit.yml", 'on:\n  schedule:\n    - cron: "0 20 * * *"\n'],
        ];
        expect(workflowsWithSchedule(files)).toEqual(["scheduled-audit.yml"]);
    });

    it("W17: 配列形式 / scalar 形式の on も検出する (map 形式だけ見ると素通りする)", () => {
        // GitHub Actions では 3 形式すべて有効。Object.keys() だけの実装では
        // 配列形式が ["0","1"]、scalar 形式が文字位置のキーになり **schedule を見落とす**。
        expect(workflowsWithSchedule([["array.yml", "on: [push, schedule]\n"]])).toEqual(["array.yml"]);
        expect(workflowsWithSchedule([["scalar.yml", "on: schedule\n"]])).toEqual(["scalar.yml"]);
        // 正のコントロール: 同じ形式でも schedule を含まなければ検出しない
        expect(workflowsWithSchedule([["array.yml", "on: [push, pull_request]\n"]])).toEqual([]);
    });

    it("triggerNames: 3 形式を同じ語彙へ正規化する", () => {
        expect(triggerNames({ on: { push: null, pull_request: null } })).toEqual(["pull_request", "push"]);
        expect(triggerNames({ on: ["push", "schedule"] })).toEqual(["push", "schedule"]);
        expect(triggerNames({ on: "schedule" })).toEqual(["schedule"]);
        expect(triggerNames({})).toEqual([]);
        // parser が YAML 1.1 の boolean として解釈した場合も空配列 (= W12 が落ちる向き)
        expect(triggerNames({ on: true })).toEqual([]);
    });

    it("正常な fixture では W12 / W15 / W17 とも違反 0 件", () => {
        const fixture = {
            on: { push: null, pull_request: null },
            jobs: { php: {}, "supply-chain-audit": {} },
        } as Workflow;
        expect(triggerNames(fixture)).toEqual(["pull_request", "push"]);
        expect(jobsWithCondition(fixture)).toEqual([]);
        expect(workflowsWithSchedule([["ci.yml", "on:\n  push:\n  pull_request:\n"]])).toEqual([]);
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
