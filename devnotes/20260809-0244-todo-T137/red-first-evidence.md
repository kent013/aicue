# テストファースト: 実装前の赤

## M9 Manual 経路 (実装前 = dispatch が tx 外)

`vendor/bin/pest tests/Feature/Manual/QueueDispatchAtomicityTest.php`

```
tests=5 passed=2 failed=3
- 解析トリガの RunManualAnalysis は業務 tx の内側で投入される
  → Failed asserting that 1 is equal to 2 or is greater than 2. (level=1 = tx 外)
- レンダトリガの RunManualRender は業務 tx の内側で投入される      → 同上
- プレビュートリガの RunManualRender は業務 tx の内側で投入される  → 同上
```

補助の rollback テスト 2 本は**実装前でも緑**である (設計 §保証しないもの 12 のとおり。
旧実装でもテストの外側 tx が dispatch を包むため jobs 行は rollback で消える)。
= 移設を検出するのは tx level 観測だけ、という設計の主張が実測で裏付けられた。

## M9 残り 5 ファイル (実装前)

```
tests=11 passed=4 failed=7
- テイク削除の DeleteTakeObjectsJob は業務 tx の内側で投入される        → level 1 (要 2 以上)
- マニュアル削除の DeleteTakeObjectsJob は業務 tx の内側で投入される    → level 1
- finalize の DeleteRenderOutputsJob は terminal tx の内側で投入される  → level 1
- reserve の AutoRechargeTriggerJob は業務 tx の内側で投入される        → level 1
- attempt 起票と ExecuteAutoRechargeAttemptJob の投入は同一 tx である   → 記録 0 件
  (旧実装は createAttemptLocked 内で dispatch していないため)
- checkout.session.completed の打刻と PM 流用 job 投入は同一 tx である  → level 1
- auto_recharge_setup 完了の台帳更新と PM 既定設定 job 投入は同一 tx    → level 1
```

補助の rollback テスト 4 本は実装前でも緑 (設計 §保証しないもの 12 のとおり)。
