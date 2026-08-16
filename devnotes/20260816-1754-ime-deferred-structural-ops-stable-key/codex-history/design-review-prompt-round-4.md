# Round 4: 詳細設計の修正版レビュー (施策 6 のみ修正)

Round 3 の Warning 2 件・Suggestion 1 件はすべて受け入れ、修正しました (反論なし)。
施策 1-5 は Round 3 で APPROVE 済みのため設計変更はありません
(リスク表の 1 行だけ、Suggestion に従って文言を正確にしました)。

修正内容:
1. `collectCallSites` を Codex 提示の順序へ変更 — 宣言行を先に処理して `continue` する。
   `function runSettled(action: () => void): void {` が 9 件目として数えられる問題を解消。
2. assertion 直上のコメントを「検出対象の文レベル arrow function から直接呼ぶ形を弾く
   (メソッド・入れ子関数は検出しない = 保証外)」へ訂正。
3. リスク表を「6・7 件目が誤変異に伴う余分な履歴エントリを Undo 回数で検証し、
   8 件目が no-op 後も drain が続くことを検証する」へ訂正。

確認してほしいこと:
- 修正後の `collectCallSites` が現行 `ScenarioEditor.svelte` に対して**ちょうど 8 件**を返し、
  `fromNamedFunction` がすべて true になるか。
- ほかに事実誤認・実装したら即落ちる箇所が残っていないか。
- 残っていなければ全体判定 APPROVED を出してください。

---

## 対応マトリクス (Round 3 の指摘への回答)

# 対応マトリクス: design-review Round 3

全体判定 **CHANGES_REQUESTED**。施策 1-5 は **APPROVE**。施策 6 のみ REQUEST_CHANGES。
Warning 2 件 / Suggestion 1 件。**すべて対応する** (反論なし。いずれもこちらの誤り)。

## [Warning] 施策 6: `runSettled` の宣言行自体が 9 件目として数えられる

> `function runSettled(action: () => void): void {` にも `CALL` が一致し、
> `current !== "runSettled"` は宣言判定より前なので防げない。

- 判断: **対応する (こちらのバグ。実害のある指摘)**
- 根拠: 完全に正しい。`CALL = /(^|[^.\w])runSettled\s*\(/` は
  `function runSettled(action: () => void): void {` にも一致する
  (`function ` の直後なので `[^.\w]` = 半角空白に一致する)。
  そして呼び出し判定を宣言判定より**前**に置いていたため、この行を評価する時点で
  `current` は直前に宣言された関数 (`flushPendingEdit`) のままである。
  結果、目録は 8 件を期待するのに走査は 9 件を返し、
  **`flushPendingEdit` が未登録の呼び出し元として現れて常時赤**になる。
  設計のまま実装すると初回から落ちる = 実害のあるバグだった。
- 対応内容: Codex の提示した修正案をそのまま採用した。
  宣言行を**先に処理して `continue`** する形へ変え、
  「宣言行は呼び出し判定に掛けない」ことをコードとコメントの両方で明示した。
  `addStep` / `addPoint` の `runSettled(() =>` は宣言行ではないので、
  この順序でも呼び出しとして正しく数えられる (Codex も同じ結論)。
  引数の中の arrow は `ARROW_DEFINITION` に一致しないので同一行の判定に影響しない。

## [Warning] 施策 6: assertion のコメントだけが保証範囲を超えている

> 「arrow function / メソッドから直接呼ぶ形は…」と書いてあるが、実際に検出するのは
> `ARROW_DEFINITION` に一致する文レベル arrow だけ。メソッドは検出していない。

- 判断: **対応する**
- 根拠: 正しい。テスト冒頭のコメントでは「メソッドは保証外」と正しく書いたのに、
  assertion 直上のコメントだけが古い表現のまま残っていた。
  本リポジトリが繰り返し要求している「保証範囲を誇張しない」に反する。
- 対応内容: 該当コメントを
  「検出対象の文レベル arrow function から直接呼ぶ形を弾く
  (メソッド・入れ子関数は検出しない = 保証外。冒頭コメント参照)」へ書き換えた。
  メソッド検出の追加は行わない (Codex も「トリップワイヤに限定する方針ならコメント修正で十分」
  としており、検出語彙を勝手に増やさない方針と一致する)。

## [Suggestion] 全体リスク表の履歴検証の記述が不正確

> ケース 8 は Undo 履歴を直接検証していない。

- 判断: **対応する**
- 根拠: 正しい。ケース 8 が検証するのは「no-op の後も drain が続くこと」であって
  履歴ではない。リスク表の「施策 4 の 6・7・8 件目がこれ (履歴の整合) を直接検証する」は
  8 件目について不正確だった。
- 対応内容: 「施策 4 の 6・7 件目が『誤変異に伴う余分な履歴エントリ』を Undo 回数で検証し、
  8 件目が『no-op の後も drain が続くこと』を検証する」へ書き換えた。

## Codex が APPROVE とした施策 (1・2・3・4・5)

- 変更しない。ケース 6・7・8 の期待値が JavaScript の実挙動と一致していること、
  変種 (a)/(b1)/(b2)/(b3)/(d) が施策 4・6 と 1:1 対応していることを追認された。


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

    if (CALL.test(line) && current !== null && current !== "runSettled") {
      sites.push({ line: index + 1, caller: current, fromNamedFunction: lastOpenerWasNamed });
    }

    // 呼び出し判定の**後**に arrow 定義を見る
    // (`runSettled(() => {` は同じ行で arrow を開くが、引数の中の arrow は
    //  ARROW_DEFINITION に一致しないので呼び出し自身の判定に影響しない)
    if (ARROW_DEFINITION.test(line)) {
      lastOpenerWasNamed = false; // 文レベルの arrow 定義が挟まったら帰属を信用しない
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



---

## 参考: 修正後の詳細設計書 (全文)

# 詳細設計: ime-deferred-structural-ops-stable-key (IME 変換中の構造操作を安定キーで解決する)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 (AGENTS.md 「禁止事項」より)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

**本設計との関係**: 2〜7 は PHP / LLM / HTTP 応答に関する規約で、本設計はサーバ側を一切変更しない
ため無関係である。1 (テスト必須) と 8 (disabled にしない) が直接効く。
8 については、本設計は既存ボタンの `disabled` 状態を**一切増やさない**
(削除・追加ボタンは常に押下可能で、対象が解決できない場合は押下を受けたうえで何もしない)。

### コーディングルール

- **PHPStan level 10** 必須 — **本設計に PHP 変更は無いため該当なし** (実装時は無変更の確認として `composer phpstan` を通す)
- **Pest** テストフレームワーク — **本設計に PHP テストは無い**。テストは Vitest (`pnpm test`)
- **RefreshDatabase** + `--parallel` — 該当なし (DB に触れない)
- **テストデータは必ず Factory で生成** — 該当なし。フロントテストは既存の `makeDocument()` /
  `makeDndDocument()` 相当のヘルパで document を組む (既存流儀に合わせる)
- **DTO + JsonResource** パターン — 該当なし (サーバ応答契約は不変)
- **アーリーリターン** 推奨 — 本設計の解決ロジックはすべて早期 return で書く
- **コードフォーマット**: `pnpm lint:fix` (Prettier + ESLint)
- フロント規約: Svelte 5 runes + DS token のみ / component 階層は単方向 import /
  アイコンは `@lucide/svelte` のみ — **本設計は新しい component / icon / token を増やさない**
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260816-1754-ime-deferred-structural-ops-stable-key/conceptual-design.md`
(Codex conceptual-review Round 1 で **APPROVED**。Warning 4 件は反映済み。
対応マトリクスは `codex-history/conceptual-review-decisions-round-1.md`)

## 不変条件 (本タスクが固定するもの)

> **遅延実行される構造操作は、削除・追加・並べ替えを問わず、対象を安定キー (`clientKey`) で
> 捕捉し、実行時に解決し直してから変異する。解決できなければ何もしない。**

T185 が並べ替え 2 経路で確立した形を、残り 3 経路へ横展開してこの不変条件を
`ScenarioEditor` 全体で成立させる。

### 遅延 queue に積まれる全操作の棚卸し (`runSettled` 呼び出し 8 件。実コード走査で確定)

| # | 操作 | closure が捕捉する値 (現行) | 判定 | 本タスクでの扱い |
|---|------|---------------------------|------|-----------------|
| 1 | `addStep()` (L216) | なし (末尾 push) | 安全 | 変更しない |
| 2 | `addPoint(stepIndex)` (L222) | 親手順の数値 index | **欠陥** | 施策 1: 親を `clientKey` で解決 |
| 3 | `removeStep(index)` (L228) | 手順の数値 index | **欠陥** | 施策 3: 手順を `clientKey` で解決 |
| 4 | `removePoint(stepIndex, pointIndex)` (L233) | 親 + 対象の数値 index | **欠陥** | 施策 2: 両方を `clientKey` で解決 |
| 5 | `moveStepTo(from, to)` (L252) | `from` は `clientKey` (T185) / `to` は位置 | 安全 | 変更しない |
| 6 | `movePointTo(stepIndex, from, to)` (L277) | 親と対象が `clientKey` (T185) / `to` は位置 | 安全 | 変更しない |
| 7 | `undo()` → `doUndo` (L515) | なし (undoStack 先頭) | 安全 | 変更しない |
| 8 | `redo()` → `doRedo` (L519) | なし (redoStack 先頭) | 安全 | 変更しない |

- **`to` (移動先位置) を安定キー化しないのは意図的**。「n 番目へ移動する」は位置そのものが意図。
- **`addStep()` が安全なのは「末尾へ追加」だから**。位置指定の追加を将来足すなら同じ監査が要る。

### queue の外にある同種の弱点

- **`confirmingStepIndex` (削除確認ダイアログ)**: 数値 index が「ボタン押下」と「確定」の間の
  非同期境界をまたぐ。開いている間に `compositionend` で遅延中の並べ替えが実行されると、
  確定時点で index は別の手順を指す。**施策 3 で `confirmingStepKey` へ替えて閉じる。**
- **`save()` は `runSettled` を通さない** (変換確定前の文字列で PUT しうる)。
  「対象の取り違え」ではなく「確定前テキストの送信」という別種の論点なので**本タスクでは扱わない**
  (棚卸しの結果として記録のみ)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `addPoint` を親手順の安定キーで解決する | `resources/js/components/features/manual/ScenarioEditor.svelte` | 高 |
| 2 | `removePoint` を親手順+急所の安定キーで解決する | 同上 | 高 |
| 3 | `removeStep` と削除確認ダイアログを安定キー化する | 同上 | 高 |
| 4 | 回帰テストの追加 (index ずれ後の削除/追加が正しい対象へ当たる) | `tests/js/components/features/manual/ScenarioEditor.test.ts` | 高 |
| 5 | 負のコントロールの実測と記録 | `devnotes/{dir}/negative-control.md` | 高 |
| 6 | 遅延 queue に積む経路の目録テスト (deny-by-default) | `tests/js/architecture/scenario-editor-deferred-ops-inventory.test.ts` (新規) | 中 |

---

## 施策 1: `addPoint` を親手順の安定キーで解決する

### 変更箇所

- ファイル: `resources/js/components/features/manual/ScenarioEditor.svelte` (L222-226 = 関数本体、L1230 = 呼び出し側)

### 波及変更

- TypeScript 型定義: **なし** (`DraftStep.clientKey` / `DraftPoint.clientKey` は
  `resources/js/types/manual.ts` L177-184 に既存。新しい型は増やさない)
- API Resource/DTO: **なし** (PUT payload = `payloadSteps()` は不変。`clientKey` は送らない)
- Inertia Props: **なし** (`Props` interface 不変)
- テストファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts`
  (施策 4 で新規ケース追加。既存の「急所を追加できる (行内の急所を追加ボタン)」L205 は
  `data-testid` 経由の操作なので**変更不要**)
- markup: 呼び出し側 1 箇所 (`step-{stepIndex}-add-point` ボタンの `onclick`)。
  `data-testid` は変えない (既存テストを壊さない)

### 現行コード

```svelte
<script lang="ts">
    function addPoint(stepIndex: number): void {
        runSettled(() =>
            commitStructural(() => steps[stepIndex].points.push({ ...emptyRow("yori"), id: null })),
        );
    }
</script>

<!-- markup (L1226-1236) -->
<Button
    variant="ghost"
    size="sm"
    onclick={() => addPoint(stepIndex)}
    testId="step-{stepIndex}-add-point"
>
```

### 変更後コード

```svelte
<script lang="ts">
    /**
     * 急所の追加。
     * **親手順は数値 index ではなく安定キー (clientKey) で持ち回る**。`runSettled` は IME 変換中に
     * 実行を compositionend まで遅らせるため、実行時点では手順の並びが変わっていることがある
     * (moveStepTo / movePointTo と同じ理由)。実行時に親手順が消えていたら**何もしない** —
     * 「この手順に足す」という意図が無効になったので、別の手順や末尾へ足さない。
     */
    function addPoint(stepKey: string): void {
        runSettled(() => {
            const at = steps.findIndex((step) => step.clientKey === stepKey); // 実行時点で解決し直す
            if (at < 0) return;
            const parent = steps[at];
            commitStructural(() => parent.points.push({ ...emptyRow("yori"), id: null }));
        });
    }
</script>

<!-- markup -->
<Button
    variant="ghost"
    size="sm"
    onclick={() => addPoint(step.clientKey)}
    testId="step-{stepIndex}-add-point"
>
```

**設計上の注意**:

- `emptyRow("yori")` は closure の**中**で呼ぶ。`nextClientKey()` の採番が実行時に起きるため、
  遅延しても採番が飛ばず、undo/redo の round-trip も現行と同じ。
  (呼び出し時に採番して closure が捕捉する形にすると、解決失敗で捨てられたときに
  採番だけ進む = 実害は無いが無駄な差が出る。現行同様 closure 内で作る)
- `parent` は `commitStructural` の**外**で解決する。`commitStructural` は
  `flushPendingEdit()` → `before` 採取 → `mutate()` の順で走るので、`mutate` の中で
  解決すると「解決前に before を採る」ことになり読み手が混乱する。
  `flushPendingEdit()` は `steps` の**配列構造を変えない** (履歴 push のみ) ので、
  外で解決した参照が `mutate` 時点で無効になることはない。
- `steps[at]` は `at >= 0` を確認した後にのみ触る (早期 return)。
  `noUncheckedIndexedAccess` は無効 (`tsconfig.json` は `strict` のみ) なので型は
  `DraftStep` になるが、読み手に安全性が見えるよう分岐の後に置く。
  これは `movePointTo` (L285-288) と同じ書き方である。

### PHPStan 適合チェック

- [x] **該当なし** (PHP の変更が無い)。TypeScript 側は `pnpm typecheck` で確認する:
  - `addPoint` の引数型が `string` になり、数値を渡す呼び出しが残っていればコンパイルエラー
  - `findIndex` の戻り値 `number` を `< 0` で分岐してから添字アクセス
  - 素の型アサーション (`as`) を追加しない

### テスト計画

施策 4 に集約 (下記)。本施策に対応するケース: 「変換中に並べ替え → 急所追加 を続けて要求すると、
急所は**押した手順**にぶら下がる」「親手順が消えていたら no-op で完走する」。

### リスク

- **呼び出し漏れ**: markup 側の `addPoint(stepIndex)` を替え忘れると型エラーになる
  (`number` → `string`)。`pnpm typecheck` が検出するので silent break にはならない。
- **後退リスクは低い**: 変換中でない通常操作では `findIndex` が押した手順を必ず返すため、
  観測できる挙動は現行と完全に同一。

---

## 施策 2: `removePoint` を親手順+急所の安定キーで解決する

### 変更箇所

- ファイル: `resources/js/components/features/manual/ScenarioEditor.svelte` (L233-235 = 関数本体、L1199 = 呼び出し側)

### 波及変更

- TypeScript 型定義 / API Resource / DTO / Inertia Props: **なし**
- テストファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts` (施策 4)。
  既存の「急所の削除はダイアログなしで行える」(L228) は testId 経由なので**変更不要**
- markup: 呼び出し側 1 箇所 (`point-{stepIndex}-{pointIndex}-remove` ボタンの `onclick`)

### 現行コード

```svelte
<script lang="ts">
    function removePoint(stepIndex: number, pointIndex: number): void {
        runSettled(() => commitStructural(() => steps[stepIndex].points.splice(pointIndex, 1)));
    }
</script>

<!-- markup (L1194-1203) -->
<Button
    variant="danger-ghost"
    size="sm"
    iconOnly
    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を削除`}
    onclick={() => removePoint(stepIndex, pointIndex)}
    testId="point-{stepIndex}-{pointIndex}-remove"
>
```

### 変更後コード

```svelte
<script lang="ts">
    /**
     * 急所の削除。
     * **親手順・対象の急所とも安定キー (clientKey) で持ち回る** (理由は addPoint と同じ)。
     * 見つからなければ**何もしない** — もう消えているなら意図は満たされている。
     * **早期 return は必須**である: 落とすと splice(-1, 1) が末尾の急所を消す。
     */
    function removePoint(stepKey: string, pointKey: string): void {
        runSettled(() => {
            const stepAt = steps.findIndex((step) => step.clientKey === stepKey);
            if (stepAt < 0) return;
            const parent = steps[stepAt];
            const at = parent.points.findIndex((point) => point.clientKey === pointKey);
            if (at < 0) return; // ← 落とすと splice(-1,1) で末尾を消す (design-review R1)
            commitStructural(() => parent.points.splice(at, 1));
        });
    }
</script>

<!-- markup -->
<Button
    variant="danger-ghost"
    size="sm"
    iconOnly
    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を削除`}
    onclick={() => removePoint(step.clientKey, point.clientKey)}
    testId="point-{stepIndex}-{pointIndex}-remove"
>
```

**設計上の注意**:

- 親 → 子の順に解決する。急所の `clientKey` は**手順をまたいで一意**
  (`nextClientKey()` はコンポーネントインスタンス内の単調増加カウンタ、L63-67) なので、
  理屈の上では全手順を横断して探しても同じ結果になる。しかし
  「急所は手順をまたがない」という既存のドメイン制約 (L237 のコメント、`movePointTo` の実装) を
  コードに残すため、**親を先に解決してからその配下だけを探す**形にする。
  こうすると「親が消えているのに子だけ見つかる」という状態が構造的に起きない。
- `at` は `splice` の直前に解決済みの値をそのまま使う。`commitStructural` は
  `flushPendingEdit()` (履歴 push のみ) しか挟まないので、`at` が無効化されることはない。

### PHPStan 適合チェック

- [x] 該当なし (PHP 変更無し)。TypeScript は `pnpm typecheck`:
  - 引数 2 つとも `string`。markup 側で `number` を渡していればコンパイルエラー
  - `findIndex` 2 回とも `< 0` 早期 return の後に添字アクセス

### テスト計画

施策 4 に集約。対応ケース: 「変換中に手順の並べ替え → 急所の削除 を続けて要求すると、
**掴んだ手順の急所**が消える」。

### リスク

- **後退リスクは低い**。通常操作では両 `findIndex` が押した行を必ず返す。
- 親を先に解決する分だけ探索が 2 段になるが、シナリオの規模 (手順数十・急所数個) では
  計測に出ない。既存の `movePointTo` と同じ計算量である。

---

## 施策 3: `removeStep` と削除確認ダイアログを安定キー化する

### 変更箇所

- ファイル: `resources/js/components/features/manual/ScenarioEditor.svelte`
  - L182 `confirmingStepIndex` の宣言
  - L228-231 `removeStep` の本体
  - L588 キーボードショートカット `$effect` 内のガード条件
  - L1124 削除ボタンの `onclick`
  - L1323-1332 `ConfirmDialog` の `open` / `onConfirm` / `onCancel`

### 波及変更

- TypeScript 型定義 / API Resource / DTO / Inertia Props: **なし**
- `ConfirmDialog` の props 契約 (`ConfirmDialogProps`): **変更なし**。
  `open: boolean` へ渡す式が `confirmingStepIndex !== null` から
  `confirmingStepKey !== null` に変わるだけで、`boolean` のまま
- テストファイル: 既存の「手順の削除は確認ダイアログを経由し、配下の急所ごと消える」(L213) と
  「手順削除 (確認ダイアログ) → Undo で配下急所ごと復活」(L958) は
  `data-testid` (`step-0-remove` / `scenario-step-remove-dialog`) 経由なので**変更不要**。
  施策 4 で新規ケースを足す
- markup: 削除ボタン 1 箇所 + ダイアログ 1 箇所

### 現行コード

```svelte
<script lang="ts">
    let confirmingStepIndex = $state<number | null>(null);

    function removeStep(index: number): void {
        runSettled(() => commitStructural(() => steps.splice(index, 1)));
        confirmingStepIndex = null; // 確認ダイアログを閉じるのは即時 (履歴とは独立)
    }

    // キーボードショートカット $effect 内 (L588)
    if (saving || confirmingStepIndex !== null || confirmingReload) return;
</script>

<!-- 削除ボタン (L1119-1128) -->
<Button
    variant="danger-ghost"
    size="sm"
    iconOnly
    ariaLabel={`手順 ${stepIndex + 1} を削除`}
    onclick={() => (confirmingStepIndex = stepIndex)}
    testId="step-{stepIndex}-remove"
>

<!-- ダイアログ (L1323-1332) -->
<ConfirmDialog
    open={confirmingStepIndex !== null}
    title="手順を削除しますか?"
    message="この手順を削除すると、配下の急所と登録済みのテイク (撮影動画) も一緒に削除されます。この操作は「シナリオを更新」で保存すると元に戻せません。"
    confirmLabel="削除する"
    confirmVariant="danger"
    onConfirm={() => confirmingStepIndex !== null && removeStep(confirmingStepIndex)}
    onCancel={() => (confirmingStepIndex = null)}
    testId="scenario-step-remove-dialog"
/>
```

### 変更後コード

```svelte
<script lang="ts">
    /**
     * 削除確認ダイアログが対象にしている手順の**安定キー** (null = 閉じている)。
     * 数値 index を持つと、ダイアログを開いている間に compositionend で遅延中の並べ替えが
     * 実行されたとき、確定した時点で index が別の手順を指す。
     */
    let confirmingStepKey = $state<string | null>(null);

    /**
     * 手順の削除。
     * **対象は数値 index ではなく安定キー (clientKey) で持ち回る** (理由は addPoint と同じ)。
     * 見つからなければ何もしない。**早期 return は必須** (落とすと splice(-1,1) で末尾が消える)。
     * ダイアログを閉じるのは解決の成否によらず即時に行う。
     */
    function removeStep(stepKey: string): void {
        runSettled(() => {
            const at = steps.findIndex((step) => step.clientKey === stepKey); // 実行時点で解決し直す
            if (at < 0) return; // ← 落とすと splice(-1,1) で末尾を消す (design-review R1)
            commitStructural(() => steps.splice(at, 1));
        });
        confirmingStepKey = null; // 確認ダイアログを閉じるのは即時 (履歴とは独立)
    }

    // キーボードショートカット $effect 内
    if (saving || confirmingStepKey !== null || confirmingReload) return;
</script>

<!-- 削除ボタン -->
<Button
    variant="danger-ghost"
    size="sm"
    iconOnly
    ariaLabel={`手順 ${stepIndex + 1} を削除`}
    onclick={() => (confirmingStepKey = step.clientKey)}
    testId="step-{stepIndex}-remove"
>

<!-- ダイアログ -->
<ConfirmDialog
    open={confirmingStepKey !== null}
    title="手順を削除しますか?"
    message="この手順を削除すると、配下の急所と登録済みのテイク (撮影動画) も一緒に削除されます。この操作は「シナリオを更新」で保存すると元に戻せません。"
    confirmLabel="削除する"
    confirmVariant="danger"
    onConfirm={() => confirmingStepKey !== null && removeStep(confirmingStepKey)}
    onCancel={() => (confirmingStepKey = null)}
    testId="scenario-step-remove-dialog"
/>
```

**設計上の注意**:

- **ダイアログの表示内容は対象に依存しない**。`title` / `message` は固定文言で、手順番号も
  scene も描画していない (現行コードで確認済み)。したがって key 化しても表示側に index 依存は
  残らず、`steps` から対象を引き直す derived を作る必要が無い
  (conceptual-review R1 Warning への回答)。
- **対象が解決できなくてもダイアログは閉じる**。`confirmingStepKey = null` は `runSettled` の
  **外**にあるため、遅延されても即時に実行される (現行と同じ)。利用者から見える結果は
  「消したかった行はもう無く、ダイアログも閉じている」で不整合が無い。
  閉じる処理を `compositionend` の drain 側へ移す案は、遅延実行の仕組みへ新しい結合を作るので
  採らない (本タスクの制約: 遅延実行の仕組みを作り替えない)。
- **`onConfirm` の `confirmingStepKey !== null &&` は残す**。TypeScript の narrowing のために
  必要 (`string | null` を `string` 引数へ渡せない)。現行と同じ形。
- **`confirmingStepIndex` という名前は残さない**。AGENTS.md 思考原則 3
  「後方互換の並走を残さない」に従い、同じ変更で旧名を消す。
- `splice(at, 1)` の `at` は `commitStructural` の外で解決済み。`flushPendingEdit()` は
  配列構造を変えないので無効化されない (施策 1 と同じ根拠)。

### PHPStan 適合チェック

- [x] 該当なし (PHP 変更無し)。TypeScript は `pnpm typecheck`:
  - `confirmingStepKey: string | null` の narrowing が `onConfirm` で成立している
  - `removeStep` の引数が `string`。旧呼び出し (`number`) が残ればコンパイルエラー
  - `open` へ渡す式が `boolean` を保つ

### テスト計画

施策 4 に集約。対応ケース: 「変換中に並べ替え → 手順削除 を続けて要求すると、
**確定した手順**が消える」「ダイアログを開いている間に遅延中の並べ替えが確定しても、
**開いたときの手順**が消える」「対象が既に消えていたらダイアログは閉じ、何も起きない」。

### リスク

- **キーボードショートカットのガード条件の替え忘れ**: L588 の `confirmingStepIndex !== null` を
  替え忘れると未定義変数でコンパイルエラーになる (旧名を消すため silent break にならない)。
- **`step.clientKey` が markup で参照できること**は `{#each steps as step, stepIndex (step.clientKey)}`
  (L1079) から自明。
- **後退リスク**: ダイアログの開閉タイミング・文言・testId はいずれも不変なので、
  既存の削除テスト 2 件はそのまま緑であるべき。緑でなければ本設計の想定違いなので調査する。

---

## 施策 4: 回帰テストの追加

### 変更箇所

- ファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts`
  (既存 1533 行。`describe("ドラッグ&ドロップ並べ替え (T185)")` の末尾に隣接して
  新しい `describe` を足す。既存ケースは 1 件も削除・書き換えしない)

### 波及変更

- 既存テストの変更: **なし**。すべて `data-testid` 経由で操作しており、
  本設計は testId を 1 つも変えない
- 新規ヘルパ: 既存の `makeDndDocument()` (2 手順 × 2 急所) と `dragHandle()` /
  `stubRowRects()` を再利用する。**新しいモックや新しい描画ヘルパを作らない**

### テスト設計の方針

T185 のテスト「IME 変換中に手順の並べ替えと急所の並べ替えを続けて確定しても、掴んだ手順の
急所が動く」(L1457-1478) が手本。**先行する構造操作で index がずれた後に、遅延実行された
削除/追加が正しい対象へ適用される**ことを直接検証する。

前提の作り方は既存と同じ:
`fireEvent.compositionStart(...)` で composing に入れ → 遅延させたい操作を順に発火 →
`fireEvent.compositionEnd(...)` で drain → 結果を検証する。

`makeDndDocument()` は手順 2 件 (A / B) × 急所 2 件なので、
「A を末尾へ移動 → B を対象に操作」という形で index を確実にずらせる
(移動後は B が index 0、A が index 1 になる)。

**負の実装 (壊れた実装) を 4 種類想定し、それぞれを捕まえるケースを置く**
(design-review R1/R2 Warning への対応):

| 壊れた実装 | 症状 | 捕まえるケース |
|-----------|------|---------------|
| (a) 数値 index を捕捉したまま (= 現行実装) | 別の行に当たる / 範囲外で `TypeError` | 1・2・3・4・5 |
| (b1) `removeStep` の未検出ガード (`at < 0`) を落とす | `splice(-1, 1)` で**末尾の手順**が消える | 6 |
| (b2) `removePoint` の**子**の未検出ガードを落とす | `splice(-1, 1)` で**末尾の急所**が消える | 7 |
| (b3) `removePoint` の**親**の未検出ガードを落とす | `parent` が `undefined` → `TypeError` で drain 中断 | 8 |

> **(b1)/(b2) と (b3) は壊れ方が違う** (design-review R2 で訂正した点)。
> `Array.prototype.splice` は第 1 引数の負数を**末尾からのオフセット**として解釈するので、
> `splice(-1, 1)` は「何もしない」ではなく**末尾の 1 件を消す**。
> 一方、**添字アクセス `steps[-1]` は末尾要素を返さず `undefined` になる**ので、
> 親の解決失敗をガードしないと次の行で `TypeError` になる。
> 前者は静かなデータ喪失、後者は drain の中断 (後続の遅延操作が失われる) として現れる。

ケース 6・7 の Undo 検証が捕まえるのは「空の履歴エントリ」ではなく、
**誤った 2 回目の実変異 (末尾行の巻き添え) と、それに伴って積まれた履歴エントリ**である
(`commitStructural` は before === after なら `pushHistory` が no-op を返すため、
「変異しないのに履歴が積まれる」状態は現在の履歴コアでは作れない。design-review R2 の訂正)。

### 新規テストケース (8 件)

```ts
describe("IME 変換中の構造操作は安定キーで対象を解決する", () => {
    // stubRowRects() / dragHandle() / makeDndDocument() は T185 の describe から共有する
    // (実装時は共通ヘルパを module scope へ持ち上げるか、describe を統合する)

    it("変換中に「並べ替え → 手順削除」を続けて確定すると、確定した手順が消える", async () => {
        // A を末尾へドラッグ (遅延) → B の削除を確認ダイアログで確定 (遅延)
        // → compositionEnd → 残るのは A のみ (数値 index なら A が消える)
    });

    it("変換中に「並べ替え → 急所追加」を続けて確定すると、押した手順に急所が付く", async () => {
        // A を末尾へドラッグ (遅延) → B の「急所を追加」(遅延)
        // → compositionEnd → B の急所が 3 件、A は 2 件のまま
    });

    it("変換中に「並べ替え → 急所削除」を続けて確定すると、掴んだ手順の急所が消える", async () => {
        // A を末尾へドラッグ (遅延) → A の急所 1 の削除 (遅延)
        // → compositionEnd → A の急所は「急所A-2」のみ、B の急所は 2 件のまま
    });

    it("遅延中に対象手順が消えていたら、急所追加は何も起こさず後続の遅延操作も走る", async () => {
        // A の削除を確定 (遅延) → A の「急所を追加」(遅延) → B の「急所を追加」(遅延)
        // → compositionEnd → 例外を投げずに完走し、B の急所が 3 件になる
        // (現行実装は steps[1] が undefined で TypeError → 3 つ目の操作が失われる)
    });

    it("削除ダイアログを開いている間に遅延中の並べ替えが確定しても、開いたときの手順が消える", async () => {
        // 変換中に A を末尾へドラッグ (遅延) → B の削除ボタンでダイアログを開く
        // → compositionEnd (並べ替えだけ実行され B が index 0 になる)
        // → ダイアログの「削除する」を押す → 消えるのは B
    });

    it("対象が既に消えている手順削除は、末尾の手順を巻き添えにしない", async () => {
        // 変換中に A の削除を確定 (遅延) → もう一度 A の削除を確定 (遅延)
        // → compositionEnd → 消えるのは A だけで B は残る
        //   (壊れた実装 (b1): 2 回目が splice(-1,1) で B を消す)
        // → Undo 1 回で A が戻り、Undo が disabled になる
        //   ((b1) なら 2 回目の誤変異の分だけ履歴が 1 つ多く、Undo 1 回では初期状態に戻らない)
    });

    it("対象が既に消えている急所削除は、末尾の急所を巻き添えにしない", async () => {
        // 変換中に A の急所 1 の削除を 2 回要求 (遅延) → compositionEnd
        // → A に残るのは「急所A-2」だけ (壊れた実装 (b2) なら 0 件になる)
        // → Undo 1 回で「急所A-1」「急所A-2」の 2 件が戻り、Undo が disabled になる
    });

    it("親手順が消えた後の急所削除は no-op で、後続の遅延操作は正しい手順へ届く", async () => {
        // 変換中に 手順 A の削除を確定 (遅延) → A の急所 1 の削除 (遅延)
        //   → B の「急所を追加」(遅延) → compositionEnd
        // → A は消え、B の既存 2 件は残ったまま**追加されて 3 件**になる
        //   (壊れた実装 (b3): 親解決の失敗をガードしないと steps[-1] = undefined から
        //    TypeError になり drain が中断する → B は 2 件のまま = 追加操作が失われる)
    });
});
```

各ケースの検証手段 (既存ヘルパと同じ流儀):

- 手順の並び: `stepScenes()` (既存。`step-{i}-scene` の value 配列)
- 急所の並び / 件数: `screen.getByTestId("point-{i}-{j}-scene")` の value と
  `screen.queryByTestId(...)` の不在
- 履歴: `undoBtn()` の `disabled` 状態と、`click` 後の DOM 復元
- 例外で drain が止まっていないこと: 3 つ目の操作の結果が DOM に出ていること
  (`fireEvent.compositionEnd` が reject しないことも同時に担保される)

### PHPStan 適合チェック

- [x] 該当なし。TypeScript は `pnpm typecheck` (テストも `tsconfig.json` の `include` 対象)
- [x] **素の型アサーション (`as`) を新規に増やさない**。DOM 検証は
  `toHaveValue` / `toBeInTheDocument` / `not.toBeInTheDocument` で書く
  (T185 impl-review Round 1 で指摘された「DOM assertion の型アサーション」を再発させない)

### テスト計画 (上位)

- [x] バグ修正なので**再現テストを先に書く** (施策 4 → 施策 1-3 の順で実装し、
  先に赤を確認してから直す。AGENTS.md 思考原則 5「テストファースト」)
- [x] 既存テスト `tests/js/components/features/manual/ScenarioEditor.test.ts` は
  既存 79 ケースすべてが**無変更で緑**であること
- [x] 新規テスト 8 件 (上記)
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 — **該当なし** (JS テスト)
- 検証コマンド: `pnpm test` / `pnpm typecheck` / `pnpm lint` / `pnpm build`。
  PHP 側は無変更の確認として `composer test` / `composer phpstan` も通す

### リスク

- **テストが実装を写しただけになる危険**。これを潰すのが施策 5 (負のコントロール) である。
- `compositionStart` / `compositionEnd` の発火要素: 既存テストは `section` に委譲した
  ハンドラを使うため、`screen.getByTestId("step-0-scene")` に対して発火してもバブリングで届く
  (T185 のテストが実証済み)。同じ形にする。

---

## 施策 5: 負のコントロールの実測と記録

### 変更箇所

- ファイル: `devnotes/20260816-1754-ime-deferred-structural-ops-stable-key/negative-control.md` (新規)

### 手順

**5 種類の負の実装をそれぞれ独立に実測する** (design-review R1/R2 Warning への対応)。
記号は施策 4 の分類表と 1:1 で対応させる。

各変種は「1 つだけ壊して測り、必ず戻してから次へ進む」。まとめて壊すと
どのケースが何を検出したのか分からなくなる。

1. 施策 1-3・6 を実装し、施策 4 の 8 ケースと施策 6 の 2 ケースが**緑**であることを確認する
2. **変種 (a): 安定キー解決を外す** — 3 経路を数値 index を捕捉する現行実装へ戻す
   (markup 側の引数も戻す)。
   `pnpm test tests/js/components/features/manual/ScenarioEditor.test.ts` を実行し、
   **どのケースがどう落ちるか** (失敗メッセージ・期待値と実測値・`TypeError` の有無) を記録 → 戻す
3. **変種 (b1): `removeStep` の `at < 0` 早期 return を落とす** — 安定キー解決は残す。
   期待する観測: ケース 6 が赤。**末尾の手順が消える** (`splice(-1, 1)` の機序)。記録 → 戻す
4. **変種 (b2): `removePoint` の子側 (`at < 0`) 早期 return を落とす**。
   期待する観測: ケース 7 が赤。**末尾の急所が消える**。記録 → 戻す
5. **変種 (b3): `removePoint` の親側 (`stepAt < 0`) 早期 return を落とす**。
   期待する観測: ケース 8 が赤。**`TypeError` または後続操作の未実行**
   (B の急所が 3 件にならない)。どちらとして現れたかを記録 → 戻す
6. **変種 (d): 施策 6 の目録テストの検出力** — `ScenarioEditor.svelte` へ
   `runSettled` を呼ぶ未登録の呼び出し元を足し、
   `tests/js/architecture/scenario-editor-deferred-ops-inventory.test.ts` が赤くなるか測る。
   **2 通りの形をそれぞれ試す** (design-review R2 の指示):
   - (d1) 名前付き `function` からの呼び出し
   - (d2) arrow function (`const foo = (): void => { runSettled(...) }`) からの呼び出し
   どちらかが検出されなければ、それは**保証しないものの実測済みの証拠**として記録する
   (テストを無理に強くしない)。記録 → 戻す
7. すべて戻したうえで全レーンが緑であることを再確認する
8. 2-6 の出力 (抜粋) と、**8 ケース × 変種の対応表**を `negative-control.md` に残す

### 記録に必ず含めるもの

- 実行コマンドと実行日時
- 変種ごとの、落ちたケース名の一覧と失敗メッセージ (抜粋)
- **新規 8 件がそれぞれどの変種で落ちたかの対応表**。
  **どの変種でも落ちないケースがあれば、そのケースは何も検出していない** =
  テストとして弱いので設計を見直す (削除するのではなく、検出できる形へ書き直す)
- 変種 (a) で `addPoint` の範囲外アクセスが `TypeError` として観測されたことの証跡
  (「静かな取り違え」と「操作の消失」の 2 種類の壊れ方を実測で区別する)
- 変種 (b1)/(b2) で**末尾の行が消えた**ことの証跡 (`splice(-1, 1)` の機序の実証)
- 変種 (b3) が `TypeError` として現れたか、後続操作の未実行として現れたかの実測
- 変種 (d1)/(d2) の検出結果。**(d2) が検出されなかった場合はそれを明記する**
  (施策 6 の「保証しないもの」の実測済みの裏付けになる)
- 既存 79 ケースが負のコントロール中も緑のままであること
  (= 新規ケースだけが欠陥を検出しており、既存テストは元々この欠陥に沈黙していた事実)

### リスク

- **負のコントロールの戻し忘れ**。各変種の直後に必ず戻し、
  最終的なコミット差分に一時的な巻き戻しが残っていないことを `git diff` で確認する
  (この確認は実装エージェントの責務)。

---

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

    if (CALL.test(line) && current !== null && current !== "runSettled") {
      sites.push({ line: index + 1, caller: current, fromNamedFunction: lastOpenerWasNamed });
    }

    // 呼び出し判定の**後**に arrow 定義を見る
    // (`runSettled(() => {` は同じ行で arrow を開くが、引数の中の arrow は
    //  ARROW_DEFINITION に一致しないので呼び出し自身の判定に影響しない)
    if (ARROW_DEFINITION.test(line)) {
      lastOpenerWasNamed = false; // 文レベルの arrow 定義が挟まったら帰属を信用しない
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

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更は `ScenarioEditor.svelte` + そのコンポーネントテスト + 新規の目録テスト 1 本の計 3 ファイルに閉じており、他タスクと共有する層 (DTO / route / DS token / 共通 lib) に一切触れない。一方で **T182〜T186 と同じファイル群を触る後続タスクが並行しうる**ため、独立ブランチで完結させて衝突面を最小化する。施策 1-6 は 1 つの不変条件を共有しており、分割すると中間状態で不変条件が半分しか成立しない期間ができるので、1 タスクとして一括で入れる。 |
| 競合リスク | `ScenarioEditor.svelte` を触る他タスクがあれば行単位の衝突が起きる。現時点で main にマージ済みの T182-T186 以降、同ファイルへの未マージ変更は把握していない。実装前に main の最新を取り込むこと。 |

## 全体のリスクと後退可能性

| リスク | 影響 | 緩和 |
|--------|------|------|
| 通常操作 (非 IME) の挙動が変わる | 高 | 解決に成功した場合の変異は現行と同一。既存 79 ケースが無変更で緑であることを完了条件にする |
| undo/redo 履歴の整合が崩れる | 高 | すべての変異を `commitStructural` へ合流 (T185 の設計を踏襲)。解決失敗時は `commitStructural` を呼ばないので空エントリが積まれない。施策 4 の 6・7 件目が「誤変異に伴う余分な履歴エントリ」を Undo 回数で検証し、8 件目が「no-op の後も drain が続くこと」を検証する |
| dirty 判定が壊れる | 中 | `serializeSteps` / `snapshot` / `payloadSteps` を変更しない。`clientKey` は元から `serializeSteps` に含まれ `payloadSteps` に含まれない (この非対称を維持) |
| PUT payload に `clientKey` が混入する | 高 (サーバ保護キー) | `payloadSteps()` に手を触れない。既存テスト「PUT payload に clientKey を含めない」(L915) が守る |
| テストが実装の写しになる | 中 | 施策 5 の負のコントロールで検出力を実測する |
| 解決失敗時の no-op が利用者に「無反応」と映る | 低 | 発生条件は「変換中に、同じ対象を消す操作と別の操作を続けて要求した」ときのみ。その時点で画面上その行は既に消えており、観測される最終状態は利用者の意図と一致する。告知を足すと成功時に告知しない現行 UI と粒度が不揃いになるため足さない |

## 完了条件

- [ ] 施策 1-3 の実装 (`ScenarioEditor.svelte` から数値 index を捕捉する遅延 closure が 0 件になる)
- [ ] 施策 4 の新規テスト 8 件が緑
- [ ] 施策 6 の目録テスト 2 件が緑 (目録の 8 エントリが実コードと完全一致)
- [ ] 既存テスト (JS 全レーン / PHP 全レーン) が緑
- [ ] `pnpm typecheck` / `pnpm lint` / `pnpm build` が緑
- [ ] 施策 5 の `negative-control.md` が実測ログ付きで存在し、新規 8 件すべてが
      安定キー解決の欠如を検出したことが示されている
- [ ] `confirmingStepIndex` という識別子がコードベースに残っていない (旧実装を並走させない)
- [ ] 負のコントロールの一時変更が最終差分に残っていない (`git diff` で確認)

