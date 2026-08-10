# T146 実装レビュー依頼 (one-shot)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
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

Laravel + Svelte アプリの実装レビュアー。以下の観点でレビューせよ:
設計との一致性 / 正確性 / PHPStan 適合性 / DTO パターン / テスト網羅性 (空振り検知・負のコントロール) / セキュリティ / 運用ドキュメントとの整合。
本 diff は **UI を含まない** (Console command + テスト + docs のみ) ので DESIGN.md / Atomic Design 観点は対象外。

出力形式: ファイルごとに判定 → [Critical] / [Warning] / [Suggestion] で分類 → 最後に全体判定 (APPROVED または CHANGES_REQUESTED)。

## user: 変更の背景と差分

### 修正対象の欠陥 (実測済み)

`php artisan billing:purge-retention-expired` の horizon 判定 (人間が読む 1 行) が
`$remaining === 0` **だけ**を見ていた。dev で dry-run したところ (dev DB が未 migrate で)
7 target すべてが QueryException で失敗し、それでも出力は以下だった:

```
[dry-run] 保持期間 7 年 / 閾値 2019-08-10 04:25:48 以前の起算日時が期限超過
失敗 target=stripe_webhook_event (RuntimeException)
  stripe_webhook_event: expired=0 processed=0 fail_closed=0 unexpected_failures=1 remaining=0
  ... (以下 6 target)
合計: 決着 0 件 / 残存 (期限超過) 0 件 / fail-closed 0 件
horizon: OK (期限超過 0 件)          ← 嘘 (fail-open)
dry-run のため 1 行も変更していません (--apply で実処理)。
想定外の失敗がある target があります (件数は不明として扱ってください)。
```

`remaining=0` は「期限超過が無い」ではなく「**クエリが失敗して数えられなかった**」結果。
終了コードは FAILURE なので機械は気づけるが、設計上「人手に残すのは horizon 0 の確認だけ」
(規約文面公開 PR-C3 の唯一の歯止め) とした、その人間向けの行が嘘をつく。

### 修正方針 (この 1 点に絞る。ついでのリファクタはしない)

`unexpected_failures > 0` の target が 1 つでもあれば horizon を **OK と読めない**表現にする。
実装では OK / NG / 判定不能 の 3 値にし、失敗があれば `判定不能` を優先させた
(NG より判定不能が悪い = 何件残っているかも分からないため)。

### 実装差分 (git diff)

```diff
diff --git a/app/Console/Commands/Billing/PurgeBillingRetentionCommand.php b/app/Console/Commands/Billing/PurgeBillingRetentionCommand.php
index 2658c70..8de89c4 100644
--- a/app/Console/Commands/Billing/PurgeBillingRetentionCommand.php
+++ b/app/Console/Commands/Billing/PurgeBillingRetentionCommand.php
@@ -25,6 +25,8 @@
  *
  * ★**horizon (期限超過が残っているか) を出力で観測できる**こと。規約文面の公開 (PR-C3) の
  *   前提条件は「分類を問わず期限超過 0 件」であり、その確認はこのコマンドの出力で行う。
+ *   horizon は **OK / NG / 判定不能** の 3 値である。想定外失敗があった target の件数は
+ *   「数えられなかった」ので 0 で報告されるため、その 0 を根拠に OK と言ってはならない。
  *
  * ★終了コードは 2 分類 — 想定外失敗があれば FAILURE。**`failClosed` が残っていても
  *   SUCCESS である** (安全に残した = 異常ではない)。ただしそれは「規約を満たした」ではない
@@ -117,19 +119,34 @@ private function report(array $results, bool $apply): int
             $failClosed,
         ));
 
+        $failed = array_filter(
+            $results,
+            static fn (BillingRetentionPurgeResultDto $result): bool => $result->hasUnexpectedFailures(),
+        );
+
         // horizon の観測点。C3 の前提条件は「分類を問わず 0 件」である。
-        $this->line($remaining === 0
-            ? 'horizon: OK (期限超過 0 件)'
-            : "horizon: NG (期限超過 {$remaining} 件が残存。fail-closed も残存に数える)");
+        //
+        // ★失敗した target が 1 つでもあれば **OK とも NG とも言えない**。失敗した target の
+        //   件数は「数えられなかった」ので 0 で報告されており、その 0 を合計に足した
+        //   `$remaining === 0` は「期限超過が無い」ことを意味しない (fail-open)。
+        //   終了コードは FAILURE になるが、この行を読む人間 (C3 の唯一の歯止め) には
+        //   「確認できていない」ことが伝わらなければならない。
+        if ($failed !== []) {
+            $this->error(sprintf(
+                'horizon: 判定不能 (集計に失敗した target が %d 件。観測できた期限超過は %d 件だが、実数は不明)',
+                count($failed),
+                $remaining,
+            ));
+        } else {
+            $this->line($remaining === 0
+                ? 'horizon: OK (期限超過 0 件)'
+                : "horizon: NG (期限超過 {$remaining} 件が残存。fail-closed も残存に数える)");
+        }
 
         if (! $apply) {
             $this->comment('dry-run のため 1 行も変更していません (--apply で実処理)。');
         }
 
-        $failed = array_filter(
-            $results,
-            static fn (BillingRetentionPurgeResultDto $result): bool => $result->hasUnexpectedFailures(),
-        );
         if ($failed !== []) {
             $this->error('想定外の失敗がある target があります (件数は不明として扱ってください)。');
 
diff --git a/docs/architecture.md b/docs/architecture.md
index 4207d3f..349a5f3 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -1506,8 +1506,10 @@ ## 課金記録の保持期間 (7 年) の決着 (T143 / T144)
   ある日その経路だけが静かに壊れる。目録は読み方 (`aggregate` / `row_detail` / `other_table`)
   の宣言を強制する。
 - **監視対象**: 本コマンドの終了コード (`unexpected_failures > 0` で `FAILURE`) と、
-  出力の `horizon:` 行。**`fail_closed` は「安全に残した」であって「規約を満たした」ではない**
-  ので、`horizon: NG` の継続と `fail_closed` の増加を正常成功として扱わない。
+  出力の `horizon:` 行 (**OK / NG / 判定不能** の 3 値)。**`fail_closed` は「安全に残した」であって
+  「規約を満たした」ではない**ので、`horizon: NG` の継続と `fail_closed` の増加を正常成功として
+  扱わない。想定外失敗があった target の件数は**数えられなかったので 0 で報告される**ため、
+  失敗が 1 件でもあれば horizon は `判定不能` になる (その 0 を根拠に OK と言わない)。
 - **保証しないもの (誇張しない)**: 目録 (`BillingRetentionTarget` /
   `BillingRetentionExclusion`) は**人間の申告**であり、課金取引の記録が
   `app/Models/Billing/` の外や Eloquent を経由しない表に置かれれば gate は沈黙する。
diff --git a/docs/billing-retention-runbook.md b/docs/billing-retention-runbook.md
index 9fc3283..cdc95cb 100644
--- a/docs/billing-retention-runbook.md
+++ b/docs/billing-retention-runbook.md
@@ -39,7 +39,15 @@ ## 2. 出力の読み方
 | `fail_closed` | **安全のため残した**件数。(a) 起算列が null で補助時計が古い異常、(b) 参照中で消せないもの |
 | `unexpected_failures` | 想定外の失敗。**件数の 0 は信用できない**という印 |
 | `remaining` | 決着後に残った期限超過の件数 |
-| `horizon:` | **規約 (最長 7 年) を満たしているか**の観測点。`remaining` の合計が 0 なら OK |
+| `horizon:` | **規約 (最長 7 年) を満たしているか**の観測点。**OK / NG / 判定不能** の 3 値 (下記) |
+
+`horizon:` の 3 値:
+
+| 値 | 条件 | 意味 |
+|---|---|---|
+| `OK` | 失敗 0 件 かつ `remaining` 合計 0 | 規約を満たしている |
+| `NG` | 失敗 0 件 かつ `remaining` 合計 > 0 | 期限超過が残っている (§5) |
+| `判定不能` | `unexpected_failures > 0` の target が 1 件でもある | **満たしているか確認できていない**。失敗した target の件数は数えられず 0 で報告されるため、`remaining` 合計 0 を根拠に OK と読んではならない |
 
 - **出力に PII は出さない** (organization id / メールアドレス / 金額 / Stripe 識別子を載せない)。
   調査で個別の行に降りる必要が出たら、コマンドの出力ではなく DB を直接見ること。
@@ -124,7 +132,10 @@ ## 6. 監視対象
 **本コマンドの終了コードと出力の `horizon:` 行**を監視対象に登録する。
 
 - `FAILURE` (= `unexpected_failures > 0`) … 件数報告そのものが信用できない状態。即調査
+  (このとき `horizon:` は `判定不能` になる。**`OK` は出ない**)
 - `horizon: NG` が**継続** … 規約 (/privacy が宣言する最長 7 年) を満たせていない状態
+- `horizon: 判定不能` … 規約を満たしているか**確認できていない**状態。`NG` と同等以上に扱う
+  (「満たしていないと分かっている」より悪い = 何件残っているかも分からない)
 - `fail_closed` の**継続・増加** … 正常成功として扱わない (§5)
 
 ## 7. 台帳の畳み込みで**失われるもの** (誇張しない)
diff --git a/tests/Feature/Billing/BillingRetentionPurgeTest.php b/tests/Feature/Billing/BillingRetentionPurgeTest.php
index a9fc165..4f6387d 100644
--- a/tests/Feature/Billing/BillingRetentionPurgeTest.php
+++ b/tests/Feature/Billing/BillingRetentionPurgeTest.php
@@ -2,6 +2,7 @@
 
 declare(strict_types=1);
 
+use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
 use App\Enums\Billing\BillingRetentionTarget;
 use App\Models\Billing\StripeWebhookEvent;
 use App\Models\Billing\Subscription;
@@ -271,6 +272,90 @@
     expect(Subscription::query()->count())->toBe(1);
 });
 
+/*
+ * horizon の fail-open 是正 (T146)。
+ *
+ * `remaining=0` は「期限超過が無い」とは限らない。**集計クエリ自体が失敗して数えられなかった**
+ * ときも 0 になる (コマンドは件数不明を 0 で報告し `unexpected_failures` を立てる)。
+ * 終了コードは FAILURE になるので機械は気づけるが、**人間が読む horizon 行が「OK」と嘘をつく**と
+ * PR-C3 (規約文面の公開) の唯一の歯止めが外れる。よって失敗が 1 件でもあれば
+ * horizon は **OK と読めない**表現でなければならない。
+ */
+
+/**
+ * 指定 purger を「必ず例外を投げる」実装へ差し替える (集計クエリ失敗の再現)。
+ *
+ * registry は final だが `app($class)` で purger を解決するため、container への
+ * bind がそのまま効く。
+ */
+function bindFailingBillingRetentionPurger(string $purgerClass, BillingRetentionTarget $target): void
+{
+    app()->bind($purgerClass, fn (): BillingRetentionPurger => new class($target) implements BillingRetentionPurger
+    {
+        public function __construct(private readonly BillingRetentionTarget $target) {}
+
+        public function target(): BillingRetentionTarget
+        {
+            return $this->target;
+        }
+
+        public function countExpired(CarbonImmutable $threshold): int
+        {
+            throw new RuntimeException('集計に失敗した (テスト用)');
+        }
+
+        public function countFailClosed(CarbonImmutable $threshold): int
+        {
+            throw new RuntimeException('集計に失敗した (テスト用)');
+        }
+
+        public function purgeExpired(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
+        {
+            throw new RuntimeException('決着に失敗した (テスト用)');
+        }
+    });
+}
+
+test('集計に失敗した target があれば dry-run の horizon を OK と報告しない', function (): void {
+    // 他 target は 1 件も持たないので、失敗を無視すると remaining 合計は 0 = 従来は OK と出ていた
+    bindFailingBillingRetentionPurger(StripeWebhookEventPurger::class, BillingRetentionTarget::StripeWebhookEvent);
+
+    $this->artisan('billing:purge-retention-expired')
+        ->expectsOutputToContain('stripe_webhook_event: expired=0 processed=0 fail_closed=0 unexpected_failures=1 remaining=0')
+        ->expectsOutputToContain('horizon: 判定不能 (集計に失敗した target が 1 件')
+        ->doesntExpectOutputToContain('horizon: OK')
+        ->assertExitCode(1);
+});
+
+test('決着に失敗した target があれば --apply の horizon を OK と報告しない', function (): void {
+    bindFailingBillingRetentionPurger(StripeWebhookEventPurger::class, BillingRetentionTarget::StripeWebhookEvent);
+
+    $this->artisan('billing:purge-retention-expired', ['--apply' => true])
+        ->expectsOutputToContain('horizon: 判定不能 (集計に失敗した target が 1 件')
+        ->doesntExpectOutputToContain('horizon: OK')
+        ->assertExitCode(1);
+});
+
+test('失敗と実在の残存が同時にあっても件数を確定させず判定不能と報告する', function (): void {
+    $threshold = BillingRetention::threshold();
+    BillingRetentionFixtures::endedSubscription($threshold->subSecond());
+    bindFailingBillingRetentionPurger(StripeWebhookEventPurger::class, BillingRetentionTarget::StripeWebhookEvent);
+
+    $this->artisan('billing:purge-retention-expired')
+        ->expectsOutputToContain('horizon: 判定不能 (集計に失敗した target が 1 件。観測できた期限超過は 1 件だが、実数は不明)')
+        ->doesntExpectOutputToContain('horizon: OK')
+        ->doesntExpectOutputToContain('horizon: NG')
+        ->assertExitCode(1);
+});
+
+test('負のコントロール: 失敗が無ければ従来どおり horizon: OK と報告する', function (): void {
+    $this->artisan('billing:purge-retention-expired')
+        ->expectsOutputToContain('unexpected_failures=0')
+        ->expectsOutputToContain('horizon: OK (期限超過 0 件)')
+        ->doesntExpectOutputToContain('判定不能')
+        ->assertExitCode(0);
+});
+
 test('保持年数が 0 以下なら fail-fast する', function (): void {
     config()->set('legal.billing_retention_years', 0);
 
```

### テスト結果

- テストファースト: 追加した 3 テストが赤であることを確認 (「Output does not contain "horizon: 判定不能 ..."」) してから実装。
- 修正後: `composer test` (全体) = 4302 tests / 4300 passed / 2 skipped / 0 failed
- `composer phpstan` = No errors、`vendor/bin/pint --test` = passed、`pnpm lint` / `pnpm typecheck` = passed
- UI 非変更のため `pnpm test` / `pnpm build` / packages 系は省略

### 特に見てほしい点

1. horizon 3 値の優先順位 (失敗があれば NG より判定不能を出す) は運用上正しいか
2. テストの空振り検知は十分か (負のコントロールを 1 本置いた)
3. docs (runbook / architecture.md) の記述が実装と一致しているか、誇張していないか
