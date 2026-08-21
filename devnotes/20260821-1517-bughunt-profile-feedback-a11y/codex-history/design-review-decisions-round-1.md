# 対応マトリクス: design-review Round 1

Critical は 0 件。Warning に対応する。

## [Warning] 施策1: 「認証メールを送信しました」の前提が未テスト
- 判断: 対応する
- 根拠: `UpdateUserProfileInformation` は email 変更時 `sendEmailVerificationNotification()` を呼ぶが、
  既存テストは `EmailChangedSecurityNotification`(旧アドレス)しか assert していない。文言を裏付ける必要あり。
- 対応内容: 施策1-T に `Notification::assertSentTo($user, Illuminate\Auth\Notifications\VerifyEmail::class)`
  (Fortify 標準の検証通知) の assert を追加し、確定表現「認証メールを送信しました」を保証する。

## [Warning] 施策1-T: session だけでなく Inertia props の flash.success を検査
- 判断: 対応する
- 対応内容: PUT が返す 302 を追って `/email/verify` を Inertia GET し、`assertInertia(fn ($page) =>
  $page->where('flash.success', EMAIL_CHANGED_MESSAGE))` で consumeFlash が読む共有 prop 値まで固定する。

## [Warning] 施策1-T: recent-auth 統合を「fresh 直接 PUT で代替可」は不十分
- 判断: 対応する
- 対応内容: `ProfileEmailChangeRecentAuthTest` に統合テストを 1 本追加。stale → PUT(email 変更)=409 →
  `POST /recent-auth/password`(確認)→ 元 PUT 再送 → `assertRedirect(verification.notice)` +
  session success を固定 (再認証完了後の元操作再送という実経路を server 側で通す)。代替表現を撤回。

## [Warning] 施策1-T: JSON 本文を正確に固定
- 判断: 対応する
- 対応内容: 「空 JSON」を撤回。`putJson(...)` で HTTP 200 / Content-Type application/json /
  本文が正確に `""` (`$response->getContent() === '""'`) を固定。

## [Suggestion] 施策1-T: Factory state / recent_auth fresh を明示
- 判断: 対応する
- 対応内容: 変更前は認証済み (verified) ・`withSession(['recent_auth_at' => time()])` を明示設定する旨をテスト計画に記載。

## [Warning] 施策2: 統合 `<p aria-live>` 撤去で動的読み上げ手段が消える (a11y 後退)
- 判断: 対応する (Codex 提示の option (b) を採用)
- 根拠: FormError atom は現状 `role="alert"`/`aria-live` を持たない (読了確認)。FormField 経由に寄せるだけだと
  AutoRecharge が現在持つ動的読み上げ (`<p aria-live="polite">`) を失う。
- 対応内容: 施策 2b として **FormError atom に `aria-live="polite"` を付与**する (押下後に現れるエラーの動的
  読み上げを保証。全フォーム共通の a11y 底上げにもなり consistency も上がる)。FormField.test.ts の既存 assert
  (id="name-error" 等) は属性追加で壊れない。施策2-T で FormError の aria-live をコンポーネントテストで固定。
  ※ 追加は 1 属性 + テストのみで over-engineering ではない。

## [Warning] 施策2-T: getByText は誤検出・関連付け未保証
- 判断: 対応する
- 対応内容: `getByRole("spinbutton", { name: ... })` で対象入力を取得し、`toHaveAttribute("aria-invalid","true")`
  + `toHaveAccessibleDescription(正確なエラー文言)` (jest-dom 6.9.1 導入済み) で describedby 関連付けまで検査。

## [Warning] 施策2-T: max<=threshold の具体値を明記し 3 分岐を区別
- 判断: 対応する
- 対応内容: props 既定 (thresholdCount=5, minCount=1, maxCountLimit=1000) を用い、
  (1) threshold 解析/範囲エラー: threshold-input="-1"(または非整数) / (2) max 解析/範囲エラー: max-input="0" /
  (3) 個別有効だが max<=threshold: threshold-input="5"・max-input="3" (3 は minCount..limit で有効かつ 3<=5)
  の 3 ケースを別個に固定。

## [Suggestion] 施策2-T: getByRole 優先
- 判断: 対応する (上記で getByRole spinbutton を採用済み)

## [Warning] 横断: テストファースト順序と検証コマンド一覧を明記
- 判断: 対応する
- 対応内容: 「テストファースト実行順序」と AGENTS.md L336-338 の検証コマンド一覧
  (`composer test`/`composer phpstan`/`vendor/bin/pint --test`/`pnpm lint`/`pnpm typecheck`/`pnpm test`/`pnpm build` 等)
  を横断セクションに明記。`composer fix`/`pnpm lint:fix` は完了条件ではないことも記載。
