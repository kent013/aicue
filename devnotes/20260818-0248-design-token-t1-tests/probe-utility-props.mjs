/** 詳細設計のための前提確認: 色 utility が出す CSS プロパティ名を確かめる。 */
import postcss from "postcss";
import tailwindcss from "@tailwindcss/postcss";
const from = "/workspace/tests/js/styles/__probe-utility-props.css";
const names = ["primary","primary-hover","primary-soft","tertiary","tertiary-hover","neutral","surface","border","border-strong","text","text-secondary","success","warning","danger"];
const utils = names.flatMap((s)=>[`bg-${s}`,`text-${s}`,`border-${s}`]);
const input = [
  '@import "tailwindcss" source(none);',
  '@import "../../../resources/css/tokens.css";',
  `@source inline("${[...utils, "rounded-sm rounded-md rounded-lg", "text-display text-h1 text-h2 text-h3 text-body text-caption", "hover:bg-primary-hover hover:bg-tertiary-hover"].join(" ")}");`,
].join("\n");
const r = await postcss([tailwindcss()]).process(input, { from, to: undefined });
function decls(sel){ const m=new Map(); r.root.walkRules((ru)=>{ if(ru.selector!==sel) return; ru.walkDecls((d)=>m.set(d.prop,d.value.trim())); }); return m; }
for (const s of ["bg-primary","text-primary","border-primary","border-border","text-text","bg-primary-soft"]) {
  console.log(`.${s} ->`, JSON.stringify([...decls("."+s)]));
}
console.log(".rounded-md ->", JSON.stringify([...decls(".rounded-md")]));
console.log(".text-caption ->", JSON.stringify([...decls(".text-caption")]));
console.log(".hover\\:bg-tertiary-hover ->", JSON.stringify([...decls(".hover\\:bg-tertiary-hover")]));
console.log("未知 selector ->", JSON.stringify([...decls(".bg-does-not-exist")]));
let n=0; r.root.walkRules(()=>n++); console.log("規則の総数:", n);
