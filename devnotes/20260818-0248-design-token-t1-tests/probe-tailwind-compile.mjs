/**
 * 設計時の前提確認用の一時スクリプト (実装では使わない)。
 *
 * 追加で確認したいこと:
 *  5. `@import "tailwindcss" source(none)` で自動ソース走査を切れば、生成 CSS が
 *     アプリ全体の class 使用状況に依存しなくなるか (= 密閉した入力になるか)
 *  6. postcss AST 走査で utility の宣言を取り出せるか (入れ子の hover / @media 対応)
 */
import postcss from "postcss";
import tailwindcss from "@tailwindcss/postcss";

const REPO = "/workspace";
const from = REPO + "/tests/js/styles/__virtual-ds-tokens-input.css";

async function build(sourceInline, useSourceNone) {
    const input = [
        useSourceNone ? '@import "tailwindcss" source(none);' : '@import "tailwindcss";',
        '@import "../../../resources/css/tokens.css";',
        `@source inline("${sourceInline}");`,
    ].join("\n");
    const r = await postcss([tailwindcss()]).process(input, { from, to: undefined });
    return r;
}

const ALL = [
    "bg-primary bg-tertiary bg-neutral bg-surface bg-success bg-warning bg-danger bg-primary-soft",
    "text-primary text-tertiary text-text text-text-secondary text-success text-warning text-danger",
    "border-primary border-tertiary border-border border-border-strong border-success border-warning border-danger",
    "rounded-sm rounded-md rounded-lg",
    "text-display text-h1 text-h2 text-h3 text-body text-caption",
    "hover:bg-primary-hover hover:bg-tertiary-hover",
].join(" ");

const narrowNone = await build("bg-primary", true);
const fullNone = await build(ALL, true);
console.log("[source(none), bg-primary のみ] LEN", narrowNone.css.length);
console.log("[source(none), 全 utility]      LEN", fullNone.css.length);
console.log(
    "[source(none), bg-primary のみ] .text-h1 が出るか:",
    narrowNone.css.includes(".text-h1"),
);
console.log("[source(none), 全 utility] .text-h1 が出るか:", fullNone.css.includes(".text-h1"));
console.log(
    "[source(none), 全 utility] --color-success 変数が出るか:",
    fullNone.css.includes("--color-success:"),
);
console.log(
    "[source(none), 全 utility] アプリ由来の無関係 class (例 .flex) が出るか:",
    /\.flex\s*\{/.test(fullNone.css),
);

// --- 6. AST 走査で utility の宣言を取り出す ---
function declsOfRule(root, selector) {
    const found = [];
    root.walkRules((rule) => {
        if (rule.selector !== selector) return;
        rule.walkDecls((d) => found.push([d.prop, d.value]));
    });
    return found;
}
console.log("AST .bg-primary        :", JSON.stringify(declsOfRule(fullNone.root, ".bg-primary")));
console.log("AST .text-h1           :", JSON.stringify(declsOfRule(fullNone.root, ".text-h1")));
console.log(
    "AST .hover\\:bg-primary-hover:",
    JSON.stringify(declsOfRule(fullNone.root, ".hover\\:bg-primary-hover")),
);
console.log("AST .rounded-md        :", JSON.stringify(declsOfRule(fullNone.root, ".rounded-md")));
console.log("AST .text-text-secondary:", JSON.stringify(declsOfRule(fullNone.root, ".text-text-secondary")));
console.log("AST .bg-primary-soft   :", JSON.stringify(declsOfRule(fullNone.root, ".bg-primary-soft")));

// theme 変数 (:root 相当) の取り出し
const themeVars = new Map();
fullNone.root.walkDecls((d) => {
    if (d.prop.startsWith("--")) themeVars.set(d.prop, d.value);
});
console.log("theme 変数件数:", themeVars.size);
console.log(
    "色変数:",
    JSON.stringify([...themeVars].filter(([k]) => k.startsWith("--color-"))),
);
console.log(
    "radius 変数:",
    JSON.stringify([...themeVars].filter(([k]) => k.startsWith("--radius-"))),
);
console.log("--font-sans:", themeVars.get("--font-sans")?.slice(0, 60));
