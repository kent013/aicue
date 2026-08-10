## `tests/Architecture/BillingRetentionConfigSingleSourceTest.php`

[Critical] `T_NAME_RELATIVE` が走査対象外なので、namespace-relative な直接呼び出しで caller inventory を迂回できます。

PHP 8 では、次のクラス名は `T_NAME_RELATIVE` として token 化されます。

```php
namespace App\Support\Legal;

namespace\BillingRetention::years();
```

これは `App\Support\Legal\BillingRetention::years()` の有効な直接呼び出しですが、現在の許可 token は以下だけです。

```php
T_STRING
T_NAME_QUALIFIED
T_NAME_FULLY_QUALIFIED
```

したがって `ssotCall` が 0 になり、未登録ファイルを検査6が検出できません。動的呼び出しではなく静的に解決可能な直接呼び出しなので、既知の非対応範囲にも該当しません。

`billingRetentionScanSource()` のクラス名 token 判定へ `T_NAME_RELATIVE` を追加してください。既存の最終セグメント判定にそのまま流せば検出できます。

```php
if (! in_array($id, [
    T_STRING,
    T_NAME_QUALIFIED,
    T_NAME_FULLY_QUALIFIED,
    T_NAME_RELATIVE,
], true)) {
    continue;
}
```

負のコントロールには大小文字も含めた形が適切です。

```php
namespace\billingretention::YEARS();
```

`billingRetentionAliasNames()` 側は、通常の class import に `namespace\...` を使用する対応は不要です。caller token の集合と import parser の token 集合を別々に定義すると、保証範囲が明確になります。

## Case 正規化

指摘なしです。

以下の4箇所すべてが正規化されています。

- import FQCN
- 保存する alias
- 呼び出し側のクラス最終セグメントと alias
- `years` / `threshold` メソッド名

`strtolower()` による新たな実用上の過検出もありません。既存の「別 namespace の同名クラスを意図的に過検出する」という方針とも整合しています。

## Mixed Group Use

指摘なしです。

`function` / `const` を entry 単位で読み飛ばし、カンマ後の class entry へ解析を継続できています。次の形はいずれも扱えます。

- symbol entry が先
- symbol entry が後
- symbol entry 自体に alias がある
- 複数行
- 深い group prefix
- group entry が `Legal\BillingRetention` のような qualified name

PHP の group use は入れ子にできないため、再帰的な group parser は不要です。

## その他

自己参照コントロール、数字境界、Feature 側の節見出し検査について、前ラウンドまでの修正を損なう変更はありません。

文字列変数、可変クラス、`call_user_func()`、可変メソッドなどの動的・間接呼び出しを保証外とする記述も妥当です。一方、今回の `namespace\BillingRetention::years()` は静的な直接呼び出しなので、その保証外には含められません。

## 全体判定

**CHANGES_REQUESTED**

Round 3 の2件は解消していますが、`T_NAME_RELATIVE` という有効な直接呼び出しで exact-fit inventory を迂回できる穴が1件残っています。