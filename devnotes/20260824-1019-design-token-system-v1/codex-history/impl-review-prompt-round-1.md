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

あなたは Laravel + Svelte アプリ aicue のコードレビュアーである。TODO T262「デザイントークン体系を正典 v1 へ追従」の実装差分をレビューせよ。

【レビュー観点】
1. **設計との一致性**: 詳細設計書の 12 施策 (S1〜S12) が設計どおり実装されているか。設計が求めた不変条件が実装で本当に固定されているか
2. **正確性**: 走査器・gate のロジックに論理誤りが無いか。とくに fail-open (検査が緑なのに穴が開いている) になる形
3. **AGENTS.md「静的検査 (gate) と走査器の共通規約」の 5 条** ((a) 完全修飾名 / (b) fail-closed / (c) 負例で裏取り / (d) 集めて使わない形を作らない / (e) 語彙一致はトークン完全一致) の適合
4. **テスト網羅性**: 負例と正例の両方向が固定されているか。母集団の非空が検査されているか
5. **DESIGN.md 準拠**: DESIGN.md が design token の canonical source。color / radius / typography は token 経由で参照し hex 直書き (#RRGGBB) を増やさない。token 値を変更する diff は resources/css/tokens.css と同一 diff 内で同期しているか
6. **Atomic Design 準拠**: resources/js/components/ の atoms/molecules/organisms/templates の責務分離。階層を逆流していないか。アイコンは Lucide、SVG 直書きを増やさない
7. **PHPStan level 10 適合 / TypeScript の型の閉じ方** (discriminated union / as const satisfies / never への収束)
8. **セキュリティ**: 本作業はテスト・スタイル・文書のみだがアプリ挙動に影響する変更 (text-white → text-surface / トークン値の是正) の副作用

【出力形式】
- ファイルごとに判定を書く
- 指摘は **行頭**に `[Critical]` / `[Warning]` / `[Suggestion]` のラベルを置く
- 最後に全体判定を `APPROVED` または `CHANGES_REQUESTED` で明記する

---

## 詳細設計書 (devnotes/20260824-1019-design-token-system-v1/detailed-design.md。レビュー履歴の付録は省略)

# 詳細設計: design-token-system 正典 v1 追従

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

加えて `app-design` スキルが設計判断に直結するものとして挙げる核: 既存テストの削除・上書き禁止 /
`DatabaseTransactions` の個別使用禁止 / やたらに複雑な案を提案しない。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）。**アプリケーション PHP は 1 行も変えない**。
  変更するのはテスト支援 PHP (`tests/Support/TemplateDivergence/LedgerPins.php`) の
  `int` 定数 2 本の値だけで、型は変わらないため PHP 側の型の母集団に変化は無い
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）。**本作業は DB を使わない**
- **DTO + JsonResource** パターン（本作業には該当なし）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- **TS 側の型の閉じ方** (概念設計「型の方針」):
  discriminated union / `as const satisfies` / 分類の網羅を `never` へ収束
- **AGENTS.md「静的検査 (gate) と走査器の共通規約」の 5 条**を新設・変更する走査器すべてに適用し、
  **同じ PR で 4 点** (負例と正例 / 解決できない形を落とす分岐 / 空振り検知 / docblock) を揃える

## 概念設計リファレンス

- [devnotes/20260824-1019-design-token-system-v1/conceptual-design.md](./conceptual-design.md) (Codex `gpt-5.6-terra` Round 3 で APPROVED)
- 実測記録: [contrast-measurements.md](./contrast-measurements.md)
- 逆引き表: [token-change-impact.md](./token-change-impact.md)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 写像 (tokens.css) の読み出しを 1 実装へ集約し、`@theme` ブロックの一意性を機械で固定する (i21 / i2 前半) | `tests/js/styles/theme-map.ts` (新) / `tests/js/styles/theme-map.test.ts` (新) / `canonical-source-parity.test.ts` / `tokens.test.ts` | 高 (他施策の土台) |
| S2 | class 走査器を新設する (i15 / i16 / i9 の共通入力 + 未対応入口の deny) | `tests/js/styles/class-usage.ts` (新) / `tests/js/styles/class-usage.test.ts` (新) | 高 (土台) |
| S3 | 参照の閉包 gate を新設し、写像の外の色語を落とす (i9) | `token-reference-closure.test.ts` (新) / `inventory.ts` / `AppLayout.svelte` / `SidebarNavItems.svelte` | 高 |
| S4 | 線形化しきい値を 0.04045 へ揃える (i13) | `contrast-invariant.test.ts` | 高 (S5 の前提) |
| S5 | 半透明背景 × 不透明文字の合成検査を新設する (i16) | `contrast-invariant.test.ts` / `inventory.ts` | 高 |
| S6 | トークン値を是正する (i16 の帰結) | `DESIGN.md` / `resources/css/tokens.css` | 高 |
| S7 | 実装からの逆向き被覆と役割分類の是正 (i15 / i14) | `contrast-invariant.test.ts` / `inventory.ts` | 高 |
| S8 | 文書 ⇔ 実装の双方向一致 gate を新設する (i10) | `component-doc-parity.test.ts` (新) / `design-md.ts` / `inventory.ts` / `DESIGN.md` | 中 |
| S9 | 規範判定対象外領域の除去と字下げの禁止を 2 契約に分け、行分類を 1 実装へ集約する (i12 の残余。**S8 の前提**) | `tests/js/styles/markdown-lines.ts` (新) / `design-system-docs.test.ts` / `design-md.ts` / `docs/design-system.md` | 中 |
| S10 | 不透明度修飾の生成形を契約として固定する (i6 の補強 / S5 の前提の裏取り) | `tokens.test.ts` | 中 |
| S11 | 責務境界表へ新設 gate を登録する (i11 の帰結) | `docs/design-system.md` | 中 (必須。書かないと S1/S2/S3/S8 で既存 gate が落ちる) |
| S12 | 共有パスの採用時債務を決着させる (乖離台帳 D50 / D51 の新設と D28 の本文訂正) | `docs/template-divergence.md` / `LedgerPins.php` / `adoption-debt.tsv` | 中 (必須) |

**実施順**: S1 → S2 → S4 → S10 → S5 → S6 → **S3 → S7** → **S9 → S8** → S11 → S12。
S4 を S5 より先に置くのは、しきい値を直してから合成の期待値を書くため
(逆順だと 0.03928 基準の期待値を書いて後で全部直すことになる)。
S6 (値の是正) は S5 の赤を確認した**後**に行う (テストファースト。思考原則 5)。
**S3 を S7 より先に置く** (Round 1 レビューの指摘で入れ替えた): S7 の逆向き被覆は
S3 が `text-white` を `text-surface` へ直した**後**に現れる `(surface, primary)` の赤を
前提にしているため、逆順だと S7 の「先に赤くするテスト」の記述が実際の実行順と食い違う。
**S9 を S8 より先に置く** (Round 5 の Warning で入れ替えた): S8 の節抽出は
S9 が新設する `tests/js/styles/markdown-lines.ts` (`scanMarkdownLines()`) を使うので、
逆順だと S8 の前提が存在しない。
S11 は S1 / S2 / S3 / S8 が新設する `tests/js/styles/*.test.ts` を既存 `design-system-docs.test.ts` の
双方向集合一致が要求するので、**同じコミットの中**で行う。

> **Codex レビューの反映**: 本書は Codex (`gpt-5.6-sol` / reasoning=high) の詳細設計レビューを
> 7 ラウンド受け、**全件を対応して改訂した版**である (反論 0 件)。
> ★スキルの既定は最大 5 ラウンドだが、Round 5 / Round 6 が設計の骨格 (解析器の選択と契約の形) を
> 作り替えたため、**確認のために 2 ラウンド超過**した。
> 件数は**行頭のラベル**の機械カウント (`grep -c '^\[Critical\]'` 等) を正本にする
> (本文中のラベルへの言及まで数えていたため Round 5 で数え方を行頭に限定した)。
> - Round 1: Critical 12 / Warning 11 / Suggestion 1 →
>   [decisions-round-1](./codex-history/design-review-decisions-round-1.md)
> - Round 2: Critical 7 / Warning 11 / Suggestion 1 →
>   [decisions-round-2](./codex-history/design-review-decisions-round-2.md)
> - Round 3: Critical 6 / Warning 10 →
>   [decisions-round-3](./codex-history/design-review-decisions-round-3.md)
> - Round 4: Critical 2 / Warning 8 / Suggestion 1 →
>   [decisions-round-4](./codex-history/design-review-decisions-round-4.md)
> - Round 5: Critical 7 / Warning 6 →
>   [decisions-round-5](./codex-history/design-review-decisions-round-5.md)
> - Round 6: Critical 9 / Warning 9 →
>   [decisions-round-6](./codex-history/design-review-decisions-round-6.md)
> - Round 7 (最終確認): Critical 2 / Warning 4 → 12 施策のうち **8 施策が APPROVE**。
>   残る 6 件も本改訂で閉じた →
>   [decisions-round-7](./codex-history/design-review-decisions-round-7.md)

---

## S1 写像の読み出しを 1 実装へ集約し、`@theme` ブロックの一意性を固定する (i21 / i2 前半)

### 変更箇所

- 新規: `tests/js/styles/theme-map.ts` (パーサ。gate ではない)
- 新規: `tests/js/styles/theme-map.test.ts` (パーサの自己検査 = 固定検体の負例・正例)
- `tests/js/styles/canonical-source-parity.test.ts` (L29-35 の `cssColorTokens()` を削除して移設、
  L66-69 の radius 抽出と L122 の `@utility` 抽出も移設)
- `tests/js/styles/tokens.test.ts` (`REPO_ROOT` の import 元は `design-md.ts` のままでよい。
  写像のテキストを読む必要が生じた箇所だけ `theme-map.ts` を使う)

### 波及変更

- TypeScript 型定義: `theme-map.ts` の公開型を新設 (下記)。`ParsedColor` / `Rgb` は
  S5 (合成) と S10 (派生の導出検査) が import する
- API Resource/DTO: なし
- テストファイル: `canonical-source-parity.test.ts` の import 追加 / ローカル関数の削除

> ⚠ `theme-map.ts` は `*.test.ts` ではないので `design-system-docs.test.ts` の
> `gateFiles()` の母集団には入らない。一方 `theme-map.test.ts` は**入る**ので
> S11 で責務境界表へ行を足す (足さないと既存 gate が赤くなる)。

### 現行コード

```ts
// tests/js/styles/canonical-source-parity.test.ts (L27-35)
const tokensCss = fs.readFileSync(path.join(REPO_ROOT, "resources/css/tokens.css"), "utf-8");

function cssColorTokens(): Map<string, string> {
    const map = new Map<string, string>();
    for (const m of tokensCss.matchAll(/--color-([a-z-]+):\s*([^;]+);/g)) {
        map.set(m[1], m[2].replace(/\/\*.*?\*\//g, "").trim().toLowerCase());
    }
    return map;
}
```

`--radius-*` の抽出 (L66-69) と `@utility text-*` の抽出 (L122) も**同ファイルの中に直書き**されている。
`tokens.test.ts` は生成 CSS 側を読むので写像のテキストは読んでいないが、
S3 (参照の閉包) が `@theme` の宣言集合を必要とするため、**このまま新 gate に 2 本目の
パーサを書くと i21 に反する**。

### 変更後コード

```ts
// tests/js/styles/theme-map.ts (新設)
/**
 * 実装写像 (resources/css/tokens.css) の読み出し — 検査テスト共有。
 *
 * ★正典 i21: 正本と写像の読み出しは**それぞれ 1 実装へ集約する**。
 *   同じ関心の解析が 2 本あると弱い方が緑を作る (「片方だけが読める写像」が成立する)。
 *   正本 (DESIGN.md) 側は design-md.ts が担当する。本ファイルは写像側だけを担当する。
 *
 * 【走査対象】呼び出し側が渡した CSS ソース文字列。実ファイルを読むのは薄いラッパーだけである。
 * 【解析の方式】**postcss で構文木にしてから読む**。自前の字句走査は書かない。
 *   ★`postcss` は**既に devDependency で、`tokens.test.ts` が生成 CSS の解析に使っている**
 *     (同じ解析器を写像側にも使う = 思考原則 1「フレームワークのレンジ内でやる」)。
 *     手書きの字句走査で解こうとしていた次の 4 つは、すべて解析器の側で解決する —
 *     (a) 文字列リテラルの中の `/*` `{` `}` の誤認 (`--font-sans` は引用符つきの値を 8 個持つ)、
 *     (b) at-keyword の境界 (`@theme-extra` は別の `name` になる)、
 *     (c) 宣言値の中の `@theme` (`Decl` の値であって `AtRule` にならない)、
 *     (d) 未終端のコメント・文字列・閉じないブロック (`CssSyntaxError` が飛ぶ = fail-closed)。
 *   ★受理する形は**実測して一意に決めた** (postcss 8.5 で確認。下の「実測表」)。
 *   読み方は 6 条 (外れたものはすべて**例外** = i20):
 *     1. `@theme` は `AtRule` かつ `name === "theme"` の**完全一致**で、
 *        **`params === ""`** かつ **`nodes !== undefined`** (ブロックを持つ) であること
 *     2. `topLevel` は `parent` が `Root` であること
 *     3. 宣言は**トップレベル `@theme` の直接の子 `Decl`** だけを採る。
 *        許容する直接子は **`Decl` と `Comment` の 2 種**で、**`Comment` は無視する**
 *        (tokens.css は `@theme` の中に節見出しコメントを持つので拒否すると実装できない)。
 *        `Rule` / 別の `AtRule` / その他のノードがあれば**例外**
 *     4. 同名宣言が 2 件以上あれば**例外** (postcss は後勝ちにせず `Decl` を 2 件返すので検出できる)
 *     5. `@utility` は**ルート直下**・`params` が `^text-[a-z0-9-]+$`・`nodes !== undefined`・
 *        直接の子が `Decl` と `Comment` だけ (Comment は無視)・同じ `params` の重複が無いこと
 *     6. 構文エラー (未終端コメント / 未終端文字列 / 閉じないブロック) は postcss の例外を伝播させる
 * 【保証しないもの】
 *   - Tailwind の解釈 (宣言が生成 CSS に出るか) は見ない。それは tokens.test.ts の担当
 *   - postcss の AST 形状に依存する。postcss の major 更新で形が変われば
 *     固定検体が最初に落ちる (無言で緑にはならない)
 *   - 値の意味 (色空間・単位) は見ない。色だけは parseCssColor が明示的に扱う
 */
export interface ThemeBlock {
    /** ソース先頭からのブロック開始位置 (診断用。期待値には使わない) */
    readonly offset: number;
    /** ルート直下の `@theme` か (条件つき at-rule の内側なら false) */
    readonly topLevel: boolean;
}
/* ★`body` (ブロック本文の文字列) は**持たない** — どこからも使わない出力を作らない
   (共通規約 (d)「集めた走査結果を判定に使わない形を作らない」)。宣言は AST から採る。 */

/** 1 本のソースを解析した結果。 */
export interface ThemeMap {
    /** 見つかった `@theme` ブロック全件 (0 件・2 件以上も呼び出し側が判定できるよう返す) */
    readonly blocks: readonly ThemeBlock[];
    /** ルート直下の `@theme` 直下の CSS 変数宣言 `{ 変数名 → 値 }` */
    readonly declarations: ReadonlyMap<string, string>;
    /** `@utility text-<name>` の宣言 `{ name → { プロパティ → 値 } }` */
    readonly rampUtilities: ReadonlyMap<string, ReadonlyMap<string, string>>;
}

/**
 * ★**唯一の解析実装**。実ファイル用の関数はすべてこの薄いラッパーである
 *   (Round 1 レビューの指摘: 固定検体を解析する入口が公開 API に無いと、
 *   `theme-map.test.ts` が任意入力を検査できず i18 の裏取りにならない)。
 * `file` は例外メッセージに載せる識別子であって、ファイルを読むためのものではない。
 */
export function parseThemeMap(source: string, file: string): ThemeMap;

/** `resources/css/tokens.css` を読んで `parseThemeMap` に渡す薄いラッパー。 */
export function tokensCssThemeMap(): ThemeMap;

/** `--color-<suffix>` だけを suffix で引ける形にしたもの (コメント除去・小文字化)。 */
export function cssColorTokens(): ReadonlyMap<string, string>;

/** `--radius-<suffix>` だけを suffix で引ける形にしたもの。 */
export function cssRadiusTokens(): ReadonlyMap<string, string>;

/** `@utility text-<name>` の宣言 (`tokensCssThemeMap().rampUtilities` の別名)。 */
export function cssRampUtilities(): ReadonlyMap<string, ReadonlyMap<string, string>>;

/**
 * 色の値を厳密に解析する (派生 token の値の検査と、合成の入力に使う)。
 *
 * 【受理する形】`#rrggbb` (大小文字どちらも) / `rgba(r, g, b, a)` / `rgb(r g b / a)`。
 *   ★`#rrggbb` は必須である — 正本 (`designColors()`) が返すのは hex で、
 *     S10 の「派生 token は正本の primary の RGB を alpha 0.12 にしたもの」の検査が
 *     正本側の hex を本関数へ渡す。
 * 【厳密に拒否する】RGB が 0..255 の整数でない / alpha が 0..1 でない /
 *   余分な末尾文字がある / 数値にならない / 上記以外の関数記法 (`color-mix(…)` 等)。
 *   いずれも**例外**にする (i20: 読めるものだけ拾う形にしない)。
 */
export function parseCssColor(value: string): ParsedColor;

/** 色の正規化形 (S5 の合成と S10 の派生検査が共有する)。 */
export type ParsedColor =
    | { readonly kind: "opaque"; readonly rgb: Rgb }
    | { readonly kind: "alpha"; readonly rgb: Rgb; readonly alpha: number };

export interface Rgb {
    readonly r: number;
    readonly g: number;
    readonly b: number;
}
```

**postcss の実挙動 (実測。設計の期待値の根拠)**:

| 入力 | 結果 |
|---|---|
| `@theme { --a: 1px; }` | `AtRule(name="theme", params="")` + 子 `Decl` |
| `@theme-extra { … }` | `AtRule(name="theme-extra")` — 別物 |
| `@/* c */theme { … }` | **例外** (`CssSyntaxError: At-rule without name`) |
| `@theme;` | `AtRule(name="theme")` で `nodes === undefined` |
| `@theme foo { … }` | `AtRule(name="theme", params="foo")` |
| `--x: "@theme { }";` | `Decl` のみ (at-rule にならない) |
| `@theme { --f: "a{b"; --g: 2px; }` | 宣言 2 件を正しく採る |
| `@theme { --a: 1px; --a: 2px; }` | `Decl` が 2 件現れる (呼び出し側が重複を検出できる) |
| `@theme { :root { … } }` | 子に `Rule` が現れる |

- `canonical-source-parity.test.ts` は**ローカル関数を削除**して `theme-map.ts` を使う
  (後方互換の並走を残さない = AGENTS.md 思考原則 3)。
- `@theme` の一意性は `canonical-source-parity.test.ts` に describe を 1 つ足して固定する
  (写像の形の検査なので 正本 ⇔ 写像 の gate が持つのが自然)。

```ts
describe("canonical source parity: 写像の形", () => {
    it("@theme ブロックがリポジトリに 1 つだけある (2 つ目の宣言が検査を素通りする経路を塞ぐ)", () => {
        // 走査は git 追跡下の *.css 全数。tokens.css の外に @theme を置くと
        // canonical-source-parity / tokens の両方が見ない token 空間が育つ。
        const cssFiles = trackedCssFiles();
        expect(cssFiles.length, "*.css が 1 件も取れない (走査の空振り)").toBeGreaterThan(0);
        // ★判定は parseThemeMap の結果で行う (コメントの中の @theme を数えない)。
        const withTheme = cssFiles.filter(
            (rel) => parseThemeMap(readCss(rel), rel).blocks.length > 0,
        );
        expect(withTheme).toEqual(["resources/css/tokens.css"]);
        expect(tokensCssThemeMap().blocks.length, "tokens.css の @theme が 1 ブロックでない").toBe(1);
        expect(tokensCssThemeMap().blocks[0].topLevel, "@theme がルート直下でない").toBe(true);
    });
});
```

写像のキー空間と正本のキー空間の橋渡しが一意であることも同じ describe で固定する。

```ts
    it("COLOR_TOKEN_MAP の逆写像が一意である (suffix → DESIGN キーが後勝ちにならない)", () => {
        // 走査器は suffix 空間を返し、gate は逆写像で DESIGN キー空間へ写す。
        // 値に重複があると逆引きが後勝ちになり、別のトークンの値で検査してしまう。
        const suffixes = Object.values(COLOR_TOKEN_MAP);
        expect(suffixes.length, "COLOR_TOKEN_MAP が空 (走査の空振り)").toBeGreaterThan(0);
        expect(new Set(suffixes).size).toBe(suffixes.length);
    });
```

- `trackedCssFiles()` は `git ls-files -- '*.css'` を使わず、**`resources/` の再帰走査**で
  取る (テスト実行で子プロセスを起こさない。`vitest-inventory-gate` が
  「収集フェーズで spawn しない」規約を持つのと同じ配慮)。
  走査根は `resources/` 1 本で、**存在しなければ fail-fast** にする。
  `node_modules` / `vendor` / `public/build` は走査根の外なので自然に落ちる。
  **保証範囲**: `resources/` の外に置いた CSS は見ない — これを docblock に明記する
  (アプリの CSS はすべて `resources/css` にあり、`vite.config` の入口も同ディレクトリである)。

### 型適合チェック

- [x] 戻り値の型が明示されている (`readonly` / `ReadonlyMap` で外から書き換えられない)
- [x] `null` 安全: 解析失敗は `undefined` を返さず**例外**にする (i20 = 解析の失敗を pass に変えない)
- [x] 配列返却ではなく `ReadonlyMap` / `interface` を返す
- [x] Generics の型パラメータが正しい (`ReadonlyMap<string, ReadonlyMap<string, string>>`)

### テスト計画

- [x] **先に赤くするテスト**: `canonical-source-parity.test.ts` の新 describe「@theme ブロックが
      リポジトリに 1 つだけある」。実装前は `parseThemeMap()` が存在しないので**コンパイルエラーで赤**。
      次に `theme-map.ts` を空実装 (`throw`) で置いて**実行時エラーで赤**を確認してから実装する
- [x] 既存テスト `canonical-source-parity.test.ts` の 8 it は**移設後も同じ期待値で緑**であること
      (リファクタの等価性の確認)
- [x] 新規: `tests/js/styles/theme-map.test.ts` — **固定検体を `parseThemeMap(source, file)` へ
      直接渡して**パーサの仕様を固定する (i18。実ファイルを読む経路は検体を差し込めない)
  - 負例 1: `@theme` を 2 ブロック持つ検体 → `blocks.length === 2` (呼び出し側が落とせる)
  - 負例 2: `@media` の中の `@theme` → **ブロックとして数える**が `topLevel === false` で、
    `declarations` は**トップレベルの `@theme` だけ**を見る (i2 後半と同じ絞り込み)
  - 負例 3: **コメントの中の `@theme`** (`/* @theme { --color-x: red; } *​/`) →
    `blocks.length === 0` (コメント除去を先に行う仕様の裏取り)
  - 負例 4: 同名変数の再宣言 → 例外 (i20)
  - 負例 5: `@theme` の中に別の `AtRule` がある → 例外 (深さ 1 段の前提を破る形)
  - 負例 6: **閉じないブロック** (`@theme {` のまま EOF) → 例外 (`CssSyntaxError`)
  - 負例 7: `parseCssColor("color-mix(in oklab, red 10%, transparent)")` → 例外
    (扱えない色表現を「読めた」ことにしない)
  - 負例 8: `parseCssColor("rgba(300, 0, 0, 0.1)")` (RGB が範囲外) → 例外
  - 負例 9: `parseCssColor("rgba(29, 78, 216, 1.5)")` (alpha が範囲外) → 例外
  - 負例 10: `parseCssColor("#1d4ed8ff")` (余分な末尾文字) → 例外
  - 負例 10b: `@theme-extra { … }` / `@utility-extra text-x { … }` →
    **数えない** (postcss の `name` の完全一致)
  - 負例 10c: 未終端のコメント (`/*` のまま EOF) → 例外 (postcss の `CssSyntaxError`)
  - 負例 10d: 未終端の文字列 (`'` のまま EOF) → 例外 (同上)
  - 負例 10e: **宣言値の中の `@theme`** (`--x: '@theme { }';`) → ブロックとして数えない
  - 負例 10f: `@/* c */theme { }` → **例外** (`CssSyntaxError: At-rule without name`。実測値)
  - 負例 10g: `@theme` の中に `Rule` (`:root { }`) がある → 例外
  - 負例 10h: `@theme;` (ブロック無し) → 例外 (`nodes === undefined`)
  - 負例 10i: `@theme foo { }` (params つき) → 例外
  - 負例 10j: `@utility text-x` が 2 つ → 例外 / `@utility bg-x { }` (params が規則外) → 例外
  - 正例 4: `@theme { --f: "a{b"; --g: 2px; }` → 宣言 2 件を正しく採る
    (文字列の中の `{` を誤認しない。実測で確認済み)
  - 正例 5: `@theme { /* 節見出し */ --a: 1px; }` / `@utility text-x { /* c */ font-size: 1px; }` →
    **`Comment` を無視して**宣言を採る (現行 tokens.css がこの形である)
  - **負例 11〜14 (文字列状態の裏取り。Round 2 の Critical)**:
    - `--x: '/* not a comment */';` → コメントとして潰されず、宣言が 1 件取れる
    - `--x: '{';` / `--y: '}';` → ブロックの対応が壊れない
    - `--x: 'it\\'s';` (エスケープした引用符) → 文字列がそこで閉じない
    - **現行 `--font-sans` と同形の宣言** (引用符つき family を 8 個持つ) →
      値が丸ごと 1 つの宣言として取れる
  - 正例 1: 現行 tokens.css と同形の検体で色 / radius / ramp が期待どおり取れる
  - 正例 2: `parseCssColor("rgba(29, 78, 216, 0.12)")` →
    `{ kind: "alpha", rgb: { r: 29, g: 78, b: 216 }, alpha: 0.12 }`
  - 正例 3: `parseCssColor("#1d4ed8")` →
    `{ kind: "opaque", rgb: { r: 29, g: 78, b: 216 } }`
- [x] 母集団の非空: `tokensCssThemeMap().declarations.size > 0` / `cssColorTokens().size > 0` /
      `cssRampUtilities().size > 0` (共通規約 (b) の 3 点目)
- [x] 個別の `DatabaseTransactions` を使っていない (DB を使わない)

### リスク

- リファクタで既存 8 it の期待値が変わると、**値の drift を見逃す穴**が開く。
  → 等価性の担保は「**期待値を変えない**」であって「実装を変えない」ではない。
  **解析実装そのものは postcss ベースへ置換する** (Round 3 の Critical: 旧リスク欄の
  「本体をそのまま移す」「正規表現を書き換えない」は、Round 2 で問題になった
  **文字列の中の `/*` `{` `}` を誤認する実装を温存する**指示になっていた)。
  移設の受け入れ条件は「既存 8 it が同じ期待値で緑になること」だけである。
- `resources/` 再帰走査は将来 CSS を別の場所へ置いたときに見落とす。
  → docblock に保証範囲として明記し、`vite.config.ts` の入口が
  `resources/css/app.css` であることを根拠として書く。

---

## S2 class 走査器を新設する (i15 / i16 / i9 の共通入力)

### 変更箇所

- 新規: `tests/js/styles/class-usage.ts` (走査器。gate ではない)
- 新規: `tests/js/styles/class-usage.test.ts` (走査器の自己検査 = 固定検体の負例・正例)

> ⚠ `class-usage.ts` は `*.test.ts` ではないので `design-system-docs.test.ts` の
> `gateFiles()` の母集団には入らない (母集団は `tests/js/styles/*.test.ts`)。
> 一方 `class-usage.test.ts` は**入る**ので S11 で責務境界表へ行を足す。

### 波及変更

- TypeScript 型定義: 下記の公開型 (走査結果) を新設。`inventory.ts` が理由の union を参照する
- API Resource/DTO: なし
- テストファイル: S3 / S5 / S7 の gate が本走査器を import する

### 変更後コード (公開する型と関数)

```ts
// tests/js/styles/class-usage.ts (新設)
/**
 * resources/js の class 記述から「前景 × 背景の組」と「解決できなかった形」を導出する走査器。
 *
 * 【走査分母】resources/js のディレクトリ単位の再帰走査 (`*.svelte` / `*.ts`)。
 *   ファイルを足したら自動で分母に入る (正典 i15 / s14: 固定のファイル列挙は足し忘れが静かに起きる)。
 *
 * 【解析の方式】**既存の解析器で構文木 / トークン列にしてから読む**。自前の字句走査は書かない。
 *   ★準拠実装がリポジトリに在る — `tests/js/support/file-input-scan.ts` は
 *     `svelte/compiler` の `parse()` で `.svelte` を AST にし、解析できない形を
 *     診断へ落とす (`parse-failed` / `unresolved-*`)。`typescript` も既に devDependency で、
 *     `tests/js/support/enum-ts-sync/*` が `ts` の API を使っている。
 *   - `.svelte`: `parse(source, { modern: true })` の AST を歩き、
 *     `class` 属性の `Text` チャンクと、式の中の**文字列リテラルのノード**を単位にする。
 *     parse が失敗したら診断 `parse-failed` にして**gate を落とす**
 *   - `.ts`: **`ts.createSourceFile()` で AST 化**し、ノード種別で分類する —
 *     `StringLiteral` / `NoSubstitutionTemplateLiteral` は**単位**、
 *     `TemplateExpression` (置換つき) は **`interpolated` の判定不能**。
 *     ★**`ts.createScanner()` は使わない** (Round 5 の Critical: scanner は字句解析器であり、
 *       `` `${cond ? "}" : v}` `` の `}` が補間の終端か object literal の内側かを
 *       判断するには構文文脈が要る。scanner を順に呼ぶだけでは解けない)。
 *     **parse diagnostics が 1 件でもあれば解析失敗**にする
 *     (括弧の不整合など構文エラー全般が fail-closed になる。scanner では字句エラーしか拾えない)
 *   ★`TemplateExpression` を `interpolated` として記録したら、**その subtree へは降りない**
 *     (降りると補間内部の `StringLiteral` を独立した class 単位として二重に拾う。Round 6 の Warning)
 *   ★**構文解析の失敗はすべて診断**にする (例外は投げない)。
 *     診断が出たファイルの `occurrences` / `pairs` は**空にする** —
 *     部分結果を後続 gate が使う形を作らない (best-effort で返さない。Round 6 の Warning)。
 *   ★**未終端**のコメント・文字列・template・補間は解析器がエラーとして返すので**診断**にする。
 *     単純な波括弧の数え上げで補間の終端を誤認し、**以降のソースを無言で読み落とす**経路は
 *     この方式では生じない (Round 4 の Critical)。
 *   ★解析不能後に**残りのファイルを無言で捨てない** — 診断は必ず結果に残り、
 *     gate は診断が 1 件でもあれば落ちる (共通規約 (b) の 1 点目)。
 *
 * 【走査単位 (これが保証する構文集合)】**文字列リテラル**。単位の中だけで状態と組を作る。
 *   ★**それ以外の形については検出力を主張しない**。代わりに、扱えない**既知の入口**を
 *     語彙の deny (unsupportedEntryPoints()) で 0 件に固定する。
 *
 * 【class 候補の分解 (3 段)】
 *   1. まず **CSS の空白** (空白 / タブ / 改行 / CR / FF) で class 候補へ分割する
 *   2. **監視対象かどうかを先に判定する** (`isWatchedCandidate()`)。
 *      ★これが無いと、import 指定子 (`"./Button.types"`) や URL のような
 *        「そもそも class ではない文字列」まで文字検証に掛かって `unparsable-token` になり、
 *        実リポジトリを正常に走査できない (Round 6 の Critical)。
 *      判定は 3 段で、**文字検証はしない** —
 *        (a) 先頭から `<何らかの文字列>:` の並びを variant 列として剥がす
 *        (b) 残りの先頭の `!` を剥がす
 *        (c) 残りが**監視対象接頭辞**のいずれかで始まるなら監視対象
 *      監視対象接頭辞は `WATCHED_UTILITY_PREFIXES` に**1 か所だけ**宣言し、
 *      S3 (閉包) と共有する (`bg-` / `text-` / `border-` / `ring-` / `divide-` /
 *      `outline-` / `rounded-` / `fill-` / `stroke-` / `decoration-` / `accent-` /
 *      `caret-` / `placeholder-` / `from-` / `to-` / `via-`)
 *   3. **監視対象と判定した候補だけ**を、候補**全体**の許可文字検証へ回す
 *      (英数字 / `_` / `-` / `:` / `/` / `.` / `%` / `[` / `]` / `!` / `#`。
 *      ds-purity.ts の CLASS_TOKEN_PATTERN と同じ集合)。
 *      **許可外の文字が 1 つでもあれば候補全体を `unparsable-token`** にする
 *   4. そのうえで variant / important / alpha / utility を分解する
 *   ★「許可文字以外はすべて区切り」という規則は**採らない** (Round 5 の Critical:
 *     それだと `bg-primaryあ` が `bg-primary` へ縮退して**有効な token として通り**、
 *     `bg-(--var)` も候補全体を未解決にする根拠を失う)。
 *
 * 【文字列リテラルの扱い (解析器が保証する範囲)】
 *   - 3 種のリテラル (単引用 / 二重引用 / バッククォート) の判別・エスケープ・
 *     コメントとの区別は**解析器の責務**である (自前で状態を持たない)
 *   - 置換を含む template literal は `interpolated` の判定不能にする
 *     (無言で「通常のリテラル」に落とさない = 共通規約 (b))
 *
 * 【不透明度修飾の受理範囲】`/` + 半角数字 1〜3 桁で値が **0..100** の形だけを受理する。
 *   - `/100` は**修飾なし (不透明)** と同じ扱い (`alpha === null`)
 *   - `/0` は**透明**なので背景が親から来る = `keyword-color` と同じ判定不能
 *   - 範囲外 (`/101`) / 負数 / 小数 / 任意値 (`/[0.35]`) は
 *     `unresolved: "unsupported-alpha-syntax"` にして**素通りさせない**
 *
 * 【状態の作り方】素の宣言を基底の状態とし、同じ修飾の連なり (`hover:` / `disabled:` …) を
 *   持つ宣言は基底をその修飾で上書きした状態とする。組は状態の内側だけで作る。
 *   ★**発火条件を形式化する** (Round 6 の Critical: 旧文面だと通常ケースまで該当し、
 *     肝心の例では発火しなかった) —
 *       各候補は variant 列 `V` を持つ (素の宣言は空列)。
 *       単位内の**非空の `V` の集合**を `S` とする (**基底は継承元なので `S` に入れない**)。
 *       `|S| ≤ 1` → **解決可能**。基底を `S` の唯一の列で channel ごとに上書きした状態を作る。
 *       `|S| ≥ 2` → **`variant-composition` の判定不能** (channel を跨いで単位全体を落とす)。
 *     variant 条件の包含関係は Tailwind の意味論であり、自前で再実装しない。
 *   これをしないと `"bg-surface text-danger hover:bg-danger hover:text-neutral"` から
 *   `text-danger on bg-danger` (比 1.0) という**実在しない組**が生まれる。
 *
 * 【保証しないもの (誇張しない)】
 *   - **宣言の単位をまたいで成立する組**。実例: atoms/input-state.ts は `text-text` を
 *     INPUT_BASE_CLASSES に、`bg-surface` / `bg-neutral` を inputStateClass() の戻り値に持つ。
 *     ただしこの穴の大部分は役割の直積 (i14) が覆っている — 両方の token に役割が在れば、
 *     その組は宣言が割れていても既に母集団の内側にある。見えないのは
 *     「直積に現れない役割の組み合わせの 2 token が同じ要素に載り、かつ宣言の単位が割れている」
 *     場合だけである
 *   - **親から渡る class** (`extraClass`) と**親要素から継承する背景** (正典 i22 (2))
 *   - **実行時に組み立てられる class** (正典 i22 (1))
 *   - **DOM の実際の入れ子**。同じ単位に載っていることは「同じ要素にある」ことの近似である
 *   - **変種の修飾の綴りが正しいこと**。`hoverr:bg-primary` は token としては解決する
 *     (変種の名前空間は Tailwind のもので、本アプリの写像ではない)
 */

/**
 * ★**すべての利用側 (S3 / S5 / S7) が同じ抽出結果から導出する**ための共通出力
 *   (Round 1 レビューの Critical: これが無いと S3 が 2 本目の走査器を書くことになり i21 に反する)。
 *
 * 解析は **3 段を独立に**行う — 変種の修飾 (`sm:` `hover:`) / 重要度の修飾 (`!`) /
 * 不透明度の修飾 (`/NN`)。**不透明度の修飾は色 utility にだけ許す**ので、
 * `text-center/50` は `unresolved: "alpha-on-non-color"` になり**素通りしない**。
 */
export interface ClassTokenOccurrence {
    /** リポジトリ相対のファイルパス */
    readonly file: string;
    /** 走査単位 (文字列リテラル) の識別子。行番号は持たない (正典 s14) */
    readonly unit: string;
    /** 区切りで分割したままの生のトークン (診断用。期待値には使わない) */
    readonly raw: string;
    /** 変種の修飾を出現順に並べたもの (`["sm", "hover"]`)。素の宣言は空配列 */
    readonly variants: readonly string[];
    /** 重要度の修飾が付いているか */
    readonly important: boolean;
    /** 変種・重要度・不透明度を取り除いた utility 名 (`bg-primary` / `text-center`) */
    readonly utility: string;
    /**
     * 不透明度修飾の**百分率** (0..100 の整数)。`null` は修飾なし。
     * ★名前で単位を分ける (Round 3 の Critical: `10` と `0.10` を同じ `number` で扱うと
     *   取り違えが型で落ちず、二重除算・除算漏れの温床になる)。
     *   0..1 の実効値を持つのは `ResolvedAlphaBackground.effectiveAlpha` **だけ**である。
     */
    readonly alphaPercent: number | null;
    /** utility 名が何へ解決したか */
    readonly resolution: TokenResolution;
}

/** utility 名の解決結果 (判別可能 union。未解決を無言で候補から外さない = 共通規約 (b))。 */
export type TokenResolution =
    | { readonly kind: "color"; readonly channel: ColorChannel; readonly suffix: string }
    | { readonly kind: "ramp"; readonly name: string }
    | { readonly kind: "radius"; readonly name: string }
    | { readonly kind: "contract"; readonly word: string }
    | { readonly kind: "unresolved"; readonly reason: UnresolvedReason };

/** 色 utility の channel。**前景 / 背景以外も分類する** (i17 の非テキスト境界を混ぜないため)。 */
export type ColorChannel = "background" | "foreground" | "border" | "ring" | "other";

/** 解決できなかった理由。 */
export type UnresolvedReason =
    | "unknown-token"            // テーマ名前空間の接頭辞を持つが写像にも契約表にも無い
    | "alpha-on-non-color"       // 色でない utility に不透明度修飾が付いている
    | "unsupported-alpha-syntax" // 不透明度修飾の書き方が受理範囲外 (下記)
    | "unparsable-token";        // 区切りで割れた形 (`bg-(--var)` / 非 ASCII の混入)

/** `var(--…)` 参照 (class ではない別チャネル)。 */
export interface CssVarReference {
    readonly file: string;
    readonly name: string;
    readonly resolution: TokenResolution;
}

/*
 * ★**純粋入口が唯一の実装**である (Round 2 の Critical。Round 1 で S1 に指摘された穴が
 *   S2 で再発していた)。実リポジトリ用の関数は**ファイルを読んで集約するだけ**の
 *   薄いラッパーで、固定検体は下の 3 本へ直接渡す。
 */
export function scanClassUsageSource(source: string, file: string): SourceClassUsageScan;
export function scanCssVarReferencesSource(source: string, file: string): CssVarReferenceScan;
export function unsupportedEntryPointsSource(
    source: string,
    file: string,
): readonly UnsupportedEntryPoint[];

export function scanCssVarReferences(): CssVarReferenceScan;
/* ★戻り値は `CssVarReferenceScan` (参照配列ではない)。利用側は `.references` と
   `.diagnostics` を**両方とも明示的に消費する** (Round 7 の Critical:
   S3 で結果型を導入したのに S2 の公開シグネチャが参照配列のままで、正本が二通りに読めた)。 */

/** 走査で得た 1 つの組。 */
export type ScannedPair =
    | { readonly kind: "opaque"; readonly file: string; readonly fg: string; readonly bg: string }
    | {
          readonly kind: "alpha-background";
          readonly file: string;
          readonly fg: string;
          readonly bg: string;
          /** class 修飾の百分率 (0..100)。`null` は修飾なし (token の値が持つ alpha だけ) */
          readonly modifierPercent: number | null;
      }
    | { readonly kind: "undecidable"; readonly file: string; readonly reason: UndecidableReason };

/**
 * 静的に組を決められない理由 (正典 i16 が「例外にして素通りさせない」と定めた形)。
 *
 * ★**正本は実行時の配列**である (Round 7 の Warning: union 型は実行時に列挙できないので、
 *   「各 reason を発火させる検体が 1 つ以上ある」の網羅検査も pending の説明の生成も
 *   union からは書けない)。配列を正本にし、**型はその要素型から導出する**。
 *
 * ```ts
 * export const UNDECIDABLE_REASONS = [
 *     { id: "foreground-alpha", label: "前景の alpha" },
 *     { id: "keyword-color", label: "色キーワードと /0 (透明)" },
 *     { id: "alpha-background-no-text", label: "前景を持たない alpha 背景" },
 *     { id: "opaque-and-alpha-background", label: "塗り面と alpha 背景の同居" },
 *     { id: "multiple-background", label: "背景の多重宣言" },
 *     { id: "multiple-foreground", label: "前景の多重宣言" },
 *     { id: "element-opacity", label: "要素全体の不透明度" },
 *     { id: "interpolated", label: "補間" },
 *     { id: "variant-composition", label: "variant 列の合成" },
 * ] as const;
 * export type UndecidableReason = (typeof UNDECIDABLE_REASONS)[number]["id"];
 * ```
 *
 * fixture の網羅・表示ラベル・`PENDING_CONTRAST_PAIRS` の説明は**すべてこの配列から導出する**。
 *
 * ★`double-alpha` は**値域から外した** (Round 2 レビューの Critical)。
 *   alpha を値に持つ token への修飾は実効 alpha が `token の alpha × 修飾の alpha` に
 *   確定する (S10 が生成形を固定する) ので、**静的に決められる形**であり
 *   例外へ逃がすのは i16 に反する。合成対象として計算する。
 */
/* 下は値域の可読な写し (正本は上の `UNDECIDABLE_REASONS` 配列)。 */
export type UndecidableReason =
    | "foreground-alpha"          // 前景にも不透明度修飾がある
    | "keyword-color"             // bg-transparent / bg-current 等の色キーワードと `/0` (透明)
    | "alpha-background-no-text"  // 同じ宣言に前景が無い alpha 背景
    | "opaque-and-alpha-background" // 同じ状態に塗り面の背景と alpha 背景が同居
    | "multiple-background"       // 同じ状態に不透明な背景の宣言が 2 つ以上 (勝敗を静的に決められない)
    | "multiple-foreground"       // 同じ状態に前景の宣言が 2 つ以上
    | "variant-composition"       // 単位内の非空 variant 列の集合 S が |S| >= 2 (包含関係を解かない)
    | "element-opacity"           // 要素全体の不透明度指定 (opacity-*) が同居
    | "interpolated";             // 補間で完成した class 文字列を差し込む単位

/** 不透明のみの不完全な単位 (前景か背景の片方しか無い) の集計。 */
export interface IncompleteOpaqueCounts {
    readonly backgroundOnly: number;
    readonly foregroundOnly: number;
}

/**
 * **1 本のソース**の解析結果 (純粋入口が返す形)。
 * ★集約用の `files` / `perDirectory` は持たない (Round 3 の Warning: 任意の検体に対して
 *   どのディレクトリ分類を生成するのかが定義できず、責務が合わない)。
 */
export interface SourceClassUsageScan {
    /** ★全 class トークンの共通出力。S3 / S5 / S7 はここから導出する (2 本目の走査器を書かない) */
    readonly occurrences: readonly ClassTokenOccurrence[];
    readonly pairs: readonly ScannedPair[];
    readonly incompleteOpaque: IncompleteOpaqueCounts;
    /**
     * ★解析そのものが失敗したことの記録 (Round 5 の Critical: これが無いと
     *   「診断が 1 件でもあれば gate を落とす」を型で実装できなかった)。
     *   純粋入口は**例外を投げず**にここへ積む。集約ラッパーも例外を握らず
     *   ファイル名つきで積む (準拠実装 `file-input-scan.ts` の `parse-failed` と同じ形)。
     */
    readonly diagnostics: readonly ClassScanDiagnostic[];
}

export interface ClassScanDiagnostic {
    readonly file: string;
    readonly reason: "svelte-parse-failed" | "ts-diagnostic";
    /** 解析器が返したメッセージ (診断出力用。期待値には使わない) */
    readonly detail: string;
}

/** **実リポジトリ**の集約結果 (薄いラッパーが返す形)。 */
export interface ClassUsageScan extends SourceClassUsageScan {
    /** 走査したファイル (リポジトリ相対、ソート済み)。空なら呼び出し側が落とす */
    readonly files: readonly string[];
    /** `resources/js` の直下の子ごとの抽出件数 (どれかが丸ごと読めていない状態を捕まえる) */
    readonly perDirectory: ReadonlyMap<string, number>;
}

export function scanClassUsage(): ClassUsageScan;

/** 走査器が扱えない**既知の入口**の出現 (0 件であることを gate が固定する)。 */
export interface UnsupportedEntryPoint {
    readonly file: string;
    readonly kind: "class-directive" | "class-helper-library" | "interpolated-prefix";
}

export function unsupportedEntryPoints(): readonly UnsupportedEntryPoint[];
```

### 走査分母と走査根

**走査根は `resources/js` の 1 本**で、**全体を再帰走査**する
(Round 1 レビューの Critical: 固定 3 根 (`components` / `pages` / `lib`) は
実測で `app.ts` / `inertia.ts` / `vite-env.d.ts` / `types/` の 4 つを取り落としており、
docblock の「resources/js の走査分母」と食い違っていた。新しい直下ディレクトリからも迂回できる)。
走査根が存在しなければ **fail-fast** (`PrismDirectDispatchScanner::roots()` に倣う)。

**拡張子の全数分類** (未分類が現れたら不合格):

★照合は**最長接尾辞一致**である (Round 3 の Warning: `.d.ts` は `.ts` の接尾辞でもあり、
照合順が未定義だと `vite-env.d.ts` が走査対象に入る)。S8 のファイル種別分類と同じ規則を使う。

| 拡張子 | 扱い | 理由 |
|---|---|---|
| `.svelte` | 走査する | 画面のマークアップ |
| `.ts` | 走査する | variant 表・helper の class 文字列 |
| `.d.ts` | 走査しない | 型宣言のみ。class 文字列を持たない (**`.ts` より長いので先に一致する**) |
| `.gitkeep` | 走査しない | 空ディレクトリの目印 |

**`resources/js` 直下の子の全数分類** (新しい直下の子が現れたら不合格):

```ts
// tests/js/styles/inventory.ts
export const JS_SCAN_CHILD_CLASSIFICATION = {
    "components": { requiresOccurrences: true },
    "pages": { requiresOccurrences: true },
    "lib": { requiresOccurrences: false, reason: "テーマ名前空間の class トークンが実測 0 件" },
    "types": { requiresOccurrences: false, reason: "型定義のみで class 文字列を持たない" },
    // 直下のファイル (app.ts / inertia.ts / vite-env.d.ts) をまとめた 1 枠
    "(直下のファイル)": { requiresOccurrences: false, reason: "実測 0 件。起動と型宣言だけを持つ" },
} as const;
```

- `perDirectory` のキーは上の分類表のキーと**集合一致**する
  (分類していない子が現れても、分類したのに走査していない子があっても赤)。
- **`requiresOccurrences: true` の子だけ**が 0 でないことを gate が固定する
  (motivation の「ディレクトリごとに 1 件以上抽出できる」形)。
  **要求しない子に 0 件を強いない** — 0 件が正常なので、要求すると正常な状態を赤にする
  (Round 2 の Critical)。
- `resources/views/vendor/mail/html/themes/template.css` は**走査根の外**である。
  Laravel 同梱メールテーマの独立パレットで DS token の写像ではない
  (既に `contrast-invariant.test.ts` の docblock が同じ線引きを持つ)。

### deny する既知の入口 (実測で現状すべて 0 件)

| kind | 判定 | 現状 |
|---|---|---|
| `class-directive` | Svelte の `class:` に**識別子が直接続く**形 (`class:foo=` / `class:foo`)。`class: extraClass` (props の分割代入。コロンの後に空白) は**別物**なので当たらない | 0 件 |
| `class-helper-library` | `clsx` / `twMerge` / `tailwind-merge` / `classnames` / `cva` が区切りで分割したトークンとして現れる (import・呼び出しとも) | 0 件 |
| `interpolated-prefix` | テーマ名前空間の接頭辞の**直後**に補間が来る形 | 0 件 |

### 型適合チェック

- [x] 戻り値の型が明示されている
- [x] `null` 安全: `alpha` は `number | null` を明示し、`ScannedPair` の判別で分岐を強制する
- [x] 配列返却ではなく判別可能 union を返している (`ScannedPair`)
- [x] `UndecidableReason` の網羅を `switch` の default で `never` へ収束させる

### テスト計画

- [x] **先に赤くするテスト**: `class-usage.test.ts` に固定検体を置き、
      **純粋入口 `scanClassUsageSource(source, file)` へ直接渡して**
      「状態単位の組の作り方」を先に書く。実装前は import が解決せず**赤**
- [x] 字句の負例・正例 (Round 2 の Warning + Round 4 の Critical。
      **解析器に任せた結果がこうなること**を固定検体で確かめる):
  - コメントの中のリテラル (`// "bg-primary text-danger"`) は**拾わない**
  - エスケープした引用符 (`'it\\'s bg-primary'`) で文字列が途中で閉じない
  - 複数行のバッククォートリテラルを 1 単位として扱う
  - `${…}` を含む単位は `interpolated` の判定不能になる (通常リテラルに落とさない)
  - **補間の中に閉じ波括弧を含む文字列** (`` `${ cond ? "}" : x }` ``) を
    終端と誤認しない (**以降のソースを読み落とさない**)
  - **補間の中の object literal と入れ子 template** を終端と誤認しない
  - **未終端**のブロックコメント / 3 種のリテラル / template / 補間は**診断**になる
    (例外は投げない。当該ファイルの `occurrences` / `pairs` は空になる)
  - `.svelte` の parse 失敗は診断 `svelte-parse-failed` として残り、**gate が落ちる**
  - `.ts` の parse diagnostics (括弧の不整合など) は診断 `ts-diagnostic` として残る
  - 診断が出たファイルは `occurrences` / `pairs` が**空になる** (部分結果を返さない)
  - **補間内部の class 風文字列を二重に拾わない**
    (`` `${"bg-primary text-danger"}` `` から単位が 1 件も出ない)
- [x] **監視対象の判定** (Round 6 の Critical。`isWatchedCandidate()` の正負例):
  `"./Button.types"` / `"https://example.com/a"` / `"保存しました"` は**非監視** (無視される) /
  `bg-primaryあ` と `sm:bg-primaryあ` は**監視 → `unparsable-token`** /
  `text-center` は監視 → 契約表で解決 / `!bg-primary` と `sm:hover:bg-primary` は監視 → 解決
- [x] **variant の合成** (Round 5 の Warning + Round 6 の Critical。**4 形を別々に固定する**):
  1. 基底 + `hover:` (`"bg-surface hover:text-danger"`) → **解決可能** (`(danger, surface)`)
  2. 両 channel が同じ `hover:` (`"bg-surface text-text hover:bg-danger hover:text-neutral"`)
     → **解決可能**
  3. `sm:` + `sm:hover:` (`"bg-surface sm:bg-neutral sm:hover:text-danger"`) → **判定不能**
  4. `sm:` + `hover:` (`"bg-surface sm:bg-neutral hover:text-danger"`) → **判定不能**
     (同時成立を否定できない)
- [x] 不透明度修飾の端点 (Round 2 の Warning):
  `bg-primary/100` → `alphaPercent === null` (不透明) / `bg-primary/0` → `keyword-color` の判定不能 /
  `bg-primary/101` と `bg-primary/[0.35]` → `unsupported-alpha-syntax` の未解決
- [x] **拡張子の最長接尾辞一致** (Round 3 の Warning): `vite-env.d.ts` は走査対象外 /
      `app.ts` は対象 / `Badge.svelte` は対象、を固定検体で固定する
- [x] 負例 (共通規約 (c) / i18):
  - `"bg-surface text-danger hover:bg-danger hover:text-neutral"` から
    `(danger, surface)` と `(neutral, danger)` の**2 組だけ**が出る
    (`(danger, danger)` / `(neutral, surface)` が出たら赤)
  - **状態の継承の片側だけ上書き** (Round 1 レビューの Warning。上の検体は両方を上書きするので
    「継承していない実装」を検出できない):
    - `"text-text hover:bg-danger"` → `(text, danger)` が出る (前景を基底から継承する)
    - `"bg-surface hover:text-danger"` → `(danger, surface)` が出る (背景を基底から継承する)
  - 同じ状態に不透明な背景が 2 つ (`"bg-surface bg-neutral text-text"`) →
    `multiple-background` の判定不能になる (どちらが勝つかは生成 CSS の順で決まり静的に決められない)
  - 同じ状態に前景が 2 つ (`"bg-surface text-text text-danger"`) → `multiple-foreground`
  - **二重 alpha は判定不能にしない**: `"bg-primary-soft/40 text-text"` →
    `kind: "alpha-background"` / `modifierPercent === 40` の組が出る
    (実効値 0.048 = 0.12 × 0.40 を作るのは `resolveAlphaBackground()` だけである)
  - class トークンの分解: 接頭辞つき `sm:bg-primary` / 打ち消しつき `!bg-primary` /
    接尾辞つき `bg-primary/10` の**3 形**をそれぞれ正しく解決する
    (素の部分文字列一致だと 3 形が一緒に消える。共通規約 (e))
  - 非 ASCII の混入 (`bg-primaryあ`) は**候補全体**が `resolution.kind === "unresolved"` /
    `reason === "unparsable-token"` になる (**`bg-primary` へ縮退して通らない**ことを固定する)
  - `bg-(--var)` も**候補全体**が `unparsable-token` になる
  - **色でない utility への不透明度修飾**: `text-center/50` は
    `unresolved: "alpha-on-non-color"` になる (`text-center` として通さない)。
    一方 `sm:text-center` と `!text-center` は `utility === "text-center"` /
    `resolution.kind === "contract"` として**正しく解決する** (3 形を別々に固定する = 共通規約 (e))
  - deny 語彙 3 群それぞれについて、合成入力で `unsupportedEntryPoints()` が**検出する**
    (`class:foo={x}` / `clsx(...)` / 接頭辞の直後に補間) ことと、
    紛らわしい形 (`class: extraClass` / `flash-to-toast` / 補間が完成した class を差し込む形) を
    **誤検出しない**ことの両方向
  - `ramp` と整列語の取り違え: `text-body` / `text-center` を前景色として拾わない
  - **DESIGN.md のキーとの衝突**: `text-primary` は前景色 `primary`、
    `text-text` は前景色 `text` として解決する (`COLOR_TOKEN_MAP` の `text-primary` キーは
    本文色 = `--color-text` であって別物)
- [x] 正例: 実在する `atoms/Badge.types.ts` の**全 tone** / `atoms/Button.types.ts` の**全 variant** を
      (件数は散文に書かず `TONE_CLASSES` / `VARIANT_CLASSES` のキーから導出して)
      期待どおりの組へ分解する (**既知の要求組が抽出結果から実際に生成されること** = 正典 i15)
- [x] **分類分岐の点灯は固定検体で確かめる** (Round 1 レビューの Warning。
      実リポジトリに「不完全な単位が必ず存在する」ことを要求すると、コードが良くなって
      0 件になった正常状態を赤にしてしまう)。`incompleteOpaque.backgroundOnly` /
      `foregroundOnly` と、**`UndecidableReason` の全分類**は、それぞれ
      **合成入力で 1 件出る**ことを固定する。
      ★**分類数を散文に書かない** (Round 6 の Warning)。網羅は **`UNDECIDABLE_REASONS`
      (実行時の配列)** から機械的に導出し、「各 reason を発火させる検体が 1 つ以上ある」ことを検査する
- [x] **診断ゼロの正本は本 gate である** (Round 6 の Critical。積むだけで誰も見ない形にしない):
      `class-usage.test.ts` が実リポジトリ走査に対して
      `scanClassUsage().diagnostics` が**空**であることを要求する。
      S3 / S5 / S7 はこの保証に依存する (各節と責務境界表の行に明記する)
- [x] 空振り検知 (実リポジトリに対して要求するのはここまで):
      `files.length > 0` / `occurrences.length > 0` / `pairs.length > 0` /
      `perDirectory` の**「要求する」2 つ** (`components` / `pages`) がそれぞれ > 0
      (共通規約 (b) の 3 点目)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 状態単位の作り方が Tailwind の実際の勝敗 (生成 CSS の順序) と一致しない場合がある。
  → `atoms/input-state.ts` のコメントが既に「Tailwind は同一プロパティの utility が並んだ場合、
  勝敗が class 属性の順ではなく生成 CSS の順で決まる」と記録している。本走査器は
  **同じ状態に同じ channel の宣言が 2 つ以上ある単位**を
  `multiple-background` / `multiple-foreground` / `opaque-and-alpha-background` の
  **判定不能**として扱い、勝敗を勝手に決めずに素通りもさせない。
- 走査単位が「同じ要素」の近似であることは誇張しない (docblock に明記)。

---

## S4 線形化しきい値を 0.04045 へ揃える (i13)

### 変更箇所

- `tests/js/architecture/contrast-invariant.test.ts` (L45-49)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 同ファイルの負のコントロール (L146-153) に errata の裏取りを 1 行足す
- **共有パス**: このファイルは `docs/template-fingerprints.json` のキーに在り、
  `adoption-debt.tsv` にも在る → **S12 で決着させる**

### 現行コード

```ts
/** sRGB チャンネルの線形化 (WCAG 2.x 相対輝度の定義) */
function linearize(channel: number): number {
    const c = channel / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}
```

### 変更後コード

```ts
/**
 * sRGB チャンネルの線形化 (WCAG 2.x 相対輝度の定義)。
 *
 * しきい値は **0.04045** を使う。WCAG 2.0 / 2.1 本文の 0.03928 は
 * **2022-02-22 の errata で訂正済み**で、IEC 61966-2-1 (sRGB) の正しい値が 0.04045 である。
 * ★**8bit の色値では判定結果は変わらない** (境界は 0.03928*255 = 10.02 と
 *   0.04045*255 = 10.31 の間にあり、整数のチャンネル値 10 と 11 のどちらも
 *   両しきい値の同じ側に落ちる)。正しい方へ揃えるだけの変更である。
 */
export function linearizeChannel(c: number): number {
    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

function linearize(channel: number): number {
    return linearizeChannel(channel / 255);
}
```

★**正規化済みチャンネル (0..1) を受ける純粋関数 `linearizeChannel()` を切り出す**のが本施策の要点である
(Round 1 レビューの Critical: 8bit の全値で「両しきい値の判定が一致する」ことを確かめるだけの検査は
**実装本体を 1 度も呼ばない**ので、実装が 0.03928 のままでも緑になり、i13 を固定できない)。

負のコントロールへ追加する検査:

```ts
it("負のコントロール: 線形化のしきい値が errata 後の 0.04045 である", () => {
    // 2 つのしきい値の**間**の値でだけ実装の差が出る。
    //   c = 0.04 → 0.04045 実装は線形枝 = 0.04 / 12.92 = 0.0030959752321981426
    //              0.03928 実装は pow 枝  =              0.0030954995810608932
    // ★実装本体 (linearizeChannel) を呼ぶので、0.03928 のままならこの toBe が落ちる。
    expect(linearizeChannel(0.04)).toBe(0.04 / 12.92);
    // 両しきい値の外側では当然一致する (この it が「何でも通る」形でないことの裏取り)。
    expect(linearizeChannel(0.03)).toBe(0.03 / 12.92);
    expect(linearizeChannel(0.5)).toBeCloseTo(Math.pow((0.5 + 0.055) / 1.055, 2.4), 12);
});

it("補助: errata のしきい値の差が 8bit では判定を変えない", () => {
    // 「揃えたら結果が変わった」= どちらかの実装が間違っていたことになるので、
    // 変わらないことを 8bit の全チャンネル値で固定する (i18 の既知値)。
    // ★これは**性質の検査**であって実装のしきい値は固定しない (上の it が固定する)。
    for (let channel = 0; channel <= 255; channel += 1) {
        const c = channel / 255;
        expect(c <= 0.03928, `channel=${channel}`).toBe(c <= 0.04045);
    }
});
```

### 型適合チェック

- [x] 戻り値の型が明示されている / [x] `null` 安全 (数値のみ) / [x] 配列返却なし / [x] Generics なし

### テスト計画

- [x] **先に赤くするテスト**: 上の「線形化のしきい値が errata 後の 0.04045 である」を
      **先に書く**。現行実装 (0.03928 + `linearizeChannel` の切り出しなし) では
      **コンパイルエラー → 切り出し後は `toBe` の不一致**で赤になる。
      赤を確認してからしきい値を直す (テストファースト。思考原則 5)
- [x] 既存の 12 ペア + 負のコントロール 4 件が**同じ値で緑**であること (差が出ないことの実証)

### リスク

- 実質的な後退リスクは無い (8bit では判定不変)。値だけを直す変更なので、
  **仕組みが機能していない段階で値を弄るな**の原則には触れない (仕組みは既にある)。

---

## S10 不透明度修飾の生成形を契約として固定する (i6 の補強)

### 変更箇所

- `tests/js/styles/tokens.test.ts` (`UTILITY_CANDIDATES` に `alpha` 区分を追加 / describe を 1 つ追加)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし / テストファイル: 同ファイルのみ

### 変更後コード

```ts
const UTILITY_CANDIDATES = {
    color: /* 既存 */,
    radius: /* 既存 */,
    ramp: /* 既存 */,
    hover: /* 既存 */,
    /**
     * 不透明度修飾。**S5 (合成の検査) が置く前提「修飾は同じ色の alpha になる」の裏取り**。
     * 代表として不透明 token の /10、alpha を値に持つ派生 token の /40 (= 二重) を取る。
     */
    alpha: ["bg-primary/10", "bg-primary-soft/40"],
} as const;
```

```ts
/* ===== H. 不透明度修飾の生成形 (密閉の層) ===== */

describe("tokens/H: 不透明度修飾は同じ色の alpha として生成される", () => {
    /**
     * ★S5 の合成モデルはこの生成形を前提にしている。前提が版で変わったら
     *   ここが赤くなって「見直す契機」になる (正典 i16 が要求する形)。
     *
     * 実測 (Tailwind 4.3):
     *   .bg-primary\/10 {
     *       background-color: color-mix(in srgb, #1d4ed8 10%, transparent);
     *       @supports (color: color-mix(in lab, red, red)) {
     *           background-color: color-mix(in oklab, var(--color-primary) 10%, transparent);
     *       }
     *   }
     * fallback 側は**正本の hex をリテラルで埋め込む**ので、値の突き合わせも兼ねる。
     */
    it("不透明 token の /10 は正本の hex を 10% で透明と混ぜた形になる", () => {
        const decls = soleRule(sealed, ".bg-primary\\/10");
        // ★`Map#get` の undefined が文字列補間で "undefined" になり、
        //   「意図した解析失敗」ではなく「文字列が一致しないだけ」の赤に化けるのを防ぐ
        //   (Round 1 レビューの Warning)。不在は例外にする。
        const expected = requiredMapValue(designColors(), "primary", "DESIGN.md colors.primary");
        expect(requiredMapValue(decls, "background-color", ".bg-primary/10")).toBe(
            `color-mix(in srgb, ${expected} 10%, transparent)`,
        );
    });

    it("@supports の中は var() 参照の oklab 混色になる", () => {
        // 条件つき at-rule の中は soleRule が拾わないので、条件つきの側を明示的に見る。
        // 条件の綴りは allowlist と突き合わせる (D の ALLOWED_HOVER_CONDITIONS と同じ方針)。
        …
    });

    it("alpha を値に持つ派生 token への修飾は実効 alpha が積になる (S5 が合成対象にする根拠)", () => {
        const decls = soleRule(sealed, ".bg-primary-soft\\/40");
        const soft = requiredMapValue(cssColorTokens(), "primary-soft", "--color-primary-soft");
        expect(requiredMapValue(decls, "background-color", ".bg-primary-soft/40")).toBe(
            `color-mix(in srgb, ${soft} 40%, transparent)`,
        );
        // 透明との混色は乗算済み alpha なので、実効 alpha は token の alpha × 修飾の alpha に確定する。
        const parsed = parseCssColor(soft);
        expect(parsed.kind).toBe("alpha");
        if (parsed.kind !== "alpha") return;
        expect(parsed.alpha * 0.4).toBeCloseTo(0.048, 6);
    });

    /**
     * ★派生 token の**導出関係**を機械で固定する (Round 1 レビューの Critical)。
     *   `COMPILED_VALUE_EXEMPT_TOKENS` が免除しているのは「DESIGN.md に期待値が無い」
     *   ことの表明にとどまり、**別の rgba へ静かに差し替わる**ことまで許してはいない。
     *   これが無いと、S6 で primary を直したのに primary-soft を直し忘れた状態が
     *   (生成 CSS の出現とコントラストが偶然通れば) 検出できない。
     */
    it("--color-primary-soft は正本の primary の RGB を alpha 0.12 にしたものである", () => {
        const soft = parseCssColor(
            requiredMapValue(cssColorTokens(), "primary-soft", "--color-primary-soft"),
        );
        const primary = parseCssColor(
            requiredMapValue(designColors(), "primary", "DESIGN.md colors.primary"),
        );
        expect(soft.kind).toBe("alpha");
        expect(primary.kind).toBe("opaque");
        if (soft.kind !== "alpha" || primary.kind !== "opaque") return;
        expect(soft.rgb).toEqual(primary.rgb);
        expect(soft.alpha).toBe(0.12);
    });
});
```

`requiredMapValue()` は共有ヘルパとして `tests/js/styles/theme-map.ts` に置く
(`Map#get` の `undefined` を文字列補間で `"undefined"` に化けさせないため。
不在は**例外**にする = i20)。

### 型適合チェック

- [x] 戻り値の型が明示されている / [x] `null` 安全 (`soleRule` は 0 件も重複も落とす) /
      [x] 配列返却なし / [x] Generics なし

### テスト計画

- [x] **先に赤くするテスト**: 上の 4 it。`UTILITY_CANDIDATES.alpha` を足す前は
      `.bg-primary\/10` の規則が生成されないので `soleRule` が「1 件でない」で**赤**になる。
      派生の導出関係の it は、`--color-primary-soft` の RGB を 1 文字変えた検体で
      赤になることを確認してから実値へ戻す
- [x] 空振り防止: 既存の `it.each(Object.entries(UTILITY_CANDIDATES))` が
      新区分 `alpha` も 0 件でないことを自動で見る (区分を足すだけで検査が増える形)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- Tailwind の版が上がって生成形が変わると赤くなる。**それは緩める理由ではなく、
  合成モデルを見直す契機である** (同ファイル冒頭のリスク欄と同じ方針を明記する)。

---

## S5 半透明背景 × 不透明文字の合成検査を新設する (i16)

### 変更箇所

- `tests/js/architecture/contrast-invariant.test.ts` (合成関数と describe を追加 / docblock の
  「検査しないもの」を書き換え)
- `tests/js/styles/inventory.ts` (`ALPHA_PAIR_USAGE_LEDGER` / `ALPHA_CONTRAST_PAIRS` /
  `UNDECIDABLE_PAIR_LEDGER` を新設、
  `PENDING_CONTRAST_PAIRS` を書き換え)

### 波及変更

- TypeScript 型定義: `inventory.ts` に台帳の型を新設 (`UndecidableReason` を `class-usage.ts` から import)
- API Resource/DTO: なし
- テストファイル: `contrast-invariant.test.ts` のみ
- **共有パス**: `contrast-invariant.test.ts` → S12

### 現行コード

```ts
// tests/js/styles/inventory.ts (L97-101)
export const PENDING_CONTRAST_PAIRS = [
    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring",
    "alpha 合成ペア: Badge の bg-<tone>/10 + text-<tone>、bg-primary-soft、ring-primary/35、" +
        "bg-text/70 + text-surface (合成後の実効色が親背景に依存しトークン単体では定まらない)",
] as const;
```

### 変更後コード

```ts
// tests/js/styles/inventory.ts
/**
 * 半透明の背景 × 不透明な文字の組の台帳 (正典 i16)。
 *
 * ★**走査で見つかった半透明の組は全件がここに載る**ことを contrast-invariant が
 *   集合一致で固定する (件数だけの pin にしない = 新しい使用を件数更新で通せない)。
 * ★**下地は宣言しない**。実在する不透明な下地 = 役割分類の「面」(`surface` 役割を持つ token =
 *   `SURFACE_ROLE_TOKENS`) の**すべて**の上で 4.5:1 を要求するので、部品がどちらに置かれても成立する。
 * ★**「面」と「テキストを載せる塗り」は別物である** (思考原則 4)。
 *   `border` は Button の hover 塗りとしてテキストを載せるので
 *   `declared-text-background` の役割を持つが、**容器の背景として宣言された用途は無い**ので
 *   「面」ではなく、半透明の合成の**下地には数えない**。
 *   下地に数えると、実際には起きない重ね方 (ソフト背景のバッジを Button の hover 塗りの上へ置く)
 *   を根拠にテーマ値の是正を要求することになる。この線引きは**宣言であって導出ではない**ことを
 *   gate 本体に書く (静的走査は親要素を辿れない = 正典 i22 (2))。
 * ★行番号は持たない (正典 s14)。ファイル単位までである。
 * ★パスは**リポジトリ相対** (`resources/js/…`) で統一する。走査器の
 *   `ClassTokenOccurrence.file` / `perDirectory` のキーも同じ空間である
 *   (Round 2 の Warning: 型はリポジトリ相対、台帳例は走査根相対で食い違っていた)。
 * ★`fg` / `bg` の型は `CssColorSuffix` (下記の literal union) である。
 *   `readonly string[]` では取り違えが型で落ちないので、
 *   `COLOR_TOKEN_MAP` と `DERIVED_COLOR_TOKENS` から直接導出する。
 */
/**
 * ★**使用箇所の全数台帳**である (正典 i16「走査で見つかった半透明の組は
 *   全件が台帳に載ることを件数まで含めて要求する」)。走査結果と**集合 + 件数**で完全一致させる。
 * ★キーは **tokens.css の `--color-<suffix>` 空間** である (下の「2 つのキー空間」を参照)。
 * ★`modifierPercent` は **class 修飾の百分率だけ**である (実効値ではない)。
 *   `bg-primary-soft` は token の値が alpha 0.12 を持つので `null`、
 *   `bg-primary-soft/40` は `40` になる。実効値を作るのは `resolveAlphaBackground()` だけ。
 * ★行番号は持たない (正典 s14)。ファイル単位までである。
 */
export const ALPHA_PAIR_USAGE_LEDGER = [
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "danger", bg: "danger",
      modifierPercent: 10, count: 1 },
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "primary", bg: "primary-soft",
      modifierPercent: null, count: 1 },
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "success", bg: "success",
      modifierPercent: 10, count: 1 },
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "tertiary", bg: "tertiary",
      modifierPercent: 10, count: 1 },
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "warning", bg: "warning",
      modifierPercent: 10, count: 1 },
    { file: "resources/js/components/molecules/SubtitleOverlay.svelte", fg: "surface", bg: "text",
      modifierPercent: 70, count: 2 },
    { file: "resources/js/components/molecules/PendingInvitationsNotice.svelte",
      fg: "text", bg: "primary-soft", modifierPercent: 40, count: 1 },
    /* … 実装時に走査結果で確定させる (実測で 20 行前後) … */
] as const satisfies readonly AlphaPairUsage[];

/**
 * 上の台帳を `(fg, bg, modifierPercent)` へ**射影した一意な意味ペア**。
 * AA の `it.each` はこちらを回す (同じ意味ペアを 20 回検査しても情報は増えない)。
 * ★**「射影が一致する」という it は置かない** — 導出しているので恒真に近く、
 *   共通規約 (d)「集めた走査結果を判定に使わない形を作らない」の形骸化に当たる。
 *   代わりに**導出関数 `distinctPairs()` の仕様**を固定検体で固定する。
 */
export const ALPHA_CONTRAST_PAIRS: readonly AlphaPair[] = distinctPairs(ALPHA_PAIR_USAGE_LEDGER);

/**
 * 静的に組を決められなかった単位の台帳 (正典 i16「例外にして静かに素通りさせない」)。
 *
 * ★識別子は **(ファイル, 理由, 件数) の完全一致**である (Round 1 レビューの Critical:
 *   (ファイル, 理由) だけだと、同じファイルに同じ理由の未解析箇所が**増えても集合が変わらず**
 *   追加を検出できない)。**行番号は持たない** (正典 s14: 無関係な 1 行の追加でずれ、
 *   期待値の機械的な更新が常態化して統制が形骸化する)。
 * ★不透明のみの不完全な単位 (前景か背景の片方しか無い) は**ここに載せない** —
 *   `bg-surface` 単独が 39 単位・`bg-neutral` 単独が 20 単位あり、実体集合で pin すると
 *   期待値の機械的な更新が常態化して統制が形骸化する (正典 s14 と同じ理由)。
 *   そちらは「分類の全数性」を固定検体で受け、組そのものは i14 の役割直積が覆う。
 * ★`double-alpha` は**もう理由の値域に無い**。実効 alpha が積で確定するので
 *   使用箇所の台帳へ載せて計算する (i16 は「静的に決められない形」だけを例外にする)。
 */
export const UNDECIDABLE_PAIR_LEDGER = [
    { file: "resources/js/components/atoms/Button.types.ts", reason: "keyword-color", count: 2,
      note: "ghost / danger-ghost の bg-transparent。背景は親から来る" },
    { file: "resources/js/components/atoms/Button.types.ts", reason: "element-opacity", count: 2,
      note: "success / danger の hover:opacity-90 (要素全体の不透明度)" },
    { file: "resources/js/components/atoms/input-state.ts", reason: "interpolated", count: 1,
      note: "完成した class 文字列を補間で差し込む (border の状態)" },
    { file: "resources/js/components/atoms/input-state.ts", reason: "foreground-alpha", count: 1,
      note: "placeholder:text-text-secondary/70 (前景に不透明度修飾)" },
    { file: "resources/js/components/features/notifications/NotificationListItem.svelte",
      reason: "alpha-background-no-text", count: 1,
      note: "unread 時の bg-primary-soft/40 だけを持つリテラル (前景は別のリテラル)" },
    /* … alpha-background-no-text の残り 12 ファイル (実装時に走査結果で確定させる) … */
] as const satisfies readonly UndecidableEntry[];

/** 未検査であることを明示する pending 集合。**i16 の完了後も空にならない**。 */
export const PENDING_CONTRAST_PAIRS = [
    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring (正典 i17 により本 gate の対象外)",
    // ★列挙は `UNDECIDABLE_REASONS` (実行時の配列) から**生成する** (散文で数を書かない。
    //   分類を足したのに pending の説明が古いまま、という食い違いを作らない)。
    `UNDECIDABLE_PAIR_LEDGER に載せた分類: ${UNDECIDABLE_REASONS.map((r) => r.label).join(" / ")}。` +
        "値域の正本は UndecidableReason で、分類の全数性は contrast-invariant の it が" +
        "never への収束と「各 reason を発火させる検体が 1 つ以上ある」ことで固定する",
] as const;
```

```ts
// tests/js/architecture/contrast-invariant.test.ts (追加)

/**
 * 半透明の背景を不透明な下地の上へ合成する。
 *
 * 【本 gate が採用する**近似モデル** (版や環境で変わりうるので gate 本体に書く)】
 *   1. 不透明度修飾は `color-mix(…, transparent)` へ展開され、**透明との混色は
 *      同じ色の alpha になる** (透明側の乗算済み色が寄与しないため色相・明度は変わらない)。
 *      alpha を値に持つ token にさらに修飾が付く形は**実効 alpha が積**になる。
 *      生成形そのものは tokens.test.ts の「H. 不透明度修飾の生成形」が固定する
 *   2. 合成は**チャンネルごとの `a*FG + (1-a)*BG`** で、ガンマ符号化された sRGB 値を
 *      直接ブレンドする (web の既定)
 *   3. 比の計算に使うのは **8bit へ丸めた値**である。丸めまで再現しないと
 *      docs/design-system.md の記録値と 0.01 ずれる
 *   ★これは「ブラウザが必ずこう描く」という主張ではない (Round 1 レビューの Warning)。
 *     **本 gate が判定に使う近似**であり、近似が判定を変えていないことは
 *     「丸めない合成との比が 4.5 の境界を跨がない」検査が別に固定する。
 *   ★広い色域 (Display P3 等) の実描画との厳密一致は**測っていない** (正典の未決論点 q3)。
 */

/**
 * 合成の入力は**完全に正規化してから**渡す (Round 2 の Critical:
 * `ParsedColor` 自身の alpha と台帳の実効 alpha を関数が二重適用しうる形だった)。
 *
 * 正規化の規則は 1 本である —
 *   `effectiveAlpha = (token の値が持つ alpha ?? 1) × ((modifierPercent ?? 100) / 100)`
 * 3 形:
 *   不透明 token の `/10`                → 1 × 0.10 = 0.10
 *   値に alpha を持つ token (修飾なし)   → 0.12 × 1 = 0.12
 *   値に alpha を持つ token の `/40`     → 0.12 × 0.40 = 0.048
 */
interface ResolvedAlphaBackground {
    readonly rgb: Rgb;
    readonly effectiveAlpha: number;
}

/**
 * ★**token 固有 alpha と class 修飾を合成する唯一の場所**である。
 *   引数は**百分率** (`modifierPercent`)、戻り値は **0..1 の実効値** (`effectiveAlpha`) で、
 *   名前が単位を表す (Round 3 の Critical)。
 */
function resolveAlphaBackground(
    suffix: CssColorSuffix,
    modifierPercent: number | null,
): ResolvedAlphaBackground;

/** ★`ParsedColor` を直接受けない (alpha の出所を 1 つにする)。 */
function compositeOverOpaque(background: ResolvedAlphaBackground, base: Rgb): Rgb { … }

describe("architecture/contrast-invariant: 半透明背景 × 不透明文字 (面のすべての上で 4.5:1)", () => {
    it("走査で見つかった半透明の組と使用箇所台帳が (ファイル, 組, 修飾, 件数) で完全一致する", () => { … });

    it("判定不能の単位と台帳が (ファイル, 理由, 件数) の完全一致で揃う", () => { … });
    it("台帳の理由が UndecidableReason の値域に収まり、分類が全数である (never で収束)", () => { … });
    it("台帳の行が一意で、件数と修飾率が値域に収まる", () => {
        // ★集合 + 件数の比較は、同じキーを複数行へ分割したり count: 0 を登録したりすると
        //   正規化のしかた次第で意図しない一致が起きる (Round 4 の Warning)。
        //   キーの一意性と値域を独立した不変条件として固定する。
        expectUnique(ALPHA_PAIR_USAGE_LEDGER, (r) => [r.file, r.fg, r.bg, r.modifierPercent]);
        expectUnique(UNDECIDABLE_PAIR_LEDGER, (r) => [r.file, r.reason]);
        for (const r of [...ALPHA_PAIR_USAGE_LEDGER, ...UNDECIDABLE_PAIR_LEDGER]) {
            expect(Number.isInteger(r.count) && r.count > 0, `${r.file}: count`).toBe(true);
        }
        for (const r of ALPHA_PAIR_USAGE_LEDGER) {
            const m = r.modifierPercent;
            expect(m === null || (Number.isInteger(m) && m >= 0 && m <= 100)).toBe(true);
        }
    });
    it("distinctPairs の仕様 (重複除去・並び順・キー生成) を固定検体で固定する", () => {
        // ★「射影と ALPHA_CONTRAST_PAIRS が集合一致する」は導出しているので恒真に近い。
        //   共通規約 (d) の形骸化に当たるため置かず、導出関数そのものを固定する。
        … });
    it.each(ALPHA_CONTRAST_PAIRS)("%o が面のすべての上で 4.5:1 以上", ({ fg, bg, modifierPercent }) => {
        for (const base of SURFACE_ROLE_TOKENS) { … }
    });
    it("負のコントロール: 是正前の値では 5 組が AA を割る", () => {
        // 家系で実在した違反値を固定する (正典 i18 (d))。
        // primary #2563EB の 12% を neutral #F4F4F5 の上へ合成 → 4.01 で 4.5 を割る。
        expect(ratioOfComposite("#2563eb", "#2563eb", 0.12, "#f4f4f5")).toBeLessThan(4.5);
        // 是正後の値では通る。
        expect(ratioOfComposite("#1d4ed8", "#1d4ed8", 0.12, "#f4f4f5")).toBeGreaterThanOrEqual(4.5);
    });
    it("負のコントロール: 8bit の丸めを省くと記録値とずれる", () => { … });
    it("近似の裏取り: 丸めない合成との比が 4.5 の境界を跨ぐ組が無い", () => {
        // 8bit へ丸める近似が**判定そのものを変えていない**ことを固定する。
        // 跨ぐ組が現れたら、その組は近似の当否に判定が依存しているので、
        // 近似モデルの側を見直す契機になる (緩める理由にはしない)。
        for (const pair of ALPHA_CONTRAST_PAIRS) {
            for (const base of SURFACE_ROLE_TOKENS) {
                const rounded = ratioRounded(pair, base);
                const exact = ratioUnrounded(pair, base);
                expect(rounded >= 4.5, `${pair.fg} on ${pair.bg} over ${base}`).toBe(exact >= 4.5);
            }
        }
    });
});
```

### 2 つのキー空間 (取り違えの防止)

`inventory.ts` は**2 つのキー空間**を扱う。取り違えると別のトークンを検査してしまうので、
どちらの空間かを宣言ごとに docblock へ書き、境界は `COLOR_TOKEN_MAP` の 1 本だけにする。

**suffix 空間の literal union は導出する** (Round 2 の Warning: 現行の
`CSS_COLOR_SUFFIXES: readonly string[]` からは union を作れず、
「キーの取り違えが型で落ちる」という主張が成立していなかった):

```ts
type CanonicalColorSuffix = (typeof COLOR_TOKEN_MAP)[keyof typeof COLOR_TOKEN_MAP];
type DerivedColorSuffix = (typeof DERIVED_COLOR_TOKENS)[number];
export type CssColorSuffix = CanonicalColorSuffix | DerivedColorSuffix;

/**
 * ★**台帳は実効値を持たない** (Round 3 の Critical: 実効値を持つと
 *   `resolveAlphaBackground()` へ渡したときに token 固有 alpha が二重に掛かる読み方ができた)。
 *   持つのは **class 修飾の百分率だけ**で、token 固有 alpha と合成して実効値を作るのは
 *   `resolveAlphaBackground()` **1 か所だけ**である。
 */
export interface AlphaPair {
    readonly fg: CssColorSuffix;
    readonly bg: CssColorSuffix;
    /** class 修飾の百分率 (0..100)。`bg-primary-soft` のような修飾なしは `null` */
    readonly modifierPercent: number | null;
}

/** 使用箇所の全数台帳の 1 行 (正典 i16 の「全件が台帳に載ることを件数まで」)。 */
export interface AlphaPairUsage extends AlphaPair {
    /** リポジトリ相対パス。**行番号は持たない** (正典 s14) */
    readonly file: string;
    /** そのファイルでの出現数 (完全一致で固定する) */
    readonly count: number;
}
```

| 空間 | 使う宣言 | 例 |
|---|---|---|
| **DESIGN.md の色キー** (13 件) | 役割分類 (`COLOR_TOKEN_ROLES` と、そこから導出する `SURFACE_ROLE_TOKENS` / `TEXT_ON_SURFACE_TOKENS` / `FILL_TOKENS` / `FILL_LABEL_TOKENS` / `NON_TEXT_BOUNDARY_REASONS` / `DECLARED_CONTRAST_PAIRS`) | `text-primary` = **本文色** |
| **tokens.css の `--color-<suffix>`** (14 件) | 半透明の台帳 (`ALPHA_PAIR_USAGE_LEDGER` / `ALPHA_CONTRAST_PAIRS`)、走査器の出力、生成 CSS 検査 | `text` = 本文色 / `text-primary` は**存在しない** |

- 派生トークン `primary-soft` は**DESIGN.md に無い**ので、半透明の台帳は
  suffix 空間で書かなければ表現できない (これが空間を分ける実質的な理由である)。
- **走査器 (`class-usage.ts`) は suffix 空間だけを返す**。役割の母集団と突き合わせるときに
  gate が `COLOR_TOKEN_MAP` の逆写像で DESIGN キー空間へ写す。
- 逆写像が一意であること (`COLOR_TOKEN_MAP` の値に重複が無いこと) は
  **S1 で `canonical-source-parity.test.ts` に it を 1 本足して固定する**
  (重複があると逆写像が後勝ちになり、別のトークンの値で検査してしまう)。

### 型適合チェック

- [x] 戻り値の型が明示されている
- [x] `null` 安全: `hex()` は不在で例外 (既存の形を踏襲)
- [x] 配列返却ではなく `as const satisfies` の台帳 (キーの取り違えが型で落ちる)
- [x] `UndecidableReason` の網羅を `never` へ収束させる

### テスト計画

- [x] **先に赤くするテスト**: `it.each(ALPHA_CONTRAST_PAIRS)` の AA 検査。
      **S6 (値の是正) の前なので 5 組が実際に赤くなる** — これが本設計の
      「実測が設計の見込みを覆した」記録そのものである
- [x] 集合一致の 2 it も、台帳を空で置いた状態で**先に赤**を確認する
- [x] 負のコントロール: 是正前の値で落ちること / 是正後の値で通ること / 丸めを省くとずれること
- [x] **実効 alpha の正規化を固定検体で 3 形とも固定する** (Round 2 の Critical):
      `resolveAlphaBackground("primary", 10).effectiveAlpha === 0.1` /
      `resolveAlphaBackground("primary-soft", null).effectiveAlpha === 0.12` /
      `resolveAlphaBackground("primary-soft", 40).effectiveAlpha` が 0.048 (0.0144 ではない)。
      ★台帳が持つのは `modifierPercent` だけなので、**実効値を台帳から渡す経路が型で存在しない**
      (Round 3 の Critical への構造的な対処)
- [x] 既存テストの削除・上書きをしない:
      「未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない」は**据え置く**
      (i17 の 1 行と判定不能の 1 行が残るので空にならない)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 台帳が 20 行前後になり、初見では冗長に見える。
  → 「不透明のみの不完全な単位は載せない」線引きを docblock に書き、
  台帳が肥大しないことを構造で担保する。
- 走査結果と台帳の集合一致は、**新しいソフト背景を足すたびに台帳の更新を要求する**。
  これは意図した摩擦である (正典 i16 の「全件が台帳に載る」)。

---

## S6 トークン値を是正する (i16 の帰結)

### 変更箇所

- `DESIGN.md` frontmatter L6-9 / L16-17 (色値 6 件)
- `DESIGN.md` L71-72 (§Overview の色記述) / L79 / L82 / L100 / L102 (§Colors・§状態色の本文)
- `DESIGN.md` L107-110 (**§状態色の規約文の改定**)
- `DESIGN.md` L112-114 付近 (**ソフト背景の置き場の規約行を追加**)
- `resources/css/tokens.css` L13-17 / L28-29 (色値 6 件 + `--color-primary-soft`)

### 波及変更

- TypeScript 型定義: なし (値だけの変更)
- API Resource/DTO: なし
- テストファイル: `canonical-source-parity` の値一致 / `tokens` の値検査 /
  `contrast-invariant` の不透明ペアと半透明ペアが**自動で追随する**
  (どれも DESIGN.md から導出しているので期待値の手書きは 1 か所も無い)
- `docs/design-system.md`: 値は書かれていないので更新不要 (grep で確認済み)。
  ただし §テーマの差し替え方の手順に「合成の検査も通ること」を 1 行足す (S11 に含める)
- **メールテンプレート** `resources/views/vendor/mail/html/themes/template.css` は
  独立パレットなので**追随させない** (DS token の写像ではない。既存の線引きどおり)

### 現行コード / 変更後コード

| 位置 | 現行 | 変更後 |
|---|---|---|
| `DESIGN.md:6` / `tokens.css:13` | `#2563EB` | `#1D4ED8` (blue-700) |
| `DESIGN.md:7` / `tokens.css:14` | `#1D4ED8` | `#1E40AF` (blue-800) |
| `DESIGN.md:8` / `tokens.css:16` | `#0F766E` | `#115E59` (teal-800) |
| `DESIGN.md:9` / `tokens.css:17` | `#115E59` | `#134E4A` (teal-900) |
| `DESIGN.md:16` / `tokens.css:28` | `#15803D` | `#166534` (green-800) |
| `DESIGN.md:17` / `tokens.css:29` | `#B45309` | `#92400E` (amber-800) |
| `tokens.css:15` | `rgba(37, 99, 235, 0.12)` | `rgba(29, 78, 216, 0.12)` |
| `DESIGN.md:18` / `tokens.css:30` | `#B91C1C` | **据え置き** (soft でも 4.98 で足りる) |

§状態色の規約文 (現行 L107-110):

```markdown
状態色・アクセントは Tailwind の **-700 段**で揃える(`tertiary` teal-700 / `success` green-700 /
`warning` amber-700 / `danger` red-700)。`neutral`(#F4F4F5)や `surface`(#FFFFFF)の上で
**本文コントラスト 4.5:1** を確保するための下限であり、これより明るい段は使わない
(`tests/js/architecture/contrast-invariant.test.ts` が機械検証する)。
```

変更後:

```markdown
状態色・アクセントの段は**段の名前ではなくコントラストの実測で決める**。満たすべき条件は 2 つで、
**面として分類した token の上で本文コントラスト 4.5:1** と、
**同じ色のソフト背景(不透明度 10〜12%)の上でも 4.5:1** である。後者が効くため、
実際に選べるのは概ね **-800 段**になる(既定テーマは `tertiary` teal-800 / `success` green-800 /
`warning` amber-800 / `danger` red-700 で、`danger` だけは -700 でも両条件を満たす)。
**段を機械的に揃えるのではなく、`tests/js/architecture/contrast-invariant.test.ts` の
実測で決めること**(不透明ペアと半透明ペアの両方を機械検証する)。

ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
`bg-primary-soft` 等)。**ソフト背景の部品は面として分類した token の上にのみ置く**
(既定テーマでは `neutral` / `surface`)。塗り面(`bg-primary` 等)の上へ重ねると
合成後の実効色が前景と同色になり、どの値を選んでも 4.5:1 を満たせない
(静的走査は親要素を辿れないため、この規約は機械では部分的にしか保証されない —
保証範囲は contrast gate の docblock が持つ)。
```

### 型適合チェック

- [x] 該当なし (値のみ。TypeScript の変更なし)

### テスト計画

- [x] **順序が本質**: S5 で `it.each(ALPHA_CONTRAST_PAIRS)` の 5 組が**赤**であることを
      確認した後に本施策で値を変える (テストファースト。思考原則 5)
- [x] 是正後に緑になる範囲を実測で確認済み ([contrast-measurements.md](./contrast-measurements.md))
- [x] `canonical-source-parity` の値一致 / `tokens/A` の値検査が
      **DESIGN.md と tokens.css の両方を直さないと赤**であること (片側だけの変更を落とす既存機構)
- [x] **派生 token の追随は機械で保証する**: S10 が足す
      「`--color-primary-soft` は正本の primary の RGB を alpha 0.12 にしたもの」の it が、
      `primary` を直して `primary-soft` を直し忘れた状態を**赤**にする
      (Round 1 レビューの Warning。値免除の穴を塞ぐ)
- [x] 逆引き表 ([token-change-impact.md](./token-change-impact.md)) の 131 行で、
      非テキスト用途 (`border-*` / `ring-*` / `decoration-*` / `accent-*`) と
      テキストを載せない塗り面 (Toggle トラック / アイコン帯) を目視レビューする
- [x] **目視確認する画面** — ブランド色を動かすので、逆引き表の机上確認だけで終わらせない:
  1. 撮影画面のガイド帯・字幕帯 (`features/capture/ShootingGuideOverlay` /
     `molecules/SubtitleOverlay`。`bg-text/70` + `text-surface`)
  2. 通知一覧の未読行 (`features/notifications/NotificationListItem`。
     `bg-primary-soft/40` と `bg-primary-soft` + `text-primary`)
  3. Badge の**全 tone** を並べて出す画面 (`pages/Welcome.svelte` の状態表示)。
     確認対象の tone は**散文に数を書かず `TONE_CLASSES` のキーから導出する**
     (Round 2 の Warning: 「5 tone」はソフト背景を持つ tone の数、
     「6 tone」は `BadgeTone` の全数で、散文が 2 つの数を混ぜていた)
  4. サイドバーの選択中 (`templates/AppLayout` / `templates/_helpers/SidebarNavItems`。
     `bg-primary` + `text-surface`)
  5. 料金ページの強調カード (`pages/Guest/Pricing.svelte`。`border-primary/30` + `bg-primary-soft`)
  6. **主要 Button の disabled 状態** (`atoms/Button.svelte` の primary / danger。
     `opacity-40` が変更後の塗りへ掛かる)

### リスク

- **ブランド印象が変わる** (primary が blue-600 → blue-700)。
  → i1 によりテーマ値はプロジェクト裁量であり、正典が値を定めているわけではない。
  変更理由は「i16 を満たすための帰結」であり、規約文の改定として DESIGN.md に記録する。
  家系の先行事例 (motivation:T194) は同じ方向・同じ段へ動いている。
- **hover の視認性**: `primary` と `primary-hover` の差が blue-700 → blue-800 になり、
  明度差は現行 (blue-600 → blue-700) と同程度に保たれる (逆引き表で確認)。
- **disabled の見え方は是正対象 token に依存する** (Round 2 の Warning で訂正)。
  `opacity-40` は要素全体に掛かるので、**変更後の `bg-primary` へ**適用される。
  SC 1.4.3 は無効化された UI 部品を適用除外にしているので**機械検査の対象ではない**が、
  ブランド変更による視覚的後退が無いことは別の問題なので、
  **主要 Button の disabled 状態を目視確認対象へ加える** (下の目視確認 6 面目)。

---

## S3 参照の閉包 gate を新設する (i9)

### 変更箇所

- 新規: `tests/js/styles/token-reference-closure.test.ts`
- `tests/js/styles/inventory.ts` (`NON_TOKEN_WORD_CONTRACT` を新設)
- `resources/js/components/templates/AppLayout.svelte` (L299 / L427: `text-white` → `text-surface`)
- `resources/js/components/templates/_helpers/SidebarNavItems.svelte` (L38: 同上)

### 波及変更

- TypeScript 型定義: 契約表の型を新設
- API Resource/DTO: なし
- テストファイル: `contrast-invariant.test.ts` の逆向き被覆に `(surface, primary)` が現れる
  (S7 で `surface` を `FILL_LABEL_TOKENS` へ足すことで直積の内側に入る)
- **見た目の変化は無い**: `--color-surface` は `#FFFFFF` で `text-white` と同色

### 変更後コード

```ts
// tests/js/styles/inventory.ts
/**
 * **token を指さない語**の契約表 (正典 i9)。
 *
 * ★これは許可一覧ではなく**検査対象の定義**である。テーマの名前空間の接頭辞を持つ語のうち、
 *   写像の宣言集合へ解決しないものは**全数がここに登録されていなければ不合格**になる。
 * ★Tailwind 既定テーマの色語 (`white` / `black` / raw palette) は**登録しない** —
 *   写像の外の token 空間を参照する形なので落とすのが正しい
 *   (実在した `text-white` 3 箇所は本施策で `text-surface` へ直す)。
 * ★**チャネルを型で分ける** (Round 1 レビューの Warning)。class の語と `var()` 参照を
 *   同じ無型の表へ入れると、**別のチャネルでの出現によって登録が生きているように見える**
 *   (`--app-sidebar-w` が class 語として出現しなくなっても、`var()` 側の出現で
 *   冗長判定をすり抜ける)。出現の突き合わせと冗長判定は**チャネル別**に行う。
 * ★登録するのは**正規化後の有効な完全 token** である。`text-center/50` のような
 *   「色でない utility に不透明度修飾が付いた形」は走査器が
 *   `unresolved: "alpha-on-non-color"` にするので、**契約表に登録しても救われない**。
 */
export type NonTokenWord =
    | { readonly kind: "class-word"; readonly word: string; readonly reason: string }
    | { readonly kind: "css-variable"; readonly name: string; readonly reason: string };

export const NON_TOKEN_WORD_CONTRACT = [
    { kind: "class-word", word: "bg-transparent", reason: "CSS の全域キーワード。色 token を指さない" },
    { kind: "class-word", word: "border-transparent",
      reason: "同上。全 variant で外形高さを揃えるための透明枠 (DESIGN.md §Components)" },
    { kind: "class-word", word: "border-2", reason: "境界の太さ。色ではない" },
    { kind: "class-word", word: "border-b", reason: "境界の辺の指定。色ではない" },
    { kind: "class-word", word: "border-b-0", reason: "同上 (打ち消し)" },
    { kind: "class-word", word: "border-l-2", reason: "同上" },
    { kind: "class-word", word: "border-r", reason: "同上" },
    { kind: "class-word", word: "border-t", reason: "同上" },
    { kind: "class-word", word: "border-dashed", reason: "境界の線種。色ではない" },
    { kind: "class-word", word: "divide-y", reason: "区切り線の軸。色ではない (色は divide-border が持つ)" },
    { kind: "class-word", word: "outline-none", reason: "outline の打ち消し。色ではない" },
    { kind: "class-word", word: "ring-2", reason: "focus ring の太さ。色ではない" },
    { kind: "class-word", word: "ring-3", reason: "同上" },
    { kind: "class-word", word: "rounded-full",
      reason: "角丸 ramp の外の真円 UI。radius token を指さず ds-purity の file-scoped allowlist が管轄する" },
    { kind: "class-word", word: "text-center", reason: "テキストの整列。色でも ramp でもない" },
    { kind: "class-word", word: "text-left", reason: "同上" },
    { kind: "class-word", word: "text-right", reason: "同上" },
    { kind: "css-variable", name: "--app-sidebar-w",
      reason: "同一要素の style 属性で宣言する局所変数。@theme の token ではない " +
              "(他ファイルのローカル宣言を解決の根拠に数えない)" },
] as const satisfies readonly NonTokenWord[];
```

```ts
// tests/js/styles/token-reference-closure.test.ts (新設)
/**
 * 参照の閉包 (正典 i9) — 自リポジトリのスタイルと画面のコードが参照する token 名が、
 * すべて写像 (resources/css/tokens.css の @theme) の宣言集合へ解決することを検査する。
 *
 * 【なぜ要るか】綴り誤りは「無スタイル」として静かに消える。Tailwind は未知の utility を
 *   エラーにせず、単に生成しない。
 * 【解決の根拠は写像 1 か所だけ】他ファイルのローカル宣言 (style 属性 / 別 CSS の :root) を
 *   根拠に数えると、正本の外に token 空間が静かに育つ形が通ってしまう。
 * 【走査対象】
 *   - resources/js: 文字列リテラルの中の class トークン (class-usage.ts と同じ走査単位)
 *   - resources/js / resources/css: `var(--…)` 参照
 * 【保証しないもの】
 *   - resources/views 配下 (Laravel 同梱メールテーマの独立パレット) は対象外
 *   - 変種の修飾の綴り (`hoverr:`) は見ない (Tailwind の名前空間で写像ではない)
 *   - 走査単位の外 (動的に組み立てた class) は見ない。既知の入口は class-usage.ts が deny する
 */
```

検査項目:

1. `scanClassUsage().occurrences` のうち `resolution.kind === "unresolved"` が **0 件**であること。
   すなわち、テーマ名前空間の接頭辞を持つ class トークンはすべて
   **写像の宣言集合 / ramp 集合 / radius 集合 / 契約表 (`class-word`)** のいずれかへ解決する。
   ★走査器は S2 の 1 本だけを使う (2 本目のパーサを書かない = i21)
2. `scanCssVarReferences()` のうち `unresolved` が **0 件**であること
   (**写像の宣言集合か契約表 (`css-variable`)** へ解決する)。
   ★`var()` 参照の走査根は **`resources/js` と `resources/css` の 2 本**である
   (class トークンの走査根が `resources/js` の 1 本であることとは**別の契約**。
   Round 3 の Warning: S2 と S3 で走査根の説明が食い違っていた)。
   **2 根とも存在すること**と**それぞれ列挙したファイル数が 0 でないこと**を gate が固定する。
   ★**根ごとの参照件数の非空は要求しない** (Round 4 の Warning:
   参照を正当に消しただけで赤くなる)。要求するのは**参照の総数が 0 でないこと**だけで、
   これは「アプリのスタイルが token を 1 つも参照しないことは無い」というドメインの不変条件である。
   ソース解析の本体は純粋入口 `scanCssVarReferencesSource(source, file)` を共有する。
   ★**入力は解析器の出力に限る** —
   `resources/css` は **postcss AST の `Decl.value` と対象 at-rule の `params` だけ**、
   `resources/js` は **S2 が確定した AST 上の文字列だけ**。

   **結果型に診断を持たせる** (Round 6 の Critical。参照配列だけでは診断を実装できない):

   ```ts
   export interface CssVarReferenceScan {
       readonly references: readonly CssVarReference[];
       readonly diagnostics: readonly CssVarReferenceDiagnostic[];
   }
   export type CssVarDiagnosticReason =
       | "unterminated-string"
       | "unterminated-function"
       | "unresolvable-var"
       | "unsupported-at-rule-params";
   ```

   **値走査の受理契約** (Round 6 の Critical。括弧カウントだけの実装にしない):
   1. **コメントは postcss が `Decl.value` から既に除いている** (実測:
      `color: var(--a /* c */)` → `value === "var(--a )"`、原文は `raws.value.raw`)。
      よって **`raws.value.raw` は使わない**
   2. 値を左から 1 文字ずつ走査する。`'` / `"` で始まる区間は**エスケープ (`\`) を尊重して**読み飛ばす
   3. 閉じない引用は診断 `unterminated-string`
   4. 引用区間の**外**で **`var` の関数トークン**を見つけたら括弧の対応を数えて引数列を取る。
      ★**関数トークンの境界を定義する** (Round 7 の Warning。部分文字列一致にしない) —
      `var` の直前の文字が識別子文字 (`[A-Za-z0-9_-]`) でも `\` でもなく、
      直後が `(` であること。`myvar(--x)` は**関数トークンではない**ので参照に数えない
   5. 引数列は**最初のトップレベルのカンマ** (括弧の深さ 0・引用区間の外) で
      「名前」と「fallback 全体」に分ける。カンマが無ければ fallback は無い
   6. 名前は前後の空白を除いた**全体**が `^--[A-Za-z0-9_-]+$` に一致すること。
      一致しなければ診断 `unresolvable-var` (`var(--x garbage)` はここで落ちる)
   7. fallback 全体は同じ規則で**再帰的に**走査する (中の `var()` を拾う)
   8. 閉じない括弧は診断 `unterminated-function`

   **参照母集団に含める at-rule** は `@media` / `@supports` / `@container` の 3 つに限定して列挙する
   (条件式に `var()` を書ける at-rule)。**列挙外の at-rule の params に `var(` が現れたら
   診断 `unsupported-at-rule-params`** にする (無視しない = fail-closed)。

   正負例: `content: "var(--x)"` は参照 0 件 / `color: var(--a /* c */)` は `--a` を 1 件 /
   `var(--a, var(--b))` は 2 件 / `var(--a` は診断 `unterminated-function` /
   `--f: "a,b", c` は参照 0 件・診断 0 件 / `@media (min-width: var(--x))` は参照 1 件 /
   `@page { … var(--x) … }` は診断 `unsupported-at-rule-params` /
   **`myvar(--x)` は参照 0 件・診断 0 件** (関数トークンの境界) /
   **`var(--x garbage)` は診断 `unresolvable-var`** /
   **`var(--a, b, c)` は名前 `--a` + fallback `b, c`** (最初のトップレベルのカンマで分ける)
3. **契約表に冗長な登録が無い**。判定は**チャネル別**に行う —
   `class-word` の登録は class トークンとして 1 回以上出現し、かつ写像へは解決しないこと。
   `css-variable` の登録は `var()` 参照として 1 回以上出現し、かつ写像へは解決しないこと
4. **母集団が空でない** (class トークン数 > 0 / `var()` 参照の**総数** > 0 /
   走査根が 2 本とも存在し、列挙したファイル数がそれぞれ > 0)。
   **`scanCssVarReferences().diagnostics` が空である** (本 gate が CSS var 診断の消費先である)。
   ★**契約表のチャネルごとの非空は要求しない** (最後の局所変数の例外を解消した
   正常な状態を赤にしないため)。チャネルごとの判定分岐は**固定検体で点灯**させる
5. 負のコントロール (固定検体):
   - `text-white` を含む検体 → 不合格になる (**Tailwind 既定テーマの色語を通さない**)
   - `bg-primaryy` (綴り誤り) → 不合格になる
   - `var(--color-does-not-exist)` → 不合格になる
   - 別ファイルの `:root` に `--color-foo` を宣言した検体 → **解決の根拠に数えない**
     (写像 1 か所だけという境界そのものを pin する)
   - 契約表の語 (`text-center` 等) は誤検出しない
   - **変種 / 重要度 / 不透明度の 3 形を別々に固定する** (共通規約 (e)) —
     接頭辞つき `sm:text-center` は解決する / 打ち消しつき `!text-center` は解決する /
     **接尾辞つき `text-center/50` は不合格**になる
     (色でない utility への不透明度修飾を「同じ語」として通すと、未知の utility が静かに通る)
   - `css-variable` の登録語を class トークンとして書いた検体 (`--app-sidebar-w` を class に置く) →
     **チャネルが違うので解決の根拠にならず不合格**になる

### 型適合チェック

- [x] 戻り値の型が明示されている / [x] `null` 安全 (解決失敗は結果に残して gate が落とす) /
      [x] 配列返却ではなく union / [x] Generics 正しい

### テスト計画

- [x] **先に赤くするテスト**: 検査 1。`text-white` が 3 箇所あるので**実装した時点で赤**になる。
      赤を確認してからアプリ側 3 箇所を直す (テストファースト。バグ修正の再現テストと同じ形)
- [x] 検査 3 (冗長な登録) を先に書き、契約表を空で置いて赤 → 埋めて緑
- [x] 負のコントロール 6 種を固定検体で置く (一時的に壊す形では代替しない = 正典 i18)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 契約表が Tailwind の構造 utility を足すたびに増える。
  → 増えるのは**テーマ名前空間の接頭辞を持つ語だけ**である (実測 17 件)。
  `flex` / `px-3` / `gap-2` のような語は接頭辞を持たないので母集団に入らない。
  この限定を docblock に書く。
- `text-white` → `text-surface` の置き換えでサイドバー選択中の見た目が変わらないこと
  (`--color-surface` = `#FFFFFF`) を実装時に目視で確認する。

---

## S7 実装からの逆向き被覆と役割分類の是正 (i15 / i14)

### 変更箇所

- `tests/js/styles/inventory.ts`
  (**`COLOR_TOKEN_ROLES` を新設して 5 つの役割配列をそこから導出する** /
  `CONTRAST_EXEMPT_TOKENS` を `NON_TEXT_BOUNDARY_REASONS` へ作り替える /
  `DECLARED_CONTRAST_PAIRS` を新設)
- `tests/js/architecture/contrast-invariant.test.ts` (逆向き被覆の describe を追加 /
  役割分類の全数性の it を個別宣言ペアまで含む形へ拡張)

### 波及変更

- TypeScript 型定義: `DeclaredPair` を新設
- API Resource/DTO: なし
- テストファイル: `contrast-invariant.test.ts` のみ。既存の 4 it と `it.each(PAIRS)` は据え置く
- **共有パス**: `contrast-invariant.test.ts` → S12

### 現行コード

```ts
// tests/js/styles/inventory.ts (L59-85)
export const FILL_TOKENS = ["primary","primary-hover","tertiary","tertiary-hover","success","warning","danger"] as const;
export const FILL_LABEL_TOKENS = ["neutral"] as const;
export const CONTRAST_EXEMPT_TOKENS = {
    "border": "1px の区切り線・入力欄の枠。テキストではなく WCAG 1.4.11 (非テキスト 3:1) の領域。…",
    "border-strong": "区切りの強調・ghost ボタンの枠。…",
} as const;
```

### 走査で判明した役割分類の食い違い 2 件と、その決着

| 食い違い | 実測 | 決着 |
|---|---|---|
| `bg-border` が**テキストを載せた塗り面**として使われている (`atoms/Button.types.ts` の neutral variant の hover) のに、`border` は 1.4.11 の免除に入っている | `text-text` on `bg-border` = 13.96 で AA を満たす | **`border` を免除から外し、個別宣言ペアで受ける**。`FILL_TOKENS` には**入れない** |
| `text-surface` が塗り面のラベルとして使われている (字幕帯 / 撮影中バッジ / サイドバーの選択中) のに、`surface` は `FILL_LABEL_TOKENS` に無い | `surface` × 全塗り面 = 6.70〜9.48 で全組が AA を満たす (是正後の値) | **`FILL_LABEL_TOKENS` へ `surface` を追加する** (直積が全組成立するので直積で受けられる) |

**`border` を `FILL_TOKENS` へ入れない理由 (設計判断)**: 入れると直積に
`neutral on border` (**1.15**) と `surface on border` (**1.27**) が生まれるが、
**この 2 組は実装に 1 件も存在しない** (`bg-border` の上に載るのは `text-text` だけである)。
実在しない組を検査すると誤検知になる。正典 i14 は
「役割の直積で表現できない正当な 1 対 1 の組は**個別宣言ペア**として理由つきで足し、
直積と同じ閾値を課す」と定めており、これはまさにその用途である。
**逆に `surface` は直積が全組成立するので直積側で受ける** — 個別宣言ペアは
「直積で表現できないもの」に限る (安易に個別宣言へ逃がすと母集団が痩せる)。

### 変更後コード

```ts
// tests/js/styles/inventory.ts

/**
 * 色 token の役割。**1 つの token が複数の役割を持ちうる** (思考原則 4: 別物の用途を統合しない)。
 *
 * ★Round 1 レビューの Critical を受けた作り直しである。旧設計は
 *   「個別宣言ペアに現れた token を役割分類済みと数える」形だったため、
 *   **任意の新 token を 1 組だけ登録すれば全色被覆の既定拒否を通せる**穴があった。
 *   役割の全数性は本表のキーと DESIGN.md の色キーの集合一致だけで見る。
 */
export type ColorRole =
    /** 面 = 容器の背景。**半透明の合成の下地でもある** (i16) */
    | "surface"
    /** 面の上に載るテキスト色 */
    | "text-on-surface"
    /** 塗り面 (solid fill) */
    | "fill"
    /** 塗り面の上に載るラベル色 */
    | "fill-label"
    /** 直積で表現できない、テキストを載せる塗り (個別宣言ペアの背景側にだけ現れる) */
    | "declared-text-background"
    /** 1px 境界・focus ring 等。WCAG 1.4.11 の別の閾値体系なので本 gate の対象外 (i17。理由必須) */
    | "non-text-boundary";

/**
 * ★**役割分類の唯一の宣言**。既存の 5 つの配列は**ここから導出する** (i4)。
 * ★キーは **DESIGN.md の色キー空間**である (`text-primary` は本文色 = `--color-text`)。
 */
export const COLOR_TOKEN_ROLES = {
    "primary": ["text-on-surface", "fill"],
    "primary-hover": ["fill"],
    "tertiary": ["text-on-surface", "fill"],
    "tertiary-hover": ["fill"],
    "neutral": ["surface", "fill-label"],
    "surface": ["surface", "fill-label"],
    // ★2 役割を持つ: 1px 枠 (対象外) と、Button の neutral variant の hover 塗り (検査する)
    "border": ["non-text-boundary", "declared-text-background"],
    "border-strong": ["non-text-boundary"],
    "text-primary": ["text-on-surface"],
    "text-secondary": ["text-on-surface"],
    "success": ["text-on-surface", "fill"],
    "warning": ["text-on-surface", "fill"],
    "danger": ["text-on-surface", "fill"],
} as const satisfies Readonly<Record<string, readonly ColorRole[]>>;

/** 導出 (固定配列を持たない = i4)。 */
export const SURFACE_ROLE_TOKENS = tokensWithRole("surface");
export const TEXT_ON_SURFACE_TOKENS = tokensWithRole("text-on-surface");
export const FILL_TOKENS = tokensWithRole("fill");
export const FILL_LABEL_TOKENS = tokensWithRole("fill-label");

/**
 * `non-text-boundary` の役割を持つ token の理由 (理由必須。正典 i17)。
 *
 * ★**キー集合が `tokensWithRole("non-text-boundary")` と一致する**ことを機械で見る
 *   (理由だけ残る / 役割だけ足す のどちらも落とす)。
 * ★**「この token は一切検査しない」という意味ではない**。`border` は
 *   `declared-text-background` の役割も持つので、その用途は個別宣言ペアで検査される。
 */
export const NON_TEXT_BOUNDARY_REASONS = {
    "border":
        "1px の区切り線・入力欄の枠としての用途。WCAG 1.4.11 (非テキスト 3:1) の別の閾値体系で、" +
        "装飾的な境界線は 1.4.11 の適用除外にあたるため、使用箇所ごとの役割分類が要る " +
        "(家系の未決論点 q2 の担当)。**テキストを載せる塗りとしての用途は別の役割で検査する**",
    "border-strong":
        "3 つの用途がいずれも本 gate の対象外である — (1) 1px の区切り線・入力欄の枠 " +
        "(WCAG 1.4.11 の非テキスト 3:1 で別の閾値体系。役割モデルが未定のため家系の未決論点 q2 の担当)、" +
        "(2) Toggle のトラック (テキストを載せない塗り)、" +
        "(3) 無効化したタブのラベル (SC 1.4.3 は無効化された UI 部品を適用除外にしている)。" +
        "実測 2.56 で 3:1 に届かないので、値の是正は 1.4.11 の役割モデルを DESIGN.md に" +
        "定めてから別バッチで行う",
} as const;

/**
 * 役割の直積で表現できない正当な 1 対 1 の組 (理由必須。正典 i14)。
 *
 * ★直積と**同じ閾値** (4.5:1) を課す。
 * ★**キーは DESIGN.md の色キー空間**である。走査器が返す CSS suffix 空間とは別なので、
 *   突き合わせは COLOR_TOKEN_MAP の逆写像で行う。
 * ★**役割分類の既定拒否をここで迂回できない** — 本表に現れた token を
 *   「分類済み」と数えるのはやめ、分類の全数性は `COLOR_TOKEN_ROLES` だけで見る。
 *   本表に対しては別の集合一致を課す (下の 3 条)。
 */
export const DECLARED_CONTRAST_PAIRS = [
    {
        fg: "text-primary",
        bg: "border",
        reason:
            "Button の neutral variant の hover (hover:bg-border + text-text)。" +
            "border を塗り面の役割へ入れると直積に neutral on border (1.15) と " +
            "surface on border (1.27) が生まれるが、この 2 組は実装に 1 件も無い。" +
            "border の 1px 枠としての用途は WCAG 1.4.11 (別の閾値体系) で本 gate の対象外である",
    },
] as const satisfies readonly DeclaredPair[];
```

**個別宣言ペアに課す 5 条** (これが無いと「1 組登録して全色被覆を通す」経路が残る):

1. 背景側は `declared-text-background` の役割を持つこと
2. 前景側は `text-on-surface` か `fill-label` の役割を持つこと
3. `declared-text-background` の役割を持つ token は、**本表の背景側に 1 回以上現れる**こと
   (役割だけ宣言して組を書かない = 死んだ宣言を作らせない)。
   加えて背景側は `surface` / `fill` の役割を**持たない**こと
   (持つなら直積で受けられるので個別宣言は冗長である)
4. **各個別宣言ペアが、走査された不透明ペアに 1 回以上現れる**こと
   (Round 4 の Warning: 3 条までだと、同じ背景へ**実装に存在しない前景**を足して
   母集団を広げられた。実在しない組を検査すると誤検知になるので、実在を要求する)
5. **同一 `(fg, bg)` の重複宣言を拒否する**こと

```ts
// tests/js/architecture/contrast-invariant.test.ts (追加)

/** 個別宣言ペアも直積と同じ閾値を課す (正典 i14)。 */
const PAIRS = [
    ...TEXT_ON_SURFACE_TOKENS.flatMap(/* 既存 */),
    ...FILL_LABEL_TOKENS.flatMap(/* 既存 */),
    ...DECLARED_CONTRAST_PAIRS.map((p) => [p.fg, p.bg, "個別宣言ペア"] as const),
];

describe("architecture/contrast-invariant: 実装からの逆向き被覆 (i15)", () => {
    it("走査の分母が空でない (ディレクトリ単位の走査が生きている)", () => {
        // ★非空を要求するのは `requiresOccurrences: true` の子だけである
        //   (Round 2 の Critical: 全件へ要求すると、抽出 0 件が正常な lib / types /
        //   直下ファイルで**設計どおり実装すると必ず赤**になる)。
        const scan = scanClassUsage();
        expect(scan.files.length).toBeGreaterThan(0);

        // 分類表と走査結果のキーが集合一致する
        // (分類していない子が現れても、分類したのに走査していない子があっても赤)
        expect([...scan.perDirectory.keys()].sort()).toEqual(
            Object.keys(JS_SCAN_CHILD_CLASSIFICATION).sort(),
        );

        for (const [dir, spec] of Object.entries(JS_SCAN_CHILD_CLASSIFICATION)) {
            if (!spec.requiresOccurrences) continue;
            expect(scan.perDirectory.get(dir), `${dir} から 1 件も抽出できていない`)
                .toBeGreaterThan(0);
        }
    });

    it("走査で得た不透明ペアがすべて母集団 (役割の直積 + 個別宣言) の内側にある", () => {
        // 役割の宣言を書かずに新しい組を足す経路を塞ぐ。
        // 走査は CSS suffix 空間なので COLOR_TOKEN_MAP の逆写像で母集団へ写す。
        …
    });

    it("既知の要求組が抽出結果から実際に生成される (抽出の空振り防止)", () => {
        // Badge の全 tone と Button の全 variant が期待どおり出ること (正典 i15)。
        // 期待値は TONE_CLASSES / VARIANT_CLASSES のキーから導出する (件数を散文に書かない)。
        …
    });

    it("面の役割とテキストの役割が素である (自己ペア = 比 1.0 が混入しない)", () => {
        // 既存 it の等価な置き換え (導出後の配列で見る)。
        const surfaces = new Set<string>(SURFACE_ROLE_TOKENS);
        expect(TEXT_ON_SURFACE_TOKENS.filter((t) => surfaces.has(t))).toEqual([]);
    });

    it("走査器が扱えない既知の入口が 0 件である", () => {
        expect(unsupportedEntryPoints()).toEqual([]);
    });

});
```

> **解決できなかった class トークン (`resolution.kind === "unresolved"`) を 0 件に固定するのは
> S3 (参照の閉包) の担当**である。同じ主張を 2 つの gate へ書くと、片方を緩めたときに
> もう片方が残っていることが分かりにくくなる (責務境界は `docs/design-system.md` の表が正本)。
> 走査器は `unresolved` を**結果に必ず残す** (無言で候補から外さない = 共通規約 (b) の 1 点目)。

既存 it の書き換え (**個別宣言ペアを分類の根拠に数えない**形へ):

```ts
it("役割宣言が DESIGN.md の全色トークンを覆う (deny-by-default)", () => {
    // ★分類の全数性は COLOR_TOKEN_ROLES **だけ**で見る。
    //   個別宣言ペアに現れることを「分類済み」と数えると、任意の新 token を
    //   1 組登録するだけで既定拒否を通せてしまう (Round 1 レビューの Critical)。
    expect(Object.keys(COLOR_TOKEN_ROLES).sort()).toEqual(Object.keys(COLOR_TOKEN_MAP).sort());
    for (const [token, roles] of Object.entries(COLOR_TOKEN_ROLES)) {
        expect(roles.length, `${token}: 役割が 0 件`).toBeGreaterThan(0);
        // 同じ役割の重複登録を拒否する (導出した直積に重複ペアが生じるのを防ぐ。Round 2 の Suggestion)
        expect(new Set(roles).size, `${token}: 役割が重複している`).toBe(roles.length);
    }
});

it("non-text-boundary の役割と理由の集合が一致する (理由だけ残る / 役割だけ足す を落とす)", () => {
    expect(Object.keys(NON_TEXT_BOUNDARY_REASONS).sort()).toEqual(
        [...tokensWithRole("non-text-boundary")].sort(),
    );
    for (const [token, reason] of Object.entries(NON_TEXT_BOUNDARY_REASONS)) {
        expect(reason.length, `${token}: 理由`).toBeGreaterThan(30);
    }
});

it("個別宣言ペアが 5 条を満たす (直積の既定拒否を迂回できない)", () => {
    const declaredBackgrounds = new Set(DECLARED_CONTRAST_PAIRS.map((p) => p.bg));
    const scanned = new Set(
        scanClassUsage().pairs.filter((p) => p.kind === "opaque").map((p) => `${p.fg}|${p.bg}`),
    );
    expectUnique(DECLARED_CONTRAST_PAIRS, (p) => [p.fg, p.bg]);
    for (const p of DECLARED_CONTRAST_PAIRS) {
        expect(rolesOf(p.bg), `${p.bg}: 背景側の役割`).toContain("declared-text-background");
        expect(rolesOf(p.bg), `${p.bg}: 直積で受けられる背景は個別宣言にしない`)
            .not.toContain("surface");
        expect(rolesOf(p.bg)).not.toContain("fill");
        expect(
            rolesOf(p.fg).some((r) => r === "text-on-surface" || r === "fill-label"),
            `${p.fg}: 前景側の役割`,
        ).toBe(true);
        expect(p.reason.length, `${p.fg} on ${p.bg}: 理由`).toBeGreaterThan(30);
        // 実装に存在しない個別宣言ペアを足せないようにする (走査は suffix 空間なので写す)
        expect(
            scanned.has(`${toSuffix(p.fg)}|${toSuffix(p.bg)}`),
            `${p.fg} on ${p.bg}: 実装に 1 件も無い個別宣言ペア`,
        ).toBe(true);
    }
    // 役割だけ宣言して組を書かない = 死んだ宣言を作らせない
    for (const token of tokensWithRole("declared-text-background")) {
        expect(declaredBackgrounds.has(token), `${token}: 役割はあるが個別宣言ペアが無い`).toBe(true);
    }
});
```

### 型適合チェック

- [x] 戻り値の型が明示されている
- [x] `null` 安全 (`hex()` は不在で例外。`COLOR_TOKEN_MAP` の逆写像が引けない suffix は例外)
- [x] 配列返却ではなく `as const satisfies readonly DeclaredPair[]` の宣言
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] **先に赤くするテスト**: 「走査で得た不透明ペアがすべて母集団の内側にある」。
      役割分類を直す**前**に実行すると `(text, border)` が母集団の外なので**赤**になる。
      **S3 が先に済んでいる**ので `text-white` は `text-surface` へ直っており、
      `(surface, primary)` も同時に赤で現れる — `surface` に `fill-label` の役割を足し、
      `border` に `declared-text-background` の役割と個別宣言ペアを足すまで赤が続く。
      これが役割分類と実装の食い違いの実証である
- [x] 「個別宣言ペアが 5 条を満たす」は、次の 2 つの検体で**赤**になることを先に確認する —
      (a) `border` に `surface` の役割を足した状態 (直積で受けられるのに個別宣言している)、
      (b) **実装に存在しない前景**を同じ背景へ足した状態 (母集団の水増し)
- [x] 「役割宣言が DESIGN.md の全色トークンを覆う」は、`COLOR_TOKEN_ROLES` から 1 キーを
      抜いた検体で**赤**になることを確認する (個別宣言ペアで迂回できないことの裏取り)
- [x] `it.each(PAIRS)` に個別宣言ペアが加わることで**組の総数が増える**ことを確認する
      (母集団を痩せさせていないことの確認)
- [x] 既存テストの削除・上書きをしない: 既存の 4 it (役割の被覆 / 0 件でない / 素である /
      pending が空でない) と `it.each(PAIRS)` は**すべて据え置く** (被覆の it は拡張のみ)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 個別宣言ペアは「直積で表現できないもの」に限る規律が緩むと母集団が痩せる。
  → 登録の理由に「直積へ入れると実在しない組が生まれる」ことを**具体的な比の値つき**で
  書くことを様式にする (上記 `reason` の形)。レビューで判断できる。
- `surface` に `fill-label` の役割を足すと直積が 7 組増える。是正後の値では全組が
  6.70〜9.48 で成立する (実測)。**是正前の値では `surface on primary` が 5.17 で成立する**ので、
  S6 の前に足しても赤にはならない。

---

## S9 規範判定対象外領域の除去と字下げの禁止を 2 契約に分け、行分類を 1 実装へ集約する (i12 の残余)

### 変更箇所

- `tests/js/styles/design-system-docs.test.ts` (`renderedLines()` / docblock / fixture)
- `docs/design-system.md` (「落とすのは HTML コメント / fenced code の 2 つ」の記述を訂正)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 同ファイルの fixture に負例を追加
- **共有パス**: `docs/design-system.md` → S12

### 現行コード

```ts
// tests/js/styles/design-system-docs.test.ts (L25-28 の docblock)
 *   - **描画されない領域の全種類**。潰すのは HTML コメントと fenced code の 2 つだけで、
 *     4 空白字下げのコードブロックや HTML 要素による非表示は見ていない
```

### 変更後コード

```ts
/**
 * 4 空白以上の字下げ行を**落とすのではなく、gate 自体を失敗させる** (Round 2 の Critical)。
 *
 * ★経緯: 当初は CommonMark の indented code block を状態機械で近似して落とす設計だったが、
 *   「直前の描画行が空行」という近似では**見出しの直後**の 4 空白行を取りこぼす。
 *   CommonMark で字下げコードが中断できないのは**段落**であって、
 *   見出しや区切り線の直後の 4 空白行は字下げコードになりうる。
 *   したがって近似のままだと、規範の最小断片を
 *     ## 契約
 *     (空行)
 *     ␣␣␣␣本当は読者に見えないコードの中にある規範文
 *   の形へ退避させて**緑にできる穴が残る**。
 *
 * ★**契約は 2 つあり、混ぜない** (Round 5 の Critical: 「container 文法を扱わない」は
 *   字下げの検出には言えるが、**囲みコードの除去には言えない**)。
 *
 * 【契約 A — 規範判定対象外領域の除去】
 *   ★呼称は「非描画領域」ではなく**「規範判定対象外領域」**である (Round 6 の Warning) —
 *     **HTML コメントは読者に描画されない**が、**囲みコードは描画される**。
 *     どちらも「規範の本文として数えない」点だけが共通である。
 *   落とすのは HTML コメントと囲みコードの 2 つ。
 *   ★**fence の受理範囲を明記する** (実装者依存にしない。Round 6 の Warning) —
 *     marker は**同一文字 3 個以上** (`` ` `` または `~`)、開始は**字下げ 3 空白まで**、
 *     終了は**開始と同じ種類で開始以上の長さ・後続は空白のみ**、
 *     backtick 型は**info string にバッククォートを含められない**。
 *   ★**container を伴う fence 候補はすべて診断にする** (Round 6 の Critical。
 *     `- > ``` ` や `> - ``` ` は「行頭の `>` を剥がす」でも `^ {0,3}` でも通過し、
 *     4 連続空白も含まないので契約 B でも落ちない) —
 *     **囲みコードの外の行に fence marker (3 個以上連続した `` ` `` または `~`) が
 *     どこかに現れたら、その行が上の受理範囲を満たす正規の top-level fence 行でない限り、診断**にする。
 *     ★これで container を伴う fence 候補はすべて落ちる。
 *       **container 文法 (list marker の記法・padding・入れ子の順) を 1 つも書かない**。
 *     ★**行内コード span も 3 個以上の delimiter を使える** (Round 7 の Critical:
 *       「行内コードは 1〜2 個だから誤検出しない」は誤り)。したがって本 gate の契約は
 *       **「正規の top-level fence 行以外に、3 個以上連続した marker を書くこと自体を禁じる」**
 *       である。3 個以上の delimiter の行内コード span も**拒否**する
 *       (「誤検出しない正例」ではなく「拒否する負例」として固定する)。
 *     ★**対象文書の本文から連続 marker の表記を除く** — S9 / S11 が
 *       `docs/design-system.md` へ足す説明文に 3 連 marker を書くと、
 *       **その文書自身が診断で赤くなる**。説明は「囲みコード記法」という語で書き、
 *       marker そのものを本文へ書かない。
 *     ★実測: `docs/design-system.md` と `DESIGN.md` はどちらも fence 0 行・
 *       3 連 marker 0 件なので偽陽性は起きない。
 *
 * 【契約 B — 字下げの禁止】囲みコードの外に次のいずれかがあれば **gate を失敗させる**。
 *   1. **タブを含む行** (列の解釈が環境依存になるため)
 *   2. **4 個以上連続した半角空白を含む行** (行頭に限らない)
 *   ★**契約 B は container 文法を 1 つも扱わない**。
 *
 *   ★**見逃しが 0 であることの証明** (Round 5 で論証を差し替えた。
 *     旧論証の「container marker が消費する空白は marker ごとに高々 1 個」は**誤り**で、
 *     CommonMark の list marker の padding は 1〜4 である):
 *     (1) すべての有効な container prefix を消費した後の**内容開始列**を基準にする。
 *     (2) 字下げコードには、その基準から**さらに 4 列以上**の字下げが要る。
 *     (3) タブを禁じた場合、その追加 4 列を作れるのは**連続した U+0020 だけ**である。
 *     (4) list marker の幅や padding は**内容開始列を決める prefix 側**であり、
 *         追加 4 列の代用にはならない。
 *     (5) gate は全行を見るので、コードブロックの**少なくとも先頭の非空行**で
 *         4 連続空白を検出する。
 *     よって `>␣␣␣␣text` も `-␣␣␣␣␣␣text` も `1)␣␣␣␣␣text` も契約 B で落ちる。
 *
 *   - i12 の目的 (契約の本文を読者に見えない場所へ退避させられないこと) は、
 *     **そもそも書かせない**ことで満たす。
 *   - 実測: `docs/design-system.md` は囲みコードの外にタブが **0 件**、
 *     4 連続空白も **0 件**である。現時点で偽陽性は起きない。
 *   - **偽陽性の class は 1 つだけ**である — 本文の中で意図的に 4 空白以上を並べる書き方
 *     (表の桁揃え等)。**書き方を直す**のが正しい対応であり、検査は緩めない。
 *   - 失敗のメッセージには**直し方**を書く (「囲みコード ``` を使うこと」)。
 * ★**CommonMark パーサは導入しない**: `marked` / `commonmark` / `markdown-it` はいずれも未導入で、
 *   この 1 検査のために依存を増やすのは「今必要なものだけ作る」に反する。
 *   **導入を再検討する契機**は「本書に字下げコードを書く正当な必要が出たとき」である
 *   (そのときは block レベルの解析が要る)。
 * ★保証しないもの: HTML 要素による非表示 (`<details>` / `hidden` 属性等) は見ていない。
 */
```

**行の分類は 1 回だけ行う** (Round 3 の Warning: `renderedLines()` と
`indentedLineNumbers()` がそれぞれ囲みコード状態を解析すると、同じ Markdown に
2 本の字句走査ができて弱い方が緑を作る = i21 と同じ問題)。

```ts
// tests/js/styles/markdown-lines.ts (新設。*.test.ts ではないので責務境界表の母集団に入らない)
export interface MarkdownScan {
    /** 規範判定の対象になる行 (HTML コメントと囲みコードを "" へ潰したもの。**行数は保つ**) */
    readonly renderedLines: readonly string[];
    /** 契約 B: 囲みコードの外でタブ、または 4 個以上連続した半角空白を含む行の行番号 (1 始まり) */
    readonly forbiddenIndentLines: readonly number[];
    /**
     * 契約 A: 解析できなかった形 (1 件でもあれば gate が落ちる)。
     * ★`unparsableFenceLines` という個別の口は**廃止**し、理由つきの診断へ一本化した
     *   (Round 6: blockquote fence 以外の未対応 fence を表現できなかった)。
     */
    readonly diagnostics: readonly MarkdownDiagnostic[];
}

export interface MarkdownDiagnostic {
    /** 1 始まりの行番号 (診断出力用。期待値には使わない) */
    readonly line: number;
    readonly reason: MarkdownDiagnosticReason;
}

export type MarkdownDiagnosticReason =
    | "unterminated-html-comment"
    | "unterminated-fence"
    | "container-fence"     // container prefix を伴う fence 候補
    | "unsupported-fence";  // 受理範囲外の fence 記法

export function scanMarkdownLines(source: string): MarkdownScan;
```

- `design-system-docs.test.ts` (S9) と `design-md.ts` の
  `parseDesignComponentSections()` (S8) が**同じ実装**を使う。
- 固定検体は `design-system-docs.test.ts` の既存の fixture describe に置く
  (新しい `*.test.ts` を増やさない = 責務境界表の行を増やさない)。

`docs/design-system.md` の訂正:

```markdown
ただし**完全な Markdown 解析ではない** — 4 空白字下げのコードブロックと
HTML 要素による非表示は見ていない。
```
↓
```markdown
落とすのは HTML コメントと囲みコードの 2 つ(前者は読者に描画されず、後者は描画されるが
規範の本文として数えない。まとめて**規範判定対象外領域**と呼ぶ)。
**行頭から 3 空白までで始まる正規の囲みコード記法以外の位置に、
記号を 3 個以上連続させて書くことは禁じる**(引用やリストの中の囲みコード記法、
行の途中の連続記号、記号 3 個以上の行内コードを含む)。書かれていたら検査自体を失敗させる。
加えて**タブと 4 個以上連続した半角空白も検査自体を失敗させる**
(字下げによるコードは書かず、囲みコード記法を使うこと)。
字下げコードの位置を近似で判定すると見出し直後や引用の中の形を取りこぼし、
そこへ規範の断片を退避させられる。タブを禁じたうえで 4 連続空白を拒否すれば、
引用やリストの記号が何段入れ子になっていても字下げコードは書けないので、
**字下げについては引用やリストの文法を一切扱わずに見逃しを 0 にできる**。
ただし**完全な Markdown 解析ではない** — HTML 要素による非表示は見ていない。
```

### 型適合チェック

- [x] 戻り値の型が明示されている (`readonly string[]`)
- [x] `null` 安全 (状態は判別可能な形で持つ)
- [x] 配列返却は行配列という性質上正しい (行数保存が契約)
- [x] Generics なし

### テスト計画

- [x] **先に赤くするテスト**: 実文書に対する
      「囲みコードの外にタブと 4 連続空白が無い」「Markdown 走査の診断が 0 件である」の
      2 it を**先に書く** (本 gate が docs 側 Markdown 診断の消費先である)。
      実装前は `scanMarkdownLines()` が存在せず**コンパイルエラーで赤**。
      次に空実装 (`throw`) で**実行時エラーの赤**を確認してから実装する
- [x] 負のコントロール (固定検体を `scanMarkdownLines()` へ直接渡す):
  1. **空行の後の 4 空白字下げ行**を検出する
  2. **見出しの直後の 4 空白字下げ行**を検出する
     (Round 2 の近似が取りこぼしていた形)
  3. **段落の継続行** (直前が空行でない 4 空白字下げ行) も検出する
     (CommonMark では本文だが、本 gate は書き方そのものを禁じるので区別しない。
     この「厳しい側へ倒す」判断を docblock に明記する)
  4. **行頭タブ**を検出する
  5. **1〜3 空白 + タブ**を検出する
  6. **`>␣␣␣␣text`** (blockquote の中の字下げコード) を検出する
     (Round 3 の Critical が指摘した見逃し)
  7. **入れ子の blockquote** (`> >␣␣␣␣text`) を検出する
  8. **list marker の後の字下げコード** (`-␣␣␣␣␣␣text`) を検出する
  9. **`1)␣␣␣␣␣text`** (ordered list の別記法) を検出する
     (**container 文法を 1 つも書かずに落ちる**ことの裏取り = 本改訂の要点)
  10. **行の途中の 4 連続空白** (`text␣␣␣␣text`) を検出する
      (「行頭に限らない」ことの明示。偽陽性 class をテストで見えるようにする)
  11. **marker の padding 1〜4** (`-␣text` 〜 `-␣␣␣␣text` の各段の継続行が字下げコードになる形)
  12. **ordered marker の 1〜9 桁**と `.` / `)` の両方
  13. **list の最初の block が字下げコード**の場合と、**後続 block が字下げコード**の場合
  14. **blockquote と list の異種入れ子** (`> -␣␣␣␣␣text` / `- >␣␣␣␣text`)
  15. **lazy continuation は字下げコードではない**という**正例** (誤検出しない)
  16. **囲みコードの中の 4 空白字下げ行とタブ**は検出しない (偽陽性を出さない負のコントロール)
  17. **通常の blockquote 本文** (`> text`) と**通常の list 本文** (`- text` /
      2 空白の継続行) は検出しない (偽陽性を出さない負のコントロール)
  18. 1〜3 空白の字下げ行は検出しない
  19. **契約 A の負例 (`container-fence` の診断になる)**: `> ``` ` / `> > ``` ` /
      `- > ``` ` / `> - ``` ` / `  > ``` ` / 行の途中に現れる 3 連バッククォート。
      その中に置いた規範の断片や `### 部品名` が**通常本文として数えられない**ことを固定する
  20. **契約 A の正例**: `^ {0,3}` の正規の fence は通常の fence として扱われ、中身が落ちる
      (診断にならない)。1〜2 個の delimiter の行内コード span も診断にならない
  20b. **契約 A の負例**: **3 個以上の delimiter の行内コード span**も診断になる
      (Round 7 の Critical。「1〜2 個だから安全」ではない)
  21. **契約 A の負例 (`unsupported-fence` / `unterminated-fence`)**:
      開始より短い終了 marker / 種類の違う終了 marker /
      backtick 型で info string にバッククォートを含む行 / EOF まで閉じない fence
  21. **行数が保存される**こと (既存の it が自動で見る)
- [x] 既存の 8 it が同じ期待値で緑であること (`docs/design-system.md` に
      4 空白以上の字下げ行が 1 行も無いことを実測済み)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- **本書に字下げコード・タブ・4 連続空白を書けなくなる**(囲みコードを使うことになる)。
  実測で現状 0 行なので既存の記述は影響を受けない。
  リストの継続行は**3 空白以内**にする必要がある (S11 で足す行はこの制約に合わせてある)。
  → 赤くなったら**書き方を直す**のが正しい対応であり、検査を緩めない。
  拾いすぎる方向へ倒すのは共通規約 (b)「拾いすぎる方向へ倒すのは可、見逃す方向へ倒すのは不可」に沿う。
- 逆に**近似の状態機械で落とす**実装にすると、見出し直後の字下げコードへ
  規範の断片を退避させて緑にできる (Round 2 レビューで是正した点)。そちらは見逃す方向なので採らない。
- 本施策で `docs/design-system.md` の**記述の訂正**が要る (「落とすのは 2 つ」の説明)。
  この文書は共有パスなので S12 の D51 で決着させる。

---

## S8 文書 ⇔ 実装の双方向一致 gate を新設する (i10)

### 変更箇所

- 新規: `tests/js/styles/component-doc-parity.test.ts`
- `tests/js/styles/design-md.ts` (`designComponentSections()` を追加 — 正本の解析は 1 実装へ集約)
- `tests/js/styles/inventory.ts` (`COMPONENT_DIR_CLASSIFICATION` / `COMPONENT_FILE_KINDS` /
  `COMPONENT_SECTION_MAPPINGS` を新設)
- `DESIGN.md` (§Components 冒頭の対象範囲を明記 + **4 節を追加**)

### 波及変更

- TypeScript 型定義: 分類表と申告表の型を新設
- API Resource/DTO: なし
- テストファイル: 新設 1 本 (S11 で責務境界表へ行を足す)

### 現行コード

```markdown
## Components

> component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
> 使い分けルールのみを定義する。各 component を追加したら本節に追記すること。
```

31 節が並ぶ。実測で**節を持たない部品が 4 本**ある。

### 変更後コード

```markdown
## Components

> component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
> 使い分けルールのみを定義する。
> **本節が対象にするのは DS の再利用部品(`atoms` / `molecules` / `organisms`)である。**
> `features/` のドメイン部品と `templates/` のレイアウト骨格は本節の対象外
> (前者は各 feature の設計が使い分けを決め、後者は §Layout と
> `tests/js/architecture/page-shell-structure.test.ts` が担当する)。
> **対象の component を追加したら本節に追記すること**
> (`tests/js/styles/component-doc-parity.test.ts` が双方向の集合一致で強制する)。
```

追加する 4 節 (アルファベット順ではなく既存の並びの流儀 = 概ね atom → molecule に従う):

| 節 | 対応ファイル | 節に書く意味論 |
|---|---|---|
| `### DragHandle` | `atoms/DragHandle.svelte` | 並べ替えのつかみ手。`GripVertical` 固定 / `touch-none` でタッチをスクロールに奪わせない / 小コントロールなので `rounded-sm` / **並べ替えができない状態の表現は別途定義する** (禁止事項 8 は「必須条件未充足を理由に disabled にする」ことの禁止であって、あらゆる disabled の禁止ではない) |
| `### OrganizationChoiceCard` | `molecules/OrganizationChoiceCard.svelte` | 組織を 1 件選ぶ遷移カード。遷移先 URL は親が渡す (組織文脈を molecule が解決しない) |
| `### PendingInvitationsNotice` | `molecules/PendingInvitationsNotice.svelte` | 自分宛の保留中招待の件数だけを出す誘導専用 notice。**受諾 UI は持たない** (受諾は通知一覧) |
| `### SubtitleOverlay` | `molecules/SubtitleOverlay.svelte` | 映像へ重畳する字幕 overlay。焼込ではなく DOM overlay (MediaRecorder の stream に含まれない) / primary=上部帯・secondary=下部メイン / 位置は `AssSubtitleWriter` (ASS) と一致 / 長文は line-clamp で省略 |

**判定は 3 段の純粋関数に分ける** (Round 2 の Critical。S2 と同じ穴が S8 にもあった —
実リポジトリを直接列挙する gate だけでは「未分類ディレクトリを足す」「部品を 1 つ足す」の
固定検体を同じ判定実装へ渡せない):

```ts
/**
 * DESIGN.md の本文から §Components の `###` 節名を取り出す (design-md.ts に置く)。
 *
 * ★**S9 が新設する共通 Markdown 行走査 (`scanMarkdownLines`) を共有する** — 独立した弱い解析器を
 *   増やさない (i21)。単純な見出し正規表現だと、囲みコードの中に `### DragHandle` を置いて
 *   「文書化済み」に見せられ、**双方向一致という中心の保証を直接迂回できる** (Round 3 の Critical)。
 *   ★**S9 が前提施策である** (実施順は S9 → S8)。
 *   ★Markdown 走査の **`diagnostics` が 1 件でもあれば解析失敗**にする (未終端コメント /
 *     未終端 fence / container fence / 未対応 fence を**同じ経路**で消費する。Round 6 の Warning)。
 *     `- > ``` ` の中へ `### 部品名` を置いて「文書化済み」に見せる迂回もここで落ちる。
 *     ★**本 gate が DESIGN.md 側 Markdown 診断の消費先である**。
 *     実測: `DESIGN.md` は blockquote 2 行・fence 0 行なので現時点で偽陽性は起きない。
 * ★契約 5 条 (いずれも固定検体で裏取りする):
 *   1. `## Components` は**ちょうど 1 節**であること (0 件も 2 件も例外)
 *   2. HTML コメントと囲みコードの中の見出しは**数えない**
 *   3. `###` だけを対象にし、`####` 以降は数えない
 *   4. 同名の節が 2 つあれば**例外**
 *   5. Markdown 走査の診断 (未終端コメント / 未終端 fence / container fence /
      未対応 fence) が 1 件でもあれば**解析失敗** (i20)
 */
export function parseDesignComponentSections(source: string): readonly string[];

/**
 * ディレクトリ木を分類表で仕分ける (部品の母集団と、未分類の検出結果を返す)。
 * ★引数は**構造型**である (Round 3 の Critical: `typeof COMPONENT_DIR_CLASSIFICATION` にすると
 *   実定数の literal 型に固定され、**固定検体から分類表を増減できない**)。
 */
export type ComponentDirClassification = Readonly<Record<string, ComponentDirSpec>>;
export type ComponentFileKinds = Readonly<Record<string, ComponentFileKindSpec>>;
export type ComponentSectionMappings = readonly ComponentSectionMapping[];

export function classifyComponentTree(
    tree: ComponentTree,
    dirClassification: ComponentDirClassification,
    fileKinds: ComponentFileKinds,
): ComponentClassification;

/** 節と部品を申告表つきで突き合わせる (双方向の差分と、申告表の失効・重複・冗長を返す)。 */
export function compareComponentDocumentation(
    sections: readonly string[],
    components: readonly string[],
    mappings: ComponentSectionMappings,
): ComponentDocDiff;
```

実定数は `as const satisfies ComponentDirClassification` の形で構造型へ適合させる
(literal 型の情報は保ちつつ、純粋関数へは構造型として渡せる)。

実ファイル用の gate は、DESIGN.md を読み・ディレクトリを列挙し・この 3 段へ渡すだけの
薄いラッパーにする。固定検体は 3 段へ直接渡す。

**探索規則** (Round 1 レビューの Warning。再帰の境界を実装者依存にしない):

1. **集合一致は 2 段で見る** (Round 3 の Warning: 分類表は深さ 2 の `atoms/icons` を含むので、
   直下の集合とそのまま比べると字面上矛盾する) —
   (1) `resources/js/components` の**直下**のサブディレクトリ集合と、
   分類表キーの**第 1 要素**の集合を一致させる。
   (2) 再帰が終わった後に、**実際に使用した完全パスの集合**と分類表**全体**を一致させる
2. `kind: "excluded"` の分類は**そこで再帰を止める** (中は一切見ない)
3. `kind: "documented"` の分類は**その直下のファイルだけ**を部品の母集団に入れる
4. `documented` の直下にさらにサブディレクトリがある場合 (`atoms/icons`)、
   **そのパス自体が分類表に無ければ不合格**にする (深さ 2 以降も同じ規則を適用する)
5. **部品の basename の重複を無条件に拒否する** (Round 3 の Warning: 既定の対応が
   ファイル名だけなので、`atoms/Foo.svelte` と `molecules/Foo.svelte` があると 1 節へ衝突する)。
   ★判定は `classifyComponentTree()` で行い、**申告表では救わない** (Round 4 の Warning:
   同関数は申告表を受け取らないので、救う口を書くと二通りに読める)。
   実測でも重複 basename は 0 件で、救う必要のある実例が無い。
   将来重複が要るようになったら、そのとき判定を `compareComponentDocumentation()` 側へ移す
   (この契機を docblock に書く)
6. 分類表のキーは実在するディレクトリであり、かつ**実際に判定へ使われた**こと。
   `excluded` の配下は規則 2 で再帰を止めるので、そこに入れ子のキーを登録しても
   **判定に使われない死んだ登録**になる (Round 2 の Warning)。
   したがって `templates/_helpers` の登録は**削除する** (`templates` が `excluded` で止まる)。
   使われなかった分類エントリが 1 つでもあれば不合格にする

```ts
// tests/js/styles/inventory.ts
/** §Components の対象にするサブディレクトリの全数分類 (既定拒否。キーは components からの相対パス)。 */
export const COMPONENT_DIR_CLASSIFICATION = {
    atoms: { kind: "documented" },
    molecules: { kind: "documented" },
    organisms: { kind: "documented" },
    templates: {
        kind: "excluded",
        reason: "レイアウトの骨格。使い分けは DESIGN.md §Layout と page-shell-structure.test.ts が担当する",
    },
    features: {
        kind: "excluded",
        reason: "ドメイン部品。使い分けは各 feature の設計が決め、DS の再利用部品カタログではない",
    },
    "atoms/icons": {
        kind: "excluded",
        reason: "Lucide に無いブランド/SSO ロゴの SVG 内包専用。svg-inline-allowlist.test.ts が担当する",
    },
    // ★`templates/_helpers` は登録しない — `templates` が excluded で再帰を止めるので
    //   判定に使われない死んだ登録になる (Round 2 の Warning)。
} as const;

/**
 * 対象ディレクトリ直下のファイル種別の全数分類 (既定拒否)。
 *
 * ★照合は**最長接尾辞一致**である (Round 2 の Warning: `.types.ts` は `.ts` の接尾辞でもあり、
 *   照合順が未定義だと `Button.types.ts` が helper へ誤分類されうる)。
 */
export const COMPONENT_FILE_KINDS = {
    ".svelte": { kind: "component", requiresSection: true },
    ".types.ts": {
        kind: "types",
        requiresSection: false,
        reason: "型と variant 表。同名の *.svelte が対になっていることを検査する",
    },
    ".ts": {
        kind: "helper",
        requiresSection: false,
        reason: "共有 helper。現状 1 件 = atoms/input-state.ts (入力系 atom の共通スタイル定義)",
    },
    ".gitkeep": { kind: "marker", requiresSection: false, reason: "空ディレクトリの目印" },
} as const;

/** 既定の対応 (節名 = ファイル名) に乗らない対応の申告 (理由必須。正典 i10)。 */
export const COMPONENT_SECTION_MAPPINGS = [
    { section: "Input / Textarea / Select(入力系 atom)",
      files: ["atoms/Input.svelte", "atoms/Textarea.svelte", "atoms/Select.svelte"],
      reason: "3 つの入力 atom は同じ枠・同じ状態表現を共有するため 1 節で意味論を定義している" },
    { section: "Toast", files: ["organisms/ToastContainer.svelte"],
      reason: "節名は利用者から見た概念 (Toast)、実装は容器 1 本 (ToastContainer)" },
    { section: "PageHeader / PageHeaderSection",
      files: ["molecules/PageHeader.svelte", "molecules/PageHeaderSection.svelte"],
      reason: "ページ見出しと節見出しは対で使うため 1 節で使い分けを定義している" },
] as const;
```

検査項目:

1. **双方向の集合一致**: §Components の `###` 節と、対象ディレクトリ直下の `*.svelte` が
   (申告表を適用したうえで) 集合一致する
2. **サブディレクトリの全数分類**: 実在するサブディレクトリが分類表と集合一致する (未分類は不合格)
3. **ファイル種別の全数分類**: 対象ディレクトリ直下の拡張子が分類表と集合一致する (未分類は不合格)
4. **`.types.ts` に対の `*.svelte` がある** (孤立した型ファイルを作らせない)
5. **申告表の健全性**: 失効 (存在しない節 / 存在しないファイル) / 重複 (同じファイルが 2 つの節に) /
   **冗長** (既定の対応で足りるのに申告している) をそれぞれ落とす
6. **母集団が空でない** (節数 > 0 / 部品数 > 0)
7. 負のコントロール (**固定検体を 3 段の純粋関数へ直接渡す**): 節を 1 つ消すと赤 /
   部品を 1 つ足すと赤 / 申告を冗長にすると赤 / 未分類のサブディレクトリを足すと赤 /
   **`documented` の下に未分類の入れ子ディレクトリを足すと赤** (規則 4 の裏取り) /
   **`excluded` の下のファイルは母集団に入らない** (規則 2 の裏取り) /
   **使われなかった分類エントリがあると赤** (規則 5 の裏取り)
8. ファイル種別の**最長接尾辞一致**の裏取り (固定検体):
   `Button.types.ts` → `types` / `input-state.ts` → `helper` /
   `Badge.svelte` → `component`
9. 節の抽出の負のコントロール (固定検体を `parseDesignComponentSections()` へ直接渡す):
   囲みコードの中の `### DragHandle` は**数えない** / HTML コメントの中の見出しも数えない /
   `#### X` は数えない / `## Components` が 2 つあれば例外 / 同名の `###` が 2 つあれば例外 /
   未終端の囲みコードは診断 /
   **container を伴う fence (`> ``` ` / `- > ``` ` / `> - ``` `) の中の `### X` は
   「数えない」のではなく診断になる** (扱えないものを通常本文として数えない)
10. basename 重複の負のコントロール: `atoms/Foo.svelte` と `molecules/Foo.svelte` を置くと不合格

### 型適合チェック

- [x] 戻り値の型が明示されている
- [x] `null` 安全 (節の抽出に失敗したら例外 = i20)
- [x] 配列返却ではなく `as const satisfies` の宣言
- [x] `kind` の網羅を `never` へ収束させる

### テスト計画

- [x] **先に赤くするテスト**: 検査 1 (双方向の集合一致)。DESIGN.md に 4 節を足す**前**は
      「実装にあるのに節が無い」で赤になる (13 部品事件と同じ形が実在することの実証)
- [x] 検査 5 の冗長判定を先に書き、`Input / Textarea / Select` を申告しない状態で赤を確認する
- [x] 負のコントロール 4 種を固定検体で置く
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- DESIGN.md §Components に節を足す作業が今後の部品追加ごとに要る。
  → それが i10 の目的である。`docs/design-system.md` の
  「コンポーネント追加時のチェックリスト」に既に
  「DESIGN.md §Components に意味論・使い分けを追記」が入っており、規約は変わらない。
- `features/` を対象外にする判断は、DESIGN.md 冒頭の「各 component を追加したら本節に追記する」と
  食い違っていた。→ 同じ PR で冒頭の文を対象範囲つきに直す (上記)。

---

## S11 責務境界表へ新設 gate を登録する (i11 の帰結)

### 変更箇所

- `docs/design-system.md` (§検査の責務境界の表に 4 行追加 / **本数の記述そのものを廃止** /
  §トークン変更時の運用契約に 1 行追加 / §テーマの差し替え方に 1 行追加)

### 波及変更

- テストファイル: 既存 `design-system-docs.test.ts` の
  「責務境界表の 1 列目と実在する検査ファイルが集合一致する (双方向)」が**この行なしでは赤**
- **共有パス**: `docs/design-system.md` → S12

### 変更後コード (表に追加する 4 行)

| 検査 | 見ている写像 | 代表的に検出する壊れ方 |
|------|------------|--------------------|
| `tests/js/styles/token-reference-closure.test.ts` | 参照側 (resources/js / resources/css) ⇒ tokens.css の宣言集合 | token 名の綴り誤りが無スタイルとして静かに消える / 写像の外の色語 (Tailwind 既定の white 等) の混入 |
| `tests/js/styles/component-doc-parity.test.ts` | DESIGN.md §Components ⇔ resources/js/components の部品ファイル | 文書に載らない部品が増える / 節だけ残って実装が消える |
| `tests/js/styles/class-usage.test.ts` | 走査器そのもの (固定検体) | 状態単位の分解の退行 / 未対応入口の deny の空振り |
| `tests/js/styles/theme-map.test.ts` | 写像パーサそのもの (固定検体) | `@theme` の検出・宣言の抽出・色表現の解析の退行 |

**本数の記述そのものを廃止する** (Round 1 レビューの Critical: 既存 4 本 + 新規 4 本 = 8 本で、
「4 本 → 6 本」は算術的に誤っていた。**数字は機械検査の対象外なので必ず陳腐化する**)。

| 現行 | 変更後 |
|---|---|
| 「本節で責務境界を管理するデザイントークン検査は **4 本ある**」 | 「本節で責務境界を管理するデザイントークン検査は**下表に挙げたものがすべてである**」 |
| 「保証しないもの: … **4 本のどれも**見ていない」 | 「保証しないもの: … **下表のどれも**見ていない」 |

表の双方向集合一致 (`design-system-docs.test.ts`) だけを正本にする。

`§トークン変更時の運用契約` へ追加する 1 行
(★S9 の決着により**字下げ 4 以上の継続行を作らない**。1 行に収める):

```markdown
- [ ] トークンの**値**を変える場合は `contrast-invariant.test.ts` の不透明ペアと**半透明ペア(合成)**の両方が緑であること(ソフト背景の色は面の上での合成後の値で判定される)
```

`§テーマの差し替え方` の 3 手順へ追加:

```markdown
3. parity テスト green を確認(**contrast-invariant の合成検査も含む**。
   状態色を明るい段に戻すとソフト背景側で落ちる)
```

★継続行の字下げは **3 空白以内**にする (S9 の gate が 4 空白以上を失敗させる)。
上の例は 3 空白なので通る。

### 型適合チェック

- [x] 該当なし (Markdown)

### テスト計画

- [x] **先に赤くするテスト**: S3 / S8 / S2 の新 `*.test.ts` を置いた時点で
      既存の「責務境界表の 1 列目と実在する検査ファイルが集合一致する」が**赤**になる。
      その赤を確認してから本施策で行を足す
- [x] 既存の「Canonical source 表の 2 列目のパスがすべて実在する」が緑のままであること
- [x] 規範の最小断片 (`SECTION_CONTRACT_PHRASES`) を**変えない**
      (契約の文言は変えず、行と本数だけを足す)

### リスク

- **本数の記述は廃止する**ので陳腐化しない。表そのものが機械で突き合わされており、
  数字は「表と実体が一致していること」に何も足していなかった。
  数字を最小断片 (`SECTION_CONTRACT_PHRASES`) に入れない (文言固定は増やさない = 既存方針)。

---

## S12 共有パスの採用時債務を決着させる (乖離台帳)

### 変更箇所

- `docs/template-divergence.md` (宣言行 46 → 48 / **D50 と D51 を追加**)
- `tests/Support/TemplateDivergence/LedgerPins.php`
  (`DIVERGENCE_ENTRY_COUNT` 46 → **48** / `ADOPTION_DEBT_COUNT` 148 → 146)
- `tests/Support/TemplateDivergence/adoption-debt.tsv` (2 行削除)

### 乖離台帳の確認段 (app-design スキル 3-0)

`docs/template-fingerprints.json` のキーに在るか (= テンプレートと共有するパスか) を実測した。

| 変更するパス | 指紋台帳のキー | 採用時債務 | 決着 |
|---|---|---|---|
| `docs/design-system.md` | **在る** | **在る** (採用時 sha と現況が一致) | **(3) 意図的逸脱として登録 (D51) を書き、債務から削る** |
| `tests/js/architecture/contrast-invariant.test.ts` | **在る** | **在る** (同上) | **(3) 意図的逸脱として登録 (D50) を書き、債務から削る** |
| `tests/js/support/ds-purity.ts` | 在る | 在る | **変更しない** (i9 が同じ穴を塞ぐので `white`/`black` を禁止リストへ足す案は採らない) |
| `DESIGN.md` | 無い | — | 登録不要 |
| `resources/css/tokens.css` | 無い | — | 登録不要 |
| `resources/css/app.css` | 無い (変更もしない) | — | — |
| `tests/js/styles/*` (既存 5 + 新設 3) | 無い | — | 登録不要 (既存の D28 が同領域の逸脱を説明済み) |
| `postcss.config.js` | 在る (変更しない) | — | — |

**判定の根拠**: `FingerprintReconciler` は債務パスの現況が採用時 sha と違えば
`mutatedDebtPaths` として落とす。かつ債務パスと登録の対象パスの**両方に在る** (`doubleDeclaredPaths`)
のも落とす。したがって「登録を書く」と「債務から削る」は**同じ変更で行う**。

### 追加する登録 (D50 / D51 の 2 件)

**2 エントリに分ける** (Round 1 レビューの Warning: 1 エントリの説明がコントラストだけでは、
`docs/design-system.md` に入る**別の変更理由** (検査目録の正本化 / 規範判定対象外領域の除去範囲 /
運用契約への合成検査の追加) を説明できない。**パス単位で採用時債務を解除するのだから、
登録理由は変更全体を説明していなければならない**)。

```markdown
## D50 デザイントークンのコントラスト検査を、半透明の合成と実装からの逆向き被覆まで広げる

| 行 | 内容 |
|---|---|
| 対象パス | `tests/js/architecture/contrast-invariant.test.ts` |
| 業務要件起因の説明 | 撮影 PWA の状態表示 (撮影中 / 完了 / 警告) はソフト背景のバッジで出しており、作業者はその 1 個の色で工程の状態を読む。テンプレートの検査は不透明な組だけを見るため、実際に画面へ出ているソフト背景の可読性が 1 件も検査されていなかった (実測で 5 組が AA 未達) |
| 揃え続ける不変条件と保証機構 | 半透明の背景 × 不透明な文字の組が、面として分類した token のすべての上で 4.5:1 を満たすこと。走査で見つかった半透明の組が (ファイル, 組, 修飾率, 件数) で全件台帳に載り、静的に決められない形は理由と件数つきで別台帳に載ること。台帳が持つのは class 修飾の百分率だけで、token 固有 alpha との合成は 1 か所 (`resolveAlphaBackground()`) に集約されること。実装の class から導出した前景 × 背景の組が役割の母集団 (役割の全数分類の直積 + 個別宣言ペア) の内側にあること。線形化しきい値が errata 後の 0.04045 であること。`contrast-invariant.test.ts` と `tests/js/styles/class-usage.ts` が保証する |
| 再判定の条件 | 正典が半透明の合成を不変条件から外したとき。または Tailwind の不透明度修飾の展開形が変わって合成モデルの前提が崩れたとき (`tokens.test.ts` の「不透明度修飾の生成形」が赤くなる)。広色域の実描画との差を実測して系統的なずれが出たとき (家系の未決論点 q3) |
| 決めた日 | 2026-08-24 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260824-1019-design-token-system-v1/ |
| 状態 | 恒久 |
| 見直し期限 | — |
```

```markdown
## D51 デザインシステム運用ガイドを検査目録の正本にし、部品カタログの被覆と字下げの禁止まで機械で固定する

| 行 | 内容 |
|---|---|
| 対象パス | `docs/design-system.md` |
| 業務要件起因の説明 | 本アプリはデザイントークン検査を独自系統で持つ (D28) ため、検査の本数も置き場もテンプレートと一致せず、責務境界の表を機械照合の入力にしている以上テンプレートの散文をそのまま維持できない。加えて (1) 撮影 PWA のテーマ値を動かす運用契約 (半透明の合成検査を通すこと) を書き足す必要があり、(2) DS の再利用部品が文書に載らないまま増える事故 (家系で実在) を機械で止めるため部品カタログの被覆を契約として書く必要があり、(3) 契約の本文を読者に見えない場所へ退避させる経路を塞ぐため字下げコードの扱いを書き換える必要がある |
| 揃え続ける不変条件と保証機構 | 正本の宣言表が全数宣言であり検査側の宣言と役割とパスの組で集合一致すること。責務境界表と `tests/js/styles/*.test.ts` の実体が双方向に集合一致すること。DESIGN.md §Components の節 (囲みコード・HTML コメントの中の見出しを数えず、`## Components` がちょうど 1 節であること) と対象サブディレクトリの部品ファイルが双方向に集合一致すること。節ごとの規範の最小断片が読者に描画される本文に在ること。文書の走査については保証が 2 つに分かれる — (a) **規範判定対象外領域の除去**: HTML コメント (読者に描画されない) と囲みコード (描画されるが規範の本文として数えない) を落とす。囲みコードの外の行に 3 個以上連続した marker (バッククォートまたはチルダ) が現れ、その行が字下げ 3 空白までの正規の top-level fence 行でなければ**診断**にする (container を伴う fence も、行の途中の連続 marker も、3 個以上の delimiter の行内コードも、通常本文として数えない)。未終端のコメント・未終端の fence・受理範囲外の fence 記法も同じ診断へ落とし、診断が 1 件でもあれば検査を失敗させる。(b) **字下げコードの拒否**: タブと 4 個以上連続した半角空白を含む行が現れたら検査自体を失敗させる (タブを禁じた前提では、container prefix を消費した後の内容開始列からさらに 4 列以上の字下げが要り、その 4 列を作れるのは連続した U+0020 だけなので、container 文法を扱わずに字下げコードの見逃しを 0 にできる)。**完全な CommonMark 解析ではない** — 保証するのはこの 2 命題の範囲だけである。行の分類は 1 実装 (`scanMarkdownLines()`) に集約されること。`tests/js/styles/design-system-docs.test.ts` と `tests/js/styles/component-doc-parity.test.ts` が保証する |
| 再判定の条件 | 検査目録を文書ではなく機械可読な台帳へ移したとき。部品カタログの正本を DESIGN.md 以外へ移したとき。Markdown パーサを導入して字下げコードを解析できるようにしたとき。または正典が運用ガイドの節構成そのものを不変条件として明文化したとき |
| 決めた日 | 2026-08-24 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260824-1019-design-token-system-v1/ |
| 状態 | 恒久 |
| 見直し期限 | — |
```

各エントリには観点表 (テンプレート / 本アプリ)、`### なぜ正当な差分か(logic-driven)`、
`### 揃えている不変条件(これは保証し続ける)`、`### 保証しないもの`、`### 関連` を
エントリ形式どおりに書く。**対象パスは全登録の和集合で重複しない**規約があるので、
既存 D28 の対象パス (`tests/js/styles/tokens.test.ts` /
`tests/js/styles/design-system-docs.test.ts`) とは重ならないことを確認する
(実測: `docs/design-system.md` と `contrast-invariant.test.ts` はどの登録にも現れていない)。

> **D28 の本文も同じ変更で直す**: 「保証しないもの」に書かれた
> 「描画されない領域として除くのは HTML コメントと fenced code の 2 つだけで、
> 4 空白字下げのコードブロックと HTML 要素による非表示は見ていない」は S9 で事実が変わる。
> 直す点は 2 つ — (1) 呼称を「描画されない領域」から**「規範判定対象外領域」**へ揃える
> (HTML コメントは非描画、囲みコードは描画されるが規範の本文として数えない)、
> (2) **除くのは 2 つのままだが、タブ・4 連続空白・正規 top-level 以外の 3 連 marker は
> 除かずに検査を失敗させる**。
> 台帳の中身を実態に合わせるのは登録の維持であって新規登録ではない (件数は変わらない)。

### 型適合チェック

- [x] `LedgerPins.php` は `int` 定数の値変更のみ。型は変わらない (PHPStan level 10 に影響なし)
- [x] `declare(strict_types=1)` は既に在る

### テスト計画

- [x] **先に赤くする**: S4 で `contrast-invariant.test.ts` を 1 文字変えた時点で
      `TemplateDivergenceFingerprintTest` が `mutatedDebtPaths` で**赤**になる。
      その赤を確認してから本施策で決着させる
- [x] `TemplateDivergenceLedgerFormatTest` (9 行ちょうど / 値域 / 対象パスの実在と重複なし /
      件数の 3 点一致) が緑であること
- [x] `TemplateDivergenceFingerprintTest` の `doubleDeclaredPaths` が空であること
      (債務から削り忘れると赤)
- [x] `composer test` 全体が緑 (PHP 側の唯一の変更が定数 2 本なので他への波及なし)

### リスク

- 債務件数を減らす変更なので、**掃除の方向**である (D34 の期限つき縮小の趣旨に沿う)。
- D 番号は再利用しない規約なので `D50` / `D51` (現在の最大が `D49`) を使う。
- 件数の 3 点一致 (本文の宣言行 46 → **48** / 見出しの実数 / `DIVERGENCE_ENTRY_COUNT`) を
  同じ変更で揃える。**エントリ形式の例 (`## D1 <逸脱の要約>`) は囲みコードの中なので
  見出しの実数に数えない** — 実測で本文の `## D<n> ` 見出しは 47 個検出されるが、
  うち 1 個がその例である (現行の宣言行 46 と整合)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 12 施策が 1 本の依存鎖でつながっており、途中の状態では**必ず赤いテストが残る**。とくに S5 (合成検査) を入れた時点で 5 組が赤になり、S6 (値の是正) を同じ作業単位で行わなければ main がマージ不能になる。同様に S1 / S2 / S3 / S8 が新 `*.test.ts` を作った時点で既存 `design-system-docs.test.ts` が赤になり、S11 が同じ作業単位に無いと閉じない。S4 が共有パスを触った時点で `TemplateDivergenceFingerprintTest` が赤になり、S12 が同じ作業単位に無いと閉じない。分割すると「赤いまま main に入れる」か「後方互換の並走を残す」のどちらかになり、AGENTS.md 思考原則 3 と禁止事項 1 に触れる |
| 競合リスク | `tests/js/styles/inventory.ts` に 6 つの台帳・分類表を追加するため、同ファイルを触る他タスクと衝突しうる。`docs/TODO.md` の Open は T249 (別 feature「起動 probe の共通 runner 一元化」) のみで、`tests/js/styles/` には触らないため**現時点で衝突なし**。`DESIGN.md` / `resources/css/tokens.css` / `docs/design-system.md` も T249 の対象外 |

### 実装中に「後方互換の並走を残さない」ために同じ作業単位で消すもの (AGENTS.md 思考原則 3)

| 消すもの | 移す先 |
|---|---|
| `canonical-source-parity.test.ts` のローカル `cssColorTokens()` / radius 抽出 / `@utility` 抽出 | `tests/js/styles/theme-map.ts` |
| `PENDING_CONTRAST_PAIRS` の「alpha 合成ペア」の 1 行 | `ALPHA_PAIR_USAGE_LEDGER` + `UNDECIDABLE_PAIR_LEDGER` (pending には判定不能の分類だけが残る) |
| `CONTRAST_EXEMPT_TOKENS` (token 単位の排他な免除) | `COLOR_TOKEN_ROLES` の複数役割 + `NON_TEXT_BOUNDARY_REASONS` + `DECLARED_CONTRAST_PAIRS` |
| `SURFACE_ROLE_TOKENS` / `TEXT_ON_SURFACE_TOKENS` / `FILL_TOKENS` / `FILL_LABEL_TOKENS` の**固定配列** | `COLOR_TOKEN_ROLES` からの導出 (i4: 母集団を固定配列に書かない) |
| `UndecidableReason` の `double-alpha` | 使用箇所台帳の `modifierPercent` として載せ、`resolveAlphaBackground()` が実効値 (積) を作る |
| `resources/js` の `text-white` 3 箇所 | `text-surface` |
| `docs/design-system.md` の「落とすのは HTML コメントと fenced code の 2 つ」 | 「2 つを落とし、4 空白以上の行は検査自体を失敗させる」へ訂正 |
| `docs/design-system.md` の「検査は 4 本ある」 | 「下表に挙げたものがすべてである」(数字を持たない形へ) |
| `adoption-debt.tsv` の 2 行 | `docs/template-divergence.md` の D50 / D51 |

### migration の扱い

**DB migration は 1 本も要らない**。本作業はスタイルの正本・写像・検査・文書・乖離台帳のみで、
スキーマ・モデル・Factory・route・DTO に触れない。したがって
`docs/architecture.md` / `docs/factories.md` への追記も不要である
(新規モデルを追加していないため)。

### 検証コマンド (全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

- `pnpm build` は**必須**である (トークン値を変えるので生成 CSS が変わる)。
- `composer test` は `TemplateDivergenceFingerprintTest` /
  `TemplateDivergenceLedgerFormatTest` を含むので S12 の決着を検証する。

---

## design system 参照

### DESEIGN.md (是正後の全文)

```markdown
---
version: "1.0"
name: Slate × Blue (Neutral)
description: テンプレート既定のニュートラルテーマ。中立的な青を主役に、無彩のスレートを支配色とする。アプリはこのファイルと tokens.css の値を差し替えてテーマを定義する。
colors:
    primary: "#1D4ED8"
    primary-hover: "#1E40AF"
    tertiary: "#115E59"
    tertiary-hover: "#134E4A"
    neutral: "#F4F4F5"
    surface: "#FFFFFF"
    border: "#E4E4E7"
    border-strong: "#A1A1AA"
    text-primary: "#18181B"
    text-secondary: "#52525B"
    success: "#166534"
    warning: "#92400E"
    danger: "#B91C1C"
typography:
    display:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 48px
        fontWeight: 500
        lineHeight: 1.2
        letterSpacing: 0.02em
    h1:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 32px
        fontWeight: 500
        lineHeight: 1.3
        letterSpacing: 0.02em
    h2:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 24px
        fontWeight: 500
        lineHeight: 1.4
    h3:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 18px
        fontWeight: 500
        lineHeight: 1.5
    body:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 16px
        fontWeight: 400
        lineHeight: 1.7
    caption:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 12px
        fontWeight: 400
        lineHeight: 1.5
rounded:
    sm: 4px
    md: 6px
    lg: 8px
spacing:
    xs: 4px
    sm: 8px
    md: 16px
    lg: 24px
    xl: 40px
---

# Design System

本ファイルが**デザインの canonical source**。`resources/css/tokens.css` はその実装写像であり、
独自に値を変えてはいけない(同期契約は `docs/design-system.md`)。

## Overview

テンプレート既定のニュートラルテーマ。中立的な青(#1D4ED8)を主役、teal(#115E59)を強アクセント、
無彩のスレート(#F4F4F5)を背景に据える。**アプリ固有のテーマは frontmatter の色値と
tokens.css の値を差し替えて定義する**(制約体系=影なし・最小色・ramp は維持したまま色だけ変える)。

## Colors

色は意味で割り当てる。順序や見た目の好みで使い分けない。

- **Primary(#1D4ED8)**: ブランドの中核。プライマリボタン、リンク、選択中のナビゲーション。
  1 画面の主要 CTA 以外には濫用しない。
  - tailwind: `bg-primary`, `text-primary`, `border-primary`、hover は `hover:bg-primary-hover`
- **Tertiary(#115E59)**: 強いアクセント。緊急性・重要性のある前向き CTA、特別なバッジに限定。
  1 画面に 1 箇所が原則。
  - tailwind: `bg-tertiary`, `text-tertiary`, `border-tertiary`、hover は `hover:bg-tertiary-hover`
- **Neutral(#F4F4F5)**: 主要な背景色。画面全体はこの色で塗る。
  - tailwind: `bg-neutral`
- **Surface(#FFFFFF)**: カード・モーダル・浮いた要素の背景。Neutral との明度差で奥行きを出す。
  - tailwind: `bg-surface`
- **Border(#E4E4E7)**: 区切り線、入力欄の枠。常に細く(1px)。
  - tailwind: `border-border`
- **Border Strong(#A1A1AA)**: 区切りの強調、ghost ボタンの枠。
  - tailwind: `border-border-strong`
- **Text Primary(#18181B)**: 本文・見出しの主たる色。純黒は使わない。
  - tailwind: `text-text`(`--color-text` を参照)
- **Text Secondary(#52525B)**: 補足文、キャプション、ラベル。
  - tailwind: `text-text-secondary`

### 状態色

- **Success(#166534)**: 完了・正常・公開済み。
  - tailwind: `text-success`, `bg-success`, `border-success`
- **Warning(#92400E)**: 注意・確認が必要・保留。
  - tailwind: `text-warning`, `bg-warning`, `border-warning`
- **Danger(#B91C1C)**: 失敗・破壊的操作・エラー。Tertiary とは別物
  (Tertiary は前向きな強調、Danger は否定的なシグナル)。
  - tailwind: `text-danger`, `bg-danger`, `border-danger`

状態色・アクセントの段は**段の名前ではなくコントラストの実測で決める**。満たすべき条件は 2 つで、
**面として分類した token の上で本文コントラスト 4.5:1** と、
**同じ色のソフト背景(不透明度 10〜12%)の上でも 4.5:1** である。後者が効くため、
実際に選べるのは概ね **-800 段**になる(既定テーマは `tertiary` teal-800 / `success` green-800 /
`warning` amber-800 / `danger` red-700 で、`danger` だけは -700 でも両条件を満たす)。
**段を機械的に揃えるのではなく、`tests/js/architecture/contrast-invariant.test.ts` の
実測で決めること**(不透明ペアと半透明ペアの両方を機械検証する)。

ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
`bg-primary-soft` 等)。**ソフト背景の部品は面として分類した token の上にのみ置く**
(既定テーマでは `neutral` / `surface`)。塗り面(`bg-primary` 等)の上へ重ねると
合成後の実効色が前景と同色になり、どの値を選んでも 4.5:1 を満たせない
(静的走査は親要素を辿れないため、この規約は機械では部分的にしか保証されない —
保証範囲は contrast gate の docblock が持つ)。**新しい色トークンを足す前に opacity 修飾と
atom 化で表現できないか検討すること**(追加条件は `docs/design-system.md` の 4 条件)。

## Typography

全ランプ Noto Sans JP。フォントウェイトは **400 と 500 の 2 階層のみ**(700 は使わない)。
コード・識別子・数値整列には `font-mono` を許可する(日本語 prose には使わない)。

### Typography ramp utility

各 ramp は `resources/css/tokens.css` の `@utility` で定義済。実装はこの utility を
そのまま class として適用する。**raw の `text-sm` / `font-bold` 等は禁止**(ds-purity が検出)。

- **text-display**: 48px / 500 / lh 1.2 / ls 0.02em — tailwind: `text-display`
- **text-h1**: 32px / 500 / lh 1.3 / ls 0.02em — tailwind: `text-h1`
- **text-h2**: 24px / 500 / lh 1.4 — tailwind: `text-h2`
- **text-h3**: 18px / 500 / lh 1.5 — tailwind: `text-h3`
- **text-body**: 16px / 400 / lh 1.7 — tailwind: `text-body`
- **text-caption**: 12px / 400 / lh 1.5 — tailwind: `text-caption`

役割マッピング: 本文/入力値/主要数値 → `text-body`、ラベル/補助情報/日時 → `text-caption`、
page タイトル → `text-h1`/`text-h2`、section/card 見出し → `text-h3`。
強調は `font-medium`(500)を上限とし、足りなければ weight を上げず ramp 昇格+余白+
色階層(text vs text-secondary)でコントラストを作る。

## Layout

8px ベースのスケール。要素間は `md (16px)` を基本に、セクション間は `xl (40px)`。
コンテナは最大幅 1080px を目安に、画面の左右に 32px の余白を確保する。

## Elevation & Depth

**`box-shadow` は使わない。** Neutral(背景)と Surface(カード)の明度差、および 1px の
ボーダーで階層を表現する。ホバー時も影を出さず、ボーダー色や文字色の変化で反応を示す。
グラデーション・scale 効果も使わない。

## Shapes

角丸 ramp は **`rounded-sm`(4px)/ `rounded-md`(6px)/ `rounded-lg`(8px)の 3 段のみ**。
DOM 役割で選ぶ(上から優先): カード・モーダル=`lg` / 中間 box(パネル・`<pre>`)=`md` /
ボタン・入力・バッジ等の小コントロール=`sm`。
素の `rounded`・`rounded-xl` 以上・任意値・方向別(`rounded-t-*` 等)は使わない。
完全円(`rounded-full`)はアバター/status dot/トグル等の**真に円形な UI に限る** ramp 外の例外で、
file-scoped allowlist で個別管理する。

## Components

> component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
> 使い分けルールのみを定義する。
> **本節が対象にするのは DS の再利用部品(`atoms` / `molecules` / `organisms`)である。**
> `features/` のドメイン部品と `templates/` のレイアウト骨格は本節の対象外
> (前者は各 feature の設計が使い分けを決め、後者は §Layout と
> `tests/js/architecture/page-shell-structure.test.ts` が担当する)。
> **対象の component を追加したら本節に追記すること**
> (`tests/js/styles/component-doc-parity.test.ts` が双方向の集合一致で強制する)。

### Button

実装: `components/atoms/Button.svelte`(仕様の真実は `Button.types.ts`)。

| variant | 用途 | スタイル要旨 |
|---------|------|------------|
| `primary` | 主要 CTA(1 画面 1 つ目安) | bg-primary + text-neutral |
| `tertiary` | 真に重要な前向き CTA(1 画面 1 箇所) | bg-tertiary + text-neutral |
| `ghost` | 補助・キャンセル | 透明 + border-border-strong、hover で primary 化 |
| `neutral` | 取消可能・UI-only の補助操作(一時停止等) | bg-neutral + 常時 border(境界確保) |
| `success` | 肯定操作(追加・承認・付与) | bg-success + text-neutral |
| `danger` | dialog/form の主破壊 CTA | bg-danger + text-neutral |
| `danger-outline` | section 単位の破壊(card 内の削除) | border-danger、hover で塗り |
| `danger-ghost` | dense な row/list 内の破壊アクション | text-danger + 透明、hover で淡い tint |

- **全 variant が border(透明 or 色)を持ち外形高さを統一する**
- danger 系は irreversible / destructive 操作専用(削除・revoke・移譲・再開不可の中断)。
  危険度ではなく**配置文脈**で 3 重みを選ぶ
- **anchor 対応**: `href` 指定で `<a>`(`inertia` 指定で Inertia Link)。anchor モードでは
  `type`/`disabled` は型レベルで禁止。`target="_blank"` には `rel="noopener noreferrer"` を自動補完
- **iconOnly**: `ghost` / `neutral` / `danger-ghost` のみ許可。`ariaLabel` が型で必須
- **disclosure**: button モード限定で `ariaExpanded` / `ariaControls` / `element`(bindable な
  `HTMLButtonElement` 参照)を受ける。ハンバーガー等のトグルはこれを使い素の `<button>` を書かない
- size: `sm`(caption)/ `md`(既定)/ `lg`(form 入力面との高さ整合限定)

### Input / Textarea / Select(入力系 atom)

実装: `components/atoms/Input.svelte` / `Textarea.svelte` / `Select.svelte`。
見た目は `components/atoms/input-state.ts`(`INPUT_BASE_CLASSES` + `inputStateClass`)に集約し、
入力系 atom 間で統一する。`error` prop で danger 枠と `aria-invalid` が連動する。
`aria-describedby` 等は restProps で透過。Select の `<option>` 群は呼び出し側が
children snippet として記述する。Input の `type` は text 系に限定した union。
ラベル・エラー文言・`aria-describedby` の配線は FormField molecule の責務
(入力 atom は最小責務に保つ)。パスワード入力は素の `Input type="password"` ではなく
PasswordInput molecule を使う。

- **`type` は入力補助であって検証手段ではない**。`email` / `tel` / `url` / `number` 等は
  モバイルキーボード・autofill・スクリーンリーダーの型アナウンスのために付ける。
  検証の正本はサーバ(日本語)と押下時の client エラーで、native constraint validation には
  依存しない(form 側で `novalidate`。§Do's and Don'ts)。`inputmode` は restProps で透過する
- **readonly は「編集できない」ことを面で示す**(`Input` / `Textarea` の `readonly` prop)。
  `bg-neutral` + `cursor-default`。ただし **disabled と同じ見た目にしない** — readonly の値は
  生きている(送信される・選択してコピーできる・フォーカスできる)ので、文字色は `text-text` の
  ままにし focus ring も維持する。disabled は `text-text-secondary` + `cursor-not-allowed` +
  フォーカス不可。`<select>` は HTML 仕様上 readonly を持たない(編集させないなら値を
  読み取り表示にする)
- 「編集させない値」の表現は 2 通り。**そのフォームの送信対象に含む / コピーさせたい**なら
  readonly input(例: 招待 email の prefill、権限が無い閲覧者への設定値提示)、
  **編集手段自体を出さない**なら読み取り表示(`<dl>` 等。例: 請求先情報カードの非管理者表示)。
  readonly input を選んだ場合、上記の見た目が付くことは atom が保証する

### Checkbox

実装: `components/atoms/Checkbox.svelte`。インラインラベル(右側)とエラー表示
(FormError 内包)を持つチェックボックス。ラベルは string のほか snippet でも受けられる
(利用規約リンク等を含める用)。複数行ラベルでもチェックボックスが 1 行目に揃う行揃えは
本 atom の責務。ページ側で素の `<input type="checkbox">` を書かない(§Do's and Don'ts)。

### FormError

実装: `components/atoms/FormError.svelte`。フィールド単位のエラー文言
(`text-caption text-danger`。message が無ければ何も描画しない)。FormField / Checkbox から
composition される前提の最小 atom。単体で使う場合、`aria-describedby` の配線は呼び出し側の
責務。ページ常在の通知は Alert、一時通知は Toast を使う。
**フィールドに紐づかない失敗(ceremony 失敗・端末非対応等)を FormError に流さない**
(原因と提示先が食い違い、「パスキー失敗がパスワード欄の赤字として出る」species のバグになる)。
非フィールド起因は Alert(§Alert)。

### Avatar

実装: `components/atoms/Avatar.svelte`。`src` があれば画像、無ければ `name` の先頭 1 文字
(大文字化。サロゲートペアも 1 文字扱い)をイニシャル表示する。アバターは真に円形な UI
のため `rounded-full` を使う ramp 外例外(Toggle と並び ds-purity の file-scoped allowlist
出荷時 2 件の 1 つ)。size: `sm` / `md`(既定)/ `lg`。

### Badge

実装: `components/atoms/Badge.svelte`(仕様の真実は `Badge.types.ts`)。状態・属性の
**結果表示**ラベル(操作は Button。action button と status badge は意味色を独立に判断する
— §色の意味的割り当てルール)。tone: `primary` / `tertiary` / `success` / `warning` /
`danger` / `neutral`(中立ラベル)。既定は soft(tone 色の淡い背景 + tone 色文字)、
`bordered` は tone 色 border を atom 内で付与する(呼び出し側から border を足さない)。
左アイコン 1 つを snippet で受け、size/色の責務は Badge 内 wrapper に閉じる。
小コントロールなので `rounded-sm`。size: `sm`(既定)/ `md`。

### Card

実装: `components/atoms/Card.svelte`。浮いた要素の基本サーフェス
(`bg-surface border border-border rounded-lg`。影を使わず明度差 + 1px border で階層を
表現する — §Elevation & Depth)。padding: `none`(table/list 等を内包し内側で個別に
padding を制御する箱用)/ `sm` / `md`(既定)/ `lg`。

### QrCodeImage

実装: `components/atoms/QrCodeImage.svelte`。**サーバが生成した SVG 文字列を
data URI の `<img>` として描く**。生の HTML を DOM へ差し込む構文 (`{@html}`) を
使わずに QR を表示するための**唯一の手段**であり、lint 規則
`svelte/no-at-html-tags` (`eslint.config.js`) と対で 1 組である。
props は `svg: string`(必須)/ `alt: string`(必須)/ `testId`。
**`class` は受けない** — 寸法・装飾は呼び出し側の wrapper が持つ。
`svg` は **null 許容にしない** — 取得中・取得失敗の分岐は呼び出し側が持つ。
アクセシブルネームの正本は `alt` なので、wrapper 側に `role="img"` を重ねない。
data URI は percent encoding で作る(`btoa()` は非 ASCII の SVG で例外を投げる)。
CSP の `img-src` が `data:` を含むことに依存しており、
`tests/Feature/Security/SecurityHeadersTest.php` が 2 構成で pin している。

### Spinner

実装: `components/atoms/Spinner.svelte`。LoaderCircle(@lucide/svelte)+ `animate-spin`。
色は currentColor 継承(置かれた文脈の文字色に従う)。既定は装飾扱い(`aria-hidden`)で、
単独のローディング表示に使うときだけ `label` を渡す(`role="status"` + sr-only で
読み上げ)。size: `sm` / `md`(既定)/ `lg` / `xl`。

### TextLink

実装: `components/atoms/TextLink.svelte`(仕様の真実は `TextLink.types.ts`)。
リンク風 `<a>` / `<button>` の手書きは禁止(§Do's and Don'ts)、本 atom を使う。
3 モードの discriminated union: (a) `href` のみ = Inertia Link(SPA 遷移)、
(b) `href` + `external` = ネイティブ `<a>` + 別タブ + `rel="noopener noreferrer"` +
末尾 ExternalLink アイコン(`icon` で差し替え可)、(c) `onclick` のみ = リンク風
`<button type="button">`。様式は `text-primary` + 下線(hover で下線が濃くなる)で 3 モード共通。

### Toggle

実装: `components/atoms/Toggle.svelte`(仕様の真実は `Toggle.types.ts`)。
オン/オフを**即時反映**する設定スイッチ(ネイティブ `<button>` + `role="switch"` +
`aria-checked`)。フォーム送信を伴う選択には使わない。`ariaLabel` は型レベルで必須。
トラックは On=`bg-primary` / Off=`bg-border-strong`、つまみは `bg-surface`(影なし、
明度差で表現)。`rounded-full` は真に円形な UI の例外として file-scoped allowlist で管理する。

### DragHandle

実装: `components/atoms/DragHandle.svelte`(仕様の真実は `DragHandle.types.ts`)。
並べ替えのつかみ手。アイコンは `GripVertical` 固定で差し替えない(つかめる場所の合図を
画面間で揃える)。`touch-none` でタッチをスクロールに奪わせず、小コントロールなので
`rounded-sm`。キーボード操作は上下キーで、つかみ手自身がフォーカスを受ける。
**並べ替えができない状態の表現は本 atom では決めない**(禁止事項 8 は「必須条件未充足を
理由に disabled にする」ことの禁止であって、あらゆる disabled の禁止ではない。
並べ替え自体が成立しない場面では呼び出し側がつかみ手を出さない)。

### Modal

実装: `components/organisms/Modal.svelte`(仕様の真実は `Modal.types.ts`)。bits-ui Dialog のラップ。

- overlay は `bg-text/50`(墨色 50%。黒 hex を使わない)、本体は `bg-surface border border-border rounded-lg`
  (影が使えないためボーダーで背景と区別する)
- size: `sm`(max-w-md)/ `md`(max-w-lg 既定)/ `lg`(max-w-2xl)
- `processing` 中は ESC / overlay クリックでの close を抑止し、X ボタンを disabled にする(二重実行防止)
- title は `text-h3`。a11y 名は bits-ui `Dialog.Title` 経由で `aria-labelledby` に配線される

### ConfirmDialog

実装: `components/organisms/ConfirmDialog.svelte`(仕様の真実は `ConfirmDialog.types.ts`)。Modal の composition。

- `confirmVariant` は `primary` / `danger` の 2 値のみ。**irreversible / destructive な操作は danger**
  (§色の意味的割り当てルール)
- footer は Button atom(cancel=`ghost` / confirm=`confirmVariant`、processing 中は loading)
- confirm で自動 close しない(処理完了後に呼び出し側が `open=false` にする)。
  cancel / ESC / overlay / X は `onCancel` を発火して close
- `banner?: Snippet` は message 直上の任意スロット(サーバ validation エラーの Alert 等)。
  未指定なら描画されない(既存の出力は不変)

### Toast

実装: `components/organisms/ToastContainer.svelte` + `lib/stores/toast.ts`(addToast / dismissToast)。
Laravel flash の取り込みは `lib/stores/flash-to-toast.ts` の `consumeFlash`(visitKey で de-dup)。

- 上部中央 fixed(`top-6 left-1/2 -translate-x-1/2 z-50`)に縦 stack 表示。アプリで 1 箇所のみ mount する
  (mount するのは layout: AppLayout / AuthLayout / GuestLayout の 3 種。ページ側では mount しない)
- 自動消去: **success / info / warning = 4 秒、error = 手動閉じのみ**
- 消去境界: **layout(AppLayout / AuthLayout / GuestLayout)の初期化時に既存 toast を破棄**してから
  当該 visit の flash を消費する。= **layout が再初期化される遷移**では toast を持ち越さない
  (認証済み文脈の toast を未認証面へ出さない)。`preserveState` の visit / partial reload は
  layout を再初期化しないため toast は残る。別タブの既表示 toast の即時消去は保証しない
- 各 toast は `bg-surface` + type 別 border / アイコン色(success / primary(info)/ warning / danger)。
  アイコンは CircleCheck / Info / TriangleAlert / CircleX(@lucide/svelte)
- a11y: `role="status"`(error のみ `role="alert"`)

### Alert

実装: `components/atoms/Alert.svelte`。ページ内に常在するインライン通知ボックス
(一時通知は Toast、フィールド単位のエラーは FormField/FormError を使う)。

- type: `success` / `warning` / `danger` / `info`(info は primary を流用。Toast と同じ規約)
- 配色: ボーダー=状態色、見出し(title 任意)=状態色、本文=`text-text`、背景=`bg-surface`。
  テーマ色を面塗りに使わない。中間 box なので `rounded-md`
- `action` snippet(本文下の CTA)、`dismissible` + `onDismiss`(右上の X)を持つ
- a11y: **danger のみ `role="alert"`(assertive)**、他は `role="status"`(polite)
- **非フィールド起因の操作失敗は Alert**。フォームのフィールドに紐づかない失敗
  (WebAuthn ceremony 失敗・端末非対応・ネットワーク失敗など)は、操作したその場に残る
  Alert で出す。FormError は**フィールド単位**のエラー専用であり、Toast は「一時通知」なので、
  押した直後に読ませたい失敗理由を画面外(上部中央)へ飛ばさない

### FormField

実装: `components/molecules/FormField.svelte`。ラベル + 入力 + エラー(FormError)+
ヘルプの複合 molecule。入力 atom を最小責務に保つため、ラベル・エラー文言・
`aria-describedby` の配線は本 molecule が担う(関心分離)。children snippet に
`{ id, describedBy, invalid }` を渡すので、呼び出し側はそれを入力 atom へ流し込む。
`required` は `*`(danger 色、`aria-hidden`)をラベルに付与する。フォームの入力欄は
本 molecule 経由で組む(AGENTS.md 実装規約)。

- **押下時に出した client エラーは、その後の入力に追随させる**(stale invalid を残さない)。
  ボタンを disabled にしない(§Do's and Don'ts / AGENTS.md 禁止事項 8)代わりに押下時にエラーを
  出すのだから、そのエラーは常に「今の入力」を説明していなければならない — 有効に戻ったら消え、
  無効の理由が変わったら文言も変わる。押下前には出さない。
  **canonical なのはこの不変条件であって実装形ではない**。実装は
  **「提示を開始したかの boolean」+ 文言は `$derived`** で組むのが既定(文言を `$state` で
  持つと同期漏れが起きる。`$effect` での状態同期はしない = Svelte 公式の指針)。
  先行実装(`Billing/PurchaseTickets.svelte` / `Organizations/Settings.svelte`)は `$effect` に
  よる連動クリアで**同じ不変条件を満たしており、そのまま許容する**(動いている仕組みを
  churn させない)。**新規は `$derived` 形で書く**
- サーバ由来の errors(`form.errors.*`)はこの追随の対象外。入力の変更で消さない

### DangerZone

実装: `components/molecules/DangerZone.svelte`。破壊的・取り返しのつかない操作
(アカウント削除等)を集約する警告セクション(presentational・状態なし)。
`border-danger/30` + 淡い danger 背景の枠に title(danger 色 `text-h3`)+ 任意の
description、children には danger 系 Button(card 内なら `danger-outline`)を置く。
`<section>` + `aria-labelledby` で region 境界に accessible name を紐付ける。
複数同居時は `idBase` で id 衝突を回避する。

### Divider

実装: `components/molecules/Divider.svelte`。区切り線の正規化(「または」セパレータ等)。
`label` 指定時は中央ラベル付き区切り(線は `aria-hidden`、ラベルは `bg-surface` で線を
切り抜く)、省略時は素の `<hr>`。余白は呼び出し側が class で渡す(`my-6` 等)。

### Pagination

実装: `components/molecules/Pagination.svelte`。前へ / ページ番号 / 次へのページ送り UI。
callback ベース(ページング state は親が持ち、`currentPage` / `totalPages` を受けて
`onChange(page)` を返す)で遷移手段を持たないため、全て `<button type="button">` で構成する
(Inertia 遷移かローカル state 更新かは呼び出し側裁量)。総ページ ≤ 7 は全番号表示、
超過時は先頭・末尾 + 現在ページ ± 1 の窓を出し、飛びに省略記号を挿入する最小実装。
`<nav>` ランドマーク + 現在ページに `aria-current="page"`。

### Tabs

実装: `components/molecules/Tabs.svelte`。**同一ページ内 section 切替**の WAI-ARIA タブバー
(tablist のみ。URL 遷移で切り替えるページ間タブは ApiKeyTabNav のような専用 molecule を
使う)。パネル本体の描画は呼び出し側責務(god component 回避)で、
`id="{idBase}-panel-{tab.id}"` / `role="tabpanel"` / `aria-labelledby` を id 生成規則に
揃えて配線する。キーボードは ←/→(端でラップしない)+ Home/End、自動アクティベーション +
roving tabindex(active のみ tabindex=0)。`active` は bindable、`idBase` は必須
(複数同居時の id 衝突回避)。

### PasswordInput

実装: `components/molecules/PasswordInput.svelte`。Input atom + 右端の Eye/EyeOff トグルで
`password` ↔ `text` を即時切替する(button トグル + `aria-pressed`)。`id` は必須
(トグルの `aria-controls` に結線)。label/error 配線は FormField 側が担う。
Auth 系のパスワード入力は素の `Input type="password"` ではなく本 molecule を使う。

### CodeSnippet

実装: `components/molecules/CodeSnippet.svelte`。コピー付きコードブロック
(API キー・リカバリコード・CLI コマンド等)。コピー処理(navigator.clipboard)は
component 内に内包し、成功「コピー完了」/失敗「コピー失敗」を 2 秒表示する。
`<pre>` は `rounded-md bg-neutral` + `font-mono text-caption`。

### StatCard

実装: `components/molecules/StatCard.svelte`。Card atom に label(`text-caption`)+
value(`text-h2`。weight でなく ramp 昇格で強調)+ 任意の subtext / Lucide icon
(`bg-primary-soft` の rounded-md box)を載せる統計カード。

### EmptyState

実装: `components/molecules/EmptyState.svelte`。リストやテーブルが空のとき、次の行動を
案内する空状態表示。`description`(必須)+ 任意の `title` / Lucide `icon`(装飾なので
`aria-hidden`、`size-10`)。`cta` は discriminated union で遷移(`kind: "link"` = Button
の anchor+inertia)と操作(`kind: "action"` = onclick)を型安全に出し分ける。`bordered`
で破線枠サーフェス(`border-dashed`。drop 領域や明示的な空 region 向け)。

### Breadcrumb

実装: `components/molecules/Breadcrumb.svelte`。`BreadcrumbItem[]`(`@/types/components`)を
`ChevronRight` 区切りで並べるパンくず。**`href` 省略の項目は現在位置**としてリンクにしない。
atom 非依存(Lucide アイコンのみ)。単体で置かず、通常は PageHeaderSection 経由で出す。

### PageHeader / PageHeaderSection

実装: `components/molecules/PageHeaderSection.svelte`(full feature)と
`components/molecules/PageHeader.svelte`(shorthand)。

- **PageHeaderSection**: `title` / `breadcrumbs` / `description` / `icon`(Lucide 互換
  `Component`)/ actions(`children` Snippet)を持つ詳細画面用ヘッダ。全幅バーは
  PageContainer の padding を打ち消す**負マージン契約**で敷き、サイドバーのロゴブロックと
  同じ高さに揃える。**パンくずは 2 件以上のときだけ出す**(1 件は h1 と二重提示になるため)。
- **PageHeader**: breadcrumbs / actions を使わないルート画面用の薄いラッパー。
  内部で PageHeaderSection を呼ぶだけ。**actions や breadcrumbs が要るなら
  PageHeaderSection を直接使う**(PageHeader に prop を足さない)。
- actions は children Snippet で渡す(旧 slot API は使わない)。

### NotificationBell

実装: `components/molecules/NotificationBell.svelte`。`/organizations/{slug}/notifications` への Inertia link に
未読数バッジを重ねた通知ベル。未読数は shared props(`notifications.unreadCount`)を親が渡す。
**100 以上は `99+` に丸める**。v1 はドロップダウンを持たない最小構成(フォーカス管理・
開閉状態を持たない)。**通知はこのベルが単一導線**で、サイドバー nav 項目に重複掲載しない。
`data-testid` は既定 `notification-bell`(mobile は呼び出し側が `notification-bell-mobile`)。

### PricingPlanCard

実装: `components/molecules/PricingPlanCard.svelte`(仕様の真実は `PricingPlanCard.types.ts`)。
料金プランカード。**DTO 非依存**(primitive props)で、feature 文言と CTA は呼び出し側が
props / Snippet で供給する。

- `priceAmount` が **null = 基本料金を持たない = 「無料」表示**(0 も防御的に同一表示)。
- `priceCaption`(例: 「基本料金」)は表示価格が総額と誤解されるのを防ぐための価格直上の説明。
- `isHighlighted` で `border-primary` の強調枠(現在のプラン等)。
- `headerBadges`(header 右上)/ `footerCta`(card 下部)は Snippet 専用スロット。

### ApiKeyTabNav

実装: `components/molecules/ApiKeyTabNav.svelte`。API キー管理ドメインのページ間
(API キー ⇔ 接続セッション ⇔ 導入ガイド)を **URL 遷移**(Inertia `Link`)で切替えるタブナビ。
同一ページ内 section 切替の `molecules/Tabs.svelte` とは責務が異なる。`tabs`(label + href +
active)はページ側が組み立てる(どのタブを出すか・URL は呼び出し側責務)。active タブに
`aria-current="page"` を付与する。

### RecentAuthModal

実装: `components/organisms/RecentAuthModal.svelte`(Modal の composition)。機微操作
(API キー発行/失効・アカウント削除・オーナー移譲)の前に出す**同一画面の再認証(step-up)
モーダル**。パスワード設定済みは再入力 → `POST /recent-auth/password`(成功は XHR 204)、
再 SSO 可能な provider は `reauthUrl` へフルリダイレクト、パスキー登録済みは WebAuthn 検証。
認可の最終ゲートは各操作の recent-auth middleware で、本モーダルは UX 補助。

- **props 契約は `status: RecentAuthStatus | null` の 1 本**(`bind:open` / `onConfirmed` を除く)。
  `/recent-auth/status` の応答を field へ分解して手渡さない — field が増えるたびに配線漏れが
  生まれる(T106 で `passkeyAvailable` を足した際、6 呼び出し中 5 箇所が未配線のまま出荷され
  passkey-only ユーザーが 5 画面で詰んだ)。`tsc --noEmit` は `.svelte` テンプレートを型検査
  しないため、強制点は `tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts`
  (deny-by-default。`status={recentAuthStatus}` の識別子・旧 prop 不在・`onStale` での代入まで検査)
- `status === null` は**状態不明**として扱い、空表示や事実に反する文言を出さず再読み込み導線を出す
- 再認証が成立しないユーザー(`canSatisfy=false` / この端末で実行不能)への回復導線は
  **`molecules/RecentAuthRecoveryNotice` に集約**する(下記)

### RecentAuthRecoveryNotice

実装: `components/molecules/RecentAuthRecoveryNotice.svelte`。再認証(step-up)が**この場では
成立しない**ユーザーに出す回復導線。全画面 confirm(`pages/Auth/ConfirmRecentAuth`)と
インラインモーダル(`organisms/RecentAuthModal`)の**両方が使う唯一の実装**(分けて持つと
片方だけ旧作法が残る)。

- `variant`: `no-satisfier`(アカウントに手段が無い)/ `not-executable-here`(手段はあるが
  この端末で実行できない = パスキー非対応ブラウザ)
- **`/forgot-password` へ直接リンクしない**。Fortify が `guest` middleware 付きで登録しており
  ログイン済みの本 UI 利用者はフォームに到達できない(踏破不能 CTA)。案内するのは
  「ログアウト → guest としてパスワード再設定」の経路だけ。アプリ内の初回設定
  (`POST /settings/password`)は recent-auth 必須なので、ここに来ているユーザーには使えない
- ログアウトは **Inertia visit(`router.post`)**(経路 C の保証条件。
  `tests/js/architecture/logout-call-site-inventory.test.ts` が inventory で固定)
- molecule 配置は構造的制約: 呼び出し元の RecentAuthModal は organism であり、
  atomic-import-graph 上 organism は features 層を import できない

### OrganizationChoiceCard

実装: `components/molecules/OrganizationChoiceCard.svelte`。組織を 1 件選んで移動する遷移カード。
**遷移先 URL は親が渡す**(組織文脈の解決は molecule の責務ではない)。カード全体が 1 つの
リンクで、名前と補足の 2 段構成。選択済みの状態は持たない(選ぶ操作そのものが遷移である)。

### PendingInvitationsNotice

実装: `components/molecules/PendingInvitationsNotice.svelte`。自分宛の**保留中招待の件数だけ**を
出す誘導専用 notice。**受諾 UI は持たない**(受諾は通知一覧で行う)。
背景は `bg-primary-soft/40`、hover で `bg-primary-soft`。件数 0 のときは呼び出し側が出さない。

### SubtitleOverlay

実装: `components/molecules/SubtitleOverlay.svelte`。映像へ重畳する字幕 overlay。
**焼き込みではなく DOM overlay** である(MediaRecorder の stream には含まれない)。
`primary` は上部帯、`secondary` は下部のメイン字幕で、位置は合成側の `AssSubtitleWriter`
(ASS)と一致させる。長文は line-clamp で省略する。帯は `bg-text/70` + `text-surface`。

## Do's and Don'ts

**Do**

- 背景は常に neutral、浮いた要素は surface(逆に使わない)
- 余白を多めにとる。色は Primary / Tertiary / 状態色 1 種までを目安に
- 操作の可否は**押した後のフィードバック**で伝える(バリデーションエラー表示+フォーカス移動)
- **認証フロー画面(`AuthLayout`)には離脱導線を footer に必ず置く**。その手順を完了できない
  ユーザー(リンク期限切れ・コード紛失・再認証手段なし)が別の入口へ抜けられる `TextLink` を
  `{#snippet footer()}` に 1 つ以上持つ。行き先は**その画面のユーザーの認証状態で実際に
  踏破できる先**に限る(`tests/js/architecture/page-shell-structure.test.ts` が機械強制。
  例外は理由付き allowlist)

**Don't**

- グラデーション・ドロップシャドウ・scale 効果を使わない
- Danger と Tertiary を同一 action cluster・隣接 CTA 群で併置しない(赤系・強調系の意味が混ざる)
- **必須条件未充足を理由にボタンを disabled でブロックしない**。ボタンは活性のまま、
  押下時に何が足りないかをエラー表示する(例: 利用規約同意チェック。
  disabled はユーザーに「なぜ押せないか」を伝えられない)
- **表示条件と踏破条件が食い違う導線を出さない**。押しても必ず失敗するボタン・リンク
  (認証・権限・ゲートで確実に弾かれる先を指すもの)は**出さずに、なぜ今は進めないかを
  文章で説明する**。disabled 化でも代替しない(上の Don't と同根。例: メール未認証画面から
  `verified` ゲート内の checkout へ進む CTA)
- **生の HTML を DOM へ差し込む構文 (`{@html}`) を書かない**。値の出どころが 1 か所でも
  汚れていれば script がそのまま実行される。`eslint.config.js` の
  `svelte/no-at-html-tags` が error で落とし、inline コメントでの無効化も効かない
  (`noInlineConfig`)。**許可一覧の口は無い** — 例外を設けるなら、その口を排除できない
  理由・安全境界・専用テストを含む別のセキュリティ設計としてレビューを通すこと。
  サーバ生成の SVG (2 要素認証の QR) には `QrCodeImage` atom を使う。
  実効性の裏取りは `tests/js/architecture/svelte-raw-html-gate.test.ts`。
  なお同 gate は**字面**で数えるため、`resources/js` 配下の `.svelte` では
  コメントであってもこの構文の字面を書けない(「raw HTML 挿入構文」と呼び名で書く)
- ページ内で素の `<input>` / `<table>` / リンク風 `<a>` 手書きをしない(対応する atom/molecule を使う)
- **native の constraint validation に検証を任せない**。`<form>` には `novalidate` を付け、
  検証文言はサーバ(日本語)と押下時の client エラーに一本化する。
  native validation は submit より先に発火してブラウザロケール依存の文言で送信を止めるため、
  日本語 UI の検証経路に到達できなくなる(`tests/js/architecture/form-novalidate.test.ts` が機械検証)

## 色の意味的割り当てルール

- **danger** = irreversible な喪失・破壊(削除・revoke・unassign・移譲・再開不可の中断)。
  確認 dialog があっても操作自体が不可逆ならボタン色は danger
- **warning** = 注意喚起 / 保留 / 可逆な要確認状態
- **tertiary** = 前向きな強調のみ(1 画面 1 箇所)
- **primary** = ブランド中核 / 主要 CTA / 選択中
- **neutral / text-secondary** = 中立・取消可能・UI-only の補助操作

action button(操作)と status badge(結果表示)は意味色を**独立に判断**する。

```

### resources/css/tokens.css (是正後の全文)

```css
/**
 * DS tokens — DESIGN.md (canonical source) の実装写像。
 *
 * 値の変更は DESIGN.md / docs/design-system.md と同一 PR で必ず行うこと
 * (tests/js/styles/canonical-source-parity.test.ts が drift を検出する)。
 *
 * 取り込み契約:
 *   本ファイルは単独でビルドしない。常に Tailwind 処理コンテキスト
 *   (`@import "tailwindcss"` の直後) から取り込まれることを前提とする。
 */

@theme {
    /* ===== Brand colors (DESIGN.md Slate × Blue) ===== */
    --color-primary:         #1d4ed8;
    --color-primary-hover:   #1e40af;
    --color-primary-soft:    rgba(29, 78, 216, 0.12);  /* primary 12% — badge / focus ring 用 */
    --color-tertiary:        #115e59;
    --color-tertiary-hover:  #134e4a;

    /* ===== Neutrals & surface ===== */
    --color-neutral:         #f4f4f5;  /* page background */
    --color-surface:         #ffffff;  /* card / modal background */
    --color-border:          #e4e4e7;
    --color-border-strong:   #a1a1aa;
    --color-text:            #18181b;
    --color-text-secondary:  #52525b;

    /* ===== Status colors ===== */
    --color-success:         #166534;
    --color-warning:         #92400e;
    --color-danger:          #b91c1c;

    /* ===== Fonts ===== */
    --font-sans:  'Noto Sans JP', 'Hiragino Sans', 'Yu Gothic UI', 'Segoe UI',
                  ui-sans-serif, system-ui, sans-serif,
                  'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';

    /* ===== Radius (DESIGN.md rounded) ===== */
    --radius-sm: 4px;
    --radius-md: 6px;
    --radius-lg: 8px;
}

/* ===== Typography ramp =====
   全ランプ Noto Sans JP、ウェイトは 400 / 500 の 2 階層のみ (DESIGN.md 準拠) */

@utility text-display {
    font-family: var(--font-sans);
    font-size: 48px;
    font-weight: 500;
    line-height: 1.2;
    letter-spacing: 0.02em;
}

@utility text-h1 {
    font-family: var(--font-sans);
    font-size: 32px;
    font-weight: 500;
    line-height: 1.3;
    letter-spacing: 0.02em;
}

@utility text-h2 {
    font-family: var(--font-sans);
    font-size: 24px;
    font-weight: 500;
    line-height: 1.4;
}

@utility text-h3 {
    font-family: var(--font-sans);
    font-size: 18px;
    font-weight: 500;
    line-height: 1.5;
}

@utility text-body {
    font-family: var(--font-sans);
    font-size: 16px;
    font-weight: 400;
    line-height: 1.7;
}

@utility text-caption {
    font-family: var(--font-sans);
    font-size: 12px;
    font-weight: 400;
    line-height: 1.5;
}

```

### 触れた atomic ディレクトリ

- `resources/js/components/templates/AppLayout.svelte` (text-white → text-surface。2 箇所)
- `resources/js/components/templates/_helpers/SidebarNavItems.svelte` (同上。1 箇所)
- 新規 component の追加は無い。`resources/js/components/` 配下のその他のファイルは変更していない

## 実装差分 (git diff HEAD)

```diff
diff --git a/DESIGN.md b/DESIGN.md
index 3e685440..6234e5c2 100644
--- a/DESIGN.md
+++ b/DESIGN.md
@@ -3,18 +3,18 @@
 name: Slate × Blue (Neutral)
 description: テンプレート既定のニュートラルテーマ。中立的な青を主役に、無彩のスレートを支配色とする。アプリはこのファイルと tokens.css の値を差し替えてテーマを定義する。
 colors:
-    primary: "#2563EB"
-    primary-hover: "#1D4ED8"
-    tertiary: "#0F766E"
-    tertiary-hover: "#115E59"
+    primary: "#1D4ED8"
+    primary-hover: "#1E40AF"
+    tertiary: "#115E59"
+    tertiary-hover: "#134E4A"
     neutral: "#F4F4F5"
     surface: "#FFFFFF"
     border: "#E4E4E7"
     border-strong: "#A1A1AA"
     text-primary: "#18181B"
     text-secondary: "#52525B"
-    success: "#15803D"
-    warning: "#B45309"
+    success: "#166534"
+    warning: "#92400E"
     danger: "#B91C1C"
 typography:
     display:
@@ -68,7 +68,7 @@ # Design System
 
 ## Overview
 
-テンプレート既定のニュートラルテーマ。中立的な青(#2563EB)を主役、teal(#0F766E)を強アクセント、
+テンプレート既定のニュートラルテーマ。中立的な青(#1D4ED8)を主役、teal(#115E59)を強アクセント、
 無彩のスレート(#F4F4F5)を背景に据える。**アプリ固有のテーマは frontmatter の色値と
 tokens.css の値を差し替えて定義する**(制約体系=影なし・最小色・ramp は維持したまま色だけ変える)。
 
@@ -76,10 +76,10 @@ ## Colors
 
 色は意味で割り当てる。順序や見た目の好みで使い分けない。
 
-- **Primary(#2563EB)**: ブランドの中核。プライマリボタン、リンク、選択中のナビゲーション。
+- **Primary(#1D4ED8)**: ブランドの中核。プライマリボタン、リンク、選択中のナビゲーション。
   1 画面の主要 CTA 以外には濫用しない。
   - tailwind: `bg-primary`, `text-primary`, `border-primary`、hover は `hover:bg-primary-hover`
-- **Tertiary(#0F766E)**: 強いアクセント。緊急性・重要性のある前向き CTA、特別なバッジに限定。
+- **Tertiary(#115E59)**: 強いアクセント。緊急性・重要性のある前向き CTA、特別なバッジに限定。
   1 画面に 1 箇所が原則。
   - tailwind: `bg-tertiary`, `text-tertiary`, `border-tertiary`、hover は `hover:bg-tertiary-hover`
 - **Neutral(#F4F4F5)**: 主要な背景色。画面全体はこの色で塗る。
@@ -97,22 +97,29 @@ ## Colors
 
 ### 状態色
 
-- **Success(#15803D)**: 完了・正常・公開済み。
+- **Success(#166534)**: 完了・正常・公開済み。
   - tailwind: `text-success`, `bg-success`, `border-success`
-- **Warning(#B45309)**: 注意・確認が必要・保留。
+- **Warning(#92400E)**: 注意・確認が必要・保留。
   - tailwind: `text-warning`, `bg-warning`, `border-warning`
 - **Danger(#B91C1C)**: 失敗・破壊的操作・エラー。Tertiary とは別物
   (Tertiary は前向きな強調、Danger は否定的なシグナル)。
   - tailwind: `text-danger`, `bg-danger`, `border-danger`
 
-状態色・アクセントは Tailwind の **-700 段**で揃える(`tertiary` teal-700 / `success` green-700 /
-`warning` amber-700 / `danger` red-700)。`neutral`(#F4F4F5)や `surface`(#FFFFFF)の上で
-**本文コントラスト 4.5:1** を確保するための下限であり、これより明るい段は使わない
-(`tests/js/architecture/contrast-invariant.test.ts` が機械検証する)。
+状態色・アクセントの段は**段の名前ではなくコントラストの実測で決める**。満たすべき条件は 2 つで、
+**面として分類した token の上で本文コントラスト 4.5:1** と、
+**同じ色のソフト背景(不透明度 10〜12%)の上でも 4.5:1** である。後者が効くため、
+実際に選べるのは概ね **-800 段**になる(既定テーマは `tertiary` teal-800 / `success` green-800 /
+`warning` amber-800 / `danger` red-700 で、`danger` だけは -700 でも両条件を満たす)。
+**段を機械的に揃えるのではなく、`tests/js/architecture/contrast-invariant.test.ts` の
+実測で決めること**(不透明ペアと半透明ペアの両方を機械検証する)。
 
 ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
-`bg-primary-soft` 等)。**新しい色トークンを足す前に opacity 修飾と atom 化で表現できないか
-検討すること**(追加条件は `docs/design-system.md` の 4 条件)。
+`bg-primary-soft` 等)。**ソフト背景の部品は面として分類した token の上にのみ置く**
+(既定テーマでは `neutral` / `surface`)。塗り面(`bg-primary` 等)の上へ重ねると
+合成後の実効色が前景と同色になり、どの値を選んでも 4.5:1 を満たせない
+(静的走査は親要素を辿れないため、この規約は機械では部分的にしか保証されない —
+保証範囲は contrast gate の docblock が持つ)。**新しい色トークンを足す前に opacity 修飾と
+atom 化で表現できないか検討すること**(追加条件は `docs/design-system.md` の 4 条件)。
 
 ## Typography
 
@@ -159,7 +166,13 @@ ## Shapes
 ## Components
 
 > component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
-> 使い分けルールのみを定義する。各 component を追加したら本節に追記すること。
+> 使い分けルールのみを定義する。
+> **本節が対象にするのは DS の再利用部品(`atoms` / `molecules` / `organisms`)である。**
+> `features/` のドメイン部品と `templates/` のレイアウト骨格は本節の対象外
+> (前者は各 feature の設計が使い分けを決め、後者は §Layout と
+> `tests/js/architecture/page-shell-structure.test.ts` が担当する)。
+> **対象の component を追加したら本節に追記すること**
+> (`tests/js/styles/component-doc-parity.test.ts` が双方向の集合一致で強制する)。
 
 ### Button
 
@@ -291,6 +304,16 @@ ### Toggle
 トラックは On=`bg-primary` / Off=`bg-border-strong`、つまみは `bg-surface`(影なし、
 明度差で表現)。`rounded-full` は真に円形な UI の例外として file-scoped allowlist で管理する。
 
+### DragHandle
+
+実装: `components/atoms/DragHandle.svelte`(仕様の真実は `DragHandle.types.ts`)。
+並べ替えのつかみ手。アイコンは `GripVertical` 固定で差し替えない(つかめる場所の合図を
+画面間で揃える)。`touch-none` でタッチをスクロールに奪わせず、小コントロールなので
+`rounded-sm`。キーボード操作は上下キーで、つかみ手自身がフォーカスを受ける。
+**並べ替えができない状態の表現は本 atom では決めない**(禁止事項 8 は「必須条件未充足を
+理由に disabled にする」ことの禁止であって、あらゆる disabled の禁止ではない。
+並べ替え自体が成立しない場面では呼び出し側がつかみ手を出さない)。
+
 ### Modal
 
 実装: `components/organisms/Modal.svelte`(仕様の真実は `Modal.types.ts`)。bits-ui Dialog のラップ。
@@ -510,6 +533,25 @@ ### RecentAuthRecoveryNotice
 - molecule 配置は構造的制約: 呼び出し元の RecentAuthModal は organism であり、
   atomic-import-graph 上 organism は features 層を import できない
 
+### OrganizationChoiceCard
+
+実装: `components/molecules/OrganizationChoiceCard.svelte`。組織を 1 件選んで移動する遷移カード。
+**遷移先 URL は親が渡す**(組織文脈の解決は molecule の責務ではない)。カード全体が 1 つの
+リンクで、名前と補足の 2 段構成。選択済みの状態は持たない(選ぶ操作そのものが遷移である)。
+
+### PendingInvitationsNotice
+
+実装: `components/molecules/PendingInvitationsNotice.svelte`。自分宛の**保留中招待の件数だけ**を
+出す誘導専用 notice。**受諾 UI は持たない**(受諾は通知一覧で行う)。
+背景は `bg-primary-soft/40`、hover で `bg-primary-soft`。件数 0 のときは呼び出し側が出さない。
+
+### SubtitleOverlay
+
+実装: `components/molecules/SubtitleOverlay.svelte`。映像へ重畳する字幕 overlay。
+**焼き込みではなく DOM overlay** である(MediaRecorder の stream には含まれない)。
+`primary` は上部帯、`secondary` は下部のメイン字幕で、位置は合成側の `AssSubtitleWriter`
+(ASS)と一致させる。長文は line-clamp で省略する。帯は `bg-text/70` + `text-surface`。
+
 ## Do's and Don'ts
 
 **Do**
diff --git a/docs/design-system.md b/docs/design-system.md
index e1686890..2b5b9790 100644
--- a/docs/design-system.md
+++ b/docs/design-system.md
@@ -15,8 +15,10 @@ ## Canonical source の宣言
 
 ## 検査の責務境界
 
-本節で責務境界を管理するデザイントークン検査は 4 本ある
+本節で責務境界を管理するデザイントークン検査は**下表に挙げたものがすべてである**
 (DS purity 系など、トークンの値以外を見る検査は本節の管理対象ではない)。
+数字は書かない — 表そのものを機械で実体と突き合わせているので、本数の記述は
+「表と実体が一致していること」に何も足さないまま必ず陳腐化する。
 **どれが何を見ているか**を混同しないこと — 見ている写像の段が違うので、
 片方を消すと別の壊れ方が見えなくなる。
 
@@ -25,25 +27,39 @@ ## 検査の責務境界
 | `tests/js/styles/canonical-source-parity.test.ts` | DESIGN.md (正本) ⇔ tokens.css (宣言) のテキスト | 片方だけ更新した PR / トークンの増減 / 検査の母集団の取りこぼし |
 | `tests/js/styles/tokens.test.ts` | tokens.css (宣言) ⇒ Tailwind 生成 CSS | `@theme` が解釈されない / utility 名が解決しない / app.css が tokens.css を取り込んでいない |
 | `tests/js/styles/design-system-docs.test.ts` | 本書の構造 ⇔ 検査ファイルの実体 | 運用契約の節の消失 / 表と実体の食い違い |
-| `tests/js/architecture/contrast-invariant.test.ts` | DESIGN.md の色値 ⇒ コントラスト比 | 読めない色の組合せ |
+| `tests/js/architecture/contrast-invariant.test.ts` | DESIGN.md の色値 ⇒ コントラスト比 (不透明ペアと半透明ペアの合成、実装からの逆向き被覆) | 読めない色の組合せ / 役割宣言を書かずに新しい前景 × 背景の組を足す |
+| `tests/js/styles/theme-map.test.ts` | 写像パーサそのもの (固定検体) | `@theme` の検出・宣言の抽出・色表現の解析の退行 |
+| `tests/js/styles/class-usage.test.ts` | 走査器そのもの (固定検体) と `resources/js` の解析診断 | 状態単位の分解の退行 / 未対応入口の deny の空振り |
+| `tests/js/styles/token-reference-closure.test.ts` | 参照側 (resources/js / resources/css) ⇒ tokens.css の宣言集合 | token 名の綴り誤りが無スタイルとして静かに消える / 写像の外の色語 (Tailwind 既定の white 等) の混入 |
+| `tests/js/styles/component-doc-parity.test.ts` | DESIGN.md §Components ⇔ resources/js/components の部品ファイル | 文書に載らない部品が増える / 節だけ残って実装が消える |
 
 **この表は機械で実体と突き合わせている**。`tests/js/styles/` に検査を足したら本表にも行を足す
 (足さないと `design-system-docs.test.ts` が落ちる)。逆に検査を消したら行も消す。
 別の場所へ足す検査は `design-system-docs.test.ts` の `EXTERNAL_GATE_FILES` へ明示登録する。
 
-本書の検査は、読者に描画されない領域 (HTML コメント / fenced code) を落としてから節と表を見る。
+本書の検査は、**規範判定対象外領域**を落としてから節と表を見る。
+落とすのは HTML コメントと囲みコードの 2 つ(前者は読者に描画されず、後者は描画されるが
+規範の本文として数えない。まとめてこう呼ぶ)。
 落とす判定は Markdown の fence 規則に寄せてあり (字下げした偽の終端や、
 情報文字列にバッククォートを含む無効な開始行では区間が閉じない・開かない)、
 コメントを取り除いた跡には**規範の最小断片には使わない制御文字**を目印として残すので、
 コメントを挟んだ 2 つの断片が検査の上でだけ繋がることはない。
-ただし**完全な Markdown 解析ではない** — 4 空白字下げのコードブロックと
-HTML 要素による非表示は見ていない。
+**行頭から 3 空白までで始まる正規の囲みコード記法以外の位置に、
+記号を 3 個以上連続させて書くことは禁じる**(引用やリストの中の囲みコード記法、
+行の途中の連続記号、記号 3 個以上の行内コードを含む)。書かれていたら検査自体を失敗させる。
+加えて**タブと 4 個以上連続した半角空白も検査自体を失敗させる**
+(字下げによるコードは書かず、囲みコード記法を使うこと)。
+字下げコードの位置を近似で判定すると見出し直後や引用の中の形を取りこぼし、
+そこへ規範の断片を退避させられる。タブを禁じたうえで 4 連続空白を拒否すれば、
+引用やリストの記号が何段入れ子になっていても字下げコードは書けないので、
+**字下げについては引用やリストの文法を一切扱わずに見逃しを 0 にできる**。
+ただし**完全な Markdown 解析ではない** — HTML 要素による非表示は見ていない。
 そのうえで節ごとに**規範の最小断片** (`design-system-docs.test.ts` の
 `SECTION_CONTRACT_PHRASES`) が本文に在ることを求めるので、契約の一文を消したり
 描画されない領域へ移したりすると赤になる。**文言を直すときは同じ PR で最小断片も直す**
 (それが「契約を変えた」ことの可視化になる)。
 
-保証しないもの: Vite のビルド・アセット配信・ブラウザでの適用は 4 本のどれも見ていない。
+保証しないもの: Vite のビルド・アセット配信・ブラウザでの適用は**下表のどれも**見ていない。
 文書側で見ているのは節の構造・表の実体・最小断片までで、**周りの説明が骨抜きになったことは
 検出できない**。
 DESIGN.md frontmatter の `spacing:` は**値も tokens.css への実装写像の有無も検査していない**
@@ -58,6 +74,7 @@ ## トークン変更時の運用契約
 - [ ] `/resources/css/tokens.css` の `@theme` / `@utility` 該当ブロック
 - [ ] `/tests/js/styles/inventory.ts`(トークンの追加・削除時。parity と生成 CSS 検査の母集団を兼ねる)
 - [ ] テーマ由来の制約を変える場合は `/tests/js/support/ds-purity.ts` の THEME_PATTERNS
+- [ ] トークンの**値**を変える場合は `contrast-invariant.test.ts` の不透明ペアと**半透明ペア(合成)**の両方が緑であること(ソフト背景の色は面の上での合成後の値で判定される)
 
 片方だけ更新する PR は merge しない(parity テストが落ちる)。
 
@@ -67,7 +84,8 @@ ## テーマの差し替え方(テンプレート派生アプリ向け)
 
 1. `DESIGN.md` frontmatter の colors と本文の色記述を更新
 2. `tokens.css` の `--color-*` を同じ値に更新
-3. parity テスト green を確認
+3. parity テスト green を確認(**contrast-invariant の合成検査も含む**。
+   状態色を明るい段に戻すとソフト背景側で落ちる)
 
 制約体系(影なし / rounded 3 段 / weight 400-500 / ramp 必須)を変えるテーマにする場合は、
 `ds-purity.ts` の **THEME_PATTERNS** を DESIGN.md と同期して書き換える。
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 773f1b07..8a453329 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 51 件
+登録エントリ: 53 件
 
 ## 記録の原則
 
@@ -1688,13 +1688,18 @@ ### 揃えている不変条件 (これは保証し続ける)
 
 ### 保証しないもの
 
-- 派生トークン `--color-primary-soft` の値 (生成 CSS への出現までしか見ていない)
+- 派生トークン `--color-primary-soft` の**正本 (DESIGN.md) 側の期待値** (正本に無いため)。
+  ただし「正本の primary の RGB を alpha 0.12 にしたもの」であることは
+  `tokens.test.ts` の不透明度修飾の生成形の検査が固定する (D55 と対)
 - font-family の先頭以外のフォールバック列
 - 生成 CSS より先 (Vite のビルド・アセット配信・ブラウザでの適用)
 - 文書側は構造と節ごとの規範の最小断片までを見る。最小断片が在っても
   周りの説明が骨抜きになっていることは検出できない。
-  描画されない領域として除くのは HTML コメントと fenced code の 2 つだけで、
-  4 空白字下げのコードブロックと HTML 要素による非表示は見ていない
+  **規範判定対象外領域**として除くのは HTML コメントと囲みコードの 2 つだけで、
+  HTML 要素による非表示は見ていない。字下げによるコード・タブ・
+  正規の top-level 以外の位置に書いた 3 個以上の連続記号は**除かずに検査を失敗させる**
+  (D56 で足した契約。近似で位置を判定すると規範の断片を退避させられるため、
+  書き方の側を禁じている)
 
 ### 関連
 
@@ -3157,3 +3162,126 @@ ### 関連
 - 実装: `tests/js/architecture/enum-ts-sync.test.ts` / `tests/js/support/enum-ts-sync/`
 - 設計: `devnotes/20260824-1633-enum-ts-sync-gate-v3/`
 - 関連する登録: D34 (採用時債務の凍結層。本登録で 1 行解消した)
+
+## D55 デザイントークンのコントラスト検査を、半透明の合成と実装からの逆向き被覆まで広げる
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/js/architecture/contrast-invariant.test.ts` |
+| 業務要件起因の説明 | 撮影 PWA の状態表示 (撮影中 / 完了 / 警告) はソフト背景のバッジで出しており、作業者はその 1 個の色で工程の状態を読む。テンプレートの検査は不透明な組だけを見るため、実際に画面へ出ているソフト背景の可読性が 1 件も検査されていなかった (実測で 5 組が AA 未達だった) |
+| 揃え続ける不変条件と保証機構 | 半透明の背景 × 不透明な文字の組が、面として分類した token のすべての上で 4.5:1 を満たすこと。走査で見つかった半透明の組が (ファイル, 組, 修飾率, 件数) で全件台帳に載り、静的に決められない形は理由と件数つきで別台帳に載ること。台帳が持つのは class 修飾の百分率だけで、token 固有 alpha との合成は 1 か所 (`resolveAlphaBackground()`) に集約されること。実装の class から導出した前景 × 背景の組が役割の母集団 (役割の全数分類の直積 + 個別宣言ペア) の内側にあること。線形化しきい値が errata 後の 0.04045 であること。`contrast-invariant.test.ts` と `tests/js/styles/class-usage.ts` が保証する |
+| 再判定の条件 | 家系の機能台帳 `design-token-system` が半透明の合成を不変条件から外したとき / Tailwind の不透明度修飾の展開形が変わって合成モデルの前提が崩れたとき (`tokens.test.ts` の不透明度修飾の生成形の検査が赤くなる) / 広色域の実描画との差を実測して系統的なずれが出たとき (家系の未決論点 q3) |
+| 決めた日 | 2026-08-24 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260824-1019-design-token-system-v1/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 検査する組 | 不透明な前景 × 不透明な背景だけ | 不透明の組に加えて**半透明の背景 × 不透明な文字**の合成 |
+| 組の出どころ | 役割宣言の直積だけ (宣言 ⇒ 検査の一方向) | 直積 + 個別宣言ペア + **実装の class から導出した逆向きの被覆** |
+| 役割の持ち方 | token ごとに排他な分類 (免除は token 単位) | token ごとに**複数役割**。非テキスト境界の免除は役割の 1 つで、同じ token の別用途は検査される |
+| 静的に決められない形 | 検査対象外 (宣言だけ) | 理由別に**全数台帳**へ載せ、(ファイル, 理由, 件数) で完全一致させる |
+| 線形化しきい値 | WCAG 2.x 本文の 0.03928 | errata 後の 0.04045 (8bit では判定不変) |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **工程の状態はソフト背景のバッジでしか出ていない**。「思考ゼロ」の前提は
+   「見れば分かる」ことなので、その 1 個の色が最低基準を割るのは業務要件の破綻である。
+   テンプレートの不透明ペアだけの検査は、実際に画面へ出ている組を 1 件も見ていなかった。
+2. **宣言 ⇒ 検査の一方向では、宣言を書かずに組を足せる**。役割を書かないまま新しい
+   前景 × 背景を実装へ足すと、母集団に入らないので永久に検査されない。
+   逆向きの被覆はこの経路を塞ぐ。
+3. **役割の排他分類は実装と食い違っていた**。`border` は 1px 枠であると同時に
+   Button の neutral variant の hover 塗りとしてテキストを載せている。
+   排他分類のままだと「免除された token の上に文字が載る」形が見えない。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「画面に実在する前景 × 背景の組は、不透明でも半透明でも**必ずどれかの母集団に入り**、
+> 静的に決められない形は**理由と件数つきで台帳に見える**」
+
+- 母集団は 3 つ (役割の直積 / 個別宣言ペア / 半透明の意味ペア) で、どれにも入らない組が
+  実装に現れたら逆向きの被覆が落とす
+- 個別宣言ペアには 5 条 (背景側の役割 / 前景側の役割 / 直積で受けられる背景を個別宣言にしない /
+  実装に実在すること / 重複禁止) を課しており、1 組登録して既定拒否を通す経路は無い
+- 合成モデル (透明との混色 = 同じ色の alpha / チャンネルごとの線形合成 / 8bit 丸め) は
+  gate 本体に前提として書いてあり、生成形そのものは `tokens.test.ts` が固定する
+
+### 保証しないもの
+
+- **走査単位をまたいで成立する組**・**親から渡る class**・**親要素から継承する背景**・
+  **実行時に組み立てられる class**。正本は `tests/js/styles/class-usage.ts` の docblock
+- **WCAG 1.4.11 (非テキスト 3:1)**。`border` / `border-strong` の 1px 枠としての用途は
+  役割 `non-text-boundary` として理由つきで対象外にしてある
+- **広色域 (Display P3 等) の実描画との厳密一致**。合成は近似であり、近似が判定を
+  変えていないことだけを「丸めない合成との比が境界を跨がない」検査が固定する
+- **ブラウザが必ずこう描くこと**。本 gate が判定に使う近似モデルの宣言である
+
+### 関連
+
+- 実装: `tests/js/architecture/contrast-invariant.test.ts` / `tests/js/styles/class-usage.ts` /
+  `tests/js/styles/theme-map.ts` / `tests/js/styles/inventory.ts`
+- 設計: `devnotes/20260824-1019-design-token-system-v1/`
+- 関連する登録: D28 (デザイントークン検査の独自系統) / D34 (採用時債務の凍結層。本登録で 1 行解消した)
+
+## D56 デザインシステム運用ガイドを検査目録の正本にし、部品カタログの被覆と字下げの禁止まで機械で固定する
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `docs/design-system.md` |
+| 業務要件起因の説明 | 本アプリはデザイントークン検査を独自系統で持つ (D28) ため、検査の本数も置き場もテンプレートと一致せず、責務境界の表を機械照合の入力にしている以上テンプレートの散文をそのまま維持できない。加えて (1) 撮影 PWA のテーマ値を動かす運用契約 (半透明の合成検査を通すこと) を書き足す必要があり、(2) DS の再利用部品が文書に載らないまま増える事故を機械で止めるため部品カタログの被覆を契約として書く必要があり、(3) 契約の本文を読者に見えない場所へ退避させる経路を塞ぐため字下げコードの扱いを書き換える必要がある |
+| 揃え続ける不変条件と保証機構 | 責務境界表と `tests/js/styles/*.test.ts` の実体が双方向に集合一致すること (本数は書かない)。DESIGN.md §Components の節と対象サブディレクトリの部品ファイルが双方向に集合一致すること。節ごとの規範の最小断片が読者に描画される本文に在ること。文書の走査については保証が 2 つに分かれる — (a) 規範判定対象外領域の除去: HTML コメント (読者に描画されない) と囲みコード (描画されるが規範の本文として数えない) を落とし、囲みコードの外の行に記号が 3 個以上連続して現れ、その行が字下げ 3 空白までの正規の top-level 囲みコード開始行でなければ診断にする。未終端のコメント・未終端の囲みコード・受理範囲外の記法も同じ診断へ落とし、診断が 1 件でもあれば検査を失敗させる。(b) 字下げコードの拒否: タブと 4 個以上連続した半角空白を含む行が現れたら検査自体を失敗させる。行の分類は 1 実装 (`scanMarkdownLines()`) に集約されること。`tests/js/styles/design-system-docs.test.ts` と `tests/js/styles/component-doc-parity.test.ts` が保証する |
+| 再判定の条件 | 検査目録を文書ではなく機械可読な台帳へ移したとき / 部品カタログの正本を DESIGN.md 以外へ移したとき / CommonMark パーサを導入して字下げコードを解析できるようにしたとき / 家系の正典が運用ガイドの節構成そのものを不変条件として明文化したとき |
+| 決めた日 | 2026-08-24 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260824-1019-design-token-system-v1/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 検査目録の書き方 | 本数を散文に書く | 本数を書かず、表そのものを機械で実体と双方向照合する |
+| 部品カタログ | 文書の指示だけ (機械検査なし) | DESIGN.md §Components ⇔ 部品ファイルの双方向集合一致を機械で強制する |
+| 規範判定対象外領域 | 呼称は「描画されない領域」。HTML コメントと囲みコードを落とす | 呼称を訂正 (囲みコードは描画される)。落とす 2 つに加え、正規でない位置の連続記号を診断にする |
+| 字下げコード | 見ていない (近似もしない) | **書き方そのものを禁じ**、タブと 4 連続空白で検査を失敗させる |
+| テーマ差し替えの手順 | parity テストのみ | 合成検査 (半透明ペア) を通すことを明記する |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **本数の記述は必ず陳腐化する**。表は機械で実体と突き合わせているので、
+   本数は「表と実体が一致していること」に何も足さないまま、検査を足すたびにずれる。
+   数字を持たない形にするのが唯一の解である。
+2. **文書に載らない部品が増える事故は家系で実在した**。本アプリでも実測で 4 部品が
+   節を持たないまま増えていた。文書の指示だけでは止まらない。
+3. **字下げコードは近似で判定できない**。CommonMark で字下げコードが中断できるのは段落だけで、
+   見出しや区切り線の直後の 4 空白行は字下げコードになりうる。近似で落とす実装のままだと、
+   規範の最小断片をそこへ退避させて緑にできる。書き方の側を禁じれば、
+   引用やリストの文法を一切扱わずに見逃しを 0 にできる。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「運用ガイドと DESIGN.md の**規範は読者に見える本文にしか書けない**。
+> 検査目録と部品カタログは**文書と実体が双方向に一致する**」
+
+- 規範判定対象外領域の除去と字下げの禁止は 2 つの独立した契約で、
+  行の分類は `tests/js/styles/markdown-lines.ts` の 1 実装に集約してある
+- 責務境界表の双方向一致は `design-system-docs.test.ts`、
+  部品カタログの双方向一致は `component-doc-parity.test.ts` が担当する
+  (同じ主張を 2 つの gate へ書かない)
+
+### 保証しないもの
+
+- **完全な CommonMark 解析ではない**。保証するのは上の 2 命題の範囲だけで、
+  HTML 要素による非表示 (`<details>` / `hidden` 属性等) は見ていない
+- **節の中身が実装と合っていること**。部品の意味論の一致は人のレビューの担当である
+- **周りの説明が骨抜きになっていないこと**。見るのは節ごとの規範の最小断片までである
+
+### 関連
+
+- 実装: `docs/design-system.md` / `tests/js/styles/design-system-docs.test.ts` /
+  `tests/js/styles/component-doc-parity.test.ts` / `tests/js/styles/markdown-lines.ts` /
+  `tests/js/styles/design-md.ts`
+- 設計: `devnotes/20260824-1019-design-token-system-v1/`
+- 関連する登録: D28 (デザイントークン検査の独自系統) / D34 (採用時債務の凍結層。本登録で 1 行解消した)
diff --git a/resources/css/tokens.css b/resources/css/tokens.css
index 77eb5ca5..ea67b1f5 100644
--- a/resources/css/tokens.css
+++ b/resources/css/tokens.css
@@ -11,11 +11,11 @@
 
 @theme {
     /* ===== Brand colors (DESIGN.md Slate × Blue) ===== */
-    --color-primary:         #2563eb;
-    --color-primary-hover:   #1d4ed8;
-    --color-primary-soft:    rgba(37, 99, 235, 0.12);  /* primary 12% — badge / focus ring 用 */
-    --color-tertiary:        #0f766e;
-    --color-tertiary-hover:  #115e59;
+    --color-primary:         #1d4ed8;
+    --color-primary-hover:   #1e40af;
+    --color-primary-soft:    rgba(29, 78, 216, 0.12);  /* primary 12% — badge / focus ring 用 */
+    --color-tertiary:        #115e59;
+    --color-tertiary-hover:  #134e4a;
 
     /* ===== Neutrals & surface ===== */
     --color-neutral:         #f4f4f5;  /* page background */
@@ -26,8 +26,8 @@ @theme {
     --color-text-secondary:  #52525b;
 
     /* ===== Status colors ===== */
-    --color-success:         #15803d;
-    --color-warning:         #b45309;
+    --color-success:         #166534;
+    --color-warning:         #92400e;
     --color-danger:          #b91c1c;
 
     /* ===== Fonts ===== */
diff --git a/resources/js/components/templates/AppLayout.svelte b/resources/js/components/templates/AppLayout.svelte
index 580a2768..e383a5a5 100644
--- a/resources/js/components/templates/AppLayout.svelte
+++ b/resources/js/components/templates/AppLayout.svelte
@@ -296,7 +296,7 @@
                     title={`${orgName} / ${userName}`}
                 >
                     <div
-                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-white"
+                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-surface"
                     >
                         <Building2 class="size-5" aria-hidden="true" />
                     </div>
@@ -424,7 +424,7 @@
             <div class="min-h-0 overflow-y-auto border-t border-border px-2 py-3">
                 <div class="mb-2 flex items-center gap-2 px-2">
                     <div
-                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-white"
+                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-surface"
                     >
                         <Building2 class="size-5" aria-hidden="true" />
                     </div>
diff --git a/resources/js/components/templates/_helpers/SidebarNavItems.svelte b/resources/js/components/templates/_helpers/SidebarNavItems.svelte
index fa6ef056..24028969 100644
--- a/resources/js/components/templates/_helpers/SidebarNavItems.svelte
+++ b/resources/js/components/templates/_helpers/SidebarNavItems.svelte
@@ -35,7 +35,7 @@
             href={item.href}
             onclick={() => onNavigate?.()}
             class="flex items-center gap-3 rounded-lg px-3 py-3 transition-colors {isActive(item.href)
-                ? 'bg-primary text-white'
+                ? 'bg-primary text-surface'
                 : 'text-text hover:bg-neutral'}"
             title={item.label}
             data-testid="nav-item-{item.href}"
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index c7f73601..bf07d694 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -19,7 +19,7 @@ final class LedgerPins
     private function __construct() {}
 
     /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
-    public const int DIVERGENCE_ENTRY_COUNT = 51;
+    public const int DIVERGENCE_ENTRY_COUNT = 53;
 
     /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
     public const int FINGERPRINT_POPULATION_COUNT = 281;
@@ -31,7 +31,7 @@ private function __construct() {}
      *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
      *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
      */
-    public const int ADOPTION_DEBT_COUNT = 145;
+    public const int ADOPTION_DEBT_COUNT = 143;
 
     /**
      * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
diff --git a/tests/Support/TemplateDivergence/adoption-debt.tsv b/tests/Support/TemplateDivergence/adoption-debt.tsv
index 6717668b..b6a053eb 100644
--- a/tests/Support/TemplateDivergence/adoption-debt.tsv
+++ b/tests/Support/TemplateDivergence/adoption-debt.tsv
@@ -42,7 +42,6 @@ docker/Dockerfile	81c72a1ac1f564f410ab68e7cee499d9c897ca8b37a4d6bbb6ac5e87d3414f
 docs/account-deletion-runbook.md	e3d587325203170182d7615424823c7c32780b887e075b8e3cfe1f738556393c
 docs/api-idempotency.md	9ee2c2fb2292aa76315c846288b60beb690c461367e0cc9b3175084ca4b78883
 docs/auth-security-mechanisms.md	1384827d1b91386047a2362bfe5e0267b3df8507eca5c6d08f92b88d0f122b2c
-docs/design-system.md	eaf73277cc0867b4c9af240007013f11c82ae3fb35a64854a790c0366200d015
 docs/mcp-oauth.md	1544286bb0e8ced6b171cc687d9e2bbcfa7c1e2873c59d9dae9e03625210102d
 docs/ses-mail-runbook.md	41c49a99368f74dae6ccaf7dbae18afed1ae7e2ed8d3c2808ded15e66f94329c
 docs/supply-chain/review-checklist.md	bf81ee897ee42ccf528430019ad978aa62a68eb8108ff580bc9ea7ac067ba784
@@ -134,7 +133,6 @@ tests/Support/Security/PrimaryKeyPredicateKind.php	bb252647b61b3e38eed9c43293baf
 tests/Support/SnsTestData.php	6b1fda460e451eb452409d4548de5ed822f2bd8844369dc90c1070ca3d62b3d6
 tests/Support/StrayHttpRequestGuard.php	cea69f5a162395495844c026b3e2537248ea65fc594ce35642c7b0ae19d0e8e9
 tests/TestCase.php	332af0ee95f4edc5bb960bd805057c40a4182ad4226a5fd08bb24c706d06ba59
-tests/js/architecture/contrast-invariant.test.ts	ee111fc338e62e936f85ffcc165dffe7d570c7c81d44a27baffe06f3eeaf96a8
 tests/js/architecture/ds-purity.test.ts	c383d0e28f12193c1408ba6c3079dceddf9efcba4d37c04d4bf7b1c3b9531f01
 tests/js/architecture/flash-keys-sync.test.ts	b3e5e4ac23edd1818739623e233e33b42370db817adfdc3a66a03a5fc9ed3b9d
 tests/js/architecture/pages-path-case-invariant.test.ts	037938ed0a56b30fb67a043694eb114ad70f784ab7beb486cede0b72df661220
diff --git a/tests/js/architecture/contrast-invariant.test.ts b/tests/js/architecture/contrast-invariant.test.ts
index 72e733ef..2f447036 100644
--- a/tests/js/architecture/contrast-invariant.test.ts
+++ b/tests/js/architecture/contrast-invariant.test.ts
@@ -1,14 +1,29 @@
 import { describe, it, expect } from "vitest";
 import { designColors } from "../styles/design-md";
 import {
+    ALPHA_CONTRAST_PAIRS,
+    ALPHA_PAIR_USAGE_LEDGER,
     COLOR_TOKEN_MAP,
-    CONTRAST_EXEMPT_TOKENS,
+    COLOR_TOKEN_ROLES,
+    DECLARED_CONTRAST_PAIRS,
     FILL_LABEL_TOKENS,
     FILL_TOKENS,
     PENDING_CONTRAST_PAIRS,
     SURFACE_ROLE_TOKENS,
     TEXT_ON_SURFACE_TOKENS,
+    UNDECIDABLE_PAIR_LEDGER,
+    JS_SCAN_CHILD_CLASSIFICATION,
+    NON_TEXT_BOUNDARY_REASONS,
+    UNDECIDABLE_REASONS,
+    distinctPairs,
+    rolesOf,
+    tokensWithRole,
+    type AlphaPair,
+    type CssColorSuffix,
+    type UndecidableReason,
 } from "../styles/inventory";
+import { cssColorTokens, parseCssColor, requiredMapValue, type Rgb } from "../styles/theme-map";
+import { scanClassUsage, unsupportedEntryPoints } from "../styles/class-usage";
 
 /*
  * contrast-invariant — DESIGN.md のテーマ色が読める組合せであることを機械検証する。
@@ -42,24 +57,51 @@ import {
 
 const AA_NORMAL_TEXT = 4.5;
 
-/** sRGB チャンネルの線形化 (WCAG 2.x 相対輝度の定義) */
+/**
+ * sRGB チャンネルの線形化 (WCAG 2.x 相対輝度の定義)。**正規化済み (0..1) の値を受ける**。
+ *
+ * しきい値は **0.04045** を使う。WCAG 2.0 / 2.1 本文の 0.03928 は
+ * **2022-02-22 の errata で訂正済み**で、IEC 61966-2-1 (sRGB) の正しい値が 0.04045 である。
+ * **8bit の色値では判定結果は変わらない** (境界は 0.03928*255 = 10.02 と
+ * 0.04045*255 = 10.31 の間にあり、整数のチャンネル値 10 と 11 のどちらも
+ * 両しきい値の同じ側に落ちる)。正しい方へ揃えるだけの変更である。
+ *
+ * 純粋関数として切り出してあるのは、負のコントロールが**実装本体を呼ぶ**ためである
+ * (8bit の全値で「両しきい値の判定が一致する」ことを確かめるだけの検査は実装を 1 度も
+ * 呼ばないので、実装が 0.03928 のままでも緑になり正典 i13 を固定できない)。
+ */
+export function linearizeChannel(c: number): number {
+    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
+}
+
 function linearize(channel: number): number {
-    const c = channel / 255;
-    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
+    return linearizeChannel(channel / 255);
+}
+
+/** RGB (0..255。丸めていない実数も受ける) → 相対輝度 (WCAG 2.x) */
+function luminanceOfRgb(rgb: Rgb): number {
+    return (
+        0.2126 * linearize(rgb.r) + 0.7152 * linearize(rgb.g) + 0.0722 * linearize(rgb.b)
+    );
+}
+
+/** 相対輝度 2 つ → コントラスト比。1.0〜21.0 */
+function ratioOfLuminance(a: number, b: number): number {
+    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
 }
 
 /** #rrggbb → 相対輝度 (WCAG 2.x) */
 function relativeLuminance(hex: string): number {
-    const r = linearize(parseInt(hex.slice(1, 3), 16));
-    const g = linearize(parseInt(hex.slice(3, 5), 16));
-    const b = linearize(parseInt(hex.slice(5, 7), 16));
-    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
+    return luminanceOfRgb({
+        r: parseInt(hex.slice(1, 3), 16),
+        g: parseInt(hex.slice(3, 5), 16),
+        b: parseInt(hex.slice(5, 7), 16),
+    });
 }
 
 /** コントラスト比 (WCAG 2.x)。1.0〜21.0 */
 export function contrastRatio(a: string, b: string): number {
-    const [l1, l2] = [relativeLuminance(a), relativeLuminance(b)];
-    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
+    return ratioOfLuminance(relativeLuminance(a), relativeLuminance(b));
 }
 
 const colors = designColors();
@@ -82,28 +124,35 @@ const PAIRS: readonly (readonly [string, string, string])[] = [
     ...FILL_LABEL_TOKENS.flatMap((fg) =>
         FILL_TOKENS.map((bg) => [fg, bg, "塗り面のラベル"] as const),
     ),
+    // 個別宣言ペアも直積と**同じ閾値**を課す (正典 i14)。
+    ...DECLARED_CONTRAST_PAIRS.map((p) => [p.fg, p.bg, "個別宣言ペア"] as const),
 ];
 
 describe("architecture/contrast-invariant: 不透明ペアのテキストコントラスト (一律 4.5:1)", () => {
     it("役割宣言が DESIGN.md の全色トークンを覆う (deny-by-default)", () => {
-        const classified = new Set<string>([
-            ...SURFACE_ROLE_TOKENS,
-            ...TEXT_ON_SURFACE_TOKENS,
-            ...FILL_TOKENS,
-            ...FILL_LABEL_TOKENS,
-            ...Object.keys(CONTRAST_EXEMPT_TOKENS),
-        ]);
-        const unclassified = Object.keys(COLOR_TOKEN_MAP).filter((t) => !classified.has(t));
+        // 分類の全数性は COLOR_TOKEN_ROLES **だけ**で見る。
+        // 個別宣言ペアに現れることを「分類済み」と数えると、任意の新 token を
+        // 1 組登録するだけで既定拒否を通せてしまう。
         expect(
-            unclassified.sort(),
-            `未分類の色トークンがある。tests/js/styles/inventory.ts で ` +
-                `SURFACE_ROLE / TEXT_ON_SURFACE / FILL / FILL_LABEL / CONTRAST_EXEMPT の ` +
-                `いずれかに分類すること (免除するなら理由を書くこと): ${unclassified.join(", ")}`,
-        ).toEqual([]);
+            Object.keys(COLOR_TOKEN_ROLES).sort(),
+            "未分類の色トークン、または DESIGN.md に存在しないトークンの宣言がある。" +
+                "tests/js/styles/inventory.ts の COLOR_TOKEN_ROLES で分類すること",
+        ).toEqual(Object.keys(COLOR_TOKEN_MAP).sort());
 
-        // 逆向き: 宣言に DESIGN.md に無いトークンが紛れていないか
-        const unknown = [...classified].filter((t) => !(t in COLOR_TOKEN_MAP));
-        expect(unknown.sort(), `DESIGN.md に存在しないトークンが宣言されている`).toEqual([]);
+        for (const [token, roles] of Object.entries(COLOR_TOKEN_ROLES)) {
+            expect(roles.length, `${token}: 役割が 0 件`).toBeGreaterThan(0);
+            // 同じ役割の重複登録を拒否する (導出した直積に重複ペアが生じるのを防ぐ)
+            expect(new Set(roles).size, `${token}: 役割が重複している`).toBe(roles.length);
+        }
+    });
+
+    it("non-text-boundary の役割と理由の集合が一致する (理由だけ残る / 役割だけ足す を落とす)", () => {
+        expect(Object.keys(NON_TEXT_BOUNDARY_REASONS).sort()).toEqual(
+            [...tokensWithRole("non-text-boundary")].sort(),
+        );
+        for (const [token, reason] of Object.entries(NON_TEXT_BOUNDARY_REASONS)) {
+            expect(reason.length, `${token}: 理由`).toBeGreaterThan(30);
+        }
     });
 
     it("検査対象ペアが 0 件でない (空振り防止)", () => {
@@ -142,6 +191,27 @@ describe("architecture/contrast-invariant: 不透明ペアのテキストコン
         ).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
     });
 
+    it("負のコントロール: 線形化のしきい値が errata 後の 0.04045 である", () => {
+        // 2 つのしきい値の**間**の値でだけ実装の差が出る。
+        //   c = 0.04 → 0.04045 実装は線形枝 = 0.04 / 12.92
+        //              0.03928 実装は pow 枝  = ((0.04 + 0.055) / 1.055) ** 2.4
+        // 実装本体 (linearizeChannel) を呼ぶので、0.03928 のままならこの toBe が落ちる。
+        expect(linearizeChannel(0.04)).toBe(0.04 / 12.92);
+        // 両しきい値の外側では当然一致する (この it が「何でも通る」形でないことの裏取り)。
+        expect(linearizeChannel(0.03)).toBe(0.03 / 12.92);
+        expect(linearizeChannel(0.5)).toBeCloseTo(Math.pow((0.5 + 0.055) / 1.055, 2.4), 12);
+    });
+
+    it("補助: errata のしきい値の差が 8bit では判定を変えない", () => {
+        // 「揃えたら結果が変わった」= どちらかの実装が間違っていたことになるので、
+        // 変わらないことを 8bit の全チャンネル値で固定する。
+        // これは**性質の検査**であって実装のしきい値は固定しない (上の it が固定する)。
+        for (let channel = 0; channel <= 255; channel += 1) {
+            const c = channel / 255;
+            expect(c <= 0.03928, `channel=${channel}`).toBe(c <= 0.04045);
+        }
+    });
+
     /* 負のコントロール: 計算器が実際に点灯することを既知値で確認する */
     it("負のコントロール: 既知の低コントラスト対を検出し、既知の高コントラスト対は通す", () => {
         expect(contrastRatio("#ffffff", "#ffffff")).toBeCloseTo(1, 5);
@@ -152,3 +222,410 @@ describe("architecture/contrast-invariant: 不透明ペアのテキストコン
         expect(contrastRatio("#b91c1c", "#f4f4f5")).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
     });
 });
+
+/*
+ * ===== 半透明背景 × 不透明文字の合成 (正典 i16) =====
+ *
+ * 【本 gate が採用する**近似モデル** (版や環境で変わりうるので gate 本体に書く)】
+ *   1. 不透明度修飾は `color-mix(…, transparent)` へ展開され、**透明との混色は
+ *      同じ色の alpha になる** (透明側の乗算済み色が寄与しないため色相・明度は変わらない)。
+ *      alpha を値に持つ token にさらに修飾が付く形は**実効 alpha が積**になる。
+ *      生成形そのものは tokens.test.ts の「H. 不透明度修飾の生成形」が固定する
+ *   2. 合成は**チャンネルごとの `a*FG + (1-a)*BG`** で、ガンマ符号化された sRGB 値を
+ *      直接ブレンドする (web の既定)
+ *   3. 比の計算に使うのは **8bit へ丸めた値**である。丸めまで再現しないと
+ *      docs/design-system.md の記録値と 0.01 ずれる
+ *   これは「ブラウザが必ずこう描く」という主張ではない。**本 gate が判定に使う近似**であり、
+ *   近似が判定を変えていないことは「丸めない合成との比が 4.5 の境界を跨がない」検査が別に固定する。
+ *   広い色域 (Display P3 等) の実描画との厳密一致は**測っていない** (家系の未決論点 q3)。
+ *
+ * 【下地について】**下地は宣言しない**。実在する不透明な下地 = 役割分類の「面」
+ *   (`SURFACE_ROLE_TOKENS`) の**すべて**の上で 4.5:1 を要求するので、部品がどちらに
+ *   置かれても成立する。**「面」と「テキストを載せる塗り」は別物である** —
+ *   `border` は Button の hover 塗りとしてテキストを載せるが、容器の背景として宣言された
+ *   用途は無いので「面」ではなく、半透明の合成の**下地には数えない**。
+ *   下地に数えると、実際には起きない重ね方 (ソフト背景のバッジを Button の hover 塗りの上へ
+ *   置く) を根拠にテーマ値の是正を要求することになる。
+ *   この線引きは**宣言であって導出ではない** (静的走査は親要素を辿れない = 正典 i22 (2))。
+ *   ソフト背景の部品を面以外の上へ置かないことは DESIGN.md §状態色の規約行が受ける。
+ *
+ * 【本 gate が保証しないもの】走査単位をまたいで成立する組 / 親から渡る class /
+ *   親要素から継承する背景 / 実行時に組み立てられる class
+ *   (正本は tests/js/styles/class-usage.ts の docblock)。
+ */
+
+/** 合成の入力は**完全に正規化してから**渡す (alpha の出所を 1 つにする)。 */
+interface ResolvedAlphaBackground {
+    readonly rgb: Rgb;
+    readonly effectiveAlpha: number;
+}
+
+/**
+ * **token 固有 alpha と class 修飾を合成する唯一の場所**である。
+ *
+ * 引数は**百分率** (`modifierPercent`)、戻り値は **0..1 の実効値** (`effectiveAlpha`) で、
+ * 名前が単位を表す。正規化の規則は 1 本である —
+ *   `effectiveAlpha = (token の値が持つ alpha ?? 1) × ((modifierPercent ?? 100) / 100)`
+ */
+function resolveAlphaBackground(
+    suffix: CssColorSuffix,
+    modifierPercent: number | null,
+): ResolvedAlphaBackground {
+    const parsed = parseCssColor(
+        requiredMapValue(cssColorTokens(), suffix, `--color-${suffix}`),
+    );
+    const tokenAlpha = parsed.kind === "alpha" ? parsed.alpha : 1;
+
+    return { rgb: parsed.rgb, effectiveAlpha: tokenAlpha * ((modifierPercent ?? 100) / 100) };
+}
+
+/** suffix 空間の不透明な色を RGB で取る (前景と下地に使う)。 */
+function opaqueRgb(suffix: string): Rgb {
+    const parsed = parseCssColor(requiredMapValue(cssColorTokens(), suffix, `--color-${suffix}`));
+    if (parsed.kind !== "opaque") throw new Error(`--color-${suffix} が不透明色ではない`);
+
+    return parsed.rgb;
+}
+
+/** DESIGN.md の色キー → tokens.css の suffix。 */
+function toSuffix(designKey: string): string {
+    const suffix = (COLOR_TOKEN_MAP as Readonly<Record<string, string>>)[designKey];
+    if (suffix === undefined) throw new Error(`COLOR_TOKEN_MAP に ${designKey} が無い`);
+
+    return suffix;
+}
+
+/** `ParsedColor` を直接受けない (alpha の出所を 1 つにする)。`round` を切ると近似の裏取りになる。 */
+function compositeOverOpaque(
+    background: ResolvedAlphaBackground,
+    base: Rgb,
+    round: boolean,
+): Rgb {
+    const mix = (fg: number, bg: number): number => {
+        const value = background.effectiveAlpha * fg + (1 - background.effectiveAlpha) * bg;
+
+        return round ? Math.round(value) : value;
+    };
+
+    return {
+        r: mix(background.rgb.r, base.r),
+        g: mix(background.rgb.g, base.g),
+        b: mix(background.rgb.b, base.b),
+    };
+}
+
+function alphaPairRatio(pair: AlphaPair, baseDesignKey: string, round: boolean): number {
+    const background = resolveAlphaBackground(pair.bg, pair.modifierPercent);
+    const composite = compositeOverOpaque(background, opaqueRgb(toSuffix(baseDesignKey)), round);
+
+    return ratioOfLuminance(luminanceOfRgb(opaqueRgb(pair.fg)), luminanceOfRgb(composite));
+}
+
+/** 既知の値だけで比を出す (負のコントロール用。台帳にも写像にも依存しない)。 */
+function ratioOfComposite(fgHex: string, bgHex: string, alpha: number, baseHex: string): number {
+    const hexRgb = (hex: string): Rgb => ({
+        r: parseInt(hex.slice(1, 3), 16),
+        g: parseInt(hex.slice(3, 5), 16),
+        b: parseInt(hex.slice(5, 7), 16),
+    });
+    const composite = compositeOverOpaque(
+        { rgb: hexRgb(bgHex), effectiveAlpha: alpha },
+        hexRgb(baseHex),
+        true,
+    );
+
+    return ratioOfLuminance(luminanceOfRgb(hexRgb(fgHex)), luminanceOfRgb(composite));
+}
+
+/** キーの一意性を確かめる (同じキーを複数行へ分割して集合一致を誤魔化せないようにする)。 */
+function expectUnique<T>(rows: readonly T[], key: (row: T) => readonly unknown[]): void {
+    const keys = rows.map((row) => JSON.stringify(key(row)));
+    expect(new Set(keys).size, `台帳のキーが重複している: ${keys.join(", ")}`).toBe(keys.length);
+}
+
+describe("architecture/contrast-invariant: 半透明背景 × 不透明文字 (面のすべての上で 4.5:1)", () => {
+    const scan = scanClassUsage();
+
+    it("走査で見つかった半透明の組と使用箇所台帳が (ファイル, 組, 修飾, 件数) で完全一致する", () => {
+        const counted = new Map<string, number>();
+        for (const pair of scan.pairs) {
+            if (pair.kind !== "alpha-background") continue;
+            const key = `${pair.file}|${pair.fg}|${pair.bg}|${pair.modifierPercent ?? "-"}`;
+            counted.set(key, (counted.get(key) ?? 0) + 1);
+        }
+        const declared = new Map<string, number>(
+            ALPHA_PAIR_USAGE_LEDGER.map((row) => [
+                `${row.file}|${row.fg}|${row.bg}|${row.modifierPercent ?? "-"}`,
+                row.count,
+            ]),
+        );
+        expect(counted.size, "半透明の組が 1 件も取れない (走査の空振り)").toBeGreaterThan(0);
+        expect(
+            Object.fromEntries([...counted].sort()),
+            "走査結果と ALPHA_PAIR_USAGE_LEDGER が食い違っている (台帳を更新すること)",
+        ).toEqual(Object.fromEntries([...declared].sort()));
+    });
+
+    it("判定不能の単位と台帳が (ファイル, 理由, 件数) の完全一致で揃う", () => {
+        const counted = new Map<string, number>();
+        for (const pair of scan.pairs) {
+            if (pair.kind !== "undecidable") continue;
+            const key = `${pair.file}|${pair.reason}`;
+            counted.set(key, (counted.get(key) ?? 0) + 1);
+        }
+        const declared = new Map<string, number>(
+            UNDECIDABLE_PAIR_LEDGER.map((row) => [`${row.file}|${row.reason}`, row.count]),
+        );
+        expect(counted.size, "判定不能が 1 件も取れない (走査の空振り)").toBeGreaterThan(0);
+        expect(
+            Object.fromEntries([...counted].sort()),
+            "走査結果と UNDECIDABLE_PAIR_LEDGER が食い違っている (台帳を更新すること)",
+        ).toEqual(Object.fromEntries([...declared].sort()));
+    });
+
+    it("台帳の理由が UndecidableReason の値域に収まり、分類が全数である (never で収束)", () => {
+        const known = new Set<string>(UNDECIDABLE_REASONS.map((r) => r.id));
+        for (const row of UNDECIDABLE_PAIR_LEDGER) {
+            expect(known.has(row.reason), `${row.file}: 未知の理由 ${row.reason}`).toBe(true);
+            expect(row.note.length, `${row.file}: 理由の説明`).toBeGreaterThan(0);
+        }
+        // 分類の網羅を never へ収束させる (値域に足したら必ずここが赤くなる)。
+        const label = (reason: UndecidableReason): string => {
+            switch (reason) {
+                case "foreground-alpha":
+                case "keyword-color":
+                case "alpha-background-no-text":
+                case "opaque-and-alpha-background":
+                case "multiple-background":
+                case "multiple-foreground":
+                case "element-opacity":
+                case "interpolated":
+                case "variant-composition":
+                    return reason;
+                default: {
+                    const exhaustive: never = reason;
+
+                    return exhaustive;
+                }
+            }
+        };
+        expect(UNDECIDABLE_REASONS.map((r) => label(r.id))).toEqual(
+            UNDECIDABLE_REASONS.map((r) => r.id),
+        );
+    });
+
+    it("台帳の行が一意で、件数と修飾率が値域に収まる", () => {
+        // 集合 + 件数の比較は、同じキーを複数行へ分割したり count: 0 を登録したりすると
+        // 正規化のしかた次第で意図しない一致が起きる。
+        // キーの一意性と値域を独立した不変条件として固定する。
+        expectUnique(ALPHA_PAIR_USAGE_LEDGER, (r) => [r.file, r.fg, r.bg, r.modifierPercent]);
+        expectUnique(UNDECIDABLE_PAIR_LEDGER, (r) => [r.file, r.reason]);
+        for (const row of [...ALPHA_PAIR_USAGE_LEDGER, ...UNDECIDABLE_PAIR_LEDGER]) {
+            expect(Number.isInteger(row.count) && row.count > 0, `${row.file}: count`).toBe(true);
+        }
+        for (const row of ALPHA_PAIR_USAGE_LEDGER) {
+            const m = row.modifierPercent;
+            expect(
+                m === null || (Number.isInteger(m) && m >= 0 && m <= 100),
+                `${row.file}: modifierPercent`,
+            ).toBe(true);
+        }
+    });
+
+    it("distinctPairs の仕様 (重複除去・並び順・キー生成) を固定検体で固定する", () => {
+        // 「射影と ALPHA_CONTRAST_PAIRS が集合一致する」は導出しているので恒真に近い。
+        // 共通規約 (d) の形骸化に当たるため置かず、導出関数そのものを固定する。
+        const fixture: readonly AlphaPair[] = [
+            { fg: "primary", bg: "primary-soft", modifierPercent: null },
+            { fg: "primary", bg: "primary-soft", modifierPercent: null },
+            { fg: "primary", bg: "primary-soft", modifierPercent: 40 },
+            { fg: "danger", bg: "danger", modifierPercent: 10 },
+        ];
+        // 並び順はキー文字列 (`fg|bg|修飾率、修飾なしは "-"`) の昇順である。
+        expect(distinctPairs(fixture)).toEqual([
+            { fg: "danger", bg: "danger", modifierPercent: 10 },
+            { fg: "primary", bg: "primary-soft", modifierPercent: null },
+            { fg: "primary", bg: "primary-soft", modifierPercent: 40 },
+        ]);
+        // 修飾率 null と 0 は別のキーになる (null を 0 へ潰さない)
+        expect(
+            distinctPairs([
+                { fg: "primary", bg: "primary", modifierPercent: null },
+                { fg: "primary", bg: "primary", modifierPercent: 0 },
+            ]).length,
+        ).toBe(2);
+    });
+
+    it("意味ペアが 0 件でない (空振り防止)", () => {
+        expect(ALPHA_CONTRAST_PAIRS.length).toBeGreaterThan(0);
+        expect(SURFACE_ROLE_TOKENS.length).toBeGreaterThan(0);
+    });
+
+    it.each(ALPHA_CONTRAST_PAIRS)(
+        "[alpha bg] %o が面のすべての上で 4.5:1 以上",
+        ({ fg, bg, modifierPercent }) => {
+            for (const base of SURFACE_ROLE_TOKENS) {
+                const ratio = alphaPairRatio({ fg, bg, modifierPercent }, base, true);
+                expect(
+                    ratio,
+                    `text-${fg} on bg-${bg}${modifierPercent === null ? "" : `/${modifierPercent}`} ` +
+                        `over ${base} = ${ratio.toFixed(2)}:1。` +
+                        `DESIGN.md の色値を見直すこと (ペア集合を縮めて green にしないこと)`,
+                ).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
+            }
+        },
+    );
+
+    it("負のコントロール: 是正前の値では soft 背景が AA を割り、是正後は通る", () => {
+        // 家系で実在した違反値を固定する (正典 i18 (d))。
+        // primary #2563EB の 12% を neutral #F4F4F5 の上へ合成 → 4.01 で 4.5 を割る。
+        expect(ratioOfComposite("#2563eb", "#2563eb", 0.12, "#f4f4f5")).toBeLessThan(
+            AA_NORMAL_TEXT,
+        );
+        // 是正後の値では通る。
+        expect(
+            ratioOfComposite("#1d4ed8", "#1d4ed8", 0.12, "#f4f4f5"),
+        ).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
+    });
+
+    it("負のコントロール: 8bit の丸めを省くと比がずれる (丸めが判定に効いている)", () => {
+        const pair: AlphaPair = { fg: "primary", bg: "primary-soft", modifierPercent: null };
+        const rounded = alphaPairRatio(pair, "neutral", true);
+        const exact = alphaPairRatio(pair, "neutral", false);
+        expect(rounded).not.toBe(exact);
+        expect(rounded).toBeCloseTo(exact, 1);
+    });
+
+    it("近似の裏取り: 丸めない合成との比が 4.5 の境界を跨ぐ組が無い", () => {
+        // 8bit へ丸める近似が**判定そのものを変えていない**ことを固定する。
+        // 跨ぐ組が現れたら、その組は近似の当否に判定が依存しているので、
+        // 近似モデルの側を見直す契機になる (緩める理由にはしない)。
+        for (const pair of ALPHA_CONTRAST_PAIRS) {
+            for (const base of SURFACE_ROLE_TOKENS) {
+                const rounded = alphaPairRatio(pair, base, true);
+                const exact = alphaPairRatio(pair, base, false);
+                expect(
+                    rounded >= AA_NORMAL_TEXT,
+                    `${pair.fg} on ${pair.bg} over ${base}`,
+                ).toBe(exact >= AA_NORMAL_TEXT);
+            }
+        }
+    });
+});
+
+/*
+ * ===== 実装からの逆向き被覆 (正典 i15) =====
+ *
+ * 役割の宣言を書かずに新しい前景 × 背景の組を足す経路を塞ぐ。
+ * 走査器 (tests/js/styles/class-usage.ts) は CSS suffix 空間を返すので、
+ * COLOR_TOKEN_MAP の逆写像で DESIGN.md の色キー空間へ写してから母集団と突き合わせる
+ * (逆写像が一意であることは canonical-source-parity が固定する)。
+ *
+ * **解決できなかった class トークン (`resolution.kind === "unresolved"`) を 0 件に固定するのは
+ * token-reference-closure.test.ts (参照の閉包) の担当である** — 同じ主張を 2 つの gate へ
+ * 書くと、片方を緩めたときにもう片方が残っていることが分かりにくくなる
+ * (責務境界は docs/design-system.md の表が正本)。
+ */
+
+/** CSS suffix → DESIGN.md の色キー (逆写像)。 */
+function toDesignKey(suffix: string): string {
+    const found = Object.entries(COLOR_TOKEN_MAP).find(([, value]) => value === suffix);
+    if (found === undefined) throw new Error(`COLOR_TOKEN_MAP の逆写像に ${suffix} が無い`);
+
+    return found[0];
+}
+
+describe("architecture/contrast-invariant: 実装からの逆向き被覆 (i15)", () => {
+    const scan = scanClassUsage();
+
+    it("走査の分母が空でない (ディレクトリ単位の走査が生きている)", () => {
+        // 非空を要求するのは `requiresOccurrences: true` の子だけである
+        // (全件へ要求すると、抽出 0 件が正常な lib / types / 直下ファイルで必ず赤になる)。
+        expect(scan.files.length).toBeGreaterThan(0);
+        expect([...scan.perDirectory.keys()].sort()).toEqual(
+            Object.keys(JS_SCAN_CHILD_CLASSIFICATION).sort(),
+        );
+        for (const [dir, spec] of Object.entries(JS_SCAN_CHILD_CLASSIFICATION)) {
+            if (!spec.requiresOccurrences) continue;
+            expect(scan.perDirectory.get(dir), `${dir} から 1 件も抽出できていない`).toBeGreaterThan(
+                0,
+            );
+        }
+    });
+
+    it("走査で得た不透明ペアがすべて母集団 (役割の直積 + 個別宣言) の内側にある", () => {
+        const population = new Set(PAIRS.map(([fg, bg]) => `${fg}|${bg}`));
+        const scanned = [
+            ...new Set(
+                scan.pairs.flatMap((p) =>
+                    p.kind === "opaque" ? [`${toDesignKey(p.fg)}|${toDesignKey(p.bg)}`] : [],
+                ),
+            ),
+        ].sort();
+        expect(scanned.length, "不透明ペアが 1 件も取れない (走査の空振り)").toBeGreaterThan(0);
+        expect(
+            scanned.filter((pair) => !population.has(pair)),
+            "役割宣言に無い前景 × 背景の組が実装に現れた。" +
+                "COLOR_TOKEN_ROLES へ役割を足すか、直積で表現できないなら " +
+                "DECLARED_CONTRAST_PAIRS へ理由つきで登録すること",
+        ).toEqual([]);
+    });
+
+    it("既知の要求組が抽出結果から実際に生成される (抽出の空振り防止)", () => {
+        // Badge の soft 背景と Button の塗り面が、走査結果に実際に現れることを固定する。
+        const alpha = new Set(
+            scan.pairs.flatMap((p) =>
+                p.kind === "alpha-background" ? [`${p.fg}|${p.bg}`] : [],
+            ),
+        );
+        const opaque = new Set(
+            scan.pairs.flatMap((p) => (p.kind === "opaque" ? [`${p.fg}|${p.bg}`] : [])),
+        );
+        expect(alpha.has("primary|primary-soft"), "Badge primary tone が抽出できていない").toBe(
+            true,
+        );
+        expect(opaque.has("neutral|primary"), "Button primary variant が抽出できていない").toBe(
+            true,
+        );
+    });
+
+    it("走査器が扱えない既知の入口が 0 件である", () => {
+        expect(unsupportedEntryPoints()).toEqual([]);
+    });
+
+    it("個別宣言ペアが 5 条を満たす (直積の既定拒否を迂回できない)", () => {
+        const declaredBackgrounds = new Set<string>(DECLARED_CONTRAST_PAIRS.map((p) => p.bg));
+        const scanned = new Set(
+            scan.pairs.flatMap((p) => (p.kind === "opaque" ? [`${p.fg}|${p.bg}`] : [])),
+        );
+        expectUnique(DECLARED_CONTRAST_PAIRS, (p) => [p.fg, p.bg]);
+        for (const p of DECLARED_CONTRAST_PAIRS) {
+            expect(rolesOf(p.bg), `${p.bg}: 背景側の役割`).toContain("declared-text-background");
+            expect(rolesOf(p.bg), `${p.bg}: 直積で受けられる背景は個別宣言にしない`).not.toContain(
+                "surface",
+            );
+            expect(rolesOf(p.bg), `${p.bg}: 直積で受けられる背景は個別宣言にしない`).not.toContain(
+                "fill",
+            );
+            expect(
+                rolesOf(p.fg).some((r) => r === "text-on-surface" || r === "fill-label"),
+                `${p.fg}: 前景側の役割`,
+            ).toBe(true);
+            expect(p.reason.length, `${p.fg} on ${p.bg}: 理由`).toBeGreaterThan(30);
+            // 実装に存在しない個別宣言ペアを足せないようにする (走査は suffix 空間なので写す)
+            expect(
+                scanned.has(
+                    `${(COLOR_TOKEN_MAP as Readonly<Record<string, string>>)[p.fg]}|` +
+                        `${(COLOR_TOKEN_MAP as Readonly<Record<string, string>>)[p.bg]}`,
+                ),
+                `${p.fg} on ${p.bg}: 実装に 1 件も無い個別宣言ペア`,
+            ).toBe(true);
+        }
+        // 役割だけ宣言して組を書かない = 死んだ宣言を作らせない
+        for (const token of tokensWithRole("declared-text-background")) {
+            expect(declaredBackgrounds.has(token), `${token}: 役割はあるが個別宣言ペアが無い`).toBe(
+                true,
+            );
+        }
+    });
+});
diff --git a/tests/js/styles/canonical-source-parity.test.ts b/tests/js/styles/canonical-source-parity.test.ts
index d5985bc6..1d4ff4f9 100644
--- a/tests/js/styles/canonical-source-parity.test.ts
+++ b/tests/js/styles/canonical-source-parity.test.ts
@@ -18,22 +18,24 @@ import {
     designRounded,
     designTypographyNames,
 } from "./design-md";
+// 写像 (tokens.css) 側のパーサは 1 実装へ集約する (正典 i21)。ローカルの抽出は持たない。
+import {
+    cssColorTokens,
+    cssRadiusTokens,
+    cssRampUtilities,
+    parseThemeMap,
+    readResourceCss,
+    requiredMapValue,
+    parseCssColor,
+    resourceCssFiles,
+    tokensCssThemeMap,
+} from "./theme-map";
 
 /**
  * DESIGN.md (canonical) ⇔ resources/css/tokens.css (実装写像) の双方向同期を機械検証する。
  * 片方だけ更新された PR をここで落とす (docs/design-system.md の同期契約)。
  */
 
-const tokensCss = fs.readFileSync(path.join(REPO_ROOT, "resources/css/tokens.css"), "utf-8");
-
-function cssColorTokens(): Map<string, string> {
-    const map = new Map<string, string>();
-    for (const m of tokensCss.matchAll(/--color-([a-z-]+):\s*([^;]+);/g)) {
-        map.set(m[1], m[2].replace(/\/\*.*?\*\//g, "").trim().toLowerCase());
-    }
-    return map;
-}
-
 describe("canonical source parity: colors", () => {
     it("DESIGN.md の色集合と tokens.css の --color-* が一致する (set equality)", () => {
         const design = designColors();
@@ -62,10 +64,7 @@ describe("canonical source parity: radius", () => {
         // section 不在は designRounded() が例外で落とす (旧 expect(section).not.toBeNull() 相当)
         const design = designRounded();
 
-        const css = new Map<string, string>();
-        for (const m of tokensCss.matchAll(/--radius-([a-z]+):\s*([^;]+);/g)) {
-            css.set(m[1], m[2].trim());
-        }
+        const css = cssRadiusTokens();
 
         expect([...css.keys()].sort()).toEqual([...RADIUS_TOKENS].sort());
         for (const key of RADIUS_TOKENS) {
@@ -75,32 +74,26 @@ describe("canonical source parity: radius", () => {
 });
 
 describe("canonical source parity: typography ramp", () => {
-    function cssRamp(name: string): Record<string, string> {
-        const m = tokensCss.match(new RegExp(`@utility text-${name} \\{([^}]+)\\}`));
-        if (!m) throw new Error(`tokens.css @utility not found: text-${name}`);
-        const props: Record<string, string> = {};
-        for (const line of m[1].matchAll(/([a-z-]+):\s*([^;]+);/g)) {
-            props[line[1]] = line[2].trim();
-        }
-        return props;
+    function cssRamp(name: string): ReadonlyMap<string, string> {
+        return requiredMapValue(cssRampUtilities(), name, `tokens.css @utility text-${name}`);
     }
 
     it.each([...TYPOGRAPHY_RAMPS])("text-%s の size/weight/line-height が DESIGN.md と一致する", (name) => {
         const design = designRamp(name);
         const css = cssRamp(name);
 
-        expect(css["font-size"], "font-size").toBe(design["fontSize"]);
-        expect(css["font-weight"], "font-weight").toBe(design["fontWeight"]);
-        expect(css["line-height"], "line-height").toBe(design["lineHeight"]);
+        expect(css.get("font-size"), "font-size").toBe(design["fontSize"]);
+        expect(css.get("font-weight"), "font-weight").toBe(design["fontWeight"]);
+        expect(css.get("line-height"), "line-height").toBe(design["lineHeight"]);
         if (design["letterSpacing"]) {
-            expect(css["letter-spacing"], "letter-spacing").toBe(design["letterSpacing"]);
+            expect(css.get("letter-spacing"), "letter-spacing").toBe(design["letterSpacing"]);
         }
     });
 
     it("ramp の font-weight は 400/500 のみ (DESIGN.md §Typography)", () => {
         for (const name of TYPOGRAPHY_RAMPS) {
             const css = cssRamp(name);
-            expect(["400", "500"], `text-${name} font-weight`).toContain(css["font-weight"]);
+            expect(["400", "500"], `text-${name} font-weight`).toContain(css.get("font-weight"));
         }
     });
 });
@@ -119,9 +112,7 @@ describe("canonical source parity: 検査の母集団", () => {
     });
 
     it("tokens.css の @utility text-* と TYPOGRAPHY_RAMPS が集合一致する", () => {
-        const utilities = [...tokensCss.matchAll(/@utility\s+text-([a-z0-9-]+)\s*\{/g)].map(
-            (m) => m[1],
-        );
+        const utilities = [...cssRampUtilities().keys()];
         expect(utilities.length, "@utility が 0 件 (抽出の空振り)").toBeGreaterThan(0);
         expect([...utilities].sort()).toEqual([...TYPOGRAPHY_RAMPS].sort());
     });
@@ -218,3 +209,45 @@ describe("canonical source parity: frontmatter の節の担当宣言", () => {
         }
     });
 });
+
+/**
+ * 写像 (tokens.css) の**形**そのものを固定する。
+ *
+ * 値の一致 (上の describe) は「見ている宣言が正しい値か」しか見ない。
+ * 見ていない場所に 2 つ目の `@theme` を置くと、どの検査も見ない token 空間が育つ
+ * (正典 i2 前半)。ブロックの一意性はここで固定する。
+ */
+describe("canonical source parity: 写像の形", () => {
+    it("@theme ブロックがリポジトリに 1 つだけある (2 つ目の宣言が検査を素通りする経路を塞ぐ)", () => {
+        // 走査は resources/ 配下の *.css 全数。tokens.css の外に @theme を置くと
+        // canonical-source-parity / tokens の両方が見ない token 空間が育つ。
+        const cssFiles = resourceCssFiles();
+        expect(cssFiles.length, "*.css が 1 件も取れない (走査の空振り)").toBeGreaterThan(0);
+
+        // 判定は parseThemeMap の結果で行う (コメントの中の @theme を数えない)。
+        const withTheme = cssFiles.filter(
+            (rel) => parseThemeMap(readResourceCss(rel), rel).blocks.length > 0,
+        );
+        expect(withTheme).toEqual(["resources/css/tokens.css"]);
+        expect(tokensCssThemeMap().blocks.length, "tokens.css の @theme が 1 ブロックでない").toBe(
+            1,
+        );
+        expect(tokensCssThemeMap().blocks[0].topLevel, "@theme がルート直下でない").toBe(true);
+    });
+
+    it("COLOR_TOKEN_MAP の逆写像が一意である (suffix → DESIGN キーが後勝ちにならない)", () => {
+        // 走査器は suffix 空間を返し、gate は逆写像で DESIGN キー空間へ写す。
+        // 値に重複があると逆引きが後勝ちになり、別のトークンの値で検査してしまう。
+        const suffixes = Object.values(COLOR_TOKEN_MAP);
+        expect(suffixes.length, "COLOR_TOKEN_MAP が空 (走査の空振り)").toBeGreaterThan(0);
+        expect(new Set(suffixes).size).toBe(suffixes.length);
+    });
+
+    it("tokens.css の色宣言が parseCssColor で全件読める (読めない値を素通りさせない)", () => {
+        const colors = cssColorTokens();
+        expect(colors.size, "色トークンが 0 件 (走査の空振り)").toBeGreaterThan(0);
+        for (const [suffix, value] of colors) {
+            expect(() => parseCssColor(value), `--color-${suffix}: ${value}`).not.toThrow();
+        }
+    });
+});
diff --git a/tests/js/styles/class-usage.test.ts b/tests/js/styles/class-usage.test.ts
new file mode 100644
index 00000000..a162e64d
--- /dev/null
+++ b/tests/js/styles/class-usage.test.ts
@@ -0,0 +1,454 @@
+import { describe, expect, it } from "vitest";
+import {
+    isScannedFileName,
+    isWatchedCandidate,
+    scanClassUsage,
+    scanClassUsageSource,
+    unsupportedEntryPoints,
+    unsupportedEntryPointsSource,
+    type ScannedPair,
+    type UndecidableReason,
+} from "./class-usage";
+import { JS_SCAN_CHILD_CLASSIFICATION, UNDECIDABLE_REASONS } from "./inventory";
+import { TONE_CLASSES } from "../../../resources/js/components/atoms/Badge.types";
+import { VARIANT_CLASSES } from "../../../resources/js/components/atoms/Button.types";
+
+/*
+ * class 走査器そのものの仕様を固定検体で固定する (正典 i18)。
+ *
+ * 実リポジトリだけを相手にすると「分解が効いているから緑」なのか
+ * 「分解が壊れていても緑」なのか区別できない。純粋入口
+ * scanClassUsageSource(source, file) / unsupportedEntryPointsSource(source, file) へ
+ * 直接渡して両方向 (検出する / 誤検出しない) を固定する。
+ *
+ * **本 gate が class 走査の診断の消費先である** — S3 (参照の閉包) / S5 (合成) / S7 (逆向き被覆) は
+ * 「実リポジトリ走査の診断が 0 件」という保証に依存する。
+ */
+
+const TS = "fixture.ts";
+const SVELTE = "fixture.svelte";
+
+/** `.ts` の 1 行検体を作る (文字列リテラル 1 つを持つだけのソース)。 */
+const tsUnit = (literal: string): string => `export const a = ${literal};\n`;
+
+const scanTs = (literal: string) => scanClassUsageSource(tsUnit(literal), TS);
+
+const pairsOf = (literal: string): readonly ScannedPair[] => scanTs(literal).pairs;
+
+const opaquePairs = (literal: string): readonly string[] =>
+    pairsOf(literal)
+        .flatMap((p) => (p.kind === "opaque" ? [`${p.fg} on ${p.bg}`] : []))
+        .sort();
+
+const reasonsOf = (scan: { readonly pairs: readonly ScannedPair[] }): readonly string[] =>
+    scan.pairs.flatMap((p) => (p.kind === "undecidable" ? [p.reason] : [])).sort();
+
+describe("class-usage: 字句 (解析器に任せた結果を固定検体で確かめる)", () => {
+    it("コメントの中のリテラルは拾わない", () => {
+        const scan = scanClassUsageSource('// "bg-primary text-danger"\nexport const a = 1;\n', TS);
+        expect(scan.occurrences).toEqual([]);
+        expect(scan.diagnostics).toEqual([]);
+    });
+
+    it("エスケープした引用符で文字列が途中で閉じない", () => {
+        const scan = scanTs("'it\\'s bg-primary'");
+        expect(scan.occurrences.map((o) => o.utility)).toEqual(["bg-primary"]);
+    });
+
+    it("複数行のバッククォートリテラルを 1 単位として扱う", () => {
+        const scan = scanClassUsageSource(
+            "export const a = `bg-surface\n    text-text`;\n",
+            TS,
+        );
+        expect(opaquePairs("`bg-surface\n    text-text`")).toEqual(["text on surface"]);
+        expect(scan.occurrences.length).toBe(2);
+    });
+
+    it("補間を含む単位は interpolated の判定不能になる (通常リテラルに落とさない)", () => {
+        expect(reasonsOf(scanTs("`${x} bg-primary text-neutral`"))).toEqual(["interpolated"]);
+    });
+
+    it("補間の中に閉じ波括弧を含む文字列を終端と誤認しない (以降のソースを読み落とさない)", () => {
+        const source = 'export const a = `${ cond ? "}" : x }`;\nexport const b = "bg-surface text-text";\n';
+        expect(scanClassUsageSource(source, TS).diagnostics).toEqual([]);
+        expect(
+            scanClassUsageSource(source, TS).pairs.flatMap((p) =>
+                p.kind === "opaque" ? [`${p.fg} on ${p.bg}`] : [],
+            ),
+        ).toEqual(["text on surface"]);
+    });
+
+    it("補間の中の object literal と入れ子 template を終端と誤認しない", () => {
+        const source =
+            "export const a = `${ { k: `${y}` } }`;\nexport const b = \"bg-neutral text-text\";\n";
+        const scan = scanClassUsageSource(source, TS);
+        expect(scan.diagnostics).toEqual([]);
+        expect(scan.occurrences.map((o) => o.utility).sort()).toEqual(["bg-neutral", "text-text"]);
+    });
+
+    it("補間内部の class 風文字列を二重に拾わない", () => {
+        const scan = scanTs('`${"bg-primary text-danger"}`');
+        expect(scan.occurrences).toEqual([]);
+    });
+
+    it("未終端の文字列 / template / コメントは診断になり、当該ファイルの結果は空になる", () => {
+        for (const source of [
+            'export const a = "bg-primary;\n',
+            "export const a = `bg-primary;\n",
+            "/* unterminated\nexport const a = 1;\n",
+        ]) {
+            const scan = scanClassUsageSource(source, TS);
+            expect(scan.diagnostics.map((d) => d.reason), source).toEqual(["ts-diagnostic"]);
+            expect(scan.occurrences, source).toEqual([]);
+            expect(scan.pairs, source).toEqual([]);
+        }
+    });
+
+    it("括弧の不整合 (字句エラーではない構文エラー) も診断になる", () => {
+        const scan = scanClassUsageSource('export const a = (1;\n', TS);
+        expect(scan.diagnostics.map((d) => d.reason)).toEqual(["ts-diagnostic"]);
+    });
+
+    it(".svelte の parse 失敗は診断 svelte-parse-failed として残る", () => {
+        const scan = scanClassUsageSource("<div class='bg-primary'>", SVELTE);
+        expect(scan.diagnostics.map((d) => d.reason)).toEqual(["svelte-parse-failed"]);
+        expect(scan.occurrences).toEqual([]);
+    });
+
+    it(".svelte の class 属性の静的テキストと script のリテラルを両方拾う", () => {
+        const source =
+            "<script>\n  const x = \"bg-neutral text-text\";\n</script>\n" +
+            '<div class="bg-surface text-danger"></div>\n';
+        const scan = scanClassUsageSource(source, SVELTE);
+        expect(scan.occurrences.map((o) => o.utility).sort()).toEqual([
+            "bg-neutral",
+            "bg-surface",
+            "text-danger",
+            "text-text",
+        ]);
+    });
+});
+
+describe("class-usage: 監視対象の判定 (isWatchedCandidate)", () => {
+    it.each(["./Button.types", "https://example.com/a", "保存しました", "flex", "px-3"])(
+        "%s は非監視 (文字検証に掛からず無視される)",
+        (candidate) => {
+            expect(isWatchedCandidate(candidate)).toBe(false);
+        },
+    );
+
+    it.each(["bg-primary", "sm:hover:bg-primary", "!bg-primary", "text-center", "bg-primaryあ"])(
+        "%s は監視対象",
+        (candidate) => {
+            expect(isWatchedCandidate(candidate)).toBe(true);
+        },
+    );
+
+    it("非 ASCII の混入は候補全体が unparsable-token になる (bg-primary へ縮退しない)", () => {
+        for (const literal of ['"bg-primaryあ"', '"sm:bg-primaryあ"']) {
+            const scan = scanClassUsageSource(`export const a = ${literal};\n`, TS);
+            expect(scan.occurrences.length, literal).toBe(1);
+            expect(scan.occurrences[0].resolution, literal).toEqual({
+                kind: "unresolved",
+                reason: "unparsable-token",
+            });
+        }
+    });
+
+    it("bg-(--var) も候補全体が unparsable-token になる", () => {
+        const scan = scanTs('"bg-(--var)"');
+        expect(scan.occurrences[0].resolution).toEqual({
+            kind: "unresolved",
+            reason: "unparsable-token",
+        });
+    });
+
+    it("import 指定子や日本語の文字列は occurrences を 1 件も作らない", () => {
+        expect(scanTs('"./Button.types"').occurrences).toEqual([]);
+        expect(scanTs('"保存しました"').occurrences).toEqual([]);
+    });
+});
+
+describe("class-usage: 変種・重要度・不透明度の 3 形 (共通規約 (e))", () => {
+    it("接頭辞つき / 打ち消しつき / 接尾辞つきをそれぞれ正しく解決する", () => {
+        const prefixed = scanTs('"sm:bg-primary"').occurrences[0];
+        expect(prefixed.variants).toEqual(["sm"]);
+        expect(prefixed.utility).toBe("bg-primary");
+        expect(prefixed.resolution.kind).toBe("color");
+
+        const important = scanTs('"!bg-primary"').occurrences[0];
+        expect(important.important).toBe(true);
+        expect(important.utility).toBe("bg-primary");
+
+        const alpha = scanTs('"bg-primary/10"').occurrences[0];
+        expect(alpha.alphaPercent).toBe(10);
+        expect(alpha.utility).toBe("bg-primary");
+    });
+
+    it("色でない utility への不透明度修飾は alpha-on-non-color (text-center として通さない)", () => {
+        expect(scanTs('"text-center/50"').occurrences[0].resolution).toEqual({
+            kind: "unresolved",
+            reason: "alpha-on-non-color",
+        });
+        // 一方 3 形のうち接頭辞つき / 打ち消しつきは正しく解決する
+        expect(scanTs('"sm:text-center"').occurrences[0].resolution).toEqual({
+            kind: "contract",
+            word: "text-center",
+        });
+        expect(scanTs('"!text-center"').occurrences[0].resolution).toEqual({
+            kind: "contract",
+            word: "text-center",
+        });
+    });
+
+    it("不透明度修飾の端点", () => {
+        expect(scanTs('"bg-primary/100"').occurrences[0].alphaPercent).toBeNull();
+        expect(reasonsOf(scanTs('"bg-primary/0 text-text"'))).toEqual(["keyword-color"]);
+        for (const literal of ['"bg-primary/101"', '"bg-primary/[0.35]"']) {
+            expect(
+                scanClassUsageSource(`export const a = ${literal};\n`, TS).occurrences[0].resolution,
+                literal,
+            ).toEqual({ kind: "unresolved", reason: "unsupported-alpha-syntax" });
+        }
+    });
+
+    it("ramp と整列語を前景色として拾わない", () => {
+        expect(scanTs('"text-body"').occurrences[0].resolution).toEqual({
+            kind: "ramp",
+            name: "body",
+        });
+        expect(scanTs('"text-center"').occurrences[0].resolution.kind).toBe("contract");
+        // ramp と整列語だけの単位は前景の宣言を持たないので組にならない
+        expect(pairsOf('"bg-surface text-body text-center"')).toEqual([]);
+    });
+
+    it("DESIGN.md のキーとの衝突: text-primary は前景色 primary、text-text は前景色 text", () => {
+        expect(scanTs('"text-primary"').occurrences[0].resolution).toEqual({
+            kind: "color",
+            channel: "foreground",
+            suffix: "primary",
+        });
+        expect(scanTs('"text-text"').occurrences[0].resolution).toEqual({
+            kind: "color",
+            channel: "foreground",
+            suffix: "text",
+        });
+    });
+});
+
+describe("class-usage: 状態単位の分解 (i15 の設計核心)", () => {
+    it("実在しない組を作らない (直積にしない)", () => {
+        expect(opaquePairs('"bg-surface text-danger hover:bg-danger hover:text-neutral"')).toEqual([
+            "danger on surface",
+            "neutral on danger",
+        ]);
+    });
+
+    it("状態の継承を片側だけ上書きする形も正しく解ける", () => {
+        expect(opaquePairs('"text-text hover:bg-danger"')).toEqual(["text on danger"]);
+        expect(opaquePairs('"bg-surface hover:text-danger"')).toEqual([
+            "danger on surface",
+            "text on surface",
+        ].filter((p) => p === "danger on surface"));
+    });
+
+    it("variant の合成: 4 形をそれぞれ固定する", () => {
+        // 1. 基底 + hover: → 解決可能
+        expect(opaquePairs('"bg-surface hover:text-danger"')).toEqual(["danger on surface"]);
+        // 2. 両 channel が同じ hover: → 解決可能
+        expect(opaquePairs('"bg-surface text-text hover:bg-danger hover:text-neutral"')).toEqual([
+            "neutral on danger",
+            "text on surface",
+        ]);
+        // 3. sm: + sm:hover: → 判定不能
+        expect(reasonsOf(scanTs('"bg-surface sm:bg-neutral sm:hover:text-danger"'))).toEqual([
+            "variant-composition",
+        ]);
+        // 4. sm: + hover: → 判定不能 (同時成立を否定できない)
+        expect(reasonsOf(scanTs('"bg-surface sm:bg-neutral hover:text-danger"'))).toEqual([
+            "variant-composition",
+        ]);
+    });
+
+    it("二重 alpha は判定不能にしない (実効値を作るのは gate 側の 1 か所だけ)", () => {
+        expect(pairsOf('"bg-primary-soft/40 text-text"')).toEqual([
+            {
+                kind: "alpha-background",
+                file: TS,
+                fg: "text",
+                bg: "primary-soft",
+                modifierPercent: 40,
+            },
+        ]);
+    });
+
+    it("token の値が alpha を持つ背景は修飾なしでも alpha-background になる", () => {
+        expect(pairsOf('"bg-primary-soft text-primary"')).toEqual([
+            {
+                kind: "alpha-background",
+                file: TS,
+                fg: "primary",
+                bg: "primary-soft",
+                modifierPercent: null,
+            },
+        ]);
+    });
+});
+
+/**
+ * 判定不能の**全分類**が固定検体で点灯することを確かめる。
+ *
+ * 分類数を散文に書かない — 網羅は `UNDECIDABLE_REASONS` (実行時の配列) から導出する。
+ * 実リポジトリに「各分類が必ず存在する」ことを要求すると、コードが良くなって 0 件になった
+ * 正常状態を赤にしてしまうので、点灯は合成入力で確かめる。
+ */
+const UNDECIDABLE_FIXTURES: Readonly<Record<UndecidableReason, string>> = {
+    "foreground-alpha": '"bg-surface text-danger/70"',
+    "keyword-color": '"bg-transparent text-danger"',
+    "alpha-background-no-text": '"bg-primary/10"',
+    "opaque-and-alpha-background": '"bg-surface bg-primary/10 text-text"',
+    "multiple-background": '"bg-surface bg-neutral text-text"',
+    "multiple-foreground": '"bg-surface text-text text-danger"',
+    "element-opacity": '"bg-primary text-neutral opacity-40"',
+    "interpolated": "`${x} bg-primary text-neutral`",
+    "variant-composition": '"bg-surface sm:bg-neutral hover:text-danger"',
+};
+
+describe("class-usage: 分類分岐の点灯", () => {
+    it("UNDECIDABLE_REASONS の全分類に検体があり、その分類が実際に出る", () => {
+        expect(Object.keys(UNDECIDABLE_FIXTURES).sort()).toEqual(
+            UNDECIDABLE_REASONS.map((r) => r.id).sort(),
+        );
+        for (const [reason, literal] of Object.entries(UNDECIDABLE_FIXTURES)) {
+            expect(reasonsOf(scanTs(literal)), `${reason}: ${literal}`).toContain(reason);
+        }
+    });
+
+    it("不完全な単位の分類が両方向とも点灯する", () => {
+        expect(scanTs('"bg-surface"').incompleteOpaque).toEqual({
+            backgroundOnly: 1,
+            foregroundOnly: 0,
+        });
+        expect(scanTs('"text-text"').incompleteOpaque).toEqual({
+            backgroundOnly: 0,
+            foregroundOnly: 1,
+        });
+    });
+});
+
+describe("class-usage: 既知の要求組が抽出結果から生成される (正例)", () => {
+    it("Badge の全 tone が期待どおりの組へ分解される", () => {
+        const tones = Object.keys(TONE_CLASSES);
+        expect(tones.length, "tone が 0 件 (抽出の空振り)").toBeGreaterThan(0);
+        for (const [tone, classes] of Object.entries(TONE_CLASSES)) {
+            const pairs = pairsOf(JSON.stringify(classes));
+            expect(pairs.length, `${tone}: ${classes}`).toBe(1);
+            expect(pairs[0].kind === "opaque" || pairs[0].kind === "alpha-background", tone).toBe(
+                true,
+            );
+        }
+    });
+
+    it("Button の全 variant が期待どおりの組 / 判定不能へ分解される", () => {
+        const variants = Object.keys(VARIANT_CLASSES);
+        expect(variants.length, "variant が 0 件 (抽出の空振り)").toBeGreaterThan(0);
+        for (const [variant, classes] of Object.entries(VARIANT_CLASSES)) {
+            expect(pairsOf(JSON.stringify(classes)).length, `${variant}: ${classes}`).toBeGreaterThan(
+                0,
+            );
+        }
+    });
+});
+
+describe("class-usage: 扱えない既知の入口の deny", () => {
+    it("3 群それぞれを合成入力で検出する", () => {
+        expect(
+            unsupportedEntryPointsSource("<div class:active={x}></div>\n", SVELTE).map((e) => e.kind),
+        ).toEqual(["class-directive"]);
+        expect(
+            unsupportedEntryPointsSource(
+                'import clsx from "clsx";\nexport const a = clsx("bg-primary");\n',
+                TS,
+            ).map((e) => e.kind),
+        ).toEqual(["class-helper-library"]);
+        expect(
+            unsupportedEntryPointsSource("export const a = `bg-${tone}`;\n", TS).map((e) => e.kind),
+        ).toEqual(["interpolated-prefix"]);
+    });
+
+    it.each(["twMerge", "tailwind-merge", "classnames", "cva"])("%s も語彙で検出する", (name) => {
+        expect(
+            unsupportedEntryPointsSource(`import x from "${name}";\n`, TS).length,
+        ).toBeGreaterThan(0);
+    });
+
+    it("紛らわしい形を誤検出しない (接頭辞つき・打ち消しつき・接尾辞つきの 3 形を含む)", () => {
+        // class: 直後が空白の分割代入 props は別物
+        expect(
+            unsupportedEntryPointsSource(
+                "<script>let { class: extraClass } = $props();</script>\n<div></div>\n",
+                SVELTE,
+            ),
+        ).toEqual([]);
+        // 語彙の部分一致で当てない (接頭辞つき / 打ち消しつき / 接尾辞つき)
+        for (const token of ["myclsx", "clsx-helper", "not_cva", "cvax", "xcva"]) {
+            expect(
+                unsupportedEntryPointsSource(`export const ${token} = 1;\n`, TS),
+                token,
+            ).toEqual([]);
+        }
+        // 完成した class 文字列を補間で差し込む形は入口の deny ではない (判定不能で受ける)
+        expect(unsupportedEntryPointsSource("export const a = `${state} bg-primary`;\n", TS)).toEqual(
+            [],
+        );
+        // テーマ名前空間でない接頭辞の直後の補間は当たらない
+        expect(
+            unsupportedEntryPointsSource("export const a = `take-thumbnail-${id}`;\n", TS),
+        ).toEqual([]);
+    });
+});
+
+describe("class-usage: 拡張子の最長接尾辞一致", () => {
+    it.each([
+        ["resources/js/vite-env.d.ts", false],
+        ["resources/js/app.ts", true],
+        ["resources/js/components/atoms/Badge.svelte", true],
+        ["resources/js/components/atoms/icons/.gitkeep", false],
+    ])("%s の走査可否が %s", (name, scanned) => {
+        expect(isScannedFileName(name)).toBe(scanned);
+    });
+
+    it("未分類の拡張子は例外 (無言で走査対象から外さない)", () => {
+        expect(() => isScannedFileName("resources/js/x.json")).toThrow(/未分類の拡張子/);
+    });
+});
+
+describe("class-usage: 実リポジトリの走査", () => {
+    const scan = scanClassUsage();
+
+    it("解析の診断が 1 件も無い (本 gate が class 走査の診断の消費先である)", () => {
+        expect(scan.diagnostics).toEqual([]);
+    });
+
+    it("走査分母が空でない", () => {
+        expect(scan.files.length).toBeGreaterThan(0);
+        expect(scan.occurrences.length).toBeGreaterThan(0);
+        expect(scan.pairs.length).toBeGreaterThan(0);
+    });
+
+    it("直下の子の分類と走査結果のキーが集合一致し、要求する子は 0 件でない", () => {
+        expect([...scan.perDirectory.keys()].sort()).toEqual(
+            Object.keys(JS_SCAN_CHILD_CLASSIFICATION).sort(),
+        );
+        for (const [dir, spec] of Object.entries(JS_SCAN_CHILD_CLASSIFICATION)) {
+            if (!spec.requiresOccurrences) continue;
+            expect(scan.perDirectory.get(dir), `${dir} から 1 件も抽出できていない`).toBeGreaterThan(
+                0,
+            );
+        }
+    });
+
+    it("扱えない既知の入口が 0 件である", () => {
+        expect(unsupportedEntryPoints()).toEqual([]);
+    });
+});
diff --git a/tests/js/styles/class-usage.ts b/tests/js/styles/class-usage.ts
new file mode 100644
index 00000000..e1b29aa5
--- /dev/null
+++ b/tests/js/styles/class-usage.ts
@@ -0,0 +1,1134 @@
+/**
+ * resources/js の class 記述から「前景 × 背景の組」と「解決できなかった形」を導出する走査器。
+ *
+ * 【走査分母】resources/js のディレクトリ単位の再帰走査 (`*.svelte` / `*.ts`)。
+ *   ファイルを足したら自動で分母に入る (正典 i15 / s14: 固定のファイル列挙は足し忘れが静かに起きる)。
+ *   走査根が存在しなければ **fail-fast**。
+ *
+ * 【解析の方式】**既存の解析器で構文木にしてから読む**。自前の字句走査は書かない。
+ *   準拠実装がリポジトリに在る — `tests/js/support/file-input-scan.ts` は `svelte/compiler` の
+ *   `parse()` で `.svelte` を AST にし、解析できない形を診断へ落とす。
+ *   - `.svelte`: `parse(source, { modern: true })` の AST を歩き、`class` 属性の `Text` チャンクと、
+ *     式・script の中の**文字列リテラルのノード**を単位にする。
+ *     parse が失敗したら診断 `svelte-parse-failed` にして gate を落とす
+ *   - `.ts`: `ts.createSourceFile()` で AST 化し、`StringLiteral` /
+ *     `NoSubstitutionTemplateLiteral` を単位、`TemplateExpression` (置換つき) を
+ *     `interpolated` の判定不能にする。**`ts.createScanner()` は使わない** —
+ *     scanner は字句解析器であり `` `${cond ? "}" : v}` `` の `}` が補間の終端か
+ *     object literal の内側かを判断するには構文文脈が要る。
+ *     **parse diagnostics が 1 件でもあれば解析失敗**にする (括弧の不整合など構文エラー全般が
+ *     fail-closed になる)
+ *   - 置換つき template を判定不能として記録したら、**その subtree へは降りない**
+ *     (降りると補間内部の文字列を独立した class 単位として二重に拾う)
+ *   - **構文解析の失敗はすべて診断**にする (例外は投げない)。診断が出たファイルの
+ *     `occurrences` / `pairs` は**空にする** (部分結果を後続 gate が使う形を作らない)
+ *
+ * 【走査単位 (これが保証する構文集合)】**文字列リテラル** (と `class` 属性の静的テキスト)。
+ *   単位の中だけで状態と組を作る。**それ以外の形については検出力を主張しない**。
+ *   代わりに、扱えない**既知の入口**を語彙の deny (`unsupportedEntryPoints()`) で 0 件に固定する。
+ *
+ * 【class 候補の分解 (4 段)】
+ *   1. まず **CSS の空白** (空白 / タブ / 改行 / CR / FF) で class 候補へ分割する
+ *   2. **監視対象かどうかを先に判定する** (`isWatchedCandidate()`)。
+ *      これが無いと import 指定子 (`"./Button.types"`) や URL のような
+ *      「そもそも class ではない文字列」まで文字検証に掛かって `unparsable-token` になる。
+ *      判定は 3 段で、**文字検証はしない** —
+ *        (a) 先頭から `<何らかの文字列>:` の並びを variant 列として剥がす (最後の `:` まで)
+ *        (b) 残りの先頭の `!` を剥がす
+ *        (c) 残りが**監視対象接頭辞** (`WATCHED_UTILITY_PREFIXES`) のいずれかで始まるなら監視対象
+ *   3. **監視対象と判定した候補だけ**を、候補**全体**の許可文字検証へ回す。
+ *      **許可外の文字が 1 つでもあれば候補全体を `unparsable-token`** にする
+ *   4. そのうえで variant / important / alpha / utility を分解する
+ *   「許可文字以外はすべて区切り」という規則は**採らない** — それだと `bg-primaryあ` が
+ *   `bg-primary` へ縮退して**有効な token として通り**、`bg-(--var)` も候補全体を
+ *   未解決にする根拠を失う。
+ *
+ * 【許可する文字集合 (共通規約 (e) の宣言)】
+ *   英数字 / `_` / `-` / `:` / `/` / `.` / `%` / `[` / `]` / `!` / `#` / `&`。
+ *   `&` 以外は `tests/js/support/ds-purity.ts` の `CLASS_TOKEN_PATTERN` と同じ集合である。
+ *   `&` を足しているのは、本リポジトリに**任意変種**の実例 (`[&_svg]:stroke-current`) が
+ *   在るためで、ds-purity は許可一覧の照合にしか使わないので必要が無かった。
+ *   割れない書き方 (丸括弧・`@`・カンマ・`=`) は候補全体が `unparsable-token` になる。
+ *
+ * 【不透明度修飾の受理範囲】`/` + 半角数字 1〜3 桁で値が **0..100** の形だけを受理する。
+ *   - `/100` は**修飾なし (不透明)** と同じ扱い (`alphaPercent === null`)
+ *   - `/0` は**透明**なので背景が親から来る = `keyword-color` と同じ判定不能
+ *   - 範囲外 (`/101`) / 負数 / 小数 / 任意値 (`/[0.35]`) は
+ *     `unresolved: "unsupported-alpha-syntax"` にして**素通りさせない**
+ *
+ * 【状態の作り方】素の宣言を基底の状態とし、同じ修飾の連なり (`hover:` / `disabled:` …) を
+ *   持つ宣言は基底をその修飾で上書きした状態とする。組は状態の内側だけで作る。
+ *   発火条件の形式化 — 各候補は variant 列 `V` を持つ (素の宣言は空列)。
+ *   単位内の**非空の `V` の集合**を `S` とする (**基底は継承元なので `S` に入れない**)。
+ *   `|S| <= 1` → 解決可能。基底を `S` の唯一の列で channel ごとに上書きした状態を作る。
+ *   `|S| >= 2` → **`variant-composition` の判定不能** (channel を跨いで単位全体を落とす)。
+ *   variant 条件の包含関係は Tailwind の意味論であり、自前で再実装しない。
+ *   これをしないと `"bg-surface text-danger hover:bg-danger hover:text-neutral"` から
+ *   `text-danger on bg-danger` (比 1.0) という**実在しない組**が生まれる。
+ *
+ * 【保証しないもの (誇張しない)】
+ *   - **宣言の単位をまたいで成立する組**。実例: atoms/input-state.ts は `text-text` を
+ *     INPUT_BASE_CLASSES に、`bg-surface` / `bg-neutral` を inputStateClass() の戻り値に持つ。
+ *     ただしこの穴の大部分は役割の直積 (正典 i14) が覆っている — 両方の token に役割が在れば、
+ *     その組は宣言が割れていても既に母集団の内側にある。見えないのは
+ *     「直積に現れない役割の組み合わせの 2 token が同じ要素に載り、かつ宣言の単位が割れている」
+ *     場合だけである
+ *   - **親から渡る class** (`extraClass`) と**親要素から継承する背景** (正典 i22 (2))
+ *   - **実行時に組み立てられる class** (正典 i22 (1))
+ *   - **DOM の実際の入れ子**。同じ単位に載っていることは「同じ要素にある」ことの近似である
+ *   - **変種の修飾の綴りが正しいこと**。`hoverr:bg-primary` は token としては解決する
+ *     (変種の名前空間は Tailwind のもので、本アプリの写像ではない)
+ *   - `resources/views/vendor/mail/html/themes/template.css` は走査根の外である
+ *     (Laravel 同梱メールテーマの独立パレットで DS token の写像ではない)
+ */
+import fs from "node:fs";
+import path from "node:path";
+import postcss from "postcss";
+import ts from "typescript";
+import { parse as parseSvelte } from "svelte/compiler";
+import { REPO_ROOT } from "./design-md";
+import { cssColorTokens, cssRadiusTokens, cssRampUtilities } from "./theme-map";
+import { NON_TOKEN_WORD_CONTRACT, UNDECIDABLE_REASONS } from "./inventory";
+import type { UndecidableReason } from "./inventory";
+
+export type { UndecidableReason };
+
+/**
+ * 監視対象にするテーマ名前空間の接頭辞。**1 か所だけ**に宣言し、
+ * S3 (参照の閉包) と共有する。
+ */
+export const WATCHED_UTILITY_PREFIXES = [
+    "bg-",
+    "text-",
+    "border-",
+    "ring-",
+    "divide-",
+    "outline-",
+    "rounded-",
+    "fill-",
+    "stroke-",
+    "decoration-",
+    "accent-",
+    "caret-",
+    "placeholder-",
+    "from-",
+    "to-",
+    "via-",
+] as const;
+
+/** 色 utility の channel。**前景 / 背景以外も分類する** (正典 i17 の非テキスト境界を混ぜないため)。 */
+export type ColorChannel = "background" | "foreground" | "border" | "ring" | "other";
+
+const CHANNEL_BY_PREFIX: Readonly<Record<string, ColorChannel>> = {
+    "bg-": "background",
+    "text-": "foreground",
+    "border-": "border",
+    "ring-": "ring",
+};
+
+/**
+ * 色そのものを指す CSS のキーワード。契約表の語のうちこれらだけが
+ * 「その channel の色宣言」として状態に効く (`text-center` は整列であって前景色ではない)。
+ */
+const COLOR_KEYWORDS: readonly string[] = [
+    "transparent",
+    "current",
+    "inherit",
+    "initial",
+    "unset",
+    "revert",
+];
+
+/** 解決できなかった理由。 */
+export type UnresolvedReason =
+    /** テーマ名前空間の接頭辞を持つが写像にも契約表にも無い */
+    | "unknown-token"
+    /** 色でない utility に不透明度修飾が付いている */
+    | "alpha-on-non-color"
+    /** 不透明度修飾の書き方が受理範囲外 */
+    | "unsupported-alpha-syntax"
+    /** 区切りで割れた形 (`bg-(--var)` / 非 ASCII の混入) */
+    | "unparsable-token";
+
+/** utility 名の解決結果 (判別可能 union。未解決を無言で候補から外さない = 共通規約 (b))。 */
+export type TokenResolution =
+    | { readonly kind: "color"; readonly channel: ColorChannel; readonly suffix: string }
+    | { readonly kind: "ramp"; readonly name: string }
+    | { readonly kind: "radius"; readonly name: string }
+    | { readonly kind: "contract"; readonly word: string }
+    | { readonly kind: "unresolved"; readonly reason: UnresolvedReason };
+
+/** class トークン 1 件の共通出力 (S3 / S5 / S7 はここから導出する)。 */
+export interface ClassTokenOccurrence {
+    /** リポジトリ相対のファイルパス */
+    readonly file: string;
+    /** 走査単位 (文字列リテラル) の識別子。行番号は持たない (正典 s14) */
+    readonly unit: string;
+    /** 区切りで分割したままの生のトークン (診断用。期待値には使わない) */
+    readonly raw: string;
+    /** 変種の修飾を出現順に並べたもの (`["sm", "hover"]`)。素の宣言は空配列 */
+    readonly variants: readonly string[];
+    /** 重要度の修飾が付いているか */
+    readonly important: boolean;
+    /** 変種・重要度・不透明度を取り除いた utility 名 (`bg-primary` / `text-center`) */
+    readonly utility: string;
+    /**
+     * 不透明度修飾の**百分率** (0..100 の整数)。`null` は修飾なし。
+     * 名前で単位を分ける — 0..1 の実効値を持つのは
+     * `ResolvedAlphaBackground.effectiveAlpha` **だけ**である。
+     */
+    readonly alphaPercent: number | null;
+    /** utility 名が何へ解決したか */
+    readonly resolution: TokenResolution;
+}
+
+/** `var(--…)` 参照 (class ではない別チャネル)。 */
+export interface CssVarReference {
+    readonly file: string;
+    readonly name: string;
+    readonly resolution: TokenResolution;
+}
+
+export type CssVarDiagnosticReason =
+    | "unterminated-string"
+    | "unterminated-function"
+    | "unresolvable-var"
+    | "unsupported-at-rule-params"
+    | "css-parse-failed";
+
+export interface CssVarReferenceDiagnostic {
+    readonly file: string;
+    readonly reason: CssVarDiagnosticReason;
+    readonly detail: string;
+}
+
+export interface CssVarReferenceScan {
+    readonly references: readonly CssVarReference[];
+    readonly diagnostics: readonly CssVarReferenceDiagnostic[];
+    /** 走査したファイル (リポジトリ相対、ソート済み) */
+    readonly files: readonly string[];
+    /** 走査根ごとのファイル数 (根が丸ごと読めていない状態を捕まえる) */
+    readonly perRoot: ReadonlyMap<string, number>;
+}
+
+/** 走査で得た 1 つの組。 */
+export type ScannedPair =
+    | { readonly kind: "opaque"; readonly file: string; readonly fg: string; readonly bg: string }
+    | {
+          readonly kind: "alpha-background";
+          readonly file: string;
+          readonly fg: string;
+          readonly bg: string;
+          /** class 修飾の百分率 (0..100)。`null` は修飾なし (token の値が持つ alpha だけ) */
+          readonly modifierPercent: number | null;
+      }
+    | { readonly kind: "undecidable"; readonly file: string; readonly reason: UndecidableReason };
+
+/** 不透明のみの不完全な単位 (前景か背景の片方しか無い) の集計。 */
+export interface IncompleteOpaqueCounts {
+    readonly backgroundOnly: number;
+    readonly foregroundOnly: number;
+}
+
+export interface ClassScanDiagnostic {
+    readonly file: string;
+    readonly reason: "svelte-parse-failed" | "ts-diagnostic";
+    /** 解析器が返したメッセージ (診断出力用。期待値には使わない) */
+    readonly detail: string;
+}
+
+/** **1 本のソース**の解析結果 (純粋入口が返す形)。 */
+export interface SourceClassUsageScan {
+    readonly occurrences: readonly ClassTokenOccurrence[];
+    readonly pairs: readonly ScannedPair[];
+    readonly incompleteOpaque: IncompleteOpaqueCounts;
+    readonly diagnostics: readonly ClassScanDiagnostic[];
+}
+
+/** **実リポジトリ**の集約結果 (薄いラッパーが返す形)。 */
+export interface ClassUsageScan extends SourceClassUsageScan {
+    /** 走査したファイル (リポジトリ相対、ソート済み)。空なら呼び出し側が落とす */
+    readonly files: readonly string[];
+    /** `resources/js` の直下の子ごとの抽出件数 (どれかが丸ごと読めていない状態を捕まえる) */
+    readonly perDirectory: ReadonlyMap<string, number>;
+}
+
+/** 走査器が扱えない**既知の入口**の出現 (0 件であることを gate が固定する)。 */
+export interface UnsupportedEntryPoint {
+    readonly file: string;
+    readonly kind: "class-directive" | "class-helper-library" | "interpolated-prefix";
+}
+
+/* ===== 走査単位の抽出 ===== */
+
+/** 1 つの走査単位 (文字列リテラル / class 属性の静的テキスト)。 */
+interface Unit {
+    readonly text: string;
+    /** 補間つき template から作られた単位か (判定不能 `interpolated` になる) */
+    readonly interpolated: boolean;
+}
+
+interface SourceUnits {
+    readonly units: readonly Unit[];
+    readonly diagnostics: readonly ClassScanDiagnostic[];
+    readonly entryPoints: readonly UnsupportedEntryPoint[];
+}
+
+const CLASS_ATTRIBUTE = "class";
+
+interface AstNode {
+    readonly type: string;
+    readonly [key: string]: unknown;
+}
+
+const isAstNode = (value: unknown): value is AstNode =>
+    typeof value === "object" &&
+    value !== null &&
+    typeof (value as { type?: unknown }).type === "string";
+
+/**
+ * template literal の quasis から「テーマ名前空間の接頭辞の**内側**に補間が入る形」を探す。
+ *
+ * 判定は「補間の直前にある空白区切りの断片が**監視対象の候補**である」こと。
+ * `bg-${tone}` / `text-body${x}` は当たり、`take-thumbnail-${id}` や
+ * `${border} bg-neutral` は当たらない。
+ */
+function quasiPrefixEntryPoint(texts: readonly string[]): boolean {
+    // 最後の quasi の後ろには補間が無いので見ない。
+    for (const text of texts.slice(0, -1)) {
+        const tail = text.split(CSS_WHITESPACE).pop() ?? "";
+        if (tail !== "" && isWatchedCandidate(tail)) return true;
+    }
+
+    return false;
+}
+
+/** 単位のテキストに監視対象の候補が含まれるか (判定不能へ落とすかの判断に使う)。 */
+function containsWatched(text: string): boolean {
+    return splitCandidates(text).some((candidate) => isWatchedCandidate(candidate));
+}
+
+function svelteUnits(source: string, file: string): SourceUnits {
+    const units: Unit[] = [];
+    const entryPoints: UnsupportedEntryPoint[] = [];
+
+    let ast: unknown;
+    try {
+        ast = parseSvelte(source, { modern: true });
+    } catch (error) {
+        return {
+            units: [],
+            diagnostics: [
+                {
+                    file,
+                    reason: "svelte-parse-failed",
+                    detail: error instanceof Error ? error.message : String(error),
+                },
+            ],
+            entryPoints: [],
+        };
+    }
+
+    const pushTemplate = (node: AstNode): boolean => {
+        const quasis = node["quasis"];
+        const expressions = node["expressions"];
+        if (!Array.isArray(quasis) || !Array.isArray(expressions)) return false;
+        const texts = quasis.map((q) => {
+            const value = isAstNode(q) ? (q["value"] as { raw?: unknown } | undefined) : undefined;
+            return typeof value?.raw === "string" ? value.raw : "";
+        });
+        if (expressions.length === 0) {
+            units.push({ text: texts.join(""), interpolated: false });
+
+            return true;
+        }
+        if (quasiPrefixEntryPoint(texts)) {
+            entryPoints.push({ file, kind: "interpolated-prefix" });
+        }
+        if (texts.some((text) => containsWatched(text))) {
+            units.push({ text: texts.join(" "), interpolated: true });
+        }
+
+        return true;
+    };
+
+    const walk = (value: unknown): void => {
+        if (Array.isArray(value)) {
+            for (const item of value) walk(item);
+
+            return;
+        }
+        if (typeof value !== "object" || value === null) return;
+
+        if (isAstNode(value)) {
+            const node = value;
+            if (node.type === "Comment") return;
+            if (node.type === "ClassDirective") {
+                entryPoints.push({ file, kind: "class-directive" });
+            }
+            if (node.type === "Attribute" && String(node["name"]).toLowerCase() === CLASS_ATTRIBUTE) {
+                const attrValue = node["value"];
+                const parts = Array.isArray(attrValue) ? attrValue : [attrValue];
+                for (const part of parts) {
+                    if (!isAstNode(part) || part.type !== "Text") continue;
+                    units.push({ text: String(part["data"] ?? ""), interpolated: false });
+                }
+                // 式の中のリテラルは下の一般規則が拾う (Text は上で拾ったので二重に採らない)
+                for (const part of parts) {
+                    if (!isAstNode(part) || part.type === "Text") continue;
+                    walk(part);
+                }
+
+                return;
+            }
+            if (node.type === "Literal" && typeof node["value"] === "string") {
+                units.push({ text: node["value"], interpolated: false });
+
+                return;
+            }
+            if (node.type === "TemplateLiteral" && pushTemplate(node)) return;
+        }
+
+        for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
+            if (key === "type" || key === "parent" || key === "loc" || key === "name_loc") continue;
+            walk(child);
+        }
+    };
+
+    walk((ast as { fragment?: unknown }).fragment);
+    walk((ast as { instance?: unknown }).instance);
+    walk((ast as { module?: unknown }).module);
+
+    return { units, diagnostics: [], entryPoints };
+}
+
+function typescriptUnits(source: string, file: string): SourceUnits {
+    const sourceFile = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, false, ts.ScriptKind.TS);
+    const parseDiagnostics = (
+        sourceFile as unknown as { parseDiagnostics?: readonly ts.Diagnostic[] }
+    ).parseDiagnostics;
+    if (parseDiagnostics === undefined) {
+        throw new Error(`${file}: TypeScript の parseDiagnostics を取得できない`);
+    }
+    if (parseDiagnostics.length > 0) {
+        return {
+            units: [],
+            diagnostics: [
+                {
+                    file,
+                    reason: "ts-diagnostic",
+                    detail: ts.flattenDiagnosticMessageText(parseDiagnostics[0].messageText, " "),
+                },
+            ],
+            entryPoints: [],
+        };
+    }
+
+    const units: Unit[] = [];
+    const entryPoints: UnsupportedEntryPoint[] = [];
+
+    const visit = (node: ts.Node): void => {
+        if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) {
+            units.push({ text: node.text, interpolated: false });
+
+            return;
+        }
+        if (ts.isTemplateExpression(node)) {
+            const texts = [
+                node.head.text,
+                ...node.templateSpans.map((span) => span.literal.text),
+            ];
+            if (quasiPrefixEntryPoint(texts)) {
+                entryPoints.push({ file, kind: "interpolated-prefix" });
+            }
+            if (texts.some((text) => containsWatched(text))) {
+                units.push({ text: texts.join(" "), interpolated: true });
+            }
+
+            // 補間内部の文字列を独立した単位として二重に拾わないため subtree へ降りない
+            return;
+        }
+        ts.forEachChild(node, visit);
+    };
+    ts.forEachChild(sourceFile, visit);
+
+    return { units, diagnostics: [], entryPoints };
+}
+
+/* ===== class 候補の分解 ===== */
+
+const CSS_WHITESPACE = /[ \t\n\r\f]+/;
+const ALLOWED_CANDIDATE_CHARS = /^[A-Za-z0-9_:./[\]!%#&-]+$/;
+const ALPHA_MODIFIER = /^\d{1,3}$/;
+
+function splitCandidates(text: string): readonly string[] {
+    return text.split(CSS_WHITESPACE).filter((c) => c !== "");
+}
+
+/** variant 列を剥がした残り (最後の `:` より後ろ) と、剥がした列を返す。 */
+function splitVariants(candidate: string): { variants: readonly string[]; rest: string } {
+    const parts = candidate.split(":");
+    const rest = parts[parts.length - 1];
+
+    return { variants: parts.slice(0, -1), rest };
+}
+
+/** 監視対象の候補か (文字検証はしない)。 */
+export function isWatchedCandidate(candidate: string): boolean {
+    const { rest } = splitVariants(candidate);
+    const withoutImportant = rest.startsWith("!") ? rest.slice(1) : rest;
+
+    return WATCHED_UTILITY_PREFIXES.some((prefix) => withoutImportant.startsWith(prefix));
+}
+
+function longestWatchedPrefix(utility: string): string | null {
+    let found: string | null = null;
+    for (const prefix of WATCHED_UTILITY_PREFIXES) {
+        if (!utility.startsWith(prefix)) continue;
+        if (found === null || prefix.length > found.length) found = prefix;
+    }
+
+    return found;
+}
+
+/** 契約表の語が「色そのものを指すキーワード」か (`bg-transparent` は真、`text-center` は偽)。 */
+function isColorKeyword(utility: string): boolean {
+    const prefix = longestWatchedPrefix(utility);
+    if (prefix === null) return false;
+
+    return COLOR_KEYWORDS.includes(utility.slice(prefix.length));
+}
+
+function contractClassWords(): ReadonlySet<string> {
+    return new Set(
+        NON_TOKEN_WORD_CONTRACT.filter((entry) => entry.kind === "class-word").map(
+            (entry) => entry.word,
+        ),
+    );
+}
+
+function resolveUtility(utility: string, hasAlphaModifier: boolean): TokenResolution {
+    const prefix = longestWatchedPrefix(utility);
+    if (prefix === null) return { kind: "unresolved", reason: "unknown-token" };
+    const rest = utility.slice(prefix.length);
+
+    const colors = cssColorTokens();
+    if (colors.has(rest)) {
+        return { kind: "color", channel: CHANNEL_BY_PREFIX[prefix] ?? "other", suffix: rest };
+    }
+    if (hasAlphaModifier) return { kind: "unresolved", reason: "alpha-on-non-color" };
+    if (prefix === "text-" && cssRampUtilities().has(rest)) return { kind: "ramp", name: rest };
+    if (prefix === "rounded-" && cssRadiusTokens().has(rest)) return { kind: "radius", name: rest };
+    if (contractClassWords().has(utility)) return { kind: "contract", word: utility };
+
+    return { kind: "unresolved", reason: "unknown-token" };
+}
+
+/** 監視対象の候補 1 件を分解する。 */
+function decompose(file: string, unit: string, candidate: string): ClassTokenOccurrence {
+    const base = {
+        file,
+        unit,
+        raw: candidate,
+        variants: [] as readonly string[],
+        important: false,
+        utility: candidate,
+        alphaPercent: null,
+    };
+    if (!ALLOWED_CANDIDATE_CHARS.test(candidate)) {
+        return { ...base, resolution: { kind: "unresolved", reason: "unparsable-token" } };
+    }
+
+    const { variants, rest } = splitVariants(candidate);
+    const important = rest.startsWith("!");
+    const withoutImportant = important ? rest.slice(1) : rest;
+
+    const slash = withoutImportant.lastIndexOf("/");
+    let utility = withoutImportant;
+    let alphaPercent: number | null = null;
+    let hasAlphaModifier = false;
+    if (slash >= 0) {
+        const modifier = withoutImportant.slice(slash + 1);
+        utility = withoutImportant.slice(0, slash);
+        if (!ALPHA_MODIFIER.test(modifier) || Number(modifier) > 100) {
+            return {
+                file,
+                unit,
+                raw: candidate,
+                variants,
+                important,
+                utility: withoutImportant,
+                alphaPercent: null,
+                resolution: { kind: "unresolved", reason: "unsupported-alpha-syntax" },
+            };
+        }
+        hasAlphaModifier = true;
+        const percent = Number(modifier);
+        alphaPercent = percent === 100 ? null : percent;
+    }
+
+    return {
+        file,
+        unit,
+        raw: candidate,
+        variants,
+        important,
+        utility,
+        alphaPercent,
+        resolution: resolveUtility(utility, hasAlphaModifier),
+    };
+}
+
+/* ===== 状態と組の構築 ===== */
+
+const ELEMENT_OPACITY_PREFIX = "opacity-";
+
+interface OpacityCandidate {
+    readonly variantKey: string;
+}
+
+function alphaOfSuffix(suffix: string): number | null {
+    const value = cssColorTokens().get(suffix);
+    if (value === undefined) return null;
+    // 派生 token (rgba) だけが値に alpha を持つ。読めない値は色として扱わない。
+    const match = /^rgba?\(/.test(value);
+    if (!match) return null;
+    const parts = value.replace(/^rgba?\(|\)$/g, "").split(/[,/]/);
+    if (parts.length < 4) return null;
+    const alpha = Number(parts[3].trim());
+
+    return Number.isFinite(alpha) ? alpha : null;
+}
+
+interface UnitScan {
+    readonly pairs: readonly ScannedPair[];
+    readonly backgroundOnly: number;
+    readonly foregroundOnly: number;
+}
+
+function scanUnit(file: string, unit: Unit, occurrences: readonly ClassTokenOccurrence[]): UnitScan {
+    if (unit.interpolated) {
+        return {
+            pairs: [{ kind: "undecidable", file, reason: "interpolated" }],
+            backgroundOnly: 0,
+            foregroundOnly: 0,
+        };
+    }
+
+    const opacity: OpacityCandidate[] = [];
+    for (const candidate of splitCandidates(unit.text)) {
+        const { variants, rest } = splitVariants(candidate);
+        const withoutImportant = rest.startsWith("!") ? rest.slice(1) : rest;
+        if (withoutImportant.startsWith(ELEMENT_OPACITY_PREFIX)) {
+            opacity.push({ variantKey: variants.join(":") });
+        }
+    }
+
+    const variantKeys = new Set<string>();
+    for (const occurrence of occurrences) {
+        if (occurrence.variants.length > 0) variantKeys.add(occurrence.variants.join(":"));
+    }
+    for (const item of opacity) {
+        if (item.variantKey !== "") variantKeys.add(item.variantKey);
+    }
+
+    if (variantKeys.size >= 2) {
+        return {
+            pairs: [{ kind: "undecidable", file, reason: "variant-composition" }],
+            backgroundOnly: 0,
+            foregroundOnly: 0,
+        };
+    }
+
+    const states: readonly string[] = variantKeys.size === 0 ? [""] : ["", [...variantKeys][0]];
+    const reasons = new Set<UndecidableReason>();
+    const pairs: ScannedPair[] = [];
+    let backgroundOnly = 0;
+    let foregroundOnly = 0;
+
+    const inChannel = (channel: ColorChannel, variantKey: string): ClassTokenOccurrence[] =>
+        occurrences.filter(
+            (o) =>
+                o.variants.join(":") === variantKey &&
+                ((o.resolution.kind === "color" && o.resolution.channel === channel) ||
+                    // 契約表の語のうち**色キーワード**だけが channel の色宣言として効く
+                    (o.resolution.kind === "contract" &&
+                        isColorKeyword(o.utility) &&
+                        CHANNEL_BY_PREFIX[longestWatchedPrefix(o.utility) ?? ""] === channel)),
+        );
+
+    for (const variantKey of states) {
+        const pick = (channel: ColorChannel): ClassTokenOccurrence[] => {
+            const own = inChannel(channel, variantKey);
+            if (variantKey === "") return own;
+
+            return own.length > 0 ? own : inChannel(channel, "");
+        };
+
+        const backgrounds = pick("background");
+        const foregrounds = pick("foreground");
+        const hasOpacity = opacity.some(
+            (item) => item.variantKey === variantKey || item.variantKey === "",
+        );
+
+        if (backgrounds.length === 0 && foregrounds.length === 0) continue;
+
+        const isAlphaBackground = (o: ClassTokenOccurrence): boolean =>
+            o.resolution.kind === "color" &&
+            (o.alphaPercent !== null || alphaOfSuffix(o.resolution.suffix) !== null);
+
+        if (backgrounds.length >= 2) {
+            reasons.add(
+                backgrounds.some((o) => isAlphaBackground(o)) &&
+                    backgrounds.some((o) => !isAlphaBackground(o))
+                    ? "opaque-and-alpha-background"
+                    : "multiple-background",
+            );
+            continue;
+        }
+        if (foregrounds.length >= 2) {
+            reasons.add("multiple-foreground");
+            continue;
+        }
+
+        const bg = backgrounds[0];
+        const fg = foregrounds[0];
+
+        if (hasOpacity) {
+            reasons.add("element-opacity");
+            continue;
+        }
+        if (fg !== undefined && (fg.alphaPercent !== null || fg.resolution.kind !== "color")) {
+            reasons.add("foreground-alpha");
+            continue;
+        }
+        if (bg !== undefined && (bg.resolution.kind !== "color" || bg.alphaPercent === 0)) {
+            reasons.add("keyword-color");
+            continue;
+        }
+        if (bg === undefined) {
+            if (fg !== undefined) foregroundOnly += 1;
+            continue;
+        }
+        if (bg.resolution.kind !== "color") continue;
+        const bgSuffix = bg.resolution.suffix;
+        const alpha = isAlphaBackground(bg);
+        if (fg === undefined) {
+            if (alpha) reasons.add("alpha-background-no-text");
+            else backgroundOnly += 1;
+            continue;
+        }
+        if (fg.resolution.kind !== "color") continue;
+        if (alpha) {
+            pairs.push({
+                kind: "alpha-background",
+                file,
+                fg: fg.resolution.suffix,
+                bg: bgSuffix,
+                modifierPercent: bg.alphaPercent,
+            });
+            continue;
+        }
+        pairs.push({ kind: "opaque", file, fg: fg.resolution.suffix, bg: bgSuffix });
+    }
+
+    for (const reason of reasons) pairs.push({ kind: "undecidable", file, reason });
+
+    return { pairs, backgroundOnly, foregroundOnly };
+}
+
+/* ===== 純粋入口 ===== */
+
+/** 拡張子の全数分類 (最長接尾辞一致)。未分類の拡張子が現れたら fail-fast。 */
+const EXTENSION_CLASSIFICATION = [
+    { suffix: ".d.ts", scan: false },
+    { suffix: ".svelte", scan: true },
+    { suffix: ".ts", scan: true },
+    { suffix: ".gitkeep", scan: false },
+] as const;
+
+/** 走査対象の拡張子か (最長接尾辞一致。未分類の拡張子は例外)。 */
+export function isScannedFileName(name: string): boolean {
+    return classifyExtension(name).scan;
+}
+
+function classifyExtension(name: string): (typeof EXTENSION_CLASSIFICATION)[number] {
+    const matches = EXTENSION_CLASSIFICATION.filter((entry) => name.endsWith(entry.suffix));
+    if (matches.length === 0) throw new Error(`未分類の拡張子: ${name}`);
+
+    return matches.reduce((a, b) => (b.suffix.length > a.suffix.length ? b : a));
+}
+
+function extractUnits(source: string, file: string): SourceUnits {
+    return file.endsWith(".svelte") ? svelteUnits(source, file) : typescriptUnits(source, file);
+}
+
+/** **純粋入口**: 1 本のソースから class の出現・組・診断を導出する。 */
+export function scanClassUsageSource(source: string, file: string): SourceClassUsageScan {
+    const { units, diagnostics } = extractUnits(source, file);
+    if (diagnostics.length > 0) {
+        return {
+            occurrences: [],
+            pairs: [],
+            incompleteOpaque: { backgroundOnly: 0, foregroundOnly: 0 },
+            diagnostics,
+        };
+    }
+
+    const occurrences: ClassTokenOccurrence[] = [];
+    const pairs: ScannedPair[] = [];
+    let backgroundOnly = 0;
+    let foregroundOnly = 0;
+
+    for (const unit of units) {
+        const unitOccurrences = splitCandidates(unit.text)
+            .filter((candidate) => isWatchedCandidate(candidate))
+            .map((candidate) => decompose(file, unit.text, candidate));
+        occurrences.push(...unitOccurrences);
+
+        const scan = scanUnit(file, unit, unitOccurrences);
+        pairs.push(...scan.pairs);
+        backgroundOnly += scan.backgroundOnly;
+        foregroundOnly += scan.foregroundOnly;
+    }
+
+    return {
+        occurrences,
+        pairs,
+        incompleteOpaque: { backgroundOnly, foregroundOnly },
+        diagnostics: [],
+    };
+}
+
+const CLASS_HELPER_LIBRARIES = ["clsx", "twMerge", "tailwind-merge", "classnames", "cva"] as const;
+const HELPER_TOKEN_SPLIT = /[^A-Za-z0-9_-]+/;
+
+/** **純粋入口**: 走査器が扱えない既知の入口を語彙の deny で探す。 */
+export function unsupportedEntryPointsSource(
+    source: string,
+    file: string,
+): readonly UnsupportedEntryPoint[] {
+    const found: UnsupportedEntryPoint[] = [...extractUnits(source, file).entryPoints];
+
+    const tokens = new Set(source.split(HELPER_TOKEN_SPLIT));
+    for (const library of CLASS_HELPER_LIBRARIES) {
+        if (tokens.has(library)) found.push({ file, kind: "class-helper-library" });
+    }
+
+    return found;
+}
+
+/* ===== var(--…) 参照の走査 ===== */
+
+const VAR_NAME = /^--[A-Za-z0-9_-]+$/;
+const IDENTIFIER_CHAR = /[A-Za-z0-9_-]/;
+const AT_RULES_WITH_CONDITIONS = ["media", "supports", "container"] as const;
+
+interface VarScanSink {
+    push(name: string): void;
+    diagnose(reason: CssVarDiagnosticReason, detail: string): void;
+}
+
+/**
+ * CSS の値 (または at-rule の条件式) から `var()` 参照を取り出す。
+ *
+ * 受理契約 (括弧カウントだけの実装にしない):
+ *   1. コメントは postcss が `Decl.value` から既に除いている (実測) ので `raws.value.raw` は使わない
+ *   2. 値を左から 1 文字ずつ走査し、`'` / `"` で始まる区間はエスケープ (`\`) を尊重して読み飛ばす
+ *   3. 閉じない引用は診断 `unterminated-string`
+ *   4. 引用区間の**外**で **`var` の関数トークン**を見つけたら括弧の対応を数えて引数列を取る。
+ *      関数トークンの境界 — `var` の直前の文字が識別子文字でも `\` でもなく、直後が `(`
+ *   5. 引数列は**最初のトップレベルのカンマ**で「名前」と「fallback 全体」に分ける
+ *   6. 名前は前後の空白を除いた**全体**が `^--[A-Za-z0-9_-]+$` に一致すること。
+ *      一致しなければ診断 `unresolvable-var`
+ *   7. fallback 全体は同じ規則で**再帰的に**走査する
+ *   8. 閉じない括弧は診断 `unterminated-function`
+ */
+function collectVarReferences(value: string, sink: VarScanSink): void {
+    let i = 0;
+    while (i < value.length) {
+        const ch = value[i];
+        if (ch === "'" || ch === '"') {
+            let j = i + 1;
+            let closed = false;
+            while (j < value.length) {
+                if (value[j] === "\\") {
+                    j += 2;
+                    continue;
+                }
+                if (value[j] === ch) {
+                    closed = true;
+                    break;
+                }
+                j += 1;
+            }
+            if (!closed) {
+                sink.diagnose("unterminated-string", value);
+
+                return;
+            }
+            i = j + 1;
+            continue;
+        }
+        if (
+            value.startsWith("var", i) &&
+            value[i + 3] === "(" &&
+            !(i > 0 && (IDENTIFIER_CHAR.test(value[i - 1]) || value[i - 1] === "\\"))
+        ) {
+            let depth = 0;
+            let j = i + 3;
+            let end = -1;
+            let quote: string | null = null;
+            for (; j < value.length; j += 1) {
+                const c = value[j];
+                if (quote !== null) {
+                    if (c === "\\") {
+                        j += 1;
+                        continue;
+                    }
+                    if (c === quote) quote = null;
+                    continue;
+                }
+                if (c === "'" || c === '"') {
+                    quote = c;
+                    continue;
+                }
+                if (c === "(") depth += 1;
+                else if (c === ")") {
+                    depth -= 1;
+                    if (depth === 0) {
+                        end = j;
+                        break;
+                    }
+                }
+            }
+            if (end < 0) {
+                sink.diagnose("unterminated-function", value);
+
+                return;
+            }
+            const args = value.slice(i + 4, end);
+            // 最初のトップレベルのカンマで名前と fallback に分ける
+            let comma = -1;
+            let level = 0;
+            let q: string | null = null;
+            for (let k = 0; k < args.length; k += 1) {
+                const c = args[k];
+                if (q !== null) {
+                    if (c === "\\") {
+                        k += 1;
+                        continue;
+                    }
+                    if (c === q) q = null;
+                    continue;
+                }
+                if (c === "'" || c === '"') {
+                    q = c;
+                    continue;
+                }
+                if (c === "(") level += 1;
+                else if (c === ")") level -= 1;
+                else if (c === "," && level === 0) {
+                    comma = k;
+                    break;
+                }
+            }
+            const name = (comma < 0 ? args : args.slice(0, comma)).trim();
+            if (!VAR_NAME.test(name)) sink.diagnose("unresolvable-var", args);
+            else sink.push(name);
+            if (comma >= 0) collectVarReferences(args.slice(comma + 1), sink);
+            i = end + 1;
+            continue;
+        }
+        i += 1;
+    }
+}
+
+function resolveVarName(name: string): TokenResolution {
+    if (tokensDeclarationNames().has(name)) {
+        return { kind: "color", channel: "other", suffix: name };
+    }
+    const contract = NON_TOKEN_WORD_CONTRACT.find(
+        (entry) => entry.kind === "css-variable" && entry.name === name,
+    );
+    if (contract !== undefined) return { kind: "contract", word: name };
+
+    return { kind: "unresolved", reason: "unknown-token" };
+}
+
+function tokensDeclarationNames(): ReadonlySet<string> {
+    const names = new Set<string>();
+    for (const suffix of cssColorTokens().keys()) names.add(`--color-${suffix}`);
+    for (const suffix of cssRadiusTokens().keys()) names.add(`--radius-${suffix}`);
+    names.add("--font-sans");
+
+    return names;
+}
+
+/** **純粋入口**: 1 本のソースから `var(--…)` 参照を導出する。 */
+export function scanCssVarReferencesSource(
+    source: string,
+    file: string,
+): Pick<CssVarReferenceScan, "references" | "diagnostics"> {
+    const references: CssVarReference[] = [];
+    const diagnostics: CssVarReferenceDiagnostic[] = [];
+    const sink: VarScanSink = {
+        push: (name) => references.push({ file, name, resolution: resolveVarName(name) }),
+        diagnose: (reason, detail) => diagnostics.push({ file, reason, detail }),
+    };
+
+    if (file.endsWith(".css")) {
+        let root;
+        try {
+            root = postcss.parse(source, { from: file });
+        } catch (error) {
+            return {
+                references: [],
+                diagnostics: [
+                    {
+                        file,
+                        reason: "css-parse-failed",
+                        detail: error instanceof Error ? error.message : String(error),
+                    },
+                ],
+            };
+        }
+        root.walkDecls((decl) => collectVarReferences(decl.value, sink));
+        root.walkAtRules((rule) => {
+            if (AT_RULES_WITH_CONDITIONS.some((name) => name === rule.name.toLowerCase())) {
+                collectVarReferences(rule.params, sink);
+
+                return;
+            }
+            if (rule.params.includes("var(")) {
+                sink.diagnose("unsupported-at-rule-params", `@${rule.name} ${rule.params}`);
+            }
+        });
+
+        return { references, diagnostics };
+    }
+
+    const { units, diagnostics: unitDiagnostics } = extractUnits(source, file);
+    if (unitDiagnostics.length > 0) {
+        // class 走査側の診断は class-usage.test.ts が消費するので、ここでは参照 0 件で返す。
+        return { references: [], diagnostics: [] };
+    }
+    for (const unit of units) collectVarReferences(unit.text, sink);
+
+    return { references, diagnostics };
+}
+
+/* ===== 実リポジトリ用の薄いラッパー ===== */
+
+const JS_SCAN_ROOT = "resources/js";
+const CSS_VAR_SCAN_ROOTS = ["resources/js", "resources/css"] as const;
+
+function listFiles(relativeRoot: string): readonly string[] {
+    const root = path.join(REPO_ROOT, relativeRoot);
+    if (!fs.existsSync(root)) throw new Error(`走査根 ${relativeRoot} が存在しない`);
+    const found: string[] = [];
+    const walk = (dir: string): void => {
+        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
+            const full = path.join(dir, entry.name);
+            if (entry.isDirectory()) {
+                walk(full);
+                continue;
+            }
+            if (!entry.isFile()) continue;
+            found.push(path.relative(REPO_ROOT, full).split(path.sep).join("/"));
+        }
+    };
+    walk(root);
+
+    return found.sort();
+}
+
+/** `resources/js` 直下の子の分類キー (直下のファイルは 1 枠にまとめる)。 */
+export const JS_SCAN_DIRECT_FILES_KEY = "(直下のファイル)";
+
+function directChildKey(relative: string): string {
+    const rest = relative.slice(`${JS_SCAN_ROOT}/`.length);
+    const slash = rest.indexOf("/");
+
+    return slash < 0 ? JS_SCAN_DIRECT_FILES_KEY : rest.slice(0, slash);
+}
+
+/** 実リポジトリ (`resources/js`) を走査する。 */
+export function scanClassUsage(): ClassUsageScan {
+    const all = listFiles(JS_SCAN_ROOT);
+    const occurrences: ClassTokenOccurrence[] = [];
+    const pairs: ScannedPair[] = [];
+    const diagnostics: ClassScanDiagnostic[] = [];
+    const files: string[] = [];
+    const perDirectory = new Map<string, number>();
+    let backgroundOnly = 0;
+    let foregroundOnly = 0;
+
+    for (const relative of all) {
+        const key = directChildKey(relative);
+        if (!perDirectory.has(key)) perDirectory.set(key, 0);
+        if (!classifyExtension(relative).scan) continue;
+        files.push(relative);
+        const scan = scanClassUsageSource(fs.readFileSync(path.join(REPO_ROOT, relative), "utf-8"), relative);
+        occurrences.push(...scan.occurrences);
+        pairs.push(...scan.pairs);
+        diagnostics.push(...scan.diagnostics);
+        backgroundOnly += scan.incompleteOpaque.backgroundOnly;
+        foregroundOnly += scan.incompleteOpaque.foregroundOnly;
+        perDirectory.set(key, (perDirectory.get(key) ?? 0) + scan.occurrences.length);
+    }
+
+    return {
+        occurrences,
+        pairs,
+        incompleteOpaque: { backgroundOnly, foregroundOnly },
+        diagnostics,
+        files,
+        perDirectory,
+    };
+}
+
+/** 実リポジトリ (`resources/js` / `resources/css`) の `var(--…)` 参照を走査する。 */
+export function scanCssVarReferences(): CssVarReferenceScan {
+    const references: CssVarReference[] = [];
+    const diagnostics: CssVarReferenceDiagnostic[] = [];
+    const files: string[] = [];
+    const perRoot = new Map<string, number>();
+
+    for (const root of CSS_VAR_SCAN_ROOTS) {
+        const listed = listFiles(root).filter(
+            (relative) => !relative.endsWith(".gitkeep") && !relative.endsWith(".d.ts"),
+        );
+        perRoot.set(root, listed.length);
+        for (const relative of listed) {
+            files.push(relative);
+            const scan = scanCssVarReferencesSource(
+                fs.readFileSync(path.join(REPO_ROOT, relative), "utf-8"),
+                relative,
+            );
+            references.push(...scan.references);
+            diagnostics.push(...scan.diagnostics);
+        }
+    }
+
+    return { references, diagnostics, files: files.sort(), perRoot };
+}
+
+/** 実リポジトリの「扱えない既知の入口」を走査する。 */
+export function unsupportedEntryPoints(): readonly UnsupportedEntryPoint[] {
+    const found: UnsupportedEntryPoint[] = [];
+    for (const relative of listFiles(JS_SCAN_ROOT)) {
+        if (!classifyExtension(relative).scan) continue;
+        found.push(
+            ...unsupportedEntryPointsSource(
+                fs.readFileSync(path.join(REPO_ROOT, relative), "utf-8"),
+                relative,
+            ),
+        );
+    }
+
+    return found;
+}
+
+/** `UNDECIDABLE_REASONS` の値域 (再輸出。分類の全数性は gate が `never` で収束させる)。 */
+export { UNDECIDABLE_REASONS };
diff --git a/tests/js/styles/component-doc-parity.test.ts b/tests/js/styles/component-doc-parity.test.ts
new file mode 100644
index 00000000..5dd7356e
--- /dev/null
+++ b/tests/js/styles/component-doc-parity.test.ts
@@ -0,0 +1,520 @@
+import { describe, expect, it } from "vitest";
+import fs from "node:fs";
+import path from "node:path";
+import { REPO_ROOT, designComponentSections, parseDesignComponentSections } from "./design-md";
+import {
+    COMPONENT_DIR_CLASSIFICATION,
+    COMPONENT_FILE_KINDS,
+    COMPONENT_SECTION_MAPPINGS,
+    type ComponentDirClassification,
+    type ComponentFileKinds,
+    type ComponentSectionMappings,
+} from "./inventory";
+
+/**
+ * 文書 ⇔ 実装の双方向一致 (正典 i10) —
+ * DESIGN.md §Components の `###` 節と `resources/js/components` の部品ファイルが
+ * (申告表を適用したうえで) 集合一致することを検査する。
+ *
+ * 【なぜ要るか】文書に載らない部品が静かに増える形 (家系で実在した「13 部品事件」) と、
+ *   節だけ残って実装が消える形の**両方**を止める。片側だけでは足りない。
+ * 【対象範囲】DS の再利用部品 (`atoms` / `molecules` / `organisms`) だけである。
+ *   `features/` のドメイン部品と `templates/` のレイアウト骨格は対象外で、
+ *   その宣言は `COMPONENT_DIR_CLASSIFICATION` に理由つきで置く (未分類は不合格)。
+ * 【判定は 3 段の純粋関数に分ける】実リポジトリを直接列挙する gate だけでは
+ *   「未分類ディレクトリを足す」「部品を 1 つ足す」の固定検体を同じ判定実装へ渡せない。
+ * 【本 gate が消費する診断】`parseDesignComponentSections()` 経由の DESIGN.md 側 Markdown 診断
+ *   (未終端コメント / 未終端 fence / container fence / 未対応 fence)。
+ *   1 件でもあれば解析失敗として例外になる。
+ * 【保証しないもの】節の**中身**が実装と合っていること (意味論の一致は人のレビューの担当)。
+ */
+
+/* ===== 段 1: ディレクトリ木の仕分け ===== */
+
+/** 走査結果の木 (固定検体からも組み立てられる構造型)。 */
+export interface ComponentTree {
+    /** `resources/js/components` からの相対ディレクトリパス */
+    readonly path: string;
+    readonly directories: readonly ComponentTree[];
+    /** 直下のファイル名 (basename) */
+    readonly files: readonly string[];
+}
+
+export interface ComponentClassification {
+    /** 節を要求する部品 (components からの相対パス) */
+    readonly components: readonly string[];
+    /** 分類表に無いディレクトリ (相対パス) */
+    readonly unclassifiedDirectories: readonly string[];
+    /** 分類表に無いファイル種別 (相対パス) */
+    readonly unclassifiedFiles: readonly string[];
+    /** 判定に実際に使われた分類表のキー */
+    readonly usedDirectoryKeys: readonly string[];
+    /** 対の `*.svelte` を持たない `*.types.ts` */
+    readonly orphanTypes: readonly string[];
+    /** 別ディレクトリで衝突する部品の basename */
+    readonly duplicateBasenames: readonly string[];
+}
+
+/** 最長接尾辞一致でファイル種別を引く (`.types.ts` を `.ts` より先に当てる)。 */
+function fileKindOf(name: string, kinds: ComponentFileKinds): string | null {
+    const matched = Object.keys(kinds).filter((suffix) => name.endsWith(suffix));
+    if (matched.length === 0) return null;
+
+    return matched.reduce((a, b) => (b.length > a.length ? b : a));
+}
+
+/**
+ * ディレクトリ木を分類表で仕分ける。
+ *
+ * 探索規則:
+ *   1. `excluded` の分類は**そこで再帰を止める** (中は一切見ない)
+ *   2. `documented` の分類は**その直下のファイルだけ**を部品の母集団に入れる
+ *   3. `documented` の直下にさらにサブディレクトリがある場合、**そのパス自体が分類表に
+ *      無ければ不合格**にする (深さ 2 以降も同じ規則を適用する)
+ *   4. **部品の basename の重複を無条件に拒否する** (既定の対応がファイル名だけなので、
+ *      `atoms/Foo.svelte` と `molecules/Foo.svelte` があると 1 節へ衝突する)。
+ *      **申告表では救わない** — 本関数は申告表を受け取らないので、救う口を書くと二通りに読める。
+ *      将来重複が要るようになったら、そのとき判定を `compareComponentDocumentation()` 側へ移す
+ *   5. 分類表のキーは実在するディレクトリであり、かつ**実際に判定へ使われた**こと
+ */
+export function classifyComponentTree(
+    tree: ComponentTree,
+    dirClassification: ComponentDirClassification,
+    fileKinds: ComponentFileKinds,
+): ComponentClassification {
+    const components: string[] = [];
+    const unclassifiedDirectories: string[] = [];
+    const unclassifiedFiles: string[] = [];
+    const usedDirectoryKeys: string[] = [];
+    const orphanTypes: string[] = [];
+
+    const visit = (node: ComponentTree): void => {
+        for (const child of node.directories) {
+            const spec = dirClassification[child.path];
+            if (spec === undefined) {
+                unclassifiedDirectories.push(child.path);
+                continue;
+            }
+            usedDirectoryKeys.push(child.path);
+            if (spec.kind === "excluded") continue;
+
+            const svelte = new Set<string>();
+            for (const file of child.files) {
+                const suffix = fileKindOf(file, fileKinds);
+                if (suffix === null) {
+                    unclassifiedFiles.push(`${child.path}/${file}`);
+                    continue;
+                }
+                if (fileKinds[suffix].requiresSection) {
+                    components.push(`${child.path}/${file}`);
+                    svelte.add(file.slice(0, -suffix.length));
+                }
+            }
+            for (const file of child.files) {
+                if (!file.endsWith(".types.ts")) continue;
+                if (!svelte.has(file.slice(0, -".types.ts".length))) {
+                    orphanTypes.push(`${child.path}/${file}`);
+                }
+            }
+            visit(child);
+        }
+    };
+    visit(tree);
+
+    const basenames = components.map((rel) => rel.slice(rel.lastIndexOf("/") + 1));
+    const duplicateBasenames = [
+        ...new Set(basenames.filter((name, index) => basenames.indexOf(name) !== index)),
+    ];
+
+    return {
+        components: [...components].sort(),
+        unclassifiedDirectories: [...unclassifiedDirectories].sort(),
+        unclassifiedFiles: [...unclassifiedFiles].sort(),
+        usedDirectoryKeys: [...usedDirectoryKeys].sort(),
+        orphanTypes: [...orphanTypes].sort(),
+        duplicateBasenames: duplicateBasenames.sort(),
+    };
+}
+
+/* ===== 段 2: 節と部品の突き合わせ ===== */
+
+export interface ComponentDocDiff {
+    /** 実装にあるのに節が無い部品 */
+    readonly missingSections: readonly string[];
+    /** 節があるのに実装が無い節名 */
+    readonly orphanSections: readonly string[];
+    /** 存在しない節 / 存在しないファイルを指す申告 */
+    readonly staleMappings: readonly string[];
+    /** 同じファイルが 2 つの節に申告されている */
+    readonly duplicateMappedFiles: readonly string[];
+    /** 既定の対応で足りるのに申告している */
+    readonly redundantMappings: readonly string[];
+}
+
+/** 既定の対応: 節名 = 拡張子を除いたファイル名。 */
+function defaultSectionName(relative: string): string {
+    const base = relative.slice(relative.lastIndexOf("/") + 1);
+
+    return base.slice(0, base.lastIndexOf("."));
+}
+
+export function compareComponentDocumentation(
+    sections: readonly string[],
+    components: readonly string[],
+    mappings: ComponentSectionMappings,
+): ComponentDocDiff {
+    const sectionSet = new Set(sections);
+    const componentSet = new Set(components);
+
+    const staleMappings: string[] = [];
+    const duplicateMappedFiles: string[] = [];
+    const redundantMappings: string[] = [];
+    const mappedFileToSection = new Map<string, string>();
+
+    for (const mapping of mappings) {
+        if (!sectionSet.has(mapping.section)) staleMappings.push(`節が無い: ${mapping.section}`);
+        for (const file of mapping.files) {
+            if (!componentSet.has(file)) staleMappings.push(`部品が無い: ${file}`);
+            if (mappedFileToSection.has(file)) duplicateMappedFiles.push(file);
+            mappedFileToSection.set(file, mapping.section);
+        }
+        if (mapping.files.length === 1 && defaultSectionName(mapping.files[0]) === mapping.section) {
+            redundantMappings.push(mapping.section);
+        }
+    }
+
+    const covered = new Set<string>();
+    const missingSections: string[] = [];
+    for (const component of components) {
+        const section = mappedFileToSection.get(component) ?? defaultSectionName(component);
+        if (!sectionSet.has(section)) missingSections.push(component);
+        else covered.add(section);
+    }
+    const orphanSections = sections.filter((section) => !covered.has(section));
+
+    return {
+        missingSections: [...missingSections].sort(),
+        orphanSections: [...orphanSections].sort(),
+        staleMappings: [...staleMappings].sort(),
+        duplicateMappedFiles: [...new Set(duplicateMappedFiles)].sort(),
+        redundantMappings: [...redundantMappings].sort(),
+    };
+}
+
+/* ===== 段 3: 実リポジトリ用の薄いラッパー ===== */
+
+const COMPONENTS_ROOT = "resources/js/components";
+
+function readComponentTree(relative: string): ComponentTree {
+    const absolute = path.join(REPO_ROOT, COMPONENTS_ROOT, relative);
+    const entries = fs.readdirSync(absolute, { withFileTypes: true });
+
+    return {
+        path: relative,
+        directories: entries
+            .filter((entry) => entry.isDirectory())
+            .map((entry) => readComponentTree(relative === "" ? entry.name : `${relative}/${entry.name}`)),
+        files: entries.filter((entry) => entry.isFile()).map((entry) => entry.name),
+    };
+}
+
+const tree = readComponentTree("");
+const classification = classifyComponentTree(tree, COMPONENT_DIR_CLASSIFICATION, COMPONENT_FILE_KINDS);
+const sections = designComponentSections();
+const diff = compareComponentDocumentation(sections, classification.components, COMPONENT_SECTION_MAPPINGS);
+
+describe("component-doc-parity: 双方向の集合一致", () => {
+    it("母集団が空でない (走査の空振り防止)", () => {
+        expect(sections.length, "§Components の節が 1 件も取れない").toBeGreaterThan(0);
+        expect(classification.components.length, "部品が 1 件も取れない").toBeGreaterThan(0);
+    });
+
+    it("実装にあるのに節が無い部品が無い", () => {
+        expect(
+            diff.missingSections,
+            "DESIGN.md §Components に節を足すこと (既定の対応に乗らないなら " +
+                "COMPONENT_SECTION_MAPPINGS へ理由つきで申告すること)",
+        ).toEqual([]);
+    });
+
+    it("節があるのに実装が無い節が無い", () => {
+        expect(diff.orphanSections, "実装の消えた節が DESIGN.md に残っている").toEqual([]);
+    });
+});
+
+describe("component-doc-parity: 全数分類 (既定拒否)", () => {
+    it("サブディレクトリが分類表と集合一致する (未分類も死んだ登録も落とす)", () => {
+        expect(classification.unclassifiedDirectories, "分類表に無いディレクトリがある").toEqual([]);
+        expect(
+            classification.usedDirectoryKeys,
+            "判定に使われなかった分類エントリがある (excluded の配下は再帰を止めるので死んだ登録になる)",
+        ).toEqual(Object.keys(COMPONENT_DIR_CLASSIFICATION).sort());
+    });
+
+    it("直下の子のうち第 1 要素の集合が分類表と一致する", () => {
+        const firstSegments = new Set(
+            Object.keys(COMPONENT_DIR_CLASSIFICATION).map((key) => key.split("/")[0]),
+        );
+        expect(tree.directories.map((d) => d.path).sort()).toEqual([...firstSegments].sort());
+    });
+
+    it("ファイル種別が分類表と集合一致する (未分類は不合格)", () => {
+        expect(classification.unclassifiedFiles, "分類表に無い拡張子のファイルがある").toEqual([]);
+    });
+
+    it("孤立した型ファイルが無い (*.types.ts には対の *.svelte がある)", () => {
+        expect(classification.orphanTypes).toEqual([]);
+    });
+
+    it("部品の basename が衝突していない", () => {
+        expect(classification.duplicateBasenames).toEqual([]);
+    });
+
+    it("excluded の分類に理由が書かれている", () => {
+        for (const [dir, spec] of Object.entries(COMPONENT_DIR_CLASSIFICATION)) {
+            if (spec.kind !== "excluded") continue;
+            expect(spec.reason?.length ?? 0, `${dir}: 理由`).toBeGreaterThan(30);
+        }
+    });
+});
+
+describe("component-doc-parity: 申告表の健全性", () => {
+    it("失効・重複・冗長な申告が無い", () => {
+        expect(diff.staleMappings, "存在しない節 / ファイルを指す申告がある").toEqual([]);
+        expect(diff.duplicateMappedFiles, "同じファイルが 2 つの節へ申告されている").toEqual([]);
+        expect(diff.redundantMappings, "既定の対応で足りるのに申告している").toEqual([]);
+    });
+
+    it("申告に理由が書かれている", () => {
+        for (const mapping of COMPONENT_SECTION_MAPPINGS) {
+            expect(mapping.reason.length, `${mapping.section}: 理由`).toBeGreaterThan(30);
+            expect(mapping.files.length, `${mapping.section}: files`).toBeGreaterThan(0);
+        }
+    });
+});
+
+/* ===== 負のコントロール (固定検体を 3 段の純粋関数へ直接渡す) ===== */
+
+const FIXTURE_DIRS: ComponentDirClassification = {
+    atoms: { kind: "documented" },
+    features: { kind: "excluded", reason: "ドメイン部品" },
+};
+const FIXTURE_KINDS: ComponentFileKinds = COMPONENT_FILE_KINDS;
+
+const fixtureTree = (overrides: Partial<ComponentTree> = {}): ComponentTree => ({
+    path: "",
+    directories: [
+        { path: "atoms", directories: [], files: ["Badge.svelte", "Badge.types.ts"] },
+        { path: "features", directories: [], files: ["Domain.svelte"] },
+    ],
+    files: [],
+    ...overrides,
+});
+
+describe("component-doc-parity: 負のコントロール (固定検体)", () => {
+    it("節を 1 つ消すと不合格になる", () => {
+        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
+        expect(compareComponentDocumentation([], c.components, []).missingSections).toEqual([
+            "atoms/Badge.svelte",
+        ]);
+    });
+
+    it("部品を 1 つ足すと不合格になる", () => {
+        const tree2 = fixtureTree({
+            directories: [
+                { path: "atoms", directories: [], files: ["Badge.svelte", "New.svelte"] },
+                { path: "features", directories: [], files: [] },
+            ],
+        });
+        const c = classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS);
+        expect(compareComponentDocumentation(["Badge"], c.components, []).missingSections).toEqual([
+            "atoms/New.svelte",
+        ]);
+    });
+
+    it("実装の消えた節は orphanSections になる", () => {
+        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
+        expect(
+            compareComponentDocumentation(["Badge", "Gone"], c.components, []).orphanSections,
+        ).toEqual(["Gone"]);
+    });
+
+    it("申告を冗長にすると不合格になる", () => {
+        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
+        const redundant = compareComponentDocumentation(["Badge"], c.components, [
+            { section: "Badge", files: ["atoms/Badge.svelte"], reason: "冗長" },
+        ]);
+        expect(redundant.redundantMappings).toEqual(["Badge"]);
+    });
+
+    it("失効した申告 (存在しない節 / ファイル) を落とす", () => {
+        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
+        const stale = compareComponentDocumentation(["Badge"], c.components, [
+            { section: "無い節", files: ["atoms/Missing.svelte"], reason: "失効" },
+        ]);
+        expect(stale.staleMappings.length).toBe(2);
+    });
+
+    it("同じファイルを 2 つの節へ申告すると落とす", () => {
+        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
+        const duplicated = compareComponentDocumentation(["A", "B"], c.components, [
+            { section: "A", files: ["atoms/Badge.svelte"], reason: "1 つ目" },
+            { section: "B", files: ["atoms/Badge.svelte"], reason: "2 つ目" },
+        ]);
+        expect(duplicated.duplicateMappedFiles).toEqual(["atoms/Badge.svelte"]);
+    });
+
+    it("未分類のサブディレクトリを足すと不合格になる", () => {
+        const tree2 = fixtureTree({
+            directories: [
+                { path: "atoms", directories: [], files: ["Badge.svelte"] },
+                { path: "features", directories: [], files: [] },
+                { path: "unknown", directories: [], files: ["X.svelte"] },
+            ],
+        });
+        expect(
+            classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS).unclassifiedDirectories,
+        ).toEqual(["unknown"]);
+    });
+
+    it("documented の下に未分類の入れ子ディレクトリを足すと不合格になる (規則 3)", () => {
+        const tree2 = fixtureTree({
+            directories: [
+                {
+                    path: "atoms",
+                    directories: [{ path: "atoms/nested", directories: [], files: ["X.svelte"] }],
+                    files: ["Badge.svelte"],
+                },
+                { path: "features", directories: [], files: [] },
+            ],
+        });
+        const c = classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS);
+        expect(c.unclassifiedDirectories).toEqual(["atoms/nested"]);
+        expect(c.components).toEqual(["atoms/Badge.svelte"]);
+    });
+
+    it("excluded の下のファイルは母集団に入らない (規則 1)", () => {
+        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
+        expect(c.components).toEqual(["atoms/Badge.svelte"]);
+    });
+
+    it("使われなかった分類エントリを検出できる (規則 5)", () => {
+        const c = classifyComponentTree(fixtureTree(), { ...FIXTURE_DIRS, ghost: { kind: "documented" } }, FIXTURE_KINDS);
+        expect(c.usedDirectoryKeys).toEqual(["atoms", "features"]);
+    });
+
+    it("basename の重複を無条件に拒否する", () => {
+        const tree2 = fixtureTree({
+            directories: [
+                { path: "atoms", directories: [], files: ["Badge.svelte"] },
+                { path: "features", directories: [], files: [] },
+                { path: "molecules", directories: [], files: ["Badge.svelte"] },
+            ],
+        });
+        const c = classifyComponentTree(
+            tree2,
+            { ...FIXTURE_DIRS, molecules: { kind: "documented" } },
+            FIXTURE_KINDS,
+        );
+        expect(c.duplicateBasenames).toEqual(["Badge.svelte"]);
+    });
+
+    it("ファイル種別の最長接尾辞一致 (固定検体)", () => {
+        const tree2 = fixtureTree({
+            directories: [
+                {
+                    path: "atoms",
+                    directories: [],
+                    files: ["Button.svelte", "Button.types.ts", "input-state.ts", "notes.md"],
+                },
+                { path: "features", directories: [], files: [] },
+            ],
+        });
+        const c = classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS);
+        expect(c.components).toEqual(["atoms/Button.svelte"]);
+        expect(c.unclassifiedFiles).toEqual(["atoms/notes.md"]);
+        expect(c.orphanTypes).toEqual([]);
+    });
+
+    it("対の *.svelte を持たない *.types.ts を検出する", () => {
+        const tree2 = fixtureTree({
+            directories: [
+                { path: "atoms", directories: [], files: ["Badge.svelte", "Gone.types.ts"] },
+                { path: "features", directories: [], files: [] },
+            ],
+        });
+        expect(classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS).orphanTypes).toEqual([
+            "atoms/Gone.types.ts",
+        ]);
+    });
+});
+
+describe("component-doc-parity: 節の抽出の負のコントロール (固定検体)", () => {
+    const FENCE = "`".repeat(3);
+    const md = (lines: readonly string[]): string => lines.join("\n");
+
+    it("囲みコードの中の見出しは数えない", () => {
+        expect(
+            parseDesignComponentSections(
+                md(["## Components", FENCE, "### DragHandle", FENCE, "### Badge"]),
+            ),
+        ).toEqual(["Badge"]);
+    });
+
+    it("HTML コメントの中の見出しも数えない", () => {
+        expect(
+            parseDesignComponentSections(
+                md(["## Components", "<!-- ### DragHandle -->", "### Badge"]),
+            ),
+        ).toEqual(["Badge"]);
+    });
+
+    it("#### 以降は数えない", () => {
+        expect(parseDesignComponentSections(md(["## Components", "#### X", "### Badge"]))).toEqual([
+            "Badge",
+        ]);
+    });
+
+    it("## Components が 0 件 / 2 件なら例外", () => {
+        expect(() => parseDesignComponentSections(md(["### Badge"]))).toThrow(/1 節でない/);
+        expect(() =>
+            parseDesignComponentSections(md(["## Components", "## Components"])),
+        ).toThrow(/1 節でない/);
+    });
+
+    it("同名の ### が 2 つあれば例外", () => {
+        expect(() =>
+            parseDesignComponentSections(md(["## Components", "### Badge", "### Badge"])),
+        ).toThrow(/重複/);
+    });
+
+    it("次の ## 以降の節は数えない", () => {
+        expect(
+            parseDesignComponentSections(md(["## Components", "### Badge", "## Other", "### Not"])),
+        ).toEqual(["Badge"]);
+    });
+
+    it("未終端の囲みコードは診断として例外になる", () => {
+        expect(() => parseDesignComponentSections(md(["## Components", FENCE, "### X"]))).toThrow(
+            /Markdown 走査が失敗/,
+        );
+    });
+
+    it("container を伴う fence の中の ### は「数えない」のではなく例外になる", () => {
+        for (const prefix of ["> ", "- > ", "> - "]) {
+            expect(
+                () =>
+                    parseDesignComponentSections(
+                        md([
+                            "## Components",
+                            prefix + FENCE,
+                            prefix + "### 部品名",
+                            prefix + FENCE,
+                            "### Badge",
+                        ]),
+                    ),
+                prefix,
+            ).toThrow(/Markdown 走査が失敗/);
+        }
+    });
+});
diff --git a/tests/js/styles/design-md.ts b/tests/js/styles/design-md.ts
index 611c0c65..16ebda60 100644
--- a/tests/js/styles/design-md.ts
+++ b/tests/js/styles/design-md.ts
@@ -8,6 +8,7 @@
 import fs from "node:fs";
 import path from "node:path";
 import { fileURLToPath } from "node:url";
+import { scanMarkdownLines } from "./markdown-lines";
 
 const HERE = path.dirname(fileURLToPath(import.meta.url));
 export const REPO_ROOT = path.resolve(HERE, "../../../");
@@ -88,3 +89,53 @@ export function designTypographyNames(): readonly string[] {
     }
     return names;
 }
+
+/**
+ * DESIGN.md の本文から §Components の `###` 節名を取り出す。
+ *
+ * **S9 が新設した共通 Markdown 行走査 (`scanMarkdownLines`) を共有する** —
+ * 独立した弱い解析器を増やさない (正典 i21)。単純な見出し正規表現だと、囲みコードの中に
+ * `### 部品名` を置いて「文書化済み」に見せられ、**双方向一致という中心の保証を
+ * 直接迂回できる**。
+ *
+ * 契約 5 条 (いずれも固定検体で裏取りする):
+ *   1. `## Components` は**ちょうど 1 節**であること (0 件も 2 件も例外)
+ *   2. HTML コメントと囲みコードの中の見出しは**数えない**
+ *   3. `###` だけを対象にし、`####` 以降は数えない
+ *   4. 同名の節が 2 つあれば**例外**
+ *   5. Markdown 走査の診断 (未終端コメント / 未終端 fence / container fence /
+ *      未対応 fence) が 1 件でもあれば**解析失敗** (正典 i20)
+ *
+ * **本関数の呼び出し側 (`component-doc-parity.test.ts`) が DESIGN.md 側の
+ * Markdown 診断の消費先である**。
+ */
+export function parseDesignComponentSections(source: string): readonly string[] {
+    const scan = scanMarkdownLines(source);
+    if (scan.diagnostics.length > 0) {
+        const shown = scan.diagnostics.map((d) => `${d.line}:${d.reason}`).join(", ");
+        throw new Error(`DESIGN.md の Markdown 走査が失敗した: ${shown}`);
+    }
+
+    const lines = scan.renderedLines;
+    const heads = lines.flatMap((line, index) => (line.trim() === "## Components" ? [index] : []));
+    if (heads.length !== 1) {
+        throw new Error(`DESIGN.md の "## Components" が 1 節でない (実際 ${heads.length} 件)`);
+    }
+
+    const sections: string[] = [];
+    for (const line of lines.slice(heads[0] + 1)) {
+        if (/^#{1,2}\s/.test(line)) break;
+        const matched = line.match(/^### (.+)$/);
+        if (matched === null) continue;
+        const name = matched[1].trim();
+        if (sections.includes(name)) throw new Error(`DESIGN.md §Components の節が重複: ${name}`);
+        sections.push(name);
+    }
+
+    return sections;
+}
+
+/** 実ファイルの DESIGN.md から §Components の節名を取り出す薄いラッパー。 */
+export function designComponentSections(): readonly string[] {
+    return parseDesignComponentSections(designMd);
+}
diff --git a/tests/js/styles/design-system-docs.test.ts b/tests/js/styles/design-system-docs.test.ts
index 2887e2c8..f180e356 100644
--- a/tests/js/styles/design-system-docs.test.ts
+++ b/tests/js/styles/design-system-docs.test.ts
@@ -2,6 +2,8 @@ import { beforeAll, describe, expect, it } from "vitest";
 import fs from "node:fs";
 import path from "node:path";
 import { REPO_ROOT } from "./design-md";
+// 行の分類 (規範判定対象外領域の除去 / 字下げの禁止) は 1 実装へ集約する (正典 i21)。
+import { scanMarkdownLines } from "./markdown-lines";
 
 /*
  * design-system-docs — docs/design-system.md の**構造**が壊れていないことを検査する。
@@ -11,20 +13,20 @@ import { REPO_ROOT } from "./design-md";
  * 【見ないもの】散文そのもの。下の SECTION_CONTRACT_PHRASES に挙げた最小断片**以外**の
  *   言い回しは検査しない (文章を良くする PR を止めないため)
  *
- * 【描画されない領域を先に落とす理由】
- *   Markdown の本文には「ファイルには書かれているが読者には表示されない」領域がある
- *   (HTML コメント / fenced code)。契約の本文をそこへ移すだけで「節はあるし本文も空でない」
- *   状態を作れてしまうため、検査の前に該当行を空行へ潰す (fail-open を塞ぐ)。
- *   潰しの判定は CommonMark の fence 規則に合わせる — **開始も終了も字下げは 3 空白まで**で、
- *   4 空白以上の `` ``` `` は fence ではない (これを fence 扱いにすると、
- *   区間の途中に偽の終端を置いて後続を「描画される本文」に見せかけられる)。
+ * 【規範判定対象外領域を先に落とす理由】
+ *   Markdown の本文には「規範の本文として数えてはいけない」領域がある
+ *   (HTML コメント = 読者に描画されない / 囲みコード = 描画されるが本文ではない)。
+ *   契約の本文をそこへ移すだけで「節はあるし本文も空でない」状態を作れてしまうため、
+ *   検査の前に該当行を空行へ潰す (fail-open を塞ぐ)。判定の正本は
+ *   `tests/js/styles/markdown-lines.ts` (契約 A / 契約 B) で、本ファイルはその**消費先**である。
  *
  * 【保証しないもの】
  *   - **運用契約の意味が残っていること**。最小断片が本文に在ることまでしか見ておらず、
  *     周りの説明が骨抜きになっていることは検出できない
- *   - **描画されない領域の全種類**。潰すのは HTML コメントと fenced code の 2 つだけで、
- *     4 空白字下げのコードブロックや HTML 要素による非表示は見ていない
- *     (Markdown の文脈依存が強く、誤って本文を潰す方が害が大きいため)。
+ *   - **規範判定対象外領域の全種類**。潰すのは HTML コメントと囲みコードの 2 つだけで、
+ *     HTML 要素による非表示は見ていない。字下げによるコードは**潰さずに検査自体を失敗させる**
+ *     (契約 B。近似で判定すると見出し直後や引用の中の形を取りこぼし、そこへ規範の断片を
+ *     退避させられるため、書き方の側を禁じている)。
  *     また HTML コメントの除去は**行内コード (`` ` `` 囲み) の文脈を見ない**ので、
  *     行内コードとして書いた `<!-- … -->` は読者に見えていても潰される。
  *     ただし跡には目印 (HIDDEN_MARK) が残るため、**読者に見える文字を挟んだ断片が
@@ -71,96 +73,13 @@ const SECTION_CONTRACT_PHRASES: Readonly<Record<string, readonly string[]>> = {
 const EXTERNAL_GATE_FILES = ["tests/js/architecture/contrast-invariant.test.ts"] as const;
 
 /**
- * 読者に描画されない領域 (HTML コメント / fenced code) を空行へ潰した行配列を返す。
+ * 規範判定対象外領域 (HTML コメント / 囲みコード) を空行へ潰した行配列を返す。
  *
- * 行数は保存する (行番号がずれると節の切り出しがずれるため)。
- *
- * fence の判定は CommonMark に合わせる:
- *   - 開始も終了も**字下げは 3 空白まで**。4 空白以上の `` ``` `` は fence ではない
- *     (緩めると、区間の途中に偽の終端を置いて後続を描画される本文に見せかけられる)
- *   - 終了は**開始と同じ記号**で**開始と同じかそれ以上の長さ**、後続は空白のみ
- *     (`~~~` で開いた区間の中の 3 連バッククォートでは閉じない)
- *   - バッククォートで開く行の**情報文字列にバッククォートを含められない**。
- *     含む行は開始 fence ではないので通常の本文として扱う
- *     (fence 扱いにすると、その次の本物の開始 fence を終端と誤認して区間がずれる)
- *
- * HTML コメントを取り除いた跡には**目印 (HIDDEN_MARK) を 1 つ残す**。詰めて繋ぐと、
- * 読者には離れて見える 2 つの断片が検査の上でだけ 1 つの文字列になり、
- * 規範の最小断片と一致してしまう (行内コードの中にコメントを置く形で作れる)。
- * 目印を**空白にしてはいけない** — 最小断片が元々空白を含む位置
- * (`同一 PR 内で` の空白等) にコメントを置かれると、空白では一致を防げないためである。
- *
- * 閉じないまま EOF に達したら、そこまでを潰す。
- */
-const FENCE_OPEN = /^ {0,3}(`{3,}|~{3,})/;
-const FENCE_CLOSE = /^ {0,3}(`{3,}|~{3,})[ \t]*$/;
-
-/**
- * コメントを取り除いた跡に残す目印。垂直タブ (U+000B) を使う。
- *
- * 要件は 2 つある。
- *   1. **規範の最小断片には使わない文字**であること。半角空白のように断片へ現れる文字だと、
- *      最小断片が元々空白を含む位置 (`同一 PR 内で` の空白等) を狙って断片を合成できてしまう
- *   2. **`trim()` が空白として落とす文字**であること。落とさない文字 (U+0000 等) だと、
- *      コメントだけの行が「本文のある行」に見えて節の非空検査をすり抜ける
- * 垂直タブはこの 2 つを同時に満たす (最小断片には使わない / `trim()` の対象)。
- * ファイルに格納できないという意味ではない — 使わないと決めているだけである。
+ * 解析の正本は `markdown-lines.ts` の `scanMarkdownLines()` である
+ * (本ファイルと `design-md.ts` の節抽出が同じ実装を使う = 正典 i21)。
  */
-const HIDDEN_MARK = "\u000B";
-
 function renderedLines(doc: string): readonly string[] {
-    const out: string[] = [];
-    let fence: { readonly char: string; readonly length: number } | null = null;
-    let inComment = false;
-
-    for (const raw of doc.split(/\r?\n/)) {
-        if (fence !== null) {
-            const close = raw.match(FENCE_CLOSE);
-            if (close !== null && close[1][0] === fence.char && close[1].length >= fence.length) {
-                fence = null;
-            }
-            out.push("");
-            continue;
-        }
-
-        let line = raw;
-        if (inComment) {
-            const end = line.indexOf("-->");
-            if (end < 0) {
-                out.push("");
-                continue;
-            }
-            // コメントの終端より後ろだけを描画される本文として残す (跡に目印を置く)
-            line = HIDDEN_MARK + line.slice(end + 3);
-            inComment = false;
-        }
-
-        // 同一行に閉じる HTML コメントは繰り返し取り除く (跡には目印を 1 つ残す)
-        for (;;) {
-            const start = line.indexOf("<!--");
-            if (start < 0) break;
-            const end = line.indexOf("-->", start + 4);
-            if (end < 0) {
-                line = line.slice(0, start) + HIDDEN_MARK;
-                inComment = true;
-                break;
-            }
-            line = line.slice(0, start) + HIDDEN_MARK + line.slice(end + 3);
-        }
-
-        const open = line.match(FENCE_OPEN);
-        // バッククォート fence の情報文字列にバッククォートがある行は開始 fence ではない
-        const infoString = open === null ? "" : line.slice(open[0].length);
-        if (open !== null && !(open[1][0] === "`" && infoString.includes("`"))) {
-            fence = { char: open[1][0], length: open[1].length };
-            out.push("");
-            continue;
-        }
-
-        out.push(line);
-    }
-
-    return out;
+    return scanMarkdownLines(doc).renderedLines;
 }
 
 /**
@@ -389,3 +308,135 @@ describe("design-system-docs: 検査目録の同期", () => {
         ]);
     });
 });
+
+/* ===== 契約 A / 契約 B の仕様固定 (fixture) =====
+ *
+ * 「規範判定対象外領域の除去」と「字下げの禁止」は本ファイルの検出力そのものなので、
+ * 実文書だけを相手にすると「効いているから緑」なのか「効かなくても緑」なのか区別できない。
+ * 壊れた形・紛らわしい形を `scanMarkdownLines()` へ直接渡して両方向を固定する。
+ */
+
+const BACKTICK = "`";
+const FENCE = BACKTICK.repeat(3);
+
+const indentLines = (lines: readonly string[]): readonly number[] =>
+    scanMarkdownLines(lines.join("\n")).forbiddenIndentLines;
+
+const diagnosticReasons = (lines: readonly string[]): readonly string[] =>
+    scanMarkdownLines(lines.join("\n")).diagnostics.map((d) => d.reason);
+
+describe("design-system-docs: 契約 B — 字下げの禁止 (fixture)", () => {
+    it.each([
+        ["空行の後の 4 空白字下げ行", ["本文", "", "    退避させた規範"]],
+        ["見出しの直後の 4 空白字下げ行", ["## 契約", "", "    退避させた規範"]],
+        ["段落の継続行 (直前が空行でない 4 空白字下げ行)", ["本文", "    継続行"]],
+        ["行頭タブ", ["本文", "\t退避させた規範"]],
+        ["1〜3 空白 + タブ", ["本文", "  \t退避させた規範"]],
+        ["引用の中の字下げ", ["> 本文", ">    退避させた規範"]],
+        ["入れ子の引用の中の字下げ", ["> > 本文", "> >    退避させた規範"]],
+        ["リストの中の字下げ", ["- 本文", "-      退避させた規範"]],
+        ["番号つきリストの別記法", ["1) 本文", "1)     退避させた規範"]],
+        ["行の途中の 4 連続空白", ["本文    退避させた規範"]],
+        ["marker の padding 1", ["- 本文", "      退避させた規範"]],
+        ["marker の padding 4", ["-    本文", "         退避させた規範"]],
+        ["ordered marker 1 桁 + ピリオド", ["1. 本文", "1.     退避させた規範"]],
+        ["ordered marker 9 桁 + 閉じ括弧", ["123456789) 本文", "123456789)     退避"]],
+        ["リストの最初の block が字下げコード", ["-     退避させた規範"]],
+        ["リストの後続 block が字下げコード", ["- 本文", "", "      退避させた規範"]],
+        ["引用とリストの異種入れ子 (引用が外)", ["> - 本文", "> -     退避させた規範"]],
+        ["引用とリストの異種入れ子 (リストが外)", ["- > 本文", "- >    退避させた規範"]],
+    ])("%s を検出する", (_label, lines) => {
+        expect(indentLines(lines).length).toBeGreaterThan(0);
+    });
+
+    it.each([
+        ["lazy continuation は字下げコードではない", ["> 本文", "継続行"]],
+        ["通常の引用本文", ["> 本文"]],
+        ["通常のリスト本文と 2 空白の継続行", ["- 本文", "  継続行"]],
+        ["1〜3 空白の字下げ行", ["本文", "   3 空白は字下げコードではない"]],
+    ])("%s は検出しない (偽陽性を出さない)", (_label, lines) => {
+        expect(indentLines(lines)).toEqual([]);
+    });
+
+    it("囲みコードの中の 4 空白字下げ行とタブは検出しない", () => {
+        expect(indentLines([FENCE, "    字下げしたコード", "\tタブを含むコード", FENCE])).toEqual(
+            [],
+        );
+    });
+});
+
+describe("design-system-docs: 契約 A — 規範判定対象外領域の除去 (fixture)", () => {
+    it.each([
+        ["引用の中の囲みコード記法", ["> " + FENCE, "> 退避させた規範", "> " + FENCE]],
+        ["入れ子の引用の中の囲みコード記法", ["> > " + FENCE, "> > 退避", "> > " + FENCE]],
+        ["リストの中の引用の中の囲みコード記法", ["- > " + FENCE, "- > 退避", "- > " + FENCE]],
+        ["引用の中のリストの中の囲みコード記法", ["> - " + FENCE, "> - 退避", "> - " + FENCE]],
+        ["2 空白 + 引用の囲みコード記法", ["  > " + FENCE, "  > 退避", "  > " + FENCE]],
+        ["行の途中に現れる連続 marker", ["本文 " + FENCE + " 退避"]],
+    ])("%s は container-fence の診断になる", (_label, lines) => {
+        expect(diagnosticReasons(lines)).toContain("container-fence");
+    });
+
+    it("container を伴う fence 候補の中の見出しや規範は通常本文として数えられない", () => {
+        const scan = scanMarkdownLines(["> " + FENCE, "> ### 部品名", "> " + FENCE].join("\n"));
+        expect(scan.diagnostics.length).toBeGreaterThan(0);
+    });
+
+    it("3 個以上の delimiter の行内コード span も診断になる (1〜2 個は診断にならない)", () => {
+        expect(diagnosticReasons(["本文 " + FENCE + "行内" + FENCE + " 本文"]).length).toBeGreaterThan(
+            0,
+        );
+        expect(diagnosticReasons(["本文 " + BACKTICK + "行内" + BACKTICK + " 本文"])).toEqual([]);
+        expect(
+            diagnosticReasons([
+                "本文 " + BACKTICK.repeat(2) + "行内" + BACKTICK.repeat(2) + " 本文",
+            ]),
+        ).toEqual([]);
+    });
+
+    it("正規の top-level fence は診断にならず、中身が落ちる", () => {
+        const scan = scanMarkdownLines([FENCE, "囲みの中", FENCE, "本文"].join("\n"));
+        expect(scan.diagnostics).toEqual([]);
+        expect(scan.renderedLines.join("\n")).not.toContain("囲みの中");
+        expect(scan.renderedLines.join("\n")).toContain("本文");
+        expect(scan.renderedLines.length).toBe(4);
+    });
+
+    it("受理範囲外の fence 記法と未終端の fence が診断になる", () => {
+        // 開始より短い終了 marker では閉じない → EOF まで開いたまま
+        expect(diagnosticReasons([BACKTICK.repeat(4), "中身", FENCE])).toEqual([
+            "unterminated-fence",
+        ]);
+        // 種類の違う終了 marker でも閉じない
+        expect(diagnosticReasons([FENCE, "中身", "~~~"])).toEqual(["unterminated-fence"]);
+        // backtick 型で情報文字列にバッククォートを含む行は開始 fence にならず診断になる
+        expect(diagnosticReasons([FENCE + "info" + BACKTICK + "string", "本文"])).toEqual([
+            "unsupported-fence",
+        ]);
+        // EOF まで閉じない fence
+        expect(diagnosticReasons([FENCE, "中身"])).toEqual(["unterminated-fence"]);
+    });
+
+    it("未終端の HTML コメントが診断になる", () => {
+        expect(diagnosticReasons(["<!-- 閉じないコメント", "ここも隠れる"])).toEqual([
+            "unterminated-html-comment",
+        ]);
+    });
+});
+
+describe("design-system-docs: 実文書の行分類", () => {
+    const source = fs.readFileSync(DOC_PATH, "utf-8");
+
+    it("囲みコードの外にタブと 4 連続空白が無い", () => {
+        const scan = scanMarkdownLines(source);
+        expect(scan.renderedLines.length, "行が 1 行も取れない (走査の空振り)").toBeGreaterThan(0);
+        expect(
+            scan.forbiddenIndentLines,
+            "囲みコードの外に字下げがある。字下げによるコードは書かず、囲みコード記法を使うこと",
+        ).toEqual([]);
+    });
+
+    it("Markdown 走査の診断が 0 件である (本 gate が docs 側の診断の消費先である)", () => {
+        expect(scanMarkdownLines(source).diagnostics).toEqual([]);
+    });
+});
diff --git a/tests/js/styles/inventory.ts b/tests/js/styles/inventory.ts
index 22784fe9..4741b080 100644
--- a/tests/js/styles/inventory.ts
+++ b/tests/js/styles/inventory.ts
@@ -37,68 +37,130 @@ export const TYPOGRAPHY_RAMPS = ["display", "h1", "h2", "h3", "body", "caption"]
 /*
  * ===== コントラスト検査の役割宣言 (contrast-invariant.test.ts の入力) =====
  *
- * DESIGN.md の全色トークンは下の 5 分類の**いずれかに必ず属する** (deny-by-default)。
- * 未分類のトークンがあれば contrast-invariant が fail する = 新トークンが
+ * DESIGN.md の全色トークンは `COLOR_TOKEN_ROLES` に**必ず 1 つ以上の役割で登録される**
+ * (deny-by-default)。未分類のトークンがあれば contrast-invariant が fail する = 新トークンが
  * 黙って gate をすり抜けられない。
+ *
+ * ここは **DESIGN.md の色キー空間**である (`text-primary` = 本文色)。
+ * tokens.css の `--color-<suffix>` 空間とは別で、境界は `COLOR_TOKEN_MAP` の 1 本だけである。
+ */
+
+/**
+ * 色 token の役割。**1 つの token が複数の役割を持ちうる** (思考原則 4: 別物の用途を統合しない)。
+ *
+ * 役割の全数性は本表のキーと DESIGN.md の色キーの集合一致だけで見る
+ * (個別宣言ペアに現れた token を「分類済み」と数えると、任意の新 token を 1 組登録するだけで
+ * 既定拒否を通せてしまう)。
  */
+export type ColorRole =
+    /** 面 = 容器の背景。**半透明の合成の下地でもある** (正典 i16) */
+    | "surface"
+    /** 面の上に載るテキスト色 */
+    | "text-on-surface"
+    /** 塗り面 (solid fill) */
+    | "fill"
+    /** 塗り面の上に載るラベル色 */
+    | "fill-label"
+    /** 直積で表現できない、テキストを載せる塗り (個別宣言ペアの背景側にだけ現れる) */
+    | "declared-text-background"
+    /** 1px 境界・focus ring 等。WCAG 1.4.11 の別の閾値体系なので本 gate の対象外 (正典 i17。理由必須) */
+    | "non-text-boundary";
+
+/**
+ * **役割分類の唯一の宣言**。下の 4 つの配列は**ここから導出する** (正典 i4: 母集団を固定配列に書かない)。
+ */
+export const COLOR_TOKEN_ROLES = {
+    "primary": ["text-on-surface", "fill"],
+    "primary-hover": ["fill"],
+    "tertiary": ["text-on-surface", "fill"],
+    "tertiary-hover": ["fill"],
+    "neutral": ["surface", "fill-label"],
+    "surface": ["surface", "fill-label"],
+    // 2 役割を持つ: 1px 枠 (対象外) と、Button の neutral variant の hover 塗り (検査する)
+    "border": ["non-text-boundary", "declared-text-background"],
+    "border-strong": ["non-text-boundary"],
+    "text-primary": ["text-on-surface"],
+    "text-secondary": ["text-on-surface"],
+    "success": ["text-on-surface", "fill"],
+    "warning": ["text-on-surface", "fill"],
+    "danger": ["text-on-surface", "fill"],
+} as const satisfies Readonly<Record<string, readonly ColorRole[]>>;
+
+/** ある役割を持つ token を宣言順で返す。 */
+export function tokensWithRole(role: ColorRole): readonly string[] {
+    return Object.entries(COLOR_TOKEN_ROLES)
+        .filter(([, roles]) => (roles as readonly ColorRole[]).includes(role))
+        .map(([token]) => token);
+}
+
+/** ある token の役割を返す (逆写像の起点)。 */
+export function rolesOf(token: string): readonly ColorRole[] {
+    const roles = (COLOR_TOKEN_ROLES as Readonly<Record<string, readonly ColorRole[]>>)[token];
+    if (roles === undefined) throw new Error(`COLOR_TOKEN_ROLES に ${token} が無い`);
+
+    return roles;
+}
 
 /** 面 (背景) として塗るトークン。DESIGN.md §Colors: neutral=画面全体 / surface=カード・モーダル */
-export const SURFACE_ROLE_TOKENS = ["neutral", "surface"] as const;
+export const SURFACE_ROLE_TOKENS: readonly string[] = tokensWithRole("surface");
 
 /** 面の上に載るテキスト色 (本文・見出し・意味を担う状態テキスト) */
-export const TEXT_ON_SURFACE_TOKENS = [
-    "text-primary",
-    "text-secondary",
-    "primary", // リンク / TextLink
-    "tertiary",
-    "success",
-    "warning",
-    "danger", // Alert 見出し / Button danger-ghost のラベル
-] as const;
+export const TEXT_ON_SURFACE_TOKENS: readonly string[] = tokensWithRole("text-on-surface");
 
 /** 塗り面 (solid fill) として使うトークン。DESIGN.md §Components Button の bg-* */
-export const FILL_TOKENS = [
-    "primary",
-    "primary-hover",
-    "tertiary",
-    "tertiary-hover",
-    "success",
-    "warning",
-    "danger",
-] as const;
+export const FILL_TOKENS: readonly string[] = tokensWithRole("fill");
 
-/** 塗り面の上に載るラベル色。DESIGN.md §Components: `bg-* + text-neutral` */
-export const FILL_LABEL_TOKENS = ["neutral"] as const;
+/** 塗り面の上に載るラベル色。DESIGN.md §Components: `bg-* + text-neutral` / `text-surface` */
+export const FILL_LABEL_TOKENS: readonly string[] = tokensWithRole("fill-label");
 
 /**
- * コントラスト検査の対象外トークン (理由必須)。
- * 「検査していない」ことを見えるようにするための明示宣言であり、免罪符ではない。
+ * `non-text-boundary` の役割を持つ token の理由 (理由必須。正典 i17)。
+ *
+ * キー集合が `tokensWithRole("non-text-boundary")` と一致することを機械で見る
+ * (理由だけ残る / 役割だけ足す のどちらも落とす)。
+ * **「この token は一切検査しない」という意味ではない** — `border` は
+ * `declared-text-background` の役割も持つので、その用途は個別宣言ペアで検査される。
  */
-export const CONTRAST_EXEMPT_TOKENS = {
+export const NON_TEXT_BOUNDARY_REASONS = {
     "border":
-        "1px の区切り線・入力欄の枠。テキストではなく WCAG 1.4.11 (非テキスト 3:1) の領域。" +
-        "装飾的な境界線は 1.4.11 の適用除外のため、使用箇所ごとの役割分類が要る (v1 スコープ外)",
+        "1px の区切り線・入力欄の枠としての用途。WCAG 1.4.11 (非テキスト 3:1) の別の閾値体系で、" +
+        "装飾的な境界線は 1.4.11 の適用除外にあたるため、使用箇所ごとの役割分類が要る " +
+        "(家系の未決論点 q2 の担当)。**テキストを載せる塗りとしての用途は別の役割で検査する**",
     "border-strong":
-        "区切りの強調・ghost ボタンの枠。ghost ボタンの枠は機能的境界の可能性があり、" +
-        "実測 2.56 で 3:1 に届かない。値の是正は『どの border が機能的境界か』の" +
-        "役割モデルを DESIGN.md に定めてから別バッチで行う (申し送り 5-3)",
+        "3 つの用途がいずれも本 gate の対象外である — (1) 1px の区切り線・入力欄の枠 " +
+        "(WCAG 1.4.11 の非テキスト 3:1 で別の閾値体系。役割モデルが未定のため家系の未決論点 q2 の担当)、" +
+        "(2) Toggle のトラック (テキストを載せない塗り)、" +
+        "(3) 無効化したタブのラベル (SC 1.4.3 は無効化された UI 部品を適用除外にしている)。" +
+        "実測 2.56 で 3:1 に届かないので、値の是正は 1.4.11 の役割モデルを DESIGN.md に" +
+        "定めてから別バッチで行う",
 } as const;
 
+/** 役割の直積で表現できない正当な 1 対 1 の組 (理由必須。正典 i14)。 */
+export interface DeclaredPair {
+    readonly fg: string;
+    readonly bg: string;
+    readonly reason: string;
+}
+
 /**
- * 未検査であることを明示する pending 集合 (v1 スコープ外)。
- * contrast-invariant はこれらを検査しない — 「gate があるからコントラストは守られている」
- * という誤読を作らないための宣言。
+ * 直積で表現できない正当な 1 対 1 の組。**直積と同じ閾値 (4.5:1) を課す**。
  *
- * **出口**: pending 項目に対応したらその行を削る。全部消えたら
- * 本 export と contrast-invariant.test.ts の
- * 「未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない」テストを**同時に削除**すること
- * (空の宣言と、空でないことを確かめるテストを残すと形骸化する)。
+ * キーは **DESIGN.md の色キー空間**である。走査器が返す CSS suffix 空間とは別なので、
+ * 突き合わせは `COLOR_TOKEN_MAP` の逆写像で行う。
+ * **役割分類の既定拒否をここで迂回できない** — 本表に現れた token を「分類済み」と数えるのはやめ、
+ * 分類の全数性は `COLOR_TOKEN_ROLES` だけで見る。本表には別の 5 条を課す。
  */
-export const PENDING_CONTRAST_PAIRS = [
-    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring",
-    "alpha 合成ペア: Badge の bg-<tone>/10 + text-<tone>、bg-primary-soft、ring-primary/35、" +
-        "bg-text/70 + text-surface (合成後の実効色が親背景に依存しトークン単体では定まらない)",
-] as const;
+export const DECLARED_CONTRAST_PAIRS = [
+    {
+        fg: "text-primary",
+        bg: "border",
+        reason:
+            "Button の neutral variant の hover (hover:bg-border + text-text)。" +
+            "border を塗り面の役割へ入れると直積に neutral on border (1.15) と " +
+            "surface on border (1.27) が生まれるが、この 2 組は実装に 1 件も無い。" +
+            "border の 1px 枠としての用途は WCAG 1.4.11 (別の閾値体系) で本 gate の対象外である",
+    },
+] as const satisfies readonly DeclaredPair[];
 
 /*
  * ===== 生成 CSS 検査の入力 (tokens.test.ts) =====
@@ -193,3 +255,392 @@ export const FRONTMATTER_SECTION_OWNERS: Readonly<Record<string, FrontmatterSect
         tracking: "devnotes/20260818-0248-design-token-t1-tests/",
     },
 };
+
+/*
+ * ===== 実装からの逆向き被覆 (i15) / 参照の閉包 (i9) の入力 =====
+ */
+
+/**
+ * 静的に組を決められない理由の**正本 (実行時の配列)**。
+ *
+ * 型は本配列の要素型から導出する — union 型は実行時に列挙できないので、
+ * 「各 reason を発火させる検体が 1 つ以上ある」という網羅の検査そのものが書けない。
+ * fixture の網羅・表示ラベル・`PENDING_CONTRAST_PAIRS` の説明は**すべてこの配列から導出する**。
+ *
+ * `double-alpha` は**値域に無い**。alpha を値に持つ token への修飾は実効 alpha が
+ * `token の alpha × 修飾の alpha` に確定する (tokens.test.ts の H が生成形を固定する) ので、
+ * **静的に決められる形**であり例外へ逃がすのは正典 i16 に反する。合成対象として計算する。
+ */
+export const UNDECIDABLE_REASONS = [
+    { id: "foreground-alpha", label: "前景の alpha" },
+    { id: "keyword-color", label: "色キーワードと /0 (透明)" },
+    { id: "alpha-background-no-text", label: "前景を持たない alpha 背景" },
+    { id: "opaque-and-alpha-background", label: "塗り面と alpha 背景の同居" },
+    { id: "multiple-background", label: "背景の多重宣言" },
+    { id: "multiple-foreground", label: "前景の多重宣言" },
+    { id: "element-opacity", label: "要素全体の不透明度" },
+    { id: "interpolated", label: "補間" },
+    { id: "variant-composition", label: "variant 列の合成" },
+] as const;
+
+/** 判定不能の理由 (値域の正本は `UNDECIDABLE_REASONS`)。 */
+export type UndecidableReason = (typeof UNDECIDABLE_REASONS)[number]["id"];
+
+/**
+ * **token を指さない語**の契約表 (正典 i9)。
+ *
+ * これは許可一覧ではなく**検査対象の定義**である。テーマの名前空間の接頭辞を持つ語のうち、
+ * 写像の宣言集合へ解決しないものは**全数がここに登録されていなければ不合格**になる。
+ * Tailwind 既定テーマの色語 (`white` / `black` / raw palette) は**登録しない** —
+ * 写像の外の token 空間を参照する形なので落とすのが正しい。
+ *
+ * **チャネルを型で分ける**。class の語と `var()` 参照を同じ無型の表へ入れると、
+ * **別のチャネルでの出現によって登録が生きているように見える**。
+ * 出現の突き合わせと冗長判定は**チャネル別**に行う。
+ *
+ * 登録するのは**正規化後の有効な完全 token** である。`text-center/50` のような
+ * 「色でない utility に不透明度修飾が付いた形」は走査器が
+ * `unresolved: "alpha-on-non-color"` にするので、**契約表に登録しても救われない**。
+ */
+export type NonTokenWord =
+    | { readonly kind: "class-word"; readonly word: string; readonly reason: string }
+    | { readonly kind: "css-variable"; readonly name: string; readonly reason: string };
+
+export const NON_TOKEN_WORD_CONTRACT = [
+    {
+        kind: "class-word",
+        word: "bg-transparent",
+        reason: "CSS の全域キーワード。色 token を指さない",
+    },
+    {
+        kind: "class-word",
+        word: "border-transparent",
+        reason: "同上。全 variant で外形高さを揃えるための透明枠 (DESIGN.md §Components)",
+    },
+    { kind: "class-word", word: "border-2", reason: "境界の太さ。色ではない" },
+    { kind: "class-word", word: "border-b", reason: "境界の辺の指定。色ではない" },
+    { kind: "class-word", word: "border-b-0", reason: "同上 (打ち消し)" },
+    { kind: "class-word", word: "border-b-2", reason: "同上 (太さつき)" },
+    { kind: "class-word", word: "border-l-2", reason: "同上" },
+    { kind: "class-word", word: "border-r", reason: "同上" },
+    { kind: "class-word", word: "border-t", reason: "同上" },
+    { kind: "class-word", word: "border-dashed", reason: "境界の線種。色ではない" },
+    {
+        kind: "class-word",
+        word: "divide-y",
+        reason: "区切り線の軸。色ではない (色は divide-border が持つ)",
+    },
+    { kind: "class-word", word: "outline-none", reason: "outline の打ち消し。色ではない" },
+    { kind: "class-word", word: "ring-2", reason: "focus ring の太さ。色ではない" },
+    { kind: "class-word", word: "ring-3", reason: "同上" },
+    {
+        kind: "class-word",
+        word: "rounded-full",
+        reason:
+            "角丸 ramp の外の真円 UI。radius token を指さず ds-purity の file-scoped allowlist が管轄する",
+    },
+    {
+        kind: "class-word",
+        word: "stroke-current",
+        reason: "CSS の currentColor キーワード。前景色を引き継ぐ指定で色 token を指さない",
+    },
+    { kind: "class-word", word: "text-center", reason: "テキストの整列。色でも ramp でもない" },
+    { kind: "class-word", word: "text-left", reason: "同上" },
+    { kind: "class-word", word: "text-right", reason: "同上" },
+    {
+        kind: "css-variable",
+        name: "--app-sidebar-w",
+        reason:
+            "同一要素の style 属性で宣言する局所変数。@theme の token ではない " +
+            "(他ファイルのローカル宣言を解決の根拠に数えない)",
+    },
+] as const satisfies readonly NonTokenWord[];
+
+/**
+ * `resources/js` 直下の子の全数分類 (新しい直下の子が現れたら不合格)。
+ *
+ * `requiresOccurrences: true` の子だけが 0 でないことを gate が固定する。
+ * **要求しない子に 0 件を強いない** — 0 件が正常なので、要求すると正常な状態を赤にする。
+ */
+export const JS_SCAN_CHILD_CLASSIFICATION = {
+    "components": { requiresOccurrences: true },
+    "pages": { requiresOccurrences: true },
+    "lib": {
+        requiresOccurrences: false,
+        reason:
+            "DOM を直に組み立てる bfcache 秘匿オーバーレイが ramp を使うだけで、" +
+            "色の組を持たない (0 件が正常な状態なので非空を要求しない)",
+    },
+    "types": { requiresOccurrences: false, reason: "型定義のみで class 文字列を持たない" },
+    "(直下のファイル)": {
+        requiresOccurrences: false,
+        reason: "実測 0 件。起動と型宣言だけを持つ",
+    },
+} as const;
+
+/*
+ * ===== 半透明背景 × 不透明文字の合成検査の入力 (正典 i16) =====
+ *
+ * ここは**2 つのキー空間**のうち **tokens.css の `--color-<suffix>` 空間**である。
+ * 役割分類 (COLOR_TOKEN_ROLES など) は **DESIGN.md の色キー空間**で、
+ * `text-primary` = 本文色という別の意味を持つ。境界は COLOR_TOKEN_MAP の 1 本だけである。
+ * 派生トークン `primary-soft` は DESIGN.md に無いので、半透明の台帳は suffix 空間で
+ * 書かなければ表現できない (これが空間を分ける実質的な理由である)。
+ */
+
+type CanonicalColorSuffix = (typeof COLOR_TOKEN_MAP)[keyof typeof COLOR_TOKEN_MAP];
+type DerivedColorSuffix = (typeof DERIVED_COLOR_TOKENS)[number];
+
+/** tokens.css の `--color-<suffix>` の suffix (literal union。取り違えが型で落ちる)。 */
+export type CssColorSuffix = CanonicalColorSuffix | DerivedColorSuffix;
+
+/**
+ * 半透明の背景 × 不透明な文字の 1 組。
+ *
+ * **台帳は実効値を持たない** — 持つのは **class 修飾の百分率だけ**で、
+ * token 固有 alpha と合成して実効値を作るのは `resolveAlphaBackground()` **1 か所だけ**である。
+ */
+export interface AlphaPair {
+    readonly fg: CssColorSuffix;
+    readonly bg: CssColorSuffix;
+    /** class 修飾の百分率 (0..100)。`bg-primary-soft` のような修飾なしは `null` */
+    readonly modifierPercent: number | null;
+}
+
+/** 使用箇所の全数台帳の 1 行 (正典 i16 の「全件が台帳に載ることを件数まで」)。 */
+export interface AlphaPairUsage extends AlphaPair {
+    /** リポジトリ相対パス。**行番号は持たない** (正典 s14) */
+    readonly file: string;
+    /** そのファイルでの出現数 (完全一致で固定する) */
+    readonly count: number;
+}
+
+/** 判定不能の単位の台帳の 1 行。 */
+export interface UndecidableEntry {
+    readonly file: string;
+    readonly reason: UndecidableReason;
+    readonly count: number;
+    readonly note: string;
+}
+
+/**
+ * 半透明の背景 × 不透明な文字の組の**使用箇所の全数台帳** (正典 i16)。
+ *
+ * **走査で見つかった半透明の組は全件がここに載る**ことを contrast-invariant が
+ * (ファイル, 組, 修飾, 件数) の完全一致で固定する (件数だけの pin にしない =
+ * 新しい使用を件数更新で通せない)。
+ * **下地は宣言しない** — 実在する不透明な下地 = 役割分類の「面」(`SURFACE_ROLE_TOKENS`) の
+ * **すべて**の上で 4.5:1 を要求するので、部品がどちらに置かれても成立する。
+ * **行番号は持たない** (正典 s14)。ファイル単位までである。
+ */
+export const ALPHA_PAIR_USAGE_LEDGER = [
+    { file: "resources/js/components/atoms/Badge.types.ts", fg: "danger", bg: "danger", modifierPercent: 10, count: 1 },
+    { file: "resources/js/components/atoms/Badge.types.ts", fg: "primary", bg: "primary-soft", modifierPercent: null, count: 1 },
+    { file: "resources/js/components/atoms/Badge.types.ts", fg: "success", bg: "success", modifierPercent: 10, count: 1 },
+    { file: "resources/js/components/atoms/Badge.types.ts", fg: "tertiary", bg: "tertiary", modifierPercent: 10, count: 1 },
+    { file: "resources/js/components/atoms/Badge.types.ts", fg: "warning", bg: "warning", modifierPercent: 10, count: 1 },
+    { file: "resources/js/components/atoms/Button.types.ts", fg: "danger", bg: "danger", modifierPercent: 10, count: 1 },
+    { file: "resources/js/components/features/capture/CameraRecorder.svelte", fg: "surface", bg: "text", modifierPercent: 70, count: 1 },
+    { file: "resources/js/components/features/capture/ScenarioPreviewDialog.svelte", fg: "text", bg: "surface", modifierPercent: 80, count: 1 },
+    { file: "resources/js/components/features/capture/ScenarioPreviewDialog.svelte", fg: "text-secondary", bg: "surface", modifierPercent: 80, count: 1 },
+    { file: "resources/js/components/features/capture/ShootingGuideOverlay.svelte", fg: "surface", bg: "text", modifierPercent: 70, count: 1 },
+    { file: "resources/js/components/features/capture/TakePreviewDialog.svelte", fg: "text", bg: "surface", modifierPercent: 80, count: 1 },
+    { file: "resources/js/components/features/capture/TakePreviewDialog.svelte", fg: "text-secondary", bg: "surface", modifierPercent: 80, count: 1 },
+    { file: "resources/js/components/features/invitations/PendingInvitationList.svelte", fg: "primary", bg: "primary-soft", modifierPercent: null, count: 1 },
+    { file: "resources/js/components/features/notifications/NotificationListItem.svelte", fg: "primary", bg: "primary-soft", modifierPercent: null, count: 1 },
+    { file: "resources/js/components/molecules/PendingInvitationsNotice.svelte", fg: "text", bg: "primary-soft", modifierPercent: 40, count: 1 },
+    { file: "resources/js/components/molecules/PendingInvitationsNotice.svelte", fg: "text", bg: "primary-soft", modifierPercent: null, count: 1 },
+    { file: "resources/js/components/molecules/PricingPlanCard.svelte", fg: "text", bg: "warning", modifierPercent: 10, count: 1 },
+    { file: "resources/js/components/molecules/SubtitleOverlay.svelte", fg: "surface", bg: "text", modifierPercent: 70, count: 2 },
+    { file: "resources/js/components/templates/_helpers/SidebarUserMenu.svelte", fg: "danger", bg: "danger", modifierPercent: 10, count: 1 },
+    { file: "resources/js/pages/Guest/Pricing.svelte", fg: "text", bg: "primary-soft", modifierPercent: null, count: 1 },
+    { file: "resources/js/pages/Onboarding/Checkout.svelte", fg: "primary", bg: "primary", modifierPercent: 10, count: 1 },
+    { file: "resources/js/pages/Organizations/Sso/Index.svelte", fg: "text", bg: "danger", modifierPercent: 10, count: 1 },
+    { file: "resources/js/pages/Welcome.svelte", fg: "primary", bg: "primary-soft", modifierPercent: null, count: 6 },
+    { file: "resources/js/pages/Welcome.svelte", fg: "success", bg: "success", modifierPercent: 10, count: 1 },
+    { file: "resources/js/pages/Welcome.svelte", fg: "text", bg: "primary-soft", modifierPercent: null, count: 1 },
+] as const satisfies readonly AlphaPairUsage[];
+
+/**
+ * 使用箇所台帳を `(fg, bg, modifierPercent)` へ射影した一意な意味ペア。
+ *
+ * AA の `it.each` はこちらを回す (同じ意味ペアを何度検査しても情報は増えない)。
+ * 「射影が一致する」という it は置かない — 導出しているので恒真に近く、
+ * 共通規約 (d) の形骸化に当たる。代わりに**導出関数 `distinctPairs()` の仕様**を
+ * 固定検体で固定する。
+ */
+export function distinctPairs(ledger: readonly AlphaPair[]): readonly AlphaPair[] {
+    const byKey = new Map<string, AlphaPair>();
+    for (const row of ledger) {
+        byKey.set(`${row.fg}|${row.bg}|${row.modifierPercent ?? "-"}`, {
+            fg: row.fg,
+            bg: row.bg,
+            modifierPercent: row.modifierPercent,
+        });
+    }
+
+    return [...byKey.entries()].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)).map(([, v]) => v);
+}
+
+export const ALPHA_CONTRAST_PAIRS: readonly AlphaPair[] = distinctPairs(ALPHA_PAIR_USAGE_LEDGER);
+
+/**
+ * 静的に組を決められなかった単位の台帳 (正典 i16「例外にして静かに素通りさせない」)。
+ *
+ * 識別子は **(ファイル, 理由, 件数) の完全一致**である ((ファイル, 理由) だけだと、
+ * 同じファイルに同じ理由の未解析箇所が**増えても集合が変わらず**追加を検出できない)。
+ * **行番号は持たない** (正典 s14: 無関係な 1 行の追加でずれ、期待値の機械的な更新が
+ * 常態化して統制が形骸化する)。
+ *
+ * 不透明のみの不完全な単位 (前景か背景の片方しか無い) は**ここに載せない** —
+ * 実体集合で pin すると期待値の機械的な更新が常態化する。そちらは「分類の全数性」を
+ * 固定検体で受け、組そのものは正典 i14 の役割直積が覆う。
+ */
+export const UNDECIDABLE_PAIR_LEDGER = [
+    { file: "resources/js/components/atoms/Alert.svelte", reason: "variant-composition", count: 1, note: "閉じるボタンの hover と focus-visible が別の変種列で同居する" },
+    { file: "resources/js/components/atoms/Button.types.ts", reason: "element-opacity", count: 2, note: "success / danger の hover:opacity-90 (要素全体の不透明度)" },
+    { file: "resources/js/components/atoms/Button.types.ts", reason: "keyword-color", count: 2, note: "ghost / danger-ghost の bg-transparent。背景は親から来る" },
+    { file: "resources/js/components/atoms/input-state.ts", reason: "foreground-alpha", count: 1, note: "placeholder:text-text-secondary/70 (前景に不透明度修飾)" },
+    { file: "resources/js/components/atoms/input-state.ts", reason: "interpolated", count: 2, note: "完成した class 文字列を補間で差し込む (readonly / 通常の 2 分岐)" },
+    { file: "resources/js/components/features/capture/CameraRecorder.svelte", reason: "variant-composition", count: 5, note: "撮影コントロールの hover と focus-visible が別の変種列で同居する" },
+    { file: "resources/js/components/features/capture/CutSwipeBar.svelte", reason: "alpha-background-no-text", count: 1, note: "スワイプ帯の半透明背景 (前景は別のリテラル)" },
+    { file: "resources/js/components/features/capture/GridOverlay.svelte", reason: "alpha-background-no-text", count: 4, note: "構図ガイドの罫線 (bg-surface/40。文字を載せない)" },
+    { file: "resources/js/components/features/capture/ScenarioPreviewDialog.svelte", reason: "alpha-background-no-text", count: 1, note: "プレビュー枠の下地 (bg-text/5。文字を載せない)" },
+    { file: "resources/js/components/features/capture/TakePreviewDialog.svelte", reason: "alpha-background-no-text", count: 1, note: "プレビュー枠の下地 (bg-text/5。文字を載せない)" },
+    { file: "resources/js/components/features/manual/TakePickerList.svelte", reason: "alpha-background-no-text", count: 1, note: "サムネイル枠の下地 (文字を載せない)" },
+    { file: "resources/js/components/features/manual/TakePreviewPanel.svelte", reason: "alpha-background-no-text", count: 1, note: "プレビュー枠の下地 (bg-text/5。文字を載せない)" },
+    { file: "resources/js/components/features/notifications/NotificationListItem.svelte", reason: "alpha-background-no-text", count: 1, note: "未読行の bg-primary-soft/40 だけを持つリテラル (前景は別のリテラル)" },
+    { file: "resources/js/components/molecules/DangerZone.svelte", reason: "alpha-background-no-text", count: 1, note: "危険操作枠の下地 (bg-danger/5。文字は子要素が持つ)" },
+    { file: "resources/js/components/molecules/Pagination.svelte", reason: "variant-composition", count: 1, note: "ページ送りボタンの hover と focus-visible が別の変種列で同居する" },
+    { file: "resources/js/components/molecules/PasswordInput.svelte", reason: "variant-composition", count: 1, note: "表示切替ボタンの hover と focus-visible が別の変種列で同居する" },
+    { file: "resources/js/components/molecules/StatCard.svelte", reason: "alpha-background-no-text", count: 1, note: "アイコン帯の半透明背景 (文字を載せない)" },
+    { file: "resources/js/components/organisms/Modal.svelte", reason: "alpha-background-no-text", count: 1, note: "オーバーレイの bg-text/50 (文字を載せない)" },
+    { file: "resources/js/components/organisms/Modal.svelte", reason: "variant-composition", count: 1, note: "閉じるボタンの hover と focus-visible が別の変種列で同居する" },
+    { file: "resources/js/components/organisms/ToastContainer.svelte", reason: "variant-composition", count: 1, note: "閉じるボタンの hover と focus-visible が別の変種列で同居する" },
+    { file: "resources/js/components/templates/AppLayout.svelte", reason: "alpha-background-no-text", count: 1, note: "サイドバーの背後を覆うオーバーレイ (bg-text/50。文字を載せない)" },
+    { file: "resources/js/pages/Debug/Login.svelte", reason: "variant-composition", count: 1, note: "開発用ログインボタンの hover と focus-visible が別の変種列で同居する" },
+    { file: "resources/js/pages/Guest/Pricing.svelte", reason: "alpha-background-no-text", count: 1, note: "強調カードの帯の半透明背景 (文字は子要素が持つ)" },
+    { file: "resources/js/pages/Organizations/ApiKeys/Index.svelte", reason: "alpha-background-no-text", count: 1, note: "キー表示欄の下地 (文字は子要素が持つ)" },
+] as const satisfies readonly UndecidableEntry[];
+
+/**
+ * 未検査であることを明示する pending 集合。**i16 の完了後も空にならない**。
+ *
+ * contrast-invariant はこれらを検査しない — 「gate があるからコントラストは守られている」
+ * という誤読を作らないための宣言。
+ *
+ * 列挙は `UNDECIDABLE_REASONS` (実行時の配列) から**生成する** (散文で数を書かない。
+ * 分類を足したのに pending の説明が古いまま、という食い違いを作らない)。
+ *
+ * **出口**: pending 項目に対応したらその行を削る。全部消えたら
+ * 本 export と contrast-invariant.test.ts の
+ * 「未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない」テストを**同時に削除**すること
+ * (空の宣言と、空でないことを確かめるテストを残すと形骸化する)。
+ */
+export const PENDING_CONTRAST_PAIRS = [
+    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring " +
+        "(正典 i17 により本 gate の対象外)",
+    `UNDECIDABLE_PAIR_LEDGER に載せた分類: ${UNDECIDABLE_REASONS.map((r) => r.label).join(" / ")}。` +
+        "値域の正本は UNDECIDABLE_REASONS で、分類の全数性は contrast-invariant の it が " +
+        "never への収束と「各 reason を発火させる検体が 1 つ以上ある」ことで固定する",
+] as const;
+
+/*
+ * ===== DESIGN.md §Components ⇔ 部品ファイルの双方向一致の入力 (正典 i10) =====
+ */
+
+export type ComponentDirKind = "documented" | "excluded";
+
+export interface ComponentDirSpec {
+    readonly kind: ComponentDirKind;
+    /** `excluded` は理由必須 (どこが担当するかを見えるようにする) */
+    readonly reason?: string;
+}
+
+export type ComponentDirClassification = Readonly<Record<string, ComponentDirSpec>>;
+
+/**
+ * §Components の対象にするサブディレクトリの全数分類 (既定拒否)。
+ * キーは `resources/js/components` からの相対パスである。
+ *
+ * `excluded` の配下は再帰を止めるので、そこに入れ子のキーを登録しても
+ * **判定に使われない死んだ登録**になる (使われなかった登録は gate が落とす)。
+ */
+export const COMPONENT_DIR_CLASSIFICATION = {
+    "atoms": { kind: "documented" },
+    "molecules": { kind: "documented" },
+    "organisms": { kind: "documented" },
+    "templates": {
+        kind: "excluded",
+        reason:
+            "レイアウトの骨格。使い分けは DESIGN.md §Layout と page-shell-structure.test.ts が担当する",
+    },
+    "features": {
+        kind: "excluded",
+        reason: "ドメイン部品。使い分けは各 feature の設計が決め、DS の再利用部品カタログではない",
+    },
+    "atoms/icons": {
+        kind: "excluded",
+        reason:
+            "Lucide に無いブランド/SSO ロゴの SVG 内包専用。svg-inline-allowlist.test.ts が担当する",
+    },
+} as const satisfies ComponentDirClassification;
+
+export interface ComponentFileKindSpec {
+    readonly kind: "component" | "types" | "helper";
+    readonly requiresSection: boolean;
+    readonly reason?: string;
+}
+
+export type ComponentFileKinds = Readonly<Record<string, ComponentFileKindSpec>>;
+
+/**
+ * 対象ディレクトリ直下のファイル種別の全数分類 (既定拒否)。
+ *
+ * 照合は**最長接尾辞一致**である (`.types.ts` は `.ts` の接尾辞でもあり、
+ * 照合順が未定義だと `Button.types.ts` が helper へ誤分類されうる)。
+ *
+ * `.gitkeep` は**登録しない** — 実在するのは `atoms/icons` の 1 件だけで、
+ * そこは `excluded` として再帰を止めるため判定に到達せず、登録すると死んだ登録になる。
+ * 対象ディレクトリの直下に置かれたら未分類として赤くなり、そのとき分類を書けばよい。
+ */
+export const COMPONENT_FILE_KINDS = {
+    ".svelte": { kind: "component", requiresSection: true },
+    ".types.ts": {
+        kind: "types",
+        requiresSection: false,
+        reason: "型と variant 表。同名の *.svelte が対になっていることを検査する",
+    },
+    ".ts": {
+        kind: "helper",
+        requiresSection: false,
+        reason: "共有 helper。現状 1 件 = atoms/input-state.ts (入力系 atom の共通スタイル定義)",
+    },
+} as const satisfies ComponentFileKinds;
+
+export interface ComponentSectionMapping {
+    readonly section: string;
+    readonly files: readonly string[];
+    readonly reason: string;
+}
+
+export type ComponentSectionMappings = readonly ComponentSectionMapping[];
+
+/** 既定の対応 (節名 = ファイル名) に乗らない対応の申告 (理由必須。正典 i10)。 */
+export const COMPONENT_SECTION_MAPPINGS = [
+    {
+        section: "Input / Textarea / Select(入力系 atom)",
+        files: ["atoms/Input.svelte", "atoms/Textarea.svelte", "atoms/Select.svelte"],
+        reason: "3 つの入力 atom は同じ枠・同じ状態表現を共有するため 1 節で意味論を定義している",
+    },
+    {
+        section: "Toast",
+        files: ["organisms/ToastContainer.svelte"],
+        reason: "節名は利用者から見た概念 (Toast)、実装は容器 1 本 (ToastContainer)",
+    },
+    {
+        section: "PageHeader / PageHeaderSection",
+        files: ["molecules/PageHeader.svelte", "molecules/PageHeaderSection.svelte"],
+        reason: "ページ見出しと節見出しは対で使うため 1 節で使い分けを定義している",
+    },
+] as const satisfies ComponentSectionMappings;
diff --git a/tests/js/styles/markdown-lines.ts b/tests/js/styles/markdown-lines.ts
new file mode 100644
index 00000000..3cb3edaa
--- /dev/null
+++ b/tests/js/styles/markdown-lines.ts
@@ -0,0 +1,184 @@
+/**
+ * Markdown の**行の分類**を 1 実装へ集約する (正典 i21) — 検査テスト共有。
+ *
+ * `design-system-docs.test.ts` (docs/design-system.md の構造) と
+ * `design-md.ts` の `parseDesignComponentSections()` (DESIGN.md §Components の節) が
+ * **同じ実装**を使う。同じ Markdown に 2 本の字句走査ができると弱い方が緑を作る。
+ *
+ * **契約は 2 つあり、混ぜない**。
+ *
+ * 【契約 A — 規範判定対象外領域の除去】
+ *   呼称は「非描画領域」ではなく**「規範判定対象外領域」**である —
+ *   **HTML コメントは読者に描画されない**が、**囲みコードは描画される**。
+ *   どちらも「規範の本文として数えない」点だけが共通である。
+ *   落とすのは HTML コメントと囲みコードの 2 つ。行数は保存する
+ *   (行番号がずれると節の切り出しがずれるため)。
+ *
+ *   fence の受理範囲 (実装者依存にしない):
+ *     marker は**同一文字 3 個以上** (バッククォートまたはチルダ)、開始は**字下げ 3 空白まで**、
+ *     終了は**開始と同じ種類で開始以上の長さ・後続は空白のみ**、
+ *     バッククォート型は**情報文字列にバッククォートを含められない**
+ *     (含む行は開始 fence ではないので通常の本文として扱う。fence 扱いにすると
+ *     次に来る本物の開始 fence を終端と誤認して区間が 1 つずれる)。
+ *
+ *   **囲みコードの外の行に marker (3 個以上連続) が現れたら、その行が上の受理範囲を満たす
+ *   正規の top-level fence 行でない限り診断にする**。これで
+ *   引用やリストを伴う fence 候補も、行の途中の連続 marker も、
+ *   **3 個以上の delimiter の行内コード span** も落ちる。
+ *   **container 文法 (list marker の記法・padding・入れ子の順) は 1 つも書かない** —
+ *   判定に使うのは「marker より前に非空白があるか」という**位置だけ**である
+ *   (`container-fence` という名前はその位置の分類であって、container 文法の解析結果ではない)。
+ *
+ * 【契約 B — 字下げの禁止】囲みコードの外に次のいずれかがあれば行番号を返す (gate が失敗させる)。
+ *   1. **タブを含む行** (列の解釈が環境依存になるため)
+ *   2. **4 個以上連続した半角空白を含む行** (行頭に限らない)
+ *   **契約 B は container 文法を 1 つも扱わない**。
+ *
+ *   **見逃しが 0 であることの論証**:
+ *     (1) すべての有効な container prefix を消費した後の**内容開始列**を基準にする。
+ *     (2) 字下げコードには、その基準から**さらに 4 列以上**の字下げが要る。
+ *     (3) タブを禁じた場合、その追加 4 列を作れるのは**連続した U+0020 だけ**である。
+ *     (4) list marker の幅や padding は**内容開始列を決める prefix 側**であり、
+ *         追加 4 列の代用にはならない。
+ *     (5) 全行を見るので、コードブロックの**少なくとも先頭の非空行**で 4 連続空白を検出する。
+ *   よって引用の中の字下げも、リストの中の字下げも、番号つきリストの中の字下げも契約 B で落ちる。
+ *
+ *   i12 の目的 (契約の本文を読者に見えない場所へ退避させられないこと) は、
+ *   **そもそも書かせない**ことで満たす。**偽陽性の class は 1 つだけ**である —
+ *   本文の中で意図的に 4 空白以上を並べる書き方 (表の桁揃え等)。
+ *   **書き方を直す**のが正しい対応であり、検査は緩めない
+ *   (拾いすぎる方向へ倒すのは共通規約 (b) に沿う)。
+ *
+ * **CommonMark パーサは導入しない**: `marked` / `commonmark` / `markdown-it` はいずれも未導入で、
+ * この 1 検査のために依存を増やすのは「今必要なものだけ作る」に反する。
+ * **導入を再検討する契機**は「対象の文書に字下げコードを書く正当な必要が出たとき」である。
+ *
+ * 【保証しないもの】HTML 要素による非表示 (`<details>` / `hidden` 属性等) は見ていない。
+ * また HTML コメントの除去は**行内コードの文脈を見ない**ので、行内コードとして書いた
+ * HTML コメントは読者に見えていても潰される (跡には目印が残るので断片は繋がらない)。
+ */
+
+export type MarkdownDiagnosticReason =
+    | "unterminated-html-comment"
+    | "unterminated-fence"
+    /** container prefix を伴う fence 候補 (= marker より前に非空白がある行) */
+    | "container-fence"
+    /** 受理範囲外の fence 記法 (行頭から始まるが正規の fence ではない) */
+    | "unsupported-fence";
+
+export interface MarkdownDiagnostic {
+    /** 1 始まりの行番号 (診断出力用。期待値には使わない) */
+    readonly line: number;
+    readonly reason: MarkdownDiagnosticReason;
+}
+
+export interface MarkdownScan {
+    /** 規範判定の対象になる行 (HTML コメントと囲みコードを "" へ潰したもの。**行数は保つ**) */
+    readonly renderedLines: readonly string[];
+    /** 契約 B: 囲みコードの外でタブ、または 4 個以上連続した半角空白を含む行の行番号 (1 始まり) */
+    readonly forbiddenIndentLines: readonly number[];
+    /** 契約 A: 解析できなかった形 (1 件でもあれば gate が落ちる) */
+    readonly diagnostics: readonly MarkdownDiagnostic[];
+}
+
+const FENCE_MARKER = /`{3,}|~{3,}/;
+const FENCE_OPEN = /^ {0,3}(`{3,}|~{3,})/;
+const FENCE_CLOSE = /^ {0,3}(`{3,}|~{3,})[ \t]*$/;
+const FORBIDDEN_INDENT = /\t| {4,}/;
+
+/**
+ * コメントを取り除いた跡に残す目印。垂直タブ (U+000B) を使う。
+ *
+ * 要件は 2 つある。
+ *   1. **規範の最小断片には使わない文字**であること。半角空白のように断片へ現れる文字だと、
+ *      最小断片が元々空白を含む位置 (`同一 PR 内で` の空白等) を狙って断片を合成できてしまう
+ *   2. **`trim()` が空白として落とす文字**であること。落とさない文字 (U+0000 等) だと、
+ *      コメントだけの行が「本文のある行」に見えて節の非空検査をすり抜ける
+ * 垂直タブはこの 2 つを同時に満たす。
+ */
+export const HIDDEN_MARK = "\u000B";
+
+export function scanMarkdownLines(source: string): MarkdownScan {
+    const out: string[] = [];
+    const forbiddenIndentLines: number[] = [];
+    const diagnostics: MarkdownDiagnostic[] = [];
+
+    let fence: { readonly char: string; readonly length: number; readonly line: number } | null =
+        null;
+    let inComment = false;
+    let commentStartLine = 0;
+
+    const raws = source.split(/\r?\n/);
+    for (let index = 0; index < raws.length; index += 1) {
+        const raw = raws[index];
+        const lineNumber = index + 1;
+
+        if (fence !== null) {
+            const close = raw.match(FENCE_CLOSE);
+            if (close !== null && close[1][0] === fence.char && close[1].length >= fence.length) {
+                fence = null;
+            }
+            out.push("");
+            continue;
+        }
+
+        let line = raw;
+        if (inComment) {
+            const end = line.indexOf("-->");
+            if (end < 0) {
+                out.push("");
+                continue;
+            }
+            // コメントの終端より後ろだけを規範判定の対象として残す (跡に目印を置く)
+            line = HIDDEN_MARK + line.slice(end + 3);
+            inComment = false;
+        }
+
+        // 同一行に閉じる HTML コメントは繰り返し取り除く (跡には目印を 1 つ残す)
+        for (;;) {
+            const start = line.indexOf("<!--");
+            if (start < 0) break;
+            const end = line.indexOf("-->", start + 4);
+            if (end < 0) {
+                line = line.slice(0, start) + HIDDEN_MARK;
+                inComment = true;
+                commentStartLine = lineNumber;
+                break;
+            }
+            line = line.slice(0, start) + HIDDEN_MARK + line.slice(end + 3);
+        }
+
+        // 契約 B: 囲みコードの外の字下げを禁じる
+        if (FORBIDDEN_INDENT.test(line)) forbiddenIndentLines.push(lineNumber);
+
+        const markerIndex = line.search(FENCE_MARKER);
+        if (markerIndex >= 0) {
+            const open = line.match(FENCE_OPEN);
+            // バッククォート fence の情報文字列にバッククォートがある行は開始 fence ではない
+            const infoString = open === null ? "" : line.slice(open[0].length);
+            const validOpen = open !== null && !(open[1][0] === "`" && infoString.includes("`"));
+            if (validOpen && open !== null) {
+                fence = { char: open[1][0], length: open[1].length, line: lineNumber };
+                out.push("");
+                continue;
+            }
+            // 正規の top-level fence 行でない marker の出現は診断にする。
+            // 判定は「marker より前に非空白があるか」という位置だけで、container 文法は解釈しない。
+            diagnostics.push({
+                line: lineNumber,
+                reason: /^\s*$/.test(line.slice(0, markerIndex))
+                    ? "unsupported-fence"
+                    : "container-fence",
+            });
+        }
+
+        out.push(line);
+    }
+
+    if (fence !== null) diagnostics.push({ line: fence.line, reason: "unterminated-fence" });
+    if (inComment) {
+        diagnostics.push({ line: commentStartLine, reason: "unterminated-html-comment" });
+    }
+
+    return { renderedLines: out, forbiddenIndentLines, diagnostics };
+}
diff --git a/tests/js/styles/theme-map.test.ts b/tests/js/styles/theme-map.test.ts
new file mode 100644
index 00000000..5b6d289d
--- /dev/null
+++ b/tests/js/styles/theme-map.test.ts
@@ -0,0 +1,223 @@
+import { describe, expect, it } from "vitest";
+import {
+    cssColorTokens,
+    cssRadiusTokens,
+    cssRampUtilities,
+    parseCssColor,
+    parseThemeMap,
+    requiredMapValue,
+    resourceCssFiles,
+    tokensCssThemeMap,
+} from "./theme-map";
+
+/*
+ * theme-map の**パーサそのもの**の仕様を固定検体で固定する (正典 i18)。
+ *
+ * 実ファイルだけを相手にすると「解析が効いているから緑」なのか
+ * 「解析が壊れていても緑」なのか区別できない。壊れた形・紛らわしい形を
+ * 純粋入口 parseThemeMap(source, file) へ直接渡して両方向を固定する。
+ */
+
+const FIXTURE = "fixture.css";
+const parse = (source: string) => parseThemeMap(source, FIXTURE);
+
+describe("theme-map: @theme ブロックの検出 (負例)", () => {
+    it("負例 1: @theme を 2 ブロック持つ検体は blocks が 2 件になる (呼び出し側が落とせる)", () => {
+        const map = parse("@theme { --a: 1px; }\n@theme { --b: 2px; }");
+        expect(map.blocks.length).toBe(2);
+        expect(map.blocks.every((b) => b.topLevel)).toBe(true);
+    });
+
+    it("負例 2: @media の中の @theme は数えるが topLevel でなく、宣言も採らない", () => {
+        const map = parse("@media (min-width: 1px) { @theme { --a: 1px; } }");
+        expect(map.blocks.length).toBe(1);
+        expect(map.blocks[0].topLevel).toBe(false);
+        expect(map.declarations.size).toBe(0);
+    });
+
+    it("負例 3: コメントの中の @theme は数えない", () => {
+        expect(parse("/* @theme { --color-x: red; } */").blocks.length).toBe(0);
+    });
+
+    it("負例 4: 同名変数の再宣言は例外", () => {
+        expect(() => parse("@theme { --a: 1px; --a: 2px; }")).toThrow(/重複/);
+    });
+
+    it("負例 5: @theme の中の別の AtRule は例外", () => {
+        expect(() => parse("@theme { @media screen { --a: 1px; } }")).toThrow(/直接の子/);
+    });
+
+    it("負例 6: 閉じないブロックは例外 (CssSyntaxError)", () => {
+        expect(() => parse("@theme {")).toThrow();
+    });
+
+    it("負例 10b: @theme-extra / @utility-extra は名前が違うので数えない", () => {
+        const map = parse("@theme-extra { --a: 1px; }\n@utility-extra text-x { color: red; }");
+        expect(map.blocks.length).toBe(0);
+        expect(map.rampUtilities.size).toBe(0);
+    });
+
+    it("負例 10c: 未終端のコメントは例外", () => {
+        expect(() => parse("@theme { --a: 1px; }\n/* unterminated")).toThrow();
+    });
+
+    it("負例 10d: 未終端の文字列は例外", () => {
+        expect(() => parse("@theme { --a: 'unterminated }")).toThrow();
+    });
+
+    it("負例 10e: 宣言値の中の @theme はブロックとして数えない", () => {
+        expect(parse("--x: '@theme { }';").blocks.length).toBe(0);
+    });
+
+    it("負例 10f: @/* c */theme は例外 (At-rule without name)", () => {
+        expect(() => parse("@/* c */theme { --a: 1px; }")).toThrow();
+    });
+
+    it("負例 10g: @theme の中の Rule は例外", () => {
+        expect(() => parse("@theme { :root { color: red; } }")).toThrow(/直接の子/);
+    });
+
+    it("負例 10h: ブロックを持たない @theme; は例外", () => {
+        expect(() => parse("@theme;")).toThrow(/ブロックを持たない/);
+    });
+
+    it("負例 10i: params つきの @theme foo { } は例外", () => {
+        expect(() => parse("@theme foo { --a: 1px; }")).toThrow(/params/);
+    });
+
+    it("負例 10j: @utility の重複と規則外 params は例外", () => {
+        expect(() =>
+            parse("@utility text-x { font-size: 1px; }\n@utility text-x { font-size: 2px; }"),
+        ).toThrow(/重複/);
+        expect(() => parse("@utility bg-x { color: red; }")).toThrow(/params/);
+    });
+});
+
+describe("theme-map: 文字列状態の裏取り (負例 11〜14)", () => {
+    it("値の中のコメント風文字列は潰されない", () => {
+        const map = parse("@theme { --x: '/* not a comment */'; }");
+        expect(map.declarations.size).toBe(1);
+        expect(requiredMapValue(map.declarations, "--x", "--x")).toBe("'/* not a comment */'");
+    });
+
+    it("値の中の波括弧でブロックの対応が壊れない", () => {
+        const map = parse("@theme { --x: '{'; --y: '}'; --z: 1px; }");
+        expect([...map.declarations.keys()]).toEqual(["--x", "--y", "--z"]);
+    });
+
+    it("エスケープした引用符で文字列がそこで閉じない", () => {
+        const map = parse("@theme { --x: 'it\\'s'; --y: 1px; }");
+        expect([...map.declarations.keys()]).toEqual(["--x", "--y"]);
+    });
+
+    it("現行 --font-sans と同形 (引用符つき family 8 個) が丸ごと 1 宣言として取れる", () => {
+        const source =
+            "@theme {\n" +
+            "    --font-sans:  'Noto Sans JP', 'Hiragino Sans', 'Yu Gothic UI', 'Segoe UI',\n" +
+            "                  ui-sans-serif, system-ui, sans-serif,\n" +
+            "                  'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';\n" +
+            "    --x: 1px;\n" +
+            "}";
+        const map = parse(source);
+        expect([...map.declarations.keys()]).toEqual(["--font-sans", "--x"]);
+        expect(requiredMapValue(map.declarations, "--font-sans", "--font-sans")).toContain(
+            "Noto Color Emoji",
+        );
+    });
+});
+
+describe("theme-map: 正例", () => {
+    it("正例 4: 文字列の中の { を誤認しない", () => {
+        const map = parse('@theme { --f: "a{b"; --g: 2px; }');
+        expect(map.declarations.size).toBe(2);
+    });
+
+    it("正例 5: Comment を無視して宣言と ramp を採る", () => {
+        const map = parse(
+            "@theme { /* 節見出し */ --a: 1px; }\n@utility text-x { /* c */ font-size: 1px; }",
+        );
+        expect([...map.declarations.keys()]).toEqual(["--a"]);
+        expect(requiredMapValue(map.rampUtilities, "x", "text-x").get("font-size")).toBe("1px");
+    });
+
+    it("正例 1: 現行 tokens.css と同形の検体で色 / radius / ramp が取れる", () => {
+        const map = parse(
+            [
+                "@theme {",
+                "    --color-primary: #1d4ed8;",
+                "    --color-primary-soft: rgba(29, 78, 216, 0.12);  /* soft */",
+                "    --radius-sm: 4px;",
+                "}",
+                "@utility text-body {",
+                "    font-size: 16px;",
+                "    font-weight: 400;",
+                "}",
+            ].join("\n"),
+        );
+        expect(requiredMapValue(map.declarations, "--color-primary", "primary")).toBe("#1d4ed8");
+        expect(requiredMapValue(map.declarations, "--radius-sm", "radius")).toBe("4px");
+        expect(requiredMapValue(map.rampUtilities, "body", "text-body").get("font-weight")).toBe(
+            "400",
+        );
+    });
+});
+
+describe("theme-map: parseCssColor", () => {
+    it("負例 7: color-mix は例外 (扱えない色表現を読めたことにしない)", () => {
+        expect(() => parseCssColor("color-mix(in oklab, red 10%, transparent)")).toThrow();
+    });
+
+    it("負例 8: RGB が範囲外は例外", () => {
+        expect(() => parseCssColor("rgba(300, 0, 0, 0.1)")).toThrow();
+    });
+
+    it("負例 9: alpha が範囲外は例外", () => {
+        expect(() => parseCssColor("rgba(29, 78, 216, 1.5)")).toThrow();
+    });
+
+    it("負例 10: 余分な末尾文字は例外", () => {
+        expect(() => parseCssColor("#1d4ed8ff")).toThrow();
+    });
+
+    it("正例 2: rgba(...) を alpha 色として読む", () => {
+        expect(parseCssColor("rgba(29, 78, 216, 0.12)")).toEqual({
+            kind: "alpha",
+            rgb: { r: 29, g: 78, b: 216 },
+            alpha: 0.12,
+        });
+    });
+
+    it("正例 3: #rrggbb を不透明色として読む", () => {
+        expect(parseCssColor("#1d4ed8")).toEqual({
+            kind: "opaque",
+            rgb: { r: 29, g: 78, b: 216 },
+        });
+    });
+
+    it("空白区切り + スラッシュ記法も読む", () => {
+        expect(parseCssColor("rgb(29 78 216 / 0.5)")).toEqual({
+            kind: "alpha",
+            rgb: { r: 29, g: 78, b: 216 },
+            alpha: 0.5,
+        });
+    });
+});
+
+describe("theme-map: 実ファイルの母集団が空でない", () => {
+    it("tokens.css の宣言 / 色 / radius / ramp が 0 件でない", () => {
+        expect(tokensCssThemeMap().declarations.size).toBeGreaterThan(0);
+        expect(cssColorTokens().size).toBeGreaterThan(0);
+        expect(cssRadiusTokens().size).toBeGreaterThan(0);
+        expect(cssRampUtilities().size).toBeGreaterThan(0);
+    });
+
+    it("resources/ の *.css が 0 件でない (走査の空振り防止)", () => {
+        expect(resourceCssFiles().length).toBeGreaterThan(0);
+    });
+});
+
+describe("theme-map: requiredMapValue", () => {
+    it("不在は例外にする (undefined を文字列補間で undefined に化けさせない)", () => {
+        expect(() => requiredMapValue(new Map<string, string>(), "x", "ラベル")).toThrow(/ラベル/);
+    });
+});
diff --git a/tests/js/styles/theme-map.ts b/tests/js/styles/theme-map.ts
new file mode 100644
index 00000000..53db54e3
--- /dev/null
+++ b/tests/js/styles/theme-map.ts
@@ -0,0 +1,319 @@
+/**
+ * 実装写像 (resources/css/tokens.css) の読み出し — 検査テスト共有。
+ *
+ * ★正典 i21: 正本と写像の読み出しは**それぞれ 1 実装へ集約する**。
+ *   同じ関心の解析が 2 本あると弱い方が緑を作る (「片方だけが読める写像」が成立する)。
+ *   正本 (DESIGN.md) 側は design-md.ts が担当する。本ファイルは写像側だけを担当する。
+ *
+ * 【走査対象】呼び出し側が渡した CSS ソース文字列。実ファイルを読むのは薄いラッパーだけである。
+ * 【解析の方式】**postcss で構文木にしてから読む**。自前の字句走査は書かない。
+ *   `postcss` は既に devDependency で、`tokens.test.ts` が生成 CSS の解析に使っている
+ *   (同じ解析器を写像側にも使う = 思考原則 1「フレームワークのレンジ内でやる」)。
+ *   手書きの字句走査で解こうとしていた次の 4 つは、すべて解析器の側で解決する —
+ *     (a) 文字列リテラルの中の `/*` `{` `}` の誤認 (`--font-sans` は引用符つきの値を 8 個持つ)、
+ *     (b) at-keyword の境界 (`@theme-extra` は別の `name` になる)、
+ *     (c) 宣言値の中の `@theme` (`Decl` の値であって `AtRule` にならない)、
+ *     (d) 未終端のコメント・文字列・閉じないブロック (`CssSyntaxError` が飛ぶ = fail-closed)。
+ *   受理する形は**実測して一意に決めた** (postcss 8.5 で確認)。
+ *   読み方は 6 条 (外れたものはすべて**例外** = 正典 i20):
+ *     1. `@theme` は `AtRule` かつ `name === "theme"` の**完全一致**で、
+ *        **`params === ""`** かつ **`nodes !== undefined`** (ブロックを持つ) であること
+ *     2. `topLevel` は `parent` が `Root` であること
+ *     3. 宣言は**トップレベル `@theme` の直接の子 `Decl`** だけを採る。
+ *        許容する直接子は **`Decl` と `Comment` の 2 種**で、**`Comment` は無視する**
+ *        (tokens.css は `@theme` の中に節見出しコメントを持つので拒否すると実装できない)。
+ *        `Rule` / 別の `AtRule` / その他のノードがあれば**例外**
+ *     4. 同名宣言が 2 件以上あれば**例外** (postcss は後勝ちにせず `Decl` を 2 件返す)
+ *     5. `@utility` は**ルート直下**・`params` が `^text-[a-z0-9-]+$`・`nodes !== undefined`・
+ *        直接の子が `Decl` と `Comment` だけ (Comment は無視)・同じ `params` の重複が無いこと
+ *     6. 構文エラー (未終端コメント / 未終端文字列 / 閉じないブロック) は postcss の例外を伝播させる
+ * 【保証しないもの】
+ *   - Tailwind の解釈 (宣言が生成 CSS に出るか) は見ない。それは tokens.test.ts の担当
+ *   - postcss の AST 形状に依存する。postcss の major 更新で形が変われば
+ *     固定検体 (theme-map.test.ts) が最初に落ちる (無言で緑にはならない)
+ *   - 値の意味 (色空間・単位) は見ない。色だけは parseCssColor が明示的に扱う
+ *   - `resourceCssFiles()` が見るのは `resources/` 配下だけである。
+ *     その外に置いた CSS は見ない (アプリの CSS はすべて `resources/css` にあり、
+ *     `vite.config.ts` の入口も `resources/css/app.css` である)
+ */
+import fs from "node:fs";
+import path from "node:path";
+import postcss from "postcss";
+import { REPO_ROOT } from "./design-md";
+
+/** `@theme` ブロック 1 つ分の位置と階層。 */
+export interface ThemeBlock {
+    /** ソース先頭からのブロック開始位置 (診断用。期待値には使わない) */
+    readonly offset: number;
+    /** ルート直下の `@theme` か (条件つき at-rule の内側なら false) */
+    readonly topLevel: boolean;
+}
+
+/** 1 本のソースを解析した結果。 */
+export interface ThemeMap {
+    /** 見つかった `@theme` ブロック全件 (0 件・2 件以上も呼び出し側が判定できるよう返す) */
+    readonly blocks: readonly ThemeBlock[];
+    /** ルート直下の `@theme` 直下の CSS 変数宣言 `{ 変数名 → 値 }` */
+    readonly declarations: ReadonlyMap<string, string>;
+    /** `@utility text-<name>` の宣言 `{ name → { プロパティ → 値 } }` */
+    readonly rampUtilities: ReadonlyMap<string, ReadonlyMap<string, string>>;
+}
+
+const THEME_AT_RULE = "theme";
+const UTILITY_AT_RULE = "utility";
+const RAMP_UTILITY_PARAMS = /^text-[a-z0-9-]+$/;
+
+/**
+ * ★**唯一の解析実装**。実ファイル用の関数はすべてこの薄いラッパーである
+ *   (固定検体を解析する入口が公開 API に無いと theme-map.test.ts が任意入力を検査できず、
+ *   正典 i18 の裏取りにならない)。
+ * `file` は例外メッセージに載せる識別子であって、ファイルを読むためのものではない。
+ */
+export function parseThemeMap(source: string, file: string): ThemeMap {
+    const root = postcss.parse(source, { from: file });
+    const blocks: ThemeBlock[] = [];
+    const declarations = new Map<string, string>();
+    const rampUtilities = new Map<string, ReadonlyMap<string, string>>();
+
+    root.walkAtRules((rule) => {
+        if (rule.name === THEME_AT_RULE) {
+            if (rule.params !== "") {
+                throw new Error(`${file}: @theme に params がある (${JSON.stringify(rule.params)})`);
+            }
+            if (rule.nodes === undefined) {
+                throw new Error(`${file}: @theme がブロックを持たない`);
+            }
+            const topLevel = rule.parent?.type === "root";
+            blocks.push({ offset: rule.source?.start?.offset ?? 0, topLevel });
+            if (!topLevel) return;
+
+            for (const child of rule.nodes) {
+                if (child.type === "comment") continue;
+                if (child.type !== "decl") {
+                    throw new Error(`${file}: @theme の直接の子に ${child.type} がある`);
+                }
+                if (declarations.has(child.prop)) {
+                    throw new Error(`${file}: @theme の宣言 ${child.prop} が重複している`);
+                }
+                declarations.set(child.prop, child.value.trim());
+            }
+
+            return;
+        }
+
+        if (rule.name !== UTILITY_AT_RULE) return;
+
+        if (rule.parent?.type !== "root") {
+            throw new Error(`${file}: @utility がルート直下にない`);
+        }
+        if (!RAMP_UTILITY_PARAMS.test(rule.params)) {
+            throw new Error(`${file}: @utility の params が規則外 (${JSON.stringify(rule.params)})`);
+        }
+        if (rule.nodes === undefined) {
+            throw new Error(`${file}: @utility ${rule.params} がブロックを持たない`);
+        }
+        const name = rule.params.slice("text-".length);
+        if (rampUtilities.has(name)) {
+            throw new Error(`${file}: @utility ${rule.params} が重複している`);
+        }
+        const props = new Map<string, string>();
+        for (const child of rule.nodes) {
+            if (child.type === "comment") continue;
+            if (child.type !== "decl") {
+                throw new Error(`${file}: @utility ${rule.params} の直接の子に ${child.type} がある`);
+            }
+            if (props.has(child.prop)) {
+                throw new Error(`${file}: @utility ${rule.params} の宣言 ${child.prop} が重複している`);
+            }
+            props.set(child.prop, child.value.trim());
+        }
+        rampUtilities.set(name, props);
+    });
+
+    return { blocks, declarations, rampUtilities };
+}
+
+const TOKENS_CSS_RELATIVE = "resources/css/tokens.css";
+
+/** `resources/css/tokens.css` を読んで `parseThemeMap` に渡す薄いラッパー。 */
+export function tokensCssThemeMap(): ThemeMap {
+    return parseThemeMap(readResourceCss(TOKENS_CSS_RELATIVE), TOKENS_CSS_RELATIVE);
+}
+
+/** `resources/` 配下の CSS をリポジトリ相対パスで読む (走査根の外は読まない)。 */
+export function readResourceCss(relative: string): string {
+    return fs.readFileSync(path.join(REPO_ROOT, relative), "utf-8");
+}
+
+/**
+ * `resources/` 配下の `*.css` をリポジトリ相対パスで全件返す (ソート済み)。
+ *
+ * `git ls-files` を使わないのは、テスト実行で子プロセスを起こさないためである。
+ * 走査根 `resources/` が存在しなければ **fail-fast** で落とす。
+ */
+export function resourceCssFiles(): readonly string[] {
+    const root = path.join(REPO_ROOT, "resources");
+    if (!fs.existsSync(root)) throw new Error("走査根 resources/ が存在しない");
+
+    const found: string[] = [];
+    const walk = (dir: string): void => {
+        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
+            const full = path.join(dir, entry.name);
+            if (entry.isDirectory()) {
+                walk(full);
+                continue;
+            }
+            if (entry.isFile() && entry.name.endsWith(".css")) {
+                found.push(path.relative(REPO_ROOT, full).split(path.sep).join("/"));
+            }
+        }
+    };
+    walk(root);
+
+    return found.sort();
+}
+
+/** `--color-<suffix>` だけを suffix で引ける形にしたもの (小文字化)。 */
+export function cssColorTokens(): ReadonlyMap<string, string> {
+    const map = new Map<string, string>();
+    for (const [name, value] of tokensCssThemeMap().declarations) {
+        if (!name.startsWith("--color-")) continue;
+        map.set(name.slice("--color-".length), value.toLowerCase());
+    }
+
+    return map;
+}
+
+/** `--radius-<suffix>` だけを suffix で引ける形にしたもの。 */
+export function cssRadiusTokens(): ReadonlyMap<string, string> {
+    const map = new Map<string, string>();
+    for (const [name, value] of tokensCssThemeMap().declarations) {
+        if (!name.startsWith("--radius-")) continue;
+        map.set(name.slice("--radius-".length), value);
+    }
+
+    return map;
+}
+
+/** `@utility text-<name>` の宣言 (`tokensCssThemeMap().rampUtilities` の別名)。 */
+export function cssRampUtilities(): ReadonlyMap<string, ReadonlyMap<string, string>> {
+    return tokensCssThemeMap().rampUtilities;
+}
+
+/**
+ * `Map#get` の `undefined` を文字列補間で `"undefined"` に化けさせないための共有ヘルパ。
+ * 不在は**例外**にする (正典 i20: 解析の失敗を pass に変えない)。
+ */
+export function requiredMapValue<K, V>(map: ReadonlyMap<K, V>, key: K, label: string): V {
+    const value = map.get(key);
+    if (value === undefined) throw new Error(`${label} が見つからない`);
+
+    return value;
+}
+
+/** 色の正規化形 (S5 の合成と S10 の派生検査が共有する)。 */
+export type ParsedColor =
+    | { readonly kind: "opaque"; readonly rgb: Rgb }
+    | { readonly kind: "alpha"; readonly rgb: Rgb; readonly alpha: number };
+
+export interface Rgb {
+    readonly r: number;
+    readonly g: number;
+    readonly b: number;
+}
+
+const HEX_COLOR = /^#([0-9A-Fa-f]{6})$/;
+const RGB_FUNCTION = /^rgba?\(([^()]*)\)$/;
+const INTEGER = /^\d{1,3}$/;
+const NUMBER = /^(?:\d+(?:\.\d+)?|\.\d+)$/;
+
+function channelOf(text: string, value: string): number {
+    const trimmed = text.trim();
+    if (!INTEGER.test(trimmed)) throw new Error(`色の RGB が 0..255 の整数でない: ${value}`);
+    const n = Number(trimmed);
+    if (n > 255) throw new Error(`色の RGB が 0..255 の整数でない: ${value}`);
+
+    return n;
+}
+
+function alphaOf(text: string, value: string): number {
+    const trimmed = text.trim();
+    if (!NUMBER.test(trimmed)) throw new Error(`色の alpha が数値でない: ${value}`);
+    const n = Number(trimmed);
+    if (n > 1) throw new Error(`色の alpha が 0..1 でない: ${value}`);
+
+    return n;
+}
+
+/**
+ * 色の値を厳密に解析する (派生 token の値の検査と、合成の入力に使う)。
+ *
+ * 【受理する形】`#rrggbb` (大小文字どちらも) / `rgba(r, g, b, a)` / `rgb(r g b / a)`。
+ *   `#rrggbb` は必須である — 正本 (`designColors()`) が返すのは hex で、
+ *   S10 の「派生 token は正本の primary の RGB を alpha 0.12 にしたもの」の検査が
+ *   正本側の hex を本関数へ渡す。
+ * 【厳密に拒否する】RGB が 0..255 の整数でない / alpha が 0..1 でない /
+ *   余分な末尾文字がある / 数値にならない / 上記以外の関数記法 (`color-mix(…)` 等)。
+ *   いずれも**例外**にする (正典 i20: 読めるものだけ拾う形にしない)。
+ */
+export function parseCssColor(value: string): ParsedColor {
+    const trimmed = value.trim();
+
+    const hex = HEX_COLOR.exec(trimmed);
+    if (hex !== null) {
+        return {
+            kind: "opaque",
+            rgb: {
+                r: parseInt(hex[1].slice(0, 2), 16),
+                g: parseInt(hex[1].slice(2, 4), 16),
+                b: parseInt(hex[1].slice(4, 6), 16),
+            },
+        };
+    }
+
+    const fn = RGB_FUNCTION.exec(trimmed);
+    if (fn === null) throw new Error(`扱えない色表現: ${value}`);
+    const args = fn[1];
+
+    if (args.includes("/")) {
+        const [head, tail, ...rest] = args.split("/");
+        if (rest.length > 0) throw new Error(`扱えない色表現: ${value}`);
+        const parts = head.trim().split(/\s+/);
+        if (parts.length !== 3) throw new Error(`扱えない色表現: ${value}`);
+
+        return {
+            kind: "alpha",
+            rgb: {
+                r: channelOf(parts[0], value),
+                g: channelOf(parts[1], value),
+                b: channelOf(parts[2], value),
+            },
+            alpha: alphaOf(tail, value),
+        };
+    }
+
+    const parts = args.split(",");
+    if (parts.length === 3) {
+        return {
+            kind: "opaque",
+            rgb: {
+                r: channelOf(parts[0], value),
+                g: channelOf(parts[1], value),
+                b: channelOf(parts[2], value),
+            },
+        };
+    }
+    if (parts.length === 4) {
+        return {
+            kind: "alpha",
+            rgb: {
+                r: channelOf(parts[0], value),
+                g: channelOf(parts[1], value),
+                b: channelOf(parts[2], value),
+            },
+            alpha: alphaOf(parts[3], value),
+        };
+    }
+
+    throw new Error(`扱えない色表現: ${value}`);
+}
diff --git a/tests/js/styles/token-reference-closure.test.ts b/tests/js/styles/token-reference-closure.test.ts
new file mode 100644
index 00000000..e2882bba
--- /dev/null
+++ b/tests/js/styles/token-reference-closure.test.ts
@@ -0,0 +1,226 @@
+import { describe, expect, it } from "vitest";
+import {
+    scanClassUsage,
+    scanClassUsageSource,
+    scanCssVarReferences,
+    scanCssVarReferencesSource,
+} from "./class-usage";
+import { NON_TOKEN_WORD_CONTRACT } from "./inventory";
+
+/**
+ * 参照の閉包 (正典 i9) — 自リポジトリのスタイルと画面のコードが参照する token 名が、
+ * すべて写像 (`resources/css/tokens.css` の `@theme`) の宣言集合へ解決することを検査する。
+ *
+ * 【なぜ要るか】綴り誤りは「無スタイル」として静かに消える。Tailwind は未知の utility を
+ *   エラーにせず、単に生成しない。
+ * 【解決の根拠は写像 1 か所だけ】他ファイルのローカル宣言 (style 属性 / 別 CSS の `:root`) を
+ *   根拠に数えると、正本の外に token 空間が静かに育つ形が通ってしまう。
+ * 【走査対象】
+ *   - `resources/js`: 文字列リテラルの中の class トークン (class-usage.ts と同じ走査単位)
+ *   - `resources/js` / `resources/css`: `var(--…)` 参照
+ * 【契約表】token を指さない語は `NON_TOKEN_WORD_CONTRACT` へ**チャネル別**に全数登録する。
+ *   Tailwind 既定テーマの色語 (`white` / `black` / raw palette) は**登録しない** —
+ *   写像の外の token 空間を参照する形なので落とすのが正しい。
+ * 【本 gate が消費する診断】`scanCssVarReferences().diagnostics` (CSS var 走査)。
+ *   class 走査の診断は `class-usage.test.ts` が消費する。
+ * 【保証しないもの】
+ *   - `resources/views` 配下 (Laravel 同梱メールテーマの独立パレット) は対象外
+ *   - 変種の修飾の綴り (`hoverr:`) は見ない (Tailwind の名前空間で本アプリの写像ではない)
+ *   - 走査単位の外 (動的に組み立てた class) は見ない。既知の入口は class-usage.ts が deny する
+ *   - 増えるのは**テーマ名前空間の接頭辞を持つ語だけ**である。`flex` / `px-3` / `gap-2` は
+ *     接頭辞を持たないので母集団に入らない
+ */
+
+const CSS = "fixture.css";
+const TS = "fixture.ts";
+
+const unresolvedUtilities = (source: string, file: string): readonly string[] =>
+    scanClassUsageSource(source, file)
+        .occurrences.filter((o) => o.resolution.kind === "unresolved")
+        .map((o) => o.utility);
+
+const classFixture = (literal: string): readonly string[] =>
+    unresolvedUtilities(`export const a = ${literal};\n`, TS);
+
+describe("token-reference-closure: class トークンの閉包", () => {
+    const scan = scanClassUsage();
+
+    it("母集団が空でない (走査の空振り防止)", () => {
+        expect(scan.files.length).toBeGreaterThan(0);
+        expect(scan.occurrences.length).toBeGreaterThan(0);
+    });
+
+    it("テーマ名前空間の class トークンがすべて写像か契約表へ解決する", () => {
+        const unresolved = scan.occurrences
+            .filter((o) => o.resolution.kind === "unresolved")
+            .map((o) =>
+                o.resolution.kind === "unresolved"
+                    ? `${o.file}: ${o.raw} (${o.resolution.reason})`
+                    : "",
+            )
+            .sort();
+        expect(
+            [...new Set(unresolved)],
+            "写像 (tokens.css の @theme) にも契約表にも解決しない語がある。" +
+                "綴りを直すか、token を指さない語なら NON_TOKEN_WORD_CONTRACT へ理由つきで登録すること",
+        ).toEqual([]);
+    });
+});
+
+describe("token-reference-closure: var(--…) 参照の閉包", () => {
+    const scan = scanCssVarReferences();
+
+    it("走査根が 2 本とも生きており、参照の総数が 0 でない", () => {
+        expect([...scan.perRoot.keys()].sort()).toEqual(["resources/css", "resources/js"]);
+        for (const [root, count] of scan.perRoot) {
+            expect(count, `${root} からファイルが 1 件も取れない`).toBeGreaterThan(0);
+        }
+        // 根ごとの参照件数の非空は要求しない (参照を正当に消しただけで赤くなるため)。
+        // 要求するのは総数が 0 でないことだけで、これはドメインの不変条件である。
+        expect(scan.references.length, "var() 参照が 1 件も取れない").toBeGreaterThan(0);
+    });
+
+    it("CSS var 走査の診断が 1 件も無い (本 gate が診断の消費先である)", () => {
+        expect(scan.diagnostics).toEqual([]);
+    });
+
+    it("var(--…) 参照がすべて写像か契約表へ解決する", () => {
+        const unresolved = scan.references
+            .filter((r) => r.resolution.kind === "unresolved")
+            .map((r) => `${r.file}: var(${r.name})`)
+            .sort();
+        expect([...new Set(unresolved)]).toEqual([]);
+    });
+});
+
+describe("token-reference-closure: 契約表の健全性", () => {
+    const scan = scanClassUsage();
+    const varScan = scanCssVarReferences();
+
+    it("契約表に重複が無い", () => {
+        const keys = NON_TOKEN_WORD_CONTRACT.map((entry) =>
+            entry.kind === "class-word" ? `class:${entry.word}` : `var:${entry.name}`,
+        );
+        expect(new Set(keys).size).toBe(keys.length);
+    });
+
+    it("契約表の登録に理由が書かれている", () => {
+        for (const entry of NON_TOKEN_WORD_CONTRACT) {
+            const label = entry.kind === "class-word" ? entry.word : entry.name;
+            expect(entry.reason.length, `${label}: 理由`).toBeGreaterThan(0);
+        }
+    });
+
+    it("class-word の登録が class トークンとして 1 回以上出現し、写像へは解決しない", () => {
+        // チャネル別に判定する。別のチャネルでの出現によって登録が生きているように見える形を作らない。
+        const resolvedByContract = new Set(
+            scan.occurrences.flatMap((o) =>
+                o.resolution.kind === "contract" ? [o.resolution.word] : [],
+            ),
+        );
+        for (const entry of NON_TOKEN_WORD_CONTRACT) {
+            if (entry.kind !== "class-word") continue;
+            expect(
+                resolvedByContract.has(entry.word),
+                `${entry.word}: class トークンとして 1 件も出現しない (冗長な登録)`,
+            ).toBe(true);
+        }
+    });
+
+    it("css-variable の登録が var() 参照として 1 回以上出現し、写像へは解決しない", () => {
+        const resolvedByContract = new Set(
+            varScan.references.flatMap((r) =>
+                r.resolution.kind === "contract" ? [r.resolution.word] : [],
+            ),
+        );
+        for (const entry of NON_TOKEN_WORD_CONTRACT) {
+            if (entry.kind !== "css-variable") continue;
+            expect(
+                resolvedByContract.has(entry.name),
+                `${entry.name}: var() 参照として 1 件も出現しない (冗長な登録)`,
+            ).toBe(true);
+        }
+    });
+});
+
+describe("token-reference-closure: 負のコントロール (固定検体)", () => {
+    it("Tailwind 既定テーマの色語 (text-white) は通らない", () => {
+        expect(classFixture('"bg-primary text-white"')).toEqual(["text-white"]);
+    });
+
+    it("綴り誤り (bg-primaryy) は通らない", () => {
+        expect(classFixture('"bg-primaryy"')).toEqual(["bg-primaryy"]);
+    });
+
+    it("契約表の語 (text-center 等) は誤検出しない", () => {
+        expect(classFixture('"text-center text-left text-right rounded-full border-2"')).toEqual([]);
+    });
+
+    it("変種 / 重要度 / 不透明度の 3 形を別々に固定する", () => {
+        expect(classFixture('"sm:text-center"')).toEqual([]);
+        expect(classFixture('"!text-center"')).toEqual([]);
+        // 色でない utility への不透明度修飾は「同じ語」として通さない
+        expect(classFixture('"text-center/50"')).toEqual(["text-center"]);
+    });
+
+    it("写像に無い CSS 変数の参照は通らない", () => {
+        const scan = scanCssVarReferencesSource("a { color: var(--color-does-not-exist); }", CSS);
+        expect(scan.references.map((r) => r.resolution.kind)).toEqual(["unresolved"]);
+    });
+
+    it("別ファイルのローカル宣言を解決の根拠に数えない", () => {
+        // 写像 1 か所だけという境界そのものを pin する。
+        const scan = scanCssVarReferencesSource(
+            ":root { --color-foo: red; }\na { color: var(--color-foo); }",
+            CSS,
+        );
+        expect(scan.references.map((r) => r.resolution.kind)).toEqual(["unresolved"]);
+    });
+
+    it("チャネルが違う登録は解決の根拠にならない", () => {
+        // class-word の登録は var() 参照を救わない
+        expect(
+            scanCssVarReferencesSource("a { color: var(--text-center); }", CSS).references.map(
+                (r) => r.resolution.kind,
+            ),
+        ).toEqual(["unresolved"]);
+        // css-variable の登録は class トークンを救わない
+        expect(classFixture('"text-app-sidebar-w"')).toEqual(["text-app-sidebar-w"]);
+    });
+
+    it("var() 走査の受理契約 (関数トークンの境界・カンマ・fallback・未終端)", () => {
+        const names = (source: string): readonly string[] =>
+            scanCssVarReferencesSource(source, CSS).references.map((r) => r.name);
+        const reasons = (source: string): readonly string[] =>
+            scanCssVarReferencesSource(source, CSS).diagnostics.map((d) => d.reason);
+
+        expect(names('a { content: "var(--x)"; }')).toEqual([]);
+        expect(names("a { color: var(--color-primary /* c */); }")).toEqual(["--color-primary"]);
+        expect(names("a { color: var(--color-primary, var(--color-neutral)); }")).toEqual([
+            "--color-primary",
+            "--color-neutral",
+        ]);
+        // 閉じない `var(` は at-rule の条件式で確かめる (宣言側は postcss の parse が先に落ちる)
+        expect(reasons("@media var(--color-primary { a { color: red; } }")).toEqual([
+            "unterminated-function",
+        ]);
+        expect(reasons("a { color: var(--color-primary; }")).toEqual(["css-parse-failed"]);
+        expect(names('@theme { --f: "a,b", c; }')).toEqual([]);
+        expect(reasons('@theme { --f: "a,b", c; }')).toEqual([]);
+        expect(names("@media (min-width: var(--color-primary)) { a { color: red; } }")).toEqual([
+            "--color-primary",
+        ]);
+        expect(names("a { color: myvar(--color-primary); }")).toEqual([]);
+        expect(reasons("a { color: myvar(--color-primary); }")).toEqual([]);
+        expect(reasons("a { color: var(--color-primary garbage); }")).toEqual(["unresolvable-var"]);
+        expect(names("a { color: var(--color-primary, b, c); }")).toEqual(["--color-primary"]);
+    });
+
+    it("列挙外の at-rule の条件式に var( があれば診断になる (無視しない)", () => {
+        expect(
+            scanCssVarReferencesSource("@page var(--color-primary) { size: a; }", CSS).diagnostics.map(
+                (d) => d.reason,
+            ),
+        ).toEqual(["unsupported-at-rule-params"]);
+    });
+});
diff --git a/tests/js/styles/tokens.test.ts b/tests/js/styles/tokens.test.ts
index 48926e24..c1543729 100644
--- a/tests/js/styles/tokens.test.ts
+++ b/tests/js/styles/tokens.test.ts
@@ -5,6 +5,7 @@ import path from "node:path";
 import postcss, { AtRule, type Container, type Document, type Root, type Rule } from "postcss";
 import tailwindcss from "@tailwindcss/postcss";
 import { REPO_ROOT, designColors, designRamp, designRounded } from "./design-md";
+import { cssColorTokens, parseCssColor, requiredMapValue } from "./theme-map";
 import {
     COLOR_TOKEN_MAP,
     COMPILED_VALUE_EXEMPT_TOKENS,
@@ -52,6 +53,11 @@ const UTILITY_CANDIDATES = {
     radius: RADIUS_TOKENS.map((r) => `rounded-${r}`),
     ramp: TYPOGRAPHY_RAMPS.map((r) => `text-${r}`),
     hover: CSS_COLOR_SUFFIXES.filter((s) => s.endsWith("-hover")).map((s) => `hover:bg-${s}`),
+    /**
+     * 不透明度修飾。**S5 (合成の検査) が置く前提「修飾は同じ色の alpha になる」の裏取り**。
+     * 代表として不透明 token の /10 と、alpha を値に持つ派生 token の /40 (= 二重) を取る。
+     */
+    alpha: ["bg-primary/10", "bg-primary-soft/40"],
 } as const;
 
 /**
@@ -576,3 +582,86 @@ describe("tokens/G: app.css の入口 2 行の規約", () => {
         expect(second.params).toMatch(/^["']\.\/tokens\.css["']$/);
     });
 });
+
+/* ===== H. 不透明度修飾の生成形 (密閉の層) ===== */
+
+/**
+ * 不透明度修飾の宣言を包んでよい条件つき at-rule の一覧 (`<名前> <条件文>`)。
+ *
+ * Tailwind v4 は `color-mix(in oklab, …)` を `@supports` で条件づける (実測)。
+ * ここに無い条件が現れたら赤になる = 生成形の前提が変わった契機である。
+ */
+const ALLOWED_ALPHA_CONDITIONS: readonly string[] = [
+    "supports (color: color-mix(in lab, red, red))",
+];
+
+describe("tokens/H: 不透明度修飾は同じ色の alpha として生成される", () => {
+    /*
+     * S5 の合成モデルはこの生成形を前提にしている。前提が版で変わったら
+     * ここが赤くなって「見直す契機」になる (正典 i16 が要求する形)。
+     *
+     * 実測 (Tailwind 4.x):
+     *   .bg-primary\/10 {
+     *       background-color: color-mix(in srgb, #2563eb 10%, transparent);
+     *       @supports (color: color-mix(in lab, red, red)) {
+     *           background-color: color-mix(in oklab, var(--color-primary) 10%, transparent);
+     *       }
+     *   }
+     * fallback 側は**正本の hex をリテラルで埋め込む**ので、値の突き合わせも兼ねる。
+     */
+    it("不透明 token の /10 は正本の hex を 10% で透明と混ぜた形になる", () => {
+        const decls = soleRule(sealed, ".bg-primary\\/10");
+        // Map#get の undefined が文字列補間で "undefined" になり、
+        // 「意図した解析失敗」ではなく「文字列が一致しないだけ」の赤に化けるのを防ぐ。
+        const expected = requiredMapValue(designColors(), "primary", "DESIGN.md colors.primary");
+        expect(requiredMapValue(decls, "background-color", ".bg-primary/10")).toBe(
+            `color-mix(in srgb, ${expected} 10%, transparent)`,
+        );
+    });
+
+    it("@supports の中は var() 参照の oklab 混色になる", () => {
+        // 条件つき at-rule の中は soleRule が拾わないので、条件つきの側を明示的に見る。
+        // 条件の綴りは allowlist と突き合わせる (D の ALLOWED_HOVER_CONDITIONS と同じ方針)。
+        const rules = rulesWithSelector(sealed, ".bg-primary\\/10");
+        expect(rules.length, ".bg-primary/10 の規則が 1 件でない").toBe(1);
+        const { values, conditions } = collectDeclarations(rules[0]);
+        expect(conditions).toEqual(ALLOWED_ALPHA_CONDITIONS);
+        expect(requiredMapValue(values, "background-color", ".bg-primary/10 @supports")).toBe(
+            "color-mix(in oklab, var(--color-primary) 10%, transparent)",
+        );
+    });
+
+    it("alpha を値に持つ派生 token への修飾は実効 alpha が積になる (S5 が合成対象にする根拠)", () => {
+        const decls = soleRule(sealed, ".bg-primary-soft\\/40");
+        const soft = requiredMapValue(cssColorTokens(), "primary-soft", "--color-primary-soft");
+        expect(requiredMapValue(decls, "background-color", ".bg-primary-soft/40")).toBe(
+            `color-mix(in srgb, ${soft} 40%, transparent)`,
+        );
+        // 透明との混色は乗算済み alpha なので、実効 alpha は token の alpha × 修飾の alpha に確定する。
+        const parsed = parseCssColor(soft);
+        expect(parsed.kind).toBe("alpha");
+        if (parsed.kind !== "alpha") return;
+        expect(parsed.alpha * 0.4).toBeCloseTo(0.048, 6);
+    });
+
+    /*
+     * 派生 token の**導出関係**を機械で固定する。
+     * COMPILED_VALUE_EXEMPT_TOKENS が免除しているのは「DESIGN.md に期待値が無い」ことの
+     * 表明にとどまり、**別の rgba へ静かに差し替わる**ことまで許してはいない。
+     * これが無いと、primary を直したのに primary-soft を直し忘れた状態が
+     * (生成 CSS の出現とコントラストが偶然通れば) 検出できない。
+     */
+    it("--color-primary-soft は正本の primary の RGB を alpha 0.12 にしたものである", () => {
+        const soft = parseCssColor(
+            requiredMapValue(cssColorTokens(), "primary-soft", "--color-primary-soft"),
+        );
+        const primary = parseCssColor(
+            requiredMapValue(designColors(), "primary", "DESIGN.md colors.primary"),
+        );
+        expect(soft.kind).toBe("alpha");
+        expect(primary.kind).toBe("opaque");
+        if (soft.kind !== "alpha" || primary.kind !== "opaque") return;
+        expect(soft.rgb).toEqual(primary.rgb);
+        expect(soft.alpha).toBe(0.12);
+    });
+});

```

## テスト結果

- composer test: 7835 tests / 7833 passed / 0 failed / 2 skipped / 38031 assertions
- composer phpstan (level 10): No errors
- vendor/bin/pint --test: passed
- pnpm lint / pnpm typecheck: passed
- pnpm test: 183 files / 2733 tests passed
- pnpm build: OK
- pnpm typecheck:packages / build:packages / test:packages: passed (129 tests)

実装順は設計指定どおり S1→S2→S4→S10→S5→S6→S3→S7→S9→S8→S11→S12。
S5 (合成検査) を入れた時点で設計が予測した **5 組ちょうど** が AA 未達で赤になることを実測で確認し、
その後 S6 (トークン値の是正) を行って緑にした。
S3 の閉包 gate は実装直後に text-white 3 箇所を検出して赤になり、text-surface へ直して緑にした。
S7 の逆向き被覆は、役割 (surface の fill-label / border の declared-text-background) を
一時的に外すと (surface, primary) と (text-primary, border) を検出して赤になることを実測で確認した。
S8 の双方向一致は DESIGN.md へ 4 節を足す前に 4 部品を検出して赤になった。
