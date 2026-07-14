# bug-hunt shard-2 report (run 20260714-093524) — 3回目走行 (real-llm, 回帰確認)

- 対象 URL: http://127.0.0.1:8012 (DB: bug_hunt_2, 開始時 users=8)
- 担当ストーリー: S1 (登録/ログインファネル), S2 (招待フロー)
- 主眼: F-01 (T030, 前回 run 20260714-005157 で発見。招待未ログイン受諾の中間不整合) の回帰確認が最優先。
  F-02 (メンバーロール変更の無言失敗) の回帰確認も次点。
- ブラウザ: playwright-cli -s=bughunt2

## 回帰確認サマリ

### F-01 (T030, 前回 run 20260714-005157 Critical): **回帰OK — 修正確認**
再現手順 (前回と同一パターン):
1. owner-free@example.com (Freeプラン組織, オーナー) でログイン → `manage/users` から
   `shard2-invitee@example.com` を管理者ロールで招待 (`organizations.invitations.store`)。
2. ログアウト (未ログイン状態) → 署名付き招待リンク `http://127.0.0.1:8012/invitations/accept?token=...`
   を直接開く → `/register` へリダイレクト (招待コンテキスト session 保持)。
3. 招待と同一 email (`shard2-invitee@example.com`) で新規登録 → `email/verify` (verification.notice) →
   署名付き認証リンクで認証完了 → `dashboard?verified=1` に遷移。
4. **結果 (前回の中間不整合が解消していることを確認)**:
   - 登録直後の dashboard ヘッダー組織メニューが **即座に「Freeプラン組織」(招待先組織) を表示**
     (前回は「組織を選択/組織を作成」で無所属に見えた)。組織メニュー展開でも
     `organizations/free-nkvezp/...` (招待元と同一 slug) の各リンクが表示され、
     「組織を切り替え」セクションは出ない (= 個人組織は作られておらず所属組織が1つのみ)。
   - **チケット残高 = 0** (招待先組織の既存残高と一致。前回の「チケット10が二重付与される」
     という不整合は再現せず。`CreateNewUser::create()` の `$joined !== null` 分岐で
     `grantSignupGrant` がスキップされるコードパスと一致)。
   - 招待元オーナー側 `manage/users` の「招待中」セクションは**「有効な招待はありません」**
     (トークンが正しく消費され、二重受諾/宙ぶらりんの招待が残らない)。
   - メンバー一覧に **「Shard2 Invitee」が単一エントリ**で「管理者」ロールとして表示 (重複メンバー化なし)。
   - このセッションのまま (招待先組織の管理者ロールとして) `manage/users` に到達できたこと自体が
     「参加が即座に成立し、current_organization も正しく確定している」ことの追加証跡。
5. 証跡: `screenshots/regress-F01-post-register-org-menu.png` (登録直後 dashboard、組織メニュー展開で
   「Freeプラン組織」= 招待先組織の各リンクを表示、チケット残高0)。
- **結論: F-01 は T030 (`OrganizationMembershipService::acceptInvitationIfValid()` での
  `current_organization_id` 無条件 forceFill) により解消。中間不整合・チケット二重付与増幅とも再発なし。**

### F-02 (前回 High, organizations.members.update の無言失敗): **回帰OK — 修正確認**
再現手順: shard2-invitee (管理者ロール, プロジェクト無し組織) で `manage/users` から
Free Member の combobox を「編集者」に変更 → **今回は combobox が `[invalid]` 状態になり、
直下に赤字で「編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」というエラー文言が
即時表示される** (前回はエラー表示無しで無言のまま何も起きたように見えなかった)。
リロード後も combobox は「未割当（選択してください）」のまま (DB に保存されていないことを確認、
= 見た目もDBも一致)。ネットワーク的には `PATCH .../members/{id}` は引き続き `303 See Other` だが、
これは Inertia の標準的な validation-error-redirect-back 挙動 (session flash 経由でフロントが
再描画) であり、フロント側のエラー表示が実装されたことで H7/H10 の実害が解消されている。
証跡: `screenshots/regress-F02-member-role-inline-error.png`。
- **結論: F-02 は解消。ロール変更失敗時に inline エラー表示が実装され、無言の破棄は再現しない。**

## findings

今回走行では新規 Critical/High finding なし (F-01/F-02 とも回帰OK)。以下は「要確認」レベルの所見のみ。

### Q-03 (要確認): 招待受諾の未ログイン誘導 (`/register` へのリダイレクト) でメールアドレスが自動入力されない
- severity: 要確認 (H12寄り。使い勝手上の摩擦だが破綻ではない)
- story/step: S2-2 (invitations.accept 未ログイン分岐)
- 再現手順: 未ログインで招待リンク `http://127.0.0.1:8012/invitations/accept?token=...` を開く →
  `/register` へリダイレクトされる → メールアドレス欄は**空**。招待と異なる email で登録しようとすると
  `MatchesInvitationEmail` バリデーションで弾かれる設計 (未検証、5分調査の範囲では未確認) だが、
  そもそも「どの email で登録すべきか」がフォーム上で示されない (プレースホルダ/自動入力なし)。
- 期待: 招待された email がフォームに自動入力される (または画面上に明記される) と迷わない。
- 実際: 名前・メール・パスワード全て空欄の通常登録フォームと見た目上区別がつかない
  (「組織への招待に基づく登録です」といった文脈表示も無し)。
- 阻害されたユーザージョブ: 招待された本人が招待と違う email で登録してしまい、
  バリデーションエラーで初めて「メールアドレスを間違えた」と気づく可能性。
- 改善アクション候補: session の招待 email をフォーム初期値として渡す、または登録フォーム上部に
  「◯◯様を招待先組織へご招待します」等の文脈表示を追加。
- 証跡: 未取得 (処理優先度の都合でスクリーンショット未取得。再現手順は上記で十分)。
- 推定原因: 未調査 (`InvitationAcceptanceController::show` → `register` へのリダイレクト時に
  email を query や Inertia props で渡していない可能性)。
- 関連既知情報: 前回 run (20260714-005157) では未指摘の新規観察。F-01 修正の副産物として今回
  初めてこの経路を平常心で最後まで追えたために気づいた所見 (前回は中間不整合の方が優先度が高く
  埋もれていた可能性)。severityは「要確認」に留める (仕様として意図的に空欄という可能性を排除できないため)。

## S1 実行ログ (正常系 + バリデーション + 逸脱)
- home: CTA (料金プラン/ログイン/無料で始める) 到達確認。フッターリンク (pricing/terms/privacy/contact) 確認。
- contact: 空送信バリデーション (名前・メール・内容・同意チェックボックス4項目、それぞれ invalid + 必須文言) →
  正常系送信 → `contact/thanks` 到達確認。
- register (通常登録・招待なし): 空送信バリデーション (名前・メール・パスワード・同意、invalid+必須文言) →
  正常系登録 (`shard2-normal-reg@example.com`) → `email/verify` (verification.notice) → 再送信ボタンで
  成功トースト確認 → 署名付きリンクで認証完了 → `dashboard?verified=1`。
  **F-H1 (T021) 回帰OK: 個人組織「Shard2 Normal の組織」が作られ、チケット残高 = 10 で表示。**
- register (招待経由・未ログイン受諾): 上記回帰確認サマリの F-01 参照。
- login: 誤パスワードで「認証に失敗しました。」表示確認 (owner-pro@example.com は元々存在しないアカウントで
  試行し 401 相当のエラー文言を確認。owner-free@example.com で正しい資格情報のログイン成功も確認)。
- 未認証で `/dashboard` 直アクセス → `/login` へリダイレクト確認 (逸脱アイデア1、OK)。
- forgot-password → password.email: 空送信バリデーション → 正常系で成功トースト
  「パスワードリセット用のリンクをメールで送信しました。」確認 → 署名付きURLで `reset-password/{token}` →
  空パスワードバリデーション → 新パスワードでリセット成功 (「パスワードを変更しました。ログインしてください。」) →
  新パスワードでログイン成功。
- logout: 複数回実行、いずれもトップページへ遷移しセッション破棄を確認。
- 未実行/skip: two-factor.login / two-factor.login.store — 前回同様、割当アカウント範囲内に2FA有効化済み
  アカウントが無く、時間予算の関係で自前有効化までは未実施 (skip、理由: S6 (settings.security) 管轄と重複)。
  debug.login-as も未実行 (skip、優先度により見送り。前回と同一理由)。

## S2 実行ログ (操作カバレッジ + 逸脱)
- organizations.invitations.store: 実行 (owner-free で管理者ロール招待 x2、owner-standard で管理者ロール招待 x1、
  いずれも招待中リストに反映)。編集者/撮影者ロールでの送信は「編集者・撮影者を招待するには、先にプロジェクトを
  作成してください。」のインライン invalid 表示で弾かれることを確認 (前回はエラー文言確認のみだったが今回は
  combobox の `[invalid]` 状態 + 文言表示を screenshot 相当で確認)。
  **既存メンバーの email (multi-org@example.com) への再招待は「このメールアドレスには招待を送信できません。」
  で弾かれることを確認 (期待通りの拒否だが、メッセージが「既にメンバーです」等の具体性を欠く軽微なUX所見、
  finding化は見送り)。**
- invitations.accept (screen) + invitations.accept.store (operation):
  - **未ログイン経路 (shard2-invitee@example.com 新規登録)**: F-01 回帰確認 (上記)。
  - **ログイン済み経路 (owner-standard@example.com が Freeプラン組織へ招待を受諾)**: 「組織への招待」
    確認画面 (招待先組織名明記) → 「招待を受諾する」実行 (2連打だが2回目は遷移後で ref 消失 → 事実上の
    二重送信保護、成功トースト「『Freeプラン組織』に参加しました」1回のみ) → dashboard 反映。
    **current_organization は切り替わらず「Standardプラン組織」のまま** (T030 の設計どおり、POST受諾は
    current を切り替えない仕様と一致。組織スイッチャーで「Freeプラン組織」への切替が可能なことも確認)。
    Free側 `manage/users` で確認 → Standard Owner が単一エントリで参加 (重複メンバー化なし)。
  - **使用済みトークンの再利用 (逸脱)**: 受諾済みの招待リンクを再訪 → 「この招待リンクは使用できません」
    + ログイン/トップへの導線ありの専用画面 (詰みではない、OK)。
- organizations.members.update: F-02 回帰確認 (上記)。
- organizations.members.destroy: 実行 (確認ダイアログ「この操作は取り消せません」→ 削除実行 →
  成功トースト「メンバーを削除しました」→ 一覧から即消滅、3点セット完了)。
- organizations.members.two-factor.reset: **UI 導線が見当たらず skip** (前回と同一。`manage/users` を
  "2FA"/"二段階"/"リセット" で検索してもヒットなし。既知の未実装、regressionではない)。
- organizations.invitations.revoke: 実行 (確認ダイアログ「取り消した招待は受諾できなくなります」→
  取消実行 → 招待中リストから即消滅を確認)。
- organizations.switch: owner-standard で Standardプラン組織→Freeプラン組織へ切替実行、確認済み。
- 未実行/skip: 「撮影者ロールで受諾後、編集者専用操作 (manuals.store 等) を試す→403か」(逸脱アイデア4) —
  プロジェクト作成・撮影者ロール確立が前提となり S7 (認可境界) の管轄と重複するため、今回の主眼
  (T030/T031 回帰確認) を優先し skip。

## 画面カバレッジ
S1: home, register, login, dashboard, verification.notice, verification.verify, password.request,
password.reset, contact, contact.thanks, legal.privacy, legal.terms, legal.commerce-disclosure(直URL) →
**12/13 完全走行、1 (two-factor.login) 未走行 (理由: 上記)**。
S2: invitations.accept → **1/1 走行** (未ログイン分岐・ログイン済み分岐の両方、および使用済みトークン再訪も確認)。

## 操作カバレッジ
S1 (9 対象): register.store / login.store / logout / password.email / password.update /
verification.send / contact.store 実行。two-factor.login.store 未実行 (skip、理由記載済み)。
debug.login-as 未実行 (skip)。→ **7/9 実行**。
S2 (6 対象): invitations.accept.store / organizations.invitations.store /
organizations.invitations.revoke / organizations.members.update / organizations.members.destroy
実行。organizations.members.two-factor.reset は UI 導線不在で skip。→ **5/6 実行 (UI不在1件skip)**。

## UI/UX 検証 (H11-H14)
- H11 (視覚破綻): register/login/contact/manage-users/dashboard/招待受諾画面 いずれも崩れなし。
- H12 (アフォーダンス): F-02 修正後は combobox の invalid 状態 + エラー文言でロール変更失敗が明確に
  区別できるようになった (前回の「成功に見える失敗」問題は解消)。招待フォームの編集者/撮影者ブロックも
  同様に invalid 状態で判別可能。Q-03 (招待受諾登録フォームの email 未表示) のみアフォーダンス上の軽微な摩擦。
- H13 (レスポンシブ): mobile 375×667 で `/dashboard` (screenshots/S1-dashboard-mobile375.png)、
  tablet 768×1024 で `/manage/users` (screenshots/S2-manage-users-tablet768.png) を確認。
  いずれも横スクロール・要素はみ出し・重なりなし。確認後 desktop (1280x900) に復帰。
- H14 (a11y基礎): フォームラベル・invalid 状態のaria (textbox [invalid])・確認ダイアログの heading 構造は
  引き続き適切。詳細なコントラスト測定は未実施 (前回同様)。

## Critical/High TODO 候補サマリ
今回走行では新規 Critical/High finding なし。前回 F-01 (Critical) / F-02 (High) は両方とも修正確認済み
(T030 / member-role-update-feedback 相当の修正)。Q-03 (要確認) は severity 未確定のため TODO 候補化は保留し、
仕様確認質問リストに留める。

## 仕様確認質問リスト (バグと混ぜない)
1. Q-03: 招待受諾→未ログイン→register フローで、招待された email をフォームに自動入力 or 文脈表示する
   仕様意図はあるか (現状は空欄で通常登録と見分けがつかない)。
2. (前回 Q-01 の再掲・未再検証): `legal.commerce-disclosure` がフッターから未リンクな点は意図的な
   プレースホルダ運用か。今回は再検証していないため要否確認のみ。

## インベントリ修正提案
特になし (screens.md / operations.md との乖離は未発見)。
