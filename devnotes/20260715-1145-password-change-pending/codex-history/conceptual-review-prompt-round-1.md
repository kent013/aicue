# 使命・禁止事項・思考原則（全レビューに適用）

## アプリの使命（North Star / AGENTS.md より）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)**

## 思考原則
まず仮説を立てろ。ユーザー視点で考えろ。データに真摯に向き合え。先人の知恵（Laravel/Svelte エコシステム）を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

## ツール使用制限
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: レビュアーの役割

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか（特に禁止事項 8: disabled UI）
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js 3.3.1）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか（本 item はフロントのみだが波及の有無を確認）

【重要な前提事実（コード調査で確認済み）】
- `@inertiajs/svelte` 3.3.1 の `useForm` は送信開始時に errors をクリアしない（`resetBeforeSubmit` は wasSuccessful/recentlySuccessful のみリセット）。errors は応答後の onError/onSuccess で更新される。
- `Button.svelte` は `loading` 時に LoaderCircle(spin) + disabled + aria-busy を描画。パスワード送信ボタンは既に `loading={passwordForm.processing}` を渡している。
- 成功トーストはサーバ flash → AppLayout.consumeFlash 経由で既存。
- HIBP `uncompromised()` は `PasswordPolicy::rule()` が `App::runningUnitTests()` 時のみ除外（bughunt では実 HTTP）。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下が本レビュー対象。`devnotes/20260715-1145-password-change-pending/conceptual-design.md` の内容）

<!-- conceptual-design.md 全文をここに転記 -->
# 概念設計: password-change-pending

## 背景・課題

bug-hunt run 20260715-084108 **F-4-01 (High, H3: visibility of system status)**。

パスワード変更フォーム (`resources/js/pages/Settings/Index.svelte` の「パスワード変更」カード) を送信すると、
サーバ側 (`Fortify UpdatesUserPasswords` → `Password::default()` = `PasswordPolicy::rule()`) が
HIBP 漏洩照合 (`uncompromised()`) の**実 HTTP 呼び出し**を行い、応答まで **10〜14 秒**かかる。
その間ユーザーには次の 2 点の問題が起きる:

1. **知覚可能な pending 表示が弱い**: 送信ボタンはスピナー付きで disabled になるが、10〜14 秒という
   長い待ち時間に対しては「処理中」という状態が伝わりにくい。ボタン文言は「パスワードを変更」のまま。
2. **直前の失敗エラー文言が残る**: 一度バリデーションで弾かれた後に再送信すると、Inertia の `useForm` は
   **送信開始時に既存 errors をクリアしない** (後述)。このため送信中〜応答待ちの間、前回の失敗エラー
   (例: 「現在のパスワードが違います」) が表示されたまま。サーバは成功しているのに、ユーザーは
   「処理中なのか、また失敗したのか」を判別できず、**失敗と誤認して離脱・再入力する**恐れがある。

### 技術的根拠 (Inertia useForm の挙動確認済み)

`@inertiajs/svelte` 3.3.1 の `useForm().put()` 送信ライフサイクル:

- `onBefore` → `resetBeforeSubmit()`: `wasSuccessful` / `recentlySuccessful` のみリセット。**errors はクリアしない**。
- `onStart` → `processing=true`。
- `onError` (応答後) → `form.clearErrors().setError(errors)`: ここで初めて errors が更新される。
- `onSuccess` (応答後) → `markAsSuccessful()` → `form.clearErrors()`。

つまり **前回失敗の errors は、次リクエストの応答が返るまで表示され続ける**。これが症状 2 の直接原因。

### 現状の実装状況 (調査で判明)

- 症状 1 の **スピナー自体は既に存在**する: `Button.svelte` は `loading` 時に `LoaderCircle`(spin) +
  `disabled` + `aria-busy` を描画し、パスワード送信ボタンは既に `loading={passwordForm.processing}` を渡している。
  → 追加すべきは「10〜14 秒に耐える、より明示的な pending 表現」(ボタン文言を「変更中…」へ) と `aria-busy` の担保。
- 症状 2 の **errors クリアは未実装** (Inertia が自動でやらないため、明示的な `clearErrors()` が必要)。← 本 item の実質的な修正点。
- 成功時トーストは **既存**: サーバ flash → `AppLayout.consumeFlash` → `addToast` の経路で表示済み。

## 改善アイデア

`resources/js/pages/Settings/Index.svelte` のパスワード変更フォームに、フロントエンドのみで完結する 2 点の修正を入れる:

1. **送信開始時に前回 errors をクリアする**: `submitPassword` の先頭で `passwordForm.clearErrors()` を呼ぶ。
   これで送信した瞬間に「現在のパスワードが違います」等の前回エラーが消え、pending 状態が
   誤解なく伝わる (症状 2 の解消 = 本質)。
2. **10〜14 秒に耐える明示的 pending 表現**: 送信ボタンの文言を `processing` 中に「変更中…」へ切り替える
   (スピナー + disabled + aria-busy は既存の `Button loading` が担保)。長時間処理でも「処理中」が明確に伝わる
   (症状 1 の H3 強化)。

成功時はサーバ flash 由来の既存トーストで完了通知する (追加不要)。

## 期待効果

- **使命への貢献**: AI-CUE は「専門知識ゼロの現場作業者」が迷わず使えることが前提。設定変更のような
  基本操作で「成功したのに失敗に見える」状態は信頼性を損ない、操作詰み・離脱を招く。知覚可能な
  フィードバック (Nielsen H3) を担保することは、プロダクト全体の「思考ゼロ」体験の土台を守る。
- **具体的改善**: (1) 前回エラーの残留による失敗誤認をゼロにする。(2) 長時間処理でも進行中が明確に伝わる。

## 実装方針（概要）

- 変更対象は **`resources/js/pages/Settings/Index.svelte` 1 ファイルのフロントエンドのみ**。
  サーバ (Fortify Action / PasswordPolicy) は本 item では変更しない。
- `submitPassword` の先頭で `passwordForm.clearErrors()` を追加。
- 送信ボタンの children を `processing` に応じて「変更中…」/「パスワードを変更」で切り替え。
- テスト: `tests/js/pages/SettingsIndex.test.ts` に vitest ケースを追加
  (送信時に前回 errors がクリアされる / 送信中は「変更中…」+ disabled + aria-busy)。
  既存の useForm fake に `clearErrors` spy は既にあるため、それを検証に使う。

## 制約・前提

- Svelte 5 runes + DS token のみ (DESIGN.md canonical、ds-purity テスト)。新規 hex / SVG 直書きなし。
- アイコンは Lucide のみ (スピナーは既存 Button 内の `LoaderCircle` を流用、本 item で新規 SVG は追加しない)。
- Atomic Design 階層維持 (pages 層の Index.svelte のみ変更、atom/molecule の責務は不変)。
- 禁止事項 8 (必須未充足で disabled にする UI) には抵触しない: ここでの disabled は
  **送信処理中の二重送信防止**であり、入力不足による事前 disabled ではない。
- PHPStan / Pest への影響なし (フロントのみ)。`pnpm typecheck/lint/test/build` を green に保つ。

## スコープ外 (別 item 候補として notes に記す)

- **HIBP `uncompromised()` の bughunt での実 HTTP 呼び出し** (禁止事項 4: fake-externals との不整合)。
  `PasswordPolicy::rule()` は `App::runningUnitTests()` 時のみ `uncompromised()` を外すため、
  bughunt 環境 (`APP_ENV=bughunt.local`) では実 HIBP を叩く。これは本 item の UX 修正 (フロント) とは
  独立したサーバ側 config/bind の論点であり、**別 item として提案**する (本 item では触らない)。
- サーバ側の応答時間短縮 (HIBP 呼び出しの非同期化・キャッシュ等) は本 item のスコープ外。
- 他フォーム (プロフィール更新等) の errors クリア挙動の横展開は本 item では扱わない
  (パスワード変更に閉じる。必要なら別途)。
