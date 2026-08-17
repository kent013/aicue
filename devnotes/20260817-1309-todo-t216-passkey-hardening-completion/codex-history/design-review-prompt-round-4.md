# Round 4: Round 3 指摘への対応


## [Critical] 施策 C: ワイルドカード購読を捕捉できない

- 判断: 対応する (指摘のとおり。vendor 実読で裏を取った)
- 根拠: `Dispatcher::listen()` はワイルドカードを `setupWildcardListen()` へ回し、
  `$this->wildcards` へ積む。`getRawListeners()` が返すのは `$this->listeners` だけなので、
  `Laravel\Passkeys\Events\*` のような購読は直接購読の一覧に現れない。
  一方 `Dispatcher::getListeners($event)` は
  **直接購読 + ワイルドカード + インタフェース経由**を合成して返す (vendor 実読)。
- 対応内容: 検査を 2 段にした。
  (a) 直接購読の顔ぶれと `ShouldQueue` の不実装を完全一致で固定する
  (テスト名も「**直接購読は**同期で走る 2 つだけ」に限定した)。
  (b) `count(getListeners(PasskeyDeleted::class))` が直接購読の件数と一致することを
  固定する。ワイルドカードでもインタフェース経由でも増えれば件数がずれて赤くなる。
  内部プロパティへの Reflection は使わない (公開 API 2 つの差で判定できるため)。

## [Warning] 施策 C: 想定外の購読の形を安全に拒否できない

- 判断: 対応する
- 根拠: `toBeArray()` の後に要素数もキーも確かめずに `$listener[0]` を読むと、
  空配列や連想配列で未定義オフセットになり、意図した契約違反として落ちない。
  docblock だけで `array{0: string, 1: string}` と断定するのも level 10 では根拠が弱い。
- 対応内容: 取り出しをヘルパ `passkeyListenerClass(mixed $listener): string` へ切り出し、
  `is_array()` / `array_is_list()` / `count() === 2` / 各要素の `is_string()` を
  順に明示的な期待として確かめてから返す形にした。
  `$raw[PasskeyDeleted::class]` 自体も `is_array()` で絞ってから走査する。

## [Suggestion] 施策 B: 「そのまま返す」の言い方

- 判断: 対応する
- 対応内容: 「**構造的な変形を加えず**、前後空白の除去と小文字化だけを施した値を返す」
  へ書き換え、テスト表にも「表の『同じ』は構造的な変形をしないという意味で、
  空白除去と小文字化はどの行にも掛かる」と注記した。

---

## 修正後の該当箇所 (施策 C の購読の固定)

 * 購読の登録値から「クラス名」を取り出す。
 *
 * 期待する形は `[クラス名, メソッド名]` **だけ**である。無名関数・オブジェクト・
 * 要素数の違う配列が来たら、未定義オフセットではなく**明示的な失敗**にする
 * (同期かどうかを機械的に判定できない形を通さないため)。
 */
function passkeyListenerClass(mixed $listener): string
{
    expect(is_array($listener))->toBeTrue('購読が [クラス名, メソッド名] の形ではない');
    /** @var array<mixed> $listener */
    expect(array_is_list($listener))->toBeTrue('購読の登録値が list ではない');
    expect(count($listener))->toBe(2, '購読の登録値の要素数が 2 ではない');
    expect(is_string($listener[0]))->toBeTrue('購読のクラス名が文字列ではない');
    expect(is_string($listener[1]))->toBeTrue('購読のメソッド名が文字列ではない');

    /** @var string $class */
    $class = $listener[0];

    return $class;
}

test('パスキー削除イベントの直接購読は同期で走る 2 つだけである (巻き戻りの前提)', function (): void {
    $dispatcher = app('events');
    $raw = $dispatcher->getRawListeners();

    expect($raw)->toHaveKey(PasskeyDeleted::class);
    $direct = $raw[PasskeyDeleted::class];
    expect(is_array($direct))->toBeTrue();
    /** @var array<mixed> $direct */

    $classes = [];
    foreach ($direct as $listener) {
        $class = passkeyListenerClass($listener);
        $classes[] = $class;

        // ShouldQueue を実装した購読はキューへ載り、削除の transaction の外で走る。
        expect(is_a($class, ShouldQueue::class, true))->toBeFalse(
            "{$class} がキュー化された。削除の巻き戻りの前提 (同期購読) が崩れる"
        );
    }

    // 顔ぶれを完全一致で固定する (増減のどちらでも赤くなる)。
    expect($classes)->toBe([RecordSecurityEvent::class, ClearRecentAuthOnPasskeyChange::class]);

    // ★**直接購読だけを見ても閉じない**。Dispatcher は
    //   ワイルドカード購読 (`Laravel\Passkeys\Events\*`) を別の集合で持ち、
    //   getRawListeners() には現れない。実装 (Dispatcher::getListeners) は
    //   直接購読 + ワイルドカード + インタフェース経由の購読を合成して返すので、
    //   **件数の一致**を見れば、そのどれが増えても赤くなる。
    expect(count($dispatcher->getListeners(PasskeyDeleted::class)))->toBe(
        count($classes),
        'ワイルドカードまたはインタフェース経由の購読が増えている。'
        .'キュー化されていないか (削除の巻き戻りの前提) を確かめること'
    );
});
```

---

各施策の判定と全体判定を再度出してください。
