## Round 5: Round 4 の必須修正 2 点への対応

### 1. PHP の委譲検査を字句配列の完全一致に変えた

部分文字列検索をやめ、**字句の配列どうしのスライド比較**にした。

```php
    // 比較は**字句の配列どうし**で行う (1 本の文字列に繋いでから部分一致を見ると、
    // `NotFlashNotificationRelay` のような接尾辞一致を数えてしまう)。
    $delegation = [
        'FlashNotificationRelay', '::', 'SHARED_PROP_KEY', '=>',
        'FlashNotificationRelay', '::', 'payload', '(', '$request', ')',
    ];

    expect(phpTokenSequenceCount($middleware, $delegation))->toBe(1);

    expect(phpTokenSequenceCount($middleware, ['FlashNotificationRelay', '::', 'SHARED_PROP_KEY']))->toBe(1);
    expect(phpTokenSequenceCount($middleware, ['FlashNotificationRelay', '::', 'payload', '(']))->toBe(1);

    expect(phpStringLiterals($middleware))->not->toContain(FlashNotificationRelay::SHARED_PROP_KEY);
```

抽出器自身の負のコントロールも足した。

```php
test('字句列の比較は接尾辞一致を数えない (負のコントロール)', function (): void {
    expect(phpTokenSequenceCountIn(
        '<?php $a = [NotFlashNotificationRelay::SHARED_PROP_KEY => 1];',
        ['FlashNotificationRelay', '::', 'SHARED_PROP_KEY'],
    ))->toBe(0);

    expect(phpTokenSequenceCountIn(
        '<?php $a = [FlashNotificationRelay::SHARED_PROP_KEY => 1];',
        ['FlashNotificationRelay', '::', 'SHARED_PROP_KEY'],
    ))->toBe(1);
});
```

`phpTokenSequenceCount()` は `token_get_all` でコメントと空白を落とした
`list<string>` の字句配列を作り、`array_slice` で期待字句列と各要素完全一致する箇所を数える。
`phpTokenSequenceCountIn()` は同じ比較をソース文字列に対して行う (負のコントロール用)。

保証しないもの: **計算で組み立てたキー** (`'fl'.'ash'` / 変数経由) は字句が違うので見えない。
そこまで塞ぐ必要が出たら、そのとき構文木で `share()` の戻り値配列を解析する形へ上げる、と明記した。

### 2. TS の `readFlash` が正規モジュール由来の束縛であることを固定した

束縛の由来を構文木で見る検査を追加した。

```ts
const FLASH_MODULE = "@/lib/stores/flash-to-toast";

it("consumeFlash / readFlash はどちらも正規の入口から import された名前である", async () => {
    // 同名の自作関数を置いて迂回する形を塞ぐ。名前が一致するだけでは
    // 「正規の readFlash を通った」ことにならない。
    for (const relative of FLASH_CONSUMER_FILES) {
        expect(bindingOrigins(await readScript(relative), ["consumeFlash", "readFlash"]))
            .toEqual({
                consumeFlash: [{ kind: "import", module: FLASH_MODULE, aliased: false }],
                readFlash: [{ kind: "import", module: FLASH_MODULE, aliased: false }],
            });
    }
});

it("同名の自作 readFlash は正規の入口として認めない (負のコントロール)", () => {
    const forged = `
        import { consumeFlash } from "${FLASH_MODULE}";
        const readFlash = (props: unknown) => (props as { flash?: unknown }).flash;
        consumeFlash(readFlash(page.props));
    `;

    expect(bindingOrigins(forged, ["consumeFlash", "readFlash"]).readFlash)
        .toEqual([{ kind: "local", module: null, aliased: false }]);
});
```

`bindingOrigins(source, names)` は各名前について、その script 内の**束縛の宣言**
(import 指定子 / 関数宣言 / 変数宣言 / 引数) を列挙し、import なら取得元モジュールと
別名かどうかを添える。期待値を「名前ごとにちょうど 1 件で、それが別名なしの正規モジュールからの
import」に固定したので、同名の自作関数・別名 import・引数や変数による shadowing はすべて赤になる。

保証しないもの: 束縛の由来は**その script の中の宣言**までで、import 先モジュールが
何を export しているかは追わない (`flash-to-toast.ts` の中身は同ファイルの他の検査が担当する)。
`.svelte` は `<script>` 区間のみ、動的な名前での呼び出しは見えない、も引き続き明記している。

---

以上で全体判定 APPROVED にできるか答えてほしい。
