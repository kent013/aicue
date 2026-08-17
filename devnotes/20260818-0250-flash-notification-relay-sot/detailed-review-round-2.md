## 結論

Round 1の主要な反例はほぼ解消されています。

- `KINDS` を使ったmiddleware内の直接組み立て: 赤になる
- `page.props.flash` / `shared.flash` の再導入: 現在のドット記法なら赤になる
- truthyな非文字列のtoast流入: 防止される
- PHPStan上の`mixed`オフセットアクセス: 解消
- flash lifecycle: 実経路で固定される

ただし、まだ3点修正が必要です。Criticalはありません。

## 横断・Step 0

判定: APPROVE

正典照合を実装前へ移し、取得不能をblockedとし、commit SHAを含む照合記録を残す条件は十分です。

## 施策1: PHPレーン

判定: REQUEST_CHANGES

[Warning] `payload()`という呼び出し名だけを数えており、中継クラスへの委譲を証明していません。

例えば次は検査を通過できます。

```php
FlashNotificationRelay::SHARED_PROP_KEY => OtherFlashBuilder::payload($request),
```

- `payload()` は1回
- `SHARED_PROP_KEY` は存在
- middleware内に`session()` / `uuid()`はない
- 種別リテラルもない

しかし`FlashNotificationRelay::payload()`には委譲していません。

修正案は、字句列として次の対応関係をちょうど1回検査することです。

```php
FlashNotificationRelay::SHARED_PROP_KEY
    => FlashNotificationRelay::payload($request),
```

最低限、以下をクラス名込みで検査してください。

- `FlashNotificationRelay::SHARED_PROP_KEY` の使用がちょうど1回
- `FlashNotificationRelay::payload(...)` の呼び出しがちょうど1回
- 両者が同じ配列entryのkey/valueになっている

`session()` / `uuid()`の全禁止はこの正確な検査を入れれば必須ではありません。現状の全禁止はflash以外の将来のsession利用まで巻き込む一方、別名helper経由の組み立ては検出できないため、保証範囲と制約範囲が一致していません。

## 施策2: TSレーン

判定: REQUEST_CHANGES

[Warning] 提示された負のコントロールにTypeScriptの構文エラーがあります。

`await`が非`async`のコールバック内にあります。

```ts
expect(() => extractStringConstant(await readRelay(), "NO_SUCH_CONSTANT"))
```

修正案:

```ts
it("抽出できない定数名は fail する", async () => {
    const source = await readRelay();

    expect(() =>
        extractStringConstant(source, "NO_SUCH_CONSTANT"),
    ).toThrow(/degenerate PASS/);
});
```

`extractKinds()`の正例・抽出不能・空配列の自己検証は十分です。

## 施策3: Featureテスト

判定: APPROVE

`renderedFlash()`の型narrowing、全種別×複数の不正値、本物のredirect flashの一回性まで検査できています。

テスト用routeは各テストのApplication lifecycle内に閉じるため、通常のPest実行では他テストへ残りません。`Location`が取得できなかった場合に黙って`/login`へフォールバックすると原因が隠れるため、可能なら次のようにfail-closedにするのがより明確です。

```php
$location = $landing->headers->get('Location');

expect($location)->toBeString();

$flash = renderedFlash($this->get($location));
```

これはSuggestion相当で、承認を妨げません。

## 施策4: 中継クラス

判定: APPROVE

前回どおり問題ありません。文字列正規化、戻り値型、Inertia Propsの利用、既存評価時点の維持はいずれも妥当です。

## 施策5: frontend

判定: REQUEST_CHANGES

`readFlash()`と`consumeFlash()`の実行時安全性は修正されています。残る問題は利用強制の走査方法です。

[Warning] `/\.flash\b/`はdeny-by-defaultの直接読み検査として不完全かつ過剰です。

見逃す例:

```ts
page.props["flash"]
const { flash } = page.props;
Reflect.get(page.props, "flash");
```

誤検知する例:

```ts
camera.flash
validation.flash
```

コメント中の`.flash`も対象になり得ます。つまり「flash共有propの直接読み禁止」ではなく「`.flash`という文字列の禁止」になっています。

修正案として、検査対象をプロパティ表記ではなく、実際の消費入口へ寄せてください。

- `consumeFlash`のアプリコード上の呼び出し元を完全一致のinventoryにする
- 3つのLayoutで呼び出し式が`consumeFlash(readFlash(...))`になっていることを固定する
- `flash-to-toast.ts`以外から`consumeFlash`を呼ぶ場合はinventory更新を必須にする
- 可能ならTypeScript ASTでcall expressionを検査する
- 文字列検査を使うなら、Pint相当の固定書式で`consumeFlash(readFlash(`を検査し、保証範囲をその形式に限定すると明記する

この方法なら、ドット記法・ブラケット記法・分割代入のどれで値を取得しても、`consumeFlash()`へ直接渡した時点で検出できます。また、無関係な`.flash`プロパティを禁止しません。

## 全体判定

**CHANGES_REQUESTED**

残る必須修正は以下の3点です。

1. middlewareの検査を、クラス名込みの正確な`SHARED_PROP_KEY => payload()`委譲検査にする
2. TS負のコントロールの`await`構文エラーを直す
3. `.flash`文字列走査を、`consumeFlash(readFlash(...))`という消費経路の検査へ置き換える

これらを直せば、提示範囲についてはAPPROVEDにできる状態です。