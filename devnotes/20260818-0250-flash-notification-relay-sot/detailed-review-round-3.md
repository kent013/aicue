## 結論

まだAPPROVEDにはできません。Criticalはありませんが、Warningが3件残ります。

`session()` / `uuid()` の0件固定は防御層として残して構いません。ただし、説明されている抜け道を完全には塞いでいません。

## 施策1: middleware委譲

判定: REQUEST_CHANGES

[Warning] 別helperによる後勝ち上書きが依然可能です。

次の実装は現在の検査を通過できます。

```php
FlashNotificationRelay::SHARED_PROP_KEY
    => FlashNotificationRelay::payload($request),

'flash' => OtherFlashBuilder::build($request),
```

- 正しい委譲entry: 1回
- `SHARED_PROP_KEY`: 1回
- `FlashNotificationRelay::payload()`: 1回
- middleware内の`session()` / `uuid()`: 0回
- `KINDS`の文字列: なし

別helperが同じ形を返せばFeatureテストも通ります。したがって「別名helper経由はクラス名込み検査で塞がる」という説明は成立しません。

修正案:

```php
expect(phpStringLiterals($middleware))
    ->not->toContain(FlashNotificationRelay::SHARED_PROP_KEY);
```

を加え、middleware内で`'flash'`をキーとして再定義できないようにしてください。

より厳密には、`share()`が返すトップレベル配列をASTまたは字句構造で調べ、

- 解決可能な`flash`キーがちょうど1つ
- そのvalueが`FlashNotificationRelay::payload($request)`
- 同じキーのliteral表記と定数表記が併存しない

ことを検査するのが望ましいです。

`session()` / `uuid()`の0件固定は、このキー一意性検査に追加する防御としてなら受け入れられます。ただし、それ単独でhelper経由を防ぐとは記述しないでください。

## 施策2: TS負のコントロール

判定: APPROVE

`await`の位置は正しく修正されています。正例、抽出不能、空配列、存在しない定数名の検査も揃っています。

## 施策3: flash lifecycleテスト

判定: REQUEST_CHANGES

[Warning] `$location`が再びPHPStan上で`?string`のまま使用されています。

```php
expect($location)->toBeString();
$flash = renderedFlash($this->get($location));
```

PestのExpectationが後続変数を型narrowingする保証には依存できません。

修正案:

```php
$location = $this->get('/__test/flash-relay-origin')
    ->headers
    ->get('Location');

if (! is_string($location)) {
    throw new RuntimeException('flash 着地先の Location がありません');
}

$flash = renderedFlash($this->get($location));
```

または、リポジトリで採用済みのAssertライブラリがあるなら、その文字列assertを使用してください。

## 施策5: 消費経路検査

判定: REQUEST_CHANGES

[Warning] `filesCalling("consumeFlash(")`と`toContain("consumeFlash(readFlash(")`は、実際の呼び出しではなくコメントでも成立します。

例えばLayoutの実装を削除して次だけ残しても緑になります。

```ts
// consumeFlash(readFlash(
```

これはdegenerate PASSです。また、文字列リテラル中の同じ文字列も呼び出し元として数えられます。

修正案:

- コメントと文字列を除外した字句列で`consumeFlash`のCallExpressionを検出する
- 可能ならTypeScript/SvelteのASTで、calleeと第一引数を検査する
- 第一引数が`readFlash(...)`のCallExpressionであることを確認する
- comment-only fixtureを負のコントロールにする

最低限、次のfixtureが呼び出しとして数えられないことを自己検証してください。

```ts
// consumeFlash(readFlash(page.props));
const example = "consumeFlash(readFlash(";
```

一方、実コードの次の形は1件として数える必要があります。

```ts
consumeFlash(readFlash(page.props));
```

## 全体判定

**CHANGES_REQUESTED**

残る必須修正は次の3点です。

1. literalの`'flash'`またはトップレベル配列キー解析により、後勝ち上書きを防ぐ
2. `$location`をPHPの実assertで`string`へnarrowingする
3. TSの消費経路検査を生文字列検索から字句・ASTベースへ変更し、comment-only負例を追加する

`session()` / `uuid()`の0件固定を残す判断自体には反対しません。キー一意性検査と組み合わせ、保証範囲の説明を修正すれば受け入れ可能です。