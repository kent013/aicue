# bug-hunt report shard-4 (run 20260812-100645) 2026-08-12 開始

- shard: 4 / URL: http://127.0.0.1:8014 / DB: bug_hunt_4 / session: bughunt4
- 実行ストーリー: S6 (security-2fa-profile) のみ (1 枚を深掘り)
- 走行方針: 前回 run (20260811-003230) の F-4-01 (2FA必須組織×退会取消の詰み) は
  `RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES` に
  `settings.account.deletion-request.destroy` が追加され修正済みとコード確認済み。
  回帰していないか実機で再確認する。CodeSnippet のコピー失敗時挙動 (manual-selected /
  manual-unselected 案内、自動で消えない) も新規に操作確認する。

## 画面カバレッジ (走行中に更新)
- settings — 走行済み (owner-personal / member-personal 両ロール)
- settings.security — 走行済み (owner / member 両方、2FA enable/confirm/regenerate/disable ダイアログ)
- password.confirm — 走行済み (`/user/confirm-password` → `/recent-auth/confirm` へ内部リダイレクト。前回同様の統合実装を再確認)
- recent-auth.status — 走行済み (XHR で `{recent:true,...}` 確認)
- recent-auth.confirm — 走行済み (上記と同一画面。誤パスワードのバリデーション表示確認)
- notifications.index — 走行済み (一覧表示・既読化・一括既読・open の空ターゲット処理・タブ title 確認)
- session.status (bfcache guard) — 走行済み (ログアウト後の browser-back で `/dashboard`→`/login` へ
  秒殺で倒れ中身が漏れないことを確認、console error なし。前回 run 同様 Playwright は
  `--disable-back-forward-cache` で起動するため本物の bfcache 経路B の検証は構造的に不可 [既知のハーネス制約、
  finding ではなくskip] — 今回観測したのは Inertia history 復元ガード [経路C])
- passkey.registration-options, passkey.confirm-options — 環境制約により skip (headless chromium に
  platform authenticator が無く「この端末ではパスキーを作成できません」。前回 run と同条件を確認済み)

## 操作カバレッジ (走行中に更新)
- user-profile-information.update — 実行 (空値バリデーション→正常値保存→toast確認)
- user-password.update — 実行 (現行パスワード誤り/新パスワード文字数不足/大文字小文字要件のバリデーション→
  正常変更→toast、表示トグル(T042)確認)
- two-factor.enable / .confirm — 実行 (owner-personal で TOTP secret から実コード計算し確認完了)
- two-factor.regenerate-recovery-codes — 実行 (確認ダイアログ→実行→toast 1つのみ確認、T026)
- two-factor.disable — 実行 (owner-personal でダイアログ→キャンセルで維持を確認。owner/member/admin いずれも
  2FA必須組織のメンバーであるため、UI 経由 [確認ダイアログ実行] と直 DELETE 経由の両方で
  `two_factor_disable_forbidden` の fail-closed を確認。「無効化する」を押しても実際にブロックされる
  正常系は前回 run と回帰なし)
- password.confirm.store / recent-auth.password — 実行 (誤パスワードのバリデーション表示→正常確認→dashboardへ遷移)
- settings.account.deletion-request (store/destroy) — 実行 (**F-4-01 回帰確認: 修正確認、詳細は findings 外の
  「回帰確認ノート」参照。取消は成功しtoast「退会の予約を取り消しました。」が表示され、DELETE は 303 で
  /settings へ直接戻るようになった。前回の /settings/security への誤誘導は解消**。owner-personal (last-owner)
  でも store 自体は成功することを確認、詳細は「追加検証」節)
- settings.account.destroy (即時削除) — 実行 (退会予約が有効な状態での直 DELETE が `409` で
  fail-closed になることを admin-personal でクリーンに 2 回再現・確認。ただし member-personal で
  1 回、想定外の経緯でアカウントが実際に削除される事象が発生し **F-4-Q1 (要確認)** として記録)
- settings.password.store — 実行 (既にパスワードを持つ owner/member/admin いずれかで直 POST → 422
  「すでにパスワードが設定されています」で fail-closed を確認。SSO-only ユーザー経路は禁止事項4
  [実 IdP 遷移] のため作成不可で skip [前回 run と同条件])
- notifications.read / .open / .read-all — 実行 (既読化の視覚反映、開ける対象が無い通知での
  「この通知には開ける対象がありません。」フォールバック toast、一括既読の toast 確認)
- passkey.store / .destroy / .confirm — 環境制約により skip (headless chromium に platform authenticator
  なし)。`passkey.destroy` への IDOR/型崩し (`1`/`999999999`/`abc`/`-1`/20桁数値) は
  passkey 0 件の状態で全件 404 (存在オラクル化・500 なし) を確認

## UI/UX 検証 (H11-H14)
- H13: `/settings/security` (owner-personal, 2FA 有効 + passkey 状態表示あり) を mobile 375×667 /
  tablet 768×1024 で確認 (`screenshots/H13-settings-security-mobile-375x667.png`,
  `screenshots/H13-settings-security-tablet-768x1024.png`)。ハンバーガーメニューへの折り畳み、
  カード幅の追従、passkey status の複数行文言も折り返しのみで overflow なし。
  `/settings` (プロフィール/パスワード変更) も mobile で確認
  (`screenshots/H13-settings-profile-mobile-375x667.png`)。破綻なし。確認後 desktop (1280x800) に復帰。
- H11/H12: 崩れなし。ボタンの primary/danger 色分け (2要素認証を無効化=赤枠、パスキーを登録=青背景) が
  一貫しており階層は判別可能。
- H14: 毎操作 snapshot で role/name/state が取得できており focus 到達性上の明白な欠落は観測なし
  (詳細な contrast 測定は未実施、時間予算outside scope)。

## H7 未検証一覧
- なし。書き込み操作は毎回 feedback probe で `installed_now:false` かつ結果文言 (toast-success /
  toast-error / toast-info の視認可能テキスト) を確認できている。唯一 `installed_now:true` が返った
  操作 (2FA enable 確認、2FA disable 確認クリック直後など) も、画面自体の劇的な状態変化 (2要素認証:
  無効→有効、リカバリコード一覧の出現等) が肯定証拠として得られているため H7 には計上しない。

## findings サマリ (確定)
- Critical 0 / High 0 / Medium 0 / Low 0 / 要確認 1 (F-4-Q1)

## 追加検証: last-owner ブロッカーと退会予約の共存表示
- owner-personal (唯一 Owner) で `settings.account.deletion-request.store` (30日後に削除) を実行すると、
  「対応が必要」バナー (last-owner ブロッカー) が出ていても **store 自体は成功し**、
  toast「退会を予約しました。2026年9月11日までは取り消せます。」が表示され、
  「退会するには先に対応が必要です」バナーと「退会を予約しています」バナーが**両方同時に表示**される。
  「退会を予約しています」側の文言が「上に「対応が必要」と出ている場合は削除できないため、
  毎日1回自動で削除を再試行します」と明示的に接続しているため、2つのバナーが矛盾せず読める
  (H10 の懸念なし。設計通り「削除時にサーバーが再判定する」ため store 自体はブロックしない仕様と判断)。
  screenshot: `screenshots/owner-deletion-request-with-blocker.png`。**finding なし**。取消して原状回復済み。
- `two-factor.disable` の直 POST(DELETE) bypass も確認: owner-personal (2FA必須組織のメンバー) に対し
  直 `DELETE /user/two-factor-authentication` を叩くと `422 {"code":"two_factor_disable_forbidden",...}`
  で fail-closed (UI のダイアログ確認を経由しなくてもサーバー側で同じブロックが効く)。**finding なし**。
- `user-profile-information.update` の直 PUT でメールアドレス変更 (`owner-personal@example.com` →
  `owner-personal-changed@example.com`) を試行 → **fresh な recent-auth (数分前にログイン済み) の状態では
  200 で成功**し、`/email/verify` へ誘導された。`mail-urls` で確認した検証リンクを踏んで verify 完了。
  **旧アドレス (owner-personal@example.com) へ「メールアドレスが変更されました」という通知メールが
  実際に送信されていることを `storage/logs/laravel.log` で確認** (件名: 【AI-CUE】メールアドレスが
  変更されました)。deviate アイデアの「変更成功時に旧アドレスへ通知されるか」は確認できた
  (**finding なし**)。ただし「stale セッション (recent_auth 未 stamp) では step-up を要求されるか」の
  サブケースは、本 shard では recent-auth が終始 fresh だったため独立には再検証できていない
  (コード上は `FortifyServiceProvider` で `user-profile-information.update` に
  `recent-auth.on-email-change` middleware が明示的に配線されていることを確認済み。
  実機での鮮度切れ発火は前回 run が別操作 [`two-factor.recovery-codes`] で機序として確認済みのため、
  同じ機構の別ルートとして参照確認に留める。**H7 未検証ではなく「skip: 時間予算」として記録**)。

## 回帰確認ノート: F-4-01 (前回 run 20260811-003230 で発見・修正済み)
- コード確認: `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` の `ALLOWED_ROUTE_NAMES` に
  `settings.account.deletion-request.destroy` が追加され、コメントで F-4-01 を明示的に参照している。
- 実機再現: owner-personal で 2FA 有効化 + 組織「Personalプラン組織」の 2FA 必須化 ON。member-personal
  (2FA未設定・非準拠) が退会予約 (`30日後に削除`) 済みの状態でログインし `/settings/security` へ強制遷移。
  `/settings` に直接遷移し「退会を取り消す」をクリック →
  `DELETE /settings/account/deletion-request => 303 See Other` → `/settings` へ直接戻り
  `toast-success: "退会の予約を取り消しました。"` が表示され、画面も「30日後に削除」ボタンへ復帰
  (取消が実際に成功していることを確認)。**前回観測された「2FA必須の汎用メッセージに無言ですり替わる」
  症状は解消。finding なし (修正確認)**。

## 追加検証: CodeSnippet コピー失敗時の挙動 (T042/2FAセットアップキー)
- Clipboard API を `navigator.clipboard.writeText` の reject に patch → 通常は
  `document.execCommand("copy")` の legacy fallback が headless chromium で成功し
  「コピー完了」表示になることを確認 (これは設計通りの動作)。
- **さらに `document.execCommand` も無効化して両方失敗させる**と、期待通り
  `manual-selected` 状態 (`コピーできませんでした。上のテキストを選択したので、⌘C / Ctrl+C
  (スマートフォンでは端末のコピー操作) でコピーしてください。`) が表示され、
  `window.getSelection().toString()` がセットアップキー文字列と一致 (実際に選択状態になっていることを実測)。
  5秒待機後も案内は自動で消えなかった (設計通り「自動で消さない」)。**finding なし (仕様通り)**。

---

# Findings 詳細 (severity 降順、逐次追記)

## F-4-Q1 (要確認): member-personal アカウントが「退会予約中の凍結」下で直 DELETE により消失した (2回のクリーン再現では再現せず)
- severity: 要確認 (severity なし。誤検知の可能性が高いが、実データ消失が起きたため記録する)
- story/step: S6-4 の逸脱アイデア「アカウント削除を...」に隣接する検証中の事故的発見
- 観測した事実:
  1. member-personal@example.com (2FA 準拠済み、退会予約 (`30日後に削除`) を作成し**取消していない**状態) で
     `playwright-cli goto http://127.0.0.1:8014/dashboard` を実行した直後 (ページ遷移完了とほぼ同時)に、
     同一 eval コール内で `fetch(origin + '/settings/account', {method:'DELETE', headers:{X-XSRF-TOKEN,
     Accept: application/json}, credentials:'same-origin'})` を実行した。
  2. **1回目の fetch (goto と同一 shell 呼び出しで連続実行、ネットワークが未確定な状態) は
     `405 Method Not Allowed` (`route /` 宛という不可解な応答本文)を返した。**
  3. **直後の2回目の fetch (ページが `/dashboard` に確定した状態を snapshot で確認してから実行) は
     `401 {"message":"Unauthenticated."}` を返した** (`not-pending-deletion` ミドルウェアの
     `409 退会予約中のため...` ではなく、素の `auth` ミドルウェアの未認証応答)。
  4. 直後の `GET /recent-auth/status` も同じセッションから `401 Unauthenticated` になり、
     `reload` で `/login` へ強制的に遷移した (完全にログアウト状態)。
  5. `tmp/bug-hunt/shard-4-cmd.sh db-check` の `users` count が **11 → 10 に減少**していた。
  6. その後 `member-personal@example.com` / `password123` でのログインは
     **「認証に失敗しました。」(通常の資格情報不一致メッセージ) で失敗する**ようになった
     (owner-personal 等、他アカウントのログインは正常)。
  → 上記 1〜6 を総合すると、**退会予約 (凍結) が有効なまま `settings.account.destroy`
     (即時削除、猶予 30 日の凍結対象外と設計で明記) が実行され、アカウントが恒久的に削除された**
     ように見える。設計 (`AccountDeletionFreezeAllowance` の doc comment) はこれを明示的に
     **意図的に禁止**しており (「猶予が守ろうとしているものをそのまま通してしまう」)、
     もし本当に迂回されていたら **Critical** 級 (30日猶予の完全な無効化 + 復旧不能なデータ損失)。
- **再現性の検証 (重要)**: 同一条件 (2FA 準拠済み・退会予約が有効・direct DELETE を goto 直後に発火) を
  **admin-personal@example.com で 2 回、慎重に再試行**したが、**いずれも設計通り
  `409 {"message":"退会予約中のため、この操作はできません。設定画面から退会を取り消してください。"}`
  が返り、`users` count も変化しなかった** (2回目は `goto /dashboard` も正しく `/settings` へ
  302 され、freeze が dashboard アクセス自体もブロックしていることを確認)。
  一方、member-personal の事故発生時は `goto /dashboard` 後のページ URL が **`/dashboard` のまま
  redirect されていなかった** (admin-personal の再現では `/settings` へ redirect された) —
  この一点だけが両者で食い違っており、**member-personal は事故発生時点で実は退会予約状態ではなかった
  可能性** (何らかの理由で凍結解除済みだった、または直前の別操作でクリアされていた) を否定できない。
  ただし本 shard の操作ログを見返す限り、退会予約作成後に取消操作をした記憶/記録はない。
- 阻害されたユーザージョブ: (もし再現するなら) 「30日以内に気が変わって取り消したい」という
  猶予期間つき削除の中核的な安全網が、direct API 呼び出し (悪意あるクライアント・ブラウザ拡張・
  リプレイ攻撃等) から機械的に迂回され、ユーザーの意思に反して即座に・不可逆にデータが消える。
- 証跡: `db-check` before=11 users, after=10 users。ログイン失敗スクリーンショット未取得
  (即時対応を優先したため)。`storage/logs/laravel.log` に例外ログなし (ミドルウェアで正常応答した
  ように見えるため exception は記録されない設計)。
- 推定原因: 未確定。仮説: (a) ブラウザナビゲーション (`page.goto`) と同一タブ内の `fetch` が
  ほぼ同時に発火した際の**セッションストアの競合状態** (同一セッションへの同時書き込みで
  一方の認証状態が失われる) が実際に発生し、その隙間で `not-pending-deletion` の判定より前に
  別の状態変化が起きた。(b) 単なるテスト手法上のアーティファクト
  (ページ遷移中に発行した fetch が本来届くべきでない経路/セッションに届いた) で、
  アプリのバグではない可能性が高い。**5分の調査では特定できず、再現条件を安定させられなかった
  ため「未調査」のまま「要確認」に留める**。
- 改善アクション候補: (a) の可能性を潰すため、`session()->put/save` を伴うミドルウェアチェーンで
  同一セッションへの同時リクエストに対する挙動 (ロック要否) をユニットテストで確認することを推奨。
  少なくとも `settings.account.destroy` は**必ず `not-pending-deletion` を通過してから
  `recent-auth`/`auth` を評価する**という優先順位がテストで機械固定されているか
  (`tests/Architecture/AccountDeletionFreezeRouteGateTest.php` 等) の確認を推奨。
- 関連既知情報: `app/Enums/Account/AccountDeletionFreezeAllowance.php` の doc comment が
  「`settings.account.destroy` は意図的に allowlist から除外」と明記。前回 run
  (20260811-003230) の shard-4 report が「即時削除は 409 ブロック確認 (予約中の迂回不可を確認、正常)」
  と記載しており、通常経路では正常に機能していたことと矛盾しない (今回もクリーンな再試行では
  同じく正常だった)。

---

## インベントリ修正提案
- なし。screens.md / operations.md の S6 該当行はすべて実装と一致していた。ドリフトは検出しなかった。

## Critical/High TODO 候補 (要約)
- 今回 Critical/High の確定 finding は 0 件。F-4-Q1 (要確認) のみ — 再現性が不安定で severity を
  付けられないため TODO 候補としては起票しない。ただし「実アカウントが 1 件、想定と異なる経緯で
  消失した」という事実は次回 run 前に確認しておく価値がある (可能なら
  `tests/Architecture/AccountDeletionFreezeRouteGateTest.php` 等の既存アーキテクチャテストに
  「ナビゲーションと同時発火する fetch」のような同時実行系ケースを足せないか、実装側で検討を推奨)。

## 要確認 (仕様確認の質問リスト)
- F-4-Q1: member-personal アカウント消失の原因究明 (セッション周りの並行リクエスト耐性 or
  テストハーネス起因のアーティファクトか、開発側でログ・コードから特定してほしい)。

## 総括 (走行完了)
S6 の screen/operation はほぼ全て実走行した。**主目的だった F-4-01 (2FA必須組織×退会取消の詰み) の
回帰確認は「修正済みで回帰なし」と実機で確認できた** (取消 DELETE が allowlist に入り、303 で
/settings に直接戻り toast で結果が明示される)。**CodeSnippet のコピー失敗時挙動**
(Clipboard API と execCommand の両方失敗時に選択状態 + 「自動で消えない」案内が出る)も実機確認し
仕様通りだった。2FA enable/confirm/regenerate/disable、last-owner ブロッカーとの共存、
settings.password.store の fail-closed、passkey.destroy の IDOR/型崩し (0件状態) 全 404、
メールアドレス変更の旧アドレス通知など、S6 の主要な安全境界はいずれも設計通りに機能していることを
実機で確認した。唯一、テスト中に member-personal アカウントが想定外の経緯で実際に削除される事象が
発生し、2 回のクリーンな追試では再現しなかったため severity を付けずに「要確認 F-4-Q1」として記録した
(実データ消失を伴う事象のため、再現性が低くとも報告する判断)。
環境制約による skip は passkey 登録系 (headless に authenticator なし)、SSO-only ユーザー経路
(実 IdP 遷移が禁止事項に抵触)、bfcache 経路B (Playwright が `--disable-back-forward-cache` で起動) の
3 件で、いずれも前回 run と同条件・理由付きで記録した。
