# Round 5: 対応マトリクスと修正内容

Round 4 の Critical 2 件・Warning 2 件・Suggestion 3 件すべてに対応しました。反論はありません (Round 3 で採用した clone 方式は撤回し、ご提案の不活性化方式に変更しています)。

# 対応マトリクス: design-review Round 4

## [Critical] M6: R1〜R3 の sync 除外を `driver === 'sync'` で判定している (fail-open)

- 判断: **対応する (指摘は正しい。自分の疑似コードの穴だった)**
- 根拠: `database-analysis.driver = sync` にすると、その接続は R1〜R3 を全部 skip する。
  R4 が見るのは `connections.sync` だけなので、**pin 済み接続を sync へ差し替える構成が通る**。
- 対応内容: 除外を **接続「名」** (`if ($name === 'sync') { continue; }`) に変更し、
  それ以外の参照接続には `driver === 'database'` を無条件で要求する形にした。
  テスト `pin 済み接続 (database-analysis) の driver が sync なら R1 違反になる` と
  mutation #19 (`database-analysis.driver` を `sync` に変える) を追加した。

## [Critical] M9: dispatcher clone 方式が `QueueManager` の接続キャッシュと不整合

- 判断: **対応する (指摘は正しい。Round 3 で採用した案を撤回する)**
- 根拠: `QueueManager` は解決済み queue connection をキャッシュし、connection は自分が持つ
  container 経由で event dispatcher を引く。swap 前に connection が生成済みなら clone 側の
  listener が捕捉できず、swap 中に生成された connection は capture 後も clone dispatcher を
  握り続けうる。
- 対応内容: Codex の提案どおり **元 dispatcher に listener を足し、`finally` で
  `$active = false` にして不活性化する**方式へ変更した。dispatcher の差し替えも
  既存 listener の削除も起きない。docblock に採らなかった 2 案
  (`Event::forget` / clone swap) とその理由を残した。
  自己テストを **Feature テスト (実 database queue 経由)** に格上げし、
  Codex の挙げた 4 点 + `only()` の filter を固定する。
  mutation #18 を「`$active = false;` を削る」へ変更した。

## [Suggestion] M1: ヘルパ docblock に固定値 `level >= 2` が残っている

- 判断: **対応する**
- 対応内容: docblock を「判定は **baseline + 1 以上**。固定値では判定しない
  (ネストの深さはテストの書き方で変わるため)」へ書き換えた。

## [Suggestion] M3: リスク欄に「戻り値は `mixed`」の古い表現が残っている

- 判断: **対応する**
- 対応内容: PHPStan 適合欄と同じ表現
  (「戻り値型を伝播できるが、解析結果が十分に具体化されない場合に備えて shape を明示する」) へ揃えた。

## [Suggestion] M7: `phpFilesUnder()` の入力契約を検査せよ

- 判断: **対応する**
- 対応内容: 「各入力が**絶対パス**かつ**存在するディレクトリ**であることを明示検査し、
  満たさなければ例外」を docblock ではなく**契約**として書き、
  負のテスト 2 本 (テスト 13 / 14) を追加した。理由も明記
  (「タイポで存在しないルートを渡したときに黙って 0 件を返すと、母集団 0 件 fail の意図が空洞化する」)。

## [Warning] 保証しないもの 13 番と 16 番が矛盾している

- 判断: **対応する**
- 対応内容: 13 番の主文を「**対象ジョブが実際に使う接続**が `database` driver かつ
  `after_commit=false` であることに依存する」に変え、
  「`queue.default='database'` が効くのは `onConnection` で pin されていないジョブだけで、
  pin 済みジョブには直接効かない (16 番と対応)」と位置づけ直した。

## [Warning] mutation 表に M6 の pin 済み接続 sync 化の変異が無い

- 判断: **対応する**
- 対応内容: mutation #19 として追加し、「sync 除外を接続名で行っていないと**落ちない**」ことを注記した。

---

## 修正後の該当箇所

### M6: R1〜R3 の sync 除外 (接続名判定へ)
        // R1〜R3: 参照集合 = [既定接続] ∪ PINNED_CONNECTIONS (重複除去)。
        // ★ **除外は接続「名」で判定する** (Codex Round 4 の Critical)。
        //   driver === 'sync' で除外すると、`database-analysis.driver = sync` にした構成が
        //   R1〜R3 を全部 skip して通ってしまう (R4 が見るのは connections.sync だけのため)。
        //   名前で除外すれば pin 済み接続を sync へ差し替える構成は R1 違反になる。
        foreach ($this->referencedConnections($defaultQueue) as $name) {
            if ($name === 'sync') {
                continue;                       // sync 接続の契約は R4 / R5 が担う
            }
            $config = $connections[$name] ?? null;
            if (! is_array($config)) {
                $violations[] = /* 接続定義の欠落・非配列 = R1 違反 */;

                continue;                       // ← 以降の offset 参照をしない
            }
            $driver = $config['driver'] ?? null;
            if ($driver !== 'database') {
                $violations[] = /* R1 違反 */;

                continue;
            }
            // R2 は **三分岐** (Codex Round 2 の Warning)
            $connection = $config['connection'] ?? null;
            if ($connection === null) {
                // 既定 DB 接続を使う = 許可
            } elseif (is_string($connection) && $connection !== '') {
                if ($connection !== $defaultDatabase) {
                    $violations[] = /* R2 違反 */;
                }
            } else {
                $violations[] = /* R2 違反 (null | 非空 string 以外は不正) */;
            }
            // R3 はキー欠落も非 bool も違反 (厳密比較)
            if (($config['after_commit'] ?? null) !== false) {
                $violations[] = /* R3 違反 */;
            }
        }

### M9: capture ヘルパ (不活性化方式)
     * ★ listener の隔離は **元 dispatcher に listener を足し、capture 終了後に
     *   その closure を不活性化する**方式で行う。採らなかった 2 案とその理由:
     *   - `Event::forget(JobQueueing::class)`: capture 以前から存在した同イベントの
     *     listener まで削除する。「現時点で grep 0 件」は恒久的な安全性にならない
     *   - **dispatcher の clone へ swap**: Laravel の `QueueManager` は解決済みの
     *     queue connection をキャッシュし、connection は自分が持つ container 経由で
     *     event dispatcher を引く。swap の前に connection が生成済みなら clone 側の
     *     listener が `JobQueueing` を捕捉できず、swap 中に生成された connection は
     *     capture 後も clone dispatcher を握り続けうる (Codex Round 4 の Critical)
     *   不活性化方式なら dispatcher の差し替えも既存 listener の削除も起きない。
     *   グローバルな `RefreshApplication` によりテスト終了時に dispatcher ごと破棄され、
     *   「1 テスト 1 capture」の規約下では不活性 listener はそのテスト中に高々 1 個残るだけである。
     *
     * @return list<array{job: string, level: int}>
     */
    public static function capture(callable $action): array
    {
        $active = true;
        $records = [];

        Event::listen(JobQueueing::class, function (JobQueueing $event) use (&$active, &$records): void {
            if (! $active) {
                return; // capture 終了後は記録しない
            }
            $job = $event->job;
            $records[] = [
                'job' => is_object($job) ? $job::class : (string) $job,
                'level' => DB::transactionLevel(),
            ];
        });

        try {
            $action();
        } finally {
            $active = false; // action が例外を投げても必ず不活性化する
        }

        return $records;
    }

### M9 自己テスト
- [ ] 新規: `tests/Feature/Support/Queue/RecordsJobQueueingTransactionLevelTest.php`
  **(実 database queue 経由で確認する。`Event::dispatch()` を直接叩くだけでは
  `QueueManager` 経由の発火経路を検証したことにならない — Codex Round 4)**
  - `capture 中は JobQueueing を記録する`
  - `capture 前から存在する listener は capture 中も capture 後も動く`
  - `capture 後に別ジョブを dispatch しても records が増えない`
  - `action が例外を投げても、その後 records が増えない`
  - `only() は対象ジョブクラスの記録だけを返す`

### M7 phpFilesUnder の契約検査
     * ★ 各入力について「**絶対パスであること**」「**存在するディレクトリであること**」を
     *   明示検査し、満たさなければ例外を投げる (docblock だけの契約にしない)。
     *   タイポで存在しないルートを渡したときに黙って 0 件を返すと、
     *   母集団 0 件 fail の意図が空洞化するため。
     *
     * @param  list<string>  $absoluteRoots  絶対パスの既存ディレクトリ
     * @return list<string>
     */
    public static function phpFilesUnder(array $absoluteRoots): array { /* 独立列挙 */ }

### mutation 表 (末尾)
| 17 | `config/queue.php` の `sync` の `driver` を `database` に変える | `QueueDispatchAtomicityGuardTest` (R4 の driver 検査) |
| 18 | `RecordsJobQueueingTransactionLevel::capture()` の `$active = false;` (finally) を削る | `RecordsJobQueueingTransactionLevelTest` の「capture 後に別ジョブを dispatch しても records が増えない」 |
| 19 | `config/queue.php` の `database-analysis` の `driver` を `sync` に変える | `QueueDispatchAtomicityGuardTest` (R1。sync 除外を接続名で行っていないと**落ちない**) |


### 保証しないもの 13 / 16
13. **tx level 観測は「対象ジョブが実際に使う接続が `database` driver かつ
    `after_commit=false` であること」に依存する**。`after_commit=true` の接続では
    `JobQueueing` が commit 後の callback 内で発火し、観測される level が baseline に落ちる。
    `queue.default='database'` の設定が効くのは **`onConnection` で pin されていないジョブ**
    だけで、pin 済みジョブ (`database-analysis` 等) には直接効かない (16 番と対応)。
    テストは対象ジョブの pin 先接続について前提自体を assert する
14. **pinned connection 集合の完全性は `QueuedJobLeaseInventoryTest` の接続抽出能力に依存する**。
16. **tx level 観測は「対象ジョブが実際に使う接続」の設定に依存する**。
    `queue.default` を `database` に変えても、ジョブ自身が `database-analysis` 等へ
    `onConnection` で pin されている場合、効くのは **pin 先の `after_commit`** である。
    テストは `queue.default` ではなく**対象ジョブの pin 先接続**の `after_commit=false` を
    assert すること (Codex Round 3 の Suggestion)


---

各施策の判定と全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。残る懸念が Suggestion 相当であれば APPROVED としてください (実装フェーズで扱います)。
