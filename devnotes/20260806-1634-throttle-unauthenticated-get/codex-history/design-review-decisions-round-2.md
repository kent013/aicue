# 対応マトリクス: design-review Round 2

## [Critical] 施策 6: Filament MFA exemption の前提が機械検証されていない

- 判断: **対応する** (前提を機械化する側を採った)
- 根拠: 指摘が正しい。実査で「今は生成しない」と分かっても、vendor が `mount()` 側へ
  移した瞬間に **GET が秘密生成 endpoint に変わり、inventory は無音で通り続ける**。
  これは本件の最重要条件 (機械が検出できない状態を作らない) に反する。
- 検討した代替: exemption をやめて `two-factor-secret-read` を貼る案。
  だが Filament panel の面と Fortify の 2FA 秘密読み取りレーンを 1 bucket に混ぜることになり、
  レーン設計としては劣化する (施策 4 で「レーンを混ぜない」ことの重要性を示したばかり)。
- 対応内容: **9-7 を新設**。既存の
  `tests/Feature/Filament/AdminMfaBypassPreventionTest.php` が
  `AdminUser::factory()` + `actingAs($admin, 'admin')` で panel 内 URL を叩けることを確認したため、
  premise テストは低コストで書ける。検証内容は
  (a) GET が DB 書込を 1 件も発行しない、
  (b) `app_authentication_secret` / `app_authentication_recovery_codes` が null のまま。
  実装スニペットと `SESSION_DRIVER=array` 依存の注記も設計に入れた。

## [Warning] 施策 9: 「状態が自セッションに閉じる」前提が未検証

- 判断: **対応する** (Codex 提示の 2 案のうち、より安い機械化を採用)
- 根拠: 2 セッションを跨ぐ behavioral テストは、controller 側の intent 検証で先に短絡するため
  「state 検証が効いた」ことを分離して示せず、**空振り green** になりやすい。
  一方この前提は Socialite `hasInvalidState()` (session 由来の `state` を `hash_equals`) と
  Laravel の session 分離が保証する vendor 側の性質であり、
  **アプリがこれを壊しうる現実的な経路は `->stateless()` の付与ただ一つ**。
- 対応内容: **9-6 を新設**し、`SocialAuthController` のソースに `stateless(` が現れないことを
  deny-by-default で固定した (同ファイル内の `debug.login-as` 登録条件のソース走査と同じ流儀)。
  「なぜソース走査なのか」の根拠も設計に明記した。

## [Warning] 施策 9: `recent-auth.confirm` がまだ「実装時に確認」のまま

- 判断: **対応する** (実査で確定させた)
- 根拠: 「exemption 理由と実装の一致」がレビュー重点である以上、1 件だけ未確定は不整合。
- 対応内容: `ConfirmRecentAuthController::show()` / `buildStatus()` を実査。
  `hasPassword()` / `socialAccounts()->pluck()` / `passkeys()->exists()` の **read のみ**で、
  鮮度は session から読む。**DB 書込なし**を確定し「実査で確定させた事項」の表へ移した。
  「実装時に確認すること」は施策 1 の fail 件数の確認 1 項目だけになった。

## [Warning] 施策 10・段階分けの件数が計画と不一致

- 判断: **対応する**
- 対応内容:
  - 段階分けを「named limiter 3 本 / throttle 5 本 / exemption 14 件 (新 case 2) /
    inventory 検査 3 本追加 / 前提テスト 7 本 / behavioral proof 8 本 / docs」に更新
  - 検証コマンド表の `RateLimiterKeyConventionTest` 期待結果に
    `two-factor-secret-read:user:` / `two-factor-secret-read:ip:` を追加
