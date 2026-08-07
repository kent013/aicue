# 対応マトリクス: conceptual-review Round 1

## [Critical] 「後退リスクは構造的にゼロ」は言い切り過ぎ
- 判断: **対応する**
- 根拠: 指摘のとおり。単調緩和が成立するのは「巻き添え 429 の経路」という 1 軸に限られる。
  同一 actor が 1 分間に実行できる状態変更操作の合算量とログ量は実際に増える。
  AGENTS.md 思考原則「データに真摯に向き合え」に照らして、成立する範囲を限定して書くのが正しい。
- 対応内容: §改善アイデア 2 を書き直した。主張を 2 つに分離し
  (a) 巻き添え 429 は単調緩和 / (b) コスト軸ごとの天井は個別に確認、
  コスト軸 6 行の表 (パスワード照合 / TOTP / 招待 token / 外向きメール / 状態変更 / ログ量) を追加。
  「増える」2 軸を明示し、受け入れるトレードオフとして書いた。§期待効果の文言も合わせて修正。

## [Warning] `settings.password.store` を `password-credential` に同居させる根拠が弱い
- 判断: **対応する**
- 根拠: `PasswordSetupController::store()` / `SetPasswordRequest` を実読して確認した結果、
  current_password の照合は**無い** (パスワード未設定ユーザー専用経路で、防御は
  `recent-auth` middleware と `PasswordCredentialService` の fail-closed 拒否)。
  Codex の言うとおり「秘密の推測試行」ではなく credential mutation であり、数える対象が違う。
  同居させると「パスワード設定に 6 回失敗 → step-up 再認証が 429」という、
  本 TODO が潰したい巻き添えが 1 本だけ残る。
- 対応内容: レーンを分割。`password-verify` (6/min: recent-auth.password /
  password.confirm.store / user-password.update) と `password-set` (6/min:
  settings.password.store)。新レーン数は 5 → 6。分割根拠を設計書に明記。

## [Warning] `verification.send` / `verification.verify` の同居は暫定判断として明記すべき
- 判断: **対応する** (方針は維持し、位置づけを明記)
- 根拠: Fortify は `config('fortify.limiters.verification')` の 1 knob で 2 本に貼るため、
  第 2 段で貼る限り構造的に同レーンになる。第 2 段で貼れるものを第 3 段へ落とさないのが既存規約。
  ただし概念的に別物であることは Codex の指摘どおり。
- 対応内容: 「Fortify 制約を優先した暫定判断」と明記し、**将来分離する条件を 2 つ**
  ((a) リンク先読みによる `verify` 消費で `send` が 429 になる事象の観測、
  (b) Fortify が 2 knob を持つようになったとき) と、分離時の方針
  (verify のみ第 3 段で別レーンへ / send の 6/min は維持) を書いた。

## [Warning] inline 残置の enum は「bucket signature の性質」で分類すべき
- 判断: **対応する**
- 根拠: route 単位の分類は実装都合の例外集になるという指摘は正しい。
  `livewire.upload-file` は認証状態でキー種別が変わるため、route 単位では表現できない。
- 対応内容: enum を `VendorStatelessIpBucket` (cap 2) と
  `VendorMixedUserOrIpBucket` (cap 1) の 2 case にした。
  Codex 案の `VendorAuthenticatedUserBucket` は**現に該当する route が 0 本**のため作らない
  (AGENTS.md 思考原則 2「今必要なものだけ作る」)。
  自前 route 向けの case を 1 つも定義しないことで、自前 inline は
  「当てはまる case が無い = 目録に登録できない」= deny-by-default で必ず fail する。

## [Warning] Passport / Livewire の IP bucket 共有を「詰みを作らない」で済ませるのは粗い
- 判断: **対応する**
- 根拠: OAuth token endpoint の 429 は MCP / API クライアントのトークン更新失敗に直結する。
  「詰みではない」は正しいが「影響が無い」ではない。
- 対応内容: §改善アイデア 4 を書き直し、**残存リスクとして明示**した
  (影響は同一 IP に閉じる / 本 TODO の主障害とは別問題 / named 化には
  Passport が throttle をハードコードしている構造的障壁がある)。
  §スコープ外にも 1 項目として追加し、unresolved にも残す。

## [Warning] `InlineThrottleInventoryTest` と既存 gate の責務境界を明文化すべき
- 判断: **対応する**
- 根拠: 重複検査は保守負荷を上げるだけ。本リポジトリは gate が多いため境界の明文化が要る。
- 対応内容: §改善アイデア 5 として gate 責務表 (4 行) を追加。
  既存 `ThrottleCoverageInventoryTest` の母集団・exemption 台帳は本数が変わらないため
  無変更で通ることも明記した。

## [Warning] limiter closure の PHPStan (戻り値 / user null / ip nullable)
- 判断: **対応する**
- 根拠: 6 レーン分の closure に同じ null 分岐をベタ書きすると差異が入り込む。
- 対応内容: `app/Support/Http/RateLimiterKeys.php` を新設し
  `{レーン}:user:{id}` / `{レーン}:ip:{ip}` の組み立てを一点に集約する施策を追加。
  既存の `passkeys` / `two-factor-secret-read` も同 helper へ寄せる
  (AGENTS.md 思考原則 3「後方互換の並走を残さない」= 2 つの書き方を並存させない。
   キー文字列は不変で、既存の exact-fit 検査がそれを保証する)。

## [Warning] 実装時に vendor route の応答や `response()->json()` を触らない旨を明記すべき
- 判断: **対応する**
- 根拠: 主戦場を明示しておくと実装フェーズでの逸脱を防げる。
- 対応内容: §実装方針の冒頭に「変更の範囲は throttle middleware の指定と
  RateLimiter 登録に閉じる。controller 応答・vendor route の middleware stack は触らない」を明記。
  §スコープ外にも追加。

## [Suggestion] 使命への接続は「撮影機能の改善」ではなく「到達不能な認証導線の除去」と書くべき
- 判断: **対応する**
- 対応内容: §期待効果の 1 項目目を書き直した。

## [Suggestion] route ごとに「保護資産 / 秘密 / 外部コスト / 状態変更」の表を足す
- 判断: **対応する**
- 対応内容: §改善アイデア 1 の冒頭に 10 行の性質表を追加。
  これが `password-verify` / `password-set` 分割と `two-factor-manage` 統合の根拠になっている。

## [Suggestion] `api-*` の共有は別 TODO で正しい
- 判断: **対応不要** (現行方針を維持)
- 対応内容: なし。共有グループ目録への明示登録と後続 TODO 化はそのまま。
