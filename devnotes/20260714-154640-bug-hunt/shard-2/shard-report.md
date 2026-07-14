# bug-hunt report shard-2 (run 20260714-154640)

- 実行ストーリー: S1 (登録/ログインファネル), S2 (招待フロー)
- 走行目的: real-llm 2nd pass。前回 run(20260714-093524)で修正確認済みの F-01(招待所属)・F-H1(登録チケット)・F-02 のデグレ検知を主眼に再確認。
- URL: http://127.0.0.1:8012 / DB: bug_hunt_2 / セッション: bughunt2
- 開始時 db-check: db=bug_hunt_2, users=8 (正常)

## 画面カバレッジ
- S1 走行済み: home, pricing, contact, contact.thanks, register, login, dashboard, verification.notice(email/verify),
  verification.verify(署名URL), password.request(forgot-password), password.reset(reset-password), privacy, terms,
  commerce-disclosure(直URL到達のみ、footer等にリンクなし。下記 finding 参照)
- S1 未確認: two-factor.login / two-factor.login.store (割当アカウントに2FA有効化済みが無いため未実施。S6管轄と重複のためskip)
- S2 走行済み: invitations.accept (未ログイン経路・ログイン済み経路の両方で「組織への招待」確認画面
  (招待元組織名・「招待を受諾する」ボタン) を確認), manage/users (screens.md には S4 割当だが S2 の
  organizations.invitations/members 系操作の実装画面として実質的に走行)

## 操作カバレッジ
- S1 実行済み: register.store(空/バリデーションNG→OK), login.store(誤パスワード→OK), logout, password.email(空→OK、成功flash確認),
  password.update(空→OK、成功flash確認)、verification.send(再送信、成功flash確認), contact.store(空→OK)
- S1 skip: two-factor.login.store (2FA有効アカウント無し、S6管轄), debug.login-as (優先度により見送り)
- S2 実行済み: organizations.invitations.store (空バリデーション→OK。撮影者/編集者ロールはプロジェクト
  未作成のため「先にプロジェクトを作成してください」で invalid 表示され拒否されることを確認 (期待通り)、
  管理者ロールで成功・招待中リストに反映)、invitations.accept.store (未ログイン新規登録経由/既存アカウント
  ログイン済み経由の両方で実行。両方とも成功トースト + 組織参加を確認)、organizations.invitations.revoke
  (確認ダイアログ→取消→リストから消滅、取消後のリンクは「この招待リンクは使用できません」で正しく拒否)、
  organizations.members.update (ロール変更が即座に combobox に反映)、organizations.members.destroy
  (確認ダイアログ→削除→一覧から消滅、削除されたユーザーは再ログイン時「組織を選択」の空状態になり
  /manage/users への直アクセスは404で正しく拒否されることを確認)
- S2 skip: organizations.members.two-factor.reset — UI 導線が見当たらず (`manage/users` を "2FA"/
  "二段階"/"認証" で検索してもヒットなし)。過去 5 回の bug-hunt run (20260713-085818 ～ 20260714-093524)
  すべてで同一の skip が記録されており、既知の未実装/導線不在ギャップと判断 (regression ではない。
  対象メンバーが 2FA 有効化済みでないと表示されない設計の可能性もあるが未確認)
- S2 skip: 逸脱アイデア「撮影者ロールで受諾後、編集者専用操作 (manuals.store 等) を試す→403か」—
  プロジェクト作成が前提となり S3/S7 (プロジェクト作成・認可境界) の管轄と重複するため、今回は
  S1/S2 主眼の回帰確認を優先し見送り (前回 run と同一理由)

## UI/UX 検証 (H11-H14)
- H11(視覚破綻): home/pricing/contact/register/login/dashboard いずれも崩れなし
- H12(アフォーダンス): register/login/forgot-password/reset-password のバリデーションエラーは textbox [invalid] +
  エラー文言で明確。成功時は status toast (閉じるボタン付き) で一貫したパターン
- H13(レスポンシブ): mobile 375×667 で /dashboard (screenshots/S1-dashboard-mobile375.png) と
  /manage/users (screenshots/S2-manage-users-mobile375.png)、tablet 768×1024 で /register
  (screenshots/S1-register-tablet768.png) を確認。いずれも横スクロール・要素はみ出し・重なりなし。
  確認後 desktop (1280x900) に復帰。
- H14(a11y基礎): フォームラベルは textbox の accessible name として取得可能。invalid 状態も aria で判別可能。

## 回帰確認 (前回修正済み finding)
- F-H1 (登録時チケット10枚付与): 新規登録 → メール認証後の dashboard で「チケット残高: 10」を確認。
  デグレなし (継続して修正済み)。
- 個人組織自動作成: 登録直後に「シャード2 太郎 の組織」が dashboard 右上に表示され、本人の個人組織に
  正しく所属していることを確認。デグレなし。
- password.email / password.update の成功 flash: 前回 run (20260714-093524) と同様に正しく表示されることを
  再確認 (自分の snapshot タイミングが早すぎて誤って見落としかけたが、1秒待って再確認し正常動作を確認。
  「誤検知として却下した所見」参照)。
- F-01 (招待所属バグ) の修正: owner-free@example.com で管理者ロール招待 → 未ログイン状態で招待リンクを開くと
  /register へ誘導 → 招待メールと同じアドレスで新規登録 → メール認証完了後、dashboard で「Freeプラン組織」
  (招待元組織) に正しく所属していることを確認 (個人組織が作られていない)。/manage/users でも
  「シャード2 招待太郎」が招待どおり「管理者」ロールでメンバー一覧に反映されていることを確認。デグレなし。
  (チケット残高は 0 で「残高が少なくなっています」警告が出るが、これは招待先組織の既存チケット残高に
  依存する仕様通りの挙動であり、新規登録者に付与される個人組織向け10枚とは別物のため正常)。

## findings
(走行中に追記。severity 降順)

## F-01: legal.commerce-disclosure (特定商取引法に基づく表記) ページへのリンクがサイト内のどこにもない
- severity: Low (H8寄り。孤立ページ)
- story/step: S1-1 (公開導線)
- 再現手順:
  1. http://127.0.0.1:8012/ のフッターを確認 → 「料金プラン」「利用規約」「プライバシーポリシー」「お問い合わせ」の
     4リンクのみで「特定商取引法に基づく表記」へのリンクが無い
  2. /pricing, /terms, /privacy, /register, /contact の各ページも同様にリンクなし (find "商取引" で検索し確認)
  3. 直接 URL http://127.0.0.1:8012/commerce-disclosure にアクセスするとページ自体は正常に表示される
     (「特定商取引法に基づく表記」というタイトルで、プレースホルダ文言「本ページはプレースホルダです。
     有償サービスを提供する場合は事業者情報を記入してください。」を含む)
- 期待: 有償プラン (Standard ¥4,980/月) を提供している以上、特定商取引法に基づく表記ページはフッター等の
  常設導線からユーザーが到達できるべき
- 実際: ページ自体は実装済みだがサイト内のどこからもリンクされておらず、URL を知っている人しか到達できない
  孤立ページになっている
- 阻害されたユーザージョブ: 有償プラン契約を検討するユーザーが事業者情報 (特定商取引法上の必須表示事項) を
  確認したくても、通常の導線からは辿り着けない
- 改善アクション候補: home/pricing のフッターに「特定商取引法に基づく表記」リンクを追加する
  (ページ本文がプレースホルダのままである点も合わせて要確認)
- 証跡: playwright-cli find "商取引" (home/pricing/register で "No matches found")、
  goto http://127.0.0.1:8012/commerce-disclosure (200 OK, タイトル "特定商取引法に基づく表記 | AI-CUE")
- 推定原因: 未調査 (footer コンポーネントへのリンク追加漏れの可能性)
- 関連既知情報: 未確認 (前回 run report との突合は未実施。ページ本文が明示的にプレースホルダと自認している
  ため、意図的な未完成 (今後の実装待ち) の可能性もあり、severity は Low + 要確認寄りとする)

(現時点で確定 finding は F-01 のみ。招待受諾直後に組織スイッチャーが切り替わらない点は一時 finding 化
しかけたが、既知の意図的仕様と確認済み。詳細は下記「誤検知として却下した所見」参照)

## 誤検知として却下した所見
- forgot-password 送信直後に snapshot したところ toast が見えなかったため一時的に finding 化しかけたが、
  1 秒待ってから再 snapshot すると「パスワードリセット用のリンクをメールで送信しました。」の status toast が
  正しく表示されることを確認 (前回 run 20260714-093524 の挙動と一致、regression なし)。snapshot 直後の toast
  フェードインタイミングによる自分側の誤操作と判断し finding 化を見送った。
- **招待受諾 (invitations.accept.store) 直後、成功トースト「「{組織名}」に参加しました」が出るのに
  ヘッダーの組織スイッチャーは旧 (元々ログイン中だった) 組織のまま切り替わらず、ダッシュボード本文も
  旧組織のものが表示され続ける事象を発見** (owner-standard@example.com とは別に、新規登録した
  「シャード2 太郎B」アカウントで個人組織を持った状態から Standard 組織への招待を受諾して再現、
  screenshots/REJECTED-invite-accept-no-org-switch-known-design.png に証跡あり。フルリロードしても active org は
  切り替わらず、組織スイッチャーを手動でクリックして初めて新組織に切り替えられる)。
  一見 finding 化しかけたが、前回 run (20260714-093524) の shard-2 レポート (S2 実行ログ) に
  「current_organization は切り替わらず「Standardプラン組織」のまま (T030 の設計どおり、POST受諾は
  current を切り替えない仕様と一致。組織スイッチャーで切替が可能なことも確認)」と明記されており、
  **T030 実装時に意図的に選んだ設計 (招待受諾は自動で active org を切り替えない、ユーザーが手動で
  スイッチャーから選ぶ) であることが既に確認済み**。よって finding 化せず、デグレでもないことを確認。
  ただし「成功メッセージが参加を告げるのに画面が旧組織のまま」という体験自体には改善余地があるとは
  考えられるため、次回の仕様レビュー時に UX 改善候補として検討する価値はある (severity なしの「要確認」
  寄りメモとして記録するに留める)。

## TODO 候補 (Critical/High のみ)
- 今回 shard-2 走行では Critical/High の新規 finding なし。F-01 (commerce-disclosure 孤立ページ) は Low。

## 要確認 (仕様未確定、バグと断定しないもの)
- なし (今回はいずれも severity 付き finding か、既知仕様として却下したもののみ)

## インベントリ修正提案
- なし (screens.md / operations.md との乖離は未検出。manage/users が screens.md 上 S4 割当だが
  organizations.invitations/members 系の実装画面として S2 でも実質的に使われている点は
  ドキュメント上の注記漏れの可能性あり。修正は親判断に委ねる)

## 総括
- S1 (登録/ログインファネル): 全操作 3点セットで完走。前回修正 (F-H1: 登録時チケット10枚, 個人組織自動作成)
  にデグレなし。新規 Low finding 1件 (commerce-disclosure 孤立ページ)。
- S2 (招待フロー): 全操作 (招待送信/取消/受諾/ロール変更/メンバー削除) 3点セットで完走。前回修正
  (F-01: 招待経由登録の組織所属) にデグレなし。organizations.members.two-factor.reset は5回連続で
  UI導線不在によりskip (regressionではない、既知ギャップ)。
- 誤検知2件を丁寧に切り分けて却下 (forgot-passwordのtoastタイミング、招待受諾後の組織スイッチャー非切替
  =T030の意図的設計) — いずれも過去runとの突合により誤検知と確定。
