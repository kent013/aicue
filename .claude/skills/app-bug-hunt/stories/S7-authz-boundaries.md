# S7: 認可境界 (IDOR)

> **スケルトン**: 手順・期待・screens/operations 対応はアプリのルートに合わせて埋めること (SKILL.md Phase 1 で route:list から)。

- 前提状態: S3 実行後の状態を意図的に使う。組織 A/B の 2 ユーザー
- 目的: 組織を跨いだ read/write が認可より前に 404/403 で弾かれるか (H9)

## 手順
1. (操作) → (期待)

## このストーリーで消化する screens / operations
- screens: (screens.md の該当行を列挙)
- operations: (operations.md の該当行を列挙)

## 逸脱アイデア (--deviate 時)
- (IDOR 探索・二重送信・戻る/リロード・隣接 ID 書き換え 等)
