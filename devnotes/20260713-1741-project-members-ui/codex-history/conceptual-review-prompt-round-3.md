# 概念設計レビュー Round 3

Round 2 の指摘への対応を報告します。

## [Warning] 候補ゼロ時の案内文分岐 → 対応
案内文を状況で分岐: (a) org メンバーは居るが全員アサイン済み → 「アサインできる未割当の組織メンバーがいません」(招待導線なし)。(b) 招待が必要な状況で `canManageMembers` があるとき → /manage/users への招待導線を併記。

## [Warning] 暗黙メンバーへの明示アサインのサーバ側扱い → 「許容 (無害)」を明記、バックエンド変更なし
選択肢のうち「許容し意味を定義する」を採用しました。理由と定義:
- 暗黙メンバー (org owner/admin) を明示 pivot に追加しても**無害**。管理アクセスは org ロールから継承され (ProjectPolicy)、明示 pivot の有無に依存しない。detach しても暗黙メンバーとして残る。cross-org は store が 403 で既に防御済み。
- これは守るべきドメイン不変条件ではなく、既存 store の意図した upsert 挙動 (組織メンバーなら誰でも pivot 付与可、テスト済み)。禁止すると store をフォークすることになり AGENTS.md 原則2 (今必要なものだけ・オーバーエンジニアリング禁止) に反する。
- UI は候補に出さないだけ (既にアクセスがあり追加が無意味なため)。

## [Warning] add と role-update を兼ねる store の競合 → last-writer-wins を明示、バックエンド変更なし
- store の upsert (syncWithoutDetaching) は既存テスト済みの意図した契約 (add と role 更新を兼ねる)。add と update を別セマンティクスに分けるには endpoint フォークが必要だが、本質は「メンバーシップの upsert」という単一概念で過剰 (AGENTS.md 原則2/3)。
- 低頻度の管理操作。各操作後に Inertia redirect back で members/assignableUsers が再取得され stale 窓は小さい。add は assignableUsers (= 非メンバー) からのみ選択させ、万一既存メンバーへ着弾しても upsert で選択ロールに収束 (無害)。
- 概念設計に「競合セマンティクス = last-writer-wins」と明記。

## [Suggestion] 候補除外テスト 4 ケース → 対応
Feature テスト計画に assignableUsers 絞り込みの 4 ケース (明示メンバー除外 / 暗黙メンバー除外 / 他組織ユーザー除外 / canManage=false で []) を明記。

暗黙メンバーのサーバ側扱いと競合仕様について「バックエンド変更なし・許容 (無害)・last-writer-wins」という判断を採りましたが、この根拠で承認いただけますか。まだ看過できない Critical/Warning があればご指摘ください。
