# Round 3 レビュー結果

Round 2のCriticalは実質的に解消されています。残るのは3件のWarningです。特にAC-14のpartition本体は正しくなりましたが、AC-14自身の正例・負例点呼に小さな矛盾があります。

| 施策 | 判定 |
|---|---|
| 1. 書式の正本 | APPROVE |
| 2. 7枚のカード移行 | APPROVE |
| 3. 前付け読み取り器 | APPROVE |
| 4. 書式契約の自己テスト | REQUEST_CHANGES |
| 5. `composer test`配線 | APPROVE |
| 6. 注釈から`story`撤去 | APPROVE |
| 7. 生成器の入力切替 | REQUEST_CHANGES |
| 8. 照合器の複数値対応 | APPROVE |
| 9. 目録再生成 | APPROVE |
| 10. 乖離台帳更新 | APPROVE |
| 11. 移行検算 | REQUEST_CHANGES |

## 施策4: REQUEST_CHANGES

[Warning] AC-14自身が、`SUBJECT_TO_TESTS`の正例・負例命名規約と一致していません。

提示されたAC-14のメソッドは次の名前です。

- `test_ac_14_invariant_partition_is_total`
- `test_ac_14_every_adopted_invariant_has_a_bearer`
- `test_ac_14_every_story_side_invariant_maps_to_a_real_test`

これらには`accepts`も`rejects`もありません。したがって、`SUBJECT_TO_TESTS["AC-14"]`へ登録すると以下が失敗します。

```python
any("accepts" in n for n in names)
any("rejects" in n for n in names)
```

逆にAC-14を`SUBJECT_TO_TESTS`から外すと、「各主題に正例・負例がある」という設計上の主張と一致しません。

修正案: partition判定を入力付きの関数へ抽出し、正負を直接裏取りしてください。

```python
def partition_violations(
    all_invariants: tuple[str, ...],
    adopted: tuple[str, ...],
    differences: tuple[str, ...],
    not_adopted: tuple[str, ...],
) -> list[str]:
    ...
```

テスト例:

```python
def test_ac_14_accepts_complete_partition(self) -> None:
    ...

def test_ac_14_rejects_missing_invariant(self) -> None:
    ...

def test_ac_14_rejects_duplicate_classification(self) -> None:
    ...

def test_ac_14_rejects_unknown_bearer_id(self) -> None:
    ...
```

これにより、固定定数をそのままassertするだけでなく、検出分岐自体の負例も固定できます。

なお、58項目のpartition設計そのものは妥当です。以下は正しく解消されています。

- I群を含む58項目の独立した基準集合
- 分類の排他性と全数一致
- ID重複の検出
- 採用項目の担い手確認
- 未知の担い手IDの拒否
- E5/G6の明示的な非機械保証

## 施策7: REQUEST_CHANGES

`終`の波及設計は、提示テキスト上で確認できるconsumerをすべて押さえています。

- reason要否
- 旧`story`検査
- screens/operationsの対象外件数
- 対象外節
- 新しい割当検査

`KUBUN_NEEDS_REASON`と`KUBUN_OUT_OF_SCOPE`の役割分離も適切です。追加した`終`の統合テストにより、将来`終`が追加された場合の後退も検出できます。

[Warning] `not_applicable`の説明がD6と矛盾しています。

施策7では次の理由で割当母集団から外しています。

> 実走しないカードがrouteを消化することにはならない

しかしD6では「`not_applicable`を実走対象から外す契約」は未採用です。現状のSKILLでは実走除外が保証されません。

割当から外す設計自体は妥当です。F2により手順を持たず、coverageの消化カードとして数えるべきではありません。問題は「実走しない」と断定している点です。

修正案:

> `not_applicable`カードは手順を持たず、coverageの消化カードとして数えないため、割当母集団から外す。実走対象から除外されること自体はD6のとおり未採用であり、現在該当カードは0枚である。

併せてI1を「1枚以上のapplicableカードに載る」と全数対応表側でも明記すると、生成器の仕様と一致します。

## 施策11: REQUEST_CHANGES

11件のroute名について、名称だけから明白に誤りと判断できるものはありません。プロジェクト・manual・category・capture配下を組織B視点で踏み直す構成はS7の目的と整合します。

ただし、現在の検算条件では「全11件が既にS3またはS4の消化対象だった」という前提を機械保証していません。

[Warning] 「変換後のみのS7関係が期待リストと一致」するだけでは、変換前が空だったrouteにS7だけを追加しても成功します。

例えば次も、追加された関係だけを比較する実装なら通り得ます。

```text
変換前: []
変換後: [S7]
```

しかし設計が意図するのは次です。

```text
変換前: [S3] または [S4]
変換後: 変換前 ∪ [S7]
```

修正案: route名だけでなく、変換前の期待割当も固定してください。

```python
EXPECTED_S7_PRIOR_SCREEN_ASSIGNMENTS = {
    "capture.manuals.show": frozenset({"S3"}),
    # ...
    "projects.show": frozenset({"S4"}),
}
```

操作側も同様に、各routeの変換前集合を固定します。検算条件はrouteごとに次の完全一致とします。

```python
before == expected_before
after == expected_before | {"S7"}
```

さらに、`projects.edit`と`projects.show`は名前上は「nested child」ではなくproject自身の画面なので、11件それぞれについて短い選定根拠を検算資料へ残すと、「全nested screen」という散文との関係が明確になります。例えば次の分類で十分です。

- project自身のcurrent-org境界
- project配下manualの親子境界
- manual配下take/render/jobの親子境界
- project配下categoryの親子境界
- capture経由でmanual/takeへ到達する境界

## 指定された4点への回答

### (a) AC-14のpartition

58項目の一覧落ちを検出する設計になっています。Round 2の問題は解消されています。

残るのは、AC-14自身の正例・負例命名と検出分岐の裏取りです。partitionロジックを入力付き関数にして負例を通せば完成です。

### (b) `終`のconsumer

提示された現行コードと棚卸し表の範囲では漏れは見当たりません。特にレンダリング件数と対象外節まで修正対象へ入ったことで、前版の矛盾は解消されています。

実装時には、`KUBUN_NEEDS_REASON`がreason検査以外で残っていないことを最終確認すれば十分です。

### (c) S7の11画面

route名・区分・S7の目的から見て、明確な誤選択は確認できません。ただし「元からS3/S4が消化している」という選定前提を、現在の検算条件は保証していません。

routeごとの変換前集合を固定し、`before`と`after`を完全一致させれば、11件の選定根拠まで機械的に閉じられます。

### (d) 残る指摘

Criticalはありません。残るWarningは次の3件です。

- AC-14自身の正例・負例が命名・検出力条件を満たしていない
- `not_applicable`を「実走しない」とする説明が未採用D6と矛盾
- S7追加routeの変換前割当が固定されていない

# 全体判定

**CHANGES_REQUESTED**

いずれも局所修正です。設計の方向性、58項目の全数分類、`終`のscope変更、PHPStan対応、複数値セルのfail-closed処理には、追加のCriticalはありません。