# 対応マトリクス: conceptual-review Round 1

## [Critical] assignableUsers を canManage 非充足者にも配ると name (PII) が漏れる
- 判断: 対応する
- 根拠: name も PII。UI 非表示だけでは Inertia payload に載る。canViewMemberEmails と同じく payload 生成時点で絞るのが正。
- 対応内容: `assignableUsers` は `can('update', $project)` (= $canManage) が true のときのみ実データ、それ以外は `[]`。設計に明記。

## [Warning] 禁止事項8: 「押下時エラー or 案内文」が曖昧で disabled に戻る余地
- 判断: 対応する
- 根拠: 実装揺れを防ぐため UI 挙動を明文化すべき。
- 対応内容: 「追加ボタンは常に活性。候補 0 (assignableUsers 空) のときはフォーム上部に案内文を出し、送信時に候補未選択なら form error を出す (POST しない)。組織メンバー招待は /manage/users へ誘導」と明記。Organizations/Settings のオーナー移譲流儀に一致。

## [Warning] 「退行リスク低」が強すぎる
- 判断: 対応する
- 根拠: UI から初到達する経路は新規。
- 対応内容: 効果表現を「死蔵 endpoint/props の活性化」「ブラウザからの割当操作を可能にする」に絞り、リスクは「バックエンド契約は安定だが UI 到達性・権限表示の追加検証が必要」に弱める。

## [Warning] 候補に暗黙メンバー(org owner/admin)が混じると混乱
- 判断: 対応する
- 根拠: 追加しても見え方が変わらない/削除しても暗黙で残る混乱。
- 対応内容: 候補 = 「現在の `members` に存在しない org メンバー」= 明示メンバーも暗黙メンバーも除外。設計に明記。一覧では明示/暗黙をバッジで見分け、暗黙メンバーは削除ボタン非表示。

## [Warning] assignableUsers の shape 乖離 (PHPStan L10)
- 判断: 対応する
- 根拠: PHP shape と TS interface の乖離防止。
- 対応内容: Controller に `list<array{id:int,name:string}>` PHPDoc を固定。Feature テストで id/name 以外のキーを含まないことを検証。Svelte の role は ProjectRole ラベル定数で持つ。

## [Suggestion] UI 観点テスト方針の明記
- 判断: 対応する (詳細設計のテスト計画に反映)
- 対応内容: Feature (Inertia assertion) で「canManage=false では assignableUsers=[] かつ管理 UI 非表示相当」「canViewMemberEmails=false では email 実値なし」を検証する方針を追記。

## [Suggestion] ロール即時変更を同一 PR に入れるか
- 判断: 対応する (残す)
- 根拠: store の syncWithoutDetaching で兼用でき追加コストが小さい。ただし誤操作配慮として role 変更は select の onchange 即時でなく確認を挟まず preserveScroll で送る (Admin/Users 流儀踏襲、二重送信ガードあり)。スコープに残す。

## [Suggestion] 使命寄与の表現・明示/暗黙の視覚分離
- 判断: 対応する
- 対応内容: 効果表現を撮影者アサイン可能化に絞る。一覧は implicit フラグで Badge 分離。
