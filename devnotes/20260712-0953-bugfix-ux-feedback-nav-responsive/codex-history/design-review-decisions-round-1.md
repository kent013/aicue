# 対応マトリクス: design-review Round 1

Codex (gpt-5.3-codex/high) Round 1 判定: CHANGES_REQUESTED（A1: REQUEST_CHANGES / A2: APPROVE / B: REQUEST_CHANGES / C: APPROVE）

## [Critical] A1: verification-notification の Feature テスト前提 (auth/throttle 条件) の固定不足
- 判断: 対応する
- 根拠: `verification.send` ルートは `auth:web` + `throttle:6,1`（fortify.limiters.verification 既定）で、テスト条件を固定しないと不安定化しうる。
- 対応内容: A1 テスト計画を具体化。`actingAs(User::factory()->unverified()->create())` の **1 リクエストのみ**発行で throttle 上限 (6/min) に構造的に触れない設計（`withoutMiddleware` 等の抑制は使わない）と明記。middleware 前提（routes.php L98-100）・`assertRedirect('/email/verify')`・`assertSessionHas('success', ...)`・`assertSessionMissing('status')`・`Notification::assertSentTo(..., VerifyEmail::class)` をテスト計画に列挙。

## [Warning] A1: wantsJson 採用理由をテストコメントに残す
- 判断: 対応する
- 根拠: 既存 3 クラスは expectsJson を使っており、将来の統一リファクタで誤変換されるリスクは実在する。
- 対応内容: テスト計画に「JSON 分岐は Fortify 元実装互換のため wantsJson/202 を維持（既存 3 クラスの expectsJson とあえて揃えない）とテストコメントに明記する」を追加。

## [Warning] A2: user 在/不在の両ケースで success 一致 + status 不在を対で検証
- 判断: 対応する
- 根拠: enumeration 抑止は同一メッセージだけでなく同一キーの保証が必要。片側だけ status が残る誤実装の検出にもなる。
- 対応内容: A2 テスト計画を「両ケースで対に `assertSessionHas('success', 同一文言)` + `assertSessionMissing('status')`」に更新。

## [Suggestion] A2: STATUS_MESSAGE 名称の意味を docblock に 1 行追記
- 判断: 対応する
- 対応内容: 「`STATUS_MESSAGE` は Fortify の status 言語キーに対応するメッセージ内容の意味であり、flash キー名 (success) とは無関係」と docblock に追記する旨を設計に反映。

## [Warning] B: notifications 遅延共有 (closure) との整合をテストで固定
- 判断: 対応する
- 根拠: notifications は closure 共有のため partial reload で省略されうる。未定義時のフォールバックはテストで固定すべき。
- 対応内容: AppLayout.test.ts に「notifications が undefined でもクラッシュせず unreadCount 0 相当で描画する」ケースを追加。

## [Warning] B: headerActions 併用時の 設定/ログアウト 重複表示の回帰余地
- 判断: 対応する
- 根拠: snippet 契約を残す以上、将来ページが再注入する回帰は起こりうる。テストで「ちょうど 1 つずつ」を固定するのが安価で確実。
- 対応内容: AppLayout.test.ts に「headerActions snippet を渡しても nav-settings / nav-logout は getAllByTestId で 1 件ずつ」のアサーションを追加。

## [Suggestion] B: Dashboard.test.ts にテスト意図 (logout は AppLayout 責務) を明記
- 判断: 対応する
- 対応内容: Dashboard.test.ts の追記に「logout POST は AppLayout の単一ハンドラの責務であり、Dashboard 内イベントから router.post('/logout') を直接呼ばない」というコメント明記を設計に反映。

## [Warning] C: クラス不変条件テストの対象特定が DOM 順序依存になりがち
- 判断: 対応する
- 根拠: li の順序依存はフィクスチャ変更で壊れる。既存 data-testid 起点の traversal が堅牢。
- 対応内容: C テスト計画を「`member-role-3` / `revoke-invitation-10` 等の既存 testid から `closest("li")` / 親 div を辿る」方式に更新。2FA バッジ + 2FA 解除ボタンあり（bug-hunt 再現条件）の行を fixture で用意することも明記。

## [Suggestion] C: min-w-0 維持の明記は良い
- 判断: 現状維持（既に設計に明記済み）
