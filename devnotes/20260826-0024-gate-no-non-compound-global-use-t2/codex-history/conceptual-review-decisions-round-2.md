# 対応マトリクス: conceptual-review Round 2

全体判定 APPROVED (Critical / Warning 0 件)。Suggestion のみ。

## [Suggestion] 観点5: T_OPEN_TAG 直後を含む 4 候補形の既存検体の実読確認 (方針の実施確認)
- 判断: 対応する (詳細設計で実施済み)
- 根拠・対応内容: 検体を実読した。`T_OPEN_TAG` 直後 (コメント読み飛ばし後) の宣言は
  `clean-named-namespace` (1 本目の `namespace App;`) と `clean-bracketed-named` /
  `detects-bracketed-global` が既に持つ。`;` の後は `clean-named-namespace` の
  `namespace Bar;`、`}` の後は `detects-bracketed-after-named` の 2 本目の `namespace {`。
  よって不足は無く、追加検体の形の変更は不要。副産物として、**直前有意トークンの判定は
  コメント・docblock も読み飛ばす必要がある**ことを既存検体が強制すると確認した
  (検体は開始タグと宣言の間にコメントを持つため、空白だけの読み飛ばしでは
  既存検体の宣言が識別子扱いになり照合が赤くなる)。詳細設計に反映する。
- `null` (ファイル先頭) は実ファイルでは開始タグより前に T_NAMESPACE が来ないため
  検体では固定できない (防御的に候補へ含めるのみ)。docblock に明記する。

## その他の Suggestion (観点1/2/3/4/6/7)
- 判断: 受け入れ (設計の現行記述と整合。追加変更なし)。
  `previousSignificant()` nullable + 呼び出し側分岐、`Process::getEnv()` による配線検査は
  詳細設計に反映する。
