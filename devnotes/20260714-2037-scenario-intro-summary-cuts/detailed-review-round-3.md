全体判定: **CHANGES_REQUESTED**

完成文ベースの長さ判定は妥当です。一方、施策4.5には上限の意味との不整合が残っています。

### 施策3: 総括組み立て

判定: **APPROVE**

- 接頭辞込み完成文で件数を減らし、1件でも超過する場合だけ完成文をtruncateするため、意図した優先順位を満たします。
- `normalize()` の `Assert::string()` もPHPStan L10向けに適切です。
- [Suggestion] `$render = fn (array $items)` はPHPStan L10で「iterableの値型未指定」になる可能性があります。`renderRecap(array $items): string` のprivateメソッドへ分離し、`@param list<string>` を付けるのが確実です。

### 施策4.5: 保存上限

判定: **REQUEST_CHANGES**

- [Critical] Requestを一律102件許可すると、導入・総括を含まない任意の通常stepも102件保存できます。したがって「編集の手順上限は100、定型2件のみ別枠」という定数コメントと実際の不変条件が一致しません。現モデルでは定型cutを識別できないため、Requestの件数検証だけでは内訳を保証できません。  
  修正案: v1では実際に強制可能な仕様として、次のように定義してください。

```php
MAX_STEPS = 100; // LLM生成step上限
MAX_TOP_LEVEL_CUTS = 102; // 手動保存可能なtop-level cut総数上限
```

- 手動編集の「通常手順100件」という保証は削除し、102件全体を保存上限と明記する。
- 通常step最大100件を厳密に維持したい場合は、bookendを識別する永続属性が必要であり、今回の「独立種別なし」方針では実現できません。

### 施策6: Featureテスト

判定: **REQUEST_CHANGES**

- [Warning] 「200」は既存POST endpointの応答契約と要照合です。Inertiaの操作系POSTがredirectを返すなら期待値は通常302です。  
  修正案: 既存`ScenarioUpdateTest`と同じ応答アサートを使い、その後DBで102件・順序・version更新を検証してください。
- [Suggestion] 102件成功に加え、103件validation失敗を同じ新定数基準で固定する計画は適切です。

### その他

- 施策1・2・4・5・7: **APPROVE**
- `ScenarioWritePathInventoryTest`への新規登録不要判断も引き続き妥当です。

施策4.5のコメントと仕様を「生成100／保存総数102」に修正し、POSTの期待statusを既存契約へ合わせれば承認可能です。