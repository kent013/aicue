# 対応マトリクス: impl-review Round 1

## [Critical] GuestLayout の `svelte-ignore a11y_click_events_have_key_events` は不要抑止
- 判断: 対応する
- 根拠: a11y 警告の blanket 抑止は将来の本物の警告を隠す負債になる、という指摘は妥当。
  ただし単に ignore を消すとコンパイラ警告が復活する (パネル `<nav>` に onclick を付けている限り
  `a11y_click_events_have_key_events` / `a11y_no_noninteractive_element_interactions` が発生する)。
  根本解決として **パネル `<nav>` からイベントリスナ自体を除去** し、リンク押下の close 委譲を
  既存の `<svelte:window>` の `onclick` に移した。window は要素 a11y ルールの対象外のため、
  svelte-ignore を 1 つも残さず警告ゼロを達成。
- 対応内容: `handlePanelClick` を `handleWindowClick` に置換 (`menuOpen` かつ
  `target.closest("#guest-nav-panel a")` のときだけ close)。`<svelte:window onclick={handleWindowClick}>`
  を追加し、`<nav id="guest-nav-panel">` の `onclick` と両 svelte-ignore を削除。
  リンクの Enter 押下も既定で click を発火するためキーボード操作でも閉じる (a11y 上も正当)。
  build 再実行で GuestLayout の a11y 警告が消えたことを確認。484 テスト全 green。

## [Warning] `svelte:window onkeydown` が nav 未指定時も常駐 (`if (!nav) return` 提案)
- 判断: 見送る (実質対応済み)
- 根拠: 両ハンドラ (`handleKeydown` / `handleWindowClick`) は先頭で `!menuOpen` を early return する。
  トグルは `{#if nav}` 配下でのみ描画されるため nav 未指定では `menuOpen` が真になり得ず、
  既存ガードで nav 未指定時のハンドラ本体は必ず no-op。`!nav` 追加は論理的に冗長なため見送り。

## [Warning] Button.types `element?: HTMLButtonElement | undefined` を明示するとより厳密
- 判断: 見送る
- 根拠: `element?:` の optional 記法が既に `| undefined` を含意する。`$bindable(undefined)` 側と型整合
  済み (typecheck green)。冗長な明示は追加しない。

## [Warning] Welcome.test の単一ヒットを `within(header)` に寄せると頑健
- 判断: 見送る (現状許容と Codex も明記)
- 根拠: 判定に使う "ログイン" は nav 専用リンクで footer に同名が無い。将来 footer に "ログイン" が
  増える蓋然性は低く、増えた時点で検知・修正すればよい。過剰な scope 追加は避ける。

## [Suggestion] 各種 (DESIGN.md 追記 / union never 補完 / DEV 警告 / テスト分担)
- 判断: 対応不要 (肯定的評価)
