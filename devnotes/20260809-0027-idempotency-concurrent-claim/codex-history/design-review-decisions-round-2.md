# 対応マトリクス: design-review Round 2

## [Critical] 施策 B — backfill を検証するテストが無い (最終スキーマ検査では変異を捕まえられない)
- 判断: **対応する**
- 根拠: 指摘のとおり。`IdempotencyStateMigrationTest` は最終スキーマしか見ておらず、
  `IdempotencyTest` の再生テストは migration 完了後に Factory で行を作るため、
  backfill を `indeterminate` に変えても赤くならない (mutation #22 が空振りしていた)。
- 対応内容: 「既存行は completed へ backfill される」テストを追加した。
  (1) `state` 列と index を落として旧スキーマ相当へ戻す、
  (2) 旧実装が書いていた形の行を query builder で 1 件用意、
  (3) 対象 migration を `require` して `up()` を直接実行、
  (4) 行が `completed` で `response_status` / `response_body` が無傷であることを検証。
  pgsql は DDL がトランザクショナルなので `RefreshDatabase` のロールバックで巻き戻る旨も明記。
  mutation #22 の赤化対象を本テストへ変更した。

## [Critical] 施策 D — prune コマンドの import 誤り
- 判断: **対応する**
- 根拠: 指摘のとおり。`cutoff()` を廃止した際に `CarbonImmutable` の import を入れ忘れ、
  未使用になった `IdempotencyRetention` の import が残っていた。
  現 namespace では `App\Console\Commands\Operations\CarbonImmutable` に解決されて壊れる。
- 対応内容: `use Carbon\CarbonImmutable;` を追加し `use App\Support\Idempotency\IdempotencyRetention;` を削除。

## [Warning] 施策 F — テスト数の記載が 8 本のまま (実際は 9 本)
- 判断: **対応する**
- 根拠: `conflict_codes` のテストを足したのに見出しと末尾の数を直していなかった。
  実装漏れに直結する不整合。
- 対応内容: 「テスト本体 (9 本)」「上記 9 本」に訂正した。

## [Warning] 施策 H — リスク節に「commit を証明する」旧記述が残存
- 判断: **対応する**
- 根拠: テスト表と「保証しないもの」2 番の修正に対してリスク節が追随していなかった。
- 対応内容: 「テスト 1 は claim 行が controller 実行前に作られ同一接続から processing として
  観測できることを証明する」に書き換え、並行安全性の根拠を
  (a) claim の先行 (テスト 1) / (b) unique による 2 本目の排除 (テスト 3) /
  (c) 外側 transaction 不在 + pgsql autocommit・read committed (テストではなく実装と DB の性質)
  の 3 つに分けて明記した。

## [Suggestion] 施策 E — 前提テスト末尾のコメントが主張範囲と食い違う
- 判断: **対応する**
- 対応内容: 「観測上、revoke と再送のどちらでも冪等行は作られない (= 配線しても再生応答が
  返る経路が無いという免除理由の裏取り)」に書き換えた。

## その他 (施策 A / C / G / I は APPROVE)
- 判断: **対応不要**
