# 対応マトリクス: conceptual-review Round 2

## [Critical] `AutoRechargeTriggerJob` の unique lock 残留は**ネスト経路で未解決**

- 判断: **対応する (指摘は完全に正しい。Round 1 の対応は誤りだった)**
- 根拠: `reserve()` は `AnalysisPipeline::startJob` / `RenderPipeline::startJob` の
  `DB::transaction` の内側から呼ばれる。「reserve の内側 tx を抜けた直後」は
  **外側 tx の内側**であり、`PendingDispatch` (vendor:218) の unique lock 取得は起きる。
  外側 rollback で jobs 行は消えるが lock は `uniqueFor=30` 秒残る。Round 1 の
  「除外にすることで回避済み」は誤りだった。
- 対応内容: Codex の 3 択のうち **3 番目 (`ShouldBeUnique` を外す)** を採用し、
  あわせて `AutoRechargeTriggerJob` を **AG-127 除外から外して確定 1 の適用対象へ戻す**
  (tx 内 dispatch)。根拠:
  - AGENTS.md ドメイン固有規約 6 が「**入口の排他 (`ShouldBeUnique` / `Cache::lock`) は
    best-effort であり保証を担わない。結果の一回性は永続状態遷移が担う**」と既に規定している
  - `JobExecutionDedupInventoryTest` は既にこの job を
    `JobDedupExemption::GuardedByDownstreamConstraint` で登録しており、その根拠文が
    「起票先の partial unique が『org に pending は 1 つ』を DB で拒否するため…
    **ジョブ自身は課金も状態確定も行わない**」と書いている。つまり
    **この job の一回性を `ShouldBeUnique` が担っていないことは既に裁定済み**である
  - `AutoRechargeTriggerJob` 自身の docblock も
    「重複 dispatch は maybeCreateAttempt の pending 検査 / DB partial unique が吸収する」と書く
  - よって `ShouldBeUnique` は **保証を担わない機構**であり、思考原則 2
    (今必要なものだけ作る) から撤去が正しい。撤去すればネスト深さに依らず問題が消え、
    条件分岐 (Codex の 1 番目の案 = `DB::transactionLevel() > 0` なら dispatch しない) のような
    「黙って投入しない経路」も作らずに済む
  - Codex の 2 番目の案 (最外層まで dispatch intent を返す) は `reserve()` の戻り値契約を
    変え、全呼び出し元へ波及する。得るものに対して過大 (思考原則 2)
- **波及**: `JobExclusionOrderingInvariantTest` の 2 テスト
  (`uniqueFor は既定接続の retry_after を下回る` / `uniqueFor は正の値である`) が
  `AutoRechargeTriggerJob->uniqueFor` を参照している。**削除ではなく反転**で扱う
  (M8 の 5 件目として追加。旧主張・旧目的・新主張・新前提・前提を守る機構・反転根拠の 6 行 docblock)。
  `JobExecutionDedupInventoryTest` の登録は根拠文が `ShouldBeUnique` に言及していないため変更不要。
- コスト (受容する): trigger job の投入量が「org あたり 30 秒に高々 1 件」から
  「reserve 1 回につき 1 件」へ増える。job 本体は `exists()` 1 本で早期 return する薄い箱であり、
  reserve は人間の操作 (解析/レンダ開始) 起点なので実運用上の増分は無視できる。

## [Warning] 低残高通知の「ロック保持時間が伸びない」はネスト経路で不成立

- 判断: **対応する (指摘は正しい)**
- 根拠: 同上。ネスト時は外側 tx がロックを保持したまま通知 INSERT が走る。
- 対応内容: §3 の記述を「**最外層の `reserve()` では伸びない。ネスト時は外側 tx の
  ロック保持中に実行される**」へ訂正した。
- **さらに重要な帰結を自分で見つけたので設計に反映した**: `DB::afterCommit` は
  ネスト時に「最外層 commit 後 = 全 tx の外」で実行していた。撤去すると
  **通知 INSERT の失敗が業務 tx を巻き込みうる**ようになり、これは AG-127 の
  「付随的副作用の失敗で業務 tx を巻き戻さない」に正面から反する。
  §3 に「AG-127 の保証がネスト時に狭まること」を明示し、緩和として
  (a) 通知は `reserve()` の tx を抜けた**最後**に行う、
  (b) `safely()` が握るのは**アプリケーション層の例外**であり、そこは behavioral test で固定する、
  (c) SQL 層の失敗 (PostgreSQL の tx abort) は fail-closed に倒れることを §8 に明記する、
  の 3 点を書いた。

## [Warning] PostgreSQL abort を behavioral test で固定せよ

- 判断: **一部対応・一部反論**
- 根拠 (反論): tx 内で SQL 失敗を意図的に起こすには DDL (制約追加/トリガ) をテスト内で
  流す必要があり、`RefreshDatabase` + `--parallel` のレーンに DDL を持ち込む機構を
  新設することになる。得られるのは「PostgreSQL の仕様どおりに abort する」という
  **PostgreSQL の仕様の再確認**であって、本アプリの不変条件ではない (思考原則 2)。
- 対応内容 (受容部分): 現実的な失敗クラス (アプリケーション層の例外) については
  behavioral test を置く — `NotificationCenterService` をモックして throw させ、
  **reserve が成功し予約行が残る**ことを固定する (= AG-127 の性質を実際に検査する)。
  SQL 層の abort は §8 の「保証しないもの」に明記する。

## [Warning] R-10 (sync レーンでの業務クロージャ再実行)

- 判断: **対応する (ただし Codex の想定より状況は良い。実測で否定できた)**
- 根拠: `Connection::handleCommitTransactionException()` が再実行 (return) するのは
  `causedByConcurrencyError($e) && $currentAttempt < $maxAttempts` のときのみ。
  `DB::transaction($callback)` の既定 `$attempts = 1` では `1 < 1` が偽なので**常に rethrow** する。
  **`app/` 配下で `DB::transaction()` の第 2 引数 (attempts) を使っている箇所は 0 件**
  (`grep -rnE 'DB::transaction\([^)]*\},\s*[0-9]' app/` で 0 hit / `DB::transaction(` の
  出現は 35 ファイル)。よって再実行は起きない。
- 対応内容: §6 の R-10 を「**前提: `DB::transaction` の attempts は全経路で既定の 1。
  よって再実行は起きない。attempts>1 を導入したら sync レーンでのみ再実行が起きうる**」
  へ書き換え、§8 にも前提として記載した。

## [Warning] §9 の「6 本消える」が列挙と対応していない

- 判断: **対応する**
- 対応内容: §9 を名称列挙 + 3 分類 (「commit 済み・未投入の窓を除去」/
  「reconcile まで残る」/「at-most-once として受容」) の表に書き換えた。件数表現をやめた
  (Codex の言う「件数表現のドリフト」を構造的に避けるため、数を書かない)。

## [Warning] §5-1 の負のコントロールでは「母集団から一部が脱落する故障」を検出できない

- 判断: **対応する (良い指摘)**
- 対応内容: §5-1 を次の 3 層に書き換えた。
  1. **経路統合の負のコントロール**: 検出器を「ファイルパス配列 / クラス名配列を受ける純関数」
     として設計し、テストは **fixture ディレクトリツリー**を作って
     「列挙 → 読み込み → 検出」の**全経路**を通す (検出関数だけを直接叩かない)
  2. **母集団の完全性アンカー**: 本番母集団に既知の代表要素が含まれることを assert する
     (`app/Services/Billing/TicketLedgerService.php` ∈ `appPhpFiles()` /
     `RunManualAnalysis::class` ∈ `shouldQueueClasses()` /
     `database-analysis` ∈ `config('queue.connections')` のキー集合)。
     特定ディレクトリだけ脱落する故障をこれで検出する
  3. **既存 inventory との接続**: `shouldQueueClasses()` の完全性は
     `QueuedJobLeaseInventoryTest` / `JobExecutionDedupInventoryTest` が
     **対称差が空**の形で既に deny-by-default 固定している。その契約を docblock で参照し、
     本 gate では二重実装しない (ブリーフ申し送り (d) と同じ理由)
  4. **D4 は全接続集合で検証**: 「`after_commit=true` を持ってよいのは `sync` **だけ**」を
     `queue.connections` 全件に対して評価する (既定接続だけを見ない)

## [Suggestion] AG-127 の判定基準を 6 条件へ厳格化し enum + premise test に落とせ

- 判断: **反論する (基準そのものは受け入れるが、機構は作らない)**
- 根拠: 上の Critical 対応で `AutoRechargeTriggerJob` を確定 1 の適用対象へ戻した結果、
  **確定 1 の母集団 (業務 tx の内側から投入される queue dispatch) における AG-127 除外は 0 件**
  になった。残るのは
  - 低残高通知 = `ShouldQueue` ではない (同期 DB 書き込み) ため確定 1 の母集団外
  - `CreateInquiryAction` の Mail 2 本 = **そもそも業務 tx が無い**
    (同ファイル docblock「単一 save のため明示 `DB::transaction` は使わない」を Read で確認済み)
  - `BillingNotificationDispatcher` 経由の請求通知 = 呼び出し元がすべて業務 tx の外
  であり、いずれも「除外」ではなく「**元から tx の外**」である。
  case を 1 つも持たない exemption enum と premise test を作るのは、
  思考原則 2 (今必要なものだけ作る) に反する死んだ機構である。
- 対応内容: §3 を「**AG-127 の除外は 0 件。除外機構 (enum) は作らない**」と書き換え、
  「除外が必要になったら gate が落ちるので、そのときに設計し直す」= deny-by-default の
  最も強い形 (allow-list なしの deny) であることを gate の docblock に書く方針にした。
  Codex の 6 条件は「将来除外を作るときの判断チェックリスト」として §3 に残した。

## [Suggestion] §11-2 の順序を施策ごとに「テスト追加・赤化確認 → 実装」にせよ

- 判断: **対応する**
- 対応内容: §11-2 を施策単位の「赤 → 実装 → 緑」の並びへ書き換えた。

## [Suggestion] violations を value object にせよ

- 判断: **対応する (詳細設計で具体化)**
- 対応内容: guard は `list<QueueDispatchAtomicityViolation>` (readonly DTO: 規則 ID enum +
  接続名 + 実値 + メッセージ) を返し、`enforce()` がメッセージへ写像する設計にする。
  詳細設計 §M6 に書く。
