# Round 3: 指摘への対応と修正後の概念設計

## 対応マトリクス (Round 2 の指摘に対する判断)

# 対応マトリクス: conceptual-review Round 2

## [Critical] 主キー昇順 + 総件数上限による starvation (先頭の失敗行が後続を塞ぐ)
- 判断: 対応する
- 根拠: 指摘のとおり。現行 4 経路は上限を持たず全件を処理しているので、上限の導入は
  **今まで無かった無音の滞留を新設する**ことになる。回収基盤が回収を止めるのは本末転倒
- 対応内容: 上限の意味を「1 回の掃引で扱う総件数」から「1 ページの大きさ (メモリ上界)」へ変えた。
  契約を `candidateIds($sweptAt, ?$afterId, $pageSize)` のページ送りにし、sweeper が
  最後に見た id をカーソルにして候補が尽きるまで回す。例外になった行を跨いでカーソルが
  前進するので、その掃引の中で全候補に手が届く。`--limit` は手動実行の試し打ち用の
  総件数上限として残す (既定は無制限 = 現行挙動と同じ)。
  唯一 500 件上限を維持するのは撮影アップロード stream で、これは S3 の I/O を有界にする
  **現行実装が既に持つ性質**である旨を明記した (新設する穴ではない)。
  公平性は Feature テストの不変条件として明記した

## [Warning] `LogicException` を一律 `Skipped` に変換すると不変条件違反を隠す
- 判断: 対応する
- 根拠: 同一型からメッセージで見分けるのは脆い。指摘に同意する
- 対応内容: `ReservationNotReleasableException` (`LogicException` を継承) を新設し、
  予約が reserved でないときだけこれを投げる。stream が `Skipped` へ変換するのはこの型だけで、
  他の `LogicException` は sweeper へ通す。継承しているので既存の `catch (LogicException)`
  呼び出しと既存テストはそのまま成立する

## [Warning] S3 削除失敗を `Recovered` に畳むと集計が実態を表さない
- 判断: 対応する
- 対応内容: `RecoveredWithCleanupFailure` を結果の種類に追加し、コマンド出力と結果 DTO に
  件数を残す。未削除オブジェクトは自動では拾えないことを「保証しないもの」として docs に書く

## [Warning] Schedule gate の同一性が曖昧 (全部が同じコマンド名)
- 判断: 対応する
- 対応内容: 突き合わせるのはコマンド名ではなく **stream キー**であると明記。registry の
  キー集合と Schedule の `--stream=<key>` 集合がちょうど一致すること (未登録・未定期実行・
  重複をそれぞれ落とす)、キーごとに 4 点 + 実行間隔を検査することを確定事項に書いた

## [Warning] `recover()` の戻り値契約 (DTO にすべきか)
- 判断: 一部対応 (DTO 化は採らない)
- 根拠: `recover()` が返す情報は結果の種類だけで、補助情報は 1 つも要らない。
  1 フィールドの DTO は包み紙が増えるだけで、enum を直接返すほうが型は強い
  (自由文字列も未型付け配列も介在しない)。ただし「集計側で網羅処理する」という指摘の
  本質は正しい
- 対応内容: 結果の種類を 5 値の enum に閉じ、**stream ごとに取りうる種類を目録で申告**し、
  集計側は `default` の arm を持たない網羅 `match` で処理する、と確定事項に明記した。
  掃引全体の結果 (stream ごとの種類別件数と例外件数) は typed な DTO で返す


---

## 修正後の概念設計 (全文)

# 概念設計: stuck-job-recovery (滞留回収の共通基盤への寄せ替え)

## 背景・課題

### 1. 台帳 (lctl) 側の事実

feature `stuck-job-recovery` の裁定 AG-083 (2026-08-06) は「雛形を置くだけではなく、実装そのものを
標準形 v1 へ統一する」と決めた。標準形が求めるのは 3 点 — **回収の共通基盤 / 既存回収の寄せ替え /
定期に外から叩く入口**。aicue のセルはこれまで「追従元 (laravel-claude-template) に共通基盤が
無いので寄せる先が存在しない」という理由で pending だった。

2026-08-10 の差分巡回でこの理由は失効した。laravel-claude-template:T076 が共通基盤を入れ、
既存の期限切れ予約解放を同一基盤へ移設して旧実装を撤去している (= 寄せ替えの参照実装が家系に
存在する)。したがって aicue の pending 理由は「基盤が無いから成立しない」ではなく
**「基盤はできたので着手できる (未着手)」** に変わった。

正典 (laravel-claude-template) の構成:

- `StuckWorkStream` 契約 — `candidateIds()` が主キーだけを返し `recover()` が id しか
  受け取らない。**行ロック下での述語再評価が構造的に強制される**のが要点
- `StuckWorkRecoverySweeper` / `StuckWorkStreamRegistry` — 走査枠と作用枠を分ける
- `work:recover-stuck {--stream} {--limit} {--apply}` — 既定 dry-run、Schedule 3 本
- `config/recovery.php` + `RecoveryThresholds` — stall は進捗時刻起点、give-up と look-back は
  発生時刻起点と起点を分ける
- deny-by-default の stream 台帳 gate / 撤去済み参照の再流入を止める gate

### 2. aicue 側の事実 (実読で確認した)

回収の入口は**現在 5 本**あり、それぞれ独立に実装されている
(監督セッションの観測は 3 本だったが、実読すると解析ジョブと撮影アップロード予約の
2 本が加わる。**寄せ替えの対象範囲はこの 5 本で確定させる**)。

| # | 入口 (routes/console.php) | 実体 | 周期 | 滞留の判定 | 述語の再評価方式 |
|---|---|---|---|---|---|
| 1 | `analysis:recover-stale-jobs` | `AnalysisJobService::recoverStale()` | 5 分 | queued: `created_at` / running: `updated_at` が 30 分超過 | `failJob()` 内の `lockForUpdate` + terminal guard |
| 2 | `render:recover-stale-jobs` | `RenderJobService::recoverStale()` | 5 分 | queued 10 分 / running 30 分 | 同上 |
| 3 | `billing:release-stale-reservations` | `TicketLedgerService::releaseStale()` | 5 分 | `expires_at` 超過 / 失効 monthly hold | `release()` 内の行ロック + status 検査 (不成立は `LogicException`) |
| 4 | `billing:recover-stale-webhook-events` | `StripeWebhookProcessor::recoverStale()` | 5 分 | `received` かつ `updated_at` が 15 分超過 | `claimStale()` の WHERE 付き `lockForUpdate` |
| 5 | `capture:release-stale-upload-reservations` | `StaleUploadReservationSweeper::sweep()` | 10 分 | pending の `expires_at` / verifying の `updated_at` | 条件付き UPDATE (CAS) |

5 本が共有しているもの: 候補を列挙 → 1 件ずつ取り直して条件を再確認 → 件数を返す、という同じ作法。

5 本でばらついているもの (= 今回の課題):

1. **述語の再評価が 3 通りの機構**で書かれている (行ロック + guard / 例外 / 条件付き UPDATE)。
   どれも正しいが、6 本目を書く人がどれを写すかは書き手次第で、**間違えても静かに壊れる**
   (正常に動いていたものを失敗にする事故はエラーにならない)
2. **1 回の実行で扱う件数の上限を持つのは 5 番だけ** (500 件)。他は全件を 1 回で処理する
3. **試し打ち (dry-run) の手段が 1 本も無い**。本番で「いま何が回収対象なのか」を
   副作用なしに見ることができない
4. **回収結果の語彙がばらばら** (件数 int が 4 本、4 つの内訳を持つ DTO が 1 本)
5. **回収の入口が存在することを機械的に強制する仕組みが無い**。定期実行を 1 本足すときに、
   それが回収なのか突き合わせなのか保持期間の削除なのかを申告させる場所が無く、
   **6 本目を素通しで足せる**
6. **cron の失敗が運用アラートに載るのは 4 番だけ**。1〜3・5 は失敗しても無音である
   (回収が止まっていることに誰も気づけない = 本 feature の存在理由そのものが穴になる)

### 3. なぜ今か

4 番 (Stripe webhook の滞留回収) は本日 T162 で入ったばかりで、その設計は
「既存 2 つと同じ作法を採り、共通の回収基盤は作らない」と明記して着地している
(`devnotes/20260815-1109-stripe-webhook-stuck-recovery/`)。本設計はその判断を正面から見直す。

T162 の判断は当時の前提 (aicue にも家系にも共通基盤が無い) の下では妥当だった。前提が変わった
今、同じ判断を続けると 6 本目・7 本目が同じ理由で増える。**3 本目が生えた直後の今が寄せ替えの
好機**であり、家系の他リポジトリ (motivation) では「基盤ができた直後に寄せ替えではなく 2 本目の
独自回収が増えた」という乖離の拡大が実際に観測されている (台帳 2026-08-12 の巡回記録)。

## 改善アイデア

**5 本の回収経路を 1 つの契約・1 つの入口・1 つの目録へ寄せ、旧実装を同じ PR で撤去する。**

### 取るもの (正典から aicue に持ち込む)

1. **stream 契約**: `candidateIds()` は主キーだけを返し、`recover()` は id (と掃引開始時刻) しか
   受け取らない。行の内容を持ち回れないので、**再取得と述語の再評価が構造的に強制される**。
   これが正典の核であり、aicue が本当に必要としている唯一の部分である
2. **走査枠と作用枠を分けた sweeper と registry**: 1 回の実行で扱う件数の上限と、
   結果の集計をここに 1 箇所だけ持つ
3. **単一の入口コマンド** `work:recover-stuck --stream= --limit= --apply` (既定 dry-run)
4. **deny-by-default の目録 gate**: 登録された stream の集合と、定期実行に載っている
   コマンドの集合を突き合わせ、どちらにも属さないものを落とす
5. **撤去済み参照の再流入を止める gate**: 撤去した 5 本のコマンド名とメソッド名が
   コードにも docs にも戻ってこないことを機械で固定する

### 取らないもの (aicue には要らない / 入れると悪くなる)

1. **`config/recovery.php` への閾値の集約**。aicue の滞留閾値は既にドメインの config にあり、
   **ジョブの `timeout` < キューの `retry_after` < 予約 TTL ≤ 滞留閾値**という序列を
   Architecture テスト 2 本 (`AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest`)
   が固定している。回収側の config へ移すと**序列の情報源が 2 つに割れる**。
   → stream が自分のドメインの config を読む形にする (正典からの意図的な逸脱として記録する)
2. **look-back (遡及の下限)**。入れると「古すぎる滞留は永久に回収されない」という
   新しい無音の穴が生まれる。件数が問題になった実測が無い段階で入れるのは
   「今必要なものだけ作る」に反する
3. **give-up の共通機構**。「自走をやめる条件」は webhook が既に持っている
   (`attempts >= 8` で `recovery_pending` へ置き、**失敗として確定はしない**)。
   共通側に持たせるのは「1 回の実行で扱う件数の上限」だけにし、上限に達しても
   対象を失敗にせず次回の実行へ残す (正典が言う区別と同じ)
4. **正典と同じ形の結果 DTO**。webhook は 4 つの内訳 (再実行済み / 次回へ回した /
   回収待ちへ置いた / 何もしなかった) を **docs/architecture.md が監視の必須項目として
   宣言済み**なので、共通側は「結果の種類ごとの件数」を stream ごとに保持する形にする

### 寄せ替えない経路 (目録に理由付きで登録し、混ぜない)

「滞留した業務状態を前へ進める」ことと、「外部と突き合わせる」「保持期間を決着させる」ことは
別の概念である (似ているからで統合しない)。次は stream にしない:

- `billing:reconcile-auto-recharge` / `billing:reconcile-schedules` /
  `billing:reconcile-subscription-status` — Stripe を真実として収束させる突き合わせ。
  DB の状態だけでは行き先が決まらない
- `render:reconcile-outputs` — 世代交代済みの出力を消し込む後始末であり、滞留の前進ではない
- `inquiry:purge` / `idempotency:prune` / `account:purge-deletion-requests` /
  `billing:purge-retention-expired` — 保持期間の決着
- `billing:detect-orphan-billing-organizations` / `billing:send-billing-reminders` — 検知・通知

### 5 番 (撮影アップロード予約) の扱い

現行の `sweep()` は 3 つの責務が 1 メソッドに同居している:
(a) 期限切れ予約の解放、(b) 未登録 S3 オブジェクトの削除、(c) 古い行の物理削除。
(a)(b) は滞留回収なので stream にし、(c) は保持期間の決着なので
`capture:purge-upload-reservations` (日次) へ分ける。aicue には既に保持期間の決着コマンドが
4 本あり、分けたほうが既存の作法に揃う。新コマンドの新設は**本改善の目的ではなく、
1 メソッドに同居した 3 責務を解体するために必要な最小限**である (機能追加ではない)。

外部副作用 (S3 削除) の扱いは次を契約とする:

- **候補の正本は DB だけ**にする (S3 を列挙しない)。stream は
  `take_upload_reservations` の主キーだけを候補にする
- 解放 (条件付き UPDATE) に勝った実行だけが S3 削除へ進む (現行の CAS をそのまま移設する)
- **S3 削除の失敗は解放を巻き戻さない**。行は解放済みのまま、結果の種類は
  `RecoveredWithCleanupFailure` とし (`Recovered` に畳まない)、削除失敗は `report()` にも
  載せる。件数がコマンド出力と結果 DTO に残るので、**未削除オブジェクトの増加を
  集計から観測できる** (枠の解放は人質にしない)。行は解放済みになるため
  次回の掃引では候補にならない = **未削除オブジェクトは自動では拾えない**ことを
  「保証しないもの」として docs に明記する

## 契約の確定事項 (Round 1 レビューを受けて明文化する)

概念段階で決めておかないと詳細設計が分岐するため、次を契約として先に固定する。

1. **候補列挙はページ送りにする (総件数の上限にはしない)**。契約は
   `candidateIds(CarbonImmutable $sweptAt, ?positive-int $afterId, positive-int $pageSize): list<positive-int>`
   とし、`id > $afterId` の主キー昇順で `pageSize` 件までを返す。sweeper は最後に見た id を
   次ページの開始点にして、候補が尽きるまで 1 回の掃引の中で回す。
   - **上限をページの大きさに限る理由**: 「1 回の掃引で N 件まで」という総件数の上限にすると、
     先頭に居座る 1 件が毎回例外になったとき、後続の行が永久に処理されない
     (回収基盤そのものが回収を止める)。ページ送りなら例外になった行を跨いで
     カーソルが前進するので、その掃引の中で全候補に手が届く
   - `--limit` は**手動実行の試し打ち用の総件数上限**として残す (既定は無制限 = 現行挙動)。
     手動で上限を付けた実行は先頭側しか見ないことを help に書く
   - 唯一の例外は撮影アップロード予約 stream で、**現行の 500 件上限を維持する**
     (S3 の存在確認・削除の I/O を有界にするための既存の判断)。したがってこの stream だけは
     先頭候補が毎回例外になると後続が進まないが、これは**現行実装が既に持つ性質**であり
     新設する穴ではない (かつ後述のとおり削除失敗は例外にせず観測へ回す)
   - 候補の主キーはすべて bigint auto-increment なので `positive-int` に閉じる
     (`int|string` の union にはしない = PHPStan level 10 で緩まない)
2. **競合は例外ではなく結果の種類で返す**。`recover()` は述語が不成立になった競合
   (別プロセスが先に前進させた / 既に解放済み) を `Skipped` として返す。
   - `TicketLedgerService::release()` は現在いずれの不成立でも `LogicException` を投げるため、
     **競合だけを表す専用の例外型** `ReservationNotReleasableException` (`LogicException` を継承) を
     新設し、予約が reserved でないときだけこれを投げる。stream が `Skipped` へ変換するのは
     この型**だけ**で、他の `LogicException` は sweeper へ通す
     (メッセージ文字列で見分ける形にはしない)
   - 継承しているので、既存の `catch (LogicException)` 呼び出し
     (`AnalysisJobService::failJob` / `RenderJobService::failJob` の並行 release 握り) と
     既存テストはそのまま成立する (後方互換のための並走ではなく、型の細分化である)
   - 結果の種類は次の 5 つに閉じる。**stream ごとに「取りうる種類」を目録で申告**し、
     集計側は網羅 `match` で処理する (`default` の arm を作らない):
     `Recovered` (前へ進めた) / `RecoveredWithCleanupFailure` (業務状態は前へ進めたが
     付随する後始末に失敗した = 撮影アップロードの S3 削除失敗) /
     `Skipped` (競合・条件不成立で何もしなかった) / `Deferred` (前へ進まなかったが
     次回の掃引へ残した = webhook の再実行失敗) / `Escalated` (自動回収の対象外へ移し
     人手へ渡した = webhook の `recovery_pending`)
3. **1 件の失敗で掃引全体を止めない。ただし成功で隠さない**。sweeper は 1 件の
   `Throwable` を `report()` して次の候補へ進み、その実行で 1 件でも例外があれば
   コマンドの終了コードを失敗にする (= `Schedule::onFailure()` が発火する)
4. **dry-run が数えるのは候補件数だけ**で、`recover()` は 1 度も呼ばない。webhook の
   回収は受理そのものが書き込みなので、「回収されるはずの件数」を副作用なしに
   出すことはできない。出力には**実際に回収される件数の上界**であると明記する
   (できないことをできるように見せない)
5. **失敗通知の正本は `Schedule::onFailure()` → `report()`** とする (aicue の運用アラート
   経路は `report()` のみ。既存の webhook 回収・オートリチャージ突き合わせと同じ形)。
   **とくに `--apply` の付け忘れは回収が全面停止しても無音**なので、これは必須の gate である。
   gate が突き合わせるのは**コマンド名ではなく stream のキー**である
   (定期実行は全部が同じ `work:recover-stuck` なので、コマンド名の集合では
   stream の欠落も重複も検出できない):
   - registry に登録された stream キーの集合と、Schedule に載っている
     `--stream=<key>` の集合が**ちょうど一致する** (未登録・未定期実行・重複をそれぞれ落とす)
   - キーごとに `--apply` / `onOneServer()` / `withoutOverlapping()` / `onFailure()` の
     4 点と、目録が申告する実行間隔が付いていることを検査する
6. **撤去 gate の走査範囲**は `app/` `routes/` `config/` `tests/` と `docs/` の運用正本に限る。
   `devnotes/` と `docs/TODO-closed.md` は過去の記録なので対象外とする
   (歴史を書き換えさせない)

## 期待効果

- **使命への貢献**: 本改善が守るのは「SOP → シナリオ → 撮影 → レンダ」の各段が止まったときに、
  **人手を介さず前へ進む**という運用上の性質である。解析・レンダ・撮影アップロードの 3 本は
  パイプラインそのものの滞留で、課金予約の 2 本 (チケット予約 / Stripe webhook) は
  **その滞留で押さえたままになる利用枠と、支払い済みなのに付与されないチケットの回収**として
  同じ鎖に載っている。回収の入口が 5 本バラバラだと、1 本が静かに止まっても誰も気づけない。
  入口と目録を 1 つにすることで「止まった仕事が必ず前へ進む」ことの担保を 1 箇所にする
- **6 本目を素通しで足せなくする** (deny-by-default の目録)。これが本改善の主目的で、
  同型の実装が 4 リポジトリで独立に増えたという家系の実績がその必要性の証拠である
- **本番で試し打ちできる** (既定 dry-run)。回収は「正常に動いていたものを失敗にする」
  事故を起こしうる操作なので、副作用なしに対象を見られることに運用上の価値がある
- **cron の失敗が全 stream で運用アラートに載る** (現在は 1 本だけ)

## 実装方針（概要）

1. `App\Contracts\Recovery\StuckWorkStream` (契約) と
   `App\Enums\Recovery\RecoveryOutcome` (結果の語彙) を新設する
2. `App\Services\Recovery\StuckWorkStreamRegistry` (stream の解決) と
   `App\Services\Recovery\StuckWorkRecoverySweeper` (走査・上限・集計) を新設する
3. `App\Services\Recovery\Streams\` 配下に stream を 5 本置く。中身は既存の実装を移設し、
   業務判定 (何を滞留とみなすか・どう前へ進めるか) は各ドメインの Service に残す
4. `App\Console\Commands\Operations\RecoverStuckWorkCommand` (`work:recover-stuck`) を新設し、
   `routes/console.php` の 5 本の `Artisan::command` と `Schedule` を撤去して、
   stream ごとの `Schedule` (現行の周期を 1 本ずつ保存) に置き換える
5. `AnalysisJobService::recoverStale()` / `RenderJobService::recoverStale()` /
   `TicketLedgerService::releaseStale()` / `StripeWebhookProcessor::recoverStale()` /
   `StaleUploadReservationSweeper::sweep()` を**同じ PR で撤去**する (並走させない)
6. Architecture テストを 2 本追加する (目録 gate / 撤去済み参照 gate)。
   目録 gate は Schedule の配線 (`--apply` / `onOneServer` / `withoutOverlapping` /
   `onFailure`) もあわせて固定する
7. 既存の Feature テストは 1 本も消さず、呼び出し先を stream・新コマンドへ張り替えて維持する

**フロントへの影響は無い** (Svelte / Inertia props / TypeScript 型 / API の表面は 1 つも変わらない)。
変更は Console・Service・テストの 3 層に閉じる。

**テストで固定する不変条件 (詳細設計で施策ごとに割り付ける)**:

- **公平性**: 先頭の候補が毎回例外になっても、候補数が 1 ページを超える後続の行が
  同じ掃引の中で処理される (ページ送りの契約。上限を持つ撮影アップロード stream だけは
  「500 件までしか見ない」ことを別テストで明示する)
- **dry-run の副作用ゼロ**: `--apply` 無しの実行で `recover()` が 1 度も呼ばれない
- **競合の扱い**: 候補列挙後に別プロセスが前進させた行は `Skipped` になり、
  コマンドは成功で終わる (運用アラートを鳴らさない)
- **例外の扱い**: 1 件の例外は掃引を止めず、その実行の終了コードは失敗になる
- **Schedule の配線**: stream キーの集合一致と 4 点 (`--apply` / `onOneServer` /
  `withoutOverlapping` / `onFailure`) + 実行間隔
- **移設の等価性**: 既存 5 経路の Feature テスト (閾値・冪等・競合・順序非依存・通知) を
  そのまま維持し、呼び出し口だけ差し替えて緑にする

**実装順序 (fail-first を保つための固定順)**:
共通契約と sweeper のテスト → 契約と sweeper 本体 → 低リスクな stream 3 本
(解析 / レンダ / チケット予約) → webhook stream → 撮影アップロードの責務分割 →
旧入口 5 本の撤去 → Schedule 配線と目録・撤去の 2 gate。

## 制約・前提

- **既存テストの削除は禁止事項**。5 経路のテスト (閾値・冪等・競合・順序非依存・通知) は
  すべて維持する。呼び出し口の張り替えのみ行う
- **後方互換の並走を残さない** (思考原則 3)。旧メソッド・旧コマンド名は同じ PR で消す
- 閾値の値は 1 つも変えない (`AnalysisTimeBudgetInvariantTest` /
  `RenderTimeBudgetInvariantTest` の序列をそのまま緑に保つ)
- 定期実行の**周期も変えない** (5 分 ×4 / 10 分 ×1)。統合するのは実装契約と入口であって
  実行間隔ではない
- `tests/Support/Security/DirectFetchInventory.php` に登録済みの 3 エントリ
  (主キー同一性クエリの分類) は、移設先のファイルパスへ**キーを更新**する必要がある
- テンプレートからの意図的な逸脱は `docs/template-divergence.md` に記録する

## スコープ外

- 突き合わせ (reconcile) 系 4 本・保持期間の決着系 4 本・検知系 2 本の寄せ替え
- 閾値の値そのものの見直し
- キュー投入経路の原子性 (別 feature `queue-dispatch-outbox` の範囲。aicue は既に
  業務トランザクション内 dispatch で決着済み)
- 台帳 (lctl) への書き戻し (監督セッションの責務)


---

再レビューしてください。全体判定 (APPROVED / CHANGES_REQUESTED) と、未解消の
[Critical] / [Warning] があればその根拠と修正案を日本語で示してください。
