# 対応マトリクス: conceptual-review Round 1

Codex 判定: **APPROVED** (Critical 0 / Warning 5 / Suggestion 5)。
APPROVED のため合議ループは Round 1 で終了する。ただし Warning はすべて概念設計へ反映した。

## [Warning] `composer test` だけで `config/` `bootstrap/` `public/index.php` の副作用を完全確認できるという説明は過剰
- 判断: 対応する
- 根拠: 指摘のとおり。`public/index.php` は Feature テスト (Laravel の内部 kernel 呼び出し) を通らない。
- 対応内容: 検証手段を分けて書き直した。`public/index.php` は **Browser レーン (`composer test:browser`) が
  実サーバ経由で通る**こと、および `php -l` を明記。`bootstrap/` は全レーンの起動が通ること。
  「完全確認」という語を消し、保証範囲を限定して書いた。

## [Warning] 起動系 smoke (`php artisan route:list` 等) を検証項目に入れるべき
- 判断: 対応する
- 根拠: `config/` への宣言追加は config 評価時の関数呼び出しに効くため、起動そのものが確認点になる。
- 対応内容: 検証手順に `php artisan route:list` / `php artisan config:clear` を追加した
  (どちらも読み取り側で dev DB へ破壊操作をしない)。

## [Warning] 「PHPStan level 10 が副作用を機械的に検出する」は完全検出ではない
- 判断: 対応する
- 根拠: 動的呼び出し・container 解決・config 評価時の値変換は静的解析の外にある。
- 対応内容: 「機械的に確認できる」を「主要経路を静的に検出できる (完全ではない)」へ改めた。

## [Warning] 骨組み由来ファイルは将来の vendor publish で未宣言が戻りうる
- 判断: 対応する
- 根拠: 実際に `vendor:publish` は宣言なしのファイルを書き戻す。
- 対応内容: `docs/template-divergence.md` への記録内容に「骨組み由来ファイルも本リポジトリでは対象。
  publish した直後は gate が赤くなるので宣言を足してから commit する」を含めた。
  gate の失敗メッセージにもこの手順を出す。

## [Warning] strict_types 追加で PHPStan エラーが出たとき型を緩めて黙らせるのは禁止事項 2
- 判断: 対応する
- 根拠: AGENTS.md 禁止事項 2 そのもの。
- 対応内容: 「PHPStan エラーが出たら明示 cast / 値の正規化で直す。widen も baseline 化もしない」を
  概念設計の制約へ明記した。

## [Suggestion] 使命への貢献は間接である旨を抑えた表現にする
- 判断: 対応する
- 対応内容: 「基盤整備としての間接貢献」と書き直した。

## [Suggestion] 失敗メッセージに「有効な別記法かもしれないが正準形のみ許可」と明示する
- 判断: 対応する
- 対応内容: gate の失敗メッセージ仕様へ追加した。

## [Suggestion] 将来どうしても宣言できないファイルが出たら、なし崩しに免除を足さない前提を文書化する
- 判断: 対応する
- 対応内容: 「免除機構を後から足す場合は設計レビューを通す」を gate の説明文と概念設計へ明記した。

## [Suggestion] 走査域拡大 / baseline 非採用 / 判定器を下界にする / 母集団列挙の共用
- 判断: 4 件とも「妥当」の追認。変更なし。
