# Round 2: Round 1 指摘への対応

Round 1 の指摘 (Critical 3 / Warning 12 / Suggestion 2) はすべて対応しました。反論はありません。
Critical 3 件は実測 (probe-scope-and-r2.mjs) で指摘が正しいことを確認してから直しています。

## 追加の実測結果 (Round 1 の指摘を受けて取ったもの)

- 正常時のテーマ変数はすべて `@layer theme > :root, :host` の 1 か所に出る (18 件)
- `@theme` を `:root` に書き換えたとき:
  - 全走査: `--color-primary` = `#2563eb` / `--radius-md` = `6px` (= 緑で通ってしまう)
  - `@layer theme` 限定: `--color-primary` = undefined / `--radius-md` = `0.375rem` (既定値) / `--font-sans` = 既定 (= 赤くなる)
  - `.bg-primary` → 規則なし / `.hover\:bg-primary-hover` → 規則なし
  - `.rounded-md` → `border-radius: var(--radius-md)` **のまま** (= E は緑)
  - `.text-body` → 4 プロパティ **のまま** (= B は緑)
- hover の宣言位置: `@layer utilities > .hover\:bg-primary-hover > &:hover > @media (hover: hover)`
- `config/app.php` の timezone は `UTC` で、ledger の基準日は `CarbonImmutable::today()` (= 指摘のとおり 2026-08-18 は未来日)

全体判定を再度出してください (APPROVED / CHANGES_REQUESTED)。

---

# 対応マトリクス: design-review Round 1

指摘はすべて **対応する**。反論は 1 件も無い。
うち 3 件の [Critical] は、実測 (`probe-scope-and-r2.mjs`) で指摘が正しいことを確かめてから直した。

## 施策 1

### [Suggestion] 「書式変更時は集合一致が赤になる」の表現が強い

- 判断: 対応する
- 対応内容: 「抽出結果が変わり集合一致で気付ける**ことが多い**が、
  別の最上位らしい文字列を拾う誤解析まで防げるわけではない」に弱めた。

## 施策 2

### [Warning] `COMPILED_VALUE_EXEMPT_TOKENS` と `DERIVED_COLOR_TOKENS` の集合関係が未固定

- 判断: 対応する（提案の前者 = 派生色は全件値免除、を契約にする）
- 対応内容: 「**派生トークンは全件が値免除である**」を契約として宣言のコメントに書き、
  施策 4 に `Object.keys(COMPILED_VALUE_EXEMPT_TOKENS)` と `DERIVED_COLOR_TOKENS` の
  集合一致テストを追加した。免除の理由が 30 文字以上あることも同時に見る。

### [Warning] `rounded` / `typography` を checked と宣言しながら子の母集団が未固定

- 判断: 対応する
- 根拠: 指摘のとおり。`typography.subtitle` や `rounded.xl` を DESIGN.md に足しても
  固定配列 (`TYPOGRAPHY_RAMPS` / `RADIUS_TOKENS`) に入らず見逃す。
  「checked と宣言しているのに全項目を見ていない」は説明として嘘になる。
- 対応内容: 施策 1 に `designTypographyNames()` を追加し、施策 4 に
  **「検査の母集団」describe** を新設して 3 つの集合一致を固定した。
  1. DESIGN.md typography の子キー ⇔ `TYPOGRAPHY_RAMPS`
  2. tokens.css の `@utility text-*` ⇔ `TYPOGRAPHY_RAMPS`
  3. DESIGN.md rounded のキー ⇔ `RADIUS_TOKENS`
  併せて `kind: "checked"` の意味を「**担当がいる**ことを表すのであって全項目網羅の主張ではない。
  網羅は母集団の集合一致が別に固定する」と型のコメントに明記した (施策 4 の [Warning] への対応も兼ねる)。

### [Suggestion] `satisfies readonly string[]` は綴りを検証しない

- 判断: 対応する
- 対応内容: `satisfies` を外して `readonly string[]` の型注釈にし、
  「compile-time に typo を検出する」という説明を削除した。

## 施策 3

### [Critical] `cssVariables()` の走査範囲が広すぎ、`@theme` 由来を保証していない

- 判断: 対応する
- 根拠: **実測で指摘が正しいことを確認した**。`@theme` を `:root` に書き換えると、
  全走査では `--color-primary` が `#2563eb` のまま取れて**緑で通る**。
  `@layer theme` に限定すると `undefined` になり赤くなる。
  正常時のテーマ変数は `@layer theme > :root, :host` の 1 か所だけに出ている (18 件)。
- 対応内容: `cssVariables()` を **`themeVariables()`** に作り替えた。
  - `@layer theme` の at-rule 配下だけを見る
  - その**直接の子**である `:root` / `:host` の規則だけを見る (`rule.parent !== layer` で入れ子を除く)
  - 規則の**直接の子宣言**だけを取る
  - 同名で値の違う宣言があれば `conflicts` に集め、**空であることをテストが確かめる**
  - 絞り込みの理由をファイル冒頭の「走査範囲を絞る理由 (重要)」に書いた

### [Critical] `declarationsOf()` が同一 selector の全規則と全子孫を混ぜている

- 判断: 対応する
- 対応内容: 2 つに分けた。
  - `utilityRules()`: selector 完全一致の規則の**直接の子宣言**を**出現ごと**に返す
  - `soleRule()`: 出現がちょうど 1 件であることを確かめてから返す (0 件も重複もここで落ちる)
  - `hoverDeclarations()`: 外側規則の中の **`&:hover` 入れ子**を明示的に辿ってから宣言を拾う
    (`&:focus` の中は拾わない)。`@media (hover: hover)` は Tailwind の実装詳細なので契約にせず、
    その中の宣言は拾う (指摘の許容範囲どおり)
  - 併せて C / E / B は**キー集合ごと**一致させる形にした (余分なプロパティも検出する)

### [Critical] R2 の割り当てが誤っている

- 判断: 対応する
- 根拠: 実測の結果は指摘とほぼ一致した。特に **E は緑**である
  (`.rounded-md` は既定テーマから `border-radius: var(--radius-md)` を出し続ける)。
  「E は `var(--radius-*)` でなくなる」という旧記述は誤りだった。
- 対応内容: R2 の行を実測どおりに書き直した。
  - 赤: **A の色** (`@layer theme` に出ない) / **A の radius** (既定の `0.375rem` になる) /
    **A の font** (既定の先頭 family になる) / **C** / **D** / **F**
  - 緑: **B** (`@utility` は残る) / **E** / **G**
  - 「A をテーマ層限定にしたからこそ A が赤くなる」ことを R 表の下に注記した

### [Warning] B が「4 プロパティを持つ」だけで余分を検出しない

- 判断: 対応する
- 対応内容: 宣言の**キー集合ごと**の一致に変えた。DESIGN.md に `letterSpacing` が無い ramp に
  `letter-spacing` を勝手に足した場合も赤になる。テスト名も
  「宣言が DESIGN.md と過不足なく一致する」に変えた。

### [Warning] font-family は先頭 family しか比較していないのに保証が強い

- 判断: 対応する（先頭 family に限定する側を選ぶ）
- 根拠: DESIGN.md は `"Noto Sans JP, sans-serif"`、tokens.css は 10 個近いフォールバック列で、
  そもそも全体一致はしない。DESIGN.md 側がフォールバック列の正本を持っていない。
- 対応内容: テスト名を「`--font-sans` の**先頭 family**と一致する」に変え、
  ファイル冒頭の「保証しないもの」と D27 の「見ていないもの」に
  「font-family の先頭以外のフォールバック列」を明記した。

### [Warning] G の行ベースのコメント除去が複数行コメントを誤解析する

- 判断: 対応する
- 対応内容: 行の走査をやめ、`postcss.parse()` した AST の**先頭ノード**を見る形にした。
  コメントノードを除いた先頭 2 つが `@import` の at-rule であり、params が
  `tailwindcss` / `./tokens.css` であることを確かめる。

## 施策 4

### [Warning] TODO の `toContain` は表の実在を保証しない

- 判断: 対応する
- 対応内容: `docs/TODO.md` / `docs/TODO-closed.md` の各行を
  `/^\|\s*(T\d{3,})\s*\|/` で走査して **ID 列だけ**を集合にし、`Set#has` で完全一致させる。
  ID が 1 件も取れなかったら抽出の空振りとして落とす。

### [Warning] `checked.by` は網羅を保証しない

- 判断: 対応する（施策 2 の母集団修正で実体を直し、記述も分けた）
- 対応内容: 上記「検査の母集団」describe を新設して網羅を実際に固定し、
  型のコメントで「`checked` は担当がいることを表すだけ」と区別を明記した。

## 施策 5

### [Warning] `contrast-invariant.test.ts` の実在を確かめていない

- 判断: 対応する
- 対応内容: `EXTERNAL_GATE_FILES` という定数に分け、`statSync(...).isFile()` を確かめてから
  母集団へ入れる形にした。ファイルを消して文書の行を残した場合はここで落ちる。

### [Warning] `listed` が節全体のバッククォート文字列を拾っている

- 判断: 対応する
- 対応内容: `tableCellLiterals(section, column)` を新設し、
  **表の行のセル**だけを見る形にした (`|` 始まりの行をセル分割して指定列の最初のバッククォートを取る)。
  Canonical source 表は 2 列目、責務境界表は 1 列目を見る。
  散文に同じフルパスを書いても通らない。

### [Warning] 自動検出できる範囲が `tests/js/styles/` 直下だけ

- 判断: 対応する
- 対応内容: ファイル冒頭の「保証しないもの」に
  「自動で母集団に入るのは `tests/js/styles/` 直下と `EXTERNAL_GATE_FILES` に明示登録した分だけ。
  別の場所へ足しても自動では見つからない」と書いた。

## 施策 6

### [Warning] 「デザイントークンの検査は 4 本ある」が完全な一覧と読める

- 判断: 対応する
- 対応内容: 「**本節で責務境界を管理する**デザイントークン検査は 4 本ある
  (DS purity 系など、トークンの値以外を見る検査は本節の管理対象ではない)」に書き換えた。

### [Warning] 「spacing はどの検査も見ていない」が曖昧

- 判断: 対応する
- 対応内容: 「`spacing:` は**値も tokens.css への実装写像の有無も検査していない**」に限定した。

### [Warning] 表の説明が施策 3・5 の修正後でないと成立しない

- 判断: 対応する
- 対応内容: 施策 3 のテーマ層限定と施策 5 の実在確認を先に直した上で、
  表の文言を確定させた (「`@theme` が解釈されない」は themeVariables の限定によって実際に成立する)。

## 施策 7

### [Warning] D27 の保証が実装より強すぎる

- 判断: 対応する
- 対応内容: 「揃え続ける不変条件」を
  「**inventory に登録された DESIGN.md 対応の**色・角丸・文字組が、生成 CSS に同じ値で現れ、
  対応する utility 名がその変数へ解決すること」に限定した。
  併せて「見ていないもの」節を新設し、`--color-primary-soft` の値 (出現のみ) /
  font-family の先頭以外 / 生成 CSS より先 を明記した。
  母集団の取りこぼし (typography / radius) は施策 4 の集合一致で塞いだので、
  「固定 inventory に未登録の新しい項目」は除外事項から外れた。

### [Warning] `決めた日 | 2026-08-18` が未来日

- 判断: 対応する
- 根拠: 確認したところ `TemplateDivergenceLedgerFormatTest` は `CarbonImmutable::today()` を
  基準日にしており、`config/app.php` の timezone は **UTC** である。
  日本時間 2026-08-18 02:48 は UTC ではまだ 2026-08-17 なので、指摘のとおり未来日になる。
- 対応内容: `2026-08-17` に直し、施策 7 の冒頭に
  「日本時間の深夜に実装すると UTC ではまだ前日である。実装時点の UTC 日付を入れること」
  という注意を書いた。

## 実装順序

### [Warning] 施策 3〜5 は通常の Red-Green にならない

- 判断: 対応する
- 根拠: 指摘のとおり。`tokens.css` の現在の中身は正しいので、施策 3・4 のテストは
  書いた瞬間に緑になる。
- 対応内容: §実装順序 を書き直し、**通常の Red-Green が成り立つもの (施策 5・6) と
  感度確認 (故障注入) で確かめるもの (施策 3・4)** に分けた。手順は提案どおり
  「テストを追加 → 基準結果を確認 → 故障を 1 件ずつ注入 → 狙った assertion の失敗を確認 →
  必ず戻して緑を確認」とし、記録は
  `red-verification.md` に**「想定した assertion」と「実際に落ちた assertion」を別の列で**残す、
  食い違ったら実測を正本にして設計を直す、と明記した。

---

## 修正後の詳細設計書 (全文)

# 詳細設計: design-token-t1-tests (機能 `design-token-system` の正典 t1 追従)

> Round 1 のレビューで **AST 走査の範囲が広すぎる**という [Critical] を受け、
> テーマ変数の収集を `@layer theme` の `:root, :host` の**直接の子**に限定する形へ作り替えた。
> R2 の予測と保証の記述も実測に合わせて直してある。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を
生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

> 本バッチは **フロントエンドの検査とドキュメントのみ**で、PHP / DB / LLM / 課金のいずれにも触れない。
> 4〜7 は構造的に該当しない。

### コーディングルール

- **TypeScript strict** 必須（`pnpm typecheck`）。`any` / 非 null 断定 / 型アサーションで黙らせない
- **vitest**（`pnpm test`）。既存 include (`tests/js/**/*.test.ts`) に自動で入るので
  `scripts/test-inventory-config.ts` の変更は不要
- **JavaScript を新規に足さない**（新規テストは `.ts`）
- コメントは日本語。書式は既存テストに揃える（4 スペースインデント・ダブルクォート）
- `docs/template-divergence.md` を触るため `TemplateDivergenceLedgerFormatTest` が回る →
  **`composer test` は必ず流す**

## 概念設計リファレンス

- `devnotes/20260818-0248-design-token-t1-tests/conceptual-design.md`（APPROVED / Round 5）
- 設計時の実測: 同ディレクトリの `probe-tailwind-compile.mjs` / `probe-appcss-compile.mjs` /
  `probe-utility-props.mjs` / `probe-scope-and-r2.mjs`（一時スクリプト。`scripts/` へ昇格しない）

### 実測で確定した前提（設計の土台）

| # | 事実 |
|---|---|
| P1 | テーマ変数は **`@layer theme` 直下の `:root, :host` 規則**に出る（実測: 該当の 18 件がすべてこの 1 か所） |
| P2 | `@theme` を素の `:root` に書き換えると、**変数は生の CSS として残る**が `@layer theme` からは消える。全走査だと `--color-primary` が `#2563eb` のまま取れてしまう |
| P3 | Tailwind 既定テーマが `--radius-sm/md/lg` と `--font-sans` を持つ。`@theme` が壊れても `.rounded-md` は `border-radius: var(--radius-md)` を出し続ける（**値が既定の `0.375rem` に変わる**） |
| P4 | hover の宣言は `@layer utilities > .hover\:bg-primary-hover > &:hover > @media (hover: hover)` の位置に出る |
| P5 | `@import "tailwindcss" source(none)` + `@source inline(...)` で密閉できる（7,682 文字 / アプリ由来の class は 0 件）。候補を与えないと utility は 1 つも出ない |
| P6 | 実 app.css のコンパイルは 832ms。`@import './tokens.css'` を外すと `--color-primary` / `.bg-primary` / `.text-body` が消える |
| P7 | `@import` の**順序**を入れ替えても生成物は壊れない |
| P8 | `@tailwindcss/postcss` は `from` パスを鍵に結果をキャッシュする |
| P9 | 生成される宣言: `.bg-*`→`background-color` / `.text-*`(色)→`color` / `.border-*`→`border-color` / `.rounded-*`→`border-radius` / `.text-<ramp>`→`font-family; font-size; font-weight; line-height` (+`letter-spacing`) |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 共有パーサに節名・ramp 名の取り出しを足す | `tests/js/styles/design-md.ts` | 高 (2・4 の前提) |
| 2 | inventory へ宣言を足す | `tests/js/styles/inventory.ts` | 高 (3・4 の前提) |
| 3 | `tokens.test.ts` の新設（経路の層 + 密閉の層） | `tests/js/styles/tokens.test.ts` (新規) | 高 |
| 4 | 母集団の集合一致と担当宣言を parity に足す | `tests/js/styles/canonical-source-parity.test.ts` | 高 |
| 5 | `design-system-docs.test.ts` の新設 | `tests/js/styles/design-system-docs.test.ts` (新規) | 中 |
| 6 | `docs/design-system.md` に §検査の責務境界 を新設 | `docs/design-system.md` | 中 (5 の前提) |
| 7 | 逸脱登録 D27 | `docs/template-divergence.md` | 高 (3 と同一 PR) |

### 波及変更（全施策まとめ）

- TypeScript 型定義: 追加は `tests/js/styles/inventory.ts` 内で閉じる（`resources/js` 側は無変更）
- API Resource / DTO / Inertia Props / Svelte component: **すべて無し**（サーバ側もトークンの値も触らない）
- テストファイル: 新規 2 本 + 既存 1 本（`canonical-source-parity.test.ts`）へ追記
- ビルド設定 / 依存: **なし**（`postcss` `@tailwindcss/postcss` `tailwindcss` は既に devDependencies）

---

## 実装順序（感度確認を含む）

**通常の Red-Green が成り立つ施策と、成り立たない施策がある**ので分けて進める。
`tokens.css` の現在の中身は既に正しいので、施策 3・4 のテストは**書いた瞬間に緑**になる。
「fail を見ていない gate」を避ける手段は、**故障を 1 件ずつ注入して狙った assertion が
赤くなることを確かめる感度確認**である（AGENTS.md 思考原則 5 の趣旨をこの形で満たす）。

1. 施策 1・2（共有パーサと宣言）を入れる。`pnpm typecheck` が通ることだけ確認
2. 施策 5 のテストを書く → **本物の Red**（`docs/design-system.md` に §検査の責務境界 がまだ無い）
3. 施策 6（文書）を入れて 2 が緑になることを確認
4. 施策 3・4 を書いて緑を確認する（= 基準結果）
5. **感度確認**: R1〜R6 の故障を 1 件ずつ注入し、狙った assertion が落ちることを確認して**必ず戻す**
6. 施策 7（D27）を入れて `composer test` が緑になることを確認

感度確認の記録は `devnotes/20260818-0248-design-token-t1-tests/red-verification.md` に、
**「想定した assertion」と「実際に落ちた assertion」を別の列で**残す
（食い違ったら設計の理解が誤っていたということなので、実測を正本にして本書を直す）。

### 注入する故障と、落ちるはずの assertion

| # | 注入する故障 | 落ちるはずの assertion | 落ちないはずのもの |
|---|---|---|---|
| R1 | `resources/css/app.css` から `@import './tokens.css'` を消す | **F**（経路の層のアンカー 4 件 / `.bg-primary`）+ **G**（先頭 2 行） | 密閉の層 (A〜E) は tokens.css を直接取り込むので緑 |
| R2 | `tokens.css` の `@theme {` を `:root {` に変える | **A の色**（`@layer theme` に出なくなる）/ **A の radius**（既定の `0.375rem` になる）/ **A の font**（既定の先頭 family になる）/ **C**（色 utility が生成されない）/ **D**（hover が生成されない）/ **F**（同上） | **B は緑**（`@utility` は残る）/ **E も緑**（`.rounded-md` は既定テーマから `var(--radius-md)` を出し続ける）/ **G も緑** |
| R3 | `tokens.css` の `@utility text-body { … }` を消す | **B の text-body**（規則が 1 件でない）+ 施策 4 の `@utility text-*` 集合一致 | 他の ramp は緑 |
| R4 | `tokens.css` の `--color-danger` の値だけ変える | **A の danger** + **既存 `canonical-source-parity` の value parity** | 同じ値の不一致を 2 つの段で見ている（重複ではない） |
| R5 | `docs/design-system.md` から `## file-scoped allowlist の運用` を消す | 施策 5 の節検査 | 他は緑 |
| R6 | `tests/js/styles/` にダミーの `.test.ts` を置く | 施策 5 の集合一致 | 他は緑 |

> **R2 の予測は実測に基づく**（`probe-scope-and-r2.mjs`）。A を「全走査」で実装すると
> `--color-primary` が `#2563eb` のまま取れて**緑になってしまう** — これが
> 「`@layer theme` の `:root, :host` に限定する」設計の理由である。

---

## 施策 1: 共有パーサに節名・ramp 名の取り出しを足す

### 変更箇所

- ファイル: `tests/js/styles/design-md.ts`（末尾に 2 関数を追加。既存の export は触らない）

### 波及変更

- テストファイル: 施策 4 が両関数を使う
- TypeScript 型定義 / API Resource / DTO: なし

### 変更後コード（追記分）

```ts
/**
 * frontmatter の**最上位の節名**を宣言順で返す。
 *
 * 「どの節がどの検査の担当か」を既定拒否で宣言するための入力
 * (tests/js/styles/inventory.ts の FRONTMATTER_SECTION_OWNERS)。
 * 入れ子の子キー (typography.display 等) は含めない — 担当の宣言は節の粒度で行う。
 *
 * 保証範囲: 行頭から始まるキーだけを最上位として拾う。frontmatter の書式が変わったときは
 * 抽出結果が変わり、担当宣言との集合一致で気付ける**ことが多い**が、
 * 別の最上位らしい文字列を拾う形の誤解析まで防げるわけではない。
 */
export function designFrontmatterSections(): readonly string[] {
    const sections: string[] = [];
    for (const m of frontmatter.matchAll(/^([a-zA-Z][a-zA-Z0-9-]*):/gm)) {
        sections.push(m[1]);
    }
    return sections;
}

/**
 * frontmatter `typography:` の**子キー**(ramp 名) を宣言順で返す。
 *
 * TYPOGRAPHY_RAMPS (検査側の母集団) と集合一致させるための入力。
 * これが無いと、DESIGN.md に ramp を足しても検査側の固定配列に入らず見逃す。
 */
export function designTypographyNames(): readonly string[] {
    const section = frontmatter.match(/^typography:\n((?: {4}\S[^\n]*\n| {8}\S[^\n]*\n)+)/m);
    if (!section) throw new Error("DESIGN.md typography section not found");
    const names: string[] = [];
    for (const m of section[1].matchAll(/^ {4}([a-zA-Z][a-zA-Z0-9-]*):$/gm)) {
        names.push(m[1]);
    }
    return names;
}
```

### TypeScript 適合チェック

- [x] 戻り値の型が明示されている（`readonly string[]`）
- [x] `any` / 非 null 断定を使っていない
- [x] `section` の `null` は `throw` で潰す（既存 `designColors()` と同じ作法）

### テスト計画

- [ ] 施策 4 が両関数の出力を母集団として使う（関数専用のテストは持たない = 使われることが検査になる）
- [ ] 空振り防止として「節が 0 件でない」「ramp 名が 0 件でない」を施策 4 に置く

### リスク

- `designTypographyNames()` の正規表現は「4 スペースのキーで値を持たない行」を ramp 名とみなす。
  frontmatter で `typography:` の直下に**値付きのキー**を足すと拾わない。
  その場合は施策 4 の集合一致が赤になるので黙って通らない

---

## 施策 2: inventory へ宣言を足す

### 変更箇所

- ファイル: `tests/js/styles/inventory.ts`（末尾に追記。既存の export は 1 つも変えない）

### 波及変更

- テストファイル: 施策 3・4 が読む

### 変更後コード（追記分）

```ts
/*
 * ===== 生成 CSS 検査の入力 (tokens.test.ts) =====
 */

/**
 * tokens.css が持つ `--color-<suffix>` の全件。
 *
 * COLOR_TOKEN_MAP (DESIGN.md 由来) と DERIVED_COLOR_TOKENS (tokens.css 固有の派生) の和。
 * これが tokens.css の `--color-*` 全件と一致することは canonical-source-parity の
 * 集合一致テストが固定しているので、この配列は「定義上の全件」である。
 */
export const CSS_COLOR_SUFFIXES: readonly string[] = [
    ...Object.values(COLOR_TOKEN_MAP),
    ...DERIVED_COLOR_TOKENS,
];

/**
 * 生成 CSS で**値**の一致を検査しないトークン (理由必須)。
 *
 * 契約: **派生トークンは全件が値免除である** (DESIGN.md に期待値が無いため)。
 * キー集合が DERIVED_COLOR_TOKENS と一致することを canonical-source-parity が固定する
 * = 派生トークンを足したのに「値も見ていない・免除にも入っていない」状態を作れない。
 * 免除しているのは**値だけ**で、生成 CSS への出現は検査する。
 */
export const COMPILED_VALUE_EXEMPT_TOKENS = {
    "primary-soft":
        "DESIGN.md frontmatter に現れない派生トークン (rgba)。期待値を正本から導出できないため" +
        "値の突き合わせは行わず、生成 CSS への出現までを検査する。値の正本は tokens.css で、" +
        "集合としての存在は canonical-source-parity が固定している",
} as const;

/**
 * 経路の層 (実 app.css のコンパイル) で**必ず現れることを求める**トークン。
 *
 * これは**アンカー集合であって全件ではない**。経路の層の生成物はアプリ側の class 使用状況に
 * 依存するため、全件の網羅は密閉の層が担う。ここに並べるのは画面の土台
 * (面・本文・主 CTA) が使う 4 件に限る
 * (実測の使用回数: bg-primary 17 / text-text 106 / bg-surface 47 / bg-neutral 35)。
 *
 * **アンカーが使われなくなったときの直し方**: テストを緩めるのではなく、
 * 土台に相当する別のトークンへ差し替える (集合を縮めて緑にしない)。
 */
export const ROUTE_LAYER_ANCHOR_TOKENS = ["primary", "text", "surface", "neutral"] as const;

/*
 * ===== DESIGN.md frontmatter の節ごとの担当宣言 (既定拒否) =====
 *
 * frontmatter の最上位の節は下の 3 分類の**いずれかに必ず属する**。
 * 未分類の節があれば canonical-source-parity が fail する
 * = 正本に節を足したのに誰も見ていない状態を作れない。
 *
 * **`checked` は「担当がいる」ことを表すのであって、節の中身を全項目網羅しているという
 * 主張ではない**。母集団の網羅は節ごとの集合一致テスト (施策 4) が別に固定する。
 */

/** 節を検査している gate の識別子 (ファイル名の語幹に合わせる)。 */
export type DesignGateName = "canonical-source-parity" | "tokens" | "contrast-invariant";

export type FrontmatterSectionOwner =
    /** 担当のいる節。どの gate が見ているかを列挙する */
    | { readonly kind: "checked"; readonly by: readonly DesignGateName[] }
    /** 実装写像を持たないメタ情報 (理由必須) */
    | { readonly kind: "metadata"; readonly reason: string }
    /**
     * 未検査であることの明示宣言 (理由・解消条件・追跡先の 3 つが必須)。
     * 追跡先は `T<3 桁以上>` (TODO の表の ID 列に実在) か
     * `devnotes/<dir>/` (実在するディレクトリ) のどちらか。
     */
    | {
          readonly kind: "pending";
          readonly reason: string;
          readonly exit: string;
          readonly tracking: string;
      };

export const FRONTMATTER_SECTION_OWNERS: Readonly<Record<string, FrontmatterSectionOwner>> = {
    version: { kind: "metadata", reason: "テーマの版。実装写像を持たない" },
    name: { kind: "metadata", reason: "テーマの名前。実装写像を持たない" },
    description: { kind: "metadata", reason: "テーマの説明文。実装写像を持たない" },
    colors: { kind: "checked", by: ["canonical-source-parity", "tokens", "contrast-invariant"] },
    typography: { kind: "checked", by: ["canonical-source-parity", "tokens"] },
    rounded: { kind: "checked", by: ["canonical-source-parity", "tokens"] },
    spacing: {
        kind: "pending",
        reason:
            "tokens.css に --spacing-* の写像が無く、値も写像の有無もどの検査も見ていない。" +
            "Tailwind 既定の spacing で足りているのか写像の作り忘れなのかが未決である",
        exit:
            "tokens.css に写像を作って canonical-source-parity と tokens の担当に移すか、" +
            "frontmatter から spacing を外すかを決めたら、本項目を削る",
        tracking: "devnotes/20260818-0248-design-token-t1-tests/",
    },
};
```

### TypeScript 適合チェック

- [x] 判別可能 union（`kind`）で分岐が型で保証される
- [x] `CSS_COLOR_SUFFIXES` は `readonly string[]` を明示（`satisfies` で綴りを検証できるわけではないので
      「compile-time に typo を検出する」とは書かない）
- [x] `any` / 非 null 断定なし

### テスト計画

- [ ] 施策 4 が `COMPILED_VALUE_EXEMPT_TOKENS` のキー集合 = `DERIVED_COLOR_TOKENS` を固定する
- [ ] 施策 3・4 が本宣言を読む

### リスク

- `CSS_COLOR_SUFFIXES` は `COLOR_TOKEN_MAP` の**値**（CSS 側 suffix）を使うため、
  DESIGN.md キーと CSS suffix が違うトークン（`text-primary` → `text`）で取り違えやすい。
  施策 3 の A では **キー側（DESIGN.md）から値側（CSS）へ写像して**突き合わせるので、
  取り違えると値が一致せず赤になる

---

## 施策 3: `tokens.test.ts` の新設（経路の層 + 密閉の層）

### 変更箇所

- ファイル: `tests/js/styles/tokens.test.ts`（新規）

### 波及変更

- TypeScript 型定義: postcss の公開型（`Root` / `ChildNode`）を import する。独自の型は増やさない
- テストファイル: 本ファイルが新規。既存 2 本の assertion は 1 つも変えない

### 変更後コード

```ts
import { beforeAll, describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import postcss, { type Root } from "postcss";
import tailwindcss from "@tailwindcss/postcss";
import { REPO_ROOT, designColors, designRamp, designRounded } from "./design-md";
import {
    COLOR_TOKEN_MAP,
    COMPILED_VALUE_EXEMPT_TOKENS,
    CSS_COLOR_SUFFIXES,
    RADIUS_TOKENS,
    ROUTE_LAYER_ANCHOR_TOKENS,
    TYPOGRAPHY_RAMPS,
} from "./inventory";

/*
 * tokens.test — tokens.css の宣言が Tailwind のコンパイルを通って生成 CSS に出ることを検査する。
 *
 * 【他の検査との境界】
 *   canonical-source-parity : DESIGN.md ⇔ tokens.css の**テキスト**一致 (正本 ⇔ 宣言)
 *   本ファイル              : tokens.css ⇒ **Tailwind 生成 CSS** (宣言 ⇒ 生成物)
 *   contrast-invariant      : DESIGN.md の色の可読性
 *   検査範囲は一部重なる。トークンの値を変えれば parity と本ファイルの双方が赤になり得るが、
 *   Tailwind の解釈が壊れる形 (`@theme` が効かなくなる等) は本ファイルだけが検出する。
 *
 * 【2 層に分ける理由】
 *   経路の層 (実 app.css) : アプリの入口から tokens.css へ実際に繋がっていることを見る。
 *                            **アンカー集合であって全件ではない**
 *   密閉の層 (組み立て入力): `source(none)` で自動走査を止め、`@source inline` で候補を明示供給する。
 *                            アプリの class 使用状況に依存せず全件を見る
 *
 * 【走査範囲を絞る理由 (重要)】
 *   テーマ変数は `@layer theme` 直下の `:root, :host` に出る。生成 CSS 全体を無差別に走査すると、
 *   `@theme` が壊れて素の `:root` 宣言になった場合でも同じ変数が拾えてしまい**緑で通る**
 *   (実測で確認済み)。よって themeVariables() は @layer theme の :root, :host の
 *   **直接の子宣言**だけを集める。
 *
 * 【保証しないもの】
 *   - Vite のビルド・アセット配信・ブラウザでの適用 (生成 CSS より先は見ていない)
 *   - `@import` の**順序**を入れ替えたときの破綻。実測では順序を入れ替えても生成物は壊れない。
 *     順序はリポジトリ規約であり、その固定は下の「取り込みの規約」が行う
 *   - font-family は**先頭 family だけ**を突き合わせる。フォールバック列は見ていない
 *   - 共有パーサ (design-md.ts) の**値の誤解析**。キーの取りこぼしは canonical-source-parity の
 *     集合一致が検出するが、値を誤って読む形は本ファイルも parity も同じ誤りを見るので検出できない
 */

/* ===== 検査対象の utility 候補 (注入と検査を同じ 1 つの値から作る) ===== */

const UTILITY_CANDIDATES = {
    color: CSS_COLOR_SUFFIXES.flatMap((s) => [`bg-${s}`, `text-${s}`, `border-${s}`]),
    radius: RADIUS_TOKENS.map((r) => `rounded-${r}`),
    ramp: TYPOGRAPHY_RAMPS.map((r) => `text-${r}`),
    hover: CSS_COLOR_SUFFIXES.filter((s) => s.endsWith("-hover")).map((s) => `hover:bg-${s}`),
} as const;

/**
 * 密閉入力を組み立てる。
 *
 * `source(none)` は Tailwind の自動ソース走査を止めるだけなので、
 * **候補を @source inline で与えないと utility は 1 つも生成されない**。
 * 注入する集合と検査する集合は UTILITY_CANDIDATES という 1 つの値から作る (2 か所に書かない)。
 */
function sealedInput(): string {
    const candidates = Object.values(UTILITY_CANDIDATES).flat().join(" ");
    return [
        '@import "tailwindcss" source(none);',
        '@import "../../../resources/css/tokens.css";',
        `@source inline("${candidates}");`,
    ].join("\n");
}

/*
 * @tailwindcss/postcss は `from` に渡したパスを鍵に結果をキャッシュする。
 * 1 つの `from` に 1 つの入力を対応させる (同じ from で別の入力を流さない)。
 * 密閉入力の from は実在しないパスでよい (相対 @import の解決にだけ使われる)。
 */
const SEALED_FROM = path.join(REPO_ROOT, "tests/js/styles/__sealed-tokens-input.css");
const APP_CSS_PATH = path.join(REPO_ROOT, "resources/css/app.css");

/** postcss + Tailwind でコンパイルする。失敗は握り潰さずそのまま伝播させる。 */
async function compile(css: string, from: string): Promise<Root> {
    const result = await postcss([tailwindcss()]).process(css, { from, to: undefined });
    return result.root;
}

interface ThemeVariables {
    readonly values: ReadonlyMap<string, string>;
    /** 同名で値の違う宣言が複数あったもの。空であることをテストが確かめる。 */
    readonly conflicts: readonly string[];
}

/**
 * `@layer theme` 直下の `:root, :host` 規則の**直接の子**である CSS 変数だけを集める。
 *
 * 「Tailwind がテーマとして解釈した結果」だけを見るための絞り込みであり、
 * ここを緩めると `@theme` の破損が検出できなくなる (ファイル冒頭の「走査範囲を絞る理由」)。
 * `@media` 等で入れ子になった `:root` は採らない。
 */
function themeVariables(root: Root): ThemeVariables {
    const values = new Map<string, string>();
    const conflicts = new Set<string>();

    root.walkAtRules("layer", (layer) => {
        const layers = layer.params.split(",").map((name) => name.trim());
        if (!layers.includes("theme")) return;

        layer.walkRules((rule) => {
            if (rule.parent !== layer) return; // 入れ子 (@media 等) の中は採らない
            const selectors = rule.selector.split(",").map((sel) => sel.trim());
            if (!selectors.every((sel) => sel === ":root" || sel === ":host")) return;

            for (const node of rule.nodes) {
                if (node.type !== "decl" || !node.prop.startsWith("--")) continue;
                const value = node.value.trim().toLowerCase();
                const previous = values.get(node.prop);
                if (previous !== undefined && previous !== value) conflicts.add(node.prop);
                values.set(node.prop, value);
            }
        });
    });

    return { values, conflicts: [...conflicts].sort() };
}

/**
 * selector が完全一致する規則の**直接の**宣言を、出現ごとに返す。
 * 子孫 (`&:hover` や `@media` の中) は含めない — 「この utility を当てたら効く宣言」だけを見る。
 */
function utilityRules(root: Root, selector: string): readonly ReadonlyMap<string, string>[] {
    const found: Map<string, string>[] = [];
    root.walkRules((rule) => {
        if (rule.selector !== selector) return;
        const decls = new Map<string, string>();
        for (const node of rule.nodes) {
            if (node.type !== "decl") continue;
            decls.set(node.prop, node.value.trim());
        }
        found.push(decls);
    });
    return found;
}

/** 出現がちょうど 1 件であることを確かめてその宣言を返す (0 件も重複もここで落ちる)。 */
function soleRule(root: Root, selector: string): ReadonlyMap<string, string> {
    const rules = utilityRules(root, selector);
    expect(rules.length, `${selector} の規則が 1 件でない (実際 ${rules.length} 件)`).toBe(1);
    return rules[0];
}

/**
 * `.hover\:…` 規則の中の **`&:hover` 入れ子配下**の宣言を返す。
 *
 * 外側の規則に直接書かれた宣言や `&:focus` の中の宣言は拾わない
 * (「hover 時に効く」ことを見たいので入れ子を明示的に辿る)。
 * `&:hover` の下でさらに `@media (hover: hover)` に包まれる形は Tailwind の実装詳細なので
 * 契約にせず、その中の宣言も拾う。
 */
function hoverDeclarations(root: Root, selector: string): ReadonlyMap<string, string> {
    const decls = new Map<string, string>();
    root.walkRules((rule) => {
        if (rule.selector !== selector) return;
        rule.walkRules((nested) => {
            if (nested.selector !== "&:hover") return;
            nested.walkDecls((decl) => decls.set(decl.prop, decl.value.trim()));
        });
    });
    return decls;
}

/** font-family 宣言の先頭 family を引用符抜き・小文字で取り出す。 */
function firstFamily(value: string): string {
    const head = value.split(",")[0].trim();
    return head.replace(/^['"]|['"]$/g, "").toLowerCase();
}

let sealed: Root;
let routed: Root;

beforeAll(async () => {
    sealed = await compile(sealedInput(), SEALED_FROM);
    routed = await compile(fs.readFileSync(APP_CSS_PATH, "utf-8"), APP_CSS_PATH);
}, 60_000);

/* ===== 空振り防止 ===== */

describe("tokens: 空振り防止", () => {
    it.each(Object.entries(UTILITY_CANDIDATES))(
        "utility 候補の区分 %s が 0 件でない",
        (_kind, list) => {
            // 注入と検査を 1 つの値から作るので、組み立てが壊れると両方が同時に空になり
            // 緑のまま通る。区分ごとに非空を確かめてそれを防ぐ。
            expect(list.length).toBeGreaterThan(0);
        },
    );

    it("密閉入力の生成 CSS がテーマ変数を持つ", () => {
        expect(themeVariables(sealed).values.size).toBeGreaterThan(0);
    });

    it("負のコントロール: 実在しない utility の規則は 0 件になる", () => {
        // utilityRules が「何にでも一致して緑になる」実装でないことを確かめる
        expect(utilityRules(sealed, ".bg-does-not-exist-token").length).toBe(0);
    });

    it("負のコントロール: テーマ変数の収集が @layer theme の外を拾わない", () => {
        // bfcache オーバーレイ等、テーマ層の外にも変数を書ける。それが混ざらないことを
        // 「収集した変数がすべて Tailwind の命名 (--color-/--radius-/--font-/--default- 等) である」
        // ではなく、@layer theme 限定という構造で担保している。
        // ここでは構造の証拠として、収集元が 1 か所に閉じていることを確かめる。
        let themeRootRules = 0;
        sealed.walkAtRules("layer", (layer) => {
            if (!layer.params.split(",").map((n) => n.trim()).includes("theme")) return;
            layer.walkRules((rule) => {
                if (rule.parent !== layer) return;
                const selectors = rule.selector.split(",").map((s) => s.trim());
                if (selectors.every((s) => s === ":root" || s === ":host")) themeRootRules++;
            });
        });
        expect(themeRootRules).toBeGreaterThan(0);
    });
});

/* ===== A. テーマ変数 (密閉の層) ===== */

describe("tokens/A: @theme 由来の CSS 変数が生成 CSS に期待値で現れる", () => {
    it("同名変数の値が競合していない", () => {
        expect(themeVariables(sealed).conflicts).toEqual([]);
    });

    it.each(Object.entries(COLOR_TOKEN_MAP))(
        "DESIGN.md colors.%s の値が --color-%s に届く",
        (designKey, cssSuffix) => {
            const expected = designColors().get(designKey);
            expect(expected, `DESIGN.md colors に ${designKey} が無い`).toBeDefined();
            expect(themeVariables(sealed).values.get(`--color-${cssSuffix}`)).toBe(expected);
        },
    );

    it.each(Object.keys(COMPILED_VALUE_EXEMPT_TOKENS))(
        "派生トークン --color-%s は出現までを検査する (値は免除)",
        (suffix) => {
            // 値の突き合わせを免除する理由は inventory.ts の COMPILED_VALUE_EXEMPT_TOKENS にある。
            // 「見ていない」のは値だけで、出現そのものは見る。
            expect(themeVariables(sealed).values.has(`--color-${suffix}`)).toBe(true);
        },
    );

    it.each([...RADIUS_TOKENS])("DESIGN.md rounded.%s の値が --radius-%s に届く", (key) => {
        // ⚠ Tailwind 既定テーマにも --radius-sm/md/lg がある (0.25rem / 0.375rem / 0.5rem)。
        //    「存在するか」だけでは空振りするので、必ず値を突き合わせる。
        expect(themeVariables(sealed).values.get(`--radius-${key}`)).toBe(designRounded().get(key));
    });

    it("ramp の font-family が 1 つに揃っており、--font-sans の**先頭 family**と一致する", () => {
        // ⚠ --font-sans も Tailwind 既定テーマに存在する。ここも値で見る。
        //    フォールバック列は DESIGN.md 側が持っていないので突き合わせない (先頭 family だけ)。
        const families = new Set(TYPOGRAPHY_RAMPS.map((r) => designRamp(r)["fontFamily"]));
        expect(families.size, "DESIGN.md の ramp が複数の fontFamily を宣言している").toBe(1);

        const declared = [...families][0];
        const fontSans = themeVariables(sealed).values.get("--font-sans");
        expect(fontSans, "--font-sans が @layer theme に無い").toBeDefined();
        expect(firstFamily(fontSans ?? "")).toBe(firstFamily(declared));
    });
});

/* ===== B. typography ramp utility (密閉の層) ===== */

describe("tokens/B: ramp utility が DESIGN.md の値で生成される", () => {
    it.each([...TYPOGRAPHY_RAMPS])("text-%s の宣言が DESIGN.md と過不足なく一致する", (name) => {
        const design = designRamp(name);
        const decls = soleRule(sealed, `.text-${name}`);

        const expected = new Map<string, string>([
            ["font-family", "var(--font-sans)"],
            ["font-size", design["fontSize"]],
            ["font-weight", design["fontWeight"]],
            ["line-height", design["lineHeight"]],
        ]);
        // letterSpacing が DESIGN.md に**無い** ramp に letter-spacing を勝手に足すことも防ぐ
        // (キー集合の一致で見る)。
        if (design["letterSpacing"]) expected.set("letter-spacing", design["letterSpacing"]);

        expect(Object.fromEntries([...decls].sort())).toEqual(
            Object.fromEntries([...expected].sort()),
        );
    });
});

/* ===== C. 色 utility (密閉の層) ===== */

describe("tokens/C: 色 utility が var(--color-*) を参照して生成される", () => {
    it.each([...CSS_COLOR_SUFFIXES])("bg-%s / text-%s / border-%s が解決する", (suffix) => {
        const token = `var(--color-${suffix})`;
        expect(Object.fromEntries(soleRule(sealed, `.bg-${suffix}`))).toEqual({
            "background-color": token,
        });
        expect(Object.fromEntries(soleRule(sealed, `.text-${suffix}`))).toEqual({ color: token });
        expect(Object.fromEntries(soleRule(sealed, `.border-${suffix}`))).toEqual({
            "border-color": token,
        });
    });
});

/* ===== D. hover variant (密閉の層) ===== */

describe("tokens/D: hover variant が &:hover の中で解決する", () => {
    it.each([...UTILITY_CANDIDATES.hover])("%s が hover 時の背景色になる", (utility) => {
        const suffix = utility.replace("hover:bg-", "");
        const decls = hoverDeclarations(sealed, `.hover\\:bg-${suffix}`);
        expect(decls.get("background-color")).toBe(`var(--color-${suffix})`);
    });
});

/* ===== E. radius utility (密閉の層) ===== */

describe("tokens/E: radius utility が var(--radius-*) を参照する", () => {
    it.each([...RADIUS_TOKENS])("rounded-%s が解決する", (key) => {
        // ⚠ この参照自体は Tailwind 既定テーマでも成立する (実測)。
        //    「aicue の値であること」を保証するのは A の値検査であって本テストではない。
        expect(Object.fromEntries(soleRule(sealed, `.rounded-${key}`))).toEqual({
            "border-radius": `var(--radius-${key})`,
        });
    });
});

/* ===== F. 経路の層 (実 app.css) ===== */

describe("tokens/F: 実 app.css のコンパイルで tokens.css が実際に効いている", () => {
    it.each([...ROUTE_LAYER_ANCHOR_TOKENS])(
        "アンカー --color-%s が DESIGN.md の値で現れる",
        (suffix) => {
            // アンカー集合であって全件ではない (全件は密閉の層が見る)。
            // アンカーが使われなくなったら、テストを緩めず土台の別トークンへ差し替える。
            const designKey = Object.entries(COLOR_TOKEN_MAP).find(([, v]) => v === suffix)?.[0];
            expect(designKey, `COLOR_TOKEN_MAP に --color-${suffix} の対応が無い`).toBeDefined();
            expect(themeVariables(routed).values.get(`--color-${suffix}`)).toBe(
                designColors().get(designKey ?? ""),
            );
        },
    );

    it("主 CTA の塗り (.bg-primary) が生成される", () => {
        expect(Object.fromEntries(soleRule(routed, ".bg-primary"))).toEqual({
            "background-color": "var(--color-primary)",
        });
    });

    it("生成された自前トークンの値はすべて DESIGN.md と一致する", () => {
        // アンカー以外にも出ているトークンがあれば、ついでに値を確かめる (母集団は要求しない)。
        const vars = themeVariables(routed).values;
        for (const [designKey, cssSuffix] of Object.entries(COLOR_TOKEN_MAP)) {
            const actual = vars.get(`--color-${cssSuffix}`);
            if (actual === undefined) continue;
            expect(actual, `--color-${cssSuffix}`).toBe(designColors().get(designKey));
        }
    });
});

/* ===== G. 取り込みの規約 (AST でのテキスト検査) ===== */

describe("tokens/G: app.css の入口 2 行の規約", () => {
    it("最初の 2 つの at-rule が tailwindcss → ./tokens.css の @import である", () => {
        // これは**規約**の固定であって動作の不変条件ではない。
        // 実測では @import の順序を入れ替えても Tailwind v4 の生成物は壊れなかった。
        // 取り込みが失われる形の破綻は F (経路の層) が検出する。
        //
        // 行ベースでコメントを除くと複数行コメントを誤って解釈するので、
        // postcss で parse した AST の先頭ノードを見る。
        const appRoot = postcss.parse(fs.readFileSync(APP_CSS_PATH, "utf-8"), {
            from: APP_CSS_PATH,
        });
        const significant = appRoot.nodes.filter((node) => node.type !== "comment");

        expect(significant.length).toBeGreaterThanOrEqual(2);
        const [first, second] = significant;
        expect(first.type).toBe("atrule");
        expect(second.type).toBe("atrule");
        if (first.type !== "atrule" || second.type !== "atrule") return;

        expect(first.name).toBe("import");
        expect(first.params).toMatch(/^["']tailwindcss["']$/);
        expect(second.name).toBe("import");
        expect(second.params).toMatch(/^["']\.\/tokens\.css["']$/);
    });
});
```

### TypeScript 適合チェック

- [x] postcss の公開型 `Root` を使い、型アサーション・非 null 断定を使わない
- [x] `node.type !== "decl"` の early continue で `Declaration` に絞られる（`ChildNode` の判別 union）
- [x] `first.type !== "atrule"` の early return で `AtRule` に絞られる
- [x] `compile()` は `Promise<Root>` を明示。`beforeAll` に `try/catch` を書かない
- [x] `Map#get` の戻りが `string | undefined` である前提で書き、`!` で潰さない

### テスト計画

- [ ] 感度確認 R1〜R4（§実装順序）を実施し、想定と実測を `red-verification.md` に残す
- [ ] 実装後、既存の `canonical-source-parity` / `contrast-invariant` が緑のままであることを確認
- [ ] `pnpm test` の実行時間の増分を測る（見込み: 密閉 + 経路で 1 秒前後）
- [ ] 個別の `DatabaseTransactions` は使っていない（PHP テストではない）

### リスク

- **Tailwind の版が上がると生成 CSS の形が変わる**。`@layer theme` の名前や
  `:root, :host` という selector、`&:hover` の入れ子はいずれも Tailwind v4 の実装形である。
  変わったら「テストを緩める」のではなく**新しい出力形に合わせて読み方を直す**
  （緩めると `@theme` 破損の検出という肝心の性質が失われる）
- **経路の層はアプリの class 使用状況に依存する**。アンカー 4 件は画面の土台で使われているものに
  限っており当面の変動には耐えるが、使われなくなったら差し替える（縮めない）
- **`@source inline` の候補に空白以外の区切りを入れると壊れる**。本バッチは utility 名だけを
  入れるので問題ないが、将来 alpha 修飾（`bg-text/70` 等）を足すときは引用の扱いを確かめること

---

## 施策 4: 母集団の集合一致と担当宣言を parity に足す

### 変更箇所

- ファイル: `tests/js/styles/canonical-source-parity.test.ts`（describe を 2 つ追加。既存は触らない）

### 波及変更

- テストファイル: 本ファイルのみ

### 変更後コード（追記分）

```ts
import {
    COMPILED_VALUE_EXEMPT_TOKENS,
    FRONTMATTER_SECTION_OWNERS,
} from "./inventory";
import { designFrontmatterSections, designTypographyNames } from "./design-md";

/**
 * 検査の**母集団**が DESIGN.md / tokens.css と集合一致していることを固定する。
 *
 * これが無いと「DESIGN.md に ramp や角丸を足したのに検査側の固定配列に入らず、
 * 誰も見ないまま通る」形が起きる (色だけは既存の set equality が守っていた)。
 */
describe("canonical source parity: 検査の母集団", () => {
    it("DESIGN.md typography の子キーと TYPOGRAPHY_RAMPS が集合一致する", () => {
        const names = designTypographyNames();
        expect(names.length, "ramp 名が 0 件 (抽出の空振り)").toBeGreaterThan(0);
        expect([...names].sort()).toEqual([...TYPOGRAPHY_RAMPS].sort());
    });

    it("tokens.css の @utility text-* と TYPOGRAPHY_RAMPS が集合一致する", () => {
        const utilities = [...tokensCss.matchAll(/@utility\s+text-([a-z0-9-]+)\s*\{/g)].map(
            (m) => m[1],
        );
        expect(utilities.length, "@utility が 0 件 (抽出の空振り)").toBeGreaterThan(0);
        expect([...utilities].sort()).toEqual([...TYPOGRAPHY_RAMPS].sort());
    });

    it("DESIGN.md rounded のキーと RADIUS_TOKENS が集合一致する", () => {
        expect([...designRounded().keys()].sort()).toEqual([...RADIUS_TOKENS].sort());
    });

    it("値検査を免除する派生色と DERIVED_COLOR_TOKENS が集合一致する", () => {
        // 契約: 派生色は全件が値免除である (DESIGN.md に期待値が無いため)。
        // 派生色を足したのに「値も見ていない・免除にも入っていない」状態を作れないようにする。
        expect(Object.keys(COMPILED_VALUE_EXEMPT_TOKENS).sort()).toEqual(
            [...DERIVED_COLOR_TOKENS].sort(),
        );
    });

    it("免除の理由が書かれている", () => {
        for (const [token, reason] of Object.entries(COMPILED_VALUE_EXEMPT_TOKENS)) {
            expect(reason.length, `${token}: 理由`).toBeGreaterThan(30);
        }
    });
});

/**
 * DESIGN.md frontmatter の節が、どの検査の担当かを既定拒否で固定する。
 *
 * 正本に節を足したのに誰も見ていない、という状態を作れないようにするための宣言。
 * 未検査の節は kind: "pending" として理由・解消条件・追跡先つきで登録する
 * (「検査があるから守られている」という誤読を防ぐ明示宣言であって免罪符ではない)。
 *
 * **kind: "checked" は「担当がいる」ことだけを表す**。節の中身の網羅は上の
 * 「検査の母集団」describe が別に固定している。
 */
describe("canonical source parity: frontmatter の節の担当宣言", () => {
    const sections = designFrontmatterSections();

    it("節が 0 件でない (抽出の空振り防止)", () => {
        expect(sections.length).toBeGreaterThan(0);
    });

    it("宣言と frontmatter の節が集合一致する (既定拒否)", () => {
        expect([...sections].sort(), "未宣言の節、または実在しない節の宣言がある").toEqual(
            Object.keys(FRONTMATTER_SECTION_OWNERS).sort(),
        );
    });

    it("metadata 宣言は理由を持ち、checked 宣言は担当 gate を 1 つ以上持つ", () => {
        for (const [section, owner] of Object.entries(FRONTMATTER_SECTION_OWNERS)) {
            if (owner.kind === "metadata") {
                expect(owner.reason.length, `${section}: reason`).toBeGreaterThan(0);
            }
            if (owner.kind === "checked") {
                expect(owner.by.length, `${section}: by`).toBeGreaterThan(0);
            }
        }
    });

    it("pending 宣言は理由・解消条件・追跡先をすべて埋めている", () => {
        for (const [section, owner] of Object.entries(FRONTMATTER_SECTION_OWNERS)) {
            if (owner.kind !== "pending") continue;
            expect(owner.reason.length, `${section}: reason`).toBeGreaterThan(30);
            expect(owner.exit.length, `${section}: exit`).toBeGreaterThan(30);
            expect(owner.tracking.length, `${section}: tracking`).toBeGreaterThan(0);
        }
    });

    it("pending の追跡先が実在する (書式だけ整った死んだ参照を作らせない)", () => {
        // TODO の ID は**表の ID 列**から取る。散文に現れた文字列や、
        // T1234 に含まれる T123 のような部分一致で通らないようにする。
        const todoIds = new Set(
            ["docs/TODO.md", "docs/TODO-closed.md"]
                .map((rel) => fs.readFileSync(path.join(REPO_ROOT, rel), "utf-8"))
                .join("\n")
                .split(/\r?\n/)
                .flatMap((line) => line.match(/^\|\s*(T\d{3,})\s*\|/)?.[1] ?? []),
        );
        expect(todoIds.size, "TODO の ID が 1 件も取れない (抽出の空振り)").toBeGreaterThan(0);

        for (const [section, owner] of Object.entries(FRONTMATTER_SECTION_OWNERS)) {
            if (owner.kind !== "pending") continue;
            const { tracking } = owner;

            if (/^T\d{3,}$/.test(tracking)) {
                expect(todoIds.has(tracking), `${section}: ${tracking} が TODO の表に無い`).toBe(
                    true,
                );
                continue;
            }
            expect(tracking, `${section}: 追跡先の書式`).toMatch(/^devnotes\/[\w.-]+\/$/);
            expect(
                fs.existsSync(path.join(REPO_ROOT, tracking)),
                `${section}: ${tracking} が実在しない`,
            ).toBe(true);
        }
    });
});
```

### TypeScript 適合チェック

- [x] `owner.kind !== "pending"` の early continue で union が絞られる
- [x] `line.match(...)?.[1] ?? []` は `flatMap` で `string[]` に落ちる（`!` を使わない）
- [x] `fs` / `path` / `REPO_ROOT` / `tokensCss` / `designRounded` / `DERIVED_COLOR_TOKENS` は
      同ファイルで既に import / 定義済み

### テスト計画

- [ ] 感度確認: `FRONTMATTER_SECTION_OWNERS` から `spacing` を消すと集合一致が赤
- [ ] 感度確認: `tracking` を実在しない `devnotes/no-such-dir/` にすると赤
- [ ] 感度確認: `TYPOGRAPHY_RAMPS` から 1 件消すと母集団の集合一致が赤（R3 と併せて確認）

### リスク

- 本 describe を `canonical-source-parity.test.ts` に置くのは、同ファイルが既に
  「DESIGN.md ⇔ 実装」の関係と `tokensCss` の読み込みを持っているため。
  `tokens.test.ts` に置くと「生成 CSS の検査」という責務からはみ出す

---

## 施策 5: `design-system-docs.test.ts` の新設

### 変更箇所

- ファイル: `tests/js/styles/design-system-docs.test.ts`（新規）

### 波及変更

- ドキュメント: 施策 6（`docs/design-system.md` に §検査の責務境界 を新設）が**前提**

### 変更後コード

```ts
import { beforeAll, describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { REPO_ROOT } from "./design-md";

/*
 * design-system-docs — docs/design-system.md の**構造**が壊れていないことを検査する。
 *
 * 【見るもの】節の実在と本文の非空 / 表のセルに並ぶパスの実在 / 検査目録の双方向の集合一致
 * 【見ないもの】散文。言い回しの一致は検査しない (文章を良くする PR を止めないため)
 *
 * 【保証しないもの】
 *   - **運用契約の意味が残っていること**。節が空でなく表が実体と一致していても、
 *     中身が骨抜きになっていることは検出できない (守るのは文書構造と検査目録の同期だけ)
 *   - **リポジトリ全体のデザイントークン検査の網羅**。自動で母集団に入るのは
 *     `tests/js/styles/` 直下の `*.test.ts` と、下の EXTERNAL_GATE_FILES に明示登録した分だけ。
 *     別の場所へ検査を足しても自動では見つからない
 */

const DOC_PATH = path.join(REPO_ROOT, "docs/design-system.md");

/** 本文を持つことを求める節 (見出し行そのもの)。 */
const REQUIRED_SECTIONS = [
    "## Canonical source の宣言",
    "## トークン変更時の運用契約",
    "## 検査の責務境界",
    "## 新規 domain 色トークン追加の必須条件(4 条件)",
    "## file-scoped allowlist の運用",
] as const;

/**
 * `tests/js/styles/` の外にある対象検査 (明示登録)。
 * 実在を確かめてから母集団へ入れる — 登録したまま消えたファイルを見逃さないため。
 */
const EXTERNAL_GATE_FILES = ["tests/js/architecture/contrast-invariant.test.ts"] as const;

/**
 * 見出しから、次の同レベル以上の見出しまでの本文を返す。
 * `## X` の中の `### Y` は同じ節の本文として残る。
 */
function extractSection(doc: string, heading: string): readonly string[] {
    const lines = doc.split(/\r?\n/);
    const start = lines.indexOf(heading);
    if (start < 0) return [];
    const level = (heading.match(/^#+/) ?? [""])[0].length;
    const rest = lines.slice(start + 1);
    const end = rest.findIndex((line) => new RegExp(`^#{1,${level}}\\s`).test(line));
    return end < 0 ? rest : rest.slice(0, end);
}

/**
 * Markdown 表の指定した列から、最初のバッククォート囲みの文字列を取り出す。
 *
 * 散文に同じ文字列を書いても通ってしまわないよう、**表の行のセル**だけを見る
 * (区切り行とヘッダー行はバッククォートを持たないので自然に落ちる)。
 */
function tableCellLiterals(section: readonly string[], column: number): readonly string[] {
    const literals: string[] = [];
    for (const line of section) {
        const trimmed = line.trim();
        if (!trimmed.startsWith("|")) continue;
        const cells = trimmed.split("|").slice(1, -1);
        const cell = cells[column];
        if (cell === undefined) continue;
        const literal = cell.match(/`([^`]+)`/)?.[1];
        if (literal !== undefined) literals.push(literal);
    }
    return literals;
}

/** 責務境界表に載っていなければならない検査ファイルの母集団。 */
function gateFiles(): readonly string[] {
    const stylesDir = path.join(REPO_ROOT, "tests/js/styles");
    const styles = fs
        .readdirSync(stylesDir)
        .filter((name) => name.endsWith(".test.ts"))
        .map((name) => `tests/js/styles/${name}`);

    for (const external of EXTERNAL_GATE_FILES) {
        // 明示登録したファイルが消えていたらここで落とす (行だけ残る状態を作らせない)。
        expect(
            fs.statSync(path.join(REPO_ROOT, external)).isFile(),
            `${external} が実在しない (EXTERNAL_GATE_FILES の登録が古い)`,
        ).toBe(true);
    }
    return [...styles, ...EXTERNAL_GATE_FILES].sort();
}

let doc: string;

beforeAll(() => {
    doc = fs.readFileSync(DOC_PATH, "utf-8");
});

describe("design-system-docs: 運用契約の節", () => {
    it.each([...REQUIRED_SECTIONS])("%s が存在し、本文を持つ", (heading) => {
        const body = extractSection(doc, heading);
        expect(body.length, `${heading} が見つからない`).toBeGreaterThan(0);
        expect(
            body.some((line) => line.trim() !== ""),
            `${heading} の本文が空`,
        ).toBe(true);
    });
});

describe("design-system-docs: Canonical source 表のパス", () => {
    it("表の 2 列目に並ぶリポジトリ相対パスがすべて実在する", () => {
        const section = extractSection(doc, "## Canonical source の宣言");
        // 同じセルに `@import "./tokens.css"` のようなコード片も入るため、
        // `/` 始まり (リポジトリ相対) のものだけをパスとして扱う。
        const paths = tableCellLiterals(section, 1)
            .filter((literal) => literal.startsWith("/"))
            .map((literal) => literal.slice(1));

        expect(paths.length, "表からパスが 1 件も取れない (抽出の空振り)").toBeGreaterThan(0);
        for (const relative of paths) {
            expect(
                fs.existsSync(path.join(REPO_ROOT, relative)),
                `Canonical source 表の ${relative} が実在しない`,
            ).toBe(true);
        }
    });
});

describe("design-system-docs: 検査目録の同期", () => {
    it("責務境界表の 1 列目と実在する検査ファイルが集合一致する (双方向)", () => {
        // 片側だけでは足りない —
        //   実体 → 文書 だけ: 検査を消したのに表の行が残るのを止められない
        //   文書 → 実体 だけ: 検査を足したのに書かないのを止められない
        const section = extractSection(doc, "## 検査の責務境界");
        const listed = tableCellLiterals(section, 0)
            .filter((literal) => literal.endsWith(".test.ts"))
            .sort();

        expect(listed.length, "責務境界表からパスが 1 件も取れない (抽出の空振り)").toBeGreaterThan(
            0,
        );
        expect(listed, "文書の責務境界表と実在する検査ファイルが食い違っている").toEqual([
            ...gateFiles(),
        ]);
    });
});
```

### TypeScript 適合チェック

- [x] `readonly string[]` を戻り値に明示
- [x] `heading.match(/^#+/) ?? [""]` で `null` を型で潰す（`!` を使わない）
- [x] `cell.match(...)?.[1]` の `undefined` を明示的に分岐

### テスト計画

- [ ] 本物の Red: §検査の責務境界 が無い状態で書いて赤を確認 → 施策 6 で緑
- [ ] 感度確認 R5（節を消す）/ R6（ダミー `.test.ts` を置く）
- [ ] 感度確認: Canonical source 表のパスを 1 つ架空のものに変えると赤

### リスク

- **見出し文字列の完全一致に依存する**。文言を直すと赤になるが、それは
  「節を消す・改名する」ことを検出する仕組みそのものなので許容する
  （直す側は `REQUIRED_SECTIONS` を同じ PR で直す）
- `gateFiles()` は `tests/js/styles/` 直下の `*.test.ts` を FS 走査で拾う。
  **この場所に検査以外の `.test.ts` を置かない**ことが前提になる（現状そうなっている）

---

## 施策 6: `docs/design-system.md` に §検査の責務境界 を新設

### 変更箇所

- ファイル: `docs/design-system.md`
  - §Canonical source の宣言 の直後に `## 検査の責務境界` を追加
  - §トークン変更時の運用契約 のチェックリスト 1 行を差し替え

### 波及変更

- テストファイル: 施策 5 の集合一致テストが本節を読む

### 変更後コード（追記分）

````markdown
## 検査の責務境界

本節で責務境界を管理するデザイントークン検査は 4 本ある
(DS purity 系など、トークンの値以外を見る検査は本節の管理対象ではない)。
**どれが何を見ているか**を混同しないこと — 見ている写像の段が違うので、
片方を消すと別の壊れ方が見えなくなる。

| 検査 | 見ている写像 | 代表的に検出する壊れ方 |
|------|------------|--------------------|
| `tests/js/styles/canonical-source-parity.test.ts` | DESIGN.md (正本) ⇔ tokens.css (宣言) のテキスト | 片方だけ更新した PR / トークンの増減 / 検査の母集団の取りこぼし |
| `tests/js/styles/tokens.test.ts` | tokens.css (宣言) ⇒ Tailwind 生成 CSS | `@theme` が解釈されない / utility 名が解決しない / app.css が tokens.css を取り込んでいない |
| `tests/js/styles/design-system-docs.test.ts` | 本書の構造 ⇔ 検査ファイルの実体 | 運用契約の節の消失 / 表と実体の食い違い |
| `tests/js/architecture/contrast-invariant.test.ts` | DESIGN.md の色値 ⇒ コントラスト比 | 読めない色の組合せ |

**この表は機械で実体と突き合わせている**。`tests/js/styles/` に検査を足したら本表にも行を足す
(足さないと `design-system-docs.test.ts` が落ちる)。逆に検査を消したら行も消す。
別の場所へ足す検査は `design-system-docs.test.ts` の `EXTERNAL_GATE_FILES` へ明示登録する。

保証しないもの: Vite のビルド・アセット配信・ブラウザでの適用は 4 本のどれも見ていない。
DESIGN.md frontmatter の `spacing:` は**値も tokens.css への実装写像の有無も検査していない**
(未検査であることは `tests/js/styles/inventory.ts` の `FRONTMATTER_SECTION_OWNERS` に
理由・解消条件・追跡先つきで宣言してある)。
````

チェックリストの差し替え（§トークン変更時の運用契約）:

```markdown
- [ ] `/tests/js/styles/inventory.ts`(トークンの追加・削除時。parity と生成 CSS 検査の母集団を兼ねる)
```

### テスト計画

- [ ] 施策 5 が本節を読んで緑になることを確認

### リスク

- 本節と施策 5 は互いに依存する（節が無ければテストが赤、テストが無ければ節は放置される）。
  **同一 PR で入れる**

---

## 施策 7: 逸脱登録 D27

### 変更箇所

- ファイル: `docs/template-divergence.md`
  - 冒頭 L11 の `登録エントリ: 25 件` → `登録エントリ: 26 件`
  - 末尾に `## D27 …` を追加

> **決めた日の注意**: `TemplateDivergenceLedgerFormatTest` は `CarbonImmutable::today()` を
> 基準日にしており、`config/app.php` の timezone は **UTC** である。日本時間の深夜に
> 実装すると「日本では今日でも UTC ではまだ昨日」になり、未来日として落ちる。
> **実装する時点の UTC 日付**を入れること（本設計の確定時点は UTC 2026-08-17）。

### 変更後コード（追記分）

````markdown
## D27 デザイントークンの生成 CSS 検査を、値の写しを持たず実 app.css も通す形で実装する

| 行 | 内容 |
|---|---|
| 対象パス | `tests/js/styles/tokens.test.ts` / `tests/js/styles/design-system-docs.test.ts` |
| 業務要件起因の説明 | 撮影 PWA は現場作業者が屋外のスマホで使う面であり、状態色と本文が読めることが業務の前提になる。テンプレート家系の正典実装は期待値を検査側の表に literal で持つが、本アプリは DESIGN.md を唯一の正本と定めており、値の写しを 3 か所へ増やすと正本の一元化と衝突する |
| 揃え続ける不変条件と保証機構 | inventory に登録された DESIGN.md 対応の色・角丸・文字組が、Tailwind の生成 CSS に同じ値で現れ、対応する utility 名がその変数へ解決すること。および運用契約の文書が検査ファイルの実体と同期していること。`tests/js/styles/tokens.test.ts` (密閉の層 = 母集団の全件 / 経路の層 = 実 app.css のアンカー) と `tests/js/styles/design-system-docs.test.ts` (双方向の集合一致) が保証する |
| 再判定の条件 | 正典が literal 期待値表の保持そのものを不変条件として明文化したとき。または Tailwind の生成 CSS の構造 (`@layer theme` の `:root, :host` / `&:hover` の入れ子) が変わって AST 走査で読めなくなったとき |
| 決めた日 | 2026-08-17 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260818-0248-design-token-t1-tests/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 期待値の置き場所 | 検査側の inventory に literal の表 | DESIGN.md から共有パーサ経由で導出 |
| 入力 CSS | 静的な fixture ファイル | inventory から組み立てた文字列 |
| 自動ソース走査 | 止めていない (アプリ全体の class を拾う) | `source(none)` + `@source inline` で候補を明示供給 |
| 生成 CSS の読み方 | 文字列の正規表現 | postcss の AST を `@layer theme` の `:root, :host` 直下に絞って走査 |
| 実 app.css の検査 | 先頭 2 行のテキスト検査のみ | AST でのテキスト検査 + 実コンパイル (経路の層) |
| 文書の検査 | 散文の完全一致フレーズ | 節・表のセル・パス・検査目録の構造検査 |

### なぜ正当な差分か(logic-driven)

家系の裁定 (機能 `design-token-system`) は「揃えるべきは検査の仕組みであり、テーマ値や
デザインシステムの中身はプロジェクト別カスタマイズ点で drift ではない」と定めている。
正典の literal 表が持つ「DESIGN.md とは独立に値を pin する」性質は、この裁定に照らせば
**t1 の不変条件ではなく正典実装の副次的な性質**である。本アプリは DESIGN.md を唯一の正本と
しており、トークンの値の変更は「気付くべき事故」ではなく正規の変更手順であるため、
独立 pin を採らない。

静的 fixture を持たない判断も同様に、fixture の目的
(アプリ全体の class 変動から検査を独立させる) を `source(none)` + `@source inline` が満たす。
実測では、正典の fixture はこの目的を**満たしていなかった** (自動ソース走査が働き、
アプリ全体を拾った生成 CSS 46,667 文字に対して検査していた)。

### 揃えている不変条件(これは保証し続ける)

> 「inventory に登録された DESIGN.md 対応の色・角丸・文字組が、Tailwind のコンパイルを通って
> 生成 CSS に同じ値で現れ、UI が使う utility 名がその変数へ解決すること」

密閉の層が母集団の全件を、経路の層が実 app.css からの到達をアンカーで保証する。
正本との drift は `canonical-source-parity.test.ts` の集合一致・値一致が別の段で保証する。

**見ていないもの**: 派生トークン `--color-primary-soft` の値 (出現のみ) /
font-family の先頭以外のフォールバック列 / 生成 CSS より先 (Vite・配信・ブラウザ)。

### 関連

- 実装: `tests/js/styles/tokens.test.ts` / `tests/js/styles/design-system-docs.test.ts` /
  `tests/js/styles/inventory.ts` / `tests/js/styles/design-md.ts` /
  `tests/js/styles/canonical-source-parity.test.ts` / `docs/design-system.md`
- 設計: devnotes/20260818-0248-design-token-t1-tests/
````

### テスト計画

- [ ] `composer test` の `TemplateDivergenceLedgerFormatTest` が緑（9 行・値域・件数の 3 点一致）
- [ ] 対象パスが**他のエントリと重複していない**ことを確認（新規 2 ファイルなので重複しないはずだが、
      マージ直前に和集合を確認する）
- [ ] 対象パスの 2 ファイルが**実在する**状態でコミットする（施策 3・5 と同一 PR）

### リスク

- 件数行（`登録エントリ: 26 件`）の更新忘れで赤になる。**同一 PR で必ず直す**
- 他バッチが D27 を先に取ると番号が衝突する。マージ直前に採番を確認する

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 7 施策が相互に前提関係を持ち（1・2 → 3・4・5、6 → 5、7 は 3・5 と同一 PR 必須）、分割すると中間状態が必ず赤になる。総変更量は新規 2 ファイル + 既存 3 ファイルへの追記で小さい |
| 競合リスク | `tests/js/styles/` と `docs/design-system.md` / `docs/template-divergence.md` に触る他バッチがあれば衝突する。特に `docs/template-divergence.md` の**件数行と D 番号**は衝突しやすいので、マージ直前に採番を確認する |

## 完了条件（Definition of Done）

- [ ] `red-verification.md` に R1〜R6 の「想定した assertion」と「実際に落ちた assertion」が
      両方記録され、食い違いがあれば本設計を実測に合わせて直してある
- [ ] 注入した故障をすべて戻し、緑に復帰している
- [ ] `pnpm test` / `pnpm typecheck` / `pnpm lint` が緑
- [ ] `composer test`（`TemplateDivergenceLedgerFormatTest` を含む）が緑
- [ ] `pnpm build` が緑
- [ ] 既存の `canonical-source-parity` / `contrast-invariant` の assertion を 1 つも削っていない
- [ ] トークンの値を 1 つも変えていない
- [ ] 設計時の一時スクリプト（`probe-*.mjs`）は devnotes 配下に置いたまま（`scripts/` へ昇格しない）
