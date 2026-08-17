# 実装レビュー Round 2 の対応マトリクス (T223)

Codex 判定: **CHANGES_REQUESTED** (Critical 2 / Warning 1 + 検証未提示 1)

| # | 指摘 | 判断 | 対応内容 |
|---|---|---|---|
| 1 | [Critical] `adjudication_id` の CR/LF 防御漏れ。`re.match` + `$` は Python では末尾の改行 1 個の直前にも一致するため `"A-001\n"` を受理し、機械マーカーと見出しへそのまま出て掲載の完全性を壊す。`supersedes` と移行 hash の鍵も同じ式を使っている | **対応する** | `_ADJ_ID_RE` / `_SHA256_RE` から行頭・行末アンカーを外し、**照合をすべて `fullmatch` に統一**した (`load_adjudications` の id / `_check_supersede_graph` の supersedes / `load_migration` の pin の鍵と値の 4 か所)。理由を定数の直上にコメントで残した。テスト側の `SPEC_BASIS_FORM_RE` も同じ理由で `fullmatch` に揃えた |
| 2 | [Critical] CR/LF 表駆動テストに `adjudication_id` が漏れている。少なくとも `"A-001\n"` / `"A-001\r"` の拒否ケースを足すこと | **対応する** | `test_identifier_with_trailing_newline_is_rejected` を新設 (id と supersedes × CR / LF の 4 ケース)。`test_bad_adjudication_id_form_is_rejected` にも `"A-001\n"` / `"A-001\r"` / `" A-001"` を足した。移行 hash の鍵と値の末尾改行も `test_provenance_shape_and_heading_count` のケースに追加した |
| 3 | [Warning] `SPEC_BASIS_EXTENSIONS` と正規表現が別々に手書きで、許可側の pin になっていない (式だけ広げても拒否例に無ければ全緑) | **対応する** | 正規表現を `SPEC_BASIS_EXTENSIONS` から組み立てる形に変え、**定数を唯一の正本**にした (長い順に並べて `jsonl` が `json` に食われないようにしてある) |
| 4 | [Warning] `pnpm test:packages` の結果が未提示 | **対応する** | 完了を待って実測した (Test Files 10 passed / Tests 106 passed)。これで AGENTS.md の検証コマンド一式が全 green である |

## 追加でこちらから行ったこと (Round 2 の指摘から派生した自己点検)

Round 2 の Critical 1 を修正した直後に**変異試験**を行ったところ、
`test_identifier_with_trailing_newline_is_rejected` が「id 検査を `match` に戻しても緑のまま」だった —
id を壊すと移行台帳の鍵が解決できなくなり、**別の理由の `RenderError`** が上がっていたためである。
同じ masking は機械項目の変更全般に起きる (機械項目を触ると `machine_projection_sha256` の pin も外れる)。

そこで**否定系テストの期待を「例外の型」から「失敗理由」へ引き上げた** —
主要な negative test を `assertRaisesRegex` にし、生成器側のエラーメッセージと 1:1 で突き合わせるようにした
(marker 混入 / 改行 / id 書式 / supersede の 4 点 / 機械項目の欠落 / context の形 / JSON の読み方 /
移行台帳の語彙・整数・痩せ・断片・解決不能・形)。

変異試験でこの点検を裏づけた (いずれも赤になることを確認し、その後に復元して全緑を再確認した):

| 変異 | 結果 |
|---|---|
| id の照合を `fullmatch` → `match` に戻す | 4 failures / 1 error |
| `_check_inline_text` から機械マーカーの検査を外す | 9 failures |
| 移行台帳の鍵の重複検査を外す | 1 failure |

反論・見送りはゼロ件である。
