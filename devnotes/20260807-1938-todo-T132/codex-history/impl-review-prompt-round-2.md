# impl-review Round 2

Round 1 の指摘への対応が完了しました。対応マトリクス・追加差分・再検証結果を示します。
**再判定 (APPROVED / CHANGES_REQUESTED) をお願いします。**

## 対応マトリクス (Round 1 の指摘に対する判断)

# 対応マトリクス: impl-review Round 1

## [Critical] `PropagatesToQueueFailure` の前提が gate で検査されていない

- 判断: **対応する**
- 根拠: 指摘のとおり。件数と根拠長だけを見る gate は「後から `catch (Throwable)` を足して
  `getMessage()` をログへ載せても green」という抜け道を残す。deny-by-default を名乗る以上、
  免除の**前提そのもの**を機械で固定すべきである
  (AGENTS.md が言及する `ThrottleExemptionPremiseTest` と同じ作法が既にリポジトリにある = 先例あり)。
- 対応内容:
  - `tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php` に **検査 21** を新設。
    `PropagatesToQueueFailure` を宣言したクラスのソースに `catch (` が **0 件**であることを要求する。
    保守的な近似 (gateway 呼び出しを囲む catch かどうかまでは見ない) であることをコメントに明記した。
  - mutation coverage 表に **M11** を追加 (検査 20 の期待 ID 集合も同時に更新)。
  - `docs/architecture.md` §オートリチャージの失敗分類 に検査 21 の契約を追記
    (「gate が強制する」という記述が実態に追いついた)。
  - **mutation M11 で赤化を実測**: `SetDefaultPaymentMethodJob` の gateway 呼び出しを
    `try { … } catch (\Throwable $e) { return; }` で囲むと検査 21 が赤くなる
    (`Failed asserting that 1 is identical to 0.`)。復元済み。

## [Warning] 検査 19 が `use` 文しか見ておらず、完全修飾名で回避できる

- 判断: **対応する**
- 根拠: 指摘のとおり。docs で「4 つに閉じる」と保証を書いた以上、`use` 限定の走査は保証が嘘になる。
- 対応内容: PHP 同梱の `token_get_all()` (tokenizer。vendor 依存を増やさない = AST 不使用の方針と両立)
  で走査する `billingSourceReferencesStripeException()` を追加し、検査 19 をこれに切り替えた。
  - `T_COMMENT` / `T_DOC_COMMENT` を除外する。これは必須だった —
    `app/Services/Billing/Contracts/StripeGatewayInterface.php` の docblock が
    `\Stripe\Exception\ApiErrorException` に言及しており、素の文字列走査だと誤検出になる
    (「docblock で名前を挙げる」ことは「型を知っている」ことではない)。
  - 名前トークンだけでなく文字列リテラルも対象にする (`class_exists('Stripe\Exception\X')` 回避を許さない)。
  - **実測**: `SetDefaultPaymentMethodJob` に `\Stripe\Exception\ApiConnectionException::class` を
    1 行足す (import なし) と検査 19 が赤化することを確認。復元済み。
  - docs にも走査方式 (tokenizer / コメント除外) を明記した。

## [Warning] `Schema::rename` による DB 例外注入が Feature テストとして重い

- 判断: **一部対応する (注入方式は維持し、後片付けを二重化して理由を明記する)**
- 根拠 (反論込み):
  - 取りこぼし起票の catch が受けるのは `maybeCreateAttempt()` の**内側で起きる失敗**だけであり、
    gateway は一切通らない。したがって gateway fixture / hook では到達できない。
  - `AutoRechargeService` は `final` なので、テスト用サブクラスや partial mock で
    `maybeCreateAttempt()` を差し替えることもできない。
  - 残る選択肢は (a) 実 DB を一時的に壊す (b) `DB::listen` から擬似的に throw する の 2 つ。
    (b) は「QueryException が実際に上がった」ことの証明にならない (テストが自分で作った例外を
    自分で観測するだけ) ため、**(a) の方が検査として強い**と判断した。
  - PostgreSQL では DDL がトランザクショナルであり、`RefreshDatabase` の巻き戻しで確実に復元される。
- 対応内容: それでも「失敗時の後片付け」の懸念は正当なので、`try { … } finally { Schema::rename(戻す) }`
  で**明示的に戻す**ようにした (RefreshDatabase の巻き戻しと二重)。
  注入点をここに選んだ理由もテスト内コメントに残した。

## [Warning] `mutation-log.md` が diff に無い

- 判断: **説明する (実物は存在する)**
- 根拠: 設計の記述は「設計 dir へ記録」だが、実装スキルの規約では実装成果物 (mutation ログ /
  impl-review) は**実装用 design_dir** (`devnotes/{YYYYMMDD-HHMM}-todo-{todo_id}/`) に置く。
  実物は `devnotes/20260807-1938-todo-T132/mutation-log.md` に存在し、コミット対象に含まれる。
  Round 1 の diff が `app/ tests/ docs/ AGENTS.md` に限定されていたため見えなかっただけである。
- 対応内容: Round 2 のプロンプトで `mutation-log.md` の全文を添付する。


## Round 1 で「diff に無い」と指摘された mutation-log.md の全文

`devnotes/20260807-1938-todo-T132/mutation-log.md` (実装用 design_dir。コミット対象)

```markdown
# T132 mutation ログ

対象設計: `devnotes/20260807-1851-billing-gateway-error-taxonomy/detailed-design.md`
§mutation で赤化を確認する手順

実行環境: worktree `/workspace/.claude/worktrees/tasks/T132` (ブランチ `todo/T132`)

---

## 0. テストファースト: S5 検査 6 (getMessage cap) が実装前に赤くなることの実測

設計は「素の main で実際に赤くなるのは S5 検査 6 だけ」と述べている。実装 (S3) の前に
検査 6 だけを置いて実行し、穴が実在することを記録した。

- 適用状態: `tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php` に
  検査 6 (`rawMessageCap: 0`) だけを置き、`app/Services/Billing/AutoRechargeService.php` は
  main の現状 (`$e->getMessage()` が 3 箇所) のまま
- 実行コマンド: `composer test -- tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php`
- 結果: **FAILED (1 failed / 0 passed)**

```
P\Tests\Architecture\BillingGatewayFailureTaxonomyInventoryTest::
  __pest_evaluable_観測目録のクラスは例外_message_をログへ載せない__getMessage_の_cap_と一致_
App\Services\Billing\AutoRechargeService: getMessage() の出現件数が rawMessageCap と一致しません
Failed asserting that 3 is identical to 0.
```

→ 穴 (`$e->getMessage()` 3 箇所) の実在を実測で確認。S3 実装後は green
(`composer test -- tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php` = 23 passed)。

---

## 1. 「spy と本物の分類が食い違う = 実在する偽グリーン」の実査

設計 §S4 は「本物 `CashierAutoRechargeGateway::terminateInvoice()` の paid 判定は
`Assert::true(...)` = Webmozart の InvalidArgumentException を投げるのに対し、
spy は RuntimeException を投げる。分類は前者が `invariant_violation`、後者が `unknown` で
**実際に食い違う**」と判定している。**実装した分類器で実測して確認した**。

- 確認方法: 使い捨てスクリプト (worktree 直下に一時配置して実行後削除) で
  - (A) 本物の判定式と同一の `Assert::true(false, 'invoice ... は終端できない状態です (status=paid)')`
    (実装は `app/Services/Billing/CashierAutoRechargeGateway.php:167-170`)
  - (B) 変更前 spy の paid 判定 `new RuntimeException("fake gateway: paid invoice ... は終端できない")`
  - (C) 変更前 spy の `failOnTerminate` `new RuntimeException('fake gateway: invoice 終端失敗')`
  をそれぞれ `GatewayFailureClassifier::classify()` に通した。

```
real(paid)             Webmozart\Assert\InvalidArgumentException   => invariant_violation
spy(paid)              RuntimeException                            => unknown
spy(failOnTerminate)   RuntimeException                            => unknown
```

**結果: 設計の判定は正しい。** 本物 `invariant_violation` / spy `unknown` で食い違っており、
変更前の spy を使い続けると「分類を記録する経路がテストで一度も本物と同じ値を見ない」
偽グリーンが成立していた。S4 で spy の失敗注入を `GatewayFailureFixtures` 経由
(実ライブラリ例外) に寄せ、検査 15/16/17 が機械で固定するようにした。

---

## 2. mutation M1〜M10

手順: mutation を **1 つだけ**適用 → 対象テストを実行 → 赤化した test 名を記録 →
**必ず元へ戻す**。最後に `git diff` / grep で残留 0 を確認した。

実行コマンド (共通):
`composer test -- tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php`
(M5 のみ `tests/Unit/Support/Billing/GatewayFailureClassifierTest.php` も別途実行。
テストランナー wrapper は path を 1 つしか受け取らないため 2 回に分けた)

| ID | mutation 内容 | 赤化した検査 (test 名) | 設計の期待 | 一致 |
|---|---|---|---|---|
| M1 | `GatewayFailureClassifier::directMap()` から `RateLimitException` の entry を削除 | 検査 9: 分類対象の集合が vendor 母集団 + 非 vendor 明示宣言と一致する | 検査 9 | ○ |
| M2 | `directMap()` に実在しないクラス `\Foo\BarException::class` を追加 | 検査 9 | 検査 9 | ○ |
| M3 | `directMap()` の `PermissionException` の値を `GatewayFailureClass::Unknown` に変更 | 検査 11: 写像表の値に Unknown が現れない (unknown は写像の不在専用) | 検査 11 | ○ |
| M4 | `conditionalClasses()` を `[RateLimitException::class]` に差し替え | 検査 8 / 検査 9 / 検査 10 (条件付き規則のクラスがクラス同一性で固定されている) | 検査 10 (+ 9) | ○ |
| M5 | `GatewayFailureFixtures::throwableFor()` の `InvariantViolation` を `new \RuntimeException('x')` に変更 | 検査 15 (fixture の分類が宣言 case と一致する) / 検査 16 (実ライブラリ名前空間) / 検査 17b (message のマーカー) | 検査 15 / 16 (+ Unit) | △ (下記) |
| M6 | spy `FakeAutoRechargeGateway::terminateInvoice()` の throw を `new \RuntimeException('fake gateway')` に戻す | 検査 17: spy の throw がすべて fixture 経由である | 検査 17 | ○ |
| M7 | `AutoRechargeService::tryTerminateInvoice()` のログに `'error' => $e->getMessage()` を戻す | 検査 6: 観測目録のクラスは例外 message をログへ載せない (getMessage の cap と一致) | 検査 6 | ○ |
| M8 | `billingGatewayObservers()` の `AutoRechargeService` entry を実在しないクラス名へ差し替え (目録から削除) | 検査 1 (未分類 / 実在しない目録 entry) / 検査 5 / 検査 6 / 検査 7a / 検査 7b | 検査 1 | ○ |
| M9 | `billingGatewayObservationExemptionCap()` を `4` に変更 | 検査 3: 免除件数が cap と一致する | 検査 3 | ○ |
| M10 | `reconcile()` の attempt 隔離 catch から `...GatewayFailureClassifier::context($e)` を削除 | 検査 7a / 検査 7b (context() 出現回数の exact fit) | 検査 7 | ○ |
| M11 (追加) | 免除クラス `SetDefaultPaymentMethodJob` の gateway 呼び出しを `try { … } catch (\Throwable $e) { return; }` で囲む | 検査 21: 免除クラスは宣言どおり例外を伝播させる (catch を持たない) | (impl-review Round 1 の Critical 対応で新設) | ○ |

### M5 の差分 (正直に記録する)

設計の期待は「検査 15 / 16 + **Unit (分類一致)**」だが、実測では
**Unit テスト (`GatewayFailureClassifierTest`) は green のままだった** (33 passed)。

理由: Unit テストは `billingTaxonomyExpectedClassification()` の**独立宣言**と
`billingTaxonomyInstantiate()` の実インスタンス生成で分類を固定しており、
`GatewayFailureFixtures` に依存していない (依存しているのは `context()` の
検査で使う `EXTERNAL_MESSAGE_MARKER` 定数だけ)。したがって fixture を壊しても
Unit は落ちない。これは**設計意図どおりの独立性**であり、fixture の破壊は
Architecture gate の検査 15 / 16 / 17b が 3 本同時に捕まえる。
期待より検出が弱くはなっていない (むしろ 17b が追加で赤くなる)。

### 残留がないことの確認

```
git diff --stat
 AGENTS.md                                          |   9 ++
 app/Services/Billing/AutoRechargeService.php       |  35 +++--
 docs/architecture.md                               |  55 +++++++-
 tests/Feature/Billing/AutoRechargeReconcileTest.php|  66 +++++++++
 tests/Feature/Billing/AutoRechargeServiceTest.php  | 155 ++++++++++++++++++---
 tests/Support/FakeAutoRechargeGateway.php          |  27 ++--
```

mutation で触った箇所を grep で個別確認:

- `RemovedForMutation` / `BarException` / `new \RuntimeException('x')` … 0 件
- `GatewayFailureClassifier::context` の出現回数 = **4** (`AutoRechargeService`)
- `conditionalClasses()` = `return [UnknownApiErrorException::class];`
- `RateLimitException::class => GatewayFailureClass::ProviderUnavailable` が存在
- `billingGatewayObservationExemptionCap()` = `return 3;`

---

## 3. impl-review Round 1 の指摘対応で追加した検査の実効性確認

### 検査 21 (免除の前提を behavioral に固定) — M11

上表 M11 のとおり赤化を実測した。

```
App\Jobs\Billing\SetDefaultPaymentMethodJob: 「伝播させる」と免除宣言しているのに catch があります。
観測目録へ移すか、免除の分類を見直すこと
Failed asserting that 1 is identical to 0.
```

### 検査 19 の走査を tokenizer 化 (完全修飾名の回避を塞ぐ) — 一回性の確認

mutation coverage 表には載せていないが (表は M1〜M11 が正本)、
「`use` 文を書かずに完全修飾名で参照して allowlist を回避する」経路が実際に塞がったことを
1 回だけ実測した。

- mutation: `SetDefaultPaymentMethodJob` に
  `$ignored = \Stripe\Exception\ApiConnectionException::class;` を 1 行足す (import は追加しない)
- 結果: **検査 19 が赤化**。`App\Jobs\Billing\SetDefaultPaymentMethodJob` が allowlist との
  差分として検出された。
- 併せて `app/Services/Billing/Contracts/StripeGatewayInterface.php` の
  **docblock 内の言及**は tokenizer が `T_DOC_COMMENT` を除外するため検出されない
  (= 誤検出しない) ことも allowlist が 4 件のまま green であることで確認済み。
- 復元後に `git diff --stat app/Jobs/` が空であることを確認した。

```

## 実装差分 (Round 1 の対応を含む最新の全差分 / app/ tests/ docs/ AGENTS.md)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index a5918cc..8509234 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -363,3 +363,12 @@ ## ドメイン固有規約
    保証を代替できる長さに伸ばさない (`JobExclusionOrderingInvariantTest` が
    `retry_after` 未満を固定)。**閉じない窓と運用上の所有者**は `docs/architecture.md`
    §ジョブの重複実行と結果の一回性 が正本。
+7. **決済 gateway 失敗の観測語彙**: `AutoRechargeGatewayInterface` を注入されるクラスは、
+   gateway 例外を **観測する (`GatewayFailureClassifier::context()` の
+   `failure_class` / `error_class` の 2 キーだけをログへ載せる)** か、
+   **伝播させる (`GatewayFailureObservationExemption` + 30 文字以上の根拠で免除登録)** かの
+   どちらかに目録登録が必須 (`BillingGatewayFailureTaxonomyInventoryTest` が
+   deny-by-default で強制)。**例外 message はログに載せない** (外部生成の可変文字列)。
+   分類は**観測のためであり制御フローを変えない**。`unknown` は「写像表に一致が無かった」
+   ことを意味し、写像表の値としては禁止。詳細と運用契約は
+   `docs/architecture.md` §オートリチャージの失敗分類。
diff --git a/app/Enums/Billing/GatewayFailureClass.php b/app/Enums/Billing/GatewayFailureClass.php
new file mode 100644
index 0000000..af1285c
--- /dev/null
+++ b/app/Enums/Billing/GatewayFailureClass.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * 決済 gateway 消費経路で観測された失敗の分類。
+ *
+ * ★語彙は「**呼び出し側 / 運用担当が取れる行動**」で切る。Stripe の error code を
+ *   そのまま採らない (外部語彙に依存すると増えたときに追随できない)。
+ * ★case を足す条件は「運用担当が取る行動が既存 case と異なる」ことだけ。
+ *   分類の粒度を過剰にしない (AGENTS.md 思考原則 2)。
+ * ★**この分類は観測のためであり、制御フローを変えない。**
+ *   分岐に使いたくなったら、そのときは型 (ドメイン例外) を検討し直すこと。
+ * ★カード拒否 (`card_declined` / `authentication_required`) は本 enum の担当ではない。
+ *   既に `OffSessionChargeResultDto` の typed 結果が持っている (語彙を二重管理しない)。
+ */
+enum GatewayFailureClass: string
+{
+    /** 決済事業者側の一時的な不能 (接続断・タイムアウト・レート制限・5xx)。同じ要求の再送で収束しうる */
+    case ProviderUnavailable = 'provider_unavailable';
+
+    /** 決済事業者が要求を受理しなかった。同じ要求を再送しても収束しない (要求内容・認証情報・利用者操作のいずれかが要る) */
+    case ProviderRejected = 'provider_rejected';
+
+    /** アプリ自身が検出した不変条件違反 (Assert / 明示的な例外 / SDK・Cashier の誤用) */
+    case InvariantViolation = 'invariant_violation';
+
+    /** 自インフラ層 (DB / cache) が返した失敗。障害・SQL 不備・制約違反のいずれもありうる */
+    case LocalFailure = 'local_failure';
+
+    /**
+     * **写像表に一致が無かった**。
+     *
+     * ★この case が出ること自体が「分類器に欠落がある」という通知である。
+     *   したがって**写像表の値として使ってはならない** (登録済みなのに unknown、という
+     *   状態を作ると運用契約「unknown が出たら表へ足せ」と矛盾する)。
+     *   `BillingGatewayFailureTaxonomyInventoryTest` が機械で禁止する。
+     */
+    case Unknown = 'unknown';
+}
diff --git a/app/Enums/Security/GatewayFailureObservationExemption.php b/app/Enums/Security/GatewayFailureObservationExemption.php
new file mode 100644
index 0000000..bc3a6a1
--- /dev/null
+++ b/app/Enums/Security/GatewayFailureObservationExemption.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 「決済 gateway を注入されるが、gateway 例外を**観測しない**ことが正しい」と裁定された理由の分類。
+ *
+ * `tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php` が deny-by-default で
+ * 「観測目録に登録する」か「本 enum + 具体的根拠付きの exemption」かを機械強制する。
+ *
+ * ★置き場所は既存の gate 語彙 enum (ThrottleCoverageExemption / JobDedupExemption /
+ *   DirectFetchJustification / ControllerAuthorizationExemption / NestedRouteDefenseMode) と揃える。
+ */
+enum GatewayFailureObservationExemption: string
+{
+    /**
+     * gateway 例外を catch せず**伝播させる**。
+     *
+     * 適用条件: クラス内に gateway 呼び出しを囲む catch が 1 つも無く、失敗が
+     * キューの再試行 / `failed_jobs` に載ることで可観測性が担保されること。
+     * ★根拠欄には「catch しないから安全」ではなく
+     *   **「catch しない結果どこに何が残るか」**を書くこと
+     *   (伝播先には vendor 例外の message が載る = 本設計の保証範囲外である)。
+     */
+    case PropagatesToQueueFailure = 'propagates_to_queue_failure';
+}
diff --git a/app/Services/Billing/AutoRechargeService.php b/app/Services/Billing/AutoRechargeService.php
index 97263f2..91092d7 100644
--- a/app/Services/Billing/AutoRechargeService.php
+++ b/app/Services/Billing/AutoRechargeService.php
@@ -27,6 +27,7 @@
 use App\Notifications\Billing\AutoRechargeEnabledNotification;
 use App\Notifications\Billing\AutoRechargeFailedNotification;
 use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use App\Support\Billing\GatewayFailureClassifier;
 use App\Support\JobExecution\AttemptOwnershipPreflight;
 use Carbon\CarbonImmutable;
 use Illuminate\Contracts\Cache\LockTimeoutException;
@@ -680,10 +681,12 @@ private function terminateUnattachedInvoice(
      *   手動収束に委ねる。
      * ★ **cleanup 専用の event 名**を使う。送信抑止の記録 (`LOG_EVENT`) は最小 7 キー schema を
      *   持つ契約であり、キー集合の違うログを同じ event 名に混ぜない。
-     * ★ `error` に入れるのは**例外クラス名だけ**である (impl-review Round 2/3 反映)。
+     * ★ ログに載せるのは `GatewayFailureClassifier` が返す**2 キーだけ**である
+     *   (`failure_class` = 有界な分類 / `error_class` = 例外クラス名。T132)。
      *   Stripe SDK の例外メッセージは**外部サービスが生成する可変文字列**であり、
      *   いま既知の内容が invoice id と status だけでも、将来の SDK / API 応答で
      *   何が混ざるかの契約は無い。構造化ログには**アプリが決めた有界な語彙**だけを載せる。
+     *   ★成功時も 2 キーは **null で存在させる** (集計 schema を成否で割らない)。
      * ★ 例外報告も**原例外を渡さない** (impl-review Round 3 反映)。
      *   標準の exception handler は message とスタックトレースを記録するため、
      *   `report($exception)` では「保存場所を移しただけ」で外部生成文字列が残る。
@@ -696,17 +699,22 @@ private function terminateInvoiceBestEffort(
         string $invoiceId,
     ): void {
         $terminated = true;
-        $error = null;
+        $failure = null;
         try {
             $this->gateway->terminateInvoice($invoiceId);
         } catch (Throwable $exception) {
             $terminated = false;
-            // paid 等の「明示的な非成功」もここに落ちる。分類できる有界な値 (クラス名) のみ記録する。
-            $error = $exception::class;
+            // paid 等の「明示的な非成功」もここに落ちる。有界な 2 キー (分類 + 例外クラス名) のみ記録する。
+            $failure = GatewayFailureClassifier::context($exception);
             // 原例外は報告しない (外部生成メッセージ / previous chain をログ基盤へ流さない)。
-            report(new RuntimeException(
-                "auto-recharge: invoice {$invoiceId} の終端に失敗しました ({$error})",
-            ));
+            // ★文言は**固定テンプレート**にする。report message は集計語彙になりうるため、
+            //   Feature テストが**完全一致**で固定する (部分一致だと文字列の追加を検出できない)。
+            report(new RuntimeException(sprintf(
+                'auto-recharge: invoice %s の終端に失敗しました (%s / %s)',
+                $invoiceId,
+                $failure['failure_class'],
+                $failure['error_class'],
+            )));
         }
 
         Log::warning('auto-recharge: 所有権喪失後の invoice 終端', [
@@ -716,7 +724,10 @@ private function terminateInvoiceBestEffort(
             'attempt_ulid' => $attempt->attempt_ulid,
             'invoice_id' => $invoiceId,
             'terminated' => $terminated,
-            'error' => $error,
+            // ★成功時も**キーは常に存在させる** (集計 schema を安定させる。値は null)。
+            //   ここだけ spread を使わないのはこのためである。
+            'failure_class' => $failure['failure_class'] ?? null,
+            'error_class' => $failure['error_class'] ?? null,
         ]);
     }
 
@@ -830,10 +841,10 @@ private function tryTerminateInvoice(TicketAutoRechargeAttempt $attempt): bool
             Log::warning('auto-recharge: invoice termination failed, keeping attempt pending', [
                 'attempt_ulid' => $attempt->attempt_ulid,
                 'invoice_id' => $attempt->stripe_invoice_id,
-                'error' => $e->getMessage(),
+                ...GatewayFailureClassifier::context($e),
             ]);
 
-            return false;
+            return false; // ★制御フローは現行のまま (pending 維持 → リコンサイル再試行)
         }
     }
 
@@ -991,7 +1002,7 @@ public function reconcile(): array
                 // 1 attempt の失敗が他 org の回収を止めないよう隔離 (次周期で再試行)。
                 Log::warning('auto-recharge reconcile: attempt processing failed', [
                     'attempt_ulid' => $attempt->attempt_ulid,
-                    'error' => $e->getMessage(),
+                    ...GatewayFailureClassifier::context($e),
                 ]);
             }
         }
@@ -1011,7 +1022,7 @@ public function reconcile(): array
             } catch (Throwable $e) {
                 Log::warning('auto-recharge reconcile: trigger failed', [
                     'organization_id' => $organization->getKey(),
-                    'error' => $e->getMessage(),
+                    ...GatewayFailureClassifier::context($e),
                 ]);
             }
         }
diff --git a/app/Support/Billing/GatewayFailureClassifier.php b/app/Support/Billing/GatewayFailureClassifier.php
new file mode 100644
index 0000000..e87dc97
--- /dev/null
+++ b/app/Support/Billing/GatewayFailureClassifier.php
@@ -0,0 +1,174 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Billing;
+
+use App\Enums\Billing\GatewayFailureClass;
+use Illuminate\Contracts\Cache\LockTimeoutException;
+use Illuminate\Database\QueryException;
+use Laravel\Cashier\Exceptions\CustomerAlreadyCreated;
+use Laravel\Cashier\Exceptions\IncompletePayment;
+use Laravel\Cashier\Exceptions\InvalidCoupon;
+use Laravel\Cashier\Exceptions\InvalidCustomer;
+use Laravel\Cashier\Exceptions\InvalidCustomerBalanceTransaction;
+use Laravel\Cashier\Exceptions\InvalidInvoice;
+use Laravel\Cashier\Exceptions\InvalidPaymentMethod;
+use Laravel\Cashier\Exceptions\SubscriptionUpdateFailure;
+use Stripe\Exception\ApiConnectionException;
+use Stripe\Exception\AuthenticationException;
+use Stripe\Exception\BadMethodCallException as StripeBadMethodCallException;
+use Stripe\Exception\CardException;
+use Stripe\Exception\IdempotencyException;
+use Stripe\Exception\InvalidArgumentException as StripeInvalidArgumentException;
+use Stripe\Exception\InvalidRequestException;
+use Stripe\Exception\PermissionException;
+use Stripe\Exception\RateLimitException;
+use Stripe\Exception\SignatureVerificationException;
+use Stripe\Exception\TemporarySessionExpiredException;
+use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
+use Stripe\Exception\UnknownApiErrorException;
+use Throwable;
+use Webmozart\Assert\InvalidArgumentException as AssertInvalidArgumentException;
+
+/**
+ * 決済 gateway 消費経路で捕まえた Throwable を、有界な分類 (GatewayFailureClass) へ写す純関数。
+ *
+ * ★**Stripe / Cashier の例外型を知る唯一の非 gateway コンポーネント**である。
+ *   ここに集約することで「外部語彙が観測点へ散らばる」ことを防ぐ
+ *   (集約点が 2 つになったら語彙が割れる。gate が import の allowlist を固定する)。
+ * ★制御フローに使わない。分類は観測 (構造化ログ / 例外報告の文言) 専用である。
+ * ★`unknown` は「写像の不在」であり、`directMap()` の値には現れない
+ *   (`BillingGatewayFailureTaxonomyInventoryTest` が機械で禁止する)。
+ */
+final class GatewayFailureClassifier
+{
+    public static function classify(Throwable $throwable): GatewayFailureClass
+    {
+        // ★条件付き規則を先に判定する (唯一の特別扱い)。
+        //   UnknownApiErrorException は ApiRequestor::_specificV1APIError() の status switch の
+        //   `default:` 分岐であり、**Stripe の 5xx はすべてここに来る**。
+        //   「未知」なのは error type であって status ではないため、status で細分する。
+        if ($throwable instanceof UnknownApiErrorException) {
+            // ★vendor の PHPDoc は @return null|int だが**戻り型宣言は無い**。
+            //   `!== null` ではなく `is_int()` で narrowing して、PHPDoc の揺れに耐えさせる。
+            $status = $throwable->getHttpStatus();
+
+            if (is_int($status) && $status >= 500) {
+                return GatewayFailureClass::ProviderUnavailable;
+            }
+
+            // 4xx / その他 / null / 非 int。**運用上の保守的分類**であり、
+            // 再送可能性の完全な意味判定ではない。status 不明で ProviderUnavailable
+            // (= 待てば直る) と言うと**無行動を示唆する誤誘導**になるため「調べる」側へ倒す。
+            // 実際には factory が必ず status を受け取るため、null / 非 int は防御的分岐である。
+            return GatewayFailureClass::ProviderRejected;
+        }
+
+        $map = self::directMap();
+
+        // ★実クラス → 親クラス連鎖の順に最初の一致を採る (将来のサブクラスを取りこぼさない)。
+        //   グローバル SPL クラス (\RuntimeException 等) は表に入れないため、
+        //   Stripe\Exception\InvalidArgumentException と Webmozart\Assert\InvalidArgumentException が
+        //   共通祖先 \InvalidArgumentException で衝突することはない。
+        $class = $throwable::class;
+
+        do {
+            if (array_key_exists($class, $map)) {
+                return $map[$class];
+            }
+
+            // get_parent_class() は最上位クラスで false を返す (= 連鎖の終端)。
+            $class = get_parent_class($class);
+        } while ($class !== false);
+
+        return GatewayFailureClass::Unknown;
+    }
+
+    /**
+     * 構造化ログ / 例外報告に載せる 2 キー。
+     *
+     * ★観測点が**同じ綴りの同じ 2 キー**を出すことをコードの構造で担保する
+     *   (gate が「宣言した catch 箇所の数 == `context(` の出現回数」を exact fit で検査する)。
+     * ★`error_class` は外部サービスが生成する文字列ではない (値域はコードベース + vendor の
+     *   クラス名に閉じる)。**例外 message は載せない**。
+     *
+     * @return array{failure_class: string, error_class: class-string<Throwable>}
+     */
+    public static function context(Throwable $throwable): array
+    {
+        return [
+            'failure_class' => self::classify($throwable)->value,
+            'error_class' => $throwable::class,
+        ];
+    }
+
+    /**
+     * 直接写像 (class => case) の正本。
+     *
+     * ★根拠は推測ではなく **vendor の throw site**。Stripe 側は
+     *   `vendor/stripe/stripe-php/lib/ApiRequestor.php` の `_specificV1APIError()` の
+     *   HTTP status switch が正本 (400 => InvalidRequest / 400+idempotency_error => Idempotency /
+     *   400+rate_limit => RateLimit / 401 => Authentication / 402 => Card / 403 => Permission /
+     *   404 => InvalidRequest / 429 => RateLimit / default => UnknownApiError)。
+     *   `_specificV2APIError()` は temporary_session_expired のみ振り分けて V1 へ委譲する。
+     * ★**値に GatewayFailureClass::Unknown を置かない** (unknown は写像の不在専用)。
+     * ★**vendor 全件分類 gate のため、gateway 経路で通常発生しない Stripe 例外
+     *   (SignatureVerificationException = webhook 署名検証用 など) も観測語彙上は分類する。**
+     *   分類は「もし来たら何と呼ぶか」の宣言であって「来る」という主張ではない
+     *   (母集団に穴を空けると、SDK 更新で増えた例外が無音で unknown へ落ちる)。
+     *
+     * @return array<class-string<Throwable>, GatewayFailureClass>
+     */
+    public static function directMap(): array
+    {
+        return [
+            // --- Stripe SDK: 決済事業者側の一時的な不能 ---
+            ApiConnectionException::class => GatewayFailureClass::ProviderUnavailable, // HTTP 到達前の接続断
+            RateLimitException::class => GatewayFailureClass::ProviderUnavailable,     // 429 / 400+rate_limit
+
+            // --- Stripe SDK: 要求が受理されなかった ---
+            InvalidRequestException::class => GatewayFailureClass::ProviderRejected,           // 400 / 404
+            AuthenticationException::class => GatewayFailureClass::ProviderRejected,           // 401
+            CardException::class => GatewayFailureClass::ProviderRejected,                     // 402 (通常は typed 結果へ変換される)
+            PermissionException::class => GatewayFailureClass::ProviderRejected,               // 403
+            IdempotencyException::class => GatewayFailureClass::ProviderRejected,              // 400 + idempotency_error
+            TemporarySessionExpiredException::class => GatewayFailureClass::ProviderRejected,  // V2: temporary_session_expired
+            SignatureVerificationException::class => GatewayFailureClass::ProviderRejected,    // webhook 署名不一致 (gateway 経路では発生しない)
+
+            // --- Stripe SDK: SDK の誤用 = 自コードの欠陥 ---
+            StripeBadMethodCallException::class => GatewayFailureClass::InvariantViolation,
+            StripeInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
+            StripeUnexpectedValueException::class => GatewayFailureClass::InvariantViolation,
+
+            // --- Cashier ---
+            IncompletePayment::class => GatewayFailureClass::ProviderRejected,          // 追加認証 (SCA) が要る
+            CustomerAlreadyCreated::class => GatewayFailureClass::InvariantViolation,   // ManagesCustomer::createAsStripeCustomer
+            InvalidCustomer::class => GatewayFailureClass::InvariantViolation,          // ManagesCustomer::assertCustomerExists
+            InvalidPaymentMethod::class => GatewayFailureClass::InvariantViolation,     // PaymentMethod::__construct (invalidOwner)
+            InvalidInvoice::class => GatewayFailureClass::InvariantViolation,           // Invoice::__construct (invalidOwner)
+            InvalidCoupon::class => GatewayFailureClass::InvariantViolation,            // 本アプリは coupon を使わない
+            InvalidCustomerBalanceTransaction::class => GatewayFailureClass::InvariantViolation,
+            SubscriptionUpdateFailure::class => GatewayFailureClass::InvariantViolation, // Subscription::guardAgainst*
+
+            // --- 非 vendor 明示宣言 (reconcile の catch(Throwable) が実際に受けうるもの) ---
+            QueryException::class => GatewayFailureClass::LocalFailure,
+            LockTimeoutException::class => GatewayFailureClass::LocalFailure,
+            AssertInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
+        ];
+    }
+
+    /**
+     * 条件付き規則を持つクラス (直接写像に入れられないもの)。
+     *
+     * ★`directMap()` に入れると値がダミーになり「正本」が嘘をつくため分けている。
+     * ★gate が `=== [UnknownApiErrorException::class]` を**クラス同一性**で固定する
+     *   (件数だけだと別クラスへ差し替えても green になる)。
+     *
+     * @return list<class-string<Throwable>>
+     */
+    public static function conditionalClasses(): array
+    {
+        return [UnknownApiErrorException::class];
+    }
+}
diff --git a/docs/architecture.md b/docs/architecture.md
index 7a60cdb..7f35fc1 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -338,7 +338,7 @@ ### ジョブの重複実行と結果の一回性
 
      | # | 発生条件 | 検知元 | 収束手順 |
      |---|---|---|---|
-     | (a) | 所有権喪失後の void / delete に失敗した | **アプリログ**: `event = job_ownership_lost_cleanup` かつ `terminated=false` (原因の分類は同ログの `error` = 例外クラス名。`report()` 側にも **invoice id と例外クラス名だけを持つサニタイズ済み例外**しか流れないため、**この cleanup 経路では Stripe が生成した原メッセージはアプリのどこにも残らない** (別経路の `tryTerminateInvoice()` は対象外)。詳細が要るときは `invoice_id` で Stripe 側を直接確認する) | 同ログの `invoice_id` を Stripe で確認し、`paid` でなければ手動 void |
+     | (a) | 所有権喪失後の void / delete に失敗した | **アプリログ**: `event = job_ownership_lost_cleanup` かつ `terminated=false` (原因の分類は同ログの **`failure_class`** = `GatewayFailureClass`、**`error_class`** = 例外クラス名。**成功時も両キーは `null` で存在する** (集計 schema を成否で割らない)。`report()` 側にも invoice id とこの 2 値だけを持つサニタイズ済み例外しか流れないため、**この cleanup 経路で本サービスが出す構造化ログと report message には Stripe が生成した原メッセージが残らない** (`report()` の stack trace / vendor 側の別ログ / 伝播した queue failure は本保証の範囲外)。`tryTerminateInvoice()` / `reconcile()` も同じ 2 キーへ統一済み。詳細が要るときは `invoice_id` で Stripe 側を直接確認する) | 同ログの `invoice_id` を Stripe で確認し、`paid` でなければ手動 void |
      | (b) | invoice 作成成功 → `stripe_invoice_id` の永続化前にワーカーが死亡した | **アプリログには何も残らない**。Stripe 側を起点に探す — metadata `purpose=auto_recharge` を持つ `draft` / `open` invoice を列挙し、その `recharge_attempt_ulid` に対応する `ticket_auto_recharge_attempts` 行の `stripe_invoice_id` が **NULL または別 id** のものが孤児 | **原則すべて手動終端の対象**とする。`paid` でないことを確認して void / delete する |
 
      > **(b) を「次の実行が拾うから放置してよい」と書かない** — Stripe の idempotency key は
@@ -374,6 +374,67 @@ ### ジョブの重複実行と結果の一回性
 | 所有権喪失時に invoice 作成・支払いを抑止し、必要な既作成 invoice を終端する | `AutoRechargeServiceTest` |
 | ログコンテキストに PII を含めない | `JobOwnershipLostContextTest` |
 | 固定 event 名の literal が 1 箇所に閉じる | `JobExecutionDedupInventoryTest` |
+| gateway を注入されるクラスが観測目録 or 免除に分類される / vendor 例外が全件分類される / `unknown` が写像表の値に現れない / fake の失敗注入が本物と同じ分類になる | `BillingGatewayFailureTaxonomyInventoryTest` |
+| 分類器の写像・境界 (`UnknownApiErrorException` の HTTP status) ・`context()` の array shape | `GatewayFailureClassifierTest` |
+| 失敗分類が実際にログへ載る / 成功時も null で存在する / 制御フローが変わらない | `AutoRechargeServiceTest` / `AutoRechargeReconcileTest` |
+
+### オートリチャージの失敗分類
+
+決済 gateway (`AutoRechargeGatewayInterface`) の消費経路で捕まえた例外は、
+`App\Support\Billing\GatewayFailureClassifier` が**有界な語彙**へ写してからログに載せる。
+**分類は観測のためであり、制御フローを変えない** (課金の振る舞いは分類の導入前後で同一)。
+
+| `failure_class` | 意味 | 運用担当が取る行動 |
+|---|---|---|
+| `provider_unavailable` | 決済事業者側の一時的な不能 (接続断・レート制限・5xx) | 同じ要求の再送で収束しうる。頻度を監視する |
+| `provider_rejected` | 決済事業者が要求を受理しなかった (400/401/402/403 等) | 再送しても収束しない。要求内容・認証情報・利用者操作を確認する |
+| `invariant_violation` | アプリ自身が検出した不変条件違反 (Assert / SDK・Cashier の誤用) | **アプリの欠陥**。コードを直す |
+| `local_failure` | 自インフラ層 (DB / cache) の失敗 | インフラを確認する |
+| `unknown` | **写像表に一致が無かった** | 下記「`unknown` の運用契約」 |
+
+ログに載るのは `failure_class` と `error_class` (例外クラス名) の **2 キーだけ**である。
+**例外 message は載せない** (外部サービスが生成する可変文字列であり、
+構造化ログの集計語彙にしない)。
+
+**`unknown` の運用契約 (所有者 = 課金運用担当)**
+
+- **検知条件**: `failure_class = unknown` を含む warning が 1 件でも出たら検知とみなす
+  (`unknown` は「分類器に欠落がある」という通知そのものであり、正常状態では出ない)。
+- **初動**: 同ログの `error_class` を見て、そのクラスを
+  `GatewayFailureClassifier::directMap()` (または条件付き規則) へ追加し、
+  `GatewayFailureClassifierTest` の期待値表にも**独立に**書く。
+  **`unknown` を写像表の値として書いてはならない** (gate が機械的に禁止する)。
+- vendor 由来のクラスなら `BillingGatewayFailureTaxonomyInventoryTest` の検査 9 が
+  同時に赤くなっているはずなので、CI の赤と突き合わせる。
+
+**vendor 更新 (`composer update`) で gate が赤くなったときの手順**
+
+`BillingGatewayFailureTaxonomyInventoryTest` は stripe-php / cashier の例外クラス集合を走査し
+「写像表 == 実在クラス集合」を要求する。**依存更新で赤くなるのは意図した費用**であり
+(外部の語彙が増えたことを人間に必ず知らせるための仕掛け)、soft-fail 化しない。
+
+1. 検査 9 の失敗メッセージが挙げるクラス名を確認する。
+2. 増えたクラスは vendor の throw site を読んで**行動で切る**分類を決め、
+   `GatewayFailureClassifier::directMap()` と `GatewayFailureClassifierTest` の期待値表の
+   **両方**へ追加する (二重宣言なのは、片方だけでは写像を間違えても green になるため)。
+   HTTP status 等の条件で分岐が要るものだけ `conditionalClasses()` 側へ置く。
+3. 消えたクラスは両方から削除する。
+4. 検査 13 (a) が赤い場合は SDK が**サブ名前空間を増減させた**ことを意味する。
+   `VendorExceptionPopulation::EXCLUDED_STRIPE_SUBNAMESPACES` に
+   30 文字以上の根拠付きで宣言するか、母集団定義そのものを再検討する。
+
+**Stripe 例外型を知ってよいクラス**は gateway 実装 3 本
+(`CashierStripeGateway` / `CashierAutoRechargeGateway` / `StripeScheduleGateway`) と
+`GatewayFailureClassifier` の**計 4 つに閉じる** (検査 19 が allowlist で固定。
+走査は PHP 同梱の tokenizer で行い、`use` 文だけでなく完全修飾名・文字列リテラルも拾う。
+コメント / docblock での言及は対象外)。
+集約点が増えると観測語彙が割れるため、新しい観測点を作らず分類器を使うこと。
+
+**免除 (`GatewayFailureObservationExemption::PropagatesToQueueFailure`) の前提**は
+検査 21 が behavioral に固定する: 免除宣言したクラスは `catch (` を **1 つも持たない**。
+件数と根拠長だけを見る gate では、後から `catch (Throwable)` を足して `getMessage()` を
+ログへ載せても green のままになるため (`ThrottleExemptionPremiseTest` と同じ作法)。
+catch を足す必要が出たら、観測目録へ移すか免除の分類を見直すこと。
 
 ### AI 解析ジョブの運用契約
 
diff --git a/tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php b/tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php
new file mode 100644
index 0000000..1634450
--- /dev/null
+++ b/tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php
@@ -0,0 +1,571 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\GatewayFailureClass;
+use App\Enums\Security\GatewayFailureObservationExemption;
+use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
+use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
+use App\Jobs\Billing\SetDefaultPaymentMethodJob;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\CashierAutoRechargeGateway;
+use App\Services\Billing\CashierStripeGateway;
+use App\Services\Billing\StripeScheduleGateway;
+use App\Support\Billing\GatewayFailureClassifier;
+use Illuminate\Contracts\Cache\LockTimeoutException;
+use Illuminate\Database\QueryException;
+use Laravel\Cashier\Exceptions\IncompletePayment;
+use Stripe\Exception\ApiConnectionException;
+use Stripe\Exception\UnknownApiErrorException;
+use Tests\Support\Billing\GatewayConsumerPopulation;
+use Tests\Support\Billing\GatewayFailureFixtures;
+use Tests\Support\Billing\GatewayObservationEntry;
+use Tests\Support\Billing\GatewayObservationExemptionEntry;
+use Tests\Support\Billing\VendorExceptionPopulation;
+use Tests\Support\FakeAutoRechargeGateway;
+use Tests\Support\QueuedJobPopulation;
+use Webmozart\Assert\Assert;
+use Webmozart\Assert\InvalidArgumentException as AssertInvalidArgumentException;
+
+/*
+ * 決済 gateway 消費経路の「失敗分類の語彙」を deny-by-default で固定する。
+ *
+ * ★この gate が保証するもの:
+ *   - gateway を注入される app クラスが全件「観測目録」か「免除」に分類されている
+ *   - vendor (Stripe / Cashier) の例外クラスが全件、写像表か条件付き規則に属する
+ *   - `unknown` が写像表の値に現れない (= unknown は写像の不在専用)
+ *   - 条件付き規則のクラスがクラス同一性で 1 件に固定されている
+ *   - fake の失敗注入が本物と同じ分類を返す (fixture 経由・実ライブラリ例外)
+ *   - **fixture の message に外部生成文字列の目印が確かに入っている**
+ *     (negative assertion が空虚に green にならないための前提保証)
+ *   - 観測目録のクラスが例外 message をログへ載せない (getMessage() の cap)。
+ *     ★これは gateway 観測点だけでなく**クラス全体**に掛かる設計制約である
+ *       (対象クラスは gateway 以外の外部由来例外も受けうる。catch 近傍だけに限ると走査が脆い)。
+ *       将来正当な必要が出たら rawMessageCap の変更が必ず差分に現れる
+ *   - 旧 API (`failOnTerminate` 等) の残存が **本 gate ファイル自身 (= リテラルの正本) を除いて**
+ *     0 件 (思考原則 3 の機械化)。★除外しないと**検査コード自身が hit して必ず失敗する**
+ *   - **免除の前提が behavioral に固定されている**: `PropagatesToQueueFailure` を宣言した
+ *     クラスに `catch (` が 1 つも無いこと (impl-review Round 1 反映)。件数と根拠長だけを
+ *     見る gate は、後から catch を足して message をログへ載せても green になる抜け道を残す
+ *     (`ThrottleExemptionPremiseTest` と同じ「免除の前提を検査する」作法)
+ *
+ * ★この gate が保証しないもの:
+ *   - catch が「gateway 呼び出しを囲んでいる」こと (メソッド単位までは絞るが、
+ *     catch の**中**で呼ばれているかは検査しない。配置の保証は Feature テスト =
+ *     AutoRechargeServiceTest / AutoRechargeReconcileTest が
+ *     「失敗時に分類が載る / 成功時にキーが null で載る」で担う)
+ *   - **AST は使わない**。nikic/php-parser は vendor に存在するが直接依存ではなく
+ *     transitive (phpstan / nette 経由) であり、composer の解決次第で消えうるものへ
+ *     Architecture テストを依存させない (AGENTS.md 思考原則 1・2)。
+ *     Reflection によるメソッド単位の切り出しで足りる
+ *   - 期待値と目録を**同時に**消す変更 (宣言的 gate の性質。目的は
+ *     「1 箇所の削除では通らない = レビューで必ず 2 箇所の差分が見える」こと)
+ *
+ * 運用契約: docs/architecture.md §オートリチャージの失敗分類
+ */
+
+const BILLING_GATEWAY_TAXONOMY_MUTATION_COVERAGE = [
+    'M1' => '写像表から entry を 1 つ削ると vendor 集合一致が赤くなる',
+    'M2' => '写像表に実在しないクラスを足すと集合一致が赤くなる',
+    'M3' => '写像表の値に Unknown を置くと赤くなる',
+    'M4' => 'conditionalClasses を別クラスへ差し替えると赤くなる',
+    'M5' => 'fixture の 1 case を独自 RuntimeException にすると分類一致 / 名前空間が赤くなる',
+    'M6' => 'spy に fixture 経由でない throw を戻すと赤くなる',
+    'M7' => 'AutoRechargeService に $e->getMessage() を戻すと赤くなる',
+    'M8' => '観測目録から AutoRechargeService を消すと未分類で赤くなる',
+    'M9' => '免除 cap を書き換えると赤くなる',
+    'M10' => 'context() の呼び出しを 1 つ削ると出現回数の exact fit が赤くなる',
+    'M11' => '免除クラス (伝播すると宣言) に catch を足すと前提検査が赤くなる',
+];
+
+/** @return array<class-string, GatewayObservationEntry> */
+function billingGatewayObservers(): array
+{
+    return [
+        AutoRechargeService::class => new GatewayObservationEntry(
+            // ★メソッド名 => そのメソッド内で期待する context() 呼び出し回数。
+            //   ファイル全体の出現回数ではなく**メソッド単位**で検査する
+            //   (ファイル総数だとコメント / 別文脈でも数が合えば green になる)。
+            catchSites: [
+                'terminateInvoiceBestEffort' => 1,  // 所有権喪失後の後始末 (T131 新設)
+                'tryTerminateInvoice' => 1,         // 停止側の invoice 終端
+                'reconcile' => 2,                   // attempt 隔離 + 取りこぼし起票
+            ],
+            rawMessageCap: 0,
+            rationale: 'gateway 例外を catch して観測へ落とす唯一のクラス。4 箇所すべてが '
+                .'GatewayFailureClassifier::context() の 2 キーだけを載せ、例外 message は載せない。'
+                .'rawMessageCap=0 は gateway 観測点だけでなく**クラス全体**に掛かる設計制約である '
+                .'(本クラスが受ける例外は gateway 以外も外部由来を含みうるため。'
+                .'catch の近傍だけに限定すると走査が脆くなる)。'
+                .'通知送信失敗を受ける applySetupCompletion / applyReusedPaymentMethod の '
+                .'catch は gateway を消費しないため catchSites の対象外。',
+        ),
+    ];
+}
+
+/** @return array<class-string, GatewayObservationExemptionEntry> */
+function billingGatewayObservationExemptions(): array
+{
+    return [
+        SetDefaultPaymentMethodJob::class => new GatewayObservationExemptionEntry(
+            GatewayFailureObservationExemption::PropagatesToQueueFailure,
+            'gateway 例外を catch せず伝播させる。失敗は queue の再試行と failed_jobs に載り、'
+            .'そこには vendor 例外の message が残る (本設計の保証範囲は AutoRechargeService の'
+            .'構造化ログと report 文言までであり、伝播先の redact は横断基盤の話でスコープ外)。',
+        ),
+        ReuseSubscriptionPaymentMethodJob::class => new GatewayObservationExemptionEntry(
+            GatewayFailureObservationExemption::PropagatesToQueueFailure,
+            'gateway 例外 (resolveSubscriptionPaymentMethod) を catch せず伝播させる。'
+            .'失敗は queue の再試行と failed_jobs に載り、そこには vendor 例外の message が残る。'
+            .'サブスク PM 再利用は失敗しても業務が止まらない補助経路であり、'
+            .'観測点をここに増やすと語彙の集約点が割れる。',
+        ),
+        HandleAutoRechargeChargeFailureJob::class => new GatewayObservationExemptionEntry(
+            GatewayFailureObservationExemption::PropagatesToQueueFailure,
+            'gateway 例外 (retrieveInvoiceState / terminateInvoice) を catch せず伝播させる。'
+            .'失敗は queue の再試行と failed_jobs に載り、そこには vendor 例外の message が残る。'
+            .'終端の再試行はキューに委ね、本 Job 内で握り潰さない (fail-closed)。',
+        ),
+    ];
+}
+
+function billingGatewayObservationExemptionCap(): int
+{
+    return 3; // exact fit
+}
+
+/**
+ * 非 vendor の明示宣言クラス (期待値の正本。分類器の写像表とは**独立した宣言**)。
+ *
+ * ★framework 由来に限定しない。`unknown` の運用契約 (出たクラスは必ず写像表へ足す) により、
+ *   将来アプリ自身の例外クラスがここへ入りうる。
+ *
+ * @return list<class-string<Throwable>>
+ */
+function billingNonVendorExplicitClasses(): array
+{
+    return [
+        QueryException::class,
+        LockTimeoutException::class,           // Illuminate\Contracts\Cache\LockTimeoutException (具象クラス)
+        AssertInvalidArgumentException::class,
+    ];
+}
+
+function billingNonVendorExplicitCap(): int
+{
+    return 3; // exact fit
+}
+
+/** `Stripe\Exception\` を import してよい app クラス (集約点の allowlist)。 */
+function billingStripeExceptionImportAllowlist(): array
+{
+    return [
+        CashierAutoRechargeGateway::class,
+        CashierStripeGateway::class,
+        GatewayFailureClassifier::class,
+        StripeScheduleGateway::class,
+    ];
+}
+
+/** クラスのソースを読む (Reflection で実ファイルを特定する)。 */
+function billingGatewaySourceOf(string $class): string
+{
+    $path = (new ReflectionClass($class))->getFileName();
+    Assert::string($path, "{$class}: ソースファイルを特定できません");
+    $source = file_get_contents($path);
+    Assert::string($source, "{$class}: ソースを読み込めません");
+
+    return $source;
+}
+
+/**
+ * ソースが `Stripe\Exception\` を**コード上で**参照しているか。
+ *
+ * ★PHP 同梱の tokenizer で走査し、コメント / docblock を除外する
+ *   (gateway interface の docblock が Stripe 例外型に言及するのは「知っている」ことにならない)。
+ * ★名前トークンだけでなく文字列リテラルも対象にする
+ *   (`class_exists('Stripe\Exception\X')` のような回避を許さない)。
+ */
+function billingSourceReferencesStripeException(string $source): bool
+{
+    foreach (token_get_all($source) as $token) {
+        if (! is_array($token)) {
+            continue;
+        }
+        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
+            continue;
+        }
+        if (str_contains($token[1], 'Stripe\\Exception\\')) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/** メソッド本体のソースを行範囲で切り出す (AST を使わない割り切り)。 */
+function billingGatewayMethodSource(string $class, string $method): string
+{
+    $reflection = new ReflectionMethod($class, $method);
+    $start = $reflection->getStartLine();
+    $end = $reflection->getEndLine();
+    Assert::integer($start, "{$class}::{$method}: 開始行を特定できません");
+    Assert::integer($end, "{$class}::{$method}: 終了行を特定できません");
+
+    $lines = explode("\n", billingGatewaySourceOf($class));
+
+    return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
+}
+
+// ---------------------------------------------------------------------------
+// 検査 1〜5: 観測目録 / 免除の deny-by-default
+// ---------------------------------------------------------------------------
+
+test('検査 1: gateway を注入される app クラスが全件分類されている (未分類は fail)', function (): void {
+    $scanned = GatewayConsumerPopulation::classes();
+    $classified = array_merge(
+        array_keys(billingGatewayObservers()),
+        array_keys(billingGatewayObservationExemptions()),
+    );
+    sort($classified);
+
+    $missing = array_values(array_diff($scanned, $classified));
+    $stale = array_values(array_diff($classified, $scanned));
+
+    expect($missing)->toBe([], '未分類の gateway 消費クラスがある: '.implode(', ', $missing));
+    expect($stale)->toBe([], '目録に実在しないクラスが残っている: '.implode(', ', $stale));
+
+    // ★走査の縮み検出 (母集団が空に落ちても green にならない)
+    expect($scanned)->toContain(AutoRechargeService::class);
+    expect($scanned)->toContain(SetDefaultPaymentMethodJob::class);
+});
+
+test('検査 2: 観測目録と免除は排他 (同じクラスが両方に居ない)', function (): void {
+    $both = array_intersect(
+        array_keys(billingGatewayObservers()),
+        array_keys(billingGatewayObservationExemptions()),
+    );
+
+    expect(array_values($both))->toBe([]);
+});
+
+test('検査 3: 免除件数が cap と一致する (形骸化ガード)', function (): void {
+    expect(count(billingGatewayObservationExemptions()))->toBe(
+        billingGatewayObservationExemptionCap(),
+        '免除件数が宣言と一致しません。増減させたら billingGatewayObservationExemptionCap() も書き換えること',
+    );
+});
+
+test('検査 4: 目録・免除の根拠が 30 文字以上 (constructor と gate の二重固定)', function (): void {
+    foreach (billingGatewayObservers() as $class => $entry) {
+        expect(mb_strlen($entry->rationale))->toBeGreaterThanOrEqual(30, "{$class}: 観測目録の根拠が短すぎます");
+    }
+
+    foreach (billingGatewayObservationExemptions() as $class => $entry) {
+        expect(mb_strlen($entry->rationale))->toBeGreaterThanOrEqual(30, "{$class}: 免除の根拠が短すぎます");
+    }
+});
+
+test('検査 5: catchSites のキーが実在メソッドで、期待回数が 1 以上', function (): void {
+    foreach (billingGatewayObservers() as $class => $entry) {
+        $reflection = new ReflectionClass($class);
+        foreach ($entry->catchSites as $method => $expected) {
+            expect($reflection->hasMethod($method))->toBeTrue("{$class}::{$method} が実在しません");
+            expect($expected)->toBeGreaterThanOrEqual(1, "{$class}::{$method}: 期待回数は 1 以上で宣言すること");
+        }
+    }
+});
+
+// ---------------------------------------------------------------------------
+// 検査 6〜7: 観測点の形 (message を載せない / 分類器を必ず通す)
+// ---------------------------------------------------------------------------
+
+test('検査 6: 観測目録のクラスは例外 message をログへ載せない (getMessage の cap と一致)', function (): void {
+    foreach (billingGatewayObservers() as $class => $entry) {
+        expect(substr_count(billingGatewaySourceOf($class), 'getMessage()'))->toBe(
+            $entry->rawMessageCap,
+            "{$class}: getMessage() の出現件数が rawMessageCap と一致しません",
+        );
+    }
+});
+
+test('検査 7a: catchSites の各メソッドが catch を持ち、context() の回数が宣言と一致する', function (): void {
+    foreach (billingGatewayObservers() as $class => $entry) {
+        foreach ($entry->catchSites as $method => $expected) {
+            $source = billingGatewayMethodSource($class, $method);
+
+            expect(str_contains($source, 'catch ('))->toBeTrue("{$class}::{$method}: catch がありません");
+            expect(substr_count($source, 'GatewayFailureClassifier::context('))->toBe(
+                $expected,
+                "{$class}::{$method}: GatewayFailureClassifier::context() の回数が宣言と一致しません",
+            );
+        }
+    }
+});
+
+test('検査 7b: ファイル全体の context() 回数が catchSites の総和と一致する', function (): void {
+    foreach (billingGatewayObservers() as $class => $entry) {
+        expect(substr_count(billingGatewaySourceOf($class), 'GatewayFailureClassifier::context('))->toBe(
+            array_sum($entry->catchSites),
+            "{$class}: 宣言外のメソッドで context() を呼んでいます (catchSites を更新すること)",
+        );
+    }
+});
+
+// ---------------------------------------------------------------------------
+// 検査 8〜13: 分類語彙の全域性 (vendor 走査 gate)
+// ---------------------------------------------------------------------------
+
+test('検査 8: 写像表と条件付き規則は排他', function (): void {
+    $both = array_intersect(
+        array_keys(GatewayFailureClassifier::directMap()),
+        GatewayFailureClassifier::conditionalClasses(),
+    );
+
+    expect(array_values($both))->toBe([]);
+});
+
+test('検査 9: 分類対象の集合が vendor 母集団 + 非 vendor 明示宣言と一致する', function (): void {
+    $classified = array_merge(
+        array_keys(GatewayFailureClassifier::directMap()),
+        GatewayFailureClassifier::conditionalClasses(),
+    );
+    sort($classified);
+
+    $expected = array_merge(VendorExceptionPopulation::classes(), billingNonVendorExplicitClasses());
+    sort($expected);
+
+    $missing = array_values(array_diff($expected, $classified));
+    $stale = array_values(array_diff($classified, $expected));
+
+    expect($missing)->toBe(
+        [],
+        '未分類の例外クラスがある (composer update で vendor の語彙が増えた可能性がある。'
+        .'復旧手順は docs/architecture.md §オートリチャージの失敗分類): '.implode(', ', $missing),
+    );
+    expect($stale)->toBe([], '実在しない / 母集団外のクラスが写像表に残っている: '.implode(', ', $stale));
+});
+
+test('検査 10: 条件付き規則のクラスがクラス同一性で固定されている', function (): void {
+    expect(GatewayFailureClassifier::conditionalClasses())->toBe([UnknownApiErrorException::class]);
+});
+
+test('検査 11: 写像表の値に Unknown が現れない (unknown は写像の不在専用)', function (): void {
+    $unknown = array_keys(array_filter(
+        GatewayFailureClassifier::directMap(),
+        static fn (GatewayFailureClass $case): bool => $case === GatewayFailureClass::Unknown,
+    ));
+
+    expect($unknown)->toBe(
+        [],
+        'unknown は「写像表に一致が無かった」ことの通知であり、表の値として使ってはならない: '
+        .implode(', ', $unknown),
+    );
+});
+
+test('検査 12: 非 vendor 明示宣言の件数が cap と一致する', function (): void {
+    expect(count(billingNonVendorExplicitClasses()))->toBe(billingNonVendorExplicitCap());
+});
+
+test('検査 13: vendor 母集団の除外宣言がサブディレクトリ集合と一致し、母集団と交差しない', function (): void {
+    $stripeDir = base_path('vendor/stripe/stripe-php/lib/Exception');
+
+    // (a) 実サブディレクトリ集合 == 除外宣言のキー集合
+    $declared = array_keys(VendorExceptionPopulation::EXCLUDED_STRIPE_SUBNAMESPACES);
+    sort($declared);
+    expect(VendorExceptionPopulation::subdirectories($stripeDir))->toBe(
+        $declared,
+        'Stripe SDK がサブ名前空間を増減させました。母集団定義 (EXCLUDED_STRIPE_SUBNAMESPACES) を再検討すること',
+    );
+
+    // (b) 除外理由は 30 文字以上
+    foreach (VendorExceptionPopulation::EXCLUDED_STRIPE_SUBNAMESPACES as $sub => $reason) {
+        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(30, "{$sub}: 除外理由は 30 文字以上で書くこと");
+    }
+
+    // (c) 直下母集団の各クラスが除外名前空間に属さない (集合の非交差)
+    foreach (VendorExceptionPopulation::stripeClasses() as $class) {
+        foreach ($declared as $sub) {
+            expect(str_starts_with($class, 'Stripe\\Exception\\'.$sub.'\\'))->toBeFalse(
+                "{$class}: 除外宣言した名前空間のクラスが母集団へ混入しています",
+            );
+        }
+    }
+
+    // (d) 走査の縮み検出 (代表クラス)
+    expect(VendorExceptionPopulation::stripeClasses())->toContain(ApiConnectionException::class);
+    expect(VendorExceptionPopulation::cashierClasses())->toContain(IncompletePayment::class);
+});
+
+// ---------------------------------------------------------------------------
+// 検査 14〜18: fake / spy の parity
+// ---------------------------------------------------------------------------
+
+test('検査 14: fixture の case 集合が業務 4 case (cases() - Unknown) と一致する', function (): void {
+    $expected = array_values(array_filter(
+        GatewayFailureClass::cases(),
+        static fn (GatewayFailureClass $case): bool => $case !== GatewayFailureClass::Unknown,
+    ));
+
+    expect(GatewayFailureFixtures::parityCases())->toBe($expected);
+    expect(GatewayFailureFixtures::parityCases())->toHaveCount(count(GatewayFailureClass::cases()) - 1);
+});
+
+test('検査 15: fixture が返す例外の分類が宣言 case と一致する (fake/real parity)', function (): void {
+    foreach (GatewayFailureFixtures::parityCases() as $case) {
+        $throwable = GatewayFailureFixtures::throwableFor($case);
+
+        expect(GatewayFailureClassifier::classify($throwable))->toBe(
+            $case,
+            "{$case->value}: fixture の例外が宣言と違う分類になります (".$throwable::class.')',
+        );
+    }
+});
+
+test('検査 16: fixture が返すクラスが実ライブラリ名前空間に属する', function (): void {
+    foreach (GatewayFailureFixtures::parityCases() as $case) {
+        $class = GatewayFailureFixtures::throwableFor($case)::class;
+
+        $allowed = false;
+        foreach (GatewayFailureFixtures::ALLOWED_NAMESPACE_PREFIXES as $prefix) {
+            if (str_starts_with($class, $prefix)) {
+                $allowed = true;
+
+                break;
+            }
+        }
+
+        expect($allowed)->toBeTrue(
+            "{$case->value}: fixture が実ライブラリ以外のクラス ({$class}) を返しています。"
+            .'独自例外を投げると本物との分類 parity が崩れる',
+        );
+    }
+});
+
+test('検査 17: spy の throw がすべて fixture 経由である', function (): void {
+    $source = billingGatewaySourceOf(FakeAutoRechargeGateway::class);
+
+    expect(substr_count($source, 'throw GatewayFailureFixtures::throwableFor('))->toBe(
+        substr_count($source, 'throw '),
+        'spy が fixture を経由しない throw を持っています (本物との分類 parity が崩れる)',
+    );
+});
+
+test('検査 17b: 全 fixture の message に外部生成文字列の目印が含まれる', function (): void {
+    // ★negative assertion (「ログにマーカーが含まれない」) が空虚に green にならないための前提保証。
+    foreach (GatewayFailureFixtures::parityCases() as $case) {
+        $message = GatewayFailureFixtures::throwableFor($case)->getMessage();
+
+        expect(str_contains($message, GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER))->toBeTrue(
+            "{$case->value}: fixture の message にマーカーが入っていません",
+        );
+    }
+});
+
+test('検査 17c: 旧 API 名が tests/ 配下に残っていない (後方互換の並走を残さない)', function (): void {
+    // ★除外は文字列一致ではなく realpath で正規化して比較する (自己検出の回避)。
+    $self = realpath(__FILE__);
+    Assert::string($self, '自ファイルの realpath を解決できません');
+
+    $legacyNames = ['failOnTerminate', 'failOnResolveSubscriptionPaymentMethod'];
+    $violations = [];
+
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator(base_path('tests'), FilesystemIterator::SKIP_DOTS),
+    );
+    foreach ($iterator as $file) {
+        Assert::isInstanceOf($file, SplFileInfo::class);
+        if (! $file->isFile() || $file->getExtension() !== 'php') {
+            continue;
+        }
+
+        $path = realpath($file->getPathname());
+        Assert::string($path, 'テストファイルの realpath を解決できません');
+        if ($path === $self) {
+            continue; // 本ファイルはリテラルの正本
+        }
+
+        $source = file_get_contents($path);
+        Assert::string($source, "ソースを読み込めません: {$path}");
+
+        foreach ($legacyNames as $name) {
+            if (str_contains($source, $name)) {
+                $violations[] = $path.' => '.$name;
+            }
+        }
+    }
+
+    sort($violations);
+
+    expect($violations)->toBe([], '旧 API 名が残っています: '.implode(', ', $violations));
+});
+
+test('検査 18: runtime fake は例外を 1 つも投げない', function (): void {
+    $source = billingGatewaySourceOf(App\Services\Billing\Fakes\FakeAutoRechargeGateway::class);
+
+    expect(substr_count($source, 'throw '))->toBe(
+        0,
+        'runtime fake (fake_externals 環境) は例外を投げない契約である',
+    );
+});
+
+test('検査 21: 免除クラスは宣言どおり例外を伝播させる (catch を持たない)', function (): void {
+    // ★件数と根拠長だけを見る gate は、後から catch (Throwable) を足して getMessage() を
+    //   ログへ載せても green のままになる。**免除の前提そのもの**を機械で固定する
+    //   (AGENTS.md の ThrottleExemptionPremiseTest と同じ作法。impl-review Round 1 反映)。
+    // ★保守的な近似である: gateway 呼び出しを囲む catch かどうかは判定せず、
+    //   クラス全体で `catch (` が 0 件であることを要求する。catch を足したくなったら
+    //   観測目録へ移すか、新しい免除 case を根拠付きで足すこと (どちらも差分に必ず現れる)。
+    foreach (billingGatewayObservationExemptions() as $class => $entry) {
+        if ($entry->exemption !== GatewayFailureObservationExemption::PropagatesToQueueFailure) {
+            continue;
+        }
+
+        expect(substr_count(billingGatewaySourceOf($class), 'catch ('))->toBe(
+            0,
+            "{$class}: 「伝播させる」と免除宣言しているのに catch があります。"
+            .'観測目録へ移すか、免除の分類を見直すこと',
+        );
+    }
+});
+
+// ---------------------------------------------------------------------------
+// 検査 19〜20: 集約点と mutation coverage
+// ---------------------------------------------------------------------------
+
+test('検査 19: Stripe 例外型を参照する app クラスが allowlist と一致する', function (): void {
+    // ★`use` 文だけを見ると、完全修飾名 (`\Stripe\Exception\InvalidRequestException::class`) や
+    //   文字列リテラルでの参照が allowlist を回避できる (impl-review Round 1 反映)。
+    //   PHP 同梱の `token_get_all()` (tokenizer。vendor 依存を増やさない) で
+    //   **コメント / docblock を除いた**トークンだけを走査する。
+    $found = [];
+    foreach (QueuedJobPopulation::appPhpFiles() as $path) {
+        $source = file_get_contents($path);
+        Assert::string($source, "ソースを読み込めません: {$path}");
+
+        if (! billingSourceReferencesStripeException($source)) {
+            continue;
+        }
+
+        $found[] = QueuedJobPopulation::classNameForPath($path);
+    }
+    sort($found);
+
+    $allowlist = billingStripeExceptionImportAllowlist();
+    sort($allowlist);
+
+    expect($found)->toBe(
+        $allowlist,
+        'Stripe 例外型を知るクラスは gateway 実装 + GatewayFailureClassifier に閉じる '
+        .'(集約点が増えると観測語彙が割れる)',
+    );
+});
+
+test('検査 20: mutation coverage 表のキー集合が想定 ID 集合と一致する', function (): void {
+    expect(array_keys(BILLING_GATEWAY_TAXONOMY_MUTATION_COVERAGE))
+        ->toBe(['M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'M8', 'M9', 'M10', 'M11']);
+
+    foreach (BILLING_GATEWAY_TAXONOMY_MUTATION_COVERAGE as $id => $description) {
+        expect(mb_strlen($description))->toBeGreaterThanOrEqual(10, "{$id}: mutation の説明が短すぎます");
+    }
+});
diff --git a/tests/Feature/Billing/AutoRechargeReconcileTest.php b/tests/Feature/Billing/AutoRechargeReconcileTest.php
index a2fecfd..7b0f81a 100644
--- a/tests/Feature/Billing/AutoRechargeReconcileTest.php
+++ b/tests/Feature/Billing/AutoRechargeReconcileTest.php
@@ -6,6 +6,7 @@
 use App\DataTransferObjects\Billing\InvoiceStateDto;
 use App\Enums\Billing\AutoRechargeAttemptStatus;
 use App\Enums\Billing\BillingNotificationType;
+use App\Enums\Billing\GatewayFailureClass;
 use App\Models\Billing\BillingNotification;
 use App\Models\Billing\TicketAutoRechargeAttempt;
 use App\Models\Billing\TicketLedgerEntry;
@@ -15,7 +16,12 @@
 use Carbon\CarbonImmutable;
 use Illuminate\Console\Scheduling\Event;
 use Illuminate\Console\Scheduling\Schedule;
+use Illuminate\Database\QueryException;
+use Illuminate\Support\Facades\Log;
 use Illuminate\Support\Facades\Queue;
+use Illuminate\Support\Facades\Schema;
+use Stripe\Exception\ApiConnectionException;
+use Tests\Support\Billing\GatewayFailureFixtures;
 use Tests\Support\FakeAutoRechargeGateway;
 
 /*
@@ -166,6 +172,74 @@
     expect($good->fresh()->status)->toBe(AutoRechargeAttemptStatus::Paid);
 });
 
+test('隔離ログに失敗分類が載る (gateway 例外 → provider_unavailable)', function (): void {
+    // ★分類は観測のためであり制御フローを変えない: 隔離は現行どおり続行する。
+    Log::spy();
+    [$organization] = createOrganizationWithOwner();
+    $attempt = TicketAutoRechargeAttempt::factory()->for($organization)->create([
+        'created_at' => CarbonImmutable::now()->subMinutes(20),
+    ]);
+    enableAutoRecharge($organization);
+    // 本物の gateway が伝播させる実ライブラリ例外を invoice 作成中に注入する
+    $this->gateway->duringCreateInvoice = function (): void {
+        throw GatewayFailureFixtures::throwableFor(GatewayFailureClass::ProviderUnavailable);
+    };
+
+    $stats = app(AutoRechargeService::class)->reconcile();
+
+    // 隔離されるので reconcile 自体は例外を投げず、attempt は pending のまま次周期へ回る
+    expect($stats['retried'])->toBe(0);
+    expect($attempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Pending);
+
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context): bool {
+            if ($message !== 'auto-recharge reconcile: attempt processing failed') {
+                return false;
+            }
+
+            return $context['failure_class'] === 'provider_unavailable'
+                && $context['error_class'] === ApiConnectionException::class
+                && ! str_contains(
+                    json_encode($context, JSON_THROW_ON_ERROR),
+                    GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER,
+                );
+        })
+        ->once();
+});
+
+test('取りこぼし起票ログに失敗分類が載る (DB 例外 → local_failure)', function (): void {
+    Log::spy();
+    [$organization] = createOrganizationWithOwner();
+    enableAutoRecharge($organization);
+
+    // 単価表を実際に引けなくして DB 例外 (QueryException) を起こす。
+    // ★注入点の選択理由: 取りこぼし起票の catch は `maybeCreateAttempt()` の内側で起きる
+    //   DB 失敗しか受けず、AutoRechargeService は final なので差し替えられない。
+    //   実 DB を一時的に壊すのが「QueryException が実際に上がる」唯一の素直な作り方である。
+    // ★後片付けは 2 重: 明示的に rename を戻し、さらに RefreshDatabase の
+    //   トランザクション巻き戻しでも復元される。
+    Schema::rename('ticket_volume_prices', 'ticket_volume_prices_missing');
+
+    try {
+        $stats = app(AutoRechargeService::class)->reconcile();
+    } finally {
+        Schema::rename('ticket_volume_prices_missing', 'ticket_volume_prices');
+    }
+
+    expect($stats['triggered'])->toBe(0);
+
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context): bool {
+            if ($message !== 'auto-recharge reconcile: trigger failed') {
+                return false;
+            }
+
+            return $context['failure_class'] === 'local_failure'
+                && $context['error_class'] === QueryException::class;
+        })
+        ->once();
+});
+
 test('リコンサイルコマンドは 0 で終了し統計を出力する', function (): void {
     $this->artisan('billing:reconcile-auto-recharge')
         ->expectsOutputToContain('auto-recharge reconcile:')
diff --git a/tests/Feature/Billing/AutoRechargeServiceTest.php b/tests/Feature/Billing/AutoRechargeServiceTest.php
index 462237f..7ad868c 100644
--- a/tests/Feature/Billing/AutoRechargeServiceTest.php
+++ b/tests/Feature/Billing/AutoRechargeServiceTest.php
@@ -7,6 +7,7 @@
 use App\Enums\Billing\AutoRechargeAttemptStatus;
 use App\Enums\Billing\AutoRechargeDisabledReason;
 use App\Enums\Billing\BillingNotificationType;
+use App\Enums\Billing\GatewayFailureClass;
 use App\Enums\Billing\TicketLedgerKind;
 use App\Enums\Security\ExternalCallKind;
 use App\Models\Billing\BillingNotification;
@@ -23,6 +24,9 @@
 use Illuminate\Support\Facades\Exceptions;
 use Illuminate\Support\Facades\Log;
 use Illuminate\Validation\ValidationException;
+use Stripe\Exception\ApiConnectionException;
+use Stripe\Exception\InvalidRequestException;
+use Tests\Support\Billing\GatewayFailureFixtures;
 use Tests\Support\FakeAttemptOwnershipPreflight;
 use Tests\Support\FakeAutoRechargeGateway;
 
@@ -296,7 +300,7 @@ function grantTickets(Organization $organization, int $amount): void
 
     $attempt = $service->maybeCreateAttempt($organization);
     $attempt->forceFill(['stripe_invoice_id' => 'in_stuck'])->save();
-    $gateway->failOnTerminate = true;
+    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;
 
     $service->terminateAndFail($organization, $attempt);
 
@@ -634,7 +638,7 @@ function autoRechargePendingAttempt(
     $gateway->withDefaultPaymentMethod();
     $attempt = autoRechargePendingAttempt($organization, $owner, $service);
     $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
-    $gateway->failOnTerminate = true;
+    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;
 
     $service->executeAttempt($attempt);
 
@@ -658,11 +662,16 @@ function autoRechargePendingAttempt(
             }
             $keys = array_keys($context);
             sort($keys);
-            $expected = ['attempt_ulid', 'error', 'event', 'invoice_id', 'job_id', 'job_type', 'terminated'];
+            $expected = [
+                'attempt_ulid', 'error_class', 'event', 'failure_class',
+                'invoice_id', 'job_id', 'job_type', 'terminated',
+            ];
 
             return $keys === $expected
                 && $context['terminated'] === true
-                && $context['error'] === null
+                // ★成功時も 2 キーは null で存在する (集計 schema を成否で割らない)
+                && $context['failure_class'] === null
+                && $context['error_class'] === null
                 && $context['attempt_ulid'] === $attempt->attempt_ulid;
         })
         ->once();
@@ -674,7 +683,7 @@ function autoRechargePendingAttempt(
         ->once();
 });
 
-test('後始末ログの error は例外クラス名のみで、外部由来のメッセージを含まない', function (): void {
+test('後始末のログに外部由来のメッセージを載せない (分類 + 例外クラス名のみ)', function (): void {
     // Stripe SDK の例外メッセージは外部サービスが生成する可変文字列であり、構造化ログの
     // 集計語彙へ流さない。
     Log::spy();
@@ -682,7 +691,8 @@ function autoRechargePendingAttempt(
     $gateway->withDefaultPaymentMethod();
     $attempt = autoRechargePendingAttempt($organization, $owner, $service);
     $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
-    $gateway->failOnTerminate = true; // メッセージ「fake gateway: invoice 終端失敗」で throw する
+    // 本物の gateway が伝播させる実ライブラリ例外を投げる (message にマーカーが入る)
+    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;
 
     $service->executeAttempt($attempt);
 
@@ -693,13 +703,19 @@ function autoRechargePendingAttempt(
             }
 
             return $context['terminated'] === false
-                && $context['error'] === RuntimeException::class
-                && ! str_contains((string) $context['error'], 'fake gateway');
+                && $context['failure_class'] === 'provider_unavailable'
+                && $context['error_class'] === ApiConnectionException::class
+                // ★マーカー非含有。gate が「fixture の message にマーカーが確かに入る」ことを
+                //   保証しているため、この negative assertion は空虚にならない。
+                && ! str_contains(
+                    json_encode($context, JSON_THROW_ON_ERROR),
+                    GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER,
+                );
         })
         ->once();
 });
 
-test('後始末の例外報告にも外部由来のメッセージを渡さない (サニタイズ済み例外のみ)', function (): void {
+test('後始末の例外報告は固定テンプレートと完全一致する (外部由来のメッセージを渡さない)', function (): void {
     // 「構造化ログに載せない」だけでは不十分 — 標準の exception handler は message と
     // スタックトレースを記録するため、原例外をそのまま report() すると保存場所が移るだけになる。
     Exceptions::fake();
@@ -707,17 +723,24 @@ function autoRechargePendingAttempt(
     $gateway->withDefaultPaymentMethod();
     $attempt = autoRechargePendingAttempt($organization, $owner, $service);
     $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
-    $gateway->failOnTerminate = true;
+    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;
 
     $service->executeAttempt($attempt);
 
-    Exceptions::assertReported(function (RuntimeException $reported): bool {
-        return str_contains($reported->getMessage(), 'の終端に失敗しました')
-            // 外部 (fake gateway = Stripe SDK 相当) が生成した文字列を含まない
-            && ! str_contains($reported->getMessage(), 'fake gateway')
-            // previous chain も繋がない (reporter が previous を出力しうるため)
-            && $reported->getPrevious() === null;
-    });
+    // ★部分一致をやめ**完全一致**で固定する (予期しない文字列の追加を必ず検出する)。
+    //   invoice_id は pay preflight より前に永続化されているため DB から取れる。
+    $invoiceId = $attempt->refresh()->stripe_invoice_id;
+    expect($invoiceId)->not->toBeNull();
+    $expected = sprintf(
+        'auto-recharge: invoice %s の終端に失敗しました (%s / %s)',
+        $invoiceId,
+        'provider_unavailable',
+        ApiConnectionException::class,
+    );
+
+    Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported->getMessage() === $expected
+        // previous chain も繋がない (reporter が previous を出力しうるため)
+        && $reported->getPrevious() === null);
     Exceptions::assertReportedCount(1);
 });
 
@@ -781,13 +804,109 @@ function autoRechargePendingAttempt(
     $gateway->withDefaultPaymentMethod();
     $attempt = autoRechargePendingAttempt($organization, $owner, $service);
     $attempt->forceFill(['stripe_invoice_id' => 'in_stuck_precondition'])->save();
-    $gateway->failOnTerminate = true;
+    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;
 
     $service->terminateAndFail($organization, $attempt);
 
     expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Pending);
 });
 
+/** 所有権喪失 → 後始末までを 1 シナリオ実行する (cleanup ログの発生源)。 */
+function autoRechargeRunCleanupScenario(?GatewayFailureClass $terminateFailure): void
+{
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+    $gateway->terminateFailure = $terminateFailure;
+
+    $service->executeAttempt($attempt);
+}
+
+test('cleanup event のキー集合が成功・失敗の両方で同一である (集計 schema を成否で割らない)', function (): void {
+    // ★Log::spy() は既に mock 済みなら再作成しないため、1 本の spy で 2 シナリオを記録する。
+    Log::spy();
+
+    autoRechargeRunCleanupScenario(null);                                        // 終端成功
+    autoRechargeRunCleanupScenario(GatewayFailureClass::ProviderUnavailable);     // 終端失敗
+
+    $contexts = [];
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context) use (&$contexts): bool {
+            if (($context['event'] ?? null) !== ExternalCallKind::CLEANUP_LOG_EVENT) {
+                return false;
+            }
+            // ★Mockery は照合と件数検証で closure を複数回呼ぶため、成否をキーにして
+            //   冪等に記録する (append だと重複して数が合わない)。
+            $contexts[$context['terminated'] === true ? 'success' : 'failure'] = $context;
+
+            return true;
+        })
+        ->twice();
+
+    expect(array_keys($contexts))->toEqualCanonicalizing(['success', 'failure']);
+    $success = $contexts['success'];
+    $failure = $contexts['failure'];
+
+    expect(array_keys($success))->toBe(array_keys($failure));
+    // 成功時も 2 キーは **null で存在**する
+    expect($success['terminated'])->toBeTrue()
+        ->and($success['failure_class'])->toBeNull()
+        ->and($success['error_class'])->toBeNull();
+    expect($failure['terminated'])->toBeFalse()
+        ->and($failure['failure_class'])->toBe('provider_unavailable')
+        ->and($failure['error_class'])->toBe(ApiConnectionException::class);
+});
+
+test('制御フロー等価性: 分類ログを出しても収束先と gateway 呼び出し回数が変わらない', function (): void {
+    // ★分類は**観測のため**であり課金の振る舞いを変えない。終端失敗時の収束先
+    //   (pending 維持) と gateway 呼び出し回数を明示的に固定する。
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;
+
+    $service->executeAttempt($attempt);
+
+    // 所有権喪失で canceled 化済み (preflight が terminal 化させた側の結果)
+    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Canceled);
+    // 終端は失敗したので terminated 配列は空のまま / 課金 (pay) には進まない
+    expect($gateway->terminated)->toBe([]);
+    expect($gateway->payCalls)->toBe([]);
+    expect($gateway->createdInvoices)->toHaveCount(1);
+    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::Grant)->count())->toBe(0);
+});
+
+test('停止側の終端失敗ログにも分類が載る (message は載らない)', function (): void {
+    // tryTerminateInvoice の catch。制御フローは現行のまま (pending 維持)。
+    Log::spy();
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $attempt->forceFill(['stripe_invoice_id' => 'in_try_terminate'])->save();
+    $gateway->terminateFailure = GatewayFailureClass::ProviderRejected;
+
+    $service->terminateAndCancel($attempt);
+
+    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Pending);
+
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context): bool {
+            if ($message !== 'auto-recharge: invoice termination failed, keeping attempt pending') {
+                return false;
+            }
+
+            return $context['failure_class'] === 'provider_rejected'
+                && $context['error_class'] === InvalidRequestException::class
+                && ! str_contains(
+                    json_encode($context, JSON_THROW_ON_ERROR),
+                    GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER,
+                );
+        })
+        ->once();
+});
+
 test('冪等キーは 2 本ある: 同一 invoice の付与は台帳 1 件・attempt 遷移も 1 回', function (): void {
     [$organization, $owner, $gateway, $service] = autoRechargeSetup();
     $gateway->withDefaultPaymentMethod();
diff --git a/tests/Support/Billing/GatewayConsumerPopulation.php b/tests/Support/Billing/GatewayConsumerPopulation.php
new file mode 100644
index 0000000..df44605
--- /dev/null
+++ b/tests/Support/Billing/GatewayConsumerPopulation.php
@@ -0,0 +1,67 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use ReflectionClass;
+use ReflectionMethod;
+use ReflectionNamedType;
+use Tests\Support\QueuedJobPopulation;
+
+/**
+ * 「決済 gateway (`AutoRechargeGatewayInterface`) を注入される app クラス」の母集団を決める唯一の実装。
+ *
+ * ★判定は **constructor と全メソッドの引数型**に interface が現れることだけを見る
+ *   (`QueuedJobPopulation` と同じ作法で `app/` を走査 → PSR-4 → `class_exists()` → Reflection)。
+ *   gateway の**実装クラス** (`implements AutoRechargeGatewayInterface`) は
+ *   「注入される側」ではないので母集団に入らない。
+ * ★**走査の縮み**は gate の代表クラス検査で拾う (母集団が 0 件に落ちても green にならない)。
+ */
+final class GatewayConsumerPopulation
+{
+    /** @return list<class-string> */
+    public static function classes(): array
+    {
+        $classes = [];
+        foreach (QueuedJobPopulation::appPhpFiles() as $path) {
+            $class = QueuedJobPopulation::classNameForPath($path);
+            if (! class_exists($class)) {
+                continue;
+            }
+
+            $reflection = new ReflectionClass($class);
+            if (! self::injectsGateway($reflection)) {
+                continue;
+            }
+
+            $classes[] = $reflection->getName();
+        }
+
+        sort($classes);
+
+        return $classes;
+    }
+
+    /** @param ReflectionClass<object> $reflection */
+    private static function injectsGateway(ReflectionClass $reflection): bool
+    {
+        $methods = $reflection->getMethods();
+        $constructor = $reflection->getConstructor();
+        if ($constructor instanceof ReflectionMethod) {
+            $methods[] = $constructor;
+        }
+
+        foreach ($methods as $method) {
+            foreach ($method->getParameters() as $parameter) {
+                $type = $parameter->getType();
+                if ($type instanceof ReflectionNamedType && $type->getName() === AutoRechargeGatewayInterface::class) {
+                    return true;
+                }
+            }
+        }
+
+        return false;
+    }
+}
diff --git a/tests/Support/Billing/GatewayFailureFixtures.php b/tests/Support/Billing/GatewayFailureFixtures.php
new file mode 100644
index 0000000..c4875a6
--- /dev/null
+++ b/tests/Support/Billing/GatewayFailureFixtures.php
@@ -0,0 +1,105 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use App\Enums\Billing\GatewayFailureClass;
+use Illuminate\Database\QueryException;
+use LogicException;
+use PDOException;
+use Stripe\Exception\ApiConnectionException;
+use Stripe\Exception\InvalidRequestException;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 「**本物の gateway が実際に伝播させる例外クラスそのもの**」を分類ごとに返す共有 fixture。
+ *
+ * ★fake が独自の RuntimeException を投げると、分類を記録する経路がテストで一度も
+ *   本物と同じ値を見ない (偽グリーン)。fake の失敗注入をここへ集約し、
+ *   `BillingGatewayFailureTaxonomyInventoryTest` が
+ *   「fixture の case 集合 == 業務 4 case」「classify(fixture(case)) === case」
+ *   「fixture が返すクラスが実ライブラリ名前空間に属する」を deny-by-default で固定する。
+ * ★`Unknown` は parity の対象外 (写像の不在専用なので「本物と同じ例外」が存在しない)。
+ *   `Unknown` の固定は分類器の Unit テストが UnmappedGatewayFailureForTest で行う。
+ */
+final class GatewayFailureFixtures
+{
+    /**
+     * 全 fixture の message に必ず含める「外部生成文字列」の目印。
+     *
+     * ★これが**無いと negative assertion が空虚に green になる**。
+     *   「ログにこの文字列が含まれない」という検査は、
+     *   「例外 message にはこの文字列が確かに入っている」という保証とセットでしか意味を持たない。
+     *   gate が全 fixture について `str_contains(getMessage(), MARKER)` を検査する。
+     */
+    public const string EXTERNAL_MESSAGE_MARKER = 'FIXTURE-EXTERNAL-MESSAGE';
+
+    /**
+     * fixture が返してよいクラスの名前空間 (gate が参照する)。
+     *
+     * @var list<string>
+     */
+    public const array ALLOWED_NAMESPACE_PREFIXES = [
+        'Stripe\\Exception\\',
+        'Laravel\\Cashier\\Exceptions\\',
+        'Illuminate\\',
+        'Webmozart\\Assert\\',
+    ];
+
+    /** parity の対象 (業務分類 4 case)。`Unknown` を含めない。 */
+    public static function throwableFor(GatewayFailureClass $class): Throwable
+    {
+        return match ($class) {
+            // Stripe に到達できない (接続断) — 本物では ApiConnectionException が伝播する
+            GatewayFailureClass::ProviderUnavailable => ApiConnectionException::factory(
+                self::EXTERNAL_MESSAGE_MARKER.': stripe unreachable',
+            ),
+            // 要求が拒否された (400) — 本物では InvalidRequestException が伝播する
+            GatewayFailureClass::ProviderRejected => InvalidRequestException::factory(
+                self::EXTERNAL_MESSAGE_MARKER.': invalid request',
+                400,
+            ),
+            // 本物の terminateInvoice の paid 判定 (Assert::true) と**同じクラス**
+            GatewayFailureClass::InvariantViolation => self::assertFailure(),
+            // reconcile が DB 例外を受ける経路
+            GatewayFailureClass::LocalFailure => new QueryException(
+                'pgsql',
+                'select 1',
+                [],
+                // ★QueryException::formatMessage() は previous の message を取り込むため、
+                //   マーカーは QueryException 自身の getMessage() にも現れる (実測で確認済み)。
+                new PDOException(self::EXTERNAL_MESSAGE_MARKER.': db unavailable'),
+            ),
+            GatewayFailureClass::Unknown => throw new LogicException(
+                'Unknown は parity の対象外。分類器 Unit テストの UnmappedGatewayFailureForTest を使うこと',
+            ),
+        };
+    }
+
+    /**
+     * parity 対象の業務 4 case (`Unknown` を除く全 case)。
+     *
+     * @return list<GatewayFailureClass>
+     */
+    public static function parityCases(): array
+    {
+        return array_values(array_filter(
+            GatewayFailureClass::cases(),
+            static fn (GatewayFailureClass $case): bool => $case !== GatewayFailureClass::Unknown,
+        ));
+    }
+
+    /** Webmozart\Assert\InvalidArgumentException を「実際に Assert に投げさせて」得る。 */
+    private static function assertFailure(): Throwable
+    {
+        try {
+            Assert::true(false, self::EXTERNAL_MESSAGE_MARKER.': 不変条件違反');
+        } catch (Throwable $throwable) {
+            return $throwable;
+        }
+
+        throw new LogicException('Assert::true(false) が例外を投げませんでした');
+    }
+}
diff --git a/tests/Support/Billing/GatewayObservationEntry.php b/tests/Support/Billing/GatewayObservationEntry.php
new file mode 100644
index 0000000..217cb50
--- /dev/null
+++ b/tests/Support/Billing/GatewayObservationEntry.php
@@ -0,0 +1,32 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * 「決済 gateway 例外を catch して観測へ落とす」と裁定されたクラスの目録エントリ。
+ *
+ * ★`catchSites` は**メソッド単位**で宣言する。ファイル全体の出現回数だけだと
+ *   コメント / 別文脈でも数が合えば green になるため、
+ *   `BillingGatewayFailureTaxonomyInventoryTest` が ReflectionMethod の行範囲で切り出して検査する。
+ */
+final readonly class GatewayObservationEntry
+{
+    /**
+     * @param  array<string, int>  $catchSites  メソッド名 => そのメソッド内で期待する context() 呼び出し回数
+     * @param  int  $rawMessageCap  当該クラスのソースに現れてよい `getMessage()` の件数 (exact fit)
+     * @param  non-empty-string  $rationale  30 文字以上
+     */
+    public function __construct(
+        public array $catchSites,
+        public int $rawMessageCap,
+        public string $rationale,
+    ) {
+        Assert::notEmpty($catchSites, 'catchSites を 1 件以上宣言すること');
+        Assert::greaterThanEq($rawMessageCap, 0, 'rawMessageCap は 0 以上で宣言すること');
+        Assert::greaterThanEq(mb_strlen($rationale), 30, '観測目録の根拠は 30 文字以上で書くこと');
+    }
+}
diff --git a/tests/Support/Billing/GatewayObservationExemptionEntry.php b/tests/Support/Billing/GatewayObservationExemptionEntry.php
new file mode 100644
index 0000000..81df8e0
--- /dev/null
+++ b/tests/Support/Billing/GatewayObservationExemptionEntry.php
@@ -0,0 +1,20 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use App\Enums\Security\GatewayFailureObservationExemption;
+use Webmozart\Assert\Assert;
+
+/** 「決済 gateway 例外を観測しないことが正しい」と裁定されたクラスの目録エントリ。 */
+final readonly class GatewayObservationExemptionEntry
+{
+    /** @param non-empty-string $rationale 30 文字以上 */
+    public function __construct(
+        public GatewayFailureObservationExemption $exemption,
+        public string $rationale,
+    ) {
+        Assert::greaterThanEq(mb_strlen($rationale), 30, '免除の根拠は 30 文字以上で書くこと');
+    }
+}
diff --git a/tests/Support/Billing/UnmappedGatewayFailureForTest.php b/tests/Support/Billing/UnmappedGatewayFailureForTest.php
new file mode 100644
index 0000000..38ed415
--- /dev/null
+++ b/tests/Support/Billing/UnmappedGatewayFailureForTest.php
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use RuntimeException;
+
+/**
+ * 写像表に**載っていない**ことを目的とするテスト専用例外。
+ *
+ * ★`unknown` (写像の不在) の分類を固定するために使う。vendor 例外を未分類のまま
+ *   fixture に使うと「vendor 全件分類」の gate と衝突するため、専用クラスを置く。
+ */
+final class UnmappedGatewayFailureForTest extends RuntimeException {}
diff --git a/tests/Support/Billing/VendorExceptionPopulation.php b/tests/Support/Billing/VendorExceptionPopulation.php
new file mode 100644
index 0000000..32ff377
--- /dev/null
+++ b/tests/Support/Billing/VendorExceptionPopulation.php
@@ -0,0 +1,115 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use FilesystemIterator;
+use ReflectionClass;
+use SplFileInfo;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 分類対象となる vendor 例外クラスの母集団 (Stripe SDK / Cashier)。
+ *
+ * ★`vendor/stripe/stripe-php/lib/Exception/*.php` (**直下のみ**) と
+ *   `vendor/laravel/cashier/src/Exceptions/*.php` を glob → クラス名へ変換 →
+ *   `class_exists()` → interface / abstract を除外する。
+ * ★`composer update` で例外クラスが増減すると gate が赤くなる。これは
+ *   **意図した費用**であり「外部の語彙が増えたことを人間に必ず知らせる」ための仕掛けである
+ *   (復旧手順は `docs/architecture.md` §オートリチャージの失敗分類)。
+ */
+final class VendorExceptionPopulation
+{
+    /**
+     * 母集団から外す Stripe のサブ名前空間 (根拠付き。gate がサブディレクトリ集合と突き合わせる)。
+     *
+     * @var array<string, string>
+     */
+    public const array EXCLUDED_STRIPE_SUBNAMESPACES = [
+        'OAuth' => 'Stripe Connect の OAuth 専用。本アプリは Connect を使わないため gateway 経路から到達しない',
+    ];
+
+    /** @return list<class-string<Throwable>> */
+    public static function classes(): array
+    {
+        $classes = array_merge(self::stripeClasses(), self::cashierClasses());
+        sort($classes);
+
+        return array_values($classes);
+    }
+
+    /** @return list<class-string<Throwable>> */
+    public static function stripeClasses(): array
+    {
+        return self::concreteThrowables(
+            base_path('vendor/stripe/stripe-php/lib/Exception'),
+            'Stripe\\Exception\\',
+        );
+    }
+
+    /** @return list<class-string<Throwable>> */
+    public static function cashierClasses(): array
+    {
+        return self::concreteThrowables(
+            base_path('vendor/laravel/cashier/src/Exceptions'),
+            'Laravel\\Cashier\\Exceptions\\',
+        );
+    }
+
+    /**
+     * ディレクトリ**直下**のサブディレクトリ名一覧 (除外宣言との突き合わせ用)。
+     *
+     * @return list<string>
+     */
+    public static function subdirectories(string $directory): array
+    {
+        $names = [];
+        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
+            Assert::isInstanceOf($entry, SplFileInfo::class);
+            if ($entry->isDir()) {
+                $names[] = $entry->getFilename();
+            }
+        }
+
+        sort($names);
+
+        return $names;
+    }
+
+    /**
+     * ディレクトリ直下の `*.php` のうち、具象 Throwable クラスだけを返す。
+     *
+     * @return list<class-string<Throwable>>
+     */
+    private static function concreteThrowables(string $directory, string $namespace): array
+    {
+        $paths = glob($directory.DIRECTORY_SEPARATOR.'*.php');
+        Assert::isArray($paths, "vendor 例外ディレクトリを走査できません: {$directory}");
+
+        $classes = [];
+        foreach ($paths as $path) {
+            $class = $namespace.basename($path, '.php');
+            if (! class_exists($class)) {
+                continue;
+            }
+
+            $reflection = new ReflectionClass($class);
+            if ($reflection->isInterface() || $reflection->isAbstract()) {
+                continue;
+            }
+            if (! $reflection->implementsInterface(Throwable::class)) {
+                continue;
+            }
+
+            /** @var class-string<Throwable> $name */
+            $name = $reflection->getName();
+            $classes[] = $name;
+        }
+
+        sort($classes);
+
+        return array_values($classes);
+    }
+}
diff --git a/tests/Support/FakeAutoRechargeGateway.php b/tests/Support/FakeAutoRechargeGateway.php
index d12d5e5..0e747ec 100644
--- a/tests/Support/FakeAutoRechargeGateway.php
+++ b/tests/Support/FakeAutoRechargeGateway.php
@@ -7,10 +7,11 @@
 use App\DataTransferObjects\Billing\DefaultPaymentMethodDto;
 use App\DataTransferObjects\Billing\InvoiceStateDto;
 use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
+use App\Enums\Billing\GatewayFailureClass;
 use App\Models\Organization;
 use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
 use Closure;
-use RuntimeException;
+use Tests\Support\Billing\GatewayFailureFixtures;
 
 /**
  * AutoRechargeGatewayInterface のテスト用 spy (Stripe に到達しない)。
@@ -53,8 +54,13 @@ final class FakeAutoRechargeGateway implements AutoRechargeGatewayInterface
     /** @var array<string, string> */
     public array $invoiceStatuses = [];
 
-    /** true にすると terminateInvoice が throw する (終端失敗 → pending 維持の再現)。 */
-    public bool $failOnTerminate = false;
+    /**
+     * terminateInvoice が投げる失敗の**分類** (null なら投げない)。
+     *
+     * ★bool ではなく分類で指定する。投げる実体は GatewayFailureFixtures が返す
+     *   **実ライブラリ例外**であり、本物の gateway が伝播させるクラスと一致する。
+     */
+    public ?GatewayFailureClass $terminateFailure = null;
 
     /**
      * createAutoRechargeInvoice が invoice ID を返す**直前**に呼ばれる hook
@@ -71,8 +77,8 @@ final class FakeAutoRechargeGateway implements AutoRechargeGatewayInterface
     /** resolveSubscriptionPaymentMethod の返り値 (null = 解決不能)。 */
     public ?string $subscriptionPaymentMethodId = 'pm_test_subscription';
 
-    /** true にすると resolveSubscriptionPaymentMethod が throw する。 */
-    public bool $failOnResolveSubscriptionPaymentMethod = false;
+    /** resolveSubscriptionPaymentMethod が投げる失敗の分類 (null なら投げない)。 */
+    public ?GatewayFailureClass $resolveSubscriptionFailure = null;
 
     /** createSetupCheckout が返す url (null = 進行中 replay の再現)。 */
     public ?string $setupUrl = 'https://checkout.stripe.test/c/setup/cs_setup_test';
@@ -152,13 +158,14 @@ public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBa
 
     public function terminateInvoice(string $invoiceId): void
     {
-        if ($this->failOnTerminate) {
-            throw new RuntimeException('fake gateway: invoice 終端失敗');
+        if ($this->terminateFailure !== null) {
+            throw GatewayFailureFixtures::throwableFor($this->terminateFailure);
         }
 
         $status = $this->invoiceStatuses[$invoiceId] ?? 'open';
         if ($status === 'paid') {
-            throw new RuntimeException("fake gateway: paid invoice {$invoiceId} は終端できない");
+            // ★本物 (CashierAutoRechargeGateway の Assert::true) と**同じクラス**を投げる
+            throw GatewayFailureFixtures::throwableFor(GatewayFailureClass::InvariantViolation);
         }
 
         $this->terminated[] = $invoiceId;
@@ -208,8 +215,8 @@ public function resolveSubscriptionPaymentMethod(string $stripeSubscriptionId):
     {
         $this->resolvedSubscriptions[] = $stripeSubscriptionId;
 
-        if ($this->failOnResolveSubscriptionPaymentMethod) {
-            throw new RuntimeException('fake gateway: resolveSubscriptionPaymentMethod failed');
+        if ($this->resolveSubscriptionFailure !== null) {
+            throw GatewayFailureFixtures::throwableFor($this->resolveSubscriptionFailure);
         }
 
         return $this->subscriptionPaymentMethodId;
diff --git a/tests/Unit/Support/Billing/GatewayFailureClassifierTest.php b/tests/Unit/Support/Billing/GatewayFailureClassifierTest.php
new file mode 100644
index 0000000..77ed2e6
--- /dev/null
+++ b/tests/Unit/Support/Billing/GatewayFailureClassifierTest.php
@@ -0,0 +1,191 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\GatewayFailureClass;
+use App\Support\Billing\GatewayFailureClassifier;
+use Illuminate\Contracts\Cache\LockTimeoutException;
+use Illuminate\Database\QueryException;
+use Laravel\Cashier\Exceptions\CustomerAlreadyCreated;
+use Laravel\Cashier\Exceptions\IncompletePayment;
+use Laravel\Cashier\Exceptions\InvalidCoupon;
+use Laravel\Cashier\Exceptions\InvalidCustomer;
+use Laravel\Cashier\Exceptions\InvalidCustomerBalanceTransaction;
+use Laravel\Cashier\Exceptions\InvalidInvoice;
+use Laravel\Cashier\Exceptions\InvalidPaymentMethod;
+use Laravel\Cashier\Exceptions\SubscriptionUpdateFailure;
+use Laravel\Cashier\Payment;
+use Stripe\Exception\ApiConnectionException;
+use Stripe\Exception\AuthenticationException;
+use Stripe\Exception\BadMethodCallException as StripeBadMethodCallException;
+use Stripe\Exception\CardException;
+use Stripe\Exception\IdempotencyException;
+use Stripe\Exception\InvalidArgumentException as StripeInvalidArgumentException;
+use Stripe\Exception\InvalidRequestException;
+use Stripe\Exception\PermissionException;
+use Stripe\Exception\RateLimitException;
+use Stripe\Exception\SignatureVerificationException;
+use Stripe\Exception\TemporarySessionExpiredException;
+use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
+use Stripe\Exception\UnknownApiErrorException;
+use Stripe\PaymentIntent;
+use Tests\Support\Billing\GatewayFailureFixtures;
+use Tests\Support\Billing\UnmappedGatewayFailureForTest;
+use Webmozart\Assert\Assert;
+use Webmozart\Assert\InvalidArgumentException as AssertInvalidArgumentException;
+
+/*
+ * 分類器の全域性・境界・context の array shape を固定する。
+ *
+ * ★DB を触らない (Unit レーン。RefreshDatabase はグローバル適用だがクエリを発行しない)。
+ */
+
+/**
+ * ★**期待値は分類器と独立に手書きで宣言する**。
+ *   `directMap()` をそのまま dataset にすると、期待値と実装が同一ソースになり
+ *   **写像を間違えても常に green** になる (既存 gate の「目録と期待値 map の二重宣言」と同じ作法)。
+ * ★件数は固定定数で持たない。**キー集合一致の検査が正本**である
+ *   (件数を別に持つと、片方だけ直したときに嘘の安心を与える)。
+ *
+ * @return array<class-string<Throwable>, GatewayFailureClass>
+ */
+function billingTaxonomyExpectedClassification(): array
+{
+    return [
+        // --- Stripe SDK ---
+        ApiConnectionException::class => GatewayFailureClass::ProviderUnavailable,
+        RateLimitException::class => GatewayFailureClass::ProviderUnavailable,
+        InvalidRequestException::class => GatewayFailureClass::ProviderRejected,
+        AuthenticationException::class => GatewayFailureClass::ProviderRejected,
+        CardException::class => GatewayFailureClass::ProviderRejected,
+        PermissionException::class => GatewayFailureClass::ProviderRejected,
+        IdempotencyException::class => GatewayFailureClass::ProviderRejected,
+        TemporarySessionExpiredException::class => GatewayFailureClass::ProviderRejected,
+        SignatureVerificationException::class => GatewayFailureClass::ProviderRejected,
+        StripeBadMethodCallException::class => GatewayFailureClass::InvariantViolation,
+        StripeInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
+        StripeUnexpectedValueException::class => GatewayFailureClass::InvariantViolation,
+
+        // --- Cashier ---
+        IncompletePayment::class => GatewayFailureClass::ProviderRejected,
+        CustomerAlreadyCreated::class => GatewayFailureClass::InvariantViolation,
+        InvalidCustomer::class => GatewayFailureClass::InvariantViolation,
+        InvalidPaymentMethod::class => GatewayFailureClass::InvariantViolation,
+        InvalidInvoice::class => GatewayFailureClass::InvariantViolation,
+        InvalidCoupon::class => GatewayFailureClass::InvariantViolation,
+        InvalidCustomerBalanceTransaction::class => GatewayFailureClass::InvariantViolation,
+        SubscriptionUpdateFailure::class => GatewayFailureClass::InvariantViolation,
+
+        // --- 非 vendor 明示宣言 ---
+        QueryException::class => GatewayFailureClass::LocalFailure,
+        LockTimeoutException::class => GatewayFailureClass::LocalFailure,
+        AssertInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
+    ];
+}
+
+/**
+ * 期待値表のクラスを**実インスタンス**として生成する。
+ *
+ * ★factory / constructor が違うため match で分ける。**実インスタンスで固定する**ことに意味がある
+ *   (`LockTimeoutException` は `Contracts\Cache` と `Contracts\Filesystem` に同名クラスがあり、
+ *    import を取り違えても文字列比較では気づけない)。
+ */
+function billingTaxonomyInstantiate(string $class): Throwable
+{
+    $throwable = match ($class) {
+        ApiConnectionException::class => ApiConnectionException::factory('connection'),
+        RateLimitException::class => RateLimitException::factory('rate limit', 429),
+        InvalidRequestException::class => InvalidRequestException::factory('invalid request', 400),
+        AuthenticationException::class => AuthenticationException::factory('auth', 401),
+        CardException::class => CardException::factory('card', 402),
+        PermissionException::class => PermissionException::factory('permission', 403),
+        IdempotencyException::class => IdempotencyException::factory('idempotency', 400),
+        TemporarySessionExpiredException::class => TemporarySessionExpiredException::factory('expired', 400),
+        SignatureVerificationException::class => SignatureVerificationException::factory('signature'),
+        StripeBadMethodCallException::class => new StripeBadMethodCallException('bad method call'),
+        StripeInvalidArgumentException::class => new StripeInvalidArgumentException('invalid argument'),
+        StripeUnexpectedValueException::class => new StripeUnexpectedValueException('unexpected value'),
+
+        IncompletePayment::class => new IncompletePayment(new Payment(new PaymentIntent('pi_test')), 'incomplete'),
+        CustomerAlreadyCreated::class => new CustomerAlreadyCreated('already created'),
+        InvalidCustomer::class => new InvalidCustomer('invalid customer'),
+        InvalidPaymentMethod::class => new InvalidPaymentMethod('invalid payment method'),
+        InvalidInvoice::class => new InvalidInvoice('invalid invoice'),
+        InvalidCoupon::class => new InvalidCoupon('invalid coupon'),
+        InvalidCustomerBalanceTransaction::class => new InvalidCustomerBalanceTransaction('invalid transaction'),
+        SubscriptionUpdateFailure::class => new SubscriptionUpdateFailure('update failure'),
+
+        QueryException::class => new QueryException('pgsql', 'select 1', [], new PDOException('db')),
+        LockTimeoutException::class => new LockTimeoutException('lock timeout'),
+        AssertInvalidArgumentException::class => new AssertInvalidArgumentException('assert'),
+
+        default => throw new LogicException("生成方法が未定義のクラスです: {$class}"),
+    };
+
+    // 生成物が宣言どおりのクラスであること (import 取り違えの検出)
+    Assert::same($throwable::class, $class, "生成したインスタンスのクラスが宣言と一致しません: {$class}");
+
+    return $throwable;
+}
+
+dataset('分類の期待値 (独立宣言)', function (): Generator {
+    foreach (billingTaxonomyExpectedClassification() as $class => $expected) {
+        yield $class => [$class, $expected];
+    }
+});
+
+test('各クラスが期待どおりに分類される', function (string $class, GatewayFailureClass $expected): void {
+    expect(GatewayFailureClassifier::classify(billingTaxonomyInstantiate($class)))->toBe($expected);
+})->with('分類の期待値 (独立宣言)');
+
+test('期待値表と directMap のキー集合が一致する (書き忘れ / 余剰の検出)', function (): void {
+    $expected = array_keys(billingTaxonomyExpectedClassification());
+    $actual = array_keys(GatewayFailureClassifier::directMap());
+    sort($expected);
+    sort($actual);
+
+    expect($actual)->toBe($expected);
+});
+
+test('UnknownApiErrorException は HTTP status で分岐する', function (?int $status, GatewayFailureClass $expected): void {
+    expect(GatewayFailureClassifier::classify(UnknownApiErrorException::factory('boundary', $status)))
+        ->toBe($expected);
+})->with([
+    'null (status 不明)' => [null, GatewayFailureClass::ProviderRejected],
+    '400' => [400, GatewayFailureClass::ProviderRejected],
+    '499 (境界の下)' => [499, GatewayFailureClass::ProviderRejected],
+    '500 (境界)' => [500, GatewayFailureClass::ProviderUnavailable],
+    '503' => [503, GatewayFailureClass::ProviderUnavailable],
+]);
+
+test('写像表に無い例外は unknown へ落ちる', function (): void {
+    expect(GatewayFailureClassifier::classify(new UnmappedGatewayFailureForTest('x')))
+        ->toBe(GatewayFailureClass::Unknown);
+});
+
+test('親クラス連鎖で分類される (将来のサブクラスを取りこぼさない)', function (): void {
+    $subclass = new class('sub') extends ApiConnectionException {};
+
+    expect(GatewayFailureClassifier::classify($subclass))->toBe(GatewayFailureClass::ProviderUnavailable);
+});
+
+test('context はキー集合と値が完全一致する (message は入り得ない)', function (): void {
+    $context = GatewayFailureClassifier::context(
+        ApiConnectionException::factory(GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER),
+    );
+
+    // ★キー集合と各値を**完全一致**で固定する。
+    //   これ以外の値が入り得ないので、マーカー非含有は自明になる
+    //   (json_encode して部分文字列を否定する形は array shape の検査として過剰)。
+    expect($context)->toBe([
+        'failure_class' => 'provider_unavailable',
+        'error_class' => ApiConnectionException::class,
+    ]);
+});
+
+test('LockTimeoutException は Contracts\Cache の具象クラスである (同名別クラスの取り違え検出)', function (): void {
+    $throwable = new LockTimeoutException('lock timeout');
+
+    expect($throwable::class)->toBe('Illuminate\Contracts\Cache\LockTimeoutException');
+    expect(GatewayFailureClassifier::classify($throwable))->toBe(GatewayFailureClass::LocalFailure);
+});

```

## テスト結果 (Round 1 の対応後に再実行)

### composer test (全件)
{"tool":"pest","result":"passed","tests":3689,"passed":3687,"assertions":14888,"duration_ms":239265,"skipped":2}
(検査 21 の追加で 3688 → 3689)

### composer phpstan (level 10)
[OK] No errors (809 files)

### vendor/bin/pint --test
{"tool":"pint","result":"passed"}

### 対象テストの個別実行
- `tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php`: 24 passed (99 assertions)
- `tests/Feature/Billing/AutoRechargeReconcileTest.php`: 12 passed
- `tests/Feature/Billing/AutoRechargeServiceTest.php`: 37 passed
- `tests/Unit/Support/Billing/GatewayFailureClassifierTest.php`: 33 passed

### 追加検査の赤化実測
- 検査 21 (M11): 免除クラスに catch を足す → 赤化を実測 (復元済み)
- 検査 19 (tokenizer 化): 完全修飾名 1 行を足す → 赤化を実測 (復元済み)。
  docblock 内の言及 (`StripeGatewayInterface`) は誤検出しないことも確認済み

## 特に判定してほしい点

1. 検査 21 の近似 (「クラス全体で `catch (` が 0 件」) が保守的すぎて実運用を阻害しないか。
   3 つの免除クラスは現状いずれも catch を持たないため今は通るが、
   将来 gateway と無関係な catch を足したいときに「観測目録へ移すか免除分類を見直す」という
   出口で足りるか。
2. 検査 19 の tokenizer 走査で、`T_COMMENT` / `T_DOC_COMMENT` だけを除外する粒度が妥当か
   (heredoc / 変数展開文字列を含む `T_ENCAPSED_AND_WHITESPACE` も拾う実装になっている)。
3. `Schema::rename` 注入を維持した判断 (対応マトリクス参照) に納得できるか。
   より良い代替があれば具体的に示してください。
