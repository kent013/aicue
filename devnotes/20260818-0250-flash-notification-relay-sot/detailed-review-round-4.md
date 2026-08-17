## 結論

まだAPPROVEDにはできません。Criticalはありませんが、検査そのものに残るWarningが2件あります。

`session()` / `uuid()`の0件固定と、文字列リテラル`'flash'`の禁止を追加した判断は受け入れられます。

## 施策1: PHP字句列検査

判定: REQUEST_CHANGES

[Warning] 字句を1本の文字列へ連結した後の部分文字列検索は、トークン境界を保証しません。

例えば次のクラス名でも、検索文字列が接尾辞として一致します。

```php
NotFlashNotificationRelay::SHARED_PROP_KEY
    => NotFlashNotificationRelay::payload($request),
```

連結後には次の文字列が含まれるためです。

```text
NotFlashNotificationRelay :: SHARED_PROP_KEY
```

`substr_count()`相当なら、`FlashNotificationRelay :: SHARED_PROP_KEY`が1件と数えられます。したがって「クラス名込みの完全一致」にはなっていません。

修正案:

- 正規化した結果を文字列ではなく`list<string>`のトークン列として保持する
- 期待値もトークン列にする
- `array_slice()`によるスライド比較で、各トークンが完全一致した箇所だけ数える

概念例:

```php
$delegation = [
    'FlashNotificationRelay',
    '::',
    'SHARED_PROP_KEY',
    '=>',
    'FlashNotificationRelay',
    '::',
    'payload',
    '(',
    '$request',
    ')',
];

expect(phpTokenSequenceCount($middleware, $delegation))->toBe(1);
```

負のコントロールとして、`NotFlashNotificationRelay`が一致しないことも固定してください。

なお、`'fl'.'ash'`のような意図的な計算キーまでは検出しないなら、その点は保証範囲へ明記すれば十分です。

## 施策2・3・4

判定: APPROVE

- TSの負のコントロール
- `$location`のPHPによるnarrowing
- flash lifecycle
- 中継クラス本体

はいずれも問題ありません。

## 施策5: TS構文木検査

判定: REQUEST_CHANGES

[Warning] 第一引数のcallee名が`readFlash`であることだけでは、正規の`readFlash`を通ったと証明できません。

次のコードは現在の検査を通ります。

```ts
import { consumeFlash } from "@/lib/stores/flash-to-toast";

const readFlash = (props: unknown) => {
    // 独自の直接読み
    return (props as { flash?: unknown }).flash;
};

consumeFlash(readFlash(page.props));
```

- 正規モジュールから`consumeFlash`をimportしている
- `consumeFlash`の第一引数callee名は`readFlash`
- しかし正規の`readFlash`は使っていない

修正案:

各consumerについて、少なくとも以下をASTで固定してください。

- `consumeFlash`が対象モジュールからnamed importされている
- `readFlash`も同じ対象モジュールからnamed importされている
- aliasされず、local名もそれぞれ`consumeFlash` / `readFlash`
- 同名の関数・変数・parameterによるshadowingがない

より厳密にはTypeScriptのsymbol解決を使い、両calleeの宣言元が対象ImportSpecifierであることを検査します。

簡易方式なら、対象script内の`consumeFlash` / `readFlash`というbinding宣言を列挙し、それぞれ正規モジュールのImportSpecifier以外に存在したらfailさせても構いません。local `readFlash`を置いた負のfixtureも追加してください。

## 全体判定

**CHANGES_REQUESTED**

残る必須修正は次の2点です。

1. PHPの委譲検査を部分文字列検索ではなく、トークン配列の完全一致へ変更する
2. TSの`readFlash`が同名の別関数ではなく、正規モジュールからimportされたbindingであることを固定する

この2点が解消されれば、提示された詳細設計はAPPROVEDにできる状態です。