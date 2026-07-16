## Round 4: Round 3 指摘への対応（3 Warning）

- [Warning] Architecture テスト保証範囲 → 「import かつ使用(`<PageContent` タグ出現、dead import 不可)」を
  保証すると明記。「本文ルート包含 / 幅の単独所有」は移行時の内側 max-w 除去 + 代表表示テスト + レビューで担保
  (完全 AST は過剰につき不採用)。soft check として top-level max-w 直書き残存の検出も可能なら加える、と明記。
- [Warning] Capture の "full-bleed" 用語 → **「max-width 非制約 allowlist」**に改称(AppLayout の padding は残る =
  真の画面端 full-bleed ではない)。
- [Warning] 対象境界の再未確定 → 概念設計で確定。Capture/Index を実構造確認し移行対象と確定。**max-width 非制約
  allowlist = Capture/Show の 1 枚のみ**。移行対象 = 24 − 1 = **23 枚**。詳細設計は照合と maxWidth 割当のみ。

以上で承認条件(保証範囲・用語・境界の一致)を満たしたと考えます。再評価をお願いします。

---

## 改訂後 概念設計（全文）

# 概念設計: t069-layout-followup（T069 サイドバー移行のレイアウト後退 2 件を修正）

> Round 1-2 レビュー反映済み（責務一本化 / 対象境界を AppLayout 全ページに確定 / Architecture テストで
> PageContent 利用を強制 / maxWidth 必須 union prop / テストファースト順序明記）。

## 背景・課題

bug-hunt run `20260716-201314`（証跡 `devnotes/20260716-201314-bug-hunt/shard-0/`）が、T069（ログイン後
レイアウトの左サイドバー移行）で入り込んだ 2 件のレイアウト後退を検出した。ユーザー方針は「UI は参照
アプリ aigenba に合わせる」。

**F-0-1（設定の二重掲載, Medium/H10）**: `AppLayout.svelte` の `navItems` に `{ href:"/settings",
label:"設定" }` が残り、下部 `SidebarUserMenu` の「個人設定」(→ `/settings`) と**同一導線が 2 箇所に
重複**。aigenba は左 nav に個人設定を置かず、設定はポップアップ専用。T069 の設計時翻訳ミスによる regression。

**F-0-2（コンテンツ幅の不統一, Medium/H11）**: T069 で旧 `AppLayout` の `max-w-6xl mx-auto`（本文全体の
中央寄せ）を撤去した結果、**認証ページの本文が左寄せ**になり、右側デッドスペースが不揃いに露出した
（実測 desktop1280: settings 右余白 320px / billing 224px / manage-users 32px）。監査の結果、AppLayout を
import する認証ページ **24 枚**のうち多数が `max-w-2xl`/`3xl`/`4xl` を `mx-auto` 無しで左寄せしていた。
ゲスト系（Pricing / Welcome / Contact）は `mx-auto` 中央寄せ済みで問題なし。

真因は「T069 でシェルの中央寄せを外したのに、各ページは中央寄せ前提（左寄せ narrow max-width）のまま
だった」こと。aigenba は共通 layout primitive `PageContent`（`mx-auto` 中央寄せ）を**全ページで使い**、
この種のドリフトを構造的に防いでいる。AI-CUE には該当 primitive も強制も無い。

## 改善アイデア

**F-0-1**: `navItems` から「設定」を除去し、個人設定は `SidebarUserMenu`（下部ポップアップ）のみに
一本化（aigenba 準拠）。`AppLayout.test` に「/settings は左サイドバー nav に出ない」負例を追加。

**F-0-2**: 幅制御 + 中央寄せの責務を単一 primitive `PageContent` に一本化し、**AppLayout を使う全ページで
PageContent 利用を Architecture テストで強制**する（aigenba 準拠 + ドリフト再発を構造的に封じる）。

### 責務の固定（二重中央寄せ / nested max-w を避ける）
- **`PageContent`（新規, templates layout primitive）が幅 + 中央寄せを一元所有**:
  `<div class="mx-auto max-w-{maxWidth}">`。narrow フォームは狭いまま**中央寄せ**になる。
- **`AppLayout` の `<main>` は padding のみ**（`px-4 py-6 lg:px-8`）。max-width / 中央寄せを持たない。
- 各認証ページは本文ルート（見出し含むページ全体）を `<PageContent maxWidth="…">` で包み、内側に残る
  重複 `max-w-*` は除去する（幅は PageContent が単独所有）。

### maxWidth は必須 union prop
`maxWidth: 'xl' | '2xl' | '3xl' | '4xl' | '5xl' | '6xl' | '7xl'`（**必須 prop・既定値なし**）。内部で
Record により対応 Tailwind class（`max-w-2xl` 等）へ引く。任意 class 直渡し禁止・指定漏れは型エラーで露見。

### 対象境界（概念設計で確定・詳細設計は照合のみ）
AppLayout を import するページは **24 枚**。境界を本概念設計で**確定**する（詳細設計では現行構造との照合と
maxWidth 割当のみ。差異発見時は本設計へ戻す）。
- **移行対象 = 23 枚**（`<PageContent maxWidth=…>` で本文ルートを包む）: Billing/{Index, PurchaseTickets},
  Settings/{Index, Security}, Projects/{Index, Show, Create, Edit}, Manuals/{Show, Create, Edit},
  Organizations/{ApiKeys/Index, ApiKeys/Sessions, Create, Settings, Onboarding/Cli, Onboarding/Mcp},
  Admin/{Users, Categories}, Notifications/Index, Invitations/Accept, Capture/Index（マニュアル選択リスト、
  wide grid なし = 通常ページ）, **Dashboard（maxWidth="7xl" = 全幅相当・中央寄せ）**。
- **max-width 非制約 allowlist = 1 枚**: `Capture/Show`（2 カラム grid の撮影レコーダー面。カメラ/カット一覧を
  ワイドに使うため PageContent の max-width 中央寄せを課さない）。※ AppLayout 共通の padding は残る（真の
  画面端 full-bleed ではなく「max-width 非制約」の意）。

### Architecture テストで強制（保証範囲を明示）
新設の Architecture テストが `resources/js/pages/**` を走査する。
- **保証する**: `AppLayout` を import するページ（max-width 非制約 allowlist を除く）は `PageContent` を
  **import かつ使用**する（テンプレートに `<PageContent` が出現。dead import は不可）。→ 移行漏れ検出 +
  新規ページの自動統一（PageContent 必須）が構造的に成立。allowlist は test に理由付きで列挙。
- **保証しない（別手段）**: 「本文ルートを包む」「幅を PageContent だけが所有」は、移行時の内側 `max-w-*`
  除去 + 代表ページの表示回帰テスト（中央寄せラッパ存在）+ コードレビューで担保（完全 AST 化は過剰につき不採用）。
  可能なら arch テストの soft check として、移行ページに `<PageContent>` 経由以外の top-level `max-w-` 直書きが
  残っていないことも検査する。

## 期待効果

- **UI 一貫性の回復（第1段, 確実）**: 認証ページの本文横幅制御が共通 primitive `PageContent` に統一され、
  左寄せ dead space の不揃いが解消。設定導線の二重化も解消。参照アプリ aigenba 水準の見た目統一
  （T069 の目的の完遂）。**成功条件 = AppLayout 全ページ(allowlist 除く)が PageContent を使い、右余白の
  不揃いが解消。Architecture テストが全件を構造保証**。
- **保守性（第1段）**: Architecture テスト強制により幅ドリフトを構造的に封じ、新規ページも PageContent
  必須で自動的に統一される。ds-purity / atomic-import-graph の既存不変条件に適合。

## 実装方針（概要・テストファースト順序）

1. **S1（F-0-1）**: (a) `AppLayout.test.ts` に「/settings が左 nav に出ない」負例を**先に追加し fail 確認** →
   (b) `AppLayout.svelte` の `navItems` から「設定」除去 → green。個人設定は `SidebarUserMenu` のみ（既存）。
2. **S2（F-0-2 primitive）**: (a) `PageContent` 単体テスト（children 描画 + `mx-auto` + maxWidth prop →
   対応 max-w class）を**先に書き fail** → (b) `templates/PageContent.svelte` 実装（DS-pure・layout utility
   のみ・maxWidth 必須 union→Record）→ green。あわせて `AppLayout` の `<main>` を padding 責務のみに確定。
3. **S3（F-0-2 移行 + 強制）**: (a) **Architecture テスト**（AppLayout ページは PageContent 利用 or
   allowlist）を**先に書き fail 確認**（未移行ページで落ちる）→ (b) 対象ページ群を `<PageContent
   maxWidth=…>` へ移行し内側重複 max-w を除去 → green。max-width 非制約 allowlist を test に明記。
4. 補助テスト: 幅バリエーションの異なる代表ページ（Settings=2xl / Billing=3xl / Manuals=4xl）の表示回帰。
   移行ページは既存テスト green を維持（testid / 振る舞い不変）。

## 制約・前提

- フロントは Svelte 5 runes + DS token/ramp のみ（DESIGN.md canonical, ds-purity テスト）。PageContent は
  layout utility のみで DS-pure、任意色・hex 直書きなし。maxWidth は union→Record で class 解決。
- component 階層は atoms→…→templates→pages の単方向 import（atomic-import-graph テストが強制）。
  PageContent は templates 層 layout primitive（pages から import 許可・他 primitive/page shell 依存禁止）。
- バックエンド変更なし（純フロント・レイアウトのみ）。認可・shared prop・ルートは不変。
- 既存の各ページ testid / 振る舞いは不変（中央寄せ化は表示のみ）。見出しもページ列に含め中央寄せ列に揃える。

## スコープ外

- ゲスト系ページ（Pricing / Welcome / Contact 等、GuestLayout/AuthLayout・既に mx-auto 中央寄せ）の変更。
- max-width 非制約 allowlist ページ（Capture 撮影レコーダー）の幅変更。
- ページ内コンテンツ自体の再設計（フォーム項目・情報設計は不変。幅の中央寄せ統一のみ）。
- サイドバー nav 項目の増減（F-0-1 の「設定」除去を除く）。
- aigenba の `PageContainer`（padding wrapper）の別途新設（AI-CUE は AppLayout `<main>` が padding を
  担う。責務は PageContent=幅/中央寄せ、AppLayout=padding に二分）。
