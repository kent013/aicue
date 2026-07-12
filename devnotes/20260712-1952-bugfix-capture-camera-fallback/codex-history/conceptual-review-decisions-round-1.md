# 対応マトリクス: conceptual-review Round 1

## [Critical] 理由なし void callback (`onCameraUnavailable: () => void`) は失敗理由を捨てる
- 判断: 対応する
- 根拠: UI 文言の出し分け・将来の計測・サポートに reason が必要という指摘は妥当。型で表現できる情報を捨てない方針は本リポジトリの厳格運用 (PHPStan level 10 / TS strict) とも整合する。
- 対応内容: `lib/capture/camera.ts` に判別可能 union `CameraUnavailableReason = "permission_denied" | "device_missing" | "mime_unsupported" | "recorder_unsupported" | "unknown"` を定義。callback を `onCameraUnavailable(reason: CameraUnavailableReason)` に、親 state を `cameraUnavailableReason: CameraUnavailableReason | null` に変更。説明文も reason で出し分け。なお `policy_blocked` は提案されたが、Permissions-Policy 拒否はブラウザ上 `NotAllowedError` として観測されユーザー拒否と機械的に区別できないため `permission_denied` に統合 (設計に注記)。

## [Warning] 失敗分類が粗い (`NotReadableError` 等の一時的失敗まで永久フォールバック)
- 判断: 対応する
- 根拠: 他アプリのカメラ使用中 (`NotReadableError`) 等は自然回復し得るため、永久フォールバックはカメラ利用可能端末の録画経路を不必要に閉じるという指摘は妥当。
- 対応内容: `DOMException.name` ベースの分類方針を設計に明記。恒久系 (`NotAllowedError`/`SecurityError`/`NotFoundError`/`OverconstrainedError` + MIME/Recorder 不適合) → フォールバック通知。一時系 (`NotReadableError`/`AbortError`) → ローカルエラー表示 + 再試行可能のまま。分類不能 (`unknown`) はフォールバック側に倒す (§10.8-3 の必須要件は「詰みを作らない」ことであり、誤ってフォールバックに倒してもテイク投入は継続できるが、逆に倒すと再び詰みになるため)。

## [Warning] 子のローカル error 表示と親の即切替が二重責務・一瞬表示になる
- 判断: 対応する
- 根拠: フォールバック直前に子の赤字エラーが一瞬出るのは半端な UX という指摘は妥当。
- 対応内容: フォールバック対象 (恒久系) の失敗では子はローカル `error` をセットせず `onCameraUnavailable(reason)` のみ呼ぶ。説明表示は親の責務に一本化。一時系のみ子がローカルエラーを表示。

## [Warning] 期待効果の言い切りすぎ (`capture` 属性は端末依存)
- 判断: 対応する
- 根拠: `<input capture>` がカメラ起動になるかファイル選択になるかは端末・ブラウザ依存で、保証できるのは「経路への到達」まで。
- 対応内容: 期待効果を「録画 UI で詰まらず、ファイル選択アップロード経路へ到達できる」に修正し、端末依存の注記を追加。成功判定「権限拒否時でも 1 テイクを `UploadQueue.enqueue()` まで到達させられる」を明文化。

## [Warning] 業務上の成功条件の明文化 (使命との整合性)
- 判断: 対応する
- 対応内容: 期待効果に「制作フロー継続性の回復」であることと成功判定を明記。タイトルに「doc/10 §10.8-3 v1 必須要件の未達補正」を明示。

## [Suggestion] テストを 2 段に分ける (CameraRecorder 単体 = 分類と通知 / Show 単体 = 分岐表示、HTTP は既存資産へ)
- 判断: 対応する
- 対応内容: 実装方針のテスト表を 2 段構成に改訂。reason まで検証対象に含める。ページテストは enqueue 到達までとし upload HTTP の網羅は `upload-queue.test.ts` に寄せる。

## [Suggestion] テスト追加を完了条件に固定 / テストファースト
- 判断: 対応する
- 対応内容: 「再現テストを先に書き fail を確認してから実装」「テスト追加が完了条件」を実装方針に明記。

## [Suggestion] 再試行 UI を持たない理由を設計書に残す
- 判断: 対応する
- 対応内容: スコープ外に理由 (恒久系失敗はセッション内で自然回復しない・permission_denied 文言で回復手順を案内) を明記。
