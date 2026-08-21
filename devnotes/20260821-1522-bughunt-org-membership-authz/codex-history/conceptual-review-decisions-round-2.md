# 対応マトリクス: conceptual-review Round 2

## [Warning] F-2-03「全経路 403」表現が事前検証表と背景の 2 箇所に残存 (文書内で保証範囲が矛盾)
- 判断: 対応する
- 根拠: 後段で「主要な組織保護 route」に狭めたのに前段が古い表現のままで自己矛盾。
- 対応内容: 事前検証表 F-2-03(c) と背景・課題の該当文をいずれも「検証した主要 route (dashboard/projects/billing/manage-users) で 403」に統一。

## [Warning] F-2-01 のテスト計画 8 が backend データ契約のみで、UI 挙動 (注記ラベル・非 disabled) を固定していない
- 判断: 対応する
- 根拠: 今回の実変更は Svelte の option ラベル。禁止事項 1 (不変条件はテスト登録まで) を満たすには UI 挙動のテストが要る。
- 対応内容: テスト計画に項 9 を追加。Svelte テスト (tests/js/pages/AdminUsers.test.ts) で hasDefaultProject=false 時に編集者/撮影者へ注記付与・管理者は非付与・3 option とも非 disabled、true 時に注記が消える対の正例、を固定。

## 全 Suggestion
- 判断: 対応不要 (肯定的評価)。
