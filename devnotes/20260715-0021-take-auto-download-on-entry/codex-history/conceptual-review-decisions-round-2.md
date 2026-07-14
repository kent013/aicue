# 対応マトリクス: conceptual-review Round 2

## [Critical] fetch は header 受信で resolve / 404・403・500 でも resolve → 「実バイト取得完了後のみ ACK」を満たさない
- 判断: **全面対応**
- 根拠: 正当な技術指摘。`fetch` の resolve はレスポンス到達であって body 読了ではなく、HTTP エラーでも resolve する。
- 対応内容: ACK 発火条件を次に厳密化して設計に明記:
  - `response.ok === true`（4xx/5xx は失敗扱い。特に署名 URL 期限切れ 403 を含む）
  - **response body を最後まで正常に読み切る**（`response.body` の `ReadableStream` を順次 drain。`arrayBuffer()` で大容量を一括保持しない＝メモリ配慮）。読取途中の失敗/中断では ACK しない。
  - `Content-Length` 取得可能時は読取総量との不一致を失敗扱い。
  - 以上を満たしたときのみ `POST takes.downloaded`。

## [Critical] 「この端末が取得した」という主体定義はデータモデルで表現できない（downloaded_at に端末識別子なし・別端末/別セッションからも ACK 可能）
- 判断: **全面対応**
- 根拠: 正当。`downloaded_at` は単一タイムスタンプで端末識別を持たない。端末別状態はスコープ超過。
- 対応内容: 定義から「この端末」を除去し次へ改める:
  > `takes.downloaded_at` は、**いずれかの認可済みクライアントで当該採用テイクの取得処理（body 読取完了）が成功し ACK された時刻**を表す。端末単位の状態ではない。
  端末別状態が必要になれば端末識別 + 端末別 ACK レコードが要るが、それは本件スコープ外。

## [Warning] ACK 失敗時にも attempted に入れると、取得成功済みなのに同一セッションで ACK を再試行できない
- 判断: **対応**
- 根拠: fetch 成功と ACK 成功は別事象。分離が正しい。
- 対応内容: 状態を **`fetchAttempted`（取得成功後は再 fetch しない）** と **`ackPending`（取得成功後、ACK のみ有界リトライ可能）** に分離。fetch 失敗は attempted に入れず（次トリガで再試行可）だがセッション内無限ループは有界リトライで防ぐ。

## [Warning] 使命/UX: 「DL 済み」文言が「端末保存・オフライン再生可能」と誤解され得る
- 判断: **一部対応（doc caveat 追加、バッジ文言は v1 維持）**
- 根拠: doc/05 §5.3 の要求語「DL」と既存 UX に合わせバッジ「DL 済み」は維持（label 変更は UX churn かつ既存テスト波及）。ただし誤解防止の caveat を doc に明記。
- 対応内容: `doc/05` と `docs/architecture.md` に次を明記: (a) オフライン再生・端末内ファイル存在を保証しない、(b) ブラウザキャッシュ残存も保証しない、(c) `downloaded` は「取得処理と ACK が成功した状態」、(d) 永続保存が必要になったら別状態を設計し `downloaded_at` を流用しない。バッジ近傍 or ヘルプでの注記は詳細設計で検討（最小は doc 追記）。

## [Suggestion] fetcher の戻り値を bare boolean にせず body 読取完了 + HTTP 成功を表現する厳密型に
- 判断: **対応**
- 対応内容: `auto-download.ts` の取得結果を `{ ok: true } | { ok: false; reason: "http" | "network" | "aborted" | "size_mismatch" }` 相当の判別可能 union にし、ACK は `ok:true` のみ。

## [Suggestion] CORS は preflight 有無だけでなく GET レスポンスの Access-Control-Allow-Origin が必要 / 署名 URL 期限切れもテスト対象
- 判断: **対応**
- 対応内容: 受け入れ条件に「GET レスポンスに適切な ACAO ヘッダ」を追記。テスト計画に「署名 URL 期限切れ(403)・CORS 不備時は ACK せずスキップ」を追加。

## [Suggestion] 実装完了条件に test-first(fail 確認) と pnpm lint/typecheck/test/build green を含める
- 判断: **対応（明記）**
