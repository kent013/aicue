# 対応マトリクス: conceptual-review Round 1

全体判定は **APPROVED**（Critical なし）。Warning 2 件を設計文へ明文化して解消する。

## [Warning] 3. 実現可能性 — disable の resume 契約が暗黙
- 判断: 対応する
- 根拠: `Settings/Security.svelte` には既に `guardWithRecentAuth(action)` / `pendingAction` / `resumePendingAction()` の resume 機構があり、regenerate 系が同契約で稼働中。disable も同じ closure-as-action 契約に載せるだけで安全に再開できる。指摘どおり設計文が暗黙だったので明文化する。
- 対応内容: 概念設計「実装方針」に、disable も regenerate と同一の `guardWithRecentAuth` → stale で modal → `resumePendingAction()` で `router.delete` closure を再実行する契約であることを明記。resume は元操作 closure の再呼び出しであり「再送信されるのは冪等な DELETE のみ」である点も追記。最終ゲートは server middleware（stale なら 409）である旨も残す。詳細設計側でフロントの分岐（onFresh/onStale）を施策として明示する。

## [Warning] 5. リスク — 2FA 必須組織での self-disable 後の扱いが未記載
- 判断: 対応する（現行仕様を追記）
- 根拠: 現行コードに `BlockTwoFactorDisableForEnforcedOrganizations`（`bootstrap/app.php` の web group `append`、global）が既にあり、`two-factor.disable` の準拠ユーザー self-disable を **422 で拒否**する。web group middleware は route 付与の `recent-auth`（route-level）より **前**に走るため、enforced org のユーザーは recent-auth に到達する前に 422 で弾かれる。つまり本変更は enforced org には影響せず、非 enforced org（self-disable が許可される）ユーザーにのみ step-up を課す純粋な追加。self-disable 後の遷移は既存 `TwoFactorDisabledResponse`（web は `back()->with('success')`、XHR は 200）のまま。
- 対応内容: 概念設計「制約・前提」に、enforced org の self-disable は既存 422 middleware が recent-auth より先に遮断する旨と、本変更が非 enforced org のみに作用する追加である旨を明記。

## [Suggestion] 6 / 効果表現 / 409 shape テスト
- 判断: 一部取り込み（任意）
- 対応内容: qr-code/secret-key の「別チケットで再評価」追跡を「スコープ外」に明記済み方針を維持。Feature テストで 409 の `code`/`redirect` shape まで固定する方針を詳細設計のテスト計画に反映する。効果表現は「単独セッション侵害を防ぐ」に寄せた文言へ微修正。
