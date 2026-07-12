# 対応マトリクス: design-review Round 2

## [Warning] unreachableFailureView の未使用引数が静的検査に抵触
- 判断: 対応する
- 対応内容: `_value: never` にリネーム (never による網羅性は維持)

## [Warning] リスク欄に「behavior 未指定」の旧記述が残存
- 判断: 対応する
- 対応内容: 「`behavior: "auto"` を明示するため smooth scroll にならず、実ブラウザでも instant scroll で flake リスクなし」へ更新

## [Warning] PHPStan適合チェックに旧関数名 assertNever が残存
- 判断: 対応する
- 対応内容: `unreachableFailureView` へ修正し、検証対象が `pnpm typecheck` + `pnpm lint` (未使用引数規則) であることを明記

## [Suggestion] 403 固定文言の反論支持 / action 条件付き受け渡し等
- 判断: 現状維持 (肯定的評価)
