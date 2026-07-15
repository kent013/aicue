# bug-hunt report shard-2 (run 20260715-084108)

- 対象URL: http://127.0.0.1:8012 (DB: bug_hunt_2)
- 担当ストーリー: S1 (ゲスト登録ファネル), S2 (招待フロー)
- 走行主眼: T055 招待メール自動入力 (最重要) / T030 招待先組織所属回帰 / T045 特定商取引法リンク / 通常登録の個人組織+チケット10枚(F-H1) / S1 バリデーション / S2 招待3点セット
- 開始時 db-check: db=bug_hunt_2, users=8

## 実行ストーリー
- S1 (ゲスト登録ファネル): 完走。home → pricing → contact → contact.thanks → register (バリデーション→正常系) → email/verify → verification 再送 → メール認証 → dashboard → logout → login (誤パスワード→正常系) → 認証済みで /login /register /forgot-password に直アクセス→dashboard へ正しくリダイレクト確認 → forgot-password → reset-password → 新パスワードでログイン確認 → terms/privacy 閲覧。
- S2 (招待フロー): 完走 (プロジェクト作成を挟んで編集者ロール検証も実施)。organizations.invitations.store (空値バリデーション→正常送信) → 未ログインで invitations.accept を開く → **T055: 招待メールが register フォームに自動入力 (readonly) されることを確認** → 新規登録→メール認証 → **T030: 招待先組織に正しく所属、個人組織なし、チケット重複付与なし、ヘッダー組織メニューに反映を確認** → organizations.members.update (役割変更、再読込後も永続) → organizations.members.two-factor.reset (3点セット、F-03 の状態不整合を発見) → organizations.invitations.revoke (3点セット) → 失効/使用済みトークンの再利用が正しく拒否されることを確認 (逸脱アイデア) → 既存ユーザー (multi-org@example.com) がログイン状態で招待を受諾するパスも確認 (organizations.members.destroy も実施、除名後のユーザーが詰まず「組織を作成」導線に落ちることを確認) → two-factor.login / two-factor.login.store をTOTPシークレット実算出で実地検証 (誤りコード→エラー表示、正しいコード→ログイン成功)。

## skip したステップ
- 撮影者ロールでの manuals.store 403 確認 (S2 逸脱アイデア末尾) は S7 (認可境界) の担当領域と重複するため今回は skip (理由: shard-2 は S1/S2 担当で S7 は別 shard 想定、時間配分を T055/T030/2FA 状態不整合の深掘りに優先した)。
- debug.login-as (S1 operations 記載) は screens.md 上の位置づけを確認できず、通常のログインフローで代替検証した (直接該当ボタン/導線が UI 上に見当たらなかったため)。

## 画面カバレッジ
- 走行 14 / 14 (S1: home, register, login, dashboard, verification.notice, verification.verify, password.request, password.reset, two-factor.login, contact, contact.thanks, legal.commerce-disclosure, legal.privacy, legal.terms / S2: invitations.accept)

## 操作カバレッジ
- 実行 13 / 14 (S1: register.store, login.store, logout, password.email, password.update, verification.send, two-factor.login.store, contact.store / S2: invitations.accept.store, organizations.invitations.store, organizations.invitations.revoke, organizations.members.update, organizations.members.destroy, organizations.members.two-factor.reset)
- 未実行: debug.login-as (上記 skip 理由参照)

## UI/UX 検証
- H11/H12/H14: 毎ステップ snapshot で role/name/state 取得を確認。致命的な視覚破綻・アフォーダンス不明瞭は見つからず。バリデーションエラーは概ね inline 表示され invalid 状態も明示される (F-02 のみ例外)。
- H13 レスポンシブ: home (mobile 375x667, ハンバーガーメニュー動作確認 screenshots/H13-home-mobile375.png), manage/users (mobile 375x667 screenshots/H13-manage-users-mobile375.png, tablet 768x1024 screenshots/H13-manage-users-tablet768.png) で確認、横スクロール・要素はみ出し・操作不能なし。確認後 1280x800 に復帰済み。

## findings サマリ
- Critical 0 / High 1 (F-02) / Medium 2 (F-01, F-03) / Low 0 / 要確認 0

## 新機能・回帰の確認結果 (最重要)
- **T055 (招待メール自動入力)**: **確認OK**。未ログインで invitations/accept を開くと register にリダイレクトされ、メールアドレス欄に招待先メールが自動入力 (readonly) され「招待されたメールアドレスで登録します。」の案内文も表示される。
- **T030 (招待先組織への正しい所属、回帰)**: **確認OK**。招待メールで新規登録→メール認証後、個人組織は作成されず招待先組織 (Shard2 テスト太郎 の組織) にのみ所属。組織切替メニューにも招待先組織のみ表示。チケット残高は 10 のまま (招待受諾による二重付与なし)。オーナー側のメンバー一覧にも正しいロール (管理者) で反映。
- **T045 (特定商取引法リンク)**: **確認OK**。home・pricing のフッターに「特定商取引法に基づく表記」リンクがあり、/commerce-disclosure へ正しく遷移しプレースホルダ内容が表示される。
- **F-H1 (通常登録で個人組織+チケット10枚)**: **確認OK**。通常登録 (招待なし) でメール認証完了後、「{名前} の組織」という個人組織が自動作成され、チケット残高 10 が付与される。

## インベントリ修正提案
- なし (screens.md / operations.md との乖離は確認されなかった)

---

(以下、finding 詳細を見つけ次第 severity 降順で追記)

## F-01: パスワードリセット申請後に成功フィードバックが無い
- severity: Medium (H7)
- story/step: S1-5 (forgot-password → password.email)
- 再現手順: http://127.0.0.1:8012/forgot-password を開く → メールアドレス欄に `shard2-normal-reg@example.com` を入力 → 「リセットリンクを送信」をクリック。
- 期待: 送信後にトースト等の成功フィードバック(例: 「登録されたメールアドレスにパスワードリセットリンクを送信しました」)が表示される。同フローの「認証メールを再送信」ボタンでは緑色のトーストが出る (screenshots/S1-resend-immediate.png)。
- 実際: フォームがそのまま残るのみで、成功・失敗どちらのフィードバックも表示されない (ネットワークは POST /forgot-password → 302 → GET /forgot-password → 200 で成功しているように見えるが画面上に痕跡なし)。
- 阻害されたユーザージョブ: パスワードを忘れたユーザーが「メールが送られたか」を確認できず、再送すべきか・迷惑メールを探すべきか判断できない。
- 改善アクション候補: verification.send と同様に flash → toast (flash-to-toast) を password.email のレスポンスにも実装する。
- 証跡: screenshots/S1-forgot-password-fresh.png, screenshots/S1-resend-immediate.png (対比用), console: no errors, network: POST /forgot-password => 302 Found
- 推定原因: 未調査 (password.email のコントローラが flash message をセットしていない可能性)
- 関連既知情報: 未確認 (TODO.md 未参照)

## F-02: 2要素認証セットアップで誤った認証コードを送信してもエラーが表示されない
- severity: High (H7 / H1: 無反応・無説明)
- story/step: S2-3 隣接 (settings/security → 2要素認証有効化。two-factor.login.store とは別エンドポイント `user/confirmed-two-factor-authentication`)
- 再現手順: 招待先ユーザー (shard2-invitee-editor@example.com / InviteePass77zQ) でログイン → http://127.0.0.1:8012/settings/security → 「有効化」→ QR コード表示 → 認証コード欄に不正な値 `123456` を入力 → 「確認して有効化」をクリック。
- 期待: 認証コードが誤っている旨のエラーメッセージ (フィールド invalid 表示 or トースト) が表示される。
- 実際: POST `/user/confirmed-two-factor-authentication` は 302 でフォームページに戻るが、画面上に成功・失敗どちらの表示も一切ない (「2要素認証: 無効」のまま、フィールドに入力した値 123456 は残るがエラー文言なし、console error もなし)。ユーザーはコードが間違っていたのか、何が起きたのか全く判断できない。
- 阻害されたユーザージョブ: アカウントのセキュリティ強化 (2FA 有効化) をしたいユーザーが、なぜ有効化が完了しないのか分からず詰む可能性がある。
- 改善アクション候補: バリデーションエラーを画面に表示する (他のフォーム同様 invalid state + エラー文言、または flash→toast)。
- 証跡: screenshots/S2-2fa-wrong-code.png, console: no errors, network: POST /user/confirmed-two-factor-authentication => 302 Found (GET /settings/security => 200 直後)
- 推定原因: 未調査 (コントローラが ValidationException を投げていない、またはフロントが flash/errors bag を描画していない)
- 関連既知情報: 未確認 (TODO.md 未参照)

## F-03: 組織メンバー管理画面が「2FA 未確定 (secret生成のみ)」を「2FA 有効」として「2FA 解除」ボタンを表示する
- severity: Medium (H10: 状態表示の矛盾)
- story/step: S2-3 (organizations.members.two-factor.reset)
- 再現手順:
  1. 招待先ユーザー (shard2-invitee-editor@example.com) でログイン → /settings/security → 「有効化」をクリックして QR を表示させる → 認証コード欄に不正な6桁 `123456` を入力して「確認して有効化」をクリック (F-02 のとおりエラー表示は出ないが、TOTP は確定していない)。
  2. 本人の /settings/security を再確認 → 「2要素認証: 無効」のまま (確定していないことが正しく表示される)。
  3. オーナー (shard2-normal-reg@example.com) で /manage/users を開く → 招待先 太郎 の行に **「2FA 解除」ボタンが表示される** (実際には確定した 2FA は存在しない)。
  4. 「2FA 解除」→ 理由入力 → 「解除する」で実行すると DELETE `/organizations/{org}/members/10/two-factor` が 303 で成功し、以降ボタンは消える。
- 期待: メンバー一覧の「2FA 解除」ボタンは、本人が実際に 2FA を**有効化・確定**した場合にのみ表示される (本人の設定画面の「有効/無効」表示と一致する)。
- 実際: QR コード発行 (secret 生成) しただけの未確定状態でも「2FA 解除」ボタンが表示され、オーナーから見ると恰もそのメンバーの 2FA が有効であるかのように見える。本人の画面は終始「無効」のまま矛盾する。
- 阻害されたユーザージョブ: 組織オーナー/管理者がメンバーの実際のセキュリティ状態 (2FA 有効/無効) を正しく把握できず、誤って「このメンバーは 2FA で保護されている」と誤認する可能性がある (セキュリティ監査上の誤情報)。
- 改善アクション候補: メンバー一覧の「2FA 解除」ボタン表示条件を `two_factor_secret が存在する` ではなく `two_factor_confirmed_at が確定している` に揃える。
- 証跡: screenshots/S2-2fa-reset-button-visible.png (オーナー視点、無効なはずのメンバーに解除ボタン), screenshots/S2-2fa-reset-click-result.png (解除確認モーダル), network: DELETE /organizations/shard2-ysqn8w/members/10/two-factor => 303 See Other
- 推定原因: 未調査 (メンバー一覧のクエリ/表示条件が two_factor_secret の有無で判定している可能性)
- 関連既知情報: 未確認 (TODO.md 未参照)。F-02 (2FA 確定時のエラー無表示) と関連する可能性あり (どちらも 2FA 確定フローの検証不備が根)

