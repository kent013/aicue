# 対応マトリクス: conceptual-review Round 1

## [Critical] 期待効果4: skip guard が回帰検出を弱める（ffmpeg 未導入が skip に吸収され赤化しない）
- 判断: 対応する
- 根拠: 妥当。skip guard に「導入されていること」の検証まで負わせると、最も検出したい
  「未導入」が黙って通る。検出責務を二層に分けるべき。
- 対応内容: 概念設計を二層構造に修正。
  (1) **プロビジョニング検証（必須・skip 不可）**: CI php ジョブに `ffmpeg -version` /
      `ffprobe -version` の存在確認ステップを置き、未導入なら fail-fast。
  (2) **合成疎通検証（skip guard 付き）**: 実 ffmpeg で字幕焼き込みを含む最小合成を実行。
      skip は「ローカル任意環境の便宜」に限定する（CI/devcontainer/bughunt は導入済み前提）。

## [Warning] 実現可能性3: CI で導入必須チェック化 + 疎通テスト実行必須
- 判断: 対応する（上記 Critical と同一対応）
- 根拠: 同上。
- 対応内容: CI ステップで fail-fast。CI では ffmpeg 導入済みのため疎通テストも実走する。

## [Warning] 使命1 / 期待効果4-2: S3 end-to-end を効果として言い切らない
- 判断: 対応する
- 根拠: 追加テストはローカル mp4 出力までで S3 書き込み・ジョブ連携は直接検証しない。
  過大主張を避ける。
- 対応内容: 期待効果を「レンダー工程のバイナリ依存疎通（ローカル合成成功）」に限定表現へ修正。
  S3 連携疎通は本 item のスコープ外（別 item）と明記。bughunt での end-to-end は
  「バイナリ不在で止まっていた工程が動くようになる」という前提回復として記述。

## [Warning] リスク5: lavfi だけでは字幕・日本語フォント経路を見逃す
- 判断: 対応する
- 根拠: 背景に fonts-noto-cjk 導入済みという文脈がある。色板動画生成だけでは検証密度不足。
  日本語字幕焼き込みを通せば ffmpeg 本体・filtergraph・字幕描画・フォント解決を一度に通せる。
- 対応内容: 疎通テストを「短い日本語字幕を 1 枚焼き込む最小合成」に寄せる。合格条件は
  「正常終了・output.mp4 存在・ffprobe で尺が読める」（コーデック細部・ビット一致は問わない）。

## [Warning] スコープ6: 任意のエラーメッセージ改善はぶれる元。本 item から外すか follow-up 明記
- 判断: 対応する
- 根拠: acceptance criteria に混ぜるとスコープがぶれる。
- 対応内容: エラーメッセージ改善を「follow-up（別 item 候補）」へ明確に降格。本 item の
  acceptance criteria からは除外する。

## [Suggestion] テスト配置 Unit→Integration 役割名 / テストファースト一文 / 合格条件
- 判断: 反映する
- 根拠: 実 ffmpeg を叩くテストは既存 Process::fake の Unit テストと役割が異なる。
- 対応内容: 実疎通テストは既存ファイルに混ぜず新規ファイル
  `tests/Unit/Render/FfmpegVideoComposerSmokeTest.php`（実バイナリを叩く smoke。DB 非依存の
  ため Unit ツリーに置くが命名で役割を明示）とする。テストファースト（先に skip なしで
  fail を観測 →バイナリ導入で green）を実装段取りに一文追加。合格条件を明記。

## [Suggestion] 早期判定を入れる場合の責務分離
- 判断: 見送る（本 item スコープ外へ）
- 根拠: 上記スコープ6 の対応でエラーメッセージ改善自体を follow-up 降格。本 item では
  FfmpegVideoComposer に手を入れない。
