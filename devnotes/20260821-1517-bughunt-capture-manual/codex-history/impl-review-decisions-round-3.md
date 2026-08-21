# 対応マトリクス: impl-review Round 3

Codex 全体判定: CHANGES_REQUESTED (JS collector 承認。施策4 の live 観測の空振り防止と
response 観測が残)。Warning 2 + Suggestion 1 に対応した。

## [Warning] CaptureAppBoundaryTest: fetch/XHR 観測母集団が非空であることを未確認
- 判断: 対応する
- 根拠: `$externalXhr === []` は resource entry が 0 件でも green になる (1.5s 待機中に対象フローが
  発火しなくても Phase A 成功扱い)。
- 対応内容: 母集団を受動観測 (Performance API) に依存させず、**reloadManual が叩く現 URL の Inertia
  visit を能動 fetch して実 response を 1 件確実に観測する** よう変更 (母集団非空を構造的に保証)。

## [Warning] Performance API はネットワーク最終 response の代替になっていない (status/ヘッダ未観測)
- 判断: 対応する
- 根拠: 設計は response status・`X-Inertia`・`X-Inertia-Location` 実値の観測を要求している。
  Performance API は URL と initiatorType しか出さない。
- 対応内容: 同一オリジンの能動 fetch (`X-Inertia:true`) で reload endpoint の実 response を読み、
  **status (200 部分リロード / 409 現 URL ハードリロード のいずれか)・`X-Inertia`・`X-Inertia-Location`
  実値**を assert。`X-Inertia-Location` と最終 URL が現 origin の /app 配下である (= /app 外 redirect でない)
  ことを固定した。devnotes の「証拠の正本」記述もこの能動観測 (実 response) を指すよう更新し、
  Performance API は受動補助と位置づけ直した。

## [Suggestion] 外部 origin 判定は観測開始時の期待 origin と比較する
- 判断: 対応する
- 対応内容: 最初の visit 直後に `window.location.origin` を保存し、以降の navigation/resource/location
  判定と能動 fetch の in-app 判定すべてをこの固定 origin で行う (遷移後 origin を正解にしない)。
