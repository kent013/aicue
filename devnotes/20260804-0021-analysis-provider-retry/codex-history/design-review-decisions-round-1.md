# 対応マトリクス: design-review Round 1

## [Warning] `isTransient()` の順序が継承変更に脆い (施策 3)
- 判断: **対応する**
- 根拠: 指摘は正しい。`PrismProviderOverloadedException` が将来 `PrismRateLimitedException` の
  派生になると、先に置いた deny 判定に食われて 529 が非 retry になる。
- 対応内容: 判定順を **「retryable 型を先に、deny を後に」** へ入れ替え、
  さらに deny 側 (429 / 413) を **`$exception::class === X::class` の厳密比較**にした。
  これで「派生型が deny に巻き込まれる」経路が構造的に消える。

## [Warning] `userMessageFor()` が generic `PrismException` の 408/500/502/503/504 を分岐していない (施策 4)
- 判断: **対応する**
- 根拠: 指摘は正しい。`isTransient()` では 408/5xx を区別しているのに、
  文言側は default の汎用文言に落ちており H4 (理由別の次アクション) が一貫しない。
- 対応内容: `extractHttpStatus(Throwable): ?int` を private ヘルパとして追加し、
  **`isTransient()` と `userMessageFor()` の両方から使う** (判定の二重管理を避ける)。
  分岐: 408 → `timedOut()` / 500・502・503・504 → `providerBusy()`。

## [Warning] Architecture テストの `Yaml::parseFile()` 後の型絞り込みが `expect()` 依存 (施策 5)
- 判断: **対応する**
- 対応内容: `Webmozart\Assert\Assert::isArray()` / `Assert::integer()` で静的に `mixed` を潰す
  (`expect()` は PHPStan の narrowing に効かない)。既存の `Assert` 利用イディオムに揃う。

## [Warning] Pest のファイルスコープ `const` / 関数の衝突 (施策 5)
- 判断: **対応する**
- 対応内容: 新規に導入する予定だったグローバル `const` / 関数をやめ、
  **`tests/Support/AnalysisBudget.php` (`final class`)** に `public const` + static メソッドとして集約する。
  2 つの Architecture テストが同じ 1 箇所から C を読むため、
  **360 という値が 2 ファイルに重複しない**という副次的な利点もある。
  既存の `AnalysisTokenBudgetInvariantTest` のトークン系 const
  (`MODEL_CONTEXT_TOKENS` 等) は衝突実績が無いため**そのまま維持**する
  (既存テストの不要な書き換えを避ける)。

## [Warning] deadline 系テストが時計進行に依存して CI で揺れる (施策 6)
- 判断: **対応する**
- 対応内容: deadline 系テストは **`travelTo()` で時刻を固定**し、
  `ThrowingPromptFake` 側で**明示的に `travel()` して時計を進める**設計にする
  (実時間の経過に依存しない)。
  → `ThrowingPromptFake` に「呼び出しごとに時計を進める秒数」を渡せるようにする。

## [Suggestion] 503 連続失敗時の「最終文言」ケースを追加
- 判断: **対応する**
- 対応内容: テスト計画 (A) に
  「`PrismException(previous=503)` ×3 (試行上限) → failed / `error` = providerBusy 文言 / 予約 Released」
  を追加。

## [Suggestion] `withBoundedRetry` の設計 (ループ先頭 deadline guard / deny-by-default / off-by-one)
- 判断: **維持** (「概ね妥当」との評価)。

## [Suggestion] DESIGN.md / Atomic Design は該当なし
- 判断: **維持**。
