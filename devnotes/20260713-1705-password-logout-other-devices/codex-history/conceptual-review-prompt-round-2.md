# 概念設計レビュー Round 2

Round 1 の指摘に対応しました。以下が対応マトリクスと修正後の概念設計です。再レビューをお願いします。

## Round 1 指摘への対応マトリクス

### [Critical] logoutOtherDevices + DB行削除だけでは remember-me 再侵入を防げない → 対応
Laravel source を検証し指摘が正しいことを確認（`retrieveByToken` は remember_token のみ照合、
recaller の password_hash は AuthenticateSession でしか照合されない）。
**remember_token を rotate する**設計に変更した:
1. 新パスワードハッシュ保存
2. `$user->setRememberToken(Str::random(60))` + save で rotate → 他デバイス recaller は
   `retrieveByToken` で token 不一致となり失効
3. `Auth::logoutOtherDevices($input['password'])` → `queueRecallerCookie()` が rotate 後の
   新 token を読み、現在デバイスの recaller のみ再発行（現在維持）+ OtherDeviceLogout イベント
4. DB セッション行削除（database driver 時、現在セッションID除外）
これで AuthenticateSession をグローバル配線せずとも session 経路（行削除）と remember-me 経路
（token rotate）の両輪で閉じられる。

### [Critical] 期待効果の記述 → 対応
「remember_token rotate により recaller が token 不一致で失効」と正確に書き直した。

### [Warning] 新 password を渡す旨明記 → 対応（`$input['password']` と明記）

### [Warning] 失敗時整合性 → 対応（保存・rotate・行削除を `DB::transaction()` で囲む）

### [Warning] AuthenticateSession の判断軸 → 一部対応（rotate で remember-me を閉じたため今回不要と判断軸を明記）

### [Warning] 他経路（reset/SSO）→ 対応（不変条件を明文化、reset は別TODO follow-up 推奨。リセット時は未ログインでフローが異なるため今回統合しない）

### [Suggestion] session テーブル名/ID を設定から取得 → 対応（`config('session.table','sessions')` / `session()->getId()`）

---

## 修正後の概念設計

（下に conceptual-design.md 全文を貼付）
# 概念設計: password-logout-other-devices

## 背景・課題

bug-hunt finding **F-H4 (High / authz_bypass)**。

`app/Actions/Fortify/UpdateUserPassword.php` は `current_password` 検証 → 新パスワードの
ハッシュ保存のみで完結しており、パスワード変更後も **既存の他セッション / remember-me
(recaller) トークンが有効なまま**残る。

これは「アカウントが乗っ取られたかもしれない」という状況での**防御的パスワード変更**が、
攻撃者が確立済みのセッションや remember-me ログインを排除できないことを意味する。ユーザーが
「パスワードを変えた = 侵入者を締め出せた」と期待する挙動を裏切っており、認可境界の実質的な
バイパスを許す。

### 現状の技術的事実（調査結果）

- `config/session.php`: `driver = database`（`SESSION_DRIVER`, 既定 `database`）。`sessions`
  テーブルは `0001_01_01_000000_create_users_table.php` で作成済み、`user_id` (nullable, index)
  カラムを持つ。
- Fortify の `PasswordController::update()` は `UpdatesUserPasswords::update()` を呼ぶだけで、
  他デバイスのログアウト処理は**アプリ側（この Action）の責務**。
- `Illuminate\Session\Middleware\AuthenticateSession` は **web ミドルウェアグループに未配線**
  （Filament admin panel のみで使用）。したがって「session の password_hash 比較による
  他セッション失効」は現状**発火しない**。
- `Auth` ファサードには `@method static ...logoutOtherDevices(string $password)` が定義済み
  （`Support/Facades/Auth.php` L44）。PHPStan L10 で直接呼び出し可能。

## 改善アイデア

Laravel 標準の "Log Out Other Browser Sessions" 相当を、`UpdateUserPassword::update()` の中で
**新パスワード保存の直後に**実行する。**セッション経路**と **remember-me 経路**の両輪で他デバイス
を確実に締め出すため、次の 3 段を `DB::transaction()` で囲んで実行する:

1. **remember_token の rotate**（remember-me 経路を閉じる）。
   - `$user->setRememberToken(Str::random(60))` して save する。remember-me 復帰は
     `SessionGuard::userFromRecaller()` → `EloquentUserProvider::retrieveByToken($id, $token)`
     が **remember_token のみを `hash_equals` で照合**する（recaller Cookie 第3セグメントの
     password_hash は `AuthenticateSession` ミドルウェア経由でしか照合されない）。したがって
     token を rotate すれば、他デバイスの recaller (`id|oldToken|oldHash`) は token 不一致で
     **確実に失効**する。これが本設計の核心（Round 1 レビューで確認した Laravel 実挙動に基づく）。

2. **`Auth::logoutOtherDevices($input['password'])`** を呼ぶ（現在デバイスの remember-me を維持）。
   - `queueRecallerCookie()` が **rotate 後の新 remember_token** を読み、現在デバイスの recaller
     Cookie を再発行する（現在リクエストが recaller を持つ場合のみ。session-only ログインには
     無影響）。これにより**現在デバイスのみ remember-me が生存**する。`OtherDeviceLogout`
     イベントも発火（audit / listener 用）。
   - **渡すのは `current_password` ではなく、保存直後の新 `password`（`$input['password']`）**。
     `logoutOtherDevices` は呼び出し時点の保存済みハッシュに対し `Hash::check` するため。

3. **DB セッション行の明示削除**（セッション経路を閉じる）。
   - `config('session.driver') === 'database'` のとき、`config('session.table','sessions')` から
     当該 `user_id` かつ**現在のセッションID (`session()->getId()`) 以外**の行を削除する。
     database ドライバでは行削除がそのままセッション失効（次リクエストで未認証）になる。これは
     Jetstream の `deleteOtherSessionRecords` と同じ手法。テーブル名・現在IDは設定/フレーム
     ワーク API から取得しリテラル直書きを避ける。

3 段を `DB::transaction()` で囲むことで、DELETE 失敗時にパスワード保存・token rotate ごと
ロールバックし、「パスワードだけ変わって他セッションが残る」不整合を防ぐ。現在のセッション行は
除外し、recaller は logoutOtherDevices が再発行するため、**操作中のユーザー自身はログアウト
されない**。

### なぜ AuthenticateSession をグローバル配線しないか

`AuthenticateSession` を web グループ／認証ルート群へ追加する案（もう一つの Laravel 標準経路）は、
全認証済み web リクエストで毎回 password_hash 照合を走らせる多層防御である。しかし本 finding
（パスワード変更時の他デバイス失効）は、上記の **remember_token rotate（remember-me 経路）** と
**DB セッション行削除（session 経路）** の両輪で必要十分に閉じられる。したがって
**「今必要なものだけ作る（オーバーエンジニアリング禁止）／フレームワークのレンジ内でやる」
（思考原則）に従い、今回はミドルウェアのグローバル配線を行わない**。auth.session の認証ルート群
適用は将来の多層防御として有効な選択肢であり、スコープ外に判断軸を記す。

## 期待効果

- **使命への貢献**: 本アプリは現場作業者の SOP・動画マニュアルを扱い、組織/チーム/プロジェクト
  の RBAC で機微データ（PII は CipherSweet 暗号化）を保護する。パスワード変更で侵入セッションを
  締め出せることは、テナント境界・認可の実効性を担保する基本的な安全不変条件であり、プラット
  フォームの信頼性の前提となる。
- **具体的改善**: パスワード変更後、攻撃者の既存セッション（**DB セッション行削除**）と
  remember-me（**remember_token rotate により recaller が retrieveByToken で token 不一致**）が
  ともに失効する。防御的パスワード変更が意図どおり機能する。
- 現在の操作ユーザーはログアウトされない。現在デバイスの remember-me は rotate 後トークンで
  recaller が再発行されるため生存し、UX を損なわない。

## 実装方針（概要）

- `app/Actions/Fortify/UpdateUserPassword.php` の `update()` を拡張。検証は既存どおり
  （current_password + Password::default）。検証後、`DB::transaction()` 内で:
  1. `forceFill(['password' => Hash::make($input['password'])])->save()`（新ハッシュ確定）。
  2. `$user->setRememberToken(Str::random(60))` して save（remember_token rotate）。
  3. `Auth::logoutOtherDevices($input['password'])`（現在デバイス recaller 再発行 + イベント）。
  4. driver が database のとき `sessions` テーブルの他行を削除（現在セッションID除外）。
- ロジックは薄く保ち、DB 削除は 1 クエリ。DTO/JsonResource は不要（void アクション、レスポンス
  生成なし）。`response()->json()` 直書きなし。`Auth::logoutOtherDevices` は Auth ファサードの
  公開 API（`@method` 定義済）で PHPStan L10 安全。

## 制約・前提

- 対象は Fortify の**セルフサービス**パスワード変更経路（`user-password.update`）。この経路では
  ガードの認証ユーザー = 引数 `$user` であることが保証される（他人のパスワード変更ではない）。
  ガードユーザーが不在の場合 `logoutOtherDevices` は早期 return（no-op）で安全。
- `session.driver` が database 以外（例: テスト既定の `array`）の場合、DB 行削除はスキップする
  （defensive guard）。本番既定は database のため実効性は担保される。
- 既存の PasswordPolicy / current_password 検証 / confirmed 不使用の方針は維持（変更しない）。

## 不変条件（明文化）

- **パスワードを変える全経路は、変更後に他デバイスのセッション / remember-me を失効させる。**
  本 finding では認証済み変更経路（`user-password.update` → `UpdateUserPassword`）を対象に
  この不変条件を実装する。

## スコープ外

- `AuthenticateSession` ミドルウェアの認証ルート群配線（上記理由により今回は行わない。将来の
  多層防御の選択肢として判断軸のみ記録）。判断軸は「global か なしか」ではなく、rotate + 行削除で
  finding が閉じるため**今回は不要**、というもの。
- **パスワードリセット経路（`app/Actions/Fortify/ResetUserPassword.php` / `NewPasswordController`）**：
  確認したところ同経路も他セッション失効を行っておらず同種の不変条件破りがある。ただしリセット時は
  ユーザーが未ログイン（保持すべき現在セッションが無い）ため「全セッション破棄 + token rotate」と
  なり本設計（現在維持）とフローが異なる。「似ているから」で今回統合しない（思考原則#4）。
  **別 TODO として follow-up 起票を推奨**（本スキルは TODO 起票しない）。SSO ログイン経路も同様。
- 「現在のブラウザセッション一覧の表示 / 個別失効 UI」（Jetstream の profile 画面相当）。今回は
  パスワード変更に付随する自動失効のみ。
