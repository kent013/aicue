## Round 2: Round 1 指摘への対応

Round 1 の全 Warning に対応し概念設計を改訂しました。再評価をお願いします。

- [Warning] 責務二重化 → **PageContent(新規 primitive)が幅+中央寄せを一元所有**、AppLayout の <main> は
  padding のみ(max-width を持たせない)に固定。nested max-w を排除。各認証ページが <PageContent maxWidth=…>
  で本文ルートを包む(aigenba のページ単位 primitive 方式)。narrow フォーム幅は尊重(max-w-7xl 機械移植は回避)。
- [Warning] 対象範囲未確定 → 監査済み AppLayout 配下・左寄せ narrow ページ **全 17 枚を列挙・一括対象**に確定
  (段階導入せずドリフト残件を作らない)。Dashboard の扱いは詳細設計で確定。
- [Warning] テスト弱い → PageContent 単体テスト + **幅バリエーション異なる代表 3 ページ**(Settings 2xl /
  Billing 3xl / Manuals 4xl)で「本文ルートが PageContent(中央寄せラッパ)を使う」回帰テスト + AppLayout 負例。
- [Suggestion] 成功条件 → 「認証ページの幅制御が PageContent に統一 + 右余白不揃い解消」に引き上げ。
- [Suggestion] maxWidth union prop → `'xl'|'2xl'|…|'7xl'`(既定 '2xl')を Record で class 解決(任意 class 拡散防止)。

---

## 改訂後 概念設計（全文）

# 概念設計: t069-layout-followup（T069 サイドバー移行のレイアウト後退 2 件を修正）

> Round 1 レビュー反映済み（責務の一本化 / 対象 inventory 確定 / 回帰テスト強化 / maxWidth union prop）。

## 背景・課題

bug-hunt run `20260716-201314`（証跡 `devnotes/20260716-201314-bug-hunt/shard-0/`）が、T069（ログイン後
レイアウトの左サイドバー移行）で入り込んだ 2 件のレイアウト後退を検出した。ユーザー方針は「UI は参照
アプリ aigenba に合わせる」。

**F-0-1（設定の二重掲載, Medium/H10）**: `AppLayout.svelte` の `navItems` に `{ href:"/settings",
label:"設定" }` が残り、下部 `SidebarUserMenu` の「個人設定」(→ `/settings`) と**同一導線が 2 箇所に
重複**。aigenba は左 nav に個人設定を置かず、設定はポップアップ専用。T069 の設計時翻訳ミスによる regression。

**F-0-2（コンテンツ幅の不統一, Medium/H11）**: T069 で旧 `AppLayout` の `max-w-6xl mx-auto`（本文全体の
中央寄せ）を撤去した結果、**認証ページの本文が左寄せ**になり、右側デッドスペースが不揃いに露出した
（実測 desktop1280: settings 右余白 320px / billing 224px / manage-users 32px）。監査の結果、**AppLayout
配下の認証ページ 17 枚が `max-w-2xl`/`max-w-3xl`/`max-w-4xl` を `mx-auto` 無しで左寄せ**していた。
ゲスト系（Pricing / Welcome / Contact）は `mx-auto` 中央寄せ済みで問題なし。

真因は「T069 でシェルの中央寄せを外したのに、各ページは中央寄せ前提（左寄せ narrow max-width）のまま
だった」こと。aigenba は共通 layout primitive `PageContent`（`mx-auto` 中央寄せ）+ `PageContainer`
（padding）を全ページで使い、この種のドリフトを構造的に防いでいる。AI-CUE には該当 primitive が無い。

## 改善アイデア

**F-0-1**: `navItems` から「設定」を除去し、個人設定は `SidebarUserMenu`（下部ポップアップ）のみに
一本化（aigenba 準拠）。`AppLayout.test` に「/settings は左サイドバー nav に出ない」負例を追加。

**F-0-2**: aigenba のページ単位 primitive 方式に倣い、**幅制御 + 中央寄せの責務を単一 primitive
`PageContent` に一本化**する。

### 責務の固定（Round 1 [Warning] 対応 — 二重中央寄せ / nested max-w を避ける）
- **`PageContent`（新規, templates layout primitive）が幅 + 中央寄せを一元所有**:
  `<div class="mx-auto max-w-{maxWidth}">`。narrow フォームは狭いまま**中央寄せ**になる。
- **`AppLayout` の `<main>` は padding のみ**（`px-4 py-6 lg:px-8`）を担い、**max-width / 中央寄せを
  持たない**。→ 幅の責務が PageContent に集約され、shell とページの二重 max-width が起きない。
- 各認証ページは本文ルートを `<PageContent maxWidth="…">` で包む。aigenba のように page 側が幅を宣言する
  ため、AI-CUE の意図した narrow フォーム幅（2xl 等）を尊重しつつ中央寄せに統一できる（aigenba の
  max-w-7xl を機械移植して全フォームをワイド化する、という誤移植は避ける）。

### maxWidth は union prop（Round 1 [Suggestion] 対応）
`maxWidth: 'xl' | '2xl' | '3xl' | '4xl' | '5xl' | '6xl' | '7xl'`（既定 '2xl'）。内部で Record により
対応 Tailwind class（`max-w-2xl` 等）へ引く。任意 class 直渡しを禁じ ds-purity / 型安全に適合。

### 対象 inventory（Round 1 [Warning] 対応 — 全 17 枚を確定・一括）
監査で左寄せ narrow max-width と判明した AppLayout 配下の認証ページ **17 枚**を対象とし、各本文ルートを
`<PageContent maxWidth={現行踏襲}>` に寄せる（段階導入せず一括）:

Organizations/ApiKeys/Index, Organizations/ApiKeys/Sessions, Organizations/Create,
Organizations/Settings, Organizations/Onboarding/Cli, Organizations/Onboarding/Mcp,
Settings/Index, Settings/Security, Projects/Index, Projects/Show, Projects/Create, Projects/Edit,
Manuals/Show, Manuals/Create, Manuals/Edit, Billing/Index, Billing/PurchaseTickets。

Dashboard（`max-w` 無し = 現状全幅、左寄せ dead space なし）は詳細設計で判断: 幅統一のため
`<PageContent maxWidth="7xl">`（全幅相当・中央寄せ）で包み全認証ページを PageContent 経由に揃えるか、
全幅のまま残すか。方針は「全認証ページの幅制御を PageContent に統一」を優先し詳細設計で確定する。

## 期待効果

- **UI 一貫性の回復（第1段, 確実）**: 認証ページの本文横幅制御が共通 primitive `PageContent` に統一され、
  左寄せ dead space の不揃いが解消。設定導線の二重化も解消。参照アプリ aigenba 水準の見た目統一
  （T069 の目的の完遂）。**成功条件 = 認証ページの幅制御が PageContent に統一され、右余白の不揃いが解消**。
- **保守性（第1段）**: 共通 primitive によりコンテンツ幅ドリフトを構造的に防ぎ、今後の新規ページも
  PageContent 経由で自動的に統一される。ds-purity / atomic-import-graph の既存不変条件に適合。

## 実装方針（概要）

1. **S1（F-0-1）**: `AppLayout.svelte` の `navItems` から「設定」除去。`AppLayout.test.ts` に「/settings が
   左 nav に出ない」負例追加。
2. **S2（F-0-2 primitive）**: `templates/PageContent.svelte` 新規（`mx-auto max-w-{union}` 中央寄せ、
   DS-pure・layout utility のみ・単方向 import）。`AppLayout` の `<main>` は padding 責務のみ（max-width
   を持たせない）に確定。
3. **S3（F-0-2 移行）**: 対象 17 枚（+Dashboard）の本文ルートを `<PageContent maxWidth=…>` に寄せる
   （現行の max-width 値を踏襲、narrow フォームは維持しつつ中央寄せ）。
4. **テスト**:
   - `PageContent` 単体テスト（children 描画 + `mx-auto` + maxWidth prop → 対応 max-w class）。
   - 幅バリエーションの異なる代表認証ページ（Settings=2xl / Billing=3xl / Manuals=4xl）で「本文ルートが
     `PageContent`（中央寄せラッパ）を使う」回帰テスト。
   - `AppLayout.test.ts`（S1 負例）。移行ページは既存テスト green を回帰で維持（testid / 振る舞い不変）。

## 制約・前提

- フロントは Svelte 5 runes + DS token/ramp のみ（DESIGN.md canonical, ds-purity テスト）。PageContent は
  layout utility のみで DS-pure、任意色・hex 直書きなし。maxWidth は union→Record で class 解決。
- component 階層は atoms→…→templates→pages の単方向 import（atomic-import-graph テストが強制）。
  PageContent は templates 層 layout primitive（pages から import 許可・他 primitive/page shell への依存禁止）。
- バックエンド変更なし（純フロント・レイアウトのみ）。認可・shared prop・ルートは不変。
- 既存の各ページ testid / 振る舞いは不変（中央寄せ化は表示のみ）。

## スコープ外

- ゲスト系ページ（Pricing / Welcome / Contact 等、既に mx-auto 中央寄せ）の変更。
- ページ内コンテンツ自体の再設計（フォーム項目・情報設計は不変。幅の中央寄せ統一のみ）。
- サイドバー nav 項目の増減（F-0-1 の「設定」除去を除く）。
- T069 で確立したサイドバー構造そのものの変更。
- aigenba の `PageContainer`（padding wrapper）の別途新設（AI-CUE は AppLayout の `<main>` が padding を
  担うため、padding primitive は導入しない。責務は PageContent=幅/中央寄せ、AppLayout=padding に二分）。
