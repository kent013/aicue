# 対応マトリクス: conceptual-review Round 4

## [Critical] `remainingAfter()` の確認と実際の削除が原子的でない (TOCTOU)

- 判断: **対応する (指摘が正しい。かつ本リポジトリに既存の作法がある)**
- 根拠: passkey 2 件のユーザーが別々の passkey を同時に削除すると、
  両リクエストが「もう片方が残る」と判定して両方成功し、残存 0 件になりうる。
  投影が正しくても確認と削除が別トランザクションなら防げない、という指摘は正しい。

  さらに、これは**本リポジトリが既に解いている問題型**である。
  AGENTS.md ドメイン固有規約 1「シナリオ整合の共有ロック規約」は
  「対象行を `lockForUpdate()` で取得した**同一トランザクション内**で反映する」+
  「経路 inventory を Architecture テストへ昇格させ、新経路の登録を必須にする」
  (`ScenarioWritePathInventoryTest` / `MembershipWriteLockInventoryTest`) という形で
  同型の不変条件を守っている。同じ作法に乗せるのが正しい
  (思考原則: 先人の知恵を探せ / フレームワークとリポジトリのレンジ内でやる)。
- 対応内容: 施策 1 に「**ログイン手段除去の直列化規約**」を新設する。
  1. `EnsureLoginMethodRemains` が `DB::transaction()` を開き、
     対象 User 行を `lockForUpdate()` で取得する
  2. **ロック取得後に** `remainingAfter()` を評価する (ロック前の評価は使わない)
  3. **同一トランザクション内で `$next($request)`** を実行し、vendor の削除まで完了させる
  4. ロック取得順序を **User → credential** に固定する
  5. 将来の password 削除 / SSO 解除にも同じ規約を適用する
  - **経路 inventory は新設しない**。除去 route は必ず
    `EnsureLoginMethodRemains` を通ること (= 単一の直列化点) を
    `LoginMethodRemovalRouteTest` が既に deny-by-default で強制しており、
    middleware がロックを所有する以上それが inventory そのものになる
    (gate を 2 本に増やすのは冗長 = 禁止事項 6)
  - Feature/統合テストに「passkey 2 件を並行削除しても片方だけ成功し最低 1 件残る」を追加

## [Warning] トランザクション境界と Response 生成 / ロールバック時の flash

- 判断: **対応する (概念設計には方針と誤差の向きだけ書き、詳細は詳細設計へ)**
- 根拠: 妥当な懸念。ただし整理すると本設計では危険な組合せは起きにくい:
  - ブロックする場合は `$next()` を**呼ぶ前**に abort するため、
    「ロールバックしたのに成功 flash が残る」経路は通常存在しない
  - `PasskeyDeleted` はトランザクション内で dispatch されるため、
    ロールバック時には `RecentAuthState::clear()` (session 操作) だけが残りうる。
    これは「**再認証を余計に 1 回要求する**」方向の誤差であり **fail-safe**
- 対応内容: 上記の 2 点を設計に明記し、
  「`$next()` が返す Responsable の変換時点 / `PasskeyDeleted` / recent-auth clear /
  session 更新の順序」の確定と、ロールバック時に成功 flash が残らないことのテストは
  **詳細設計フェーズの担当**として明示する。

## [Warning] `LoginMethodRemoval` の variant が閉じていない

- 判断: **対応する**
- 根拠: 正しい。TOTP 不変条件テストで `allPasskeys()` を使いながら variant 一覧に無かった。
  文字列種別や nullable ID への縮退を避けるべきという指摘も PHPStan level 10 の観点で正しい。
- 対応内容: variant を**閉じた集合**として明示する:
  `none()` / `password()` / `social(string $provider)` / `passkey(Passkey $target)` / `allPasskeys()`

## [Warning] 直列化境界は S1 ではなく S3 で完成させる必要がある

- 判断: **対応する**
- 根拠: 正しい。S1 時点では除去 route が 1 本も無いためロック規約を検証できない。
- 対応内容: S3 の内容に
  「User 行ロックを伴う原子的な投影評価」「vendor 削除まで同一トランザクション」
  「並行削除テスト」を明記する。S1 は middleware の骨格までとする。

## [Suggestion] binder で 404 を確定させてから DTO を作る

- 判断: **対応する (既に設計にある不変条件の明文化)**
- 対応内容: 「除去対象 passkey が対象 User に属することは
  **binder が 404 で確定させ**、`LoginMethodRemoval` 生成はその後」であることを明記する
  (§2 施策 3-b の binder 差し替えと同じ不変条件)。
