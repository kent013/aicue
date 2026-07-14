Round 2 の 2 Warning + 2 Suggestion を反映しました。評価と全体判定をお願いします。

## 施策1
- [Warning] onDownloaded 契約二重 → **コールバック廃止・戻り値に一本化**。責務欄を「ACK 成功件数を集計し戻り値で返す。reload 判断は呼び出し側が changed を見て行う（コールバックなし）」に修正。戻り値セクションも「コールバックは設けず戻り値に一本化」と明記。
- [Suggestion] maxRetries 意味明記 → 「初回試行に加える再試行回数（総試行 = 1 + maxRetries、既定 2 → 総 3 回）」。施策3 テストで fetch/ACK 呼び出し回数 = 1 + maxRetries を固定。
- [Suggestion] Content-Length 堅牢化 → 「`/^\d+$/` かつ `Number.isSafeInteger(n)` の場合のみ検査対象」。

## 施策4
- [Warning] online 連打抑制を結線テストで保証不可 → 修正: 結線テストは「online ごとに run 起動要求が出る」ことのみ検証。**多重実行抑止（running ガード）は施策3 の実クラス単体テストで保証**する分担に変更。Show 側に独立ガードは置かない（二重ガード回避）。

## 質問
Round 2 の残 2 Warning（onDownloaded 一本化 / online 連打テスト責務）と 2 Suggestion を反映しました。残があれば指摘してください。無ければ APPROVED をお願いします。
