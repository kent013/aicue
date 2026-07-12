# 対応マトリクス: conceptual-review Round 1

## [Warning] 使命との整合性: F-05 の位置づけが不明確
- 判断: 対応する
- 根拠: F-02 が本丸であることは shard-report の severity (High vs Low) とも一致
- 対応内容: 「改善アイデア」冒頭に優先度を明記 (F-02 主目的 / F-05 補助的改善)。実装コミットを F-02 / F-05 で分離する方針も追記

## [Warning] 実現可能性: focus/scrollIntoView の DOM 未反映タイミング
- 判断: 対応する
- 根拠: Svelte 5 でも state 更新は同期 DOM 反映されないため `tick()` 待ちは必須
- 対応内容: 「保存失敗の状態確定後 `await tick()` → focus/scrollIntoView」と設計に明記。発火は保存試行失敗時の明示呼び出しに限定 ($effect 監視にしない)

## [Warning] 期待効果: 「必ずビューポート内」は強すぎる
- 判断: 対応する
- 根拠: モバイルキーボード・レイアウト変化まで含めると保証できない
- 対応内容: 「押下地点の近傍に表示され、focus/scroll で知覚可能性を担保する」に表現を修正

## [Warning] 期待効果: manuals.edit の静的タイトルは判別性が半端
- 判断: 対応する
- 根拠: 複数 manual の並行編集タブを想定すると動的固有名が一貫する。title カラムは NOT NULL (string 200)・required バリデーション済みで null 安全も確認済み
- 対応内容: `VideoManualController::edit()` も `setPrivateTitle($manual->title.' の編集')` の動的タイトルに変更。app_titles 追加は `projects.manuals.create` のみに縮小

## [Warning] リスク: 自動フォーカスの連続スクロールジャンプ
- 判断: 対応する (機構で解決)
- 根拠: 連続失敗のたびに大きくジャンプすると編集継続を阻害する
- 対応内容: `scrollIntoView({ block: "nearest" })` (既に可視なら no-op) + 発火を「保存試行が失敗で完了した時」の明示呼び出しに限定 (同一エラー再描画・無関係な再レンダで再発火しない) を設計に明記

## [Warning] スコープ: F-02 / F-05 の変更セット分離
- 判断: 対応する
- 対応内容: コミット分離方針を「改善アイデア」冒頭に明記

## [Warning] 型安全性: 失敗 state の分岐肥大化
- 判断: 対応する
- 根拠: conflict / genericError / forbidden の 3 本並列 state は分岐漏れを型で守れない
- 対応内容: `saveFailure` discriminated union ({kind: "conflict"|"forbidden"|"generic"}) に統合する設計を明記。`ScenarioConflictBody`・409 応答 shape は据え置き

## [Suggestion] 403 導線を再読み込みに固定しすぎない
- 判断: 対応する (軽微)
- 対応内容: union の独立分岐にすることで将来の再ログイン導線追加が局所変更で済む旨を明記

## [Suggestion] setPrivateTitle の null 安全確認
- 判断: 対応する
- 対応内容: title カラム NOT NULL / required バリデーションを確認し設計に記載
