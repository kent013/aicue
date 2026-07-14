# 対応マトリクス: impl-review Round 4

Codex 全体判定: **APPROVED**（Critical 0 / Warning 0 / Suggestion 1）。

## [Suggestion] onCaptureActiveChange の doc コメントが "phase !== idle" のまま
- 判断: 対応する（動作影響なし・契約明確化のため）
- 根拠: 実際の active 定義は `starting || resuming || phase !== "idle"`。コメントを実装に一致させる。
- 対応内容: Props の `onCaptureActiveChange` コメントを実定義に更新（コメントのみ、挙動不変）。

合議終了: Round 4 で APPROVED。以降コード変更はコメント 1 行のみ。
