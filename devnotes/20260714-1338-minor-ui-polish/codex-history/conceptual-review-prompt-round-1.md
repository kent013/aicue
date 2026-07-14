# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
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

# system: 役割

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

【補足コンテキスト】
- 本件はフロントのみの軽微 (Low) UI 一貫性修正 2 件。バックエンド/DTO/API 変更なし。
- 対象は Svelte 5 + Inertia の page コンポーネント 2 ファイルと、既存 molecule の再利用。
- 既存の関連ファイル (レビュー時に参照可):
  - resources/js/pages/Admin/Users.svelte (メンバー行 li は sm:flex-row sm:justify-between、名前列 min-w-0 truncate、操作ブロック flex-wrap sm:shrink-0。サイドバー aside は md:w-56)
  - resources/js/pages/Settings/Index.svelte (パスワード変更フォームが素の Input type=password 2 つ)
  - resources/js/pages/Auth/Login.svelte (PasswordInput molecule を既に使用)
  - resources/js/components/molecules/PasswordInput.svelte (Eye/EyeOff トグル付き。id/error/aria-describedby/autocomplete 透過)
  - tests/js/pages/AdminUsers.test.ts (行が flex-col sm:flex-row、操作ブロックが flex-wrap を持つことを assert 済み)
  - tests/js/pages/SettingsIndex.test.ts (useForm を fake 差し替え)

---

# user: 概念設計

{下記が概念設計の全文}

（別添: conceptual-design.md）

---- conceptual-design.md 全文 ----

# 概念設計: minor-ui-polish (F-3-02 + F-4-03)

## 背景・課題

bug-hunt (real-llm run) が検出した軽微 (Low) な UI 一貫性の綻び 2 件。いずれも
「見た目のみ・操作阻害なし」だが、標準化された体験を掲げるプロダクトとして
一貫性の欠けは信頼感を損なう。

### F-3-02 (Low, H11+H13): manage/users の名前が tablet 幅で過剰 truncate
- 画面: `manage/users` (`resources/js/pages/Admin/Users.svelte`, Inertia page `Admin/Users`)
- 症状: tablet 幅 (768px 相当) でメンバー名が過剰に truncate される
  (例「Unverified User」→「Un...」)。読めない。
- 根本原因: メンバー行 (`<li>`) は `sm:flex-row sm:justify-between` で 640px 以上を
  横並びにする。一方、この画面は管理メニュー用の左サイドバー `<aside class="md:w-56">`
  を **md (768px) 以上で** 表示する。結果、ちょうど 768px 付近で
  「サイドバー (w-56 ≒ 224px) が本文幅を削る」×「行はすでに横並び」が重なり、
  名前列 (`min-w-0` で無制限に縮む) が操作ブロック (`sm:shrink-0` = 縮まない) に
  押されて数文字まで潰れる。招待中一覧の email 列も同一構造で同じ潜在バグを持つ。

### F-4-03 (Low, H12): /settings のパスワード変更欄に表示トグルが無い
- 画面: `/settings` プロフィール (`resources/js/pages/Settings/Index.svelte`) の
  「パスワード変更」カード。
- 症状: 「現在のパスワード」「新しいパスワード」の 2 入力が素の
  `Input type="password"` で、ログイン画面 (`Auth/Login.svelte`) にはある
  「パスワードを表示」トグルが無い。同じパスワード入力なのに UI が非一貫。
- 既存資産: 表示トグル付きパスワード入力は
  `resources/js/components/molecules/PasswordInput.svelte` として molecule 化済み。
  Login/Register/ResetPassword/ConfirmRecentAuth が既に再利用している。
  Settings のパスワード変更フォームだけが取り残されている。

## 改善アイデア

いずれも **既存資産の再利用** と **最小の Tailwind クラス調整** で閉じる。新規
コンポーネント・新規トークン・API/DTO 変更は不要。

### F-3-02: 名前列に可読な最小幅の床を与え、操作ブロックを回り込ませる
- メンバー行 / 招待行の `<li>` に **行レベルの折り返し (`sm:flex-wrap`)** を許可する。
- 名前 (email) 列に **sm 以上で最小幅の床 (`sm:min-w-*`)** を与え、`min-w-0` による
  無制限の潰れを止める。名前が入りきらない幅では操作ブロックが次行へ回り込み、
  名前は最低限読める文字数を確保する (`truncate` は極端に長い名前の保険として残す)。
- 横並びの発火ブレークポイント (`sm:flex-row`) は **変更しない** →
  既存 AdminUsers テストのブレークポイント不変条件を保ちつつ、潰れだけを解消する。

### F-4-03: Settings のパスワード変更フォームで PasswordInput molecule を再利用
- `Settings/Index.svelte` の「現在のパスワード」「新しいパスワード」の
  `Input type="password"` を、Login 画面と同じ `PasswordInput` molecule に差し替える。
- `id` / `autocomplete` (`current-password` / `new-password`) / `error` /
  `aria-describedby` は現行 FormField 配線をそのまま透過する (PasswordInput が透過対応済み)。

## 期待効果

- **一貫性 (使命への貢献)**: 「思考ゼロ」で迷わず使えるプロダクトとして、同種入力の
  UI を全画面で揃える。パスワード入力の表示トグルが全経路で一貫する。
- **可読性**: tablet でメンバー名 / 招待メールが読める。管理者が誰に何をするかを
  取り違えない (誤操作リスクの芽を摘む)。
- **副作用最小**: 既存 molecule / Tailwind utility の範囲内。トークン・API 不変。

## 実装方針（概要）

| 施策 | 変更 | 種別 |
|------|------|------|
| F-3-02 | `Admin/Users.svelte`: メンバー行・招待行の名前/email 列に `sm:min-w-*` の床、`<li>` に `sm:flex-wrap` | Tailwind クラス調整 |
| F-4-03 | `Settings/Index.svelte`: パスワード 2 入力を `PasswordInput` molecule に差し替え | 既存 molecule 再利用 |

- Atomic Design: `PasswordInput` は既存 molecule、`Admin/Users` / `Settings/Index` は
  pages。単方向 import (pages → molecules) を維持。新規 SVG 内包なし (Lucide の
  eye/eye-off は PasswordInput 内で既に完結)。
- DESIGN.md: color/radius/typography トークンの新規追加・hex 直書きは無し。
  spacing は Tailwind の min-w utility (既存スケール) のみ。

## 制約・前提

- PHP/バックエンド変更なし (フロント表示のみ)。PHPStan/Pest(PHP) への影響なし。
- 既存 vitest テスト:
  - `AdminUsers.test.ts` は行が `flex-col sm:flex-row`、操作ブロックが `flex-wrap` を
    持つことを assert 済み → ブレークポイント (`sm:flex-row`) を変えない方針なので
    これらは維持。新たに「名前列が最小幅の床クラスを持つ」検証を追加する。
  - `SettingsIndex.test.ts` は useForm を fake 差し替え。PasswordInput 差し替え後も
    `screen.getByLabelText`/type 切替の検証を追加できる。
- 検証: `pnpm test` (vitest) / `pnpm typecheck` / `pnpm lint` / `pnpm build` 全 green。

## スコープ外

- パスワード強度メーター等の新 UI (今回の一貫性修正に不要)。
- Users 画面のロール変更・2FA リセット等の挙動 (今回は列レイアウトのみ)。
- サイドバー (`AdminMenuNav`) 自体のレスポンシブ再設計。
- 他画面の Input→PasswordInput 移行 (該当は Settings のみ、他は移行済み)。
