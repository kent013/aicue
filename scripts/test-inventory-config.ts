/**
 * vitest の include の単一 SoT。
 *
 * root と packages/cli は **統合しない**: root は jsdom + svelte plugin、
 * packages/cli は node 環境 + 独自 setupFile + 独自 timeout の別 project であり、
 * 「似ているから」で 1 つにしない (AGENTS.md 思考原則 4)。
 * 統合する代わりに **include だけ** を本ファイルへ集約し、
 * scripts/vitest-inventory-gate.test.ts が「FS 上の *.test.ts が全部どこかの
 * project に入っているか」を独立に突き合わせる。
 */

/** vitest project 1 つ分の収集定義。 */
export interface TestProject {
    /** 人間可読な識別子 (gate の失敗メッセージに出す)。 */
    readonly name: string;
    /** repo root からの相対 project root。vitest を起動する cwd でもある。 */
    readonly root: string;
    /** project root からの相対 include glob。 */
    readonly include: readonly string[];
}

/**
 * 全 vitest project の inventory。
 * **新しい project / 新しい include を足したらここに書く**。書き忘れると
 * scripts/vitest-inventory-gate.test.ts が「どの project にも入らない test file」を検出して落ちる。
 */
export const TEST_PROJECTS: readonly TestProject[] = [
    {
        name: "root",
        root: ".",
        include: ["tests/js/**/*.test.ts", "scripts/**/*.test.ts"],
    },
    {
        name: "packages/cli",
        root: "packages/cli",
        include: ["tests/**/*.test.ts"],
    },
] as const;

/** name から project を引く (config 側で使う。未知名は fail-fast)。 */
export function testProject(name: string): TestProject {
    const found = TEST_PROJECTS.find((p) => p.name === name);
    if (!found) throw new Error(`unknown vitest project: ${name}`);
    return found;
}
