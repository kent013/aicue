# 対応マトリクス: conceptual-review Round 2

Codex 判定: CHANGES_REQUESTED（Critical なし。Warning 4 / Suggestion 複数）

## [Warning] 3: recorder 停止/解放が別テイク録画中を破棄しうる
- 判断: 対応する（概念契約として確定）
- 根拠: ready テイクの再生ボタンは別テイク録画中も存在。禁止事項8（disabled 禁止）。妥当。
- 対応内容: 概念契約に明記 — (a) 録画中の再生押下はプレビューを開かず押下時にエラー表示（disabled にしない）、(b) 待機中のみ stream 解放し close 後に再取得、(c) 録画データを暗黙に終了/破棄しない。

## [Warning] 5: Cache-Control は 302 のみ制御。オブジェクト本体 cache は保証しない
- 判断: 対応する（効果を限定）
- 根拠: 正しい。302 応答 header はストレージ応答に及ばない。
- 対応内容: 効果を「302 による署名 URL の再利用防止」に限定と明記。動画本体の非キャッシュは v1 要件でない（スコープ外）。teardown 補足（Suggestion）として close 時 `video.pause()` + `src` 除去/`load()` で通信・デコーダ資源を解放。

## [Warning] 7: object_path 非 null が型で保証されていない（PHPStan L10）
- 判断: 対応する（+ 事実訂正）
- 根拠: Take の実カラムは `video_path`（`@property string $video_path` = 非 null 文字列。`object_path` は誤記）。
- 対応内容: 設計の参照を `Take::video_path` に訂正。`video_path` は非 null string カラムのため `temporaryPlaybackUrl(string)` に型の絞り込み問題は発生しない。再生可能条件は `status===Ready`（未 ready は 404）。Assert 不要だが防御的に early-return 404 を明記。

## [Warning] 8: 発行署名 URL が対象 take の path に対応する固定テストが無い
- 判断: 対応する
- 根拠: 認可済み take と発行対象オブジェクトの取り違え防止。妥当。
- 対応内容: Feature テストで FakeTakeObjectStorage を用い、302 Location が**対象 take の `video_path` から生成**されること（別 take の path を使わないこと）を検証、とテスト計画に追記。

## [Suggestion] teardown で src 除去/load()
- 判断: 対応する（上記 W5 対応に統合）

## [Suggestion] W8 見送り妥当・スコープ判断妥当
- 判断: 情報。追加対応なし。
