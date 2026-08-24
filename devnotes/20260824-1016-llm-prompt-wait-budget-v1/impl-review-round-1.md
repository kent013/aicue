結論として、判定ロジックの単一化と負例による裏取りは成立しています。現行入力で再現できる gate の偽グリーンは見当たりません。ただし、運用契約の説明に矛盾が残るため修正を求めます。

## ファイル別判定

### [docs/architecture.md](/workspace/.claude/worktrees/tasks/T255/docs/architecture.md)

判定: OK

実効タイムアウトの断定を3前提付きへ変更し、読み取り経路・機械保証の範囲・前提崩壊時の対応を分離できています。施策7どおりです。

### [docs/template-divergence.md](/workspace/.claude/worktrees/tasks/T255/docs/template-divergence.md)

判定: 要修正

[Warning] D50の業務要件説明が、同ファイルの「保証しないもの」と `docs/architecture.md` の条件付き契約に一致していません。

D50では、宣言が落ちると実効値が30秒へ縮むことを無条件に述べ、さらにその結果として「ワーカーが塞がって後続が詰まる」としています。しかし実効値が30秒へ短縮されるなら、直接の帰結は解析の早すぎる失敗です。ワーカー占有が長期化する説明にはなりません。

次のように整理すべきです。

- 30秒へのフォールバックは「3前提が成立する現行実装では」と条件付ける
- 直接の障害は「解析の早期打ち切り」とする
- リトライ反復によるキュー圧迫を主張するなら、その具体的な経路と上限を示す
- 根拠がなければ「ワーカーが塞がる」は削除する

D50登録、47件へのpin、対象パスは詳細設計どおりです。

### [PromptClientTimeoutInvariantTest.php](/workspace/.claude/worktrees/tasks/T255/tests/Architecture/PromptClientTimeoutInvariantTest.php)

判定: 要修正

[Warning] 冒頭コメントの「宣言を落とすと実効値は30秒へ縮む」も無条件の断定になっています。直後の「このgateは実効値を保証しない」と矛盾するため、`docs/architecture.md` と同じ3前提付きの表現にしてください。

コード上は以下を満たしています。

- インライン判定の完全削除
- `PromptWaitBudget` への単一化
- 分母ゼロの単独テスト内検査
- 既知5本の包含による到達証明
- 再帰性を裏取りしていない限界の明記
- 収集した `$labels` / `$missing` / `$violations` をすべて判定に使用

再帰退行を検出しない点は明示的に保証範囲から外されており、隠れた偽グリーンではありません。

### [PromptWaitBudget.php](/workspace/.claude/worktrees/tasks/T255/tests/Support/PromptWaitBudget.php)

判定: OK（改善提案あり）

5類型と解決不能3段はfail-closedです。`requirePositive()` の不整合チェックも適切で、旧実装の `0`・負数通過を塞いでいます。

[Suggestion] `PromptYaml::parseOrFail()` が将来 `null` と空の `$parseErrors` を返した場合、`violations()` は空配列を返せます。一方、`requirePositive()` は同じ状態を例外にするため、公開2口が非対称になります。

現在の構文不正・非map見本がこの回帰を検出するため、現時点のブロッカーではありません。ただし、`$parsed === null && $parseErrors === []` を内部不整合の違反または例外に正規化すると、クラス単体でもより強いfail-closedになります。

### [PromptWaitBudgetTest.php](/workspace/.claude/worktrees/tasks/T255/tests/Unit/Architecture/PromptWaitBudgetTest.php)

判定: OK

- 9類型を件数ではなくラベル集合で照合
- 正例による誤検出防止
- 解決不能3分岐を分類付きで固定
- `requirePositive()` の拒否・正常値の両方向
- 公開口を通したファイル読込・parse委譲込みの検証

`toContain()` の可変長引数問題を `str_contains()` と `toBeTrue()` へ変更した逸脱も妥当です。検出力は落ちていません。

### 見本YAML 12本

判定: すべてOK

`missing-client-options`、`client-options-not-array`、`missing-timeout`、`zero`、`negative`、`numeric-string`、`float`、`bool`、`null`、`declared`、`broken`、`list-top-level` は詳細設計の類型と一致しています。

### [AnalysisBudget.php](/workspace/.claude/worktrees/tasks/T255/tests/Support/AnalysisBudget.php)

判定: OK

旧 `Yaml` / `Assert` 実装を残さず削除し、正の整数保証を `requirePositive()` へ委譲しています。仕様値 `CLIENT_TIMEOUT_SECONDS` との意図的な二重化も維持されています。

### [AnalysisTokenBudgetInvariantTest.php](/workspace/.claude/worktrees/tasks/T255/tests/Architecture/AnalysisTokenBudgetInvariantTest.php)

判定: OK

OCR変種のtimeoutだけを単一読み取り器へ寄せ、`max_tokens` の既存検査と3段パイプラインの `PROMPT_NAMES` を混同していません。

### [LedgerPins.php](/workspace/.claude/worktrees/tasks/T255/tests/Support/TemplateDivergence/LedgerPins.php)

判定: OK

46→47は台帳宣言およびD50追加と一致しています。

## テストと偽グリーンの評価

赤→緑の実測は、特に以下の退行を実際に検出できる証拠になっています。

- `<= 0` 判定の削除
- `is_int()` から `is_numeric()` への緩和
- 既知promptの到達証明欠落
- 未登録テンプレート乖離

一方、報告された `composer test` には5件のriskyがあります。今回追加したテストは明示的なassertionを持つため直接の原因には見えませんが、「全green」と扱う前に既存由来かを確認しておくべきです。

## 全体判定

**CHANGES_REQUESTED**

コード上の単一化・fail-closed・負例検証は承認できる水準です。`PromptClientTimeoutInvariantTest.php` とD50の無条件な実効値断定、および30秒への短縮とワーカー閉塞を結び付けた説明を、条件付き運用契約と整合させれば承認できます。