# bug-hunt report shard-2 (run 20260713-085818)

- 対象 URL: http://127.0.0.1:8012 (DB: bug_hunt_2)
- 担当ストーリー: S1 (登録/ログインファネル), S2 (招待フロー)
- ブラウザセッション: playwright-cli -s=bughunt2
- 開始時 db-check: db=bug_hunt_2, users=8

## 画面カバレッジ (S1/S2 割当分)
- S1 screens: home, register, login, dashboard, verification.notice, verification.verify, password.request, password.reset, two-factor.login, contact, contact.thanks, legal.commerce-disclosure, legal.privacy, legal.terms
- S2 screens: invitations.accept
- 走行状況: **14/14 走行済み** (S1 screens 全て + S2 の invitations.accept)。
  - two-factor.login (screen) のみ「有効化された 2FA アカウントでのログイン」という条件分岐は未検証 (下記 skip 参照。
    ページ自体は operations.md 上 screen 未定義のため二重カウントしない)。
  - invitations.accept は「有効な招待トークン」でのハッピーパスは F-04 によりブロックされ未検証。無効/欠落トークンの
    2 パターン (token 無し=404、bogus token=Invalid 専用画面) は検証済み。

## 操作カバレッジ (S1/S2 割当分)
- S1 operations: register.store, login.store, logout, password.email, password.update, verification.send, two-factor.login.store, contact.store, debug.login-as
- S2 operations: invitations.accept.store, organizations.invitations.store, organizations.invitations.revoke, organizations.members.update, organizations.members.destroy, organizations.members.two-factor.reset
- 走行状況:
  - **実行済み (7/9, S1)**: register.store (バリデーション含む), login.store (バリデーション/誤パスワード含む),
    logout, password.email, password.update, verification.send, contact.store (バリデーション/二重送信含む)
  - **skip (2/9, S1)**: two-factor.login.store (2FA 有効アカウント無し、理由付き skip)、
    debug.login-as (bughunt 環境で 404、fail-safe 設計、理由付き skip)
  - **skip (6/6, S2)**: F-04 によりブロックされ全操作 skip (理由付き、上記 finding 参照)

## UI/UX 検証 (H11-H14)
- H13 (レスポンシブ): home / register を mobile 375x667・home を tablet 768x1024 で確認。
  home ヘッダーで mobile 375 幅の単語途中改行を検出 (F-03)。register / tablet は崩れなし。確認後 desktop
  (1280x900) に復帰済み。
- H11 (視覚破綻): 上記 F-03 以外は全画面で顕著な崩れ・重なり・overflow は未検出。
- H12 (アフォーダンス/状態): 送信中ボタンの disabled/loading 状態、バリデーション invalid 状態 (赤枠+エラー文)
  は全フォームで一貫して機能。パスワード表示切替ボタンは目視未確認 (クリック未実施、動作は自明と判断し省略)。
- H14 (a11y 基礎): 全フォームで textbox/checkbox/button に role・accessible name が snapshot 上で取得でき、
  未ラベルの要素は検出せず。2FA 設定 UI で QR コードの手動入力用テキスト代替が見当たらない点のみ observation
  (「インベントリ修正提案」に記載、S6 スコープのため finding 化せず)。

## findings サマリ
- Critical 1 (F-04) / High 1 (F-01) / Medium 2 (F-02, F-03) / Low 0 / 要確認 1 (パスワード漏洩チェックの外部通信有無)
- 追加 observation: ログイン中に別ユーザーのメール認証署名リンクへ 1 回だけアクセスした際に予期せずログアウトされた
  事象を観測したが、再現手順を変えて2回目は正しく 403 (ログイン維持) となり再現性を確立できなかったため finding化
  していない (下記「要確認」に追記)。

## インベントリ修正提案
- `debug.login-as` / `debug.login` (S1 割当 operation): bughunt 環境 (`APP_ENV=bughunt.local`) では `app()->isLocal()`
  が false のためルート自体が未登録で 404 になる (routes/web.php の isLocal||runningUnitTests guard)。
  operations.md に「bughunt 環境では到達不能 (fail-safe 設計)」旨の注記を追加することを提案する。
- 設定 > セキュリティの 2FA 有効化 UI (S6 スコープだが S1 の two-factor.login 分岐検証で遭遇): QR コードのみが
  表示され、手動入力用シークレットキー (テキスト表示) が見当たらない。認証アプリでQRを読み取れない環境
  (自動化ブラウザ・スクリーンリーダー利用者等) では 2FA を有効化できない可能性がある。S6 担当 shard での確認を提案。

## 要確認 (仕様不明のため severity 未確定)
- パスワード強度バリデーションに Laravel の `Password::uncompromised()` (HaveIBeenPwned k-anonymity API 相当) と
  思われるチェックがあり、"Password123456" のようなよくある文字列パターンで「データ漏洩で見つかったため使用できません」
  と拒否された (再現: /register で Password123456 を入力し送信 → F-01 finding 手順内の中間ステップで確認)。
  ブラウザの network タブには外部ドメインへのリクエストは出ない (サーバサイドで発生するため観測不可)。
  bug-hunt 環境は `TESTING_FAKE_EXTERNALS=true` で外部サービスを fake する前提だが、この判定がローカルのブルーム
  フィルタ/コーパスによるものか、実際に外部 API (api.pwnedpasswords.com 等) へ問い合わせているものかはブラウザ側からは
  判別できない。もし実外部通信なら禁止事項4 (実外部サービスに触れない) に抵触する可能性があるため、実装側の確認を推奨する。
- 再現性未確立の observation: owner-standard でログイン中に他ユーザー (id=9) 宛のメール認証署名リンクへアクセスした
  際、1 回目は `/login` へリダイレクトされセッションが失われた (直後の `/dashboard` アクセスでも未ログイン扱い)。
  同一操作をやり直したところ 2 回目は期待通り 403 (アクセスできません) でログイン状態も維持された。
  操作前の多数回のログイン/ログアウト切り替えによるセッション状態の偶発的な不整合の可能性が高いと考えるが、
  bug-hunt 実行環境固有の再現性の低い事象のため severity を付けず「要確認」に留める。もし再現するようなら
  「認証済みユーザーが他ユーザー宛の署名付き email/verify リンクを踏むと 403 ではなくセッションが失われる」
  可能性があり、該当時は authz_bypass ではないが session 管理のバグとして再調査が必要。

---

## Findings (詳細)

## F-01: 新規登録の無料チケット10枚がアカウントに付与されない
- severity: High
- story/step: S1-3 (register → verification.verify)
- 再現手順:
  1. http://127.0.0.1:8012/ または /pricing を開く。「新規登録でチケット 10 枚が無料」「新規契約でチケット 10 枚が無料でついてきます (付与から 30 日間有効)」という訴求文言を確認。
  2. http://127.0.0.1:8012/register で新規アカウントを作成 (例: shard2-newuser@example.com / password Xk9mQzTh7pLwR2v)。
  3. メール認証 (email/verify/{id}/{hash} 署名付きリンク) を完了し dashboard に遷移。
  4. dashboard の「チケット残高」を確認 → `0` (「残高が少なくなっています」の警告表示付き)。
  5. /purchase-tickets の「現在の残高」でも `0 枚` と表示される。
- 期待: 新規登録直後に無料チケット10枚が付与され、残高が「10」と表示される (トップ/料金ページの訴求と一致)。
- 実際: 新規登録・メール認証完了後もチケット残高は 0 のまま。dashboard には「残高が少なくなっています」という警告まで表示され、無料トライアルの体験ができない。
- 阻害されたユーザージョブ: 新規ユーザーが無料チケットでAI解析・動画レンダを試す (プロダクトの最初の価値体験) ができない。ユーザーは「登録すれば10枚もらえる」と信じて登録したのに何も使えず離脱する可能性が高い。
- 改善アクション候補: 登録時 (またはメール認証完了時) に無料チケット付与処理が実行されているか確認する。付与タイミングがメール認証完了後で本テストでは反映が間に合っていない可能性もあるため、付与トリガー (registered イベント / verified イベント / キュー処理) を確認する。もし意図的に無料チケットを廃止したなら、トップ/料金ページの文言修正が必要。
- 証跡: screenshots/F-01-zero-tickets-after-register.png (dashboard)、/purchase-tickets 残高表示も同じ 0 枚。
- 推定原因: 未調査 (チケット付与がキュー経由の非同期処理で、本走行時にキューワーカーが処理し切れていない可能性 / もしくは付与ロジック自体が未実装・条件不一致の可能性)。
- 関連既知情報: 未確認。TODO.md 等の参照なし。

## F-03: トップページ (home) のヘッダーナビが mobile 375px 幅で単語途中改行し視認性が崩れる
- severity: Medium
- story/step: S1-1 (home), H11/H13 (視覚破綻・レスポンシブ)
- 再現手順:
  1. http://127.0.0.1:8012/ を開く。
  2. `playwright-cli resize 375 667` で mobile 幅にする。
  3. ヘッダーを確認 → ロゴ「AI-CUE」が「AI-」「CUE」で改行、ナビの「料金プラン」「ログイン」「無料で始める」も
     それぞれ単語の途中 (例: 「料金プラ」「ン」、「ログイ」「ン」、「無料で始」「める」) で折り返される。
  4. ハンバーガーメニュー等のモバイル向け折りたたみは無く、4 つの要素が横一列に詰め込まれたまま縮小されている。
- 期待: モバイル幅でもロゴ・ナビ項目が単語単位で改行される (もしくはハンバーガーメニューに畳まれる) 等、
  読みやすいレイアウトになる。
- 実際: 単語の途中で強制改行され、ヘッダーが縦に間延びし視認性・第一印象が悪化する (ファーストビューの CTA
  である「無料で始める」ボタンの文言も読みにくい)。
- 阻害されたユーザージョブ: 新規訪問者 (スマホ経由が多いと想定される) が最初に見るヘッダーの印象が悪く、
  ブランドロゴ・CTA の可読性が下がることで離脱リスクが上がる。操作自体は可能なため阻害は軽微 (Medium)。
- 改善アクション候補: mobile breakpoint でヘッダーをハンバーガーメニュー化する、またはナビ項目のフォントサイズ縮小・
  折り返し単位を CSS (word-break/overflow-wrap の調整、あるいは flex-wrap) で単語単位に制御する。
- 証跡: screenshots/H13-home-mobile-375.png (viewport 375x667)
- 推定原因: 未調査 (ヘッダーの flex コンテナに min-width 制御が無く、要素数に対して横幅が不足している可能性)。
- 関連既知情報: 未確認。TODO.md 等の参照なし。

## F-04: 組織設定/メンバー招待画面へのナビゲーション導線が UI 上に一切存在しない (S2 の核心操作が到達不能)
- severity: Critical
- story/step: S2-1 (organizations.invitations.store 等、全操作)
- 再現手順:
  1. http://127.0.0.1:8012/login で `owner-standard@example.com` / `password123` (組織オーナー) でログイン。
  2. dashboard / settings / billing / projects/create など到達可能な全画面のヘッダー・本文を確認 → 常設ナビは
     「通知」「設定 (個人プロフィール)」「ログアウト」のみで、組織設定・メンバー管理・招待へのリンクが存在しない。
  3. `multi-org@example.com` (複数組織に所属) でも確認 → dashboard の shared props (`organizations` 配列) に
     組織一覧 (id/name) はあるが `slug` が含まれておらず、組織切替 UI 自体も未実装 (AppLayout.svelte が
     「ログイン中は通知ベル・設定・ログアウトを全ページ常設する」のみで組織切替を持たないことをコード上で確認)。
  4. ルート定義 (`routes/web.php`) を確認すると `organizations/{organization:slug}/settings` (GET, S4 screen)
     に組織一覧/index ルートが存在せず、slug を事前に知らない限り到達不能。
  5. 結果として `organizations.invitations.store` / `organizations.invitations.revoke` /
     `organizations.members.update` / `organizations.members.destroy` /
     `organizations.members.two-factor.reset` (すべて S2 割当) を UI 操作で到達・実行する経路が存在しない。
- 期待: 組織オーナー/管理者であれば、ダッシュボードやヘッダーから組織設定 (メンバー管理・招待) 画面に
  到達できる (S2 のストーリーが成立する前提)。
- 実際: 現在のビルドでは組織設定へのナビゲーション導線が一切実装されておらず、オーナーであってもメンバーを
  招待する手段が UI 上に存在しない。
- 阻害されたユーザージョブ: 「組織オーナーがメンバーを招待し、撮影者/編集者を追加してチームで運用する」という
  プロダクトの中核ジョブ (トップページで謳う「組織で安全に運用できます」「誰が撮っても、同じ品質に」) が
  現状 UI からは一切実行できない。
- 改善アクション候補: AppLayout (または dashboard) に現在の組織名 + 組織設定へのリンクを常設する。
  複数組織所属時は切替 UI も必要。shared props (`SharedProps`/`HandleInertiaRequests`) に `currentOrganization.slug`
  を含める (現状 id/name/role のみで slug が欠落しており、たとえリンクを実装しても URL を組み立てられない)。
- 証跡: screenshots/F-04-no-org-settings-nav.png (owner-standard dashboard、組織設定への導線なし)
- 推定原因: `resources/js/components/templates/AppLayout.svelte` のコード内コメントに
  「Phase 2 (組織・Team・Project 導入) でサイドバー・組織切替・通知センターを拡張する。」とあり、意図的な
  段階的実装で組織ナビが未着手であることが確認できる。**既知の開発途上 (Phase 2 予定) の可能性が高いが、
  現行ビルドで S2 のシナリオが体験できない事実は変わらないため finding として記録する。**
  operations.md 側で S2 を「Phase 2 実装後に検証可能」と明記するか、ナビ実装を前倒しするかの判断を推奨。
- 関連既知情報: `resources/js/components/templates/AppLayout.svelte` 冒頭コメント (Phase 2 予定の言及) を参照。
  devnotes/TODO.md 等での対応するタスクの有無は未確認。

## skip (F-04 によるブロック): S2 の招待/メンバー管理操作全般
- operations: organizations.invitations.store, organizations.invitations.revoke, organizations.members.update,
  organizations.members.destroy, organizations.members.two-factor.reset, invitations.accept, invitations.accept.store
- 理由: F-04 の通り、組織設定画面 (招待フォーム含む) への UI 到達手段が存在しないため、招待そのものを作成できず
  被招待者側の受諾フロー (invitations.accept 系) も再現できない。DB 直接操作・生 artisan/tinker での招待レコード
  作成は禁止事項のため行っていない。

## F-02: パスワードリセット完了後に成功フィードバックが無い
- severity: Medium
- story/step: S1-5 (forgot-password → password.email → password.reset → password.update)
- 再現手順:
  1. http://127.0.0.1:8012/forgot-password でメールアドレスを送信 (「パスワードリセット用のリンクをメールで送信しました」のトースト表示は正常に出る)。
  2. `tmp/bug-hunt/shard-2-cmd.sh mail-urls` で reset-password リンクを取得し開く。
  3. 新しいパスワードを入力し「パスワードをリセット」をクリック。
  4. `/login` へリダイレクトされるが、成功を示すトースト/バナー/メッセージが一切表示されない (2 回再現して確認)。
- 期待: 他の操作 (メール送信・お問い合わせ送信・認証メール再送信) と同様、パスワード変更完了時も「パスワードを変更しました」等のフィードバックがログイン画面に表示されるべき (H7)。
- 実際: 何の説明もなく静かに /login に遷移するだけ。ユーザーは操作が成功したのか、たまたまログイン画面に戻されただけなのか判別できない。
- 阻害されたユーザージョブ: パスワード再設定が完了したことを確認できず、不安なまま新パスワードでログインを試すことになる (実際には成功していることは動作確認済み)。
- 改善アクション候補: password.update 成功時のレスポンスに session flash (成功メッセージ) を追加し、/login 画面でトースト表示する。
- 証跡: screenshots/F-02-no-flash-after-password-reset.png
- 推定原因: 未調査 (register/contact/verification.send では flash が実装されているため、password.update のコントローラだけ flash 追加漏れの可能性)。
- 関連既知情報: 未確認。TODO.md 等の参照なし。

---

## skip 一覧

- operation `debug.login-as` (POST /debug/login/{userId}, S1 割当): `/debug/login` (GET, index) にアクセスすると 404。
  ルート定義 (routes/web.php) が `app()->isLocal() || app()->runningUnitTests()` の場合のみ登録される設計 (LocalOnly middleware も二重防御)。
  bughunt 環境は `APP_ENV=bughunt.local` であり `isLocal()` が false のため、ルート自体が存在しない。
  **意図的な fail-safe 設計であり bug ではない**。bughunt 環境ではこの operation は原理的に到達不能なため skip する
  (screens.md/operations.md 側で「bughunt 環境では検証不可」と明記する運用修正を提案)。
- 2FA ログイン分岐 (two-factor.login / two-factor.login.store): 既定シードアカウントに 2FA 有効ユーザーが存在しない。
  自アカウント (shard2-newuser@example.com) で `設定 > セキュリティ` から有効化を試みたが、TOTP QR コードのみが表示され
  手動入力用のシークレットキー (テキスト) が UI 上に見当たらず、認証アプリなしでは有効化を完了できないため skip。
  (この QR-only UX 自体は S6 のスコープなので S1 側では finding 化せず「インベントリ修正提案」に記載するに留める)

---

## 環境ハザード

(なし。走行中に serve 断・DB 断・全 500 化などは発生しなかった)

---

## Critical/High summary (TODO 候補)

- **F-04 (Critical)**: 組織設定/メンバー招待画面へのナビゲーション導線が UI に存在せず、S2 の全操作
  (organizations.invitations.store 等) が到達不能。再現: shard-report.md#F-04。
  阻害ジョブ: 組織オーナーがメンバー (編集者/撮影者) を招待してチーム運用を始めること。
  改善: AppLayout / dashboard に組織設定への常設リンクを追加 + shared props に currentOrganization.slug を追加。
  関連ファイル: resources/js/components/templates/AppLayout.svelte,
  app/Http/Controllers/Organizations/OrganizationController.php, routes/web.php (organizations.settings 系)。
- **F-01 (High)**: 新規登録時の無料チケット10枚がアカウントに付与されない。再現: shard-report.md#F-01。
  阻害ジョブ: 新規ユーザーが無料でAI解析・動画レンダを試す最初の価値体験。
  改善: 登録/メール認証完了時のチケット付与トリガー (イベント/キュー) の実装状況を確認。
  関連ファイル: 未特定 (チケット付与ロジックの担当 Service/Listener を実装側で特定要)。
