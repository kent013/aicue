# 対応マトリクス: conceptual-review Round 4

## [Warning] 本番の `QUEUE_CONNECTION=sync` が guard を通過する

- 判断: **対応する (指摘は正しい。設計の穴だった)**
- 根拠: R1〜R3 を「既定接続が sync なら skip」としていたため、
  `APP_ENV=production` + `QUEUE_CONNECTION=sync` が起動できてしまう。このとき
  job は HTTP リクエスト内でインライン実行され、原子性・非同期化・worker 分離が
  すべて失われる。§9 の「構成が崩れれば M6 が fail-closed で押さえる」という主張とも矛盾する。
- 対応内容: M6 に **R5「production では既定接続の driver が `database`」** を追加し、
  R1〜R3 の適用条件を「常時」に変更した (skip 条件そのものを廃止し、
  「driver が sync でない参照接続」に規則を掛ける形へ整理)。
  これで「R-10 は本番には存在しない」が**構成不変条件として機械的に成立**する。

## [Suggestion] R-10 の顕在化の表現が不正確

- 判断: **対応する**
- 根拠: 「テストが不安定化する」は誤り。concurrency error を踏まなければテストは安定して緑。
- 対応内容: §8 を「対象 job が commit callback 内で concurrency error 相当を投げた場合に、
  業務クロージャの重複実行、または commit 済みなのに例外応答が返る形で顕在化しうる。
  専用 gate では検出しない」へ書き換えた。

## [Suggestion] 並行一回性テストは `RefreshDatabase` 下では本物の競合にならない

- 判断: **対応する**
- 根拠: 正しい。ラッパ tx 内で逐次 handle するだけでは org lock / partial unique の
  競合保証を検証したことにならない。
- 対応内容: §5-3 を 3 点に分割した。
  (1) pending 存在時の逐次 no-op / (2) partial unique 制約そのもの (直接 INSERT で確認) /
  (3) unique violation が no-op へ収束し呼び出し側へ例外が漏れない経路。

## [Suggestion] 低残高通知テストで `NotificationCenterService` 全体を mock しない

- 判断: **対応する**
- 根拠: 正しい。公開メソッドごと mock すると実装本体の `safely()` まで置き換わり、
  「握っていること」の検査にならない。
- 対応内容: `AppServiceProvider` が bind している `DatabaseChannel` →
  `OrganizationScopedDatabaseChannel` を **throw する fake channel** に差し替え、
  `safely()` の内側で失敗させる形へ変更した。
