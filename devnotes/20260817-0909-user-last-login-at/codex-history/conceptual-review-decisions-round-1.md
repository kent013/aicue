# 対応マトリクス: conceptual-review Round 1

全体判定は **APPROVED**。Critical は 0 件。Warning 3 件と Suggestion 群への判断を記録する。
APPROVED のため Round 2 は起こさず、Warning は概念設計への反映 + 詳細設計への申し送りで閉じる。

## [Warning] `LastLoookup` の戻り値型を設計段階で固定せよ (`max(occurred_at)` の型揺れ)

- 判断: **対応する**
- 根拠: 妥当な指摘である。`max()` は driver によって string / DateTime / null に揺れ、
  PHPStan level 10 では `mixed` から narrowing しないと落ちる。本リポジトリの既存作法
  (`InvitationRowData::fromInvitation` が `Assert::isInstanceOf($expiresAt, Carbon::class)` で
  narrowing している) と同じ形で閉じるのが自然である。
- 対応内容: 概念設計 §5-1 に戻り値契約を追記した
  (`array<int, CarbonImmutable>` で返し、DTO 境界で ISO8601 文字列へ落とす)。
  実際の narrowing 手順 (`Assert` の使用位置) は詳細設計 §施策 A で確定する。

## [Warning] 保持期間への依存に機械的なトリップワイヤが欲しい

- 判断: **対応する (ただし提案の一部は採らない)**
- 根拠: 「将来の purger 追加時に見落とされる余地が残る」という懸念は正しい。
  ただし「根拠文の**文言**が消えたら落ちる Architecture テスト」は採らない。
  文言の完全一致 pin は、台帳の日本語を推敲しただけで赤くなる脆い検査であり、
  本リポジトリが嫌う「2 か所に同じ文字列を持つ」形そのものになる。
  代わりに **区分そのものを pin する**方が、意味を正しく捉えていて壊れにくい:
  「`security_audit_events` が undecided 区分から動いたら落ちる」検査であれば、
  purger を入れる人が必ず本設計を読み直す導線になり、文言の推敲では赤くならない。
- 対応内容: 概念設計 §2-4 の決着 3 を差し替え、施策 F を
  「根拠文への追記」+「区分 pin のトリップワイヤ」の 2 点にした。
  もう 1 つの提案 (「保持期間未確定のままこの表示は `記録なし` と表現する」を
  テストで固定する) は**採用**し、施策 G のテスト計画に入れた。

## [Warning] DTO / TS の nullability 契約を明示せよ

- 判断: **対応する**
- 根拠: 指摘のとおりで、`?string` (ISO8601) / `string | null` の対で持つのが
  既存の `SecurityController` の `lastUsedAt` / `PasskeySection.svelte` と同じ流儀である。
- 対応内容: 概念設計 §4-3 と §5 の表に型を明記した。
  ただし Svelte 側の「`null` 分岐後にのみ `formatDateTime()` を呼ぶ」は**採らない** —
  `formatDateTime(value, fallback)` は第 2 引数の fallback で null を吸収する設計であり
  (`resources/js/lib/date-format.ts` の SSoT)、呼び出し側で分岐を書くのは
  その SSoT を迂回することになる。`formatDateTime(member.lastLoginAt, "記録なし")` で書く。
  この差分は詳細設計 §施策 C に根拠付きで記す。

## [Suggestion] 使命への貢献は運用支援であり、設計自身が正直に位置づけている

- 判断: **見送る (変更不要)**
- 根拠: 既に §6 で「撮影体験そのものを改善する施策ではない」と明記済み。

## [Suggestion] 「記録なし」は断定しない文言にする必要がある

- 判断: **対応済み (変更不要)**
- 根拠: §4-3 で `未ログイン` を採らない根拠とともに確定済み。

## [Suggestion] 索引置き換えは妥当。index 名と migration の lock 影響を確認せよ

- 判断: **対応する (詳細設計へ送る)**
- 根拠: 索引の張り替えは pgsql では対象表への lock を伴う。
  `security_audit_events` は認証経路が書き込む表なので、lock の性質を明示する価値がある。
- 対応内容: 詳細設計 §施策 D で index 名 (Laravel 既定命名) と
  lock の扱い (現行データ量・`CONCURRENTLY` を使うか) を確定する。
