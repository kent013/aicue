# 対応マトリクス: design-review Round 1

## [Critical] S3: PII を props で返す応答のキャッシュ/漏えい運用が未明文化
- 判断: 対応する
- 根拠: 妥当。bearer token 由来 PII を含む GET 応答は共有/中間キャッシュに保存させない運用 fail-safe が必要。
- 対応内容: S3 の registerView を closure 化し `Inertia::render(...)->toResponse($request)` で concrete Response を取得、`invitationEmail !== null` の時のみ `Cache-Control: no-store` を付与。Fortify `SimpleViewResponse::toResponse()` が素の Response をそのまま返すことを vendor で確認済。「セキュリティ/キャッシュ運用」節と S5 検証項目 (no-store あり/なし) を追加。

## [Critical] S5: 「POST 前 revoke」ケースの副作用アサート不足
- 判断: 対応する
- 根拠: 妥当。登録完了後の current_organization_id / メンバーシップまで固定すべき。
- 対応内容: S5 の該当ケースに「招待組織に非参加」「個人組織生成済」「current_organization_id が個人組織側」「session token forget」を明示アサートとして追加。

## [Warning] S2: `Assert::string($email)` は想定外型で 500 を招く
- 判断: 対応する
- 根拠: 妥当。fail-secure 徹底のため Assert 依存を減らす。
- 対応内容: `if (! is_string($email) || $email === '') { $session->forget('invitation_token'); return null; }` に置換。PHPStan チェック欄も更新。

## [Warning] S4: readonly は devtools で外され得る (真正性はサーバ)
- 判断: 対応する
- 根拠: 妥当。責務分担の明文化。
- 対応内容: S4 に「readonly は UX 誘導に過ぎず、真正性は `MatchesInvitationEmail` (サーバ) が強制」する責務分担注記を追加 (実装コメントにも残す)。

## [Warning] S5: socialProviders 非退行が 1 ケースのみで弱い
- 判断: 対応する
- 対応内容: token 有り/無しの 2 系統で `socialProviders` props 存在を確認するケースを追加。

## [Suggestion] S1: active 定義の単一コメント
- 判断: 対応する
- 対応内容: `findActiveByPlainToken()` に「active の正は scopeActive」コメントを追加。

## [Suggestion] S5(JS): 未指定時の初期値空文字も検証
- 判断: 対応する
- 対応内容: JS テストに「invitationEmail 未指定 → email 初期値空文字」を追加。

## [Suggestion] その他 (S1 命名/責務, S2 fail-secure 一貫性, S3 Inertia props 妥当, S4 Atomic/DESIGN 準拠)
- 判断: 見送り (肯定コメント。追加対応不要)
