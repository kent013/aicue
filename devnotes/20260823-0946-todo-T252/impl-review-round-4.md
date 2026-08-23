### `tests/Architecture/ArchBaselineTest.php`

Round 3の先行 `return;` 指摘は解消しています。テスト38が別ファイルから宿主を検査するため、宿主側の自己検査が登録されない場合でも検出できます。

`topLevelControlStructureSites()`との組み合わせにより、ファイル全体を代替構文で囲む自主監査の穴も塞がれています。

[Critical] 禁止表明を登録したまま、7本すべてをskipして無力化する経路が残っています。

現在固定しているのは、closureのヘッダーと内部のexpectation文までです。`test()` 呼び出しを閉じた後のsuffixは検査していません。そのため、次の変更がすべての検査を通過し得ます。

```php
foreach (ArchBaseline::ruleIds() as $ruleId) {
    test(ArchBaseline::descriptionOf($ruleId), function () use ($ruleId): void {
        expect(ArchBaseline::symbolsOf($ruleId))
            ->not->toBeUsed()
            ->ignoring(ArchBaseline::exceptionsOf($ruleId));
    })->skip();
}
```

この形では、

- `EXPECTED_CHAIN_HEADER_TOKENS`は一致
- `EXPECTED_CHAIN_TOKENS`も一致
- 最上位制御構造は期待する `foreach` 1件
- abortは0件
- 7規則のdescriptionはPestへ登録済み
- S4-3cの`missing`は空

となりますが、closureが実行されないため、禁止表明は評価されません。`->todo()`など同種の実行修飾も確認対象です。

S4-3cは「登録」を確認していますが、「実行可能な状態で登録されたこと」は確認していません。次のいずれかが必要です。

- `test(...)` 全体を閉じる `});` まで含めて完全な生成文を固定し、後置チェーンを許さない
- Pest登録簿から7規則のskip/todo等の状態も検査する
- 外部テストから禁止語彙を含むfixtureを実際に評価し、7規則が実行されることを固定する

注入時には、終了コードだけでなくskip件数と7規則の実行結果を確認してください。

### `tests/Support/Architecture/ArchSurfaceScanner.php`

`topLevelControlStructureSites()`の8語は、今回対象とする最上位の条件付き実行に対して妥当です。

- `if` / `while` / `do` / `for` / `foreach` / `switch` / `try` は適切
- `match`はやや保守的ですが、拾いすぎ側なので安全
- `declare`を除外する判断は妥当
- `catch` / `finally` / `else`を開始側と重複して数えない判断も妥当
- ちょうど1件かつ期待する`foreach`の位置と一致させる契約は十分に強い

[Suggestion] 「関数・クロージャ本体のトークンは数えない」という説明は、波括弧を持たないarrow functionには厳密には当てはまりません。

```php
$fn = fn () => throw new RuntimeException();
$value = fn () => match ($x) { default => 0 };
```

この中の `throw` / `match` は波括弧深さ0として数えられます。これは安全側の誤検出であり現在の宿主契約には支障ありませんが、保証範囲には「arrow functionの式内部は区別しない」と記載すると正確です。

### `tests/Unit/Architecture/ArchBaselineScannerTest.php`

テスト38は別ファイルに置かれており、Round 3の自己参照問題を正しく解消しています。

13fも以下を両方向で固定しており適切です。

- 代替構文とbrace-less構文の外側 `if` を検出
- 期待する `foreach` も同時に検出
- 波括弧内の制御構造を除外
- `declare`を除外

[Critical] `test(...)->skip()`または同等の後置実行修飾の負例がありません。テスト38は登録名しか見ないため、この注入でも緑になる可能性があります。実際のsuffix形を負例として追加し、gateが赤になることを固定してください。

### `docs/template-divergence.md`

Round 3のWarningは解消しています。打ち切り形と条件付き実行形を分け、外部自己検査の担当範囲を正しく記述しています。

[Warning] 「7本が実際にPestへ登録されたこと」は現在も真ですが、それだけでは禁止表明が実行されることを保証しません。`skip`等を塞いだ後は、「実行可能な状態で登録されている」ことまで保証機構へ反映してください。

### `tests/Support/Architecture/ArchBaseline.php`

今回の差分に問題はありません。ただし、上記の後置 `skip` を防ぐには、内部expectationだけでなく `test(...)` 呼び出し全体の終端形も正本化する必要があります。

### `tests/Support/Architecture/ArchTokenStream.php`

問題ありません。

### `tests/Support/Architecture/GlobalFunctionCallScanner.php`

問題ありません。

### `tests/Support/Architecture/VendorArchPresetReader.php`

問題ありません。

### `tests/Support/Concurrency/ProcessBarrier.php`

問題ありません。

### `tests/Support/TemplateDivergence/LedgerPins.php`

問題ありません。

### 全体判定

Round 3の先行 `return` と自主監査の代替構文による無力化は解消しています。最上位短絡に対する8語の選択、外部検査、件数・位置一致はいずれも妥当です。

一方、登録後の `->skip()` により7規則を評価せずに全検査を通す経路が残っています。

CHANGES_REQUESTED