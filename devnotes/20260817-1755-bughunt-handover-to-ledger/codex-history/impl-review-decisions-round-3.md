# 実装レビュー Round 3 の対応マトリクス (T223)

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 1)

| # | 指摘 | 判断 | 対応内容 |
|---|---|---|---|
| 1 | [Warning] `supersedes` へのマーカー混入テストだけ期待が `"書式が不正\|機械マーカーの接頭辞"` と緩く、marker 検査だけを外しても後段の supersede 書式検査が同じ入力を捕まえてテストが緑のままになる (masking が残っている) | **対応する** | 期待を `"機械マーカーの接頭辞"` **だけ**に絞った。指摘どおりの変異 (「`supersedes` に対する `_check_inline_text` のマーカー検査だけを無効化する」) を追加で実施し、**1 failure** で赤になることを確認してから復元し、全 118 本の緑を再確認した |

反論・見送りはゼロ件である。

## 変異試験の記録 (4 件)

| 変異 | 結果 |
|---|---|
| id の照合を `fullmatch` → `match` に戻す | 4 failures / 1 error |
| `_check_inline_text` から機械マーカーの検査を外す | 9 failures |
| 移行台帳の鍵の重複検査を外す | 1 failure |
| `supersedes` に対するマーカー検査だけを無効化する (Round 3 の指摘) | 1 failure |
