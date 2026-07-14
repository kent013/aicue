【アプリの使命（North Star）】

AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(migrate:fresh 等)をエージェント判断で実行すること
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory 経由のみ)
6. prompt 文字列のコード直書き(resources/prompts/*.yaml に置く)
7. 操作系 POST の応答での redirect()->intended()(ログイン直後フロー専用。招待送信等は back()->with(...) で完結)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【セキュリティ不変条件】cross-org 不可(組織を跨ぐ read/write をしない)。権限判定は常に laratrust_team_id を明示(strict_check=true)。tenant キー不信。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足コンテキスト（現行コードの要点）】
- `HandleInertiaRequests::currentOrganizationProp()` は現在 `{id, name, role}` を返し slug を含まない。`organizationsProp()` は `[{id, name, isPersonal}]` を返す。
- `organizations.switch` は `POST /organizations/{organization}/switch`。`{organization}` は id 解決 (MembershipScopedOrganizationBinder。非メンバー/不在は等しく 404 で存在秘匿)。
- `organizations.settings` / `organizations.api-keys.index` は `{organization:slug}` バインド (slug 必須)。`billing.index` は current-org スコープ (slug 不要)。メンバー管理は `/manage/users` (manage.users.index)。
- `OrganizationPolicy::manageMembers/manageBilling` は owner/admin (canManage)。`manageApiKeys` は owner/admin + `manage-api-keys` 直接付与メンバー。
- Atomic Design: `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import (テストで強制)。アイコンは @lucide/svelte のみ。DESIGN.md が design token の canonical。

---

## 概念設計

（以下、conceptual-design.md 全文）

# 概念設計: org-nav-switcher

## 背景・課題

bug-hunt findings F-C1 (Critical) / F-C2 (Critical) が、組織まわりの UI 導線欠落による「詰み」を報告している。

- F-C1: 組織設定 / API keys / 請求 / 料金 / メンバー招待への恒常ナビ導線が UI 上に一切無い。組織作成直後の一度きりリダイレクトでしか各画面に到達できず、以後戻る手段がない。さらに共有 props currentOrganization に slug が欠落しているため、フロントで organizations.settings / organizations.api-keys.index(いずれも {organization:slug} バインド)へのリンクを自力生成することもできない。結果として S2 招待フロー (organizations.invitations.store/revoke, members.update/destroy 等) が全て到達不能。
- F-C2: 組織スイッチャー UI が全く無い。新規組織作成で current_organization_id が自動的に切り替わる (H2) と、UI から元の組織に戻る手段がなく詰む。organizations.switch (POST /organizations/{organization}/switch) は実装済みだが、それを呼ぶ UI が存在しない。

### 根本原因
- resources/js/components/templates/AppLayout.svelte がサイドバー / 組織メニュー / 組織切替を「Phase 2」コメントのまま未実装。ヘッダーは通知ベル・設定・ログアウトのみ。
- app/Http/Middleware/HandleInertiaRequests.php の currentOrganizationProp() が {id, name, role} のみを共有し slug を含めていない。

## 改善アイデア

AppLayout ヘッダーに、SaaS 標準の組織スイッチャー兼組織メニューを 1 コンポーネントとして常設する。トリガーは現在の組織名を表示するボタン、展開パネルに以下を含める:
1. 組織切替セクション — 共有 prop organizations を列挙し、各項目を POST /organizations/{id}/switch (既存 organizations.switch) にポストする。現在の組織にはチェック表示。所属組織が 1 個のときは切替セクションを出さない。
2. 組織管理リンクセクション — 権限に応じて表示: 組織設定 (organizations.settings, slug 必要, メンバー全員) / メンバー管理・招待 (manage.users.index, owner/admin のみ) / API キー (organizations.api-keys.index, slug 必要, 管理権限保持者のみ) / 請求 (billing.index, メンバー全員) / 料金 (/pricing 公開, 常時)。
3. フォールバック — currentOrganization が null のとき「組織を作成」(organizations.create) を出し詰み脱出口を保証。

これを支えるため HandleInertiaRequests の currentOrganization 共有に slug と、リンク出し分け用の権限フラグ (canManageMembers / canManageApiKeys / canManageBilling) を追加する。権限フラグは OrganizationPolicy を Gate 経由で参照し認可ロジックの二重管理を避ける。

## 期待効果
- 使命への貢献: 現場作業者が組織(現場)をまたいでマニュアルを運用する導線を回復。組織切替・メンバー招待・請求管理という運用の背骨が UI から到達可能になり「思考ゼロ」で使える前提を満たす。
- 詰みの解消: F-C2 の H2 を恒常スイッチャーで解消。F-C1 の到達不能を恒常メニューで解消し S2 招待フローを開通。
- 回帰防止: slug 欠落を型 (CurrentOrganization) と Feature テストで塞ぐ。

## 実装方針（概要）
1. backend: currentOrganizationProp() に slug と権限フラグ 3 種を追加。role は既存。OrganizationPolicy::manageMembers/manageApiKeys/manageBilling を Gate で評価。
2. 型: shared-props.ts の CurrentOrganization に slug: string と canManageMembers/canManageApiKeys/canManageBilling: boolean を追加。
3. frontend: components/features/organizations/OrganizationSwitcher.svelte を新設 (状態を持つ organism 級 = features/{domain} 配置)。開閉・click-outside・Escape・フォーカス管理を内包。アイコンは @lucide/svelte のみ。色/radius/typography は DS token のみ。
4. AppLayout: showAccountNav 時にヘッダー左側 (通知ベルの前) へ OrganizationSwitcher を配置。「Phase 2」コメントを実装済みに置換。
5. テスト: (a) PHP Feature — 共有 prop に slug + 権限フラグが role 別に正しく載ることを検証。(b) JS component — 現在組織表示 / 複数所属時の切替リスト / switch POST / 権限リンク出し分け / null 組織時の作成導線。

新規ルート・新規コントローラは不要。組織一覧専用 GET 画面は今回作らない (ドロップダウンで完結)。

## 制約・前提
- organizations.switch は id 解決。切替は organizations[].id を使い /organizations/{id}/switch に POST (slug 不要)。
- organizations.settings / api-keys.index は slug 必須 = currentOrganization に slug を足す動機。
- 非メンバー org は binder が 404 で存在秘匿。切替リストは id のみで足り cross-org slug 露出を作らない。
- リンクは権限フラグで出し分けるが最終認可は各エンドポイント Policy が担保 (フラグは UX 先出し)。押下時 403 を避ける非表示であり「必須未充足で disabled」ではない (禁止事項 8 非抵触)。
- Atomic Design 単方向: templates(AppLayout) ← features/organizations は許可方向。

## スコープ外
- サイドバー全面刷新 / Team・Project 階層ナビ。組織一覧専用ページ。組織作成・招待フロー本体の改修。通知ベルのドロップダウン化。権限フラグに基づく各画面内 UI のガード。
