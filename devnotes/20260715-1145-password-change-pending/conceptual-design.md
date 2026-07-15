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
- **具体的改善**: (1) 失敗誤認の主要因である「前回エラー残留」を除去し、誤認リスクを有意に下げる
  (通信断・タイムアウト等の残余要因まで消せるわけではないため「ゼロ」とは主張しない)。
  (2) 長時間処理でも進行中が明確に伝わる。

## 実装方針（概要）

- 変更対象は **`resources/js/pages/Settings/Index.svelte` 1 ファイルのフロントエンドのみ**。
  サーバ (Fortify Action / PasswordPolicy) は本 item では変更しない。
- `submitPassword` の先頭で `passwordForm.clearErrors()` を追加。
- 送信ボタンの children を `processing` に応じて「変更中…」/「パスワードを変更」で切り替え。
- テスト: `tests/js/pages/SettingsIndex.test.ts` に vitest ケースを追加
  (送信時に前回 errors がクリアされる / 送信中は「変更中…」+ disabled + aria-busy)。
  既存の useForm fake に `clearErrors` spy は既にあるため、それを検証に使う。
  さらに **ユーザー視点の DOM 検証** (前回エラー文言が submit 直後の pending 中に画面から消える) を
  併せて追加し、内部実装 (clearErrors) 依存だけに寄らない回帰網を張る (Codex 概念レビュー Warning 反映)。

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
