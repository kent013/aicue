# 概念設計レビュー Round 2

Round 1 の指摘に対する対応マトリクスと、修正後の概念設計を提示する。
Critical 1 件は「一部反論 + 一部対応」で決着させたので、その反論の妥当性を最優先で判定してほしい。

---

# 対応マトリクス: conceptual-review Round 1

## [Critical] `carry_forward` を保持期限の対象外にする根拠が prose に留まる (無期限保持と 7 年契約の衝突)

- 判断: **一部対応する / 一部反論する**
- 根拠:
  1. **これは v1 が持ち込む新しいリスクではない**。v0 (現行) も繰越行の `created_at` を実行時刻に
     するので、繰越行は**その後どの実行でも適格にならず、削除する経路がどこにも無い**。
     つまり「繰越行が無期限に残る」は **今日すでに真**である。v1 は繰越行を再び適格側に置いて
     **集約単位ごとに 1 行へ収束させる**ので、無期限に残る行数は**厳密に減る** (在籍年数比例 → 集約単位数)。
     したがって本件は「新規リスクの受容」ではなく「既存の性質を明文化し、量を減らす」変更である。
  2. 宣言文 (`resources/views/legal/privacy.blade.php` §4 実読) が 7 年の上限を約束している対象は
     **「ご契約およびお支払いに関する取引関係書類等」**であり、同じ節は
     **「継続中のご契約に関する記録は、当該契約が終了するまで保有します」**と書いている。
     繰越行が持つのは `organization_id` / `delta` / `kind` / `source` / `expires_at` /
     固定文言の `description` / `created_at` だけで、**取引関係書類の中身 (決済事業者の識別子・
     決済額・個別の付与時刻・予約への参照・冪等キー) と個人情報を 1 つも持たない**。
     繰越行は「継続中の契約に紐づく現在残高」であり、7 年で消すべき取引書類ではない。
  3. 寿命は組織行に束縛されている。`ticket_ledger_entries.organization_id` は
     `constrained()->cascadeOnDelete()` (migration 実読) なので、**組織を消せば繰越行も消える**。
     「アカウントが消えても残る」形にはならない。
- 対応内容:
  - 概念設計に **§繰越行の保持分類** を新設し、上記 3 点を明文化する。
  - **prose のままにしない**。詳細設計で
    「`ticket_ledger_entries` の**全列**を実スキーマから列挙し、繰越行では
    『集約キー (4 列) + 固定文言 + 主キー・時刻』以外の列がすべて NULL であること」を
    deny-by-default で検査する Feature テストを置く (列を足したら分類を強制される)。
    これで「明細を持たない」は人の申告ではなく機械の事実になる。
  - **人間の確認事項として明示的に残す**。文面 (`/privacy`) は変更しない (新しい法的主張をしない)。
    「繰越行を取引関係書類ではなく残高として分類する」判断はオーナー確認事項として
    `docs/billing-retention-runbook.md` の申し送りへ書き、**再判定条件**
    (法務が台帳行そのものを取引関係書類と判定したとき / 繰越行へ取引情報を載せる要件が出たとき) を添える。
  - 許容できないと判定された場合の退路も 1 行書く (繰越行にも保持期限を課すなら、
    残高を別表へ持つ設計が必要 = 本 feature の射程外の再設計になる)。

## [Warning] `expiredRemaining` の意味が target ごとに変わる

- 判断: 対応する
- 根拠: 指摘どおり。共通 DTO の語の意味が target で変わるなら、変わることと理由を型の側に書かないと
  運用が誤読する。
- 対応内容:
  - `expiredRemaining` / `candidates` の母集団を台帳 target について
    **「保持期限以前の*取引明細*の残数 (繰越行は数えない)」**と再定義し、
    `BillingRetentionPurgeResultDto` の docblock・`docs/architecture.md`・runbook の 3 か所のうち
    **正本は DTO の docblock** に置く (他は参照)。
  - 利用箇所を詳細設計で全数列挙する
    (`isPublicationReady()` / `BillingRetentionHorizonTest` / `PurgeBillingRetentionCommand` の
    出力行と終了コード / runbook の監視節)。
  - テストで固定する: 「畳み込み後に `countExpired()` が 0 かつ繰越行が実在する」
    「繰越行以外の適格行が 1 行あれば 0 にならない」の 2 本。

## [Warning] 「実質 2〜3 行/組織」は未検証

- 判断: 対応する
- 根拠: 分布依存であり、絶対値を主張する根拠が無い。
- 対応内容: 期待効果を
  **「繰越行は*存続する集約単位数* (未失効の失効時刻の種類 + 無期限の出所) に上限づけられ、
  付与回数・在籍年数に比例して増えない」**へ書き換える。
  さらに詳細設計のテスト計画に**有界性の実測テスト**を置く
  (失効済みの窓を N 個 + 未失効の窓 1 個で seed し、畳み込み後の行数が N に依存しないこと)。

## [Warning] int4 範囲外 / 0 合計 / 再畳み込みの扱いが未確定

- 判断: 対応する
- 根拠: 境界はテストで固定しないと守られない。
- 対応内容: 詳細設計のテスト計画に 5 ケースを明記する
  (`INT_MAX` ちょうどは通る / `INT_MAX + 1` は組織単位で巻き戻る / `INT_MIN` 側も同じ /
  合計 0 は繰越行を作らず削除だけ / 既存の繰越行 + 後から入った古い行は 1 行へ合算される)。
  合計 0 の扱いは正典と同じ**削除だけ**で確定 (0 の行を作らない)。

## [Warning] 件数不一致時の rollback と再実行収束が実装境界として未明示

- 判断: 対応する
- 根拠: 順序が設計で固定されていないと、検査の意味が実装差で変わる。
- 対応内容: 組織 1 件ぶんの手順を**番号つきで固定**する
  (1. `DB::transaction` を開く → 2. 組織行 `lockForUpdate` → 3. 失効済みの物理削除 →
  4. 集約 1 文 (件数・合計・最大 created_at・繰越行数) → 5. 範囲検査 →
  6. 群の削除 → 7. 件数照合 (不一致は例外) → 8. 繰越行の追記)。
  不一致時は組織ごと巻き戻り `unexpectedFailures` に計上、次回実行で同じ組織を再処理して収束する
  ことをテストで固定する。

## [Warning] `CarryForwardGroup` の境界検証が fail-closed である設計が必要

- 判断: 対応する
- 対応内容: `fromRow(object $row): self` を唯一の入口にし、
  列の欠落 (`Assert::propertyExists`) / 非スカラー (`Assert::scalar`) /
  非数値 (`Assert::integerish`) / 集約基準時刻の null (`Assert::notNull`) /
  未知の出所 (`TicketSource::from()` が例外) をすべて例外にする。
  動的プロパティ参照 (`$row->$name`) は使わず `get_object_vars()` 経由にする
  (`ArchSurfaceScanner` の動的メンバ目録を太らせないため)。単体テストを `tests/Unit/` に置く。

## [Warning] 静的 gate の検出範囲 (別名 import / group use / 動的組立て / `DB::table`)

- 判断: 対応する
- 根拠: AGENTS.md の走査器共通規約 (a)(b)(c) にそのまま該当する。短名一致だけでは (a) を満たさない。
- 対応内容:
  - モデル参照の判定を **既存の `Tests\Support\PhpReferenceScanner`** (完全修飾名まで解決 /
    `use` / group use / 別名 / Unresolved の fail-closed を実装済み) に載せる。
    加えて**短名一致による過剰検出を和で足す** (型宣言だけの参照は同走査器が emit しないと
    docblock で明言されているため、そこを短名で埋める)。和なので**拾いすぎ側 = fail-closed**。
  - 走査根は `Tests\Support\TrackedPhpSourceFiles` (git 追跡下の PHP 全数) を `app/` で絞る。
    トークン化は `ArchTokenStream::significantTokens()` (`TOKEN_PARSE` + 例外) を使い、
    **解析できない入力は無言で空にせず例外**にする。
  - **保証しないもの**を gate の docblock に列挙する (定数・列挙型・変数経由の表名 /
    呼び出し側と共通処理側で語彙が分かれる形 / 可変メソッド名 / 到達解析 /
    真の並行実行での排他の実効性)。**主張はその範囲へ狭める**。
  - 負例 (変異) を gate 内に置き、走査器自体の positive/negative は
    `tests/Unit/Architecture/` の自己検査に置く (本リポジトリの既存作法)。
  - 空振り検査 (走査根の非空 / 母集団の下限 / 検出件数の pin) を置く。

## [Suggestion] 使命との整合 / スコープ / drop migration の判断

- 判断: 見送る (追加対応なし)
- 根拠: 肯定的評価であり変更を要さない。

---

## 修正後の概念設計 (全文)

# 概念設計: ticket-ledger-carry-forward-v1 (追記専用台帳の畳み込みを正典 v1 へ追従)

## 背景・課題

### この機構は何か

`ticket_ledger_entries` は **delta 型の追記専用台帳**であり、チケット残高は
「未失効行の delta 合計 − 予約中の hold」である。課金記録の保持期限 (7 年) が来た行を
単純に物理削除すると**利用者の残高が変わる**ため、aicue は T144 (aicue@983fb1bc) で
「保持期限以前の行を `(組織, 出所, 失効時刻)` ごとに合算した**繰越行 1 行**へ畳み込む」
という決着方式を実装した (`app/Services/Billing/TicketLedgerCarryForwardService.php`)。

家系の機能台帳 (lctl) はこの設計判断を feature `append-only-ledger-carry-forward` として
独立起票し、**2026-08-22 に正典を v1 (二段判定・収束繰越形) に確定**した
(`canonical_promoted`。根拠は lctl:devnotes/20260822-canonical-backlog/compare-append-only-ledger-carry-forward.md)。
aicue のセルは **`implemented` → `update_pending` / version `v0` / target_version `v1`** に落ちている。

### v0 (現行 aicue) の不足 — 実コードで裏取りした 4 点

台帳の記述を鵜呑みにせず `/workspace` の現物を実読して確認した。

| # | 正典 v1 が要求すること | 現行 aicue の実測 |
|---|---|---|
| 1 | **第 2 段の寄与判定**。保持期限以前の行のうち失効済み (`expires_at` が現在時刻以前) は繰越に含めず物理削除する | `groupQuery()` (L358-) の述語は `organization_id` / `created_at <= 閾値` / `source` / `expires_at` の一致だけで、**失効時刻と現在時刻を比べる述語が 1 件も無い**。判定は単段である |
| 2 | **繰越行の有界化**。失効済みの窓を集約の単位に残さない | 失効済みの `expires_at` が group key に残るため、**monthly 付与のたびに繰越行が 1 行増え、二度と減らない** (在籍 7 年で 1 組織 84 行規模) |
| 3 | **繰越行の基準時刻を実行時刻にしない** (`created_at` = 畳み込んだ行の最大 `created_at` にして集約単位ごとに 1 行へ収束させる) | L263 で `created_at => CarbonImmutable::now()` を入れ、集約の終端は専用列 `carried_forward_through` の単調前進で表している |
| 4 | **合計の範囲検査**。`SUM(delta)` が signed integer の範囲を超えたら進めない側に倒す | `aggregateGroup()` は `Assert::numeric` と `(int)` キャストのみ。範囲検査は無い |

加えて構造面で 3 点が欠けている。

| # | 正典 v1 | 現行 aicue |
|---|---|---|
| 5 | **変更サイトを deny-by-default で固定する静的ゲート** (`TicketLedgerMutationSiteGateTest` 相当) | 読み手の目録 `tests/Architecture/TicketLedgerReaderInventoryTest.php` だけ。**書き込み経路の閉じ込めはコード上の規律であって機械では固定されていない** (Eloquent の一括削除はモデルの `deleting` guard を発火しないため、静的な検査が無いと「唯一の例外」は担保されない) |
| 6 | **集約結果の境界の型** (`CarryForwardGroup` 相当) | `expiredGroups()` はモデルの `distinct select` で返す。`app/DataTransferObjects/Billing/` に相当 DTO は無い |
| 7 | **置き場** `app/Services/Billing/Retention/` 配下 | `app/Services/Billing/` 直下 |

### なぜ今直すか (使命との関係)

使命の直接の担い手ではないが、**課金の土台**である。v0 の帰結は 2 つとも実害である。

1. **台帳が単調に膨れる**。失効した monthly の窓が繰越行として永久に残るので、
   長期在籍組織の残高計算 (`balance()` は組織全行を毎回 SUM する) が年々重くなる。
   撮影 PWA からの `reserve` は残高判定を組織行ロック下で行うため、
   ここが遅くなると**撮影導線の待ち時間**に直接乗る。
2. **保持期限の宣言と実態がずれる方向に倒れる**。v0 は繰越行の `created_at` を実行時刻にするので、
   繰越行は「保持期限より新しい記録」として台帳に残り続ける。繰越行は取引の明細を 1 つも
   持たないので情報保持の問題は起きないが、**「7 年より古い行は 1 行も無い」という説明**は
   繰越行の作り方に依存した言い方になっており、正典が求める収束形の方が説明が単純になる。

なお **v0 に残高を壊すバグは無い** (残高保存は既存テストが 7 観測値で機械固定している)。
今回は「壊れているものを直す」ではなく「**正典の形へ追従して有界化と静的固定を得る**」である。

## 改善アイデア

**畳み込みを正典 v1 形 (二段判定・収束繰越形) へ差し替える。** 併走は残さない。

### (A) 判定を 2 段に分ける

- **第 1 段 (適格性)**: `created_at <= 閾値`。これを満たさない行は 1 行も触らない (現行と同じ)。
- **第 2 段 (処理方式)**: 実行開始時に 1 度確定した `now` に対して
  - 寄与しない (`expires_at` が非 null かつ `expires_at <= now`) → **物理削除**
  - 寄与する (`expires_at` が null または `> now`) → **`(組織, 出所, 失効時刻)` ごとに合算して繰越 1 行へ**

第 2 段の述語は `TicketLedgerService::sumBalance()` の残高集計条件
(`expires_at IS NULL OR expires_at > now`) の**厳密な補集合**に揃える。
2 つの述語がずれると「どちらの枝にも入らない行」または「両方に入る行」が生まれる。

### (B) 繰越行を収束させる

繰越行の `created_at` を**畳み込んだ行の最大 `created_at`** にする。実行時刻にしないので
繰越行は次回も保持期限以前に留まり、**集約の単位ごとに 1 行へ収束する**。
帰結として専用列 `carried_forward_through` と単調前進のロジック、および繰越行の冪等キーは
**役割を失うので同じ PR で撤去する** (思考原則 3「後方互換の並走を残さない」)。

### (C) 合計の範囲検査を fail-closed で入れる

`delta` 列は `integer` (int4) なので、`SUM(delta)` が
`[-2147483648, 2147483647]` を外れたら**その組織の処理を巻き戻す**。
DB の SUM は bigint で返るため、検査が無いと INSERT の段で生 SQL 例外になる。

### (D) 集約結果の境界の型を切り出す

集計は**列挙型への cast を通さないクエリビルダ**で行い、生の集計行を
`App\DataTransferObjects\Billing\CarryForwardGroup` が受けて型を確定させる
(モデル経由で `selectRaw` すると `source` が列挙型へ cast され、
その値をさらに `TicketSource::from()` へ渡す二重変換で実行時に落ちる = 正典側が実際に踏んだ穴)。

### (E) 変更サイトの静的ゲートを新設する

`tests/Architecture/TicketLedgerMutationSiteGateTest.php` を deny-by-default + 件数完全一致で置く。

- 表名リテラルを持ってよいファイルの**全数申告** (件数まで)
- 台帳モデル参照 + 変更語彙を同居させてよいファイルの**全数申告** (件数まで)
- 削除語彙を持ってよいのは畳み込みサービス 1 ファイルだけ
- 畳み込みが「ロック → 変更」の順で、**同一トランザクションの内側**でロックを取ること
- 検出器の負例 (変異) と走査根の非空

走査の作り方は AGENTS.md 「静的検査 (gate) と走査器の共通規約」に合わせる。

- **(a) 名前解決**: モデル参照は既存の `Tests\Support\PhpReferenceScanner`
  (`use` / group use / 別名を解いて**完全修飾名**まで解決し、受け手が変数・`static::` 等で
  確定できない形は `Unresolved` として返す) に載せる。
  同走査器が「型宣言 / `::class` / `instanceof` の位置は emit しない」と docblock で
  明言しているので、そこは**短名一致による過剰検出を和で足して埋める**。
  和なので判定は**拾いすぎ側 = fail-closed** に倒れる。
- **(b) 解決できない形を落とす**: トークン化は `ArchTokenStream::significantTokens()`
  (`TOKEN_PARSE` + `ParseError` → 例外) を使い、解析できない入力を無言で空にしない。
  畳み込みサービスのメソッド本体が見つからない / トランザクションが無い /
  トランザクションの内側に変更が 1 つも無い (= 空振り) はいずれも**失敗**にする。
- **(c) 負例で裏取り**: ロックがトランザクションの外 / ロックが削除の後 /
  ロックだけ別メソッド / トランザクションごと別メソッドへ逃がす /
  受け手が `DB` ファサードでない / コメント・文字列中の削除語彙、の 6 変異を固定する。
- **(e) 語彙一致**: 変更語彙はトークン列上で「識別子 + 直後が `(`」かつ
  「直前が `function` でない」位置だけを数える (部分文字列一致に頼らない)。
- **保証しないもの**を gate の docblock に列挙し、主張をその範囲へ狭める
  (定数・列挙型・変数経由の表名 / 呼び出し側と共通処理側で語彙が分かれる形 /
  可変メソッド名 / 到達解析 / 真の並行実行での排他の実効性)。

### (F) 置き場を正典化する

`app/Services/Billing/TicketLedgerCarryForwardService.php`
→ `app/Services/Billing/Retention/TicketLedgerCarryForwardService.php`。
`TicketLedgerReaderInventoryTest` の目録パスを追随させる。

### 追従しないもの (正典が要求していない差分は動かさない)

正典実装との差を意図的に残す。理由を明記する。

| 正典 | aicue で採らない判断 | 理由 |
|---|---|---|
| `fold(horizon, apply)` + `BillingRetentionTargetResult` | aicue の `carryForward(threshold)` + `BillingRetentionPurgeResultDto` + purger adapter を維持 | dry-run の表現は aicue では `AbstractBillingRetentionPurger` / コマンド側の契約であり、台帳だけ別形にすると保持期限の報告が 2 形式になる。正典要求 (7 点) に入っていない |
| 組織を **id** で回す (`pluck('organization_id')`) | aicue の `Collection<Organization>` 反復を維持 | `Organization::query()->whereKey($int)` に変えると `ModelDirectFetchInvariantTest` の候補が 1 件増えて `DirectFetchInventory` への登録が要る (実測で確認済み)。現行形は「引数が解決済みモデル由来」として走査器が候補にしないので、**セキュリティ目録を無用に太らせない**方を採る |
| 件数一致検査を持たない | aicue の「集計対象と削除対象の件数一致」検査を**維持** | aicue の台帳追記経路 (`grantMonthly` / `grantPurchased` の冪等 insert) は**組織行ロックを取らない**。集計と削除の間に `created_at <= 閾値` の行が commit されると、合計に入っていない行を削除が巻き込む = その枚数ぶん残高が消える。この窓は aicue に実在するので検査を落とさない (台帳の裁定も「残す判断は追従側の自治でよい」と明記) |

## 期待効果

- **使命への貢献**: 撮影導線の残高判定 (`reserve`) が読む台帳の行数が、
  **付与回数・在籍年数に比例して増える形から、存続する集約単位数
  (未失効の失効時刻の種類 + 無期限の出所) に上限づけられる形**へ変わる。
  長期利用で撮影が重くなる経路を 1 本閉じる。
  **行数の絶対値は主張しない** (組織ごとの期限設定の分布に依存する)。
  主張するのは「失効済みの窓が残り続けないこと」であり、
  これは詳細設計の有界性テスト (失効済みの窓を N 個置いても畳み込み後の行数が N に依存しない)
  で機械的に示す。
- **正典追従**: lctl のセルが `update_pending/v0` → `implemented/v1` になり、
  家系 6 リポジトリ中 4 本目の v1 保有になる (差分巡回の宿題が 1 件減る)。
- **静的固定**: 「追記専用台帳を変更するのは畳み込み 1 ファイルだけ」が
  レビューの規律から機械の検査へ上がる。Eloquent の一括削除がモデル guard を
  発火しないという既知の穴が、静的に塞がる。
- **schema が 1 列減る** (`carried_forward_through`)。役割を失った列を残さない。

## 繰越行の保持分類 (Codex 概念レビュー Round 1 の [Critical] への決着)

**問い**: 繰越行は保持期限で消えない。これは「取引記録は最長 7 年」という宣言と衝突しないか。

**結論**: 衝突しない。繰越行は**取引関係書類ではなく、継続中の契約に紐づく現在残高**である。
根拠を 3 つ置き、そのうち 2 つを機械で固定する。

1. **v1 が持ち込む新しい性質ではない**。v0 (現行) は繰越行の `created_at` を実行時刻にするので、
   繰越行は**その後どの実行でも適格にならず、消す経路がどこにも無い**。
   「繰越行が無期限に残る」は**今日すでに真**である。v1 は繰越行を再び適格側に置いて
   集約単位ごとに 1 行へ収束させるので、**無期限に残る行数は厳密に減る**。
2. **宣言文の対象と一致しない**。`resources/views/legal/privacy.blade.php` §4 が最長 7 年を
   約束しているのは「ご契約およびお支払いに関する**取引関係書類等**」であり、同じ節は
   「**継続中のご契約に関する記録は、当該契約が終了するまで保有します**」と書いている。
   繰越行が持つのは集約キー (`organization_id` / `source` / `expires_at`) と `delta` /
   `kind` / 固定文言の `description` / `created_at` だけで、
   **決済事業者の識別子・決済額・個別の付与時刻・予約への参照・冪等キー・個人情報を
   1 つも持たない**。→ これを **deny-by-default の列分類テスト**で固定する
   (実スキーマの全列を列挙し、繰越行では許可列以外がすべて NULL であること。
   列を足したら分類を強制される)。**文面 (`/privacy`) は変更しない** (新しい法的主張をしない)。
3. **寿命が組織行に束縛されている**。`ticket_ledger_entries.organization_id` は
   `constrained()->cascadeOnDelete()` (migration 実読) なので、組織を消せば繰越行も消える。
   「アカウントが消えても残る」形にはならない。

**人間の確認事項 (機械では決めない)**: 「繰越行を取引関係書類ではなく残高として分類する」ことは
最終的にオーナー / 法務の判断である。`docs/billing-retention-runbook.md` の申し送りへ
判断と**再判定条件** (法務が台帳の行そのものを取引関係書類と判定したとき /
繰越行へ取引情報を載せる要件が出たとき) を書く。
再判定で許容できないとなった場合の退路は「残高を台帳とは別の表で持つ」設計であり、
それは本 feature の射程外の再設計になる (今は先回りして作らない = 思考原則 2)。

## 実装方針（概要）

| # | 施策 | 主な変更 |
|---|---|---|
| 1 | 畳み込みサービスを正典 v1 形へ差し替え + 置き場を `Retention/` へ | `app/Services/Billing/Retention/TicketLedgerCarryForwardService.php` (新規・旧ファイル削除) / `TicketLedgerEntryPurger` の use |
| 2 | 集約結果の境界 DTO | `app/DataTransferObjects/Billing/CarryForwardGroup.php` (新規) |
| 3 | `carried_forward_through` の撤去 | drop migration / モデルの cast と `@property` / `NullableStateColumnRegistry` / `NullInitialStateColumnClassificationTest` の件数 pin |
| 4 | 変更サイトの静的ゲート新設 | `tests/Architecture/TicketLedgerMutationSiteGateTest.php` / 走査器 + 目録 (`tests/Support/Architecture/`) / 走査器の自己検査 (`tests/Unit/Architecture/`) |
| 5 | 読み手の目録の追随 | `tests/Architecture/TicketLedgerReaderInventoryTest.php` (パス変更 + DTO の登録 + 列走査範囲に `DataTransferObjects/Billing` を追加) |
| 6 | 挙動テストの書き換え | `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` (テストファースト) |
| 7 | 規約・文書の追随 | `AGENTS.md` ドメイン固有規約の追加 / `docs/architecture.md` / `docs/billing-retention-runbook.md` |

### 決着させる衝突 (triage の `conflicts_or_blockers`)

1. **T144 が固定したテストの去就**。`carried_forward_through` の単調性を固定する 2 本と
   冪等キーの形を固定する 1 本は、機構ごと撤去するので**意味を失う**。
   「消して終わり」にはしない — 旧テストが守っていた不変条件を**新形のどのテストが引き受けるか**
   の対応表を詳細設計に置き、対応先の無い削除を 1 件も作らない。
2. **`carried_forward_through` 列を落とす**。残すと「書き手のいない列」が schema に残り
   思考原則 3 に反する。落とす側に倒し、波及 (model cast / registry / 件数 pin) を同一 PR で処理する。
   既存 DB のために **add migration は歴史として残し、drop migration を新規に足す**
   (add を消すと新規環境で drop が失敗する)。
3. **`expiredRemaining` の意味**。正典形では繰越行自身が `created_at <= 閾値` に留まるので、
   「保持期限以前の行数」をそのまま数えると**恒久的に 0 にならない**。
   aicue は `BillingRetentionPurgeResultDto::isPublicationReady()` が
   `expiredRemaining === 0` を要求するため、ここを決着させないと保持期限の宣言 gate が落ちる。
   → **`countExpired()` の母集団から `kind = carry_forward` を外す**。
   すなわち台帳 target の `candidates` / `expiredRemaining` は
   **「保持期限以前の*取引明細*の残数」**である (§繰越行の保持分類 参照)。
   語の意味が target ごとに変わることになるので、
   **正本は `BillingRetentionPurgeResultDto` の docblock** に置き
   (`docs/architecture.md` と runbook はそこを参照する)、
   利用箇所 (`isPublicationReady()` / `BillingRetentionHorizonTest` /
   `PurgeBillingRetentionCommand` の出力行と終了コード / runbook の監視節) を
   詳細設計で全数列挙する。テストで
   「畳み込み後に `countExpired()` が 0 かつ繰越行が実在する」
   「繰越行以外の適格行が 1 行あれば 0 にならない」の 2 本を固定する。

## 制約・前提

- **残高保存が最優先の不変条件**。1 枚でも増減したら重大な不具合。
  既存の 7 観測値の突合を土台に、寄与判定の導入で意味が変わる観測 (群ごとの生 SUM) は
  「寄与する行だけの群 SUM」へ**定義を書き換える** (緩めるのではなく正典の意味に合わせる)。
- **直列化**: 台帳書き込みの既存経路と同じ組織行の排他ロックを、変更より先に、
  同一トランザクションの内側で取る。組織 1 件 = 1 トランザクション。1 組織の失敗が他を止めない。
- **append-only の例外はここ 1 ファイルだけ**。ただし `TicketLedgerService::backfillPaymentIntentId`
  は既存の UPDATE 経路として実在する (1 列のみを null → 値で埋める)。
  静的ゲートは**削除語彙を畳み込み 1 ファイルへ、変更語彙を 2 ファイルへ**閉じる形で
  実態どおりに申告する (実態と違う主張をしない)。
- **PHPStan level 10 / Pest / RefreshDatabase グローバル適用 / Factory 経由の fixture** を守る。
- 走査器・gate の新設は AGENTS.md 「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」
  (負例と正例 / 解決できない形を落とす / 空振り検査 / docblock に保証しないもの) に従う。
- **テンプレートとの共有ファイルには触らない**。変更対象パスを
  `docs/template-fingerprints.json` のキーと突き合わせた結果は**全件「非該当」**で、
  採用時債務一覧 (`adoption-debt.tsv`) にあるのは
  `tests/Architecture/BillingRetentionConfigSingleSourceTest.php` だけだが、
  同ファイルの目録 (`BILLING_RETENTION_CALLERS`) は
  `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` を既に含み、
  書き換え後も同ファイルが `BillingRetention::threshold()` を使い続けるので**変更不要**である
  (債務パスを触らない)。

## スコープ外

- 保持年数の値・宣言場所 (`BillingRetention` / `config/legal.php`) は読むだけで変えない。
- 他 6 target の purger (削除で決着する群) は触らない。
- 予約の 2 段階方式 (reserve → commit/release)・残高計算式・`nearestMonthlyExpiry` の探索規則。
- 繰越行の内容をフロントへ出す (現在フロントに台帳 kind の型は無く、
  `TicketLedgerReaderInventoryTest` の検査 7 がその不在を固定している。増やさない)。
- 真の並行実行 (別 connection + barrier) での排他の実効性の測定。
- `billing:purge-retention-expired` のスケジュール・監視契約。
