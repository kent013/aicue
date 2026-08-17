# 対応マトリクス: design-review Round 2

全体判定 **CHANGES_REQUESTED**（**Critical 0 件**）。施策 D と G が REQUEST_CHANGES、A/B/C/E/F は APPROVE。
Round 1 で出した反論 2 件（Filament admin guard の扱い / 索引の明示名を採らない）は**いずれも承認**された。
残る Warning 4 件 + Suggestion 2 件の判断を記録する。

---

## [Warning] 施策 D: 新索引の性能効果を強く表現しすぎている

- 判断: **対応する（指摘が正しい。以前の表現は誤りだったので撤回する）**
- 根拠: 完全に正しい。`group by user_id` + `max(occurred_at)` は、
  `IN` リストに一致した**各利用者の login 索引エントリを原則として走査する**。
  `occurred_at` を索引に足して得られるのは
  「集約に要る値を索引から供給でき、heap 参照を減らせる（index-only scan の候補になる）」ことまでで、
  **履歴件数に対する計算量が定数になるわけではない**。
  「行数の増加に耐える」「最大値の取得に効く」は、この索引が与えない保証を与えると書いていた。
  本リポジトリは「保証範囲を誇張しない」を規約として持つので、これは直さなければならない。
- 対応内容: migration の doc comment とリスク節の両方を書き直した。
  - 効果を「heap 参照を減らせる / index-only scan の候補になる」に限定した。
  - 「計算量は履歴件数に対して線形のままで定数にはならない」を⚠として明記した。
  - 「性能を必須保証にしない。必要になったら `EXPLAIN (ANALYZE, BUFFERS)` を根拠に
    `DISTINCT ON` / LATERAL / 別の導出方式を設計し直す。今は先回りしない」を追記した。
  - 「以前の表現は誤りだったので撤回した」と明示的に書いた（黙って差し替えない）。

## [Warning] 施策 G テスト 11: `/manage/users` を閲覧する主体が書かれていない

- 判断: **対応する**
- 根拠: 正しい。招待経由で参加した利用者は org Member であり、
  `Gate::authorize('manageMembers', …)` で**403 になる**。
  本人のまま `/manage/users` を開く手順では props を検査できず、テストが成立しない。
  設計の手順に穴があった。
- 対応内容: テスト 11 の記述に
  「そのあと owner（または org Admin）として認証し直してから `/manage/users` を開く。
  招待された本人のまま開いてはいけない（403 になる）」を明記した。

## [Warning] 施策 G-4: 検証コマンドが AGENTS.md と同期していない

- 判断: **対応する**
- 根拠: 正しい。`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` の
  3 件が欠けていた。AGENTS.md の `VERIFICATION_COMMANDS` マーカー節は
  `tests/js/architecture/verification-commands-doc-sync.test.ts` が package.json との
  同期を deny-by-default で強制している正本であり、設計側が部分集合を書くと
  実装者が走らせ漏らす。
- 対応内容: G-4 を全 10 件に揃え、正本がどこかとその同期を強制するテスト名も併記した。

## [Warning] 最終確認表の「Feature 8 件」が修正後の G-1（11 項目）と一致していない

- 判断: **対応する（件数の二重管理をやめる形で直す）**
- 根拠: 正しい。Round 1 の修正で G-1 が 8 件から 11 件に増えたのに、
  最終確認表の件数を更新していなかった。**同じ数を 2 か所に書いたから食い違った**という、
  本リポジトリが繰り返し警告している失敗そのものである。
- 対応内容: 件数を写す形をやめ、「G-1 に記載した全ケース」という参照に置き換えたうえで、
  内訳（新規 10 件 + 既存テストの再利用 1 件）を明示した。
  以後 G-1 に行を足しても最終確認表は書き換え不要になる。

## [Suggestion] 施策 C: 「Browser lane は DOM 契約を新設しない」が `data-testid` の新設と矛盾する

- 判断: **対応する**
- 根拠: 正しい。「Browser テストへの波及は無い」ことと
  「DOM 契約を 1 つも増やさない」ことは別の主張で、後者は事実に反していた。
- 対応内容: 波及変更表の Browser lane 行を
  「Browser テストは存在しない（影響なし）。ただし **Vitest 用の DOM 契約は 1 つ増える**
  （`data-testid="member-last-login-{id}"`）」に直した。

## [Suggestion] 施策 A: `Assert::integerish()` 後の `(int)` cast を PHPStan がどう扱うか

- 判断: **見送る（設計の変更は不要。実装時の確認事項として既に書いてある）**
- 根拠: Codex 自身が「設計上の問題ではない」と述べており、
  設計は既に「実装時に `composer phpstan` の出力で確定する。型を緩めて黙らせない」を
  PHPStan 適合チェック節に持っている。
  `Assert::integerish()` は `int|float|string` を返す宣言なので、
  narrowing が足りなければ `(int)` cast の前後を局所的に組み替える（禁止事項 2 を破らない）。

## Round 1 の反論 2 件への Codex の判定

- **Filament admin guard を構造で保証し `metadata.guard` で絞らない**: **承認**。
  Codex の追記「要件は『web guard だけを数える』ではなく
  『`App\Models\User` について発生した Login を数える』であり、
  `metadata.guard = 'web'` を読み取り側に足すと将来正当に追加された users provider の
  セッション guard まで無言で除外する」は、こちらの根拠より強い理由になっている。
  → **この理由を施策 A の doc comment へ取り込む**（対応済み判断: 取り込む）。
- **索引の明示名での drop を採らない**: **承認**。変更不要。
