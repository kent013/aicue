# bug-hunt report shard-3 (run 20260713-085818)

- 実行ストーリー: S4 (組織・プロジェクト・カテゴリ・ユーザー管理), S5 (課金・チケット)
- 対象URL: http://127.0.0.1:8013
- DB: bug_hunt_3 (db-check時点 users: 8)
- 走行状態: 完了 (S4/S5 screens 全走行、operations は一部 skip/代替実行あり。理由は下記カバレッジ節参照)

## 画面カバレッジ
- S4 screens: organizations.create○, organizations.settings○, organizations.api-keys.index○, organizations.api-keys.sessions.index○, organizations.onboarding.cli○, organizations.onboarding.mcp○, manage.users.index○, projects.index○, projects.create○, projects.edit○, projects.categories.index○ — 11/11 走行済み
- S5 screens: pricing○, billing.index○, billing.tickets.show(=purchase-tickets)○ — 3/3 走行済み

## 操作カバレッジ
- S4 operations: organizations.store○, organizations.update○, organizations.switch△(UI無し。fetch直叩きで代替実行), organizations.transfer-ownership○, organizations.two-factor-requirement.update○(業務ルール拒否パスのみ確認), organizations.api-keys.store○, organizations.api-keys.revoke○, organizations.api-keys.sessions.revoke skip(該当セッション無しのため実行不可。理由: OAuth接続セッションが0件で失効対象が存在しない), projects.store○, projects.update○, projects.destroy○, projects.categories.store○, projects.categories.update○, projects.categories.destroy○, projects.categories.reorder○, projects.members.store skip(理由: 呼び出すUIがアプリ内に存在しない。F-3-04参照), projects.members.destroy skip(同上), projects.items.store○, projects.items.update○, projects.items.destroy○, debug.login-as skip(理由: bughunt環境はAPP_ENV=bughunt.localでありapp()->isLocal()がfalseのためroute自体が404。意図的なfail-safe gateと判断) — 15/20 実行、2 skip(理由明記)、1 skip(セッション無し)、1 代替実行
- S5 operations: billing.checkout△(fake gateway起動まで確認。実際のプラン反映はfake harnessの仕様上「neutral return」でありUIから完走確認不可。理由: FakeSubscriptionCheckoutGateway/FakeTicketCheckoutGatewayのdocblockで「決済・チケット付与・状態変更は一切行わない」と明記), billing.portal○, billing.tickets.checkout△(同上。購入導線・バリデーションは確認、残高反映はfake harness仕様により未確認)

## UI/UX 検証
- H11 (視覚破綻): projects/{id} 画面 (mobile 375 / tablet 768) で確認、崩れなし
- H12 (アフォーダンス/状態): カテゴリ/アイテム/APIキー/招待の追加・編集・削除ボタンはすべて明確なラベルと確認ダイアログを持つ。異常なし
- H13 (レスポンシブ): projects/1 (プロジェクト詳細、要素数の多い代表画面) を mobile 375×667 / tablet 768×1024 で確認 → 崩れなし。デスクトップ (1280×900) に復帰済み
- H14 (a11y基礎): onboarding/cli の「コピー」ボタンで clipboard 書き込み失敗 (Q-3-01、環境要因の可能性)。見出し階層・aria-label は主要画面で概ね適切

## findings サマリ
Critical 2 / High 1 / Medium 1 / Low 1 / 要確認 2

## インベントリ修正提案
- S4 stories手順4「管理者ユーザー管理(manage.users.index): ...ID英数20字・重複不可、表示名全角20字、PW 8〜16字、メール形式」という記述は実装と不一致。実際の manage.users.index は「招待メール送信」ベースの UI で、ID/パスワードを直接入力してユーザーを作成する機能は存在しない(推測: 別テンプレート/アプリの記述が残存)。実際の operations は organizations.invitations.store/revoke, organizations.members.update/destroy (screens.md では S2 に割当)。ストーリーカードの更新を提案。
- S5 stories手順3「二重送信しても二重課金にならない(冪等)」は現行の bug-hunt fake harness (FakeTicketCheckoutGateway/FakeSubscriptionCheckoutGateway) では検証不能。両 Fake は doc comment で明記の通り「決済・チケット付与・状態変更は一切行わない (neutral return)」ため、UI 操作だけでは checkout 完了後の残高/プラン反映を再現できない。将来的にこのフローを bug-hunt で検証したい場合は、Webhook 相当のシミュレーションを注入できる専用 wrapper サブコマンドの追加を検討事項として提案する。

## Critical/High TODO候補
- F-3-03 (Critical): ManualTestSeeder が Free プラン組織にも `plan_code='free'` を強制代入するため、BillingAccess の「plan_code null = free tier 無償許可」判定に乗らず、有償プラン契約時と同じ「支払い健全性チェック」に落ちる。Stripe subscription 行が存在しないため fail-closed で不許可となり、Free プラン組織の全ユーザー (owner-free/admin-free/member-free) が /projects 等の中核業務画面に一切アクセスできない。
- F-3-02 (Critical): 組織切替 (organizations.switch) を呼び出す UI が全画面に存在しない。複数組織所属ユーザーが一度でも組織を切り替える(または新規組織作成で自動切替される)と、UI から元の組織に戻る手段が一切ない詰み状態になる。
- F-3-01 (High): 組織作成後の組織設定・API キー・課金・料金表など主要管理画面へ恒常的なナビゲーション導線がなく、一度きりのURL(作成直後のリダイレクト)や直接URL入力でしかたどり着けない。
- F-3-04 (Medium): `projects.members.store`/`projects.members.destroy` (プロジェクト個別のメンバー管理) を呼び出す UI がアプリ内のどこにも存在しない。
- F-3-05 (Low): `config/seo.php` の `app_titles` マップに複数の route が未登録のため、ブラウザタブの `<title>` がサイト名 "AI-CUE" のみになる画面が複数存在する (manage.users.index, projects.categories.index, organizations.api-keys.index, organizations.api-keys.sessions.index, organizations.onboarding.cli, organizations.onboarding.mcp)。

## 要確認リスト
- Q-3-01: onboarding/cli, onboarding/mcp の「コピー」ボタンがクリック時に毎回「コピー失敗」を表示する。ヘッドレスブラウザの clipboard permission 制約による環境起因の可能性が高いため断定せず要確認とする。
- Q-3-02: S5 の「二重送信しても二重課金にならない」「チャージ直後にジョブ失敗で予約解放」等の冪等性シナリオは、bug-hunt の fake checkout harness が決済完了を一切シミュレートしないため UI 経由では検証できなかった (インベントリ修正提案を参照)。実装自体の妥当性は未確認 (要確認)。

---
(以下、finding 詳細を見つけ次第逐次追記)

## F-3-03: Free プラン組織が「支払い健全性チェック」に誤って引っかかり、全ユーザーが中核機能を一切利用できない
- severity: Critical
- story/step: S4-2 (projects.index に到達できない) / S5 前提条件にも影響
- 再現手順:
  1. http://127.0.0.1:8013/login に member-free@example.com / password123 でログイン (Free プラン組織のメンバー)
  2. ヘッダーの導線が無いため直接 http://127.0.0.1:8013/projects に遷移
  3. `require-active-subscription` ミドルウェアにより強制的に `/billing` へリダイレクトされ、赤い alert
     「サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。」
     が表示される (プランは "Free" 0 枚)
  4. owner-free / admin-free でログインしても同様に /projects 等の業務画面すべてが /billing にリダイレクトされる (組織単位のゲートのため role に依らず全員影響)
  5. コード根拠: `app/Services/Billing/BillingAccess.php` の doc comment により、本来 `plan_code === null` (未契約 = free tier) は無条件許可される設計。しかし `database/seeders/ManualTestSeeder.php` の `createOrganization()` は plan (Free 含む) 全てに対し `$organization->forceFill(['plan_code' => $plan->code])->save();` を実行しており、Free プラン組織にも `plan_code='free'` (非 null) が代入される。`BillingAccess::hasActiveAccess()` は `plan_code !== null` の場合 `organization->subscription('default')` が active/trialing であることを要求するが、Free 組織には (Checkout を経ていないため) Stripe subscription 行が存在せず fail-closed で不許可になる
- 期待: Free プラン組織はいかなる操作をせずとも中核機能 (プロジェクト作成・マニュアル作成等) に無償でアクセスできる (BillingAccess のコード doc が明言する設計)
- 実際: Free プラン組織の全ユーザーが /projects をはじめとする課金ゲート対象 route すべてで /billing に強制リダイレクトされ、「支払いが確認できない」という誤った理由表示のまま中核機能が完全に使用不能になる
- 阻害されたユーザージョブ: Free プランで試用しようとしている新規ユーザーが、プロジェクト作成・マニュアル作成など S3/S4 の中核ジョブを一切開始できない (無償利用の入口が事実上ふさがれている)
- 改善アクション候補: `ManualTestSeeder::createOrganization()` で Free プラン (`$plan->code === 'free'` 相当 / Stripe Price を持たないプラン) には `plan_code` を `null` のままにする (forceFill をスキップする)。あるいは Free 用に Stripe fake subscription (active) をあわせて用意する
- 証跡: screenshots/S4-05-free-plan-blocked.png
- 推定原因: `database/seeders/ManualTestSeeder.php` の `createOrganization()` (該当行: `$organization->forceFill(['plan_code' => $plan->code])->save();`) が `BillingAccess`/`RequireActiveSubscription` の invariant (`app/Services/Billing/BillingAccess.php` の doc comment: 「支払い不要のプランを plan_code に載せる場合は本判定とセットで見直すこと」) に反している。おそらく devnotes/20260712-0927-bugfix-billing-free-access で BillingAccess 側の判定ロジックが修正された際、Seeder 側が追従していない (regression の可能性)
- 関連既知情報: devnotes/20260712-0927-bugfix-billing-free-access (BillingAccess の free tier 許可ロジックの導入経緯。本 finding はその決定とシーダーの不整合)

## F-3-04: プロジェクト個別メンバー管理 (projects.members.store/destroy) を呼び出す UI が存在しない
- severity: Medium
- story/step: S4-2 (projects.members.store/destroy)
- 再現手順:
  1. owner-standard@example.com でログインし /projects/{id} (プロジェクト詳細) を開く
  2. 「アイテム」「カテゴリ管理」「ユーザー管理」への導線はあるが、プロジェクト単位のメンバー追加/削除フォームがどこにもない
  3. `resources/js/pages/Projects/Show.svelte` / `Edit.svelte` を全文検索しても `project.members` 系の呼び出しが無い一方、`app/Http/Controllers/Projects/ProjectMemberController.php` は store/destroy とも実装済みで、成功時 flash メッセージ文言まで用意されている
  4. `manage/users.index` の「ロール」combobox (管理者/編集者/撮影者) は `OrganizationMembershipService::applyConsoleRole()` により組織の Default Project にのみ pivot を同期する実装であり、Default Project 以外の複数プロジェクト運用では個別のメンバー管理手段が UI 上に存在しない
- 期待: 「編集者・撮影者」をプロジェクト単位で個別にアサインできる (バックエンドの実装が想定する契約)
- 実際: UI からはこの操作を一切実行できない (Default Project 以外のプロジェクトでは編集者/撮影者の個別アサインが事実上不可能)
- 阻害されたユーザージョブ: 複数プロジェクトを持つ組織で、プロジェクトごとに異なるメンバー構成 (誰がどのプロジェクトの編集者/撮影者か) を管理できない
- 改善アクション候補: プロジェクト詳細/編集画面に projects.members.store/destroy を呼ぶメンバー管理 UI を追加する
- 証跡: コード根拠 (resources/js 全文検索で該当 UI 無し、app/Http/Controllers/Projects/ProjectMemberController.php は実装済み)
- 推定原因: フロントエンド未実装 (Default Project の console role で単一プロジェクト運用がカバーされるため後回しになった可能性)
- 関連既知情報: なし (未調査)

## F-3-02: 組織切替 (organizations.switch) を呼び出す UI が存在せず、切替後に元の組織へ戻れない (詰み)
- severity: Critical
- story/step: S4-1 (organizations.switch)
- 再現手順:
  1. http://127.0.0.1:8013/login に owner-standard@example.com / password123 でログイン (current_organization = Standardプラン組織、id=2)
  2. /organizations/create から新規組織「S4新規組織」を作成 → 作成直後に current_organization が自動的に新組織 (id=3) に切り替わり、`/organizations/{slug}/settings` へリダイレクトされる
  3. /projects に遷移 → 新組織にはプロジェクトが無いため一覧は空。元々作成していた /projects/1 (Standard組織所有) へ直接 URL 遷移すると 404 (テナント分離としては正しい)
  4. アプリ内の全画面 (ヘッダー・ダッシュボード・設定・プロジェクト一覧・管理メニュー) を `playwright-cli eval "() => Array.from(document.querySelectorAll('button, a, select')).map(...)"` で走査しても、組織切替のトリガーとなる UI 要素が一つも存在しない
  5. ソース `resources/js/components/templates/AppLayout.svelte` のコメントで「サイドバー・組織切替・通知センターを拡張する (Phase 2)」と明記されており、組織切替 UI が未実装であることをコードレベルでも確認
- 期待: `organizations.switch` (POST /organizations/{organization}/switch) は実装済みの操作であり、S4 ストーリーが要求する主要操作。複数組織に所属するユーザーは UI から組織を切り替えられるべき
- 実際: バックエンドの `organizations.switch` route は存在するが、呼び出す UI (組織スイッチャー/組織一覧画面) がアプリのどこにも実装されていない。一度切替が発生すると (今回は組織新規作成で自動発生)、UI 操作だけでは元の組織に戻れず、当該組織配下の全プロジェクト・カテゴリ・APIキー等の管理作業ができなくなる
- 阻害されたユーザージョブ: 複数組織に所属する/複数組織を運営するユーザーが「別の組織の作業に切り替える」「誤って切り替わった後に元の組織に戻る」ことができず、他組織のプロジェクト管理そのものが完全に不能になる
- 改善アクション候補: ヘッダーまたはサイドバーに組織スイッチャー (ドロップダウン等、shared prop `organizations` を利用) を追加する。最低限、`organizations.switch` を呼べる GET 画面 (例: /organizations 一覧) を用意する
- 証跡: screenshots/S4-02-no-org-switcher.png、コード根拠 resources/js/components/templates/AppLayout.svelte:12-13 のコメント
- 推定原因: AppLayout.svelte のコメントに「Phase 2 で拡張する」と明記の通り、組織切替 UI が未実装のまま (実装漏れ、意図的な未着手の可能性あり)
- 関連既知情報: docs/TODO.md に該当記述なし (未追跡の可能性)

## F-3-01: 組織設定/API キー/課金/料金表などへの恒常的なナビゲーション導線が存在しない
- severity: High
- story/step: S4-1, S4-5 / S5-1,2
- 再現手順:
  1. http://127.0.0.1:8013/login に owner-standard@example.com / password123 でログイン
  2. /dashboard, /projects, /projects/1, /settings, /manage/users, /projects/1/categories の各画面を snapshot・DOM 内 `<a href>` を確認 (`playwright-cli eval "() => Array.from(document.querySelectorAll('a')).map(a=>a.href)"`)
  3. organizations.settings / organizations.api-keys.index / billing.index / pricing への恒常的なリンクがヘッダー・サイドバー・ダッシュボードのどこにも存在しないことを確認
  4. 唯一 organizations.settings に到達できるのは「組織を新規作成した直後の自動リダイレクト」のみ。既存組織の設定を再訪する手段が UI 上にない (URL を記憶/ブックマークするしかない)
- 期待: 組織オーナー/管理者は、いつでもヘッダーやサイドバーの導線から組織設定・API キー管理・課金/プラン画面に到達できるべき
- 実際: header は「AI-CUE ロゴ・通知・設定(個人設定のみ)・ログアウト」のみで、組織/課金関連の導線が皆無。プロジェクト内の「管理メニュー」にも「ユーザー管理」「カテゴリ管理」しかなく、組織設定・API キー・課金へのリンクがない
- 阻害されたユーザージョブ: 組織のオーナーが後日、組織名変更・2FA必須化・オーナー移譲・APIキー発行・プラン変更/チケットチャージを行おうとしても、通常の画面遷移では到達できず作業自体を断念する可能性が高い
- 改善アクション候補: ヘッダーまたはサイドバーに「組織設定」「請求/プラン」への恒常的なリンクを追加する。少なくとも /settings 画面や /projects 画面から到達できる導線を用意する
- 証跡: screenshots/S4-01-projects-no-nav.png (プロジェクト一覧に組織関連導線が一切ない状態)
- 推定原因: 未調査 (レイアウトコンポーネントにナビゲーション項目が実装されていない可能性。F-3-02 と根本原因は同一の可能性が高い)
- 関連既知情報: なし (未調査)

## F-3-05: 一部画面のブラウザタブタイトルがサイト名のみになる (`app_titles` map の未登録)
- severity: Low
- story/step: S4-3, S4-4, S4-5 (projects.categories.index, manage.users.index, organizations.api-keys.*, organizations.onboarding.*)
- 再現手順:
  1. owner-standard@example.com でログインし、以下の画面を順に開いてブラウザタブタイトル (`playwright-cli snapshot` の `Page Title`) を確認する:
     - /projects/{id}/categories → "AI-CUE" のみ (期待: 「カテゴリ管理 | AI-CUE」等)
     - /manage/users → "AI-CUE" のみ (期待: 「ユーザー管理 | AI-CUE」等)
     - /organizations/{slug}/api-keys → "AI-CUE" のみ
     - /organizations/{slug}/api-keys/sessions → "AI-CUE" のみ
     - /organizations/{slug}/onboarding/cli → "AI-CUE" のみ
     - /organizations/{slug}/onboarding/mcp → "AI-CUE" のみ
  2. 一方 /projects, /projects/create, /projects/{id}/edit, /organizations/create, /organizations/{slug}/settings, /billing, /purchase-tickets 等は正しく固有タイトルが付与されている
  3. コード根拠: `app/Http/Middleware/HandleInertiaRequests.php` → `SeoManager::resolveDocumentTitle()` → `config('seo.app_titles')[routeName]` のフォールバック map (`config/seo.php`) に、上記 6 route が未登録
- 期待: 全ての認証済み画面がブラウザタブ上で識別可能な固有タイトルを持つ (他の大半の画面と同様)
- 実際: 上記 6 画面はサイト名 "AI-CUE" のみが表示され、複数タブを開いた際や履歴・ブックマークから見分けがつかない
- 阻害されたユーザージョブ: 複数タブで作業中のユーザーがタブを見分けられない。スクリーンリーダー利用者がタブ切替時にページ内容を音声で把握できない (H14 a11y にも関連)
- 改善アクション候補: `config/seo.php` の `app_titles` に `projects.categories.index`, `manage.users.index`, `organizations.api-keys.index`, `organizations.api-keys.sessions.index`, `organizations.onboarding.cli`, `organizations.onboarding.mcp` を追加する
- 証跡: 各画面の `playwright-cli snapshot` 出力 (`Page Title: AI-CUE`)。コード根拠 `config/seo.php` の `app_titles` 配列 (該当 6 route が不在)
- 推定原因: `config/seo.php` の `app_titles` map 追加漏れ (新規画面実装時のチェックリスト漏れ)
- 関連既知情報: なし (未調査)
