## Round 3: Round 2 の必須修正 3 点への対応

### 1. middleware の委譲検査をクラス名込みの正確な形にした

呼び出し名だけを数える形をやめ、**コメントと空白を落として 1 個の空白で繋いだ字句列**に対する
完全一致に変えた。

```php
test('middleware は共有 prop を中継へ委譲する', function (): void {
    $middleware = 'app/Http/Middleware/HandleInertiaRequests.php';

    $delegation = 'FlashNotificationRelay :: SHARED_PROP_KEY => FlashNotificationRelay :: payload ( $request )';

    expect(phpNormalizedTokenCount($middleware, $delegation))->toBe(1);

    // 上の 1 entry 以外に prop 名・中継呼び出しが現れないこと。
    expect(phpNormalizedTokenCount($middleware, 'FlashNotificationRelay :: SHARED_PROP_KEY'))->toBe(1);
    expect(phpNormalizedTokenCount($middleware, 'FlashNotificationRelay :: payload ('))->toBe(1);
});
```

`phpNormalizedTokenCount()` は `token_get_all` でコメント・空白を落とし、
1 個の空白で繋いだ 1 本の文字列に対する出現回数を返す (整形の違いに左右されない)。
指摘の反例 `SHARED_PROP_KEY => OtherFlashBuilder::payload($request)` は
1 本目の完全一致が 0 回になるため赤になる。
保証範囲 (「その 1 entry がその形で書かれていること」までで、中継の中身は Feature テストが担う /
引数名を `$request` から変えると赤になる) も設計へ明記した。

### 2. TS の負のコントロールの await 構文エラーを直した

```ts
it("抽出できない定数名は fail する (degenerate PASS 防止の負のコントロール)", async () => {
    // await は async コールバックの中で先に済ませる (expect の中に置かない)
    const source = await readRelay();

    expect(() => extractStringConstant(source, "NO_SUCH_CONSTANT")).toThrow(
        /degenerate PASS/,
    );
});
```

### 3. `.flash` 文字列走査を消費経路の検査へ置き換えた

```ts
describe("共有 prop の消費経路", () => {
    // 検査するのはプロパティの書き方ではなく消費の入口である。
    // 読み出しがドット記法でもブラケット記法でも分割代入でも、値は最後に
    // consumeFlash(...) へ渡るため、その引数の形を見れば迂回は必ず現れる。

    it("consumeFlash の呼び出し元は目録どおりである", async () => {
        const callers = await filesCalling("consumeFlash("); // 0 件なら throw
        expect(callers).toEqual([...FLASH_CONSUMER_FILES]);
    });

    it("呼び出し元はいずれも readFlash を通した値を渡す", async () => {
        for (const relative of FLASH_CONSUMER_FILES) {
            expect(await readSource(relative)).toContain("consumeFlash(readFlash(");
        }
    });
});
```

`filesCalling` は `resources/js` 配下の `.ts` / `.svelte` を再帰走査し、定義元
(`lib/stores/flash-to-toast.ts`) を除いた呼び出し元を相対パス昇順で返す。
走査対象 0 件・呼び出し元 0 件はいずれも throw。
保証範囲 (整形後の固定書式に対する文字列一致であり、
`const flash = readFlash(...); consumeFlash(flash);` のように変数を挟む書き方は赤になる。
通してよいと判断したらそのとき検査を意図して広げる) も明記した。

### 4. 施策 3 の Suggestion も取り込んだ

```php
    $location = $this->get('/__test/flash-relay-origin')->headers->get('Location');

    // 行き先が取れなかったときに黙って /login へ倒すと原因が隠れる (fail-closed)。
    expect($location)->toBeString();

    $flash = renderedFlash($this->get($location));
```

---

## 1 点だけ反論させてほしい: `session` / `uuid` の 0 件固定を残した理由

Round 2 で「正確な委譲検査を入れれば `session()` / `uuid()` の全禁止は必須ではなく、
保証範囲と制約範囲が一致していない」との指摘を受けたが、**残す判断をした**。根拠は次の抜け道である。

PHP の配列リテラルは**同じキーが 2 度現れると後勝ち**になる。したがって正しい委譲 entry の
後ろに、同じ prop 名で別の entry を書けば黙って上書きできる。しかもその中身を
「文字列リテラルを使わず `KINDS` を回して `session` から組み立てる」形にすると、

- 委譲検査 (クラス名込みの完全一致): 1 回のままなので緑
- 種別の直書き検査 (文字列リテラル): リテラルが無いので緑
- Feature テスト (キー集合 = KINDS ∪ 見分けキー): 出力の形が同じなので緑

と全部を通ってしまう。`session` / `uuid` の呼び出しが 0 件であることは、この残りをちょうど塞ぐ。

一方でご指摘のとおり将来の session 利用まで巻き込むコストがあるため、設計には
**何を塞ぐために置いているのか**と、**共有 props で session が要る prop を足すときの手順**
(専用の支援クラスへ寄せる / それが不自然ならこの検査を意図して直す = 直したことがレビューに見える)
を明記した。「別名 helper 経由の組み立て」の方は、上の委譲検査 (クラス名込み) で塞がっている。

この判断が受け入れられない場合は、代わりに置くべき検査の形を示してほしい。

---

以上で全体判定 APPROVED にできるか答えてほしい。
