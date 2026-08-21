# 対応マトリクス: design-review Round 1

## [Critical] 施策1: email 照合が joinOrganization のロック外 (TOCTOU / stale user)
- 判断: 対応する
- 根拠: 本コードベースは membership 書き込みをすべてロック下で再検証する規律を持つ。email 境界もこれに揃えるべき。
- 対応内容: 施策 0 で domain predicate を新設し、施策 1 で「早期照合 (UX/高速拒否) + joinOrganization のロック下再照合 (権威)」の二段構成に変更。ロック下では $user->fresh() で committed email を読み再照合。3 経路共通コアに入るため全経路がロック下 email 境界を得る (register/in-app は挙動不変)。施策 5 T4b で TOCTOU をテスト固定。

## [Warning] 施策1: email 同一性規則の三重実装
- 判断: 対応する
- 根拠: 「同じ規則」の文書化では将来分岐する。
- 対応内容: 施策 0 で `OrganizationInvitation::isAddressedToEmail(string)` / `isAddressedTo(User)` を単一出典化。acceptInvitationIfValid L179 / MatchesInvitationEmail L46 もこの predicate へ置換。厳密比較 (case-sensitive) は既存 L150 の fail-secure 規則を踏襲。

## [Suggestion] 施策1: Assert::string 冗長
- 判断: 対応する
- 対応内容: narrow を predicate 内 1 箇所に集約し呼び出し側に散らさない。

## [Warning] 施策2: canAccept が email 一致しか表さない (名前が強い)
- 判断: 対応する
- 対応内容: prop 名を `recipientEmailMatches` に改名。既メンバー等は Service が別途拒否する旨を明記。

## [Warning] 施策2: Controller が独自比較式
- 判断: 対応する
- 対応内容: Controller も施策 0 の predicate を使用 (独自比較式を持たない)。

## [Warning] 施策3: Svelte DOM テストが任意扱い / PageHeader 文言未確定
- 判断: 対応する
- 対応内容: 新規 `tests/js/pages/InvitationsAccept.test.ts` を必須化。一致/不一致の PageHeader title・description の確定文言を表で明記 (不一致時は組織名を含めない)。ボタン表示/非表示・案内文の表示をテスト。

## [Critical] 施策5: ロック外照合のままでは T1-T6 が TOCTOU を検出できない
- 判断: 対応する (施策 1 のロック下再照合とセット)
- 対応内容: T4b を追加。解決後に宛先を食い違わせて joinOrganization を通し join/role/accepted_at 不変を固定 (Service 直呼び or reflection)。

## [Warning] 施策5: T5 双方成功で同一招待を順に使うと 2 人目失敗
- 判断: 対応する
- 対応内容: 経路ごとに独立 fixture を作り同一 email 入力表 (一致 / 完全不一致 / 大小差のみ) を適用。大小差は fail-secure 不一致で固定。

## [Warning] 施策5: T1/T6 が未確定
- 判断: 対応する
- 対応内容: T1 は InvitationTest L278/L369/L446、T6 は PendingInvitationScopeTest / AcceptInvitationInAppTest を名指しし「緑維持 + 不足なら assertion 追加」に確定。

## [Warning] 施策5: T4 role 判定が team context/cache で偽陽性
- 判断: 対応する
- 対応内容: laratrust_team_id 明示 + キャッシュ/relation reset + DB assertion で pivot/role 不在を直接確認。accepted_at/project pivot/current_org 不変も確認。

## [Critical] 施策6: T8 が current_organization_id を対象組織に設定しないと「現在組織なし 403」になり role fail-closed を検証できない
- 判断: 対応する
- 対応内容: T8 は membership あり + current_organization_id=対象組織 + role のみ無し、で 403 を検証。

## [Warning] 施策6: T7 の 403 が current-org 不在で成立し得る
- 判断: 対応する
- 対応内容: T7 は自然な null 化を検証しつつ、T7b で current_organization_id を除名済み組織へ明示的に戻しても membership 境界で拒否されることを分離固定。

## [Warning] 施策6: organizationRole === null が cache で不安定
- 判断: 対応する
- 対応内容: DB pivot 不在を直接 assert + relation/Laratrust キャッシュ reset。strict_types / RefreshDatabase 明記。

## [Suggestion] 施策7: ROLE_OPTIONS → roleOptions
- 判断: 対応する
- 対応内容: derived 化に伴い roleOptions へ改名。template も追随。

## [Warning] 施策8: disabled でないことだけでは禁止事項 8 の「押下時にエラー表示」まで担保できない
- 判断: 対応する
- 対応内容: T9b (操作開始可能・最終操作も非 disabled) / T9c (サーバ role エラーの画面表示) / T10 (Feature: applyConsoleRole が 422 拒否し role/project pivot 不変) を追加。
