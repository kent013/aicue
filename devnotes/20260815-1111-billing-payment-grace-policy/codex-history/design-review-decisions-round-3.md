# 対応マトリクス: design-review Round 3

## [Warning] 施策 10: 最後に開始した照会 1 回分がロック期限を跨ぎうる
- 判断: 対応する
- 根拠: 指摘のとおり、実行時間上限は soft limit であり `600 < 900` の比較では保証にならない。
  待ち上限の単一出典 (`App\Support\ExternalClientTimeouts`) を明示的に式へ入れるべきである。
- 対応内容: 定数の docblock に安全余白の式を書き、テストもその式で固定することにした:

  ```
  TIME_BUDGET_SECONDS
    + (STRIPE_CONNECT_TIMEOUT_SECONDS + STRIPE_TIMEOUT_SECONDS) × (1 + STRIPE_MAX_NETWORK_RETRIES)
    < LOCK_SECONDS
  ```

  現行値では 600 + (5 + 20) × 1 = 625 < 900 で成立する (再試行は 0 回で pin 済み)。
  「実行時間上限は soft limit で、最後の照会 1 回分だけ超過しうる」ことも明記した。
  待ち上限・再試行回数・上限値のいずれを緩めてもテストが赤くなる。

## [Suggestion] 施策 10: 定数コメントとテスト計画の表現ずれ
- 判断: 対応する
- 対応内容: `TIME_BUDGET_SECONDS` のコメントを「**各契約の照会の直前**に超過を検査」へ更新。
  テスト計画の「2 ケース」を「3 項目」に直した。

## [Warning] 施策 11: `getRealPath()` は `string|false` で PHPStan level 10 に触れる
- 判断: 対応する
- 対応内容: 判定関数へ渡す経路も relative path を作る経路も `getPathname()` に統一した
  (必ず `string` を返すため narrowing 不要)。`(string)` キャストで黙らせる形は採らない。

## [Suggestion] 施策 11: 負のコントロールの本数の記述ずれ
- 判断: 対応する
- 対応内容: リスク欄の「2 本」を「3 本」に修正した (設計本文と一致)。
