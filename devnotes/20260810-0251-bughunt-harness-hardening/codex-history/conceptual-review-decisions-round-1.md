# 対応マトリクス: conceptual-review (harness) Round 1

Critical 2 件 + Warning 6 件 + Suggestion 4 件。**全件対応**した（反論なし）。

## [Critical] H-1 の `/proc/<pid>/stat` パースが罠だらけで仕様固定が不足
- 判断: **対応する**
- 根拠: 指摘のとおり。`comm` は括弧で囲まれ**プロセス名に空白や `)` を含みうる**ため、
  `awk '{print $3}'` 的な位置決めは state を誤読する。また `kill -0 -- -pgid` からは
  **group のメンバー一覧が取れない**ので、列挙方法そのものが未定義だった。
  ここを曖昧にしたまま実装に渡すと、dev DB 防御の直前ガードが壊れる。
- 対応内容: helper (`group_live_members`) に切り出し、**5 段階の仕様を概念設計で固定**した:
  (1) `/proc/[0-9]*/stat` を走査、(2) **最後の `") "` 以降**をパースして
  `state` / `ppid` / `pgrp` を確定 (先頭からの位置決めをしない)、
  (3) `pgrp` 一致 かつ `state != Z` が 1 つでもあれば残留、
  (4) 全て `Z` なら停止成功、(5) 走査中に消えた PID は race として無視。
  受入条件にも「`comm` に空白・`)` があっても誤読しない」「PID 消滅 race を残留と誤判定しない」を追加。

## [Critical] H-1 は dropdb 直前ガードなので誤判定の blast radius が大きい
- 判断: **対応する**
- 根拠: 指摘のとおり。procfs パースミスや group 列挙漏れがあると、
  実行中 worker が残っているのに dropdb を許す = **dev DB 防御の実質的な緩和**になりうる。
- 対応内容: 概念設計に「**dropdb 側の条件は 1 mm も広げない**」を明記し、
  H-1 が変えるのは「worker が止まったか」の判定だけで、
  「どの DB を落としてよいか」(`guard_shard_db_name` の regex 一致 + admin role 明示) には
  一切触れないことを宣言した。受入条件 7 に
  「dropdb の条件が広がっていないこと」を独立した検査項目として追加した。
  受入条件 3 に「zombie と非 zombie の**混在は失敗**」も独立して立てた。

## [Warning] H-3 の「`CACHE_STORE=array` なら DB に一切触らない」は保証が強すぎる
- 判断: **対応する (方針を変更)**
- 根拠: 指摘のとおり。`optimize:clear` は複合コマンドで、cache store の設定だけを弄るのは
  **「設定で誤魔化す」**であって構造的な解決ではない。将来 DB を触るタスクが増えれば再発する。
- 対応内容: `OptimizeClearCommand` の実装を読み、**`--except` オプションの存在**を確認した
  (`$exceptions->hasAny([$command, $key])` でキー名 `cache` とコマンド名 `cache:clear` の
  両方に一致する)。方針を **`optimize:clear --except=cache` + `env -i` 隔離**へ変更した。
  検討した 3 案 (a: CACHE_STORE=array / b: 個別 clear へ分解 / c: --except + env -i) を
  表で比較し、(b) を採らない理由 (`ServiceProvider::$optimizeClearCommands` の
  取りこぼしが起きる) も明記した。
  受入条件も「cache store を無効化」から
  「`--except=cache` を伴う」「ambient `DB_*`/`PG*` が渡らない」
  「親が `CACHE_STORE=database` でも database store を使わない」の 3 条件へ分割した。

## [Warning] H-3 は「そもそも optimize:clear が必要か」を一段確認すべき
- 判断: **対応する**
- 根拠: 「機能の名前に立ち返れ」の原則どおり。必要なのは bootstrap cache の破棄であって
  アプリケーションキャッシュの削除ではない。
- 対応内容: 施策 H-3 の冒頭に「機能の名前に立ち返る」段落を置き、
  `optimize:clear` の description ("Remove the cached bootstrap files") と
  `getOptimizeClearTasks()` の中身 (config / cache / compiled / events / routes / views +
  ServiceProvider 登録分) を示して、**DB に触るのは `cache` だけ**であることを根拠にした。
  そのうえで「分解 (案 b) は取りこぼしを作るので採らない」と結論を書いた。

## [Warning] 「zombie は DB 接続を保持しない」は procfs 実装が正しい前提でのみ成立
- 判断: **対応する**
- 対応内容: 受入条件 2・3 で「非 zombie が 1 つでもあれば失敗」「混在は失敗」を
  独立に固定し、self-test が `Z` と `S/R/D` の混在ケースを持つことを要求した。

## [Warning] 「bug-hunt が最後まで通るようになる」は表現が強い
- 判断: **対応する**
- 根拠: playwright-cli の既定ブラウザ解決や pcov 未導入といったスコープ外要因が残る。
  保証範囲を誇張しないのは AGENTS.md の基調でもある。
- 対応内容: 期待効果を
  「**既知の harness 起因の停止 4 件を除去し、次回 run が同じ 4 件では止まらなくなる**」
  に書き換え、括弧でスコープ外要因が残ることを明示した。

## [Warning] H-4 は秘密ファイルの複製範囲を広げる。permission の扱いを決めるべき
- 判断: **対応する**
- 対応内容: 「コピー後に親と同等の mode を維持する (`cp -p` 相当、または明示 `chmod 600`)」を
  施策に追記し、受入条件 15 に「world-readable を新たに作らない」を追加した。

## [Warning] self-test だけでは H-1 のすり抜けを固定しきれない
- 判断: **対応する**
- 対応内容: 受入条件を 8 → **15 件**に拡張した。H-1 だけで 7 件 (全 zombie / 非 zombie 残留 /
  混在 / comm パース / PID 消滅 race / stderr 出力 / dropdb 条件不変) を立てた。

## [Suggestion] H-4 の契約テストは setup-worktree 全体を走らせると副作用が大きい
- 判断: **対応する**
- 対応内容: 「実行時ファイルのコピー部分を関数へ切り出し、
  composer install / pnpm install / DB 作成を走らせずにコピーの契約だけ検証できるようにする」を
  施策 H-4 に明記した。

## [Suggestion] `.env.bughunt.local` に本番相当 credential が混入しない運用前提の確認
- 判断: **詳細設計で扱う**
- 根拠: `.env.bughunt.local.example` は「専用 role `bughunt` (CREATEDB なし・dev DB へ
  CONNECT 不可)」を前提として書かれており、構造上は本番 credential が入る設計ではない。
  ただし「入っていないこと」を実ファイルで確認するのは詳細設計フェーズの作業。

## [Suggestion] 使命との整合性 / スコープの適切さ
- 追加対応なし (APPROVE 評価を受領)。
