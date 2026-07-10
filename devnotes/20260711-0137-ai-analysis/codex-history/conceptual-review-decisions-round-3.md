# 対応マトリクス: conceptual-review Round 3

## [Warning] 「1 文字 = 最大 2 token」は数学的上限ではない
- 判断: 対応する
- 根拠: 指摘どおり結合文字・希少 Unicode・壊れた抽出文字列では文字ベース係数は上限にならない。
  byte-fallback BPE 系 tokenizer では「token 数 ≤ UTF-8 バイト数」が構造的な安全側上界
  （1 byte が 1 token より細かく分割されることはない）。
- 対応内容: 上限を UTF-8 バイト数基準（`strlen`）へ変更。config を
  `analysis_max_text_bytes => 150_000`（入力 budget 180,000 token に対しマージン込み）とし、
  算術 `max_text_bytes + 出力予約 + 固定分 ≤ context` を config 不変条件テストで CI 固定。
  さらに防御第二層として「provider が入力長で拒否した場合は failJob（長さ起因は
  有界リトライ対象外）」を明記し、context 超過を実行前検出 + 実行時 fail-safe の二段で扱う。

## [Suggestion] terminal tx / failJob のインターリーブを Feature テストで固定
- 判断: 採用する
- 対応内容: テスト計画に 4 インターリーブ（cron 先勝ち / pipeline 先勝ち / materialize 例外 /
  commit 例外）と不変条件（failed ∧ committed の非共存、succeeded ∧ released の非共存）を
  明記した。
