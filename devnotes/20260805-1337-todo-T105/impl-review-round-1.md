提供差分ベースのレビューです。コマンド実行・追加ファイル確認はしていません。

**総評**

[Critical] なし  
[Warning] なし  
[Suggestion] 2 件ありますが、施策 1 の完了を妨げるものではありません。

**app/Enums/EmailTrustLevel.php**

判定: APPROVED

設計どおりです。`mixed` な config 値を `is_string()` で narrow し、未知値・非文字列・未宣言を `Unconfirmed` に倒しており、fail-closed です。PHPStan level 10 観点でも型を緩めて黙らせる形ではありません。

**app/Services/Auth/EmailTrust/*.php**

判定: APPROVED

`EmailTrustPolicy` / `ConfirmedEmailTrustPolicy` / `UnconfirmedEmailTrustPolicy` / `EmailTrustPolicyResolver` は詳細設計と一致しています。resolver も config 未宣言時に `EmailTrustLevel::fromRaw()` 経由で `Unconfirmed` を返すため、実行時の fail-closed は閉じています。

[Suggestion] 将来 `confirmed` でも IdP の claim 内容を見る provider を入れるなら、`trustsEmail()` の provider-specific policy テストを追加するとよいです。今回の施策 1 では true/false 固定なので不要です。

**app/Services/Auth/SocialAccountService.php**

判定: APPROVED

`email_verified_at => now()` の無条件付与が、provider ごとの `EmailTrustPolicyResolver` 判定に置き換わっており、施策 1 の中心要件と一致しています。`google` は config 側で `confirmed` のため既存挙動を維持し、`unconfirmed` / 未宣言では `null` になるので nOAuth 対策の継ぎ目として機能します。

DTO / JsonResource パターンに関係する変更はありません。`response()->json()` 直書きもありません。

[Suggestion] unconfirmed SSO 登録ユーザーが `/email/verify` に落ちることは Feature test で確認されていますが、確認メール送信または再送導線の到達性まではこの差分では固定されていません。施策 1 の範囲では必須ではないものの、Microsoft 等を実際に `unconfirmed` で追加する前には回帰テスト化した方が安全です。

**config/template.php**

判定: APPROVED

`google.email_trust = confirmed` の追加、未宣言は `unconfirmed` 扱いというコメント、Microsoft は `unconfirmed` から始めるべきという注意書きは設計どおりです。`socialProviders` の Inertia prop 形状も `array_keys()` 前提なら変わりません。

**docs/architecture.md / docs/auth-security-mechanisms.md**

判定: APPROVED

詳細設計で求められていた Confirmed 判定基準、fail-closed、機械強制の説明が追加されています。`architecture.md` から認証セキュリティ機構への参照も妥当です。

**tests/Architecture/SocialProviderTrustPolicyTest.php**

判定: APPROVED

施策 1 の不変条件を Architecture test として機械強制できています。

特に良い点:
- 全 provider の `capability` / `email_trust` 明示宣言を要求している
- `google` の `confirmed` を pin して既存挙動を固定している
- `fromRaw(null / nonsense / array / bool / int / empty)` の fail-closed を検証している
- 未知 provider が `UnconfirmedEmailTrustPolicy` になることを検証している

これは実装をなぞるだけではなく、config 宣言漏れ・誤値・google pin 変更を検出できます。

**tests/Feature/Auth/SocialAuthTest.php**

判定: APPROVED

Feature test は実際の SSO register 経路を通して、以下を確認できています。

- google confirmed では従来どおり `email_verified_at` が立つ
- dashboard の verified gate を通過できる
- `email_trust=unconfirmed` では `email_verified_at` が null
- verified gate により `/email/verify` へ落ちる
- 未宣言相当でも fail-closed で null になる

施策 1 の回帰テストとして十分です。`SocialAccount` 作成確認は confirmed 側の既存テストで維持されているため、今回の差分では不足とは見ません。

**セキュリティ評価**

施策 1 の範囲では nOAuth 防御として機能しています。未検証 email claim を信頼できない provider は、少なくとも `email_verified_at` を立てられず、verified middleware 配下の既存機能へは進めません。google の既存挙動も pin されています。

ただし、ログイン手段 inventory / phantom password / passkey / recent-auth 配線は指定どおり施策 2 以降の領域なので、本レビューではブロッカー扱いしません。

**全体判定: APPROVED**