# 対応マトリクス: design-review Round 2

Codex 判定: 施策 A **APPROVE** / 施策 B **REQUEST_CHANGES** / 全体 **CHANGES_REQUESTED**。
反論した 2 点（A の architecture テスト不採用 / B の AST 不採用）は**いずれも妥当と判定**された。

## [Warning] B-2 は「ログアウト後に本当にパスワードを設定できる」ことを固定できていない
- 判断: **対応する**
- 根拠: 指摘のとおり。追加していた Feature テストは「認証中に `/forgot-password` へ到達できない」
  ことしか示しておらず、**案内した回復手順の終端**（実際にパスワードを得て再認証可能になる）は
  未固定だった。案内だけあって実行できない導線は本 TODO が排除しようとしている species そのもの。
- 対応内容: `tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php`（新規）を追加し、
  SSO 専用ユーザー（`User::factory()->ssoOnly()`、social account なし = `canSatisfy=false`）が
  ログアウト → `POST /forgot-password` → 通知の token で `POST /reset-password` →
  `/recent-auth/confirm` の props が `passwordSet=true` / `canSatisfy=true` になるところまでを固定する。
  email は CipherSweet 暗号化だが `App\Auth\EncryptedUserProvider` が `whereBlind` 経由で
  解決する（平文 where に依存しない）ことを裏取り済み。HIBP 照会は `Http::fake` で止める。
  併せて `RecentAuthTest.php` に「SSO 専用ユーザーは `canSatisfy=false`」の 1 本を追記した。

## [Warning] CTA の文言と実際の着地が一致していない（押しても `/` に着地するだけ）
- 判断: **対応する（文言側を実挙動に合わせる）**
- 根拠: 「ログアウトしてパスワードを設定する」は 1 アクションで完了する印象を与えるが、
  実際に起きるのはログアウトのみ。**ラベルと着地の不一致は F-2-01 と同じ不誠実さ**である。
  一方で `router.post('/logout')` の成功後に `/forgot-password` へ二段遷移させる案は、
  Fortify の logout 応答契約の外側にクライアント固有の遷移規則を発明することになるため採らない。
- 対応内容: CTA を「**ログアウトする**」に変更し、手順（ログアウト → ログイン画面の
  「パスワードをお忘れの方」）は本文の説明が担う形にした。
  Codex が条件として挙げた「`/` にログイン導線が常時存在すること」は、
  既存テスト `tests/js/pages/Welcome.test.ts:120`（guest nav の `ログイン` role=link）が
  既に固定しているため、**その契約に依存する旨を設計へ明記**した（テストの二重化はしない）。

## [Suggestion] `AUTH_EXIT_ALLOWLIST` の path 重複も検出する
- 判断: **対応する**
- 対応内容: reason 必須の it に `AUTH_EXIT_ALLOWLIST_PATHS.size === AUTH_EXIT_ALLOWLIST.length` を追加。

## 施策 A への指摘（表現の精度）
- 「ラベル非依存」は厳密には不正確、という指摘を反映し、
  「**禁止したい CTA 側の testId・ラベルには依存しない**（依存するのは許可された 2 ボタンのラベルのみ）」
  と記述を修正した。
