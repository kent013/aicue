## 全体判定

**CHANGES_REQUESTED**

Round 4 の2件への対応方針は妥当です。ただし、導入したscope規則とR1の`use`検出が両立していないCriticalが1件残っています。

## 施策別判定

| 施策 | 判定 |
|---|---|
| 1 | APPROVE |
| 2 | APPROVE |
| 3 | APPROVE |
| 4 | APPROVE |
| 5 | REQUEST_CHANGES |
| 6 | APPROVE |
| 7 | APPROVE |
| 8 | APPROVE |
| 9 | APPROVE |

## 残る指摘

### 施策5: R1の`use`宣言は常にFileScopeになる

[Critical] R1は名前空間直下の`use`宣言を検出しますが、PHPのimportはクラス本体の外に記述されます。

```php
use Aws\S3\S3Client; // FileScope

final class TakeObjectStorage
{
}
```

一方、改訂後の規則はR1〜R5すべてに`NamedClass`帰属を要求し、`FileScope`を違反としています。そのまま実装すると、正規の`use Aws\...`を持つ全ファイルが違反します。

修正案: R1を実行siteではなく「ファイルの名前解決情報」として扱い、R2〜R5とは帰属規則を分けてください。

| 規則 | 扱い |
|---|---|
| R1 `use_import` | FileScopeを正常とする。alias mapを構築し、そのファイルの名前付きクラスへ境界到達の根拠として関連付ける |
| R2〜R5 | 実際の参照・呼び出しsiteのscopeを検査し、`NamedClass`のみ許可する |
| R6 | scopeを問わず、相対パス・シンボル・件数で検査する |

1ファイルに複数の名前付きクラスがある場合の帰属も明示してください。保守性を優先するなら、R1対象importを持つ`app/`ファイルは名前付きクラスちょうど1件を要求し、0件または複数件ならfail-loudにするのが単純です。複数クラスを許容するなら、import自体では母集団へ登録せず、R2〜R5で実際にaliasが使われたクラスへ帰属させます。

また、走査精度テストの次の表現は旧仕様の残骸です。

```text
匿名クラス内の site は null 帰属として違反になる
```

`ScanScopeKind`導入後は、次へ更新してください。

```text
匿名クラス内の site は AnonymousClass 帰属として違反になる
```

施策6のclosure直接PHPDoc、setter件数exact-fit、`Assert` importは適切に解消されています。上記R1の帰属規則を分離すれば、設計上の残件は解消します。