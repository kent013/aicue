# 対応マトリクス: design-review Round 3

## [Warning] `prismHttpException()` が Pest のグローバル関数として再登場している
- 判断: **対応する**
- 根拠: 指摘どおり。Round 1 で Architecture テスト側の衝突を回避したのに、
  Feature テスト側で同じ問題を持ち込んでいた。
- 対応内容: **`tests/Support/PrismHttpExceptionFactory.php` (`final class`)** に移し、
  `PrismHttpExceptionFactory::withStatus(int)` で生成する形にした。
  施策 6 の変更ファイル一覧と PHPStan チェック欄にも反映。

## [Warning] `onAttempt` のシグネチャ不一致 (`Closure(int): void` vs arrow fn)
- 判断: **対応する**
- 根拠: 指摘どおり。arrow fn は式の値を返すため `void` の phpdoc と食い違い、
  PHPStan level 10 で落ちうる。
- 対応内容: 使用例とテスト計画の両方を
  `function (int $attempt): void { $this->travel(60)->seconds(); }` に統一し、
  「arrow fn ではなく通常の closure で void にする」旨のコメントを添えた。
  施策 6 の PHPStan チェック欄にも項目を追加。

## [Suggestion] 施策 5 の PHPStan チェック欄が削除済みメソッドに言及している
- 判断: **対応する**
- 対応内容: `clientTimeoutSecondsFromYaml(): array<string, int>` を前提とした記述へ更新し、
  「配列 offset 式のままではなくローカル変数へ移してから narrowing する」
  「`CLIENT_TIMEOUT_SECONDS` / `DEADLINE_SECONDS` は `public const` (int)」を追記した。

## [Suggestion] 施策 4 の「現行コード」に存在しない `extractHttpStatus()` 呼び出しが混入
- 判断: **対応する**
- 根拠: 一括置換の副作用で「現行コード」ブロックにも `$status = ...` 行が入ってしまっていた
  (差分の誤読を招く)。
- 対応内容: 「現行コード」ブロックを実際の現行実装どおりに戻した。
