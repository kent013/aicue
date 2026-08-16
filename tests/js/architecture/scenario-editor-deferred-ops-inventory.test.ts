import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * ScenarioEditor の「IME 変換中に遅延実行される操作」の目録を deny-by-default で固定する。
 *
 * 不変条件: 遅延実行される構造操作は、対象を安定キー (clientKey) で捕捉し、
 * 実行時に解決し直してから変異する。数値 index を持ち回ると、先行する遅延操作で
 * 添字がずれて別の行へ適用される (T185 で並べ替え 2 経路、T188 で削除・追加 3 経路を修正)。
 *
 * **本テストの位置づけは「現在のソース書式に対するトリップワイヤ」である。**
 * 静的解析でも網羅検査でもない。狙いはただ 1 つ —
 * **遅延 queue に積む経路を増減させた人を、この設計へ引き戻すこと**である。
 *
 * **保証しないもの (誇張しない)**:
 * - closure が実際に安定キーで解決しているか。これは
 *   tests/js/components/features/manual/ScenarioEditor.test.ts の behavioral テストの担当
 * - 関数への**正確な**帰属。走査は「直前に現れた名前付き function 宣言」への便宜的な
 *   関連付けであり、閉じ括弧で解除しない。入れ子関数・メソッド・引数の中の arrow・
 *   複数行にまたがる呼び出しは誤帰属しうる
 *   (文レベルの arrow function 定義からの呼び出しだけは ARROW_DEFINITION 検査で弾く)
 * - コメント除去の正確さ。文字列・テンプレートリテラル・正規表現リテラルの中にある
 *   コメント記号は区別しない。**誤除去が必ず赤に倒れるとは言えない**
 * - 間接呼び出し (runSettled を変数へ束ねる) / 別モジュールへの切り出し
 *
 * 新しく runSettled を呼ぶ関数を足すときは、まず behavioral テストで
 * 「先行する構造操作で index がずれた後に正しい対象へ当たる」ことを固定し、
 * そのうえで下の目録へ「対象をどう捕捉するか」付きで登録すること。
 */

const EDITOR_PATH = path.resolve(
  __dirname,
  "../../../resources/js/components/features/manual/ScenarioEditor.svelte",
);

/**
 * runSettled を呼んでよい関数と、その closure が「対象をどう捕捉するか」。
 * 値は根拠であり、増減のどちらでも本テストが赤くなる。
 */
const DEFERRED_OP_INVENTORY: Readonly<Record<string, string>> = {
  addStep: "対象を持たない (末尾へ追加するだけ)",
  addPoint: "親手順を clientKey で解決し直す",
  removeStep: "対象手順を clientKey で解決し直す",
  removePoint: "親手順と対象急所を clientKey で解決し直す",
  moveStepTo: "対象手順を clientKey で解決し直す (移動先は位置そのものが意図)",
  movePointTo: "親手順と対象急所を clientKey で解決し直す (移動先は位置)",
  undo: "対象を持たない (undoStack の先頭)",
  redo: "対象を持たない (redoStack の先頭)",
};

/** 行コメント・ブロックコメントを落とす (説明文中の runSettled を数えないため) */
const stripComments = (source: string): string =>
  source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "");

/** 名前付き function 宣言 (帰属先として認める唯一の形) */
const DECLARATION = /^\s*function\s+([A-Za-z0-9_$]+)\s*\(/;
/**
 * 文レベルの arrow function 定義 (`const foo = (...): T => {`)。
 * **狭く書いてある**のが要点で、`steps.some((s) => …)` のような引数の中の arrow や
 * `$derived.by(() => …)` には一致しない (一致させると誤検出で赤くなるだけで得が無い)。
 */
const ARROW_DEFINITION =
  /^\s*(?:export\s+)?(?:const|let|var)\s+[A-Za-z0-9_$]+[^=]*=\s*(?:async\s*)?\([^)]*\)\s*(?::[^=]*)?=>/;
const CALL = /(^|[^.\w])runSettled\s*\(/;

interface CallSite {
  readonly line: number;
  readonly caller: string;
  /** 直前のスコープ開始行が名前付き function 宣言だったか */
  readonly fromNamedFunction: boolean;
}

/**
 * runSettled 呼び出しを、直前に現れた名前付き function 宣言へ便宜的に関連付ける。
 * 併せて「呼び出し行の直前に開かれたスコープが名前付き function だったか」を持ち帰り、
 * arrow function から直接呼ぶ形 (= 帰属が偽装される形) を検出できるようにする。
 */
const collectCallSites = (source: string): CallSite[] => {
  const sites: CallSite[] = [];
  const lines = stripComments(source).split("\n");
  let current: string | null = null;
  let lastOpenerWasNamed = false;
  for (const [index, line] of lines.entries()) {
    // **宣言行を先に処理して continue する**。`function runSettled(action: () => void)` の
    // 宣言行自体が CALL に一致するため、後回しにすると直前の関数からの呼び出しとして
    // 余分に数えてしまう。
    const declared = DECLARATION.exec(line);
    if (declared !== null && declared[1] !== undefined) {
      current = declared[1];
      lastOpenerWasNamed = true;
      continue;
    }

    // arrow 定義は**呼び出し判定より先**に見る。1 行で書かれた
    // `const foo = (): void => { runSettled(…); };` を同じ行で弾くため。
    // `runSettled(() => {` は行頭の変数宣言ではないので ARROW_DEFINITION に一致せず、
    // addStep / addPoint の検出には影響しない。
    if (ARROW_DEFINITION.test(line)) {
      lastOpenerWasNamed = false; // 文レベルの arrow 定義が挟まったら帰属を信用しない
    }

    if (CALL.test(line) && current !== null && current !== "runSettled") {
      sites.push({ line: index + 1, caller: current, fromNamedFunction: lastOpenerWasNamed });
    }
  }
  return sites;
};

describe("ScenarioEditor deferred ops inventory", () => {
  it("runSettled を呼ぶのは目録に登録された名前付き関数だけである", async () => {
    const source = await fs.readFile(EDITOR_PATH, "utf-8");
    const sites = collectCallSites(source);

    // 未登録の経路が 1 つでもあれば赤 (deny-by-default)
    expect([...new Set(sites.map((site) => site.caller))].sort()).toEqual(
      Object.keys(DEFERRED_OP_INVENTORY).sort(),
    );
    // 件数も完全一致で pin (同じ関数から 2 回呼ぶ形が生まれたら気づく)
    expect(sites.length).toBe(Object.keys(DEFERRED_OP_INVENTORY).length);
    // 検出対象の文レベル arrow function から直接呼ぶ形を弾く
    // (メソッド・入れ子関数は検出しない = 保証外。冒頭コメント参照)
    expect(sites.filter((site) => !site.fromNamedFunction)).toEqual([]);
  });

  it("目録の各エントリは対象の捕捉方法を根拠として持つ", () => {
    for (const [name, rationale] of Object.entries(DEFERRED_OP_INVENTORY)) {
      expect(rationale.length, `${name} の根拠が短すぎる`).toBeGreaterThanOrEqual(10);
    }
  });
});
