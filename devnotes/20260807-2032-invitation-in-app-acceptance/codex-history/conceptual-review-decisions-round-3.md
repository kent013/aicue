# 対応マトリクス: conceptual-review Round 3

## [Warning] 戻り値消費の Architecture テストが文字列一致に依存しすぎている
- 判断: **対応する** (Codex 案 2 を採用)
- 根拠: `if (! $this->joinOrganization(` の表記固定は
  `$joined = $this->joinOrganization(...); if (! $joined)` という正常な実装で壊れ、
  コメント中の同一文字列では通ってしまう。型安全性ではなく表記を固定する gate になっている。
  AST 化は本件の得るものに対して過大。
- 対応内容: `MembershipWriteLockInventoryTest` の `delegatedToLocked` 検査は
  **既存どおり「本文に `joinOrganization(` を含む」までに留める** (強めない)。
  消費されていることは**振る舞いで固定**する:
  `tests/Feature/Organization/InvitationAcceptRaceTest.php` を新設し、
  3 経路それぞれで「ロック下再検証が false になる状況」を決定的に作り、
  失敗契約 (`ValidationException` / `null` かつ現在組織不変 / 404) を検証する。
  決定的な作り方は、テスト内だけで `OrganizationInvitation` の `retrieved` イベントを登録し、
  ロック下で再取得されたインスタンスへ `forceFill(['revoked_at' => now()])` を当てる
  (本番コードに検査用の穴を開けない)。

## [Warning] throttle の Feature テスト表現が曖昧 (「10 回目まで通る」は成立しない)
- 判断: **対応する**
- 根拠: 受諾は一回性の操作で、同じ招待への 2 回目は受諾済み = 404。
  「10 回すべて成功」は実現できない、という指摘は正しい。
- 対応内容: テストを 2 本に分離。
  (1) 429 の位置: 不在 id へ 10 回 POST (すべて 404。throttle は controller より前に走るため
      bucket は消費される) → 11 回目が 429。
  (2) 正常受諾: 有効な招待へ 1 回 POST → 302 + success flash (429 にならない)。

## [Warning] gate の登録数と登録表が一致していない
- 判断: **対応する** (指摘どおりの数え間違い)
- 対応内容: 登録表に `RateLimiterKeyConventionTest` の行を追加し、
  「**合計 6 本の gate に対応が要る。うち inventory への明示登録が要るのは 5 本**」と再集計した
  (`ThrottleCoverageInventoryTest` は throttle を貼れば満たすため登録不要)。
  施策一覧 #4 の記述も「gate 6 本への対応 (うち inventory 登録 5 本)」へ修正。
  新しい GET の Inertia 面を作らないため `DocumentTitleCoverageTest` は対象外である旨も明記。

## [Warning] スコープ外の「署名 token 経路は一切触らない」が本文と矛盾
- 判断: **対応する**
- 根拠: 共有コアの戻り値強化に伴い `acceptInvitation` / `acceptInvitationIfValid` の
  競合時挙動は実際に変わる。「一切触らない」は嘘になる。
- 対応内容: スコープ外の記述を
  「route / 解決条件 / 画面は変更しない。ただし共有コアの戻り値強化に伴い、
  既存 2 経路で競合失敗を既存の失敗契約へ変換する追随修正は行う」へ限定した。
  改善アイデア A の「`joinOrganization()` をそのまま共有する」も
  「**変換責務**を共有し、**戻り値契約を強化する**」へ書き直した。

## [Suggestion] in-flight 送信ガードが `disabled` の別名にならないこと
- 判断: **対応する**
- 対応内容: 「ボタンは常に押下可能なまま (`disabled` 属性を出さない) で、
  ハンドラ側が in-flight 中の再入を無視する」と明記し
  (既存 `NotificationListItem.svelte` の `opening` / `reading` ガードと同じ流儀)、
  「`disabled` 属性が描画されないこと」を js component テストで固定する旨を追記した。

## [Suggestion] 使命 / 期待効果 / スコープ / 型安全性
- 判断: **見送る** (肯定的コメントのため対応不要)

---

# 追記: Round 4 (APPROVED) の [Suggestion] 対応

## [Suggestion] `retrieved` は通常取得でも発火する。適用回を限定せよ
- 判断: **対応する**
- 対応内容: 「取得回数をカウントしロック下の再取得 (n 回目) にだけ `forceFill` を当てる」
  「テストの目的は競合の完全再現ではなく `joinOrganization() === false` の消費契約の
  決定的な検証である旨を docblock に明記する」を概念設計へ追記した。

## [Suggestion] throttle 節の「gate 登録先は合計 5 箇所」の文言整理
- 判断: **対応する**
- 対応内容: 「**inventory への明示登録先は合計 5 箇所** (対応が要る gate は 6 本)」へ書き換えた。
