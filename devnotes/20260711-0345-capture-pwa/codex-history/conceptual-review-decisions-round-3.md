# 対応マトリクス: conceptual-review Round 3

（Round 2 はファイル読み込み不可による全文貼付依頼のため、指摘対応は Round 3 返答に対して行う）

## [Critical] presigned PUT URL の再利用による登録後オブジェクト差し替え
- 判断: 対応する
- 根拠: 指摘のとおり size/content_type 照合だけでは同サイズ・同種別の別内容への差し替えを防げない。提案 3 案のうち「署名済み SHA-256 チェックサム」が最も局所的（Versioning はバケット設定依存、staging コピーは大容量動画の二重 I/O）。
- 対応内容: **D2b 新設**。クライアントが upload-url 要求時に blob の SHA-256（base64・WebCrypto）を申告 → 予約行 `checksum_sha256` + チケット封入 + presigned PUT の `x-amz-checksum-sha256` 署名条件に含める。S3 が本文とチェックサムの一致を強制するため当該 URL で置ける内容は 1 通りに固定。POST takes は HeadObject（ChecksumMode=ENABLED）の ChecksumSHA256 も照合する三点照合へ。フロー図・D4・D9・テスト一覧に反映。登録後再 PUT の Feature テストを追加項目に明記。

## [Warning] completed チケット拒否と冪等 200 の矛盾
- 判断: 対応する
- 対応内容: D2 のチケット状態契約を明文化: released/期限切れ → 422、**completed → 対応する Take（同 cut_id + client_take_id）が存在すれば 200 で既存返却**（応答喪失リトライ）、completed かつ Take 不在は 409（整合性異常）。D4 の処理順序を「冪等ショートカット最優先」に再構成し矛盾を解消。completed チケット再送テストをテスト一覧に追加。

## [Warning] 登録処理と期限切れ cron の競合制御が未定義
- 判断: 対応する
- 対応内容: 予約状態に **verifying** を追加（pending|verifying|completed|released）。登録側は外部 I/O 前に原子的 UPDATE（pending→verifying、expires_at 未超過条件付き）で claim し、S3 呼び出し中に DB ロックを保持しない。cron は「pending の期限切れ」と「stale な verifying（updated_at 15 分超過 = リクエスト異常終了）」のみ回収し、fresh な verifying には触れない（D4-2 / D7 改訂）。

## [Warning] downloaded ACK が任意 Take に可能（恣意的削除不能化）
- 判断: 対応する
- 対応内容: D6 に「ACK できるのはその時点で cut の採用テイクである take のみ（cut.adopted_take_id === take.id をサーバ検証、非採用は 422）」を追加。署名 DL URL は採用テイクにしか発行しない（§10.3）ため DL 可能集合 = ACK 可能集合と一致。非採用 ACK 拒否の Feature テストを追加。

## [Suggestion] チケット復号直後の値の専用 decoder
- 判断: 対応する
- 対応内容: D2 に「復号直後の値は未検証の動的値として扱い、専用 decoder（存在・型・範囲の Assert 検証）だけを型境界にする」を明記（詳細設計の UploadTicketCodec::open / UploadTicketClaims::fromArray に対応）。

## [Suggestion] B 段の完了条件に competed 再送・cron 競合テストを含める
- 判断: 対応する
- 対応内容: テスト一覧に「completed チケット再送の冪等 200」「claim/cron 競合」を明記（B 段の Feature テスト範囲）。
