Round 3 の指摘（Warning 1 件・Suggestion 1 件）に対応しました。再レビューをお願いします。

## 対応マトリクス

### [Warning] 「1 文字 = 最大 2 token」は数学的上限ではない → 対応
- 上限を **UTF-8 バイト数基準**（`strlen($text)`）へ変更。byte-fallback BPE 系 tokenizer では「token 数 ≤ UTF-8 バイト数」が構造的な安全側上界（1 byte が 1 token より細かく割れることはない）であることを根拠に採用。
- config を `analysis_max_text_bytes => 150_000` に変更（入力 budget = context 200,000 − 出力予約 16,000 − 固定プロンプト 4,000 = 180,000 token に対しマージン込み。日本語 3 bytes/字で実質約 5 万字）。
- 算術 `max_text_bytes + 出力予約 + 固定分 ≤ context` は config 不変条件テストで CI 固定。
- **防御第二層**を追記: 上限内でも provider が入力長で拒否した場合は当該例外を握って failJob（ユーザー向けエラー）。長さ起因の失敗は有界リトライの対象にしない（リトライは JSON 検証失敗のみ）。

### [Suggestion] terminal tx / failJob のインターリーブテスト → 採用
- テスト計画に明記: (a) cron 先勝ち → pipeline は materialize/commit/succeeded を行わない、(b) pipeline 先勝ち → 後追い cron/failed() は no-op、(c) materialize 例外 → rollback + failed + released、(d) commit 例外（非 Reserved）→ terminal tx 全体 rollback + failed。
- 不変条件「failed ∧ committed が共存しない」「succeeded ∧ released が共存しない」をアサーションに含める。

---

改訂後の概念設計全文: /workspace/devnotes/20260711-0137-ai-analysis/conceptual-design.md（ファイル読み込み可）。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 残る指摘は [Critical] [Warning] [Suggestion] で分類し、Critical/Warning には修正提案を添える
- 日本語で出力
