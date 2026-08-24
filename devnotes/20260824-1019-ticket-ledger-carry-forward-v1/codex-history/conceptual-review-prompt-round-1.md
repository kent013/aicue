## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は上に挿入済み）

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【この案件の追加文脈】
- 本件は家系の機能台帳 (lctl) が確定した「正典 v1 (二段判定・収束繰越形)」への追従である。
  正典の参照実装は laravel-claude-template の
  app/Services/Billing/Retention/TicketLedgerCarryForwardService.php であり、
  正典が要求する 7 点は概念設計の表に列挙してある。
- 追従先が確定しているので「別の設計案の方が良い」型の指摘よりも、
  「正典形を aicue に入れたときに壊れるもの・見落としているもの」を優先して指摘してほしい。

---

## 概念設計

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
- 台帳モデル名 + 変更語彙を同居させてよいファイルの**全数申告** (件数まで)
- 削除語彙を持ってよいのは畳み込みサービス 1 ファイルだけ
- 畳み込みが「ロック → 変更」の順で、**同一トランザクションの内側**でロックを取ること
- 検出器の負例 (変異) と走査根の非空

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
  在籍年数比例から**集約単位数比例 (実質 2〜3 行/組織)** へ落ちる。長期利用で撮影が
  重くなる経路を 1 本閉じる。
- **正典追従**: lctl のセルが `update_pending/v0` → `implemented/v1` になり、
  家系 6 リポジトリ中 4 本目の v1 保有になる (差分巡回の宿題が 1 件減る)。
- **静的固定**: 「追記専用台帳を変更するのは畳み込み 1 ファイルだけ」が
  レビューの規律から機械の検査へ上がる。Eloquent の一括削除がモデル guard を
  発火しないという既知の穴が、静的に塞がる。
- **schema が 1 列減る** (`carried_forward_through`)。役割を失った列を残さない。

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
   繰越行は取引の明細を 1 つも持たない**残高のスナップショット**であり、
   保持期限が消すべき「7 年より古い取引記録」ではない。この意味づけを
   `docs/architecture.md` と runbook に明記する。

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
