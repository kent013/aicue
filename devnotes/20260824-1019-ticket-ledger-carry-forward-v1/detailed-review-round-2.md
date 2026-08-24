# 全体判定: CHANGES_REQUESTED

Round 1 の中心的な実装上の穴は解消されています。残る主な問題は、主キー取得 gate への反論で示した実測コードと実際の設計コードが一致していないこと、および旧記述が数か所残って設計内部で矛盾していることです。

## 最優先事項: 主キー取得 gate への反論

判定: **反論の方針自体は妥当だが、現状の根拠では未承認**

`DirectFetchInventory` が「解決済みモデル由来の識別子を母集団から外す」と明示しているなら、すべての `whereKey($model->getKey())` を登録する必要はありません。外部入力由来のIDと、既に解決済みのモデルを再ロックするためのIDは、セキュリティ上も別物です。

ただし、提示された実測と変更後コードが異なります。

実測した形:

```php
Organization::withTrashed()
    ->whereKey($organization->getKey())
```

実際の変更後コード:

```php
$organizationId = $organization->getKey();

DB::transaction(function () use ($organizationId, ...): int {
    Organization::withTrashed()
        ->whereKey($organizationId)
```

後者では query site の引数は単なる captured `int` です。走査器が代入元と closure capture を追跡しない限り、「解決済みモデル由来なので除外された」のか、「`withTrashed()` を挟んだため受け手解析に失敗した」のかを区別できません。候補が0件という結果だけでは、反論の核心を証明できていません。

修正案は次のいずれかです。

- 推奨: closure に `$organization` を渡し、実測済みの形とコードを一致させる。

  ```php
  DB::transaction(function () use ($organization, $threshold, $now): int {
      Organization::withTrashed()
          ->whereKey($organization->getKey())
          ->lockForUpdate()
          ->firstOrFail();
  });
  ```

  必要なら処理用の `$organizationId` は closure 内で別途確定します。

- または、実際の `$organizationId` + closure capture のコードを走査器へ渡し、0件になる理由が provenance 除外であることを正例・負例で示す。識別子を payload 由来へ変えた変異が候補になることまで必要です。

このどちらかが確認できれば、`DirectFetchInventory` に登録しない判断を承認できます。現段階で「走査器の盲点ではない」と断定するのは早いです。

---

## 施策 1: REQUEST_CHANGES

- [Warning] 決着対象を「4か所すべてで共有する」という説明は実装と一致しません。

  `settlementPredicate()` を共有しているのは、実際には次の経路です。

  - 件数・監視: `settlementScope()`
  - 組織列挙: `organizationsWithSettlementTargets()`

  行の処理では、独立した `expiredScope()`、`contributingGroups()`、`groupScope()` が同等の条件を再実装しています。

  修正案は、説明を「列挙・件数・監視で共有し、処理側は厳密な補集合となる2枝で実装する」へ正すことです。その補集合性は N1、N18、境界時刻テスト、変異表で固定してください。完全共有を主張するなら、処理側も共通 predicate helper を使う必要があります。

- [Warning] append-only の説明がまだ広すぎます。

  次の記述が、既存の `payment_intent_id` backfill と矛盾しています。

  - クラス冒頭の「append-only 台帳に対する唯一の例外経路」
  - 「保持期限の決着だけが唯一の例外」
  - mutation inventory の「append-only の唯一の例外」

  修正案は、すべて「行の物理削除・残高スナップショットへの置換を行う唯一の経路」へ限定することです。限定 metadata backfill は別の許容変更です。

- [Warning] ロック範囲の説明に内部矛盾があります。

  前半で `grant` をロック対象に含めた直後、`grantMonthly` / `grantPurchased` / `grantSignupGrant` はロックを取らないと説明しています。ロックを取る具体的メソッドだけを列挙するか、「残高判定を伴う一部の grant」と限定してください。

- [Warning] PHPStan fallback 節に撤回済みの gate 方針が残っています。

  「同ファイル内の `Organization::query()` の存在に読み替える」とありますが、施策5ではこれを fail-open として撤回しています。受理する厳密な2形へ記述を揃えてください。

主キー取得の証拠を修正し、上記の説明を整合させれば、実装ロジック自体は承認可能です。

## 施策 2: APPROVE

`stdClass` への境界限定、変換前の範囲検査、件数間の不変条件はいずれも適切です。PHPStan level 10 に対する設計も妥当です。

- [Suggestion] `nullableTimestamp()` は任意の自然言語日時を `CarbonImmutable::parse()` が受理します。DB driver の値だけが入力である現状では許容できますが、DTOを別用途へ公開しないことを維持してください。

## 施策 3: APPROVE

寄与中の繰越行と失効済み繰越行を分離したことで、`expiredRemaining`、処理対象、publication-ready の意味が整合しました。3方向のテストも十分です。

## 施策 4: REQUEST_CHANGES

- [Critical] 文書末尾に旧結論が残っています。

  `migration / 後方互換の扱い` に次の記述があります。

  > デプロイ順序の制約は無い  
  > コード先行でも migration 先行でも動く

  これは施策4、migration docblock、runbook の新しい結論と正面から矛盾します。

  修正案:

  ```text
  デプロイ順序は「新コード → drop migration」に固定する。
  drop先行およびdrop後の旧コードへの単純rollbackは不可。
  ```

  「この事実を migration の docblock に書く」という文も削除してください。

- [Warning] リスク節では「migration / runbook / architecture の3か所」としていますが、施策9では architecture は順序を書かず runbook を参照するとしています。

  修正案は、「順序の正本は runbook、migration と architecture は正本を参照する」に統一することです。

## 施策 5: APPROVE

TLM-5 の拡張、追記呼び出しの closure 外移動に対する負例、`withTrashed()` の受理構文固定は適切です。

特に、変数受け手や長い連鎖を未解決として落とす方針は、共通走査規約の fail-closed 要件に合っています。

ただし、施策1の PHPStan fallback 節もこの確定方針へ揃える必要があります。

## 施策 6: APPROVE

変更ありません。読み手目録の移設追随と DTO ディレクトリの走査追加は妥当です。

## 施策 7: REQUEST_CHANGES

- [Warning] テストファースト手順に N3 の旧記述が残っています。

  段1には依然として N3 が含まれ、期待する赤にも次があります。

  > N3（2回目で行が増える or 短絡が無い）

  これは本文の「N3はv0でも緑」「赤の起点に使わない」と矛盾します。

  修正案は、段1から N3 を外し、N3b を短絡検査として後段へ置くことです。例えば:

  ```text
  段1: N1 / N2 / N11 / N12 / N14 / N18
  段10: N3 / N3b を追加し、N3b は短絡を一時的に壊して赤を確認
  ```

- [Suggestion] テストケース17の「列欠落」の説明がまだ `propertyExists` になっています。実装に合わせて `Assert::keyExists()` へ直してください。

N18、N19、時刻固定、N3b の追加自体は適切です。

## 施策 8: APPROVE

Round 1 の不足は解消されています。特に PHP整数範囲超、指数表記、bool、集約結果間の不整合を含めた点は十分です。

## 施策 9: REQUEST_CHANGES

- [Warning] AGENTS.md の規約案は修正されていますが、サービス docblock と mutation inventory に「append-only の唯一の例外」が残っています。

  規約だけでなく、実装コメント・目録の理由も同じ語義へ統一してください。

- [Warning] デプロイ順序の正本を runbook にする方針は妥当ですが、後段の `migration / 後方互換の扱い` に旧結論が残っています。検索対象は「順序制約は無い」「migration先行」「コード先行でも」の3語句を含めて全数確認する計画にしてください。

- [Suggestion] `down()` 後に旧コードへ戻しても、既存 v1 繰越行の `idempotency_key` は null のため、旧コードが同じ group を再処理した場合の挙動は完全な旧状態には戻りません。「列の値が戻らない」だけでなく「アプリケーション状態の意味も完全には復元されない」と runbook に明記すると安全です。

## 施策 10: APPROVE

変異表の再作成と、失効済み繰越行を決着対象から外す変異の追加は適切です。

---

## 最終的に必要な修正

承認までに必要なのは次の4点です。

1. 主キー取得 gate について、実測コードと実装コードを一致させるか、実装どおりの closure-captured ID で provenance 除外を証明する。
2. `migration / 後方互換の扱い` に残った「順序制約なし」を削除する。
3. テストファースト段1から N3 を外し、N3b の赤確認へ置き換える。
4. 「append-only の唯一の例外」と撤回済みの `Organization::query()` 同居判定を、サービス・目録・PHPStan節から除去する。

これらは設計の中心ロジックを作り直す変更ではなく、証拠と記述を実装方針へ正確に揃える修正です。