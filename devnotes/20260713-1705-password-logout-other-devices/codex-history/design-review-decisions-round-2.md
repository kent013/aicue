# 対応マトリクス: design-review Round 2

## [Critical] `$this->flush()` は非実在 API / flush が device B の server session を破棄する（施策3 (c)）
- 判断: **対応（cookie replay を廃し withSession による決定的テストへ）**
- 根拠: 指摘は妥当。`$this->flush()` は Laravel HTTP テストクライアントに存在しない。cookie replay +
  session flush の取り回しは不安定。
- 対応内容: (c) を **`withSession(['password_hash_web' => $oldHash])`** による決定的統合テストへ変更。
  cookie を一切使わず、driver 非依存で `AuthenticateSession` の password_hash 照合を直接検証する:
  1. `$user = User::factory()->create();` の現在ハッシュ `$oldHash = $user->getAuthPassword();` を退避。
  2. `$user->forceFill(['password' => Hash::make('NewPassword12345')])->save();` で in-memory $user を
     新ハッシュに更新（actingAs が使う guard user が新ハッシュを持つようにする）。
  3. `$this->actingAs($user)->withSession(['password_hash_web' => $oldHash])->get('/dashboard')`
     → 現在(new) vs 保存(old) 不一致で AuthenticateSession が logout → `assertRedirect('/login')`。
  これは「旧 password_hash_web を持つ既存/復活セッションが次リクエストで失効する」= 層1 correctness の
  直接証明で、cookie 暗号化に一切依存しない。

## [Critical/Warning] (d) recaller 経路の安定化（施策3 (d)）
- 判断: 対応（(c) 同型の決定的テストを主とし、recaller 固有経路は Assert ガード付きで補助）
- 根拠: recaller の viaRemember 分岐も `validatePasswordHash(currentHash, ...)` という (c) と同一 primitive を
  使うため、(c) がこの照合ロジックの有効性を固定する。recaller 固有の暗号化 cookie 取り回しは不安定。
- 対応内容: (d) 主テストは (c) と同じ `withSession` 決定的方式で「remember 由来セッションの旧ハッシュ失効」を
  検証（recaller の password_hash も同 primitive で照合されるため同値の保証）。recaller cookie を実際に
  経由する追加検証を行う場合は、下記 Warning の Assert ガードを必須にする。

## [Warning] `config('session.cookie')` は mixed → Assert::string（施策3）
- 判断: 対応
- 対応内容: cookie を扱う場合は `Assert::string($sessionCookieName)` を追加。ただし主方式 (withSession) では
  cookie 名を使わないため不要になる。

## [Warning] `getCookie()` は nullable → Assert::notNull / isInstanceOf（施策3）
- 判断: 対応
- 対応内容: cookie を扱う補助検証を残す場合は `Assert::isInstanceOf($cookie, \Symfony\Component\HttpFoundation\Cookie::class)`
  で確定してから `->getValue()`。主方式 (withSession) では不要。

## 施策1 / 施策2: APPROVE（Codex 追認）
- 変更なし。
