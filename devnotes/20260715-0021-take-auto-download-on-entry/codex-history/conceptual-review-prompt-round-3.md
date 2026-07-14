Round 2 の 2 Critical + Warning を全面反映しました。評価と全体判定をお願いします。

## Round 2 Critical への対応

### Critical 1: fetch は header で resolve / 4xx・5xx でも resolve → 「実バイト取得完了後のみ ACK」を満たさない
**全面対応**。取得成功判定を次に厳密化して設計へ明記:
- `response.ok === true`（4xx/5xx は失敗。署名 URL 期限切れ 403 を含む）
- **`response.body` の ReadableStream を最後まで順次 drain して読み切る**（`arrayBuffer()` で一括保持せず chunk を読み捨て＝メモリ配慮）
- `Content-Length` 取得可能時は読取総量との不一致を失敗扱い。読取途中の失敗/中断(abort)では ACK しない
- 取得結果は判別可能 union `{ ok: true } | { ok: false; reason: "http"|"network"|"aborted"|"size_mismatch" }` で返し、`ok:true` のみ ACK

### Critical 2: 「この端末が取得した」はデータモデルで表現不能（downloaded_at に端末識別子なし）
**全面対応**。定義から「この端末」を除去:
> `takes.downloaded_at` は、いずれかの認可済みクライアントで当該採用テイクの取得処理（HTTP 成功 + body 読取完了）が成功し ACK された時刻を表す。端末単位の状態ではない。

明示的に保証しないこと: (a) オフライン再生・端末内ファイル存在、(b) ブラウザキャッシュ残存。downloaded は「取得処理と ACK が成功した状態」に過ぎない。将来オフライン再生等で永続保存が必要なら downloaded_at を流用せず別状態を設計する。以上を doc/05 と docs/architecture.md に明記。

### Warning: ACK 失敗時にも attempted に入れると取得成功済みでも ACK 再試行できない
**対応**。状態を 2 分離:
- `fetchSucceeded`（per-take）: fetch 成功 take は同一セッションで再 fetch しない
- `ackPending`（per-take）: fetch 成功済みだが ACK 未成功の take は再 fetch せず ACK のみ有界リトライ
fetch 失敗は fetchSucceeded に入れない（次トリガで再取得可）が 1 トリガ内は有界リトライで抑制。

### Warning: 「DL 済み」文言の誤解
**一部対応**。バッジ label は既存 UX・要求語(doc/05 §5.3「DL」)に合わせ v1 維持。ただし doc に caveat 明記（上記(a)(b) + downloaded の定義 + downloaded_at 流用禁止）。

### Suggestion: fetcher 戻り値を厳密型に / CORS は GET レスポンスの ACAO 必須・署名期限切れもテスト対象 / 完了条件に test-first + lint/typecheck/test/build
**すべて対応**。受け入れ条件に「GET レスポンスへの適切な Access-Control-Allow-Origin」追記。テスト計画に「署名 URL 期限切れ 403・CORS 不備時は ACK せずスキップ」追加。完了条件に test-first(fail 確認) + pnpm lint/typecheck/test/build green を明記。

## 質問
Round 2 の 2 Critical（body 読取完了を ACK 条件化・「この端末」除去）と Warning（fetch/ACK 状態分離・DL 文言 caveat）はこれで解消と判断できますか。残 Critical/Warning があればご指摘ください。無ければ APPROVED をお願いします。
