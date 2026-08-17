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


---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + laravel/framework v13.18.0 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (ただし paths は app/config/database/routes のみ。tests は対象外)
- Pest テストフレームワーク (RefreshDatabase グローバル適用 + --parallel)
- DTO + JsonResource パターン
- Laratrust RBAC (Organization → Team → Project 階層)

【本件固有の背景】
- 他リポジトリ (rio-development/laravel-claude-template) が「配る側」として整備した検査資産 29 ファイルを byte 一致で移植する追従作業。家系の機能台帳 lctl の裁定 AG-081 / AG-081b が根拠。
- 適用対象 (退避 release を正常系に持つジョブ) は aicue に 0 件。それでも移植することが裁定の趣旨 (「機構 + 自己検査の実在が条件。適用件数は保証の一部ではない」)。
- app/ は 1 行も変更しない。フロントの変更も 0 行。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（テストファースト計画は「先に赤にする」順序として妥当か。変異検査の網羅性）
5. 受け入れ条件が本当に機械検証可能か
6. 「保証しないもの」の記述が誇張していないか / 逆に漏れていないか
7. 副作用・後退リスク（既存の Architecture テスト群との干渉）
8. 波及変更の網羅性（変更ファイル一覧に漏れがないか）
9. セキュリティ（本件は検査の追加だが、検査が fail-open になる経路が無いか）
10. スコープ判断（byte 一致移植と必要最小限の適合の切り分けが妥当か。適合 3 箇所は本当に必要最小限か）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 概念設計 (参考)

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

> **互換性の主張の範囲**: 本設計が主張するのは「**aicue の現行 `composer.lock`
> (`laravel/framework` v13.18.0) で動く**」ことだけである。Laravel の別の系列一般への
> 前方・後方互換は主張しない。契約表 (退避 middleware 5 種 / 加算メソッドの閉集合) と
> 振る舞い検査 (期限の焼き込み / 期限がある間の試行回数の無視) は
> **framework と Carbon の実装に対する pin** なので、更新したら赤くなるのが正しい振る舞いであり、
> そのとき棚卸しするのは PR レビューの義務である (契約表の docblock が正本)。
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
  したがって本件について `composer phpstan` が主張できるのは「**悪化していない**」ことだけであり、
  新規 29 ファイルの型の正しさを PHPStan が保証するわけではない
  (実質的な担保は移植元が level 10 を通していることと、gate 自身の正例・負例である)。
  `tests/` を PHPStan の対象に含める話は本件のスコープ外で、
  やるなら独立した作業項目として起こす (29 ファイルの移植と同時にやると、
  移植の byte 一致性と型検査の導入という別々の変更が 1 つの PR に混ざる)。
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

### 母集団に Mailable と Notification が入ることの意味

feature の名前は「ジョブ」だが、ここでの「ジョブ」は**キューの payload に載るもの全般**を指す。
Mailable も Notification も `Mail::queue()` / `Notification` の queued 経路で同じキューに載り、
同じ `attempts` の勘定を受けるので、退避の有無を問う対象としては同格である。
aicue はこの母集団を `QueuedJobPopulation::shouldQueueClasses()` (`ShouldQueue` 実装の全数) で
既に定義しており、本件はその既存の正本にそのまま乗る。

その帰結として、**メールや通知の実装が原因で本 gate が赤くなることがありうる**。
たとえば Mailable に `WithoutOverlapping` を付ければ E4 が赤くなる。
これは誤検出ではなく設計どおりの動作である (退避を持つならメールであっても標準形 v1 が要る) が、
「ジョブの話なのになぜメールで落ちるのか」と読まれると調査が遠回りになるため、
**静的 gate の冒頭コメントと `aicue:D25` の本文に、ここでの「ジョブ」が
キューに載るもの全般を指すことと、母集団を既存の正本に合わせたことを明記する**。

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

---

## 詳細設計書

# 詳細設計: 退避 (release) を正常系に持つジョブの再試行終端 — 正典資産の移植

- 作業項目: `aicue:T215` (登録予定)
- 概念設計: `devnotes/20260817-1309-todo-t215-job-deferral-gate-port/conceptual-design.md`
- 台帳 feature: `job-deferral-termination` (area: async / canonical_version: v1)
- 移植元: `rio-development/laravel-claude-template` の `main`
  (作業項目 `laravel-claude-template:T075` / 観測点 `laravel-claude-template@d18a46d`)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書 (SOP) を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ (PWA) でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合 (OJT を撮って形式化する tebiki) と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置 (SECI)。

v1 スコープ: 字幕のみ (TTS 後回し) / 撮影は PWA (同一オリジン・セッション認証) /
動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 (AGENTS.md の正本から転記)

1. テストなしの実装完了報告 (不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen (型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作 (`migrate:fresh` 等) をエージェント判断で実行すること
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び (`app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ)
6. prompt 文字列のコード直書き (`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

**本件は `app/` を 1 行も変更せず、テストと文書だけを足す。**
禁止事項 4・5・6・7・8 は構造的に触れない。1 は本設計の中心そのもの。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。ただし `phpstan.neon` の `paths` は
  `app` `config` `database` `routes` であり **`tests` を含まない**。
  本件の新規 29 ファイルは解析対象外なので、`composer phpstan` について主張できるのは
  **「悪化していない」ことだけ**である (誇張しない)。
- **Pest** (`composer test`)。`RefreshDatabase` は `tests/Pest.php` でグローバル適用済みで、
  個別の `DatabaseTransactions` は使わない。`--parallel` で実行される。
- `declare(strict_types=1)` + 日本語コメント。
- 整形は `vendor/bin/pint --test` / 修正は `composer fix`。
- PHP 8.4 + Laravel 12 系 (`laravel/framework` v13.18.0) + Svelte 5 + Inertia.js + TypeScript。
  **本件にフロントの変更は 1 行も無い。**

## 目的と台帳の根拠

### 目的

キューに載る 20 クラス全数について「順番待ちのためにキューへ戻す (退避) を持たない」という
申告を置き、その申告を**毎回の走査で裏取りする** deny-by-default の検査を作る。
将来 aicue が退避を正常系に持つジョブを書いた瞬間に CI が赤くなり、
標準形 v1 (絶対時刻の期限 + 未処理例外の分離計数) を要求する状態にする。

### 台帳の根拠 (裁定番号)

- **AG-081** (2026-08-06): 3 案の比較のうえ統合形 v1 を標準形として確定した。
  必須 4 点は (1) 絶対時刻の期限で終端し試行回数を終端条件にしない、
  (2) 期限の基準時刻を投入時刻に取る、(3) 未処理例外だけを別に数える、
  (4) 採った終端方式を検査で固定する。
- **AG-081b** (2026-08-10、オーナー委任): 「配る側」としての整備を裁定されたセルは
  **機構とその自己検査が実在すれば implemented とする。適用件数は保証の一部ではない。**
  条件は 2 点 — (a) 「適用対象を持たない」という申告を母集団との完全一致で
  deny-by-default に取り走査で毎回裏取りすること、(b) 検出器自身の負のコントロールと、
  依存フレームワークの前提を pin する振る舞い検査を持つこと。
  同裁定は `aicue` と `metamovics` を名指しし「適用対象が無いことは追従しない理由にならない」
  「両セルの目標版を v1 として明示する」と述べている。

### 家系の実装状況 (台帳 `get_feature('job-deferral-termination')` の実読)

| リポジトリ | 状態 | 形 |
|---|---|---|
| `laravel-claude-template` | implemented (v1) | 配る側として整備。適用対象 0 件。`app/` 変更 0 行 |
| `aigenba` | implemented (v1) | 適用対象 1 ジョブ。`app/` 変更 0 行 (保証の層だけが不足していた) |
| `motivation` | implemented (v1) | 原初。配信ジョブ 1 本へ適用 |
| `spirux` | implemented (v1) | 案 2 から統合形へ移行。走査は目録型 (完成形の走査 5 種は未移植) |
| **`aicue`** | **pending** (target_version: v1) | **本件** |
| `metamovics` | pending | テンプレート取り込み待ち |

### `spirux` の申し送りへの回答

`spirux` は差分巡回 2026-08-16 で
「重複防止の仕組み (`WithoutOverlapping`) は**戻すまでの秒数を書かなくても既定でキューへ戻す**ので、
秒数の明示を条件にする字句の検出器は同じ取り逃がしをする」と家系全体へ申し送っている。

移植する検出器は**この取り逃がしをしない**。`JobDeferralScanner::deferralMarkersIn()` の
`middleware-new` マーカーは**生成式そのもの**(`new WithoutOverlapping(...)` /
FQCN 直書き / alias import / 変数への代入) を検出し、引数の有無を条件にしない。
`dontRelease()` が生成式に直結している形だけを非退避と判定する (それ以外は保守的に退避側へ倒す)。
本設計はこの性質を**受け入れ条件 AC-8 の変異検査**で実際に確かめる。

## 概念設計リファレンス

`devnotes/20260817-1309-todo-t215-job-deferral-gate-port/conceptual-design.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|---|---|---|
| 1 | 検出器・契約表・probe の byte 一致移植 | `tests/Support/Queue/` に新規 28 本 | 最高 |
| 2 | 配布雛形の移植 (docblock 2 箇所のみ適合) | `tests/Support/Queue/DeferringJobTemplate.php` | 最高 |
| 3 | 静的 gate の移植と目録 2 関数の実装 | `tests/Architecture/JobDeferralTerminationGateTest.php` | 最高 |
| 4 | 振る舞い検査の移植 (docblock 2 箇所のみ適合) | `tests/Feature/Queue/DeferredRetryHorizonTest.php` | 最高 |
| 5 | 逸脱の登録 | `docs/template-divergence.md` (`aicue:D25`) | 高 |
| 6 | 規約と保証しないものの明文化 | `AGENTS.md` / `docs/architecture.md` | 高 |
| 7 | 移植の byte 一致を確かめる一時スクリプト | `devnotes/.../verify-byte-parity.sh` | 中 |

## 変更ファイル一覧

### 新規 (31)

**`tests/Support/Queue/` — byte 一致 (27 本)**

| # | ファイル | 役割 |
|---|---|---|
| 1 | `JobDeferralContract.php` | 契約表 (退避 middleware 5 / 非退避 3 / container 解決子 3 / 時計起点 4 / 加算メソッド 8 / mode 値域 2) |
| 2 | `JobDeferralScanner.php` | 検出器 (走査根の推移閉包 / マーカー 5 kind / C1-C4 の純関数) |
| 3 | `DeferralProbeAliasedHorizon.php` | C4 正例 (alias 解決) |
| 4 | `DeferralProbeInheritedHorizon.php` | C4 正例 (継承) |
| 5 | `DeferralProbeInheritedHorizonBase.php` | 上記の基底 (定数の供給元) |
| 6 | `DeferralProbeInheritedHorizonZero.php` | C4 負例 (`static::` が 0 分) |
| 7 | `DeferralProbeInheritedTries.php` | C2 負例 (継承した `$tries`) |
| 8 | `DeferralProbeInnerReleasingTrait.php` | 走査根の推移閉包の内側 trait |
| 9 | `DeferralProbeInteractsOnly.php` | 負例 (vendor trait を走査根に含めて 0 件) |
| 10 | `DeferralProbeMissingContract.php` | C1 / C3 負例 |
| 11 | `DeferralProbeNestedTraitJob.php` | 推移閉包の正例 |
| 12 | `DeferralProbeNullableHorizon.php` | C1 負例 (戻り型が nullable) |
| 13 | `DeferralProbeOuterTrait.php` | 推移閉包の外側 trait |
| 14 | `DeferralProbePropertyHorizon.php` | C1 負例 (メソッドでなくプロパティ) |
| 15 | `DeferralProbeShadowedHorizon.php` | C4 正例 (1 ファイル 2 クラスの行範囲切り出し) |
| 16 | `DeferralProbeTimestampHorizon.php` | C1 負例 (戻り型が int) |
| 17 | `DeferralProbeTriesAttributeTrait.php` | C2 負例の材料 |
| 18 | `DeferralProbeTriesBase.php` | C2 負例の材料 (基底) |
| 19 | `DeferralProbeTriesMethod.php` | C2 負例 (`tries()`) |
| 20 | `DeferralProbeTriesOuterAttributeTrait.php` | C2 負例の材料 |
| 21 | `DeferralProbeTriesProperty.php` | C2 負例 (`$tries`) |
| 22 | `DeferralProbeTriesUninitialized.php` | C2 負例 (既定値なし typed property) |
| 23 | `DeferralProbeTriesViaNestedTrait.php` | C2 負例 (trait の trait の `#[Tries]`) |
| 24 | `DeferralProbeTriesViaTrait.php` | C2 負例 (trait 経由の `$tries`) |
| 25 | `DeferralProbeZeroMaxExceptions.php` | C3 負例 (`$maxExceptions = 0`) |
| 26 | `DeferringNoContractProbeJob.php` | B3 対照 (期限を持たず回数で終端する) |
| 27 | `DeferringReleaseProbeJob.php` | B0 / B2 / B3 (退避を正常系に持つ) |
| 28 | `DeferringThrowProbeJob.php` | B4 (未処理例外を投げる) |

**`tests/Support/Queue/` — docblock 2 箇所のみ適合 (1 本)**

| # | ファイル | 役割 |
|---|---|---|
| 29 | `DeferringJobTemplate.php` | 配布雛形 (`app/Jobs/` へコピーして使う標準形 v1 の見本) |

**検査 (2 本)**

| # | ファイル | 役割 |
|---|---|---|
| 30 | `tests/Architecture/JobDeferralTerminationGateTest.php` | 静的 gate 16 ケース + 目録 2 関数 |
| 31 | `tests/Feature/Queue/DeferredRetryHorizonTest.php` | 振る舞い検査 5 ケース (docblock 2 箇所のみ適合) |

**作業用 (1 本。恒久化しない)**

- `devnotes/20260817-1309-todo-t215-job-deferral-gate-port/verify-byte-parity.sh`
  移植 27 本 + 部分適合 3 本を移植元と突き合わせる。`scripts/` へは昇格させない
  (1 回きりの移植の検証であり、恒久的に回す性質が無い)。

### 変更 (5)

| ファイル | 変更内容 |
|---|---|
| `AGENTS.md` | ドメイン固有規約に **17.** を追加 (退避を正常系に持つジョブの終端方式) |
| `docs/architecture.md` | 「§退避を正常系に持つジョブの終端方式」を新設 (保証しないものの正本) |
| `docs/template-divergence.md` | `D25` を登録 + 冒頭の「登録エントリ: 23 件」を **24 件** へ |
| `docs/TODO.md` | `aicue:T215` の登録 (`app-todo-add` スキルが行う) → クローズ時に削除 |
| `docs/TODO-closed.md` | `aicue:T215` のクローズ行を追加 (`app-todo-close` スキルが行う) |

### 削除 (0)

無し。**後方互換の並走も作らない** (そもそも旧実装が存在しない)。

---

## 施策 1: 検出器・契約表・probe の byte 一致移植

### 変更箇所

`tests/Support/Queue/` に 28 ファイルを新規作成。取得は

```
gh api repos/rio-development/laravel-claude-template/contents/tests/Support/Queue/<name>.php \
  --jq '.content' | base64 -d > tests/Support/Queue/<name>.php
```

### 波及変更

- TypeScript 型定義: **なし**
- API Resource / DTO: **なし**
- テストファイル: 施策 3・4 が参照する (同一 PR 内で同時に入る)

### 前提の実読結果 (byte 一致で成立する根拠)

| 前提 | 移植元 | aicue | 一致 |
|---|---|---|---|
| `laravel/framework` | v13.18.0 | v13.18.0 (`composer.lock`) | ○ |
| `Illuminate\Queue\Attributes\Tries` / `MaxExceptions` | 使用 | `vendor/` に実在 | ○ |
| 退避 middleware 5 種 | `RateLimited` / `RateLimitedWithRedis` / `ThrottlesExceptions` / `ThrottlesExceptionsWithRedis` / `WithoutOverlapping` | 5 種とも `vendor/.../Queue/Middleware/` に実在 | ○ |
| 非退避 middleware 3 種 | `Skip` / `SkipIfBatchCancelled` / `FailOnException` | 3 種とも実在 | ○ |
| namespace | `Tests\Support\Queue` | 同ディレクトリが既に存在 | ○ |
| 名前の衝突 | — | 既存 5 本 (`JobQueueingTransactionRecords` / `QueueDispatchDeferralInventory` / `RecordsJobQueueingTransactionLevel` / `TriesOnceProbeJob` / `TriesThriceProbeJob`) と 1 件も衝突しない | ○ |
| 外部依存 | `DateTimeInterface` / `Illuminate\Queue\Attributes\*` / `Reflection*` / `Illuminate\Bus\Queueable` 等の vendor のみ | 同じ | ○ |

### 既存の検査群との整合 (実読で確認済み)

| 検査 | 判定 | 根拠 |
|---|---|---|
| `StrictTypesDeclarationGateTest` | 通る | 29 本すべてが `declare(strict_types=1)` を持つ |
| `ForbiddenStatementTokenInvariantTest` | 通る | 禁止 4 語彙が 0 件。`tests` は分類済みの置き場所 |
| `NoNonCompoundGlobalUseTest` | 通る | 非複合 `use` はすべて名前付き namespace の中 |
| `PcreUnicodeModifierGateTest` | 通る | `\R` を含むパターンが 0 件 (検出器の唯一の `preg_match` は `\R` を持たない) |
| `CarbonOverflowArithmeticGateTest` | 通る | 使う加算は `addMinutes` / `addHours` のみ |
| `QueueWorkerLeaseInvariantTest` 規則 1 | 通る | ワーカー起動コマンドの文字列を持たない |
| `QueuedJobLeaseInventoryTest` | 影響なし | 母集団は `app/` 走査 |
| `JobExecutionDedupInventoryTest` | 影響なし | 母集団は `QueuedJobPopulation::shouldQueueClasses()` = `app/` 走査 |
| `QueueDispatchAtomicityInventoryTest` | 影響なし | D3 / D5 の母集団も `app/` 走査 |
| `TemplateDivergenceLedgerFormatTest` | 施策 5 で対応 | 対象パスの実在・件数の 3 点一致を満たす |

`tests/Support/Queue/` に `ShouldQueue` 実装の probe を置くこと自体は
既存の `TriesOnceProbeJob` / `TriesThriceProbeJob` という先例があり、
どの母集団検出器も `app/` しか見ないので既存 gate には現れない。

### PHPStan 適合チェック

- [x] `tests/` は `phpstan.neon` の `paths` に含まれないので解析対象外
- [x] `@phpstan-ignore-line` / baseline は 1 件も足さない (禁止事項 2)
- [x] `composer phpstan` の結果は本件で変化しない (悪化なしの確認のみ)

### テスト計画

本施策はテストの材料そのものなので、単体の新規テストは持たない。
検出力の証明は施策 3 の E11-E16 と施策 4 の B0-B4 が担う。
byte 一致は施策 7 のスクリプトが機械で確かめる。

### リスク

- **`gh` の取得に失敗する / 移植元が変わっている**
  → 施策 7 のスクリプトが差分を出すので、気付かず古い版を入れることはない。
    取得できない場合は実装を進めず作業項目を止める (概念設計の前提が崩れるため)。
- **移植元が今後変わり、aicue の写しが古くなる**
  → 本件は「その時点の正典を移す」作業であり、追随の自動化は作らない
    (家系の同期はテンプレート取り込みの領分)。`aicue:D25` の「再判定の条件」に書く。

---

## 施策 2: 配布雛形の移植 (docblock 2 箇所のみ適合)

### 変更箇所

`tests/Support/Queue/DeferringJobTemplate.php` (新規。移植元 3,767 byte)

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 施策 3 の E10 が C1-C4 の非劣化を毎回検査する

### 現行コード (移植元。**実行される行はこのまま入れる**)

```php
final class DeferringJobTemplate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $maxExceptions = 3;

    private const HORIZON_MINUTES = 30;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::HORIZON_MINUTES);
    }

    public function handle(): void
    {
        // 雛形なので何もしない (配布物に「何もしない handle()」以上のものを入れない)。
    }
}
```

### 変更後 (docblock の 2 箇所だけ)

**適合 1 — 目録の所在**

```
  4. **退避を正常系に持たないジョブには適用しない** (失敗が本当の失敗であるジョブ)。
-    分類は `jobDeferralTerminationInventory()` (tests/Pest.php) へ全数申告する。
+    分類は `jobDeferralTerminationInventory()`
+    (tests/Architecture/JobDeferralTerminationGateTest.php) へ全数申告する。
```

理由: aicue は目録を静的 gate のファイル内に持つ (施策 3 / `aicue:D25`)。
所在が違うポインタを配ると、雛形を `app/Jobs/` へ写した人が申告先を探せない。

**適合 2 — 回収経路の有無 (事実の訂正)**

```
  5. **退避したジョブの回収経路を持たないまま本形を採らないこと** —
     終端しなかった仕事がどう回収されるかまで pin しないと、ジョブが黙って消える退行を
-    検出できない (本リポジトリに回収機構は無い。stuck-job-recovery の領分)。
+    検出できない。**本リポジトリには回収の入口が既にある** —
+    `work:recover-stuck --stream=<key>` ただ 1 本である (AGENTS.md ドメイン規約 14)。
+    退避を正常系に持つジョブを足すときは、そこへ系列を 1 つ足すかどうかを必ず判断すること
+    (`App\Enums\Recovery\RecoveryStream` の case / registry / 目録 / Schedule の 4 つを同時に更新する)。
```

理由: **移植元の記述が aicue では事実に反する**。aicue は裁定 AG-083 標準形 v1 に沿って
滞留回収の単一入口 `work:recover-stuck` を実装済みである (ドメイン規約 14)。
そのまま配ると「回収機構は無い」という誤った前提で判断させることになる。
逆に**この 1 文を直さないほうが危険**なので、byte 一致より事実の正しさを優先する。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`DateTimeInterface`)
- [x] null 安全 (null を返す経路が無い)
- [x] DTO を返している (該当なし。キュージョブの雛形)
- [x] Generics の型パラメータ (該当なし)
- [x] `tests/` は解析対象外

### テスト計画

- [x] E10 が `DeferringJobTemplate` に対して C1-C4 をすべて掛ける
  (雛形が劣化したら赤くなる)
- [x] B1 / B3 が実キューで雛形の期限の焼き込みを観測する

### リスク

- **docblock の適合が「移植元と違う」ことを後から誤って byte 一致へ戻される**
  → 施策 7 のスクリプトが「この 2 箇所だけが差分であること」を明示的に許容差分として
    列挙するので、戻すと逆にスクリプトが赤くなる。

---

## 施策 3: 静的 gate の移植と目録 2 関数の実装

### 変更箇所

`tests/Architecture/JobDeferralTerminationGateTest.php` (新規)。
16 ケース (E1-E16) は移植元のまま。**追加するのは目録 2 関数と冒頭コメント 1 段落だけ**。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: `tests/Support/Queue/` の 29 本を `use` する (施策 1・2 と同時)

### 現行コード (aicue の先例)

`tests/Architecture/JobExecutionDedupInventoryTest.php` は目録関数を**自ファイル内**で定義し、
母集団だけを共有クラスから取る。

```php
test('キューに載る全クラスが保証側 or 免除に分類されている (未分類は fail)', function (): void {
    $scanned = QueuedJobPopulation::shouldQueueClasses();
    $classified = array_merge(array_keys(jobDedupGuarantees()), array_keys(jobDedupExemptions()));
    ...
});
```

### 変更後コード (追加する 2 関数)

```php
use Tests\Support\QueuedJobPopulation;

/**
 * 退避終端 gate の母集団 = **キューに載るクラスの全数**。
 *
 * **母集団を数える実装を新しく作らない**。aicue は「キューに載るクラスの母集団」を
 * `Tests\Support\QueuedJobPopulation` ただ 1 本へ集約しており、`QueuedJobLeaseInventoryTest` と
 * `JobExecutionDedupInventoryTest` が同じ集合を見ることを構造で保証している。
 * ここで別の検出器を建てると、片方だけ更新される食い違いが復活する。
 * テンプレート正典との置き場所の差は docs/template-divergence.md D25 に登録済み。
 *
 * **ここでの「ジョブ」はキューの payload に載るもの全般**を指す (Mailable / Notification を含む)。
 * どれも同じキューに載り同じ試行回数の勘定を受けるので、退避の有無を問う対象として同格である。
 * 帰結として、メールや通知に退避する job middleware を付けると本 gate が赤くなる。
 * それは誤検出ではなく設計どおりの動作である。
 *
 * @return list<class-string>
 */
function jobDeferralTerminationPopulation(): array
{
    return QueuedJobPopulation::shouldQueueClasses();
}

/**
 * 退避 (release) の有無による全数申告台帳 (lctl 裁定 AG-081 標準形 v1)。
 *
 * **allowlist ではない**: 母集団との**完全一致**を要求するので、キューに載るクラスを足したら
 * 必ずここに追記しないと E1 が落ちる。first-party / vendor で分岐を持たない
 * (分岐は allowlist の口になる)。
 *
 * mode:
 *   NO_DEFERRAL … 退避が起きない (失敗は本当の失敗)。走査根に退避マーカーが 0 件であることを
 *                 E4 が毎回裏取りする (申告を信じない)
 *   DEFERS      … 退避が起きうる。C1-C5 を E5-E9 が課す
 *
 * @return list<array{class: class-string, mode: string, reason: string, coveredBy: list<string>}>
 */
function jobDeferralTerminationInventory(): array
{
    // …20 エントリ (下表のとおり。すべて mode = 'NO_DEFERRAL' / coveredBy = [])…
}
```

### 目録 20 エントリ (全数。すべて `NO_DEFERRAL` / `coveredBy` は空)

`reason` は**実装を読んで書く**。共通の型は
「順番待ちのためにキューへ戻す (release) 経路を持たない。退避する job middleware も付けていない。
失敗は本当の失敗であり回数で終端してよい」+ **クラス固有の 1 文**。

| # | クラス | 固有の 1 文 (要旨) |
|---|---|---|
| 1 | `App\Jobs\Manual\RunManualAnalysis` | `startJob` が悲観ロック + status guard で `running` へ遷移させ、取れなければ退避ではなく**その場で終了**する |
| 2 | `App\Jobs\Manual\RunManualRender` | 同上 (`RenderPipeline` が同型) |
| 3 | `App\Jobs\Manual\DeleteRenderOutputsJob` | 削除の冪等 no-op のみ。競合したら退避ではなく何もしない |
| 4 | `App\Jobs\Capture\GenerateTakeThumbnailJob` | 一回性は条件付き UPDATE が担い、0 行更新なら退避ではなく終了する |
| 5 | `App\Jobs\Capture\DeleteTakeObjectsJob` | payload は S3 キーの list のみ。存在しないキーの削除は no-op |
| 6 | `App\Jobs\Billing\AutoRechargeTriggerJob` | 起票を 1 つの tx で行うだけ。起票できない条件では退避ではなくその場で終了する |
| 7 | `App\Jobs\Billing\ExecuteAutoRechargeAttemptJob` | 所有権を条件付き UPDATE で取ってから決済事業者を呼ぶ。取れなければその場で終了 |
| 8 | `App\Jobs\Billing\HandleAutoRechargeChargeFailureJob` | 取り消し確定の要求と短い決着 tx のみ。状態が変わっていればその場で終了 |
| 9 | `App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob` | 収束する同期の upsert のみ。順番待ちの概念を持たない |
| 10 | `App\Jobs\Billing\SetDefaultPaymentMethodJob` | 同上 |
| 11 | `App\Jobs\Billing\SyncBillingCustomerDetails` | 組織名を決済事業者へ写す片方向同期のみ |
| 12 | `App\Mail\InquiryReceivedMail` | 送信は 1 通の呼び出しだけ |
| 13 | `App\Mail\InquiryAcknowledgementMail` | 同上 |
| 14 | `App\Notifications\Account\AccountDeletionRequestedNotification` | 通知の送信だけでドメイン状態を書かない |
| 15 | `App\Notifications\Billing\AutoRechargeActionRequiredNotification` | 同上 |
| 16 | `App\Notifications\Billing\AutoRechargeDisabledNotification` | 同上 |
| 17 | `App\Notifications\Billing\AutoRechargeEnabledNotification` | 同上 |
| 18 | `App\Notifications\Billing\AutoRechargeFailedNotification` | 同上 |
| 19 | `App\Notifications\Billing\PaymentFailedNotification` | 同上 |
| 20 | `App\Notifications\Billing\RenewalReminderNotification` | 同上 |

> `reason` を「同上」で埋めない。E3 が非空しか見ないので機械では止まらないが、
> **後から読む人が「なぜ退避しないのか」を 1 件ずつ判断できること**が目録の値である。
> 実装時は上の要旨を各クラスの実コードで裏取りしてから文にする。

### `appDispatchableVendorJobs()` を移植しない判断

移植元は母集団を `appShouldQueueClasses()` + `appDispatchableVendorJobs()` の 2 つで組むが、
後者は**移植元でも空配列を返す**拡張点である。aicue は
`QueuedJobPopulation::shouldQueueClasses()` 1 本にする。
帰結として、**`app/` の外 (vendor が登録するキュークラス) は母集団に入らない**。
これは移植元と実効的に同じ挙動であり、`docs/architecture.md` の
「保証しないもの」へ明記する。

### E9 (`coveredBy`) が空振りすることについて

`DEFERS` が 0 件なので E5-E9 は空ループになる。**これは裁定 AG-081b が想定した状態そのもの**で、
「0 件だから何も見ていない」わけではないことは E2 (母集団が 0 件でない) /
E10 (雛形に C1-C4 を掛ける) / E11-E16 (検出器の正例・負例 13 形) が毎回示す。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`list<class-string>` / `list<array{...}>`)
- [x] null 安全 (null を返す経路が無い)
- [x] 配列返却だが DTO 化しない — **テストの目録**であり、
      aicue の既存目録 (`jobDedupGuarantees()` 等) と同じ作法に揃える
- [x] `tests/` は解析対象外 (level 10 の対象にならない)

### テスト計画

本施策そのものがテストである。fail-first の手順は §テストファースト計画。

### リスク

- **`--parallel` で目録関数が未定義になる**
  → 同一ファイル内定義なので起きない。先例
    (`JobExecutionDedupInventoryTest` の `jobDedupGuarantees()`) が緑で回っている。
- **E4 が 20 クラス分の走査根 (vendor trait を含む) を毎回読むので遅い**
  → 走査根は「クラス自身 + 祖先 + trait の推移閉包」であり 1 クラスあたり数ファイル。
    移植元は同じ形で許容範囲に収まっている。実測が 10 秒を超えるなら
    **検出力を落とさずに**キャッシュを足す余地はあるが、先回りしては作らない (思考原則 2)。
- **`QueuedJobPopulation::shouldQueueClasses()` が `class_exists()` の副作用を伴う**
  → 既存の 2 gate が既に同じ副作用の上で緑になっている。本件で新しい副作用は生まれない。

---

## 施策 4: 振る舞い検査の移植 (docblock 2 箇所のみ適合)

### 変更箇所

`tests/Feature/Queue/DeferredRetryHorizonTest.php` (新規。5 ケース)

| ケース | 何を pin するか |
|---|---|
| B0 | 退避したジョブは削除されず `attempts` を進めて再投入される (B2 / B3 の前提) |
| B1 | push すると payload の `retryUntil` が **push 時刻 + 地平線**になる (時刻を進めれば期限も進む) |
| B2 | 退避を繰り返しても `retryUntil` が延びない / `uuid` が変わらない |
| B3 | 期限内なら `attempts` が `maxTries` を超えても失敗しない / 期限後は失敗する。**対照**として期限を持たないジョブが回数で終端すること |
| B4 | 未処理例外が `$maxExceptions` に達したところで期限より前に終端する |

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: `DeferringJobTemplate` / `DeferringReleaseProbeJob` /
  `DeferringNoContractProbeJob` / `DeferringThrowProbeJob` を `use` する

### 実行環境の前提 (実読で確認済み)

| 前提 | 実測 | 影響 |
|---|---|---|
| `phpunit.xml` の `QUEUE_CONNECTION` | `sync` | 検査は `QueueFacade::connection('database')` と**明示**するので問題ない |
| `phpunit.xml` の `CACHE_STORE` | `array` | B4 の `job-exceptions:{uuid}` カウンタに必要。検査は冒頭で `config('cache.default') === 'array'` を pin し、**`config()->set()` で書き換えない** (`CachePayloadPlainDataGateTest` に触れないため) |
| `jobs` 表の migration | `0001_01_01_000002_create_jobs_table.php` が実在 | ○ |
| `config('queue.connections.database.retry_after')` | 360 (int) | 施策 3 の E13 が config 解決の正例に使う |
| ワーカーの起動 | `app('queue.worker')` を解決して `runNextJob()` を直接呼ぶ | `QueueWorkerLeaseInvariantTest` 規則 1 に触れない |
| 失敗の観測 | `JobFailed` イベント (`failed_jobs` の行数は見ない) | `runNextJob()` 直呼びでは failer の listener が居ないため |

### 変更後 (docblock の 2 箇所だけ)

移植元は同型の先例として `tests/Feature/Queue/QueuedJobLeaseGuardTest.php` を 2 箇所で参照するが、
**aicue にそのファイルは無い**。aicue の同型の先例は
`tests/Feature/Queue/WorkerTimeoutTransitionTest.php` である
(ワーカーを container から解決して直接叩き、失敗を `JobFailed` イベントで数える形)。
参照先をこちらへ差し替える。

```
- * (既存 `tests/Feature/Queue/QueuedJobLeaseGuardTest.php` と同じ形)。
+ * (既存 `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` と同じ形)。
```

```
- * `tests/Feature/Queue/QueuedJobLeaseGuardTest.php` も同じ理由でイベントを数えている。
+ * `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` も同じ理由でイベントを数えている。
```

### PHPStan 適合チェック

- [x] `tests/` は解析対象外
- [x] `Webmozart\Assert\Assert` で null 安全を担保する形が移植元にある (そのまま入れる)
- [x] 個別の `DatabaseTransactions` を使っていない (`RefreshDatabase` のグローバル適用に乗る)

### テスト計画

本施策そのものがテストである。

### リスク

- **`RefreshDatabase` + `--parallel` で `jobs` 表が他レーンと干渉する**
  → テスト DB は worktree ごと・並列シャードごとに分離済み (`docs/worktree-isolation-strategy.md`)。
    既存の `WorkerTimeoutTransitionTest` が同じく `jobs` 表を実際に使って緑で回っている。
- **`Carbon::setTestNow()` の後始末漏れ**
  → 移植元が `afterEach` で `Carbon::setTestNow(null)` を戻す形を持つ。そのまま入れる。

---

## 施策 5: 逸脱の登録 (`aicue:D25`)

### 変更箇所

`docs/template-divergence.md`
(冒頭の「登録エントリ: 23 件」→「24 件」、末尾に `D25` を追加)

### 登録メタ表 (9 行ちょうど・この順序)

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/JobDeferralTerminationGateTest.php` |
| 業務要件起因の説明 | キューに載るクラスの母集団を決める実装を `tests/Support/QueuedJobPopulation.php` ただ 1 本へ集約する既存の判断があり、正典の形をそのまま持ち込むと母集団の実装が 2 本になって片方だけ更新される食い違いが復活する |
| 揃え続ける不変条件と保証機構 | 母集団と全数申告の完全一致を既定拒否で取り、退避を持たないという申告を毎回の走査で裏取りする。同ファイルの E1 から E4 が固定する |
| 再判定の条件 | 母集団の正本が `QueuedJobPopulation` から移ったとき / 並列実行で同一ファイル内の関数定義が解決されなくなったとき / 移植元が目録の置き場所を変えたとき |
| 決めた日 | 2026-08-17 |
| 決めた人 | 開発者 |
| 根拠 | T215 |
| 状態 | 恒久 |
| 見直し期限 | — |

> **書式の注意**: `docs/template-divergence.md` の「根拠」欄の値域は
> `T<n>` (3 桁以上のゼロ埋め) であり、`<repo>:` 修飾は書式に含まれない
> (`TemplateDivergenceLedgerFormatTest` が値域を機械で強制する)。
> 他リポジトリから参照するときに `aicue:D25` / `aicue:T215` と書く、という規律は
> 同ファイルの「記録の原則」に既に書かれている。

### 対比表

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 母集団と目録の置き場所 | `tests/Pest.php` の 2 関数 | 静的 gate のファイル内の 2 関数 |
| 母集団の供給元 | `appShouldQueueClasses()` + `appDispatchableVendorJobs()` (後者は空) | `Tests\Support\QueuedJobPopulation::shouldQueueClasses()` (既存の唯一の正本) |
| 守る不変条件 | 母集団と申告の完全一致 + 走査による裏取り | **同じ** |
| 保証機構 | E1-E4 | **同じ** |

### 本文に書くこと

- なぜ正当な差分か: 上記「業務要件起因の説明」の展開。
  正典が `tests/Pest.php` を選んだ理由 (並列実行で別プロセスへ振り分けられると
  他のテストファイルの関数を参照できない) は**同一ファイル内の定義には掛からない**こと。
  aicue に先例 (`JobExecutionDedupInventoryTest`) があること。
- ここでの「ジョブ」がキューに載るもの全般 (Mailable / Notification を含む) を指すこと。
- 保証しないもの: `app/` の外 (vendor が登録するキュークラス) は母集団に入らないこと。

### リスク

- **対象パスの重複**: 登録メタ表の規約は「全登録の和集合で重複しないこと」。
  `tests/Architecture/JobDeferralTerminationGateTest.php` は新規ファイルなので重複しない。
- **件数の 3 点一致**: 冒頭の「登録エントリ: N 件」を 24 へ直し忘れると
  `TemplateDivergenceLedgerFormatTest` が赤くなる (**それが正しい振る舞い**)。

---

## 施策 6: 規約と保証しないものの明文化

### 変更箇所 1: `AGENTS.md` のドメイン固有規約に **17.** を追加

現在 16 まで。17 として次を足す (要旨。実装時に既存 16 項の語調へ揃える)。

> 17. **退避を正常系に持つジョブの終端方式 (`aicue:T215` / 家系の裁定 AG-081・AG-081b 標準形 v1)**:
>     キューに載るクラス (`ShouldQueue` 実装の全数。Mailable / Notification を含む) は、
>     `tests/Architecture/JobDeferralTerminationGateTest.php` の全数申告へ
>     `NO_DEFERRAL` か `DEFERS` のどちらかで登録する (deny-by-default。allowlist の口は無い)。
>     - **`NO_DEFERRAL` の申告は信じない**。走査根 (クラス自身 + 祖先 + trait の推移閉包。
>       vendor を含む) に退避マーカーが 0 件であることを E4 が毎回裏取りする。
>     - **`DEFERS` にしたら標準形 v1 が要る** — 絶対時刻の期限 (`retryUntil()`) を持ち、
>       `$tries` / `#[Tries]` / `tries()` を**書かない** (期限があるとワーカーは試行回数を
>       一切参照しないため、書いても効かず誤読しか生まない)。未処理例外は
>       `$maxExceptions` (1 以上) で別に数える。期限の基準時刻は**投入時刻**である。
>       雛形は `tests/Support/Queue/DeferringJobTemplate.php` を `app/Jobs/` へ写して使う。
>     - **退避したジョブの回収まで考える**。回収の入口は `work:recover-stuck` ただ 1 本
>       (ドメイン規約 14)。系列を足すかどうかを必ず判断する。
>     - 契約表 (`tests/Support/Queue/JobDeferralContract.php`) の棚卸しは
>       **Laravel / Carbon を更新したときの PR レビューの義務**である (機械検出できない)。
>     - **保証範囲を誇張しない**: サービスへの委譲 / 動的呼び出し / 自作の job middleware /
>       factory 経由 / 投入サイトでの後付けは**検出できない**。
>       保証しないものの正本は `docs/architecture.md` §退避を正常系に持つジョブの終端方式。

### 変更箇所 2: `docs/architecture.md` に「§退避を正常系に持つジョブの終端方式」を新設

**保証しないものの完全な一覧の正本**をここに置く (AGENTS.md には要約だけを書き、
2 か所に増減を持たない)。書く内容は §保証しないもの (下記) と同じ。

### リスク

- **AGENTS.md の番号の重複**: 現在 16 まで。17 を使う。
  実装時に他の作業項目が同時に 17 を取っていないかを確認する。
- **`docs/architecture.md` の節の重複**: 「§ジョブの重複実行と結果の一回性」
  「§キュー投入の原子性」「§滞留回収の共通基盤」と**別の節**として立てる
  (同じ節に混ぜると、どの検査が何を保証するかが読めなくなる)。

---

## 施策 7: 移植の byte 一致を確かめる一時スクリプト

### 変更箇所

`devnotes/20260817-1309-todo-t215-job-deferral-gate-port/verify-byte-parity.sh` (新規)

### 何をするか

1. 移植元 30 ファイル (`tests/Support/Queue/` 29 + `tests/Architecture/...` + `tests/Feature/Queue/...`) を
   `gh api` で一時ディレクトリへ取得する。
2. **byte 一致を要求する 27 本**を `cmp` で突き合わせ、**1 件でも差分があれば非ゼロ終了**する。
3. **部分適合を許す 3 本**を `diff` で突き合わせ、
   **許容差分の行数と位置が設計どおりであること**を確かめる
   (`DeferringJobTemplate.php` = docblock 2 箇所 /
   `DeferredRetryHorizonTest.php` = docblock 2 箇所 /
   `JobDeferralTerminationGateTest.php` = 目録 2 関数 + 冒頭コメント 1 段落 + `use` 1 行)。
4. 想定外の差分は**行番号つきで出力**する。

### 恒久化しない理由

1 回きりの移植の検証であり、恒久的に回す性質が無い。
`scripts/` へ昇格させると `scripts/README.md` の台帳にも載り、
「移植元へ毎回アクセスする検査」が CI の暗黙の前提になる
(テストレーンの外部 HTTP 出口は既定拒否である。AGENTS.md §テストレーンの外部 HTTP 出口)。
一時スクリプトは devnotes に置く、という既存の運用に従う。

### リスク

- **`gh` の認証が無い環境で動かない** → 一時スクリプトなので CI では回さない。
  移植を行う人の手元でだけ実行する。

---

## テストファースト計画

「どのテストを先に赤にするか」を段階で決める。**赤を見てから緑にする** (思考原則 5)。

### 段階 0: 移植前に赤を作れることの確認

`tests/Architecture/JobDeferralTerminationGateTest.php` は存在しない = 検査が 0 件である。
まずこの不在そのものを出発点として記録する (現状 `composer test` は緑)。

### 段階 1: 母集団の裏取りを先に赤にする (E1)

1. 施策 1 (検出器・契約表・probe) を先に入れる。**この時点ではテストが 1 本も増えない**。
2. 施策 3 の gate を **`jobDeferralTerminationInventory()` が空配列を返す状態**で入れる。
3. `composer test -- --filter JobDeferralTerminationGate` を実行 →
   **E1 が赤**になり、未登録の 20 クラスが名前つきで列挙される。
   これが「母集団の検出が実際に効いている」ことの最初の証拠である。
4. 列挙された 20 件を目録へ書き写し、`reason` を実コードの実読で埋める → E1 が緑。

> ここで**目録を先に手で書かない**。空で赤にして gate に列挙させることで、
> 母集団の検出器が本当に 20 件を見つけていることを人手の写経と独立に確かめられる。

### 段階 2: 申告の裏取りを赤にする (E4)

1. `app/Jobs/Manual/RunManualAnalysis.php` へ**一時的に**
   `public function middleware(): array { return [new WithoutOverlapping('x')]; }` を足す。
2. `composer test -- --filter JobDeferralTerminationGate` → **E4 が赤**。
   `RunManualAnalysis <- app/Jobs/Manual/RunManualAnalysis.php:NN (middleware-new)` が出ること。
3. **引数を消した形 (`new WithoutOverlapping`) でも赤になること**を確かめる
   (`spirux` の申し送りへの直接の回答。秒数の明示を条件にしていない証拠)。
4. `dontRelease()` を生成式に直結した形 (`(new WithoutOverlapping('x'))->dontRelease()`) にすると
   **緑に戻る**ことを確かめる (fail-closed の境界が設計どおりであること)。
5. 一時変更を**すべて戻す** (`git checkout app/`)。

### 段階 3: 検出器の自己検査を入れる (E10-E16)

移植元のまま入れれば緑になる。ここは**赤を作る側ではなく、赤を作れることの証明**である。
入れた直後に次の変異を当てて、それぞれ赤になることを確かめてから戻す。

| 変異 | 期待 |
|---|---|
| `DeferringJobTemplate::retryUntil()` を `return now();` にする | E10 (C4) が赤 |
| `DeferringJobTemplate` に `public int $tries = 3;` を足す | E10 (C2) が赤 |
| `DeferringJobTemplate::$maxExceptions` を `0` にする | E10 (C3) が赤 |
| `JobDeferralContract::RELEASING_MIDDLEWARE` から `WithoutOverlapping` を消す | E11 (正例 2) が赤 |
| `JobDeferralScanner` の `dontRelease` 判定を常に true にする | E12 (対比 3c / 3d) が赤 |

### 段階 4: 振る舞いを赤にする (B0-B4)

1. 施策 4 の検査を入れる → 5 ケースが緑になるはずである。
2. **緑が偽物でないこと**を次の変異で確かめる。

| 変異 | 期待 |
|---|---|
| `DeferringReleaseProbeJob::retryUntil()` を消す | B3 の前半 (期限内なのに失敗しない) が赤 |
| `DeferringThrowProbeJob::$maxExceptions` を `99` にする | B4 が赤 (3 回目で終端しない) |
| `deferredHorizonRunWorker()` から `setCache()` を消す | B4 が赤 (`$maxExceptions` が無言で効かなくなる) |
| B1 の期待値を固定時刻にする | B1 の対照 (時刻を進めると期限も進む) が赤 |

3. 変異を**すべて戻す**。

### 段階 5: 文書と登録

施策 5・6 を入れ、`TemplateDivergenceLedgerFormatTest` が緑であることを確かめる
(件数の直し忘れで赤くなるのが正しい振る舞いなので、**わざと直さずに 1 度赤を見てから**直す)。

### 段階 6: 全数

§受け入れ条件の全検証コマンドを回す。

---

## 受け入れ条件 (機械検証可能な形で)

| # | 条件 | 検証方法 |
|---|---|---|
| AC-1 | 静的 gate の 16 ケースが緑 | `composer test -- --filter JobDeferralTerminationGate` が 16 passed |
| AC-2 | 振る舞い検査の 5 ケースが緑 | `composer test -- --filter DeferredRetryHorizon` が 5 passed |
| AC-3 | **母集団 = 目録 = 20 件、`DEFERS` = 0 件、`NO_DEFERRAL` = 20 件** | E1 が緑 + 目録のエントリ数が 20 + `mode` が全件 `NO_DEFERRAL` |
| AC-4 | **適用対象 0 件のまま gate が緑である** (裁定 AG-081b の受け入れ) | AC-1 と AC-3 の同時成立 |
| AC-5 | 母集団が 0 件でない (検出器の故障検出) | E2 が緑 |
| AC-6 | 20 件すべての走査根に退避マーカーが 0 件 | E4 が緑 |
| AC-7 | `app/` の差分が **0 行** | `git diff --stat main -- app/` が空 |
| AC-8 | 段階 2・3・4 の変異 13 通りがすべて期待どおり赤になり、戻すと緑に戻る | 変異ごとの実行ログを `devnotes/.../mutation-log.md` に残す |
| AC-9 | byte 一致 27 本の差分が 0 | `devnotes/.../verify-byte-parity.sh` が終了コード 0 |
| AC-10 | 部分適合 3 本の差分が設計どおりの箇所だけ | 同スクリプトが許容差分以外を検出しない |
| AC-11 | `docs/template-divergence.md` の件数 3 点一致 | `composer test -- --filter TemplateDivergenceLedgerFormat` が緑 |
| AC-12 | `AGENTS.md` ドメイン規約 17 と `docs/architecture.md` の新節が実在し、保証しないものが後者にだけ完全な形である | レビューで確認 (機械検査は持たない。2 か所に増減を持たないため) |
| AC-13 | **全検証コマンドが green** | 下記 |

### 全検証コマンド (すべて green であること)

```
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

- `composer phpstan` について主張できるのは **「悪化していない」ことだけ**である
  (`tests/` は `paths` に含まれない)。
- フロントの 6 コマンド (`pnpm *`) は**本件で 1 行も変更しないので変化しない**が、
  AGENTS.md の検証コマンド一覧が全数を要求しているので全部回す。
- `composer test` は**ホスト全体で 1 本ずつ**しか走らない (T099 のグローバルロック)。
  待ち時間が出るのは正常で、30 秒ごとの heartbeat が出ている間はハングではない。
  **kill しない / ロックファイルを消さない**。

---

## 保証しないもの (誇張しない)

`docs/architecture.md` §退避を正常系に持つジョブの終端方式 を**正本**とし、
`AGENTS.md` には要約だけを書く (2 か所に増減を持たない)。

### 検出器が原理的に見えないもの (レビューの義務として残る)

走査根は「目録エントリのクラス自身 + 祖先クラス + 使用 trait の推移閉包 (vendor を含む)」である。
次はどれも**検出できない**。

1. **サービスへ委譲した先**で退避相当が呼ばれる形 (メソッド境界を越える伝播)
2. **動的呼び出し** (可変メソッド名 / 可変クラス名 / `call_user_func` 系)
3. **自作の job middleware** (自前で書いた、退避を行う middleware)
4. **factory / helper が middleware インスタンスを返す形** (使用サイトに現れない)
5. **投入サイトでの後付け** (`(new Job)->through([new RateLimited(...)])`)。
   マーカーがジョブクラスではなく投入側のファイルに現れるため走査根の外になる
6. `eval` / 動的 `include` で生成されるコード

### 判定の粗さ (意図的に fail-closed へ倒している)

- `dontRelease()` は**生成式に直接連結された形だけ**を非退避と判定する。
  変数へ入れてから呼ぶ形・条件分岐は追跡せず、**保守的に退避ありへ倒す**。
  正当なコードを赤にしうるが、修正経路 (生成式に直結して書く) が常に存在するので受け入れる。
- C4 は「return 式が許可された時計起点から始まり、1 以上の加算だけで構成されること」までを見る。
  **地平線の長さが妥当かは人間の判断**である (1 秒でも通る)。

### 射程の外 (別の機構が持つ)

- **`NO_DEFERRAL` のまま運用で退避が起きること** (ワーカー側の再取得 / リース満了) は射程外。
  `queue-lease-timeout-consistency` (aicue では `QueuedJobLeaseInventoryTest` /
  `QueueWorkerLeaseInvariantTest`) と `stuck-job-recovery` (aicue では `work:recover-stuck`) の担当。
- **重複実行の防止そのもの**は `JobExecutionDedupInventoryTest` (ドメイン規約 6) の担当。
- **キュー投入の原子性**は `QueueDispatchAtomicityInventoryTest` (ドメイン規約 11) の担当。
- **`app/` の外 (vendor が登録するキュークラス)** は母集団に入らない。
  移植元の `appDispatchableVendorJobs()` も空配列であり、実効は同じである。

### 更新で無言に古くなるもの

- **契約表**は vendor の実読で作った閉集合である。
  `laravel/framework` を更新して退避する job middleware が増えても、
  **契約表は自動では増えない** (機械検出できない)。棚卸しは PR レビューの義務である。
  `Carbon` を更新して単位固定の加算メソッドが増えた場合も同じ。
- **振る舞い検査は framework の実装に対する pin** である。更新で赤くなるのが正しい振る舞いで、
  そのとき pin を緩めるのではなく前提の変化を読み直す。

### 型検査について

- `phpstan.neon` の `paths` は `app` `config` `database` `routes` であり `tests` を含まない。
  **新規 29 ファイルは PHPStan level 10 の対象外**である。
  「level 10 が通っている」を「新しいテストの型が保証されている」と読み替えない。

---

## やらないと決めたこと (理由付き)

| やらないこと | 理由 |
|---|---|
| `app/` の既存 11 ジョブへ `retryUntil()` / `$maxExceptions` を足す | 退避が 0 件で**適用対象が無い**。標準形 v1 自身が「退避を正常系に持たないジョブには適用しない」と適用条件を明文化している。足すと思考原則 2 (今必要なものだけ作る) に反する |
| 実行時ガード (アプリ起動時に退避を検出して落とす等) を作る | 正典の概念設計 `laravel-claude-template:R5` で棄却済み。検査だけで足りる |
| 目録と母集団を `tests/Pest.php` へ置く | 母集団を数える実装が aicue の中に 2 本できる。既に潰した食い違いを復活させる (`aicue:D25` として登録) |
| `appDispatchableVendorJobs()` の拡張点を移植する | 移植元でも空配列を返す。使わない抽象を配らない (思考原則 2)。実効の差は「保証しないもの」に明記する |
| 検出器の限界 (委譲 / 動的呼び出し / 自作 middleware など) を埋める | 正典自身が「原理的に塑げない」と宣言している範囲である。埋めようとすると偽陽性か複雑さが跳ねる。**限界ごと移植して明文化する**ほうが正確 |
| 滞留回収に系列を足す | 退避するジョブが 0 件なので足すべき系列が無い。存在しない対象のための機構を先回りして作らない |
| `tests/` を PHPStan の解析対象に含める | 別の変更である。移植の byte 一致性と型検査の導入を 1 つの PR に混ぜると、どちらのレビューも粗くなる。やるなら独立した作業項目 |
| 移植元への追随を自動化する | 家系の同期はテンプレート取り込みの領分。テストレーンの外部 HTTP 出口は既定拒否であり、CI から移植元を毎回引く前提を作らない |
| `verify-byte-parity.sh` を `scripts/` へ昇格させる | 1 回きりの移植の検証で、恒久的に回す性質が無い。一時スクリプトは devnotes に置く既存の運用に従う |
| 台帳 (lctl) へ本設計の時点で書き込む | 観測の記録はキュレーター巡回の専権。実装・マージ・push が終わってから `status_reported` を出す |

---

## 実装モード

| 項目 | 内容 |
|---|---|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1〜4 は 1 つの検査群として同時に入らないと**そもそも読み込めない** (gate が probe を `use` する)。施策 5・6 は同じ変更の中で登録する規約 (`docs/template-divergence.md` の「登録は逸脱を作る変更そのものに含める」)。分割すると中間状態で `composer test` が赤いコミットが必ず生まれる |
| 競合リスク | **低**。`app/` を 1 行も触らず、`tests/Support/Queue/` へ足す 29 本は既存 5 本と名前が衝突しない。ただし `AGENTS.md` のドメイン規約の**番号 17** と `docs/template-divergence.md` の**番号 D25 / 件数 24** は、同時期に走る他の作業項目と取り合いになる。worktree をマージする直前に両方を再確認する |
| worktree | `scripts/setup-worktree.sh T215` → `.claude/worktrees/tasks/T215` / ブランチ `todo/T215` |
