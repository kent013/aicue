# 対応マトリクス: impl-review Round 1

Codex 判定: **Critical なし**（前回 Warning の 409 stale $state 問題は解消済み、マージ可能水準）。

## [Warning] isScenarioDocument の type guard が浅い (steps 要素 shape を未検証)

- 判断: 対応する
- 根拠: reload 経路は外部応答依存であり、防御的パース方針 (doc/10 §10.8-3) と整合する。検証コストも軽微
- 対応内容: `isScenarioRow` (id: number / scene: string) を追加し、`steps.every(...)` で各 step とその `points` 配列の行 shape まで検証するよう拡張

## [Suggestion] reloadScenario の onError 未使用 (リロード失敗時に無反応に見える)

- 判断: 対応する
- 根拠: ネットワーク断時に onSuccess 非到達で genericError が出ず、ユーザーには押しても何も起きないように見える
- 対応内容: `onError` で「最新シナリオの取得に失敗しました」を genericError に設定。対応する Vitest (onError → 汎用エラー表示) を追加

## [Suggestion] reloading 中の confirm スキップの回帰テスト未追加

- 判断: 対応する
- 根拠: 二重確認防止は今回修正の本質ロジックであり、直接固定すべき
- 対応内容: `router.on` モックで before ガードを捕捉し、「リロード実行中 (onFinish 前) は confirm 未呼び出し・preventDefault なし / 完了後は dirty なら confirm する」テストを追加
