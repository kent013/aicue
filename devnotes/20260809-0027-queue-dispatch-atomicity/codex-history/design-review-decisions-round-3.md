# 対応マトリクス: design-review Round 3

## [Critical] M3: mutation #13 の期待テストが別ジョブを見ている

- 判断: **対応する (指摘は正しい)**
- 根拠: `AutoRechargeAttemptDispatchAtomicityTest` が観測するのは
  `createAttemptLocked()` 内の `ExecuteAutoRechargeAttemptJob` であり、
  `reserve()` 内の `AutoRechargeTriggerJob` を tx 外へ戻しても落ちない。
- 対応内容: 新規 `tests/Feature/Billing/TicketReserveDispatchAtomicityTest.php` を追加し、
  `AutoRechargeTriggerJob` で filter した `baseline + 1` の主契約テストを置いた。
  mutation 表の #13 の期待先をこのテストへ変更し、
  「`AutoRechargeAttemptDispatchAtomicityTest` は別ジョブなので落ちない / rollback テストも落ちない」
  という注記も添えた。さらに #13b (`ExecuteAutoRechargeAttemptJob` を tx 外へ戻す) を分離した。

## [Warning] M6: R4 が `sync.driver` を確認していない

- 判断: **対応する**
- 根拠: 正しい。`connections.sync.driver` が `database` でも、既定接続が別なら R4 を通過できる
  (「sync という名前の database 接続」が作れてしまう)。
- 対応内容: R4 の条件を
  `is_array($sync) && ($sync['driver'] ?? null) === 'sync' && ($sync['after_commit'] ?? null) === true`
  に変更。テスト計画に
  `sync 接続の driver が欠落 / 非 string / 'database' なら違反する` を追加。
  mutation #17 (`sync.driver` を `database` に変える) も追加した。

## [Warning] M7: `phpFilesUnder()` の相対ルート契約が fixture と噛み合わない

- 判断: **対応する (良い指摘。実装したら fixture が列挙できなかったはず)**
- 根拠: `base_path()` からの相対ルートを受ける契約だと、
  `sys_get_temp_dir()` 配下の fixture root を渡したときにパスが連結されて列挙できない。
- 対応内容: Codex の前者案を採用。`phpFilesUnder(list<string> $absoluteRoots)` を
  **絶対パスを受ける純関数**にし、相対→絶対の変換は `runtimePhpFiles()` 側で
  `array_map(base_path(...), RUNTIME_ROOTS)` として行う形にした。

## [Warning] M9: `Event::forget()` は capture 以前の listener も消す

- 判断: **対応する (指摘は正しい。「今は grep 0 件」は恒久的な安全性ではない)**
- 対応内容: Codex の提案どおり **dispatcher の clone へ swap** する方式に変更した。
  clone 側に既存 listener が引き継がれるため既存 listener を壊さず、
  追加した listener だけが clone ごと破棄される。
  あわせて **dispatcher が clone 可能であること**を固定する自己テスト
  `tests/Unit/Support/Queue/RecordsJobQueueingTransactionLevelTest.php` を新設し
  (「capture 後に listener が残らない」「capture 前の listener は生きている」「`only()` の filter」)、
  mutation #18 (clone 隔離をやめる) を追加した。

## [Suggestion] ヘルパ docblock の「level >= 2」を消して `baseline + 1` を正本にせよ

- 判断: **対応する**
- 対応内容: ヘルパ docblock から固定値の記述を削除済み (Round 2 の修正で
  「RefreshDatabase のラッパ tx が level 1」の記述も掲載コード側から外している)。
  主契約の記述は `baseline + 1` に一本化した。

## [Suggestion] 保証しないものに「pin 先接続の after_commit を検査する必要」を追加

- 判断: **対応する**
- 対応内容: 16 番として追加した。
  「`queue.default` を `database` に変えても、ジョブが `database-analysis` 等へ pin されている
  場合に効くのは pin 先の `after_commit` である。テストは pin 先接続の `after_commit=false` を
  assert すること」。M9 のサンプルテストは既にそう書いている
  (`config('queue.connections.database-analysis.after_commit')`)。

## [Warning] mutation 表が「1 変異 = 1 検査点」になっていない

- 判断: **対応する (表の目的を書き換える + 誤りを修正)**
- 対応内容:
  - 表の目的を「各変異が**少なくとも意図した検査で赤になる**」ことの確認と明記し、
    「1 変異 = 1 テストの厳密な 1:1 対応ではない (#2 のように 2 検査点を落とす変異もある)」を追記
  - #10 を **#10a (`phpFilesUnder()` を空に → M7 テスト 5 / 6)** と
    **#10b (`shouldQueueClasses()` を空に → M7 テスト 7 + 既存 2 inventory の対称差)** に分離
  - #11 の対象を `appPhpFiles()` から **`phpFilesUnder()`** へ修正 (M7 テスト 5 が担当)
  - #13 / #13b の期待先を上記のとおり修正
