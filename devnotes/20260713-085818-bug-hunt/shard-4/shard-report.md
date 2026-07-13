# bug-hunt report shard-4 (S6: security / 2FA / profile / notifications)
- run-id: 20260713-085818
- 対象URL: http://127.0.0.1:8014
- DB: bug_hunt_4
- 開始: 2026-07-13 (JST)

## 実行ストーリー
- S6 (完走)。--deviate 記載の4項目すべて実行 (1: recent-auth未経由直POSTブロック確認 → F-05で穴を発見。2: パスワード変更後の旧セッション → F-06コード調査で確定。3: 最後のオーナーのアカウント削除 → F-04で組織孤児化を確認。4: メール変更時の旧アドレス通知 → 正しく実装されていることをコード確認で確定・findingなし)

## 画面カバレッジ (screens.md 割当)
- [x] settings (member-free / owner-free)
- [x] settings.security (member-free)
- [x] password.confirm (/user/confirm-password → recent-auth.confirm へリダイレクト。統合画面として確認)
- [x] recent-auth.confirm
- [x] recent-auth.status (fetch で確認)
- [x] notifications.index (member-free / multi-org / admin-free いずれも0件。空状態文言を確認)

## 操作カバレッジ (operations.md 割当)
- [x] user-profile-information.update (空値バリデーション + 正常系。F-01)
- [x] user-password.update (空値/誤パスワード/複雑性/漏洩パスワードバリデーション + 正常系。F-02。新パスワードでの再ログインも確認)
- [x] two-factor.enable
- [x] two-factor.confirm (TOTPシークレットから正しいコードを算出して確認)
- [x] two-factor.disable
- [x] two-factor.regenerate-recovery-codes (F-03: 二重トースト)
- [x] password.confirm.store (誤パスワード/正パスワード)
- [x] recent-auth.password (POST /recent-auth/password を確認。誤パスワード/正パスワード双方)
- [x] settings.account.destroy (member-freeでキャンセル確認 + owner-freeで実削除。F-04)
- [x] notifications.read (0件環境のため実データでの既読反映は未検証。存在しないUUIDへのPOSTは404で安全に失敗することを確認 = IDOR的な情報漏洩なし)
- [x] notifications.read-all (0件状態でも200 + 成功トースト。エラーなし)
- [x] notifications.open (存在しないUUIDへのPOSTは404で安全に失敗することを確認)

## UI/UX 検証
- H11 (視覚破綻): desktop/mobile/tabletいずれのscreenshotでもレイアウト崩れ・overflow・テキスト切れは観察されず
- H12 (アフォーダンス/状態): 2FA有効/無効バッジ、確認ダイアログの危険操作(赤枠)スタイリングなど状態表現は概ね明確。ただしF-01/F-02の通り「保存成功」という状態変化がUI上表現されない点は本ヒューリスティクスにも関連 (H7として計上)
- H13 (レスポンシブ): settings (mobile 375x667) / settings.security (mobile 375x667, tablet 768x1024, 2FA QRコード表示状態含む) / notifications (tablet 768x1024) を確認。いずれも横スクロール・要素はみ出し・タップ不能は観察されず。確認後 desktop (1280x800) に復帰済み
- H14 (a11y基礎): confirm dialogに見出し(h2)・閉じるボタンあり。トースト(status role)は適切にrole="status"。フォームエラーはtextbox[invalid]+関連paragraphで表現されaria的に妥当。QRコードのalt/aria-labelは個別確認していない(要フォローアップ)

## findings
Critical x0 / High x3 (F-04, F-05, F-06) / Medium x2 (F-01, F-02) / Low x1 (F-03) / 要確認 x0
(H7由来: F-01, F-02, F-05に関連。H10由来: F-03。純粋なセキュリティ設計/実装の穴: F-04, F-05, F-06)

## F-01: プロフィール保存成功時に成功フィードバック(トースト/flash)が一切表示されない
- severity: Medium (H7)
- story/step: S6-1
- 再現手順: http://127.0.0.1:8014/settings に `member-free@example.com` / `password123` でログイン → 「名前」欄を書き換え → 「保存」クリック
- 期待: 保存成功を示すトースト/flashメッセージ等のフィードバックが表示される
- 実際: ページはリロードされ (PUT /user/profile-information → 303 → GET /settings 200)、フォームの値は新しい値に更新されているが、画面上に成功を示す視覚的フィードバックが一切ない。console error/network error は無し。値自体は正しく保存されている(リロード後も保持)ため機能的には動作しているが、ユーザーは保存が成功したのか失敗したのか画面から判断できない。
- 阻害されたユーザージョブ: プロフィール更新が成功したことを確認できず、二重送信や不安な操作(何度も保存ボタンを押す等)を誘発する可能性がある。
- 改善アクション候補: 保存成功時にトースト通知 or インラインの成功メッセージを表示する。
- 証跡: screenshots/profile-update-feedback.png
- 推定原因: 未調査 (フロントの保存後フィードバックUIが実装されていない可能性)
- 関連既知情報: 未確認

## F-02: パスワード変更成功時も成功フィードバックが表示されない
- severity: Medium (H7)
- story/step: S6-1
- 再現手順: http://127.0.0.1:8014/settings で「現在のパスワード」に正しい値、「新しいパスワード」に強度要件を満たす新パスワードを入力し「パスワードを変更」をクリック (PUT /user/password → 303 → GET /settings 200)
- 期待: パスワード変更のような機微操作の成功時こそ明確なフィードバック(トースト等)が必要
- 実際: フォームはクリアされるが成功メッセージは一切表示されない。F-01と同一パターンだが、セキュリティ上重要な操作であるため独立して記録。
- 阻害されたユーザージョブ: パスワードが実際に変更されたのか確信が持てず、再度同じ操作を繰り返す・不安になる。
- 改善アクション候補: 保存成功時にトースト通知を表示する (F-01と共通対応で解決可能)。
- 証跡: console/network ログのみ (PUT /user/password => 303, GET /settings => 200, エラーなし)
- 推定原因: 未調査 (F-01と同根の可能性が高い)
- 関連既知情報: F-01 と同一原因の可能性

## F-03: リカバリコード再生成で同一操作に対しトーストが2重表示される
- severity: Low (H10寄り。表示矛盾ではないが冗長)
- story/step: S6-2
- 再現手順: http://127.0.0.1:8014/settings/security で2FA有効化後、「リカバリコードを再生成」→確認ダイアログで「再生成する」をクリック
- 期待: 成功トーストが1つ表示される
- 実際: `snapshot` で確認すると status ロールのトーストが2つ同時に表示される: 「リカバリコードを再生成しました。」と「リカバリコードを再生成しました。新しいコードを保管してください。」(文言も微妙に異なる)。screenshot撮影時には既にフェードアウトしていたため見た目上は一瞬で消えるが、DOM上は明確に2つの status リージョンが存在した。
- 阻害されたユーザージョブ: 実害は軽微だが、UIの一貫性が損なわれ、将来的にトースト重複が原因のちらつき・読み上げ二重化(スクリーンリーダー)につながる可能性。
- 改善アクション候補: 同一操作のtoast発火元を1箇所に統一する(サーバflash + クライアント側の楽観的トーストが二重発火している可能性)。
- 証跡: snapshot出力 (二重 status region)。screenshots/recovery-codes-double-toast.png (撮影時は既に消滅)
- 推定原因: 未調査 (flash-to-toast変換とフロント側の即時トースト表示が競合している可能性)
- 関連既知情報: 未確認

## F-04: 組織の唯一のオーナー/管理者がアカウント削除しても組織孤児化の警告が一切ない (逸脱アイデア確認)
- severity: High
- story/step: S6-deviate (「アカウント削除を最後のオーナーが実行 → 組織が孤児化しないか、警告が出るか」)
- 再現手順:
  1. http://127.0.0.1:8014 に `owner-free@example.com` / `password123` でログイン (「Freeプラン組織」のオーナー)
  2. `/settings` → 「アカウントを削除」→ 確認ダイアログ「削除する」で実削除 (DELETE /settings/account)
  3. 確認ダイアログ・削除完了後いずれにも「あなたは唯一のオーナーです」等の警告は一切表示されない。削除後はランディングページへ遷移するのみ
  4. 別セッションで `member-free@example.com` (同組織のメンバー) で再ログインし `/dashboard` を見ると、依然として「『Freeプラン組織』の管理者にプロジェクト作成を依頼してください」と表示される。しかし依頼先の管理者は存在しない (オーナーごと削除済み)
- 期待: 唯一のオーナー/管理者のアカウント削除時には (a) 確認ダイアログで組織が孤児化する旨の明示的な警告を出す、または (b) 削除をブロックし、事前にオーナー移譲を要求する
- 実際: 警告なし・ブロックなしで即削除される。結果、組織にはプロジェクト作成等の管理操作ができる人間が誰もいなくなり、残存メンバー (member-free 等) は永続的に「管理者に依頼してください」という到達不能な導線に取り残される (H2 詰みに近い組織全体の機能不全)
- 阻害されたユーザージョブ: 組織全体が管理不能になる。残存メンバーはプロジェクト作成・メンバー管理・プラン変更等、管理者権限が必要な操作を二度と行えなくなる可能性が高い (組織丸ごとのサービス利用停止に近い)
- 改善アクション候補: アカウント削除処理で「削除対象ユーザーが唯一のオーナーである組織」を検出し、(1) 削除前に警告 + オーナー移譲を促す、または (2) 削除をサーバー側でブロックする
- 証跡: 操作ログ (snapshot) — 確認ダイアログ本文に組織関連の文言なし。削除後 `tmp/bug-hunt/shard-4-cmd.sh db-check` で users: 8→7 (削除成功を確認)。member-free 再ログイン後の dashboard snapshot で「管理者に依頼してください」文言が削除後も残存
- 推定原因: 未調査 (settings.account.destroy の実装が組織所属チェックをしていない可能性)
- 関連既知情報: 未確認。S4 (組織管理) 側のオーナー移譲・退出フローとの整合性要確認

## F-05: 2要素認証の有効化/無効化/リカバリコード再生成が recent-auth (再認証) を一切要求しない
- severity: High
- story/step: S6-deviate (「再認証を経ずに機微操作を直POST → ブロックされるか」)
- 再現手順:
  1. http://127.0.0.1:8014 に `member-free@example.com` でログインした直後、`/settings/security` へ遷移
  2. 「有効化」→ QRコード表示 → TOTPコードで「確認して有効化」→ 即座に成功 (再認証プロンプトなし)
  3. 続けて「2要素認証を無効化」→ 確認ダイアログ (削除意図の確認のみ、パスワード再入力欄なし) → 「無効化する」で即座に無効化 (再認証プロンプトなし)
  4. 同様に「リカバリコードを再生成」も即座に成功
  5. コード確認: `app/Http/Middleware/RequireRecentAuth.php` によるstep-up再認証 (`recent-auth` middleware) は `routes/web.php` 上で `settings.account.destroy`・`organizations.members.two-factor.reset`・`organizations.two-factor-requirement.update`・オーナー移譲・APIトークン発行/失効にのみ明示的に付与されている。一方 `two-factor.enable`/`two-factor.confirm`/`two-factor.disable`/`two-factor.regenerate-recovery-codes` は `vendor/laravel/fortify/routes/routes.php` が定義する Fortify 標準ルートで、`config/fortify.php` の `Features::twoFactorAuthentication(['confirmPassword' => false])` によりFortify自身の `password.confirm` ミドルウェアも外されている (`$twoFactorMiddleware` = 認証済みのみ)。結果、これら4操作は一切のstep-up再認証を経由しない
  6. 参考: `app/Security/RecentAuthWindow.php` により recent-auth の鮮度窓は既定 900秒 (15分) だが、2FA系操作はこの窓の判定自体を通らないため、ログインから何時間・何日経過したセッション(remember-meの永続セッション含む)でも再認証なしで2FAを無効化できる
- 期待: アカウント削除やAPIトークン発行と同程度以上にセキュリティ影響の大きい「2要素認証の無効化」「リカバリコード再生成」こそ `recent-auth` step-up 必須にすべき (実際、S6ストーリーカード自体が2FA無効化を「機微操作」の代表例として明記している)
- 実際: 2FA関連の4操作 (enable/confirm/disable/regenerate-recovery-codes) はいずれも通常のセッション認証のみで実行でき、`recent-auth`/`password.confirm` のいずれの再認証チェックも通らない
- 阻害されたユーザージョブ: セッションハイジャック(XSS・共有端末への放置・remember-meクッキー窃取等)が発生した場合、攻撃者はパスワードを一切知らなくても被害者の2FAを無効化しアカウントの認証要素を弱体化できる。step-up再認証という多層防御がこの経路にだけ存在しない
- 改善アクション候補: `two-factor.disable` と `two-factor.regenerate-recovery-codes` (少なくとも無効化・再生成) に `recent-auth` middleware を付与する。有効化(enable/confirm)は初回設定なので現状維持でも議論の余地はあるが、無効化・再生成は他の機微操作と同列に扱うべき
- 証跡: `routes/web.php` の `recent-auth` middleware 付与箇所 (grep結果)、`vendor/laravel/fortify/routes/routes.php:146-175`、`config/fortify.php:166` (`'confirmPassword' => false`)。実機操作でも 有効化→無効化→再生成 のいずれもパスワード再入力/recent-auth confirm画面への遷移が一度も発生しないことを確認 (snapshot 上に該当UIなし)
- 推定原因: Fortifyの2FAルートがカスタムの `recent-auth` ミドルウェアでラップされておらず、かつ `confirmPassword` オプションも意図的にfalseにされている (コメントでは「Fortify生のpassword.confirmを置き換える」意図が読めるが、2FAルート自体は置き換え対象に含まれていない)
- 関連既知情報: 未確認。設計意図であれば「要確認」に降格すべきだが、S6ストーリーカードの前提 (2FA無効化 = 機微操作) と矛盾するため finding として記録

## F-06: パスワード変更後も旧セッション(他端末/盗難クッキー)が無効化されない
- severity: High
- story/step: S6-deviate (「パスワード変更後に旧セッションが無効化されるか」)
- 再現手順 (コード調査で確定。実機では単一隔離セッションのため2セッション比較は不可):
  1. `app/Actions/Fortify/UpdateUserPassword.php` を確認。パスワード変更処理は `current_password` 検証 → `Hash::make($input['password'])` を `forceFill` して `save()` するのみ
  2. `Auth::logoutOtherDevices()` の呼び出しや `remember_token` の再生成、他セッションの破棄処理が一切ない
  3. 実機でも、`/settings` でパスワード変更後、同一ブラウザの既存セッション (変更前から張られていたcookie) は再ログイン不要でそのまま `/dashboard` 等にアクセスでき続けることを確認 (当然だが少なくとも「自セッションの継続」は無条件に許可されており、他セッションを区別して失効させる仕組みが存在しないことのコード的傍証)
- 期待: パスワード変更(特に「アカウント乗っ取られたかもしれない」ときの防御的パスワード変更)は、変更を行ったセッション以外の全セッション(他端末・盗まれたセッションcookie・remember-meトークン)を無効化するべき (Laravel Fortify/Jetstream標準機能の "Log Out Other Browser Sessions" 相当)
- 実際: パスワードハッシュのみ更新され、既存の全セッション(DBセッションのuser_id紐付け、remember_token)がそのまま有効であり続ける。攻撃者がセッションcookieやremember-meトークンを窃取している場合、被害者がパスワードを変更しても攻撃者のセッションは生き続ける
- 阻害されたユーザージョブ: 「アカウントが乗っ取られたかもしれないのでパスワードを変更する」という典型的な防御行動が、実際には攻撃者のセッションを排除できず無意味になる
- 改善アクション候補: `UpdateUserPassword::update()` 内で `Auth::logoutOtherDevices($input['password'])` を呼ぶ、または `remember_token` を再生成しDBセッションから当該ユーザーの他セッションを削除する
- 証跡: `app/Actions/Fortify/UpdateUserPassword.php` 全文 (該当メソッドにセッション失効処理が存在しない)
- 推定原因: Fortify標準実装のまま (ログアウト他デバイス機能が未実装)
- 関連既知情報: 未確認。仕様として「他デバイスログアウトは別画面で提供」であればscreens.md/operations.mdに見当たらないため要確認

## skip した項目
- notifications.read / notifications.open の「実データに対する既読反映確認」(H10): member-free / multi-org@example.com / admin-free@example.com いずれのテストアカウントにも通知(ジョブ完了・招待・チケット残高)が1件も存在しない状態だったため、UI上で実際の通知アイテムをクリックして既読反映・遷移を確認することができなかった。
  - 理由: 通知を新規発生させるには (a) 組織への招待送信 (S2/S4のoperations.invitations.store)、(b) ジョブ完了 (動画レンダリング等、他ストーリー領域)、(c) チケット残高閾値をまたぐ購入/消費 (課金操作) のいずれかが必要で、いずれもS6の担当操作範囲外。org設定画面へのnav導線も現在のヘッダーからは見つけられなかった (S4の管轄画面のため深追いしなかった)。
  - 代替検証として: 存在しないUUID (`00000000-0000-0000-0000-000000000000`) への `POST /notifications/{id}/read` と `/open` が404で安全に失敗すること (他ユーザーの通知の存在有無を推測させる情報漏洩がないこと) を確認済み。`notifications.read-all` は0件状態でも200 + 成功トーストで正常動作を確認済み。

## インベントリ修正提案
- notifications.read / notifications.open の実データ検証のために、次回走行では ManualTestSeeder 側で最低1件の in-app notification (例: TicketBalanceLowNotification) を代表ユーザーに仕込んでおくことを提案。または S6 のストーリーカードに「S2実施後の状態を前提にする (招待通知を利用)」という前提を明記し、S7同様に状態依存ストーリーとして扱う。

## 要確認 (仕様不明)
- F-05 (2FA関連操作がrecent-auth未対象) は既存コードのコメント意図から見ると「Fortify生のpassword.confirmを置き換える」目的で recent-auth が導入されたと読めるが、置き換え対象に2FAルート自体が含まれていない。これが意図的な設計判断 (2FA初回設定の摩擦を避けるため無効化/再生成もあえて対象外にした) か実装漏れかは、設計文書 (devnotes) での確認が必要。
- F-04 (アカウント削除時の組織孤児化) も同様に、「唯一のオーナーの退出/削除」を扱う専用フロー (オーナー移譲必須化など) がS4側に別途存在するのか、それとも本当に未対応なのか確認が必要。

## Critical/High サマリ (TODO候補)
1. **F-05 [High]**: 2要素認証の無効化・リカバリコード再生成が recent-auth (step-up再認証) を一切要求しない。再現: `member-free@example.com` でログイン直後に `/settings/security` で有効化→即座に無効化/再生成が可能 (再認証プロンプトなし)。阻害ジョブ: セッションハイジャック時に攻撃者がパスワード知識なしで2FAを無効化できる。改善: `two-factor.disable`/`two-factor.regenerate-recovery-codes` に `recent-auth` middleware を付与。関連ファイル: `routes/web.php`, `config/fortify.php:166`, `app/Http/Middleware/RequireRecentAuth.php`
2. **F-06 [High]**: パスワード変更が既存の他セッション/remember-meトークンを無効化しない。再現: `app/Actions/Fortify/UpdateUserPassword.php` のコード確認 (logoutOtherDevices等の呼び出しなし)。阻害ジョブ: 「乗っ取られたかもしれない」ためのパスワード変更が攻撃者セッションを排除できず無意味になる。改善: `Auth::logoutOtherDevices($password)` の呼び出しを追加。関連ファイル: `app/Actions/Fortify/UpdateUserPassword.php`
3. **F-04 [High]**: 組織の唯一のオーナーがアカウント削除しても孤児化警告が皆無。再現: `owner-free@example.com` でログイン→`/settings`→アカウント削除→確認ダイアログ・削除後いずれにも組織関連の警告なし。阻害ジョブ: 残存メンバーが管理者不在のまま永久に「管理者に依頼してください」に取り残される。改善: 唯一オーナー検出時の削除ブロックまたは明示警告+オーナー移譲導線。関連ファイル: settings.account.destroy のコントローラ/アクション (未特定、要調査)

## 走行終了
- 終了: 2026-07-13 (JST)
- playwright-cli close 実行済み
