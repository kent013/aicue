# 対応マトリクス: conceptual-review Round 2

全体判定: APPROVED (Critical/Warning なし)。以下の [Suggestion] を詳細設計へ持ち越す。

## [Suggestion] 成功後フォーカスは DOM 反映後 (`tick()` 等) を考慮せよ
- 判断: 対応する（詳細設計に反映）
- 対応内容: 新コード一覧へのフォーカス移動は `await tick()` 後に実行する設計を detailed-design.md に明記する。

## [Suggestion] F-12: 選択値が候補一覧に実在することを確認してから ConfirmDialog を開く
- 判断: 対応する（詳細設計に反映）
- 対応内容: submit ハンドラの条件を「`transferCandidates.some(c => String(c.id) === transferForm.user_id)` が真のときのみダイアログを開く」に強化する (DOM 改変・stale 値の早期排除)。最終ゲートは既存サーババリデーション (`exists:users,id` + Service のメンバーシップ検証)。

## [Suggestion] select の `string` と `Member.id` の `number` の変換地点を明示せよ
- 判断: 対応する（詳細設計に反映）
- 対応内容: 変換は「表示/比較は `String(member.id)` へ揃える (既存実装踏襲)、送信は useForm の `user_id: string` のままサーバ側 `integerish` 検証に委ねる (既存挙動)」と明示する。

## [Suggestion] 失敗分岐と不変条件を Vitest で固定すること (承認条件)
- 判断: 対応する（詳細設計のテスト計画に反映）
- 対応内容: GET 失敗時の error トースト + 再試行導線復帰、候補 0 人時のダイアログ非表示 + エラー表示、を Vitest テストケースとして明記する。
