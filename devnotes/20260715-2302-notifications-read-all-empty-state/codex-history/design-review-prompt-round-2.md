Round 1 の指摘への対応です。全体判定を再確認してください。

## [Critical] 施策4: Props 必須化に伴う既存 render 追従漏れ → 対応
詳細設計の施策4 テスト計画を更新しました:
- ファイル冒頭に共通ヘルパを導入:
  `function baseProps(overrides = {}) { return { notifications: [], meta, unreadCount: 0, ...overrides }; }`
- 既存を含む**全 render 呼び出しを `render(NotificationsIndex, { props: baseProps({...}) })` に統一**
  (追従漏れによる型/実行時不整合を防止)。
- 既存「read-all は disabled でなく押下で POST」→ テスト名を
  `未読あり時、read-all ボタンは disabled でなく、押下で POST /notifications/read-all` に改名し
  `baseProps({ unreadCount: 1 })` で render。
- 新規「未読 0 件なら read-all を描画しない」: `baseProps({ unreadCount: 0 })` で
  `queryByTestId('read-all-button')` null **かつ** `queryByRole('button', { name: 'すべて既読にする' })` null。
- 新規「未読ありなら描画する」: `baseProps({ unreadCount: 3 })` で `getByTestId('read-all-button')` 可視。
- 既存 EmptyState / 一覧描画テストも baseProps 経由に統一。

## [Warning] 施策3: flaky 化リスク → 対応
- 対象ユーザーを `notificationCenterContext()` で明示生成。別ユーザー宛通知を混ぜて
  「自分宛のみカウント」を同時検証。1 件 read で既読化し `where('unreadCount', 残数)` を assert。
- RefreshDatabase により各テストは独立 DB = カウント決定的。

## [Warning] 施策2: 負値防御 `> 0` 維持 → 対応 (現行案どおり `{#if unreadCount > 0}` 固定)。

## [Warning] 施策4: テスト名の意図明確化・role assert 併用 → 対応 (上記)。

## [Suggestion] 施策1: controller コメント → 反映済み (prop 名衝突理由を 1 行コメント)。
## [Suggestion] 施策2: ヘッダ構造維持テスト → 見送り (h1 は条件描画の外で崩れ発生せず、過剰テスト回避)。

以上で Critical/Warning は解消の想定です。全体判定を再判定してください。
