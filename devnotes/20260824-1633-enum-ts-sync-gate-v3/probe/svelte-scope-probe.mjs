import ts from "typescript";
const files = new Map();
// 2 コンポーネント相当。import/export 無し。同名の型別名を持つ。
files.set("/v/A.ts", 'type Shared = "a" | "b";\nconst x: Shared = "a";\n');
files.set("/v/B.ts", 'type Ref = Shared;\n');
const build = (suffix) => {
  const src = new Map([...files].map(([k,v]) => [k, v + suffix]));
  const host = {
    getSourceFile: (f, lv) => src.has(f) ? ts.createSourceFile(f, src.get(f), lv, true, ts.ScriptKind.TS) : undefined,
    getDefaultLibFileName: () => "lib.d.ts",
    writeFile: () => {},
    getCurrentDirectory: () => "/",
    getCanonicalFileName: (f) => f,
    useCaseSensitiveFileNames: () => true,
    getNewLine: () => "\n",
    fileExists: (f) => src.has(f),
    readFile: (f) => src.get(f),
  };
  const program = ts.createProgram({ rootNames: [...src.keys()], options: { noEmit: true, noLib: true }, host });
  const checker = program.getTypeChecker();
  const out = [];
  for (const f of src.keys()) {
    const sf = program.getSourceFile(f);
    for (const st of sf.statements) {
      if (!ts.isTypeAliasDeclaration(st)) continue;
      const sym = checker.getSymbolAtLocation(st.name);
      const t = checker.getDeclaredTypeOfSymbol(sym);
      const parts = t.isUnion() ? t.types : [t];
      out.push(`${f}::${st.name.text} = ${parts.map(p=>p.isStringLiteral?.() ? p.value : checker.typeToString(p)).join("|")}`);
    }
  }
  return out;
};
console.log("without export {}:", build(""));
console.log("with export {}  :", build("export {};\n"));
