import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * Codex 呼び出しモデルの一本化を deny-by-default で固定する。
 *
 * c2c 台帳 (skill-codex-integration t1 / skill-design-flow / skill-implement-flow) は
 * 「gpt-5.5 一本化」を正典としており、aicue はこれに追従した
 * (devnotes/20260805-0101-devtool-template-followup/)。
 *
 * 本テストが守るのは 2 つ:
 *   1. app-* スキルの SKILL.md に canonical (gpt-5.5) 以外のモデル名が現れないこと
 *   2. 走査対象そのものがドリフトしていないこと (inventory と実測の集合一致)
 *
 * devnotes/ は過去のレビュー実績の記録 (どのモデルが何を指摘したか) であり、
 * 書き換えは履歴の改竄にあたるため **走査対象に含めない**。
 */

const SKILLS_ROOT = path.resolve(__dirname, "../../../.claude/skills");

/** 唯一許可されるモデル。世代更新時はここだけを書き換える。 */
const CANONICAL_MODEL = "gpt-5.5";

/**
 * 走査対象の明示 inventory (`.claude/skills` からの相対パス)。
 *
 * 現時点でモデル指定を持たないスキルも登録する = 将来そこにモデル記述が
 * 生えたときも自動で検査対象になる。スキルを増減したらここも更新する
 * (更新を忘れると下の集合一致検査が fail する)。
 */
const SKILL_INVENTORY: readonly string[] = [
    "app-autopilot/SKILL.md",
    "app-bug-hunt/SKILL.md",
    "app-codex-review/SKILL.md",
    "app-codex-vscode/SKILL.md",
    "app-design/SKILL.md",
    "app-implement/SKILL.md",
    "app-todo-add/SKILL.md",
    "app-todo-close/SKILL.md",
    "app-update-docs/SKILL.md",
] as const;

/**
 * `gpt-4` / `gpt-5` / `gpt-5.3-codex` / `gpt-5.1-codex-max` などのモデル
 * トークンを拾う。`\.\d+` は数字を要求するので文末の句点を巻き込まない。
 */
const MODEL_TOKEN_PATTERN = /gpt-\d+(?:\.\d+)?(?:-[a-z0-9]+)*/gi;

/** `.claude/skills/app-*\/SKILL.md` を実測で列挙する。 */
const discoverAppSkillFiles = async (): Promise<readonly string[]> => {
    const entries = await fs.readdir(SKILLS_ROOT, { withFileTypes: true });
    const found: string[] = [];
    for (const entry of entries) {
        if (!entry.isDirectory()) continue;
        if (!entry.name.startsWith("app-")) continue;
        const rel = `${entry.name}/SKILL.md`;
        try {
            await fs.access(path.join(SKILLS_ROOT, rel));
        } catch {
            continue;
        }
        found.push(rel);
    }
    return found.sort();
};

describe("codex model consistency", () => {
    it("走査対象 SKILL.md の集合が inventory と一致する (drift ガード)", async () => {
        const discovered = await discoverAppSkillFiles();

        // 「検査件数 0 なら fail」はこの一致検査に含まれる (inventory は非空)。
        expect(SKILL_INVENTORY.length).toBeGreaterThan(0);
        expect(discovered.length).toBeGreaterThan(0);

        const missing = SKILL_INVENTORY.filter((p) => !discovered.includes(p));
        const unregistered = discovered.filter(
            (p) => !SKILL_INVENTORY.includes(p),
        );

        expect(
            missing,
            `inventory にあるのに実在しない SKILL.md があります (移動/改名/削除で\n`
                + `モデル検査の守備範囲が痩せます)。意図した削除なら inventory からも\n`
                + `外してください:\n  ${missing.join("\n  ")}`,
        ).toEqual([]);

        expect(
            unregistered,
            `inventory に無い app-* スキルがあります。モデル指定が野放しになるため\n`
                + `SKILL_INVENTORY へ追加してください:\n  ${unregistered.join("\n  ")}`,
        ).toEqual([]);
    });

    it(`SKILL.md に ${CANONICAL_MODEL} 以外のモデル名が現れない`, async () => {
        const offenders: string[] = [];
        let scanned = 0;

        for (const rel of SKILL_INVENTORY) {
            const content = await fs.readFile(
                path.join(SKILLS_ROOT, rel),
                "utf8",
            );
            scanned += 1;
            const lines = content.split("\n");
            lines.forEach((line, index) => {
                const matches = line.match(MODEL_TOKEN_PATTERN);
                if (matches === null) return;
                for (const token of matches) {
                    if (token.toLowerCase() === CANONICAL_MODEL) continue;
                    offenders.push(
                        `${rel}:${String(index + 1)}: ${token} — ${line.trim()}`,
                    );
                }
            });
        }

        // 走査 0 件を「合格」と誤読しないための drift ガード。
        expect(scanned).toBe(SKILL_INVENTORY.length);

        expect(
            offenders,
            `canonical (${CANONICAL_MODEL}) 以外のモデル名が残っています。\n`
                + `用途別の使い分けは廃止済みです (概念設計 判断 2/5)。\n`
                + `モデル世代を更新する場合は CANONICAL_MODEL を書き換えてください:\n`
                + `  ${offenders.join("\n  ")}`,
        ).toEqual([]);
    });
});
