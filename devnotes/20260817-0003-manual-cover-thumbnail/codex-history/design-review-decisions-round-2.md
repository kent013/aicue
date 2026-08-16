# 対応マトリクス: design-review Round 2

Round 2 は全 10 施策が APPROVE。全体判定のみ CHANGES_REQUESTED (検証レーンの不足) だった。

## [Warning] 実装完了条件の検証コマンドが AGENTS.md の必須集合と一致していない
- 判断: **対応する**
- 根拠: 指摘のとおり。AGENTS.md の `VERIFICATION_COMMANDS` は 10 本を「全 green でコミット」と
  規定しており、package 側を直接変更しないことは省略の理由にならない
  (`verification-commands-doc-sync.test.ts` が package.json の検証系 script との同期を
  deny-by-default で強制している = 集合そのものが契約である)。
- 対応内容: 実装順序の Step 6 を 10 本 (`pnpm typecheck:packages` / `pnpm build:packages` /
  `pnpm test:packages` を追加) に書き換えた。併せて Step 7 として
  一時検証スクリプトを devnotes に残す (`scripts/` へ昇格させない) ことを明記した。

## [Suggestion] eager load の「1 クエリ」の根拠の書き方
- 判断: **対応する**
- 根拠: 検証手順の副作用で矛盾条件 (`video_manual_id is null` と `in (1,2,3)` の同居) が
  混ざった SQL を根拠に「1 クエリ」と書くのは、保証範囲の誇張になる。
- 対応内容: `ofmany-sql-evidence.md` の読み方 3 を書き換え、
  SQL 本文から読めるのは「親をまとめて絞る形になること」までで、
  **1 クエリであることの根拠は Laravel の eager load の仕組み + 施策 8 のクエリ数テスト**である、
  と分けて書いた。
