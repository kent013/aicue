# 対応マトリクス: impl-review Round 2

## [Warning] withRawEnvironmentValue() が指定しなかった経路を未設定化していない
- 判断: 対応する
- 根拠: 指摘のとおり。3 経路のうち 1 つだけを設定しても、実行環境に同じ変数が残っていれば
  違反が 2 件以上になり `toHaveCount(1)` が落ちる = テストがホスト環境依存になる。
  「3 経路とも未設定」のケースに至っては、未設定状態を構築しないまま名前だけ主張していた。
  さらに、二重判定が入ったことで**本ファイルのほぼ全ケース**が同じ依存を持つようになっていた
  (baseline の「violations は空」も、手元シェルに TESTING_FAKE_* があれば落ちる)。
- 対応内容: 2 段で直した。
  1. `withRawEnvironmentValue()` が**指定されなかった経路をテスト中だけ未設定化**し、
     `finally` で 3 経路とも原状復帰する。
  2. ファイル全体の前提として `beforeEach` で**対象 3 変数 × 3 経路をすべて未設定にし**、
     `afterEach` で戻す (退避先は Pest の TestCase へ動的プロパティを生やさないよう
     ファイル内の関数の static に置いた)。対象変数の一覧は
     `ExternalFakeDeclaration::FLAG_ENVIRONMENT_VARIABLES` から導く (写経しない)。
  「3 経路とも未設定なら violation は出ない」は、どの経路も指定しない形で
  `withRawEnvironmentValue()` を通し、未設定が判定対象にならないことを明示的に固定する形にした。
- 実測: 汚染された環境 (`TESTING_FAKE_STORAGE=true TESTING_FAKE_EXTERNALS=true`) で
  `composer test -- --filter='ProductionEnvGuard'` を走らせ 48 passed を確認した
  (修正前はこの条件で落ちる形だった)。
