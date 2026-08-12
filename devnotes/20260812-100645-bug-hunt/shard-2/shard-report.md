# bug-hunt report shard-2 (run 20260812-100645)

- 対象 URL: http://127.0.0.1:8012
- 担当ストーリー: S1 (guest-registration-funnel) → S2 (invitation-flow)
- ブラウザセッション: bughunt2
- 開始: db-check OK (db=bug_hunt_2, users=11)

## 実行ストーリー / skip したステップ
- S1: 完走。home/pricing/contact/legal/register/verify/login/dashboard/password reset/passkey login-options/
  onboarding.checkout(Personal 活性化 later・auto_recharge 両方)/plan= 改竄/reset token 再利用 一式を実走。
  - ManualTestSeeder には 2FA 有効アカウントが無いため、S1 の時点では two-factor.login に到達できなかったが、
    S2 で自前セットアップし後述のとおり走行済みに回収した。debug.login-as は未着手
    (skip: 他 operation の検証を優先したため)。
- S2: 完走。organizations.invitations.store → invitations.accept (未ログイン→register 誘導・メール自動入力・
  個人組織を作らず招待先組織に参加・特典二重付与なし)→ organizations.members.update (ロール変更含む403系
  バリデーション)→ organizations.members.destroy (確認ダイアログ付き)→ organizations.invitations.revoke
  (確認ダイアログ付き・取消後リンク無効化)→ onboarding.billing-required (非管理member着地・オーナー連絡先表示・
  戻り導線・逆方向ガードとも往復ループなし)→ 撮影者ロールでの編集者専用操作(manuals.store)403 を実走。
  - **追記**: 対象メンバーで実際に 2FA (TOTP) を有効化し (setup key を Python で手動 TOTP 計算)、
    two-factor.login / two-factor.login.store (不正コード→エラー表示、正コード→dashboard) と
    organizations.members.two-factor.reset (理由必須の確認ダイアログ→解除→通知の旨の表示→
    トースト→一覧から2FAバッジ消失) を最終的に完走した。two-factor.login は当初 S1 の制約対象と
    書いたが、S2 のこのメンバーで実施できたため **未走行から走行済みに更新** (画面カバレッジ参照)。

## 画面カバレッジ (S1+S2 合算)
走行済み: home, pricing, contact, contact.thanks, legal.terms, legal.privacy, legal.commerce-disclosure,
register, verification.notice, verification.verify, login, dashboard, onboarding.checkout, password.request,
password.reset, passkey.login-options(直叩き含む), two-factor.login, invitations.accept,
onboarding.billing-required
未走行: なし (S1/S2 対象 screens は全て走行)

## 操作カバレッジ (S1+S2 合算)
実行済み: register.store, login.store, logout, password.email, password.update, verification.send,
contact.store, onboarding.activate-personal(later/auto_recharge 両方), passkey.login, two-factor.login.store,
invitations.accept.store, organizations.invitations.store, organizations.invitations.revoke,
organizations.members.update, organizations.members.destroy, organizations.members.two-factor.reset
未実行: debug.login-as — skip (理由: S1/S2 の他 operation 完走を優先。CLI/管理者ツール的な補助
operation で、通常のユーザージョブ導線に無いため優先度を下げた。次回持ち越し候補)

## UI/UX 検証
- H13 (レスポンシブ): dashboard (S1) と manage/users (S2) を mobile 375×667 / tablet 768×1024 で確認。
  ハンバーガーメニューへの折りたたみ・カード積み直しとも横スクロールやはみ出しなし。screenshots/
  s1-dashboard-mobile375.png, s1-dashboard-tablet768.png, s2-manage-users-mobile375.png。確認後 desktop
  (1280×900) に復帰。
- H11/H12: 各画面の snapshot / screenshot で視覚破綻・状態判別不能は観測されず。ボタンの
  active/selected/invalid 状態は snapshot 上区別可能。
- H14: フォームは label 関連付け・aria-invalid・role=alert/status が一貫して付与されている。
  重大な a11y 欠落は今回未観測。

## findings
Critical 0 / High 0 / Medium 0 / Low 0 / 要確認 0

今回の走行では確定 finding は 0 件だった。以下「検証メモ」に記載の通り、疑わしい挙動をいくつか
深掘りしたが、いずれも probe の再確認・レスポンス確認の結果、仕様どおりと判断した
(誤検知を finding にしない、SKILL.md 禁止事項 6 に従う)。

## 検証メモ (finding にならなかった要検証事項)
- 「無料プランを開始する」で funding_choice=auto_recharge を選ぶと /billing?fake_external=stripe に
  直接着地し、オートリチャージは「無効」のまま (カード未登録)。当初トーストが観測できず H7 候補かと
  疑ったが、click 直後に probe を取り直すと `present_new` にトースト
  「パーソナルプラン（無料）を開始しました。無料チケット 10 枚をお付けしました。カード登録が完了すると、
  オートリチャージが自動で有効になります。」を確認 (probe 呼び出しの遅延で最初は取り逃していただけ)。
  さらに `app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php` のコメントで
  「遷移先はアプリ内帰還画面 ($cancelUrl)」と明記されており、fake_externals 環境では
  Stripe Checkout 完了を模擬せず常に cancel 相当で戻る仕様と判断。billing 側に
  「カード登録が完了すると...」の説明文と「カードを登録する」CTA が残っており詰まない。
  → finding にしない (期待どおりの fake 環境の挙動)。
- passkey ログイン: click 実行直後の console に 405 (POST) が 2 件見えたが、これは直前に手動 eval で
  POST fetch した自分のテスト分の残留ログで、実際の UI クリックが発行したのは
  GET /passkeys/login/options => 200 (requests #23)。WebAuthn 非対応 (headless) のため
  navigator.credentials.get() 側で失敗し、`role=alert` で
  「パスキーの処理に失敗しました。時間をおいて再度お試しください。」を表示、ログインフォームは
  そのまま操作可能 (詰みなし)。存在オラクルも email あり/なし/未指定の 3 パターンで
  応答 (200 + 同一 shape の options) が区別できないことを確認。→ finding にしない (期待どおり)。
- reset トークン再利用: 使用済みトークンで再送信 → 422 相当で「このパスワード再設定トークンは無効です。」
  + 「新しいリセットリンクをリクエスト」導線が残り、詰まない。→ finding にしない。
- register/onboarding への ?plan= 未知値 (`enterprise`) / 5000 文字のダミー文字列 → 500 やコンソール
  エラーなし、Starter が既定候補として表示され続ける (フォールバック)。→ finding にしない。
- (S2) プロジェクトが無い組織でメンバーに「編集者/撮影者」を選択 → 一覧上部の説明文
  「プロジェクトがまだありません。編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」
  と矛盾するように見え、選択肢自体は combobox に出ている点を疑って深掘りしたが、実際には PATCH が
  422 validation error (`role: 編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。`)
  で拒否され、combobox 直下にインライン error として明示されていた (`[invalid]` + paragraph)。
  feedback probe が拾わなかったのは role=alert/status のライブリージョンではなくフォームの
  インラインエラーだったため (probe の対象外は仕様どおり)。→ finding にしない。
- (S2) メンバー削除・招待取消は事前に確認ダイアログ (destroy: 理由なしの Yes/No、
  2FA解除: 10文字以上の理由必須) が出ることを確認。破壊的操作の確認なし (H7) には該当しない。
- (S2) onboarding.billing-required の往復: 非manageBilling memberが未契約組織の /projects 等へ行くと
  billing-required に着地 (dashboard/請求は素通り)。paid化後は member/owner とも billing-required 直叩きで
  dashboard へ、未paid owner の billing-required 直叩きは onboarding.checkout へ、無限ループなし。

## H7 未検証
(現時点で 0 件。全て probe で肯定的観測を取得できた)

## インベントリ修正提案
(あれば)

---
(以下 finding 詳細を逐次追記)
