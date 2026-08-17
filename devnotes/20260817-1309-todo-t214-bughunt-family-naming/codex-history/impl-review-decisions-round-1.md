# 実装レビュー Round 1 の対応マトリクス (T214)

Codex (`gpt-5.5` / high) の全体判定は **CHANGES_REQUESTED**。Critical 0 / Warning 2 / Suggestion 2。

| # | 分類 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Warning | `verify-rename-only.php` が `echo` を使っており、AGENTS.md「禁止する文 (echo / goto / global / 開始タグ付きの出力記法)」に反する | **対応する** | 出力を `fwrite(STDOUT, ...)` の 1 関数 (`out()`) へ集約した。走査 (`ForbiddenStatementTokenInvariantTest`) は devnotes を除外するため機械では止まらないが、規約は置き場所に関係なく適用される (指摘が正しい) |
| 2 | Warning | A-6 の検証スクリプトが未実行で、`rename-verification.md` も差分に無いため A-6d / A-6e / A-10 を満たしたと判定できない | **対応する** | 実装をコミットしたうえでスクリプトを実走し、出力を `rename-verification.md` へ写して同じコミットへ畳んだ。結果は Round 2 のプロンプトへ添付する。母集団を `main...HEAD` から取る設計上、コミット前には実行できない (設計の実行順序どおり) |
| 3 | Suggestion | N-4 の負のコントロールが `docs/TODO-closed.md` だけを使っている。逸脱で足した `docs/TODO.md` の 1/1 pin にも件数ずれの検出ケースが欲しい | **対応する** | N-4 に (f) を追加。`docs/TODO.md` について「pin ちょうどは沈黙」「1 件多いと 1 件検出」「片方 0 件・他方 2 件で 2 件検出」を同じ述語で通す |
| 4 | Suggestion | `META_ALLOWED_PREFIXES` の `str_starts_with()` 判定だと `docs/TODO.md.backup` のような別ファイルまで通る | **対応する** | TODO 台帳 2 冊を `META_ALLOWED_EXACT` の**完全一致**へ分離し、接頭辞判定は本 devnotes ディレクトリだけに限定した |

## ファイル別判定について

差分 34 ファイルのうち `verify-rename-only.php` 以外はすべて OK 判定。逸脱 3 点
(`docs/TODO.md` の件数 pin 追加 / `A-6a-imports` 分類の新設 / `composer dump-autoload` の必要性) は
いずれも妥当と判定された。
