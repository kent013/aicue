# 対応マトリクス: design-review Round 3

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
