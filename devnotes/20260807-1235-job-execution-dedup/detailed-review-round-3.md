# 詳細設計レビュー Round 3

## S1: REQUEST_CHANGES

[Warning] `LOG_EVENT` の説明と Billing の実際のキー集合が一致していません。

docblock は「キー集合は最小7キーで固定」「キー集合の違うログを同じ event 名に混ぜない」としていますが、Billing は `attempt_ulid` を加えた8キーです。テスト計画も追加キーを許容しています。

修正案: 契約を次のどちらかに統一してください。

- 7キーの完全一致とし、`attempt_ulid` を除く
- 「必須7キーを共通 schema とし、PII-free なドメイン固有キーを追加可能」と記述する

現行実装に合うのは後者です。「キー集合固定」ではなく「必須キー集合固定」と明記すれば整合します。

## S2: APPROVE

array shape により書き込み可能な列が閉じられ、条件付き UPDATE の責務も明確になりました。監査スナップショットを対象外とする境界も妥当です。

## S3: APPROVE

Round 2 までの指摘は解消されています。進捗更新、preflight、finally の責務分離とテスト計画に問題ありません。

## S4: REQUEST_CHANGES

[Critical] auto-recharge が持つ2つの外部呼び出しのうち、S6 の目録には pay しか登録されていません。

実装には以下の2つの checkpoint があります。

- `StripeInvoiceCreate`
- `StripeInvoicePay`

しかし `GuaranteeEntry::$preflight` は単一値で、登録は `StripeInvoicePay` だけです。この状態では `StripeInvoiceCreate` の preflight を削除しても、Architecture gate の目録は green のままです。また「読み手が目録からすべての外部呼び出しを辿れる」という説明も成立しません。

修正案: `GuaranteeEntry` を次のように変更してください。

```php
/**
 * @param non-empty-list<PreflightRequirement> $preflights
 */
public function __construct(
    public array $mechanisms,
    public array $preflights,
    public string $rationale,
) {}
```

auto-recharge には create/pay の2件を登録し、同じ `ExternalCallKind` の重複も gate で拒否します。外部呼び出しがないジョブは `[new NoExternalCall(...)]` とします。

[Warning] `terminateInvoiceAfterOwnershipLost()` の `Failed` に関する前提は Feature テストで直接固定した方が安全です。

「attach 済みなので failed 遷移側が終端済み」という説明は、`terminateAndFail()` が必ず invoice 終端成功後にだけ `Failed` へ遷移することへ依存します。

修正案: 既存テストで未保証なら、「終端失敗時は Pending のまま」「Failed なら invoice 終端済み」を behavioral test に追加するか、対応する既存テスト名を設計へ明記してください。

## S5: REQUEST_CHANGES

[Critical] 掲載されたテストコードでは `ExecuteAutoRechargeAttemptJob` の import がありません。

```php
expect((new ExecuteAutoRechargeAttemptJob(1))->connection)->toBeNull();
```

修正案:

```php
use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
```

を追加してください。記載省略ではなく変更後コードとして扱う限り、このままではテスト実行時にクラス解決が失敗します。

## S6: REQUEST_CHANGES

[Critical] `DatabaseUniqueConstraint` の定義が、auto-recharge 台帳の保証に適合していません。

現在の enum は次のように限定されています。

- partial unique index
- 2回目の起票を拒否

一方、`recharge:{invoiceId}` は台帳効果の重複挿入を拒否する冪等キーであり、「partial unique index による起票拒否」とは別物です。複数 mechanism 化しても、登録する enum case 自体の適用条件がまだ不一致です。

修正案はどちらかです。

- `DatabaseUniqueConstraint` を「DB UNIQUE 制約が同じ冪等キーによる2回目の効果確定を拒否する」と一般化する
- `IdempotentLedgerKeyUniqueConstraint` を別 case として追加する

概念を混ぜない原則からは、起票制約と台帳冪等キーを別 case にする方が明確です。

[Critical] gate の掲載コードでは必要な import が不足しています。少なくとも以下が使用されています。

```php
use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
use App\Services\Billing\AutoRechargeService;
use Tests\Support\JobDedup\PreflightControlFlow;
```

「要点のみ」の省略なら、実装設計上の import 一覧に含めることを明記してください。

[Warning] 固定 event literal の検査がシングルクォートしか検出しません。

```php
$literals = ["'job_ownership_lost'", "'job_ownership_lost_cleanup'"];
```

次は gate を回避します。

```php
"job_ownership_lost"
```

修正案: single/double quote の両方を検査するか、PHP token から文字列 literal の値を取得してください。今回の範囲なら両 quote の4パターン検査で十分です。mutation に double quote 版も追加してください。

[Warning] `mechanisms` の重複検査は順序依存です。

```php
expect(array_values(array_unique($values)))->toBe($values);
```

現状は意図どおり動きますが、エラー表示を安定させるなら重複値そのものを算出して検査する方が読みやすくなります。これは実装時の改善候補であり、承認条件ではありません。

## S7: REQUEST_CHANGES

[Warning] S4 の preflight 目録を複数件対応へ変更した後、対応表も複数 checkpoint を表す記述へ更新してください。

また、次の行は少し強すぎます。

> 所有権喪失時に Stripe を呼ばず invoice を終端する

invoice 終端自体が Stripe 呼び出しです。正確には次のような記述になります。

> 所有権喪失時に invoice 作成・支払いを抑止し、必要な既作成 invoice を終端する

## 全体判定

**CHANGES_REQUESTED**

Round 2 の主要3点、制御方式の型付け、複合保証、未紐付け invoice の回収は適切に解消されています。残る本質的な問題は、auto-recharge の2つの外部呼び出しを単一 `PreflightCheckpoint` で表現していることと、台帳冪等キーを「partial unique index による起票拒否」と分類していることです。

この2点と import 不足を修正すれば、実装へ進める設計になります。