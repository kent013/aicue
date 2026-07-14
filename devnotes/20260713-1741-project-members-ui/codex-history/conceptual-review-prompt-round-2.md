# 概念設計レビュー Round 2

Round 1 の指摘への対応を報告します。conceptual-design.md を以下のとおり改訂しました。全て採用しています。

## [Critical] assignableUsers の PII ゲート → 対応
- `assignableUsers` は `can('update', $project)` (= $canManage) が true のときのみ実データを返し、それ以外は `[]`。UI 非表示に頼らず **payload 生成時点で絞る** (canViewMemberEmails と同じ流儀)。email は候補に含めない。

## [Warning] 禁止事項8 の UI 挙動明文化 → 対応
- 追加ボタンは**常に活性**。候補 0 人 (assignableUsers 空) のときはフォーム上部に案内文を表示 (「アサインできる組織メンバーがいません。先にユーザー管理から招待してください」)。送信時に候補未選択なら form error を表示し **POST しない**。組織未所属ユーザーの招待は /manage/users へ誘導。

## [Warning] 「退行リスク低」の表現緩和 → 対応
- 効果を「死蔵 endpoint/props の活性化」「ブラウザからの割当操作を可能にする」に絞り、リスクは「バックエンド契約は安定だが UI 到達性・権限に応じた表示 (email 可視性・assignableUsers ゲート) には追加検証が必要」と明記。検証は詳細設計のテスト計画でカバー。

## [Warning] 候補から暗黙メンバー除外 → 対応
- 候補 = 「現在の `members` prop に存在しない org メンバー」= 明示メンバーも暗黙メンバー (org owner/admin) も除外。一覧は `implicit` フラグで「管理者(組織)」バッジと明示ロールを視覚分離し、暗黙メンバー行は削除ボタン・ロール変更 select を出さない。

## [Warning] assignableUsers の shape 固定 (PHPStan L10) → 対応
- Controller に `list<array{id:int,name:string}>` PHPDoc を固定。Feature テストで id/name 以外のキーを含まないことを検証。Svelte の role は ProjectRole ラベル定数で持つ。

## [Suggestion] UI 観点テスト方針 → 対応
- Feature (Inertia assertion) で「canManage=false では assignableUsers=[]」「canViewMemberEmails=false では email 実値なし」を検証する方針をテスト計画に追記。

## [Suggestion] ロール即時変更を同一 PR に含めるか → 残す
- store の syncWithoutDetaching で兼用でき追加コスト小。Admin/Users 流儀 (二重送信ガード付き select) を踏襲。

これらの改訂で承認可能でしょうか。残る Critical / Warning があれば指摘してください。
