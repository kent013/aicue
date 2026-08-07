# T126 mutation evidence — 新設 gate が「本当に効いているか」

新設 gate は**素の main では赤にならない** (実装後に green になるのが正常)。
したがって「効いている」と主張する唯一の根拠は、**意図的な退行 (mutation) を入れたときに
期待どおり赤くなる**ことの記録である。

- 実行スクリプト: `devnotes/20260807-2127-todo-T126/run-mutations.py` (一時スクリプト。恒久化しない)
- 実行方法: `cd .claude/worktrees/tasks/T126 && python3 devnotes/20260807-2127-todo-T126/run-mutations.py`
- 各 mutation は「退避 → 適用 → 対象テスト実行 → **必ず退避から復元**」を 1 セットとする
  (`try/finally` で復元するため、途中で失敗しても mutation は残らない)
- テストは `vendor/bin/pest <file>` を直接実行した。グローバルテストロックは**worktree 横断の
  直列化**用であり、ここは非 parallel かつ worktree 固有 base DB (`app_test_44b5f445`) しか
  使わないため、ロック外の単発実行で意味が変わらない。最終の全 green 確認は
  `composer test` (= ロック配下 + `--parallel`) で別途行っている
- 実行結果: **22/22 すべて RED (`ALL RED`)**
  (設計に列挙した 20 件 + impl-review Round 1 の Critical 対応で新設した gate 2 件)
- 復元確認: 実行後に `git status --short` と
  `rg -n "mutationProbe|s3_unpinned_probe|listObjects" app/ tests/ config/` で残骸ゼロを確認済み
- **main (T133 / T125) を取り込んだ後に全 22 件を再実行**しており、上表は再実行後の実測である

| # | mutation | 赤くなったテスト (実測) |
|---|---|---|
| 1 | `ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS` を `80` (SDK 既定) にする | `pin 値は SDK 既定値と異なる` / `時間予算: 外部予算 + 局所予算 < worker --timeout < retry_after` |
| 2 | `ExternalClientTimeouts::AWS_MAX_ATTEMPTS` を `3` (SDK 既定) にする | `pin 値は SDK 既定値と異なる` |
| 3 | `config/filesystems.php` の `...awsS3ClientOptions()` 行を削除 | `AWS config: s3 / ses が http と retries を宣言する` / `AWS config: driver=s3 の disk はすべて http / retries を宣言する` / `AWS behavioral: s3 disk クライアントの @http が pin 値になる` |
| 4 | `config/services.php` の `...awsControlClientOptions()` 行を削除 | `AWS config: …` / `AWS behavioral: vendor 契約: MailManager は services.ses を SesV2Client の構築引数へ素通しする` |
| 5 | `ExternalClientTimeoutServiceProvider::boot()` の中身を空にする | `Stripe HTTP client の timeout / connect_timeout / max_network_retries が pin 値になる` |
| 6 | `bootstrap/providers.php` から provider 行を削除 | `provider が bootstrap/providers.php に登録されている` |
| 7 | `TakeObjectStorage::headObject()` の `...awsControlPlaneCommandOptions()` を削除 | `headObject は制御系の @http / @retries を per-command で積む` / `負のコントロール: headObject の @http は s3 disk の既定 (データ系) を上書きする` |
| 8 | `StorageUsageService` に `Storage::disk('s3')->exists()` を 1 行足す | `到達境界: AWS / Flysystem へ到達するクラスは目録と対称差ゼロ` |
| 9 | `TakeObjectStorage` に未登録の public メソッド (`listObjects`) を足す | `面分類: adapter の public メソッドは目録と対称差ゼロ` |
| 10 | `CashierAutoRechargeGateway` に Stripe 呼び出しを 3 つ増やす | `既定接続の Stripe 呼び出しは予算を超えない` (2 データセット) |
| 11 | `config/queue.php` の `retry_after` を `280` にする (予算 290 未満) | `時間予算: 外部予算 + 局所予算 < worker --timeout < retry_after` |
| 12 | `mprocs.yaml` の `--timeout` を `360` にする | `時間予算: mprocs の database worker --timeout が定数と一致する` / 既存 `規則 1: mprocs のキューワーカーは --timeout を明示し retry_after を下回る` |
| 13 | `TakeRegistrationService` の成功パスに `$this->storage->exists()` を足す | `テイク登録エンドポイントは BoundedControl / NoObjectRequest 面しか呼ばない` |
| 14 | `AppServiceProvider` に `ApiRequestor::setHttpClient(new CurlClient)` を足す | `到達境界: Stripe の大域 setter はシンボルごとに許可箇所へ限定される` / `到達境界: 免除理由の適用条件が走査結果と矛盾しない` |
| 15 | `PhpTokenScan::normalize()` からコメント除去を外す | 既存 `QueuedJobLeaseInventoryTest` の `接続経路: 接続の指定は $this->onConnection('リテラル') に限る` / `接続経路: 目録の接続宣言がソースと一致する` |
| 16 | `FakeTakeObjectStorage` の目録 entry を `surface => 'adapter'` に変える | `到達境界: adapter 集合は面分類目録のクラスキーと一致する` |
| 17 | provider の `new CurlClient` を `CurlClient::instance()` に変える | `到達境界: Stripe の大域 setter …` (`CurlClient::instance` は app/ で 0 件) |
| 18 | 無関係なテスト (`tests/Unit/Architecture/…`) に `setHttpClient` を足す | `到達境界: Stripe の大域 setter …` (tests/ 側 exact-fit) |
| 19 | 許可済みテストファイル**内**に `setHttpClient` を 1 件追加する (3 件にする) | `到達境界: Stripe の大域 setter …` (**site 件数**一致。ファイル許可だけでは検出できない側) |
| 20 | `app/` の Service に匿名クラス経由の `Storage::disk('s3')` を足す | `到達境界: AWS / Flysystem へ到達するクラスは目録と対称差ゼロ` (`AnonymousClass` 帰属違反) |
| 21 | 免除 `DefaultDiskWithoutAwsClient` のクラス (`SopTextExtractor`) に `Storage::disk('s3')` を足す | `到達境界: 免除理由の適用条件が走査結果と矛盾しない` |
| 22 | `config/filesystems.php` に pin 無しの `driver=s3` disk を足す | `AWS config: driver=s3 の disk はすべて http / retries を宣言する` |

## 補足 (誇張しない)

- M15 は「共通化 (`PhpTokenScan` への delegate 化) が既存 gate の振る舞いを変えていない」ことの
  **逆確認**である。コメント除去を外すと既存 `QueuedJobLeaseInventoryTest` が赤くなる
  = delegate 先が実際に既存 gate の判定を担っていることが示せた。
- M13 が固定するのは**登録成功パス**である。三点照合の不一致など異常系では
  `TakeRegistrationService` が意図的に `delete()` (Bulk 面) を呼ぶ。テスト側にもその旨を明記した。
- mutation が赤くする対象は「新設 gate だけ」ではない (M1 は 2 本、M12 は既存 gate も赤くする)。
  これは**帯の序列が複数の検査で二重化されている**ことの表れであり、意図した設計である。
- M21 / M22 は impl-review Round 1 の [Critical] (免除理由の適用条件がコメント上の約束だけで
  機械検査されていない / 既定 disk が `s3` を指すと pin を迂回できる) への対応で新設した
  2 gate — `到達境界: 免除理由の適用条件が走査結果と矛盾しない` と
  `AWS config: driver=s3 の disk はすべて http / retries を宣言する` — が
  効いていることの根拠である。**約束を機械検査へ寄せた**ので、
  免除の前提から外れたコードは免除の陰に隠れられない。
- ただし M22 が示すのは「**driver=s3 の disk はすべて帯を宣言している**」までである。
  既定 disk が `s3` を指した場合の待ちは**データ系の長い帯 (900s)** を継承する
  = 有界だが短くはない。「既定 disk 経由でも制御系の帯になる」とは主張しない。
