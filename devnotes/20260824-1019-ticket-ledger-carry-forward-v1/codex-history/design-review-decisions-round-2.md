# 対応マトリクス: design-review Round 2

## 最優先: 主キー取得 gate への反論の証拠が実装と一致していない

- 判断: **対応する (指摘が正しい)**
- 根拠: 当初の実測は `whereKey($organization->getKey())` の形だったのに、変更後コードは
  closure が `int` を捕まえて `whereKey($organizationId)` になっていた。
  この 2 つは走査器から見て別物であり、「0 件」の理由が provenance 除外なのか
  解析失敗なのか区別できない。指摘のとおり証明になっていなかった。
- 対応内容: **推奨案を採る** — closure へ渡すのを `int` から
  **`Organization` モデルそのもの**へ変え、`whereKey($organization->getKey())` にした
  (`$organizationId` は closure の内側で確定する)。あわせて **4 形の実測**を設計へ載せた。

  | 形 | 候補 |
  |---|---|
  | A. closure が `Organization` を捕まえ `whereKey($organization->getKey())` (**採用形**) | **0 件** |
  | B. closure が `int` を捕まえ `whereKey($organizationId)` (当初案) | **1 件** |
  | C. `whereKey($request->input('organization_id'))` (payload 由来。**負のコントロール**) | **1 件** |
  | D. `withTrashed()` **無し**で `whereKey($organization->getKey())` | **0 件** |

  - **A と D が同じ 0 件** → `withTrashed()` が解析を壊しているのではない
  - **B が 1 件** → 走査器はこのファイル・このメソッドを見ている (母集団の外ではない)
  - **C が 1 件** → payload 由来へ変えれば検出される (**負のコントロールが点灯**)

  したがって A が 0 件になる理由は provenance 除外であり、解析の失敗ではない。
  `DirectFetchInventory` の登録も走査器の変更も行わない。
  「id を捕まえる形へ書き換えたら候補が 1 件生まれるので、その時は登録する」ことを
  実装の docblock にも書く。

## 施策 1

### [Warning] 決着対象を「4 か所すべてで共有する」は実装と一致しない

- 判断: 対応する
- 対応内容: docblock を
  「`settlementPredicate()` を**直接共有するのは組織の列挙と件数・監視の 2 経路**。
  **処理側は厳密な補集合となる 2 枝で実装する** (`expiredScope()` /
  `contributingGroups()` + `groupScope()`)。処理側を同じ述語にできないのは
  削除 (1 本の DELETE) と集約 (集約キーごとの GROUP BY) で必要な形が違うからである。
  補集合であることは N1・N18・境界時刻テスト・変異表が固定する」へ書き換えた。

### [Warning] append-only の説明がまだ広すぎる (3 箇所)

- 判断: 対応する
- 対応内容: 3 箇所すべてを「**行の物理削除・残高スナップショットへの置換を行う唯一の経路**」へ限定した。
  - クラス冒頭: 「(「台帳への変更の唯一の経路」ではない — `TicketLedgerService` は
    通常の追記と `payment_intent_id` の限定 backfill を持つ)」を併記
  - append-only 節: 例外は **2 種類**あると明記 (行の削除・置換 = 本ファイル /
    限定 metadata backfill = `TicketLedgerService::backfillPaymentIntentId()`)
  - mutation inventory の理由文と定数コメント: 同じ語義へ統一
  さらに実装の最後に `grep -rn "唯一の例外" app/ tests/ docs/ AGENTS.md` で
  全数点検する手順を施策 9 へ入れた。

### [Warning] ロック範囲の説明に内部矛盾 (`grant` の扱い)

- 判断: 対応する
- 対応内容: 「同じロック (`lockOrganizationRow()`) を取る経路 = 畳み込み同士と、
  `TicketLedgerService` のうち**残高判定を伴う操作** (`grant` / `reserve` / `commit` / `release`)。
  一方 `grantMonthly` / `grantPurchased` / `grantSignupGrant` / `clawback` の**冪等 insert は
  取らない** (実読で確認)」へ書き分けた。

### [Warning] PHPStan fallback 節に撤回済みの gate 方針が残っている

- 判断: 対応する
- 対応内容: 「同ファイル内の `Organization::query()` の存在に読み替える」を削除し、
  「**gate が受理するのは 2 形だけなのでどちらを選んでも通る。
  変数受け手・長い連鎖へ流れたら gate が落とすので、その時は実装を直す**」へ差し替えた。

## 施策 2 / 3 / 5 / 6 / 8 / 10

- 判定: APPROVE。追加対応なし。
- [Suggestion] `nullableTimestamp()` が `CarbonImmutable::parse()` で任意の自然言語日時を
  受理する点: **DTO を別用途へ公開しない**という前提を維持する
  (入力は畳み込みサービスが組み立てた集約 SQL の結果だけ)。この前提は
  DTO の docblock に既に書いてある「集約 SQL は畳み込みサービスが組み立てる」で担保する。

## 施策 4

### [Critical] 文書末尾 (`migration / 後方互換の扱い`) に旧結論が残っている

- 判断: 対応する
- 対応内容: 「デプロイ順序の制約は無い / コード先行でも migration 先行でも動く /
  この事実を migration の docblock に書く」を削除し、
  「**新コード → drop migration に固定**。drop 先行は旧コードを壊し、
  drop 後に旧コードへ単純 rollback もできない。**順序の正本は runbook の手順節**で、
  migration の docblock と architecture.md はそこを参照する」へ差し替えた。

### [Warning] リスク節の「3 か所で食い違わせない」という書き方

- 判断: 対応する
- 対応内容: 「**正本は runbook の手順節**であり、migration の docblock と
  `docs/architecture.md` は**そこを参照するだけ**にする (順序そのものを 2 か所に書かない)」へ統一した。

## 施策 7

### [Warning] テストファースト段 1 に N3 の旧記述が残っている

- 判断: 対応する
- 対応内容: 段 1 の一覧を **N1 / N2 / N11 / N12 / N14 / N18** に変え、
  「**N3 はここに置かない** (v0 でも緑になるため赤の起点にならない)」を明記した。
  N3 / N3b は段 10 へ移し、**N3b は短絡条件を一時的に壊して赤を確認**する手順を書いた。

### [Suggestion] ケース 17 の「列欠落」の説明

- 判断: 対応する
- 対応内容: `propertyExists` → `get_object_vars()` + `Assert::keyExists()` へ直した。

## 施策 9

### [Warning] サービス docblock と mutation inventory の語義統一

- 判断: 対応する (施策 1 の同名指摘と同じ対応)

### [Warning] 旧結論の全数確認を計画に入れる

- 判断: 対応する
- 対応内容: 施策 9 に
  `grep -rn "順序制約\|migration 先行\|コード先行" docs/ devnotes/{本ディレクトリ}/ database/migrations/`
  と `grep -rn "唯一の例外" app/ tests/ docs/ AGENTS.md` の 2 本を実装最後の手順として書いた。

### [Suggestion] rollback でアプリケーション状態の意味も戻らない

- 判断: 対応する
- 対応内容: runbook の新節へ
  「v1 が作った繰越行は `idempotency_key` が null なので、旧コードへ戻して同じ集約キーを
  再処理したときの挙動は旧状態と同一にならない (**列の値が戻らないだけでなく
  アプリケーションの状態の意味も完全には復元されない**)」を追記する。
