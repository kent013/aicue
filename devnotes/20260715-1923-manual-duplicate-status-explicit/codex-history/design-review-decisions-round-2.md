# 対応マトリクス: design-review Round 2 (item2)

## [施策3 Warning] 振る舞いテストが DB default で実装前も pass = fail-first でない・明示代入削除を検出できない
- 判断: 対応
- 対応内容: `duplicate()` のメソッドソースを ReflectionMethod (getStartLine/getEndLine) で取得し、
  `'status' => VideoManualStatus::Draft` と `'scenario_version' => 0` の明示代入が存在することを
  機械的に要求する契約テスト (3-b) を追加。実装前は fail (fail-first)、明示代入を消すと fail =
  本件の目的 (DB default 依存の排除) を直接守る。振る舞いテスト (3-a) は Draft/0 + 元 manual 不変
  + created_by の回帰ガードとして併存。

## [施策1/2] Round 1 の反論を Codex が受理 (APPROVE)
- enum インスタンス代入 = canonical、file 単位 allowlist = 確立粒度、として確定。追加変更なし。
