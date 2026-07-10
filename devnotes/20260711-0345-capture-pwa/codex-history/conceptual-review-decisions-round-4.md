# 対応マトリクス: conceptual-review Round 4

## [Critical] 冪等ショートカットが completed 予約の video_path（= 登録済み動画本体）を削除する
- 判断: 対応する
- 根拠: 指摘のとおり completed チケット再送では予約.video_path が既存 Take と同一で、無条件削除は登録済み動画を破壊する。
- 対応内容: D4-1 を 3 分岐に改訂: (a) 同一 completed 予約からの再送（path 一致）= 何も削除せず 200、(b) 別 pending/verifying 予約の重複 = その予約 released + **既存 Take と異なるキーのみ**削除して 200、(c) 予約と Take の path/checksum 矛盾 = 削除せず 409（調査可能な状態を残す）。「completed 再送で S3 削除が発生しない」テストをテスト一覧に明記。

## [Critical] verifying 遷移で bytes_pending から消え Quota 超過を許す
- 判断: 対応する
- 対応内容: D3 の bytes_pending 定義を「pending かつ未失効 + verifying 全件」に改訂。verifying は completed/released まで Quota を占有（stale verifying は cron が released 化して解放）。「claim 中の並行 upload-url 発行でも上限を超えない」テストを追加。

## [Warning] fresh verifying への同チケット再送の応答が未定義
- 判断: 対応する
- 対応内容: D4-2 の claim 失敗分岐を明文化: released/期限切れ pending → 422（再取得）、**fresh verifying → 409（処理中・リトライ可能）**、completed → D4-1 の冪等分岐へ。

## [Warning] DL 完了から ACK までに採用が変わると ACK 不能 → 削除可能の race
- 判断: 対応する（提案どおり署名 ACK トークン方式へ変更）
- 対応内容: D6 を改訂: 詳細 GET が採用テイクの署名 DL URL と同時に take_id + user_id + 期限を封入した短寿命 ACK トークンを発行し、POST downloaded はトークン検証で打刻（「現在採用中か」の動的検証を廃止）。DL URL を得た take のみ ACK 可能 = 濫用防止と race 解消を両立。端末別台帳は不要のまま。

## [Suggestion] checksum の値オブジェクト検証
- 判断: 対応する
- 対応内容: D2b に `Sha256Checksum` 値オブジェクト（正しい base64 かつデコード後 32 bytes を生成時保証）を明記。

## [Suggestion] 予約状態・Quota 不変条件を A〜B 段の完了条件に含める
- 判断: 対応する
- 対応内容: テスト一覧（= A/B 段の Feature テスト範囲）に verifying 占有・completed 再送・409 分岐を明記。
