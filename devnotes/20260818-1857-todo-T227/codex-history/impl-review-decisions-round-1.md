# 対応マトリクス: impl-review Round 1

## [Warning] `ClaudeHooksWiringTest` S12c が union 全体の非空しか見ておらず、代表を持たない glob (とくに `.github/workflows/*`) の故障を検出できない

- 判断: 対応する
- 根拠: 指摘のとおり。走査域は 7 本の glob の和集合なので、1 本だけ綴りが壊れても他が非空なら
  S12c は緑のままになる。これは本 TODO が塞ごうとしている「空振りしても緑」そのものである。
- 対応内容: `CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS` を
  **glob => 代表ファイル | null** の写像へ変えた (代表ファイル = 非空が契約 / `null` = 当たらない
  ことが正常。3 通り目を作らない)。S12c は glob ごとに代表ファイルを当てているかを見る。
  `.github/workflows/*` には `ci.yml` を代表として割り当て、
  scripts の下位ディレクトリを見る glob だけを `null` にして理由を docblock へ書いた。
  併せて glob 1 本ぶんを返す `claudeHooksSelfWiringGlobFiles()` を切り出し、
  負のコントロールも glob ごとに空になることを見るようにした。
  赤の確認: `.github/workflows/*` の綴りを壊すと S12c が
  「glob [...] が代表ファイル ... を当てていません」で赤くなることを実行して確認済み。

## [Warning] `AppNameHardcodeTest` は slug が既定値 'app' の間、判定経路が一度も実行されない (負のコントロールはファイル列挙までしか裏取りしていない)

- 判断: 対応する
- 根拠: 指摘のとおり。列挙が生きていることと、判定が生きていることは別である。
  slug を設定した瞬間に効く判定が壊れたまま緑、という状態を許してしまう。
- 対応内容: 判定を `appSlugHardcodeViolations(array $roots, string $needle)` へ分離し、
  「自己検査」ケースで両方向を固定した。
  - 当たる語: `declare(strict_types=1);` (app/ 配下の PHP は全数が宣言している。
    その事実は `StrictTypesDeclarationGateTest` が deny-by-default で強制している) → 非空
  - 当たらない語: このリポジトリのどこにも書かれていない語 → 空
  赤の確認: 判定の `str_contains` を潰すと自己検査が赤くなることを実行して確認済み。
  分類メモ側にも「裏取りが押さえる範囲」を追記し、誇張しないよう限定した。

## [Suggestion] `ProjectMemberPivotWriteScanner::findViolations()` の戻り値を `findDetections()` と同じ固定 array shape にする

- 判断: 対応する
- 根拠: 2 種別を必ず返す契約が型に出るほうがよい。コストも小さい。
- 対応内容: docblock を
  `array{project_members_literal: list<string>, members_relation_write: list<string>}` にし、
  実装も foreach ではなく 2 キーを明示的に組み立てる形へ変えた (PHPStan level 10 で No errors)。

## [Suggestion] inline 側の床値 200 に実測件数の記録がない

- 判断: 対応する
- 根拠: 床値の余裕がどれだけあるかは、次にこの値を触る人が必要とする情報である。
- 対応内容: 実測して床値とコメントを揃えた。
  - `ValidationAttributeCoverageTest` の inline 母集団: 床値 400 (実測 793 件)
  - `ProjectMemberPivotWritePathTest` の走査ファイル: 床値 400 (実測 827 件)
  他 2 か所 (FormRequest 34 / Model 40) は元から実測値を書いてある。
