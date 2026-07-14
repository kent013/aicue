# アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

# 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

# セキュリティ不変条件（抜粋）

- tenant キー不信 / 子は親に属する(404先行) / cross-org 不可 / 権限判定は laratrust_team_id 明示 / PII は CipherSweet。

【思考原則 — 全議論に適用】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ（Laravel/Svelte の既存解決策を使え）。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性
2. 禁止事項違反の有無
3. 実現可能性（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性
5. リスク（重大な副作用・後退）
6. スコープの適切さ
7. 型安全性（DTO/JsonResource、PHPStan L10）

【特に検証してほしい技術的論点】
- `Auth::logoutOtherDevices()` は database session driver 単体で他「セッション行」を失効させないため、DB セッション行の明示削除で補完する設計だが、この理解と補完は正しいか。
- `AuthenticateSession` をグローバル配線しない判断は妥当か（過小スコープになっていないか）。
- remember_token を cycle せず recaller の password_hash 経路に委ねる判断は正しいか。
- 現在セッションを維持しつつ他セッションのみ失効させる設計に穴はないか。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計


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

Laravel/Jetstream の標準 "Log Out Other Browser Sessions" 相当を、`UpdateUserPassword::update()`
の中で**パスワード保存の直後に**実行する。具体的には次の 2 段を組み合わせる:

1. **`Auth::logoutOtherDevices($input['password'])`** を呼ぶ。
   - パスワードハッシュを再ハッシュし、**現在のデバイスの recaller (remember-me) Cookie を
     新ハッシュで再発行**する。recaller Cookie は `id|remember_token|password_hash` 形式のため、
     パスワードハッシュが変わると**他デバイスの remember-me は次回検証で失効**する（現在デバイス
     のみ再発行で生存）。`OtherDeviceLogout` イベントも発火する。

2. **DB セッションレコードの明示削除**（`config('session.driver') === 'database'` のとき）。
   - `sessions` テーブルから当該 `user_id` かつ**現在のセッションID以外**の行を削除する。
     database ドライバでは行削除がそのままセッション失効（次リクエストで未認証）になるため、
     `AuthenticateSession` ミドルウェアをグローバル配線しなくても他ブラウザセッションを確実に
     締め出せる。これは Jetstream の `deleteOtherSessionRecords` と同じ手法。

現在のセッションID (`session()->getId()`) を除外し、recaller は logoutOtherDevices が再発行する
ため、**操作中のユーザー自身はログアウトされない**。

### なぜ AuthenticateSession をグローバル配線しないか

`AuthenticateSession` を web グループへ追加する案（もう一つの Laravel 標準経路）は、全認証済み
web リクエストの挙動を変える広い波及を持つ。今回の目的（他セッション失効）は DB セッション行の
直接削除で database ドライバに対して確実に達成できるため、**「今必要なものだけ作る／フレーム
ワークのレンジ内」（思考原則）に従い、ミドルウェアのグローバル配線は行わない**。DB 行削除は
ドライバに即した直接的かつ副作用の小さい経路。

## 期待効果

- **使命への貢献**: 本アプリは現場作業者の SOP・動画マニュアルを扱い、組織/チーム/プロジェクト
  の RBAC で機微データ（PII は CipherSweet 暗号化）を保護する。パスワード変更で侵入セッションを
  締め出せることは、テナント境界・認可の実効性を担保する基本的な安全不変条件であり、プラット
  フォームの信頼性の前提となる。
- **具体的改善**: パスワード変更後、攻撃者の既存セッション（DB 行削除）と remember-me
  (recaller の password_hash 不一致) が失効する。防御的パスワード変更が意図どおり機能する。
- 現在の操作ユーザーはログアウトされず UX を損なわない。

## 実装方針（概要）

- `app/Actions/Fortify/UpdateUserPassword.php` の `update()` を拡張:
  1. 既存どおり検証 → `forceFill(['password' => Hash::make(...)])->save()`（新ハッシュ確定）。
  2. `Auth::logoutOtherDevices($input['password'])`（recaller 再発行 + イベント）。
  3. driver が database のとき `sessions` テーブルの他行を削除（現在セッションID除外）。
- ロジックは薄く保ち、DB 削除は 1 クエリ。DTO/JsonResource は不要（void アクション、レスポンス
  生成なし）。`response()->json()` 直書きなし。

## 制約・前提

- 対象は Fortify の**セルフサービス**パスワード変更経路（`user-password.update`）。この経路では
  ガードの認証ユーザー = 引数 `$user` であることが保証される（他人のパスワード変更ではない）。
  ガードユーザーが不在の場合 `logoutOtherDevices` は早期 return（no-op）で安全。
- `session.driver` が database 以外（例: テスト既定の `array`）の場合、DB 行削除はスキップする
  （defensive guard）。本番既定は database のため実効性は担保される。
- 既存の PasswordPolicy / current_password 検証 / confirmed 不使用の方針は維持（変更しない）。

## スコープ外

- `AuthenticateSession` ミドルウェアのグローバル配線（上記理由により今回は行わない）。
- パスワードリセット経路（`NewPasswordController`）や SSO ログインのセッション失効設計。
- 「現在のブラウザセッション一覧の表示 / 個別失効 UI」（Jetstream の profile 画面相当）。今回は
  パスワード変更に付随する自動失効のみ。
- remember_token カラム自体の cycle（recaller の password_hash 経路で失効が成立するため不要。
  cycle すると現在デバイスの recaller も切れるため、むしろ logoutOtherDevices の再発行方式が適切）。
