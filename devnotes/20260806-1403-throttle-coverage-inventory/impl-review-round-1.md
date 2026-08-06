レビュー仮説: この施策の成功条件は「保護対象 route が無音で throttle なしにならないこと」と「exemption の前提崩れが偽グリーンにならないこと」です。この観点では、現差分はまだ修正要求です。

**app/Support/Http/RouteThrottleBinder.php**
- [Warning] `routesAreCached()` 時に全面 skip する実装は、詳細設計の「cached 起動でも冪等 no-op で fail-fast」とは性質が変わっています。`route:cache` 生成時に焼き込まれる前提は理解できますが、古い/誤生成の route cache が残った場合に起動時検査が一切走らず、無防備な cached route をそのまま起動できます。少なくとも「cached route に期待 throttle が焼き込まれていること」を検証するテストまたは起動時検査が欲しいです。
- [Warning] `routeThrottleEntries()` が controller middleware を見ないため、vendor 更新で controller 側 throttle が追加された場合、boot 時 fail-fast ではなく CI の inventory 検査頼みになります。boot 中 controller 解決を避ける判断は妥当ですが、この弱点は設計差分として明記した方がよいです。

**tests/Architecture/ThrottleCoverageInventoryTest.php**
- [Critical] `default-livewire.update` の exemption が「component 内に実際の制限がある」ことに依存しているのに、その前提を固定するテストがありません。Filament 側の `rateLimit()` が削除・移動・対象外化されても inventory は通り続け、広い Livewire POST が throttle なしで残ります。deny-by-default の最悪失敗モードです。対象 component の rate limit 実装をソース/behavioral のどちらかで固定してください。
- [Warning] `SessionTeardownOnly` の `logout` / `filament.admin.auth.logout` も exemption 前提テストがありません。攻撃利得は小さいですが、「exemption は前提を Feature 固定する」という設計原則とは不一致です。

**tests/Feature/Security/ThrottleExemptionPremiseTest.php**
- [Critical] 上記の通り、`default-livewire.update` exemption の behavioral/source 前提が未検証です。このファイルに追加するのが自然です。
- [Suggestion] `.well-known/oauth-*/{path}` は JSON キーのみ比較していますが、inventory 理由は `{path}` が応答内容に影響しないことまで主張しています。主要値も比較すると、理由とテストの対応がより強くなります。

**docs/app-integration-guide.md**
- [Warning] 「貼る仕組みの 3 段優先順」が実装コメントと逆に読めます。vendor route は package 設定で貼れるならそれを優先し、設定で貼れない場合だけ `RouteThrottleBinder` に落とす、という順序に直すべきです。現状の文面だと binder を先に選ぶ運用に見えます。

**app/Providers/AppServiceProvider.php**
- [Suggestion] `attachThrottleToVendorRoutes()` のコメントが `RouteThrottleBinder::attachByName()` ではなく `attachOnBooted()` 実装に変わった後の前提を十分に反映していません。cached 起動 skip の運用条件を近くに書くと保守時に安全です。

**app/Providers/FortifyServiceProvider.php**
- [Suggestion] 実装は設計の `private static function throttledFortifyRoutes()` 方針に一致しています。大きな問題は見当たりません。

**routes/web.php**
- [Suggestion] SES webhook と招待受諾の付与方針は設計に一致しています。実効順テストも入っており、ここは妥当です。

**その他の新規テスト/補助**
- [Warning] `AuthThrottleCoverageTest` の異常入力テストは `password-reset-request` のみで、詳細設計にある `password-reset-* / account-register` 全体の固定には届いていません。共通 helper で実装されているため実害は低いですが、route 配線ミスの検出力は落ちます。

全体判定: **CHANGES_REQUESTED**