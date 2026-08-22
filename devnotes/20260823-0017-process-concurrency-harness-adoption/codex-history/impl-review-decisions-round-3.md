# 対応マトリクス: impl-review Round 3

Codex 全体判定: **CHANGES_REQUESTED** ([Critical] 0 / [Warning] 2)

Round 3 の指摘は「**例外メッセージ**と**例外 trace** は別経路であり、
メッセージの伏せ字 (choke point) は trace の引数には効かない」という 1 点に集約される。

## [Warning] 親プロセスの trace に `run()` の引数 (plain API キー等) が残る

- 判断: **対応する**
- 根拠: 妥当である。`zend.exception_ignore_args=0` の環境 (php.ini-development の既定) では
  `getTraceAsString()` が文字列引数を出す。メッセージを作り直しても trace の引数は変わらない。
  Round 1 で「子は trace を出さない」と決めたのと**同じ理由が親側にも当てはまる**のに、
  親側は塞いでいなかった = 非対称が残っていた。
- 対応内容: 秘密を運ぶ引数へ `#[\SensitiveParameter]` を付けた (**20 箇所**)。
  対象は「秘密そのもの」と「子が書いた untrusted な文字列」の両方である:
  - `ConcurrencyProbeRunner::run()` の `$plainApiKey` / `$requestBody`
  - `ConcurrencyProbeRunner::redactedForDiagnostics()` / `redactSecrets()` の `$secrets`
  - `ConcurrencyProtocolException` の `childDiedEarly($stderr)` / `identityMismatch($actual)` /
    `goTokenMismatch($actual)` / `unexpectedObservation($reason)` / `unknownSignal($names)`
    (メッセージに秘密が無く choke point が**元の例外をそのまま返す**場合、
    元の例外の trace が残るため)
  - `ConcurrentProbeObservation::fromDecodedJson()` / `stringValue()` / `intValue()` と
    `ProbeDatabaseCoordinates::fromDecodedJson()` (子の観測 JSON がまるごと引数に載る)
  - `ProcessBarrier::signal()` の `$payload`
  - `ProbeEnvironment::encodeLine()` の `$value` / `writeProtectedFile()` の `$contents`
    (env ファイルの本文は APP_KEY / CIPHERSWEET_KEY / DB パスワードを**全部**含む)
  - テスト側の `harnessRun()` の `$plainApiKey` / `$requestBody` と
    `ScriptedProbeProcess::__construct()` の `$stderr`

## [Warning] 群4-43 が trace を検査していない

- 判断: **対応する**
- 根拠: 同上。メッセージだけを見る検査では、上の穴が開いていても緑のままだった。
- 対応内容: `harnessThrowableText()` が **`getMessage()` と `getTraceAsString()` の両方**を
  連鎖の各段ぶん集めるようにした。群 4-43 の 2 経路 (stderr / 合図の中身) は
  この全文に対して 5 種の sentinel が現れないことを検査する。
