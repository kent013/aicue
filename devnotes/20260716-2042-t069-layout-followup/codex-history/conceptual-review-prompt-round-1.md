## アプリの使命（North Star）
AI-CUE は、現場の作業手順書(SOP)を起点に AI が撮るべきカットを設計した動画シナリオを生成し、
スマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル
動画を作れるようにする（思考ゼロ・編集ゼロ）。

## 禁止事項（核）
1. PHPStan エラーの無視(ignore/baseline/widen) 2. テストなしの実装完了 3. 既存テストの削除
4. response()->json() 直書き 6. やたらに複雑な案(オーバーエンジニアリング)

## 思考原則・ツール使用制限
先人の知恵(Laravel/Svelte 既存解)を使え。機能の名前に立ち返れ。今必要なものだけ作れ。
コマンド実行・ファイル書き込みは行わず、提供テキストの分析に集中(読み込みは可)。

---
あなたは Web アプリ(Laravel + Svelte)改善の概念設計レビュアーです。
【レビュー観点】1.使命整合 2.禁止事項 3.実現可能性(Laravel12+Svelte5+Inertia) 4.期待効果の妥当性
5.リスク(後退) 6.スコープ適切さ(過大/過小) 7.型安全性

補足コンテキスト:
- 本件は bug-hunt run 20260716-201314 が検出した T069(左サイドバー移行)のレイアウト後退 2 件の修正。
- 参照アプリ aigenba(同 template・同 DS)は templates/PageContent.svelte(mx-auto max-w-7xl 中央寄せ)+
  PageContainer.svelte(padding wrapper)を全ページで使い幅を統一。AI-CUE には該当 primitive が無い。
- AI-CUE 監査: AppLayout 配下の認証ページ ~17 枚が max-w-2xl/3xl を mx-auto 無しで左寄せ。ゲスト系は
  mx-auto 済み。旧 AppLayout は max-w-6xl mx-auto で全体中央寄せだったため左寄せが目立たなかった。
- frontend 規約: Svelte5 runes + DS token のみ(ds-purity テスト)、単方向 import(atomic-import-graph)。

【出力形式】全体判定 APPROVED/CHANGES_REQUESTED。各観点 [Critical]/[Warning]/[Suggestion] 分類。
Critical/Warning に修正提案必須。日本語。

---

## 概念設計

# 概念設計: t069-layout-followup（T069 サイドバー移行のレイアウト後退 2 件を修正）

## 背景・課題

bug-hunt run `20260716-201314`（証跡 `devnotes/20260716-201314-bug-hunt/shard-0/`）が、T069（ログイン後
レイアウトの左サイドバー移行）で入り込んだ 2 件のレイアウト後退を検出した。ユーザー方針は「UI は参照
アプリ aigenba に合わせる」。

**F-0-1（設定の二重掲載, Medium/H10）**: `AppLayout.svelte` の `navItems` に `{ href:"/settings",
label:"設定" }` が残り、下部 `SidebarUserMenu` の「個人設定」(→ `/settings`) と**同一導線が 2 箇所に
重複**。aigenba は左 nav に個人設定を置かず、設定はポップアップ専用。T069 の設計時翻訳ミスによる regression。

**F-0-2（コンテンツ幅の不統一, Medium/H11）**: T069 で旧 `AppLayout` の `max-w-6xl mx-auto`（本文全体の
中央寄せ）を撤去した結果、**認証ページの本文が左寄せ**になり、ページごとの内側 max-width の差がそのまま
右側デッドスペースの不揃いとして露出した（実測 desktop1280: settings 右余白 320px / billing 224px /
manage-users 32px）。**監査の結果、AppLayout 配下の認証ページ ~17 枚が `max-w-2xl`/`max-w-3xl` を
`mx-auto` 無しで左寄せ**している（ApiKeys / Settings / Projects / Manuals / Billing / Onboarding /
Organizations 等）。ゲスト系（Pricing / Welcome / Contact）は `mx-auto` 中央寄せ済みで問題なし。

真因は「T069 でシェルの中央寄せを外したのに、各ページは中央寄せ前提（左寄せ narrow max-width）のまま
だった」こと。aigenba は共通 layout primitive `templates/PageContent.svelte`（`<div class="mx-auto
max-w-7xl">` = 中央寄せ+一定 max-width）と `templates/PageContainer.svelte`（padding wrapper）を全ページで
使い、この種のドリフトを構造的に防いでいる。AI-CUE には該当プリミティブが無い。

## 改善アイデア

**F-0-1**: `navItems` から「設定」を除去し、個人設定は `SidebarUserMenu`（下部ポップアップ）のみに
一本化する（aigenba 準拠）。`AppLayout.test` に「/settings は左サイドバー nav に出ない」負例を追加。

**F-0-2**: aigenba 準拠で**コンテンツ幅を中央寄せに統一**する。ドリフト再発を構造的に防ぐため、
aigenba の `PageContent`（`mx-auto max-w-*` 中央寄せ layout primitive）相当を導入し、認証シェルの
コンテンツを中央寄せ化する。**実現の要点（接地）**:
- シェル側だけを `mx-auto max-w-7xl` にしても、ページ内の `max-w-2xl` 本文は依然その中で左寄せに
  なる（＝ページ側の中央寄せが必要）。よって「シェルに中央寄せ枠を置く」だけでは不十分で、**認証
  ページの本文コンテナを中央寄せに揃える**ことが本質的な修正になる。
- 選択肢（詳細設計・Codex 合議で確定）:
  - **案A（primitive 導入 + 移行）**: `PageContent`（`mx-auto max-w-*`）を templates 直下に新設し、
    AppLayout の `<main>` ラッパへ適用 + 各認証ページの本文ルートを primitive 経由に寄せる。将来ドリフト
    を構造的に防ぐ（aigenba と同型）。移行対象は監査した ~17 ページ（機械的）。
  - **案B（最小: 中央寄せの付与）**: 各認証ページの既存 max-width コンテナに `mx-auto` を付与して
    中央寄せするだけ（新規 primitive なし）。差分は小さいが将来の再発防止は弱い。
- いずれも「narrow な readable フォーム幅（max-w-2xl 等）」は尊重し（＝過度にワイド化しない）、
  **左寄せ→中央寄せ**にすることで観察された不揃い右余白を解消する。

## 期待効果

- **UI 一貫性の回復（第1段, 確実）**: 認証ページ間で本文が一貫して中央寄せになり、右側デッドスペースの
  不揃いが解消。参照アプリ aigenba 水準の見た目統一（T069 の目的の完遂）。設定導線の二重化も解消し、
  「設定はどこか」のメンタルモデルが一貫する。
- **保守性（第1段）**: （案A採用時）共通 layout primitive によりコンテンツ幅ドリフトを構造的に防ぎ、
  今後の新規ページも自動的に統一幅に載る。ds-purity / atomic-import-graph の既存不変条件に適合。

## 実装方針（概要）

1. **S1（F-0-1）**: `AppLayout.svelte` の `navItems` から「設定」項目を除去。`AppLayout.test.ts` に
   「/settings が左 nav に出ない」負例を追加。個人設定は `SidebarUserMenu` のみ（既存）。
2. **S2（F-0-2 シェル）**: aigenba 準拠の中央寄せ layout primitive（`PageContent` 相当, `mx-auto max-w-*`）
   を導入し、`AppLayout` の `<main>` コンテンツラッパへ適用（+ 必要なら `PageContainer` 相当の padding
   wrapper）。DS-pure（layout/padding utility のみ・任意色なし）・単方向 import（templates 層 primitive）。
3. **S3（F-0-2 ページ）**: 監査で左寄せ narrow max-width と判明した**認証ページの本文ルートを中央寄せに
   統一**（案A: primitive 経由 / 案B: mx-auto 付与）。ゲスト系（既に中央寄せ）は対象外。
4. テスト: `AppLayout.test.ts` 更新（S1 負例 + シェルの中央寄せ構造）。primitive 導入時は最小の表示テスト。
   ページ移行分は既存の各ページテストが緑を維持することを回帰で確認（表示崩れ・testid 不変）。

スコープの最終確定（案A/B、移行ページ範囲＝全 ~17 か観察 offender のみか）は詳細設計 + Codex 合議で
決める。過剰な全面書き換えは避け、観察された非準拠の是正に絞る（AGENTS.md: 今必要なものだけ）。

## 制約・前提

- フロントは Svelte 5 runes + DS token/ramp のみ（DESIGN.md canonical, ds-purity テスト）。primitive は
  layout/padding utility のみで DS-pure。アイコン新設なし。
- component 階層は atoms→…→templates→pages の単方向 import（atomic-import-graph テストが強制）。
  layout primitive は templates 層（aigenba と同じ配置分類 = layout_primitive、pages から import 許可・
  他 primitive / page shell への依存禁止の flat 構成）。
- バックエンド変更なし（純フロント・レイアウトのみ）。認可・shared prop・ルートは不変。
- 既存の各ページ testid / 振る舞いは不変（中央寄せ化は表示のみの変更）。

## スコープ外

- ゲスト系ページ（Pricing / Welcome / Contact 等、既に mx-auto 中央寄せ）の変更。
- ページ内コンテンツ自体の再設計（フォーム項目・情報設計は不変。幅の中央寄せのみ）。
- サイドバー nav 項目の増減（F-0-1 の「設定」除去を除く）。
- T069 で確立したサイドバー構造そのものの変更（本件は幅とナビ重複の後退修正に限定）。
