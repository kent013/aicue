# 概念設計: purchase-tickets-stale-validation

## 背景・課題

bug-hunt(real-llm run) F-3-01(Medium, ヒューリスティクス H10 一貫性 + H12 エラーからの回復)。

`/purchase-tickets`(billing.tickets.checkout 直前のチケット購入画面)で以下の UX 破綻が観測された:

1. 枚数入力に範囲外(例: `1001`、上限 `maxCount=1000` 超)を入力し「購入手続きへ」を押下
2. client-side validation がブロックし、フィールドが invalid 表示 + エラー文言
   「購入枚数は 1〜1000 の整数で入力してください」を表示(**ここまでは設計どおり正しい**)
3. **送信し直さずに**枚数を有効値(例: `20`)へ修正すると、合計金額は正しく再計算される
   (`単価 ¥80 × 20 枚 = 合計 ¥1,600`)が、**invalid 表示とエラー文言が消えず残留する**

結果、実際には購入可能な有効入力であるにもかかわらず「エラー中」と誤認させ、
ユーザーが有効な入力のまま操作を諦めうる。エラーからの回復(H12)が阻害されている。

### 根本原因

`resources/js/pages/Billing/PurchaseTickets.svelte`:

- `clientError`(L36 `let clientError = $state<string | null>(null)`)は独立した state で、
  `submit()`(L81-100)内でのみ `null` リセット/セットされる。
- 入力値は `countText`(`$state`)→ `parsedCount`(`$derived`)→ `isValidCount`(`$derived`)と
  正しく再計算されるが、`clientError` はこの派生チェーンに**追従しない独立 state** のため、
  入力が有効値へ回復しても残留する。
- FormField の `error` prop は `clientError ?? serverErrors.count ?? serverErrors.attempt_token ?? null`
  で解決され、`clientError` が非 null の限り invalid + 文言が表示され続ける。

つまり「押下時にのみエラーを設定/クリアする」という imperative なライフサイクルと、
「入力に連動して有効性が変わる」という reactive な実態が乖離していることがバグの本質。

## 改善アイデア

**入力が有効値へ回復した時点で client-side validation エラーを自動的に解消する。**

`isValidCount`(既存の `$derived`)が `true` に復帰した時点で `clientError = null` にする
反応的な dismissal を追加する。具体的には `$effect` で `isValidCount` を監視し、
有効になったら `clientError` をクリアする最小変更を行う。

- クリア条件は「値が有効に戻ったら消える」のみ(`isValidCount === true` の時だけクリア)。
- 無効値のまま別の無効値へ変えても(例: `1001` → `2002`)エラーは残す。
  → 過剰クリアを避け、送信前の「押下時にエラー表示」という既存契約
  (AGENTS.md 禁止事項#8 / DESIGN.md: 必須未充足でも disabled にせず押下時にエラー表示)を維持する。
- サーバ由来エラー(`serverErrors.count` 等、full POST の往復で戻るもの)は本変更の対象外。
  `clientError` のみを扱う。

### なぜ $effect か(代替案の検討)

- **代替案 A: `countText` の oninput で毎回クリア** — 入力のたびにクリアすると、無効値を
  タイプ中でもエラーが消え、「押下時にエラー表示」の設計意図(コメント L18, L82)と乖離する。
  また `isValidCount` を条件にしないと過剰クリアになる。採用しない。
- **代替案 B: `submitAttempted` フラグ + `$derived` で表示エラーを純粋導出** — `$effect` を
  避けられ Svelte 的に idiomatic だが、「一度送信を試みたら無効入力のタイプ中に即エラーを再表示」
  する挙動になり、「押下時にのみエラー表示」という既存契約から逸脱する(有効へ回復後に再度無効を
  打つと押下前に赤くなる)。既存 UX 契約の維持を優先し採用しない。
- **採用: `$effect` による条件付き dismissal** — `clientError` は「押下」という imperative
  イベントで設定される transient な UI state であり、その dismissal(有効化時の解除)は
  純粋な派生ではなく反応的副作用。`$effect` の正当な用途(state の単純ミラーリングではない)。
  最小差分で既存契約を保てる。コードベースでも `$effect` は 11 ファイルで確立済みパターン。

## 期待効果

- **使命への貢献**: AI-CUE は現場作業者が専門知識ゼロで使えることが価値の核。課金導線
  (チケット購入)で「有効入力なのにエラー中に見える」誤認は、まさに専門外ユーザーを
  つまずかせる摩擦。これを除去し「思考ゼロ」で購入完了まで到達できる導線に近づける。
- **具体的改善**: 範囲外入力後に有効値へ修正した時点でエラー表示が即座に消え、invalid が外れる。
  合計金額の再計算(既に正しい)と表示状態が一致し、H10(一貫性)/ H12(回復)を回復する。

## 実装方針(概要)

`resources/js/pages/Billing/PurchaseTickets.svelte` に以下の最小変更を加える:

```ts
// isValidCount が true に復帰したら client-side エラーを解消する
// (「押下時にエラー表示」契約は維持: 無効のままなら残す / サーバエラーは対象外)
// clientError の有無も条件に含め、不要な代入を避け意図を明確化する(Codex Round1 反映)
$effect(() => {
    if (clientError !== null && isValidCount) clientError = null;
});
```

- `submit()` の既存ロジック(押下時に `clientError` を設定)はそのまま。
- 無限ループの懸念なし: effect は `isValidCount` を読むが `clientError` を読まないため、
  `clientError` への書き込みが effect を再起動しない。`isValidCount` は `countText` 変更時のみ変化。

## テスト方針(禁止事項#1 遵守)

vitest(@testing-library/svelte)で以下を検証する再現テストを先に追加する:

- 範囲外入力(`1001`)→ 押下でエラー文言「購入枚数は 1〜1000 の整数で入力してください」が表示される
- **送信し直さず**有効値(`20`)へ修正 → エラー文言が消え、入力の invalid(`aria-invalid`)が外れる
- 無効値のまま別の無効値へ変えてもエラーは残る(過剰クリアしない)ことも確認する

FormField が `error` prop を単一真実源として `aria-invalid` / `aria-describedby` を出すため、
`clientError = null` で表示・a11y ともに解除されることをテストで担保する。

## 制約・前提

- Svelte 5 runes(`$state` / `$derived` / `$effect`)。`$effect` は本コードベースで確立済み。
- DESIGN.md / AGENTS.md 禁止事項#8(必須未充足で disabled にしない、押下時にエラー表示)を維持する。
- DS token / atomic import 階層は変更しない(pages 層のロジック追加のみ、UI 構造・token 不変更)。
- サーバ権威(金額はサーバ確定)・attempt_token フローは不変更。

## スコープ外

- サーバ側 validation・課金ロジック・傾斜単価計算の変更。
- `serverErrors`(full POST 往復由来)の表示ライフサイクル変更。**本修正は `clientError` の
  stale state のみを対象とし、サーバ由来エラーのクリア戦略は別件**(Codex Round1 反映)。
- 他フォーム(他ページ)の同種 stale error の横展開(本 bug-hunt 指摘は当該画面のみ)。
- UI デザイン・文言・token の変更。
