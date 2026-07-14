# 対応マトリクス: design-review Round 3

## [Critical] (b) の 2 回目 actingAs が再認証してしまう（施策3 (b)）
- 判断: **対応**
- 根拠: 指摘は妥当。変更後に再度 `actingAs()` すると「現在セッションが維持された」ことを検証できない
  （新たに認証してしまう）。
- 対応内容: 変更後は `actingAs()` せず、最初の `actingAs($user)->put(...)` が確立したセッションを
  そのまま使い、`$this->get('/dashboard')->assertSuccessful()` で維持を検証する（Laravel テストは
  同一テスト内でセッションを跨いで保持する）。

## [Warning] (d) を (c) 同型にすると viaRemember=false で recaller 固有分岐を検証できない（施策3 (d)）
- 判断: **対応（実 recaller cookie の統合テストに変更）**
- 根拠: recaller からハッシュを取得・照合する viaRemember 分岐は session-hash 分岐と別の不変条件。
  Codex 指摘どおり (c) 同型では未検証。
- 対応内容: (d) を **remember 付き実ログイン → recaller cookie を暗号化生値で capture → out-of-band
  hash 変更 → session cookie 無しで recaller のみ提示 → viaRemember 経路で失効（`assertRedirect('/login')`）**
  の決定的統合テストに変更する。PHPStan L10 のため:
  - guard は `Assert::isInstanceOf($guard, \Illuminate\Auth\SessionGuard::class)` してから `getRecallerName()`。
  - cookie 名は `Assert::string`、`getCookie($name, false)` の結果は
    `Assert::isInstanceOf($cookie, \Symfony\Component\HttpFoundation\Cookie::class)` で確定してから `->getValue()`。
  - `getCookie(..., false)` で decrypt=false（暗号化生値）を取得し `withCookie` で送る（EncryptCookies が復号）。
  - session cookie を明示送信しないため recaller 経路に落ちる（Laravel テストは応答 cookie を自動再送しない）。
  - 実行環境で暗号化 cookie 取り回しが不安定な場合の **fallback**（Codex 提示）: AuthenticateSession の
    viaRemember 分岐を guard/recaller 値を制御して叩く単体テスト、または (d) を削除し DoD に
    「remember-me 失効はフレームワーク仕様依存で本 PR では未検証」と明記する。本設計は主に実 recaller
    統合テストを採用し、fallback を許容範囲として明記する。

## (a)(c)(e): Codex 追認（残課題なし）
- 変更なし。
