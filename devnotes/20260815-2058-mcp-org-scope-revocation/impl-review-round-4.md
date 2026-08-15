**tests/Architecture/McpAuthorizationChokePointTest.php**

指摘なし。訂正後の説明は検出器の挙動と一致しています。

- `authorizeTool()` の結果を直接否定する字句形だけを許可する
- 呼び出しと `throw` の間に文や分岐があれば一律に違反とする
- 権限割り当ての意味論までは保証しない
- 実挙動は Feature テストが担当する

過小申告・誇張ともに解消されています。

**devnotes/20260815-2058-mcp-org-scope-revocation/detailed-design.md**

指摘なし。実装時の訂正、検出器を強化した経緯、fail-closed に倒す範囲が現在のコードと整合しています。

**AGENTS.md**

指摘なし。T175を15、T174を16とする繰り下げは項目の意味や契約を変更しません。提示された範囲には番号15をT174として参照する記述はなく、取り込み後のArchitectureテストを含む全数検証も通っています。他文書は機能名や節名による参照であり、参照切れを示す材料はありません。

main取り込み後の全検証結果も、PHPStan level 10、PHP/Pest、フロントエンド、packagesを含め必要なレーンを満たしています。残存する [Critical] / [Warning] はありません。

全体判定: APPROVED