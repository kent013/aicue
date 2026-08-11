# 対応マトリクス: conceptual-review Round 1

## [Critical] local で fake を許すと本番 SSO の回帰を見逃す / ログイン導線そのものの差し替えは影響が大きい
- 判断: **対応する**
- 根拠: 指摘が正しい。SSO fake は Stripe / captcha fake と性質が違い、**未認証 GET 2 本で
  任意の canned アカウントへログインできる = 認証バイパス**である。加えて `local` は開発者が
  実 IdP 連携を確認する唯一の環境であり、そこで無言に fake が立つと回帰を見逃す。
  なお `ExternalFakeBinding` は **entry ごとに `allowedEnvironments` を宣言できる**構造で、
  storage fake は既に `['testing', 'bughunt.local']` という別 allowlist を使っている
  (= 独自形ではなく既存形の再利用)。
- 対応内容: SSO fake の env allowlist を **`['testing', 'bughunt.local']`** に限定する
  (`local` を除外)。provider には SSO 専用の早期 return を置き、**warning ログは出さない**
  (LLM fake と同じく「誤設定ではなく設計上の除外」。既存の 3-4 テストが
  `Log::warning` の呼び出し回数を `once()` で固定しているため、ここで warning を足すと
  既存テストを壊す = 波及 0 行の前提が崩れる)。
  併せて fake が返す identity を **一目で fake と分かる値**にする
  (`fake-{provider}-user` / `fake-{provider}-sso@example.com` / `SSO Fake User ({provider})`)。

## [Warning] OAuth state / session の段差を飛ばす可能性。round-trip まで通すテストにせよ
- 判断: **対応する** (ただし state については「そもそも依存が無い」ことを明示する)
- 根拠: 実コードを確認した。`SocialAuthController` が session に置くのは
  `social_auth_intent` **だけ**で、OAuth の `state` は Socialite 内部 (`AbstractProvider`) の
  責務であり controller/service は一切参照しない。したがって fake が state を持たなくても
  **アプリ層の契約は 1 つも飛ばさない**。とはいえ「飛ばしていない」ことを主張するなら
  実走で示すべきという指摘は正しい。
- 対応内容: 検証を「redirect 先 host」だけにせず、**`social.redirect` → `social.callback` の
  full round-trip** (register / login / link / step-up の 4 intent) を Feature テストで通す。
  allowlist に `testing` を残したことでこれが HTTP レベルで素直に書ける。
  state に依存しないことは設計文へ明記する。

## [Warning] fake user の属性契約が未定義 (PHPStan level 10 / 読まれるフィールド)
- 判断: **対応する**
- 根拠: 妥当。実コードで読者を洗い出した結果、アプリが読むのは
  `getId()` (`SocialAccountService::findLinkedUser` / `link` / `completeStepUp`)、
  `getEmail()` / `getName()` (`SocialAccountService::register`) の 3 つだけ。
  `EmailTrustPolicy` (Confirmed / Unconfirmed) は socialite user から値を読まない。
- 対応内容: fake user は vendor の `Laravel\Socialite\Two\User` を `map()` で組み立て
  (自前の `Contracts\User` 実装を書かない = 先人の知恵)、id / nickname / name / email / avatar を
  すべて決定論的に埋める。決定規則を概念設計に明記する。

## [Warning] resolver 以外に Socialite::driver() が残らない保証が弱い
- 判断: **対応する** (既に機械化済みであることを明記する)
- 根拠: `ExternalSeamInventoryTest` の「SocialLogin は 1 クラスに固定される」テストは
  走査結果集合と `socialLoginFunnel()` の**完全一致** (`toBe`) を要求しており、
  resolver 以外に `Socialite` facade 参照が 1 つでも残れば赤くなる。新 gate は不要で、
  名指し先を retarget するだけ。
- 対応内容: 概念設計に「この不変条件は既存 gate の retarget で機械化される」と明記する。

## [Warning] concrete + 継承で差し替える形の脆さ
- 判断: **対応する (制約として明記)**。形そのものは変えない
- 根拠: `RecaptchaVerifier` → `RecaptchaVerifierTestFake` が**まったく同じ形** (具象 real +
  サブクラス fake) で既に本番稼働しており、`ExternalFakeWiringInvariantTest` は
  `instanceof` ではなく**厳密クラス一致**で判定するため継承でも対照が壊れない。
  ここで interface を新設すると「既存 fake 宣言と同じ形に揃える」という本 TODO の
  前提から外れる。
- 対応内容: 「resolver は `driver()` 1 メソッドのみ・`final` にしない・fake は `driver()` 以外を
  持たない・resolver に責務を足さない」を制約として設計へ明記する。

## [Suggestion] 位置づけは「探索基盤の信頼性改善」
- 判断: 対応する (文言調整)
- 対応内容: 期待効果の見出しを「使命への貢献 (間接 / 探索基盤の信頼性)」と明示する。
