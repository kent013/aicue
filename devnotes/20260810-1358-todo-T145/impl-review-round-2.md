仮説は「追加した検出器が exact-fit の主張を維持し、自己参照コントロールが fixture 自体に依存せず成立するか」です。数字境界と見出し検査は解消していますが、Architecture gate に修正必須の問題が残っています。

## `tests/Architecture/BillingRetentionConfigSingleSourceTest.php`

[Critical] 自己参照コントロールが SoT と逆で、fixture を壊しても緑になり得ます。

SoT が要求しているのは「gate ファイル自身を走査して hit 0 件」です。しかし現在は次を保証しています。

```php
expect(billingRetentionProseYearLiterals((string) $self, $years))->not->toBe([]);
```

これは自己参照による偽赤を防ぐ検査ではなく、「自己参照すれば必ず汚染されること」の固定です。また、gate 内には fixture 以外にも `7 年` 相当の説明文が複数あるため、対象 fixture の `最長 7 年間` を変更・削除しても別の記述で点灯し、緑のままになる可能性があります。したがって循環性への懸念は実在します。

fixture のリテラルを連結などで生ソース上に現れない形にし、gate 自身への detector 結果を `[]` にする必要があります。例えば fixture を次のように組み立てれば、検出器への負のコントロールと自己参照 hit 0 を両立できます。

```php
$asciiLiteral = '最長 7'.' 年間';
```

ただしコメントや assertion 名にも対象パターンを直接残さず、最後に以下を検査する必要があります。

```php
expect(billingRetentionProseYearLiterals((string) $self, $years))->toBe([]);
```

[Warning] `billingRetentionAliasNames()` は class import の文脈を確認しておらず、誤検出します。

現在は、末尾が `BillingRetention` のトークンに `T_AS` と `T_STRING` が続くだけで alias と認定します。このため少なくとも次を区別できません。

```php
use function Vendor\BillingRetention as Retention;
use Other\Domain\BillingRetention as Retention;
use Other\Domain\{BillingRetention as Retention};

trait Example {
    use SomeTrait {
        BillingRetention as Retention;
    }
}
```

特に PHP は関数 import と class import が別のシンボル表なので、次は成立し得ます。

```php
use function Vendor\BillingRetention as Retention;

Retention::years(); // 同一 namespace の class Retention
```

この呼び出しを SSOT 呼び出しとして誤認します。group use も `BillingRetention` 部分は拾えますが、group prefix が `App\Support\Legal` か確認していないため、正しい alias 解決とはいえません。

`use` 文の範囲、`T_FUNCTION`/`T_CONST` の不在、完全な import FQCN が `App\Support\Legal\BillingRetention` であることを確認してください。少なくとも以下の負のコントロールが必要です。

- `use function ... as Retention` は alias 0 件
- 別 namespace の `BillingRetention as Retention` は alias 0 件
- direct class import は 1 件
-正しい group class import は 1 件
- trait adaptation の `T_AS` は alias 0 件

## `tests/Feature/Legal/PrivacyRetentionDeclarationTest.php`

指摘なし。

数字境界の次の表現は、`u` 修飾子の下で ASCII 数字と全角数字をそれぞれ1コードポイントとして扱い、意図どおりです。

```regex
(?<![0-9０-９])7(?![0-9０-９])\s*年
```

`$years` は整数なので regex injection もありません。`17`、`70`、`１７` の負のコントロールも Round 1 の部分一致問題を適切に固定しています。

`privacyRetentionHeading()` の `h1`〜`h6` 判定も、「節見出し」を保証しつつ `h2` に過剰固定しない妥当な実装です。

## Round 1 対応状況

- alias import：部分対応。ただし class import の文脈・FQCNを確認しない新たな誤検出が残る
- 自己参照コントロール：未解消。SoT の hit 0 要件と逆
- 年数の部分一致：解消
- 見出し要素の確認：解消

## 全体判定

**CHANGES_REQUESTED**

自己参照コントロールは共通 SoT への明示的な不一致です。alias 検出も exact-fit を名乗るには文脈判定が不足しています。いずれも Architecture テスト内で完結する修正です。