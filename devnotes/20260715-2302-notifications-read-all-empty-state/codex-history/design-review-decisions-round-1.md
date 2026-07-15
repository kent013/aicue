# 対応マトリクス: design-review Round 1

全体判定: CHANGES_REQUESTED。施策1-3 は APPROVE、施策4 が REQUEST_CHANGES (Critical 1)。

## [Critical] 施策4: Props 必須化に伴う既存 render 追従漏れ (施策4)
- 判断: 対応する
- 根拠: `unreadCount` を必須にするなら全 render 呼び出しに付与しないと型/実行時不整合。
- 対応内容: 詳細設計に共通 `baseProps(overrides)` ヘルパ (`unreadCount: 0` デフォルト) を導入し、
  全 render を baseProps 経由に統一する旨を明記。

## [Warning] 施策2: unreadCount 負値防御は `> 0` 維持
- 判断: 対応する (現行案どおり)
- 根拠: `> 0` 条件は負値・0 双方を非表示にでき防御的。緩めない。
- 対応内容: 設計は既に `{#if unreadCount > 0}` 固定。変更なし (方針確認)。

## [Warning] 施策3: 既読/未読生成の flaky 化リスク
- 判断: 対応する
- 根拠: 他ユーザー/既存通知混入でカウントがブレる懸念。
- 対応内容: 対象ユーザーを明示生成し、別ユーザー宛通知を混ぜて「自分宛のみカウント」を同時検証、
  1 件 read で既読化して残数を assert する形に詳細設計を更新。RefreshDatabase で各テスト独立。

## [Warning] 施策4: テスト名の意図明確化
- 判断: 対応する
- 対応内容: 既存テストを「未読あり時、read-all は disabled でなく…」に改名。

## [Warning] 施策4: 非表示判定に role ベース assert 併用
- 判断: 対応する
- 対応内容: `queryByTestId('read-all-button')` に加え `queryByRole('button', { name: 'すべて既読にする' })`
  も null 検証する形に更新。

## [Suggestion] 施策1: controller にも prop 名衝突理由の 1 行コメント
- 判断: 対応する (設計に既記載)
- 対応内容: 変更後コードのコメントで衝突理由を明記済み。

## [Suggestion] 施策2: ヘッダ構造維持の回帰観点
- 判断: 見送る (任意)
- 根拠: 見出し (h1) は独立要素で条件描画の外。崩れは構造上発生しない。過剰なテスト追加は避ける。
