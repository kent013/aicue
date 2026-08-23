## ファイル別判定

### `tests/Architecture/ArchBaselineTest.php`

[Critical] 設計固有の PHPStan level 10 受入条件を満たしていません。  
[禁止表明](/workspace/.claude/worktrees/tasks/T252/tests/Architecture/ArchBaselineTest.php:117) に対する4エラーを「Pest の動的 mixin なので残る」と説明していますが、詳細設計はこのファイルを含む追加コマンドを必須検証にしています。通常の `composer phpstan` が緑でも代替になりません。

`@phpstan-ignore`、baseline、`mixed` への widen を使わず、Pest の型情報を正しく与えるか、型検査可能な宣言方式へ変更して、指定コマンドを0エラーにする必要があります。これが不可能なら、実装後の注記ではなく詳細設計の再レビューが必要です。

[Warning] S4 はチェーンの文そのものしか固定しておらず、7規則が実際に Pest へ登録されたことを固定していません。  
現在はトップレベルの `foreach` なので動作しますが、次のようにチェーンを変えずに gate を無力化できます。

```php
if (false) {
    foreach (ArchBaseline::ruleIds() as $ruleId) {
        // 現在と完全に同じチェーン
    }
}
```

この場合、S4-1〜S4-6、S1〜S5は通り得ますが、禁止表明7本は登録されません。V3のスパイク結果は恒久的な自己検査ではありません。生成された7テストの登録数を実行時に固定するか、少なくともチェーンがトップレベルの `foreach` にあることまで検査してください。

それ以外のS1〜S5、例外クラスのPest母集団包含、前方一致衝突、母集団の床値・代表値検査は設計どおりです。

### `tests/Support/Architecture/ArchBaseline.php`

問題ありません。

- AB-1〜AB-7、97語彙、規則別件数pinは設計と一致
- AB-7の3クラス化は、T246後の実測に基づく逸脱Bとして妥当
- 例外を持つ規則が単一シンボルであるため、シンボル方向の波及半径は維持
- 型のwiden、baseline、PHPStan抑制はありません

### `tests/Support/Architecture/ArchTokenStream.php`

逸脱Aは妥当です。既存の `PhpTokenScan` の挙動を変更せず、3走査器で `TOKEN_PARSE` と例外変換を共有する判断は、fail-closedと重複排除の両面で合理的です。

[Warning] ただし、共有化後のfail-closed契約を呼び出し口ごとに固定できていません。現在の不正PHPテストは `GlobalFunctionCallScanner` 経由だけです。将来 `ArchSurfaceScanner` または `VendorArchPresetReader` がこの共通入口を外しても、既存の正例は通る可能性があります。少なくとも3走査器それぞれについて不正PHPが例外になる負例を置くべきです。

### `tests/Support/Architecture/ArchSurfaceScanner.php`

概ね正確です。

逸脱Dの「importでは各名前トークンの末尾セグメントだけを見る」は妥当です。取り込まれる記号は、元名の末尾またはalias自身として必ず末尾セグメントに現れます。名前空間の中間セグメントは取り込まれる記号ではないため、除外しても穴にはなりません。25cも、この判断の正例とalias側の負例を固定しています。

以下も設計に一致しています。

- 大小無視の完全一致
- 修飾名・完全修飾名・namespace相対名を拾いすぎ側へ倒す
- 呼び出しとimportを区別
- 動的メンバ5形の検出
- `::$var`を直後の `(` で静的プロパティと区別
- コメント・文字列を実行識別子として数えない
- 到達不能な `unresolved` 結果を持たない

上記の不正PHPテスト不足を除き、共通規約(b)〜(e)への重大な不適合は見当たりません。

### `tests/Support/Architecture/GlobalFunctionCallScanner.php`

問題ありません。

S2が「使用の証明」であるため狭く数える方向、大小を区別してPestの `===` に合わせる方向、接頭辞・打ち消し・接尾辞を完全一致で除外する方向はいずれも正しいです。0件でもキーを残す点も、母集団消失との区別に有効です。

### `tests/Support/Architecture/VendorArchPresetReader.php`

実装上の重大な見逃しは見当たりません。

- 配列なし・複数配列・式・キー・spread・ネスト・二重引用符・未知escapeがfail-closed
- `expect([...])->not->toBeUsed()` の直結形だけを採用
- vendor更新によるソース表現変更を意図的に赤にする
- Reflectionでパスを解決し、vendorパスを直書きしていない

前述のとおり、この公開API自身に対する不正PHPの負例は追加が必要です。

### `tests/Unit/Architecture/ArchBaselineScannerTest.php`

正例・負例の構成はかなり充実しています。特に以下は適切です。

- 共通規約(e)の3形をcall/import双方で固定
- 大小のS2/S4非対称を固定
- group use、mixed group use、カンマ区切り、aliasを固定
- 動的メンバと静的プロパティの境界を隣接入力で固定
- `call.index` が行番号より強いことを実証
- S3の包含・前方一致・正規化を同じ本体関数で検証

[Warning] `ArchSurfaceScanner` と `VendorArchPresetReader` の「不正PHPなら例外」という公開契約の負例がありません。Aで共通化した実装を信頼するだけでなく、各公開境界が共通入口を通り続けることをテストで固定してください。

### `tests/Support/Concurrency/ProcessBarrier.php`

逸脱Cのコード変更自体は等価で、機能上妥当です。

[Suggestion] コメントの「callable経由の迂回口を塞ぐ」は主張が広すぎます。`$reader(...)` はそれ自体が可変callableの第一級callable構文であり、S4が明示的に保証外とする経路です。ここで塞いでいるのは `fromCallable` という特定語彙だけだと記述を狭めてください。

### `docs/template-divergence.md`

逸脱EのD43・40件への追随は、提示されたworktree実測に基づいており妥当です。Aで増えた `ArchTokenStream.php` も対象パスへ追加されています。

規則数だけが正典との差で、分解原則・S1〜S5・vendor集合一致を維持している説明も適切です。

### `tests/Support/TemplateDivergence/LedgerPins.php`

40件への更新は文書の宣言・実エントリ数と一致しており問題ありません。

### `devnotes/.../conceptual-design.md`

逸脱Fについて、V1訂正が既に現行文書へ反映済みなら追加差分がないことは妥当です。実装側の説明は完全一致判定へ統一されています。

## その他の観点

- DTO / JsonResource・HTTP経路: 変更なし
- DESIGN.md / Atomic Design: `resources/js`・`resources/css`変更なし
- アプリのNorth Star: 本件は開発時gateであり、使命との衝突なし
- `pnpm test`の1件失敗: clean mainでも再現するならT252の回帰とは判断しません。ただし、AGENTS.mdの「全コマンドgreen」という完了条件は別途解消または正式に裁定する必要があります
- A〜Fのうち、A・B・D・E・Fは妥当、Cは機能的には妥当ですが保証説明の縮小が必要です

CHANGES_REQUESTED