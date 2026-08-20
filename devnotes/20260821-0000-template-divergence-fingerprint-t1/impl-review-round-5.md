## ファイル別判定

### [Suggestion] `RegularFileReader.php`

docblockの「純関数」は実態と異なります。ファイル種別確認と読み取りというI/Oを行うため、「読み取り境界」などへ直すと正確です。fail-closedの実装と正負例自体は適切です。

### [Suggestion] `TemplateDivergenceFingerprintRulesTest.php`

次の2 dataset は入力が完全に同一です。

- `1 件以上・登録そのものが無い`
- `1 件以上・対象パスはあるが登録が無い`

どちらも以下です。

```php
[176, InventoryPresence::RegularFile, true, false, false]
```

「登録と対象パスを一緒に消した通常形」も残したい場合、前者を `false, false` にし、後者を独立発火用の `true, false` にするとdataset名と検体が一致します。現状でも後続の件数検査により判定ロジックの検出力は担保されています。

### 指摘なし

- `FingerprintGenerationService.php`: 削除前presence、削除、削除後presenceの3段はfail-closedです。削除失敗と「成功を返したが残っている」の両方を落としています。
- 削除経路の6形は、通常ファイル・symlink・壊れたsymlink・ディレクトリ・削除失敗・不在を十分に固定しています。
- `debtInventoryRemoved` は実削除時だけtrueとなり、`retired` / `newlyRetired` との意味も分離されています。
- CLIの案内条件は安定した引退状態で反復しません。
- `RegularFileReader` のread failure分岐は注入によって独立して裏取りされています。
- gateと`LedgerPins`の説明は、D34を残す現在の設計と一致しています。
- D34のライフサイクル、seeding、role分類、symlink拒否、掃除漏れ検出について、これまでの阻害的な指摘は解消されています。
- PHPStanについても、新規実装が解析対象外であるという前提を超えた保証はしていません。

残る2点は表現・検体整理のSuggestionであり、不変条件や検出力を損なうものではありません。

APPROVED