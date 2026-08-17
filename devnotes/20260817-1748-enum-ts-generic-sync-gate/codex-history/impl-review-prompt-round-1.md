## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 実装レビュー

あなたは Laravel + Svelte 5 + Inertia のリポジトリのコードレビュアーである。
本変更は **テスト基盤 (静的 gate) の作り直し**であり、アプリの実行時コードは docblock 以外変えていない。

## レビュー観点

1. **設計との一致性**: 添付の詳細設計 (施策 A〜F) と実装が一致しているか。設計が明示的に
   「やらない」と決めたこと (起点を縮める / 重複を検出すると主張する / 意味の診断を見る) を
   実装が破っていないか
2. **正確性**: PHP の字句走査 (php-enums.ts) が **取り残しを緑にする** 経路を持たないか。
   とくに引用符の逆斜線の偶奇・行注釈の中の閉じタグ・属性 `#[` と行注釈 `#` の区別・
   波括弧の深さの数え方・CRLF と補助面文字での位置ずれ
3. **偽グリーン (degenerate PASS) の不在**: 空集合・未初期化・例外の握り潰しで
   検査が空振りする経路が無いか。負例行列 (TS 27 件 / PHP 38 件) に穴が無いか
4. **保証範囲の誇張**: docblock / AGENTS.md / docs の記述が、実装が実際に保証することを
   超えていないか (逆に、保証しているのに書いていないものがあるか)
5. **PHPStan / 型安全**: TypeScript 側で `any` / 非 null 断言 / 例外の型の握り潰しが無いか
6. **テスト網羅性**: 旧テスト 18 件 (値集合の比較 14 + 抽出不能の自己検査 4) の保証が
   新 gate と負例行列へ漏れなく移設できているか
7. **セキュリティ**: 本変更はテスト基盤だが、目録のパス検査 (symlink / `..` / 兄弟ディレクトリ) に
   抜けが無いか

## 出力形式

ファイルごとに判定を書き、指摘は **[Critical] / [Warning] / [Suggestion]** に分類する。
最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で 1 行書くこと。

---

## 詳細設計書

# 詳細設計: PHP 列挙 ⇔ TypeScript 値域の汎用同期 gate (裁定 AG-099 追従)

> Codex 詳細設計レビュー Round 1 / 2 / 3 の指摘をすべて反映した最終版。
> 対応の判断は `codex-history/design-review-decisions-round-{1,2,3}.md`、
> レビュー本文は `detailed-review-round-{1,2,3}.md`。

## 使命・制約(絶対遵守)

### アプリの使命(North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

### 禁止事項(AGENTS.md より。本設計に効くもの)

1. テストなしの実装完了報告 / 2. PHPStan エラーの widen・baseline 化 /
3. dev DB への破壊操作 / 4. `response()->json()` の直書き /
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き /
7. 操作系 POST の `redirect()->intended()` / 8. 必須条件未充足での disabled ボタン /
9. **Artifact の使用**(成果物はリポジトリ内のファイルとして出力する)

加えて思考原則 2 (**今必要なものだけ作る**) と 3 (**後方互換の並走を残さない**) が
本設計の中心的な判断根拠である。

### コーディングルール

- **PHPStan level 10** 必須(`composer phpstan`)/ **Pest**(`composer test`)
- **RefreshDatabase** はグローバル適用(個別 `DatabaseTransactions` 禁止)
- `declare(strict_types=1)` + 日本語コメント
- フロント: TypeScript strict / vitest。`pnpm lint` は `resources/js` のみを見る
- 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

## 概念設計リファレンス

`devnotes/20260817-1748-enum-ts-generic-sync-gate/conceptual-design.md`(Codex Round 2 で APPROVED)
実測は同ディレクトリの `survey.md` / `survey-raw.txt` / `survey.py`。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | 型情報で読む抽出基盤 | `tests/js/support/enum-ts-sync/{errors,program,ts-value-sets,php-enums}.ts` (新規) | 高 |
| B | 汎用 gate 本体 (目録 + 突き合わせ) | `tests/js/architecture/enum-ts-sync.test.ts` (新規) | 高 |
| C | 抽出器の自己検査 (負例行列) | `tests/js/architecture/enum-ts-sync-extractor.test.ts` + `tests/js/support/enum-ts-sync/fixtures/*.ts` (新規) / `tsconfig.json` | 高 |
| D | 旧実装の撤去と参照の是正 | `tests/Support/TsUnionValues.php` + PHP テスト 4 本 (削除) / `tests/Architecture/TicketLedgerReaderInventoryTest.php` / `app/Enums/**` の docblock 8 件 / `resources/js/types/*.ts` の docblock 4 件 / `docs/architecture.md` 2 箇所 | 高 |
| E | 母集団の拡張 (14 組 → 27 組) | `tests/js/architecture/enum-ts-sync.test.ts` の目録 | 中 |
| F | 規約・文書・テンプレート差分の登録 | `AGENTS.md` / `docs/architecture.md` / `docs/template-divergence.md` (D27) / `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` の件数 | 中 |

施策 A〜E は**同一コミットで着地させる**(旧実装を残したまま新 gate を足すと並走になる。
思考原則 3)。F は同じ PR 内で続けて行う。

---

## 施策 A: 型情報で読む抽出基盤

### 変更箇所

新規 4 ファイル。すべて `tests/js/support/enum-ts-sync/` 配下。

### 波及変更

- TypeScript 型定義: 新規のみ / API Resource・DTO: なし / テストファイル: 施策 B・C が利用者

### A-1 `errors.ts` — 失敗の型

```ts
/**
 * 抽出に失敗したことを表す例外。
 * **空集合を返して失敗を表さない**(空 vs 空が一致して素通りするため)。
 * 文面には必ず「対象の場所」と「落ちた理由」を入れる。
 */
export class EnumTsSyncError extends Error {
    constructor(where: string, reason: string) {
        super(`${where}: ${reason}`);
        this.name = "EnumTsSyncError";
    }
}
```

### A-2 `program.ts` — 型情報の入口

**本番の gate は `tsconfig.json` が含む TS ファイル全体で program を作る**。
目録のファイルだけを起点にすると、`include` だけで参加する宣言
(周囲宣言 `.d.ts` / `declare global` / モジュールの拡張) が program に載らず、
**本番の型と違う型世界**で判定してしまう。本リポジトリには実際に
`resources/js/lib/recaptcha.ts` の `declare global` があり、この経路は絵空事ではない。
偽陰性 (取り残しを緑にする) になるので、速さのために縮める判断はしない。

```ts
import ts from "typescript";
import fs from "node:fs";
import path from "node:path";
import { EnumTsSyncError } from "./errors";

/** リポジトリのルート (tests/js/support/enum-ts-sync から 4 つ上)。 */
export const REPO_ROOT = path.resolve(__dirname, "../../../..");

export interface MirrorProgram {
    readonly program: ts.Program;
    readonly checker: ts.TypeChecker;
}

/** tsconfig.json を読む。回復可能な診断も含めて 1 件でもあれば例外にする。 */
const parseRepoTsconfig = (): ts.ParsedCommandLine => {
    const configPath = path.join(REPO_ROOT, "tsconfig.json");
    const parsed = ts.getParsedCommandLineOfConfigFile(configPath, {}, {
        ...ts.sys,
        onUnRecoverableConfigFileDiagnostic: (d) => {
            throw new EnumTsSyncError("tsconfig.json", ts.flattenDiagnosticMessageText(d.messageText, " "));
        },
    } as ts.ParseConfigFileHost);
    if (parsed === undefined) throw new EnumTsSyncError("tsconfig.json", "読み込みに失敗しました");
    if (parsed.errors.length > 0) {
        throw new EnumTsSyncError("tsconfig.json", ts.formatDiagnostics(parsed.errors, formatHost));
    }
    return parsed;
};

/**
 * 目録が指す TS ファイルを含む program を作る。
 * 起点は **tsconfig が含む全ファイル ∪ 目録のファイル**。
 */
export const createMirrorProgram = (tsFiles: readonly string[]): MirrorProgram => {
    const parsed = parseRepoTsconfig();
    const inventoryRoots = tsFiles.map((f) => {
        const abs = path.join(REPO_ROOT, f);
        if (!fs.existsSync(abs)) throw new EnumTsSyncError(f, "目録が指す TS ファイルが実在しません");
        return abs;
    });
    const program = ts.createProgram({
        rootNames: [...new Set([...parsed.fileNames, ...inventoryRoots])],
        options: { ...parsed.options, noEmit: true },
        projectReferences: parsed.projectReferences,
        configFileParsingDiagnostics: parsed.errors,
    });
    const optionsDiagnostics = program.getOptionsDiagnostics();
    if (optionsDiagnostics.length > 0) {
        throw new EnumTsSyncError("tsconfig.json", ts.formatDiagnostics(optionsDiagnostics, formatHost));
    }
    return { program, checker: program.getTypeChecker() };
};

/**
 * 見本 (fixture) 専用の縮めた program。**本番の gate では使わない**。
 * 見本は自己完結しているので周囲宣言の影響を受けない。
 */
export const createFixtureProgram = (absoluteFiles: readonly string[]): MirrorProgram => { /* rootNames を明示 */ };
```

- **構文の診断だけは見る**。目録が指す TS ファイルについて `getSyntacticDiagnostics` が
  空でなければ例外にする(構文が壊れていると型解決が黙って縮む)。
  **意味の診断 (`getSemanticDiagnostics`) は見ない** — 型検査は `pnpm typecheck` の担当であり、
  同じことを 2 箇所で見ない。この分担は「保証しないもの」に明記する。
- **速さは実測してから語る**。実装時に program 構築の所要時間を測り、実装報告に数値で残す。
  遅かったとしても**起点を縮めて偽陰性を作る解決はしない**(縮めるなら、縮めた program と
  全体 program で 27 組の結果が一致することを検査する形にする)。

### A-3 `ts-value-sets.ts` — TS 側の値集合

**受理する形**(**解決・正規化された後の型**についての条件である):

1. 対象ファイルのトップレベルに、その名前の**型別名の宣言**が**ちょうど 1 つ**あること。
2. その宣言が解決する型が、**文字列リテラル型だけ**の union か、単独の文字列リテラル型であること。
3. `ts.TypeFlags.EnumLiteral` を持つ構成要素は**受理しない**。
   TypeScript の `enum` は本リポジトリに 1 件も無く(`isolatedModules` 前提)、
   文字列リテラル型と同じ契約ではないため、**必要になってから明示的に広げる**。

```ts
export const readTsUnionValues = (
    { program, checker }: MirrorProgram,
    tsFile: string,
    declaration: string,
): ReadonlySet<string> => {
    const where = `${tsFile}::${declaration}`;
    const source = program.getSourceFile(path.join(REPO_ROOT, tsFile));
    if (source === undefined) throw new EnumTsSyncError(where, "TS ファイルが program に載っていません");
    if (program.getSyntacticDiagnostics(source).length > 0) {
        throw new EnumTsSyncError(where, "TS ファイルの構文が壊れています");
    }

    const aliases = source.statements
        .filter(ts.isTypeAliasDeclaration)
        .filter((s) => s.name.text === declaration);
    if (aliases.length === 0) {
        throw new EnumTsSyncError(where, "型別名の宣言が見つかりません (受理するのは `type X = …` だけ。定数配列・switch の case ラベル・.svelte 内の宣言は読みません)");
    }
    if (aliases.length > 1) throw new EnumTsSyncError(where, `同名の型別名が ${aliases.length} 件あります`);

    const symbol = checker.getSymbolAtLocation(aliases[0].name);
    if (symbol === undefined) throw new EnumTsSyncError(where, "宣言の記号を解決できません");
    const declared = checker.getDeclaredTypeOfSymbol(symbol);
    const parts = declared.isUnion() ? declared.types : [declared];

    const values = new Set<string>();
    for (const part of parts) {
        if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) {
            throw new EnumTsSyncError(where, `TypeScript の enum の値は受理しません: ${checker.typeToString(part)}`);
        }
        if (!part.isStringLiteral()) {
            throw new EnumTsSyncError(where, `文字列リテラル型でない構成要素があります: ${checker.typeToString(part)}`);
        }
        values.add(part.value);
    }
    if (values.size === 0) throw new EnumTsSyncError(where, "値を 1 つも取り出せません");
    return values;
};
```

**受理範囲の明示**(解決後の型で判断するので、次のようになる):

| 書き方 | 結果 | 理由 |
|---|---|---|
| `"a" \| "b"` / `"only"` | 受理 | そのまま |
| 別名参照 (`Y \| "c"`) / import 越しの別名 | 受理 | 解決される |
| `keyof typeof O` / `typeof O[keyof typeof O]` | 受理 | 文字列リテラルの union に評価される |
| `Lowercase<"A" \| "B">` | 受理 | `"a" \| "b"` に評価される |
| 具体化された分配条件型 | 受理 | 有限のリテラル union に評価される |
| 有限のテンプレートリテラル型 (`` `a${"1"\|"2"}` ``) | 受理 | リテラル union に展開される |
| 開いたテンプレートリテラル型 (`` `x${string}` ``) | 例外 | 文字列リテラル型でない |
| 未具体化の generic 条件型 | 例外 | 条件型のまま |
| `string` / `string & {}` / `never` / `unique symbol` / 数値 | 例外 | 文字列リテラル型でない |
| TypeScript の `enum` の値 | 例外 | 上の 3 |

**重複の検査は置かない**。`"a" | "a"` は型検査器が `"a"` へ正規化するため、
値集合の側からは元の重複を観測できない。**「同じ値が 2 回あると落ちる」とは主張しない**
(旧設計の記述は誤りだったので削る)。同様に union の中の `never` も正規化で消える。

これで概念設計の問題 1 が閉じる:

| 旧 (正規表現) の穴 | 新 (型情報) の挙動 |
|---|---|
| 注釈の中の引用符を値として拾う | 注釈は型に現れないので混ざらない |
| `"a" \| "b" \| (string & {})` を閉じた union と誤認 | 交差型の構成要素で例外 |
| 別名参照 (`ConsoleRole \| "owner"`) を読めない | 解決して全値を得る |
| 宣言本体を最初の `;` で切って壊れる | 構文木で宣言を取るので起きない |

### A-4 `php-enums.ts` — PHP 側の値集合

入口を 2 つに分ける(見本をファイルで持たずに済ませるため)。

```ts
/** 本体。`fileName` は語幹の照合と例外の文面にだけ使う。 */
export const readPhpEnumValuesFromText = (source: string, fileName: string): ReadonlySet<string> => { … };

/** リポジトリ相対のパスから読む薄い包み。 */
export const readPhpEnumValues = (phpFile: string): ReadonlySet<string> => { … };
```

#### 字句の状態(明文化する)

走査は次の状態を持つ。**PHP のキーワードは大文字小文字を区別しない**ので、
`enum` / `case` / `string` の照合は ASCII の大小を無視して行う(`CASE B = 'b';` を
見落とすと**取り残しを緑にする**ため、ここは Critical である)。

| 状態 | 入口 | 出口 | 扱い |
|---|---|---|---|
| コード | 既定 | — | 波括弧の深さを数える |
| 単一引用符の文字列 | `'` | `'`(**直前の連続する `\` が偶数個**のとき) | 中身は深さの計算から除く |
| 二重引用符の文字列 | `"` | `"`(同上) | 同上 |
| 行注釈 | `//` または `#`(**`#[` は除く**) | 改行 **または `?>`** | 同上 |
| ブロック注釈 | `/*` | `*/` | 同上 |

**引用符の終端は逆斜線の偶奇で決まる**。「直前が `\` でない」では不十分で、
`'…\\'` の終端を文字列の中と誤認して**以降の case を丸ごと文字列の中として食い潰す**
(取り残しを緑にしうる)。直前に連続する `\` を数え、**偶数なら終端・奇数なら文字列の中**とする。

**次を見つけたら例外**(受理範囲外であることを理由に含める):

- コード状態の `` ` ``(バッククォート文字列)/ `__halt_compiler`(大小無視)/
  `<<<`(ヒアドキュメント・ナウドキュメント)
- **コード状態と行注釈状態の `?>`**。PHP は**行注釈の中の `?>` でも PHP モードを抜ける**ので、
  `// ?>` / `# ?>` を見逃すと以降を PHP として読んでしまう。
  引用符の中とブロック注釈の中の `?>` は**無視する**(PHP モードを抜けないため)

**走査が終わった時点で、単一引用符・二重引用符・ブロック注釈のいずれかが閉じていなければ例外**。
行注釈は EOF で閉じたものとして扱う(PHP の挙動と同じ)。

無害化した写しは**元の文字列と同じ長さ(UTF-16 の符号単位数)を必ず保つ**。
実装は `for...of`(符号位置単位)を使わず、`charCodeAt` と添字で回す
(絵文字などの補助面文字が入ると位置がずれ、`slice` の対応が壊れるため)。
改行は `\r` も `\n` もそのまま残す(CRLF で位置がずれない)。

#### 受理する文法

1. コード状態の深さ 0 に `enum <名前>` の宣言が**ちょうど 1 つ**あり、
   その backing 型が `string` であること(0 件・2 件以上・`int`・backing 無しは例外)。
   `implements Foo, Bar` が続いてもよい。
2. `<名前>` が **ファイル名の語幹と一致**すること(PSR-4 の前提の裏取り)。
   語幹は「末尾の `.php` をちょうど 1 つ剥がしたもの」と定義する
   (未知の拡張子は例外にする)。
3. その本体の**深さ 1** に現れる `case` から `;` までを 1 件の宣言として取り出し、
   **元の本文**の同じ範囲を次のどちらかに一致させる(改行を含む範囲は例外)。

```ts
const SINGLE = /^case[ \t]+[A-Za-z_]\w*[ \t]*=[ \t]*'([^'\\]*)'[ \t]*;$/i;
const DOUBLE = /^case[ \t]+[A-Za-z_]\w*[ \t]*=[ \t]*"([^"\\$]*)"[ \t]*;$/i;
```

4. 深さ 1 の `case` で 3 に合わないものは**例外**(定数式・逆斜線・変数の埋め込み・複数行)。
5. case が 0 件なら例外。
6. **case の名前が重複、または backing の値が重複していたら例外**。
   旧テストは配列同士を比べていたため値の重複を検出できた。集合にすると消えるので、
   **抽出器の側で明示的に落として保証を引き継ぐ**。

`match` 式・匿名クラス・`const` 宣言・メソッド本体は深さが 2 以上になるので自然に対象外である
(`App\Enums\MemberRoleState` が `match (true)` を持つ実例)。

#### 保証しないもの(誇張しない)

> 保証するのは「**enum 本体の直下で見つけた case は、限定した文法に一致するか例外になる**」ことまでである。
> PHP ファイル全体の構文の妥当性・名前空間・オートロード・完全修飾名の正しさ・
> メソッド本体の妥当性は**検証しない**(それらは `composer test` / PHPStan の担当)。
> また PHP が受理する構文をすべて受理するわけではない(閉じタグやバッククォートは拒否する)。

### PHPStan 適合チェック

本施策に PHP の変更は無い(すべて TypeScript)。TS 側は次を守る:

- [x] 公開関数の戻り値は `ReadonlySet<string>`。**`null` / 空配列で失敗を表さない**
- [x] 失敗は `EnumTsSyncError`(場所 + 理由)を投げる
- [x] `any` を使わない(`ts.ParseConfigFileHost` への `as` は host 合成の 1 箇所のみ)
- [x] 目録は `as const satisfies readonly EnumTsMirror[]`

### リスク

- 全体 program の構築時間。実測して報告に残す。**速やかさのために偽陰性を作らない**。
- PHP の字句解析は本 gate 専用の最小実装である。主張はファイル全体にはかけず、次に限る:
  > **抽出の対象として認識した enum 宣言・case 宣言・禁止した字句状態については、
  > 受理するか、理由付きの例外になる。**

---

## 施策 B: 汎用 gate 本体

### 変更箇所

`tests/js/architecture/enum-ts-sync.test.ts`(新規)。

### 目録の行

```ts
interface EnumTsMirror {
    /** リポジトリルートからの PHP 列挙ファイルの相対パス (`app/` 配下の `*.php`)。 */
    readonly php: string;
    /** リポジトリルートからの TS ファイルの相対パス (`resources/js/` 配下の `*.ts`)。 */
    readonly ts: string;
    /** TS 側の型別名の名前。 */
    readonly declaration: string;
    /** この写しが要る理由 (画面のどこが値で分岐するか)。 */
    readonly note: string;
}

const ENUM_TS_MIRRORS = [ /* 27 行 */ ] as const satisfies readonly EnumTsMirror[];
```

`note` に 30 文字以上の長さの検査は**課さない**。本目録は**免除の申告ではなく登録**であり、
判断の重さが違うため(免除の目録が 30 文字を課すのは「検査から外す」判断だから)。

### 検査

```ts
/**
 * 目録の件数の pin。増えても減っても赤くする (写しが黙って消えるのを防ぐ)。
 * **これは網羅の証明ではない** — 登録していない写しは 1 件も検査していない。
 */
const EXPECTED_MIRROR_COUNT = 27;

let mirrorProgram: MirrorProgram | undefined;

/** 初期化されていなければ落ちる (definite assignment の `!` を使わない)。 */
const requireMirrorProgram = (): MirrorProgram => {
    if (mirrorProgram === undefined) throw new EnumTsSyncError("mirror program", "初期化されていません");
    return mirrorProgram;
};

describe("PHP 列挙 ⇔ TS 値域の同期", () => {
    beforeAll(() => {
        // **パスの検査を program の構築より先に**行う。
        // 後回しにすると、検査の外にあるファイルを「赤くなる前に読んでしまう」ことになる。
        validateMirrors(ENUM_TS_MIRRORS);
        mirrorProgram = createMirrorProgram([...new Set(ENUM_TS_MIRRORS.map((m) => m.ts))]);
    }, 180_000);

    it("目録の件数が pin と一致する", () => {
        expect(ENUM_TS_MIRRORS).toHaveLength(EXPECTED_MIRROR_COUNT);
    });

    // 体裁の検査は同じ純関数を呼ぶ (実装を 2 つに分けない)。
    it("目録の行の体裁が守られている", () => {
        expect(() => validateMirrors(ENUM_TS_MIRRORS)).not.toThrow();
    });

    it.each(ENUM_TS_MIRRORS)("$php ⇔ $ts::$declaration の値集合が一致する", (mirror) => {
        const phpValues = readPhpEnumValues(mirror.php);
        const tsValues = readTsUnionValues(requireMirrorProgram(), mirror.ts, mirror.declaration);
        expect([...tsValues].sort()).toEqual([...phpValues].sort());
    });
});
```

**`validateMirrors(rows)`**(純関数。**program を作る前**に呼び、体裁のテストからも同じものを呼ぶ):

- 絶対パス・逆斜線・`.` / `..` の区間を含むパスを拒否する
- `path.resolve` した後に、`php` は `app/` 配下、`ts` は `resources/js/` 配下にあること。
  包含の判定は `startsWith(root)` ではなく **`startsWith(root + path.sep)`** で行う
  (`app-legacy/` のような兄弟ディレクトリを通さない)
- **`fs.realpathSync` の結果でも同じ包含を確かめる**(symlink 経由の抜けを塞ぐ)
- 通常ファイルであること (`fs.statSync(...).isFile()`)
- `php` は `.php`、`ts` は `.ts` で終わること
- `ts` + `declaration` の組が重複しないこと。**`realpathSync(ts)` + `declaration` でも重複しないこと**
  (symlink で別名にした同じファイルを 2 回登録できないようにする)
- `note` が空でないこと

### テスト計画

- [ ] **fail-first の実測は代表 3 組**に絞る(`VideoManualStatus` / `MemberRoleState` /
      `Billing\PlanCode`)。TS 側から 1 値落とす・PHP 側へ 1 case 足す、の両方向で赤くなることを確かめ、
      実装報告に残す。27 組すべてを手で変異させるのは、**同じ比較器を 27 回通すだけで
      持続的な保証にならない**ため行わない(Codex Round 1 の指摘)
- [ ] 件数 pin を 1 ずらすと赤くなる
- [ ] `pnpm test` が全件緑

### リスク

- 27 組それぞれが 1 テストになるので vitest の出力が増える。これは母集団が見えるようになった
  ということであり、増殖ではない(**足すのは目録の行 1 つ**)。

---

## 施策 C: 抽出器の自己検査(負例行列)

### 変更箇所

- `tests/js/architecture/enum-ts-sync-extractor.test.ts`(新規)
- `tests/js/support/enum-ts-sync/fixtures/*.ts`(新規。**壊れた見本を含む TS の見本**)
- `tests/js/support/enum-ts-sync/program-fixtures/*.ts`(新規。**正常な TS だけ**。
  `tsconfig.json` の対象に**残す**。T25 が使う)
- `tsconfig.json` の `exclude` に `tests/js/support/enum-ts-sync/fixtures/**` を追加
  (**`program-fixtures/` は除外しない**)

### 見本の置き方(Round 1 から変更)

- **PHP の見本はテスト内の文字列で書く**(`readPhpEnumValuesFromText` を直接呼ぶ)。
  Round 1 案の `.php.txt` は「`.php` にすると 4 つの PHP 側検査
  (`StrictTypesDeclarationGateTest` / `ForbiddenStatementTokenInvariantTest` / Pint / PHPStan)の
  母集団に入る」問題を避けるためのものだったが、**文字列で書けばその問題自体が消える**。
  語幹の照合に使うファイル名は引数で渡す。ファイルから読む包み
  (`readPhpEnumValues`) は、**実在する `app/Enums/**` の 2 本**
  (`MemberRoleState` = `match` 式を持つ実例 / `Manual/RenderKind` = 素直な例) を
  読ませて確かめる(見本を増やさずに経路を通す)。
- **TS の見本はファイルで置く**。型検査器で解決させるには実ファイルが要るため。
  `tsconfig.json` の `exclude` へ足して `pnpm typecheck` の対象から外す
  (わざと壊した見本があるため。aigenba の申し送りと同じ手当)。
  抽出器の検査は `createFixtureProgram`(**リポジトリの `compilerOptions` はそのまま使い、
  起点だけを明示する**)で行う。`paths`(`@/*`)が効くのはこのためである。

#### T25 (縮めた program への回帰) の置き方

見本を全部 `exclude` に入れると、**増える側の宣言も `parsed.fileNames` に入らない**ので
「全体 program だから値が増える」という差が出ず、回帰にならない(Round 2 の指摘)。
そこで**増える側だけを `tsconfig` の対象に残す**。

```text
tests/js/support/enum-ts-sync/
  program-fixtures/            # tsconfig の対象に残す (正常な TS のみ)
    registry-base.ts           #   export interface Registry { a: "a" }
    registry-augmentation.ts   #   declare module "./registry-base" { interface Registry { b: "b" } }
  fixtures/                    # tsconfig から除外 (壊れた見本を含む)
    t25-target.ts              #   registry-base だけを import し、Registry[keyof Registry] を読む
```

- `t25-target.ts` は **`registry-augmentation.ts` を import しない**。
- 全体 program(`createMirrorProgram`)では拡張が `parsed.fileNames` 経由で載るので `{a,b}`。
- 起点だけの program(`createFixtureProgram`)では拡張が載らないので `{a}`。
  **この 2 つを両方 pin する**ことで、起点を縮める改変が入ったら赤くなる。

#### T10 (別名参照) の分け方

`@/*` は `resources/js/*` を指すので、見本どうしの参照には効かない。2 つに分ける。

- **T10a**: 見本どうしを**相対 import** で参照する(別ファイルの型別名が解決されること)。
- **T10b**: **実在する `resources/js` の型**を `@/` 経由で import した見本
  (例: `import type { DashboardRole } from "@/types/dashboard";` を union に混ぜる)。
  リポジトリ本来の `paths` 解決が効いていることを、見本専用の `paths` を作らずに固定する。

### 負例・正例の行列(TS 側)

| # | 見本 | 期待 |
|---|---|---|
| T1 | `type X = "a" \| "b";` | 受理 |
| T2 | `type X = "only";` | 受理 |
| T3 | 注釈の中に引用符付きの語がある union | 受理し、注釈の語は混ざらない |
| T4 | `"a" \| "b" \| (string & {})` | 例外 |
| T5 | `"a" \| 1` | 例外 |
| T6 | `never` | 例外 |
| T7 | 宣言が無い名前 | 例外 |
| T8 | 同名の型別名が 2 つ | 例外 |
| T9 | `export const X = ["a"] as const;` を宣言名で登録 | 例外 |
| T10a | 別名参照(見本どうしを**相対 import**) | 受理し `Y` の値も含む |
| T10b | 実在する `resources/js` の型を **`@/` 経由**で import した union | 受理し、実在の型の値も含む |
| T11 | `{ a: "p"; b: "q" }["a" \| "b"]`(本体に `;` を含む) | 受理 |
| T12 | `keyof typeof O` | 受理 |
| T13 | `typeof O[keyof typeof O]` | 受理 |
| T14 | `Lowercase<"A" \| "B">` | 受理 (`{a,b}`) |
| T15 | 具体化された分配条件型 | 受理 |
| T16 | 未具体化の generic 条件型 | 例外 |
| T17 | 有限のテンプレートリテラル型 | 受理 |
| T18 | 開いたテンプレートリテラル型 (`` `x${string}` ``) | 例外 |
| T19 | TypeScript の `enum`(文字列)の値の union | 例外 |
| T20 | TypeScript の `enum`(数値)の値の union | 例外 |
| T21 | `unique symbol` | 例外 |
| T22 | 循環する別名 | 例外 |
| T23 | 解決できない import 越しの参照 | 例外 |
| T24 | ソース上は重複した union (`"a" \| "a"`) | **受理し `{a}` になる**(正規化で消えることを固定する。重複は検出しないと明示) |
| T25a | モジュールの拡張で値が増える宣言 / **全体 program** | 受理し `{a,b}`(増えた値を含む) |
| T25b | 同じ見本 / **起点だけの program** | 受理するが `{a}`(縮めると値が減ることの対照。起点を縮める改変の回帰) |

### 負例・正例の行列(PHP 側。すべて文字列で書く)

| # | 見本 | 期待 |
|---|---|---|
| P1 | 素直な `enum X: string` に case 3 つ | 受理 |
| P2 | 注釈の中に `case Fake = 'x';` | 受理し混ざらない |
| P3 | メソッド本体の `switch` の `case` | 受理し混ざらない |
| P4 | `match` 式を持つ | 受理 |
| P5 | 匿名クラスを含むメソッド | 受理 |
| P6 | case の値や本文の文字列に波括弧を含む | 受理 |
| P7 | 属性 (`#[...]`) が付いた enum / case | 受理 |
| P8 | `# ` で始まる行注釈 | 受理し混ざらない |
| P9 | `enum X: string implements Foo, Bar` | 受理 |
| P10 | enum の `const` 宣言がある | 受理 |
| P11 | `CASE B = 'b';`(大文字) / `Case C = 'c';`(混在) | **受理し値に含む** |
| P12 | `case A = self::PREFIX.'a';` | 例外 |
| P13 | `case A = 'it\'s';`(逆斜線) | 例外。**復号して受理しない** |
| P14 | `case A = "pre{$x}";` | 例外 |
| P15 | 複数行にまたがる case | 例外 |
| P16 | `enum X: int` | 例外 |
| P17 | backing の無い `enum X` | 例外 |
| P18 | 1 ファイルに `enum` が 2 つ | 例外 |
| P19 | case が 0 件 | 例外 |
| P20 | コード状態の `<<<` | 例外 |
| P21 | 注釈・文字列の中の `<<<` | **受理**(誤認しない) |
| P22 | バッククォート文字列 | 例外 |
| P23 | コード状態の `?>` で閉じて HTML が続く | 例外 |
| P24 | **`// ?>` の後に HTML が続く** | 例外(行注釈の中でも PHP モードを抜けるため) |
| P25 | **`# ?>` の後に HTML が続く** | 例外 |
| P26 | ブロック注釈・文字列の中の `?>` | **受理**(PHP モードを抜けないため誤認しない) |
| P27 | `__halt_compiler()` | 例外 |
| P28 | 未終端の**単一引用符** | 例外 |
| P29 | 未終端の**二重引用符** | 例外 |
| P30 | 未終端の**ブロック注釈** | 例外 |
| P31 | **メソッド本体の文字列**に逆斜線が**奇数**個(`'…\''` で文字列が続く) | **受理**し、その後ろの `case A = 'a';` を含む |
| P32 | 同上・逆斜線が**偶数**個(`'…\\'` で文字列が終わる) | **受理**し、その後ろの case を含む |
| P33 | 同上・逆斜線が 3 個以上連続する | **受理**し、その後ろの case を含む |
| P34 | ファイル名の語幹と enum 名が食い違う | 例外 |
| P35 | case の名前が重複 | 例外 |
| P36 | backing の値が重複 (`case A = 'a'; case B = 'a';`) | 例外 |
| P37 | 補助面の文字(絵文字)を注釈に含む | 受理(位置がずれない) |
| P38 | CRLF 改行 | 受理 |

**P31〜P33 は逆斜線を case の値に置かない**(Round 3 の指摘)。値に置くと受理文法が
逆斜線を拒んでその case で例外になり、**偶奇の判定が正しかったかを戻り値から観測できない**
(別の理由の例外でもテストが緑になってしまう)。
そこで逆斜線を**メソッド本体の文字列**に置き、その**後ろに普通の case を置いて、
その case が抽出結果に含まれること**を確かめる。偶奇を間違えると走査が文字列の中へ
迷い込んでその case を食い潰すので、**戻り値の差として観測できる**。

```php
enum X: string
{
    public function example(): string
    {
        return 'ここに逆斜線を 1 個 / 2 個 / 3 個以上と変えて置く';
    }

    case A = 'a';   // ← これが抽出結果に入ることを固定する
}
```

case の値に逆斜線がある場合を拒むことは **P13** が担当する(役割を分ける)。
検査は例外クラスだけでなく**期待する値集合か、正確な失敗の理由**まで見る。

### 見本ファイルの集合の固定

- 行列の**実際の case 数**を pin する(概念上の行数ではなく、`it.each` に渡す要素数)。
  **TS 27 件**(T1-T9 の 9 + T10a/T10b の 2 + T11-T24 の 14 + T25a/T25b の 2)/
  **PHP 38 件**(P1-P38)。
- **行列の配列・件数の定数・見本の実在集合の 3 つを同じテストで突き合わせる**。
  `fixtures/` に実在する `.ts` の集合と、行列が参照する見本パスの集合を完全一致で比べ、
  孤児の見本も、行列にあって実在しない見本も、どちらも赤にする。
  T10a / T25 が使う補助ファイル(`program-fixtures/` の 2 本と、相対 import される見本)は
  「補助」として別の一覧に分けて宣言し、こちらも実在と完全一致で比べる。
- `program-fixtures/registry-augmentation.ts` は**外部モジュールとして成立させる**
  (`import "./registry-base";` か `export {};` を持たせる。持たないと `declare module` が
  大域宣言の側と解釈されて拡張にならない)。

### リスク

- 見本を 2 通りの置き方(TS はファイル / PHP は文字列)にするのは非対称である。
  **理由をテストファイルの冒頭に書く**(型検査器には実ファイルが要る / PHP は要らない)。
- **P31〜P33 は逆斜線の段数を間違えやすい**。PHP の見本を TypeScript の文字列に書くと
  逃がしが二重にかかるため、**抽出器へ渡った PHP のソースそのもの**が意図どおりか
  (逆斜線が何個あるか)を 1 度確かめてから期待値を書く。素の文字列 (`String.raw`) を
  使うか、逆斜線の個数をテスト側で数えて pin する(Codex Round 4 の実装上の注意)。

---

## 施策 D: 旧実装の撤去と参照の是正

### 削除

- `tests/Support/TsUnionValues.php`
- `tests/Architecture/ManualEnumTsSyncInvariantTest.php`(test 宣言 12 件)
- `tests/Architecture/NotificationTypeTsSyncInvariantTest.php`(同 2 件)
- `tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php`(同 2 件)
- `tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest.php`(同 2 件)

合計 18 件の内訳は **値集合の比較 14 件 + 抽出不能を確かめる自己検査 4 件**。
前者は新 gate の目録 14 行へ、後者は施策 C の負例行列(T7)へ移る。
実装報告には**この対応表**を残す。

### 移設で引き継ぐもの / 引き継がないもの(誇張しない)

旧テストは PHP のクラスを実際に読み込んで `::cases()` を呼んでいた。新 gate は本文を読む。
したがって次のように書く。

> **値集合の不変条件を移設した**。PHP の構文の妥当性・名前空間・オートロード・
> 完全修飾名の正しさは新 gate では見ておらず、`composer test` と PHPStan が担う。
> **backing の値の重複**だけは抽出器の側で明示的に落として保証を引き継いだ(施策 A-4 の 6)。
> TS 側のソースの重複は値集合の意味では区別できないので、**保証から外す**と明記する。

### 参照の付け替え

修正(指し先だけを直す):

| ファイル | 箇所 |
|---|---|
| `tests/Architecture/TicketLedgerReaderInventoryTest.php` | L344 の注釈 / L370 の案内文 |
| `app/Enums/Manual/{RenderKind,RenderStep,RenderErrorCode,RenderConflictType,ScenarioVerdict,ScenarioRuleCode}.php` | docblock |
| `app/Enums/Notification/NotificationType.php` / `app/Enums/AccountDeletionBlockerAction.php` | docblock |
| `resources/js/types/{manual,notification,account}.ts` | docblock 4 箇所 |
| `docs/architecture.md` | L961 / L1301 |

**残骸の掃除は次の語をリポジトリ全体(git 追跡下)で探して確かめる**:
`TsUnionValues` / `extractTsUnionValues` / `ManualEnumTsSyncInvariantTest` /
`NotificationTypeTsSyncInvariantTest` / `OnboardingBillingStateTsSyncInvariantTest` /
`AccountDeletionBlockerActionTsSyncInvariantTest` / `tests/Architecture/*TsSyncInvariantTest.php` /
旧案内文の言い回し(「同期テスト (Tests\Support\TsUnionValues) を同時に追加してください」)。
探索の根は `app` `tests` `docs` `resources` **に加えて** `AGENTS.md` / `scripts` / `config` /
`.github` も含める。**`devnotes/` は歴史の記録なので残す**(直さない)。

### テスト計画

- [ ] `composer test` が緑(test 件数が 18 件減る。前後の数値を実装報告に残す)
- [ ] `composer phpstan` level 10 が緑(未使用 import が残らない)
- [ ] `vendor/bin/pint --test` が緑
- [ ] 上の語の探索結果が `devnotes/` を除いて 0 件

### リスク

- **`composer test` だけを回す開発者はこの不変条件を検証しなくなる**。
  AGENTS.md は全検証コマンドの green を commit の条件にしており CI も `frontend` job で
  `pnpm test` を回すので運用上は塞がるが、**「PHP レーンでも見ている」とは書かない**。
  施策 F でこの非対称を明記する。

---

## 施策 E: 母集団の拡張(14 組 → 27 組)

施策 B の目録に 13 行追加する。内訳と理由は `survey.md` の表(#15〜#27)。
いずれも**現時点で値集合が一致している**ことを実測済み。

### 登録しないものと理由の置き場所

未登録のものには目録の行が無いので `note` には書けない。
**gate ファイルの冒頭の注釈**と `docs/architecture.md` に書く。
機械的な発見の仕組みがまだ無いので、**これを「免除の目録」とは呼ばない**
(呼べるのは AG-099 後半で全数走査が入ってからである)。

| TS 宣言 | 理由 |
|---|---|
| `types/manual.ts::SelectableTakeStatus` | 「選択できるテイクの状態」という部分集合の意図。今は全一致だが完全一致で縛ると意図と食い違う |
| `types/dashboard.ts::DashboardJobStatus` | `JobStatus` の真部分集合(進行中のみ) |
| `types/capture.ts::CaptureProgress` ほか画面側だけの語彙 | 対応する PHP 列挙が無い |

### テスト計画

- [ ] `MemberRoleState`(#17)は**旧抽出器では読めなかった**組なので、
      「型情報にしたから登録できた」実例として実装報告に明記する

### リスク

- 13 組の登録により、これらの enum を今後変えるときに TS 側の追随が**必須**になる。
  これは意図した効果だが、実装報告で影響範囲を明示する。

---

## 施策 F: 規約・文書・テンプレート差分の登録

### F-1 `docs/template-divergence.md` に **D27** を登録する(Round 1 から変更)

Round 1 案の「段階的な取り込みだから登録しない」は根拠として弱い、という Codex の指摘を採る。
台帳自身の記録の原則が **「登録するか迷ったら登録する」** と定めており、
家系の正典 (テンプレート) の同種 gate は **PHP 列挙の全数を分類する既定拒否**であるのに対し、
本リポジトリの v1 は**登録した写しだけを見る**ので、**保護の境界が実際に違う**。

| 行 | 値 |
|---|---|
| 対象パス | `tests/js/architecture/enum-ts-sync.test.ts` |
| 業務要件起因の説明 | 正規表現の抽出器を型情報へ置き換える作り直しが先に必要で、全数走査と逆走査まで 1 度に入れると 1 変更が扱えない大きさになるため、まず登録した写しだけを見る形で着地させた |
| 揃え続ける不変条件と保証機構 | 登録した写しの値集合が完全一致すること(同ファイルの目録 + 件数 pin)。抽出器が静かに間違えないことは `enum-ts-sync-extractor.test.ts` の負例行列 |
| 再判定の条件 | AG-099 後半(全数走査による既定拒否の分類 + 逆走査 2 規則)が入ったとき |
| 決めた日 | 実装した日を `YYYY-MM-DD` で確定して書く(式のまま残さない) |
| 決めた人 | 開発者 |
| 根拠 | `devnotes/20260817-1748-enum-ts-generic-sync-gate/` |
| 状態 | 監視中 |
| 見直し期限 | 決めた日の 180 日後を `YYYY-MM-DD` で確定して書く(基準日から 400 日以内) |

- 冒頭の「登録エントリ: 25 件」を 26 件へ、`TemplateDivergenceLedgerFormatTest` の
  件数の定数も同じ変更で直す(**3 点一致**が機械強制されている)。
- 番号は D26 まで使用済みなので **D27**(番号は再利用しない)。

### F-2 「保証しないもの」の正本を 1 か所にする

Codex の指摘どおり、同じ限界を 3 か所へ書くと必ず食い違う。分担を次のように決める。

| 置き場所 | 書くこと |
|---|---|
| `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期 | **正本**。受理する形・保証しないものの完全な一覧・レーンの非対称 |
| gate と抽出器の docblock | 短い要約 + 正本への参照 |
| `AGENTS.md` ドメイン固有規約 19 | **登録の手順とレーンだけ**。詳細は写さない(既存の規約 15 / 17 / 18 と同じ扱い) |

**保証しないもの(正本に書く一覧)**:

- 登録していない写しは 1 件も検査しない(全数走査と既定拒否の分類は未実装。D27)
- 値の集合だけを見る(表示ラベル・並び順・意味は見ない)
- 部分集合の関係は表現できない
- `.svelte` の中の宣言・定数配列・switch の case ラベルは読まない
- TS 側は**解決・正規化された後の型**で判断する。ソース上の重複した union は区別できない
- PHP 側はファイル全体の構文の妥当性・名前空間・オートロード・完全修飾名を検証しない。
  PHP が受理する構文をすべて受理するわけでもない(閉じタグ・バッククォート等は拒否する)
- 型検査そのものは見ない(`pnpm typecheck` の担当)
- **レーンの非対称**: 値集合の同期は `frontend` job (`pnpm test`)、
  PHP としての妥当性は backend job (`composer test` / PHPStan)。
  **`composer test` だけでは値集合の同期は検証されない**

### F-3 `AGENTS.md` ドメイン固有規約 19(現在 18 まで)

- 登録の作法: 「PHP の文字列付き列挙の値を TS の型別名で受ける箇所を作ったら、
  `tests/js/architecture/enum-ts-sync.test.ts` の目録へ 1 行足す(件数 pin も 1 増やす)」
- 受理する形: 型別名の宣言で、解決した型が文字列リテラル型だけであること
- 正本のレーンは `pnpm test` であること
- 保証しないものの正本は `docs/architecture.md` であること(本文に写さない)

### テスト計画

- [ ] `TemplateDivergenceLedgerFormatTest` が緑(9 行のメタ表・状態の値域・対象パスの実在と重複・
      件数の 3 点一致)
- [ ] `verification-commands-doc-sync.test.ts` が緑(AGENTS.md のマーカーを壊していない)
- [ ] `docs/architecture.md` の既存参照 2 箇所(施策 D)が新しい節を指す

---

## 後続 TODO(本作業の完了条件に含める)

裁定 AG-099 の**後半**を別 TODO として起票する(起票は `app-todo-add` の責務。
本設計は文面だけを用意する)。**D27 の再判定の条件がこの TODO の完了に結び付いている**。

> **タイトル**: PHP 列挙 ⇔ TS 値域の発見の段と逆走査 (AG-099 後半)
> **完了条件**: (1) PHP の文字列付き列挙を全数走査し、**登録済み / 対象外の理由つき /
> 抽出できない残余**の 3 つへ既定拒否で分類する。(2) 逆走査 2 規則
> (規則 1 = 未登録で値集合が完全一致する候補の検出 / 規則 2 = 名前の対応と値の交差による
> 「既に食い違った写し」の検出) を実装する。(3) `docs/template-divergence.md` の D27 を再判定する。
> **前提**: 本作業 (前半) の着地。実測では規則 1 の残りは 1 件だが、
> **これは見積りの仮説であり網羅の証拠ではない**(`survey.md`)。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 旧実装の削除 (施策 D) と新 gate の追加 (施策 A/B/C/E) は**同時でなければ緑にならない**。1 つの worktree で一括して着地させる |
| 競合リスク | `tsconfig.json` / `AGENTS.md` / `docs/architecture.md` / `docs/template-divergence.md` / `TemplateDivergenceLedgerFormatTest` を触るので、同じファイルを触る他タスクとは並行しない。`app/Enums/**` と `resources/js/types/*.ts` の変更は docblock のみで、値や型には触れない |

## 実装差分 (git diff。一時的な fail-first 検証用の変異 3 ファイルは除外済み)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 0ec49b0..d32a20d 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -836,3 +836,16 @@ ## ドメイン固有規約
       キュークラス) は母集団に入らない。**保証しないものの正本は
       `docs/architecture.md` §退避を正常系に持つジョブの終端方式**
       (ここは要約であり、増減はそちらで管理する)。
+19. **PHP 列挙 ⇔ TypeScript 値域の同期の登録 (T218 / 家系の裁定 AG-099 前半)**:
+    PHP の文字列付き列挙の値を TS の型別名で受ける箇所を作ったら、
+    `tests/js/architecture/enum-ts-sync.test.ts` の目録へ 1 行足し、件数の pin も 1 増やす。
+    **個別の同期テストのファイルを増やさない** (増殖を止めるのが本 gate の目的)。
+    - 受理する形は**型別名の宣言**で、解決した型が**文字列リテラル型だけ**であること
+      (別名参照・`keyof typeof`・有限のテンプレートリテラル型は解決されるので受理する)。
+      PHP 側は深さ 0 の `enum X: string` がちょうど 1 つで、本体直下の case が
+      `case Name = '値';` の 1 行に一致すること
+    - **正本のレーンは `pnpm test`** (CI の frontend job) である。
+      `composer test` だけでは値集合の同期は検証されない
+    - **保証しないものの正本は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期**
+      であり、本書には写さない (2 か所に書くと必ず食い違う)。
+      全数走査と逆走査を持たないことは `docs/template-divergence.md` **D27**
diff --git a/app/Enums/AccountDeletionBlockerAction.php b/app/Enums/AccountDeletionBlockerAction.php
index 7bd1d3d..37dd7cb 100644
--- a/app/Enums/AccountDeletionBlockerAction.php
+++ b/app/Enums/AccountDeletionBlockerAction.php
@@ -9,7 +9,7 @@
  *
  * **表示時点のヒントであり権威ではない** (削除時にサーバがロック下で再評価する)。
  * 値集合は resources/js/types/account.ts の TS union と同期する
- * (AccountDeletionBlockerActionTsSyncInvariantTest が固定)。
+ * (tests/js/architecture/enum-ts-sync.test.ts の目録が固定)。
  */
 enum AccountDeletionBlockerAction: string
 {
diff --git a/app/Enums/Manual/RenderConflictType.php b/app/Enums/Manual/RenderConflictType.php
index 7fd6b1b..8c0d873 100644
--- a/app/Enums/Manual/RenderConflictType.php
+++ b/app/Enums/Manual/RenderConflictType.php
@@ -6,7 +6,8 @@
 
 /**
  * レンダ/プレビュートリガーが 409 になる理由の判別子 (doc/10 §10.8-8 / 概念設計 §4)。
- * TS 側 types/manual.ts の RenderConflictType union と対で保守する (ManualEnumTsSyncInvariantTest)。
+ * TS 側 types/manual.ts の RenderConflictType union と対で保守する
+ * (tests/js/architecture/enum-ts-sync.test.ts の目録が固定)。
  */
 enum RenderConflictType: string
 {
diff --git a/app/Enums/Manual/RenderErrorCode.php b/app/Enums/Manual/RenderErrorCode.php
index 564311e..3778cc8 100644
--- a/app/Enums/Manual/RenderErrorCode.php
+++ b/app/Enums/Manual/RenderErrorCode.php
@@ -7,7 +7,8 @@
 /**
  * レンダ失敗種別の型付き判別子 (v1 は 3 値で閉じる。概念設計 Round 2/3)。
  * フロントの CTA 分岐は自由文 error でなくこの code で行う (文言変更で壊れない)。
- * TS 側 types/manual.ts の RenderErrorCode union と対で保守する (ManualEnumTsSyncInvariantTest)。
+ * TS 側 types/manual.ts の RenderErrorCode union と対で保守する
+ * (tests/js/architecture/enum-ts-sync.test.ts の目録が固定)。
  */
 enum RenderErrorCode: string
 {
diff --git a/app/Enums/Manual/RenderKind.php b/app/Enums/Manual/RenderKind.php
index 237cb10..8bcf8c4 100644
--- a/app/Enums/Manual/RenderKind.php
+++ b/app/Enums/Manual/RenderKind.php
@@ -7,7 +7,8 @@
 /**
  * レンダジョブの操作種別 (§10.8-8「preview と render は別操作種別」の実体)。
  * in-flight 判定・課金有無 (render のみチケット消費)・manual status 遷移有無が異なる。
- * TS 側 types/manual.ts の RenderKind union と対で保守する (ManualEnumTsSyncInvariantTest)。
+ * TS 側 types/manual.ts の RenderKind union と対で保守する
+ * (tests/js/architecture/enum-ts-sync.test.ts の目録が固定)。
  */
 enum RenderKind: string
 {
diff --git a/app/Enums/Manual/RenderStep.php b/app/Enums/Manual/RenderStep.php
index 27e422b..ada47f1 100644
--- a/app/Enums/Manual/RenderStep.php
+++ b/app/Enums/Manual/RenderStep.php
@@ -6,7 +6,8 @@
 
 /**
  * レンダジョブの進行段階 (doc/10 §10.1)。
- * TS 側 types/manual.ts の RenderStep union と対で保守する (ManualEnumTsSyncInvariantTest)。
+ * TS 側 types/manual.ts の RenderStep union と対で保守する
+ * (tests/js/architecture/enum-ts-sync.test.ts の目録が固定)。
  */
 enum RenderStep: string
 {
diff --git a/app/Enums/Manual/ScenarioRuleCode.php b/app/Enums/Manual/ScenarioRuleCode.php
index de7af22..cdbb835 100644
--- a/app/Enums/Manual/ScenarioRuleCode.php
+++ b/app/Enums/Manual/ScenarioRuleCode.php
@@ -13,7 +13,7 @@
  * **閾値 (文字数上限等) を持つ検査も入れない** (根拠となる実データが無いため)。
  *
  * TS 側 resources/js/types/manual.ts の ScenarioRuleCode union と値集合を一致させる
- * (ManualEnumTsSyncInvariantTest が固定)。
+ * (tests/js/architecture/enum-ts-sync.test.ts の目録が固定)。
  */
 enum ScenarioRuleCode: string
 {
diff --git a/app/Enums/Manual/ScenarioVerdict.php b/app/Enums/Manual/ScenarioVerdict.php
index fcd4f11..f4c0000 100644
--- a/app/Enums/Manual/ScenarioVerdict.php
+++ b/app/Enums/Manual/ScenarioVerdict.php
@@ -9,7 +9,7 @@
  *
  * **制御フローには使わない** (表示のみ。保存・撮影・レンダを止めない)。
  * TS 側 resources/js/types/manual.ts の ScenarioVerdict union と値集合を一致させる
- * (ManualEnumTsSyncInvariantTest が固定)。
+ * (tests/js/architecture/enum-ts-sync.test.ts の目録が固定)。
  */
 enum ScenarioVerdict: string
 {
diff --git a/app/Enums/Notification/NotificationType.php b/app/Enums/Notification/NotificationType.php
index 56d5c0c..687d74c 100644
--- a/app/Enums/Notification/NotificationType.php
+++ b/app/Enums/Notification/NotificationType.php
@@ -10,7 +10,7 @@
  * - DB (notifications.type) には本 enum の value を格納する (クラス名を DB に置かない。
  *   AppNotification::databaseType() 経由。InAppNotificationTypeInvariantTest が強制)
  * - TS 側 resources/js/types/notification.ts の literal union と値集合を一致させる
- *   (NotificationTypeTsSyncInvariantTest が固定)
+ *   (tests/js/architecture/enum-ts-sync.test.ts の目録が固定)
  */
 enum NotificationType: string
 {
diff --git a/docs/architecture.md b/docs/architecture.md
index 4e2a4cb..37ae67f 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -958,8 +958,8 @@ ## サブスク契約 Checkout とオンボーディング着地 (P7/P9) の運
     `billing.index`、`manageBilling` なしなら `onboarding.billing-required` へ
     **サーバ (`OnboardingController::show` の離脱ガード) が捌く**。認可をフロントで
     二重実装しないし、押せないボタンも作らない (禁止事項 8)。
-  - 値集合の同期は `OnboardingBillingStateTsSyncInvariantTest` (PHP enum ⇔
-    `resources/js/types/billing.ts` の `BillingStateValue`)、分岐の網羅は
+  - 値集合の同期は `tests/js/architecture/enum-ts-sync.test.ts` の目録 (PHP enum ⇔
+    `resources/js/types/billing.ts` の `BillingStateValue`。§PHP 列挙と TypeScript 値域の同期)、分岐の網羅は
     **`resources/js/types/dashboard.ts` の `BILLING_CALLOUTS`** が持つ
     `satisfies Record<BillingStateValue, …>` (= `pnpm typecheck`)、描画は vitest と
     Browser lane が担う。**3 層は別物でどれか 1 つでは足りない**。
@@ -1298,7 +1298,8 @@ ## アプリ内通知センター (T008) の運用契約
 - **type 規約**: `notifications.type` には `NotificationType` enum の value を格納する
   (クラス名を DB に置かない。`InAppNotificationTypeInvariantTest` が
   `app/Notifications/InApp/*` の全派生に deny-by-default で強制。
-  TS 側 `types/notification.ts` との値集合同期は `NotificationTypeTsSyncInvariantTest`)
+  TS 側 `types/notification.ts` との値集合同期は
+  `tests/js/architecture/enum-ts-sync.test.ts` の目録。§PHP 列挙と TypeScript 値域の同期)
 - **発火**: すべて `NotificationCenterService` 経由・既存 exactly-once 遷移の **commit 後**
   (解析/レンダ terminal 遷移の bool ゲート / 招待作成後 / reserve の残高閾値クロス検知)。
   terminal tx 内に通知 insert を入れない。通知例外は catch + report でジョブ本流を壊さない
@@ -2791,3 +2792,58 @@ ### 保証しないもの (誇張しない。**本節が正本**)
   沈黙する。
 - **認可コードの交換時に所属を確認してはいない**。閉じているのは「失効の時点で未交換だった
   コードを撃つ」ところまでである (後続の候補)。
+
+## PHP 列挙と TypeScript 値域の同期 (T218 / 家系の裁定 AG-099 前半)
+
+サーバの語彙 (PHP の文字列付き列挙) を画面が受けるとき、TS 側は型別名の値域として
+同じ集合を持つ。片方だけ増えると画面の分岐に「どこにも当たらない値」が生まれ、
+**無言の描画漏れ**になる。これを 1 本の汎用 gate
+(`tests/js/architecture/enum-ts-sync.test.ts`) で固定する。
+
+- **登録の仕方**: 目録 `ENUM_TS_MIRRORS` へ 1 行 (PHP のパス / TS のパス / 型別名 / 理由) を足し、
+  件数の pin `EXPECTED_MIRROR_COUNT` を 1 増やす。**個別の検査ファイルは増やさない**
+  (裁定 AG-099 が止めたかったのは検査の増殖である)。
+  `note` に最小文字数は課さない — 本目録は**免除の申告ではなく登録**であり、
+  「検査から外す」判断ではないため。
+- **受理する形 (TS 側)**: 対象ファイルのトップレベルに、その名前の型別名の宣言が
+  ちょうど 1 つあり、**解決・正規化された後の型**が文字列リテラル型だけの union
+  (または単独の文字列リテラル型) であること。別名参照・import 越しの参照・
+  `keyof typeof`・`Lowercase<…>`・具体化された条件型・有限のテンプレートリテラル型は
+  すべて受理する (型検査器が畳んだ後を見るため)。TypeScript の `enum` の値は受理しない
+  (本リポジトリに 1 件も無く、文字列リテラル型と同じ契約ではない。必要になってから広げる)。
+- **受理する形 (PHP 側)**: 深さ 0 の `enum <名前>: string` がちょうど 1 つあり、
+  その名前がファイル名の語幹と一致し、本体の直下の `case` が
+  `case Name = '値';` / `case Name = "値";` の 1 行に一致すること。
+  定数式・逆斜線・変数の埋め込み・複数行の case は例外にする。
+- **program は tsconfig が含む TS 全体で作る**。目録のファイルだけを起点にすると、
+  `include` だけで参加する宣言 (周囲宣言 / `declare global` / モジュールの拡張) が載らず
+  **本番の型と違う型世界**で判定してしまう (偽陰性)。速さのために起点を縮めない。
+  縮める改変が入ったら `enum-ts-sync-extractor.test.ts` の T25 が赤くなる。
+- **抽出器が静かに間違えないこと**は `tests/js/architecture/enum-ts-sync-extractor.test.ts` の
+  負例行列 (TS 27 件 / PHP 38 件) が固定する。見本の置き方は非対称で、
+  TS は**ファイル** (型検査器に実ファイルが要る。`tsconfig.json` の `exclude` で
+  `pnpm typecheck` の対象から外す)、PHP は**テスト内の文字列** (`.php` として置くと
+  strict_types 宣言 gate / 禁止文の字句走査 / Pint / PHPStan の母集団に入るため)。
+
+### 保証しないもの (誇張しない)
+
+- **登録していない写しは 1 件も検査していない**。全数走査による既定拒否の分類と
+  逆走査 2 規則は裁定 AG-099 の後半の担当で、本 gate には無い
+  (`docs/template-divergence.md` **D27**)。現在意図的に登録していないのは
+  `types/manual.ts::SelectableTakeStatus` (部分集合の意図) /
+  `types/dashboard.ts::DashboardJobStatus` (`JobStatus` の真部分集合) /
+  `types/capture.ts::CaptureProgress` ほか画面側だけの語彙 (対応する PHP 列挙が無い) である。
+- **値の集合だけを見る**。表示ラベル・並び順・意味は見ない。
+- **部分集合の関係は表現できない** (完全一致だけ)。
+- `.svelte` の中の宣言・定数配列 (`as const` の配列)・`switch` の case ラベルは読まない。
+- TS 側は**解決・正規化された後の型**で判断するので、ソース上の重複した union
+  (`"a" | "a"`) や union の中の `never` は区別できない。**「同じ値が 2 回あると落ちる」とは
+  主張しない**。PHP 側の backing の値の重複だけは抽出器が明示的に落とす
+  (旧テストが配列比較で持っていた保証の引き継ぎ)。
+- PHP 側はファイル全体の構文の妥当性・名前空間・オートロード・完全修飾名を検証しない
+  (それらは `composer test` と PHPStan の担当)。PHP が受理する構文をすべて受理する
+  わけでもない (閉じタグ・バッククォート・ヒアドキュメントは拒否する)。
+- **型検査そのものは見ない** (`pnpm typecheck` の担当。意味の診断は読まない)。
+- **レーンの非対称**: 値集合の同期は `pnpm test` (CI の frontend job) でだけ走る。
+  PHP としての妥当性は backend job (`composer test` / PHPStan)。
+  **`composer test` だけでは値集合の同期は検証されない**。
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 99d6e34..532827a 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 25 件
+登録エントリ: 26 件
 
 ## 記録の原則
 
@@ -1506,3 +1506,55 @@ ### 関連
 - 実装: `app/Support/PasskeyConfigValidator.php` / `app/Support/PasskeyOriginCanonicalizer.php`
 - 設計: `devnotes/20260815-1111-passkey-config-hardening/` /
   `devnotes/20260817-1309-todo-t216-passkey-hardening-completion/`
+
+## D27 PHP 列挙と TS 値域の同期を「登録した写しだけ」で守る (全数走査と逆走査は持たない)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/js/architecture/enum-ts-sync.test.ts` |
+| 業務要件起因の説明 | 正規表現で二重引用符の literal union だけを読んでいた旧抽出器を型情報の抽出へ作り直すことが先に要り、全数走査による既定拒否の分類と逆走査まで 1 度に入れると 1 変更が扱えない大きさになる。まず登録した写しだけを見る形で着地させた |
+| 揃え続ける不変条件と保証機構 | 登録した写しの値集合が PHP 側と完全一致すること (同ファイルの目録 + 件数 pin)。抽出器が静かに間違えないことは `tests/js/architecture/enum-ts-sync-extractor.test.ts` の負例行列 |
+| 再判定の条件 | 家系の裁定 AG-099 の後半 (PHP の文字列付き列挙の全数走査による既定拒否の分類 + 逆走査 2 規則) を入れたとき |
+| 決めた日 | 2026-08-17 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260817-1748-enum-ts-generic-sync-gate/ |
+| 状態 | 監視中 |
+| 見直し期限 | 2027-02-13 |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 母集団の決め方 | PHP の文字列付き列挙を全数走査し、既定拒否で分類する | 目録へ登録した写しだけを見る |
+| 未登録の写しの扱い | 分類されていない残余として赤くなる | 検査されない (沈黙する) |
+| 逆走査 (未登録の一致候補 / 既に食い違った写しの検出) | 2 規則を持つ | 持たない |
+| 抽出の基盤 | 型情報 | 同じ (型情報。ここは正典と揃えた) |
+
+### なぜ正当な差分か (logic-driven)
+
+置き換え前の抽出器 (本変更で削除した `tests/Support/TsUnionValues.php`) は
+「二重引用符の文字列を正規表現で拾う」実装で、別名参照を含む宣言 (`ConsoleRole | "owner" | "unassigned"`) を読めず、
+注釈の中の引用符を値として拾い、`(string & {})` を閉じた union と誤認していた。
+この抽出器の上に全数走査を載せると、**分類の入力そのものが間違ったまま母集団だけが増える**。
+先に抽出を型情報へ移し、母集団の拡張 (14 組 → 27 組) までを 1 つの変更として着地させ、
+全数走査と逆走査は後半の TODO へ分けた。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「登録した写しについては、PHP の列挙と TS の型別名の値集合が完全一致する」
+
+- 目録の件数は完全一致で pin する (写しが黙って消えるのを防ぐ)
+- 抽出は**型情報**で行う (正典と同じ基盤)。受理できない形は空集合ではなく例外にする
+- 抽出器の受理・拒否の境界は負例行列 (TS 27 件 / PHP 38 件) が固定する
+
+### 保証しないもの
+
+- **登録していない写しは 1 件も検査していない**。未登録の写しが食い違っても沈黙する
+- 逆走査を持たないので、「値集合が完全一致するのに未登録」の候補も、
+  「名前が対応するのに既に食い違っている写し」も自動では見つからない
+- 保証しないものの完全な一覧は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期
+
+### 関連
+
+- 実装: `tests/js/architecture/enum-ts-sync.test.ts` /
+  `tests/js/architecture/enum-ts-sync-extractor.test.ts` /
+  `tests/js/support/enum-ts-sync/`
+- 設計: `devnotes/20260817-1748-enum-ts-generic-sync-gate/`
diff --git a/resources/js/types/account.ts b/resources/js/types/account.ts
index d1b4f72..d5adc92 100644
--- a/resources/js/types/account.ts
+++ b/resources/js/types/account.ts
@@ -3,7 +3,7 @@
  *
  * PHP 側 App\Enums\AccountDeletionBlockerAction /
  * App\DataTransferObjects\Organizations\AccountDeletionBlockerDto::toArray() と対で保守する
- * (値集合の一致は tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest が固定する)。
+ * (値集合の一致は tests/js/architecture/enum-ts-sync.test.ts の目録が固定する)。
  */
 
 /** PHP: App\Enums\AccountDeletionBlockerAction と対 (値集合を一致させる) */
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index fb034e6..428809c 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -3,8 +3,8 @@
  * PHP 側の typed array PHPDoc (ProjectController::manualRows 等) と対で保守する。
  * status は PHP enum App\Enums\Manual\VideoManualStatus と値集合を一致させる
  * (literal union で UI 分岐漏れを検出する)。**乖離検知の正本は
- * tests/Architecture/ManualEnumTsSyncInvariantTest.php** (VideoManualStatus /
- * ManualProgress を含む値集合同期テスト) であり、手動確認ではない。
+ * tests/js/architecture/enum-ts-sync.test.ts の目録** (VideoManualStatus /
+ * ManualProgress を含む値集合同期 gate) であり、手動確認ではない。
  */
 
 import type { BadgeTone } from "@/components/atoms/Badge.types";
@@ -297,7 +297,7 @@ export interface InsufficientTicketsBody {
     message: string;
 }
 
-/** PHP: App\Enums\Manual\RenderKind と対 (値集合同期テストあり = ManualEnumTsSyncInvariantTest) */
+/** PHP: App\Enums\Manual\RenderKind と対 (値集合同期は enum-ts-sync.test.ts の目録) */
 export type RenderKind = "render" | "preview";
 
 /** PHP: App\Enums\Manual\RenderStep と対 (値集合同期テストあり) */
diff --git a/resources/js/types/notification.ts b/resources/js/types/notification.ts
index 78f51b0..a0b9c8c 100644
--- a/resources/js/types/notification.ts
+++ b/resources/js/types/notification.ts
@@ -2,7 +2,7 @@
  * アプリ内通知 (通知センター) の Inertia props 型。
  * PHP 側 App\Enums\Notification\NotificationType /
  * App\DataTransferObjects\Notification\NotificationListItemData::toArray() と対で保守する
- * (値集合の一致は tests/Architecture/NotificationTypeTsSyncInvariantTest が固定する)。
+ * (値集合の一致は tests/js/architecture/enum-ts-sync.test.ts の目録が固定する)。
  */
 
 /** PHP: App\Enums\Notification\NotificationType と対 (値集合を一致させる) */
diff --git a/tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest.php b/tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest.php
deleted file mode 100644
index 744f962..0000000
--- a/tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest.php
+++ /dev/null
@@ -1,23 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-use App\Enums\AccountDeletionBlockerAction;
-use Tests\Support\TsUnionValues;
-
-/*
- * AccountDeletionBlockerAction (PHP enum) ⇔ resources/js/types/account.ts (TS literal union) の
- * 値集合同期 invariant。退会ガードの「次の一手」は wire に載る語彙で、フロントが action 値で
- * 導線を分岐するため、enum 追加が silent に描画漏れへ落ちるのを防ぐ
- * (抽出は共有 helper TsUnionValues。抽出不能 = fail)。
- */
-
-test('AccountDeletionBlockerAction の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(TsUnionValues::extract('resources/js/types/account.ts', 'AccountDeletionBlockerAction'))
-        ->toBe(TsUnionValues::enumStringValues(AccountDeletionBlockerAction::cases()));
-});
-
-test('account.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
-    expect(fn (): array => TsUnionValues::extract('resources/js/types/account.ts', 'NoSuchUnionName'))
-        ->toThrow(RuntimeException::class, 'degenerate PASS');
-});
diff --git a/tests/Architecture/ManualEnumTsSyncInvariantTest.php b/tests/Architecture/ManualEnumTsSyncInvariantTest.php
deleted file mode 100644
index 6baa8c4..0000000
--- a/tests/Architecture/ManualEnumTsSyncInvariantTest.php
+++ /dev/null
@@ -1,94 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-use App\Enums\Manual\JobStatus;
-use App\Enums\Manual\ManualProgress;
-use App\Enums\Manual\MaterialType;
-use App\Enums\Manual\RenderConflictType;
-use App\Enums\Manual\RenderErrorCode;
-use App\Enums\Manual\RenderKind;
-use App\Enums\Manual\RenderStep;
-use App\Enums\Manual\ScenarioRuleCode;
-use App\Enums\Manual\ScenarioVerdict;
-use App\Enums\Manual\VideoManualStatus;
-use Tests\Support\TsUnionValues;
-
-/*
- * PHP enum ⇔ TS literal union の値集合同期 invariant (概念設計 Round 3)。
- *
- * resources/js/types/manual.ts の literal union を正規表現で抽出し、PHP enum の
- * 値集合と完全一致することを固定する (フロントの CTA 分岐・型分岐が enum 追加で
- * silent に壊れるのを防ぐ)。抽出不能 (degenerate PASS) は fail させる。
- * 抽出ロジックは共有 helper (Tests\Support\TsUnionValues) に置き、
- * NotificationTypeTsSyncInvariantTest と共用する。
- */
-
-/**
- * types/manual.ts から `export type {Name} = "a" | "b" | ...;` の値集合を抽出する。
- *
- * @return list<string>
- */
-function extractTsUnionValues(string $typeName): array
-{
-    return TsUnionValues::extract('resources/js/types/manual.ts', $typeName);
-}
-
-test('VideoManualStatus の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('VideoManualStatus'))
-        ->toBe(TsUnionValues::enumStringValues(VideoManualStatus::cases()));
-});
-
-test('ManualProgress の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('ManualProgress'))
-        ->toBe(TsUnionValues::enumStringValues(ManualProgress::cases()));
-});
-
-test('RenderKind の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('RenderKind'))->toBe(TsUnionValues::enumStringValues(RenderKind::cases()));
-});
-
-test('RenderStep の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('RenderStep'))->toBe(TsUnionValues::enumStringValues(RenderStep::cases()));
-});
-
-test('RenderErrorCode の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('RenderErrorCode'))->toBe(TsUnionValues::enumStringValues(RenderErrorCode::cases()));
-});
-
-test('RenderConflictType の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('RenderConflictType'))->toBe(TsUnionValues::enumStringValues(RenderConflictType::cases()));
-});
-
-test('ScenarioVerdict の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('ScenarioVerdict'))->toBe(TsUnionValues::enumStringValues(ScenarioVerdict::cases()));
-});
-
-test('ScenarioRuleCode の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('ScenarioRuleCode'))->toBe(TsUnionValues::enumStringValues(ScenarioRuleCode::cases()));
-});
-
-test('AnalysisJobStatus (JobStatus 共用) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('AnalysisJobStatus'))->toBe(TsUnionValues::enumStringValues(JobStatus::cases()));
-});
-
-test('抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
-    expect(fn (): array => extractTsUnionValues('NoSuchUnionName'))
-        ->toThrow(RuntimeException::class, 'degenerate PASS');
-});
-
-/*
- * MaterialType の TS 側の写しは **2 ファイルにある** (PC 側 types/manual.ts の CutMaterialType /
- * 撮影 PWA 側 types/capture.ts の MaterialType)。2 つの types ファイルは
- * 「PC は署名 URL の口を持たない」という理由で意図的に分けてあり、片方が他方を import すると
- * その分離が崩れる。したがって**写しは 2 つ残し、両方を enum と突き合わせる**
- * (片方だけ pin すると drift が起きる)。
- */
-test('CutMaterialType (types/manual.ts) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('CutMaterialType'))->toBe(TsUnionValues::enumStringValues(MaterialType::cases()));
-});
-
-test('MaterialType (types/capture.ts) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(TsUnionValues::extract('resources/js/types/capture.ts', 'MaterialType'))
-        ->toBe(TsUnionValues::enumStringValues(MaterialType::cases()));
-});
diff --git a/tests/Architecture/NotificationTypeTsSyncInvariantTest.php b/tests/Architecture/NotificationTypeTsSyncInvariantTest.php
deleted file mode 100644
index b884bcd..0000000
--- a/tests/Architecture/NotificationTypeTsSyncInvariantTest.php
+++ /dev/null
@@ -1,22 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-use App\Enums\Notification\NotificationType;
-use Tests\Support\TsUnionValues;
-
-/*
- * NotificationType (PHP enum) ⇔ resources/js/types/notification.ts (TS literal union) の
- * 値集合同期 invariant。フロントの type 駆動描画 (アイコン/文言分岐) が enum 追加で
- * silent に壊れるのを防ぐ (抽出は共有 helper TsUnionValues。抽出不能 = fail)。
- */
-
-test('NotificationType の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(TsUnionValues::extract('resources/js/types/notification.ts', 'NotificationType'))
-        ->toBe(TsUnionValues::enumStringValues(NotificationType::cases()));
-});
-
-test('notification.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
-    expect(fn (): array => TsUnionValues::extract('resources/js/types/notification.ts', 'NoSuchUnionName'))
-        ->toThrow(RuntimeException::class, 'degenerate PASS');
-});
diff --git a/tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php b/tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php
deleted file mode 100644
index e72c8b0..0000000
--- a/tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php
+++ /dev/null
@@ -1,30 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-use App\Enums\Billing\OnboardingBillingState;
-use Tests\Support\TsUnionValues;
-
-/*
- * OnboardingBillingState (PHP enum) ⇔ resources/js/types/billing.ts の BillingStateValue
- * (TS literal union) の値集合同期 invariant。
- *
- * この union は /billing と /dashboard の**両方**で分岐に使われる (dashboard は
- * bug-hunt 20260811-003230 F-2-01 の是正で state 分岐になった)。case 追加が
- * TS 側の更新なしに通ると、新状態が画面で「どの分岐にも当たらない」= 無言の描画漏れになる。
- */
-
-test('OnboardingBillingState の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    $enumValues = TsUnionValues::enumStringValues(OnboardingBillingState::cases());
-
-    // 母集団 0 件での degenerate PASS を防ぐ (空 vs 空は一致してしまう)
-    expect($enumValues)->not->toBeEmpty();
-
-    expect(TsUnionValues::extract('resources/js/types/billing.ts', 'BillingStateValue'))
-        ->toBe($enumValues);
-});
-
-test('billing.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
-    expect(fn (): array => TsUnionValues::extract('resources/js/types/billing.ts', 'NoSuchUnionName'))
-        ->toThrow(RuntimeException::class, 'degenerate PASS');
-});
diff --git a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
index 1ca616d..0632592 100644
--- a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
+++ b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
@@ -34,7 +34,7 @@
  * **明示件数との同期検査であって、例外を許す一覧ではない**。個別の D 番号を名指しして
  * 規則を免除する仕組みは持たない。登録を足した / 消したら同じ変更でこの値も直す。
  */
-const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 25;
+const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 26;
 
 /** 逸脱の登録簿の本文 (読めないことは不合格)。 */
 function templateDivergenceMarkdown(): string
diff --git a/tests/Architecture/TicketLedgerReaderInventoryTest.php b/tests/Architecture/TicketLedgerReaderInventoryTest.php
index 0d4da78..6ed43b8 100644
--- a/tests/Architecture/TicketLedgerReaderInventoryTest.php
+++ b/tests/Architecture/TicketLedgerReaderInventoryTest.php
@@ -341,8 +341,8 @@ final class R { public function f($q): void { $q->sum('delta'); } }
 test('検査 7: フロント側に台帳 kind の対応型が無い (増やすなら TS 同期テストが要る)', function (): void {
     // C2b で `TicketLedgerKind` に `carry_forward` を足した。**現時点で `resources/js` 側に
     // 台帳 kind の対応型も表示分岐も存在しない**ため TS 同期テストは不要である
-    // (ManualEnumTsSyncInvariantTest / NotificationTypeTsSyncInvariantTest のような
-    //  literal union が 1 つも無い)。この「不在」を deny-by-default で固定する —
+    // (enum-ts-sync.test.ts の目録が見るような literal union が 1 つも無い)。
+    // この「不在」を deny-by-default で固定する —
     // フロントに台帳 kind を持ち込むなら、同時に enum ⇔ TS union の同期テストを足させる。
     $hits = [];
     $base = resource_path('js');
@@ -367,7 +367,8 @@ final class R { public function f($q): void { $q->sum('delta'); } }
 
     expect($hits)->toBe([],
         'フロントに台帳 kind の対応型 / 表示分岐が現れました。PHP enum ⇔ TS union の '
-        .'同期テスト (Tests\Support\TsUnionValues) を同時に追加してください。'
+        .'同期 gate (tests/js/architecture/enum-ts-sync.test.ts の目録) へ '
+        .'1 行足してください。'
         .PHP_EOL.implode(PHP_EOL, $hits));
 
     // 空振り検知: 走査が実際にファイルへ届いている
diff --git a/tests/Support/TsUnionValues.php b/tests/Support/TsUnionValues.php
deleted file mode 100644
index adbcbc2..0000000
--- a/tests/Support/TsUnionValues.php
+++ /dev/null
@@ -1,64 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace Tests\Support;
-
-use BackedEnum;
-use RuntimeException;
-
-/**
- * PHP enum ⇔ TS literal union の値集合同期 invariant 用の抽出ヘルパ。
- * ManualEnumTsSyncInvariantTest / NotificationTypeTsSyncInvariantTest が共有する
- * (T008 で ManualEnumTsSyncInvariantTest 内のローカル関数から昇格)。
- */
-final class TsUnionValues
-{
-    /**
-     * TS ファイルから `export type {Name} = "a" | "b" | ...;` の値集合を抽出する。
-     * 抽出不能 (degenerate PASS) は fail させる (RuntimeException)。
-     *
-     * @param  string  $relativePath  base_path からの相対パス (例: resources/js/types/manual.ts)
-     * @return list<string>
-     */
-    public static function extract(string $relativePath, string $typeName): array
-    {
-        $path = base_path($relativePath);
-        $contents = file_get_contents($path);
-        if ($contents === false) {
-            throw new RuntimeException("TS ファイルを読めません: {$path}");
-        }
-
-        // `export type X =` から次の `;` までを取り出す (複数行 union 対応)
-        $matched = preg_match(
-            '/export\s+type\s+'.preg_quote($typeName, '/').'\s*=\s*(.*?);/s',
-            $contents,
-            $matches,
-        );
-        if ($matched !== 1) {
-            throw new RuntimeException("TS union が抽出できません (degenerate PASS 防止): {$typeName}");
-        }
-
-        $literalCount = preg_match_all('/"([^"]+)"/', $matches[1], $literals);
-        if ($literalCount === false || $literalCount === 0) {
-            throw new RuntimeException("TS union のリテラルが抽出できません: {$typeName}");
-        }
-
-        $values = $literals[1];
-        sort($values);
-
-        return $values;
-    }
-
-    /**
-     * @param  list<BackedEnum>  $cases
-     * @return list<string>
-     */
-    public static function enumStringValues(array $cases): array
-    {
-        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
-        sort($values);
-
-        return $values;
-    }
-}
diff --git a/tests/js/architecture/enum-ts-sync-extractor.test.ts b/tests/js/architecture/enum-ts-sync-extractor.test.ts
new file mode 100644
index 0000000..649ec42
--- /dev/null
+++ b/tests/js/architecture/enum-ts-sync-extractor.test.ts
@@ -0,0 +1,505 @@
+/**
+ * 抽出器の自己検査 (負例行列)。
+ *
+ * `enum-ts-sync.test.ts` の本体 gate は「PHP 列挙 ⇔ TS 値域が一致すること」しか見ない。
+ * 抽出器が**静かに間違える** (値を落とす / 注釈の語を混ぜる / 受理範囲外を素通しする) と
+ * 一致の主張そのものが空になるので、抽出器の受理・拒否の境界をここで固定する。
+ *
+ * **見本の置き方が TS と PHP で非対称なのは理由がある**:
+ * - TS の見本は**ファイル**で置く。型検査器に解決させるには実ファイルが要るため。
+ *   わざと壊した見本を含むので `tsconfig.json` の `exclude` で `pnpm typecheck` の
+ *   対象から外してある (`program-fixtures/` は T25 のために**除外しない**)。
+ * - PHP の見本は**テスト内の文字列**で書く。抽出器はファイルを要求しないので、
+ *   `.php` として置くと 4 つの PHP 側検査 (strict_types 宣言 gate / 禁止文の字句走査 /
+ *   Pint / PHPStan) の母集団に入ってしまうのを避ける。
+ *
+ * 保証しないものの正本は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期。
+ */
+import { describe, expect, it, beforeAll } from "vitest";
+import fs from "node:fs";
+import path from "node:path";
+import { EnumTsSyncError } from "../support/enum-ts-sync/errors";
+import {
+    REPO_ROOT,
+    createFixtureProgram,
+    createMirrorProgram,
+    type MirrorProgram,
+} from "../support/enum-ts-sync/program";
+import { readTsUnionValues } from "../support/enum-ts-sync/ts-value-sets";
+import { readPhpEnumValues, readPhpEnumValuesFromText } from "../support/enum-ts-sync/php-enums";
+
+const FIXTURE_DIR = path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/fixtures");
+const PROGRAM_FIXTURE_DIR = path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/program-fixtures");
+
+/** 見本ファイルのリポジトリ相対パス。 */
+const fixture = (name: string): string => `tests/js/support/enum-ts-sync/fixtures/${name}`;
+
+interface TsCase {
+    /** 行列の番号 (設計の表と 1:1)。 */
+    readonly id: string;
+    /** 見本ファイル名 (`fixtures/` 配下)。 */
+    readonly file: string;
+    /** 読ませる型別名の名前。 */
+    readonly declaration: string;
+    /** 受理するなら期待する値集合、拒否するなら `undefined`。 */
+    readonly accepts: readonly string[] | undefined;
+    /** 拒否するときに文面へ必ず含まれる語 (別の理由の例外で緑にならないようにする)。 */
+    readonly reason?: string;
+}
+
+/**
+ * TS 側の行列。**全体 program で判定する** (本番の gate と同じ型世界)。
+ * T25b だけは起点を縮めた program で判定するので別に持つ。
+ */
+const TS_CASES: readonly TsCase[] = [
+    { id: "T1", file: "t01-plain-union.ts", declaration: "X", accepts: ["a", "b"] },
+    { id: "T2", file: "t02-single-literal.ts", declaration: "X", accepts: ["only"] },
+    { id: "T3", file: "t03-comment-noise.ts", declaration: "X", accepts: ["a", "b"] },
+    { id: "T4", file: "t04-open-string.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
+    { id: "T5", file: "t05-number-member.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
+    { id: "T6", file: "t06-never.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
+    { id: "T7", file: "t07-absent.ts", declaration: "X", accepts: undefined, reason: "型別名の宣言が見つかりません" },
+    { id: "T8", file: "t08-duplicate-alias.ts", declaration: "X", accepts: undefined, reason: "同名の型別名が 2 件あります" },
+    { id: "T9", file: "t09-const-array.ts", declaration: "X", accepts: undefined, reason: "型別名の宣言が見つかりません" },
+    { id: "T10a", file: "t10a-target.ts", declaration: "X", accepts: ["c", "y1", "y2"] },
+    { id: "T10b", file: "t10b-path-alias.ts", declaration: "X", accepts: ["editor", "extra", "shooter", "viewer"] },
+    { id: "T11", file: "t11-indexed-access.ts", declaration: "X", accepts: ["p", "q"] },
+    { id: "T12", file: "t12-keyof-typeof.ts", declaration: "X", accepts: ["a", "b"] },
+    { id: "T13", file: "t13-value-of.ts", declaration: "X", accepts: ["va", "vb"] },
+    { id: "T14", file: "t14-lowercase.ts", declaration: "X", accepts: ["a", "b"] },
+    { id: "T15", file: "t15-instantiated-conditional.ts", declaration: "X", accepts: ["a", "b"] },
+    { id: "T16", file: "t16-generic-conditional.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
+    { id: "T17", file: "t17-closed-template.ts", declaration: "X", accepts: ["a1", "a2"] },
+    { id: "T18", file: "t18-open-template.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
+    { id: "T19", file: "t19-string-enum.ts", declaration: "X", accepts: undefined, reason: "TypeScript の enum の値は受理しません" },
+    { id: "T20", file: "t20-numeric-enum.ts", declaration: "X", accepts: undefined, reason: "TypeScript の enum の値は受理しません" },
+    { id: "T21", file: "t21-unique-symbol.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
+    { id: "T22", file: "t22-circular.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
+    { id: "T23", file: "t23-unresolved-import.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
+    { id: "T24", file: "t24-source-duplicate.ts", declaration: "X", accepts: ["a"] },
+    { id: "T25a", file: "t25-target.ts", declaration: "X", accepts: ["a", "b"] },
+];
+
+/** T25b: 起点だけの program では拡張が載らないので値が減る (起点を縮める改変の回帰)。 */
+const T25B = { file: "t25-target.ts", declaration: "X", accepts: ["a"] } as const;
+
+/**
+ * 行列の件数の pin (概念上の行数ではなく `it.each` に渡す要素数)。
+ * T25b は別 program で判定するため `TS_CASES` の外にあり、行列全体では 27 件になる。
+ */
+const EXPECTED_TS_CASE_COUNT = 26;
+const EXPECTED_TS_MATRIX_COUNT = 27;
+
+/**
+ * 見本の実在集合と行列の参照集合を突き合わせるための「補助」一覧。
+ * 行列から直接は読まないが、見本の解決に必要なファイル。
+ */
+const TS_AUXILIARY_FIXTURES: readonly string[] = ["t10a-other.ts"];
+
+/** `program-fixtures/` に置く補助 (tsconfig の対象に残す)。 */
+const PROGRAM_FIXTURES: readonly string[] = ["registry-base.ts", "registry-augmentation.ts"];
+
+let fullProgram: MirrorProgram | undefined;
+let narrowProgram: MirrorProgram | undefined;
+
+const requireFullProgram = (): MirrorProgram => {
+    if (fullProgram === undefined) throw new EnumTsSyncError("fixture full program", "初期化されていません");
+    return fullProgram;
+};
+const requireNarrowProgram = (): MirrorProgram => {
+    if (narrowProgram === undefined) throw new EnumTsSyncError("fixture narrow program", "初期化されていません");
+    return narrowProgram;
+};
+
+describe("TS 側抽出器の負例行列", () => {
+    beforeAll(() => {
+        // 見本は tsconfig から除外してあるので、全体 program にも起点として明示的に足す。
+        fullProgram = createMirrorProgram(TS_CASES.map((c) => fixture(c.file)));
+        narrowProgram = createFixtureProgram([path.join(FIXTURE_DIR, T25B.file)]);
+    }, 300_000);
+
+    it("行列の件数が pin と一致する", () => {
+        expect(TS_CASES).toHaveLength(EXPECTED_TS_CASE_COUNT);
+        // T25b (起点だけの program) を足した行列全体の件数。
+        expect(TS_CASES.length + 1).toBe(EXPECTED_TS_MATRIX_COUNT);
+    });
+
+    it("見本の実在集合と行列の参照集合が完全一致する", () => {
+        const onDisk = fs
+            .readdirSync(FIXTURE_DIR)
+            .filter((f) => f.endsWith(".ts"))
+            .sort();
+        const referenced = [...new Set([...TS_CASES.map((c) => c.file), ...TS_AUXILIARY_FIXTURES])].sort();
+        expect(onDisk).toEqual(referenced);
+    });
+
+    it("program-fixtures の実在集合と宣言が完全一致する", () => {
+        const onDisk = fs
+            .readdirSync(PROGRAM_FIXTURE_DIR)
+            .filter((f) => f.endsWith(".ts"))
+            .sort();
+        expect(onDisk).toEqual([...PROGRAM_FIXTURES].sort());
+    });
+
+    it.each(TS_CASES)("$id: $file::$declaration", (testCase) => {
+        const read = (): ReadonlySet<string> =>
+            readTsUnionValues(requireFullProgram(), fixture(testCase.file), testCase.declaration);
+
+        if (testCase.accepts === undefined) {
+            expect(read).toThrow(EnumTsSyncError);
+            expect(read).toThrow(testCase.reason);
+            return;
+        }
+        expect([...read()].sort()).toEqual([...testCase.accepts].sort());
+    });
+
+    it("T25b: 起点だけの program では拡張が載らず値が減る", () => {
+        const values = readTsUnionValues(requireNarrowProgram(), fixture(T25B.file), T25B.declaration);
+        expect([...values].sort()).toEqual([...T25B.accepts].sort());
+    });
+});
+
+interface PhpCase {
+    readonly id: string;
+    /** 見本の PHP ソース (文字列で書く)。 */
+    readonly source: string;
+    /** 語幹の照合に使うファイル名。 */
+    readonly fileName: string;
+    readonly accepts: readonly string[] | undefined;
+    readonly reason?: string;
+}
+
+/** よく使う前置き (見本を短く保つ)。 */
+const HEAD = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Enums;\n\n";
+
+const php = (body: string): string => HEAD + body;
+
+/** 逆斜線を n 個だけ並べた文字列 (見本の段数を取り違えないための唯一の作り方)。 */
+const backslashes = (n: number): string => "\\".repeat(n);
+
+const PHP_CASES: readonly PhpCase[] = [
+    {
+        id: "P1",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'a';\n    case B = 'b';\n    case C = 'c';\n}\n"),
+        accepts: ["a", "b", "c"],
+    },
+    {
+        id: "P2",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    // case Fake = 'x';\n    /* case Ghost = 'y'; */\n    case A = 'a';\n}\n"),
+        accepts: ["a"],
+    },
+    {
+        id: "P3",
+        fileName: "X.php",
+        source: php(
+            "enum X: string\n{\n    case A = 'a';\n\n    public function f(int $n): string\n    {\n        switch ($n) {\n            case 1:\n                return 'one';\n            default:\n                return 'other';\n        }\n    }\n}\n",
+        ),
+        accepts: ["a"],
+    },
+    {
+        id: "P4",
+        fileName: "X.php",
+        source: php(
+            "enum X: string\n{\n    case A = 'a';\n\n    public function label(): string\n    {\n        return match ($this) {\n            self::A => 'ラベル',\n        };\n    }\n}\n",
+        ),
+        accepts: ["a"],
+    },
+    {
+        id: "P5",
+        fileName: "X.php",
+        source: php(
+            "enum X: string\n{\n    case A = 'a';\n\n    public function make(): object\n    {\n        return new class\n        {\n            public string $v = 'inner';\n        };\n    }\n}\n",
+        ),
+        accepts: ["a"],
+    },
+    {
+        id: "P6",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'a{b}c';\n    case B = 'plain';\n}\n"),
+        accepts: ["a{b}c", "plain"],
+    },
+    {
+        id: "P7",
+        fileName: "X.php",
+        source: php("#[SomeAttribute]\nenum X: string\n{\n    #[Another]\n    case A = 'a';\n}\n"),
+        accepts: ["a"],
+    },
+    {
+        id: "P8",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    # case Fake = 'x';\n    case A = 'a';\n}\n"),
+        accepts: ["a"],
+    },
+    {
+        id: "P9",
+        fileName: "X.php",
+        source: php("enum X: string implements Foo, Bar\n{\n    case A = 'a';\n}\n"),
+        accepts: ["a"],
+    },
+    {
+        id: "P10",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    private const PREFIX = 'p';\n\n    case A = 'a';\n}\n"),
+        accepts: ["a"],
+    },
+    {
+        id: "P11",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'a';\n    CASE B = 'b';\n    Case C = 'c';\n}\n"),
+        accepts: ["a", "b", "c"],
+    },
+    {
+        id: "P12",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    private const PREFIX = 'p';\n\n    case A = self::PREFIX.'a';\n}\n"),
+        accepts: undefined,
+        reason: "受理する書き方に一致しません",
+    },
+    {
+        id: "P13",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'it\\'s';\n}\n"),
+        accepts: undefined,
+        reason: "受理する書き方に一致しません",
+    },
+    {
+        id: "P14",
+        fileName: "X.php",
+        source: php('enum X: string\n{\n    case A = "pre{$x}";\n}\n'),
+        accepts: undefined,
+        reason: "受理する書き方に一致しません",
+    },
+    {
+        id: "P15",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A =\n        'a';\n}\n"),
+        accepts: undefined,
+        reason: "受理する書き方に一致しません",
+    },
+    {
+        id: "P16",
+        fileName: "X.php",
+        source: php("enum X: int\n{\n    case A = 1;\n}\n"),
+        accepts: undefined,
+        reason: "backing 型が string ではありません",
+    },
+    {
+        id: "P17",
+        fileName: "X.php",
+        source: php("enum X\n{\n    case A;\n}\n"),
+        accepts: undefined,
+        reason: "backing 型がありません",
+    },
+    {
+        id: "P18",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'a';\n}\n\nenum Y: string\n{\n    case B = 'b';\n}\n"),
+        accepts: undefined,
+        reason: "enum 宣言が 2 件あります",
+    },
+    {
+        id: "P19",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    public function f(): int\n    {\n        return 1;\n    }\n}\n"),
+        accepts: undefined,
+        reason: "case を 1 件も取り出せません",
+    },
+    {
+        id: "P20",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = <<<'TXT'\n        a\n        TXT;\n}\n"),
+        accepts: undefined,
+        reason: "ヒアドキュメント",
+    },
+    {
+        id: "P21",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    // <<<TXT は注釈の中\n    case A = 'a <<<TXT';\n}\n"),
+        accepts: ["a <<<TXT"],
+    },
+    {
+        id: "P22",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'a';\n\n    public function f(): string\n    {\n        return `ls`;\n    }\n}\n"),
+        accepts: undefined,
+        reason: "バッククォート",
+    },
+    {
+        id: "P23",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'a';\n}\n?>\n<p>html</p>\n"),
+        accepts: undefined,
+        reason: "PHP の閉じタグ",
+    },
+    {
+        id: "P24",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'a';\n}\n// ?>\n<p>html</p>\n"),
+        accepts: undefined,
+        reason: "PHP の閉じタグ",
+    },
+    {
+        id: "P25",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'a';\n}\n# ?>\n<p>html</p>\n"),
+        accepts: undefined,
+        reason: "PHP の閉じタグ",
+    },
+    {
+        id: "P26",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    /* ?> はブロック注釈の中 */\n    case A = 'a ?> b';\n}\n"),
+        accepts: ["a ?> b"],
+    },
+    {
+        id: "P27",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'a';\n}\n\n__halt_compiler();\nrest\n"),
+        accepts: undefined,
+        reason: "__halt_compiler",
+    },
+    {
+        id: "P28",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'a';\n\n    public function f(): string\n    {\n        return 'unterminated;\n    }\n}\n"),
+        accepts: undefined,
+        reason: "単一引用符の文字列が閉じていません",
+    },
+    {
+        id: "P29",
+        fileName: "X.php",
+        source: php('enum X: string\n{\n    case A = \'a\';\n\n    public function f(): string\n    {\n        return "unterminated;\n    }\n}\n'),
+        accepts: undefined,
+        reason: "二重引用符の文字列が閉じていません",
+    },
+    {
+        id: "P30",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'a';\n    /* 閉じない注釈\n}\n"),
+        accepts: undefined,
+        reason: "ブロック注釈が閉じていません",
+    },
+    {
+        id: "P31",
+        // 逆斜線が**奇数**個 → 直後の引用符は逃がされ、文字列は続く。
+        // 走査が偶奇を取り違えるとこの後ろの case A を食い潰すので、戻り値の差として観測できる。
+        fileName: "X.php",
+        source: php(
+            "enum X: string\n{\n    public function f(): string\n    {\n        return 'odd " +
+                backslashes(1) +
+                "'';\n    }\n\n    case A = 'a';\n}\n",
+        ),
+        accepts: ["a"],
+    },
+    {
+        id: "P32",
+        fileName: "X.php",
+        source: php(
+            "enum X: string\n{\n    public function f(): string\n    {\n        return 'even " +
+                backslashes(2) +
+                "';\n    }\n\n    case A = 'a';\n}\n",
+        ),
+        accepts: ["a"],
+    },
+    {
+        id: "P33",
+        fileName: "X.php",
+        source: php(
+            "enum X: string\n{\n    public function f(): string\n    {\n        return 'three " +
+                backslashes(3) +
+                "'';\n    }\n\n    case A = 'a';\n}\n",
+        ),
+        accepts: ["a"],
+    },
+    {
+        id: "P34",
+        fileName: "Other.php",
+        source: php("enum X: string\n{\n    case A = 'a';\n}\n"),
+        accepts: undefined,
+        reason: "ファイル名の語幹",
+    },
+    {
+        id: "P35",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'a';\n    case A = 'b';\n}\n"),
+        accepts: undefined,
+        reason: "case の名前が重複",
+    },
+    {
+        id: "P36",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    case A = 'a';\n    case B = 'a';\n}\n"),
+        accepts: undefined,
+        reason: "backing の値が重複",
+    },
+    {
+        id: "P37",
+        fileName: "X.php",
+        source: php("enum X: string\n{\n    // 補助面の文字 \u{1F600} を注釈に含む\n    case A = 'a';\n}\n"),
+        accepts: ["a"],
+    },
+    {
+        id: "P38",
+        fileName: "X.php",
+        source: php("enum X: string\r\n{\r\n    case A = 'a';\r\n    case B = 'b';\r\n}\r\n"),
+        accepts: ["a", "b"],
+    },
+];
+
+/** 行列の件数の pin。 */
+const EXPECTED_PHP_CASE_COUNT = 38;
+
+describe("PHP 側抽出器の負例行列", () => {
+    it("行列の件数が pin と一致する", () => {
+        expect(PHP_CASES).toHaveLength(EXPECTED_PHP_CASE_COUNT);
+    });
+
+    /**
+     * P31〜P33 は逆斜線の段数を取り違えやすい (TypeScript の文字列で PHP を書くと
+     * 逃がしが二重にかかる)。**抽出器へ渡る PHP のソースそのもの**の逆斜線の個数を
+     * 先に pin して、期待値が意図どおりの見本に対するものであることを確かめる。
+     */
+    it("P31〜P33 の見本に含まれる逆斜線の個数が意図どおり", () => {
+        const count = (id: string): number => {
+            const found = PHP_CASES.find((c) => c.id === id);
+            if (found === undefined) throw new Error(`見本 ${id} がありません`);
+            return [...found.source].filter((ch) => ch === "\\").length;
+        };
+        // namespace App\Enums; の 1 個を含むので、本体の段数 + 1 になる。
+        expect(count("P31")).toBe(2);
+        expect(count("P32")).toBe(3);
+        expect(count("P33")).toBe(4);
+    });
+
+    it.each(PHP_CASES)("$id", (testCase) => {
+        const read = (): ReadonlySet<string> => readPhpEnumValuesFromText(testCase.source, testCase.fileName);
+
+        if (testCase.accepts === undefined) {
+            expect(read).toThrow(EnumTsSyncError);
+            expect(read).toThrow(testCase.reason);
+            return;
+        }
+        expect([...read()].sort()).toEqual([...testCase.accepts].sort());
+    });
+
+    /**
+     * ファイルから読む包み (`readPhpEnumValues`) の経路を、見本を増やさずに通す。
+     * `MemberRoleState` は `match` 式を持つ実例、`Manual/RenderKind` は素直な実例。
+     */
+    it("実在の enum ファイルを相対パスから読める", () => {
+        expect([...readPhpEnumValues("app/Enums/MemberRoleState.php")].sort()).toEqual([
+            "admin",
+            "editor",
+            "owner",
+            "shooter",
+            "unassigned",
+        ]);
+        expect([...readPhpEnumValues("app/Enums/Manual/RenderKind.php")].sort()).toEqual(["preview", "render"]);
+    });
+
+    it("実在しないパスは理由付きで落ちる", () => {
+        expect(() => readPhpEnumValues("app/Enums/NoSuchEnum.php")).toThrow(EnumTsSyncError);
+    });
+});
diff --git a/tests/js/architecture/enum-ts-sync.test.ts b/tests/js/architecture/enum-ts-sync.test.ts
new file mode 100644
index 0000000..8ce0323
--- /dev/null
+++ b/tests/js/architecture/enum-ts-sync.test.ts
@@ -0,0 +1,350 @@
+/**
+ * PHP 列挙 ⇔ TypeScript 値域の汎用同期 gate (家系の裁定 AG-099 前半)。
+ *
+ * 目録に登録した写しについて、PHP の文字列付き列挙の値集合と TS の型別名が解決する
+ * 値集合が**完全一致**することを固定する。写しが片方だけ増えると、画面の分岐に
+ * 「どこにも当たらない値」が生まれて無言の描画漏れになる。
+ *
+ * **登録の仕方**: PHP の列挙の値を TS の型別名で受ける箇所を作ったら、
+ * `ENUM_TS_MIRRORS` へ 1 行足し、`EXPECTED_MIRROR_COUNT` を 1 増やす。
+ * 個別の検査ファイルは**増やさない** (増殖を止めるのが本 gate の目的)。
+ *
+ * **登録していない写しは 1 件も検査していない**。全数走査による既定拒否の分類と
+ * 逆走査は AG-099 後半の担当で、本 gate には無い (`docs/template-divergence.md` D27)。
+ * 現時点で意図的に登録していないものと理由:
+ *
+ * | TS 宣言 | 理由 |
+ * |---|---|
+ * | `types/manual.ts::SelectableTakeStatus` | 「選択できるテイクの状態」という部分集合の意図。今は `TakeStatus` と全一致だが完全一致で縛ると意図と食い違う |
+ * | `types/dashboard.ts::DashboardJobStatus` | `JobStatus` の真部分集合 (進行中のみ) |
+ * | `types/capture.ts::CaptureProgress` ほか画面側だけの語彙 | 対応する PHP 列挙が無い |
+ *
+ * **レーンの非対称**: 本 gate は `pnpm test` (CI の frontend job) でだけ走る。
+ * `composer test` だけでは値集合の同期は検証されない。
+ * 保証しないものの正本は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期。
+ */
+import { beforeAll, describe, expect, it } from "vitest";
+import fs from "node:fs";
+import path from "node:path";
+import { EnumTsSyncError } from "../support/enum-ts-sync/errors";
+import { REPO_ROOT, createMirrorProgram, type MirrorProgram } from "../support/enum-ts-sync/program";
+import { readTsUnionValues } from "../support/enum-ts-sync/ts-value-sets";
+import { readPhpEnumValues } from "../support/enum-ts-sync/php-enums";
+
+interface EnumTsMirror {
+    /** リポジトリルートからの PHP 列挙ファイルの相対パス (`app/` 配下の `*.php`)。 */
+    readonly php: string;
+    /** リポジトリルートからの TS ファイルの相対パス (`resources/js/` 配下の `*.ts`)。 */
+    readonly ts: string;
+    /** TS 側の型別名の名前。 */
+    readonly declaration: string;
+    /** この写しが要る理由 (画面のどこが値で分岐するか)。 */
+    readonly note: string;
+}
+
+/**
+ * 写しの目録。
+ * `note` に最小文字数は課さない — 本目録は**免除の申告ではなく登録**であり、
+ * 「検査から外す」判断ではないため (免除目録が 30 文字を課すのとは重さが違う)。
+ */
+const ENUM_TS_MIRRORS = [
+    {
+        php: "app/Enums/Manual/VideoManualStatus.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "VideoManualStatus",
+        note: "詳細画面とダッシュボードが制作状態 5 値で CTA を分岐する",
+    },
+    {
+        php: "app/Enums/Manual/ManualProgress.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "ManualProgress",
+        note: "一覧の絞り込みと行バッジが 3 値で分岐する",
+    },
+    {
+        php: "app/Enums/Manual/RenderKind.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "RenderKind",
+        note: "プレビューと完成動画で受け取り口の扱いを分ける",
+    },
+    {
+        php: "app/Enums/Manual/RenderStep.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "RenderStep",
+        note: "合成の進捗表示が段の値で分岐する",
+    },
+    {
+        php: "app/Enums/Manual/RenderErrorCode.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "RenderErrorCode",
+        note: "失敗時の案内文を符号で選ぶ",
+    },
+    {
+        php: "app/Enums/Manual/RenderConflictType.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "RenderConflictType",
+        note: "409 の理由ごとに画面の受け方を変える",
+    },
+    {
+        php: "app/Enums/Manual/ScenarioVerdict.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "ScenarioVerdict",
+        note: "台本の判定バッジが 3 値で分岐する",
+    },
+    {
+        php: "app/Enums/Manual/ScenarioRuleCode.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "ScenarioRuleCode",
+        note: "台本の指摘一覧が規則の符号で文言を選ぶ",
+    },
+    {
+        php: "app/Enums/Manual/JobStatus.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "AnalysisJobStatus",
+        note: "解析ジョブの進行表示が状態で分岐する (TS 側は別名)",
+    },
+    {
+        php: "app/Enums/Manual/MaterialType.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "CutMaterialType",
+        note: "カット編集が素材種別で入力欄を切り替える",
+    },
+    {
+        php: "app/Enums/Manual/MaterialType.php",
+        ts: "resources/js/types/capture.ts",
+        declaration: "MaterialType",
+        note: "撮影 PWA 側の写し。PC 側と types を分けてあるので両方 pin する",
+    },
+    {
+        php: "app/Enums/Notification/NotificationType.php",
+        ts: "resources/js/types/notification.ts",
+        declaration: "NotificationType",
+        note: "通知一覧がアイコンと文言を種別で選ぶ",
+    },
+    {
+        php: "app/Enums/Billing/OnboardingBillingState.php",
+        ts: "resources/js/types/billing.ts",
+        declaration: "BillingStateValue",
+        note: "契約画面とダッシュボードの両方が契約状態で分岐する",
+    },
+    {
+        php: "app/Enums/AccountDeletionBlockerAction.php",
+        ts: "resources/js/types/account.ts",
+        declaration: "AccountDeletionBlockerAction",
+        note: "退会ガードの「次の一手」で導線を分岐する",
+    },
+    {
+        php: "app/Enums/PlanCode.php",
+        ts: "resources/js/types/Auth.ts",
+        declaration: "PlanCode",
+        note: "契約プランの符号で表示と導線を分岐する",
+    },
+    {
+        php: "app/Enums/AdminConsoleRole.php",
+        ts: "resources/js/types/admin.ts",
+        declaration: "ConsoleRole",
+        note: "ユーザー管理のロール遷移コマンド (TS 側は別名)",
+    },
+    {
+        php: "app/Enums/MemberRoleState.php",
+        ts: "resources/js/types/admin.ts",
+        declaration: "MemberRoleState",
+        note: "ユーザー管理の表示状態 5 値。TS 側は ConsoleRole の別名参照を含む",
+    },
+    {
+        php: "app/Enums/OrganizationRole.php",
+        ts: "resources/js/lib/shared-props.ts",
+        declaration: "OrganizationRoleValue",
+        note: "共有 props の組織ロールで画面の権限表示を分岐する",
+    },
+    {
+        php: "app/Enums/Billing/BillingFeedbackKind.php",
+        ts: "resources/js/types/billing.ts",
+        declaration: "BillingFeedbackKind",
+        note: "課金画面の通知種別で文言を選ぶ",
+    },
+    {
+        php: "app/Enums/Billing/PurchaseFormState.php",
+        ts: "resources/js/types/billing.ts",
+        declaration: "PurchaseFormStateValue",
+        note: "購入フォームの状態で入力欄の初期値を変える",
+    },
+    {
+        php: "app/Enums/Manual/TakeStatus.php",
+        ts: "resources/js/types/capture.ts",
+        declaration: "TakeStatus",
+        note: "撮影テイクの状態で再撮影・採用の可否表示を分岐する",
+    },
+    {
+        php: "app/Enums/Dashboard/DashboardState.php",
+        ts: "resources/js/types/dashboard.ts",
+        declaration: "DashboardState",
+        note: "ダッシュボードの初期状態で案内を切り替える",
+    },
+    {
+        php: "app/Enums/Dashboard/DashboardRole.php",
+        ts: "resources/js/types/dashboard.ts",
+        declaration: "DashboardRole",
+        note: "ダッシュボードの役割で出す導線を変える",
+    },
+    {
+        php: "app/Enums/Manual/AnalysisStep.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "AnalysisStep",
+        note: "解析の進捗表示が段の値で分岐する",
+    },
+    {
+        php: "app/Enums/Manual/AnalysisConflictType.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "AnalysisConflictType",
+        note: "解析要求の 409 の理由ごとに案内を変える",
+    },
+    {
+        php: "app/Enums/Manual/ScenarioConflictType.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "ScenarioConflictType",
+        note: "台本保存の 409 の理由ごとに案内を変える",
+    },
+    {
+        php: "app/Enums/Manual/ManualSortOption.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "ManualSortOption",
+        note: "一覧の並び順の選択肢を URL クエリと突き合わせる",
+    },
+] as const satisfies readonly EnumTsMirror[];
+
+/**
+ * 目録の件数の pin。増えても減っても赤くする (写しが黙って消えるのを防ぐ)。
+ * **これは網羅の証明ではない** — 登録していない写しは 1 件も検査していない。
+ */
+const EXPECTED_MIRROR_COUNT = 27;
+
+const APP_ROOT = path.join(REPO_ROOT, "app");
+const JS_ROOT = path.join(REPO_ROOT, "resources", "js");
+
+/** `root` の**配下**にあるか (兄弟ディレクトリを通さないよう区切りまで含めて見る)。 */
+const isUnder = (absolute: string, root: string): boolean => absolute.startsWith(root + path.sep);
+
+/**
+ * 目録の行の体裁を検査する純関数。
+ * **program を作る前に呼ぶ** — 後回しにすると、検査の外にあるファイルを
+ * 「赤くなる前に読んでしまう」ことになる。
+ */
+export const validateMirrors = (rows: readonly EnumTsMirror[]): void => {
+    const seen = new Set<string>();
+    const seenReal = new Set<string>();
+
+    for (const row of rows) {
+        const where = `${row.php} ⇔ ${row.ts}::${row.declaration}`;
+
+        for (const relative of [row.php, row.ts]) {
+            if (path.isAbsolute(relative)) throw new EnumTsSyncError(where, `絶対パスは登録できません: ${relative}`);
+            if (relative.includes("\\")) throw new EnumTsSyncError(where, `逆斜線を含むパスは登録できません: ${relative}`);
+            const segments = relative.split("/");
+            if (segments.some((s) => s === "" || s === "." || s === "..")) {
+                throw new EnumTsSyncError(where, `. / .. / 空の区間を含むパスは登録できません: ${relative}`);
+            }
+        }
+
+        if (!row.php.endsWith(".php")) throw new EnumTsSyncError(where, `php は .php で終わること: ${row.php}`);
+        if (!row.ts.endsWith(".ts")) throw new EnumTsSyncError(where, `ts は .ts で終わること: ${row.ts}`);
+        if (row.note.trim() === "") throw new EnumTsSyncError(where, "note が空です");
+
+        const phpAbs = path.resolve(REPO_ROOT, row.php);
+        const tsAbs = path.resolve(REPO_ROOT, row.ts);
+        if (!isUnder(phpAbs, APP_ROOT)) throw new EnumTsSyncError(where, `php は app/ 配下だけ: ${row.php}`);
+        if (!isUnder(tsAbs, JS_ROOT)) throw new EnumTsSyncError(where, `ts は resources/js/ 配下だけ: ${row.ts}`);
+
+        for (const [absolute, root, label] of [
+            [phpAbs, APP_ROOT, row.php],
+            [tsAbs, JS_ROOT, row.ts],
+        ] as const) {
+            if (!fs.existsSync(absolute)) throw new EnumTsSyncError(where, `登録されたファイルが実在しません: ${label}`);
+            if (!fs.statSync(absolute).isFile()) throw new EnumTsSyncError(where, `通常ファイルではありません: ${label}`);
+            // symlink 経由で走査範囲の外へ抜けられないようにする
+            if (!isUnder(fs.realpathSync(absolute), root)) {
+                throw new EnumTsSyncError(where, `symlink の解決先が走査範囲の外です: ${label}`);
+            }
+        }
+
+        const key = `${row.ts}::${row.declaration}`;
+        if (seen.has(key)) throw new EnumTsSyncError(where, `同じ TS 宣言が 2 回登録されています: ${key}`);
+        seen.add(key);
+
+        const realKey = `${fs.realpathSync(tsAbs)}::${row.declaration}`;
+        if (seenReal.has(realKey)) {
+            throw new EnumTsSyncError(where, `symlink 越しに同じ TS 宣言が 2 回登録されています: ${realKey}`);
+        }
+        seenReal.add(realKey);
+    }
+};
+
+let mirrorProgram: MirrorProgram | undefined;
+
+/** 初期化されていなければ落ちる (definite assignment の `!` を使わない)。 */
+const requireMirrorProgram = (): MirrorProgram => {
+    if (mirrorProgram === undefined) throw new EnumTsSyncError("mirror program", "初期化されていません");
+    return mirrorProgram;
+};
+
+describe("PHP 列挙 ⇔ TS 値域の同期", () => {
+    beforeAll(() => {
+        validateMirrors(ENUM_TS_MIRRORS);
+        mirrorProgram = createMirrorProgram([...new Set(ENUM_TS_MIRRORS.map((m) => m.ts))]);
+    }, 300_000);
+
+    it("目録の件数が pin と一致する", () => {
+        expect(ENUM_TS_MIRRORS).toHaveLength(EXPECTED_MIRROR_COUNT);
+    });
+
+    it("目録の行の体裁が守られている", () => {
+        expect(() => validateMirrors(ENUM_TS_MIRRORS)).not.toThrow();
+    });
+
+    it.each(ENUM_TS_MIRRORS)("$php ⇔ $ts::$declaration の値集合が一致する", (mirror) => {
+        const phpValues = readPhpEnumValues(mirror.php);
+        const tsValues = readTsUnionValues(requireMirrorProgram(), mirror.ts, mirror.declaration);
+
+        // 空 vs 空で素通りしないことを明示する (抽出器は空集合を返さないが、意図を残す)
+        expect(phpValues.size).toBeGreaterThan(0);
+        expect([...tsValues].sort()).toEqual([...phpValues].sort());
+    });
+});
+
+describe("validateMirrors() の負のコントロール", () => {
+    const valid: EnumTsMirror = {
+        php: "app/Enums/Manual/RenderKind.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "RenderKind",
+        note: "負のコントロール用の正常な行",
+    };
+
+    it("正常な行は通る", () => {
+        expect(() => validateMirrors([valid])).not.toThrow();
+    });
+
+    it("app/ の外の php は拒否する", () => {
+        expect(() => validateMirrors([{ ...valid, php: "config/app.php" }])).toThrow("app/ 配下だけ");
+    });
+
+    it("resources/js/ の外の ts は拒否する", () => {
+        expect(() => validateMirrors([{ ...valid, ts: "tests/js/setup.ts" }])).toThrow("resources/js/ 配下だけ");
+    });
+
+    it(".. を含むパスは拒否する", () => {
+        expect(() => validateMirrors([{ ...valid, php: "app/../app/Enums/Manual/RenderKind.php" }])).toThrow(
+            ". / .. / 空の区間",
+        );
+    });
+
+    it("実在しないファイルは拒否する", () => {
+        expect(() => validateMirrors([{ ...valid, php: "app/Enums/NoSuchEnum.php" }])).toThrow("実在しません");
+    });
+
+    it("同じ TS 宣言の二重登録は拒否する", () => {
+        expect(() => validateMirrors([valid, { ...valid, note: "別の理由" }])).toThrow("2 回登録されています");
+    });
+
+    it("note が空の行は拒否する", () => {
+        expect(() => validateMirrors([{ ...valid, note: "  " }])).toThrow("note が空です");
+    });
+});
diff --git a/tests/js/support/enum-ts-sync/errors.ts b/tests/js/support/enum-ts-sync/errors.ts
new file mode 100644
index 0000000..143b731
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/errors.ts
@@ -0,0 +1,12 @@
+/**
+ * PHP 列挙 ⇔ TypeScript 値域の抽出に失敗したことを表す例外。
+ *
+ * **空集合を返して失敗を表さない** (空 vs 空が一致して素通りするため)。
+ * 文面には必ず「対象の場所」と「落ちた理由」を入れる。
+ */
+export class EnumTsSyncError extends Error {
+    constructor(where: string, reason: string) {
+        super(`${where}: ${reason}`);
+        this.name = "EnumTsSyncError";
+    }
+}
diff --git a/tests/js/support/enum-ts-sync/fixtures/t01-plain-union.ts b/tests/js/support/enum-ts-sync/fixtures/t01-plain-union.ts
new file mode 100644
index 0000000..78c8cfe
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t01-plain-union.ts
@@ -0,0 +1 @@
+export type X = "a" | "b";
diff --git a/tests/js/support/enum-ts-sync/fixtures/t02-single-literal.ts b/tests/js/support/enum-ts-sync/fixtures/t02-single-literal.ts
new file mode 100644
index 0000000..89ca56d
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t02-single-literal.ts
@@ -0,0 +1 @@
+export type X = "only";
diff --git a/tests/js/support/enum-ts-sync/fixtures/t03-comment-noise.ts b/tests/js/support/enum-ts-sync/fixtures/t03-comment-noise.ts
new file mode 100644
index 0000000..e7da78d
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t03-comment-noise.ts
@@ -0,0 +1,6 @@
+/** 注釈の中の "ghost" という語は値ではない。 */
+// もう 1 つの "phantom" も同じ。
+export type X =
+    // "decoy" を挟む
+    | "a"
+    | "b";
diff --git a/tests/js/support/enum-ts-sync/fixtures/t04-open-string.ts b/tests/js/support/enum-ts-sync/fixtures/t04-open-string.ts
new file mode 100644
index 0000000..c82fb6a
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t04-open-string.ts
@@ -0,0 +1 @@
+export type X = "a" | "b" | (string & {});
diff --git a/tests/js/support/enum-ts-sync/fixtures/t05-number-member.ts b/tests/js/support/enum-ts-sync/fixtures/t05-number-member.ts
new file mode 100644
index 0000000..f1f478c
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t05-number-member.ts
@@ -0,0 +1 @@
+export type X = "a" | 1;
diff --git a/tests/js/support/enum-ts-sync/fixtures/t06-never.ts b/tests/js/support/enum-ts-sync/fixtures/t06-never.ts
new file mode 100644
index 0000000..cfe3c49
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t06-never.ts
@@ -0,0 +1 @@
+export type X = never;
diff --git a/tests/js/support/enum-ts-sync/fixtures/t07-absent.ts b/tests/js/support/enum-ts-sync/fixtures/t07-absent.ts
new file mode 100644
index 0000000..49ea16b
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t07-absent.ts
@@ -0,0 +1 @@
+export type Present = "a";
diff --git a/tests/js/support/enum-ts-sync/fixtures/t08-duplicate-alias.ts b/tests/js/support/enum-ts-sync/fixtures/t08-duplicate-alias.ts
new file mode 100644
index 0000000..b5b816b
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t08-duplicate-alias.ts
@@ -0,0 +1,2 @@
+export type X = "a";
+type X = "b";
diff --git a/tests/js/support/enum-ts-sync/fixtures/t09-const-array.ts b/tests/js/support/enum-ts-sync/fixtures/t09-const-array.ts
new file mode 100644
index 0000000..bcd4298
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t09-const-array.ts
@@ -0,0 +1 @@
+export const X = ["a"] as const;
diff --git a/tests/js/support/enum-ts-sync/fixtures/t10a-other.ts b/tests/js/support/enum-ts-sync/fixtures/t10a-other.ts
new file mode 100644
index 0000000..43c7fa3
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t10a-other.ts
@@ -0,0 +1 @@
+export type Y = "y1" | "y2";
diff --git a/tests/js/support/enum-ts-sync/fixtures/t10a-target.ts b/tests/js/support/enum-ts-sync/fixtures/t10a-target.ts
new file mode 100644
index 0000000..e951dfc
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t10a-target.ts
@@ -0,0 +1,3 @@
+import type { Y } from "./t10a-other";
+
+export type X = Y | "c";
diff --git a/tests/js/support/enum-ts-sync/fixtures/t10b-path-alias.ts b/tests/js/support/enum-ts-sync/fixtures/t10b-path-alias.ts
new file mode 100644
index 0000000..9b60412
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t10b-path-alias.ts
@@ -0,0 +1,3 @@
+import type { DashboardRole } from "@/types/dashboard";
+
+export type X = DashboardRole | "extra";
diff --git a/tests/js/support/enum-ts-sync/fixtures/t11-indexed-access.ts b/tests/js/support/enum-ts-sync/fixtures/t11-indexed-access.ts
new file mode 100644
index 0000000..b81378b
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t11-indexed-access.ts
@@ -0,0 +1 @@
+export type X = { a: "p"; b: "q" }["a" | "b"];
diff --git a/tests/js/support/enum-ts-sync/fixtures/t12-keyof-typeof.ts b/tests/js/support/enum-ts-sync/fixtures/t12-keyof-typeof.ts
new file mode 100644
index 0000000..097cc1c
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t12-keyof-typeof.ts
@@ -0,0 +1,3 @@
+const O = { a: 1, b: 2 } as const;
+
+export type X = keyof typeof O;
diff --git a/tests/js/support/enum-ts-sync/fixtures/t13-value-of.ts b/tests/js/support/enum-ts-sync/fixtures/t13-value-of.ts
new file mode 100644
index 0000000..112f69a
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t13-value-of.ts
@@ -0,0 +1,3 @@
+const O = { a: "va", b: "vb" } as const;
+
+export type X = (typeof O)[keyof typeof O];
diff --git a/tests/js/support/enum-ts-sync/fixtures/t14-lowercase.ts b/tests/js/support/enum-ts-sync/fixtures/t14-lowercase.ts
new file mode 100644
index 0000000..517d392
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t14-lowercase.ts
@@ -0,0 +1 @@
+export type X = Lowercase<"A" | "B">;
diff --git a/tests/js/support/enum-ts-sync/fixtures/t15-instantiated-conditional.ts b/tests/js/support/enum-ts-sync/fixtures/t15-instantiated-conditional.ts
new file mode 100644
index 0000000..473078d
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t15-instantiated-conditional.ts
@@ -0,0 +1,3 @@
+type Pick2<T> = T extends "a" | "b" ? T : never;
+
+export type X = Pick2<"a" | "b" | "c">;
diff --git a/tests/js/support/enum-ts-sync/fixtures/t16-generic-conditional.ts b/tests/js/support/enum-ts-sync/fixtures/t16-generic-conditional.ts
new file mode 100644
index 0000000..168a12e
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t16-generic-conditional.ts
@@ -0,0 +1 @@
+export type X<T> = T extends string ? "a" : "b";
diff --git a/tests/js/support/enum-ts-sync/fixtures/t17-closed-template.ts b/tests/js/support/enum-ts-sync/fixtures/t17-closed-template.ts
new file mode 100644
index 0000000..1553d40
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t17-closed-template.ts
@@ -0,0 +1 @@
+export type X = `a${"1" | "2"}`;
diff --git a/tests/js/support/enum-ts-sync/fixtures/t18-open-template.ts b/tests/js/support/enum-ts-sync/fixtures/t18-open-template.ts
new file mode 100644
index 0000000..7d46acf
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t18-open-template.ts
@@ -0,0 +1 @@
+export type X = `x${string}`;
diff --git a/tests/js/support/enum-ts-sync/fixtures/t19-string-enum.ts b/tests/js/support/enum-ts-sync/fixtures/t19-string-enum.ts
new file mode 100644
index 0000000..bad4ae3
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t19-string-enum.ts
@@ -0,0 +1,6 @@
+enum E {
+    A = "a",
+    B = "b",
+}
+
+export type X = E;
diff --git a/tests/js/support/enum-ts-sync/fixtures/t20-numeric-enum.ts b/tests/js/support/enum-ts-sync/fixtures/t20-numeric-enum.ts
new file mode 100644
index 0000000..40aac99
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t20-numeric-enum.ts
@@ -0,0 +1,6 @@
+enum E {
+    A,
+    B,
+}
+
+export type X = E;
diff --git a/tests/js/support/enum-ts-sync/fixtures/t21-unique-symbol.ts b/tests/js/support/enum-ts-sync/fixtures/t21-unique-symbol.ts
new file mode 100644
index 0000000..5df316d
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t21-unique-symbol.ts
@@ -0,0 +1,3 @@
+declare const s: unique symbol;
+
+export type X = typeof s;
diff --git a/tests/js/support/enum-ts-sync/fixtures/t22-circular.ts b/tests/js/support/enum-ts-sync/fixtures/t22-circular.ts
new file mode 100644
index 0000000..de0b3a3
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t22-circular.ts
@@ -0,0 +1,2 @@
+export type X = Y;
+type Y = X;
diff --git a/tests/js/support/enum-ts-sync/fixtures/t23-unresolved-import.ts b/tests/js/support/enum-ts-sync/fixtures/t23-unresolved-import.ts
new file mode 100644
index 0000000..06637b5
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t23-unresolved-import.ts
@@ -0,0 +1,3 @@
+import type { Missing } from "./no-such-module-here";
+
+export type X = Missing;
diff --git a/tests/js/support/enum-ts-sync/fixtures/t24-source-duplicate.ts b/tests/js/support/enum-ts-sync/fixtures/t24-source-duplicate.ts
new file mode 100644
index 0000000..6e0f077
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t24-source-duplicate.ts
@@ -0,0 +1 @@
+export type X = "a" | "a";
diff --git a/tests/js/support/enum-ts-sync/fixtures/t25-target.ts b/tests/js/support/enum-ts-sync/fixtures/t25-target.ts
new file mode 100644
index 0000000..2696690
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/t25-target.ts
@@ -0,0 +1,3 @@
+import type { Registry } from "../program-fixtures/registry-base";
+
+export type X = Registry[keyof Registry];
diff --git a/tests/js/support/enum-ts-sync/php-enums.ts b/tests/js/support/enum-ts-sync/php-enums.ts
new file mode 100644
index 0000000..d564ec9
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/php-enums.ts
@@ -0,0 +1,310 @@
+/**
+ * PHP 側の値集合を読む (本 gate 専用の最小の字句走査)。
+ *
+ * **保証するのは次の 1 点だけである**:
+ * > 抽出の対象として認識した enum 宣言・case 宣言・禁止した字句状態については、
+ * > 受理するか、理由付きの例外になる。
+ *
+ * PHP ファイル全体の構文の妥当性・名前空間・オートロード・完全修飾名の正しさ・
+ * メソッド本体の妥当性は**検証しない** (それらは `composer test` / PHPStan の担当)。
+ * また PHP が受理する構文をすべて受理するわけではない (閉じタグ・バッククォート・
+ * ヒアドキュメントは拒否する)。正本は `docs/architecture.md`
+ * §PHP 列挙と TypeScript 値域の同期。
+ */
+import fs from "node:fs";
+import path from "node:path";
+import { EnumTsSyncError } from "./errors";
+import { REPO_ROOT } from "./program";
+
+/** 字句の状態。 */
+const CODE = 0;
+const SINGLE = 1;
+const DOUBLE = 2;
+const LINE = 3;
+const BLOCK = 4;
+
+interface ScanResult {
+    /** 元と**同じ長さ** (UTF-16 の符号単位数) の無害化した写し。 */
+    readonly sanitized: string;
+    /** その位置がコード状態か。 */
+    readonly isCode: Uint8Array;
+    /** その位置の波括弧の深さ (閉じ波括弧は外側の深さになる)。 */
+    readonly depth: Int32Array;
+}
+
+/**
+ * 文字列・注釈を空白へ潰しつつ、波括弧の深さを数える。
+ *
+ * - **引用符の終端は逆斜線の偶奇で決まる**。「直前が `\` でない」では不十分で、
+ *   `'…\\'` の終端を文字列の中と誤認すると以降の case を丸ごと食い潰す。
+ *   逆斜線を見たら次の 1 文字ごと飛ばす形で偶奇を自然に実装する。
+ * - 改行は `\r` も `\n` もそのまま残す (CRLF で位置がずれない)。
+ * - 走査は `charCodeAt` ではなく添字の 1 進みで回す (符号位置単位で回すと
+ *   補助面の文字で位置がずれる)。
+ */
+const scan = (source: string, where: string): ScanResult => {
+    const length = source.length;
+    const out: string[] = new Array<string>(length);
+    const isCode = new Uint8Array(length);
+    const depth = new Int32Array(length);
+
+    let state: number = CODE;
+    let braceDepth = 0;
+    let index = 0;
+
+    /** 中身を潰す (改行だけは残す)。 */
+    const blank = (at: number): void => {
+        const ch = source[at];
+        out[at] = ch === "\n" || ch === "\r" ? ch : " ";
+    };
+
+    while (index < length) {
+        const ch = source[index];
+        const next = index + 1 < length ? source[index + 1] : "";
+
+        if (state === CODE) {
+            if (ch === "`") {
+                throw new EnumTsSyncError(where, "バッククォート文字列は受理しません");
+            }
+            if (ch === "?" && next === ">") {
+                throw new EnumTsSyncError(where, "PHP の閉じタグ (?>) は受理しません");
+            }
+            if (ch === "<" && next === "<" && source[index + 2] === "<") {
+                throw new EnumTsSyncError(where, "ヒアドキュメント / ナウドキュメント (<<<) は受理しません");
+            }
+            if (ch === "_" && source.slice(index, index + 15).toLowerCase() === "__halt_compiler") {
+                throw new EnumTsSyncError(where, "__halt_compiler は受理しません");
+            }
+
+            if (ch === "/" && next === "/") {
+                depth[index] = braceDepth;
+                depth[index + 1] = braceDepth;
+                out[index] = " ";
+                out[index + 1] = " ";
+                state = LINE;
+                index += 2;
+                continue;
+            }
+            // `#[` は属性なのでコードのまま。それ以外の `#` は行注釈。
+            if (ch === "#" && next !== "[") {
+                depth[index] = braceDepth;
+                out[index] = " ";
+                state = LINE;
+                index += 1;
+                continue;
+            }
+            if (ch === "/" && next === "*") {
+                depth[index] = braceDepth;
+                depth[index + 1] = braceDepth;
+                out[index] = " ";
+                out[index + 1] = " ";
+                state = BLOCK;
+                index += 2;
+                continue;
+            }
+
+            isCode[index] = 1;
+            if (ch === "{") {
+                depth[index] = braceDepth;
+                braceDepth += 1;
+            } else if (ch === "}") {
+                braceDepth -= 1;
+                if (braceDepth < 0) throw new EnumTsSyncError(where, "波括弧の対応が壊れています");
+                depth[index] = braceDepth;
+            } else {
+                depth[index] = braceDepth;
+            }
+            out[index] = ch;
+            if (ch === "'") state = SINGLE;
+            else if (ch === '"') state = DOUBLE;
+            index += 1;
+            continue;
+        }
+
+        if (state === SINGLE || state === DOUBLE) {
+            depth[index] = braceDepth;
+            if (ch === "\\") {
+                blank(index);
+                if (index + 1 < length) {
+                    depth[index + 1] = braceDepth;
+                    blank(index + 1);
+                }
+                index += 2;
+                continue;
+            }
+            if ((state === SINGLE && ch === "'") || (state === DOUBLE && ch === '"')) {
+                isCode[index] = 1;
+                out[index] = ch;
+                state = CODE;
+                index += 1;
+                continue;
+            }
+            blank(index);
+            index += 1;
+            continue;
+        }
+
+        if (state === LINE) {
+            depth[index] = braceDepth;
+            // PHP は**行注釈の中の `?>` でも PHP モードを抜ける**ので見逃さない。
+            if (ch === "?" && next === ">") {
+                throw new EnumTsSyncError(where, "PHP の閉じタグ (?>) は受理しません");
+            }
+            if (ch === "\n" || ch === "\r") {
+                isCode[index] = 1;
+                out[index] = ch;
+                state = CODE;
+                index += 1;
+                continue;
+            }
+            blank(index);
+            index += 1;
+            continue;
+        }
+
+        // BLOCK
+        depth[index] = braceDepth;
+        if (ch === "*" && next === "/") {
+            depth[index + 1] = braceDepth;
+            out[index] = " ";
+            out[index + 1] = " ";
+            state = CODE;
+            index += 2;
+            continue;
+        }
+        blank(index);
+        index += 1;
+        continue;
+    }
+
+    if (state === SINGLE) throw new EnumTsSyncError(where, "単一引用符の文字列が閉じていません");
+    if (state === DOUBLE) throw new EnumTsSyncError(where, "二重引用符の文字列が閉じていません");
+    if (state === BLOCK) throw new EnumTsSyncError(where, "ブロック注釈が閉じていません");
+
+    return { sanitized: out.join(""), isCode, depth };
+};
+
+/**
+ * 深さ 0 のコード状態に現れる `enum` の位置 / 深さ 1 の `case` の位置。
+ * `lastIndex` を持つので**呼び出しごとに作る** (共有すると走査位置が持ち越される)。
+ */
+const enumTokenRe = (): RegExp => /(?<![\w$\\])enum(?=[\s])/gi;
+const caseTokenRe = (): RegExp => /(?<![\w$\\])case(?![\w])/gi;
+/** `enum <名前>[: <backing>]` の頭。 */
+const ENUM_HEADER = /^enum\s+([A-Za-z_\u0080-\uFFFF][A-Za-z0-9_\u0080-\uFFFF]*)(?:\s*:\s*([A-Za-z_\\][A-Za-z0-9_\\]*))?/i;
+/** 受理する case の書き方 (単一引用符)。 */
+const CASE_SINGLE = /^case[ \t]+([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*'([^'\\]*)'[ \t]*;$/i;
+/** 受理する case の書き方 (二重引用符。変数の埋め込みを拒むため `$` も除く)。 */
+const CASE_DOUBLE = /^case[ \t]+([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*"([^"\\$]*)"[ \t]*;$/i;
+
+/**
+ * PHP の文字列付き列挙の値集合を読む (本体)。
+ *
+ * @param source   PHP のソース
+ * @param fileName 語幹の照合と例外の文面にだけ使うファイル名
+ */
+export const readPhpEnumValuesFromText = (source: string, fileName: string): ReadonlySet<string> => {
+    const where = fileName;
+    const base = path.basename(fileName);
+    if (!base.endsWith(".php")) {
+        throw new EnumTsSyncError(where, "ファイル名の拡張子が .php ではありません");
+    }
+    const stem = base.slice(0, -".php".length);
+
+    const { sanitized, isCode, depth } = scan(source, where);
+
+    // 1. 深さ 0 の enum 宣言がちょうど 1 つ
+    const enumOffsets: number[] = [];
+    const enumToken = enumTokenRe();
+    for (let m = enumToken.exec(sanitized); m !== null; m = enumToken.exec(sanitized)) {
+        if (isCode[m.index] === 1 && depth[m.index] === 0) enumOffsets.push(m.index);
+    }
+    if (enumOffsets.length === 0) throw new EnumTsSyncError(where, "enum 宣言が見つかりません");
+    if (enumOffsets.length > 1) {
+        throw new EnumTsSyncError(where, `enum 宣言が ${enumOffsets.length} 件あります`);
+    }
+
+    const headerOffset = enumOffsets[0];
+    const header = ENUM_HEADER.exec(sanitized.slice(headerOffset));
+    if (header === null) throw new EnumTsSyncError(where, "enum 宣言の頭を読めません");
+    const enumName = header[1];
+    const backing = header[2];
+    if (backing === undefined) {
+        throw new EnumTsSyncError(where, "backing 型がありません (string 付きの列挙だけを受理します)");
+    }
+    if (backing.toLowerCase() !== "string") {
+        throw new EnumTsSyncError(where, `backing 型が string ではありません: ${backing}`);
+    }
+
+    // 2. PSR-4 の前提の裏取り (ファイル名の語幹と一致すること)
+    if (enumName !== stem) {
+        throw new EnumTsSyncError(where, `ファイル名の語幹 (${stem}) と enum 名 (${enumName}) が食い違います`);
+    }
+
+    // 3. 本体の範囲を取る
+    let bodyStart = -1;
+    for (let i = headerOffset + header[0].length; i < sanitized.length; i += 1) {
+        if (isCode[i] === 1 && sanitized[i] === "{") {
+            bodyStart = i;
+            break;
+        }
+    }
+    if (bodyStart < 0) throw new EnumTsSyncError(where, "enum の本体が見つかりません");
+
+    let bodyEnd = -1;
+    for (let i = bodyStart + 1; i < sanitized.length; i += 1) {
+        if (isCode[i] === 1 && sanitized[i] === "}" && depth[i] === 0) {
+            bodyEnd = i;
+            break;
+        }
+    }
+    if (bodyEnd < 0) throw new EnumTsSyncError(where, "enum の本体が閉じていません");
+
+    // 4. 深さ 1 の case を 1 件ずつ受理する
+    const names = new Set<string>();
+    const values = new Set<string>();
+    const caseToken = caseTokenRe();
+    for (let m = caseToken.exec(sanitized); m !== null; m = caseToken.exec(sanitized)) {
+        const at = m.index;
+        if (at < bodyStart || at > bodyEnd) continue;
+        if (isCode[at] !== 1 || depth[at] !== 1) continue;
+
+        let semicolon = -1;
+        for (let i = at; i < bodyEnd; i += 1) {
+            if (isCode[i] === 1 && sanitized[i] === ";") {
+                semicolon = i;
+                break;
+            }
+        }
+        if (semicolon < 0) throw new EnumTsSyncError(where, "case 宣言の終端 (;) が見つかりません");
+
+        // **元の本文**を照合する (無害化した写しではない)
+        const declaration = source.slice(at, semicolon + 1);
+        const matched = CASE_SINGLE.exec(declaration) ?? CASE_DOUBLE.exec(declaration);
+        if (matched === null) {
+            throw new EnumTsSyncError(where, `受理する書き方に一致しません: ${JSON.stringify(declaration)}`);
+        }
+        const caseName = matched[1];
+        const caseValue = matched[2];
+        if (names.has(caseName)) throw new EnumTsSyncError(where, `case の名前が重複しています: ${caseName}`);
+        // 旧テストは配列同士を比べていて値の重複を検出できた。集合にすると消えるので
+        // **抽出器の側で明示的に落として**保証を引き継ぐ。
+        if (values.has(caseValue)) throw new EnumTsSyncError(where, `backing の値が重複しています: ${caseValue}`);
+        names.add(caseName);
+        values.add(caseValue);
+    }
+
+    if (values.size === 0) throw new EnumTsSyncError(where, "case を 1 件も取り出せません");
+
+    return values;
+};
+
+/** リポジトリ相対のパスから読む薄い包み。 */
+export const readPhpEnumValues = (phpFile: string): ReadonlySet<string> => {
+    const absolute = path.join(REPO_ROOT, phpFile);
+    if (!fs.existsSync(absolute)) {
+        throw new EnumTsSyncError(phpFile, "PHP ファイルが実在しません");
+    }
+    // 例外の文面には**リポジトリ相対のパス**を載せる (語幹の照合は中で basename を取る)。
+    return readPhpEnumValuesFromText(fs.readFileSync(absolute, "utf-8"), phpFile);
+};
diff --git a/tests/js/support/enum-ts-sync/program-fixtures/registry-augmentation.ts b/tests/js/support/enum-ts-sync/program-fixtures/registry-augmentation.ts
new file mode 100644
index 0000000..5b2527c
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/program-fixtures/registry-augmentation.ts
@@ -0,0 +1,12 @@
+/**
+ * T25 の増える側。`registry-base` の Registry をモジュール拡張で広げる。
+ * **外部モジュールとして成立させる** (import を持たせないと `declare module` が
+ * 大域宣言側の解釈になり拡張にならない)。
+ */
+import "./registry-base";
+
+declare module "./registry-base" {
+    interface Registry {
+        b: "b";
+    }
+}
diff --git a/tests/js/support/enum-ts-sync/program-fixtures/registry-base.ts b/tests/js/support/enum-ts-sync/program-fixtures/registry-base.ts
new file mode 100644
index 0000000..5f206d1
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/program-fixtures/registry-base.ts
@@ -0,0 +1,8 @@
+/**
+ * T25 (起点を縮める改変の回帰) の土台。
+ * **tsconfig.json の対象に残す** (除外すると拡張が全体 program にも載らず、
+ * 「全体 program だから値が増える」という差が出せない)。
+ */
+export interface Registry {
+    a: "a";
+}
diff --git a/tests/js/support/enum-ts-sync/program.ts b/tests/js/support/enum-ts-sync/program.ts
new file mode 100644
index 0000000..c0558a3
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/program.ts
@@ -0,0 +1,99 @@
+/**
+ * 型情報の入口 (TypeScript の program と型検査器を作る)。
+ *
+ * **本番の gate は `tsconfig.json` が含む TS ファイル全体で program を作る**。
+ * 目録のファイルだけを起点にすると、`include` だけで参加する宣言 (周囲宣言 `.d.ts` /
+ * `declare global` / モジュールの拡張) が program に載らず、**本番の型と違う型世界**で
+ * 判定してしまう。本リポジトリには実際に `resources/js/lib/recaptcha.ts` の
+ * `declare global` があり、この経路は絵空事ではない。偽陰性 (取り残しを緑にする) に
+ * なるので、速さのために起点を縮める判断はしない。
+ */
+import ts from "typescript";
+import fs from "node:fs";
+import path from "node:path";
+import { fileURLToPath } from "node:url";
+import { EnumTsSyncError } from "./errors";
+
+/** リポジトリのルート (tests/js/support/enum-ts-sync から 4 つ上)。 */
+export const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../../..");
+
+export interface MirrorProgram {
+    readonly program: ts.Program;
+    readonly checker: ts.TypeChecker;
+}
+
+const formatHost: ts.FormatDiagnosticsHost = {
+    getCanonicalFileName: (fileName) => fileName,
+    getCurrentDirectory: () => REPO_ROOT,
+    getNewLine: () => "\n",
+};
+
+/** tsconfig.json を読む。回復可能な診断も含めて 1 件でもあれば例外にする。 */
+const parseRepoTsconfig = (): ts.ParsedCommandLine => {
+    const configPath = path.join(REPO_ROOT, "tsconfig.json");
+    const host: ts.ParseConfigFileHost = {
+        useCaseSensitiveFileNames: ts.sys.useCaseSensitiveFileNames,
+        readDirectory: ts.sys.readDirectory,
+        fileExists: ts.sys.fileExists,
+        readFile: ts.sys.readFile,
+        getCurrentDirectory: () => REPO_ROOT,
+        onUnRecoverableConfigFileDiagnostic: (d) => {
+            throw new EnumTsSyncError("tsconfig.json", ts.flattenDiagnosticMessageText(d.messageText, " "));
+        },
+    };
+    const parsed = ts.getParsedCommandLineOfConfigFile(configPath, {}, host);
+    if (parsed === undefined) throw new EnumTsSyncError("tsconfig.json", "読み込みに失敗しました");
+    if (parsed.errors.length > 0) {
+        throw new EnumTsSyncError("tsconfig.json", ts.formatDiagnostics(parsed.errors, formatHost));
+    }
+    if (parsed.fileNames.length === 0) {
+        throw new EnumTsSyncError("tsconfig.json", "対象ファイルが 0 件です (gate が空振りしている)");
+    }
+    return parsed;
+};
+
+const buildProgram = (rootNames: readonly string[], parsed: ts.ParsedCommandLine): MirrorProgram => {
+    const program = ts.createProgram({
+        rootNames: [...rootNames],
+        options: { ...parsed.options, noEmit: true },
+        projectReferences: parsed.projectReferences,
+        configFileParsingDiagnostics: parsed.errors,
+    });
+    const optionsDiagnostics = program.getOptionsDiagnostics();
+    if (optionsDiagnostics.length > 0) {
+        throw new EnumTsSyncError("tsconfig.json", ts.formatDiagnostics(optionsDiagnostics, formatHost));
+    }
+    return { program, checker: program.getTypeChecker() };
+};
+
+/**
+ * 目録が指す TS ファイルを含む program を作る。
+ * 起点は **tsconfig が含む全ファイル ∪ 目録のファイル**。
+ *
+ * @param tsFiles リポジトリルートからの相対パス
+ */
+export const createMirrorProgram = (tsFiles: readonly string[]): MirrorProgram => {
+    const parsed = parseRepoTsconfig();
+    const inventoryRoots = tsFiles.map((file) => {
+        const absolute = path.join(REPO_ROOT, file);
+        if (!fs.existsSync(absolute)) {
+            throw new EnumTsSyncError(file, "目録が指す TS ファイルが実在しません");
+        }
+        return absolute;
+    });
+    return buildProgram([...new Set([...parsed.fileNames, ...inventoryRoots])], parsed);
+};
+
+/**
+ * 見本 (fixture) 専用の**起点を縮めた** program。**本番の gate では使わない**。
+ * リポジトリの `compilerOptions` (`paths` を含む) はそのまま使い、起点だけを明示する。
+ *
+ * @param absoluteFiles 絶対パス
+ */
+export const createFixtureProgram = (absoluteFiles: readonly string[]): MirrorProgram => {
+    const parsed = parseRepoTsconfig();
+    for (const absolute of absoluteFiles) {
+        if (!fs.existsSync(absolute)) throw new EnumTsSyncError(absolute, "見本ファイルが実在しません");
+    }
+    return buildProgram(absoluteFiles, parsed);
+};
diff --git a/tests/js/support/enum-ts-sync/ts-value-sets.ts b/tests/js/support/enum-ts-sync/ts-value-sets.ts
new file mode 100644
index 0000000..d774ef5
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/ts-value-sets.ts
@@ -0,0 +1,68 @@
+/**
+ * TS 側の値集合を**型情報から**読む。
+ *
+ * 受理する形 (**解決・正規化された後の型**についての条件である):
+ *   1. 対象ファイルのトップレベルに、その名前の**型別名の宣言**が**ちょうど 1 つ**あること。
+ *   2. その宣言が解決する型が、**文字列リテラル型だけ**の union か、単独の文字列リテラル型であること。
+ *   3. `ts.TypeFlags.EnumLiteral` を持つ構成要素は**受理しない** (本リポジトリに TypeScript の
+ *      `enum` は 1 件も無く、文字列リテラル型と同じ契約ではないため。必要になってから広げる)。
+ *
+ * **重複は検出しない**。`"a" | "a"` は型検査器が `"a"` へ正規化するため、値集合の側からは
+ * 元の重複を観測できない (union の中の `never` も同じく正規化で消える)。
+ * **意味の診断は見ない** — 型検査そのものは `pnpm typecheck` の担当で、同じことを 2 箇所で見ない。
+ */
+import ts from "typescript";
+import path from "node:path";
+import { EnumTsSyncError } from "./errors";
+import { REPO_ROOT, type MirrorProgram } from "./program";
+
+export const readTsUnionValues = (
+    { program, checker }: MirrorProgram,
+    tsFile: string,
+    declaration: string,
+): ReadonlySet<string> => {
+    const where = `${tsFile}::${declaration}`;
+    const source = program.getSourceFile(path.join(REPO_ROOT, tsFile));
+    if (source === undefined) throw new EnumTsSyncError(where, "TS ファイルが program に載っていません");
+
+    // 構文が壊れていると型解決が黙って縮むので、構文の診断だけは見る。
+    if (program.getSyntacticDiagnostics(source).length > 0) {
+        throw new EnumTsSyncError(where, "TS ファイルの構文が壊れています");
+    }
+
+    const aliases = source.statements
+        .filter(ts.isTypeAliasDeclaration)
+        .filter((statement) => statement.name.text === declaration);
+    if (aliases.length === 0) {
+        throw new EnumTsSyncError(
+            where,
+            "型別名の宣言が見つかりません (受理するのは `type X = …` だけ。定数配列・switch の case ラベル・.svelte 内の宣言は読みません)",
+        );
+    }
+    if (aliases.length > 1) {
+        throw new EnumTsSyncError(where, `同名の型別名が ${aliases.length} 件あります`);
+    }
+
+    const symbol = checker.getSymbolAtLocation(aliases[0].name);
+    if (symbol === undefined) throw new EnumTsSyncError(where, "宣言の記号を解決できません");
+
+    const declared = checker.getDeclaredTypeOfSymbol(symbol);
+    const parts = declared.isUnion() ? declared.types : [declared];
+
+    const values = new Set<string>();
+    for (const part of parts) {
+        if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) {
+            throw new EnumTsSyncError(where, `TypeScript の enum の値は受理しません: ${checker.typeToString(part)}`);
+        }
+        if (!part.isStringLiteral()) {
+            throw new EnumTsSyncError(
+                where,
+                `文字列リテラル型でない構成要素があります: ${checker.typeToString(part)}`,
+            );
+        }
+        values.add(part.value);
+    }
+    if (values.size === 0) throw new EnumTsSyncError(where, "値を 1 つも取り出せません");
+
+    return values;
+};
diff --git a/tsconfig.json b/tsconfig.json
index b80df54..48d5099 100644
--- a/tsconfig.json
+++ b/tsconfig.json
@@ -23,5 +23,9 @@
         "tests/js/**/*.ts",
         "scripts/**/*.ts"
     ],
-    "exclude": ["node_modules", "tmp"]
+    "exclude": [
+        "node_modules",
+        "tmp",
+        "tests/js/support/enum-ts-sync/fixtures/**"
+    ]
 }
```

## テスト結果

検証コマンドは worktree 内で全件 green:

- `composer test` : 5752 tests / 5750 passed / 2 skipped / 0 failed / 25260 assertions
  (旧 PHP 検査 4 本の削除で test 宣言が 18 件減っている)
- `composer phpstan` (level 10) : No errors / `vendor/bin/pint --test` : passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build` : green
- `pnpm test` : 162 files / **2115 tests passed**
  (うち新規 108 件 = 本体 gate 36 + 抽出器の負例行列 72)
- `pnpm typecheck:packages` / `pnpm build:packages` : green / `pnpm test:packages` : 10 files / 106 tests passed

**故障注入 (感度) の実測 — 17 件すべて赤**。再現手順は
`devnotes/20260817-1748-enum-ts-generic-sync-gate/fault-injection.sh` (注入ごとに退避 → 壊す →
置換が実際に起きたことを確認 → vitest → 復元)。結果表は同ディレクトリの `implementation-notes.md`。

| 注入 | 結果 |
|---|---|
| 代表 3 組 (VideoManualStatus / MemberRoleState / PlanCode) の TS 側から 1 値落とす | 3 件とも赤 |
| 同 3 組の PHP 側へ case を 1 つ足す | 3 件とも赤 |
| 目録の件数 pin を 1 ずらす / 目録の行を 1 つ消す | 赤 |
| 目録へ `app/` の外のパスを登録する | 赤 (beforeAll の体裁検査で停止 = program 構築より先に効いている) |
| 抽出器: TS の `enum` を弾く分岐を外す / 同名の型別名の検査を外す | 赤 (T19・T20 / T8) |
| 抽出器: 起点を縮めた program を全体 program に差し替える | 赤 (T25b) |
| 抽出器: 逆斜線の偶奇を 1 文字送りにする | 赤 (P31・P32・P33) |
| 抽出器: 行注釈の中の閉じタグを見逃す | 赤 (P24・P25) |
| 抽出器: case の深さの条件・値の重複の検査・ファイル名の語幹の照合を外す | 赤 (P3 / P36 / P34) |

- program 構築の実測 (同一プロセス内で 3 回): 1480ms / 1108ms / 1059ms。
  gate 2 ファイル 108 件で 3.4 秒。**速さのために起点を縮める判断はしていない**
- **マージ時の追随予定**: main 側で D27 (T220) / D28 (T221) が先に確定したため、本変更の登録は
  **D29**、`docs/template-divergence.md` の登録エントリ数は **28** へ改める
  (差分中の D27 / 26 件はマージ前の値である)
