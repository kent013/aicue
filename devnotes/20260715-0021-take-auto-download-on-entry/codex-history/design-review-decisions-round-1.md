# 対応マトリクス: design-review Round 1

## [Critical] 施策1: `response.body === null` を即 network 扱いは環境依存で誤判定
- 判断: **対応**
- 対応内容: body 取得を段階化。(1) `response.body` が `ReadableStream` なら `reader.read()` で drain。(2) `response.body === null`（jsdom/古環境等）なら **`response.arrayBuffer()` フォールバック**で全読（メモリ制約はコメントで明示）し `byteLength` を `received` にする。(3) `ok` かつ received 0（`Content-Length===0` 含む）は成功許容。純粋な取得失敗（非 ok）は `http`。

## [Critical] 施策1: `aborted` と `network` の判別基準が曖昧
- 判断: **対応**
- 対応内容: `catch (e)` で `e instanceof DOMException && e.name === "AbortError"` を `aborted`、それ以外を `network` に分岐、と設計へ明記。

## [Warning] 施策1: manual 更新後に対象外化された take の墓石が Set に残る
- 判断: **対応**
- 対応内容: `run(manual)` 開始時に「現在の対象 take ID 集合」を作り、`fetchSucceeded`/`ackPending` から**対象外 ID を掃除**（集合差分でプルーン）。

## [Warning] 施策1: ACK 失敗後の再試行トリガが online/再入室のみで遅延しうる
- 判断: **一部対応（return を拡張、短時間タイマは任意/スコープ外）**
- 対応内容: `run()` 戻り値を `{ changed: boolean; hasPendingAck: boolean }` に拡張。呼び出し側は `changed` で reload、`hasPendingAck` は将来の軽量再試行フックに利用可能とする。v1 は online/再入室トリガで十分とし短時間タイマは実装任意（過剰実装回避のためデフォルト無効）。

## [Suggestion] 施策1: FetchOutcome に status?: number を持たせる
- 判断: **対応**
- 対応内容: `{ ok:false; reason:"http"; status: number }` を含める（テスト容易性・観測性）。他 reason は status なし。

## [Warning] 施策2: モジュールスコープ生成は Inertia props 差し替え時の ID 追従に弱い
- 判断: **一部対応（コメントで前提固定 + 既存 queue と同一方針）**
- 対応内容: Svelte の `<script>` トップは**コンポーネント・インスタンス毎**に実行され、`project.id`/`manual.id` はインスタンス生存中は安定（別 manual への遷移は remount）。既存 `new UploadQueue({store})` と同一配置で整合。コメントで「id はインスタンス安定・別 manual は remount」を明記。`reload({only:["manual"]})` は id を変えない。

## [Warning] 施策2: reloadManual 後の再発火抑止が downloaded=true 前提
- 判断: **対応（テストで固定）**
- 対応内容: `runAutoDownload` に呼び出し側ローカル `inFlight` フラグ、`changed` true 時のみ reload、を明記。施策4 テストで「reload 後に再度自動 DL が走らない（downloaded=true で対象空）」を固定。

## [Suggestion] 施策2: online で resumeUploads と runAutoDownload の順序非依存を明文化
- 判断: **対応**（コメント/設計に明記。両者は独立）

## [Suggestion] 施策3: 追加ケース（再入時 false / 1件成功2件失敗で true / Content-Length 不正値スキップ / never exhaustiveness）
- 判断: **対応**（テスト計画に追加）

## [Warning] 施策4: run stub 化で状態機械の本質は施策3 依存
- 判断: **対応**（テスト分担を明示: 施策4=結線責務のみ、施策3=状態機械厳密検証。名前/コメントで明示）

## [Suggestion] 施策4: online 連打で run 呼び出しが抑制される（running ガード）ケース追加
- 判断: **対応**

## [Suggestion] 施策5: 「downloaded_at は可用性指標であり端末保存保証ではない」を太字統一
- 判断: **対応**（doc/05 と docs/architecture.md で同一太字文言）

## [Critical] 施策6: ExposeHeaders 未指定だと size 検査が実質無効化
- 判断: **対応**
- 対応内容: 受け入れ条件に **`Access-Control-Expose-Headers: Content-Length, Content-Encoding`（必要なら ETag）** を追加。未公開時は設計どおり size 検査を自動スキップ（graceful degrade）し `response.ok` + 完読で判定する旨を併記。

## [Warning] 施策6: GET のみだと将来 HEAD 診断で詰まる
- 判断: **一部対応（脚注で HEAD 許可推奨・必須ではない）**
