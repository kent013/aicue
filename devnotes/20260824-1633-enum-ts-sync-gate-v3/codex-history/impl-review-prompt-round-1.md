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

あなたは Laravel + Svelte アプリ (aicue) の改善実装をレビューするコードレビュアーである。
本変更は **静的検査 gate (走査器) の作り替え**であり、UI の変更を含まない。

## レビュー観点

1. **設計との一致性** — 詳細設計の S1〜S13 と「実装の順序」表の意図が実装に落ちているか
2. **正確性** — 走査・判定の論理に見落とし・取りこぼし (偽陰性) が無いか。とくに
   fail-closed (解決できない形を落とす) が本当に閉じているか
3. **AGENTS.md §静的検査 (gate) と走査器の共通規約 (a)〜(e)** への適合
   - (b) 未解決を解決済みと同じ値へ混ぜない / 母集団 0 件と違反 0 件を区別する
   - (c) 検出力は負例で裏取りする (両方向)
   - (d) 集めた走査結果を判定に使わない形を作らない
   - (e) 語彙一致の否定形はトークンの完全一致で判定し、区切りを宣言する
4. **AGENTS.md §走査器・gate を新設・変更するときに同じ PR で揃える 4 点**
5. **テスト網羅性** — 負例・故障注入が「その分岐」を本当に押さえているか
6. **セキュリティ** — 該当は薄いが、道具パッケージの OAuth スコープ縮小の影響
7. **保証範囲の誇張** — docblock / 文書が実装より強い保証を主張していないか
8. **後方互換の並走を残していないか** (思考原則 3)

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する
- 最後に全体判定を **APPROVED** か **CHANGES_REQUESTED** で書く

---

# user

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

## 概念設計 (決着のみ抜粋)

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

## 実装差分 (git diff --cached)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 203fde34..2c1a2f58 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -1018,26 +1018,41 @@ ## ドメイン固有規約
       キュークラス) は母集団に入らない。**保証しないものの正本は
       `docs/architecture.md` §退避を正常系に持つジョブの終端方式**
       (ここは要約であり、増減はそちらで管理する)。
-19. **PHP 列挙 ⇔ TypeScript 値域の同期の登録 (T218/T225 / 家系の裁定 AG-099)**:
-    PHP の文字列付き列挙の値を TS の型別名で受ける箇所を作ったら、
-    `tests/js/support/enum-ts-sync/mirror-inventory.ts` の `ENUM_TS_MIRRORS` へ
+19. **PHP 列挙 ⇔ TypeScript 値域の同期の登録 (T218/T225/T261 / 家系の機能台帳
+    `enum-ts-sync-gate` の正典 v3)**:
+    PHP の文字列付き列挙の値を TS で受ける箇所を作ったら、
+    `tests/js/support/enum-ts-sync/relation-inventory.ts` の `ENUM_TS_RELATIONS` へ
     1 行足し、件数の pin も 1 増やす。**個別の同期テストのファイルを増やさない**
     (増殖を止めるのが本 gate の目的)。
-    - 受理する形は**型別名の宣言**で、解決した型が**文字列リテラル型だけ**であること
+    - 受理する形は**型別名の宣言**か **`const` の配列** (`as const` の有無を問わない) で、
+      前者は解決した型が**文字列リテラル型だけ**であること
       (別名参照・`keyof typeof`・有限のテンプレートリテラル型は解決されるので受理する)。
+      後者は**構文から**読む (素の配列は型検査器の上で `string[]` に広がるため)。
+      **対応表のキーと分岐のラベルは登録できない** — 写しなら型別名か定数の配列へ切り出す。
       PHP 側は深さ 0 の `enum X: string` がちょうど 1 つで、本体直下の case が
       `case Name = '値';` の 1 行に一致すること
+    - **登録できる TS の置き場**は `resources/js/` と `packages/<名前>/src/` で、
+      拡張子は `.ts` と `.svelte` (`tests/js/` と `packages/<名前>/tests/` は置き場ではない)
+    - **関係は 2 つ**ある。`equal` は**値域そのものの写し**、
+      `subset` は**値域の写しではなく、許される値域から選んだ非空の集合**である
+      (例: 道具が既定で要求する権限)。`subset` の行には `subsetReason` に
+      **なぜ値域の写しではないのか**を 30 文字以上で書く (`note` ではない)
     - **`app/` の文字列付き列挙は全数走査で既定拒否される**
       (`tests/js/architecture/enum-ts-sync-discovery.test.ts`)。TS 側に写しを作らない
       判断をしたら `PHP_ENUM_EXEMPTIONS` へ理由 (30 文字以上) 付きで登録すること。
       **未分類のまま残すと gate が赤くなる**
-    - **TS 側も全数走査で逆走査する** (同ファイル)。値集合が PHP 列挙と完全一致する、
-      または名前が対応し値が交差する未登録の TS 宣言が見つかったら
-      `REVERSE_SWEEP_EXEMPTIONS` へ理由付きで登録するか、`ENUM_TS_MIRRORS` へ登録すること
+    - **TS 側も全数走査で逆走査する** (同ファイル)。走査範囲は**版管理下の `*.ts` と
+      `*.svelte` の全数** (除くのは検出器自身の構文破壊見本 1 ディレクトリだけ) で、
+      拾う形は**リテラル型の合併 / 定数の配列 / 対応表のキー / 分岐のラベル**の 4 種である。
+      値集合が PHP 列挙と完全一致する、または名前が対応し値が交差する未登録の宣言が
+      見つかったら `REVERSE_SWEEP_EXEMPTIONS` へ理由付きで登録するか、
+      `ENUM_TS_RELATIONS` へ登録すること。候補かどうかを**決められなかった**宣言は
+      `KNOWN_INDETERMINATE_TS_DECLARATIONS` へ理由付きで登録する (非候補と混ぜない)
     - **正本のレーンは `pnpm test`** (CI の frontend job) である。
       `composer test` だけでは値集合の同期は検証されない
     - **保証しないものの正本は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期**
-      であり、本書には写さない (2 か所に書くと必ず食い違う)
+      であり、本書には写さない (2 か所に書くと必ず食い違う)。件数も写さない
+      (正本は目録側の pin)
 20. **file input の accept 供給元の宣言 (T235)**: `resources/js` 配下の `.svelte` に
     file input を足したら、`tests/js/support/file-input-accept-inventory.ts` の
     `FILE_INPUT_ACCEPT_INVENTORY` へ 1 行足し、件数の pin も 1 増やす
diff --git a/docs/architecture.md b/docs/architecture.md
index 2f85ef95..f02ab39b 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -3086,102 +3086,212 @@ ### 保証しないもの (誇張しない。**本節が正本**)
 - **認可コードの交換時に所属を確認してはいない**。閉じているのは「失効の時点で未交換だった
   コードを撃つ」ところまでである (後続の候補)。
 
-## PHP 列挙と TypeScript 値域の同期 (T218 / 家系の裁定 AG-099 前半)
+## PHP 列挙と TypeScript 値域の同期 (T218 / T225 / T261。家系の機能台帳 `enum-ts-sync-gate` 正典 v3)
 
-サーバの語彙 (PHP の文字列付き列挙) を画面が受けるとき、TS 側は型別名の値域として
-同じ集合を持つ。片方だけ増えると画面の分岐に「どこにも当たらない値」が生まれ、
-**無言の描画漏れ**になる。これを 1 本の汎用 gate
-(`tests/js/architecture/enum-ts-sync.test.ts`) で固定する。
+サーバの語彙 (PHP の文字列付き列挙) を画面と付属の道具が受けるとき、TS 側は
+同じ集合を値域として持つ。片方だけ増えると分岐に「どこにも当たらない値」が生まれ、
+**無言の描画漏れ**になる。しかも**どちらの側も単体では整合している**ので、
+型検査でも通常のテストでも落ちない。これを 2 本の gate で固定する。
 
-- **登録の仕方**: 目録 `ENUM_TS_MIRRORS` へ 1 行 (PHP のパス / TS のパス / 型別名 / 理由) を足し、
-  件数の pin `EXPECTED_MIRROR_COUNT` を 1 増やす。**個別の検査ファイルは増やさない**
+| 向き | 検査 | 見るもの |
+|---|---|---|
+| 前向き | `tests/js/architecture/enum-ts-sync.test.ts` | 目録に**登録した関係**が成り立つこと |
+| 逆走査 | `tests/js/architecture/enum-ts-sync-discovery.test.ts` | **登録し忘れ**と**判定保留**が 0 件であること |
+
+### 目録 (単一の出典) と関係の 2 値
+
+- **登録の仕方**: 目録 `ENUM_TS_RELATIONS`
+  (`tests/js/support/enum-ts-sync/relation-inventory.ts`) へ 1 行足し、
+  件数の pin `EXPECTED_RELATION_COUNT` を 1 増やす。**個別の検査ファイルは増やさない**
   (裁定 AG-099 が止めたかったのは検査の増殖である)。
-  `note` に最小文字数は課さない — 本目録は**免除の申告ではなく登録**であり、
-  「検査から外す」判断ではないため。
-- **受理する形 (TS 側)**: 対象ファイルのトップレベルに、その名前の型別名の宣言が
-  ちょうど 1 つあり、**解決・正規化された後の型**が文字列リテラル型だけの union
-  (または単独の文字列リテラル型) であること。別名参照・import 越しの参照・
-  `keyof typeof`・`Lowercase<…>`・具体化された条件型・有限のテンプレートリテラル型は
-  すべて受理する (型検査器が畳んだ後を見るため)。TypeScript の `enum` の値は受理しない
-  (本リポジトリに 1 件も無く、文字列リテラル型と同じ契約ではない。必要になってから広げる)。
+  目録は前向きと逆走査の**両方**が読む単一の出典である。
+- **`equal` と `subset` は同じ目録に載るが意味が違う**。
+  `equal` は**値域そのものの写し**で双方向の差分が空であること、
+  `subset` は**値域の写しではなく、許される値域から選んだ非空の集合**で
+  「TS 側にだけある値が無い」ことだけを見る。
+  **許可する値域と、そこから選んだ集合は別の概念である** — 例えばサーバの
+  `App\Enums\OAuth\CliOAuthScope` は「サーバが認識する全スコープ」、道具側の
+  `DEFAULT_CLI_SCOPES` は「道具が既定で要求する権限」である。完全一致で登録すると
+  「サーバにスコープを足したら道具も要求する」方向へ設計が引っ張られ最小権限に反する。
+  `subset` の登録は**前者を後者へ広げないための装置**であり、
+  `subsetReason` (30 文字以上) に**なぜ値域の写しではないのか**を書く。
+  **`subset` は逃げ道になり得る** (完全一致の写しを `subset` と偽れば緩む)。
+  機械では見分けられないので、`subsetReason` の記述とレビューで担保する。
+- **登録できる TS の置き場**は `resources/js/` (画面側) と `packages/<名前>/src/`
+  (付属のコマンドライン道具) で、拡張子は `.ts` と `.svelte`。
+  `tests/js/` と `packages/<名前>/tests/` は登録の置き場ではない
+  (検査の見本を写しとして登録しない)。
+- **受理する形 (TS 側) は 2 つ**である。対象ファイルの**最上位**にある
+  **型別名の宣言** (解決した型が文字列リテラル型だけ) か、**`const` 束縛の配列**
+  (`as const` の有無を問わない)。同じ名前で受理できる宣言がちょうど 1 つあること。
+  別名参照・import 越しの参照・`keyof typeof`・`Lowercase<…>`・具体化された条件型・
+  有限のテンプレートリテラル型はすべて受理する (型検査器が畳んだ後を見るため)。
+  TypeScript の `enum` の値は受理しない。
+  **配列の値は構文から読む** — `const X = ["a", "b"];` は型検査器の上では `string[]` に
+  広げられるので、型から要素を復元してはいけない。
+  **対応表のキーと分岐のラベルは登録できない**。写しとして扱うなら型別名か
+  定数の配列へ切り出す。
 - **受理する形 (PHP 側)**: 深さ 0 の `enum <名前>: string` がちょうど 1 つあり、
   その名前がファイル名の語幹と一致し、本体の直下の `case` が
   `case Name = '値';` / `case Name = "値";` の 1 行に一致すること。
   定数式・逆斜線・変数の埋め込み・複数行の case は例外にする。
-- **program は tsconfig が含む TS 全体で作る**。目録のファイルだけを起点にすると、
-  `include` だけで参加する宣言 (周囲宣言 / `declare global` / モジュールの拡張) が載らず
-  **本番の型と違う型世界**で判定してしまう (偽陰性)。速さのために起点を縮めない。
-  縮める改変が入ったら `enum-ts-sync-extractor.test.ts` の T25 が赤くなる。
-- **抽出器が静かに間違えないこと**は `tests/js/architecture/enum-ts-sync-extractor.test.ts` の
-  負例行列 (TS 27 件 / PHP 40 件) が固定する。見本の置き方は非対称で、
-  TS は**ファイル** (型検査器に実ファイルが要る。`tsconfig.json` の `exclude` で
-  `pnpm typecheck` の対象から外す)、PHP は**テスト内の文字列** (`.php` として置くと
-  strict_types 宣言 gate / 禁止文の字句走査 / Pint / PHPStan の母集団に入るため)。
-
-## 発見の段と逆走査 (T225 / 家系の裁定 AG-099 後半)
-
-`enum-ts-sync.test.ts` は目録に登録した写しだけを見る (未登録は沈黙する)。この欠落を
-`tests/js/architecture/enum-ts-sync-discovery.test.ts` が向きを変えて埋める
-(`docs/template-divergence.md` の D29 はこの実装で再判定条件を満たし、登録を削除した)。
-
-- **発見の段 (全数走査 → 既定拒否の分類)**: `buildPhpEnumCatalog()`
-  (`tests/js/support/enum-ts-sync/php-enum-catalog.ts`) が `app/` 配下の git 追跡下の
-  `*.php` を全数走査する。抽出器は既存の `readPhpEnumValuesFromText` が使う字句走査器を
-  `detectEnumHeaders` として共有し (**2 本目の抽出器を作らない**)、値集合を読めたもの
-  (`resolved`) と読めなかったもの (`unresolvable`) に分ける。`resolved` の**すべて**が
-  「登録済み (`ENUM_TS_MIRRORS`)」か「対象外の理由つき (`PHP_ENUM_EXEMPTIONS`。理由は
-  30 文字以上)」のどちらか一方に分類されていることを固定する。`unresolvable` の
-  **すべて**が `KNOWN_UNRESOLVABLE_PHP_ENUMS` に登録されていることを固定する。
-  どの分類にも入らない PHP 列挙が 1 件でもあれば赤くする (既定拒否)。登録先が実態と
-  食い違った (stale) ときも赤くする。
-  - `scan()` が拒否する字句 (バッククォート・ヒアドキュメント等) を含むファイルは、
-    生のソースに **`enum` の語が無ければ**母集団から外し、**あれば**
-    (直後の並びを問わず。コメントを挟む書き方・非 ASCII 識別子も見逃さない)
-    安全側に倒して `unresolvable` へ回す (取りこぼしを作らない側に倒す。実測では
-    ヒアドキュメントを持ちつつ docblock で「enum」に言及するだけの
-    `app/Mcp/Servers/AppMcpServer.php` がここで意図した過剰検出になる)。
-  - **波括弧付き namespace 宣言** (`namespace Foo { … }`。無名・大文字小文字・
-    コメントの割り込みを問わない) の中は `enum` 宣言の波括弧の深さが 1 になり、
-    「深さ 0」の前提が崩れる。**個別の namespace 構文を正規表現で当てるのではなく**、
-    `detectEnumHeaders` が返す**深さ付きの enum 候補**を見て、**深さ 0 でない候補が
-    1 件でも混ざっていれば**安全側で `unresolvable` へ回す (深さ 0 の候補だけを拾って
-    残りを黙って捨てると、同じファイルの別の深さ 0 enum の影に隠れて消えてしまう。
-    どんな書き方で深さがずれても同じ 1 つの判定で拾える。本リポジトリは波括弧無しの
-    namespace 宣言 (`namespace Foo;`) だけを使っており、現時点で該当ファイルは 0 件)。
-- **逆走査 (未登録候補の検出。2 規則)**: `collectTsUnionCandidates()`
-  (`tests/js/support/enum-ts-sync/ts-candidates.ts`) が `resources/js/` 配下の
-  文字列リテラル型だけの union に解決するトップレベルの型別名を全数走査する。
-  母集団は `tsconfig.json` の `include` (`resources/js/**` 配下の `*.ts`) が実際に
-  決めるが、**それだけを出典とは言わない** — `resources/js/` をプログラムを介さず
-  直接再帰的に歩いた `*.ts` (`.d.ts` を除く) の集合と、program に載った集合が
-  **完全一致すること**を独立実装の回帰テストで固定しており、この一致こそが
-  「登録済みファイルの import グラフに閉じない・tsconfig の `exclude` が
-  意図せず広がっていない」という不変条件の実体である
-  (`createMirrorProgram` の rootNames が tsconfig の全ファイルを含むことにも依存しない)。
-  走査対象ファイルの構文が壊れているときは無言で読み飛ばさず例外にする (fail-closed)。
-  `findUnregisteredMirrorCandidates()` (`tests/js/support/enum-ts-sync/reverse-sweep.ts`)
-  が未登録の宣言を PHP の母集団 (`resolved`。分類にかかわらず全件) と突き合わせる。
+- **登録行の locator は AST から解決する**。目録の行が持つのは `ts + declaration` だけで、
+  候補の同一性に要る**形**と**出現順**が無い。同名の入れ子の宣言が最上位より前にあると
+  最上位でも出現順は 0 とは限らないため、**逆走査の候補と同じ採番器**で locator を作り、
+  逆走査の「登録済み」の判定は **locator の完全一致**で行う (採番の実装を 2 本持たない)。
+
+### program はパッケージごとに作る
+
+**`packages/<名前>` をルートの設定 (bundler / ESNext) で読まない**。読むと NodeNext 前提の
+取り込みが解決できず、型が `any` に落ちた宣言が「文字列リテラル型ではない = 非候補」として
+**静かに消える**。「本番と同じ型世界」は、道具パッケージにとっては
+**そのパッケージ自身の tsconfig** である。したがって program を複数本持つ。
+
+| program | 起点 |
+|---|---|
+| `<root>` | ルート `tsconfig.json` の全ファイル ∪ どのパッケージにも属さない版管理下の `*.ts` ∪ 仮想 `.svelte` |
+| `packages/<名前>` (tsconfig を持つものだけ) | そのパッケージの tsconfig の全ファイル ∪ 配下の版管理下の `*.ts` ∪ 配下の仮想 `.svelte` |
+
+- 起点を**速さのために縮めない** (`include` だけで参加する周囲宣言 / `declare global` /
+  モジュールの拡張が載らないと本番と違う型世界になる。縮める改変が入ったら
+  `enum-ts-sync-extractor.test.ts` の T25 が赤くなる)。
+- **母集団の全件が「所有者」をちょうど 1 つ持つ**ことを検査する。tsconfig を持たない
+  パッケージのファイルはどの program にも載らず、この検査が赤くなる (fail-closed)。
+- **候補走査は「所有者の program 上の `SourceFile`」だけを使う**
+  (`program.getSourceFiles()` 全体は依存ライブラリ・推移的な取り込み・JSON が載るので
+  母集団の一致根拠にしない)。
+
+### `.svelte` は 1 つの仮想 TS へ平坦化する
+
+`.svelte` は第一級の解析対象である。`svelte/compiler` の `parse` で script の範囲を取り、
+**script の中身以外を空白で潰した**仮想 TypeScript を **1 ファイルにつき 1 本**作る。
+潰すときに UTF-16 の符号単位の数を変えないので**行も列も元ファイルと一致する**。
+末尾に `export {};` を足して**モジュール文脈**にする (付けないと大域スクリプトになり、
+取り込みも書き出しも無いコンポーネント同士の宣言が混ざって偽の候補が立つ)。
+
+**文脈ごとに別ファイルへ割らない** (割ると module の宣言を実体側から参照できなくなる。
+Svelte では参照できる)。代わりに、平坦化で再現できない 2 つを
+**保証外にせず不合格条件として塞ぐ**:
+
+| 食い違い | Svelte 本来 | 平坦化した TS | 対処 |
+|---|---|---|---|
+| module から実体側の宣言を参照 | 見えない | 前方参照として解決する | **不合格** |
+| module と実体に同名の最上位束縛 | 実体側が覆う | 重複宣言になる | **不合格** |
+| 実体から module の宣言を参照 | 見える | 解決する | 正しいので許す |
+
+検査の呼び出し義務は利用側に無い — program を組む一本道が内部で必ず走らせ、
+低層の組み立て関数は輸出しないので検査を飛ばした program を外から作れない。
+**`.svelte` 全体が `parse` できることは前提**であり、script の外
+(目印の中・制御構文の中・スタイル) は候補にしない。
+
+### 逆走査 (母集団の全数 → 4 形の候補 → 3 規則)
+
+- **母集団**: `git ls-files` が返す**版管理下の `*.ts` と `*.svelte` の全数**。
+  **唯一の除外**は検出器自身の構文破壊見本 1 ディレクトリ
+  (`tests/js/support/enum-ts-sync/fixtures/candidates-broken/`) で、除外根は
+  `tests/js/support/enum-ts-sync/` の配下に限り、**件数を pin** し、
+  **配下の全ファイルが実際に本番と同じ入口で落ちること**を検査する
+  (これが「除外根へ正常なファイルを置いて母集団から静かに消す」経路を塞ぐ)。
+  型世界に載せる起点は `.d.ts` を**含み**、候補を探す対象は `.d.ts` を**除く**。
+  どちらかが 0 件なら例外にする。
+- **候補の形は 4 種**: リテラル型の合併 / 定数の配列 / 対応表のキー / 分岐のラベル。
+  **入れ子の宣言も拾う**ので、候補の同一性は「置き場・形・名前・出現順」の 4 つ組
+  (locator) で持つ。**行は診断にだけ使う** (同一性に入れると無関係な行移動で
+  申告が一斉に stale になる)。**採番は三値の分類より前に**構文上の宣言の場所の全体へ行う。
+- **三値にする**: 「候補かどうかを決められない」(`any` / `unknown` へ解決したが構文が
+  その綴りではない) を非候補と混ぜず、**判定保留**として
+  `KNOWN_INDETERMINATE_TS_DECLARATIONS` の既定拒否の申告で受ける。
+- **派生の除外**: 対応表のキーは、明示の型があり・文字列の添字シグネチャが無く・
+  プロパティが 1 件以上ですべて必須で・書かれたキーが必須プロパティと集合として一致し・
+  **`object-keys` 以外の形の候補に同じ値集合の証人がある**ときだけ外す。
+  証人の資格を「派生除外の対象になり得ない形」に限るのは**循環の遮断**である
+  (任意の候補を証人にすると、同じキー集合の対応表が互いを証人にして両方消える)。
+- **規則は 3 つ**で、判定は排他 (完全一致 → 交差 0 なら無視 → 名前不明なら判定不能 →
+  2a → 2b の順):
   - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全一致する未登録の宣言 = 登録漏れの疑い。
-  - **規則 2 (名前対応 + 値の交差)**: 名前が厳密に対応し (大文字小文字の違いを除く
-    一致 / `+s` / `+es` / `+values`。**英数字以外を除去する正規化はしない**。
-    `Foo_Bar` と `FooBar` を同一視すると要件より緩くなるため) 値が交差するが
-    完全一致ではない未登録の宣言 = 片方だけ値を足してズレた写しの疑い。
-    緩い名前対応 (部分集合・ファイル名を名前に混ぜる形) は採らない
-    (家系の実測で偽陽性が支配的になったため)。判定は `ResolvedPhpEnum.name`
-    (抽出器が読んだ enum 宣言の名前) を使い、ファイル名の語幹からの再計算はしない。
-  - 見つかった候補は `REVERSE_SWEEP_EXEMPTIONS`
-    (`php` + `file` + `declaration` + `rule` の組で固定) に登録された分だけ許す。
-    未登録の候補が 1 件でもあれば赤くする。登録先が実態と食い違ったときも赤くする。
-
-### 保証しないもの (誇張しない。発見の段・逆走査を含む)
+  - **規則 2a (厳密名対応 + 1 値以上の交差)**: 小文字化して一致 / `+s` / `+es` / `+values`。
+    **英数字以外を除去する正規化はしない** (`Foo_Bar` と `FooBar` を 2a では同一視しない)。
+  - **規則 2b (語分割名対応 + 両側から見て半分以上の交差)**: 名前を語に割り、
+    主要語 (語列の末尾) が対応し、列挙の語と候補の語袋の**最大マッチング**が
+    `min(2, 列挙の語数)` 以上であること。**規則 2 は 2a と 2b の論理和**であり、
+    どちらの式も他方を包含しない (本リポジトリの実測が家系の未決論点 q2 への一次観測)。
+  - 語の正規化は**1 つの正規形へ畳まない**。接尾辞だけで畳むと `cases → cas` /
+    `uses → us` のような誤った語幹を正規形にしてしまうので、語ごとに候補形の集合を作り
+    **集合が交われば同じ語**とみなす (過剰検出の向き)。
+- **名前を決められない候補**: 分岐のラベルは判定対象の**型の名前**を優先し、
+  取れなければ識別子とプロパティ参照の連なりだけを名前に使う。どちらも取れないときは
+  規則 2 を判定できないので、**列挙と 1 値でも交差するなら判定不能として gate を赤くする**
+  (交差 0 なら規則 2 の対象になり得ないので黙って通す)。
+  候補から静かに落とすと、完全一致しない真の部分写しが規則 1 にも規則 2 にも掛からず
+  無言で通過する。
+- 見つかった候補は `REVERSE_SWEEP_EXEMPTIONS` (`php` + locator + 規則の組で固定) に
+  登録された分だけ許す。**申告の生死判定は「免除を適用する前」の候補集合に対して行う**
+  (免除適用後で判定すると、申告が自分自身を根拠にして永久に生き続ける)。
+
+### 発見の段 (PHP 側の全数走査 → 既定拒否の分類)
+
+`buildPhpEnumCatalog()` (`tests/js/support/enum-ts-sync/php-enum-catalog.ts`) が
+`app/` 配下の git 追跡下の `*.php` を全数走査する。抽出器は既存の
+`readPhpEnumValuesFromText` が使う字句走査器を `detectEnumHeaders` として共有し
+(**2 本目の抽出器を作らない**)、値集合を読めたもの (`resolved`) と読めなかったもの
+(`unresolvable`) に分ける。`resolved` の**すべて**が「TS との関係を登録済み
+(`ENUM_TS_RELATIONS`)」か「対象外の理由つき (`PHP_ENUM_EXEMPTIONS`。理由は 30 文字以上)」の
+どちらか一方に分類されていることを固定する (分類の呼び名を「登録済み」ではなく
+「TS との関係を登録済み」とするのは、`subset` の行を「写し」と言えないためである)。
+`unresolvable` の**すべて**が `KNOWN_UNRESOLVABLE_PHP_ENUMS` に登録されていることを固定する。
+どの分類にも入らない PHP 列挙が 1 件でもあれば赤くする (既定拒否)。登録先が実態と
+食い違った (stale) ときも赤くする。
+
+- `scan()` が拒否する字句 (バッククォート・ヒアドキュメント等) を含むファイルは、
+  生のソースに **`enum` の語が無ければ**母集団から外し、**あれば**
+  (直後の並びを問わず。コメントを挟む書き方・非 ASCII 識別子も見逃さない)
+  安全側に倒して `unresolvable` へ回す (取りこぼしを作らない側に倒す。実測では
+  ヒアドキュメントを持ちつつ docblock で「enum」に言及するだけの
+  `app/Mcp/Servers/AppMcpServer.php` がここで意図した過剰検出になる)。
+- **波括弧付き namespace 宣言** (`namespace Foo { … }`。無名・大文字小文字・
+  コメントの割り込みを問わない) の中は `enum` 宣言の波括弧の深さが 1 になり、
+  「深さ 0」の前提が崩れる。**個別の namespace 構文を正規表現で当てるのではなく**、
+  `detectEnumHeaders` が返す**深さ付きの enum 候補**を見て、**深さ 0 でない候補が
+  1 件でも混ざっていれば**安全側で `unresolvable` へ回す (深さ 0 の候補だけを拾って
+  残りを黙って捨てると、同じファイルの別の深さ 0 enum の影に隠れて消えてしまう)。
+
+### 抽出器が静かに間違えないことの裏取り
+
+- 前向きの受理・拒否の境界は `tests/js/architecture/enum-ts-sync-extractor.test.ts` の
+  負例行列 (TS 27 件 / PHP 40 件) が固定する。
+- 逆走査の抽出器・純関数の境界と故障注入の受け皿は
+  `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts` が持つ。
+- 見本の置き方は非対称である。TS は**ファイル** (型検査器に実ファイルが要る。
+  `tsconfig.json` の `exclude` で `pnpm typecheck` の対象から外す)、PHP は
+  **テスト内の文字列** (`.php` として置くと strict_types 宣言 gate / 禁止文の字句走査 /
+  Pint / PHPStan の母集団に入るため)。
+  **不正な TS / `.svelte` の入力は追跡ファイルにしない** — 母集団に入って本番の gate が
+  恒久的に赤くなるので、テストの中の文字列として渡す。
+  逆に `fixtures/` の正常な見本は**母集団に入る**ので、見本の値は現物の列挙と
+  交差しない綴り (`"zzz-…"`) にする。
+
+### 保証しないもの (誇張しない。前向き・発見の段・逆走査を含む)
 
 - **値の集合だけを見る**。表示ラベル・並び順・意味は見ない。
-- **部分集合の関係は表現できない** (完全一致だけ)。
-- `.svelte` の中の宣言・定数配列 (`as const` の配列)・`switch` の case ラベルは読まない。
+- **版管理外のファイル**(無視されたもの・未追跡のもの) は見ない。
+  `.js` / `.mjs` / `.cjs` は母集団に入れない。`.d.ts` は候補にしない。
+- `.svelte` は **script の中だけ**を見る (目印の中の式・制御構文の中・スタイルは見ない)。
+  ただしファイル全体が `parse` できることは前提である。
+- 候補にするのは「**すべての**要素が読める」形だけである
+  (1 つでも読めない要素があれば候補にしない)。
+- 派生として外した対応表は、**証人 (対応表以外の形の候補) がある場合だけ**外れる。
+  証人が無ければ候補として残る (fail-closed)。
+- **分岐のラベルと対応表のキーは登録できない**。写しなら型別名か定数の配列へ切り出す。
+- パッケージの型は**そのパッケージ自身の tsconfig** で解決する
+  (ルートの設定で解決するわけではない)。tsconfig を持たないパッケージは
+  どの program にも載らず、母集団の直和検査が赤くなる。
+- **除外根の中は見ない**。`fixtures/` の残りは**見る**ので、見本を書き換えると
+  本番の候補集合も動く (過剰検出の向きなので許容する)。
+- **`subset` の妥当性は機械では見分けられない** (完全一致の写しを `subset` と偽れば緩む)。
+  `subsetReason` の記述とレビューで担保する。
 - TS 側は**解決・正規化された後の型**で判断するので、ソース上の重複した union
   (`"a" | "a"`) や union の中の `never` は区別できない。**「同じ値が 2 回あると落ちる」とは
-  主張しない**。PHP 側の backing の値の重複だけは抽出器が明示的に落とす
-  (旧テストが配列比較で持っていた保証の引き継ぎ)。
+  主張しない**。PHP 側の backing の値の重複だけは抽出器が明示的に落とす。
 - PHP 側はファイル全体の構文の妥当性・名前空間・オートロード・完全修飾名を検証しない
   (それらは `composer test` と PHPStan の担当)。PHP が受理する構文をすべて受理する
   わけでもない (閉じタグ・バッククォート・ヒアドキュメントは拒否する)。
@@ -3189,15 +3299,8 @@ ### 保証しないもの (誇張しない。発見の段・逆走査を含む)
 - **レーンの非対称**: 値集合の同期は `pnpm test` (CI の frontend job) でだけ走る。
   PHP としての妥当性は backend job (`composer test` / PHPStan)。
   **`composer test` だけでは値集合の同期は検証されない**。
-- **逆走査は「登録漏れが無いことの証明」ではない**。名前も対応せず値も完全一致しない
-  drift 済みの写しは検出できない (2 規則それぞれの意図した限界)。
-- `collectTsUnionCandidates` は `resources/js/` 配下の `type X = …` という
-  トップレベル宣言だけを見る。`.svelte` の中の宣言・定数配列・switch の case ラベルは
-  逆走査の対象にもならない。**`.d.ts` (宣言ファイル) も対象外**である。
-  母集団は `tsconfig.json` の `include`/`exclude` が実際に決めるが、それだけを出典とは
-  言わない — `resources/js/` を直接歩いた `*.ts` (`.d.ts` を除く) の集合と program に
-  載った集合が完全一致することを独立実装の回帰テストで固定しており、目録に登録済みの
-  ファイルから import されるかどうかにも `tsconfig` の設定だけにも依存しない。
+- **逆走査は「登録漏れが無いことの証明」ではない**。名前も対応せず値も半分未満しか
+  交差しない drift 済みの写しは検出できない (規則それぞれの意図した限界)。
 
 ## キャッシュ素データ規約の 2 層 (T228 / 家系の裁定 AG-151 = 正典 v2)
 
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 3c0b7727..11a35180 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 50 件
+登録エントリ: 51 件
 
 ## 記録の原則
 
@@ -3098,3 +3098,62 @@ ### 関連
 - 実装: `tests/Support/RawEnv/` / `tests/Architecture/RawEnvDirectWriteGateTest.php`
 - 設計: `devnotes/20260824-1633-raw-env-snapshot-restore-v1/`
 - 関連する登録: D30 (`scripts/ci/pgsql_test_conn.php` の出自の記録) / D42 (契約文書のゲート索引)
+
+---
+
+## D54 前向きの同期検査を、単一ファイルの構文木方式ではなく共有の走査器 + 型情報方式で持つ
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/js/architecture/enum-ts-sync.test.ts` |
+| 業務要件起因の説明 | 撮影 PWA と管理画面と付属のコマンドライン道具は、制作状態・カット種別・通知種別・API のエラー符号といったサーバ側の選択肢で分岐する。写しがずれると導線が無言で 1 本欠けるが、どちらの側も単体では整合しているので型検査でも通常のテストでも落ちない。テンプレートの前向き検査は単一ファイルに構文木だけで閉じており、別名参照・添字アクセス・閉じたテンプレート文字列・定数の配列を読めないため、写しを登録するには実装側の書き方を変えるよう強いることになる。本アプリは家系の機能台帳 `enum-ts-sync-gate` の正典 v3 (i4 / i5) へ追従し、共有の走査器と型情報 (Program + TypeChecker) で読む形にして、目録を逆走査の gate と共有する |
+| 揃え続ける不変条件と保証機構 | 目録 (`ENUM_TS_RELATIONS`) が前向きの検査と逆走査の単一の出典であること (両 gate が同じモジュールを読む) / 値集合の抽出器を 2 本持たないこと (`ts-literal-values.ts` の 1 本を前向きと逆走査が共有し、登録行の locator も逆走査と同じ採番器が作る) / 受理範囲の外は空集合ではなく例外にすること (`EnumTsSyncError`。空 vs 空で素通りさせない) / 正本のレーンは `pnpm test` であり `composer test` ではないこと (レーンの非対称を台帳から追える形にする) |
+| 再判定の条件 | 家系の機能台帳 `enum-ts-sync-gate` が v4 を確定したとき / テンプレート側が型情報方式を採用して還流できるようになったとき / TypeScript の Program API が型の解決結果の観測方法を変えたとき / 目録の置き場を `resources/js` と `packages/<名前>/src` 以外へ広げる必要が出たとき |
+| 決めた日 | 2026-08-24 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260824-1633-enum-ts-sync-gate-v3/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 前向きの検査の実体 | 単一ファイル (正典 v2 = 3,858 行) に構文木だけで閉じる | 220 行の gate + 支援モジュール群 (`tests/js/support/enum-ts-sync/`) |
+| 値の読み取り | 構文木のみ | 型検査器で解決した型 (別名参照・`keyof typeof`・閉じたテンプレート文字列を受理) + 配列は構文から読む |
+| 目録 | 前向きの検査が自分で持つ | `relation-inventory.ts` へ切り出し、逆走査の gate と共有する |
+| 関係 | 完全一致だけ | `equal` と `subset` の 2 値 (許す値域と、そこから選んだ集合は別概念) |
+| 登録できる置き場 | 画面側だけ | 画面側 (`resources/js/`) と付属の道具 (`packages/<名前>/src/`) |
+| program の作り方 | 単一 | パッケージごと (道具は自前の tsconfig で解決する) |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **構文木だけでは本アプリの写しを読めない**。`resources/js/types/` の値域は
+   別名参照・`keyof typeof`・具体化された条件型で書かれており、構文木方式では
+   「登録できるように実装の書き方を変える」ことになる。検査の都合で本番のコードの
+   書き方を決めるのは順序が逆である。
+2. **目録を 2 つ持てない**。逆走査 (`enum-ts-sync-discovery.test.ts`) は
+   「どの宣言が登録済みか」を判定するのに同じ目録を読む。分けると
+   「片方だけ更新して食い違う」経路が生まれ、逆走査が登録済みの写しを
+   未登録として鳴らし続ける (または黙る)。
+3. **道具パッケージが境界の外に無い**。付属のコマンドライン道具はサーバの
+   エラー符号と OAuth スコープを写しとして持っており、実測でどちらもドリフトしていた。
+   画面側だけを見る検査では、この 2 件は永久に見えない。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「前向きの検査と逆走査は**同じ目録**と**同じ抽出器**と**同じ採番器**を使い、
+> 受理範囲の外は空集合ではなく例外になる」
+
+- 目録の単一性は、両 gate が `relation-inventory.ts` を読むことで構造的に保たれる
+- 抽出器の単一性は `ts-literal-values.ts` に集約してある (前向きの型別名の読み取りは
+  共有抽出器の結果と食い違ったら例外にする自己整合の検査を持つ)
+- 採番器の単一性は `buildScanIndex` に集約してある (登録行の locator と逆走査の候補の
+  locator が同じ採番空間に載る)
+- **機械が保証するのはここまでである**。「テンプレートより厳しい」ことは主張しない —
+  受理範囲・除外集合・保証しないものの正本は `docs/architecture.md`
+  §PHP 列挙と TypeScript 値域の同期 である
+
+### 関連
+
+- 実装: `tests/js/architecture/enum-ts-sync.test.ts` / `tests/js/support/enum-ts-sync/`
+- 設計: `devnotes/20260824-1633-enum-ts-sync-gate-v3/`
+- 関連する登録: D34 (採用時債務の凍結層。本登録で 1 行解消した)
diff --git a/packages/cli/src/api/client.ts b/packages/cli/src/api/client.ts
index 5610c839..2eef7dda 100644
--- a/packages/cli/src/api/client.ts
+++ b/packages/cli/src/api/client.ts
@@ -16,11 +16,13 @@ import { ApiErrorBodySchema, type ApiErrorCode } from "./schemas.js";
  * The `error` cases are split into a small finite set so command code can
  * map them to exit codes without re-parsing HTTP details:
  *
- *   - `auth`         → 401/403 (or `unauthenticated` / `forbidden` codes)
+ *   - `auth`         → 401/403 (or `unauthenticated` / `forbidden` /
+ *                       `insufficient_ability` / `actor_not_resolvable` codes)
  *   - `not-found`    → 404 / `not_found`
- *   - `rate-limit`   → 429 / `rate_limit_exceeded` with optional Retry-After
+ *   - `rate-limit`   → 429 / `rate_limited` with optional Retry-After
  *   - `quota`        → 402 / `quota_exceeded` (Stripe / plan limit exhausted)
- *   - `conflict`     → 409 / `idempotency_conflict`
+ *   - `conflict`     → 409 / `idempotency_conflict`, `idempotency_in_progress`,
+ *                       `idempotency_indeterminate`
  *   - `validation`   → 422 / `validation_failed` (+ controller-local codes
  *                       `payload_sanitization_failed`, `site_not_cli_capture`,
  *                       `use_audits_submit`)
@@ -200,13 +202,23 @@ function parseRetryAfter(headerValue: string | null): number | null {
 }
 
 /**
- * Map a canonical `error.code` to the CLI-side failure discriminator.
+ * Map an `error.code` to the CLI-side failure discriminator.
  * Returns `null` for unknown codes so the caller falls back to the
  * status-based mapping below. Keeping these arms narrow (one case per
  * known code) is deliberate: silent aliasing in this table was the
  * pre-T144 bug that motivated C1 in the first place.
+ *
+ * T261: the server emits `rate_limited` (not `rate_limit_exceeded`); the old
+ * spelling was drift carried over from another app and is **not** kept as an
+ * alias. A server still emitting the old spelling lands on the same
+ * `rate-limit` kind through the HTTP-status fallback (429), so the observable
+ * failure kind does not change — that is fixed by the tests.
+ * The four server-only codes (`insufficient_ability`, `actor_not_resolvable`,
+ * `idempotency_in_progress`, `idempotency_indeterminate`) get explicit arms
+ * rather than relying on the status fallback; the classification matches what
+ * `ApiErrorCode::defaultStatus()` would produce (403 → auth, 409 → conflict).
  */
-function dispatchKindFromCode(
+export function dispatchKindFromCode(
     code: string | null,
 ): Exclude<ApiCallFailure["kind"], "network" | "schema"> | null {
     if (code === null) return null;
@@ -214,14 +226,18 @@ function dispatchKindFromCode(
     switch (narrowed) {
         case "unauthenticated":
         case "forbidden":
+        case "insufficient_ability":
+        case "actor_not_resolvable":
             return "auth";
         case "not_found":
             return "not-found";
-        case "rate_limit_exceeded":
+        case "rate_limited":
             return "rate-limit";
         case "quota_exceeded":
             return "quota";
         case "idempotency_conflict":
+        case "idempotency_in_progress":
+        case "idempotency_indeterminate":
             return "conflict";
         case "validation_failed":
         case "payload_sanitization_failed":
@@ -241,7 +257,7 @@ function dispatchKindFromCode(
  * so older staging builds (and misbehaving reverse proxies) keep their
  * historic CLI UX.
  */
-function dispatchKindFromStatus(
+export function dispatchKindFromStatus(
     status: number,
 ): Exclude<ApiCallFailure["kind"], "network" | "schema"> {
     if (status === 401 || status === 403) return "auth";
@@ -253,6 +269,19 @@ function dispatchKindFromStatus(
     return "server";
 }
 
+/**
+ * Combination point of the two dispatch tables: the canonical `error.code`
+ * wins, and an unknown / absent code falls back to the HTTP status. Exported
+ * so the fallback contract can be pinned as a pure function (no HTTP round
+ * trip needed to tell "the code decided" from "the status decided").
+ */
+export function resolveFailureKind(
+    code: string | null,
+    status: number,
+): Exclude<ApiCallFailure["kind"], "network" | "schema"> {
+    return dispatchKindFromCode(code) ?? dispatchKindFromStatus(status);
+}
+
 /**
  * Canonical envelope extracted from an error response (C1 / T144).
  *
@@ -420,9 +449,7 @@ async function apiRequest<S extends ZodTypeAny>(
         const resolvedMessage = (fallback: string): string =>
             envelope.message ?? fallback;
 
-        const kindFromCode = dispatchKindFromCode(code);
-        const kind: ApiCallFailure["kind"] =
-            kindFromCode ?? dispatchKindFromStatus(status);
+        const kind: ApiCallFailure["kind"] = resolveFailureKind(code, status);
 
         switch (kind) {
             case "auth":
diff --git a/packages/cli/src/api/schemas.ts b/packages/cli/src/api/schemas.ts
index d77ae1ea..75d57e36 100644
--- a/packages/cli/src/api/schemas.ts
+++ b/packages/cli/src/api/schemas.ts
@@ -281,33 +281,52 @@ export const PersonaResponseSchema = envelope(PersonaSchema);
 export const ScenarioResponseSchema = envelope(ScenarioSchema);
 
 /**
- * Canonical `error.code` strings emitted by the v1 REST API (C1 / T144).
+ * Mirror of the server enum `App\Enums\ApiErrorCode` (C1 / T144).
  *
- * Mirrors `app/Enums/ApiErrorCode.php` plus the controller-local codes
- * that rely on the same envelope shape. Keep this list in sync by hand —
- * the schema contract test (`tests/api/schemas-contract.test.ts`) catches
- * drift by round-tripping real API responses through these schemas.
- *
- * Unknown `error.code` values are not rejected: consumers should fall
- * back to HTTP status when the CLI is older than the server.
+ * The value set is pinned to the server enum by
+ * `tests/js/architecture/enum-ts-sync.test.ts` (relation `equal`), so this
+ * list must stay a *pure* copy: **do not mix non-canonical, surface-local
+ * codes in here** — doing so breaks the sync gate and re-opens the drift
+ * this list exists to prevent.
  */
 export const API_ERROR_CODES = [
     "unauthenticated",
     "forbidden",
+    "insufficient_ability",
+    "actor_not_resolvable",
     "not_found",
     "validation_failed",
-    "rate_limit_exceeded",
-    "quota_exceeded",
+    "rate_limited",
     "idempotency_conflict",
+    "idempotency_in_progress",
+    "idempotency_indeterminate",
     "internal_server_error",
-    // Controller-local codes (AuditSubmissionController / SitePagesBulkController
-    // / EvaluationExecutionController) layered on top of the canonical enum.
+] as const;
+
+/**
+ * Non-canonical (not in the server enum) surface-local `error.code` values.
+ *
+ * These are emitted by individual controllers that share the error envelope
+ * shape (quota, payload sanitisation, capture-surface routing). They
+ * originate server-side; the CLI never invents them. They are kept apart
+ * from `API_ERROR_CODES` so the enum mirror stays exact.
+ */
+export const NON_CANONICAL_API_ERROR_CODES = [
+    "quota_exceeded",
     "payload_sanitization_failed",
     "site_not_cli_capture",
     "use_audits_submit",
 ] as const;
 
-export type ApiErrorCode = (typeof API_ERROR_CODES)[number];
+/**
+ * Every `error.code` the CLI knows how to dispatch on.
+ *
+ * Unknown `error.code` values are **not** rejected: consumers fall back to
+ * the HTTP status when the CLI is older than the server.
+ */
+export type ApiErrorCode =
+    | (typeof API_ERROR_CODES)[number]
+    | (typeof NON_CANONICAL_API_ERROR_CODES)[number];
 
 const ApiErrorBodySchema = z
     .object({
diff --git a/packages/cli/src/oauth/login.ts b/packages/cli/src/oauth/login.ts
index 9a9f679b..8fdc0ba0 100644
--- a/packages/cli/src/oauth/login.ts
+++ b/packages/cli/src/oauth/login.ts
@@ -43,15 +43,27 @@ export type LoginWithPkceOptions = {
 };
 
 /**
- * Default scope set. `cli:use` is required by the server's actor resolver;
- * the rest map to the CLI's read/write surface (see `CliOAuthScope`).
+ * Scopes the CLI requests by default.
+ *
+ * **This is not a mirror of the server enum `App\Enums\OAuth\CliOAuthScope`** —
+ * that one is "every scope the server recognises", this one is "the permissions
+ * the CLI asks for by default". Different concepts. The relation between them is
+ * **subset** (only values inside the server value range may appear here), pinned
+ * by `tests/js/architecture/enum-ts-sync.test.ts` with `relation: "subset"`.
+ * Writing a scope the server does not register turns that gate red.
+ * **The server growing a new scope does not oblige the CLI to request it**
+ * (least privilege).
+ *
+ * `cli:use` is required by the server's actor resolver; `read` / `write` map to
+ * the REST ability vocabulary and `session.revoke` covers CLI sign-out.
+ * T261: `evaluations:run` and `pages:bulk` were removed — the server
+ * (`McpPassportServiceProvider`) never registered them, so requesting them was
+ * either rejected or silently dropped.
  */
 export const DEFAULT_CLI_SCOPES = [
     "cli:use",
     "read",
     "write",
-    "evaluations:run",
-    "pages:bulk",
     "session.revoke",
 ] as const;
 
diff --git a/packages/cli/tests/api/error-code-dispatch.test.ts b/packages/cli/tests/api/error-code-dispatch.test.ts
new file mode 100644
index 00000000..6f0e7818
--- /dev/null
+++ b/packages/cli/tests/api/error-code-dispatch.test.ts
@@ -0,0 +1,99 @@
+import { describe, expect, it } from "vitest";
+import {
+    dispatchKindFromCode,
+    dispatchKindFromStatus,
+    resolveFailureKind,
+} from "../../src/api/client.js";
+import {
+    API_ERROR_CODES,
+    NON_CANONICAL_API_ERROR_CODES,
+} from "../../src/api/schemas.js";
+
+/**
+ * T261: the CLI-side `error.code` vocabulary drifted from the server enum
+ * (`app/Enums/ApiErrorCode.php`): the CLI branched on `rate_limit_exceeded`
+ * while the server emits `rate_limited`, and four server codes had no arm at
+ * all. The value-set half is pinned by the enum sync gate
+ * (`tests/js/architecture/enum-ts-sync.test.ts`); this file pins the half the
+ * sync gate cannot see — that the codes actually classify responses the way
+ * the server's `defaultStatus()` implies.
+ */
+describe("dispatchKindFromCode() — server codes", () => {
+    it.each([
+        ["unauthenticated", "auth"],
+        ["forbidden", "auth"],
+        ["insufficient_ability", "auth"],
+        ["actor_not_resolvable", "auth"],
+        ["not_found", "not-found"],
+        ["validation_failed", "validation"],
+        ["rate_limited", "rate-limit"],
+        ["idempotency_conflict", "conflict"],
+        ["idempotency_in_progress", "conflict"],
+        ["idempotency_indeterminate", "conflict"],
+        ["internal_server_error", "server"],
+    ] as const)("%s → %s", (code, kind) => {
+        expect(dispatchKindFromCode(code)).toBe(kind);
+    });
+
+    it("classifies every server code (no silent fall-through to status)", () => {
+        const unclassified = API_ERROR_CODES.filter(
+            (code) => dispatchKindFromCode(code) === null,
+        );
+        expect(unclassified).toEqual([]);
+    });
+});
+
+describe("dispatchKindFromCode() — non-canonical surface-local codes", () => {
+    it.each([
+        ["quota_exceeded", "quota"],
+        ["payload_sanitization_failed", "validation"],
+        ["site_not_cli_capture", "validation"],
+        ["use_audits_submit", "validation"],
+    ] as const)("%s → %s", (code, kind) => {
+        expect(dispatchKindFromCode(code)).toBe(kind);
+    });
+
+    it("classifies every non-canonical code", () => {
+        const unclassified = NON_CANONICAL_API_ERROR_CODES.filter(
+            (code) => dispatchKindFromCode(code) === null,
+        );
+        expect(unclassified).toEqual([]);
+    });
+});
+
+describe("dispatchKindFromCode() — unknown codes", () => {
+    it("returns null so the caller falls back to HTTP status", () => {
+        expect(dispatchKindFromCode("something_the_cli_never_learned")).toBeNull();
+        expect(dispatchKindFromCode(null)).toBeNull();
+    });
+
+    it("does not keep the retired spelling as an alias", () => {
+        expect(dispatchKindFromCode("rate_limit_exceeded")).toBeNull();
+    });
+});
+
+/**
+ * `resolveFailureKind` is the combination point the client uses: the code wins,
+ * an unknown / absent code falls back to the status. Pinning it as a pure
+ * function keeps "the code decided" and "the status decided" distinguishable
+ * (an end-to-end response assertion cannot tell them apart).
+ */
+describe("resolveFailureKind() — code first, status as the safety net", () => {
+    it("rate_limited + 429 → rate-limit (decided by the code)", () => {
+        expect(resolveFailureKind("rate_limited", 429)).toBe("rate-limit");
+    });
+
+    it("retired rate_limit_exceeded + 429 → rate-limit (decided by the status)", () => {
+        expect(dispatchKindFromCode("rate_limit_exceeded")).toBeNull();
+        expect(dispatchKindFromStatus(429)).toBe("rate-limit");
+        expect(resolveFailureKind("rate_limit_exceeded", 429)).toBe("rate-limit");
+    });
+
+    it("unknown code + 409 → conflict (status fallback still works)", () => {
+        expect(resolveFailureKind("brand_new_server_code", 409)).toBe("conflict");
+    });
+
+    it("a code that disagrees with the status is decided by the code", () => {
+        expect(resolveFailureKind("idempotency_in_progress", 500)).toBe("conflict");
+    });
+});
diff --git a/resources/js/types/organization.ts b/resources/js/types/organization.ts
index 8c430823..bd43a460 100644
--- a/resources/js/types/organization.ts
+++ b/resources/js/types/organization.ts
@@ -2,7 +2,7 @@
  * 組織まわりの画面が受け取る型。
  *
  * ★`OrganizationEntryTarget` は PHP の `App\Enums\Organization\EntryTarget` の写しである。
- *   値集合の一致は `tests/js/architecture/enum-ts-sync.test.ts` の目録 (`ENUM_TS_MIRRORS`) が
+ *   値集合の一致は `tests/js/architecture/enum-ts-sync.test.ts` の目録 (`ENUM_TS_RELATIONS`) が
  *   固定するので、**ここに値を足したら PHP 側にも足す** (逆も同じ)。
  */
 
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index 29d4c251..c7f73601 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -19,7 +19,7 @@ final class LedgerPins
     private function __construct() {}
 
     /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
-    public const int DIVERGENCE_ENTRY_COUNT = 50;
+    public const int DIVERGENCE_ENTRY_COUNT = 51;
 
     /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
     public const int FINGERPRINT_POPULATION_COUNT = 281;
@@ -31,7 +31,7 @@ private function __construct() {}
      *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
      *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
      */
-    public const int ADOPTION_DEBT_COUNT = 146;
+    public const int ADOPTION_DEBT_COUNT = 145;
 
     /**
      * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
diff --git a/tests/Support/TemplateDivergence/adoption-debt.tsv b/tests/Support/TemplateDivergence/adoption-debt.tsv
index 8402b722..6717668b 100644
--- a/tests/Support/TemplateDivergence/adoption-debt.tsv
+++ b/tests/Support/TemplateDivergence/adoption-debt.tsv
@@ -136,7 +136,6 @@ tests/Support/StrayHttpRequestGuard.php	cea69f5a162395495844c026b3e2537248ea65fc
 tests/TestCase.php	332af0ee95f4edc5bb960bd805057c40a4182ad4226a5fd08bb24c706d06ba59
 tests/js/architecture/contrast-invariant.test.ts	ee111fc338e62e936f85ffcc165dffe7d570c7c81d44a27baffe06f3eeaf96a8
 tests/js/architecture/ds-purity.test.ts	c383d0e28f12193c1408ba6c3079dceddf9efcba4d37c04d4bf7b1c3b9531f01
-tests/js/architecture/enum-ts-sync.test.ts	be70ca4be292aeade50187c5dfd75c34912d27794835e05114d15f8b1305f466
 tests/js/architecture/flash-keys-sync.test.ts	b3e5e4ac23edd1818739623e233e33b42370db817adfdc3a66a03a5fc9ed3b9d
 tests/js/architecture/pages-path-case-invariant.test.ts	037938ed0a56b30fb67a043694eb114ad70f784ab7beb486cede0b72df661220
 tests/js/architecture/passkeys-import-isolation.test.ts	5562deb2943d33069b82cd29ca9d1ac9202a575725e251a24a533786adab9fac
diff --git a/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts b/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts
index dd8542e3..f2806db4 100644
--- a/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts
+++ b/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts
@@ -1,29 +1,73 @@
 /**
- * 発見の段・逆走査 (T225) の抽出器・純関数の自己検査 (負例行列)。
+ * 発見の段・逆走査の抽出器・純関数の自己検査 (負例行列と故障注入の受け皿)。
  *
- * `enum-ts-sync-discovery.test.ts` の本体 gate は「未分類の PHP 列挙・未登録の候補が
- * 0 件であること」しか見ない。分類そのものが静かに間違える (母集団に入れるべきものを
- * 落とす / 入れるべきでないものを混ぜる / 候補の突き合わせが緩すぎる・厳しすぎる) と、
- * 「0 件」という結果そのものが空虚になる。ここで抽出器・突き合わせの純関数の
- * 受理・拒否の境界を固定する。
+ * `enum-ts-sync-discovery.test.ts` の本体 gate は「未分類の PHP 列挙・未登録の候補・
+ * 未登録の判定保留が 0 件であること」しか見ない。分類そのものが静かに間違える
+ * (母集団に入れるべきものを落とす / 入れるべきでないものを混ぜる / 候補の突き合わせが
+ * 緩すぎる・厳しすぎる) と、「0 件」という結果そのものが空虚になる。ここで抽出器・
+ * 突き合わせの純関数の受理・拒否の境界を固定する。
  *
- * **見本の置き方**: PHP はテスト内の文字列で書く (`classifyPhpFile` はファイルを要求しない。
- * `.php` として置くと strict_types 宣言 gate 等の母集団に入ってしまうのを避ける。
- * `enum-ts-sync-extractor.test.ts` と同じ理由)。TS は `fixtures/candidates/` にファイルで置く
- * (型検査器に実ファイルが要るため。`tsconfig.json` の `exclude` に
- * `tests/js/support/enum-ts-sync/fixtures/**` が既にあるので新設不要)。
+ * **本番の入口に差し替え口を作らない**。戦略は入口の側で固定し、自己検査は
+ * **輸出した純関数へ入力のデータを渡して**判定を突く。
+ *
+ * **見本の置き方**:
+ * - PHP はテスト内の文字列で書く (`classifyPhpFile` はファイルを要求しない)
+ * - TS は `fixtures/candidates/` にファイルで置く (型検査器に実ファイルが要る)。
+ *   `fixtures/` は**本番の母集団に入る**ので、見本の値は現物の列挙と交差しない綴りにする
+ * - **不正な入力は追跡ファイルにしない** (母集団に入って本番の gate が恒久的に赤くなる)。
+ *   構文の壊れた `.svelte`・受理しない属性・module から実体への参照・同名の最上位束縛は
+ *   **テストの中の文字列**として `toVirtualUnit()` / `createFixtureProgram()` へ渡す
  *
  * 保証しないものの正本は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期。
  */
-import { describe, expect, it } from "vitest";
+import { beforeAll, describe, expect, it } from "vitest";
 import fs from "node:fs";
+import os from "node:os";
 import path from "node:path";
+import ts from "typescript";
+import { EnumTsSyncError } from "../support/enum-ts-sync/errors";
 import { classifyPhpFile, listTrackedPhpFiles } from "../support/enum-ts-sync/php-enum-catalog";
-import { createFixtureProgram, createMirrorProgram, REPO_ROOT } from "../support/enum-ts-sync/program";
-import { collectTsUnionCandidates } from "../support/enum-ts-sync/ts-candidates";
-import { findUnregisteredMirrorCandidates, shortEnumName } from "../support/enum-ts-sync/reverse-sweep";
+import {
+    createFixtureProgram,
+    createMirrorPrograms,
+    REPO_ROOT,
+    type MirrorPrograms,
+} from "../support/enum-ts-sync/program";
+import {
+    listProgramTsFiles,
+    parseTrackedOutput,
+    validateExcludedRoots,
+    type ExcludedRoot,
+} from "../support/enum-ts-sync/population";
+import {
+    assertNoVirtualPathCollision,
+    toVirtualUnit,
+    VIRTUAL_SUFFIX,
+} from "../support/enum-ts-sync/svelte-source";
+import {
+    buildWitnessIndex,
+    collectTsCandidates,
+    isDerivedObjectKeys,
+    locatorKey,
+    switchSubjectName,
+    type DerivedFacts,
+    type TsCandidateScan,
+    type TsCandidateShape,
+    type TsUnionCandidate,
+} from "../support/enum-ts-sync/ts-candidates";
+import {
+    auditReverseSweepExemptions,
+    correspondWords,
+    findUnregisteredMirrorCandidates,
+    matchReverseRule,
+    maxWordMatching,
+    shortEnumName,
+    strictNameCorrespondence,
+    wordForms,
+} from "../support/enum-ts-sync/reverse-sweep";
 import type { ResolvedPhpEnum } from "../support/enum-ts-sync/php-enum-catalog";
-import type { TsUnionCandidate } from "../support/enum-ts-sync/ts-candidates";
+
+const FIXTURE = "tests/js/support/enum-ts-sync/fixtures/candidates";
 
 describe("classifyPhpFile() (発見の段の PHP 側分類)", () => {
     it("D1: 素直な string enum は resolved になる", () => {
@@ -33,6 +77,12 @@ describe("classifyPhpFile() (発見の段の PHP 側分類)", () => {
         expect(result?.kind === "resolved" && [...result.values].sort()).toEqual(["a", "b"]);
     });
 
+    it("D1b: resolved は enum 宣言の頭の行を持つ (失敗メッセージが PHP 側の位置を出せる)", () => {
+        const source = "<?php\n\ndeclare(strict_types=1);\n\nenum D1b: string\n{\n    case A = 'a';\n}\n";
+        const result = classifyPhpFile(source, "D1b.php");
+        expect(result?.kind === "resolved" && result.line).toBe(5);
+    });
+
     it("D2: int backing の enum は母集団から外れる (undefined)", () => {
         const source = "<?php\nenum D2: int\n{\n    case A = 1;\n}\n";
         expect(classifyPhpFile(source, "D2.php")).toBeUndefined();
@@ -142,7 +192,7 @@ describe("classifyPhpFile() (発見の段の PHP 側分類)", () => {
     });
 });
 
-describe("listTrackedPhpFiles() (母集団の走査根)", () => {
+describe("listTrackedPhpFiles() (PHP 側母集団の走査根)", () => {
     it("実リポジトリの app/ 配下は空でない", () => {
         expect(listTrackedPhpFiles().length).toBeGreaterThan(0);
     });
@@ -154,111 +204,341 @@ describe("listTrackedPhpFiles() (母集団の走査根)", () => {
     });
 });
 
-describe("collectTsUnionCandidates() (逆走査の TS 側候補走査)", () => {
-    const fixtureDir = path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/fixtures/candidates");
-    const fixtureFile = path.join(fixtureDir, "mixed.ts");
-
-    it("文字列リテラル型だけの union / 単独リテラルを候補として拾い、それ以外は拾わない", () => {
-        const program = createFixtureProgram([fixtureFile]);
-        const candidates = collectTsUnionCandidates(program, fixtureDir);
-        const byName = new Map(candidates.map((c) => [c.name, c]));
-
-        expect([...(byName.get("LiteralUnionCandidate")?.values ?? [])].sort()).toEqual(["a", "b"]);
-        expect([...(byName.get("SingleLiteralCandidate")?.values ?? [])].sort()).toEqual(["only"]);
-        expect(byName.has("NotAUnionCandidate")).toBe(false);
-        expect(byName.has("NumberCandidate")).toBe(false);
-    });
-
-    it("走査根の配下でないファイルは対象にしない", () => {
-        const program = createFixtureProgram([fixtureFile]);
-        const candidates = collectTsUnionCandidates(program, path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/program-fixtures"));
-        expect(candidates.some((c) => c.name === "LiteralUnionCandidate")).toBe(false);
-    });
-
-    it("走査対象ファイルの構文が壊れていると無言で読み飛ばさず例外になる (fail-closed)", () => {
-        const brokenDir = path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/fixtures/candidates-broken");
-        const brokenFile = path.join(brokenDir, "broken.ts");
-        const program = createFixtureProgram([brokenFile]);
-        expect(() => collectTsUnionCandidates(program, brokenDir)).toThrow("構文が壊れているため候補を読めません");
-    });
-
-    it("母集団は明示した tsFiles に依存しない (tsconfig の include が実際に決める)", () => {
-        // createMirrorProgram に登録済みミラーの ts ファイルを 1 つも渡さなくても、
-        // tsconfig の include (`resources/js/**/*.ts`) が母集団を決めるので、
-        // 登録済みミラーから import されない実在のファイルの宣言が見つかる。
-        // ここが崩れると「TS 側の逆走査は登録済みファイルの import グラフに閉じる」
-        // という回帰になる。**母集団の単一出典が tsconfig だと主張するものではない**
-        // (それは次のテストが固定する、ファイルシステムを直接歩いた集合との完全一致)。
-        const program = createMirrorProgram([]);
-        const candidates = collectTsUnionCandidates(program);
-        expect(candidates.some((c) => c.file === "resources/js/lib/stores/toast.ts")).toBe(true);
-    }, 60_000);
-
-    it("走査した非宣言ファイルの集合は、ファイルシステムを直接歩いた集合と一致する (対象ファイル集合の差分が空)", () => {
-        // `collectTsUnionCandidates` 自身は「見つかった候補」しか返さないので、
-        // 「対象にしたファイル集合」を候補の有無だけでは裏取りできない。
-        // ここでは `collectTsUnionCandidates` と**独立した実装**
-        // (プログラムを介さない素朴なファイルシステム走査) で resources/js 配下の
-        // 期待する .ts (.d.ts を除く) の集合を作り、program 側の集合と完全一致させる。
-        // 片方だけ絞られる改変 (tsconfig の exclude を広げる等) が入ったらここが赤くなる。
-        const jsRoot = path.join(REPO_ROOT, "resources", "js");
-        const expectedFiles = new Set<string>();
-        const walk = (dir: string): void => {
-            for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
-                const absolute = path.join(dir, entry.name);
-                if (entry.isDirectory()) {
-                    walk(absolute);
-                } else if (entry.isFile() && absolute.endsWith(".ts") && !absolute.endsWith(".d.ts")) {
-                    expectedFiles.add(path.relative(REPO_ROOT, absolute).split(path.sep).join("/"));
-                }
-            }
+/** カテゴリ 3: 母集団の列挙が空振りしたら赤くする。 */
+describe("population.ts (逆走査の母集団と唯一の除外)", () => {
+    it("parseTrackedOutput は空出力を空の一覧にする (0 件の分岐を単体で突く)", () => {
+        expect(parseTrackedOutput("")).toEqual([]);
+        expect(parseTrackedOutput("a.ts\0b.ts\0")).toEqual(["a.ts", "b.ts"]);
+    });
+
+    it("列挙が 0 件になったら例外になる (空振りを緑にしない)", () => {
+        // `app/Enums` を根にすると版管理下の `*.ts` は 1 件も無い。
+        expect(() => listProgramTsFiles(path.join(REPO_ROOT, "app", "Enums"))).toThrow(
+            "母集団の走査が空振りしています",
+        );
+    });
+
+    it("除外根の一覧が空だと例外になる", () => {
+        expect(() => validateExcludedRoots([])).toThrow("除外根の一覧が空です");
+    });
+
+    it("除外根の体裁の負例 (配下でない / 実在しない / 重複 / 理由 29 文字)", () => {
+        const reason = "あ".repeat(30);
+        const valid: ExcludedRoot = {
+            root: "tests/js/support/enum-ts-sync/fixtures/candidates-broken",
+            reason,
         };
-        walk(jsRoot);
+        expect(() => validateExcludedRoots([valid])).not.toThrow();
+        expect(() => validateExcludedRoots([{ root: "tests/js/architecture", reason }])).toThrow(
+            "の配下だけです",
+        );
+        expect(() =>
+            validateExcludedRoots([{ root: "tests/js/support/enum-ts-sync/no-such-dir", reason }]),
+        ).toThrow("除外根が実在するディレクトリではありません");
+        expect(() => validateExcludedRoots([valid, valid])).toThrow("2 回登録されています");
+        expect(() => validateExcludedRoots([{ ...valid, reason: "あ".repeat(29) }])).toThrow(
+            "理由は 30 文字以上",
+        );
+    });
+
+    it("境界: 除外根に正常な .ts を置くと本番と同じ入口 (構文診断) では落ちない = gate の自己点検が赤くなる", () => {
+        const sandbox = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), "enum-ts-sync-excluded-")));
+        try {
+            const file = path.join(sandbox, "healthy.ts");
+            fs.writeFileSync(file, 'export type Healthy = "zzz-healthy";\n');
+            const fixture = createFixtureProgram([file]);
+            const source = fixture.program.getSourceFile(file);
+            expect(source).toBeDefined();
+            expect(source === undefined ? -1 : fixture.program.getSyntacticDiagnostics(source).length).toBe(0);
+        } finally {
+            fs.rmSync(sandbox, { recursive: true, force: true });
+        }
+    });
+});
+
+/** カテゴリ 4 / 4': `.svelte` の仮想化と平坦化で再現できない形の不合格。 */
+describe("toVirtualUnit() (.svelte の仮想 TS 化)", () => {
+    const svelteFile = "tests/js/support/enum-ts-sync/fixtures/svelte/__negative__.svelte";
+
+    const unitOf = (source: string) => toVirtualUnit(svelteFile, source);
+
+    it("script の中身以外を空白で潰し、行と列を元ファイルと一致させる", () => {
+        const source = '<div>x</div>\n<script lang="ts">\ntype A = "zzz-a";\n</script>\n';
+        const unit = unitOf(source);
+        expect(unit.text.startsWith("            \n")).toBe(true);
+        expect(unit.text.length).toBe(source.length + "\nexport {};\n".length);
+        // 元ファイル上の位置がそのまま使える。
+        expect(unit.text.indexOf('type A = "zzz-a";')).toBe(source.indexOf('type A = "zzz-a";'));
+    });
+
+    it.each([
+        ["LF", 'a\n<script lang="ts">\ntype A = "zzz-a";\n</script>\n'],
+        ["CRLF", 'a\r\n<script lang="ts">\r\ntype A = "zzz-a";\r\n</script>\r\n'],
+        ["孤立 CR", 'a\r<script lang="ts">\rtype A = "zzz-a";\r</script>\r'],
+        ["非 BMP 文字", '<p>\u{1F600}</p>\n<script lang="ts">\ntype A = "zzz-a";\n</script>\n'],
+        ["U+2028", '<p>a b</p>\n<script lang="ts">\ntype A = "zzz-a";\n</script>\n'],
+    ])("行と列が保たれる (%s)", (_label, source) => {
+        const unit = unitOf(source);
+        const original = ts.createSourceFile("o.ts", source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
+        const virtual = ts.createSourceFile("v.ts", unit.text, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
+        const offset = source.indexOf('type A = "zzz-a";');
+        expect(virtual.getLineAndCharacterOfPosition(offset)).toEqual(
+            original.getLineAndCharacterOfPosition(offset),
+        );
+    });
+
+    it("末尾が改行で終わらない / 行注釈で終わっても export {}; が独立した文になる", () => {
+        for (const tail of ['<script lang="ts">type A = "zzz-a";</script>', '<script lang="ts">\n// 注釈</script>']) {
+            const unit = unitOf(tail);
+            const virtual = ts.createSourceFile("v.ts", unit.text, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
+            expect(virtual.statements.some((s) => ts.isExportDeclaration(s))).toBe(true);
+        }
+    });
+
+    it.each([
+        // 属性なし / `lang="js"` の script は svelte の parse が JS として読むので、
+        // 見本の中身も JS にする (走査器はその中身を TS として読む = 過剰検出の向き)。
+        ["属性なし (実体)", "<script>\nconst a = 1;\n</script>\n"],
+        ["lang=ts (実体)", '<script lang="ts">\ntype A = "zzz-a";\n</script>\n'],
+        ["lang=js (実体)", '<script lang="js">\nconst a = 1;\n</script>\n'],
+        ["module + lang=ts", '<script lang="ts" module>\ntype A = "zzz-a";\n</script>\n'],
+        ["module (値なし)", "<script module>\nconst a = 1;\n</script>\n"],
+        ["module + lang=js", '<script lang="js" module>\nconst a = 1;\n</script>\n'],
+    ])("受理する script の形 (%s)", (_label, source) => {
+        expect(() => unitOf(source)).not.toThrow();
+    });
+
+    it.each([
+        ["lang が受理表の外", '<script lang="scss">\n$a: 1;\n</script>\n', "受理しない script の lang"],
+        // 値つきの `module` は svelte の parse 自身が先に拒む。**どちらの層で落ちても不合格**
+        // であることが要点で、走査器側の検査は parse の仕様が緩んだときの受け皿として残す。
+        ["値つきの module 属性", '<script module="x">\nconst a = 1;\n</script>\n', ".svelte の構文を読めません"],
+        ["src 属性", '<script src="./a.js"></script>\n', "受理しない script 属性"],
+        ["generics 属性", '<script lang="ts" generics="T">\nconst a = 1;\n</script>\n', "受理しない script 属性"],
+    ])("不合格にする script の形 (%s)", (_label, source, reason) => {
+        expect(() => unitOf(source)).toThrow(reason);
+    });
+
+    it("構文の壊れた .svelte は無言で読み飛ばさず例外になる", () => {
+        expect(() => unitOf('<script lang="ts">\ntype A = "zzz-a";\n')).toThrow(EnumTsSyncError);
+    });
+
+    it.each([
+        ["変数", '<script lang="ts" module>\nlet shared = 1;\n</script>\n<script lang="ts">\nlet shared = 2;\n</script>\n'],
+        ["分割代入", '<script lang="ts" module>\nconst { shared } = { shared: 1 };\n</script>\n<script lang="ts">\nconst shared = 2;\n</script>\n'],
+        ["関数", '<script lang="ts" module>\nfunction shared(): void {}\n</script>\n<script lang="ts">\nconst shared = 2;\n</script>\n'],
+        ["型別名", '<script lang="ts" module>\ntype Shared = "zzz-a";\n</script>\n<script lang="ts">\ntype Shared = "zzz-b";\n</script>\n'],
+        ["enum", '<script lang="ts" module>\nenum Shared { A }\n</script>\n<script lang="ts">\nconst Shared = 2;\n</script>\n'],
+        ["namespace", '<script lang="ts" module>\nnamespace Shared { export const a = 1; }\n</script>\n<script lang="ts">\nconst Shared = 2;\n</script>\n'],
+        ["取り込み", '<script lang="ts" module>\nimport type { Shared } from "./x";\n</script>\n<script lang="ts">\ntype Shared = "zzz-b";\n</script>\n'],
+    ])("検査 A: module と実体に同名の最上位束縛があると不合格 (%s)", (_label, source) => {
+        expect(() => unitOf(source)).toThrow("同名の最上位束縛");
+    });
+});
+
+describe("assertNoVirtualPathCollision() (仮想パスの綴り)", () => {
+    const unit = toVirtualUnit(
+        "resources/js/components/atoms/Sample.svelte",
+        '<script lang="ts">\ntype A = "zzz-a";\n</script>\n',
+    );
 
-        const program = createMirrorProgram([]);
-        const scannedFiles = new Set(
-            program.program
-                .getSourceFiles()
-                .filter((s) => !s.isDeclarationFile && s.fileName.startsWith(jsRoot + path.sep))
-                .map((s) => path.relative(REPO_ROOT, s.fileName).split(path.sep).join("/")),
+    it("衝突しなければ通る", () => {
+        expect(() => assertNoVirtualPathCollision([unit], ["resources/js/lib/x.ts"])).not.toThrow();
+    });
+
+    it("版管理下に同じ綴りのファイルがあれば例外になる", () => {
+        expect(() =>
+            assertNoVirtualPathCollision([unit], [`resources/js/components/atoms/Sample.svelte${VIRTUAL_SUFFIX}`]),
+        ).toThrow("仮想パスの綴りが版管理下のファイルと衝突しています");
+    });
+});
+
+describe("createFixtureProgram() / createMirrorPrograms() が検査 B を必ず走らせる", () => {
+    const svelteFile = "tests/js/support/enum-ts-sync/fixtures/svelte/__negative__.svelte";
+
+    it("境界: module から実体側の宣言を参照する .svelte は program の作成そのものが失敗する", () => {
+        const unit = toVirtualUnit(
+            svelteFile,
+            '<script lang="ts" module>\ntype FromInstance = typeof instanceValue;\nexport type Exposed = FromInstance;\n</script>\n<script lang="ts">\nconst instanceValue = "zzz-b";\n</script>\n',
+        );
+        expect(() => createFixtureProgram([], [unit])).toThrow("実体側の宣言");
+    });
+
+    it("境界: 実体側の取り込みを module 側が参照する形も不合格 (別名の宣言位置で捕まえる)", () => {
+        const unit = toVirtualUnit(
+            svelteFile,
+            '<script lang="ts" module>\nexport type Alias = ImportedDerivedKey;\n</script>\n<script lang="ts">\nimport type { ImportedDerivedKey } from "../candidates/derived-keys";\nconst value: ImportedDerivedKey = "zzz-i-1";\n</script>\n',
+        );
+        expect(() => createFixtureProgram([], [unit])).toThrow("実体側の宣言");
+    });
+
+    it("実体から module の宣言を参照するのは正しいので通る", () => {
+        const unit = toVirtualUnit(
+            svelteFile,
+            '<script lang="ts" module>\nexport type ModuleKind = "zzz-m-1";\n</script>\n<script lang="ts">\ntype InstanceKind = ModuleKind;\nconst value: InstanceKind = "zzz-m-1";\n</script>\n',
         );
+        expect(() => createFixtureProgram([], [unit])).not.toThrow();
+    });
+
+    it("仮想 TS はモジュール文脈なので、宣言が別の見本コンポーネントへ漏れない", () => {
+        const declaring = toVirtualUnit(
+            "tests/js/support/enum-ts-sync/fixtures/svelte/__A__.svelte",
+            '<script lang="ts">\ntype Leaked = "zzz-leak-1";\nconst a: Leaked = "zzz-leak-1";\n</script>\n',
+        );
+        const referencing = toVirtualUnit(
+            "tests/js/support/enum-ts-sync/fixtures/svelte/__B__.svelte",
+            '<script lang="ts">\ntype Reference = Leaked;\n</script>\n',
+        );
+        const fixture = createFixtureProgram([], [declaring, referencing]);
+        const source = fixture.program.getSourceFile(referencing.virtualPath);
+        expect(source).toBeDefined();
+        const alias = source?.statements.find(ts.isTypeAliasDeclaration);
+        expect(alias).toBeDefined();
+        if (alias === undefined) return;
+        const symbol = fixture.checker.getSymbolAtLocation(alias.name);
+        expect(symbol).toBeDefined();
+        if (symbol === undefined) return;
+        // 漏れていれば `"zzz-leak-1"` に解決してしまう。
+        const declared = fixture.checker.getDeclaredTypeOfSymbol(symbol);
+        expect(declared.isStringLiteral()).toBe(false);
+    });
+});
+
+/** カテゴリ 2 / 7: 派生の除外と証人の索引。 */
+describe("isDerivedObjectKeys() (対応表のキーの派生除外)", () => {
+    const derived: DerivedFacts = {
+        hasExplicitType: true,
+        explicitTypeResolved: true,
+        hasStringIndexSignature: false,
+        hasOptionalProperty: false,
+        requiredKeys: ["a", "b"],
+        writtenKeys: ["b", "a"],
+        witnessed: true,
+    };
+
+    it("5 条件をすべて満たすときだけ派生と認める", () => {
+        expect(isDerivedObjectKeys(derived)).toBe(true);
+    });
+
+    it.each([
+        ["明示の型が無い", { hasExplicitType: false }],
+        ["明示の型を解決できない", { explicitTypeResolved: false }],
+        ["文字列の添字シグネチャがある", { hasStringIndexSignature: true }],
+        ["任意プロパティがある", { hasOptionalProperty: true }],
+        ["必須プロパティが 0 件", { requiredKeys: [] }],
+        ["書かれたキーが必須プロパティと違う (欠落)", { requiredKeys: ["a", "b", "c"] }],
+        ["書かれたキーが必須プロパティと違う (余剰)", { requiredKeys: ["a"] }],
+        ["証人が無い", { witnessed: false }],
+    ] as const)("%s なら派生と認めない (候補として残す)", (_label, patch) => {
+        expect(isDerivedObjectKeys({ ...derived, ...patch })).toBe(false);
+    });
+});
+
+describe("buildWitnessIndex() (証人の資格)", () => {
+    const candidate = (shape: TsCandidateShape, values: readonly string[]): TsUnionCandidate => ({
+        locator: { file: "resources/js/types/x.ts", shape, name: "X", occurrence: 0 },
+        line: 1,
+        topLevel: true,
+        values: new Set(values),
+        correspondenceName: "X",
+        nameResolved: true,
+    });
+
+    it("対応表のキー形だけの候補集合では索引が空になる (対応表は証人になれない)", () => {
+        expect(buildWitnessIndex([candidate("object-keys", ["a", "b"])]).size).toBe(0);
+    });
 
-        const missingFromProgram = [...expectedFiles].filter((f) => !scannedFiles.has(f));
-        const unexpectedInProgram = [...scannedFiles].filter((f) => !expectedFiles.has(f));
+    it("対応表以外の形は証人になれる", () => {
+        const index = buildWitnessIndex([
+            candidate("literal-union", ["a", "b"]),
+            candidate("const-array", ["c"]),
+            candidate("switch-cases", ["d"]),
+        ]);
+        expect(index.has("a b")).toBe(true);
+        expect(index.size).toBe(3);
+    });
+});
 
-        expect(missingFromProgram, `ファイルシステムには実在するのに program に載っていない: ${missingFromProgram.join(", ")}`).toEqual([]);
-        expect(unexpectedInProgram, `program には載っているがファイルシステム走査に無い: ${unexpectedInProgram.join(", ")}`).toEqual([]);
-    }, 60_000);
+/** カテゴリ 8: 分岐の判定対象の名前。 */
+describe("switchSubjectName() (分岐のラベルの名前解決)", () => {
+    const svelteFile = "tests/js/support/enum-ts-sync/fixtures/svelte/__switch__.svelte";
+    const body = (subject: string): string =>
+        `{ switch (${subject}) { case "zzz-s-1": return 1; default: return 0; } }`;
+    const source = [
+        '<script lang="ts">',
+        'type SubjectKind = "zzz-s-1" | "zzz-s-2";',
+        `export const a = (subject: SubjectKind): number => ${body("subject")};`,
+        `export const b = (holder: { kind: SubjectKind }): number => ${body("holder.kind")};`,
+        `export const c = (plain: string): number => ${body("plain")};`,
+        `export const d = (make: () => string): number => ${body("make()")};`,
+        `export const e = (table: readonly string[]): number => ${body("table[0]")};`,
+        "</script>",
+        "",
+    ].join("\n");
+
+    const subjects = (): readonly (string | null)[] => {
+        const unit = toVirtualUnit(svelteFile, source);
+        const fixture = createFixtureProgram([], [unit]);
+        const file = fixture.program.getSourceFile(unit.virtualPath);
+        expect(file).toBeDefined();
+        if (file === undefined) return [];
+        const names: (string | null)[] = [];
+        const visit = (node: ts.Node): void => {
+            if (ts.isSwitchStatement(node)) names.push(switchSubjectName(fixture.checker, node.expression, file));
+            ts.forEachChild(node, visit);
+        };
+        visit(file);
+        return names;
+    };
+
+    it("型別名が解決できれば型の名前を優先し、できなければ識別子とプロパティ参照だけを名前にする", () => {
+        const [aliasIdentifier, aliasProperty, plainIdentifier, call, indexed] = subjects();
+        expect(aliasIdentifier).toBe("SubjectKind");
+        expect(aliasProperty).toBe("SubjectKind");
+        expect(plainIdentifier).toBe("plain");
+        // 呼び出し式・添字アクセスは名前対応に使わない (任意の式の字面を名前にしない)。
+        expect(call).toBeNull();
+        expect(indexed).toBeNull();
+    });
 });
 
-const phpEnum = (path_: string, values: readonly string[]): ResolvedPhpEnum => ({
+const phpEnum = (path_: string, values: readonly string[], line = 1): ResolvedPhpEnum => ({
     path: path_,
     name: shortEnumName(path_),
+    line,
     values: new Set(values),
 });
 
-const tsCandidate = (file: string, name: string, values: readonly string[]): TsUnionCandidate => ({
-    file,
-    name,
+const tsCandidate = (
+    file: string,
+    name: string,
+    values: readonly string[],
+    options: { readonly shape?: TsCandidateShape; readonly correspondenceName?: string | null } = {},
+): TsUnionCandidate => ({
+    locator: { file, shape: options.shape ?? "literal-union", name, occurrence: 0 },
+    line: 1,
+    topLevel: true,
     values: new Set(values),
+    correspondenceName: options.correspondenceName === undefined ? name : options.correspondenceName,
+    nameResolved: (options.correspondenceName === undefined ? name : options.correspondenceName) !== null,
 });
 
 describe("findUnregisteredMirrorCandidates() (逆走査の突き合わせ純関数)", () => {
     const notRegistered = (): boolean => false;
 
     it("E1: 値集合が完全一致する未登録の宣言は規則 1 で見つかる", () => {
-        const found = findUnregisteredMirrorCandidates(
+        const { found } = findUnregisteredMirrorCandidates(
             [phpEnum("app/Enums/Foo.php", ["a", "b"])],
             [tsCandidate("resources/js/types/x.ts", "Unrelated", ["a", "b"])],
             notRegistered,
         );
         expect(found).toHaveLength(1);
-        expect(found[0].rule).toBe(1);
-        expect(found[0].nameMatch).toBeNull();
+        expect(found[0].rule).toBe("1");
+        expect(found[0].reason).toBe("完全一致");
     });
 
     it("E2: 完全一致でも登録済みなら見つからない", () => {
-        const found = findUnregisteredMirrorCandidates(
+        const { found } = findUnregisteredMirrorCandidates(
             [phpEnum("app/Enums/Foo.php", ["a", "b"])],
             [tsCandidate("resources/js/types/x.ts", "Foo", ["a", "b"])],
             () => true,
@@ -266,49 +546,50 @@ describe("findUnregisteredMirrorCandidates() (逆走査の突き合わせ純関
         expect(found).toEqual([]);
     });
 
-    it("E3: 名前が一致し値が交差 (完全一致ではない) する未登録の宣言は規則 2 で見つかる", () => {
-        const found = findUnregisteredMirrorCandidates(
+    it("E3: 名前が一致し値が交差 (完全一致ではない) する未登録の宣言は規則 2a で見つかる", () => {
+        const { found } = findUnregisteredMirrorCandidates(
             [phpEnum("app/Enums/Foo.php", ["a", "b", "c"])],
             [tsCandidate("resources/js/types/x.ts", "Foo", ["a", "z"])],
             notRegistered,
         );
         expect(found).toHaveLength(1);
-        expect(found[0].rule).toBe(2);
-        expect(found[0].nameMatch).not.toBeNull();
+        expect(found[0].rule).toBe("2a");
+        expect(found[0].onlyInPhp).toEqual(["b", "c"]);
+        expect(found[0].onlyInTs).toEqual(["z"]);
     });
 
-    it("E4: 名前が複数形接尾辞 (s) で対応し値が交差すると規則 2 で見つかる", () => {
-        const found = findUnregisteredMirrorCandidates(
+    it("E4: 名前が複数形接尾辞 (s) で対応し値が交差すると規則 2a で見つかる", () => {
+        const { found } = findUnregisteredMirrorCandidates(
             [phpEnum("app/Enums/Foo.php", ["a", "b"])],
             [tsCandidate("resources/js/types/x.ts", "Foos", ["a", "z"])],
             notRegistered,
         );
         expect(found).toHaveLength(1);
-        expect(found[0].rule).toBe(2);
+        expect(found[0].rule).toBe("2a");
     });
 
     it("E5: 複数形接尾辞 (es) でも対応する", () => {
-        const found = findUnregisteredMirrorCandidates(
+        const { found } = findUnregisteredMirrorCandidates(
             [phpEnum("app/Enums/Box.php", ["a", "b"])],
             [tsCandidate("resources/js/types/x.ts", "Boxes", ["a", "z"])],
             notRegistered,
         );
         expect(found).toHaveLength(1);
-        expect(found[0].rule).toBe(2);
+        expect(found[0].rule).toBe("2a");
     });
 
     it("E6: 接尾辞 values でも対応する", () => {
-        const found = findUnregisteredMirrorCandidates(
+        const { found } = findUnregisteredMirrorCandidates(
             [phpEnum("app/Enums/Foo.php", ["a", "b"])],
             [tsCandidate("resources/js/types/x.ts", "FooValues", ["a", "z"])],
             notRegistered,
         );
         expect(found).toHaveLength(1);
-        expect(found[0].rule).toBe(2);
+        expect(found[0].rule).toBe("2a");
     });
 
     it("E7: 名前が対応しても値が交差しなければ見つからない", () => {
-        const found = findUnregisteredMirrorCandidates(
+        const { found } = findUnregisteredMirrorCandidates(
             [phpEnum("app/Enums/Foo.php", ["a", "b"])],
             [tsCandidate("resources/js/types/x.ts", "Foo", ["x", "y"])],
             notRegistered,
@@ -317,7 +598,7 @@ describe("findUnregisteredMirrorCandidates() (逆走査の突き合わせ純関
     });
 
     it("E8: 値が交差しても名前が対応しなければ見つからない (緩い名前対応は採らない)", () => {
-        const found = findUnregisteredMirrorCandidates(
+        const { found } = findUnregisteredMirrorCandidates(
             [phpEnum("app/Enums/Foo.php", ["a", "b"])],
             [tsCandidate("resources/js/types/x.ts", "CompletelyUnrelatedName", ["a", "b", "c"])],
             notRegistered,
@@ -326,7 +607,7 @@ describe("findUnregisteredMirrorCandidates() (逆走査の突き合わせ純関
     });
 
     it("E9: 名前も値も対応しなければ見つからない", () => {
-        const found = findUnregisteredMirrorCandidates(
+        const { found } = findUnregisteredMirrorCandidates(
             [phpEnum("app/Enums/Foo.php", ["a", "b"])],
             [tsCandidate("resources/js/types/x.ts", "Bar", ["x", "y"])],
             notRegistered,
@@ -334,17 +615,21 @@ describe("findUnregisteredMirrorCandidates() (逆走査の突き合わせ純関
         expect(found).toEqual([]);
     });
 
-    it("E10: 名前対応は英数字以外を除去しない (Foo_Bar と FooBar は同一視しない)", () => {
-        const found = findUnregisteredMirrorCandidates(
+    it("E10: 厳密名対応 (2a) は英数字以外を除去しない。語対応 (2b) は区切りとして割るので成立する", () => {
+        // 2a の側は Foo_Bar と FooBar を同一視しない (この不変条件は維持する)。
+        expect(strictNameCorrespondence("FooBar", "Foo_Bar")).toBeNull();
+        // 論理和にしたので、語に割れば対応する 2b の側では鳴る (意図した拡張)。
+        const { found } = findUnregisteredMirrorCandidates(
             [phpEnum("app/Enums/Foo_Bar.php", ["a", "b"])],
             [tsCandidate("resources/js/types/x.ts", "FooBar", ["a", "z"])],
             notRegistered,
         );
-        expect(found).toEqual([]);
+        expect(found).toHaveLength(1);
+        expect(found[0].rule).toBe("2b");
     });
 
     it("E11: 名前の一部が一致するだけ (部分文字列) では対応と認めない", () => {
-        const found = findUnregisteredMirrorCandidates(
+        const { found } = findUnregisteredMirrorCandidates(
             [phpEnum("app/Enums/Foo.php", ["a", "b"])],
             [tsCandidate("resources/js/types/x.ts", "MyFooValue", ["a", "z"])],
             notRegistered,
@@ -353,29 +638,334 @@ describe("findUnregisteredMirrorCandidates() (逆走査の突き合わせ純関
     });
 
     it("E12: 大文字小文字の違いだけは対応と認める (名前対応は大小無視)", () => {
-        const found = findUnregisteredMirrorCandidates(
+        const { found } = findUnregisteredMirrorCandidates(
             [phpEnum("app/Enums/Foo.php", ["a", "b", "c"])],
             [tsCandidate("resources/js/types/x.ts", "FOO", ["a", "z"])],
             notRegistered,
         );
         expect(found).toHaveLength(1);
-        expect(found[0].rule).toBe(2);
+        expect(found[0].rule).toBe("2a");
     });
 
     it("E13: 判定は ResolvedPhpEnum.name を使う (ファイル名の語幹と enum 名が食い違っていても name を見る)", () => {
-        const found = findUnregisteredMirrorCandidates(
-            [{ path: "app/Enums/FileStem.php", name: "ActualEnumName", values: new Set(["a", "b"]) }],
+        const { found } = findUnregisteredMirrorCandidates(
+            [{ path: "app/Enums/FileStem.php", name: "ActualEnumName", line: 1, values: new Set(["a", "b"]) }],
             [tsCandidate("resources/js/types/x.ts", "ActualEnumName", ["a", "z"])],
             notRegistered,
         );
         expect(found).toHaveLength(1);
-        expect(found[0].rule).toBe(2);
+        expect(found[0].rule).toBe("2a");
         // ファイル名の語幹 (FileStem) とは対応しないので、そちらでは見つからないことも確かめる。
         const notFoundByFileStem = findUnregisteredMirrorCandidates(
-            [{ path: "app/Enums/FileStem.php", name: "ActualEnumName", values: new Set(["a", "b"]) }],
+            [{ path: "app/Enums/FileStem.php", name: "ActualEnumName", line: 1, values: new Set(["a", "b"]) }],
             [tsCandidate("resources/js/types/x.ts", "FileStem", ["a", "z"])],
             notRegistered,
         );
-        expect(notFoundByFileStem).toEqual([]);
+        expect(notFoundByFileStem.found).toEqual([]);
+    });
+});
+
+/** カテゴリ 5: 規則 2 の論理和 (2a と 2b はどちらも他方を包含しない)。 */
+describe("規則 2 の論理和 (2a ∨ 2b)", () => {
+    it("wordForms() の期待値 (1 つの正規形へ畳まない)", () => {
+        expect([...wordForms("status")].sort()).toEqual(["statu", "status"]);
+        expect([...wordForms("statuses")].sort()).toEqual(["status", "statuse", "statuses"]);
+        expect([...wordForms("class")].sort()).toEqual(["class"]);
+        expect([...wordForms("policies")].sort()).toEqual(["policie", "policies", "policy"]);
+        expect([...wordForms("kind")].sort()).toEqual(["kind"]);
+    });
+
+    it.each([
+        ["status", "statuses", true],
+        ["class", "classes", true],
+        ["policy", "policies", true],
+        ["value", "values", true],
+        ["kind", "kinds", true],
+        ["case", "cases", true],
+        ["response", "responses", true],
+        ["use", "uses", true],
+        ["status", "state", false],
+        ["code", "codec", false],
+    ] as const)("語の対応: %s ⇔ %s = %s", (a, b, expected) => {
+        expect(correspondWords(a, b)).toBe(expected);
+        expect(correspondWords(b, a)).toBe(expected);
+    });
+
+    it("最大マッチング: 候補側の 1 語を 2 回数えない", () => {
+        expect(maxWordMatching(["status", "status"], ["status"])).toBe(1);
+        expect(maxWordMatching(["status", "status"], ["status", "statuses"])).toBe(2);
+    });
+
+    it("最大マッチング: 増補路が要る入力でも最大値へ届く", () => {
+        // 左 L1 は {R1, R2} と、L2 は {R1} とだけ対応する。貪欲に L1→R1 を選んでも
+        // 付け替えて大きさ 2 になること。
+        expect(maxWordMatching(["kind", "case"], ["cases", "kinds"])).toBe(2);
+    });
+
+    it("2b だけが拾う組 (厳密名対応では鳴らない)", () => {
+        const outcome = matchReverseRule(
+            phpEnum("app/Enums/JobStatus.php", ["queued", "running", "succeeded", "failed"]),
+            tsCandidate("resources/js/types/dashboard.ts", "DashboardJobStatus", ["queued", "running"]),
+        );
+        expect(outcome.kind === "match" && outcome.rule).toBe("2b");
+    });
+
+    it("2a だけが拾う組 (両側半分以上の交差を満たさない)", () => {
+        const outcome = matchReverseRule(
+            phpEnum("app/Enums/Foo.php", ["a", "b", "c", "d", "e"]),
+            tsCandidate("resources/js/types/x.ts", "Foo", ["a", "y", "z"]),
+        );
+        expect(outcome.kind === "match" && outcome.rule).toBe("2a");
+    });
+
+    it("2a と 2b の両方に当たる組は 2a が勝つ (判定は排他)", () => {
+        const outcome = matchReverseRule(
+            phpEnum("app/Enums/JobStatus.php", ["queued", "running", "failed"]),
+            tsCandidate("resources/js/types/x.ts", "JobStatus", ["queued", "running", "zzz-extra"]),
+        );
+        expect(outcome.kind === "match" && outcome.rule).toBe("2a");
+    });
+
+    it.each([
+        ["接頭辞つき", "PrejobStatus"],
+        ["打ち消しつき", "JobNonstatus"],
+        ["接尾辞つき", "JobStatusKind"],
+    ])("2b の負例 3 形 (%s) は鳴らない", (_label, name) => {
+        const outcome = matchReverseRule(
+            phpEnum("app/Enums/JobStatus.php", ["queued", "running", "failed"]),
+            tsCandidate("resources/js/types/x.ts", name, ["queued", "running"]),
+        );
+        expect(outcome.kind).toBe("none");
+    });
+
+    it("2b は主要語が一致しても交差が片側半分未満なら鳴らない", () => {
+        const outcome = matchReverseRule(
+            phpEnum("app/Enums/JobStatus.php", ["queued", "running", "succeeded", "failed"]),
+            tsCandidate("resources/js/types/x.ts", "DashboardJobStatus", ["queued", "z1", "z2", "z3"]),
+        );
+        expect(outcome.kind).toBe("none");
+    });
+
+    it("境界 8': 名前を決められない候補は、交差があれば判定不能・無ければ鳴らない", () => {
+        const undecided = matchReverseRule(
+            phpEnum("app/Enums/Foo.php", ["a", "b", "c"]),
+            tsCandidate("resources/js/types/x.ts", "switch:next()", ["a", "z"], {
+                shape: "switch-cases",
+                correspondenceName: null,
+            }),
+        );
+        expect(undecided.kind).toBe("undecidable");
+
+        const silent = matchReverseRule(
+            phpEnum("app/Enums/Foo.php", ["a", "b", "c"]),
+            tsCandidate("resources/js/types/x.ts", "switch:next()", ["y", "z"], {
+                shape: "switch-cases",
+                correspondenceName: null,
+            }),
+        );
+        expect(silent.kind).toBe("none");
+    });
+
+    it("宣言名から語が取れない候補は例外になる (静かに名前不一致へ混ぜない)", () => {
+        expect(() =>
+            matchReverseRule(
+                phpEnum("app/Enums/Foo.php", ["a", "b"]),
+                tsCandidate("resources/js/types/x.ts", "___", ["a", "z"], { correspondenceName: "___" }),
+            ),
+        ).toThrow("宣言名から語を 1 つも取り出せません");
+    });
+});
+
+/** カテゴリ 6: 申告の生死判定は「免除を適用する前」の集合で行う。 */
+describe("auditReverseSweepExemptions() (申告の突き合わせ)", () => {
+    const php = phpEnum("app/Enums/Foo.php", ["a", "b"]);
+    const candidate = tsCandidate("resources/js/types/x.ts", "Unrelated", ["a", "b"]);
+    const exemption = {
+        php: php.path,
+        locator: candidate.locator,
+        rule: "1",
+        reason: "テストの見本なので登録しない (30 文字以上の理由をここに書いておく)",
+    } as const;
+
+    it("申告した候補は unexempted から外れ、stale にもならない", () => {
+        const { found } = findUnregisteredMirrorCandidates([php], [candidate], () => false);
+        const audit = auditReverseSweepExemptions(found, [exemption]);
+        expect(audit.unexempted).toEqual([]);
+        expect(audit.stale).toEqual([]);
+    });
+
+    it("免除を適用した後の集合で判定すると、自分自身を根拠にする申告が stale になる", () => {
+        const { found } = findUnregisteredMirrorCandidates([php], [candidate], () => false);
+        const afterExemption = auditReverseSweepExemptions(found, [exemption]).unexempted;
+        // 生死判定に「免除適用後」を渡すと、申告が実態から消えたことになる = この形にしない。
+        expect(auditReverseSweepExemptions(afterExemption, [exemption]).stale).toHaveLength(1);
+    });
+
+    it("規則が移ると申告は stale になる", () => {
+        const { found } = findUnregisteredMirrorCandidates([php], [candidate], () => false);
+        expect(auditReverseSweepExemptions(found, [{ ...exemption, rule: "2a" }]).stale).toHaveLength(1);
+    });
+
+    it("occurrence が違うと申告は stale になる", () => {
+        const { found } = findUnregisteredMirrorCandidates([php], [candidate], () => false);
+        const moved = { ...exemption, locator: { ...exemption.locator, occurrence: 1 } };
+        expect(auditReverseSweepExemptions(found, [moved]).stale).toHaveLength(1);
+    });
+});
+
+/** カテゴリ 9: 本番の走査を通した候補の形・locator・派生・証人。 */
+describe("collectTsCandidates() (本番の走査を通した見本の検査)", () => {
+    let programs: MirrorPrograms | undefined;
+    let scan: TsCandidateScan | undefined;
+
+    const requireScan = (): TsCandidateScan => {
+        if (scan === undefined) throw new EnumTsSyncError("scan", "初期化されていません");
+        return scan;
+    };
+
+    const find = (
+        file: string,
+        shape: TsCandidateShape,
+        name: string,
+        occurrence = 0,
+    ): TsUnionCandidate | undefined =>
+        requireScan().candidates.find(
+            (candidate) => locatorKey(candidate.locator) === `${file}|${shape}|${name}|${occurrence}`,
+        );
+
+    const values = (
+        file: string,
+        shape: TsCandidateShape,
+        name: string,
+        occurrence = 0,
+    ): readonly string[] => [...(find(file, shape, name, occurrence)?.values ?? [])].sort();
+
+    beforeAll(() => {
+        programs = createMirrorPrograms();
+        scan = collectTsCandidates(programs);
+    }, 300_000);
+
+    it("母集団は版管理下の全数で、道具パッケージも `.svelte` も含む", () => {
+        const { population } = programs ?? { population: { ts: [], svelte: [] } };
+        expect(population.ts).toContain("packages/cli/src/api/schemas.ts");
+        expect(population.svelte).toContain("resources/js/components/features/manual/ScenarioEditor.svelte");
+        expect(programs?.ownerOf("packages/cli/src/api/schemas.ts")).toBe("packages/cli");
+        expect(programs?.ownerOf("resources/js/types/manual.ts")).toBe("<root>");
+    });
+
+    it("道具パッケージは自前の tsconfig (NodeNext) で解決される", () => {
+        // ルートの設定 (bundler) で読むと `./schemas.js` の取り込みが解決できず、
+        // 型が any へ落ちた宣言が「非候補」として静かに消える。
+        expect(values("packages/cli/src/api/schemas.ts", "const-array", "API_ERROR_CODES")).toContain(
+            "rate_limited",
+        );
+        expect(values("packages/cli/src/api/schemas.ts", "literal-union", "ApiErrorCode")).toContain(
+            "quota_exceeded",
+        );
+    });
+
+    it("4 形すべてを拾う", () => {
+        expect(values(`${FIXTURE}/mixed.ts`, "literal-union", "LiteralUnionCandidate")).toEqual(["a", "b"]);
+        expect(values(`${FIXTURE}/mixed.ts`, "const-array", "ConstArrayCandidate")).toEqual([
+            "zzz-sample-1",
+            "zzz-sample-2",
+        ]);
+        expect(values(`${FIXTURE}/mixed.ts`, "object-keys", "ObjectKeysCandidate")).toEqual([
+            "zzz-key-1",
+            "zzzKey2",
+        ]);
+        expect(values(`${FIXTURE}/mixed.ts`, "switch-cases", "switch:value")).toEqual(["a", "b"]);
+    });
+
+    it("包み (as const / satisfies / 丸括弧) を剥がして読む", () => {
+        expect(values(`${FIXTURE}/mixed.ts`, "const-array", "ConstArrayAsConst")).toEqual(["zzz-sample-3"]);
+        expect(values(`${FIXTURE}/mixed.ts`, "const-array", "ConstArraySatisfies")).toEqual(["zzz-sample-4"]);
+        expect(values(`${FIXTURE}/mixed.ts`, "const-array", "ConstArrayParenthesized")).toEqual(["zzz-sample-5"]);
+    });
+
+    it("非候補は拾わない (開いた文字列 / 数値 / let の配列 / 非リテラル混在 / 空配列 / 展開)", () => {
+        for (const [shape, name] of [
+            ["literal-union", "NotAUnionCandidate"],
+            ["literal-union", "NumberCandidate"],
+            ["literal-union", "ExplicitAnyCandidate"],
+            ["literal-union", "ExplicitUnknownCandidate"],
+            ["const-array", "LetArrayCandidate"],
+            ["const-array", "MixedArrayCandidate"],
+            ["const-array", "EmptyArrayCandidate"],
+            ["object-keys", "ObjectSpreadCandidate"],
+        ] as const) {
+            expect(find(`${FIXTURE}/mixed.ts`, shape, name), `${name} は非候補であること`).toBeUndefined();
+        }
+    });
+
+    it("計算キーは型検査器が文字列リテラルへ解決したときだけ読む", () => {
+        expect(values(`${FIXTURE}/mixed.ts`, "object-keys", "ObjectComputedKeyCandidate")).toEqual(["zzz-key-4"]);
+    });
+
+    it("判定保留は候補にも非候補にもならず indeterminate へ入る", () => {
+        const keys = requireScan().indeterminate.map((row) => locatorKey(row.locator));
+        expect(keys).toContain(`${FIXTURE}/mixed.ts|literal-union|IndirectAnyCandidate|0`);
+        expect(keys).toContain(`${FIXTURE}/mixed.ts|object-keys|ObjectAnyComputedKeyCandidate|0`);
+        expect(find(`${FIXTURE}/mixed.ts`, "literal-union", "IndirectAnyCandidate")).toBeUndefined();
+    });
+
+    it("入れ子の宣言も拾い、同名なら occurrence で区別する", () => {
+        expect(values(`${FIXTURE}/mixed.ts`, "literal-union", "NestedShadow", 0)).toEqual(["zzz-nested-1"]);
+        expect(values(`${FIXTURE}/mixed.ts`, "literal-union", "NestedShadow", 1)).toEqual(["zzz-nested-2"]);
+        expect(find(`${FIXTURE}/mixed.ts`, "literal-union", "NestedShadow", 0)?.topLevel).toBe(false);
+    });
+
+    it("入れ子が先・最上位が後なら、最上位の occurrence は 0 ではない", () => {
+        const nested = find(`${FIXTURE}/nested-occurrence.ts`, "literal-union", "NestedFirst", 0);
+        const top = find(`${FIXTURE}/nested-occurrence.ts`, "literal-union", "NestedFirst", 1);
+        expect(nested?.topLevel).toBe(false);
+        expect(top?.topLevel).toBe(true);
+        expect([...(top?.values ?? [])]).toEqual(["zzz-nested-4"]);
+    });
+
+    it("派生の対応表は証人があるときだけ外れる", () => {
+        for (const name of ["DerivedRecord", "DerivedSatisfies", "DerivedViaAlias", "DerivedViaKeyof", "DerivedViaImport"]) {
+            expect(find(`${FIXTURE}/derived.ts`, "object-keys", name), `${name} は派生として外れる`).toBeUndefined();
+        }
+        for (const name of [
+            "DerivedPartial",
+            "DerivedIndexSignature",
+            "DerivedMissingKey",
+            "DerivedExtraKey",
+            "DerivedUnionType",
+            "DerivedIntersectionType",
+            "DerivedNoExplicitType",
+            "DerivedWitnessless",
+        ]) {
+            expect(find(`${FIXTURE}/derived.ts`, "object-keys", name), `${name} は候補として残る`).toBeDefined();
+        }
+    });
+
+    it("証人は対応表以外の形に限る (自己証人・相互証人・循環証人では消えない)", () => {
+        for (const name of [
+            "SelfWitness",
+            "MutualWitnessA",
+            "MutualWitnessB",
+            "CycleWitnessA",
+            "CycleWitnessB",
+            "CycleWitnessC",
+        ]) {
+            expect(find(`${FIXTURE}/witness-cycle.ts`, "object-keys", name), `${name} は候補として残る`).toBeDefined();
+        }
+    });
+
+    it(".svelte の script の中の 4 形を拾い、module と実体を 1 つの単位として扱う", () => {
+        const svelte = "tests/js/support/enum-ts-sync/fixtures/svelte/Sample.svelte";
+        expect(values(svelte, "literal-union", "SampleModuleKind")).toEqual(["zzz-svelte-1", "zzz-svelte-2"]);
+        // 実体側から module 側の型別名を参照できる (Svelte 本来の可視性)。
+        expect(values(svelte, "literal-union", "SampleInstanceKind")).toEqual(["zzz-svelte-1", "zzz-svelte-2"]);
+        expect(values(svelte, "const-array", "SAMPLE_LIST")).toEqual(["zzz-svelte-1", "zzz-svelte-2"]);
+        expect(values(svelte, "object-keys", "SAMPLE_LABELS")).toEqual(["zzz-svelte-1", "zzz-svelte-2"]);
+    });
+
+    it(".svelte はモジュール文脈なので、別のコンポーネントの同名宣言と混ざらない", () => {
+        expect(
+            values("tests/js/support/enum-ts-sync/fixtures/svelte/Other.svelte", "literal-union", "SampleInstanceKind"),
+        ).toEqual(["zzz-svelte-3"]);
     });
 });
diff --git a/tests/js/architecture/enum-ts-sync-discovery.test.ts b/tests/js/architecture/enum-ts-sync-discovery.test.ts
index d102ca93..a9c85d32 100644
--- a/tests/js/architecture/enum-ts-sync-discovery.test.ts
+++ b/tests/js/architecture/enum-ts-sync-discovery.test.ts
@@ -1,10 +1,9 @@
 /**
- * PHP の文字列付き列挙の発見の段と逆走査 (家系の裁定 AG-099 後半 / T225)。
+ * PHP の文字列付き列挙の発見の段と逆走査 (家系の機能台帳 `enum-ts-sync-gate` の正典 v3)。
  *
- * `enum-ts-sync.test.ts` は「目録 (`ENUM_TS_MIRRORS`) に登録した写しだけ」を見る検査で、
- * 登録し忘れた PHP 列挙・TS 宣言は 1 件も検査していなかった (`docs/template-divergence.md`
- * の D29 が記録していた欠落)。本ファイルは向きを変え、次の 2 段で「登録し忘れ」を
- * **既定拒否 (deny-by-default)** で炙り出す。
+ * `enum-ts-sync.test.ts` は「目録 (`ENUM_TS_RELATIONS`) に登録した関係だけ」を見る検査で、
+ * 登録し忘れた PHP 列挙・TS 宣言は 1 件も検査していなかった。本ファイルは向きを変え、
+ * 次の 2 段で「登録し忘れ」を**既定拒否 (deny-by-default)** で炙り出す。
  *
  * ## 1. 発見の段 (全数走査 → 既定拒否の分類)
  *
@@ -12,40 +11,40 @@
  * 値集合を読めた PHP の文字列付き列挙 (`resolved`) と、読めなかったもの (`unresolvable`)
  * に分ける。`resolved` の**すべて**が次のどちらか一方に分類されていることを固定する。
  *
- * - **登録済み** (`ENUM_TS_MIRRORS` に php パスがある)
- * - **対象外の理由つき** (`PHP_ENUM_EXEMPTIONS` に登録がある。TS 側に写しを作らない
+ * - **TS との関係を登録済み** (`ENUM_TS_RELATIONS` に php パスがある。
+ *   `equal` と `subset` の両方を含むので「写しを登録済み」とは呼ばない)
+ * - **対象外の理由つき** (`PHP_ENUM_EXEMPTIONS` に登録がある。TS 側に値域の写しを作らない
  *   意図的な判断で、理由を 30 文字以上で書く)
  *
  * `unresolvable` の**すべて**が `KNOWN_UNRESOLVABLE_PHP_ENUMS` に登録されていることを
  * 固定する (本 gate 専用の字句走査器では値集合を読み切れないと分かっている残余)。
  *
- * どの分類にも入らない PHP 列挙が 1 件でもあれば赤くする (**既定拒否**)。
- * 逆に、分類の登録先が実際にはその分類でなくなった (stale) ときも赤くする
- * (登録が実態と食い違ったまま残るのを防ぐ)。
+ * ## 2. 逆走査 (未登録候補の検出。母集団の全数 → 4 形の候補 → 3 規則)
  *
- * ## 2. 逆走査 (未登録候補の検出。2 規則)
+ * - **母集団 (i8)**: 版管理下の `*.ts` と `*.svelte` の**全数**
+ *   (`population.ts`。唯一の除外は検出器自身の構文破壊見本 1 ディレクトリ)
+ * - **候補の形 (i9)**: リテラル型の合併 / 定数の配列 / 対応表のキー / 分岐のラベルの 4 種
+ * - **規則**: 完全一致 (規則 1) / 厳密名対応 + 1 値交差 (規則 2a) /
+ *   語分割名対応 + 両側半分以上の交差 (規則 2b)。**規則 2 は 2a と 2b の論理和**である
  *
- * `collectTsUnionCandidates()` が `resources/js/` 配下の文字列リテラル型だけの union に
- * 解決する型別名を全数走査し、`findUnregisteredMirrorCandidates()` が
- * 未登録 (`ENUM_TS_MIRRORS` に無い) の宣言を PHP の母集団と突き合わせて次の 2 規則で拾う。
- *
- * - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全一致する未登録の宣言 = 登録漏れの疑い
- * - **規則 2 (名前対応 + 値の交差)**: 名前が厳密に対応し値が交差するが完全一致ではない
- *   未登録の宣言 = 片方だけ値を足してズレた写しの疑い
- *
- * 見つかった候補は `REVERSE_SWEEP_EXEMPTIONS` に登録された分だけ許す
- * (意図的に登録しない判断を明示する)。未登録の候補が 1 件でもあれば赤くする。
+ * 見つかった候補は `REVERSE_SWEEP_EXEMPTIONS` に登録された分だけ許す。
+ * 候補かどうかを決められなかった宣言 (判定保留) は `KNOWN_INDETERMINATE_TS_DECLARATIONS`
+ * に登録された分だけ許す (どちらも既定拒否)。
  *
  * **保証しないもの (誇張しない)**:
- * - 名前も対応せず値も完全一致しない drift 済みの写しは検出できない (規則の意図した限界)
- * - 緩い名前対応 (部分集合・ファイル名を名前に混ぜる形) は採らない。実測 (家系の記録) で
- *   偽陽性が支配的になるため、名前対応は「一致 / +s / +es / +values」の厳密な形だけを見る
- * - `.svelte` の中の宣言・定数配列・switch の case ラベルは走査しない
- *   (`collectTsUnionCandidates` は `type X = …` のトップレベル宣言だけを見る。
- *   `.d.ts` も対象外)
+ * - 版管理外のファイル (無視されたもの・未追跡のもの) は見ない。
+ *   `.js` / `.mjs` / `.cjs` は母集団に入れない。`.d.ts` は候補にしない
+ * - `.svelte` は script の中だけを見る (目印の中・制御構文の中・スタイルは見ない)。
+ *   ただし**ファイル全体が `parse` できることは前提**である
+ * - 「すべての要素が読める」形だけを候補にする (1 つでも読めない要素があれば候補にしない)
+ * - 派生として外した対応表は、**証人 (対応表以外の形の候補) がある場合だけ**外れる
+ * - 分岐のラベルと対応表のキーは**登録できない**。写しなら型別名か定数の配列へ切り出す
+ * - パッケージの型は**そのパッケージ自身の tsconfig** で解決する
+ *   (ルートの設定で解決するわけではない)
+ * - 除外根 (`fixtures/candidates-broken`) の中は見ない。
+ *   `fixtures/` の残りは**見る** (見本を書き換えると本番の候補集合も動く)
+ * - 名前も対応せず値も半分未満しか交差しない drift 済みの写しは検出できない (規則の限界)
  * - PHP 側の母集団は `php-enum-catalog.ts` の docblock が明記する範囲に限る
- *   (走査器が読み切れない字句を含むファイルは、生のソースに `enum` の語が
- *   無ければ母集団から外れる。あれば安全側に倒して `unresolvable` へ回る)
  *
  * 正本のレーンは `pnpm test`。詳細は `docs/architecture.md`
  * §PHP 列挙と TypeScript 値域の同期。
@@ -53,11 +52,36 @@
 import { beforeAll, describe, expect, it } from "vitest";
 import fs from "node:fs";
 import path from "node:path";
-import { createMirrorProgram, REPO_ROOT, type MirrorProgram } from "../support/enum-ts-sync/program";
+import {
+    createFixtureProgram,
+    createMirrorPrograms,
+    REPO_ROOT,
+    type MirrorPrograms,
+} from "../support/enum-ts-sync/program";
+import {
+    EXCLUDED_ROOTS,
+    EXPECTED_EXCLUDED_ROOT_COUNT,
+    listExcludedFiles,
+    validateExcludedRoots,
+} from "../support/enum-ts-sync/population";
+import { toVirtualUnit } from "../support/enum-ts-sync/svelte-source";
 import { buildPhpEnumCatalog, type PhpEnumCatalog } from "../support/enum-ts-sync/php-enum-catalog";
-import { collectTsUnionCandidates, type TsUnionCandidate } from "../support/enum-ts-sync/ts-candidates";
-import { findUnregisteredMirrorCandidates } from "../support/enum-ts-sync/reverse-sweep";
-import { ENUM_TS_MIRRORS, registeredPhpPaths, registeredTsKeys } from "../support/enum-ts-sync/mirror-inventory";
+import {
+    collectTsCandidates,
+    locatorKey,
+    type TsCandidateLocator,
+    type TsCandidateScan,
+    type TsCandidateShape,
+} from "../support/enum-ts-sync/ts-candidates";
+import {
+    auditReverseSweepExemptions,
+    findUnregisteredMirrorCandidates,
+    type ReverseSweepResult,
+    type ReverseSweepRule,
+    type UnregisteredMirrorCandidate,
+} from "../support/enum-ts-sync/reverse-sweep";
+import { ENUM_TS_RELATIONS, declaredPhpPaths, validateRelations } from "../support/enum-ts-sync/relation-inventory";
+import { resolveRelations } from "../support/enum-ts-sync/ts-value-sets";
 
 interface PhpEnumExemption {
     /** リポジトリルートからの PHP 列挙ファイルの相対パス。 */
@@ -68,7 +92,7 @@ interface PhpEnumExemption {
 
 /**
  * 「対象外の理由つき」に分類する PHP の文字列付き列挙。
- * ここに無く、かつ `ENUM_TS_MIRRORS` にも無い `resolved` エントリが 1 件でもあれば
+ * ここに無く、かつ `ENUM_TS_RELATIONS` にも無い `resolved` エントリが 1 件でもあれば
  * 発見の段が赤くなる (既定拒否)。
  */
 const PHP_ENUM_EXEMPTIONS = [
@@ -82,8 +106,7 @@ const PHP_ENUM_EXEMPTIONS = [
     { path: "app/DataTransferObjects/Manual/Render/RenderClipSource.php", reason: "レンダーパイプライン内部でクリップの取得元を表す区分。フロントは個別のフラグで結果を受け取り、この値そのものは渡らない" },
     { path: "app/Enums/Account/AccountDeletionFreezeAllowance.php", reason: "退会凍結中に許可する route 名相当の内部許可リスト。ガード判定にのみ使い、画面には表示しない" },
     { path: "app/Enums/AccountDeletionBlockReason.php", reason: "退会ブロックの内部理由コード。画面には理由ごとの案内文をサーバ側で確定して渡すだけである" },
-    { path: "app/Enums/ApiErrorCode.php", reason: "公開 API のエラーコード語彙。TS 側はコードで分岐せず HTTP 状態とエラー文言だけを見る" },
-    { path: "app/Enums/ApiKeyAbility.php", reason: "API キー権限 (read/write) の内部語彙。管理画面はチェックボックスの選択状態だけを見る" },
+    { path: "app/Enums/ApiKeyAbility.php", reason: "API キー権限 (read/write)。画面はチェックボックスの選択状態で操作し、表示ラベル表は未知の値を素の文字列へ退避するため値域の写しを要さない" },
     { path: "app/Enums/Auth/AuthMethodChangeEvent.php", reason: "認証手段変更メール通知の内部分類 (T110)。件名・本文はサーバ側で確定して送るだけで画面へは一切渡らない" },
     { path: "app/Enums/Auth/EmailVerificationGateContext.php", reason: "メール確認ゲートの発生元コンテキスト。内部のルーティング判定にのみ使う語彙である" },
     { path: "app/Enums/Billing/AutoRechargeAttemptStatus.php", reason: "自動追加購入試行の内部状態機械。画面は結果の通知種別 (BillingFeedbackKind) 経由でしか見ない" },
@@ -127,8 +150,7 @@ const PHP_ENUM_EXEMPTIONS = [
     { path: "app/Enums/Manual/LlmOutputInvalidReason.php", reason: "LLM 出力不正の内部理由。画面には再試行可否の結果だけが渡る" },
     { path: "app/Enums/Manual/ShotType.php", reason: "ショット種別 (hiki/yori) の内部語彙。台本表示は文言化済みの値を受け取るだけである" },
     { path: "app/Enums/Mcp/ToolName.php", reason: "MCP ツール名の内部登録名。Web UI からは呼ばれない CLI/MCP 専用の語彙である" },
-    { path: "app/Enums/OAuth/CliOAuthScope.php", reason: "CLI OAuth スコープの内部語彙。認可判定にのみ使い画面へは出ない" },
-    { path: "app/Enums/OAuth/OAuthClientKind.php", reason: "OAuth クライアント種別の内部判定。認可ロジックの内部でのみ使う" },
+    { path: "app/Enums/OAuth/OAuthClientKind.php", reason: "OAuth クライアント種別。認可判定の内部語彙で、画面の表示ラベル表は未知の値を素の文字列へ退避するため値域の写しを要さない" },
     { path: "app/Enums/Organization/SlugReservationReason.php", reason: "組織識別名の予約理由の 3 分類 (家系裁定 AG-039)。設定ファイルの読み込み検査とレビューのための語彙で、画面には拒否の文言だけが渡る" },
     { path: "app/Enums/ProjectRole.php", reason: "プロジェクトロールの内部判定。画面は権限の有無を真偽値として受け取るだけである" },
     { path: "app/Enums/ProviderCapability.php", reason: "認証プロバイダの能力分類の内部語彙。認可ロジックの内部でのみ使う" },
@@ -170,7 +192,7 @@ const PHP_ENUM_EXEMPTIONS = [
 ] as const satisfies readonly PhpEnumExemption[];
 
 /** `PHP_ENUM_EXEMPTIONS` の件数の pin。増えても減っても赤くする。 */
-const EXPECTED_EXEMPTION_COUNT = 95;
+const EXPECTED_EXEMPTION_COUNT = 93;
 
 interface UnresolvablePhpEnumEntry {
     readonly path: string;
@@ -198,59 +220,191 @@ const KNOWN_UNRESOLVABLE_PHP_ENUMS = [
 
 const EXPECTED_UNRESOLVABLE_COUNT = 3;
 
+
 interface ReverseSweepExemption {
     /** 一致した PHP 列挙のパス。 */
     readonly php: string;
-    /** 未登録の TS 宣言のファイル。 */
-    readonly file: string;
-    /** 未登録の TS 宣言の名前。 */
-    readonly declaration: string;
-    readonly rule: 1 | 2;
+    /** 未登録の TS 宣言の locator (置き場・形・名前・出現順の 4 つ組)。 */
+    readonly locator: TsCandidateLocator;
+    /** 適用された規則。**規則が移ると申告は stale になる**。 */
+    readonly rule: ReverseSweepRule;
     /** 登録しない理由 (30 文字以上)。 */
     readonly reason: string;
 }
 
+const locator = (file: string, shape: TsCandidateShape, name: string, occurrence = 0): TsCandidateLocator => ({
+    file,
+    shape,
+    name,
+    occurrence,
+});
+
 /**
  * 逆走査が見つける候補のうち、意図的に登録しないものの一覧。
- * `(php, file, declaration, rule)` の組が完全一致したものだけを免除する
+ * `(php, locator, rule)` が完全一致したものだけを免除する
  * (php パスまで固定するので、たまたま同じ値集合を持つ**別の** PHP 列挙が現れたときは
- * 新しい候補として検出され続ける)。
+ * 新しい候補として検出され続ける。`occurrence` まで固定するので、同名の入れ子の宣言が
+ * 前に足されると申告が stale になり赤くなる = 人が見直す合図である)。
  */
 const REVERSE_SWEEP_EXEMPTIONS = [
     {
         php: "app/Enums/Manual/TakeStatus.php",
-        file: "resources/js/types/manual.ts",
-        declaration: "SelectableTakeStatus",
-        rule: 1,
+        locator: locator("resources/js/types/manual.ts", "literal-union", "SelectableTakeStatus"),
+        rule: "1",
         reason: "「選択できるテイクの状態」という部分集合の意図の宣言。今は TakeStatus と値が完全一致するが、意図は部分集合なので登録しない",
     },
+    {
+        php: "app/Enums/Manual/CutType.php",
+        locator: locator(
+            "resources/js/components/features/manual/ScenarioEditor.svelte",
+            "literal-union",
+            "DragOwner",
+        ),
+        rule: "1",
+        reason: "台本編集のドラッグの所有者 (カット / 素材) という別概念で、値がたまたまカット種別と一致しているだけである。似ているからで統合しない (思考原則 4)",
+    },
+    {
+        php: "app/Enums/Notification/NotificationType.php",
+        locator: locator(
+            "resources/js/components/features/notifications/NotificationListItem.svelte",
+            "switch-cases",
+            "switch:notification.type",
+        ),
+        rule: "1",
+        reason: "通知の絵柄を選ぶ分岐。既定の枝があるので、種別が増えると新種の通知は汎用のベルの絵柄で出る (操作は詰まらない)。期待動作は「新種を足すときに絵柄も足す」であり、値が増えれば完全一致が崩れて本申告が stale になり赤くなる",
+    },
+    {
+        php: "app/Enums/ApiKeyAbility.php",
+        locator: locator("resources/js/pages/Organizations/ApiKeys/Index.svelte", "object-keys", "ABILITY_LABELS"),
+        rule: "1",
+        reason: "API キー権限の表示ラベル表。未知の値は素の文字列で表示する退避 (?? ability) があるので、値の取りこぼしが画面を壊さない。値域の写しではない",
+    },
+    {
+        php: "app/Enums/OAuth/OAuthClientKind.php",
+        locator: locator("resources/js/pages/Organizations/ApiKeys/Sessions.svelte", "object-keys", "CLIENT_KIND_LABELS"),
+        rule: "1",
+        reason: "OAuth クライアント種別の表示ラベル表。未知の値は素の文字列で表示する退避 (?? kind) があるので、値の取りこぼしが画面を壊さない。値域の写しではない",
+    },
+    {
+        php: "app/Enums/EnterpriseSso/OidcConnectionStatus.php",
+        locator: locator("tests/js/components/features/sso/oidc-connection.test.ts", "const-array", "ALL_STATUSES"),
+        rule: "1",
+        reason: "検査が全値を並べた入力であって画面の写しではない。目録の置き場は resources/js と packages/<name>/src に限るので、そもそも登録できない",
+    },
+    {
+        php: "app/Enums/Manual/JobStatus.php",
+        locator: locator("resources/js/types/dashboard.ts", "literal-union", "DashboardJobStatus"),
+        rule: "2b",
+        reason: "ダッシュボードが出す「進行中のジョブ」だけを表す意図した真部分集合である。終端の状態はダッシュボードに出ないので値域の写しにしない",
+    },
+    {
+        php: "app/Enums/ApiErrorCode.php",
+        locator: locator("packages/cli/src/api/schemas.ts", "literal-union", "ApiErrorCode"),
+        rule: "2a",
+        reason: "サーバの符号 (API_ERROR_CODES) と正規でない面固有の符号 (NON_CANONICAL_API_ERROR_CODES) の合併型である。写しの実体は API_ERROR_CODES として relation equal で登録済みで、合併型そのものは写しではない",
+    },
 ] as const satisfies readonly ReverseSweepExemption[];
 
-const EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT = 1;
+const EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT = 8;
+
+interface IndeterminateTsEntry {
+    readonly locator: TsCandidateLocator;
+    /** 判定保留のまま残す理由 (30 文字以上)。 */
+    readonly reason: string;
+}
+
+/**
+ * 候補かどうかを**決められなかった** TS 宣言 (判定保留) の申告。
+ * PHP 側の `KNOWN_UNRESOLVABLE_PHP_ENUMS` と同じ形の既定拒否の受け皿である
+ * (判定保留を非候補と混ぜないための当て所。共通規約 (b))。
+ */
+const KNOWN_INDETERMINATE_TS_DECLARATIONS = [
+    {
+        locator: locator("tests/js/support/enum-ts-sync/fixtures/t22-circular.ts", "literal-union", "X"),
+        reason: "型別名が自分自身を経由して循環する見本。型検査器が解決できないことを固定するために置いてある負の対照である",
+    },
+    {
+        locator: locator("tests/js/support/enum-ts-sync/fixtures/t22-circular.ts", "literal-union", "Y"),
+        reason: "同上 (循環の相方)。型検査器が解決できないことを固定するために置いてある負の対照である",
+    },
+    {
+        locator: locator("tests/js/support/enum-ts-sync/fixtures/t23-unresolved-import.ts", "literal-union", "X"),
+        reason: "実在しないモジュールからの取り込みに依存する見本。解決できないことを固定するために置いてある負の対照である",
+    },
+    {
+        locator: locator(
+            "tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts",
+            "literal-union",
+            "IndirectAnyCandidate",
+        ),
+        reason: "別名越しに明示の any へ解決する見本。構文が any の綴りでないので「正常な非候補」と区別できないことを固定する負の対照である",
+    },
+    {
+        locator: locator(
+            "tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts",
+            "object-keys",
+            "ObjectAnyComputedKeyCandidate",
+        ),
+        reason: "計算キーの型が any へ解決する対応表の見本。判定保留を非候補と混ぜないことを固定するために置いてある負の対照である",
+    },
+] as const satisfies readonly IndeterminateTsEntry[];
 
-const reverseSweepKey = (php: string, file: string, declaration: string, rule: number): string =>
-    `${php}|${file}|${declaration}|${rule}`;
+const EXPECTED_INDETERMINATE_TS_COUNT = 5;
 
 let catalog: PhpEnumCatalog | undefined;
-let mirrorProgram: MirrorProgram | undefined;
-let tsCandidates: readonly TsUnionCandidate[] | undefined;
+let programs: MirrorPrograms | undefined;
+let scan: TsCandidateScan | undefined;
+let sweep: ReverseSweepResult | undefined;
 
 const requireCatalog = (): PhpEnumCatalog => {
     if (catalog === undefined) throw new Error("catalog が初期化されていません");
     return catalog;
 };
-
-const requireTsCandidates = (): readonly TsUnionCandidate[] => {
-    if (tsCandidates === undefined) throw new Error("tsCandidates が初期化されていません");
-    return tsCandidates;
+const requirePrograms = (): MirrorPrograms => {
+    if (programs === undefined) throw new Error("programs が初期化されていません");
+    return programs;
+};
+const requireScan = (): TsCandidateScan => {
+    if (scan === undefined) throw new Error("scan が初期化されていません");
+    return scan;
+};
+const requireSweep = (): ReverseSweepResult => {
+    if (sweep === undefined) throw new Error("sweep が初期化されていません");
+    return sweep;
 };
 
 beforeAll(() => {
+    validateRelations(ENUM_TS_RELATIONS);
     catalog = buildPhpEnumCatalog();
-    mirrorProgram = createMirrorProgram([...new Set(ENUM_TS_MIRRORS.map((m) => m.ts))]);
-    tsCandidates = collectTsUnionCandidates(mirrorProgram);
+    programs = createMirrorPrograms();
+    scan = collectTsCandidates(programs);
+    // 登録済みの判定は locator の完全一致で行う (前向きの解決と同じ採番器の出力を使う)。
+    const declared = new Set(
+        resolveRelations(programs, ENUM_TS_RELATIONS).map((row) => locatorKey(row.tsLocator)),
+    );
+    sweep = findUnregisteredMirrorCandidates(catalog.resolved, scan.candidates, (row) =>
+        declared.has(locatorKey(row)),
+    );
 }, 300_000);
 
+/** 失敗メッセージ (i13。PHP 側と TS 側の**両方の位置**を出す)。 */
+const describeHit = (hit: UnregisteredMirrorCandidate): string =>
+    [
+        `規則${hit.rule} ${hit.php.path}:${hit.php.line} (${hit.php.name})`,
+        `     ⇔ ${hit.candidate.locator.file}:${hit.candidate.line}::${hit.candidate.locator.name} (${hit.candidate.locator.shape} #${hit.candidate.locator.occurrence})`,
+        `     ${hit.reason}`,
+        `     PHP にだけある値: ${hit.onlyInPhp.join(", ")}`,
+        `     TS にだけある値: ${hit.onlyInTs.join(", ")}`,
+    ].join("\n");
+
+const HOW_TO_FIX = [
+    "直し方:",
+    "  - TS が PHP の値域そのものの写しなら ENUM_TS_RELATIONS へ relation:\"equal\" で 1 行足し、EXPECTED_RELATION_COUNT を 1 増やす",
+    "  - TS が PHP の値域から選んだ非空の集合なら relation:\"subset\" と subsetReason (30 文字以上) を付けて登録する",
+    "  - どちらでもないなら REVERSE_SWEEP_EXEMPTIONS へ理由 30 文字以上で登録し EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT を直す",
+    "  - 登録できるのは型別名か const の配列である。対応表のキーと分岐のラベルは、いったん型別名か const の配列へ切り出す",
+].join("\n");
+
 describe("PHP 文字列付き列挙の発見の段 (全数走査・既定拒否の分類)", () => {
     it("走査が空振りしていない (母集団が空でない)", () => {
         const { resolved, unresolvable } = requireCatalog();
@@ -275,8 +429,8 @@ describe("PHP 文字列付き列挙の発見の段 (全数走査・既定拒否
         }
     });
 
-    it("resolved はすべて『登録済み』か『対象外の理由つき』のどちらか一方に分類される", () => {
-        const registered = registeredPhpPaths();
+    it("resolved はすべて『TS との関係を登録済み』か『対象外の理由つき』のどちらか一方に分類される", () => {
+        const registered = declaredPhpPaths();
         const exempt = new Set<string>(PHP_ENUM_EXEMPTIONS.map((e) => e.path));
 
         const unclassified: string[] = [];
@@ -293,7 +447,7 @@ describe("PHP 文字列付き列挙の発見の段 (全数走査・既定拒否
     });
 
     it("exemption の登録先が stale になっていない (今も resolved かつ未登録のままである)", () => {
-        const registered = registeredPhpPaths();
+        const registered = declaredPhpPaths();
         const resolvedPaths = new Set(requireCatalog().resolved.map((r) => r.path));
 
         const stale = PHP_ENUM_EXEMPTIONS.filter(
@@ -337,65 +491,192 @@ describe("PHP 文字列付き列挙の発見の段 (全数走査・既定拒否
     });
 });
 
-describe("PHP ⇔ TS 値域の逆走査 (未登録候補の検出)", () => {
-    it("TS 側の候補走査が空振りしていない (母集団が空でない)", () => {
-        expect(requireTsCandidates().length).toBeGreaterThan(0);
+describe("逆走査の母集団 (版管理下の全数・唯一の除外)", () => {
+    it("除外根の件数が pin と一致する", () => {
+        expect(EXCLUDED_ROOTS).toHaveLength(EXPECTED_EXCLUDED_ROOT_COUNT);
     });
 
-    it("逆走査で見つかる候補は REVERSE_SWEEP_EXEMPTIONS に登録された分だけである", () => {
-        const registered = registeredTsKeys();
-        const found = findUnregisteredMirrorCandidates(
-            requireCatalog().resolved,
-            requireTsCandidates(),
-            (file, name) => registered.has(`${file}::${name}`),
-        );
+    it("除外根の体裁 (配下・実在・重複無し・理由 30 文字以上) が守られている", () => {
+        expect(() => validateExcludedRoots()).not.toThrow();
+    });
 
-        const exemptKeys = new Set(
-            REVERSE_SWEEP_EXEMPTIONS.map((e) => reverseSweepKey(e.php, e.file, e.declaration, e.rule)),
-        );
+    it("除外根の配下は 0 件でなく、全ファイルが実際に本番と同じ入口で落ちる", () => {
+        const files = listExcludedFiles();
+        expect(files.length).toBeGreaterThan(0);
+
+        const survivors: string[] = [];
+        for (const file of files) {
+            const absolute = path.join(REPO_ROOT, file);
+            if (file.endsWith(".svelte")) {
+                // `.svelte` は仮想化の失敗が本番と同じ入口である。
+                let failed = false;
+                try {
+                    toVirtualUnit(file, fs.readFileSync(absolute, "utf-8"));
+                } catch {
+                    failed = true;
+                }
+                if (!failed) survivors.push(file);
+                continue;
+            }
+            // `.ts` は TypeScript の構文診断が本番と同じ入口である。
+            const fixture = createFixtureProgram([absolute]);
+            const source = fixture.program.getSourceFile(absolute);
+            expect(source, `${file}: 見本 program に載っていません`).toBeDefined();
+            if (source !== undefined && fixture.program.getSyntacticDiagnostics(source).length === 0) {
+                survivors.push(file);
+            }
+        }
 
-        const unexempted = found.filter(
-            (f) => !exemptKeys.has(reverseSweepKey(f.php.path, f.candidate.file, f.candidate.name, f.rule)),
-        );
+        expect(
+            survivors,
+            `除外根の配下に本番の入口で落ちないファイルがあります (母集団から静かに消える経路です。除外根から出すこと):\n${survivors.join("\n")}`,
+        ).toEqual([]);
+    });
+
+    it("母集団が空でない (.ts と .svelte のどちらも)", () => {
+        const { population } = requirePrograms();
+        expect(population.ts.length).toBeGreaterThan(0);
+        expect(population.svelte.length).toBeGreaterThan(0);
+        expect(requireScan().scannedFiles.size).toBe(population.ts.length + population.svelte.length);
+    });
+
+    it("母集団の全件がちょうど 1 本の program に載っている (過不足の両方を見る)", () => {
+        const { byOwner, population } = requirePrograms();
+        const owners = [...byOwner.values()];
+
+        const missing: string[] = [];
+        const duplicated: string[] = [];
+        for (const file of [...population.ts, ...population.svelte]) {
+            const carriers = owners.filter((mirror) => mirror.rootRelatives.has(file));
+            if (carriers.length === 0) missing.push(file);
+            if (carriers.length > 1) duplicated.push(`${file} (${carriers.map((c) => c.owner).join(", ")})`);
+        }
+
+        expect(missing, `どの program の起点にも載っていない母集団のファイル:\n${missing.join("\n")}`).toEqual([]);
+        expect(duplicated, `2 本以上の program の起点に載っている母集団のファイル:\n${duplicated.join("\n")}`).toEqual([]);
+    });
+});
+
+describe("TS 側の判定保留 (既定拒否の受け皿)", () => {
+    it("登録の件数が pin と一致し、実在・重複無し・reason が 30 文字以上", () => {
+        expect(KNOWN_INDETERMINATE_TS_DECLARATIONS).toHaveLength(EXPECTED_INDETERMINATE_TS_COUNT);
+
+        const seen = new Set<string>();
+        for (const entry of KNOWN_INDETERMINATE_TS_DECLARATIONS) {
+            expect(fs.existsSync(path.join(REPO_ROOT, entry.locator.file))).toBe(true);
+            const key = locatorKey(entry.locator);
+            expect(seen.has(key)).toBe(false);
+            seen.add(key);
+            expect(entry.reason.length).toBeGreaterThanOrEqual(30);
+        }
+    });
+
+    it("indeterminate はすべて KNOWN_INDETERMINATE_TS_DECLARATIONS に登録されている", () => {
+        const known = new Set(KNOWN_INDETERMINATE_TS_DECLARATIONS.map((e) => locatorKey(e.locator)));
+        const unknown = requireScan().indeterminate.filter((row) => !known.has(locatorKey(row.locator)));
 
         expect(
-            unexempted,
-            `未登録のミラー候補が見つかりました (登録するか REVERSE_SWEEP_EXEMPTIONS へ理由付きで登録すること):\n${unexempted
-                .map((f) => `規則${f.rule} ${f.php.path} <-> ${f.candidate.file}::${f.candidate.name}${f.nameMatch !== null ? ` (${f.nameMatch})` : ""}`)
+            unknown,
+            `未登録の判定保留の TS 宣言 (実装を直して解消するか KNOWN_INDETERMINATE_TS_DECLARATIONS へ理由付きで登録すること):\n${unknown
+                .map((row) => `${row.locator.file}:${row.line}::${row.locator.name} (${row.locator.shape}) ${row.reason}`)
                 .join("\n")}`,
         ).toEqual([]);
     });
 
+    it("登録先が stale になっていない (今も判定保留のままである)", () => {
+        const actual = new Set(requireScan().indeterminate.map((row) => locatorKey(row.locator)));
+        const stale = KNOWN_INDETERMINATE_TS_DECLARATIONS.filter((e) => !actual.has(locatorKey(e.locator)));
+
+        expect(
+            stale,
+            `KNOWN_INDETERMINATE_TS_DECLARATIONS の登録が実態と食い違っている (削除するか登録し直すこと):\n${stale
+                .map((e) => locatorKey(e.locator))
+                .join("\n")}`,
+        ).toEqual([]);
+    });
+});
+
+describe("PHP ⇔ TS 値域の逆走査 (未登録候補の検出)", () => {
+    it("TS 側の候補走査が空振りしていない (候補が空でない)", () => {
+        expect(requireScan().candidates.length).toBeGreaterThan(0);
+    });
+
+    it("判定不能な組は 0 件である (名前を決められないのに列挙と交差する候補は無い)", () => {
+        const { undecidable } = requireSweep();
+        expect(
+            undecidable,
+            `規則 2 を判定できない組があります (判定対象の名前を解決できる形へ直すこと):\n${undecidable
+                .map(
+                    (row) =>
+                        `${row.php.path}:${row.php.line} <-> ${row.candidate.locator.file}:${row.candidate.line}::${row.candidate.locator.name} (交差 ${row.intersectionSize} 値)`,
+                )
+                .join("\n")}`,
+        ).toEqual([]);
+    });
+
+    it("逆走査で見つかる候補は REVERSE_SWEEP_EXEMPTIONS に登録された分だけである", () => {
+        const { unexempted } = auditReverseSweepExemptions(requireSweep().found, REVERSE_SWEEP_EXEMPTIONS);
+
+        expect(
+            unexempted,
+            `未登録の PHP・TS 関係の候補が見つかりました。正本は PHP 側です。\n${unexempted
+                .map(describeHit)
+                .join("\n")}\n${HOW_TO_FIX}`,
+        ).toEqual([]);
+    });
+
     it("REVERSE_SWEEP_EXEMPTIONS の件数が pin と一致し、登録先が実在・重複無し・reason が 30 文字以上", () => {
         expect(REVERSE_SWEEP_EXEMPTIONS).toHaveLength(EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT);
 
         const seen = new Set<string>();
         for (const entry of REVERSE_SWEEP_EXEMPTIONS) {
             expect(fs.existsSync(path.join(REPO_ROOT, entry.php))).toBe(true);
-            expect(fs.existsSync(path.join(REPO_ROOT, entry.file))).toBe(true);
-            const key = reverseSweepKey(entry.php, entry.file, entry.declaration, entry.rule);
+            expect(fs.existsSync(path.join(REPO_ROOT, entry.locator.file))).toBe(true);
+            const key = `${entry.php}|${locatorKey(entry.locator)}|${entry.rule}`;
             expect(seen.has(key)).toBe(false);
             seen.add(key);
             expect(entry.reason.length).toBeGreaterThanOrEqual(30);
         }
     });
 
+    it("失敗メッセージに PHP 側と TS 側の両方の位置が出る (i13)", () => {
+        // 実際に鳴る組が 0 件でも診断文の形は固定する
+        // (収集した情報が判定と診断に使われていることを保証する。共通規約 (d))。
+        const message = describeHit({
+            rule: "2a",
+            php: { path: "app/Enums/ApiErrorCode.php", name: "ApiErrorCode", line: 13, values: new Set(["a"]) },
+            candidate: {
+                locator: locator("packages/cli/src/api/schemas.ts", "literal-union", "ApiErrorCode"),
+                line: 327,
+                topLevel: true,
+                values: new Set(["b"]),
+                correspondenceName: "ApiErrorCode",
+                nameResolved: true,
+            },
+            reason: "厳密名対応 (apierrorcode = apierrorcode) / 交差 1 値",
+            onlyInPhp: ["a"],
+            onlyInTs: ["b"],
+        });
+
+        expect(message).toContain("app/Enums/ApiErrorCode.php:13");
+        expect(message).toContain("packages/cli/src/api/schemas.ts:327::ApiErrorCode");
+        expect(message).toContain("literal-union #0");
+        expect(message).toContain("PHP にだけある値: a");
+        expect(message).toContain("TS にだけある値: b");
+        expect(HOW_TO_FIX).toContain("ENUM_TS_RELATIONS");
+        expect(HOW_TO_FIX).toContain("REVERSE_SWEEP_EXEMPTIONS");
+    });
+
     it("REVERSE_SWEEP_EXEMPTIONS の登録先が stale になっていない (今も候補として検出され続けている)", () => {
-        const registered = registeredTsKeys();
-        const found = findUnregisteredMirrorCandidates(
-            requireCatalog().resolved,
-            requireTsCandidates(),
-            (file, name) => registered.has(`${file}::${name}`),
-        );
-        const foundKeys = new Set(found.map((f) => reverseSweepKey(f.php.path, f.candidate.file, f.candidate.name, f.rule)));
-
-        const stale = REVERSE_SWEEP_EXEMPTIONS.filter(
-            (e) => !foundKeys.has(reverseSweepKey(e.php, e.file, e.declaration, e.rule)),
-        );
+        // 生死の判定は**免除を適用する前**の候補集合に対して行う
+        // (免除適用後で判定すると、申告が自分自身を根拠にして永久に生き続ける)。
+        const { stale } = auditReverseSweepExemptions(requireSweep().found, REVERSE_SWEEP_EXEMPTIONS);
 
         expect(
             stale,
-            `REVERSE_SWEEP_EXEMPTIONS の登録が実態と食い違っている (削除するか登録し直すこと):\n${stale.map((e) => `${e.php} <-> ${e.file}::${e.declaration}`).join("\n")}`,
+            `REVERSE_SWEEP_EXEMPTIONS の登録が実態と食い違っている (削除するか登録し直すこと):\n${stale
+                .map((e) => `${e.php} <-> ${locatorKey(e.locator)} 規則${e.rule}`)
+                .join("\n")}`,
         ).toEqual([]);
     });
 });
diff --git a/tests/js/architecture/enum-ts-sync-extractor.test.ts b/tests/js/architecture/enum-ts-sync-extractor.test.ts
index adf9f4ef..343845e7 100644
--- a/tests/js/architecture/enum-ts-sync-extractor.test.ts
+++ b/tests/js/architecture/enum-ts-sync-extractor.test.ts
@@ -22,8 +22,9 @@ import { EnumTsSyncError } from "../support/enum-ts-sync/errors";
 import {
     REPO_ROOT,
     createFixtureProgram,
-    createMirrorProgram,
+    createMirrorPrograms,
     type MirrorProgram,
+    type MirrorPrograms,
 } from "../support/enum-ts-sync/program";
 import { readTsUnionValues } from "../support/enum-ts-sync/ts-value-sets";
 import { readPhpEnumValues, readPhpEnumValuesFromText } from "../support/enum-ts-sync/php-enums";
@@ -67,9 +68,11 @@ const TS_CASES: readonly TsCase[] = [
     { id: "T4", file: "t04-open-string.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
     { id: "T5", file: "t05-number-member.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
     { id: "T6", file: "t06-never.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
-    { id: "T7", file: "t07-absent.ts", declaration: "X", accepts: undefined, reason: "型別名の宣言が見つかりません" },
-    { id: "T8", file: "t08-duplicate-alias.ts", declaration: "X", accepts: undefined, reason: "同名の型別名が 2 件あります" },
-    { id: "T9", file: "t09-const-array.ts", declaration: "X", accepts: undefined, reason: "型別名の宣言が見つかりません" },
+    { id: "T7", file: "t07-absent.ts", declaration: "X", accepts: undefined, reason: "受理できる宣言が見つかりません" },
+    { id: "T8", file: "t08-duplicate-alias.ts", declaration: "X", accepts: undefined, reason: "同名の受理できる宣言が 2 件あります" },
+    // T9 は**意味を更新した**行である (削除ではない)。受理する形が 2 つ (型別名 / const の配列)
+    // へ広がったので、`const X = ["a"] as const;` は拒否から受理へ移った。
+    { id: "T9", file: "t09-const-array.ts", declaration: "X", accepts: ["a"] },
     { id: "T10a", file: "t10a-target.ts", declaration: "X", accepts: ["c", "y1", "y2"] },
     { id: "T10b", file: "t10b-path-alias.ts", declaration: "X", accepts: ["editor", "extra", "shooter", "viewer"] },
     { id: "T11", file: "t11-indexed-access.ts", declaration: "X", accepts: ["p", "q"] },
@@ -103,12 +106,12 @@ const TS_AUXILIARY_FIXTURES: readonly string[] = ["t10a-other.ts"];
 /** `program-fixtures/` に置く補助 (tsconfig の対象に残す)。 */
 const PROGRAM_FIXTURES: readonly string[] = ["registry-base.ts", "registry-augmentation.ts"];
 
-let fullProgram: MirrorProgram | undefined;
+let fullPrograms: MirrorPrograms | undefined;
 let narrowProgram: MirrorProgram | undefined;
 
-const requireFullProgram = (): MirrorProgram => {
-    if (fullProgram === undefined) throw new EnumTsSyncError("fixture full program", "初期化されていません");
-    return fullProgram;
+const requireFullPrograms = (): MirrorPrograms => {
+    if (fullPrograms === undefined) throw new EnumTsSyncError("fixture full programs", "初期化されていません");
+    return fullPrograms;
 };
 const requireNarrowProgram = (): MirrorProgram => {
     if (narrowProgram === undefined) throw new EnumTsSyncError("fixture narrow program", "初期化されていません");
@@ -117,8 +120,8 @@ const requireNarrowProgram = (): MirrorProgram => {
 
 describe("TS 側抽出器の負例行列", () => {
     beforeAll(() => {
-        // 見本は tsconfig から除外してあるので、全体 program にも起点として明示的に足す。
-        fullProgram = createMirrorProgram(TS_CASES.map((c) => fixture(c.file)));
+        // 見本は tsconfig から除外してあるが、版管理下なので母集団 (= 起点) には入る。
+        fullPrograms = createMirrorPrograms();
         // 起点を縮めた program は「縮めた行が指す見本だけ」を起点にする。
         narrowProgram = createFixtureProgram(
             TS_CASES.filter((c) => c.program === "narrow").map((c) => path.join(FIXTURE_DIR, c.file)),
@@ -149,7 +152,10 @@ describe("TS 側抽出器の負例行列", () => {
     });
 
     it.each(TS_CASES)("$id: $file::$declaration", (testCase) => {
-        const mirrorProgram = testCase.program === "narrow" ? requireNarrowProgram() : requireFullProgram();
+        const mirrorProgram =
+            testCase.program === "narrow"
+                ? requireNarrowProgram()
+                : requireFullPrograms().programOf(fixture(testCase.file));
         const read = (): ReadonlySet<string> =>
             readTsUnionValues(mirrorProgram, fixture(testCase.file), testCase.declaration);
 
diff --git a/tests/js/architecture/enum-ts-sync.test.ts b/tests/js/architecture/enum-ts-sync.test.ts
index a50ed0bb..8a0f4c4a 100644
--- a/tests/js/architecture/enum-ts-sync.test.ts
+++ b/tests/js/architecture/enum-ts-sync.test.ts
@@ -1,19 +1,25 @@
 /**
- * PHP 列挙 ⇔ TypeScript 値域の汎用同期 gate (家系の裁定 AG-099 前半)。
+ * PHP 列挙 ⇔ TypeScript 値域の汎用同期 gate (家系の機能台帳 `enum-ts-sync-gate`)。
  *
- * 目録 (`ENUM_TS_MIRRORS`。実体は `../support/enum-ts-sync/mirror-inventory.ts`) に
- * 登録した写しについて、PHP の文字列付き列挙の値集合と TS の型別名が解決する値集合が
- * **完全一致**することを固定する。写しが片方だけ増えると、画面の分岐に
+ * 目録 (`ENUM_TS_RELATIONS`。実体は `../support/enum-ts-sync/relation-inventory.ts`) に
+ * 登録した**関係**について、PHP の文字列付き列挙の値集合と TS の宣言が解決する値集合の
+ * 関係が成り立つことを固定する。写しが片方だけ増えると、画面の分岐に
  * 「どこにも当たらない値」が生まれて無言の描画漏れになる。
  *
- * **登録の仕方**: PHP の列挙の値を TS の型別名で受ける箇所を作ったら、
- * `ENUM_TS_MIRRORS` へ 1 行足し、`EXPECTED_MIRROR_COUNT` を 1 増やす。
- * 個別の検査ファイルは**増やさない** (増殖を止めるのが本 gate の目的)。
+ * **関係は 2 つ**である:
+ * - `equal` … 値域そのものの写し。**双方向の差分が空**であること
+ * - `subset` … 値域の写しではなく、許される値域から選んだ非空の集合。
+ *   **TS 側にだけある値が無い**ことだけを見る (PHP 側の追加では赤くならない)
  *
- * **本ファイルが見るのは登録した写しだけ**である。未登録の PHP 列挙・TS 宣言の発見と、
+ * **登録の仕方**: PHP の列挙の値を TS で受ける箇所を作ったら、`ENUM_TS_RELATIONS` へ
+ * 1 行足し、`EXPECTED_RELATION_COUNT` を 1 増やす。個別の検査ファイルは**増やさない**
+ * (増殖を止めるのが本 gate の目的)。受理する TS の形は**型別名の宣言**か
+ * **`const` の配列**で、置き場は `resources/js/` と `packages/<name>/src/` である。
+ *
+ * **本ファイルが見るのは登録した関係だけ**である。未登録の PHP 列挙・TS 宣言の発見と、
  * 「登録し忘れ」「名前は対応するが既に食い違った写し」の検出は
- * `enum-ts-sync-discovery.test.ts` (裁定 AG-099 後半) の担当であり、そちらが
- * `ENUM_TS_MIRRORS` を**登録済みの判定**に再利用する (単一の出典)。
+ * `enum-ts-sync-discovery.test.ts` の担当であり、そちらが `ENUM_TS_RELATIONS` を
+ * **登録済みの判定**に再利用する (単一の出典)。
  *
  * **レーンの非対称**: 本 gate は `pnpm test` (CI の frontend job) でだけ走る。
  * `composer test` だけでは値集合の同期は検証されない。
@@ -24,119 +30,175 @@ import fs from "node:fs";
 import os from "node:os";
 import path from "node:path";
 import { EnumTsSyncError } from "../support/enum-ts-sync/errors";
-import { REPO_ROOT, createMirrorProgram, type MirrorProgram } from "../support/enum-ts-sync/program";
-import { readTsUnionValues } from "../support/enum-ts-sync/ts-value-sets";
+import { REPO_ROOT, createMirrorPrograms, type MirrorPrograms } from "../support/enum-ts-sync/program";
+import { resolveRelations, type ResolvedEnumTsRelation } from "../support/enum-ts-sync/ts-value-sets";
 import { readPhpEnumValues } from "../support/enum-ts-sync/php-enums";
 import {
-    ENUM_TS_MIRRORS,
-    EXPECTED_MIRROR_COUNT,
-    validateMirrors,
-    type EnumTsMirror,
-} from "../support/enum-ts-sync/mirror-inventory";
+    ENUM_TS_RELATIONS,
+    EXPECTED_RELATION_COUNT,
+    validateRelations,
+    type EnumTsRelationEntry,
+} from "../support/enum-ts-sync/relation-inventory";
 
+type Row = (typeof ENUM_TS_RELATIONS)[number];
 
-let mirrorProgram: MirrorProgram | undefined;
+let programs: MirrorPrograms | undefined;
+let resolved: readonly ResolvedEnumTsRelation<Row>[] | undefined;
 
 /** 初期化されていなければ落ちる (definite assignment の `!` を使わない)。 */
-const requireMirrorProgram = (): MirrorProgram => {
-    if (mirrorProgram === undefined) throw new EnumTsSyncError("mirror program", "初期化されていません");
-    return mirrorProgram;
+const requireResolved = (): readonly ResolvedEnumTsRelation<Row>[] => {
+    if (resolved === undefined) throw new EnumTsSyncError("relation program", "初期化されていません");
+    return resolved;
 };
 
 describe("PHP 列挙 ⇔ TS 値域の同期", () => {
     beforeAll(() => {
-        validateMirrors(ENUM_TS_MIRRORS);
-        mirrorProgram = createMirrorProgram([...new Set(ENUM_TS_MIRRORS.map((m) => m.ts))]);
+        validateRelations(ENUM_TS_RELATIONS);
+        programs = createMirrorPrograms();
+        resolved = resolveRelations(programs, ENUM_TS_RELATIONS);
     }, 300_000);
 
     it("目録の件数が pin と一致する", () => {
-        expect(ENUM_TS_MIRRORS).toHaveLength(EXPECTED_MIRROR_COUNT);
+        expect(ENUM_TS_RELATIONS).toHaveLength(EXPECTED_RELATION_COUNT);
     });
 
     it("目録の行の体裁が守られている", () => {
-        expect(() => validateMirrors(ENUM_TS_MIRRORS)).not.toThrow();
+        expect(() => validateRelations(ENUM_TS_RELATIONS)).not.toThrow();
     });
 
-    it.each(ENUM_TS_MIRRORS)("$php ⇔ $ts::$declaration の値集合が一致する", (mirror) => {
-        const phpValues = readPhpEnumValues(mirror.php);
-        const tsValues = readTsUnionValues(requireMirrorProgram(), mirror.ts, mirror.declaration);
+    it.each(ENUM_TS_RELATIONS)("$php ⇔ $ts::$declaration ($relation)", (row) => {
+        const phpValues = readPhpEnumValues(row.php);
+        const entry = requireResolved().find((r) => r.entry === row);
+        expect(entry, `${row.ts}::${row.declaration} の解決結果がありません`).toBeDefined();
+        if (entry === undefined) return;
 
-        // 空 vs 空で素通りしないことを明示する (抽出器は空集合を返さないが、意図を残す)
+        // 空 vs 空で素通りしないことを明示する (抽出器は空集合を返さないが、意図を残す)。
         expect(phpValues.size).toBeGreaterThan(0);
-        expect([...tsValues].sort()).toEqual([...phpValues].sort());
+        expect(entry.tsValues.size).toBeGreaterThan(0);
+
+        const onlyInTs = [...entry.tsValues].filter((value) => !phpValues.has(value)).sort();
+        expect(onlyInTs, `TS 側にだけある値があります: ${onlyInTs.join(", ")}`).toEqual([]);
+
+        if (row.relation === "equal") {
+            const onlyInPhp = [...phpValues].filter((value) => !entry.tsValues.has(value)).sort();
+            expect(onlyInPhp, `PHP 側にだけある値があります: ${onlyInPhp.join(", ")}`).toEqual([]);
+        }
     });
 });
 
-describe("validateMirrors() の負のコントロール (実リポジトリを根にする)", () => {
-    const valid: EnumTsMirror = {
+describe("validateRelations() の負のコントロール (実リポジトリを根にする)", () => {
+    const valid: EnumTsRelationEntry = {
         php: "app/Enums/Manual/RenderKind.php",
         ts: "resources/js/types/manual.ts",
         declaration: "RenderKind",
+        relation: "equal",
         note: "負のコントロール用の正常な行",
     };
 
     it("正常な行は通る", () => {
-        expect(() => validateMirrors([valid])).not.toThrow();
+        expect(() => validateRelations([valid])).not.toThrow();
     });
 
     it("app/ の外の php は拒否する", () => {
-        expect(() => validateMirrors([{ ...valid, php: "config/app.php" }])).toThrow("app/ 配下だけ");
+        expect(() => validateRelations([{ ...valid, php: "config/app.php" }])).toThrow("app/ 配下だけ");
+    });
+
+    it("登録できる置き場の外の ts は拒否する", () => {
+        expect(() => validateRelations([{ ...valid, ts: "tests/js/setup.ts" }])).toThrow(
+            "resources/js/ 配下か packages/*/src/ 配下だけ",
+        );
     });
 
-    it("resources/js/ の外の ts は拒否する", () => {
-        expect(() => validateMirrors([{ ...valid, ts: "tests/js/setup.ts" }])).toThrow("resources/js/ 配下だけ");
+    it("道具パッケージでも src の外は拒否する", () => {
+        expect(() => validateRelations([{ ...valid, ts: "packages/cli/vitest.config.ts" }])).toThrow(
+            "resources/js/ 配下か packages/*/src/ 配下だけ",
+        );
+        expect(() => validateRelations([{ ...valid, ts: "packages/cli/tests/branding.test.ts" }])).toThrow(
+            "resources/js/ 配下か packages/*/src/ 配下だけ",
+        );
+    });
+
+    it("道具パッケージの src は通る", () => {
+        expect(() =>
+            validateRelations([
+                {
+                    php: "app/Enums/ApiErrorCode.php",
+                    ts: "packages/cli/src/api/schemas.ts",
+                    declaration: "API_ERROR_CODES",
+                    relation: "equal",
+                    note: "見本の行",
+                },
+            ]),
+        ).not.toThrow();
     });
 
     it("絶対パスは拒否する", () => {
-        expect(() => validateMirrors([{ ...valid, php: path.join(REPO_ROOT, valid.php) }])).toThrow(
+        expect(() => validateRelations([{ ...valid, php: path.join(REPO_ROOT, valid.php) }])).toThrow(
             "絶対パスは登録できません",
         );
     });
 
     it("逆斜線を含むパスは拒否する", () => {
-        expect(() => validateMirrors([{ ...valid, php: "app\\Enums\\Manual\\RenderKind.php" }])).toThrow(
+        expect(() => validateRelations([{ ...valid, php: "app\\Enums\\Manual\\RenderKind.php" }])).toThrow(
             "逆斜線を含むパス",
         );
     });
 
     it(".. を含むパスは拒否する", () => {
-        expect(() => validateMirrors([{ ...valid, php: "app/../app/Enums/Manual/RenderKind.php" }])).toThrow(
+        expect(() => validateRelations([{ ...valid, php: "app/../app/Enums/Manual/RenderKind.php" }])).toThrow(
             ". / .. / 空の区間",
         );
     });
 
     it(". と空の区間を含むパスは拒否する", () => {
-        expect(() => validateMirrors([{ ...valid, php: "app/./Enums/Manual/RenderKind.php" }])).toThrow(
+        expect(() => validateRelations([{ ...valid, php: "app/./Enums/Manual/RenderKind.php" }])).toThrow(
             ". / .. / 空の区間",
         );
-        expect(() => validateMirrors([{ ...valid, ts: "resources/js//types/manual.ts" }])).toThrow(
+        expect(() => validateRelations([{ ...valid, ts: "resources/js//types/manual.ts" }])).toThrow(
             ". / .. / 空の区間",
         );
     });
 
     it("拡張子が違う登録は拒否する", () => {
-        expect(() => validateMirrors([{ ...valid, php: "app/Enums/Manual/RenderKind.phpx" }])).toThrow(
+        expect(() => validateRelations([{ ...valid, php: "app/Enums/Manual/RenderKind.phpx" }])).toThrow(
             "php は .php で終わること",
         );
-        expect(() => validateMirrors([{ ...valid, ts: "resources/js/types/manual.d.ts.map" }])).toThrow(
-            "ts は .ts で終わること",
+        expect(() => validateRelations([{ ...valid, ts: "resources/js/types/manual.d.ts.map" }])).toThrow(
+            "ts は .ts か .svelte で終わること",
         );
     });
 
     it("実在しないファイルは拒否する", () => {
-        expect(() => validateMirrors([{ ...valid, php: "app/Enums/NoSuchEnum.php" }])).toThrow("実在しません");
+        expect(() => validateRelations([{ ...valid, php: "app/Enums/NoSuchEnum.php" }])).toThrow("実在しません");
     });
 
     it("ディレクトリの登録は拒否する", () => {
-        expect(() => validateMirrors([{ ...valid, php: "app/Enums/Manual.php" }])).toThrow("実在しません");
+        expect(() => validateRelations([{ ...valid, php: "app/Enums/Manual.php" }])).toThrow("実在しません");
     });
 
     it("同じ TS 宣言の二重登録は拒否する", () => {
-        expect(() => validateMirrors([valid, { ...valid, note: "別の理由" }])).toThrow("2 回登録されています");
+        expect(() => validateRelations([valid, { ...valid, note: "別の理由" }])).toThrow("2 回登録されています");
     });
 
     it("note が空の行は拒否する", () => {
-        expect(() => validateMirrors([{ ...valid, note: "  " }])).toThrow("note が空です");
+        expect(() => validateRelations([{ ...valid, note: "  " }])).toThrow("note が空です");
+    });
+
+    it("subset の行に subsetReason が無い / 短いと拒否する", () => {
+        const subsetRow: EnumTsRelationEntry = {
+            ...valid,
+            relation: "subset",
+            subsetReason: "短すぎる理由",
+        };
+        expect(() => validateRelations([subsetRow])).toThrow("subsetReason は 30 文字以上");
+        expect(() =>
+            validateRelations([
+                {
+                    ...subsetRow,
+                    subsetReason: " ".repeat(40),
+                },
+            ]),
+        ).toThrow("subsetReason は 30 文字以上");
     });
 });
 
@@ -145,15 +207,16 @@ describe("validateMirrors() の負のコントロール (実リポジトリを
  * 兄弟ディレクトリ (`app-legacy/`)・symlink による脱出・symlink 別名の二重登録は
  * **実リポジトリには作れない**ので、一時ディレクトリに同じ形の木を作って根を差し替える。
  * ここが無いと `root + path.sep` を素の `root` へ弱める回帰や `realpathSync` 検査の
- * 撤去を検出できない (Codex 実装レビュー Round 1 の Warning)。
+ * 撤去を検出できない。登録できる根が 2 系統になったので、**根ごとに**負例を置く。
  */
-describe("validateMirrors() の負のコントロール (走査根の境界)", () => {
+describe("validateRelations() の負のコントロール (走査根の境界)", () => {
     let sandbox = "";
 
-    const row = (php: string, ts: string, declaration = "X"): EnumTsMirror => ({
+    const row = (php: string, ts: string, declaration = "X"): EnumTsRelationEntry => ({
         php,
         ts,
         declaration,
+        relation: "equal",
         note: "見本の木の行",
     });
 
@@ -163,12 +226,16 @@ describe("validateMirrors() の負のコントロール (走査根の境界)", (
         fs.mkdirSync(path.join(sandbox, "app", "Enums"), { recursive: true });
         fs.mkdirSync(path.join(sandbox, "app-legacy", "Enums"), { recursive: true });
         fs.mkdirSync(path.join(sandbox, "resources", "js", "types"), { recursive: true });
+        fs.mkdirSync(path.join(sandbox, "packages", "tool", "src"), { recursive: true });
+        fs.mkdirSync(path.join(sandbox, "packages", "linked"), { recursive: true });
         fs.mkdirSync(path.join(sandbox, "outside"), { recursive: true });
 
         fs.writeFileSync(path.join(sandbox, "app", "Enums", "X.php"), "<?php\n");
         fs.writeFileSync(path.join(sandbox, "app-legacy", "Enums", "X.php"), "<?php\n");
         fs.writeFileSync(path.join(sandbox, "outside", "X.php"), "<?php\n");
+        fs.writeFileSync(path.join(sandbox, "outside", "x.ts"), "export type X = \"a\";\n");
         fs.writeFileSync(path.join(sandbox, "resources", "js", "types", "x.ts"), "export type X = \"a\";\n");
+        fs.writeFileSync(path.join(sandbox, "packages", "tool", "src", "x.ts"), "export type X = \"a\";\n");
 
         // app/ の中から走査範囲の外を指す symlink。
         fs.symlinkSync(path.join(sandbox, "outside", "X.php"), path.join(sandbox, "app", "Enums", "escape.php"));
@@ -177,6 +244,10 @@ describe("validateMirrors() の負のコントロール (走査根の境界)", (
             path.join(sandbox, "resources", "js", "types", "x.ts"),
             path.join(sandbox, "resources", "js", "types", "alias.ts"),
         );
+        // packages/<name>/src の中から外へ抜ける symlink。
+        fs.symlinkSync(path.join(sandbox, "outside", "x.ts"), path.join(sandbox, "packages", "tool", "src", "escape.ts"));
+        // packages/<name>/src 自体が symlink である場合。
+        fs.symlinkSync(path.join(sandbox, "outside"), path.join(sandbox, "packages", "linked", "src"));
     });
 
     afterAll(() => {
@@ -184,24 +255,42 @@ describe("validateMirrors() の負のコントロール (走査根の境界)", (
     });
 
     it("見本の木の正常な行は通る", () => {
-        expect(() => validateMirrors([row("app/Enums/X.php", "resources/js/types/x.ts")], sandbox)).not.toThrow();
+        expect(() => validateRelations([row("app/Enums/X.php", "resources/js/types/x.ts")], sandbox)).not.toThrow();
+    });
+
+    it("見本の木の道具パッケージの src も通る", () => {
+        expect(() =>
+            validateRelations([row("app/Enums/X.php", "packages/tool/src/x.ts")], sandbox),
+        ).not.toThrow();
     });
 
     it("兄弟ディレクトリ (app-legacy/) は app/ 配下と認めない", () => {
         expect(() =>
-            validateMirrors([row("app-legacy/Enums/X.php", "resources/js/types/x.ts")], sandbox),
+            validateRelations([row("app-legacy/Enums/X.php", "resources/js/types/x.ts")], sandbox),
         ).toThrow("app/ 配下だけ");
     });
 
     it("symlink で走査範囲の外へ抜ける登録は拒否する", () => {
         expect(() =>
-            validateMirrors([row("app/Enums/escape.php", "resources/js/types/x.ts")], sandbox),
+            validateRelations([row("app/Enums/escape.php", "resources/js/types/x.ts")], sandbox),
+        ).toThrow("symlink の解決先が走査範囲の外です");
+    });
+
+    it("道具パッケージの src から外へ抜ける symlink も拒否する", () => {
+        expect(() =>
+            validateRelations([row("app/Enums/X.php", "packages/tool/src/escape.ts")], sandbox),
         ).toThrow("symlink の解決先が走査範囲の外です");
     });
 
+    it("src 自体が symlink のパッケージも走査範囲の外として拒否する", () => {
+        expect(() =>
+            validateRelations([row("app/Enums/X.php", "packages/linked/src/x.ts")], sandbox),
+        ).toThrow("resources/js/ 配下か packages/*/src/ 配下だけ");
+    });
+
     it("symlink の別名で同じ TS 宣言を 2 回登録するのは拒否する", () => {
         expect(() =>
-            validateMirrors(
+            validateRelations(
                 [
                     row("app/Enums/X.php", "resources/js/types/x.ts"),
                     row("app/Enums/X.php", "resources/js/types/alias.ts"),
@@ -214,7 +303,7 @@ describe("validateMirrors() の負のコントロール (走査根の境界)", (
     it("ディレクトリを登録するのは拒否する", () => {
         fs.mkdirSync(path.join(sandbox, "app", "Enums", "dir.php"), { recursive: true });
         expect(() =>
-            validateMirrors([row("app/Enums/dir.php", "resources/js/types/x.ts")], sandbox),
+            validateRelations([row("app/Enums/dir.php", "resources/js/types/x.ts")], sandbox),
         ).toThrow("通常ファイルではありません");
     });
 });
diff --git a/tests/js/support/enum-ts-sync/fixtures/candidates/derived-keys.ts b/tests/js/support/enum-ts-sync/fixtures/candidates/derived-keys.ts
new file mode 100644
index 00000000..3f53cbbf
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/candidates/derived-keys.ts
@@ -0,0 +1,2 @@
+/** 派生除外の見本が**取り込んだ型**として使う鍵の型。値は現物の列挙と交差しない綴り。 */
+export type ImportedDerivedKey = "zzz-i-1" | "zzz-i-2";
diff --git a/tests/js/support/enum-ts-sync/fixtures/candidates/derived.ts b/tests/js/support/enum-ts-sync/fixtures/candidates/derived.ts
new file mode 100644
index 00000000..9bed5193
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/candidates/derived.ts
@@ -0,0 +1,68 @@
+/**
+ * 対応表のキーの「派生」除外の見本 (証人つきでだけ外す)。
+ * 値は現物の列挙と交差しない綴りにすること。
+ *
+ * **型検査は通らなくてよい** — `tsconfig.json` の `exclude` に `fixtures/**` があり、
+ * 本 gate が見るのは構文診断だけである (意味の診断は `pnpm typecheck` の担当)。
+ */
+import type { ImportedDerivedKey } from "./derived-keys";
+
+/** 証人になれる形 (型の合併)。 */
+export type DerivedKey = "zzz-d-1" | "zzz-d-2";
+
+/** 証人になれる形 (定数の配列)。取り込んだ型の見本の証人になる。 */
+export const ImportedDerivedKeyList = ["zzz-i-1", "zzz-i-2"] as const;
+
+/** 派生 (型注釈の `Record`)。証人があるので外れる。 */
+export const DerivedRecord: Record<DerivedKey, number> = { "zzz-d-1": 1, "zzz-d-2": 2 };
+
+/** 派生 (`satisfies`)。証人があるので外れる。 */
+export const DerivedSatisfies = { "zzz-d-1": 1, "zzz-d-2": 2 } satisfies Record<DerivedKey, number>;
+
+/** 派生 (型別名越しの `Record`)。証人があるので外れる。 */
+type DerivedAlias = Record<DerivedKey, number>;
+export const DerivedViaAlias: DerivedAlias = { "zzz-d-1": 1, "zzz-d-2": 2 };
+
+/** 派生 (`keyof`)。証人があるので外れる。 */
+interface DerivedShape {
+    readonly "zzz-d-1": number;
+    readonly "zzz-d-2": number;
+}
+export const DerivedViaKeyof: Record<keyof DerivedShape, number> = { "zzz-d-1": 1, "zzz-d-2": 2 };
+
+/** 派生 (取り込んだ型)。証人 (`ImportedDerivedKeyList`) があるので外れる。 */
+export const DerivedViaImport: Record<ImportedDerivedKey, number> = { "zzz-i-1": 1, "zzz-i-2": 2 };
+
+/** `Partial` は過不足を落とさないので派生と認めない (候補として残る)。 */
+export const DerivedPartial: Partial<Record<DerivedKey, number>> = { "zzz-d-1": 1, "zzz-d-2": 2 };
+
+/** 文字列の添字シグネチャがあるので派生と認めない (候補として残る)。 */
+export const DerivedIndexSignature: Record<string, number> = { "zzz-d-1": 1, "zzz-d-2": 2 };
+
+/** 必須プロパティが書かれたキーより多い (欠落) ので派生と認めない。 */
+export const DerivedMissingKey: Record<DerivedKey | "zzz-d-3", number> = { "zzz-d-1": 1, "zzz-d-2": 2 };
+
+/** 書かれたキーが必須プロパティより多い (余剰) ので派生と認めない。 */
+export const DerivedExtraKey: Record<"zzz-d-1", number> = { "zzz-d-1": 1, "zzz-d-2": 2 };
+
+/** 合併の型は共通のプロパティしか必須にならないので派生と認めない。 */
+export const DerivedUnionType: Record<DerivedKey, number> | Record<"zzz-d-1", number> = {
+    "zzz-d-1": 1,
+    "zzz-d-2": 2,
+};
+
+/** 交叉の型は必須プロパティが増えるので派生と認めない。 */
+export const DerivedIntersectionType: Record<DerivedKey, number> & { readonly "zzz-d-3": number } = {
+    "zzz-d-1": 1,
+    "zzz-d-2": 2,
+};
+
+/** 明示の型が無いので派生と認めない。 */
+export const DerivedNoExplicitType = { "zzz-d-1": 1, "zzz-d-2": 2 };
+
+/** 証人が無い (鍵の型が対応表以外の候補にならない) ので派生と認めない。 */
+interface WitnesslessShape {
+    readonly "zzz-nw-1": number;
+    readonly "zzz-nw-2": number;
+}
+export const DerivedWitnessless: WitnesslessShape = { "zzz-nw-1": 1, "zzz-nw-2": 2 };
diff --git a/tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts b/tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts
index 563592d4..3fb15031 100644
--- a/tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts
+++ b/tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts
@@ -1,5 +1,60 @@
-/** 逆走査の候補走査 (collectTsUnionCandidates) の負のコントロール専用の見本。 */
+/**
+ * 逆走査の候補走査 (`collectTsCandidates`) の正例・負例の見本。
+ *
+ * **`fixtures/` は本番の母集団に入る** (除外は `candidates-broken/` だけ)。したがって
+ * 見本の値は**現物の PHP 列挙と交差しない綴り** (`"zzz-…"` など) にすること。
+ * 交差する値を書くと本番の逆走査が鳴る。
+ */
+
+// --- literal-union -------------------------------------------------------
 export type LiteralUnionCandidate = "a" | "b";
 export type SingleLiteralCandidate = "only";
 export type NotAUnionCandidate = string;
 export type NumberCandidate = 1 | 2;
+export type ExplicitAnyCandidate = any;
+export type ExplicitUnknownCandidate = unknown;
+type IndirectAny = any;
+export type IndirectAnyCandidate = IndirectAny;
+
+// --- const-array ---------------------------------------------------------
+export const ConstArrayCandidate = ["zzz-sample-1", "zzz-sample-2"];
+export const ConstArrayAsConst = ["zzz-sample-3"] as const;
+export const ConstArraySatisfies = ["zzz-sample-4"] satisfies readonly string[];
+export const ConstArrayParenthesized = (["zzz-sample-5"] as const);
+export let LetArrayCandidate = ["zzz-sample-6"];
+export const MixedArrayCandidate = ["zzz-sample-7", LetArrayCandidate[0]];
+export const EmptyArrayCandidate: readonly string[] = [];
+
+// --- object-keys ---------------------------------------------------------
+export const ObjectKeysCandidate = { "zzz-key-1": 1, zzzKey2: 2 };
+export const ObjectKeysWithIndexSignature: Record<string, number> = { "zzz-key-3": 1 };
+export const ObjectSpreadCandidate = { ...ObjectKeysCandidate };
+const computedKey = "zzz-key-4" as const;
+export const ObjectComputedKeyCandidate = { [computedKey]: 1 };
+const anyKey: any = "zzz-key-5";
+export const ObjectAnyComputedKeyCandidate = { [anyKey]: 1 };
+
+// --- switch-cases --------------------------------------------------------
+export const switchCandidate = (value: LiteralUnionCandidate): number => {
+    switch (value) {
+        case "a":
+            return 1;
+        case "b":
+            return 2;
+        default:
+            return 0;
+    }
+};
+
+// --- 入れ子の同名宣言 (locator の一意性の見本) -----------------------------
+export function nestedA(): string {
+    type NestedShadow = "zzz-nested-1";
+    const value: NestedShadow = "zzz-nested-1";
+    return value;
+}
+
+export function nestedB(): string {
+    type NestedShadow = "zzz-nested-2";
+    const value: NestedShadow = "zzz-nested-2";
+    return value;
+}
diff --git a/tests/js/support/enum-ts-sync/fixtures/candidates/nested-occurrence.ts b/tests/js/support/enum-ts-sync/fixtures/candidates/nested-occurrence.ts
new file mode 100644
index 00000000..43ecbf28
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/candidates/nested-occurrence.ts
@@ -0,0 +1,14 @@
+/**
+ * 「入れ子が先・最上位が後」の見本。前向きの目録は**最上位の宣言**の locator へ
+ * 解決し、入れ子の同名候補は逆走査に残る (`occurrence` が別なので申告も混ざらない)。
+ * 値は現物の列挙と交差しない綴りにすること。
+ */
+function inner(): string {
+    type NestedFirst = "zzz-nested-3";
+    const value: NestedFirst = "zzz-nested-3";
+    return value;
+}
+
+export type NestedFirst = "zzz-nested-4";
+
+export const nestedFirstUser = (): string => inner();
diff --git a/tests/js/support/enum-ts-sync/fixtures/candidates/witness-cycle.ts b/tests/js/support/enum-ts-sync/fixtures/candidates/witness-cycle.ts
new file mode 100644
index 00000000..df7f3f55
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/candidates/witness-cycle.ts
@@ -0,0 +1,19 @@
+/**
+ * 証人の資格を「対応表のキー形**以外**」に限ることの見本 (循環の遮断)。
+ * どれも `object-keys` 形どうしなので互いの証人になれず、**候補として残る**。
+ * 値は現物の列挙と交差しない綴りにすること。
+ *
+ * **型検査は通らなくてよい** (`fixtures/**` は `pnpm typecheck` の対象外)。
+ */
+
+/** 自己証人: 自分自身を根拠に消えてはならない。 */
+export const SelfWitness: Record<"zzz-w-1" | "zzz-w-2", number> = { "zzz-w-1": 1, "zzz-w-2": 2 };
+
+/** 2 件の相互証人: 互いを根拠に両方消えてはならない。 */
+export const MutualWitnessA: Record<"zzz-w-3" | "zzz-w-4", number> = { "zzz-w-3": 1, "zzz-w-4": 2 };
+export const MutualWitnessB: Record<"zzz-w-3" | "zzz-w-4", number> = { "zzz-w-3": 1, "zzz-w-4": 2 };
+
+/** 3 件の循環証人: 巡回を根拠に全部消えてはならない。 */
+export const CycleWitnessA: Record<"zzz-w-5" | "zzz-w-6", number> = { "zzz-w-5": 1, "zzz-w-6": 2 };
+export const CycleWitnessB: Record<"zzz-w-5" | "zzz-w-6", number> = { "zzz-w-5": 1, "zzz-w-6": 2 };
+export const CycleWitnessC: Record<"zzz-w-5" | "zzz-w-6", number> = { "zzz-w-5": 1, "zzz-w-6": 2 };
diff --git a/tests/js/support/enum-ts-sync/fixtures/svelte/Other.svelte b/tests/js/support/enum-ts-sync/fixtures/svelte/Other.svelte
new file mode 100644
index 00000000..1d558d31
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/svelte/Other.svelte
@@ -0,0 +1,11 @@
+<script lang="ts">
+    /**
+     * 干渉の相方。`SampleInstanceKind` は Sample.svelte にも在るが、仮想 TS を
+     * モジュール文脈にしてあるので**混ざらない** (値集合が別になる)。
+     */
+    type SampleInstanceKind = "zzz-svelte-3";
+
+    const current: SampleInstanceKind = "zzz-svelte-3";
+</script>
+
+<span>{current}</span>
diff --git a/tests/js/support/enum-ts-sync/fixtures/svelte/Sample.svelte b/tests/js/support/enum-ts-sync/fixtures/svelte/Sample.svelte
new file mode 100644
index 00000000..c8aa1f56
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/svelte/Sample.svelte
@@ -0,0 +1,19 @@
+<script lang="ts" module>
+    /**
+     * `.svelte` を仮想 TS へ平坦化する見本 (module 文脈と実体文脈の両方を持つ)。
+     * 値は現物の PHP 列挙と交差しない綴りにすること (fixtures/ は母集団に入る)。
+     */
+    export type SampleModuleKind = "zzz-svelte-1" | "zzz-svelte-2";
+</script>
+
+<script lang="ts">
+    // 実体から module の宣言を参照できること (Svelte 本来の可視性と同じ)。
+    type SampleInstanceKind = SampleModuleKind;
+
+    const SAMPLE_LABELS = { "zzz-svelte-1": "one", "zzz-svelte-2": "two" };
+    const SAMPLE_LIST = ["zzz-svelte-1", "zzz-svelte-2"] as const;
+
+    const current: SampleInstanceKind = "zzz-svelte-1";
+</script>
+
+<span>{SAMPLE_LABELS[current]}{SAMPLE_LIST.length}</span>
diff --git a/tests/js/support/enum-ts-sync/php-enum-catalog.ts b/tests/js/support/enum-ts-sync/php-enum-catalog.ts
index 92677805..1bb02593 100644
--- a/tests/js/support/enum-ts-sync/php-enum-catalog.ts
+++ b/tests/js/support/enum-ts-sync/php-enum-catalog.ts
@@ -63,6 +63,8 @@ export interface ResolvedPhpEnum {
     readonly path: string;
     /** enum 宣言の名前。 */
     readonly name: string;
+    /** enum 宣言の頭がある行 (1 始まり)。失敗メッセージに PHP 側の位置を出すために持つ。 */
+    readonly line: number;
     /** case の値集合。 */
     readonly values: ReadonlySet<string>;
 }
@@ -102,7 +104,7 @@ export const listTrackedPhpFiles = (root: string = REPO_ROOT): readonly string[]
 export const classifyPhpFile = (
     source: string,
     fileName: string,
-): { readonly kind: "resolved"; readonly name: string; readonly values: ReadonlySet<string> }
+): { readonly kind: "resolved"; readonly name: string; readonly line: number; readonly values: ReadonlySet<string> }
     | { readonly kind: "unresolvable"; readonly reason: string }
     | undefined => {
     let headers;
@@ -143,7 +145,8 @@ export const classifyPhpFile = (
 
     try {
         const values = readPhpEnumValuesFromText(source, fileName);
-        return { kind: "resolved", name: depthZero[0].name, values };
+        const line = source.slice(0, depthZero[0].offset).split("\n").length;
+        return { kind: "resolved", name: depthZero[0].name, line, values };
     } catch (error) {
         return { kind: "unresolvable", reason: error instanceof Error ? error.message : String(error) };
     }
@@ -164,7 +167,12 @@ export const buildPhpEnumCatalog = (root: string = REPO_ROOT): PhpEnumCatalog =>
         const classification = classifyPhpFile(source, relative);
         if (classification === undefined) continue;
         if (classification.kind === "resolved") {
-            resolved.push({ path: relative, name: classification.name, values: classification.values });
+            resolved.push({
+                path: relative,
+                name: classification.name,
+                line: classification.line,
+                values: classification.values,
+            });
         } else {
             unresolvable.push({ path: relative, reason: classification.reason });
         }
diff --git a/tests/js/support/enum-ts-sync/population.ts b/tests/js/support/enum-ts-sync/population.ts
new file mode 100644
index 00000000..ce2e79d0
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/population.ts
@@ -0,0 +1,155 @@
+/**
+ * 逆走査の母集団 (家系の機能台帳 `enum-ts-sync-gate` 正典 v3 の i8)。
+ *
+ * **母集団**: `git ls-files -z` が返す**版管理下の `*.ts` と `*.svelte` の全数**。
+ * 走査根の手書きの列挙は持たない (足し忘れが静かな穴になる)。
+ * `-z` を使うのは、改行を含む合法なパスでも全数を列挙するためである。
+ *
+ * **2 つの一覧を区別する**:
+ * - `listProgramTsFiles()` … 型世界に載せる起点。**`.d.ts` を含む**
+ *   (周囲宣言が落ちると本番と違う型世界になる)
+ * - `listCandidateTsFiles()` … 候補を探す対象。**`.d.ts` を除く**
+ *
+ * どちらかが 0 件なら「母集団が不明」として例外にする (空振りを緑にしない)。
+ *
+ * **唯一の除外**: `EXCLUDED_ROOTS`。**わざと構文を壊した見本**だけを外す。
+ * i14 が「構文が壊れたファイルを無言で読み飛ばさない」ので、これを母集団に入れると
+ * 本番の gate が恒久的に赤くなる。申告では逃がせない (申告は候補を逃がす仕組みで、
+ * 読めないファイルの受け皿ではない)。除外は `tests/js/support/enum-ts-sync/` の
+ * **配下**に限る (構造で縛る。任意のパスを書けない)。
+ *
+ * **除外根の自己点検は利用側の gate が持つ** — 「除外根の配下の全ファイルが実際に
+ * 本番と同じ入口で落ちること」を `enum-ts-sync-discovery.test.ts` が見る。
+ * ここが「除外根へ正常なファイルを置いて母集団から静かに消す」経路を塞ぐ。
+ * 現時点の除外根は `.ts` だけを含む。**将来 `.svelte` を除外根へ入れるなら、
+ * 自己点検は拡張子ごとに本番と同じ入口を使う必要がある** (`.ts` は TypeScript の
+ * 構文診断、`.svelte` は `toVirtualUnit()` の失敗)。
+ *
+ * **保証しないもの**: 版管理外のファイル (無視されたもの・未追跡のもの) は見ない。
+ * `.js` / `.mjs` / `.cjs` は母集団に入れない (本リポジトリの TS 以外の出口は
+ * 本 gate の対象外である)。`git` の作業ツリーと索引が使えることが前提である。
+ */
+import { execFileSync } from "node:child_process";
+import fs from "node:fs";
+import path from "node:path";
+import { EnumTsSyncError } from "./errors";
+import { REPO_ROOT } from "./repo-root";
+
+export interface ExcludedRoot {
+    /** リポジトリ相対のディレクトリ。`tests/js/support/enum-ts-sync/` の配下だけ。 */
+    readonly root: string;
+    /** 外す理由 (30 文字以上)。 */
+    readonly reason: string;
+}
+
+/** 除外根を書ける唯一の場所 (構造で縛る)。 */
+export const EXCLUDED_ROOT_PREFIX = "tests/js/support/enum-ts-sync/";
+
+export const EXCLUDED_ROOTS = [
+    {
+        root: "tests/js/support/enum-ts-sync/fixtures/candidates-broken",
+        reason: "候補走査が構文の壊れたファイルを無言で読み飛ばさないことの負の対照。中身は意図的に壊してあるので母集団に入れると本番の gate が恒久的に赤くなる",
+    },
+] as const satisfies readonly ExcludedRoot[];
+
+/** 除外根の件数の pin。増えても減っても赤くする。 */
+export const EXPECTED_EXCLUDED_ROOT_COUNT = 1;
+
+/**
+ * `git ls-files -z` の生出力から一覧を作る**純関数**
+ * (0 件の分岐を単体で試験できるように分けてある)。
+ */
+export const parseTrackedOutput = (raw: string): readonly string[] =>
+    [...new Set(raw.split("\0").filter((line) => line !== ""))].sort();
+
+const trackedFiles = (root: string, pattern: string): readonly string[] =>
+    parseTrackedOutput(
+        execFileSync("git", ["-C", root, "ls-files", "-z", "--", pattern], {
+            encoding: "utf-8",
+            maxBuffer: 64 * 1024 * 1024,
+        }),
+    );
+
+/**
+ * 除外根の配下か。**パスの区間一致**で見る (素の `startsWith` にすると
+ * 兄弟ディレクトリ `candidates-broken-2/` まで巻き込む)。
+ */
+export const isUnderExcludedRoot = (
+    relative: string,
+    roots: readonly ExcludedRoot[] = EXCLUDED_ROOTS,
+): boolean => roots.some((entry) => relative === entry.root || relative.startsWith(`${entry.root}/`));
+
+const requireNonEmpty = (files: readonly string[], label: string): readonly string[] => {
+    if (files.length === 0) {
+        throw new EnumTsSyncError("population", `${label} が 0 件です (母集団の走査が空振りしています)`);
+    }
+    return files;
+};
+
+/** 型世界に載せる起点 (`.d.ts` を含む)。 */
+export const listProgramTsFiles = (root: string = REPO_ROOT): readonly string[] =>
+    requireNonEmpty(
+        trackedFiles(root, "*.ts").filter((file) => !isUnderExcludedRoot(file)),
+        "版管理下の *.ts",
+    );
+
+/** 候補を探す対象 (`.d.ts` を除く)。 */
+export const listCandidateTsFiles = (root: string = REPO_ROOT): readonly string[] =>
+    requireNonEmpty(
+        listProgramTsFiles(root).filter((file) => !file.endsWith(".d.ts")),
+        "候補走査の対象になる *.ts",
+    );
+
+/** 候補を探す対象の `.svelte`。 */
+export const listCandidateSvelteFiles = (root: string = REPO_ROOT): readonly string[] =>
+    requireNonEmpty(
+        trackedFiles(root, "*.svelte").filter((file) => !isUnderExcludedRoot(file)),
+        "版管理下の *.svelte",
+    );
+
+/**
+ * 除外根の配下にある版管理下ファイル (除外の自己点検に使う)。
+ * 0 件は「除外根が空である = 除外の意味が失われた」ので例外にする。
+ */
+export const listExcludedFiles = (
+    root: string = REPO_ROOT,
+    roots: readonly ExcludedRoot[] = EXCLUDED_ROOTS,
+): readonly string[] => {
+    const files = new Set<string>();
+    for (const entry of roots) {
+        for (const file of trackedFiles(root, entry.root)) files.add(file);
+    }
+    return requireNonEmpty([...files].sort(), "除外根の配下の版管理下ファイル");
+};
+
+/** 除外根の体裁 (配下・実在・重複無し・理由 30 文字以上)。 */
+export const validateExcludedRoots = (
+    roots: readonly ExcludedRoot[] = EXCLUDED_ROOTS,
+    root: string = REPO_ROOT,
+): void => {
+    if (roots.length === 0) {
+        throw new EnumTsSyncError("population", "除外根の一覧が空です (除外の仕組みが黙って消えています)");
+    }
+
+    const seen = new Set<string>();
+    for (const entry of roots) {
+        const where = `除外根 ${entry.root}`;
+        if (path.isAbsolute(entry.root)) throw new EnumTsSyncError(where, "絶対パスは登録できません");
+        if (entry.root.includes("\\")) throw new EnumTsSyncError(where, "逆斜線を含むパスは登録できません");
+        if (entry.root.split("/").some((s) => s === "" || s === "." || s === "..")) {
+            throw new EnumTsSyncError(where, ". / .. / 空の区間を含むパスは登録できません");
+        }
+        if (!entry.root.startsWith(EXCLUDED_ROOT_PREFIX) || entry.root === EXCLUDED_ROOT_PREFIX) {
+            throw new EnumTsSyncError(where, `除外根は ${EXCLUDED_ROOT_PREFIX} の配下だけです`);
+        }
+        const absolute = path.join(root, entry.root);
+        if (!fs.existsSync(absolute) || !fs.statSync(absolute).isDirectory()) {
+            throw new EnumTsSyncError(where, "除外根が実在するディレクトリではありません");
+        }
+        if (seen.has(entry.root)) throw new EnumTsSyncError(where, "同じ除外根が 2 回登録されています");
+        seen.add(entry.root);
+        if (entry.reason.trim().length < 30) {
+            throw new EnumTsSyncError(where, "理由は 30 文字以上で書くこと");
+        }
+    }
+};
diff --git a/tests/js/support/enum-ts-sync/program.ts b/tests/js/support/enum-ts-sync/program.ts
index c0558a3f..3980bb99 100644
--- a/tests/js/support/enum-ts-sync/program.ts
+++ b/tests/js/support/enum-ts-sync/program.ts
@@ -1,25 +1,80 @@
 /**
  * 型情報の入口 (TypeScript の program と型検査器を作る)。
  *
- * **本番の gate は `tsconfig.json` が含む TS ファイル全体で program を作る**。
- * 目録のファイルだけを起点にすると、`include` だけで参加する宣言 (周囲宣言 `.d.ts` /
- * `declare global` / モジュールの拡張) が program に載らず、**本番の型と違う型世界**で
- * 判定してしまう。本リポジトリには実際に `resources/js/lib/recaptcha.ts` の
- * `declare global` があり、この経路は絵空事ではない。偽陰性 (取り残しを緑にする) に
- * なるので、速さのために起点を縮める判断はしない。
+ * **program は 1 本ではなくパッケージごとに作る** (正典 v3 の i5)。
+ * `packages/cli` をルートの設定 (bundler / ESNext) で読むと NodeNext 前提の取り込みが
+ * 解決できず、型が `any` に落ちた宣言が「文字列リテラル型ではない = 非候補」として
+ * **静かに消える**。i5 が言う「本番と同じ型世界」は、道具パッケージにとっては
+ * **そのパッケージ自身の tsconfig** である。
+ *
+ * | program | 起点 |
+ * |---|---|
+ * | `<root>` | ルート `tsconfig.json` の全ファイル ∪ どのパッケージにも属さない版管理下の `*.ts` ∪ 仮想 `.svelte` |
+ * | `packages/<name>` | そのパッケージの `tsconfig.json` の全ファイル ∪ 配下の版管理下の `*.ts` ∪ 配下の仮想 `.svelte` |
+ *
+ * **所有者の判定は `.ts` と `.svelte` で同じ規則を使う** (現時点で `packages/` の下に
+ * `.svelte` は無いが、足されたときにルートの設定で読まれてしまうのを防ぐ)。
+ * tsconfig を持たないパッケージのファイルはどの program にも載らず、母集団の直和検査が
+ * 赤くなる (fail-closed。そのとき扱いを判断させる)。
+ *
+ * 出力はしないので、起点を `rootDir` の外へ足せるよう `rootDir` / `outDir` /
+ * `declaration` / `declarationMap` / `composite` / `sourceMap` は落として組む。
+ *
+ * **`createMirrorProgram(tsFiles)` は廃止した** (2 つの program の作り方を残さない)。
  */
 import ts from "typescript";
 import fs from "node:fs";
 import path from "node:path";
-import { fileURLToPath } from "node:url";
 import { EnumTsSyncError } from "./errors";
+import { REPO_ROOT } from "./repo-root";
+import {
+    listCandidateSvelteFiles,
+    listCandidateTsFiles,
+    listProgramTsFiles,
+    validateExcludedRoots,
+} from "./population";
+import {
+    assertNoModuleToInstanceReference,
+    assertNoVirtualPathCollision,
+    realPathOfVirtual,
+    toVirtualUnit,
+    type SvelteVirtualUnit,
+} from "./svelte-source";
+
+export { REPO_ROOT } from "./repo-root";
 
-/** リポジトリのルート (tests/js/support/enum-ts-sync から 4 つ上)。 */
-export const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../../..");
+/** ルートの program の所有者名 (パッケージのディレクトリ名と衝突しない綴り)。 */
+export const ROOT_OWNER = "<root>";
 
 export interface MirrorProgram {
+    readonly owner: string;
     readonly program: ts.Program;
     readonly checker: ts.TypeChecker;
+    /** 仮想パス (正規化済み) → 元の `.svelte` のリポジトリ相対パス。 */
+    readonly virtualPaths: ReadonlyMap<string, string>;
+    /** この program の起点 (`rootNames`) をリポジトリ相対で表したもの。 */
+    readonly rootRelatives: ReadonlySet<string>;
+}
+
+/** 見本専用の**起点を縮めた** program。**本番の gate では使わない**。 */
+export interface FixtureProgram extends MirrorProgram {
+    readonly fixture: true;
+}
+
+export interface MirrorPrograms {
+    /** 所有者 (`<root>` またはパッケージのディレクトリ) → program。 */
+    readonly byOwner: ReadonlyMap<string, MirrorProgram>;
+    /** 候補走査の母集団 (リポジトリ相対)。 */
+    readonly population: {
+        readonly ts: readonly string[];
+        readonly svelte: readonly string[];
+    };
+    /** 母集団の相対パス → 所有者。 */
+    ownerOf(relativePath: string): string;
+    /** 母集団の相対パス → それを載せている program。 */
+    programOf(relativePath: string): MirrorProgram;
+    /** 相対パス → その program 上の SourceFile (`.svelte` は仮想単位)。 */
+    sourceOf(relativePath: string): ts.SourceFile;
 }
 
 const formatHost: ts.FormatDiagnosticsHost = {
@@ -29,71 +84,208 @@ const formatHost: ts.FormatDiagnosticsHost = {
 };
 
 /** tsconfig.json を読む。回復可能な診断も含めて 1 件でもあれば例外にする。 */
-const parseRepoTsconfig = (): ts.ParsedCommandLine => {
-    const configPath = path.join(REPO_ROOT, "tsconfig.json");
+const parseTsconfig = (configPath: string): ts.ParsedCommandLine => {
+    const where = path.relative(REPO_ROOT, configPath).split(path.sep).join("/");
     const host: ts.ParseConfigFileHost = {
         useCaseSensitiveFileNames: ts.sys.useCaseSensitiveFileNames,
         readDirectory: ts.sys.readDirectory,
         fileExists: ts.sys.fileExists,
         readFile: ts.sys.readFile,
-        getCurrentDirectory: () => REPO_ROOT,
+        getCurrentDirectory: () => path.dirname(configPath),
         onUnRecoverableConfigFileDiagnostic: (d) => {
-            throw new EnumTsSyncError("tsconfig.json", ts.flattenDiagnosticMessageText(d.messageText, " "));
+            throw new EnumTsSyncError(where, ts.flattenDiagnosticMessageText(d.messageText, " "));
         },
     };
     const parsed = ts.getParsedCommandLineOfConfigFile(configPath, {}, host);
-    if (parsed === undefined) throw new EnumTsSyncError("tsconfig.json", "読み込みに失敗しました");
+    if (parsed === undefined) throw new EnumTsSyncError(where, "読み込みに失敗しました");
     if (parsed.errors.length > 0) {
-        throw new EnumTsSyncError("tsconfig.json", ts.formatDiagnostics(parsed.errors, formatHost));
+        throw new EnumTsSyncError(where, ts.formatDiagnostics(parsed.errors, formatHost));
     }
     if (parsed.fileNames.length === 0) {
-        throw new EnumTsSyncError("tsconfig.json", "対象ファイルが 0 件です (gate が空振りしている)");
+        throw new EnumTsSyncError(where, "対象ファイルが 0 件です (gate が空振りしている)");
     }
     return parsed;
 };
 
-const buildProgram = (rootNames: readonly string[], parsed: ts.ParsedCommandLine): MirrorProgram => {
-    const program = ts.createProgram({
-        rootNames: [...rootNames],
-        options: { ...parsed.options, noEmit: true },
-        projectReferences: parsed.projectReferences,
-        configFileParsingDiagnostics: parsed.errors,
-    });
+const relativeOf = (fileName: string): string =>
+    realPathOfVirtual(fileName) ?? path.relative(REPO_ROOT, fileName).split(path.sep).join("/");
+
+/**
+ * program を 1 本組み、仮想単位に対して**検査 B を必ず走らせる**。
+ * **この関数は輸出しない** — 検査を飛ばした program を外から作る経路を型で消すためである。
+ */
+const buildProgram = (
+    owner: string,
+    parsed: ts.ParsedCommandLine,
+    rootNames: readonly string[],
+    virtualUnits: readonly SvelteVirtualUnit[],
+): MirrorProgram => {
+    const options: ts.CompilerOptions = {
+        ...parsed.options,
+        noEmit: true,
+        rootDir: undefined,
+        outDir: undefined,
+        declaration: false,
+        declarationMap: false,
+        composite: false,
+        sourceMap: false,
+    };
+    const base = ts.createCompilerHost(options, true);
+    const virtualText = new Map(virtualUnits.map((unit) => [unit.virtualPath, unit.text]));
+    const host: ts.CompilerHost = {
+        ...base,
+        fileExists: (fileName) => virtualText.has(fileName) || base.fileExists(fileName),
+        readFile: (fileName) => virtualText.get(fileName) ?? base.readFile(fileName),
+        getSourceFile: (fileName, languageVersion, onError, shouldCreate) => {
+            const text = virtualText.get(fileName);
+            return text !== undefined
+                ? ts.createSourceFile(fileName, text, languageVersion, true, ts.ScriptKind.TS)
+                : base.getSourceFile(fileName, languageVersion, onError, shouldCreate);
+        },
+    };
+
+    const roots = [...new Set([...rootNames, ...virtualText.keys()])];
+    const program = ts.createProgram({ rootNames: roots, options, host });
     const optionsDiagnostics = program.getOptionsDiagnostics();
     if (optionsDiagnostics.length > 0) {
-        throw new EnumTsSyncError("tsconfig.json", ts.formatDiagnostics(optionsDiagnostics, formatHost));
+        throw new EnumTsSyncError(owner, ts.formatDiagnostics(optionsDiagnostics, formatHost));
+    }
+    const checker = program.getTypeChecker();
+
+    const canonical = host.getCanonicalFileName.bind(host);
+    const virtualPaths = new Map(virtualUnits.map((unit) => [canonical(unit.virtualPath), unit.source]));
+
+    // 検査 B は program を組んだ直後に必ず走らせる (呼び出し義務を利用側へ渡さない)。
+    for (const unit of virtualUnits) {
+        const source = program.getSourceFile(unit.virtualPath);
+        if (source === undefined) {
+            throw new EnumTsSyncError(unit.source, "仮想単位が program に載っていません");
+        }
+        if (canonical(source.fileName) !== canonical(unit.virtualPath)) {
+            throw new EnumTsSyncError(unit.source, "仮想単位の綴りが正規化の規則と食い違っています");
+        }
+        assertNoModuleToInstanceReference(checker, source, unit);
     }
-    return { program, checker: program.getTypeChecker() };
+
+    return {
+        owner,
+        program,
+        checker,
+        virtualPaths,
+        rootRelatives: new Set(roots.map(relativeOf)),
+    };
+};
+
+/** tsconfig を持つ `packages/<name>` のディレクトリ (リポジトリ相対・綴り順)。 */
+export const listPackageProgramRoots = (root: string = REPO_ROOT): readonly string[] => {
+    const packagesDir = path.join(root, "packages");
+    if (!fs.existsSync(packagesDir) || !fs.statSync(packagesDir).isDirectory()) return [];
+    return fs
+        .readdirSync(packagesDir, { withFileTypes: true })
+        .filter((entry) => entry.isDirectory() && fs.existsSync(path.join(packagesDir, entry.name, "tsconfig.json")))
+        .map((entry) => `packages/${entry.name}`)
+        .sort();
 };
 
 /**
- * 目録が指す TS ファイルを含む program を作る。
- * 起点は **tsconfig が含む全ファイル ∪ 目録のファイル**。
- *
- * @param tsFiles リポジトリルートからの相対パス
+ * 逆走査と前向きの検査が共通で使う program 群を作る。
+ * 目録のファイルも母集団の一部なので所有者の program へ載る。
  */
-export const createMirrorProgram = (tsFiles: readonly string[]): MirrorProgram => {
-    const parsed = parseRepoTsconfig();
-    const inventoryRoots = tsFiles.map((file) => {
-        const absolute = path.join(REPO_ROOT, file);
-        if (!fs.existsSync(absolute)) {
-            throw new EnumTsSyncError(file, "目録が指す TS ファイルが実在しません");
+export const createMirrorPrograms = (): MirrorPrograms => {
+    validateExcludedRoots();
+
+    const programTs = listProgramTsFiles();
+    const candidateTs = listCandidateTsFiles();
+    const candidateSvelte = listCandidateSvelteFiles();
+    const packageRoots = listPackageProgramRoots();
+
+    const ownerOfRelative = (relative: string): string =>
+        packageRoots.find((dir) => relative.startsWith(`${dir}/`)) ?? ROOT_OWNER;
+
+    const units = candidateSvelte.map((relative) =>
+        toVirtualUnit(relative, fs.readFileSync(path.join(REPO_ROOT, relative), "utf-8")),
+    );
+    assertNoVirtualPathCollision(units, programTs);
+    const virtualByReal = new Map(units.map((unit) => [unit.source, unit]));
+
+    const absolute = (relative: string): string => path.join(REPO_ROOT, relative);
+
+    const rootParsed = parseTsconfig(path.join(REPO_ROOT, "tsconfig.json"));
+    const byOwner = new Map<string, MirrorProgram>();
+    byOwner.set(
+        ROOT_OWNER,
+        buildProgram(
+            ROOT_OWNER,
+            rootParsed,
+            [...rootParsed.fileNames, ...programTs.filter((file) => ownerOfRelative(file) === ROOT_OWNER).map(absolute)],
+            units.filter((unit) => ownerOfRelative(unit.source) === ROOT_OWNER),
+        ),
+    );
+    for (const dir of packageRoots) {
+        const parsed = parseTsconfig(path.join(REPO_ROOT, dir, "tsconfig.json"));
+        byOwner.set(
+            dir,
+            buildProgram(
+                dir,
+                parsed,
+                [...parsed.fileNames, ...programTs.filter((file) => ownerOfRelative(file) === dir).map(absolute)],
+                units.filter((unit) => ownerOfRelative(unit.source) === dir),
+            ),
+        );
+    }
+
+    const ownerOf = (relative: string): string => {
+        const owner = ownerOfRelative(relative);
+        if (!byOwner.has(owner)) {
+            throw new EnumTsSyncError(relative, `所有者 ${owner} の program がありません (tsconfig を持たないパッケージです)`);
+        }
+        return owner;
+    };
+
+    const programOf = (relative: string): MirrorProgram => {
+        const program = byOwner.get(ownerOf(relative));
+        if (program === undefined) throw new EnumTsSyncError(relative, "所有者の program を解決できません");
+        return program;
+    };
+
+    const sourceOf = (relative: string): ts.SourceFile => {
+        const mirror = programOf(relative);
+        let fileName = absolute(relative);
+        if (relative.endsWith(".svelte")) {
+            const unit = virtualByReal.get(relative);
+            if (unit === undefined) throw new EnumTsSyncError(relative, ".svelte が仮想化されていません");
+            fileName = unit.virtualPath;
         }
-        return absolute;
-    });
-    return buildProgram([...new Set([...parsed.fileNames, ...inventoryRoots])], parsed);
+        const source = mirror.program.getSourceFile(fileName);
+        if (source === undefined) throw new EnumTsSyncError(relative, `所有者 ${mirror.owner} の program に載っていません`);
+        return source;
+    };
+
+    return {
+        byOwner,
+        population: { ts: candidateTs, svelte: candidateSvelte },
+        ownerOf,
+        programOf,
+        sourceOf,
+    };
 };
 
 /**
  * 見本 (fixture) 専用の**起点を縮めた** program。**本番の gate では使わない**。
  * リポジトリの `compilerOptions` (`paths` を含む) はそのまま使い、起点だけを明示する。
+ * 仮想単位を渡した場合も**検査 B は必ず走る** (本番と同じ一本道)。
  *
  * @param absoluteFiles 絶対パス
+ * @param virtualUnits  仮想 `.svelte` 単位 (省略可)
  */
-export const createFixtureProgram = (absoluteFiles: readonly string[]): MirrorProgram => {
-    const parsed = parseRepoTsconfig();
-    for (const absolute of absoluteFiles) {
-        if (!fs.existsSync(absolute)) throw new EnumTsSyncError(absolute, "見本ファイルが実在しません");
+export const createFixtureProgram = (
+    absoluteFiles: readonly string[],
+    virtualUnits: readonly SvelteVirtualUnit[] = [],
+): FixtureProgram => {
+    for (const file of absoluteFiles) {
+        if (!fs.existsSync(file)) throw new EnumTsSyncError(file, "見本ファイルが実在しません");
     }
-    return buildProgram(absoluteFiles, parsed);
+    // 起点は明示したものだけにする (tsconfig の全ファイルは載せない = 縮めた program)。
+    const parsed = parseTsconfig(path.join(REPO_ROOT, "tsconfig.json"));
+    return { ...buildProgram("<fixture>", parsed, absoluteFiles, virtualUnits), fixture: true };
 };
diff --git a/tests/js/support/enum-ts-sync/mirror-inventory.ts b/tests/js/support/enum-ts-sync/relation-inventory.ts
similarity index 60%
rename from tests/js/support/enum-ts-sync/mirror-inventory.ts
rename to tests/js/support/enum-ts-sync/relation-inventory.ts
index f1348125..eaf2239a 100644
--- a/tests/js/support/enum-ts-sync/mirror-inventory.ts
+++ b/tests/js/support/enum-ts-sync/relation-inventory.ts
@@ -1,220 +1,322 @@
 /**
- * PHP 列挙 ⇔ TS 値域の写しの目録 (`ENUM_TS_MIRRORS`) と、その体裁を検査する
- * `validateMirrors()`。
+ * PHP 列挙 ⇔ TS 値域の**関係**の目録 (`ENUM_TS_RELATIONS`) と、その体裁を検査する
+ * `validateRelations()`。
  *
- * `tests/js/architecture/enum-ts-sync.test.ts` (登録した写しの値集合が一致することを見る)
- * と `tests/js/architecture/enum-ts-sync-discovery.test.ts` (発見の段・逆走査。
+ * `tests/js/architecture/enum-ts-sync.test.ts` (登録した関係が成り立つことを見る) と
+ * `tests/js/architecture/enum-ts-sync-discovery.test.ts` (発見の段・逆走査。
  * どの PHP 列挙・TS 宣言が「登録済み」かを判定するのに同じ目録を使う) の**両方から使う
- * 単一の出典**である。2 つに分かれると「片方だけ更新して食い違う」経路が生まれるため、
- * ここへ集約している。
+ * 単一の出典**である。2 つに分かれると「片方だけ更新して食い違う」経路が生まれる。
+ *
+ * **関係は 2 つある**。`equal` は値域そのものの写しで双方向の差分が空であること、
+ * `subset` は**値域の写しではなく、許される値域から選んだ非空の集合**で
+ * 「TS 側にだけある値が無い」ことだけを見る。`subset` の行には
+ * **なぜ値域の写しではないのか**を `subsetReason` (30 文字以上) で書く。
+ * **`subset` は逃げ道になり得る** (完全一致の写しを `subset` と偽れば緩む)。
+ * 機械では見分けられないので、`subsetReason` の記述とレビューで担保する。
+ *
+ * **登録できる TS の置き場**は `resources/js/` (画面側) と `packages/<name>/src/`
+ * (付属のコマンドライン道具) で、拡張子は `.ts` と `.svelte`。
+ * `tests/js/` と `packages/<name>/tests/` は登録の置き場ではない
+ * (検査の見本を写しとして登録しない)。
  */
 import fs from "node:fs";
 import path from "node:path";
 import { EnumTsSyncError } from "./errors";
-import { REPO_ROOT } from "./program";
+import { REPO_ROOT } from "./repo-root";
 
-export interface EnumTsMirror {
+/** 目録の 1 行の共通部分。 */
+export interface EnumTsRelationBase {
     /** リポジトリルートからの PHP 列挙ファイルの相対パス (`app/` 配下の `*.php`)。 */
     readonly php: string;
-    /** リポジトリルートからの TS ファイルの相対パス (`resources/js/` 配下の `*.ts`)。 */
+    /** リポジトリルートからの TS ファイルの相対パス (`resources/js/` か `packages/<name>/src/` の配下)。 */
     readonly ts: string;
-    /** TS 側の型別名の名前。 */
+    /** TS 側の宣言の名前 (型別名 または `const` の配列)。 */
     readonly declaration: string;
-    /** この写しが要る理由 (画面のどこが値で分岐するか)。 */
+    /** この関係が要る理由 (画面や道具のどこが値で分岐するか)。 */
     readonly note: string;
 }
 
 /**
- * 写しの目録。
+ * PHP の値集合と TS の値集合の関係。**判別された合併**にして、
+ * `"subset"` の行にだけ追加の申告 (`subsetReason`) を要求する
+ * (`note` は `"equal"` の行にもあるので、`note` 非空だけでは subset 固有の負担にならない)。
+ */
+export type EnumTsRelationEntry =
+    | (EnumTsRelationBase & { readonly relation: "equal" })
+    | (EnumTsRelationBase & {
+          readonly relation: "subset";
+          /** **なぜ値域の写しではないのか** (30 文字以上)。 */
+          readonly subsetReason: string;
+      });
+
+/**
+ * 関係の目録。
  * `note` に最小文字数は課さない — 本目録は**免除の申告ではなく登録**であり、
  * 「検査から外す」判断ではないため (免除目録が 30 文字を課すのとは重さが違う)。
+ * `subsetReason` だけは 30 文字以上を課す (前向きの検査を片側だけにする申告であるため)。
  */
-export const ENUM_TS_MIRRORS = [
+export const ENUM_TS_RELATIONS = [
     {
         php: "app/Enums/EnterpriseSso/OidcConnectionStatus.php",
         ts: "resources/js/components/features/sso/oidc-connection.ts",
         declaration: "OidcConnectionStatus",
+        relation: "equal",
         note: "企業 SSO の接続管理画面がバッジ・案内文・押せる操作を状態 4 値で分岐する",
     },
     {
         php: "app/Enums/Organization/EntryTarget.php",
         ts: "resources/js/types/organization.ts",
         declaration: "OrganizationEntryTarget",
+        relation: "equal",
         note: "組織を選ぶ画面が「どこへ向かう選択なのか」を描くために値を受け取る",
     },
     {
         php: "app/Enums/Manual/VideoManualStatus.php",
         ts: "resources/js/types/manual.ts",
         declaration: "VideoManualStatus",
+        relation: "equal",
         note: "詳細画面とダッシュボードが制作状態 5 値で CTA を分岐する",
     },
     {
         php: "app/Enums/Manual/ManualProgress.php",
         ts: "resources/js/types/manual.ts",
         declaration: "ManualProgress",
+        relation: "equal",
         note: "一覧の絞り込みと行バッジが 3 値で分岐する",
     },
     {
         php: "app/Enums/Manual/RenderKind.php",
         ts: "resources/js/types/manual.ts",
         declaration: "RenderKind",
+        relation: "equal",
         note: "プレビューと完成動画で受け取り口の扱いを分ける",
     },
     {
         php: "app/Enums/Manual/RenderStep.php",
         ts: "resources/js/types/manual.ts",
         declaration: "RenderStep",
+        relation: "equal",
         note: "合成の進捗表示が段の値で分岐する",
     },
     {
         php: "app/Enums/Manual/RenderErrorCode.php",
         ts: "resources/js/types/manual.ts",
         declaration: "RenderErrorCode",
+        relation: "equal",
         note: "失敗時の案内文を符号で選ぶ",
     },
     {
         php: "app/Enums/Manual/RenderConflictType.php",
         ts: "resources/js/types/manual.ts",
         declaration: "RenderConflictType",
+        relation: "equal",
         note: "409 の理由ごとに画面の受け方を変える",
     },
     {
         php: "app/Enums/Manual/ScenarioVerdict.php",
         ts: "resources/js/types/manual.ts",
         declaration: "ScenarioVerdict",
+        relation: "equal",
         note: "台本の判定バッジが 3 値で分岐する",
     },
     {
         php: "app/Enums/Manual/ScenarioRuleCode.php",
         ts: "resources/js/types/manual.ts",
         declaration: "ScenarioRuleCode",
+        relation: "equal",
         note: "台本の指摘一覧が規則の符号で文言を選ぶ",
     },
     {
         php: "app/Enums/Manual/JobStatus.php",
         ts: "resources/js/types/manual.ts",
         declaration: "AnalysisJobStatus",
+        relation: "equal",
         note: "解析ジョブの進行表示が状態で分岐する (TS 側は別名)",
     },
     {
         php: "app/Enums/Manual/MaterialType.php",
         ts: "resources/js/types/manual.ts",
         declaration: "CutMaterialType",
+        relation: "equal",
         note: "カット編集が素材種別で入力欄を切り替える",
     },
     {
         php: "app/Enums/Manual/MaterialType.php",
         ts: "resources/js/types/capture.ts",
         declaration: "MaterialType",
+        relation: "equal",
         note: "撮影 PWA 側の写し。PC 側と types を分けてあるので両方 pin する",
     },
     {
         php: "app/Enums/Notification/NotificationType.php",
         ts: "resources/js/types/notification.ts",
         declaration: "NotificationType",
+        relation: "equal",
         note: "通知一覧がアイコンと文言を種別で選ぶ",
     },
     {
         php: "app/Enums/Billing/OnboardingBillingState.php",
         ts: "resources/js/types/billing.ts",
         declaration: "BillingStateValue",
+        relation: "equal",
         note: "契約画面とダッシュボードの両方が契約状態で分岐する",
     },
     {
         php: "app/Enums/AccountDeletionBlockerAction.php",
         ts: "resources/js/types/account.ts",
         declaration: "AccountDeletionBlockerAction",
+        relation: "equal",
         note: "退会ガードの「次の一手」で導線を分岐する",
     },
     {
         php: "app/Enums/PlanCode.php",
         ts: "resources/js/types/Auth.ts",
         declaration: "PlanCode",
+        relation: "equal",
         note: "契約プランの符号で表示と導線を分岐する",
     },
     {
         php: "app/Enums/AdminConsoleRole.php",
         ts: "resources/js/types/admin.ts",
         declaration: "ConsoleRole",
+        relation: "equal",
         note: "ユーザー管理のロール遷移コマンド (TS 側は別名)",
     },
     {
         php: "app/Enums/MemberRoleState.php",
         ts: "resources/js/types/admin.ts",
         declaration: "MemberRoleState",
+        relation: "equal",
         note: "ユーザー管理の表示状態 5 値。TS 側は ConsoleRole の別名参照を含む",
     },
     {
         php: "app/Enums/OrganizationRole.php",
         ts: "resources/js/lib/shared-props.ts",
         declaration: "OrganizationRoleValue",
+        relation: "equal",
         note: "共有 props の組織ロールで画面の権限表示を分岐する",
     },
     {
         php: "app/Enums/Billing/BillingFeedbackKind.php",
         ts: "resources/js/types/billing.ts",
         declaration: "BillingFeedbackKind",
+        relation: "equal",
         note: "課金画面の通知種別で文言を選ぶ",
     },
     {
         php: "app/Enums/Billing/PurchaseFormState.php",
         ts: "resources/js/types/billing.ts",
         declaration: "PurchaseFormStateValue",
+        relation: "equal",
         note: "購入フォームの状態で入力欄の初期値を変える",
     },
     {
         php: "app/Enums/Manual/TakeStatus.php",
         ts: "resources/js/types/capture.ts",
         declaration: "TakeStatus",
+        relation: "equal",
         note: "撮影テイクの状態で再撮影・採用の可否表示を分岐する",
     },
     {
         php: "app/Enums/Dashboard/DashboardState.php",
         ts: "resources/js/types/dashboard.ts",
         declaration: "DashboardState",
+        relation: "equal",
         note: "ダッシュボードの初期状態で案内を切り替える",
     },
     {
         php: "app/Enums/Dashboard/DashboardRole.php",
         ts: "resources/js/types/dashboard.ts",
         declaration: "DashboardRole",
+        relation: "equal",
         note: "ダッシュボードの役割で出す導線を変える",
     },
     {
         php: "app/Enums/Manual/AnalysisStep.php",
         ts: "resources/js/types/manual.ts",
         declaration: "AnalysisStep",
+        relation: "equal",
         note: "解析の進捗表示が段の値で分岐する",
     },
     {
         php: "app/Enums/Manual/AnalysisConflictType.php",
         ts: "resources/js/types/manual.ts",
         declaration: "AnalysisConflictType",
+        relation: "equal",
         note: "解析要求の 409 の理由ごとに案内を変える",
     },
     {
         php: "app/Enums/Manual/ScenarioConflictType.php",
         ts: "resources/js/types/manual.ts",
         declaration: "ScenarioConflictType",
+        relation: "equal",
         note: "台本保存の 409 の理由ごとに案内を変える",
     },
     {
         php: "app/Enums/Manual/ManualSortOption.php",
         ts: "resources/js/types/manual.ts",
         declaration: "ManualSortOption",
+        relation: "equal",
         note: "一覧の並び順の選択肢を URL クエリと突き合わせる",
     },
-] as const satisfies readonly EnumTsMirror[];
+    {
+        php: "app/Enums/ApiErrorCode.php",
+        ts: "packages/cli/src/api/schemas.ts",
+        declaration: "API_ERROR_CODES",
+        relation: "equal",
+        note: "付属のコマンドライン道具が応答の符号で失敗の種類を分ける (rate-limit / conflict / auth)",
+    },
+    {
+        php: "app/Enums/OAuth/CliOAuthScope.php",
+        ts: "packages/cli/src/oauth/login.ts",
+        declaration: "DEFAULT_CLI_SCOPES",
+        relation: "subset",
+        note: "道具がログインのときに既定で要求する権限の集合",
+        subsetReason: "値域そのものの写しではなく、サーバが認識する値域から道具が既定で要求する権限だけを選んだ集合であるため。サーバ側の追加を道具へ強制しない (最小権限)",
+    },
+] as const satisfies readonly EnumTsRelationEntry[];
 
 /**
- * 目録の件数の pin。増えても減っても赤くする (写しが黙って消えるのを防ぐ)。
- * **これは網羅の証明ではない** — 登録していない写しは 1 件も検査していない。
+ * 目録の件数の pin。増えても減っても赤くする (関係が黙って消えるのを防ぐ)。
+ * **これは網羅の証明ではない** — 登録していない写しは 1 件も検査していない
+ * (未登録の検出は逆走査の担当)。
  */
-export const EXPECTED_MIRROR_COUNT = 29;
+export const EXPECTED_RELATION_COUNT = 31;
 
 /** `root` の**配下**にあるか (兄弟ディレクトリを通さないよう区切りまで含めて見る)。 */
 export const isUnder = (absolute: string, root: string): boolean => absolute.startsWith(root + path.sep);
 
+/** 登録できる TS の拡張子。 */
+const TS_EXTENSIONS = [".ts", ".svelte"] as const;
+
+/**
+ * 登録できる TS の置き場。
+ * - `resources/js/` … 画面側
+ * - `packages/<name>/src/` … 付属のコマンドライン道具 (本 feature の境界は画面側に限らない)
+ *
+ * `listPackageSrcRoots()` は綴り順に整列し、**通常ディレクトリだけ**を返す (診断を安定させる)。
+ */
+const listPackageSrcRoots = (root: string): readonly string[] => {
+    const packagesDir = path.join(root, "packages");
+    if (!fs.existsSync(packagesDir) || !fs.statSync(packagesDir).isDirectory()) return [];
+    return fs
+        .readdirSync(packagesDir, { withFileTypes: true })
+        .filter((entry) => entry.isDirectory())
+        .map((entry) => path.join(packagesDir, entry.name, "src"))
+        // `lstat` で見る = **symlink のディレクトリは根にしない** (根そのものが
+        // 走査範囲の外を指す形を最初から作らせない)。
+        .filter((dir) => fs.existsSync(dir) && fs.lstatSync(dir).isDirectory())
+        .sort();
+};
+
+const tsRootsOf = (root: string): readonly string[] => [
+    path.join(root, "resources", "js"),
+    ...listPackageSrcRoots(root),
+];
+
+/** 登録できる置き場の説明 (失敗メッセージと負の対照が同じ文面を見る)。 */
+export const TS_ROOT_DESCRIPTION = "ts は resources/js/ 配下か packages/*/src/ 配下だけです";
+
 /**
  * 目録の行の体裁を検査する純関数。
  * **program を作る前に呼ぶ** — 後回しにすると、検査の外にあるファイルを
@@ -225,9 +327,9 @@ export const isUnder = (absolute: string, root: string): boolean => absolute.sta
  *             兄弟ディレクトリを含む見本の木を一時ディレクトリに作って渡すためだけ**に
  *             引数化してある (本番の呼び出しは既定値を使う)。
  */
-export const validateMirrors = (rows: readonly EnumTsMirror[], root: string = REPO_ROOT): void => {
+export const validateRelations = (rows: readonly EnumTsRelationEntry[], root: string = REPO_ROOT): void => {
     const appRoot = path.join(root, "app");
-    const jsRoot = path.join(root, "resources", "js");
+    const tsRoots = tsRootsOf(root);
     const seen = new Set<string>();
     const seenReal = new Set<string>();
 
@@ -244,17 +346,25 @@ export const validateMirrors = (rows: readonly EnumTsMirror[], root: string = RE
         }
 
         if (!row.php.endsWith(".php")) throw new EnumTsSyncError(where, `php は .php で終わること: ${row.php}`);
-        if (!row.ts.endsWith(".ts")) throw new EnumTsSyncError(where, `ts は .ts で終わること: ${row.ts}`);
+        if (!TS_EXTENSIONS.some((extension) => row.ts.endsWith(extension))) {
+            throw new EnumTsSyncError(where, `ts は .ts か .svelte で終わること: ${row.ts}`);
+        }
         if (row.note.trim() === "") throw new EnumTsSyncError(where, "note が空です");
+        if (row.relation === "subset" && row.subsetReason.trim().length < 30) {
+            throw new EnumTsSyncError(where, "subsetReason は 30 文字以上で書くこと (なぜ値域の写しではないのか)");
+        }
 
         const phpAbs = path.resolve(root, row.php);
         const tsAbs = path.resolve(root, row.ts);
         if (!isUnder(phpAbs, appRoot)) throw new EnumTsSyncError(where, `php は app/ 配下だけ: ${row.php}`);
-        if (!isUnder(tsAbs, jsRoot)) throw new EnumTsSyncError(where, `ts は resources/js/ 配下だけ: ${row.ts}`);
+        // 字面で一致した根に対して symlink の脱出検査を行う
+        // (別の根と比べると拒否漏れ・誤拒否のどちらも起きる)。
+        const matchedRoot = tsRoots.find((tsRoot) => isUnder(tsAbs, tsRoot));
+        if (matchedRoot === undefined) throw new EnumTsSyncError(where, `${TS_ROOT_DESCRIPTION}: ${row.ts}`);
 
         for (const [absolute, scanRoot, label] of [
             [phpAbs, appRoot, row.php],
-            [tsAbs, jsRoot, row.ts],
+            [tsAbs, matchedRoot, row.ts],
         ] as const) {
             if (!fs.existsSync(absolute)) throw new EnumTsSyncError(where, `登録されたファイルが実在しません: ${label}`);
             if (!fs.statSync(absolute).isFile()) throw new EnumTsSyncError(where, `通常ファイルではありません: ${label}`);
@@ -277,9 +387,13 @@ export const validateMirrors = (rows: readonly EnumTsMirror[], root: string = RE
 };
 
 /** 登録済みの `(php パス)` 集合。発見の段が「登録済み」を判定するのに使う。 */
-export const registeredPhpPaths = (rows: readonly EnumTsMirror[] = ENUM_TS_MIRRORS): ReadonlySet<string> =>
+export const declaredPhpPaths = (rows: readonly EnumTsRelationEntry[] = ENUM_TS_RELATIONS): ReadonlySet<string> =>
     new Set(rows.map((row) => row.php));
 
-/** 登録済みの `(ts パス, 宣言名)` 集合。逆走査が「登録済み」を判定するのに使う。 */
-export const registeredTsKeys = (rows: readonly EnumTsMirror[] = ENUM_TS_MIRRORS): ReadonlySet<string> =>
+/**
+ * 登録済みの `(ts パス, 宣言名)` 集合。
+ * **逆走査が「登録済み」を判定するのに使うのは locator であり本集合ではない**
+ * (locator は `ts-value-sets.ts` の解決が AST から作る)。本集合は診断と重複検査の補助である。
+ */
+export const declaredTsKeys = (rows: readonly EnumTsRelationEntry[] = ENUM_TS_RELATIONS): ReadonlySet<string> =>
     new Set(rows.map((row) => `${row.ts}::${row.declaration}`));
diff --git a/tests/js/support/enum-ts-sync/repo-root.ts b/tests/js/support/enum-ts-sync/repo-root.ts
new file mode 100644
index 00000000..e6d9a05a
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/repo-root.ts
@@ -0,0 +1,12 @@
+/**
+ * リポジトリのルートだけを持つ最下層のモジュール。
+ *
+ * 母集団 (`population.ts`) と program (`program.ts`) が互いを参照するため、
+ * 両方が要る 1 つの値だけをここへ切り出してある (循環取り込みを作らない)。
+ * `program.ts` は後方の呼び出し側のために同じ名前で再輸出する。
+ */
+import path from "node:path";
+import { fileURLToPath } from "node:url";
+
+/** リポジトリのルート (tests/js/support/enum-ts-sync から 4 つ上)。 */
+export const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../../..");
diff --git a/tests/js/support/enum-ts-sync/reverse-sweep.ts b/tests/js/support/enum-ts-sync/reverse-sweep.ts
index 25e466cc..923af5d3 100644
--- a/tests/js/support/enum-ts-sync/reverse-sweep.ts
+++ b/tests/js/support/enum-ts-sync/reverse-sweep.ts
@@ -1,99 +1,301 @@
 /**
- * 逆走査 (裁定 AG-099 後半)。
+ * 逆走査の突き合わせ (正典 v3 の i10)。
  *
- * `enum-ts-sync.test.ts` は「目録に登録した写しについて PHP → TS を見る」向きの検査なので、
- * **登録し忘れた写し**は素通りする。本モジュールは向きを変え、TS 側の型別名の候補
- * (`collectTsUnionCandidates`) と PHP の文字列付き列挙の母集団 (`buildPhpEnumCatalog`)
- * を突き合わせ、次の 2 規則で「未登録だが対応していそうな組」を検出する。
+ * `enum-ts-sync.test.ts` は「目録に登録した関係について PHP → TS を見る」向きの検査なので、
+ * **登録し忘れた写し**は素通りする。本モジュールは向きを変え、TS 側の候補
+ * (`collectTsCandidates`) と PHP の文字列付き列挙の母集団 (`buildPhpEnumCatalog`) を
+ * 突き合わせ、次の規則で「未登録だが対応していそうな組」を検出する。
  *
- * - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全に一致する未登録の TS 宣言。
- *   これは「登録を忘れているだけ」の可能性が高い最有力候補である。
- * - **規則 2 (名前対応 + 値の交差)**: 型別名の名前が PHP 列挙名と厳密に対応し
- *   (一致 / 複数形接尾辞 `s` `es` `values` の付加)、かつ値集合が交差するが**完全一致ではない**
- *   未登録の TS 宣言。これは「かつて対応していたが、どちらか片方だけ値を足して
- *   ズレた写し」を拾うためのもので、規則 1 に緩い部分集合や名前無視の条件を混ぜると
- *   誤検出が支配的になる (家系の実測: 緩い形は偽陽性 80〜100%)。
+ * - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全に一致する未登録の宣言
+ * - **規則 2a (厳密名対応 + 1 値以上の交差)**: 名前が小文字化して一致 / `+s` / `+es` /
+ *   `+values` で対応し、値集合が交差するが完全一致ではない宣言
+ * - **規則 2b (語分割名対応 + 両側から見て半分以上の交差)**: 名前を語に割って対応を見る
+ *
+ * **規則 2 は 2a と 2b の論理和**であり、**どちらの式も他方を包含しない**
+ * (家系の未決論点 q2 に対する本リポジトリの一次観測。実測は
+ * `devnotes/20260824-1633-enum-ts-sync-gate-v3/probe/measurements.md`)。
+ *
+ * **判定の順序 (排他)**:
+ * 1. 値集合が完全一致 → 規則 1
+ * 2. 交差が 0 なら何もしない
+ * 3. 名前を決められない (`nameResolved` が偽) → **判定不能** (gate を赤くする)
+ * 4. 2a の名前対応が成立 → 規則 2a
+ * 5. 2b の名前対応と交差条件を満たす → 規則 2b
+ * 6. どれでもなければ鳴らさない
+ *
+ * **語の区切りの宣言 (AGENTS.md §共通規約 (e))**: 語に割る文字は `_` `-` `.` `:` `$` と
+ * 空白類。加えて**大文字の境界**(小文字または数字 → 大文字 / 大文字の連なり → 大文字 + 小文字)
+ * と**数字の境界**(英字 ↔ 数字) でも割る。割った後は空の要素を捨て、すべて小文字化する。
+ *
+ * **正規化は「1 つの正規形へ畳む」形を採らない**。接尾辞だけで畳むと
+ * `cases → cas` / `uses → us` のように誤った語幹を正規形にしてしまう。代わりに
+ * 語ごとに候補形の集合 (`wordForms`) を作り、**集合が交われば同じ語**とみなす。
+ * これは過剰検出の向きへ倒した判定であり、鳴った先は申告で逃がせる。
+ * **これ以上の語形変化 (不規則変化・語幹の交替) は扱わない**。
  *
  * **これは「登録漏れが無いことの証明」ではなく「候補の検出」である**。
- * 名前も対応せず値も完全一致しない drift 済みの写しは検出できない (意図した限界)。
+ * 名前も対応せず値も半分未満しか交差しない drift 済みの写しは検出できない (意図した限界)。
  */
 import type { ResolvedPhpEnum } from "./php-enum-catalog";
-import type { TsUnionCandidate } from "./ts-candidates";
+import type { TsCandidateLocator, TsUnionCandidate } from "./ts-candidates";
+import { locatorKey } from "./ts-candidates";
+
+/** 適用した規則。申告の同一性に含める (規則が変わったら申告は stale になる)。 */
+export type ReverseSweepRule = "1" | "2a" | "2b";
 
 export interface UnregisteredMirrorCandidate {
-    readonly rule: 1 | 2;
+    readonly rule: ReverseSweepRule;
     readonly php: ResolvedPhpEnum;
     readonly candidate: TsUnionCandidate;
-    /** 規則 1 は `null`。規則 2 は名前の対応関係の説明 (メッセージ用)。 */
-    readonly nameMatch: string | null;
+    /** 鳴った理由 (どの規則・どの語・どの値の交差で鳴ったか)。 */
+    readonly reason: string;
+    readonly onlyInPhp: readonly string[];
+    readonly onlyInTs: readonly string[];
 }
 
-/**
- * 大文字小文字の違いだけを吸収する。**英数字以外は除去しない**
- * (`_` や `$` まで消すと `Foo_Bar` と `FooBar` を同一視してしまい、
- * 「一致 / +s / +es / +values」という厳密な対応より緩くなる)。
- */
-const normalizeName = (name: string): string => name.toLowerCase();
+/** 名前を決められないので規則 2 を判定できなかった組 (gate を赤くする)。 */
+export interface UndecidableMirrorPair {
+    readonly php: ResolvedPhpEnum;
+    readonly candidate: TsUnionCandidate;
+    readonly intersectionSize: number;
+}
 
-/** ファイル名の語幹を取る (テストの見本構築用のユーティリティ。判定本体は `ResolvedPhpEnum.name` を使う)。 */
+export interface ReverseSweepResult {
+    readonly found: readonly UnregisteredMirrorCandidate[];
+    readonly undecidable: readonly UndecidableMirrorPair[];
+}
+
+export type ReverseRuleOutcome =
+    | { readonly kind: "match"; readonly rule: ReverseSweepRule; readonly reason: string }
+    | { readonly kind: "undecidable"; readonly intersectionSize: number }
+    | { readonly kind: "none" };
+
+/** ファイル名の語幹を取る (テストの見本構築用。判定本体は `ResolvedPhpEnum.name` を使う)。 */
 export const shortEnumName = (path: string): string => {
     const base = path.split("/").pop() ?? path;
     return base.endsWith(".php") ? base.slice(0, -".php".length) : base;
 };
 
-/** 厳密な名前対応 (一致 / +s / +es / +values)。対応しなければ `null`。 */
-const nameCorrespondence = (candidateName: string, enumName: string): string | null => {
-    const candidate = normalizeName(candidateName);
-    const target = normalizeName(enumName);
-    if (candidate === target) return `${target} = ${candidate}`;
+/** 分岐のラベルの `switch:` は**両規則の共通の前処理**で外す。 */
+const stripSwitchPrefix = (name: string): string => name.replace(/^switch:/, "");
+
+/**
+ * 厳密な名前対応 (一致 / `+s` / `+es` / `+values`)。
+ * 小文字化して比較し、**英数字以外は除去しない**
+ * (`_` や `$` まで消すと `Foo_Bar` と `FooBar` を同一視してしまう)。
+ */
+export const strictNameCorrespondence = (candidateName: string, enumName: string): string | null => {
+    const candidate = candidateName.toLowerCase();
+    const target = enumName.toLowerCase();
+    if (candidate === target) return `厳密名対応 (${target} = ${candidate})`;
     for (const suffix of ["s", "es", "values"]) {
-        if (candidate === `${target}${suffix}`) return `${target} + "${suffix}" = ${candidate}`;
+        if (candidate === `${target}${suffix}`) return `厳密名対応 (${target} + "${suffix}" = ${candidate})`;
     }
     return null;
 };
 
-const sameValueSet = (a: ReadonlySet<string>, b: ReadonlySet<string>): boolean => {
-    if (a.size !== b.size) return false;
-    for (const value of a) if (!b.has(value)) return false;
-    return true;
+/** 語の候補形の集合。**1 つの正規形へ畳まない** (誤った語幹を正規形にしないため)。 */
+export const wordForms = (word: string): ReadonlySet<string> => {
+    const forms = new Set<string>([word]);
+    if (word.endsWith("ies") && word.length > 3) forms.add(`${word.slice(0, -3)}y`);
+    if (word.length > 2 && /(?:s|x|z|ch|sh)es$/.test(word)) forms.add(word.slice(0, -2));
+    if (word.endsWith("s") && !word.endsWith("ss") && word.length > 1) forms.add(word.slice(0, -1));
+    return forms;
 };
 
-const intersects = (a: ReadonlySet<string>, b: ReadonlySet<string>): boolean => {
-    for (const value of a) if (b.has(value)) return true;
+/** 2 つの語が対応するか (候補形の集合が交わるか)。**推移律は持たない**。 */
+export const correspondWords = (a: string, b: string): boolean => {
+    const formsOfA = wordForms(a);
+    for (const form of wordForms(b)) if (formsOfA.has(form)) return true;
     return false;
 };
 
+/** 識別子を語に割る (区切りの宣言は本モジュールの docblock)。 */
+export const splitWords = (identifier: string): readonly string[] =>
+    stripSwitchPrefix(identifier)
+        .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
+        .replace(/([A-Z]+)([A-Z][a-z])/g, "$1 $2")
+        .replace(/([A-Za-z])([0-9])/g, "$1 $2")
+        .replace(/([0-9])([A-Za-z])/g, "$1 $2")
+        .split(/[^A-Za-z0-9]+/)
+        .map((word) => word.toLowerCase())
+        .filter((word) => word !== "");
+
+/**
+ * 列挙側の語と候補側の語袋の**最大マッチング** (候補側の 1 語を 2 回使わない)。
+ * 「列挙の各語について語袋のどれかと対応するか」を単純に数えると候補側の 1 語が
+ * 使い回されるが、`correspondWords` は推移律を持たないので同値類にも畳めない。
+ */
+export const maxWordMatching = (enumWords: readonly string[], bag: readonly string[]): number => {
+    const matchOf = new Array<number>(bag.length).fill(-1);
+    const tryAssign = (index: number, seen: boolean[]): boolean => {
+        for (let j = 0; j < bag.length; j += 1) {
+            if (seen[j] || !correspondWords(enumWords[index], bag[j])) continue;
+            seen[j] = true;
+            if (matchOf[j] === -1 || tryAssign(matchOf[j], seen)) {
+                matchOf[j] = index;
+                return true;
+            }
+        }
+        return false;
+    };
+    let matched = 0;
+    for (let i = 0; i < enumWords.length; i += 1) {
+        if (tryAssign(i, new Array<boolean>(bag.length).fill(false))) matched += 1;
+    }
+    return matched;
+};
+
+/** ファイル名の語幹 (拡張子を除いた basename)。 */
+const baseNameOf = (relative: string): string =>
+    (relative.split("/").pop() ?? relative).replace(/\.(ts|svelte|php)$/, "");
+
+export class ReverseSweepNameError extends Error {
+    constructor(where: string) {
+        super(`${where}: 宣言名から語を 1 つも取り出せません`);
+        this.name = "ReverseSweepNameError";
+    }
+}
+
+/**
+ * 語に分けた名前対応 (2b)。
+ * 候補側の語袋 = 宣言名の語 ∪ ファイル名の語。**主要語は宣言名の語列の末尾**
+ * (ファイル名の語は主要語に使わない)。列挙の語と語袋の最大マッチングが
+ * `min(2, 列挙の語数)` 以上であることを要求する。
+ */
+export const wordNameCorrespondence = (
+    candidateName: string,
+    candidateFile: string,
+    enumName: string,
+    where: string,
+): string | null => {
+    const declarationWords = splitWords(candidateName);
+    if (declarationWords.length === 0) throw new ReverseSweepNameError(where);
+
+    const bag = [...new Set([...declarationWords, ...splitWords(baseNameOf(candidateFile))])];
+    const enumWords = splitWords(enumName);
+    if (enumWords.length === 0) return null;
+
+    const candidateHead = declarationWords[declarationWords.length - 1];
+    const enumHead = enumWords[enumWords.length - 1];
+    if (!correspondWords(candidateHead, enumHead)) return null;
+
+    const shared = maxWordMatching(enumWords, bag);
+    if (shared < Math.min(2, enumWords.length)) return null;
+    return `語対応 ${shared}/${enumWords.length} 語 主要語=${enumHead}`;
+};
+
+const intersectionSizeOf = (a: ReadonlySet<string>, b: ReadonlySet<string>): number => {
+    let size = 0;
+    for (const value of a) if (b.has(value)) size += 1;
+    return size;
+};
+
+/** 1 組の突き合わせ (自己検査の対象になる純関数)。 */
+export const matchReverseRule = (php: ResolvedPhpEnum, candidate: TsUnionCandidate): ReverseRuleOutcome => {
+    const size = intersectionSizeOf(php.values, candidate.values);
+    if (size === php.values.size && size === candidate.values.size) {
+        return { kind: "match", rule: "1", reason: "完全一致" };
+    }
+    if (size === 0) return { kind: "none" };
+    if (candidate.correspondenceName === null) return { kind: "undecidable", intersectionSize: size };
+
+    const name = stripSwitchPrefix(candidate.correspondenceName);
+    const strict = strictNameCorrespondence(name, php.name);
+    if (strict !== null) return { kind: "match", rule: "2a", reason: `${strict} / 交差 ${size} 値` };
+
+    // 交差条件 (両側それぞれの要素数の半分以上。ceil 側で切り上げ)。
+    if (!(size * 2 >= php.values.size && size * 2 >= candidate.values.size)) return { kind: "none" };
+
+    const words = wordNameCorrespondence(
+        name,
+        candidate.locator.file,
+        php.name,
+        `${candidate.locator.file}::${candidate.locator.name}`,
+    );
+    if (words === null) return { kind: "none" };
+    return { kind: "match", rule: "2b", reason: `${words} / 交差 ${size} 値` };
+};
+
+const difference = (a: ReadonlySet<string>, b: ReadonlySet<string>): readonly string[] =>
+    [...a].filter((value) => !b.has(value)).sort();
+
 /**
- * 未登録のミラー候補を検出する。
+ * 未登録の関係の候補を検出する。
  *
- * @param phpEnums   母集団のうち値集合が読めた PHP 列挙 (`resolved`)。
- * @param candidates TS 側の型別名の候補。
- * @param isRegistered `(file, name)` の組が既に目録に登録済みかを判定する述語
- *                      (登録済みは検査対象から外す)。
+ * @param phpEnums     母集団のうち値集合が読めた PHP 列挙 (`resolved`)
+ * @param candidates   TS 側の候補
+ * @param isRegistered locator が既に目録へ登録済みかを判定する述語
  */
 export const findUnregisteredMirrorCandidates = (
     phpEnums: readonly ResolvedPhpEnum[],
     candidates: readonly TsUnionCandidate[],
-    isRegistered: (file: string, name: string) => boolean,
-): readonly UnregisteredMirrorCandidate[] => {
+    isRegistered: (locator: TsCandidateLocator) => boolean,
+): ReverseSweepResult => {
     const found: UnregisteredMirrorCandidate[] = [];
+    const undecidable: UndecidableMirrorPair[] = [];
 
     for (const candidate of candidates) {
-        if (isRegistered(candidate.file, candidate.name)) continue;
+        if (isRegistered(candidate.locator)) continue;
 
-        for (const phpEnum of phpEnums) {
-            if (sameValueSet(phpEnum.values, candidate.values)) {
-                found.push({ rule: 1, php: phpEnum, candidate, nameMatch: null });
+        for (const php of phpEnums) {
+            const outcome = matchReverseRule(php, candidate);
+            if (outcome.kind === "none") continue;
+            if (outcome.kind === "undecidable") {
+                undecidable.push({ php, candidate, intersectionSize: outcome.intersectionSize });
                 continue;
             }
-
-            const correspondence = nameCorrespondence(candidate.name, phpEnum.name);
-            if (correspondence === null) continue;
-            if (!intersects(phpEnum.values, candidate.values)) continue;
-
-            found.push({ rule: 2, php: phpEnum, candidate, nameMatch: correspondence });
+            found.push({
+                rule: outcome.rule,
+                php,
+                candidate,
+                reason: outcome.reason,
+                onlyInPhp: difference(php.values, candidate.values),
+                onlyInTs: difference(candidate.values, php.values),
+            });
         }
     }
 
-    return found;
+    return { found, undecidable };
+};
+
+/** 申告の同一性 (`php` + 候補の locator + `rule`)。 */
+export interface ReverseSweepExemptionKeyParts {
+    readonly php: string;
+    readonly locator: TsCandidateLocator;
+    readonly rule: ReverseSweepRule;
+}
+
+export const reverseSweepKey = (parts: ReverseSweepExemptionKeyParts): string =>
+    `${parts.php}|${locatorKey(parts.locator)}|${parts.rule}`;
+
+export interface ReverseSweepAudit<E extends ReverseSweepExemptionKeyParts> {
+    /** 申告で逃がせていない候補。 */
+    readonly unexempted: readonly UnregisteredMirrorCandidate[];
+    /** 実態と食い違った申告 (今はもう候補として鳴らない)。 */
+    readonly stale: readonly E[];
+}
+
+/**
+ * 申告の突き合わせ。**生死の判定は「免除を適用する前」の候補集合に対して行う**
+ * (免除適用後の集合で判定すると、申告が自分自身を根拠にして永久に生き続ける)。
+ */
+export const auditReverseSweepExemptions = <E extends ReverseSweepExemptionKeyParts>(
+    found: readonly UnregisteredMirrorCandidate[],
+    exemptions: readonly E[],
+): ReverseSweepAudit<E> => {
+    const exemptKeys = new Set(exemptions.map(reverseSweepKey));
+    const foundKeys = new Set(
+        found.map((hit) => reverseSweepKey({ php: hit.php.path, locator: hit.candidate.locator, rule: hit.rule })),
+    );
+
+    return {
+        unexempted: found.filter(
+            (hit) =>
+                !exemptKeys.has(
+                    reverseSweepKey({ php: hit.php.path, locator: hit.candidate.locator, rule: hit.rule }),
+                ),
+        ),
+        stale: exemptions.filter((entry) => !foundKeys.has(reverseSweepKey(entry))),
+    };
 };
diff --git a/tests/js/support/enum-ts-sync/svelte-source.ts b/tests/js/support/enum-ts-sync/svelte-source.ts
new file mode 100644
index 00000000..900ad658
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/svelte-source.ts
@@ -0,0 +1,289 @@
+/**
+ * `.svelte` を第一級の解析対象にする (正典 v3 の i6)。
+ *
+ * `svelte/compiler` の `parse` (解析ツール向けの入口) で script の範囲を取り、
+ * **script の中身以外を空白で潰した**仮想 TypeScript を **1 ファイルにつき 1 本**作る。
+ * 潰すときに **UTF-16 の符号単位の数を変えない**ので、行も列も元ファイルと一致する。
+ * 改行と認識される文字 (LF / CR / U+2028 / U+2029) はそのまま残す。
+ *
+ * 末尾に `\nexport {};\n` を足して**モジュール文脈**にする。付けないと仮想ファイルが
+ * 大域スクリプトになり、取り込みも書き出しも無いコンポーネント同士の宣言が**混ざる**
+ * (実測は `devnotes/20260824-1633-enum-ts-sync-gate-v3/probe/measurements.md`)。
+ * 必ず `\n` を前に付けるのは、元のソースが改行で終わらない / 末尾が行注釈のときに
+ * `export {};` が注釈へ吸われるのを防ぐためである。末尾へ足すので既存の行も列も動かない。
+ *
+ * **文脈ごとに別ファイルへ割らない**。割ると module の宣言を実体側から参照できなくなる
+ * (Svelte では参照できる)。代わりに、1 本へ平坦化すると再現できない 2 つを
+ * **保証外にせず不合格にする**:
+ *
+ * | 食い違い | Svelte 本来 | 平坦化した TS | 対処 |
+ * |---|---|---|---|
+ * | module から実体側の宣言を参照 | 見えない | 前方参照として解決する | 不合格 (検査 B) |
+ * | module と実体に同名の最上位束縛 | 実体側が覆う | 重複宣言になる | 不合格 (検査 A) |
+ * | 実体から module の宣言を参照 | 見える | 解決する | 正しいので許す |
+ *
+ * **不合格にするもの (fail-closed)**:
+ * - `parse` が失敗した (`.svelte` 全体の構文が壊れている)。
+ *   script の外 (目印・制御構文・スタイル) は候補にしないが、
+ *   **ファイル全体が `parse` できることは前提**である
+ * - script の属性が受理表 (`describeScriptAttributes`) の外
+ * - script の中身の範囲を取れない
+ * - module と実体に同名の最上位束縛がある (検査 A)
+ * - module 側が実体側の宣言を参照している (検査 B。program 構築の一本道で必ず走る)
+ *
+ * **検査 B の呼び出し義務は利用側に無い** — `createMirrorPrograms()` と
+ * `createFixtureProgram()` が program を組んだ直後に全仮想単位へ必ず走らせる
+ * (低層の組み立て関数は輸出しないので、検査を飛ばした program を外から作れない)。
+ *
+ * **保証しないもの**: 目印の中の式 (`{…}`)、`{#if}` などの制御構文の中、
+ * スタイルの中は候補にしない。`lang="js"` は TS として読む (過剰検出の向き)。
+ */
+import ts from "typescript";
+import path from "node:path";
+import { parse } from "svelte/compiler";
+import { EnumTsSyncError } from "./errors";
+import { REPO_ROOT } from "./repo-root";
+
+/**
+ * 仮想ファイルの綴り。素朴な `.ts` の付加は採らない
+ * (`*.svelte.ts` が実在し得るため実在ファイルと衝突する)。
+ */
+export const VIRTUAL_SUFFIX = ".__enum_ts_sync_virtual__.ts";
+
+export interface SvelteVirtualUnit {
+    /** 元の `.svelte` のリポジトリ相対パス。 */
+    readonly source: string;
+    /** program に載せる仮想の絶対パス。 */
+    readonly virtualPath: string;
+    /** 行・列を保った仮想 TS。 */
+    readonly text: string;
+    readonly moduleRange: readonly [number, number] | null;
+    readonly instanceRange: readonly [number, number] | null;
+}
+
+/** 改行と認識される文字 (潰さずに残す)。 */
+const LINE_TERMINATORS = new Set(["\n", "\r", "\u2028", "\u2029"]);
+
+interface ParsedScript {
+    readonly attributes?: readonly { readonly name: string; readonly value: unknown }[];
+    readonly content?: { readonly start: number; readonly end: number };
+}
+
+interface ParsedSvelte {
+    readonly module?: ParsedScript;
+    readonly instance?: ParsedScript;
+}
+
+/** 受理する `lang` の値 (`js` も TS として読む = 過剰検出の向き)。 */
+const ACCEPTED_LANGS = new Set(["ts", "js"]);
+
+const attributeText = (value: unknown): string | true | null => {
+    if (value === true) return true;
+    if (!Array.isArray(value) || value.length !== 1) return null;
+    const first: unknown = value[0];
+    if (typeof first !== "object" || first === null) return null;
+    const data = (first as { readonly data?: unknown }).data;
+    return typeof data === "string" ? data : null;
+};
+
+/**
+ * script の属性の受理表。受理するのは `lang="ts"` / `lang="js"` / 属性なし /
+ * module 文脈での値なし `module` だけである。
+ */
+const assertAcceptedAttributes = (where: string, context: "module" | "instance", script: ParsedScript): void => {
+    for (const attribute of script.attributes ?? []) {
+        const value = attributeText(attribute.value);
+        if (attribute.name === "lang") {
+            if (typeof value !== "string" || !ACCEPTED_LANGS.has(value)) {
+                throw new EnumTsSyncError(where, `受理しない script の lang です: ${String(value)}`);
+            }
+            continue;
+        }
+        if (attribute.name === "module") {
+            if (context !== "module") throw new EnumTsSyncError(where, "実体の script に module 属性は受理しません");
+            if (value !== true) throw new EnumTsSyncError(where, "値つきの module 属性は受理しません");
+            continue;
+        }
+        throw new EnumTsSyncError(where, `受理しない script 属性です: ${attribute.name}`);
+    }
+};
+
+/** 仮想パス → 元の `.svelte` のリポジトリ相対パス。仮想でなければ `undefined`。 */
+export const realPathOfVirtual = (virtualPath: string): string | undefined => {
+    if (!virtualPath.endsWith(VIRTUAL_SUFFIX)) return undefined;
+    const absolute = virtualPath.slice(0, -VIRTUAL_SUFFIX.length);
+    return path.relative(REPO_ROOT, absolute).split(path.sep).join("/");
+};
+
+/** 最上位の束縛名を集める (束縛を作る構文を網羅する)。 */
+export const topLevelBindingNames = (statements: readonly ts.Statement[]): ReadonlySet<string> => {
+    const names = new Set<string>();
+
+    const addBindingName = (name: ts.BindingName): void => {
+        if (ts.isIdentifier(name)) {
+            names.add(name.text);
+            return;
+        }
+        for (const element of name.elements) {
+            if (ts.isBindingElement(element)) addBindingName(element.name);
+        }
+    };
+
+    for (const statement of statements) {
+        if (ts.isVariableStatement(statement)) {
+            for (const declaration of statement.declarationList.declarations) addBindingName(declaration.name);
+            continue;
+        }
+        if (
+            ts.isFunctionDeclaration(statement)
+            || ts.isClassDeclaration(statement)
+            || ts.isEnumDeclaration(statement)
+            || ts.isInterfaceDeclaration(statement)
+            || ts.isTypeAliasDeclaration(statement)
+            || ts.isModuleDeclaration(statement)
+        ) {
+            const name = statement.name;
+            if (name !== undefined && ts.isIdentifier(name)) names.add(name.text);
+            continue;
+        }
+        if (ts.isImportEqualsDeclaration(statement)) {
+            names.add(statement.name.text);
+            continue;
+        }
+        if (ts.isImportDeclaration(statement)) {
+            const clause = statement.importClause;
+            if (clause === undefined) continue;
+            if (clause.name !== undefined) names.add(clause.name.text);
+            const bindings = clause.namedBindings;
+            if (bindings === undefined) continue;
+            if (ts.isNamespaceImport(bindings)) names.add(bindings.name.text);
+            else for (const element of bindings.elements) names.add(element.name.text);
+        }
+    }
+
+    return names;
+};
+
+const withinRange = (position: number, range: readonly [number, number] | null): boolean =>
+    range !== null && position >= range[0] && position < range[1];
+
+/**
+ * `.svelte` の中身を仮想 TS 単位へ変換する**純関数**。
+ *
+ * @param relativePath 元の `.svelte` のリポジトリ相対パス
+ * @param source       元の `.svelte` の中身
+ */
+export const toVirtualUnit = (relativePath: string, source: string): SvelteVirtualUnit => {
+    const where = relativePath;
+
+    let root: ParsedSvelte;
+    try {
+        root = parse(source, { modern: true }) as unknown as ParsedSvelte;
+    } catch (error) {
+        throw new EnumTsSyncError(where, `.svelte の構文を読めません: ${error instanceof Error ? error.message : String(error)}`);
+    }
+
+    const ranges: { readonly context: "module" | "instance"; readonly range: readonly [number, number] }[] = [];
+    for (const context of ["module", "instance"] as const) {
+        const script = root[context];
+        if (script === undefined) continue;
+        assertAcceptedAttributes(where, context, script);
+        if (script.content === undefined) throw new EnumTsSyncError(where, `${context} の script の中身の範囲を取れません`);
+        ranges.push({ context, range: [script.content.start, script.content.end] });
+    }
+
+    const keep = new Uint8Array(source.length);
+    for (const { range } of ranges) {
+        for (let index = range[0]; index < range[1]; index += 1) keep[index] = 1;
+    }
+
+    let blanked = "";
+    for (let index = 0; index < source.length; index += 1) {
+        const character = source[index];
+        blanked += keep[index] === 1 || LINE_TERMINATORS.has(character) ? character : " ";
+    }
+    // モジュール文脈にする (末尾へ足すので既存の行も列も動かない)。
+    const text = `${blanked}\nexport {};\n`;
+
+    const moduleRange = ranges.find((r) => r.context === "module")?.range ?? null;
+    const instanceRange = ranges.find((r) => r.context === "instance")?.range ?? null;
+
+    // 検査 A: module と実体に同名の最上位束縛があると shadowing を再現できない。
+    const virtualPath = path.join(REPO_ROOT, relativePath) + VIRTUAL_SUFFIX;
+    const parsed = ts.createSourceFile(virtualPath, text, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
+    const moduleStatements: ts.Statement[] = [];
+    const instanceStatements: ts.Statement[] = [];
+    for (const statement of parsed.statements) {
+        const start = statement.getStart(parsed);
+        if (withinRange(start, moduleRange)) moduleStatements.push(statement);
+        else if (withinRange(start, instanceRange)) instanceStatements.push(statement);
+    }
+    const moduleNames = topLevelBindingNames(moduleStatements);
+    const shared = [...topLevelBindingNames(instanceStatements)].filter((name) => moduleNames.has(name)).sort();
+    if (shared.length > 0) {
+        throw new EnumTsSyncError(
+            where,
+            `module と実体に同名の最上位束縛があります (平坦化では shadowing を再現できません): ${shared.join(", ")}`,
+        );
+    }
+
+    return { source: relativePath, virtualPath, text, moduleRange, instanceRange };
+};
+
+/**
+ * 検査 B: module 範囲の中の識別子が実体範囲の宣言を指していないこと。
+ * **`createMirrorPrograms()` / `createFixtureProgram()` が内部で必ず実行する**。
+ */
+export const assertNoModuleToInstanceReference = (
+    checker: ts.TypeChecker,
+    file: ts.SourceFile,
+    unit: SvelteVirtualUnit,
+): void => {
+    const { moduleRange, instanceRange } = unit;
+    if (moduleRange === null || instanceRange === null) return;
+
+    const declaredInInstance = (symbol: ts.Symbol | undefined): boolean =>
+        (symbol?.declarations ?? []).some(
+            (declaration) =>
+                declaration.getSourceFile() === file && withinRange(declaration.getStart(file), instanceRange),
+        );
+
+    const visit = (node: ts.Node): void => {
+        if (ts.isIdentifier(node)) {
+            const symbol = checker.getSymbolAtLocation(node);
+            const aliased =
+                symbol !== undefined && (symbol.flags & ts.SymbolFlags.Alias) !== 0
+                    ? checker.getAliasedSymbol(symbol)
+                    : undefined;
+            if (declaredInInstance(symbol) || declaredInInstance(aliased)) {
+                throw new EnumTsSyncError(
+                    unit.source,
+                    `module の script が実体側の宣言 (${node.text}) を参照しています (Svelte では見えないので平坦化を認めません)`,
+                );
+            }
+        }
+        ts.forEachChild(node, visit);
+    };
+
+    for (const statement of file.statements) {
+        if (withinRange(statement.getStart(file), moduleRange)) visit(statement);
+    }
+};
+
+/**
+ * 仮想パスの綴りが**版管理下に実在しない**ことを検査する。
+ * 実在すると仮想単位が本物のファイルを覆い隠す (または逆に覆われる)。
+ * `*.svelte.ts` が実在し得るので素朴な `.ts` の付加を採らないのはこのためである。
+ */
+export const assertNoVirtualPathCollision = (
+    units: readonly SvelteVirtualUnit[],
+    trackedFiles: readonly string[],
+): void => {
+    const tracked = new Set(trackedFiles);
+    for (const unit of units) {
+        const relative = `${unit.source}${VIRTUAL_SUFFIX}`;
+        if (tracked.has(relative)) {
+            throw new EnumTsSyncError(unit.source, `仮想パスの綴りが版管理下のファイルと衝突しています: ${relative}`);
+        }
+    }
+};
diff --git a/tests/js/support/enum-ts-sync/ts-candidates.ts b/tests/js/support/enum-ts-sync/ts-candidates.ts
index 0103f0ea..e171d41f 100644
--- a/tests/js/support/enum-ts-sync/ts-candidates.ts
+++ b/tests/js/support/enum-ts-sync/ts-candidates.ts
@@ -1,96 +1,430 @@
 /**
- * `resources/js/` 配下にある**文字列リテラル型だけの union に解決する型別名**を
- * 全数走査する (裁定 AG-099 後半 / 逆走査の入力)。
+ * 逆走査の候補走査 (正典 v3 の i7 / i9)。
  *
- * `readTsUnionValues` (`ts-value-sets.ts`) は「目録に登録した 1 つの宣言」を読む検査で、
- * 受理できない形は例外にして呼び出し側の登録ミスを知らせる。本モジュールは向きが逆で、
- * **プログラム全体から候補を拾う**。**型別名 1 つずつの受理・拒否は黙って読み飛ばす**
- * (「型別名だが対象にならない」は前者では失敗、後者では単に非対象という違いである) が、
- * **ファイル単位の構文診断は無言で読み飛ばさない**。構文が壊れたファイルは中の型別名が
- * 正しく読めているか判別できないため、その 1 点だけは例外にして gate を失敗させる
- * (AGENTS.md §静的検査の共通規約 (b) fail-closed)。
+ * **母集団**は `population.ts` が決める版管理下の `*.ts` / `*.svelte` の全数で、
+ * 走査は**所有者の program 上の `SourceFile`** だけを使う
+ * (`program.getSourceFiles()` 全体は依存ライブラリ・推移的な取り込み・JSON が載るので
+ * 母集団の一致根拠にしない)。
  *
- * **母集団の実体**: `resources/js/` 配下の走査対象は `program.getSourceFiles()` から
- * `.ts` の**通常ファイルだけ**を取る (`source.isDeclarationFile` で `.d.ts` を除く)。
- * `program` は `createMirrorProgram()` が `tsconfig.json` の `include`/`exclude` から組むが、
- * **それだけを母集団の出典とは言わない** — `resources/js/` をプログラムを介さず
- * 直接再帰的に歩いた `*.ts` (`.d.ts` を除く) の集合と、program に載った集合が
- * **完全一致すること**を独立実装の回帰テストで固定しており、この一致こそが
- * 「呼び出し時に渡す `tsFiles` 引数に依存しない・`exclude` が意図せず広がっていない」
- * という不変条件の実体である (`enum-ts-sync-discovery-extractor.test.ts` の
- * 「走査した非宣言ファイルの集合は、ファイルシステムを直接歩いた集合と一致する」テスト)。
+ * **受理する 4 形 (i9)**:
  *
- * **保証しないもの**: 対象は `resources/js/` 配下の `.ts` ファイルのトップレベルにある
- * `type X = …` 宣言だけ。`.svelte` の中の宣言・定数配列・switch の case ラベル・
- * ネストした (トップレベルでない) 型別名は対象外。**`.d.ts` (宣言ファイル) も対象外**
- * (`vite-env.d.ts` 以外に手書きの `.d.ts` が増えても、その中の literal union は読まない)。
+ * | 形 | 受理条件 | 値集合 |
+ * |---|---|---|
+ * | `literal-union` | 型別名の宣言 (**入れ子も含む**)。解決した型が文字列リテラル型だけ | リテラルの値 |
+ * | `const-array` | `const` 束縛の変数宣言で、包みを剥がした初期化子が配列リテラル。要素がすべて文字列リテラル | 要素の値 |
+ * | `object-keys` | 変数宣言で、包みを剥がした初期化子がオブジェクトリテラル。キーが読める | キーの綴り |
+ * | `switch-cases` | `switch` 文で `default` を除く case がすべて文字列リテラル型へ解決 | case の値 |
+ *
+ * `object-keys` に `const` を要求しないのは、正典が「オブジェクト (対応表) のキー」としか
+ * 言わないためである (`let` の対応表も写しになり得る)。`const-array` にだけ要求するのは
+ * 正典の「**定数の**配列」という言い方に合わせたもので、この非対称は意図している。
+ *
+ * **三値にする (共通規約 (b))**: 「候補かどうかを決められない」を非候補と混ぜない。
+ * 判定保留 (`indeterminate`) は候補にも非候補にもせず、利用側の gate が既定拒否の
+ * 申告で受ける。
+ *
+ * **採番 → 分類の順序**: 候補の同一性は `(file, shape, name, occurrence)` の 4 つ組
+ * (locator) で持つ。`occurrence` は**三値の分類より前に**、構文上の宣言の場所の全体に
+ * 対して振る (候補だけを採番すると、同名で片方が判定保留・片方が候補のときに
+ * どちらも 0 になり、非候補を外すと分類が変わっただけで番号が動く)。
+ * **採番器 (`buildScanIndex`) は 1 本だけ持ち、逆走査と前向きの解決が共有する**。
+ * 行は同一性に入れない (無関係な行移動で申告が一斉に stale になるのを避ける。
+ * **行はメッセージにだけ使う**)。
+ *
+ * **派生の除外**: `object-keys` 形のうち、明示の型があり・文字列の添字シグネチャが無く・
+ * プロパティが 1 件以上ですべて必須で・書かれたキーが必須プロパティと集合として一致し・
+ * **`object-keys` 以外の形の候補に同じ値集合の証人がある**ものだけを外す。
+ * 証人の資格を派生除外の対象になり得ない形に限るのは**循環の遮断**である
+ * (任意の候補を証人にすると、同じキー集合の対応表 A と B が互いを証人にして両方消える)。
+ *
+ * **保証しないもの**: 版管理外のファイルは見ない。`.d.ts` は候補にしない。
+ * `.svelte` は script の中だけを見る (目印の中・制御構文の中・スタイルは見ない)。
+ * 分割代入の束縛には locator を作らない (4 形はどれも名前付きの 1 つの宣言を前提にする)。
  */
 import ts from "typescript";
-import path from "node:path";
 import { EnumTsSyncError } from "./errors";
-import { REPO_ROOT, type MirrorProgram } from "./program";
+import type { MirrorPrograms } from "./program";
+import {
+    isIndeterminateType,
+    readConstArrayLiteralValues,
+    readObjectLiteralKeys,
+    readResolvedStringLiteralUnion,
+    readSwitchCaseValues,
+    unwrapInitializer,
+} from "./ts-literal-values";
 
-export interface TsUnionCandidate {
-    /** リポジトリルートからの相対パス。 */
+export type TsCandidateShape = "literal-union" | "const-array" | "object-keys" | "switch-cases";
+
+export interface TsCandidateLocator {
+    /** リポジトリルートからの相対パス (`.svelte` は仮想ではなく元のパス)。 */
     readonly file: string;
-    /** 型別名の名前。 */
+    readonly shape: TsCandidateShape;
+    /** 宣言の名前。分岐のラベルは `switch:<判定対象の字面>`。 */
     readonly name: string;
+    /** 同じ (file, shape, name) の中の出現順 (0 始まり)。 */
+    readonly occurrence: number;
+}
+
+export interface TsUnionCandidate {
+    readonly locator: TsCandidateLocator;
+    /** 元ファイル上の行 (1 始まり)。**同一性には使わない** (メッセージ用)。 */
+    readonly line: number;
+    /** 最上位の宣言か (前向きの目録が指せるのは最上位だけ)。 */
+    readonly topLevel: boolean;
     readonly values: ReadonlySet<string>;
+    /** 規則 2 の名前対応に使える名前。決められなければ `null`。 */
+    readonly correspondenceName: string | null;
+    /** `correspondenceName !== null`。 */
+    readonly nameResolved: boolean;
+}
+
+/** 候補かどうかを決められなかった宣言 (判定保留)。**同一性は候補と同じ locator**。 */
+export interface IndeterminateTsDeclaration {
+    readonly locator: TsCandidateLocator;
+    readonly line: number;
+    readonly reason: string;
+}
+
+export interface TsCandidateScan {
+    readonly candidates: readonly TsUnionCandidate[];
+    readonly indeterminate: readonly IndeterminateTsDeclaration[];
+    /** 実際に走査したファイル (リポジトリ相対)。空振り検査に使う。 */
+    readonly scannedFiles: ReadonlySet<string>;
+}
+
+/** locator の綴り (集合の鍵・メッセージ用)。 */
+export const locatorKey = (locator: TsCandidateLocator): string =>
+    `${locator.file}|${locator.shape}|${locator.name}|${locator.occurrence}`;
+
+/** 値集合の鍵 (証人の索引に使う)。 */
+export const valueSetKey = (set: ReadonlySet<string>): string => [...set].sort().join(" ");
+
+export interface SwitchSubject {
+    /** locator 専用。構文が正常なら必ず得られる。 */
+    readonly siteName: string;
+    /** 規則 2 の名前対応に使える場合だけ値を持つ。 */
+    readonly correspondenceName: string | null;
 }
 
-/** `root` の配下にあるか (区切り文字まで含めて見る。兄弟ディレクトリを通さない)。 */
-const isUnder = (absolute: string, root: string): boolean => absolute === root || absolute.startsWith(root + path.sep);
+/** 名前対応に使ってよい式の形 (識別子 / `this` / それらのプロパティ参照の連なり)。 */
+const isNameableExpression = (expression: ts.Expression): boolean =>
+    ts.isIdentifier(expression)
+    || expression.kind === ts.SyntaxKind.ThisKeyword
+    || (ts.isPropertyAccessExpression(expression) && isNameableExpression(expression.expression));
 
-/** 解決した型が文字列リテラル型だけの union (または単独) なら値集合を返す。それ以外は `undefined`。 */
-const tryReadStringLiteralUnion = (checker: ts.TypeChecker, alias: ts.TypeAliasDeclaration): ReadonlySet<string> | undefined => {
-    const symbol = checker.getSymbolAtLocation(alias.name);
-    if (symbol === undefined) return undefined;
+/**
+ * 分岐の判定対象の名前。**locator 用の構文名と規則 2 用の解決名を分ける**。
+ * locator の名前は必須なので、名前対応に使えない式でも `siteName` は必ず作る。
+ */
+export const switchSubject = (
+    checker: ts.TypeChecker,
+    expression: ts.Expression,
+    source: ts.SourceFile,
+    where: string,
+): SwitchSubject => {
+    const siteName = `switch:${expression.getText(source).replace(/\s+/g, " ").trim()}`;
+    if (siteName === "switch:") throw new EnumTsSyncError(where, "分岐の判定対象の字面が空です");
 
-    const declared = checker.getDeclaredTypeOfSymbol(symbol);
-    const parts = declared.isUnion() ? declared.types : [declared];
+    const type = checker.getTypeAtLocation(expression);
+    const alias =
+        type.aliasSymbol?.name
+        ?? (type.isUnion()
+            ? type.types.map((part) => part.aliasSymbol?.name).find((name) => name !== undefined)
+            : undefined);
+    if (alias !== undefined) return { siteName, correspondenceName: alias };
 
-    const values = new Set<string>();
-    for (const part of parts) {
-        if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) return undefined;
-        if (!part.isStringLiteral()) return undefined;
-        values.add(part.value);
+    if (isNameableExpression(expression)) return { siteName, correspondenceName: expression.getText(source) };
+
+    return { siteName, correspondenceName: null };
+};
+
+/** 自己検査用の薄い入口 (名前対応に使える名前だけを返す)。 */
+export const switchSubjectName = (
+    checker: ts.TypeChecker,
+    expression: ts.Expression,
+    source: ts.SourceFile,
+): string | null => switchSubject(checker, expression, source, "switch").correspondenceName;
+
+export interface DeclarationSite {
+    readonly node: ts.Node;
+    readonly shape: TsCandidateShape;
+    readonly name: string;
+    readonly line: number;
+    readonly topLevel: boolean;
+    readonly correspondenceName: string | null;
+}
+
+export interface ScanIndex {
+    readonly file: string;
+    readonly sites: readonly DeclarationSite[];
+    /** その場所の locator。採番は三値の分類より前に済んでいる。 */
+    locatorOf(node: ts.Node): TsCandidateLocator;
+}
+
+const lineOf = (source: ts.SourceFile, node: ts.Node): number =>
+    source.getLineAndCharacterOfPosition(node.getStart(source)).line + 1;
+
+/** 変数宣言の形 (包みを剥がした初期化子で決まる)。候補にならない形は `undefined`。 */
+const variableShape = (declaration: ts.VariableDeclaration): TsCandidateShape | undefined => {
+    if (declaration.initializer === undefined) return undefined;
+    const { expression } = unwrapInitializer(declaration.initializer);
+    if (ts.isArrayLiteralExpression(expression)) return "const-array";
+    if (ts.isObjectLiteralExpression(expression)) return "object-keys";
+    return undefined;
+};
+
+/**
+ * 1 ファイル分の宣言の場所を数え上げ、`(file, shape, name)` ごとに
+ * **ソース位置の順**で `occurrence` を振る。**三値の判定はまだしない**。
+ */
+export const buildScanIndex = (source: ts.SourceFile, checker: ts.TypeChecker, file: string): ScanIndex => {
+    const sites: DeclarationSite[] = [];
+
+    const visit = (node: ts.Node): void => {
+        if (ts.isTypeAliasDeclaration(node)) {
+            sites.push({
+                node,
+                shape: "literal-union",
+                name: node.name.text,
+                line: lineOf(source, node),
+                topLevel: node.parent === source,
+                correspondenceName: node.name.text,
+            });
+        } else if (ts.isVariableDeclaration(node) && ts.isIdentifier(node.name)) {
+            const shape = variableShape(node);
+            if (shape !== undefined) {
+                sites.push({
+                    node,
+                    shape,
+                    name: node.name.text,
+                    line: lineOf(source, node),
+                    topLevel: node.parent.parent.parent === source,
+                    correspondenceName: node.name.text,
+                });
+            }
+        } else if (ts.isSwitchStatement(node)) {
+            const subject = switchSubject(checker, node.expression, source, file);
+            sites.push({
+                node,
+                shape: "switch-cases",
+                name: subject.siteName,
+                line: lineOf(source, node),
+                topLevel: node.parent === source,
+                correspondenceName: subject.correspondenceName,
+            });
+        }
+        ts.forEachChild(node, visit);
+    };
+    visit(source);
+
+    sites.sort((a, b) => a.node.getStart(source) - b.node.getStart(source));
+
+    const counters = new Map<string, number>();
+    const locators = new Map<ts.Node, TsCandidateLocator>();
+    for (const site of sites) {
+        const key = `${site.shape}|${site.name}`;
+        const occurrence = counters.get(key) ?? 0;
+        counters.set(key, occurrence + 1);
+        locators.set(site.node, { file, shape: site.shape, name: site.name, occurrence });
     }
-    if (values.size === 0) return undefined;
-    return values;
+
+    return {
+        file,
+        sites,
+        locatorOf: (node) => {
+            const locator = locators.get(node);
+            if (locator === undefined) {
+                throw new EnumTsSyncError(file, "採番していない宣言の locator を求めました (採番器の母集団から漏れています)");
+            }
+            return locator;
+        },
+    };
 };
 
+/** 派生の除外に使う「事実」(述語ではなくデータを渡す = 自己検査できる形)。 */
+export interface DerivedFacts {
+    /** 明示の型 (型注釈 または `satisfies`) があるか。 */
+    readonly hasExplicitType: boolean;
+    /** その型を解決できたか。 */
+    readonly explicitTypeResolved: boolean;
+    /** 文字列の添字シグネチャを持つか。 */
+    readonly hasStringIndexSignature: boolean;
+    /** 任意プロパティを 1 つでも持つか。 */
+    readonly hasOptionalProperty: boolean;
+    /** 必須プロパティの名前。 */
+    readonly requiredKeys: readonly string[];
+    /** 実際に書かれたキー。 */
+    readonly writtenKeys: readonly string[];
+    /** `object-keys` 以外の形に同じ値集合の候補があるか。 */
+    readonly witnessed: boolean;
+}
+
+const sameSet = (a: readonly string[], b: readonly string[]): boolean =>
+    valueSetKey(new Set(a)) === valueSetKey(new Set(b));
+
 /**
- * `resources/js/` 配下の全 `.ts` ファイルから、文字列リテラル型だけの union に解決する
- * トップレベルの型別名をすべて拾う。
- *
- * @param jsRoot 走査根 (既定は `resources/js`。負のコントロール専用の引数)
+ * 対応表のキーを「派生」として候補から外してよいか。
+ * **1 つでも欠けたら候補として残す** (fail-closed)。
  */
-export const collectTsUnionCandidates = (
-    { program, checker }: MirrorProgram,
-    jsRoot: string = path.join(REPO_ROOT, "resources", "js"),
-): readonly TsUnionCandidate[] => {
+export const isDerivedObjectKeys = (facts: DerivedFacts): boolean =>
+    facts.hasExplicitType
+    && facts.explicitTypeResolved
+    && !facts.hasStringIndexSignature
+    && !facts.hasOptionalProperty
+    && facts.requiredKeys.length > 0
+    && sameSet(facts.writtenKeys, facts.requiredKeys)
+    && facts.witnessed;
+
+/** 証人の索引 (**`object-keys` 以外の形だけ**が証人になれる = 循環の遮断)。 */
+export const buildWitnessIndex = (candidates: readonly TsUnionCandidate[]): ReadonlySet<string> =>
+    new Set(
+        candidates
+            .filter((candidate) => candidate.locator.shape !== "object-keys")
+            .map((candidate) => valueSetKey(candidate.values)),
+    );
+
+interface PendingDerived {
+    readonly locator: TsCandidateLocator;
+    readonly line: number;
+    readonly topLevel: boolean;
+    readonly values: ReadonlySet<string>;
+    readonly facts: Omit<DerivedFacts, "witnessed">;
+}
+
+/** 明示の型から派生判定の事実を集める。 */
+const derivedFactsOf = (
+    checker: ts.TypeChecker,
+    declaration: ts.VariableDeclaration,
+    initializer: ts.Expression,
+    writtenKeys: readonly string[],
+): Omit<DerivedFacts, "witnessed"> => {
+    const { satisfiesType } = unwrapInitializer(initializer);
+    const typeNode = declaration.type ?? satisfiesType;
+    const empty = {
+        hasStringIndexSignature: false,
+        hasOptionalProperty: false,
+        requiredKeys: [] as readonly string[],
+        writtenKeys,
+    };
+    if (typeNode === undefined) {
+        return { hasExplicitType: false, explicitTypeResolved: false, ...empty };
+    }
+    const bound = checker.getTypeFromTypeNode(typeNode);
+    if (isIndeterminateType(bound, typeNode)) {
+        return { hasExplicitType: true, explicitTypeResolved: false, ...empty };
+    }
+    const properties = checker.getPropertiesOfType(bound);
+    return {
+        hasExplicitType: true,
+        explicitTypeResolved: true,
+        hasStringIndexSignature: checker.getIndexInfoOfType(bound, ts.IndexKind.String) !== undefined,
+        hasOptionalProperty: properties.some((symbol) => (symbol.flags & ts.SymbolFlags.Optional) !== 0),
+        requiredKeys: properties
+            .filter((symbol) => (symbol.flags & ts.SymbolFlags.Optional) === 0)
+            .map((symbol) => symbol.name),
+        writtenKeys,
+    };
+};
+
+/** 1 つの宣言の場所を三値へ分類する。 */
+const classify = (
+    checker: ts.TypeChecker,
+    site: DeclarationSite,
+): ReturnType<typeof readConstArrayLiteralValues> => {
+    if (site.shape === "literal-union") {
+        return readResolvedStringLiteralUnion(checker, site.node as ts.TypeAliasDeclaration);
+    }
+    if (site.shape === "const-array") {
+        return readConstArrayLiteralValues(site.node as ts.VariableDeclaration);
+    }
+    if (site.shape === "switch-cases") {
+        return readSwitchCaseValues(checker, site.node as ts.SwitchStatement);
+    }
+    const declaration = site.node as ts.VariableDeclaration;
+    const initializer = declaration.initializer;
+    if (initializer === undefined) return { kind: "not-a-catalogue" };
+    const { expression } = unwrapInitializer(initializer);
+    if (!ts.isObjectLiteralExpression(expression)) return { kind: "not-a-catalogue" };
+    return readObjectLiteralKeys(checker, expression);
+};
+
+/**
+ * 母集団の全ファイルから候補を拾う。**本番の入口**であり、
+ * 戦略の差し替え口は持たない (自己検査は輸出した純関数へデータを渡して行う)。
+ */
+export const collectTsCandidates = (programs: MirrorPrograms): TsCandidateScan => {
     const candidates: TsUnionCandidate[] = [];
+    const indeterminate: IndeterminateTsDeclaration[] = [];
+    const pending: PendingDerived[] = [];
+    const scannedFiles = new Set<string>();
 
-    for (const source of program.getSourceFiles()) {
-        if (source.isDeclarationFile) continue;
-        if (!isUnder(source.fileName, jsRoot)) continue;
+    const population = [...programs.population.ts, ...programs.population.svelte];
+    for (const file of population) {
+        const mirror = programs.programOf(file);
+        const source = programs.sourceOf(file);
+        scannedFiles.add(file);
 
-        const where = path.relative(REPO_ROOT, source.fileName).split(path.sep).join("/");
-        if (program.getSyntacticDiagnostics(source).length > 0) {
-            throw new EnumTsSyncError(where, "構文が壊れているため候補を読めません (無言で読み飛ばさない)");
+        if (mirror.program.getSyntacticDiagnostics(source).length > 0) {
+            throw new EnumTsSyncError(file, "構文が壊れているため候補を読めません (無言で読み飛ばさない)");
         }
 
-        for (const statement of source.statements) {
-            if (!ts.isTypeAliasDeclaration(statement)) continue;
-            const values = tryReadStringLiteralUnion(checker, statement);
-            if (values === undefined) continue;
+        const index = buildScanIndex(source, mirror.checker, file);
+        for (const site of index.sites) {
+            const locator = index.locatorOf(site.node);
+            const result = classify(mirror.checker, site);
+
+            if (result.kind === "not-a-catalogue") continue;
+            if (result.kind === "indeterminate") {
+                indeterminate.push({ locator, line: site.line, reason: result.reason });
+                continue;
+            }
+
+            if (site.shape === "object-keys") {
+                const declaration = site.node as ts.VariableDeclaration;
+                const facts = derivedFactsOf(
+                    mirror.checker,
+                    declaration,
+                    declaration.initializer as ts.Expression,
+                    [...result.values],
+                );
+                if (
+                    facts.hasExplicitType
+                    && facts.explicitTypeResolved
+                    && !facts.hasStringIndexSignature
+                    && !facts.hasOptionalProperty
+                    && facts.requiredKeys.length > 0
+                    && sameSet([...result.values], facts.requiredKeys)
+                ) {
+                    pending.push({ locator, line: site.line, topLevel: site.topLevel, values: result.values, facts });
+                    continue;
+                }
+            }
+
             candidates.push({
-                file: where,
-                name: statement.name.text,
-                values,
+                locator,
+                line: site.line,
+                topLevel: site.topLevel,
+                values: result.values,
+                correspondenceName: site.correspondenceName,
+                nameResolved: site.correspondenceName !== null,
             });
         }
     }
 
-    return candidates;
+    // 第 2 パス: 証人のある派生だけを捨て、無いものは候補へ戻す。
+    const witnessIndex = buildWitnessIndex(candidates);
+    for (const row of pending) {
+        const facts: DerivedFacts = { ...row.facts, witnessed: witnessIndex.has(valueSetKey(row.values)) };
+        if (isDerivedObjectKeys(facts)) continue;
+        candidates.push({
+            locator: row.locator,
+            line: row.line,
+            topLevel: row.topLevel,
+            values: row.values,
+            correspondenceName: row.locator.name,
+            nameResolved: true,
+        });
+    }
+
+    return { candidates, indeterminate, scannedFiles };
 };
diff --git a/tests/js/support/enum-ts-sync/ts-literal-values.ts b/tests/js/support/enum-ts-sync/ts-literal-values.ts
new file mode 100644
index 00000000..b667a20c
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/ts-literal-values.ts
@@ -0,0 +1,194 @@
+/**
+ * 値集合の読み取りの最下層。**逆走査と前向きの検査が共有する唯一の抽出器**である
+ * (正典 v3 の i4「抽出器を 2 本持たない」)。
+ *
+ * - `unwrapInitializer` … 丸括弧 / `as` / `satisfies` の包みを剥がし、
+ *   値の構文と**明示の型ノード**を別々に返す
+ * - `readConstArrayLiteralValues` … `const` 束縛の配列リテラルから値を**構文で**読む
+ *   (型検査器の配列型は使わない。素の配列は `string[]` に広げられるため)
+ * - `readResolvedStringLiteralUnion` … 型を**型検査器で**解決し、
+ *   文字列リテラル型だけの合併なら値集合を返す
+ * - `readObjectLiteralKeys` … オブジェクトリテラルのキーを読む
+ *   (文字列リテラル / 識別子 / 型検査器が文字列リテラルへ解決する計算キー)
+ * - `readSwitchCaseValues` … `default` を除く `case` の式の値を読む
+ *
+ * どれも**「1 つでも読めない要素があれば読めなかったことにする」**。
+ * 「読めない」には 2 種類あり、**形ごとに境界が違う**:
+ *
+ * | 形 | 受理に型解決が要るか | `not-a-catalogue` | `indeterminate` |
+ * |---|---|---|---|
+ * | `const-array` | 要らない (構文だけ) | `const` でない / 空配列 / 要素に構文上の文字列リテラル以外がある | **無い** |
+ * | `object-keys` | 計算キーだけ要る | 通常の代入でないプロパティ / 計算キーが文字列リテラル型以外へ正常に解決 / 空 | 計算キーの型が `any` / `unknown` |
+ * | `literal-union` | 要る | 文字列リテラル型でない構成要素 / `EnumLiteral` を含む | 解決した型が `any` / `unknown` |
+ * | `switch-cases` | 要る | `case` の式が文字列リテラル型以外へ正常に解決 / `case` が 0 件 | `case` の式の型が `any` / `unknown` |
+ *
+ * **定数の配列は構文だけで判定する**ので、識別子や呼び出し式が混ざったら
+ * 型解決の成否によらず `not-a-catalogue` である (保留にしない)。
+ *
+ * `ts.TypeFlags.EnumLiteral` は 4 形すべてで拒否する (本リポジトリに TypeScript の
+ * `enum` は 1 件も無く、文字列リテラル型と同じ契約ではない)。
+ */
+import ts from "typescript";
+
+export type LiteralValuesResult =
+    /** 値集合を読めた。 */
+    | { readonly kind: "values"; readonly values: ReadonlySet<string> }
+    /** 正常に非候補 (読めたうえで対象ではない)。 */
+    | { readonly kind: "not-a-catalogue" }
+    /** 候補かどうかを決められない (判定保留)。 */
+    | { readonly kind: "indeterminate"; readonly reason: string };
+
+const NOT_A_CATALOGUE: LiteralValuesResult = { kind: "not-a-catalogue" };
+
+const values = (set: ReadonlySet<string>): LiteralValuesResult =>
+    set.size === 0 ? NOT_A_CATALOGUE : { kind: "values", values: set };
+
+/**
+ * 「解決できなかった」だけでなく「明示的に `any` へ正しく解決した」場合も含めて
+ * **候補かどうかを確定できない**ことを表す。両者を機械で見分けるには TypeScript の
+ * 内部表現へ踏み込む必要があるので、踏み込まずに契約の側を広げてある。
+ * **構文が `any` / `unknown` そのものなら正常な非候補**である。
+ */
+export const isIndeterminateType = (type: ts.Type, node: ts.Node | undefined): boolean =>
+    (type.flags & (ts.TypeFlags.Any | ts.TypeFlags.Unknown)) !== 0
+    && node !== undefined
+    && node.kind !== ts.SyntaxKind.AnyKeyword
+    && node.kind !== ts.SyntaxKind.UnknownKeyword;
+
+export interface UnwrappedInitializer {
+    /** 包みを剥がした値の構文。 */
+    readonly expression: ts.Expression;
+    /** `satisfies` の型ノード (一番外側のものを優先)。 */
+    readonly satisfiesType: ts.TypeNode | undefined;
+}
+
+/** 丸括弧 / `as` / `satisfies` の包みを剥がす。 */
+export const unwrapInitializer = (node: ts.Expression): UnwrappedInitializer => {
+    let expression = node;
+    let satisfiesType: ts.TypeNode | undefined;
+    for (;;) {
+        if (ts.isParenthesizedExpression(expression)) {
+            expression = expression.expression;
+            continue;
+        }
+        if (ts.isAsExpression(expression)) {
+            expression = expression.expression;
+            continue;
+        }
+        if (ts.isSatisfiesExpression(expression)) {
+            satisfiesType ??= expression.type;
+            expression = expression.expression;
+            continue;
+        }
+        return { expression, satisfiesType };
+    }
+};
+
+/** 解決済みの型から文字列リテラル値の集合を読む。 */
+const stringLiteralValues = (type: ts.Type): ReadonlySet<string> | undefined => {
+    const parts = type.isUnion() ? type.types : [type];
+    const out = new Set<string>();
+    for (const part of parts) {
+        if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) return undefined;
+        if (!part.isStringLiteral()) return undefined;
+        out.add(part.value);
+    }
+    return out;
+};
+
+/**
+ * `const` 束縛の配列リテラルから値を**構文で**読む。
+ * `const X = ["a", "b"];` は型検査器の上では `string[]` に広げられるので、
+ * 型から要素を復元してはいけない。
+ */
+export const readConstArrayLiteralValues = (declaration: ts.VariableDeclaration): LiteralValuesResult => {
+    if ((declaration.parent.flags & ts.NodeFlags.Const) === 0) return NOT_A_CATALOGUE;
+    if (declaration.initializer === undefined) return NOT_A_CATALOGUE;
+    const { expression } = unwrapInitializer(declaration.initializer);
+    if (!ts.isArrayLiteralExpression(expression)) return NOT_A_CATALOGUE;
+    if (expression.elements.length === 0) return NOT_A_CATALOGUE;
+
+    const out = new Set<string>();
+    for (const element of expression.elements) {
+        const inner = unwrapInitializer(element).expression;
+        if (!ts.isStringLiteral(inner)) return NOT_A_CATALOGUE;
+        out.add(inner.text);
+    }
+    return values(out);
+};
+
+/** 型別名の宣言を型検査器で解決し、文字列リテラル型だけの合併なら値集合を返す。 */
+export const readResolvedStringLiteralUnion = (
+    checker: ts.TypeChecker,
+    alias: ts.TypeAliasDeclaration,
+): LiteralValuesResult => {
+    const symbol = checker.getSymbolAtLocation(alias.name);
+    if (symbol === undefined) return { kind: "indeterminate", reason: "型別名の記号を解決できません" };
+
+    const declared = checker.getDeclaredTypeOfSymbol(symbol);
+    if (isIndeterminateType(declared, alias.type)) {
+        return { kind: "indeterminate", reason: "型別名が any / unknown へ解決しました (構文はその綴りではありません)" };
+    }
+    const read = stringLiteralValues(declared);
+    if (read === undefined) return NOT_A_CATALOGUE;
+    return values(read);
+};
+
+/** オブジェクトリテラルのキーを読む。 */
+export const readObjectLiteralKeys = (
+    checker: ts.TypeChecker,
+    object: ts.ObjectLiteralExpression,
+): LiteralValuesResult => {
+    if (object.properties.length === 0) return NOT_A_CATALOGUE;
+
+    const out = new Set<string>();
+    let notACatalogue = false;
+    for (const property of object.properties) {
+        if (!ts.isPropertyAssignment(property)) {
+            notACatalogue = true;
+            continue;
+        }
+        const key = property.name;
+        if (ts.isStringLiteral(key) || ts.isIdentifier(key)) {
+            out.add(key.text);
+            continue;
+        }
+        if (!ts.isComputedPropertyName(key)) {
+            notACatalogue = true;
+            continue;
+        }
+        const type = checker.getTypeAtLocation(key.expression);
+        if (isIndeterminateType(type, key.expression)) {
+            return { kind: "indeterminate", reason: "計算キーが any / unknown へ解決しました" };
+        }
+        if ((type.flags & ts.TypeFlags.EnumLiteral) !== 0 || !type.isStringLiteral()) {
+            notACatalogue = true;
+            continue;
+        }
+        out.add(type.value);
+    }
+    if (notACatalogue) return NOT_A_CATALOGUE;
+    return values(out);
+};
+
+/** `default` を除く `case` の式の値を読む。 */
+export const readSwitchCaseValues = (checker: ts.TypeChecker, statement: ts.SwitchStatement): LiteralValuesResult => {
+    const out = new Set<string>();
+    let notACatalogue = false;
+    let seen = 0;
+    for (const clause of statement.caseBlock.clauses) {
+        if (ts.isDefaultClause(clause)) continue;
+        seen += 1;
+        const type = checker.getTypeAtLocation(clause.expression);
+        if (isIndeterminateType(type, clause.expression)) {
+            return { kind: "indeterminate", reason: "case の式が any / unknown へ解決しました" };
+        }
+        if ((type.flags & ts.TypeFlags.EnumLiteral) !== 0 || !type.isStringLiteral()) {
+            notACatalogue = true;
+            continue;
+        }
+        out.add(type.value);
+    }
+    if (seen === 0 || notACatalogue) return NOT_A_CATALOGUE;
+    return values(out);
+};
diff --git a/tests/js/support/enum-ts-sync/ts-value-sets.ts b/tests/js/support/enum-ts-sync/ts-value-sets.ts
index d774ef52..00cb97b8 100644
--- a/tests/js/support/enum-ts-sync/ts-value-sets.ts
+++ b/tests/js/support/enum-ts-sync/ts-value-sets.ts
@@ -1,68 +1,173 @@
 /**
- * TS 側の値集合を**型情報から**読む。
+ * TS 側の値集合を**登録した 1 つの宣言について**読む (前向きの検査)。
  *
- * 受理する形 (**解決・正規化された後の型**についての条件である):
- *   1. 対象ファイルのトップレベルに、その名前の**型別名の宣言**が**ちょうど 1 つ**あること。
- *   2. その宣言が解決する型が、**文字列リテラル型だけ**の union か、単独の文字列リテラル型であること。
- *   3. `ts.TypeFlags.EnumLiteral` を持つ構成要素は**受理しない** (本リポジトリに TypeScript の
- *      `enum` は 1 件も無く、文字列リテラル型と同じ契約ではないため。必要になってから広げる)。
+ * 受理する形は **2 つ**である:
+ *   1. 対象ファイルの**最上位**にある**型別名の宣言** (解決した型が文字列リテラル型だけ)
+ *   2. 対象ファイルの**最上位**にある **`const` 束縛の配列** (`as const` の有無を問わない)
+ *
+ * 同じ名前で受理できる宣言が**ちょうど 1 つ**あることを要求する (0 件・2 件以上は例外)。
+ *
+ * **値の読み取りは `ts-literal-values.ts` の共有抽出器を使う** (逆走査と同じ 1 本)。
+ * とくに配列は**構文から読む** — `const X = ["a", "b"];` は型検査器の上では `string[]` に
+ * 広げられるので、型から要素を復元してはいけない。`satisfies` を付けても対象型によって
+ * 広げられ得るので、**受理の判断は常に配列リテラルの構文**から行う。
+ *
+ * **対応表のキーと分岐のラベルは登録できない**。写しとして扱うなら型別名か定数の配列へ
+ * 切り出す (失敗メッセージにもそう書く)。
+ *
+ * **登録行の locator は AST から解決する** — 目録の行が持つのは `ts + declaration` だけで、
+ * locator に要る `shape` と `occurrence` が無い。同名の入れ子の宣言が最上位より前にあると
+ * 最上位でも `occurrence` は 0 とは限らないため、**逆走査と同じ採番器 (`buildScanIndex`)**
+ * でその節の locator を求める (採番の実装を 2 本持たない)。
  *
  * **重複は検出しない**。`"a" | "a"` は型検査器が `"a"` へ正規化するため、値集合の側からは
- * 元の重複を観測できない (union の中の `never` も同じく正規化で消える)。
- * **意味の診断は見ない** — 型検査そのものは `pnpm typecheck` の担当で、同じことを 2 箇所で見ない。
+ * 元の重複を観測できない。**意味の診断は見ない** — 型検査そのものは `pnpm typecheck` の担当。
  */
 import ts from "typescript";
 import path from "node:path";
 import { EnumTsSyncError } from "./errors";
-import { REPO_ROOT, type MirrorProgram } from "./program";
+import { REPO_ROOT, type MirrorProgram, type MirrorPrograms } from "./program";
+import { VIRTUAL_SUFFIX } from "./svelte-source";
+import { buildScanIndex, type TsCandidateLocator } from "./ts-candidates";
+import {
+    readConstArrayLiteralValues,
+    readResolvedStringLiteralUnion,
+    unwrapInitializer,
+} from "./ts-literal-values";
 
-export const readTsUnionValues = (
-    { program, checker }: MirrorProgram,
+export interface ResolvedTsDeclaration {
+    readonly locator: TsCandidateLocator;
+    readonly values: ReadonlySet<string>;
+}
+
+/** 受理できる宣言の候補 (型別名 または最上位の配列束縛)。 */
+type AcceptableDeclaration = ts.TypeAliasDeclaration | ts.VariableDeclaration;
+
+const sourceFileOf = (mirror: MirrorProgram, tsFile: string, where: string): ts.SourceFile => {
+    const absolute = path.join(REPO_ROOT, tsFile);
+    if (tsFile.endsWith(".svelte")) {
+        const virtual = absolute + VIRTUAL_SUFFIX;
+        const source = mirror.program.getSourceFile(virtual);
+        if (source === undefined) {
+            throw new EnumTsSyncError(where, ".svelte の仮想単位が program にありません (仮想化されていません)");
+        }
+        return source;
+    }
+    const source = mirror.program.getSourceFile(absolute);
+    if (source === undefined) throw new EnumTsSyncError(where, "TS ファイルが program に載っていません");
+    return source;
+};
+
+const acceptableDeclarations = (source: ts.SourceFile, declaration: string): readonly AcceptableDeclaration[] => {
+    const found: AcceptableDeclaration[] = [];
+    for (const statement of source.statements) {
+        if (ts.isTypeAliasDeclaration(statement) && statement.name.text === declaration) {
+            found.push(statement);
+            continue;
+        }
+        if (!ts.isVariableStatement(statement)) continue;
+        for (const variable of statement.declarationList.declarations) {
+            if (!ts.isIdentifier(variable.name) || variable.name.text !== declaration) continue;
+            if (variable.initializer === undefined) continue;
+            if (!ts.isArrayLiteralExpression(unwrapInitializer(variable.initializer).expression)) continue;
+            found.push(variable);
+        }
+    }
+    return found;
+};
+
+/**
+ * 登録した 1 つの宣言の値集合と locator を解決する。
+ * **値集合の比較より先に locator を解決する** — 値が食い違っていても登録済みの locator の
+ * 母集団は変わらず、前向きの診断と逆走査が同じ解決結果を共有できる。
+ */
+export const resolveTsDeclaration = (
+    mirror: MirrorProgram,
     tsFile: string,
     declaration: string,
-): ReadonlySet<string> => {
+): ResolvedTsDeclaration => {
     const where = `${tsFile}::${declaration}`;
-    const source = program.getSourceFile(path.join(REPO_ROOT, tsFile));
-    if (source === undefined) throw new EnumTsSyncError(where, "TS ファイルが program に載っていません");
+    const source = sourceFileOf(mirror, tsFile, where);
 
     // 構文が壊れていると型解決が黙って縮むので、構文の診断だけは見る。
-    if (program.getSyntacticDiagnostics(source).length > 0) {
+    if (mirror.program.getSyntacticDiagnostics(source).length > 0) {
         throw new EnumTsSyncError(where, "TS ファイルの構文が壊れています");
     }
 
-    const aliases = source.statements
-        .filter(ts.isTypeAliasDeclaration)
-        .filter((statement) => statement.name.text === declaration);
-    if (aliases.length === 0) {
+    const found = acceptableDeclarations(source, declaration);
+    if (found.length === 0) {
         throw new EnumTsSyncError(
             where,
-            "型別名の宣言が見つかりません (受理するのは `type X = …` だけ。定数配列・switch の case ラベル・.svelte 内の宣言は読みません)",
+            "受理できる宣言が見つかりません (受理するのは最上位の型別名の宣言か const の配列だけ。対応表のキーと分岐のラベルは登録できないので、写しなら型別名か定数の配列へ切り出すこと)",
         );
     }
-    if (aliases.length > 1) {
-        throw new EnumTsSyncError(where, `同名の型別名が ${aliases.length} 件あります`);
+    if (found.length > 1) {
+        throw new EnumTsSyncError(where, `同名の受理できる宣言が ${found.length} 件あります`);
     }
 
-    const symbol = checker.getSymbolAtLocation(aliases[0].name);
-    if (symbol === undefined) throw new EnumTsSyncError(where, "宣言の記号を解決できません");
+    const node = found[0];
+    const locator = buildScanIndex(source, mirror.checker, tsFile).locatorOf(node);
 
-    const declared = checker.getDeclaredTypeOfSymbol(symbol);
-    const parts = declared.isUnion() ? declared.types : [declared];
-
-    const values = new Set<string>();
-    for (const part of parts) {
-        if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) {
-            throw new EnumTsSyncError(where, `TypeScript の enum の値は受理しません: ${checker.typeToString(part)}`);
+    if (ts.isTypeAliasDeclaration(node)) {
+        const symbol = mirror.checker.getSymbolAtLocation(node.name);
+        if (symbol === undefined) throw new EnumTsSyncError(where, "宣言の記号を解決できません");
+        const declared = mirror.checker.getDeclaredTypeOfSymbol(symbol);
+        const parts = declared.isUnion() ? declared.types : [declared];
+        const values = new Set<string>();
+        for (const part of parts) {
+            if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) {
+                throw new EnumTsSyncError(
+                    where,
+                    `TypeScript の enum の値は受理しません: ${mirror.checker.typeToString(part)}`,
+                );
+            }
+            if (!part.isStringLiteral()) {
+                throw new EnumTsSyncError(
+                    where,
+                    `文字列リテラル型でない構成要素があります: ${mirror.checker.typeToString(part)}`,
+                );
+            }
+            values.add(part.value);
         }
-        if (!part.isStringLiteral()) {
-            throw new EnumTsSyncError(
-                where,
-                `文字列リテラル型でない構成要素があります: ${checker.typeToString(part)}`,
-            );
+        if (values.size === 0) throw new EnumTsSyncError(where, "値を 1 つも取り出せません");
+        // 共有抽出器と同じ判定であることを固定する (読み方を 2 本持たない)。
+        const shared = readResolvedStringLiteralUnion(mirror.checker, node);
+        if (shared.kind !== "values") {
+            throw new EnumTsSyncError(where, "共有抽出器と前向きの読み取りが食い違いました");
         }
-        values.add(part.value);
+        return { locator, values };
     }
-    if (values.size === 0) throw new EnumTsSyncError(where, "値を 1 つも取り出せません");
 
-    return values;
+    const result = readConstArrayLiteralValues(node);
+    if (result.kind !== "values") {
+        throw new EnumTsSyncError(
+            where,
+            "const の配列として受理できません (const 束縛であり、要素が 1 件以上あり、すべて構文上の文字列リテラルであること)",
+        );
+    }
+    return { locator, values: result.values };
 };
+
+/** 値集合だけを読む薄い入口 (負例行列が使う)。 */
+export const readTsUnionValues = (
+    mirror: MirrorProgram,
+    tsFile: string,
+    declaration: string,
+): ReadonlySet<string> => resolveTsDeclaration(mirror, tsFile, declaration).values;
+
+/** 目録の行を解決した結果 (前向きの判定と逆走査の登録済み判定が共有する)。 */
+export interface ResolvedEnumTsRelation<E extends { readonly ts: string; readonly declaration: string }> {
+    readonly entry: E;
+    readonly tsLocator: TsCandidateLocator;
+    readonly tsValues: ReadonlySet<string>;
+}
+
+/** 目録の全行を所有者の program 上で解決する。 */
+export const resolveRelations = <E extends { readonly ts: string; readonly declaration: string }>(
+    programs: MirrorPrograms,
+    rows: readonly E[],
+): readonly ResolvedEnumTsRelation<E>[] =>
+    rows.map((entry) => {
+        const resolved = resolveTsDeclaration(programs.programOf(entry.ts), entry.ts, entry.declaration);
+        return { entry, tsLocator: resolved.locator, tsValues: resolved.values };
+    });
```

## 実測 (実装後)

```
programs=<root>,packages/cli
population .ts=383 .svelte=132 (見本の追加後)
php resolved=123 unresolvable=3
candidates=388 {const-array:64, object-keys:192, literal-union:118, switch-cases:14}
indeterminate=5 / undecidable=0
hits=8 {規則1:6, 規則2a:1, 規則2b:1}  ← 申告 8 件と 1:1
(目録へ 2 行を登録する前は hits=10 {規則1:6, 規則2a:1, 規則2b:3} = 設計の実測と一致)
```
件数 pin: EXPECTED_RELATION_COUNT=31 / EXPECTED_EXEMPTION_COUNT=93 /
EXPECTED_UNRESOLVABLE_COUNT=3 / EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT=8 /
EXPECTED_INDETERMINATE_TS_COUNT=5 (設計見積り 3 + 見本 2) / EXPECTED_EXCLUDED_ROOT_COUNT=1 /
LedgerPins: DIVERGENCE_ENTRY_COUNT 50→51 (D54 新設) / ADOPTION_DEBT_COUNT 146→145

## テスト結果

- pnpm test: 179 files / 2496 tests passed
- pnpm test:packages: 11 files / 129 tests passed
- pnpm typecheck / lint / build / typecheck:packages / build:packages: green
- composer phpstan (level 10): No errors / vendor/bin/pint --test: passed
- composer test: 7835 tests / 7829 passed / 3 failed + 1 error
  (失敗は EmailPromotionTest 2 件と BughuntSelfTestExecutionTest 2 件で、
   直列に再実行すると 46/46 green。並列実行時の CPU 競合由来のフレークと判断している)
- 故障注入 16 件すべてで赤を実測 (devnotes の fault-injection-log.md)
