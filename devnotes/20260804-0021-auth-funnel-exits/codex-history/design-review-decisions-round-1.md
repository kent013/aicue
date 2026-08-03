# 対応マトリクス: design-review Round 1

Codex 判定: 全体 **CHANGES_REQUESTED** (施策 A: REQUEST_CHANGES / 施策 B: REQUEST_CHANGES)。
論点 (a)「verified ゲートを緩めず CTA を消す」方針は **妥当** と判定された。

## 施策 A

### [Warning] 回帰検知が `verify-email-continue` testid 依存で、別実装の踏破不能 CTA を検出できない
- 判断: **対応する（ただし architecture テスト化の案は採らない）**
- 根拠: 指摘のとおり testid 依存では一般化されない。一方
  「architecture テストで `VerifyEmail.svelte` から checkout 直遷移を禁止する」案は、
  URL が prop 経由で渡る本件では静的文字列走査で捕まらず（元の bug も literal を持っていなかった）、
  ページ固有ルールを architecture 層へ持ち込むと保守負債になる。
- 対応内容: vitest 側の不変条件を**ラベル非依存**に強化した:
  `continuesToCheckout` が true / false のどちらでも
  「描画される button は『認証メールを再送信』『ログアウト』の 2 つだけ」
  「role=link の要素が 0 個」を厳密比較で固定する。新しい CTA が実装方法を問わず混入すれば落ちる。
  併せて `verify-email-continue` 不在の assert と、Feature 側の `missing('continueUrl')` を維持する。

### [Suggestion] `hasContinuation()` と `resolveUrl()` の同値性をデータプロバイダ化
- 判断: **対応する**
- 対応内容: Pest `dataset` で 5 ケース（remember 後 / 他組織 id / 非 int / null user / forget 後）を列挙し、
  各ケースで `hasContinuation() === (resolveUrl() !== null)` という**同値性そのもの**を assert する。

## 施策 B

### [Warning] `footerSnippetBody()` の正規表現抽出が脆い（Svelte 構文変化・snippet 入れ子）
- 判断: **一部対応する（AST 化は反論、fail-closed ガードは採用）**
- 根拠: 同ファイルの既存契約（`AppLayout` 側）は正規表現 + import 識別子解決で統一されており、
  1 ファイルに 2 方式を並走させるのは保守上不利。`svelte/compiler` の AST 形状は
  Svelte のマイナー更新で変わりうるため、テスト基盤の脆さを別の場所へ移すだけになりうる。
- 対応内容: 抽出器に **fail-closed ガード**を入れた。footer snippet 定義が 2 個以上、または
  抽出本体に `{#snippet` が入れ子で現れる場合は**例外で落とす**（黙って pass しない）。
  ガードが発火したら AST 方式へ移行する、と設計に明記した。

### [Warning] allowlist の死蔵エントリ・typo を機械検出できない
- 判断: **対応する**
- 対応内容: `AUTH_EXIT_ALLOWLIST` の各 path について「ファイルが実在する」
  「そのファイルが `AuthLayout` を import している」を検証する it を追加した。

### [Warning] `ConfirmRecentAuth` の既存 `/forgot-password` 導線は認証済み文脈で到達不能の疑い
- 判断: **対応する（指摘は正しい。裏取り済み）**
- 根拠: `/forgot-password` は Fortify が `guest` middleware 付きで登録している
  (`vendor/laravel/fortify/routes/routes.php:55-57`)。本画面のユーザーはログイン済みのため
  `RedirectIfAuthenticated` でフォームに到達しない（リセットメールも送られない）。
  さらに `UpdateUserPassword` は `current_password` 必須のため、パスワード未設定ユーザーは
  アプリ内でパスワードを設定できない (`app/Actions/Fortify/UpdateUserPassword.php:33`)。
  = **F-2-01 と完全に同 species の踏破不能導線**であり、本 TODO の主題そのもの。
- 対応内容: 施策 B に **B-2** を追加。`canSatisfy=false` 分岐の CTA を
  「ログアウトしてパスワードを設定する」(`router.post('/logout')`) に差し替え、説明文を
  実際に踏破できる手順（ログアウト → ログイン画面の「パスワードをお忘れの方」）へ書き換える。
  併せて `tests/Feature/Auth/RecentAuthTest.php` に
  「ログイン済みユーザーは GET `/forgot-password` のフォームに到達できない」を追記し、
  「認証済み画面から `/forgot-password` へリンクしない」根拠を仕様として固定する。
  vitest 側でも `canSatisfy=false` で `/forgot-password` リンクが無いことを固定する。

## 論点への回答
- (a) 妥当と判定されたため方針変更なし。
- (b) 「方向性は良いが強化必要」→ fail-closed ガード + allowlist 健全性検査で強化した。
- (c) 「あと一歩」→ A の UI 回帰を testid 非依存へ一般化、B の allowlist 健全性を追加。
