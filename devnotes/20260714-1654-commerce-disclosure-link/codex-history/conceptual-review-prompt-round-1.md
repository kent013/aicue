# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

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

---

## 概念設計

（以下は `resources/js/pages/Welcome.svelte` / `Pricing.svelte` の footerLinks snippet、`GuestLayout.svelte`、`routes/web.php` の legal ルート定義を実際に確認した上での概念設計です。既存フッターリンクは生の `<a href="/terms" class="hover:text-primary">利用規約</a>` パターン。）

# 概念設計: commerce-disclosure-link

bug-hunt (real-llm 2nd run) F-2-01 (Low) / 前回 run Q-01 と同観察。

## 背景・課題

`legal.commerce-disclosure`（特定商取引法に基づく表記, `/commerce-disclosure`）は
`routes/web.php` に `Route::view('/commerce-disclosure', 'legal.commerce-disclosure')` として
定義され、URL 直打ちでは正常表示される。しかし home / pricing / footer などサイト内の
どこからもリンクされておらず孤立している。

- 既存の姉妹ページ `legal.terms`（利用規約 `/terms`）と `legal.privacy`
  （プライバシーポリシー `/privacy`）は、公開ゲストページのフッターにリンクがある:
  - `resources/js/pages/Welcome.svelte` L388-393 の `footerLinks` snippet
  - `resources/js/pages/Pricing.svelte` L225-230 の `footerLinks` snippet
- commerce-disclosure だけ、この 2 か所のどちらにも並んでいない。
- 特定商取引法に基づく表記は事業者が到達可能な形で掲示すべき法的表記であり、
  「URL を知っている人だけ見られる」状態は不適切。

孤立の根本原因: フッターのリンク群が Welcome / Pricing に重複コピーされており、
legal ページ追加時に片方（commerce-disclosure）を並べ忘れた（ドリフト）。

## 改善アイデア

既存の `legal.terms` / `legal.privacy` リンクが並んでいる同じフッター導線
（Welcome / Pricing の `footerLinks` snippet）に、
「特定商取引法に基づく表記」→ `/commerce-disclosure` へのリンクを追加する。
文言・スタイル（`<a href=... class="hover:text-primary">…</a>`）は既存の法的リンクに
完全に揃える。terms → privacy → commerce の順で配置。

## 期待効果

- 特定商取引法に基づく表記がサイト内導線から到達可能になり reachability 要件を満たす。
- 3 つの法的ページがフッターで一貫して並び、UX 一貫性が向上。
- 使命への貢献: 直接の North Star 機能ではないが、公開・課金 SaaS の
  コンプライアンス基盤の欠落を埋め、プロダクト完成度を保つ。

## 実装方針（概要）

- `resources/js/pages/Welcome.svelte` の `footerLinks` snippet に 1 行追加。
- `resources/js/pages/Pricing.svelte` の `footerLinks` snippet に 1 行追加。
- フッターは `GuestLayout.svelte` の `{@render footerLinks()}` 経由で描画（テンプレート変更不要）。
  ルートは既存（`legal.commerce-disclosure`）で追加不要。
- vitest（`tests/js/pages/Welcome.test.ts` / `Pricing.test.ts`）に、フッターに
  `href="/commerce-disclosure"`・アクセシブル名「特定商取引法に基づく表記」の
  リンクが存在することを検証するテストを追加。

## 制約・前提

- legal ページは現状 noindex のプレースホルダ（`NoIndex` middleware + blade `<meta robots>`）。
  今回はサイト内ナビ導線の追加のみで、noindex 方針・文面差し替えには手を付けない
  （内部リンク追加は noindex と両立）。
- 既存パターン（生の `<a href>` + Tailwind `hover:text-primary`）に一致させ、
  新規 DS token / component は導入しない。
- 既存 snippet 内へのリンク 1 行追加のみで component 層の新設・階層変更なし。

## スコープ外

- フッターリンクの共通化リファクタ（重複 snippet の共有 molecule 抽出）は行わない。
  3 リンクのための component 新設は「今必要なものだけ作る」に反する over-engineering。
  最小修正で reachability を回復し、ドリフト再発防止はテストで担保する。
- Contact ページ（Contact/Index・Thanks）のフッター: GuestLayout を使うが
  `footerLinks` を渡しておらず terms/privacy も含め法的リンクを一切持たない（既存設計）。
  今回のバグは Welcome / Pricing への追加で解消するため対象外。
- legal ページ本文の文面確定・noindex 解除、legal ページ相互のクロスリンク追加。
