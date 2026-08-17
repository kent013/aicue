# アプリの使命・禁止事項・思考原則（全 Codex 呼び出しに自動適用）

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

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js + PostgreSQL）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか（とくにテナント境界・PII・性能）
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件で特に見てほしい点】
- 「原稿」の解釈（narration/subtitle に scene を足して「カット本文」とした判断）は妥当か
- PC 面と撮影 PWA 面で検索対象を書き分けない判断は妥当か
- 作成者名検索を「作らない」とした判断（PII 暗号化を弱めない制約下で）は妥当か
- 性能の見立て（EXISTS / 索引 / pg_trgm を採らない判断）に穴はないか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: manual-search-scope (一覧検索の対象範囲の拡張)

## 背景・課題

### doc が求めている検索対象

| 出典 | 記述 |
|---|---|
| `doc/04_PCサイト機能仕様.md` L30 (§4.2 動画一覧ページ) | 「**検索**: タイトル・作成者名などのキーワード。」 |
| `doc/05_スマホアプリ機能仕様.md` L33 (§5.2 シナリオ選択画面) | 「**検索**: タイトルや原稿のキーワード。」 |

### 現行実装 (2026-08-17 時点。**コードを読んで確認した事実**)

- PC 一覧: `app/Http/Controllers/Projects/ProjectController.php` `manualRows()` L185-188
  ```php
  if ($listQuery->keyword !== null) {
      $baseQuery->where('title', 'like', '%'.addcslashes($listQuery->keyword, '%_\\').'%');
  }
  ```
- 撮影 PWA 一覧: `app/Http/Controllers/Capture/CaptureManualController.php` `index()` L75-78
  ```php
  ->when($search !== null, function (Builder $query) use ($search): void {
      Assert::string($search);
      $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%');
  })
  ```

**両面とも `title` の LIKE 1 条件だけ**である。`cuts` (narration / subtitle_* / scene) を
対象にした検索も、作成者名の検索も **app/ に 1 件も存在しない**
(`grep` で `narration`/`subtitle`/`scene` を含む検索経路が無いことを確認済み)。

### 台帳の記述が実態と合っていないことの訂正 (重要)

`docs/TODO-closed.md` L71 の **T053** は表題・本文とも「原稿検索」を実装したと書いているが、
**実装は存在しない**。本設計は台帳を根拠にせず、現行コードだけを根拠にする。
(本タスクは `docs/TODO.md` を編集しないため、台帳の訂正は本設計書への記録に留める。
実装 TODO をクローズする際に `docs/TODO-closed.md` の T053 行へ訂正注記を足すかは
実装タスク側の判断とする。)

### ブリーフの前提の検証結果

| ブリーフの前提 | 検証結果 |
|---|---|
| PC / PWA とも title の LIKE 1 条件だけ | **正しい** (上記) |
| 原稿検索も作成者名検索も無い | **正しい** |
| `users.name` は CipherSweet + blind index | **正しい**。`User::configureCipherSweet()` が `addField('name')` + `addBlindIndex('name', new BlindIndex('name_index', [new Lowercase]))`。既存利用は Filament の 2 箇所のみ (`UserResource` / `UsersRelationManager`) で、いずれも `whereBlind('name', 'name_index', $search)` = **case-insensitive 完全一致** |
| `ManualListQuery` に 200 字上限がある | **正しい** (`MAX_KEYWORD_LENGTH = 200`)。ただし **PWA 側はこの VO を通っていない** = **検索語長の上限が無い** (`$request->string('q')->value()` を素通し、`trim` も無し)。ブリーフに無かった食い違いなので本設計で扱う |
| 撮影 PWA は ready/published に限定された母集団 | **正しい**。加えて **PWA 一覧は paginate せず `.get()` で全件返す** |

### 追加で判明した前提 (設計判断を左右する)

1. **`cuts` のテキスト列で必須なのは `scene` だけ**である。
   `UpdateScenarioRequest` L143-148: `scene` は `required`、`narration` / `subtitle_secondary` は
   `present` (空文字許容)、`subtitle_primary` は `nullable`。`ScenarioEditor.svelte` L208 も
   「新規行の空値 (scene のみ必須のため空で作る)」。
   → **手で書いたシナリオは narration が全カット空になりうる**。
2. `cuts.video_manual_id` に **明示的な索引が無い**。migration
   `2026_07_10_000300_create_cuts_table.php` は `foreignId(...)->constrained()` だけで、
   Laravel の `compileForeign` は FK 制約しか出さない。**PostgreSQL は FK 列に索引を自動生成しない**
   (MySQL/InnoDB とは異なる)。テストレーンの接続は `phpunit.xml` L52 で `pgsql` 固定。
3. 一覧のクエリ数を行数に比例させない契約が既にテストで固定されている
   (`tests/Feature/Projects/ManualListQueryCountTest.php` /
   `tests/Feature/Capture/CaptureManualListQueryCountTest.php`)。

## 改善アイデア

### 1. 「カット本文検索」を PC / PWA の両面へ入れる

キーワード検索の対象を **`video_manuals.title` + 配下 `cuts` の本文 4 列** に広げる。

対象列 (**採る**):

| 列 | 意味 | 採る理由 |
|---|---|---|
| `cuts.scene` | 「シーン (何を撮るか)」= そのカットで写す内容 | **カット本文で唯一の必須列**。手書きシナリオでは他が空になりうるため、これを外すと「本文検索が空振りするシナリオ」が構造的に生まれる。PWA の手順/急所リストに表示される主テキストでもある |
| `cuts.narration` | ナレーション原稿 | doc/05 の「原稿」の中心。doc/04 L47 が「ナレーション/字幕原稿」と呼ぶ 2 つのうちの 1 つ |
| `cuts.subtitle_primary` | 画面常時表示の短い要点 (100 字) | 同上。字幕原稿 |
| `cuts.subtitle_secondary` | 補足説明の字幕 | 同上。字幕原稿 |

対象外 (**採らない**):

| 列 | 採らない理由 |
|---|---|
| `cuts.shooting_point` | 「撮影ポイント」= **撮影者への構図指示**であり作業内容ではない。「手元を寄りで」「全体が入る画角で」のような定型句が多数のマニュアルに散らばるため、含めると精度 (precision) だけが落ちる。doc/05 でも電球アイコンの「撮影ガイド」として原稿と別扱い |
| `cuts.type` / `shot_type` / `material_type` | enum。キーワード検索の対象ではない |
| `source_documents` (SOP 原本) | doc の「原稿」はナレーション/字幕原稿を指す (doc/04 L47・`TakePreviewPanel.svelte` L183「ナレーション原稿を表示」)。SOP 原本の全文検索は別の機能で、今回のスコープ外 |

**「原稿」の解釈の明示**: doc/05 の「原稿」は厳密には narration / subtitle である。本設計は
そこへ `scene` を 1 列足して「**カット本文**」と呼ぶ。足す根拠は上表のとおり
「scene がカット本文で唯一の必須列であり、これを外すと本文検索が成立しないシナリオが出る」ため。
narration だけに絞る案は **却下** (却下理由を上に明記した)。

### 2. 面ごとに対象を変えない (PC も PWA も同じ対象)

doc は PC = タイトル + 作成者名、PWA = タイトル + 原稿と書き分けているが、**実装は書き分けない**。

- 同一利用者が PC と PWA を同じ ID で使う。同じ形の検索窓が面によって当たる範囲を変えると、
  「PC で出たのに PWA で出ない」を利用者が説明できない。
- doc の面ごとの記述は「その面で最低限欲しいもの」の列挙であって排他ではない。
  PC 側はシナリオを**書く**面なので、「あのナレーションを書いたマニュアル」を探す需要はむしろ強い。
- 二重管理の回避: 検索述語 (対象列・エスケープ規則・キーワード正規化) を **1 箇所**に置き、
  両 Controller がそれを呼ぶ。面ごとに条件を書くと必ず食い違う (今まさに 200 字上限で食い違っている)。

### 3. 検索語の正規化を PC / PWA で一本化する

現状 PWA は `trim` も 200 字上限も通っていない。正規化 (trim → 先頭 200 字) を
`ManualListQuery` と PWA の両方が同じ関数から得る形にする。

**200 字上限の根拠を書き直す**: 現在の docblock は「title の validation が max:200 なので
201 文字目以降が一致に寄与することは無い」と書いているが、`narration` / `subtitle_secondary` は
max:2000 なので **この根拠は本改善で成立しなくなる**。上限は残す (根拠を
「実務で 200 字を超えるキーワードを打たない」+「切り詰めは常により広く当たる方向にしか倒れない」
へ差し替える) が、**古い根拠を残さない**。

### 4. 作成者名検索は「作らない」— 設計判断

ブリーフの選択肢 (a) 完全一致で実装 / (b) 作らない / (c) 別方式 のうち **(b) を採る**。

**採る理由**:

- `users.name` は blind index (値全体のハッシュ) なので `whereBlind` は
  **case-insensitive の完全一致**しかできない。doc が書いている「キーワード」= 部分一致は
  **原理的に成立しない**。
- 現在の検索窓は 1 つで、そこへ入れた語は title / カット本文に対して**部分一致**する。
  そこへ「作成者名だけは完全一致」を混ぜると、「田中」で `田中 太郎` が出ない一方
  タイトルの「田中」は出る、という**説明できない挙動**になる。
  これは「静かに嘘をつく UI」であり、AGENTS.md の禁止事項 8 (押せない/詰む UI を作らない) と
  同じ精神に反する。
- 現状の代替手段で実用上足りている: 一覧は **作成者名を各行に表示**しており、
  **「自分の作成分のみ」フィルタ (`mine`)** が PC / PWA の両方にある。
  「自分 / 全員」の 2 値以上の粒度が要るという観測データは今のところ無い (思考原則 2)。

**採らなかった案と理由**:

| 案 | 却下理由 |
|---|---|
| (a) `whereBlind('name','name_index', $keyword)` で完全一致検索を実装 | 実装自体は容易だが上記の「説明できない挙動」を作る。部分一致を期待した利用者に 0 件を返す = 検索が壊れているように見える |
| (c-1) 平文の `name_search` 列を併設 / 正規化平文列を持つ | **PII の暗号化を弱める**。禁止 (AGENTS.md セキュリティ不変条件 6) |
| (c-2) n-gram / 前方一致ごとに blind index を張る (粒度を下げる) | blind index の粒度を下げると頻度解析で平文が推定できる。**暗号化を弱める方向**なので禁止 |
| (c-3) 作成者を **select で選ぶフィルタ** (`created_by` = 選んだ org メンバーの id) | 暗号化を一切弱めずに実現できる**唯一の正攻法**。ただし今は `mine` で足りており「あったら便利」の段階 (思考原則 2)。**Conditional として条件付きで登録する**: 「1 project の manual 作成者が 3 人を超え、かつ `mine` では絞れないという要望が出たら」着手する |

### 5. 対象が広がることを利用者に伝える

- PWA の検索欄の placeholder は現在 **「タイトルで検索」** (`Capture/Index.svelte` L101)。
  対象が広がると**この文言は嘘になる**ので必ず直す → 「タイトル・本文で検索」。
- PC の検索欄はラベル「キーワード」だけで placeholder が無い (`Projects/Show.svelte` L456-466)。
  同じ文言の placeholder を足して両面を揃える。
- 文言は 1 箇所 (共有定数) に持つ。2 箇所に書くと必ず食い違う。

## 期待効果

- **使命への貢献**: 「思考ゼロ」で現場作業者が撮るべきシナリオへ最短で到達できる。
  撮影 PWA の利用者はタイトルを覚えていない (タイトルは SOP 由来で機械的な名前になりがち) 一方、
  「ほうき」「ネジ」のような**作業の語**は覚えている。本文検索はその語で当てられるようにする。
- doc/04 §4.2・doc/05 §5.2 の検索要件のうち、実現可能な部分 (原稿検索) を満たす。
  実現不可能な部分 (作成者名の部分一致) は**やらない理由を設計として残す**。
- PC / PWA の検索挙動の食い違い (200 字上限・trim の有無) を解消する。

## 実装方針（概要）

1. `App\Services\Manual\ManualKeywordSearch` (仮称) を新設し、以下を 1 箇所に持つ:
   - `normalize(?string): ?string` — trim → 空なら null → 先頭 `MAX_KEYWORD_LENGTH` 文字
   - `apply(Builder $query, string $keyword): void` — `title` LIKE **OR** `cuts` への EXISTS
     (4 列の LIKE)。LIKE メタ文字は既存と同じ `addcslashes($keyword, '%_\\')`
2. `ManualListQuery::fromRequest()` は `normalize()` を呼ぶ (挙動は現状同値)。
   `ProjectController::manualRows()` は `apply()` を呼ぶ。
3. `CaptureManualController::index()` も `normalize()` + `apply()` を呼ぶ
   (= PWA に 200 字上限と trim が入る)。
4. **OR 条件は必ず 1 つのクロージャで括る**。括らないと `orWhere` が
   `project_id` / `status` / `created_by` の絞り込みを外へ押し出し、
   **cross-project の manual が一覧に混ざる** (テナント境界の破壊)。
   これは本改善で最も危険な失敗様式であり、Feature テストで固定する。
5. `cuts.video_manual_id` に索引が無ければ足す migration を 1 本。
6. 検索欄の placeholder 文言を共有定数化して PC / PWA の両方に出す。

## 性能の見立て

- **クエリ本数は増えない**。EXISTS は同一 SQL 内の副問い合わせであり、行ごとの追加クエリ (N+1) を
  生まない。既存の `ManualListQueryCountTest` / `CaptureManualListQueryCountTest` の契約を維持する。
- **件数規模の想定**: 1 project あたり manual 10^2〜10^3 本、manual あたり cut 10〜50 →
  project あたり cuts は 10^3〜10^4 行、全テナント合計でも 10^5 行のオーダー。
- `%語%` の LIKE は B-tree 索引が効かない。この規模では `cuts` の逐次走査 (数 ms〜数十 ms) で足り、
  **pg_trgm + GIN 索引は導入しない** (拡張の導入は運用権限と運用負担を増やす。思考原則 2)。
- ただし `cuts.video_manual_id` の索引は足す。理由は本改善だけではない —
  PWA 一覧は既に `withCount(['cuts', ...])` で **manual 行ごとに `cuts` への相関副問い合わせ**を
  出しており、索引が無いと `cuts` 全走査 × 行数になる。**既存の性能問題の解消も兼ねる**。
- **将来の引き金 (Conditional)**: `cuts` が 10^6 行を超える、または一覧描画の p95 が 1 秒を
  超えたら pg_trgm / 全文検索を検討する。今は作らない。

## 制約・前提

- テナント境界を跨ぐ検索を作らない。PC は `$project->manuals()`、PWA は
  `$project->manuals()->whereIn('status', [Ready, Published])` が母集団で、
  **本改善は母集団を 1 行も広げない** (キーワードは母集団の内側の AND 条件として足す)。
- `ManualListQuery` の allowlist 解析 (category / progress / sort / mine / page) は変更しない。
- LIKE の大小文字の扱いは現行踏襲 (pgsql の `like` = 大小文字を区別する)。
  本改善で変えない (下記スコープ外)。
- `response()->json()` 直書きをしない / DTO 経由 — 本改善は props の shape を変えないため
  新規 DTO は不要。
- フロントは Svelte 5 runes + DS token のみ。変更は placeholder 文言 1 種のみで
  component 階層に影響しない。

## スコープ外

- **作成者名の検索** (上記 4 の理由により作らない。(c-3) の select フィルタは Conditional)。
- **SOP 原本 (`source_documents`) の全文検索**。
- **大小文字を区別しない検索 (ILIKE 化)**。現行の title 検索と挙動を揃えるため今回は変えない。
  変えるなら title と本文を同時に変える別タスクにする (面によって挙動が違う状態を作らない)。
- **語の分割・部分語・同義語・ランキング**。今回は単一の部分一致だけ。
- **pg_trgm / 全文検索索引の導入**。
- カテゴリ / 状態 / 並べ替えなど他の絞り込み条件の変更。
- 検索結果のハイライト表示 (どの列に当たったかの提示)。

