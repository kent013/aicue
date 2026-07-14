# 概念設計: profile-update-recent-auth

## 背景・課題

bug-hunt 回帰 run の finding **F-4-01 (High / authz_bypass、人間トリアージで Critical 格上げ検討)**。
前回修正 **T023 (2FA recent-auth)** の取りこぼし。

`user-profile-information.update` (氏名・メールアドレス変更 / `PUT /user/profile-information`) が
recent-auth (step-up 再認証) で保護されていない。

- `app/Providers/FortifyServiceProvider.php` の `RECENT_AUTH_ROUTE_NAMES` は
  two-factor 系 3 ルートのみ。`user-profile-information.update`
  (`vendor/laravel/fortify/routes/routes.php:105-107`) には recent-auth も
  current_password 確認も無い (対照的に `user-password.update` は `current_password` を要求)。
- fresh credential login は `StampRecentAuthOnLogin` listener が `recent_auth_at` を stamp するが、
  **remember-token 自動再ログイン (`viaRemember()===true`) は fail-closed で stamp しない**。
  つまり stale セッション (remember-me 復元) では `recent_auth_at` が未 stamp。
- 結果: セッション/remember-token を窃取した攻撃者が、**パスワード不知のまま**登録メールアドレスを
  差し替え、その後「パスワードを忘れた」で新アドレスにリセットメールを受信して完全なアカウント乗っ取りが可能。

### 現状で既に手当て済みの部分 (重要)

修正方針 (2)「旧アドレスへの通知」は **既に実装済み**である
(`app/Actions/Fortify/UpdateUserProfileInformation.php`)。
メール変更成功時に旧アドレスへ `EmailChangedSecurityNotification` を on-demand 送信し、
`email_verified_at` を null 化して新アドレスの再検証を要求する。よって本設計の主眼は
**未対応の (1) recent-auth 配線**であり、(2) は既存挙動を回帰テストで固定するに留める
(新アドレス非開示の設計方針は維持。ワンクリック変更取り消しリンクは別スコープ)。

## 改善アイデア

`user-profile-information.update` を recent-auth allowlist に組み込み、
**メールアドレス変更を伴う場合に step-up 再認証を要求**する。

氏名のみの変更 (メールアドレス不変) は乗っ取りベクタではなく、日常的で無害な操作のため、
**条件付き (email 変更時のみ) recent-auth** を採用する。無条件化すると stale セッションでの
氏名変更まで毎回 step-up を要求し、UX を不必要に劣化させるため。

具体的には、既存の generic recent-auth 機構 (`RequireRecentAuth` middleware /
`RecentAuthWindow` / `ConfirmRecentAuthController` / client `recent-auth.ts`) を
**そのまま再利用**し、送信 email が現在の email と異なる場合のみ `RequireRecentAuth` に委譲する
薄い条件付き middleware `RequireRecentAuthOnEmailChange` (alias `recent-auth.on-email-change`) を
新設して `user-profile-information.update` に後付け配線する。

## 期待効果

- **使命への貢献**: セキュリティ不変条件 (認証要素変更前の step-up) を profile 更新経路に拡張し、
  アカウント乗っ取り (メール差し替え→パスワードリセット) の主要ベクタを塞ぐ。使命の前提である
  「現場作業者が安心して使えるアプリ」の信頼基盤を守る。
- **具体的改善**:
  - stale セッション (remember-me 復元) からのメールアドレス変更が step-up 無しでは 409/302 で遮断される。
  - 氏名のみ変更の既存 UX (fresh でなくても即保存) は温存され、後退しない。
  - 既存の旧アドレス通知 + 新アドレス再検証は回帰テストで固定される。

## 実装方針（概要）

1. **条件付き middleware 新設**: `app/Http/Middleware/RequireRecentAuthOnEmailChange.php`。
   `$request->input('email')` が `$request->user()->email` (CipherSweet で透過復号) と一致する場合は
   `$next($request)` で素通し、異なる場合は `app(RequireRecentAuth::class)->handle($request, $next)` に
   委譲する (409/302・intended 保持・dropped_mutation flag 等の既存ロジックを完全再利用)。
   - fail-closed 比較: email が「同一」と判定できる時のみゲートを外す。判定不能・型不正・欠落時は
     ゲートを掛ける方向へ倒す。
   - 送信 email と action 側 (`UpdateUserProfileInformation`) の比較は同一の
     `$request->input('email')` を source とし、判定ドリフト (middleware=同一 / action=変更 の
     bypass) を作らない。
2. **alias 登録**: `bootstrap/app.php` の `$middleware->alias([...])` に
   `'recent-auth.on-email-change' => RequireRecentAuthOnEmailChange::class` を追加。
3. **route 後付け配線**: `FortifyServiceProvider` の booted callback を拡張し、
   `user-profile-information.update` に `recent-auth.on-email-change` を idempotent に append する
   (既存 `attachRecentAuthToSensitiveRoutes` と同じ後付けパターン。Fortify がルートを boot 内で
   登録するため booted で名前解決)。
4. **Architecture テスト更新**: `tests/Architecture/RecentAuthRouteTest.php` の
   `recentAuthRequiredRouteNames()` に `user-profile-information.update` を追加
   (`routeHasRecentAuth` は `str_starts_with($m, 'recent-auth')` で条件付き alias も検出する)。
5. **client 側 UX (Settings/Index.svelte)**: profile 送信を、email が変更されている時のみ
   `guardWithRecentAuth` (既存 `withRecentAuth` precheck) でラップする。氏名のみ変更は従来通り即 put。
   サーバ側 middleware が最終ゲート、client precheck は UX 補助 (account 削除と同じ二層構造)。
6. **回帰テスト**: 既存の旧アドレス通知・新アドレス再検証を Feature テストで固定 (未固定なら新設)。

## email 同一性判定契約（middleware ⇄ action のドリフト排除）

条件付き gate の判定は、action `UpdateUserProfileInformation` の early-return 条件
(`$email === $user->email`) と**完全に同一の raw 文字列比較**を用いる。独自の trim/lowercase
正規化は導入しない (正規化を挟むと middleware=同一 / action=変更 の bypass を生む)。

- **抽出**: submitted email は `is_string($request->input('email')) ? {その文字列} : null` で
  `?string` として取得 (PHPStan L10: mixed を持ち込まない)。
- **gate 条件 (以下を全て満たす時のみ RequireRecentAuth へ委譲)**:
  1. submitted email が `is_string` (= 非欠落・非配列)。
  2. submitted email `!==` `$request->user()->email` (認証済みユーザーの透過復号値)。
- **欠落 / 非 string**: gate しない。action へ流し Validator の `required` / `email` 422 に委ねる。
  非 string の email は email 変更を起こせない (validation が弾く) ため、gate しなくても
  **fail-safe は維持される** (bypass 不可)。UX 上も「再認証」より「入力エラー」を先に見せられる。
- **case-only / whitespace 差**: raw `!==` で「変更」と判定 → gate する。これは action が同差分を
  「email 変更」として扱い旧アドレス通知を送る挙動と**一貫**する。
- 判定は bool を返す薄い private メソッドに閉じ込め、middleware 本体は薄く保つ。

## テストマトリクス（必須・設計へ昇格）

Architecture テスト (付与有無) だけでは条件付き分岐の破壊を検出できないため、以下の Feature
テストを**必須スコープ**として設計に固定する:

遮断ケースは request 種別ごとに期待値を分離する (誤分岐でも通る一括り期待を避ける):

| # | 前提セッション | 送信内容 / 種別 | 期待 |
|---|--------------|---------|------|
| 1a | stale (`recent_auth_at` 無し) | email 変更あり / **Inertia mutation** (X-Inertia + PUT) | 409 + `RecentAuthRequiredResource` (redirect=recent-auth.confirm)。email 未変更 |
| 1b | stale | email 変更あり / **通常リクエスト** (非 Inertia) | 302 → `recent-auth.confirm` + `url.intended` 保持。email 未変更 |
| 2 | stale | 氏名のみ変更 (email 不変) | 成功。gate されない。名前が保存される |
| 3 | fresh (`recent_auth_at` 新鮮) | email 変更あり | 成功。email 更新 + 旧アドレス `EmailChangedSecurityNotification` + `email_verified_at` null (通知・null 化の回帰もここで固定) |
| 4 | remember-me 復元直後 (`viaRemember()===true`, 未 stamp) | email 変更あり | stale 扱いで遮断 (1a/1b と同じ) |
| 5 | **stale** | email 欠落 / 非 string | **gate されず** Validator 422 (recent-auth 応答ではなく入力エラー = no-gate 分岐の証明) |
| 6 | stale → 再認証 (client) | email 変更あり | client テスト: stale 検出 → RecentAuthModal 再認証 → 更新再開で編集済み name/email が失われず再送される |

## 制約・前提

- 既存 recent-auth 機構 (middleware / window / satisfier / client helper) を再利用し、新機軸を作らない
  (AGENTS 思考原則「フレームワークのレンジ内」「今必要なものだけ作る」)。
- email は CipherSweet 暗号化カラム。middleware での現在 email 参照は認証済み `$request->user()->email`
  の透過復号で足りる (blind index 検索は不要)。
- `response()->json()` 直書き禁止 → 委譲先 `RequireRecentAuth` は既に
  `RecentAuthRequiredResource` (JsonResource) を使用。新 middleware は独自 JSON を返さない。
- Inertia PUT (`useForm.put`) は非 GET mutation のため、stale 時は既存ロジックで 409 + redirect JSON
  を返す (Inertia GET の 302 replay とは別扱い。RequireRecentAuth の既存分岐に従う)。
- 認証要素変更後の `RecentAuthState::clear()` (鮮度失効) は今回のゲート追加 (変更「前」の step-up)
  とは別関心のため本スコープ外 (現状も UpdateUserPassword は clear していない。将来別途)。

## スコープ外

- メールアドレス変更のワンクリック取り消し (undo) リンク付き通知 (brief の「可能なら」)。
- 氏名変更への無条件 step-up。
- 新アドレスへの確認リンク方式 (double opt-in) への切り替え。
- `RecentAuthState::clear()` による変更後の鮮度失効。
- 他の Fortify 経路 (`user-password.update` は current_password で既に保護済) の見直し。
