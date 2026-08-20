# 対応マトリクス: design-review Round 4

## [Critical] (S4) `FingerprintGenerationService` が受け取る readonly context DTO のクラス名と定義ファイルが無い
- 判断: 対応する
- 根拠: 指摘のとおり。「新規 PHP 18 本・1 クラス 1 ファイル」と言いながら DTO の置き場所が無かった
- 対応内容: `tests/Support/TemplateDivergence/FingerprintGenerationContext.php` を新設 (19 本目)。
  施策一覧の S4・変更ファイル・新規ファイル一覧・実装モードの本数をすべて 19 本へ揃えた。
  境界検証は指摘の 6 点をコンストラクタで例外にする形で明記 —
  期待 sha256 が 64 桁小文字 hex / 期待 source commit が 40 桁小文字 hex /
  出力先 2 つが root 配下の**規定のパス**である / 出力先 2 つが同一でない /
  前世代台帳があるなら `role: app` / 非 adopt 時は前世代の `generated_at_commit` が
  期待 source commit と一致する。
  母集合外の新規ファイルなので `FINGERPRINT_POPULATION_COUNT = 281` は変わらない

## [Warning] (S4) service のテスト計画が「拒否 3 経路」と書いて 4 経路を列挙している
- 判断: 対応する / 対応内容: 「拒否 **4 経路**」へ訂正

## [Warning] (S4) TSV の書式説明のコードブロックに世代ヘッダが無い
- 判断: 対応する
- 対応内容: 正準形を `# template_ledger_commit=<40 桁 hex>` + `<path>\t<sha256>` の 2 段で示し、
  1 行目がヘッダ・2 行目以降がパスの昇順であることを明記

## [Warning] (S7) context DTO の不変条件の正負テストが無い
- 判断: 対応する
- 対応内容: S7 の表へ `FingerprintGenerationContext` の行を追加
  (負例 6 形 / 正例 = 正しい組み合わせで構築できる)

## [Warning] (実装モード) context DTO を足すと新規 PHP は 19 本
- 判断: 対応する / 対応内容: ファイル一覧・実装モード・S4 の変更ファイルを 19 本へ統一
