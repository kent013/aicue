Round 1 の指摘への対応です。概念設計を改訂しました。以下の対応マトリクスと改訂差分を評価し、全体判定（APPROVED / CHANGES_REQUESTED）を出してください。

## 対応方針の要旨

Round 1 の中核 Critical（「fetch+破棄+ACK を『端末へ自動ダウンロード』と呼ぶのは無理」「手動 window.open と自動 fetch で実体が違うのに同一 downloaded_at は不整合」）に対し、**意味の明文化**で解決しました。反論の根拠は次の事実です:

- **現行 v1 の手動 DL（`downloadAndAck`）は `window.open(playback_url)` であり、これもアプリ管理ストレージへ永続保存していない**（新規タブでの表示/ブラウザ DL に委ねるだけ）。つまり `downloaded_at` は現状すでに「端末保存の事実」ではなく「この端末が採用テイクを一度取得(retrieve)し同期済みである記録」を意味している。
- 自動 `fetch(playback_url)` はネットワーク越しに実バイトを端末へ**転送する**ため、inline 再生で終わる場合がある `window.open` より「取得」として忠実。
- 従って新たな意味のねじれを作るのではなく、**既存の（暗黙の）意味を明文化する**のが正しい手当てと判断。

## 改訂内容（概念設計に反映済み）

1. **`downloaded_at` の意味を明文化**（新セクション）:
   「この端末が採用テイクを一度取得(retrieve)しサーバに同期済みである記録。アプリ内永続保存の有無は問わない（v1 は永続保存しない）。手動(window.open)・自動(fetch)いずれも同一意味・同一 ACK 経路」。doc/05 §5.3 付近と docs/architecture.md へ追記予定。フィールド/route の rename はしない（機能的必要がなく、要求語『自動ダウンロード』ともバイト転送の実体は一致。破壊的 rename は過剰）。
2. **手動/自動の整合**: 上記定義で両経路の意味を統一（fetch の方がバイト転送を確実に行う）。
3. **バッジ**: `downloaded`（=取得済み）に紐づくため自動 fetch+ACK でバッジが付くのは意味的に正しい。「DL 済み」文言は「取得済み」の UX 表現として維持。
4. **S3 CORS(GET) を受け入れ条件化**: 既に upload-queue.ts が同バケットへクロスオリジン PUT 済み＝CORS は PUT で成立。GET を AllowedMethods に含める確認/設定を実装タスクに追加。fetch は credentials:"omit"・カスタムヘッダ無しで preflight 回避。CORS 不備含む失敗は有界リトライ後スキップ→手動ボタン fallback。
5. **帯域抑制**: 未 DL のみ・入室時＋online 復帰のみ・直列・**セッション内 per-take attempted セット**（ACK 失敗時もセッション内で再 fetch しない）・有界リトライ後打ち切り。期待効果の表現を「入室時同期の自動化 = UX 一貫性」に調整（過大表現を下げた）。
6. **テスト要件を設計に明記**: 対象選別 / fetch 成功時のみ ACK / 有界リトライ・打ち切り / 二重起動防止・per-take attempted / オフライン skip / Show.svelte 結線。
7. **スコープ外の明記**: 端末永続保存（offline 再生キャッシュ）は v1 対象外。ただし fetch によるネットワーク取得は行うため doc/05 §5.3「端末へ DL」自体は満たす旨を併記。
8. **型**: auto-download.ts は types/capture.ts の CaptureManualDetail/CaptureCut/CaptureTake を厳密に受ける（any 禁止）。API フィールド意味は変えないため DTO/Resource/TS 同時変更は不要。

## 改訂後の該当セクション（抜粋）

### `downloaded_at` の意味（本設計で明文化する不変条件）
> `takes.downloaded_at` は「この端末が当該採用テイクを一度取得(retrieve)し、サーバに同期済みであることを記録するタイムスタンプ」である。アプリ内ストレージへの永続保存の有無は問わない（v1 は永続保存を行わない）。手動 DL（window.open）・自動 DL（fetch）のいずれも同一の意味であり、両経路で同じ ACK 経路（POST takes.downloaded）を通す。

### 手順（改訂）
- 対象: 採用テイク && status="ready" && downloaded=false && playback_url≠null && download_ack_token≠null。
- (1) fetch(playback_url, {credentials:"omit"}) で実バイト取得（body は破棄。v1 永続化なし）。(2) 成功時のみ POST takes.downloaded（{ack_token}）で ACK（手動と同一経路）。(3) ACK 成功後 router.reload({only:["manual"]}) を最小回数で反映。
- オフライン skip / 有界リトライ後打ち切り / 多重起動防止 + per-take attempted / 手動ボタン残置。

## 質問
この「意味の明文化（rename せず downloaded_at を『取得済み・同期済み』と定義）」による解決で、Round 1 の Critical は解消したと判断できますか。残る懸念（特に『取得済みだが永続保存なし』を運用者が誤解するリスクへの doc 対応の十分性）があれば指摘してください。
