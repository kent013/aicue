【アプリの使命 (North Star)】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(migrate:fresh 等)をエージェント判断で実行すること
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory → 窓口 (PromptDefense) → 実行単位 (GuardedPrompt) の1 本道のみ)
6. prompt 文字列のコード直書き(resources/prompts/*.yaml に置く)
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

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

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12+ / PHP 8.4 / Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の背景】
- 本件は他リポジトリ (laravel-claude-template) が「配る側」として整備した検査資産を byte 一致で移植する追従作業である。家系の機能台帳 lctl の裁定 AG-081 / AG-081b が根拠。
- 適用対象 (退避 release を正常系に持つジョブ) は aicue に 0 件であり、それでも移植することが裁定の趣旨である。
- app/ は 1 行も変更しない。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: 退避 (release) を正常系に持つジョブの再試行終端 — 正典資産の移植

- 作業項目: `aicue:T215` (登録予定)
- 台帳 feature: `job-deferral-termination` (area: async / canonical_version: v1)
- 台帳上の現状: `aicue` セルは `status: pending` / `target_version: v1`
- 根拠となる裁定: **AG-081** (2026-08-06 標準形 v1 の確定) と **AG-081b** (2026-08-10 オーナー委任の一般規則)

## 背景・課題

### 何が問題か

キューに積んだ仕事の中には、他の仕事と順番がぶつかったときにいったんキューへ戻して
後でやり直す動き (退避 = `release`) を**正常な流れ**として持つものがある。
Laravel はこの「戻す」動作でも試行回数 (`attempts`) を 1 つ消費する。
したがって終端条件を試行回数で持つと、**一度も失敗していない仕事が回数を使い切って落ちる**。

標準形 v1 はこれを 2 本立てで解く。

1. 打ち切りを**絶対時刻の期限** (`retryUntil()`) で持ち、試行回数を終端条件にしない
2. 期限の基準時刻を**投入 (push) 時刻**に取る (親のまとまりの作成時刻にしない)
3. **未処理例外だけを別に数える** (`$maxExceptions`) ので、本当の失敗は期限を待たずに止まる
4. 採った終端方式が実際に効いていることを**検査で固定する**

### aicue の現在地 (実読で確認した事実。推測ではない)

| 観測項目 | 実測値 | 確認方法 |
|---|---|---|
| `app/Jobs/` 配下のジョブ | **11 本** (Billing 6 / Capture 2 / Manual 3) | `ls app/Jobs/**` |
| キューに載るクラスの総数 (母集団) | **20 件** (ジョブ 11 / Mailable 2 / Notification 7) | `implements ShouldQueue` の実読 |
| キューへの退避 (`$this->release(` / `$this->job->release(`) | **0 件** | `app/` 全体の grep |
| 退避する job middleware (`WithoutOverlapping` / `RateLimited` / `ThrottlesExceptions` 系) | **0 件** | `app/` 全体の grep |
| `retryUntil` / `$maxExceptions` の参照 | **0 件** | `app/` `tests/` の grep |
| 正典資産 4 本の存在 | **0 件** | `tests/` の実読 |

`app/Services/**` に現れる `->release(` 20 件前後はすべて**チケット予約の解放**
(`TicketLedgerService::release()`) であって、キューへの退避ではない。
`app/Jobs/Billing/AutoRechargeTriggerJob.php` などの docblock は
「再実行は退避ではなくリコンサイルの再トリガーに寄せる」と明記している。

> 台帳の `aicue` セルは観測点 `aicue@29141ac` (2026-08-12) で「`app/Jobs/` は 10 本」と
> 書いているが、その後 `GenerateTakeThumbnailJob` が加わって**現在は 11 本**である。
> 結論 (キューへの退避 0 件) は変わらない。

### なぜ「適用対象 0 件」でも作るのか

裁定 **AG-081b** が一般規則を確定している。

> 「配る側」としての整備を裁定されたセルは、**機構とその自己検査が実在すれば implemented とする。
> 適用件数は保証の一部ではない。** ただし条件が 2 点ある —
> (a) 「適用対象を持たない」という申告を母集団との完全一致で deny-by-default に取り、
> その申告を**走査で毎回裏取り**すること (適用対象が生まれた瞬間に赤くなる)、
> (b) 検出器自身の負のコントロールと、依存しているフレームワークの前提を pin する
> 振る舞い検査を持つこと。

同裁定は `aicue` と `metamovics` を名指しして「同じ規則が当てはまる。適用対象が無いことは
追従しない理由にならない」と述べ、両セルの目標版を v1 と明示している。

つまり**今の 20 件が全部「退避しない」ことを機械で毎回裏取りする申告に変える**のが本件の中身であり、
将来 aicue が退避を正常系に持つジョブを書いた瞬間に CI が赤くなって標準形 v1 を要求する、
という状態を作ることが目的である。

### 追従しないままだと何が起きるか

`app/Jobs/Manual/RunManualAnalysis.php` と `RunManualRender.php` は LLM 呼び出しと
ffmpeg 合成という長時間処理で、専用のキュー接続 (`retry_after` 1680 秒) を持つ。
ここに順番待ちの排他 (`WithoutOverlapping`) を足したくなる日は近い。
そのとき現在の aicue には「退避が試行回数を食う」ことを知らせる仕組みが 1 つも無く、
**撮影済みのテイクを合成する処理が、失敗していないのに黙って打ち切られる**形を
素通しで書けてしまう。使命 (「思考ゼロ・編集ゼロ」で標準化された動画マニュアルを作る) の
最終工程が無音で欠落する経路であり、後から探すのが難しい種類の欠陥である。

とくに `WithoutOverlapping` は**戻すまでの秒数を書かなくても既定でキューへ戻す**。
`spirux` は「秒数の明示を条件にする字句の検出器はこの 1 本を取り逃がす」という
申し送りを台帳へ残している (差分巡回 2026-08-16)。正典の検出器はこの取り逃がしをしない
(生成式そのものをマーカーにし、引数を条件にしない) ので、移植することで
同じ落とし穴を先に塞げる。

## 改善アイデア

`rio-development/laravel-claude-template` (配る側として整備済み。
観測点 `laravel-claude-template@d18a46d` / 作業項目 `laravel-claude-template:T075`) の
正典資産を **byte 一致移植を第一候補**として取り込み、
aicue 固有の事情でどうしても合わない箇所だけを**必要最小限で適合**させる。

### 取り込む資産

`gh api repos/rio-development/laravel-claude-template/contents/...` で取得済み
(取得済みであることを本設計の前提事実として確認した。入手不能ではない)。

| 区分 | ファイル | 大きさ |
|---|---|---|
| 契約表 | `tests/Support/Queue/JobDeferralContract.php` | 3,284 byte |
| 検出器 | `tests/Support/Queue/JobDeferralScanner.php` | 54,291 byte |
| 配布雛形 | `tests/Support/Queue/DeferringJobTemplate.php` | 3,767 byte |
| 静的 gate | `tests/Architecture/JobDeferralTerminationGateTest.php` | 28,593 byte (16 ケース) |
| 振る舞い検査 | `tests/Feature/Queue/DeferredRetryHorizonTest.php` | 13,272 byte (5 ケース) |
| 検出器の負のコントロール | `tests/Support/Queue/DeferralProbe*.php` 23 本 + `Deferring*ProbeJob.php` 3 本 | 計 15,762 byte |

**作業項目の指示は正典資産を 4 本と書いているが、実読の結果 6 区分 29 ファイルである。**
差の理由は 2 つで、どちらも AG-081b の到達条件そのものに直結する。

- **振る舞い検査 `DeferredRetryHorizonTest.php`**: AG-081b の条件 (b) の後半
  「依存しているフレームワークの前提を pin する振る舞い検査を持つこと」を満たすのは
  この 1 本だけである。裁定文も laravel-claude-template の到達根拠として名指ししている。
  外すと 4 本を入れても implemented の条件を満たさない。
- **probe / fixture 26 本**: 静的 gate の E11-E16 (検出器が「常に緑を返す装置」でないことの
  毎回証明 = 条件 (b) の前半) が、これらを `use` して直接参照している。
  無いと gate はそもそも読み込めない。

### 移植の方針 (2 段構え)

**第一候補 = byte 一致**。29 ファイル中 **27 ファイルを 1 byte も変えずに置く**。
契約表が pin している vendor の前提 (`laravel/framework` v13.18.0 / 退避 middleware 5 種 /
非退避 middleware 3 種) は aicue の `composer.lock` と**完全に同じ版**であることを実読で確認した。
namespace も `Tests\Support\Queue` で aicue の既存ディレクトリと一致し、
既存 5 ファイル (`JobQueueingTransactionRecords` / `QueueDispatchDeferralInventory` /
`RecordsJobQueueingTransactionLevel` / `TriesOnceProbeJob` / `TriesThriceProbeJob`) と
**名前が 1 件も衝突しない**。

**第二候補 = 必要最小限の適合**。次の 3 点だけを変える。

1. **母集団と目録の置き場所** (静的 gate。実質的な唯一の設計判断)
   正典は母集団 `jobDeferralTerminationPopulation()` と目録 `jobDeferralTerminationInventory()` を
   `tests/Pest.php` に置く。aicue はこれを**静的 gate のファイル内**に置き、
   母集団は既存の `Tests\Support\QueuedJobPopulation::shouldQueueClasses()` から取る。
   理由は下の「制約・前提」で述べる。
2. **配布雛形の docblock 2 箇所** (`DeferringJobTemplate.php`)
   目録の所在を指すポインタ (`tests/Pest.php` → 静的 gate のファイル) と、
   「本リポジトリに回収機構は無い」という一文である。**aicue には回収機構がある**
   (`work:recover-stuck`。ドメイン規約 14 / 裁定 AG-083 標準形 v1) ので、
   このまま配ると事実に反する案内になる。
3. **振る舞い検査の docblock 2 箇所** (`DeferredRetryHorizonTest.php`)
   「既存の `tests/Feature/Queue/QueuedJobLeaseGuardTest.php` と同じ形」という参照が
   aicue には存在しないファイルを指す。aicue の同型の先例は
   `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` である。

いずれも**実行される行は 1 行も変えない** (2. と 3. は docblock のみ)。

## 期待効果

### 使命への貢献

- **長時間処理の無音打ち切りを構造的に防ぐ**。SOP → シナリオ → 撮影 → 合成のうち
  最も長く走る 2 段 (AI 解析 / レンダ) に順番待ちの排他を足す日が来たとき、
  試行回数で終端する形を CI が拒否する。「編集ゼロ」の最終成果物が
  失敗していないのに欠落する経路を先に塞ぐ。
- **家系の追従待ちを 1 件消す**。台帳の 6 セル中 4 セルが implemented で、
  残る追従待ちは `aicue` と `metamovics` の 2 つである。

### 具体的な改善見込み

- キューに載る 20 クラス全数について「退避を持たない」という申告が
  **毎回走査で裏取り**される (deny-by-default。allowlist の口を持たない)。
  ジョブを 1 本足して申告を忘れたら E1 が赤くなり、
  申告したのに退避を書いたら E4 が赤くなる。
- 検出器が壊れて常に緑を返す状態 (0 件で素通りする状態) を、
  母集団の非空検査 (E2) と検出器の正例・負例 13 形 (E11-E16) が毎回否定する。
- 「期限は投入時に焼き込まれ、期限がある間ワーカーは試行回数を参照しない」という
  Laravel の前提そのものを実キューで pin する (B0-B4)。
  フレームワーク更新でここが変わったら赤くなる。

## 実装方針 (概要)

### `app/` は 1 行も変えない

退避が 0 件なので**適用対象が無い**。適用対象の無いジョブに `retryUntil()` を足すのは
思考原則 2 (今必要なものだけ作る) に反するうえ、標準形 v1 自身が
「退避を正常系に持たないジョブ (失敗が本当の失敗であるジョブ) には適用しない」と
適用条件を明文化している。laravel-claude-template も aigenba も
「`app/` は 1 行も変更していない」と実装記録に明記している。

### 実行時ガードを作らない

正典の概念設計 (`laravel-claude-template:R5`) で棄却済みである。
本件は**検査だけ**を足す。

### 変更の全体像

| 区分 | 対象 | 件数 |
|---|---|---|
| 新規 (byte 一致) | `tests/Support/Queue/` の検出器・契約表・probe 26 本 | 28 |
| 新規 (docblock 2 箇所のみ適合) | `tests/Support/Queue/DeferringJobTemplate.php` | 1 |
| 新規 (目録 2 関数を自前で持つ) | `tests/Architecture/JobDeferralTerminationGateTest.php` | 1 |
| 新規 (docblock 2 箇所のみ適合) | `tests/Feature/Queue/DeferredRetryHorizonTest.php` | 1 |
| 変更 | `AGENTS.md` (ドメイン固有規約 17 を追加) | 1 |
| 変更 | `docs/architecture.md` (保証しないものの正本を追加) | 1 |
| 変更 | `docs/template-divergence.md` (`aicue:D25` を登録) | 1 |
| 変更 | `docs/TODO.md` → `docs/TODO-closed.md` (`aicue:T215` のクローズ) | 2 |
| 削除 | なし | 0 |

## 制約・前提

### 母集団を `QueuedJobPopulation` から取る理由 (`aicue:D25` として登録する逸脱)

aicue は「キューに載るクラスの母集団」を決める実装を
`tests/Support/QueuedJobPopulation.php` **ただ 1 本**に集約している。
同クラスの docblock は集約の目的をこう書いている —
「`QueuedJobLeaseInventoryTest` と `JobExecutionDedupInventoryTest` が同じ母集団を見ることを
構造的に保証する (2 実装に分かれていると、片方だけ更新される drift が起きる)」。

正典の `tests/Pest.php` 版をそのまま持ち込むと、母集団を数える実装が
**aicue の中に 2 本**できる。それは aicue が既に潰した drift をわざわざ復活させる変更であり、
思考原則 4 (別物の概念を似ているからで統合しない) の裏返しとして、
**同じ概念を 2 つの実装で持たない**という既存の判断に反する。

正典が `tests/Pest.php` を選んだ理由は「`composer test --parallel` では Architecture テストが
別プロセスへ振り分けられうるため、他のテストファイルで定義した関数を参照すると
未定義関数で落ちる」ことである。**この理由は同一ファイル内の定義には掛からない**。
aicue には既に先例があり、`JobExecutionDedupInventoryTest.php` は
`jobDedupGuarantees()` / `jobDedupExemptions()` を自ファイル内で定義して
`--parallel` 下で緑になっている。

守り続ける不変条件は同じである — 「母集団と目録の完全一致を deny-by-default で取り、
NO_DEFERRAL の申告を走査で毎回裏取りする」。保証機構も同じ (E1-E4)。
違うのは関数の置き場所と母集団の供給元だけなので、
`docs/template-divergence.md` へ `aicue:D25` として登録する
(登録の原則「迷ったら登録する」に従う)。

### 既存の検査群との整合 (実読で確認済み)

- **PHPStan は `tests/` を解析しない** (`phpstan.neon` の `paths` は `app` `config` `database` `routes`)。
  移植する 29 ファイルは level 10 の対象外である。誇張せず、そう書く。
- **`StrictTypesDeclarationGateTest`**: 29 ファイル全部が `declare(strict_types=1)` を持つ (確認済み)。
- **`ForbiddenStatementTokenInvariantTest`**: 禁止 4 語彙 (`echo` / `goto` / `global` / 開始タグ付き出力) は
  29 ファイルに 1 件も無い (確認済み)。`tests` は既に分類済みの置き場所なので新しい分類は要らない。
- **`NoNonCompoundGlobalUseTest`**: 非複合 `use` はすべて `namespace Tests\Support\Queue;` の中にあり、
  グローバル名前空間のファイル (静的 gate / 振る舞い検査) の `use` はすべて複合名である (確認済み)。
- **`PcreUnicodeModifierGateTest`**: `/u` を要求するのは `\R` を含むパターンだけ。
  検出器が持つ唯一の `preg_match` は `\R` を含まない (確認済み)。
- **`CarbonOverflowArithmeticGateTest`**: 移植物が使う加算は `addMinutes` / `addHours` だけで、
  溢れる月・年の加算は 1 件も無い (確認済み)。
- **`QueueWorkerLeaseInvariantTest` 規則 1** (追跡下からワーカー起動定義を検出する):
  振る舞い検査は `Worker` を container から解決して `runNextJob()` を直接呼ぶ形なので
  起動コマンドの文字列を持たない (確認済み)。
- **`QueuedJobLeaseInventoryTest` / `JobExecutionDedupInventoryTest` / `QueueDispatchAtomicityInventoryTest`**:
  いずれも母集団は `app/` 走査なので、`tests/Support/Queue/` に増える probe は入らない。
  既存の `TriesOnceProbeJob` / `TriesThriceProbeJob` が同じ場所で緑を保っている先例がある。
- **テスト環境**: `phpunit.xml` は `QUEUE_CONNECTION=sync` / `CACHE_STORE=array` を強制する。
  振る舞い検査は接続を `database` と明示して push し、`cache.default === 'array'` を
  冒頭で pin する形なので、どちらの前提とも整合する。`jobs` 表の migration も実在する。
- **`config('queue.connections.database.retry_after')`** は 360 (int)。
  静的 gate の E13 が config 解決の正例に使う値で、int かつ 1 以上という条件を満たす。

### 移植後に確定する数値

- 母集団 = 20 件、目録 = 20 件、`DEFERS` = **0 件**、`NO_DEFERRAL` = 20 件。
- **gate が適用対象 0 件で green になることを受け入れ条件に含める** (裁定 AG-081b の趣旨そのもの)。
  ただし「0 件だから何も見ていない」わけではないことを E2 / E10 / E11-E16 が毎回示す。

## スコープ外

- **`app/` への標準形の適用**。退避が 0 件なので適用対象が無い。
- **退避したジョブの回収機構の新設**。aicue には既に `work:recover-stuck` がある
  (ドメイン規約 14)。本件はそこへ系列を足さない
  (足すべき系列が無い = 退避するジョブが無いため)。
- **`retry_after` / ワーカー制限時間の整合**。別 feature (`queue-lease-timeout-consistency`) の領分で、
  aicue では `QueuedJobLeaseInventoryTest` / `QueueWorkerLeaseInvariantTest` が既に担っている。
- **重複実行の防止そのもの**。別 feature (`job-execution-deduplication`) の領分で、
  aicue では `JobExecutionDedupInventoryTest` が既に担っている (ドメイン規約 6)。
- **家系の完成形の走査 5 種のうち未移植分**。正典の検出器は「サービス委譲 / 動的呼び出し /
  自作 job middleware / factory 経由 / 投入サイトでの後付け」を**原理的に検出できない**と
  自ら宣言している。本件はその限界ごと移植する (限界を勝手に埋めようとしない)。
- **台帳 (lctl) への書き戻し**。実装・マージ・push が終わった後の別作業である。
