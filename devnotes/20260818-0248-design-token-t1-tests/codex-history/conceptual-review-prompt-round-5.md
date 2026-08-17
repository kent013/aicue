# Round 5: Round 4 指摘への対応

Round 4 の指摘 3 件はすべて対応しました (反論なし)。対応マトリクスと修正後の概念設計全文を示します。
全体判定を出してください (APPROVED / CHANGES_REQUESTED)。

---

# 対応マトリクス: conceptual-review Round 4

すべて **対応する**。反論は 1 件も無い。いずれも設計変更ではなく本文の正確化。

## [Warning] R2 の記述が Tailwind 既定テーマの実測と矛盾する (観点 2)

- 判断: 対応する
- 根拠: 同じ設計内で「tokens.css を外しても既定の `--radius-*` / `--font-sans` は残る」と
  実測しているのに、R2 では「C〜E の utility が 1 つも生成されない」と書いていた。E は成り立たない。
- 対応内容: R2 を区分ごとに書き分けた。C / D は独自の色テーマが失われて対象 utility が
  生成されない。E は `rounded-*` 自体は既定テーマから生成され得るが、値が既定 (`0.25rem` 等) になり
  `var(--radius-*)` 参照でなくなるので赤になる。どの assertion が赤になるかは詳細設計で固定する。

## [Warning] 背景の壊れ方 1 の記述が R2 の説明と矛盾する (観点 4)

- 判断: 対応する
- 対応内容: 「CSS 変数が生成されず画面から色が消える」をやめ、
  「`@theme` として解釈されなくなる。生の CSS 変数が残る場合でも、テーマ由来 utility の生成と
  トークン参照が壊れる。既存のテキスト一致検査は tokens.css の字面しか見ていないので検出できない」
  に書き換えた。

## [Warning] 制約・前提の冒頭が直後の説明と矛盾する (観点 7)

- 判断: 対応する
- 対応内容: 冒頭を提案どおり
  「共有パーサの空振りを**限定的に**防ぐ。キーの取りこぼしは集合一致で検出するが、
  値の誤解析は共通障害として残る」に書き換えた。

---

## 修正後の概念設計 (全文)

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
   **`@theme` として解釈されなくなる**。生の CSS 変数が出力に残る場合でも、
   テーマ由来 utility の生成とトークン参照が壊れる。既存のテキスト一致検査は
   `tokens.css` の字面しか見ていないので、この差を検出できず緑のまま通る
2. `resources/css/app.css` から `@import './tokens.css'` を消す →
   同上。**これは実際の機能破損**であり、経路の層 (F) が検出する。
   なお `@import` の**順序**を入れ替えても実測では壊れない (§前提の実測確認)。
   順序はリポジトリ規約であって動作の不変条件ではなく、規約としての固定は G が担う
3. UI が使っている utility 名 (`bg-primary` / `text-text-secondary` / `border-border` /
   `hover:bg-primary-hover` / `bg-primary-soft` / `rounded-md` / `text-h1` …) が
   Tailwind から生成されなくなる (token 名の綴り変え・`@utility` 定義の削除)
4. ramp の `font-family` が正しい font token を指しているか
   (**既存の parity は fontFamily を 1 つも検査していない** — frontmatter に
   `fontFamily: "Noto Sans JP, sans-serif"` が書いてあるのに突き合わせ先が無い)

これらは「デザイントークン体系」という機能の名前が果たすべき役割 —
**正本 (DESIGN.md) に書いた見た目が Tailwind の生成 CSS まで届くこと** — の中核であり、
現状はそのうち tokens.css → 生成 CSS の区間だけが無検査である
(生成 CSS より先、Vite のビルド・配信・ブラウザでの適用は本設計の保証範囲に入らない)。

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

postcss + `@tailwindcss/postcss` で **実際にコンパイル**し、生成された CSS に対して検査する。
どちらも既に devDependency として実在するので依存追加は無い。

**2 層に分ける** (どちらか一方だけでは t1 の検出能力に届かない):

| 層 | 入力 | 役割 |
|---|---|---|
| **経路の層** | 実物の `resources/css/app.css` をそのままコンパイル | アプリの入口から tokens.css へ実際に繋がっていることを見る。取り込みが失われたら落ちる |
| **密閉の層** | `@import "tailwindcss" source(none);` + tokens.css の取り込み + **`@source inline(...)` による utility 候補の明示供給**を組み立てる | アプリ全体の class 使用状況に依存せず、全トークン・全 utility を漏れなく見る |

> **`source(none)` は自動走査を止めるだけなので、候補を与えないと utility は 1 つも生成されない**。
> 密閉の層は `inventory.ts` から組み立てた utility 名の全件を `@source inline("…")` として
> 入力へ**注入**する。期待する名前の配列をテスト側に持つだけでは生成候補にならない。
> 注入した集合と検査する集合が同一であること自体を、テストの中で 1 つの値から作って担保する
> (2 か所に書かない)。ただし **1 つの値から作ると空振りも同時に起きる** — 組み立てが壊れて
> 候補が空になれば、注入も検査も一緒に空になって緑のまま通る。よって母集団全体が
> 0 件でないことに加えて、**区分ごと (色 / radius / ramp / hover) の非空**も確かめる。

検査するもの:

| 区分 | 層 | 内容 |
|---|---|---|
| A. CSS 変数 | 密閉 | `--color-*` / `--radius-*` / `--font-sans` が生成 CSS に現れ、値が **DESIGN.md 由来の期待値と一致**する |
| B. ramp utility | 密閉 | `text-display` / `text-h1` / … の 6 本が生成され、font-family / font-size / font-weight / line-height (+ letter-spacing) が DESIGN.md と一致する |
| C. 色 utility | 密閉 | 全色トークンについて `bg-*` / `text-*` / `border-*` が `var(--color-*)` を参照する形で生成される |
| D. variant | 密閉 | `hover:bg-primary-hover` / `hover:bg-tertiary-hover` が hover 時の背景色として解決する |
| E. radius utility | 密閉 | `rounded-sm` / `rounded-md` / `rounded-lg` が `var(--radius-*)` を参照する |
| F. 経路 | 経路 | 実 app.css の生成 CSS に、代表トークンが **DESIGN.md の値で**現れ、代表 utility 規則が生成される |
| G. 取り込みの規約 | — | `resources/css/app.css` の非空・非コメント先頭 2 行が `@import 'tailwindcss'` → `@import './tokens.css'` の順である (テキスト検査) |

> **F の代表トークンは「アンカー集合」であって全件ではない**。経路の層の生成物は
> アプリ側の class 使用状況に依存するため、全件の網羅は密閉の層が担う。
> この非対称をテストのコメントに明記する (「経路の層があるから全部見えている」と読ませない)。

> **G は順序も含めて固定する。ただしそれは動作の不変条件ではなくリポジトリ規約である**。
> 実測 (§前提の実測確認) では `@import` の順序を入れ替えても Tailwind v4 の生成物は壊れなかった。
> **取り込みを外すと壊れる**が**入れ替えても壊れない**。壊れ方の検出は経路の層 (F) が担い、
> G は「入口の 2 行はこの形で書く」という規約を固定する。テストの説明にこの区別をそのまま書き、
> 正典の説明 (順序契約違反 = 動作の破綻) をそのまま写さない。

### 2. `tests/js/styles/design-system-docs.test.ts` — 運用契約文書の検査 (新設)

正典 (aigenba) は「散文の完全一致フレーズ」を並べる形だが、**それは採らない**。
文言だけ残して実態を失う形骸化に弱く、文章の改善も妨げるためである。aicue は
**消えたら契約が失われる構造**だけを、次の 3 つに絞って見る:

1. **節の実在と本文の非空**: `docs/design-system.md` の運用契約 4 節
   (Canonical source の宣言 / トークン変更時の運用契約 / 新規 domain 色トークン追加の必須条件 /
   file-scoped allowlist の運用) が見出しだけでなく本文を持つこと
2. **表に並ぶパスの実在**: 「Canonical source の宣言」表のリポジトリ相対パスが実在すること
   (改名・移動で表だけが古くなる壊れ方を止める)
3. **責務境界表と実ファイルの集合一致 (双方向)**:

   ```
   実在する対象テストファイルの集合  =  文書の「検査の責務境界」表に載っているファイルの集合
   ```

   対象は `tests/js/styles/*.test.ts` と `tests/js/architecture/contrast-invariant.test.ts`。
   **片側だけでは足りない** — 実体 → 文書だけなら「検査を消したのに表の行が残る」が止まらず、
   文書 → 実体だけなら「検査を足したのに書かない」が止まらない。集合一致なら両方落ちる。
   表に対象外の行を混ぜたくなったら、行の種別を構造として分けてから足す
   (「関係ない行だから」で例外を作らない)

3 が本設計の要である。1・2 は文書側から実体を縛るだけだが、3 は**両側を縛る**。
散文の一致を見る検査より形骸化しにくい。

### 3. 既存 2 本との重複・整合の整理

3 本 + コントラスト検査の**責務境界を宣言として書き、機械で固定する**。

| 検査 | 何から何への写像を見るか |
|---|---|
| `canonical-source-parity` | DESIGN.md (正本) ⇔ tokens.css (写像) の**テキスト**一致 |
| `tokens.test` (新設) | tokens.css ⇒ **Tailwind 生成 CSS** (宣言がビルドへ届くか / utility 名が解決するか) |
| `contrast-invariant` | DESIGN.md の色値の**可読性** |
| `design-system-docs` (新設) | `docs/design-system.md` の**文書構造と検査目録の同期** (運用契約の意味が残っていることまでは見ない) |

**「重複がない」とは言わない。正しくはこうである**:

> 検査範囲は一部重なるが、責務となる**写像の段**が異なる。トークンの値を変えれば
> parity と `tokens.test` の双方が赤になり得る一方、Tailwind の解釈が壊れる形
> (`@theme` が効かなくなる等) は生成 CSS を見る側だけが検出する。

どちらを削っても、「生成物はあるが正本と値が違う」「値は合っているが Tailwind に解釈されない」の
どちらかが見えなくなる。詳細設計では各検査の**代表的な red ケース**を表で固定し、この境界を実証する。

さらに「DESIGN.md frontmatter の各節が、どの検査の担当か」を `inventory.ts` に
**既定拒否で宣言**する。未分類の節があれば落とす。これは既に `contrast-invariant` が
色トークンの役割分類で使っている作法と同じで、この宣言を書くと現状の**未検査の穴が 1 つ露出する** —
frontmatter の `spacing:` (xs/sm/md/lg/xl) は tokens.css に写像が無く、どの検査も見ていない。
本バッチではこれを **PENDING (未検査であることの明示宣言)** として登録し、値の是正は行わない。
PENDING は無期限の逃げ道になりやすいので、**理由・解消条件・追跡先の 3 つを型で必須**にし、
埋めずには登録できない形にする。**追跡先は空文字でないことだけでは足りない** —
`docs/TODO.md` の課題番号か `devnotes/<dir>/` のどちらかの形であることをテストで確かめ、
**指し先が実在することも見る** — 課題番号なら `docs/TODO.md` / `docs/TODO-closed.md` の表に
その行があること、ディレクトリならそれが実在すること (ファイルがあるだけ・書式が整っているだけの
死んだ参照を作らせない)。

色トークンの母集団は**既存 `inventory.ts` の分類をそのまま正本として使う**
(`COLOR_TOKEN_MAP` = DESIGN.md 由来 / `DERIVED_COLOR_TOKENS` = tokens.css 固有の派生)。
両者の和が tokens.css の `--color-*` 全件と一致することは既に `canonical-source-parity` が
集合一致で固定しているので、そこから導いた母集団は定義上の全件になる。
派生トークン (`--color-primary-soft`) だけは DESIGN.md に期待値が無いため
**値の検査を理由付きで免除**し、生成 CSS への出現までを見る。

## 実装方針（概要）

**「値の写しを増やさない」を最優先の制約とする。** 正典 (aigenba) の `tokens.test.ts` は
`inventory.ts` に色 14 件の hex と utility 21 対を **literal で持つ** 形だが、aicue でそれを
そのまま真似ると DESIGN.md / tokens.css に続く **3 つ目の値の写し**ができる。
AGENTS.md が繰り返し書いている「2 か所に書くと必ず食い違う」に正面から反するため、aicue では

- **期待値は `design-md.ts` の共有パーサ経由で DESIGN.md から導出する**
- **utility 名は既存 `inventory.ts` の token 集合から機械的に組み立てる**
  (`bg-<suffix>` / `text-<suffix>` / `border-<suffix>` / `rounded-<段>` / `text-<ramp>`)

とする。結果として **新しい値の表を 1 つも足さずに** 正典と同じ不変条件を張れ、
トークンを増やしたときの検査漏れも構造的に起こらない (新トークンは自動で検査対象に入る)。

この差は `docs/template-divergence.md` へ **D27 (同一不変条件・別実装)** として登録する。
登録本文では「値の写しを避けた」だけを理由にせず、**t1 の不変条件を 1 つずつ列挙して、
どれをどの検査が満たすかを対応付ける**。「正典以上」という比較の言い方はしない。

| t1 の不変条件 | 正典 (aigenba) の満たし方 | aicue の満たし方 |
|---|---|---|
| 生成 CSS に token 変数が期待値で現れる | inventory の literal 表と突き合わせ | DESIGN.md から導出した期待値と突き合わせ |
| 生成 CSS に ramp / 色 / hover utility が現れ token を参照する | 文字列正規表現 | postcss AST 走査 |
| 検査がアプリ全体の class 変動から独立している | 静的 fixture (実測では**この目的を満たしていない**) | `source(none)` + `@source inline` で候補を明示供給 |
| app.css の取り込みが宣言されている | 先頭 2 行のテキスト検査 | 同左 (G) + **実 app.css のコンパイル検査 (F)** |
| 運用契約文書が空にならない | 散文の完全一致フレーズ | 節・表・パス・対象集合の構造検査 |

**正典の literal 表が持つ「DESIGN.md とは独立に値を pin する」性質は、t1 の不変条件ではなく
正典実装の副次的な性質として扱う**。根拠は台帳の裁定文そのもので、
「**揃えるべきは検査の仕組みであり、テーマ値やデザインシステムの中身はプロジェクト別
カスタマイズ点で drift ではない**」と書かれている。値そのものを別表で pin することは
「テーマ値を揃える」側に属するので t1 が求めているものではない。
よって D27 の分類は **同一不変条件・別実装**で矛盾しない。

念のため、aicue が独立 pin を採らない理由も書いておく: 本リポジトリでは DESIGN.md が唯一の正本であり、
その値の変更は「気付くべき事故」ではなく**正規の変更手順**である。値を変えるたびに 2 か所直させる形は
正本の一元化という上位の設計判断と衝突する。変更が意図されたものかは PR の差分と
`docs/design-system.md` の運用契約が見る。

| # | 施策 | 主な変更 | 優先度 |
|---|---|---|---|
| 1 | `tokens.test.ts` 新設 (経路の層 + 密閉の層の 2 層) | `tests/js/styles/tokens.test.ts` (新規) | 高 |
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
- 実物の `app.css` のコンパイルは **832ms / 60,726 文字**で完了する (テストとして許容範囲)
- **負のコントロールが点灯する**: app.css から `@import './tokens.css'` を外すと
  `--color-primary` も `.bg-primary` も `.text-body` も生成 CSS から消える
- **`@import` の順序を入れ替えても壊れない** (Tailwind v4 は入れ替え後も同じ変数と utility を出した)。
  よって順序は規約であって壊れ方の検出点ではない (§改善アイデア 1 の G に反映)
- **Tailwind 既定テーマと名前が衝突する**トークンがある。tokens.css を外しても
  `--radius-sm/md/lg` は既定値 (`0.25rem` / `0.375rem` / `0.5rem`) として残り、`--font-sans` も残る。
  **したがって「存在するか」だけの検査では radius と font は空振りする** — 必ず**値**を突き合わせる
- `@tailwindcss/postcss` は **`from` に渡したパス単位で結果をキャッシュ**する。
  同じ `from` で入力を変えると前回の結果が返る (この落とし穴で最初の負のコントロールが空振りした)。
  実装でも「1 つの `from` は 1 つの入力」に対応させる

### テストファースト: 先に確認する Red (AGENTS.md 思考原則 5)

実装前に次が **fail することを確認**してから着手する (「fail を見ていない gate」は空振りに気付けない)。

| # | Red の作り方 | 何が示せるか |
|---|---|---|
| R1 | `resources/css/app.css` から `@import './tokens.css'` を外す | 経路の層が実 import 経路を見ていること |
| R2 | `tokens.css` の `@theme { … }` を素の `:root { … }` に書き換える | 密閉の層が「Tailwind の**テーマとして**解釈されているか」を見ていること。**変数そのものは生の CSS として出力に残る**。赤になるのは — **C / D**: 独自の色テーマが失われるので対象 utility が生成されない。**E**: `rounded-*` 自体は Tailwind 既定テーマから生成され得るが、値が既定 (`0.25rem` 等) になり `var(--radius-*)` 参照でなくなるので赤になる。どの assertion が赤になるかは詳細設計で固定する |
| R3 | `tokens.css` の `@utility text-body { … }` を消す | ramp utility の生成を見ていること |
| R4 | `tokens.css` の `--color-danger` の値だけを変える | 値が DESIGN.md 由来の期待値と突き合わされていること (parity も同時に赤になる = 段の違いを示す) |
| R5 | `docs/design-system.md` から運用契約の 1 節を丸ごと消す | 文書検査が節の実在と本文を見ていること |
| R6 | `tests/js/styles/` に検査ファイルを 1 本足して文書の表に書かない | 実体 → 文書の既定拒否が効いていること |

**R1〜R3 は既存 2 本 (parity / contrast) では赤にならない** ことを併せて確認する。
**R4 は parity と新規テストの双方が赤になる** — 同じ「値の不一致」を違う段
(正本 ⇔ 宣言 / 宣言 ⇒ 生成物) で検出しているためで、重複ではない。
詳細設計の red 表にこの対応をそのまま書く。

## 期待効果

- **使命への貢献**: 「正本に書いた色・文字サイズが Tailwind のコンパイルを通って出てくる」ことが
  機械で閉じる。色が消える / 状態色が壊れる形の事故は、現場で撮影中の作業者に直撃する。
  ただし**画面に届くところまでは保証しない** — Vite のビルド・アセット配信・ブラウザでの適用は
  対象外であり、「最後の一区間が閉じた」とは書かない
- **既存の穴が 2 つ埋まる**: (a) ramp の font-family がどこからも検査されていない、
  (b) app.css の取り込み順序契約が文書にしか無い
- **未検査の穴が 1 つ可視化される**: frontmatter の `spacing:` に写像が無いこと
- **家系との整合**: 台帳 `gates:` が宣言する 5 本が全て揃い、aicue の版が t0 → t1 になる

## 制約・前提

- 依存追加は無い (`postcss` `@tailwindcss/postcss` `tailwindcss` は既に devDependencies)
- postcss の AST 走査は **postcss の公開型 (`Root` / `Rule` / `Declaration`) を使い**、
  型アサーションや非 null 断定で黙らせない。コンパイルの失敗は `beforeAll` で握り潰さず
  そのままテストの失敗として伝播させる
- vitest の include は `tests/js/**/*.test.ts` なので `scripts/test-inventory-config.ts` の変更は不要
- Tailwind のコンパイルが 1 ファイルにつき 1 回走る。`beforeAll` に十分な timeout を置く
- TypeScript 必須 (JS を新規に足さない)。`tsconfig.json` の include は `tests/js/**/*.ts` を含む
- 検査の追加であって**トークンの値は 1 つも変えない** (色・サイズ・角丸の是正は本バッチの対象外)
- **共有パーサ (`design-md.ts`) の空振りを限定的に防ぐ。キーの取りこぼしは集合一致で検出するが、
  値の誤解析は共通障害として残る**。3 本が同じパーサを使うため、
  パーサが degrade すると同じ誤った期待値で全部が緑になりうる。ただしその形は現状すでに
  塞がっている — `canonical-source-parity` はパーサの出力キー集合を `inventory.ts` の
  宣言と**集合一致**で突き合わせるので、取りこぼしがあれば赤になる。
  本バッチはこの構造に乗り、`tokens.test` 側にも**母集団が 0 件でないこと**の空振り防止を置く。
  パーサを純関数へ切り出して独立の unit test を足すことは**しない** (思考原則 2。
  既存の集合一致が同じ役割を果たしているため)。
  **ただし集合一致が守るのはキーの網羅性であって、値の誤解析一般ではない** —
  例えばパーサが値の末尾を取りこぼす形の誤りは、3 本すべてが同じ誤った値を見るので
  検出できない。「共通障害点ではない」とは書かず、この残余をテストのコメントに明記する
- `docs/design-system.md` の**散文は検査しない**。見る対象は節・表・パス・対象集合という
  **構造だけ**にする (文章の言い回しを直す PR がテストで止まらないようにするため)

## スコープ外 (と、その申し送り)

- **`spacing:` トークンの実装写像を作ること**。frontmatter に xs〜xl が宣言されているが
  tokens.css に `--spacing-*` は無い。「Tailwind 既定の spacing で足りているから写像を作らない」のか
  「作り忘れ」なのかは設計判断であり、値を弄る前に決めるべき事柄なので別バッチとする
  (本バッチは未検査であることの明示宣言までを行う)
- **派生トークン `--color-primary-soft` の値検査**。DESIGN.md frontmatter に無い rgba 値であり、
  現状 parity は集合にしか現れない (値の正本は tokens.css)。本バッチは生成 CSS への
  「存在」までを見て、値の正本化は別途とする
- **WCAG 1.4.11 (非テキスト 3:1) / alpha 合成ペア**。既存 `PENDING_CONTRAST_PAIRS` のまま据え置く
- **Vite / `pnpm build` を通した配信アセットの検査**。本バッチは postcss + Tailwind の
  コンパイルを通すところまでとする (実 app.css を入力にする経路の層は本バッチに**含む**。
  これは build 検査の先取りではなく、取り込み経路を保証する最小範囲である)
- **メールテンプレート (`resources/views/vendor/mail/html/themes/template.css`)**。
  CSS 変数を使えないクライアント向けの独立パレットであり DS token の写像ではない
- 台帳への `append_event` は行わない (実装完了後に監督側が一括で行う)
