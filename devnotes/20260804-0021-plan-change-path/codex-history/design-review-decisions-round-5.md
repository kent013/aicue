# 対応マトリクス: design-review Round 5

判定: A/C/D は APPROVE、B/E が REQUEST_CHANGES (Critical 1 件 + Suggestion 1 件)。全件対応した。

## [Critical] 段 4 の「local `organizations.plan_code` が同一なら成功」は projection を真実源にしている

- 判断: **対応する** (指摘のとおり自己矛盾。設計自身が「local 列は webhook 遅延の projection で
  remote 判定に使えない」と定義しているのに、no-op 判定でその列を真実源にしていた。
  remote が別 Price のままでも「受付済み」と嘘をつきうる)
- 対応内容:
  - **段 4 の同一プラン no-op を削除**し、**同一プラン判定は gateway の remote 照合に一本化**。
    `Applied` / `AlreadyOnTargetPrice` は remote の事実だけで決まる。
  - stale 検知は **「要求先 ≠ local 現在プラン」のときだけ**評価する
    (`$org->plan_code !== $plan->code && $org->plan_code !== $expectedCurrentPlanCode`)。
    これで「反映待ち中の再操作 / 古い画面からの同一プラン再送」を stale で誤拒否しない。
  - 段番号を 段 1 契約再読込 → 段 2 state → 段 3 schedule → 段 4 stale → 段 5 swap に整理。
  - テスト計画を差し替え:
    - local 対象 / remote 対象 → **gateway が呼ばれ** `AlreadyOnTargetPrice`
    - local 対象 / remote 別 Price → **gateway が呼ばれ** `Applied`
    - 要求先 = local 現在プラン かつ期待値が古い → stale にならず gateway へ進む
    - 要求先 ≠ local 現在プラン かつ期待値も不一致 → `StalePlanChangeException` / gateway 0 回
    - grace period → 段 2 で `InvalidArgumentException` / gateway 0 回
  - `docs/architecture.md` の追記内容も同じ契約へ更新
    (「local が目標プランでも早期 return しない」「同一プラン判定は remote 照合に一本化」)。

## [Suggestion] `ChangePlanRequest::rules()` 直前コメントに「表示用 currentPlanCode」が残っている

- 判断: 対応する
- 対応内容: 「送信元は画面の `planChangeExpectedPlanCode` (= `organizations.plan_code` そのもの)」
  へ書き換え、`present` + `nullable` の意図 (送信漏れは 422 / 正当な null で詰まない) を明記。
