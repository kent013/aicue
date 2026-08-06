# 対応マトリクス: conceptual-review Round 3 (概念設計の打ち切りラウンド)

> オーケストレータ指示により概念設計の Codex 合議は**最大 3 ラウンド**。
> Round 3 の Critical 2 件はいずれも根拠が実コードで取れたのでその場で設計へ反映し、
> 未消化の Warning は下記のとおり処理した (残置ゼロ)。

## [Critical] `token_get_all()` でも dispatch 側とジョブ自身の接続指定を区別できていない (観点 3)

- 判断: **対応する** (指摘は正しい。`OtherJob::dispatch()->onConnection('x')` を
  自クラスの指定と誤認しうる)
- 対応内容: **許容形を `$this->onConnection('リテラル')` ただ 1 つに限定**する。
  トークン列で **receiver が `T_VARIABLE` = `$this`** であることまで検証し、
  それ以外の receiver (`Foo::dispatch()->onConnection(...)` / `$job->onConnection(...)` /
  `Queue::connection(...)`) は**すべて接続経路違反として fail** させる。
  「dispatch 側で接続を差し替える」形は本アプリに 1 件も無いことを実査で確認済みなので、
  deny-by-default にしても既存コードは通る。

## [Critical] `queue:listen` の終了経路を `Worker` の SIGALRM と同一視している (観点 3)

- 判断: **対応する** (指摘は正しく、実装を読んだら**想定より重大**だった)
- 根拠 (vendor 実読):
  - `Illuminate\Queue\Listener::createCommand()` が子へ渡すのは
    `queue:work {connection} --once --name --queue --backoff --memory --sleep --tries` のみ。
    **`--timeout` は子へ渡らない**。
  - `Listener::makeProcess()` は `$options->timeout` を **Symfony Process の timeout**
    としてだけ使う (親側の制限)。
  - `WorkCommand::runWorker()` は `--once` のとき `Worker::runNextJob()` を呼ぶ。
    **`runNextJob()` は SIGALRM ハンドラを張らない** (張るのは `daemon()` だけ)。
  - `Listener::listen()` は `runProcess()` を `while (true)` で回すだけで
    `ProcessTimedOutException` を catch しない。
  - **結論**: `queue:listen` 配下では**ジョブ側 `$timeout` は一切効かない**。
    唯一の上限は親の `--timeout` であり、到達時は Symfony が子を kill するだけで
    `markJobAsFailedIfWillExceedMaxAttempts()` を通らない。さらに例外が
    `listen()` を抜けるので **listener 本体も終了する**。
- 対応内容 (設計への反映 3 点):
  1. 遷移表を **`queue:work` (常駐 = 本番運用契約)** と
     **`queue:listen` (mprocs / bug-hunt)** の 2 経路に分けて書き直す。
  2. **`queue:listen` では規則 1 が唯一の防壁である**ことを明記する
     (ジョブ側 `$timeout` が 0 の保護しか与えないので、「`$timeout` があるから大丈夫」が
     dev / bug-hunt では文字どおり成立しない = 規則 1 が「無条件」である理由の実例)。
  3. **有限 `--timeout` を入れると timeout 到達時に listener 本体が落ちる**という
     運用上の副作用を明記する (mprocs はペインが死ぬ / bug-hunt は
     既存の `worker_alive` 検査が検出する)。現状 (`--timeout=0` = Symfony timeout 無効) では
     落ちない代わりにジョブが無限に走り必ず二重取得される、というトレードオフを書く。
  4. Feature テストの対象を「`queue:work` 経路」に限定し、`queue:listen` 経路は
     **プロセス起動を伴うので自動テストにせず**、上記の実読結果を設計に固定する
     (詳細設計の「検証しないと決めたこと」に理由付きで書く)。

## [Warning] 540 / 240 の「運用 SLA」に受入条件が無い (観点 2)

- 判断: **対応する**
- 対応内容: 接続ごとに「timeout 到達時の業務影響 / 回収経路 / 受入根拠」を表で明記する。
  - `database` (540): Mail・Notification は**遅い成功が失敗に変わる**。回収は
    failed_jobs 記録 + 各ドメインのリコンサイル。受入根拠 = 既知の有限上限 (Stripe 400s) を
    上回る最小値であること。
  - `database-media` (240): 削除ジョブは**冪等** (「既に無いキーの削除は no-op」と
    実装 PHPDoc が明記) かつ `$tries = 3` なので、kill されても再配布で完了する。
    受入根拠 = 冪等 + 再試行が構造的に保証されていること (時間見積りではない)。

## [Warning] 「後退ではない」は不正確 (観点 5)

- 判断: **対応する** (指摘のとおり)
- 対応内容: 「後退ではない」を削除し、
  「**無限待機を防ぐ代わりに、遅い成功を失敗へ変えるトレードオフ**」に書き換える。

## [Warning] `database-media = 240` の根拠不足 (観点 5)

- 判断: **対応する**
- 根拠 (実査): `DeleteTakeObjectsJob` は `list<string> $paths` を 1 件ずつ削除する。
  dispatch 元は `CaptureTakeService::delete()` (1 テイク分 = 数件) と
  `VideoManualService` のマニュアル削除 (マニュアル全テイク分 = 最大で数百件)。
  1 件あたり数百 ms とすると数百件でも数十秒。かつ**冪等 + `$tries = 3`**。
- 対応内容: 上記を根拠として設計に明記する (「オブジェクト数本」という雑な記述を置換)。

## [Warning] `$tries=1` の即時 failed 説明が条件依存 (観点 5)

- 判断: **対応する** (Critical 2 の遷移表書き直しに吸収)
- 対応内容: 表を「`queue:work` の SIGALRM 経路」「`queue:listen` の親プロセス終了経路」に分け、
  成立条件 (`maxTries` は CLI `--tries` とジョブ `$tries` の合成であること) を併記する。

## [Suggestion] 匿名クラス / `Foo::class` を `T_CLASS` と誤認しない条件 (観点 7)

- 判断: **対応する**
- 対応内容: 詳細設計に「`T_CLASS` の直後が `T_STRING` である場合のみクラス宣言とみなす
  (`::class` は直前が `T_DOUBLE_COLON`、匿名クラスは直後が `(` または `T_EXTENDS` 等)」を規定する。

## [Suggestion] その他 (観点 1 / 4 / 6)

- 判断: **対応不要** (いずれも肯定的な評価)
