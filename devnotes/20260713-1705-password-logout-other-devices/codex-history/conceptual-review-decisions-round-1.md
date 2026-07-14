# 対応マトリクス: conceptual-review Round 1

## [Critical] logoutOtherDevices + DB行削除だけでは remember-me 再侵入を防げない (観点3)
- 判断: **対応する（設計を修正）**
- 根拠: Laravel source を確認し Codex の指摘が正しいことを検証した。
  `SessionGuard::userFromRecaller()` → `EloquentUserProvider::retrieveByToken($id, $token)` は
  **remember_token のみを hash_equals で照合**し、recaller Cookie の第3セグメント
  (password_hash) は照合しない。password_hash の検証は `AuthenticateSession` ミドルウェアの
  `viaRemember()` 分岐でのみ行われる。したがって AuthenticateSession 未配線・remember_token
  非 rotation のままだと、攻撃者の recaller (`id|oldToken|oldHash`) は
  `retrieveByToken(id, oldToken)` で通り再ログインできてしまう。
- 対応内容: **remember_token を rotate する**設計に変更。
  1. 新パスワードハッシュ保存。
  2. `$user->setRememberToken(Str::random(60))` + save で remember_token を rotate
     → 他デバイスの recaller は `retrieveByToken` で token 不一致となり**確実に失効**。
  3. `Auth::logoutOtherDevices($input['password'])` を呼ぶ。これは
     `queueRecallerCookie()` が **rotate 後の新 remember_token** を読んで現在デバイスの
     recaller Cookie を再発行するため、**現在デバイスの remember-me のみ生存**する
     (現在リクエストが recaller を持つ場合のみ再発行。session-only の現在ログインには無影響)。
     加えて `OtherDeviceLogout` イベントも発火。
  4. DB セッション行の削除（database driver 時、現在セッションID除外）。
  この結果、AuthenticateSession をグローバル配線しなくても **セッション行削除（session 経路）**
  と **remember_token rotate（remember-me 経路）** の両輪で全経路を閉じられる。
  「AuthenticateSession をグローバル配線しない」判断は、remember-me 経路を rotate で閉じたこと
  で妥当化される（過小スコープではなくなる）。

## [Critical] 期待効果「remember-me は password_hash 不一致で失効」は現設計で不成立 (観点4)
- 判断: **対応する（期待効果を書き換え）**
- 根拠: 上と同根。password_hash 経路ではなく remember_token rotate 経路で失効させる。
- 対応内容: 期待効果を「remember_token rotate により他デバイスの recaller が失効。現在デバイスは
  rotate 後トークンで recaller 再発行され生存」と正確に記述し直す。

## [Warning] logoutOtherDevices に渡すのは新 password である旨を明記 (観点3)
- 判断: 対応する
- 対応内容: 実装方針に「渡すのは `current_password` ではなく保存直後の新 `password`
  (`$input['password']`)」と明記。

## [Warning] 失敗時整合性（トランザクション境界）(観点3)
- 判断: 対応する
- 対応内容: パスワード保存・remember_token rotate・セッション行削除を **`DB::transaction()`
  で囲む**方針を追加。DELETE 失敗時はリクエスト全体を失敗させ「パスワードだけ変わって他
  セッションが残る」状態を防ぐ。recaller Cookie の queue と OtherDeviceLogout イベントは
  logoutOtherDevices の副作用として transaction 内で発生するが、cookie は response 時反映・
  event は listener 側の冪等性前提で許容（audit ログ用途）と明記。

## [Warning] AuthenticateSession を「global か なしか」の二択にしている (観点5)
- 判断: **一部対応（判断軸を明確化）**
- 根拠: remember_token rotate で remember-me を閉じたため、他セッション失効の実効性はミドルウェア
  無しで担保される。中間案（認証済み Web ルート群へ auth.session 適用）は有効な代替だが、
  今回の finding（パスワード変更時の失効）に対しては rotate + 行削除で必要十分であり、全認証
  リクエストにミドルウェアを足すのはオーバーエンジニアリング（AGENTS.md 思考原則#2）。
- 対応内容: スコープ外セクションで「auth.session の認証ルート群適用は将来的な多層防御として
  有効だが、本 finding の解消には不要」と判断軸を明記。

## [Warning] 他のパスワード変更経路（reset / SSO）でも同不変条件が破られる (観点5)
- 判断: **対応する（不変条件を明文化し follow-up を記録）**
- 根拠: `app/Actions/Fortify/ResetUserPassword.php` を確認したところ、リセット経路も同様に
  他セッション失効を行っていない。ただしリセット時はユーザーが未ログイン（保持すべき現在
  セッションが無い）ため、`logoutOtherDevices`（現在維持）ではなく「全セッション破棄 + token
  rotate」という別設計になり、フローが異なる。「似ているから」で今回統合するのは AGENTS.md
  思考原則#4 に反する。
- 対応内容: スコープ外に「パスワードを変える全経路で他デバイス失効を保証する」を不変条件として
  明記し、reset 経路は**別 TODO として follow-up 起票を推奨**と記載（本スキルは TODO 起票しない）。

## [Suggestion] session テーブル名/ID を直書きせず設定/フレームワークAPIから取る (観点7)
- 判断: 対応する
- 対応内容: `config('session.table', 'sessions')` と `session()->getId()` を使い、リテラル直書きを
  避ける旨を実装方針に明記。
