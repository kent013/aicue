# 対応マトリクス: impl-review Round 1

> Round 1 の差分は `app/ resources/ tests/ routes/ database/` に限って送っていたため、
> Codex の「差分に存在しない必須ファイル」3 件 (AGENTS.md / docs/ / devnotes/) は
> **送信範囲の問題**であり、実装には最初から含まれている。Round 2 でその差分を送る。

## [Critical] settlementPredicate と実際に削除される集合が一致しない (processed >= candidates)

- 判断: **対応する**
- 根拠: 指摘のとおり `candidates` と `processed` の母集団がずれていた。詳細設計は
  「`processed >= candidates` になりうる」と受容していたが、監視値としては
  `candidates = processed + expiredRemaining` が成り立つ方が正しい。
  設計からの意図的な逸脱として記録する。
- 対応内容:
  - `carryForwardOrganization()` の `processed` を **`rowCount - carryForwardRows`** の
    積算へ変更 (寄与中の繰越行は「再集約で消して作り直しただけ」なので数えない)。
    件数照合には従来どおり**削除した行数の全量**を使う (2 つの意味を分離した)。
  - `BillingRetentionPurgeResultDto` の docblock に
    「`candidates = processed + expiredRemaining`」の恒等式を明記。
  - 検証 1〜4・7 の assertion を `processed >= candidates` から
    **恒等式 `processed + expiredRemaining === candidates`** へ差し替え。
  - N5 の期待値を `processed = 2` → `candidates = 1 / processed = 1 / remaining = 0` へ。
  - runbook §2 の件数表と恒等式の注記を更新。

## [Critical] v0 が生成した繰越行のデータ移行が無い

- 判断: **反論する (根拠を文書化して残す)**
- 根拠: **v0 形の繰越行は現存しえない**。台帳表を作った migration は `2026_06_11_091400`、
  保持期限は 7 年なので `created_at <= now - 7 年` を満たす行はどの環境にも無く、
  v0 の畳み込みが繰越行を作れるのは **2033-06-11 以降**である。
  存在しないデータのための移行を先回りして書かない (AGENTS.md 思考原則 2)。
  仮に人為的に作られた v0 形の行があっても、v1 は繰越行を集約キーの削除対象に含めるので
  保持期限以前に入った時点で新形へ合算され**自己修復する** (残高は常に保存される)。
- 対応内容: この根拠と**限界** (「収束」「`idempotency_key` は NULL」は v1 が作った行に
  ついての不変条件であり、残置行には遡及しない / 2033 年より前に本番で監視するなら
  先に棚卸しが要る) を **drop migration の docblock と runbook §7c** に明記した。

## [Critical] DTO の件数契約

- 判断: **対応する** (上の 1 件目と同じ修正で解消)

## [Warning] `expires_at = now` の境界を固定するテストが無い

- 判断: **対応する**
- 対応内容: **N1b** を追加 (`expires_at` が now ちょうど → 失効側 /
  now + 1 秒 → 寄与側)。実測で
  - 削除枝 `<=` → `<` の変異は **N1b が赤にする**
  - 寄与枝 `>` → `>=` の変異は **どのテストも赤にならない**
  ことを確認した。後者は検出漏れではなく**等価変異**である
  (削除枝が先に走って `expires_at = now` の行を消すので集約の母集団に差が出ない)。
  この事実を N1b のコメントと `mutation-evidence.md` に書いた
  (検出できないものを「検出する」と書かない)。

## [Warning] 短名一致を FQCN 解決と同じ bool へ潰している ((a) 規約)

- 判断: **対応する (潰さない形へ変更。和で拾う方針自体は維持)**
- 根拠: 和で拾いすぎ側へ倒すこと自体は概念設計で決めた方針であり (走査器が
  型宣言 / `::class` / `instanceof` の位置を emit しないため)、fail-closed 側である。
  ただし 2 つを 1 つの `bool` へ潰すと「同名の別クラスに当たっただけ」と
  「本当に台帳モデルを参照している」が区別できず、失敗メッセージが嘘になる。
- 対応内容: `referencesLedgerModel()` → **`ledgerModelReference(): array{fqcn: bool, shortName: bool}`**。
  gate は和で判定しつつ `modelFqcn` を走査結果に残し、TLM-7 に
  「FQCN まで解決できた参照が 0 件なら名前解決が壊れている」検査を足した。
  自己検査も 3 形 (別名 import = fqcn のみ / 同名の別クラス = shortName のみ /
  型宣言位置 = shortName) に分けて固定した。

## [Warning] TLM-5 が主張する検出力を実装できていない

- 判断: **一部対応する + 主張を狭める**
- 根拠: 受け手・削除対象まで見る実装は本 feature の射程を超える (詳細設計の 5 条にも無い)。
  AGENTS.md 共通規約 (b) は「保証範囲の外にする構文は docblock へ明記し、
  明記したならその構文について検出力を主張しない」と定めているので、主張を狭める。
- 対応内容:
  - **実装の強化**: `DB::transaction(` の**第 1 引数が closure であること**を要求する分岐を追加
    (負例「transaction の第 1 引数が closure でない」を gate と走査器自己検査の両方に追加。
    負例は 7 → 8 変異になった)。
  - **主張の縮小**: 走査器 docblock に「保証しないもの 5b」として
    (i) 引数範囲を closure の範囲として扱う (ii) `lockForUpdate(` の受け手は見ない
    (iii) `delete(` の対象は見ない を明記し、「同一 closure 内で**組織行を**先にロックし
    **台帳を**変更する」ことは**証明しない**と書いた。gate の docblock と
    AGENTS.md 規約 21 の文言も同じ範囲へ揃えた。

## [Warning] `literalValue()` がエスケープを評価しない

- 判断: **対応する (docblock で保証範囲を狭める)**
- 根拠: 表名は英小文字と下線だけなのでエスケープを含む書き方は実在しない。
  解析を合わせるより、保証範囲を正しく書く方が安い。
- 対応内容: 走査器 docblock と `literalValue()` の docblock に
  「**引用符を外した素の綴り**の完全一致であり、エスケープ列・変数展開は評価しない
  (その書き方について検出力を主張しない)」と明記した。

## [Warning] gate が「グローバル関数を 1 つも宣言しない」と書きながら 4 つ宣言している

- 判断: **対応する (記述を実態へ合わせる。support class への移動はしない)**
- 根拠: 詳細設計が禁じた本体は**既存 gate と同名の再宣言** (`Cannot redeclare` で
  Architecture レーンが落ちる) であり、目録と走査ロジックは既にクラスへ置いてある。
  本ファイルの helper 4 つはすべて `ticketLedgerMutation…` 等の固有名で、
  既存のどの gate とも綴りが違う (grep で確認済み)。
  Pest のファイルスコープ helper は既存 gate (`TicketLedgerReaderInventoryTest` 等) でも
  使われている作法であり、クラスを 1 本増やす価値がない (思考原則 2)。
- 対応内容: docblock を「**既存 gate と同名の**グローバル定数・関数を宣言しない。
  本ファイルはグローバル定数を 1 つも宣言せず、目録と走査ロジックは
  `Tests\Support\Architecture\` のクラスに置く」へ訂正し、helper 名を列挙した。

## [Warning] `BillingRetentionTarget` / `TicketLedgerEntry` の「物理削除ではなく畳み込み」が v1 と矛盾

- 判断: **対応する**
- 対応内容: 「**単純な物理削除ではなく二段判定の畳み込み**」へ統一
  (`BillingRetentionTarget` / `TicketLedgerEntry` / `TicketLedgerEntryPurger` /
  `BillingRetentionPurgerRegistry` の 4 箇所。grep で残り 0 件を確認)。

## [Warning] 施策 9 / 施策 10 のファイルが差分に無い / `composer test` 未完了

- 判断: **送信範囲の問題として説明する**
- 対応内容: Round 2 の差分に `AGENTS.md` / `docs/` / `devnotes/` を含める。
  `composer test` は完了済み (**7450 tests / 7448 passed / 2 skipped / 5 risky / 0 failed**)。

## [Suggestion] selectRaw の binding / トランザクション順序 / N10 の限界

- 判断: **見送る (指摘なしと同義)**
