# Round 5 (最終): 詳細設計の修正版レビュー (施策 6 の判定順のみ修正)

Round 4 の Warning 1 件を受け入れ、`collectCallSites` の処理順を提示どおりに直しました。
1 反復の処理順は **(1) 名前付き function 宣言 → continue / (2) ARROW_DEFINITION で帰属の信用を落とす /
(3) 呼び出し判定** です。施策 1-5 に設計変更はありません。

これが最終ラウンドです。残る問題が無ければ全体判定 **APPROVED** を出してください。
問題が残る場合は、実装前に必ず直すべき点だけを挙げてください。

---

## 対応マトリクス (Round 4 の指摘への回答)

# 対応マトリクス: design-review Round 4

全体判定 **CHANGES_REQUESTED**。施策 1-5 は APPROVE 継続。施策 6 のみ Warning 1 件。
**対応する** (反論なし)。

## [Warning] 施策 6: 1 行で書かれた arrow 定義からの呼び出しを `fromNamedFunction` で弾けない

> `const foo = (): void => { runSettled(...); };` は、`CALL` 判定の時点で
> `lastOpenerWasNamed` がまだ直前の名前付き関数の状態のままなので true で登録される。

- 判断: **対応する (こちらの順序ミス)**
- 根拠: 正しい。Round 3 で「宣言行を先に処理する」ところまでは直したが、
  `ARROW_DEFINITION` の状態更新は依然として `CALL` 判定の**後**に置いていた。
  そのため 1 行に収まった arrow 定義では、その行の呼び出しが
  「直前の名前付き関数からの呼び出し」として `fromNamedFunction: true` で登録される。
  件数が 9 になるので**この追加だけなら赤くはなる**が、
  Codex の言うとおり「既存呼び出しの削除と同時に足された」場合は件数が相殺され、
  arrow 検査としては機能しない。**設計が主張している保証と実装が一致していない**状態だった。
  施策 5 の負のコントロール (d2) は、まさにこの形を実測する手順なので、
  直さないと (d2) が「検出できない」という結果になり、
  せっかく塞いだつもりの穴が塞がっていないことになる。
- 対応内容: Codex 提示の順序へ変更した。1 反復の中の処理順を
  **(1) 名前付き function 宣言 → `continue` / (2) `ARROW_DEFINITION` で帰属の信用を落とす /
  (3) 呼び出し判定** とした。
  `runSettled(() => {` は行頭の変数宣言ではないため `ARROW_DEFINITION` に一致せず、
  `addStep` / `addPoint` の検出には影響しない (Codex も同じ結論)。
  これで 1 行形式・複数行形式のどちらの arrow 定義も同じく弾ける。

## Codex が確認した事実 (追認)

- 修正後の走査は現行 `ScenarioEditor.svelte` に対して **ちょうど 8 件**
  (`addStep` / `addPoint` / `removeStep` / `removePoint` / `moveStepTo` / `movePointTo` /
  `undo` / `redo`) を返し、**全件 `fromNamedFunction: true`** になる。
- `onKeydown` (L585) / `onBeforeUnload` (L845) は最後の `runSettled` 呼び出し (L519) より
  後ろにあるため、現時点で誤検出を起こさない。
- リスク表の訂正文にも問題なし。


---

## 修正後の施策 6 (全文)

## 施策 6: 遅延 queue に積む経路の目録テスト (deny-by-default)

### 背景 (なぜ機械で固定するか)

本欠陥が T185 で残ったのは、**棚卸しが散文の申し送りだったから**である。
T185 は並べ替え 2 経路を直し、残り 3 経路を devnotes に書き残したが、
次に構造操作を足す人がそれを読む保証は無かった (実際、本タスクは Codex レビューの
指摘が無ければ起票されていない)。同じことを繰り返さないため、
**`runSettled` に積む経路が増減したら赤くなる**検出点を置く。

本リポジトリは同種の不変条件を deny-by-default の目録テストで固定する流儀を持ち、
JS レーンにも先例がある (`tests/js/architecture/logout-call-site-inventory.test.ts` /
`recent-auth-modal-call-site-inventory.test.ts` / `svg-inline-allowlist.test.ts`)。
これらと同じ様式に揃える。

### 変更箇所

- ファイル: `tests/js/architecture/scenario-editor-deferred-ops-inventory.test.ts` (**新規**)

### 波及変更

- アプリコード: **なし** (走査するだけ)
- 既存テスト: **なし**
- ドキュメント: **なし** (目録の正本はテストファイル自身。AGENTS.md へ件数を写さない
  = 「2 か所に書くと必ず食い違う」の一般則に従う)

### 設計

```ts
import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * ScenarioEditor の「IME 変換中に遅延実行される操作」の目録を deny-by-default で固定する。
 *
 * 不変条件: 遅延実行される構造操作は、対象を安定キー (clientKey) で捕捉し、
 * 実行時に解決し直してから変異する。数値 index を持ち回ると、先行する遅延操作で
 * 添字がずれて別の行へ適用される (T185 で並べ替え 2 経路、本タスクで削除・追加 3 経路を修正)。
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
 * 現行ファイルでの一致は 2 件 (onKeydown / onBeforeUnload) で、どちらも
 * runSettled 呼び出しより後ろにある = 現時点の誤検出は 0 件 (実測で確認済み)。
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
    // 9 件目に数えてしまう (design-review R3)。
    const declared = DECLARATION.exec(line);
    if (declared) {
      current = declared[1];
      lastOpenerWasNamed = true;
      continue;
    }

    // arrow 定義は**呼び出し判定より先**に見る。1 行で書かれた
    // `const foo = (): void => { runSettled(…); };` を同じ行で弾くため (design-review R4)。
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
```

**設計上の注意**:

- **保証範囲を誇張しない**。このテストは「closure が安定キーで解決しているか」を検証できない。
  検出できるのは「遅延 queue に積む経路が増減したこと」だけであり、しかもそれは
  **現在のソース書式を前提にした便宜的な帰属**の上に成り立っている。
  この限界をテスト冒頭のコメントに明記し、
  新しい経路を足す人へ「先に behavioral テストを書け」と誘導する。
- **「誤検出は必ず件数不一致で赤に倒れる」とは書かない** (design-review R2)。
  コメント除去の正規表現は文字列・テンプレートリテラル・正規表現リテラルを区別しないので、
  誤除去と別の増減が相殺して緑になる配置は理屈の上で作れる。
  この検査は**証明ではなくトリップワイヤ**である。
- **AST 走査 (Codex 推奨案) を採らなかった理由**: Svelte 5 の `lang="ts"` を含む
  単一ファイルのために parser 依存を新設することになり、「今必要なものだけ作る」に反する。
  本リポジトリの JS レーンの既存目録テスト
  (`logout-call-site-inventory` / `svg-inline-allowlist` / `lucide-scoped-import`) も
  すべて字句走査で、様式が揃う。**将来この判断を見直すなら**、
  誤帰属の実害が実際に起きたときが契機である。
- 走査対象を `ScenarioEditor.svelte` **1 ファイルに限定**する。
  `runSettled` はこのコンポーネント固有の仕組みで、他所には存在しない
  (実コード走査で確認済み)。全 `resources/js` を走査する形にすると、
  無関係な同名関数を将来拾う可能性が出るだけで得が無い。
- コメント除去は素朴な正規表現で足りる。当該ファイルに `://` は 1 件も無く
  (URL リテラルは `/projects/...` のようにスラッシュ 1 つ)、
  現時点で文字列中の `//` を誤って落とす箇所が無いことは実測で確認した。
  ただしこれは**現在のソースについての観測であって、性質の保証ではない**。
- 目録は**テストファイル自身が正本**とし、AGENTS.md や docs へ件数を写さない。
- `ARROW_DEFINITION` の現行ファイルでの一致は 2 件 (L585 `onKeydown` /
  L845 `onBeforeUnload`) で、いずれも最後の `runSettled` 呼び出し (L519) より後ろにある。
  よって現時点で `fromNamedFunction` が false になる呼び出しは 0 件である (実測で確認)。

### PHPStan 適合チェック

- [x] 該当なし (PHP 変更無し)。TypeScript は `pnpm typecheck`:
  - `Readonly<Record<string, string>>` で目録を宣言し、素の型アサーションを使わない
  - `RegExp.exec` の結果を null チェックしてから添字アクセス

### テスト計画

- [x] 新規テスト 2 件 (目録一致 + 名前付き関数からの呼び出しであること / 根拠の存在)
- [x] 負のコントロール: 施策 5 の変種 (d1)(d2) で、名前付き function と
      文レベル arrow function の両方から `runSettled` を呼んで赤くなるかを実測する。
      **検出できない形が見つかったらそれを記録する** (テストを無理に強くしない)

### リスク

- **偽陽性でうるさくなる**: 経路を足すたびに 1 行の登録が要る。
  これは意図した摩擦である (登録操作がレビューで必ず見える = deny-by-default の目的)。
  一方 `ARROW_DEFINITION` による帰属不信検査は、既存関数の中に文レベル arrow 定義を
  書いてから `runSettled` を呼ぶと**正しい実装でも赤くなる**。
  その場合は「呼び出しを名前付き関数の先頭側へ寄せる」か「本テストの限界として
  パターンを見直す」かを、レビューで判断する (静かに緑にしない)。
- **字句走査の脆さ**: `runSettled` を変数へ束ねる・別モジュールへ切り出す・
  入れ子関数から呼ぶといった形は検出外になる。限界としてテストへ明記し、誇張しない。

---


