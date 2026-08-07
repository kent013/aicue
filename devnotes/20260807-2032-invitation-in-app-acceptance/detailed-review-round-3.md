# 全体判定: CHANGES_REQUESTED

Round 2 の3点への対応方針は妥当です。ただし、施策3のトークナイザ検査にもう1つ構文上の見落としがあり、このままでは正常実装でも gate が fail します。

## 施策 1: APPROVE

email完全一致、正規化しない既存契約、fail-secure時の代替経路まで明確です。

## 施策 2: APPROVE

受信者用DTOの分離、開示項目、PHP/TypeScript契約に問題ありません。

## 施策 3: REQUEST_CHANGES

[Critical] `joinOrganization` のメソッド宣言も呼び出しとして抽出されます。

現在の抽出条件は次の2条件です。

- `T_STRING` の値が `joinOrganization`
- 次の有意トークンが `(`

これは呼び出しだけでなく、次のメソッド宣言にも一致します。

```php
private function joinOrganization(
```

トークン列は概ね次の形です。

```text
T_PRIVATE / T_FUNCTION / T_STRING(joinOrganization) / (
```

その後の検査では、後方が `T_OBJECT_OPERATOR` → `$this` ではないため「未知の呼び出し形」としてfailします。

修正案: 抽出時に、直前の有意トークンが `T_FUNCTION` のものをメソッド宣言として除外してください。そのうえで、呼び出し件数は「3件未満」より現在値への完全一致が明確です。

```php
expect($callCount)->toBe(3);
```

将来呼び出し元を増やした場合に数値更新とレビューを強制したいなら、exact-fitがdeny-by-defaultの意図に合います。

[Warning] `DB::beforeExecuting()` の説明とサンプルコードが一致していません。

説明では「対象 invitation id を含む `for update`」に限定するとありますが、サンプルはテーブル名と`for update`しか判定していません。

```php
if (! str_contains($query, 'organization_invitations')
    || ! str_contains(strtolower($query), 'for update')) {
    return;
}
```

さらに通常のSQLはIDをプレースホルダにするため、SQL文字列だけでは対象IDを判定できません。

修正案: callbackでbindingsも受け取り、対象IDを検証してください。bindingsの位置に依存したくない場合は、説明を「登録後最初の招待行`FOR UPDATE`に発火する」へ正確に変更してください。現行テスト経路では後者でも目的を満たします。

one-shot後のinert化と、後続の正常受諾によるbehavioral proofは妥当です。

## 施策 4: APPROVE

inventory登録、手動解決、404への統一、Gate exemption、named limiterの組合せに問題ありません。Round 1への反論は引き続き妥当です。

## 施策 5: APPROVE

既存`Button`のloading契約、DS token、Lucide、Atomic Designの責務分離に適合しています。

## 施策 6: APPROVE

共有prop、DB非問い合わせ条件、partial reloadの更新契約までテスト可能な形で定義されています。

## 施策 7: APPROVE

以下が明確になりました。

- コード先行、旧プロセス排除、migrationの順序
- 新旧HTTP契約の両方向
- 一時的な422の受容と再読込による回復
- 500を避けるschema contract境界
- rollback時に値が復元されないこと

後方互換分岐を残さない判断とも整合しています。

## 施策 8: APPROVE

抽出結果、floor、exact-fit cap、mutation結果をレビュー対象として固定する計画は十分です。

## 施策 9: APPROVE

アーキテクチャ上の非対称、運用契約、検証コマンドが正本と整合しています。

## 修正必須事項

1. トークナイザ検査で`T_FUNCTION`直後のメソッド宣言を除外する。
2. `DB::beforeExecuting()`について、対象ID判定を実装するか、「最初の対象テーブルのロック読取に発火」という実際の保証へ説明を修正する。

この2点はいずれも局所修正です。解消後は全体をAPPROVEDと判定できる設計です。