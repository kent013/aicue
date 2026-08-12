# 対応マトリクス: conceptual-review Round 3

判定 CHANGES_REQUESTED。Critical 0 / Warning 2。**両方対応**(反論なし)。

## [Warning] 「テストを足すだけ」という記述が、監査 metadata 追加と矛盾している

- 判断: **対応する**
- 対応内容: 「**契約テストと監査 metadata を追加するが、原因特定や防御追加までは行わない**」に修正した。

## [Warning] metadata 3 項目の値の出所が不正確 / HTTP コンテキスト外の値を決めよ

- 判断: **対応する**
- 根拠: `$freshUser` から取れるのは `deletion_requested` だけで、`route` / `method` は
  現在の HTTP request から取る。出所が違うものを 1 文でまとめていた。
- 対応内容: **項目ごとの表**にして型と出所を分けた。あわせて
  **HTTP コンテキスト外 (日次バッチの執行など) では `route` / `method` は `null`** と決め、
  型も `string|null` にした (`deleteAccount()` はバッチからも呼ばれるため、
  HTTP が無い呼び出しを異常扱いにしない)。
