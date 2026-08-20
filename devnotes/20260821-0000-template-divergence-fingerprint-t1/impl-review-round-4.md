## ファイル別判定

### [Critical] `FingerprintGenerationService.php`

債務一覧の削除処理が、非regularな残置や読み取り失敗を「不在」と誤認して成功します。

現在の処理は次です。

```php
if ($reader($context->debtOutputPath) !== false && ! $remover($context->debtOutputPath)) {
    throw ...
}
$debtInventoryRemoved = true;
```

以下の経路がfail-openです。

- 壊れたsymlink: production readerは `is_file()` がfalseなので削除しない
- ディレクトリ: readerはfalse、removerも呼ばれず残る
- 読み取り不能なregular file: readerはfalseなので削除しない
- 不在: 何も削除していないのに `debtInventoryRemoved=true`

serviceは成功を返しますが、F12が要求する `InventoryPresence::Absent` にはなっていません。「掃除漏れ検出・fail-closed」の中心経路なので修正が必要です。

読み取り結果を存在判定に使わず、例えば次を分離してください。

- 削除前の `InventoryPresence`
- パス削除処理
- 削除後が `Absent` であることの再検査

I/O注入方針を維持するならpresence resolverとdeleterを注入し、少なくとも通常ファイル・symlink・壊れたsymlink・ディレクトリ・削除失敗・既に不在を固定してください。`debtInventoryRemoved` は実際に存在したパスを削除した場合だけtrueにすべきです。

### [Warning] `TemplateFingerprintGeneratorTest.php`

上記の新しい削除分岐について、正常なregular fileの削除と安定した不在しか検査していません。

不足している負例は次です。

- removerがfalseを返す
- 読み取り不能なregular file
- symlink
- 壊れたsymlink
- ディレクトリ

特に現在の実装では、壊れたsymlinkとディレクトリが残ったまま成功する負例が実際に再現できます。

### [Warning] `RegularFileReader.php` / `TemplateDivergenceFingerprintRulesTest.php`

docblockは「読み取りが失敗した」分岐を保証対象に含めていますが、負例はありません。

現在の4件datasetは以下です。

- symlink
- 壊れたsymlink
- directory
- missing

symlinkと壊れたsymlinkは同じ最初の分岐へ入るため、`file_get_contents()` がfalseを返す最後の分岐は一度も通りません。共通規約 (c) に照らし、読み取り関数を注入できる小さな境界へするなどして、regular file判定後のread failureを独立して裏取りしてください。

### [Warning] `LedgerPins.php`

`ADOPTION_DEBT_DIVERGENCE_ID` のdocblockが旧設計のままです。

```php
債務が 0 件になったらこの登録ごと消す。
```

Round 4で確定した設計は「D34は一覧クラスの説明として残す」です。正反対なので修正が必要です。

### [Warning] `TemplateDivergenceFingerprintTest.php`

こちらにも旧設計の記述が残っています。

`fingerprintDebtRetired()` のdocblock:

> 引退後は一覧ファイルも登録も残っていてはならない

F12のコメント:

> pin が 0 件 → どちらも残っていてはならない

実装と現在の正本は「一覧パスは消すがD34は残す」です。保証範囲の説明がコードと逆なので更新してください。

### [Suggestion] `scripts/update-template-fingerprints.php`

`retired` は「今回0件になった」ではなく「生成結果が0件」を表すため、安定した引退状態で再実行しても毎回次の案内を出します。

> 採用時債務が 0 件になった

さらに現在は `debtInventoryRemoved` が常にtrueなので、一覧が元から無くても「生成器が取り除いた」と表示します。

報告を次のように分けると正確です。

- `retired`: 生成結果が0件
- `debtInventoryRemoved`: 今回実際に削除した
- 必要なら `newlyRetired`: 既存債務が非空から0件へ遷移した

pinや対象パス更新の案内は遷移時または現状との不整合がある場合に限定するのが自然です。

### 指摘なし

- D34を残す案3は、恒久的な機構と一時的な一覧の役割を正しく分離しています。
- `InventoryPresence` の3値化と写像テストは適切です。
- F12はD34の存在と一覧対象パスを別々に判定できています。
- seeding blocker、role guard、symlink拒否の既存指摘は解消されています。
- `RegularFileReader` 本体の型・例外・保証範囲は適切です。
- 引退後の再実行と再開の正常系シナリオは妥当です。

D34のライフサイクルは整合しましたが、生成器の削除経路が非regularな残置を成功扱いするため、現時点ではfail-closedになっていません。

CHANGES_REQUESTED