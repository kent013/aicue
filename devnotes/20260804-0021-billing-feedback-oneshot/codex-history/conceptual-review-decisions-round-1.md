# 対応マトリクス: conceptual-review Round 1

## [Critical] 追加 303 で既存 flash (特に `?portal` 着地の error) を失う
- 判断: **対応する**
- 根拠: 正当な指摘。Laravel の flash は「次の 1 リクエスト」までなので、hop を 1 段挟むと
  hop 自身が消費してしまう。現行の「error flash がある `?portal` 着地では feedback を出さない」
  (= error を優先する) 不変条件が、error 自体の消失によって無意味化する。
- 対応内容: 概念設計に「着地 hop で他の状態を落とさないための規約」節を追加。
  `session()->keep(['success','error','info','warning'])` で 4 キーを透過させ、
  `error` 存在時は feedback を積まない現行ルールを維持。
  テスト計画に「`?portal` + error flash で error が次 render に生存する」を追加。

## [Critical] canonical を素の `/billing` にすると他の query state を消す
- 判断: **対応する** (ただし事実確認の結果、消えうる query は `highlight` の 1 つだけ)
- 根拠: `/billing` の query surface を grep で全数確認した:
  `setup_session_id` / `session_id` / `portal` / `replayed` / `retry` (BillingController) と
  `highlight` (Index.svelte の scroll anchor) の 6 つで閉じている。
  `?plan=` handoff は `IntendedPlanResolver` を呼ぶ `OnboardingController` / `SocialAuthController`
  の所管で `/billing` には来ない。`return_to` は session キー (`onboarding.return_to.org.{id}`) で
  query ではない。→ 「消えうる他 query」は `highlight` のみ。
- 対応内容: 畳む query を **allowlist (feedback 専用 query だけ)** とし、`highlight` は保持する方針を明記。
  併せて着地 3 系統の優先順位・相互排他も明記し、テストで固定する。

## [Warning] 「履歴・ブックマークのいずれからも復活しない」は言い過ぎ
- 判断: **対応する**
- 根拠: そのとおり。query 付き URL を手入力・外部保存された場合は再び着地する。
- 対応内容: 期待効果の表現を「通常のリロード・戻る・ブックマーク起点での再発を構造的に防ぐ」に狭め、
  手入力再訪は非スコープと明記。あわせて「その場合も表示は DB 現在値から再導出されるため
  嘘にはならない」ことと、完全単回消費が代案 C (GET 副作用) を要することを併記。

## [Warning] 複数 query 同時到達時の優先順位が未定義
- 判断: **対応する**
- 根拠: `/billing` には既に 3 系統の着地があり、順序が実装の並び順という暗黙知になっている。
- 対応内容: 優先順位を概念設計 + (詳細設計で) `index()` docblock + docs/architecture.md に明記し、
  `?setup_session_id` × `?session_id` 同時指定のテストを追加。

## [Warning] flash からの復元経路 (型) が未記述
- 判断: **対応する**
- 根拠: session からの取り出しは `mixed`。PHPStan level 10 では narrowing が必須。
- 対応内容: `is_string()` → `BillingFeedbackKind::tryFrom()` → 未知値は null (fail-closed) の
  復元経路を明記。`BillingFeedbackDto::fromKind(BillingFeedbackKind)` のみを公開し、
  生文字列が DTO 境界を越えない設計にする。

## [Suggestion] docs では「サーバ再主張を防ぐ one-shot」であることを明示
- 判断: **対応する**
- 対応内容: docs/architecture.md に書く内容を (a) one-shot の定義 (b) 担保方式
  (c) 副作用境界 の 3 点に具体化した。

## [Suggestion] DTO 側で array-shape も明示
- 判断: **見送る (既に充足)**
- 根拠: `BillingFeedbackDto` は既に `@phpstan-type BillingFeedbackShape` を持ち、
  `toArray()` の戻り値に適用済み。今回 shape は変えない。

## [Suggestion] 使命との整合 / 禁止事項 / 実現可能性 / `/purchase-tickets` 切り離し
- 判断: 指摘なしのため対応不要 (現状維持)。
