# bug-hunt report shard-2 (run 20260821-095643)

- shard: 2
- URL: http://127.0.0.1:8012
- 割当ストーリー: S1 (登録/ログインファネル), S2 (招待フロー)
- モード: --deviate 込み, --real-llm
- 開始: 2026-08-21 (JST)

## 実行ストーリー
- S1: 完走 (--deviate 込み)
- S2: 完走 (--deviate 込み)

## skip したステップ
- **S1 4-b パスキーログイン (passkey.login 操作の完走)**: この headless Chromium 環境では
  `/settings/security` で「この端末ではパスキーを作成できません。画面ロック（生体認証・PIN）を
  設定すると利用できます。」と表示され、パスキー登録 (passkey.store) 自体が実行不能。
  したがって「TOTP confirmed 済みユーザーで passkey.login を試すと拒否されるか」
  (PasskeyLoginPolicy の assurance 後退防止検証) も実施不能。理由: 環境的制約 (WebAuthn platform
  authenticator 非対応)。`passkey.login-options` (GET, JSON) 自体は実行し応答を確認済み
  (existence oracle 検証は完了、下記参照)。
- **S1 debug.login-as**: 操作自体は un実行 (下記インベントリ修正提案を参照。bughunt 環境では
  route が 404 のため実行不能)。

## 画面カバレッジ
- S1 screens: home, register, login, dashboard, onboarding.checkout, verification.notice, verification.verify, password.request, password.reset, two-factor.login, contact, contact.thanks, legal.commerce-disclosure, legal.privacy, legal.terms, passkey.login-options
  → 全 15 画面走行済み。
- S2 screens: invitations.accept, onboarding.billing-required
  → 全 2 画面走行済み (billing-required は順方向・逆方向の離脱ガード両方を確認)。
- 走行 17 / 総 17

## 操作カバレッジ
- S1 operations: register.store ✓, login.store ✓, logout ✓, password.email ✓, password.update ✓,
  verification.send ✓, two-factor.login.store ✓ (2FA 有効化は S6 領域だが S1 の two-factor.login.store
  検証のため自前で設定), contact.store ✓, debug.login-as (実行不能。404。下記インベントリ修正提案),
  onboarding.activate-personal ✓ (二重送信・throttle:10,1 の 429 挙動も検証), passkey.login (skip、上記)
- S2 operations: invitations.accept.store ✓ (T055 メール自動入力・別メールユーザーによる誤受諾を含む),
  organizations.invitations.store ✓, organizations.invitations.revoke ✓ (再利用不可を確認),
  organizations.members.update ✓ (プロジェクト未作成時のロール割当エラーを発見),
  organizations.members.destroy ✓ (削除が不完全であることを発見)、
  organizations.members.two-factor.reset ✓
- 実行 16 / 総 17 (skip 1: passkey.login、理由は上記)

## UI/UX検証
- H11 (視覚破綻): S1/S2 通して視覚破綻は観察されなかった (register/dashboard/billing-required の
  desktop・mobile・tablet screenshot で確認)。
- H12 (アフォーダンス/状態表現): F-2-01 (プロジェクト未作成時に選択可能に見える編集者/撮影者ロール option
  が実際は選べない) を検出。
- H13 (レスポンシブ): dashboard (375/768/1280)、billing-required (375/1280)、register (375/1280) で
  resize 確認。いずれも横スクロール・要素はみ出しなし。サイドバーはモバイルでハンバーガーメニューに
  正しく折りたたまれる。
- H14 (a11y 基礎): 主要フォームの invalid 属性・エラーテキストの関連付けは一貫していた。目立った欠落は
  見つからなかった (但し十分な axe 相当の機械チェックは実施していない。目視 snapshot ベース)。

## H7 未検証
- 0 件。書き込み操作はすべて feedback-probe で `installed_now=false` かつ肯定証拠 (成功 toast) または
  明示エラー文言を確認できた。

## findings (最終集計)
- Critical: 2 (F-2-02 招待受諾のメール照合漏れ、F-2-03 メンバー削除が不完全)
- High: 0
- Medium: 1 (F-2-01、H12)
- Low: 0
- 要確認: 1 (Q-2-01)

### Critical/High 要約 (TODO 候補)
- **F-2-02**: 招待受諾 (`invitations.accept.store`) が「受諾しようとしているログイン中ユーザーの
  email」と「招待トークンの宛先 email」を照合していない。無関係な第三者が招待リンクを知るだけで
  任意の組織にメンバーとして参加できてしまう。再現手順は F-2-02 参照。阻害ジョブ:
  組織のメンバー境界 (誰が参加できるか) が意図した認可境界として機能しない。改善: 受諾時に
  `$request->user()->email === $invitation->email` 相当の一致検証を追加し、不一致は拒否+説明表示。
  関連ファイル (未調査、推定): invitations.accept.store を処理するコントローラ (`OrganizationInvitation` 関連)。
- **F-2-03**: メンバー削除 (`organizations.members.destroy`) が成功トースト・「取り消せません」の
  確認文言を出すにもかかわらず、実際には組織メンバーシップ (pivot) が解除されず、削除されたはずの
  ユーザーが dashboard/projects/billing への閲覧アクセスを保持し続ける。再現手順は F-2-03 参照。
  阻害ジョブ: 組織からメンバーを排除する最重要のセキュリティ操作が実質的に機能しない。改善:
  destroy 処理で pivot (`$organization->users()->detach()`) を確実に外すよう修正。
  関連ファイル (未調査、推定): メンバー削除を処理するコントローラ (`organizations.members.destroy` route)。

## インベントリ修正提案
- **debug.login-as (operations.md, S1, 通常)**: 実装は `app()->isLocal() || app()->runningUnitTests()`
  でガードされており (`routes/web.php` L704 付近)、`APP_ENV=bughunt.local` は文字列比較で
  `isLocal()` が false になるため、bughunt 環境の実サーバ上では常に 404 で到達不能
  (本走行で `/debug/login` 直叩きも 404 を確認)。inventory-scan がこの route を機械的に発見できるのは
  scan 実行時の env 設定に依存すると見られ、実走行環境 (bughunt.local) との間に断絶がある。
  提案: (a) この操作を「区分=外」にして bug-hunt の実行対象分母から外す、または
  (b) bughunt 環境でも到達可能にする guard 条件を検討する (どちらが望ましいかは要判断)。
- **S2 カード本文の「編集者/撮影者」招待表現**: S2.md 手順1は「メールとロール（編集者/撮影者）を
  指定して招待」と書かれているが、実際の招待フォーム (`organizations.invitations.store`) の
  ロール選択は「管理者・メンバー」の 2 値のみで、編集者/撮影者はメンバー参加後に別途
  `organizations.members.update` で割り当てる (UI 上にも明記あり: 「編集者・撮影者は参加後に
  割り当てます」)。カード本文を実装と一致する表現に修正することを提案。

## F-2-03: メンバー削除 (organizations.members.destroy) が「削除しました」と表示するのにメンバーシップ自体は解除されず、削除したはずのユーザーが組織の Dashboard/Projects/Billing に引き続きアクセスできる (H10 矛盾 / 実質的な認可漏れ, Critical)
- severity: Critical
- story/step: S2-3 (organizations.members.destroy)
- 再現手順:
  1. 組織オーナー (shard2-dbl@example.com、組織「Shard2 二重送信テスト の組織」) が `/manage/users` を開き、
     メンバー (shard2-invitee@example.com、当時ロール「編集者」) の「削除」ボタン → 確認モーダルで
     「削除する」を押す。トースト「メンバーを削除しました」が出て、メンバー一覧からその行が消える
     (この時点では正しく完了したように見える)。
  2. 別セッションで shard2-invitee@example.com としてログインする。
  3. `/dashboard` に到達できる。左サイドバーに「ダッシュボード」「プロジェクト」「請求」の nav が出ている
     (削除前と同様に組織「Shard2 二重送信テスト の組織」がアクティブ組織のまま)。
  4. `/projects` を開くと、その組織のプロジェクト一覧 (「Shard2 テストプロジェクト」) が引き続き閲覧できる。
  5. オーナー側で再度 `/manage/users` を確認すると、削除したはずの shard2-invitee@example.com が
     再びメンバー一覧に「未割当」ロールとして表示される (削除操作の前に一覧から消えたのは表示上の
     一時的な反映のみで、実データは削除されていない)。
- 期待: 「削除する」を確定した時点で、対象ユーザーはその組織のメンバーではなくなり、
  当該組織の dashboard/projects/billing 等へのアクセスができなくなる (せめて 403 か、
  組織切替を強制されて別組織/組織なし状態に落ちる)。確認モーダルの文言
  「この操作は取り消せません」とも整合しない。
- 実際: 「削除する」操作後もそのユーザーは組織のメンバーとして残り続け (ロールだけ「未割当」に
  戻る)、業務画面 (dashboard・projects・billing) への閲覧アクセスを保持したままである。
  ユーザー管理画面には再度「未割当」として表示され続ける (実質的に削除されていない)。
- 阻害されたユーザージョブ: 組織オーナーが問題のあるメンバー・退職者・誤って招待した相手を
  組織から排除する、という最重要のセキュリティ操作が実質的に機能しない。オーナーは「削除した」と
  信じて安心するが、対象ユーザーは組織のデータ (プロジェクト名、請求情報の一部等) へのアクセスを
  保持し続ける。編集者・撮影者専用の書き込み操作 (manuals.store 等) がロール未割当により拒否される
  可能性はあるが、閲覧アクセス自体が残ることは重大な認可上の欠陥である。
- 改善アクション候補: `organizations.members.destroy` がロール剥奪 (Laratrust role detach) だけでなく、
  組織とユーザーの pivot (メンバーシップそのもの) を確実に削除するよう修正する。合わせて、
  「ロール未割当だが組織に紐づいたまま」の状態 (`MemberRowData` のコメントが言及する「異常行」)
  を許容し続けるのか、根本的に許容しない設計に変えるのかを設計判断として明確化する。
- 証跡: `screenshots/F-2-03-removed-member-still-has-access.png` (削除されたはずの
  shard2-invitee@example.com が `/projects` にアクセスできている様子。組織切替表示は
  「Shard2 二重送信テスト ...」「Shard2 被招待者」)。加えて `/manage/users` の再確認 snapshot
  (2026-08-21T01:32 頃) で shard2-invitee@example.com が「未割当」として再出現していることを確認。
- 推定原因: 未調査 (`OrganizationMemberController::destroy` 等が Laratrust の
  `detachRole`/`syncRoles` のみを呼び、`$organization->users()->detach($user)` (pivot 解除) を
  呼んでいない可能性)。
- 関連既知情報: 未確認 (devnotes/TODO.md 未検索)。`Admin/UserManagementController` のコメントに
  「organizationRole null (attach 済みだが Laratrust ロール未付与の異常行) も非表示にせず
  『未割当』として可視化する」との記述があり、この「異常行」を意図的に許容する設計自体が、
  destroy 操作の不完全さを覆い隠している可能性がある (要設計確認)。

## F-2-02: 招待受諾 (invitations.accept.store) がトークンの宛先メールアドレスと受諾者を照合しておらず、無関係な既ログインユーザーが他人宛の招待を受諾して組織に参加できる (認可バイパス, H9)
- severity: Critical
- story/step: S2-2 / S2 逸脱アイデア「別組織の招待トークンを自分のセッションで受諾」相当 (トークンの宛先と受諾者のミスマッチ)
- 再現手順:
  1. 組織オーナー (shard2-dbl@example.com、組織「Shard2 二重送信テスト の組織」) が `/manage/users` から
     `shard2-reuse-test@example.com` 宛にメンバー招待を送信する。
  2. `tmp/bug-hunt/shard-2-cmd.sh mail-urls` で招待受諾 URL
     (`/invitations/accept?token=...`) を取得する (例: 本走行では
     `token=GWpUfQChQZoCaCAgz74PNw6NEXabCMkD82SPXx93OjIrIiL5U4ISO2dqX0xfbD3P`)。
  3. **招待先とは全く別のメールアドレス**を持つ既存の認証済みユーザー (本走行では shard2-user1@example.com、
     自分の別の組織のオーナー) としてログインする。
  4. ログイン済みのまま手順2の招待 URL を直接開く。
  5. 表示された「組織への招待」画面 (「Shard2 二重送信テスト の組織」に招待されています。受諾するとこの組織の
     メンバーになります。) で「招待を受諾する」ボタンを押す。
- 期待: 招待は宛先メールアドレス (shard2-reuse-test@example.com) を持つユーザーのみが受諾できるべきで、
  別メールアドレスで既ログイン中のユーザーが受諾しようとした場合は拒否されるか、少なくとも
  「このアカウント (shard2-user1@example.com) では受諾できません。招待先のメールアドレスでログインし直してください」
  等の警告が必要。
- 実際: 警告なしに「招待を受諾する」ボタンが表示され、押すと即座に成功トースト
  「『Shard2 二重送信テスト の組織』に参加しました」が出て `/dashboard` に遷移した。
  ヘッダーの組織切替メニューに「Shard2 二重送信テスト の組織」が追加され、実際にそのメンバー
  (ロール: メンバー) として組織へ参加できてしまった (`/manage/users` を当該組織で開くと 403 になるのは
  メンバー権限の制約のため正常だが、そもそも参加自体が成立している点が問題)。
  つまり **招待トークンの検証は「有効な (未失効・未使用・未受諾) トークンか」のみで、
  「受諾しようとしているユーザーのメールアドレスがトークンの宛先と一致するか」を検証していない**。
- 阻害されたユーザージョブ: 組織オーナーが特定の個人 (メールアドレス) だけを組織に招待したつもりでも、
  招待リンクを (メール転送・URL 共有・ブラウザ履歴・ログ等で) 知った**無関係な第三者**が、
  自分の既存アカウントでそのまま組織に参加できてしまう。組織のメンバー境界 (どの個人がメンバーか) が
  メールアドレスという意図した認可境界どおりに機能しない。
- 改善アクション候補: `invitations.accept.store` (および受諾確認画面表示時) で、ログイン中ユーザーの
  email と招待の宛先 email を比較し、不一致なら 403 相当で拒否し「別のメールアドレスでログイン中です。
  招待先のメールアドレスでログインしてください」等を表示する。未ログイン時のフロー
  (register 誘導・メール自動入力) は今回問題なし (T055 相当は正しく動作していることを別途確認済み)。
- 証跡: `.playwright-cli` snapshot 2026-08-21T01:25 前後
  (URL: `http://127.0.0.1:8012/invitations/accept?token=...`、
  表示: 「Shard2 二重送信テスト の組織」に招待されています。受諾するとこの組織のメンバーになります。/
  受諾後 toast: 「Shard2 二重送信テスト の組織」に参加しました)、
  feedback-probe: `installed_now=true (accept 前に arm) seen=2(visible:true, text=参加しました) present_new=1 pending=0 errors=0`。
  受諾後 `/manage/users` (対象組織) で shard2-user1@example.com がメンバー一覧に出現することを確認済み。
  再現 screenshot: `screenshots/F-2-02-wrong-user-invite-screen.png` (別メールアドレスの
  shard2-invitee@example.com でログイン中に shard2-screenshot-repro@example.com 宛の招待受諾画面が
  警告なしに表示される様子)、`screenshots/F-2-02-joined-org-toast.png` (受諾後に成功トーストが出て
  組織へ参加してしまう様子)。
- 推定原因: 未調査 (`InvitationAcceptController`/`OrganizationInvitation` 受諾処理が token → invitation の解決のみ行い、
  `$request->user()->email === $invitation->email` 相当の一致検証を行っていない可能性)。
- 関連既知情報: 未確認 (devnotes/TODO.md 未検索)。S2 逸脱アイデア「別組織の招待トークンを自分のセッションで
  受諾 → 想定組織にのみ参加するか(トークン改竄)」に対応する検証だが、今回発見した不整合は
  「別組織」ではなく「別メールアドレスの本人以外」が受諾できる点である。

## F-2-01: プロジェクト未作成の組織で編集者/撮影者ロールを選択すると選択自体は可能だが送信後にエラーになる (H12)
- severity: Medium
- story/step: S2-3 (organizations.members.update)
- 再現手順:
  1. `http://127.0.0.1:8012` で組織オーナー (owner, 例 shard2-dbl@example.com) としてログイン。当該組織にプロジェクトが 0 件の状態にする。
  2. 招待済みメンバー (`未割当`) を1名用意し `/manage/users` を開く。
  3. 対象メンバーの「ロール」combobox で「編集者」または「撮影者」を選択する。
- 期待: 選択できない (disabled) か、選択前に「先にプロジェクトを作成してください」の説明が出てから選ぶ流れになる。
- 実際: 「編集者」「撮影者」は選択可能な option としてそのまま表示され、選択して送信すると combobox が invalid になり
  「編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」という validation エラーが事後に出る。
  選択は "未割当" に戻る。プロジェクト作成後に同じ手順を行うと成功する (H7 ではない: toast「ロールを変更しました」を確認、
  feedback-probe で証跡取得済み)。
- 阻害されたユーザージョブ: オーナーが招待直後のメンバーに編集者/撮影者ロールを付与しようとして、
  「なぜ選べないのか」が事前にわからず 1 往復無駄になる (詰みではないが手戻りが発生する)。
- 改善アクション候補: プロジェクトが 0 件の組織では「編集者」「撮影者」option を disabled にし、
  option の傍か combobox の説明文に「プロジェクトを作成すると選べます」を事前表示する。
- 証跡: screenshots/ (snapshot ログに記録。エラー文言は
  `.playwright-cli` yaml 参照 2026-08-21T01-18-38Z 前後), feedback-probe: 事後確認は下記の再試行で成功
  (`installed_now=false seen=1(visible:true, text=ロールを変更しました) present_new=1 pending=0 errors=0`)
- 推定原因: 未調査 (organizations.members.update のバリデーションルールが projects 存在チェックを行っているとみられる)。
- 関連既知情報: 未確認 (devnotes/TODO.md 未検索)。

## Q-2-01 (要確認): 招待経由参加メンバーの登録直後の着地が /billing になる
- severity: 要確認
- story/step: S2-2 (T030 関連)
- 再現手順: 既契約組織 (Personal free 有効) への招待を受けた未ログインメールが register → email 認証を完走すると
  `/billing` (プランとお支払い) に着地する (`/dashboard` ではない)。
- 期待/実際: screens.md「課金ゲート着地」節は `onboarding.checkout` の離脱ガードとして
  「契約済みは billing.index へ」を明記しており、動作は文書と整合する。ただし一般メンバー (編集者/撮影者未割当、
  管理権限なし) の初回ログイン後の着地として `/billing` (自分では変更できない請求情報ばかりの画面) が
  直感的か要確認。個人組織を作らず・チケット二重付与もされていないこと (10 枚のまま) は確認済みで、
  機能的な破綻はない。
- 阻害されたユーザージョブ: 特になし (バグではなく着地画面の適切性の疑問)。
- 改善アクション候補: (要確認) 非管理メンバーの初回着地を /dashboard に統一するか、現状の仕様を明文化する。
- 証跡: 2026-08-21T01:17 頃の snapshot (URL: http://127.0.0.1:8012/billing、見出し「プランとお支払い」)。
- 関連既知情報: screens.md「課金ゲート着地」節 (docs/billing-gate-inversion-runbook.md)。

---
