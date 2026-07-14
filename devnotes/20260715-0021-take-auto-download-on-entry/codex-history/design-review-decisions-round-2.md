# 対応マトリクス: design-review Round 2

## [Warning] 施策1: onDownloaded コールバックと戻り値 reload の契約が二重
- 判断: **対応**
- 対応内容: `onDownloaded` コールバックを**廃止**し、`run(): Promise<{ changed; hasPendingAck }>` に一本化。責務欄・インターフェースから onDownloaded 記述を削除。reload は呼び出し側（Show.svelte）が `changed` を見て行う。

## [Suggestion] 施策1: maxRetries の意味を明記
- 判断: **対応**
- 対応内容: `maxRetries` は「**初回試行に加える再試行回数**」（総試行 = 1 + maxRetries、既定 2 → 総 3 回）と定義し、施策3 で呼び出し回数テストに固定。

## [Suggestion] 施策1: Content-Length は非負 10 進整数かつ Number.isSafeInteger() のみ検査対象
- 判断: **対応**
- 対応内容: size 検査条件を「`/^\d+$/` かつ `Number.isSafeInteger(n)`」に厳格化。

## [Warning] 施策4: auto-download 全体 stub 化で running ガードが無く「online 連打抑制」を結線テストで保証できない
- 判断: **対応**
- 対応内容: 施策4（結線テスト）は「online ごとに `run` 起動要求が出る」ことのみ検証に修正。**多重実行抑止（running ガード）は施策3 の実クラス単体テストで保証**する分担へ変更。Show 側に独立ガードは置かない（二重ガードの複雑化回避）。
