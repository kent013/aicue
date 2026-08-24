Round 1 の指摘への対応が完了しました。**Critical 5 件・Warning 10 件はすべて対応**、
Suggestion 5 件も全件対応しました (反論 0 件)。

重要な実測の前提: 指摘された 3 つの fail-open (置換だけの補間 / 任意値の中のコロン /
Svelte `<style>`) は **現在のリポジトリでは出現 0 件**です (grep で確認済み)。
したがって修正しても走査結果は 1 件も変わっておらず、閉じたのは将来の穴です。
実際、修正の前後で `ALPHA_PAIR_USAGE_LEDGER` (25 行) と `UNDECIDABLE_PAIR_LEDGER` (24 行) は
1 行も変わっていません。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

Codex (`gpt-5.6-sol` / reasoning=high) の Round 1 判定は **CHANGES_REQUESTED**
(行頭ラベルの機械カウントで Critical 5 / Warning 10 / Suggestion 5)。
**Critical 5 件と Warning 10 件はすべて対応**、Suggestion 5 件も 4 件対応・1 件は記述訂正で対応した。

★実測の前提: 指摘された 3 つの fail-open (置換だけの補間 / 任意値の中のコロン /
Svelte `<style>`) は**いずれも現在のリポジトリでは出現 0 件**である
(`grep` で確認)。よって修正しても走査結果は 1 件も変わらず、閉じたのは**将来の穴**である。

## [Critical] 置換だけの template literal が無言で消える (`` `${classes}` ``)

- 判断: **対応する (ただし検出ではなく保証範囲の明示的な縮小で閉じる)**
- 根拠: 静的部分にテーマ名前空間の語を 1 つも持たない補間は、class 記述なのか
  他用途の文字列なのかを**静的に判別できない**。全 `TemplateExpression` を判定不能にすると
  `` `テイク ${i}` `` のような無関係な文字列まで台帳に載り、台帳が統制として機能しなくなる。
  AGENTS.md 共通規約 (b) は「保証範囲の外にする構文は docblock へ明記し、明記したなら
  その構文について検出力を主張しない」「利用側 gate は検出力の主張をその構文を除く形へ
  明示的に狭める」ことを認めている。
- 対応内容:
  1. `class-usage.ts` の「保証しないもの」へ本形を名指しで追記し、
     「この形で組み立てると全 gate を迂回できる / 止めているのは規約と人のレビューである」と明記
  2. **固定検体で限界を pin** した (`class-usage.test.ts`:
     「静的部分に監視対象語を持たない補間は単位を作らない」)。
     対比として `` `${classes} bg-primary` `` は `interpolated` になることも同じ it で固定
  3. 乖離登録 D55 の「保証しないもの」へ同じ限界を書き、恒久保証の過大評価を解消

## [Critical] `splitVariants()` が任意値の中のコロンを variant 境界と誤認する

- 判断: **対応する**
- 根拠: 真の fail-open。`text-[color:#ffffff]` は rest が `#ffffff]` になり
  `isWatchedCandidate()` が false を返すため、**hex 直書きなのに occurrence 自体が作られない**。
  文字検証に到達する前に候補から外れるので `unparsable-token` にもならない。
- 対応内容: `splitVariants()` を**角括弧の外のコロンだけ**で分割する形へ直した。
  負例 2 形を追加 (`text-[color:#ffffff]` は `unknown-token` になる /
  `[&_svg]:stroke-current` は従来どおり variant + utility に割れる)。
  `token-reference-closure.test.ts` にも「候補ごと消えずに落ちる」負例を追加。

## [Critical] Svelte AST の `css` ノードを走査していない (`<style>` の var 参照)

- 判断: **対応する**
- 根拠: S3 が「resources/js の var 参照を閉包する」と主張している以上、
  `.svelte` の `<style>` を見ないのは主張と実装の食い違いである。
- 対応内容: `svelteUnits()` が `ast.css.content.styles` を返すようにし、
  `scanCssVarReferencesSource()` が `<style>` の中身を**CSS と同じ postcss 経路**で読むようにした
  (CSS 読み取りを `collectFromCss()` に括り出し、`.css` と `<style>` が同一実装を共有する)。
  負例・正例の両方向を追加 (未知 var は `unresolved` / 写像 token は解決し診断 0 件)。

## [Critical] `parseDesignComponentSections()` が字下げした `## Components` を受理する

- 判断: **対応する**
- 根拠: `line.trim()` で見出しを探すと、`## Components` を字下げコード
  (契約 B が失敗させるのは docs/design-system.md だけで DESIGN.md には適用していない) へ
  移して S8 の双方向一致を迂回できる。
- 対応内容: 判定を `line === "## Components"` (行頭から始まる有効な ATX 見出し) へ変更。
  負例「字下げした `## Components` は受理しない」を `component-doc-parity.test.ts` に追加。
  D56 の「保証しないもの」へも明記した。

## [Critical] 上記 2 経路を通すと token-reference-closure が green のままになる

- 判断: **対応する** (上の 3 件の修正で閉じる)
- 対応内容: 指摘された 3 つの負例を純粋入口の検体として追加した
  (置換だけの補間 = 限界の pin / コロン入り任意値 = `unresolved` /
  `<style>` の未知 var = `unresolved`)。

## [Warning] `alphaOfSuffix()` が `parseCssColor()` と別の簡易パーサになっている

- 判断: **対応する**
- 根拠: 簡易パーサは `rgb(r g b / a)` を alpha と認識できず、
  「色表現の読み出しを 1 実装へ集約する」方針にも反する。
- 対応内容: `parseCssColor()` の結果から判定する形へ置き換えた。

## [Warning] 「補間内部の class 風文字列を二重に拾わない」テストが現在の実装を正解にしている

- 判断: **対応する (Critical 1 と一体)**
- 対応内容: 限界を明示する専用の it を追加し、対比 (`interpolated` が 1 件出る形) を同じ it で固定した。

## [Warning] Badge 全 tone / Button 全 variant の期待値が件数と kind しか見ていない

- 判断: **対応する**
- 根拠: 誤った fg/bg や誤った reason に分類されても通る。詳細設計の
  「全 tone / 全 variant が期待どおりの組へ分解される」を満たしていない。
- 対応内容: `EXPECTED_TONE_PAIRS` / `EXPECTED_VARIANT_PAIRS` を導入し、
  分解結果を `fg on bg` / `fg on bg/修飾率` / `!理由` の表記で**意味まで**突き合わせる形にした。
  キー集合が `TONE_CLASSES` / `VARIANT_CLASSES` と一致することを別に固定するので、
  件数は散文に書かない (設計の要求どおり)。

## [Warning] コロン入り任意値と `<style>` 内未知 var の負例が無い

- 判断: **対応する** (上記 Critical 2 / 3 の対応に含む)

## [Warning] `classifyComponentTree()` がルート直下ファイルを一度も分類しない

- 判断: **対応する**
- 根拠: `resources/js/components/New.svelte` は部品にも未分類にもならず**消える** (fail-open)。
- 対応内容: 走査根直下のファイルを `unclassifiedFiles` へ入れる形にし、負例を追加した。

## [Warning] `ComponentFileKindSpec.kind` が判定に使われず、使用済み suffix の集合一致も無い

- 判断: **対応する**
- 対応内容: `kind` を `switch` + `never` で網羅させ、`usedFileKinds` を集計して
  `COMPONENT_FILE_KINDS` のキーと**集合一致**させた (死んだ登録を落とす)。負例も追加。

## [Warning] 字下げされた `## Components` の負例が無い

- 判断: **対応する** (Critical 4 の対応に含む)

## [Warning] 「既知の要求組」が 2 例だけ

- 判断: **対応する**
- 対応内容: Badge の soft 背景を**全 tone** (5 組) へ広げ、Button 側も
  `neutral|primary` / `neutral|danger` / `text|border` の 3 組を要求する形にした。
  分解の意味の検査は `class-usage.test.ts` が担い、ここは「実リポジトリの走査から消えていない」
  ことを見る、と責務を明記した。

## [Warning] 「是正前の値では 5 組が AA 未達」が 1 組しか固定されていない

- 判断: **対応する**
- 対応内容: 5 組すべてを `PRE_CORRECTION_FAILURES` としてリテラルだけで固定し、
  是正前は AA 未達 / 是正後は充足を `it.each` で回す形にした。
  併せて「danger は是正前でも通る」= 一律に暗くしたのではないことの裏取りも追加した。

## [Warning] D55 / D56 が実態を過大評価している

- 判断: **対応する**
- 対応内容: 実装を閉じたうえで、閉じきれない 1 点 (置換だけの補間) を
  D55 の「保証しないもの」へ、見出しの受理範囲を D56 の「保証しないもの」へ明記した。

## [Suggestion] `CssVarReferenceScan.files` がどの gate からも参照されていない

- 判断: **対応する**
- 対応内容: `token-reference-closure.test.ts` の母集団検査で `files.length > 0` を要求する形にした。

## [Suggestion] `DeclaredPair.fg/bg` が `string` で typo が型で落ちない

- 判断: **対応する**
- 対応内容: `DesignColorKey = keyof typeof COLOR_TOKEN_MAP` を導出して `DeclaredPair` に使った。

## [Suggestion] `PENDING_CONTRAST_PAIRS` の責務記述が実装とずれている

- 判断: **対応する**
- 対応内容: 「各 reason を発火させる検体は `class-usage.test.ts` が担当する」と実装に合わせた。

## [Suggestion] `ThemeBlock.offset` が参照されていない

- 判断: **対応する**
- 対応内容: `offset` を削除した (共通規約 (d)。`body` を持たない理由と同じ)。

## [Suggestion] tokens.test.ts の H 節コメントが旧値のまま

- 判断: **対応する**
- 対応内容: 実測コメントの hex を `#1d4ed8` へ更新した。


## 修正差分 (tests/ と docs/template-divergence.md のみ。Round 1 からの追加分を含む累積差分)

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 773f1b07..2a826ea6 100644
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
 
@@ -3157,3 +3162,133 @@ ### 関連
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
+  **実行時に組み立てられる class**。正本は `tests/js/styles/class-usage.ts` の docblock。
+  とくに**静的な部分にテーマ名前空間の語を 1 つも持たない補間** (`` `${classes}` ``) は
+  class 記述だと静的に判別できないので**単位を作らない** (判定不能にも数えない) —
+  この形で class を組み立てると本登録の gate は丸ごと迂回される。
+  止めているのは走査器ではなく「そう書かない」という規約と人のレビューであり、
+  固定検体 (`class-usage.test.ts`) がこの限界を見える形で pin してある
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
+  HTML 要素による非表示 (`<details>` / `hidden` 属性等) は見ていない。
+  節の見出しは**行頭から始まる有効な ATX 見出しだけ**を受理する
+  (字下げした見出しを受理すると、字下げコードへ退避させて双方向一致を迂回できる)
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
index 72e733ef..703ef7b9 100644
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
@@ -152,3 +222,442 @@ describe("architecture/contrast-invariant: 不透明ペアのテキストコン
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
+    /**
+     * 是正前の値で AA を割っていた **5 組**を既知値として固定する (正典 i18 (d))。
+     *
+     * 台帳からも写像からも独立した**リテラルだけ**で書く — 合成器そのものが
+     * 「何を渡しても 4.5 以上を返す」形に退行したことを検出するための負のコントロールであり、
+     * 同時に「なぜテーマ値を 1 段暗くしたのか」の根拠を機械可読な形で残す。
+     * 各行は `[前景 hex, 背景 hex, 実効 alpha, 下地 hex, 是正後の前景/背景 hex]`。
+     */
+    const PRE_CORRECTION_FAILURES = [
+        ["primary-soft", "#2563eb", 0.12, "#f4f4f5", "#1d4ed8"],
+        ["primary/10", "#2563eb", 0.1, "#f4f4f5", "#1d4ed8"],
+        ["success/10", "#15803d", 0.1, "#f4f4f5", "#166534"],
+        ["warning/10", "#b45309", 0.1, "#f4f4f5", "#92400e"],
+        ["tertiary/10", "#0f766e", 0.1, "#f4f4f5", "#115e59"],
+    ] as const;
+
+    it.each(PRE_CORRECTION_FAILURES)(
+        "負のコントロール: 是正前の %s は AA を割り、是正後は通る",
+        (_label, before, alpha, base, after) => {
+            expect(ratioOfComposite(before, before, alpha, base)).toBeLessThan(AA_NORMAL_TEXT);
+            expect(ratioOfComposite(after, after, alpha, base)).toBeGreaterThanOrEqual(
+                AA_NORMAL_TEXT,
+            );
+        },
+    );
+
+    it("負のコントロール: danger は是正前の値でも soft 背景で通る (一律に暗くしたのではない)", () => {
+        // 「5 組だけが未達だった」という実測の裏取り。danger は 2026-08 に red-700 へ
+        // 是正済みだったので据え置いた。
+        expect(ratioOfComposite("#b91c1c", "#b91c1c", 0.1, "#f4f4f5")).toBeGreaterThanOrEqual(
+            AA_NORMAL_TEXT,
+        );
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
+        // Badge の soft 背景 (全 tone) と Button の塗り面が、走査結果に実際に現れることを固定する。
+        // 分解の**意味**まで見るのは class-usage.test.ts の担当で、ここは
+        // 「実リポジトリの走査からその組が消えていない」ことだけを見る。
+        const alpha = new Set(
+            scan.pairs.flatMap((p) =>
+                p.kind === "alpha-background" ? [`${p.fg}|${p.bg}|${p.modifierPercent ?? "-"}`] : [],
+            ),
+        );
+        const opaque = new Set(
+            scan.pairs.flatMap((p) => (p.kind === "opaque" ? [`${p.fg}|${p.bg}`] : [])),
+        );
+        for (const required of [
+            "primary|primary-soft|-",
+            "tertiary|tertiary|10",
+            "success|success|10",
+            "warning|warning|10",
+            "danger|danger|10",
+        ]) {
+            expect(alpha.has(required), `Badge の soft 背景 ${required} が抽出できていない`).toBe(
+                true,
+            );
+        }
+        for (const required of ["neutral|primary", "neutral|danger", "text|border"]) {
+            expect(opaque.has(required), `Button の組 ${required} が抽出できていない`).toBe(true);
+        }
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
index 00000000..dc7cd950
--- /dev/null
+++ b/tests/js/styles/class-usage.test.ts
@@ -0,0 +1,521 @@
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
+    it("静的部分に監視対象語を持たない補間は単位を作らない (保証範囲の外であることの固定)", () => {
+        // ★これは**検出できている**ことの検査ではなく、**検出しないと宣言した形**を
+        //   固定検体で見える化するものである (共通規約 (b): 保証範囲の外にした構文は
+        //   docblock に明記し、その構文について検出力を主張しない)。
+        //   この形で class を組み立てると本走査器の全 gate を迂回できるため、
+        //   迂回を止めているのは走査器ではなく規約と人のレビューである。
+        const scan = scanTs("`${classes}`");
+        expect(scan.occurrences).toEqual([]);
+        expect(scan.pairs).toEqual([]);
+        expect(scan.diagnostics).toEqual([]);
+        // 静的部分に監視対象語が 1 つでもあれば判定不能として台帳に載る (対比)
+        expect(reasonsOf(scanTs("`${classes} bg-primary`"))).toEqual(["interpolated"]);
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
+    it("任意値の中のコロンを variant 境界と誤認しない (候補ごと消える fail-open を塞ぐ)", () => {
+        // 素朴に split(":") すると rest が `#ffffff]` になって監視対象から外れ、
+        // hex 直書きなのに occurrence 自体が作られない。
+        const arbitrary = scanTs('"text-[color:#ffffff]"').occurrences;
+        expect(arbitrary.length).toBe(1);
+        expect(arbitrary[0].variants).toEqual([]);
+        expect(arbitrary[0].utility).toBe("text-[color:#ffffff]");
+        expect(arbitrary[0].resolution).toEqual({ kind: "unresolved", reason: "unknown-token" });
+
+        // 角括弧の**外**のコロンは従来どおり variant 境界である
+        const variantArbitrary = scanTs('"[&_svg]:stroke-current"').occurrences;
+        expect(variantArbitrary[0].variants).toEqual(["[&_svg]"]);
+        expect(variantArbitrary[0].utility).toBe("stroke-current");
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
+/**
+ * 期待する分解結果を**意味まで**書く (件数と kind だけでは、誤った fg/bg や
+ * 誤った reason に分類されても通ってしまう)。
+ *
+ * 表記は `fg on bg` (不透明) / `fg on bg/修飾率` (半透明。修飾なしは `-`) /
+ * `!理由` (判定不能)。キー集合が実装の variant 表と一致することを別に固定するので、
+ * 件数は散文に書かない。
+ */
+const describePair = (pair: ScannedPair): string => {
+    if (pair.kind === "opaque") return `${pair.fg} on ${pair.bg}`;
+    if (pair.kind === "alpha-background") {
+        return `${pair.fg} on ${pair.bg}/${pair.modifierPercent ?? "-"}`;
+    }
+
+    return `!${pair.reason}`;
+};
+
+const decomposed = (classes: string): readonly string[] =>
+    pairsOf(JSON.stringify(classes)).map(describePair).sort();
+
+const EXPECTED_TONE_PAIRS: Readonly<Record<string, readonly string[]>> = {
+    primary: ["primary on primary-soft/-"],
+    tertiary: ["tertiary on tertiary/10"],
+    success: ["success on success/10"],
+    warning: ["warning on warning/10"],
+    danger: ["danger on danger/10"],
+    neutral: ["text-secondary on neutral"],
+};
+
+const EXPECTED_VARIANT_PAIRS: Readonly<Record<string, readonly string[]>> = {
+    "primary": ["neutral on primary", "neutral on primary-hover"],
+    "tertiary": ["neutral on tertiary", "neutral on tertiary-hover"],
+    "ghost": ["!keyword-color"],
+    "neutral": ["text on border", "text on neutral"],
+    "success": ["!element-opacity", "neutral on success"],
+    "danger": ["!element-opacity", "neutral on danger"],
+    "danger-outline": ["danger on surface", "neutral on danger"],
+    "danger-ghost": ["!keyword-color", "danger on danger/10"],
+};
+
+describe("class-usage: 既知の要求組が抽出結果から生成される (正例)", () => {
+    it("Badge の全 tone が期待どおりの組へ分解される", () => {
+        expect(Object.keys(EXPECTED_TONE_PAIRS).sort()).toEqual(Object.keys(TONE_CLASSES).sort());
+        for (const [tone, classes] of Object.entries(TONE_CLASSES)) {
+            expect(decomposed(classes), `${tone}: ${classes}`).toEqual(
+                [...EXPECTED_TONE_PAIRS[tone]].sort(),
+            );
+        }
+    });
+
+    it("Button の全 variant が期待どおりの組 / 判定不能へ分解される", () => {
+        expect(Object.keys(EXPECTED_VARIANT_PAIRS).sort()).toEqual(
+            Object.keys(VARIANT_CLASSES).sort(),
+        );
+        for (const [variant, classes] of Object.entries(VARIANT_CLASSES)) {
+            expect(decomposed(classes), `${variant}: ${classes}`).toEqual(
+                [...EXPECTED_VARIANT_PAIRS[variant]].sort(),
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
index 00000000..27561d73
--- /dev/null
+++ b/tests/js/styles/class-usage.ts
@@ -0,0 +1,1170 @@
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
+ *   - **実行時に組み立てられる class** (正典 i22 (1))。とくに
+ *     **静的な部分にテーマ名前空間の語を 1 つも持たない補間** (`` `${classes}` `` /
+ *     `class={classes}`) は、class 記述なのか他の用途の文字列なのかを静的に区別できないため
+ *     **単位を作らない** (判定不能にも数えない)。ここに検出力は主張しない —
+ *     この形で class を組み立てると本走査器の全 gate を迂回できる。
+ *     迂回を止めているのは走査器ではなく、**そう書かない**という規約と人のレビューである
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
+import { cssColorTokens, cssRadiusTokens, cssRampUtilities, parseCssColor } from "./theme-map";
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
+    /** `.svelte` の `<style>` の中身 (CSS として別経路で読む) */
+    readonly styles: readonly string[];
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
+            styles: [],
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
+    const styleSource = (ast as { css?: { content?: { styles?: unknown } } }).css?.content?.styles;
+    const styles = typeof styleSource === "string" ? [styleSource] : [];
+
+    return { units, styles, diagnostics: [], entryPoints };
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
+            styles: [],
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
+    return { units, styles: [], diagnostics: [], entryPoints };
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
+/**
+ * variant 列を剥がした残りと、剥がした列を返す。
+ *
+ * 分割は**角括弧の外のコロンだけ**で行う。素朴に `split(":")` すると
+ * 任意値の中のコロン (`text-[color:#ffffff]` / `bg-[url(a:b)]`) を variant 境界と誤認し、
+ * 残りが監視対象接頭辞で始まらなくなって**候補ごと無言で母集団から外れる** (fail-open)。
+ * 角括弧の中は Tailwind の任意値なので、そこにコロンがあっても変種の区切りではない。
+ */
+function splitVariants(candidate: string): { variants: readonly string[]; rest: string } {
+    const variants: string[] = [];
+    let depth = 0;
+    let start = 0;
+    for (let i = 0; i < candidate.length; i += 1) {
+        const ch = candidate[i];
+        if (ch === "[") depth += 1;
+        else if (ch === "]") depth = Math.max(0, depth - 1);
+        else if (ch === ":" && depth === 0) {
+            variants.push(candidate.slice(start, i));
+            start = i + 1;
+        }
+    }
+
+    return { variants, rest: candidate.slice(start) };
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
+/**
+ * token の**値そのもの**が持つ alpha (派生 token だけが持つ)。不透明なら `null`。
+ *
+ * 色表現の読み出しは `parseCssColor()` の 1 実装へ集約する (簡易パーサを別に持つと、
+ * 片方だけが受理する書き方 = `rgb(r g b / a)` で判定が食い違う)。
+ */
+function alphaOfSuffix(suffix: string): number | null {
+    const value = cssColorTokens().get(suffix);
+    if (value === undefined) return null;
+    const parsed = parseCssColor(value);
+
+    return parsed.kind === "alpha" ? parsed.alpha : null;
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
+    /** CSS ソースを postcss で読んで `var()` 参照を集める (`.css` と `<style>` が共有する)。 */
+    const collectFromCss = (css: string): boolean => {
+        let root;
+        try {
+            root = postcss.parse(css, { from: file });
+        } catch (error) {
+            sink.diagnose(
+                "css-parse-failed",
+                error instanceof Error ? error.message : String(error),
+            );
+
+            return false;
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
+        return true;
+    };
+
+    if (file.endsWith(".css")) {
+        if (!collectFromCss(source)) return { references: [], diagnostics };
+
+        return { references, diagnostics };
+    }
+
+    const { units, styles, diagnostics: unitDiagnostics } = extractUnits(source, file);
+    if (unitDiagnostics.length > 0) {
+        // class 走査側の診断は class-usage.test.ts が消費するので、ここでは参照 0 件で返す。
+        return { references: [], diagnostics: [] };
+    }
+    for (const unit of units) collectVarReferences(unit.text, sink);
+    // `.svelte` の <style> は CSS なので**CSS と同じ経路**で読む
+    // (歩かないと「resources/js の var 参照を閉包する」という主張と食い違う)。
+    for (const style of styles) collectFromCss(style);
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
index 00000000..eb9037e5
--- /dev/null
+++ b/tests/js/styles/component-doc-parity.test.ts
@@ -0,0 +1,567 @@
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
+    /** 判定に実際に使われたファイル種別の接尾辞 */
+    readonly usedFileKinds: readonly string[];
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
+    const usedFileKinds: string[] = [];
+    const orphanTypes: string[] = [];
+
+    // ★分類対象ディレクトリの**外**に置かれたファイル (components 直下など) を
+    //   無言で捨てない。部品にも未分類にもならず消える形は fail-open である。
+    for (const file of tree.files) unclassifiedFiles.push(file);
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
+                usedFileKinds.push(suffix);
+                const spec = fileKinds[suffix];
+                // 分類の網羅を never へ収束させる (種別を足したらここが必ず赤くなる)
+                switch (spec.kind) {
+                    case "component":
+                    case "types":
+                    case "helper":
+                        break;
+                    default: {
+                        const exhaustive: never = spec.kind;
+                        throw new Error(`未知のファイル種別: ${String(exhaustive)}`);
+                    }
+                }
+                if (spec.requiresSection) {
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
+        usedFileKinds: [...new Set(usedFileKinds)].sort(),
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
+    it("ファイル種別が分類表と集合一致する (未分類も死んだ登録も落とす)", () => {
+        expect(
+            classification.unclassifiedFiles,
+            "分類表に無い拡張子のファイル、または分類対象ディレクトリの外に置かれたファイルがある",
+        ).toEqual([]);
+        expect(
+            classification.usedFileKinds,
+            "判定に使われなかったファイル種別の登録がある (死んだ登録)",
+        ).toEqual(Object.keys(COMPONENT_FILE_KINDS).sort());
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
+    it("分類対象ディレクトリの外 (components 直下) のファイルは未分類として落ちる", () => {
+        const tree2 = fixtureTree({ files: ["Stray.svelte"] });
+        const c = classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS);
+        expect(c.components).toEqual(["atoms/Badge.svelte"]);
+        expect(c.unclassifiedFiles).toEqual(["Stray.svelte"]);
+    });
+
+    it("使われなかったファイル種別の登録を検出できる", () => {
+        const c = classifyComponentTree(fixtureTree(), FIXTURE_DIRS, FIXTURE_KINDS);
+        expect(c.usedFileKinds).toEqual([".svelte", ".types.ts"]);
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
+    it("字下げした ## Components は見出しとして受理しない (字下げコードへの退避を塞ぐ)", () => {
+        // `trim()` で探すと、規範の見出しを字下げコードへ移して双方向一致を迂回できる。
+        expect(() =>
+            parseDesignComponentSections(md(["    ## Components", "### Button"])),
+        ).toThrow(/1 節でない/);
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
index 611c0c65..40d4c37c 100644
--- a/tests/js/styles/design-md.ts
+++ b/tests/js/styles/design-md.ts
@@ -8,6 +8,7 @@
 import fs from "node:fs";
 import path from "node:path";
 import { fileURLToPath } from "node:url";
+import { scanMarkdownLines } from "./markdown-lines";
 
 const HERE = path.dirname(fileURLToPath(import.meta.url));
 export const REPO_ROOT = path.resolve(HERE, "../../../");
@@ -88,3 +89,58 @@ export function designTypographyNames(): readonly string[] {
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
+ *   1. `## Components` は**ちょうど 1 節**であること (0 件も 2 件も例外)。
+ *      判定は**行頭から始まる有効な ATX 見出し**に限る (字下げした見出しは受理しない —
+ *      受理すると字下げコードへ退避させて双方向一致を迂回できる)
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
+    // ★`trim()` で探さない — 字下げした行を見出しとして受理すると、
+    //   `## Components` を字下げコードへ退避させて双方向一致を迂回できる (fail-open)。
+    //   有効な ATX 見出し (行頭から始まる `## Components`) だけを受理する。
+    const heads = lines.flatMap((line, index) => (line === "## Components" ? [index] : []));
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
index 22784fe9..90a07081 100644
--- a/tests/js/styles/inventory.ts
+++ b/tests/js/styles/inventory.ts
@@ -37,68 +37,133 @@ export const TYPOGRAPHY_RAMPS = ["display", "h1", "h2", "h3", "body", "caption"]
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
 
+/** DESIGN.md の色キー (役割分類と個別宣言ペアが使う空間。綴り誤りが型で落ちる)。 */
+export type DesignColorKey = keyof typeof COLOR_TOKEN_MAP;
+
+/** 役割の直積で表現できない正当な 1 対 1 の組 (理由必須。正典 i14)。 */
+export interface DeclaredPair {
+    readonly fg: DesignColorKey;
+    readonly bg: DesignColorKey;
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
@@ -193,3 +258,393 @@ export const FRONTMATTER_SECTION_OWNERS: Readonly<Record<string, FrontmatterSect
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
+        "never へ収束させ、「各 reason を発火させる検体が 1 つ以上ある」ことは " +
+        "class-usage.test.ts の固定検体が担当する",
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
index 00000000..600b693e
--- /dev/null
+++ b/tests/js/styles/theme-map.ts
@@ -0,0 +1,322 @@
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
+/**
+ * `@theme` ブロック 1 つ分。
+ *
+ * ★位置 (offset) は**持たない** — どこからも使わない出力を作らない
+ *   (共通規約 (d)「集めた走査結果を判定に使わない形を作らない」)。
+ */
+export interface ThemeBlock {
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
+            blocks.push({ topLevel });
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
index 00000000..06bf3ba9
--- /dev/null
+++ b/tests/js/styles/token-reference-closure.test.ts
@@ -0,0 +1,252 @@
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
+        expect(scan.files.length, "走査したファイルが 0 件 (走査の空振り)").toBeGreaterThan(0);
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
+    it("Svelte の <style> の中の未知 var も見える (走査対象から外れない)", () => {
+        // `<style>` を歩かないと「resources/js の var 参照を閉包する」という主張と食い違う。
+        const scan = scanCssVarReferencesSource(
+            "<div></div>\n<style>.x { color: var(--color-does-not-exist); }</style>\n",
+            "fixture.svelte",
+        );
+        expect(scan.references.map((r) => r.name)).toEqual(["--color-does-not-exist"]);
+        expect(scan.references.map((r) => r.resolution.kind)).toEqual(["unresolved"]);
+    });
+
+    it("Svelte の <style> の中の写像 token は解決する (誤検出しない)", () => {
+        const scan = scanCssVarReferencesSource(
+            "<div></div>\n<style>.x { color: var(--color-primary); }</style>\n",
+            "fixture.svelte",
+        );
+        expect(scan.references.map((r) => r.resolution.kind)).toEqual(["color"]);
+        expect(scan.diagnostics).toEqual([]);
+    });
+
+    it("任意値の中のコロンを持つ未知 token は候補ごと消えずに落ちる", () => {
+        // 変種の分割が角括弧を見ないと `text-[color:#ffffff]` が監視対象から外れ、
+        // hex 直書きが無検査で通ってしまう。
+        expect(classFixture('"text-[color:#ffffff]"')).toEqual(["text-[color:#ffffff]"]);
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
index 48926e24..3ff0e814 100644
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
+     *       background-color: color-mix(in srgb, #1d4ed8 10%, transparent);
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

## テスト結果 (修正後)

- pnpm typecheck / pnpm lint: 通過
- tests/js/styles/ + contrast-invariant: 8 files / 361 tests passed
- composer phpstan (level 10): No errors
- vendor/bin/pint --test: passed
- TemplateDivergenceFingerprintTest / TemplateDivergenceLedgerFormatTest: 17 passed

## 確認してほしいこと

1. Round 1 の Critical 5 件・Warning 10 件が実際に閉じているか
2. 「置換だけの補間」を検出ではなく**保証範囲の明示的な縮小**で閉じた判断が、
   AGENTS.md 共通規約 (b) の「保証範囲の外にする構文は docblock へ明記し、
   明記したならその構文について検出力を主張しない / 利用側 gate は検出力の主張を
   その構文を除く形へ明示的に狭める」に適合しているか
3. 新たに入れた修正が別の fail-open を作っていないか

全体判定を `APPROVED` または `CHANGES_REQUESTED` で明記してください。
