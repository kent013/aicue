# Round 2: Round 1 指摘への対応

Round 1 の指摘 6 件 (Critical 0 / Warning 6) すべてに対応した。判断と根拠は以下のとおり。

# 対応マトリクス: impl-review Round 1

全体判定: `CHANGES_REQUESTED` (Critical 0 / Warning 6 / Suggestion 0)

## [Warning] BillingCustomerSynchronizer: 移設の主契約 (tx level 観測) が無い
- 判断: **対応する**
- 根拠: 指摘のとおり。他 6 経路には tx level 観測を置いたのに、この経路だけ
  「afterCommit フラグを持たない」+「rollback で jobs 行が残らない」しか無かった。
  後者は設計自身が「移設の検出には使えない」と明記した補助テストであり、
  呼び出し元が tx 外へ移動しても赤くならない。
- 対応内容: `tests/Feature/Billing/BillingCustomerSynchronizerTest.php` に
  `実呼び出し元 (RenameOrganizationAction) 経由でも SyncBillingCustomerDetails は業務 tx の内側で投入される`
  を追加。実 caller (`RenameOrganizationAction::execute`) 経由で `JobQueueing` の
  `DB::transactionLevel()` を観測し `baseline + 1` 以上を assert する。
  = 呼び出し元が tx の外へ出た場合も赤くなる。

## [Warning] BillingCustomerSynchronizerTest: 反転後の主張を直接検証していない
- 判断: **対応する** (上と同一の対応)
- 根拠: 同上。
- 対応内容: 同上。

## [Warning] AutoRechargeAttemptUniquenessTest が queue を database に固定していない
- 判断: **対応する**
- 根拠: 妥当。`createAttemptLocked()` が同一 tx で `ExecuteAutoRechargeAttemptJob` を投入するように
  なった結果、sync レーン (after_commit=true) では commit 直後にインライン実行されうる。
  attempt が pending から動くと「pending 検査で no-op」を見ているつもりが別要因で緑になる。
- 対応内容: `beforeEach` で `config()->set('queue.default', 'database')` を固定し、
  1 件目が `Pending` のまま残っていることも assert に追加した。

## [Warning] TicketLowBalanceNotificationIsolationTest が fake channel の呼び出しを検証していない
- 判断: **対応する**
- 根拠: 妥当。「通知経路が全く走らない」「bind が効いていない」場合でも緑になる偽グリーンだった。
- 対応内容: `ThrowingDatabaseChannel::$calls` を追加し、`expect(...)->toBeGreaterThan(0)` で
  「実際に例外が起きて握られた」ことまで固定した。

## [Warning] D5 (既定値) が truthy 値 / constructor promotion を見ていない
- 判断: **対応する** (設計の `=== true` 指定を意図的に広げる。deviations に記録)
- 根拠: 指摘が正しい。vendor の `Queue::shouldDispatchAfterCommit()` は
  `isset($job->afterCommit)` で拾った値を**真偽値文脈**で評価するため、`1` / `'yes'` でも
  commit 後ずらしが起きる。また promoted property の既定値は `getDefaultProperties()` に
  現れないため、プロパティ宣言だけを見る実装ではすり抜ける。
  設計が `=== true` を指定した理由は「`Queueable` の既定値 `null` を偽陽性にしない」ことであり、
  `null` / `false` を除外したうえで残りを違反にすれば**その目的は保たれたまま**穴が閉じる。
- 対応内容: `detectAfterCommitProperty()` の判定を「`null` でも `false` でもない」へ広げ、
  constructor promotion (`ReflectionMethod::getParameters()` + `isPromoted()`) も見るようにした。
  負のコントロールを 2 本追加 (`= 1` の truthy / promoted `= true`)、
  偽陰性コントロール (null / false) は維持。

## [Warning] deferralCandidateClasses() 自体を片側へ潰す変異が検出できない (mutation #24)
- 判断: **一部対応する (完全には閉じられないことを明示する)**
- 根拠: 指摘は正しく、mutation-evidence にも実測として記録済み。ただし原因は
  「現状 `mailableClasses()` ⊆ `shouldQueueClasses()` (Mailable 2 クラスが `implements ShouldQueue`
  を併記している)」という**リポジトリの状態**であり、app/ にダミークラスを置けない以上、
  ブラックボックスでこの変異を赤くする方法が無い (置けば禁止事項の「不必要な複雑化」+
  本番コードへのテスト専用クラス混入になる)。
- 対応内容: (1) 和集合の生成を純関数 `mergeCandidateClasses(array, array)` へ切り出し、
  disjoint な 2 集合を食わせる負のコントロールで「和を取る意図」を固定済み (Round 1 前に対応済み)。
  (2) 追加で **trip-wire テスト**
  `母集団: Mailable が ShouldQueue を併記しているあいだ和集合は degenerate である`
  を新設した。包含が崩れた瞬間 (= ShouldQueue を実装しない Mailable が現れた瞬間) に赤くなり、
  失敗メッセージで「この時点から和集合が実効を持つので (1) merge 経由であること
  (2) D3/D5 の 0 件 pin が当該 Mailable を含めて緑であること を確認せよ」と指示する。
  = 被覆の穴を**不可視のまま放置せず、転換点で必ず人間の目に触れる**形にした。


---

## 修正差分 (Round 1 の指摘に対応した 5 ファイル。**新規ファイルは全文が差分として出る**)

```diff
diff --git a/tests/Architecture/QueueDispatchAtomicityInventoryTest.php b/tests/Architecture/QueueDispatchAtomicityInventoryTest.php
new file mode 100644
index 0000000..50aa27e
--- /dev/null
+++ b/tests/Architecture/QueueDispatchAtomicityInventoryTest.php
@@ -0,0 +1,506 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Mail\InquiryReceivedMail;
+use App\Notifications\Billing\PaymentFailedNotification;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
+use Illuminate\Foundation\Bus\Dispatchable;
+use Illuminate\Mail\Mailable;
+use Symfony\Component\Finder\Finder;
+use Tests\Support\Queue\QueueDispatchDeferralInventory;
+use Tests\Support\QueuedJobPopulation;
+
+/*
+|--------------------------------------------------------------------------
+| キュー投入の commit 後ずらしを deny-by-default で 0 件に固定する (AG-114 確定 1 / AG-126)
+|--------------------------------------------------------------------------
+|
+| ★ **allow-list を持たない deny-by-default** である。免除目録 (enum) は**作っていない** —
+|   確定 1 の queue dispatch 母集団における除外が 0 件だからで、case を 1 つも持たない
+|   exemption enum は死んだ機構になる (思考原則 2)。将来除外が必要になったら
+|   この gate が落ちるので、そのときに免除機構ごと設計し直すこと。
+|
+| ★ D1/D2/D5(代入) は **token 走査** (PhpTokenScan) で行い、コメント・docblock・
+|   文字列リテラルは対象外にする。素の grep にすると契約の反転 docblock
+|   (旧主張として `->afterCommit()` を引用する) で gate が落ちてしまう。
+|
+| ★ 母集団の**完全性**そのものは QueuedJobLeaseInventoryTest / JobExecutionDedupInventoryTest が
+|   対称差 0 で既に固定しているため、本 gate では 0 件 fail のみとし二重実装しない。
+|
+| ★ 保証しないもの: token 走査でも**動的な迂回**には沈黙する —
+|   `$m = 'afterCommit'; $job->$m();` / helper・facade alias で包んだ呼び出し /
+|   `$this->afterCommit = $flag;` のような動的値 / vendor 内の afterCommit 使用。
+|   また **「dispatch が業務 tx の内側にあること」の静的完全性は保証しない** —
+|   固定するのは「commit 後ずらしの機構を使っていないこと」までである。
+|   (D3 と D5(既定値) はリフレクション判定なので中間 interface・親クラス経由まで拾う)
+*/
+
+/**
+ * 期待ルート集合を**テスト側でリテラルに独立固定**する。
+ *
+ * ★ これが要である。テスト 5 (対称差) と テスト 6 (ルート単位 0 件 fail) が両方とも
+ *   `RUNTIME_ROOTS` を参照していると、**定数から `routes` を消したときに実装列挙と
+ *   Finder 列挙が同時に狭まり、どちらも通ってしまう**。
+ *
+ * @var list<string>
+ */
+const QUEUE_DEFERRAL_EXPECTED_ROOTS = ['app', 'routes', 'bootstrap', 'database', 'config'];
+
+/** base_path() からの相対パス表示 (失敗メッセージ用)。 */
+function queueDeferralRelativePath(string $path): string
+{
+    $base = base_path().DIRECTORY_SEPARATOR;
+
+    return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
+}
+
+/** 検出結果を「相対パス:行」の list に整形する。 */
+function queueDeferralFormatHits(array $hits): array
+{
+    return array_map(
+        static fn (array $hit): string => queueDeferralRelativePath((string) $hit['path']).':'.$hit['line'],
+        $hits,
+    );
+}
+
+/** プロセスごとに一意な fixture ディレクトリを作る (--parallel での衝突回避)。 */
+function queueDeferralFixtureRoot(): string
+{
+    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'queue-deferral-'.getmypid().'-'.uniqid();
+    mkdir($root.DIRECTORY_SEPARATOR.'nested', 0o777, true);
+
+    return $root;
+}
+
+/** fixture ディレクトリを再帰削除する。 */
+function queueDeferralRemoveFixture(string $root): void
+{
+    if (! is_dir($root)) {
+        return;
+    }
+
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
+        RecursiveIteratorIterator::CHILD_FIRST,
+    );
+    foreach ($iterator as $file) {
+        $path = (string) $file;
+        is_dir($path) ? rmdir($path) : unlink($path);
+    }
+    rmdir($root);
+}
+
+// ------------------------------------------------------------------
+// ダミークラス (D3 / D5(既定値) の負のコントロール。リポジトリ内に fixture PHP を置かない)
+// ------------------------------------------------------------------
+
+/** D3 の負のコントロール: ShouldQueueAfterCommit を implement する job。 */
+final class DeferralProbeAfterCommitJob implements ShouldQueue, ShouldQueueAfterCommit
+{
+    use Dispatchable;
+    use Queueable;
+}
+
+/** D3 の負のコントロール: ShouldHandleEventsAfterCommit を implement する listener。 */
+final class DeferralProbeAfterCommitListener implements ShouldHandleEventsAfterCommit, ShouldQueue
+{
+    use Queueable;
+}
+
+/** D5 (既定値) の負のコントロール: $afterCommit の既定値が true な job。 */
+final class DeferralProbeAfterCommitPropertyJob implements ShouldQueue
+{
+    use Dispatchable;
+
+    /**
+     * ★ `Illuminate\Bus\Queueable` trait は使わない。同 trait が `public $afterCommit;`
+     *   (型なし・既定値なし) を持つため、既定値付きで再宣言すると composition が fatal になる
+     *   (= PHP の言語仕様上、Queueable を使うクラスではこの迂回路そのものが書けない)。
+     *   既定値 true が現実に書けるのは Mailable のように trait を使わないクラスである。
+     *
+     * @var bool
+     */
+    public $afterCommit = true;
+}
+
+/** D5 (既定値) の偽陰性コントロール: 既定値が null / false のクラスは検出しない。 */
+final class DeferralProbeNullAfterCommitJob implements ShouldQueue
+{
+    use Dispatchable;
+    use Queueable;
+}
+
+/** D5 (既定値) の偽陰性コントロール: 明示 false。 */
+final class DeferralProbeFalseAfterCommitJob implements ShouldQueue
+{
+    use Dispatchable;
+
+    /** @var bool */
+    public $afterCommit = false;
+}
+
+/** D5 (既定値) の負のコントロール: truthy だが `true` ではない既定値 (vendor は真偽値文脈で見る)。 */
+final class DeferralProbeTruthyAfterCommitJob implements ShouldQueue
+{
+    use Dispatchable;
+
+    /** @var int */
+    public $afterCommit = 1;
+}
+
+/** D5 (既定値) の負のコントロール: constructor promotion で既定値を持たせた形。 */
+final class DeferralProbePromotedAfterCommitJob implements ShouldQueue
+{
+    use Dispatchable;
+
+    public function __construct(public bool $afterCommit = true) {}
+}
+
+/** D5 (既定値) の負のコントロール: **ShouldQueue を実装しない** Mailable。 */
+final class DeferralProbeMailable extends Mailable
+{
+    /** @var bool */
+    public $afterCommit = true;
+}
+
+// ------------------------------------------------------------------
+// 0 件 pin
+// ------------------------------------------------------------------
+
+test('D1: first-party ランタイム PHP に ->afterCommit() の呼び出しは 1 件も無い', function (): void {
+    $hits = array_values(array_filter(
+        QueueDispatchDeferralInventory::detectInFiles(QueueDispatchDeferralInventory::runtimePhpFiles()),
+        static fn (array $hit): bool => $hit['kind'] === 'D1',
+    ));
+
+    expect(queueDeferralFormatHits($hits))->toBe(
+        [],
+        'キュー投入を commit 後へずらす ->afterCommit() が残っています。業務 tx の内側で'
+        .'素直に dispatch してください (AG-114 確定 1)。',
+    );
+});
+
+test('D2: first-party ランタイム PHP に DB::afterCommit() の呼び出しは 1 件も無い', function (): void {
+    $hits = array_values(array_filter(
+        QueueDispatchDeferralInventory::detectInFiles(QueueDispatchDeferralInventory::runtimePhpFiles()),
+        static fn (array $hit): bool => $hit['kind'] === 'D2',
+    ));
+
+    expect(queueDeferralFormatHits($hits))->toBe(
+        [],
+        'DB::afterCommit() への退避が残っています。付随的副作用 (AG-127) は tx の外へ出し、'
+        .'キュー投入は業務 tx の内側で行ってください。',
+    );
+});
+
+test('D3: 母集団に ShouldQueueAfterCommit / ShouldHandleEventsAfterCommit を implement するクラスは 1 件も無い', function (): void {
+    $hits = QueueDispatchDeferralInventory::detectAfterCommitInterfaces(
+        QueueDispatchDeferralInventory::deferralCandidateClasses(),
+    );
+
+    expect($hits)->toBe(
+        [],
+        '宣言的迂回 (grep afterCommit では見えない) が残っています: '.implode(', ', $hits),
+    );
+});
+
+test('D4: after_commit=true を持ってよい接続は sync だけである', function (): void {
+    $connections = config('queue.connections');
+    expect($connections)->toBeArray();
+
+    $hits = QueueDispatchDeferralInventory::detectAfterCommitEnabledConnections((array) $connections);
+
+    expect($hits)->toBe([], 'sync 以外の接続で after_commit=true になっています: '.implode(', ', $hits));
+});
+
+test('D5: 母集団に $afterCommit の既定値が true のクラスは 1 件も無い', function (): void {
+    $hits = QueueDispatchDeferralInventory::detectAfterCommitProperty(
+        QueueDispatchDeferralInventory::deferralCandidateClasses(),
+    );
+
+    expect($hits)->toBe([], '$afterCommit の既定値が true のクラスがあります: '.implode(', ', $hits));
+});
+
+test('D5: first-party ランタイム PHP に $afterCommit への true 代入は 1 件も無い', function (): void {
+    $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
+        QueueDispatchDeferralInventory::runtimePhpFiles(),
+    );
+
+    expect(queueDeferralFormatHits($hits))->toBe([], '$afterCommit への true 代入が残っています。');
+});
+
+// ------------------------------------------------------------------
+// 母集団の境界と 0 件 fail
+// ------------------------------------------------------------------
+
+test('母集団: runtimePhpFiles() は Finder による独立列挙と対称差が空である', function (): void {
+    $finder = Finder::create()
+        ->in(array_map(static fn (string $root): string => base_path($root), QUEUE_DEFERRAL_EXPECTED_ROOTS))
+        ->files()
+        ->name('*.php');
+
+    $expected = [];
+    foreach ($finder as $file) {
+        $expected[] = (string) $file->getRealPath();
+    }
+    sort($expected);
+
+    $actual = QueueDispatchDeferralInventory::runtimePhpFiles();
+
+    expect(array_values(array_diff($expected, $actual)))->toBe([], '実装列挙から漏れているファイルがあります');
+    expect(array_values(array_diff($actual, $expected)))->toBe([], '実装列挙に余分なファイルがあります');
+});
+
+test('母集団: RUNTIME_ROOTS はテスト側で独立に固定した期待ルート集合と一致する', function (): void {
+    expect(QueueDispatchDeferralInventory::RUNTIME_ROOTS)
+        ->toEqualCanonicalizing(QUEUE_DEFERRAL_EXPECTED_ROOTS);
+});
+
+test('母集団: 期待ルート集合の各ルートについて 1 件以上のファイルが列挙される', function (): void {
+    // ★ ループはテスト側リテラルを回す (定数を回すと定数を削るだけで空振りする)
+    foreach (QUEUE_DEFERRAL_EXPECTED_ROOTS as $root) {
+        $files = QueueDispatchDeferralInventory::phpFilesUnder([base_path($root)]);
+
+        expect(count($files))->toBeGreaterThan(0, "ルート {$root} から 1 件も列挙されていません");
+    }
+});
+
+test('母集団: ShouldQueue 実装クラスの列挙は 0 件でない', function (): void {
+    expect(count(QueuedJobPopulation::shouldQueueClasses()))->toBeGreaterThan(0);
+});
+
+test('母集団: Mailable subclass の列挙は 0 件でない', function (): void {
+    expect(QueuedJobPopulation::mailableClasses())->toContain(InquiryReceivedMail::class);
+});
+
+test('母集団: deferralCandidateClasses() は unique(shouldQueueClasses ∪ mailableClasses) と一致し、Mailable 全件を含む', function (): void {
+    $expected = array_values(array_unique(array_merge(
+        QueuedJobPopulation::shouldQueueClasses(),
+        QueuedJobPopulation::mailableClasses(),
+    )));
+    sort($expected);
+
+    $actual = QueueDispatchDeferralInventory::deferralCandidateClasses();
+
+    expect($actual)->toBe($expected);
+    foreach (QueuedJobPopulation::mailableClasses() as $mailable) {
+        expect($actual)->toContain($mailable);
+    }
+    // ShouldQueue 側の代表 (Notification) も含まれている = 和集合が片側に潰れていない
+    expect($actual)->toContain(PaymentFailedNotification::class);
+});
+
+test('母集団: Mailable が ShouldQueue を併記しているあいだ和集合は degenerate である (trip-wire)', function (): void {
+    // ★ **これは「壊れたら直す」テストではなく、被覆の穴を可視化する trip-wire である。**
+    //   現状 mailableClasses() ⊆ shouldQueueClasses() のため、deferralCandidateClasses() を
+    //   shouldQueueClasses() 片側へ潰す変異は**結果が変わらず検出できない** (mutation #24 の実測)。
+    //   この包含が崩れた瞬間 (= Mailable から implements ShouldQueue が外れた瞬間) に、
+    //   和集合は実効を持ち D3 / D5 の 0 件 pin が Mailable を独立に見るようになる。
+    //   本テストはその転換点を**赤で知らせる**ためのものである。
+    $outside = array_values(array_diff(
+        QueuedJobPopulation::mailableClasses(),
+        QueuedJobPopulation::shouldQueueClasses(),
+    ));
+
+    expect($outside)->toBe(
+        [],
+        'ShouldQueue を実装しない Mailable が現れました: '.implode(', ', $outside).PHP_EOL
+        .'これ自体は不具合ではありません。ただしこの時点から deferralCandidateClasses() の'
+        .'和集合が実効を持つため、(1) 同関数が今も mergeCandidateClasses() 経由であること、'
+        .'(2) D3 / D5 の 0 件 pin が当該 Mailable を含めて緑であること を確認し、'
+        .'確認できたら本 trip-wire の期待値をその Mailable を含む形へ更新してください'
+        .' (devnotes の mutation-evidence #24 参照)。',
+    );
+});
+
+test('負のコントロール: mergeCandidateClasses() は disjoint な 2 集合の和を返す (片側に潰れていない)', function (): void {
+    // ★ 現状 mailableClasses() ⊆ shouldQueueClasses() のため、deferralCandidateClasses() を
+    //   片側へ潰す変異は**実結果が変わらず検出できない**。和集合を取る意図そのものは
+    //   ここで disjoint な集合を食わせて固定する (併記が外れて集合が乖離した瞬間に効く)。
+    $merged = QueueDispatchDeferralInventory::mergeCandidateClasses(
+        [DeferralProbeAfterCommitJob::class],
+        [DeferralProbeMailable::class],
+    );
+
+    expect($merged)->toContain(DeferralProbeAfterCommitJob::class);
+    expect($merged)->toContain(DeferralProbeMailable::class);
+    expect($merged)->toHaveCount(2);
+});
+
+test('母集団: queue.connections は 0 件でない', function (): void {
+    $connections = config('queue.connections');
+    expect($connections)->toBeArray();
+    expect(count((array) $connections))->toBeGreaterThan(0);
+});
+
+// ------------------------------------------------------------------
+// 負のコントロール (検出器が生きていることの固定)
+// ------------------------------------------------------------------
+
+test('負のコントロール: fixture ツリーを列挙して D1 を検出する', function (): void {
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\nSomeJob::dispatch(1)->afterCommit();\n",
+        );
+
+        $hits = QueueDispatchDeferralInventory::detectInFiles(
+            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
+        );
+
+        expect(array_column($hits, 'kind'))->toContain('D1');
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+test('負のコントロール: fixture ツリーを列挙して D2 を検出する', function (): void {
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\nDB::afterCommit(static fn () => null);\n",
+        );
+
+        $hits = QueueDispatchDeferralInventory::detectInFiles(
+            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
+        );
+
+        expect(array_column($hits, 'kind'))->toContain('D2');
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+test('負のコントロール: ShouldQueueAfterCommit 実装ダミークラスを D3 が検出する', function (): void {
+    expect(QueueDispatchDeferralInventory::detectAfterCommitInterfaces([DeferralProbeAfterCommitJob::class]))
+        ->toBe([DeferralProbeAfterCommitJob::class]);
+});
+
+test('負のコントロール: ShouldHandleEventsAfterCommit 実装ダミークラスを D3 が検出する', function (): void {
+    expect(QueueDispatchDeferralInventory::detectAfterCommitInterfaces([DeferralProbeAfterCommitListener::class]))
+        ->toBe([DeferralProbeAfterCommitListener::class]);
+});
+
+test('負のコントロール: after_commit=true の非 sync 接続を D4 が検出する', function (): void {
+    $hits = QueueDispatchDeferralInventory::detectAfterCommitEnabledConnections([
+        'sync' => ['driver' => 'sync', 'after_commit' => true],
+        'database' => ['driver' => 'database', 'after_commit' => false],
+        'database-analysis' => ['driver' => 'database', 'after_commit' => true],
+    ]);
+
+    expect($hits)->toBe(['database-analysis']);
+});
+
+test('負のコントロール: $afterCommit = true を持つダミー job クラスを D5 (既定値) が検出する', function (): void {
+    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbeAfterCommitPropertyJob::class]))
+        ->toBe([DeferralProbeAfterCommitPropertyJob::class]);
+});
+
+test('負のコントロール: ShouldQueue を実装しないダミー Mailable の $afterCommit = true を D5 (既定値) が検出する', function (): void {
+    // Mailable を母集団に含めない実装では、このクラスは検出器へ届かない
+    expect(is_subclass_of(DeferralProbeMailable::class, ShouldQueue::class))->toBeFalse();
+    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbeMailable::class]))
+        ->toBe([DeferralProbeMailable::class]);
+});
+
+test('負のコントロール: $this->afterCommit = true; を含む fixture を D5 (代入) が検出する', function (): void {
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\nfinal class Probe\n{\n    public function __construct()\n    {\n        \$this->afterCommit = true;\n    }\n}\n",
+        );
+
+        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
+            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
+        );
+
+        expect($hits)->toHaveCount(1);
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+test('負のコントロール: $job->afterCommit = true; (外部からの代入) も D5 (代入) が検出する', function (): void {
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\n\$job = new SomeJob;\n\$job->afterCommit = true;\n",
+        );
+
+        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
+            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
+        );
+
+        expect($hits)->toHaveCount(1);
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+test('負のコントロール: truthy だが true ではない既定値 (= 1) も D5 (既定値) が検出する', function (): void {
+    // vendor の Queue::shouldDispatchAfterCommit() は isset() で拾った値を真偽値文脈で評価するため、
+    // `=== true` だけを見る実装ではこの迂回路がすり抜ける
+    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbeTruthyAfterCommitJob::class]))
+        ->toBe([DeferralProbeTruthyAfterCommitJob::class]);
+});
+
+test('負のコントロール: constructor promotion で持たせた $afterCommit = true も D5 (既定値) が検出する', function (): void {
+    // promoted property の既定値は getDefaultProperties() に現れないため、
+    // プロパティ宣言だけを見る実装ではすり抜ける
+    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbePromotedAfterCommitJob::class]))
+        ->toBe([DeferralProbePromotedAfterCommitJob::class]);
+});
+
+test('偽陰性の負のコントロール: $afterCommit の既定値が null / false のクラスは D5 (既定値) が検出しない', function (): void {
+    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([
+        DeferralProbeNullAfterCommitJob::class,
+        DeferralProbeFalseAfterCommitJob::class,
+    ]))->toBe([]);
+});
+
+test('偽陽性の負のコントロール: コメント / docblock / 文字列リテラル中の ->afterCommit() は検出しない', function (): void {
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\n/**\n * 旧主張: SomeJob::dispatch()->afterCommit() で commit 後に発火する\n */\n"
+            ."// DB::afterCommit(fn () => null);\n"
+            ."\$sql = 'SomeJob::dispatch()->afterCommit()';\n"
+            ."\$assignment = '\$this->afterCommit = true;';\n",
+        );
+
+        $paths = QueueDispatchDeferralInventory::phpFilesUnder([$root]);
+
+        expect(QueueDispatchDeferralInventory::detectInFiles($paths))->toBe([]);
+        expect(QueueDispatchDeferralInventory::detectAfterCommitAssignments($paths))->toBe([]);
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+// ------------------------------------------------------------------
+// 契約の固定 (黙って 0 件を返さない)
+// ------------------------------------------------------------------
+
+test('phpFilesUnder(): 相対パスを渡すと例外になる', function (): void {
+    expect(fn () => QueueDispatchDeferralInventory::phpFilesUnder(['app']))
+        ->toThrow(InvalidArgumentException::class);
+});
+
+test('phpFilesUnder(): 存在しないディレクトリを渡すと例外になる (黙って 0 件を返さない)', function (): void {
+    expect(fn () => QueueDispatchDeferralInventory::phpFilesUnder([base_path('no-such-directory')]))
+        ->toThrow(InvalidArgumentException::class);
+});
diff --git a/tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php b/tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php
new file mode 100644
index 0000000..0e0fec0
--- /dev/null
+++ b/tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php
@@ -0,0 +1,125 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
+use App\Enums\Billing\AutoRechargeAttemptStatus;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use Illuminate\Database\QueryException;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Str;
+use Tests\Support\FakeAutoRechargeGateway;
+
+/*
+|--------------------------------------------------------------------------
+| ShouldBeUnique 撤去後の「結果の一回性」 (AG-114 確定 1 / AGENTS.md ドメイン規約 6)
+|--------------------------------------------------------------------------
+|
+| 入口排他 (ShouldBeUnique) は撤去された。一回性を担うのは 3 点:
+|  (1) maybeCreateAttempt の organizations 行ロック + pending 存在検査
+|  (2) tar_attempts_org_pending_unique (partial unique) — DB の最終防衛
+|  (3) unique violation の no-op 収束 (呼び出し側へ例外を漏らさない)
+|
+| ★ (3) の判定 (isUniqueViolation) は SQLSTATE だけを見て制約名を識別しない。
+|   これは本 PR で作った問題ではなく、docs/TODO.md へ Low で追跡起票済みである。
+*/
+
+beforeEach(function (): void {
+    // ★ 実 jobs 表へ積むだけの構成に固定する。sync レーン (after_commit=true) のままだと
+    //   起票と同一 tx で投入された ExecuteAutoRechargeAttemptJob が commit 直後に
+    //   インライン実行され、attempt が pending から動いてしまう
+    //   (「pending があるから 2 件目は no-op」を見ているつもりが別要因で緑になる偽グリーン)。
+    config()->set('queue.default', 'database');
+    $this->gateway = new FakeAutoRechargeGateway;
+    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
+});
+
+/**
+ * 閾値割れ + enabled な組織。
+ *
+ * @return array{Organization, User}
+ */
+function attemptUniquenessContext(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    /** @var FakeAutoRechargeGateway $gateway */
+    $gateway = app(AutoRechargeGatewayInterface::class);
+    $gateway->withDefaultPaymentMethod();
+    app(AutoRechargeService::class)->updateSettings(
+        $organization,
+        $owner,
+        enabled: true,
+        threshold: 5,
+        max: 50,
+        consent: new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
+    );
+
+    return [$organization, $owner];
+}
+
+test('pending attempt があるとき maybeCreateAttempt は null を返し attempt が増えない', function (): void {
+    [$organization] = attemptUniquenessContext();
+
+    $first = app(AutoRechargeService::class)->maybeCreateAttempt($organization);
+    expect($first)->not->toBeNull();
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
+
+    $second = app(AutoRechargeService::class)->maybeCreateAttempt($organization->refresh());
+
+    expect($second)->toBeNull();
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
+    // 1 件目が pending のまま残っていること (= no-op の理由が pending 検査であること) まで固定する
+    expect(TicketAutoRechargeAttempt::query()->firstOrFail()->status)
+        ->toBe(AutoRechargeAttemptStatus::Pending);
+});
+
+test('同一 org の 2 件目の pending 行は tar_attempts_org_pending_unique が拒否する', function (): void {
+    [$organization] = attemptUniquenessContext();
+    $first = app(AutoRechargeService::class)->maybeCreateAttempt($organization);
+    expect($first)->not->toBeNull();
+
+    // pending 検査を迂回して直接 INSERT する = DB 制約が最終防衛であることの固定。
+    // ★ PostgreSQL は失敗した文でトランザクション全体を abort させるため、
+    //   savepoint (ネストした DB::transaction) の中で起こして外側を巻き込まない。
+    expect(fn () => DB::transaction(fn () => DB::table('ticket_auto_recharge_attempts')->insert([
+        'organization_id' => $organization->getKey(),
+        'attempt_ulid' => strtolower((string) Str::ulid()),
+        'status' => AutoRechargeAttemptStatus::Pending->value,
+        'quantity' => 10,
+        'unit_amount' => 70,
+        'stripe_price_id' => 'price_test',
+        'created_at' => now(),
+        'updated_at' => now(),
+    ])))->toThrow(QueryException::class);
+
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
+});
+
+test('unique violation は no-op へ収束し呼び出し側へ例外が漏れない', function (): void {
+    [$organization] = attemptUniquenessContext();
+
+    // pending 検査の**後**・INSERT の**直前**に別経路で pending 行が生まれた状況
+    // (= 並行起票の敗者側) を模す。DB::table は model event を発火しないため再入しない。
+    TicketAutoRechargeAttempt::creating(function () use ($organization): void {
+        DB::table('ticket_auto_recharge_attempts')->insert([
+            'organization_id' => $organization->getKey(),
+            'attempt_ulid' => strtolower((string) Str::ulid()),
+            'status' => AutoRechargeAttemptStatus::Pending->value,
+            'quantity' => 10,
+            'unit_amount' => 70,
+            'stripe_price_id' => 'price_race',
+            'created_at' => now(),
+            'updated_at' => now(),
+        ]);
+    });
+
+    $result = app(AutoRechargeService::class)->maybeCreateAttempt($organization);
+
+    // 例外は漏れず null に収束し、tx ごと巻き戻るため attempt 行も残らない
+    expect($result)->toBeNull();
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
+});
diff --git a/tests/Feature/Billing/BillingCustomerSynchronizerTest.php b/tests/Feature/Billing/BillingCustomerSynchronizerTest.php
index 6ac59a1..3c185d6 100644
--- a/tests/Feature/Billing/BillingCustomerSynchronizerTest.php
+++ b/tests/Feature/Billing/BillingCustomerSynchronizerTest.php
@@ -2,17 +2,19 @@
 
 declare(strict_types=1);
 
+use App\Actions\Organizations\RenameOrganizationAction;
 use App\Jobs\Billing\SyncBillingCustomerDetails;
 use App\Services\Billing\BillingCustomerSynchronizer;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
 use App\Services\Billing\Fakes\FakeStripeGateway;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Queue;
+use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;
 
 /*
  * BillingCustomerSynchronizer: Stripe customer 同期 job の dispatch を集約する単一窓口。
  * - Stripe customer 未作成 (stripe_id === null) は no-op (例外にしない)
- * - dispatch は afterCommit (transaction rollback では発火しない)
+ * - dispatch は業務 tx の内側 (jobs 行が同一 tx に乗り、rollback では jobs 行ごと巻き戻る)
  */
 
 function synchronizer(): BillingCustomerSynchronizer
@@ -43,13 +45,17 @@ function synchronizer(): BillingCustomerSynchronizer
     );
 });
 
-/*
- * IV-3 (commit 前の stale read を防ぐ) の固定。job が afterCommit フラグを立てて積まれることを
- * 検証する。「rollback では発火しない」という実挙動そのものは Queue::fake では観測できない
- * (QueueFake は afterCommit を解決する Queue::enqueueUsing を経由せず即時記録するため)。
- * afterCommit フラグ = 実 queue driver における「outer commit 後に発火」の唯一の入力。
+/**
+ * 【契約の反転 (AG-114 確定 1 / T137)】
+ * - 旧主張: dispatch した job は afterCommit フラグを持つ (outer commit 後に発火する)
+ * - 旧目的: commit 前の値を Stripe へ送る stale read を防ぐ (IV-3)
+ * - 新主張: job は afterCommit フラグを**持たない**。投入は業務 tx に乗る
+ * - 新前提: jobs 行が業務 tx と同一トランザクションにあるため、可視化は commit 後になる
+ * - 前提を守る機構: QueueDispatchAtomicityGuard (R1〜R3) + QueueDispatchAtomicityInventoryTest (D1)
+ * - 反転根拠: afterCommit は「commit したのに未投入」の窓を残す。job は organization を
+ *   ID で直列化して handle 時に再取得するため、IV-3 は afterCommit なしで保たれる
  */
-test('dispatch した job は afterCommit フラグを持つ (outer commit 後に発火する)', function (): void {
+test('dispatch した job は afterCommit フラグを持たない (業務 tx に乗る)', function (): void {
     Queue::fake();
     [$organization] = createOrganizationWithOwner();
     $organization->forceFill(['stripe_id' => 'cus_test_2'])->save();
@@ -58,8 +64,64 @@ function synchronizer(): BillingCustomerSynchronizer
 
     Queue::assertPushed(
         SyncBillingCustomerDetails::class,
-        fn (SyncBillingCustomerDetails $job): bool => $job->afterCommit === true,
+        fn (SyncBillingCustomerDetails $job): bool => $job->afterCommit !== true,
+    );
+});
+
+/**
+ * 実挙動 (jobs 行が業務 tx に乗ること) は **実 jobs 表**で観測する。
+ * `Queue::fake()` では検証できない (QueueFake::push は enqueueUsing を通らない)。
+ */
+test('外側 tx が rollback すると jobs 行が残らない', function (): void {
+    config()->set('queue.default', 'database');
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['stripe_id' => 'cus_test_rollback'])->save();
+    $before = DB::table('jobs')->count();
+
+    try {
+        DB::transaction(function () use ($organization): void {
+            synchronizer()->dispatchFor($organization);
+
+            throw new RuntimeException('意図的な rollback');
+        });
+    } catch (RuntimeException) {
+        // 期待どおり
+    }
+
+    expect(DB::table('jobs')->count())->toBe($before);
+});
+
+/**
+ * **主契約** (移設を検出するのはこれだけ)。実際の呼び出し元 (RenameOrganizationAction) 経由で
+ * `JobQueueing` の tx level を観測する。呼び出し元が tx の外へ移動したら赤くなる。
+ * rollback テストは補助であり、旧実装 (afterCommit) でも緑になるため移設の検出には使えない。
+ */
+test('実呼び出し元 (RenameOrganizationAction) 経由でも SyncBillingCustomerDetails は業務 tx の内側で投入される', function (): void {
+    config()->set('queue.default', 'database');
+    expect(config('queue.connections.database.after_commit'))->toBeFalse();
+
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['stripe_id' => 'cus_test_txlevel'])->save();
+
+    $baseline = DB::transactionLevel();
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => app(RenameOrganizationAction::class)->execute($organization, '新しい組織名'),
     );
+    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), SyncBillingCustomerDetails::class);
+
+    expect($target)->toHaveCount(1);
+    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
+});
+
+test('業務 tx の内側で dispatch した job は commit 後に jobs 行として残る', function (): void {
+    config()->set('queue.default', 'database');
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['stripe_id' => 'cus_test_commit'])->save();
+    $before = DB::table('jobs')->count();
+
+    DB::transaction(fn () => synchronizer()->dispatchFor($organization));
+
+    expect(DB::table('jobs')->count())->toBe($before + 1);
 });
 
 test('job は StripeGatewayInterface へ委譲する (fake bind 時は実 Stripe を叩かない)', function (): void {
diff --git a/tests/Feature/Billing/TicketLowBalanceNotificationIsolationTest.php b/tests/Feature/Billing/TicketLowBalanceNotificationIsolationTest.php
new file mode 100644
index 0000000..2a7e2ee
--- /dev/null
+++ b/tests/Feature/Billing/TicketLowBalanceNotificationIsolationTest.php
@@ -0,0 +1,58 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Billing\TicketReservation;
+use App\Services\Billing\TicketLedgerService;
+use Illuminate\Notifications\Channels\DatabaseChannel;
+use Illuminate\Notifications\Notification;
+use Illuminate\Support\Facades\Log;
+
+/*
+|--------------------------------------------------------------------------
+| 低残高通知の隔離 (AG-127 の付随的副作用)
+|--------------------------------------------------------------------------
+|
+| 通知は reserve の tx を抜けた最後に同期実行される。**通知チャネルの例外で reserve を
+| 巻き戻さない**ことを固定する (NotificationCenterService::safely() 本体を通す =
+| サービス全体を mock しない)。
+|
+| ★ 保証範囲を誇張しない: ここが保証するのは**アプリケーション層の例外分離だけ**である。
+|   reserve が呼び出し側の tx にネストされている場合、通知 INSERT の SQL 層の失敗は
+|   PostgreSQL の transaction abort を経て業務操作ごと失敗させうる (設計 §保証しないもの 6)。
+*/
+
+/** database channel を必ず throw する fake へ差し替える (呼び出し回数を記録する)。 */
+final class ThrowingDatabaseChannel extends DatabaseChannel
+{
+    /** 実際に通知経路を通ったことを assert するためのカウンタ。 */
+    public static int $calls = 0;
+
+    public function send($notifiable, Notification $notification): void
+    {
+        self::$calls++;
+
+        throw new RuntimeException('通知チャネルの意図的な失敗');
+    }
+}
+
+test('通知チャネルが例外を投げても reserve は成功し予約行が残る', function (): void {
+    Log::spy();
+    ThrowingDatabaseChannel::$calls = 0;
+    config()->set('billing.ticket_low_balance_threshold', 5);
+    app()->bind(DatabaseChannel::class, ThrowingDatabaseChannel::class);
+
+    [$organization] = createOrganizationWithOwner();
+    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');
+
+    // 10 → 4 で閾値 5 を跨ぐ = 通知が走る (そして必ず throw する)
+    $reservation = app(TicketLedgerService::class)->reserve($organization, 6);
+
+    // ★ 「通知経路が全く走らなかった」場合でも緑になる偽グリーンを塞ぐ:
+    //   fake channel が実際に呼ばれ、例外が握られたことまで固定する
+    //   (owner/admin 宛のため 1 回以上。人数は組織構成に依存するので下限で見る)
+    expect(ThrowingDatabaseChannel::$calls)->toBeGreaterThan(0);
+    expect($reservation->amount)->toBe(6);
+    expect(TicketReservation::query()->whereKey($reservation->getKey())->exists())->toBeTrue();
+    expect(app(TicketLedgerService::class)->availableTrueBalance($organization))->toBe(4);
+});
diff --git a/tests/Support/Queue/QueueDispatchDeferralInventory.php b/tests/Support/Queue/QueueDispatchDeferralInventory.php
new file mode 100644
index 0000000..5d9f39d
--- /dev/null
+++ b/tests/Support/Queue/QueueDispatchDeferralInventory.php
@@ -0,0 +1,369 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Queue;
+
+use FilesystemIterator;
+use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
+use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
+use RecursiveDirectoryIterator;
+use RecursiveIteratorIterator;
+use ReflectionClass;
+use SplFileInfo;
+use Tests\Support\PhpTokenScan;
+use Tests\Support\QueuedJobPopulation;
+use Webmozart\Assert\Assert;
+
+/**
+ * キュー投入の commit 後ずらし (deferral) を検出する純関数群。
+ *
+ * 【5 種の検出器】`Queue::shouldDispatchAfterCommit()` の解決順
+ * (ShouldQueueAfterCommit → job の `$afterCommit` プロパティ → 接続 config) に
+ * 1:1 で対応させ、どの層からも迂回できないようにしている。
+ * - D1 `->afterCommit(` / `?->afterCommit(`  … PendingDispatch の明示指定
+ * - D2 `DB::afterCommit(`                    … トランザクション callback への退避
+ * - D3 宣言的迂回 interface の実装            … **リフレクション判定** (文字列走査ではない)。
+ *   `ShouldQueueAfterCommit` に加え **`ShouldHandleEventsAfterCommit`** も見る
+ *   (`Events\Dispatcher::handlerShouldBeDispatchedAfterDatabaseTransactions()` が
+ *   この interface でも commit 後ずらしを発動するため。ShouldQueue な listener では
+ *   これが**キュー投入そのもの**を commit 後へずらす)
+ * - D4 config の `after_commit => true`       … sync 以外の接続
+ * - D5 `Queueable` の `$afterCommit` プロパティ … **既定値はリフレクション** +
+ *   **実行時代入は token 走査**。`public bool $afterCommit = true;` /
+ *   `$this->afterCommit = true;` は **D1〜D4 のどれにも映らない第 3 の迂回路**であり、
+ *   これを落とすと「0 件 pin」の主張が嘘になる
+ *
+ * 【D3 / D5(既定値) の母集団は `ShouldQueue` 実装だけでは足りない — Mailable を足す】
+ * `Mailable` は **`ShouldQueue` を実装していなくても** `Mail::to(...)->queue()` /
+ * `Mail::queue()` でキューへ載る。このとき vendor の `SendQueuedMailable::__construct()` が
+ * `$mailable instanceof ShouldQueueAfterCommit ? true : ($mailable->afterCommit ?? null)` を
+ * **wrapper job へコピーする**ため、非 `ShouldQueue` な Mailable の
+ * `public $afterCommit = true;` / `implements ShouldQueueAfterCommit` が
+ * そのまま commit 後ずらしになる。
+ * **本リポジトリでは現に `CreateInquiryAction` が `Mail::to(...)->queue(...)` を使っている**
+ * (仮想の穴ではない。現行 2 クラスは `ShouldQueue` を併記しているので今は母集団に入るが、
+ * 併記を外した瞬間に検出器から消える)。
+ * よって D3 / D5(既定値) の母集団は
+ * **`QueuedJobPopulation::shouldQueueClasses()` ∪ `QueuedJobPopulation::mailableClasses()`** とする。
+ * Notification と listener は vendor 側 (`NotificationSender` / `Events\Dispatcher`) が
+ * `ShouldQueue` を要求するため `shouldQueueClasses()` で尽きており、追加は要らない。
+ *
+ * 【D3 を文字列走査にしない理由】文字列走査だと「`ShouldQueueAfterCommit` を継承した中間
+ * interface を implement する」「親クラス経由で implement される」形を丸ごと見落とす。
+ * 家系の申し送り (「grep afterCommit は interface 名に一致しないので宣言的迂回が丸ごと
+ * 見えない」) への正しい応答は、grep を強化することではなく判定を型システム側へ移すこと。
+ *
+ * 【D1 / D2 / D5(代入) は「文字列 grep」ではなく token 走査で行う】
+ * 既存の `Tests\Support\PhpTokenScan::normalize()` を再利用する
+ * (`token_get_all()` の正規化。`T_WHITESPACE` / `T_COMMENT` / `T_DOC_COMMENT` を除去済み)。
+ * 素の `str_contains()` にすると **本設計自身が破綻する** — 契約の反転 docblock は
+ * 旧主張として `->afterCommit()` を引用するため、コメントを見る検出器では
+ * 反転を書いた瞬間に gate が落ちる。token 走査ならコメントも文字列リテラルも
+ * (`T_CONSTANT_ENCAPSED_STRING` を明示除外して) 対象外にできる。
+ *
+ * 【引数で母集団を受け取る理由】テストが fixture ディレクトリツリー / ダミークラス /
+ * 擬似 config を同じ関数へ食わせて「列挙 → 読み込み → 検出」の**全経路**を通せるようにするため。
+ * 検出関数だけを直接叩く形にすると「検出器は生きているが実ファイルが渡されていない」
+ * 偽グリーンを閉じられない。
+ *
+ * 【保証しないもの (誇張しない)】token 走査でも**動的な迂回**には沈黙する —
+ * `$m = 'afterCommit'; $job->$m();` / helper・facade alias で包んだ呼び出し /
+ * `$this->afterCommit = $flag;` のような動的値 / vendor 内の afterCommit 使用。
+ * (D3 と D5(既定値) はリフレクション判定なので中間 interface・親クラス経由まで拾う)
+ */
+final class QueueDispatchDeferralInventory
+{
+    /**
+     * D1/D2/D5(代入) の走査母集団となる first-party ランタイム PHP のルート。
+     * **`app/` だけでは狭い** — `DB::afterCommit` は `routes/console.php` や
+     * `bootstrap/app.php` にも書けるため。
+     * `vendor/` / `tests/` / `storage/` は対象外 (前者は自リポジトリの管轄外、
+     * 後者 2 つはランタイム経路ではない)。この定数が母集団境界の唯一の正本。
+     *
+     * @var list<string>
+     */
+    public const RUNTIME_ROOTS = ['app', 'routes', 'bootstrap', 'database', 'config'];
+
+    /**
+     * 指定ディレクトリ配下の PHP ファイル絶対パス (昇順) を列挙する純関数。
+     * **ルートを引数で受ける**ことで、負のコントロールが fixture root を渡して
+     * 「列挙 → 読み込み → 検出」の**列挙部分まで**同じコードを通せる。
+     *
+     * ★ **引数は絶対パス**である。相対ルートを受けて内部で `base_path()` を掛ける形にすると、
+     *   `sys_get_temp_dir()` 配下の fixture root を渡したときにパスが連結されて列挙できない。
+     *   本番側の相対→絶対変換は `runtimePhpFiles()` が行う。
+     *
+     * ★ 各入力について「**絶対パスであること**」「**存在するディレクトリであること**」を
+     *   明示検査し、満たさなければ例外を投げる (docblock だけの契約にしない)。
+     *   タイポで存在しないルートを渡したときに黙って 0 件を返すと、母集団 0 件 fail の
+     *   意図が空洞化するため。
+     *
+     * @param  list<string>  $absoluteRoots  絶対パスの既存ディレクトリ
+     * @return list<string>
+     */
+    public static function phpFilesUnder(array $absoluteRoots): array
+    {
+        $paths = [];
+        foreach ($absoluteRoots as $root) {
+            Assert::true(
+                str_starts_with($root, DIRECTORY_SEPARATOR),
+                "phpFilesUnder() には絶対パスを渡すこと (受け取った値: {$root})",
+            );
+            Assert::directory($root, "phpFilesUnder() のルートが存在しません: {$root}");
+
+            $iterator = new RecursiveIteratorIterator(
+                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
+            );
+            foreach ($iterator as $file) {
+                Assert::isInstanceOf($file, SplFileInfo::class);
+                if (! $file->isFile() || $file->getExtension() !== 'php') {
+                    continue;
+                }
+                $paths[] = $file->getPathname();
+            }
+        }
+
+        sort($paths);
+
+        return array_values(array_unique($paths));
+    }
+
+    /**
+     * 本番母集団 (RUNTIME_ROOTS を絶対パスへ変換して列挙する)。
+     *
+     * @return list<string>
+     */
+    public static function runtimePhpFiles(): array
+    {
+        return self::phpFilesUnder(array_map(
+            static fn (string $root): string => base_path($root),
+            self::RUNTIME_ROOTS,
+        ));
+    }
+
+    /**
+     * D1 (`->afterCommit(`) と D2 (`DB::afterCommit(`) を token 走査で検出する。
+     *
+     * @param  list<string>  $paths
+     * @return list<array{path: string, line: int, kind: string}>
+     */
+    public static function detectInFiles(array $paths): array
+    {
+        $hits = [];
+        foreach ($paths as $path) {
+            $source = file_get_contents($path);
+            Assert::string($source, "ファイルを読み込めません: {$path}");
+
+            $tokens = PhpTokenScan::normalize($source);
+            $count = count($tokens);
+            for ($i = 0; $i < $count; $i++) {
+                $token = $tokens[$i];
+                $name = $tokens[$i + 1] ?? null;
+                $open = $tokens[$i + 2] ?? null;
+                if ($name === null || $name['id'] !== T_STRING || $name['text'] !== 'afterCommit') {
+                    continue;
+                }
+                if ($open === null || $open['text'] !== '(') {
+                    continue; // 呼び出しでない (プロパティ参照など) は D5 の担当
+                }
+
+                $id = $token['id'];
+                if ($id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR) {
+                    $hits[] = ['path' => $path, 'line' => $name['line'], 'kind' => 'D1'];
+
+                    continue;
+                }
+                if ($id === T_DOUBLE_COLON) {
+                    // `DB::afterCommit(` / `Illuminate\Support\Facades\DB::afterCommit(`
+                    $hits[] = ['path' => $path, 'line' => $name['line'], 'kind' => 'D2'];
+                }
+            }
+        }
+
+        return $hits;
+    }
+
+    /**
+     * D3: 宣言的迂回 interface を implement するクラス。
+     * `ShouldQueueAfterCommit` と `ShouldHandleEventsAfterCommit` の**両方**を見る
+     * (`ReflectionClass::implementsInterface()` なので中間 interface / 親クラス経由も拾う)。
+     *
+     * @param  list<class-string>  $classes
+     * @return list<class-string>
+     */
+    public static function detectAfterCommitInterfaces(array $classes): array
+    {
+        $hits = [];
+        foreach ($classes as $class) {
+            $reflection = new ReflectionClass($class);
+            if ($reflection->implementsInterface(ShouldQueueAfterCommit::class)
+                || $reflection->implementsInterface(ShouldHandleEventsAfterCommit::class)) {
+                $hits[] = $reflection->getName();
+            }
+        }
+
+        return $hits;
+    }
+
+    /**
+     * D3 / D5(既定値) の母集団 = `ShouldQueue` 実装 ∪ Mailable subclass。
+     * **和集合にする理由**は class docblock を参照。重複は除去し昇順で返す。
+     *
+     * @return list<class-string>
+     */
+    public static function deferralCandidateClasses(): array
+    {
+        return self::mergeCandidateClasses(
+            QueuedJobPopulation::shouldQueueClasses(),
+            QueuedJobPopulation::mailableClasses(),
+        );
+    }
+
+    /**
+     * 和集合の生成そのものを**引数で検証できる形**に切り出した純関数。
+     *
+     * ★ **なぜ切り出すか**: 現状の本リポジトリでは `mailableClasses()` ⊆ `shouldQueueClasses()`
+     *   (2 つの Mailable が `implements ShouldQueue` を併記している) のため、
+     *   `deferralCandidateClasses()` を「shouldQueueClasses だけ返す」に潰しても**結果が変わらず、
+     *   ブラックボックスのテストでは検出できない**。和集合を取る意図そのものは、
+     *   ここへ disjoint な集合を食わせる負のコントロールで固定する。
+     *   (併記が外れて集合が乖離した瞬間に、D3 / D5 の 0 件 pin が実効を持つ)
+     *
+     * @param  list<class-string>  $shouldQueueClasses
+     * @param  list<class-string>  $mailableClasses
+     * @return list<class-string>
+     */
+    public static function mergeCandidateClasses(array $shouldQueueClasses, array $mailableClasses): array
+    {
+        $classes = array_values(array_unique(array_merge($shouldQueueClasses, $mailableClasses)));
+        sort($classes);
+
+        return $classes;
+    }
+
+    /**
+     * D4: `after_commit => true` を持つ接続名 (sync を除く)。
+     *
+     * @param  array<mixed>  $connections
+     * @return list<string> 違反した接続名
+     */
+    public static function detectAfterCommitEnabledConnections(array $connections): array
+    {
+        $hits = [];
+        foreach ($connections as $name => $config) {
+            if ($name === 'sync') {
+                continue; // sync の after_commit=true は M1 の契約 (R4 が別途固定する)
+            }
+            if (! is_array($config)) {
+                continue;
+            }
+            if (($config['after_commit'] ?? null) === true) {
+                $hits[] = (string) $name;
+            }
+        }
+
+        sort($hits);
+
+        return $hits;
+    }
+
+    /**
+     * D5 (既定値): `$afterCommit` プロパティの既定値が「commit 後ずらしを起こす値」のクラス。
+     * `ReflectionClass::getDefaultProperties()` を使う (**インスタンス化しない**ので、
+     * コンストラクタ引数が必要な job でも判定できる)。
+     *
+     * ★ **判定は「`null` でも `false` でもない」**である。設計は `=== true` の厳密比較を
+     *   指定していたが、vendor 側 (`Queue::shouldDispatchAfterCommit()`) は
+     *   `isset($job->afterCommit)` で拾った値を**真偽値文脈で**評価するため、
+     *   `1` / `'yes'` のような truthy 値でも commit 後ずらしが起きる。
+     *   `=== true` だけだとこれらが 0 件 pin をすり抜ける (Codex 実装レビュー Round 1)。
+     *   一方で `Queueable` trait の既定値 `null` と明示 `false` は**必ず除外する**
+     *   (これを違反にすると全 job が偽陽性になる = 設計が禁じた失敗形)。
+     *
+     * ★ **constructor promotion も見る**。`public function __construct(public bool $afterCommit = true)`
+     *   は promoted property の既定値がクラスの default properties に現れないため、
+     *   `getDefaultProperties()` だけでは検出できない (同 Round 1 の指摘)。
+     *
+     * @param  list<class-string>  $classes
+     * @return list<class-string>
+     */
+    public static function detectAfterCommitProperty(array $classes): array
+    {
+        $hits = [];
+        foreach ($classes as $class) {
+            $reflection = new ReflectionClass($class);
+
+            $defaults = $reflection->getDefaultProperties();
+            if (array_key_exists('afterCommit', $defaults) && self::defersAfterCommit($defaults['afterCommit'])) {
+                $hits[] = $reflection->getName();
+
+                continue;
+            }
+
+            $constructor = $reflection->getConstructor();
+            if ($constructor === null) {
+                continue;
+            }
+            foreach ($constructor->getParameters() as $parameter) {
+                if ($parameter->getName() !== 'afterCommit' || ! $parameter->isPromoted()) {
+                    continue;
+                }
+                if ($parameter->isDefaultValueAvailable() && self::defersAfterCommit($parameter->getDefaultValue())) {
+                    $hits[] = $reflection->getName();
+                }
+            }
+        }
+
+        return $hits;
+    }
+
+    /** その既定値が commit 後ずらしを発動させるか (null / false 以外はすべて発動する)。 */
+    private static function defersAfterCommit(mixed $value): bool
+    {
+        return $value !== null && $value !== false;
+    }
+
+    /**
+     * D5 (実行時代入): `->afterCommit = true` の **token 走査**。
+     * `$this->afterCommit = true;` (自クラス内) と `$job->afterCommit = true;`
+     * (外部からの代入) の**両方**を拾う = 判定は receiver を問わず
+     * `T_OBJECT_OPERATOR` + `afterCommit` + `=` + `true` の並びで行う。
+     *
+     * @param  list<string>  $paths
+     * @return list<array{path: string, line: int}>
+     */
+    public static function detectAfterCommitAssignments(array $paths): array
+    {
+        $hits = [];
+        foreach ($paths as $path) {
+            $source = file_get_contents($path);
+            Assert::string($source, "ファイルを読み込めません: {$path}");
+
+            $tokens = PhpTokenScan::normalize($source);
+            $count = count($tokens);
+            for ($i = 0; $i < $count; $i++) {
+                $operator = $tokens[$i];
+                if ($operator['id'] !== T_OBJECT_OPERATOR && $operator['id'] !== T_NULLSAFE_OBJECT_OPERATOR) {
+                    continue;
+                }
+                $name = $tokens[$i + 1] ?? null;
+                $assign = $tokens[$i + 2] ?? null;
+                $value = $tokens[$i + 3] ?? null;
+                if ($name === null || $name['id'] !== T_STRING || $name['text'] !== 'afterCommit') {
+                    continue;
+                }
+                if ($assign === null || $assign['text'] !== '=') {
+                    continue;
+                }
+                if ($value === null || strtolower($value['text']) !== 'true') {
+                    continue; // 動的値 (`= $flag`) は検出できない = 0 件 pin の穴 (誇張しない)
+                }
+
+                $hits[] = ['path' => $path, 'line' => $name['line']];
+            }
+        }
+
+        return $hits;
+    }
+}

```

---

## 再検証結果 (修正後)

- `composer phpstan`: OK (838 files, No errors)
- `vendor/bin/pint --test`: passed
- `vendor/bin/pest tests/Architecture/ tests/Feature/Billing/ tests/Feature/Support/`:
  1418 tests / 1418 passed / 0 failed
- (全レーンの `composer test` は最終コミット前に再実行する)

---

## 質問

上記の対応で Round 1 の Warning 6 件は解消しているか。
残る問題があれば [Critical] / [Warning] / [Suggestion] で指摘し、
最後に全体判定を `APPROVED` または `CHANGES_REQUESTED` の 1 語で明示せよ。
