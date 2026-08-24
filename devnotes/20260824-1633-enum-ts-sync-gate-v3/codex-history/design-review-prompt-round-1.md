## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


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


## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / Vitest
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【本件の性質】
本件は「家系の機能台帳 (lctl) が確定した正典 v3 への追従」であり、対象は本番アプリのコードではなく CI で走る静的検査 (gate) とその走査器 (TypeScript) である。正本のレーンは `pnpm test` (vitest)。概念設計は同じ Codex セッションとは別セッションで 4 ラウンド議論して APPROVED になっている。

したがって DTO/JsonResource は直接関係しないが、AGENTS.md の次の 2 節が強く効く:
- 「静的検査 (gate) と走査器の共通規約」(a) 完全修飾名で突き合わせる / (b) 解決できない形を落とす (fail-closed。未解決を解決済みと同じ値へ混ぜない。保証範囲外は docblock へ明記。違反 0 件と母集団 0 件を区別) / (c) 検出力は負例で裏取り (両方向) / (d) 集めた走査結果を判定に使わない形を作らない / (e) 語彙一致の否定形は区切りで割ったトークンの完全一致 (区切りを宣言。負例に接頭辞・打ち消し・接尾辞の 3 形)
- 「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」= 負例と正例 (テストファーストで先に赤く) / 解決できない形を落とす分岐 / 走査が空振りしていないことの検査 / docblock に走査対象と保証しないものを書く

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性、TypeScript Compiler API の使い方の正しさ）
2. 既存コードとの整合性（命名規約、パターン、API。既存テストの削除・上書きをしていないか）
3. 型安全性（TS strict。型の解決不能を握り潰していないか）
4. テスト計画の網羅性（各施策にテスト。テストファーストの順序が実際に赤くなるか）
5. 副作用・後退リスク（本番の型世界を変えていないか、既存 gate を壊さないか）
6. 波及変更の網羅性（型定義・呼び出し側・見本・件数 pin・文書が変更対象に入っているか）
7. セキュリティ（本件は検査なので限定的だが、道具 (CLI) の失敗分類の変更が利用者影響を持つ）
8. AGENTS.md の共通規約 (a)〜(e) と 4 点への適合
9. 乖離台帳 (指紋台帳 / 採用時債務 / 件数 pin) の扱いが正しいか

【特に見てほしい論点】
- S2 の `.svelte` 仮想化: `svelte/compiler` の `parse` の使い方、行・列の保存、module/instance のスコープ分離、fail-closed の条件に穴はないか
- S3 の program 拡大: `ts.CompilerHost` の差し替えで仮想ファイルを載せる方法が正しいか。`packages/cli` をルートの tsconfig の設定で読むことの副作用
- S4 の派生除外: `getIndexInfoOfType` / `getPropertiesOfType` / `SymbolFlags.Optional` の使い方は妥当か。2 パスの証人判定に循環が残っていないか
- S5 の規則 2b: 区切り・正規化・主要語・閾値の定義に穴はないか。(e) の負例 3 形が本当に不成立になるか
- S11 の CLI 変更: `rate_limit_exceeded` を落とす判断の利用者影響
- 実装の順序 (テストファースト) が実際に「先に赤く」なるか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
| S2 | `.svelte` の仮想 TS 化 | `tests/js/support/enum-ts-sync/svelte-source.ts` (新) | 高 |
| S3 | program の起点拡大と仮想ファイルの載せ替え | `tests/js/support/enum-ts-sync/program.ts` | 高 |
| S4 | 候補走査を 4 種へ + 派生の証人つき除外 | `tests/js/support/enum-ts-sync/ts-candidates.ts` | 高 |
| S5 | 規則 2 の論理和 | `tests/js/support/enum-ts-sync/reverse-sweep.ts` | 高 |
| S6 | 目録の受理範囲拡大 (`packages/*/src/` と `.svelte`) | `tests/js/support/enum-ts-sync/mirror-inventory.ts` | 中 |
| S7 | 前向きの検査の `.svelte` 対応 | `tests/js/support/enum-ts-sync/ts-value-sets.ts` | 中 |
| S8 | 逆走査 gate の再整備 (申告・pin・メッセージ・保証範囲) | `tests/js/architecture/enum-ts-sync-discovery.test.ts` | 高 |
| S9 | 検出器の自己検査 (負例と故障注入) | `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts` + 見本 | 高 |
| S10 | 前向き gate の負の対照を新しい受理範囲へ | `tests/js/architecture/enum-ts-sync.test.ts` | 中 |
| S11 | 実ドリフトの是正 (CLI の符号一覧) | `packages/cli/src/api/schemas.ts` / `client.ts` / `packages/cli/tests/` | 高 |
| S12 | 乖離台帳の手当て (D50 / 債務 1 行 / 件数 pin 2 つ) | `docs/template-divergence.md` / `adoption-debt.tsv` / `LedgerPins.php` | 中 |
| S13 | 文書の更新 | `AGENTS.md` / `docs/architecture.md` | 中 |

---

## S1: 母集団モジュールの新設

### 変更箇所

- 新規: `tests/js/support/enum-ts-sync/population.ts`

### 波及変更

- TypeScript 型定義: 新規 (`PopulationFile`)
- API Resource/DTO: なし
- テストファイル: `enum-ts-sync-discovery-extractor.test.ts` に単体の負例を足す

### 変更後コード（骨子）

```ts
/**
 * 逆走査の母集団 (正典 v3 の i8)。
 *
 * **母集団**: `git ls-files` が返す**版管理下の `*.ts` と `*.svelte` の全数**。
 * 走査根の手書きの列挙は持たない (足し忘れが静かな穴になる)。
 * どちらかが 0 件なら「母集団が不明」として例外にする (空振りを緑にしない)。
 *
 * **唯一の除外**: `EXCLUDED_ROOTS`。**わざと構文を壊した見本**だけを外す。
 * i14 が「構文が壊れたファイルを無言で読み飛ばさない」ので、これを母集団に入れると
 * 本番の gate が恒久的に赤くなる。申告では逃がせない (申告は候補を逃がす仕組みで、
 * 読めないファイルの受け皿ではない)。
 * 除外は `tests/js/support/enum-ts-sync/` の配下に限る (構造で縛る)。
 *
 * **保証しないもの**: 版管理外のファイル (`.gitignore` されたもの・未追跡のもの) は見ない。
 * `.js` / `.mjs` / `.cjs` / `.d.ts` は母集団に入れない (`.d.ts` は `isDeclarationFile` で落ちる)。
 */
export const EXCLUDED_ROOTS = [
    {
        root: "tests/js/support/enum-ts-sync/fixtures/candidates-broken",
        reason: "候補走査が構文の壊れたファイルを無言で読み飛ばさないことの負の対照。中身は意図的に壊してある",
    },
] as const satisfies readonly ExcludedRoot[];

export const EXPECTED_EXCLUDED_ROOT_COUNT = 1;

/** 除外根の体裁 (`tests/js/support/enum-ts-sync/` 配下・実在・重複無し・理由 30 文字以上)。 */
export const validateExcludedRoots = (roots = EXCLUDED_ROOTS, root = REPO_ROOT): void => { … };

/** 版管理下の `*.ts` (除外根の配下を除く)。0 件は例外。 */
export const listPopulationTsFiles = (root = REPO_ROOT): readonly string[] => { … };

/** 版管理下の `*.svelte` (除外根の配下を除く)。0 件は例外。 */
export const listPopulationSvelteFiles = (root = REPO_ROOT): readonly string[] => { … };

/** 除外根の配下にある版管理下ファイル (除外の自己点検に使う。0 件は例外)。 */
export const listExcludedFiles = (root = REPO_ROOT): readonly string[] => { … };
```

- `git ls-files -- '*.ts'` / `'*.svelte'` を `execFileSync` で実行する
  (`php-enum-catalog.ts` の `listTrackedPhpFiles` と同じ形。**同じ列挙を 2 本持たない**ため
  PHP 側の実装はそのまま残し、TS 側はこの新モジュールに 1 本だけ置く)
- 除外の判定は**パスの区間一致** (`rel === root || rel.startsWith(root + "/")`)。
  素の `startsWith` にしない (兄弟ディレクトリ `candidates-broken-2/` を巻き込むため)

### PHPStan適合チェック

- [x] PHP の変更なし (TS 側は `strict` + `noUncheckedIndexedAccess` 相当を維持)

### テスト計画

- [ ] **先に赤くする**: `listPopulationTsFiles()` が `packages/cli/src/api/schemas.ts` を含むことを
      主張するテストを書く → 現状 `population.ts` が無いので**モジュール解決で失敗**する (赤)
- [ ] `listPopulationSvelteFiles()` が 130 本前後を返し、`.svelte` を含むこと
- [ ] 除外根の配下のファイルがどちらの一覧にも入らないこと
- [ ] 除外根の体裁検査の負例: 配下でないパス / 実在しないパス / 重複 / 理由 29 文字
- [ ] **故障注入 1**: `git ls-files` の結果を空にした根 (一時ディレクトリ) を渡すと例外になる

### リスク

- `git ls-files` は worktree でも動くが、**shallow でない clone** が前提。
  `listTrackedPhpFiles` が既に同じ前提で動いているので新しいリスクではない

---

## S2: `.svelte` の仮想 TS 化

### 変更箇所

- 新規: `tests/js/support/enum-ts-sync/svelte-source.ts`

### 変更後コード（骨子）

```ts
/**
 * `.svelte` を第一級の解析対象にする (正典 v3 の i6)。
 *
 * `svelte/compiler` の `parse` (解析ツール向けの入口) で script の範囲を取り、
 * **script の中身以外を空白で潰した**仮想 TypeScript を作る。空白で潰すので
 * **行も列も元ファイルと一致する** (診断の位置がそのまま `.svelte` の位置になる)。
 *
 * **文脈ごとに別の仮想ファイルへ割る**。`<script module>` と実体の `<script>` は
 * 別スコープなので、1 本へ連結すると偽の重複宣言と誤った名前解決が出る。
 *
 * **不合格にするもの (fail-closed)**:
 * - `parse` が失敗した (構文が壊れている)
 * - 同じ文脈の script が 2 つ以上ある
 * - script が `src` 属性で外部を参照している (中身がこのファイルに無い)
 *
 * **保証しないもの**: 目印の中の式 (`{…}`)、`{#if}` などの制御構文の中、
 * スタイルの中は見ない。script の外に書いた値の一覧は**候補にならない**。
 */
export type SvelteScriptContext = "module" | "instance";

export interface SvelteVirtualUnit {
    readonly context: SvelteScriptContext;
    /** 元の `.svelte` のリポジトリ相対パス。 */
    readonly source: string;
    /** program に載せる仮想の絶対パス。 */
    readonly virtualPath: string;
    /** 行・列を保った仮想 TS。 */
    readonly text: string;
}

/** 仮想パスの接尾辞。実在ファイルと衝突しない綴りにする (`*.svelte.ts` は実在する)。 */
export const VIRTUAL_SUFFIX: Record<SvelteScriptContext, string> = {
    module: ".__enum_ts_sync_module__.ts",
    instance: ".__enum_ts_sync_instance__.ts",
};

export const toVirtualUnits = (relativePath: string, source: string): readonly SvelteVirtualUnit[] => { … };

/** 仮想パス → 元の `.svelte` の相対パス。仮想でなければ `undefined`。 */
export const realPathOfVirtual = (virtualPath: string): string | undefined => { … };
```

- 実装の骨: `parse(source, { modern: true })` → `root.instance` / `root.module`。
  それぞれ `content.start` / `content.end` が script 本体の範囲。
  範囲外の文字は `"\n"` はそのまま、それ以外は `" "` に置換する
- **衝突の防止**: 仮想パスの綴りが版管理下に実在しないことを `population.ts` の一覧に対して
  検査する (実在したら例外)

### 実測による裏取り (設計時)

版管理下の `.svelte` **130 本すべてが `parse` に成功**し、`module` script を持つのは 2 本、
同じ文脈の script が 2 つ以上あるファイルは 0 本 (svelte 5.56.3)。

### テスト計画

- [ ] **先に赤くする**: 見本 `.svelte` (module と実体の両方を持つ) から
      2 つの仮想単位が返ることを主張 → モジュールが無いので赤
- [ ] 行・列の一致: 見本の `type X = "a" | "b";` が元ファイルの行・列と一致すること
- [ ] スコープ分離: module と実体に**同名**の宣言を置いた見本で、
      重複宣言の診断が出ず、両方が別々の候補として拾えること
- [ ] **故障注入 4**: 2 つを 1 本へ連結する実装に差し替えると上のテストが赤くなる
- [ ] 不合格の負例: 構文の壊れた `.svelte` / 同じ文脈の script が 2 つ / `src` 属性つき
- [ ] 仮想パスの綴りが実在ファイルと衝突したら例外になること

---

## S3: program の起点拡大と仮想ファイルの載せ替え

### 変更箇所

- `tests/js/support/enum-ts-sync/program.ts` (L69-99 の `createMirrorProgram` 周辺)

### 波及変更

- TypeScript 型定義: `MirrorProgram` に `virtualPaths: ReadonlyMap<string, string>` を足す
  (仮想パス → 元の `.svelte` の相対パス。候補側とメッセージ側が使う)
- テストファイル: `enum-ts-sync.test.ts` (呼び出し形は変えない) /
  `enum-ts-sync-discovery*.test.ts`

### 現行コード

```ts
export const createMirrorProgram = (tsFiles: readonly string[]): MirrorProgram => {
    const parsed = parseRepoTsconfig();
    const inventoryRoots = tsFiles.map((file) => { … });
    return buildProgram([...new Set([...parsed.fileNames, ...inventoryRoots])], parsed);
};
```

### 変更後コード（骨子）

```ts
/**
 * 起点は **tsconfig が含む全ファイル ∪ 版管理下の `*.ts` ∪ 仮想 `.svelte` ∪ 目録のファイル**。
 *
 * `tsconfig.json` は**変えない**。本番のビルド設定であって gate の都合で広げるものではなく、
 * 広げると `pnpm typecheck` の対象まで動く。取り込み範囲の外にある道具パッケージ
 * (`packages/cli`) は**起点に足す**ことで型世界へ入れる。
 * 速さのために起点を縮めない (実測: 構築は約 3 秒)。
 */
export const createMirrorProgram = (tsFiles: readonly string[]): MirrorProgram => {
    const parsed = parseRepoTsconfig();
    const inventoryRoots = tsFiles.map(resolveExistingInventoryFile);

    const virtualSources = new Map<string, string>();   // 仮想絶対パス → 中身
    const virtualPaths = new Map<string, string>();     // 仮想絶対パス → 元の相対パス
    for (const relative of listPopulationSvelteFiles()) {
        for (const unit of toVirtualUnits(relative, fs.readFileSync(abs(relative), "utf-8"))) {
            virtualSources.set(unit.virtualPath, unit.text);
            virtualPaths.set(unit.virtualPath, unit.source);
        }
    }

    const rootNames = [...new Set([
        ...parsed.fileNames,
        ...listPopulationTsFiles().map(abs),
        ...virtualSources.keys(),
        ...inventoryRoots,
    ])];

    return buildProgram(rootNames, parsed, virtualSources, virtualPaths);
};
```

- `buildProgram` は `ts.createCompilerHost` を包んだ host を渡す形にする。
  `fileExists` / `readFile` / `getSourceFile` の 3 つだけを仮想対応させる
  (`getSourceFile` は `ts.ScriptKind.TS` で `setParentNodes = true`)
- `createFixtureProgram` (見本専用の縮めた program) は**そのまま残す**。
  ただし仮想 `.svelte` を明示で渡せるよう、引数に仮想単位の配列を受ける多重定義を足す
- **`createMirrorProgram(tsFiles)` の引数の形は変えない** (S10 の前向き gate の呼び出しを
  そのままにするため。中で母集団を足すだけ)

### PHPStan適合チェック

- [x] PHP の変更なし

### テスト計画

- [ ] **先に赤くする**: `createMirrorProgram([])` の program に
      `packages/cli/src/api/schemas.ts` が載っていることを主張 → 現状は載らないので赤
- [ ] 仮想 `.svelte` が program に載っていること (`virtualPaths` が 130×(1〜2) 件)
- [ ] `program.getSourceFiles()` の非宣言ファイルの集合が、
      **`population.ts` の一覧 + 仮想単位 + tsconfig の分**と一致すること
      (現行の「ファイルシステムを直接歩いた集合と一致する」検査を、
      走査根を `resources/js` から母集団全体へ広げた形へ置き換える)
- [ ] **故障注入 3**: 母集団の列挙を空に差し替えると「母集団が 0 件」で赤くなる
- [ ] 構築時間が `beforeAll` の 300 秒枠に収まること (実測を記録するだけで assert はしない)

### リスク

- `packages/cli` は自前の `tsconfig.json` (NodeNext) を持つ。ルートの設定 (bundler) で
  読むと**取り込みの解決が一部失敗**し、意味の診断が出る。本 gate は**意味の診断を見ない**
  (構文の診断だけを見る) ので判定には影響しないが、
  「道具パッケージの型は自前の設定で解決されるわけではない」ことを docblock の
  保証しないものへ書く
- program が大きくなるので、`enum-ts-sync.test.ts` (前向き) の `beforeAll` も
  同じ構築費を払う。実測 3 秒なので 300 秒枠に対して問題ない

---

## S4: 候補走査を 4 種へ + 派生の証人つき除外

### 変更箇所

- `tests/js/support/enum-ts-sync/ts-candidates.ts` (全面書き換え)

### 波及変更

- TypeScript 型定義: `TsUnionCandidate` に `shape` と `line` を足す
  → `enum-ts-sync-discovery-extractor.test.ts` の `tsCandidate()` ヘルパを更新
- テストファイル: `enum-ts-sync-discovery.test.ts` / `-extractor.test.ts`

### 変更後の型

```ts
export type TsCandidateShape = "literal-union" | "const-array" | "object-keys" | "switch-cases";

export interface TsUnionCandidate {
    /** リポジトリルートからの相対パス (`.svelte` は仮想ではなく元のパス)。 */
    readonly file: string;
    /** 宣言の名前。分岐のラベルは `switch:<判定対象>`。 */
    readonly name: string;
    readonly shape: TsCandidateShape;
    /** 元ファイル上の行 (1 始まり)。 */
    readonly line: number;
    readonly values: ReadonlySet<string>;
}
```

### 受理する 4 形（正典 i9）

| 形 | 受理条件 | 値集合 |
|---|---|---|
| `literal-union` | 型別名の宣言 (**入れ子も含む**。関数の中の `type X = …` も拾う) で、解決した型が文字列リテラル型だけ | リテラルの値 |
| `const-array` | 変数の宣言で、初期化子が配列リテラル (`as const` の有無を問わない)。要素が**すべて**文字列リテラル、1 件以上 | 要素の値 |
| `object-keys` | 変数の宣言で、初期化子がオブジェクトリテラル。プロパティが**すべて**通常の代入で、キーが文字列リテラル / 識別子 / 型検査器が文字列リテラルへ解決する計算キー。1 件以上 | キーの綴り |
| `switch-cases` | `switch` 文で、`default` を除く**すべての** `case` の式が文字列リテラル型へ解決する。1 件以上 | `case` の値 |

- `ts.TypeFlags.EnumLiteral` を持つ構成要素があれば**受理しない** (現行と同じ)
- 「すべて」を満たさない (1 つでも読めない要素がある) 形は**候補にしない**。
  これは見逃しではなく「値の一覧を書き下した宣言ではない」という判断である。
  この判断は docblock の保証しないものへ書く

### 分岐のラベルの名前 (fail-closed)

```ts
const switchSubjectName = (checker, node, source): string => {
    const type = checker.getTypeAtLocation(node.expression);
    const alias = type.aliasSymbol?.name
        ?? (type.isUnion() ? type.types.map((t) => t.aliasSymbol?.name).find(isDefined) : undefined);
    if (alias !== undefined) return alias;
    const text = node.expression.getText(source).trim();
    if (text !== "") return text;
    throw new EnumTsSyncError(where, "分岐の判定対象の名前を解決できません (名前解決不能)");
};
```

**「名前が取れないときは候補に残して名前対応だけ不成立」にしない**。完全一致しない真の
部分写しが規則 1 にも規則 2 にも掛からず無言で通るため、**解析の失敗として gate を赤くする**
(AGENTS.md §共通規約 (b)「未解決を解決済みと同じ値へ混ぜない」)。

### 派生の除外 (概念設計 決着 2)

`object-keys` 形だけに適用する。**4 条件をすべて満たすときだけ**外す。

1. 明示の型がある — 変数宣言の型注釈、または初期化子が `satisfies` 式
2. 型検査器で解決したその型に**文字列の添字シグネチャが無い**
   (`checker.getIndexInfoOfType(type, ts.IndexKind.String) === undefined`)
3. その型の**プロパティが 1 件以上あり、すべて必須**
   (`(symbol.flags & ts.SymbolFlags.Optional) === 0`)。`Partial<Record<…>>` は過不足を
   落とさないので派生と認めない
4. **証人がある** — 束縛先のキー集合と**同一の値集合**を持つ候補が、
   **`object-keys` 以外の形**の候補 (`literal-union` / `const-array` / `switch-cases`) の中に
   1 件以上ある

証人の資格を「派生除外の対象になり得ない形」に限るのは**循環の遮断**である。
任意の候補を証人にすると、同じキー集合を持つ対応表 A と B が互いを証人にして両方消える
(自己証人・相互証人・3 件の循環)。この形なら判定は**非派生の候補を種にした単調な到達判定**に
なり、一括の相互参照判定にならない。

**実装の順序** (2 パス):

1. 第 1 パス — `object-keys` 以外の 3 形をすべて集める。`object-keys` は
   「派生の条件 1〜3 を満たす候補 (保留)」と「満たさない候補 (確定)」に分ける
2. 証人の索引を第 1 パスの 3 形だけから作る
3. 第 2 パス — 保留のうち証人があるものを捨て、無いものを候補へ戻す

型を解決できない場合 (`checker` が `any` / `unknown` を返す等) は**外さない** (候補に残す)。

### 構文の診断 (fail-closed)

母集団のファイル (仮想 `.svelte` を含む) について `program.getSyntacticDiagnostics(source)` が
1 件でもあれば例外にする。現行と同じ。**除外根の中身がここに来ないこと**は S8 の gate が別途固定する。

### PHPStan適合チェック

- [x] PHP の変更なし
- [x] 戻り値の型が明示されている
- [x] `readonly` と `ReadonlySet` を維持

### テスト計画

- [ ] **先に赤くする**: 見本 `mixed.ts` に定数配列・対応表・分岐を足し、
      4 形すべてが拾えることを主張 → 現行は型別名しか拾わないので赤
- [ ] 各形の正例と負例 (要素に非リテラルが混ざる / 数値リテラル / TS の `enum` / 0 件)
- [ ] 入れ子の型別名 (関数の中) が拾えること (現行はトップレベルのみ)
- [ ] `.svelte` の中の 4 形が拾えること (S2 の見本を使う)
- [ ] 派生の除外: `Record<Alias, string>` は外れ、`Record<string, string>` は残る
- [ ] 派生の負例セット: **型別名越しの `Record` / `Partial<Record<…>>` / union /
      intersection / `keyof` / 取り込んだ型 / `satisfies`** をそれぞれ見本に置く
- [ ] **証人の負例 3 種**: 自己証人 / 2 件の相互証人 / 3 件の循環証人 —
      いずれも「外れずに候補として残る」ことを固定
- [ ] **故障注入 2**: 派生の判定を常に真にすると、証人の無い見本が候補から消えて赤くなる
- [ ] **故障注入 7**: 証人の資格を「任意の候補」へ緩めると、相互証人の見本が消えて赤くなる
- [ ] **故障注入 8**: 名前解決不能の分岐を静かに落とすと、
      「名前解決不能で例外になる」テストが緑にならず赤くなる

### リスク

- `object-keys` の候補が 163 件と多い (現物の実測)。判定式が名前と値を見るので
  実際に鳴るのは 2 件だけだが、**将来 PHP の列挙が増えると鳴る組が増える**。
  これは過剰検出の向きであり、申告 1 行で吸収できる (i11)

---

## S5: 規則 2 の論理和

### 変更箇所

- `tests/js/support/enum-ts-sync/reverse-sweep.ts` (L44-99)

### 現行コード

```ts
const nameCorrespondence = (candidateName: string, enumName: string): string | null => { …一致 / +s / +es / +values… };
// 規則 2 = nameCorrespondence !== null && intersects(...)
```

### 変更後の型

```ts
/** 適用した規則。申告の同一性に含める (規則が変わったら申告は stale になる)。 */
export type ReverseSweepRule = "1" | "2a" | "2b";

export interface UnregisteredMirrorCandidate {
    readonly rule: ReverseSweepRule;
    readonly php: ResolvedPhpEnum;
    readonly candidate: TsUnionCandidate;
    /** 鳴った理由 (どの規則・どの語・どの値の交差で鳴ったか)。規則 1 は `null`。 */
    readonly reason: string | null;
    /** PHP にだけある値 / TS にだけある値 (メッセージ用。双方向の差分)。 */
    readonly onlyInPhp: readonly string[];
    readonly onlyInTs: readonly string[];
}
```

### 判定の順序 (排他)

1. **規則 1**: 値集合が完全一致 → `rule = "1"`
2. 交差が 1 件以上あり、**2a の名前対応**が成立 → `rule = "2a"`
3. **2b の名前対応**が成立し、**2b の交差条件**を満たす → `rule = "2b"`
4. どれでもなければ鳴らさない

### 2a: 厳密な名前対応 + 1 値以上の交差 (現行を維持)

- 小文字化して比較。**英数字以外は除去しない**
- 一致 / `+s` / `+es` / `+values`
- 分岐のラベルは `switch:` の接頭辞を外してから比べる

### 2b: 語に分けた名前対応 + 両側から見て半分以上の交差 (新設)

**区切りの宣言** (AGENTS.md §共通規約 (e)):

- 語に割る文字は `_` `-` `.` `$` と空白類
- 加えて**大文字の境界**で割る — 「小文字または数字 → 大文字」と「大文字の連なり → 大文字 + 小文字」
- 加えて**数字の境界**で割る (英字 ↔ 数字)
- 割った後、空の要素を捨て、すべて小文字化する

**正規化 (単数化)**: 末尾 `ies` (長さ > 3) → `y` / 末尾 `es` (長さ > 2 かつ `ses` で終わらない) → 落とす /
末尾 `s` (`ss` で終わらない、長さ > 1) → 落とす。**これ以上の語形変化は扱わない** (docblock に書く)。

**語袋**:

- 候補側 = **宣言名の語** ∪ **ファイル名の語** (拡張子を除いた basename を同じ規則で割ったもの)
- PHP 側 = **列挙名の語** (ファイル名は列挙名と同じなので足さない)

**主要語**: 語列の**末尾の語**と定義する (英語の複合名詞の主要部)。
候補側の主要語は**宣言名**の語列の末尾を使う (ファイル名の語は主要語に使わない)。

**名前対応 (2b)**: 候補の主要語 == 列挙の主要語 **かつ**
`|候補の語袋 ∩ 列挙の語列| >= min(2, |列挙の語列|)`。

**交差条件 (2b)**: `|A ∩ B| >= ceil(|A| / 2)` **かつ** `|A ∩ B| >= ceil(|B| / 2)`。
A / B は値集合 (集合として扱うので重複は無い)。**どちらかが空なら鳴らさない**。

**実測での挙動**: `JobStatus` ⇔ `DashboardJobStatus` は
語 `[job, statu]` を共有し主要語 `statu` が一致、交差 2 値に対し PHP 4 値 / TS 2 値で
`2 >= ceil(4/2)` かつ `2 >= ceil(2/2)` を満たして鳴る (誤検出 1 件。申告で逃がす)。

### 診断文字列

規則 2a: `厳密名対応 (apierrorcode = apierrorcode) / 交差 8 値`
規則 2b: `語対応 [job+statu] 主要語=statu / 交差 2 値 (PHP 4 値・TS 2 値の半分以上)`

### PHPStan適合チェック

- [x] PHP の変更なし

### テスト計画

- [ ] **先に赤くする**: 「語対応 + 両側半分以上」だけが拾う組 (2a では鳴らない) を
      主張するテスト → 現行は鳴らないので赤
- [ ] 既存の E1〜E11 をすべて残し (**削除・上書き禁止**)、`rule` の値を `"1"` / `"2a"` へ直す
- [ ] 2b の正例: 主要語一致 + 2 語一致 + 両側半分以上
- [ ] 2b の負例 (共通規約 (e) の 3 形): **接頭辞つき** (`DraftJobStatus` の主要語は一致するが
      語の一致数が足りない例) / **打ち消しつき** (`NonJobStatus`) / **接尾辞つき**
      (`JobStatusKind` — 主要語が `kind` になるので不成立)
- [ ] 2b の負例: 主要語が一致しても交差が片側半分未満 / 交差が 0 / 値集合が空
- [ ] 2a と 2b の両方に該当し得る組で、**2a が勝つ** (排他) こと
- [ ] **故障注入 5**: 論理和から 2b を落とすと 2b 専用の正例が消えて赤くなる。
      2a を落とすと 2a 専用の正例が消えて赤くなる
- [ ] `onlyInPhp` / `onlyInTs` が双方向の差分になっていること

---

## S6: 目録の受理範囲拡大

### 変更箇所

- `tests/js/support/enum-ts-sync/mirror-inventory.ts` (L16-25 の型 / L223-260 の `validateMirrors`)

### 現行コード

```ts
const jsRoot = path.join(root, "resources", "js");
…
if (!row.ts.endsWith(".ts")) throw new EnumTsSyncError(where, `ts は .ts で終わること: ${row.ts}`);
…
if (!isUnder(tsAbs, jsRoot)) throw new EnumTsSyncError(where, `ts は resources/js/ 配下だけ: ${row.ts}`);
```

### 変更後コード（骨子）

```ts
/**
 * 登録できる TS の置き場。
 * - `resources/js/` … 画面側
 * - `packages/*/src/` … 付属のコマンドライン道具 (本 feature の境界は画面側に限らない)
 * `tests/js/` は登録の置き場ではない (検査の見本を写しとして登録しない)。
 */
const tsRootsOf = (root: string): readonly string[] => [
    path.join(root, "resources", "js"),
    ...listPackageSrcRoots(root), // packages/*/src の実在するものだけ
];

const TS_EXTENSIONS = [".ts", ".svelte"] as const;
…
if (!TS_EXTENSIONS.some((e) => row.ts.endsWith(e))) {
    throw new EnumTsSyncError(where, `ts は .ts か .svelte で終わること: ${row.ts}`);
}
…
if (!tsRootsOf(root).some((r) => isUnder(tsAbs, r))) {
    throw new EnumTsSyncError(
        where,
        `ts は resources/js/ 配下か packages/*/src/ 配下だけです: ${row.ts}`,
    );
}
```

- symlink の脱出検査 (`realpathSync` が走査根の中を指すこと) は**根の集合に対して**行う
  (現行と同じ厳しさを保つ)
- `.svelte` を受理しても aicue に登録対象は**現時点で 0 件**である。
  正典 i6 が「`.svelte` の中の写しも登録の対象になる」と定めるため経路を用意する。
  見本で正例・負例を固定する

### 波及変更

- テストファイル: `enum-ts-sync.test.ts` の負の対照
  「`resources/js/` の外の ts は拒否する」の**期待文字列が変わる** → **S10 と S12 が発火する**

### テスト計画

- [ ] **先に赤くする**: `packages/cli/src/api/schemas.ts` を登録した行が
      `validateMirrors` を通ることを主張 → 現行は「resources/js/ 配下だけ」で落ちるので赤
- [ ] `.svelte` の登録行が通ること (見本の木で)
- [ ] `tests/js/setup.ts` は**引き続き拒否**されること (期待文字列は新しい文面に合わせる)
- [ ] `packages/cli/tests/…` (src の外) は拒否されること
- [ ] 既存の負の対照 (絶対パス / 逆斜線 / `..` / symlink の脱出 / 二重登録 / note 空) は
      **すべて残す** (削除・上書き禁止)

---

## S7: 前向きの検査の `.svelte` 対応

### 変更箇所

- `tests/js/support/enum-ts-sync/ts-value-sets.ts` (L25 の `getSourceFile` 解決)

### 変更後コード（骨子）

```ts
const sourcesOf = (program: MirrorProgram, tsFile: string): readonly ts.SourceFile[] => {
    if (!tsFile.endsWith(".svelte")) {
        const one = program.program.getSourceFile(path.join(REPO_ROOT, tsFile));
        return one === undefined ? [] : [one];
    }
    // `.svelte` は文脈ごとの仮想単位を両方見る (module と実体で名前が衝突しないのは
    // svelte の側の制約であり、2 つ見つかったら「同名の型別名が 2 件」で落ちる)。
    return [...program.virtualPaths.entries()]
        .filter(([, real]) => real === tsFile)
        .map(([virtual]) => program.program.getSourceFile(virtual))
        .filter(isDefined);
};
```

- 「型別名がちょうど 1 つ」の検査は**仮想単位をまたいだ合計**で行う (2 つあれば落ちる)
- 失敗メッセージの `where` は**元の `.svelte` のパス**を出す (仮想パスを利用者に見せない)

### テスト計画

- [ ] 見本 `.svelte` の中の型別名が読めること (正例)
- [ ] module と実体に同名の型別名を置いた見本で「同名の型別名が 2 件」で落ちること
- [ ] `.svelte` に型別名が無い登録は「型別名の宣言が見つかりません」で落ちること
- [ ] 既存の受理・拒否 (T01〜T25 の見本) は**すべて残す**

---

## S8: 逆走査 gate の再整備

### 変更箇所

- `tests/js/architecture/enum-ts-sync-discovery.test.ts` (docblock / `REVERSE_SWEEP_EXEMPTIONS` /
  逆走査の describe / `beforeAll`)

### docblock の書き換え (i15)

現行の次の宣言は**事実でなくなる**ので書き換える:

- 「`collectTsUnionCandidates()` が `resources/js/` 配下の…型別名を全数走査し」
- 「`.svelte` の中の宣言・定数配列・switch の case ラベルは走査しない」
- 「名前対応は『一致 / +s / +es / +values』の厳密な形だけを見る」

新しい**保証しないもの**として書くこと:

- 版管理外のファイルは見ない
- 目印の中の式・制御構文の中・スタイルの中は見ない (`.svelte`)
- 「すべての要素が読める」形だけを候補にする (1 つでも読めない要素があれば候補にしない)
- 派生として外した対応表は、束縛先の型が候補として立つことに依存する
  (**証人が無ければ外さない**)
- 分岐のラベルは**登録できない** (前向きの検査は型別名だけを読む)。
  写しとして扱うなら型別名へ切り出す
- 道具パッケージは**自前の tsconfig ではなく**ルートの設定で解決される
- 除外根 (`fixtures/candidates-broken`) の中は見ない。
  `fixtures/` の残りは**見る** (見本を書き換えると本番の候補集合も動く)

### 除外根の検査 (新設。決着 1 の条件 2/3)

```ts
describe("逆走査の母集団 (版管理下の全数・唯一の除外)", () => {
    it("除外根の件数が pin と一致する", …);
    it("除外根の体裁 (配下・実在・重複無し・理由 30 文字以上) が守られている", …);
    it("除外根の配下は 0 件でなく、全ファイルが実際に構文診断で落ちる", () => {
        // ここが「除外根へ正常なファイルを置いて母集団から静かに消す」経路を塞ぐ。
        const files = listExcludedFiles();
        expect(files.length).toBeGreaterThan(0);
        for (const file of files) {
            const source = fixtureProgramFor(file);
            expect(program.getSyntacticDiagnostics(source).length).toBeGreaterThan(0);
        }
    });
    it("母集団が空でない (.ts と .svelte のどちらも)", …);
});
```

### 申告 (`REVERSE_SWEEP_EXEMPTIONS`) の再整備

現行 1 件 → **6 件**にする。`rule` は `"1" | "2a" | "2b"`。

| php | file | declaration | rule | 理由の要点 |
|---|---|---|---|---|
| `app/Enums/Manual/TakeStatus.php` | `resources/js/types/manual.ts` | `SelectableTakeStatus` | `"1"` | 既存。部分集合の意図 |
| `app/Enums/Manual/CutType.php` | `resources/js/components/features/manual/ScenarioEditor.svelte` | `DragOwner` | `"1"` | ドラッグの所有者という**別概念**で、値がたまたま一致する。統合しない (思考原則 4) |
| `app/Enums/Notification/NotificationType.php` | `resources/js/components/features/notifications/NotificationListItem.svelte` | `switch:notification.type` | `"1"` | 絵柄を選ぶ分岐。**値が増えると既定の枝 (ベルの絵柄) に落ちる**。利用者影響は「新種の通知が汎用の絵柄で出る」ことで、操作は詰まらない。期待動作は「新種を足すときに絵柄も足す」。**値が増えた時点で規則 1 が外れ規則 2a へ移るので、この申告は自動で stale になり赤くなる** |
| `app/Enums/ApiKeyAbility.php` | `resources/js/pages/Organizations/ApiKeys/Index.svelte` | `ABILITY_LABELS` | `"1"` | 表示ラベル表。未知の値は素の文字列で表示する退避 (`?? ability`) があるので値の取りこぼしが画面を壊さない |
| `app/Enums/OAuth/OAuthClientKind.php` | `resources/js/pages/Organizations/ApiKeys/Sessions.svelte` | `CLIENT_KIND_LABELS` | `"1"` | 同上 (`?? kind`) |
| `app/Enums/EnterpriseSso/OidcConnectionStatus.php` | `tests/js/components/features/sso/oidc-connection.test.ts` | `ALL_STATUSES` | `"1"` | 検査が並べた全値。写しではなく検査の入力である |
| `app/Enums/Manual/JobStatus.php` | `resources/js/types/dashboard.ts` | `DashboardJobStatus` | `"2b"` | 進行中だけを表す**意図した真部分集合**。終端の状態はダッシュボードに出ない |

→ `EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT = 7`。

- 申告の体裁検査は現行のまま (実在・重複無し・理由 30 文字以上・件数 pin)
- **stale 判定は免除を適用する前の状態**で行う (現行のまま)

### PHP 側の分類理由の訂正 (決着 5)

`PHP_ENUM_EXEMPTIONS` の 2 行の `reason` を事実に合わせる (**分類は「対象外」のまま**、
件数 pin 95 は変わらない):

- `app/Enums/ApiKeyAbility.php` → 「API キー権限 (read/write)。画面はチェックボックスの
  選択状態で操作し、表示ラベル表は未知の値を素の文字列へ退避するため値域の写しを要さない」
- `app/Enums/OAuth/OAuthClientKind.php` → 「OAuth クライアント種別。認可判定の内部語彙で、
  画面の表示ラベル表は未知の値を素の文字列へ退避するため値域の写しを要さない」

### 失敗メッセージ (i13)

```
未登録のミラー候補が見つかりました。正本は PHP 側です。
規則2a app/Enums/ApiErrorCode.php:12 (ApiErrorCode)
     ⇔ packages/cli/src/api/schemas.ts:310::ApiErrorCode (literal-union)
     厳密名対応 (apierrorcode = apierrorcode) / 交差 8 値
     PHP にだけある値: actor_not_resolvable, idempotency_in_progress, …
     TS にだけある値: quota_exceeded, rate_limit_exceeded, …
     直し方: 写しなら ENUM_TS_MIRRORS へ 1 行足して EXPECTED_MIRROR_COUNT を 1 増やす。
             写しでないなら REVERSE_SWEEP_EXEMPTIONS へ理由 30 文字以上で登録し
             EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT を直す。
```

- PHP 側の行を出すために `ResolvedPhpEnum` に `line` を足す
  (`detectEnumHeaders` の `offset` から改行を数える。無害化した写しは長さが元と同じなので
  そのまま使える)

### テスト計画

- [ ] **先に赤くする**: 新しい母集団・4 形・論理和で走らせ、
      申告が現行 1 件のままだと**未登録候補が 7 件出て赤くなる**ことを確認する
      (これが実装の出発点の赤)
- [ ] 申告を 7 件へ整備すると緑になること
- [ ] 申告の stale 検査: 申告 1 件の `rule` をわざと変えると赤くなる
      (**規則 1 → 規則 2a の遷移で赤くなる**負例そのもの)
- [ ] **故障注入 6**: 生死判定を「免除適用後」に変えると、
      自分自身を根拠にする申告の見本が通ってしまい負の対照が赤くなる
- [ ] メッセージに PHP 側の行と TS 側の行が両方出ること (文字列の照合)

---

## S9: 検出器の自己検査 (負例と故障注入)

### 変更箇所

- `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts`
- 見本の追加:
  - `tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts` (4 形へ拡張)
  - `tests/js/support/enum-ts-sync/fixtures/candidates/derived.ts` (派生の 7 パターン)
  - `tests/js/support/enum-ts-sync/fixtures/candidates/witness-cycle.ts` (自己 / 相互 / 3 件循環)
  - `tests/js/support/enum-ts-sync/fixtures/svelte/Sample.svelte` (module + 実体)
  - `tests/js/support/enum-ts-sync/fixtures/svelte/DuplicateContext.svelte` (同じ文脈 2 つ)
  - `tests/js/support/enum-ts-sync/fixtures/svelte/Broken.svelte` (構文破壊)

**見本の置き場の注意**: `fixtures/` は**母集団に入る** (除外は `candidates-broken/` だけ)。
そのため見本の値は `"a"` `"b"` のような**現物の列挙と交差しない綴り**にする
(交差する綴りを置くと本番の gate が鳴る)。この約束を見本のディレクトリの
`README` 相当の docblock へ書く。

**`.svelte` の見本は `fixtures/svelte/` に置く**。`resources/js` の外なので
`pnpm lint` / `svelte-no-undef-gate` の対象にならない (これらは `resources/js` を見る)。

### 既存テストの扱い (禁止事項 3)

- D1〜D18 (PHP 側の分類) は**そのまま残す**
- E1〜E11 (突き合わせ純関数) は**残す**。`rule` の値だけ `1 → "1"` / `2 → "2a"` へ直す
- 「走査根の配下でないファイルは対象にしない」は**母集団の考え方が変わる**ので、
  「除外根の配下は対象にしない」へ**置き換える** (削除ではなく意味の更新)。
  置き換えたことを対応マトリクスと commit メッセージに残す
- 「走査した非宣言ファイルの集合は、ファイルシステムを直接歩いた集合と一致する」は
  **走査根を母集団全体へ広げて残す** (独立実装の突合という性質を維持する)

### 故障注入の一覧 (i12。8 件)

| # | 注入 | 赤くなる検査 |
|---|---|---|
| 1 | 除外根を空にする / 広げる | 除外根の件数 pin / 「配下が全件構文で落ちる」検査 |
| 2 | 派生の判定を常に真にする | 証人の無い見本が候補から消える |
| 3 | 母集団の列挙を空にする | 「母集団が空でない」検査 |
| 4 | `.svelte` の仮想化を無効にする / 2 文脈を 1 本へ連結する | `.svelte` の候補が消える / 偽の重複宣言が出る |
| 5 | 規則 2 の論理和から 2a または 2b を落とす | その式だけが拾う正例が消える |
| 6 | 申告の生死判定を「免除適用後」に変える | 自己根拠の申告の見本が通る |
| 7 | 証人の資格を「任意の候補」へ緩める | 相互証人・循環証人の見本が消える |
| 8 | 名前解決不能の分岐を静かに落とす | 「名前解決不能で例外」の検査が緑にならない |

**故障注入の実現方法**: 走査器の引数 (`root` / 除外根 / 判定の述語) を**テストから差し替え可能**に
してあるものはそれで行い、そうでないものは**見本を壊す**形で行う。
どちらも `devnotes` のスクリプトではなく**テストの中**に置く (継続的に赤くなる形にする)。

---

## S10: 前向き gate の負の対照を新しい受理範囲へ

### 変更箇所

- `tests/js/architecture/enum-ts-sync.test.ts` (L86-88 の負の対照 1 件)

### 現行コード

```ts
it("resources/js/ の外の ts は拒否する", () => {
    expect(() => validateMirrors([{ ...valid, ts: "tests/js/setup.ts" }])).toThrow("resources/js/ 配下だけ");
});
```

### 変更後コード

```ts
it("登録できる置き場の外の ts は拒否する", () => {
    expect(() => validateMirrors([{ ...valid, ts: "tests/js/setup.ts" }])).toThrow(
        "resources/js/ 配下か packages/*/src/ 配下だけ",
    );
});

it("道具パッケージでも src の外は拒否する", () => {
    expect(() => validateMirrors([{ ...valid, ts: "packages/cli/vitest.config.ts" }])).toThrow(
        "resources/js/ 配下か packages/*/src/ 配下だけ",
    );
});
```

- **これ以外は 1 行も変えない**。診断文の正しさが先で、台帳の手当ては帰結である (決着 3)
- この変更が `docs/template-fingerprints.json` のキーに触るため **S12 が発火する**

---

## S11: 実ドリフトの是正 (CLI の符号一覧)

### 変更箇所

- `packages/cli/src/api/schemas.ts` (L283-310)
- `packages/cli/src/api/client.ts` (L213-235 の `dispatchKindFromCode`)
- `packages/cli/tests/` に検査を足す

### 現行コード

```ts
export const API_ERROR_CODES = [
    "unauthenticated", "forbidden", "not_found", "validation_failed",
    "rate_limit_exceeded", "quota_exceeded", "idempotency_conflict", "internal_server_error",
    // Controller-local codes …
    "payload_sanitization_failed", "site_not_cli_capture", "use_audits_submit",
] as const;
export type ApiErrorCode = (typeof API_ERROR_CODES)[number];
```

サーバ側 `app/Enums/ApiErrorCode.php` の 11 値:
`unauthenticated` / `forbidden` / `insufficient_ability` / `actor_not_resolvable` /
`not_found` / `validation_failed` / `rate_limited` / `idempotency_conflict` /
`idempotency_in_progress` / `idempotency_indeterminate` / `internal_server_error`

**食い違い**: 道具側は `rate_limit_exceeded` を持つがサーバは `rate_limited`。
サーバの 4 値 (`insufficient_ability` / `actor_not_resolvable` / `idempotency_in_progress` /
`idempotency_indeterminate`) が道具側に無い。

### 変更後コード

```ts
/**
 * サーバの `App\Enums\ApiErrorCode` の写し (値集合の一致は
 * `tests/js/architecture/enum-ts-sync.test.ts` が機械で固定する)。
 * **ここに道具固有の符号を混ぜない** — 混ぜると同期の検査が成立しなくなる。
 */
export type CanonicalApiErrorCode =
    | "unauthenticated" | "forbidden" | "insufficient_ability" | "actor_not_resolvable"
    | "not_found" | "validation_failed" | "rate_limited" | "idempotency_conflict"
    | "idempotency_in_progress" | "idempotency_indeterminate" | "internal_server_error";

/**
 * サーバの列挙には無く、道具が独自に扱う符号。
 * (課金・入力の無害化・撮影面の判定など、封筒の形だけを共有する応答が返す。)
 */
export type CliLocalErrorCode =
    | "quota_exceeded" | "payload_sanitization_failed"
    | "site_not_cli_capture" | "use_audits_submit";

/** 道具が受け取り得る符号の全体。未知の符号は拒否せず状態番号へ退避する (既存の契約)。 */
export type ApiErrorCode = CanonicalApiErrorCode | CliLocalErrorCode;
```

- `API_ERROR_CODES` は**削除する** (実行時の参照は 0 件。`schemas.ts` 自身の型導出だけ。
  後方互換の並走を残さない)
- `client.ts` の `case "rate_limit_exceeded":` を **`case "rate_limited":`** へ差し替える
  (サーバが実際に返す綴りに合わせる。`rate_limit_exceeded` は残さない)
- `client.ts` の docblock の符号の並びも新しい分類へ直す

### 波及変更

- TypeScript 型定義: `ApiErrorCode` は**広がる**だけ (`dispatchKindFromCode` の `switch` は
  `default` を持つので網羅の破れは起きない)
- API Resource/DTO: なし (PHP 側は変えない)
- テストファイル: `packages/cli/tests/` に 3 系統の検査を足す

### 目録への登録

`ENUM_TS_MIRRORS` へ 1 行足し、`EXPECTED_MIRROR_COUNT` を 29 → **30** にする。

```ts
{
    php: "app/Enums/ApiErrorCode.php",
    ts: "packages/cli/src/api/schemas.ts",
    declaration: "CanonicalApiErrorCode",
    note: "付属のコマンドライン道具が応答の符号で失敗の種類を分ける (rate-limit / conflict / auth)",
},
```

そのうえで `ApiErrorCode` (合併型) は**規則 2a で鳴り続ける**ので申告する:

| php | file | declaration | rule | 理由の要点 |
|---|---|---|---|---|
| `app/Enums/ApiErrorCode.php` | `packages/cli/src/api/schemas.ts` | `ApiErrorCode` | `"2a"` | サーバの符号と道具固有の符号の**合併**。サーバ側の写しは `CanonicalApiErrorCode` として登録済みで、合併型は写しではない |

→ `EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT` は S8 の 7 件 + この 1 件 = **8**。

`CliLocalErrorCode` はサーバの列挙と 1 値も交差しないので鳴らない
(`quota_exceeded` はサーバの列挙に無い。`QuotaExceededResource` は列挙を経由しない)。

### テスト計画

- [ ] **先に赤くする**: `ENUM_TS_MIRRORS` に `CanonicalApiErrorCode` の行を足すと
      `enum-ts-sync.test.ts` の値集合一致が**落ちる** (道具側が古いため) → これが出発点の赤
- [ ] 道具側を直すと緑になること
- [ ] `packages/cli/tests/` に 3 系統:
      - サーバ固有の符号 (`rate_limited`) → 失敗の種類が `rate-limit` になる
      - 道具固有の符号 (`quota_exceeded`) → `quota` になる
      - **未知の符号** (`something_new`) → 符号では分類されず、HTTP の状態番号へ退避する
- [ ] `pnpm typecheck:packages` / `pnpm test:packages` / `pnpm build:packages` が緑

### リスク

- `rate_limit_exceeded` を落とすことで、**古いサーバ**がその綴りを返す環境では
  符号による分類が効かなくなる。ただし 429 の状態番号への退避が残るので
  失敗の種類は同じ `rate-limit` になる (退避路は既存の契約であり、本変更で壊さない)。
  この判断を `client.ts` の docblock に残す

---

## S12: 乖離台帳の手当て

### 変更箇所

- `docs/template-divergence.md` (**D50 を新設**)
- `tests/Support/TemplateDivergence/adoption-debt.tsv` (L141 の 1 行を削除)
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
- **理由**: 正典 v3 の i4 / i5 (走査器の共有と型情報での抽出)。
  構文木だけでは別名参照・添字アクセス・閉じたテンプレート文字列を読めず、
  その写しを登録できないため実装側に書き方の変更を強いる
- **揃え続ける不変条件**: 目録 (`ENUM_TS_MIRRORS`) が前向きの検査と逆走査の**単一の出典**であること /
  値集合の抽出器を 2 本持たないこと / 受理範囲の外は空集合でなく例外にすること
- **対象パス**: `tests/js/architecture/enum-ts-sync.test.ts`
- 書式 (登録メタ表の 9 行・状態の値域・対象パスの実在と重複) は
  `TemplateDivergenceLedgerFormatTest` が機械で強制する。**書式の正本は同ファイルの規約節**

### `tsconfig.json` は変えない

`packages/cli` は program の起点に足すことで型世界へ入れる (S3)。
`include` を広げると `pnpm typecheck` の対象まで動き、債務 pin にも触れる。

### テスト計画

- [ ] **先に赤くする**: S10 の変更を入れた時点で `TemplateDivergenceFingerprintTest` が
      `mutatedDebtPaths` で赤くなることを確認する (これが手当ての出発点)
- [ ] D50 を書き債務の行を削り pin を直すと緑になること
- [ ] `TemplateDivergenceLedgerFormatTest` (件数の 3 点一致) が緑
- [ ] `composer test` 全体が緑

---

## S13: 文書の更新

### 変更箇所

- `AGENTS.md` ドメイン固有規約 **19** (L994-1013)
- `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期

### 変更内容

`AGENTS.md` 19 の次の 2 点を書き換える:

- 「受理する形は**型別名の宣言**で…」→ **前向きの登録**が受理する形は型別名の宣言
  (ここは変わらない) と明記したうえで、**逆走査**が拾う形は 4 種であることを足す
- 「**TS 側も全数走査で逆走査する**」の走査範囲を、
  「版管理下の `*.ts` と `*.svelte` の全数 (検出器自身の構文破壊見本を除く)」へ直す
- 登録できる TS の置き場が `resources/js/` と `packages/*/src/` であることを足す

**正典 v3 の条文を転載しない**。書くのは aicue 固有の受理範囲・除外集合・登録の手順だけで、
正典は版 (家系の機能台帳 `enum-ts-sync-gate` の v3) で指す。
**保証しないものの正本は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期** であり、
`AGENTS.md` には写さない (現行の書き分けを維持)。

`docs/architecture.md` 側は「保証しないもの」の正本なので、S8 の docblock に書いた
保証範囲と**同じ内容**を散文で置く (docblock と食い違わせない)。

### テスト計画

- [ ] `docs/architecture.md` の節が実在し、`AGENTS.md` から参照されていること (人手)
- [ ] `pnpm test` / `composer test` が緑

---

## 実装の順序 (テストファースト)

| 段 | 先に赤くするもの | 緑にする実装 |
|---|---|---|
| 1 | `population.ts` の単体テスト (モジュールが無い) | S1 |
| 2 | `svelte-source.ts` の単体テスト (行・列の一致 / スコープ分離) | S2 |
| 3 | `createMirrorProgram([])` に `packages/cli` が載る主張 | S3 |
| 4 | 4 形と派生の証人つき除外の単体テスト | S4 |
| 5 | 2b 専用の正例 / (e) の 3 形の負例 | S5 |
| 6 | 逆走査 gate が**未登録候補 7 件**で赤くなる | S8 の申告整備 |
| 7 | `packages/*/src/` の登録が通る主張 | S6 |
| 8 | `.svelte` の型別名が読める主張 | S7 |
| 9 | `CanonicalApiErrorCode` を登録すると前向きの検査が落ちる | S11 |
| 10 | `enum-ts-sync.test.ts` の負の対照が新しい文面で落ちる | S10 |
| 11 | `TemplateDivergenceFingerprintTest` が `mutatedDebtPaths` で赤くなる | S12 |
| 12 | 故障注入 8 件 | S9 |
| 13 | — | S13 (文書) |

**段 6 が本作業の中心の赤**である。ここで出る 7 件が概念設計の実測と一致すること
(#1 は段 9 で扱うので段 6 では `ApiErrorCode` の合併型として 1 件出る) を確認する。

## 後方互換・migration の扱い

- **DB の migration は無い** (本作業は検査と道具の型だけを変える)
- **後方互換の並走を残さない** (AGENTS.md 思考原則 3):
  - `API_ERROR_CODES` の定数は**削除**する (新旧を並べない)
  - `rate_limit_exceeded` の分岐は**差し替える** (両方を受ける形にしない)
  - `collectTsUnionCandidates` の `jsRoot` 引数 (走査根を差し替える負のコントロール専用) は
    **廃止**し、除外根の差し替えに置き換える (2 つの縮め方を残さない)
  - `reverse-sweep.ts` の `rule: 1 | 2` は `"1" | "2a" | "2b"` へ**置き換える**
    (数値と文字列を併存させない)

## docs/template-divergence.md の登録/更新/削除の要否

| 対象 | 指紋台帳のキーか | 採用時債務か | 判断 |
|---|---|---|---|
| `tests/js/architecture/enum-ts-sync.test.ts` | **在る** | **在る** | S10 で変更するので **D50 を新設し債務から削る** (S12) |
| `tsconfig.json` | 在る | 在る | **変更しない** (S3 で起点に足す方式を採る) |
| `tests/js/support/enum-ts-sync/*.ts` | 無い | 無い | 登録の義務なし (aicue 固有の上積み) |
| `tests/js/architecture/enum-ts-sync-discovery*.test.ts` | 無い | 無い | 同上 |
| `packages/cli/**` | 無い | 無い | 同上 |
| `AGENTS.md` / `docs/architecture.md` | 無い | 無い | 同上 |

削除する登録は無い。

## 検証コマンド (全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 走査器・gate・目録・道具の型・乖離台帳を**同じ変更**で揃える必要がある (段 6 の赤は S1〜S5 が揃うまで解けず、S10 の変更は S12 の手当てと不可分)。段階的に main へ入れると gate が赤いまま並走する期間ができる |
| 競合リスク | `docs/template-divergence.md` と `LedgerPins.php` は他の TODO も触る。件数 pin が衝突しやすいので、マージ直前に再計算する。`AGENTS.md` も同様 |

## 概念設計 (参考。Codex 別セッションで APPROVED 済み)

# 概念設計: enum-ts-sync-gate v3 追従

## 背景・課題

家系の機能台帳 `enum-ts-sync-gate` は 2026-08-22 に正典 **v3** (不変条件 i1〜i16) を確定した
(`design_settled` / `doc_sha aeced2ccfd07`)。aicue のセルは `status=update_pending` /
`version=v2` / `target_version=v3` で、追従が要る。**採否の判断は済んでいる**ので、
本設計は「何を採るか」ではなく「aicue の現物にどう着地させるか」を決める。

aicue が既に満たしている不変条件 (実コードで確認):

| 不変条件 | 現物 |
|---|---|
| i2 発見の段の全数走査 | `php-enum-catalog.ts` が `git ls-files app/**/*.php` を全数走査 (resolved 123 / unresolvable 3) |
| i3 既定拒否の 3 分類 + 件数固定 | `PHP_ENUM_EXEMPTIONS` (95) / `KNOWN_UNRESOLVABLE_PHP_ENUMS` (3) / `ENUM_TS_MIRRORS` (29) |
| i4 発見と抽出の走査器共有 | `detectEnumHeaders` を `classifyPhpFile` と `readPhpEnumValuesFromText` が共用 |
| i5 (program 側) | `createMirrorProgram` が tsconfig 全体で program を組み、起点を縮めない |
| i7 登録済みも逆走査の母集団に残す | `findUnregisteredMirrorCandidates` は PHP 側 `resolved` 全件を渡される |
| i11 (片側) | `REVERSE_SWEEP_EXEMPTIONS` に理由 30 文字・実在・重複無し・件数 pin・stale 判定 |
| i14 / i16 | 0 件・読めないは例外。program 構築は `beforeAll` (300 秒) |

**足りないのは逆走査の狭さだけ**である (台帳の aicue セルの note と一致する):

| 欠け | 現物 |
|---|---|
| i8 母集団 | `collectTsUnionCandidates` は `resources/js` 配下の `.ts` だけ。版管理下の `.ts` 379 本のうち **348 本が母集団外** (`packages/cli` 81 本・`tests/js` 226 本ほか)。`.svelte` **130 本は 1 本も見ていない** |
| i6 `.svelte` | 走査対象外だと docblock が自認している |
| i9 候補の形 | トップレベルの型別名 1 種のみ (定数配列・対応表のキー・分岐のラベルは対象外) |
| i10 規則 2 | 「厳密名対応 + 1 値交差」の片側だけ。語分割名対応 + 両側半分以上の交差を持たない |
| i5 (起点) | tsconfig の `include` 外にある `packages/cli` が program に載っていない |
| i13 失敗メッセージ | PHP 側の位置 (行) を出さない。TS 側も行を出さない |
| i15 保証範囲の宣言 | 「`.svelte`・定数配列・case は走査しない」と宣言したままになる |

## 仮説と検証

**仮説**: 逆走査を正典 v3 の広さへ広げると、現物ツリーに**実在するが未検出のドリフト**が出る。
そのとき鳴る誤検出は、申告 1 行で吸収できる規模 (10 件未満) に収まる。

**検証**: 設計段階で判定式そのものを現物ツリーへ走らせて数えた
(`probe/probe.ts` / 実測は `probe/measurements.md`。未決論点 **q2** の解消経路そのもの)。

実測 (2026-08-24):

- 母集団: `.ts` **378 本** (構文を壊した見本 1 本だけ除外) + `.svelte` **130 本** = 508 本。
  program 構築 **約 3 秒** (実測 2.9〜3.9 秒の揺れ) / source files 5,859
  (i16 の前処理枠 300 秒に対して十分小さい)
- 候補 **304 件** (型の合併 106 / 対応表のキー 163 / 定数配列 22 / 分岐のラベル 13。
  合計が総数と一致することを probe 内で assert している)。
  対応表のキー形のうち派生として外したのは**証人のある 10 件だけ**で、
  証人の無い 53 件は候補として残している
- 鳴った組 **8 件** (規則 1 = 6 / 規則 2a = 1 / 規則 2b = 1)
- **誤検出率**: 鳴った 8 件のうち、真の未登録の写しでないもの (= 申告で逃がすもの) は
  #4 / #5 / #6 / #7 / #8 の **5 件**。真に手を入れるべきものは #1 (実ドリフト) と
  #2 / #3 (事実と食い違う分類理由) の **3 件**

鳴った 8 件の内訳:

| # | 規則 | PHP | TS | 判定 |
|---|---|---|---|---|
| 1 | 2a | `app/Enums/ApiErrorCode.php` | `packages/cli/src/api/schemas.ts::ApiErrorCode` | **実ドリフト**。道具側は `rate_limit_exceeded` を持つがサーバは `rate_limited`。サーバ側の 4 値 (`insufficient_ability` / `actor_not_resolvable` / `idempotency_in_progress` / `idempotency_indeterminate`) が道具側に無い。当該ファイルの docblock 自身が「Mirrors `app/Enums/ApiErrorCode.php`」と書いている |
| 2 | 1 | `app/Enums/ApiKeyAbility.php` | `pages/Organizations/ApiKeys/Index.svelte::ABILITY_LABELS` | 独立した対応表 (`Record<string, string>`)。PHP 側の分類理由「管理画面はチェックボックスの選択状態だけを見る」が**事実と食い違っている** |
| 3 | 1 | `app/Enums/OAuth/OAuthClientKind.php` | `pages/Organizations/ApiKeys/Sessions.svelte::CLIENT_KIND_LABELS` | 同上。分類理由「認可ロジックの内部でのみ使う」が事実と食い違う |
| 4 | 1 | `app/Enums/Manual/CutType.php` | `features/manual/ScenarioEditor.svelte::DragOwner` | 別概念 (ドラッグの所有者) が偶然同値。申告 |
| 5 | 1 | `app/Enums/Notification/NotificationType.php` | `features/notifications/NotificationListItem.svelte` の分岐 | 登録済み型で束縛された分岐。申告 (理由には「既定の枝がある」だけでなく、値が増えたときに既定の絵柄へ落ちる利用者影響と期待動作まで書く) |
| 6 | 1 | `app/Enums/Manual/TakeStatus.php` | `types/manual.ts::SelectableTakeStatus` | 既存の申告 (継続) |
| 7 | 1 | `app/Enums/EnterpriseSso/OidcConnectionStatus.php` | `tests/js/.../oidc-connection.test.ts::ALL_STATUSES` | 検査側が並べた全値。申告 |
| 8 | 2b | `app/Enums/Manual/JobStatus.php` | `types/dashboard.ts::DashboardJobStatus` | 意図した真部分集合 (進行中のみ)。申告 |

**仮説は支持された**。広げた判定式は実ドリフトを 1 件見つけ、誤検出は申告 5 件で吸収できる。
この「10 件未満」は**この時点の観測値**であって将来の保証ではない (件数 pin が増減を可視化する)。
規則 2 を論理和にした差分は次のとおりで、**どちらの式も他方を包含しない**ことが実測で裏付いた:

- 規則 2a (厳密名対応 + 1 値交差) だけが拾ったもの = #1 (実ドリフト)
- 規則 2b (語分割名対応 + 両側半分以上の交差) だけが拾ったもの = #8 (誤検出 1 件)

## 改善アイデア

逆走査を正典 v3 の広さへ広げる。**PHP 側の発見の段は既に v3 なので触らない**。

1. **母集団 (i8)**: 版管理下の `*.ts` / `*.svelte` **全数**。0 件は不合格
2. **`.svelte` の第一級化 (i6)**: `<script>` の範囲だけを残し**行番号を元ファイルと一致**させた
   仮想 TS として同じ program に載せる
3. **候補の形 4 種 (i9)**: リテラル型の合併 / 定数の配列 / 対応表のキー / 分岐のラベル
4. **program の起点 (i5)**: tsconfig が含む全体 ∪ 版管理下の `.ts` ∪ 仮想 `.svelte` ∪ 目録のファイル。
   速さのために縮めない (実測 約 3 秒)
5. **規則 2 の論理和 (i10)**: 2a (厳密名対応 + 1 値交差) ∨ 2b (語分割名対応 + 両側半分以上の交差)
6. **申告の再整備 (i11)**: 広がった判定式に合わせて `REVERSE_SWEEP_EXEMPTIONS` を書き直す。
   理由 30 文字・実在・重複無し・件数 pin・**免除適用前**の stale 判定はすべて維持
7. **負の対照 (i12)**: 母集団・受理範囲・申告のそれぞれに負例を置き、故障注入で赤を実測する
8. **メッセージと宣言 (i13 / i15)**: PHP 側と TS 側の**両方の位置 (ファイル + 行)** を出す。
   docblock の保証範囲を書き換える

## 設計上の決着 (triage が挙げた衝突の処理)

### 決着 1: 母集団から外すのは「わざと構文を壊した見本」1 ディレクトリだけ

`tests/js/support/enum-ts-sync/fixtures/candidates-broken/broken.ts` は
**わざと構文を壊した負の対照**である。i14 は「構文が壊れたファイルを無言で読み飛ばさない」ので、
このファイルが母集団に入ると**本番の gate が恒久的に赤**になる (実測: `mode=included` で
`broken syntax files=1`)。申告では逃がせない — 申告は「候補として鳴ったもの」を逃がす仕組みで、
「読めないファイル」の受け皿ではない。

**決着**: 除外するのは `tests/js/support/enum-ts-sync/fixtures/candidates-broken/` **1 つだけ**とし、
`fixtures/` の残り (t01〜t25 / `candidates/mixed.ts`) と `program-fixtures/` は
**母集団に入れる**。除外は次の 4 条件で縛る。

1. 除外根は `tests/js/support/enum-ts-sync/` の配下に限る (構造で縛る。任意のパスを書けない)
2. **件数を pin する** (増えても減っても赤)。現時点は 1 件
3. **除外根が実在し、その配下の全ファイルが実際に構文診断で落ちること**を検査する。
   これで「除外根に正常なファイルを置いて母集団から静かに消す」経路が塞がる
   (置いた瞬間に**この検査が赤くなる**)
4. 除外を docblock の保証範囲へ明記する

見本を母集団に入れても鳴る組は 8 件で変わらない (実測)。したがってこの最小除外で
**検出力は落ちない**。副作用として、`fixtures/` の見本を書き換えると本番 gate の候補集合も
動く (過剰検出の向きなので許容する)。これは docblock に書く。

### 決着 2: 対応表のキーの「派生」除外は、証人つきでだけ行う

`Record<VideoManualStatus, string>` のような対応表は、キーの過不足を `pnpm typecheck` が落とす。
値をその場で決めていない**派生**であり、独立した写しではない。そのまま候補にすると
申告が許可一覧に膨らむ (i11 が禁じる形になる)。

一方、「束縛先の型はそれ自体が候補になる」は**一般には成り立たない** — 束縛先が
取り込んだ型・`keyof`・条件型・合成型で、候補の 4 形のどれにもならないことがある。
そのときに除外すると**代替の候補が存在せず検出力が落ちる**。

**決着**: 対応表のキー形の候補は、次を**すべて**満たすときだけ派生として外す
(1 つでも欠けたら候補として残す = fail-closed)。

- 明示の型 (注釈または `satisfies`) がある
- 型検査器で解決した結果、その型が**文字列の添字シグネチャを持たない**
- その型の**プロパティが 1 件以上あり、すべて必須** (`Partial<Record<…>>` は
  過不足を落とさないので派生と認めない)
- **証人がある** — 束縛先のキー集合と**同一の値集合を持つ候補が、
  「対応表のキー形**以外**」の候補 (型の合併 / 定数の配列 / 分岐のラベル) の中に
  1 件以上ある**。無ければ候補として残す

**証人を対応表以外に限る理由 (循環の遮断)**: 証人を「任意の候補」にすると、
同じキー集合を持つ対応表 A と B が**互いを証人にして両方消える**。
自分自身を証人にする経路も同時に閉じる必要がある。証人の資格を
「派生除外の対象になり得ない形」に限れば、判定は**非派生の候補を種にした単調な到達判定**になり、
自己証人も相互証人も 3 件の循環も構造的に起こらない (一括の相互参照判定にしない)。
負例には**自己証人・2 件の相互証人・3 件の循環証人**を置く。

判定はすべて**型検査器の解決結果**で行う (構文で `Record` や `satisfies` を当てない)。
型を解決できない場合は除外せず候補に残す。正例・負例には
**型別名越しの `Record` / `Partial` / union / intersection / `keyof` / 取り込んだ型 / `satisfies`**
を置き、「必須プロパティがある」だけで派生と断じていないことを固定する。

実測 (2026-08-24): 派生の条件 3 つまでを満たすのが 63 件、うち**証人があるのは 10 件だけ**で、
残り 53 件は候補として残る。それでも鳴る組は 8 件で変わらない。
すなわち循環を塞いだ厳しい証人条件は**ただで買える**。

### 決着 3: 診断文は正しさで決める。その帰結として乖離台帳を 1 件動かす

`tests/js/architecture/enum-ts-sync.test.ts` と `tsconfig.json` は
`docs/template-fingerprints.json` のキーであり、かつ
`tests/Support/TemplateDivergence/adoption-debt.tsv` に採用時ハッシュ付きで凍結されている。
触ると `TemplateDivergenceFingerprintTest` が `mutatedDebtPaths` で落ちるため、
「変更したまま債務に残す」は選べない (app-design 3-0 段)。

**決着**: **台帳の都合で診断文を捻じ曲げない**。順序を固定する —
(1) 受理範囲の正しい診断文を決める → (2) その結果として既存の負の対照が壊れるかを見る →
(3) 壊れるなら台帳の手当てをする。

見積り: 登録できる TS の置き場が `resources/js/` ∪ `packages/*/src/` になるので、
正しい診断文は両方を挙げる形になり、既存の負の対照が照合している語
(`resources/js/ 配下だけ`) は自然な言い回しでは残らない。したがって
**`tests/js/architecture/enum-ts-sync.test.ts` は変更する**前提で設計する。

手当て (同じ変更で行う):

- `docs/template-divergence.md` に **D50** を新設し、
  「前向きの検査を単一ファイルの構文木方式ではなく、共有の走査器 + 型情報方式で持ち、
  目録を逆走査の gate と共有する」という既存の逸脱を登録する
  (テンプレートは v2 = 3,858 行の単一ファイル / 構文木のみ。aicue は 220 行 + 支援モジュール群)
- `tests/Support/TemplateDivergence/adoption-debt.tsv` から
  `tests/js/architecture/enum-ts-sync.test.ts` の行を削る
- `tests/Support/TemplateDivergence/LedgerPins.php` の
  `DIVERGENCE_ENTRY_COUNT` 46→47、`ADOPTION_DEBT_COUNT` 148→147 を同じ変更で直す

**`tsconfig.json` は変えない**。`packages/cli` は **program の起点に足す**ことで型世界へ入れる
(aigenba の `outsideTsconfig()` と同じ方式)。tsconfig の `include` は本番のビルド設定であって
gate の都合で広げるものではなく、広げると `pnpm typecheck` の対象まで動いてしまう。

変更する `tests/js/support/enum-ts-sync/*.ts` と
`tests/js/architecture/enum-ts-sync-discovery*.test.ts` は指紋台帳のキーに無い
(= テンプレートに無い aicue 固有の上積み) ため、登録の義務は生じない。

### 決着 4: 実ドリフト (#1) は申告で黙らせず、同じ変更で直す

`packages/cli/src/api/schemas.ts` の符号一覧はサーバの `App\Enums\ApiErrorCode` と食い違っている。
「値が食い違ったまま申告する」のは抑制コメントで黙らせるのと同じ形で、禁止事項 2 の精神に反する。

**決着**: 道具側の一覧を **(a) サーバの写し** と **(b) 道具固有の符号** の 2 つに割り、
(a) を目録へ登録して値集合の一致を gate に固定させる。(b) はサーバの enum に無い符号なので
PHP 側と交差せず、鳴らない。両者の合併である `ApiErrorCode` は**申告 1 件**で逃がす
(理由: サーバの符号と道具固有の符号の合併であり、写しの実体は (a) 側で登録済み)。

### 決着 4b: 道具側の是正は型の分割だけで終わらせない

決着 4 の分割は**契約の整理**であり、それだけでは道具の振る舞いが正しくならない。
サーバが `rate_limited` を返すのに道具が `rate_limit_exceeded` で分岐している経路は、
現状 HTTP の状態番号への退避で辛うじて動いている。同じ変更で次を固定する。

- **用途の明示**: サーバの写し (a) / 道具固有の符号 (b) / 公開する合併型 (c) の 3 つが
  それぞれ何のためにあるかを docblock に書く
- **道具側の検査**: サーバ固有の符号・道具固有の符号・**未知の符号**の 3 系統について、
  応答の分類が期待どおりであることを `packages/cli/tests` で固定する
  (未知の符号は拒否せず状態番号へ退避する、が既存の契約である)

### 決着 5: 事実と食い違った PHP 側の分類理由を直す (#2 / #3)

`ApiKeyAbility` / `OAuthClientKind` の `PHP_ENUM_EXEMPTIONS` の理由は
「画面へは出ない / 内部でのみ使う」だが、実際には画面の対応表が値をキーにしている。
**理由の文面を事実に合わせて書き直す** (分類そのものは「対象外」のままでよい —
対応表は未知の値を素の文字列で表示する退避を持ち、値の取りこぼしが画面を壊さない)。
そのうえで対応表の側を**申告**へ登録する。

## 期待効果

- **使命への貢献**: 撮影 PWA と管理画面は制作状態・カット種別・通知種別といった
  サーバ側の選択肢で分岐する。写しがずれると「思考ゼロ・編集ゼロ」の導線が
  無言で 1 本欠ける。逆走査が `.svelte` と道具パッケージまで届くことで、
  **画面の中に直接書かれた写しと、付属コマンドライン道具の写し**が初めて検査対象になる
- **実測された具体効果**: 母集団が 62 本 → **508 本** (`.ts` 378 本 + `.svelte` 130 本。
  追跡下の `.ts` は 379 本で、意図的に構文を壊した見本 1 本だけを除いた数である)。
  候補が 87 件 → **304 件** (106 + 163 + 22 + 13)。鳴った組 8 件。
  **実在の未検出ドリフト 1 件**を検出
- **家系への貢献**: 未決論点 q2 (論理和の誤検出件数) に**家系で初の一次観測**を与える

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `tests/js/support/enum-ts-sync/program.ts` | 仮想 `.svelte` を載せる compiler host。起点に版管理下の `.ts` と仮想 `.svelte` を足す。`createMirrorProgram(tsFiles)` の**呼び出し形は変えない** |
| `tests/js/support/enum-ts-sync/svelte-source.ts` (新設) | `.svelte` → 行番号を保った仮想 TS への変換 (単体で検査できる純関数) |
| `tests/js/support/enum-ts-sync/ts-candidates.ts` | 母集団を版管理下の全数へ。候補の形を 4 種へ。派生の除外。行番号を持たせる |
| `tests/js/support/enum-ts-sync/reverse-sweep.ts` | 規則 2 を 2a ∨ 2b の論理和へ |
| `tests/js/support/enum-ts-sync/mirror-inventory.ts` | 登録できる TS の置き場を `resources/js/` ∪ `packages/*/src/` へ。`.svelte` の登録も受ける |
| `tests/js/support/enum-ts-sync/ts-value-sets.ts` | `.svelte` の中の型別名を読めるようにする (仮想パスの解決) |
| `tests/js/architecture/enum-ts-sync-discovery.test.ts` | 除外根の pin と検査、申告の再整備、メッセージに両側の位置、docblock の保証範囲 |
| `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts` | 新しい母集団・受理範囲・申告の負の対照と故障注入 |
| `tests/js/architecture/enum-ts-sync.test.ts` | `validateMirrors()` の負の対照を新しい受理範囲へ (指紋 + 債務。決着 3 の手当てを伴う) |
| `docs/template-divergence.md` / `adoption-debt.tsv` / `LedgerPins.php` | D50 の新設と債務 1 行の解消、件数 pin 2 つの更新 |
| `packages/cli/src/api/schemas.ts` / `client.ts` | 実ドリフトの是正 (決着 4) |
| `AGENTS.md` ドメイン固有規約 19 / `docs/architecture.md` | 受理する形と保証範囲の更新。**正典 v3 の条文を転載しない** — 書くのは aicue 固有の受理範囲・除外集合・保証外だけで、正典は版 (v3) で指す |

## 制約・前提

- **正本のレーンは `pnpm test`**。`composer test` では走らない (この非対称は維持する)
- 走査器・gate の新設変更なので **AGENTS.md §走査器・gate を新設・変更するときに同じ PR で
  揃える 4 点**が発火する (負例と正例 / 解決できない形を落とす分岐 / 空振り検査 / docblock)
- 静的検査の共通規約 (a)〜(e) のうち、本件は **(b) fail-closed**・**(c) 負例の裏取り**・
  **(d) 使わない走査結果を作らない**・**(e) 語彙一致の否定形** が効く。
  規則 2b は語に分けたトークンの完全一致で判定し、**区切り文字を宣言する** ((e))
- 見本置き場は tsconfig の `exclude` にあり `pnpm typecheck` の対象外である。この関係は変えない
- `.svelte` の登録経路は**用意するが aicue に登録対象は現時点で 0 件**である
  (正典 i6 が要求するため用意する。見本で正例・負例を固定する)

## 詳細設計へ持ち越す確定事項 (概念段階で穴を残さないための宣言)

規則 2b と `.svelte` の仮想化は、詳細設計で**次を必ず確定する**。

**規則 2b (語分割名対応 + 両側半分以上の交差)**

- **区切り**: 何を区切りとして語に割るかを宣言する (大文字境界 / 数字境界 / `_` / `-` / `.`)。
  AGENTS.md §共通規約 (e) が要求する「区切り文字の宣言」である
- **正規化**: 大文字小文字と単純な複数形 (`s` / `es` / `ies`) の畳み方を宣言する
- **主要語**: 「頭の名詞」を**語列の末尾の語**と定義する (英語の複合名詞の主要部)。
  実測の `words[job+statu] head=statu` はこの定義の出力である
- **一致数**: 主要語の一致に加え、共通語数が `min(2, 列挙名の語数)` 以上
- **交差**: 交差の要素数が**両側それぞれの要素数の半分以上** (`ceil` 側で切り上げ)。
  値は集合として扱う。どちらかが空集合なら鳴らさない
- **名前を持たない候補**: 分岐のラベルは判定対象の式の**型の名前**を優先し、
  取れなければ式の字面を使う。**どちらも取れないときは「名前解決不能」という
  解析の失敗として gate を赤くする** (候補に残して名前対応だけ不成立にすると、
  完全一致しない真の部分写しが規則 1 にも規則 2 にも掛からず**無言で通過する**。
  AGENTS.md §共通規約 (b)「未解決を解決済みと同じ値へ混ぜない」)
- **診断**: 2a と 2b の両方に該当しても、**どの規則・どの語・どの値の交差で鳴ったか**を出す
- **負例**: 接頭辞つき・打ち消しつき・接尾辞つきの 3 形を置く (共通規約 (e))

**`.svelte` の仮想化**

- 仮想ファイルのパス規則 (実在ファイルと衝突しないこと。`*.svelte.ts` が実在するため
  素朴な `.ts` 付加は採らない)
- `<script>` が複数ある場合 (`module` 文脈と実体文脈) は**スコープを分離したまま**扱う。
  1 本へ連結すると別スコープの宣言が混ざり、重複宣言と名前解決に偽の結果が出る。
  **文脈ごとに別の仮想ファイルへ割る**のを既定とし、3 つ以上の script や
  想定外の属性は不合格にする
- 診断の位置を元の `.svelte` へ**逆写像**できること (行だけでなく列も)
- `lang="ts"` の有無・属性の並びの扱いと、**扱えない書き方を不合格にする条件**
- 行・列を元ファイルと一致させる方式と、その一致を固定する検査
- 読み取り不能・構文不正のときに**無言で読み飛ばさない**こと

## 故障注入の一覧 (i12。probe の観測を継続的な赤へ移す)

実測は記録であって検査ではない。次の 8 つを**故障注入で赤くなること**まで固定する。

1. 除外根を空にする / 広げる → 除外根の件数 pin と「配下が全件構文で落ちる」検査が赤くなる
2. 派生除外の判定を常に真にする → 証人の無い派生が候補から消え、負の対照が赤くなる
3. 版管理下のファイル列挙を空にする → 「母集団が 0 件」で赤くなる
4. `.svelte` の仮想化を無効にする / module と実体の script を 1 本へ連結する →
   `.svelte` の中の見本候補が消える・偽の重複宣言が出る、で負の対照が赤くなる
5. 規則 2 の論理和から片方を落とす → その式だけが拾う見本が消え、負の対照が赤くなる
6. 申告の生死判定を「免除適用後」に変える → 自分自身を根拠にする申告の見本が通ってしまい赤くなる
7. 証人の資格を「任意の候補」へ緩める → 相互証人・循環証人の見本が消え、負の対照が赤くなる
8. 名前解決不能の分岐ラベルを候補から静かに落とす → 「名前解決不能で赤くなる」負例が緑になる

## スコープ外

- **PHP 側の発見の段**の作り替え (既に v3)
- 目録に登録した写しを見る**前向きの検査**に 4 種すべてを読ませること
  (正典 v3 は逆走査の候補の形を 4 種と定めるだけで、登録の受理範囲は定めない。
  型別名として切り出して登録する、が引き続き案内になる)
- `.svelte` への値集合の直書きを**禁止する**規則の新設 (正典 s2 が不変条件から外した)
- 未決論点 **q1** (テンプレート系の構築費) と **q3** (spirux の切り分け) — 他リポジトリの担当
- `packages/cli` の道具固有の符号 (`site_not_cli_capture` / `use_audits_submit` 等) の棚卸し。
  aicue に対応する controller は無いが、道具の挙動に関わるため本追従では触らない

## 関連する現行コード

### tests/js/support/enum-ts-sync/ts-candidates.ts
```ts
/**
 * `resources/js/` 配下にある**文字列リテラル型だけの union に解決する型別名**を
 * 全数走査する (裁定 AG-099 後半 / 逆走査の入力)。
 *
 * `readTsUnionValues` (`ts-value-sets.ts`) は「目録に登録した 1 つの宣言」を読む検査で、
 * 受理できない形は例外にして呼び出し側の登録ミスを知らせる。本モジュールは向きが逆で、
 * **プログラム全体から候補を拾う**。**型別名 1 つずつの受理・拒否は黙って読み飛ばす**
 * (「型別名だが対象にならない」は前者では失敗、後者では単に非対象という違いである) が、
 * **ファイル単位の構文診断は無言で読み飛ばさない**。構文が壊れたファイルは中の型別名が
 * 正しく読めているか判別できないため、その 1 点だけは例外にして gate を失敗させる
 * (AGENTS.md §静的検査の共通規約 (b) fail-closed)。
 *
 * **母集団の実体**: `resources/js/` 配下の走査対象は `program.getSourceFiles()` から
 * `.ts` の**通常ファイルだけ**を取る (`source.isDeclarationFile` で `.d.ts` を除く)。
 * `program` は `createMirrorProgram()` が `tsconfig.json` の `include`/`exclude` から組むが、
 * **それだけを母集団の出典とは言わない** — `resources/js/` をプログラムを介さず
 * 直接再帰的に歩いた `*.ts` (`.d.ts` を除く) の集合と、program に載った集合が
 * **完全一致すること**を独立実装の回帰テストで固定しており、この一致こそが
 * 「呼び出し時に渡す `tsFiles` 引数に依存しない・`exclude` が意図せず広がっていない」
 * という不変条件の実体である (`enum-ts-sync-discovery-extractor.test.ts` の
 * 「走査した非宣言ファイルの集合は、ファイルシステムを直接歩いた集合と一致する」テスト)。
 *
 * **保証しないもの**: 対象は `resources/js/` 配下の `.ts` ファイルのトップレベルにある
 * `type X = …` 宣言だけ。`.svelte` の中の宣言・定数配列・switch の case ラベル・
 * ネストした (トップレベルでない) 型別名は対象外。**`.d.ts` (宣言ファイル) も対象外**
 * (`vite-env.d.ts` 以外に手書きの `.d.ts` が増えても、その中の literal union は読まない)。
 */
import ts from "typescript";
import path from "node:path";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT, type MirrorProgram } from "./program";

export interface TsUnionCandidate {
    /** リポジトリルートからの相対パス。 */
    readonly file: string;
    /** 型別名の名前。 */
    readonly name: string;
    readonly values: ReadonlySet<string>;
}

/** `root` の配下にあるか (区切り文字まで含めて見る。兄弟ディレクトリを通さない)。 */
const isUnder = (absolute: string, root: string): boolean => absolute === root || absolute.startsWith(root + path.sep);

/** 解決した型が文字列リテラル型だけの union (または単独) なら値集合を返す。それ以外は `undefined`。 */
const tryReadStringLiteralUnion = (checker: ts.TypeChecker, alias: ts.TypeAliasDeclaration): ReadonlySet<string> | undefined => {
    const symbol = checker.getSymbolAtLocation(alias.name);
    if (symbol === undefined) return undefined;

    const declared = checker.getDeclaredTypeOfSymbol(symbol);
    const parts = declared.isUnion() ? declared.types : [declared];

    const values = new Set<string>();
    for (const part of parts) {
        if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) return undefined;
        if (!part.isStringLiteral()) return undefined;
        values.add(part.value);
    }
    if (values.size === 0) return undefined;
    return values;
};

/**
 * `resources/js/` 配下の全 `.ts` ファイルから、文字列リテラル型だけの union に解決する
 * トップレベルの型別名をすべて拾う。
 *
 * @param jsRoot 走査根 (既定は `resources/js`。負のコントロール専用の引数)
 */
export const collectTsUnionCandidates = (
    { program, checker }: MirrorProgram,
    jsRoot: string = path.join(REPO_ROOT, "resources", "js"),
): readonly TsUnionCandidate[] => {
    const candidates: TsUnionCandidate[] = [];

    for (const source of program.getSourceFiles()) {
        if (source.isDeclarationFile) continue;
        if (!isUnder(source.fileName, jsRoot)) continue;

        const where = path.relative(REPO_ROOT, source.fileName).split(path.sep).join("/");
        if (program.getSyntacticDiagnostics(source).length > 0) {
            throw new EnumTsSyncError(where, "構文が壊れているため候補を読めません (無言で読み飛ばさない)");
        }

        for (const statement of source.statements) {
            if (!ts.isTypeAliasDeclaration(statement)) continue;
            const values = tryReadStringLiteralUnion(checker, statement);
            if (values === undefined) continue;
            candidates.push({
                file: where,
                name: statement.name.text,
                values,
            });
        }
    }

    return candidates;
};
```
### tests/js/support/enum-ts-sync/reverse-sweep.ts
```ts
/**
 * 逆走査 (裁定 AG-099 後半)。
 *
 * `enum-ts-sync.test.ts` は「目録に登録した写しについて PHP → TS を見る」向きの検査なので、
 * **登録し忘れた写し**は素通りする。本モジュールは向きを変え、TS 側の型別名の候補
 * (`collectTsUnionCandidates`) と PHP の文字列付き列挙の母集団 (`buildPhpEnumCatalog`)
 * を突き合わせ、次の 2 規則で「未登録だが対応していそうな組」を検出する。
 *
 * - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全に一致する未登録の TS 宣言。
 *   これは「登録を忘れているだけ」の可能性が高い最有力候補である。
 * - **規則 2 (名前対応 + 値の交差)**: 型別名の名前が PHP 列挙名と厳密に対応し
 *   (一致 / 複数形接尾辞 `s` `es` `values` の付加)、かつ値集合が交差するが**完全一致ではない**
 *   未登録の TS 宣言。これは「かつて対応していたが、どちらか片方だけ値を足して
 *   ズレた写し」を拾うためのもので、規則 1 に緩い部分集合や名前無視の条件を混ぜると
 *   誤検出が支配的になる (家系の実測: 緩い形は偽陽性 80〜100%)。
 *
 * **これは「登録漏れが無いことの証明」ではなく「候補の検出」である**。
 * 名前も対応せず値も完全一致しない drift 済みの写しは検出できない (意図した限界)。
 */
import type { ResolvedPhpEnum } from "./php-enum-catalog";
import type { TsUnionCandidate } from "./ts-candidates";

export interface UnregisteredMirrorCandidate {
    readonly rule: 1 | 2;
    readonly php: ResolvedPhpEnum;
    readonly candidate: TsUnionCandidate;
    /** 規則 1 は `null`。規則 2 は名前の対応関係の説明 (メッセージ用)。 */
    readonly nameMatch: string | null;
}

/**
 * 大文字小文字の違いだけを吸収する。**英数字以外は除去しない**
 * (`_` や `$` まで消すと `Foo_Bar` と `FooBar` を同一視してしまい、
 * 「一致 / +s / +es / +values」という厳密な対応より緩くなる)。
 */
const normalizeName = (name: string): string => name.toLowerCase();

/** ファイル名の語幹を取る (テストの見本構築用のユーティリティ。判定本体は `ResolvedPhpEnum.name` を使う)。 */
export const shortEnumName = (path: string): string => {
    const base = path.split("/").pop() ?? path;
    return base.endsWith(".php") ? base.slice(0, -".php".length) : base;
};

/** 厳密な名前対応 (一致 / +s / +es / +values)。対応しなければ `null`。 */
const nameCorrespondence = (candidateName: string, enumName: string): string | null => {
    const candidate = normalizeName(candidateName);
    const target = normalizeName(enumName);
    if (candidate === target) return `${target} = ${candidate}`;
    for (const suffix of ["s", "es", "values"]) {
        if (candidate === `${target}${suffix}`) return `${target} + "${suffix}" = ${candidate}`;
    }
    return null;
};

const sameValueSet = (a: ReadonlySet<string>, b: ReadonlySet<string>): boolean => {
    if (a.size !== b.size) return false;
    for (const value of a) if (!b.has(value)) return false;
    return true;
};

const intersects = (a: ReadonlySet<string>, b: ReadonlySet<string>): boolean => {
    for (const value of a) if (b.has(value)) return true;
    return false;
};

/**
 * 未登録のミラー候補を検出する。
 *
 * @param phpEnums   母集団のうち値集合が読めた PHP 列挙 (`resolved`)。
 * @param candidates TS 側の型別名の候補。
 * @param isRegistered `(file, name)` の組が既に目録に登録済みかを判定する述語
 *                      (登録済みは検査対象から外す)。
 */
export const findUnregisteredMirrorCandidates = (
    phpEnums: readonly ResolvedPhpEnum[],
    candidates: readonly TsUnionCandidate[],
    isRegistered: (file: string, name: string) => boolean,
): readonly UnregisteredMirrorCandidate[] => {
    const found: UnregisteredMirrorCandidate[] = [];

    for (const candidate of candidates) {
        if (isRegistered(candidate.file, candidate.name)) continue;

        for (const phpEnum of phpEnums) {
            if (sameValueSet(phpEnum.values, candidate.values)) {
                found.push({ rule: 1, php: phpEnum, candidate, nameMatch: null });
                continue;
            }

            const correspondence = nameCorrespondence(candidate.name, phpEnum.name);
            if (correspondence === null) continue;
            if (!intersects(phpEnum.values, candidate.values)) continue;

            found.push({ rule: 2, php: phpEnum, candidate, nameMatch: correspondence });
        }
    }

    return found;
};
```
### tests/js/support/enum-ts-sync/program.ts
```ts
/**
 * 型情報の入口 (TypeScript の program と型検査器を作る)。
 *
 * **本番の gate は `tsconfig.json` が含む TS ファイル全体で program を作る**。
 * 目録のファイルだけを起点にすると、`include` だけで参加する宣言 (周囲宣言 `.d.ts` /
 * `declare global` / モジュールの拡張) が program に載らず、**本番の型と違う型世界**で
 * 判定してしまう。本リポジトリには実際に `resources/js/lib/recaptcha.ts` の
 * `declare global` があり、この経路は絵空事ではない。偽陰性 (取り残しを緑にする) に
 * なるので、速さのために起点を縮める判断はしない。
 */
import ts from "typescript";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { EnumTsSyncError } from "./errors";

/** リポジトリのルート (tests/js/support/enum-ts-sync から 4 つ上)。 */
export const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../../..");

export interface MirrorProgram {
    readonly program: ts.Program;
    readonly checker: ts.TypeChecker;
}

const formatHost: ts.FormatDiagnosticsHost = {
    getCanonicalFileName: (fileName) => fileName,
    getCurrentDirectory: () => REPO_ROOT,
    getNewLine: () => "\n",
};

/** tsconfig.json を読む。回復可能な診断も含めて 1 件でもあれば例外にする。 */
const parseRepoTsconfig = (): ts.ParsedCommandLine => {
    const configPath = path.join(REPO_ROOT, "tsconfig.json");
    const host: ts.ParseConfigFileHost = {
        useCaseSensitiveFileNames: ts.sys.useCaseSensitiveFileNames,
        readDirectory: ts.sys.readDirectory,
        fileExists: ts.sys.fileExists,
        readFile: ts.sys.readFile,
        getCurrentDirectory: () => REPO_ROOT,
        onUnRecoverableConfigFileDiagnostic: (d) => {
            throw new EnumTsSyncError("tsconfig.json", ts.flattenDiagnosticMessageText(d.messageText, " "));
        },
    };
    const parsed = ts.getParsedCommandLineOfConfigFile(configPath, {}, host);
    if (parsed === undefined) throw new EnumTsSyncError("tsconfig.json", "読み込みに失敗しました");
    if (parsed.errors.length > 0) {
        throw new EnumTsSyncError("tsconfig.json", ts.formatDiagnostics(parsed.errors, formatHost));
    }
    if (parsed.fileNames.length === 0) {
        throw new EnumTsSyncError("tsconfig.json", "対象ファイルが 0 件です (gate が空振りしている)");
    }
    return parsed;
};

const buildProgram = (rootNames: readonly string[], parsed: ts.ParsedCommandLine): MirrorProgram => {
    const program = ts.createProgram({
        rootNames: [...rootNames],
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
 * 目録が指す TS ファイルを含む program を作る。
 * 起点は **tsconfig が含む全ファイル ∪ 目録のファイル**。
 *
 * @param tsFiles リポジトリルートからの相対パス
 */
export const createMirrorProgram = (tsFiles: readonly string[]): MirrorProgram => {
    const parsed = parseRepoTsconfig();
    const inventoryRoots = tsFiles.map((file) => {
        const absolute = path.join(REPO_ROOT, file);
        if (!fs.existsSync(absolute)) {
            throw new EnumTsSyncError(file, "目録が指す TS ファイルが実在しません");
        }
        return absolute;
    });
    return buildProgram([...new Set([...parsed.fileNames, ...inventoryRoots])], parsed);
};

/**
 * 見本 (fixture) 専用の**起点を縮めた** program。**本番の gate では使わない**。
 * リポジトリの `compilerOptions` (`paths` を含む) はそのまま使い、起点だけを明示する。
 *
 * @param absoluteFiles 絶対パス
 */
export const createFixtureProgram = (absoluteFiles: readonly string[]): MirrorProgram => {
    const parsed = parseRepoTsconfig();
    for (const absolute of absoluteFiles) {
        if (!fs.existsSync(absolute)) throw new EnumTsSyncError(absolute, "見本ファイルが実在しません");
    }
    return buildProgram(absoluteFiles, parsed);
};
```
### tests/js/support/enum-ts-sync/ts-value-sets.ts
```ts
/**
 * TS 側の値集合を**型情報から**読む。
 *
 * 受理する形 (**解決・正規化された後の型**についての条件である):
 *   1. 対象ファイルのトップレベルに、その名前の**型別名の宣言**が**ちょうど 1 つ**あること。
 *   2. その宣言が解決する型が、**文字列リテラル型だけ**の union か、単独の文字列リテラル型であること。
 *   3. `ts.TypeFlags.EnumLiteral` を持つ構成要素は**受理しない** (本リポジトリに TypeScript の
 *      `enum` は 1 件も無く、文字列リテラル型と同じ契約ではないため。必要になってから広げる)。
 *
 * **重複は検出しない**。`"a" | "a"` は型検査器が `"a"` へ正規化するため、値集合の側からは
 * 元の重複を観測できない (union の中の `never` も同じく正規化で消える)。
 * **意味の診断は見ない** — 型検査そのものは `pnpm typecheck` の担当で、同じことを 2 箇所で見ない。
 */
import ts from "typescript";
import path from "node:path";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT, type MirrorProgram } from "./program";

export const readTsUnionValues = (
    { program, checker }: MirrorProgram,
    tsFile: string,
    declaration: string,
): ReadonlySet<string> => {
    const where = `${tsFile}::${declaration}`;
    const source = program.getSourceFile(path.join(REPO_ROOT, tsFile));
    if (source === undefined) throw new EnumTsSyncError(where, "TS ファイルが program に載っていません");

    // 構文が壊れていると型解決が黙って縮むので、構文の診断だけは見る。
    if (program.getSyntacticDiagnostics(source).length > 0) {
        throw new EnumTsSyncError(where, "TS ファイルの構文が壊れています");
    }

    const aliases = source.statements
        .filter(ts.isTypeAliasDeclaration)
        .filter((statement) => statement.name.text === declaration);
    if (aliases.length === 0) {
        throw new EnumTsSyncError(
            where,
            "型別名の宣言が見つかりません (受理するのは `type X = …` だけ。定数配列・switch の case ラベル・.svelte 内の宣言は読みません)",
        );
    }
    if (aliases.length > 1) {
        throw new EnumTsSyncError(where, `同名の型別名が ${aliases.length} 件あります`);
    }

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
            throw new EnumTsSyncError(
                where,
                `文字列リテラル型でない構成要素があります: ${checker.typeToString(part)}`,
            );
        }
        values.add(part.value);
    }
    if (values.size === 0) throw new EnumTsSyncError(where, "値を 1 つも取り出せません");

    return values;
};
```
### tests/js/support/enum-ts-sync/mirror-inventory.ts (validateMirrors 抜粋)
```ts
/**
 * 目録の件数の pin。増えても減っても赤くする (写しが黙って消えるのを防ぐ)。
 * **これは網羅の証明ではない** — 登録していない写しは 1 件も検査していない。
 */
export const EXPECTED_MIRROR_COUNT = 29;

/** `root` の**配下**にあるか (兄弟ディレクトリを通さないよう区切りまで含めて見る)。 */
export const isUnder = (absolute: string, root: string): boolean => absolute.startsWith(root + path.sep);

/**
 * 目録の行の体裁を検査する純関数。
 * **program を作る前に呼ぶ** — 後回しにすると、検査の外にあるファイルを
 * 「赤くなる前に読んでしまう」ことになる。
 *
 * @param rows 目録の行
 * @param root 走査根 (既定はリポジトリのルート)。**負のコントロールが symlink や
 *             兄弟ディレクトリを含む見本の木を一時ディレクトリに作って渡すためだけ**に
 *             引数化してある (本番の呼び出しは既定値を使う)。
 */
export const validateMirrors = (rows: readonly EnumTsMirror[], root: string = REPO_ROOT): void => {
    const appRoot = path.join(root, "app");
    const jsRoot = path.join(root, "resources", "js");
    const seen = new Set<string>();
    const seenReal = new Set<string>();

    for (const row of rows) {
        const where = `${row.php} ⇔ ${row.ts}::${row.declaration}`;

        for (const relative of [row.php, row.ts]) {
            if (path.isAbsolute(relative)) throw new EnumTsSyncError(where, `絶対パスは登録できません: ${relative}`);
            if (relative.includes("\\")) throw new EnumTsSyncError(where, `逆斜線を含むパスは登録できません: ${relative}`);
            const segments = relative.split("/");
            if (segments.some((s) => s === "" || s === "." || s === "..")) {
                throw new EnumTsSyncError(where, `. / .. / 空の区間を含むパスは登録できません: ${relative}`);
            }
        }

        if (!row.php.endsWith(".php")) throw new EnumTsSyncError(where, `php は .php で終わること: ${row.php}`);
        if (!row.ts.endsWith(".ts")) throw new EnumTsSyncError(where, `ts は .ts で終わること: ${row.ts}`);
        if (row.note.trim() === "") throw new EnumTsSyncError(where, "note が空です");

        const phpAbs = path.resolve(root, row.php);
        const tsAbs = path.resolve(root, row.ts);
        if (!isUnder(phpAbs, appRoot)) throw new EnumTsSyncError(where, `php は app/ 配下だけ: ${row.php}`);
        if (!isUnder(tsAbs, jsRoot)) throw new EnumTsSyncError(where, `ts は resources/js/ 配下だけ: ${row.ts}`);

        for (const [absolute, scanRoot, label] of [
            [phpAbs, appRoot, row.php],
            [tsAbs, jsRoot, row.ts],
        ] as const) {
            if (!fs.existsSync(absolute)) throw new EnumTsSyncError(where, `登録されたファイルが実在しません: ${label}`);
            if (!fs.statSync(absolute).isFile()) throw new EnumTsSyncError(where, `通常ファイルではありません: ${label}`);
            // symlink 経由で走査範囲の外へ抜けられないようにする
            if (!isUnder(fs.realpathSync(absolute), scanRoot)) {
                throw new EnumTsSyncError(where, `symlink の解決先が走査範囲の外です: ${label}`);
            }
        }

        const key = `${row.ts}::${row.declaration}`;
        if (seen.has(key)) throw new EnumTsSyncError(where, `同じ TS 宣言が 2 回登録されています: ${key}`);
        seen.add(key);

        const realKey = `${fs.realpathSync(tsAbs)}::${row.declaration}`;
        if (seenReal.has(realKey)) {
            throw new EnumTsSyncError(where, `symlink 越しに同じ TS 宣言が 2 回登録されています: ${realKey}`);
        }
        seenReal.add(realKey);
    }
};

/** 登録済みの `(php パス)` 集合。発見の段が「登録済み」を判定するのに使う。 */
export const registeredPhpPaths = (rows: readonly EnumTsMirror[] = ENUM_TS_MIRRORS): ReadonlySet<string> =>
    new Set(rows.map((row) => row.php));

/** 登録済みの `(ts パス, 宣言名)` 集合。逆走査が「登録済み」を判定するのに使う。 */
export const registeredTsKeys = (rows: readonly EnumTsMirror[] = ENUM_TS_MIRRORS): ReadonlySet<string> =>
    new Set(rows.map((row) => `${row.ts}::${row.declaration}`));
```
### tests/js/architecture/enum-ts-sync-discovery.test.ts (逆走査の部分と beforeAll)
```ts
let tsCandidates: readonly TsUnionCandidate[] | undefined;

const requireCatalog = (): PhpEnumCatalog => {
    if (catalog === undefined) throw new Error("catalog が初期化されていません");
    return catalog;
};

const requireTsCandidates = (): readonly TsUnionCandidate[] => {
    if (tsCandidates === undefined) throw new Error("tsCandidates が初期化されていません");
    return tsCandidates;
};

beforeAll(() => {
    catalog = buildPhpEnumCatalog();
    mirrorProgram = createMirrorProgram([...new Set(ENUM_TS_MIRRORS.map((m) => m.ts))]);
    tsCandidates = collectTsUnionCandidates(mirrorProgram);
}, 300_000);

describe("PHP 文字列付き列挙の発見の段 (全数走査・既定拒否の分類)", () => {
    it("走査が空振りしていない (母集団が空でない)", () => {
        const { resolved, unresolvable } = requireCatalog();
        expect(resolved.length).toBeGreaterThan(0);
        expect(resolved.length + unresolvable.length).toBeGreaterThan(0);
    });

});

describe("PHP ⇔ TS 値域の逆走査 (未登録候補の検出)", () => {
    it("TS 側の候補走査が空振りしていない (母集団が空でない)", () => {
        expect(requireTsCandidates().length).toBeGreaterThan(0);
    });

    it("逆走査で見つかる候補は REVERSE_SWEEP_EXEMPTIONS に登録された分だけである", () => {
        const registered = registeredTsKeys();
        const found = findUnregisteredMirrorCandidates(
            requireCatalog().resolved,
            requireTsCandidates(),
            (file, name) => registered.has(`${file}::${name}`),
        );

        const exemptKeys = new Set(
            REVERSE_SWEEP_EXEMPTIONS.map((e) => reverseSweepKey(e.php, e.file, e.declaration, e.rule)),
        );

        const unexempted = found.filter(
            (f) => !exemptKeys.has(reverseSweepKey(f.php.path, f.candidate.file, f.candidate.name, f.rule)),
        );

        expect(
            unexempted,
            `未登録のミラー候補が見つかりました (登録するか REVERSE_SWEEP_EXEMPTIONS へ理由付きで登録すること):\n${unexempted
                .map((f) => `規則${f.rule} ${f.php.path} <-> ${f.candidate.file}::${f.candidate.name}${f.nameMatch !== null ? ` (${f.nameMatch})` : ""}`)
                .join("\n")}`,
        ).toEqual([]);
    });

    it("REVERSE_SWEEP_EXEMPTIONS の件数が pin と一致し、登録先が実在・重複無し・reason が 30 文字以上", () => {
        expect(REVERSE_SWEEP_EXEMPTIONS).toHaveLength(EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT);

        const seen = new Set<string>();
        for (const entry of REVERSE_SWEEP_EXEMPTIONS) {
            expect(fs.existsSync(path.join(REPO_ROOT, entry.php))).toBe(true);
            expect(fs.existsSync(path.join(REPO_ROOT, entry.file))).toBe(true);
            const key = reverseSweepKey(entry.php, entry.file, entry.declaration, entry.rule);
            expect(seen.has(key)).toBe(false);
            seen.add(key);
            expect(entry.reason.length).toBeGreaterThanOrEqual(30);
        }
    });

    it("REVERSE_SWEEP_EXEMPTIONS の登録先が stale になっていない (今も候補として検出され続けている)", () => {
        const registered = registeredTsKeys();
        const found = findUnregisteredMirrorCandidates(
            requireCatalog().resolved,
            requireTsCandidates(),
            (file, name) => registered.has(`${file}::${name}`),
        );
        const foundKeys = new Set(found.map((f) => reverseSweepKey(f.php.path, f.candidate.file, f.candidate.name, f.rule)));

        const stale = REVERSE_SWEEP_EXEMPTIONS.filter(
            (e) => !foundKeys.has(reverseSweepKey(e.php, e.file, e.declaration, e.rule)),
        );

        expect(
            stale,
            `REVERSE_SWEEP_EXEMPTIONS の登録が実態と食い違っている (削除するか登録し直すこと):\n${stale.map((e) => `${e.php} <-> ${e.file}::${e.declaration}`).join("\n")}`,
        ).toEqual([]);
    });
});
```
### tests/js/architecture/enum-ts-sync.test.ts (負の対照の抜粋)
```ts
describe("validateMirrors() の負のコントロール (実リポジトリを根にする)", () => {
    const valid: EnumTsMirror = {
        php: "app/Enums/Manual/RenderKind.php",
        ts: "resources/js/types/manual.ts",
        declaration: "RenderKind",
        note: "負のコントロール用の正常な行",
    };

    it("正常な行は通る", () => {
        expect(() => validateMirrors([valid])).not.toThrow();
    });

    it("app/ の外の php は拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "config/app.php" }])).toThrow("app/ 配下だけ");
    });

    it("resources/js/ の外の ts は拒否する", () => {
        expect(() => validateMirrors([{ ...valid, ts: "tests/js/setup.ts" }])).toThrow("resources/js/ 配下だけ");
    });

    it("絶対パスは拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: path.join(REPO_ROOT, valid.php) }])).toThrow(
            "絶対パスは登録できません",
        );
    });

    it("逆斜線を含むパスは拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app\\Enums\\Manual\\RenderKind.php" }])).toThrow(
            "逆斜線を含むパス",
        );
    });

    it(".. を含むパスは拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app/../app/Enums/Manual/RenderKind.php" }])).toThrow(
            ". / .. / 空の区間",
        );
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
    "exclude": [
        "node_modules",
        "tmp",
        "tests/js/support/enum-ts-sync/fixtures/**"
    ]
}
```
### packages/cli/src/api/schemas.ts (符号一覧)
```ts
/**
 * Canonical `error.code` strings emitted by the v1 REST API (C1 / T144).
 *
 * Mirrors `app/Enums/ApiErrorCode.php` plus the controller-local codes
 * that rely on the same envelope shape. Keep this list in sync by hand —
 * the schema contract test (`tests/api/schemas-contract.test.ts`) catches
 * drift by round-tripping real API responses through these schemas.
 *
 * Unknown `error.code` values are not rejected: consumers should fall
 * back to HTTP status when the CLI is older than the server.
 */
export const API_ERROR_CODES = [
    "unauthenticated",
    "forbidden",
    "not_found",
    "validation_failed",
    "rate_limit_exceeded",
    "quota_exceeded",
    "idempotency_conflict",
    "internal_server_error",
    // Controller-local codes (AuditSubmissionController / SitePagesBulkController
    // / EvaluationExecutionController) layered on top of the canonical enum.
    "payload_sanitization_failed",
    "site_not_cli_capture",
    "use_audits_submit",
] as const;

export type ApiErrorCode = (typeof API_ERROR_CODES)[number];

const ApiErrorBodySchema = z
    .object({
        error: z
            .object({
                // Left as a plain string to tolerate server-side additions
                // the CLI has not learned about yet; the `ApiErrorCode`
                // union above is the authoritative CLI-side list.
                code: z.string(),
                message: z.string(),
```
### packages/cli/src/api/client.ts (dispatchKindFromCode)
```ts
}

/**
 * Map a canonical `error.code` to the CLI-side failure discriminator.
 * Returns `null` for unknown codes so the caller falls back to the
 * status-based mapping below. Keeping these arms narrow (one case per
 * known code) is deliberate: silent aliasing in this table was the
 * pre-T144 bug that motivated C1 in the first place.
 */
function dispatchKindFromCode(
    code: string | null,
): Exclude<ApiCallFailure["kind"], "network" | "schema"> | null {
    if (code === null) return null;
    const narrowed = code as ApiErrorCode | string;
    switch (narrowed) {
        case "unauthenticated":
        case "forbidden":
            return "auth";
        case "not_found":
            return "not-found";
        case "rate_limit_exceeded":
            return "rate-limit";
        case "quota_exceeded":
            return "quota";
        case "idempotency_conflict":
            return "conflict";
        case "validation_failed":
        case "payload_sanitization_failed":
        case "site_not_cli_capture":
        case "use_audits_submit":
            return "validation";
        case "internal_server_error":
            return "server";
        default:
            return null;
    }
}

/**
 * Fallback dispatch for responses whose envelope did not carry a
 * recognisable `error.code`. Retains the pre-T144 HTTP-status mapping
```
### app/Enums/ApiErrorCode.php (case のみ)
```php
13:enum ApiErrorCode: string
15:    case Unauthenticated = 'unauthenticated';
16:    case Forbidden = 'forbidden';
22:    case InsufficientAbility = 'insufficient_ability';
27:    case ActorNotResolvable = 'actor_not_resolvable';
28:    case NotFound = 'not_found';
29:    case ValidationFailed = 'validation_failed';
30:    case RateLimited = 'rate_limited';
32:    case IdempotencyConflict = 'idempotency_conflict';
34:    case IdempotencyInProgress = 'idempotency_in_progress';
36:    case IdempotencyIndeterminate = 'idempotency_indeterminate';
37:    case InternalServerError = 'internal_server_error';
```
### 実測ログ

# 実測ログ (probe.ts。設計時 2026-08-24)

判定式・母集団・派生除外 (証人は対応表キー以外の候補に限る) は概念設計の決着 1/2 と同じ形。
`excluded` = 構文を壊した見本 (`fixtures/candidates-broken/`) だけを母集団から外す。
`included` = その除外もしない (= 除外が要る理由の実測)。
集計は probe 内で `total === 各形の合計` を assert してある。

```
# mode=excluded
tracked .ts=379 .svelte=130
population .ts=378 .svelte=130
php resolved=123 unresolvable=3
program build ms=2953 sourceFiles=5859
derived(object-keys)=63 witnessed(excluded)=10 witnessless(kept)=53
broken syntax files=0 
candidates total=304 {"union":106,"object-keys":163,"switch-cases":13,"const-array":22}
hits total=8 {"1":6,"2b":1,"2a":1}
  [rule 1] app/Enums/Manual/CutType.php <-> resources/js/components/features/manual/ScenarioEditor.svelte:401::DragOwner (union) exact
  [rule 1] app/Enums/Notification/NotificationType.php <-> resources/js/components/features/notifications/NotificationListItem.svelte:67::switch:notification.type (switch-cases) exact
  [rule 1] app/Enums/ApiKeyAbility.php <-> resources/js/pages/Organizations/ApiKeys/Index.svelte:61::ABILITY_LABELS (object-keys) exact
  [rule 1] app/Enums/OAuth/OAuthClientKind.php <-> resources/js/pages/Organizations/ApiKeys/Sessions.svelte:41::CLIENT_KIND_LABELS (object-keys) exact
  [rule 1] app/Enums/Manual/TakeStatus.php <-> resources/js/types/manual.ts:409::SelectableTakeStatus (union) exact
  [rule 1] app/Enums/EnterpriseSso/OidcConnectionStatus.php <-> tests/js/components/features/sso/oidc-connection.test.ts:17::ALL_STATUSES (const-array) exact
  [rule 2a] app/Enums/ApiErrorCode.php <-> packages/cli/src/api/schemas.ts:310::ApiErrorCode (union) apierrorcode = apierrorcode
  [rule 2b] app/Enums/Manual/JobStatus.php <-> resources/js/types/dashboard.ts:10::DashboardJobStatus (union) words[job+statu] head=statu

# mode=included
tracked .ts=379 .svelte=130
population .ts=379 .svelte=130
php resolved=123 unresolvable=3
program build ms=3125 sourceFiles=5860
derived(object-keys)=63 witnessed(excluded)=10 witnessless(kept)=53
broken syntax files=1 tests/js/support/enum-ts-sync/fixtures/candidates-broken/broken.ts
candidates total=304 {"union":106,"object-keys":163,"switch-cases":13,"const-array":22}
hits total=8 {"1":6,"2b":1,"2a":1}
  [rule 1] app/Enums/Manual/CutType.php <-> resources/js/components/features/manual/ScenarioEditor.svelte:401::DragOwner (union) exact
  [rule 1] app/Enums/Notification/NotificationType.php <-> resources/js/components/features/notifications/NotificationListItem.svelte:67::switch:notification.type (switch-cases) exact
  [rule 1] app/Enums/ApiKeyAbility.php <-> resources/js/pages/Organizations/ApiKeys/Index.svelte:61::ABILITY_LABELS (object-keys) exact
  [rule 1] app/Enums/OAuth/OAuthClientKind.php <-> resources/js/pages/Organizations/ApiKeys/Sessions.svelte:41::CLIENT_KIND_LABELS (object-keys) exact
  [rule 1] app/Enums/Manual/TakeStatus.php <-> resources/js/types/manual.ts:409::SelectableTakeStatus (union) exact
  [rule 1] app/Enums/EnterpriseSso/OidcConnectionStatus.php <-> tests/js/components/features/sso/oidc-connection.test.ts:17::ALL_STATUSES (const-array) exact
  [rule 2a] app/Enums/ApiErrorCode.php <-> packages/cli/src/api/schemas.ts:310::ApiErrorCode (union) apierrorcode = apierrorcode
  [rule 2b] app/Enums/Manual/JobStatus.php <-> resources/js/types/dashboard.ts:10::DashboardJobStatus (union) words[job+statu] head=statu
```
