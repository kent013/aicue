# bug-hunt shard-4 report (run 20260715-084108)
- 対象: S6 (security/2FA/profile/notifications) — http://127.0.0.1:8014 / DB bug_hunt_4
- 主眼: 通知センター(notifications.index, 新規), T042 パスワード表示トグル, T031 メール変更 recent-auth, F-H3/H4/H5 回帰
- 実行ストーリー: S6 (完走)
- skip したステップ: なし (全 screens/operations 到達。notifications.read のみ UI 導線が無いため直接 fetch で疎通確認に留めた。詳細は「インベントリ修正提案」参照)

## 画面カバレッジ
- 対象 screens: settings, settings.security, password.confirm, recent-auth.confirm, recent-auth.status, notifications.index
- 走行: 6/6 完走 (`/user/confirm-password` は `password.confirm` の Fortify デフォルトルートだが、アプリ側で `/recent-auth/confirm` へリダイレクトされる形で統合されていることを確認)

## 操作カバレッジ
- 対象 operations: user-profile-information.update, user-password.update, two-factor.enable, two-factor.confirm, two-factor.disable, two-factor.regenerate-recovery-codes, password.confirm.store, recent-auth.password, settings.account.destroy, notifications.read, notifications.read-all, notifications.open
- 実行: 12/12 到達 (notifications.read のみ UI ボタンが無く直接 fetch での疎通確認。他 11 件は UI 操作で正常系・異常系・回帰項目まで実施)
  - user-profile-information.update: 空名前バリデーション → 正常更新 (name) → メール変更 (stale セッションで recent-auth 要求・完了・新メール確認メール送信・旧メールへセキュリティ通知) → 直接 fetch bypass で 409 recent_auth_required (T031 確認)
  - user-password.update: 空/弱い(mixedCase欠如)/HIBP漏洩/現在パスワード不一致の各バリデーション → 正常更新。F-01 (下記) を発見
  - two-factor.enable/confirm: QRコード secret から TOTP を算出し確認・有効化・リカバリコード表示
  - two-factor.regenerate-recovery-codes: 確認ダイアログ → 再生成 (旧コードと異なる新コード表示)
  - two-factor.disable: 通常セッションでは即時無効化、stale セッションでは recent-auth 必須 (F-H3 回帰OK)、直接 fetch bypass も 409 (回帰OK)
  - password.confirm.store 相当 (`recent-auth.confirm` 画面): 誤パスワードでバリデーションエラー → 正しいパスワードで確認完了しダッシュボードへ
  - recent-auth.password: モーダル経由・フルページ経由の両方で確認 (直接 `/recent-auth/status` API も 200 で recent:true を確認)
  - settings.account.destroy: 唯一オーナーは削除ブロック (アラート表示、F-H5 回帰OK)。非オーナー (member) では確認ダイアログ→削除→ログアウト→再ログイン失敗まで完全実証
  - notifications.index/read-all/open: 招待通知を実際に発生させて一覧表示・未読バッジ・既読化・「すべて既読にする」・タブ title (「通知 | AI-CUE」T034 OK) を確認

## UI/UX 検証 (H11-H14)
- H11 (視覚破綻): 走行した全画面で崩れ・overflow・テキスト切れは未検出。
- H12 (アフォーダンス/状態): パスワード表示トグルは aria-pressed とラベル切替 (パスワードを表示⇔非表示) が適切 (T042 OK)。2FA有効/無効・リカバリコード表示/非表示のトグルも状態表現は明確。ただし F-01 (下記) で pending 中の状態表現が不能な問題を検出。
- H13 (レスポンシブ): `/settings/security` と `/notifications` を mobile 375×667・`/settings` を tablet 768×1024 で確認 (screenshots/H13-*.png)。いずれも横スクロール・要素はみ出しなし。確認後 desktop (1280×900) へ復帰。
- H14 (a11y基礎): 通知ベル・アカウント削除ボタン等に適切な aria-label / role が付与されている。フォーム入力欄はすべて label 紐付け済み (role=textbox with name)。重大な a11y 欠落は未検出。

## findings

## F-01: パスワード変更 (HIBP 漏洩チェック) が 10〜14 秒無応答になり、その間は直前の失敗エラーがそのまま表示され続けて進行中か失敗かが判別不能
- severity: High (H3 無反応 >10s / H12 状態表現不能。security-sensitive操作のため実害リスクは高め)
- story/step: S6-1 (settings, user-password.update)
- 再現手順:
  1. `http://127.0.0.1:8014` に `owner-free@example.com` / `password123` でログイン。
  2. `/settings` のパスワード変更フォームで、意図的に漏洩パスワード (例 `Password1234`) を新パスワードに入力し送信 → 「入力されたパスワードはデータ漏洩で見つかったため使用できません。」エラーが表示される (これ自体は正しい)。
  3. 続けて新しいパスワード欄だけを別の強固なランダム値 (例 `RtuSQWQN0LpdG2Yz7`) に書き換えて再送信。
  4. 送信直後 (クリック直後) に画面を確認すると、送信ボタンが `disabled` になるだけで、**直前のステップ2のエラー文言がそのまま残った状態が 10 秒以上続く** (ローディングスピナー・「送信中」等の明示的な pending 表示は無い)。`playwright-cli request` でこの PUT `/user/password` の `duration` を計測すると 10296ms〜14053ms だった。
  5. 実際には数秒後にサーバー側で成功しており (`flash.success: "パスワードを変更しました。"`、`errors: {}`)、最終的に画面は正しく更新される (エラー消去・フィールドクリア)。
  6. しかし手順4の時点だけを見たユーザーは「(ステップ2と同じ) 漏洩パスワードとして拒否された」と誤認しうる。実際に本ワーカーは初回遭遇時、この mid-flight 状態を最終状態と誤認し、`password123` (元のパスワード) でのログインが失敗すること・却下されたと表示されていたはずの値でログインが成功することを検証して初めて「実際には成功していた」と判明した。
- 期待: 送信中は失敗時の文言を残さず「送信中」等の明示的な pending 状態を表示する。少なくとも 10 秒を超える処理では進捗が分かる表示 (spinner 等、H3 の閾値) が必要。
- 実際: 直前の失敗エラー文言が pending 中もそのまま表示され、成功/失敗/処理中の 3 状態が視覚的に区別できない。
- 阻害されたユーザージョブ: パスワードを安全な値に変更する、という目的の達成可否をユーザー自身が正しく把握できない。誤って「失敗した」と思い込み、変更後の新パスワードを控えずに離脱すると、後でログインできなくなるリスクがある (実際は変更済みのため)。
- 改善アクション候補: (a) フォーム送信中は disabled だけでなく明示的な pending UI (スピナー/「変更中…」ラベル) に切り替え、直前のエラー文言は送信開始時にクリアする。(b) HIBP 照会 (`Password::uncompromised()`) の外部呼び出しがボトルネックなら、キャッシュ/タイムアウト短縮/非同期化を検討する。
- 証跡: screenshots/F-01-stuck-disabled-no-toast.png, screenshots/F-01-login-success-with-rejected-pwned-password.png / network: PUT /user/password duration 10296ms・14053ms (`playwright-cli request` で確認) / response body に `flash.success` あり
- 推定原因: `PasswordPolicy::rule()` が `App::runningUnitTests()` 以外の全環境で `Password::min(12)->mixedCase()->numbers()->uncompromised()` を適用しており (`app/Support/PasswordPolicy.php`)、`uncompromised()` は実際に HIBP へライブ HTTP 照会する。bug-hunt 環境でこれが 10 秒級のレイテンシになっており、`app/Actions/Fortify/UpdateUserPassword.php` 側にはこの遅延を吸収する pending UI 連携が (資源探索した範囲では) 見当たらない。フロント側コンポーネントは未特定 (5分調査の範囲内、詳細は未調査)。
- 関連既知情報: 禁止事項4「LLM 以外の外部接続は fake / 外部通信なし」との整合性という観点でも、この HIBP 呼び出しが bug-hunt 環境で実外部 API に生で通信している点は環境設計上の確認事項として別途「要確認」に記載する。

## インベントリ修正提案
- `notifications.read` (POST `/notifications/{notification}/read`) は operations.md / S6 カードに記載があるが、現行 UI (`resources/js/components/features/notifications/NotificationListItem.svelte`, `resources/js/molecules/NotificationBell.svelte`) にはこの単体既読化を呼ぶ導線が見当たらない (一覧の行クリックは `notifications.open`、まとめては `notifications.read-all`)。直接 fetch で疎通確認はした (存在しない ID → 404、cross-user 404 の設計どおり) が、実ブラウザ操作としては未到達。将来 UI が追加されるまでは「実装済みだが UI 未配線」として要確認に留める (finding 化はしない: 実害・破綻ではないため)。

## 要確認 (仕様不明)
- **HIBP (Have I Been Pwned) へのライブ外部 HTTP 通信**: `app/Support/PasswordPolicy.php` の `PasswordPolicy::rule()` は `App::runningUnitTests()` でない限り `Password::uncompromised()` を有効化しており、パスワード変更のたびに実際に外部 API へ照会している (F-01 の 10〜14 秒の応答時間から確認)。SKILL.md 禁止事項4「許可する実外部接続は LLM プロバイダ API ドメインのみ、他は fake/外部通信なし」という bug-hunt 環境設計方針と、この HIBP 実通信が整合しているか要確認 (本ワーカーはブラウザの egress ガード内に留まっており、この通信はサーバー側 (PHP) が発生させたものなので直接の禁止事項違反ではないが、環境設計として意図的か未整理かの確認を推奨)。

## Critical/High TODO 候補サマリ (app-design → app-todo-add 用)
1. **F-01 (High)**: パスワード変更が HIBP 照会で 10〜14 秒無応答になり、その間に直前の失敗エラー文言が残ったまま pending 状態が視覚的に判別不能。実際には成功しているのに失敗したと誤認しうる。
   - 再現: 本レポート F-01 参照 (`/settings` パスワード変更フォーム)。
   - 阻害されたユーザージョブ: 安全なパスワードへの変更成否を正しく把握する。
   - 改善アクション候補: 送信中の明示的 pending UI 化 + 直前エラーのクリア。HIBP 呼び出しのタイムアウト短縮/キャッシュ化も検討。
   - 関連ファイル: `app/Support/PasswordPolicy.php`, `app/Actions/Fortify/UpdateUserPassword.php`, パスワード変更フォームのフロントコンポーネント (未特定)。

## 走行終了
- 全 6 screens / 12 operations (うち1件は直接fetch疎通のみ) を完走。F-H3 (2FA無効化 recent-auth) / F-H4 (パスワード変更で他セッション失効、直接は未再検証だが `Auth::logoutOtherDevices` 実装確認済み) / F-H5 (唯一オーナー削除ガード) はいずれも回帰なし。T026 (単一トースト) / T031 (メール変更 stale セッション recent-auth) / T034 (通知タブ title) / T042 (パスワード表示トグル) はすべて期待通り。新規 finding は F-01 (High) の1件。
- `playwright-cli close` 実行済み (bughunt4 セッション終了)。serve/teardown は行っていない (親の管轄)。
