# 対応マトリクス: design-review Round 2

## [Critical] `AnalysisBudget` が YAML から実値を導出するため 360 秒を固定するテストが消えている
- 判断: **対応する**
- 根拠: 指摘は正しい。Round 1 の「値を 1 箇所に集約する」対応をやり過ぎて、
  **drift 検出という不変条件テストの本来の目的を壊していた**
  (3 YAML と deadline を同時に 300/900 へ変えても全部 green になる)。
  Architecture テストにおける期待値の複製は「意図的な重複」である。
- 対応内容: `AnalysisBudget::CLIENT_TIMEOUT_SECONDS = 360` を**仕様値として定義**し、
  「解析 3 プロンプトの `client_options.timeout` がこの仕様値と一致する」ことを検証する形に戻した。
  `clientTimeoutSeconds()` は YAML からの読み出しではなく仕様値を返し、
  YAML 側は `assertPromptTimeoutsMatchSpec()` で突き合わせる。

## [Critical] `ThrowingPromptFake` の `RuntimeException` が import されていない
- 判断: **対応する**
- 根拠: 指摘どおり。`Tests\Support\RuntimeException` として解決され Fatal になる。
- 対応内容: `use RuntimeException;` を追加した。

## [Warning] deadline の T0 定義と実装位置が一致していない
- 判断: **対応する**
- 根拠: 指摘は正しい。設計では T0 を「`run()` 入口」としつつ、コード例は
  `findOrFail()` の**後**で deadline を生成しており、P の説明とも食い違っていた。
- 対応内容: **deadline 生成を `run()` の先頭 (第 1 文) へ移した**。
  これで T0 = `run()` 入口 という定義とコードが一致し、
  P = 「worker が alarm を張った時点 → `run()` 入口」(payload 復元 / handler 解決 / DI) となって
  `findOrFail()` は deadline の内側に入る。施策 2・3・7 の記述もこの定義に統一した。

## [Warning] `Assert::isArray($yaml['client_options'])` の narrowing が PHPStan で保持されない
- 判断: **対応する**
- 対応内容: `$clientOptions = $yaml['client_options'];` とローカル変数へ移してから
  `Assert::isArray($clientOptions)` → `$clientOptions['timeout']` の順にアクセスする形へ修正。
  `$timeout` も同様にローカル変数へ格納してから `Assert::integer()` する。

## [Warning] `ThrowingPromptFake` の時計操作方式が未確定
- 判断: **対応する (提案どおり Closure 注入へ)**
- 根拠: 指摘は正しい。「`travel()` が使えなければ別方式」は詳細設計として不完全で、
  Carbon のグローバル状態管理が Support クラスへ漏れるのも良くない。
- 対応内容: `ThrowingPromptFake` に **`?Closure $onAttempt`** を注入する方式に変更。
  時計を進めるのは**テスト側**が `$this->travel(60)->seconds()` を closure 内で呼ぶ
  (`Illuminate\Foundation\Testing\InteractsWithTime` の trait メソッド)。
  Support クラスは Carbon にも `travel()` にも依存しない。

## [Suggestion] `userMessageFor()` で HTTP status を先に一度だけ取得する
- 判断: **対応する**
- 対応内容: `match(true)` の前に `$status = $this->extractHttpStatus($exception);` を置き、
  arm ではローカル変数を参照する形にした。

## [Suggestion] `providerBusy()` のコメント更新
- 判断: **対応する**
- 対応内容: 「provider の混雑 (429 / 529 / 500・502・503・504)」へ更新。
