# 対応マトリクス: design-review Round 2

## [Warning] 施策2: 空コンストラクタ `private function __construct() {}` が Pint に適合しない
- 判断: **反論する** (根拠は実測)
- 根拠: 本リポジトリの Pint は laravel preset で、**この 1 行形をそのまま通す**。
  実測: 同じ書き方を持つ `tests/Support/TemplateDivergence/LedgerPins.php` に対し
  `vendor/bin/pint --test tests/Support/TemplateDivergence/LedgerPins.php` が
  `{"tool":"pint","result":"passed"}` を返す。同形は `tests/Support/` 配下に 10 件以上ある
  (`ArchTokenStream` / `SourceLiterals` / `ArchBaseline` / `StoryFrontMatterPins` 等)。
  波括弧を次行へ割ると**既存の全 Support クラスと書式が食い違う**ので採らない。
- 対応内容: 設計を変えず、施策 2 のコード直前に**書式の注記**として実測の根拠を書き足した
  (実装者が Round 2 の指摘を見て割る形に変えてしまわないようにするため)。

## [Warning] 施策7: 「列挙すべき経路が現時点で 0 件」が 3 経路列挙と矛盾して見える
- 判断: 対応する
- 根拠: 指摘のとおり。0 件なのは「宣言値が実効値にならない**例外経路**」であって、
  待ち予算を**読む経路** (3 本) ではない。このままだと「読む経路が無い」とも読める。
- 対応内容: 施策 7 のテスト計画を
  「**『宣言値が実効値にならない例外経路』が現時点で 0 件**であり、空の例外一覧を固定する
  検査は『今必要なものだけ作る』に反するため」へ書き換え、
  「0 件なのは例外経路であって読む経路ではない (読む経路は 3 本あり上の箇条で列挙)」を併記した。

## [Suggestion] 施策7 の名称が実態 (既存箇条の書き換え + 3 箇条追記) と合っていない
- 判断: 対応する
- 対応内容: 施策一覧・見出し・テストファースト手順の 3 か所を
  「実効性の運用契約の条件付き化と 3 箇条追記」へ改めた。

## [Suggestion] 想定規模の「既存 5 ファイル」は実際には 6 ファイル
- 判断: 対応する
- 対応内容: 実装モードの表で 6 ファイルを名前つきで列挙した
  (`PromptClientTimeoutInvariantTest.php` / `AnalysisBudget.php` /
  `AnalysisTokenBudgetInvariantTest.php` / `docs/template-divergence.md` /
  `LedgerPins.php` / `docs/architecture.md`)。

## [Suggestion] 「成果物はすべて devnotes/ 配下」は変更先 (tests/ docs/) と合わない
- 判断: 対応する
- 対応内容: 「Artifact は使用せず、成果物はすべてリポジトリ内のファイルとして出力する
  (設計は `devnotes/`、実装は `tests/` と `docs/`)」へ改めた。

## 施策1 / 3 / 4 / 5 / 6: APPROVE
- 判断: 変更なし
