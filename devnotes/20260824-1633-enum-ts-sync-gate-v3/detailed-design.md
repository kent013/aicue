# 詳細設計: enum-ts-sync-gate v3 追従

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

**本作業に直接効く追加規約**:

- AGENTS.md §静的検査 (gate) と走査器の共通規約 **(a)〜(e)**
- AGENTS.md §走査器・gate を新設・変更するときに同じ PR で揃える **4 点**
- AGENTS.md §テンプレートとの関係 (指紋台帳・採用時債務・登録簿の件数 pin)
- app-design 3-0 段 (乖離台帳の確認段)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）— 本件は PHP の変更が無いので新規の型は増えないが、
  `composer phpstan` は緑を維持する
- **Pest** / **Vitest**。本件の正本のレーンは **`pnpm test`**
- **テストデータは必ず Factory で生成**（本件は DB を使わない）
- **アーリーリターン** 推奨
- **コードフォーマット**: `pnpm lint:fix`（`pnpm lint` の対象は `resources/js` のみ）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260824-1633-enum-ts-sync-gate-v3/conceptual-design.md` (Codex Round 4 で APPROVED)
- 実測: `devnotes/20260824-1633-enum-ts-sync-gate-v3/probe/measurements.md`
  (再現用スクリプト `probe/probe.ts`。**実装物ではない**)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 母集団モジュールの新設 (版管理下の全数 + 構文破壊見本の除外) | `tests/js/support/enum-ts-sync/population.ts` (新) | 高 |
| S2 | `.svelte` の仮想 TS 化 (1 ファイル 1 単位・モジュール文脈) | `tests/js/support/enum-ts-sync/svelte-source.ts` (新) | 高 |
| S3 | program をパッケージごとに作り、母集団を全部どれかに載せる | `tests/js/support/enum-ts-sync/program.ts` | 高 |
| S3b | 値の構文抽出を 1 本に切り出す (逆走査と前向きの共有) | `tests/js/support/enum-ts-sync/ts-literal-values.ts` (新) | 高 |
| S4 | 候補走査を 4 種へ + 派生の証人つき除外 + 判定保留の受け皿 | `tests/js/support/enum-ts-sync/ts-candidates.ts` | 高 |
| S5 | 規則 2 の論理和 | `tests/js/support/enum-ts-sync/reverse-sweep.ts` | 高 |
| S6 | 目録の受理範囲拡大 (`packages/*/src/` と `.svelte`) + 目録の改名 | `tests/js/support/enum-ts-sync/relation-inventory.ts` | 中 |
| S7 | 前向きの検査を 2 形 (型別名 / const の配列)・2 関係 (一致 / 部分集合)・`.svelte` へ | `tests/js/support/enum-ts-sync/ts-value-sets.ts` | 中 |
| S8 | 逆走査 gate の再整備 (申告・pin・メッセージ・保証範囲) | `tests/js/architecture/enum-ts-sync-discovery.test.ts` | 高 |
| S9 | 検出器の自己検査 (負例と故障注入) | `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts` + 見本 | 高 |
| S10 | 前向き gate の負の対照を新しい受理範囲へ | `tests/js/architecture/enum-ts-sync.test.ts` | 中 |
| S11 | 実ドリフト 2 件の是正 (API エラー符号 / CLI OAuth スコープ) | `packages/cli/src/api/schemas.ts` / `client.ts` / `oauth/login.ts` / `packages/cli/tests/` | 高 |
| S12 | 乖離台帳の手当て (D50 / 債務 1 行 / 件数 pin 2 つ) | `docs/template-divergence.md` / `adoption-debt.tsv` / `LedgerPins.php` | 中 |
| S13 | 文書の更新 | `AGENTS.md` / `docs/architecture.md` | 中 |

**設計時の実測 (probe2.ts。最終形の判定式)**:
母集団 `.ts` 377 本 (追跡下 378 本 − `.d.ts` 1 本) + `.svelte` 130 本 = 507 本 /
program 2 本 (`<root>` と `packages/cli`) を約 4.5 秒で構築 /
候補 345 件 (型の合併 106・対応表のキー 172・定数の配列 54・分岐のラベル 13) /
判定保留 3 件 / 派生の保留 86 件のうち証人つきで外れたのが 40 件 /
**鳴った組 10 件 (規則 1 = 6 / 規則 2a = 1 / 規則 2b = 3)**。
実ドリフトは **2 件** (API のエラー符号と CLI OAuth スコープ。どちらも道具パッケージ)。

---

## S1: 母集団モジュールの新設

### 変更箇所

- 新規: `tests/js/support/enum-ts-sync/population.ts`

### 波及変更

- TypeScript 型定義: 新規 (`ExcludedRoot`)
- API Resource/DTO: なし
- テストファイル: `enum-ts-sync-discovery-extractor.test.ts` に単体の負例を足す

### 変更後コード（骨子）

```ts
/**
 * 逆走査の母集団 (正典 v3 の i8)。
 *
 * **母集団**: `git ls-files -z` が返す**版管理下の `*.ts` と `*.svelte` の全数**。
 * 走査根の手書きの列挙は持たない (足し忘れが静かな穴になる)。
 * `-z` を使うのは、改行を含む合法なパスでも全数を列挙するためである。
 *
 * **2 つの一覧を区別する**:
 * - `listProgramTsFiles()` … 型世界に載せる起点。**`.d.ts` を含む**
 *   (周囲宣言が落ちると本番と違う型世界になる)
 * - `listCandidateTsFiles()` … 候補を探す対象。**`.d.ts` を除く**
 * どちらかが 0 件なら「母集団が不明」として例外にする (空振りを緑にしない)。
 *
 * **唯一の除外**: `EXCLUDED_ROOTS`。**わざと構文を壊した見本**だけを外す。
 * i14 が「構文が壊れたファイルを無言で読み飛ばさない」ので、これを母集団に入れると
 * 本番の gate が恒久的に赤くなる。申告では逃がせない (申告は候補を逃がす仕組みで、
 * 読めないファイルの受け皿ではない)。除外は `tests/js/support/enum-ts-sync/` の
 * 配下に限る (構造で縛る)。
 *
 * **保証しないもの**: 版管理外のファイル (無視されたもの・未追跡のもの) は見ない。
 * `.js` / `.mjs` / `.cjs` は母集団に入れない (本リポジトリの TS 以外の出口は
 * 本 gate の対象外である)。
 */
export interface ExcludedRoot {
    /** リポジトリ相対のディレクトリ。`tests/js/support/enum-ts-sync/` の配下だけ。 */
    readonly root: string;
    /** 外す理由 (30 文字以上)。 */
    readonly reason: string;
}

export const EXCLUDED_ROOTS = [
    {
        root: "tests/js/support/enum-ts-sync/fixtures/candidates-broken",
        reason: "候補走査が構文の壊れたファイルを無言で読み飛ばさないことの負の対照。中身は意図的に壊してある",
    },
] as const satisfies readonly ExcludedRoot[];

export const EXPECTED_EXCLUDED_ROOT_COUNT = 1;

/** `git ls-files -z` の生出力から一覧を作る**純関数** (0 件の分岐を単体で試験できるように分ける)。 */
export const parseTrackedOutput = (raw: string): readonly string[] => { … };

export const listProgramTsFiles = (root = REPO_ROOT): readonly string[] => { … };   // .d.ts を含む
export const listCandidateTsFiles = (root = REPO_ROOT): readonly string[] => { … }; // .d.ts を除く
export const listCandidateSvelteFiles = (root = REPO_ROOT): readonly string[] => { … };
/** 除外根の配下にある版管理下ファイル (除外の自己点検に使う。0 件は例外)。 */
export const listExcludedFiles = (root = REPO_ROOT): readonly string[] => { … };
/** 除外根の体裁 (配下・実在・重複無し・理由 30 文字以上)。 */
export const validateExcludedRoots = (roots = EXCLUDED_ROOTS, root = REPO_ROOT): void => { … };
```

- 除外の判定は**パスの区間一致** (`rel === root || rel.startsWith(root + "/")`)。
  素の `startsWith` にしない (兄弟ディレクトリ `candidates-broken-2/` を巻き込むため)
- **不正な `.svelte` の見本は追跡ファイルにしない** (S9 参照)。除外根は現時点で `.ts` だけを
  含む。将来 `.svelte` を除外根へ入れるなら、除外の自己点検 (S8) は
  **拡張子ごとに本番と同じ入口**を使う必要がある (`.ts` は TS の構文診断、
  `.svelte` は `toVirtualUnit()` の失敗)。この条件を docblock へ書く

### PHPStan適合チェック

- [x] PHP の変更なし

### テスト計画

- [ ] **先に赤くする**: `listCandidateTsFiles()` が `packages/cli/src/api/schemas.ts` を含むことを
      主張するテストを書く → モジュールが無いので解決に失敗して赤
- [ ] `listCandidateSvelteFiles()` が `.svelte` を返し、`listProgramTsFiles()` だけが `.d.ts` を含むこと
- [ ] 除外根の配下のファイルがどの候補一覧にも入らないこと / `listExcludedFiles()` には入ること
- [ ] `parseTrackedOutput("")` が空を返し、それを使う列挙が**例外になる**こと
      (「Git repository でない」ではなく「正常終了したが 0 件」の分岐を突く)
- [ ] 除外根の体裁の負例: 配下でないパス / 実在しないパス / 重複 / 理由 29 文字

### リスク

- 前提は「git の作業ツリーと索引が使えること」だけである (浅い clone でも索引の追跡ファイルは
  列挙できる)。`listTrackedPhpFiles` が既に同じ前提で動いているので新しいリスクではない

---

## S2: `.svelte` の仮想 TS 化

### 変更箇所

- 新規: `tests/js/support/enum-ts-sync/svelte-source.ts`

### 決めたこと (レビュー Round 1 / Round 2 の Critical に対する結論)

**`.svelte` 1 本につき仮想 TS を 1 本だけ作る**。module 文脈と実体文脈の**両方の中身を
元の位置のまま**残し、script の外は空白で潰す。末尾に `\nexport {};\n` を足して
**モジュール文脈**にする。

- **文脈ごとに別ファイルへ割らない**。割ると module の宣言を実体側から参照できなくなる
  (Svelte では参照できる)
- **`export {};` は必須**である。付けないと仮想ファイルが**大域スクリプト**になり、
  取り込みも書き出しも無いコンポーネント同士の宣言が**混ざる**。
  実測 (`probe/svelte-scope-probe.mjs`): `A.ts` が `type Shared = "a" | "b"` を宣言し
  `B.ts` が宣言せずに `type Ref = Shared` と書くと、`export {};` 無しでは
  **`Ref` が `"a" | "b"` に解決してしまう** (偽の候補が立つ)
- **必ず `\n` を前に付ける** (元のソースが改行で終わらない / 末尾が行注釈のときに
  `export {};` が注釈へ吸われるのを防ぐ)。末尾へ足すので既存の行も列も動かない

### 平坦化で再現できないものを fail-closed で塞ぐ (Round 2 の Critical)

1 本へ平坦化すると、Svelte 本来の可視性と食い違う形が 2 つ残る。
**保証外にするのではなく、構築時と gate で不合格にする**。

| 食い違い | Svelte 本来 | 平坦化した TS | 対処 |
|---|---|---|---|
| module から実体側の宣言を参照 | 見えない | 前方参照として解決する | **不合格** (下の検査 B) |
| module と実体に同名の最上位束縛 | 実体側が覆う (shadowing) | 同じ記号の重複宣言になる | **不合格** (下の検査 A) |
| 実体から module の宣言を参照 | 見える | 解決する | 正しいので許す |

- **検査 A (構築時)**: `toVirtualUnit()` が module 範囲と実体範囲の**最上位の束縛名**を集め、
  交わりが空でなければ例外にする。束縛を作る構文を**網羅する**
  (レビュー Round 3 の Warning) — 共通の `topLevelBindingNames(statements)` を 1 本置き、
  次を拾う:
  - 変数宣言 (**分割代入の個々の束縛名を含む**)
  - 関数宣言 / クラス宣言 / `enum` 宣言
  - `interface` 宣言 / 型別名の宣言
  - `namespace` / `module` 宣言
  - 取り込みの束縛 (既定・名前つき・名前空間) と `import x = …`
- **検査 B (program 構築の一本道)**: `assertNoModuleToInstanceReference(checker, file, unit)` が
  module 範囲の中の識別子について `checker.getSymbolAtLocation()` を引き、
  **その記号の宣言 (`symbol.declarations`) が実体範囲の中にあれば例外**にする。
  記号が別名 (取り込みなど。**`(symbol.flags & ts.SymbolFlags.Alias) !== 0` を確かめてから**
  `checker.getAliasedSymbol()` を呼ぶ) ならその先も見る —
  実体側の取り込みを module 側が参照した場合、指す先は外部ファイルでも
  **別名の宣言そのものは実体範囲の中**にあるので、そこで捕まえる
  - **呼び出しを「利用側の義務」にしない** (レビュー Round 3 の Critical)。
    `createMirrorPrograms()` が program を組んだ直後に**全仮想単位へ必ず走らせ**、
    検査を通った program だけを返す。低層の組み立て関数は**輸出しない**ので、
    検査を飛ばした `MirrorProgram` を外から作る経路が型で消える
  - 見本専用の縮めた program は**別の型 (`FixtureProgram`)** にして、
    検査済みの `MirrorPrograms` を要求する場所へ渡せないようにする
- どちらも**現物では 0 件**である (実測: module script を持つのは 2 本
  `components/atoms/Alert.svelte` と `templates/_helpers/SidebarNavItems.svelte` で、
  中身はどちらも型の宣言だけで実体側を参照しない)
- 検査 A / B の負例は**テストの中の文字列**で与える (追跡ファイルにしない)

### 受理する script 属性の表 (Round 2 の Warning)

| 文脈 | 属性 | 判定 |
|---|---|---|
| 実体 / module | 属性なし | 受理 (TS として読む) |
| 実体 / module | `lang="ts"` | 受理 |
| 実体 / module | `lang="js"` | **受理**して TS として読む (過剰検出の向き。JS は TS の部分集合として読める) |
| 実体 / module | `lang="<その他>"` | 不合格 |
| module | 値なしの `module` | 受理 (svelte 5 の module 文脈の印) |
| module | 値つきの `module="…"` | 不合格 |
| 実体 | `module` | 不合格 (`parse` の構造上は起きないが検査は置く) |
| 実体 / module | `src` / `context` / `generics` / 未知の属性 | 不合格 |

実測 (2026-08-24): 現物に在るのは `実体: lang="ts"` 130 件 / `module: lang="ts"` 2 件 /
`module: 値なしの module` 2 件の**ちょうど 3 種**。`src` / `context` / `generics` は 0 件。

### 変更後コード（骨子）

```ts
/**
 * `.svelte` を第一級の解析対象にする (正典 v3 の i6)。
 *
 * `svelte/compiler` の `parse` (解析ツール向けの入口) で script の範囲を取り、
 * **script の中身以外を空白で潰した**仮想 TypeScript を 1 本作る。潰すときに
 * **UTF-16 の符号単位の数を変えない**ので、行も列も元ファイルと一致する。
 * 改行と認識される文字 (LF / CR / U+2028 / U+2029) はそのまま残す。
 *
 * **不合格にするもの (fail-closed)**:
 * - `parse` が失敗した (`.svelte` 全体の構文が壊れている)。
 *   **script の外 (目印・制御構文・スタイル) は候補にしないが、
 *   ファイル全体が `parse` できることは前提**である
 * - script の属性が受理表の外
 * - script の中身の範囲を取れない
 * - **module と実体に同名の最上位束縛がある** (平坦化すると shadowing を再現できない)
 *
 * **検査は `createMirrorPrograms()` が内部で必ず実行する** —
 * `assertNoModuleToInstanceReference()` の呼び出し義務は利用側に無い
 * (呼び忘れを構造的に防ぐため、低層の組み立て関数は輸出しない)。
 *
 * **保証しないもの**: 目印の中の式 (`{…}`)、`{#if}` などの制御構文の中、
 * スタイルの中は候補にしない。
 */
export interface SvelteVirtualUnit {
    readonly source: string;        // 元の `.svelte` のリポジトリ相対パス
    readonly virtualPath: string;   // program に載せる仮想の絶対パス
    readonly text: string;          // 行・列を保った仮想 TS
    readonly moduleRange: readonly [number, number] | null;
    readonly instanceRange: readonly [number, number] | null;
}

export const VIRTUAL_SUFFIX = ".__enum_ts_sync_virtual__.ts";

export const toVirtualUnit = (relativePath: string, source: string): SvelteVirtualUnit => { … };
export const assertNoModuleToInstanceReference = (checker: ts.TypeChecker, file: ts.SourceFile, unit: SvelteVirtualUnit): void => { … };
export const realPathOfVirtual = (virtualPath: string): string | undefined => { … };
```

- 仮想パスの綴りが**版管理下に実在しない**ことを `population.ts` の一覧に対して検査する

### テスト計画

- [ ] **先に赤くする**: 見本 `.svelte` (module と実体の両方を持つ) から仮想単位が返り、
      両方の宣言が読めることを主張 → モジュールが無いので赤
- [ ] **行・列の一致**: LF / CRLF / 孤立 CR / 非 BMP 文字 (サロゲート対) / U+2028 を含む見本
- [ ] **末尾の扱い**: 改行で終わらないソース / 末尾が行注釈のソースでも
      `export {};` が独立した文として効くこと
- [ ] **`export {};` の効き目**: 取り込みも書き出しも無い 2 つの見本コンポーネントに
      同名の宣言を置き、互いに干渉しないこと。片方でしか宣言していない名前を
      もう片方が参照しても**解決しない**こと
- [ ] **実体 → module の参照**: 実体側の型別名が module 側の型別名を参照でき、値集合が読めること
- [ ] **module → 実体の参照は不合格** (検査 B の負例。テスト内の文字列で与える)。
      **実体側の取り込みを module 側が参照する形**も不合格になること (別名の宣言位置で捕まえる)
- [ ] **同名の最上位束縛は不合格** (検査 A の負例。同上)。
      束縛の種類ごとに負例を置く — **取り込み / `enum` / `namespace` / 分割代入**を含む
- [ ] `createMirrorPrograms()` が**検査 B を必ず走らせる**こと
      (module → 実体の参照を持つ見本を含む木を根にすると、program の作成そのものが失敗する)
- [ ] 検査を飛ばした `MirrorProgram` を外から作れないこと
      (低層の組み立て関数が輸出されていない / 見本用は別の型である)
- [ ] 属性の受理表の各行 (受理 6 / 不合格 4) をそれぞれ固定する
- [ ] **故障注入 4**: `export {};` を足さない版 / 文脈ごとに割る版 /
      検査 A を外した版 / **`createMirrorPrograms()` から検査 B の呼び出しを外した版**で、
      それぞれ対応するテストが赤くなる
- [ ] 仮想パスの綴りが実在ファイルと衝突したら例外になること

## S3: program をパッケージごとに作る

### 変更箇所

- `tests/js/support/enum-ts-sync/program.ts` (全面的な作り直し。`buildProgram` は再利用)

### 決めたこと (レビュー Round 1 の Critical に対する結論)

**`packages/cli` をルートの設定 (bundler / ESNext) で読まない**。読むと NodeNext 前提の
取り込みが解決できず、型が `any` に落ちた宣言が「文字列リテラル型ではない = 非候補」として
**静かに消える**。i5 が言う「本番と同じ型世界」は、道具パッケージにとっては
**そのパッケージ自身の tsconfig** である。

したがって **program を複数本持つ**:

| program | 起点 |
|---|---|
| `<root>` | ルート `tsconfig.json` の全ファイル ∪ **どのパッケージにも属さない**版管理下の `*.ts` ∪ 仮想 `.svelte` |
| `packages/<name>` (tsconfig を持つものだけ) | そのパッケージの `tsconfig.json` の全ファイル ∪ そのパッケージ配下の版管理下の `*.ts` ∪ そのパッケージ配下の仮想 `.svelte` |

**所有者の判定は `.ts` と `.svelte` で同じ規則を使う** (現時点で `packages/` の下に
`.svelte` は無いが、足されたときにルートの設定で読まれてしまうのを防ぐ)。

- **母集団の全件が「所有者」をちょうど 1 つ持つ**ことを検査する。3 つの層を区別する:
  1. **所有者への割当**はちょうど 1 件 (母集団の各ファイルに対して過不足の両方を見る)
  2. **起点 (`rootNames`) としての所属**もちょうど 1 件
  3. **推移的な取り込みで別の program にも現れること**は**許す** (依存の共有で普通に起きる)
- **候補走査は「所有者の program 上の `SourceFile`」だけを使う**。
  `program.getSourceFiles()` 全体を母集団の一致根拠にしない
  (依存ライブラリ・推移的な取り込み・JSON が載るため)
- 出力はしないので、起点を `rootDir` の外へ足せるよう
  `rootDir` / `outDir` / `declaration` / `declarationMap` / `composite` / `sourceMap` を
  落として組む (`noEmit: true`)
- 実測: program は 2 本、構築は合わせて約 4.5 秒 (`beforeAll` の 300 秒枠に十分収まる)

### 変更後の型

```ts
export interface MirrorProgram {
    readonly program: ts.Program;
    readonly checker: ts.TypeChecker;
    /** 仮想パス → 元の `.svelte` の相対パス。 */
    readonly virtualPaths: ReadonlyMap<string, string>;
}

export interface MirrorPrograms {
    /** 所有者 (`<root>` またはパッケージのディレクトリ) → program。 */
    readonly byOwner: ReadonlyMap<string, MirrorProgram>;
    /** 母集団の相対パス → それを載せている program。 */
    programOf(relativePath: string): MirrorProgram;
    /** 相対パス → その program 上の SourceFile (`.svelte` は仮想単位)。 */
    sourceOf(relativePath: string): ts.SourceFile;
}

/** 逆走査と前向きの検査が共通で使う。目録のファイルも所有者の program へ載る。 */
export const createMirrorPrograms = (): MirrorPrograms => { … };
```

- **`createMirrorProgram(tsFiles)` は廃止する** (後方互換の並走を残さない)。
  呼び出し側 (`enum-ts-sync.test.ts` / `enum-ts-sync-discovery.test.ts`) を
  `createMirrorPrograms()` へ揃える。前向きの gate 側の呼び出しが変わるので
  **S10 と S12 が発火する** (どのみち S6 の診断文の変更で発火する)
- `createFixtureProgram(absoluteFiles, virtualUnits?)` は**残す** (見本専用)。
  仮想単位を明示で渡せるよう引数を足す
- 仮想の対応表の鍵は host の正規化規則 (`getCanonicalFileName`) を通した綴りで持つ。
  照合も**両側を `getCanonicalFileName()` に通してから**比べる
  (大文字小文字を区別しない環境で `SourceFile.fileName` の生の綴りと鍵が
  文字列として一致するとは限らない)

### 波及変更

- TypeScript 型定義: `MirrorProgram` に `virtualPaths` / 新設 `MirrorPrograms`
- 呼び出し側: `enum-ts-sync.test.ts` / `enum-ts-sync-discovery.test.ts` /
  `enum-ts-sync-extractor.test.ts` / `enum-ts-sync-discovery-extractor.test.ts`
- テストファイル: 下記

### テスト計画

- [ ] **先に赤くする**: `createMirrorPrograms().programOf("packages/cli/src/api/schemas.ts")` が
      `packages/cli` の program を返すことを主張 → 現状は存在しないので赤
- [ ] **母集団の全件がちょうど 1 本に載る**: 母集団の相対パス集合と、
      各 program の対象集合の**直和**が完全一致すること (過不足の両方を出す)
- [ ] `getRootFileNames()` が期待する起点集合を含むこと。
      **`getSourceFiles()` 全体を母集団の一致根拠にしない** (依存ライブラリ・推移的な
      取り込み・JSON が載るため)
- [ ] 仮想 `.svelte` の `getCanonicalFileName(SourceFile.fileName)` が `virtualPaths` の鍵と
      **完全一致**すること (正規化の食い違いを固定する)
- [ ] `packages/` の下に `.svelte` を置いた見本の木で、その仮想単位が
      **そのパッケージの program** に載ること
- [ ] `packages/cli` の program が NodeNext の取り込み (`./schemas.js`) を解決できること
      (ルート設定で読むと解決できない見本と対にする)
- [ ] **故障注入 3**: 母集団の列挙を空に差し替えると「母集団が 0 件」で赤くなる
- [ ] **故障注入 3'**: `packages/cli` をルートの program へ混ぜる実装に差し替えると、
      NodeNext の取り込みを経由する型別名が解析不能になって赤くなる

### リスク

- パッケージが増えたとき、tsconfig を持たないパッケージのファイルは
  **どの program にも載らない** → 母集団の直和検査が赤くなる。
  これは fail-closed であり、そのとき「そのパッケージをどう扱うか」を判断させる形にする

---

## S3b: 値の構文抽出を 1 本に切り出す

### 変更箇所

- 新規: `tests/js/support/enum-ts-sync/ts-literal-values.ts`

### なぜ要るか (レビュー Round 2 の Critical)

前向きの検査で `const` の配列を受理するとき、**型検査器の配列型から要素を復元してはいけない**。
`const X = ["a", "b"];` は通常 `string[]` に広げられ、リテラル型が残らない
(`as const` を付ければ読み取り専用のタプルになるが、素の配列も受理する設計である)。
したがって**配列の値は構文から読む**。

同じ読み方を逆走査 (S4) と前向きの検査 (S7) が使うので、
**下位のモジュールへ 1 本だけ置いて共有する** (正典 i4「抽出器を 2 本持たない」)。

### 変更後コード（骨子）

```ts
/**
 * 値集合の読み取りの最下層。**逆走査と前向きの検査が共有する唯一の抽出器**である。
 *
 * - `unwrapInitializer` … 丸括弧 / `as` / `satisfies` の包みを剥がし、
 *   値の構文と**明示の型ノード**を別々に返す
 * - `readConstArrayLiteralValues` … `const` 束縛の配列リテラルから値を**構文で**読む
 *   (型検査器の配列型は使わない。素の配列は `string[]` に広げられるため)
 * - `readResolvedStringLiteralUnion` … 型を**型検査器で**解決し、
 *   文字列リテラル型だけの合併なら値集合を返す
 * - `readObjectLiteralKeys` … オブジェクトリテラルのキーを読む
 *   (文字列リテラル / 識別子 / 型検査器が文字列リテラルへ解決する計算キー)
 * - `readSwitchCaseValues` … `default` を除く `case` の式の値を読む
 *
 * どれも**「1 つでも読めない要素があれば読めなかったことにする」**。
 * 「読めない」には 2 種類あり、**形ごとに境界が違う** (下の表)。
 */
export type LiteralValuesResult =
    | { readonly kind: "values"; readonly values: ReadonlySet<string> }
    | { readonly kind: "not-a-catalogue" }          // 正常に非候補
    | { readonly kind: "indeterminate"; readonly reason: string }; // 候補かどうか決められない
```

### 形ごとの三値の境界 (レビュー Round 3 の Warning)

| 形 | 受理に型解決が要るか | `not-a-catalogue` になるもの | `indeterminate` になるもの |
|---|---|---|---|
| `const-array` | **要らない** (構文だけ) | `const` でない / 空配列 / 要素に**構文上の文字列リテラル以外**が 1 つでもある (識別子・呼び出し式・展開) | **無い** (型を見ないので保留は起きない) |
| `object-keys` | 計算キーだけ要る | プロパティに通常の代入でないものがある / キーが計算キーで**文字列リテラル型以外へ正常に解決**した / 空 | 計算キーの型が `any` / `unknown` (構文がその綴りでないとき) |
| `literal-union` | **要る** | 解決した型に文字列リテラル型でない構成要素がある / `EnumLiteral` を含む | 解決した型が `any` / `unknown` (構文がその綴りでないとき) |
| `switch-cases` | **要る** | `case` の式が文字列リテラル型以外へ正常に解決 / `case` が 0 件 | `case` の式の型が `any` / `unknown` (構文がその綴りでないとき) |

**定数の配列は構文だけで判定する**ので、識別子や呼び出し式が混ざったら
型解決の成否によらず `not-a-catalogue` である (保留にしない)。

### テスト計画

- [ ] **先に赤くする**: `const X = ["a", "b"];` (素の配列) から値集合が読めることを主張
      → モジュールが無いので赤
- [ ] `as const` / `satisfies readonly string[]` / 丸括弧 / それらの入れ子でも同じ値になること
- [ ] **型検査器の配列型に依存していないこと**: 素の配列で
      `checker.getTypeAtLocation(name)` が `string[]` になっても値が読めること
- [ ] `let` の配列 / 非リテラルが混ざる配列 / 空配列は `not-a-catalogue`
- [ ] **5 つの関数それぞれに、該当する三値の分岐を直接置く**
      (S4 経由の試験だけにしない。どの分岐が壊れたか分かるようにする)
- [ ] `readConstArrayLiteralValues` に `indeterminate` の分岐が**無い**こと
      (識別子が混ざったら `not-a-catalogue`)
- [ ] `readObjectLiteralKeys` / `readResolvedStringLiteralUnion` / `readSwitchCaseValues` は
      `any` / `unknown` で `indeterminate` を返し、構文が `any` の綴りなら `not-a-catalogue`

---

## S4: 候補走査を 4 種へ + 派生の証人つき除外 + 判定保留の受け皿

### 変更箇所

- `tests/js/support/enum-ts-sync/ts-candidates.ts` (全面書き換え)

### 変更後の型

```ts
export type TsCandidateShape = "literal-union" | "const-array" | "object-keys" | "switch-cases";

/**
 * 候補の**同一性**。`file + name` では足りない (レビュー Round 3 の Critical) —
 * 入れ子の宣言まで拾うので、同じファイルの別のスコープに同名の宣言が合法的に共存する。
 *
 * ```ts
 * function a() { type Status = "a"; }
 * function b() { type Status = "b"; }
 * ```
 *
 * `file + name` だと片方の申告がもう片方まで免除し、
 * 最上位の登録済み宣言と同名の入れ子候補が逆走査から消え、
 * 判定保留の申告 1 行が複数の宣言を免除してしまう。
 *
 * **同一性に `occurrence` を入れる**。`occurrence` は
 * 同じ `(file, shape, name)` を持つ**宣言の場所**を**ソース上の位置の順**に並べた
 * 0 始まりの番号である。
 * 行番号を同一性に入れないのは、無関係な行移動で申告が一斉に stale になるのを避けるため。
 * **行はメッセージにだけ使う**。
 *
 * **採番は三値の分類より前に、構文上の宣言の場所の全体に対して行う**
 * (レビュー Round 4 の Critical)。候補だけを採番すると、同名で片方が判定保留・
 * 片方が候補のときに**どちらも `occurrence: 0`** になり、
 * 非候補を採番から外すと**分類が変わっただけで後続の番号が動く**。
 *
 * 同名の宣言が 1 つしか無い通常の場合は `occurrence` が 0 になるので、申告の見た目は素直である。
 * 同名の宣言を**前に**足すと後続の `occurrence` がずれて申告が stale になり赤くなる
 * (人が見直す合図であり、fail-closed の向きである)。
 */
export interface TsCandidateLocator {
    /** リポジトリルートからの相対パス (`.svelte` は仮想ではなく元のパス)。 */
    readonly file: string;
    readonly shape: TsCandidateShape;
    /** 宣言の名前。分岐のラベルは `switch:<判定対象>`。 */
    readonly name: string;
    /** 同じ (file, shape, name) の中の出現順 (0 始まり)。 */
    readonly occurrence: number;
}

export interface TsUnionCandidate {
    readonly locator: TsCandidateLocator;
    /** 元ファイル上の行 (1 始まり)。**同一性には使わない** (メッセージ用)。 */
    readonly line: number;
    /** 最上位の宣言か (前向きの目録が指せるのは最上位だけ)。 */
    readonly topLevel: boolean;
    readonly values: ReadonlySet<string>;
    /** 分岐のラベルで判定対象の名前を決められたか。名前対応の判定に使う。 */
    readonly nameResolved: boolean;
}

/** 候補かどうかを決められなかった宣言 (判定保留)。**同一性は候補と同じ locator**。 */
export interface IndeterminateTsDeclaration {
    readonly locator: TsCandidateLocator;
    readonly line: number;
    readonly reason: string;
}

export interface TsCandidateScan {
    readonly candidates: readonly TsUnionCandidate[];
    readonly indeterminate: readonly IndeterminateTsDeclaration[];
}
```

### 受理する 4 形（正典 i9）

| 形 | 受理条件 | 値集合 |
|---|---|---|
| `literal-union` | 型別名の宣言 (**入れ子も含む**)。解決した型が文字列リテラル型だけ | リテラルの値 |
| `const-array` | **`const` 束縛**の変数宣言で、包み (`as` / `satisfies` / 丸括弧) を剥がした初期化子が配列リテラル。要素が**すべて**文字列リテラル、1 件以上 | 要素の値 |
| `object-keys` | 変数宣言で、包みを剥がした初期化子がオブジェクトリテラル。プロパティが**すべて**通常の代入で、キーが文字列リテラル / 識別子 / 型検査器が文字列リテラルへ解決する計算キー。1 件以上 | キーの綴り |
| `switch-cases` | `switch` 文で、`default` を除く**すべての** `case` の式が文字列リテラル型へ解決する。1 件以上 | `case` の値 |

**読み取りは S3b の共有抽出器 (`ts-literal-values.ts`) を使う**。包みの剥がし方
(`ParenthesizedExpression` / `AsExpression` / `SatisfiesExpression`)、
**明示の型の取り方** (変数宣言の型注釈 `node.type` を優先し、無ければ
`SatisfiesExpression.type` を `checker.getTypeFromTypeNode()` で解決する。
`getTypeAtLocation(initializer)` は `satisfies` の型ではなく値の型を返すので使わない)、
**配列の値は構文から読む** (型検査器の配列型は広げられるので使わない) —
これらはすべて共有抽出器の側の契約である。

`const` 束縛の判定は `(declaration.parent.flags & ts.NodeFlags.Const) !== 0`。
**`object-keys` には `const` を要求しない** (正典は「オブジェクト (対応表) のキー」としか
言わず、`let` の対応表も写しになり得る)。`const-array` にだけ要求するのは正典の
「**定数の**配列」という言い方に合わせるためで、この非対称を docblock に書く。

**`ts.TypeFlags.EnumLiteral` の拒否は 4 形すべてに適用する** (本リポジトリに TypeScript の
`enum` は 1 件も無く、文字列リテラル型と同じ契約ではない)。形ごとの期待:

| 形 | `enum` の要素を値に使ったとき |
|---|---|
| `literal-union` | 受理しない (非候補) |
| `const-array` | 要素が `enum` の要素なら**構文としては識別子**なので `not-a-catalogue` |
| `object-keys` | 計算キーが `enum` の要素へ解決したら受理しない |
| `switch-cases` | `case` の式が `enum` の要素へ解決したら受理しない |

### 三値にする (共通規約 (b))

「候補かどうかを**決められない**」を**非候補と混ぜない**。

**呼び名を `indeterminate` (判定保留) にする** (レビュー Round 2 の Warning)。
`any` / `unknown` は「記号を解決できなかった」場合だけでなく
「明示的に `any` へ正しく解決した」場合にも現れる (`type Dynamic = any; type X = Dynamic;`)。
両者を機械で見分けるには TypeScript の内部表現に踏み込む必要があるので、
**踏み込まずに契約の側を広げる** — 「解決できなかったものに加えて、
候補かどうかを確定できない `any` / `unknown` も含む」とする。

```ts
const isIndeterminateType = (type: ts.Type, node: ts.Node | undefined): boolean =>
    (type.flags & (ts.TypeFlags.Any | ts.TypeFlags.Unknown)) !== 0
    && node !== undefined
    && node.kind !== ts.SyntaxKind.AnyKeyword
    && node.kind !== ts.SyntaxKind.UnknownKeyword;
```

- 関数名は **`isIndeterminateType`** にする (契約の呼び名と揃える)
- 型別名の解決結果 / 計算キーの型 / `case` の式の型 / 明示型の解決結果に対して適用する
- 当たったら `indeterminate` へ積む (**候補にも非候補にもしない**)
- gate 側は `indeterminate` の**全件が申告されている**ことを既定拒否で固定する
  (PHP 側の `KNOWN_UNRESOLVABLE_PHP_ENUMS` と同じ形。i3 の区分 3 の TS 版)
- **`type X = any` のように構文が `any` / `unknown` そのものなら正常な非候補**である
  (上の式が `node.kind` を見ているのはこのため)

実測 (2026-08-24) の `indeterminate` は **3 件**で、すべて既存の見本である
(`fixtures/t22-circular.ts` の `X` / `Y`、`fixtures/t23-unresolved-import.ts` の `X`)。
どれも**わざと解決できない形にした見本**なので、申告に理由付きで載せる。

### 分岐のラベルの名前 (fail-closed の当て所を変える)

**locator 用の構文名と、規則 2 用の解決名を分ける** (レビュー Round 5 の Warning)。
locator の `name` は**必須**で、採番は三値の分類より前に `(file, shape, name)` 単位で行うので、
「名前を決められない」ときでも locator 用の名前は必ず要る。

```ts
interface SwitchSubject {
    /** locator 専用。構文が正常なら必ず得られる。 */
    readonly siteName: string;          // `switch:${式の字面 (空白を 1 つに畳む)}`
    /** 規則 2 の名前対応に使える場合だけ値を持つ。 */
    readonly correspondenceName: string | null;
}

const switchSubject = (checker, expr, source): SwitchSubject => {
    const siteName = `switch:${expr.getText(source).replace(/\s+/g, " ").trim()}`;
    if (siteName === "switch:") throw new EnumTsSyncError(where, "分岐の判定対象の字面が空です");

    const type = checker.getTypeAtLocation(expr);
    const alias = type.aliasSymbol?.name
        ?? (type.isUnion() ? type.types.map((t) => t.aliasSymbol?.name).find(isDefined) : undefined);
    if (alias !== undefined) return { siteName, correspondenceName: alias };

    // 名前対応に使ってよい式の形: 識別子 / `this` / それらのプロパティ参照の連なり
    if (isNameableExpression(expr)) return { siteName, correspondenceName: expr.getText(source) };

    return { siteName, correspondenceName: null };
};
```

- **locator の `name` には常に `siteName` を使う** (呼び出し式の分岐でも採番できる)
- `nameResolved` は `correspondenceName !== null`
- 規則 2a / 2b へ渡すのは `correspondenceName` **だけ**である
  (任意の式の字面を名前対応に使わない)
- 式の字面が空になる形は**構文の診断か解析の失敗**として落とす
- 名前を決められなかった候補は `nameResolved: false` で**候補として残す**
- **規則 1 (完全一致) は名前を使わないのでそのまま効く**
- **規則 2 は判定できない**。そこで `reverse-sweep` 側で
  「`nameResolved` が偽 かつ 値集合が列挙と 1 値でも交差する かつ 完全一致ではない」組を
  **判定不能 (undecidable) として gate を赤くする**。交差が 0 なら規則 2 の対象になり得ないので
  黙って通す。これが「未解決を解決済みと同じ値へ混ぜない」の当て所である
- **変数宣言の場所の `name` は識別子の束縛だけを受理する** (分割代入には locator を作らない。
  候補の 4 形はどれも名前付きの 1 つの宣言を前提にしている)
- 実測: 現物ツリーで `nameResolved: false` になるのは
  `switch (errorName(error))` (呼び出し式) などで、
  **判定不能に落ちる組は 0 件**である (交差する列挙が無い)。
  見本で交差する形を作り、**故障注入 8** が到達可能になる

### 派生の除外 (3 集合一致 + 対応表以外の証人)

`object-keys` 形だけに適用する。**次をすべて満たすときだけ**外す。

1. 明示の型がある (型注釈 または `satisfies`)。型が解決できないなら外さない
2. その型に**文字列の添字シグネチャが無い**
   (`checker.getIndexInfoOfType(type, ts.IndexKind.String) === undefined`)
3. その型の**プロパティが 1 件以上あり、すべて必須**
   (`(symbol.flags & ts.SymbolFlags.Optional) === 0`)
4. **書かれたキー == 明示型の必須プロパティ** (集合として完全一致)。
   意味の診断を読まない以上、余剰キー・欠落キーを前提にしない
5. **証人がある** — その値集合と**同一の値集合**を持つ候補が、
   **`object-keys` 以外の形**の候補の中に 1 件以上ある

証人の資格を「派生除外の対象になり得ない形」に限るのは**循環の遮断**である。
任意の候補を証人にすると、同じキー集合を持つ対応表 A と B が互いを証人にして両方消える。
この形なら判定は**非派生の候補を種にした単調な到達判定**になり、
自己証人・相互証人・3 件の循環が構造的に起こらない。

### 採番と分類の順序 (レビュー Round 4 の Critical)

**採番 → 分類**の順で行い、候補・判定保留・非候補が**同じ採番空間**を共有する。

1. **宣言の場所を数え上げる** (`enumerateDeclarationSites`)。三値の判定はまだしない
   - `literal-union` … **すべての型別名の宣言**
   - `const-array` / `object-keys` … 包みを剥がした初期化子がその形になる
     **すべての変数宣言**
   - `switch-cases` … **すべての `switch` 文**
2. `(file, shape, name)` ごとに**ソース位置の順**で `occurrence` を振る
   (`locatorOf(node, scanIndex)`。**この採番器は 1 本だけ持ち、
   逆走査 (S4) と前向きの解決 (S7) が共有する**)
3. 各場所を `candidate` / `not-a-catalogue` / `indeterminate` へ分類する

**実装の順序 (派生除外の 2 パス)**: 第 1 パスで `object-keys` 以外の 3 形と、
`object-keys` のうち条件 1〜4 を満たすものを「保留」に分ける。
第 2 パスで保留のうち証人があるものを捨て、無いものを候補へ戻す。

実測: 保留 86 件のうち証人つきで外れたのは **40 件**、候補へ戻したのが 46 件。

### 構文の診断 (fail-closed)

母集団のファイル (仮想 `.svelte` を含む) について
`program.getSyntacticDiagnostics(source)` が 1 件でもあれば例外にする (現行と同じ)。

### PHPStan適合チェック

- [x] PHP の変更なし
- [x] 戻り値の型が明示されている / `readonly` と `ReadonlySet` を維持

### テスト計画

- [ ] **先に赤くする**: 見本に定数配列・対応表・分岐を足し、4 形すべてが拾えることを主張
- [ ] 各形の正例と負例 (非リテラルが混ざる / 数値 / TS の `enum` / 0 件 /
      `let` の配列は `const-array` にならない)
- [ ] **包みの負例**: `as const` / `satisfies Record<…>` / 丸括弧 / それらの入れ子を
      剥がして正しく読めること。`satisfies` の型を `getTypeFromTypeNode` で取っていること
      (値の型を使う実装に差し替えると赤くなる見本を置く)
- [ ] 入れ子の型別名 (関数の中) が拾えること
- [ ] `.svelte` の中の 4 形が拾えること
- [ ] **判定保留の三値**: 解決できない型別名が `indeterminate` に入り、
      候補にも非候補にもならないこと。`type X = any` / `type X = unknown` は
      **正常な非候補**であること。**`type Dynamic = any; type X = Dynamic;` は
      `indeterminate` に入る** (別名越しの明示 `any`) こと。
      **`any` 型の変数を計算キーにした対応表**も `indeterminate` に入ること
- [ ] 形ごとの `enum` の要素の扱い (上表の 4 行) をそれぞれ固定する
- [ ] **locator の一意性**: 同じファイルの別のスコープに同名の宣言を 2 件置いた見本で、
      2 件が**別の locator を持つ** (`occurrence` が 0 と 1 になる) こと
- [ ] **採番が三値をまたぐ**こと (Round 4 の Critical):
      - 同名で **判定保留が先・候補が後** → 候補の `occurrence` は 1
      - 同名で **非候補が先・候補が後** → 候補の `occurrence` は 1
      - 片方だけを申告しても**もう片方へ効かない**
- [ ] 最上位の宣言と入れ子の同名宣言が共存する見本で、`topLevel` が正しく付くこと
- [ ] 派生の除外: `Record<Alias, string>` は外れ、`Record<string, string>` は残る
- [ ] 派生の負例セット: **型別名越しの `Record` / `Partial<Record<…>>` / union /
      intersection / `keyof` / 取り込んだ型 / `satisfies`** をそれぞれ見本に置く。
      とくに「書かれたキー ≠ 必須プロパティ」の見本 (欠落・余剰) が**外れない**こと
- [ ] **証人の負例 3 種**: 自己証人 / 2 件の相互証人 / 3 件の循環証人 —
      いずれも「外れずに候補として残る」ことを固定
- [ ] 分岐の名前: 型別名が取れる形 / 識別子とプロパティ参照の形 / 呼び出し式の形で
      `siteName` と `correspondenceName` (と `nameResolved`) が期待どおりになること
- [ ] **呼び出し式の分岐が同じファイルに 2 件**あるとき、`occurrence` が 0 と 1 になり、
      **一方だけが判定不能になる** (交差する列挙を持つ側だけ) こと
- [ ] **故障注入 2 / 7 / 8** (S9 の表)

### リスク

- `object-keys` の候補が 172 件と多い。判定式が名前と値を見るので実際に鳴るのは 2 件だが、
  PHP の列挙が増えると鳴る組が増える。過剰検出の向きであり申告 1 行で吸収できる

---

## S5: 規則 2 の論理和

### 変更箇所

- `tests/js/support/enum-ts-sync/reverse-sweep.ts` (L44-99)

### 変更後の型

```ts
/** 適用した規則。申告の同一性に含める (規則が変わったら申告は stale になる)。 */
export type ReverseSweepRule = "1" | "2a" | "2b";

export interface UnregisteredMirrorCandidate {
    readonly rule: ReverseSweepRule;
    readonly php: ResolvedPhpEnum;
    readonly candidate: TsUnionCandidate;
    /** 鳴った理由 (どの規則・どの語・どの値の交差で鳴ったか)。 */
    readonly reason: string;
    readonly onlyInPhp: readonly string[];
    readonly onlyInTs: readonly string[];
}

/** 名前を決められないので規則 2 を判定できなかった組 (gate を赤くする)。 */
export interface UndecidableMirrorPair {
    readonly php: ResolvedPhpEnum;
    readonly candidate: TsUnionCandidate;
    readonly intersectionSize: number;
}

export interface ReverseSweepResult {
    readonly found: readonly UnregisteredMirrorCandidate[];
    readonly undecidable: readonly UndecidableMirrorPair[];
}
```

### 判定の順序 (排他)

1. 値集合が完全一致 → `"1"`
2. 交差が 0 なら何もしない
3. `nameResolved` が偽 → **undecidable** (gate が赤くなる)
4. **2a の名前対応**が成立 → `"2a"`
5. **2b の名前対応**が成立し **2b の交差条件**を満たす → `"2b"`
6. どれでもなければ鳴らさない

### 2a: 厳密な名前対応 + 1 値以上の交差 (現行を維持)

小文字化して比較 (**英数字以外は除去しない**)。一致 / `+s` / `+es` / `+values`。

### 2b: 語に分けた名前対応 + 両側から見て半分以上の交差 (新設)

**候補名の前処理**: 分岐のラベルの `switch:` は**両規則の共通の前処理で外す**。
区切りの集合にも `:` を含める。

**区切りの宣言** (AGENTS.md §共通規約 (e)):

- 語に割る文字は `_` `-` `.` `:` `$` と空白類
- **大文字の境界**でも割る (「小文字または数字 → 大文字」と「大文字の連なり → 大文字 + 小文字」)
- **数字の境界**でも割る (英字 ↔ 数字)
- 割った後、空の要素を捨て、すべて小文字化する

**正規化 (単数化) は「1 つの正規形へ畳む」形を採らない** (レビュー Round 2 の Warning)。
接尾辞だけで畳むと `cases → cas` / `responses → respons` / `uses → us` のように
**誤った語幹を正規形にしてしまう**。代わりに**語ごとに候補形の集合**を作り、
**集合が交われば同じ語とみなす**。

```
forms(w) = { w }
         ∪ { w の末尾 "ies" を "y" にしたもの }            (長さ > 3 のとき)
         ∪ { w の末尾 "es" を落としたもの }                 (s/x/z/ch/sh + "es" のとき)
         ∪ { w の末尾 "s" を落としたもの }                  (長さ > 1 かつ "ss" で終わらないとき)
```

語 a と語 b が**対応する**とは `forms(a) ∩ forms(b) ≠ ∅` のことである。

| 語 | `forms()` | 相手 | `forms()` | 対応 |
|---|---|---|---|---|
| `status` | {status, statu} | `statuses` | {statuses, statuse, status} | する |
| `case` | {case} | `cases` | {cases, case, cas} | する |
| `class` | {class} (`ss` で終わるので落とさない) | `classes` | {classes, classe, class} | する |
| `policy` | {policy} | `policies` | {policies, policie, policy} | する |
| `value` | {value} | `values` | {values, value} | する |
| `kind` | {kind} | `kinds` | {kinds, kind} | する |
| `use` | {use} | `uses` | {uses, use, us} | する |
| `response` | {response} | `responses` | {responses, response, respons} | する |
| `status` | {status, statu} | `state` | {state} | **しない** |
| `code` | {code} | `codec` | {codec} | **しない** |

`cas` / `us` / `respons` は**複数形の側の候補形**として現れるだけで、
**正規形として採用しない** (単数形の側の集合には入らない)。

**過剰検出の向きへ倒している** — 例えば `us` という語があれば `uses` と対応してしまう。
これは「拾いすぎる方向へ倒すのは可」の側であり、鳴った先は申告で逃がせる。
**これ以上の語形変化 (不規則変化・語幹の交替) は扱わない** (docblock に書く)。

期待値をテストで固定する (対応する / しない の両方向):
`status`⇔`statuses` / `class`⇔`classes` / `policy`⇔`policies` / `value`⇔`values` /
`kind`⇔`kinds` / `case`⇔`cases` / `response`⇔`responses` / `use`⇔`uses` が対応し、
`status`⇔`state` / `code`⇔`codec` が対応しないこと。

**語袋**: 候補側 = 宣言名の語 ∪ ファイル名 (拡張子を除いた basename) の語。
PHP 側 = 列挙名の語。

**主要語**: 語列の**末尾の語**。候補側の主要語は**宣言名**の語列の末尾を使う
(ファイル名の語は主要語に使わない)。**宣言名から語が 1 つも取れなければ例外**にする
(静かに名前不一致へ混ぜない)。

**名前対応 (2b)**: 候補の主要語と列挙の主要語が**対応する** (候補形の集合が交わる) かつ、
列挙の語と候補の語袋の**最大マッチング**の大きさが `min(2, |列挙の語列|)` 以上。

**一致数は最大マッチングで数える** (レビュー Round 3 の Warning)。
「列挙の各語について語袋のどれかと対応するか」を単純に数えると、
**候補側の 1 語が列挙側の複数の語に使い回される**。
`forms(a) ∩ forms(b) ≠ ∅` は推移律を持たないので同値類にも畳めない。
語数は多くて 5 程度なので、素直な増補路の探索 (二部グラフの最大マッチング) で足りる。

**交差条件 (2b)**: `|A ∩ B| >= ceil(|A| / 2)` かつ `|A ∩ B| >= ceil(|B| / 2)`。
どちらかが空なら鳴らさない。

### 負例の設計 (共通規約 (e) の 3 形)

**トークンの完全一致で判定していることを突く形にする** (素の部分文字列一致なら
一致してしまうが、トークン一致では一致しない形)。

| 形 | 見本 | なぜ不成立か |
|---|---|---|
| 接頭辞つき | `PrejobStatus` (対 `JobStatus`) | 語は `[prejob, status]`。`job` と対応する語が無い → 一致数 1 < 2 |
| 打ち消しつき | `JobNonstatus` (対 `JobStatus`) | 語は `[job, nonstatus]`。主要語が `nonstatus` ≠ `status` |
| 接尾辞つき | `JobStatusKind` (対 `JobStatus`) | 語は `[job, status, kind]`。主要語が `kind` ≠ `status` |

**`DraftJobStatus` / `NonJobStatus` は負例にしない** — これらは語として `job` と `status` を
持ち主要語も一致するので、本判定式では**成立するのが正しい**
(レビュー Round 1 の指摘。実際、`DashboardJobStatus` が同じ理由で成立している)。

### 診断文字列

- 規則 2a: `厳密名対応 (apierrorcode = apierrorcode) / 交差 6 値`
- 規則 2b: `語対応 2/2 語 主要語=status / 交差 2 値`

### 最終形での再測定 (レビュー Round 3 の Warning)

`forms()` + 最大マッチングへ変えたあとに現物ツリーで測り直した
(`probe/measurements.md`)。**鳴った組は 10 件のままで、規則別の内訳も変わらない**
(規則 1 = 6 / 2a = 1 / 2b = 3)。語の対応の期待値 10 組もすべて期待どおりだった。

### テスト計画

- [ ] **先に赤くする**: 2b だけが拾う組 (2a では鳴らない) を主張 → 現行は鳴らないので赤
- [ ] 既存の E1〜E11 を**すべて残し**、`rule` の値を `"1"` / `"2a"` へ直す
- [ ] `forms()` そのものの期待値を語ごとに固定する (上表に出る語すべて)
- [ ] 語の対応の期待値 (**対応する 8 組 / 対応しない 2 組** = 上表の 10 行) を固定する
- [ ] **最大マッチング**: 列挙名に同じ語が 2 回出る形で、候補側の 1 語が
      2 回数えられない (一致数が 1 になる) こと
- [ ] **増補路が要る入力**を直接与える (貪欲では最大値に届かない形。
      隣接が `L1 → {R1, R2}` / `L2 → {R1}` のとき、`L1→R1` を先に選んでも
      付け替えて大きさ 2 になること)
- [ ] 2b の正例: 主要語一致 + 2 語一致 + 両側半分以上
- [ ] 2b の負例 3 形 (上表) と、主要語一致でも交差が片側半分未満 / 交差 0 / 空集合
- [ ] 2a と 2b の両方に該当し得る組で **2a が勝つ**こと
- [ ] `nameResolved` が偽で交差ありの組が `undecidable` に入ること (交差 0 なら入らない)
- [ ] 宣言名から語が取れない候補で例外になること
- [ ] **故障注入 5**: 論理和から 2b / 2a のどちらかを落とすと、その式専用の正例が消えて赤くなる
- [ ] `onlyInPhp` / `onlyInTs` が双方向の差分になっていること

---

## S6: 目録の受理範囲拡大

### 変更箇所

- `tests/js/support/enum-ts-sync/relation-inventory.ts` (型と `validateRelations`)

### 現行コード

```ts
const jsRoot = path.join(root, "resources", "js");
if (!row.ts.endsWith(".ts")) throw new EnumTsSyncError(where, `ts は .ts で終わること: ${row.ts}`);
if (!isUnder(tsAbs, jsRoot)) throw new EnumTsSyncError(where, `ts は resources/js/ 配下だけ: ${row.ts}`);
```

### 変更後コード（骨子）

```ts
/**
 * 登録できる TS の置き場。
 * - `resources/js/` … 画面側
 * - `packages/*/src/` … 付属のコマンドライン道具 (本 feature の境界は画面側に限らない)
 * `tests/js/` と `packages/*/tests/` は登録の置き場ではない (検査の見本を写しとして登録しない)。
 */
const tsRootsOf = (root: string): readonly string[] => [
    path.join(root, "resources", "js"),
    ...listPackageSrcRoots(root), // packages/*/src のうち実在する通常ディレクトリ。綴り順
];

const TS_EXTENSIONS = [".ts", ".svelte"] as const;

const matchedRoot = tsRootsOf(root).find((r) => isUnder(tsAbs, r));
if (matchedRoot === undefined) {
    throw new EnumTsSyncError(where, `ts は resources/js/ 配下か packages/*/src/ 配下だけです: ${row.ts}`);
}
// symlink の脱出検査は「字面で一致した根」に対して行う (別の根と比べると
// 拒否漏れ・誤拒否のどちらも起きる)。
if (!isUnder(fs.realpathSync(tsAbs), matchedRoot)) { … }
```

- `listPackageSrcRoots()` は綴り順に整列し、**通常ディレクトリだけ**を返す (診断を安定させる)
- 目録の行に **`relation` (`"equal"` / `"subset"`)** を足す (S7 の決めたこと 2)。
  型は**判別された合併**にし、`"subset"` の行だけ `subsetReason` を必須にする。
  `validateRelations()` は **`subsetReason` を trim した長さが 30 文字以上**であることを見る
  (空白だけで長さを稼ぐ形を通さない)
- `.svelte` を受理しても aicue に登録対象は現時点で 0 件である。
  正典 i6 が「`.svelte` の中の写しも登録の対象になる」と定めるため経路を用意し、
  見本で正例・負例を固定する

### 波及変更

- `enum-ts-sync.test.ts` の負の対照の期待文字列が変わる → **S10 と S12 が発火する**

### テスト計画

- [ ] **先に赤くする**: S10 の 2 つの負例 (新しい文面) を**先に**書く → 現行の文面と
      合わないので赤。そのうえで本施策を実装して緑にする (S10 と同じ赤→緑の単位)
- [ ] `packages/cli/src/api/schemas.ts` の登録行が通ること
- [ ] `.svelte` の登録行が通ること (見本の木で)
- [ ] `tests/js/setup.ts` / `packages/cli/tests/…` / `packages/cli/vitest.config.ts` は拒否
- [ ] symlink の負例を根ごとに: `packages/cli/src` の中から外へ抜ける symlink /
      `packages/cli/src` 自体が symlink
- [ ] 既存の負の対照 (絶対パス / 逆斜線 / `..` / 二重登録 / note 空) は**すべて残す**

---

## S7: 前向きの検査を 2 形・2 関係・`.svelte` へ

### 変更箇所

- `tests/js/support/enum-ts-sync/ts-value-sets.ts`
- `tests/js/support/enum-ts-sync/relation-inventory.ts` (`relation` の追加。S6 と同じファイル)

### 決めたこと 1: 受理する形を 2 つにする

前向きの検査 (`readTsUnionValues`) が受理する形を **2 つ**にする。

1. **型別名の宣言** (現行)
2. **`const` 束縛の配列** (`as const` の有無を問わない)

理由: 逆走査が見つけた実ドリフト 2 件はどちらも**定数の配列**であり、
登録できる形が型別名だけだと**直す道が「型別名を足す」しかなくなり、
申告が実質の許可一覧に膨らむ** (i11 が禁じる形)。

**値の読み取りは S3b の共有抽出器を使う** (`ts-literal-values.ts`)。
とくに配列は**構文から読む** — `const X = ["a", "b"];` は型検査器の上では `string[]` に
広げられるので、型から要素を復元してはいけない (レビュー Round 2 の Critical)。
`satisfies` を付けても対象型によって広げられ得るので、**受理の判断は常に配列リテラルの構文**から行う。

**対応表のキーと分岐のラベルは引き続き登録できない**。写しとして扱うなら
型別名か定数の配列へ切り出す — これを失敗メッセージと docblock に書く。

### 決めたこと 2: 目録に「関係」の欄を足す (レビュー Round 2 の Critical への回答)

`CliOAuthScope` (サーバが認識する**全スコープ**) と `DEFAULT_CLI_SCOPES`
(道具が**既定で要求する権限**) は**別の概念**である。今は偶然 4 値が一致するが、
完全一致の写しとして登録すると「サーバにスコープを足したら道具も要求する」方向へ
設計が引っ張られ、最小権限に反する (AGENTS.md 思考原則 4「別物の概念を似ているからで統合しない」)。

**専用の検査ファイルは作らない** — ドメイン固有規約 19 が
「個別の同期テストのファイルを増やさない (増殖を止めるのが本 gate の目的)」と定めているためである。
代わりに**目録の行に `relation` を足す**。

```ts
/** 目録の 1 行の共通部分。 */
interface EnumTsRelationBase {
    readonly php: string;
    readonly ts: string;
    readonly declaration: string;
    readonly note: string;
}

/**
 * PHP の値集合と TS の値集合の関係。**判別された合併**にして、
 * `"subset"` の行にだけ追加の申告 (`subsetReason`) を要求する
 * (`note` は `"equal"` の行にもあるので、`note` 非空だけでは subset 固有の負担にならない)。
 */
export type EnumTsRelationEntry =
    | (EnumTsRelationBase & { readonly relation: "equal" })
    | (EnumTsRelationBase & {
          readonly relation: "subset";
          /** **なぜ値域の写しではないのか** (30 文字以上)。 */
          readonly subsetReason: string;
      });
```

### 名前を役割に合わせる (レビュー Round 3 の Warning)

`subset` の行は「写し (mirror)」ではないので、名前を役割へ合わせる
(思考原則「機能の名前に立ち返れ」)。**同じ変更で機械的に置き換える**:

| 現行 | 変更後 |
|---|---|
| `tests/js/support/enum-ts-sync/mirror-inventory.ts` | `tests/js/support/enum-ts-sync/relation-inventory.ts` |
| `EnumTsMirror` | `EnumTsRelationEntry` |
| `ENUM_TS_MIRRORS` | `ENUM_TS_RELATIONS` |
| `EXPECTED_MIRROR_COUNT` | `EXPECTED_RELATION_COUNT` |
| `validateMirrors()` | `validateRelations()` |
| `registeredPhpPaths()` / `registeredTsKeys()` | `declaredPhpPaths()` / `declaredTsLocators()` |

`AGENTS.md` 19 が `ENUM_TS_MIRRORS` を名指ししているので、S13 で同時に直す。
**旧名を別名として残さない** (後方互換の並走を残さない)。

- 前向きの gate は `relation` に応じて判定を変える
  (`equal` は双方向の差分が空、`subset` は TS 側にだけある値が空)
- **逆走査は `relation` を問わず「登録済み」として扱う** (i7 の「登録済みも突き合わせ母集団から
  外さない」は PHP 側の話であり、TS 側の宣言は登録済みなら候補から外す — 現行と同じ)。
  ただし**外すのは locator が完全一致する候補だけ**である (下の「登録行の locator の解決」)

### 登録行の locator の解決 (レビュー Round 4 の Critical)

目録の行が持つのは `ts + declaration` だけで、locator に要る `shape` と `occurrence` が無い。
**同名の入れ子の宣言が最上位より前にあると、最上位でも `occurrence` は 0 とは限らない**。
そこで**前向きの解決の時点で AST から locator を計算する**。

```ts
export interface ResolvedEnumTsRelation {
    readonly entry: EnumTsRelationEntry;
    readonly tsLocator: TsCandidateLocator;
    readonly phpValues: ReadonlySet<string>;
    readonly tsValues: ReadonlySet<string>;
}
```

処理の順序:

0. **値集合の比較より先に locator を解決する** (値が食い違っていても登録済みの locator の
   母集団は変わらず、前向きの診断と逆走査が同じ解決結果を共有できる)
1. 対象の `SourceFile` の**最上位**から、その名前の**受理できる宣言をちょうど 1 つ**解決する
   (型別名 または `const` の配列。2 つ以上あれば落とす)
2. **S4 と同じ採番器 `locatorOf(node, scanIndex)`** で、その節の `shape` / `name` / `occurrence` を求める
3. `declaredTsLocators()` は**解決済みの関係**から作る
4. 逆走査は **locator が完全一致する候補だけ**を登録済みとして外す

**採番の実装を S4 と S7 で別に持たない** (正典 i4「抽出器を 2 本持たない」と同じ理由)。
- `subset` にすれば「サーバにスコープが増えても道具は赤くならない」ので、
  権限を自動で要求する方向への圧力が消える。逆に**道具がサーバに無い値を要求したら赤くなる**
- **TS 側が空集合でないことを関係の判定の側でも明示的に見る**
  (抽出器が空配列を拒むことに依存しない。将来受理する形が増えても不変条件が残る)
- **`subset` は逃げ道になり得る** (完全一致の写しを `subset` と偽れば緩む)。
  機械では見分けられないので、**`subsetReason` の記述とレビュー**で担保する。
  この限界を docblock に書く

### `.svelte` の解決

```ts
const sourceOf = (programs: MirrorPrograms, tsFile: string): ts.SourceFile => {
    // `.svelte` は仮想単位が 1 本だけある。無ければ「仮想化されていない」で落とす
    // (「型別名が見つからない」と混ぜない)。
    …
};
```

- 「その名前の宣言がちょうど 1 つ」の検査は現行どおり (型別名と `const` の配列を合わせて数える)
- 失敗メッセージの `where` は**元の `.svelte` のパス**を出す (仮想パスを見せない)

### テスト計画

- [ ] **先に赤くする**: `const X = ["a", "b"];` (素の配列) を登録して値集合が読めることを主張
- [ ] **型検査器の配列型に依存していないこと** (S3b と同じ観点をこちらでも固定する)
- [ ] `let` の配列 / 非リテラルが混ざる配列 / 空配列は受理しないこと
- [ ] `relation: "subset"` の行で、TS 側にだけある値があると落ち、
      PHP 側にだけある値があっても**落ちない**こと
- [ ] `relation: "subset"` で TS 側が空集合なら落ちること (関係の判定の側で見ていること)
- [ ] `relation: "subset"` の行に `subsetReason` が無い / 29 文字だと型検査か体裁検査で落ちること
- [ ] `relation: "equal"` の行は現行どおり双方向で落ちること
- [ ] `.svelte` の中の型別名が読めること
- [ ] `.svelte` が仮想化されていないときは「仮想単位が無い」で落ちること
      (「型別名が見つからない」と別のメッセージ)
- [ ] **入れ子が先・最上位が後**の見本 (`function a() { type Status = "x"; } type Status = "y";`) で、
      登録行が**最上位の宣言の locator**へ解決し、**入れ子の候補は逆走査に残る**こと
- [ ] 実体側の型別名が module 側の型別名を参照する見本で値集合が読めること
- [ ] module と実体に同名の宣言を置いた見本は **S2 の検査 A** で構築時に落ちること
- [ ] 既存の受理・拒否 (T01〜T25) は**すべて残す**

## S8: 逆走査 gate の再整備

### 変更箇所

- `tests/js/architecture/enum-ts-sync-discovery.test.ts`

### docblock の書き換え (i15)

現行の次の宣言は事実でなくなるので書き換える:

- 「`resources/js/` 配下の…型別名を全数走査し」
- 「`.svelte` の中の宣言・定数配列・switch の case ラベルは走査しない」
- 「名前対応は『一致 / +s / +es / +values』の厳密な形だけを見る」

新しい**保証しないもの**:

- 版管理外のファイルは見ない。`.js` / `.mjs` / `.cjs` は母集団に入れない
- `.svelte` は script の中だけを見る (目印の中・制御構文の中・スタイルは見ない)。
  ただし**ファイル全体が `parse` できることは前提**である
- 「すべての要素が読める」形だけを候補にする (1 つでも読めない要素があれば候補にしない)
- 派生として外した対応表は、**証人 (対応表以外の候補) がある場合だけ**外れる
- 分岐のラベルと対応表のキーは**登録できない**。写しなら型別名か定数の配列へ切り出す
- パッケージの型は**そのパッケージ自身の tsconfig** で解決する
  (ルートの設定で解決するわけではない)
- 除外根 (`fixtures/candidates-broken`) の中は見ない。
  `fixtures/` の残りは**見る** (見本を書き換えると本番の候補集合も動く)

### 新設する検査

```ts
describe("逆走査の母集団 (版管理下の全数・唯一の除外)", () => {
    it("除外根の件数が pin と一致する", …);
    it("除外根の体裁 (配下・実在・重複無し・理由 30 文字以上) が守られている", …);
    it("除外根の配下は 0 件でなく、全ファイルが実際に本番と同じ入口で落ちる", () => {
        // `.ts` は TS の構文診断、`.svelte` は toVirtualUnit() の失敗で見る
        // (拡張子ごとに本番と同じ入口を使う)。
        // ここが「除外根へ正常なファイルを置いて母集団から静かに消す」経路を塞ぐ。
    });
    it("母集団が空でない (.ts と .svelte のどちらも)", …);
    it("母集団の全件がちょうど 1 本の program に載っている", …);
});

describe("TS 側の判定保留 (既定拒否の受け皿)", () => {
    it("indeterminate はすべて KNOWN_INDETERMINATE_TS_DECLARATIONS に登録されている", …);
    it("登録は実在・重複無し・reason が 30 文字以上・件数が pin と一致する", …);
    it("登録先が stale になっていない (今も判定保留のままである)", …);
});

describe("逆走査の判定不能", () => {
    it("判定不能な組は 0 件である (名前を決められないのに列挙と交差する分岐は無い)", …);
});
```

### 申告 (`REVERSE_SWEEP_EXEMPTIONS`) の再整備

現行 1 件に **7 件を足して合計 8 件**にする (**足す時点は 2 つに分かれる** —
段 6 で 6 件、段 9 で 1 件。下の「実装の順序」を参照)。`rule` は `"1" | "2a" | "2b"`。

**同一性は `php` と候補の locator (`file` / `shape` / `name` / `occurrence`) と `rule`** である。
**locator は 1 本の採番器 (`locatorOf`) だけが作る** — 候補 / 判定保留 / 解決済みの関係 /
逆走査の申告 / stale 判定 / 重複検査 / 診断のすべてが同じ採番空間を使う
(レビュー Round 4 の Critical)。
(レビュー Round 3 の Critical。`file + name` だけでは入れ子の同名宣言と衝突する)。
下の表では `shape` と `occurrence` を省いて読みやすくしてあるが、
実際の登録には**必ず両方を書く** (現物では同名の衝突が無いのでどれも `occurrence: 0`)。

| # | php | file | declaration | rule | 理由の要点 |
|---|---|---|---|---|---|
| 1 | `app/Enums/Manual/TakeStatus.php` | `resources/js/types/manual.ts` | `SelectableTakeStatus` | `"1"` | 既存。部分集合の意図 |
| 2 | `app/Enums/Manual/CutType.php` | `…/ScenarioEditor.svelte` | `DragOwner` | `"1"` | ドラッグの所有者という**別概念**で値がたまたま一致する。統合しない (思考原則 4) |
| 3 | `app/Enums/Notification/NotificationType.php` | `…/NotificationListItem.svelte` | `switch:notification.type` | `"1"` | 絵柄を選ぶ分岐。値が増えると既定の枝 (ベルの絵柄) に落ち、新種の通知が汎用の絵柄で出る (操作は詰まらない)。期待動作は「新種を足すときに絵柄も足す」。**値が増えれば完全一致が崩れて申告が stale になり赤くなる** (移り先が 2a か 2b かは判定対象の型名を解決できるかに依る) |
| 4 | `app/Enums/ApiKeyAbility.php` | `…/ApiKeys/Index.svelte` | `ABILITY_LABELS` | `"1"` | 表示ラベル表。未知の値は素の文字列で表示する退避 (`?? ability`) があるので取りこぼしが画面を壊さない |
| 5 | `app/Enums/OAuth/OAuthClientKind.php` | `…/ApiKeys/Sessions.svelte` | `CLIENT_KIND_LABELS` | `"1"` | 同上 (`?? kind`) |
| 6 | `app/Enums/EnterpriseSso/OidcConnectionStatus.php` | `tests/js/.../oidc-connection.test.ts` | `ALL_STATUSES` | `"1"` | 検査が並べた全値。写しではなく検査の入力である |
| 7 | `app/Enums/Manual/JobStatus.php` | `resources/js/types/dashboard.ts` | `DashboardJobStatus` | `"2b"` | 進行中だけを表す**意図した真部分集合**。終端の状態はダッシュボードに出ない |
| 8 | `app/Enums/ApiErrorCode.php` | `packages/cli/src/api/schemas.ts` | `ApiErrorCode` | `"2a"` | サーバの符号と**正規でない面固有の符号**の**合併**。サーバ側の写しは `API_ERROR_CODES` として登録済みで、合併型は写しではない (S11) |

→ `EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT = 8`。

**この 8 件は設計時の見積りである**。実装時に走らせて実測と突き合わせ、
食い違ったら**申告を足すのではなく差分の理由を確認**してから決める。

### 判定保留の申告 (`KNOWN_INDETERMINATE_TS_DECLARATIONS`)

| file | line | name | 理由の要点 |
|---|---|---|---|
| `tests/js/support/enum-ts-sync/fixtures/t22-circular.ts` | 1 | `X` | 型別名が自分自身を経由して循環する見本。型検査器が解決できないことを固定するために置いてある |
| 同上 | 2 | `Y` | 同上 (循環の相方) |
| `tests/js/support/enum-ts-sync/fixtures/t23-unresolved-import.ts` | 3 | `X` | 実在しないモジュールからの取り込みに依存する見本。解決できないことを固定するために置いてある |

→ `EXPECTED_INDETERMINATE_TS_COUNT = 3`。**同一性は候補と同じ locator**
(`file` / `shape` / `name` / `occurrence`) で持ち、**行はメッセージにだけ使う**。
`file + name` だけにすると、入れ子の同名宣言が 1 行の申告でまとめて免除される
(レビュー Round 3 の Critical)。

### PHP 側の分類の更新

分類の呼び名を **「TS との関係を登録済み」** に改める (`equal` と `subset` の両方を含むため。
「写しを登録済み」だと `subset` の行を言い表せない)。

- **2 件を「対象外」から「関係を登録済み」へ移す** (道具パッケージが母集団に入ったため、
  「画面へは出ない」という理由が事実でなくなる):
  - `app/Enums/ApiErrorCode.php` → `ENUM_TS_RELATIONS` へ (`relation: "equal"`)
  - `app/Enums/OAuth/CliOAuthScope.php` → `ENUM_TS_RELATIONS` へ (**`relation: "subset"`**。
    値域そのものの写しではなく「道具が既定で要求する権限」である)
  → `EXPECTED_EXEMPTION_COUNT` 95 → **93**、`EXPECTED_RELATION_COUNT` 29 → **31**
- **2 件の理由を事実に合わせて書き直す** (分類は「対象外」のまま。件数は変わらない):
  - `app/Enums/ApiKeyAbility.php` → 「API キー権限 (read/write)。画面はチェックボックスの
    選択状態で操作し、表示ラベル表は未知の値を素の文字列へ退避するため値域の写しを要さない」
  - `app/Enums/OAuth/OAuthClientKind.php` → 「OAuth クライアント種別。認可判定の内部語彙で、
    画面の表示ラベル表は未知の値を素の文字列へ退避するため値域の写しを要さない」

### 失敗メッセージ (i13)

```
未登録の PHP・TS 関係の候補が見つかりました。正本は PHP 側です。
規則2a app/Enums/ApiErrorCode.php:12 (ApiErrorCode)
     ⇔ packages/cli/src/api/schemas.ts:310::ApiErrorCode (literal-union)
     厳密名対応 (apierrorcode = apierrorcode) / 交差 6 値
     PHP にだけある値: actor_not_resolvable, idempotency_in_progress, …
     TS にだけある値: quota_exceeded, rate_limit_exceeded, …
     直し方:
       - TS が PHP の値域**そのものの写し**なら ENUM_TS_RELATIONS へ
         relation:"equal" で 1 行足し、EXPECTED_RELATION_COUNT を 1 増やす
       - TS が PHP の値域から**選んだ非空の集合**なら relation:"subset" と
         subsetReason (30 文字以上) を付けて登録する
       - どちらでもないなら REVERSE_SWEEP_EXEMPTIONS へ理由 30 文字以上で登録し
         EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT を直す
       - 登録できるのは型別名か const の配列である。対応表のキーと分岐のラベルは
         いったん型別名か const の配列へ切り出す
```

### 波及変更: `ResolvedPhpEnum.line` の追加

PHP 側の行を出すために `ResolvedPhpEnum` に `line` を足す
(`detectEnumHeaders` の `offset` から改行を数える。無害化した写しは長さが元と同じ)。
**この型を作っている場所をすべて直す**:

- `tests/js/support/enum-ts-sync/php-enum-catalog.ts` の `classifyPhpFile` / `buildPhpEnumCatalog`
- `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts` の `phpEnum()` ヘルパと
  D1〜D18 / E1〜E11 の手書きオブジェクト
- `tests/js/architecture/enum-ts-sync-discovery.test.ts` の合成入力

### テスト計画

- [ ] **先に赤くする**: 新しい母集団・4 形・論理和で走らせ、申告が現行 1 件のままだと
      **未登録候補が 9 件出て赤くなる**ことを確認する (S11 の是正前の実測 10 件のうち
      既存申告 1 件を除いた数)
- [ ] 段 6 で**ドリフトでない 6 件**を申告すると、残る赤が
      `ApiErrorCode` (合併型) / `API_ERROR_CODES` / `DEFAULT_CLI_SCOPES` の **3 件**になること
- [ ] 段 9 で登録 2 件 + 申告 1 件を足すと緑になること
- [ ] 申告の stale 検査: 申告 1 件の `rule` をわざと変えると赤くなる
      (**規則が移ると申告が stale になる**負例そのもの)。
      `occurrence` をわざと変えても赤くなること
- [ ] **故障注入 6**: 生死判定を「免除適用後」に変えると、
      自分自身を根拠にする申告の見本が通ってしまい負の対照が赤くなる
- [ ] メッセージに PHP 側の行と TS 側の行が両方出ること (文字列の照合)

---

## S9: 検出器の自己検査 (負の対照と故障注入)

### 変更箇所

- `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts`
- 追跡する見本の追加:
  - `tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts` (4 形へ拡張)
  - `tests/js/support/enum-ts-sync/fixtures/candidates/derived.ts` (派生の 7 パターン)
  - `tests/js/support/enum-ts-sync/fixtures/candidates/witness-cycle.ts` (自己 / 相互 / 3 件循環)
  - `tests/js/support/enum-ts-sync/fixtures/svelte/Sample.svelte` (module + 実体。**正常な形だけ**)
  - `tests/js/support/enum-ts-sync/fixtures/svelte/Other.svelte` (同名宣言の干渉を見る相方)

**不正な入力は追跡ファイルにしない**。構文の壊れた `.svelte`・同じ文脈の script が 2 つ・
受理しない属性・module から実体への参照・同名の最上位束縛・呼び出し式の分岐は、
**テストの中の文字列**として `toVirtualUnit()` / `createFixtureProgram()` に渡す。
追跡ファイルにすると母集団に入って**本番の gate が恒久的に赤**になる。

**見本の値の綴り**: `fixtures/` は母集団に入るので、見本の値は
**現物の列挙と交差しない綴り** (`"a"` `"b"` `"zzz-sample-1"` など) にする。
この約束を `fixtures/` の見本ファイルの docblock へ書く。

**`.svelte` の見本の置き場**: `resources/js` の外なので `pnpm lint` /
`svelte-no-undef-gate` の対象にならない (どちらも `resources/js` を見る)。

### 既存テストの扱い (禁止事項 3)

- D1〜D18 (PHP 側の分類) は**そのまま残す** (`line` の追加に伴う機械的な更新のみ)
- E1〜E11 (突き合わせ純関数) は**残す**。`rule` の値だけ `1 → "1"` / `2 → "2a"` へ直す
- 「走査根の配下でないファイルは対象にしない」は母集団の考え方が変わるので
  「**除外根の配下は対象にしない**」へ**意味を更新**する (削除ではない)
- 「走査した非宣言ファイルの集合は、ファイルシステムを直接歩いた集合と一致する」は
  「**母集団の全件が所有者をちょうど 1 つ持つ**」へ意味を更新する
  (独立実装で突き合わせるという性質は維持する)
- 意味を更新したことを対応マトリクスと commit メッセージに残す

### 本番の入口と純関数の分け方 (レビュー Round 2 の Warning)

**本番の入口に「任意の述語を渡せる口」を作らない**。戦略は入口の側で固定し、
自己検査は**純関数へ入力のデータを渡して**判定を突く。

```ts
// 本番の入口。戦略は固定で、差し替え口は無い。
export const collectTsCandidates = (programs: MirrorPrograms): TsCandidateScan =>
    collectTsCandidatesCore(programs, PRODUCTION_STRATEGY);

// 自己検査の対象。述語ではなく「事実」を受け取る純関数。
export const isDerivedObjectKeys = (facts: DerivedFacts): boolean => …;
export const buildWitnessIndex = (candidates: readonly TsUnionCandidate[]): ReadonlySet<string> => …;
export const matchReverseRule = (php: ResolvedPhpEnum, candidate: TsUnionCandidate): RuleMatch | null => …;
export const auditReverseSweepExemptions = (found, exemptions) => …;
export const parseTrackedOutput = (raw: string): readonly string[] => …;
export const toVirtualUnit = (relativePath: string, source: string): SvelteVirtualUnit => …;
export const switchSubjectName = (checker, expr, source): string | null => …;
```

### 負の対照と故障注入 (9 カテゴリ + 境界試験 4 件)

**`'` を付けた行は、そのカテゴリの中で「境界」を突く追加の試験**である
(`8'` はカテゴリ 8 の通常のケースではなく、名前を決められない候補が
**列挙と交差するかどうか**で挙動が分かれる境界を突く)。

| # | 対象 (純関数) | 与える入力 | 赤くなるテスト |
|---|---|---|---|
| 1 | `validateExcludedRoots` | 空の一覧 / 配下でない根 / 実在しない根 / 理由 29 文字 | 「除外根の件数が pin と一致する」「体裁」 |
| 1' (境界) | 除外根の自己点検 | 除外根に**正常な** `.ts` を置いた見本の木 (一時ディレクトリ) | 「配下は全件が本番と同じ入口で落ちる」 |
| 2 | `isDerivedObjectKeys` | 証人が無い事実 / 書かれたキー ≠ 必須プロパティの事実 / 文字列の添字がある事実 / 任意プロパティを含む事実 | 「それぞれ派生と認めない (偽を返す)」 |
| 3 | `parseTrackedOutput` | 空文字列 | 「母集団が空でない」 |
| 4 | `toVirtualUnit` | 同名の最上位束縛を持つ `.svelte` / 属性表の外の script / 構文の壊れた `.svelte` | 「それぞれ例外になる」 |
| 4' (境界) | `assertNoModuleToInstanceReference` | module から実体側の宣言を参照する `.svelte` | 「例外になる」 |
| 5 | `matchReverseRule` | 2a だけが鳴る組 / 2b だけが鳴る組 / どちらも鳴らない組 / 2a と 2b の両方に当たる組 | 「規則の識別子が期待どおり (両方に当たる組は `2a`)」 |
| 6 | `auditReverseSweepExemptions` | 免除を適用した後の候補集合 | 「自己根拠の申告が stale と判定される」 |
| 7 | `buildWitnessIndex` | 対応表のキー形だけを含む候補集合 | 「索引が空になる (対応表は証人になれない)」 |
| 8 | `switchSubjectName` | 呼び出し式 / 添字アクセス / 型名が解決できる識別子 / プロパティ参照の連なり | 「呼び出し式と添字アクセスは `null` を返す」 |
| 8' | `matchReverseRule` | 名前を決められない候補 × 交差する列挙 / 交差しない列挙 | 「前者は判定不能、後者は鳴らない」 |
| 9 | locator の一意性 (**4 形**) | 同じファイルに同名の宣言を 2 件置いた見本 — (a) 候補 + 候補 / (b) 判定保留 + 候補 / (c) 非候補 + 候補 / (d) 入れ子が先 + 最上位を登録 | 「別の locator を持つ」「一方の申告が他方へ効かない」「最上位の登録が入れ子の同名候補を消さない」「採番が三値をまたぐ」 |
| 9' (境界) | `createMirrorPrograms()` の検査 B の結線 | 検査 B の呼び出しを外した実装 | 「module → 実体の参照を持つ見本で program の作成が失敗しなくなり、統合の検査が赤くなる」 |

**故障注入の実体は「本体を一時的に壊して赤を確認する」ことである** (AGENTS.md
§4 点の 1)。上の表は**その赤を受け止めるテスト**の一覧で、
実装時に本体側を 1 つずつ壊して赤を実測し、devnotes に記録する。

## S10: 前向き gate の負の対照を新しい受理範囲へ

### 変更箇所

- `tests/js/architecture/enum-ts-sync.test.ts`

### 変更内容

1. 負の対照 1 件の期待文字列を新しい文面へ直し、道具パッケージの負例を 1 件足す

```ts
it("登録できる置き場の外の ts は拒否する", () => {
    expect(() => validateRelations([{ ...valid, ts: "tests/js/setup.ts" }])).toThrow(
        "resources/js/ 配下か packages/*/src/ 配下だけ",
    );
});

it("道具パッケージでも src の外は拒否する", () => {
    expect(() => validateRelations([{ ...valid, ts: "packages/cli/vitest.config.ts" }])).toThrow(
        "resources/js/ 配下か packages/*/src/ 配下だけ",
    );
});
```

2. `createMirrorProgram([...])` の呼び出しを `createMirrorPrograms()` へ揃える (S3)

**既存のケースは期待文言以外を変えない**。診断文の正しさが先で、台帳の手当ては帰結である
(決着 3)。この変更が `docs/template-fingerprints.json` のキーに触るため **S12 が発火する**。

---

## S11: 実ドリフト 2 件の是正

逆走査が見つけた**実在のドリフト 2 件**を、申告で黙らせずに直す。
どちらも道具パッケージ (`packages/cli`) にあり、他アプリから持ち込まれた符号が
そのまま残っていた形である。

### S11-a: API のエラー符号

#### 変更箇所

- `packages/cli/src/api/schemas.ts` (L283-310) / `packages/cli/src/api/client.ts` (L200-235)

#### 食い違い

サーバ `app/Enums/ApiErrorCode.php` の 11 値:
`unauthenticated` / `forbidden` / `insufficient_ability` / `actor_not_resolvable` /
`not_found` / `validation_failed` / `rate_limited` / `idempotency_conflict` /
`idempotency_in_progress` / `idempotency_indeterminate` / `internal_server_error`

道具側は `rate_limit_exceeded` を持つがサーバは `rate_limited`。
サーバの 4 値 (`insufficient_ability` / `actor_not_resolvable` /
`idempotency_in_progress` / `idempotency_indeterminate`) が道具側に無い。
当該ファイルの docblock 自身が「Mirrors `app/Enums/ApiErrorCode.php`」と書いている。

#### 変更後コード

```ts
/**
 * サーバの `App\Enums\ApiErrorCode` の写し。
 * 値集合の一致は `tests/js/architecture/enum-ts-sync.test.ts` が機械で固定する。
 * **ここに正規でない面固有の符号を混ぜない** — 混ぜると同期の検査が成立しなくなる。
 */
export const API_ERROR_CODES = [
    "unauthenticated", "forbidden", "insufficient_ability", "actor_not_resolvable",
    "not_found", "validation_failed", "rate_limited", "idempotency_conflict",
    "idempotency_in_progress", "idempotency_indeterminate", "internal_server_error",
] as const;

/**
 * **正規でない (サーバの列挙に無い) 面固有の符号**。
 * 封筒の形だけを共有する面 (課金・入力の無害化・撮影面の判定) がサーバ側で返す。
 * 発生源はサーバ側の個別の面であり、道具の内部で作る符号ではない。
 */
export const NON_CANONICAL_API_ERROR_CODES = [
    "quota_exceeded", "payload_sanitization_failed", "site_not_cli_capture", "use_audits_submit",
] as const;

/** 道具が受け取り得る符号の全体。未知の符号は拒否せず状態番号へ退避する (既存の契約)。 */
export type ApiErrorCode =
    | (typeof API_ERROR_CODES)[number]
    | (typeof NON_CANONICAL_API_ERROR_CODES)[number];
```

- `client.ts` の `case "rate_limit_exceeded":` を **`case "rate_limited":`** へ差し替える。
  旧綴りは残さない (後方互換の並走を残さない)
- `client.ts` の docblock の符号の並びも新しい分類へ直す
- **サーバ側の 4 値の分類を明示の枝で足す** (状態番号への退避に任せない。
  「枝を 1 符号 1 本に保つのは意図的」という既存の docblock の方針に合わせる)。
  値は `defaultStatus()` と `dispatchKindFromStatus()` の対応から決まり、
  **どちらの経路でも同じ分類になる**:

| 符号 | サーバの既定の状態 | 分類 |
|---|---|---|
| `insufficient_ability` | 403 | `auth` |
| `actor_not_resolvable` | 403 | `auth` |
| `idempotency_in_progress` | 409 | `conflict` |
| `idempotency_indeterminate` | 409 | `conflict` |

#### 公開契約の確認 (レビュー Round 1 の Warning)

- `packages/cli/package.json` の `main` は `./dist/index.js`、`types` は `./dist/index.d.ts`
- `src/index.ts` が書き出すのは `getCliVersion()` **だけ**で、`api/schemas` を再輸出しない
- したがって `API_ERROR_CODES` は**パッケージの公開面ではない** (深い取り込みでしか届かない)
- パッケージ名は `@app/cli` (作業空間の中だけで解決する名前。`linkWorkspacePackages: true`) で、
  登録所へ公開する設定 (`publishConfig` 等) を持たない

→ 外部の利用者への影響は無いと判断する。**この根拠を設計に残す**
(「後方互換を残さない」は外部影響の確認を省く根拠にはならない)。

### S11-b: CLI OAuth のスコープ

#### 変更箇所

- `packages/cli/src/oauth/login.ts` (L45-56)

#### 食い違い

サーバ `app/Enums/OAuth/CliOAuthScope.php` の 4 値:
`cli:use` / `read` / `write` / `session.revoke`。
`app/Providers/McpPassportServiceProvider.php` が登録するスコープもこの 4 つである。

道具側の `DEFAULT_CLI_SCOPES` は 6 値で、**`evaluations:run` と `pages:bulk` が余分**。
サーバが登録していないスコープを要求しているので、認可要求が拒否されるか黙って落ちる。

#### 変更後コード

```ts
/**
 * 道具が既定で要求するスコープ集合。
 *
 * **サーバの `App\Enums\OAuth\CliOAuthScope` の値域そのものの写しではない** —
 * あちらは「サーバが認識する全スコープ」、こちらは「道具が既定で要求する権限」で
 * **別の概念**である。両者の関係は **部分集合** (ここに書けるのは値域の中の値だけ) で、
 * `tests/js/architecture/enum-ts-sync.test.ts` が `relation: "subset"` として機械で固定する。
 * サーバが登録していないスコープを書くと赤くなる。
 * **サーバにスコープが増えても、道具がそれを要求する義務は無い** (最小権限)。
 */
export const DEFAULT_CLI_SCOPES = ["cli:use", "read", "write", "session.revoke"] as const;
```

**なぜ完全一致の写しにしないか** (レビュー Round 2 の Critical):
今は偶然 4 値が一致するが、完全一致で登録すると「サーバにスコープを足したら
道具側も足さないと赤くなる」= **道具が自動的に広い権限を要求する方向**へ設計が引っ張られる。
最小権限に反し、AGENTS.md 思考原則 4 (別物の概念を似ているからで統合しない) にも抵触する。
`relation: "subset"` にすれば、余分な値 (`evaluations:run` / `pages:bulk`) は赤くなり、
サーバ側の追加は赤くならない。

### 目録への登録

`ENUM_TS_RELATIONS` へ 2 行足し、`EXPECTED_RELATION_COUNT` を 29 → **31** にする。
併せて `PHP_ENUM_EXEMPTIONS` から同じ 2 件を外す (95 → **93**)。

```ts
{
    php: "app/Enums/ApiErrorCode.php",
    ts: "packages/cli/src/api/schemas.ts",
    declaration: "API_ERROR_CODES",
    relation: "equal",
    note: "付属のコマンドライン道具が応答の符号で失敗の種類を分ける (rate-limit / conflict / auth)",
},
{
    php: "app/Enums/OAuth/CliOAuthScope.php",
    ts: "packages/cli/src/oauth/login.ts",
    declaration: "DEFAULT_CLI_SCOPES",
    relation: "subset",
    note: "道具がログインのときに既定で要求する権限の集合",
    subsetReason: "値域そのものの写しではなく、サーバが認識する値域から道具が既定で要求する権限だけを選んだ集合であるため。サーバ側の追加を道具へ強制しない (最小権限)",
},
```

**既存の 29 行はすべて `relation: "equal"`** を明示する (既定値に頼らず全行に書く。
`satisfies` で型に縛られるので書き漏らしは型検査が落とす)。

合併型 `ApiErrorCode` は規則 2a で鳴り続けるので**申告 1 件**で逃がす (S8 の #8)。
`NON_CANONICAL_API_ERROR_CODES` はサーバの列挙と 1 値も交差しないので鳴らない。

### テスト計画

- [ ] `dispatchKindFromCode()` を試験のために輸出する場合でも、
      **パッケージの公開面 (`src/index.ts`) からは再輸出しない**
- [ ] **先に赤くする (S11-a)**: **純関数 `dispatchKindFromCode()` を直接**試験する
      (応答を組み立てないので「符号が効いたのか状態番号が効いたのか」が曖昧にならない)。
      `dispatchKindFromCode("rate_limited")` が `"rate-limit"` を返すことを主張 →
      現行は `null` を返すので赤
- [ ] 符号による分類の表 (純関数の単位):
      `rate_limited → rate-limit` / `insufficient_ability → auth` /
      `actor_not_resolvable → auth` / `idempotency_in_progress → conflict` /
      `idempotency_indeterminate → conflict` / `quota_exceeded → quota` /
      **未知の符号 → `null`** (状態番号へ回す)
- [ ] 応答の単位での退避の固定:
      - `rate_limited` + 429 → `rate-limit`
      - 旧 `rate_limit_exceeded` + 429 → 未知の符号として状態番号へ退避し `rate-limit`
        (**旧綴りのサーバでも失敗の種類が変わらない**ことの固定)
      - 未知の符号 + 409 → `conflict` (状態番号の退避が効くこと)
- [ ] **S11-b の赤**: 目録へ `relation: "subset"` の行を足すと
      `enum-ts-sync.test.ts` が「TS 側にだけある値がある」で落ちること
      (`evaluations:run` / `pages:bulk`)
- [ ] 目録へ 2 行足し、道具側を直すと `enum-ts-sync.test.ts` が緑になること
- [ ] `pnpm typecheck:packages` / `pnpm test:packages` / `pnpm build:packages` が緑

### リスク

- `rate_limit_exceeded` を落とすと、**古いサーバ**がその綴りを返す環境で符号による分類が
  効かなくなる。ただし 429 の状態番号への退避が残るので失敗の種類は同じ `rate-limit` になる
  (上のテストで固定する)。この判断を `client.ts` の docblock に残す
- `evaluations:run` / `pages:bulk` を落とすと、それらのスコープを本当に必要とする面が
  将来できたときに要求が足りなくなる。**現時点のサーバはそのスコープを登録していない**ので
  今は要求しても意味が無い (`McpPassportServiceProvider` が登録するのは 4 つだけ)。
  足すときは**サーバの値域へ先に (または同じ変更で) 足す**。
  `relation: "subset"` の検査が「サーバに無い値を要求する」側だけを赤くするので、
  **サーバ側だけが先に増えるのは契約上許される** (道具が追随する義務は無い)

---

## S12: 乖離台帳の手当て

### 変更箇所

- `docs/template-divergence.md` (**D50 を新設**)
- `tests/Support/TemplateDivergence/adoption-debt.tsv` (`tests/js/architecture/enum-ts-sync.test.ts` の行を削除)
- `tests/Support/TemplateDivergence/LedgerPins.php`
  (`DIVERGENCE_ENTRY_COUNT` 46 → **47** / `ADOPTION_DEBT_COUNT` 148 → **147**)

### 判断の根拠 (app-design 3-0 段)

- `tests/js/architecture/enum-ts-sync.test.ts` は `docs/template-fingerprints.json` のキーであり、
  かつ `adoption-debt.tsv` に採用時ハッシュ付きで凍結されている
- S10 でこのファイルを変更するので、**「変更したまま債務に残す」は選べない**
- 3 択のうち **(3) 意図的逸脱として登録を書き債務から削る** を採る。
  (1) 採用時の姿へ戻すのは S10 の診断文の訂正を捨てることになり、
  (2) テンプレートへ同期するのはテンプレート側が正典 v2 のままなので成立しない

### D50 の中身 (要点)

- **逸脱**: 前向きの同期検査を、テンプレートの「単一ファイル・構文木のみ」ではなく、
  **共有の走査器 + 型情報 (Program + TypeChecker)** で持ち、目録を逆走査の gate と共有する
- **理由**: 正典 v3 の i4 / i5。構文木だけでは別名参照・添字アクセス・
  閉じたテンプレート文字列を読めず、その写しを登録できないため実装側に書き方の変更を強いる
- **揃え続ける不変条件**:
  - 目録 (`ENUM_TS_RELATIONS`) が前向きの検査と逆走査の**単一の出典**であること
  - 値集合の抽出器を 2 本持たないこと
  - 受理範囲の外は空集合でなく例外にすること
  - **正本のレーンは `pnpm test` であり `composer test` ではない** (レーンの非対称を台帳から追える形にする)
- **対象パス**: `tests/js/architecture/enum-ts-sync.test.ts`
- 書式 (登録メタ表の 9 行・状態の値域・対象パスの実在と重複) は
  `TemplateDivergenceLedgerFormatTest` が機械で強制する。**書式の正本は同ファイルの規約節**

### 件数の扱い

46 → 47 / 148 → 147 は**設計時点の値**である。実装の開始時と main へ入れる直前に
**現物から数え直す** (他の TODO が同じ pin を触る)。

### `tsconfig.json` は変えない

`packages/cli` は自前の tsconfig で program を作る (S3)。ルートの `include` を広げると
`pnpm typecheck` の対象まで動き、債務 pin にも触れる。

### テスト計画

- [ ] **先に赤くする**: S10 の変更を入れた時点で `TemplateDivergenceFingerprintTest` が
      `mutatedDebtPaths` で赤くなることを確認する
- [ ] D50 を書き債務の行を削り pin を直すと緑になること
- [ ] `TemplateDivergenceLedgerFormatTest` (件数の 3 点一致) が緑
- [ ] `composer test` 全体が緑

---

## S13: 文書の更新

### 変更箇所

- `AGENTS.md` ドメイン固有規約 **19**
- `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期

### 変更内容

`AGENTS.md` 19 で直すのは 4 点:

- **登録**が受理する形を「型別名の宣言**または `const` の配列**」に直す
- **登録の関係**に `equal` と `subset` の 2 つがあることを足す。
  `subset` は「**値域の写しではない**もの (例: 道具が既定で要求する権限)」に使い、
  **`subsetReason` に値域の写しでない理由を 30 文字以上で書く**、と明記する
  (`note` ではない)
- **逆走査**の走査範囲を「版管理下の `*.ts` と `*.svelte` の全数
  (検出器自身の構文破壊見本を除く)」に直し、拾う形が 4 種であることを足す
- 登録できる TS の置き場が `resources/js/` と `packages/*/src/` であることを足す

**正典 v3 の条文を転載しない**。書くのは aicue 固有の受理範囲・除外集合・登録の手順だけで、
正典は版 (家系の機能台帳 `enum-ts-sync-gate` の v3) で指す。

### `docs/architecture.md` に書くこと

- **入れ子の候補の同一性は宣言の名前だけでは足りない** — 置き場・形・名前・出現順の
  4 つ組 (locator) で持つこと。行は診断にだけ使うこと
- **`equal` と `subset` は同じ目録に載るが意味が違う** — `subset` は値域の写しではなく、
  許される値域に対する**選択集合**である
- **登録行の locator は AST から解決する** — 逆走査の候補の locator と
  **同じ採番器**で作り、登録済みの判定は locator の完全一致で行う

- **許可する値域と、そこから選んだ集合は別の概念である**ことを明記する
  (`CliOAuthScope` と `DEFAULT_CLI_SCOPES` を例に出す)。
  `subset` の登録は前者を後者へ広げないための装置である
- **`.svelte` の扱いを正確に書く** — 「1 つの仮想 TS へ平坦化する」ことと、
  平坦化で再現できない 2 つ (module から実体側への参照 / 同名の最上位束縛の shadowing) を
  **保証外ではなく不合格条件として塞いでいる**こと。
  `.svelte` 全体が `parse` できることは前提であり、script の外は候補にしないこと
- **保証しないものの正本**はここである

### 3 か所の書き分け (2 か所に同じ文を置かない)

| 置き場 | 書くこと |
|---|---|
| 走査器・gate の docblock | 実装に密着した短い保証範囲 (何を見て何を見ないか・不合格条件) |
| `docs/architecture.md` | 理由と全体像、**保証しないものの正本**。docblock から相互参照する |
| `AGENTS.md` 19 | 登録の手順と受理範囲・関係の 2 値だけ。保証しないものは `docs/architecture.md` を指す |

### テスト計画

- [ ] `docs/architecture.md` の節が実在し、`AGENTS.md` から参照されていること (人手)
- [ ] `pnpm test` / `composer test` が緑

## 実装の順序 (テストファースト)

**赤→緑の単位**を明示する。`enum-ts-sync-discovery` の gate が緑になるのは**段 9** である。

| 段 | 先に赤くするもの | 緑にする実装 | この段で逆走査 gate は緑か |
|---|---|---|---|
| 1 | `population.ts` の単体テスト (モジュールが無い) | S1 | いいえ (未着手) |
| 2 | `svelte-source.ts` の単体テスト (行・列 / `export {};` / 検査 A・B) | S2 | いいえ |
| 3 | `createMirrorPrograms()` が母集団の所有者を過不足なく決める主張 | S3 | いいえ |
| 3b | `const X = ["a","b"]` から値集合が読める主張 | S3b | いいえ |
| 4 | 4 形・派生の証人つき除外・判定保留の三値の単体テスト | S4 | いいえ |
| 5 | 2b 専用の正例 / (e) の 3 形の負例 / 語の対応の期待値 | S5 | いいえ |
| 6 | **逆走査 gate が未登録候補 9 件で赤くなる** | S8 の申告 **6 件** (ドリフトでないもの) | **いいえ** — 残り 3 件 |
| 7 | S10 の 2 つの負例 (新しい文面) が現行の文面と合わず赤くなる | S6 + S10 | いいえ |
| 8 | `.svelte` と `const` の配列と `relation: "subset"` を登録した行が読めない | S7 | いいえ |
| 9 | `dispatchKindFromCode("rate_limited")` が `null` を返す / `subset` の登録で TS 側の余分な 2 値が落ちる | S11 (是正 + 目録へ 2 行 + 申告 1 件) | **はい** |
| 10 | `TemplateDivergenceFingerprintTest` が `mutatedDebtPaths` で赤くなる | S12 | はい |
| 11 | 負の対照と故障注入 (9 カテゴリ + 境界 4 件) | S9 | はい |
| 12 | — | S13 (文書) | はい |

### 段 6 と段 9 の内訳 (実測との突合)

設計時の実測で鳴った組は **10 件**。既存申告 1 件 (`SelectableTakeStatus`) を除くと **9 件**。

**段 6 で申告する 6 件** (ドリフトではないもの):
`DragOwner` / `switch:notification.type` / `ABILITY_LABELS` / `CLIENT_KIND_LABELS` /
`ALL_STATUSES` / `DashboardJobStatus`

**段 6 で残る 3 件** (ドリフトまたはその周辺。段 9 で解消):
- `API_ERROR_CODES` (規則 2b) → **登録** (`relation: "equal"`) + 値の是正
- `DEFAULT_CLI_SCOPES` (規則 2b) → **登録** (`relation: "subset"`) + 値の是正
- `ApiErrorCode` の合併型 (規則 2a) → **申告 1 件**

**最終の件数** (設計時の見積り):

| pin | 現行 | 変更後 |
|---|---|---|
| `EXPECTED_RELATION_COUNT` | 29 | **31** |
| `EXPECTED_EXEMPTION_COUNT` (PHP 対象外) | 95 | **93** |
| `EXPECTED_UNRESOLVABLE_COUNT` (PHP 読めない) | 3 | 3 |
| `EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT` | 1 | **8** |
| `EXPECTED_INDETERMINATE_TS_COUNT` (新設) | — | **3** |
| `EXPECTED_EXCLUDED_ROOT_COUNT` (新設) | — | **1** |
| `LedgerPins::DIVERGENCE_ENTRY_COUNT` | 46 | **47** |
| `LedgerPins::ADOPTION_DEBT_COUNT` | 148 | **147** |

**この表は設計時の見積りである**。実装時に走らせて実測と突き合わせ、
食い違ったら**申告を足す前に差分の理由を確認**する。

## 後方互換・migration の扱い

- **DB の migration は無い**
- **後方互換の並走を残さない** (AGENTS.md 思考原則 3):
  - `createMirrorProgram(tsFiles)` は**廃止**し `createMirrorPrograms()` に置き換える
    (2 つの program の作り方を残さない)
  - `collectTsUnionCandidates` の `jsRoot` 引数 (走査根を差し替える負のコントロール専用) は
    **廃止**し、除外根の差し替えに置き換える
  - `reverse-sweep.ts` の `rule: 1 | 2` は `"1" | "2a" | "2b"` へ**置き換える**
  - 値の読み取りは `ts-literal-values.ts` の 1 本に集約する
    (`ts-candidates.ts` と `ts-value-sets.ts` に別々の読み方を残さない)
  - `relation` は**全行に明示**する (既定値に頼る書き方と併存させない)
  - `client.ts` の `rate_limit_exceeded` の分岐は**差し替える** (両方を受ける形にしない)
  - `DEFAULT_CLI_SCOPES` の 2 値は**削除する** (「当面残す」をしない)

## docs/template-divergence.md の登録/更新/削除の要否

| 対象 | 指紋台帳のキーか | 採用時債務か | 判断 |
|---|---|---|---|
| `tests/js/architecture/enum-ts-sync.test.ts` | **在る** | **在る** | S10 で変更するので **D50 を新設し債務から削る** (S12) |
| `tsconfig.json` | 在る | 在る | **変更しない** (S3 でパッケージごとの program を作る) |
| `tests/js/support/enum-ts-sync/*.ts` | 無い | 無い | 登録の義務なし (aicue 固有の上積み) |
| `tests/js/architecture/enum-ts-sync-discovery*.test.ts` | 無い | 無い | 同上 |
| `packages/cli/**` | 無い | 無い | 同上 |
| `AGENTS.md` / `docs/architecture.md` | 無い | 無い | 同上 |

削除する登録は無い。**実装時に `docs/template-fingerprints.json` のキーを数え直して
確認する** (他の TODO が触っている可能性がある)。

## 検証コマンド (全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 走査器・gate・目録・道具の型・乖離台帳を**同じ変更**で揃える必要がある (段 6 の赤は段 9 まで解けず、S10 の変更は S12 の手当てと不可分)。段階的に main へ入れると gate が赤いまま並走する期間ができる |
| 競合リスク | `docs/template-divergence.md` と `LedgerPins.php` は他の TODO も触る。件数 pin が衝突しやすいので、着手時と main へ入れる直前に現物から数え直す。`AGENTS.md` も同様 |

---

## 最終確認 (使命・禁止事項・コーディングルール)

### 使命への寄与

撮影 PWA と管理画面は、制作状態・カット種別・通知種別・接続状態といった**サーバ側の選択肢**で
画面の分岐を決める。写しがずれると「思考ゼロ・編集ゼロ」の導線が**無言で 1 本欠ける**
(実在しない値に当たらない / 実在する値を落とす)。しかも**どちらの側も単体では整合している**ので、
型検査でも通常のテストでも落ちない。本作業は逆走査を `.svelte` と付属の道具パッケージまで届かせ、
この静かな欠損を CI で落とす。設計の段階で**実在のドリフトを 2 件**見つけている
(API のエラー符号 / CLI OAuth のスコープ) ことが、寄与の一次的な裏付けである。

### 禁止事項との突合

| # | 禁止事項 | 本設計での扱い |
|---|---|---|
| 1 | テストなしの実装完了 | 全 13 施策にテスト計画。9 カテゴリ + 境界 4 件の負の対照と故障注入まで含む |
| 2 | PHPStan の widen / baseline | PHP の変更は無い。`composer phpstan` は緑を維持 |
| 3 | dev DB への破壊操作 | DB に触れない |
| 4 | `response()->json()` の直書き | 該当なし |
| 5 | Prism 直呼び | 該当なし |
| 6 | prompt 文字列の直書き | 該当なし |
| 7 | `redirect()->intended()` | 該当なし |
| 8 | 必須条件未充足で disabled | 該当なし |
| 9 | Artifact の使用 | 成果物は `devnotes/` 配下のファイルだけ |
| (設計スキル) 既存テストの削除・上書き | D1〜D18 / E1〜E11 / `validateRelations()` の負の対照はすべて残す。意味を更新する 2 件は削除ではなく置き換えで、対応マトリクスと commit メッセージに残す |

### 共通規約 (a)〜(e) との突合

- **(a) 完全修飾名**: 名前の解決は型検査器で行う。パッケージは自前の tsconfig で解決する (S3)
- **(b) fail-closed**: 三値 (`candidate` / `not-a-catalogue` / `indeterminate`) を分け、
  判定保留は既定拒否の申告で受ける。名前を決められない候補は交差があれば判定不能で赤くする。
  母集団 0 件・構文の破損・program に載らない・型が解決できないは、すべて不合格側へ倒す
- **(c) 両方向の負例**: 正例と負例をすべての判定に置き、故障注入で赤を実測する
- **(d) 使わない走査結果を作らない**: 候補・差分・申告・判定保留はすべて判定か診断に使う
- **(e) 語彙一致**: 区切りを宣言し、トークンの対応で判定する。負例は接頭辞・打ち消し・接尾辞の 3 形

### 4 点 (走査器・gate を新設・変更するときに揃える)

1. **負例と正例** … テストファーストの順序を段 1〜12 で明示した
2. **解決できない形を落とす分岐** … 判定保留 / 判定不能 / 構文の破損 / 母集団 0 件
3. **走査が空振りしていないことの検査** … 母集団の非空、所有者の割当の過不足、除外根の自己点検
4. **docblock に走査対象と保証しないものを書く** … S8 の保証範囲一覧。正本は `docs/architecture.md`
