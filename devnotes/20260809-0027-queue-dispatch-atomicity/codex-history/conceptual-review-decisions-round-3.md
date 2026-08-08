# 対応マトリクス: conceptual-review Round 3

## [Warning] R-10 の前提 (attempts=1) が機械固定されていない

- 判断: **一部反論・一部対応 (Codex の提示した 2 択のうち「§8 を弱める」を選ぶ)**
- 根拠 (反論): 提案された Architecture gate は「sync レーンで実行されうる tx 内 dispatch を
  含むトランザクションは attempts=1」という条件付き不変条件で、母集団の定義自体が
  「どのトランザクションが sync レーンで実行されうるか」という到達可能性解析を要する。
  一方、影響範囲は **sync レーン (テスト / dev) に限られ、本番 (database driver) には存在しない**
  (本番では tx 内に載るのは jobs 行の INSERT だけで job 本体は走らない)。
  本番に存在しないリスクのために到達可能性解析を伴う gate を新設するのは
  思考原則 2 (今必要なものだけ作る) に反する。
- 対応内容 (受容部分): §8 を Codex の言うとおり弱めた。
  「現行実査 (2026-08-08 / main = c71061e) では 0 件のため起きない。
  **ただしこの前提は機械固定していない = 将来の退行を検出しない**。
  複数行の第 2 引数・変数渡し・`DB::connection(...)->transaction(...)`・自前 wrapper は
  grep では捕捉できない」と明記し、機械固定しない理由 (影響が sync レーン限定) と
  退行時の顕在化の仕方 (sync レーンのテストが不安定化する) も書いた。

## [Warning] D1/D2 の本番母集団が代表アンカーだけでは不十分

- 判断: **対応する (指摘は正しい)**
- 根拠: 列挙器が `app/Jobs` や `app/Actions` を丸ごと除外しても
  `TicketLedgerService.php` のアンカーは通ってしまう。
- 対応内容: §5-1 の 2 を「**母集団境界の exact-fit 固定**」へ書き換えた。
  Architecture テスト側で `Symfony\Component\Finder\Finder` (既に
  `BillingSyncDispatchInvariantTest` が使用) により `app/**/*.php` の正規化済み集合を作り、
  `QueuedJobPopulation::appPhpFiles()` との**対称差が空**であることを assert する。
  これは検出ロジックの二重実装ではなく母集団境界の固定である旨も明記した。

## [Warning] §5-2 mutation #10 が旧設計のまま

- 判断: **対応する**
- 対応内容: #10 を Codex の提案 3 つで置換した。
  #10 = `ShouldBeUnique` を戻す → M8 の反転テストが落ちる /
  #11 = trigger dispatch を tx 外へ戻す → M9 の実 jobs 表 + tx level テストが落ちる /
  #12 = partial unique を外す → 並行実行の一回性テストが落ちる。

## [Suggestion] `ShouldBeUnique` 撤去後の一回性を behavioral test で固定せよ

- 判断: **対応する**
- 対応内容: §5-3 に「同一 org へ `AutoRechargeTriggerJob` を並行実行しても
  pending attempt は高々 1 件」を追加した。

## [Suggestion] §8 の `ShouldBeUnique` 一般論の対象範囲を明記せよ

- 判断: **対応する**
- 対応内容: 「撤去するのは `AutoRechargeTriggerJob` **だけ**。今後 `ShouldBeUnique` を持つ job を
  業務 tx の内側から dispatch する設計を足すと同じ問題が再発する。本設計はこれを機械検査しない」
  を §8 に追記した。

## [Suggestion] 「AG-127 の除外は 0 件」に修飾を付けよ

- 判断: **対応する**
- 対応内容: 「**確定 1 の queue dispatch 母集団では** 0 件」に統一し、
  「通知まで含めた広義の付随的副作用に除外が無いという意味ではない」旨の注記を §3 に置いた。

## [Suggestion] 契約数の表記ゆれ (4 契約 / 5 契約)

- 判断: **対応する**
- 対応内容: R-3 と §11 を「既存 5 契約」に統一した。

## [Suggestion] DTO に生 config 値を `mixed` のまま持たせない

- 判断: **対応する (詳細設計で具体化)**
- 対応内容: 詳細設計 §M6 で、DTO は正規化済みの型限定値
  (`string` / `bool` / `null` へ狭めた値) と規則 ID enum を持ち、`mixed` を公開しない形にする。

## [Suggestion] `ShouldBeUnique` 撤去 / AG-127 整理 / スコープ分割しない判断

- 判断: **見送る (Codex が成立と認めた項目。現状維持)**
