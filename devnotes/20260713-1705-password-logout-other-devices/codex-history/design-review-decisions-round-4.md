# 対応マトリクス: design-review Round 4

## [Critical] `getCookie(...,false)`(暗号化値) + `withCookie` は二重暗号化（施策3 (d)）
- 判断: **対応**
- 根拠: 指摘に従い、暗号化レイヤの取り回しを内部整合の取れた組合せに統一する。
- 対応内容: **復号済み平文** recaller（`$login->getCookie($recallerName)` = decrypt 既定 true）を
  **`withUnencryptedCookie($recallerName, $value)`**（EncryptCookies の復号をスキップ）で送る。平文値 +
  skip-decrypt の組合せで guard が `id|token|hash` を正しく受け取る（二重暗号化を回避）。

## [Critical] ログイン直後の guard/session が残り recaller 経路に落ちない（施策3 (d)）
- 判断: **対応**
- 根拠: 既存セッション/解決済み guard が残ると次リクエストが session で認証され viaRemember=false になる。
- 対応内容: recaller のみのリクエスト前に **`$this->flushSession()` + `Auth::forgetGuards()`** を実行し、
  既存認証状態を消してから recaller だけで認証を成立させる。

## [Warning] 「不安定なら(d)削除」は不変条件の未検証化（施策3 (d) fallback）
- 判断: **対応**
- 根拠: テスト必須方針（AGENTS.md 禁止事項#1）と整合しない。
- 対応内容: fallback を「削除」ではなく **`AuthenticateSession` の viaRemember 分岐を制御する単体テストを
  必須 fallback**とする（recaller 値と guard の viaRemember を制御し logout を確認）。削除オプションは撤回。

## 補足（PHPStan スコープ）
- `phpstan.neon` の解析対象は `app/config/database/routes` のみ（`tests` は対象外）。よって (d) の
  `Auth::forgetGuards()` / `withUnencryptedCookie` / `Assert::*` は PHPStan 解析対象外。Assert ガードは
  ランタイム null 安全・可読性のため残す（`Auth::forgetGuards()` は Auth ファサード `@method` 定義済、
  `withUnencryptedCookie` は MakesHttpRequests に実在）。

## (b): Codex 追認（残課題なし）
