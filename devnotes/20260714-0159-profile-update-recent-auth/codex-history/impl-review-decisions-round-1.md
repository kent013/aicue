# 対応マトリクス: impl-review Round 1

Codex (gpt-5.3-codex, reasoning=high) 総合判定: **APPROVED** (Critical 該当なし)。

## [Warning] middleware の `! $user instanceof User` 分岐を Architecture で明文化するとより堅い
- 判断: 見送る (本タスクスコープ外)
- 根拠: route は `auth` group 内で `$user instanceof User` が false になるのは auth guard 未通過時のみ。その場合 middleware は gate せず後続へ流すが、action 側も User を要求し安全側に倒れる。詳細設計 S1「リスク」で既に構造的に担保されている。将来 auth 前提が崩れる変更が来た時に対応すべき論点で、現差分では実害ゼロ。
- 対応内容: 変更なし。middleware docblock に判定契約を明記済み。

## [Warning] baselineEmail の initialUser 依存で外部再注入時に同期ズレ余地
- 判断: 見送る (実害なし・設計通り)
- 根拠: Codex 自身が「本差分範囲では onSuccess 同期があり実害は低い」と評価。precheck はサーバ最終ゲートの UX 補助であり、万一ズレてもサーバ側 recent-auth.on-email-change が最終ゲート。詳細設計 S5「設計上の注意」で「ズレてもサーバが最終ゲート」と明記済み。
- 対応内容: 変更なし。

## [Warning] Fortify の email=array で 500 は入力正規化を別チケット化推奨
- 判断: 見送る (本タスク非起因・スコープ外)
- 根拠: 本タスク以前からの Fortify ProfileInformationController の既存挙動 (`Str::lower(array)`)。recent-auth とは無関係で、middleware は非 string を gate せず後続へ流す (fail-safe)。T031 は「email 変更の recent-auth 保護 + 旧アドレス通知」がスコープ。オーバーエンジニアリング禁止 (禁止事項) に照らし本タスクでは修正しない。
- 対応内容: テストは「recent-auth ゲート応答でない (409/redirect でない) + email 不変」を不変条件として固定 (422 断定を避けた)。最終報告に「別タスク候補」として記載。

## [Suggestion] 各 Suggestion
- 判断: 対応不要 (肯定的評価が大半)
- 根拠: S1〜S7 各施策が設計と一致・fail-safe 妥当・DTO 委譲適合・テスト網羅・Atomic Design 準拠、という肯定コメント。改善提案 (User でない場合の明文化テスト) は上記 Warning と同趣旨で見送り。
- 対応内容: 変更なし。

## 結論
Round 1 で APPROVED。Critical/対応必須の指摘なし。全 Warning は「スコープ外」または「設計で既に担保済み・実害なし」。修正なしで Phase B (コミット) へ進む。
