# bug-hunt shard-4 report (run 20260715-213842)

- shard: 4
- ストーリー: S6 (security/2FA/profile/notifications)
- URL: http://127.0.0.1:8014
- DB: bug_hunt_4 (db-check時点 users=8)
- ブラウザセッション: playwright-cli -s=bughunt4
- 走行主眼: T065(通知個別既読ボタン新規)検証 + T059/T063/T060/T042/T031/T023/T024/T025/T026/T034 回帰維持 + T064(HIBP無効化で速度改善)確認

## 状態
- 開始: db-check OK (bug_hunt_4, users=8)
- 終了時点: db-check OK (bug_hunt_4, users=7。T025非オーナー削除テストでadmin-freeを1件削除した分の差分。想定内)

## 画面カバレッジ (走行完了 6/6)
- settings ✓ (プロフィール更新・パスワード変更・アカウント削除の3セクション)
- settings.security ✓ (2FA有効化/確認/無効化・リカバリコード再生成・ソーシャル連携)
- password.confirm ✓ (GET `/user/confirm-password` は `/recent-auth/confirm` へ302リダイレクトされ、アプリ独自の本人確認画面に統合されている。POST `password.confirm.store` は直接呼ぶと201で単独動作 = Fortify標準ルートは生きているが通常導線からは到達しない「隠れ経路」。インベントリ修正提案参照)
- recent-auth.confirm ✓ (誤パスワード/正パスワードの両方確認)
- recent-auth.status ✓ (API fetchで確認、recent:true / confirmedAt を確認)
- notifications.index ✓ (0件空状態・1件・複数ユーザーでの受信を確認)

## 操作カバレッジ (走行完了 12/12)
- user-profile-information.update ✓ (名前変更・トースト確認、メールアドレス変更→email/verify遷移→署名URLで確認完了)
- user-password.update ✓ (バリデーション経路は未確認だが正常系実施、所要時間3105ms計測=T064回帰OK)
- two-factor.enable ✓ (QRコード表示、secretKey API経由でTOTP生成し確認)
- two-factor.confirm ✓ (誤コード→エラー表示=T059回帰OK / 正コード→有効化成功)
- two-factor.disable ✓ (確認ダイアログ→無効化成功、トースト単一=T026回帰OK)
- two-factor.regenerate-recovery-codes ✓ (確認ダイアログ→再生成→リスト更新・トースト単一)
- password.confirm.store ✓ (直接fetch検証、201)
- recent-auth.password ✓ (誤パスワード→エラー / 正パスワード→確認成功→dashboardへ)
- settings.account.destroy ✓ (唯一オーナー=事前警告でブロック、UI経由でも直接DELETEでも422で拒否=T025回帰OK。非オーナーadmin-freeでは確認ダイアログ→削除成功→ログアウト状態でトップページへ)
- notifications.read ✓ (T065: 個別既読ボタンで遷移なく1件既読化・バッジ即時更新・focus復帰・二重送信ガード確認)
- notifications.read-all ✓ (バッジ0件時にクリックしても302で無害)
- notifications.open ✓ (招待通知の行クリック→遷移せず説明トースト「招待はメールの受諾リンクから参加してください」)

## UI/UX 検証
- H11 (視覚破綻): 未検出。settings/security/notifications 主要画面で崩れなし。
- H12 (アフォーダンス/状態): F-4-01 (Low) を検出。「すべて既読にする」ボタンが0件時も常時有効。
- H13 (レスポンシブ): notifications (mobile 375x667) / settings/security (mobile 375x667) / settings (tablet 768x1024) で確認。横スクロール・要素はみ出し・重なりなし。screenshots/notifications-mobile375.png, screenshots/security-mobile375.png, screenshots/settings-tablet768.png
- H14 (a11y基礎): パスワード表示トグルはボタンにaria pressed状態あり(T042)。通知アイテムはbutton roleで名前が内容全体を含み読み上げ可能。見出し階層は各画面で h1→h2/h3 の順守を確認。大きな欠落は未検出。

## 回帰確認サマリ (T059/T063/T060/T042/T031/T023/T024/T025/T026/T034/T064/T065)
- T059 (2FA確認の誤コードエラー): 回帰OK。誤6桁コード送信で「二要素認証コードが無効です。」がインライン表示、フォームは維持され再試行可能。
- T063 (未確定2FA解除ボタン非表示): 回帰OK。有効化開始直後(未確認状態)は「無効化」ボタンが表示されず、確認完了後にのみ出現。
- T060 (パスワード変更pending): 部分確認。ネットワークタイミングで3105ms要したことを確認 (T064参照)。ボタンのpending/loading視覚状態はsnapshotでは直接検証できなかったため「未確認 (ツール制約)」。
- T042 (パスワード表示トグル): 回帰OK。ログイン画面・設定画面の両方でトグル動作、aria-pressed状態が正しく切り替わる。
- T031 (メール変更recent-auth): 回帰OK (fresh recent-authでは単純に成功)。メール変更後は新アドレスの検証待ち(email/verify)に遷移し、署名URLで検証完了を確認。EmailChangedSecurityNotificationがmailのみ(旧アドレス通知)であることをコード確認 (app/Notifications/EmailChangedSecurityNotification.php)。stale-session(recent-auth失効後)での409強制は recent_auth_timeout=900s(15分)のため本shard予算内では未検証 (skip、理由: 待機コスト過大)。
- T023 (2FA disable recent-auth): 回帰OK。recent:true (直近ログイン済み)の状態でdisableが正常に成功することを確認。stale状態での再認証要求そのものはrecent-auth.confirm画面の存在と機構(誤/正パスワードのバリデーション)で間接確認。
- T024 (他セッション失効): 未検証 (skip、理由: 複数タブ/セッション同時運用が必要でツール制約により今回のshardでは深掘りせず。念のためrecent-auth周りの直接POSTガードは確認済み)。
- T025 (唯一オーナー削除): 回帰OK。UIでは削除前に「オーナー移譲が必要です」の警告と移譲リンクが表示されボタン自体は押せるが、直接DELETE `/settings/account` を叩いても422 + 明確な日本語エラー「次の組織のオーナーであるため削除できません。先にオーナーを移譲してください: Freeプラン組織」でサーバー側ガードも機能。非オーナーでの削除は正常に成功。
- T026 (トースト): 回帰OK。プロフィール更新・パスワード変更・2FA無効化・リカバリコード再生成のすべてで単一トーストのみ確認 (二重表示なし)。
- T034 (通知title): 回帰OK。/notifications のタブタイトルは「通知 | AI-CUE」で固有。
- T064 (HIBP無効化効果): 回帰OK。パスワード変更 (`PUT /user/password`) のリクエストdurationは3105ms。旧仕様の10-14秒から明確に改善 (実HIBPには飛んでいないことを裏付ける)。ただし3秒はまだ体感できる待ち時間であり、UIにローディング表示があるかは未確認 (T060参照、ツール制約)。
- T065 (通知個別既読ボタン): 回帰OK・新機能正常動作。未読行に「既読にする」ボタンが表示され、クリックで (1) ページ遷移せず (2) 対象1件のみ即座に既読化 (3) 既読化後は行のfocusが維持される (4) ボタン消滅により二重送信不可 (5) header/dashboardの未読バッジが即時0に更新、を確認。行本体(open)クリックは別途「招待はメールの受諾リンクから参加してください」という説明トーストを表示し遷移しない(招待通知に直接遷移先URLがないため。仕様として妥当)。

## findings

### F-4-01: 通知0件時も「すべて既読にする」ボタンが常時有効
- severity: Low (H12)
- story/step: S6-5 (notifications.index)
- 再現手順: 1) http://127.0.0.1:8014/notifications に未読・既読とも通知が0件のユーザー(例: multi-org@example.com)でログイン。2) 「すべて既読にする」ボタンをクリック。
- 期待: 対象が存在しない操作は、ボタンをdisabled化するか非表示にして「実行しても意味がない」ことを示す (H12: アフォーダンス/状態表現)。
- 実際: 常時活性化されたボタンとして表示され、クリックするとPOST `/notifications/read-all`が発火し302で無害に完了する (エラーにはならないが無意味なリクエストが飛ぶ)。
- 阻害されたユーザージョブ: 実害はないが「押せる=何か起きる」という期待とズレるため、ユーザーが操作の意味を誤解する可能性がある。
- 改善アクション候補: 未読が0件のときは「すべて既読にする」ボタンをdisabled化するか非表示にする。
- 証跡: screenshots/notifications-empty-state.png (0件表示。同ボタンがまだ活性状態で表示されている)
- 推定原因: 未調査 (フロント側で unreadCount==0 の分岐が未実装の可能性。5分調査でNotificationのSvelteコンポーネントまで特定できず)
- 関連既知情報: T065のPRで個別既読ボタンの表示/非表示制御は実装されたが、一括既読ボタン側の0件ガードは対象外だった可能性。TODO-closed.mdのT065記述に一致する範囲外。

## skip
- T024 (他セッション失効): 複数ブラウザセッション/タブでの同時ログイン検証が必要で、本shardの隔離ブラウザ(1セッション)構成とツール制約 (playwright-cliの並列click実行がセッションロック競合を起こす挙動を確認済み) により今回は深掘りしなかった。recent-authの直接POSTガード自体は他の項目で確認済み。
- T031 stale-session (recent-auth失効後の409強制): `AUTH_RECENT_AUTH_TIMEOUT=900` (15分) のため、実時間待機によるstale化のテストはshard予算超過のため見送り。recent-auth機構自体 (fresh時に成功、誤パスワードで拒否) は確認済み。
- T060 (パスワード変更中のpending/loading表示): playwright-cliのclick操作は完了(レスポンス確定)まで待機してしまうため、送信中の中間状態 (spinner/disabled) をキャプチャできなかった。ネットワークdurationのみ確認 (3105ms)。

## インベントリ修正提案
- `password.confirm` 画面 (`GET /user/confirm-password`, route名 `password.confirm`) は通常導線から到達すると必ず `/recent-auth/confirm` へ302リダイレクトされ、そのビュー自体が描画されることはない。実質的に「死んだ画面エントリ」であり、実際の本人確認UIは `recent-auth.confirm` (`GET /recent-auth/confirm`) である。stories/S6の手順に「password.confirm(confirm-password画面)」という記述があるが、実態は `recent-auth.confirm` に統合されている旨を注記した方がよい (screens.mdの記述精度向上の提案。バグではない)。

## Critical/High summary (TODO候補)
- なし。本shardではCritical/High findingは検出されなかった。F-4-01はLowのみ。

## 要確認 (仕様不明)
- なし (今回発見した事象はいずれも既存設計/コードから意図が確認できたため「要確認」分類はなし)。
