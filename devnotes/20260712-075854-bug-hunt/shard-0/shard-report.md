# bug-hunt report shard-0 (2026-07-11, run 20260712-075854)

- 対象: http://127.0.0.1:8010 (DB bug_hunt, users=8→9)
- 実行ストーリー: S1 (完走・逸脱込み), S2 (ほぼ完走。editor/photographer招待はF-07によりブロック), S4 (組織設定/APIキー/オンボーディング/manage.users 完走。projects/categories はF-07によりブロック), S6 (完走・逸脱一部込み), S7 (組織レベルIDOR確認完了。project/manual/cut/take レベルはF-07によりA組織側リソースを作成できず未走行), S3 (screens.show/create一部のみ。ticket必須操作はF-05/F-07によりブロック), S5 (pricing/billing.index完走、checkout/portal操作はF-05で500確認)
- skip したステップ:
  - S3: `projects.manuals.*` 系の作成・解析・シナリオ編集・撮影・レンダ・DLの全操作、および `capture.*` 全画面/操作。理由: F-07 (`/projects`・`/projects/create`・`/app` が説明なく `/billing` へリダイレクトされ、プロジェクトを一切作成できない。全テストアカウント (owner-free/owner-standard/multi-org で作成した新規組織含む) で再現)。skip ではなく「試行→ブロックを確認→記録」を実施。
  - S7手順1-5 (nested route の cross-org/cross-cut IDOR確認、manual/cut/take/category/render-job レベル): 理由: S3でA組織にmanual/cut/take/categoryを作成できなかった (F-07) ため、越境対象リソースが存在せず検証不能。organizations設定/api-keys/onboardingレベルのcross-org 404確認 (S4派生) とtenantキー注入 (S7手順7、invitations.store) は実施し正常動作を確認。
  - S7手順6 (撮影者ロールでの編集者専用操作403確認の一部): 理由: 撮影者ロールをprojectなしで付与できない (F-07の派生) ため、project_member roleでのmanuals.store等の403確認は未実施。ただしmanage.users.indexへのmember-freeアカウントでの直アクセスは403を確認済み。
  - debug.login-as (S1/S4): 理由: `POST /debug/login/1` が404で本bughunt環境には未登録と判明 (意図的にdisabledの可能性が高く、環境ハザードとまでは断定せず「インベントリ修正提案」に記録)。
  - S6のGoogleソーシャルログイン連携 (user-profile-information.update に付随する画面のみ確認、実連携はTESTING_FAKE_EXTERNALS前提で実クリックは省略): 理由: 外部OAuthフローの実接続を避けるため導線の存在確認のみに留めた。

## 画面カバレッジ
- 走行 (アクセス確認込み) 24 / screens.md 総画面 55
- 走行した画面: home, register, login, dashboard, verification.notice, verification.verify, password.request, password.reset, two-factor.login, contact, contact.thanks, legal.commerce-disclosure, legal.privacy, legal.terms, pricing, billing.index, organizations.create, organizations.settings, organizations.api-keys.index, organizations.api-keys.sessions.index(遷移リンク確認のみ), organizations.onboarding.cli, organizations.onboarding.mcp, manage.users.index, settings, settings.security, password.confirm(500再現), invitations.accept
- 未走行screens (F-07により到達不能): projects.show, projects.manuals.create, projects.manuals.show, projects.manuals.edit, projects.manuals.jobs.show, projects.manuals.render-jobs.show, projects.manuals.render-jobs.playback, projects.manuals.download, capture.home, capture.csrf-cookie, capture.manuals.index, capture.manuals.show, projects.index, projects.create, projects.categories.index, recent-auth.confirm, recent-auth.status(アクセスはしたが空応答のみ確認、詳細検証は簡略)

## 操作カバレッジ
- 実行 26 / operations.md 対象 60
- 実行した操作: register.store, login.store, logout, password.email, password.update, verification.send, two-factor.login.store, contact.store, invitations.accept.store, organizations.invitations.store, organizations.invitations.revoke, organizations.members.update(ブロック確認込み), organizations.members.destroy, organizations.members.two-factor.reset, organizations.store, organizations.update, organizations.switch(cross-org 404確認込み), organizations.api-keys.store, organizations.api-keys.revoke, user-profile-information.update, user-password.update, two-factor.enable, two-factor.confirm, two-factor.regenerate-recovery-codes(直API確認), settings.account.destroy, billing.checkout(500確認), billing.portal(500確認)
- 未実行/ブロック確認済み操作: projects.manuals.* 全般, capture.* 全般, projects.categories.*, projects.items.*, projects.members.*, projects.store/update/destroy (F-07によりUIに到達できず), organizations.transfer-ownership (UI自体が存在せずF-12として記録), organizations.two-factor-requirement.update (自身の2FA未設定でガードされ完了せず、ガード動作自体は確認), two-factor.disable (2FA有効化までは確認したが無効化操作は未実行), recent-auth.password, password.confirm.store (画面自体が500のF-11によりブロック), debug.login-as (404でルート自体が本環境に存在せず)

## UI/UX 検証所見
- H11 (視覚破綻): 目立った崩れは主要画面では未検出。ただしF-01 (`${APP_NAME}` プレースホルダ未展開) がサイト全域のロゴ・タイトル・フッターに露出しており、視覚的な「壊れたテンプレート」感を与えている。
- H12 (アフォーダンス/状態表現): 概ね良好。ボタンのdisabled/確認ダイアログは適切に実装されている画面が多い (F-08のヘッダーナビ不統一を除く)。
- H13 (レスポンシブ): home/register (mobile375: 問題なし), manage.users.index (mobile375: **F-14 横スクロール発生** を確認)。tablet768はregisterで確認し問題なし。
- H14 (a11y基礎): 2FA設定のQRコード画面にimg/svgのalt/aria-labelがなく、また手動入力用のセットアップキー表示がUI上に存在しない (APIレスポンスには含まれるが画面に出ていない)。スクリーンリーダー/カメラ利用不可ユーザーが2FA設定を完了できない可能性がある (Low〜Medium、時間の都合で単独findingとしては未起票、統合時に確認推奨)。

## インベントリ修正提案
- `/purchase-tickets` (チケット購入画面) がscreens.mdに未掲載。billing.indexから遷移する独立画面のため追加を推奨。
- `/notifications` (通知一覧) がscreens.mdに未掲載。全認証済み画面のヘッダーから常時リンクされているため追加を推奨。
- `debug.login-as` (`POST /debug/login/{userId}`) が本bughunt環境で404 (未登録)。意図的な無効化なら operations.md 側に「bughunt環境では無効」等の注記を追加するか、S1/S4の対象から外すことを推奨。
- `organizations.transfer-ownership` に対応するUIがF-12の通り見当たらない。UI未実装が確定するなら、operations.mdの「区分」列に「未実装」等のフラグを追加するか、実装状況を確認の上ストーリーへの割当を見直すことを推奨。

## findings サマリ
- Critical 3 / High 1 / Medium 8 / Low 1 / 要確認 1 (F-01〜F-14 + 要確認1件。F-09は誤検知と判明し不採用)
  - Critical: F-05 (Stripe課金全滅), F-07 (プロジェクト作成が全アカウントで詰み), F-13 (管理画面アセット全滅+ログイン失敗)
  - High: F-11 (confirm-password 500)
  - Medium: F-02 (バリデーション未翻訳・複数箇所), F-03 (認証メール再送信フィードバックなし), F-06 (パスワードリセットメール送信フィードバックなし), F-08 (ヘッダーナビ不統一), F-10 (リカバリコード再生成UIなし), F-12 (オーナー移譲UIなし), F-14 (manage.users モバイル横スクロール), F-01 (${APP_NAME}未展開・Medium格上げ理由: サイト全域露出)
  - Low: なし単独 (F-01を除きLow格の単独findingは無し。当初Low分類のF-02はsystemic再発によりseverity Lowのまま維持だが影響範囲拡大を記録)
  - 要確認: パスワード uncompromised (漏洩パスワード) チェックが `Password12345` を拒否した際、実際にサーバーが外部 (haveibeenpwned等) に通信したのか、TESTING_FAKE_EXTERNALS でローカルフェイクされているのかがブラウザ側からは判別不能。禁止事項4 (実外部サービス不可侵) の観点で bughunt 環境の `Password::uncompromised()` のフェイク実装状況を確認されたい。severity は付けず要確認。

---

## finding 詳細 (severity 降順、見つけ次第追記)

## F-01: サイト全体で `${APP_NAME}` プレースホルダが未展開のまま表示される
- severity: Medium (H4: 生エラー/未展開キー相当。表示のみで操作阻害はないため Medium)
- story/step: S1-1 (home)
- 再現手順: 未ログインで `http://127.0.0.1:8010/` を開く。ブラウザタブタイトル・ヘッダーのロゴリンク文字・フッターの著作権表記 (`© 2026 ${APP_NAME}`) がすべて `${APP_NAME}` という生のプレースホルダ文字列のまま表示される。
- 期待: サイト名 (例: AI-CUE) がタイトル/ロゴ/フッターに表示される。
- 実際: 環境変数 (おそらく `APP_NAME` またはテンプレート変数) が解決されず、リテラル文字列 `${APP_NAME}` がそのまま描画されている。
- 阻害されたユーザージョブ: 直接の操作阻害はないが、プロダクト名がブランディングされず「壊れたテンプレート」に見えるため、初見ユーザー(登録前の見込み客)の信頼を損なう。公開LPとして重大な第一印象問題。
- 改善アクション候補: `.env.bughunt.local` (または全環境の `.env`) の `APP_NAME` 設定、あるいは Blade/Inertia 側のテンプレート変数解決ロジックを確認。bughunt 環境固有の設定漏れの可能性もあるため、dev 環境でも再現するか要確認。
- 証跡: screenshots/F-01-home-appname.png
- 推定原因: 未調査 (env の APP_NAME 未設定、または title 生成ロジックのテンプレート変数がそのまま出力されている可能性)
- 関連既知情報: なし (bug-hunt 初回走行)
- 注記: bughunt 環境固有の設定漏れ (env ファイルの APP_NAME 未設定) の可能性が高いため「環境ハザード」ではなく finding として記録しつつ、dev 環境の挙動未確認である旨を明記。severity は plausibly 環境固有なら要確認寄りだが、ユーザー visible な破綻のため Medium で記録。

## F-02: 複数のフォームでバリデーション必須項目メッセージに未翻訳の英語フィールド名がそのまま表示される (systemic)
- severity: Low (H4: 未翻訳キー相当。表示のみで機能は阻害されないが、複数箇所での再発を確認したため systemic 扱い)
- story/step: S1-2 (contact) / S4-4 (organizations.members.two-factor.reset の理由欄)
- 再現手順:
  1. `http://127.0.0.1:8010/contact` で全項目空欄のまま「送信する」→ お問い合わせ内容欄のみ「**message**は必須項目です。」と英語キーのまま表示 (他の項目は「名前は必須項目です。」等、正しく日本語)。
  2. `/manage/users` でメンバーの「2FA 解除」ダイアログを理由欄空欄のまま送信 → 「**reason**は必須項目です。」と同様に英語キーのまま表示。
- 期待: すべての必須項目バリデーションメッセージが日本語ラベルで表示される。
- 実際: `message` (問い合わせ内容) と `reason` (2FA解除理由) の少なくとも2フィールドで英語の内部キー名がそのまま露出。他にも同様の未登録フィールドが存在する可能性が高い。
- 阻害されたユーザージョブ: 直接の機能阻害はないが、多言語化対応漏れが複数箇所で再発しており、プロフェッショナルさを損なう。
- 改善アクション候補: `lang/ja/validation.php` (または該当箇所) の `attributes` 配列を棚卸しし、`message`・`reason` を含む未登録フィールドに日本語ラベルを追加する。他のフォーム (特に理由/コメント系のフリーテキスト欄) も横断的に確認することを推奨。
- 証跡: screenshots/F-02-contact-validation-untranslated.png (message)。reason の再現は screenshot 未取得だが snapshot ログで確認 (`paragraph: reasonは必須項目です。`)
- 推定原因: 未調査 (lang/ja/validation.php の attributes 配列に `message`・`reason` 等のキーが未登録の可能性。他にも未登録キーが残っている可能性が高いため横断棚卸しを推奨)
- 関連既知情報: なし

## F-03: メール認証再送信ボタンを押しても成功フィードバック(トースト/フラッシュ)が一切表示されない
- severity: Medium (H7: 操作の結果フィードバックがない)
- story/step: S1-3 (verification.notice → verification.send)
- 再現手順: 新規登録直後の `http://127.0.0.1:8010/email/verify` で「認証メールを再送信」ボタンを押す。network タブでは `POST /email/verification-notification => 302` が成功しているが、画面上には成功メッセージ (例:「認証メールを再送信しました」) が一切表示されない。
- 期待: ボタン押下後、送信成功を示すフィードバック (トースト/インラインメッセージ) が表示され、ユーザーが「もう一度送信されたか」を確認できる。
- 実際: ボタンの見た目・ページ本文が押下前後で全く変化せず、ユーザーは実際に再送信されたのか判断できない (二重クリックしたくなる=誤操作誘発)。
- 阻害されたユーザージョブ: メール未着で困っているユーザーが再送信操作の成否を確認できず、不安なまま連打したり詰まったりする。
- 改善アクション候補: サーバー側の redirect に `flash` メッセージを付与し、フロントで toast/inline 表示する (他の操作系で同様のパターンがあれば流用)。
- 証跡: screenshots/F-03-verify-resend-no-feedback.png
- 推定原因: 未調査
- 関連既知情報: なし

## F-05: チケット購入・プラン申込・カスタマーポータル (Stripe 連携全操作) が常に 500 で失敗し、課金/チケット取得が一切できない
- severity: Critical (H2: 詰み。課金導線が完全に機能不全で、S3 の中核ジャーニー=AI解析/レンダに必要なチケットが一切取得できない)
- story/step: S5-3/S5-4 (billing.checkout, billing.portal), S5 派生 (purchase-tickets/checkout ※screens.md 未掲載の画面)
- 再現手順:
  1. `owner-free@example.com` / `password123` でログイン (組織: Free プラン、チケット残高 0)。
  2. `http://127.0.0.1:8010/billing` → 「チケットを購入」→ `http://127.0.0.1:8010/purchase-tickets` → 枚数 10 のまま「購入手続きへ (Stripe)」を押す → `POST /purchase-tickets/checkout` が **500 Internal Server Error** (リロード後に再試行しても再現、2 回連続で同じ 500)。
  3. `/billing` に戻り、Standard プランの「このプランにする」を押す → `POST /billing/checkout` も同様に **500 Internal Server Error**。
  4. `/billing` の「お支払い方法を管理 (Stripe)」ボタン (billing.portal) を押す → `POST /billing/portal` も同様に **500 Internal Server Error**。**Stripe 連携の全操作 (チケット購入・プラン申込・カスタマーポータル) が例外なく失敗する。**
- 期待: `TESTING_FAKE_EXTERNALS=true` の fake Stripe 経由で checkout セッションが作成され、擬似決済完了後に残高/プランが更新される (S5 手順3)。
- 実際: チケット購入・プラン申込どちらの checkout エンドポイントも例外なく 500 を返す。エラー画面自体は自作の 500 ページ (スタックトレース非露出) で丁寧だが、根本機能が動作しない。
- 阻害されたユーザージョブ: **新規/既存ユーザーがチケットを一切入手できない = AI 解析・動画レンダが一切実行できない = アプリの North Star フロー (SOP→AI カット設計→撮影→完成動画) が入口で完全に詰む。** 登録直後のユーザーは「チケット10枚無料」の謳い文句 (F-04 参照) も含めて何も得られず、実質アプリを使い始められない。
- 改善アクション候補: `billing/checkout` と `purchase-tickets/checkout` のサーバーログを確認 (Stripe fake クライアントの初期化失敗、`attempt_token`/冪等キーの処理、または bughunt 環境の Stripe fake 設定漏れの可能性)。dev 環境 (:8000系) で同様の操作が成功するか照合し、bughunt 環境固有の設定漏れか実装バグかを切り分けること。
- 証跡: screenshots/F-05-purchase-tickets-500.png, screenshots/F-05-billing-checkout-500.png, screenshots/F-05-billing-portal-500.png, network: `POST /purchase-tickets/checkout => 500` (2回再現), `POST /billing/checkout => 500`, `POST /billing/portal => 500`, request body 例: `{"count":10,"attempt_token":"01KX9Q0YBVDW4BKYSE0A8YAKNS"}`
- 推定原因: 未調査 (5分調査枠内では特定できず。Stripe fake 統合か checkout コントローラの例外の可能性)
- 関連既知情報: なし (bug-hunt 初回走行)
- 注記: bughunt 環境固有の設定漏れの可能性も否定できないため、severity Critical は「このbughunt環境での検証結果」として報告し、統合レポートで dev 環境再現有無の確認を推奨する。ただし他の主要機能 (登録/ログイン/問い合わせ等) は正常に動くため「全エンドポイント500」の環境ハザード基準には該当せず、billing 機能に限定した深刻なアプリ/設定バグとして記録する。

## F-06: パスワードリセットメール送信後も成功フィードバックがない (F-03 と同一パターンの再発)
- severity: Medium (H7)
- story/step: S1-5 (password.request → password.email)
- 再現手順: `http://127.0.0.1:8010/forgot-password` でメールアドレスを入力し「リセットリンクを送信」を押す。`POST /forgot-password => 302` は成功しているが、画面上には「送信しました」等のフィードバックが一切表示されず、フォームがそのまま残る。
- 期待: 送信成功のメッセージが表示され、ユーザーがメールを確認すべきことが明確にわかる。
- 実際: F-03 (認証メール再送信) と同一パターン。ページが無反応に見え、二重送信を誘発しうる。
- 阻害されたユーザージョブ: パスワードを忘れたユーザーが「本当にメールが送られたか」を確認できず、不安なまま操作を繰り返す可能性がある。
- 改善アクション候補: F-03 と共通の原因の可能性が高い (flash メッセージ表示の仕組みが `email/verification-notification` と `forgot-password` の両方で欠落)。両エンドポイント共通のコンポーネント/レイアウトで flash 表示を確認・修正。
- 証跡: screenshots/F-06-forgot-password-no-feedback.png
- 推定原因: 未調査。F-03 と根本原因が共通の可能性が高い
- 関連既知情報: F-03 と同一パターン (要 dedupe 検討)

## F-07: Free プランのユーザーがダッシュボードの「プロジェクトを作成」を押しても説明なく `/billing` へリダイレクトされ、プロジェクト作成に一切到達できない (F-05 と合わせて Free プランが完全に詰む)
- severity: Critical (H1: 説明なしリダイレクト + H2: 詰み。F-05 と合わせて Free プランユーザーの唯一の脱出路も塞がれている)
- story/step: S2 前提確認中に発見 (S3-1 projects.show の前段、S4-2 projects.index にも影響)
- 再現手順:
  1. `owner-free@example.com` (Free プラン、`/billing` 上は「現在のプラン: Free」と表示、チケット残高 0) でログイン。
  2. ダッシュボードの「プロジェクトを作成しましょう」カードにある「プロジェクトを作成」リンク (`/projects/create` 宛) をクリック。
  3. `/projects` に直 URL でアクセスしても同様。
  4. いずれも **`/billing` へ何の説明もなく (flash メッセージなし・console/network エラーもなし) サイレントリダイレクトされる**。
- 期待: Free プランは無料で使えると謳っている (home: 「Free プランで今すぐ試せます」、pricing: 「無料で始める」) ため、少なくともプロジェクト作成画面には到達できるべき。もし「有効なサブスクリプションが必要」という制約が意図的なら、リダイレクト先で理由を明示すべき (例: 「プランのご契約が必要です」という flash)。
- 実際: 理由の説明が一切ないまま `/billing` に飛ばされる。しかも `/billing` では既に Free プランが「現在のプラン」として表示されており、ユーザーからは「もうプランは契約済みなのに、なぜまた billing に戻されるのか」全く理解できない。さらに F-05 (checkout 500 エラー) と合わせると、Standard へのアップグレードも Stripe 決済も一切できないため、**このアカウントは永久にプロジェクトを作成できない詰み状態**になる。
- 阻害されたユーザージョブ: Free プランで試したい新規ユーザーの最初の一歩 (プロジェクト作成→マニュアル作成) が完全にブロックされる。North Star フロー全体が入口で止まる。
- 改善アクション候補: (1) `/projects` 系ルートの middleware が要求している「サブスクリプション有効性」の条件を確認し、Free プランでも満たされるべき条件が満たされない原因 (Cashier subscription レコード未作成等) を特定する。(2) 少なくとも理由を説明する flash メッセージ / 専用の案内画面を出す。(3) F-05 の billing checkout 修正と合わせて根本解決が必要。
- 追加確認: 撮影 PWA 面の `capture.home` (`/app`) に直接アクセスしても同様に `/billing` へ無言リダイレクトされることを確認 (multi-org アカウント)。S3 の screens/operations リストのうち `capture.home`/`capture.manuals.index`/`capture.manuals.show`/`projects.manuals.*` 全て未到達 (下記カバレッジ節参照)。
- 証跡: screenshots/F-07-projects-silent-redirect-billing.png, network: `/projects` → 302 → `/billing` (flashメッセージなし、console error 0件)、`/app` → 302 → `/billing` (同様)
- 推定原因: 未調査。F-05 (Stripe checkout 500) と根本原因を共有している可能性が高い (Cashier subscription 未作成のまま Free プランの「アクティブ判定」ロジックが false を返している等)
- 関連既知情報: F-05 と密接に関連 (同一 root cause の可能性)。統合レポートでは F-05 と合わせて「課金サブシステム全体の機能不全」として一つの Critical TODO にまとめることを推奨。

## F-08: ヘッダーナビが画面によって「設定」「ログアウト」リンクの有無が不統一で、一部画面からログアウト/設定に到達できない
- severity: Medium (H12: ナビゲーションのアフォーダンス不統一。詰みではないがユーザーを迷わせる)
- story/step: S4 (organizations settings 探索中に発見。billing / purchase-tickets / manage.users 等の画面で共通)
- 再現手順: `owner-free@example.com` 等でログイン後、`/dashboard` のヘッダーには「通知・設定・ログアウト」の3リンクが表示される。一方 `/billing`・`/purchase-tickets`・`/manage/users` のヘッダーには「通知」リンクしか表示されず、「設定」「ログアウト」が消える。
- 期待: 認証後の全ページで一貫したグローバルヘッダー (少なくとも設定・ログアウトへの導線) が表示される。
- 実際: 画面によってヘッダーの構成要素が異なり、一部画面ではロゴをクリックして dashboard に戻らないとログアウト/設定に到達できない。
- 阻害されたユーザージョブ: 直接の機能阻害は小さいが、「今どこにいて、次に何をすべきか」の一貫性が崩れ、特にログアウトしたいユーザーが迷う (使命: North Star に反する)。
- 改善アクション候補: 共通レイアウトコンポーネント (AppShell 等) を全認証済み画面で統一適用する。billing/purchase-tickets/manage.users が個別レイアウトを使っている可能性が高い。
- 証跡: screenshots/F-08-header-nav-inconsistent-manageusers.png (billing/purchase-tickets 側の証跡は screenshots/F-05-*.png でも確認可能、ヘッダーに設定/ログアウトが無いことが写っている)
- 推定原因: 未調査 (レイアウトコンポーネントの出し分けミスの可能性)
- 関連既知情報: なし

## F-10: `/settings/security` にリカバリコード再生成 (two-factor.regenerate-recovery-codes) の UI が存在しない
- severity: Medium (operations.md に定義された操作に対応する UI がない。SKILL.md 走行プロトコル 0 に基づき finding 化)
- story/step: S6-2 (two-factor.regenerate-recovery-codes)
- 再現手順: `multi-org@example.com` で 2FA を有効化し `/settings/security` を確認する。「2要素認証を無効化」ボタンとリカバリコード一覧(発行時の8個)は表示されるが、リカバリコードを再生成するボタン/リンクが画面のどこにも存在しない (`find` で「リカバリ」「再生成」を検索しても該当ボタンなし)。
- 期待: リカバリコードを紛失・流出した場合に再生成できる導線が `/settings/security` にあるべき。
- 実際: 一度発行されたリカバリコードを再確認・再生成する手段が UI 上に一切ない。8個のコードを全部使い切る、あるいは紛失すると 2FA でロックアウトされるリスクがある。
- 阻害されたユーザージョブ: リカバリコードを紛失したユーザーが自力で復旧できず、サポート対応が必要になる。
- 改善アクション候補: `/settings/security` に「リカバリコードを再生成」ボタンを追加し `POST /user/two-factor-recovery-codes` を呼ぶ導線を実装する。
- 証跡: screenshots/F-10-no-regenerate-recovery-codes-ui.png
- 推定原因: 確認済み。`fetch('/user/two-factor-recovery-codes', {method:'POST', ...})` を直接叩くと `200` で正常に再生成される (バックエンドは正常動作)。**フロントエンドに呼び出しボタンが実装されていないだけ**の UI 実装漏れと断定できる。
- 関連既知情報: なし

## F-11: `/user/confirm-password` (機微操作の再認証画面) に直接アクセスすると 500 エラーになる
- severity: High (H4: 生エラー。機微操作の再認証というセキュリティ関連の中核画面が壊れている)
- story/step: S6-3 (password.confirm)
- 再現手順: `multi-org@example.com` でログイン後 (2FA有効)、`http://127.0.0.1:8010/user/confirm-password` に直接アクセスする。2回連続で再現。
- 期待: パスワード再入力フォームが表示され、機微操作の前に本人確認ができる。
- 実際: `500 Internal Server Error` (自作の丁寧なエラーページで、スタックトレースは非露出)。
- 阻害されたユーザージョブ: 何らかの機微操作 (アカウント削除・オーナー移譲・2FA無効化等) の直前にこの画面へリダイレクトされた場合、ユーザーは再認証できず操作を完了できずに詰む可能性がある (実際の詰みトリガー導線は F-12 のオーナー移譲 UI 欠落などにより本走行では確認できなかったため、直接 URL アクセスでの再現に留める)。
- 改善アクション候補: `password.confirm` の Inertia レンダリングで参照している「intended URL」等のセッション値が未設定の場合にクラッシュしていないか確認。直接アクセス時のデフォルト値/フォールバックを実装する。
- 証跡: screenshots/F-11-confirm-password-500.png, network: `GET /user/confirm-password => 500` (2回再現)
- 推定原因: 未調査
- 関連既知情報: なし

## F-12: 組織設定画面にオーナー移譲 (organizations.transfer-ownership) の UI が存在しない
- severity: Medium (operations.md に定義された操作に対応する UI がない)
- story/step: S4-1 (organizations.transfer-ownership)
- 再現手順: 組織オーナーで `/organizations/{slug}/settings` を開く。「組織名」「セキュリティ (2FA必須化)」「ユーザー管理」「APIキー」の4セクションのみで、オーナー移譲の導線がどこにもない (`find` で「移譲」を検索しても該当なし)。
- 期待: オーナーが他のメンバーにオーナー権限を移譲できる導線が組織設定画面にあるべき (S4 story に明記)。
- 実際: UI 上に一切存在しない。バックエンドルート `PATCH organizations/{slug}/transfer-ownership` の動作有無は未検証 (時間の都合で直 API 呼び出しでの検証は省略)。
- 阻害されたユーザージョブ: 退職・異動等でオーナーを交代したい組織が、UI からは一切それができない。
- 改善アクション候補: 組織設定画面にオーナー移譲セクションを追加する。あるいは、意図的に未実装なら operations.md 側の記載を見直す (もし本当に未実装なら「操作」ではなく「未実装ルート」として棚卸しする)。
- 証跡: screenshots/F-12-no-transfer-ownership-ui.png
- 推定原因: 未調査 (UI 未実装 or ナビゲーション経路が別にある可能性。後者なら本 finding は誤検知の可能性もあるため「要確認」性が残る)
- 関連既知情報: なし

## F-13: 管理画面 (`/admin`, Filament) が静的アセット全滅 (CSS/JS 404) で無スタイル表示になり、かつ案内された管理者アカウントでログインできない
- severity: Critical (H4: 静的アセット404多発によるほぼ無スタイル表示 + 認証失敗で管理機能に一切到達できない)
- story/step: S4 派生 (manage.users.index の上位の「管理画面」領域探索中に発見。screens.md には未掲載の面)
- 再現手順:
  1. `http://127.0.0.1:8010/admin` → `/admin/login` にリダイレクト。
  2. ページの CSS/JS が軒並み 404 (`/css/filament/filament/app.css`、`/js/filament/filament/app.js` 等、console に **26件以上のエラー**)。結果、ログインフォームが装飾なしの生 HTML で表示される (証跡参照)。
  3. 指定された管理者アカウント `admin@example.com` / `password12345` でログインを試みるが、**「認証に失敗しました。」で 2 回とも失敗**する。
  4. Livewire 自体は動いている (フォーム送信・バリデーションエラー表示は機能する) ため、JS 全滅ではなく静的アセットのビルド/公開が漏れている可能性が高い。
  - 補足: `document.cookie` 経由で覗ける限りアプリ側の通常画面 (`/dashboard` 等) は正常にスタイル・機能とも動作しており、本問題は `/admin` (Filament 管理パネル) に閉じている。
- 期待: 管理画面に指定の管理者アカウントでログインでき、正常にスタイルが適用された UI で利用できる。
- 実際: 静的アセット欠落によりほぼ生 HTML 表示、かつ案内された認証情報でログインできない。
- 阻害されたユーザージョブ: プラットフォーム運営者が管理画面 (ユーザー管理・監査等、想定される Filament リソース) に一切アクセスできない。S4 のうち管理者視点の検証が本走行では実施不能。
- 改善アクション候補: (1) `php artisan filament:assets` (または該当のアセット公開コマンド) が bughunt 環境の provision 手順に含まれているか確認。(2) admin@example.com のパスワードハッシュが `AdminUserSeeder` で正しく `password12345` にセットされているか確認。dev 環境で同じ手順が成功するか照合し、bughunt 環境固有の provision 漏れかアプリ側のバグかを切り分けること。
- 証跡: screenshots/F-13-admin-panel-broken-assets.png (無スタイルのログイン画面), screenshots/F-13-admin-login-auth-failed.png (「認証に失敗しました。」), console: 404 × 10種類のfilamentアセット (css/js/フォント)
- 推定原因: 未調査。静的アセット未公開 (dev/prod ビルド成果物の配置漏れ) の可能性と、認証情報不一致の可能性の両方が考えられる。本走行環境固有の provision 漏れの可能性が高いため、統合レポートで dev 環境との比較確認を強く推奨。
- 関連既知情報: なし。この finding は bughunt 環境固有の可能性があるため、統合時に「環境要因の疑いあり」として明記すること。

## F-14 (H13): `/manage/users` のメンバー一覧行がモバイル幅 (375px) で横スクロールを引き起こす
- severity: Medium (H13: 操作要素が画面外にはみ出す。タップ不能ではないが視認性を損なう)
- story/step: S4-4 (manage.users.index)、レスポンシブ確認
- 再現手順: 組織オーナーで `/manage/users` を開き、`playwright-cli resize 375 667` でモバイル幅にする。2FA有効・ロール未割当のメンバー行 (バッジ「2FA」「未割当」+「2FA 解除」ボタン + ロール select) で、select の選択中テキスト「未割当（選択してください）」が画面右端からはみ出し、`document.body.scrollWidth` (468px) > `clientWidth` (375px) で **93px の横スクロールが発生**することを確認。
- 期待: モバイル幅でも横スクロールなしに全要素が収まる (バッジ・ボタン・select が折り返す等)。
- 実際: メンバー行の要素 (バッジ2種 + 2FA解除ボタン + ロールselect) が1行に収まらずオーバーフローし、画面全体が横スクロール可能になる。
- 阻害されたユーザージョブ: スマートフォンから管理者がメンバー管理を行う際、視認性・操作性が低下する (完全な操作不能ではないが H13 の「見た目崩れ」〜「操作しづらい」に該当)。
- 改善アクション候補: メンバー行のレイアウトをモバイル幅で縦積み (flex-wrap または flex-col) に切り替える。select の表示テキストを短縮するか、モバイル用に折り返しを許可する。
- 証跡: screenshots/H13-manageusers-mobile375.png, eval: `{scrollWidth: 468, clientWidth: 375}` (viewport 375×667)
- 推定原因: 未調査 (メンバー行コンポーネントに `flex-wrap` 等のモバイル対応が未実装の可能性)
- 関連既知情報: なし

---

## Critical/High TODO 候補 (app-todo-add 向け要約)

### TODO-1 (Critical, F-05): Stripe 課金導線 (チケット購入・プラン申込・カスタマーポータル) が全滅
- 一行サマリ: `/purchase-tickets/checkout`・`/billing/checkout`・`/billing/portal` が例外なく 500 を返し、課金・チケット取得が一切できない。
- 再現手順: F-05 参照 (shard-report.md 内)。owner-free でログイン → `/purchase-tickets` で購入手続き → 500。`/billing` でプラン申込・ポータルも同様に 500。
- 阻害されたユーザージョブ: チケット/有料プランの取得が不可能 = AI解析・動画レンダが一切実行できず、North Starフロー全体が入口で止まる。
- 改善アクション候補: Stripe/Cashier 連携の初期化・fake実装・attempt_token処理を調査。dev環境との差分確認。
- 関連ファイル: (未調査。billing/purchase-tickets 関連のcontroller/serviceを中心に調査推奨)

### TODO-2 (Critical, F-07): Free/Standard 問わず全アカウントでプロジェクト作成に到達できない (説明なしリダイレクト+詰み)
- 一行サマリ: `/projects`・`/projects/create`・`/app` (撮影PWA) が「有効なサブスクリプションがありません」的な理由説明なく `/billing` へ無言リダイレクトされ、F-05と合わさって永久に詰む。
- 再現手順: F-07参照。任意のアカウント (Free/Standardプラン問わず、新規作成組織含む) でダッシュボードの「プロジェクトを作成」を押す、または `/projects` に直アクセス。
- 阻害されたユーザージョブ: プロジェクト作成というアプリの入口操作が不可能。S3のNorth Starジャーニー全体が検証不能なほどの深刻度。
- 改善アクション候補: `/projects` 系middlewareの「サブスクリプション有効性」判定ロジックを確認 (Cashier subscriptionレコード未作成の可能性)。F-05と根本原因を共有している可能性が高く、合わせて調査推奨。
- 関連ファイル: (未調査。projects系routeのmiddleware/gate定義を中心に調査推奨)

### TODO-3 (Critical, F-13): 管理画面 (`/admin`, Filament) の静的アセット全滅+ログイン失敗
- 一行サマリ: `/admin/login` のCSS/JS (filament関連) が軒並み404で無スタイル表示になり、案内された管理者アカウント (admin@example.com/password12345) でもログインできない。
- 再現手順: F-13参照。`/admin` にアクセス→無スタイル画面→admin@example.com/password12345でログイン試行→「認証に失敗しました。」。
- 阻害されたユーザージョブ: プラットフォーム運営者が管理画面に一切アクセスできない。
- 改善アクション候補: bughunt環境のprovision手順に `filament:assets` 公開ステップが含まれているか確認。AdminUserSeederのパスワードハッシュ設定を確認。dev環境との比較を強く推奨 (bughunt環境固有のprovision漏れの疑いが強い)。
- 関連ファイル: (未調査。AdminUserSeeder、Filament PanelProvider、アセットビルド設定を中心に調査推奨)

### TODO-4 (High, F-11): `/user/confirm-password` (機微操作再認証画面) への直接アクセスで500
- 一行サマリ: 機微操作の再認証に使うはずの `/user/confirm-password` に直接アクセスすると500エラーになる。
- 再現手順: F-11参照。ログイン済み (2FA有効) アカウントで `/user/confirm-password` に直接アクセス、2回連続で再現。
- 阻害されたユーザージョブ: 機微操作 (アカウント削除・オーナー移譲・2FA無効化等) の直前にこの画面へ遷移する導線があった場合、ユーザーが本人確認を完了できず操作が詰む可能性。
- 改善アクション候補: password.confirm のInertiaレンダリングで参照しているセッション値 (intended URL等) が未設定時にクラッシュしていないか確認し、フォールバックを実装。
- 関連ファイル: (未調査。confirm-password コントローラを中心に調査推奨)
