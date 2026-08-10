# bug-hunt report shard-4 (run 20260811-003230) 2026-08-11 開始

- shard: 4 / URL: http://127.0.0.1:8014 / DB: bug_hunt_4 / session: bughunt4
- 実行ストーリー: S6 (security-2fa-profile) のみ (1 枚を深掘り)
- skip したステップ (理由付き。詳細は各セクション参照):
  1. `passkey.registration-options` / `.store` / `.destroy` (登録操作) / `passkey.confirm-options` /
     `.confirm` (step-up 側) — headless chromium に platform authenticator が無く「この端末では
     パスキーを作成できません」で先に進めない環境制約。`passkey.destroy` への IDOR/型崩しのみ
     0 件状態で直 DELETE 確認済み。
  2. `settings.password.store` の UI クリック経路 (SSO-only ユーザーでの実クリック) — bughunt 環境は
     Socialite が fake 化されておらず「Google でログイン」が実 IdP へ遷移するため、SSO-only ユーザーを
     作れない (禁止事項 4 に抵触するため試行せず)。直 POST での bypass 不可は確認済み。
  3. `session.status` (bfcache guard 本命の経路B) の実機的な再現 — Playwright が Chromium に
     `--disable-back-forward-cache` を渡すため bfcache 自体が起動時点で無効
     (`docs/supported-browsers.md` 記載の既知のハーネス制約)。chromium/webkit いずれでも原理的に不可。
     ログアウト後 back で中身が漏れないことは確認したが、それは経路C (Inertia history 復元ガード) の検証。

## 画面カバレッジ (走行中に更新)
- settings — 走行済み (owner-personal / member-personal 両ロールで、プロフィール/パスワード/退会予約セクション)
- settings.security — 走行済み (2FA enable/confirm/regenerate/disable ダイアログ)
- password.confirm — 走行済み (実体は `/user/confirm-password` → `recent-auth.confirm` へ内部リダイレクトされ
  同一画面に統合されていることを確認。誤パスワードのバリデーション表示・throttle 到達も確認)
- recent-auth.status — 走行済み (XHR で `{recent:true,...}` を確認)
- recent-auth.confirm — 走行済み (上記 password.confirm と同一画面)
- notifications.index — 走行済み (一覧表示・既読化・open の空ターゲット処理・タブ title 確認)
- session.status (bfcache guard) — 走行済み (ログアウト後の browser-back で /login へ倒れ中身が
  漏れないことを確認)。**ただし `docs/supported-browsers.md` §bfcache 復元が自動回帰でカバーできていない理由
  に明記の通り、Playwright は既定で Chromium に `--disable-back-forward-cache` を渡しており
  bfcache 機構そのものが起動時点で無効。今回観測した「戻るで中身が漏れない」は本物の Safari 型
  bfcache 復元 (経路B, pageshow/session.status) ではなく、Inertia 側の history 復元ガード (経路C) を
  検証したものと考えられる**。経路 B (session.status を使う本命の bfcache guard) は本ハーネス
  (chromium/webkit いずれも) では原理的に再現できないとドキュメントに明記されており、
  **これは finding ではなく既知のハーネス制約として skip 扱いにする** (誤って「bfcache 復元を検証した」と
  過大申告しないための訂正)。iOS Safari 実機での受入確認が正本経路 (docs 記載) — bug-hunt shard の
  責務外。
- passkey.registration-options, passkey.confirm-options — **環境制約により skip** (headless chromium に
  platform authenticator が無く「この端末ではパスキーを作成できません」と表示され先に進めない。
  virtual authenticator を playwright-cli 経由で有効化する手段が見当たらず、CDP 直叩きは shard 権限外)

## 最重点: 2FA 秘密の GET / 有効化に対する再認証 (step-up) の鮮度切れ実地検証
- **実施済み**。owner-personal で 2FA を有効化した状態でログイン (15:58:18 JST 相当) し、
  `AUTH_RECENT_AUTH_TIMEOUT=900` (既定値、`.env.bughunt.local` に override なしを確認) を
  実際に 15 分待って `recent-auth/status` が `recent:false confirmedAt:null` になったことを確認
  (待機は一過性の環境検証のため許容。他の read-only 検証と並行して消化した)。
  その状態で `settings.security` の「リカバリコードを表示」(`two-factor.recovery-codes` = 秘密開示 GET と
  同種の step-up 対象) をクリックすると:
  1. **画面遷移せず、その場でモーダル「本人確認」が開いた** (`recent-auth-password-input` /
     `recent-auth-submit` の testid を持つ、`settings.security` に統合されたモーダル)。
  2. パスワードを入力して確認すると **モーダルが閉じ、設定画面から一切離脱せずにリカバリコードが
     そのまま展開表示された** (ボタンを再度押す必要も、ページ遷移もなし)。
  3. `recent-auth/status` を再確認すると `recent:true` に復帰 (session recent_auth_at が更新)。
  - **結論: 詳細設計 (docs/architecture.md §2FA 面の step-up 契約) が謳う「precheck ならモーダルで
    完結し設定画面から離脱しない」が、実機の鮮度切れ発火でも文書通りに機能することを確認できた。
    finding なし (期待通りの正常動作)**。念のため screenshot も残した
    (`screenshots/recent-auth-stepup-modal-success.png`)。
  - 待機中に並行して他の read-only 検証 (throttle レーン確認・IDOR 確認・a11y スナップショット等) を
    消化しており、単純な待ち時間の浪費にはしていない。

## 操作カバレッジ (走行中に更新)
- user-profile-information.update — 実行 (空値バリデーション→正常値保存→toast 確認、メールアドレス変更→
  /email/verify への遷移→署名 URL 踏破→反映→旧アドレスへの通知メール送信を確認)
- user-password.update — 実行 (空値/現パスワード誤り/複雑性バリデーション→正常変更→toast、表示トグル確認)
- two-factor.enable / .confirm — 実行 (owner-personal, member-personal の 2 アカウントで TOTP secret から
  実コード計算して確認まで完了)
- two-factor.regenerate-recovery-codes — 実行 (確認ダイアログあり→toast 1つ確認)
- two-factor.recovery-codes (表示) — 実行 (別ボタンで re-展開、throttle 分離を確認)
- two-factor.disable — 実行 (通常組織メンバーではダイアログ表示→キャンセルで維持を確認。2FA必須組織の
  メンバーでは「無効化する」を実際に押下し、`BlockTwoFactorDisableForEnforcedOrganizations` の
  明確なエラーメッセージ (要組織管理者への依頼) を確認 — 正常挙動)
- password.confirm.store / recent-auth.password — 実行 (誤パスワード×6連打で `password-verify` レーンが
  429 に到達することを確認。429 ページは「しばらく時間をおいてください」+ダッシュボード/トップへの
  導線ありで詰みなし)
- settings.account.deletion-request (store/destroy) — 実行 (予約→凍結中の画面到達性確認→取消の 3 パターン、
  さらに 2FA 必須組織×未準拠×予約中の相互作用を実機で再現 → **F-4-01 (High)**)
- settings.account.destroy (即時削除) — 直 DELETE で 409 ブロック確認 (予約中の迂回不可を確認、正常)
- settings.password.store — 直 POST で「すでにパスワードが設定されています」422 fail-closed を確認 (正常。
  UI 側の「パスワード設定」導線自体は SSO-only ユーザーが環境に存在しないため実クリックでは未確認 — 下記スキップ理由参照)
- organizations.two-factor-requirement.update — 実行 (owner から必須化 ON)
- notifications.read / .open — 実行 (既読化の視覚反映、開ける対象が無い通知での「開けるものがありません」
  フォールバック文言を確認)
- notifications.read-all — 実行 (再度予約→取消を 2 回行って未読 2 件を作り、「すべて既読にする」で
  toast 「すべての通知を既読にしました」を確認)
- passkey.store / .destroy / .confirm — **環境制約により skip** (上記画面カバレッジと同じ理由)。
  ただし `passkey.destroy` への IDOR/型崩し (`1`/`999999999`/`abc`/`-1`/20桁数値) は passkey 0 件の状態でも
  直 DELETE で全件 404 になることを確認 (存在オラクル化・500 なし。正常)
- **settings.password.store の SSO-only ユーザー経路は skip** (理由: bughunt 環境は `fake_externals` でも
  Socialite は fake化されておらず「Google でログイン」は実 IdP へ遷移する。実 IdP ドメインへの遷移は
  禁止事項 4 に抵触するため、SSO 登録→パスワード未設定ユーザーの作成そのものができない。
  代わりに「既にパスワードを持つユーザーが直 POST で bypass できないか」は確認し fail-closed を確認済み)
- **two-factor.login (リカバリコードでのログイン) — 追加実行**: `/two-factor-challenge` の
  「リカバリコード」タブから実際にリカバリコード 1 件でログインできることを確認。
  **同じコードで再ログインを試みると「二要素認証のリカバリーコードが無効です」で拒否**
  (使い切り = 一度使ったコードの再利用不可を実機で確認。正常)。
- **throttle レーン分離 — 追加確認**: `password-verify` (6/min) を故意に 429 まで枯渇させた直後でも
  同一画面上の `two-factor-secret-read` 系 (リカバリコード表示) は 429 の巻き添えを受けず正常に動作した。
  429 到達時のページも「しばらく時間をおいてください」+ 戻り導線ありで詰みなし (逆方向 = 再認証自体が
  429 で詰まる懸念も、上記の「最重点」節の実地検証で解消を確認)。

## UI/UX 検証 (H11-H14)
- H13: `/settings` と `/settings/security` を mobile 375×667 / tablet 768×1024 で確認。
  ハンバーガーメニューへの折り畳み、フォーム幅の追従とも問題なし (screenshots/H13-*.png)。破綻なし。
- H11/H12/H14: 明確な崩れ・状態不明の操作要素は今のところ観測なし (毎操作 snapshot で role/name 取得可能)。

## H7 未検証一覧
- (今のところ該当なし。probe は毎操作で installed_now:false かつ結果文言を確認できている)

## findings サマリ (確定)
- Critical 0 / High 1 (F-4-01) / Medium 0 / Low 0 / 要確認 0

## インベントリ修正提案
- なし。screens.md / operations.md の S6 該当行 (password.confirm = `/user/confirm-password` 実体、
  recent-auth.* 系、settings.account.deletion-request の store/destroy 等) はすべて実装と一致していた。
  ドリフトは検出しなかった。

## 総括 (走行完了)
S6 の screen/operation はほぼ全て実走行し、環境制約 (passkey 作成不可・SSO 実 IdP 遷移禁止・
Playwright の bfcache 無効化) による 3 件の skip はいずれも理由を明記した。最重点として指示された
「2FA 秘密 GET / 有効化への再認証 (step-up) の鮮度切れ」は実際に 15 分待って発火させ、モーダル完結・
画面離脱なしを実機確認できた (finding なし = 良好)。「猶予期間つき削除 × 2FA 必須組織」の相互作用は
設計文書が既知の危険領域として明記していた箇所を実際に操作し、文書が想定する「詰みではない」ことは
確認できた一方、**取消操作の結果メッセージが操作と無関係に見える UX 上の分かりにくさ (F-4-01, High)**
を新規に発見した。他の 6 レーン throttle 分離・IDOR (passkey.destroy 型崩し)・recovery code 使い切り・
settings.password.store の fail-closed・即時削除の凍結中ブロック (409) など、S6 で新設・変更された
セキュリティ境界はいずれも設計文書どおりに機能していることを実機で確認した。

---

# Findings 詳細 (severity 降順、逐次追記)

## F-4-01: 2FA必須組織の未準拠ユーザーが「退会を取り消す」を押すと、取消と無関係な2FA案内へ無言で差し替わり、取消できたかどうか分からない
- severity: High
- story/step: S6-4 (アカウント削除 猶予期間) × S6-2 (2FA必須) の相互作用
- 再現手順:
  1. owner-personal@example.com でログイン (password123)。`/organizations/personal-mi2f5h/settings` で
     2要素認証を有効化 (TOTP)。同じ組織設定画面で「2 段階認証の必須化」を有効にする。
  2. 別アカウント member-personal@example.com でログイン (2FA 必須化の**前**に、`/settings` で
     「30日後に削除 (取り消せます)」を押して退会を予約しておく)。
  3. member-personal でいったんログアウトし、owner が 2FA 必須化を ON にした**後**に
     member-personal で再ログインする (この時点で member はまだ 2FA 未設定 = 非準拠)。
     ログイン直後 `/settings/security` へ強制遷移し「組織「Personalプラン組織」は
     2 段階認証を必須としています。設定が完了するまで他のページはご利用いただけません。」と出る
     (ここまでは説明があり OK)。
  4. `/settings` に直接遷移すると (route 名 `settings` は 2FA ゲートの許可リストに入っているため) 到達でき、
     「退会を予約しています」の状態表示と「退会を取り消す」ボタンが**普通に操作可能な見た目で**表示される。
  5. 「退会を取り消す」ボタンをクリックする
     (`DELETE /settings/account/deletion-request` → `RequireTwoFactorForEnforcedOrganizations` が
     このルート名を許可リストに含まないため 303 で `/settings/security` へリダイレクト)。
  6. 画面は `/settings/security` に切り替わり、toast/info で
     「組織「Personalプラン組織」は 2 段階認証を必須としています。設定が完了するまで他のページは
     ご利用いただけません。」とだけ出る。**「退会」「取消」という語は一切出てこない。**
     退会予約が取り消されたのか、まだ有効なのかはこの画面から判断できない。
- 期待: 「取消」ボタンを押した結果として出るメッセージは、少なくとも
  (a) 退会予約はまだ有効であること、(b) なぜ今操作できないか (2FA未設定) の**両方**を伝えるべき。
  可能なら「2FA を設定すると退会の取消操作を続けられます」のように、押した操作へ話をつなげる文言が要る。
- 実際: 押した操作 (退会取消) と表示される案内 (2FA必須化) が文言上まったく接続されておらず、
  ユーザーは「取り消せた」のか「取り消せていない」のか、この画面のどこにも書かれていないため
  分からない (実際には取り消されておらず、予約は生きたまま)。H1 (説明なしリダイレクト) 相当。
  同じ文言 (組織「〜」は2要素認証を必須としています…) が退会取消のときも 2FA が要る他の操作のときも
  一律で出るため、**押した操作が何だったかの手がかりが画面から失われる**。
- 阻害されたユーザージョブ: 「予約してしまった退会を取り消したい」という、猶予期間つき削除の設計が
  最も重視している救済導線 (「取消に step-up を課さない」という設計判断がある領域) が、
  2FA 必須組織下では実質的に見た目上「押しても反応が説明されないボタン」になる。
  ユーザーは自分の退会予約が有効なままだと気づかず 30 日後に削除されるリスク、または
  何度も無意味に「取消」ボタンを押し続けるリスクがある。
- 改善アクション候補:
  - `RequireTwoFactorForEnforcedOrganizations` のリダイレクト/409 メッセージに、元々何をしようとしていたか
    (route 名やコンテキスト) を埋め込めるようにする、または
  - `settings.account.deletion-request.destroy` (取消) を 2FA ゲートの許可リストに追加する
    (取消は「離脱系」操作であり、`logout` 等と同様に 2FA 未設定でも通す設計判断もありうる。
    ただし `two-factor.disable` を意図的に許可リストへ入れていない既存判断
    「ゲート解除手段の濫用防止」との整合は要検討)、または
  - 少なくともクライアント側で「取消を試みたが 2FA 未設定でブロックされた」ことを検知し、
    `/settings` 側の退会バナーに「2FA 設定が必要です」という文言を先出しして
    ボタンを押す前に気づかせる (`disabled` にしてツールチップで理由を示す等)。
- 証跡: network: `DELETE http://127.0.0.1:8014/settings/account/deletion-request => 303`
  (直後 `GET /settings/security => 200`),
  feedback-probe (取消クリック直後): `installed_now=true seen(visible:true)=1 present_new=2 pending=0 errors=0`
  (present_new の内訳は toast-info の「2段階認証を必須としています」のみで、退会/取消に言及する文言なし)。
  ※ 本 finding 発生後、member-personal は 2FA 準拠済みに変わってしまい (テスト手順上必要だったため)、
  再現には別途「2FA必須組織×未準拠×退会予約中」の状態を作り直す必要がある。screenshot は未取得
  (取得を試みた回で `playwright-cli screenshot` の引数仕様 (`--filename` 必須) を誤り撮り損ねた)。
  再現手順は上記で全ステップ記載済みのため、network trace と probe 証跡で代替する。
- 推定原因: `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` の
  `ALLOWED_ROUTE_NAMES` に `settings.account.deletion-request.destroy` (取消) が含まれていないため、
  凍結許可リスト (`AccountDeletionFreezeAllowance`) 側では通っていても、優先度が先に走る 2FA ゲートで
  弾かれる。ゲートのリダイレクト先メッセージが汎用文言のみで、遮断された元操作のコンテキストを持たない。
  (docs/architecture.md の「2FA 必須組織との相互作用」節が明示的に「取消 DELETE は 2FA ゲートが
  settings.security へ倒す」設計だと記述しており、**永久な詰みではない** — 2FA を完了すれば取消できることは
  実機で確認済み。ただし「取消と無関係に見えるメッセージだけが出る」UX 上の分かりにくさは
  ドキュメントが明言していない残存論点)。
- 関連既知情報: `docs/architecture.md` §退会の猶予期間つき削除 (凍結方式・30 日) 「2FA 必須組織との相互作用」
  (T142 で発見・修正されたのは「settings.security 自体への到達性」であり、
  今回の finding はその一段先の「取消操作の結果文言の分かりにくさ」で、既存記述からは
  fix 済みと明言されていない別論点)。
