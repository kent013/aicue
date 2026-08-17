Round 1 の指摘への対応を報告する。対応マトリクスと、修正後の詳細設計書 (全文) を示す。

補足の事実 (レビューの前提として確認済み):
- `resources/js` に TypeScript の `enum` は 1 件も存在しない (grep で 0 件)。
- `resources/js/lib/recaptcha.ts` に `declare global` が実在する (指摘 (f) が机上でないことの裏づけ)。
- `docs/template-divergence.md` の記録の原則は「登録するか迷ったら登録する」「台帳リポジトリの巡回から『記録されるべき乖離』として届いた指摘は登録する側へ倒す」と定めており、状態の値域は `恒久` / `監視中` の 2 つ。既存の最大番号は D26 (番号は再利用しない)。冒頭の件数・エントリ・検査の定数の 3 点一致が機械強制されている。

残る [Critical] / [Warning] が無ければ APPROVED を、あれば追加の指摘を出してほしい。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

## [Critical] A: 目録ファイルだけを root にした program は実際の型世界と一致しない
- 判断: **対応する**
- 根拠: 指摘のとおり偽陰性 (取り残しを緑にする) になる。しかも机上の話ではない —
  本リポジトリには `resources/js/lib/recaptcha.ts` に `declare global` が実在する。
- 対応内容: `rootNames` を **`parsed.fileNames` ∪ 目録のファイル**にし、
  `projectReferences` と `configFileParsingDiagnostics` も渡す形へ変更。
  見本用には別関数 `createFixtureProgram` を用意して縮めた program はそちらだけに閉じ込めた。
  速さは実測して報告に残すこととし、**速さのために起点を縮める判断はしない**と明記した。
  負例行列に T25 (別ファイルの `declare global` / モジュール拡張で値が増える) を追加し、
  縮めた program に戻したら赤くなる回帰にした。

## [Warning] A: `parsed.errors` を見ていない
- 判断: 対応する
- 対応内容: `parsed.errors.length > 0` と `program.getOptionsDiagnostics()` を例外化。
  目録が指すファイルの `getSyntacticDiagnostics` も検査する。
  **意味の診断は見ない**ことと、その分担 (`pnpm typecheck` の担当) を「保証しないもの」へ明記した。

## [Warning] A: 型の受理方針が未確定 (enum literal / 組み込み型 / 条件型 / 循環)
- 判断: 対応する
- 対応内容: 受理範囲を表で明文化した (`Lowercase<>` / `keyof typeof` / 具体化した条件型 /
  有限のテンプレートリテラルは受理、開いたテンプレートリテラル・未具体化の条件型・
  `unique symbol`・`string & {}` は例外)。**TypeScript の `enum` は明示的に拒否**する
  (`ts.TypeFlags.EnumLiteral` を除外)。理由: 本リポジトリに TS の `enum` は 1 件も無く
  (実測)、必要になってから広げる方が安全 (思考原則 2)。
  行列に T12-T23 を追加した。

## [Warning] A: TS の重複値検査は機能しない
- 判断: **反論せず削除する**
- 根拠: 指摘のとおり `"a" | "a"` は正規化で `"a"` になるため、値集合の側から元の重複は観測できない。
  旧設計の「同じ値が 2 回なら例外」は**誤った主張**だった。
- 対応内容: 検査ごと削除し、「重複は検出しないと明示する」へ変更。
  行列に T24 (正規化で消えることを固定する正例) を追加した。

## [Critical] A: PHP キーワードの大文字小文字
- 判断: 対応する
- 根拠: `CASE B = 'b';` を見落とすと**取り残しを緑にする**。最悪の壊れ方である。
- 対応内容: `enum` / `case` / `string` の照合を ASCII の大小無視にし、
  受理正規表現にも `i` を付けた。行列に P11 (大文字・混在) を追加した。

## [Critical] A: PHP 字句状態の仕様が不足
- 判断: 対応する
- 対応内容: 状態表 (コード / 単一引用符 / 二重引用符 / 行注釈 / ブロック注釈) を明文化。
  `#[` を行注釈に入れない、バッククォート・`?>`・`__halt_compiler`・コード状態の `<<<` は
  例外、未終端は例外、無害化の写しは **UTF-16 の符号単位で同じ長さを保つ**
  (`for...of` を使わず添字で回す)、CRLF はそのまま残す、を追加。
  行列に P20-P25 / P29 / P30 を追加した。

## [Warning] A: 「範囲外はすべて例外」は保証できない
- 判断: 対応する
- 対応内容: 保証の文を指摘の文面どおりに狭めた
  (「enum 本体の直下で見つけた case は限定文法に一致するか例外になる」までで、
  ファイル全体の構文・名前空間・オートロードは検証しない)。

## [Warning] A: 複数行に関する仕様と正規表現の矛盾
- 判断: 対応する
- 対応内容: `\s*` を `[ \t]*` に変え、対象範囲に改行を含むなら例外にする、と明記。
  行列に P15 を追加した。

## [Warning] A: PHP の重複値が Set で消える
- 判断: 対応する
- 根拠: 旧テストは配列比較だったので値の重複を検出できた。移設で**保証が落ちる**のは不可。
- 対応内容: 抽出器で case 名の重複と backing 値の重複を例外にする (施策 A-4 の 6)。
  行列に P27 / P28 を追加し、施策 D の「引き継ぐもの」にも明記した。

## [Warning] B: 目録パスの検査が traversal を防いでいない
- 判断: 対応する
- 対応内容: 絶対パス・逆斜線・`.` / `..` の区間を拒否、`path.resolve` 後の包含
  (`php` は `app/` / `ts` は `resources/js/`)、**`fs.realpathSync` 後も同じ包含**、
  通常ファイルであること、拡張子の検査を「行の体裁」に追加した。

## [Warning] B: `mirrorProgram` の初期化が strict 上不明瞭
- 判断: 対応する
- 対応内容: `MirrorProgram | undefined` + `requireMirrorProgram()` の fail-closed な取り出しへ変更
  (`!` は使わない)。

## [Suggestion] B: 27 組すべてへの手動 mutation は過剰
- 判断: 対応する
- 根拠: 同じ比較器を 27 回通すだけで持続的な保証にならない、という理由に同意する。
- 対応内容: fail-first の実測を**代表 3 組** (`VideoManualStatus` /
  `MemberRoleState` / `PlanCode`) に絞った。件数 pin は維持し、
  **「網羅の証明ではない」を pin の注釈に書く**ことにした。

## [Warning] C: `.php.txt` とファイル名語幹検査の衝突 / 行列の不足 / 件数 pin の弱さ
- 判断: **より強く対応する (設計を変更)**
- 根拠: 語幹の剥がし方を作り込むより、**PHP の見本をファイルで持たない**方が問題ごと消える。
  型検査器に実ファイルが要る TS 側とは事情が違う。
- 対応内容:
  - PHP の見本は**テスト内の文字列**にし、`readPhpEnumValuesFromText(source, fileName)` を
    直接呼ぶ形へ変更 (`.php.txt` は廃止)。ファイルから読む包みは
    **実在する `app/Enums/**` の 2 本**を読ませて経路を通す。
  - 語幹の定義は「末尾の `.php` をちょうど 1 つ剥がす。未知の拡張子は例外」と明記。
  - TS の見本はファイルのまま。**行列の行数を pin** し、
    **`fixtures/` の実ファイル集合と行列が参照する集合を完全一致**で比べる
    (孤児も欠落も赤)。T10 / T25 の補助ファイルは別の一覧に分ける。
  - 指摘された追加ケースを TS 25 行 / PHP 30 行の行列へ取り込んだ。

## [Warning] D: 旧テストと新 gate は完全には同じ保証ではない
- 判断: 対応する
- 対応内容: 「同じ保証を移設」ではなく**「値集合の不変条件を移設し、構文・名前空間・
  オートロードの保証は既存 PHP レーンに依存する」**へ書き換え。
  backing 値の重複だけは抽出器で引き継ぐ。TS 側のソース重複は保証から外すと明記。
  18 件の対応表を **比較 14 件 + 自己検査 4 件**に分けて書くこととした。

## [Warning] D: 参照切れ検査が `TsUnionValues` だけでは不足
- 判断: 対応する
- 対応内容: 探す語を 8 種に増やし、探索の根に `AGENTS.md` / `scripts` / `config` / `.github` を追加。
  **`devnotes/` は歴史の記録なので残す**と明示的に分類した。

## [Suggestion] E: 「登録しない理由」の置き場所 / 「免除 inventory」と呼ばない
- 判断: 対応する
- 対応内容: 置き場所を gate ファイル冒頭の注釈と `docs/architecture.md` に定め、
  **機械的な発見が入るまで「免除の目録」とは呼ばない**と明記した。

## [Warning] F: `docs/template-divergence.md` に登録しない根拠が不足
- 判断: **対応する (判断を反転)**
- 根拠: 台帳自身の記録の原則が「登録するか迷ったら登録する」「台帳リポジトリの巡回から
  『記録されるべき乖離』として届いた指摘は登録する側へ倒す」と定めている。
  正典 (テンプレート) が PHP 列挙の**全数を分類する既定拒否**であるのに対し、
  本 v1 は**登録した写しだけ**を見るので、保護の境界が実際に違う。
- 対応内容: **D27** を新設 (状態 `監視中` / 見直し期限は実装日 + 180 日 /
  根拠は本 devnotes ディレクトリ / 再判定の条件は AG-099 後半の着地)。
  冒頭の件数 25 → 26 と `TemplateDivergenceLedgerFormatTest` の定数も同じ変更で直す
  (3 点一致が機械強制されているため)。番号は D26 まで使用済みなので D27。

## [Warning] F: 「保証しないもの」の正本が複数ある
- 判断: 対応する
- 対応内容: 分担表を作り、**正本は `docs/architecture.md` に一本化**。
  docblock は要約 + 参照、AGENTS.md は登録手順とレーンだけ (既存の規約 15 / 17 / 18 と同じ扱い)。
  指摘された PHP 側の 4 項目 (構文の妥当性 / 名前空間・オートロード / 全構文を受理しない /
  レーンの非対称) を正本の一覧へ追加した。

## 修正後の詳細設計書 (全文)

# 詳細設計: PHP 列挙 ⇔ TypeScript 値域の汎用同期 gate (裁定 AG-099 追従)

> Round 1 の Codex レビュー (CHANGES_REQUESTED) を反映した版。
> 対応の判断は `codex-history/design-review-decisions-round-1.md`。

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
| 単一引用符の文字列 | `'` | `'`(直前が `\` でない) | 中身は深さの計算から除く |
| 二重引用符の文字列 | `"` | `"`(同上) | 同上 |
| 行注釈 | `//` または `#`(**`#[` は除く**) | 改行 | 同上 |
| ブロック注釈 | `/*` | `*/` | 同上 |

**コード状態で次を見つけたら例外**(受理範囲外であることを理由に含める):
`` ` ``(バッククォート文字列) / `?>`(閉じタグ以降の HTML) /
`__halt_compiler`(大小無視) / `<<<`(ヒアドキュメント・ナウドキュメント)。
注釈や文字列の中にこれらがあるだけなら例外にしない(状態で判定するため)。
**走査が終わった時点で文字列・注釈の状態が閉じていなければ例外**。

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

- 全体 program の構築時間。実測して報告に残す。**速さのために偽陰性を作らない**。
- PHP の字句解析は本 gate 専用の最小実装であり、受理範囲外は例外なので
  読めない形が入ったら赤くなる(静かに間違えない)。

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
        mirrorProgram = createMirrorProgram([...new Set(ENUM_TS_MIRRORS.map((m) => m.ts))]);
    }, 180_000);

    it("目録の件数が pin と一致する", () => {
        expect(ENUM_TS_MIRRORS).toHaveLength(EXPECTED_MIRROR_COUNT);
    });

    it("目録の行の体裁が守られている", () => { /* 下記 */ });

    it.each(ENUM_TS_MIRRORS)("$php ⇔ $ts::$declaration の値集合が一致する", (mirror) => {
        const phpValues = readPhpEnumValues(mirror.php);
        const tsValues = readTsUnionValues(requireMirrorProgram(), mirror.ts, mirror.declaration);
        expect([...tsValues].sort()).toEqual([...phpValues].sort());
    });
});
```

**行の体裁**(パスで検査の外へ逃げられないようにする):

- 絶対パス・逆斜線・`.` / `..` の区間を含むパスを拒否する
- `path.resolve` した後に、`php` は `app/` 配下、`ts` は `resources/js/` 配下にあること
- **`fs.realpathSync` の結果でも同じ包含を確かめる**(symlink 経由の抜けを塞ぐ)
- 通常ファイルであること (`fs.statSync(...).isFile()`)
- `php` は `.php`、`ts` は `.ts` で終わること
- `ts` + `declaration` の組が重複しないこと(同じ宣言を 2 回登録しない)
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
- `tests/js/support/enum-ts-sync/fixtures/*.ts`(新規。**TS の見本だけ**)
- `tsconfig.json` の `exclude` に `tests/js/support/enum-ts-sync/fixtures/**` を追加

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
  抽出器の検査は `createFixtureProgram` で見本だけを起点にした program を使う。

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
| T10 | 別名参照(`Y` は**別ファイルから import**。`@/*` の経路も 1 件) | 受理し `Y` の値も含む |
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
| T25 | 別ファイルの `declare global` / モジュールの拡張で値が増える宣言 | **全体 program で受理し、増えた値を含む**(縮めた program を使うと落とす回帰の固定) |

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
| P23 | `?>` で閉じて HTML が続く | 例外 |
| P24 | `__halt_compiler()` | 例外 |
| P25 | 未終端の文字列 / 未終端のブロック注釈 | 例外 |
| P26 | ファイル名の語幹と enum 名が食い違う | 例外 |
| P27 | case の名前が重複 | 例外 |
| P28 | backing の値が重複 (`case A = 'a'; case B = 'a';`) | 例外 |
| P29 | 補助面の文字(絵文字)を注釈に含む | 受理(位置がずれない) |
| P30 | CRLF 改行 | 受理 |

### 見本ファイルの集合の固定

- 行列の**行数**を pin する(TS 25 行 / PHP 30 行)。
- **`fixtures/` に実在する `.ts` の集合と、行列が参照する見本パスの集合を完全一致で比べる**
  (孤児の見本も、行列にあって実在しない見本も、どちらも赤にする)。
  T10 / T25 が使う補助ファイルは「補助」として別の一覧に分けて宣言する。

### リスク

- 見本を 2 通りの置き方(TS はファイル / PHP は文字列)にするのは非対称である。
  **理由をテストファイルの冒頭に書く**(型検査器には実ファイルが要る / PHP は要らない)。

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
| 決めた日 | 実装日 |
| 決めた人 | 開発者 |
| 根拠 | `devnotes/20260817-1748-enum-ts-generic-sync-gate/` |
| 状態 | 監視中 |
| 見直し期限 | 実装日 + 180 日 |

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
