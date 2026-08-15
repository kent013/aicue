# 対応マトリクス: impl-review Round 1

## [Warning] handle() の例外経路が CAS 0 件でも必ず再 throw する (StripeWebhookProcessor.php)

- 判断: 対応する
- 根拠: 指摘のとおり非対称だった。世代を追い越された実行は行の決着に関与しないので、
  成功経路で 200 を返すなら失敗経路でも 200 を返すべきである。500 を返しても Stripe の
  再送は `claim()` に弾かれて 200 で終わるため、得られるものが無く運用ノイズだけが残る。
- 対応内容: `handle()` の catch で `finalize()` の戻り値を受け、false のときは
  `report()` だけ行って `return` する (throw しない)。設計書 (施策 C の変更後コードと
  テスト計画) も同じ内容へ更新した。

## [Warning] 失敗 CAS 経路のテストが無い (StripeWebhookStaleRecoveryTest.php)

- 判断: 対応する
- 根拠: 上の挙動はテストが無ければ「実装済み」と言えない (禁止事項 1)。
- 対応内容: 「HTTP 経路で世代を追い越されたら処理が失敗しても例外を投げない」を追加。
  mock 内で `attempts` を進めたうえで例外を投げ、行が `received` のまま
  (`failed` にならず `failure_reason` も付かず) 例外が外へ出ないことを固定した。

## [Suggestion] migration の down()/up() を try/finally で戻す

- 判断: 対応する
- 根拠: assert 失敗時に同一プロセスの後続テストへ schema 破損を残し得るのは実害がある。
  対策のコストも小さい。
- 対応内容: `down()` と assert を `try`、`up()` を `finally` に置いた。
