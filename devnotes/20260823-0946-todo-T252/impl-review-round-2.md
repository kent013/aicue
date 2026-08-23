### `tests/Architecture/ArchBaselineTest.php`

[Critical] PHPStan指摘は未解消です。

vendor側の型情報不足を2行で再現できることは、エラーの原因を明確にしていますが、受入条件を満たしたことにはなりません。詳細設計には以下が明記されています。

- PHPStan level 10必須
- 新設パスを指定した解析を「受入条件」とする
- 当該コマンドを設計固有の検証コマンドとして追加する

したがって「1度実行して既知の4エラーを確認すればよい」という解釈は成立しません。docblockへの説明追加も、コマンドが赤い事実を変えません。

また、「消す手段は3つしかない」「Pest archを使う限りどう書いても消えない」も証明されていません。例えば、実行時mixinを表す狭いinterfaceやPHPStan拡張、型付きの宣言境界などは検討余地があります。現在のチェーン形を変える必要があるなら、実装後に受入条件を読み替えるのではなく、詳細設計へ戻して再レビューすべきです。

[Critical] S4-3bでも禁止表明を静かに無効化できます。波括弧深さは「トップレベルの実行可能文」であることを証明しません。

次のコードは有効なPHPです。

```php
if (false)
    foreach (ArchBaseline::ruleIds() as $ruleId) {
        arch(ArchBaseline::descriptionOf($ruleId))
            ->expect(ArchBaseline::symbolsOf($ruleId))
            ->not->toBeUsed()
            ->ignoring(ArchBaseline::exceptionsOf($ruleId));
    }
```

この場合も、

- `tokensBefore()` は期待する11トークンと一致
- `foreach` 位置の波括弧深さは0
- `arch` 位置の波括弧深さは1
- チェーンのトークン列も完全一致
- 後続のS1〜S5は通常どおり登録・実行

となりますが、禁止表明7本だけは登録されません。`if (false): ... endif;` の代替構文でも同様です。

したがってS4-3bは、Round 1で指摘した「実際に7規則が登録されたこと」をまだ保証していません。Pestの登録数を外部テストから固定するか、AST・トークン構造により `foreach` がファイル直下の文であり、到達不能化する先行文や親制御構文がないことまで検査する必要があります。

### `tests/Support/Architecture/ArchBaseline.php`

追加された `EXPECTED_CHAIN_HEADER_TOKENS` 自体は正確です。ただし、これだけでは上記のbrace-less制御構文を排除できません。

それ以外の規則・例外・件数pinに新たな問題はありません。

### `tests/Support/Architecture/ArchSurfaceScanner.php`

`tokensBefore()` は範囲外を例外にしており、局所的な実装は正確です。

`braceDepthAt()` も以下の点は適切です。

- `T_CURLY_OPEN` と `T_DOLLAR_OPEN_CURLY_BRACES`を加算
- 通常の `{` / `}` を加減算
- `TOKEN_PARSE`を前提に到達不能な負深度分岐を作らない
- index境界をfail-closedにする

ただし、波括弧深さから「ファイル最上位の実行可能文」を導くことはできません。brace-less `if`、代替構文、先行する `return` などは深さに現れません。走査器のバグというより、S4-3bでこの値に持たせている保証が強すぎます。

### `tests/Unit/Architecture/ArchBaselineScannerTest.php`

7bの追加により、共有トークン入口のfail-closed契約は公開境界ごとに固定されました。Round 1の指摘は解消しています。

13b・13dも適切です。

[Warning] 13cの負例が波括弧付き `if (false) { ... }` だけなので、今回の判定方式が見逃すbrace-less制御構文を固定できていません。最低でも次の負例が必要です。

```php
if (false)
    foreach (...) {
        arch(...);
    }
```

この負例を追加すると、現行S4-3bが赤にならないことが確認されるはずです。

### `tests/Support/Concurrency/ProcessBarrier.php`

コメントは保証範囲に合わせて適切に縮小されました。Round 1のSuggestionは解消しています。コード変更の機能的等価性にも問題ありません。

### `tests/Support/Architecture/ArchTokenStream.php`

変更なし。共有入口を公開APIごとに検証する7bが追加されたため、Round 1のテスト不足は解消しています。

### `tests/Support/Architecture/GlobalFunctionCallScanner.php`

変更なし。問題ありません。

### `tests/Support/Architecture/VendorArchPresetReader.php`

変更なし。7bにより不正PHP入力のfail-closed契約も固定されました。

### `docs/template-divergence.md`

変更なし。D43の採番・件数・対象パスに新たな問題はありません。

### `tests/Support/TemplateDivergence/LedgerPins.php`

変更なし。40件pinは文書と一致しています。

### `devnotes/.../conceptual-design.md`

変更なし。V1訂正が既に反映済みであるという扱いは妥当です。

### 検証結果

`composer test -- --filter=ArchBaseline` の緑は確認材料になりますが、現在の自己検査がbrace-less無効化を検出しないため、78件greenだけでは禁止表明7本の恒久的な登録保証になりません。

`pnpm test`の既存失敗は、clean mainで同一失敗を再現できているならT252の回帰とは判断しません。ただし別TODOでの追跡は必要です。

CHANGES_REQUESTED