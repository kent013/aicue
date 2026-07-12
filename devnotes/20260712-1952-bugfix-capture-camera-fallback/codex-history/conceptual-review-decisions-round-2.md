# 対応マトリクス: conceptual-review Round 2 (全体判定: APPROVED)

Round 2 の指摘はすべて [Suggestion]。以下のとおり詳細設計へ反映する。

## [Suggestion] enqueue() 到達と upload-url→PUT→takes 完遂は同一ではない (テスト責務の分離)
- 判断: 対応する
- 根拠: 正確な責務記述はテストの brittle 化防止に有効。
- 対応内容: 詳細設計のテスト計画で「ページテスト = `enqueue()` への引き渡しまで」「`upload-queue.test.ts` = enqueue 後の HTTP 経路と登録完遂 (既存資産)」と明記する。

## [Suggestion] permission_denied の文言 (「ブラウザのカメラ許可」では Permissions-Policy 拒否をカバーしない)
- 判断: 対応する
- 対応内容: 文言を「カメラを利用できないため、ファイル選択でのアップロードに切り替えました。カメラで撮影する場合はブラウザまたは端末・組織のカメラ設定を確認して再読み込みしてください。」に変更 (詳細設計に反映)。

## [Suggestion] 分類ヘルパの入力型は `unknown` にし、instanceof DOMException / 安全な name 判定で絞り込む
- 判断: 対応する
- 根拠: ブラウザは任意値を reject し得る。strict TypeScript を widen なしで維持するため。
- 対応内容: `classifyCameraError(error: unknown): CameraUnavailableReason` として設計。`instanceof DOMException` (+ `name` プロパティの安全判定) で分類し、それ以外は `unknown` を返す。

## その他 [Suggestion] (使命整合・禁止事項・実現可能性・スコープ・型安全性の肯定的評価)
- 判断: 対応不要 (指摘事項なし)
