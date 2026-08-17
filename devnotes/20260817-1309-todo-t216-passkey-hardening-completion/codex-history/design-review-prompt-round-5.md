# Round 5: Round 4 指摘への対応


## [Warning] 施策 C: `app('events')` の型が level 10 で確定しない

- 判断: 対応する
- 根拠: 文字列キーによるコンテナ解決は具体クラスを推論できない。
  `getRawListeners()` / `getListeners()` は `Illuminate\Events\Dispatcher` の API である。
- 対応内容: 解決した値を `expect(...)->toBeInstanceOf(Dispatcher::class)` で**検査してから**
  docblock で絞る形へ変更した (docblock だけで断定しない)。
  `app(Dispatcher::class)` で解決する案は採らない — コンテナの配線上、
  文字列キー `events` と具体クラスが同一インスタンスを返すことを別途確かめる必要があり、
  検査してから絞るほうが前提を持ち込まずに済む。

### 修正後の該当箇所
```php
```

各施策の判定と全体判定を再度出してください。
