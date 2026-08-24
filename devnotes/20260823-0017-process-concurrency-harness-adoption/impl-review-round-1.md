結論として、並行プロトコル本体に明白な偽陽性は見つかりませんでした。`cache_store_driver` への設計変更も妥当です。ただし、秘密の診断出力と失敗経路テストに修正必須の穴があります。

## 指摘

### [Warning] 子の stderr が無加工で例外へ入り、秘密を CI ログへ残せる

対象:

- [ConcurrencyProbeRunner.php](/workspace/.claude/worktrees/tasks/T248/tests/Support/Concurrency/ConcurrencyProbeRunner.php:248)
- [ConcurrencyProtocolException.php](/workspace/.claude/worktrees/tasks/T248/tests/Support/Concurrency/ConcurrencyProtocolException.php:31)
- [idempotency-claim-probe.php](/workspace/.claude/worktrees/tasks/T248/tests/Support/Concurrency/idempotency-claim-probe.php:287)

子は catch 節で例外メッセージと完全な trace を stderr に出し、親は `childDiedEarly()` や終了コード異常の例外へ stderr をそのまま埋め込みます。

例外が request body、Authorization ヘッダ、DB 接続情報などを含んだ場合、一時ファイルを削除しても Pest/CI の永続ログへ残ります。「秘密は成否にかかわらず消す」という保証がファイルだけに閉じており、診断経路が未防御です。

子の出力を固定エラーコード・例外クラス・処理段階など非秘密情報に限定するか、親で少なくとも plain API key、raw body、APP_KEY、CIPHERSWEET_KEY、DB_PASSWORDを確実に除去してください。秘密を sentinel 値にした失敗経路テストで、投げられた例外と previous の全文に sentinel がないことも固定すべきです。

### [Warning] transaction の rollback 契約がテストされていない

対象:

- [OutOfTransactionFixtures.php](/workspace/.claude/worktrees/tasks/T248/tests/Support/Concurrency/OutOfTransactionFixtures.php:81)
- [OutOfTransactionFixturesTest.php](/workspace/.claude/worktrees/tasks/T248/tests/Feature/Concurrency/OutOfTransactionFixturesTest.php:123)

失敗テストの callback は行を作る前に即座に例外を投げています。このため、`DB::transaction()` が除去されてもテストは緑のままです。

`create()` は失敗時に keys を返せないので、途中まで作った行を後から cleanup できません。rollback は残留防止の唯一の仕組みです。callback 内で実際に検体を作って ID を外側へ控えた後に例外を投げ、別名接続から全行が存在しないことを検査してください。

### [Warning] 設計逸脱の核心である cache driver の負例がない

対象:

- [ConcurrentProbeObservation.php](/workspace/.claude/worktrees/tasks/T248/tests/Support/Concurrency/ConcurrentProbeObservation.php:183)
- [ConcurrencyHarnessFailurePathTest.php](/workspace/.claude/worktrees/tasks/T248/tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php)

すべての正例が両項目を `array` にしていますが、次の独立した負例がありません。

- `cache_default != array`, `cache_store_driver == array`
- `cache_default == array`, `cache_store_driver != array`

そのため `assertAppLocksDisabled()` から driver 側の検査が消えても、新規40件は緑のままです。「store 名と裏打ち driver の両方を見る」という今回の判断をテストが固定できていません。

なお、`Cache::getDefaultDriver()` から `cache_store_driver` への変更自体は承認できます。前者は既定 store 名の再観測にすぎず、後者は設定上の実装 driver まで検査します。L3目録とD登録を広げる必要はありません。

### [Warning] 回収失敗が複合した場合、一部の危険が診断から消える

対象:

- [ConcurrencyProbeRunner.php](/workspace/.claude/worktrees/tasks/T248/tests/Support/Concurrency/ConcurrencyProbeRunner.php:477)
- [ConcurrencyHarnessFailurePathTest.php](/workspace/.claude/worktrees/tasks/T248/tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php:1090)

`reap()` は秘密削除失敗を先に投げるため、同時に停止未確認の子が残っていても child ID と workspace が報告されません。また、残置 workspace の mode が不正なら `workspaceModeUnsafe()` が元の原因と停止未確認 child を保持せず上書きします。

さらに群4-40は env ファイル名だけを検査しているため、「1件目の unlink 失敗で残りの入力ファイルを試行しない」退行を防げません。例外に env、`input-a.json`、`input-b.json` の全対象が含まれることと、秘密削除失敗＋停止失敗の複合ケースを追加してください。

### [Warning] 厳格パーサが encoder の生成不能な escape を受理する

対象:

- [ProbeEnvironment.php](/workspace/.claude/worktrees/tasks/T248/tests/Support/Concurrency/ProbeEnvironment.php:224)
- [ConcurrencyHarnessFailurePathTest.php](/workspace/.claude/worktrees/tasks/T248/tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php:591)

encoder が生成する escape は `\\`、`\"`、`\$` の3種ですが、parser は `/\\\\(.)/` により `\q` など任意の escape を受理してバックスラッシュを除去します。「唯一の書式だけを受理する」「phpdotenv と同じ規則で復号する」という契約と一致しません。

許可する escape を3種へ限定し、未知 escape、重複キー、不正行それぞれの負例を追加してください。

### [Suggestion] `uri` は必須観測なのに一度も照合されない

対象:

- [ConcurrentProbeObservation.php](/workspace/.claude/worktrees/tasks/T248/tests/Support/Concurrency/ConcurrentProbeObservation.php:51)
- [ConcurrencyProbeRunner.php](/workspace/.claude/worktrees/tasks/T248/tests/Support/Concurrency/ConcurrencyProbeRunner.php:284)
- [IdempotencyClaimProcessConcurrencyTest.php](/workspace/.claude/worktrees/tasks/T248/tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php:78)

`route_name` と `request_hash` は親の期待値と比較されますが、`uri` は収集されるだけです。現在の hash 照合が実際の path を間接的に証明しているため偽陽性には直結しませんが、fail-closed schema の未使用項目です。両子の `uri === $result->uri` を検査するか、観測項目から削除してください。

### [Suggestion] workspace 削除が symlink 先のディレクトリを再帰する

対象:

- [ConcurrencyProbeRunner.php](/workspace/.claude/worktrees/tasks/T248/tests/Support/Concurrency/ConcurrencyProbeRunner.php:596)

`is_dir()` はディレクトリへの symlink でも true になるため、workspace 内に予期しない symlink ができると外部ディレクトリへ再帰します。信頼されたテスト子だけが書く前提でも、削除処理は `is_link()`/`lstat()` を先に見て symlink 自体だけを unlink する方が安全です。

## ファイルごとの判定

| ファイル | 判定 |
|---|---|
| `docs/architecture.md` | OK |
| `docs/template-divergence.md` | OK。D7据え置きの理由は一貫している |
| `IdempotencyConcurrentClaimTest.php` | OK |
| `IdempotencyClaimProcessConcurrencyTest.php` | Suggestion: `uri` 照合 |
| `OutOfTransactionFixturesTest.php` | Warning: rollback の実証不足 |
| `BarrierTimeoutException.php` | OK |
| `ConcurrencyFixtureKeys.php` | OK |
| `ConcurrencyProbeRunner.php` | Warning: 秘密の診断出力、複合回収失敗 |
| `ConcurrencyProtocolException.php` | Warning: raw stderr を保持 |
| `ConcurrentProbeObservation.php` | Warning: cache driver 負例不足 |
| `ConcurrentProbeResult.php` | OK |
| `OutOfTransactionFixtures.php` | 実装は妥当だが rollback 契約のテスト不足 |
| `ProbeDatabaseCoordinates.php` | OK |
| `ProbeEnvironment.php` | Warning: 非canonical escape の受理 |
| `ProbeLaunchSpec.php` | OK |
| `ProbeProcess.php` | OK |
| `ProbeProcessFactory.php` | OK |
| `ProcessBarrier.php` | OK。二重配置、未知合図、書きかけ分離は妥当 |
| `SignalName.php` | OK |
| `SymfonyProbeProcess.php` | OK |
| `SymfonyProbeProcessFactory.php` | OK |
| `idempotency-claim-probe.php` | Warning: 例外 trace の秘密漏えい経路 |
| `ConcurrencyHarnessFailurePathTest.php` | Warning: 上記の重要な負例が不足 |

PHPStan対象外という前提、値オブジェクトによる受け渡し、テスト専用 `JsonResponse` の使用、ready → go → entered → out → release の順序設計はいずれも妥当です。ツール制限に従い、コマンド実行・テスト再実行・ファイル変更は行っていません。

CHANGES_REQUESTED