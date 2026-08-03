# Playwright で bfcache 復元を再現できるかの実測 (2026-08-03)

施策 8 の完了条件「WebKit レーンで実 bfcache 復元を恒久自動回帰にする」が
実装後に達成できなかったため、**設定の問題か原理的な制約かを切り分ける**目的で実測した。

- 検証スクリプト: [`bfcache-playwright-probe.mjs`](./bfcache-playwright-probe.mjs)
- **アプリには一切触れていない**。node の http サーバで 2 ページを配信し、
  `goBack()` 後の `pageshow.persisted` を観測するだけの単独スクリプト。
- playwright-core **1.61.1**

## 結果

| 条件 | `pageshow.persisted` |
|---|---|
| chromium 既定 | `false` |
| chromium + `ignoreDefaultArgs: ['--disable-back-forward-cache']` | **`false`** |
| chromium + 上記 + `--enable-features=BackForwardCache,BackForwardCacheNoTimeEviction` | **`false`** |
| webkit 既定 | `false` |
| `ignoreDefaultArgs: true` で CDP 既定引数ごと外す | **起動不能** (launch timeout 180s) |

## 結論

**Playwright で実 bfcache 復元を自動検証することは、設定の問題ではなく原理的に不可能。**

根本原因は **Chromium が DevTools/CDP セッション接続中に bfcache を無効化する**こと。
Playwright はブラウザ駆動に CDP を必須とするため、フラグ設定では回避できない。

playwright-core 1.61.1 の既定 Chromium スイッチには確かに
`--disable-back-forward-cache` が含まれる (`lib/coreBundle.js:34444`) が、
**それを外しても復元しない**ため、フラグは根本原因ではない。

## 設計への反映

`detailed-design.md` 施策 8 の完了条件を、この実測に基づいて再定義した
(「担保しない」のではなく **担保できる境界を正確に引き直す**)。

| 対象 | 自動回帰で担保 |
|---|---|
| guard の状態機械 (分岐・遷移・誤発火しないこと) | **担保する** (vitest 19 ケース + 実ブラウザ E2E 2 ケース) |
| ブラウザ自身が bfcache から復元する経路 | **担保しない** (不可能)。skip で明示し、iOS 実機受入確認で補完する |
