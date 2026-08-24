import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { REPO_ROOT, designComponentSections, parseDesignComponentSections } from "./design-md";
import {
    COMPONENT_DIR_CLASSIFICATION,
    COMPONENT_FILE_KINDS,
    COMPONENT_SECTION_MAPPINGS,
    type ComponentDirClassification,
    type ComponentFileKinds,
    type ComponentSectionMappings,
} from "./inventory";

/**
 * 文書 ⇔ 実装の双方向一致 (正典 i10) —
 * DESIGN.md §Components の `###` 節と `resources/js/components` の部品ファイルが
 * (申告表を適用したうえで) 集合一致することを検査する。
 *
 * 【なぜ要るか】文書に載らない部品が静かに増える形 (家系で実在した「13 部品事件」) と、
 *   節だけ残って実装が消える形の**両方**を止める。片側だけでは足りない。
 * 【対象範囲】DS の再利用部品 (`atoms` / `molecules` / `organisms`) だけである。
 *   `features/` のドメイン部品と `templates/` のレイアウト骨格は対象外で、
 *   その宣言は `COMPONENT_DIR_CLASSIFICATION` に理由つきで置く (未分類は不合格)。
 * 【判定は 3 段の純粋関数に分ける】実リポジトリを直接列挙する gate だけでは
 *   「未分類ディレクトリを足す」「部品を 1 つ足す」の固定検体を同じ判定実装へ渡せない。
 * 【本 gate が消費する診断】`parseDesignComponentSections()` 経由の DESIGN.md 側 Markdown 診断
 *   (未終端コメント / 未終端 fence / container fence / 未対応 fence)。
 *   1 件でもあれば解析失敗として例外になる。
 * 【保証しないもの】節の**中身**が実装と合っていること (意味論の一致は人のレビューの担当)。
 */

/* ===== 段 1: ディレクトリ木の仕分け ===== */

/** 走査結果の木 (固定検体からも組み立てられる構造型)。 */
export interface ComponentTree {
    /** `resources/js/components` からの相対ディレクトリパス */
    readonly path: string;
    readonly directories: readonly ComponentTree[];
    /** 直下のファイル名 (basename) */
    readonly files: readonly string[];
}

export interface ComponentClassification {
    /** 節を要求する部品 (components からの相対パス) */
    readonly components: readonly string[];
    /** 分類表に無いディレクトリ (相対パス) */
    readonly unclassifiedDirectories: readonly string[];
    /** 分類表に無いファイル種別 (相対パス) */
    readonly unclassifiedFiles: readonly string[];
    /** 判定に実際に使われた分類表のキー */
    readonly usedDirectoryKeys: readonly string[];
    /** 判定に実際に使われたファイル種別の接尾辞 */
    readonly usedFileKinds: readonly string[];
    /** 対の `*.svelte` を持たない `*.types.ts` */
    readonly orphanTypes: readonly string[];
    /** 別ディレクトリで衝突する部品の basename */
    readonly duplicateBasenames: readonly string[];
}

/** 最長接尾辞一致でファイル種別を引く (`.types.ts` を `.ts` より先に当てる)。 */
function fileKindOf(name: string, kinds: ComponentFileKinds): string | null {
    const matched = Object.keys(kinds).filter((suffix) => name.endsWith(suffix));
    if (matched.length === 0) return null;

    return matched.reduce((a, b) => (b.length > a.length ? b : a));
}

/**
 * ディレクトリ木を分類表で仕分ける。
 *
 * 探索規則:
 *   1. `excluded` の分類は**そこで再帰を止める** (中は一切見ない)
 *   2. `documented` の分類は**その直下のファイルだけ**を部品の母集団に入れる
 *   3. `documented` の直下にさらにサブディレクトリがある場合、**そのパス自体が分類表に
 *      無ければ不合格**にする (深さ 2 以降も同じ規則を適用する)
 *   4. **部品の basename の重複を無条件に拒否する** (既定の対応がファイル名だけなので、
 *      `atoms/Foo.svelte` と `molecules/Foo.svelte` があると 1 節へ衝突する)。
 *      **申告表では救わない** — 本関数は申告表を受け取らないので、救う口を書くと二通りに読める。
 *      将来重複が要るようになったら、そのとき判定を `compareComponentDocumentation()` 側へ移す
 *   5. 分類表のキーは実在するディレクトリであり、かつ**実際に判定へ使われた**こと
 */
export function classifyComponentTree(
    tree: ComponentTree,
    dirClassification: ComponentDirClassification,
    fileKinds: ComponentFileKinds,
): ComponentClassification {
    const components: string[] = [];
    const unclassifiedDirectories: string[] = [];
    const unclassifiedFiles: string[] = [];
    const usedDirectoryKeys: string[] = [];
    const usedFileKinds: string[] = [];
    const orphanTypes: string[] = [];

    // ★分類対象ディレクトリの**外**に置かれたファイル (components 直下など) を
    //   無言で捨てない。部品にも未分類にもならず消える形は fail-open である。
    for (const file of tree.files) unclassifiedFiles.push(file);

    const visit = (node: ComponentTree): void => {
        for (const child of node.directories) {
            const spec = dirClassification[child.path];
            if (spec === undefined) {
                unclassifiedDirectories.push(child.path);
                continue;
            }
            usedDirectoryKeys.push(child.path);
            if (spec.kind === "excluded") continue;

            // 母集団への入れ方は **`kind` が決める** (真偽値の別フラグを持たない)。
            const componentBaseNames = new Set<string>();
            const typeFiles: { readonly file: string; readonly base: string }[] = [];
            for (const file of child.files) {
                const suffix = fileKindOf(file, fileKinds);
                if (suffix === null) {
                    unclassifiedFiles.push(`${child.path}/${file}`);
                    continue;
                }
                usedFileKinds.push(suffix);
                const base = file.slice(0, -suffix.length);
                // 分類の網羅を never へ収束させる (種別を足したらここが必ず赤くなる)
                const kind = fileKinds[suffix].kind;
                switch (kind) {
                    case "component":
                        components.push(`${child.path}/${file}`);
                        componentBaseNames.add(base);
                        break;
                    case "types":
                        typeFiles.push({ file, base });
                        break;
                    case "helper":
                        break;
                    default: {
                        const exhaustive: never = kind;
                        throw new Error(`未知のファイル種別: ${String(exhaustive)}`);
                    }
                }
            }
            for (const { file, base } of typeFiles) {
                if (!componentBaseNames.has(base)) orphanTypes.push(`${child.path}/${file}`);
            }
            visit(child);
        }
    };
    visit(tree);

    const basenames = components.map((rel) => rel.slice(rel.lastIndexOf("/") + 1));
    const duplicateBasenames = [
        ...new Set(basenames.filter((name, index) => basenames.indexOf(name) !== index)),
    ];

    return {
        components: [...components].sort(),
        unclassifiedDirectories: [...unclassifiedDirectories].sort(),
        unclassifiedFiles: [...unclassifiedFiles].sort(),
        usedDirectoryKeys: [...usedDirectoryKeys].sort(),
        usedFileKinds: [...new Set(usedFileKinds)].sort(),
        orphanTypes: [...orphanTypes].sort(),
        duplicateBasenames: duplicateBasenames.sort(),
    };
}

/* ===== 段 2: 節と部品の突き合わせ ===== */

export interface ComponentDocDiff {
    /** 実装にあるのに節が無い部品 */
    readonly missingSections: readonly string[];
    /** 節があるのに実装が無い節名 */
    readonly orphanSections: readonly string[];
    /** 存在しない節 / 存在しないファイルを指す申告 */
    readonly staleMappings: readonly string[];
    /** 同じファイルが 2 つの節に申告されている */
    readonly duplicateMappedFiles: readonly string[];
    /** 既定の対応で足りるのに申告している */
    readonly redundantMappings: readonly string[];
}

/** 既定の対応: 節名 = 拡張子を除いたファイル名。 */
function defaultSectionName(relative: string): string {
    const base = relative.slice(relative.lastIndexOf("/") + 1);

    return base.slice(0, base.lastIndexOf("."));
}

export function compareComponentDocumentation(
    sections: readonly string[],
    components: readonly string[],
    mappings: ComponentSectionMappings,
): ComponentDocDiff {
    const sectionSet = new Set(sections);
    const componentSet = new Set(components);

    const staleMappings: string[] = [];
    const duplicateMappedFiles: string[] = [];
    const redundantMappings: string[] = [];
    const mappedFileToSection = new Map<string, string>();

    for (const mapping of mappings) {
        if (!sectionSet.has(mapping.section)) staleMappings.push(`節が無い: ${mapping.section}`);
        for (const file of mapping.files) {
            if (!componentSet.has(file)) staleMappings.push(`部品が無い: ${file}`);
            if (mappedFileToSection.has(file)) duplicateMappedFiles.push(file);
            mappedFileToSection.set(file, mapping.section);
        }
        if (mapping.files.length === 1 && defaultSectionName(mapping.files[0]) === mapping.section) {
            redundantMappings.push(mapping.section);
        }
    }

    const covered = new Set<string>();
    const missingSections: string[] = [];
    for (const component of components) {
        const section = mappedFileToSection.get(component) ?? defaultSectionName(component);
        if (!sectionSet.has(section)) missingSections.push(component);
        else covered.add(section);
    }
    const orphanSections = sections.filter((section) => !covered.has(section));

    return {
        missingSections: [...missingSections].sort(),
        orphanSections: [...orphanSections].sort(),
        staleMappings: [...staleMappings].sort(),
        duplicateMappedFiles: [...new Set(duplicateMappedFiles)].sort(),
        redundantMappings: [...redundantMappings].sort(),
    };
}

/* ===== 段 3: 実リポジトリ用の薄いラッパー ===== */

const COMPONENTS_ROOT = "resources/js/components";

function readComponentTree(relative: string): ComponentTree {
    const absolute = path.join(REPO_ROOT, COMPONENTS_ROOT, relative);
    const entries = fs.readdirSync(absolute, { withFileTypes: true });

    return {
        path: relative,
        directories: entries
            .filter((entry) => entry.isDirectory())
            .map((entry) => readComponentTree(relative === "" ? entry.name : `${relative}/${entry.name}`)),
        files: entries.filter((entry) => entry.isFile()).map((entry) => entry.name),
    };
}

const tree = readComponentTree("");
const classification = classifyComponentTree(tree, COMPONENT_DIR_CLASSIFICATION, COMPONENT_FILE_KINDS);
const sections = designComponentSections();
const diff = compareComponentDocumentation(sections, classification.components, COMPONENT_SECTION_MAPPINGS);

describe("component-doc-parity: 双方向の集合一致", () => {
    it("母集団が空でない (走査の空振り防止)", () => {
        expect(sections.length, "§Components の節が 1 件も取れない").toBeGreaterThan(0);
        expect(classification.components.length, "部品が 1 件も取れない").toBeGreaterThan(0);
    });

    it("実装にあるのに節が無い部品が無い", () => {
        expect(
            diff.missingSections,
            "DESIGN.md §Components に節を足すこと (既定の対応に乗らないなら " +
                "COMPONENT_SECTION_MAPPINGS へ理由つきで申告すること)",
        ).toEqual([]);
    });

    it("節があるのに実装が無い節が無い", () => {
        expect(diff.orphanSections, "実装の消えた節が DESIGN.md に残っている").toEqual([]);
    });
});

describe("component-doc-parity: 全数分類 (既定拒否)", () => {
    it("サブディレクトリが分類表と集合一致する (未分類も死んだ登録も落とす)", () => {
        expect(classification.unclassifiedDirectories, "分類表に無いディレクトリがある").toEqual([]);
        expect(
            classification.usedDirectoryKeys,
            "判定に使われなかった分類エントリがある (excluded の配下は再帰を止めるので死んだ登録になる)",
        ).toEqual(Object.keys(COMPONENT_DIR_CLASSIFICATION).sort());
    });

    it("直下の子のうち第 1 要素の集合が分類表と一致する", () => {
        const firstSegments = new Set(
            Object.keys(COMPONENT_DIR_CLASSIFICATION).map((key) => key.split("/")[0]),
        );
        expect(tree.directories.map((d) => d.path).sort()).toEqual([...firstSegments].sort());
    });

    it("ファイル種別が分類表と集合一致する (未分類も死んだ登録も落とす)", () => {
        expect(
            classification.unclassifiedFiles,
            "分類表に無い拡張子のファイル、または分類対象ディレクトリの外に置かれたファイルがある",
        ).toEqual([]);
        expect(
            classification.usedFileKinds,
            "判定に使われなかったファイル種別の登録がある (死んだ登録)",
        ).toEqual(Object.keys(COMPONENT_FILE_KINDS).sort());
    });

    it("孤立した型ファイルが無い (*.types.ts には対の *.svelte がある)", () => {
        expect(classification.orphanTypes).toEqual([]);
    });

    it("部品の basename が衝突していない", () => {
        expect(classification.duplicateBasenames).toEqual([]);
    });

    it("excluded の分類に理由が書かれている", () => {
        for (const [dir, spec] of Object.entries(COMPONENT_DIR_CLASSIFICATION)) {
            if (spec.kind !== "excluded") continue;
            expect(spec.reason?.length ?? 0, `${dir}: 理由`).toBeGreaterThan(30);
        }
    });

    it("節を要求しないファイル種別に理由が書かれている", () => {
        // 母集団へ入れない種別は「なぜ入れないか」を書かなければ登録できない
        // (理由なしの新種別を足せると既定拒否が形骸化する)。
        for (const [suffix, spec] of Object.entries(COMPONENT_FILE_KINDS)) {
            if (spec.kind === "component") continue;
            expect(spec.reason.length, `${suffix}: 理由`).toBeGreaterThan(30);
        }
    });
});

describe("component-doc-parity: 申告表の健全性", () => {
    it("失効・重複・冗長な申告が無い", () => {
        expect(diff.staleMappings, "存在しない節 / ファイルを指す申告がある").toEqual([]);
        expect(diff.duplicateMappedFiles, "同じファイルが 2 つの節へ申告されている").toEqual([]);
        expect(diff.redundantMappings, "既定の対応で足りるのに申告している").toEqual([]);
    });

    it("申告に理由が書かれている", () => {
        for (const mapping of COMPONENT_SECTION_MAPPINGS) {
            expect(mapping.reason.length, `${mapping.section}: 理由`).toBeGreaterThan(30);
            expect(mapping.files.length, `${mapping.section}: files`).toBeGreaterThan(0);
        }
    });
});

/* ===== 負のコントロール (固定検体を 3 段の純粋関数へ直接渡す) ===== */

const FIXTURE_DIRS: ComponentDirClassification = {
    atoms: { kind: "documented" },
    features: { kind: "excluded", reason: "ドメイン部品" },
};
const FIXTURE_KINDS: ComponentFileKinds = COMPONENT_FILE_KINDS;

const fixtureTree = (overrides: Partial<ComponentTree> = {}): ComponentTree => ({
    path: "",
    directories: [
        { path: "atoms", directories: [], files: ["Badge.svelte", "Badge.types.ts"] },
        { path: "features", directories: [], files: ["Domain.svelte"] },
    ],
    files: [],
    ...overrides,
});

describe("component-doc-parity: 負のコントロール (固定検体)", () => {
    it("節を 1 つ消すと不合格になる", () => {
        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
        expect(compareComponentDocumentation([], c.components, []).missingSections).toEqual([
            "atoms/Badge.svelte",
        ]);
    });

    it("部品を 1 つ足すと不合格になる", () => {
        const tree2 = fixtureTree({
            directories: [
                { path: "atoms", directories: [], files: ["Badge.svelte", "New.svelte"] },
                { path: "features", directories: [], files: [] },
            ],
        });
        const c = classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS);
        expect(compareComponentDocumentation(["Badge"], c.components, []).missingSections).toEqual([
            "atoms/New.svelte",
        ]);
    });

    it("実装の消えた節は orphanSections になる", () => {
        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
        expect(
            compareComponentDocumentation(["Badge", "Gone"], c.components, []).orphanSections,
        ).toEqual(["Gone"]);
    });

    it("申告を冗長にすると不合格になる", () => {
        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
        const redundant = compareComponentDocumentation(["Badge"], c.components, [
            { section: "Badge", files: ["atoms/Badge.svelte"], reason: "冗長" },
        ]);
        expect(redundant.redundantMappings).toEqual(["Badge"]);
    });

    it("失効した申告 (存在しない節 / ファイル) を落とす", () => {
        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
        const stale = compareComponentDocumentation(["Badge"], c.components, [
            { section: "無い節", files: ["atoms/Missing.svelte"], reason: "失効" },
        ]);
        expect(stale.staleMappings.length).toBe(2);
    });

    it("同じファイルを 2 つの節へ申告すると落とす", () => {
        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
        const duplicated = compareComponentDocumentation(["A", "B"], c.components, [
            { section: "A", files: ["atoms/Badge.svelte"], reason: "1 つ目" },
            { section: "B", files: ["atoms/Badge.svelte"], reason: "2 つ目" },
        ]);
        expect(duplicated.duplicateMappedFiles).toEqual(["atoms/Badge.svelte"]);
    });

    it("未分類のサブディレクトリを足すと不合格になる", () => {
        const tree2 = fixtureTree({
            directories: [
                { path: "atoms", directories: [], files: ["Badge.svelte"] },
                { path: "features", directories: [], files: [] },
                { path: "unknown", directories: [], files: ["X.svelte"] },
            ],
        });
        expect(
            classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS).unclassifiedDirectories,
        ).toEqual(["unknown"]);
    });

    it("documented の下に未分類の入れ子ディレクトリを足すと不合格になる (規則 3)", () => {
        const tree2 = fixtureTree({
            directories: [
                {
                    path: "atoms",
                    directories: [{ path: "atoms/nested", directories: [], files: ["X.svelte"] }],
                    files: ["Badge.svelte"],
                },
                { path: "features", directories: [], files: [] },
            ],
        });
        const c = classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS);
        expect(c.unclassifiedDirectories).toEqual(["atoms/nested"]);
        expect(c.components).toEqual(["atoms/Badge.svelte"]);
    });

    it("excluded の下のファイルは母集団に入らない (規則 1)", () => {
        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
        expect(c.components).toEqual(["atoms/Badge.svelte"]);
    });

    it("使われなかった分類エントリを検出できる (規則 5)", () => {
        const c = classifyComponentTree(fixtureTree(), { ...FIXTURE_DIRS, ghost: { kind: "documented" } }, FIXTURE_KINDS);
        expect(c.usedDirectoryKeys).toEqual(["atoms", "features"]);
    });

    it("basename の重複を無条件に拒否する", () => {
        const tree2 = fixtureTree({
            directories: [
                { path: "atoms", directories: [], files: ["Badge.svelte"] },
                { path: "features", directories: [], files: [] },
                { path: "molecules", directories: [], files: ["Badge.svelte"] },
            ],
        });
        const c = classifyComponentTree(
            tree2,
            { ...FIXTURE_DIRS, molecules: { kind: "documented" } },
            FIXTURE_KINDS,
        );
        expect(c.duplicateBasenames).toEqual(["Badge.svelte"]);
    });

    it("kind が母集団への入れ方を決める (helper は母集団に入らず、component は入る)", () => {
        // `kind` を判定の正本にしていないと、種別を取り違えても gate が通ってしまう。
        const tree2 = fixtureTree({
            directories: [
                { path: "atoms", directories: [], files: ["Badge.svelte", "input-state.ts"] },
                { path: "features", directories: [], files: [] },
            ],
        });
        expect(classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS).components).toEqual([
            "atoms/Badge.svelte",
        ]);
        // 同じ木でも `.ts` を component 種別にすると母集団へ入る (kind が効いている裏取り)
        const swapped = classifyComponentTree(tree2, FIXTURE_DIRS, {
            ...FIXTURE_KINDS,
            ".ts": { kind: "component" as const },
        });
        expect(swapped.components).toEqual(["atoms/Badge.svelte", "atoms/input-state.ts"]);
    });

    it("ファイル種別の最長接尾辞一致 (固定検体)", () => {
        const tree2 = fixtureTree({
            directories: [
                {
                    path: "atoms",
                    directories: [],
                    files: ["Button.svelte", "Button.types.ts", "input-state.ts", "notes.md"],
                },
                { path: "features", directories: [], files: [] },
            ],
        });
        const c = classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS);
        expect(c.components).toEqual(["atoms/Button.svelte"]);
        expect(c.unclassifiedFiles).toEqual(["atoms/notes.md"]);
        expect(c.orphanTypes).toEqual([]);
    });

    it("分類対象ディレクトリの外 (components 直下) のファイルは未分類として落ちる", () => {
        const tree2 = fixtureTree({ files: ["Stray.svelte"] });
        const c = classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS);
        expect(c.components).toEqual(["atoms/Badge.svelte"]);
        expect(c.unclassifiedFiles).toEqual(["Stray.svelte"]);
    });

    it("使われなかったファイル種別の登録を検出できる", () => {
        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
        expect(c.usedFileKinds).toEqual([".svelte", ".types.ts"]);
    });

    it("対の *.svelte を持たない *.types.ts を検出する", () => {
        const tree2 = fixtureTree({
            directories: [
                { path: "atoms", directories: [], files: ["Badge.svelte", "Gone.types.ts"] },
                { path: "features", directories: [], files: [] },
            ],
        });
        expect(classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS).orphanTypes).toEqual([
            "atoms/Gone.types.ts",
        ]);
    });
});

describe("component-doc-parity: 節の抽出の負のコントロール (固定検体)", () => {
    const FENCE = "`".repeat(3);
    const md = (lines: readonly string[]): string => lines.join("\n");

    it("囲みコードの中の見出しは数えない", () => {
        expect(
            parseDesignComponentSections(
                md(["## Components", FENCE, "### DragHandle", FENCE, "### Badge"]),
            ),
        ).toEqual(["Badge"]);
    });

    it("HTML コメントの中の見出しも数えない", () => {
        expect(
            parseDesignComponentSections(
                md(["## Components", "<!-- ### DragHandle -->", "### Badge"]),
            ),
        ).toEqual(["Badge"]);
    });

    it("#### 以降は数えない", () => {
        expect(parseDesignComponentSections(md(["## Components", "#### X", "### Badge"]))).toEqual([
            "Badge",
        ]);
    });

    it("## Components が 0 件 / 2 件なら例外", () => {
        expect(() => parseDesignComponentSections(md(["### Badge"]))).toThrow(/1 節でない/);
        expect(() =>
            parseDesignComponentSections(md(["## Components", "## Components"])),
        ).toThrow(/1 節でない/);
    });

    it("同名の ### が 2 つあれば例外", () => {
        expect(() =>
            parseDesignComponentSections(md(["## Components", "### Badge", "### Badge"])),
        ).toThrow(/重複/);
    });

    it("次の ## 以降の節は数えない", () => {
        expect(
            parseDesignComponentSections(md(["## Components", "### Badge", "## Other", "### Not"])),
        ).toEqual(["Badge"]);
    });

    it("字下げした ## Components は見出しとして受理しない (字下げコードへの退避を塞ぐ)", () => {
        // `trim()` で探すと、規範の見出しを字下げコードへ移して双方向一致を迂回できる。
        expect(() =>
            parseDesignComponentSections(md(["    ## Components", "### Button"])),
        ).toThrow(/1 節でない/);
    });

    it("未終端の囲みコードは診断として例外になる", () => {
        expect(() => parseDesignComponentSections(md(["## Components", FENCE, "### X"]))).toThrow(
            /Markdown 走査が失敗/,
        );
    });

    it("container を伴う fence の中の ### は「数えない」のではなく例外になる", () => {
        for (const prefix of ["> ", "- > ", "> - "]) {
            expect(
                () =>
                    parseDesignComponentSections(
                        md([
                            "## Components",
                            prefix + FENCE,
                            prefix + "### 部品名",
                            prefix + FENCE,
                            "### Badge",
                        ]),
                    ),
                prefix,
            ).toThrow(/Markdown 走査が失敗/);
        }
    });
});
