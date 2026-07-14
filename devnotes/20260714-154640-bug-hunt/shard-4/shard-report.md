# bug-hunt report shard-4 (run 20260714-154640, real-llm 2nd)
- Session: playwright-cli -s=bughunt4
- Story: S6 (security / 2FA / profile / notifications)
- 主眼: F-4-03 (T042: パスワード表示トグル追加) の回帰確認 + F-4-01 (email変更 recent-auth) / F-H3/H4/H5 /
  F-4-02 (notifications タブ title) の軽再確認 + 新規探索
- 前回 run: 20260714-093524 (shard-4/S6)

## 走行ログ (逐次追記)

### 回帰確認: F-4-03 (T042: /settings パスワード変更欄に「パスワードを表示」トグル追加) → **回帰OK (修正確認)**
- member-free@example.com / password123 でログイン → `/settings` へ遷移。
- 「パスワード変更」セクションの「現在のパスワード」「新しいパスワード」いずれの入力欄にも
  `button "パスワードを表示"` が追加されていることを snapshot で確認 (前回 run 20260714-093524 では欠落)。
- 「新しいパスワード」欄に `TestPassInput123` を入力 → 型は `password` (`el.type` eval で確認)。
- トグルボタンをクリック → snapshot 上ボタンラベルが `パスワードを表示` → `パスワードを非表示` [active] [pressed]
  に切替、textbox の表示値が平文で見える状態に変化 (type=text 相当)。ログイン画面のトグルと同じ挙動・
  aria state (pressed) が一貫している。
- console error 0件、network も静的アセット以外の 4xx/5xx なし。
- 証跡: screenshots/F-4-03-regression-fixed-password-toggle.png
- 結論: **修正確認 (T042 反映済み)**。ログイン画面とのUX一貫性が回復し、新パスワード入力時の打ち間違い確認が可能になった。

### 軽再確認: F-4-01 (T031: メール変更が recent-auth 未保護) → 回帰OK
- member-free@example.com / password123 で「ログイン状態を保持」チェックしログイン (2FA有効化直後だったため
  `/two-factor-challenge` を挟んだ。secret を QR レスポンスの otpauth URL から抽出しローカル TOTP 計算)。
- `playwright-cli cookie-delete ai-cue-session` でセッションcookieのみ削除 → `/settings` にgoto → remember token
  経由で自動再ログイン (stale session)。
- メールアドレス欄を `member-free-takeover@example.com` に書き換え「保存」→ **即座に変更されず「本人確認」モーダル
  表示** (「セキュリティのため、この操作を続けるにはもう一度本人確認が必要です。」)。パスワード `password123` 入力
  →確認→ `/email/verify` へ遷移 (新アドレスはまだ未確認)。
- `mail-urls --count 5` で確認 URL を取得し踏むと `/settings` へリダイレクトされ検証完了。
- 結論: **回帰OK**。前回 (20260714-093524) と同じ挙動を再現。stale session からの email 変更は re-auth で
  正しくブロックされる。
- 証跡: screenshots/F-4-01-regression-ok-recent-auth-modal.png

### 軽再確認: F-H3 (2FA disable の recent-auth 要求) → 回帰OK
- 同一 stale session (`cookie-delete ai-cue-session` → `/settings/security`) で「2要素認証を無効化」→確認ダイアログ
  「無効化する」→ **即無効化されず「本人確認」モーダル表示**、パスワード `password123` 入力後に無効化完了
  (ステータス「無効」に変化、「有効化」ボタンが再表示)。
- 結論: 回帰OK (前回と同じ挙動)。
- 証跡: screenshots/F-H3-regression-ok-2fa-disable-modal.png

### 軽再確認: F-L1 (リカバリコード再生成トースト二重表示) → 回帰OK
- 2FA を再有効化 (QR レスポンスから secret 抽出しローカル TOTP 計算) → リカバリコード再生成 (確認ダイアログ
  「再生成する」)。
- snapshot 上、`status` region は1個のみ (「リカバリコードを再生成しました。新しいコードを保管してください。」)。
- 結論: 回帰OK。

### 軽再確認: F-4-02 (T034: notifications タブ title) → 回帰OK
- `/notifications` へ遷移 → Page Title: 「通知 | AI-CUE」。他の設定系画面と同じ「画面名 | AI-CUE」形式。
- 結論: 回帰OK。

### 軽再確認: 通知 (notifications) 操作の健全性 → 特に異常なし
- `/notifications` (0件・空状態): 「通知はありません」+ 説明「ジョブの完了・招待・チケット残高の通知がここに
  表示されます。」で H8 (空状態の説明) OK。「すべて既読にする」ボタンをクリックしても
  (`POST /notifications/read-all => 302`) console error なし、成功トースト「すべての通知を既読にしました」表示。
  0件時にボタンを押してもエラーにならない (堅牢)。

### 軽再確認: F-H4 (パスワード変更で他セッション失効) → 回帰OK
- member-free-takeover@example.com (2FA有効) で cookie-clear → フォームログイン+TOTP認証 (Session X 確立) →
  `state-save session-x.json`。
- 再度 cookie-clear → 同アカウントで再ログイン+TOTP (Session Y、独立セッション) → `/settings` からパスワード変更
  (`password123` → `NewPassBugHunt2026x`) 実行 (`PUT /user/password => 303`)。
- `state-load session-x.json` で Session X の cookie を復元 → `/settings` にgoto → **`/login` へリダイレクト
  された** (Session X は無効化されている)。
- 結論: 回帰OK。パスワード変更時に他セッションが正しく失効する。

### 探索: `settings.account.destroy` の成功系 (非オーナーMember) → 正常動作
- member-free-takeover@example.com (Free組織の Member ロール、オーナーではない) で 2FA+password ログイン
  (fresh session, recent-auth 済み) → `/settings` の「アカウントを削除」→確認ダイアログ
  「本当にアカウントを削除しますか？すべてのデータが完全に失われ、この操作は取り消せません。」(H7 確認あり) →
  「削除する」実行 (`DELETE /settings/account => 303`) → ログアウトされ landing page (`/`) へリダイレクト。
  `db-check` で users が 8→7 に減少したことを確認 (実際に削除された)。console error なし。
- 結論: 非オーナー Member のアカウント削除は正常に動作 (確認ダイアログあり、実行後ログアウト+landingへ遷移、
  DBからも削除)。

### 軽再確認: F-H5 (唯一オーナー削除で組織孤児化) → 回帰OK
- owner-free@example.com でログイン → `/settings` を開いた**時点で**警告 status 表示:
  「オーナー移譲が必要です。以下の組織であなたが唯一のオーナーです。アカウントを削除する前に、各組織で
  オーナーを別のメンバーへ移譲してください（削除時にサーバーが再判定します）。」+ Freeプラン組織設定への直リンク。
- 警告を無視して「アカウントを削除」→確認ダイアログ「削除する」実行 → **サーバー側が再判定してブロック**、
  inline alert「次の組織のオーナーであるため削除できません。先にオーナーを移譲してください: Freeプラン組織」表示。
  `db-check` で users=7 (不変) を確認、アカウントは削除されていない。
- 結論: 回帰OK (事前警告 + delete時サーバ再判定ブロックの2点セットが機能)。
- 証跡: screenshots/F-H5-regression-ok-delete-blocked.png

### 軽再確認: H13 (レスポンシブ) → 異常なし
- `/settings` を mobile 375x667、`/settings/security` を tablet 768x1024 で確認。`document.documentElement.scrollWidth`
  が viewport 幅と一致 (横スクロールなし)。要素の重なり・はみ出しなし。確認後 desktop (1280x900) に復帰済み。
- 証跡: screenshots/H13-mobile-375-settings.png, screenshots/H13-tablet-768-security.png

### 軽再確認: F-M1 (パスワード変更成功トースト) → 回帰OK (前回は観測タイミングで捕捉できなかった)
- owner-free@example.com で `/settings` からパスワード変更 (`password123` → `NewOwnerPass2026x`) 実行直後に
  snapshot → **status region「パスワードを変更しました。」を確認**。前回 run (20260714-093524) では取得
  タイミングの問題で確認できなかった箇所が今回は確認できた。
- 結論: 回帰OK (トースト表示は正しく機能している)。

### 深掘り (逸脱): recent-auth のサーバー側 (middleware) 強制を直POSTで確認 → 回帰OK (client-side回避不可)
- owner-free@example.com で remember-meログイン→`cookie-delete ai-cue-session`で stale session化→ `/settings`
  にgoto (UIは開くがJSは操作していない) → `playwright-cli eval` でブラウザの `fetch()` を使い **UIのモーダルを
  一切経由せず** `PUT /user/profile-information` に直接 `{name, email: 'direct-post-bypass@example.com'}` を
  XSRF token 付きで送信。
- **結果: `HTTP 409` + `{"code":"recent_auth_required","message":"この操作には直近の再認証が必要です。","redirect":".../recent-auth/confirm"}`** で拒否された。`db-check` で users=7 (メール変更されず) を確認。
  console に 409 の「Failed to load resource」ログが1件出るが、これはブラウザが非2xxレスポンスに対して出す
  標準ログでありアプリ側のJSエラーではない (finding化せず)。
- 結論: **回帰OK、かつ堅牢**。修正はサーバー側 middleware で強制されており、client-side JS を回避した直接POSTでも
  ブロックされる。

### 探索: `/user/confirm-password` (Fortify標準 password.confirm) → `/recent-auth/confirm` に収束 + バリデーション確認
- `http://127.0.0.1:8014/user/confirm-password` に直接goto → **`/recent-auth/confirm` へリダイレクト**され同一UI
  「本人確認」に収束 (前回 run のインベントリ提案どおり)。この画面の「現在のパスワード」欄にも
  **F-4-03 で追加されたトグルと同じ「パスワードを表示」ボタンが実装されている**ことを確認 (settings.md 側だけで
  なく recent-auth.confirm 画面にも一貫適用されている、良い横展開)。
- 空欄で「確認する」→ 「パスワードは必須項目です。」+ invalid state。誤ったパスワードで送信 →
  「パスワードが正しくありません。」。正しいパスワードで送信 → `/dashboard` へリダイレクト (pending action
  無しの場合のデフォルト遷移先)。バリデーション・エラーメッセージともに適切。

### 探索: バリデーション確認 (プロフィール名前欄・空入力)
- owner-free@example.com で `/settings` の「名前」欄を空にして「保存」→ inline エラー「名前は必須項目です。」+
  `[invalid]` state 表示、送信ブロック。正常値に戻して再保存すると正しく反映 (reload後も "Free Owner" のまま)。
  異常なし。

## まとめ

### 走行サマリ
- 実行ストーリー: S6 (完走)。skip したステップ: なし。
- 画面カバレッジ: 5/5 (settings, settings.security, password.confirm→recent-auth.confirm へ収束, recent-auth.confirm, recent-auth.status)
- 操作カバレッジ: 9/9 (user-profile-information.update [氏名のみ/メール変更/空入力バリデーションの3パターン],
  user-password.update, two-factor.enable, two-factor.confirm, two-factor.disable,
  two-factor.regenerate-recovery-codes, password.confirm.store, recent-auth.password,
  settings.account.destroy [非オーナー成功系 + オーナーブロック系の両方])
- UI/UX 検証:
  - H11 (視覚破綻): 特になし。
  - H12 (アフォーダンス/状態): F-4-03 (前回発見のパスワード表示トグル欠如) が修正され、settings 画面だけでなく
    recent-auth.confirm 画面にも一貫適用されていることを確認。2FA有効/無効バッジ、delete-buttonの状態は明瞭。
  - H13 (レスポンシブ): /settings を mobile 375x667、/settings/security を tablet 768x1024 で確認、
    横スクロール・要素はみ出し・重なり無し。screenshots/H13-mobile-375-settings.png,
    screenshots/H13-tablet-768-security.png。確認後 desktop (1280x900) に復帰済み。
  - H14 (a11y基礎): モーダルの見出し構造・フォーカス可能な閉じるボタンあり、button/textbox の name/role・
    invalid state・pressed state は snapshot で正しく取得できた (aria欠落の兆候なし)。
- findings: Critical 0 / High 0 / Medium 0 / Low 0 / 要確認 0 (新規 finding なし。前回の F-4-03 は修正確認)

### 回帰確認サマリ (すべて回帰OK。F-4-03 は修正確認)
| 前回 finding | 状態 | 根拠 |
|---|---|---|
| F-4-03 (T042: /settings パスワード表示トグル欠如) | **修正確認** | 現在のパスワード・新しいパスワード両欄にトグル追加、クリックでpassword↔text切替+aria pressed state確認。recent-auth.confirm画面にも横展開されている |
| F-4-01 (T031: メール変更が recent-auth 未保護) | 回帰OK | stale session で email 変更 → 本人確認モーダル必須。サーバー側 middleware も直POSTで再検証し `409 recent_auth_required` を確認 (client-side回避不可) |
| F-4-02 (T034: notifications タブ title) | 回帰OK | Page Title「通知 \| AI-CUE」に修正済み |
| F-H3 (2FA disable の recent-auth 欠如) | 回帰OK | stale session で本人確認モーダル必須、fresh session はスキップ (設計通り) |
| F-H4 (パスワード変更で他セッション残存) | 回帰OK | 独立2セッションで実証、変更後に別セッションは /login へ強制リダイレクト |
| F-H5 (唯一オーナー削除で組織孤児化) | 回帰OK | 事前警告 + delete時サーバ再判定ブロックの2点セットが機能、users数不変 |
| F-M1 (保存成功トースト欠如) | **回帰OK (今回完全確認)** | パスワード変更成功トースト「パスワードを変更しました。」を今回捕捉 (前回はタイミングで未確認) |
| F-L1 (リカバリコード再生成トースト二重表示) | 回帰OK | トーストは1個のみ |

### 新規 finding
- なし。

### 追加確認 (finding化せず、健全性の記録)
- `settings.account.destroy` の成功系 (非オーナー Member) を実際に実行し、確認ダイアログ→実行→ログアウト+
  landingへ遷移→DB上も削除 (users 8→7) を確認。前回 run はブロック系のみの検証だったため、今回で両方を確認。
- notifications の空状態 (0件) は説明文つきで H8 良好、「すべて既読にする」を0件時に押してもエラーにならない。
- `/user/confirm-password` は `/recent-auth/confirm` に収束する仕様であることを確認 (前回のインベントリ提案どおり)。
- プロフィール名前欄・recent-auth パスワード欄の空入力/誤入力バリデーションは適切なメッセージ表示。

### インベントリ修正提案
- `screens.md` の `user/confirm-password (password.confirm)` は実際には `recent-auth.confirm` へリダイレクトし
  同一UIに収束することを注記すると、次回以降の探索者が「2画面を別々に検証する必要がある」と誤解しないで済む
  (前回 run 由来の提案を再確認。まだ未反映であれば統合レポート作成時に反映を推奨)。

### Critical/High 要約 (TODO候補)
- 該当なし。今回検出した新規 finding はゼロ。前回 Low (F-4-03) は修正確認済み。

(走行終了)
