# 対応マトリクス: conceptual-review Round 3

## [Critical] `C = 360` を「TTL/stale を動かさずに取れる最大値」とする算術が誤っている
- 判断: **対応する (指摘どおり誤り)**
- 根拠: `T = 4C + 120 < retry_after = 1680` から導けるのは `C < 390` であって `C ≤ 360` ではない。
  `C ≤ 360` は `$timeout = 1560` を先に固定したときだけ出る。循環論法だった。
- 対応内容: 導出の向きを逆に書き直した。
  **「C は実測 274 秒に対する運用上限として 360 秒と決める → 結果として
  `T = 4C + M₁ + S = 1,560s` になり、`1,560 < 1,680 < 1,800` の連鎖に収まる」**。
  併せて「TTL 据え置きから来る上界は `C < 390`」であることを別途明記し、
  360 がその内側であることを示す (値は変更なし)。

## [Critical] terminal tx 途中の SIGALRM で即時 release されるとは証明できない
- 判断: **対応する (指摘どおり。保証を eventual へ弱める)**
- 根拠: 指摘は正しい。SIGALRM ハンドラは同一プロセス内で走り、
  進行中トランザクションを明示 rollback してから `fail()` を呼ぶわけではない。
  `failed()` が同じ接続を使うと (a) release が既存 tx に巻き込まれて kill と一緒に
  rollback される、(b) 接続状態により `failed()` 自体が失敗する、
  (c) 行ロック待ちのままプロセスが終了する、が起こりうる。
  「必ず即時 release」と書いていたのは過剰な主張だった。
- 対応内容:
  - 保証を **「commit 前 timeout では *即時 release、または* stale 回復による release」**
    という **eventual guarantee** に書き換えた。
  - **必須の不変条件は「最終的に予約が `Released` になり、無課金 succeeded /
    課金済み failed を作らない」こと**であり、即時性は best-effort と明記。
  - テスト計画を修正: SIGALRM 相当テストで**即時 release を必須にしない**。
    代わりに以下 3 経路の**最終会計状態**を必須テストにする:
    1. commit 前に中断 → (即時 or `recoverStale` 経由で) 予約が `Released`
    2. commit 済みで中断 → 予約は `Committed` のまま。`failJob` は terminal guard で no-op
    3. `failed()` が失敗/未実行 → `recoverStale` + `TicketLedgerService::releaseStale` が回収し
       最終的に `Released`
  - 最終防壁 (cron 2 種) を「補助」ではなく **保証の一部**として位置づけ直した。

## [Warning] 期待効果の「max_tokens 上限まで使う段でも打ち切られない」が 360s の格下げと矛盾
- 判断: **対応する**
- 対応内容: 「**観測レンジ (実測 274 秒) と設定した運用上限 (360 秒) の範囲では打ち切られない**」
  へ弱めた。

## [Warning] wall clock 採用理由のうち「TTL と同じ予算だから同じ時計」は不適切
- 判断: **対応する (当該論拠を削除)**
- 根拠: 指摘は正しい。deadline は経過時間、TTL/stale は永続化された時刻であり別概念
  (思考原則 4 を逆向きに誤用していた)。
- 対応内容: 採用理由を **「(1) `travelTo()` によるテスト容易性を優先する。
  (2) 時計補正による soft deadline の揺れは worker の `$timeout` (SIGALRM) が上限するため受容する」**
  の 2 点に限定し、「別時計だと不整合」という主張を削除した。

## [Suggestion] `previous` をローカル変数へ格納して PHPStan の narrowing を明確にする
- 判断: **対応する**
- 対応内容: 詳細設計の実装コードで
  `$previous = $exception->getPrevious(); if ($previous instanceof RequestException) { ... }`
  の形にする旨を明記する。
