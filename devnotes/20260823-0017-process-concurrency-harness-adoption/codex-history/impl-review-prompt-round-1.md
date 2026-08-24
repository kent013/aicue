# 実装レビュー依頼 (aicue / T248 実プロセス並行テストハーネス)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

あなたは Laravel 12 + Svelte 5 アプリ **aicue** のコードレビュアーである。
本 PR は「冪等キーの実行前 claim を**実プロセス 2 本**で証明するテストハーネス」の新規導入である
(家系の機能台帳 lctl の feature `process-concurrency-test-harness` 正典 v1 への追従)。

### レビュー観点

1. **設計との一致性** — 詳細設計 (下記パス) の施策 1〜9 に対して、実装が過不足なく対応しているか。
   **意図的に設計から外した点**は下の「設計からの逸脱」に列挙してある。その判断が妥当かを見よ。
2. **正確性** — 並行プロトコル (ready → go → entered → out → release) に競合・取りこぼし・
   偽陽性/偽陰性が無いか。とくに「二重実行を検出できない形」「緑のまま嘘になる形」を探せ。
3. **PHPStan 適合性** — 本リポジトリの `phpstan.neon` の `paths` は `app / config / database / routes` で
   **`tests` を含まない**。よって本 PR は静的解析の対象外であり、型の保証は
   **実行時の fail-closed 検証**と失敗経路テストが担う設計である。
4. **DTO / JsonResource パターン** — 本 PR は HTTP 応答を作らない (probe route の応答は
   テスト専用の `new JsonResponse`)。値の受け渡しは値オブジェクトで行っているか。
5. **テスト網羅性** — ハーネス自身が「黙って緑になる」壊れ方を塞げているか。
   失敗経路テスト (4 群) に**抜けている壊れ方**があれば指摘せよ。
6. **セキュリティ** — 最大の危険は**開発 DB への到達**と**秘密 (APP_KEY / CIPHERSWEET_KEY /
   DB パスワード / plain API キー / request body) の残置**である。遮断 9 段と
   秘密の後始末に穴が無いか。
7. **DESIGN.md 準拠 / Atomic Design 準拠** — 本 PR は `resources/js` / `resources/css` を
   1 バイトも触らない (frontend 変更 0 件) ため**該当なし**。

### 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に**全体判定**を `APPROVED` または `CHANGES_REQUESTED` の 1 語で書く

---

## user: データ

### 詳細設計書

全文はリポジトリ内の次のパスにある (read-only で読んでよい):

    /workspace/.claude/worktrees/tasks/T248/devnotes/20260823-0017-process-concurrency-harness-adoption/detailed-design.md

参考: 概念設計は同ディレクトリの `conceptual-design.md`。

#### 施策の要約 (設計書の目次)

- 施策 1: 合図の待ち合わせ (barrier) と締切 — `SignalName` / `ProcessBarrier` / 例外 2 型
- 施策 2: transaction 外の検体置き場と確実な後始末 — `OutOfTransactionFixtures` / `ConcurrencyFixtureKeys`
- 施策 3: 一次観測の型 (fail-closed) — `ConcurrentProbeObservation` / `ProbeDatabaseCoordinates`
- 施策 4: 子の起動・遮断・回収・調停 — `ProbeEnvironment` / `ProbeLaunchSpec` / `ProbeProcess` /
  `ProbeProcessFactory` / `SymfonyProbeProcess(Factory)` / `ConcurrentProbeResult` / `ConcurrencyProbeRunner`
- 施策 5: 実行スクリプト (子プロセスの本体) — `idempotency-claim-probe.php`
- 施策 6: 見本テスト (実プロセス版はこの 1 本だけ) — `IdempotencyClaimProcessConcurrencyTest`
- 施策 7: ハーネス自身の失敗経路の検査 (4 群) — `ConcurrencyHarnessFailurePathTest`
- 施策 8: 既存テストの「保証しないこと」宣言の是正 — `IdempotencyConcurrentClaimTest` の docblock のみ
- 施策 9: 乖離台帳 D7 の再判定記録と文書追記 — `docs/template-divergence.md` / `docs/architecture.md`

#### 設計からの逸脱 (実装時に判断した 1 点。ここを重点的に見てほしい)

**設計 施策 3 / 5 は子の観測項目に `cache_driver` = `Cache::getDefaultDriver()` を挙げていたが、
実装では `cache_store_driver` = `config("cache.stores.{既定store名}.driver")` に置き換えた。**

理由:
1. 本リポジトリには `tests/Architecture/CachePayloadPlainDataGateTest.php` の **L3 目録**があり、
   「キャッシュ API に触れるファイル」を exact-fit で列挙する deny-by-default 検査になっている。
   probe スクリプトが `Cache::getDefaultDriver()` を呼ぶと、この目録への登録が必須になる
   (実際に full test で赤くなったことを実測した)。
2. ところがその目録ファイル自身が `tests/Support/TemplateDivergence/adoption-debt.tsv`
   (採用時債務。採用時のアプリ側 sha256 で凍結) に載っており、**1 バイトでも変えると
   `TemplateDivergenceFingerprintTest` が落ちる**。解消の道は「テンプレートの姿へ戻す」か
   「意図的逸脱として登録簿へ D 番号を書き債務から削る」の 2 つしかなく、後者は
   本 TODO の設計が明示的に「D 登録も件数 pin の変更もしない」と決めた範囲を超える。
3. 加えて `Cache::getDefaultDriver()` の戻り値は vendor 実装上
   `$this->app['config']['cache.default']` そのもの (= `cache_default` と同じ事実の写し) であり、
   観測としての情報量が無い。置き換えた `cache_store_driver` は
   「`array` という**名前**の store が実は別の driver で裏打ちされている」形まで落とせるので**より強い**。

この判断が妥当か、あるいは別の道 (目録への登録 + D 登録) を採るべきかを評価してほしい。

### 検証結果

- `composer phpstan` : OK (No errors)
- `vendor/bin/pint --test` : passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` : すべて成功
- `composer test -- "--filter=Concurrency|CachePayloadPlainDataGate|TemplateDivergence"` :
  221 tests / 220 passed / 1 skipped (skip は本 PR 以前から存在する D7 のプレースホルダ)
  - うち新規は 47 件 (失敗経路 40 / transaction 外検体 6 / 実プロセス 2 本の見本 1)
  - **実プロセス 2 本を実際に起こす見本テストが緑**である (子は実 DB へ接続し、実 middleware 列を通る)
- full `composer test` は本レビューと並行して実行中 (直前の full 実行では、上記 L3 目録の 1 件を除き
  6466/6469 passed)

### 実装差分 (git diff HEAD)

```diff
diff --git a/docs/architecture.md b/docs/architecture.md
index 98766764..69e70d30 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -2153,6 +2153,14 @@ ## 冪等キーの claim と保持期間 (REST API v1 / MCP)
   使われていることが前提 (既存の `billing:send-billing-reminders` /
   `render:reconcile-outputs` と同じ。本節で新しく持ち込む前提ではない)。
   満たさないと多重実行しうるが DELETE は冪等で、害は `report()` の重複に留まる。
+- **実プロセス並行テスト (T248)**: `tests/Support/Concurrency` のハーネスが barrier で同期した
+  実プロセス 2 本を走らせ、`tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php`
+  が「冪等 claim の本処理はちょうど 1 回・敗者は `idempotency_in_progress`」を実経路で固定する
+  (**実プロセス版はこの 1 本だけ**。細かい分岐は同一プロセスの
+  `tests/Feature/Api/IdempotencyConcurrentClaimTest.php` が持つ)。子の DB 座標は親の実接続設定から
+  作られ、`TestDatabaseEnv::assertPgsqlTestDatabaseSafe()` を親子で 2 回通る。
+  検体は `OutOfTransactionFixtures` がテストの transaction の外へ commit し、末尾で 8 表の
+  残留ゼロを検査して片付ける。
 
 ## 退会の猶予期間つき削除 (凍結方式・30 日)
 
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 14198914..e950920c 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -333,7 +333,7 @@ ## D7 org 同時 preview 上限の「直列化実証テスト」は subprocess 
 | 対象パス | `app/Services/Manual/RenderJobService.php` / `tests/Feature/Manual/RenderPreviewConcurrencyTest.php` |
 | 業務要件起因の説明 | `RefreshDatabase` が検体を未コミットのトランザクション内に置くため、別プロセスからは検体が見えず、直列化の実証には非トランザクションの専用レーンが要る |
 | 揃え続ける不変条件と保証機構 | 組織ごとの同時 preview 上限の検査とジョブ作成は Organization 行ロック下で行う。逐次境界は `RenderPreviewConcurrencyTest` が固定する |
-| 再判定の条件 | 非トランザクションのテストレーンを導入したとき (別プロセスでの実証へ移す) |
+| 再判定の条件 | 実プロセス並行テストの本数制約を見直すとき、または preview 上限の直列化に退行が疑われたとき |
 | 決めた日 | 2026-07-11 |
 | 決めた人 | 開発者 |
 | 根拠 | T005 |
@@ -365,10 +365,24 @@ ### 揃えている不変条件 (これは保証し続ける)
   §レンダジョブの運用契約が正本)
 - subprocess 実証は同テストの skip プレースホルダとして残置 (専用 lane 導入時に実装する)
 
+### 再判定の記録 (2026-08-23)
+
+lctl feature `process-concurrency-test-harness` (rev `14-3117f6369f21` / 正典 v1) への追従作業
+(T248) 時に再判定した。**本登録は据え置く (完了扱いにしない)**。
+
+- 非トランザクションの検体置き場 (`tests/Support/Concurrency/OutOfTransactionFixtures.php`) は
+  導入したので、本登録が挙げていた前提 (「別プロセスからは検体が見えない」) の一部は解消した
+- ただし正典 v1 の要素 (6) が **実プロセス版は 1 本に絞る**ことを求めており、その 1 本は
+  冪等 claim (`tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php`) へ
+  割り当てた。preview 上限の実証は**逐次境界のまま据え置く**
+- したがって「subprocess 実証が入った」と読まないこと。道具はできたので、
+  次に実プロセス版の本数制約を見直すときに選択肢へ載る
+
 ### 関連
 
 - 実装: `app/Services/Manual/RenderJobService.php` (triggerPreview)
 - 設計: `devnotes/20260711-0549-render/detailed-design.md` 施策 4 テスト計画
+- 再判定: `devnotes/20260823-0017-process-concurrency-harness-adoption/detailed-design.md` 施策 9
 
 ---
 
diff --git a/tests/Feature/Api/IdempotencyConcurrentClaimTest.php b/tests/Feature/Api/IdempotencyConcurrentClaimTest.php
index 138780d2..48bd4a36 100644
--- a/tests/Feature/Api/IdempotencyConcurrentClaimTest.php
+++ b/tests/Feature/Api/IdempotencyConcurrentClaimTest.php
@@ -20,11 +20,17 @@
  *   (b) 同一スコープの 2 本目の INSERT を unique が落とすこと (テスト 3)
  * を固定する。
  *
- * ★**保証しないこと**: PHP のテストは単一プロセスであり、真の並行 2 本は走らせていない。
- *   `RefreshDatabase` 下では全操作が同一接続・同一トランザクション内で見えるため、
- *   claim の commit も別接続からの可視性も検証していない。本番で後着から claim が
- *   見えるのは「middleware を包む外側 transaction が無い + pgsql の autocommit /
- *   read committed」という前提の帰結であって、テストによる保証ではない。
+ * ★**このテストが保証しないこと**: 単一プロセスであり、真の並行 2 本は走らせていない。
+ *   細かい分岐 (再生 / conflict / indeterminate / 期限切れ再 claim / 順序) を
+ *   決定的に固定するのが本テストの役割である。
+ *
+ * ★**実プロセス 2 本での裏取りは別にある**:
+ *   tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php が
+ *   barrier で同期させた実プロセス 2 本で、
+ *   (a) claim の commit が別接続 (別プロセス) から見えること
+ *   (b) 本処理を通したのはちょうど 1 本で、もう 1 本は idempotency_in_progress で弾かれること
+ *   を測っている。**埋まったのはこの 2 点だけ**である —
+ *   任意の production route や実ジョブの副作用まで保証したわけではない。
  */
 
 /** report() 経路 (運用アラート) を観測する spy を差し込む */
diff --git a/tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php b/tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php
new file mode 100644
index 00000000..c317b174
--- /dev/null
+++ b/tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php
@@ -0,0 +1,132 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\ApiErrorCode;
+use App\Enums\Idempotency\IdempotencyState;
+use Illuminate\Support\Str;
+use Tests\Support\Concurrency\ConcurrencyFixtureKeys;
+use Tests\Support\Concurrency\ConcurrencyProbeRunner;
+use Tests\Support\Concurrency\ConcurrentProbeObservation;
+use Tests\Support\Concurrency\OutOfTransactionFixtures;
+use Tests\Support\Concurrency\ProbeDatabaseCoordinates;
+
+/*
+ * 冪等キーの実行前 claim を**実プロセス 2 本**で証明する
+ * (正典 v1 の要素 (6): 実プロセス版はこの 1 本だけ)。
+ *
+ * 守られている実装 (App\Http\Middleware\IdempotentRequest::claim) は
+ * 「unique 制約が唯一の調停者で、cache ロック等の補助機構は使わない」と宣言している。
+ * 本テストはその宣言を実経路の証拠にする — 子の cache を配列固定にし
+ * **Laravel の既定 cache を使うプロセス間共有ロックが利用できない状態**を作ってから測る。
+ *
+ * ★**本テストが測る範囲**: 既定 cache が array であることから直接言えるのは
+ *   「Laravel の既定 cache を経由するプロセス間共有ロックが使えない」までである。
+ *   「アプリ側ロックが 1 つも無い」とは書かない (観測を超える)。
+ *   claim が unique 制約以外の補助機構を持たないことは**実装の実読**が担い、
+ *   2 つを合わせて初めて「DB の一意制約だけで 1 回に収まる」と読む。
+ *
+ * ★probe 経路の middleware 列は「冪等 middleware の前提を満たす最小構成」であり
+ *   **「本番同等」とは主張しない** (throttle は 2 本の到達を乱すため入れていない)。
+ *
+ * ★細かい分岐 (再生 / conflict / indeterminate / 期限切れ再 claim / 順序) は
+ *   **同一プロセス**の tests/Feature/Api/IdempotencyConcurrentClaimTest.php が持つ。ここへ足さない。
+ */
+
+test('実プロセス 2 本の同時 claim で本処理はちょうど 1 回だけ通る', function (): void {
+    $expectedCoordinates = ProbeDatabaseCoordinates::fromParentConfig();
+
+    // 検体はテストの transaction の**外**に作る (子から見えなければ成立しない)。
+    // ★route 名はここでは決まらない (runner が決める) ので、鍵は 4 つだけ持つ。
+    [$keys, $plainKey] = OutOfTransactionFixtures::create(function (): array {
+        [$organization, $owner] = createOrganizationWithOwner();
+        [$apiKey, $plain] = issueApiKey($organization, $owner);
+
+        return [new ConcurrencyFixtureKeys(
+            organizationId: $organization->id,
+            laratrustTeamId: $organization->laratrust_team_id,
+            userId: $owner->id,
+            apiKeyId: $apiKey->id,
+        ), $plain];
+    });
+
+    try {
+        // ★同一性 (childId / nonce / go token) の検査は runner の中で完結している。
+        //   ここで再検査しない = 内部プロトコルをテストへ漏らさない。
+        $result = ConcurrencyProbeRunner::run(
+            idempotencyKey: (string) Str::uuid(),
+            plainApiKey: $plainKey,
+            requestBody: ['title' => '並行 claim の検体'],
+        );
+
+        expect($result->observations)->toHaveCount(2);
+
+        // (1) ハンドラ実行回数の**合計が 1** ← 一次観測。本テストの核心
+        $executions = array_sum(array_map(
+            fn (ConcurrentProbeObservation $observation): int => $observation->handlerExecutions,
+            $result->observations,
+        ));
+        expect($executions)->toBe(1);
+
+        // (2) 勝者は 201 / entered=true、敗者は 409 + idempotency_in_progress / entered=false
+        //     ★status だけでは足りない — 409 は 3 コードあり、body 違いの conflict でも
+        //       (1) まで成立して**緑になる**。error_code の完全一致で塞ぐ。
+        [$winner, $loser] = $result->partition();
+        expect($winner->enteredHandler)->toBeTrue();
+        expect($loser->enteredHandler)->toBeFalse();
+        expect($winner->httpStatus)->toBe(201);
+        expect($winner->handlerExecutions)->toBe(1);
+        expect($winner->errorCode)->toBeNull();
+        expect($loser->httpStatus)->toBe(409);
+        expect($loser->errorCode)->toBe(ApiErrorCode::IdempotencyInProgress->value);
+        expect($loser->handlerExecutions)->toBe(0);
+
+        // (3) 2 子は**同一要求**だった。親の期待 hash を含めた**3 点一致**で見る
+        //     (2 子の一致だけだと「2 本とも同じ誤った body を送った」形と区別がつかない)
+        expect($winner->requestHash)->toBe($result->expectedRequestHash);
+        expect($loser->requestHash)->toBe($result->expectedRequestHash);
+        expect($winner->routeName)->toBe($result->routeName);
+        expect($loser->routeName)->toBe($result->routeName);
+
+        // (4) 認証結果の api_key_id が**検体のもの**と一致する
+        //     (★入力のコピーではなく ApiActorContext から観測した値である)
+        expect($winner->apiKeyId)->toBe($keys->apiKeyId);
+        expect($loser->apiKeyId)->toBe($keys->apiKeyId);
+
+        // (5) 2 子とも既定 cache が array
+        //     (= Laravel の既定 cache を使うプロセス間共有ロックが利用できない状態)
+        foreach ($result->observations as $observation) {
+            $observation->assertAppLocksDisabled();
+        }
+
+        // (6) 2 子の実効 DB 座標が親の値と**完全一致**
+        //     (driver/host/port/database/username/charset/sslmode。url は空のみ許可)
+        foreach ($result->observations as $observation) {
+            $observation->assertDatabaseCoordinates($expectedCoordinates);
+        }
+
+        // (7) 裏取り: 行は 1 本だけで completed (**別名接続で読む**)。
+        //     ★スコープ (api_key_id + route_name + key) まで絞り、
+        //       保存された request_hash も親の期待値と突き合わせる。
+        $rows = OutOfTransactionFixtures::connection()
+            ->table('idempotency_keys')
+            ->where('api_key_id', $keys->apiKeyId)
+            ->where('route_name', $result->routeName)
+            ->where('key', $result->idempotencyKey)
+            ->get();
+        expect($rows)->toHaveCount(1);
+        expect($rows[0]->state)->toBe(IdempotencyState::Completed->value);
+        // pgsql ドライバは整数列を文字列で返しうるので緩い比較で見る (値の一致が論点)
+        expect($rows[0]->response_status)->toEqual(201);
+        expect($rows[0]->request_hash)->toBe($result->expectedRequestHash);
+
+        // (8) スコープ外に余分な行が無い (api_key_id 全体で 1 件)
+        $all = OutOfTransactionFixtures::connection()
+            ->table('idempotency_keys')->where('api_key_id', $keys->apiKeyId)->count();
+        expect($all)->toBe(1);
+    } finally {
+        // 子が commit した行は RefreshDatabase の rollback では消えない。必ず片付ける。
+        // ★cleanup() は削除後に自分で 8 表の残留ゼロを検査する。
+        OutOfTransactionFixtures::cleanup($keys);
+    }
+});
diff --git a/tests/Feature/Concurrency/OutOfTransactionFixturesTest.php b/tests/Feature/Concurrency/OutOfTransactionFixturesTest.php
new file mode 100644
index 00000000..38b654cc
--- /dev/null
+++ b/tests/Feature/Concurrency/OutOfTransactionFixturesTest.php
@@ -0,0 +1,135 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Facades\DB;
+use Tests\Support\Concurrency\ConcurrencyFixtureKeys;
+use Tests\Support\Concurrency\OutOfTransactionFixtures;
+
+/*
+ * transaction 外の検体置き場の契約 (正典 v1 の要素 (2))。
+ *
+ * `RefreshDatabase` は検体を**未コミットの transaction の中**に置くため、子プロセスからは
+ * 見えない。本テストは「別名接続へ出して commit し、末尾で確実に片付ける」という契約が
+ * 実際に効いていることを固定する。
+ *
+ * ★**片付けの完全性は cleanup() 自身の契約**である (8 表の残留ゼロ検査)。
+ *   本テストはその検査器そのものが機能していること (削除前なら非ゼロを数えること) も見る —
+ *   「残留があるのに 0 と数える」退行は、残留ゼロ検査だけでは緑のまま通ってしまう。
+ *
+ * **保証しないもの**: 見ているのは cleanup が受け持つ 8 表だけである。検体の生成経路が
+ * 別の表へ行を足すようになったら、この検査は沈黙する (一覧を同じ変更で増やすこと)。
+ */
+
+/** 検体 (組織 + owner + API キー) を transaction の外に作る */
+function createOutOfTransactionFixture(): ConcurrencyFixtureKeys
+{
+    return OutOfTransactionFixtures::create(function (): ConcurrencyFixtureKeys {
+        [$organization, $owner] = createOrganizationWithOwner();
+        [$apiKey] = issueApiKey($organization, $owner);
+
+        return new ConcurrencyFixtureKeys(
+            organizationId: $organization->id,
+            laratrustTeamId: $organization->laratrust_team_id,
+            userId: $owner->id,
+            apiKeyId: $apiKey->id,
+        );
+    });
+}
+
+test('create() で作った行は別名接続から見える (テストの transaction の外に出ている)', function (): void {
+    $keys = createOutOfTransactionFixture();
+
+    try {
+        $rows = OutOfTransactionFixtures::connection()
+            ->table('organizations')
+            ->where('id', $keys->organizationId)
+            ->count();
+
+        expect($rows)->toBe(1);
+
+        // 既定接続 (テストの transaction の中) から見ても在る = 同じ DB を指している
+        expect(DB::table('api_keys')->where('id', $keys->apiKeyId)->count())->toBe(1);
+    } finally {
+        OutOfTransactionFixtures::cleanup($keys);
+    }
+});
+
+test('residueCounts() は削除前の検体を数え上げる (検査器そのものが機能している)', function (): void {
+    $keys = createOutOfTransactionFixture();
+
+    try {
+        $counts = OutOfTransactionFixtures::residueCounts($keys);
+
+        // 8 表すべてが対象で、検体を作った直後はどれも 1 件以上ある
+        expect(array_keys($counts))->toBe([
+            'idempotency_keys', 'api_keys', 'organization_user', 'custom_teams',
+            'organizations', 'role_user', 'teams', 'users',
+        ]);
+
+        // idempotency_keys だけは検体の時点で 0 件 (要求を出していないため)
+        expect($counts['idempotency_keys'])->toBe(0);
+
+        foreach (['api_keys', 'organization_user', 'custom_teams', 'organizations', 'role_user', 'teams', 'users'] as $table) {
+            expect($counts[$table])->toBeGreaterThan(0);
+        }
+    } finally {
+        OutOfTransactionFixtures::cleanup($keys);
+    }
+});
+
+test('cleanup() の後は 8 表すべてで残留が 0 (organizations は物理削除される)', function (): void {
+    $keys = createOutOfTransactionFixture();
+
+    OutOfTransactionFixtures::cleanup($keys);
+
+    // ★softDeletes を持つ organizations も query builder 経由で**物理削除**されている
+    //   (Eloquent の delete() だと deleted_at が入るだけで行は残る)。
+    expect(OutOfTransactionFixtures::residueCounts($keys))->toBe([
+        'idempotency_keys' => 0,
+        'api_keys' => 0,
+        'organization_user' => 0,
+        'custom_teams' => 0,
+        'organizations' => 0,
+        'role_user' => 0,
+        'teams' => 0,
+        'users' => 0,
+    ]);
+});
+
+test('cleanup() は冪等 (2 回呼んでも安全)', function (): void {
+    $keys = createOutOfTransactionFixture();
+
+    OutOfTransactionFixtures::cleanup($keys);
+    OutOfTransactionFixtures::cleanup($keys);
+
+    expect(OutOfTransactionFixtures::residueCounts($keys)['users'])->toBe(0);
+});
+
+test('別名接続の座標は既定接続と一致する (別の DB を向いていない)', function (): void {
+    $expected = config('database.connections.pgsql');
+
+    $keys = createOutOfTransactionFixture();
+
+    try {
+        expect(config('database.connections.'.OutOfTransactionFixtures::CONNECTION_NAME))->toBe($expected);
+        expect(OutOfTransactionFixtures::connection()->getDatabaseName())
+            ->toBe(DB::connection('pgsql')->getDatabaseName());
+    } finally {
+        OutOfTransactionFixtures::cleanup($keys);
+    }
+});
+
+test('create() の中で例外が出たら既定接続名が元へ戻り、別名接続は disconnect + purge される', function (): void {
+    $original = config('database.default');
+
+    expect(fn () => OutOfTransactionFixtures::create(function (): never {
+        throw new RuntimeException('検体の生成に失敗した');
+    }))->toThrow(RuntimeException::class, '検体の生成に失敗した');
+
+    expect(config('database.default'))->toBe($original);
+    expect(array_key_exists(
+        OutOfTransactionFixtures::CONNECTION_NAME,
+        DB::getConnections(),
+    ))->toBeFalse();
+});
diff --git a/tests/Support/Concurrency/BarrierTimeoutException.php b/tests/Support/Concurrency/BarrierTimeoutException.php
new file mode 100644
index 00000000..8ed45412
--- /dev/null
+++ b/tests/Support/Concurrency/BarrierTimeoutException.php
@@ -0,0 +1,37 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use RuntimeException;
+
+/**
+ * 締切を超えた (合図が現れなかった / 作業全体の締切を使い切った)。
+ *
+ * ★プロトコルが破られたこと ({@see ConcurrencyProtocolException}) と**型で分ける**。
+ *   探している退行 (二重実行) を「締切超過」という紛らわしい形で出さないためである。
+ */
+final class BarrierTimeoutException extends RuntimeException
+{
+    public static function waitingFor(SignalName $name, float $remainingSeconds): self
+    {
+        return new self(sprintf(
+            '合図 "%s" が %.3f 秒以内に現れなかった',
+            $name->value,
+            $remainingSeconds,
+        ));
+    }
+
+    /** 作業の締切を使い切った (次の待ちに入れない) */
+    public static function workDeadlineExhausted(): self
+    {
+        return new self('実プロセス並行テストの作業の締切を使い切った (次の待ちに入れない)');
+    }
+
+    /** どの子も本処理へ入らないまま作業の締切を超えた */
+    public static function waitingForSingleEntered(): self
+    {
+        return new self('本処理へ入った子が 1 本も現れないまま作業の締切を超えた');
+    }
+}
diff --git a/tests/Support/Concurrency/ConcurrencyFixtureKeys.php b/tests/Support/Concurrency/ConcurrencyFixtureKeys.php
new file mode 100644
index 00000000..363a8f20
--- /dev/null
+++ b/tests/Support/Concurrency/ConcurrencyFixtureKeys.php
@@ -0,0 +1,22 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+/**
+ * 作った検体の主キー (cleanup の対象を推測させないために持ち回る)。
+ *
+ * ★route 名は**持たない**。route 名を決めるのは {@see ConcurrencyProbeRunner} であり、
+ *   検体の生成時にはまだ存在しない。掃除は `api_key_id` で足りる
+ *   (`idempotency_keys` は cascade 対象)。
+ */
+final readonly class ConcurrencyFixtureKeys
+{
+    public function __construct(
+        public int $organizationId,
+        public int $laratrustTeamId,
+        public int $userId,
+        public int $apiKeyId,
+    ) {}
+}
diff --git a/tests/Support/Concurrency/ConcurrencyProbeRunner.php b/tests/Support/Concurrency/ConcurrencyProbeRunner.php
new file mode 100644
index 00000000..54d28d71
--- /dev/null
+++ b/tests/Support/Concurrency/ConcurrencyProbeRunner.php
@@ -0,0 +1,619 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use Closure;
+use RuntimeException;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 実プロセス 2 本を barrier で同期させて走らせ、一次観測を回収する。
+ *
+ * 段取り:
+ *  1. 子ごとの ready を全員ぶん待ち、**中身の nonce を照合**する
+ *  2. **ここで初めて** go token をランダム生成し、go を 1 つ置く
+ *     (事前に渡さないので、go を読まずに正しい token を書くことは構造的にできない)
+ *  3. entered を待つ (割り当て済みの完成名だけを調べる。prefix の glob は使わない)
+ *  4. **反対側の out を待ち、中身を完全に検査する**
+ *  5. 検査をすべて通ったら release を置く
+ *  6. 両方の終了を待ち、exit code 0 と stdout/out の一致を確かめて観測を返す
+ *
+ * ★4 の検査を通す前に release しない。「出てきたから release して、あとから赤くする」形は
+ *   結果的に赤にはなるがプロトコルの証拠が弱い。
+ * ★3〜5 の待機中は**常に**「2 つ目の entered / 未知の完成合図 / 子の異常終了」を監視する
+ *   (単一ファイルだけを待つブロッキングにすると、二重実行の即時検出という性質が失われる)。
+ * ★締切は**単一の絶対 deadline** である。段ごとに更新すると総時間が締切を大幅に超える。
+ *
+ * **保証の言い方**: 回収について主張するのは
+ * 「bounded な回収操作 (TERM / KILL / 上限つき poll) を必ず要求し、停止を確認できなければ
+ * 失敗させる。秘密は成否にかかわらず必ず消す」までである。
+ * 実 OS プロセスが実際に消えたことは保証範囲外とする。
+ */
+final class ConcurrencyProbeRunner
+{
+    /** **作業の締切** (子の起動 + 合図 + 要求 + 通常の終了待ちを打ち切る) */
+    public const float DEFAULT_TIMEOUT_SECONDS = 60.0;
+
+    /** 子の識別子 (固定 2 本。N 本への一般化はしない) */
+    public const array CHILD_IDS = ['a', 'b'];
+
+    /**
+     * **回収専用の予算** (作業の締切とは独立に確保する)。
+     *
+     * ★作業の締切を回収にも使うと、**締切超過の瞬間に残り時間が 0** になり、
+     *   まさに回収が必要な場面で kill 後の待機ができず子が残る。
+     * ★この予算は**全子で共有する** (子ごとに 2 秒ではない)。
+     *   回収はフェーズ単位で行うので、子数が増えても総時間は変わらない。
+     */
+    public const float REAP_BUDGET_SECONDS = 2.0;
+
+    /** SIGTERM から SIGKILL までの猶予 (REAP_BUDGET_SECONDS の内側) */
+    public const float REAP_GRACE_SECONDS = 1.0;
+
+    /** 回収 poll の間隔 (マイクロ秒) */
+    private const int REAP_POLL_INTERVAL_MICROSECONDS = 5_000;
+
+    /**
+     * @param  array<string, mixed>  $requestBody
+     *
+     * @throws BarrierTimeoutException|ConcurrencyProtocolException|RuntimeException
+     */
+    public static function run(
+        string $idempotencyKey,
+        string $plainApiKey,
+        array $requestBody,
+        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
+        ?ProbeProcessFactory $factory = null,
+    ): ConcurrentProbeResult {
+        Assert::stringNotEmpty($idempotencyKey);
+        Assert::stringNotEmpty($plainApiKey);
+        Assert::greaterThan($timeoutSeconds, 0.0);
+
+        $suffix = bin2hex(random_bytes(6));
+        $uri = 'api/v1/__concurrency_probe_'.$suffix.'__';
+        $routeName = 'api.v1.__concurrency_probe_'.$suffix.'__';
+        $rawBody = json_encode($requestBody, JSON_THROW_ON_ERROR);
+
+        // ★middleware と**同一規則**で親が期待 hash を持つ (`Request::path()` は先頭の `/` を含まない)。
+        $expectedRequestHash = hash('sha256', 'POST|'.$uri.'|'.$rawBody);
+
+        // 遮断の段 1〜3 (親側)。ここで落ちたら子は 1 本も起きない。
+        $envValues = ProbeEnvironment::envFileValues();
+
+        $workspace = self::createWorkspace();
+
+        /** @var list<string> $secretPaths */
+        $secretPaths = [];
+        /** @var array<string, ProbeProcess> $processes */
+        $processes = [];
+        /** @var array<string, string> $nonces */
+        $nonces = [];
+
+        try {
+            $envFilePath = $workspace.'/'.ProbeEnvironment::ENV_FILE_NAME;
+            $lines = '';
+            foreach ($envValues as $key => $value) {
+                $lines .= ProbeEnvironment::encodeLine($key, $value);
+            }
+            ProbeEnvironment::writeProtectedFile($envFilePath, $lines);
+            $secretPaths[] = $envFilePath;
+
+            $configCachePath = $workspace.'/config-cache-absent.php';
+            $factory ??= new SymfonyProbeProcessFactory(base_path());
+
+            foreach (self::CHILD_IDS as $childId) {
+                $nonces[$childId] = bin2hex(random_bytes(16));
+
+                $spec = new ProbeLaunchSpec(
+                    workspaceDirectory: $workspace,
+                    childId: $childId,
+                    nonce: $nonces[$childId],
+                    scriptPath: ProbeEnvironment::probeScriptPath(),
+                    environmentDirectory: $workspace,
+                    environmentFileName: ProbeEnvironment::ENV_FILE_NAME,
+                    inputFileName: 'input-'.$childId.'.json',
+                    configCachePath: $configCachePath,
+                );
+
+                // ★秘密 (plain API key / raw body) は argv に載せず 0600 の入力ファイルへ置く。
+                //   go token は**ここに無い** (親は ready を全部検証した後に初めて作る)。
+                ProbeEnvironment::writeProtectedFile($spec->inputFilePath(), json_encode([
+                    'child_id' => $childId,
+                    'nonce' => $nonces[$childId],
+                    'route_name' => $routeName,
+                    'uri' => $uri,
+                    'raw_body' => $rawBody,
+                    'idempotency_key' => $idempotencyKey,
+                    'plain_api_key' => $plainApiKey,
+                    'timeout_seconds' => $timeoutSeconds,
+                ], JSON_THROW_ON_ERROR));
+                $secretPaths[] = $spec->inputFilePath();
+
+                // 遮断の段 4: 起動前の権限検査 (違えば子を起こさない)
+                ProbeEnvironment::assertSafePermissions(
+                    ProbeEnvironment::mode($workspace),
+                    ProbeEnvironment::mode($envFilePath),
+                    ProbeEnvironment::mode($spec->inputFilePath()),
+                );
+
+                $processes[$childId] = $factory->create($spec);
+            }
+
+            $result = self::conduct(
+                new ProcessBarrier($workspace),
+                $processes,
+                $nonces,
+                hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000),
+                $routeName,
+                $uri,
+                $idempotencyKey,
+                $expectedRequestHash,
+            );
+        } catch (Throwable $e) {
+            // ★回収は**作業の失敗の後でも必ず**行う。回収そのものが失敗したときは
+            //   その例外を投げる (元の失敗は previous に畳んで捨てない)。
+            self::reap($processes, $workspace, $secretPaths, $e);
+
+            throw $e;
+        }
+
+        self::reap($processes, $workspace, $secretPaths, null);
+
+        return $result;
+    }
+
+    /**
+     * 合図の待ち合わせと受理条件の検査 (回収は呼び出し側の責務)。
+     *
+     * @param  array<string, ProbeProcess>  $processes
+     * @param  array<string, string>  $nonces
+     */
+    private static function conduct(
+        ProcessBarrier $barrier,
+        array $processes,
+        array $nonces,
+        int $workDeadlineNs,
+        string $routeName,
+        string $uri,
+        string $idempotencyKey,
+        string $expectedRequestHash,
+    ): ConcurrentProbeResult {
+        foreach ($processes as $process) {
+            $process->start();
+        }
+
+        $abort = self::abortCondition($barrier, $processes);
+
+        // 1. ready を全員ぶん待ち、中身の nonce を照合する
+        foreach ($processes as $childId => $process) {
+            $payload = $barrier->await(
+                SignalName::make('ready', $childId),
+                self::remainingWorkSeconds($workDeadlineNs),
+                $abort,
+            );
+
+            if ($payload !== $nonces[$childId]) {
+                throw ConcurrencyProtocolException::identityMismatch(
+                    $childId,
+                    'ready の nonce',
+                    $nonces[$childId],
+                    $payload,
+                );
+            }
+        }
+
+        // 2. **ここで初めて** go token を作る (事前に子へ渡らない)
+        $goToken = bin2hex(random_bytes(16));
+        $barrier->signal(SignalName::make('go'), $goToken);
+
+        // 3. entered をちょうど 1 子ぶん待つ
+        $winnerId = self::awaitSingleEntered($barrier, $nonces, $goToken, $workDeadlineNs, $abort);
+        $loserId = self::oppositeChild($winnerId);
+
+        // 4. 反対側の out を待ち、中身を完全に検査する
+        [$loserJson, $loser] = self::readObservation($barrier, $loserId, $workDeadlineNs, $abort);
+        $loser->assertIdentity($loserId, $nonces[$loserId], $goToken);
+        $loser->assertLost($expectedRequestHash);
+
+        // 5. 検査をすべて通ったら release を置く
+        $barrier->signal(SignalName::make('release'), $goToken);
+
+        // 6. 勝者の out を待ち、同一性を検査する
+        [$winnerJson, $winner] = self::readObservation($barrier, $winnerId, $workDeadlineNs, $abort);
+        $winner->assertIdentity($winnerId, $nonces[$winnerId], $goToken);
+
+        $rawOut = [$winnerId => $winnerJson, $loserId => $loserJson];
+
+        // 受理条件 1: 両 process の exit code が 0
+        foreach ($processes as $childId => $process) {
+            $exitCode = $process->waitFor(self::remainingWorkSeconds($workDeadlineNs));
+            if ($exitCode !== 0) {
+                throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+                    '子 "%s" の終了コードが 0 でない (%s)。stderr: %s',
+                    $childId,
+                    $exitCode === null ? '時間内に終了しなかった' : (string) $exitCode,
+                    $process->errorOutput() === '' ? '(なし)' : $process->errorOutput(),
+                ));
+            }
+        }
+
+        // 受理条件 2: 各子の stdout の JSON と out ファイルの中身が一致
+        foreach ($processes as $childId => $process) {
+            if (trim($process->output()) !== trim($rawOut[$childId])) {
+                throw ConcurrencyProtocolException::unexpectedObservation(
+                    "子 \"{$childId}\" の stdout と out ファイルの中身が一致しない"
+                );
+            }
+        }
+
+        // 受理条件 3: 守りたい層以外の無効化と DB 座標
+        $expectedCoordinates = ProbeDatabaseCoordinates::fromParentConfig();
+        $observations = [$winnerId => $winner, $loserId => $loser];
+        foreach ($observations as $observation) {
+            $observation->assertAppLocksDisabled();
+            $observation->assertDatabaseCoordinates($expectedCoordinates);
+        }
+
+        $result = new ConcurrentProbeResult(
+            observations: $observations,
+            routeName: $routeName,
+            uri: $uri,
+            idempotencyKey: $idempotencyKey,
+            expectedRequestHash: $expectedRequestHash,
+        );
+
+        // 受理条件 4: 勝者・敗者がちょうど 1:1 に分かれる
+        // 受理条件 5: 勝者・敗者・親の期待値の request_hash が 3 点一致する
+        [$partitionedWinner, $partitionedLoser] = $result->partition();
+        foreach ([$partitionedWinner, $partitionedLoser] as $observation) {
+            if ($observation->requestHash !== $expectedRequestHash) {
+                throw ConcurrencyProtocolException::unexpectedObservation(
+                    '2 子と親の request_hash が 3 点一致しない'
+                );
+            }
+        }
+
+        return $result;
+    }
+
+    /**
+     * 待機中に毎周回呼ぶ中断条件 (締切を待たずに抜ける)。
+     *
+     * ★**二重実行の判定を子の生死より先**に置く。探している退行を「子が死んだ」という
+     *   別の診断で隠さないためである。
+     *
+     * @param  array<string, ProbeProcess>  $processes
+     * @return Closure(): void
+     */
+    private static function abortCondition(ProcessBarrier $barrier, array $processes): Closure
+    {
+        return static function () use ($barrier, $processes): void {
+            // present() は許可集合に無い完成合図があれば拒否する (無視しない)
+            $entered = array_values(array_filter(
+                self::presentValues($barrier),
+                static fn (string $value): bool => str_starts_with($value, 'entered-'),
+            ));
+
+            if (count($entered) >= 2) {
+                throw ConcurrencyProtocolException::doubleExecution($entered);
+            }
+
+            foreach ($processes as $childId => $process) {
+                if ($process->isRunning()) {
+                    continue;
+                }
+
+                // ★停止を観測した**後に**列挙し直す。子は「out を置く」→「終了する」の順で
+                //   動くので、停止の観測より前に取った一覧を使うと、正常に終わった子を
+                //   「観測を出さずに終了した」と誤診する (この順序が load-bearing)。
+                if (in_array('out-'.$childId, self::presentValues($barrier), true)) {
+                    continue;
+                }
+
+                throw ConcurrencyProtocolException::childDiedEarly(
+                    $childId,
+                    $process->exitCode(),
+                    $process->errorOutput(),
+                );
+            }
+        };
+    }
+
+    /**
+     * 現れている完成合図の名前 (未知の完成合図があれば拒否する)。
+     *
+     * @return list<string>
+     */
+    private static function presentValues(ProcessBarrier $barrier): array
+    {
+        return array_map(
+            static fn (SignalName $name): string => $name->value,
+            $barrier->present(SignalName::all()),
+        );
+    }
+
+    /**
+     * `entered` がちょうど 1 子ぶん現れるまで待ち、その child ID を返す。
+     *
+     * @param  array<string, string>  $nonces
+     * @param  Closure(): void  $abort
+     */
+    private static function awaitSingleEntered(
+        ProcessBarrier $barrier,
+        array $nonces,
+        string $goToken,
+        int $workDeadlineNs,
+        Closure $abort,
+    ): string {
+        while (true) {
+            $abort();
+
+            $entered = self::enteredChildren($barrier);
+
+            if (count($entered) === 1) {
+                $childId = $entered[0];
+                $payload = $barrier->await(
+                    SignalName::make('entered', $childId),
+                    self::remainingWorkSeconds($workDeadlineNs),
+                );
+
+                $expected = $nonces[$childId].':'.$goToken;
+                if ($payload !== $expected) {
+                    throw ConcurrencyProtocolException::identityMismatch(
+                        $childId,
+                        'entered の nonce:go_token',
+                        $expected,
+                        $payload,
+                    );
+                }
+
+                return $childId;
+            }
+
+            if (hrtime(true) >= $workDeadlineNs) {
+                throw BarrierTimeoutException::waitingForSingleEntered();
+            }
+
+            usleep(ProcessBarrier::POLL_INTERVAL_MICROSECONDS);
+        }
+    }
+
+    /**
+     * 現れている `entered` の child ID。
+     *
+     * @return list<string>
+     */
+    private static function enteredChildren(ProcessBarrier $barrier): array
+    {
+        $children = [];
+
+        foreach (self::presentValues($barrier) as $value) {
+            if (! str_starts_with($value, 'entered-')) {
+                continue;
+            }
+
+            $children[] = substr($value, strlen('entered-'));
+        }
+
+        return $children;
+    }
+
+    /**
+     * `out` を待って観測へ変換する (生の JSON も返す = stdout との突合に使う)。
+     *
+     * @param  Closure(): void  $abort
+     * @return array{string, ConcurrentProbeObservation}
+     */
+    private static function readObservation(
+        ProcessBarrier $barrier,
+        string $childId,
+        int $workDeadlineNs,
+        Closure $abort,
+    ): array {
+        $json = $barrier->await(
+            SignalName::make('out', $childId),
+            self::remainingWorkSeconds($workDeadlineNs),
+            $abort,
+        );
+
+        $decoded = json_decode($json, true);
+        if ($decoded === null) {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                "子 \"{$childId}\" の観測を JSON として読めない"
+            );
+        }
+
+        return [$json, ConcurrentProbeObservation::fromDecodedJson($decoded)];
+    }
+
+    private static function oppositeChild(string $childId): string
+    {
+        foreach (self::CHILD_IDS as $candidate) {
+            if ($candidate !== $childId) {
+                return $candidate;
+            }
+        }
+
+        throw new RuntimeException("反対側の子が見つからない: {$childId}");
+    }
+
+    /** 作業の残り時間 (絶対 deadline から算出。0 以下なら例外) */
+    private static function remainingWorkSeconds(int $workDeadlineNs): float
+    {
+        $remaining = ($workDeadlineNs - hrtime(true)) / 1_000_000_000;
+
+        if ($remaining <= 0.0) {
+            throw BarrierTimeoutException::workDeadlineExhausted();
+        }
+
+        return $remaining;
+    }
+
+    /**
+     * 回収 (フェーズ単位)。
+     *
+     * | 段 | 内容 |
+     * |---|---|
+     * | 0 | **秘密**(env ファイル・入力ファイル) を回収の成否にかかわらず消す |
+     * | 1 | 生存する全子へ `signalTerminate()` を送る |
+     * | 2 | 単一の reap deadline のうち最大 REAP_GRACE_SECONDS、全子をまとめて poll する |
+     * | 3 | まだ生存する全子へ `signalKill()` を送る (TERM で終わった子には送らない) |
+     * | 4 | reap deadline まで全子をまとめて poll する |
+     * | 5 | 終了を確認できない子があれば、その child ID を含む回収失敗例外にする |
+     *
+     * ★子単位の逐次処理にしない: 「子ごとに TERM → 1 秒待つ → KILL → 残りを待つ」を
+     *   順番にやると 1 子目が予算を使い切った時点で 2 子目に回収時間が残らない。
+     * ★**診断材料は残してよいが秘密は残さない**。停止を確認できない子がいるときに
+     *   workspace ごと消すと、まだ動いている子が削除済みパスへ書き込む。
+     *
+     * @param  array<string, ProbeProcess>  $processes
+     * @param  list<string>  $secretPaths
+     */
+    private static function reap(array $processes, string $workspace, array $secretPaths, ?Throwable $cause): void
+    {
+        // 段 0: **1 件目の失敗で即 throw しない** (抜けると 2 件目の削除が省略され、
+        //       消せたはずの秘密が残る)。全対象を試行してから、失敗をまとめて報告する。
+        $failedSecrets = [];
+        foreach ($secretPaths as $path) {
+            clearstatcache(true, $path);
+            if (! file_exists($path)) {
+                continue;
+            }
+
+            if (! @unlink($path)) {
+                $failedSecrets[] = $path;
+            }
+        }
+
+        $now = hrtime(true);
+        $reapDeadline = $now + (int) (self::REAP_BUDGET_SECONDS * 1_000_000_000);
+        $graceDeadline = min($reapDeadline, $now + (int) (self::REAP_GRACE_SECONDS * 1_000_000_000));
+
+        $alive = self::runningChildren($processes);
+        foreach ($alive as $childId) {
+            $processes[$childId]->signalTerminate();
+        }
+        self::reapPhase($processes, $alive, $graceDeadline);
+
+        $alive = self::runningChildren($processes);
+        foreach ($alive as $childId) {
+            $processes[$childId]->signalKill();
+        }
+        self::reapPhase($processes, $alive, $reapDeadline);
+
+        $remainingChildren = self::runningChildren($processes);
+
+        if ($failedSecrets !== []) {
+            throw ConcurrencyProtocolException::secretsNotRemoved($failedSecrets, $cause);
+        }
+
+        if ($remainingChildren !== []) {
+            self::assertWorkspaceMode($workspace);
+
+            throw ConcurrencyProtocolException::reapIncomplete($remainingChildren, $workspace, $cause);
+        }
+
+        self::removeDirectory($workspace);
+    }
+
+    /**
+     * 1 フェーズぶんの poll と、フェーズ末尾の待機要求。
+     *
+     * ★**単一のループで全子の `isRunning()` を短い間隔で確認する**。
+     *   個々の子へ残り時間いっぱいの blocking wait を順番に行わない
+     *   (それをやると 1 子目が予算を食い切り、フェーズ単位にした意味が消えて逐次処理へ戻る)。
+     *   `waitFor()` は**この poll ループの中では使わない** — フェーズの終わりに 1 回だけ、
+     *   そのフェーズで見張っていた子へ**残り予算**(0 でありうる)で要求する。
+     *
+     * @param  array<string, ProbeProcess>  $processes
+     * @param  list<string>  $watch
+     */
+    private static function reapPhase(array $processes, array $watch, int $phaseDeadlineNs): void
+    {
+        while ($watch !== []) {
+            $stillRunning = false;
+            foreach ($watch as $childId) {
+                if ($processes[$childId]->isRunning()) {
+                    $stillRunning = true;
+                }
+            }
+
+            if (! $stillRunning || hrtime(true) >= $phaseDeadlineNs) {
+                break;
+            }
+
+            usleep(self::REAP_POLL_INTERVAL_MICROSECONDS);
+        }
+
+        $remaining = max(0.0, ($phaseDeadlineNs - hrtime(true)) / 1_000_000_000);
+        foreach ($watch as $childId) {
+            $processes[$childId]->waitFor($remaining);
+        }
+    }
+
+    /**
+     * @param  array<string, ProbeProcess>  $processes
+     * @return list<string>
+     */
+    private static function runningChildren(array $processes): array
+    {
+        $running = [];
+        foreach ($processes as $childId => $process) {
+            if ($process->isRunning()) {
+                $running[] = $childId;
+            }
+        }
+
+        return $running;
+    }
+
+    private static function createWorkspace(): string
+    {
+        $workspace = sys_get_temp_dir().'/concurrency-probe-'.bin2hex(random_bytes(8));
+
+        if (! mkdir($workspace, 0700) || ! is_dir($workspace)) {
+            throw new RuntimeException("実プロセス並行テストの workspace を作れない: {$workspace}");
+        }
+
+        chmod($workspace, 0700);
+        ProcessBarrier::prepareWorkspace($workspace);
+
+        return $workspace;
+    }
+
+    private static function assertWorkspaceMode(string $workspace): void
+    {
+        $mode = ProbeEnvironment::mode($workspace);
+
+        if ($mode !== 0700) {
+            throw ConcurrencyProtocolException::workspaceModeUnsafe($workspace, $mode);
+        }
+    }
+
+    private static function removeDirectory(string $directory): void
+    {
+        if (! is_dir($directory)) {
+            return;
+        }
+
+        foreach (scandir($directory) ?: [] as $entry) {
+            if ($entry === '.' || $entry === '..') {
+                continue;
+            }
+
+            $path = $directory.'/'.$entry;
+            if (is_dir($path)) {
+                self::removeDirectory($path);
+
+                continue;
+            }
+
+            unlink($path);
+        }
+
+        rmdir($directory);
+    }
+}
diff --git a/tests/Support/Concurrency/ConcurrencyProtocolException.php b/tests/Support/Concurrency/ConcurrencyProtocolException.php
new file mode 100644
index 00000000..4f04f9ee
--- /dev/null
+++ b/tests/Support/Concurrency/ConcurrencyProtocolException.php
@@ -0,0 +1,150 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use RuntimeException;
+use Throwable;
+
+/**
+ * 実プロセス並行テストの**プロトコルが破られた**。
+ *
+ * ★{@see BarrierTimeoutException} と型を分けている。とくに {@see self::doubleExecution()} は
+ *   本ハーネスが探している退行そのものなので、締切超過という紛らわしい形では出さない。
+ */
+final class ConcurrencyProtocolException extends RuntimeException
+{
+    /**
+     * 探している退行そのもの: 本処理へ 2 本とも入った。
+     *
+     * @param  list<string>  $enteredSignals
+     */
+    public static function doubleExecution(array $enteredSignals): self
+    {
+        return new self(
+            '本処理へ 2 本とも入った (二重実行を検出): '.implode(',', $enteredSignals)
+        );
+    }
+
+    public static function childDiedEarly(string $childId, ?int $exitCode, string $stderr): self
+    {
+        return new self(sprintf(
+            '子 "%s" が観測を出さずに終了した (exit=%s)。stderr: %s',
+            $childId,
+            $exitCode === null ? 'unknown' : (string) $exitCode,
+            $stderr === '' ? '(なし)' : $stderr,
+        ));
+    }
+
+    public static function identityMismatch(string $childId, string $field, string $expected, string $actual): self
+    {
+        return new self(sprintf(
+            '子 "%s" の同一性が一致しない (%s: 期待 "%s" / 実際 "%s")',
+            $childId,
+            $field,
+            $expected,
+            $actual,
+        ));
+    }
+
+    public static function goTokenMismatch(string $childId, string $expected, string $actual): self
+    {
+        return new self(sprintf(
+            '子 "%s" の go token が一致しない (期待 "%s" / 実際 "%s")。'
+            .'go を読まずに走った可能性がある',
+            $childId,
+            $expected,
+            $actual,
+        ));
+    }
+
+    public static function unexpectedObservation(string $reason): self
+    {
+        return new self('子の観測が受理条件を満たさない: '.$reason);
+    }
+
+    /**
+     * 許可集合に無い完成合図が現れた (無視ではなく拒否する)。
+     *
+     * @param  list<string>  $names
+     */
+    public static function unknownSignal(array $names): self
+    {
+        return new self(
+            '許可集合に無い完成合図がある: '.implode(',', $names)
+        );
+    }
+
+    public static function signalUnreadable(SignalName $name): self
+    {
+        return new self("合図 \"{$name->value}\" は在るのに読めない (観測が成立していない)");
+    }
+
+    public static function signalNotWritten(SignalName $name): self
+    {
+        return new self("合図 \"{$name->value}\" の書きかけを書き切れなかった");
+    }
+
+    public static function signalNotPlaced(SignalName $name): self
+    {
+        return new self(
+            "合図 \"{$name->value}\" を配置できなかった (target は不在。権限 / I/O 障害 / "
+            .'hard link 非対応のいずれか)'
+        );
+    }
+
+    public static function duplicateSignal(SignalName $name): self
+    {
+        return new self("合図 \"{$name->value}\" を 2 回置こうとした (二重送信)");
+    }
+
+    public static function signalDirectoryUnreadable(string $directory): self
+    {
+        return new self("完成合図のディレクトリを列挙できない: {$directory}");
+    }
+
+    /**
+     * 回収を完遂できなかった (停止を確認できない子が残っている)。
+     *
+     * ★元の失敗 ($previous) は畳んで捨てない (回収の失敗が原因を隠さないようにする)。
+     *
+     * @param  list<string>  $childIds
+     */
+    public static function reapIncomplete(
+        array $childIds,
+        string $workspaceDirectory,
+        ?Throwable $previous = null,
+    ): self {
+        return new self(sprintf(
+            '停止を確認できない子が残っている (child=%s)。診断のため workspace を残置した: %s',
+            implode(',', $childIds),
+            $workspaceDirectory,
+        ), previous: $previous);
+    }
+
+    /**
+     * 秘密 (env ファイル / 入力ファイル) を消せなかった。
+     *
+     * ★元の失敗 ($previous) は畳んで捨てない。
+     *
+     * @param  list<string>  $paths
+     */
+    public static function secretsNotRemoved(array $paths, ?Throwable $previous = null): self
+    {
+        return new self(
+            '秘密を含むファイルを削除できなかった (パスから再取得できる状態で残っている): '
+            .implode(',', $paths),
+            previous: $previous,
+        );
+    }
+
+    public static function workspaceModeUnsafe(string $workspaceDirectory, int $mode): self
+    {
+        return new self(sprintf(
+            '残置する workspace の権限が 0700 でない (%s: %04o)',
+            $workspaceDirectory,
+            $mode,
+        ));
+    }
+}
diff --git a/tests/Support/Concurrency/ConcurrentProbeObservation.php b/tests/Support/Concurrency/ConcurrentProbeObservation.php
new file mode 100644
index 00000000..ab39e68b
--- /dev/null
+++ b/tests/Support/Concurrency/ConcurrentProbeObservation.php
@@ -0,0 +1,248 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use App\Enums\ApiErrorCode;
+
+/**
+ * 子プロセス 1 本ぶんの一次観測。
+ *
+ * ★勝者の判定は**行の最終状態ではなくこの一次観測**で行う (正典・家系の作法)。
+ *   行だけを見ると「2 本とも本処理を実行したが後着が上書きした」形と区別がつかない。
+ * ★{@see self::fromDecodedJson()} は **fail-closed**。必須キーの欠落・型違い・**未知キー**の
+ *   いずれでも例外にする (子と親のプロトコル退行を黙って受け入れない)。
+ * ★**キャストで救わない**。整数 cast の飽和で別の値が通る穴を家系が実際に踏んでいる。
+ */
+final readonly class ConcurrentProbeObservation
+{
+    /**
+     * 受理する JSON のキー (deny-by-default。過不足があれば例外)。
+     *
+     * @var list<string>
+     */
+    public const array REQUIRED_KEYS = [
+        // 同一性 (起動時の割り当て・親が出した go token との突合)
+        'child_id', 'nonce', 'go_token',
+        // 何が起きたか (一次観測)
+        'http_status', 'error_code', 'handler_executions', 'entered_handler',
+        // 何を送ったか (2 子が同一要求だったことの証明)
+        'route_name', 'uri', 'request_hash', 'api_key_id',
+        // 守りたい層以外が無効化されていたか (要素 (3))
+        'cache_default', 'cache_store_driver',
+        // どこへ繋いだか (開発 DB 到達の検出)
+        'db_driver', 'db_host', 'db_port', 'db_database', 'db_username', 'db_charset', 'db_sslmode', 'db_url',
+    ];
+
+    private function __construct(
+        public string $childId,
+        public string $nonce,
+        public string $goToken,
+        public int $httpStatus,
+        /** ★勝者は null、敗者は 'idempotency_in_progress' (409 は 3 コードあるので必須) */
+        public ?string $errorCode,
+        public int $handlerExecutions,
+        public bool $enteredHandler,
+        public string $routeName,
+        public string $uri,
+        public string $requestHash,
+        /** ★入力のコピーではなく、認証後の ApiActorContext から観測した値 */
+        public int $apiKeyId,
+        public string $cacheDefault,
+        /** ★既定 store を**裏打ちする driver** (store 名だけでは名前と実体のずれを落とせない) */
+        public string $cacheStoreDriver,
+        public ProbeDatabaseCoordinates $database,
+    ) {}
+
+    /**
+     * @throws ConcurrencyProtocolException 解釈できない観測は通さない
+     */
+    public static function fromDecodedJson(mixed $value): self
+    {
+        if (! is_array($value)) {
+            throw ConcurrencyProtocolException::unexpectedObservation('観測が配列でない');
+        }
+
+        $actual = array_keys($value);
+        sort($actual);
+        $expected = self::REQUIRED_KEYS;
+        sort($expected);
+        if ($actual !== $expected) {
+            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+                'キー集合が一致しない (欠落: %s / 余剰: %s)',
+                implode(',', array_diff($expected, $actual)) ?: '(なし)',
+                implode(',', array_diff($actual, $expected)) ?: '(なし)',
+            ));
+        }
+
+        /** @var array<string, mixed> $value */
+        $childId = self::stringValue($value, 'child_id');
+        $httpStatus = self::intValue($value, 'http_status');
+        if ($httpStatus < 100 || $httpStatus > 599) {
+            throw ConcurrencyProtocolException::unexpectedObservation("http_status が範囲外: {$httpStatus}");
+        }
+
+        $errorCode = $value['error_code'];
+        if ($errorCode !== null && (! is_string($errorCode) || $errorCode === '')) {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                'error_code は null か非空文字列でなければならない (空文字は通さない)'
+            );
+        }
+
+        $handlerExecutions = self::intValue($value, 'handler_executions');
+        if ($handlerExecutions < 0) {
+            throw ConcurrencyProtocolException::unexpectedObservation('handler_executions が負');
+        }
+
+        $enteredHandler = $value['entered_handler'];
+        if (! is_bool($enteredHandler)) {
+            throw ConcurrencyProtocolException::unexpectedObservation('entered_handler が真偽値でない');
+        }
+
+        // ★矛盾する組合せを通さない (true なら >= 1、false なら 0)
+        if ($enteredHandler && $handlerExecutions < 1) {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                'entered_handler=true なのに handler_executions が 0'
+            );
+        }
+        if (! $enteredHandler && $handlerExecutions !== 0) {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                'entered_handler=false なのに handler_executions が 0 でない'
+            );
+        }
+
+        return new self(
+            childId: $childId,
+            nonce: self::stringValue($value, 'nonce'),
+            goToken: self::stringValue($value, 'go_token'),
+            httpStatus: $httpStatus,
+            errorCode: $errorCode,
+            handlerExecutions: $handlerExecutions,
+            enteredHandler: $enteredHandler,
+            routeName: self::stringValue($value, 'route_name'),
+            uri: self::stringValue($value, 'uri'),
+            requestHash: self::stringValue($value, 'request_hash'),
+            apiKeyId: self::intValue($value, 'api_key_id'),
+            cacheDefault: self::stringValue($value, 'cache_default'),
+            cacheStoreDriver: self::stringValue($value, 'cache_store_driver'),
+            database: ProbeDatabaseCoordinates::fromDecodedJson($value),
+        );
+    }
+
+    /** 起動時の割り当て・親が出した go token と食い違ったら通さない */
+    public function assertIdentity(string $childId, string $nonce, string $goToken): void
+    {
+        if ($this->childId !== $childId) {
+            throw ConcurrencyProtocolException::identityMismatch($childId, 'child_id', $childId, $this->childId);
+        }
+
+        if ($this->nonce !== $nonce) {
+            throw ConcurrencyProtocolException::identityMismatch($childId, 'nonce', $nonce, $this->nonce);
+        }
+
+        if ($this->goToken !== $goToken) {
+            throw ConcurrencyProtocolException::goTokenMismatch($childId, $goToken, $this->goToken);
+        }
+    }
+
+    /**
+     * 敗者としての条件 (release の前提)。満たさなければ例外。
+     *
+     * ★`idempotency_conflict` / `idempotency_indeterminate` は通さない。
+     *   409 は 3 コードあり、body 違いの conflict でも「勝者 1 / 敗者 1」は成立して
+     *   **緑になってしまう**ためである。
+     */
+    public function assertLost(string $expectedRequestHash): void
+    {
+        if ($this->httpStatus !== 409) {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                "敗者の応答が 409 でない: {$this->httpStatus}"
+            );
+        }
+
+        if ($this->errorCode !== ApiErrorCode::IdempotencyInProgress->value) {
+            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+                '敗者の error_code が %s でない: %s',
+                ApiErrorCode::IdempotencyInProgress->value,
+                $this->errorCode ?? '(null)',
+            ));
+        }
+
+        if ($this->enteredHandler || $this->handlerExecutions !== 0) {
+            throw ConcurrencyProtocolException::unexpectedObservation('敗者が本処理へ入っている');
+        }
+
+        if ($this->requestHash !== $expectedRequestHash) {
+            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+                '敗者の request_hash が親の期待値と違う (期待 %s / 実際 %s)',
+                $expectedRequestHash,
+                $this->requestHash,
+            ));
+        }
+    }
+
+    /**
+     * 守りたい層以外が無効化されていたか (要素 (3))。
+     *
+     * ★言えるのは「Laravel の既定 cache を経由するプロセス間共有ロックが使えない」までである
+     *   (「アプリ側ロックが 1 つも無い」とは言えない)。
+     * ★**store 名と driver の 2 つ**を見る。名前だけだと「array という名前の store が
+     *   実は別の driver で裏打ちされている」形を落とせない。
+     *   (詳細設計は 2 つ目に `Cache::getDefaultDriver()` を挙げていたが、その戻り値は
+     *   `config('cache.default')` そのもので同じ事実の写しにすぎず、
+     *   かつ cache API を呼ぶと `CachePayloadPlainDataGateTest` の L3 目録への登録が要る。
+     *   採用時債務のファイルを触ることになるため、より強い設定側の観測へ置き換えた)
+     */
+    public function assertAppLocksDisabled(): void
+    {
+        if ($this->cacheDefault !== 'array' || $this->cacheStoreDriver !== 'array') {
+            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+                '子の既定 cache が array に固定できていない (store=%s driver=%s)',
+                $this->cacheDefault,
+                $this->cacheStoreDriver,
+            ));
+        }
+    }
+
+    /** 親が渡した DB 座標と完全一致するか (開発 DB 到達の検出) */
+    public function assertDatabaseCoordinates(ProbeDatabaseCoordinates $expected): void
+    {
+        if ($this->database->equals($expected)) {
+            return;
+        }
+
+        throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+            '子の実効 DB 座標が親と一致しない (親 %s / 子 %s)',
+            $expected->describe(),
+            $this->database->describe(),
+        ));
+    }
+
+    /**
+     * @param  array<string, mixed>  $value
+     */
+    private static function stringValue(array $value, string $key): string
+    {
+        $raw = $value[$key];
+        if (! is_string($raw)) {
+            throw ConcurrencyProtocolException::unexpectedObservation("{$key} が文字列でない");
+        }
+
+        return $raw;
+    }
+
+    /**
+     * @param  array<string, mixed>  $value
+     */
+    private static function intValue(array $value, string $key): int
+    {
+        $raw = $value[$key];
+        // ★`is_int` を要求する。"409" のような数値文字列はキャストで救わない。
+        if (! is_int($raw)) {
+            throw ConcurrencyProtocolException::unexpectedObservation("{$key} が整数でない");
+        }
+
+        return $raw;
+    }
+}
diff --git a/tests/Support/Concurrency/ConcurrentProbeResult.php b/tests/Support/Concurrency/ConcurrentProbeResult.php
new file mode 100644
index 00000000..a03c5450
--- /dev/null
+++ b/tests/Support/Concurrency/ConcurrentProbeResult.php
@@ -0,0 +1,60 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+/**
+ * runner の結果。
+ *
+ * ★nonce / go token は**持たない**。同一性の検査 (`assertIdentity`) は runner の中で
+ *   完結しており、内部プロトコルをテストへ漏らさない。
+ * ★代わりに、行の裏取り (`idempotency_keys` のスコープと request_hash) に要る値だけを渡す。
+ */
+final readonly class ConcurrentProbeResult
+{
+    /**
+     * @param  array<string, ConcurrentProbeObservation>  $observations  childId => 観測
+     */
+    public function __construct(
+        public array $observations,
+        public string $routeName,
+        public string $uri,
+        public string $idempotencyKey,
+        /** 親が middleware と同一規則で計算した期待 hash */
+        public string $expectedRequestHash,
+    ) {}
+
+    /**
+     * `entered_handler` で勝者・敗者に分ける (ちょうど 1:1 でなければ例外)。
+     *
+     * @return array{ConcurrentProbeObservation, ConcurrentProbeObservation} [勝者, 敗者]
+     *
+     * @throws ConcurrencyProtocolException
+     */
+    public function partition(): array
+    {
+        $winners = [];
+        $losers = [];
+
+        foreach ($this->observations as $observation) {
+            if ($observation->enteredHandler) {
+                $winners[] = $observation;
+
+                continue;
+            }
+
+            $losers[] = $observation;
+        }
+
+        if (count($winners) !== 1 || count($losers) !== 1) {
+            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+                '勝者・敗者が 1:1 に分かれない (勝者 %d 件 / 敗者 %d 件)',
+                count($winners),
+                count($losers),
+            ));
+        }
+
+        return [$winners[0], $losers[0]];
+    }
+}
diff --git a/tests/Support/Concurrency/OutOfTransactionFixtures.php b/tests/Support/Concurrency/OutOfTransactionFixtures.php
new file mode 100644
index 00000000..d72b4fc4
--- /dev/null
+++ b/tests/Support/Concurrency/OutOfTransactionFixtures.php
@@ -0,0 +1,208 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use Closure;
+use Illuminate\Database\ConnectionInterface;
+use Illuminate\Support\Facades\DB;
+use RuntimeException;
+use Webmozart\Assert\Assert;
+
+/**
+ * テストの transaction の外に検体を作る (正典 v1 の要素 (2))。
+ *
+ * `RefreshDatabase` が検体を**未コミットの transaction の中**に置くため、子プロセスからは
+ * 見えない。既定接続の設定を**複製した別名接続**を作り、**閉じた区間だけ**既定接続を
+ * そこへ差し替えて生成し、その接続の**明示トランザクションで commit** する。
+ *
+ * ★**片付けは呼び出し側の責任**である。ここで作った行は `RefreshDatabase` の
+ *   rollback では消えない。放置すると同一 worker の後続テストへ漏れる。
+ * ★既定接続の差し替えは**閉じた区間だけ**で、finally で必ず元へ戻す。
+ *   **失敗時は別名接続を disconnect + purge** し、成功時だけ後続の読み取り・cleanup 用に維持する。
+ *
+ * **保証しないもの**: 掃除するのは下の 8 表だけである。検体の生成経路が別の表へ
+ * 行を足すようになったら、この一覧を同じ変更で増やす必要がある
+ * (増やし忘れは {@see self::residueCounts()} では映らない = 8 表の外は見ていない)。
+ */
+final class OutOfTransactionFixtures
+{
+    public const string CONNECTION_NAME = 'concurrency_out_of_transaction';
+
+    /**
+     * 削除と残留検査の対象 (FK 安全な順序。表名 => 絞り込む列)。
+     *
+     * 順序が load-bearing である理由 (FK を全数実読した結果):
+     * - `organizations.laratrust_team_id` は **restrictOnDelete** なので
+     *   「組織を消せば全部消える」は成り立たない。**組織 → teams の順**でなければ削除できない
+     * - `role_user.user_id` には FK が無い (polymorphic) ので、利用者を消しても連鎖しない
+     *   (`teams` 削除の cascade で消える経路に依存する)
+     * - `organizations` は softDeletes を持つので Eloquent の `delete()` では物理削除されない
+     *   (本クラスは query builder で物理削除する)
+     *
+     * @var array<string, string>
+     */
+    private const array CLEANUP_TABLES = [
+        'idempotency_keys' => 'api_key_id',
+        'api_keys' => 'organization_id',
+        'organization_user' => 'organization_id',
+        'custom_teams' => 'organization_id',
+        'organizations' => 'id',
+        'role_user' => 'team_id',
+        'teams' => 'id',
+        'users' => 'id',
+    ];
+
+    /**
+     * 検体を transaction の外へ作る。
+     *
+     * @template T
+     *
+     * @param  Closure(): T  $callback
+     * @return T
+     */
+    public static function create(Closure $callback): mixed
+    {
+        $original = config('database.default');
+        Assert::stringNotEmpty($original);
+        Assert::same($original, 'pgsql', 'このハーネスは pgsql レーンを前提にする');
+
+        self::register($original);
+
+        $succeeded = false;
+        try {
+            config(['database.default' => self::CONNECTION_NAME]);
+            $result = DB::connection(self::CONNECTION_NAME)->transaction($callback);
+            $succeeded = true;
+
+            return $result;
+        } finally {
+            config(['database.default' => $original]);
+
+            // ★失敗したら別名接続を残さない (握ったまま抜けると接続が漏れる)
+            if (! $succeeded) {
+                DB::disconnect(self::CONNECTION_NAME);
+                DB::purge(self::CONNECTION_NAME);
+            }
+        }
+    }
+
+    /** 別名接続で読む (親の裏取り用。既定接続の transaction の中を見に行かない) */
+    public static function connection(): ConnectionInterface
+    {
+        self::ensureRegistered();
+
+        return DB::connection(self::CONNECTION_NAME);
+    }
+
+    /**
+     * 呼び出し側が finally で呼ぶ。冪等 (何度呼んでも安全)。
+     *
+     * ★**削除したあと、自分で残留ゼロを検査する**。呼び出し側のテストだけに任せると、
+     *   見本テストの後始末の完全性が「別のテストが緑であること」に依存してしまう。
+     *   1 行でも残っていれば例外にする (後続テストを汚した状態で静かに通らない)。
+     */
+    public static function cleanup(ConcurrencyFixtureKeys $keys): void
+    {
+        self::ensureRegistered();
+
+        try {
+            self::deleteInForeignKeySafeOrder($keys);
+            self::assertNoResidue($keys);
+        } finally {
+            DB::disconnect(self::CONNECTION_NAME);
+            DB::purge(self::CONNECTION_NAME);
+        }
+    }
+
+    /**
+     * 8 表それぞれの残留件数を返す (表名 => 件数)。
+     *
+     * ★`cleanup()` から切り出して**公開**しているのは、検査器そのものを検査できるようにするため。
+     *   `cleanup()` の中に埋め込むと「削除してから数える」経路でしか叩けず、
+     *   「残留があるのに 0 と数える」退行を検出できない。
+     *
+     * @return array<string, int>
+     */
+    public static function residueCounts(ConcurrencyFixtureKeys $keys): array
+    {
+        $connection = self::connection();
+
+        $counts = [];
+        foreach (self::CLEANUP_TABLES as $table => $column) {
+            $counts[$table] = $connection->table($table)
+                ->where($column, self::keyFor($keys, $table))
+                ->count();
+        }
+
+        return $counts;
+    }
+
+    /** 別名接続を登録する (既定接続設定の**完全な複製**。座標は 1 文字も変えない) */
+    private static function register(string $original): void
+    {
+        $base = config("database.connections.{$original}");
+        Assert::isArray($base);
+
+        config(['database.connections.'.self::CONNECTION_NAME => $base]);
+        DB::purge(self::CONNECTION_NAME);
+    }
+
+    /**
+     * 別名接続の設定が無ければ既定接続から複製する (cleanup / connection の入口で使う)。
+     */
+    private static function ensureRegistered(): void
+    {
+        if (is_array(config('database.connections.'.self::CONNECTION_NAME))) {
+            return;
+        }
+
+        $original = config('database.default');
+        Assert::stringNotEmpty($original);
+        Assert::same($original, 'pgsql', 'このハーネスは pgsql レーンを前提にする');
+
+        self::register($original);
+    }
+
+    private static function deleteInForeignKeySafeOrder(ConcurrencyFixtureKeys $keys): void
+    {
+        $connection = self::connection();
+
+        foreach (self::CLEANUP_TABLES as $table => $column) {
+            // ★`organizations` は softDeletes を持つが、ここは query builder なので物理削除になる。
+            $connection->table($table)
+                ->where($column, self::keyFor($keys, $table))
+                ->delete();
+        }
+    }
+
+    private static function assertNoResidue(ConcurrencyFixtureKeys $keys): void
+    {
+        $residue = array_filter(self::residueCounts($keys), static fn (int $count): bool => $count > 0);
+
+        if ($residue === []) {
+            return;
+        }
+
+        $described = [];
+        foreach ($residue as $table => $count) {
+            $described[] = "{$table}={$count}";
+        }
+
+        throw new RuntimeException(
+            'transaction 外の検体を片付けきれなかった (後続テストを汚す): '.implode(',', $described)
+        );
+    }
+
+    /** 表ごとの絞り込みに使う主キー / 外部キーの値 */
+    private static function keyFor(ConcurrencyFixtureKeys $keys, string $table): int
+    {
+        return match ($table) {
+            'idempotency_keys' => $keys->apiKeyId,
+            'api_keys', 'organization_user', 'custom_teams', 'organizations' => $keys->organizationId,
+            'role_user', 'teams' => $keys->laratrustTeamId,
+            'users' => $keys->userId,
+        };
+    }
+}
diff --git a/tests/Support/Concurrency/ProbeDatabaseCoordinates.php b/tests/Support/Concurrency/ProbeDatabaseCoordinates.php
new file mode 100644
index 00000000..7a7864a7
--- /dev/null
+++ b/tests/Support/Concurrency/ProbeDatabaseCoordinates.php
@@ -0,0 +1,198 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * DB 接続座標 (親の期待値も子の観測も同じ型で持ち、同型どうしで厳密比較する)。
+ *
+ * ★`db_port` は `int`、他は `string` である。`array<string, string>` で持つと
+ *   厳密比較のために暗黙のキャストが要り、「外部観測をキャストで救わない」という
+ *   本設計の方針と矛盾する。
+ */
+final readonly class ProbeDatabaseCoordinates
+{
+    /** 観測 JSON でのキー名 (親子で同じ綴りを使うための唯一の正本) */
+    public const array OBSERVATION_KEYS = [
+        'db_driver', 'db_host', 'db_port', 'db_database',
+        'db_username', 'db_charset', 'db_sslmode', 'db_url',
+    ];
+
+    public function __construct(
+        public string $driver,
+        public string $host,
+        public int $port,
+        public string $database,
+        public string $username,
+        public string $charset,
+        public string $sslmode,
+        /** ★空文字のみ許可 (非空は fail-closed) */
+        public string $url,
+    ) {
+        Assert::same($url, '', 'DB_URL 主体の設定は本ハーネスの前提外である');
+    }
+
+    /**
+     * **実行中のアプリの実接続設定**から作る (信頼済み設定の正規化)。
+     *
+     * 親も子も同じ経路で観測する — 値が違えば「別の DB を向いている」ことがそのまま差になる
+     * (同じ抽出規則で読むからこそ、比較が座標の差だけを映す)。
+     *
+     * ★`config` の port は数値文字列でありうる。**黙ってキャストせず**、
+     *   数値文字列であることと **1〜65535 の範囲**を明示的に検証してから int 化する。
+     *   これは「信頼済みの設定を正規化する」経路であり、外部 JSON とは扱いが違う。
+     */
+    public static function fromParentConfig(): self
+    {
+        Assert::same(config('database.default'), 'pgsql', 'このハーネスは pgsql レーンを前提にする');
+
+        $config = config('database.connections.pgsql');
+        Assert::isArray($config);
+
+        return new self(
+            driver: self::stringValue($config, 'driver'),
+            host: self::stringValue($config, 'host'),
+            port: self::portValue($config['port'] ?? null),
+            database: self::stringValue($config, 'database'),
+            username: self::stringValue($config, 'username'),
+            charset: self::stringValue($config, 'charset'),
+            sslmode: self::stringValue($config, 'sslmode'),
+            url: (string) ($config['url'] ?? ''),
+        );
+    }
+
+    /**
+     * 子側の観測 JSON から作る (**外部入力なので fail-closed**)。
+     *
+     * ★こちらは `is_int()` を要求し、**キャストで救わない**
+     *   (数値文字列 "5432" は通さない。整数 cast の飽和で別の値が通る穴を家系が踏んでいる)。
+     *
+     * @param  array<string, mixed>  $value
+     *
+     * @throws ConcurrencyProtocolException
+     */
+    public static function fromDecodedJson(array $value): self
+    {
+        foreach (self::OBSERVATION_KEYS as $key) {
+            if (! array_key_exists($key, $value)) {
+                throw ConcurrencyProtocolException::unexpectedObservation("DB 座標のキーが欠けている: {$key}");
+            }
+        }
+
+        $port = $value['db_port'];
+        if (! is_int($port)) {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                'db_port が整数でない (数値文字列をキャストで救わない)'
+            );
+        }
+        if ($port < 1 || $port > 65535) {
+            throw ConcurrencyProtocolException::unexpectedObservation("db_port が範囲外: {$port}");
+        }
+
+        $strings = [];
+        foreach (['db_driver', 'db_host', 'db_database', 'db_username', 'db_charset', 'db_sslmode', 'db_url'] as $key) {
+            $raw = $value[$key];
+            if (! is_string($raw)) {
+                throw ConcurrencyProtocolException::unexpectedObservation("{$key} が文字列でない");
+            }
+            $strings[$key] = $raw;
+        }
+
+        if ($strings['db_url'] !== '') {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                'db_url が非空 (DB_URL 主体の設定で起動した子は受理しない)'
+            );
+        }
+
+        return new self(
+            driver: $strings['db_driver'],
+            host: $strings['db_host'],
+            port: $port,
+            database: $strings['db_database'],
+            username: $strings['db_username'],
+            charset: $strings['db_charset'],
+            sslmode: $strings['db_sslmode'],
+            url: $strings['db_url'],
+        );
+    }
+
+    /** 全項目の厳密比較 */
+    public function equals(self $other): bool
+    {
+        return $this->driver === $other->driver
+            && $this->host === $other->host
+            && $this->port === $other->port
+            && $this->database === $other->database
+            && $this->username === $other->username
+            && $this->charset === $other->charset
+            && $this->sslmode === $other->sslmode
+            && $this->url === $other->url;
+    }
+
+    /**
+     * 観測 JSON へ載せる形 (キーの綴りを 1 か所に閉じる)。
+     *
+     * @return array<string, string|int>
+     */
+    public function toObservationValues(): array
+    {
+        return [
+            'db_driver' => $this->driver,
+            'db_host' => $this->host,
+            'db_port' => $this->port,
+            'db_database' => $this->database,
+            'db_username' => $this->username,
+            'db_charset' => $this->charset,
+            'db_sslmode' => $this->sslmode,
+            'db_url' => $this->url,
+        ];
+    }
+
+    /** 人が読める形 (不一致の診断に使う) */
+    public function describe(): string
+    {
+        return sprintf(
+            '%s://%s@%s:%d/%s (charset=%s sslmode=%s url=%s)',
+            $this->driver,
+            $this->username,
+            $this->host,
+            $this->port,
+            $this->database,
+            $this->charset,
+            $this->sslmode,
+            $this->url === '' ? '(空)' : $this->url,
+        );
+    }
+
+    /**
+     * @param  array<string, mixed>  $config
+     */
+    private static function stringValue(array $config, string $key): string
+    {
+        $value = $config[$key] ?? null;
+        Assert::string($value, "database.connections.pgsql.{$key} が文字列でない");
+        Assert::notEmpty($value, "database.connections.pgsql.{$key} が空である");
+
+        return $value;
+    }
+
+    private static function portValue(mixed $port): int
+    {
+        if (is_int($port)) {
+            Assert::range($port, 1, 65535, 'DB port が範囲外である');
+
+            return $port;
+        }
+
+        Assert::string($port, 'DB port が整数でも文字列でもない');
+        Assert::regex($port, '/^[0-9]+$/', 'DB port が数値文字列でない (黙ってキャストしない)');
+
+        $normalized = (int) $port;
+        Assert::range($normalized, 1, 65535, 'DB port が範囲外である');
+
+        return $normalized;
+    }
+}
diff --git a/tests/Support/Concurrency/ProbeEnvironment.php b/tests/Support/Concurrency/ProbeEnvironment.php
new file mode 100644
index 00000000..7d5db285
--- /dev/null
+++ b/tests/Support/Concurrency/ProbeEnvironment.php
@@ -0,0 +1,337 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use RuntimeException;
+use Tests\Support\Ci\TestDatabaseEnv;
+use Tests\Support\ExternalFakes\FakeWiringProbeRunner;
+use Webmozart\Assert\Assert;
+
+/**
+ * 子プロセスの設定の出所を作る (開発 DB への到達遮断の中心)。
+ *
+ * 作法は {@see FakeWiringProbeRunner} の 6 点規約を踏襲する:
+ * `env -i` で環境を作り直す / 専用の一時 env ファイル 1 つだけを設定の出所にする /
+ * ディレクトリ 0700・env ファイル 0600 を起動前に検査して違えば子を起こさない /
+ * 締切つき実行 / 解釈できない子の出力は fail-closed / finally で必ず片付ける。
+ *
+ * ★相手 (`FakeWiringProbeRunner`) は **DB へ接続しないこと**が要件なので DB 座標を渡さない。
+ *   こちらは**接続することが要件**なので、遮断の設計を独自に持つ。
+ *   「似ているから」で共通基底へ寄せない (寄せると DB 遮断が片方の都合で緩む)。
+ * ★**相手と違う判断をした点を黙って作らない**: 相手は APP_KEY / CIPHERSWEET_KEY を
+ *   使い捨てで生成し「一時ファイルは秘密を 1 つも持たない」を達成している。
+ *   こちらは**既存行 (CipherSweet で暗号化された PII) を読む必要がある**ため親の実鍵を渡す。
+ *   そのぶん置き場所を守る (0700 / 0600 / 起動前の権限検査 /
+ *   **回収の成否にかかわらず finally で必ず unlink**)。
+ *
+ * **保証しないもの**: ここが塞ぐのは「子が親のチェックアウトの `.env` / プロセス環境を
+ * 読んで別の DB へ繋ぐ」経路だけである。子が自分でハードコードした座標へ繋ぐ形
+ * (実装ミス) は塞げないので、実効座標の一致は子の段 9 と親の
+ * {@see ConcurrentProbeObservation::assertDatabaseCoordinates()} が別に見る。
+ */
+final class ProbeEnvironment
+{
+    /**
+     * 子の env ファイルへ書いてよいキー (deny-by-default)。
+     *
+     * @var list<string>
+     */
+    public const array ALLOWED_ENV_FILE_KEYS = [
+        'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY', 'BCRYPT_ROUNDS',
+        'DB_CONNECTION', 'DB_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
+        'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET', 'DB_SSLMODE',
+        'CACHE_STORE', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'MAIL_MAILER',
+    ];
+
+    /**
+     * 子へ渡してよい**プロセス環境変数** (`env -i` で空にしたうえでこれだけ載せる)。
+     *
+     * ★この定数は「起動側が載せる分」の宣言であり、**子が実際に受け取った分**は
+     *   子自身が段 6 で観測して突き合わせる (組み立て側の配列を見ても `env -i` の退行は映らない)。
+     *
+     * @var list<string>
+     */
+    public const array ALLOWED_PROCESS_ENV_KEYS = [
+        'CONCURRENCY_PROBE_ENV_DIR',
+        'CONCURRENCY_PROBE_ENV_FILE',
+        // 設定キャッシュを無効化する (存在しない絶対パスを一時ディレクトリ配下に指す)
+        'APP_CONFIG_CACHE',
+    ];
+
+    /** env ファイルの名前 (workspace 内で固定) */
+    public const string ENV_FILE_NAME = '.env.probe';
+
+    /** env ファイルの 1 行を受理する唯一の書式 */
+    private const string ENV_LINE_PATTERN = '/^([A-Z][A-Z0-9_]*)="((?:[^"\\\\]|\\\\.)*)"$/';
+
+    /**
+     * 親の**実行時の実接続設定**から子の env 値を作る。
+     *
+     * ★値の出所は `config('database.connections.pgsql')` であり env の再読解ではない
+     *   (親と子が同じ DB を見ることが構造的に保証される)。
+     * ★`DB_URL` は**空文字で固定**する。キーを消すと子の `.env` 読み込みで復活する。
+     *
+     * @return array<string, string>
+     *
+     * @throws RuntimeException 前提が崩れているとき (子を起こさせない)
+     */
+    public static function envFileValues(): array
+    {
+        Assert::same(config('database.default'), 'pgsql', 'このハーネスは pgsql レーンを前提にする');
+
+        $config = config('database.connections.pgsql');
+        Assert::isArray($config);
+
+        // ★前提検査 1: 親が DB_URL 主体で接続していると、設定配列の host/port/database は
+        //   実効座標とは限らない (URL 解析結果が優先される)。その場合は子を起こさない。
+        $url = $config['url'] ?? null;
+        if ($url !== null && $url !== '') {
+            throw new RuntimeException(
+                'このハーネスは個別キー接続のレーンを前提にする (DB_URL 主体の設定では'
+                .'設定配列の host/port/database が実効座標とは限らないため子を起こさない)'
+            );
+        }
+
+        $coordinates = ProbeDatabaseCoordinates::fromParentConfig();
+
+        // ★前提検査 2: 既存の単一点ガードを**親側でも**通す (allowlist 一致 + dev denylist)。
+        TestDatabaseEnv::assertPgsqlTestDatabaseSafe($coordinates->database);
+
+        $values = [
+            'APP_ENV' => 'testing',
+            'APP_KEY' => self::requiredString(config('app.key'), 'app.key'),
+            'APP_URL' => self::requiredString(config('app.url'), 'app.url'),
+            'APP_DEBUG' => config('app.debug') === true ? 'true' : 'false',
+            'CIPHERSWEET_KEY' => self::requiredString(
+                config('ciphersweet.providers.string.key'),
+                'ciphersweet.providers.string.key',
+            ),
+            // ★このアプリは config/hashing.php を持たない (framework 既定にまかせている) ため、
+            //   親が実際に使っている値の出所はプロセス環境だけである。
+            'BCRYPT_ROUNDS' => (string) (env('BCRYPT_ROUNDS') ?? 12),
+            'DB_CONNECTION' => 'pgsql',
+            'DB_URL' => '',
+            'DB_HOST' => $coordinates->host,
+            'DB_PORT' => (string) $coordinates->port,
+            'DB_DATABASE' => $coordinates->database,
+            'DB_USERNAME' => $coordinates->username,
+            'DB_PASSWORD' => (string) (config('database.connections.pgsql.password') ?? ''),
+            'DB_CHARSET' => $coordinates->charset,
+            'DB_SSLMODE' => $coordinates->sslmode,
+            // 守りたい層以外を無効化する (要素 (3))
+            'CACHE_STORE' => 'array',
+            'QUEUE_CONNECTION' => 'sync',
+            'SESSION_DRIVER' => 'array',
+            'MAIL_MAILER' => 'array',
+        ];
+
+        self::assertEnvFileKeys($values);
+        self::assertNoLineInjection($values);
+
+        return $values;
+    }
+
+    /**
+     * キー集合が許可一覧と**完全一致**することを検査する。
+     *
+     * 「許可外が無い」だけでは足りない — 必須の DB キーが**欠落**した場合、
+     * その穴は子の `.env` 読み込みで埋まりうる (まさに塞ぎたい形)。
+     *
+     * @param  array<string, string>  $values
+     */
+    public static function assertEnvFileKeys(array $values): void
+    {
+        $actual = array_keys($values);
+        $allowed = self::ALLOWED_ENV_FILE_KEYS;
+        sort($actual);
+        sort($allowed);
+
+        Assert::same($actual, $allowed, 'env ファイルのキー集合が許可一覧と一致しない');
+    }
+
+    /**
+     * 値に改行 / CR が入っていたら**書かずに例外**にする。
+     *
+     * env ファイルは 1 行 1 キーなので、値の改行は**別キーの注入**になる。
+     *
+     * @param  array<string, string>  $values
+     */
+    public static function assertNoLineInjection(array $values): void
+    {
+        foreach ($values as $key => $value) {
+            if (preg_match('/[\r\n]/', $value) === 1) {
+                throw new RuntimeException("env 値に改行を含むキーは書けない: {$key}");
+            }
+        }
+    }
+
+    /**
+     * 子が実際に受け取ったプロセス環境のキー集合を検査する (段 6 の純関数)。
+     *
+     * `env -i` の退行で親の `DB_URL` 等が継承されると、phpdotenv は immutable なので
+     * **環境変数が env ファイルより優先**され、遮断を迂回する。
+     *
+     * @param  list<string>  $received
+     *
+     * @throws RuntimeException 許可 3 キーとの完全一致でない
+     */
+    public static function assertProcessEnvironmentKeys(array $received): void
+    {
+        $actual = $received;
+        $allowed = self::ALLOWED_PROCESS_ENV_KEYS;
+        sort($actual);
+        sort($allowed);
+
+        if ($actual === $allowed) {
+            return;
+        }
+
+        throw new RuntimeException(
+            '継承された環境変数がある (env -i の退行): 余剰='
+            .(implode(',', array_diff($actual, $allowed)) ?: '(なし)')
+            .' / 欠落='
+            .(implode(',', array_diff($allowed, $actual)) ?: '(なし)')
+        );
+    }
+
+    /**
+     * env ファイルの 1 行を組み立てる (書式は 1 つだけ)。
+     *
+     * 形式: `KEY="value"` — 値は必ず二重引用符で囲み、**`\` / `"` / `$` の 3 文字**を
+     * バックスラッシュでエスケープする。
+     *
+     * ★`$` をエスケープするのは、**phpdotenv が二重引用符の中で `${VAR}` を変数展開するため**
+     *   である。エスケープしないと、パスワードに `$` が入っていた場合に実効値が変わる
+     *   (子が接続できない、あるいは別の値で接続する)。
+     * ★`#` と空白と空文字は引用符の内側にあるので特別扱いは要らない。
+     * ★子側の厳格パーサ ({@see self::parseEnvFile()}) は**この 1 形式だけ**を受理し、
+     *   同じ規則で復号する。
+     */
+    public static function encodeLine(string $key, string $value): string
+    {
+        $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
+
+        return $key.'="'.$escaped.'"'."\n";
+    }
+
+    /**
+     * 上の書式だけを受理する厳格パーサ (bootstrap 前の検査に使う)。
+     *
+     * ★`loadEnvironmentFrom()` は**その場では解析しない** (起動時に読む場所を指定するだけ)。
+     *   bootstrap 前に DB 名を検査するには自前解析が要る。
+     *
+     * @return array<string, string>
+     *
+     * @throws RuntimeException 受理しない行がある
+     */
+    public static function parseEnvFile(string $path): array
+    {
+        $contents = file_get_contents($path);
+        if ($contents === false) {
+            throw new RuntimeException("子の env ファイルを読めない: {$path}");
+        }
+
+        $values = [];
+        foreach (explode("\n", $contents) as $index => $line) {
+            if ($line === '') {
+                continue;
+            }
+
+            if (preg_match(self::ENV_LINE_PATTERN, $line, $matches) !== 1) {
+                throw new RuntimeException(
+                    '子の env ファイルに受理しない行がある (行 '.($index + 1).')'
+                );
+            }
+
+            $key = $matches[1];
+            if (array_key_exists($key, $values)) {
+                throw new RuntimeException("子の env ファイルにキーが重複している: {$key}");
+            }
+
+            $values[$key] = preg_replace_callback(
+                '/\\\\(.)/',
+                static fn (array $m): string => $m[1],
+                $matches[2],
+            ) ?? '';
+        }
+
+        return $values;
+    }
+
+    /**
+     * 保護されたファイルを作る (作成時点から 0600)。
+     *
+     * `FakeWiringProbeRunner::writeEnvFile()` と同じ手順を踏む:
+     * 1. 一時的に `umask(0o077)` を設定する (**作成時の mode 自体**を 0600 にする)。
+     *    `finally` で必ず元の umask へ復元する
+     * 2. `fopen($path, 'x')` で作る (既存ファイルがあれば失敗 = 乗っ取られた置き場所へ書き足さない)
+     * 3. **秘密を書き込む前に** `chmod($path, 0600)` する
+     *    (umask に依存せず 0600 を確定させる。書いてから絞ると露出が残る)
+     * 4. 書き切れなかった / 閉じられなかったら fail-closed で例外
+     */
+    public static function writeProtectedFile(string $path, string $contents): void
+    {
+        $previousUmask = umask(0o077);
+
+        try {
+            // ★`@` を付けるのは、既存ファイルでの失敗を**自前の fail-closed 例外**で表すため。
+            //   付けないと PHP の警告が先に ErrorException へ化け、診断が「file exists」の
+            //   生メッセージに置き換わって、この経路の意図 (乗っ取られた置き場所へ書き足さない) が読めなくなる。
+            $handle = @fopen($path, 'x');
+            if ($handle === false) {
+                throw new RuntimeException("子へ渡すファイルを作れない (既存 / 権限): {$path}");
+            }
+
+            chmod($path, 0600);
+
+            $written = fwrite($handle, $contents);
+            $closed = fclose($handle);
+
+            if ($written !== strlen($contents) || $closed === false) {
+                throw new RuntimeException("子へ渡すファイルを書き切れなかった: {$path}");
+            }
+        } finally {
+            umask($previousUmask);
+        }
+    }
+
+    /**
+     * ディレクトリ 0700・env ファイル 0600・入力ファイル 0600 でなければ例外 (子を起こさない)。
+     */
+    public static function assertSafePermissions(int $directoryMode, int $envFileMode, int $inputFileMode): void
+    {
+        if ($directoryMode !== 0700 || $envFileMode !== 0600 || $inputFileMode !== 0600) {
+            throw new RuntimeException(sprintf(
+                '子へ渡すファイルの権限が想定と違うため子プロセスを起こさない (dir=%04o env=%04o input=%04o)',
+                $directoryMode,
+                $envFileMode,
+                $inputFileMode,
+            ));
+        }
+    }
+
+    /** パスの permission bits (取得できなければ -1) */
+    public static function mode(string $path): int
+    {
+        clearstatcache(true, $path);
+        $permissions = fileperms($path);
+
+        return $permissions === false ? -1 : ($permissions & 0777);
+    }
+
+    /** 子プロセスの実行スクリプトの絶対パス */
+    public static function probeScriptPath(): string
+    {
+        return __DIR__.'/idempotency-claim-probe.php';
+    }
+
+    private static function requiredString(mixed $value, string $label): string
+    {
+        Assert::string($value, "{$label} が文字列でない");
+        Assert::notEmpty($value, "{$label} が空である");
+
+        return $value;
+    }
+}
diff --git a/tests/Support/Concurrency/ProbeLaunchSpec.php b/tests/Support/Concurrency/ProbeLaunchSpec.php
new file mode 100644
index 00000000..ab7c5b03
--- /dev/null
+++ b/tests/Support/Concurrency/ProbeLaunchSpec.php
@@ -0,0 +1,37 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+/**
+ * 子 1 本の起動仕様 (偽物も同じものを受け取る)。
+ *
+ * ★起動仕様を**値**にしてあるのが、失敗経路の検査で子プロセスを 1 本も起こさずに
+ *   runner の調停と回収を固定できる理由である (偽の {@see ProbeProcessFactory} が
+ *   同じ仕様を受け取り、合図を書く側を演じられる)。
+ */
+final readonly class ProbeLaunchSpec
+{
+    public function __construct(
+        /** 合図・出力・env ファイルの置き場 */
+        public string $workspaceDirectory,
+        public string $childId,
+        public string $nonce,
+        public string $scriptPath,
+        public string $environmentDirectory,
+        public string $environmentFileName,
+        public string $inputFileName,
+        public string $configCachePath,
+    ) {}
+
+    public function inputFilePath(): string
+    {
+        return $this->workspaceDirectory.'/'.$this->inputFileName;
+    }
+
+    public function environmentFilePath(): string
+    {
+        return $this->environmentDirectory.'/'.$this->environmentFileName;
+    }
+}
diff --git a/tests/Support/Concurrency/ProbeProcess.php b/tests/Support/Concurrency/ProbeProcess.php
new file mode 100644
index 00000000..03045f88
--- /dev/null
+++ b/tests/Support/Concurrency/ProbeProcess.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+/**
+ * 子プロセス 1 本の抽象。
+ *
+ * ★**操作を分けている**のは、失敗経路の検査が「runner が停止・強制終了・待機を
+ *   それぞれ要求したこと」を**順序込みで固定できる**ようにするためである。
+ *   1 メソッドに束ねると、検査は「何かを呼んだ」しか言えない。
+ *
+ * **保証の境界**: 失敗経路の検査が主張するのは「runner がこの抽象へ要求すること」までである。
+ * 実 OS プロセスに対するシグナルの実効性は**保証範囲外**とする
+ * (実プロセスを起こすテストを増やすと正典の要素 (6) に反するため踏み込まない)。
+ */
+interface ProbeProcess
+{
+    public function start(): void;
+
+    public function isRunning(): bool;
+
+    public function exitCode(): ?int;
+
+    public function output(): string;
+
+    public function errorOutput(): string;
+
+    /** SIGTERM */
+    public function signalTerminate(): void;
+
+    /** SIGKILL */
+    public function signalKill(): void;
+
+    /**
+     * 上限つきで終了を待ち、終了コードを返す (時間内に終わらなければ null)。
+     *
+     * @param  float  $seconds  0 以上。0 は「1 度だけ状態を確かめる」を意味する
+     */
+    public function waitFor(float $seconds): ?int;
+}
diff --git a/tests/Support/Concurrency/ProbeProcessFactory.php b/tests/Support/Concurrency/ProbeProcessFactory.php
new file mode 100644
index 00000000..1511da9a
--- /dev/null
+++ b/tests/Support/Concurrency/ProbeProcessFactory.php
@@ -0,0 +1,17 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+/**
+ * 子プロセスの作り手。
+ *
+ * 本番経路の実装は {@see SymfonyProbeProcessFactory} ただ 1 本で、
+ * {@see ConcurrencyProbeRunner::run()} は引数が `null` のときだけそれを作る
+ * (偽物を差す注入点と本番経路の分岐を 1 か所に留める)。
+ */
+interface ProbeProcessFactory
+{
+    public function create(ProbeLaunchSpec $spec): ProbeProcess;
+}
diff --git a/tests/Support/Concurrency/ProcessBarrier.php b/tests/Support/Concurrency/ProcessBarrier.php
new file mode 100644
index 00000000..31fcfa6c
--- /dev/null
+++ b/tests/Support/Concurrency/ProcessBarrier.php
@@ -0,0 +1,225 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use Closure;
+use Webmozart\Assert\Assert;
+
+/**
+ * 実プロセス並行テストの合図の待ち合わせ (正典 v1 の要素 (1)(4)(5))。
+ *
+ * 規律 7 点:
+ * 1. ready は**子ごと**に分ける (共有 ready だと片方だけ準備できた状態で go を出せてしまい、
+ *    「全員の準備を確認してから同一の合図で解き放つ」という最重要前提が**緑のまま**壊れる)
+ * 2. 存在だけでなく**中身を照合**する (空・別 child・誤 nonce を通さない。照合は呼び出し側が行う)
+ * 3. 待ちのループでは**毎回 clearstatcache()** する — 捨てないと合図に気付くのが遅れ、
+ *    2 本の実行が重ならず並行テストの意味が消える (正典が名指しする作法)
+ * 4. 締切は**単調時計** (hrtime) で測る (壁時計は補正で戻りうる)
+ * 5. 合図は書きかけ用ディレクトリへ書いてから `link()` で配置する (書きかけを相手に見せない)
+ * 6. 名前は {@see SignalName} でしか作れない (このクラスは string の名前を受け取らないし、
+ *    名前を作る二重入口も持たない)
+ * 7. **同じ合図を 2 回置けない** (`rename()` は既存を上書きするので `link()` を使う。
+ *    ready や out の二重送信が黙って隠れるのを塞ぐ)
+ *
+ * ★**置き場所を 2 つに分ける**: 完成合図は signals/、書きかけは partial/。
+ *   同じディレクトリに置くと、完成ファイルの列挙が書きかけを拾って
+ *   二重実行の判定が壊れる。列挙を安全にするための分離である。
+ * ★読み取りは**注入可能な読み手**越しに行う。`file_get_contents() === false` を
+ *   決定的に再現するためで、権限 (chmod 000) に依存する検査は root 実行で不安定になる。
+ *
+ * **保証しないもの**: 合図の順序関係だけを保証する。実際に処理が重なったかどうかは
+ * 呼び出し側 ({@see ConcurrencyProbeRunner}) が entered / release の 3 段で構成する。
+ */
+final class ProcessBarrier
+{
+    /** 待ちのポーリング間隔 (マイクロ秒) */
+    public const int POLL_INTERVAL_MICROSECONDS = 1_000;
+
+    private readonly ?Closure $reader;
+
+    /**
+     * @param  (callable(string): string|false)|null  $reader  既定は file_get_contents
+     */
+    public function __construct(
+        private readonly string $workspaceDirectory,
+        ?callable $reader = null,
+    ) {
+        Assert::directory($workspaceDirectory);
+        Assert::directory($this->signalDirectory());
+        Assert::directory($this->partialDirectory());
+
+        $this->reader = $reader === null ? null : Closure::fromCallable($reader);
+    }
+
+    /**
+     * 合図の置き場所 (signals/ と partial/) を作る。既に在れば何もしない。
+     */
+    public static function prepareWorkspace(string $workspaceDirectory): void
+    {
+        foreach ([$workspaceDirectory.'/signals', $workspaceDirectory.'/partial'] as $directory) {
+            if (is_dir($directory)) {
+                continue;
+            }
+
+            Assert::true(mkdir($directory, 0700), "合図の置き場所を作れない: {$directory}");
+        }
+    }
+
+    /**
+     * 合図を置く (partial/ へ書いてから signals/ へ配置)。
+     *
+     * ★配置に `rename()` を使わない。POSIX の `rename()` は**既存ファイルを上書きする**ので、
+     *   同じ合図の 2 回目の送信が黙って隠れる (ready や out の二重送信を見逃す)。
+     *   `link()` は **target が既に在れば失敗する**ので、TOCTOU のある `is_file()` 判定を
+     *   挟まずに二重配置を弾ける。同一 FS 内なので hard link が使える。
+     */
+    public function signal(SignalName $name, string $payload): void
+    {
+        $temporary = $this->partialDirectory().'/'.bin2hex(random_bytes(8));
+
+        if (file_put_contents($temporary, $payload) !== strlen($payload)) {
+            @unlink($temporary);
+
+            throw ConcurrencyProtocolException::signalNotWritten($name);
+        }
+
+        try {
+            // 既に在れば false。原子的に「無ければ置く」を実現する。
+            if (@link($temporary, $this->path($name))) {
+                return;
+            }
+
+            // ★失敗の**分類**を target の存在で行う。すべてを二重配置に倒すと、
+            //   権限・I/O 障害・hard link 非対応まで「二重送信を検出した」という
+            //   嘘の診断になる。
+            clearstatcache(true, $this->path($name));
+
+            throw is_file($this->path($name))
+                ? ConcurrencyProtocolException::duplicateSignal($name)
+                : ConcurrencyProtocolException::signalNotPlaced($name);
+        } finally {
+            @unlink($temporary);
+        }
+    }
+
+    /**
+     * 合図が現れるまで待ち、その中身を返す。
+     *
+     * @param  float  $remainingSeconds  呼び出し側が持つ**絶対 deadline** からの残り時間
+     * @param  (callable(): void)|null  $abortIf  待機中に毎周回呼ぶ中断条件
+     *                                            (二重実行の検出・子の異常終了など。
+     *                                            呼び先が例外を投げれば締切を待たずに抜ける)
+     *
+     * @throws BarrierTimeoutException 締切を超えた
+     * @throws ConcurrencyProtocolException 合図はあるのに読めない
+     */
+    public function await(SignalName $name, float $remainingSeconds, ?callable $abortIf = null): string
+    {
+        Assert::greaterThan($remainingSeconds, 0.0);
+
+        $deadline = hrtime(true) + (int) ($remainingSeconds * 1_000_000_000);
+
+        while (true) {
+            if ($abortIf !== null) {
+                $abortIf();
+            }
+
+            // ★毎周回捨てる。捨てないと合図に気付くのが遅れ、2 本の実行が重ならない。
+            clearstatcache(true, $this->path($name));
+
+            if (is_file($this->path($name))) {
+                return $this->read($name);
+            }
+
+            if (hrtime(true) >= $deadline) {
+                throw BarrierTimeoutException::waitingFor($name, $remainingSeconds);
+            }
+
+            usleep(self::POLL_INTERVAL_MICROSECONDS);
+        }
+    }
+
+    /**
+     * 完成合図のディレクトリを**列挙**し、現れている名前を返す。
+     *
+     * ★prefix の glob は採らない。書きかけは別ディレクトリなので、ここでの列挙は
+     *   完成ファイルだけを見る。
+     * ★**許可集合に無い完成ファイルが 1 つでもあれば例外**にする
+     *   (未知の child ID の合図を「無視」ではなく「拒否」にする)。
+     *
+     * @param  list<SignalName>  $allowed  許可される完成合図の全集合
+     * @return list<SignalName> 現れている合図
+     *
+     * @throws ConcurrencyProtocolException 未知の完成ファイルがある
+     */
+    public function present(array $allowed): array
+    {
+        clearstatcache(true, $this->signalDirectory());
+
+        $entries = scandir($this->signalDirectory());
+        if ($entries === false) {
+            throw ConcurrencyProtocolException::signalDirectoryUnreadable($this->signalDirectory());
+        }
+
+        $allowedValues = array_map(static fn (SignalName $name): string => $name->value, $allowed);
+
+        $present = [];
+        $unknown = [];
+
+        foreach ($entries as $entry) {
+            if ($entry === '.' || $entry === '..') {
+                continue;
+            }
+
+            $index = array_search($entry, $allowedValues, true);
+            if ($index === false) {
+                $unknown[] = $entry;
+
+                continue;
+            }
+
+            $present[] = $allowed[$index];
+        }
+
+        if ($unknown !== []) {
+            throw ConcurrencyProtocolException::unknownSignal($unknown);
+        }
+
+        return $present;
+    }
+
+    public function path(SignalName $name): string
+    {
+        return $this->signalDirectory().'/'.$name->value;
+    }
+
+    /**
+     * 合図を読む。**読めない合図は空として通さず例外**にする (fail-closed)。
+     *
+     * 合図はあるのに読めない = 観測が成立していない。空として通すと後続の照合が
+     * 別の理由で落ちて原因が隠れる。
+     */
+    private function read(SignalName $name): string
+    {
+        $reader = $this->reader ?? file_get_contents(...);
+        $contents = $reader($this->path($name));
+
+        if ($contents === false) {
+            throw ConcurrencyProtocolException::signalUnreadable($name);
+        }
+
+        return $contents;
+    }
+
+    private function signalDirectory(): string
+    {
+        return $this->workspaceDirectory.'/signals';
+    }
+
+    private function partialDirectory(): string
+    {
+        return $this->workspaceDirectory.'/partial';
+    }
+}
diff --git a/tests/Support/Concurrency/SignalName.php b/tests/Support/Concurrency/SignalName.php
new file mode 100644
index 00000000..a2693978
--- /dev/null
+++ b/tests/Support/Concurrency/SignalName.php
@@ -0,0 +1,97 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * 合図の名前 (これ以外の形は作れない)。
+ *
+ * ★{@see self::make()} が**唯一の生成口**である ({@see ProcessBarrier} に name() のような
+ *   二重入口は置かない)。`ProcessBarrier` のメソッドはすべて `SignalName` を受け取り
+ *   `string` を受けない。これで `/` や `..` を含む名前は**型の段階で作れない**
+ *   (入口ごとの再検証が要らない)。
+ * ★種別ごとに child ID の要否が違う。`go-a` や `ready` (child ID 無し) のような
+ *   語彙としては正しいがプロトコル上は不正な組合せも作れない。
+ * ★child ID は**実在する 2 つに限定**する (正規表現で 26 文字を許すと `ready-c` が作れてしまい、
+ *   「生成できるのは 8 通りだけ」という保証が実体と食い違う)。
+ *
+ * 生成できるのは次の **8 通りだけ**である:
+ *   go / release / ready-a / ready-b / entered-a / entered-b / out-a / out-b
+ */
+final readonly class SignalName
+{
+    /**
+     * child ID を**取らない**種別 (プロセス全体で 1 つの合図)。
+     *
+     * @var list<string>
+     */
+    public const array GLOBAL_KINDS = ['go', 'release'];
+
+    /**
+     * child ID を**必ず取る**種別 (子ごとの合図)。
+     *
+     * @var list<string>
+     */
+    public const array PER_CHILD_KINDS = ['ready', 'entered', 'out'];
+
+    /**
+     * 実在する child ID (固定 2 本。N 本への一般化はしない)。
+     *
+     * @var list<string>
+     */
+    public const array CHILD_IDS = ['a', 'b'];
+
+    /** @param non-empty-string $value */
+    private function __construct(public string $value) {}
+
+    /**
+     * 唯一の生成口。
+     *
+     * @throws \InvalidArgumentException 種別と child ID の組合せが 8 通りの外
+     */
+    public static function make(string $kind, ?string $childId = null): self
+    {
+        if (in_array($kind, self::GLOBAL_KINDS, true)) {
+            Assert::null($childId, "{$kind} は child ID を取らない");
+
+            return new self($kind);
+        }
+
+        Assert::oneOf($kind, self::PER_CHILD_KINDS);
+        Assert::string($childId, "{$kind} は child ID が必須");
+        // ★正規表現ではなく実在集合で絞る (`ready-c` を作れなくする)
+        Assert::oneOf($childId, self::CHILD_IDS);
+
+        return new self($kind.'-'.$childId);
+    }
+
+    /**
+     * 許可される完成合図の全集合 (未知の完成ファイルの検出に使う)。
+     *
+     * @return list<self> ちょうど 8 件
+     */
+    public static function all(): array
+    {
+        $names = [];
+
+        foreach (self::GLOBAL_KINDS as $kind) {
+            $names[] = self::make($kind);
+        }
+
+        foreach (self::PER_CHILD_KINDS as $kind) {
+            foreach (self::CHILD_IDS as $childId) {
+                $names[] = self::make($kind, $childId);
+            }
+        }
+
+        return $names;
+    }
+
+    public function equals(self $other): bool
+    {
+        return $this->value === $other->value;
+    }
+}
diff --git a/tests/Support/Concurrency/SymfonyProbeProcess.php b/tests/Support/Concurrency/SymfonyProbeProcess.php
new file mode 100644
index 00000000..b72299e6
--- /dev/null
+++ b/tests/Support/Concurrency/SymfonyProbeProcess.php
@@ -0,0 +1,97 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use Symfony\Component\Process\Exception\LogicException;
+use Symfony\Component\Process\Exception\RuntimeException as SymfonyProcessRuntimeException;
+use Symfony\Component\Process\Process;
+use Webmozart\Assert\Assert;
+
+/**
+ * {@see Process} を包む唯一の実装。
+ *
+ * ★**`waitFor()` は Symfony の `wait()` を包まない**。`Process::wait()` は秒数を受け取る
+ *   API ではない (`waitUntil()` は述語を取るがタイムアウトは Process 自身の設定に依る)。
+ *   ここでは **`isRunning()` と単調時計 (`hrtime`) で bounded wait を自前実装する**
+ *   (ポーリング + 上限)。`$seconds` に 0 を渡した場合は 1 度だけ状態を確かめて返す。
+ * ★シグナル送出は生存しているときだけ行う (`Process::signal()` は停止済みに投げると例外)。
+ *   既に止まっている子へ送らないことは回収の契約を弱めない (止まっているのが目的だから)。
+ */
+final class SymfonyProbeProcess implements ProbeProcess
+{
+    /** 終了待ちのポーリング間隔 (マイクロ秒) */
+    private const int POLL_INTERVAL_MICROSECONDS = 1_000;
+
+    public function __construct(private readonly Process $process) {}
+
+    public function start(): void
+    {
+        $this->process->start();
+    }
+
+    public function isRunning(): bool
+    {
+        return $this->process->isRunning();
+    }
+
+    public function exitCode(): ?int
+    {
+        return $this->process->getExitCode();
+    }
+
+    public function output(): string
+    {
+        return $this->process->getOutput();
+    }
+
+    public function errorOutput(): string
+    {
+        return $this->process->getErrorOutput();
+    }
+
+    public function signalTerminate(): void
+    {
+        $this->send(SIGTERM);
+    }
+
+    public function signalKill(): void
+    {
+        $this->send(SIGKILL);
+    }
+
+    public function waitFor(float $seconds): ?int
+    {
+        Assert::greaterThanEq($seconds, 0.0);
+
+        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
+
+        while (true) {
+            if (! $this->process->isRunning()) {
+                return $this->process->getExitCode();
+            }
+
+            if (hrtime(true) >= $deadline) {
+                return null;
+            }
+
+            usleep(self::POLL_INTERVAL_MICROSECONDS);
+        }
+    }
+
+    private function send(int $signal): void
+    {
+        if (! $this->process->isRunning()) {
+            return;
+        }
+
+        try {
+            $this->process->signal($signal);
+        } catch (LogicException|SymfonyProcessRuntimeException) {
+            // 送出と着弾の間に自然終了した / シグナルを送れない環境。
+            // 回収の契約は「要求すること」までなので、ここで落とさない
+            // (停止を確認できなければ後段の判定が失敗させる)。
+        }
+    }
+}
diff --git a/tests/Support/Concurrency/SymfonyProbeProcessFactory.php b/tests/Support/Concurrency/SymfonyProbeProcessFactory.php
new file mode 100644
index 00000000..3fd9a3c8
--- /dev/null
+++ b/tests/Support/Concurrency/SymfonyProbeProcessFactory.php
@@ -0,0 +1,46 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use Symfony\Component\Process\Process;
+
+/**
+ * 実プロセスを起こす唯一の実装。
+ *
+ * 起動コマンドは `env -i` で環境を作り直し、許可 3 キー
+ * ({@see ProbeEnvironment::ALLOWED_PROCESS_ENV_KEYS}) だけを載せる (遮断の段 5)。
+ *
+ * ★秘密 (plain API key / request body) は **argv に載せない** (プロセス一覧から読める)。
+ *   0700 のディレクトリ配下の 0600 の入力ファイルへ置き、そのファイル名だけを argv に載せる。
+ * ★Symfony 側のタイムアウトは無効化する (`null`)。締切は runner が単一の絶対 deadline で
+ *   持っており、2 か所に締切を置くと「どちらで落ちたか」が読めなくなる。
+ */
+final class SymfonyProbeProcessFactory implements ProbeProcessFactory
+{
+    public function __construct(private readonly string $workingDirectory) {}
+
+    public function create(ProbeLaunchSpec $spec): ProbeProcess
+    {
+        $process = new Process(
+            [
+                'env', '-i',
+                'CONCURRENCY_PROBE_ENV_DIR='.$spec->environmentDirectory,
+                'CONCURRENCY_PROBE_ENV_FILE='.$spec->environmentFileName,
+                'APP_CONFIG_CACHE='.$spec->configCachePath,
+                PHP_BINARY,
+                $spec->scriptPath,
+                $spec->workspaceDirectory,
+                $spec->childId,
+                $spec->inputFileName,
+            ],
+            $this->workingDirectory,
+            null,
+            null,
+            null,
+        );
+
+        return new SymfonyProbeProcess($process);
+    }
+}
diff --git a/tests/Support/Concurrency/idempotency-claim-probe.php b/tests/Support/Concurrency/idempotency-claim-probe.php
new file mode 100644
index 00000000..cf9dc7be
--- /dev/null
+++ b/tests/Support/Concurrency/idempotency-claim-probe.php
@@ -0,0 +1,293 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Auth\Context\ApiActorContext;
+use App\Http\Middleware\ResolveApiActor;
+use Illuminate\Contracts\Http\Kernel as HttpKernel;
+use Illuminate\Foundation\Application;
+use Illuminate\Http\JsonResponse;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Route;
+use Tests\Support\Ci\TestDatabaseEnv;
+use Tests\Support\Concurrency\ProbeDatabaseCoordinates;
+use Tests\Support\Concurrency\ProbeEnvironment;
+use Tests\Support\Concurrency\ProcessBarrier;
+use Tests\Support\Concurrency\SignalName;
+use Webmozart\Assert\Assert;
+
+/*
+ * 実プロセス並行テストの子 (正典 v1 の要素 (1))。
+ *
+ * ★責務は 6 つだけ: 受け取った環境を検査する / 設定の出所を固定する /
+ *   起動前に DB 座標を検査する / 起動後に「守りたい層以外の無効化」を検査してから
+ *   準備完了を告げる / 要求を 1 回だけ投げる / 観測を JSON で書く。
+ * ★禁止する文 (echo) を使わないため fwrite(STDOUT, …) で書く (AGENTS.md)。
+ * ★秘密 (plain API key / body) は argv に載せない。0600 の入力ファイルから読む。
+ * ★**マイグレーションを一切実行しない** (スキーマは親のレーンが用意済み)。`RefreshDatabase` も使わない。
+ *
+ * 終了コード:
+ *   0  正常 (観測を stdout と out 合図へ書いた)
+ *   70 継承された環境変数がある (env -i の退行)
+ *   71 既定 cache を array に固定できていない (守りたい層以外を無効化できていない)
+ *   72 実効 DB 座標が宣言と一致しない (二重解釈のずれ / 別 DB への到達)
+ *   73 それ以外の失敗 (stderr に例外を書く)
+ */
+
+require __DIR__.'/../../../vendor/autoload.php';
+
+// ─────────────────────────────────────────────────────────────────────────────
+// [段 6] bootstrap の**前**に、子が実際に受け取ったプロセス環境を検査する。
+//        組み立て側の配列を見ても env -i の退行は映らない (観測できるのは子だけ)。
+//        phpdotenv は immutable なので、環境変数が残っていると env ファイルより優先され、
+//        遮断を迂回する。
+// ─────────────────────────────────────────────────────────────────────────────
+try {
+    $received = getenv();
+    Assert::isArray($received);
+    ProbeEnvironment::assertProcessEnvironmentKeys(array_keys($received));
+} catch (Throwable $e) {
+    fwrite(STDERR, $e->getMessage()."\n");
+
+    exit(70);
+}
+
+try {
+    Assert::count($argv, 4, '引数は workspace / childId / inputFileName の 3 つである');
+    $workspaceDirectory = $argv[1];
+    $childId = $argv[2];
+    $inputFileName = $argv[3];
+
+    $environmentDirectory = getenv('CONCURRENCY_PROBE_ENV_DIR');
+    $environmentFile = getenv('CONCURRENCY_PROBE_ENV_FILE');
+    Assert::stringNotEmpty($environmentDirectory);
+    Assert::stringNotEmpty($environmentFile);
+
+    // ─────────────────────────────────────────────────────────────────────────
+    // [段 7] env ファイルを**自前の厳格パーサ**で解析し、bootstrap 前に DB 名を検査する。
+    //        `loadEnvironmentFrom()` はその場では解析しない (読む場所を指定するだけ) ので、
+    //        bootstrap 前の検査には自前解析が要る。
+    // ─────────────────────────────────────────────────────────────────────────
+    $declaredValues = ProbeEnvironment::parseEnvFile($environmentDirectory.'/'.$environmentFile);
+    ProbeEnvironment::assertEnvFileKeys($declaredValues);
+    TestDatabaseEnv::assertPgsqlTestDatabaseSafe($declaredValues['DB_DATABASE']);
+
+    $input = json_decode((string) file_get_contents($workspaceDirectory.'/'.$inputFileName), true);
+    Assert::isArray($input);
+    Assert::same($input['child_id'] ?? null, $childId, '入力ファイルの child ID が引数と違う');
+    $nonce = $input['nonce'];
+    $routeName = $input['route_name'];
+    $uri = $input['uri'];
+    $rawBody = $input['raw_body'];
+    $idempotencyKey = $input['idempotency_key'];
+    $plainApiKey = $input['plain_api_key'];
+    $timeoutSeconds = $input['timeout_seconds'];
+    Assert::stringNotEmpty($nonce);
+    Assert::stringNotEmpty($routeName);
+    Assert::stringNotEmpty($uri);
+    Assert::string($rawBody);
+    Assert::stringNotEmpty($idempotencyKey);
+    Assert::stringNotEmpty($plainApiKey);
+    // JSON の数値は int / float のどちらにもなりうる (60.0 と 0.2 で型が変わる)。
+    Assert::numeric($timeoutSeconds);
+    $timeoutSeconds = (float) $timeoutSeconds;
+    Assert::greaterThan($timeoutSeconds, 0.0);
+
+    // ★合図の締切は**単調時計**で測る (壁時計は NTP 補正で戻りうる)。
+    $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);
+    $remainingSeconds = static function () use ($deadline): float {
+        $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
+        Assert::greaterThan($remaining, 0.0, '子の締切を使い切った');
+
+        return $remaining;
+    };
+
+    // ─────────────────────────────────────────────────────────────────────────
+    // [段 8] 設定の出所を専用の一時 env ファイル 1 つへ固定してから起動する。
+    //        `APP_CONFIG_CACHE` は一時ディレクトリ配下の**存在しない絶対パス**
+    //        (共有の bootstrap/cache を作らない・消さない)。
+    // ─────────────────────────────────────────────────────────────────────────
+    /** @var Application $app */
+    $app = require __DIR__.'/../../../bootstrap/app.php';
+    Assert::isInstanceOf($app, Application::class);
+
+    $app->useEnvironmentPath($environmentDirectory);
+    $app->loadEnvironmentFrom($environmentFile);
+
+    $httpKernel = $app->make(HttpKernel::class);
+    $httpKernel->bootstrap();
+
+    // ─────────────────────────────────────────────────────────────────────────
+    // [段 9] **ready を出す前に**「守りたい層以外の無効化」と実効 DB 座標を検査する。
+    //        測った後に「実は無効化できていなかった」と分かって赤くなるのでは、
+    //        正典の要素 (3) を満たしたことにならない。
+    // ─────────────────────────────────────────────────────────────────────────
+    // ★**cache API は 1 つも呼ばない**。設定だけを読む形にしてあるのは、
+    //   `tests/Architecture/CachePayloadPlainDataGateTest.php` の L3 目録
+    //   (キャッシュに触れるファイルの exact-fit) へ本スクリプトを登録すると、
+    //   同ファイルが採用時債務 (adoption-debt.tsv) に在るため乖離台帳の 3 択が発生するためである。
+    //   詳細設計は `Cache::getDefaultDriver()` を挙げていたが、その戻り値は vendor 実装上
+    //   `config('cache.default')` そのもの (`CacheManager::getDefaultDriver()`) で**同じ事実の写し**にすぎない。
+    //   代わりに「既定 store を裏打ちする driver」を見る — こちらは
+    //   「array という名前の store が実は別の driver で裏打ちされている」形まで落とせるので**より強い**。
+    $cacheDefault = config('cache.default');
+    Assert::stringNotEmpty($cacheDefault);
+    $cacheStoreDriver = config("cache.stores.{$cacheDefault}.driver");
+
+    if ($cacheDefault !== 'array' || $cacheStoreDriver !== 'array') {
+        fwrite(STDERR, 'cache が array に固定できていない (守りたい層以外を無効化できていない)'."\n");
+
+        exit(71);
+    }
+
+    $effectiveCoordinates = ProbeDatabaseCoordinates::fromParentConfig();
+    Assert::regex($declaredValues['DB_PORT'], '/^[0-9]+$/');
+    $declaredCoordinates = new ProbeDatabaseCoordinates(
+        driver: $declaredValues['DB_CONNECTION'],
+        host: $declaredValues['DB_HOST'],
+        port: (int) $declaredValues['DB_PORT'],
+        database: $declaredValues['DB_DATABASE'],
+        username: $declaredValues['DB_USERNAME'],
+        charset: $declaredValues['DB_CHARSET'],
+        sslmode: $declaredValues['DB_SSLMODE'],
+        url: $declaredValues['DB_URL'],
+    );
+
+    // ★自前パーサの結果と bootstrap 後の実効値の一致まで見る (二重解釈のずれの検出)。
+    if (! $effectiveCoordinates->equals($declaredCoordinates)) {
+        fwrite(STDERR, sprintf(
+            "実効 DB 座標が宣言と一致しない (宣言 %s / 実効 %s)\n",
+            $declaredCoordinates->describe(),
+            $effectiveCoordinates->describe(),
+        ));
+
+        exit(72);
+    }
+
+    $barrier = new ProcessBarrier($workspaceDirectory);
+
+    $handlerExecutions = 0;
+    $goToken = null;
+    $apiKeyId = null;
+
+    /** 認証結果 (ApiActorContext) から api_key_id を観測する (入力のコピーではない) */
+    $observedApiKeyId = static function (Request $request): int {
+        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE_KEY);
+        Assert::isInstanceOf($actor, ApiActorContext::class, '認証後の actor を観測できない');
+        Assert::notNull($actor->apiKey, 'API キー actor でない');
+
+        return $actor->apiKey->id;
+    };
+
+    // probe route を**この子の app インスタンスへ**登録する。
+    // ハンドラは**テスト側コード**なので、アプリコードを 1 バイトも触らずに待たせられる。
+    //
+    // ★middleware 列は「**冪等 middleware の前提を満たす最小 probe 経路**」である。
+    //   本番の順序契約は auth → throttle → resolve.api-actor → api.project-in-org
+    //   → api-key.ability → idempotent → controller だが、throttle を挟むと 2 本の到達が
+    //   乱れて測りたいものと別の分岐になるため入れない。**「本番同等」とは主張しない**。
+    //
+    // ★`$goToken` は**参照キャプチャ**である。closure を定義する時点ではまだ go を待っておらず、
+    //   値キャプチャでは後の代入が反映されない (この closure は go の後にしか実行されないが、
+    //   値キャプチャだと**空文字を合図に書いてしまう**)。
+    //   先頭の Assert は「万一 go より先に handler へ入った」場合に**黙って空を書かず落ちる**ための門である。
+    Route::post($uri, function (Request $request) use (
+        $barrier,
+        $childId,
+        $nonce,
+        &$goToken,
+        &$apiKeyId,
+        $remainingSeconds,
+        &$handlerExecutions,
+        $observedApiKeyId,
+    ): JsonResponse {
+        Assert::stringNotEmpty($goToken);
+        $handlerExecutions++;
+        $apiKeyId = $observedApiKeyId($request);
+
+        // 勝者だけがここへ来る。入ったことを告げ、親の release を待つ。
+        // これで敗者は**勝者の claim 行が processing のまま在る間に必ず claim へ到達する**。
+        $barrier->signal(SignalName::make('entered', $childId), $nonce.':'.$goToken);
+        $barrier->await(SignalName::make('release'), $remainingSeconds());
+
+        return new JsonResponse(['data' => ['ok' => true]], 201);
+    })->middleware(['auth:api-key,api-oauth', 'resolve.api-actor', 'idempotent'])->name($routeName);
+
+    // 準備完了を告げ、go を待つ (起動コストはここまでで払い切る)。
+    $barrier->signal(SignalName::make('ready', $childId), $nonce);
+    $goToken = $barrier->await(SignalName::make('go'), $remainingSeconds());
+    Assert::stringNotEmpty($goToken);
+
+    // 要求を 1 回だけ投げる (実サーバは立てない。プロセス内の実 middleware 列を通す)。
+    //
+    // ★第 3 引数 ($parameters) は**空配列**である。ここへ body を渡すと form parameter として
+    //   扱われ `getContent()` が空になり、middleware が hash する内容が親の期待値と食い違う。
+    //   raw bytes は**第 7 引数 (content)** へ渡す。
+    $probeRequest = Request::create(
+        uri: '/'.$uri,
+        method: 'POST',
+        parameters: [],
+        cookies: [],
+        files: [],
+        server: [
+            'CONTENT_TYPE' => 'application/json',
+            'HTTP_ACCEPT' => 'application/json',
+            'HTTP_AUTHORIZATION' => "Bearer {$plainApiKey}",
+            'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
+        ],
+        content: $rawBody,
+    );
+
+    $response = $httpKernel->handle($probeRequest);
+
+    // 敗者は handler へ入らないので、middleware が置いた attribute から認証結果を取る
+    // (`resolve.api-actor` は `idempotent` より前に走るので、409 の場合も attribute は在る)。
+    $apiKeyId ??= $observedApiKeyId($probeRequest);
+
+    $status = $response->getStatusCode();
+    $errorCode = null;
+    if ($status < 200 || $status >= 300) {
+        $decodedBody = json_decode((string) $response->getContent(), true);
+        $code = is_array($decodedBody) && is_array($decodedBody['error'] ?? null)
+            ? ($decodedBody['error']['code'] ?? null)
+            : null;
+        // ★読めなくても黙って null にしない (親の fail-closed 検査で弾かれる非空文字列を入れる)。
+        $errorCode = is_string($code) && $code !== '' ? $code : 'unreadable_error_body';
+    }
+
+    $observedRouteName = $probeRequest->route()?->getName();
+
+    $json = json_encode([
+        'child_id' => $childId,
+        'nonce' => $nonce,
+        'go_token' => $goToken,
+        'http_status' => $status,
+        'error_code' => $errorCode,
+        'handler_executions' => $handlerExecutions,
+        'entered_handler' => $handlerExecutions > 0,
+        'route_name' => is_string($observedRouteName) && $observedRouteName !== ''
+            ? $observedRouteName
+            : '(unnamed-probe-route)',
+        'uri' => $probeRequest->path(),
+        // ★middleware と同一規則で、**実際に送った Request** から計算する
+        //   (body を form parameter で渡す事故があれば親の期待値と食い違って落ちる)。
+        'request_hash' => hash(
+            'sha256',
+            $probeRequest->method().'|'.$probeRequest->path().'|'.$probeRequest->getContent()
+        ),
+        'api_key_id' => $apiKeyId,
+        'cache_default' => $cacheDefault,
+        'cache_store_driver' => $cacheStoreDriver,
+        ...$effectiveCoordinates->toObservationValues(),
+    ], JSON_THROW_ON_ERROR);
+
+    // 観測を書く。stdout と out ファイルへ**同じ JSON** を出す (親が一致を検査する)。
+    fwrite(STDOUT, $json);
+    $barrier->signal(SignalName::make('out', $childId), $json);
+
+    exit(0);
+} catch (Throwable $e) {
+    fwrite(STDERR, $e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
+
+    exit(73);
+}
diff --git a/tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php b/tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php
new file mode 100644
index 00000000..cc6a6502
--- /dev/null
+++ b/tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php
@@ -0,0 +1,1170 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\ApiErrorCode;
+use Dotenv\Dotenv;
+use Tests\Support\Concurrency\BarrierTimeoutException;
+use Tests\Support\Concurrency\ConcurrencyProbeRunner;
+use Tests\Support\Concurrency\ConcurrencyProtocolException;
+use Tests\Support\Concurrency\ConcurrentProbeObservation;
+use Tests\Support\Concurrency\ConcurrentProbeResult;
+use Tests\Support\Concurrency\ProbeDatabaseCoordinates;
+use Tests\Support\Concurrency\ProbeEnvironment;
+use Tests\Support\Concurrency\ProbeLaunchSpec;
+use Tests\Support\Concurrency\ProbeProcess;
+use Tests\Support\Concurrency\ProbeProcessFactory;
+use Tests\Support\Concurrency\ProcessBarrier;
+use Tests\Support\Concurrency\SignalName;
+use Webmozart\Assert\Assert;
+
+/*
+ * ハーネス自身が**黙って緑になる**壊れ方を塞ぐ検査 (4 群)。
+ *
+ * ★**子プロセスを 1 本も起こさない**。偽の {@see ProbeProcessFactory} を差すか、
+ *   純関数を直接叩く。起動仕様が値 ({@see ProbeLaunchSpec}) になっているから成立する。
+ *
+ * **保証の境界**: 群 4 が主張するのは「runner が {@see ProbeProcess} へ停止・強制終了・待機を
+ * それぞれ要求すること」までである。**実 OS プロセスに対するシグナルの実効性は保証範囲外**
+ * とする (実プロセスを起こすテストを増やすと正典の要素 (6) に反するため踏み込まない)。
+ * 操作を 3 つに分けているのは、この主張を**呼び出し順込みで実際に固定できる**ようにするため。
+ */
+
+// ─────────────────────────────────────────────────────────────────────────────
+// 偽の子プロセス (合図を書く側を演じる)
+// ─────────────────────────────────────────────────────────────────────────────
+
+/** 全子で共有する呼び出し記録 (順序と poll の交互性を固定するため) */
+final class HarnessCallLog
+{
+    /** @var list<array{child: string, op: string}> シグナルと待機だけ (poll は含めない) */
+    public array $operations = [];
+
+    /** @var list<string> `isRunning()` の呼び出し順 (単一ループかどうかを見る) */
+    public array $polls = [];
+
+    public function record(string $childId, string $operation): void
+    {
+        $this->operations[] = ['child' => $childId, 'op' => $operation];
+    }
+
+    public function poll(string $childId): void
+    {
+        $this->polls[] = $childId;
+    }
+
+    public function resetPolls(): void
+    {
+        $this->polls = [];
+    }
+
+    /** @return list<string> */
+    public function operationsFor(string $childId): array
+    {
+        $operations = [];
+        foreach ($this->operations as $entry) {
+            if ($entry['child'] === $childId) {
+                $operations[] = $entry['op'];
+            }
+        }
+
+        return $operations;
+    }
+
+    /** poll 記録が a と b を行き来した回数 (単一ループなら大きく、逐次処理なら 1 になる) */
+    public function pollAlternations(): int
+    {
+        $alternations = 0;
+        for ($i = 1; $i < count($this->polls); $i++) {
+            if ($this->polls[$i] !== $this->polls[$i - 1]) {
+                $alternations++;
+            }
+        }
+
+        return $alternations;
+    }
+}
+
+/**
+ * 台本で動く偽の子。
+ *
+ * ★台本は `isRunning()` の呼び出しごとに 1 歩進む。runner は待機ループの中断条件で
+ *   毎周回 `isRunning()` を呼ぶので、これが「子が動いた」ことの決定的な差し込み点になる
+ *   (実時間に依存しないので締切の検査が安定する)。
+ */
+final class ScriptedProbeProcess implements ProbeProcess
+{
+    public int $step = 0;
+
+    /** @var array<string, mixed> 台本が使う状態 */
+    public array $bag = [];
+
+    private bool $started = false;
+
+    private bool $stopped = false;
+
+    private int $finishedExitCode = 0;
+
+    private string $stdout = '';
+
+    /** @param Closure(self): void $script */
+    public function __construct(
+        public readonly ProbeLaunchSpec $spec,
+        private readonly Closure $script,
+        private readonly HarnessCallLog $log,
+        private readonly bool $ignoreTerminate = false,
+        private readonly bool $ignoreKill = false,
+        private readonly bool $exitImmediately = false,
+        private readonly string $stderr = '',
+    ) {}
+
+    public function barrier(): ProcessBarrier
+    {
+        return new ProcessBarrier($this->spec->workspaceDirectory);
+    }
+
+    /** 台本の終わり: out 合図を置き、stdout を確定して停止する */
+    public function finish(string $outJson, ?string $stdout = null, int $exitCode = 0): void
+    {
+        $this->barrier()->signal(SignalName::make('out', $this->spec->childId), $outJson);
+        $this->stdout = $stdout ?? $outJson;
+        $this->finishedExitCode = $exitCode;
+        $this->stopped = true;
+    }
+
+    public function start(): void
+    {
+        $this->started = true;
+
+        if ($this->exitImmediately) {
+            $this->stopped = true;
+            $this->finishedExitCode = 1;
+        }
+    }
+
+    public function isRunning(): bool
+    {
+        $this->log->poll($this->spec->childId);
+
+        if (! $this->started || $this->stopped) {
+            return false;
+        }
+
+        ($this->script)($this);
+
+        return ! $this->stopped;
+    }
+
+    public function exitCode(): ?int
+    {
+        return $this->stopped ? $this->finishedExitCode : null;
+    }
+
+    public function output(): string
+    {
+        return $this->stdout;
+    }
+
+    public function errorOutput(): string
+    {
+        return $this->stderr;
+    }
+
+    public function signalTerminate(): void
+    {
+        // ★回収の入口で「その時点で go / release が置かれていたか」を記録する。
+        //   workspace は回収の最後に消えるので、ここでしか観測できない。
+        $this->bag['go_at_terminate'] = harnessSignalExists($this->spec, 'go');
+        $this->bag['release_at_terminate'] = harnessSignalExists($this->spec, 'release');
+
+        $this->log->record($this->spec->childId, 'terminate');
+        $this->log->resetPolls();
+
+        if (! $this->ignoreTerminate) {
+            $this->stopped = true;
+        }
+    }
+
+    public function signalKill(): void
+    {
+        $this->log->record($this->spec->childId, 'kill');
+
+        if (! $this->ignoreKill) {
+            $this->stopped = true;
+        }
+    }
+
+    public function waitFor(float $seconds): ?int
+    {
+        Assert::greaterThanEq($seconds, 0.0);
+        $this->log->record($this->spec->childId, 'waitFor');
+
+        return $this->exitCode();
+    }
+}
+
+/** 偽の子を作る (作った子を child ID で引けるようにしておく) */
+final class ScriptedProbeProcessFactory implements ProbeProcessFactory
+{
+    /** @var array<string, ScriptedProbeProcess> */
+    public array $processes = [];
+
+    /** @param Closure(ProbeLaunchSpec, HarnessCallLog): ScriptedProbeProcess $make */
+    public function __construct(
+        private readonly Closure $make,
+        public readonly HarnessCallLog $log = new HarnessCallLog,
+    ) {}
+
+    public function create(ProbeLaunchSpec $spec): ProbeProcess
+    {
+        $process = ($this->make)($spec, $this->log);
+        $this->processes[$spec->childId] = $process;
+
+        return $process;
+    }
+
+    public function workspaceDirectory(): string
+    {
+        foreach ($this->processes as $process) {
+            return $process->spec->workspaceDirectory;
+        }
+
+        throw new RuntimeException('偽の子がまだ 1 本も作られていない');
+    }
+}
+
+// ─────────────────────────────────────────────────────────────────────────────
+// 検査用のちいさな道具
+// ─────────────────────────────────────────────────────────────────────────────
+
+function harnessWorkspace(): string
+{
+    $directory = sys_get_temp_dir().'/harness-'.bin2hex(random_bytes(8));
+    Assert::true(mkdir($directory, 0700));
+    chmod($directory, 0700);
+    ProcessBarrier::prepareWorkspace($directory);
+
+    return $directory;
+}
+
+function harnessRemoveDirectory(string $directory): void
+{
+    if (! is_dir($directory)) {
+        return;
+    }
+
+    foreach (scandir($directory) ?: [] as $entry) {
+        if ($entry === '.' || $entry === '..') {
+            continue;
+        }
+
+        $path = $directory.'/'.$entry;
+        if (is_dir($path)) {
+            harnessRemoveDirectory($path);
+
+            continue;
+        }
+
+        @unlink($path);
+    }
+
+    @rmdir($directory);
+}
+
+function harnessSignalExists(ProbeLaunchSpec $spec, string $name): bool
+{
+    $path = $spec->workspaceDirectory.'/signals/'.$name;
+    clearstatcache(true, $path);
+
+    return is_file($path);
+}
+
+function harnessSignalContents(ProbeLaunchSpec $spec, string $name): string
+{
+    return harnessSignalContentsAt($spec->workspaceDirectory, $name);
+}
+
+function harnessSignalContentsAt(string $workspace, string $name): string
+{
+    return (string) file_get_contents($workspace.'/signals/'.$name);
+}
+
+/** @return array<string, mixed> */
+function harnessInput(ProbeLaunchSpec $spec): array
+{
+    $decoded = json_decode((string) file_get_contents($spec->inputFilePath()), true);
+    Assert::isArray($decoded);
+
+    return $decoded;
+}
+
+/**
+ * 子が返す観測 JSON を組み立てる (親の受理条件をすべて満たす正例)。
+ *
+ * @param  array<string, mixed>  $overrides
+ */
+function harnessObservation(ProbeLaunchSpec $spec, string $goToken, bool $winner, array $overrides = []): string
+{
+    $input = harnessInput($spec);
+    $uri = (string) $input['uri'];
+    $rawBody = (string) $input['raw_body'];
+
+    $values = [
+        'child_id' => $spec->childId,
+        'nonce' => $spec->nonce,
+        'go_token' => $goToken,
+        'http_status' => $winner ? 201 : 409,
+        'error_code' => $winner ? null : ApiErrorCode::IdempotencyInProgress->value,
+        'handler_executions' => $winner ? 1 : 0,
+        'entered_handler' => $winner,
+        'route_name' => (string) $input['route_name'],
+        'uri' => $uri,
+        'request_hash' => hash('sha256', 'POST|'.$uri.'|'.$rawBody),
+        'api_key_id' => 4242,
+        'cache_default' => 'array',
+        'cache_store_driver' => 'array',
+        ...ProbeDatabaseCoordinates::fromParentConfig()->toObservationValues(),
+    ];
+
+    return json_encode([...$values, ...$overrides], JSON_THROW_ON_ERROR);
+}
+
+/**
+ * 正常なプロトコルを演じる台本。
+ *
+ * @param  array<string, mixed>  $observationOverrides
+ * @return Closure(ScriptedProbeProcess): void
+ */
+function harnessProtocolScript(
+    string $winnerId,
+    array $observationOverrides = [],
+    ?string $stdoutOverride = null,
+    int $exitCode = 0,
+): Closure {
+    return static function (ScriptedProbeProcess $process) use (
+        $winnerId,
+        $observationOverrides,
+        $stdoutOverride,
+        $exitCode,
+    ): void {
+        $spec = $process->spec;
+        $isWinner = $spec->childId === $winnerId;
+
+        if ($process->step === 0) {
+            // ★ready を出す時点で go が**まだ無い**ことを記録する
+            //   (go token が ready の検証より前に作られていないことの裏取り)。
+            $process->bag['go_existed_at_ready'] = harnessSignalExists($spec, 'go');
+            // 入力ファイルは回収で消えるので、読んだ内容をここで控える
+            $process->bag['input'] = harnessInput($spec);
+            $process->barrier()->signal(SignalName::make('ready', $spec->childId), $spec->nonce);
+            $process->step = 1;
+
+            return;
+        }
+
+        if ($process->step === 1) {
+            if (! harnessSignalExists($spec, 'go')) {
+                return;
+            }
+
+            $process->bag['go_token'] = harnessSignalContents($spec, 'go');
+            $process->step = $isWinner ? 2 : 3;
+
+            return;
+        }
+
+        $goToken = (string) $process->bag['go_token'];
+
+        if ($process->step === 2) {
+            $process->barrier()->signal(
+                SignalName::make('entered', $spec->childId),
+                $spec->nonce.':'.$goToken,
+            );
+            $process->step = 4;
+
+            return;
+        }
+
+        if ($process->step === 3) {
+            $process->finish(
+                harnessObservation($spec, $goToken, winner: false, overrides: $observationOverrides),
+                exitCode: $exitCode,
+            );
+
+            return;
+        }
+
+        if ($process->step === 4 && harnessSignalExists($spec, 'release')) {
+            $json = harnessObservation($spec, $goToken, winner: true, overrides: $observationOverrides);
+            $process->finish($json, stdout: $stdoutOverride, exitCode: $exitCode);
+        }
+    };
+}
+
+/**
+ * 偽 factory を差して runner を走らせる。
+ *
+ * @param  array<string, mixed>  $requestBody
+ */
+function harnessRun(
+    ScriptedProbeProcessFactory $factory,
+    float $timeoutSeconds = 5.0,
+    array $requestBody = ['title' => '並行 claim の検体'],
+): ConcurrentProbeResult {
+    return ConcurrencyProbeRunner::run(
+        idempotencyKey: 'harness-'.bin2hex(random_bytes(6)),
+        plainApiKey: 'harness-plain-key',
+        requestBody: $requestBody,
+        timeoutSeconds: $timeoutSeconds,
+        factory: $factory,
+    );
+}
+
+// ─────────────────────────────────────────────────────────────────────────────
+// 群 1: ProcessBarrier (合図)
+// ─────────────────────────────────────────────────────────────────────────────
+
+test('群1-1: 現れない合図を待ち続けず締切で例外になる', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        $barrier = new ProcessBarrier($workspace);
+
+        expect(fn () => $barrier->await(SignalName::make('go'), 0.05))
+            ->toThrow(BarrierTimeoutException::class);
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群1-2: 合図はあるのに読めないときは空として通さず落ちる', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        // ★偽の読み手が決定的に false を返す (chmod 000 は root 実行で不安定なので使わない)。
+        $barrier = new ProcessBarrier($workspace, static fn (string $path): string|false => false);
+        $barrier->signal(SignalName::make('go'), 'token');
+
+        expect(fn () => $barrier->await(SignalName::make('go'), 1.0))
+            ->toThrow(ConcurrencyProtocolException::class, '在るのに読めない');
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群1-3: 中断条件が成立したら締切を待たずに抜ける', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        $barrier = new ProcessBarrier($workspace);
+        $startedAt = hrtime(true);
+
+        expect(fn () => $barrier->await(
+            SignalName::make('go'),
+            30.0,
+            static function (): void {
+                throw new RuntimeException('中断条件が成立した');
+            },
+        ))->toThrow(RuntimeException::class, '中断条件が成立した');
+
+        expect((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(5.0);
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群1-4: 書きかけ (partial/) を完成した合図として扱わない', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        file_put_contents($workspace.'/partial/'.bin2hex(random_bytes(8)), 'まだ書きかけ');
+
+        $barrier = new ProcessBarrier($workspace);
+        expect($barrier->present(SignalName::all()))->toBe([]);
+        expect(fn () => $barrier->await(SignalName::make('go'), 0.05))
+            ->toThrow(BarrierTimeoutException::class);
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群1-5: 未知の完成ファイルが置かれたら列挙時に拒否する (無視しない)', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        file_put_contents($workspace.'/signals/entered-c', 'unknown');
+
+        $barrier = new ProcessBarrier($workspace);
+        expect(fn () => $barrier->present(SignalName::all()))
+            ->toThrow(ConcurrencyProtocolException::class, 'entered-c');
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群1-6: global 種別に child ID を付けた合図名は作れない', function (): void {
+    expect(fn () => SignalName::make('go', 'a'))->toThrow(InvalidArgumentException::class);
+    expect(fn () => SignalName::make('release', 'b'))->toThrow(InvalidArgumentException::class);
+});
+
+test('群1-7: child ID 無しの ready / entered / out は作れない', function (): void {
+    foreach (SignalName::PER_CHILD_KINDS as $kind) {
+        expect(fn () => SignalName::make($kind))->toThrow(InvalidArgumentException::class);
+    }
+});
+
+test('群1-8: 実在しない child ID (ready-c / パス片) は作れない — 生成できるのは 8 通りだけ', function (): void {
+    expect(fn () => SignalName::make('ready', 'c'))->toThrow(InvalidArgumentException::class);
+    expect(fn () => SignalName::make('ready', '../outside'))->toThrow(InvalidArgumentException::class);
+    expect(fn () => SignalName::make('ready', 'a/b'))->toThrow(InvalidArgumentException::class);
+    expect(fn () => SignalName::make('unknown-kind', 'a'))->toThrow(InvalidArgumentException::class);
+
+    $values = array_map(static fn (SignalName $name): string => $name->value, SignalName::all());
+    sort($values);
+    expect($values)->toBe([
+        'entered-a', 'entered-b', 'go', 'out-a', 'out-b', 'ready-a', 'ready-b', 'release',
+    ]);
+});
+
+test('群1-9: 同じ合図を 2 回置こうとすると二重送信として失敗する', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        $barrier = new ProcessBarrier($workspace);
+        $barrier->signal(SignalName::make('ready', 'a'), 'nonce-1');
+
+        expect(fn () => $barrier->signal(SignalName::make('ready', 'a'), 'nonce-2'))
+            ->toThrow(ConcurrencyProtocolException::class, '2 回置こうとした');
+
+        // 上書きされていない (最初の中身が残る)
+        expect(harnessSignalContentsAt($workspace, 'ready-a'))->toBe('nonce-1');
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群1-10: target が不在のままの配置失敗は二重送信と誤分類しない', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        // ★ProcessBarrier の構築は signals/ の実在を要求するので、**構築後に**消す。
+        //   これで target が不在のまま配置だけが失敗する形を作れる。
+        $barrier = new ProcessBarrier($workspace);
+        harnessRemoveDirectory($workspace.'/signals');
+
+        expect(fn () => $barrier->signal(SignalName::make('go'), 'token'))
+            ->toThrow(ConcurrencyProtocolException::class, '配置できなかった');
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+// ─────────────────────────────────────────────────────────────────────────────
+// 群 2: ProbeEnvironment (遮断)
+// ─────────────────────────────────────────────────────────────────────────────
+
+test('群2-9: DB_URL が非空なら子を起こさない', function (): void {
+    config(['database.connections.pgsql.url' => 'pgsql://user:pass@db:5432/other']);
+
+    expect(fn () => ProbeEnvironment::envFileValues())
+        ->toThrow(RuntimeException::class, '個別キー接続のレーンを前提にする');
+});
+
+test('群2-10: dev DB 名なら子を起こさない (単一点ガードを親側でも通す)', function (): void {
+    config(['database.connections.pgsql.database' => 'app']);
+
+    expect(fn () => ProbeEnvironment::envFileValues())
+        ->toThrow(InvalidArgumentException::class);
+});
+
+test('群2-11: 許可キー以外を env ファイルへ書かない', function (): void {
+    expect(fn () => ProbeEnvironment::assertEnvFileKeys(['APP_ENV' => 'testing', 'AWS_SECRET_ACCESS_KEY' => 'x']))
+        ->toThrow(InvalidArgumentException::class);
+
+    // ★必須キーの**欠落**も落とす (穴は子の .env 読み込みで埋まりうる = まさに塞ぎたい形)
+    expect(fn () => ProbeEnvironment::assertEnvFileKeys(['APP_ENV' => 'testing']))
+        ->toThrow(InvalidArgumentException::class);
+});
+
+test('群2-12: env 値に改行 / CR があれば書かずに例外 (キー注入の拒否)', function (): void {
+    expect(fn () => ProbeEnvironment::assertNoLineInjection(['DB_PASSWORD' => "pass\nDB_DATABASE=app"]))
+        ->toThrow(RuntimeException::class, '改行を含むキーは書けない');
+
+    expect(fn () => ProbeEnvironment::assertNoLineInjection(['DB_PASSWORD' => "pass\rDB_DATABASE=app"]))
+        ->toThrow(RuntimeException::class, '改行を含むキーは書けない');
+
+    // 正規入力を誤検出しない
+    ProbeEnvironment::assertNoLineInjection(['DB_PASSWORD' => 'p a$s#s"\\']);
+});
+
+test('群2-13: encodeLine の往復は自前パーサと phpdotenv の双方で元の値に戻る', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        // ★`$` / `${NAME}` は二重引用符の中で変数展開されうるので、自前パーサとの往復だけでは
+        //   「phpdotenv が同じ値として読む」ことは言えない。**双方**に通して固定する。
+        $values = [
+            'APP_ENV' => '',
+            'APP_KEY' => 'back\\slash',
+            'APP_URL' => 'quote"inside',
+            'APP_DEBUG' => 'hash#inside',
+            'CIPHERSWEET_KEY' => '  spaced  ',
+            'BCRYPT_ROUNDS' => 'dollar$sign',
+            'DB_PASSWORD' => 'brace${NAME}brace',
+        ];
+
+        $lines = '';
+        foreach ($values as $key => $value) {
+            $lines .= ProbeEnvironment::encodeLine($key, $value);
+        }
+        file_put_contents($workspace.'/.env.roundtrip', $lines);
+
+        expect(ProbeEnvironment::parseEnvFile($workspace.'/.env.roundtrip'))->toBe($values);
+
+        // プロジェクトが実際に使っている phpdotenv の parser でも同じ値になる
+        $loaded = Dotenv::createArrayBacked($workspace, '.env.roundtrip')->load();
+        foreach ($values as $key => $value) {
+            expect($loaded[$key] ?? null)->toBe($value);
+        }
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群2-14: 0700 / 0600 以外の権限では子を起こさない', function (): void {
+    expect(fn () => ProbeEnvironment::assertSafePermissions(0755, 0600, 0600))
+        ->toThrow(RuntimeException::class, '子プロセスを起こさない');
+    expect(fn () => ProbeEnvironment::assertSafePermissions(0700, 0644, 0600))
+        ->toThrow(RuntimeException::class, '子プロセスを起こさない');
+    expect(fn () => ProbeEnvironment::assertSafePermissions(0700, 0600, 0644))
+        ->toThrow(RuntimeException::class, '子プロセスを起こさない');
+
+    ProbeEnvironment::assertSafePermissions(0700, 0600, 0600);
+});
+
+test('群2-15: 保護ファイルは作成時点で 0600 で、既存ファイルがあれば作らない', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        $path = $workspace.'/secret.json';
+        ProbeEnvironment::writeProtectedFile($path, '{"secret":true}');
+
+        expect(ProbeEnvironment::mode($path))->toBe(0600);
+        expect(file_get_contents($path))->toBe('{"secret":true}');
+
+        expect(fn () => ProbeEnvironment::writeProtectedFile($path, 'x'))
+            ->toThrow(RuntimeException::class, '子へ渡すファイルを作れない');
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群2-16: 未知の DB_* / APP_* がプロセス環境に混入していたら拒否する (env -i の退行)', function (): void {
+    expect(fn () => ProbeEnvironment::assertProcessEnvironmentKeys([
+        ...ProbeEnvironment::ALLOWED_PROCESS_ENV_KEYS,
+        'DB_URL',
+    ]))->toThrow(RuntimeException::class, 'env -i の退行');
+
+    expect(fn () => ProbeEnvironment::assertProcessEnvironmentKeys([
+        ...ProbeEnvironment::ALLOWED_PROCESS_ENV_KEYS,
+        'APP_KEY',
+    ]))->toThrow(RuntimeException::class, 'env -i の退行');
+
+    // 欠落も落とす (載せ忘れは設定の出所を欠く)
+    expect(fn () => ProbeEnvironment::assertProcessEnvironmentKeys(['CONCURRENCY_PROBE_ENV_DIR']))
+        ->toThrow(RuntimeException::class, 'env -i の退行');
+
+    ProbeEnvironment::assertProcessEnvironmentKeys(array_reverse(ProbeEnvironment::ALLOWED_PROCESS_ENV_KEYS));
+});
+
+// ─────────────────────────────────────────────────────────────────────────────
+// 群 3: ConcurrentProbeObservation (観測の型)
+// ─────────────────────────────────────────────────────────────────────────────
+
+/**
+ * 受理条件をすべて満たす観測 (群 3 の基準値)。
+ *
+ * @param  array<string, mixed>  $overrides
+ * @return array<string, mixed>
+ */
+function harnessObservationArray(array $overrides = []): array
+{
+    return [
+        'child_id' => 'a',
+        'nonce' => 'nonce-a',
+        'go_token' => 'go-token',
+        'http_status' => 409,
+        'error_code' => ApiErrorCode::IdempotencyInProgress->value,
+        'handler_executions' => 0,
+        'entered_handler' => false,
+        'route_name' => 'api.v1.__probe__',
+        'uri' => 'api/v1/__probe__',
+        'request_hash' => str_repeat('0', 64),
+        'api_key_id' => 7,
+        'cache_default' => 'array',
+        'cache_store_driver' => 'array',
+        'db_driver' => 'pgsql',
+        'db_host' => '127.0.0.1',
+        'db_port' => 5432,
+        'db_database' => 'app_test_deadbeef',
+        'db_username' => 'app',
+        'db_charset' => 'utf8',
+        'db_sslmode' => 'prefer',
+        'db_url' => '',
+        ...$overrides,
+    ];
+}
+
+test('群3-17: 必須キー欠落 / 未知キー / 型違いを通さない (キャストで救わない)', function (): void {
+    $missing = harnessObservationArray();
+    unset($missing['nonce']);
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson($missing))
+        ->toThrow(ConcurrencyProtocolException::class, 'キー集合が一致しない');
+
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['unexpected_key' => 1])
+    ))->toThrow(ConcurrencyProtocolException::class, 'キー集合が一致しない');
+
+    // ★"409" のような数値文字列はキャストで救わない
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['http_status' => '409'])
+    ))->toThrow(ConcurrencyProtocolException::class, 'http_status が整数でない');
+
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['db_port' => '5432'])
+    ))->toThrow(ConcurrencyProtocolException::class, 'db_port が整数でない');
+
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['entered_handler' => 0])
+    ))->toThrow(ConcurrencyProtocolException::class, 'entered_handler が真偽値でない');
+
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson('文字列'))
+        ->toThrow(ConcurrencyProtocolException::class, '観測が配列でない');
+
+    // 正例は通る (拒否だけでなく誤検出しないことも固定する)
+    expect(ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray())->childId)->toBe('a');
+});
+
+test('群3-18: error_code が空文字なら通さない (勝者は null / 敗者は非空)', function (): void {
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['error_code' => ''])
+    ))->toThrow(ConcurrencyProtocolException::class, 'error_code は null か非空文字列');
+
+    $winner = ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray([
+        'error_code' => null,
+        'http_status' => 201,
+        'handler_executions' => 1,
+        'entered_handler' => true,
+    ]));
+    expect($winner->errorCode)->toBeNull();
+});
+
+test('群3-19: entered_handler と handler_executions の矛盾を通さない', function (): void {
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['entered_handler' => true, 'handler_executions' => 0])
+    ))->toThrow(ConcurrencyProtocolException::class, 'handler_executions が 0');
+
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['entered_handler' => false, 'handler_executions' => 1])
+    ))->toThrow(ConcurrencyProtocolException::class, 'handler_executions が 0 でない');
+});
+
+test('群3-20: assertIdentity は childId / nonce / go token の不一致を通さない', function (): void {
+    $observation = ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray());
+
+    expect(fn () => $observation->assertIdentity('b', 'nonce-a', 'go-token'))
+        ->toThrow(ConcurrencyProtocolException::class, 'child_id');
+    expect(fn () => $observation->assertIdentity('a', 'nonce-b', 'go-token'))
+        ->toThrow(ConcurrencyProtocolException::class, 'nonce');
+    expect(fn () => $observation->assertIdentity('a', 'nonce-a', 'another-token'))
+        ->toThrow(ConcurrencyProtocolException::class, 'go token が一致しない');
+
+    $observation->assertIdentity('a', 'nonce-a', 'go-token');
+});
+
+test('群3-21: assertLost は idempotency_conflict / indeterminate を通さない', function (): void {
+    foreach ([ApiErrorCode::IdempotencyConflict, ApiErrorCode::IdempotencyIndeterminate] as $code) {
+        $observation = ConcurrentProbeObservation::fromDecodedJson(
+            harnessObservationArray(['error_code' => $code->value])
+        );
+
+        expect(fn () => $observation->assertLost(str_repeat('0', 64)))
+            ->toThrow(ConcurrencyProtocolException::class, 'error_code');
+    }
+
+    // 409 以外も通さない
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['http_status' => 500])
+    )->assertLost(str_repeat('0', 64)))
+        ->toThrow(ConcurrencyProtocolException::class, '409 でない');
+
+    ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray())
+        ->assertLost(str_repeat('0', 64));
+});
+
+test('群3-22: assertLost は request_hash の不一致を通さない', function (): void {
+    $observation = ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray());
+
+    expect(fn () => $observation->assertLost(str_repeat('f', 64)))
+        ->toThrow(ConcurrencyProtocolException::class, 'request_hash');
+});
+
+test('群3-23: assertDatabaseCoordinates は host / port / username 違いと db_url 非空を通さない', function (): void {
+    $expected = new ProbeDatabaseCoordinates(
+        driver: 'pgsql',
+        host: '127.0.0.1',
+        port: 5432,
+        database: 'app_test_deadbeef',
+        username: 'app',
+        charset: 'utf8',
+        sslmode: 'prefer',
+        url: '',
+    );
+
+    ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray())
+        ->assertDatabaseCoordinates($expected);
+
+    foreach ([
+        ['db_host' => '10.0.0.1'],
+        ['db_port' => 15432],
+        ['db_username' => 'postgres'],
+        ['db_database' => 'app'],
+        ['db_charset' => 'utf8mb4'],
+        ['db_sslmode' => 'disable'],
+    ] as $override) {
+        expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+            harnessObservationArray($override)
+        )->assertDatabaseCoordinates($expected))
+            ->toThrow(ConcurrencyProtocolException::class, '実効 DB 座標が親と一致しない');
+    }
+
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['db_url' => 'pgsql://db/other'])
+    ))->toThrow(ConcurrencyProtocolException::class, 'db_url が非空');
+});
+
+// ─────────────────────────────────────────────────────────────────────────────
+// 群 4: ConcurrencyProbeRunner (調停と回収)
+// ─────────────────────────────────────────────────────────────────────────────
+
+test('群4-25: 正常系 — go token は ready 検証の後に生成され、事前に子へ渡らない', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript('a'), $log),
+    );
+
+    $result = harnessRun($factory);
+
+    expect(array_keys($result->observations))->toHaveCount(2);
+    [$winner, $loser] = $result->partition();
+    expect($winner->enteredHandler)->toBeTrue();
+    expect($loser->errorCode)->toBe(ApiErrorCode::IdempotencyInProgress->value);
+
+    foreach ($factory->processes as $process) {
+        // ★ready を書いた時点で go は存在しなかった (= 検証の後に作られている)
+        expect($process->bag['go_existed_at_ready'])->toBeFalse();
+        // ★入力ファイルにも go token は入っていない (読まずに正しい値は書けない)
+        expect(array_keys(harnessInputSnapshot($process)))->not->toContain('go_token');
+    }
+});
+
+/**
+ * 入力ファイルは回収で消えるので、台本が読んだ内容を控えておく。
+ *
+ * @return array<string, mixed>
+ */
+function harnessInputSnapshot(ScriptedProbeProcess $process): array
+{
+    $snapshot = $process->bag['input'] ?? null;
+    Assert::isArray($snapshot);
+
+    return $snapshot;
+}
+
+test('群4-24: ready の nonce が割り当てと違えば go を出さない', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, static function (ScriptedProbeProcess $process): void {
+            if ($process->step !== 0) {
+                return;
+            }
+            $process->barrier()->signal(
+                SignalName::make('ready', $process->spec->childId),
+                'すり替えられた nonce',
+            );
+            $process->step = 1;
+        }, $log),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 2.0))
+        ->toThrow(ConcurrencyProtocolException::class, 'ready の nonce');
+
+    // 回収の入口 (TERM) の時点で go は 1 度も置かれていない
+    foreach ($factory->processes as $process) {
+        expect($process->bag['go_at_terminate'] ?? null)->toBeFalse();
+    }
+});
+
+test('群4-26: entered が 2 つ出たら締切を待たず二重実行として落ちる', function (): void {
+    // ★両方が勝者を演じる = 探している退行そのもの
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript($spec->childId), $log),
+    );
+
+    $startedAt = hrtime(true);
+    expect(fn () => harnessRun($factory, timeoutSeconds: 20.0))
+        ->toThrow(ConcurrencyProtocolException::class, '二重実行を検出');
+
+    // 締切 (20 秒) を待たずに抜ける
+    expect((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(5.0);
+});
+
+test('群4-27: 未知 child ID の entered が現れたら拒否する', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, static function (ScriptedProbeProcess $process): void {
+            if ($process->step !== 0) {
+                return;
+            }
+            file_put_contents($process->spec->workspaceDirectory.'/signals/entered-c', 'unknown');
+            $process->step = 1;
+        }, $log),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 2.0))
+        ->toThrow(ConcurrencyProtocolException::class, 'entered-c');
+});
+
+test('群4-28: 子が観測を出さずに終わったら観測なしのまま通さない', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            static function (ScriptedProbeProcess $process): void {},
+            $log,
+            exitImmediately: true,
+            stderr: 'fatal: 設定の出所が壊れている',
+        ),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 2.0))
+        ->toThrow(ConcurrencyProtocolException::class, '観測を出さずに終了した');
+});
+
+test('群4-29: 敗者の out が検査を通らなければ release を置かない', function (): void {
+    // ★body 違いの conflict は「勝者 1 / 敗者 1」まで成立して**緑になりうる**形である
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript('a', [
+            'error_code' => ApiErrorCode::IdempotencyConflict->value,
+        ]), $log),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 3.0))
+        ->toThrow(ConcurrencyProtocolException::class, 'error_code');
+
+    // 勝者 (a) は release を待ったまま回収された = release は置かれていない
+    expect($factory->processes['a']->bag['release_at_terminate'] ?? null)->toBeFalse();
+});
+
+test('群4-30: stdout の JSON と out ファイルが不一致なら通さない', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            harnessProtocolScript('a', stdoutOverride: '{"child_id":"a"}'),
+            $log,
+        ),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 3.0))
+        ->toThrow(ConcurrencyProtocolException::class, 'stdout と out ファイルの中身が一致しない');
+});
+
+test('群4-31: exit code が非ゼロなら通さない', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript('a', exitCode: 3), $log),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 3.0))
+        ->toThrow(ConcurrencyProtocolException::class, '終了コードが 0 でない');
+});
+
+test('群4-32: 勝者・敗者が 1:1 に分かれないなら通さない', function (): void {
+    // 勝者側も entered_handler=false と申告する (行だけを見ると気付けない形)
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript('a', [
+            'entered_handler' => false,
+            'handler_executions' => 0,
+            'http_status' => 409,
+            'error_code' => ApiErrorCode::IdempotencyInProgress->value,
+        ]), $log),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 3.0))
+        ->toThrow(ConcurrencyProtocolException::class, '1:1 に分かれない');
+});
+
+test('群4-33: 作業の締切は段ごとに更新されない (3 段待っても総時間が締切を超えない)', function (): void {
+    // ★ready-a を 0.5 秒後、ready-b を 0.9 秒後に出し、entered は永久に出さない。
+    //   単一の絶対 deadline (1.0 秒) なら**合計 1.0 秒**で打ち切られる。
+    //   段ごとに締切を更新する実装だと 0.5 + 0.4 + 1.0 = 1.9 秒かかる。
+    $factory = new ScriptedProbeProcessFactory(
+        static function (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess {
+            $delay = $spec->childId === 'a' ? 0.5 : 0.9;
+
+            return new ScriptedProbeProcess($spec, static function (ScriptedProbeProcess $process) use ($delay): void {
+                $process->bag['started_at'] ??= hrtime(true);
+                if ($process->step !== 0) {
+                    return;
+                }
+                if ((hrtime(true) - $process->bag['started_at']) / 1_000_000_000 < $delay) {
+                    return;
+                }
+                $process->barrier()->signal(SignalName::make('ready', $process->spec->childId), $process->spec->nonce);
+                $process->step = 1;
+            }, $log);
+        },
+    );
+
+    $startedAt = hrtime(true);
+    expect(fn () => harnessRun($factory, timeoutSeconds: 1.0))
+        ->toThrow(BarrierTimeoutException::class);
+
+    expect((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(1.5);
+});
+
+test('群4-34/35: 応答しない子へ TERM → 待機 → KILL → 待機 が順に要求される (締切を使い切った後でも)', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            static function (ScriptedProbeProcess $process): void {},
+            $log,
+            ignoreTerminate: true,
+            ignoreKill: true,
+        ),
+    );
+
+    // 作業の締切をほぼ 0 にしても、回収専用の予算で回収操作は要求される
+    expect(fn () => harnessRun($factory, timeoutSeconds: 0.05))
+        ->toThrow(ConcurrencyProtocolException::class, '停止を確認できない子が残っている');
+
+    foreach (ConcurrencyProbeRunner::CHILD_IDS as $childId) {
+        expect($factory->log->operationsFor($childId))->toBe(['terminate', 'waitFor', 'kill', 'waitFor']);
+    }
+
+    harnessRemoveDirectory($factory->workspaceDirectory());
+});
+
+test('群4-36: 混在ケース — TERM は両方へ / KILL は残った子だけへ / 予算内に収まる', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            static function (ScriptedProbeProcess $process): void {},
+            $log,
+            // b だけ TERM を無視する (KILL では止まる)
+            ignoreTerminate: $spec->childId === 'b',
+        ),
+    );
+
+    $startedAt = hrtime(true);
+    expect(fn () => harnessRun($factory, timeoutSeconds: 0.05))
+        ->toThrow(BarrierTimeoutException::class);
+    $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;
+
+    expect($factory->log->operationsFor('a'))->toBe(['terminate', 'waitFor']);
+    expect($factory->log->operationsFor('b'))->toBe(['terminate', 'waitFor', 'kill', 'waitFor']);
+
+    // ★子単位の逐次処理だと 1 子目で予算を使い切って 2 子目の回収時間が残らない。
+    //   フェーズ単位なら子数にかかわらず予算内に収まる。
+    expect($elapsed)->toBeLessThan(0.05 + ConcurrencyProbeRunner::REAP_BUDGET_SECONDS);
+});
+
+test('群4-37/38/39/41: 停止を確認できない子が残ったら workspace を残し、秘密だけ消す', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            static function (ScriptedProbeProcess $process): void {},
+            $log,
+            ignoreTerminate: true,
+            ignoreKill: true,
+        ),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 0.05))
+        ->toThrow(ConcurrencyProtocolException::class, '停止を確認できない子が残っている');
+
+    $workspace = $factory->workspaceDirectory();
+
+    try {
+        // 37: workspace を削除していない (まだ動いている子が削除済みパスへ書くのを防ぐ)
+        expect(is_dir($workspace))->toBeTrue();
+
+        // 38: 秘密 (env ファイル / 入力ファイル) は回収の成否にかかわらず消えている
+        expect(file_exists($workspace.'/'.ProbeEnvironment::ENV_FILE_NAME))->toBeFalse();
+        foreach ($factory->processes as $process) {
+            expect(file_exists($process->spec->inputFilePath()))->toBeFalse();
+        }
+
+        // 39: 非秘密の診断材料は残っている
+        expect(is_dir($workspace.'/signals'))->toBeTrue();
+
+        // 41: 残置した workspace の mode は 0700
+        expect(ProbeEnvironment::mode($workspace))->toBe(0700);
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群4-40: 秘密ファイルを消せなかったら黙って通らない (パスを明示した例外)', function (): void {
+    $probe = harnessWorkspace();
+    chmod($probe, 0500);
+    $writable = @file_put_contents($probe.'/probe.txt', 'x') !== false;
+    chmod($probe, 0700);
+    harnessRemoveDirectory($probe);
+
+    if ($writable) {
+        // root など、書き込み権限のない置き場でも削除できてしまう実行者では検査できない
+        $this->markTestSkipped('この実行者は 0500 のディレクトリでも削除できるため検査できない');
+    }
+
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, static function (ScriptedProbeProcess $process): void {
+            // 置き場を書き込み不可にして unlink を失敗させる
+            chmod($process->spec->workspaceDirectory, 0500);
+            $process->step = 1;
+        }, $log),
+    );
+
+    $thrown = null;
+    try {
+        harnessRun($factory, timeoutSeconds: 0.1);
+    } catch (Throwable $e) {
+        $thrown = $e;
+    }
+
+    $workspace = $factory->workspaceDirectory();
+    chmod($workspace, 0700);
+
+    try {
+        expect($thrown)->toBeInstanceOf(ConcurrencyProtocolException::class);
+        expect($thrown?->getMessage())->toContain('秘密を含むファイルを削除できなかった');
+        expect($thrown?->getMessage())->toContain(ProbeEnvironment::ENV_FILE_NAME);
+        // ★元の失敗は畳んで捨てない
+        expect($thrown?->getPrevious())->not->toBeNull();
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群4-42: 回収の poll は単一ループで全子を確認する (逐次の blocking wait ではない)', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            static function (ScriptedProbeProcess $process): void {},
+            $log,
+            ignoreTerminate: true,
+        ),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 0.05))
+        ->toThrow(BarrierTimeoutException::class);
+
+    // TERM 送出で poll 記録が初期化されるので、ここに残るのは回収フェーズの poll だけ。
+    // 逐次の blocking wait なら「a を延々 poll → b を延々 poll」で行き来は 1 回しか起きない。
+    expect($factory->log->pollAlternations())->toBeGreaterThan(10);
+});

```

```
