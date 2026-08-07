## アプリの使命（North Star）— 絶対遵守

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則（AGENTS.md）

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

## 禁止事項（AGENTS.md）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
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
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【この件の特殊事情 — 判断の前提にしてよい実測値】
- 本件は **アプリの振る舞いを 1 行も変えない**。app/ は既に標準形を満たしており、追加するのは
  Architecture テスト 1 本・Unit テスト 1 本・文書 2 箇所の訂正のみ。
- 実測 (2026-08-07, /workspace で確認済み):
  - `config/cache.php:128` = `'serializable_classes' => false,`
  - アプリ全体のキャッシュ書き込みは `app/Services/FxRateService.php:49` の
    `Cache::put($cacheKey, $fresh->toArray(), CarbonImmutable::now()->endOfDay());` 1 か所のみ
  - `Cache::lock()` は 9 か所 (AutoRechargeService 6 / SubscriptionService 2 /
    TicketCheckoutService 1 / ReconcileAutoRechargeAttempts 1)
  - `Illuminate\Support\Facades\Cache` を import しているファイルは上記 5 ファイルのみ
  - `cache()` ヘルパ・`Illuminate\Contracts\Cache\Repository` の DI は現状 0 件
  - `->put(` は app/ に 16 件あり、うち 15 件が `session()->put` / 1 件が `disk()->put`
    (= 受け手を見ずに `->put(` を拾う実装は全部誤検出になる)
  - `tests/Architecture/` は 70 ファイル。既存の同型 gate として
    `CarbonOverflowArithmeticGateTest.php` (PhpToken 走査 + 正負コントロール + 空振り検知) と
    `BillingGatewayFailureTaxonomyInventoryTest.php` (deny-by-default 目録 + exact-fit) がある
  - `docs/app-integration-guide.md` 213-214 行に「cache serializable_classes は既定 false。
    object cache が必要になったときだけ最小 allowlist」という **canonical 裁定と矛盾する記述**が実在する

---

## 概念設計

<!-- ここから devnotes/20260807-2032-cache-payload-plain-data/conceptual-design.md の全文 -->

# 概念設計: cache-payload-plain-data (キャッシュ素データ規約の明文化と gate)

## 背景・課題

lctl 台帳の feature `cache-payload-plain-data` は 2026-08-06 の裁定で標準形 v1 を確定している:

- キャッシュに入れてよいのは**素のデータ** (配列 / 文字列 / 数値 / 真偽値) だけ。オブジェクトをそのまま入れない
- 読み出したらアプリのコードが**明示的に組み立て直し**、その際に整合性を検査する
- `config/cache.php` の `serializable_classes` は **false のまま維持し例外を作らない**。
  クラスを名指しで許す**許可一覧は使わない**
- 配列への変換と復元の**往復が壊れないことを単体テストで固定**する (キャッシュ経路を通す必要はない)
- 「オブジェクトをキャッシュに入れていないこと」の**機械検査を標準形に必須**として含める
  (静的検査か実行時検出かは各リポジトリに委ねられている)

aicue の実コードを実査した結果、**アプリの実装は既に標準形を満たしている**:

- `config/cache.php:128` = `'serializable_classes' => false,` (許可一覧なし)
- キャッシュ書き込みはアプリ全体で `app/Services/FxRateService.php:49` の
  `Cache::put($cacheKey, $fresh->toArray(), …)` **1 か所だけ**で、既に配列化済み
- 読み戻し (同 33-37 行) は `Cache::get` → `is_array()` 検査 → `FxSnapshotDto::fromArray()`、
  38-44 行の catch で警告ログ + `Cache::forget()` = 標準形そのもの
- その他のキャッシュ API 利用は `Cache::lock()` 計 9 か所のみ (payload を持たない)

したがって**アプリの振る舞いは 1 行も変わらない**。残っているギャップは次の 4 点である。

1. **実害のある誤情報**: `docs/app-integration-guide.md` 213-214 行 (§7 不変条件 6) が
   「object cache が必要になったときだけ**最小 allowlist**」と書いており、canonical v1 の
   「許可一覧は使わない・例外を作らない」と**正面から矛盾**している。
   この記述を信じた実装者は `serializable_classes` に class を足す方向へ誘導される。
2. **AGENTS.md に規約が無い**: セキュリティ不変条件の本文に逆シリアライズ / キャッシュ payload の
   項目が無く、72 行の採番注意書きに「guide 6 = 逆シリアライズ」と参照があるだけ。
3. **機械検査が 0 本**: `tests/Architecture/` 全 70 ファイルにキャッシュ payload の検査は無く、
   `serializable_classes` の値を pin する検査も無い。違反は**本番で読み出しが失敗するまで気付けない**
   (しかも array driver は `serialize => false` なのでローカル / テストでは**成功してしまう** —
   database driver で serialize される本番でのみ壊れる、発見が最も遅れる型の欠陥)。
4. **往復の単体テストが無い**: `FxSnapshotDto` / `FxRateService` を参照するテストは tests/ 全体で 0 件。

**仮説**: 現時点で違反は 0 件なので、いま入れる検査は「バグを見つける」ためではなく
**「規約が破られた瞬間に落ちる」予防装置**として価値がある。成功判定は
(a) 誤情報が消えること、(b) 新しいキャッシュ書き込み経路を無申告で追加できなくなること、
(c) その検査が空振りしていないことが機械的に示されること、の 3 点。

## 改善アイデア

**「明文化 2 点 + 機械検査 2 点」**に絞り、アプリコードには一切触れない。

### A. 明文化 (誤情報の訂正が主目的)

- `docs/app-integration-guide.md` §7 不変条件 **6 の本文**を canonical v1 に合わせて書き換える。
  「最小 allowlist」を削り、素データ規約・読み戻し時の再構築と検査・gate のファイル名を明記する。
  **§7 の番号は動かさない** (AGENTS.md 71-75 行が renumber を禁じている。既存参照が壊れるため)
- `AGENTS.md` のセキュリティ不変条件に**末尾 11 として追記**する (既存 1-10 は renumber しない)

### B. 機械検査 (deny-by-default)

- `tests/Architecture/CachePayloadPlainDataGateTest.php` を新設し、**3 層**で塞ぐ:
  - **L1 (語彙)**: キャッシュ受け手に対して呼ばれたメソッドを全件、
    `WRITE` / `NON_WRITE` / `CHAIN` のいずれかに分類する。**どこにも属さない API は fail** させる
    (将来 Laravel が新しい書き込み API を足しても、deny 側リストの更新漏れですり抜けない)
  - **L2 (書き込み経路)**: `WRITE` に分類された呼び出し箇所を gate 内 inventory と **exact-fit** で
    突き合わせる。未登録も、登録されているのに実在しない entry も、宣言件数のズレも fail
  - **L3 (面)**: そもそも**キャッシュ記号に触れているファイル**の集合を exact-fit で固定する。
    L1/L2 の静的解析には原理的な穴 (変数による動的ディスパッチ、`app('cache')` 経由など) があるため、
    「新しいファイルがキャッシュに触れ始めたこと」自体を検知する粗い網を重ねる
- 同ファイルに `config('cache.serializable_classes') === false` の **pin** (SsrfPinBoundaryTest 流) と、
  **空振り検知** (走査ファイル数 > 0 / 解決できたキャッシュ式 > 0 / 走査メソッド呼び出し数 > 0)、
  および **正負のコントロール fixture** (`CarbonOverflowArithmeticGateTest` の作法) を置く
- `tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php` を新設し、
  `toArray()` → `fromArray()` の往復一致と不正値の拒否を固定する

### 母集団定義 — 最初に決めるべき論点への結論

**受け手 (receiver) を解決してから**メソッド名を見る、が結論。

| 書き方 | 判定 | 理由 |
|--------|------|------|
| `Cache::put(...)` (facade) | 対象 | 素直な形 |
| `Cache::store('x')->put(...)` / `Cache::tags([...])->put(...)` | 対象 | `store` / `tags` は CHAIN として辿る |
| `cache()->put(...)` / `cache(['k' => $v], $ttl)` | 対象 | ヘルパも受け手として解決する |
| `$this->cache->put(...)` (Repository を DI) | 対象 | ファイル内の型宣言から受け手名を収集して解決する |
| `Cache::lock(...)` とその後続 (`->block()` / `->get()` / `->release()`) | **対象外** | payload を持たない。`lock` は terminal 扱いで以降の chain を辿らない (9 か所) |
| `session()->put(...)` / `$session->put(...)` (16 か所中 15 か所) | **対象外** | 受け手がキャッシュでない |
| `$this->disk()->put(...)` (FakeObjectStore) | **対象外** | 同上 |

「`->put(` を受け手を見ずに拾う」方式は採らない (session/disk を巻き込む)。
「Cache facade だけを見る」方式も採らない (`cache()` ヘルパと DI が素通りして**空振り green** になる)。
この両方向を**負のコントロール fixture** で恒久的に固定する。

## 期待効果

- **使命への貢献**: 直接の機能価値は無い。効くのは中核処理を支える基盤の壊れにくさ。特に本件は
  **array driver では再現せず本番でのみ壊れる**型の欠陥を対象にしており、CI で落とせる形にする価値が高い
- **誤情報の除去**: 実装者を `serializable_classes` へ class を足す方向へ誘導する記述が消える。
  gadget chain 攻撃面 (APP_KEY 漏洩時) を開けさせない
- **予防**: キャッシュ書き込み経路の追加が**申告なしには不可能**になる
- **家系の整合**: 5 リポジトリ共通の標準形 v1 に aicue が準拠したことを機械検査で示せる

## 実装方針（概要）

| # | 施策 | 対象 | 種別 |
|---|------|------|------|
| S1 | キャッシュ payload gate の新設 | `tests/Architecture/CachePayloadPlainDataGateTest.php` | 新規 |
| S2 | FxSnapshotDto 往復の単体テスト | `tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php` | 新規 |
| S3 | guide §7 不変条件 6 の誤情報訂正 | `docs/app-integration-guide.md` | 変更 |
| S4 | AGENTS.md セキュリティ不変条件 11 の追記 | `AGENTS.md` | 変更 |

- **アプリコード (`app/` / `config/` / `routes/`) の変更はゼロ**
- 検査は `PhpToken::tokenize` による静的走査 (DB 不使用)。regex は使わない
  (本 gate 自身の説明コメントに `Cache::put` と書いた瞬間に偽赤になるため)
- inventory は**現状 1 経路しか無い**ため、`app/Enums/Security/` への新規 enum +
  `tests/Support/Security/` への inventory クラスは作らず、gate ファイル内の const で足りる (思考原則 2)
- 実装順は**テストファースト** (思考原則 5): gate を先に書き、mutation を注入して赤を確認してから
  文書を直す。「素の main では緑」の予防 gate なので、赤を一度も見ずに完了報告しない

## 制約・前提

- **§7 の採番を動かさない**。guide 6 の**本文書き換えは可**、番号移動は不可。AGENTS.md 側は 11 を足す
- Architecture lane は `tests/Pest.php` で `TestCase` のみ (DB 不使用)。本 gate も DB に触れない
- `RefreshDatabase` はグローバル適用済み。個別 `DatabaseTransactions` は使わない
- PHPStan level 10 対象に `tests/` が含まれる。走査ヘルパは戻り値型・配列 shape を明示する
- 実行時間: `app/` + `tests/` + `routes/` + `database/` の PHP を 1 パス token 走査

## スコープ外

- **アプリコードの書き換え**。`FxRateService` / `FxSnapshotDto` / `config/cache.php` は現状維持
- **実行時検出** (`KeyWritten` イベント購読 / cache store の decorator によるテスト時 assert)。
  テストで実行された経路しか見えず、`FxRateService` にテストが無い現状では**空振りする**
- **`serializable_classes` の allowlist 運用手順の設計** (canonical v1 が「例外を作らない」と裁定済み)
- **`Cache::lock` 側の不変条件** (`JobExecutionDedupInventoryTest` 等の担当で母集団が交わらない)
- **フロントエンド** (差分ゼロ)

---

## レビューで特に判断してほしい論点

1. **L3 (キャッシュ記号に触れるファイルの exact-fit) は過剰か**。現状 5 ファイルで、
   新規にキャッシュを使うファイルが増えるたびに 1 行の申告を強いる。
   L1/L2 の静的解析の穴 (変数動的ディスパッチ・`app('cache')`) を埋める粗い網として置いているが、
   思考原則 2 (今必要なものだけ作る) に照らして正当化できるか。
   代替案 (L3 を持たず L1/L2 の限界をコメントで明示するだけ) の方が良いか。
2. **走査範囲に `tests/` を含めるべきか**。現状 tests/ にキャッシュ書き込みは 0 件。
   含めると「テストが object を cache に入れて array driver でだけ通る」経路も塞げるが、
   将来のテスト作成時の摩擦が増える。
3. **実行時検出を併用しない判断**は妥当か (スコープ外の根拠が十分か)。
4. **inventory を enum + 専用クラスに昇格させない判断**は妥当か。
5. この改善自体を**今やるべきでない**と考える理由があれば指摘してほしい
   (アプリの振る舞いが変わらない予防作業であり、価値は誤情報訂正と予防に限られる)。
