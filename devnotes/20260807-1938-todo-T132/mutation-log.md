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

---

## 4. impl-review Round 2 の Suggestion 対応後の再実測

検査 21 の `catch` 計数を文字列走査から tokenizer (`T_CATCH`) へ寄せたため、M11 を再実施した。
このとき**文字列走査版では取りこぼす非標準整形**を意図的に使った。

- mutation: `SetDefaultPaymentMethodJob` の gateway 呼び出しを
  `try { … } catch(\Throwable $e) { return; }` で囲む (`catch` と `(` の間にスペースを置かない)
- 実行: `composer test -- tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php`
- 結果: **検査 21 が赤化**

```
App\Jobs\Billing\SetDefaultPaymentMethodJob: 「伝播させる」と免除宣言しているのに catch があります。
観測目録へ移すか、免除の分類を見直すこと
Failed asserting that 1 is identical to 0.
```

復元後 `git diff --stat app/Jobs/` が空であることを確認した。
