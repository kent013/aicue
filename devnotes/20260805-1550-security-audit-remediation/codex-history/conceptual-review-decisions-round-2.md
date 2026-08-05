# 対応マトリクス: conceptual-review Round 2

## [Critical] API `ResolveApiActor` の存在オラクルをスコープ外に残せない (指摘 2 / 指摘 6)

- 判断: **対応する (今サイクルで閉じる)**
- 根拠: 指摘を受けて「なぜ閉じられないと思ったのか」を再検査したところ、前提が誤っていた。
  `ResolveApiActor` の実装 (`app/Http/Middleware/ResolveApiActor.php`) は
  **route binding に一切依存しない** — 参照するのは `$request->attributes->get('api_key')` と
  `$request->user('api-oauth')` のみで、`$request->route(...)` を読む箇所は無い
  (エラーメッセージ用の route 名参照すら無い)。
  したがって `SubstituteBindings` の**前**に移動できる。
  移動すると actor 解決の 401/403 は「不在 id がまだ 404 になっていない時点」で返るため、
  `Authenticate` の 401 と同じく**構造的にオラクルになり得ない**。
- 対応内容: S2 の priority chain を
  `prependToPriorityList(SubstituteBindings, ResolveApiActor)` に変更。
  これで **exemption inventory の登録件数は 0 件**になり、
  「不変条件の期限付き受容」という論点自体が消える。
  `throttle` (priority 6/7) より後・`Authenticate` (priority 5) より後という既存の前提も維持される。
  スコープ外 1 を削除した。

## [Warning] 「SubstituteBindings の直後」という表現が厳密でない / 実 route での検証が必要

- 判断: 対応する
- 対応内容: `appendToPriorityList()` は middleware を注入せず、**その route に実在する middleware の
  相対順序だけ**を整える、と明記。あわせて S4 のテスト計画に、
  指摘された 6 種類の実 route を `Router::gatherRouteMiddleware()` で検証する項目を列挙した
  (API guard のみ / web guard のみ / `{project}` 無し同一 group / `verified` / 2FA / 課金 /
  Inertia / ability / idempotency をそれぞれ含む route)。

## [Warning] 「SubstituteBindings より前の短絡はオラクルになり得ない」は一般命題として強すぎる

- 判断: 対応する (指摘どおり。反論しない)
- 根拠: 前段 middleware が生の route parameter を読んで DB 照会すれば、binding 前でも
  存在依存の応答を作れる。現行の前段 middleware (Authenticate / ThrottleRequests /
  PreventRequestForgery / AuthenticateSession / ResolveApiActor) が
  そうしていないのは**実装の性質であって構造的保証ではない**。
- 対応内容: 命題を「**現在登録されている前段 middleware は route resource の存在に依存しない**」に限定し、
  その性質自体を inventory (`PreBindingShortCircuit` 相当) に記録して機械検証対象にした。

## [Warning] S4 の分類対象が `App\Http\Middleware\*` だけでは不足 (framework / vendor / closure が漏れる)

- 判断: 対応する
- 根拠: 今回の原因の 1 つ (`EnsureEmailIsVerified`) は framework 側の middleware であり、
  namespace 走査では捕まらない。指摘は正しい。
- 対応内容: 分類対象を「**検査対象 route の解決済み middleware 列に実際に現れた全クラス**」に変更
  (由来を問わず未分類なら fail)。closure middleware は「分類不能」として fail させる。
  検査対象 route の母集団も deny-by-default にするため、
  `NestedRouteDefenseMode` を拡張して **route parameter を持つアプリ所有 route すべて**を
  分類必須にする (現行の 2 param 以上という制限を撤廃し、`ScopedBinder` /
  `TenantGuardMiddleware` の case を追加)。

## [Warning] S5 の production fail-fast は運用契約が片側だけ

- 判断: 対応する
- 対応内容: `production:preflight` を「組み込めば検知できる」ではなく
  **本番デプロイ手順上の必須 gate** と位置づけ、`docs/trusted-proxies-runbook.md` を新設して
  owner / 実 proxy hop の記録 / CIDR 管理主体 / 変更手順 / preflight 実行証跡 / rollback 条件を固定する。
  完了条件に「実 proxy hop と CIDR 管理主体を runbook に記入すること」を含める
  (リポジトリからは構成が確認できないため、記入は運用者の作業として明示する)。

## [Warning] S7 の「enum case が app/ で参照される」検査は弱い

- 判断: 対応する
- 対応内容: grep 的走査をやめ、
  `SecurityEventType case → 記録経路 (購読イベント or 直接記録の呼び出し元)` の
  **構造化 map** を正本にする。Architecture テストは
  (a) enum 全 case と map key の完全一致、(b) event 駆動 case の subscriber 登録、
  を検査し、Feature テストが実際に行が増えることを固定する。

## [Warning] env CSV の正規化結果だけを型付けすると不正値が消える

- 判断: 対応する
- 対応内容: config は `proxies` (検証通過後 `list<non-empty-string>`) と
  `raw_proxies` (生 token `list<string>`、空要素・空白も保持) の 2 本立てにし、
  validator は**両方**を受け取る (`TrustedHostsConfigValidator` の `raw_wildcards` と同じ作法)。
  「validator 通過前の値を `list<non-empty-string>` と断定しない」を設計に明記した。

## [Suggestion] 使命との整合 / 型安全性

- 判断: 反映済み (変更なし)
