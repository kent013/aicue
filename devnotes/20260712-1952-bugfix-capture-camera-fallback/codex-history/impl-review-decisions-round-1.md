# 対応マトリクス: impl-review Round 1

全体判定: CHANGES_REQUESTED (Warning 2 / Suggestion 3)

## [Warning] CameraRecorder: recorder.start() 例外が未捕捉で「詰みを作らない」要件を満たせない余地
- 判断: 対応する
- 根拠: 指摘どおり。start() は InvalidStateError 等を投げ得る。構築成功後の失敗でも
  フォールバックへ倒さないと詰む (§10.8-3)。
- 対応内容: `recorder.start()` を try/catch で包み、失敗時は `recorder = null; releaseCamera();
  onCameraUnavailable("recorder_unsupported"); return`。recording へは遷移しない。

## [Warning] テスト: 構築失敗時の stream 解放 (track.stop) を検証していない
- 判断: 対応する
- 対応内容: `fakeStream()` を `{ stream, stop }` へ変更し getTracks() が stop spy 付き track を返す構成に。
  構築失敗テストで `stop` が 1 回呼ばれることを assert。加えて start() 例外ケースの新規テストでも
  stop 呼び出し + 非録画状態を検証。

## [Suggestion] errorName に type guard 名を付ける
- 判断: 見送る。現状 strict で問題なく、命名だけの変更は YAGNI。

## [Suggestion] starting を $state 化して将来の「開始中」表示に備える
- 判断: 見送る。現要件では UI 反映不要。$state 化は無駄な reactivity を増やす。

## [Suggestion] device_missing の notice 文言分岐テストを足す
- 判断: 対応する (安価かつ notice 出し分けの退行検知を強化)
- 対応内容: CaptureShow に (e) を追加。NotFoundError で汎用 notice 文言が出て「再読み込み」文言を
  含まないことを検証。
