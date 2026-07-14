Round 2 の全 Warning に対応しました。再レビューをお願いします。判定（APPROVED / CHANGES_REQUESTED）を明示してください。

## 対応サマリー

- **R2-W3（recorder 停止/解放が別テイク録画中を破棄しうる）**: 対応。概念契約として確定 —
  (a) 録画中の再生押下はプレビューを開かず押下時にエラー表示（disabled にしない、禁止事項8）、
  (b) 録画待機中のみ stream を停止/解放し close 後に再取得、(c) 録画データを暗黙に終了/破棄しない。
- **R2-W5（Cache-Control は 302 のみ制御）**: 対応。効果を「アプリ 302 応答=署名 URL の再利用防止」に限定と明記。
  ストレージ動画本体の非キャッシュは v1 要件外（スコープ外）。teardown に `video.pause()` + `src` 除去 + `load()` を追加。
- **R2-W7（object_path 非 null 未保証・PHPStan）**: 対応 + 事実訂正。Take の実カラムは `video_path`
  （`@property string $video_path` = 非 null string）。`object_path` は誤記。参照を `video_path` に訂正。
  非 null のため型絞り込み問題なし。再生可能条件は `status===Ready`、未 ready は early-return 404。
- **R2-W8（署名 URL が対象 take の path に対応する固定テスト無し）**: 対応。FakeTakeObjectStorage で
  302 Location が対象 take の `video_path` から生成され、別 take の path を使わないことを Feature テストで固定。

## 修正差分（該当箇所のみ）

### 実装方針 1（バックエンド）
- `CaptureTakeController::playback()`: URL 整合 guard（認可前 404）→ `Gate::authorize`（TakePolicy::preview）
  → 再生可能条件 `status===Ready` 以外は early-return 404 → `redirect()->away($storage->temporaryPlaybackUrl($take->video_path))`。
- 対象カラムは `Take::video_path`（非 null string）。PHPStan L10 の型絞り込み問題なし。
- 応答に `Cache-Control: no-store, private`。これが防ぐのは 302 応答（署名 URL）の再利用のみ。ストレージ動画本体の cache は対象外（v1 スコープ外）。

### 実装方針 2（フロント）
- 採用後 teardown: dialog close + video teardown（`pause()` + `src` 除去 + `load()`）+ `onChanged()`。失敗は dialog 内エラー。
- 録画排他の概念契約: 上記 R2-W3 (a)(b)(c)。

### テスト
- Pest: 302 + `Cache-Control: no-store` / 非 capture 403 / 非 ready 404 / IDOR（project/manual/cut mismatch 各 404）/
  署名 URL が対象 take の `video_path` から生成される固定。inventory 登録。
- vitest: 再生ボタン→player 表示 / 字幕トグル overlay 表示・非表示 / window.open 非依存 / 採用成功で dialog close。

判定をお願いします。
