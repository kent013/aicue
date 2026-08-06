## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)

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
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の前提 — 蒸し返さないこと】
- 上位の c2c 台帳で「規則 1 (ワーカー timeout < retry_after、無条件) と 規則 2 (ジョブ $timeout < retry_after) は互いに独立で、両者の間に大小関係は課さない」ことが確定裁定である。3 項連鎖の提案は却下済み。
- 実行時 fail-fast の全ジョブ導入は標準形で却下済み (限定適用の補助)。
- スコープ外に挙げた c2c feature (budget-invariant-gates / job-execution-deduplication / job-deferral-termination / stuck-job-recovery / queue-dispatch-outbox) には触れない。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: キューのリース期間とワーカー制限時間の整合 (規則 1)

- c2c feature: `queue-lease-timeout-consistency` (revision `6-507568dc7e82`)
- 裁定: **AG-084** (開発用プロセス定義 4 ペインの制限なしを是正) / **AG-080** (標準形 v1 の不足 2 点)
- canonical_version: v1 / origin: spirux (実行時 fail-fast) + aicue (静的検査)

---

## 仮説

**このアプリのキュー設定は、規則 1 (「その接続で有効なワーカーの `timeout` が、その接続の
`retry_after` を下回る」) を 1 つも満たしていない。**しかも規則 1 が破れていることの実害は
「ジョブ側が `$timeout` を持っているから大丈夫」という**偶然**でしか防がれておらず、
`$timeout` を持たないジョブが載っている接続では**現に二重実行の窓が開いている**。

検証方法: `mprocs.yaml` / `scripts/bug-hunt-shard.sh` のワーカー起動定義と
`config/queue.php` の `retry_after`、および全 `ShouldQueue` クラスの `$timeout` 宣言を突き合わせる。

成功判定: (a) 全ワーカー起動定義で `timeout < retry_after` が成立し、(b) その関係が
人の注意力ではなく Architecture テストで固定され、(c) 新しく足された接続・ジョブ・ワーカー定義が
**黙って検査外に落ちない**こと。

---

## 現状 (実査結果。ブリーフ・台帳の記述は鵜呑みにせず実コードで裏を取った)

### `config/queue.php` の `retry_after` (driver=database の 4 接続)

| 接続 | queue | `retry_after` | 載るジョブ | ジョブ側 `$timeout` |
|---|---|---|---|---|
| `database` (既定) | `default` | **90** (`DB_QUEUE_RETRY_AFTER` 既定) | Billing 6 / Mail 2 / Notification 6 | **全て未宣言** |
| `database-analysis` | `analysis` | 1680 | `RunManualAnalysis` | 1560 |
| `database-render` | `render` | 1680 | `RunManualRender` | 1500 |
| `database-media` | `media` | **300** | `DeleteTakeObjectsJob` / `DeleteRenderOutputsJob` | **両方とも未宣言** |

### ワーカー起動定義 (規則 1 の対象)

**(A) `mprocs.yaml`** — `composer dev` (`npx mprocs`) が起動する開発用プロセス定義。

| ペイン | コマンド | 接続 | `--timeout` | 規則 1 |
|---|---|---|---|---|
| `queue` | `queue:listen --tries=1 --timeout=0` | **未指定 (既定接続 = env 依存)** | 0 (制限なし) | **違反** |
| `queue-analysis` | `queue:listen database-analysis … --timeout=0` | `database-analysis` | 0 | **違反** |
| `queue-render` | `queue:listen database-render … --timeout=0` | `database-render` | 0 | **違反** |
| `queue-media` | `queue:listen database-media … --timeout=0` | `database-media` | 0 | **違反** |
| `logs` | `php artisan pail --timeout=0` | — | 0 | **対象外** (後述) |

**(B) `scripts/bug-hunt-shard.sh`** — bug-hunt 環境 (直列 :8010 / 並列 shard :8011..8014) の
worker 起動 (`start_shard_workers`, L738-758)。**ブリーフはこの面を名指ししていないが、
同じ規則 1 の違反が実在した**。

```bash
BUGHUNT_WORKER_CONNECTIONS=(database-analysis database-render database-media)   # L710
setsid php artisan queue:listen "${conn}" --env=bughunt.local \
    --sleep=1 --tries=1 --timeout=1800                                          # L752-753
```

3 接続すべてに**同一リテラル `--timeout=1800`** を与えている。

- `database-analysis` (1680) → 1800 > 1680 → **違反**
- `database-render` (1680) → 1800 > 1680 → **違反**
- `database-media` (**300**) → 1800 > 300 → **違反 (6 倍)**

さらに L736 のコメントが「Job 側の `$timeout` (1,380/1,500)」と書いているが実値は **1,560/1,500**。
`RunManualAnalysis::$timeout` の変更がこの面へ伝わっていない = 手作業の同期が既にドリフトしている
実例である。

**(C) 本番/ステージングの supervisor 定義** — リポジトリ内に**存在しない**
(`docker/Dockerfile` にも `.github/` にも supervisor conf / `queue:work` の記述なし)。
`docs/architecture.md` が「worker プロセス定義・デプロイ手順・監視対象に
`php artisan queue:work database-{analysis,render,media}` を必須項目として登録する」と
**散文で運用側に要求している**だけ。静的検査の対象にできる実体がリポジトリに無い。

### 既存の規則 2 検査

`AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` が
`RunManualAnalysis` / `RunManualRender` / `DeleteRenderOutputsJob` を**クラス名決め打ち**で見ている。
`ShouldQueue` 実装は全 **18 クラス** (Jobs 10 / Mail 2 / Notification 6) あり、
**15 クラスが誰にも見られていない**。新しいジョブが `$timeout = 3600` を宣言して
`database` (retry_after 90) に載っても、CI は一言も言わない。

---

## 課題 (なぜこれが事故になるか)

DB driver のキューは、ジョブを取り出すとき `reserved_at` を打刻し、
`reserved_at < now - retry_after` の行を「担当が落ちた」とみなして**別のワーカーへ再配布**する。
**実行中にこのリース期間を延長する API は DB driver に存在しない**
(SQS の `ChangeMessageVisibility` に相当するものが無い)。したがって
「まだ走っている処理を落ちたと誤認させない」手段は**設定の大小関係を保つことだけ**である。

規則 1 が「**無条件**」なのは、1 つのワーカーが同じ接続の**複数種類**のジョブを処理するからである。
ある 1 本のジョブが `$timeout` を持っていても、同じ接続の**別のジョブ**が持っていなければ、
そのジョブはワーカー側の制限時間まで走り、`retry_after` を超えて二重取得される。

**この「偶然の防壁」が既に外れている面が 2 つある**:

1. **`database` 接続 (retry_after 90) × mprocs `queue` ペイン (制限なし)**
   載っているのは Billing 6 ジョブ・Mail・Notification で、**どれも `$timeout` を宣言していない**。
   Stripe API が遅延して 90 秒を超えれば二重取得される。うち
   `ExecuteAutoRechargeAttemptJob` は **`$tries = 1` の課金実行ジョブ**であり、
   spirux で 2026-07-25 に起きた事故 (二重取得 → 「やり直しは 1 回まで」に抵触 → 処理失敗) と
   **同じ構図**が成立する (Stripe 側の冪等キーで二重課金そのものは止まるが、
   attempt の状態機械は 2 本の実行で踏み荒らされる)。
2. **`database-media` 接続 (retry_after 300) × bug-hunt worker (`--timeout=1800`)**
   `DeleteTakeObjectsJob` / `DeleteRenderOutputsJob` は `$timeout` 未宣言。
   オブジェクトストレージが詰まって 300 秒を超えれば二重取得され、`$tries = 3` と合わさって
   同じ削除が最大 6 回走る。

`database-analysis` / `database-render` は今のところジョブ側 `$timeout` が先に効くため潜在的だが、
**それは規則 1 が禁じている「免除されると思い込むこと」そのもの**である。

---

## 方針

### 4 つの施策

| # | 施策 | 種別 | 対象 |
|---|---|---|---|
| 1 | `mprocs.yaml` 4 ペインの `--timeout` を接続別に是正 (AG-084) | 値の修正 | `mprocs.yaml` |
| 2 | `scripts/bug-hunt-shard.sh` の `--timeout=1800` を接続別に是正 | 値の修正 | `scripts/bug-hunt-shard.sh` |
| 3 | **規則 1** の静的検査 `QueueWorkerLeaseInvariantTest` を新設 | 目録型 gate | 1・2 の両面 |
| 4 | **規則 2** の配線網羅 `QueuedJobLeaseInventoryTest` を新設 | 目録型 gate | 全 `ShouldQueue` |

### 「制限時間を下げるか `retry_after` を上げるか」の判断 (裁定が実測を要求している)

**`retry_after` は 1 つも触らず、ワーカー側の制限時間を下げる**。根拠:

- `database-analysis` / `database-render` の `retry_after` (1680) は
  `job timeout < retry_after < 予約 TTL (1800) ≤ stale 閾値 (1800)` という
  **4 項連鎖の中間項**であり、上げれば予約 TTL 1800 を突き抜けて
  `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` を壊す
  (予約 TTL は `TicketLedgerService` 側の値で「変更しない」と両テストが明記)。
- `database` (90) / `database-media` (300) は載っているジョブがいずれも短時間処理
  (Stripe API 1〜2 コール / オブジェクト削除数本) で、`retry_after` を上げる理由が無い。
- ワーカー側を下げても**既存の実処理は 1 つも短くならない**: 下げる先を
  「ジョブ側 `$timeout` より上・`retry_after` より下」に置くため、
  `$timeout` を持つジョブは従来どおり自分の alarm で終わる。

### 採用する値と根拠

安全余白 **60 秒**を共通に置く (`retry_after` − ワーカー timeout ≥ 60)。
既存の解析 budget が使う S=90 (worker alarm → `run()` 入口 P + タイマー精度 + シグナル配送) と
同系の余白で、ワーカーが子プロセスを kill してから DB の `reserved_at` が解放されるまでの
猶予に充てる。

| 接続 | `retry_after` | ワーカー timeout | 関係 |
|---|---|---|---|
| `database` | 90 | **60** | 60 < 90 (Laravel 既定値と同値。60 は Stripe API 呼び 1〜2 本に十分) |
| `database-analysis` | 1680 | **1620** | 1560 (job) < 1620 < 1680 |
| `database-render` | 1680 | **1620** | 1500 (job) < 1620 < 1680 |
| `database-media` | 300 | **240** | 240 < 300 (オブジェクト削除数本に十分) |

`database-analysis` / `database-render` の 1620 は「ジョブ側 `$timeout` (1560/1500) より上」に
置いてある。これによりジョブは従来どおり自分の pcntl alarm で `failed()` を通って終わり、
既存の finalize 予算 (M₁=30s) と terminal transaction の契約が変わらない。

### mprocs `queue` ペインの接続名を明示する

現状 `php artisan queue:listen --tries=1 --timeout=0` は接続を書いていないため、
**どの接続に対する規則 1 なのかが静的に決まらない** (`QUEUE_CONNECTION` env 次第)。
`php artisan queue:listen database --tries=1 --timeout=60` と**接続名を明示**する。
1 トークンの追加で env 依存が消え、静的 gate が「この行はこの `retry_after` と比較すればよい」と
確定できる。他 3 ペインは既に接続名を書いており、書式が揃う副次効果もある。

### 施策 3 の gate 設計 (規則 1)

`tests/Architecture/QueueWorkerLeaseInvariantTest.php` (DB 不使用の静的検査)。

- **(1) mprocs**: `mprocs.yaml` を Symfony Yaml で読み、各 proc の `shell` を走査する。
  `artisan` と `queue:work`/`queue:listen` を含む proc を**自動的に**ワーカーとみなす
  (allowlist を持たないので、新しいペインを足しても検査から漏れない)。各ワーカーに対し
  「接続名の明示がある」「`--timeout=N` の明示がある」「`1 ≤ N < retry_after(接続)`」を要求。
  `--timeout=0` は「制限なし」なので**必ず違反**として落ちる。
- **(2) ワーカーの網羅**: mprocs のワーカーが覆う接続集合が、`config/queue.php` の
  `driver=database` 接続集合と**一致**することを要求する。新しい専用接続を足したのに
  dev ワーカーを足し忘れたら落ちる (`docs/architecture.md` の運用契約の dev 側の写し)。
- **(3) 非ワーカーの明示除外**: `shell` に `--timeout` を含むが上の判定でワーカーにならない proc
  (= `logs` ペインの `php artisan pail --timeout=0`) は、**理由付きの除外目録**に登録されている
  ことを要求する。黙って除外しない (ブリーフの要求)。`pail` の `--timeout` は
  「ログ追尾を何秒で打ち切るか」であってキューのリースとは無関係、というのが理由。
- **(4) bug-hunt**: `scripts/bug-hunt-shard.sh` から
  `BUGHUNT_WORKER_TIMEOUTS` (施策 2 で導入する連想配列リテラル) を正規表現で抽出し、
  各 `[接続]=N` に同じ `1 ≤ N < retry_after(接続)` を課す。あわせて
  `BUGHUNT_WORKER_CONNECTIONS` の全要素が `BUGHUNT_WORKER_TIMEOUTS` に鍵を持つこと、
  `start_shard_workers` が `--timeout=` の**数値リテラル直書きに戻っていない**こと
  (= 必ず配列経由で渡すこと) を構造で固定する。
  bash を PHP のテストから構造検査する先例は `BughuntShardCapInvariantTest` にある。

### 施策 4 の gate 設計 (規則 2 + 配線網羅)

`tests/Architecture/QueuedJobLeaseInventoryTest.php` (DB 不使用の静的検査)。
台帳が「本 feature の真の欠落」と名指ししている**(a) 新しいジョブが検査対象に入らない**を閉じる。

- **走査**: `app/` 配下の全 PHP から `Illuminate\Contracts\Queue\ShouldQueue` を実装するクラスを
  Reflection で列挙する (現在 18 クラス)。
- **目録**: `QUEUED_JOB_LEASE_INVENTORY` に `クラス => 接続` を宣言する。
  走査結果と目録の**対称差が空**であることを要求する (deny-by-default。
  新しいジョブを足すと必ず落ち、実装者に接続の宣言を強制する)。
- **宣言の裏取り**: 各クラスのソースから `onConnection('リテラル')` を抽出し、
  目録の宣言と一致することを要求する。`onConnection(` が非リテラル引数で現れたら
  「実行時に決まる接続は静的検査できない」として落とす
  (該当時は実行時 fail-fast の対象として個別に扱う。現状 0 件)。
  `onConnection` を持たないクラスは目録で `null` (= 既定接続) と宣言する。
- **規則 2 の検査**: `ReflectionClass::getDefaultProperties()['timeout']` で
  インスタンス化せずに `$timeout` を読み、
  - 接続が明示されている entry: `$timeout < retry_after(接続)` を要求
  - 既定接続 (`null`) の entry: **`$timeout` の宣言自体を禁止**する。
    既定接続は `QUEUE_CONNECTION` env 次第でどの接続にも化けるため、静的に
    `retry_after` と比較できない。`$timeout` が要るなら `onConnection()` で接続を pin せよ、
    というメッセージで落とす (現状 12 クラスすべて `$timeout` 未宣言なので通る)。
  - `$timeout` 未宣言: 規則 2 は空に成立。上限はワーカー側 (規則 1) が担保する。

**2 本の規則のあいだに大小関係は課さない** (標準形 v1 / Codex 合議で確定済み。
ジョブ側 `$timeout` はワーカー起動時の値に優先するため、3 項連鎖は公式文面から導けない。
中央の関係は Horizon 固有の事情で、本家系は Horizon 未導入)。
本設計で `1560 < 1620` を選んだのは**運用上の意図**であって不変条件として固定しない。

---

## 代替案と却下理由

| 案 | 却下理由 |
|---|---|
| **`retry_after` を 1800+ へ引き上げてワーカー 1800 を維持** | 予約 TTL 1800 を突き抜け、`retry_after < 予約 TTL ≤ stale 閾値` の既存連鎖を壊す。裁定の注意書きが名指しで禁じている |
| **全接続に一律の `--timeout` 値を使う** | `retry_after` が 90 / 300 / 1680 と 18 倍の開きがある。最小 (90) に合わせると解析 (1560s) が完走できず、最大に合わせると規則 1 違反が残る。ブリーフも「一律で済ませない」と明記 |
| **全ジョブに `$timeout` を明示させて規則 2 だけで守る** | 規則 1 は「無条件」であり、ジョブ側宣言で免除されない (漏れ 1 本で窓が開く)。18 クラスへの手配線は「選択制だと漏れる」という標準形の指摘そのもの |
| **実行時 fail-fast (spirux 形) を全ジョブに入れる** | 標準形 v1 で**限定適用の補助**と決着済み。全ジョブ配線はまた漏れる。今回は静的側を固める (ブリーフのスコープ外に明記) |
| **`mprocs.yaml` から `--timeout` を削って Laravel 既定 (60) に任せる** | 60 < 90 は満たすが解析 (1560s) / レンダ (1500s) が 60 秒で殺される。かつ「既定に頼る」は静的検査が値を読めない = gate が書けない |
| **gate を `mprocs.yaml` だけに閉じる (ブリーフの文面どおり)** | `scripts/bug-hunt-shard.sh` に実害のある違反 (media で 6 倍) が実在する。同じ規則の違反を隣に残したまま片方だけ固定するのは gate の体裁だけを整える行為 |
| **本番 supervisor 定義をリポジトリに追加して gate 対象にする** | 実体が無い (インフラは別管理)。今無いものを設計のために作るのはオーバーエンジニアリング。`docs/architecture.md` の運用契約に「timeout < retry_after」の 1 行を足すに留める |
| **`config/queue.php` から dev worker の timeout を導出する仕組みを作る** | mprocs.yaml は静的 YAML、bug-hunt は bash で、両者から config を読む共通機構は自前機構になる。値をリテラルで 2 箇所に置き、gate でドリフトを止めるほうが小さい |

---

## スコープに入れないものとその理由

| 入れないもの | 理由 |
|---|---|
| **予約 TTL / 停滞判定閾値 / 外部 Batch API のポーリング予算の連鎖** | c2c `budget-invariant-gates` の管轄。aicue は既に保有 (`AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest`)。**触らない** |
| **二重取得後の結果収束 (冪等化)** | c2c `job-execution-deduplication`。本 feature は「二重取得を起こさない」側 |
| **再試行の終端 / 滞留の回収** | c2c `job-deferral-termination` / `stuck-job-recovery` |
| **キュー投入経路の原子性** | c2c `queue-dispatch-outbox` |
| **実行時 fail-fast (spirux 形) の導入** | 標準形 v1 で限定適用の補助と決着。今回は静的側を固める |
| **`pail` ペインの `--timeout=0`** | `pail` はワーカーではなくログ追尾クライアント。`--timeout` は「追尾を何秒で打ち切るか」であってキューのリースと無関係。**黙って除外せず、施策 3 の除外目録に理由付きで登録する** |
| **本番/ステージングの supervisor 定義の gate 化** | リポジトリに実体が無い (上表)。`docs/architecture.md` の運用契約に文言を足すのみ |
| **`RunManualAnalysis` / `RunManualRender` の `$timeout` 値の見直し** | 既存 budget 連鎖が固定済み。本 feature は連鎖の**外側** (ワーカー) を足す |
| **media 系ジョブへの `$timeout` 追加** | 規則 1 (ワーカー 240 < 300) で上限が担保される。今必要ない (思考原則 2) |
| **`ThrottleCoverageInventoryTest` 型の exemption 機構** | 現時点で除外したいジョブが 0 件。除外機構は必要になってから作る |

---

## 検証方法

| 何を確かめるか | コマンド / 手順 | 期待結果 |
|---|---|---|
| 規則 1 gate が現状を落とすこと (テストファースト) | 施策 3 のテストだけを先に足して `composer test -- --filter=QueueWorkerLease` | **7 件の違反で fail** (mprocs 4 + bug-hunt 3) |
| 規則 2 / 網羅 gate が現状を落とすこと | 施策 4 のテストだけを先に足す (目録を空で) | 18 クラス未登録で fail |
| 是正後に両 gate が通ること | `composer test -- --filter='QueueWorkerLease|QueuedJobLease'` | green |
| 既存 budget 連鎖を壊していないこと | `composer test -- --filter='TimeBudget'` | green |
| bug-hunt の実行配線が壊れていないこと | `scripts/bug-hunt-shard.sh self-test` | 全ケース pass (実資源に触れない) |
| 型安全 | `composer phpstan` | level 10 green |
| 全体 | `composer test` / `vendor/bin/pint --test` | green |

**手動確認** (値が実運用に耐えるか): `composer dev` で mprocs を起動し、
`queue-analysis` ペインで解析ジョブを 1 本流して**ワーカー timeout 1620 に達する前に
ジョブ側 1560 の alarm で終わる**ことをログで確認する (順序が逆だと finalize が走らない)。
