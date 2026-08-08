# Round 5: 対応マトリクスと修正内容

Round 4 の Warning / Suggestion はすべて対応しました。

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

---

## 修正後の該当箇所

### M6 の検査項目 (R5 追加 / skip 条件の廃止)
### M6 の検査項目 (AG-126「使っている機能に応じて選ぶ」に従う)

| 規則 | 内容 | 適用条件 |
|---|---|---|
| R1 | **参照されている**キュー接続のうち driver が `sync` でないものは driver が `database` | 常時 |
| R2 | driver=`database` の参照接続の `connection` が業務 DB の既定接続と一致 | 常時 |
| R3 | driver=`database` の参照接続の `after_commit` が **厳密に `false`** (キー欠落も違反 = fail-closed) | 常時 |
| R4 | `sync` 接続の `after_commit` が **厳密に `true`** | 常時 |
| R5 | **production では既定接続の driver が `database`** (`sync` / 未定義 / その他はすべて違反) | `app()->environment('production')` のときのみ |

**R5 を置く理由 (Codex Round 4 の指摘)**: R5 が無いと
`APP_ENV=production` + `QUEUE_CONNECTION=sync` の構成が guard を通過してしまう。
このとき job は HTTP リクエスト内でインライン実行され、**原子性・非同期化・worker 分離が
すべて失われる**うえ、R-10 (commit callback 内での job 例外) が本番にも出現する。
R5 を置くことで「R-10 は本番には存在しない」が**構成不変条件として機械的に成立**する。

- 「参照されている接続」= 既定接続 + `QueuedJobLeaseInventoryTest` が pin している

### §5-3 の behavioral 検証 (分割・fake channel 化)
### 5-3. behavioral 検証の方式

`Queue::fake()` を使わず、テスト内で `config()->set('queue.default', 'database')` して
実 `jobs` テーブルを観測する。`RefreshDatabase` のラッパ tx の内側なので、
jobs 行も business 行も同じ tx に乗り、テスト終了時に一緒に巻き戻る。

- **正**: `trigger()` 後に `jobs` 行が 1 件あり、`analysis_jobs` 行も 1 件ある
- **原子性**: `trigger()` を外側 `DB::transaction` で囲んで throw → `analysis_jobs` 0 件 **かつ**
  `jobs` 0 件 (両方巻き戻る)
- **投入時点の tx level**: `Illuminate\Queue\Events\JobQueueing` を listen して
  `DB::transactionLevel()` を記録し、**業務 tx の内側 (level ≥ 2)** で enqueue されたことを assert
  する。dispatch を tx 外へ戻すと level 1 になり落ちる (§5-2 変異 4 の検出点)
- **`ShouldBeUnique` 撤去後の一回性**: `RefreshDatabase` のラッパ tx 内では
  **本物の並行実行 (別接続からの競合) は作れない**ため、「並行テスト」1 本で済ませない。
  次の 3 点に分けて固定する (Codex Round 4 の指摘):
  1. **pending 存在時の逐次 no-op**: pending attempt がある状態で `maybeCreateAttempt` を
     もう一度呼ぶと `null` が返り attempt が増えない
  2. **partial unique 制約そのもの**: `tar_attempts_org_pending_unique` が
     同一 org の 2 件目の pending 行を DB レベルで拒否する (直接 INSERT で確認)
  3. **unique violation を正常な競合として処理する経路**: 制約違反が
     no-op へ収束し呼び出し側へ例外が漏れない
- **AG-127 の性質 (通知失敗が業務を巻き込まない)**: `NotificationCenterService` **全体を
  モックしない** (それでは実装本体の `safely()` ごと置き換わり、握りの検査にならない。
  Codex Round 4 の指摘)。`AppServiceProvider` が bind している
  `DatabaseChannel` → `OrganizationScopedDatabaseChannel` を **throw する fake channel** に
  差し替え、`safely()` の内側で失敗させる。そのうえで
  **`reserve()` が成功し予約行が残る**ことを assert する

## 6. リスク

### §8 の R-10 記述
- **sync レーンでは job 本体が `Connection::commit()` の中で走る** (R-10)。
  SQL COMMIT は済んでいるのに `DB::transaction()` が throw しうる。業務クロージャの
  再実行は `causedByConcurrencyError && $currentAttempt < $maxAttempts` のときだけ起き、
  **現行実査 (2026-08-08 / main = c71061e) では `app/` に `DB::transaction()` の
  attempts 指定が 0 件のため起きない**。ただし
  **この前提は機械固定していない = 将来の退行を検出しない**。
  複数行の第 2 引数・変数渡し・`DB::connection(...)->transaction(...)`・自前 wrapper は
  grep では捕捉できない。機械固定しない理由は、適用範囲が **sync レーン (テスト / dev) に
  限られること** (本番で sync を使う構成は M6 の R5 が起動時に拒否する) であり、
  専用 gate を新設するのは思考原則 2 に反すると判断した。
  退行は「対象 job が commit callback 内で concurrency error 相当を投げた場合に、
  業務クロージャの重複実行、または commit 済みなのに例外応答が返る形で顕在化しうる」。
  **専用 gate では検出しない** (concurrency error を踏まない限りテストは安定して緑のままである)

---

全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。残る懸念が Suggestion 相当であれば APPROVED としてください (詳細設計フェーズで扱います)。
