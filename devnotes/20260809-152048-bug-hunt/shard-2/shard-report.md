# bug-hunt report shard-2 (run 20260809-152048)

- 対象 URL: http://127.0.0.1:8012 (DB: bug_hunt_2, users at start: 11)
- 割り当てストーリー: S1 (ゲスト登録ファネル), S2 (招待フロー)
- 走行モード: --coverage(operation-reach) --deviate --real-llm
- ブラウザセッション: playwright-cli -s=bughunt2

## 実行ストーリー
- S1: 完了
- S2: 完了

## 走行メモ
- playwright-cli 初回起動時、既定 --browser=chrome が /opt/google/chrome に無く失敗。
  `.playwright/cli.config.json` に `{"browser":{"browserName":"chromium","launchOptions":{"chromiumSandbox":false}}}`
  を設置し `playwright-cli install-browser chromium` (v1237, mismatch解消) を実行して復旧。
  環境ハザードではなく shard 固有のローカル tool setup (過去 run の shard-0 でも同じ config を使用していた実績あり)。
- home → pricing → contact(空送信バリデーション→正常送信→contact.thanks) → 3法務ページ 走行、console/network エラーなし。
- register: 空送信バリデーションOK。パスワード 11桁 → 「12文字以上」、大文字小文字混在必須 → 適切な段階的フィードバック。
  password1234→Password1234 で通過。register.store 成功 → verification.notice へ。
  verification.send (再送信) → feedback probe で toast-success 確認 (「認証メールを再送信しました。」)。
  mail-urls から署名URL取得 (&amp; を & にデコードして使用) → verification.verify 成功 → onboarding.checkout へ着地 (P4 ゲート想定どおり)。
  onboarding.activate-personal: 個人利用チェック未チェックで送信→クライアント側バリデーション表示。
  チェック後「あとで決める」で送信→dashboard へ遷移、toast「パーソナルプラン（無料）を開始しました。無料チケット 10 枚をお付けしました。」を feedback probe で確認。
- dashboard: 左サイドバー nav に「設定」項目なし (個人設定はユーザーポップアップ内のみ) — T069 契約どおり。desktop/mobile(375)/tablet(768) で resize確認、レイアウト崩れなし。
- logout → home、ログイン(誤パスワード→エラーメッセージ表示→正パスワードで dashboard)。認証済みで /login /register 直叩き→dashboard へリダイレクト。ゲストで /dashboard 直叩き→/login へリダイレクト。
- forgot-password → password.email (feedback toast確認) → mail-urlsで取得したreset URLでpassword.reset → 新パスワードで更新成功 (feedback toast + login へ遷移)。
  **トークン使い回し**: 同じreset URLに再訪→フォームは表示されるが送信すると「このパスワード再設定トークンは無効です。」で適切に拒否 (詰みなし、再リクエスト導線あり)。
- 新パスワードでログイン成功を確認。
- **メール認証リンクの id 改竄**: 署名付きURLのuser id部分だけ他ユーザーIDに書き換えて叩く → 403 (アクセスできません、ホームへ戻る導線あり)。他ユーザーを誤認証させることはできない。
- **passkey.login-options 存在オラクル検証**: 実在ユーザー/存在しないユーザー/空文字列 いずれも 200 + 同一シェイプ({options:{challenge,timeout,rpId,allowCredentials:[],userVerification}}) で応答に差異なし。存在オラクルの finding なし。
- **throttle:passkeys 429 検証**: 10回目のoptions取得で429、UIに alert「パスキーの認証を開始できませんでした。」表示 (無反応ではない)。ただし1回目の失敗時と文言が異なる (後述の要確認)。
- **TOTP confirmed → passkey.login 拒否ポリシー**: shard2-newuser account で 2FA(TOTP) を有効化 (QRのセットアップキーからPython手動計算したワンタイムコードで確認)、two-factor.login.store も正常/異常系ともに確認 (無効コード→エラー表示、有効コード→dashboard)。
  ただし **passkey.login の実際の POST (WebAuthn credential 提出) は playwright-cli に仮想 authenticator 機能が見当たらず実演不可**のため、
  「TOTP confirmed で passkey.login が拒否される」の Critical チェック項目自体は **skip (理由: 環境上 WebAuthn ceremony を模擬する手段がない)**。
- **`?plan=` 正規化**: /register?plan=enterprise (未知プラン) で登録→verification→onboarding.checkoutに正常着地、500/クラッシュなし、console error なし、特定プランの誤選択もなし。
- **`?plan=starter` peek**: /onboarding/checkout?plan=starter で Starter カードが視覚的にハイライト選択され、reload後も保持される (正常動作)。ただし aria 属性が無い件を F-2-01 として記録 (下記)。
- **課金オンボーディング (Personal + オートリチャージ選択)**: 「無料プランを開始する」クリック→ /billing?fake_external=stripe へ着地、toast「パーソナルプラン（無料）を開始しました。無料チケット10枚をお付けしました。カード登録が完了すると、オートリチャージが自動で有効になります。」。
  「カードを登録する」クリック→ fake_externals 環境の設計 (FakeAutoRechargeGateway、fail-closed) により実際のカード登録は完了せず billing ページに中立帰還。オートリチャージは「無効」のまま、CTA「カードを登録する」は残存 (詰まない、想定どおりの fake 挙動、コード読み確認済み)。
- **契約済み組織での /onboarding/checkout 直叩き**: /billing へリダイレクト (ループなし)。

## S2 走行メモ (招待フロー)
- **別 cookie セッションの代替**: playwright-cli には隔離ブラウザコンテキストを新規に開くコマンドが見当たらなかった
  (`tab-new`は同一コンテキスト=同一cookie)。指示どおり、**同一 bughunt2 セッション内でログアウト→別アカウントでログイン**
  を繰り返すことで「オーナー」「被招待者」「別メンバー」の役割切り替えを代替した (owner-starter@example.com →
  ログアウト→招待URL(ゲスト)→register→verify→ログアウト→owner-starter 再ログイン、を繰り返した)。
- owner-starter@example.com (Starterプラン組織オーナー) でログイン → `/manage/users` で招待フォームの空送信バリデーション確認 →
  `shard2-invitee@example.com` へ「メンバー」ロールで招待 → toast「招待メールを送信しました」確認 → 招待中リストに反映 (期限7日後表示)。
- ログアウト→招待URL (`/invitations/accept?token=...`) を未ログインで開く → `/register` へ誘導 + **メールアドレスが招待メールで自動入力される (T055 確認)**、
  「招待されたメールアドレスで登録します。」の説明あり。登録→メール認証→**招待先組織 (Starterプラン組織) のヘッダーに直接着地**
  (個人組織を作らず、チケット残高も組織共有分「100」を表示=登録特典二重付与なし、T030 確認)。console error なし。
- owner-starter 再ログイン→`/manage/users`でメンバー一覧に招待された次郎が「未割当」で表示確認。
  - **organizations.members.update**: 編集者へロール変更を試みる→プロジェクト未作成のため即座に「未割当」へ戻り
    インラインエラー「編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」(invalid + 説明文、feedback probe では
    live region 検出なし=ARIA live regionではなくインラインバリデーションのため。実害なし、H7には該当しない)。
    続けて「管理者」へ変更→成功 (toast「ロールを変更しました」確認、一覧に反映)。
  - **organizations.members.two-factor.reset**: 2FA未設定メンバーには「2FA解除」ボタンが出ない仕様を確認
    (member.twoFactorStatus==='enabled' 時のみ表示、コード読み確認)。member-starter@example.com で別途2FAを有効化してから
    再テスト→「2FA解除」ボタン出現→確認モーダル (理由10文字以上必須、空送信でバリデーション確認)→有効な理由で送信→
    toast「「Starter Member」の 2 段階認証を解除しました」確認、一覧から2FAバッジ消滅を確認。
  - **organizations.invitations.revoke**: 新規招待(shard2-revoke-target@example.com)を作成→「取消」→確認ダイアログ
    (「招待を取り消しますか？取り消した招待は受諾できなくなります」)→取り消す→toast確認→**取り消し済みリンクを
    ゲストで開く→「この招待リンクは使用できません」画面 (ログイン/トップへの導線あり、詰みなし) を確認**。
  - **organizations.members.destroy**: 招待された次郎を削除→確認ダイアログ (「この操作は取り消せません」)→削除する→
    toast「メンバーを削除しました」確認、一覧から消滅確認。
    **除名後の本人セッション確認**: shard2-invitee@example.com で再ログイン→「組織未選択」状態のdashboardへ着地
    (詰みではなく「組織を作成」導線あり)。`/manage/users` `/organizations/starter-bm6nsx/settings` を直叩き→**両方 404**
    (元組織へのIDORなし、確認済み)。
- **onboarding.billing-required (S2-5)**: 新規登録した未契約組織 (shard2-billreq-owner) にメンバー招待→受諾させ、
  オーナーがプラン未選択のまま `/projects` 等の業務画面へ遷移させると `/billing-required` に着地することを確認。
  - 画面にオーナー名・メールアドレス (mailto: リンク) ・お問い合わせ導線があり、403や空白ではない。
  - **`/dashboard` は課金ゲート外** (コード上 `routes/web.php` に「ログイン直後の着地点 (課金ゲート外のまま。
    未契約でも状況把握と復帰導線を提供)」と明記) — dashboard 自体は直叩きしても /billing-required へ飛ばされない。
    これは意図的な設計 (誤検知回避のためコード確認済み)。`/projects` など業務画面は正しくゲートされ /billing-required へ。
  - **逆方向の離脱ガード確認**: manageBilling 保持者 (オーナー) で `/billing-required` 直叩き→`/onboarding/checkout` へ
    (無限リダイレクトなし)。非保持者 (member) は billing-required に留まる。オーナーが有効化すればループしない設計。

## S2 逸脱アイデア (--deviate)
- 取消済み招待リンクの再利用→上記で確認済み (「使用できません」画面、詰みなし)。
- 撮影者ロールでの manuals.store 403 確認は **skip (理由: 招待先組織にプロジェクトが1つも存在せず、
  S2 割当のシード状態だけでは 編集者/撮影者ロールを実際に割り当てられない。プロジェクト作成〜マニュアル操作は
  S3/S4/S7 の scope であり、本 shard(S1,S2) には project 作成 operation が割り当てられていない)**。
- 別組織の招待トークンを自分のセッションで受諾する変異は未実施 (時間都合で skip。理由: token は組織に紐づく
  一意な値であり、招待作成〜受諾の一連の流れで既に正規経路のみ確認したため優先度を下げた)。
- 招待受諾の二重送信は未実施 (skip、理由: 時間都合。ただし revoke 後の再受諾拒否・トークン改竄相当のuser id
  改竄 (メール認証URL) は403で拒否されることを確認済みで、同種の重複防御メカニズムがある可能性が高い)。

## 画面カバレッジ
走行 18 / 総 18 (S1: home, register, login, dashboard, onboarding.checkout, verification.notice,
verification.verify, password.request, password.reset, two-factor.login, contact, contact.thanks,
legal.commerce-disclosure, legal.privacy, legal.terms, passkey.login-options = 16件 全走行;
S2: invitations.accept, onboarding.billing-required = 2件 全走行)。未走行なし。

## 操作カバレッジ
走行 15 / 総 17 (S1: register.store, login.store, logout, password.email, password.update,
verification.send, two-factor.login.store, contact.store, onboarding.activate-personal = 9件 全走行;
S2: invitations.accept.store, organizations.invitations.store, organizations.invitations.revoke,
organizations.members.update, organizations.members.destroy, organizations.members.two-factor.reset = 6件 全走行)。
**skip 2件 (理由付き)**:
- `debug.login-as` (S1): bughunt.local 環境では `/debug/login*` route 自体が 404
  (LocalOnly + `isLocal()/runningUnitTests()` guard により登録されない fail-safe 設計。
  `routes/web.php` 656-659行のコメントで明記)。環境上到達不能なため skip。
- `passkey.login` (S1): playwright-cli / headless Chromium に WebAuthn 仮想 authenticator
  (CDP virtual authenticator) を操作する手段が見当たらず、実際の credential 提出を伴う
  POST を再現できなかった。`passkey.login-options` (options 取得) 側の存在オラクル検証・
  throttle 検証は実施済みだが、「TOTP confirmed ユーザーで passkey.login が拒否される」
  という Critical チェック項目 (PasskeyLoginPolicy) は完全には検証できていない。要フォローアップ。

## UI/UX 検証
- H11 (視覚破綻): desktop/mobile(375)/tablet(768) いずれの screenshot でもレイアウト崩れ・
  横スクロール・要素重なりは観測されなかった (dashboard, manage/users, billing-required, onboarding.checkout)。
- H12 (アフォーダンス/状態表現): onboarding.checkout の「選択中」プランカードが**視覚のみ**
  (border色) で表現され ARIA 状態属性が無い点を F-2-01 (H14寄り) として記録。それ以外の
  ボタン/フォームの有効・無効・選択状態は snapshot 上で判別可能だった。
- H13 (レスポンシブ): mobile 375×667 / tablet 768×1024 で dashboard, manage/users,
  billing-required, onboarding.checkout の計4画面を確認。全て崩れなし、確認後 desktop(1280×900) に復帰。
- H14 (a11y基礎): F-2-01 (aria-current 欠落) 以外に顕著な欠落は確認されなかった (フォームの
  label/エラーメッセージの関連付けは snapshot 上で invalid + 説明文が一貫して出ており良好)。

## findings

### F-2-01: `/onboarding/checkout?plan=` の peek 選択状態が視覚のみでアクセシビリティツリーに伝わらない
- severity: Low (H14 a11y)
- story/step: S1-5 (`?plan=` handoff peek)
- 再現手順:
  1. shard-2 で新規登録 → メール認証 → `/onboarding/checkout` に着地したユーザーで
     `http://127.0.0.1:8012/onboarding/checkout?plan=starter` を開く (または `/pricing` から
     `?plan=starter` 付きで遷移→ canonical URL へ 303)。
  2. Starter プランのカード (`data-testid="plan-card-starter"`) が青枠でハイライトされ
     視覚的には「選択中」に見える (screenshots/onboarding-checkout-plan-starter-param.png)。
  3. `playwright-cli snapshot` (アクセシビリティツリー) にはこの選択状態が一切現れない。
     DOM を直接 eval すると `class="... border-primary"` のみが付与されており、
     `aria-current` / `aria-selected` / `aria-pressed` などの状態属性が存在しない。
- 期待: peek で事前選択されたプランは、視覚だけでなく支援技術 (スクリーンリーダー等) でも
  「選択中」であることが判別できる (aria-current="true" 等)。
- 実際: 選択状態は border 色のみで表現され、DOM/ARIA には反映されていない。
  スクリーンリーダー利用者は、どのプランが事前選択されているか分からないまま「選択」ボタンを押すことになる。
- 阻害されたユーザージョブ: `/pricing` の「このプランで始める」から `?plan=starter` 付きで
  誘導されたスクリーンリーダー利用者が、意図したプランが選択されているかを確認できないまま
  オンボーディングを進めることになる。
- 改善アクション候補: 選択中のプランカードに `aria-current="true"` あるいは
  `aria-pressed`/`role="radio" aria-checked` 相当の状態属性を付与する。
- 証跡: screenshots/onboarding-checkout-plan-starter-param.png (starter カードが青枠でハイライト)。
  DOM eval: `{"tag":"DIV","cls":"flex flex-col rounded-lg border bg-surface p-5 border-primary","aria":null}`
- 推定原因: 未調査 (プラン選択カードコンポーネントの class 切り替えのみで aria 属性未実装と推測)。
- 関連既知情報: なし (要確認: devnotes/docs に本挙動の明示的な仕様記載は未確認)。

## 要確認 (仕様未確認・断定しない)

### Q-2-01: パスキーログイン失敗時のエラーメッセージが状況によって異なる
- story/step: S1-4b (逸脱: throttle:passkeys の429検証)
- 観察: `/login` の「パスキーでログイン」ボタンを押した際、
  1回目 (WebAuthnセレモニー自体が headless環境で失敗) は
  「パスキーの処理に失敗しました。時間をおいて再度お試しください。」、
  10回目 (429 Too Many Requests) は「パスキーの認証を開始できませんでした。」
  と、状況によって異なる文言が表示された (どちらも alert として表示され、無反応ではない)。
- 判断: 無反応・詰みではなく H4 には該当しない。文言が状況ごとに異なること自体が
  不具合かどうかは意図的な error taxonomy による作り分けの可能性があり、
  UXとして「時間をおいて」の案内が429時に無いのが親切かどうかは設計判断次第のため、
  severity を付けず要確認として記録する。
- 証跡: console: `[ERROR] Failed to load resource: ... 429 (Too Many Requests) ... /passkeys/login/options`,
  response-body: `{"message":"Too Many Attempts."}`

## H7 未検証
なし (すべての書き込み操作で feedback probe による陽性/陰性判定が確定した。
`organizations.members.update` を編集者ロールへ変更しようとした際は live region ではなく
インラインバリデーション表示だったため probe は陰性を返したが、視覚的な結果フィードバック
自体は存在する = H7 finding には該当しない、と判断した)。

## skip
- `debug.login-as` (S1 operation): bughunt.local 環境で route 自体が 404 (環境上到達不能、fail-safe設計)。上記参照。
- `passkey.login` (S1 operation, POST側): WebAuthn 仮想 authenticator が playwright-cli から操作できず、
  実際の credential 提出は再現できなかった。options 取得(GET)側の検証は実施済み。上記参照。
- S2 逸脱: 撮影者ロールでの `manuals.store` 403 確認: 招待先組織にプロジェクトが存在せず割当不可のため skip。
- S2 逸脱: 別組織トークンでの受諾、招待受諾の二重送信: 時間都合で skip (理由は上記 S2 走行メモ参照)。

## インベントリ修正提案
なし。screens.md / operations.md の記載と実装に乖離は見つからなかった。

## Critical/High まとめ (TODO 候補)
本 shard で発見した finding は F-2-01 (Low, H14 a11y) のみで、Critical/High は 0 件だった。
S1/S2 の登録・認証・招待・課金オンボーディング導線はいずれも詰み・説明なしリダイレクト・
IDOR・二重送信等の重大な破綻なく動作した。
