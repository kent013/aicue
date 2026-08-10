## `tests/Architecture/BillingRetentionConfigSingleSourceTest.php`

[Critical] PHP の名前解決が大文字・小文字を区別しない点により、caller inventory を迂回できます。

現在の比較はすべて case-sensitive です。

```php
$fqcn === BILLING_RETENTION_FQCN
in_array($value, $aliases, true)
end($segments) !== 'BillingRetention'
in_array($tokens[$method][1], ['years', 'threshold'], true)
```

一方、PHP のクラス名、import alias、メソッド名は大文字・小文字を区別しません。したがって、例えば以下の有効な呼び出しを検出できません。

```php
use App\Support\Legal\BillingRetention as Retention;

retention::years();
Retention::YEARS();
\App\Support\Legal\billingretention::threshold();
```

alias 収集時の FQCN、alias 利用時、最終セグメント、メソッド名をすべて case-insensitive に比較する必要があります。正規化して比較する形が単純です。

```php
strtolower(ltrim($fqcn, '\\')) === strtolower(BILLING_RETENTION_FQCN)
```

alias 一覧も小文字で保持するか、呼び出し検査時に双方を小文字化してください。負のコントロールには、alias・クラス名・メソッド名それぞれの大小文字違いが必要です。

[Warning] mixed group use で、`function` または `const` entry が先にあると後続 entry を取りこぼします。

PHP の group use は entry ごとに種別を指定できます。

```php
use App\Support\Legal\{
    function helper,
    BillingRetention as Retention
};

Retention::years();
```

現在は最初の entry の `T_FUNCTION` が `$nameTokenIds` に含まれないため、use 文全体の解析を `break` します。結果として後続の正しい class import alias が登録されません。

group use では `T_FUNCTION` / `T_CONST` entry を次のカンマまたは閉じ波括弧まで読み飛ばし、その後の entry の解析を継続する必要があります。逆順も検査すると挙動が明確になります。

```php
use App\Support\Legal\{
    BillingRetention as Retention,
    function helper
};
```

group use の入れ子は PHP の有効な import 構文として考慮不要です。深い prefix、複数行、通常のカンマ区切り、alias の `array_unique()` については、提示された実装で問題ありません。

[Suggestion] 最終セグメント一致による意図的な過検出は妥当です。

deny-by-default inventory では、未知の同名クラスをレビュー対象として赤化する判断には合理性があります。別 namespace の alias は FQCN 厳密一致で除外するという非対称性も docblock に明記されており、保証範囲を誇張していません。

ただし、上記の case-insensitive 対応後もこの説明を維持するなら、「最終セグメント一致」も大文字・小文字を区別しないことになります。

## 自己参照コントロール

指摘なしです。

`billingRetentionProseYearLiterals($self, $years)` を `[]` と照合しており、SoT の hit 0 要件に一致しています。負のコントロールは実行時の文字列連結で生成されるため、生ソースを走査する自己参照検査を汚染しません。

また、gate に生の対象表記を追加すると検査7が赤くなるため、以前のような「fixture が壊れても別の記述で点灯して緑」という循環は解消しています。正の自己検証を検査8へ分離した役割分担も適切です。

## Round 2 対応状況

- 自己参照コントロール：解消
- `use function` / `use const` の単独 import 誤検出：解消
- 別 namespace alias の誤検出：解消
- trait adaptation / closure use の誤検出：解消
- alias による inventory 迂回：大小文字違いと mixed group use で残存

## 全体判定

**CHANGES_REQUESTED**

自己参照問題は解消していますが、有効な PHP 構文で caller inventory を迂回できるため、exact-fit gate としてはもう一段の修正が必要です。