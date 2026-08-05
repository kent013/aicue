# 対応マトリクス: conceptual-review Round 2

## [Critical] LoginMethodInventory の passkey 判定が TOTP 制約を反映していない

- 判断: **対応する (指摘のとおり自己矛盾)**
- 根拠: Round 1 で追加した「TOTP 有効ユーザーの passkey login 拒否」と、
  inventory の「passkey 行あり AND feature 有効」が矛盾している。
  TOTP 有効ユーザーにとって passkey は**ログイン手段ではない**のに手段として数えてしまい、
  「今この瞬間使える」という自分で立てた定義を破っている。
  さらに条件を 2 箇所に書けば必ず乖離する、という指摘も正しい。
- 対応内容: `App\Services\Auth\PasskeyLoginPolicy` を新設し、
  「その User が passkey で**ログイン**できるか」の判定を**唯一の源**にする。
  `Passkeys::authorizeLoginUsing()` の closure と `LoginMethodInventory` の
  両方がこの policy を呼ぶ。closure にロジックを書かない。
  policy が集約する条件: feature 有効性 / TOTP confirmed 状態 / 将来の裁定反転点。
  「両者が同じ policy を経由すること」を Architecture テストで固定する。

## [Critical] (F) の対策が「汚染を害にしない」だけで実除去になっていない

- 判断: **対応する**
- 根拠: 正しい。`PasswordConfirmMiddlewareAbsenceTest` は route middleware しか見ておらず、
  session 値を直接参照するコードや vendor 内部利用を捕まえない。
  「書かない」という既存不変条件を満たしていない。
- 対応内容: **実際に消す地点がある**ことに気づいた。
  `PasskeyConfirmationController::store()` は
  `$session->passwordConfirmed()` の**後**に `app(PasskeyConfirmationResponse::class)` を返す。
  この Response はアプリ実装に差し替える対象そのものなので、
  **同一リクエスト内の controller 実行後**という確実な地点で
  `session()->forget('auth.password_confirmed_at')` できる。
  台帳 boundary が「Response contract 上書き」をアプリ責務としているのは、
  まさにこういう継ぎ目のためである。
  Feature テストで固定する項目も指摘どおり 3 点に増やす。
  `PasswordConfirmMiddlewareAbsenceTest` は**追加防御として残す**。

## [Warning] authorizeLoginUsing closure の contract 固定 / 拒否応答の情報漏れ

- 判断: **対応する (ただし情報漏れは意図的トレードオフとして明記)**
- 根拠: closure シグネチャの vendor 契約固定は妥当。
  情報漏れについては、拒否は **WebAuthn の user verification (生体/PIN) を通過した後**に起きる。
  そこまで到達した相手は既に物理認証器と UV を保持しており、
  「このアカウントは 2FA 有効」を追加で知られる限界被害は小さい。
  一方、完全に一般化したメッセージにすると
  **正規ユーザーが理由を知れず詰む** (禁止事項 8 の精神・「行き先のない詰みを作らない」に反する)。
- 対応内容: `PasskeyPackageContractTest` に closure のシグネチャ・呼び出し時点・
  拒否時の例外型を含める。拒否メッセージは
  「このアカウントはパスキーでログインできません。パスワードまたは SSO でログインしてください」と
  **回復導線を含む具体文言**にし、そのトレードオフ (2FA 有効の事実が UV 通過者に分かる) を
  設計へ明記する。

## [Warning] config cache / route cache の検証が boot 時確認に留まる

- 判断: **一部対応する (検証可能な機構に絞って明記)**
- 根拠: 妥当だが、「キャッシュ済み設定から起動する隔離テスト」は
  Pest の `--parallel` + `RefreshDatabase` 前提のスイートで実行するには重く、
  やたらに複雑な案 (禁止事項 6) に寄る。検証すべき**機構**を特定して固定するほうが確実。
- 対応内容: 以下を設計に明記し gate 化する。
  - `Features::passkeys([...])` の副作用は `config()->all()` に現れるため
    `config:cache` の serialize に取り込まれる (既存 2FA が同一機構に依存している既知経路)。
    → gate は `config()->all()` に `fortify-options.passkeys` が含まれることを検査する
  - `Route::bind()` の closure は **route cache に serialize されない** (boot 時登録)。
    `appendMiddlewareIfMissing()` も `$app->booted()` でキャッシュ済み collection に対して走る。
    → route cache 有無で挙動が変わらない構造であることを設計根拠として記述する
    (既存の `attachRecentAuthToSensitiveRoutes()` が同一機構で稼働中)

## [Warning] 期待効果が新規ユーザーにしか成立しない

- 判断: **対応する**
- 対応内容: §3 の期待効果を「**本変更後に新規登録された** SSO ユーザー」に限定し、
  legacy ユーザーの誤表示が既知制約として残ることを明記。

## [Warning] legacy 残存リスクの記述が狭すぎる (ロックアウト以外の実害)

- 判断: **対応する (指摘が正確)**
- 根拠: 「SSO 解除 route 追加時に初めて実害化する」は**ロックアウトについてのみ**正しく、
  誤 UI / `canSatisfy` 誤判定 / inventory の誤カウントは**今すでに起きている**。
  自分の論証の射程を広く見せていた。
- 対応内容: 2 つに分けて書く。
  (a) **ロックアウトリスク**: 現スコープ (`passkey.destroy` 1 本) では発生しない
  (b) **legacy password による誤判定・誤 UI**: **未解消の既知制約として残る**
  確認 SQL も「SSO 登録者数」ではなく「**要調査候補数**」であると明記する。

## [Warning] 「TOTP 有効ユーザーは必ず password か SSO を持つ」がコード依存の事実

- 判断: **対応する**
- 対応内容: 断定のままにせず Feature テストで保証する
  (TOTP を有効化できる経路が password / SSO ログイン済みを前提とすることの固定)。

## [Warning] P1〜P6 は PR 段階ではなく worktree 内の実装段階

- 判断: **対応する (呼称と順序を修正)**
- 根拠: 正しい。AGENTS.md 検証コマンド規約は「全 green でコミット」であり、
  P3 単独を main へ投入する記述は規約と両立しない。
- 対応内容: 表題を「**T-β worktree 内の実装段階**」に変更。
  P3 の意図的 red は**コミット前の fail 確認**に格下げ。
  さらに feature 有効化の位置を見直し、
  **「有効化」と「guard 群」を同一段階に束ねる**ことで
  「guard 無しで feature が on になっている commit が歴史上存在しない」ことを保証する
  (Codex 案の「最後に有効化」より、この不変条件のほうが安全性の本質に近いと判断)。
  main へのマージ単位は T-β 全体が green になってから 1 度、と明記。

## [Warning] Google が Confirmed である前提を契約テスト / 根拠文書で固定

- 判断: **対応する**
- 対応内容: `SocialProviderTrustPolicyTest` に
  「google の `email_trust` が `confirmed` であること」と
  「全 provider が宣言を持つこと」の両方を入れる。
  Confirmed の 2 条件と Google がそれを満たす根拠は
  `docs/architecture.md` (SSO 節) に記す。

## [Suggestion] TOTP ユーザーへのログイン画面文言 / 誤認防止

- 判断: **対応する**
- 対応内容: §2 施策 3-f に、ログイン画面での passkey 提示は
  「TOTP 有効アカウントでは使えない場合がある」旨を事前に示すこと、および
  拒否時に回復導線 (パスワード / SSO) を同画面で提示することを受入条件として追加。
