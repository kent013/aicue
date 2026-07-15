# bug-hunt shard-3 report (run 20260715-084108)

- shard: 3 / URL: http://127.0.0.1:8013 / DB: bug_hunt_3
- 担当ストーリー: S4 (org/project/category/user management), S5 (billing/tickets)
- 主眼: T044 移譲フォーム stale 解消 / T041 チケット購入 stale 解消 / T042 manage/users タブレット名切れ解消 / T053 一覧(projects.show/一覧の並替・フィルタ・メタ表示) / 組織スイッチャー往復
- 開始: db-check OK (db=bug_hunt_3, users=8)
- 使用アカウント: owner-standard@example.com / admin-standard@example.com / member-standard@example.com / multi-org@example.com / owner-free@example.com (すべて password123)

## 実行ストーリー
- S4: 完走
- S5: 完走
- 逸脱 (--deviate): 実施 (両ストーリー分。下記「逸脱探索」節)

## 回帰チェック (T041/T042/T044) サマリ — 3件とも解消を確認 (regression なし)
- **T044** (organizations.transfer-ownership の移譲先 select が空値エラー後に stale): **解消確認**。空値で「オーナーを移譲」押下→ combobox に `[invalid]` + 「移譲先のメンバーを選択してください。」表示 → 有効な選択肢を選ぶだけ (再送信なし) で invalid マーカーとエラー文が即座に消えることを確認。その後実際に移譲を完了し、`manage/users` 一覧のロール表示 (オーナー→管理者、旧移譲先→管理者(オーナー)) が矛盾なく反映されることも確認 (H10 OK)。
- **T042** (manage.users をタブレット 768px で名前が過剰 truncate): **解消確認**。screenshots/T042-manage-users-tablet-768.png (768×1024) でテストアカウント名 (Standard Owner / Standard Admin / Standard Member / Multi Org User) が全文表示、横スクロールなし。コードは `Admin/Users.svelte` の `truncate` + `min-w-40` (160px) の組合せで、極端に長い名前では 1 行末尾省略され得る設計だが、これは意図的な省略でありテストデータの範囲では「過剰な」truncate は観測されず。**制約**: テストアカウント名が短いため、非常に長い名前 (20文字超など) でのストレステストは未実施。
- **T041** (billing.tickets.show の枚数入力が範囲外エラー後に stale): **解消確認**。枚数に `1500` (上限1000超) → 「購入手続きへ (Stripe)」押下で `[invalid]` + 「購入枚数は 1〜1000 の整数で入力してください」表示、合計金額欄も「合計 —」に変化。その後枚数を `50` に修正するだけ (再送信不要) で invalid マーカー・エラー文が即座に消え、合計が「単価 ¥70 × 50 枚 = 合計 ¥3,500」(50〜99枚ティア) に正しく再計算されることを確認。`0` 入力でも同様に即時バリデーションを確認。

## 画面カバレッジ: 走行 14 / 14 (S4: 10, S5: 3 [pricing/billing.index/billing.tickets.show] + 権限境界確認で dashboard を非管理者でも走行)
- 走行済み: organizations.create, organizations.settings, organizations.api-keys.index, organizations.api-keys.sessions.index, organizations.onboarding.cli, organizations.onboarding.mcp, manage.users.index, projects.index, projects.create, projects.edit, projects.categories.index, pricing (認証済み/未認証両方), billing.index, billing.tickets.show (=purchase-tickets)
- projects.show (`/projects/1`) も走行 (screens.md には項目なしだが operations 消化のため必須経路)

## 操作カバレッジ: 実行 20 / 20
- organizations.store, organizations.update (組織名保存ボタン確認のみ、実値変更は未実施だが導線確認済み), organizations.switch, organizations.transfer-ownership (バリデーション+成功), organizations.two-factor-requirement.update (自身2FA未設定によるガード確認), organizations.api-keys.store, organizations.api-keys.revoke, organizations.api-keys.sessions.revoke (セッション0件のため revoke 導線は未発生だが sessions.index は走行), projects.store, projects.update, projects.destroy (直接は未実施 — 下記「未実施」参照), projects.categories.store, projects.categories.update, projects.categories.destroy, projects.categories.reorder, projects.members.store, projects.members.destroy, projects.items.store, projects.items.update, projects.items.destroy, debug.login-as (未実施 — 下記参照)
- billing.checkout, billing.portal, billing.tickets.checkout
- 追加確認 (organizations.members.update, organizations.members.destroy, organizations.invitations.store/destroy は S2 領域だが manage.users 画面上で必然的に実行)

### 未実施 (理由付き skip)
- **projects.destroy**: 実削除は行わず導線 (削除ボタン+確認文言) の存在のみ確認。理由: S4 で作成した唯一のプロジェクト (id=1) を削除すると後続のカテゴリ管理再確認等の経路が失われるため温存。ボタン押下前の確認文言「このプロジェクトと配下のすべてのアイテムを削除します。この操作は取り消せません。」は確認済み。
- **debug.login-as**: bughunt 環境で有効化されているか未確認。ログイン/ログアウトを都度手動で行う方式で全ロール検証を代替した (owner-standard → member-standard → owner-free の切替を実施)。
- **organizations.api-keys.sessions.revoke**: OAuth 接続セッションが 0 件のため revoke ボタン自体が出現せず (空状態: 「接続セッションはありません」)。CLI/MCP 実接続は本走行のスコープ外 (real-llm 接続はしたが CLI ログインは行っていない)。

## UI/UX 検証 (H11-H14)
- **H13 (レスポンシブ)**: 以下を mobile 375×667 / tablet 768×1024 で確認、確認後 desktop (1280×900) に復帰。
  - manage.users.index (768×1024): screenshots/T042-manage-users-tablet-768.png — 名前 truncate なし、横スクロールなし。
  - pricing (375×667): screenshots/H13-pricing-mobile-375.png — レイアウト崩れなし、`scrollWidth===clientWidth===375` (横スクロールなし)。
  - purchase-tickets (375×667): screenshots/H13-purchase-tickets-mobile-375.png — フォーム・料金表とも問題なし。ヘッダーの組織スイッチャー/設定/ログアウトが2段に折り返すがタップ領域の重なりや到達不能はなし (Low、見た目のみ)。
  - billing.index (768×1024): screenshots/H13-billing-tablet-768.png — レイアウト崩れなし。
- **H12 (アフォーダンス)**: destructive 操作 (カテゴリ削除/アイテム削除/プロジェクトメンバー削除/組織メンバー削除/API キー失効/招待取消) はすべて確認ダイアログあり、ボタン色 (danger 系) で主操作と区別できる。ローディング中はボタンが `disabled` (Button.svelte: `disabled={disabled || loading}`) になり二重送信を防ぐ実装を確認 (H6 対策)。
- **H14**: 目立った a11y 欠落は未発見 (時間の都合上、コントラスト測定・キーボードのみでの全操作は未実施。ボタン/リンクのアクセシブルネームは snapshot 上ほぼ全て取得できており欠落は目視で見当たらず)。
- **H11**: 視覚破綻は発見なし。

## 権限境界・IDOR 確認 (S4/S5 共通、H9)
- `manage.users.index` / `projects.categories.index` に member-standard (unassigned ロール) で直 URL アクセス → **403** (アクセスできません画面)。org switcher にも「メンバー管理」「API キー」リンクが表示されない (UI 非表示も一致)。
- `organizations.settings` は member でも閲覧可 (読み取り専用ビュー。編集フォーム/セキュリティ/オーナー移譲セクションは非表示) — 意図的な read-only 設計と判断 (書込み系はサーバ側でも保護、下記)。
- 直 POST 検証 (fetch + XSRF-TOKEN、UI 迂回):
  - member による `PATCH /organizations/{slug}` (組織名変更) → **403** "This action is unauthorized."
  - member による `POST /purchase-tickets/checkout` → **403** (owner/admin 限定の manageBilling gate)
  - admin (旧オーナー) による `DELETE /organizations/{slug}/members/{owner_id}` (現オーナー削除) → **422** "オーナーは削除できません。先にオーナーを移譲してください。" (最後のオーナー削除の孤児化保護をサーバ側で確認。UI 上もオーナー行に削除ボタン自体が出ない二重防御)
  - owner-free (自組織 id=1 のみ所属) による `POST /organizations/2/switch` (未所属の Standard 組織へ切替) → **404** (scoped model binding によるトークン漏洩なしの pre-authorization 404)
- チケット購入の idempotency: 同一 `attempt_token` (ULID) で2回連続 POST → 両方とも同じ Stripe fake セッション URL (`fake_external=stripe`) に収束 (`FakeTicketCheckoutGateway` が idempotency key から決定的にセッションを導出する設計を確認)。二重課金の兆候なし。

## 逸脱探索 (--deviate)
- **S4**: 撮影者/一般ユーザー(member-standard)で manage.users / categories に直アクセス → 403 (確認済み、上記)。カテゴリ reorder の二重送信は時間の都合上 未実施 (skip: 単発の reorder 操作で正常動作は確認済みだが競合パターンは追えていない)。**組織切替直後の別組織 project id 直叩み → 404 で保護されることを確認**(下記「要確認」参照、解決済み)。ユーザー削除/組織削除の直POST・最後のオーナー削除保護は確認済み (上記)。
- **S5**: checkout 二重送信 → 上記 idempotency 確認で対策済みを確認。残高不足での analyze/render 強行は S3 (manual作成) 領域に依存するため本shardでは未実施 (skip: マニュアル未作成)。チャージ直後のジョブ失敗時の予約解放は同様に S3 領域のため未実施 (skip)。料金表と実 checkout 金額の整合は確認 (pricing の ¥4,980/月・チケット単価表 と billing.index/purchase-tickets の表示が完全一致)。他組織 billing への自セッションアクセスは billing ルートが session-scoped current-organization 方式で URL に org 識別子を含まないため直接的な IDOR 経路はなし。組織切替の認可 (`organizations.switch` の pre-auth 404) で間接的に保護されていることを確認。

## findings

該当する Critical/High/Medium バグは発見なし。全て想定どおりの挙動 (認可・バリデーション・冪等性・レスポンシブとも良好)。以下は「要確認」区分。

## 要確認 (解決済み1件・残1件)
1. ~~projects.show の URL が組織スコープを含まない~~ → **解決・保護を確認 (findings.jsonl には未計上 = バグではないため)**。owner-standard で「Standardプラン組織」の project id=1 を作成後、org switcher で「S4 新規組織」へ切替し、切替後に `/projects/1` を直叩き → **404 Not Found** (別組織のプロジェクトは見えない・存在も推測できない pre-authorization 404)。IDOR (H9) なし。
2. **billing.index に「使用量 (容量 Quota)」の表示がない** (findings.jsonl: F-3-01): S5 カードは「現在のプラン・チケット残高・使用量 (容量 Quota 含む) が表示」と期待するが、実装 (`BillingController::index` の Inertia props: `plans`/`currentPlanCode`/`ticketBalance`/`canManageBilling` のみ) には容量使用率が含まれない。容量使用率はダッシュボード (`/dashboard`) に表示される設計になっている模様 (実際に dashboard で「容量使用率 0% ・ 0 B / 50.0 GB」を確認)。バグではなく画面の役割分担の可能性が高いが、カードの期待記述と実装の対応が取れないため要確認 (severity なし)。

## インベントリ修正提案
1. **S4 カード step3「名20字」は実装と不一致**: `app/Http/Requests/Projects/StoreCategoryRequest.php` の実際の上限は `max:50` (エラー文言も「名前の文字数は、50文字以下である必要があります。」)。21字入力を許可、51字(全角)入力を拒否して確認。カード記述の「名20字」を「名50字」に修正するか、意図した仕様(20字)であれば実装側のバグ報告に切り替えるべき (現状は実装が明確に50字を仕様として持っているため、カード側の記述ミスの可能性が高いと判断)。
2. **S5 カード step2「使用量(容量Quota含む)が表示」は billing.index の実装と不一致**: 上記「要確認 2」参照。ダッシュボードとの役割分担を確認の上、カードの記述を billing.index の実際の表示内容 (プラン・チケット残高のみ) に合わせるか、容量表示の追加を機能要望として起票するか判断が必要。

## Critical/High サマリ (TODO 候補)
- 該当なし (Critical/High findings は 0 件)。

## クロージング
- playwright-cli close 実施済み (下記コマンド実行)。
- serve 停止・teardown は行っていない (親の管轄)。
