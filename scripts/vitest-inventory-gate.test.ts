/**
 * vitest inventory gate — 「書いたのに走っていないテスト」を deny-by-default で検出する。
 *
 * 検査は 2 系統を **独立に** 求めて突き合わせる:
 *   A. FS 走査  — repo を自前で歩いて `*.test.ts` を全部拾う (SoT の glob を使わない)
 *   B. vitest 列挙 — 各 project で `vitest list --json=<tmpfile>` を実行して実際の収集結果を取る
 *
 * SoT の glob を A にも使うと同語反復になり、**glob そのものの誤りを検出できない**。
 * だから A は glob を使わず「拡張子が .test.ts のファイル」という素朴な定義で歩く。
 *
 * **非交渉の実装制約**:
 *   1. spawn は **module top-level と `describe` callback の中では絶対に行わない**。
 *      許されるのは「通常実行時にだけ走る callback」= `it` / `beforeAll` / `beforeEach` の
 *      内側だけである。理由: 本ファイル自身が root project の include に入るため、
 *      `vitest list` は本ファイルを **import して `describe` を評価する** (収集フェーズ)。
 *      収集フェーズで評価される場所に spawn を置くと無限再帰する。
 *      逆に `it`/hook の callback は収集フェーズでは **登録されるだけで実行されない**ため、
 *      `beforeAll` に置いても再帰しない。
 *      helper 関数も「呼ばれたときに spawn する」形にし、module 初期化時に spawn しない。
 *   2. `vitest list --json` は **stdout に vite plugin の警告が混ざる** (実測)。
 *      必ず `--json=<tmpfile>` でファイル出力し、ファイルを読む。
 *   3. 再帰防止用の env フラグは導入しない。`vitest list` は収集のみで実行しないため
 *      制約 1 だけで再帰は起きない。フラグを足すと「そのフラグが立つと gate が空振りする」
 *      新しい偽グリーン経路を作ることになる。
 */
import { beforeAll, describe, expect, it } from "vitest";
import { execFileSync } from "node:child_process";
import { mkdtempSync, readFileSync, readdirSync, realpathSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { TEST_PROJECTS } from "./test-inventory-config";

/** repo root。scripts/run-vitest.sh が repo root で起動する前提 (feedback-probe.test.ts と同じ)。 */
const REPO_ROOT = process.cwd();

/**
 * FS 走査から除外するディレクトリ名と、その理由。
 * **除外を増やすときは「そこに走らせるべきテストが無い」ことを確認すること。**
 */
const FS_SCAN_EXCLUDED_DIRS: Record<string, string> = {
    node_modules: "依存パッケージ。自リポジトリのテストではない",
    vendor: "composer 依存。同上",
    ".git": "VCS メタデータ",
    dist: "ビルド成果物 (packages/cli の emit 先)",
    devnotes:
        "設計レビュー記録。過去の設計文書に *.test.ts 断片が含まれうる " +
        "(codex-model-consistency.test.ts が同じ理由で除外している先例に倣う)",
    ".claude": "worktree / skill の作業領域。他タスクの worktree が入れ子で見える",
    storage: "Laravel の実行時生成物",
    coverage: "カバレッジレポート出力先",
    build: "public/build のビルド成果物",
};

/**
 * repo を歩いて `*.test.ts` を全部拾う (glob を使わない素朴な定義)。
 *
 * @returns 絶対パス (realpath 済み) のリスト
 */
function scanTestFiles(dir: string, found: string[] = []): string[] {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        if (entry.isDirectory()) {
            if (entry.name in FS_SCAN_EXCLUDED_DIRS) continue;
            scanTestFiles(join(dir, entry.name), found);
            continue;
        }
        if (entry.isFile() && entry.name.endsWith(".test.ts")) {
            found.push(realpathSync(join(dir, entry.name)));
        }
    }
    return found;
}

/**
 * 1 project 分の `vitest list` を実行して収集済みファイルの絶対パスを返す。
 *
 * **制約 1**: 本関数は `beforeAll` からのみ呼ぶこと (module top-level / describe から呼ばない)。
 */
function enumerateProject(projectRoot: string): string[] {
    const dir = mkdtempSync(join(tmpdir(), "vitest-inventory-"));
    const jsonPath = join(dir, "list.json");
    try {
        execFileSync("pnpm", ["exec", "vitest", "list", `--json=${jsonPath}`], {
            cwd: resolve(REPO_ROOT, projectRoot),
            // 制約 2: stdout には vite plugin の警告が混ざるので読まない (ファイル出力を読む)
            stdio: "ignore",
        });
        const parsed: unknown = JSON.parse(readFileSync(jsonPath, "utf-8"));
        if (!Array.isArray(parsed)) {
            throw new Error(`vitest list の出力が配列でない (project=${projectRoot})`);
        }
        const files = new Set<string>();
        for (const entry of parsed) {
            if (entry && typeof entry === "object" && "file" in entry) {
                const file = (entry as { file: unknown }).file;
                if (typeof file === "string") files.add(realpathSync(file));
            }
        }
        return [...files];
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }
}

/**
 * FS 集合と列挙集合を突き合わせる純関数 (テストしやすさのため副作用と分離する)。
 *
 * @param fsFiles              FS 走査で見つけた `*.test.ts` (絶対パス)
 * @param enumeratedByProject  project 名 => `vitest list` の収集結果 (絶対パス)
 * @returns 違反一覧 (空 = 合格)
 */
export function inventoryViolations(
    fsFiles: readonly string[],
    enumeratedByProject: ReadonlyMap<string, readonly string[]>,
): string[] {
    const violations: string[] = [];

    // G1: 各 project の列挙結果が 0 件でない (空振り gate の防止。合計では判定しない)
    for (const [name, files] of enumeratedByProject) {
        if (files.length === 0) {
            violations.push(`G1: project "${name}" の収集結果が 0 件 (gate が空振りしている)`);
        }
    }

    const enumeratedAll = new Set<string>();
    // G5: 2 project の列挙結果が互いに素 (同じファイルを 2 回走らせていない)
    const seenIn = new Map<string, string>();
    for (const [name, files] of enumeratedByProject) {
        for (const file of files) {
            const previous = seenIn.get(file);
            if (previous !== undefined) {
                violations.push(`G5: ${file} が project "${previous}" と "${name}" の両方で収集されている`);
            } else {
                seenIn.set(file, name);
            }
            enumeratedAll.add(file);
        }
    }

    // G2: FS 走査で見つけた全 *.test.ts が、いずれかの project の列挙に含まれる
    for (const file of fsFiles) {
        if (!enumeratedAll.has(file)) {
            violations.push(`G2: ${file} がどの vitest project にも収集されていない (書いたのに走っていない)`);
        }
    }

    // G3: 列挙結果にあって FS に無いファイルが無い (逆方向の整合)
    const fsSet = new Set(fsFiles);
    for (const file of enumeratedAll) {
        if (!fsSet.has(file)) {
            violations.push(`G3: ${file} が収集されているが FS 走査では見つからない`);
        }
    }

    return violations;
}

describe("vitest inventory gate", () => {
    let fsFiles: string[] = [];
    const enumerated = new Map<string, string[]>();

    // 制約 1: spawn は beforeAll の中でのみ行う (収集フェーズでは実行されない)
    //
    // timeout が 15 分と長いのは、この hook が **本体の suite と並走しながら**
    // project ごとに vitest を丸ごと 1 本起動するため。実測: root project の
    // `vitest list` 単体で 12 コアの idle マシンでも ~95s かかる。CI (ubuntu-latest) では
    // 残り 123 ファイルがランナーを飽和させた状態で走るので 180s では足りず、
    // hook timeout だけで frontend job が落ちていた (run 31099359972)。
    // gate の判定内容は一切緩めていない (soft-fail ではなく待ち時間の上限の話)。
    beforeAll(() => {
        fsFiles = scanTestFiles(REPO_ROOT);
        for (const project of TEST_PROJECTS) {
            enumerated.set(project.name, enumerateProject(project.root));
        }
    }, 900_000);

    it("G1〜G3/G5: FS 上の *.test.ts と vitest の収集結果が一致すること", { timeout: 180_000 }, () => {
        const violations = inventoryViolations(fsFiles, enumerated);
        expect(violations).toEqual([]);
    });

    it("G4: gate 自身が root project の収集結果に含まれること", { timeout: 180_000 }, () => {
        const self = realpathSync(resolve(REPO_ROOT, "scripts/vitest-inventory-gate.test.ts"));
        expect(enumerated.get("root")).toContain(self);
    });
});

describe("inventoryViolations() の負のコントロール", () => {
    const a = "/repo/tests/js/a.test.ts";
    const b = "/repo/packages/cli/tests/b.test.ts";

    it("正常な fixture では違反 0 件", () => {
        const violations = inventoryViolations(
            [a, b],
            new Map([
                ["root", [a]],
                ["packages/cli", [b]],
            ]),
        );
        expect(violations).toEqual([]);
    });

    it("G2: どの project にも入らないファイルを検出する", () => {
        const orphan = "/repo/scripts/orphan.test.ts";
        const violations = inventoryViolations(
            [a, b, orphan],
            new Map([
                ["root", [a]],
                ["packages/cli", [b]],
            ]),
        );
        expect(violations).toContain(
            `G2: ${orphan} がどの vitest project にも収集されていない (書いたのに走っていない)`,
        );
    });

    it("G1: 列挙が空の project を検出する", () => {
        const violations = inventoryViolations(
            [a],
            new Map([
                ["root", [a]],
                ["packages/cli", []],
            ]),
        );
        expect(violations).toContain('G1: project "packages/cli" の収集結果が 0 件 (gate が空振りしている)');
    });

    it("G5: 同じファイルが 2 project に現れることを検出する", () => {
        const violations = inventoryViolations(
            [a],
            new Map([
                ["root", [a]],
                ["packages/cli", [a]],
            ]),
        );
        expect(violations).toContain(`G5: ${a} が project "root" と "packages/cli" の両方で収集されている`);
    });

    it("G3: 収集されているが FS に無いファイルを検出する", () => {
        const ghost = "/repo/tests/js/ghost.test.ts";
        const violations = inventoryViolations([a], new Map([["root", [a, ghost]]]));
        expect(violations).toContain(`G3: ${ghost} が収集されているが FS 走査では見つからない`);
    });
});
