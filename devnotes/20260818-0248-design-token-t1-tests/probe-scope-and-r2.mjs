/**
 * Codex 詳細設計レビュー Round 1 の [Critical] を確かめる:
 *  - 生成 CSS でテーマ変数がどの入れ物 (layer / selector) に出るか
 *  - R2 (@theme を :root に書き換え) で実際にどの検査が赤になるか
 */
import postcss from "postcss";
import tailwindcss from "@tailwindcss/postcss";
import fs from "node:fs";

const TOKENS = "/workspace/resources/css/tokens.css";
const names = ["primary","primary-hover","primary-soft","tertiary","tertiary-hover","neutral","surface","border","border-strong","text","text-secondary","success","warning","danger"];
const utils = [...names.flatMap((s)=>[`bg-${s}`,`text-${s}`,`border-${s}`]), "rounded-sm","rounded-md","rounded-lg","text-display","text-h1","text-h2","text-h3","text-body","text-caption","hover:bg-primary-hover","hover:bg-tertiary-hover"];

async function build(tokensCss, from) {
  fs.writeFileSync("/workspace/devnotes/20260818-0248-design-token-t1-tests/.tmp-tokens.css", tokensCss);
  const input = [
    '@import "tailwindcss" source(none);',
    '@import "./.tmp-tokens.css";',
    `@source inline("${utils.join(" ")}");`,
  ].join("\n");
  return await postcss([tailwindcss()]).process(input, { from, to: undefined });
}

function ancestry(node){ const p=[]; let n=node.parent; while(n && n.type!=="root"){ p.unshift(n.type==="atrule"?`@${n.name} ${n.params}`:n.selector); n=n.parent; } return p.join(" > "); }

const original = fs.readFileSync(TOKENS, "utf-8");

// --- 1. 正常時: テーマ変数の入れ物 ---
const ok = await build(original, "/workspace/devnotes/20260818-0248-design-token-t1-tests/probe-ok.css");
const seen = new Map();
ok.root.walkDecls((d)=>{ if(d.prop.startsWith("--color-")||d.prop.startsWith("--radius-")||d.prop==="--font-sans"){ const k=ancestry(d); seen.set(k,(seen.get(k)||0)+1);} });
console.log("[正常] テーマ変数の入れ物:", JSON.stringify([...seen], null, 1));

// --- 2. R2: @theme を :root に置換 ---
const r2css = original.replace("@theme {", ":root {");
const r2 = await build(r2css, "/workspace/devnotes/20260818-0248-design-token-t1-tests/probe-r2.css");
function varsAll(root){ const m=new Map(); root.walkDecls((d)=>{ if(d.prop.startsWith("--")) m.set(d.prop, d.value.trim()); }); return m; }
function varsThemeScoped(root){
  const m=new Map();
  root.walkAtRules("layer",(at)=>{
    if(!/(^|,|\s)theme(\s|,|$)/.test(at.params)) return;
    at.walkRules((rule)=>{ rule.walkDecls((d)=>{ if(d.prop.startsWith("--")) m.set(d.prop, d.value.trim()); }); });
  });
  return m;
}
const all = varsAll(r2.root), scoped = varsThemeScoped(r2.root);
console.log("[R2] 全走査 --color-primary:", all.get("--color-primary"), "| theme scope:", scoped.get("--color-primary"));
console.log("[R2] 全走査 --radius-md   :", all.get("--radius-md"),    "| theme scope:", scoped.get("--radius-md"));
console.log("[R2] 全走査 --font-sans   :", (all.get("--font-sans")||"").slice(0,30), "| theme scope:", (scoped.get("--font-sans")||"").slice(0,30));
function decls(root, sel){ const m=new Map(); root.walkRules((ru)=>{ if(ru.selector!==sel) return; ru.walkDecls((d)=>m.set(d.prop,d.value.trim())); }); return m; }
console.log("[R2] .bg-primary ->", JSON.stringify([...decls(r2.root, ".bg-primary")]));
console.log("[R2] .rounded-md ->", JSON.stringify([...decls(r2.root, ".rounded-md")]));
console.log("[R2] .text-body  ->", JSON.stringify([...decls(r2.root, ".text-body")]));
console.log("[R2] .hover\\:bg-primary-hover ->", JSON.stringify([...decls(r2.root, ".hover\\:bg-primary-hover")]));

// --- 3. 正常時の theme scope の値 ---
const okScoped = varsThemeScoped(ok.root);
console.log("[正常] theme scope --color-primary:", okScoped.get("--color-primary"), "--radius-md:", okScoped.get("--radius-md"), "--color-primary-soft:", okScoped.get("--color-primary-soft"));
console.log("[正常] theme scope の変数件数:", okScoped.size);

// --- 4. hover の入れ子構造 ---
ok.root.walkRules((ru)=>{ if(ru.selector!==".hover\\:bg-primary-hover") return; ru.walkDecls((d)=>console.log("[正常] hover decl:", d.prop, "=", d.value, "| ancestry:", ancestry(d))); });

fs.unlinkSync("/workspace/devnotes/20260818-0248-design-token-t1-tests/.tmp-tokens.css");
