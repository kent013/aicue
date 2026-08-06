# 対応マトリクス: conceptual-review Round 1

## [Critical] 権限不足 actor の 403 と payload 不正 422 の境界をテストで固定せよ (観点 5)
- 判断: **対応する**
- 根拠: 指摘のとおり「Gate が validation より前」は現状コードの性質でしかなく、
  入れ替わったら権限のない actor が user_id の応答差を観測できる。
  実査でも `ProjectMemberController::store` は Gate → validate の順、
  `OrganizationOwnershipController::store` は Gate → validate の順で、
  これを固定する Feature テストは存在しない (既存は 403 を 1 パターン確認するだけ)。
- 対応内容: §7-1 に「権限のない actor は user_id の実在/不在/非メンバーによらず同一 403」
  (2 経路) を追加。§4-2 の順序記述を「テストで固定する不変条件」として明記。

## [Critical] MCP binder の入力分類境界 (422 と 403 の線引き) を明文化・固定せよ (観点 8)
- 判断: **対応する**
- 根拠: 「整数として受理された値はすべて membership 判定へ流す」を明文化しないと、
  将来 0 / 負数 / 表記ゆれの扱いを変えたときに新しい判定差が生まれうる。
  現行実装の実査結果: `is_bool` → 422、`filter_var(FILTER_VALIDATE_INT, min_range=1)` が
  false → 422 (`'abc'` / `'1.5'` / `'1e5'` / `'0'` / `'-1'` / 配列 / `'1 '`)、
  通過値 (`'001'` → 1 を含む) は membership 判定へ。
  これらの 422 は**実在情報を含まない**ため、統一の必要がないことも併記する。
- 対応内容: §4-3 に境界を明文化し、§7-1 に境界値テスト (0 / -1 / '1.5' / '001' / 配列 / bool) を追加。

## [Warning] ResponseSignature の正規化範囲 (session cookie / old input / CSRF) を明記せよ (観点 3・8)
- 判断: **対応する (ただし懸念自体は不成立)**
- 根拠: `Tests\Support\ResponseSignature` を実査した結果、`set-cookie` は VOLATILE_EXACT で
  除外済み、比較対象は status + 非 volatile ヘッダ + body。
  validation 失敗は 302 redirect (body 空 / Location は `->from()` の値) なので、
  old input が body に出ることはない。したがって flaky にはならない。
  ただし「302 が一致するだけ」では**エラーメッセージ差**を見逃すため、比較を 1 段強める。
- 対応内容: §7-1 に「signature 一致 + `session('errors')->get('user_id')` の**文言一致**を
  併せて表明する」を追加し、§2-4 に ResponseSignature の正規化範囲を記載。

## [Warning] 完了条件に既存テスト更新まで含めよ (観点 2)
- 判断: 対応する
- 対応内容: §7-1 の冒頭に「新規 + 既存更新 + Architecture が全部緑で初めて完了」と明記。

## [Warning] transferOwnership 側も Gate 前置を不変条件として固定せよ (観点 5)
- 判断: 対応する (上記 Critical 1 に統合)

## [Warning] PHPStan level 10 での `$request->input()` (mixed) の扱いを詰めよ (観点 7)
- 判断: **詳細設計で対応する**
- 根拠: 概念設計の粒度ではない。既存 controller は `Assert::integerish()` +
  `(int)` cast の形で統一されており、そのパターンを踏襲する
  (`$request->validate()` の戻り値を使う形に変えると他 controller と不揃いになる)。
- 対応内容: §4 に一行だけ方針を書き、シグネチャ・cast の具体は detailed-design.md へ。

## [Suggestion] 使命との距離感 / スコープ限定 / exists 撤去の妥当性
- 判断: 見送る (肯定的評価のため変更不要)
