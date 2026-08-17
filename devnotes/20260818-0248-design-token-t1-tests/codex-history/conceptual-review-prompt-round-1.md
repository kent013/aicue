## 使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(窓口 `PromptDefense` → 実行単位 `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

追加の思考原則 (本リポジトリ AGENTS.md より):

1. フレームワークのレンジ内でやる。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. 今必要なものだけ作る(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. 後方互換の並走を残さない
4. 別物の概念を「似ているから」で統合しない
5. テストファースト。fail を確認してから実装に入る
6. タコツボ実装を避ける

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js + Tailwind v4 + vitest）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: TypeScript strict を通せるか

【この設計に固有の重要論点 — 必ず言及すること】
- 家系 (複数リポジトリ) の共通機能台帳が定めた「正典 t1」の形に**あえて従わない**判断を 2 つ含む。
  (a) 期待値の literal 表を持たず DESIGN.md から導出する、(b) 静的 fixture CSS を持たず入力を組み立てる。
  これは「同一不変条件・別実装」として正当か、それとも正典に揃えるべきか
- 新設する `tokens.test.ts` は既存 `canonical-source-parity.test.ts` と何が重複し、何が重複しないか。
  重複が実質ゼロと言えるか、あるいは既存側を縮めるべきか
- 文書 (`docs/design-system.md`) の必須フレーズ検査は形骸化しやすい。導入する価値があるか、
  あるなら形骸化を避ける条件は何か

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は `devnotes/20260818-0248-design-token-t1-tests/conceptual-design.md` の全文）

<!-- CONCEPTUAL_DESIGN_BEGIN -->
# 概念設計: design-token-t1-tests (機能 `design-token-system` の正典 t1 追従)

## 背景・課題

### 台帳の状態 (機能 `design-token-system`)

家系の機能台帳における本機能の裁定 (AG-022 / 2026-08-05) は、標準形 **t1** を次の形と定めている:

> テンプレの 3 者一致検査 (DESIGN.md ⇔ app.css ⇔ inventory) + コントラスト検査 に、
> aigenba の追加検査 2 本 (tokens / design-system-docs) を足した形。

台帳が `gates:` として宣言している正典パスは 5 本:

| # | パス | aicue の現状 |
|---|---|---|
| 1 | `tests/js/styles/canonical-source-parity.test.ts` | 実在 |
| 2 | `tests/js/styles/inventory.ts` (parity 入力) | 実在 |
| 3 | `tests/js/architecture/contrast-invariant.test.ts` | 実在 (2026-08-05 の欠落補完で導入) |
| 4 | `tests/js/styles/tokens.test.ts` | **不在** |
| 5 | `tests/js/styles/design-system-docs.test.ts` | **不在** |

aicue の台帳上の状態は `status: implemented` / `version: t0` であり、t1 への追従が残っている。
前回バッチ (`devnotes/20260805-0101-frontend-baseline-gates/`) は 4・5 を「AG-022 で t1 標準形への
採用は裁定されているが aicue への配布は agenda 未裁定」としてスコープ外に置いた。本設計はその
積み残しを閉じる。

> 参照実装は aigenba の `tests/js/styles/{tokens.test.ts,design-system-docs.test.ts,inventory.ts}` と
> `tests/fixtures/ds-tokens-input.css` を実読して確認した (2026-08-18)。

### 現状の検査が「見ていない」もの

aicue の既存 2 本は、いずれも **ファイルのテキストを正規表現で読む**検査である。

- `canonical-source-parity.test.ts`: `DESIGN.md` frontmatter ⇔ `resources/css/tokens.css` の
  テキスト同士を突き合わせる (色の集合・値、radius、typography ramp の size/weight/line-height/letter-spacing)
- `contrast-invariant.test.ts`: `DESIGN.md` の色値だけを読んでコントラスト比を計算する

したがって、**「tokens.css に書いてある宣言が、Tailwind のビルドを通って実際に CSS として出てくるか」**
は 1 行も検査されていない。具体的に次の壊れ方は現在すべて緑のまま通る:

1. `tokens.css` の `@theme { … }` を別のブロックに移す / 入れ子を間違える →
   CSS 変数が生成されず、画面から色が消えるがテキスト検査は一致したまま緑
2. `resources/css/app.css` の `@import './tokens.css'` を消す / `@import 'tailwindcss'` より前へ動かす →
   同上 (取り込み順序が壊れると `@theme` が効かない)
3. UI が使っている utility 名 (`bg-primary` / `text-text-secondary` / `border-border` /
   `hover:bg-primary-hover` / `bg-primary-soft` / `rounded-md` / `text-h1` …) が
   Tailwind から生成されなくなる (token 名の綴り変え・`@utility` 定義の削除)
4. ramp の `font-family` が正しい font token を指しているか
   (**既存の parity は fontFamily を 1 つも検査していない** — frontmatter に
   `fontFamily: "Noto Sans JP, sans-serif"` が書いてあるのに突き合わせ先が無い)

これらは「デザイントークン体系」という機能の名前が果たすべき役割 —
**正本 (DESIGN.md) に書いた見た目が実際の画面まで届くこと** — の中核であり、
現状はその最後の一区間 (tokens.css → ビルド成果物) だけが無検査である。

また `docs/design-system.md` は同期運用契約 (「片方だけ更新する PR は merge しない」
「新規 domain 色トークン追加の 4 条件」「file-scoped allowlist の 7 フィールド」) の正本だが、
**この文書が黙って空になっても何も落ちない**。

### 使命との関係

撮影 PWA は現場作業者がスマホで使う面であり、状態色 (成功・警告・危険) と本文コントラストが
読めることが「思考ゼロ・編集ゼロ」の前提になる。色が消える / 読めない色になる壊れ方を
レビューの注意力ではなく CI で止めることは、既に本リポジトリが `contrast-invariant` で選んだ方針の延長である。

## 改善アイデア

正典 t1 が足す 2 本を aicue へ導入する。ただし **不変条件は正典と同じにし、実装は
aicue 側の既存資産 (DESIGN.md 単一正本 + 共有パーサ `design-md.ts` + `inventory.ts`) に合わせる**。

### 1. `tests/js/styles/tokens.test.ts` — 生成 CSS の検査 (新設)

postcss + `@tailwindcss/postcss` で **tokens.css を実際にコンパイル**し、生成された CSS に対して
検査する。どちらも既に devDependency として実在するので依存追加は無い。

検査するもの:

| 区分 | 内容 |
|---|---|
| A. CSS 変数 | `--color-*` / `--radius-*` / `--font-sans` が生成 CSS に現れ、値が **DESIGN.md 由来の期待値と一致**する |
| B. ramp utility | `text-display` / `text-h1` / … の 6 本が生成され、font-family / font-size / font-weight / line-height (+ letter-spacing) が DESIGN.md と一致する |
| C. 色 utility | 全色トークンについて `bg-*` / `text-*` / `border-*` が `var(--color-*)` を参照する形で生成される |
| D. variant | `hover:bg-primary-hover` / `hover:bg-tertiary-hover` が hover 時の背景色として解決する |
| E. radius utility | `rounded-sm` / `rounded-md` / `rounded-lg` が `var(--radius-*)` を参照する |
| F. 取り込み順序 | `resources/css/app.css` の非空・非コメント先頭 2 行が `@import 'tailwindcss'` → `@import './tokens.css'` の順である |

### 2. `tests/js/styles/design-system-docs.test.ts` — 運用契約文の検査 (新設)

`docs/design-system.md` を節ごとに切り出し、**合意した運用契約の要の文が消えていないこと**を検査する。
加えて「Canonical source の宣言」表に並ぶリポジトリ相対パスが**実在すること**を検査する
(改名・移動で表だけが古くなる壊れ方を止める)。

### 3. 既存 2 本との重複・整合の整理

3 本 + コントラスト検査の**責務境界を宣言として書き、機械で固定する**。

| 検査 | 何から何への写像を見るか |
|---|---|
| `canonical-source-parity` | DESIGN.md (正本) ⇔ tokens.css (写像) の**テキスト**一致 |
| `tokens.test` (新設) | tokens.css ⇒ **Tailwind 生成 CSS** (宣言がビルドへ届くか / utility 名が解決するか) |
| `contrast-invariant` | DESIGN.md の色値の**可読性** |
| `design-system-docs` (新設) | `docs/design-system.md` の**運用契約文**の残存 |

さらに「DESIGN.md frontmatter の各節が、どの検査の担当か」を `inventory.ts` に
**既定拒否で宣言**する。未分類の節があれば落とす。これは既に `contrast-invariant` が
色トークンの役割分類で使っている作法と同じで、この宣言を書くと現状の**未検査の穴が 1 つ露出する** —
frontmatter の `spacing:` (xs/sm/md/lg/xl) は tokens.css に写像が無く、どの検査も見ていない。
本バッチではこれを **PENDING (未検査であることの明示宣言)** として登録し、値の是正は行わない。

## 実装方針（概要）

**「値の写しを増やさない」を最優先の制約とする。** 正典 (aigenba) の `tokens.test.ts` は
`inventory.ts` に色 14 件の hex と utility 21 対を **literal で持つ** 形だが、aicue でそれを
そのまま真似ると DESIGN.md / tokens.css に続く **3 つ目の値の写し**ができる。
AGENTS.md が繰り返し書いている「2 か所に書くと必ず食い違う」に正面から反するため、aicue では

- **期待値は `design-md.ts` の共有パーサ経由で DESIGN.md から導出する**
- **utility 名は既存 `inventory.ts` の token 集合から機械的に組み立てる**
  (`bg-<suffix>` / `text-<suffix>` / `border-<suffix>` / `rounded-<段>` / `text-<ramp>`)

とする。結果として **新しい表を 1 つも足さずに** 正典と同じ不変条件を張れ、
トークンを増やしたときの検査漏れも構造的に起こらない (新トークンは自動で検査対象に入る)。

この差は `docs/template-divergence.md` へ **D27 (同一不変条件・別実装)** として登録する。

| # | 施策 | 主な変更 | 優先度 |
|---|---|---|---|
| 1 | `tokens.test.ts` 新設 (生成 CSS 検査) | `tests/js/styles/tokens.test.ts` (新規) | 高 |
| 2 | frontmatter 節の担当宣言 (既定拒否) + spacing の PENDING 宣言 | `tests/js/styles/inventory.ts` | 中 |
| 3 | `design-system-docs.test.ts` 新設 | `tests/js/styles/design-system-docs.test.ts` (新規) | 中 |
| 4 | `docs/design-system.md` に検査の責務境界を追記 | `docs/design-system.md` | 中 |
| 5 | 逸脱登録 D27 | `docs/template-divergence.md` | 高 (施策 1 と同一 PR) |

### 前提の実測確認 (設計時に実施済み)

`devnotes/20260818-0248-design-token-t1-tests/probe-tailwind-compile.mjs` を実行して確認した:

- postcss を**文字列入力**で走らせ、`from` に実在しないパスを渡しても、
  相対 `@import "../../../resources/css/tokens.css"` は解決される
  → 正典が持つ静的 fixture (`tests/fixtures/ds-tokens-input.css`) を**持たなくてよい**
- `@import "tailwindcss";` のままだと Tailwind の自動ソース走査が働き、
  **アプリ全体の class を拾って 46,667 文字**を生成する
  (正典の fixture コメントは「アプリ全体のクラス変動に影響されない」と書いているが、
  実測ではそうなっていない)。`@import "tailwindcss" source(none);` にすると
  **7,682 文字**まで落ち、アプリ由来の class (`.flex` 等) は 1 件も混ざらない
  → aicue は `source(none)` を使い、入力を密閉する
- 生成 CSS を **postcss の AST として走査**すれば、`hover:` variant のように
  `.hover\:bg-primary-hover { &:hover { @media (hover: hover) { … } } }` と
  **2 段入れ子**になる出力も素直に読める
  (正典は文字列正規表現で 1 段入れ子を仮定しており、この出力形では壊れる)

## 期待効果

- **使命への貢献**: 「正本に書いた色・文字サイズが実際の画面まで届く」ことの最後の一区間が
  機械で閉じる。色が消える / 状態色が壊れる形の事故は、現場で撮影中の作業者に直撃する
- **既存の穴が 2 つ埋まる**: (a) ramp の font-family がどこからも検査されていない、
  (b) app.css の取り込み順序契約が文書にしか無い
- **未検査の穴が 1 つ可視化される**: frontmatter の `spacing:` に写像が無いこと
- **家系との整合**: 台帳 `gates:` が宣言する 5 本が全て揃い、aicue の版が t0 → t1 になる

## 制約・前提

- 依存追加は無い (`postcss` `@tailwindcss/postcss` `tailwindcss` は既に devDependencies)
- vitest の include は `tests/js/**/*.test.ts` なので `scripts/test-inventory-config.ts` の変更は不要
- Tailwind のコンパイルが 1 ファイルにつき 1 回走る。`beforeAll` に十分な timeout を置く
- TypeScript 必須 (JS を新規に足さない)。`tsconfig.json` の include は `tests/js/**/*.ts` を含む
- 検査の追加であって**トークンの値は 1 つも変えない** (色・サイズ・角丸の是正は本バッチの対象外)
- `docs/design-system.md` を書き換えると `design-system-docs.test.ts` の必須フレーズと
  循環しうるので、**フレーズは「消えたら運用契約が失われる文」だけに絞る**

## スコープ外 (と、その申し送り)

- **`spacing:` トークンの実装写像を作ること**。frontmatter に xs〜xl が宣言されているが
  tokens.css に `--spacing-*` は無い。「Tailwind 既定の spacing で足りているから写像を作らない」のか
  「作り忘れ」なのかは設計判断であり、値を弄る前に決めるべき事柄なので別バッチとする
  (本バッチは未検査であることの明示宣言までを行う)
- **派生トークン `--color-primary-soft` の値検査**。DESIGN.md frontmatter に無い rgba 値であり、
  現状 parity は集合にしか現れない (値の正本は tokens.css)。本バッチは生成 CSS への
  「存在」までを見て、値の正本化は別途とする
- **WCAG 1.4.11 (非テキスト 3:1) / alpha 合成ペア**。既存 `PENDING_CONTRAST_PAIRS` のまま据え置く
- **`pnpm build` を通した実物の CSS の検査**。本バッチはコンパイラを通すところまでとする
- **メールテンプレート (`resources/views/vendor/mail/html/themes/template.css`)**。
  CSS 変数を使えないクライアント向けの独立パレットであり DS token の写像ではない
- 台帳への `append_event` は行わない (実装完了後に監督側が一括で行う)
