# Round 2: Round 1 指摘への対応

Round 1 の [Critical] 6 件 / [Warning] 3 件をすべて捌いた。対応マトリクスと修正後の差分を送る。
**修正は tests/ 配下 (走査器 + gate + inventory + Unit テスト) に閉じている**
(app/ の enum と docs は Round 1 から変更なし)。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] `orWhere` / `orWhereIn` / `whereNotIn` / `where('id','!=',…)` が素通りする
- 判断: 対応する
- 根拠: 検出漏れ (fail-open) であり本 gate の最悪の失敗モード。`where` だけ見て `orWhere` を
  見ないのは走査器の欠陥で、逃げ道として最も安易。
- 対応内容: 列名を第 1 引数に取る述語を `COLUMN_PREDICATES` 定数
  (`where` / `orWhere` / `firstWhere` / `whereIn` / `orWhereIn` / `whereNotIn` / `orWhereNotIn`) に集約。
  `orWhereKey` / `orWhereKeyNot` も key 述語へ追加。3 引数形の演算子に `!=` / `<>` / `not in` を足し、
  これらは `IdentityExclusion` として分類する。Unit fixture を追加。

## [Critical] 動的列名 (`where($column, $payloadId)`) が候補にも guard にも出ない
- 判断: 対応する (ただし設計の「0 件固定」ではなく明示 inventory にした)
- 根拠: `$column = 'id';` で gate を黙らせられるのは実在する回避手段。ただし実測すると
  0 件ではなく 3 件 (`MembershipScopedOrganizationBinder` の binding field / 通知の dedup 列)
  あるため、0 件 assertion では成立しない。
- 対応内容: `PrimaryKeyStaticQueryScanner::dynamicColumnPredicates()` を追加し、
  `DirectFetchInventory::reviewedDynamicColumnPredicates()` (記述子 => 理由) と
  **双方向整合**するテストを追加。理由は 30 文字以上を要求する。

## [Critical] group use / 複数 use を無視するためモデル解決に失敗し候補が消える
- 判断: 対応する
- 根拠: 「書き方を変えると候補が消える」は fail-open そのもの。
- 対応内容: `importsOf()` を書き直し、`use A\{B, C as D};` と `use A, B;` を展開する。
  Unit fixture (group use / group use + alias) を追加。

## [Critical] raw guard が quoted identifier と raw variant を漏らす
- 判断: 対応する
- 根拠: 同上 (書き方で guard を回避できる)。
- 対応内容: `RAW_PREDICATES` を `whereRaw` / `orWhereRaw` / `havingRaw` / `orHavingRaw` /
  `whereIntegerInRaw` / `orWhereIntegerInRaw` / `whereIntegerNotInRaw` / `orWhereIntegerNotInRaw` に拡張。
  SQL 側は `` ` `` / `"` / `[` / `]` を空白へ潰してから `id` を照合する。Unit fixture を追加。

## [Critical] `queryResultVariables()` が `$obj->method()` を受理し sameMethodQuery が形骸化する
- 判断: 対応する
- 根拠: 指摘のとおり「任意 object のメソッド結果を foreach しただけ」で副条件が通る。
  分類語彙が形骸化すれば deny-by-default の意味が消える。
- 対応内容: `queryResultVariables()` を**クラス起点 (`Model::` / `DB::`) の代入のみ**に絞った。
  relation 起点でテナントに閉じている形は `IdDerivedFromTenantScopedQuery` の担当なので
  責務が割れることもない。Unit fixture (`$input->ids()` は false / class-rooted pluck は true) を追加。

## [Critical] `whereKey($id)->delete()` が `DestructiveIdentity` にならない
- 判断: 対応する
- 根拠: `QueuePayloadRehydration + DestructiveIdentity` を禁止した設計意図が、
  書き方 1 つで無効化されるのは許可表の意味を失わせる。
- 対応内容: chain の最終 depth-0 呼び出しが `delete` / `forceDelete` / `restore` / `truncate` なら
  predicateKind を `DestructiveIdentity` へ昇格する。`update` は含めない
  (CAS 更新は識別子による削除と危険度が違い、含めると既存の正当な CAS 経路まで巻き込むため。
  理由は定数の docblock に明記)。Unit fixture を追加。

## [Critical] 抜け道 fixture が不足している
- 判断: 対応する
- 対応内容: 上記 6 件それぞれに Unit fixture を追加 (合計 +7 テスト)。

## [Warning] LocalOnly の登録条件確認がファイル全体の文字列一致で弱い
- 判断: 対応する
- 対応内容: `literalIsInsideGuardedBlock()` を追加し、**route 名リテラルが
  `isLocal` / `runningUnitTests` を含む条件式のブロック内にある**ことを波括弧の対応で確認する。
  併せて route の action が候補のコントローラを指していることも確認する
  (無関係な local route を借りて通せないようにする)。

## [Warning] delegated QueuePayloadRehydration の検証が弱い
- 判断: 対応する
- 対応内容: `enqueuedBy` の**メソッド本文が実在する**ことを必須にし、その本文が
  `->{候補メソッド}($this->` を呼んでいることまで確認する
  (メソッド名の一致だけだと job のどこかに同名呼び出しがあれば通ってしまう)。

## [Warning] 債務 case の `todoRef` が TODO ID でなく概念設計ファイル
- 判断: 一部対応する (TODO ID にはできない)
- 根拠: 本実装セッションは `docs/TODO.md` の変更を明示的に禁止されている
  (TODO のクローズを別担当が直列で行うため、同一ファイルを触ると必ず競合する)。
  したがって後続 TODO を起票できず ID を採番できない。
- 対応内容: 概念設計ではなく**専用の追跡ファイル**
  `devnotes/20260805-2311-model-direct-fetch-gate/follow-up-todo.md` を新設し、
  起票内容 (タイトル / テーマ / 優先度 / 対象 3 件 / 是正方針 / 完了時に何を消すか) を書いた。
  main 取り込み担当が `/app-todo-add` を実行して ID を採番し、`todoRef` を
  `aicue:T<番号>` へ置き換える手順もファイル内に明記した (gate は両形式を受理し実在を検証する)。

## [Warning] 追加 2 case は機械証明が弱い
- 判断: 一部対応する
- 根拠: 実コードに実在する 8 件を分類する語彙が設計の 7 case に無かったため追加は必要。
  ただし「弱い」という指摘は正しい。
- 対応内容: `IdDerivedFromSameMethodQuery` の副条件をクラス起点クエリ結果に限定して締めた (上記)。
  `IdSuppliedByInternalCaller` は private + 引数由来 + request accessor 無し + calledBy の
  実在呼び出しの 4 条件を維持し、「呼び出し元の provenance は機械証明できない」ことを
  enum の docblock に明記済み (public メソッドには使えない)。

## 新設ファイル (todoRef の追跡先)

`devnotes/20260805-2311-model-direct-fetch-gate/follow-up-todo.md`
(後続 TODO の起票内容・是正方針・完了時に消すものを記載。gate が実在を機械検証する)

## 修正後の tests/ 差分 (git diff。Round 1 からの累積ではなく HEAD からの全差分)

```diff
diff --git a/tests/Architecture/ModelDirectFetchInvariantTest.php b/tests/Architecture/ModelDirectFetchInvariantTest.php
new file mode 100644
index 0000000..b221204
--- /dev/null
+++ b/tests/Architecture/ModelDirectFetchInvariantTest.php
@@ -0,0 +1,585 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\DirectFetchJustification;
+use App\Http\Middleware\LocalOnly;
+use Illuminate\Support\Facades\Route;
+use Tests\Support\Security\DirectFetchInventory;
+use Tests\Support\Security\DirectFetchJustificationEntry;
+use Tests\Support\Security\PrimaryKeyPredicateKind;
+use Tests\Support\Security\PrimaryKeyStaticQueryCandidate;
+use Tests\Support\Security\PrimaryKeyStaticQueryScanner;
+
+/*
+ * 直 fetch (テナントスコープ外の主キー同一性クエリ) の invariant (deny-by-default)。
+ *
+ * 「クラス起点 (`User::` / `new User` / `DB::table('users')`) で主キー同一性クエリを書くなら、
+ * 分類と具体的根拠を inventory に明示登録する」を機械強制する。
+ *
+ * ★母集団は `app/**` + `routes/*.php` の全層。層で絞らない:
+ *   entrypoint 層 (`app/Http` + `app/Mcp`) に絞ると「Controller が scalar id を Service に渡し
+ *   Service 側で global fetch する」という抜け道が残る。ノイズはディレクトリではなく
+ *   **識別子引数の出所 (provenance)** で落とす。
+ *
+ * ★本 gate が守るのは `NestedRouteIdorDefenseTest` と**素で交わらない母集団**である:
+ *   前者は route parameter 由来の id、本 gate は route parameter **以外**の id
+ *   (POST payload / query string / MCP tool 引数 / token claim / queue payload)。
+ *   `NestedRouteDefenseInventory::candidates()` の母集団は parameterNames() !== [] の named route で、
+ *   body で id を受け取る経路は route parameter を増やさないため 1 件も現れない。
+ *
+ * ★本 gate が**保証しないこと** (主張範囲。AGENTS.md 不変条件「cross-org 不可」の全面証明ではない):
+ *   - 到達可能性 (`if (false) { … }` 中の候補も候補になる)
+ *   - `whereRaw` / `whereIntegerInRaw` / 動的列名 (`where($col, $x)`) — テスト 11 が 0 件を固定する
+ *   - relation / org-scoped 解決の一般的強制
+ *   - `exists:` validation rule による存在漏れ (機構が別。FormRequest / route 側の話)
+ *   - provenance 証明の第 2 段 (元モデルが保証済み provenance に属すること) — テスト 13 が代償措置
+ *
+ * 字句解析は tests/Support/Security/PrimaryKeyStaticQueryScanner に切り出し、解析器自体の
+ * positive/negative は tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest が固定する。
+ */
+
+/** 候補数の下限 (degenerate PASS 防止。走査器が部分的に壊れた場合も検知する)。 */
+function modelDirectFetchCandidateFloor(): int
+{
+    return 20;
+}
+
+/**
+ * 債務 case の件数上限。
+ *
+ * 実測 3 件 (payload user_id 2 件 + MCP consent の organization_id 1 件)。
+ * 4 件目を足そうとした瞬間に CI が落ち、「debt を増やす」判断が必ずレビューの俎上に乗る。
+ */
+function modelDirectFetchDebtCap(): int
+{
+    return 3;
+}
+
+/** `actorSource` の既定値集合。 */
+function modelDirectFetchActorSources(): array
+{
+    return ['authenticated_user', 'validated_token_claim', 'passport_token_record'];
+}
+
+/**
+ * 債務 case の membership / tenant marker。
+ *
+ * `lockForUpdate` は marker に含めない (ロックは競合制御であって所属検証ではない)。
+ */
+function modelDirectFetchMembershipMarkers(): array
+{
+    return [
+        '->organizations()->whereKey(',
+        '->users()->whereKey(',
+        '->members()->whereKey(',
+        'whereBelongsTo($organization',
+        'organizationRole(',
+    ];
+}
+
+/**
+ * case × predicateKind の許可表。表に無い組み合わせは fail。
+ *
+ * `QueuePayloadRehydration + DestructiveIdentity` を禁じるのは「queue payload の id で
+ * 無検証に削除する」形を設計段階で塞ぐため (現状 0 件)。
+ * `OwnerScopedQueryConstraint + DestructiveIdentity` も禁じる — `Model::destroy($id)` は
+ * 静的削除であり同一 chain に owner scope を足せないため、case の定義と矛盾する。
+ */
+function modelDirectFetchAllowedPredicateKinds(): array
+{
+    $single = PrimaryKeyPredicateKind::SingleIdentity->name;
+    $multi = PrimaryKeyPredicateKind::MultiIdentity->name;
+    $exclusion = PrimaryKeyPredicateKind::IdentityExclusion->name;
+    $destructive = PrimaryKeyPredicateKind::DestructiveIdentity->name;
+
+    return [
+        DirectFetchJustification::OwnerScopedQueryConstraint->value => [$single, $multi, $exclusion],
+        DirectFetchJustification::IdDerivedFromTenantScopedQuery->value => [$single, $multi, $exclusion],
+        DirectFetchJustification::IdDerivedFromSameMethodQuery->value => [$single, $multi],
+        DirectFetchJustification::IdSuppliedByInternalCaller->value => [$single, $multi],
+        DirectFetchJustification::AuthenticatedActorScope->value => [$single, $multi, $exclusion],
+        DirectFetchJustification::QueuePayloadRehydration->value => [$single, $multi],
+        DirectFetchJustification::LocalOnlyDiagnostics->value => [$single],
+        DirectFetchJustification::OperatorInvokedConsoleCommand->value => [$single, $multi, $exclusion, $destructive],
+        DirectFetchJustification::PayloadIdWithGlobalExistenceRuleDebt->value => [$single],
+    ];
+}
+
+/**
+ * inventory と現存候補を突き合わせた組 (key => [candidate, entry])。
+ *
+ * @return array<string, array{0: PrimaryKeyStaticQueryCandidate, 1: DirectFetchJustificationEntry}>
+ */
+function modelDirectFetchMatchedPairs(): array
+{
+    $inventory = DirectFetchInventory::inventory();
+    $pairs = [];
+
+    foreach (DirectFetchInventory::candidates() as $candidate) {
+        $entry = $inventory[$candidate->key] ?? null;
+        if ($entry !== null) {
+            $pairs[$candidate->key] = [$candidate, $entry];
+        }
+    }
+
+    return $pairs;
+}
+
+/**
+ * 指定 case の組だけを取り出す。
+ *
+ * @return array<string, array{0: PrimaryKeyStaticQueryCandidate, 1: DirectFetchJustificationEntry}>
+ */
+function modelDirectFetchPairsFor(DirectFetchJustification $case): array
+{
+    return array_filter(
+        modelDirectFetchMatchedPairs(),
+        static fn (array $pair): bool => $pair[1]->case === $case,
+    );
+}
+
+/** `App\Foo\Bar::method` の `App\Foo\Bar` を app/ 相対のファイルパスへ。 */
+function modelDirectFetchClassPath(string $reference): ?string
+{
+    if (preg_match('/^(App\\\\[A-Za-z0-9_\\\\]+)::[A-Za-z0-9_]+$/', $reference, $matches) !== 1) {
+        return null;
+    }
+
+    return 'app/'.str_replace('\\', '/', substr($matches[1], 4)).'.php';
+}
+
+/** `App\Foo\Bar::method` の method 名。 */
+function modelDirectFetchMethodName(string $reference): ?string
+{
+    $position = strrpos($reference, '::');
+
+    return $position === false ? null : substr($reference, $position + 2);
+}
+
+/** 候補ファイル + スコープ名から `App\Foo\Bar::method` 形式を組み立てる。 */
+function modelDirectFetchSelfReference(PrimaryKeyStaticQueryCandidate $candidate): string
+{
+    return 'App\\'.str_replace('/', '\\', substr($candidate->displayPath(), 0, -4)).'::'.$candidate->scopeName;
+}
+
+/** トークン列を空白除去して marker 照合できる形にする。 */
+function modelDirectFetchCompact(string $source): string
+{
+    return str_replace(' ', '', $source);
+}
+
+test('クラス起点の主キー同一性クエリが全て inventory に明示分類されている (未知は fail)', function (): void {
+    $inventory = DirectFetchInventory::inventory();
+    $violations = [];
+
+    foreach (DirectFetchInventory::candidates() as $candidate) {
+        if (! array_key_exists($candidate->key, $inventory)) {
+            $violations[] = $candidate->key.' ('.$candidate->identityArgument.' で引いている)';
+        }
+    }
+
+    expect($violations)->toBe([],
+        'テナントスコープ外で id からモデルを引いている箇所があります。'
+        .'まず relation 起点 ($organization->users()->whereKey(...)) に直せないか検討し、'
+        .'直せない場合のみ DirectFetchInventory へ DirectFetchJustification + 具体的根拠を登録してください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('inventory の key は全て現存する候補である (stale 検出)', function (): void {
+    $keys = array_map(static fn (PrimaryKeyStaticQueryCandidate $c): string => $c->key, DirectFetchInventory::candidates());
+    $stale = array_values(array_diff(array_keys(DirectFetchInventory::inventory()), $keys));
+
+    expect($stale)->toBe([],
+        'inventory に、現存しない候補の裁定が残っています (コードが直った / key が変わった)。'
+        .'該当エントリを削除するか、新しい key へ書き換えてください。'.PHP_EOL.implode(PHP_EOL, $stale));
+});
+
+test('OwnerScopedQueryConstraint は同一 chain の所有者制約 (右辺 provenance 込み) を伴う', function (): void {
+    $violations = [];
+
+    foreach (modelDirectFetchPairsFor(DirectFetchJustification::OwnerScopedQueryConstraint) as $key => [$candidate, $entry]) {
+        if (! PrimaryKeyStaticQueryScanner::hasOwnerScopedConstraint($candidate)) {
+            $violations[] = $key.' — chain: '.$candidate->chainSource;
+        }
+    }
+
+    // v1 の許可 signature は where('organization_id'|'user_id'|'team_id'|'project_id', $model->getKey())
+    // と whereBelongsTo($model) の 2 形のみ。whereHas(...) は必要とする候補が実在せず、
+    // ネスト closure 内の右辺 provenance 判定はコストが跳ねるため v1 では受理しない。
+    expect($violations)->toBe([],
+        'OwnerScopedQueryConstraint は「identity 述語と同じ chain に所有者/テナント制約があり、'
+        .'その右辺が解決済みモデル由来である」ことが条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('IdDerivedFromTenantScopedQuery は identity がテナントスコープ済みの解決に由来する', function (): void {
+    $violations = [];
+
+    foreach (modelDirectFetchPairsFor(DirectFetchJustification::IdDerivedFromTenantScopedQuery) as $key => [$candidate, $entry]) {
+        if (! PrimaryKeyStaticQueryScanner::identityAssignedFromRelationQuery($candidate)) {
+            $violations[] = $key.' — identity: '.$candidate->identityArgument;
+        }
+    }
+
+    expect($violations)->toBe([],
+        'IdDerivedFromTenantScopedQuery は identity 変数が relation 起点クエリ、または'
+        .'解決済みモデルの key から代入されていることが条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('IdDerivedFromSameMethodQuery は同一メソッドの走査クエリ由来かつ request 入力を読まない', function (): void {
+    $violations = [];
+
+    foreach (modelDirectFetchPairsFor(DirectFetchJustification::IdDerivedFromSameMethodQuery) as $key => [$candidate, $entry]) {
+        if (! PrimaryKeyStaticQueryScanner::identityDerivedFromSameMethodQuery($candidate)) {
+            $violations[] = $key.' — identity が同一メソッドのクエリ結果由来でない: '.$candidate->identityArgument;
+        }
+        if (! PrimaryKeyStaticQueryScanner::methodIsFreeOfRequestAccessors($candidate)) {
+            $violations[] = $key.' — 同一メソッドに request accessor がある';
+        }
+    }
+
+    expect($violations)->toBe([],
+        'IdDerivedFromSameMethodQuery は「identity が同一メソッド内の走査クエリ結果由来」かつ'
+        .'「HTTP 入力を経由しない」ことが条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('IdSuppliedByInternalCaller は private + 引数由来 + request 入力を読まない + calledBy 実在', function (): void {
+    $violations = [];
+
+    foreach (modelDirectFetchPairsFor(DirectFetchJustification::IdSuppliedByInternalCaller) as $key => [$candidate, $entry]) {
+        if (! PrimaryKeyStaticQueryScanner::methodIsPrivate($candidate)) {
+            $violations[] = $key.' — private メソッドでない (public は本 case を使えない)';
+        }
+        if (! PrimaryKeyStaticQueryScanner::identityDerivedFromMethodParameters($candidate)) {
+            $violations[] = $key.' — identity が引数由来でない: '.$candidate->identityArgument;
+        }
+        if (! PrimaryKeyStaticQueryScanner::methodIsFreeOfRequestAccessors($candidate)) {
+            $violations[] = $key.' — 同一メソッドに request accessor がある';
+        }
+
+        $path = modelDirectFetchClassPath($entry->calledBy());
+        $method = modelDirectFetchMethodName($entry->calledBy());
+        $sources = DirectFetchInventory::sourceFiles();
+        if ($path === null || $method === null || ! array_key_exists($path, $sources)) {
+            $violations[] = $key.' — calledBy のクラスが実在しない: '.$entry->calledBy();
+
+            continue;
+        }
+        $body = PrimaryKeyStaticQueryScanner::methodBody($sources[$path], $method);
+        if ($body === null || ! str_contains(modelDirectFetchCompact($body), '->'.$candidate->scopeName.'(')) {
+            $violations[] = $key.' — calledBy の本文が '.$candidate->scopeName.'() を呼んでいない';
+        }
+    }
+
+    expect($violations)->toBe([],
+        'IdSuppliedByInternalCaller は private メソッド + 引数由来 identity + request accessor 無し +'
+        .'calledBy の実在呼び出しが条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('AuthenticatedActorScope は同一メソッドに request accessor を持たない', function (): void {
+    $violations = [];
+
+    foreach (modelDirectFetchPairsFor(DirectFetchJustification::AuthenticatedActorScope) as $key => [$candidate, $entry]) {
+        if (! PrimaryKeyStaticQueryScanner::methodIsFreeOfRequestAccessors($candidate)) {
+            $violations[] = $key.' — 同一メソッドに request accessor がある';
+        }
+        if (! in_array($entry->actorSource(), modelDirectFetchActorSources(), true)) {
+            $violations[] = $key.' — actorSource が既定値集合にない: '.$entry->actorSource();
+        }
+    }
+
+    // ★本 case のみ機械証明ができない (provenance のデータフロー解析は走査器の範囲外)。
+    //   negative check と構造化 field で濫用を抑えるが、最終的には人手の根拠文に依存する。
+    expect($violations)->toBe([],
+        'AuthenticatedActorScope は「identity が request payload 由来でない」ことが条件です。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('QueuePayloadRehydration は job property 由来、または job から委譲された引数由来である', function (): void {
+    $violations = [];
+    $sources = DirectFetchInventory::sourceFiles();
+
+    foreach (modelDirectFetchPairsFor(DirectFetchJustification::QueuePayloadRehydration) as $key => [$candidate, $entry]) {
+        $enqueuedBy = $entry->enqueuedBy();
+        $path = modelDirectFetchClassPath($enqueuedBy);
+        if ($path === null || ! array_key_exists($path, $sources)) {
+            $violations[] = $key.' — enqueuedBy のクラスが実在しない: '.$enqueuedBy;
+
+            continue;
+        }
+
+        $enqueuedMethod = modelDirectFetchMethodName($enqueuedBy);
+        $enqueuedBody = $enqueuedMethod === null
+            ? null
+            : PrimaryKeyStaticQueryScanner::methodBody($sources[$path], $enqueuedMethod);
+        if ($enqueuedBody === null) {
+            $violations[] = $key.' — enqueuedBy のメソッドが実在しない: '.$enqueuedBy;
+
+            continue;
+        }
+
+        // (a) 直接形: job 本体が自身の property を再水和する
+        $isJobFile = str_starts_with($candidate->displayPath(), 'Jobs/');
+        $isJobProperty = preg_match('/^\$this->[A-Za-z0-9_]*Ids?$/', $candidate->identityArgument) === 1;
+        if ($isJobFile && $isJobProperty) {
+            continue;
+        }
+
+        // (b) 委譲形: job が **自身の property を** scalar id としてそのまま Service へ渡す。
+        //     「dispatch 元の job のメソッドが実在し、そのメソッド本文が
+        //     `->{候補メソッド}($this->...)` を呼んでいる」ところまで機械確認する
+        //     (メソッド名の一致だけだと job のどこかに同名呼び出しがあれば通ってしまう)
+        $delegated = PrimaryKeyStaticQueryScanner::identityDerivedFromMethodParameters($candidate)
+            && str_starts_with($path, 'app/Jobs/')
+            && str_contains(modelDirectFetchCompact($enqueuedBody), '->'.$candidate->scopeName.'($this->');
+        if ($delegated) {
+            continue;
+        }
+
+        $violations[] = $key.' — job property でも job からの委譲でもない: '.$candidate->identityArgument;
+    }
+
+    expect($violations)->toBe([],
+        'QueuePayloadRehydration は「app/Jobs 配下で $this->{…}Id を再水和する」か'
+        .'「app/Jobs の dispatch 元がそのメソッドへ id を渡している」ことが条件です。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('LocalOnlyDiagnostics は LocalOnly middleware 付き route と local 限定登録の 2 段で守られている', function (): void {
+    $violations = [];
+    $routeSources = array_filter(
+        DirectFetchInventory::sourceFiles(),
+        static fn (string $path): bool => str_starts_with($path, 'routes/'),
+        ARRAY_FILTER_USE_KEY,
+    );
+
+    foreach (modelDirectFetchPairsFor(DirectFetchJustification::LocalOnlyDiagnostics) as $key => [$candidate, $entry]) {
+        $route = Route::getRoutes()->getByName($entry->routeName());
+        if ($route === null) {
+            $violations[] = $key.' — route が存在しない: '.$entry->routeName();
+
+            continue;
+        }
+        if (! in_array(LocalOnly::class, $route->gatherMiddleware(), true)) {
+            $violations[] = $key.' — route に LocalOnly middleware が付いていない: '.$entry->routeName();
+        }
+        // route が候補のコントローラを指していること (無関係な local route を借りて通せないように)
+        $action = $route->getActionName();
+        if (! str_contains($action, basename($candidate->displayPath(), '.php'))) {
+            $violations[] = $key.' — routeName が候補のコントローラを指していない: '.$action;
+        }
+        // 登録条件の中に route 名リテラルが実在すること (ファイル全体の文字列一致では弱い)
+        $guarded = false;
+        foreach ($routeSources as $source) {
+            $guarded = $guarded || PrimaryKeyStaticQueryScanner::literalIsInsideGuardedBlock(
+                $source,
+                $entry->routeName(),
+                ['isLocal', 'runningUnitTests'],
+            );
+        }
+        if (! $guarded) {
+            $violations[] = $key.' — route 名が local 限定ブロック (isLocal / runningUnitTests) の中に無い';
+        }
+    }
+
+    expect($violations)->toBe([],
+        'LocalOnlyDiagnostics は「route 登録自体が local 限定」かつ「LocalOnly middleware」の'
+        .'2 段で production から到達不能であることが条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('OperatorInvokedConsoleCommand は Console/Commands 配下で signature が実在する', function (): void {
+    $violations = [];
+    $sources = DirectFetchInventory::sourceFiles();
+
+    foreach (modelDirectFetchPairsFor(DirectFetchJustification::OperatorInvokedConsoleCommand) as $key => [$candidate, $entry]) {
+        if (! str_starts_with($candidate->displayPath(), 'Console/Commands/')) {
+            $violations[] = $key.' — app/Console/Commands 配下でない';
+
+            continue;
+        }
+        $commandName = explode(' ', trim($entry->commandSignature()))[0];
+        $source = $sources['app/'.$candidate->displayPath()] ?? '';
+        if ($commandName === '' || ! str_contains($source, $commandName)) {
+            $violations[] = $key.' — commandSignature の command 名がファイルに実在しない: '.$entry->commandSignature();
+        }
+    }
+
+    expect($violations)->toBe([],
+        'OperatorInvokedConsoleCommand は app/Console/Commands 配下 + 実在する command signature が条件です。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('債務 case は補償チェックの実在 (verifiedBy / 呼び出し / marker / todoRef) を伴う', function (): void {
+    $violations = [];
+    $sources = DirectFetchInventory::sourceFiles();
+    $todoSources = file_get_contents(base_path('docs/TODO.md')).file_get_contents(base_path('docs/TODO-closed.md'));
+
+    foreach (modelDirectFetchPairsFor(DirectFetchJustification::PayloadIdWithGlobalExistenceRuleDebt) as $key => [$candidate, $entry]) {
+        $verifiedBy = $entry->verifiedBy();
+        $path = modelDirectFetchClassPath($verifiedBy);
+        $method = modelDirectFetchMethodName($verifiedBy);
+        if ($path === null || $method === null || ! array_key_exists($path, $sources)) {
+            $violations[] = $key.' — verifiedBy のクラスが実在しない: '.$verifiedBy;
+
+            continue;
+        }
+        $body = PrimaryKeyStaticQueryScanner::methodBody($sources[$path], $method);
+        if ($body === null) {
+            $violations[] = $key.' — verifiedBy のメソッドが実在しない: '.$verifiedBy;
+
+            continue;
+        }
+        $compact = modelDirectFetchCompact($body);
+        $hasMarker = false;
+        foreach (modelDirectFetchMembershipMarkers() as $marker) {
+            $hasMarker = $hasMarker || str_contains($compact, $marker);
+        }
+        if (! $hasMarker) {
+            $violations[] = $key.' — verifiedBy の本文に membership/tenant marker が無い: '.$verifiedBy;
+        }
+
+        // 呼び出し側が exact method を呼んでいること (同一メソッド内で検証する形は自己参照で受理する)
+        $callsIt = str_contains(modelDirectFetchCompact($candidate->methodSource), '->'.$method.'(')
+            || $verifiedBy === modelDirectFetchSelfReference($candidate);
+        if (! $callsIt) {
+            $violations[] = $key.' — 候補のメソッドが '.$method.'() を呼んでいない';
+        }
+
+        if ($entry->validationRule() === '') {
+            $violations[] = $key.' — validationRule が空';
+        }
+
+        // todoRef は「実在する追跡先」でなければならない (プレースホルダを許さない)
+        $todoRef = $entry->todoRef();
+        $tracked = preg_match('/^aicue:T\d+$/', $todoRef) === 1
+            ? str_contains($todoSources, substr($todoRef, 6))
+            : file_exists(base_path($todoRef));
+        if (! $tracked) {
+            $violations[] = $key.' — todoRef が実在しない: '.$todoRef;
+        }
+    }
+
+    // ★v1 の限界: `$this->membership->transferOwnership(` の `$this->membership` が
+    //   OrganizationMembershipService であることは token 走査では証明できない
+    //   (constructor promoted property の型追跡が要る)。本テストは「メソッド名の呼び出しが
+    //   候補と同一メソッド内に存在する」「verifiedBy のクラスが実在し当該メソッド本文に
+    //   marker がある」「根拠文がある」の 3 点で受理し、**exact class を証明したとは主張しない**。
+    expect($violations)->toBe([],
+        '債務 case は補償チェックの実在が条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('債務 case が増殖していない (上限を超えたら是正 TODO を先に進める)', function (): void {
+    $debts = array_keys(modelDirectFetchPairsFor(DirectFetchJustification::PayloadIdWithGlobalExistenceRuleDebt));
+
+    expect(count($debts))->toBeLessThanOrEqual(modelDirectFetchDebtCap(),
+        'PayloadIdWithGlobalExistenceRuleDebt は「fetch 後に弾く」形であり準拠形ではありません。'
+        .'新規に増やさず、org 相対化 (relation 起点) と exists: rule の見直しをセットで行ってください。'
+        .PHP_EOL.implode(PHP_EOL, $debts));
+});
+
+test('case × predicateKind が許可表の組み合わせに収まっている', function (): void {
+    $allowed = modelDirectFetchAllowedPredicateKinds();
+    $violations = [];
+
+    foreach (modelDirectFetchMatchedPairs() as $key => [$candidate, $entry]) {
+        $kinds = $allowed[$entry->case->value] ?? [];
+        if (! in_array($candidate->predicateKind->name, $kinds, true)) {
+            $violations[] = $key.' — '.$entry->case->value.' に '.$candidate->predicateKind->name.' は許可されていない';
+        }
+    }
+
+    expect($violations)->toBe([],
+        '許可表に無い case × predicateKind の組み合わせです。'.PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('同一 fingerprint の候補が重複していない (裁定理由の横滑り防止)', function (): void {
+    $groups = [];
+    foreach (DirectFetchInventory::candidates() as $candidate) {
+        $groups[$candidate->fingerprint()][] = $candidate->chainSource;
+    }
+
+    $duplicates = [];
+    foreach ($groups as $fingerprint => $chains) {
+        if (count($chains) > 1 && ! in_array($fingerprint, DirectFetchInventory::reviewedDuplicateFingerprints(), true)) {
+            $duplicates[] = $fingerprint.' (x'.count($chains).')';
+        }
+    }
+
+    expect($duplicates)->toBe([],
+        '同一 fingerprint の候補が複数あります。ordinal 依存になり、裁定理由が別の候補へ'
+        .'横滑りしても気付けません。chainSource を確認のうえ '
+        .'DirectFetchInventory::reviewedDuplicateFingerprints() へ group 単位で明示登録してください。'
+        .PHP_EOL.implode(PHP_EOL, $duplicates));
+});
+
+test('範囲外の raw 主キー述語が 0 件である', function (): void {
+    $violations = [];
+
+    foreach (DirectFetchInventory::sourceFiles() as $path => $source) {
+        if (PrimaryKeyStaticQueryScanner::containsRawPrimaryKeyPredicate($source)) {
+            $violations[] = $path;
+        }
+    }
+
+    expect($violations)->toBe([],
+        'whereRaw / whereIntegerInRaw は本 gate の走査範囲外です。主キーを指す raw 述語が'
+        .'現れたら、本 gate の検出規則を拡張するか relation 起点に書き直してください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('非主キー一意列によるモデル解決が 0 件である (provenance 前提の見張り)', function (): void {
+    $tables = DirectFetchInventory::modelTables();
+    $violations = [];
+
+    foreach (DirectFetchInventory::sourceFiles() as $path => $source) {
+        foreach (PrimaryKeyStaticQueryScanner::uniqueColumnResolutions($source, $path, $tables) as $resolution) {
+            $violations[] = $resolution;
+        }
+    }
+
+    // ★本テストは provenance 証明の第 2 段 (元モデルが保証済み provenance に属することの確認) と
+    //   同等の証明ではない。「呼び出し側が model を非主キー一意列で untrusted 入力から解決している」
+    //   経路が現状 0 件であるという前提が崩れた瞬間に気付くための guard である。
+    //   1 件でも生えたら本 gate の provenance 設計 (§4-2(c)) に戻って再検討すること。
+    expect($violations)->toBe([],
+        'クラス起点クエリが非主キー一意列 (uuid / slug / public_id / ulid / code) でモデルを解決しています。'
+        .'この形は主キー同一性の候補に現れないため、本 gate の provenance 除外の前提が崩れます。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('動的列名を使うクラス起点 chain が全て inventory に登録されている (双方向整合)', function (): void {
+    $tables = DirectFetchInventory::modelTables();
+    $reviewed = DirectFetchInventory::reviewedDynamicColumnPredicates();
+    $found = [];
+
+    foreach (DirectFetchInventory::sourceFiles() as $path => $source) {
+        foreach (PrimaryKeyStaticQueryScanner::dynamicColumnPredicates($source, $path, $tables) as $descriptor) {
+            $found[] = $descriptor;
+        }
+    }
+
+    $unknown = array_values(array_diff($found, array_keys($reviewed)));
+    $stale = array_values(array_diff(array_keys($reviewed), $found));
+
+    // 動的列名は「列が id か」を字句的に決められないため主キー同一性の候補にできない。
+    // 0 件ではない (membership binder が実在する) ので 0 件固定ではなく明示 inventory で見張り、
+    // `$column = 'id'; User::query()->where($column, $payloadId);` という回避を塞ぐ。
+    expect($unknown)->toBe([],
+        '動的列名でクラス起点クエリを絞り込んでいる箇所が未登録です。列名を literal にできないか'
+        .'検討し、できない場合のみ理由付きで reviewedDynamicColumnPredicates() へ登録してください。'
+        .PHP_EOL.implode(PHP_EOL, $unknown));
+    expect($stale)->toBe([],
+        'reviewedDynamicColumnPredicates() に現存しない記述子が残っています。'.PHP_EOL.implode(PHP_EOL, $stale));
+
+    foreach ($reviewed as $descriptor => $reason) {
+        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(DirectFetchJustificationEntry::REASON_MIN_LENGTH, $descriptor);
+    }
+});
+
+test('走査器が現行コードベースから十分な数の候補を検出している (degenerate PASS 防止)', function (): void {
+    $count = count(DirectFetchInventory::candidates());
+
+    expect($count)->toBeGreaterThanOrEqual(modelDirectFetchCandidateFloor(),
+        '検出数が下限を割りました。走査器が壊れると inventory の突合が両方 green になり '
+        .'gate が静かに無力化します (現状の実測は 34 件)。実測 '.$count.' 件');
+});
diff --git a/tests/Support/Security/DirectFetchInventory.php b/tests/Support/Security/DirectFetchInventory.php
new file mode 100644
index 0000000..2110aee
--- /dev/null
+++ b/tests/Support/Security/DirectFetchInventory.php
@@ -0,0 +1,347 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Security;
+
+use Illuminate\Database\Eloquent\Model;
+use ReflectionClass;
+use SplFileInfo;
+use Symfony\Component\Finder\Finder;
+
+/**
+ * クラス起点の主キー同一性クエリ (直 fetch 候補) の裁定 inventory (単一 source of truth)。
+ *
+ * `NestedRouteDefenseInventory` と同じく静的クラスに置く
+ * (Pest のファイル読み込み順に依存する global 関数にしない)。
+ *
+ * **母集団は `app/**` + `routes/*.php` の全層**。層で絞らない。
+ * entrypoint 層 (`app/Http` + `app/Mcp`) に絞ると「Controller が scalar id を Service に渡し
+ * Service 側で global fetch する」という明白な抜け道が残るため。
+ * ノイズは走査器の provenance フィルタ (識別子引数が解決済みモデル由来のものを外す) で落とす。
+ */
+final class DirectFetchInventory
+{
+    /** model を持たないが security-sensitive なテーブル (v1 は空。Passport 内部の `oauth_*` は入れない)。 */
+    private const EXPLICIT_TABLES = [];
+
+    /**
+     * 走査対象 (リポジトリルート相対)。
+     *
+     * @return list<string>
+     */
+    public static function scannedPaths(): array
+    {
+        return ['app', 'routes'];
+    }
+
+    /**
+     * `App\Models\*` に対応するテーブル名 (`DB::table(...)` 起点の対象を絞る)。
+     *
+     * ハードコードすると新しいモデルを足したときに
+     * `DB::table('new_things')->where('id', $payloadId)` が静かに母集団から漏れるため、
+     * `app/Models/` の具象モデルを列挙して `getTable()` から導出する。
+     *
+     * @return list<string>
+     */
+    public static function modelTables(): array
+    {
+        /** @var list<string> $tables */
+        $tables = self::EXPLICIT_TABLES;
+
+        foreach (Finder::create()->files()->in(base_path('app/Models'))->name('*.php') as $file) {
+            $class = self::classOf($file);
+            if (! class_exists($class)) {
+                continue;
+            }
+            $reflection = new ReflectionClass($class);
+            if (! $reflection->isInstantiable() || ! $reflection->isSubclassOf(Model::class)) {
+                continue;
+            }
+            // 通常の `new` はモデルの constructor 引数 / イベントに依存しうるため使わない
+            $instance = $reflection->newInstanceWithoutConstructor();
+            /** @var Model $instance */
+            $tables[] = $instance->getTable();
+        }
+
+        return array_values(array_unique($tables));
+    }
+
+    /**
+     * 走査対象全体から抽出した候補。
+     *
+     * @return list<PrimaryKeyStaticQueryCandidate>
+     */
+    public static function candidates(): array
+    {
+        $tables = self::modelTables();
+        $candidates = [];
+
+        foreach (self::sourceFiles() as $relativePath => $source) {
+            foreach (PrimaryKeyStaticQueryScanner::candidates($source, $relativePath, $tables) as $candidate) {
+                $candidates[] = $candidate;
+            }
+        }
+
+        return $candidates;
+    }
+
+    /**
+     * 走査対象のソース (リポジトリ相対パス => 全文)。
+     *
+     * @return array<string, string>
+     */
+    public static function sourceFiles(): array
+    {
+        $sources = [];
+
+        foreach (Finder::create()->files()->in(base_path('app'))->name('*.php')->sortByName() as $file) {
+            $sources['app/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname())] = $file->getContents();
+        }
+        foreach (Finder::create()->files()->in(base_path('routes'))->depth(0)->name('*.php')->sortByName() as $file) {
+            $sources['routes/'.$file->getFilename()] = $file->getContents();
+        }
+
+        return $sources;
+    }
+
+    /**
+     * 同一 fingerprint (path / scope / root / predicate / identity) の重複を人が確認済みの group。
+     *
+     * 重複があると ordinal 依存になり「裁定理由が別の候補へ横滑りする」余地が残るため、
+     * 明示登録された group だけを許可する (v1 は実測 0 件)。
+     *
+     * @return list<string>
+     */
+    public static function reviewedDuplicateFingerprints(): array
+    {
+        return [];
+    }
+
+    /**
+     * 動的列名 (`where($field, $value)`) を使うクラス起点 chain の inventory。
+     *
+     * 列名が字句的に確定しないため主キー同一性の候補にできない (走査器の範囲外) が、
+     * 放置すると `$column = 'id'; User::query()->where($column, $payloadId);` で
+     * gate を黙らせられる。実測 3 件と 0 件ではないため「0 件固定」ではなく
+     * **明示 inventory + 双方向整合**で見張る。
+     *
+     * @return array<string, string> 記述子 => その形が安全である理由
+     */
+    public static function reviewedDynamicColumnPredicates(): array
+    {
+        return [
+            'Http/Routing/MembershipScopedOrganizationBinder.php#bind#Organization.where:$field' => '$field は route の bindingFieldFor 由来で BINDABLE_FIELDS の allowlist を通っており、'
+                    .'解決結果は同一 chain の membership スコープに閉じている',
+            'Support/Billing/BillingNotificationRecorder.php#markSentBy#BillingNotification.where:$column' => '$column は呼び出し元がリテラルで渡す通知の dedup 列名で、request 由来の値ではない。'
+                    .'BillingNotification はテナント資源でなく通知の送達記録である',
+            'Support/Billing/BillingNotificationRecorder.php#markFailedReasonBy#BillingNotification.where:$column' => '$column は呼び出し元がリテラルで渡す通知の dedup 列名で、request 由来の値ではない。'
+                    .'BillingNotification はテナント資源でなく通知の送達記録である',
+        ];
+    }
+
+    /**
+     * 候補 key => 裁定エントリ。
+     *
+     * ★ここに足す前に必ず「relation 起点 (`$organization->users()->whereKey(...)`) に
+     *   直せないか」を検討すること。分類は「直せない」ことが確認できた場合の最後の手段である。
+     *
+     * @return array<string, DirectFetchJustificationEntry>
+     */
+    public static function inventory(): array
+    {
+        return [
+            // --- 運用コマンド (HTTP から到達不能) ---
+            'Console/Commands/ResetAdminMfaCommand.php#handle#AdminUser.find:$id#1' => DirectFetchJustificationEntry::operatorConsole(
+                '運用者が CLI で AdminUser を id で名指しして MFA をリセットする保守コマンド。'
+                .'HTTP から到達不能で scheduler / queue からも呼ばれず、--reason を監査ログへ残す',
+                commandSignature: 'admin:reset-mfa {id} {--reason=}',
+            ),
+
+            // --- 認証済み actor / 検証済み token claim 由来 ---
+            'Http/Controllers/Api/V1/Me/RevokeSessionController.php#destroy#OauthSession.find:$sessionId#1' => DirectFetchJustificationEntry::authenticatedActor(
+                'session id は resolve.api-actor が Passport の access token レコードから解決した '
+                .'ApiActorContext::$oauthSessionId であり、request payload / query string からは受け取らない',
+                actorSource: 'passport_token_record',
+            ),
+            'Http/Middleware/EnsureLoginMethodRemains.php#handle#User.whereKey:$user->getKey()#1' => DirectFetchJustificationEntry::authenticatedActor(
+                '対象は $request->user() で確定した認証中の本人のみで、他者を指せる入力が存在しない。'
+                .'ロック下の再取得のために主キーで引き直している (投影評価をロック後に限定するため)',
+                actorSource: 'authenticated_user',
+            ),
+            'Http/Middleware/ResolveApiActor.php#contextFromUserToken#OauthSession.find:$row->session_id#1' => DirectFetchJustificationEntry::authenticatedActor(
+                'session id は oauth_access_tokens 行 (提示された access token 自身のレコード) の列であり、'
+                .'client からは指定できない。直後に user_id / organization_id の一致も再検証している',
+                actorSource: 'passport_token_record',
+            ),
+            'Http/Middleware/ResolveApiActor.php#contextFromUserToken#Organization.find:$organizationId#1' => DirectFetchJustificationEntry::authenticatedActor(
+                'organization id は access token レコードに紐づく列で request payload からは受け取らない。'
+                .'取得後に $user->isMemberOf() を毎リクエスト再検証し、除名済み token を即時失効同等に扱う',
+                actorSource: 'passport_token_record',
+            ),
+            'Passport/Grants/McpRefreshTokenGrant.php#assertSessionRefreshable#OauthSession.find:$sessionId#1' => DirectFetchJustificationEntry::authenticatedActor(
+                'session id は署名検証済み refresh token の claim から取り出した値であり、'
+                .'League OAuth2 server が復号・検証を終えた後にしか本メソッドへ到達しない',
+                actorSource: 'validated_token_claim',
+            ),
+            'Passport/McpAuthCodeRepository.php#persistNewAuthCode#User.find:$userId#1' => DirectFetchJustificationEntry::authenticatedActor(
+                'user id は League が確立した AuthCodeEntity の user identifier (consent 時に認証済みの本人) で、'
+                .'authorize request の payload からは受け取らない',
+                actorSource: 'validated_token_claim',
+            ),
+            'Passport/McpAuthCodeRepository.php#persistNewAuthCode#Organization.find:$orgId#1' => DirectFetchJustificationEntry::authenticatedActor(
+                'organization id は McpConsentOrganizationBinder が membership 検証後に request attributes へ'
+                .'置いた値で、client の payload を直接読んでいない (attributes はサーバ側で確定するバッグ)',
+                actorSource: 'validated_token_claim',
+            ),
+            'Services/Mcp/Auth/McpAuthorizationContext.php#for#Organization.find:$orgId#1' => DirectFetchJustificationEntry::authenticatedActor(
+                'organization id は提示された access token 自身のレコード (oauth_access_tokens.organization_id) の値で、'
+                .'MCP tool 引数からは受け取らない。取得後に isMemberOf() で剥奪済み membership も拒否する',
+                actorSource: 'passport_token_record',
+            ),
+
+            // --- local 限定 (production では route が存在しない) ---
+            'Http/Controllers/DebugLoginController.php#loginAs#User.findOrFail:$userId#1' => DirectFetchJustificationEntry::localOnly(
+                'local 専用のデバッグログイン。routes/web.php 側で isLocal / runningUnitTests に囲われており '
+                .'production では route 自体が登録されない。加えて LocalOnly middleware が二重防御になる',
+                routeName: 'debug.login-as',
+            ),
+
+            // --- 同一クエリ内で所有者スコープが閉じている ---
+            'Http/Routing/SelfScopedPasskeyBinder.php#bind#Passkey.whereKey:$id#1' => DirectFetchJustificationEntry::ownerScopedQuery(
+                '所有者スコープの where を解決クエリ自体に含めている (取得後に弾くと 403/404 の差で存在が漏れる)。'
+                .'relation 起点にできないのは PasskeyUser interface が vendor 型で解決され App\Models\Passkey を返せないため',
+            ),
+
+            // --- queue payload の再水和 (id は enqueue 時にサーバが確定) ---
+            'Jobs/Billing/AutoRechargeTriggerJob.php#handle#Organization.find:$this->organizationId#1' => DirectFetchJustificationEntry::queuePayload(
+                'organization id は予約確定 (reserve) 時にサーバが解決済みモデルから採番した値で、'
+                .'HTTP 入力を経由せず dispatch される。worker 側は再水和のみ行う',
+                enqueuedBy: 'App\Services\Billing\TicketLedgerService::reserve',
+            ),
+            'Jobs/Billing/ExecuteAutoRechargeAttemptJob.php#handle#TicketAutoRechargeAttempt.find:$this->attemptId#1' => DirectFetchJustificationEntry::queuePayload(
+                'attempt id は AutoRechargeTriggerJob がサーバ側で作成した attempt 行の主キーであり、'
+                .'client からは指定できない。worker 側は再水和のみ行う',
+                enqueuedBy: 'App\Jobs\Billing\AutoRechargeTriggerJob::handle',
+            ),
+            'Jobs/Billing/HandleAutoRechargeChargeFailureJob.php#handle#TicketAutoRechargeAttempt.find:$this->attemptId#1' => DirectFetchJustificationEntry::queuePayload(
+                'attempt id は署名検証済み Stripe webhook の処理中にサーバが特定した attempt 行の主キーで、'
+                .'HTTP payload の値をそのまま id として使っていない',
+                enqueuedBy: 'App\Services\Billing\StripeWebhookProcessor::handleInvoicePaymentFailed',
+            ),
+            'Jobs/Billing/ReuseSubscriptionPaymentMethodJob.php#handle#Organization.find:$this->organizationId#1' => DirectFetchJustificationEntry::queuePayload(
+                'organization id は署名検証済み webhook の処理中にローカル subscription 行から解決した値であり、'
+                .'webhook payload が直接指定した id ではない',
+                enqueuedBy: 'App\Services\Billing\StripeWebhookProcessor::settleSubscriptionCheckout',
+            ),
+            'Jobs/Billing/SetDefaultPaymentMethodJob.php#handle#Organization.find:$this->organizationId#1' => DirectFetchJustificationEntry::queuePayload(
+                'organization id は署名検証済み webhook の処理中にサーバ側で解決した値で、'
+                .'client が指定した値をそのまま id として使っていない',
+                enqueuedBy: 'App\Services\Billing\StripeWebhookProcessor::completeAutoRechargeSetup',
+            ),
+            'Jobs/Manual/DeleteRenderOutputsJob.php#handle#RenderJob.find:$this->renderJobId#1' => DirectFetchJustificationEntry::queuePayload(
+                'render job id は reconcile 走査がサーバ側で列挙した RenderJob の主キーで、HTTP 入力を経由しない。'
+                .'worker 側は再水和して出力 prefix の一致を確認してから削除する',
+                enqueuedBy: 'App\Services\Manual\RenderJobService::reconcileOutputs',
+            ),
+            'Jobs/Manual/RunManualAnalysis.php#failed#AnalysisJob.find:$this->analysisJobId#1' => DirectFetchJustificationEntry::queuePayload(
+                'analysis job id は trigger がテナント検証済みの manual から採番して dispatch した値で、'
+                .'payload にモデル/組織値を持たない (payload 不信任の設計)。failed() は再水和して失敗記録のみ行う',
+                enqueuedBy: 'App\Services\Manual\AnalysisJobService::trigger',
+            ),
+            'Jobs/Manual/RunManualRender.php#failed#RenderJob.find:$this->renderJobId#1' => DirectFetchJustificationEntry::queuePayload(
+                'render job id は trigger がテナント検証済みの manual から採番して dispatch した値で、'
+                .'payload にモデル/組織値を持たない。failed() は再水和して失敗記録のみ行う',
+                enqueuedBy: 'App\Services\Manual\RenderJobService::trigger',
+            ),
+            'Services/Manual/AnalysisPipeline.php#run#AnalysisJob.findOrFail:$analysisJobId#1' => DirectFetchJustificationEntry::queuePayload(
+                'RunManualAnalysis::handle が $this->analysisJobId をそのまま渡す委譲先。id は trigger が採番した'
+                .'サーバ確定値で HTTP 入力を経由しない (Service 側に置くのは worker の SIGALRM 予算と分離するため)',
+                enqueuedBy: 'App\Jobs\Manual\RunManualAnalysis::handle',
+            ),
+            'Services/Manual/RenderPipeline.php#run#RenderJob.findOrFail:$renderJobId#1' => DirectFetchJustificationEntry::queuePayload(
+                'RunManualRender::handle が $this->renderJobId をそのまま渡す委譲先。id は trigger が採番した'
+                .'サーバ確定値で HTTP 入力を経由しない',
+                enqueuedBy: 'App\Jobs\Manual\RunManualRender::handle',
+            ),
+
+            // --- テナントスコープ済みの解決から確定した id ---
+            'Services/Billing/PersonalPlanService.php#activateWithinTransaction#Organization.findOrFail:$organizationId#1' => DirectFetchJustificationEntry::idFromTenantScopedQuery(
+                'id は型付き引数 Organization $org の主キーで、request からは受け取らない。'
+                .'行ロック下で最新状態を取り直すために主キーで引き直している (reserve と同じ直列化点)',
+            ),
+            'Services/Project/DefaultProjectResolver.php#resolveForUpdate#Project.whereKey:$id#1' => DirectFetchJustificationEntry::idFromTenantScopedQuery(
+                'id は直前の $organization->projects() で組織スコープ済み。HasManyThrough に lockForUpdate を'
+                .'掛けると JOIN 先までロックするため、単一テーブルの主キーロックに落としている',
+            ),
+
+            // --- 同一メソッド内の走査クエリ由来 (保守処理) ---
+            'Services/Billing/TicketLedgerService.php#releaseStale#TicketReservation.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
+                'id は同一メソッドが status / expires_at で列挙した TicketReservation の主キー。'
+                .'期限切れ予約の解放は全テナント横断の保守処理であり cron から呼ばれる (HTTP 入力を経由しない)',
+            ),
+            'Services/Capture/StaleUploadReservationSweeper.php#sweep#TakeUploadReservation.whereKey:$reservation->id#1' => DirectFetchJustificationEntry::sameMethodQuery(
+                'id は同一メソッドが status / expires_at で列挙した予約行の主キー。孤児オブジェクト回収は'
+                .'全テナント横断の保守処理で cron から呼ばれる。whereKey は CAS 更新の対象行指定に使っている',
+            ),
+            'Services/Manual/AnalysisJobService.php#recoverStale#AnalysisJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
+                'id は同一メソッドが status / 経過時間で列挙した AnalysisJob の主キー。'
+                .'stale ジョブの回復は全テナント横断の保守処理で cron から呼ばれる (HTTP 入力を経由しない)',
+            ),
+            'Services/Manual/RenderJobService.php#recoverStale#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
+                'id は同一メソッドが status / 経過時間で列挙した RenderJob の主キー。'
+                .'stale ジョブの回復は全テナント横断の保守処理で cron から呼ばれる (HTTP 入力を経由しない)',
+            ),
+            'Services/Manual/RenderJobService.php#reconcileOutputs#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
+                'id は同一メソッドが output_path 非 NULL で列挙した RenderJob の主キー。'
+                .'世代交代済み出力の整合回復は全テナント横断の保守処理で cron から呼ばれる',
+            ),
+            'Services/OAuth/OauthSessionListService.php#legacyMcpTokens#User.whereIn:id:in:$userIds#1' => DirectFetchJustificationEntry::sameMethodQuery(
+                'user id 群は同一メソッドが t.organization_id で組織スコープ済みに列挙した token 行の列由来。'
+                .'名前は暗号化列のため raw join で復号できず、復号目的で Eloquent 経由の再取得が要る',
+            ),
+
+            // --- 呼び出し元で確定した id を private ヘルパが受け取る形 ---
+            'Services/Organization/OrganizationMembershipService.php#lockForMembershipWrite#DB:users.whereIn:id:in:$sortedUserIds#1' => DirectFetchJustificationEntry::internalCaller(
+                'private な共通ロック境界。id は呼び出し元が解決済みモデルから keyOf() で取り出した値で、'
+                .'本メソッドは行ロック取得のみ行い結果を読まない (deadlock 回避のため昇順で並べ替えている)',
+                calledBy: 'App\Services\Organization\OrganizationMembershipService::transferOwnership',
+            ),
+            'Services/Organization/OrganizationMembershipService.php#lockForMembershipWrite#DB:organizations.whereIn:id:in:$sortedOrgIds#1' => DirectFetchJustificationEntry::internalCaller(
+                'private な共通ロック境界。id は呼び出し元が解決済みモデルから keyOf() で取り出した値で、'
+                .'本メソッドは行ロック取得のみ行い結果を読まない (users → organizations の順序も固定している)',
+                calledBy: 'App\Services\Organization\OrganizationMembershipService::transferOwnership',
+            ),
+
+            // --- ★債務 (新規コードで使わない。fetch 時点でスコープが閉じていない) ---
+            'Http/Controllers/Organizations/OrganizationOwnershipController.php#store#User.findOrFail:$userId#1' => DirectFetchJustificationEntry::globalExistenceRuleDebt(
+                'payload の user_id を組織スコープ外で引いている。移譲先が組織メンバーであることの検証は'
+                .'Service のロック下で行われるが、fetch 時点ではスコープが閉じていない',
+                verifiedBy: 'App\Services\Organization\OrganizationMembershipService::transferOwnership',
+                validationRule: 'exists:users,id',
+                todoRef: 'devnotes/20260805-2311-model-direct-fetch-gate/follow-up-todo.md',
+            ),
+            'Http/Controllers/Projects/ProjectMemberController.php#store#User.findOrFail:$userId#1' => DirectFetchJustificationEntry::globalExistenceRuleDebt(
+                'payload の user_id を組織スコープ外で引いている。組織メンバーであることの確認は'
+                .'fetch 後の organizationRole() 判定であり、fetch 時点ではスコープが閉じていない',
+                verifiedBy: 'App\Http\Controllers\Projects\ProjectMemberController::store',
+                validationRule: 'exists:users,id',
+                todoRef: 'devnotes/20260805-2311-model-direct-fetch-gate/follow-up-todo.md',
+            ),
+            'Http/Middleware/McpConsentOrganizationBinder.php#handle#Organization.find:$orgId#1' => DirectFetchJustificationEntry::globalExistenceRuleDebt(
+                'consent payload の organization_id を組織スコープ外で引いている。membership 確認は'
+                .'fetch 後の organizations()->whereKey()->exists() であり、fetch 時点ではスコープが閉じていない',
+                verifiedBy: 'App\Http\Middleware\McpConsentOrganizationBinder::handle',
+                validationRule: 'filter_var(FILTER_VALIDATE_INT, min_range=1)',
+                todoRef: 'devnotes/20260805-2311-model-direct-fetch-gate/follow-up-todo.md',
+            ),
+        ];
+    }
+
+    private static function classOf(SplFileInfo $file): string
+    {
+        $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $file->getRelativePathname());
+
+        return 'App\\Models\\'.substr($relative, 0, -4);
+    }
+}
diff --git a/tests/Support/Security/DirectFetchJustificationEntry.php b/tests/Support/Security/DirectFetchJustificationEntry.php
new file mode 100644
index 0000000..b6476b9
--- /dev/null
+++ b/tests/Support/Security/DirectFetchJustificationEntry.php
@@ -0,0 +1,160 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Security;
+
+use App\Enums\Security\DirectFetchJustification;
+use Webmozart\Assert\Assert;
+
+/**
+ * 直 fetch 候補 1 件分の裁定エントリ。
+ *
+ * case ごとに必須の構造化 field が違うため、**名前付きコンストラクタ経由でのみ**生成できる。
+ * nullable プロパティ + 実行時検査にすると検査漏れがそのまま抜け道になるため、
+ * 「case を選んだ時点で必須 field が型として要求される」形にしてある
+ * (実行時チェックより先に PHPStan 段で止まる)。
+ */
+final readonly class DirectFetchJustificationEntry
+{
+    /** 根拠文の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
+    public const int REASON_MIN_LENGTH = 30;
+
+    /**
+     * @param  array<string, string>  $metadata  case 固有の構造化 field
+     */
+    private function __construct(
+        public DirectFetchJustification $case,
+        public string $reason,
+        public array $metadata,
+    ) {
+        Assert::minLength($this->reason, self::REASON_MIN_LENGTH);
+    }
+
+    public static function ownerScopedQuery(string $reason): self
+    {
+        return new self(DirectFetchJustification::OwnerScopedQueryConstraint, $reason, []);
+    }
+
+    public static function idFromTenantScopedQuery(string $reason): self
+    {
+        return new self(DirectFetchJustification::IdDerivedFromTenantScopedQuery, $reason, []);
+    }
+
+    public static function sameMethodQuery(string $reason): self
+    {
+        return new self(DirectFetchJustification::IdDerivedFromSameMethodQuery, $reason, []);
+    }
+
+    /** @param  string  $calledBy  呼び出し元の `Class::method` */
+    public static function internalCaller(string $reason, string $calledBy): self
+    {
+        return new self(DirectFetchJustification::IdSuppliedByInternalCaller, $reason, [
+            'calledBy' => $calledBy,
+        ]);
+    }
+
+    /** @param  'authenticated_user'|'validated_token_claim'|'passport_token_record'  $actorSource */
+    public static function authenticatedActor(string $reason, string $actorSource): self
+    {
+        return new self(DirectFetchJustification::AuthenticatedActorScope, $reason, [
+            'actorSource' => $actorSource,
+        ]);
+    }
+
+    /** @param  string  $enqueuedBy  dispatch 元の `Class::method` */
+    public static function queuePayload(string $reason, string $enqueuedBy): self
+    {
+        return new self(DirectFetchJustification::QueuePayloadRehydration, $reason, [
+            'enqueuedBy' => $enqueuedBy,
+        ]);
+    }
+
+    /** @param  string  $routeName  route 走査で LocalOnly middleware を照合する対象 */
+    public static function localOnly(string $reason, string $routeName): self
+    {
+        return new self(DirectFetchJustification::LocalOnlyDiagnostics, $reason, [
+            'routeName' => $routeName,
+        ]);
+    }
+
+    public static function operatorConsole(string $reason, string $commandSignature): self
+    {
+        return new self(DirectFetchJustification::OperatorInvokedConsoleCommand, $reason, [
+            'commandSignature' => $commandSignature,
+        ]);
+    }
+
+    /**
+     * **債務**エントリ。新規コードで使わない。
+     *
+     * @param  string  $verifiedBy  補償チェックを行う `Class::method`
+     * @param  string  $validationRule  当該 id に掛けている validation rule (例 `exists:users,id`)
+     * @param  string  $todoRef  是正を追跡する成果物への参照。ModelDirectFetchInvariantTest が
+     *                           **実在を機械検証する**ため、次のいずれかでなければならない:
+     *                           (a) `aicue:T<番号>` 形式の TODO ID (docs/TODO.md か docs/TODO-closed.md に実在すること)
+     *                           (b) リポジトリ相対のファイルパス (存在すること)
+     */
+    public static function globalExistenceRuleDebt(
+        string $reason,
+        string $verifiedBy,
+        string $validationRule,
+        string $todoRef,
+    ): self {
+        return new self(DirectFetchJustification::PayloadIdWithGlobalExistenceRuleDebt, $reason, [
+            'verifiedBy' => $verifiedBy,
+            'validationRule' => $validationRule,
+            'todoRef' => $todoRef,
+        ]);
+    }
+
+    // --- 構造化 field の accessor (typo を runtime まで持ち越さない) ---
+
+    public function actorSource(): string
+    {
+        return $this->require('actorSource');
+    }
+
+    public function enqueuedBy(): string
+    {
+        return $this->require('enqueuedBy');
+    }
+
+    public function calledBy(): string
+    {
+        return $this->require('calledBy');
+    }
+
+    public function routeName(): string
+    {
+        return $this->require('routeName');
+    }
+
+    public function commandSignature(): string
+    {
+        return $this->require('commandSignature');
+    }
+
+    public function verifiedBy(): string
+    {
+        return $this->require('verifiedBy');
+    }
+
+    public function validationRule(): string
+    {
+        return $this->require('validationRule');
+    }
+
+    public function todoRef(): string
+    {
+        return $this->require('todoRef');
+    }
+
+    /** 当該 case が持たない field を読んだら設定ミスとして落とす。 */
+    private function require(string $key): string
+    {
+        Assert::keyExists($this->metadata, $key, $this->case->value.' は '.$key.' を持たない');
+
+        return $this->metadata[$key];
+    }
+}
diff --git a/tests/Support/Security/PrimaryKeyPredicateKind.php b/tests/Support/Security/PrimaryKeyPredicateKind.php
new file mode 100644
index 0000000..15a3ea0
--- /dev/null
+++ b/tests/Support/Security/PrimaryKeyPredicateKind.php
@@ -0,0 +1,26 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Security;
+
+/**
+ * 主キー述語の種別。
+ *
+ * `findMany($ids)` と `findOrFail($id)` を同じ扱いにすると、
+ * identity 引数を単数前提で判定する副条件 (provenance 除外など) が破綻するため分けている。
+ */
+enum PrimaryKeyPredicateKind
+{
+    /** `find` / `findOrFail` / `findOrNew` / `whereKey` / `where('id', …)` / `firstWhere('id', …)` */
+    case SingleIdentity;
+
+    /** `findMany` / `whereIn('id', …)` / `where('id', 'in', …)` */
+    case MultiIdentity;
+
+    /** `whereKeyNot` — 「同一性」ではなく除外条件 (列挙ベクタになりうる) */
+    case IdentityExclusion;
+
+    /** `destroy` — 取得ではなく削除 */
+    case DestructiveIdentity;
+}
diff --git a/tests/Support/Security/PrimaryKeyStaticQueryCandidate.php b/tests/Support/Security/PrimaryKeyStaticQueryCandidate.php
new file mode 100644
index 0000000..acf89f3
--- /dev/null
+++ b/tests/Support/Security/PrimaryKeyStaticQueryCandidate.php
@@ -0,0 +1,52 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Security;
+
+/**
+ * クラス起点の主キー同一性クエリ (`ClassRootedPrimaryKeyQuery`) の候補 1 件。
+ *
+ * `PrimaryKeyStaticQueryScanner` が抽出し、`ModelDirectFetchInvariantTest` が
+ * `DirectFetchInventory::inventory()` と突合する。
+ */
+final readonly class PrimaryKeyStaticQueryCandidate
+{
+    /**
+     * @param  string  $key  構造 fingerprint 入りの安定 key (行番号を含めない)
+     * @param  string  $relativePath  リポジトリ相対パス
+     * @param  string  $scopeName  メソッド名、または routes/*.php の疑似スコープ名 (`__file` / `__closure1` / `__fn1`)
+     * @param  string  $rootKind  `User` (モデル短縮名) / `DB:users` (テーブル名)
+     * @param  string  $predicate  `findOrFail` / `whereKey` / `where:id:=` 等
+     * @param  string  $identityArgument  正規化した識別子引数 (cast は除去済み)
+     * @param  string  $chainSource  候補式を構成する chain のトークン列
+     * @param  string  $methodSource  候補が属するスコープ (メソッド / 疑似スコープ) のトークン列
+     * @param  list<string>  $provenModelVariables  当該スコープで `App\Models\*` と証明できた変数名
+     */
+    public function __construct(
+        public string $key,
+        public string $relativePath,
+        public string $scopeName,
+        public PrimaryKeyPredicateKind $predicateKind,
+        public string $rootKind,
+        public string $predicate,
+        public string $identityArgument,
+        public string $chainSource,
+        public string $methodSource,
+        public array $provenModelVariables,
+    ) {}
+
+    /** ordinal を除いた構造 fingerprint (テスト 15 の重複検出に使う)。 */
+    public function fingerprint(): string
+    {
+        return $this->displayPath().'#'.$this->scopeName.'#'.$this->rootKind.'.'.$this->predicate.':'.$this->identityArgument;
+    }
+
+    /** key に使う表示パス (`app/` 配下は `app/` を落とし、`routes/` はそのまま)。 */
+    public function displayPath(): string
+    {
+        return str_starts_with($this->relativePath, 'app/')
+            ? substr($this->relativePath, 4)
+            : $this->relativePath;
+    }
+}
diff --git a/tests/Support/Security/PrimaryKeyStaticQueryScanner.php b/tests/Support/Security/PrimaryKeyStaticQueryScanner.php
new file mode 100644
index 0000000..149e499
--- /dev/null
+++ b/tests/Support/Security/PrimaryKeyStaticQueryScanner.php
@@ -0,0 +1,2181 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Security;
+
+/**
+ * クラス起点の主キー同一性クエリ (`ClassRootedPrimaryKeyQuery`) の字句解析器。
+ *
+ * `ModelDirectFetchInvariantTest` (直 fetch の deny-by-default gate) の検出ロジックを
+ * 母集団走査から切り離した純粋 helper。「母集団走査と突合 = テスト、字句解析 = 本 helper」
+ * という `AuthorizationMarkerScanner` と同じ責務分離で、解析器そのものの positive/negative は
+ * `tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php` が恒久固定する
+ * (gate 自体がセキュリティ機構であり、走査器が壊れたら gate は静かに無力化するため)。
+ *
+ * ★設計判断:
+ *  - 正規表現ではなく `token_get_all` の状態機械にする。コメント / docblock 中の
+ *    `AuthenticatedSessionController::destroy()` を誤検出した実例があるため、
+ *    コメントはトークン段で除去する (文字列リテラルは列名照合に要るのでトークンとしては残すが、
+ *    その**中身をコードとして解釈しない**)。
+ *  - 検出アンカーはメソッド名の列挙ではなく「**静的起点 + 主キー同一性述語**」という意味に張る。
+ *    `Model::find()` だけを禁じても `Model::query()->where('id', $payload)->firstOrFail()` で
+ *    等価なことができる。
+ *  - `use` import による裏取りを行う。これが無いと同名の別クラス
+ *    (`SomeOtherPackage\User::find()`) を誤検出する。
+ *
+ * ★builder alias の fail 方向 (重要):
+ *  `$q = User::query();` のように**一度でも静的起点から代入された変数**は、同一スコープ内の
+ *  以降の使用をすべて候補として扱う。**再代入があっても取り消さない**。
+ *  取り消す実装にすると `$q = User::query(); if ($x) { $q = $other; } $q->whereKey($id);` が
+ *  検出されなくなり、「再代入すれば gate を黙らせられる」という最も安易な回避手段になる
+ *  (= fail-open)。**誤検出は分類 1 行で解消できるが、検出漏れは永久に気付けない**という
+ *  非対称性を根拠に、過剰検出側 (fail-closed) へ倒している。
+ *
+ * ★本 helper の限界 (意図的な線引き。テストの docblock にも明記する):
+ *  - **到達可能性を判定しない** (`if (false) { … }` 中の候補も候補になる)。
+ *  - `whereRaw` / `whereIntegerInRaw` / 動的列名 (`where($col, $x)`) は**範囲外**。
+ *    範囲外を放置しないため {@see self::containsRawPrimaryKeyPredicate()} が
+ *    0 件 assertion 用の検出を提供する。
+ *  - alias 追跡は同一スコープ内の単純代入のみ。引数渡し・プロパティ代入・
+ *    メソッドをまたぐ伝播は追跡しない。
+ *  - provenance 証明は「変数が `App\Models\*` である」ことまで (第 1 段) しか行わない。
+ *    「その元モデルが保証済み provenance に属する」ことの証明 (第 2 段) は v1 では実装せず、
+ *    代償措置として {@see self::uniqueColumnResolutions()} の 0 件固定を置く。
+ *  - 非 bracketed namespace (`namespace App\Foo;` 形式) を前提とする
+ *    (`AuthorizationMarkerScanner` と同じ前提。Pint が強制している)。
+ */
+final class PrimaryKeyStaticQueryScanner
+{
+    /** 所有者/テナント制約とみなす列 (`OwnerScopedQueryConstraint` の許可 signature)。 */
+    private const OWNER_COLUMNS = ['organization_id', 'user_id', 'team_id', 'project_id'];
+
+    /**
+     * 非主キー一意列 (provenance 前提の見張り用)。
+     *
+     * `code` を含めるのは、列名だけで丸ごと除外すると将来
+     * `OrganizationInvitation::where('code', $payload)` のような**テナント資源**が生えても
+     * 検知できなくなるため。グローバルカタログである `Plan` 起点のみ {@see self::CATALOG_ROOTS}
+     * で除外する。CipherSweet の `whereBlind(…)` は列名を取らないため本一覧に現れない
+     * (AGENTS.md セキュリティ不変条件「PII は CipherSweet」が別途統制する)。
+     */
+    private const UNIQUE_COLUMNS = ['uuid', 'slug', 'public_id', 'ulid', 'code'];
+
+    /** `code` 列による解決を除外してよい root (グローバルカタログでテナント資源でない)。 */
+    private const CATALOG_ROOTS = ['Plan', 'DB:plans'];
+
+    /** 列名を第 1 引数に取る述語 (or 系も含める。片方だけ見ると `orWhere('id', …)` が素通りする)。 */
+    private const COLUMN_PREDICATES = [
+        'where', 'orWhere', 'firstWhere', 'whereIn', 'orWhereIn', 'whereNotIn', 'orWhereNotIn',
+    ];
+
+    /** raw SQL を直接渡す述語 (本 gate の範囲外。0 件 assertion で見張る)。 */
+    private const RAW_PREDICATES = [
+        'whereRaw', 'orWhereRaw', 'havingRaw', 'orHavingRaw',
+        'whereIntegerInRaw', 'orWhereIntegerInRaw', 'whereIntegerNotInRaw', 'orWhereIntegerNotInRaw',
+    ];
+
+    /**
+     * chain を「取得」ではなく「削除」に変える終端 (`whereKey($id)->delete()`)。
+     *
+     * `update` は含めない — CAS 更新 (`whereKey($id)->where('status', …)->update(…)`) は
+     * 識別子による削除とは危険度が違い、含めると既存の正当な CAS 経路まで
+     * DestructiveIdentity になって許可表の意味が薄れるため。
+     */
+    private const DESTRUCTIVE_TERMINATORS = ['delete', 'forceDelete', 'restore', 'truncate'];
+
+    /** DB ファサードの完全修飾名 (同名の別クラスによる誤検出を防ぐ)。 */
+    private const DB_FACADE = 'Illuminate\Support\Facades\DB';
+
+    /**
+     * クエリを**実行して結果を返す**メソッド (builder alias の伝播を打ち切る境界)。
+     *
+     * ここで終わる代入式の左辺は Builder ではなく Model / Collection / scalar である。
+     */
+    private const EXECUTOR_METHODS = [
+        'get', 'first', 'firstOrFail', 'firstOr', 'firstOrCreate', 'firstOrNew', 'firstWhere',
+        'sole', 'find', 'findOrFail', 'findOr', 'findOrNew', 'findMany', 'value', 'pluck',
+        'exists', 'doesntExist', 'count', 'sum', 'max', 'min', 'avg', 'paginate', 'simplePaginate',
+        'cursorPaginate', 'chunk', 'chunkById', 'each', 'create', 'update', 'delete', 'destroy',
+        'toArray', 'toBase', 'all', 'lazy', 'cursor', 'increment', 'decrement', 'insert', 'upsert',
+    ];
+
+    /**
+     * request の「入力を読む」accessor。
+     *
+     * `user()` / `attributes` はここに含めない (前者は認証済み actor、後者は middleware が
+     * サーバ側で確定させたバッグであり、どちらも client 由来の payload ではないため)。
+     */
+    private const REQUEST_INPUT_ACCESSORS = [
+        'input', 'query', 'post', 'json', 'all', 'only', 'except', 'validated', 'safe',
+        'has', 'hasAny', 'filled', 'missing', 'boolean', 'string', 'integer', 'float',
+        'date', 'enum', 'collect', 'get', 'header', 'headers', 'cookie', 'route',
+        'segment', 'segments', 'path', 'url', 'fullUrl', 'getContent', 'file', 'allFiles',
+    ];
+
+    /**
+     * @param  list<array{id: int, text: string}>  $tokens
+     * @param  list<string>  $modelTables
+     * @param  array<string, string>  $imports  短縮名 => FQCN
+     * @param  list<array{name: string, start: int, end: int}>  $scopes
+     * @param  list<int>  $scopeIdOf  トークン位置 => scope 添字 (-1 = ファイル直下)
+     * @param  array<string, string>  $docVarTypes  変数名 => `@var` で宣言された型名
+     * @param  array<string, string>  $methodReturnTypes  メソッド名 => 戻り値型宣言
+     */
+    private function __construct(
+        private readonly array $tokens,
+        private readonly string $relativePath,
+        private readonly array $modelTables,
+        private readonly array $imports,
+        private readonly string $namespace,
+        private readonly ?string $selfClass,
+        private readonly array $scopes,
+        private readonly array $scopeIdOf,
+        private readonly array $docVarTypes,
+        private readonly array $methodReturnTypes,
+    ) {}
+
+    /**
+     * ファイル 1 本から候補 (ClassRootedPrimaryKeyQuery) を抽出する。
+     *
+     * @param  string  $source  PHP ソース全文
+     * @param  string  $relativePath  リポジトリ相対パス (候補 key の生成に使う)
+     * @param  list<string>  $modelTables  `App\Models\*` に対応するテーブル名
+     * @return list<PrimaryKeyStaticQueryCandidate>
+     */
+    public static function candidates(string $source, string $relativePath, array $modelTables): array
+    {
+        return self::make($source, $relativePath, $modelTables)->scan();
+    }
+
+    /**
+     * 非主キー一意列 (`uuid` / `slug` / `public_id` / `ulid` / `code`) による解決の一覧。
+     *
+     * provenance 証明の第 2 段を v1 で実装しないことの代償措置。
+     * 「呼び出し側が model を非主キー一意列で untrusted 入力から解決している」経路が
+     * 生えた瞬間に気付くための見張りであり、**第 2 段と同等の証明ではない**。
+     *
+     * @param  list<string>  $modelTables
+     * @return list<string> 人が読める記述子 (`Models/Plan.php#lookup#Plan.where:slug`)
+     */
+    public static function uniqueColumnResolutions(string $source, string $relativePath, array $modelTables): array
+    {
+        return self::make($source, $relativePath, $modelTables)->scanUniqueColumns();
+    }
+
+    /**
+     * クラス起点 chain のうち、列名が**動的** (変数 / 定数式) な述語の一覧。
+     *
+     * 動的列名は列が `id` かを字句的に決められないため候補にできない (範囲外) が、
+     * 放置すると `$column = 'id'; User::query()->where($column, $payloadId);` で
+     * gate を黙らせられる。0 件ではない (membership binder が実在する) ため
+     * 「0 件固定」ではなく**明示 inventory**で見張る。
+     *
+     * @param  list<string>  $modelTables
+     * @return list<string> 人が読める記述子
+     */
+    public static function dynamicColumnPredicates(string $source, string $relativePath, array $modelTables): array
+    {
+        return self::make($source, $relativePath, $modelTables)->scanDynamicColumns();
+    }
+
+    /**
+     * 文字列リテラル `$literal` が、`$guards` のいずれかを含む条件式のブロック内に現れるか。
+     *
+     * `routes/*.php` で「この route は local 限定の登録条件の中にある」ことを
+     * ファイル全体の文字列一致より強く確認するために使う (波括弧の対応をトークンで数える)。
+     *
+     * @param  list<string>  $guards
+     */
+    public static function literalIsInsideGuardedBlock(string $source, string $literal, array $guards): bool
+    {
+        $tokens = self::significantTokens($source);
+        $count = count($tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_STRING || ! in_array($tokens[$i]['text'], $guards, true)) {
+                continue;
+            }
+            for ($j = $i; $j < $count; $j++) {
+                if ($tokens[$j]['text'] !== '{') {
+                    continue;
+                }
+                $close = self::matchingBrace($tokens, $j);
+                if ($close === null) {
+                    break;
+                }
+                for ($k = $j; $k <= $close; $k++) {
+                    if ($tokens[$k]['id'] === T_CONSTANT_ENCAPSED_STRING
+                        && self::literalValue($tokens[$k]['text']) === $literal) {
+                        return true;
+                    }
+                }
+
+                break;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * ソース中に**範囲外**の raw 主キー述語があるか。
+     *
+     * `whereRaw` / `whereIntegerInRaw` の第 1 引数が文字列リテラルなら `id` 列への言及を照合し、
+     * **非リテラル (変数・連結) なら中身が読めないので無条件に true** を返す
+     * (範囲外の経路が実際に生えた合図として fail させるため)。
+     */
+    public static function containsRawPrimaryKeyPredicate(string $source): bool
+    {
+        $tokens = self::significantTokens($source);
+        $count = count($tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_STRING) {
+                continue;
+            }
+            if (! in_array($tokens[$i]['text'], self::RAW_PREDICATES, true)) {
+                continue;
+            }
+            if (($tokens[$i + 1]['text'] ?? '') !== '(') {
+                continue;
+            }
+            $prev = $tokens[$i - 1]['text'] ?? '';
+            if ($prev !== '->' && $prev !== '?->' && $prev !== '::') {
+                continue;
+            }
+
+            $args = self::argumentRanges($tokens, $i + 1);
+            if ($args === []) {
+                return true; // 引数無し = 想定外の形。読めないので fail させる
+            }
+            [$start, $end] = $args[0];
+            if ($start !== $end || $tokens[$start]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
+                return true; // 非リテラル引数は中身が読めない
+            }
+            // quoted identifier (`` `id` `` / `"id"` / `[id]`) も同じ列指定なので、
+            // 引用符を空白に潰してから照合する
+            $sql = str_replace(['`', '"', '[', ']'], ' ', self::literalValue($tokens[$start]['text']));
+            if (preg_match('/(^|[.\s(])id\b/', $sql) === 1) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * 指定メソッドの**本文だけ**を切り出す (債務 case の `verifiedBy` 検証に使う)。
+     *
+     * 同名メソッドが複数ある場合は最初の 1 つを返す。
+     */
+    public static function methodBody(string $source, string $methodName): ?string
+    {
+        $tokens = self::significantTokens($source);
+        foreach (self::scopesOf($tokens) as $scope) {
+            if ($scope['name'] === $methodName) {
+                return self::join($tokens, $scope['start'], $scope['end']);
+            }
+        }
+
+        return null;
+    }
+
+    /** 候補が「同一 chain に所有者/テナント制約 (右辺 provenance 込み)」を持つか。 */
+    public static function hasOwnerScopedConstraint(PrimaryKeyStaticQueryCandidate $candidate): bool
+    {
+        $tokens = self::significantTokens($candidate->chainSource);
+        $count = count($tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_STRING || ($tokens[$i + 1]['text'] ?? '') !== '(') {
+                continue;
+            }
+            $prev = $tokens[$i - 1]['text'] ?? '';
+            if ($prev !== '->' && $prev !== '?->' && $prev !== '::') {
+                continue;
+            }
+            $args = self::argumentRanges($tokens, $i + 1);
+
+            $proven = array_values(array_unique([
+                ...$candidate->provenModelVariables,
+                ...self::authenticatedActorVariables($candidate->methodSource),
+            ]));
+
+            // whereBelongsTo($model) — 引数が解決済みモデルであること
+            if ($tokens[$i]['text'] === 'whereBelongsTo' && count($args) >= 1) {
+                if (self::isProvenModelExpression($tokens, $args[0], $proven, false)) {
+                    return true;
+                }
+
+                continue;
+            }
+
+            // where('organization_id'|'user_id'|'team_id'|'project_id', $model->getKey())
+            if ($tokens[$i]['text'] !== 'where' || count($args) < 2) {
+                continue;
+            }
+            $column = self::columnOf($tokens, $args[0]);
+            if ($column === null || ! in_array($column, self::OWNER_COLUMNS, true)) {
+                continue;
+            }
+            $valueRange = count($args) === 2 ? $args[1] : $args[2];
+            if (count($args) >= 3 && self::literalOf($tokens, $args[1]) !== '=') {
+                continue;
+            }
+            if (self::isProvenModelExpression($tokens, $valueRange, $proven, true)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * 認証済み actor が代入された変数 (`$user = Auth::guard('web')->user();` 等)。
+     *
+     * **所有者制約の右辺としてのみ**受理する。候補側の provenance 除外には使わない
+     * (使うと actor 由来の直 fetch が inventory から静かに消え、分類対象でなくなるため)。
+     *
+     * @return list<string>
+     */
+    private static function authenticatedActorVariables(string $methodSource): array
+    {
+        $tokens = self::significantTokens($methodSource);
+        $count = count($tokens);
+        $variables = [];
+
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_VARIABLE || ($tokens[$i + 1]['text'] ?? '') !== '=') {
+                continue;
+            }
+            $expression = '';
+            for ($j = $i + 2; $j < $count && $tokens[$j]['text'] !== ';'; $j++) {
+                $expression .= $tokens[$j]['text'];
+            }
+            if (preg_match('/^(Auth::user\(|Auth::guard\(.*\)->user\(|auth\(.*\)->user\(|\$request->user\(|\$this->request->user\()/', $expression) === 1) {
+                $variables[] = $tokens[$i]['text'];
+            }
+        }
+
+        return $variables;
+    }
+
+    /**
+     * 候補のスコープ本文に request accessor が 1 つも無いか (AuthenticatedActorScope の negative check)。
+     *
+     * **accessor = 入力を読む呼び出し**であり、`$request` を素通しで別メソッドへ渡すだけの
+     * 使用は accessor に数えない (`$this->apiActor($request)` で落とすと、token 由来 actor を
+     * 解決するだけの Controller が本 case を使えなくなる)。
+     *
+     * ★`attributes` バッグは例外扱いする: これは middleware が**サーバ側で確定させた値**であり
+     *   client 入力ではない。ただし「その attribute を置いた middleware が何を検証したか」は
+     *   機械証明できないため、本 case を使う側の根拠文でそれを名指しさせる
+     *   (本 case が機械証明できない旨は enum の docblock に明記済み)。
+     */
+    public static function methodIsFreeOfRequestAccessors(PrimaryKeyStaticQueryCandidate $candidate): bool
+    {
+        $tokens = self::significantTokens($candidate->methodSource);
+        $count = count($tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            $token = $tokens[$i];
+
+            // `$request->input(...)` / `$this->request->validated()` など
+            if ($token['id'] === T_VARIABLE && $token['text'] === '$request'
+                && self::readsRequestInput($tokens, $i)) {
+                return false;
+            }
+            if ($token['id'] === T_STRING && $token['text'] === 'request'
+                && ($tokens[$i - 1]['text'] ?? '') === '->'
+                && self::readsRequestInput($tokens, $i)) {
+                return false;
+            }
+
+            // `request('user_id')` / `request()->input(...)` helper
+            if ($token['id'] !== T_STRING || $token['text'] !== 'request') {
+                continue;
+            }
+            if (($tokens[$i + 1]['text'] ?? '') !== '(') {
+                continue;
+            }
+            $prev = $tokens[$i - 1]['text'] ?? '';
+            if ($prev === '::' || $prev === '->' || $prev === '?->' || $prev === 'function') {
+                continue;
+            }
+            $close = self::matchingParenthesis($tokens, $i + 1);
+            if ($close === null) {
+                return false;
+            }
+            if ($close !== $i + 2) {
+                return false; // `request('user_id')` = 入力の直読み
+            }
+            if (self::readsRequestInput($tokens, $close)) {
+                return false;
+            }
+        }
+
+        return true;
+    }
+
+    /**
+     * request を表すトークン位置の直後が「入力を読む」呼び出しか。
+     *
+     * @param  list<array{id: int, text: string}>  $tokens
+     */
+    private static function readsRequestInput(array $tokens, int $at): bool
+    {
+        $arrow = $tokens[$at + 1]['text'] ?? '';
+        if ($arrow !== '->' && $arrow !== '?->') {
+            return false;
+        }
+        $member = $tokens[$at + 2] ?? null;
+        if ($member === null || $member['id'] !== T_STRING) {
+            return false;
+        }
+
+        return in_array($member['text'], self::REQUEST_INPUT_ACCESSORS, true);
+    }
+
+    /**
+     * 候補の identity 変数が「テナントスコープ済みの解決」から確定しているか。
+     *
+     * 受理する形は 2 つだけ:
+     *  (a) relation 起点クエリからの代入 (`$id = $organization->projects()->value('id')`)
+     *  (b) **解決済みモデルの key** からの代入 (`$organizationId = $org->getKey()`。
+     *      `$org` は型付き引数 / `@var` / relation 起点代入で `App\Models\*` と証明済みであること)
+     *
+     * (b) を受理するのは、`Model::find($org->getKey())` なら provenance フィルタで
+     * そもそも候補にならないのに、スカラー変数を 1 つ挟んだだけで候補化してしまうため
+     * (同じ安全性を持つ形を書き方の違いで別扱いしない)。
+     */
+    public static function identityAssignedFromRelationQuery(PrimaryKeyStaticQueryCandidate $candidate): bool
+    {
+        $variable = $candidate->identityArgument;
+        if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $variable) !== 1) {
+            return false;
+        }
+
+        if (self::identityAssignedFromProvenModelKey($candidate, $variable)) {
+            return true;
+        }
+
+        $tokens = self::significantTokens($candidate->methodSource);
+        $count = count($tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== $variable) {
+                continue;
+            }
+            if (($tokens[$i + 1]['text'] ?? '') !== '=') {
+                continue;
+            }
+            // `$id = $organization->projects()->value('id')` の形 (relation 呼び出し + 継続 chain)
+            if (($tokens[$i + 2]['id'] ?? 0) !== T_VARIABLE) {
+                continue;
+            }
+            if (($tokens[$i + 3]['text'] ?? '') !== '->' && ($tokens[$i + 3]['text'] ?? '') !== '?->') {
+                continue;
+            }
+            if (($tokens[$i + 4]['id'] ?? 0) !== T_STRING || ($tokens[$i + 5]['text'] ?? '') !== '(') {
+                continue;
+            }
+            $close = self::matchingParenthesis($tokens, $i + 5);
+            if ($close === null) {
+                continue;
+            }
+            if (($tokens[$close + 1]['text'] ?? '') === '->' || ($tokens[$close + 1]['text'] ?? '') === '?->') {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /** identity 変数が、証明済みモデルの key (`$org->getKey()` / `$org->id`) から代入されているか。 */
+    private static function identityAssignedFromProvenModelKey(PrimaryKeyStaticQueryCandidate $candidate, string $variable): bool
+    {
+        $tokens = self::significantTokens($candidate->methodSource);
+        $count = count($tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== $variable) {
+                continue;
+            }
+            if (($tokens[$i + 1]['text'] ?? '') !== '=') {
+                continue;
+            }
+            $end = $i + 2;
+            while ($end < $count && $tokens[$end]['text'] !== ';') {
+                $end++;
+            }
+            if (self::isProvenModelExpression($tokens, [$i + 2, $end - 1], $candidate->provenModelVariables, true)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * identity が「同一メソッド内で自身が発行した走査クエリ」の結果から確定しているか。
+     *
+     * 受理条件 (両方):
+     *  (a) identity の基底変数 (`$id` / `$reservation` in `$reservation->id`) が、
+     *      同一メソッド内でクエリ結果変数から `foreach` 束縛 / 代入されている。
+     *      クエリ結果変数 = クラス起点 (`RenderJob::query()->…`) / relation 起点 /
+     *      `DB::table(…)` 起点の chain が代入された変数
+     *  (b) 同一メソッドに request accessor が 1 つも無い ({@see self::methodIsFreeOfRequestAccessors()})
+     */
+    public static function identityDerivedFromSameMethodQuery(PrimaryKeyStaticQueryCandidate $candidate): bool
+    {
+        $base = self::baseVariableOf($candidate->identityArgument);
+        if ($base === null) {
+            return false;
+        }
+        $tokens = self::significantTokens($candidate->methodSource);
+        $sources = self::queryResultVariables($tokens);
+        if ($sources === []) {
+            return false;
+        }
+        $count = count($tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            // foreach ($src as [$k =>] $base)
+            if ($tokens[$i]['id'] === T_FOREACH && ($tokens[$i + 1]['text'] ?? '') === '(') {
+                $close = self::matchingParenthesis($tokens, $i + 1);
+                if ($close === null) {
+                    continue;
+                }
+                $source = $tokens[$i + 2]['text'] ?? '';
+                $bound = $tokens[$close - 1]['text'] ?? '';
+                if (in_array($source, $sources, true) && $bound === $base) {
+                    return true;
+                }
+
+                continue;
+            }
+            // $base = <クエリ結果変数を参照する式>
+            if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== $base) {
+                continue;
+            }
+            if (($tokens[$i + 1]['text'] ?? '') !== '=') {
+                continue;
+            }
+            for ($j = $i + 2; $j < $count && $tokens[$j]['text'] !== ';'; $j++) {
+                if ($tokens[$j]['id'] === T_VARIABLE && in_array($tokens[$j]['text'], $sources, true)) {
+                    return true;
+                }
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * identity が当該メソッドの引数そのもの、または引数から導出された変数か。
+     *
+     * 「呼び出し元で確定した値を受け取っているだけ」であることの機械的な必要条件。
+     * 呼び出し元での provenance は証明しない (メソッドをまたぐデータフロー解析は範囲外)。
+     */
+    public static function identityDerivedFromMethodParameters(PrimaryKeyStaticQueryCandidate $candidate): bool
+    {
+        $base = self::baseVariableOf($candidate->identityArgument);
+        if ($base === null) {
+            return false;
+        }
+        $tokens = self::significantTokens($candidate->methodSource);
+        $parameters = self::parameterVariables($tokens);
+        if (in_array($base, $parameters, true)) {
+            return true;
+        }
+
+        $count = count($tokens);
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== $base) {
+                continue;
+            }
+            if (($tokens[$i + 1]['text'] ?? '') !== '=') {
+                continue;
+            }
+            for ($j = $i + 2; $j < $count && $tokens[$j]['text'] !== ';'; $j++) {
+                if ($tokens[$j]['id'] === T_VARIABLE && in_array($tokens[$j]['text'], $parameters, true)) {
+                    return true;
+                }
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * メソッドシグネチャの引数変数名。
+     *
+     * @param  list<array{id: int, text: string}>  $tokens
+     * @return list<string>
+     */
+    private static function parameterVariables(array $tokens): array
+    {
+        $count = count($tokens);
+        $open = null;
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['text'] === '(') {
+                $open = $i;
+
+                break;
+            }
+        }
+        if ($open === null) {
+            return [];
+        }
+        $close = self::matchingParenthesis($tokens, $open);
+        if ($close === null) {
+            return [];
+        }
+        $variables = [];
+        for ($i = $open; $i < $close; $i++) {
+            if ($tokens[$i]['id'] === T_VARIABLE) {
+                $variables[] = $tokens[$i]['text'];
+            }
+        }
+
+        return array_values(array_unique($variables));
+    }
+
+    /** 候補のメソッドが private 宣言か (外部から直接呼べないこと)。 */
+    public static function methodIsPrivate(PrimaryKeyStaticQueryCandidate $candidate): bool
+    {
+        foreach (self::significantTokens($candidate->methodSource) as $token) {
+            if ($token['id'] === T_PRIVATE) {
+                return true;
+            }
+            if ($token['id'] === T_FUNCTION) {
+                return false;
+            }
+        }
+
+        return false;
+    }
+
+    /** `$reservation->id` → `$reservation` / `$id` → `$id` / それ以外は null。 */
+    private static function baseVariableOf(string $identityArgument): ?string
+    {
+        if (preg_match('/^(\$[A-Za-z_][A-Za-z0-9_]*)(->(id|getKey\(\)|[a-z0-9_]+_id))?$/', $identityArgument, $m) !== 1) {
+            return null;
+        }
+
+        return $m[1];
+    }
+
+    /**
+     * 同一メソッド内で**クラス起点クエリ** chain の結果が代入された変数。
+     *
+     * ★relation 起点 (`$x->rel()->…`) を含めない: トークン上は `$input->ids()` のような
+     *   任意 object のメソッド呼び出しと区別できず、含めると
+     *   `IdDerivedFromSameMethodQuery` の副条件が「任意の object の戻り値を foreach しただけ」で
+     *   通ってしまう (= 分類語彙が形骸化する)。relation 起点でテナントに閉じている形は
+     *   `IdDerivedFromTenantScopedQuery` の担当。
+     *
+     * @param  list<array{id: int, text: string}>  $tokens
+     * @return list<string>
+     */
+    private static function queryResultVariables(array $tokens): array
+    {
+        $count = count($tokens);
+        $variables = [];
+
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_VARIABLE || ($tokens[$i + 1]['text'] ?? '') !== '=') {
+                continue;
+            }
+            $head = $tokens[$i + 2] ?? null;
+            if ($head === null) {
+                continue;
+            }
+            // クラス起点 (`RenderJob::query()->…` / `DB::table(…)->…`) のみ
+            $classRooted = in_array($head['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC], true)
+                && ($tokens[$i + 3]['text'] ?? '') === '::';
+            if ($classRooted) {
+                $variables[] = $tokens[$i]['text'];
+            }
+        }
+
+        return array_values(array_unique($variables));
+    }
+
+    // ------------------------------------------------------------------
+    // 内部実装
+    // ------------------------------------------------------------------
+
+    /** @param  list<string>  $modelTables */
+    private static function make(string $source, string $relativePath, array $modelTables): self
+    {
+        $tokens = self::significantTokens($source);
+        $scopes = self::scopesOf($tokens);
+
+        /** @var list<int> $scopeIdOf */
+        $scopeIdOf = array_fill(0, max(count($tokens), 1), -1);
+        foreach ($scopes as $id => $scope) {
+            for ($i = $scope['start']; $i <= $scope['end'] && $i < count($tokens); $i++) {
+                $scopeIdOf[$i] = $id;
+            }
+        }
+
+        return new self(
+            $tokens,
+            $relativePath,
+            $modelTables,
+            self::importsOf($tokens),
+            self::namespaceOf($tokens),
+            self::selfClassOf($tokens),
+            $scopes,
+            $scopeIdOf,
+            self::docVarTypesOf($source),
+            self::methodReturnTypesOf($tokens, $scopes),
+        );
+    }
+
+    /**
+     * メソッド名 => 戻り値型宣言 (`private function x(...): Organization` の `Organization`)。
+     *
+     * union / nullable は「単一のクラス名」だけを採る (`?Organization` は null を許すので採らない)。
+     *
+     * @param  list<array{id: int, text: string}>  $tokens
+     * @param  list<array{name: string, start: int, end: int}>  $scopes
+     * @return array<string, string>
+     */
+    private static function methodReturnTypesOf(array $tokens, array $scopes): array
+    {
+        $types = [];
+        foreach ($scopes as $scope) {
+            if (str_starts_with($scope['name'], '__')) {
+                continue;
+            }
+            $open = null;
+            for ($i = $scope['start']; $i <= $scope['end']; $i++) {
+                if ($tokens[$i]['text'] === '(') {
+                    $open = $i;
+
+                    break;
+                }
+            }
+            if ($open === null) {
+                continue;
+            }
+            $close = self::matchingParenthesis($tokens, $open);
+            if ($close === null || ($tokens[$close + 1]['text'] ?? '') !== ':') {
+                continue;
+            }
+            $type = $tokens[$close + 2] ?? null;
+            if ($type === null
+                || ! in_array($type['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
+                continue;
+            }
+            // `: Organization {` 以外 (union / intersection) は採らない
+            if (($tokens[$close + 3]['text'] ?? '') !== '{') {
+                continue;
+            }
+            $types[$scope['name']] = $type['text'];
+        }
+
+        return $types;
+    }
+
+    /** @return list<PrimaryKeyStaticQueryCandidate> */
+    private function scan(): array
+    {
+        $aliases = $this->builderAliases();
+        $candidates = [];
+        /** @var array<string, int> $ordinals */
+        $ordinals = [];
+        $count = count($this->tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            $root = $this->rootAt($i, $aliases);
+            if ($root === null) {
+                continue;
+            }
+            $chainEnd = $this->chainEnd($root['start']);
+            $chainSource = self::join($this->tokens, $root['start'], $chainEnd);
+            $scopeName = $this->scopeNameAt($i);
+            $proven = $this->provenModelVariables($this->scopeIdOf[$i] ?? -1);
+
+            foreach ($this->predicatesIn($root['start'], $chainEnd, $root['kind']) as $predicate) {
+                // 識別子が解決済みモデル由来なら除外する (元モデルの解決式が別途候補として捕まるため、
+                // provenance は候補へ遡及する)。単数の識別子を取る述語だけに適用し、
+                // MultiIdentity (配列変数) には適用しない
+                $singular = $predicate['kind'] === PrimaryKeyPredicateKind::SingleIdentity
+                    || $predicate['kind'] === PrimaryKeyPredicateKind::IdentityExclusion;
+                if ($singular && $this->isProvenModelIdentity($predicate['identityRange'], $proven)) {
+                    continue;
+                }
+
+                $fingerprint = $this->displayPath().'#'.$scopeName.'#'.$root['kind']
+                    .'.'.$predicate['label'].':'.$predicate['identity'];
+                $ordinals[$fingerprint] = ($ordinals[$fingerprint] ?? 0) + 1;
+
+                $candidates[] = new PrimaryKeyStaticQueryCandidate(
+                    key: $fingerprint.'#'.$ordinals[$fingerprint],
+                    relativePath: $this->relativePath,
+                    scopeName: $scopeName,
+                    predicateKind: $predicate['kind'],
+                    rootKind: $root['kind'],
+                    predicate: $predicate['label'],
+                    identityArgument: $predicate['identity'],
+                    chainSource: $chainSource,
+                    methodSource: $this->scopeSource($this->scopeIdOf[$i] ?? -1),
+                    provenModelVariables: $proven,
+                );
+            }
+        }
+
+        return $candidates;
+    }
+
+    /** @return list<string> */
+    private function scanDynamicColumns(): array
+    {
+        $aliases = $this->builderAliases();
+        $found = [];
+        $count = count($this->tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            $root = $this->rootAt($i, $aliases);
+            if ($root === null) {
+                continue;
+            }
+            $chainEnd = $this->chainEnd($root['start']);
+            $scopeName = $this->scopeNameAt($i);
+
+            for ($p = $root['start']; $p <= $chainEnd; $p++) {
+                if ($this->tokens[$p]['id'] !== T_STRING || ($this->tokens[$p + 1]['text'] ?? '') !== '(') {
+                    continue;
+                }
+                $prev = $this->tokens[$p - 1]['text'] ?? '';
+                if ($prev !== '->' && $prev !== '?->' && $prev !== '::') {
+                    continue;
+                }
+                if (! in_array($this->tokens[$p]['text'], self::COLUMN_PREDICATES, true)) {
+                    continue;
+                }
+                $args = self::argumentRanges($this->tokens, $p + 1);
+                if (count($args) < 2) {
+                    continue; // 単一引数の array 形 / closure 形は動的列名ではない
+                }
+                if (self::columnOf($this->tokens, $args[0]) !== null) {
+                    continue; // 列名が字句的に確定している
+                }
+                $found[] = $this->displayPath().'#'.$scopeName.'#'.$root['kind'].'.'.$this->tokens[$p]['text']
+                    .':'.$this->identityText($args[0]);
+            }
+        }
+
+        return array_values(array_unique($found));
+    }
+
+    /** @return list<string> */
+    private function scanUniqueColumns(): array
+    {
+        $aliases = $this->builderAliases();
+        $found = [];
+        $count = count($this->tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            $root = $this->rootAt($i, $aliases);
+            if ($root === null) {
+                continue;
+            }
+            if (in_array($root['kind'], self::CATALOG_ROOTS, true)) {
+                // Plan はグローバルカタログでテナント資源ではない (root ごと除外する)
+                continue;
+            }
+            $chainEnd = $this->chainEnd($root['start']);
+            $scopeName = $this->scopeNameAt($i);
+
+            for ($p = $root['start']; $p <= $chainEnd; $p++) {
+                if ($this->tokens[$p]['id'] !== T_STRING || ($this->tokens[$p + 1]['text'] ?? '') !== '(') {
+                    continue;
+                }
+                $prev = $this->tokens[$p - 1]['text'] ?? '';
+                if ($prev !== '->' && $prev !== '?->' && $prev !== '::') {
+                    continue;
+                }
+                $name = $this->tokens[$p]['text'];
+                $column = null;
+
+                if ($name === 'where' || $name === 'firstWhere') {
+                    $args = self::argumentRanges($this->tokens, $p + 1);
+                    $column = $args === [] ? null : self::columnOf($this->tokens, $args[0]);
+                } elseif (str_starts_with($name, 'where')) {
+                    $magic = self::snake(substr($name, 5));
+                    $column = $magic === '' ? null : $magic;
+                }
+
+                if ($column === null || ! in_array($column, self::UNIQUE_COLUMNS, true)) {
+                    continue;
+                }
+                $found[] = $this->displayPath().'#'.$scopeName.'#'.$root['kind'].'.'.$name.':'.$column;
+            }
+        }
+
+        return array_values(array_unique($found));
+    }
+
+    /**
+     * `$var = <静的起点式>` の単純代入で伝播する builder alias。
+     *
+     * **再代入では取り消さない** (docblock の fail 方向を参照)。
+     *
+     * @return array<int, array<string, array{kind: string, at: int}>> scope 添字 => 変数名 => alias
+     */
+    private function builderAliases(): array
+    {
+        /** @var array<int, array<string, array{kind: string, at: int}>> $aliases */
+        $aliases = [];
+        $count = count($this->tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            if ($this->tokens[$i]['id'] !== T_VARIABLE || ($this->tokens[$i + 1]['text'] ?? '') !== '=') {
+                continue;
+            }
+            $root = $this->staticRootAt($i + 2);
+            if ($root === null) {
+                continue;
+            }
+            if ($this->chainEndsWithExecutor($root['start'])) {
+                // 代入式が実行系メソッドで終わっている = 変数に入るのは Builder ではなく
+                // **結果 (Model / Collection)**。`$locked = Project::whereKey(...)->firstOrFail();`
+                // の `$locked` を builder alias 扱いすると、続く
+                // `$locked->categories()->whereKey($categoryId)` (relation 起点 = 安全) まで
+                // 候補化してしまい inventory が形骸化する。
+                // 「$q = User::query();」のような**未実行の Builder** だけを alias として伝播する
+                continue;
+            }
+            $scopeId = $this->scopeIdOf[$i] ?? -1;
+            $variable = $this->tokens[$i]['text'];
+            if (isset($aliases[$scopeId][$variable])) {
+                continue; // 最初の代入位置を保持する (以降の使用をすべて候補にするため)
+            }
+            $aliases[$scopeId][$variable] = ['kind' => $root['kind'], 'at' => $i];
+        }
+
+        return $aliases;
+    }
+
+    /**
+     * chain の最後の depth 0 メソッド呼び出しが「実行系」か (= 変数に入るのは Builder でない)。
+     */
+    private function chainEndsWithExecutor(int $start): bool
+    {
+        $end = $this->chainEnd($start);
+        $last = null;
+        $depth = 0;
+
+        for ($i = $start; $i <= $end; $i++) {
+            $text = $this->tokens[$i]['text'];
+            if ($text === '(' || $text === '[' || $text === '{') {
+                $depth++;
+
+                continue;
+            }
+            if ($text === ')' || $text === ']' || $text === '}') {
+                $depth--;
+
+                continue;
+            }
+            if ($depth !== 0 || $this->tokens[$i]['id'] !== T_STRING) {
+                continue;
+            }
+            if (($this->tokens[$i + 1]['text'] ?? '') !== '(') {
+                continue;
+            }
+            $prev = $this->tokens[$i - 1]['text'] ?? '';
+            if ($prev === '->' || $prev === '?->' || $prev === '::') {
+                $last = $this->tokens[$i]['text'];
+            }
+        }
+
+        return $last !== null && in_array($last, self::EXECUTOR_METHODS, true);
+    }
+
+    /**
+     * トークン位置 `$i` が chain root なら root 情報を返す。
+     *
+     * @param  array<int, array<string, array{kind: string, at: int}>>  $aliases
+     * @return array{kind: string, start: int}|null
+     */
+    private function rootAt(int $i, array $aliases): ?array
+    {
+        $static = $this->staticRootAt($i);
+        if ($static !== null) {
+            return $static;
+        }
+
+        // builder alias の使用 (`$q->whereKey($id)`)
+        $token = $this->tokens[$i];
+        if ($token['id'] !== T_VARIABLE) {
+            return null;
+        }
+        $next = $this->tokens[$i + 1]['text'] ?? '';
+        if ($next !== '->' && $next !== '?->') {
+            return null;
+        }
+        $scopeId = $this->scopeIdOf[$i] ?? -1;
+        $alias = $aliases[$scopeId][$token['text']] ?? null;
+        if ($alias === null || $alias['at'] >= $i) {
+            return null;
+        }
+
+        return ['kind' => $alias['kind'], 'start' => $i];
+    }
+
+    /**
+     * 静的起点 (クラス起点 / `new` 起点 / `DB::table()` 起点) の判定。
+     *
+     * @return array{kind: string, start: int}|null
+     */
+    private function staticRootAt(int $i): ?array
+    {
+        $token = $this->tokens[$i] ?? null;
+        if ($token === null) {
+            return null;
+        }
+        $prev = $this->tokens[$i - 1]['text'] ?? '';
+
+        // (1) `new App\Models\User`
+        if ($token['id'] === T_NEW) {
+            $class = $this->classNameAt($i + 1);
+            if ($class === null || ! $this->isModelClass($class)) {
+                return null;
+            }
+
+            // `(new User())->newQuery()` の chain は囲みの `(` から始まる
+            return ['kind' => self::shortName($class), 'start' => $prev === '(' ? $i - 1 : $i];
+        }
+
+        // (2) `ClassName::` / `self::` / `static::` / `DB::`
+        if (($this->tokens[$i + 1]['text'] ?? '') !== '::') {
+            return null;
+        }
+        if ($prev === '->' || $prev === '?->' || $prev === '::' || $prev === 'new' || $prev === 'function') {
+            return null;
+        }
+        $text = $token['text'];
+        if (! in_array($token['id'], [T_STRING, T_STATIC, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
+            return null;
+        }
+
+        if ($text === 'self' || $text === 'static' || $text === 'parent') {
+            if ($this->selfClass === null || ! $this->isModelClass($this->selfClass)) {
+                return null;
+            }
+
+            return ['kind' => self::shortName($this->selfClass), 'start' => $i];
+        }
+
+        $fqcn = $this->resolveClass($text);
+        if ($fqcn === self::DB_FACADE) {
+            $table = $this->tableInChain($i);
+
+            return $table === null ? null : ['kind' => 'DB:'.$table, 'start' => $i];
+        }
+        if (! $this->isModelClass($fqcn)) {
+            return null;
+        }
+
+        return ['kind' => self::shortName($fqcn), 'start' => $i];
+    }
+
+    /** `DB::table('users')` / `DB::connection(...)->table('users as u')` の解決テーブル名。 */
+    private function tableInChain(int $start): ?string
+    {
+        $end = $this->chainEnd($start);
+        for ($i = $start; $i <= $end; $i++) {
+            if ($this->tokens[$i]['id'] !== T_STRING || $this->tokens[$i]['text'] !== 'table') {
+                continue;
+            }
+            if (($this->tokens[$i + 1]['text'] ?? '') !== '(') {
+                continue;
+            }
+            $args = self::argumentRanges($this->tokens, $i + 1);
+            if ($args === []) {
+                return null;
+            }
+            $literal = self::literalOf($this->tokens, $args[0]);
+            if ($literal === null) {
+                return null;
+            }
+            $table = trim(explode(' ', str_ireplace(' as ', ' ', $literal))[0]);
+
+            return in_array($table, $this->modelTables, true) ? $table : null;
+        }
+
+        return null;
+    }
+
+    /**
+     * chain 内の主キー同一性述語を列挙する。
+     *
+     * @return list<array{kind: PrimaryKeyPredicateKind, label: string, identity: string, identityRange: array{int, int}}>
+     */
+    private function predicatesIn(int $start, int $end, string $rootKind): array
+    {
+        $found = [];
+        $depth = 0;
+
+        for ($i = $start; $i <= $end; $i++) {
+            $text = $this->tokens[$i]['text'];
+            if ($text === '(' || $text === '[' || $text === '{') {
+                $depth++;
+
+                continue;
+            }
+            if ($text === ')' || $text === ']' || $text === '}') {
+                $depth--;
+
+                continue;
+            }
+            if ($depth !== 0 || $this->tokens[$i]['id'] !== T_STRING) {
+                continue;
+            }
+            if (($this->tokens[$i + 1]['text'] ?? '') !== '(') {
+                continue;
+            }
+            $prev = $this->tokens[$i - 1]['text'] ?? '';
+            if ($prev !== '->' && $prev !== '?->' && $prev !== '::') {
+                continue;
+            }
+            $predicate = $this->predicateAt($i, $rootKind);
+            if ($predicate !== null) {
+                $found[] = $predicate;
+            }
+        }
+
+        // chain が削除で終わるなら「取得」ではなく「削除」として扱う。
+        // これをやらないと `User::query()->whereKey($this->userId)->delete();` が
+        // SingleIdentity のまま通り、DestructiveIdentity の禁止表を素通りできてしまう
+        if ($this->chainEndsWithDestructiveTerminator($start, $end)) {
+            $found = array_map(
+                static fn (array $predicate): array => [
+                    ...$predicate,
+                    'kind' => PrimaryKeyPredicateKind::DestructiveIdentity,
+                ],
+                $found,
+            );
+        }
+
+        return $found;
+    }
+
+    /** chain の最後の depth 0 呼び出しが削除系か。 */
+    private function chainEndsWithDestructiveTerminator(int $start, int $end): bool
+    {
+        $last = null;
+        $depth = 0;
+
+        for ($i = $start; $i <= $end; $i++) {
+            $text = $this->tokens[$i]['text'];
+            if ($text === '(' || $text === '[' || $text === '{') {
+                $depth++;
+
+                continue;
+            }
+            if ($text === ')' || $text === ']' || $text === '}') {
+                $depth--;
+
+                continue;
+            }
+            if ($depth !== 0 || $this->tokens[$i]['id'] !== T_STRING) {
+                continue;
+            }
+            if (($this->tokens[$i + 1]['text'] ?? '') !== '(') {
+                continue;
+            }
+            $prev = $this->tokens[$i - 1]['text'] ?? '';
+            if ($prev === '->' || $prev === '?->' || $prev === '::') {
+                $last = $this->tokens[$i]['text'];
+            }
+        }
+
+        return $last !== null && in_array($last, self::DESTRUCTIVE_TERMINATORS, true);
+    }
+
+    /**
+     * @return array{kind: PrimaryKeyPredicateKind, label: string, identity: string, identityRange: array{int, int}}|null
+     */
+    private function predicateAt(int $i, string $rootKind): ?array
+    {
+        $name = $this->tokens[$i]['text'];
+        $args = self::argumentRanges($this->tokens, $i + 1);
+        $single = PrimaryKeyPredicateKind::SingleIdentity;
+        $multi = PrimaryKeyPredicateKind::MultiIdentity;
+        $exclusion = PrimaryKeyPredicateKind::IdentityExclusion;
+
+        // find 系 / key 述語 / magic where
+        $simple = match ($name) {
+            'find', 'findOrFail', 'findOrNew' => $single,
+            'whereKey', 'orWhereKey' => $single,
+            'whereId' => $single,
+            'findMany' => $multi,
+            'whereKeyNot', 'orWhereKeyNot' => $exclusion,
+            'destroy' => PrimaryKeyPredicateKind::DestructiveIdentity,
+            default => null,
+        };
+        if ($simple !== null) {
+            if ($args === []) {
+                return null;
+            }
+
+            return $this->predicate($simple, $name, $args[0]);
+        }
+
+        if (in_array($name, self::COLUMN_PREDICATES, true)) {
+            return $this->columnPredicate($name, $args, $rootKind);
+        }
+
+        return null;
+    }
+
+    /**
+     * @param  list<array{int, int}>  $args
+     * @return array{kind: PrimaryKeyPredicateKind, label: string, identity: string, identityRange: array{int, int}}|null
+     */
+    private function columnPredicate(string $name, array $args, string $rootKind): ?array
+    {
+        $single = PrimaryKeyPredicateKind::SingleIdentity;
+        $multi = PrimaryKeyPredicateKind::MultiIdentity;
+        $exclusion = PrimaryKeyPredicateKind::IdentityExclusion;
+
+        // array 形 `where(['id' => $x])` / `where([['id', '=', $x]])`
+        if (($name === 'where' || $name === 'orWhere') && count($args) === 1) {
+            return $this->arrayFormPredicate($args[0]);
+        }
+        if (count($args) < 2) {
+            return null;
+        }
+        $column = self::columnOf($this->tokens, $args[0]);
+        if ($column !== 'id') {
+            return null;
+        }
+        if ($name === 'whereIn' || $name === 'orWhereIn') {
+            return $this->predicate($multi, $name.':id:in', $args[1]);
+        }
+        if ($name === 'whereNotIn' || $name === 'orWhereNotIn') {
+            return $this->predicate($exclusion, $name.':id:not-in', $args[1]);
+        }
+        if (count($args) === 2) {
+            return $this->predicate($single, $name.':id:=', $args[1]);
+        }
+
+        // 3 引数形は等価 / IN / 除外のみ
+        // (順序比較 `where('id', '>', $cursor)` は主キー同一性ではないので候補にしない)
+        $operator = strtolower((string) self::literalOf($this->tokens, $args[1]));
+        if ($operator === '=') {
+            return $this->predicate($single, $name.':id:=', $args[2]);
+        }
+        if ($operator === 'in') {
+            return $this->predicate($multi, $name.':id:in', $args[2]);
+        }
+        if ($operator === '!=' || $operator === '<>' || $operator === 'not in') {
+            return $this->predicate($exclusion, $name.':id:'.$operator, $args[2]);
+        }
+
+        return null;
+    }
+
+    /**
+     * @param  array{int, int}  $range
+     * @return array{kind: PrimaryKeyPredicateKind, label: string, identity: string, identityRange: array{int, int}}|null
+     */
+    private function arrayFormPredicate(array $range): ?array
+    {
+        [$start, $end] = $range;
+        if ($this->tokens[$start]['text'] !== '[') {
+            return null;
+        }
+        for ($i = $start; $i <= $end; $i++) {
+            if ($this->tokens[$i]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
+                continue;
+            }
+            if (self::normalizeColumn(self::literalValue($this->tokens[$i]['text'])) !== 'id') {
+                continue;
+            }
+            // `['id' => $x]`
+            if (($this->tokens[$i + 1]['text'] ?? '') === '=>') {
+                $valueEnd = $this->expressionEnd($i + 2, $end);
+
+                return $this->predicate(PrimaryKeyPredicateKind::SingleIdentity, 'where:id:=', [$i + 2, $valueEnd]);
+            }
+            // `[['id', '=', $x]]`
+            if (($this->tokens[$i + 1]['text'] ?? '') === ',' && ($this->tokens[$i + 3]['text'] ?? '') === ',') {
+                $operator = strtolower(self::literalValue($this->tokens[$i + 2]['text']));
+                if ($operator !== '=') {
+                    continue;
+                }
+                $valueEnd = $this->expressionEnd($i + 4, $end);
+
+                return $this->predicate(PrimaryKeyPredicateKind::SingleIdentity, 'where:id:=', [$i + 4, $valueEnd]);
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * @param  array{int, int}  $range
+     * @return array{kind: PrimaryKeyPredicateKind, label: string, identity: string, identityRange: array{int, int}}
+     */
+    private function predicate(PrimaryKeyPredicateKind $kind, string $label, array $range): array
+    {
+        return [
+            'kind' => $kind,
+            'label' => $label,
+            'identity' => $this->identityText($range),
+            'identityRange' => $range,
+        ];
+    }
+
+    /**
+     * 識別子引数の正規化 (cast を除去してトークンを連結する)。
+     *
+     * @param  array{int, int}  $range
+     */
+    private function identityText(array $range): string
+    {
+        [$start, $end] = $range;
+        $casts = [T_INT_CAST, T_STRING_CAST, T_BOOL_CAST, T_DOUBLE_CAST, T_ARRAY_CAST, T_OBJECT_CAST];
+        while ($start <= $end && in_array($this->tokens[$start]['id'], $casts, true)) {
+            $start++;
+        }
+        $text = '';
+        for ($i = $start; $i <= $end; $i++) {
+            $text .= $this->tokens[$i]['text'];
+        }
+
+        return $text === '' ? '(none)' : $text;
+    }
+
+    /**
+     * 識別子が解決済みモデル由来 (`$model->getKey()` / `$model->id` / `$model->{fk}_id`) か。
+     *
+     * **形だけでは除外しない**。`$dto->user_id` はトークン上まったく同じ形であり、
+     * 形だけで除外すると payload object 由来 id の global fetch が静かに消える。
+     * 除外は「変数が `App\Models\*` であると証明できる場合」に限る (fail-closed)。
+     *
+     * @param  array{int, int}  $range
+     * @param  list<string>  $proven
+     */
+    private function isProvenModelIdentity(array $range, array $proven): bool
+    {
+        return self::isProvenModelExpression($this->tokens, $range, $proven, true);
+    }
+
+    /**
+     * @param  list<array{id: int, text: string}>  $tokens
+     * @param  array{int, int}  $range
+     * @param  list<string>  $proven
+     * @param  bool  $requireKeyAccess  `->getKey()` / `->id` / `->{fk}_id` のアクセスを要求するか
+     */
+    private static function isProvenModelExpression(array $tokens, array $range, array $proven, bool $requireKeyAccess): bool
+    {
+        [$start, $end] = $range;
+        if (($tokens[$start]['id'] ?? 0) !== T_VARIABLE) {
+            return false;
+        }
+        if (! in_array($tokens[$start]['text'], $proven, true)) {
+            return false;
+        }
+        if (! $requireKeyAccess) {
+            return $start === $end;
+        }
+        $arrow = $tokens[$start + 1]['text'] ?? '';
+        if (($arrow !== '->' && $arrow !== '?->') || ($tokens[$start + 2]['id'] ?? 0) !== T_STRING) {
+            return false;
+        }
+        $property = $tokens[$start + 2]['text'];
+        $isCall = ($tokens[$start + 3]['text'] ?? '') === '(';
+
+        if ($isCall) {
+            return in_array($property, ['getKey', 'getRouteKey'], true) && $start + 4 === $end;
+        }
+
+        return ($property === 'id' || str_ends_with($property, '_id')) && $start + 2 === $end;
+    }
+
+    /**
+     * 当該スコープで `App\Models\*` と証明できた変数名。
+     *
+     * 証明手段は 3 つだけ: (1) 型付き引数、(2) PHPDoc `@var`、(3) 同一スコープ内で
+     * relation 起点クエリから代入。証明できなければ候補に残す (fail-closed)。
+     *
+     * @return list<string>
+     */
+    private function provenModelVariables(int $scopeId): array
+    {
+        $proven = [];
+
+        // (0) モデル自身のファイルでは `$this` がモデルである
+        if ($this->selfClass !== null && $this->isModelClass($this->selfClass)) {
+            $proven[] = '$this';
+        }
+
+        // (2) PHPDoc `@var Project $locked`
+        foreach ($this->docVarTypes as $variable => $type) {
+            if ($this->isModelClass($this->resolveClass($type))) {
+                $proven[] = $variable;
+            }
+        }
+
+        if ($scopeId < 0) {
+            return array_values(array_unique($proven));
+        }
+        $scope = $this->scopes[$scopeId];
+
+        // (1) 型付き引数 (promoted constructor property を含む)
+        $signatureEnd = $this->signatureEnd($scope['start']);
+        for ($i = $scope['start']; $i <= $signatureEnd; $i++) {
+            if ($this->tokens[$i]['id'] !== T_VARIABLE) {
+                continue;
+            }
+            $type = $this->tokens[$i - 1] ?? null;
+            if ($type === null
+                || ! in_array($type['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
+                continue;
+            }
+            if ($this->isModelClass($this->resolveClass($type['text']))) {
+                $proven[] = $this->tokens[$i]['text'];
+            }
+        }
+
+        // (4) 同一クラスのメソッド呼び出しからの代入で、そのメソッドの**戻り値型宣言**が
+        //     `App\Models\*` である (`$organization = $this->resolveOrganization($project);`)。
+        //     宣言された型は PHP が実行時に強制するので、これは形ではなく型の証明である
+        for ($i = $scope['start']; $i <= $scope['end']; $i++) {
+            if ($this->tokens[$i]['id'] !== T_VARIABLE || ($this->tokens[$i + 1]['text'] ?? '') !== '=') {
+                continue;
+            }
+            if (($this->tokens[$i + 2]['text'] ?? '') !== '$this') {
+                continue;
+            }
+            $arrow = $this->tokens[$i + 3]['text'] ?? '';
+            if (($arrow !== '->' && $arrow !== '?->') || ($this->tokens[$i + 4]['id'] ?? 0) !== T_STRING) {
+                continue;
+            }
+            if (($this->tokens[$i + 5]['text'] ?? '') !== '(') {
+                continue;
+            }
+            $returnType = $this->methodReturnTypes[$this->tokens[$i + 4]['text']] ?? null;
+            if ($returnType !== null && $this->isModelClass($this->resolveClass($returnType))) {
+                $proven[] = $this->tokens[$i]['text'];
+            }
+        }
+
+        // (3) relation 起点クエリ / モデル起点クエリの**実行結果**からの代入
+        //     (`$x = $organization->projects()->firstOrFail()` / `$job = RenderJob::query()->find(...)`)
+        //     後者を含めるのは循環しない: 代入式そのものが候補として別途分類を要求されるため、
+        //     provenance の遡及が閉じる (`$job->id` を除外しても `RenderJob::find(...)` は残る)
+        for ($i = $scope['start']; $i <= $scope['end']; $i++) {
+            if ($this->tokens[$i]['id'] !== T_VARIABLE || ($this->tokens[$i + 1]['text'] ?? '') !== '=') {
+                continue;
+            }
+            $modelRoot = $this->staticRootAt($i + 2);
+            if ($modelRoot !== null
+                && ! str_starts_with($modelRoot['kind'], 'DB:')
+                && $this->chainEndsWithExecutor($modelRoot['start'])) {
+                $proven[] = $this->tokens[$i]['text'];
+
+                continue;
+            }
+            if (($this->tokens[$i + 2]['id'] ?? 0) !== T_VARIABLE) {
+                continue;
+            }
+            $arrow = $this->tokens[$i + 3]['text'] ?? '';
+            if ($arrow !== '->' && $arrow !== '?->') {
+                continue;
+            }
+            if (($this->tokens[$i + 4]['id'] ?? 0) !== T_STRING || ($this->tokens[$i + 5]['text'] ?? '') !== '(') {
+                continue;
+            }
+            $close = self::matchingParenthesis($this->tokens, $i + 5);
+            if ($close === null) {
+                continue;
+            }
+            $after = $this->tokens[$close + 1]['text'] ?? '';
+            if ($after === '->' || $after === '?->') {
+                $proven[] = $this->tokens[$i]['text'];
+            }
+        }
+
+        return array_values(array_unique($proven));
+    }
+
+    /** `function` トークン位置から引数リストの `)` 位置を返す。 */
+    private function signatureEnd(int $functionToken): int
+    {
+        $count = count($this->tokens);
+        for ($i = $functionToken; $i < $count; $i++) {
+            if ($this->tokens[$i]['text'] === '(') {
+                return self::matchingParenthesis($this->tokens, $i) ?? $i;
+            }
+        }
+
+        return $functionToken;
+    }
+
+    /** chain の終端トークン位置 (`;` / 同深さの `,` / 囲みの外側まで)。 */
+    private function chainEnd(int $start): int
+    {
+        $count = count($this->tokens);
+        $depth = 0;
+        for ($i = $start; $i < $count; $i++) {
+            $text = $this->tokens[$i]['text'];
+            if ($text === '(' || $text === '[' || $text === '{') {
+                $depth++;
+
+                continue;
+            }
+            if ($text === ')' || $text === ']' || $text === '}') {
+                $depth--;
+                if ($depth < 0) {
+                    return $i - 1;
+                }
+
+                continue;
+            }
+            if ($depth === 0 && ($text === ';' || $text === ',')) {
+                return $i - 1;
+            }
+        }
+
+        return $count - 1;
+    }
+
+    /** 配列要素などの式の終端 (同深さの `,` / 範囲末尾)。 */
+    private function expressionEnd(int $start, int $limit): int
+    {
+        $depth = 0;
+        for ($i = $start; $i <= $limit; $i++) {
+            $text = $this->tokens[$i]['text'];
+            if ($text === '(' || $text === '[' || $text === '{') {
+                $depth++;
+
+                continue;
+            }
+            if ($text === ')' || $text === ']' || $text === '}') {
+                if ($depth === 0) {
+                    return $i - 1;
+                }
+                $depth--;
+
+                continue;
+            }
+            if ($depth === 0 && $text === ',') {
+                return $i - 1;
+            }
+        }
+
+        return $limit;
+    }
+
+    private function scopeNameAt(int $i): string
+    {
+        $id = $this->scopeIdOf[$i] ?? -1;
+
+        return $id < 0 ? '__file' : $this->scopes[$id]['name'];
+    }
+
+    private function scopeSource(int $scopeId): string
+    {
+        if ($scopeId < 0) {
+            return self::join($this->tokens, 0, count($this->tokens) - 1);
+        }
+
+        return self::join($this->tokens, $this->scopes[$scopeId]['start'], $this->scopes[$scopeId]['end']);
+    }
+
+    private function displayPath(): string
+    {
+        return str_starts_with($this->relativePath, 'app/')
+            ? substr($this->relativePath, 4)
+            : $this->relativePath;
+    }
+
+    /** `new` の直後などに現れるクラス名を FQCN へ解決する。 */
+    private function classNameAt(int $i): ?string
+    {
+        $token = $this->tokens[$i] ?? null;
+        if ($token === null
+            || ! in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
+            return null;
+        }
+
+        return $this->resolveClass($token['text']);
+    }
+
+    /** 短縮名 / 修飾名を FQCN へ解決する (import → 同一 namespace の順)。 */
+    private function resolveClass(string $name): string
+    {
+        if (str_starts_with($name, '\\')) {
+            return ltrim($name, '\\');
+        }
+        $parts = explode('\\', $name);
+        $first = $parts[0];
+        if (isset($this->imports[$first])) {
+            $rest = array_slice($parts, 1);
+
+            return $rest === [] ? $this->imports[$first] : $this->imports[$first].'\\'.implode('\\', $rest);
+        }
+
+        return $this->namespace === '' ? $name : $this->namespace.'\\'.$name;
+    }
+
+    private function isModelClass(?string $fqcn): bool
+    {
+        return $fqcn !== null && str_starts_with($fqcn, 'App\\Models\\');
+    }
+
+    private static function shortName(string $fqcn): string
+    {
+        $position = strrpos($fqcn, '\\');
+
+        return $position === false ? $fqcn : substr($fqcn, $position + 1);
+    }
+
+    /**
+     * 引数リストの各引数のトークン範囲 (start, end とも inclusive)。
+     *
+     * @param  list<array{id: int, text: string}>  $tokens
+     * @param  int  $open  `(` のトークン位置
+     * @return list<array{int, int}>
+     */
+    private static function argumentRanges(array $tokens, int $open): array
+    {
+        $close = self::matchingParenthesis($tokens, $open);
+        if ($close === null || $close === $open + 1) {
+            return [];
+        }
+        $ranges = [];
+        $depth = 0;
+        $start = $open + 1;
+        for ($i = $open + 1; $i < $close; $i++) {
+            $text = $tokens[$i]['text'];
+            if ($text === '(' || $text === '[' || $text === '{') {
+                $depth++;
+
+                continue;
+            }
+            if ($text === ')' || $text === ']' || $text === '}') {
+                $depth--;
+
+                continue;
+            }
+            if ($depth === 0 && $text === ',') {
+                $ranges[] = [$start, $i - 1];
+                $start = $i + 1;
+            }
+        }
+        if ($start <= $close - 1) {
+            $ranges[] = [$start, $close - 1];
+        }
+
+        return $ranges;
+    }
+
+    /**
+     * 引数が単一の文字列リテラルならその値、そうでなければ null。
+     *
+     * @param  list<array{id: int, text: string}>  $tokens
+     * @param  array{int, int}  $range
+     */
+    private static function literalOf(array $tokens, array $range): ?string
+    {
+        [$start, $end] = $range;
+        if ($start !== $end || ($tokens[$start]['id'] ?? 0) !== T_CONSTANT_ENCAPSED_STRING) {
+            return null;
+        }
+
+        return self::literalValue($tokens[$start]['text']);
+    }
+
+    /**
+     * 列名を表す引数を正規化して返す (`'users.id'` → `id`)。
+     *
+     * `$model->getKeyName()` / `$model->getQualifiedKeyName()` も主キー列とみなす。
+     *
+     * @param  list<array{id: int, text: string}>  $tokens
+     * @param  array{int, int}  $range
+     */
+    private static function columnOf(array $tokens, array $range): ?string
+    {
+        $literal = self::literalOf($tokens, $range);
+        if ($literal !== null) {
+            return self::normalizeColumn($literal);
+        }
+        [$start, $end] = $range;
+        for ($i = $start; $i <= $end; $i++) {
+            if (($tokens[$i]['id'] ?? 0) === T_STRING
+                && in_array($tokens[$i]['text'], ['getKeyName', 'getQualifiedKeyName'], true)) {
+                return 'id';
+            }
+        }
+
+        return null;
+    }
+
+    /** `'users.id'` → `id` / `'u.id'` → `id`。 */
+    private static function normalizeColumn(string $column): string
+    {
+        $position = strrpos($column, '.');
+
+        return $position === false ? $column : substr($column, $position + 1);
+    }
+
+    /** 文字列リテラルのトークンテキストから引用符を外す。 */
+    private static function literalValue(string $text): string
+    {
+        if (strlen($text) < 2) {
+            return $text;
+        }
+        $quote = $text[0];
+        if ($quote !== "'" && $quote !== '"') {
+            return $text;
+        }
+
+        return stripcslashes(substr($text, 1, -1));
+    }
+
+    /** `whereUuid` → `uuid` のような magic where の列名変換。 */
+    private static function snake(string $studly): string
+    {
+        $snake = preg_replace('/(?<!^)[A-Z]/u', '_$0', $studly);
+
+        return strtolower($snake ?? $studly);
+    }
+
+    /**
+     * `(` の位置から対応する `)` の位置。
+     *
+     * @param  list<array{id: int, text: string}>  $tokens
+     */
+    private static function matchingParenthesis(array $tokens, int $open): ?int
+    {
+        $count = count($tokens);
+        $depth = 0;
+        for ($i = $open; $i < $count; $i++) {
+            if ($tokens[$i]['text'] === '(') {
+                $depth++;
+            } elseif ($tokens[$i]['text'] === ')') {
+                $depth--;
+                if ($depth === 0) {
+                    return $i;
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * スコープ (メソッド / routes の疑似スコープ) の一覧。
+     *
+     * `app/**` はメソッド境界。`routes/*.php` はクラス/メソッドが無いため疑似スコープ
+     * (`__file` / `__closure{n}` / `__fn{n}`) を使う。疑似スコープが無いと
+     * 「route closure に直 fetch を書く」経路を key 化できず gate が実現しない。
+     *
+     * @param  list<array{id: int, text: string}>  $tokens
+     * @return list<array{name: string, start: int, end: int}>
+     */
+    private static function scopesOf(array $tokens): array
+    {
+        $count = count($tokens);
+        $scopes = [];
+        /** @var list<array{named: bool, end: int}> $stack */
+        $stack = [];
+        $anonymous = 0;
+
+        for ($i = 0; $i < $count; $i++) {
+            while ($stack !== [] && $stack[count($stack) - 1]['end'] < $i) {
+                array_pop($stack);
+            }
+            if ($tokens[$i]['id'] !== T_FUNCTION && $tokens[$i]['id'] !== T_FN) {
+                continue;
+            }
+            if (($tokens[$i - 1]['id'] ?? 0) === T_USE) {
+                continue; // `use function ...`
+            }
+            $j = $i + 1;
+            if (($tokens[$j]['text'] ?? '') === '&') {
+                $j++;
+            }
+            // メソッド名は予約語でもよい (`function for(...)` は T_FOR になる) ため、
+            // 「`(` でない = 名前がある」で判定する。T_STRING 限定にすると
+            // 予約語名のメソッドが匿名クロージャ扱いになりスコープ名がずれる
+            $named = ($tokens[$j]['text'] ?? '(') !== '(';
+            $name = $named ? $tokens[$j]['text'] : null;
+
+            $open = null;
+            for ($k = $j; $k < $count; $k++) {
+                if ($tokens[$k]['text'] === '(') {
+                    $open = $k;
+
+                    break;
+                }
+            }
+            if ($open === null) {
+                continue;
+            }
+            $close = self::matchingParenthesis($tokens, $open);
+            if ($close === null) {
+                continue;
+            }
+            $end = self::bodyEnd($tokens, $close + 1);
+            if ($end === null) {
+                continue; // abstract / interface の宣言のみ
+            }
+
+            $hasNamed = false;
+            foreach ($stack as $entry) {
+                $hasNamed = $hasNamed || $entry['named'];
+            }
+
+            if ($named && $name !== null) {
+                // 可視性修飾子まで遡って start にする (private 判定に要る)
+                $scopes[] = ['name' => $name, 'start' => self::modifiersStart($tokens, $i), 'end' => $end];
+                $stack[] = ['named' => true, 'end' => $end];
+
+                continue;
+            }
+            if ($hasNamed) {
+                continue; // メソッド内のクロージャはメソッドスコープに属させる
+            }
+            $anonymous++;
+            $scopes[] = [
+                'name' => ($tokens[$i]['id'] === T_FN ? '__fn' : '__closure').$anonymous,
+                'start' => self::modifiersStart($tokens, $i),
+                'end' => $end,
+            ];
+            $stack[] = ['named' => false, 'end' => $end];
+        }
+
+        return $scopes;
+    }
+
+    /**
+     * `function` トークンから可視性修飾子まで遡った開始位置。
+     *
+     * @param  list<array{id: int, text: string}>  $tokens
+     */
+    private static function modifiersStart(array $tokens, int $functionToken): int
+    {
+        $modifiers = [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY];
+        $start = $functionToken;
+        while ($start > 0 && in_array($tokens[$start - 1]['id'], $modifiers, true)) {
+            $start--;
+        }
+
+        return $start;
+    }
+
+    /**
+     * 関数シグネチャの `)` の次から本体の終端位置を求める。
+     *
+     * @param  list<array{id: int, text: string}>  $tokens
+     */
+    private static function bodyEnd(array $tokens, int $from): ?int
+    {
+        $count = count($tokens);
+        $depth = 0;
+        for ($i = $from; $i < $count; $i++) {
+            $text = $tokens[$i]['text'];
+            if ($text === '(') {
+                $depth++;
+
+                continue;
+            }
+            if ($text === ')') {
+                $depth--;
+
+                continue;
+            }
+            if ($depth !== 0) {
+                continue;
+            }
+            if ($text === ';') {
+                return null;
+            }
+            if ($text === '{') {
+                $close = self::matchingBrace($tokens, $i);
+
+                return $close ?? $count - 1;
+            }
+            if ($text === '=>') {
+                return self::arrowFunctionEnd($tokens, $i + 1);
+            }
+        }
+
+        return null;
+    }
+
+    /** @param  list<array{id: int, text: string}>  $tokens */
+    private static function matchingBrace(array $tokens, int $open): ?int
+    {
+        $count = count($tokens);
+        $depth = 0;
+        for ($i = $open; $i < $count; $i++) {
+            if ($tokens[$i]['text'] === '{') {
+                $depth++;
+            } elseif ($tokens[$i]['text'] === '}') {
+                $depth--;
+                if ($depth === 0) {
+                    return $i;
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /** @param  list<array{id: int, text: string}>  $tokens */
+    private static function arrowFunctionEnd(array $tokens, int $from): int
+    {
+        $count = count($tokens);
+        $depth = 0;
+        for ($i = $from; $i < $count; $i++) {
+            $text = $tokens[$i]['text'];
+            if ($text === '(' || $text === '[' || $text === '{') {
+                $depth++;
+
+                continue;
+            }
+            if ($text === ')' || $text === ']' || $text === '}') {
+                $depth--;
+                if ($depth < 0) {
+                    return $i - 1;
+                }
+
+                continue;
+            }
+            if ($depth === 0 && ($text === ';' || $text === ',')) {
+                return $i - 1;
+            }
+        }
+
+        return $count - 1;
+    }
+
+    /**
+     * 名前空間 import (短縮名 => FQCN)。
+     *
+     * group use (`use App\Models\{User, Project};`) は短縮名の対応が曖昧になるため受理しない
+     * (受理しない = 候補にしない方向ではなく、そのファイルでの解決に失敗して候補が減るため、
+     * 本リポジトリで group use が使われていないことを前提とする)。
+     *
+     * @param  list<array{id: int, text: string}>  $tokens
+     * @return array<string, string>
+     */
+    private static function importsOf(array $tokens): array
+    {
+        $imports = [];
+        $count = count($tokens);
+        $depth = 0;
+
+        for ($i = 0; $i < $count; $i++) {
+            $text = $tokens[$i]['text'];
+            if ($text === '{') {
+                $depth++;
+
+                continue;
+            }
+            if ($text === '}') {
+                $depth--;
+
+                continue;
+            }
+            if ($tokens[$i]['id'] !== T_USE || $depth !== 0) {
+                continue;
+            }
+            if (($tokens[$i + 1]['text'] ?? '') === '(') {
+                continue; // クロージャの lexical use
+            }
+            if (($tokens[$i + 1]['id'] ?? 0) === T_FUNCTION || ($tokens[$i + 1]['id'] ?? 0) === T_CONST) {
+                continue; // `use function ...` / `use const ...`
+            }
+
+            // group use (`use App\Models\{User, Project};`) と複数 use (`use A, B;`) を
+            // ここで展開する。無視すると `App\Models\*` の解決に失敗して**候補が消える**
+            // (= fail-open) ため、書き方の違いで gate を黙らせられないようにする
+            $prefix = '';
+            $name = '';
+            $alias = null;
+            $expectAlias = false;
+            for ($j = $i + 1; $j < $count; $j++) {
+                $token = $tokens[$j];
+                $text = $token['text'];
+
+                if ($token['id'] === T_AS) {
+                    $expectAlias = true;
+
+                    continue;
+                }
+                if ($expectAlias && $text !== ',' && $text !== ';' && $text !== '}') {
+                    $alias = $text;
+
+                    continue;
+                }
+                if ($text === '{') {
+                    $prefix = rtrim($name, '\\').'\\';
+                    $name = '';
+
+                    continue;
+                }
+                if ($text === ',' || $text === ';' || $text === '}') {
+                    if ($name !== '') {
+                        $fqcn = ltrim($prefix.$name, '\\');
+                        $position = strrpos($fqcn, '\\');
+                        $short = $alias ?? ($position === false ? $fqcn : substr($fqcn, $position + 1));
+                        $imports[$short] = $fqcn;
+                    }
+                    $name = '';
+                    $alias = null;
+                    $expectAlias = false;
+                    if ($text === ';') {
+                        break;
+                    }
+                    if ($text === '}') {
+                        $prefix = '';
+                    }
+
+                    continue;
+                }
+                $name .= $text;
+            }
+        }
+
+        return $imports;
+    }
+
+    /** @param  list<array{id: int, text: string}>  $tokens */
+    private static function namespaceOf(array $tokens): string
+    {
+        $count = count($tokens);
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_NAMESPACE) {
+                continue;
+            }
+            $name = '';
+            for ($j = $i + 1; $j < $count; $j++) {
+                if ($tokens[$j]['text'] === ';' || $tokens[$j]['text'] === '{') {
+                    break;
+                }
+                $name .= $tokens[$j]['text'];
+            }
+
+            return trim($name, '\\');
+        }
+
+        return '';
+    }
+
+    /**
+     * ファイルが宣言する最初のクラスの FQCN (`self::` / `static::` の解決に使う)。
+     *
+     * @param  list<array{id: int, text: string}>  $tokens
+     */
+    private static function selfClassOf(array $tokens): ?string
+    {
+        $namespace = self::namespaceOf($tokens);
+        $count = count($tokens);
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_CLASS || ($tokens[$i + 1]['id'] ?? 0) !== T_STRING) {
+                continue;
+            }
+            $prev = $tokens[$i - 1]['text'] ?? '';
+            if ($prev === '::' || $prev === 'new') {
+                continue; // `Foo::class` / 匿名クラス
+            }
+
+            return $namespace === '' ? $tokens[$i + 1]['text'] : $namespace.'\\'.$tokens[$i + 1]['text'];
+        }
+
+        return null;
+    }
+
+    /**
+     * PHPDoc `@var Type $variable` の宣言 (ファイル全体で 1 つのマップに畳む)。
+     *
+     * @return array<string, string>
+     */
+    private static function docVarTypesOf(string $source): array
+    {
+        $types = [];
+        if (preg_match_all('/@var\s+([\\\\A-Za-z0-9_|]+)\s+(\$[A-Za-z_][A-Za-z0-9_]*)/u', $source, $matches) === false) {
+            return [];
+        }
+        foreach ($matches[2] as $index => $variable) {
+            foreach (explode('|', $matches[1][$index]) as $type) {
+                if ($type !== '' && $type !== 'null') {
+                    $types[$variable] = $type;
+                }
+            }
+        }
+
+        return $types;
+    }
+
+    /**
+     * 意味のあるトークンだけを正規化する。
+     *
+     * コメント / docblock / 空白 / inline HTML / 補間文字列の中身を除去する。
+     * **文字列リテラル本体は残す** (列名 `'id'` の照合に要るため)。ただし
+     * 内容をコードとして解釈することはないので、コメント中の `Foo::destroy()` のような
+     * 誤検出は起きない。
+     *
+     * @return list<array{id: int, text: string}>
+     */
+    private static function significantTokens(string $source): array
+    {
+        $ignored = [
+            T_COMMENT,
+            T_DOC_COMMENT,
+            T_WHITESPACE,
+            T_INLINE_HTML,
+            T_OPEN_TAG,
+            T_OPEN_TAG_WITH_ECHO,
+            T_CLOSE_TAG,
+            T_ENCAPSED_AND_WHITESPACE,
+        ];
+
+        $result = [];
+        foreach (token_get_all(self::withOpenTag($source)) as $token) {
+            if (is_array($token)) {
+                if (in_array($token[0], $ignored, true)) {
+                    continue;
+                }
+                $result[] = ['id' => $token[0], 'text' => $token[1]];
+
+                continue;
+            }
+            $result[] = ['id' => -1, 'text' => $token];
+        }
+
+        return $result;
+    }
+
+    /**
+     * @param  list<array{id: int, text: string}>  $tokens
+     */
+    private static function join(array $tokens, int $start, int $end): string
+    {
+        $parts = [];
+        for ($i = max($start, 0); $i <= $end && $i < count($tokens); $i++) {
+            $parts[] = $tokens[$i]['text'];
+        }
+
+        return implode(' ', $parts);
+    }
+
+    private static function withOpenTag(string $source): string
+    {
+        return str_starts_with(ltrim($source), '<?php') ? $source : '<?php '.$source;
+    }
+}
diff --git a/tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php b/tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php
new file mode 100644
index 0000000..e7a78fc
--- /dev/null
+++ b/tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php
@@ -0,0 +1,598 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\Security\PrimaryKeyPredicateKind;
+use Tests\Support\Security\PrimaryKeyStaticQueryScanner;
+
+/*
+ * 直 fetch 走査器そのものの positive/negative 固定。
+ *
+ * ModelDirectFetchInvariantTest (直 fetch の deny-by-default gate) の検出ロジックは
+ * **gate 自体がセキュリティ機構**であり、走査器が壊れると inventory の突合が両方 green になって
+ * gate が静かに無力化する。母集団走査に依存しない純粋 helper として切り出し、直接テストする。
+ *
+ * ★本テストの存在理由は「**抜け道 fixture が検出されること**」である。
+ *   inventory が green になることは gate が効いている証明にならない。
+ *   `Model::find()` だけを禁じても `Model::query()->where('id', $payload)->firstOrFail()` や
+ *   builder alias 経由で等価なことができるため、書き方のバリエーションを恒久固定する。
+ *
+ * ★`outOfScope_*` の fixture は「検出しないことを**保証**している」のではなく
+ *   「**既知の範囲外**である」ことを記録している。範囲外の実コード出現は
+ *   ModelDirectFetchInvariantTest の 0 件 assertion が検知する。
+ *
+ * DB 非依存の Unit テスト。
+ */
+
+/** テスト用のモデルテーブル (DB::table 起点の絞り込み対象)。 */
+function scannerModelTables(): array
+{
+    return ['users', 'projects', 'plans'];
+}
+
+/** クラス本体を `app/` 配下のファイルに見立てて走査する。 */
+function scannerCandidates(string $body, string $path = 'app/Services/Sample.php'): array
+{
+    $source = <<<PHP
+    <?php
+
+    namespace App\\Services;
+
+    use App\\Models\\User;
+    use App\\Models\\Plan;
+    use App\\Models\\Project;
+    use Illuminate\\Support\\Facades\\DB;
+
+    class Sample
+    {
+    {$body}
+    }
+    PHP;
+
+    return PrimaryKeyStaticQueryScanner::candidates($source, $path, scannerModelTables());
+}
+
+/** @return list<string> */
+function scannerKeys(array $candidates): array
+{
+    return array_map(static fn (object $c): string => $c->key, $candidates);
+}
+
+// --- positive: 検出されなければならない -------------------------------------
+
+test('述語アンカー: query()->where(id) は検出される', function (): void {
+    $candidates = scannerCandidates(<<<'PHP'
+        public function run(int $payloadId): void
+        {
+            User::query()->where('id', $payloadId)->firstOrFail();
+        }
+    PHP);
+
+    expect(scannerKeys($candidates))->toBe(['Services/Sample.php#run#User.where:id:=:$payloadId#1']);
+});
+
+test('builder alias: $q = User::query() 経由でも検出される', function (): void {
+    $candidates = scannerCandidates(<<<'PHP'
+        public function run(int $payloadId): void
+        {
+            $q = User::query();
+            $q->where('id', $payloadId)->first();
+        }
+    PHP);
+
+    expect(scannerKeys($candidates))->toContain('Services/Sample.php#run#User.where:id:=:$payloadId#1');
+});
+
+test('Service 委譲: scalar 引数を受けた findOrFail も検出される', function (): void {
+    $candidates = scannerCandidates(<<<'PHP'
+        public function run(int $userId): void
+        {
+            User::findOrFail($userId);
+        }
+    PHP);
+
+    expect(scannerKeys($candidates))->toBe(['Services/Sample.php#run#User.findOrFail:$userId#1']);
+});
+
+test('qualified 列 / array 形 / 3 引数の等価形も検出される', function (): void {
+    $candidates = scannerCandidates(<<<'PHP'
+        public function run(int $id): void
+        {
+            User::query()->where('users.id', $id)->first();
+            User::query()->where(['id' => $id])->first();
+            User::query()->where([['id', '=', $id]])->first();
+            User::query()->where('id', '=', $id)->first();
+        }
+    PHP);
+
+    expect(count($candidates))->toBe(4);
+});
+
+test('destroy / findMany / whereKeyNot は predicateKind を分けて検出される', function (): void {
+    $candidates = scannerCandidates(<<<'PHP'
+        public function run(int $id, array $ids, int $requestId): void
+        {
+            User::destroy($id);
+            User::findMany($ids);
+            User::query()->whereIn('id', $ids)->get();
+            User::whereKeyNot($requestId)->get();
+        }
+    PHP);
+
+    $kinds = array_map(static fn (object $c): string => $c->predicateKind->name, $candidates);
+    expect($kinds)->toBe([
+        PrimaryKeyPredicateKind::DestructiveIdentity->name,
+        PrimaryKeyPredicateKind::MultiIdentity->name,
+        PrimaryKeyPredicateKind::MultiIdentity->name,
+        PrimaryKeyPredicateKind::IdentityExclusion->name,
+    ]);
+});
+
+test('DB::table 起点はモデルのテーブルに限って検出される', function (): void {
+    $candidates = scannerCandidates(<<<'PHP'
+        public function run(int $payloadId, string $tokenId): void
+        {
+            DB::table('users')->where('id', $payloadId)->first();
+            DB::table('users as u')->where('u.id', $payloadId)->first();
+            DB::table('oauth_access_tokens')->where('id', $tokenId)->first();
+        }
+    PHP);
+
+    $roots = array_map(static fn (object $c): string => $c->rootKind, $candidates);
+    expect($roots)->toBe(['DB:users', 'DB:users']);
+});
+
+test('FQCN 起点 / new 起点 / magic where も検出される', function (): void {
+    $candidates = scannerCandidates(<<<'PHP'
+        public function run(int $id): void
+        {
+            \App\Models\User::query()->whereKey($id);
+            (new User())->newQuery()->whereKey($id);
+            User::whereId($id)->first();
+        }
+    PHP);
+
+    expect(count($candidates))->toBe(3);
+});
+
+test('型を証明できない $dto->user_id は provenance フィルタで除外されない', function (): void {
+    $candidates = scannerCandidates(<<<'PHP'
+        public function run(object $dto): void
+        {
+            User::query()->whereKey($dto->user_id)->first();
+        }
+    PHP);
+
+    expect(scannerKeys($candidates))->toBe(['Services/Sample.php#run#User.whereKey:$dto->user_id#1']);
+});
+
+test('builder alias は再代入で取り消さない (再代入で gate を黙らせる回避を許さない)', function (): void {
+    $branching = scannerCandidates(<<<'PHP'
+        public function run(int $id, bool $x, $other): void
+        {
+            $q = User::query();
+            if ($x) {
+                $q = $other;
+            }
+            $q->whereKey($id);
+        }
+    PHP);
+    expect(scannerKeys($branching))->toContain('Services/Sample.php#run#User.whereKey:$id#1');
+
+    $straight = scannerCandidates(<<<'PHP'
+        public function run(int $id, $other): void
+        {
+            $q = User::query();
+            $q = $other;
+            $q->whereKey($id);
+        }
+    PHP);
+    expect(scannerKeys($straight))->toContain('Services/Sample.php#run#User.whereKey:$id#1');
+});
+
+test('route closure 内の直 fetch は疑似スコープ付きで検出される', function (): void {
+    $source = <<<'PHP'
+    <?php
+
+    use App\Models\User;
+    use Illuminate\Support\Facades\Route;
+
+    Route::post('/x', function () {
+        User::findOrFail(request('user_id'));
+    });
+    PHP;
+
+    $candidates = PrimaryKeyStaticQueryScanner::candidates($source, 'routes/web.php', scannerModelTables());
+
+    expect(scannerKeys($candidates))
+        ->toBe(["routes/web.php#__closure1#User.findOrFail:request('user_id')#1"]);
+});
+
+test('予約語名のメソッドでもスコープ名がずれない', function (): void {
+    $candidates = scannerCandidates(<<<'PHP'
+        public static function for(int $id): void
+        {
+            User::query()->find($id);
+        }
+    PHP);
+
+    expect(scannerKeys($candidates))->toBe(['Services/Sample.php#for#User.find:$id#1']);
+});
+
+test('or 系 / 否定系の列述語も検出される (片方だけ見ると素通りする)', function (): void {
+    $candidates = scannerCandidates(<<<'PHP'
+        public function run(int $payloadId, array $ids): void
+        {
+            User::query()->orWhere('id', $payloadId)->first();
+            User::query()->orWhereIn('id', $ids)->get();
+            User::query()->whereNotIn('id', $ids)->get();
+            User::query()->where('id', '!=', $payloadId)->get();
+            User::query()->orWhereKey($payloadId)->first();
+        }
+    PHP);
+
+    $kinds = array_map(static fn (object $c): string => $c->predicateKind->name, $candidates);
+    expect($kinds)->toBe([
+        PrimaryKeyPredicateKind::SingleIdentity->name,
+        PrimaryKeyPredicateKind::MultiIdentity->name,
+        PrimaryKeyPredicateKind::IdentityExclusion->name,
+        PrimaryKeyPredicateKind::IdentityExclusion->name,
+        PrimaryKeyPredicateKind::SingleIdentity->name,
+    ]);
+});
+
+test('group use / 複数 use でもモデルが解決される (書き方で候補を消せない)', function (): void {
+    $group = <<<'PHP'
+    <?php
+
+    namespace App\Services;
+
+    use App\Models\{User, Project};
+
+    class Sample
+    {
+        public function run(int $payloadId): void
+        {
+            User::find($payloadId);
+        }
+    }
+    PHP;
+    expect(scannerKeys(PrimaryKeyStaticQueryScanner::candidates($group, 'app/Services/Sample.php', scannerModelTables())))
+        ->toBe(['Services/Sample.php#run#User.find:$payloadId#1']);
+
+    $aliased = <<<'PHP'
+    <?php
+
+    namespace App\Services;
+
+    use App\Models\{User as U, Project};
+
+    class Sample
+    {
+        public function run(int $payloadId): void
+        {
+            U::find($payloadId);
+        }
+    }
+    PHP;
+    expect(scannerKeys(PrimaryKeyStaticQueryScanner::candidates($aliased, 'app/Services/Sample.php', scannerModelTables())))
+        ->toBe(['Services/Sample.php#run#User.find:$payloadId#1']);
+});
+
+test('chain が削除で終わると DestructiveIdentity になる (Single のまま禁止表を素通りさせない)', function (): void {
+    $candidates = scannerCandidates(<<<'PHP'
+        public function run(): void
+        {
+            User::query()->whereKey($this->userId)->delete();
+        }
+    PHP);
+
+    expect($candidates[0]->predicateKind)->toBe(PrimaryKeyPredicateKind::DestructiveIdentity);
+});
+
+test('動的列名は候補にならないが dynamicColumnPredicates が列挙する', function (): void {
+    $source = <<<'PHP'
+    <?php
+
+    namespace App\Services;
+
+    use App\Models\User;
+
+    class Sample
+    {
+        public function run(int $payloadId): void
+        {
+            $column = 'id';
+            User::query()->where($column, $payloadId)->first();
+        }
+    }
+    PHP;
+
+    expect(PrimaryKeyStaticQueryScanner::candidates($source, 'app/Services/Sample.php', scannerModelTables()))->toBe([]);
+    expect(PrimaryKeyStaticQueryScanner::dynamicColumnPredicates($source, 'app/Services/Sample.php', scannerModelTables()))
+        ->toBe(['Services/Sample.php#run#User.where:$column']);
+});
+
+// --- negative: 検出してはならない -------------------------------------------
+
+test('relation 起点は検出されない', function (): void {
+    expect(scannerCandidates(<<<'PHP'
+        public function run($organization, int $id): void
+        {
+            $organization->users()->whereKey($id)->first();
+        }
+    PHP))->toBe([]);
+});
+
+test('型付き引数のモデル由来 id は検出されない (provenance 証明あり)', function (): void {
+    expect(scannerCandidates(<<<'PHP'
+        public function run(Project $project): void
+        {
+            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
+        }
+    PHP))->toBe([]);
+});
+
+test('順序比較 (主キー同一性でない) は検出されない', function (): void {
+    expect(scannerCandidates(<<<'PHP'
+        public function run($manual, int $cursor): void
+        {
+            $manual->renderJobs()->where('id', '>', $cursor)->get();
+            User::query()->where('id', '>', $cursor)->get();
+        }
+    PHP))->toBe([]);
+});
+
+test('主キー以外の列による絞り込みは検出されない', function (): void {
+    expect(scannerCandidates(<<<'PHP'
+        public function run(string $code): void
+        {
+            Plan::query()->where('code', $code)->first();
+        }
+    PHP))->toBe([]);
+});
+
+test('docblock / コメント中の記述は検出されない', function (): void {
+    expect(scannerCandidates(<<<'PHP'
+        /**
+         * User::destroy($id) を呼ぶ (User::query()->where('id', $id) と等価)。
+         */
+        public function run(): void
+        {
+            // User::findOrFail($id) はここでは使わない
+            $this->noop();
+        }
+    PHP))->toBe([]);
+});
+
+test('Models 集合に無い同名クラスは検出されない (import 裏取り)', function (): void {
+    $source = <<<'PHP'
+    <?php
+
+    namespace App\Services;
+
+    use SomeOtherPackage\User;
+
+    class Sample
+    {
+        public function run(int $id): void
+        {
+            User::find($id);
+        }
+    }
+    PHP;
+
+    expect(PrimaryKeyStaticQueryScanner::candidates($source, 'app/Services/Sample.php', scannerModelTables()))->toBe([]);
+});
+
+test('静的起点から代入されていない変数は builder alias にならない', function (): void {
+    expect(scannerCandidates(<<<'PHP'
+        public function run($other, int $id): void
+        {
+            $q = $other->users();
+            $q->whereKey($id)->first();
+        }
+    PHP))->toBe([]);
+});
+
+test('実行系で終わる代入は builder alias にならず、結果はモデルとして provenance 証明になる', function (): void {
+    expect(scannerCandidates(<<<'PHP'
+        public function run(Project $project, int $categoryId): void
+        {
+            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
+            $locked->categories()->whereKey($categoryId)->firstOrFail();
+        }
+    PHP))->toBe([]);
+});
+
+// --- outOfScope: 「保証」ではなく「既知の範囲外」 -----------------------------
+
+test('outOfScope_whereRaw は候補にならないが 0 件 assertion 側で見張られる', function (): void {
+    expect(scannerCandidates(<<<'PHP'
+        public function run(int $id): void
+        {
+            User::query()->whereRaw('id = ?', [$id])->first();
+        }
+    PHP))->toBe([]);
+
+    expect(PrimaryKeyStaticQueryScanner::containsRawPrimaryKeyPredicate(
+        "User::query()->whereRaw('id = ?', [\$id])->first();"
+    ))->toBeTrue();
+
+    // 非リテラル引数は中身が読めないので無条件 fail させる
+    expect(PrimaryKeyStaticQueryScanner::containsRawPrimaryKeyPredicate(
+        'User::query()->whereRaw($sql)->first();'
+    ))->toBeTrue();
+
+    // quoted identifier / raw variant も同じ列指定なので見逃さない
+    foreach ([
+        "User::query()->whereRaw('`id` = ?', [\$id]);",
+        "User::query()->whereRaw('\"id\" = ?', [\$id]);",
+        "User::query()->orWhereIntegerInRaw('id', \$ids);",
+        "User::query()->whereIntegerNotInRaw('id', \$ids);",
+    ] as $snippet) {
+        expect(PrimaryKeyStaticQueryScanner::containsRawPrimaryKeyPredicate($snippet))->toBeTrue($snippet);
+    }
+
+    expect(PrimaryKeyStaticQueryScanner::containsRawPrimaryKeyPredicate(
+        "User::query()->whereRaw('lower(email) = ?', [\$email])->first();"
+    ))->toBeFalse();
+});
+
+test('sameMethodQuery の副条件は任意 object のメソッド結果では通らない', function (): void {
+    $loose = scannerCandidates(<<<'PHP'
+        public function run($input): void
+        {
+            $ids = $input->ids();
+            foreach ($ids as $id) {
+                User::find($id);
+            }
+        }
+    PHP);
+    expect($loose)->not->toBe([]);
+    expect(PrimaryKeyStaticQueryScanner::identityDerivedFromSameMethodQuery($loose[0]))->toBeFalse();
+
+    $scan = scannerCandidates(<<<'PHP'
+        public function run(): void
+        {
+            $ids = User::query()->where('active', true)->pluck('id');
+            foreach ($ids as $id) {
+                User::find($id);
+            }
+        }
+    PHP);
+    expect(PrimaryKeyStaticQueryScanner::identityDerivedFromSameMethodQuery($scan[0]))->toBeTrue();
+});
+
+test('literalIsInsideGuardedBlock は条件ブロックの内側だけを受理する', function (): void {
+    $source = <<<'PHP'
+    <?php
+
+    if (app()->isLocal() || app()->runningUnitTests()) {
+        Route::post('/debug/login/{userId}', [C::class, 'loginAs'])->name('debug.login-as');
+    }
+
+    Route::get('/health', [C::class, 'health'])->name('health');
+    PHP;
+
+    expect(PrimaryKeyStaticQueryScanner::literalIsInsideGuardedBlock($source, 'debug.login-as', ['isLocal', 'runningUnitTests']))->toBeTrue();
+    expect(PrimaryKeyStaticQueryScanner::literalIsInsideGuardedBlock($source, 'health', ['isLocal', 'runningUnitTests']))->toBeFalse();
+});
+
+test('outOfScope_動的列名は候補にならない', function (): void {
+    expect(scannerCandidates(<<<'PHP'
+        public function run(string $col, int $id): void
+        {
+            User::query()->where($col, $id)->first();
+        }
+    PHP))->toBe([]);
+});
+
+// --- 副条件ヘルパ -----------------------------------------------------------
+
+test('request accessor 判定: 入力読み出しだけを accessor とみなす', function (): void {
+    $accessors = [
+        'public function run(): void { $x = $request->input("a"); User::find($x); }',
+        'public function run(): void { $x = $request->query("a"); User::find($x); }',
+        'public function run(): void { $x = $request->validated()["a"]; User::find($x); }',
+        'public function run(): void { User::find(request("user_id")); }',
+        'public function run(): void { User::find(request()->input("user_id")); }',
+    ];
+    foreach ($accessors as $body) {
+        $candidates = scannerCandidates($body);
+        expect($candidates)->not->toBe([], $body);
+        expect(PrimaryKeyStaticQueryScanner::methodIsFreeOfRequestAccessors($candidates[0]))->toBeFalse($body);
+    }
+
+    // $request の素通し / attributes バッグは入力読み出しではない
+    $passthrough = scannerCandidates(
+        'public function run($request, int $id): void { $this->actor($request); User::find($id); }'
+    );
+    expect(PrimaryKeyStaticQueryScanner::methodIsFreeOfRequestAccessors($passthrough[0]))->toBeTrue();
+
+    $attributes = scannerCandidates(
+        'public function run(): void { $id = request()->attributes->get("org"); User::find($id); }'
+    );
+    expect(PrimaryKeyStaticQueryScanner::methodIsFreeOfRequestAccessors($attributes[0]))->toBeTrue();
+});
+
+test('所有者スコープ判定は右辺 provenance まで見る', function (): void {
+    $scoped = scannerCandidates(<<<'PHP'
+        public function run(User $user, int $id): void
+        {
+            Project::query()->whereKey($id)->where('user_id', $user->getKey())->first();
+        }
+    PHP);
+    expect(PrimaryKeyStaticQueryScanner::hasOwnerScopedConstraint($scoped[0]))->toBeTrue();
+
+    // 右辺が request 由来の値なら所有者スコープとみなさない
+    $unscoped = scannerCandidates(<<<'PHP'
+        public function run(int $id, int $requestOrgId): void
+        {
+            Project::query()->whereKey($id)->where('organization_id', $requestOrgId)->first();
+        }
+    PHP);
+    expect(PrimaryKeyStaticQueryScanner::hasOwnerScopedConstraint($unscoped[0]))->toBeFalse();
+
+    // 所有者列でない制約では通らない
+    $irrelevant = scannerCandidates(<<<'PHP'
+        public function run(User $user, int $id): void
+        {
+            Project::query()->whereKey($id)->where('active', true)->first();
+        }
+    PHP);
+    expect(PrimaryKeyStaticQueryScanner::hasOwnerScopedConstraint($irrelevant[0]))->toBeFalse();
+});
+
+test('非主キー一意列による解決は列挙され、Plan の code だけ除外される', function (): void {
+    $source = <<<'PHP'
+    <?php
+
+    namespace App\Services;
+
+    use App\Models\Plan;
+    use App\Models\Project;
+
+    class Sample
+    {
+        public function run(string $value): void
+        {
+            Plan::query()->where('code', $value)->first();
+            Project::query()->where('slug', $value)->first();
+            Project::query()->whereUuid($value)->first();
+        }
+    }
+    PHP;
+
+    expect(PrimaryKeyStaticQueryScanner::uniqueColumnResolutions($source, 'app/Services/Sample.php', scannerModelTables()))
+        ->toBe([
+            'Services/Sample.php#run#Project.where:slug',
+            'Services/Sample.php#run#Project.whereUuid:uuid',
+        ]);
+});
+
+test('methodBody は指定メソッドの本文だけを切り出す', function (): void {
+    $source = <<<'PHP'
+    <?php
+
+    namespace App\Services;
+
+    class Sample
+    {
+        public function alpha(): void
+        {
+            $this->one();
+        }
+
+        public function beta(): void
+        {
+            $this->two();
+        }
+    }
+    PHP;
+
+    $body = PrimaryKeyStaticQueryScanner::methodBody($source, 'beta');
+    expect($body)->not->toBeNull();
+    expect(str_contains((string) $body, 'two'))->toBeTrue();
+    expect(str_contains((string) $body, 'one'))->toBeFalse();
+});
```

## 検証結果 (修正後)

- `composer test`: 3175 tests, 3173 passed, 0 failed, 2 skipped
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- 走査器の実測: 候補 34 件 (変化なし) / 動的列名 3 件 / raw 主キー述語 0 件 / 非主キー一意列 0 件
- Unit テスト: 32 tests (Round 1 から +7)

## 依頼

残る [Critical] / [Warning] があれば指摘せよ。無ければ `APPROVED` と書け。
特に「この書き方をすると gate を素通りする」具体例が他にあるかを重点的に見てほしい。
