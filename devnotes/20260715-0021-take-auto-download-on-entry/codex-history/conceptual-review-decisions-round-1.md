# 対応マトリクス: conceptual-review Round 1

## [Critical] `fetch + 即破棄 + ACK` は「端末へ自動ダウンロード」と言えない / 意味の再定義が必要
- 判断: **一部対応 + 一部反論**
- 根拠:
  - **反論の核**: 現行 v1 の手動 DL（`TakeStrip.downloadAndAck`）は `window.open(playback_url)` であり、これも**アプリ管理ストレージへ永続保存していない**（新規タブでの表示/ブラウザ DL に委ねるだけ。PWA 内 IDB へは保存しない）。つまり `downloaded_at` は現状すでに「端末保存の事実」ではなく「この端末が採用テイクを一度取得(retrieve)した記録」を意味している。自動 `fetch` はネットワーク越しに**実バイトを端末へ転送する**点でむしろ `window.open`（inline 再生で終わる可能性あり）より忠実な「取得」である。
  - 従って「意味のねじれを新設する」わけではなく、**既存の意味を明文化していない**のが問題の本質。
- 対応内容:
  - `downloaded_at` / `downloaded` のドメイン意味を**設計と doc に明記**する: 「この端末が採用テイクを取得済み(retrieved)・同期済みであるサーバ記録。端末内永続保存は v1 では行わない。手動(window.open)・自動(fetch)いずれも同一意味 = 一度端末へ取得した」。これで手動/自動の整合（Critical その2）も同時に解消。
  - フィールド名/route 名の rename は**行わない**（機能的必要がなく、確立済み API の破壊的 rename は過剰。doc/05 §5.3 の「自動ダウンロード」という要求語ともバイト転送の実体は一致）。
  - `fetch` はネットワーク取得を伴う（＝ACK-only にはしない）ことで、ACK が実取得を反映する形を保つ。破棄は v1 の永続化非対応に由来（下記スコープ外）。

## [Critical] 手動 `window.open` と自動 `fetch` で実体が異なるのに同一 `downloaded_at` を立てるのは不整合
- 判断: **対応（上記で解消）**
- 根拠/対応: 上記のとおり、両者とも「端末永続保存はしない・一度端末へ取得する」で意味を揃える。むしろ `fetch` の方がバイト転送を確実に行う。doc に「両経路とも同一意味」を明記。

## [Critical] 「DL 済みバッジが自動で正しく付く」は現案では期待できない
- 判断: **反論（意味の明文化で成立）**
- 根拠: バッジは元々 `downloaded`（=`downloaded_at`）に紐づく。`downloaded_at` の意味を「取得済み」と定義すれば、自動 fetch+ACK でバッジが付くのは意味的に正しい。バッジ文言「DL 済み」は「取得済み」の UX 表現として妥当（変更不要）。

## [Warning] `fetch` に S3 CORS(GET) が必要（window.open では不要だった前提が増える）
- 判断: **対応（受け入れ条件に明記）**
- 根拠: 既に `upload-queue.ts` が `ticket.upload_url` へ**クロスオリジン PUT** している＝アプリ origin に対する S3 CORS は PUT で既に成立。GET を AllowedMethods に含める必要がある。
- 対応内容: 詳細設計の「受け入れ条件」に「S3/minio バケット CORS が対象 origin からの GET を許可（既存 PUT 許可の拡張）」を明記。dev(minio)/本番の CORS 設定確認を実装時タスクに含める。

## [Warning] フル動画 fetch の帯域・電池・時間コストが重い
- 判断: **対応（コスト受容条件を明記 + 抑制策）**
- 根拠: 「download」は本質的にバイト転送を伴う（回避＝要求名の否定）。一度きりに強く抑制する。
- 対応内容: 未 DL のみ・入室時＋online 復帰のみ・順次(直列)・**セッション内 per-take attempted フラグで再取得を抑止**・ACK 失敗時のセッション内再 fetch を禁止・有界リトライ後は打ち切り。期待効果の表現を「入室時同期の自動化 = UX 一貫性」に調整（過大表現を下げる）。

## [Warning] テスト要件を設計に先に入れよ
- 判断: **対応**
- 対応内容: 概念設計/詳細設計のテスト計画に「対象選別」「fetch 成功時のみ ACK」「ACK/fetch 失敗時の有界リトライ・打ち切り」「二重起動防止・per-take attempted」「オフライン skip」を明記。

## [Warning] 型安全性: manual 構造を厳密型で受け、auto-download.ts に any/疎辞書を持ち込まない
- 判断: **対応**
- 対応内容: `auto-download.ts` は `CaptureManualDetail`/`CaptureCut`/`CaptureTake`（types/capture.ts）を厳密に受ける。any 禁止。API フィールド意味は変えない（rename しない）ので DTO/Resource/TS 同時変更は不要。

## [Suggestion] onMount + online 限定・手動ボタン残置・新規 API なしのスコープは妥当
- 判断: **維持**（変更なし）

## [Suggestion] IDB/Cache Storage を v1 で入れないのは妥当だが「自動ダウンロード要求を満たしていない」旨の明記が必要
- 判断: **対応**
- 対応内容: スコープ外に「端末永続保存(offline 再生用キャッシュ)は v1 対象外。将来 SW/Cache Storage で別途設計」を明記。ただしネットワーク取得(fetch)は行うため doc/05 §5.3 の「端末へ DL」自体は満たす旨を併記。
