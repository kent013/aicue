import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * Atomic Design 階層の import 方向契約を機械保証する。
 *
 * 階層: atoms < molecules < organisms < features < templates < pages。
 * 依存方向の規則 (上層は下層を import 可、下層から上層は禁止):
 *   1. atoms      → token / util / external のみ (他コンポーネント層 import 禁止)
 *   2. molecules  → atoms + molecules(同層) + token/util/external
 *   3. organisms  → atoms + molecules + organisms(同層) + token/util/external
 *   4. features/{D} → atoms + molecules + organisms + features/{D} 自 domain のみ
 *                     (他 domain feature の横参照禁止)
 *   5. templates  → atoms + molecules + organisms + features + token/util/external
 *   6. 逆方向: いずれの component 層も pages を import 禁止
 *   7. molecules / organisms / 各 feature domain の同層 import graph は DAG (循環禁止)
 *
 * 検出: import 文 (from 'x' + side-effect import 'x') を正規表現で抽出し path から階層判定。
 * 未配置層は空集合 pass (テストファースト)。
 */

const REPO_RESOURCES_JS = path.resolve(__dirname, "../../../resources/js");
const COMPONENTS_DIR = path.join(REPO_RESOURCES_JS, "components");

const LAYER_DIRS = {
    atoms: path.join(COMPONENTS_DIR, "atoms"),
    molecules: path.join(COMPONENTS_DIR, "molecules"),
    organisms: path.join(COMPONENTS_DIR, "organisms"),
    features: path.join(COMPONENTS_DIR, "features"),
    templates: path.join(COMPONENTS_DIR, "templates"),
} as const;

const SVELTE_AND_TS: ReadonlySet<string> = new Set([".svelte", ".ts"]);

const dirExists = async (dir: string): Promise<boolean> => {
    try {
        await fs.access(dir);
        return true;
    } catch {
        return false;
    }
};

const listFiles = async (dir: string): Promise<string[]> => {
    if (!(await dirExists(dir))) return [];
    const entries = await fs.readdir(dir, { recursive: true, withFileTypes: true });
    const files: string[] = [];
    for (const entry of entries) {
        if (!entry.isFile()) continue;
        if (!SVELTE_AND_TS.has(path.extname(entry.name))) continue;
        const parent = (entry as unknown as { parentPath?: string }).parentPath ?? dir;
        files.push(path.join(parent, entry.name));
    }
    return files;
};

const collectImports = async (file: string): Promise<string[]> => {
    const content = await fs.readFile(file, "utf-8");
    const imports: string[] = [];
    for (const m of content.matchAll(/from\s+['"]([^'"]+)['"]/g)) imports.push(m[1]);
    for (const m of content.matchAll(/^import\s+['"]([^'"]+)['"]/gm)) imports.push(m[1]);
    return imports;
};

type Layer =
    | "atoms"
    | "molecules"
    | "organisms"
    | "features"
    | "templates"
    | "pages"
    | "token"
    | "util"
    | "external"
    | "unknown_component";

interface ImportClassification {
    layer: Layer;
    domain?: string;
}

const classifyImport = (importPath: string, fromFile: string): ImportClassification => {
    let normalizedPath: string;
    if (importPath.startsWith("@/")) {
        normalizedPath = path.resolve(REPO_RESOURCES_JS, importPath.replace("@/", ""));
    } else if (importPath.startsWith(".")) {
        normalizedPath = path.resolve(path.dirname(fromFile), importPath);
    } else {
        return { layer: "external" };
    }

    const inComponents = (sub: string): boolean =>
        normalizedPath.includes(path.join("components", sub));

    if (inComponents("atoms")) return { layer: "atoms" };
    if (inComponents("molecules")) return { layer: "molecules" };
    if (inComponents("organisms")) return { layer: "organisms" };
    const featuresMatch = normalizedPath.match(
        /[/\\]components[/\\]features[/\\]([^/\\]+)([/\\]|$)/,
    );
    if (featuresMatch) return { layer: "features", domain: featuresMatch[1] };
    if (inComponents("templates")) return { layer: "templates" };
    if (normalizedPath.includes(path.join("resources", "js", "pages")))
        return { layer: "pages" };
    if (normalizedPath.includes(path.join("resources", "js", "components")))
        return { layer: "unknown_component" };
    if (
        normalizedPath.includes(path.join("resources", "js", "types")) ||
        normalizedPath.includes(path.join("resources", "js", "lib")) ||
        normalizedPath.includes(path.join("resources", "js", "composables"))
    ) {
        return { layer: "util" };
    }
    if (
        normalizedPath.includes("tokens.css") ||
        normalizedPath.includes(path.join("resources", "css"))
    ) {
        return { layer: "token" };
    }
    return { layer: "util" };
};

const featureDomain = (file: string): string | null => {
    const m = file.match(/[/\\]components[/\\]features[/\\]([^/\\]+)[/\\]/);
    return m?.[1] ?? null;
};

const collectViolations = async (
    files: string[],
    forbidden: (cls: ImportClassification, fromFile: string) => string | null,
): Promise<string[]> => {
    const violations: string[] = [];
    for (const file of files) {
        for (const imp of await collectImports(file)) {
            const cls = classifyImport(imp, file);
            const reason = forbidden(cls, file);
            if (reason !== null) {
                violations.push(`${path.relative(COMPONENTS_DIR, file)}: ${reason} (${imp})`);
            }
        }
    }
    return violations;
};

interface ImportEdge {
    from: string;
    to: string;
}

const buildEdges = async (files: string[]): Promise<ImportEdge[]> => {
    const edges: ImportEdge[] = [];
    const fileSet = new Set(files);
    for (const file of files) {
        for (const imp of await collectImports(file)) {
            let candidate: string;
            if (imp.startsWith("@/")) {
                candidate = path.resolve(REPO_RESOURCES_JS, imp.replace("@/", ""));
            } else if (imp.startsWith(".")) {
                candidate = path.resolve(path.dirname(file), imp);
            } else {
                continue;
            }
            for (const c of [
                candidate,
                candidate + ".svelte",
                candidate + ".ts",
                path.join(candidate, "index.svelte"),
                path.join(candidate, "index.ts"),
            ]) {
                if (fileSet.has(c)) {
                    edges.push({ from: file, to: c });
                    break;
                }
            }
        }
    }
    return edges;
};

const findCycle = (edges: ImportEdge[]): string[] | null => {
    const graph = new Map<string, string[]>();
    for (const e of edges) {
        if (!graph.has(e.from)) graph.set(e.from, []);
        graph.get(e.from)!.push(e.to);
    }
    const GRAY = 1;
    const BLACK = 2;
    const color = new Map<string, number>();

    const visit = (node: string, trail: string[]): string[] | null => {
        color.set(node, GRAY);
        for (const n of graph.get(node) ?? []) {
            if (color.get(n) === GRAY) return [...trail, node, n];
            if (color.get(n) !== BLACK) {
                const cycle = visit(n, [...trail, node]);
                if (cycle) return cycle;
            }
        }
        color.set(node, BLACK);
        return null;
    };

    for (const node of graph.keys()) {
        if (!color.has(node)) {
            const cycle = visit(node, []);
            if (cycle) return cycle;
        }
    }
    return null;
};

describe("Atomic import graph", () => {
    it("atoms → token/util/external のみ (molecules/organisms/features/templates/pages 禁止)", async () => {
        const v = await collectViolations(await listFiles(LAYER_DIRS.atoms), (cls) =>
            ["molecules", "organisms", "features", "templates", "pages", "unknown_component"].includes(cls.layer)
                ? `imports ${cls.layer}`
                : null,
        );
        expect(v, v.join("\n")).toEqual([]);
    });

    it("molecules → atoms + molecules(同層) のみ (organisms/features/templates/pages 禁止)", async () => {
        const v = await collectViolations(await listFiles(LAYER_DIRS.molecules), (cls) =>
            ["organisms", "features", "templates", "pages", "unknown_component"].includes(cls.layer)
                ? `imports ${cls.layer}`
                : null,
        );
        expect(v, v.join("\n")).toEqual([]);
    });

    it("organisms → atoms + molecules + organisms(同層) のみ (features/templates/pages 禁止)", async () => {
        const v = await collectViolations(await listFiles(LAYER_DIRS.organisms), (cls) =>
            ["features", "templates", "pages", "unknown_component"].includes(cls.layer)
                ? `imports ${cls.layer}`
                : null,
        );
        expect(v, v.join("\n")).toEqual([]);
    });

    it("features/{D} → atoms + molecules + organisms + 自 domain のみ (他 domain/templates/pages 禁止)", async () => {
        const files = await listFiles(LAYER_DIRS.features);
        const v: string[] = [];
        for (const file of files) {
            const fromDomain = featureDomain(file);
            if (!fromDomain) continue;
            for (const imp of await collectImports(file)) {
                const cls = classifyImport(imp, file);
                if (["templates", "pages", "unknown_component"].includes(cls.layer)) {
                    v.push(`${path.relative(COMPONENTS_DIR, file)}: imports ${cls.layer} (${imp})`);
                }
                if (cls.layer === "features" && cls.domain && cls.domain !== fromDomain) {
                    v.push(`${path.relative(COMPONENTS_DIR, file)}: cross-domain features/${cls.domain} (${imp})`);
                }
            }
        }
        expect(v, v.join("\n")).toEqual([]);
    });

    it("templates → pages 禁止 (atoms/molecules/organisms/features は許可)", async () => {
        const v = await collectViolations(await listFiles(LAYER_DIRS.templates), (cls) =>
            cls.layer === "pages" ? "imports pages" : null,
        );
        expect(v, v.join("\n")).toEqual([]);
    });

    it("逆方向: 全 component 層 → pages の import を禁止", async () => {
        const all = (
            await Promise.all(Object.values(LAYER_DIRS).map((d) => listFiles(d)))
        ).flat();
        const v = await collectViolations(all, (cls) => (cls.layer === "pages" ? "imports pages" : null));
        expect(v, v.join("\n")).toEqual([]);
    });

    it("molecules 同層 import graph が DAG (循環なし)", async () => {
        const files = await listFiles(LAYER_DIRS.molecules);
        const edges = (await buildEdges(files)).filter((e) => files.includes(e.to));
        const cycle = findCycle(edges);
        expect(
            cycle,
            cycle ? `Cycle: ${cycle.map((f) => path.relative(COMPONENTS_DIR, f)).join(" → ")}` : "",
        ).toBeNull();
    });

    it("organisms 同層 import graph が DAG (循環なし)", async () => {
        const files = await listFiles(LAYER_DIRS.organisms);
        const edges = (await buildEdges(files)).filter((e) => files.includes(e.to));
        const cycle = findCycle(edges);
        expect(
            cycle,
            cycle ? `Cycle: ${cycle.map((f) => path.relative(COMPONENTS_DIR, f)).join(" → ")}` : "",
        ).toBeNull();
    });

    it("features 各 domain 内 import graph が DAG (循環なし)", async () => {
        const files = await listFiles(LAYER_DIRS.features);
        const byDomain = new Map<string, string[]>();
        for (const file of files) {
            const d = featureDomain(file);
            if (!d) continue;
            if (!byDomain.has(d)) byDomain.set(d, []);
            byDomain.get(d)!.push(file);
        }
        for (const [domain, domainFiles] of byDomain) {
            const edges = (await buildEdges(domainFiles)).filter((e) => domainFiles.includes(e.to));
            const cycle = findCycle(edges);
            expect(
                cycle,
                cycle
                    ? `Cycle in features/${domain}: ${cycle.map((f) => path.relative(COMPONENTS_DIR, f)).join(" → ")}`
                    : "",
            ).toBeNull();
        }
    });

    // classifyImport の抜け道検知 unit
    describe("classifyImport unit", () => {
        const fromFile = path.join(LAYER_DIRS.molecules, "X.svelte");
        it("features directory import を domain 付き features に分類", () => {
            const cls = classifyImport("@/components/features/billing", fromFile);
            expect(cls.layer).toBe("features");
            expect(cls.domain).toBe("billing");
        });
        it("旧 path (components 直下) を unknown_component に分類", () => {
            expect(classifyImport("@/components/Legacy/Old.svelte", fromFile).layer).toBe(
                "unknown_component",
            );
        });
        it("pages を pages に分類 (逆方向検知)", () => {
            expect(classifyImport("@/pages/Dashboard.svelte", fromFile).layer).toBe("pages");
        });
    });
});
