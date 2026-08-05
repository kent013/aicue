仮説: passkey 導入で壊れやすいのは、`recent-auth` の成立手段としての一貫性、WebAuthn payload の vendor 契約、そして guard のテスト空振りです。成功条件は「passkey-only ユーザーが stale recent-auth から回復できる」「登録/confirm/login の payload が実契約で検証されている」「新しい監査・UI 経路に対応テストがある」ことです。

**指摘**

| ファイル | 判定 | 指摘 |
|---|---:|---|
| `resources/js/components/organisms/RecentAuthModal.svelte` / `resources/js/pages/Settings/Security.svelte` / recent-auth status 周辺 | [Critical] | passkey が `RecentAuthState::confirm()` の satisfier になった一方、`/recent-auth/status` の契約は password / SSO のままです。`passkeyAvailable` は Security page からだけ手渡しされており、他の recent-auth 利用画面では passkey-only ユーザーが stale 状態から再認証できません。`canSatisfy=false` との組み合わせも未検証です。generic recent-auth の成立手段として追加するなら backend status に passkey 可否を含め、全モーダル利用箇所で同じ契約にすべきです。 |
| `resources/js/components/features/auth/PasskeySection.svelte` | [Critical] | 登録成功経路のテストがありません。特に `router.post("/user/passkeys", { name, credential: outcome.value })` は詳細設計の `{ name, ...credential }` と食い違っています。vendor が期待する request shape とズレると登録が全面的に失敗しますが、現テストは名前未入力・削除系だけでこの経路を検出できません。 |
| `resources/js/lib/passkeys.ts` | [Warning] | `createPasskeyCredential()` は送信可能 JSON を返すだけで、登録 POST の shape 検証が呼び出し側任せです。`PasskeySection` 側の positive test で `router.post` payload を固定してください。 |
| `app/Actions/Fortify/UpdateUserPassword.php` | [Critical] | `SecurityEventType::PasswordChanged` の production 記録経路を追加していますが、対応テストが見当たりません。監査イベントは「実装を足したが検証なし」に該当します。 |
| `app/Listeners/Auth/StampRecentAuthOnPasskeyVerified.php` | [Warning] | 本人性バインドの方向は妥当です。ただし passkey login deny 経路は event 直 dispatch で近似しており、vendor controller 実経路では固定されていません。WebAuthn ceremony を完全自動化しない方針でも、少なくとも `Passkeys::allowsLogin()` deny 時に session が汚れないことを統合境界で押さえたいです。 |
| `app/Listeners/Auth/ClearRecentAuthOnPasskeyChange.php` | [Warning] | 削除は HTTP 経路で検証されていますが、登録は event 直 dispatch のみです。登録 UI がまだ positive test されていないため、「登録成功時に recent-auth が失効する」実経路の保証が弱いです。 |
| `app/Http/Middleware/EnsureLoginMethodRemains.php` | APPROVED | 投影後評価、`lockForUpdate()`、Inertia と JSON の応答分岐は設計意図に沿っています。transaction 内 `$next()` のリスクも docblock と SQL テストで扱えています。 |
| `app/Http/Routing/SelfScopedPasskeyBinder.php` | APPROVED | 他人・不在・非数値・範囲外を 404 に倒す方針はセキュリティ不変条件に合っています。 |
| `app/Services/Auth/LoginMethodInventory.php` / `app/Services/Auth/PasskeyLoginPolicy.php` | APPROVED | 「使えるログイン手段」を数える設計、feature flag / TOTP との連動は妥当です。 |
| `app/Providers/PasskeyServiceProvider.php` | APPROVED | binder 後勝ち、response contract 差し替え、middleware 後付けの方向は設計と一致しています。 |
| `app/Http/Controllers/Settings/SecurityController.php` | APPROVED | route closure 抽出と passkey 一覧 prop は妥当です。credential 本体を露出していない点もよいです。 |
| `resources/js/pages/Auth/Login.svelte` | APPROVED | 失敗時に password / SSO 導線を残す点は設計どおりです。 |
| `docs/*` | [Warning] | 設計で必須になっている `docs/architecture.md`、`docs/factories.md`、`docs/supported-browsers.md`、`docs/template-divergence.md` の更新が diff にありません。特にコードコメントが `docs/template-divergence.md D13` を参照しているのに追加が無いのは運用上危険です。 |
| DTO / Resource / migration / factory 群 | APPROVED | 禁止事項の `response()->json()` 直書き回避、Factory 追加、credential 非露出は概ね問題ありません。 |
| Architecture / Feature / JS tests | [Warning] | guard・route・binder のテストは厚い一方、passkey 登録 positive path、passkey-only stale recent-auth、`PasswordChanged` 記録が抜けています。空振り防止は多く入っていますが、最重要ユーザー経路の穴が残っています。 |

**全体判定: CHANGES_REQUESTED**

主な修正は 3 点です。`recent-auth/status` に passkey satisfier を正式に含めること、passkey 登録の実 payload を vendor 契約に合わせて positive test で固定すること、`PasswordChanged` 監査記録のテストを追加すること。これが入れば、残りは Warning / Suggestion レベルです。