# 概念設計レビュー Round 3

Round 2 の Critical を全面的に受け入れ、Laravel/Jetstream 標準の "Log Out Other Browser Sessions"
構成へ設計を転換しました。対応マトリクスと修正後の概念設計です。再レビューをお願いします。

## Round 2 指摘への対応マトリクス（要約）

- **[Critical] 並行書き戻し競合 / AuthenticateSession をスコープに含めよ** → **全面対応**。
  `AuthenticateSession`（alias `auth.session`）を **web グループ（グローバル append）** に配線。
  correctness は AuthenticateSession の password_hash 照合が担い、復活行も次リクエストで失効。
  設計を **3 層**に再構成:
  1. AuthenticateSession 配線（correctness の要 / 並行競合を閉じる / guest no-op）
  2. logoutOtherDevices（現在デバイス維持 + recaller 再発行 + OtherDeviceLogout イベント）
  3. 他 session 行の best-effort 削除（即時クリーンアップ。失敗しても層1が保証 → transaction 不要）
- **[Critical] 「両輪で必要十分」は不成立** → 期待効果を上記 3 層で書き直し。
- **remember_token rotate** → **撤回**（AuthenticateSession の viaRemember 分岐が recaller hash を
  照合するため不要。Jetstream も rotate しない）。
- **[Warning] queueRecallerCookie 無条件では?** → 反論。pinned source framework v13.18.0
  `SessionGuard::logoutOtherDevices` L748-750 は `if ($this->recaller() || hasQueued(...))` の条件付き。
- **[Warning] transaction 内 cookie/event 副作用の乖離** → transaction 撤去で解消（best-effort 削除）。
- **[Warning] session.connection 差異** → `DB::connection(config('session.connection'))->table(...)` で
  session 接続を明示。
- **[Warning] テスト方針が無い** → 概念設計に「テスト方針」節を追加（(a)〜(e)）。
- **[Warning] 不変条件と reset スコープ外の矛盾** → 不変条件を「認証済みセルフサービス変更経路」に
  限定。reset は別 follow-up TODO（本 PR 完了条件にしない）。
- **[Suggestion] config() の型検証** → `Assert::string(...)` で string 保証してから使用。

残課題や新たな穴があれば指摘してください。特に AuthenticateSession のグローバル配線が既存フロー
（Fortify ログイン / 2FA チャレンジ / SSO / パスワードリセット / 既存テストの actingAs）に与える
副作用の見落としがないか確認してください。

---

## 修正後の概念設計（全文）

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

Laravel/Jetstream 標準の "Log Out Other Browser Sessions" 構成をそのまま採用する（先人の知恵）。
Laravel の `logoutOtherDevices()` は **`AuthenticateSession` ミドルウェアとの併用が前提**であり、
これを欠くと「他デバイスを確実に失効させる」不変条件を満たせない（Round 2 レビューで並行
書き戻し競合により確認）。したがって次の **3 層** で構成する:

### 層1: `AuthenticateSession` ミドルウェアの配線（correctness の要）

`Illuminate\Session\Middleware\AuthenticateSession`（alias `auth.session`）を **web ミドルウェア
グループ（グローバル append）** に追加する。

- 各認証済みリクエストで、session に保存した `password_hash_web` と user の現在ハッシュを
  `hash_equals` 照合し、**不一致なら現在デバイスを logout + session flush**（`viaRemember` 時は
  recaller Cookie 第3セグメントの password_hash も照合）。
- これにより、(a) 他デバイスの既存 session（旧ハッシュ保持）は次リクエストで失効し、(b) 攻撃者の
  in-flight リクエストがレスポンス終了処理で session 行を**書き戻して復活**させても、次リクエストで
  ハッシュ不一致により失効する。行削除だけでは塞げない**並行書き戻し競合を閉じる**。
- guest には **no-op**（`$request->user()` 不在で即 `next`）。副作用は認証済みリクエストのみ。
  web.php の auth ルート群と Fortify 登録の認証ルート（`user-password.update` 等）を**漏れなく**
  カバーするためグローバル配線を選ぶ（既に Filament admin panel で採用実績あり）。

### 層2: `Auth::logoutOtherDevices($input['password'])`（現在デバイス維持 + 監査）

新パスワード保存の直後に呼ぶ。

- user の password を再ハッシュ（force）し、以降 AuthenticateSession の password_hash 照合の
  基準を確定させる。**現在デバイスの session** は同一リクエストの AuthenticateSession 終端処理が
  新ハッシュを session に再保存するため維持される。
- 現在リクエストが recaller（remember-me）Cookie を持つ場合に限り `queueRecallerCookie()` で
  **現在デバイスの recaller を新ハッシュで再発行**する（条件付き。pinned source framework
  v13.18.0 `SessionGuard::logoutOtherDevices` L748-750 で確認。session-only ユーザーに recaller を
  新規付与しない）。これで現在デバイスの remember-me も維持される。
- `OtherDeviceLogout` イベントを発火（audit / listener 用）。
- **渡すのは `current_password` ではなく、保存直後の新 `password`（`$input['password']`）**。
  `logoutOtherDevices` は呼び出し時点の保存済みハッシュに `Hash::check` するため。

### 層3: 他 session 行の best-effort 削除（即時クリーンアップ）

`config('session.driver') === 'database'` のとき、当該 `user_id` かつ**現在の session ID
（`session()->getId()`）以外**の `sessions` 行を 1 クエリで削除する。

- 目的は「攻撃者を次リクエストを待たず**即時に**締め出す」こと。correctness は層1が保証するため、
  この削除は best-effort（失敗しても層1 が次リクエストで失効させる）。よって **transaction は不要**で、
  Round 1〜2 で議論した「DB rollback と cookie/event 副作用の乖離」問題は発生しない。
- 削除は **session 設定の接続**で行う:
  `DB::connection(config('session.connection'))->table(config('session.table','sessions'))`。
  テーブル名・接続名・現在 ID は設定/フレームワーク API から取得しリテラル直書きを避け、
  `Webmozart\Assert\Assert::string(...)` 等で string を保証してから使う（PHPStan L10）。

### remember_token rotate を行わない理由

recaller の password_hash 照合は層1（AuthenticateSession の viaRemember 分岐）が担うため、
remember_token を rotate しなくても他デバイスの remember-me は次リクエストで失効する。Jetstream も
rotate せず AuthenticateSession + logoutOtherDevices + deleteOtherSessionRecords で構成している。
rotate は現在デバイスの recaller を壊すリスクと再発行順序の複雑さを持ち込むため**採用しない**
（今必要なものだけ作る=思考原則#2）。

## 期待効果

- **使命への貢献**: 本アプリは現場作業者の SOP・動画マニュアルを扱い、組織/チーム/プロジェクト
  の RBAC で機微データ（PII は CipherSweet 暗号化）を保護する。パスワード変更で侵入セッションを
  締め出せることは、テナント境界・認可の実効性を担保する基本的な安全不変条件であり、プラット
  フォームの信頼性の前提となる。
- **具体的改善（3 層で担保）**:
  1. **DB 行削除**: 攻撃者の既存 session を即時失効。
  2. **AuthenticateSession の password_hash 照合**: 復活した行・他デバイスの session / recaller を
     次リクエストで失効（並行書き戻し競合を閉じる）。
  3. **logoutOtherDevices**: 現在デバイスの session / recaller を維持し、監査イベントを発火。
  防御的パスワード変更が意図どおり機能する。
- 現在の操作ユーザーはログアウトされない（UX を損なわない）。

## 実装方針（概要）

1. **ミドルウェア配線（`bootstrap/app.php`）**: web グループ append の先頭に
   `AuthenticateSession::class` を追加（StartSession 後・他 append 前）。guest no-op のため既存
   guest フローに影響なし。
2. **`app/Actions/Fortify/UpdateUserPassword.php` の `update()` 拡張**。検証は既存どおり
   （current_password + Password::default）。検証後:
   1. `forceFill(['password' => Hash::make($input['password'])])->save()`（新ハッシュ確定）。
   2. `Auth::logoutOtherDevices($input['password'])`（現在デバイス維持 + recaller 再発行 + イベント）。
   3. driver が database のとき、session 接続で他 session 行を best-effort 削除（現在 ID 除外）。
- ロジックは薄く保ち、DB 削除は 1 クエリ・transaction なし。DTO/JsonResource は不要（void
  アクション、レスポンス生成なし）。`response()->json()` 直書きなし。`Auth::logoutOtherDevices` は
  Auth ファサードの公開 API（`@method` 定義済）で PHPStan L10 安全。config 値は `Assert::string`
  で型保証してから使う。

## テスト方針

Feature テスト（Pest, `RefreshDatabase` グローバル適用, Factory 使用）で以下を検証する。詳細は
詳細設計のテスト計画へ:

- (a) パスワード変更後、当該 user の**他 session 行が削除**され、現在 session 行と**別 user の
  session 行は残る**（`session.driver=database` に `config()` で切替え、行を Factory/挿入で用意）。
- (b) 変更前の **古い recaller Cookie で再認証できない**（別クライアントで recaller を提示し、
  AuthenticateSession により失効することを確認）。
- (c) **現在デバイスの session が維持**される（変更リクエスト後も認証済みで保護ルートに 200）。
- (d) 攻撃者 session を模した**復活行が次リクエストで拒否**される（AuthenticateSession の
  password_hash 不一致 → logout）。
- (e) `session.driver != database`（例: array）では行削除をスキップしエラーにならない。
- Architecture テスト観点: セキュリティ不変条件として Feature テストへ登録（AGENTS.md 禁止事項#1）。

## 制約・前提

- 対象は Fortify の**セルフサービス**パスワード変更経路（`user-password.update`）。この経路では
  ガードの認証ユーザー = 引数 `$user` であることが保証される（他人のパスワード変更ではない）。
  ガードユーザーが不在の場合 `logoutOtherDevices` は早期 return（no-op）で安全。
- `session.driver` が database 以外（例: テスト既定の `array`）の場合、DB 行削除はスキップする
  （defensive guard）。本番既定は database のため実効性は担保される。
- 既存の PasswordPolicy / current_password 検証 / confirmed 不使用の方針は維持（変更しない）。

## 不変条件（明文化）

- **認証済みセルフサービスのパスワード変更経路（`user-password.update` → `UpdateUserPassword`）は、
  変更後に他デバイスの session / remember-me を失効させる。** 本 finding はこの経路に限定して
  不変条件を実装する（reset / SSO 経路は下記のとおり別 follow-up）。

## スコープ外

- **パスワードリセット経路（`app/Actions/Fortify/ResetUserPassword.php` / `NewPasswordController`）**：
  確認したところ同経路も他セッション失効を行っておらず同種の不変条件破りがある。ただしリセット時は
  ユーザーが未ログイン（保持すべき現在セッションが無い）ため「全セッション破棄」となり本設計
  （現在維持）とフローが異なる。「似ているから」で今回統合しない（思考原則#4）。**別 TODO として
  follow-up 起票を推奨**（本 PR の完了条件にはしない）。SSO ログイン経路も同様。なお層1の
  `AuthenticateSession` 配線はリセット後も password_hash 照合を効かせるため、reset 経路でも
  副次的に「旧 session が次リクエストで失効」する多層防御は働く（ただし即時削除は別 TODO）。
- 「現在のブラウザセッション一覧の表示 / 個別失効 UI」（Jetstream の profile 画面相当）。今回は
  パスワード変更に付随する自動失効のみ。
