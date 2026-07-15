# bug-hunt shard-2 report (run 20260715-213842)

- 対象 URL: http://127.0.0.1:8012 (DB: bug_hunt_2)
- 担当ストーリー: S1 (登録/ログインファネル), S2 (招待フロー)
- 主眼: 回帰確認 (T055招待prefill/T030招待先所属, T045特商法フッター, F-2-01 forgot-password FB, HIBP無効化T064所感) + 通常探索
- 開始: db-check OK (bug_hunt_2, users=8)

## 実行ストーリー
- **S1 (ゲスト登録ファネル)**: 完走。home (CTA/フッター法務リンク) → pricing (FAQ アコーディオン) → contact (空値バリデーション→正常送信) → contact.thanks → register (空値バリデーション→パスワード長/大文字小文字バリデーション→正常系) → email/verify (再送→トースト確認) → メール認証 (verification.verify) → dashboard (個人組織+チケット10枚を確認) → logout → login (誤パスワード→エラー表示→正常系) → 認証済みで /login /register に直アクセス→dashboard へリダイレクト確認 (逸脱) → forgot-password→reset-password→新パスワードでログイン確認 → two-factor 有効化 (TOTP実算出) → 再ログインで two-factor-challenge (誤りコード→エラー→正しいコード→ログイン成功) → terms/privacy 閲覧。
- **S2 (招待フロー)**: 完走。プロジェクト作成 (編集者/撮影者ロール招待の前提解除) を挟み、organizations.invitations.store (空値バリデーション→編集者ロールで送信、3点セット確認) → 未ログインで invitations.accept を開く → **T055 確認OK** (register フォームに招待メールが readonly で自動入力) → 新規登録→メール認証 → **T030 確認OK** (招待先組織のみに所属、個人組織なし、チケット残高0=二重付与なし、組織スイッチャーにも招待先組織のみ表示) → organizations.members.update (ロール変更、reload後も永続) → 招待先ユーザーで 2FA 有効化 (TOTP実算出) → organizations.members.two-factor.reset (確認ダイアログ+理由必須バリデーション+3点セット確認) → 2件目の招待作成→organizations.invitations.revoke (確認ダイアログ+3点セット確認) → 失効/受諾済トークンの再利用が正しく拒否されることを確認 (逸脱アイデア) → organizations.members.destroy (確認ダイアログ+3点セット確認、除名後のユーザーが「組織を作成」導線に落ちることを確認)。

## skip したステップ
- debug.login-as (S1 operations 記載): APP_ENV=bughunt.local の環境では `app()->isLocal()` が false のため route 自体が登録されない設計 (routes/web.php の isLocal/runningUnitTests ガード)。bughunt 環境では到達不能なのが意図通りであり、通常ログインフローで代替検証した。
- 撮影者ロールでの manuals.store 403 確認 (S2 逸脱アイデア末尾): S7 (認可境界) 担当領域と重複するため今回は skip (理由: shard-2 は S1/S2 担当で S7 は別 shard 想定)。

## 画面カバレッジ
- 走行 14 / 14 (S1: home, register, login, dashboard, verification.notice, verification.verify, password.request, password.reset, two-factor.login, contact, contact.thanks, legal.commerce-disclosure, legal.privacy, legal.terms / S2: invitations.accept)

## 操作カバレッジ
- 実行 13 / 14 (S1: register.store, login.store, logout, password.email, password.update, verification.send, two-factor.login.store, contact.store / S2: invitations.accept.store, organizations.invitations.store, organizations.invitations.revoke, organizations.members.update, organizations.members.destroy, organizations.members.two-factor.reset)
- 未実行: debug.login-as (上記 skip 理由参照)

## UI/UX 検証
- **H11 (視覚破綻)**: 致命的なレイアウト崩れ・overflow は発見せず。desktop/mobile/tablet いずれも問題なし。
- **H12 (アフォーダンス/状態)**: FAQ アコーディオン ([expanded] 状態明示)、モバイルナビトグル ([expanded]+ラベル切替「メニューを開く」→「メニューを閉じる」)、2FA チャレンジ画面のタブ切替 ([selected] 状態) など状態表現は概ね明確。バリデーションエラーは inline 表示+invalid 状態が一貫して機能。
- **H13 (レスポンシブ)**: mobile 375x667 で home / register / dashboard を確認 (ハンバーガーメニュー正常動作、overflow なし)。tablet 768x1024 で home を確認 (nav 横並び、overflow なし)。確認後 desktop (1280x900) に復帰済み。
- **H14 (a11y 基礎)**: モバイルメニューボタンに「メニューを開く/閉じる」の明確な aria 相当ラベルあり。フォーム要素は role/name が snapshot から正しく取得でき、label 欠落は見つからず。console error は全走行を通じて 0 件。

## findings サマリ
- Critical 0 / High 0 / Medium 1 (F-2-02) / Low 0 / 要確認 0

## 回帰確認チェックリスト
- [ ] T055 招待メール自動入力 (未ログイン招待→register prefill) - S2 で検証予定
- [ ] T030 招待先所属 (個人組織なし・特典二重付与なし) - S2 で検証予定
- [x] T045 特商法フッターリンク到達 - **確認OK**。home / pricing 双方のフッターに「特定商取引法に基づく表記」リンクがあり `/commerce-disclosure` へ正しく遷移 (200, console error なし)。
- [x] F-2-01 forgot-password 成功トースト表示 (誤検知/回帰判定) - **誤検知確定 (regression なし)**。`/forgot-password` 送信直後の snapshot ではトーストが写らないが、1.5秒待ってから snapshot すると「パスワードリセット用のリンクをメールで送信しました。」の緑トーストが正しく表示される (Inertia+Svelte の非同期描画によるタイミングの問題で、実際は表示されている)。同様に「認証メールを再送信」ボタンも即時 snapshot ではトーストが写らないが、0.3秒待つと表示された。前回 run (20260715-084108) の F-01 (Medium) は snapshot を早まった誤検知と判定。証跡: screenshots/regression-forgot-password-toast-confirmed.png
- [x] HIBP 無効化(T064) 所感 (登録/パスワード操作の速度感) - 通常登録 (POST /register → 302 リダイレクト) が約 2.8秒で完了。実 HIBP API に問い合わせている場合の典型的な体感遅延 (数秒〜) と比べて明確に高速。バリデーション往復 (パスワード長・大文字小文字要件) も即時 (1.4秒) に返っており、HIBP 無効化が効いている所感と一致。

## findings
- Critical 0 / High 0 / Medium 1 (F-2-02) / Low 0 / 要確認 0

---

## F-2-02: パスワードリセット完了後、ログイン画面に成功フィードバックが無い
- severity: Medium
- story/step: S1-5 (password.request → password.reset → password.update)
- 再現手順: `/forgot-password` でメールアドレス送信 → `mail-urls` で reset リンク取得 → `/reset-password/{token}?email=...` を開く → 新パスワードを入力し「パスワードをリセット」をクリック。
- 期待: `password.update` 成功後、遷移先の `/login` でパスワード変更完了を示す成功フィードバック (トースト等) が表示される。同フローの `forgot-password` (password.email) や email 認証再送 (verification.send) では、レスポンス後 1.5秒/0.3秒待つと緑色の成功トーストが正しく表示される (下記 F-2-01 回帰確認チェックリスト参照)。
- 実際: POST `/reset-password` → 302 → GET `/login` (200) は成功するが、1.5秒以上待っても `/login` 画面に成功トースト・メッセージは一切表示されない (console error なし)。ユーザーはパスワードが実際に変更されたのか確信が持てない。実際には新パスワードでログインでき、機能的には成功している。
- 阻害されたユーザージョブ: パスワードを再設定したユーザーが「変更が完了したか」を確信できず、不安なまま再度操作を試みたり、サポートに問い合わせたりする可能性がある。
- 改善アクション候補: `password.update` コントローラで `/login` へのリダイレクト時に flash メッセージ (例:「パスワードを変更しました。新しいパスワードでログインしてください」) をセットし、他フロー同様 flash→toast で表示する。
- 証跡: screenshots/F-2-01new-reset-password-no-toast.png, console: no errors, network: POST /reset-password => 302 Found → GET /login => 200 OK
- 推定原因: 未調査 (password.update のコントローラ、または login 側の toast コンポーネントが reset 由来の flash を拾っていない可能性)
- 関連既知情報: 過去 run (20260715-084108 F-01, 20260714-154640 F-2-01) は forgot-password 送信直後の成功トースト欠落を報告していたが、今回 1.5秒待って再検証した結果それは snapshot タイミングの誤検知と判明 (上記チェックリストの F-2-01 参照)。**本 finding (F-2-02) は reset-password 完了後 (password.update) → login 遷移時のフィードバック欠如であり、上記の誤検知とは別の症状** (forgot-password 送信時点のトーストは正常に出る)。

---

## インベントリ修正提案
- なし。screens.md / operations.md と実装の乖離は見つからなかった。debug.login-as は bughunt 環境 (APP_ENV=bughunt.local) では isLocal() ガードにより意図的に到達不能 (skip 理由参照) であり、インベントリ記載自体は正しい (local 環境での担保用)。

## Critical/High フォロー候補
- なし。新規 Critical/High finding は 0 件。

## 要確認
- なし。

## 総括
- S1/S2 の主要フロー・バリデーション・破壊的操作の確認ダイアログ・IDOR に隣接する招待トークンの失効/再利用防止・T055/T030 の新機能回帰・T045 (特商法リンク)・HIBP 無効化 (T064) の速度所感を含め、走行プロトコルに沿って完走した。新規 finding は F-2-02 (Medium、パスワードリセット完了後の成功フィードバック欠如) の 1 件のみで、Critical/High は検出されなかった。前回 run で疑われていた F-2-01 (forgot-password 送信直後の成功トースト欠落) は、snapshot タイミングの誤検知であることを 1.5秒待機での再検証により確定した (regression なし)。

(走行完了)
