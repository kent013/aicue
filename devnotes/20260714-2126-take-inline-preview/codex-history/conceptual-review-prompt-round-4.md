Round 3 の全 Warning（すべてテスト計画への反映漏れ）に対応しました。設計判定をお願いします（APPROVED / CHANGES_REQUESTED を明示）。

## 対応（テスト計画に追記）

- **R3-W2（録画排他の Vitest）**: vitest に追加 — (a) 録画中の再生押下では dialog を開かずエラー表示、(b) recorder の録画終了/破棄処理を呼ばない、(c) 録画待機中の open では stream を解放し close 後に再取得する。
- **R3-W5（video teardown の Vitest）**: dialog close 経路・採用成功経路の**両方**で video teardown（`pause()` + `src` 除去 + `load()`）が呼ばれる vitest を追加。teardown は単一関数に集約。
- **R3-W8（Cache-Control）**: Feature テストで `no-store` と `private` の**両 directive**を固定。

これ以外の設計内容は Round 3 時点から変更なし（署名 URL⇔対象 take `video_path` 対応の固定、IDOR inventory、各階層不整合 404 は据え置き）。
