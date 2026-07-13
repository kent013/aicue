# bug-hunt shard-2 report (run 20260714-005157) — 2回目走行(回帰確認)

- 対象 URL: http://127.0.0.1:8012 (DB: bug_hunt_2, 開始時 users=8)
- 担当ストーリー: S1 (登録/ログインファネル), S2 (招待フロー)
- 主眼: F-C1 (T019 組織ナビ導線) / F-H1 (T021 登録チケット付与) の回帰確認 + 新規探索
- ブラウザ: playwright-cli -s=bughunt2 (前回 run 20260713-085818 の shard-2 を参照して回帰点を追跡)

## 回帰確認サマリ (最重要)

### F-C1 (T019 組織ナビ導線): **回帰OK — 修正確認**
AppLayout ヘッダーに組織メニュー(「{組織名}」ボタン)が追加され、クリックで
組織設定 / メンバー管理 / API キー / 請求 / 料金 への恒常リンクが表示される
(`http://127.0.0.1:8012/organizations/{slug}/settings` 等)。さらに複数組織所属時は
「組織を切り替え」セクションで所属組織一覧から切替可能 (前回 F-C2 相当の組織スイッチャーも併せて
実装済みと確認)。これにより S2 の中核操作 (招待送信/取消, メンバー管理) がすべて UI 到達可能になった。
証跡: `screenshots/regress-01-org-menu-dashboard.png`。

### F-H1 (T021 登録チケット付与): **回帰OK — 直接登録では修正確認**
`/register` → メール認証完了 (通常の新規登録、招待なし) の直後、dashboard の「チケット残高」が **10**
と表示された (以前は 0)。ticket bonus の告知コピー(「新規登録でチケット10枚が無料」)と一致。
証跡: 上記 dashboard snapshot (チケット残高: "10")。
ただし、**招待フロー経由の登録で「二重付与増幅防止」が破れているケースを新規発見** (下記 F-01 参照)。

## 新規 Critical finding (F-H1 の修正と S2 招待フローの相互作用)

## F-01: 未ログイン状態の招待受諾リンクがコンテキストを喪失し、招待経由ユーザーにも登録チケット特典が二重付与される (二重付与増幅防止の破れ)
- severity: **Critical**
- story/step: S2 (invitations.accept 経路, 未ログイン分岐) × F-H1 (registration-ticket-grant)
- 再現手順:
  1. オーナー (例: 新規作成した組織オーナー) で `manage/users` からロール「管理者」でメール
     `invitee@example.com` を招待 (`organizations.invitations.store`)。
  2. ブラウザを未ログイン (ログアウト状態) にして、メールの署名付き招待リンク
     `http://127.0.0.1:8012/invitations/accept?token=...` を直接開く。
  3. `/register` へリダイレクトされる (`InvitationAcceptanceController::show` が session に
     `invitation_token` を保存して register へ誘導する設計)。招待された email と**同一の** email で
     新規登録 (名前・パスワードを入力) → 利用規約同意 → 登録実行。
  4. メール認証 (`email/verify/{id}/{hash}`) リンクを踏んで認証完了 → `dashboard?verified=1` に遷移。
- 期待 (設計文書 `devnotes/20260713-1637-registration-ticket-grant/detailed-design.md` §施策3 より):
  招待 token 経由の登録は `CreateNewUser::create()` が session の `invitation_token` を読み、
  `acceptInvitationIfValid` が成立すれば**個人組織を作らず、signup grant (10枚) も付与しない**
  (「招待 N 人 = N×10 の増幅を避ける」とコメントで明記された設計意図)。
- 実際:
  - 登録直後の dashboard で **チケット残高 = 10** (signup grant が付与されている)。
  - にもかかわらず、同じ画面のヘッダー組織メニューは **「組織を選択」→「組織を作成」のみ**
    (どの組織にも所属していない状態) を表示。招待した組織 (「回帰確認 太郎 の組織」) にも
    **参加できておらず**、個人組織も作られていない — 設計上想定される 2 分岐
    (「招待成功=参加のみでグラント無し」/「招待失敗=個人組織作成+グラント」) の**どちらにも
    一致しない中間的な不整合状態**。
  - この状態で招待リンク (`/invitations/accept?token=...`) を再訪すると「この招待リンクは使用できません」
    (使用済み扱い) になっており、`invitations.accept.store` (POST) が実際に呼ばれた形跡はない
    (S2 の中核操作がこの経路では一度も実行されないまま招待が消費されている)。
  - 後日 (別セッションで) 通常ログインし直すと、ヘッダーに招待先組織名が表示され、実際には
    その組織のメンバーとして参加できていたことが判明 (オーナー側の `manage/users` メンバー一覧にも
    表示される)。つまり参加自体は (別経路・タイミングで) 成立しているが、**登録直後の画面では
    無所属としか見えず**、かつ **signup grant 10 枚が (参加が最終的に成立したにもかかわらず) 支給
    されたまま** になっている。
- 阻害されたユーザージョブ: (a) 招待された新規ユーザーが登録直後に「どの組織にも属していない」という
  誤った状態を見せられ、二重に組織作成を試みかねない (実際には既に参加済み)。(b) 運用上は
  「招待 N 人 = N×10 チケット増幅」を防ぐはずの設計不変条件が、未ログイン受諾という主要経路で
  破れており、招待を乱発することで組織のチケット残高を不正に積み増せる余地がある。
- 改善アクション候補:
  1. 招待経由の未ログイン登録で `invitation_token` が session に正しく伝播し、
     `acceptInvitationIfValid` が成立しているかを結合テストで検証する
     (今回の手動再現では 5 分調査で断定できず、フロントの Inertia 遷移で session cookie が
     正しく引き継がれているか / `MatchesInvitationEmail` rule 通過後の分岐が想定通りか要コード追跡)。
  2. 登録直後 dashboard の組織表示が、実際の DB 上のメンバーシップと一致するまで
     (stale なショートリブ props ではなく) 確定してから遷移させる。
  3. 招待経由と判定できた場合は signup grant を確実にスキップし、既に付与済みなら是正 (取消/相殺)
     する運用手当ても検討する。
- 証跡: `screenshots/F-01-invitation-lost-no-org.png` (登録直後dashboard、チケット10・組織なし)、
  `screenshots/F-01b-invite-link-invalid-after-consumption.png` (招待リンク再訪で使用済み表示)、
  `screenshots/F-01c-invitee-fresh-login-org-appears.png` (再ログイン後に組織が見える)。
- 推定原因: 未調査 (`app/Actions/Fortify/CreateNewUser.php` の `resolveInvitationToken`/
  `acceptInvitationIfValid` 分岐と、Inertia 登録→メール認証→dashboard 遷移の間での
  session/共有 props 反映タイミングの相互作用を要追跡。5分の追跡では断定に至らず)。
- 関連既知情報: `devnotes/20260713-1637-registration-ticket-grant/detailed-design.md` (今回の
  F-H1 修正 PR 本体)。この PR が「招待経由は付与しない」ロジックを実装した張本人であり、
  **本 finding はその不変条件が招待の未ログイン受諾経路で意図通り機能していない**ことを示す。
  regression ではなく既存の未カバー経路 (前回走行は F-C1 によりこの経路自体に到達できなかったため
  今回が初検証)。

## New High finding (S2 操作系)

## F-02: メンバーのロールを編集者/撮影者へ変更しても、プロジェクト未作成時は無言で変更が破棄される (成功に見えるが実は失敗)
- severity: High (H7 + H10)
- story/step: S2-3 (organizations.members.update)
- 再現手順: `manage/users` (組織にプロジェクトが1つも無い状態) でメンバーの行のロール
  `<select>` を「管理者」→「編集者」(または「撮影者」) に変更 → `PATCH .../members/{user}` が
  `303 See Other` で返る (エラーフラッシュ無し・combobox は選択後の値のまま表示され続ける) →
  画面をリロードすると combobox の選択値は **「管理者」に戻っている** (変更は保存されていない)。
  owner アカウント (bughunt2-regress-...@example.com) / admin アカウント (multi-org@example.com) の
  両方で再現。
- 期待: 変更が保存されないなら、その理由 (「編集者・撮影者を割り当てるには、先にプロジェクトを
  作成してください」という同画面に既にある注記文言と同じ趣旨) をエラーとして明示し、combobox は
  元の値に戻すか invalid 状態にする。
- 実際: HTTP 応答は成功系 (303) で、UI 上はエラーも成功トーストも出ない。ユーザーは「変更された」と
  誤認したままページを離れる可能性が高い。
- 阻害されたユーザージョブ: 権限管理 (ロール変更) の結果が信頼できず、実際に変更されたかどうかを
  リロードするまで確認できない。
- 改善アクション候補: サーバ側で編集者/撮影者への変更を拒否する場合は 422 + エラーメッセージ
  (「先にプロジェクトを作成してください」) を返し、フロントは該当エラーを combobox 直下に表示する。
- 証跡: `screenshots/F-02-member-role-update-silent-revert.png`。
  requests: `PATCH /organizations/{slug}/members/{id}` => `303 See Other` (エラーフラッシュ無し)。
- 推定原因: 未調査 (`organizations.members.update` のコントローラ/バリデーションが
  編集者/撮影者ロールを許可しない場合の失敗経路を要確認)。

## Low / 要確認

### Q-01 (要確認): `legal.commerce-disclosure` (特定商取引法に基づく表記) がフッター等どこからもリンクされていない
直接 URL (`/commerce-disclosure`) では到達可能 (プレースホルダ文言「本ページはプレースホルダです」を
表示)。フッターには 料金プラン/利用規約/プライバシーポリシー/お問い合わせ のみで本ページへのリンクが無い。
プレースホルダのため意図的に未リンクの可能性があり severity 未確定。

### Q-02 (要確認): 登録パスワードポリシーがシードテストアカウントの `password123` を満たさない
`/register` は 12文字以上 + 大文字/小文字混在 + 漏洩パスワードチェックを要求するが、
seed アカウント (`{role}-{plan}@example.com` 等) は全員 `password123` (11文字・小文字のみ)。
シード経由のアカウントはこのバリデーションをバイパスして作成されているため実害はないが、
bug-hunt 走行者向けドキュメント (テストアカウント記載) と実際の登録フォームの要件に差異があり、
「同じパスワードで新規登録を試す」と混乱しうる。アプリのバグではなくテスト環境ドキュメントの
整合性の問題として記録。

## S1 実行ログ (正常系 + バリデーション)
- home: CTA (料金プラン/ログイン/無料で始める)・フッターリンク (pricing/terms/privacy/contact) すべて到達確認。
- contact: 空送信バリデーション (名前・メール・内容・同意チェックボックス、4項目) → 正常系送信 →
  `contact.thanks` 到達、確認メール示唆あり。
- register: 空送信バリデーション → 弱いパスワード (11文字) → 大文字小文字混在なし → 漏洩パスワード
  (Have I Been Pwned 相当チェック、fake externals 経由と推測) の3段階エラーを確認 → 最終的に強いパスワードで
  登録成功 → `email/verify` → 再送信ボタンで成功トースト確認 → 署名付きリンクで認証完了 → `dashboard?verified=1`
  (チケット残高10、F-H1回帰OK)。
- login: 空送信バリデーション → 誤パスワードで「認証に失敗しました。」表示確認 → 正しい資格情報でログイン成功。
- forgot-password → password.email: 空送信バリデーション → 正常系で成功トースト → 署名付きURLで
  `reset-password/{token}` → 空パスワードバリデーション → 新パスワードでリセット成功
  (「パスワードを変更しました。ログインしてください。」) → 新パスワードでログイン成功。
- logout: 複数回実行、いずれもトップページへ遷移しセッション破棄を確認。
- 逸脱: 未認証で `/dashboard` 直アクセス → `/login` へリダイレクト (OK)。認証済みで `/login` を開く →
  `/dashboard` へリダイレクト (OK)。
- 未実行/skip: two-factor.login / two-factor.login.store — シードアカウントに 2FA 有効化済みの
  ものが見当たらず (S1 割当アカウントの範囲では 2FA 未設定)。時間予算の関係で自前の2FA有効化
  → ログアウト → 2FA チャレンジ確認までは未実施 (skip、理由: S6 (settings.security) の管轄と重複するため
  優先度を registration/invitation 回帰確認に割いた)。debug.login-as は今回未実行 (skip、S1 の主眼である
  回帰確認と直接関係が薄いため優先度を下げた)。

## S2 実行ログ (操作カバレッジ)
- organizations.invitations.store: 実行 (管理者ロールで送信成功、招待中リストに反映)。
  編集者/撮影者ロールでの送信は「先にプロジェクトを作成してください」バリデーションで弾かれることを確認
  (プロジェクト無し組織では管理者ロールのみ招待可能な仕様と判断)。
- invitations.accept (screen) + invitations.accept.store (operation):
  - **未ログイン経路**: F-01 (Critical、上記) を発見。
  - **ログイン済み経路 (別の既存ユーザー multi-org@example.com への招待)**: 正常に「組織への招待」
    確認画面が表示され (組織名明記)、「招待を受諾する」実行 → 成功トースト「『{組織名}』に参加しました」
    → dashboard へ反映。3点セット (実行→成功FB→反映) 完了。
- organizations.members.update: F-02 (High、上記)。owner/admin 双方で再現。
- organizations.members.destroy: 実行 (確認ダイアログあり「この操作は取り消せません」→ 削除実行 →
  成功トースト「メンバーを削除しました」→ 一覧から即消滅、3点セット完了)。
- organizations.members.two-factor.reset: **UI 導線が見当たらず skip**。`manage/users` 画面を
  隅々まで確認したが、メンバー行に 2FA リセット用のボタン/メニューが存在しない (2FA/リセット関連の
  テキストで検索しても該当箇所なし)。operations.md 記載の操作だが UI 未実装の可能性 (要開発側確認)。
- organizations.invitations.revoke: 実行 (確認ダイアログあり → 取消実行 → 招待中リストから即消滅を確認)。
- organizations.switch (S4 帰属だが本ストーリーの検証にも使用): multi-org@example.com で
  Freeプラン組織→回帰確認太郎の組織 へ切替実行、成功トースト「『{組織名}』に切り替えました」を確認。

## 画面カバレッジ
S1: home, register, login, dashboard, verification.notice, verification.verify, password.request,
password.reset, contact, contact.thanks, legal.commerce-disclosure(直URLのみ), legal.privacy(リンク確認のみ),
legal.terms(リンク確認のみ) → **12/13 完全走行、1 (two-factor.login) 未走行 (理由: 上記)**。
S2: invitations.accept → **1/1 走行** (未ログイン分岐・ログイン済み分岐の両方を確認)。

## 操作カバレッジ
S1 (9 対象): register.store / login.store / logout / password.email / password.update /
verification.send / contact.store 実行。two-factor.login.store 未実行 (skip、理由記載済み)。
debug.login-as 未実行 (skip、優先度により見送り)。→ **7/9 実行**。
S2 (6 対象): invitations.accept.store / organizations.invitations.store /
organizations.invitations.revoke / organizations.members.update / organizations.members.destroy
実行。organizations.members.two-factor.reset は UI 導線不在で skip。→ **5/6 実行 (UI不在1件skip)**。

## UI/UX 検証 (H11-H14)
- H11 (視覚破綻): register/login/contact/manage-users/dashboard で崩れなし。
- H12 (アフォーダンス): ボタンの primary/secondary 区別、combobox の selected 状態、確認ダイアログの
  破壊操作表現、いずれも明確。ただし F-02 (ロール変更の無言失敗) はアフォーダンス上「成功したように
  見える」ため H12 的にも問題あり (成功/失敗が視覚的に区別できない)。
- H13 (レスポンシブ): mobile 375×667 で `/register` (screenshots/S1-register-mobile375.png)、
  tablet 768×1024 で home (screenshots/S1-home-tablet768.png) を確認。いずれも横スクロール・要素はみ出し
  なし。組織メニューの mobile 版は個別に未確認 (時間予算により desktop 中心。優先度は高くないと判断)。
- H14 (a11y基礎): フォームラベル・invalid 状態のaria (textbox [invalid])・確認ダイアログのheading構造は
  適切に実装されている印象。詳細なコントラスト測定は未実施。

## Critical/High TODO 候補サマリ (app-design → app-todo-add 引き渡し用)
1. **F-01 (Critical)**: 未ログイン招待受諾 → 登録 → メール認証の経路で、招待経由ユーザーにも signup
   grant (チケット10枚) が付与され、かつ登録直後の画面では組織無所属に見える不整合が発生する。
   `CreateNewUser::create()` の招待token伝播/`acceptInvitationIfValid`分岐と、登録後dashboardの
   共有props反映タイミングを要調査。「招待 N 人 = N×10 増幅」を防ぐ既存設計の不変条件が破れている。
2. **F-02 (High)**: `organizations.members.update` でロールを編集者/撮影者に変更する際、対象組織に
   プロジェクトが無いと変更が無言で破棄される (成功ステータスコードだがDB反映なし、エラーFB無し)。

## 回帰確認結果 (最終)
- **F-C1 (T019 組織ナビ導線): 回帰OK。修正確認。** 組織メニュー・組織スイッチャーとも実装済み、
  S2 の全操作がUI到達可能になった。
- **F-H1 (T021 登録チケット付与): 直接登録経路は回帰OK。修正確認 (チケット残高10)。**
  ただし招待経由の未ログイン登録経路で F-01 (新規Critical) を発見。
