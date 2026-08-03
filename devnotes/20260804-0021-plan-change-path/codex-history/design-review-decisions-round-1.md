# 対応マトリクス: design-review Round 1

判定: A/C/E は APPROVE、B/D が REQUEST_CHANGES。指摘 4 件はすべて妥当と判断し、全件対応した。

## [Critical] `@throws StaleP1anChangeException` の型名タイポ

- 判断: 対応する
- 対応内容: `StalePlanChangeException` に修正。

## [Warning] `current_plan_code` が `required|string|enum` だと `currentPlanCode === null` で恒常 422

- 判断: 対応する (契約中でも `organizations.plan_code` が null になりうる = 未知 Price 等)
- 対応内容:
  - FormRequest を `['present', 'nullable', 'string', Rule::enum(PlanCode::class)]` に変更
    (**キーの送信は必須**だが値は null 可 = silent な送信漏れは 422 で検出しつつ、
    正当な null で詰まない)。
  - `changePlan()` / `changePlanLocked()` の引数を `?string $expectedCurrentPlanCode` に。
  - `StalePlanChangeException::$expectedPlanCode` も nullable に。
  - Controller は `Assert::nullOrString($expected)`。
  - 判定は `!==` の厳密比較のままなので **null 同士は一致 (stale にならない)**。
  - テスト計画に「null 一致は stale にならない」「キー欠落は 422 / 値 null は通る」を追加。

## [Warning] stale 判定を「同一プラン no-op」より先に置くと、実態が既に目標プランでも stale で弾く

- 判断: 対応する
- 対応内容: 段の順序を **段 2 = 同一プラン no-op → 段 3 = stale 検知** に入れ替え、
  理由 (「実態が既に目標プランなら変更は済んでいるのが事実で、画面が古いことを理由に
  拒否するのは嘘になる」) を docblock に明記。段番号を全体で振り直した。
  テスト計画に「同一プラン かつ stale → `AlreadyOnTargetPrice`」を追加。

## [Warning] `quantity=1` を強制する一方 remote item の quantity を検証していない

- 判断: 対応する
- 対応内容: `normalizeItems()` の戻りに `quantity` を含め、
  `count($items) !== 1 || $items[0]['quantity'] !== 1` を
  `UnexpectedSubscriptionShapeException` に倒す (暗黙補正しない fail-closed)。
  例外のコンストラクタに `?int $quantity` を追加。
  gateway テストに「item 1 個だが quantity !== 1 → 例外 / update 0 回」を追加。

## [Suggestion] 「即時反映」と「反映まで数分」が認知上ぶつかる

- 判断: 対応する
- 対応内容: 確認ダイアログ文言を
  「変更は Stripe 側に即時反映され (画面表示への反映は数分かかる場合があります)、
  差額は日割りで次回のご請求に調整されます。」に統一。
