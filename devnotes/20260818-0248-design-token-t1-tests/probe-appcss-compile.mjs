/**
 * 設計時の前提確認用の一時スクリプト (実装では使わない)。
 *
 * Codex 概念レビュー Round 1 の [Critical] を受けて確認する:
 *  - 実物の resources/css/app.css を postcss + @tailwindcss/postcss でコンパイルできるか
 *  - その生成 CSS に @theme 由来の変数と代表 utility が現れるか
 *  - tokens.css の取り込みを外した (= import 経路が壊れた) ときに実際に落ちるか
 *  - 所要時間はテストとして許容できるか
 */
import postcss from "postcss";
import tailwindcss from "@tailwindcss/postcss";
import fs from "node:fs/promises";

const APP_CSS = "/workspace/resources/css/app.css";

async function compile(source, from) {
    const t0 = Date.now();
    const r = await postcss([tailwindcss()]).process(source, { from, to: undefined });
    return { css: r.css, root: r.root, ms: Date.now() - t0 };
}

const original = await fs.readFile(APP_CSS, "utf-8");

// --- 1. 実物の app.css ---
const real = await compile(original, APP_CSS);
console.log("[app.css] LEN", real.css.length, "elapsed(ms)", real.ms);
const vars = new Map();
real.root.walkDecls((d) => {
    if (d.prop.startsWith("--")) vars.set(d.prop, d.value);
});
const anchors = [
    "--color-primary",
    "--color-primary-hover",
    "--color-text",
    "--color-surface",
    "--color-danger",
    "--radius-md",
    "--font-sans",
];
for (const a of anchors) console.log(`[app.css] ${a}:`, vars.has(a) ? vars.get(a).slice(0, 40) : "(無し)");
const ruleSelectors = new Set();
real.root.walkRules((r) => ruleSelectors.add(r.selector));
for (const s of [".bg-primary", ".text-text-secondary", ".border-border", ".text-body", ".rounded-md"]) {
    console.log(`[app.css] rule ${s}:`, ruleSelectors.has(s));
}
console.log("[app.css] 生成された --color-* の件数:", [...vars.keys()].filter((k) => k.startsWith("--color-")).length);

// --- 2. tokens.css の取り込みを外した壊れ方 (負のコントロール) ---
const broken = original.replace(/@import\s+['"]\.\/tokens\.css['"];\s*\n/, "");
console.log("[negative] tokens.css の import を除去できたか:", broken !== original);
// @tailwindcss/postcss は from パス単位で結果をキャッシュするため、
// 負のコントロールは別パス (同一ディレクトリ) で走らせる。
const brokenBuild = await compile(broken, "/workspace/resources/css/__broken-probe.css");
const brokenVars = new Set();
brokenBuild.root.walkDecls((d) => {
    if (d.prop.startsWith("--")) brokenVars.add(d.prop);
});
console.log("[negative] --color-primary が消えるか:", !brokenVars.has("--color-primary"));
console.log("[negative] --radius-md が消えるか:", !brokenVars.has("--radius-md"));
const brokenSelectors = new Set();
brokenBuild.root.walkRules((r) => brokenSelectors.add(r.selector));
console.log("[negative] .bg-primary が消えるか:", !brokenSelectors.has(".bg-primary"));
console.log("[negative] .text-body が消えるか:", !brokenSelectors.has(".text-body"));

// --- 3. 取り込み順序を入れ替えた壊れ方 (負のコントロール 2) ---
const swapped = original
    .replace(/@import\s+['"]tailwindcss['"];\s*\n@import\s+['"]\.\/tokens\.css['"];\s*\n/,
             "@import './tokens.css';\n@import 'tailwindcss';\n");
console.log("[negative2] 順序を入れ替えられたか:", swapped !== original);
const swappedBuild = await compile(swapped, "/workspace/resources/css/__swapped-probe.css");
const swappedVars = new Set();
swappedBuild.root.walkDecls((d) => { if (d.prop.startsWith("--")) swappedVars.add(d.prop); });
const swappedSelectors = new Set();
swappedBuild.root.walkRules((r) => swappedSelectors.add(r.selector));
console.log("[negative2] --color-primary が消えるか:", !swappedVars.has("--color-primary"));
console.log("[negative2] .bg-primary が消えるか:", !swappedSelectors.has(".bg-primary"));
console.log("[negative2] .text-body が消えるか:", !swappedSelectors.has(".text-body"));

// --- 4. Tailwind 既定テーマとの名前衝突の確認 ---
console.log("[collision] 既定テーマ由来で残る --radius-* を確認する");
const brokenRadius = [];
brokenBuild.root.walkDecls((d) => { if (d.prop.startsWith("--radius-")) brokenRadius.push([d.prop, d.value]); });
console.log("[collision] tokens.css を外したときの --radius-*:", JSON.stringify(brokenRadius));
