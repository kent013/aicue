仮説は「Round 1 の偽グリーン経路が実質的に閉じられ、route cache の例外が明示的な運用契約として受容可能か」です。

`tests/Feature/Security/ThrottleExemptionPremiseTest.php`

- [Warning] `default-livewire.update` の前提検査は改善されていますが、`str_contains($source, '$this->rateLimit(')` は「ファイル内のどこかに文字列がある」ことしか保証しません。コメント化、未使用メソッドへの移動、対象 action からの呼び出し削除でも合格します。deny-by-default の根拠としては、Login/EditProfile の対象操作から `rateLimit()` が呼ばれる構造、または制限の実挙動まで固定する必要があります。

- [Warning] auth route 集合には `filament.admin.auth.multi-factor-authentication.set-up-required` が含まれますが、前提検査対象は Login/EditProfile の2ファイルだけです。この route が Livewire POST 上で credential 操作を提供するなら、対応 component の制限も明示的に確認してください。提供しないなら、なぜ exemption 根拠の対象外かを理由に固定する必要があります。

- [Suggestion] logout テストの `assertRedirect()` だけでは「本体へ到達していない」証明になりません。route の実効列に `Authenticate` が存在することも検査すると、根拠とテストが一致します。

`app/Support/Http/RouteThrottleBinder.php`

- [Warning] クラス docblock が現在の実装と矛盾しています。「3段優先順の第2段」「cached 起動でも同じ limiter なら冪等 no-op」とありますが、現在は第3段であり、cached 起動では全面 skip です。セキュリティ機構の契約説明なので更新が必要です。

- [Suggestion] `attachByName()` の `return // route:cache 由来の再適用` も、cached 起動では呼ばれません。同一 bootstrap 内の重複呼び出し等を表すコメントに直すと正確です。

route cache の判断自体は妥当です。Laravel の callback 順序上、当該 callback から compiled collection を検査できないなら、毎デプロイの `route:cache` 再生成を前提にするのは現実的です。ただしこれはコード内で完結する fail-fast から、デプロイ手順を含む保証へ変わっています。実際のデプロイ経路が必ず再生成することを別途機械強制しているなら受容できます。今回提示された範囲では文書化のみなので、残リスクとしての表現は適切ですが、保証そのものは運用依存です。

`tests/Architecture/ThrottleCoverageInventoryTest.php`

- [Suggestion] protected-resource の理由訂正は実態に合っており、Round 1 の指摘は解消しています。

`tests/Feature/Security/AuthThrottleCoverageTest.php`

- 指摘ありません。3 route の dataset 化により、共通 helperだけでなく配線も検証できています。

`docs/app-integration-guide.md` / `AGENTS.md` / `app/Providers/AppServiceProvider.php`

- 指摘ありません。優先順と stale cache の運用条件は明確になっています。

全体として主要な改善は入っていますが、`default-livewire.update` の exemption 根拠が依然として文字列存在検査に留まり、偽グリーン経路が残っています。

**CHANGES_REQUESTED**