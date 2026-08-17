# 実装時の追補 (T221)

詳細設計 (`detailed-design.md`) の施策 1〜7 をそのまま実装したうえで、
**設計に無かった補強を 1 つ足した**。その理由と範囲をここに残す。

## 追補: 描画されない Markdown 領域を検査前に落とす (design-system-docs.test.ts)

### 何を足したか

`renderedLines()` を新設し、`docs/design-system.md` を検査する前に
**HTML コメント**と **fenced code** の行を空行へ潰す (行数は保存する)。
節の切り出し・本文の非空判定・表のセルの抽出はすべて潰した後の行を入力にする。
潰す判定は CommonMark に合わせ、**開始も終了も字下げは 3 空白まで**とする
(4 空白の偽の終端で区間が閉じると、そこから先を「描画される本文」に見せかけられる)。
仕様は固定 fixture (`RENDER_FIXTURE`) のテスト群で恒久的に留めた。

あわせて `SECTION_CONTRACT_PHRASES` (節ごとの**規範の最小断片**) を持ち、
描画される本文にその一文が在ることを求める。これが無いと
「節はあるし本文も空でない」だけで通り、契約の一文を別の文へ差し替えても緑になる。

### なぜ足したか

家系の機能台帳 (`design-token-system`) で、正典リポジトリが 2026-08-17 に報告した
**3 セル (aicue / motivation / metamovics) の追従判定の基準**が 2 つあり、その 2 番目が
「運用ガイドの同期契約の本文が空・改変されていないか。**見出しだけ残って中身が消える形と、
描画されない場所 (コメント / コードブロック) への退避の両方を塞ぐこと**」だった。

設計は前者 (見出しだけ残る形) しか塞いでおらず、後者は塞いでいない。
契約の本文を HTML コメントへ移すだけで「節はあるし本文も空でない」状態を作れてしまい、
検査が緑のまま骨抜きになる (fail-open)。追従の判定基準が名指ししている穴なので、
同一バッチで塞ぐほうが筋が良いと判断した。

### 範囲を広げなかったところ

- **4 空白字下げのコードブロックは見ない**。Markdown の文脈依存 (直前の空行・リストの継続行)
  が強く、誤って本文を潰す害のほうが大きい。表と箇条書きが主体の本書では、
  字下げ退避よりコメント退避のほうが現実的な回避口である
- HTML 要素による非表示 (`<div style="display:none">` 等) も見ない
- この非対称は `design-system-docs.test.ts` 冒頭の「保証しないもの」と
  `docs/template-divergence.md` D27 の「保証しないもの」に書いてある

## 追補 2: 生成 CSS 側の走査を「条件なしで適用される範囲」に絞る

Codex の実装レビュー (Round 1) で受けた Critical / Warning への対応として、
`tokens.test.ts` の走査も 2 か所絞った。**設計の意図 (走査範囲の絞りが検出力そのもの)
を実装が 1 段甘く実現していた**ための是正であり、設計方針の変更ではない。

- `themeVariables()` はルート直下の `@layer theme` だけを見る
  (条件つき at-rule の中のテーマ層は、文字列としては生成 CSS に出るが画面には効かない)
- `rulesWithSelector()` は条件つき at-rule の祖先を持つ規則を数えない
  (成立しない `@supports` の中にしか無い utility を「生成されている」と数えない)
- `collectDeclarations()` は通った条件つき at-rule の条件文を返し、
  D が `(hover: hover)` の許容一覧と突き合わせる
  (`@media (hover: none)` へ移された宣言を「hover 時の背景色」と数えない)

## 設計本文との差分

- `detailed-design.md` 施策 5 の `extractSection(doc: string, …)` は
  `extractSection(lines: readonly string[], …)` になった (入力が潰した後の行配列になったため)
- `beforeAll` が読むのは文字列でなく `renderedLines()` の結果である
- 施策 5 に「節ごとの規範の最小断片」の検査が加わった
- 施策 3 の `themeVariables` / `rulesWithSelector` / `collectDeclarations` に
  上記の絞りが加わった (返り値に `conditions` が増えた)
- 施策 7 の件数の pin は `docs/template-divergence.md` の見出し行だけでなく
  `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` の
  `TEMPLATE_DIVERGENCE_ENTRY_COUNT` にもある。設計は前者しか挙げていなかったので
  **2 か所とも 26 へ直した**
- D27 の比較表と「保証しないもの」を上記に合わせて直した

それ以外 (施策 1・2・4・6) は設計のとおりである。
