# 対応マトリクス: design-review Round 1

判定 REQUEST_CHANGES。Critical 0 / Warning 7 / Suggestion 4。**すべて対応**(反論なし)。

## [Warning] `oauth/*` は `oauth` 直下に一致しない

- 判断: **対応する**
- 対応内容: `MACHINE_FACING_PATTERNS` を `['oauth', 'oauth/*', '.well-known', '.well-known/*']` にし、
  **prefix 直下そのものも含める**と docblock に明記した。

## [Warning] 契約 1 は `Accept: application/json` を明示せよ (M3 の検出条件)

- 判断: **対応する**
- 根拠: M3 (callback を前に置く) は API 要求が `expectsJson()` を満たす場合にだけ collapse へ食われる。
  Accept が無いと**M3 が赤くならない**。
- 対応内容: 契約 1 に「`Accept: application/json` を明示」「**未定義 URL と route model binding 由来の
  404 の両方**を見る」を追記した。

## [Warning] 契約 7 に `.well-known/*` も足せ

- 判断: **対応する**
- 対応内容: 契約 7b を追加 (`MACHINE_FACING_PATTERNS` から誤って消したときに気づける)。

## [Warning] M3 の「契約 1 だけ」は不正確

- 判断: **対応する**
- 対応内容: 「**本ファイル内の最小検出契約が契約 1**。既存 API テストも赤くなりうる」と書き換えた。

## [Warning] Architecture テストは named argument と multiline を拾え

- 判断: **対応する**
- 対応内容: `abort(404, message: '…')` や改行で分かれた記法も対象にすると明記し、
  実装方針 (token 走査、または改行を畳んだ上での正規表現) も書いた。

## [Warning] render callback の型 / `api/*` を条件に書かない判断 (肯定)

- 判断: 対応不要 (妥当と評価)。ただし**登録順が契約になる**ので契約 1 で固定する、という前提は
  上記の契約 1 強化で満たした。

## [Suggestion] 契約 4 は status ごとに dataset で分けよ

- 判断: **対応する**
- 対応内容: 「status ごとに dataset で分ける」と明記 (1 本集約は失敗時の切り分けが重い)。

## [Suggestion] PHPStan 適合 / enum docblock だけ是正 / 分担の整理 (肯定)

- 判断: 対応不要 (いずれも妥当と評価)。import 漏れの注意は実装時に守る。
