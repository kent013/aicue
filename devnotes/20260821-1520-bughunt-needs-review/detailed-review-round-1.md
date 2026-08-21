# 全体判定: CHANGES_REQUESTED

施策 A/B/C は既存実装と既存 Feature テストの確認で十分です。施策 D の分岐変更自体は妥当ですが、未契約非管理者の回帰テスト、continuation テストの状態定義、完了時の検証コマンドとドキュメント更新に不足があります。

## 施策 A: APPROVE

[Suggestion] 既存テストの引用で問題ありません。コード変更がないため、「テストなしの実装完了」には該当しません。

422 の HTTP 応答だけでなく、セッション行が増えないことと Stripe 呼び出しが増えないことまで固定しており、冪等性の確認として十分です。完了報告には対象テストを実際に実行した結果を残してください。

## 施策 B: APPROVE

[Suggestion] action 層と実 route を通る HTTP 層の両方があり、旧アドレス宛通知と再検証要求を固定できています。新規テストは不要です。

完了報告では、単にテストファイルが存在することではなく、対象テストが今回のブランチで成功したことを記録してください。

## 施策 C: APPROVE

[Suggestion] `ssoOnly()` Factory による正常系、パスワード設定、監査イベント、他デバイス失効、再訪時の一貫性まで確認しており、bug-hunt の browser 未検証を埋める根拠として十分です。

コード変更がないため追加テストは不要です。

## 施策 D: REQUEST_CHANGES

分岐の実装案そのものは妥当です。`hasActiveAccess()` を先に評価する順序を維持し、既契約の非管理者だけを dashboard に送るため、課金ゲートを広く緩める変更にはなっていません。DTO、JsonResource、Inertia Props、TypeScript 型への変更も不要です。

[Warning] 未契約の非管理メンバーに対する回帰テストが不足しています。

現在の計画では、未契約 owner の checkout 200 と、支払い未解決の非管理者による billing-required はありますが、通常の「未契約 + 非管理者 → billing-required」が明示されていません。今回追加する「ActiveFreePlan + 非管理者 → dashboard」と最も取り違えやすい境界です。

修正案:

- `未契約 + manageBilling 非保持 → onboarding.billing-required`
- `ActiveFreePlan + manageBilling 非保持 → dashboard`

を別テストで固定してください。これにより「active access を持つ場合だけ dashboard」という変更範囲が明確になります。

[Warning] continuation テストの契約状態が `active/free` と曖昧です。

設計方針で「状態ごとに個別ケース」としている一方、テスト 8 は paid subscription と free plan のどちらを作るのか確定していません。テスト名・fixture・期待する状態を一致させる必要があります。

修正案:

- bug-hunt の再現を優先し、テスト 8 は `ActiveFreePlan（free_plan_code=personal）` に固定する。
- paid `Subscribed` は直接アクセス側の非管理者テストで固定する。
- continuation は「verification → onboarding.checkout → dashboard」という入口の接続確認に限定する。

全状態と全入口の直積までは不要です。直接アクセス側で状態分岐を網羅し、continuation 側で代表的な非管理者経路を一本通せば、重複を抑えつつ責務を分離できます。

[Warning] 既存 owner continuation テストが保証する範囲を広く表現しています。

既存テストが確認しているのは `verification.verify → onboarding.checkout` の第一段リダイレクトと continuation の消去です。最終的な `billing.index` 着地までは固定していません。「owner 経路の最終着地の回帰防止」とは主張できません。

修正案は次のいずれかです。

- 主張を「continuation の第一段と session 消去の回帰防止」に狭め、owner の最終分岐は直接アクセス側テストで担保する。
- 最終着地まで保証したい場合だけ、リダイレクトを追跡して `billing.index` まで確認する。

前者で十分です。

[Warning] dashboard の描画テストが具体化されていません。

`assertInertia(component 'Dashboard/...')` では実装可能なテスト仕様になっていません。実在するコンポーネント名を記載してください。また、302 と 200 を別々に確認すると障害箇所が分かりやすくなります。

修正案:

1. `/onboarding/checkout` が `route('dashboard')` へリダイレクトすることを確認。
2. 同じ認証ユーザーで `route('dashboard')` を GET。
3. 200 と実際の Dashboard コンポーネントを確認。
4. 「業務導線がある」ことまで不変条件にするなら、対応する Inertia prop または UI テストを具体的に追加する。単なる component 確認だけでは業務導線の存在までは保証しない。

[Warning] 完了条件がリポジトリ必須の検証コマンドを満たしていません。

現在の完了条件は対象テスト、PHPStan、`composer fix` に留まっています。AGENTS.md はコミット前に全検証コマンドの green を要求しています。また、formatter の実行と formatter 検査は別です。

修正案として、完了条件へ次を追加してください。

- `composer test`
- `composer phpstan`
- `vendor/bin/pint --test`
- `pnpm lint`
- `pnpm typecheck`
- `pnpm test`
- `pnpm build`
- `pnpm typecheck:packages`
- `pnpm build:packages`
- `pnpm test:packages`

テストファーストの証跡として、実装前に新期待が既存の `billing.index` 着地によって失敗したことも記録してください。

[Warning] `screens.md` の扱いが変更一覧と完了条件で不整合です。

波及変更では更新対象ですが、施策一覧の変更ファイルに含まれず、完了条件も「app-update-docs で追跡」に留まっています。挙動を変更する同じ作業で仕様目録が古くなるため、追跡だけでは完了条件として弱いです。

修正案:

- `.claude/skills/app-bug-hunt/screens.md` を変更ファイル一覧へ追加する。
- 今回の変更と同じ PR で更新する。
- 更新できない明確な理由がある場合のみ、担当・期限・参照可能な TODO を完了条件に含める。

[Suggestion] UI コンポーネントや Props の変更はないため、DESIGN.md と Atomic Design の追加対応は不要です。

修正後の推奨テスト境界は次のとおりです。

| 状態 | manageBilling | 直接アクセスの期待 |
|---|---:|---|
| Subscribed | あり | billing.index |
| Subscribed | なし | dashboard |
| ActiveFreePlan | あり | billing.index |
| ActiveFreePlan | なし | dashboard |
| 未契約 | あり | checkout 200 |
| 未契約 | なし | billing-required |
| 支払い未解決 | あり | billing.index |
| 支払い未解決 | なし | billing-required |

continuation は ActiveFreePlan の非管理者について最終的な dashboard 着地を一本確認すれば十分です。