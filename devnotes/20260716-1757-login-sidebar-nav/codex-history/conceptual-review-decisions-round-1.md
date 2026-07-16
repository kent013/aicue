# 対応マトリクス: conceptual-review Round 1

## [Critical] 3. org スコープのリンク出し分けが currentOrganization + canManageApiKeys だけでは不十分（403/404 導線リスク）
- 判断: 対応する（ただし「専用 NavigationItemDTO をサーバで解決」提案の一部は反論）
- 根拠: 出し分けをサーバ真実に寄せる指摘は正当。ただし参照アプリ aigenba は
  `canViewCurrentBilling` / `canManageCurrentApiKeys` / `canManageCurrentMembers` /
  `canCreateOrganization` の **capability 真偽 shared prop** をサーバから渡し、href は
  `currentOrganization.slug` からクライアント合成する方式で本番運用している。ユーザー方針
  「基本コピー・参照側に寄せる」に照らし、bespoke な `NavigationItemDTO`（href/visible を
  サーバ完全解決）まで作るのは過剰かつ参照から乖離する。
- 対応内容: aigenba と同一の capability 真偽 shared prop 群を `currentOrganization` に
  型付き boolean として追加（`canViewBilling` 等）。org-scoped 項目は
  `currentOrganization != null && <capability>` の二重条件でのみ描画（aigenba の
  `hasManagementRole && currentOrganization()` ゲートに相当）。href は slug から組むが、
  slug が無い（org 未設定）ときは org-scoped 項目自体を出さないため null 連結は起きない。
  リンク先はサーバ policy と一致（サイドバーから 403 に到達させない）ことを詳細設計の各項目で明記。

## [Warning] 1. 使命整合の誇大（manual/capture 導線改善は nav 定義だけでは未立証）
- 判断: 対応する
- 対応内容: 期待効果を二段に分離。第1段=UI 一貫性・保守性・DRY（確実）、第2段=業務導線改善
  （Projects 起点の到達短縮。計測前提の仮説として記載、誇大表現を撤回）。

## [Warning] 2. shared prop 追加のサーバ側テストが未明記
- 判断: 対応する
- 対応内容: テスト計画に Feature テストを追加。「未認証では nav shared prop（capability）が
  出ない/false」「権限なしでは Billing/API キー/組織設定系 capability が false」を検証。

## [Warning] 4. 期待効果の妥当性（保守性は妥当だが導線改善は未立証）
- 判断: 対応する（[Warning]1 と同一対応）

## [Warning] 5-a. NotificationBell と 通知 nav の二重化
- 判断: 対応する
- 対応内容: 通知導線は **NotificationBell を単一の主導線**とし、`/notifications` の nav 項目は
  設けない（未読バッジはベルが担う）。nav 項目候補から「通知」を削除。

## [Warning] 5-b. AppLayout 全面置換の横断回帰
- 判断: 対応する
- 対応内容: 回帰観点を設計に列挙（desktop 展開/折りたたみ、mobile drawer、banner/toast の
  積み上がり、org 切替、logout POST、未認証時 nav 非表示、popover/drawer の focus/キーボード）。

## [Warning] 6. スコープの二層構造が未分離（UI 置換 + 認可契約整理）
- 判断: 対応する
- 対応内容: スコープを (A) UI shell 置換 と (B) navigation 共有プロップ契約（capability 追加）に
  分けて明記。

## [Warning] 7. 型安全性（currentOrganization への場当たり追加は shape が崩れやすい）
- 判断: 対応する（typed contract 化。ただし DTO ではなく厳密 boolean shape）
- 対応内容: 追加 prop は `CurrentOrganization` interface に**明示的な boolean フィールド**として
  型定義（`shared-props.ts`）。サーバ `currentOrganizationProp` の array shape と TS を 1:1 対応
  させ、PHPStan array shape docblock と同期。場当たりな連想配列拡張はしない。

## [Suggestion] 群
- headerActions 廃止の意図明示 → 反映（後方互換並走を残さない規約に合致）
- 成功条件の具体化（タップ数/迷い減少）→ 第2段効果の計測観点として記載
- desktop/mobile 重複描画の helper 集約効果 → そのまま主張
- Quota/BrandLogo/help 外部リンクの scope 外 → 維持

## 追加の自発的判断（Codex 未指摘だが参照方針に必要）
- AI-CUE 独自 `OrganizationSwitcher.svelte`（組織切替 + 設定/メンバー/API キー/請求/料金の
  リンク束を内包）は、aigenba では「org nav 項目＝サイドバー nav」「org 切替＝下部メニュー」に
  分離されている。ユーザー方針「独自版は削除して参照側へ寄せる」に従い、`OrganizationSwitcher`
  は退役し、org 切替は下部メニュー（aigenba 相当のインライン switcher）に、org 設定/メンバー/
  API キー/請求はサイドバー nav に移す。詳細設計で `OrganizationSwitcher` の他利用箇所の
  波及（他ページからの参照有無）を確認する。
