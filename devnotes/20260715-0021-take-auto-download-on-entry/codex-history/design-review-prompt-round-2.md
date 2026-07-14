Round 1 の Critical/Warning/Suggestion をほぼ全面反映しました。評価と全体判定をお願いします。

## 施策1（Critical/Warning/Suggestion）
- [Critical] body===null 誤判定 → **段階化**: (1) body が ReadableStream なら reader.read() drain、(2) body===null なら `response.arrayBuffer()` フォールバックで byteLength を received に、(3) ok && received===0（Content-Length===0 含む）は成功許容。
- [Critical] aborted/network 判別 → `catch(e)`: `e instanceof DOMException && e.name==="AbortError"` を aborted、それ以外を network。
- [Warning] 墓石 → `run(manual)` 冒頭で現在の対象 take ID 集合を作り、fetchSucceeded/ackPending から対象外 ID を除去。
- [Warning] ACK 再試行遅延 → `run()` 戻り値を `{ changed:boolean; hasPendingAck:boolean }` に拡張（呼び出し側は changed で reload。hasPendingAck は将来フック、v1 は online/再入室で足りるので短時間タイマは任意・既定無効）。
- [Suggestion] FetchOutcome に status → `{ ok:false; reason:"http"; status:number }` を追加。

## 施策2（Warning/Suggestion）
- [Warning] モジュールスコープ生成 → Svelte の `<script>` はインスタンス毎に 1 回実行、project.id/manual.id はインスタンス安定（別 manual は remount、reload({only:["manual"]}) は id を変えない）。既存 `new UploadQueue({store})` と同一配置。コメントで前提固定。
- [Warning] reload 後の再発火抑止 → runAutoDownload に inFlight 相当（running ガード）+ changed true 時のみ reload、を明記。施策4 テストで固定。
- [Suggestion] online の resumeUploads/runAutoDownload 順序非依存をコメント明記。

## 施策3（Suggestion）
追加ケース反映: 再入時 changed:false / 1件成功2件失敗で changed:true / Content-Length 非数値・負数はスキップ / Content-Encoding 付きは size 検査せず完読で ok / body===null は arrayBuffer フォールバック成功 / 判別 union の never 網羅チェック / 墓石掃除。

## 施策4（Warning/Suggestion）
- run を stub 化し結線責務に限定（状態機械は施策3 が厳密検証、とコメント明示）。
- online 連打で run 呼び出しが過剰化しない（running ガード前提）ケース追加。

## 施策5（Suggestion）
doc/05 と docs/architecture.md で同一太字文言を統一: 「**downloaded_at は取得済み・同期済みを示す可用性指標であり、端末内保存・オフライン再生・ブラウザキャッシュ残存を保証しない**」。

## 施策6（Critical/Warning）
- [Critical] ExposeHeaders → 受け入れ条件に `Access-Control-Expose-Headers: Content-Length, Content-Encoding`（必要なら ETag）を追加。未公開でも size 検査を自動スキップして degrade 成立、と併記。
- [Warning] HEAD → 脚注で AllowedMethods に HEAD も許可推奨（v1 必須ではない）。

## 質問
Round 1 の 3 Critical（body===null / AbortError 判別 / ExposeHeaders）を含め全指摘を反映しました。残 Critical/Warning があれば指摘してください。無ければ APPROVED をお願いします。
