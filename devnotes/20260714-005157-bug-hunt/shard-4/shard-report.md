# Shard 4 Report (run 20260714-005157) — S6 security/2FA/profile (回帰確認 2回目走行)

- URL: http://127.0.0.1:8014
- DB: bug_hunt_4 (db-check: users=8, 走行開始時点)
- Session: playwright-cli -s=bughunt4
- Story: S6 (security / 2FA / profile / notifications)
- 主眼: 前回走行 (20260713-085818) F-H3/F-H4/F-H5/F-M1/F-L1 の回帰確認 + 新規探索

## 前回 findings (回帰確認対象)
- F-H3: 2FA disable/regenerate-recovery-codes が recent-auth 未要求 (由来 shard-4 F-05) → 修正: devnotes/20260713-1653-twofa-recent-auth (APPROVED)
- F-H4: パスワード変更で他セッション/remember-me 失効しない (由来 shard-4 F-06) → 修正: devnotes/20260713-1705-password-logout-other-devices (APPROVED)
- F-H5: 唯一オーナー削除で組織孤児化、警告なし (由来 shard-4 F-04) → 修正: devnotes/20260713-1709-sole-owner-delete-guard (APPROVED)
- F-M1: プロフィール更新/パスワード変更で成功トースト欠如 (由来 shard-4 F-01/F-02) → 修正: devnotes/20260713-1713-save-success-toast (APPROVED)
- F-L1: リカバリコード再生成トースト二重表示 (由来 shard-4 F-03) → 修正: devnotes/20260713-1713-save-success-toast (APPROVED, 同PR内)

## 走行ログ (逐次追記)

### 回帰確認: F-L1 (リカバリコード再生成トースト二重表示) → 回帰OK
- member-free@example.com でログイン→ /settings/security → 2FA有効化(TOTP secret を otpauth URL から抽出しローカルでTOTP計算)→リカバリコード再生成。
- snapshot 上、`status` region は1個のみ (`リカバリコードを再生成しました。新しいコードを保管してください。`)。以前の2重表示は解消。
- 証跡: screenshots/recovery-codes-single-toast-regression-ok.png

### 回帰確認: F-H3 (2FA disable の recent-auth 要求) → 回帰OK (重要な訂正あり、下記参照)
- まず「ログイン直後」に disable を試したところ **即座に無効化された** (recent-auth モーダルなし)。
  - コード確認 (`app/Listeners/Auth/StampRecentAuthOnLogin.php`) の結果、これは仕様どおりの挙動と判明: fresh credential login (password/TOTP/SSO, remember経由でない) 時に `recent_auth_at` を stamp する設計変更が今回のfixに含まれており、「ログイン直後の二重壁」を意図的に排除している (devnotes/20260713-1653-twofa-recent-auth で APPROVED 済み)。よってこれは finding ではない。
- 真の regression 確認のため、**remember-me 経由のstaleセッション**を作って再検証:
  1. 2FAを再有効化 → ログアウト → 「ログイン状態を保持」チェックしてログイン (TOTP要求あり、通過)。
  2. `playwright-cli cookie-delete ai-cue-session` でセッションcookieのみ削除 (remember_web_* cookieは残す)。
  3. `/dashboard` にgotoすると remember token 経由で自動再ログイン (パスワード入力なし) → この経路は `viaRemember()===true` のため `recent_auth_at` はstampされない。
  4. `/settings/security` → 「2要素認証を無効化」→ 確認ダイアログ「無効化する」をクリック。
  5. **結果: 即無効化されず、「本人確認」モーダル (パスワード再入力) が表示された** (`セキュリティのため、この操作を続けるにはもう一度本人確認が必要です。`)。2FAステータスは「有効」のまま維持。
  6. パスワード `password123` を入力し「確認する」→ pending action が resume し、2FAが正しく無効化された (ステータス「無効」に遷移)。
- 結論: **F-H3 は回帰OKと判定**。stale (recent-auth未成立) セッションでの機微操作は正しくブロックされ、fresh (ログイン直後) セッションのみ二重確認をスキップする設計どおりに動作。
- 証跡: screenshots/F-H3-regression-ok-recent-auth-modal.png

### 回帰確認: F-M1 (保存成功トースト欠如) → 回帰OK (profile更新・パスワード変更とも)
- /settings プロフィール更新 (名前変更→保存): トースト「プロフィールを更新しました。」表示を確認。
  証跡: screenshots/F-M1-regression-ok-profile-toast.png
- /settings パスワード変更: バリデーション逸脱を2回確認できた副産物あり (下記「気づき」参照)、正常系ではトースト「パスワードを変更しました。」表示を確認。
  証跡: screenshots/F-M1-regression-ok-password-toast.png
- 結論: F-M1 は回帰OK。

### 気づき (finding化しない・仕様どおりの動作): パスワードバリデーションが機能している
- 新パスワードが (1) 大文字/小文字混在必須 (2) `Password::uncompromised()` (漏洩パスワードDB既知) の場合、適切にインラインエラー表示され送信は失敗する。`newpassword456`(大文字無し) と `NewPassword456`(漏洩パスワード既知) はいずれも拒否された。UXとして適切に機能 (エラー文言も分かりやすい)。副次的に「バリデーションエラー時トーストが出ない (エラーは inline のみ)」ことも確認、これは意図通り (成功時のみトースト、失敗時はinlineエラーが適切)。
- 補足 (要確認・infra寄り): `uncompromised()` は Laravel 標準で HaveIBeenPwned k-anonymity API へ実サーバ (PHP側) から問い合わせる。ブラウザ network log には現れない (サーバ間通信のため)。TESTING_FAKE_EXTERNALS=true 環境でこれが実際に外部API を叩いているのか fake されているのかはブラウザ観測からは確認不能。bug-hunt基盤側の要確認事項として記録 (アプリバグではない)。

### 回帰確認: F-H4 (パスワード変更で他セッション失効しない) → 回帰OK (2つの独立セッションで実証)
- 検証方法 (cookie隔離の限界を state-save/state-load で克服):
  1. cookie-clear → member-free@example.com でフォームログイン (Session X 確立) → `state-save session-x.json` で認証済みcookie一式を保存。
  2. 再度 cookie-clear → 同アカウントで再度フォームログイン (Session Y、Xとは独立した別セッションID) 確立。
  3. Session Y (現在アクティブ) で /settings からパスワード変更 (`Xk9qLwPvzBugHunt2026` → `Zq7mNprXtBugHunt99`) 実行・成功 (トースト確認)。
  4. `state-load session-x.json` で Session X の cookie を復元 → `/settings` にgoto。
  5. **結果: Session X は `/login` へリダイレクトされた** (以前は認証維持されたままアクセスできていたはず)。
- 結論: **F-H4 は回帰OK**。パスワード変更時に他セッションが正しく無効化されている (`Auth::logoutOtherDevices` 相当の効果を実証)。
- 証跡: 上記手順のログ (このセッション内のコマンド出力)。screenshotは省略 (ログイン画面へのリダイレクトはURL/タイトルで十分確認できたため)。

### 回帰確認: F-H5 (唯一オーナー削除で組織孤児化・警告なし) → 回帰OK (フル救済導線まで実証)
- owner-free@example.com でログイン → /settings を開いた**時点で**(削除ボタンを押す前から) 警告 status が既に表示:
  「オーナー移譲が必要です。以下の組織であなたが唯一のオーナーです。アカウントを削除する前に、各組織で
  オーナーを別のメンバーへ移譲してください（削除時にサーバーが再判定します）。」+ 該当組織 (Freeプラン組織)
  の設定画面への直リンク。
  証跡: screenshots/F-H5-regression-ok-owner-transfer-warning.png
- 警告を無視して「アカウントを削除」→確認ダイアログ→「削除する」を実行 → **サーバー側が再判定してブロック**、
  inline alert「次の組織のオーナーであるため削除できません。先にオーナーを移譲してください: Freeプラン組織」
  表示。アカウントは削除されず、`db-check` で users=8 (不変) を確認 (以前は無警告で即削除・組織孤児化していた)。
  証跡: screenshots/F-H5-regression-ok-delete-blocked.png
- 警告リンクから組織設定へ遷移 → 「オーナー移譲」セクション (パスワード再確認が必要な旨の説明あり) で
  Free Admin へオーナー移譲 → 確認ダイアログ→実行 → 成功トースト「オーナーを移譲しました」。
  (このセッションは直前ログインで recent-auth fresh のため、F-H3 と同じ設計でパスワード再入力はスキップされた。
  stale セッションなら F-H3 と同様に再認証を挟むはず。)
- 移譲後 /settings に戻ると、削除ブロック警告が消滅 (owner-free はもう唯一オーナーではないため)、
  「アカウントを削除」ボタンのみのクリーンな状態に復帰。救済導線が実際に機能することをエンドツーエンドで確認。
- 結論: **F-H5 は回帰OK**。事前警告・delete時のサーバ再判定ブロック・オーナー移譲導線の3点セットが機能している。

### 新規探索 (逸脱アイデア「メール変更時の再認証・旧アドレス通知」から派生): F-新-01 発見
**メールアドレス変更 (`user-profile-information.update`) が recent-auth (step-up再認証) で保護されておらず、
stale (パスワード未提示) セッションでもアカウントの登録メールアドレスを変更できる。旧アドレスへの通知も無い。**

- severity: **High** (F-H3/F-H5 と同じ「機微操作が re-auth で保護されていない」class。account-takeover
  への足がかりになるため実質的インパクトはF-H3以上の可能性があり、人間トリアージでの Critical 格上げ要検討)
- story/step: S6 (逸脱探索: 「メール変更時に再認証メールが飛ぶか、変更前アドレスへ通知されるか」の検証中に発見)
- 再現手順:
  1. member-free@example.com でログイン時「ログイン状態を保持」にチェックしてログイン (password: `Zq7mNprXtBugHunt99`、
     このシャードでの走行中に変更済み。初期値は `password123`)。
  2. `playwright-cli cookie-delete ai-cue-session` でセッションcookieのみ削除 (remember_web_* は残す)。
  3. `/settings` にgoto → remember token 経由で自動再ログイン (`viaRemember()===true` のため
     `recent_auth_at` は stamp されない = stale セッション)。
  4. プロフィールの「メールアドレス」欄を別アドレス (例: `member-free-takeover@example.com`) に書き換えて「保存」。
  5. **結果: パスワード再入力・recent-auth モーダルなど一切の追加確認なしに即座に受理され、`/email/verify` へ
     遷移** (新アドレス宛の確認メールが1通送信されるのみ)。
- コード根拠: `app/Providers/FortifyServiceProvider.php` の `RECENT_AUTH_ROUTE_NAMES` は
  `two-factor.recovery-codes` / `two-factor.regenerate-recovery-codes` / `two-factor.disable` の3つのみ。
  `routes/web.php` 側で `recent-auth` middleware が付与されているのは `settings.account.destroy` と
  組織のオーナー移譲/APIキー発行系のみで、Fortify標準ルートの `user-profile-information.update`
  (`vendor/laravel/fortify/routes/routes.php:105-107`、氏名・メールアドレス変更) には
  `recent-auth` はおろか current_password 確認すら課されていない (対照的に `user-password.update` は
  `current_password` 検証を要求する)。
- 阻害されたユーザージョブ: セッション/remember-tokenを窃取した攻撃者が、被害者のパスワードを一切知らずに
  アカウントの登録メールアドレスを自分の管理するアドレスへ差し替え可能。その後「パスワードを忘れた方」から
  パスワードリセットメールを自分の新アドレスで受信すれば、パスワードも掌握でき完全なアカウント乗っ取りが
  完成する。旧アドレスへの通知が無いため被害者は変更に気付く手段がない (mail-urls で新アドレス宛の
  確認メール1通のみ確認、旧アドレス宛の警告メールは無し)。
- 改善アクション候補: `user-profile-information.update` (特にメールアドレス変更を伴う場合) に `recent-auth`
  middleware を付与する。またはメール変更成功時に旧メールアドレスへ「アドレスが変更されました」の通知メールを
  送信し、可能なら変更取り消しリンクを含める。F-H3/F-H5 と同じ「機微操作 recent-auth allowlist」に
  本ルートを追加することが最小の修正。
- 証跡: screenshots/F-01-email-change-no-recent-auth.png (変更後 `/email/verify` へ遷移した画面。
  stale session であることは上記手順のコマンドログで実証)。requests: `25. [PUT] /user/profile-information => 303`
  (直前に `cookie-delete ai-cue-session` のみでrecent-auth/password確認は一度も挟まっていない)。
- 推定原因: `FortifyServiceProvider::RECENT_AUTH_ROUTE_NAMES` および `routes/web.php` の recent-auth 付与漏れ
  (F-H3 修正時に2FA関連3ルートのみ対応し、同じ Fortify プロフィール更新ルートは対象外のままだった可能性)。
- 関連既知情報: F-H3 (2FA disable の recent-auth 欠如、修正済み) と同一パターンの取りこぼし。
  devnotes/20260713-1653-twofa-recent-auth の対応範囲が2FA関連ルートに限定されていたための漏れとみられる。

### 新規探索: notifications 画面のブラウザタブ title 未設定 (F-L2 と同パターン、新規インスタンス)
- severity: Low
- 再現手順: /notifications にアクセス → タブ title が "AI-CUE" のみ (他の設定系画面は「設定 | AI-CUE」
  「セキュリティ設定 | AI-CUE」のように画面名が付与されるのに対し、notifications だけ画面名が無い)。
- 阻害されたユーザージョブ: 複数タブを開いた際に通知タブを見分けにくい (軽微)。
- 改善アクション候補: 前回 run の F-L2 (`config/seo.php` の `app_titles`) と同じ要領で `notifications`
  ルートにも title を追加。
- 証跡: 上記 snapshot 内 `Page Title: AI-CUE` (screenshots/recovery-codes-single-toast-regression-ok.png 等の
  他画面と title 表記を比較参照)。
- 推定原因: `config/seo.php` の `app_titles` に `notifications` ルートが未登録 (F-L2 と同根、対象漏れ)。
- 関連既知情報: F-L2 (前回 shard-3 由来、6画面) の続き。今回 notifications で追加で1画面判明。

### notifications 画面のH8 (空状態) チェック → 問題なし
- 通知0件で「通知はありません」+ 次アクションの説明文あり (良好)。「すべて既読にする」ボタンを0件状態で
  クリックしても console error 無し、302 → 200 で正常応答。ただし成功トースト等の視覚フィードバックは無い
  (0件操作なので実害は軽微、finding化しない)。

### H13 (レスポンシブ) 確認: settings / settings/security を mobile 375px・tablet 768px で確認 → 問題なし
- 証跡: screenshots/settings-mobile-375-run2.png, screenshots/security-mobile-375-run2.png,
  screenshots/security-tablet-768-run2.png。横スクロール・要素はみ出し・重なり無し。desktop (1280x900) に復帰済み。

## まとめ

### 回帰確認サマリ (すべて回帰OK)
| 前回 finding | 状態 | 根拠 |
|---|---|---|
| F-H3 (2FA disable の recent-auth 欠如) | 回帰OK | stale session (remember-me経由) で本人確認モーダルが正しく出現、fresh sessionでは意図的にスキップ (設計通り) |
| F-H4 (パスワード変更で他セッション残存) | 回帰OK | 独立した2セッション (state-save/load) で実証。パスワード変更後、別セッションは/loginへ強制リダイレクト |
| F-H5 (唯一オーナー削除で組織孤児化) | 回帰OK | 削除前警告 + delete時サーバ再判定ブロック + オーナー移譲導線の3点セットが機能 |
| F-M1 (保存成功トースト欠如) | 回帰OK | プロフィール更新・パスワード変更いずれもトースト表示確認 |
| F-L1 (リカバリコード再生成トースト二重表示) | 回帰OK | トーストは1個のみ |

### 新規 finding
| ID | severity | 概要 |
|---|---|---|
| F-4-01 (F-新-01) | High | メールアドレス変更 (`user-profile-information.update`) が recent-auth 未保護。stale session でも即座にメール変更可能、account-takeoverの足がかりになりうる |
| F-4-02 | Low | notifications 画面のタブ title 未設定 (F-L2 の続き、1画面追加) |

### 気づき (finding化せず)
- パスワードバリデーション (大文字小文字混在必須 / 漏洩パスワードチェック) は適切に機能している。
- `Password::uncompromised()` の HaveIBeenPwned 問い合わせが TESTING_FAKE_EXTERNALS 環境で fake されているか
  ブラウザ観測からは確認不能 (bug-hunt基盤側の要確認事項、アプリバグではない)。

(走行終了)
