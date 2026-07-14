# 概念設計: commerce-disclosure-link

bug-hunt (real-llm 2nd run) F-2-01 (Low) / 前回 run Q-01 と同観察。

## 背景・課題

`legal.commerce-disclosure`（特定商取引法に基づく表記, `/commerce-disclosure`）は
`routes/web.php` に `Route::view('/commerce-disclosure', 'legal.commerce-disclosure')` として
定義され、URL 直打ちでは正常表示される。しかし **home / pricing / footer などサイト内の
どこからもリンクされておらず孤立している**。

- 既存の姉妹ページ `legal.terms`（利用規約 `/terms`）と `legal.privacy`
  （プライバシーポリシー `/privacy`）は、公開ゲストページのフッターにリンクがある:
  - `resources/js/pages/Welcome.svelte` L388-393 の `footerLinks` snippet
  - `resources/js/pages/Pricing.svelte` L225-230 の `footerLinks` snippet
- ところが commerce-disclosure だけ、この 2 か所のどちらにも並んでいない。
- 特定商取引法に基づく表記は、EC/課金を伴うサービスにおいて事業者が到達可能な形で
  掲示すべき法的表記であり、「URL を知っている人だけ見られる」状態は不適切。

孤立の根本原因: フッターのリンク群が Welcome / Pricing に **重複コピー**されており、
legal ページ追加時に片方（commerce-disclosure）を並べ忘れた（ドリフト）。

## 改善アイデア

既存の `legal.terms` / `legal.privacy` リンクが並んでいる **同じフッター導線**
（Welcome / Pricing の `footerLinks` snippet）に、
**「特定商取引法に基づく表記」→ `/commerce-disclosure` へのリンクを追加**する。
文言・スタイル（`<a href=... class="hover:text-primary">…</a>`）は既存の法的リンクに
完全に揃える。プライバシーポリシーの直後に配置する（terms → privacy → commerce の順）。

## 成功条件

**公開ゲスト導線（Welcome / Pricing のフッター）から 1 クリックで
`/commerce-disclosure`（特定商取引法に基づく表記）へ到達できる**こと。
これを主効果とし、reachability 回復を成否の判断基準とする。

## 期待効果

- （主効果）特定商取引法に基づく表記がサイト内導線から到達可能になり、法的表記の
  reachability 要件を満たす（バグ F-2-01 の解消）。
- （副次）3 つの法的ページ（利用規約 / プライバシー / 特商法）がフッターで一貫して並び、
  ユーザーが法的情報を予測可能な場所で見つけられる（UX 一貫性）。
- 使命への貢献: 直接の North Star 機能ではないが、公開・課金を伴う SaaS の
  信頼性/コンプライアンス基盤の欠落を埋め、プロダクトとしての完成度を保つ。

## 実装方針（概要）

- `resources/js/pages/Welcome.svelte` の `footerLinks` snippet に 1 行追加。
- `resources/js/pages/Pricing.svelte` の `footerLinks` snippet に 1 行追加。
- フッターは `GuestLayout.svelte` の `{@render footerLinks()}` 経由で描画される
  （テンプレートは変更不要）。ルートは既存（`legal.commerce-disclosure`）で追加不要。
- vitest（`tests/js/pages/Welcome.test.ts` / `Pricing.test.ts`）に、フッターの
  **法的リンク集合が terms / privacy / commerce-disclosure の 3 件で、正しい href と
  表示順（terms → privacy → commerce）で揃っていること**を検証するテストを追加する
  （commerce-disclosure の単なる存在確認に留めず、legal リンク欠落を検出できる契約に
  引き上げる ＝ ドリフト再発防止）。

## 制約・前提

- `/commerce-disclosure` を含む legal ページは現状 **noindex のプレースホルダ**
  （`routes/web.php` の `NoIndex` middleware + blade の `<meta robots>`）。
  今回の変更は **サイト内ナビ導線の追加のみ**で、noindex 方針・文面差し替えには手を付けない
  （内部リンク追加は noindex と両立する。検索インデックス対象化ではない）。
- **プレースホルダを footer から誘導する点について**: 姉妹ページ `legal.terms` /
  `legal.privacy` も同じ noindex プレースホルダであり、かつ **既に Welcome / Pricing の
  フッターからリンクされている**。commerce-disclosure を並べるのは既存の露出方針との
  **パリティ回復**であって新たな露出リスクを増やさない（むしろ 3 件が揃わないほうが
  「特商法だけ辿れない」という不整合を残す）。文面確定・noindex 解除は別トラック
  （legal 文面差し替えタスク）の責務で本バグ（reachability）のスコープ外。ただし
  「本文が公開可能な状態になったら noindex を外す」という依存条件は据え置く。
- フッターリンクは既存パターン（生の `<a href>` + Tailwind DS ユーティリティ
  `hover:text-primary`）に一致させ、新規 DS token / component は導入しない。
- Atomic Design: 既存 snippet 内へのリンク 1 行追加のみで、component 層の
  新設・階層変更は伴わない。

## スコープ外

- **フッターリンクの共通化リファクタ**（Welcome / Pricing の重複 snippet を
  共有 molecule 等へ抽出）は行わない。孤立の根本原因は重複だが、3 リンクのために
  component を新設するのは「今必要なものだけ作る」（AGENTS.md 思考原則）に反する
  over-engineering。今回は最小修正（両 snippet へ同一リンクを追加）で reachability を
  回復し、ドリフト再発防止はテストで担保する。
- **Contact ページ（Contact/Index.svelte・Contact/Thanks.svelte）のフッター**:
  これらは GuestLayout を使うが `footerLinks` を渡しておらず、terms/privacy も含め
  法的リンクを一切持たない（既存設計）。今回のバグ（commerce-disclosure のみ孤立）は
  Welcome / Pricing への追加で解消するため、Contact への footerLinks 新設は対象外。
- legal ページ本文（特商法の事業者情報）の文面確定・noindex 解除。
- legal ページ相互のクロスリンク追加（現状は「← ホームに戻る」のみ。今回は据え置き）。
