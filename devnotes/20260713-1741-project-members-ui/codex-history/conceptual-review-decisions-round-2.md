# 対応マトリクス: conceptual-review Round 2

## [Warning] 候補ゼロ時の案内文が「先に招待」固定なのは不正確
- 判断: 対応する
- 根拠: 全 org メンバーが既にアサイン済みなら招待は不要。案内文を状況で分岐すべき。
- 対応内容: 案内文を 2 分岐に。(a) org メンバーは居るが全員アサイン済み → 「アサインできる未割当の組織メンバーがいません」(招待導線なし)。(b) 追加可能な組織メンバー自体が事実上いない/招待が必要 → /manage/users への導線を出す。フロントは `assignableUsers` 空のときにこの文言を出し、招待導線は `canManageMembers` 相当がある場合のみ (既存 canManageMembers prop を流用可)。

## [Warning] 暗黙メンバー(org owner/admin)への明示 pivot 付与を UI 除外だけでは防げない
- 判断: 「許容する」方針を明記 (反論寄りの受容)。バックエンド変更なしを維持。
- 根拠: 暗黙メンバーを明示 pivot に追加しても**無害**。彼らの管理アクセスは org ロールから継承され (ProjectPolicy)、明示 pivot の有無に依存しない。cross-org は store が 403 で既に防御済み。これは「守るべきドメイン不変条件」ではなく、既存 store の意図した upsert 挙動 (組織メンバーなら誰でも pivot 付与可)。禁止すると store をフォークすることになり AGENTS.md 原則2 (今必要なものだけ) に反する。
- 対応内容: 概念設計に「暗黙メンバーへの明示アサインは許容 (無害)。意味: 明示 pivot を得ても管理アクセスは org ロール由来のまま。detach しても暗黙メンバーとして残る」を明記。UI は候補に出さないだけ (既にアクセスがあり追加が無意味なため)。store 変更なし。

## [Warning] add と role-update を兼ねる store の競合 (stale 画面からの add が既存ロール上書き)
- 判断: 反論する (last-writer-wins を明示し、バックエンド変更しない)
- 根拠: store の upsert (syncWithoutDetaching) は既存テスト済みの意図した契約 (「既存メンバーへの store 再実行はロール更新」)。add と update を別セマンティクスに分けるには endpoint フォーク (flag or 別 route) が必要で、本質は「メンバーシップの upsert」という単一概念。低頻度の管理操作であり、各操作後に Inertia redirect back で members/assignableUsers が再取得され stale 窓は小さい。過剰防御は AGENTS.md 原則2・3 に反する。
- 対応内容: 概念設計に「競合セマンティクス = last-writer-wins。各操作後に画面 props を再取得。add は assignableUsers (= 非メンバー) からのみ選択させ、万一既存メンバーへ着弾しても upsert で選択ロールに収束 (無害)」と明記。

## [Suggestion] 候補除外テストは 明示/暗黙/他組織/権限なし の 4 ケース
- 判断: 対応する
- 対応内容: 詳細設計テスト計画に assignableUsers 絞り込みの 4 ケース (明示メンバー除外・暗黙メンバー除外・他組織ユーザー除外・canManage=false で [] ) を明記。
