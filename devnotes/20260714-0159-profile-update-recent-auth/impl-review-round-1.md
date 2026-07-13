以下、提示差分のみを根拠に T031 を設計照合レビューします（コマンド実行なし）。

**app/Http/Middleware/RequireRecentAuthOnEmailChange.php**
- 判定: **概ね適合**
- [Suggestion] `changesEmail()` の契約は設計 S1 と一致しています（`is_string($submitted)` かつ raw `!==`）。`UpdateUserProfileInformation` の early-return 同等性を維持できています。
- [Suggestion] 非 string / 欠落で `false` に倒す fail-safe も妥当です。少なくとも「recent-auth を誤通過させて email が変わる」経路にはなっていません（後段でしか変更不能）。
- [Warning] `! $user instanceof User` で gate 対象外にする分岐は安全側ですが、契約の主眼は email 比較同一性なので、将来 `auth` 前提が崩れた時の挙動を Architecture 側で明示固定しておくとより堅いです（現状はコメント依存）。
- [Suggestion] 応答生成を `RequireRecentAuth` に完全委譲しており、独自 `response()->json()` が無い点は要件 4 に適合。

**app/Providers/FortifyServiceProvider.php**
- 判定: **適合**
- [Suggestion] S3 の「Fortify 登録ルートへ booted 後付け配線」を `CONDITIONAL_RECENT_AUTH_ROUTES` + `appendMiddlewareIfMissing()` で実現できています。
- [Suggestion] idempotent 付与（重複防止）も実装済みで、長寿命プロセス考慮として良いです。
- [Suggestion] static helper 化・`RouteCollectionInterface` 導入は型/責務ともに自然で、PHPStan L10 的にも無理がありません。

**bootstrap/app.php**
- 判定: **適合**
- [Suggestion] S2 の alias 登録 `recent-auth.on-email-change` は明確。既存 `recent-auth` と分離されており意図が読みやすいです。

**resources/js/pages/Settings/Index.svelte**
- 判定: **適合（Atomic Design 観点含む）**
- [Suggestion] `submitProfile` のみ変更、既存 `guardWithRecentAuth` 再利用で S5 に一致。新規 component 追加なしも要件通りです。
- [Suggestion] `baselineEmail` と `submittedEmail` スナップショットで「再認証後の二重送信/判定ドリフト」を抑える実装は妥当です。
- [Warning] `baselineEmail` は `initialUser` 依存のため、外部要因でユーザー情報が再注入されるケースで同期ズレ余地は理論上あります。ただし本差分範囲では `onSuccess` 同期があり実害は低いです。

**tests/Architecture/RecentAuthRouteTest.php**
- 判定: **適合**
- [Suggestion] S4 の allowlist 追加として十分です。`recent-auth.on-email-change` を検出できる前提コメントもあり、意図が追えます。

**tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php**
- 判定: **適合（S6 中核）**
- [Suggestion] 1a/1b/2/3/5 を網羅し、条件付き gate の主要分岐を固定できています。
- [Suggestion] case 3 で「旧アドレス通知 + `email_verified_at` null 化」の回帰を押さえており、既存 `EmailChangeTest` 非改変方針とも整合。
- [Warning] case 5 は Fortify 既知 500 を踏まえ「409/confirm でないこと」を不変にした設計は合理的ですが、プロダクト品質観点では Fortify upstream/ラッパーで 422 正規化を別タスク化しておくと事故解析性が上がります（本件スコープ外）。

**tests/Feature/Auth/RecentAuthTest.php**
- 判定: **適合（S7 case4）**
- [Suggestion] `viaRemember=true` で stamp しない、対照で stamp する両側テストは bypass 防止の根拠として強いです。
- [Suggestion] stale セッション起点の脅威モデル（remember-me 復元）に直接効く固定化になっています。

**tests/js/pages/SettingsIndex.test.ts**
- 判定: **適合（S7 case6）**
- [Suggestion] 「stale 時は precheck 段階で `put` しない」「再認証後 1 回だけ `put`」を明示検証しており、二重送信回帰の捕捉として良いです。
- [Suggestion] 「氏名のみ変更は precheck を走らせない」テストもあり、S5 の条件分岐をクライアント側で固定できています。

**全体所見**
- [Critical] 該当なし。
- [Warning] Fortify 既存の `email=array` で 500 は本件非起因だが、将来のセキュリティ運用上は別チケットでの入力正規化/防御を推奨。
- [Suggestion] middleware の「`User` でない場合は gate 対象外」契約を Architecture/Feature のどちらかで明文化テストすると、将来変更にさらに強くなります。

**総合判定: APPROVED**