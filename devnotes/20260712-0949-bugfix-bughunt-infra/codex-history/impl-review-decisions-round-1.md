# 対応マトリクス: impl-review Round 1 (T015 レビュー修正後の最終ラウンド)

Codex 判定: **Critical なし / Warning なし**(Suggestion 2 件のみ)。

## [Suggestion] presigned URL 検証の将来互換性を補強(パース失敗時メッセージのヘルパー化)
- 判断: 見送る
- 根拠: `expect(preg_match(...))->toBe(1)` は失敗時に該当行で明示的に fail するため診断性は既に十分。
  2 行のパターンをヘルパー化するのはオーバーエンジニアリング(AGENTS.md 思考原則 2「今必要なものだけ作る」)。
- 対応内容: なし(現状維持)

## [Suggestion] no-op 保証の運用面ドキュメント追記(TESTING_FAKE_EXTERNALS の扱い)
- 判断: 対応する
- 根拠: 一文の追記で「運用者が意図を知らずに flag を触る」人的ミスへの防御が安価に得られる。
  追記先はフラグの定義箇所に最も近い `.env.bughunt.local.example`(T015 diff 内のファイル)。
- 対応内容: `TESTING_FAKE_EXTERNALS=true` の直前コメントに
  「bughunt 環境以外で有効化しない(本番は常時 false = config 既定)」の運用注意を追記。

## 補足: 本ラウンドに至った経緯
- 前ラウンドのレビュー findings は Critical / Warning ともに 0 件(修正対象なし)。
- `composer test` 実行時に T015 diff 外の既存テスト
  `tests/Feature/Capture/TakeObjectStorageTest.php` が `X-Amz-Expires=1799` で fail。
  原因は「テスト側 now() → SDK 内部 time() の間に S3 クライアント初回ビルド遅延が入り
  秒境界を跨ぐ」flake。固定文字列 `X-Amz-Expires=1800` の包含チェックを
  「URL の X-Amz-Date + X-Amz-Expires から復元した失効時刻 = 渡した expiresAt」の
  厳密一致検証へ置換(決定的かつ従来より強い不変条件。Codex も strengthen と判定)。
