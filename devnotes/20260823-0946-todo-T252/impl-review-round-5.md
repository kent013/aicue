### `tests/Architecture/ArchBaselineTest.php`

Round 4の直接指摘は解消しています。

- footerのexact-fitにより `})->skip()` / `todo()` / `group()` を静的に検出
- S4-3cで登録済みfactoryの修飾状態も実行時検査
- PHPStan level 10は0エラー
- 抑止・baseline・型widenなし

[Critical] ファイル単位の `beforeEach` で全テストをskipする経路が残っています。

宿主ファイルの禁止表明より前に、次を注入してください。

```php
beforeEach(function (): void {
    $this->markTestSkipped('probe');
});
```

Pestのfile-scoped `beforeEach`として適用された場合、禁止表明7本とS1〜S5は登録されますが、実行時にすべてskipされます。一方、現在の検査では以下が成立します。

- ヘッダー・表明・footerは一切変わらない
- 最上位制御構造は期待する `foreach` 1件のまま
- `topLevelAbortSites()` は、closure内部の呼び出しを数えない
- 7件のmethod factory自体は新品相当のまま
- attributesも `Test` + `TestDox` のまま
- 外部テスト38は通常実行され、静的構造を正常と判定する
- S4-3c自身は登録されるがskipされる

つまり、method factoryの状態だけではfile-scoped hookによる実行停止を検出できません。`beforeEach`の登録簿も外部から空であることを検査するか、宿主ファイルでfile-scoped hookの宣言を禁止してください。

同様に `uses()` 等からskipを行うtraitやhookを宿主へ適用できないかも、同じ観点で確認が必要です。

### `tests/Support/Architecture/ArchBaseline.php`

`EXPECTED_CHAIN_FOOTER_TOKENS = ['}', ')', ';', '}']` は、直接の後置チェーンを禁止する契約として適切です。

ヘッダー・内部文・footerの3部分が単一の定数群に置かれ、gateと外部検査が同じ値を使っている点も問題ありません。

### `tests/Support/Architecture/ArchSurfaceScanner.php`

`tokensAfter()` は境界をfail-closedに処理しており正確です。

arrow functionに関する保証範囲も正しく狭められました。Round 4のSuggestionは解消しています。

### `tests/Unit/Architecture/ArchBaselineScannerTest.php`

13gは以下を適切に固定しています。

- headerと内部文だけではskip後置を識別できない
- footerで初めて差が出る
- 期待形とskip形の具体的なトークン差
- 前後双方の範囲外入力

[Critical] file-scoped `beforeEach`によるskipの負例がありません。後置修飾とは異なりfooterにもmethod factory差分にも現れないため、実際に注入して外部検査が赤になることを確認する必要があります。

### S4-3cのfactory比較

method単位のdeny-by-default検査としては妥当です。

- 可変フィールドだけを明示的に除外
- attributesを `Test` + `TestDox` の2件へexact-fit
- skip・todo・groupの異なる変化経路を注入確認
- vendor更新で公開状態が変われば赤になる

[Warning] ただし、この比較が保証するのは個々の `TestCaseMethodFactory` の状態だけです。file-scoped hook、suite設定、trait適用など、factory外部から実行を止める状態までは保証しません。D43の「実行可能な状態」という主張には、その外部状態の検査も必要です。

また、`get_object_vars()`で比較できるのは呼び出し位置からアクセス可能なプロパティです。将来vendorが非公開の修飾状態を追加した場合まで自動検出すると主張するなら、Reflectionまたは `(array)` キャスト等による全インスタンス状態の取得が必要です。

### `docs/template-divergence.md`

直接の `skip` / `todo` 後置については記述と実装が一致しています。

[Warning] 現状の「実行可能な状態で登録された」という記述はfile-scoped hookを含みません。hook経路を塞ぐまでは、保証をmethod factory単位に狭めるか、外部hook状態の検査を追加してください。

### その他のファイル

以下には新たな問題はありません。

- `tests/Support/Architecture/ArchTokenStream.php`
- `tests/Support/Architecture/GlobalFunctionCallScanner.php`
- `tests/Support/Architecture/VendorArchPresetReader.php`
- `tests/Support/Concurrency/ProcessBarrier.php`
- `tests/Support/TemplateDivergence/LedgerPins.php`

CHANGES_REQUESTED