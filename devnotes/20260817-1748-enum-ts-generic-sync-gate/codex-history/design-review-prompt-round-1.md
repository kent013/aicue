# 使命 (North Star)
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


# 禁止事項

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


# 思考原則 — 全議論に適用

まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript 6 + vitest 4
- PHPStan level 10 / Pest / DTO + JsonResource パターン
- CI は job が分かれており、`frontend` job には PHP が入っていない (setup-php も composer install も無い)

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)。とくに TypeScript Compiler API の使い方と PHP の字句解析の受理文法に穴が無いか
2. 既存コードとの整合性 (命名規約、パターン)
3. 型安全性 (TS strict / PHPStan level 10)
4. テスト計画の網羅性 (負例行列に抜けが無いか)
5. 副作用・後退リスク (既存テスト 4 本の削除で失われる保証は本当に無いか)
6. 波及変更の網羅性 (参照の付け替え漏れが無いか)
7. セキュリティ (該当は薄いが、テスト基盤が偽グリーンにならないか = degenerate PASS の防止)
8. 「保証しないもの」の書き方が誇張になっていないか

【この設計に固有の論点 — 必ず判断を示すこと】
- (a) `checker.getDeclaredTypeOfSymbol` で型別名を解決し「文字列リテラル型だけの union」を要求する設計に、静かに素通りする穴はないか (例: 分配条件型・`Lowercase<T>` 等の組み込み型・enum リテラル型・`unique symbol`・型の遅延解決・循環参照)
- (b) PHP の受理文法 (波括弧の深さ 1 のみ / 注釈と文字列の中身を除外 / ヒアドキュメントは例外) に、実際の PHP 構文で破れるものがあるか (例: `match` 式・匿名クラス・`enum` 実装の interface・`const` 宣言・複数行の case・PHP 属性の中の波括弧・`?>` で閉じた後の HTML)
- (c) 見本 (fixture) の PHP を `.php` ではなく `.php.txt` で置く判断は妥当か。他により良い手があるか
- (d) 目録の件数を完全一致で pin する設計は妥当か (増減どちらでも赤くなる)
- (e) `docs/template-divergence.md` へ登録しない判断は妥当か
- (f) program の起点を「目録に出てくる TS ファイルだけ」にすることで、型解決が不足して静かに間違える経路はないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: PHP 列挙 ⇔ TypeScript 値域の汎用同期 gate (裁定 AG-099 追従)

## 使命・制約(絶対遵守)

### アプリの使命(North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

### 禁止事項(AGENTS.md より。本設計に効くもの)

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST の `redirect()->intended()` / 8. 必須条件未充足での disabled ボタン
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
| C | 抽出器の自己検査 (負例行列) | `tests/js/architecture/enum-ts-sync-extractor.test.ts` + `tests/js/support/enum-ts-sync/fixtures/` (新規) / `tsconfig.json` | 高 |
| D | 旧実装の撤去と参照の是正 | `tests/Support/TsUnionValues.php` + PHP テスト 4 本 (削除) / `tests/Architecture/TicketLedgerReaderInventoryTest.php` / `app/Enums/**` の docblock 8 件 / `resources/js/types/*.ts` の docblock 4 件 / `docs/architecture.md` 2 箇所 | 高 |
| E | 母集団の拡張 (14 組 → 27 組) | `tests/js/architecture/enum-ts-sync.test.ts` の目録 | 中 |
| F | 規約・文書 | `AGENTS.md` (ドメイン固有規約 19) / `docs/architecture.md` (新節) | 中 |

施策 A〜E は**同一コミットで着地させる**(旧実装を残したまま新 gate を足すと並走になる。
思考原則 3)。F は同じ PR 内で続けて行う。

---

## 施策 A: 型情報で読む抽出基盤

### 変更箇所

新規 4 ファイル。すべて `tests/js/support/enum-ts-sync/` 配下。

### 波及変更

- TypeScript 型定義: 新規のみ(公開 API は下記シグネチャ)
- API Resource/DTO: なし
- テストファイル: 施策 B / C が利用者

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

/**
 * 目録に出てくる TS ファイルだけを起点に program を 1 度だけ作る。
 * **リポジトリ全体を起点にしない**(構築時間が伸びて architecture レーンが不安定になる)。
 */
export const createMirrorProgram = (tsFiles: readonly string[]): MirrorProgram => {
    const configPath = path.join(REPO_ROOT, "tsconfig.json");
    const parsed = ts.getParsedCommandLineOfConfigFile(configPath, {}, {
        ...ts.sys,
        onUnRecoverableConfigFileDiagnostic: (d) => {
            throw new EnumTsSyncError("tsconfig.json", ts.flattenDiagnosticMessageText(d.messageText, " "));
        },
    } as ts.ParseConfigFileHost);
    if (parsed === undefined) throw new EnumTsSyncError("tsconfig.json", "読み込みに失敗しました");

    const rootNames = tsFiles.map((f) => path.join(REPO_ROOT, f));
    for (const f of rootNames) {
        if (!fs.existsSync(f)) throw new EnumTsSyncError(f, "目録が指す TS ファイルが実在しません");
    }
    const program = ts.createProgram({ rootNames, options: { ...parsed.options, noEmit: true } });
    return { program, checker: program.getTypeChecker() };
};
```

- `tsconfig.json` は `@tsconfig/svelte` を `extends` しているが、`getParsedCommandLineOfConfigFile`
  が解決する。**万一解決できない環境が出たら compilerOptions を直書きへ落とさず、
  例外にして原因を出す**(黙って既定値で動くと解決規則 (`paths`) が失われ、
  別名参照の解決が静かに壊れる)。
- 型検査の診断 (`getSemanticDiagnostics`) は**見ない**。本 gate の責務は値集合の一致であり、
  型検査は `pnpm typecheck` の担当である(同じことを 2 箇所で見ない)。

### A-3 `ts-value-sets.ts` — TS 側の値集合

**受理する形**: 「対象ファイルのトップレベルに**ちょうど 1 つ**ある同名の**型別名の宣言**で、
その宣言が解決する型が**文字列リテラル型だけ**で構成されていること」。
union でも単独でもよい。`keyof typeof X` のように解決結果が文字列リテラル型の union に
なる書き方は自然に受理される(**解決後の型**だけを見るため)。

```ts
export const readTsUnionValues = (
    { program, checker }: MirrorProgram,
    tsFile: string,
    declaration: string,
): ReadonlySet<string> => {
    const where = `${tsFile}::${declaration}`;
    const source = program.getSourceFile(path.join(REPO_ROOT, tsFile));
    if (source === undefined) throw new EnumTsSyncError(where, "TS ファイルが program に載っていません");

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
        if (!part.isStringLiteral()) {
            throw new EnumTsSyncError(where, `文字列リテラル型でない構成要素があります: ${checker.typeToString(part)}`);
        }
        values.add(part.value);
    }
    if (values.size === 0) throw new EnumTsSyncError(where, "値を 1 つも取り出せません");
    if (values.size !== parts.length) throw new EnumTsSyncError(where, "同じ値が 2 回現れます");
    return values;
};
```

これで概念設計の問題 1 が閉じる:

| 旧 (正規表現) の穴 | 新 (型情報) の挙動 |
|---|---|
| 注釈の中の引用符を値として拾う | 注釈は型に現れないので混ざらない |
| `"a" \| "b" \| (string & {})` を閉じた union と誤認 | 交差型の構成要素で例外 |
| 別名参照 (`ConsoleRole \| "owner"`) を読めない | 解決して全値を得る |
| 宣言本体を最初の `;` で切って壊れる | 構文木で宣言を取るので起きない |

### A-4 `php-enums.ts` — PHP 側の値集合

**受理する文法**(これ以外はすべて例外。**もう 1 つの静かに間違える抽出器を作らない**):

1. 対象ファイルに `enum <名前>: string` の宣言が**ちょうど 1 つ**あること
   (0 件・2 件以上は例外)。`<名前>` は**ファイル名の語幹と一致**すること
   (PSR-4 の前提の裏取り。写しの取り違えを防ぐ)。
2. その本体の**波括弧の深さ 1** に現れる `case <名前> = '<値>';` / `case <名前> = "<値>";` だけを値とする。
3. 深さ 1 に `case` で始まる記述があって 2 の形に合わないときは**例外**
   (定数式・逆斜線を含む値・二重引用符の中の `$`(変数の埋め込み)・複数行)。
4. 注釈(`//` `#` `/* */`)と文字列の**中身**は深さの計算から除く
   → メソッド本体の `switch` の `case`、注釈の中の `case`、文字列の中の波括弧は混ざらない。
5. ヒアドキュメント / ナウドキュメント(`<<<`)がファイルに現れたら**例外**(深さの計算を保証できない)。
6. case が 0 件なら例外。

```ts
export const readPhpEnumValues = (phpFile: string): ReadonlySet<string> => { /* 上の 1〜6 */ };
```

実装は 2 パス。

- パス 1: 1 文字ずつ走査し、注釈と文字列の**中身**を無害な埋め草へ置き換えた写しを作る
  (改行と位置は保つ)。`<<<` を見つけたら例外。
- パス 2: 写しの上で `enum` 宣言を見つけ、波括弧の深さを数えながら深さ 1 の `case` 文の
  範囲を取り、**元の本文**の同じ範囲を次の 2 つの正規表現で照合する。

```ts
const SINGLE = /^case\s+[A-Za-z_]\w*\s*=\s*'([^'\\]*)'\s*;$/;
const DOUBLE = /^case\s+[A-Za-z_]\w*\s*=\s*"([^"\\$]*)"\s*;$/;
```

### PHPStan 適合チェック

本施策に PHP の変更は無い(すべて TypeScript)。TS 側は次を守る:

- [x] 公開関数の戻り値は `ReadonlySet<string>`。**`null` / 空配列で失敗を表さない**
- [x] 失敗は `EnumTsSyncError`(場所 + 理由)を投げる
- [x] `any` を使わない(`ts.ParseConfigFileHost` への `as` は host 合成の 1 箇所のみ)
- [x] 目録は `as const satisfies readonly EnumTsMirror[]`

### テスト計画

施策 C が担当(抽出基盤単体の負例行列)。

### リスク

- `getParsedCommandLineOfConfigFile` の `extends` 解決が環境で揺れる → 例外で落として原因を出す
  (黙って既定値へ落とさない)。
- PHP の字句解析は本 gate 専用の最小実装であり、**PHP のすべての書き方を読めるとは主張しない**。
  受理範囲外は例外なので、読めない形が入ったら赤くなる(静かに間違えない)。

---

## 施策 B: 汎用 gate 本体

### 変更箇所

`tests/js/architecture/enum-ts-sync.test.ts`(新規)。

### 波及変更

- TypeScript 型定義: 目録の行の型 `EnumTsMirror` を同ファイル内に定義
- テストファイル: 施策 D で削除する PHP テスト 4 本の役割をここが引き継ぐ

### 目録の行

```ts
interface EnumTsMirror {
    /** リポジトリルートからの PHP 列挙ファイルの相対パス。 */
    readonly php: string;
    /** リポジトリルートからの TS ファイルの相対パス。 */
    readonly ts: string;
    /** TS 側の型別名の名前。 */
    readonly declaration: string;
    /** この写しが要る理由 (画面のどこが値で分岐するか)。 */
    readonly note: string;
}
```

`note` に 30 文字以上の長さの検査は**課さない**。本目録は**免除の申告ではなく登録**であり、
判断の重さが違うため(免除の目録 = `ThrottleCoverageExemption` 等が 30 文字を課すのは
「検査から外す」判断だから)。

### 検査

```ts
const EXPECTED_MIRROR_COUNT = 27; // 増えても減っても赤くする (黙って写しが消えるのを防ぐ)

describe("PHP 列挙 ⇔ TS 値域の同期", () => {
    let mirrorProgram: MirrorProgram;
    beforeAll(() => {
        mirrorProgram = createMirrorProgram([...new Set(ENUM_TS_MIRRORS.map((m) => m.ts))]);
    }, 120_000);

    it("目録の件数が pin と一致する", () => {
        expect(ENUM_TS_MIRRORS).toHaveLength(EXPECTED_MIRROR_COUNT);
    });

    it("目録に重複が無く、パスの体裁が守られている", () => { /* ts+declaration の一意性 / php は app/ 配下の *.php / note が空でない */ });

    it.each(ENUM_TS_MIRRORS)("$php ⇔ $ts::$declaration の値集合が一致する", (mirror) => {
        const phpValues = readPhpEnumValues(mirror.php);
        const tsValues = readTsUnionValues(mirrorProgram, mirror.ts, mirror.declaration);
        expect([...tsValues].sort()).toEqual([...phpValues].sort());
    });
});
```

- **空振り防止**は抽出器側の例外で担保される(値 0 件は例外)。加えて件数 pin が
  「目録そのものが空になる」経路を塞ぐ。
- 失敗メッセージには `$php` / `$ts` / `$declaration` が入る(どの組が食い違ったかが一目で分かる)。

### PHPStan 適合チェック

PHP の変更なし。

### テスト計画

- [ ] **fail-first を実測する**: TS 側の union から 1 値落とす / PHP 側の case を 1 つ増やす、を
      **27 組すべてで 1 回ずつ**行い、全部が赤くなることを確かめる(自動化はしない。
      実装時に 1 度回し、結果を実装報告へ数値で残す)
- [ ] 目録の件数 pin を 1 ずらすと赤くなることを確認する
- [ ] 既存の `pnpm test` が全件緑

### リスク

- 27 組それぞれが 1 テストになるので、vitest の出力が 27 行増える。これは母集団が
  見えるようになったということであり、増殖ではない(**足すのは目録の行 1 つ**)。
- program の構築は `beforeAll` の 1 回だけ。制限時間は明示的に 120 秒を与える
  (aigenba が「最初の 1 テスト内で構築すると高負荷時に既定の制限時間を超えうる」と実測している)。

---

## 施策 C: 抽出器の自己検査(負例行列)

### 変更箇所

- `tests/js/architecture/enum-ts-sync-extractor.test.ts`(新規)
- `tests/js/support/enum-ts-sync/fixtures/ts/*.ts`(新規)
- `tests/js/support/enum-ts-sync/fixtures/php/*.php.txt`(新規)
- `tsconfig.json` の `exclude` に `tests/js/support/enum-ts-sync/fixtures/**` を追加

### 波及変更

- **PHP 側の見本は `.php` にしない**。拡張子を `.php.txt` にする理由:
  git 追跡下の `*.php` は `StrictTypesDeclarationGateTest`(宣言の全数検査)・
  `ForbiddenStatementTokenInvariantTest`(禁止する文の字句走査)・Pint・PHPStan の
  対象になるため、**わざと壊した見本を `.php` で置くと 4 つの検査を同時に敵に回す**。
  抽出器はパスを受け取ってテキストを読むだけなので拡張子に依存しない。
  代わりに、**目録側で `php` が `app/` 配下の `*.php` であることを検査する**(施策 B)。
- **TS 側の見本は `tsconfig.json` の `exclude` へ足す**。`tests/js/**/*.ts` が
  `pnpm typecheck` の対象なので、わざと壊した見本を除かないと typecheck が赤くなる
  (aigenba の申し送りと同じ手当)。`ts.createProgram` は明示した起点から読むので、
  除外しても抽出器の検査には影響しない。

### 負例・正例の行列

TS 側(`readTsUnionValues`):

| # | 見本 | 期待 |
|---|---|---|
| T1 | `type X = "a" \| "b";` | 受理 (`{a,b}`) |
| T2 | `type X = "only";`(単独) | 受理 (`{only}`) |
| T3 | 注釈の中に引用符付きの語がある union | 受理し、**注釈の語は混ざらない** |
| T4 | `type X = "a" \| "b" \| (string & {});` | 例外 (構成要素が文字列リテラル型でない) |
| T5 | `type X = "a" \| 1;` | 例外 |
| T6 | `type X = never;` | 例外 |
| T7 | 宣言が無い名前 | 例外 |
| T8 | 同名の型別名が 2 つ | 例外 |
| T9 | `export const X = ["a"] as const;` を宣言名で登録 | 例外 (受理範囲外である旨) |
| T10 | 別名参照 (`type X = Y \| "c";`。`Y` は**別ファイルから import**) | 受理し、`Y` の値も含む(= 起点だけを program に載せても import 先が解決されることの固定) |
| T11 | `type X = { a: "p"; b: "q" }["a" \| "b"];`(本体に `;` を含む) | 受理 (`{p,q}`) |

PHP 側(`readPhpEnumValues`):

| # | 見本 | 期待 |
|---|---|---|
| P1 | 素直な `enum X: string` に case 3 つ | 受理 |
| P2 | 注釈の中に `case Fake = 'x';` がある | 受理し、`x` は混ざらない |
| P3 | メソッド本体に `switch` の `case` がある | 受理し、混ざらない |
| P4 | case の値や本文の文字列に波括弧を含む | 受理 (深さの計算が壊れない) |
| P5 | 属性 (`#[...]`) が付いた enum / case | 受理 |
| P6 | `case A = self::PREFIX.'a';`(定数式) | 例外 (**受理範囲外**であることを理由に含む) |
| P7 | `case A = 'it\'s';`(逆斜線) | 例外。**復号して受理しない**(Codex Round 2 の指摘。誤抽出せず理由付きで落ちることを固定) |
| P8 | `case A = "pre{$x}";`(変数の埋め込み) | 例外 |
| P9 | `enum X: int` | 例外 |
| P10 | backing の無い `enum X` | 例外 |
| P11 | 1 ファイルに `enum` が 2 つ | 例外 |
| P12 | case が 0 件 | 例外 |
| P13 | ヒアドキュメント (`<<<`) を含む | 例外 |
| P14 | ファイル名の語幹と enum 名が食い違う | 例外 |

### 旧抽出器が静かに合格する変異の実測(1 度きり)

実装の**最初**に、旧 `TsUnionValues::extract` を消す前に次を測って実装報告へ残す。

- T3 / T4 相当の変異(注釈に PHP 側の値を書いた union / 開いた union から 1 値落とす)を
  実際の `resources/js/types/*.ts` に対して作り、**旧 gate が緑のまま通ること**を実測する。
- 同じ変異に対して新 gate が赤くなることを実測する。
- 数値(何件の変異のうち何件を旧形が見逃したか)を実装報告に書く。
  これが「作り直す価値があった」ことの唯一の証拠になる。

### PHPStan 適合チェック

PHP の変更なし(見本は `.php.txt` なので PHPStan / Pint / 宣言の全数検査の母集団に入らない)。

### テスト計画

- [ ] 上の 25 行(T1-T11 / P1-P14)をそのままテストにする
- [ ] **正のコントロール**: 見本の置き場所を読めていること(見本ファイルが 0 件だと
      `it.each` が空になって素通りするため、見本の件数を pin する)

### リスク

- `.php.txt` という置き方は本リポジトリで前例が無い。**理由をファイル冒頭の注釈と
  `docs/architecture.md` に明記する**(でないと「なぜ `.php` でないのか」が失われ、
  次の人が `.php` へ直して 4 つの検査を赤くする)。

---

## 施策 D: 旧実装の撤去と参照の是正

### 変更箇所

削除:

- `tests/Support/TsUnionValues.php`
- `tests/Architecture/ManualEnumTsSyncInvariantTest.php`(test 宣言 12 件)
- `tests/Architecture/NotificationTypeTsSyncInvariantTest.php`(同 2 件)
- `tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php`(同 2 件)
- `tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest.php`(同 2 件)

修正(参照の付け替え。**内容の変更は無く、指し先だけを直す**):

| ファイル | 箇所 |
|---|---|
| `tests/Architecture/TicketLedgerReaderInventoryTest.php` | L344 の注釈 / L370 の案内文 → 新 gate (`tests/js/architecture/enum-ts-sync.test.ts` の目録) を指す |
| `app/Enums/Manual/{RenderKind,RenderStep,RenderErrorCode,RenderConflictType,ScenarioVerdict,ScenarioRuleCode}.php` | docblock の「ManualEnumTsSyncInvariantTest が固定」 |
| `app/Enums/Notification/NotificationType.php` | docblock |
| `app/Enums/AccountDeletionBlockerAction.php` | docblock |
| `resources/js/types/{manual,notification,account}.ts` | docblock 4 箇所 |
| `docs/architecture.md` | L961 / L1301 の 2 箇所 |

### 波及変更

- TypeScript 型定義: なし(docblock のみ)
- API Resource/DTO: なし
- テストファイル: 上記の削除・修正がすべて

### 「既存テストの削除」について

app-design スキルの禁止事項 3 は「既存テストの削除・上書き」を禁じるが、本施策は
**同じ不変条件を、より強い抽出器と広い母集団 (14 組 → 27 組) で、同じ変更の中に再構築する移設**である。
AGENTS.md 思考原則 3(後方互換の並走を残さない)がこちらを要求する。
PHP レーンに薄い委譲テストを残す案(aigenba が採った形)は採らない —
本リポジトリの 4 本は列挙 ⇔ TS の突き合わせ**だけ**を行っており、
残せば同じ不変条件を 2 レーンで宣言する並走になるためである。Codex 概念設計レビューも
この判断を「妥当」と明示している(Round 1 固有論点 (i))。

**移設の証拠**として、実装報告に「削除した test 宣言 18 件が新 gate のどの行に対応するか」の
対応表を残す。

### PHPStan 適合チェック

- [x] 削除だけで型は増減しない
- [x] docblock の修正のみで実行経路に触れない
- [ ] `composer phpstan` が level 10 で緑(削除後に未使用 import が残らないこと)

### テスト計画

- [ ] `composer test` が緑(削除した 18 件の分だけ test 件数が減る。数値を実装報告に残す)
- [ ] `vendor/bin/pint --test` が緑
- [ ] `grep -rn "TsUnionValues" app tests docs resources` の結果が 0 件

### リスク

- **`composer test` だけを回す開発者はこの不変条件を検証しなくなる**。
  AGENTS.md は全検証コマンドの green を commit の条件にしており、CI も `frontend` job で
  `pnpm test` を必ず回すので運用上は塞がるが、**「PHP レーンでも見ている」とは書かない**。
  施策 F でこの非対称を AGENTS.md に明記する。

---

## 施策 E: 母集団の拡張(14 組 → 27 組)

### 変更箇所

施策 B の目録に 13 行追加する。内訳と理由は `survey.md` の表(#15〜#27)。

### 波及変更

なし(登録するだけ。いずれも**現時点で値集合が一致している**ことを実測済み)。

### 登録しないものと理由(目録の注釈へ書く)

| TS 宣言 | 理由 |
|---|---|
| `types/manual.ts::SelectableTakeStatus` | 「選択できるテイクの状態」という部分集合の意図を持つ。今は全一致だが完全一致で縛ると意図と食い違う |
| `types/dashboard.ts::DashboardJobStatus` | `JobStatus` の真部分集合(進行中のみ) |
| `types/capture.ts::CaptureProgress` ほか画面側だけの語彙 | 対応する PHP 列挙が無い |

### PHPStan 適合チェック

PHP の変更なし。

### テスト計画

- [ ] 13 行それぞれについて fail-first を実測(TS 側から 1 値落として赤くなること)
- [ ] `MemberRoleState`(#17)は**旧抽出器では読めなかった**組なので、
      「型情報にしたから登録できた」実例として実装報告に明記する

### リスク

- 13 組の登録により、これらの enum を今後変えるときに TS 側の追随が**必須**になる。
  これは意図した効果だが、実装報告で影響範囲を明示する。

---

## 施策 F: 規約・文書

### 変更箇所

- `AGENTS.md` の「ドメイン固有規約」に **19 番**として追加(現在 18 まで)
- `docs/architecture.md` に「§PHP 列挙と TypeScript 値域の同期」を新設

### 書く内容(要点)

- 登録の作法: 「PHP の文字列付き列挙の値を TS の型別名で受けている箇所を作ったら、
  `tests/js/architecture/enum-ts-sync.test.ts` の目録へ 1 行足す(件数 pin も 1 増やす)」
- 受理する形: 型別名の宣言で、解決した型が文字列リテラル型だけであること
- **保証しないもの**(誇張しない):
  - 登録していない写しは 1 件も検査しない(全数走査と既定拒否の分類は未実装)
  - 値の集合だけを見る(表示ラベル・並び順・意味は見ない)
  - 部分集合の関係は表現できない
  - `.svelte` の中の宣言・定数配列・switch の case ラベルは読まない
  - PHP 側は限定した文法だけを読む(範囲外は例外で落ちる = 静かには間違えない)
  - **正本のレーンは vitest (`pnpm test`)。`composer test` だけでは検証されない**
- 見本を `.php.txt` で置いている理由(`.php` にすると 4 つの PHP 側検査の母集団に入るため)

### 波及変更

- `docs/template-divergence.md`: **登録しない**。本作業はテンプレートの同種 gate の
  **段階的な取り込み**(前半のみ)であって、構造の意図的な逸脱ではない。
  未取り込みの段(発見の段・逆走査)は「保証しないもの」として検査の冒頭と AGENTS.md に明記する。
  ※ ここは判断であり、Codex 詳細レビューで異論があれば再検討する。

### テスト計画

- [ ] `AGENTS.md` の検証コマンド節のマーカーを壊していないこと
      (`verification-commands-doc-sync.test.ts`)
- [ ] `docs/architecture.md` の既存参照 2 箇所(施策 D)が新しい節を指すこと

### リスク

- 規約が増えることで AGENTS.md が長くなる。要点だけを書き、
  **保証しないものの正本は検査の docblock と `docs/architecture.md`** に置く
  (2 か所に書くと必ず食い違う。既存のドメイン規約 17 と同じ扱い)。

---

## 後続 TODO(本作業の完了条件に含める)

裁定 AG-099 の**後半**を別 TODO として起票する(起票は `app-todo-add` の責務。
本設計は文面だけを用意する)。

> **タイトル**: PHP 列挙 ⇔ TS 値域の発見の段と逆走査 (AG-099 後半)
> **完了条件**: (1) PHP の文字列付き列挙を全数走査し、**登録済み / 対象外の理由つき /
> 抽出できない残余**の 3 つへ既定拒否で分類する。(2) 逆走査 2 規則
> (規則 1 = 未登録で値集合が完全一致する候補の検出 / 規則 2 = 名前の対応と値の交差による
> 「既に食い違った写し」の検出) を実装する。
> **前提**: 本作業 (前半) の着地。実測では規則 1 の残りは 1 件だが、
> **これは見積りの仮説であり網羅の証拠ではない**(`survey.md`)。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 旧実装の削除 (施策 D) と新 gate の追加 (施策 A/B/C/E) は**同時でなければ緑にならない**(片方だけでは不変条件が消えるか並走する)。1 つの worktree で一括して着地させる |
| 競合リスク | `tsconfig.json` の `exclude` / `AGENTS.md` / `docs/architecture.md` を触るので、同じファイルを触る他タスクとは並行しない。`app/Enums/**` と `resources/js/types/*.ts` の変更は docblock のみで、値や型には触れない |

## 概念設計 (参考)

# 概念設計: PHP 列挙 ⇔ TypeScript 値域の汎用同期 gate (裁定 AG-099 追従)

- 機能台帳 (lctl) の機能: `enum-ts-sync-gate`
- 本リポジトリの現状: `pending` / `improvement_candidate`
- 起点: 裁定 AG-099 (「共通抽出基盤 + 型情報を使う抽出 + 宣言表への集約」= 前半 /
  「発見の段 (全数走査と既定拒否の分類) + 逆走査 2 規則」= 後半)

## 背景・課題

サーバー側 (PHP) の列挙型が持つ値の集合と、TypeScript 側に書かれた同じ選択肢の集合が
食い違うと、画面は「どの分岐にも当たらない値」を受け取って無言で描画を落とす。
撮影 PWA とマニュアル編集画面は状態値で分岐する面が多いため、この事故は使命
(思考ゼロ・編集ゼロで現場が動画を作れる) に直接効く。

本リポジトリには検査自体は存在するが、次の 3 つの問題がある。

### 問題 1: 抽出器が「二重引用符の型別名」専用の正規表現である

`tests/Support/TsUnionValues.php` は

```php
'/export\s+type\s+'.preg_quote($typeName, '/').'\s*=\s*(.*?);/s'   // 宣言の本体を取る
'/"([^"]+)"/'                                                      // 本体から二重引用符の文字列を拾う
```

の 2 段の正規表現である。これは**静かに間違える経路を実際に持つ**。

- (a) **注釈の中の引用符を値として拾う**。`| "a" // "b" の例` と書くと `b` が値集合に混ざる。
  PHP 側に `b` が無ければ赤くなる (誤検出) が、逆に **PHP 側に `b` があり TS 側の union に
  無い**とき、注釈のおかげで**緑になる** = 取り残しを見逃す。
- (b) **開いた union を閉じていると誤認する**。`export type X = "a" | "b" | (string & {});`
  は TS の型としては任意の文字列を許すが、正規表現は `a` `b` だけを見て「一致」と判定する。
- (c) **別名参照を読めない**。`export type MemberRoleState = ConsoleRole | "owner" | "unassigned";`
  は正規表現では `owner` `unassigned` しか取れないため、現状**登録できない** (登録すれば
  誤って赤くなる)。実際に本リポジトリの `resources/js/types/admin.ts` がこの形で、
  PHP の `MemberRoleState` (5 値) と**値集合が完全一致しているのに検査対象外**である。
- (d) 宣言の本体を「最初の `;` まで」で切るため、`;` を含む型 (オブジェクト型リテラル等) が
  union に混ざると本体を取り違える。

つまり現状は「狭い」だけでなく「(a)(b) の形で静かに間違えうる」。

### 問題 2: 列挙を 1 つ足すたびに検査を 1 本手で足す形になっている

`tests/Architecture/ManualEnumTsSyncInvariantTest.php` の test 宣言数は
2026-07 の 6 件から現在 **12 件** (列挙ごとの検査 11 件 + 取りこぼし防止の自己検査 1 件) へ
増えている。同じヘルパを使う PHP テストは 4 本 (他に参照だけの 1 本)。
検査の本体は 1 行しか違わないのに、ファイルと test 宣言だけが単調増加している。

### 問題 3: 母集団が手書きの決め打ちで、既に大きな取りこぼしがある

本設計の実測 (`devnotes/20260817-1748-enum-ts-generic-sync-gate/survey.md`) では、
PHP の文字列付き列挙 112 本に対し、TS 側に値集合が完全一致する宣言が 27 組ある。
**うち検査されているのは 14 組だけ**で、13 組が未検査のまま放置されている
(`PlanCode` / `AdminConsoleRole` / `DashboardState` / `DashboardRole` /
`OrganizationRole` / `BillingFeedbackKind` / `PurchaseFormState` / `TakeStatus` /
`AnalysisStep` / `AnalysisConflictType` / `ScenarioConflictType` / `ManualSortOption` /
`MemberRoleState`)。プラン符号や役割のような**課金と権限の語彙**が入っているので、
取りこぼしの実害は小さくない。

## 改善アイデア

**検査を「TypeScript の型情報で読む 1 本の汎用 gate」へ作り直し、対象は目録の 1 行にする。**

1. 抽出は **TypeScript コンパイラの型情報**で行う。型別名の宣言を型検査器で解決し、
   その型が**文字列リテラル型だけの union (または単一の文字列リテラル型)** であることを
   要求する。1 つでもそれ以外の構成要素があれば**受理せず例外で落とす**(fail-closed)。
   これで問題 1 の (a)(b)(c)(d) がすべて閉じる。
2. PHP 側の値は、**PHP 列挙ファイルを最小の字句解析で読む**。受理する文法を明文化し、
   それ以外はすべて例外で落とす (**もう 1 つの静かに間違える抽出器を作らない**)。
   - 受理するのは「`enum 名: string` の本体の**直下** (波括弧の深さ 1) にある
     `case 名 = '値';` / `case 名 = "値";`」だけ。注釈 (`//` `#` `/* */`)・文字列の中身・
     属性 (`#[...]`)・メソッド本体の中の `switch` の `case` は**深さと文脈で除外**する。
   - 逆に、深さ 1 に `case` で始まる行があって上の形に合わないときは**例外**にする
     (定数式・エスケープを含む値・複数行の値 = 受理範囲外)。
   - PHP を実行しない理由: CI の `frontend` job には PHP が入っていない
     (`.github/workflows/ci.yml` の `frontend` は `setup-php` も `composer install` も
     持たない)。検査を PHP 実行に依存させると CI の構成を変えることになる。
3. **抽出の失敗と空集合を混同しない**。抽出結果は「値の集合」を返すか例外を投げるかの
   どちらかで、`null` や空配列で失敗を表さない。例外の文面には
   **PHP 列挙のパス / TS のパス / 宣言名 / 落ちた理由**を必ず入れる。
4. 対象は **1 本の目録 (写しの一覧)** に集約する。1 組 = 1 行 (PHP 列挙ファイル /
   TS ファイル / TS 側の宣言名 / 一言の説明)。列挙を足したときの作業は**行を 1 つ足すこと**になる。
5. 旧実装 (`tests/Support/TsUnionValues.php` と、それを使う PHP テスト 4 本) は
   **同じ変更で消す** (思考原則 3: 後方互換の並走を残さない)。個別の 12 件は
   目録の行として汎用 gate の母集団に載り替える。
6. ついでに、**既に値集合が完全一致している未登録の 13 組を目録へ登録する**
   (問題 3 の解消)。いずれも現状で一致しているので、登録しても赤くはならない。

### 置き場所を PHP レーンから JS レーンへ移す判断

型情報を使う抽出には TypeScript コンパイラが要るので、検査は vitest レーン
(`tests/js/architecture/`) に置く。本リポジトリの TypeScript 側の目録型 gate
(`logout-call-site-inventory.test.ts` / `svg-inline-allowlist.test.ts` /
`atomic-import-graph.test.ts` 等) はすべてこの形で、**目録を test ファイル内の定数として持つ**
様式が既にある。家系のテンプレートと motivation の同種 gate も
`tests/js/architecture/enum-ts-sync.test.ts` である。したがって置き場所は既存の様式に一致する。

PHP レーンに委譲用の薄いテストを残す案 (aigenba がとった形) は**採らない**。
本リポジトリの 4 本は列挙 ⇔ TS の突き合わせ**だけ**を行っており、他の検査と同居していない。
残すと「同じ不変条件を 2 レーンで宣言する」並走になる。検証コマンドは
`composer test` と `pnpm test` の両方が緑であることを commit の条件にしているので
(AGENTS.md 実装規約)、レーンが移っても commit 前に必ず走る。

## 期待効果

守る範囲は **「サーバーが送る文字列の値を TS の union で受けている箇所」だけ**である。
表示ラベル・並び順・画面側だけの状態・部分集合の関係は**守らない** (下記「保証しないもの」)。

- **使命への貢献**: 撮影 PWA / マニュアル画面が状態値で分岐する面は多く、
  値の取り残しは「押しても何も起きない」「空白の画面」という詰みに直結する。
  母集団が 14 組 → 27 組に増えることで、課金・権限・解析・撮影の語彙が検査に載る。
  ただし**組ごとに現場価値は同じではない** (撮影テイクの状態は撮影が止まる /
  並び順の選択肢は表示が乱れるだけ)。「全部が同じ重さ」とは書かない。
- **静かに間違える経路が閉じる**: 注釈内の引用符と開いた union の 2 つは、
  現在**取り残しを緑にしうる**。型情報で読めば構造上起きない。
- **増殖が止まる**: 列挙を足したときの作業が「テストを 1 本書く」から「目録に 1 行足す」になる。
- **家系への追従**: 裁定 AG-099 の前半 (共通抽出基盤 + 型情報 + 目録への集約) を満たす。
  aigenba が 2026-08-17 に同じ形を着地させており、設計の当たりは既に取れている。

### 保証しないもの (誇張しない)

- **登録していない写しは 1 件も検査しない**。全数走査と既定拒否の分類 (AG-099 後半) は
  本作業に入らないので、**新しい列挙と新しい TS 宣言の組は、目録に 1 行足すまで永久に対象外**である。
- 検査するのは**値の集合の一致だけ**。表示ラベル・並び順・値の意味は見ない。
- 部分集合・上位集合の関係は表現できない。
- `.svelte` の中の宣言・定数配列・switch の case ラベルは読まない (登録されたら fail-closed で落ちる)。
- **正本のレーンは vitest (`pnpm test`) である**。`composer test` だけを回しても
  この不変条件は検証されない。CI では `frontend` job が保護境界になる。

## 実装方針(概要)

| # | 施策 | 主な変更 |
|---|------|---------|
| A | 汎用 gate の新設 | `tests/js/architecture/enum-ts-sync.test.ts` (目録 + 突き合わせ) / `tests/js/support/enum-ts-sync/` (型情報の入口・TS 値集合の抽出・PHP 列挙の読み取り) |
| B | 抽出器の自己検査 | `tests/js/architecture/enum-ts-sync-extractor.test.ts` + 見本ファイル群。**旧形が静かに合格する変異**を実測して記録する |
| C | 旧実装の撤去 | `tests/Support/TsUnionValues.php` と PHP テスト 4 本を削除。`TicketLedgerReaderInventoryTest` の案内文を新しい目録へ向ける |
| D | 母集団の拡張 | 未登録で値集合が完全一致する 13 組を目録へ登録 (14 組 → 27 組) |
| E | 規約・文書 | AGENTS.md ドメイン規約に 1 項追加 / `docs/architecture.md` に「保証しないもの」を含む節を追加 |

## 制約・前提

- CI の `frontend` job に PHP は無い → PHP 実行に依存しない (字句解析で読む)。
- `tsconfig.json` は `tests/js/**/*.ts` を型検査対象に含む → 施策 B の**壊れた見本**は
  `exclude` へ足して `pnpm typecheck` から外す (aigenba の申し送りと同じ手当)。
- 型情報の入口 (program) の構築は最初のテストの中で走ると高負荷時に既定の制限時間を
  超えうる (aigenba の実測)。**目録に出てくる TS ファイルだけを起点**にし、
  `beforeAll` で明示的な制限時間を与えて 1 度だけ作る。
- 値集合の突き合わせは**完全一致のみ**を扱う。部分集合の関係
  (`SelectableTakeStatus` / `DashboardJobStatus`) は登録しない。
- `.svelte` の中の宣言は本設計では扱わない (現状 1 件も無い。登録されたら fail-closed で落とす)。

## スコープ外

- **裁定 AG-099 の後半**: 発見の段 (PHP 列挙を全数走査して未分類を既定拒否で落とす) と
  逆走査 2 規則。aigenba も未着手である。
  - **本作業の完了条件に「後続 TODO の起票」を含める**。起票内容は
    「PHP の文字列付き列挙を全数走査し、**登録済み / 対象外の理由つき / 抽出できない残余**の
    3 つに既定拒否で分類する」+「逆走査 2 規則 (未登録の完全一致候補 /
    名前の対応と値の交差による既に食い違った写しの検出)」。
  - 実測 (`survey.md`) では施策 D の後に逆走査の規則 1 が拾う残りは
    `SelectableTakeStatus` 1 件だが、**この数は網羅の証拠ではない**。
    実測は正規表現で拾える宣言だけを数えており (別名参照は数えられていない)、
    48 件という母数は下限である。**後続 TODO の見積りの仮説**として扱い、
    「取りこぼしが 1 件しかない」とは書かない。
- **スキーマ正本から TS を生成する方式** (`schema-codegen-ts-pattern`) — 別 feature。
- 部分集合・上位集合の関係の検査、`.svelte` / 定数配列 / switch の case ラベルの抽出。
- 値以外の同期 (ラベル文言・表示順)。

## 実測 (参考)

# 実測: 本リポジトリの PHP 列挙 ⇔ TS 値集合の対応関係 (2026-08-17)

数え方と再現手順は `survey.py` (設計時の使い捨て。`scripts/` へは昇格しない)、
生の出力は `survey-raw.txt`。

- PHP の文字列付き列挙: **112 本** (`app/**/*.php` の `enum X: string`)
- TS 側で「値だけの集合」として読める宣言: **48 件**
  (`export type X = "a" | "b";` と `const X = [...] as const` を正規表現で拾ったもの。
  別名参照を含む宣言は拾えていないので、これは**下限**である)

## 現在検査されている 14 組

| # | PHP 列挙 | TS ファイル | TS 宣言 | 現在の検査 |
|---|---|---|---|---|
| 1 | `Manual\VideoManualStatus` | `types/manual.ts` | `VideoManualStatus` | ManualEnumTsSyncInvariantTest |
| 2 | `Manual\ManualProgress` | `types/manual.ts` | `ManualProgress` | 同 |
| 3 | `Manual\RenderKind` | `types/manual.ts` | `RenderKind` | 同 |
| 4 | `Manual\RenderStep` | `types/manual.ts` | `RenderStep` | 同 |
| 5 | `Manual\RenderErrorCode` | `types/manual.ts` | `RenderErrorCode` | 同 |
| 6 | `Manual\RenderConflictType` | `types/manual.ts` | `RenderConflictType` | 同 |
| 7 | `Manual\ScenarioVerdict` | `types/manual.ts` | `ScenarioVerdict` | 同 |
| 8 | `Manual\ScenarioRuleCode` | `types/manual.ts` | `ScenarioRuleCode` | 同 |
| 9 | `Manual\JobStatus` | `types/manual.ts` | `AnalysisJobStatus` | 同 |
| 10 | `Manual\MaterialType` | `types/manual.ts` | `CutMaterialType` | 同 |
| 11 | `Manual\MaterialType` | `types/capture.ts` | `MaterialType` | 同 (写しが 2 ファイルにある) |
| 12 | `Notification\NotificationType` | `types/notification.ts` | `NotificationType` | NotificationTypeTsSyncInvariantTest |
| 13 | `Billing\OnboardingBillingState` | `types/billing.ts` | `BillingStateValue` | OnboardingBillingStateTsSyncInvariantTest |
| 14 | `AccountDeletionBlockerAction` | `types/account.ts` | `AccountDeletionBlockerAction` | AccountDeletionBlockerActionTsSyncInvariantTest |

## 未検査だが値集合が完全一致している 13 組 (施策 D で登録する)

| # | PHP 列挙 | TS ファイル | TS 宣言 | 備考 |
|---|---|---|---|---|
| 15 | `PlanCode` | `types/Auth.ts` | `PlanCode` | 課金プランの符号 |
| 16 | `AdminConsoleRole` | `types/admin.ts` | `ConsoleRole` | 管理画面の役割 |
| 17 | `MemberRoleState` | `types/admin.ts` | `MemberRoleState` | **正規表現では読めない** (`ConsoleRole \| "owner" \| "unassigned"` の別名参照)。型情報でのみ一致が取れる |
| 18 | `OrganizationRole` | `lib/shared-props.ts` | `OrganizationRoleValue` | 共有 props の役割 |
| 19 | `Billing\BillingFeedbackKind` | `types/billing.ts` | `BillingFeedbackKind` | 課金画面の通知種別 |
| 20 | `Billing\PurchaseFormState` | `types/billing.ts` | `PurchaseFormStateValue` | 購入フォームの状態 |
| 21 | `Manual\TakeStatus` | `types/capture.ts` | `TakeStatus` | 撮影テイクの状態 |
| 22 | `Dashboard\DashboardState` | `types/dashboard.ts` | `DashboardState` | ダッシュボードの状態 |
| 23 | `Dashboard\DashboardRole` | `types/dashboard.ts` | `DashboardRole` | ダッシュボードの役割 |
| 24 | `Manual\AnalysisStep` | `types/manual.ts` | `AnalysisStep` | 解析の段階 |
| 25 | `Manual\AnalysisConflictType` | `types/manual.ts` | `AnalysisConflictType` | 解析の衝突種別 |
| 26 | `Manual\ScenarioConflictType` | `types/manual.ts` | `ScenarioConflictType` | 台本の衝突種別 |
| 27 | `Manual\ManualSortOption` | `types/manual.ts` | `ManualSortOption` | 一覧の並び順 |

## 登録しないもの (理由つき)

| TS 宣言 | 理由 |
|---|---|
| `types/manual.ts::SelectableTakeStatus` | 「選択できるテイクの状態」という**部分集合の意図**を持つ宣言。今は `TakeStatus` と全一致だが、完全一致で縛ると意図と食い違う。逆走査 (AG-099 後半) の候補として残す |
| `types/dashboard.ts::DashboardJobStatus` | `JobStatus` の真部分集合 (進行中のみ)。注釈でそう書かれている |
| `types/capture.ts::CaptureProgress` | 対応する PHP 列挙が無い (画面側だけの語彙) |
| `components/atoms/*.types.ts` の見た目の語彙 (`ButtonVariant` / `BadgeTone` / `ModalSize` 等) | デザインシステムの語彙でサーバー側に対応が無い |
| `lib/stores/toast.ts::ToastType` / `lib/stores/flash-to-toast.ts::FLASH_KEYS` | 値集合は `AlertType` と同じだが、対応する PHP 列挙が無い |
| `lib/capture/*` / `lib/debug/*` の状態語彙 | 画面側の内部状態。サーバーへ出ない |

## 逆走査 (AG-099 後半) の見積り

施策 D の 13 組を登録した後、「値集合が完全一致するのに未登録」で残るのは
**`SelectableTakeStatus` の 1 件だけ**である。つまり後半の規則 1 を入れるときの
免除登録は 1 件で足りる見込みで、後続 TODO の規模は小さい。

**ただしこれは網羅の証拠ではない**。本実測は正規表現で読める宣言だけを数えており、
別名参照を含む宣言 (#17 の `MemberRoleState` がまさにそれ) は数えられていない。
48 件という母数は**下限**である。ここに書いた「残り 1 件」は後続 TODO の**見積りの仮説**で、
実際の残りは AG-099 後半の全数走査を入れて初めて確かめられる。

## 関連する現行コード

### tests/Support/TsUnionValues.php
```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use BackedEnum;
use RuntimeException;

/**
 * PHP enum ⇔ TS literal union の値集合同期 invariant 用の抽出ヘルパ。
 * ManualEnumTsSyncInvariantTest / NotificationTypeTsSyncInvariantTest が共有する
 * (T008 で ManualEnumTsSyncInvariantTest 内のローカル関数から昇格)。
 */
final class TsUnionValues
{
    /**
     * TS ファイルから `export type {Name} = "a" | "b" | ...;` の値集合を抽出する。
     * 抽出不能 (degenerate PASS) は fail させる (RuntimeException)。
     *
     * @param  string  $relativePath  base_path からの相対パス (例: resources/js/types/manual.ts)
     * @return list<string>
     */
    public static function extract(string $relativePath, string $typeName): array
    {
        $path = base_path($relativePath);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("TS ファイルを読めません: {$path}");
        }

        // `export type X =` から次の `;` までを取り出す (複数行 union 対応)
        $matched = preg_match(
            '/export\s+type\s+'.preg_quote($typeName, '/').'\s*=\s*(.*?);/s',
            $contents,
            $matches,
        );
        if ($matched !== 1) {
            throw new RuntimeException("TS union が抽出できません (degenerate PASS 防止): {$typeName}");
        }

        $literalCount = preg_match_all('/"([^"]+)"/', $matches[1], $literals);
        if ($literalCount === false || $literalCount === 0) {
            throw new RuntimeException("TS union のリテラルが抽出できません: {$typeName}");
        }

        $values = $literals[1];
        sort($values);

        return $values;
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @return list<string>
     */
    public static function enumStringValues(array $cases): array
    {
        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
        sort($values);

        return $values;
    }
}
```
### tests/Architecture/ManualEnumTsSyncInvariantTest.php
```php
<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\ManualProgress;
use App\Enums\Manual\MaterialType;
use App\Enums\Manual\RenderConflictType;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\RenderStep;
use App\Enums\Manual\ScenarioRuleCode;
use App\Enums\Manual\ScenarioVerdict;
use App\Enums\Manual\VideoManualStatus;
use Tests\Support\TsUnionValues;

/*
 * PHP enum ⇔ TS literal union の値集合同期 invariant (概念設計 Round 3)。
 *
 * resources/js/types/manual.ts の literal union を正規表現で抽出し、PHP enum の
 * 値集合と完全一致することを固定する (フロントの CTA 分岐・型分岐が enum 追加で
 * silent に壊れるのを防ぐ)。抽出不能 (degenerate PASS) は fail させる。
 * 抽出ロジックは共有 helper (Tests\Support\TsUnionValues) に置き、
 * NotificationTypeTsSyncInvariantTest と共用する。
 */

/**
 * types/manual.ts から `export type {Name} = "a" | "b" | ...;` の値集合を抽出する。
 *
 * @return list<string>
 */
function extractTsUnionValues(string $typeName): array
{
    return TsUnionValues::extract('resources/js/types/manual.ts', $typeName);
}

test('VideoManualStatus の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('VideoManualStatus'))
        ->toBe(TsUnionValues::enumStringValues(VideoManualStatus::cases()));
});

test('ManualProgress の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('ManualProgress'))
        ->toBe(TsUnionValues::enumStringValues(ManualProgress::cases()));
});

test('RenderKind の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderKind'))->toBe(TsUnionValues::enumStringValues(RenderKind::cases()));
});

test('RenderStep の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderStep'))->toBe(TsUnionValues::enumStringValues(RenderStep::cases()));
});

test('RenderErrorCode の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderErrorCode'))->toBe(TsUnionValues::enumStringValues(RenderErrorCode::cases()));
});

test('RenderConflictType の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderConflictType'))->toBe(TsUnionValues::enumStringValues(RenderConflictType::cases()));
});

test('ScenarioVerdict の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('ScenarioVerdict'))->toBe(TsUnionValues::enumStringValues(ScenarioVerdict::cases()));
});

test('ScenarioRuleCode の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('ScenarioRuleCode'))->toBe(TsUnionValues::enumStringValues(ScenarioRuleCode::cases()));
});

test('AnalysisJobStatus (JobStatus 共用) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('AnalysisJobStatus'))->toBe(TsUnionValues::enumStringValues(JobStatus::cases()));
});

test('抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
    expect(fn (): array => extractTsUnionValues('NoSuchUnionName'))
        ->toThrow(RuntimeException::class, 'degenerate PASS');
});

/*
 * MaterialType の TS 側の写しは **2 ファイルにある** (PC 側 types/manual.ts の CutMaterialType /
 * 撮影 PWA 側 types/capture.ts の MaterialType)。2 つの types ファイルは
 * 「PC は署名 URL の口を持たない」という理由で意図的に分けてあり、片方が他方を import すると
 * その分離が崩れる。したがって**写しは 2 つ残し、両方を enum と突き合わせる**
 * (片方だけ pin すると drift が起きる)。
 */
test('CutMaterialType (types/manual.ts) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('CutMaterialType'))->toBe(TsUnionValues::enumStringValues(MaterialType::cases()));
});

test('MaterialType (types/capture.ts) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(TsUnionValues::extract('resources/js/types/capture.ts', 'MaterialType'))
        ->toBe(TsUnionValues::enumStringValues(MaterialType::cases()));
});
```
### tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php
```php
<?php

declare(strict_types=1);

use App\Enums\Billing\OnboardingBillingState;
use Tests\Support\TsUnionValues;

/*
 * OnboardingBillingState (PHP enum) ⇔ resources/js/types/billing.ts の BillingStateValue
 * (TS literal union) の値集合同期 invariant。
 *
 * この union は /billing と /dashboard の**両方**で分岐に使われる (dashboard は
 * bug-hunt 20260811-003230 F-2-01 の是正で state 分岐になった)。case 追加が
 * TS 側の更新なしに通ると、新状態が画面で「どの分岐にも当たらない」= 無言の描画漏れになる。
 */

test('OnboardingBillingState の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    $enumValues = TsUnionValues::enumStringValues(OnboardingBillingState::cases());

    // 母集団 0 件での degenerate PASS を防ぐ (空 vs 空は一致してしまう)
    expect($enumValues)->not->toBeEmpty();

    expect(TsUnionValues::extract('resources/js/types/billing.ts', 'BillingStateValue'))
        ->toBe($enumValues);
});

test('billing.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
    expect(fn (): array => TsUnionValues::extract('resources/js/types/billing.ts', 'NoSuchUnionName'))
        ->toThrow(RuntimeException::class, 'degenerate PASS');
});
```
### tsconfig.json
```json
{
    "extends": "@tsconfig/svelte/tsconfig.json",
    "compilerOptions": {
        "target": "ESNext",
        "module": "ESNext",
        "moduleResolution": "bundler",
        "resolveJsonModule": true,
        "allowJs": true,
        "checkJs": false,
        "strict": true,
        "esModuleInterop": true,
        "skipLibCheck": true,
        "forceConsistentCasingInFileNames": true,
        "isolatedModules": true,
        "paths": {
            "@/*": ["./resources/js/*"]
        },
        "types": ["node"]
    },
    "include": [
        "resources/js/**/*.ts",
        "resources/js/**/*.svelte",
        "tests/js/**/*.ts",
        "scripts/**/*.ts"
    ],
    "exclude": ["node_modules", "tmp"]
}
```
### vitest.config.ts
```ts
import { defineConfig } from "vitest/config";
import { svelte } from "@sveltejs/vite-plugin-svelte";
import { svelteTesting } from "@testing-library/svelte/vite";
import path from "path";
import { testProject } from "./scripts/test-inventory-config";

export default defineConfig({
    plugins: [
        svelte({
            hot: !process.env.VITEST,
            compilerOptions: {},
        }),
        svelteTesting(),
    ],
    test: {
        globals: true,
        environment: "jsdom",
        // CPU を食い尽くさないよう並列ワーカーをコア数の半分に抑える
        // (環境非依存: 10コア→5, 8コア→4 のように自動追従)
        maxWorkers: "50%",
        minWorkers: 1,
        setupFiles: ["./tests/js/setup.ts"],
        // include の正本は scripts/test-inventory-config.ts (2 project 分を 1 箇所で持つ)。
        // scripts/vitest-inventory-gate.test.ts が FS 走査と突き合わせて漏れを検出する。
        include: [...testProject("root").include],
        coverage: {
            provider: "v8",
            reporter: ["text", "json", "html"],
            exclude: [
                "node_modules/",
                "tests/",
                "**/*.d.ts",
                "**/*.config.*",
                "**/mockData",
            ],
        },
    },
    resolve: {
        alias: {
            "@": path.resolve(__dirname, "./resources/js"),
        },
    },
});
```
### .github/workflows/ci.yml の frontend job
```yaml
  frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
      # version は指定しない: package.json の packageManager (pnpm@11.9.0) を単一の正本にする。
      # 両方書くと action が "Multiple versions of pnpm specified" で即 fail する
      # (実際に 11.3.0 と packageManager が食い違い、pnpm を使う 3 job が全部落ちていた)。
      - uses: pnpm/action-setup@v6
      - uses: actions/setup-node@v7
        with:
          node-version: 22
          cache: pnpm
      - name: Install dependencies
        run: pnpm install --frozen-lockfile
      - name: ESLint
        run: pnpm lint
      - name: TypeScript
        run: pnpm typecheck
      - name: Vitest
        run: pnpm test
      - name: TypeScript (workspace packages)
        run: pnpm typecheck:packages
      # emit 経路 (packages/cli/tsconfig.json) の検証。
      # typecheck:packages が使う tsconfig.test.json は noUnusedLocals/noUnusedParameters を
      # 明示的に false にしているため、**build を通さないと検出できないエラーが存在する**。
      # 「typecheck があるから build は不要」は成立しない (実測: main で TS6133/TS6192 7 件)。
      - name: Build (workspace packages)
        run: pnpm build:packages
      - name: Vitest (workspace packages)
        run: pnpm test:packages
      - name: Build
        run: pnpm build

```
### 既存の JS 側 architecture テストの様式 (抜粋)
```ts
import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * ログアウト導線が Inertia visit 一本であることを deny-by-default で固定する。
 *
 * 経路 C (Inertia history 暗号化 + ログアウト時の履歴鍵破棄。bug-hunt F-4-01) の保証は
 * 「clearHistory: true を含む Inertia page をクライアントが適用すること」に乗っている。
 * JSON 204 で完結する logout (fetch/axios) を足すと、鍵が消えないまま画面が残り、
 * ブラウザバックで PII が復元されうる。
 *
 * 新しいログアウト導線を足したい場合は、それが Inertia visit (router.post) であることを
 * 確認した上で inventory に登録すること。docs/supported-browsers.md の経路 C の記述も更新する。
 *
 * 既知の限界: 検出は **文字列リテラル `"/logout"`** に限定される。将来 `route("logout")` の
 * ような名前解決ヘルパを導入すると検出外になるため、その際は本テストのパターンも同時に更新する。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");

/**
 * `/logout` を参照してよいファイル (resources/js からの相対パス)。
 * 現状 4 箇所あり、いずれも router.post = Inertia visit
 * (AppLayout: 通常画面のユーザーメニュー / VerifyEmail: メール認証待ち画面の離脱導線 /
 *  RecentAuthRecoveryNotice: 再認証手段が無いユーザーの回復導線 = ログアウトして guest として
 *  パスワードを再設定する。/forgot-password は guest middleware 付きで直リンクできない。
 *  全画面 confirm (pages/Auth/ConfirmRecentAuth) とインラインモーダル
 *  (organisms/RecentAuthModal) の双方が本 molecule を使う /
 *  Capture/Account: 撮影 PWA のアカウント確認画面。共有端末の引き渡し時に
 *  「自分のアカウントか確認してログアウトする」だけを行う面で、doc/05 §5.2 が要求する
 *  ログアウトをこの画面自身が持つ)。
 */
const LOGOUT_CALL_SITE_INVENTORY: readonly string[] = [
  "components/templates/AppLayout.svelte",
  "pages/Auth/VerifyEmail.svelte",
  "components/molecules/RecentAuthRecoveryNotice.svelte",
  "pages/Capture/Account.svelte",
] as const;

const LOGOUT_PATH_PATTERN = /["'`]\/logout["'`]/;
/** 非 Inertia 経路 (これが同一ファイルにあると 204 完結の logout になりうる)。 */
const NON_INERTIA_CLIENT_PATTERN = /\b(fetch|axios)\s*\(/;

const SOURCE_EXTENSIONS: readonly string[] = [".svelte", ".ts"] as const;
```
### PHP 列挙の実例 (App\Enums\MemberRoleState — メソッド内に match 式を持つ)
```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ユーザー管理画面の表示状態 (毎リクエスト導出。DB に保存しない = backfill 不要)。
 * org ロール × Default Project pivot の全組合せを漏れなく 5 値に分類する
 * (概念設計 D2 の canonical mapping)。
 */
enum MemberRoleState: string
{
    case Owner = 'owner';           // 管理者 (オーナー)。変更不可 (transferOwnership のみ)
    case Admin = 'admin';           // 管理者。stale pivot があっても org ロール優先で無視
    case Editor = 'editor';         // 編集者 (org Member + project_admin)
    case Shooter = 'shooter';       // 撮影者 (org Member + project_member)
    case Unassigned = 'unassigned'; // 未割当 (org Member + pivot なし)。割当を促す表示

    /**
     * org ロール null (organization_user attach 済みだが Laratrust ロール未付与の異常行) も
     * Unassigned へ丸める: 異常行を非表示にせず「未割当」として可視化し、管理画面から
     * ロール割当コマンドで修復できるようにする (applyConsoleRole の修復経路と対)。
     * null 判定は project pivot 判定より**必ず先**に評価する (org ロールなし + stale pivot が
     * Editor/Shooter と誤表示され修復契約と食い違うのを防ぐ)。
     */
    public static function derive(?OrganizationRole $orgRole, ?ProjectRole $projectRole): self
    {
        return match (true) {
            $orgRole === null => self::Unassigned,
            $orgRole === OrganizationRole::Owner => self::Owner,
            $orgRole === OrganizationRole::Admin => self::Admin,
            $projectRole === ProjectRole::Admin => self::Editor,
            $projectRole === ProjectRole::Member => self::Shooter,
            default => self::Unassigned,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => '管理者（オーナー）',
            self::Admin => '管理者',
            self::Editor => '編集者',
            self::Shooter => '撮影者',
```
