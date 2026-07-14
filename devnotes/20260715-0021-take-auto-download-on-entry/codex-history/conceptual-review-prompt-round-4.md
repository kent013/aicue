Round 3 の Critical + Warning を反映しました。評価と全体判定をお願いします。

## Critical: global downloaded_at と「入室端末への自動DL」要求の矛盾
**Codex 提案の選択肢 1 を採用**。v1 プロダクト不変条件を確定・明記しました:

> v1 では撮影の download/sync 状態（downloaded_at）はワークフロー全体（take 単位）の「取得済み・同期済み」状態であり端末単位ではない。1 manual に対する撮影クライアントは実質単一を想定。doc/05 §5.3 の「端末へ」は達成メカニズムの記述で、強制すべき不変条件は doc/02 §2.3「PC↔アプリ二重登録防止」（グローバルな性質）でありグローバル状態で満たされる。

根拠（コード事実で確認済み）:
- `downloaded_at` の**唯一の書き込み経路は撮影 PWA の署名 ACK（CaptureTakeService::markDownloaded）**。PC（doc/04）の「ダウンロード」は完成 mp4 マニュアルの DL で downloaded_at を触らない（別概念）。→ downloaded_at は撮影 PWA のみが立てる同期状態。
- v1 はバイトの端末永続保存を行わない（スコープ外）ため per-device 再取得は端末固有の durable 便益が無い。端末別状態は端末識別 + 端末別 ACK レコードを要しスコープ爆発。
- v1 は単一 Default Project。1 manual を複数端末で同時撮影し入室毎に per-device 再取得する要求は v1 スコープ外（将来必要なら端末別 ACK を別設計）と明記。
- doc/05 §5.3 の「端末へ」を上記意味に改訂。監査ギャップ #6 はこのグローバル同期モデルで解消（correctness の二重登録は元々冪等キーで防止済み）。

## Warning: Content-Length と drain バイト数比較は Content-Encoding 付きで不一致になり得る
**対応**。サイズ一致判定は **Content-Encoding ヘッダが無く、かつ response.body が非 null の場合のみ** Content-Length と読取総量の一致を検査。`response.body === null` は取得失敗扱い、と明記。

## 質問
Round 3 の Critical（global 状態と要求主体の矛盾）を「v1 はワークフロー単位の同期状態・単一撮影クライアント想定」というプロダクト不変条件の確定で解消し、Warning（サイズ判定）も条件付き検査に修正しました。これで承認できますか。残があればご指摘ください。
