## Round 4: Round 3 の必須修正 3 点への対応

### 1. 後勝ち上書きを塞ぐ検査を足した (指摘は正しく、Round 2 の私の説明が誤りだった)

`'flash' => OtherFlashBuilder::build($request)` を後ろに置く形が通ることを確認した。
prop 名を**文字列リテラルで**書き直す口が空いていた。次の 1 本を足した。

```php
    // prop 名を**文字列リテラルで**書いた 2 つ目の entry を禁じる。PHP の配列リテラルは
    // 同じキーが 2 度現れると後勝ちになるため、これが無いと
    // `'flash' => OtherFlashBuilder::build($request)` を後ろに置いて黙って上書きできる。
    expect(phpStringLiterals($middleware))->not->toContain(FlashNotificationRelay::SHARED_PROP_KEY);
```

これで middleware の検査は 4 つになった。

1. `FlashNotificationRelay :: SHARED_PROP_KEY => FlashNotificationRelay :: payload ( $request )`
   の字句列がちょうど 1 回
2. `FlashNotificationRelay :: SHARED_PROP_KEY` の出現がちょうど 1 回 /
   `FlashNotificationRelay :: payload (` の出現がちょうど 1 回
3. prop 名の文字列リテラルが 1 つも無い (リテラル表記と定数表記の併存を封じる)
4. `session` / `uuid` の呼び出しが 0 件

`session` / `uuid` の 0 件固定については、テストのコメントと設計の記述を直し、
**これ単独では別 helper 経由の組み立てを防げない** (それは 1〜3 が担当し、
4 は「middleware の中で session から直接組み立てて別 prop に混ぜる」最後の余地を塞ぐ)
と保証範囲を書き分けた。

### 2. `$location` を PHP の実検査で narrowing した

```php
    $location = $this->get('/__test/flash-relay-origin')->headers->get('Location');

    // 行き先が取れなかったときに黙って /login へ倒すと原因が隠れる (fail-closed)。
    // narrowing は expect() ではなく PHP の検査で行う (静的解析が読める形にする)。
    if (! is_string($location)) {
        throw new RuntimeException('一時メッセージの着地先 (Location) がありません');
    }

    $flash = renderedFlash($this->get($location));
```

### 3. 消費経路の検査を構文木ベースにした

`typescript` は devDependency に既にある (実測 6.0.3) ため、生の文字列検索をやめた。

```ts
describe("共有 prop の消費経路", () => {
    // 走査は**構文木**で行う (生の文字列検索だと、コメントや文字列リテラルの中の
    // 同じ並びを呼び出しとして数えてしまい degenerate PASS になる)。

    it("consumeFlash を import するファイルは目録どおりである", async () => {
        // 入口を import している時点で消費者である (markup 側で呼んでも import は要る)。
        expect(await flashConsumerFiles()).toEqual([...FLASH_CONSUMER_FILES]);
    });

    it("consumeFlash の実引数はいずれも readFlash の呼び出しである", async () => {
        for (const relative of FLASH_CONSUMER_FILES) {
            const calls = consumeFlashCalls(await readScript(relative));

            expect(calls.length).toBeGreaterThan(0);
            expect(calls.every((call) => call.firstArgumentCallee === "readFlash")).toBe(true);
        }
    });

    it("コメント・文字列の中の同じ並びは呼び出しに数えない (負のコントロール)", () => {
        expect(
            consumeFlashCalls(`
                // consumeFlash(readFlash(page.props));
                const example = "consumeFlash(readFlash(";
            `),
        ).toEqual([]);

        expect(consumeFlashCalls("consumeFlash(readFlash(page.props));")).toHaveLength(1);
    });
});
```

補助:

- `readScript(relative)`: `.ts` はそのまま、`.svelte` は `<script …>` 区間の中身を返す
- `consumeFlashCalls(source)`: `ts.createSourceFile` の構文木から、呼び出し先の名前が
  `consumeFlash` の呼び出しを集め、第 1 引数が呼び出しならその呼び出し先名を添えて返す
- `flashConsumerFiles()`: `resources/js` 配下の `.ts` / `.svelte` を再帰走査し、
  `@/lib/stores/flash-to-toast` から `consumeFlash` を import しているファイルを
  相対パス昇順で返す (定義元は除く)。走査対象 0 件・該当 0 件はいずれも throw

目録の母集団を「呼び出し」ではなく「import」にしたのは、markup 側で呼ぶ形でも
import は必要なので取りこぼさないためである。

保証しないもの (動的な名前での呼び出しは見えない / `.svelte` は `<script>` 区間のみ /
変数を挟む書き方 `const flash = readFlash(...); consumeFlash(flash);` は赤になる) も明記した。

---

以上で全体判定 APPROVED にできるか答えてほしい。
