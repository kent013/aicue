## Round 2: Round 1 の指摘への対応

以下の 2 点を修正した。対応マトリクスの全文は
devnotes/20260815-2057-retention-table-classification/codex-history/impl-review-decisions-round-1.md にある。

### [Warning] RC-8 の保証範囲の記述 → 対応した

詳細設計の検査一覧の RC-8 行が「総件数 / 区分ごとの件数 / 未確定の表名一覧を pin」と書いていたが、
同じ設計書の後半 (RC-8 の本文) と実装・gate の docblock・docs/architecture.md はいずれも
「総件数と未確定の表名だけ」で揃っていた。設計書側だけが強い保証を主張していたので、
設計書を実装に合わせて直した。

修正後の行:

| RC-8 | 空振り検知: 総件数と**未確定の表名一覧**を現在値ちょうどで pin (**区分ごとの件数は pin しない**。下の理由を参照) | 台帳が空になった / 未確定が無音で増えた |

同設計書内の gate docblock 案の該当行も次へ揃えた:

 *   - RC-8: 台帳の総件数と未確定の表名が**現在値ちょうど**である (無音で増えない)

### [Suggestion] RC-6 の正の自己検証 → 対応した

旧実装は「DeletedWithParent かつ ownerClass が null の宣言が 1 件以上ある」ことしか見ておらず、
cascade を 1 本も持たない台帳でも緑になった。実スキーマの外部キー一覧と突き合わせ、
**実際に cascade を持つ表が 1 件以上ある**ことを確かめる形へ変更した。

差分:

```diff
diff --git a/tests/Feature/Retention/RetentionTableClassificationTest.php b/tests/Feature/Retention/RetentionTableClassificationTest.php
new file mode 100644
index 0000000..c9978cf
--- /dev/null
+++ b/tests/Feature/Retention/RetentionTableClassificationTest.php
@@ -0,0 +1,633 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\BillingRetentionTarget;
+use Illuminate\Database\Schema\Builder;
+use Illuminate\Support\Facades\Artisan;
+use Illuminate\Support\Facades\DB;
+use Tests\Support\Retention\RetentionClass;
+use Tests\Support\Retention\RetentionTableEntry;
+use Tests\Support\Retention\RetentionTableRegistry;
+
+/*
+ * Feature invariant: **実スキーマの全表が保持期限の区分へ分類されている** (deny-by-default)。
+ *
+ * SoT = devnotes/20260815-2057-retention-table-classification/detailed-design.md
+ * (lctl 台帳 feature `retention-table-classification` 標準形 v1)。
+ *
+ * ★この gate が保証するもの:
+ *   - RC-1 / RC-2: 実スキーマの表一覧と台帳が**両方向で集合等価**である
+ *   - RC-3: 表名の二重宣言が無く、根拠が 30 文字以上ある
+ *   - RC-4: 課金 7 年の表集合が App\Enums\Billing\BillingRetentionTarget と一致する
+ *   - RC-5: 宣言された保持者 (クラス / コマンド) が**識別先として実在する**
+ *   - RC-6 / RC-7: 区分と実スキーマの外部キーの構造が矛盾していない
+ *   - RC-8: 総件数と未確定の表名を**現在値ちょうど**で pin する (無音で増えない)
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - **列の内容が個人情報かどうかは見ない**。単位は表であり、列は見ない
+ *   - **実データが実際に消えることは保証しない**。それは各掃除バッチの behavioral テスト
+ *     (inquiry:purge / idempotency:prune / billing:purge-retention-expired /
+ *      capture:purge-upload-reservations / account:purge-deletion-requests) の担当である
+ *   - **`on delete cascade` の存在は「親が実際に消される」ことを意味しない**。
+ *     親を消す経路が存在するかは見ていない
+ *   - **保持者の実在は「そのクラス / コマンドがその表を処理すること」を意味しない**。
+ *     見ているのは識別先が実在することだけであり、**Schedule に配線されているかも見ない**
+ *     (コマンドが実在しさえすれば RC-5 は通る)
+ *   - **行ごとの寿命の違いは表現しない**。単位は表なので、users のように
+ *     「退会予約が入った行だけが消える」表も 1 つの区分に丸められる
+ *   - **区分の意味が正しいかは人間のレビュー対象**である
+ *   - S3 上の実体 (レンダ出力・撮影テイク) / ビュー / 他スキーマの表は対象外である
+ *   - 表と外部キーの読み取りは**現在のスキーマ**に限る (`search_path` の健全性は前提であって
+ *     保証ではない)
+ *
+ * ★責務境界 (二重検査を作らない):
+ *   tests/Architecture/BillingRetentionTargetInventoryTest.php は
+ *   「app/Models/Billing/ の課金モデルを 7 年で消すか消さないか」を扱い、年数・起算点列・
+ *   purger の配線・実行順を持つ。本 gate は**それらを 1 つも持たず**、表集合の一致 (RC-4) だけで
+ *   結線する。同じ事実を 2 か所に書かない。
+ */
+
+/** 台帳の総件数 (cap ではなく exact-fit。増減したら必ずこの数字を書き換える)。 */
+const RETENTION_TABLE_COUNT = 63;
+
+/**
+ * 保持期限が**まだ決まっていない**表 (現在値ちょうど。増えるときも減るときもここを書き換える)。
+ *
+ * ここに 1 行足す / 消す操作は必ずテストの差分として現れる = レビューで見える。
+ */
+const RETENTION_UNDECIDED_TABLES = [
+    'admin_users',
+    'email_suppressions',
+    'llm_call_logs',
+    'model_audits',
+    'oauth_access_tokens',
+    'oauth_auth_codes',
+    'oauth_clients',
+    'oauth_device_codes',
+    'oauth_refresh_tokens',
+    'oauth_sessions',
+    'organizations',
+    'security_audit_events',
+    'teams',
+];
+
+/**
+ * スキーマ照会の入口。
+ *
+ * **ファサードではなく具体の Builder を取る** — `Schema::` の docblock は
+ * `array getTables(...)` としか書いておらず、要素が mixed になる。
+ * `Connection::getSchemaBuilder()` は `Illuminate\Database\Schema\Builder` を返し、
+ * 実体側の shape 宣言がそのまま効く (**型を緩めて黙らせない**)。
+ */
+function retentionSchemaBuilder(): Builder
+{
+    return DB::connection()->getSchemaBuilder();
+}
+
+/**
+ * 現在のスキーマの表名 (非修飾・sort 済み)。
+ *
+ * pgsql は引数なしだと全スキーマを返すため必ず現在のスキーマへ絞る。
+ *
+ * @return list<string>
+ */
+function retentionSchemaTableNames(): array
+{
+    $builder = retentionSchemaBuilder();
+    $names = array_map(
+        static fn (array $table): string => $table['name'],
+        $builder->getTables($builder->getCurrentSchemaName()),
+    );
+    sort($names);
+
+    return array_values($names);
+}
+
+/**
+ * 全表の外部キーを 1 度だけ読み、表名 => 参照先と on delete の一覧にする。
+ *
+ * **スキーマ修飾名で問い合わせる** (`getForeignKeys()` は `schema.table` を受け取って分解する)。
+ * 表一覧を現在のスキーマに絞っておきながら外部キーの照会だけ `search_path` 任せにすると、
+ * 同名表があるときに食い違う。
+ *
+ * @return array<string, list<array{foreign_table: string, columns: list<string>, on_delete: string|null}>>
+ */
+function retentionForeignKeyMap(): array
+{
+    $builder = retentionSchemaBuilder();
+    $schema = $builder->getCurrentSchemaName();
+
+    $map = [];
+    foreach (retentionSchemaTableNames() as $table) {
+        $map[$table] = array_values(array_map(
+            static fn (array $fk): array => [
+                'foreign_table' => $fk['foreign_table'],
+                'columns' => array_values($fk['columns']),
+                'on_delete' => $fk['on_delete'],
+            ],
+            $builder->getForeignKeys($schema.'.'.$table),
+        ));
+    }
+
+    return $map;
+}
+
+/**
+ * 指定した表の列が nullable かどうか。
+ *
+ * RC-7 が `on delete set null` を非違反にしてよいかの判定にだけ使う
+ * (`NOT NULL` の列が混ざっていると親の削除が制約違反で失敗するため)。
+ * **対象は「基準データ」「基盤が寿命を持つ」に分類した表だけ**に絞って引く。
+ *
+ * @param  list<string>  $tables
+ * @return array<string, array<string, bool>> 表名 => 列名 => nullable か
+ */
+function retentionNullableColumnMap(array $tables): array
+{
+    $builder = retentionSchemaBuilder();
+    $schema = $builder->getCurrentSchemaName();
+
+    $map = [];
+    foreach ($tables as $table) {
+        $columns = [];
+        foreach ($builder->getColumns($schema.'.'.$table) as $column) {
+            $columns[$column['name']] = $column['nullable'];
+        }
+        $map[$table] = $columns;
+    }
+
+    return $map;
+}
+
+/**
+ * 母集団と台帳の突合 (**純関数** = 負のコントロールから合成入力で直接呼べる)。
+ *
+ * @param  list<string>  $schemaTables
+ * @param  list<RetentionTableEntry>  $entries
+ * @return array{unclassified: list<string>, phantom: list<string>, duplicated: list<string>}
+ */
+function retentionClassify(array $schemaTables, array $entries): array
+{
+    $declared = [];
+    $duplicated = [];
+    foreach ($entries as $entry) {
+        if (array_key_exists($entry->table, $declared)) {
+            $duplicated[] = $entry->table;
+
+            continue;
+        }
+        $declared[$entry->table] = true;
+    }
+
+    $declaredTables = array_keys($declared);
+    $unclassified = array_values(array_diff($schemaTables, $declaredTables));
+    $phantom = array_values(array_diff($declaredTables, $schemaTables));
+
+    sort($unclassified);
+    sort($phantom);
+    sort($duplicated);
+
+    return [
+        'unclassified' => array_values($unclassified),
+        'phantom' => array_values($phantom),
+        'duplicated' => array_values(array_unique($duplicated)),
+    ];
+}
+
+/**
+ * RC-6 の判定 (**純関数**。外部キーの一覧を引数で受け取るので合成入力で点灯させられる)。
+ *
+ * 通り道は **2 つだけ**である:
+ *   (a) `on delete cascade` の外部キーを 1 本以上持つ (DB が連動を保証する)
+ *   (b) 削除責務を持つクラスを宣言している (連動がアプリ側にある)
+ * どちらも無ければ、その表は親が消えても残るので「親と一緒に消える」とは言えない。
+ *
+ * @param  list<RetentionTableEntry>  $entries
+ * @param  array<string, list<array{foreign_table: string, columns: list<string>, on_delete: string|null}>>  $foreignKeys
+ * @return list<string> 違反した表名
+ */
+function retentionDeletedWithParentViolations(array $entries, array $foreignKeys): array
+{
+    $violations = [];
+    foreach ($entries as $entry) {
+        if ($entry->class !== RetentionClass::DeletedWithParent || $entry->ownerClass !== null) {
+            continue;
+        }
+        $cascades = array_filter(
+            $foreignKeys[$entry->table] ?? [],
+            static fn (array $fk): bool => retentionNormalizedOnDelete($fk['on_delete']) === 'cascade',
+        );
+        if ($cascades === []) {
+            $violations[] = $entry->table;
+        }
+    }
+
+    sort($violations);
+
+    return $violations;
+}
+
+/**
+ * `on delete` の表記ゆれ (大文字・前後の空白) を畳む。取得できないときは null のまま返す。
+ */
+function retentionNormalizedOnDelete(?string $onDelete): ?string
+{
+    if ($onDelete === null) {
+        return null;
+    }
+
+    return mb_strtolower(trim($onDelete));
+}
+
+/**
+ * RC-7 の判定 (**純関数**)。期限を持たない区分の表が、期限が要る区分の表を
+ * **矛盾する `on delete` で**参照していないか。
+ *
+ * ★**外部キーの存在だけでは違反にしない**。親が消えたときに子がどうなるかで意味が変わる:
+ *   - `cascade` = 子も消える → 「期限を持たない」と矛盾する (違反)
+ *   - `restrict` / `no action` = 子から参照されている親行の削除を拒否する
+ *     → 親の期限の執行を止めうる (違反)
+ *   - `set null` = 子の外部キー列を空にして子は残る → 子自身は期限の連鎖の外にある。
+ *     **ただし外部キーの列がすべて nullable なときに限る** — `NOT NULL` が混ざっていると
+ *     親の削除は制約違反で失敗するので、実際には `restrict` と同じ結果になる (違反)
+ *   - `set default` = 既定値が外部キー制約を満たさなければ親の削除は失敗する。
+ *     本リポジトリに 1 本も無いため、現れたら分類の見直しが要るものとして保守的に違反へ倒す
+ *   - `null` (取得できない) = 未知 → 保守的に違反へ倒す
+ *
+ * ★**足りない情報はすべて違反へ倒す (fail-closed)**:
+ *   - 参照先の表が台帳に無い → 違反 (区分が決まらないものを黙って通さない)
+ *   - 外部キーの列が空 → 違反 (空集合に対して「全部 nullable」と答える空虚な真を作らない)
+ *   - 列の nullable が一覧に無い → 違反
+ *   見るのは **Laravel の Schema API が返す外部キーの列すべて**である。判定は必要条件より
+ *   厳しくなりうる (見落としではなく、余分に赤くなる側へ倒れる)。
+ *
+ * @param  list<RetentionTableEntry>  $entries
+ * @param  array<string, list<array{foreign_table: string, columns: list<string>, on_delete: string|null}>>  $foreignKeys
+ * @param  array<string, array<string, bool>>  $nullableColumns  表名 => 列名 => nullable か
+ * @return list<string> `{表名} -> {親表名} (on delete …)` の形の違反一覧
+ */
+function retentionHorizonParentViolations(array $entries, array $foreignKeys, array $nullableColumns): array
+{
+    /** @var array<string, RetentionClass> $classByTable */
+    $classByTable = [];
+    foreach ($entries as $entry) {
+        $classByTable[$entry->table] = $entry->class;
+    }
+
+    $violations = [];
+    foreach ($entries as $entry) {
+        if ($entry->class->hasHorizon()) {
+            continue;
+        }
+        foreach ($foreignKeys[$entry->table] ?? [] as $fk) {
+            $parentClass = $classByTable[$fk['foreign_table']] ?? null;
+            if ($parentClass !== null && ! $parentClass->hasHorizon()) {
+                // 参照先も期限を持たない区分なので、期限の連鎖は生まれない
+                continue;
+            }
+
+            $onDelete = retentionNormalizedOnDelete($fk['on_delete']);
+            if ($parentClass !== null && $onDelete === 'set null'
+                && retentionAllColumnsNullable($nullableColumns[$entry->table] ?? [], $fk['columns'])) {
+                // 親が消えても子は残る (外部キー列が空になるだけ) ので矛盾しない
+                continue;
+            }
+
+            $violations[] = sprintf(
+                '%s -> %s (on delete %s)',
+                $entry->table,
+                $fk['foreign_table'],
+                $onDelete ?? '不明',
+            );
+        }
+    }
+
+    sort($violations);
+
+    return $violations;
+}
+
+/**
+ * 外部キーの列が**すべて** nullable か (空集合は false = fail-closed)。
+ *
+ * @param  array<string, bool>  $nullableColumns  列名 => nullable か
+ * @param  list<string>  $columns
+ */
+function retentionAllColumnsNullable(array $nullableColumns, array $columns): bool
+{
+    if ($columns === []) {
+        return false;
+    }
+
+    foreach ($columns as $column) {
+        if (($nullableColumns[$column] ?? false) !== true) {
+            return false;
+        }
+    }
+
+    return true;
+}
+
+/**
+ * 指定区分の表名 (sort 済み)。
+ *
+ * @param  list<RetentionTableEntry>  $entries
+ * @return list<string>
+ */
+function retentionTablesOfClass(array $entries, RetentionClass $class): array
+{
+    $tables = array_values(array_map(
+        static fn (RetentionTableEntry $entry): string => $entry->table,
+        array_filter($entries, static fn (RetentionTableEntry $entry): bool => $entry->class === $class),
+    ));
+    sort($tables);
+
+    return $tables;
+}
+
+test('RC-1 / RC-2: 実スキーマの表一覧と台帳が両方向で集合等価である', function (): void {
+    $result = retentionClassify(retentionSchemaTableNames(), RetentionTableRegistry::entries());
+
+    expect($result['unclassified'])->toBe([],
+        '保持期限の区分が無い表を検出しました。tests/Support/Retention/RetentionTableRegistry.php へ '
+        .'区分と 30 文字以上の根拠付きで 1 行足してください (決まっていないなら undecided で構いません): '
+        .implode(', ', $result['unclassified']));
+
+    expect($result['phantom'])->toBe([],
+        '実スキーマに存在しない表が台帳に残っています (表を消したときの消し忘れ): '
+        .implode(', ', $result['phantom']));
+});
+
+test('RC-3: 表名の二重宣言が無く、根拠が 30 文字以上ある', function (): void {
+    $entries = RetentionTableRegistry::entries();
+
+    $result = retentionClassify(retentionSchemaTableNames(), $entries);
+    expect($result['duplicated'])->toBe([],
+        '同じ表が台帳に 2 回以上宣言されています (後の 1 件で上書きされる形の消失を防ぐため禁止): '
+        .implode(', ', $result['duplicated']));
+
+    $tooShort = [];
+    foreach ($entries as $entry) {
+        if (mb_strlen($entry->rationale) < RetentionTableEntry::RATIONALE_MIN_LENGTH) {
+            $tooShort[] = $entry->table;
+        }
+    }
+
+    expect($tooShort)->toBe([],
+        '根拠が短すぎます (30 文字以上。「同上」「N/A」のような形だけの記述を弾くため): '
+        .implode(', ', $tooShort));
+});
+
+test('RC-4: 課金 7 年の表集合が BillingRetentionTarget と両方向で一致する', function (): void {
+    $declared = retentionTablesOfClass(RetentionTableRegistry::entries(), RetentionClass::BillingRecord);
+
+    $canonical = array_map(
+        static fn (BillingRetentionTarget $case): string => $case->table(),
+        BillingRetentionTarget::cases(),
+    );
+    sort($canonical);
+
+    expect($declared)->toBe($canonical,
+        '課金 7 年の対象表が 2 つの目録で食い違っています。年数と実処理の正本は '
+        .'App\Enums\Billing\BillingRetentionTarget であり、本台帳は区分の宣言だけを持ちます。');
+});
+
+test('RC-5: 宣言された期限の保持者が実在する', function (): void {
+    // コマンドは **一覧のキーに存在するか**だけを見る (実行しない)。
+    $registered = Artisan::all();
+
+    $missing = [];
+    foreach (RetentionTableRegistry::entries() as $entry) {
+        if ($entry->ownerClass !== null && ! class_exists($entry->ownerClass)) {
+            $missing[] = $entry->table.' => class '.$entry->ownerClass;
+        }
+        if ($entry->ownerCommand !== null && ! array_key_exists($entry->ownerCommand, $registered)) {
+            $missing[] = $entry->table.' => command '.$entry->ownerCommand;
+        }
+    }
+
+    expect($missing)->toBe([],
+        '台帳が名指しした期限の保持者が実在しません (改名・廃止に追随していません): '
+        .implode(', ', $missing));
+});
+
+test('RC-6: 「親と一緒に消える」表は cascade の外部キーを持つか削除責務クラスを宣言している', function (): void {
+    $entries = RetentionTableRegistry::entries();
+    $foreignKeys = retentionForeignKeyMap();
+
+    $violations = retentionDeletedWithParentViolations($entries, $foreignKeys);
+
+    expect($violations)->toBe([],
+        'cascade の外部キーも削除責務クラスの宣言も無い表を「親と一緒に消える」と分類しています '
+        .'(親が消えてもこの行は残ります): '.implode(', ', $violations));
+
+    // 正の自己検証: 全件が削除責務クラスの宣言で素通りしていたら検査が形だけになる。
+    // **実スキーマの外部キーを見て** cascade で通った表を数える (宣言だけを数えると
+    // 「cascade が 1 本も無い台帳」でも緑になり、(a) の経路が評価されない)。
+    $viaCascade = array_filter(
+        $entries,
+        static fn (RetentionTableEntry $entry): bool => $entry->class === RetentionClass::DeletedWithParent
+            && $entry->ownerClass === null
+            && array_filter(
+                $foreignKeys[$entry->table] ?? [],
+                static fn (array $fk): bool => retentionNormalizedOnDelete($fk['on_delete']) === 'cascade',
+            ) !== [],
+    );
+    expect($viaCascade)->not->toBe([],
+        '実スキーマの cascade で通っている表が 1 件も無く、RC-6 の (a) の経路が評価されていません。');
+});
+
+test('RC-7: 期限を持たない区分の表が、期限が要る区分の表を矛盾する削除動作で参照していない', function (): void {
+    $entries = RetentionTableRegistry::entries();
+
+    // nullable の照会は「基準データ」「基盤が寿命を持つ」の表だけに絞る
+    $noHorizonTables = array_values(array_map(
+        static fn (RetentionTableEntry $entry): string => $entry->table,
+        array_filter($entries, static fn (RetentionTableEntry $entry): bool => ! $entry->class->hasHorizon()),
+    ));
+
+    $violations = retentionHorizonParentViolations(
+        $entries,
+        retentionForeignKeyMap(),
+        retentionNullableColumnMap($noHorizonTables),
+    );
+
+    expect($violations)->toBe([],
+        '期限が要る表を「期限を持たない」区分の表が参照しており、親が消えたときの挙動と '
+        .'分類が矛盾しています: '.implode(', ', $violations));
+});
+
+test('RC-8: 台帳の総件数と未確定の表名が現在値ちょうどである', function (): void {
+    $entries = RetentionTableRegistry::entries();
+
+    expect($entries)->toHaveCount(RETENTION_TABLE_COUNT,
+        '台帳の件数が変わりました。表を足した / 消したなら RETENTION_TABLE_COUNT も書き換えてください。');
+
+    $undecided = retentionTablesOfClass($entries, RetentionClass::Undecided);
+    $expected = RETENTION_UNDECIDED_TABLES;
+    sort($expected);
+
+    expect($undecided)->toBe($expected,
+        '保持期限が未確定の表の一覧が変わりました。増えるときも減るときも '
+        .'RETENTION_UNDECIDED_TABLES を書き換えてください (未確定を無音で増やさないための pin です)。');
+});
+
+test('NC-1: 台帳に無い表を実スキーマ側へ足すと RC-1 が点灯する', function (): void {
+    $entries = [
+        RetentionTableEntry::referenceData('plans', '料金プランの定義。運用者が入れ替えるまで残る基準データである'),
+    ];
+
+    $result = retentionClassify(['plans', 'ghost_table'], $entries);
+
+    expect($result['unclassified'])->toBe(['ghost_table']);
+    expect($result['phantom'])->toBe([]);
+});
+
+test('NC-2: 実在しない表を台帳へ足すと RC-2 が点灯する', function (): void {
+    $entries = [
+        RetentionTableEntry::referenceData('plans', '料金プランの定義。運用者が入れ替えるまで残る基準データである'),
+        RetentionTableEntry::referenceData('removed_table', '既に落とした表。台帳から消し忘れた幽霊登録を再現する'),
+    ];
+
+    $result = retentionClassify(['plans'], $entries);
+
+    expect($result['phantom'])->toBe(['removed_table']);
+    expect($result['unclassified'])->toBe([]);
+});
+
+test('NC-2b: 同じ表を 2 回宣言すると RC-3 の二重宣言が点灯する', function (): void {
+    $entries = [
+        RetentionTableEntry::referenceData('plans', '料金プランの定義。運用者が入れ替えるまで残る基準データである'),
+        RetentionTableEntry::referenceData('plans', '同じ表をもう一度宣言して二重登録の検出を確かめる'),
+    ];
+
+    $result = retentionClassify(['plans'], $entries);
+
+    expect($result['duplicated'])->toBe(['plans']);
+});
+
+test('NC-3: cascade も削除責務クラスも無い表を「親と一緒に消える」と宣言すると RC-6 が点灯する', function (): void {
+    $entries = [
+        RetentionTableEntry::deletedWithParent('orphan_rows', '親に連動して消えると言い張るが連動の裏付けが無い表'),
+    ];
+    $foreignKeys = [
+        'orphan_rows' => [
+            ['foreign_table' => 'users', 'columns' => ['user_id'], 'on_delete' => 'set null'],
+        ],
+    ];
+
+    expect(retentionDeletedWithParentViolations($entries, $foreignKeys))->toBe(['orphan_rows']);
+
+    // 同じ表に cascade を 1 本足すと通る (通り道 (a))
+    $withCascade = [
+        'orphan_rows' => [
+            ['foreign_table' => 'users', 'columns' => ['user_id'], 'on_delete' => 'cascade'],
+        ],
+    ];
+    expect(retentionDeletedWithParentViolations($entries, $withCascade))->toBe([]);
+
+    // 削除責務クラスを宣言しても通る (通り道 (b))
+    $withOwner = [
+        RetentionTableEntry::deletedWithParent(
+            'orphan_rows',
+            '連動はアプリ側にあるため削除責務クラスを宣言する',
+            RetentionTableRegistry::class,
+        ),
+    ];
+    expect(retentionDeletedWithParentViolations($withOwner, $foreignKeys))->toBe([]);
+});
+
+test('NC-4: 「基準データ」が期限の要る表を cascade / restrict で参照すると RC-7 が点灯する', function (): void {
+    $entries = [
+        RetentionTableEntry::referenceData('lookup_rows', '期限を持たない基準データのつもりで宣言した表'),
+        RetentionTableEntry::scheduledDeletion(
+            'purged_rows',
+            '定期実行が保持日数の超過で消す表',
+            RetentionTableRegistry::class,
+            'inquiry:purge',
+        ),
+    ];
+    $nullable = ['lookup_rows' => ['purged_row_id' => true]];
+
+    foreach (['cascade', 'restrict', 'no action', 'set default', null] as $onDelete) {
+        $foreignKeys = [
+            'lookup_rows' => [
+                ['foreign_table' => 'purged_rows', 'columns' => ['purged_row_id'], 'on_delete' => $onDelete],
+            ],
+        ];
+
+        expect(retentionHorizonParentViolations($entries, $foreignKeys, $nullable))
+            ->toHaveCount(1, sprintf('on delete %s は矛盾として検出されるべきです', $onDelete ?? '不明'));
+    }
+});
+
+test('正のコントロール: すべて nullable な set null なら RC-7 は点灯しない', function (): void {
+    $entries = [
+        RetentionTableEntry::referenceData('lookup_rows', '期限を持たない基準データとして宣言した表'),
+        RetentionTableEntry::scheduledDeletion(
+            'purged_rows',
+            '定期実行が保持日数の超過で消す表',
+            RetentionTableRegistry::class,
+            'inquiry:purge',
+        ),
+    ];
+    $foreignKeys = [
+        'lookup_rows' => [
+            ['foreign_table' => 'purged_rows', 'columns' => ['purged_row_id'], 'on_delete' => 'set null'],
+        ],
+    ];
+    $nullable = ['lookup_rows' => ['purged_row_id' => true]];
+
+    expect(retentionHorizonParentViolations($entries, $foreignKeys, $nullable))->toBe([]);
+});
+
+test('NC-5: set null でも外部キーの列に NOT NULL が混ざると RC-7 が点灯する', function (): void {
+    $entries = [
+        RetentionTableEntry::referenceData('lookup_rows', '期限を持たない基準データとして宣言した表'),
+        RetentionTableEntry::scheduledDeletion(
+            'purged_rows',
+            '定期実行が保持日数の超過で消す表',
+            RetentionTableRegistry::class,
+            'inquiry:purge',
+        ),
+    ];
+    // 複合外部キーの片方が NOT NULL (= 親の削除は制約違反で失敗する)
+    $foreignKeys = [
+        'lookup_rows' => [
+            [
+                'foreign_table' => 'purged_rows',
+                'columns' => ['purged_row_id', 'purged_row_kind'],
+                'on_delete' => 'set null',
+            ],
+        ],
+    ];
+
+    expect(retentionHorizonParentViolations(
+        $entries,
+        $foreignKeys,
+        ['lookup_rows' => ['purged_row_id' => true, 'purged_row_kind' => false]],
+    ))->toHaveCount(1);
+
+    // 列の一覧が空 / nullable が取れない場合も fail-closed で点灯する
+    expect(retentionHorizonParentViolations($entries, $foreignKeys, ['lookup_rows' => []]))->toHaveCount(1);
+    expect(retentionHorizonParentViolations(
+        $entries,
+        ['lookup_rows' => [['foreign_table' => 'purged_rows', 'columns' => [], 'on_delete' => 'set null']]],
+        ['lookup_rows' => ['purged_row_id' => true]],
+    ))->toHaveCount(1);
+});
+
+test('NC-6: 参照先が台帳に無い表なら RC-7 は fail-closed で点灯する', function (): void {
+    $entries = [
+        RetentionTableEntry::referenceData('lookup_rows', '期限を持たない基準データとして宣言した表'),
+    ];
+    $foreignKeys = [
+        'lookup_rows' => [
+            ['foreign_table' => 'unknown_rows', 'columns' => ['unknown_id'], 'on_delete' => 'set null'],
+        ],
+    ];
+
+    expect(retentionHorizonParentViolations($entries, $foreignKeys, ['lookup_rows' => ['unknown_id' => true]]))
+        ->toHaveCount(1);
+});
```

### その他の指摘 (Suggestion「問題なし」) → 変更なし

## 再実行結果

- `composer test -- --filter=RetentionTableClassification`: tests 15 / passed 15 / assertions 29
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed

## 依頼

上記 2 点の対応で全体判定を出し直してほしい。APPROVED / CHANGES_REQUESTED を明示すること。
