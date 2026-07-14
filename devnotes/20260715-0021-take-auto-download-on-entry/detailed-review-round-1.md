以下、**詳細設計のレビュー結果**です。前提合意（サーバ変更なし・`downloaded_at` の意味・自動DL方針）には立ち入らず、実装正確性と網羅性に絞って評価します。

## 施策1: `auto-download.ts` 新規
**判定: REQUEST_CHANGES**

- [Critical] `response.body === null` を即 `network` 扱いは、実装環境依存で誤判定リスク  
  修正案: `body===null` の場合は `Content-Length===0` かつ `ok` を成功許容、または `response.arrayBuffer()` フォールバックを明示（メモリ制約をコメントで限定）。
- [Critical] `aborted` と `network` の判別基準が曖昧（`DOMException: AbortError` を識別しないと union が形骸化）  
  修正案: `catch (e)` で `e instanceof DOMException && e.name==="AbortError"` を `aborted`、それ以外を `network` に分岐。
- [Warning] `fetchSucceeded` / `ackPending` の2集合は妥当だが、**manual更新後に対象外化されたtake**の墓石状態が残り得る  
  修正案: `run(manual)` 開始時に「現在の対象ID集合」を作り、集合差分で不要IDを両Setから掃除。
- [Warning] ACK失敗時の有界リトライ後、`ackPending`に残す設計は正しいが、再実行トリガが`online`/再入室のみで遅延しうる  
  修正案: `run()` 戻り値を `changed` に加えて `hasPendingAck` を返せる設計、または呼び出し側で軽量再試行タイマ（短時間・有界）を任意化。
- [Suggestion] `FetchOutcome` に `status?: number`（http時）を持たせるとテスト容易性と運用観測が向上。

## 施策2: `Show.svelte` 結線
**判定: APPROVE（軽微修正推奨）**

- [Warning] モジュールスコープで `new AdoptedTakeAutoDownloader(project.id, manual.id)` は、Inertiaでprops差し替え時のID追従に弱い  
  修正案: `onMount` 内初期化 or `manual.id`/`project.id` 変化時再生成（少なくともコメントで前提固定を明記）。
- [Warning] `reloadManual()` 後の再発火抑止は「`downloaded=true`になる前提」に依存  
  修正案: `runAutoDownload` 中のローカル実行フラグ（`inFlight`）+ `changed` true時のみreloadの現設計をテストで固定化。
- [Suggestion] `online` ハンドラで `resumeUploads` と `runAutoDownload` の順序依存がないことを明文化（将来回帰防止）。

## 施策3: `auto-download.test.ts` 新規
**判定: APPROVE**

- [Suggestion] 追加推奨ケース  
  - `run()` 中再入（2回目即return）時に戻り値が常に `false` であること  
  - 1件目ACK成功・2件目失敗時に `run()` は `true`（reload条件）  
  - `Content-Length` 不正値（非数値/負数）時は size検査スキップ  
- [Suggestion] 判別unionの exhaustiveness を `never` チェックで担保（`any` 混入予防）。

## 施策4: `CaptureShow.test.ts` 更新
**判定: APPROVE**

- [Warning] `run` をstub化すると「再fetchせずACKのみ再送」等の本質は施策3側に依存  
  修正案: 施策4は結線責務に限定し、施策3で状態機械を厳密検証する分担をテスト名/コメントで明示。
- [Suggestion] `online` イベント連打時、`run` 呼び出し回数が抑制される（runningガード前提）ことを1ケース追加。

## 施策5: ドキュメント整合
**判定: APPROVE**

- [Suggestion] `downloaded_at` は「可用性指標であり端末保存保証ではない」を太字で統一記載（`doc/05`と`docs/architecture.md`で同文言）。

## 施策6: S3/minio CORS受け入れ条件
**判定: REQUEST_CHANGES**

- [Critical] `Content-Length` / `Content-Encoding` 参照前提なら `Access-Control-Expose-Headers` 要件を明示しないと size検査が実質無効化  
  修正案: 受け入れ条件に `ExposeHeaders: Content-Length, Content-Encoding`（必要なら `ETag`）を追加。
- [Warning] GET許可のみ記載だと、将来`HEAD`診断やメタ確認を入れた際に詰まる  
  修正案: 運用余地として `HEAD` も許可推奨を脚注化（必須ではない）。

## 全体評価
**全体判定: CHANGES_REQUESTED**

主な理由は2点です。  
1) 施策1の `body===null` と `AbortError` 判別が未確定で、状態機械の正しさに直結。  
2) 施策6の CORS受け入れ条件が size検査要件（ExposeHeaders）まで閉じていない。  

上記を反映すれば、設計は全体として既存制約・DTO/JsonResource方針（サーバ無変更）・Inertia運用・セキュリティ不変条件に整合し、実装着手可能レベルです。