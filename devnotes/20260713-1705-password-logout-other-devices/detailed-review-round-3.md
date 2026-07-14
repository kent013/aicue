## 施策3: REQUEST_CHANGES

- [Critical] (b) の2回目の `actingAs($user)` が再認証するため、「現在セッションが維持された」ことを検証できません。  
  修正案: パスワード変更後は再度 `actingAs()` せず、直接 `$this->get('/dashboard')->assertSuccessful()` としてください。
- [Warning] (d) を(c)と同型にすると `viaRemember()` は `false` のままで、recaller固有分岐を検証できません。同じ比較primitiveでも、recallerからハッシュを正しく取得・照合する経路は別の不変条件です。  
  修正案: (d) は実recaller cookieによる統合テスト、またはguardの `viaRemember()` とrecaller値を制御した `AuthenticateSession` の単体テストにしてください。実施しない場合は(d)を削除し、「remember-me失効はフレームワーク仕様への依存で未検証」とDoDへ明記する必要があります。

(c) の `withSession(['password_hash_web' => $oldHash])` による通常セッション失効テストは決定的で妥当です。(a)(e)にも残課題はありません。

## 全体判定: CHANGES_REQUESTED

(b)から再認証を除き、(d)で実際の`viaRemember`分岐を検証できれば承認可能です。