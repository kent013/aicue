# 詳細設計レビュー Round 4

## S1: APPROVE

必須7キーと追加可能な PII-free キーの区別が明確になり、Billing の `attempt_ulid` と整合しました。

[Suggestion] リスク節の「文字列1本のためにクラスを作る」は、現在は event 定数が2本なので「ログ event 定数だけのために専用クラスを作る」へ直すと正確です。

## S2: APPROVE

条件付き UPDATE、array shape、Feature テストの役割分担まで整っています。

## S3: APPROVE

進捗書き戻し、PUT 抑止、後始末について必要な契約が揃っています。

## S4: APPROVE

未紐付け invoice と attach 済み invoice の後始末が分離され、`Failed` の前提も behavioral test に登録されました。台帳付与と attempt 遷移の複合保証も正確です。

## S5: APPROVE

import 不足は解消され、接続前提も固定されています。

## S6: REQUEST_CHANGES

[Critical] checkpoint を複数化しても、目録から `StripeInvoiceCreate` を削除したことは gate で検出できません。

現在の検査が保証するのは、登録されている checkpoint 間で `ExternalCallKind` が重複しないことだけです。

```php
$kinds = array_map(..., $checkpoints);
$duplicates = ...;
expect($duplicates)->toBe([]);
```

そのため M8c の mutation:

```text
preflights から StripeInvoiceCreate を削除
```

を行っても、`StripeInvoicePay` が1件残るので、以下はすべて満たされます。

- `preflights` は非空
- `NoExternalCall` と混在しない
- `ExternalCallKind` の重複がない
- 登録済み checkpoint は実在する

したがって、期待する「外部呼び出し種別ごとに checkpoint がちょうど1件」は赤になりません。これは gate の主張と mutation 受け入れ条件の不一致です。

修正案: ジョブごとの期待する外部呼び出し種別を checkpoint 登録とは独立に持たせ、集合一致を検査してください。例えば:

```php
/**
 * @param non-empty-list<ExternalCallKind> $requiredExternalCalls
 * @param non-empty-list<PreflightRequirement> $preflights
 */
public function __construct(
    public array $mechanisms,
    public array $requiredExternalCalls,
    public array $preflights,
    public string $rationale,
) {}
```

gate では次を検査します。

```php
$required = array_map(
    static fn (ExternalCallKind $kind): string => $kind->value,
    $entry->requiredExternalCalls,
);
$registered = array_map(
    static fn (PreflightCheckpoint $checkpoint): string => $checkpoint->externalCall->value,
    $checkpoints,
);

sort($required);
sort($registered);

expect($registered)->toBe($required);
```

`NoExternalCall` の場合は `requiredExternalCalls` を空にする必要があるため、型を分ける方がさらに明確です。過剰な構造化を避けるなら、独立した期待値 map でも足ります。

```php
/** @return array<class-string, list<ExternalCallKind>> */
function jobDedupRequiredExternalCalls(): array
{
    return [
        RunManualAnalysis::class => [ExternalCallKind::LlmCompletion],
        RunManualRender::class => [ExternalCallKind::ObjectStoragePut],
        ExecuteAutoRechargeAttemptJob::class => [
            ExternalCallKind::StripeInvoiceCreate,
            ExternalCallKind::StripeInvoicePay,
        ],
    ];
}
```

この期待値との集合一致があれば M8c は正しく赤になります。

[Warning] `NoExternalCall` は「1件だけ」という設計ですが、gate は複数登録を拒否しません。

```php
preflights: [
    new NoExternalCall('...'),
    new NoExternalCall('...'),
]
```

も通過します。

修正案: `$none` が非空なら `count($none) === 1` かつ `$checkpoints === []` を要求してください。

[Warning] S7 の次の記述も、現状では保証を誇張しています。

> 外部呼び出し種別ごとに checkpoint がちょうど1件

現行 gate が保証するのは「登録済み種別に重複がない」までです。上記の期待集合との一致検査を追加すれば、そのまま記載できます。

## S7: REQUEST_CHANGES

[Warning] S6 の completeness 検査を追加したうえで、対応表にその期待集合の正本を明記してください。Feature テストは配置を保証し、Architecture gate は「期待する外部呼び出し種別と checkpoint 登録の集合一致」を保証する、という分担になります。

## 全体判定

**CHANGES_REQUESTED**

実装本体の S1〜S5 は承認可能です。残る問題は S6 の目録 completeness だけです。複数 checkpoint 化によって表現力は得られましたが、現在の gate は「登録漏れ」を検出できず、M8c も赤になりません。

期待する外部呼び出し種別との独立した集合一致を追加すれば、設計全体を承認できます。