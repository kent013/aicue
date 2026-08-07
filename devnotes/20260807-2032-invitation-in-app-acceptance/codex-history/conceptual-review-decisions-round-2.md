# 対応マトリクス: conceptual-review Round 2

## [Critical] `joinOrganization(): bool` を既存呼び出し元が無視してよい、という整理は成立していない
- 判断: **対応する** (指摘どおり。「互換」の記述は誤り)
- 根拠: PHP の呼び出し互換と業務上の互換は別。競合で `false` になった既存 token 経路が
  成功 flash を返しうる、という指摘は正しい。実際 `acceptInvitationIfValid` は現行でも
  join 失敗時に `current_organization_id` を招待組織へ書いてしまう潜在バグを持っている。
- 対応内容: 「戻り値を無視するため互換」という記述を削除し、**3 経路すべてが `false` を消費する**
  表を追加した。
  - `acceptInvitation` → `ValidationException`「この招待は無効です。」(既存の中立メッセージと同一文言)
  - `acceptInvitationIfValid` → `null` を返し、現在組織の確定も行わない (潜在バグも同時に閉じる)
  - `acceptPendingInvitation` → `null` → controller が 404
  さらに Codex 案「全呼び出し元が結果を消費することを Architecture テストで固定する」を採用し、
  既存 `MembershipWriteLockInventoryTest` の `delegatedToLocked` 検査を
  「本文に `joinOrganization(` を含む」→「本文に `if (! $this->joinOrganization(` を含む」へ強めた
  (PHP 8.4 に `#[\NoDiscard]` が無いための静的代替)。
  新規 gate を増やさず既存 gate を強める形にしたのは、同じ不変条件を守る目録が既にそこにあるため。

## [Warning] `acceptPendingInvitation()` の例外契約が言葉として矛盾している
- 判断: **対応する**
- 根拠: 「例外を投げない」と「インフラ障害を 404 に化けさせない」は無限定では両立しない。
- 対応内容: 「例外を投げない」を**業務上の受諾不能に限る**と限定した。
  業務上の受諾不能 (宛先不一致・不在・期限切れ・取消済・受諾済・組織削除済・ロック下再検証の敗北) は
  `null`、DB/インフラ/プログラム不整合の例外は捕捉せず伝播 (500) と明記。

## [Warning] ロック下再解決が取消経路とのロック契約まで固定できていない
- 判断: **対応する** (ただし `revokeInvitation` にロックを足す案は採らない)
- 根拠: `revokeInvitation()` は membership/role を変えないため `MembershipWriteLockInventoryTest` の
  `exempt` に登録済みで、membership ロックを取らない。したがって
  「ロック下再解決 → 招待行ロック取得」の間に取り消しが割り込む窓は実在する。
  ただしその窓は `joinOrganization()` の招待行 `lockForUpdate()` 下の再検証が閉じる
  (取り消し側の UPDATE も同じ行を取るため直列化される)。
  revoke 側に membership ロックを足すと、招待から user を知らないまま
  users → organizations の canonical 順序を組むことになり、順序契約の方を壊す。
- 対応内容: 「最終判定の権威がどこにあるか」を事象 × 行ロック × 判定場所の表で固定した。
  組織 soft-delete は organizations 行ロック (soft-delete も同じ行の UPDATE) →
  ロック下再解決が権威。取消 / 期限 / 並行受諾は招待行ロック → `joinOrganization()` の
  再検証が権威。並行 join は `insertOrIgnore`。
  この関係を `revokeInvitation` の exempt 理由にも書き、目録から読めるようにする旨を追記。
  ロック順序は canonical (users 昇順 → organizations → 招待行) のままで**新順序を作らない**
  = デッドロック非導入の根拠として明記した。

## [Warning] 背景 (2) に「3 つに強い」という過大表現が残っている
- 判断: **対応する**
- 根拠: 列挙 3 点に期限切れが含まれるが、新経路は `scopeActive` 前提で期限切れを受諾できない。
- 対応内容: 「前 2 つ (転送による第三者受諾 / 本人がメールを見つけられない) に強い」と限定し、
  期限切れは「受諾可能性の改善ではなく**判断可能性の改善**」として分離した。

## [Warning] 通知 flash の旧表現が制約節に残っている
- 判断: **対応する** (Round 1 の置換が該当箇所に当たっていなかった)
- 対応内容: 論点節の末尾を集合表現へ統一。件数 0 →
  「現在有効な招待はありません (取り消し・期限切れ・参加済みの可能性があります)。」、
  1 件以上 → flash なし。

## [Warning] throttle の方式が未確定
- 判断: **対応する**
- 根拠: inline throttle は同一 actor の全 inline route と 1 bucket を共有し、
  最小 max (`recent-auth.password` = 6) を巻き添えにする (AGENTS.md ドメイン規約 5)。
- 対応内容: 「throttle の方式」節を新設し、**named limiter `invitation-accept-in-app` を新設**と決めた。
  閾値 10/min (姉妹 `invitations.accept.store` / `invitation-accept` と同値。既存値を変えない)、
  キー `invitation-accept-in-app:user:{id}` (未認証は `guest` に落とす。`render-trigger` と同 idiom)、
  route parameter をキーに混ぜない、`RateLimiterKeyConventionTest` の inventory 登録が必要
  (gate 登録先は合計 5 箇所)、429 / 正常受諾の Feature テストを追加、を明記した。

## [Warning] 操作系 POST の成功・失敗応答が未明記 (禁止事項 7)
- 判断: **対応する**
- 対応内容: 「応答契約 (禁止事項 7 / 禁止事項 8)」節を新設。
  成功は `redirect()->route('dashboard')->with('success', ...)` (既存 POST 受諾と同形。
  現在組織を切り替えない契約のため参加先画面へは飛ばさない)、
  `redirect()->intended()` を使わない、解決不能時は `abort(404)` のみで `back()` も flash も出さない
  (文脈依存の戻り先が手掛かりになるため)、受諾ボタンを `disabled` にせず in-flight 送信ガードで
  二重送信を抑止する (禁止事項 8)、成功 flash と着地先を Feature テストで固定する、を明記。

## [Suggestion] `expiresAt` の文字列化責務を 1 箇所へ / `Builder<OrganizationInvitation>|null` の generics 明記
- 判断: **対応する**
- 対応内容: `expiresAt` の文字列化は DTO の static factory `fromInvitation()` に閉じ、
  `Assert::isInstanceOf($expiresAt, Carbon::class)` で非 null を型と実データの両方で保証する旨を追記
  (既存 `InvitationRowData` と同じ流儀)。`pendingInvitationsQuery()` の戻り値に
  `@return Builder<OrganizationInvitation>|null` を明記した。

## [Suggestion] 使命との整合性 / スコープの適切さ
- 判断: **見送る** (肯定的コメントのため対応不要)
