全体判定: **CHANGES_REQUESTED**

設計の方向性は概ね妥当です。特に `canSatisfy` と `EnsureLoginMethodRemains` を別概念として分離する判断、Fortify 1.37 の passkey 機能を使い app 側 Provider をアダプタに留める判断は、AGENTS.md の「フレームワークのレンジ内」「別概念を統合しない」に沿っています。

ただし、現状のまま実装に進むには重大な未解決リスクがあります。

**使命との整合性**
- [Suggestion] passkey 導入は North Star に貢献します。現場 PWA でパスワード入力を減らすことは「思考ゼロ」に直結します。
- [Warning] ただし passkey を有効化するだけで「現場作業者の摩擦が下がる」とは限りません。対応端末・共有端末・端末紛失・OS 生体認証未設定時の代替導線まで設計に含めないと、現場停止リスクが残ります。  
  修正提案: 対応ブラウザ/端末条件と、passkey 未対応時の password/SSO フォールバック表示条件を T-β の受入条件に入れてください。

**禁止事項・セキュリティ不変条件**
- [Critical] passkey login が Fortify の TOTP チャレンジを通らない点の扱いが不十分です。設計は「組織 2FA 強制は global middleware なので効く」としていますが、Fortify の通常 2FA を有効化したユーザーが、非強制組織で passkey login により TOTP を迂回できる可能性があります。これは既存の 2FA 期待を破る重大な後退です。  
  修正提案: 「passkey は 2FA の代替なのか」「既存 Fortify 2FA ユーザーにも TOTP を要求するのか」を明文化し、Feature テストを追加してください。少なくとも `two_factor_secret` を持つユーザーの passkey login 後の到達可否を固定すべきです。

- [Critical] `LoginMethodInventory` が passkey を「passkeys 行が 1 件以上」で数える設計は、`Features::passkeys()` をキルスイッチにする説明と矛盾します。feature を外して route が消えた状態でも passkey をログイン手段として数えると、実際にはログイン不能な手段を残存手段扱いしてしまいます。  
  修正提案: inventory は「データがある」ではなく「現在ログイン画面から使える」を基準にし、passkey は Fortify feature 有効時のみ数えてください。social も provider が現在ログイン可能に設定されているかを考慮すべきです。

- [Warning] passkey destroy の 403 情報漏えいに対する binder 差し替え方針は正しいです。ただし `Route::bind('passkey', ...)` だけでは route cache 後・Fortify route 登録後・provider boot 順序で上書き順が安定するかを設計で固定できていません。  
  修正提案: `PasskeyPackageContractTest` に「他人の passkey id は destroy で 404」「存在しない id も 404」「route cache 後も同じ」を入れてください。

**実現可能性**
- [Warning] 「passkey route の auto-discovery 露出なし」という結論は、現在の `route:list` 実測としては妥当ですが、不変条件としての導出はまだ弱いです。config cache / route cache / package discovery manifest / provider boot 順差の観点が設計上の受入条件に落ちていません。  
  修正提案: uncached の `route:list` だけでなく、`config:cache` + `route:cache` 相当で route 集合を検査する Architecture/Feature テストを入れてください。少なくとも「Fortify feature 無効時に package 側 passkey route が 0 本」「Fortify feature 有効時は Fortify 側 route のみ」を固定する必要があります。

- [Suggestion] `dont-discover` を追加しない判断は妥当です。Fortify native passkey が `laravel/passkeys` の provider binding / route binding / response contract に依存するため、discovery を切るのは壊す方向です。

**期待効果の妥当性**
- [Critical] SSO 登録ユーザーの `password = null` 化は新規ユーザーには効きますが、既存 SSO ユーザーのランダム password 問題を解決しません。既存レコードでは `hasPassword()` が引き続き true になり、recent-auth UI と `EnsureLoginMethodRemains` が形骸化します。  
  修正提案: 既存データの扱いを設計に追加してください。安全に判別できないなら一括 null 化は危険なので、少なくとも「既存 SSO 作成ランダム password をどう識別するか」「識別不能な場合の移行方針」「移行しない場合に残る既知制限」を明記してください。

- [Warning] `ConfirmedEmailTrustPolicy` が単に `true` を返すなら、名前が強すぎます。Google を `confirmed` とするなら、何を根拠に confirmed とみなすのかが必要です。  
  修正提案: `provider_email_trust = trusted_provider` のように意味を弱めるか、実際に `email_verified` claim を確認する policy にしてください。

**リスク**
- [Critical] passkey を recent-auth satisfier に追加する場合、passkey login と step-up の境界が曖昧です。ログイン直後に `StampRecentAuthOnLogin` が recent-auth を打つ既存仕様があるため、passkey login 後にどの method として recent-auth が記録されるか、`PasskeyVerified` との二重記録が起きないかを固定する必要があります。  
  修正提案: `RecentAuthState::confirm()` 呼び出し元 inventory だけでなく、login/password/sso/passkey それぞれの session state 期待値を Feature テストで固定してください。

- [Warning] Response contract 上書きで DTO/JsonResource を使う方針はよいですが、WebAuthn ceremony endpoint はブラウザ API が厳密な JSON 形を期待します。Inertia response と JSON resource の境界を誤るとフロントが壊れます。  
  修正提案: options / register / login / delete の各 endpoint について、HTML/Inertia と JSON のどちらを返すかを表にして固定してください。

**スコープの適切さ**
- [Warning] T-β は大きすぎます。password null 化、login inventory、middleware、Fortify passkey 有効化、vendor adapter、recent-auth、2FA 回帰、フロントまで含んでおり、ロールバック単位として重いです。一方で施策 1 と 3 を完全分離しない理由は成立しています。  
  修正提案: T-β 内部を PR 単位で段階化し、feature flag 無効状態で先に入れられるものと、有効化コミットを分けてください。最終 TODO は同じでも構いません。

**型安全性**
- [Warning] `App\Models\Passkey` を vendor model のサブクラスにする方針は可能ですが、vendor 側の relation / contract / generic 期待に乗れるかを明示していません。PHPStan level 10 では route binder closure、Auth user 型、`passkeys()` relation の戻り型が詰まりやすいです。  
  修正提案: `User` が実装する `PasskeyUser` contract、trait、`passkeys()` relation の戻り型、`App\Models\Passkey` factory の型を設計に追記してください。

**TODO 分割判断**
- [Warning] T-α 独立 / T-β 統合という大枠は妥当です。案 X/Y/Z の却下理由も概ね成立しています。ただし T-β の「キルスイッチ」は `LoginMethodInventory` が feature state を見る設計に直さない限り成立しません。  
  修正提案: T-β のロールバック説明を「feature off 時は passkey をログイン手段として数えない」前提に修正してください。

最小修正ラインは、1. 既存 SSO ランダム password の移行方針、2. passkey login と既存 2FA の関係、3. route/config cache を含む passkey route 露出 gate、4. feature off 時の `LoginMethodInventory` の定義、の 4 点です。ここが埋まれば APPROVED に近いです。