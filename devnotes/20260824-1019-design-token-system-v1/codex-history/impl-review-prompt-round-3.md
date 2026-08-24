Round 2 の Warning 3 件すべてに対応しました (反論 0 件)。

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

Codex Round 2 の判定は **CHANGES_REQUESTED**、内訳は **Warning 3 件のみ (Critical 0)**。
**3 件すべて対応**した (反論 0 件)。
Round 1 の Critical 5 件は「4 件が実装で閉じ、置換だけの補間は共通規約 (b) に沿った
保証範囲縮小として受け入れ可能」と明示的に承認された。

## [Warning] token-reference-closure の docblock が保証範囲の縮小と矛盾している

- 判断: 対応する
- 根拠: 「既知の入口は class-usage.ts が deny する」と書くと、`` `${classes}` `` も
  deny されていると読める。実際に deny で 0 件固定しているのは 3 入口だけである。
- 対応内容: 「deny で 0 件に固定しているのは `unsupportedEntryPoints()` が列挙する 3 入口だけ。
  静的部分にテーマ名前空間の語を持たない補間は deny もせず単位も作らない = 非保証」と明記した。

## [Warning] `ComponentFileKindSpec.kind` が意味的な判定に使われていない

- 判断: 対応する
- 根拠: `switch` に入れただけでは実挙動は `requiresSection` と `.types.ts` の直書きが決めており、
  `{ kind: "helper", requiresSection: true }` のような**矛盾した組合せ**が表現できてしまう。
  共通規約 (d) への対応として不十分という指摘は正しい。
- 対応内容: Codex の提案どおり **`kind` を判定の正本**にした —
  `component` は母集団へ追加 / `types` は対の component の存在確認 / `helper` は追加しない /
  default は `never`。**`requiresSection` は削除**し、矛盾した組合せを型と実装の両方から消した。
  `.types.ts` の直書きも `kind === "types"` 起点の照合へ置き換えた。
  「kind が母集団への入れ方を決める」ことの裏取りとして、同じ木で `.ts` の kind を
  `component` へ差し替えると母集団が増える固定検体を追加した。

## [Warning] D55 / D56 の保証本文が実態より広い

- 判断: 対応する
- 対応内容:
  - **D55**: 不変条件の本文へ「**走査器が保証する構文集合 (文字列リテラルの中の class トークン)
    の範囲で**」を 2 箇所に入れ、後段の「保証しないもの」と矛盾しない形にした
  - **D56**: 文書の走査の保証を**対象文書ごとに 2 つへ分けて**書き直した —
    (a) `docs/design-system.md` はタブ・4 連続空白まで全面的に拒否する、
    (b) `DESIGN.md` §Components は Markdown 診断の拒否と「行頭から始まる有効な ATX 見出しだけを
    受理する」ことが保証範囲で、**DESIGN.md 全体のタブ・4 連続空白は拒否しない**
    (frontmatter が 4 空白字下げを使うため)。
    「揃えている不変条件」側にも契約 B の適用対象が `docs/design-system.md` だけである旨を明記した


## 修正差分 (Round 2 で触れた 4 ファイルの累積差分)

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 773f1b07..6450e180 100644
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
 
@@ -3157,3 +3162,136 @@ ### 関連
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
+| 揃え続ける不変条件と保証機構 | 半透明の背景 × 不透明な文字の組が、面として分類した token のすべての上で 4.5:1 を満たすこと。**走査器が保証する構文集合 (文字列リテラルの中の class トークン) の範囲で**、見つかった半透明の組が (ファイル, 組, 修飾率, 件数) で全件台帳に載り、静的に決められない形は理由と件数つきで別台帳に載ること。台帳が持つのは class 修飾の百分率だけで、token 固有 alpha との合成は 1 か所 (`resolveAlphaBackground()`) に集約されること。**同じ構文集合の範囲で**、実装の class から導出した前景 × 背景の組が役割の母集団 (役割の全数分類の直積 + 個別宣言ペア) の内側にあること。線形化しきい値が errata 後の 0.04045 であること。`contrast-invariant.test.ts` と `tests/js/styles/class-usage.ts` が保証する |
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
+| 揃え続ける不変条件と保証機構 | 責務境界表と `tests/js/styles/*.test.ts` の実体が双方向に集合一致すること (本数は書かない)。DESIGN.md §Components の節と対象サブディレクトリの部品ファイルが双方向に集合一致すること。節ごとの規範の最小断片が読者に描画される本文に在ること。文書の走査の保証は**対象文書ごとに違う**ので分けて書く — (a) `docs/design-system.md` (`design-system-docs.test.ts` が保証): 規範判定対象外領域の除去 (HTML コメント = 読者に描画されない / 囲みコード = 描画されるが規範の本文として数えない) に加えて、囲みコードの外の行に記号が 3 個以上連続して現れ、その行が字下げ 3 空白までの正規の top-level 囲みコード開始行でなければ診断にする。未終端のコメント・未終端の囲みコード・受理範囲外の記法も同じ診断へ落とす。**さらにタブと 4 個以上連続した半角空白を含む行があれば検査自体を失敗させる**。(b) `DESIGN.md` §Components (`component-doc-parity.test.ts` が保証): 同じ Markdown 診断が 1 件でもあれば解析失敗にし、`## Components` は**行頭から始まる有効な ATX 見出しだけ**を受理する。**DESIGN.md 全体のタブ・4 連続空白は拒否しない** (frontmatter が 4 空白字下げを使うため)。行の分類は 1 実装 (`scanMarkdownLines()`) に集約されること |
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
+  行の分類は `tests/js/styles/markdown-lines.ts` の 1 実装に集約してある。
+  **契約 B (タブ・4 連続空白の拒否) を適用しているのは `docs/design-system.md` だけ**である
+  (`DESIGN.md` は frontmatter が 4 空白字下げを使うため適用できない。
+  そちらは Markdown 診断と見出しの受理範囲で受ける)
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
diff --git a/tests/js/styles/component-doc-parity.test.ts b/tests/js/styles/component-doc-parity.test.ts
new file mode 100644
index 00000000..9bfb607e
--- /dev/null
+++ b/tests/js/styles/component-doc-parity.test.ts
@@ -0,0 +1,586 @@
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
+            // 母集団への入れ方は **`kind` が決める** (真偽値の別フラグを持たない)。
+            const componentBaseNames = new Set<string>();
+            const typeFiles: { readonly file: string; readonly base: string }[] = [];
+            for (const file of child.files) {
+                const suffix = fileKindOf(file, fileKinds);
+                if (suffix === null) {
+                    unclassifiedFiles.push(`${child.path}/${file}`);
+                    continue;
+                }
+                usedFileKinds.push(suffix);
+                const base = file.slice(0, -suffix.length);
+                // 分類の網羅を never へ収束させる (種別を足したらここが必ず赤くなる)
+                switch (fileKinds[suffix].kind) {
+                    case "component":
+                        components.push(`${child.path}/${file}`);
+                        componentBaseNames.add(base);
+                        break;
+                    case "types":
+                        typeFiles.push({ file, base });
+                        break;
+                    case "helper":
+                        break;
+                    default: {
+                        const exhaustive: never = fileKinds[suffix].kind;
+                        throw new Error(`未知のファイル種別: ${String(exhaustive)}`);
+                    }
+                }
+            }
+            for (const { file, base } of typeFiles) {
+                if (!componentBaseNames.has(base)) orphanTypes.push(`${child.path}/${file}`);
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
+    it("kind が母集団への入れ方を決める (helper は母集団に入らず、component は入る)", () => {
+        // `kind` を判定の正本にしていないと、種別を取り違えても gate が通ってしまう。
+        const tree2 = fixtureTree({
+            directories: [
+                { path: "atoms", directories: [], files: ["Badge.svelte", "input-state.ts"] },
+                { path: "features", directories: [], files: [] },
+            ],
+        });
+        expect(classifyComponentTree(tree2, FIXTURE_DIRS, FIXTURE_KINDS).components).toEqual([
+            "atoms/Badge.svelte",
+        ]);
+        // 同じ木でも `.ts` を component 種別にすると母集団へ入る (kind が効いている裏取り)
+        const swapped = classifyComponentTree(tree2, FIXTURE_DIRS, {
+            ...FIXTURE_KINDS,
+            ".ts": { kind: "component" },
+        });
+        expect(swapped.components).toEqual(["atoms/Badge.svelte", "atoms/input-state.ts"]);
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
diff --git a/tests/js/styles/inventory.ts b/tests/js/styles/inventory.ts
index 22784fe9..f96ac774 100644
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
@@ -193,3 +258,398 @@ export const FRONTMATTER_SECTION_OWNERS: Readonly<Record<string, FrontmatterSect
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
+/**
+ * ファイル種別。**この値が判定の正本である** (`kind` から母集団への入れ方が決まる) —
+ * `component` = 節を要求する部品 / `types` = 対の部品の存在を確認する型ファイル /
+ * `helper` = 母集団に入れない共有 helper。
+ *
+ * ★「節を要求するか」を別の真偽値で持たない — `kind` と食い違う組合せ
+ *   (`helper` なのに節を要求する等) を表現できてしまい、`kind` が判定に使われないまま残る。
+ */
+export interface ComponentFileKindSpec {
+    readonly kind: "component" | "types" | "helper";
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
+    ".svelte": { kind: "component" },
+    ".types.ts": {
+        kind: "types",
+        reason: "型と variant 表。同名の *.svelte が対になっていることを検査する",
+    },
+    ".ts": {
+        kind: "helper",
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
diff --git a/tests/js/styles/token-reference-closure.test.ts b/tests/js/styles/token-reference-closure.test.ts
new file mode 100644
index 00000000..1e44c24b
--- /dev/null
+++ b/tests/js/styles/token-reference-closure.test.ts
@@ -0,0 +1,257 @@
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
+ *   - **走査単位の外 (動的に組み立てた class)**。deny で 0 件に固定しているのは
+ *     `unsupportedEntryPoints()` が列挙する 3 入口 (`class:` ディレクティブ /
+ *     class 合成ライブラリ / テーマ名前空間の接頭辞の内側への補間) **だけ**である。
+ *     **静的な部分にテーマ名前空間の語を 1 つも持たない補間** (`` `${classes}` `` /
+ *     `class={classes}`) は deny もせず単位も作らない = **非保証**である
+ *     (class 記述だと静的に判別できないため。正本は class-usage.ts の「保証しないもの」)
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

```

## テスト結果 (修正後)

- pnpm typecheck: 通過
- tests/js/styles/ + contrast-invariant: 8 files / 362 tests passed
- TemplateDivergenceFingerprintTest / TemplateDivergenceLedgerFormatTest: 17 passed

全体判定を `APPROVED` または `CHANGES_REQUESTED` で明記してください。
