全体判定: **CHANGES_REQUESTED**

提供された設計本文のみでレビューしました。コマンド実行・ファイル書き込み・追加のリポジトリ確認は行っていません。

**施策 1: REQUEST_CHANGES**

[Warning] `force=true` の回帰テストは「確立済み 2FA」の前提を明示固定してください。  
`User::factory()->withTwoFactor()` が常に `two_factor_confirmed_at` を持つ前提に依存すると、Factory 変更でテストの意味が薄れます。

修正案:
- stale / fresh の `force=true` テストで `two_factor_confirmed_at` が non-null であることを事前 assert
- stale では `two_factor_secret` / `two_factor_recovery_codes` に加えて `two_factor_confirmed_at` も不変確認
- fresh では seed 差し替え後も confirmed_at が意図通り触られないことを確認

[Suggestion] `AuthThrottleCoverageTest` は「落ちたら fresh session を足す」ではなく、設計上の既知波及として最初から変更対象に含めてもよいです。recent-auth 追加で 409 が先に出る可能性は十分高く、検査意図を守るなら `recent_auth_at` 付与は自然です。

**施策 2: REQUEST_CHANGES**

[Warning] 「recent-auth をちょうど 1 本持つ」とドキュメント化する一方で、提案された gate は `RecentAuthMiddleware::isAttached()` の boolean 判定だけです。重複付与を検出できないため、契約と機械検査が一致していません。

修正案:
- `RecentAuthMiddleware` に `countAttached(RoutingRoute $route): int` を追加
- `TwoFactorStepUpInventoryTest` では recent-auth 対象 route が `1` 本であることを検査
- 既存 `RecentAuthRouteTest` は boolean のままでもよいが、AGENTS.md に「ちょうど 1 本」と書くなら新 gate 側で必ず数える

[Warning] 「秘密を開示する route は exemption にできない」固定リストが狭いです。T124 の中心リスクは `two-factor.enable force=true` による seed 差し替え・ロックアウトでもあるため、`qr-code / secret-key / recovery-codes` だけを名指し固定すると設計意図が検査に十分反映されません。

修正案:
- 関数名を `twoFactorNonExemptibleRoutes()` などに変える
- 少なくとも `two-factor.enable` を追加
- 可能なら `two-factor.disable` / `two-factor.regenerate-recovery-codes` も「second factor の除去・bypass 更新」として non-exemptible に含める

[Warning] Step A / C の実測コマンドが `vendor/bin/pest ...` 直叩きになっています。AGENTS.md のグローバルテストロック運用と衝突する恐れがあります。

修正案:
- リポジトリのロック付き composer script 経由に統一する  
  例: `composer test -- tests/Architecture/TwoFactorStepUpInventoryTest.php`
- もし composer script が個別ファイル引数を受けないなら、ロック付きの既存 wrapper を明記する

**施策 3: APPROVE**

設計意図は妥当です。2FA 必須ゲート下で passkey-only ユーザーが recent-auth satisfier に到達できない問題を潰しており、`passkey.registration-options` など管理系 route を allowlist に入れない線引きも正しいです。

[Suggestion] 新規 Feature テストでは「settings.security へ redirect されない」だけでなく、`passkey.confirm-options` が期待する challenge 応答の最低限の shape まで見られるなら、allowlist は通ったが実用上は壊れている、という空振りを減らせます。

**施策 4: REQUEST_CHANGES**

[Critical] `loadEnrollmentAssets()` の 409 分岐で `guardWithRecentAuth(() => void loadEnrollmentAssets())` を呼ぶ設計は、`/recent-auth/status` が失敗した場合に自動再実行ループになります。`withRecentAuth()` は delegated 時に既定で `onFresh` を実行するため、409 → status 失敗 → 再取得 → 409 をユーザー操作なしで繰り返せます。リスク欄の「成立後に 1 回だけ再取得」という説明は、この delegated 分岐を見落としています。

修正案:
- 409 後の呼び出しは `guardWithRecentAuth` ではなく `withRecentAuth` を直接使い、`onDelegated` を必ず指定する
- `onDelegated` では即時 `loadEnrollmentAssets()` を再実行しない
- 例: 取得失敗 Alert に倒す、または検証済みの recent-auth confirm へ明示遷移する
- JS テストに「素材 endpoint は 409、`/recent-auth/status` は 500/network error のとき、再取得ループせず POST/fetch を再発火しない」を追加する

[Warning] `fetchEnrollmentField()` が recent-auth 409 の `redirect` を捨てています。boolean だけで足りる設計でも成立しますが、delegated fallback を安全に作るには redirect を検証済み path として保持した方が実装が安定します。

修正案:
- `EnrollmentField` に `recentAuthRedirect?: string` を持たせる、または recent-auth 409 payload parser を lib 側に寄せる
- redirect を使う場合は `recentAuthRedirectTarget` と同等の same-origin / known path 検証を通す

[Suggestion] `isRecentAuthRequiredPayload` の export 追加に伴い、`Settings/Security.svelte` 側の import 変更も変更箇所に明記してください。実装漏れしやすい小さい差分です。

**施策 5: REQUEST_CHANGES**

[Warning] AGENTS.md 追記案の「recent-auth をちょうど 1 本持つ」は、施策 2 の proposed test では担保されていません。運用契約を強く書くなら、Architecture gate も同じ強さにしてください。

修正案:
- 施策 2 で count-based gate を入れる
- それをしないなら AGENTS.md は「recent-auth を持つ」に弱める。ただし security invariant としては count-based gate の方が望ましいです

[Warning] 実装モードの `standalone` は、AGENTS.md の「実装は必ず worktree」に対して曖昧です。

修正案:
- 「専用 worktree を `scripts/setup-worktree.sh <task-id>` で作る standalone 実装」と明記する
- main 直実装ではないことを Definition of Done に含める

**まとめ**

設計の方向性自体は妥当です。Fortify の secret GET と `force=true` enable を recent-auth 配下に入れる判断、passkey satisfier の allowlist 追加、deny-by-default gate の追加はいずれもアプリのセキュリティ不変条件に沿っています。

ただし、施策 4 の delegated 時ループは実装すると実害が出る可能性があり、施策 2/5 は「ちょうど 1 本」契約と検査がズレています。この 2 点を直せば、再レビューでは APPROVED にかなり近い設計です。