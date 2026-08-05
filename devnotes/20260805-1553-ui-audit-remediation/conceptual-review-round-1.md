全体判定: **CHANGES_REQUESTED**

設計の方向性は North Star とよく整合しています。特に「passkey-only / SSO-only ユーザーが機微操作で詰まない」ことを、文言修正ではなく契約・型・gate に落とす判断は妥当です。ただし、初回パスワード設定 route の安全契約と、`RecentAuthStatus` の型・DTO 境界に未確定の穴があり、このまま実装承認するには早いです。

## 1. 使命との整合性

[Suggestion]  
passkey-only ユーザーを mainstream と捉え、機微操作の再認証 UI を踏破可能にする改善は、AI-CUE の「思考ゼロ」に本質的に貢献します。現場スマホで「対応ブラウザなのにパスキーが出ない」「ログアウトしないと回復できない」は、撮影 PWA の信頼を直接落とすため、優先度設定も妥当です。

## 2. 禁止事項違反

[Warning]  
`/recent-auth/status` の応答を「そのまま」Svelte prop に渡す方針は、DTO / JsonResource 境界が曖昧になるリスクがあります。禁止事項 4 の趣旨から、Laravel 側は `response()->json()` 直書きではなく、既存 DTO / Resource による固定 contract として返すことを明記してください。

修正提案:  
`RecentAuthStatusDto` などのサーバ DTO を正本にし、Svelte 側には `RecentAuthStatus` 型を明示定義する。最低限、`passkeyAvailable`, `canSatisfy`, `passwordSet`, `availableProviders` を非 optional に固定する。

[Warning]  
`LoginMethodRequiredDto.settingsUrl` 削除は、「resources/js で未使用」だけでは削除根拠として弱いです。DTO はバックエンドテスト、外部的な JSON contract、将来の API 消費者に影響する可能性があります。

修正提案:  
削除前提なら「Inertia 内部専用 DTO で外部公開 contract ではない」ことを設計に追記し、Feature テストまたは snapshot 的テストの更新対象を明記してください。外部 contract の可能性があるなら削除ではなく、正しい URL に修正して段階的に廃止してください。

## 3. 実現可能性

[Warning]  
初回パスワード設定の後処理を既存 `UpdateUserPassword` と共有する方針は良いですが、既存実装が `current_password` 前提ならそのまま共有できない可能性があります。特に他デバイスのセッション失効、remember token、監査記録、password hash 更新の責務分離が曖昧です。

修正提案:  
`PasswordCredentialService` の責務を明確に分けてください。例:  
`setInitialPassword(User $user, NewPassword $password)` と `changePassword(User $user, CurrentPassword $current, NewPassword $password)` を公開し、内部の「hash 保存・監査記録・他セッション失効」だけを共有する。

[Warning]  
`recent-auth` middleware 保護は妥当ですが、`hasPassword()` 判定を `lockForUpdate()` 下で行うだけでは、route の transaction 境界が未定義です。

修正提案:  
Controller は薄くし、Service 内 transaction で `User::query()->whereKey(...)->lockForUpdate()->firstOrFail()` から password 保存まで完結させる、と明記してください。

## 4. 期待効果の妥当性

[Suggestion]  
「step-up 成功経路が 1 画面 → 6 画面」は、F-1 の根因が prop 未配線であるなら合理的です。ただし、6 画面すべてが同じ `RecentAuthStatus` を取得できる前提に依存します。

修正提案:  
各ページが status をどこから受け取るかを固定してください。ページ prop として渡すのか、モーダル open 時に `/recent-auth/status` を fetch するのかで、gate とテスト対象が変わります。

## 5. リスク

[Critical]  
初回パスワード設定は、攻撃者が一度セッションを奪った場合に永続的なログイン手段を追加できる操作です。設計はこの脅威を認識していますが、`recent-auth` が「どの手段で、いつ、誰が満たしたか」を password setup route 側で十分に検証する条件が明記されていません。

修正提案:  
`settings.password.store` の受入条件を明文化してください。少なくとも以下が必要です。

- `recent-auth` 成立済みであること
- 成立時刻が TTL 内であること
- 対象 user が現在 session user と一致すること
- `hasPassword() === false` を lock 下で再確認すること
- 成功後に security event を記録すること
- 他セッション失効または同等の防御方針を明記すること

[Warning]  
`RecentAuthRecoveryNotice` が logout を持つ設計は妥当ですが、logout call-site inventory の更新だけでは Inertia history clear 条件との整合が保証されません。

修正提案:  
`router.post('/logout')` が既存の `LogoutResponse` と `Inertia::clearHistory()` 契約を通ることを、既存 `InertiaHistoryGuardTest` または JS inventory の対象に含める、と明記してください。

## 6. スコープの適切さ

[Warning]  
F-3/F-4/F-7 を同時に閉じる判断は理解できますが、主目的である「再認証 UI の踏破可能性」から少し広がっています。特に F-7 の登録 ceremony 多重防止や focus 移動は、別の UX 改善として独立可能です。

修正提案:  
本批の必須スコープを F-1/F-2/F-3 の再認証・回復導線に絞り、F-4/F-7 は「同一ファイルを触るため実装してよいが、失敗時に切り離せる項目」として扱ってください。

## 7. 型安全性

[Critical]  
「必須 prop 化するが `.svelte` テンプレートは型検査されないため gate で強制する」という認識は正しい一方、inventory gate の検査内容が文字列ベースに留まると、`status={undefined}` や別 shape の object を渡す実装を見逃します。

修正提案:  
inventory gate に加えて、呼び出し元で渡す値の由来を固定してください。例: `recentAuthStatus` という page prop 名のみ許可する、または `useRecentAuthStatus()` の戻り値のみ許可する。あわせて TS 側の `RecentAuthStatus` を非 optional field の object として export し、コンポーネント script 内では `let { status }: { status: RecentAuthStatus } = $props();` を必須にしてください。

[Warning]  
`availableProviders` の型が文字列配列のままだと、SSO provider の `step-up capable` / `identity_only` の分岐が壊れやすいです。

修正提案:  
`availableProviders` は `Array<RecentAuthProviderDto>` のようにし、`kind`, `label`, `canStepUp` などを明示した discriminated union または DTO にしてください。PHPStan level 10 の観点でも、array shape を明示する必要があります。