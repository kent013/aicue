# 対応マトリクス: conceptual-review Round 1

Codex 判定: CHANGES_REQUESTED（Critical なし。Warning 8 / Suggestion 多数）

## [Warning] W1: PWA でモーダルだと視認性が落ちる
- 判断: 対応する
- 根拠: 構図確認が目的。小さいモーダルでは精度低下。妥当。
- 対応内容: プレビューは**フルスクリーン相当の dialog**（画面幅いっぱいの video、モバイルで最大表示）を要件化。

## [Warning] W2: テストファーストの手順が設計に落ちていない
- 判断: 対応する
- 根拠: AGENTS.md 思考原則5・禁止事項1。
- 対応内容: 実装手順に「失敗テスト（Feature/Vitest）→ IDOR inventory 更新 → 実装」の順序を明記。

## [Warning] W3: 撮影中の live stream と preview video のメディア資源競合（モバイル Safari）
- 判断: 対応する
- 根拠: 実機で再生不安定の恐れ。重要。
- 対応内容: preview open 時に recorder の stream を pause/停止、dialog close 時に resume する統合契約を追加。※ただし TakeStrip は recorder と別 section。より簡潔には「preview を開く間は録画 UI と排他」= dialog は画面前面を占有し録画中は再生ボタンを出さない/または open 時 recorder 停止。詳細設計で CameraRecorder との結合契約を定義。

## [Warning] W4: 「署名 URL 露出の最小化」の効果説明が過大
- 判断: 対応する（説明を縮める）
- 根拠: 採用テイクの `playback_url` は payload に残るため露出削減は部分的。指摘は正しい。
- 対応内容: 効果説明を「非採用テイク preview 用の URL を payload に増やさない」に限定。DL 経路の payload 署名 URL は現状維持（別 PR 対象、スコープ外）。

## [Warning] W5: dialog 内採用後の state teardown 未定義
- 判断: 対応する
- 根拠: stale state で UX 破綻。重要。
- 対応内容: 採用成功→dialog close + video 停止 + `onChanged()`（Inertia reload）。失敗→dialog 内エラー表示（既存 run() の error 流用）。

## [Warning] W6: 302 redirect が cache されると期限付き署名 URL の扱いが曖昧
- 判断: 対応する
- 根拠: 妥当。既存 render playback も同懸念。
- 対応内容: playback 応答に `Cache-Control: no-store, private` を付与し、Feature テストで固定。

## [Warning] W7: doc/04 初期OFF vs doc/05 初期ON の source of truth 未整合
- 判断: 対応する
- 根拠: 妥当。
- 対応内容: 「撮影 PWA は字幕初期 ON」を正式決定として明記。doc 差分解消は本設計スコープ外（doc 更新 TODO として付記、実装は本件対象外）。

## [Warning] W8: `takeUrl(take, "/playback")` 文字列結合の route drift
- 判断: 見送り（根拠付き）
- 根拠: 既存 TakeStrip が adopt/downloaded/destroy すべて同一 `takeUrl(take, suffix)` ヘルパで組み立てており、これがコードベース確立済みの規約。ここだけ Ziggy named route に変えるのは一貫性を損なう。drift リスクは既存経路と共有で、本件で新規に悪化させない。ヘルパは 1 箇所に閉じている。
- 対応内容: 既存 `takeUrl` 規約を踏襲する旨を設計に明記（新規 URL builder は導入しない = 過剰実装回避）。

## [Warning] W9: IDOR テストが cross-manual のみで弱い
- 判断: 対応する
- 根拠: セキュリティ不変条件。妥当。
- 対応内容: Feature テストで project/manual/cut mismatch 404・non-member 403・non-ready 404 を個別に固定。inventory 登録も明記済み。
