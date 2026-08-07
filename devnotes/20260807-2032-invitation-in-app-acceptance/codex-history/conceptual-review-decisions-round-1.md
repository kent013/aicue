# 対応マトリクス: conceptual-review Round 1

## [Critical] `joinOrganization()` の戻り値が本文内で矛盾している (bool / ?Organization)
- 判断: **対応する**
- 根拠: 指摘のとおり本文が揺れていた。層が違う 2 つの戻り値を 1 つの名前で語っていたのが原因。
- 対応内容: 「存在秘匿の作り方 (核心)」3 を書き直し、**内部コア `joinOrganization(): bool`**
  (ロック下再検証を通ったか) と **公開 API `acceptPendingInvitation(User, string): ?Organization`**
  (`null` = 受諾できなかった = 404) の 2 層であることを明示。施策一覧表 (# 3) も同じ表現に揃えた。
  既存 2 経路 (`acceptInvitation` / `acceptInvitationIfValid`) は戻り値を無視するため互換。

## [Warning] 受諾は transaction + lock 下で同じ条件を再評価すべき
- 判断: **対応する**
- 根拠: 一覧で見えた招待を信用したままだと、取消・組織 soft-delete の race が
  「一覧に出たのに参加できた」に化ける。標準形 (b) の趣旨とも一致する。
- 対応内容: 「核心」に項目 4 を追加し、擬似コードで順序を固定した。
  ロックは**下見のあと・再解決の前**に canonical 順序 (users 昇順 → organizations) で取り、
  ロック確立後に**同一 scope で再解決**する。`joinOrganization()` 内の
  `lockForMembershipWrite` 再取得は同一 tx 内なので no-op で順序も変わらない。
  Codex 案の `->lockForUpdate()` を scope に直接付ける形は採らない:
  `whereHas('organization')` を含む scope に `FOR UPDATE` を付けると
  pgsql で JOIN 先までロック対象になり、既存 `DefaultProjectResolver::resolveForUpdate()` の
  docblock が明記している落とし穴を再発させるため。行ロックは既存の
  `lockForMembershipWrite` + `joinOrganization` の招待行ロックに委ねる。

## [Warning] 期待効果の「期限切れなら第 2 経路が保険になる」は過大
- 判断: **対応する**
- 根拠: 新経路も `scopeActive` 前提なので期限切れは受諾できない。事実に反する。
- 対応内容: 期待効果を書き直し、「効くのはメールが見つからない/開きにくい場合」と限定。
  期限切れ・取消済みは「一覧から消え、通知を開いたときに『現在有効な招待はありません』と
  明示できる = 再招待を依頼すべきだと分かる」という**別の価値**として書き直した。

## [Warning] `open()` の flash 文言は特定の招待について断定できない
- 判断: **対応する**
- 根拠: 通知と招待は 1:1 で紐づいていない (payload に id を持たせない設計の帰結)。
  「この招待は無効です」は嘘になりうる。
- 対応内容: 件数 0 → `info`「現在有効な招待はありません (取り消し・期限切れ・参加済みの可能性があります)。」、
  1 件以上 → flash なし (一覧がその場に出るため) と集合表現に修正。

## [Warning] `PendingInvitationForUserDto` の開示項目を概念設計で固定せよ
- 判断: **対応する** (`acceptUrl` の追加のみ反論)
- 根拠: 開示面の固定は妥当。`acceptUrl` は本経路が署名も token も持たないため
  サーバが URL を配る意味が無く、front が route から組めば足りる (開示面だけ増える)。
- 対応内容: 「受信者視点 DTO の開示項目の契約」節を新設。開示は
  `id` / `organizationName` / `roleLabel` / `expiresAt` の 4 つに限定し、
  `email` / `token_hash` / 状態列の生値 / `invited_by_user_id` / `organization_id` は出さないと明記。
  管理者視点 `InvitationRowData` とは別クラスのままにする旨も追記。

## [Warning] 認可 exemption の理由が薄い
- 判断: **対応する**
- 根拠: `SelfScopedResource` として逃がす以上、なぜ self-scoped と言えるかを目録に残す責務がある。
- 対応内容: gate 登録表の該当行に、理由へ書くべき内容
  (`auth` + `verified` + `activePendingForEmail($user->email)` の自己スコープ解決に閉じ、
  宛先不一致・不在は一律 404。Policy を挟むと 403 になり存在秘匿が壊れる) を明記した。

## [Warning] email の大小文字完全一致で「同じに見えるのに一覧に出ない」ケースが残る
- 判断: **対応する** (スコープは広げない)
- 根拠: fail-secure 側に倒れるので安全性の問題ではないが、説明が無いと運用で迷う。
- 対応内容: 制約・前提に「大小差のある宛先は 404 / 空一覧になるが、
  既存のメール token 経路 (token_hash 照合なので大小差の影響を受けない) で従来どおり受諾できる」
  と追記し、`docs/architecture.md` への明記を実装タスクに含めた。
  blind index の Lowercase transformer 化は既存の全 `whereBlind` 呼び出し元と
  index 再計算を伴うため、引き続きスコープ外。

## [Suggestion] 使命との整合性 / スコープの適切さ
- 判断: **見送る** (肯定的コメントのため対応不要)
