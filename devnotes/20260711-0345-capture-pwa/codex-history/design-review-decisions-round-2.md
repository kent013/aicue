# 対応マトリクス: design-review Round 2

## [Critical] 登録確定と stale sweeper の競合で登録済み動画が削除され得る
- 判断: 対応する（提案どおり両側 CAS）
- 対応内容:
  - 登録確定 tx（施策5）: 予約の completed 化を無条件 forceFill から **CAS（`WHERE id AND status=verifying` の条件付き UPDATE `verifying → completed`）** に変更。0 行更新（= sweeper が released 済み）なら Take を作成せず 422（再取得促し）。Take insert は CAS 成功後のみ。unique 衝突の rollback では CAS も巻き戻る。
  - sweeper（施策9）: 一覧取得後の各行を **条件付き UPDATE（pending: `status=pending AND expires_at<=now()` / verifying: `status=verifying AND updated_at<cutoff`）→ released** で claim し、**CAS 成功時のみ**オブジェクト削除。completed 化済み行は 0 行更新 → skip。戻り値も実 released 数に修正。
  - テスト追加: 登録側先勝で sweeper が削除しない / sweeper 先勝で Take 非作成 422 / stale 一覧取得後の状態変化（施策5・9 の両テスト計画に対で明記）。

## [Warning] overflow ガードより前に呼び出し側で加算している
- 判断: 対応する
- 対応内容: `StorageUsageService::occupiedBytes()`（bytes_used + bytes_pending の overflow 安全合成。跨ぐ場合は PHP_INT_MAX に丸め = 必ず超過判定）を新設し、呼び出し側（施策4）は生加算をしない。`checkAddition()` に `Assert::greaterThanEq($current/$addition, 0)` の事前条件を追加。

## [Warning] PHP DTO と TS の take 契約不一致（download_ack_token）
- 判断: 対応する
- 対応内容: `CaptureTakeData::fromTake(Take, ?string $playbackUrl = null, ?string $downloadAckToken = null)` に統一し、`playback_url` / `download_ack_token` を**全応答で常に出力**（store/update/adopt は null、詳細 GET の採用テイクのみ値 = 施策7 が唯一の設定経路）。`CaptureManualBrowsingTest` で Resource 応答のキー集合を assert する PHP↔TS 契約テストを明記。

## [Warning] SW キャッシュ対象の無限定
- 判断: 対応する
- 対応内容: キャッシュ対象を「GET × 同一オリジン × `/build/*`（fingerprinted asset）」に限定。`/app/*` navigation・JSON/XHR・署名 URL・S3 オリジンは fetch handler で早期 return（キャッシュしない）。キャッシュ名バージョニング + activate での旧キャッシュ削除。SW の fetch 分岐 Vitest を追加。
