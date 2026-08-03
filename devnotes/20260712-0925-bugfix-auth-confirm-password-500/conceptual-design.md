# 概念設計: bugfix-auth-confirm-password-500 (F-11)

## 背景・課題

bug-hunt (devnotes/20260712-075854-bug-hunt/shard-0/shard-report.md F-11, severity High) で、
認証済みユーザーが `GET /user/confirm-password` (Fortify の `password.confirm` ルート) に
直接アクセスすると 500 Internal Server Error になることが発見された。

### 根本原因 (再現・特定済み)

shard-report の推定 (「intended URL 等のセッション値未設定によるクラッシュ」) は**誤り**で、
実際はセッション状態に依存しない決定的なクラッシュである:

1. 本アプリは Fortify 生の step-up (`password.confirm`、password 限定・3h 窓) を廃し、
   generic recent-auth (15 分窓・password or 再SSO、`/recent-auth/confirm` +
   `RequireRecentAuth` middleware) に統一している
   (`config/fortify.php` L152-162、`app/Providers/FortifyServiceProvider.php` L107-108)。
   このため `Fortify::confirmPasswordView()` を意図的に登録していない。
2. しかし Fortify は `config('fortify.views') === true` の場合、feature フラグ
   (`twoFactorAuthentication.confirmPassword => false`) に**関係なく**
   `GET /user/confirm-password` (`password.confirm`) を無条件登録する
   (`vendor/laravel/fortify/routes/routes.php` L118-121。この feature フラグは
   2FA 管理ルートへの `password.confirm` middleware 適用可否のみを制御する)。
3. `ConfirmablePasswordController::show()` は `app(ConfirmPasswordViewResponse::class)` を
   解決するが、この contract は `Fortify::confirmPasswordView()` を呼んだときにのみ
   bind される (Fortify の `registerResponseBindings()` に default binding が**ない**)。
4. 結果、`BindingResolutionException: Target [Laravel\Fortify\Contracts\ConfirmPasswordViewResponse]
   is not instantiable` → 500。tinker での contract 解決により実挙動を確認済み。

## 改善アイデア

Fortify の公式拡張点 `Fortify::confirmPasswordView()` に **redirect を返す closure** を登録し、
`GET /user/confirm-password` への直アクセスを正規の step-up 画面
`route('recent-auth.confirm')` へ 302 で誘導する。

`Fortify::confirmPasswordView()` は callable を受け取れ、`SimpleViewResponse::toResponse()` は
callable の戻り値が Response ならそのまま返す (vendor 実装確認済み) ため、
`static fn (): RedirectResponse => redirect()->route('recent-auth.confirm')` の 1 行で成立する。

変更箇所は `app/Providers/FortifyServiceProvider.php` の `configureViews()`
(現在「confirmPasswordView は登録しない」とコメントしている箇所) のみ。

### 代替案と不採用理由

- **案B: この URL で Auth/ConfirmRecentAuth を直接 Inertia render する**
  → 同一画面が 2 URL に重複し、canonical URL (recent-auth/confirm) が曖昧になる。
  intended 復帰や画面仕様の変更時に 2 経路の追従が必要になり、思考原則 3
  (後方互換の並走を残さない) に反する。不採用。
- **案C: `Fortify::ignoreRoutes()` + 手動ルート再登録で route 自体を消す**
  → Fortify 登録ルート全体の再配線が必要でオーバーエンジニアリング
  (思考原則 2)。また既存の `password.confirm.store` (POST) / `password.confirmation`
  (GET status) の扱いも巻き込み、スコープが膨らむ。不採用。

## 期待効果

- 機微操作の再認証というセキュリティ中核導線から生エラー (500) を排除 (F-11 解消)。
  再認証導線で詰み画面を出さない。
- 直アクセス・ブックマーク・既存リンク由来で `/user/confirm-password` に到達しても、
  ユーザーは正規の再認証画面 `recent-auth.confirm` に 302 で誘導され 200 でフォームが出る。
  誘導先の `Auth/ConfirmRecentAuth` 画面は password 再入力と再SSO の両 satisfier を
  提示する (`ConfirmRecentAuthController::show()` の `passwordSet` /
  `availableProviders` / `canSatisfy` props。既存テスト
  `RecentAuthTest`「confirm 画面は passwordSet / availableProviders / canSatisfy を返す」が保証)
  ため、SSO-only ユーザーも詰まない。
- **注意 (効果に含めないこと)**: 本修正は GET view の救済 redirect であり、Laravel 標準の
  `password.confirm` middleware (`auth.password_confirmed_at` を要求) の互換を提供する
  ものではない。middleware 互換が必要になった場合は別タスクで設計する
  (config/fortify.php の既存 TODO(template) と同じ棚)。

## 実装方針（概要）

1. **再現テスト先行** (テストファースト): `tests/Feature/Auth/RecentAuthTest.php` に
   「認証済みユーザーの `GET /user/confirm-password` が 500 にならず
   `recent-auth.confirm` へ 302 → 追従して 200 で Auth/ConfirmRecentAuth フォームが出る」
   テストを追加し、fail (500) を確認。
2. `FortifyServiceProvider::configureViews()` に
   `Fortify::confirmPasswordView(static fn (): RedirectResponse => redirect()->route('recent-auth.confirm'));`
   を追加。コメントで「これは GET view の救済 redirect であり、`password.confirm`
   middleware 互換 (`auth.password_confirmed_at` の充足) は提供しない」ことを明記し、
   将来の実装者の誤認を防ぐ (recent-auth.confirm 側も `password.confirm` に依存しない)。
3. 既存の recent-auth / Fortify 系テスト (`RecentAuthTest` / `FortifyResponseTest` 等) が
   green のままであることを確認。

## 制約・前提

- Fortify の response contract 差し替えは既に本 Provider で多数実施しているパターンで、
  アーキテクチャと整合する (フレームワークのレンジ内)。
- `redirect()->route()` は closure 内でリクエスト時に評価されるため、boot 時の route 未解決問題はない。
- 直アクセス時は `url.intended` が未設定のため、再認証完了後は dashboard へ遷移する
  (`ConfirmRecentAuthController::confirmPassword()` の `redirect()->intended(route('dashboard'))`)。
  これは既存契約どおりで新規実装は不要。
- PHPStan level 10: closure に戻り値型 `RedirectResponse` を明示。

## スコープ外

- `POST /user/confirm-password` (`password.confirm.store`) と
  `GET /user/confirmed-password-status` (`password.confirmation`) の扱い。
  これらは 500 にならず (POST は password 検証のうえ Fortify 独自の
  `auth.password_confirmed_at` を stamp するのみで、本アプリの gate である
  `recent_auth_at` には影響しない)、本アプリのどのルートも `password.confirm`
  middleware を使っていないため実害がない。config/fortify.php の既存 TODO(template)
  (Fortify 2FA 管理ルートへの recent-auth 後付け配線) と併せて別タスクで棚卸しする。
- F-11 の「実際の詰みトリガー導線」(F-12 オーナー移譲 UI 欠落等) は別 finding。
- bughunt 環境固有の問題 (F-13 等) への対応。
