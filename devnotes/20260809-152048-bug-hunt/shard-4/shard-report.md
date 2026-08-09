# bug-hunt shard-4 report (run 20260809-152048)
- 対象 URL: http://127.0.0.1:8014 (DB: bug_hunt_4)
- 割り当て: S6 (セキュリティ: 2FA / プロフィール / パスワード / セッション管理)
- 実行ストーリー: S6 (完走。--deviate 込み)
- skip したステップ: 下記「skip したステップ」節参照 (いずれも環境制約による理由付き skip、無言 skip なし)

## 画面カバレッジ
走行 9/9 (screens.md の S6 対象を全消化): settings, settings.security, password.confirm (`/user/confirm-password`→`recent-auth/confirm`へ誘導), recent-auth.confirm, recent-auth.status (直接 fetch), notifications.index, session.status (bfcache guard 経由で実地検証), passkey.registration-options (直接 fetch), passkey.confirm-options (直接 fetch)

## 操作カバレッジ
実行 15/16 (operations.md の S6 対象): user-profile-information.update, user-password.update, two-factor.enable, two-factor.confirm, two-factor.disable, two-factor.regenerate-recovery-codes, password.confirm.store (≒recent-auth.password 経由で機能確認), recent-auth.password, settings.account.destroy, notifications.read, notifications.read-all, notifications.open, passkey.destroy (direct fetch IDOR 検証), passkey.confirm (直接 fetch options 確認まで), settings.password.store (direct POST バイパス検証)。
**未実行 1件**: passkey.store — この環境に WebAuthn 仮想認証器が無く「パスキーを登録」ボタンで実際の資格情報作成が完結しない (`settings/security` に「この端末ではパスキーを作成できません」と表示される環境制約。理由付き skip、詳細は下記)。

## UI/UX 検証 (H11-H14)
- H13 (レスポンシブ): `settings/security` を mobile 375x667 (screenshots/mobile-security.png) と tablet 768x1024 (screenshots/tablet-security.png) で確認。横スクロール・要素重なりなし、パスキー未対応の注意書きも折り返して正しく収まる。`settings` (プロフィール) mobile 375x667 (screenshots/mobile-settings-profile.png) も同様に崩れなし (メールアドレス input の表示文字列がフィールド幅で見切れるが、標準的な input のスクロール挙動でありオーバーフロー/操作不能ではないため finding にはしない)。`notifications` mobile 375x667 (screenshots/mobile-notifications.png) の空状態も中央寄せで綺麗に収まる。desktop (1280x800) に復帰して継続。
- H11/H12/H14: 各画面の snapshot で primary (青塗り) / 破壊的操作 (削除系は確認ダイアログ経由) の視覚的区別は一貫。disabled/loading の判別は明示的に崩れているケース未発見 (通常の操作フローの範囲内)。

## 検証済みの正しい挙動 (finding ではないが記録)
- V-1: プロフィール編集 (name) 空入力 → バリデーションエラー表示 (「名前は必須項目です。」)。正常系保存でトースト「プロフィールを更新しました。」表示 (probe visible:true)、サイドバー表示名も即時反映 (H10 OK)。
- V-2: メールアドレス変更 (`user-profile-information.update`) → 成功後 `email/verify` へ遷移し emailVerified が解除される。dashboard 等保護画面は再検証まで `/email/verify` にロックされる (意図的リダイレクト、H1 に非該当)。署名 URL でメール確認後は正常に戻れた。
- V-3: パスワード変更フォーム、空入力→バリデーション、誤った現在パスワード→「パスワードが現在のパスワードと一致しません。」、正しい入力→トースト「パスワードを変更しました。」(probe visible:true)。
- V-4: **パスワード変更時の他セッション無効化を実機検証**: 手順 = (a) cookie-clear→ログイン(sessionA2, cookie保存)→(b) cookie-clear (logoutは呼ばない、明示ログアウト非経由)→再ログイン(sessionB2)→(c) sessionB2 でパスワード変更 (NewPassword789! → FinalPassword001!) →(d) cookie-clear→sessionA2 の cookie を再注入して `/dashboard` へ → **`/login` へリダイレクト (sessionA2 は無効化されていた)**。旧セッションの明示的ログアウトを一切経由していないため、パスワード変更が他セッションを正しく無効化することを確認 (逸脱アイデア 2 項目め, secure)。
- V-5: 「パスワードを表示」トグルが profile / password 両フォームに存在 (T042 OK)。
- V-6: **2FA 有効化 (TOTP) を実機で完走** (`two-factor.enable`→`two-factor.confirm`)。QR/セットアップキー表示 → 秘密鍵から Python stdlib (base32+hmac-sha1) で RFC6238 TOTP を自前計算しコード入力 → 有効化成功、リカバリコード 8 件表示。ログアウト→再ログインで `two-factor-challenge` 画面に正しく遷移し、TOTP コードでログイン成功。詰み (H2) なし。
- V-7: リカバリコードでのログインも成功 (single-use)。**同じリカバリコードを再利用 → 正しく拒否**(「二要素認証のリカバリーコードが無効です。」)。リカバリコード再生成 (`two-factor.regenerate-recovery-codes`) は確認ダイアログを経由し、トースト「リカバリコードを再生成しました。新しいコードを保管してください。」が 1 回だけ表示 (T026 OK)。
- V-8: `two-factor-challenge` 画面には「認証コードもリカバリコードも使えない場合は ログインをやり直す か、組織の管理者に2要素認証のリセットを依頼してください」という行き先付きの案内があり、詰みを回避する設計になっている。
- V-9: 2FA 無効化 (`two-factor.disable`) は確認ダイアログ経由 + トースト「二要素認証を無効化しました。」で完走。ソースコード確認 (`app/Providers/FortifyServiceProvider.php` `RECENT_AUTH_ROUTE_NAMES`) では `two-factor.disable` / `two-factor.regenerate-recovery-codes` / `two-factor.recovery-codes` / `two-factor.qr-code` / `two-factor.secret-key` / `two-factor.enable` に `recent-auth` middleware が付与されている。今回の操作はいずれもログイン直後 (recent_auth_timeout=900秒 以内) だったため、UI 上は step-up プロンプトが出ずに素通りした (期待通りの挙動。「直近に認証済みなら再確認しない」設計のため)。

- V-10: 通知 (`notifications.*`) を実機で完走: 招待メール送信 (`manage/users` invite) → 招待受諾 → 通知一覧に届く → 個別「既読にする」(`notifications.read`) でトーストは無いが即座にバッジ/ボタンが消え状態変化は明確 → 通知本体クリック (`notifications.open`) は失効済み招待で「現在有効な招待はありません」と親切なエラー表示 (H4 に該当しない) → 別アカウントで新規招待を作り「すべて既読にする」(`notifications.read-all`) → トースト「すべての通知を既読にしました」表示 (1 回のみ)。空状態には「ジョブの完了・招待・チケット残高の通知がここに表示されます」の説明あり (H8 OK)。タブタイトルは「通知 | AI-CUE」(T034 OK)。
- V-11: **`passkey.destroy` IDOR/入力検証を direct fetch で検証**: 存在しないID (999999) / 非数値 ("abc") / bigint 超過 ("99999999999999999999") / 負数 (-1) / 小さい正数 (1、DB内に他ユーザーpasskeyが無いため実質的に「未所持ID」) の全パターンで **一貫して 404** (500 も 403 も無し)。存在オラクル露出なし、fail-safe な入力検証を確認 (期待通り、finding 無し)。
- V-12: **`settings.password.store` の direct POST バイパスを検証**: 既にパスワード設定済みの `owner-personal-new@example.com` で `POST /settings/password` を直接叩いたところ **422 で拒否** (「すでにパスワードが設定されています。パスワード変更フォームから変更してください。」)。current_password 検証迂回は不可能 (期待通り、finding 無し)。

- V-13: **bfcache 復元時の秘匿 (`session.status`) を実機検証**: `/dashboard` にログイン中に遷移 → ログアウト → ブラウザ「戻る」→ 一瞬 `/dashboard` の bfcache が復元されるが直後に `session.status` チェックにより `/login` へ強制遷移し、ダッシュボードの中身 (氏名・チケット残高等) が最終 DOM に露出しない。逆にセッションが有効なままの「戻る」(dashboard→settings→戻る) は白画面や詰みにならず正常に settings へ復元された。Chromium レーンでは詰みなし (Story は WebKit を主戦場と指定しているが本 shard は Chromium 固定のため、WebKit 側は別途検証が必要 — 下記 skip 参照)。

- V-14: **アカウント削除 (`settings.account.destroy`) を非オーナー (`member-standard@example.com`) で完走**: 確認ダイアログ「キャンセル」で正しく中止 → 再度「削除する」で確認ダイアログを経由し実行 → トースト「アカウントを削除しました」(1回) → ランディングページへ遷移しログアウト状態に。削除後、同じ資格情報でログイン試行 → 「認証に失敗しました。」で正しく拒否 (アカウントが実際に消えている)。**逸脱アイデア「最後のオーナーが削除実行」も確認済み**: `owner-personal-new@example.com` (Personal組織の唯一のオーナー) は削除ボタンが「アカウントを削除」ではなく無効化され、代わりに「退会するには先に対応が必要です / 以下の組織で対応が必要です (削除時にサーバーが再判定します) / Personalプラン組織 [オーナーを移譲する]」という行き先付きの警告に置き換わる (孤児化防止・詰みなし、期待通り)。
- V-15: **組織 2FA 必須化 (`organizations.two-factor-requirement.update`) の詰み検証を実機で完走 (--deviate 対応)**: オーナー自身が 2FA 未設定の状態で必須化を試みる → **ブロックされ「必須化するには、先にご自身の2段階認証を有効にしてください。」と明示**(自分自身を締め出す設定を事前に防止、H2 回避の良い設計)。オーナーが自分の 2FA を有効化してから再度必須化 → 成功トースト「2段階認証を必須にしました」。その状態でパスワードのみ (2FA/パスキー未設定) の `member-personal@example.com` でログイン → **自動的に `/settings/security` へ誘導され「組織「Personalプラン組織」は2段階認証を必須としています。設定が完了するまで他のページはご利用いただけません。」と表示** (alert)。`/dashboard` への直接遷移も `/settings/security` へリダイレクトされ回避不可 (正しい enforcement)。**そのままこの画面で 2FA (TOTP) を有効化 → 直後に `/dashboard` へ到達可能** (詰みなし)。ロックダウン中も「ログアウト」導線は残っており逃げ場もある。

## 要確認 (severity 未付与)
- Q-1: **doc drift 疑い**: `config/fortify.php` のコメントは「残る 2FA 管理エンドポイント (enable/confirm/qr-code/secret-key) は step-up なしで到達可能」と書かれているが、`app/Providers/FortifyServiceProvider.php` の `RECENT_AUTH_ROUTE_NAMES` を読むと `two-factor.enable` / `two-factor.qr-code` / `two-factor.secret-key` は実際には `recent-auth` middleware が付与されており (`two-factor.confirm` のみ未付与)、コメントと実装が食い違って見える。`two-factor.confirm` は `enable` 直後の同一フローとして freshness を共有する設計なら妥当だが、コメントが古いままである可能性がある。実際の recent-auth 失効 (900秒待機 or passkey 登録要 = この環境では認証器が使えず不可) を伴うライブ再現は今回未実施 (下記 skip 参照)。仕様確認を推奨。関連: `config/fortify.php` L162-168, `app/Providers/FortifyServiceProvider.php` L83-90。

## findings

本ストーリーは想定より「良い意味で finding が出なかった」回になった。severity 付き finding は 0 件
(Critical/High/Medium/Low いずれも無し)。要確認 (severity 未付与) は Q-1 の 1 件のみ。理由は上記
「検証済みの正しい挙動」に記録した通り、機微操作 (2FA有効化/無効化/リカバリコード/パスワード変更/
アカウント削除/組織2FA必須化/session invalidation/passkey IDOR/settings.password.store バイパス)
のいずれも実機で破壊を試みたが、すべて設計通りに fail-safe だった。

severity 別件数: Critical 0 / High 0 / Medium 0 / Low 0 / 要確認 1 (Q-1)

## H7 未検証
- 通知の個別「既読にする」(`notifications.read`) は feedback probe で `installed_now:false / seen:[] / present_new:[] / pending:0 / errors:0` (厳密には陰性の要件を満たす) だったが、クリック直後に該当アイテムの「未読」バッジと「既読にする」ボタン自体が消える**視覚的な状態変化が snapshot で直接確認できた**ため、H7 (Medium finding) には格上げしなかった (probe が拾う live region 外の視覚フィードバックが存在するケース。SKILL.md 「H14 へ格上げしてよいのは…と示せた場合だけ」に該当しないため見送り、observation として記録するに留める)。非破壊的・低リスクな操作であることも判断材料。

## skip したステップ (理由必須)
- **recent-auth の 900 秒 (recent_auth_timeout) 実失効を伴うライブ直 POST バイパス検証**: 本アプリで recent-auth を明示的に失効させる唯一のイベントは passkey 登録/削除 (`ClearRecentAuthOnPasskeyChange`) で、本環境は WebAuthn 仮想認証器が使えず (「この端末ではパスキーを作成できません」) passkey 登録不可。実時間 900 秒待機は 1 shard の予算に対して過大なため見送り、代わりに `routes/web.php` / `app/Providers/FortifyServiceProvider.php` のソース確認で `recent-auth` middleware の付与状況を検証した (Q-1 参照)。
- **passkey 登録 → 削除の実機フロー (T106/T107、唯一のログイン手段ガード `ensure-login-method` の実地検証)**: この Chromium ヘッドレス環境ではプラットフォーム認証器が無く「パスキーを登録」ボタンで実際の WebAuthn 登録が完結できない (`settings/security` に「この端末ではパスキーを作成できません」と明示表示、環境仕様通り)。`passkey.registration-options` / `passkey.confirm-options` の JSON 応答と `passkey.destroy` の 404-only IDOR 耐性は direct fetch で検証済み (V-11)。
- **WebKit レーンでの bfcache 検証**: story は「iOS Safari / WebKit レーンが主戦場」と明記するが、本 shard 環境は `--browser chromium` 固定 (config/cli.config.json)。Chromium では詰みなし・秘匿成功を確認 (V-13)。WebKit 固有の bfcache pageshow タイミング差は別途 WebKit セッションでの追走が必要。
- **多数アイテムでの通知 read-all (2件以上同時未読からの一括既読)**: 実機では 1 件ずつしか未読を再現できず、常に 1 アイテムでの read-all 検証に留まった (機能的には確認済み、大量データでの挙動は未検証)。

## 環境/インベントリ関連メモ
- インベントリ (screens.md / operations.md) と実装の乖離は見つからず。screens.md の S6 対象 9 画面・operations.md の S6 対象 16 操作は全て実在し、名前・パスも一致していた。
- 唯一の気付きは Q-1 (config/fortify.php のコメントと FortifyServiceProvider.php の実装の食い違いの疑い) だが、これはインベントリ (screens.md/operations.md) ではなくアプリ内部ドキュメントコメントの話なので、bug-hunt インベントリへの修正提案はなし。
