## `tests/Architecture/BillingRetentionConfigSingleSourceTest.php`

指摘なし。

`T_NAME_RELATIVE` が呼び出し側専用の token 集合に追加され、以下の静的な直接呼び出しを網羅しています。

- `BillingRetention::years()`
- `Legal\BillingRetention::years()`
- `\App\Support\Legal\BillingRetention::years()`
- `namespace\BillingRetention::years()`
- class import alias 経由
- group use / mixed group use の alias 経由
- クラス名・alias・メソッド名の大小文字違い

`namespace\BillingRetention` も既存の最終セグメント判定へ流れるため、追加処理に特有の穴はありません。`T_NAME_RELATIVE` を import parser 側へ含めない分離も正しいです。

`self::years()`、`parent::years()`、`static::years()` は字面だけでは対象クラスを決定できず、継承・実行コンテキストの解析が必要になるため、今回保証外としている動的・間接的な呼び出しと同じ側に属します。通常のクラス名を明示した静的直接呼び出しとして、これ以上の未検出形は見当たりません。

負のコントロール、母集団への `T_NAME_RELATIVE` 登録確認、実ファイル mutation の三段階も有効です。

## 全体判定

**APPROVED**