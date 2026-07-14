# 対応マトリクス: design-review Round 3

全体判定: **APPROVED**（全施策 S1-S5 APPROVE）。

`aria-controls` / 固定 id の削除が妥当と確認された。`aria-pressed` + 状態連動 `aria-label` でトグルの状態・操作目的を表現。追加の Critical/Warning なし。詳細設計を確定する。

## 使命・禁止事項 最終チェック
- 使命寄与: doc/05 §5.2 字幕重畳要件を満たし、撮影者の構図判断（字幕占有領域の事前把握）を支援。「思考ゼロ・編集ゼロ」に整合。
- 禁止事項: (2) PHP 無変更で PHPStan 非該当 / (8) disabled ガードなし。テストなし完了なし（S4/S5）。既存テスト非破壊（props 既定値で後方互換）。
- DS/Atomic: DS token/ramp のみ・features/capture 配置・@lucide アイコン・raw button は PasswordInput 先例準拠。
