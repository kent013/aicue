# aigenba レイアウト完全一致 監査レポート (20260716-2204)

参照: aigenba (`/tmp/aigenba`) を「正」とし、AI-CUE の全認証ページ・レイアウト primitive を突き合わせた
並列監査 (9 スライス / 69 findings / Workflow 並列)。生データ: `findings.json`。

## 結論(サマリ)

AI-CUE のログイン後レイアウトは、T069/T070 で私が**独自実装を重ねた**結果、aigenba と構造レベルで乖離している。
核心は「**aigenba の統一外枠 `AppLayout > PageContainer > PageHeader(Section) > PageContent(固定 max-w-7xl)`
を AI-CUE が持たず、各ページが独自の外枠・独自 narrow 幅・独自見出し・独自二次メニューで組まれている**」こと。

**重要**: aigenba にあって AI-CUE に無いものは**すべて aigenba から移植可能な primitive/コンポーネント**であり、
「AI-CUE に外部依存として存在しない=実装不能」な真のブロッカーは**無い**(下記 §5 参照)。よって完全一致は
「移植 + 全ページ外枠移行 + 独自要素の撤去」という、大きいが定義の明確な作業。

## 1. 欠落 primitive(aigenba にあり AI-CUE に無い → 移植する)

| primitive | aigenba の役割 | AI-CUE | 対応 |
|---|---|---|---|
| `templates/PageContainer.svelte` | 外枠 padding wrapper (`px-4 py-8 sm:px-6 lg:px-8`) | 無し(padding を AppLayout `<main>` に直付け) | 移植 + AppLayout padding を移譲 |
| `molecules/PageHeader.svelte` | root 見出し shorthand (title/description/icon/testId) | 無し(各ページ生 `<h1>` 直書き) | 移植 |
| `molecules/PageHeaderSection.svelte` | breadcrumbs/actions 付き全幅見出しバー(負マージン契約) | 無し | 移植 |
| `molecules/Breadcrumb.svelte` + `BreadcrumbItem` 型 | PageHeaderSection のパンくず | 無し | 移植(PageHeaderSection の前提) |
| `molecules/BrandLogo.svelte` | インライン SVG ロゴ(sm/md, withName) | 無し(`{appName}` テキスト直書き ×3) | 移植 + AppLayout/Guest/Auth の text を置換 |
| `molecules/AuthHeroSection.svelte` | 認証ページ共通 hero カード | 無し | 移植(Invitations/Accept 等。ゲスト/認証面と調整) |
| `molecules/OrgCard.svelte` / `Tooltip.svelte` | 組織カード枠 / 汎用 tooltip | 無し | 低優先(該当ページ実装時に移植) |

## 2. 独自化した primitive(aigenba と思想が逆 → aigenba に戻す)

- **`PageContent.svelte`**: aigenba は **prop 無し・`mx-auto max-w-7xl` 固定**。AI-CUE は T070 で私が
  **独自の必須 `maxWidth` union prop(sm..7xl)+ testId** を新設し「標準幅 2xl(narrow)」を規定 = aigenba の
  「全ページ一律 max-w-7xl」と真逆。→ **maxWidth/testId prop を撤去し max-w-7xl 固定に戻す**(全ページの
  narrow 幅指定を廃止)。※ これは T070 の私の設計判断ミスの是正。

## 3. shell / 外枠パターンの不一致(全 24 認証ページ)

- aigenba: 全ページ `AppLayout > PageContainer > PageHeader/PageHeaderSection > PageContent`。
- AI-CUE: `AppLayout > PageContent(独自 maxWidth) > 生 <h1> + 本文`(PageContainer/PageHeader 欠落)。
- **padding 責務**: aigenba は `<main>` に padding を持たせず PageContainer(`px-4 py-8 sm:px-6 lg:px-8`)が担う。
  AI-CUE は `<main>` 内 div に `px-4 py-6 lg:px-8` 直付け(値も差: py-6 vs py-8、sm:px-6 欠落)。
  **PageHeaderSection の全幅バーは PageContainer padding 前提の負マージン契約(`-mt-8 -mx-4 sm:-mx-6 lg:-mx-8`)**
  のため、padding を PageContainer に移さないと PageHeader を移植しても端が揃わない(= 移行順序の制約)。
- **幅**: 全ページ narrow(2xl/3xl/4xl/md)→ 一律 max-w-7xl 中央寄せへ(§2 の PageContent 是正で自動的に統一)。

## 4. 独自要素・nav 構造の不一致

- **`AdminMenuNav`(独自二次左メニュー)**: `Admin/Users` `Admin/Categories` が本文左に `<aside md:w-56>` +
  Card「管理メニュー」(ユーザー管理/カテゴリ管理の縦 nav)を持つ 2 カラム構成。aigenba にこの種の二次
  `<aside>` サブナビは**一切無い**(管理系は左サイドバー top-level nav 項目)。
  → **AdminMenuNav を撤去**し、ユーザー管理/カテゴリ管理を**左サイドバー nav の top-level 項目**に。
  → 現状カテゴリ管理は AdminMenuNav が唯一の入口(nav 項目が無い)ため、nav 項目化が必須(§nav-structure)。
- **Onboarding/Cli・Mcp**: aigenba は `features/onboarding/OnboardingGuide.svelte`(props/型込み)で構成。
  AI-CUE は inline で `<h1>+Card+CodeSnippet` を並べる独自実装。→ OnboardingGuide を移植 or 外枠のみ揃える。
- **層分類の乖離**(低優先): `Modal`(aigenba=molecules / AI-CUE=organisms)、`CodeSnippet`(aigenba=atoms /
  AI-CUE=molecules)。aigenba の層分類に寄せるか要検討。
- **AI-CUE 独自 molecule**(aigenba に無い): FormField/Tabs/Divider/DangerZone/Pagination/NotificationBell。
  用途を精査(機能上必要なものは残す。純粋な独自 UI は aigenba パターンに置換可能か検討)。

## 5. 実装を阻む真のブロッカー(TODO 化対象)

- 監査の結論: **外部依存として存在せず実装不能な機能は無い**。上記 §1 の欠落 primitive・§4 の OnboardingGuide は
  **すべて aigenba のコードを移植すれば実装可能**。したがって「aigenba にあって AI-CUE に無く、それが無いと
  実装できないため別途作る必要がある新規機能」は**該当なし**。
- **Capture(撮影 PWA)**: aigenba に対応機能が無い唯一の面。ただし外枠(PageContainer/PageHeader/PageContent)は
  他ページ規約をそのまま適用可能。`Capture/Show` は 2 カラム grid のワイド撮影面のため **max-width 非制約**
  (PageContent で幅を絞らない)扱いが妥当。機能本体は AI-CUE 固有として維持(レイアウト外枠のみ aigenba 準拠)。

## 6. 実装計画(提案・フェーズ分割)

大きいが密結合のため、以下の順序(padding 移行の制約に従う)で 1 つの parity 作業として進めるのが妥当:

1. **P1 primitive 移植**: PageContainer / PageHeader / PageHeaderSection / Breadcrumb(+型)/ BrandLogo を
   aigenba から移植。`PageContent` を aigenba 形(prop 無し max-w-7xl)に是正(T070 の独自 prop 撤去)。
2. **P2 shell 是正**: AppLayout `<main>` の padding を撤去 → PageContainer に移譲(値 py-8 sm:px-6)。
   `{appName}` テキストを BrandLogo に置換。
3. **P3 全ページ外枠移行**: 24 認証ページを `AppLayout > PageContainer > PageHeader(Section) > PageContent`
   に統一(生 `<h1>` を PageHeader へ、narrow 幅を廃止)。Workflow 並列で実施。
4. **P4 nav 是正**: AdminMenuNav 撤去 + ユーザー管理/カテゴリ管理を左サイドバー top-level nav 項目化。
5. **P5 個別**: Onboarding を OnboardingGuide 相当へ、Capture 外枠適用(Show は max-width 非制約)。
6. **回帰**: 各ページ既存テスト green 維持 + Architecture テスト(PageContainer/PageHeader 利用の構造保証)を
   aigenba パターンに合わせて更新。bug-hunt も「aigenba 参照の視覚比較」を組み込む形に作り直す(§今後)。

## 今後: bug-hunt が「aigenba 差分」を検出できなかった件

現行 bug-hunt は自作 `screens.md` 仕様と突き合わせるだけで aigenba を参照せず、UX 破綻ヒューリスティクスは
`AdminMenuNav` のような「AI-CUE の doc 上は正当だが aigenba と違う」要素を fire しない。→ 完全一致を保つには
**bug-hunt に「aigenba を基準にした構造/視覚比較」レーンを追加**する必要がある(P6 として別途)。
