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

## あなたの役割

Laravel 12 + Svelte 5 のアプリ (AI-CUE) のコードレビュアーとして、TODO T176
「bug-hunt 目録の生成器化とドリフト検査の作り替え」の実装差分をレビューする。

この施策は開発支援基盤である (HTTP 経路・UI を持たない。Console Command と開発用スクリプトのみ)。
bug-hunt (LLM 探索的バグハント) の分母となる 2 つの目録
(.claude/skills/app-bug-hunt/screens.md = 画面一覧 / operations.md = 書き込み操作一覧) を
手書きから生成物へ移し、ドリフト検査を「表に名前が載っているか」から
「実装 + 注釈から再生成した結果と byte 一致するか」へ作り替えた。

## レビュー観点

1. **設計との一致性**: 詳細設計 (下記) の施策 1..6 と実装が食い違っていないか。
   食い違うなら「設計が正しいのか実装が正しいのか」まで述べること
2. **正確性**: 生成器 (Python) の段 1..4 の判定に穴が無いか。とくに
   - 面 (web セッション面) の判定と除外
   - 注釈の定義域一致 (未注釈 / 残置注釈) と語彙・必須・理由の形式
   - 生成物の差し替え (os.replace) の失敗経路と復元
   - 終了コード規約 (0=一致 / 2=致命 / 3=ドリフト) の一貫性
   - 下流の照合器 coverage/correlate.py (5 列・name 列が join キー) の入力契約を壊していないか
3. **fail-closed かどうか**: 「壊れた走行が緑になる」経路が残っていないか
   (旧実装にはそれが 3 種類あり、それを潰すのが本施策の主目的である)
4. **PHPStan 適合性** (level 10): 抽出コマンドと DTO の型
5. **テスト網羅性**: Python 自己テスト (50 ケース) / Architecture テスト / Feature テストが
   「空振り (常に緑)」になっていないか。負の対照が足りているか
6. **セキュリティ**: 抽出コマンドは production で走らないこと、生成物に秘密が漏れないこと
7. **保証範囲の誇張が無いか**: 文書 (AGENTS.md / SKILL.md / docs/template-divergence.md) が
   「保証しないもの」を正直に書けているか

DESIGN.md 準拠 / Atomic Design 準拠の観点は本差分に該当なし (resources/js / resources/css を触っていない)。

## 出力形式

- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で明示する

---

## 詳細設計書

# 詳細設計: bughunt-inventory-generator

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

本施策の位置付け: **開発支援基盤**である。使命への貢献は「bug-hunt の分母の信頼性を上げることで
撮影 PWA の品質保証の精度を上げる」という**間接的・補助的**なものであり、製品機能ではない。

### 禁止事項（AGENTS.md）

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

本施策は 4〜8 の対象となる HTTP 経路・UI を持たない (Console Command と開発用スクリプトのみ)。
1〜3・9 は全施策で守る。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)。`RefreshDatabase` はグローバル適用、個別 `DatabaseTransactions` 禁止
- テストデータは Factory 経由 (本施策は DB を使わない)
- `declare(strict_types=1)` + 日本語コメント
- Python は**標準ライブラリのみ** (AGENTS.md §bug-hunt)
- PHP 8.4 + Laravel 12

## 概念設計リファレンス

- `devnotes/20260815-2100-bughunt-inventory-generator/conceptual-design.md` (Codex 合議 Round 4 で APPROVED)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 抽出コマンドと DTO (`bughunt:inventory-scan`) | `app/Console/Commands/Bughunt/InventoryScanCommand.php` / `app/DataTransferObjects/Bughunt/{InventoryScanData,InventoryRouteData}.php` | 高 |
| 2 | 注釈ファイルと散文ノートの新設 (+ 一度きりの移行) | `.claude/skills/app-bug-hunt/inventory/{annotations.toml,notes-screens.md,notes-operations.md}` / `devnotes/…/bootstrap-annotations.py` | 高 |
| 3 | 生成器兼検査器 (`scripts/bug-hunt-inventory.py`) | `scripts/bug-hunt-inventory.py` | 高 |
| 4 | 目録を生成物へ切替 + シェル検査器の薄化 | `.claude/skills/app-bug-hunt/{screens,operations}.md` / `scripts/bug-hunt-inventory-check.sh` | 高 |
| 5 | テスト配線 (Python 自己テスト / Architecture / Feature) | `scripts/tests/test_bug_hunt_inventory.py` / `tests/Architecture/{BughuntInventoryToolSelfTest,BugHuntInventoryCheckInvariantTest}.php` / `tests/Feature/Bughunt/InventoryScanCommandTest.php` | 高 |
| 6 | 文書 (逸脱登録・スキル手順・台帳) | `docs/template-divergence.md` / `AGENTS.md` / `scripts/README.md` / `.claude/skills/app-bug-hunt/{SKILL.md,capability-catalog.md}` | 中 |

**実装順序 (テストファースト)**: 5 の失敗ケースを先に書いて赤にする → 1 → 3 → 2 → 4 → 6。
施策 5 のうち Python 自己テストは施策 3 の関数を import するため、
「import に失敗して赤」から始まり、実装が進むにつれ緑になる (空振りしない)。

---

## 施策 1: 抽出コマンドと DTO

### 変更箇所

- 新規 `app/DataTransferObjects/Bughunt/InventoryRouteData.php`
- 新規 `app/DataTransferObjects/Bughunt/InventoryScanData.php`
- 新規 `app/Console/Commands/Bughunt/InventoryScanCommand.php`

### 責務の線引き

**PHP 側は「事実の書き出し」だけを行う。面の判定・分類・除外は 1 つも持たない**
(判定は生成器 = Python に一本化する。同じ規則を 2 言語に置かない)。
したがってコマンドは**全 route を出力する** (名前の無い route も落とさずに出す)。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: 新規 2 件 (HTTP 応答には使わない。Console 出力専用)
- テストファイル: 施策 5 の `tests/Feature/Bughunt/InventoryScanCommandTest.php`

### 変更後コード (骨子)

```php
// app/DataTransferObjects/Bughunt/InventoryRouteData.php
final readonly class InventoryRouteData
{
    /**
     * @param  list<non-empty-string>  $methods     HEAD を含む宣言どおりの HTTP メソッド
     * @param  list<non-empty-string>  $middleware  宣言のままの middleware (group 名 `web` を含みうる)
     */
    public function __construct(
        public ?string $name,
        public string $uri,
        public array $methods,
        public array $middleware,
        public ?string $action,
        public ?string $title,
    ) {}

    /**
     * @return array{
     *   name: string|null, uri: string, methods: list<non-empty-string>,
     *   middleware: list<non-empty-string>, action: string|null, title: string|null
     * }
     */
    public function toArray(): array { /* ... */ }
}
```

```php
// app/DataTransferObjects/Bughunt/InventoryScanData.php
final readonly class InventoryScanData
{
    public const SCHEMA_VERSION = 1;

    /** 抽出条件のラベル。環境名ではない (local 実行と Pest 実行で同一になる)。 */
    public const EXTRACTION_CONDITION = 'local-or-unit-tests';

    /** @param list<InventoryRouteData> $routes */
    public function __construct(public array $routes) {}

    /**
     * @return array{
     *   schema_version: int, extraction_condition: non-empty-string,
     *   routes: list<array{name: string|null, uri: string, methods: list<non-empty-string>,
     *     middleware: list<non-empty-string>, action: string|null, title: string|null}>
     * }
     */
    public function toArray(): array { /* ... */ }
}
```

```php
// app/Console/Commands/Bughunt/InventoryScanCommand.php
final class InventoryScanCommand extends Command
{
    protected $signature = 'bughunt:inventory-scan';

    protected $description = 'bug-hunt 目録の機械事実 (route 定義と画面題名) を JSON で出力する';

    public function handle(Router $router): int
    {
        // 抽出条件: debug route の登録条件 (routes/web.php) と同一述語。
        // 満たさない環境で走らせると母集合が黙って変わるため、生成物には触れずに落とす。
        if (! ($this->laravel->isLocal() || $this->laravel->runningUnitTests())) {
            // エラーは標準エラーへ。標準出力には 1 バイトも出さない (壊れた入力を後段へ渡さない)。
            $this->output->getErrorStyle()->error('抽出条件を満たさない環境では走らせない (local もしくはテスト実行時のみ)');

            return self::FAILURE; // = 1。生成器はこれを致命 (exit 2) へ写像する
        }

        $titles = $this->appTitles();          // config 境界で mixed を排除する
        $routes = [];
        foreach ($router->getRoutes() as $route) {
            $name = $route->getName();
            $routes[] = new InventoryRouteData(
                name: $name,
                uri: $route->uri(),
                // 空文字を残すと list<non-empty-string> が成立しないので落とす。
                // 落とした結果 methods が空になる route は生成器の段 1 が致命として拾う。
                methods: $this->nonEmptyStrings($route->methods()),
                middleware: $this->nonEmptyStrings($route->gatherMiddleware()),
                action: $route->getActionName(),
                title: $name === null ? null : ($titles[$name] ?? null),
            );
        }

        $this->output->writeln(json_encode(
            (new InventoryScanData($routes))->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function appTitles(): array { /* is_array + キー/値の型検査。壊れていたら例外 */ }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<non-empty-string>
     */
    private function nonEmptyStrings(array $values): array
    {
        // PHPDoc だけで list<non-empty-string> を主張せず、ループで組み立てて推論を通す。
        $out = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }
}
```

### 設計上の注意 (実装者向け)

- `Route::gatherMiddleware()` は**宣言のまま**の一覧を返し、group 名 `web` が残る。
  `Router::gatherRouteMiddleware()` は group を展開して `web` を消すので**使わない**
- middleware に文字列でない要素 (Closure 等) が混ざりうるので文字列だけを残す。
  `web` group の判定には影響しない (group はいつも文字列)
- 出力は**1 行の JSON** (標準出力)。人間向けの装飾を混ぜない
- 失敗時は標準出力に JSON を**1 バイトも出さない** (壊れた入力を後段へ渡さない)

### PHPStan適合チェック

- [ ] `list<non-empty-string>` / `array<string, string>` を明示 (裸の `array` を残さない)
- [ ] `config()` の戻りは `is_array` とキー・値の型検査を経てから DTO へ渡す
- [ ] `json_encode` は `JSON_THROW_ON_ERROR` (戻り値 `string|false` の分岐を残さない)
- [ ] `toArray()` の戻り値に完全な array shape を付ける

### テスト計画 (施策 5-d に実体を置く)

- [ ] **先に赤くする**: `tests/Feature/Bughunt/InventoryScanCommandTest.php` を、コマンド未実装の状態で書く
- [ ] 正常系: `artisan('bughunt:inventory-scan')` が exit 0 で、標準出力が 1 行の JSON。
      `schema_version` / `extraction_condition` / `routes[].{name,uri,methods,middleware,action,title}` の
      キーが揃う (`toArray()` の形の固定)
- [ ] `web` group を宣言した route の `middleware` に文字列 `web` が**そのまま**含まれる (展開されていない)
- [ ] `config('seo.app_titles')` にある route の `title` が引けている / 無い route は `null`
- [ ] 名前の無い route も出力に含まれる (握り潰さない)
- [ ] **抽出条件を満たさない環境で非 0 終了し、標準出力が空であること**。
      環境の差し替えは `$this->app->instance('env', 'production')` で行い、
      **元の値を保存して `finally` で戻す** (後続の assertion / teardown に漏らさない) —
      Laravel 12 の `isLocal()` は `$this['env'] === 'local'`、`runningUnitTests()` は
      `$this->bound('env') && $this['env'] === 'testing'` で、**どちらも同じ束縛 `env` を読む**ため、
      これで両方 false にできる (実装を確認済み)。
      `detectEnvironment()` は `$_SERVER['argv']` を見る経路があるので使わない

### リスク

- `gatherMiddleware()` の戻りに controller middleware が混ざる (仕様どおり)。
  `web` group の有無だけを見るので影響は無い
- 抽出条件の述語は `routes/web.php` の debug route 登録条件と**二重管理**になる。
  ずれると母集合が変わるので、Feature テストのコメントに相互参照を書き、
  条件を変えるときは両方を直すことを明記する

---

## 施策 2: 注釈ファイルと散文ノート

### 変更箇所

- 新規 `.claude/skills/app-bug-hunt/inventory/annotations.toml` (人が書く。147 route 分)
- 新規 `.claude/skills/app-bug-hunt/inventory/notes-screens.md` (現行 `screens.md` の散文節を移設)
- 新規 `.claude/skills/app-bug-hunt/inventory/notes-operations.md` (同上)
- 新規 `devnotes/20260815-2100-bughunt-inventory-generator/bootstrap-annotations.py` (**一度きり**の移行用)

### 注釈の形式

```toml
schema_version = 1

# 対象内の画面
[routes."dashboard"]
kind = "画面"     # 画面表の route でのみ必須。操作表の route に書いたら drift
story = "S1"      # 区分が 通常 / 逸 のとき必須 (S1..S7)
kubun = "通常"    # 通常 / 逸 / 終 / 外 の 4 語のみ

# 画面に付随する JSON GET (画面ではないものを画面の分母に混ぜない)
[routes."session.status"]
kind = "JSON"
story = "S6"
kubun = "通常"

# web 面だが探索の分母に載せないもの
[routes."seo.robots"]
kind = "JSON"
kubun = "外"
reason = "クローラ向けの機械可読 route であり人が操作する画面ではないため分母に載せない"  # 30 文字以上
```

| キー | 必須条件 | 検査 |
|---|---|---|
| `kind` | 画面表の route で必須 / 操作表の route では**禁止** | 段 2 |
| `story` | 区分が `通常` / `逸` のとき必須 (値は `S1`..`S7`) / 区分が `外` / `終` では**禁止** | 段 2 |
| `kubun` | 常に必須。`通常` / `逸` / `終` / `外` のみ | 段 2 |
| `reason` | 区分が `外` / `終` のとき必須・**30 文字以上** / それ以外では禁止 | 段 2 |

- **許可キーはこの 4 つだけ**。トップレベルは `schema_version` と `[routes.…]` のみ。
  未知のキー (`memo` / `stroy` のような打ち間違い) は段 2 の drift にする
  (書いたのに効いていない注釈を残さない)
- 区分 `外` / `終` で `story` を禁じるのは、表では `-` に潰れて見えなくなる古い割当を残さないため
- セルへ出る値 (`kind` / `story` / `kubun`) と、生成器が実装から取る値 (uri / route 名 / 題名) に
  `|` / CR / LF が含まれていたら段 2 の drift にする
  (`correlate.py` が `split("|")` で読むため、エスケープ規約は作らず禁止する)。
  `reason` は表の外の箇条書きに出るので、**制御文字 (`U+0000`..`U+001F` と `U+007F`) を禁止**し、
  固定の箇条書きテンプレート (`- \`{route 名}\` — {reason}`) の後ろへ 1 行として差し込む

- 形式に **TOML** を選ぶ理由は「Python 標準ライブラリだけで読める」ため (`tomllib`)。
  本環境に PyYAML は無く、家系標準の `annotations.yaml` を採ると依存追加か自前パーサが要る
  (逸脱として `docs/template-divergence.md` に登録する)
- 生成器は**注釈ファイルを書き換えない** (`tomllib` は読み取り専用。書き手は人だけ)

### 散文ノートへ移すもの (現行 md からの移設。内容は変えない)

| 移設元 | 移設先 |
|---|---|
| `screens.md` の「非 Inertia の GET」「パスキー options endpoint の扱い」「課金ゲート着地の画面遷移」「ナビゲーション/レイアウト規約」 | `notes-screens.md` |
| `operations.md` の「課金ゲート allowlist と認可」「パスキー / ログイン手段の認可・guard 契約」 | `notes-operations.md` |

### 移行手順 (一度きり)

1. `bootstrap-annotations.py` が現行 `screens.md` / `operations.md` の表を読み、
   `story` と `kind` (現行の散文が JSON GET と呼ぶ 5 本を `JSON`、他を `画面`) を写して
   `annotations.toml` の下書きを作る
2. 現行の正規表現に沈んでいた 13 route と `webhooks.ses` は `kubun = "外"` +
   **`reason` を空**で出力する (空では段 2 が落ちるので、人が理由を書くまで緑にならない)
3. 人が 14 件の理由を書く → `generate` → `check` が exit 0 になることを確認
4. 移行スクリプトは**旧表の route 集合と新 `annotations.toml` の route 集合の差分**を標準出力へ出す。
   その出力 (差分が「新しく見える 14 件」だけであること) を devnotes に記録してから次へ進む
5. 移行スクリプトは `devnotes/` に置いたままにする (`scripts/` へ昇格させない)

### テスト計画

- [ ] 注釈ファイル自体の妥当性は施策 3 の段 2 が検査する (別テストを作らない)
- [ ] 移行スクリプトはテストを持たない (一度きり・生成物は人がレビューする)。
      **代わりに移行後の `check` が exit 0 であることを実測して devnotes に記録する**

### リスク

- 147 件の注釈を一度に用意する必要がある。1 と 2 は機械で写せるので、
  人が書くのは**新しく見える 14 件の理由だけ**
- 注釈のキーを増やしたくなる誘惑がある (capability / 備考など)。**今回は増やさない**
  (段 4 はカタログ側を検査するので注釈側に capability 欄は要らない)

---

## 施策 3: 生成器兼検査器 `scripts/bug-hunt-inventory.py`

### 変更箇所

- 新規 `scripts/bug-hunt-inventory.py` (stdlib のみ。`generate` / `check` の 2 サブコマンド)

### 終了コード規約 (T164 の道具と揃える)

| コード | 意味 |
|---|---|
| 0 | 一致 (`check`) / 生成完了 (`generate`) |
| 2 | 致命 (抽出不能・抽出条件不一致・母集合 0 件・空名/重複名・入力ファイル不在・壊れた TOML・想定外例外) |
| 3 | ドリフト (段 2 / 段 3 / 段 4 の違反) |

- **1 と 4 以上は使わない**。`argparse` が引数エラーで返す 2 は「致命」の側に落ちるので矛盾しない
- `main()` は `except Exception` で `traceback` を出して 2 を返す (`BaseException` は捕まえない)

### 処理の骨子

```python
STAGE1, STAGE2, STAGE3, STAGE4 = "抽出", "注釈", "生成物", "機能カタログ"
EXIT_OK, EXIT_FATAL, EXIT_DRIFT = 0, 2, 3

EXTRACTION_CONDITION = "local-or-unit-tests"   # PHP 側 InventoryScanData と一致させる
SURFACE_EXCLUDED_SEGMENTS = ("oauth",)         # 先頭セグメント完全一致
SURFACE_EXCLUDED_PREFIXES = ("livewire",)      # 先頭セグメントの前方一致 (livewire-{hash})
KUBUN_VOCABULARY = ("通常", "逸", "終", "外")
KUBUN_OUT_OF_SCOPE, KUBUN_DEVIATE = "外", "逸"  # correlate.py の定数と一致させる (自己テストで照合)
STORY_IDS = tuple(f"S{i}" for i in range(1, 8))
SCREEN_KINDS = ("画面", "JSON")

def scan(repo_root) -> dict:            # php artisan bughunt:inventory-scan (subprocess)
def split_surface(scan) -> Facts:       # 段 1: 面の判定・表の分割・空名/重複名/母集合 0 件
def load_annotations(path) -> dict:     # tomllib。壊れていたら FatalError
def validate_annotations(facts, ann) -> list[str]   # 段 2: 定義域一致 + 語彙 + 形式 + 複合 method
def render_screens(facts, ann, notes) -> str        # 段 3 の素材
def render_operations(facts, ann, notes) -> str
def check_catalog(catalog_text, facts) -> list[str] # 段 4

# 公開 entry。CLI (main) はこの 2 つを呼ぶだけにする (自己テストは fake scanner を注入する)。
# 既定値に関数を束縛せず、None のとき関数内で scan を選ぶ (monkey patch を前提にしない)。
def run_check(repo_root, *, scanner: Scanner | None = None) -> int
def run_generate(repo_root, *, scanner: Scanner | None = None) -> int
```

**リポジトリルートは `Path(__file__).resolve().parent.parent` で確定する** (cwd に依存しない)。
`subprocess` の `cwd` にも同じ値を明示で渡す。

- `scan()` は `subprocess.run(["php", "artisan", "bughunt:inventory-scan"], cwd=repo_root)`。
  非 0 終了 / JSON parse 失敗 / `schema_version` 不一致 / `extraction_condition` 不一致は**致命**
- 面の判定は概念設計どおり: middleware の**要素**に `web` があり、
  先頭セグメントが除外表に当たらないもの。**文字列化した部分一致はしない**
- **名前の検査の母集合を分ける** (抽出コマンドが全 route を出す仕様と衝突させない):

  | 対象 | 扱い |
  |---|---|
  | web 面に入った route が無名 / 空名 | **致命 (exit 2)**。目録の join キーを作れない |
  | 面の外にある無名 route (vendor の資材配信等) | **許容** (実測 12 件。抽出結果には出るが目録に入らない) |
  | 名前の重複 | **全 named route を対象に致命 (exit 2)**。段 4 の全 route 名参照まで曖昧になるため |

- 入力構造そのものを解釈できないもの (`schema_version` が 1 でない / `routes` が表でない /
  route の項目が表でない) は drift ではなく**致命 (exit 2)**
- 表の分割: 非 GET メソッド (`GET` / `HEAD` / `OPTIONS` 以外) を 1 つでも持てば操作表、
  それ以外は画面表。**両方に該当する route (GET と非 GET の併存) は段 2 の drift**

### 生成物の書式 (byte 一致で比較するので厳密に決める)

共通:

- 改行は LF、末尾に改行 1 つ、UTF-8 (BOM なし)
- 先頭に生成物ヘッダを置く (再生成コマンド / 抽出条件のラベル / 件数)。**環境名は書かない**
- 行の並びは **route 名の昇順** (ASCII 比較。安定ソート)
- 表の後に「対象外の理由」節 (区分 `外` / `終` の route を route 名昇順で列挙)、
  その後に散文ノートの中身をそのまま連結する (区切りは空行 1 つ)

`screens.md` の表 (6 列):

```markdown
| route (URL) | name | 種別 | 画面名 | 割当ストーリー | 区分 |
|---|---|---|---|---|---|
| dashboard | dashboard | 画面 | ダッシュボード | S1 | 通常 |
```

- 画面名は `config('seo.app_titles')` 由来。無ければ `-`
- 区分が `外` / `終` の行は 割当ストーリー を `-` にする

`operations.md` の表 (**5 列固定。`correlate.py` の入力契約**):

```markdown
| method | route | name | story | 区分 |
|---|---|---|---|---|
| POST | projects | projects.store | S4 | 通常 |
```

- `method` は非 GET のメソッドを昇順で `,` 連結する (`|` は表を壊すので使わない。実測では全行 1 つ)
- `story` は区分が `外` / `終` のとき `-`
- ヘッダ名 (`name` / `story` / `区分`) は `correlate.py` が列位置を決めるキーなので変えない
- **散文ノートに下流ローダを騙す表を置かせない**: `correlate.py` は `operations.md` を頭から走査し、
  ヘッダらしい行を見つけるたびに列位置を更新する。**ヘッダとして読めない表が来ても列位置は
  更新されない**ため、連結される `notes-operations.md` にどんな表があっても、直前に読んだ
  生成表の列割当で行が読まれてしまう (実装確認: `_header_indices()` が `None` を返すと
  `idx` は据え置かれる)。よって**ノート内に表の行 (行頭が `|`) が 1 行でもあれば段 2 の drift**
  にする。実装時の是正: 設計当初は「`name` / `story` / `区分` を含む表ヘッダ」だけを禁じていたが、
  それでは別のヘッダを持つ表が素通りして同じ事故になるため、規則を「表そのものを置かない」へ広げた
  (規則が単純になり、抜けも塞がる)

### 段 4 (機能カタログの参照整合) の契約

- 対象はヘッダが `| id | 機能 (actor→outcome) | 代表機構 (route name) |` の表**だけ**
  (`capability-catalog.md` の他の表 — 責務境界・割当規則 — は見ない)
- id は `^[A-Z]{2,5}-[0-9]{2}$`。**重複したら drift**
- 代表機構セルは**バッククォートで囲まれた token だけ**を route 名候補とする。
  `/` 区切りの複数記載を許す。丸括弧の説明 (`(機構横断)` / `(admin panel)` /
  `(クライアント状態。route なし)`) とパス (`routes/api.php`) は候補にしない
- `*` で終わる token (`projects.categories.*` / `legal.*`) は**前方一致で 1 件以上**当たれば良い
- 実在判定の母集合は**抽出した全 route 名** (web 面に限らない。カタログは admin / api 面も指す)
- 当たらない token が 1 つでもあれば drift。**網羅性 (すべての route が id を持つか) は見ない**

### fail-closed の作法

- `check` は**ファイルを 1 バイトも書かない** (レンダリング結果はメモリ上で比較)
- `generate` は段 1 / 2 / 4 を通ってから、次の順で**部分的な成果物を残さずに**差し替える:
  1. 2 つの一時ファイルを同じディレクトリに書き切る
  2. 既存の 2 ファイルを同じディレクトリの控え (`.bak-*`) へ複製する
  3. `os.replace()` を 2 回連続で実行する
  4. 2 本目が失敗したら**控えから 1 本目を戻す**。戻せたら exit 2 (「再実行せよ」)
  5. 戻すのにも失敗したら、**元 / 一時 / 控えのどれも消さず**、全パスを標準エラーへ出して exit 2
  6. 成功したら控えを消す

  失敗経路の状態遷移も実装コメントで固定する (2 本目の置換だけを特別扱いしない):

  | 失敗した場所 | 後始末 | 終了コード |
  |---|---|---|
  | 一時ファイルの書き出し / 控えの複製 (置換を始める前) | 一時ファイルと作成済みの控えを best-effort で消す。元ファイルは触っていない | 2 |
  | 1 本目の `os.replace()` | 同上 (元ファイルは 2 本とも無傷) | 2 |
  | 2 本目の `os.replace()` | 控えから 1 本目を戻す | 2 |
  | 上の復元 | 何も消さず、元 / 一時 / 控えの全パスを標準エラーへ | 2 |
  | 成功後の控えの削除 | 生成の成功は取り消さない。残ったパスを標準エラーへ明示 | 2 |

  「通常の置換失敗 (1 本目 / 2 本目) では、呼び出し前の 2 ファイルが byte 単位で保たれる」ことを
  自己テストで固定する (1 本目の失敗も独立したケースとして置く)。
  世代ディレクトリを作って参照先を切り替えるような完全な多ファイル原子性は**作らない**
  (生成物 2 つに対して過剰)
- 段 2 / 段 4 の違反は**例外にせず drift 行として全件列挙**してから exit 3
  (家系 spirux で「注釈欠落が `KeyError` → exit 1」になった実績があるため先回りで塞ぐ)

### テスト計画 (施策 5-a に実体を置く)

- [ ] **先に赤くする**: `scripts/tests/test_bug_hunt_inventory.py` を先に書く (import 失敗で赤)
- [ ] 致命 (exit 2): 抽出コマンド非 0 / JSON 壊れ / `extraction_condition` 不一致 /
      母集合 0 件 / 空名 / 重複名 / ノート不在 / 壊れた TOML / 想定外例外
- [ ] ドリフト (exit 3): 未注釈 route / 残置注釈 / 未知の区分 / 未知のキー / 30 文字未満の理由 /
      story 欠落 / 区分 `外` に story がある / 画面 route の `kind` 欠落 / 操作 route への `kind` /
      セル値に `|` や改行が入る / 複合 method / 生成物 byte 不一致
- [ ] 一致 (exit 0) と、`check` が 1 バイトも書かないこと (mtime と内容で確認)
- [ ] `generate` が段 2 失敗時に**1 ファイルも書かない**こと
- [ ] `generate` の 2 本目の `os.replace()` が失敗したとき **exit 2** になり、
      **呼び出し前の 2 ファイルが byte 単位で保たれている**こと (控えからの復元)。
      復元にも失敗した場合は元 / 一時 / 控えのどれも消えず、全パスが標準エラーに出ること
- [ ] 面の外にある無名 route が抽出結果に含まれていても成功すること (許容の実測)
- [ ] `schema_version` 不一致 / `routes` が表でない → 2
- [ ] 段 4: 実在しない代表機構 / id の重複 / `*` 記法が 1 件も当たらない → 3。
      括弧書きとパスのセルは無視されること
- [ ] 面の判定: `throttle:webhook-stripe` を持つ route を web 面と誤認しないこと (部分一致の否定)
- [ ] 面の除外: `oauth/...` と `livewire-{hash}/...` が母集合に入らないこと
- [ ] 分割の網羅: 画面表 ⊎ 操作表 = web 面 (件数と集合の両方)
- [ ] 語彙の一致: `KUBUN_OUT_OF_SCOPE` / `KUBUN_DEVIATE` が
      `coverage/correlate.py` の定数と一致すること (import して比較)

### リスク

- 書式を厳密に決めるので、生成物の見た目を変えたいときは必ず生成器を直すことになる (意図どおり)
- `php artisan` を subprocess で呼ぶため、APP_KEY 未設定の環境では動かない。
  CI では `key:generate` の後に走らせる (既存ステップの位置を変えない)

---

## 施策 4: 目録を生成物へ切替 + シェル検査器の薄化

### 変更箇所

- 改稿 `.claude/skills/app-bug-hunt/screens.md` (生成物になる。68 行 + 対象外理由 + 散文ノート)
- 改稿 `.claude/skills/app-bug-hunt/operations.md` (生成物になる。79 行 + 同上)
- 改稿 `scripts/bug-hunt-inventory-check.sh` (97 行 → 判定を持たない薄い呼び出し)

### 変更後コード (シェル)

```bash
#!/usr/bin/env bash
#
# scripts/bug-hunt-inventory-check.sh — bug-hunt 目録のドリフト検査 (起動のみ)
#
# 判定は scripts/bug-hunt-inventory.py に一本化してある。**このスクリプトに判定を戻さない**
# (同じ規則が 2 か所に増えると必ず食い違う)。
#
# exit 0=一致 / 2=致命 (抽出不能・環境不一致・母集合 0 件等) / 3=ドリフト
set -euo pipefail

WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
exec python3 "${WORKSPACE}/scripts/bug-hunt-inventory.py" check "$@"
```

生成器は自分の位置 (`__file__`) からリポジトリルートを決めるので、
**どの cwd から起動しても結果は同じ**である (この性質を sandbox テストで固定する)。

### 波及変更

- CI (`.github/workflows/ci.yml`): **変更しない** (`bash scripts/bug-hunt-inventory-check.sh` のまま)。
  `tests/js/architecture/ci-workflow-inventory.test.ts` W16 が起動行を固定しているため、
  行を変えると赤くなる
- `coverage/correlate.py`: **変更しない** (5 列と列ヘッダ名を維持する)
- `.claude/skills/app-bug-hunt/SKILL.md`: 施策 6 で手順を差し替える

### 生成物の冒頭 (両ファイル共通)

```markdown
> **このファイルは生成物である。手で編集しない。**
> 直し方: `.claude/skills/app-bug-hunt/inventory/annotations.toml` (割当・区分・理由) か
> `inventory/notes-*.md` (散文) を直してから `python3 scripts/bug-hunt-inventory.py generate` を走らせる。
> 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。
> ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。
```

### テスト計画 (施策 5-c)

- [ ] シェルが**判定を持たない**こと: `route:list` / `grep` / `OUT_OF_SCOPE` の語を含まないことを静的に固定。
      検査対象は**コメント行を除いた実装行**に限る (説明コメントで誤検知しないため)
- [ ] 別の cwd から起動しても同じ結果になること
- [ ] sandbox 実走で exit 0 / 3 / 2 の 3 経路 (施策 5-c に詳細)
- [ ] 生成物の冒頭に「生成物である」宣言があること (手編集の抑止が消えたら赤くする)

### リスク

- 生成物へ切り替えた瞬間、既存の散文が失われないかが最大の懸念。
  移設は**文言を変えずに**行い、切替後の `git diff` で散文が全量残っていることを目視確認する
- 初回はテーブルの並び順が変わる (route 名昇順に統一) ため diff が大きくなる。
  内容の欠落と並び替えを混同しないよう、移行時に**行集合の一致**を機械で確認する
  (`bootstrap-annotations.py` に集合比較を出力させる)

---

## 施策 5: テスト配線

### 5-a. `scripts/tests/test_bug_hunt_inventory.py` (Python 自己テスト・stdlib)

- `unittest`。生成器はファイル名にハイフンを含むので
  `importlib.util.spec_from_file_location` で読み込む (この読み込み自体もテストの一部)
- 抽出は**実 `php` を呼ばない**。`scan()` に注入した fake (固定 JSON を返す callable) で駆動する
  = 決定論・DB 不使用・高速
- 施策 3 のテスト計画の全ケースをここに置く
- **下流ローダとの結合テストを 1 本置く** (設計記述と定数比較だけでは契約が固定できないため):
  1. fake scanner で `operations.md` をレンダリングして一時ファイルへ書く
  2. `coverage/correlate.py` の `load_operations()` を import して読む
  3. 読めた route 名の集合が**操作表と完全一致**する (余分も欠けも無い)
  4. 各 route の `story` / `kubun` / `operation` (URL 列) が期待どおり。
     併せて**同じ route 名が生成 md 内に 1 度しか現れない**ことも見る
     (`load_operations()` は重複を「最初の定義を優先」で畳むので、重複が隠れないようにする)
  5. 散文ノートに表を混ぜた fixture では、段 2 が drift を返して
     そもそもレンダリングまで進まない (上の禁止規則の負の対照)

### 5-b. `tests/Architecture/BughuntInventoryToolSelfTest.php` (新規)

既存 `tests/Architecture/BughuntCoverageToolSelfTest.php` と同じ形:

```php
test('python3 が PATH にあること (環境不備を skip で隠さない)', ...);
test('生成器の Python 自己テストが composer test の下で通ること', function (): void {
    // scripts/tests で python3 -m unittest test_bug_hunt_inventory
});
test('負の対照: 存在しないモジュール名を渡すと非 0 になること', ...);
```

- **skip しない** (python3 不在は fail)。理由は既存テストのコメントと同じ

### 5-c. `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` (作り替え)

現行テストは「シェルが自前で判定する」前提の契約を固定しているため、契約ごと作り替える。
**削除ではなく作り替え**であり、固定する不変条件は増える (0/3 → 0/2/3 + 判定の非重複)。

| 現行の test | 新しい test |
|---|---|
| スクリプトが存在・実行可能・`set -euo pipefail` | **維持** |
| 目録正本が `{screens,operations}.md` であること (シェル内の変数を見る) | **生成器側**を見る (`bug-hunt-inventory.py` が正本パスを持つ) + シェルが判定語を持たないこと |
| exit 0 / 3 の実走 (php shim で route:list を差し替え) | **exit 0 / 3 / 2 の実走** (php shim で `bughunt:inventory-scan` を差し替え、注釈・ノート・生成物の fixture を置く) |
| route:list 取得失敗で exit 0 を返さない | **維持** (exit 2 であることまで固定) |

sandbox の組み立ては現行の `bhicMakeSandbox()` を流用し、置くものを増やす
(`scripts/bug-hunt-inventory.py` / `inventory/annotations.toml` / `inventory/notes-*.md` /
生成物 2 本 / `bin/php` shim)。

sandbox の契約 (曖昧にすると実 PHP を呼んで DB / APP_KEY に依存する):

- `PATH` の**先頭**に sandbox の `bin` を置く
- `bin/php` shim は**引数を無視**して固定の scan JSON を標準出力へ出す
  (失敗系の shim は標準エラーへ出して非 0 で終わる)
- プロセスの cwd は sandbox。実 `php` / artisan / DB / APP_KEY には一切依存しない

### 5-d. `tests/Feature/Bughunt/InventoryScanCommandTest.php` (新規)

施策 1 のテスト計画のとおり。既存の `tests/Feature/Bughunt/` に置く
(`ExecutedRouteCaptureTest.php` と同じ場所)。

### リスク

- sandbox テストは実プロセスを起動するので遅い。現行と同じ規模 (数ケース) に抑え、
  細かい分岐は Python 自己テスト側に置く

---

## 施策 6: 文書

### 変更箇所と内容

| ファイル | 変更 |
|---|---|
| `docs/template-divergence.md` | **D20** を追加 (テンプレート正典との 3 点の差: 機能カタログを生成せず 3 列を維持 / 注釈は TOML / 中間 JSON を持たない)。「揃えている不変条件」に段 2・段 4 を書く |
| `AGENTS.md` §bug-hunt | 「スケルトン」の記述を「目録は生成物」へ改め、再生成コマンドとドリフト検査の役割 (何を守るのか) を 3 行で書く |
| `scripts/README.md` | `bug-hunt-inventory.py` を台帳へ追加、`bug-hunt-inventory-check.sh` の説明を薄い呼び出しへ更新、`scripts/tests/test_bug_hunt_inventory.py` を追加 |
| `.claude/skills/app-bug-hunt/SKILL.md` | Phase 1 の「`route:list` から手で生成する」手順を `generate` / `check` へ差し替え。メンテナンス規約の「新画面を実装したら 2 ファイルを更新する」を「注釈を 1 行足して再生成する」へ |
| `.claude/skills/app-bug-hunt/capability-catalog.md` | 冒頭に「本表は生成物ではない。ただし代表機構列の route 名と id の一意性は段 4 が検査する」と明記 |

文書では**現状と規則を分けて書く**: 「webhook / API / 管理画面は**現在** `web` group を宣言していないので
沈黙している」(現状) と「`web` を宣言した route は面の除外表の 2 つを除き必ず目録に入る」(規則) を混ぜない。

いずれの文書にも**線引きを同じ言葉で書く**: 「`web` group を宣言していない面には沈黙する」/
「面として除くのは `oauth` と `livewire-{hash}` の 2 つ」/「web 面の中で分母に載せないものは
注釈の区分 `外` として**目録に見える形で**宣言する」。

### D20 に書く内容 (骨子)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 機能カタログ | 生成物 (家系標準の 3 列 = 機能 / 対応する画面 / 対応する操作) | **生成しない**。3 列は `id` / `機能` / `代表機構` を維持 (id は `ledger/findings.schema.json` の `capability_tag` の語彙正本であり、標準の 3 列には id 列が無い) |
| 注釈ファイル | `annotations.yaml` | **`annotations.toml`** (Python は stdlib のみという規約があり PyYAML が無い。`tomllib` は標準) |
| 中間成果物 | `inventory/inventory.json` をコミット | **持たない** (読み手がいない。`correlate.py` が読むのは `operations.md`) |

**揃えている不変条件**: 「目録は実装と注釈から再生成でき、ずれていたら CI が落ちる」
(段 2 = 注釈の定義域一致 / 段 3 = 生成物の byte 一致 / 段 4 = カタログの参照整合)。

### テスト計画

- [ ] `docs/template-divergence.md` の追記は既存の書式に合わせる (エントリ形式の 4 節)
- [ ] `AGENTS.md` の記述は `.claude/skills/app-bug-hunt/SKILL.md` と食い違わないこと (人手で確認)
- [ ] `scripts/README.md` の台帳追記は AGENTS.md の規約 (恒久スクリプトは台帳へ) を満たす

---

## 保証しないもの (実装後に文書へ残す)

- **`web` group を宣言していない面には沈黙する** (機械向け API `api/` / Filament `/admin` /
  MCP / webhook 等。実測でこれらは `web` を宣言していない)。
  面の定義として除くのは `oauth` と `livewire-{hash}` の 2 つだけで、
  それ以外で `web` を宣言した route は**必ず目録に入り注釈を要求される** (未注釈なら drift)
- 注釈の**内容**の妥当性は見ない (語彙・形式・定義域まで)
- 画面題名の欠落は検出しない (実測で対象内 50 画面のうち 17 件は題名が無い)
- 目録の母集合 ⊆ T164 の記録器が観測しうる route であり、両者は一致しない
- 機能カタログの網羅性は見ない (代表機構の実在と id の一意性まで)

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 目録 2 ファイルを生成物へ切り替える不可逆な変更で、Architecture テスト 1 本の契約を作り替える。段階的に入れると「手書きと生成物が並走する期間」ができ、後方互換の並走を残さない原則 (思考原則 3) に反する |
| 競合リスク | 他の TODO が route を追加すると `annotations.toml` に注釈が要る (= マージ時に段 2 で赤くなる)。マージ順に依存するので、**本 TODO は route を触る他 TODO とは同時に走らせない**。CI で必ず検出されるので silent break にはならない |


## 実装差分 (git diff: app/ tests/ scripts/ とスキルの入力ファイル)

```diff
diff --git a/.claude/skills/app-bug-hunt/SKILL.md b/.claude/skills/app-bug-hunt/SKILL.md
index 6fdf8d1..56a4f48 100644
--- a/.claude/skills/app-bug-hunt/SKILL.md
+++ b/.claude/skills/app-bug-hunt/SKILL.md
@@ -15,8 +15,9 @@ # 探索的バグハント (bug-hunt)
 両方を消化するように設計されている。**発見と報告まで**が守備範囲。修正は app-design / app-implement の管轄。
 
 > **テンプレート注記**: 本スキルは spirux/aigenba の bug-hunt 基盤を汎用化したもの。アプリ名・ポート・DB 名は
-> プレースホルダ化してある。`screens.md` / `operations.md` / `stories/` は**スケルトン**で、初回に
-> `php artisan route:list` から生成する (下記 Phase 1)。オプトインで、使わなければアプリ実行には一切影響しない
+> プレースホルダ化してある。`screens.md` / `operations.md` は**生成物**で、注釈 (`inventory/annotations.toml`)
+> と散文 (`inventory/notes-*.md`) から作る (下記 Phase 1)。`stories/` はスケルトンのままである。
+> オプトインで、使わなければアプリ実行には一切影響しない
 > (config/bughunt.php + BughuntCoverageMiddleware は env + function_exists の二重 guard で完全 no-op)。
 
 ## 使命
@@ -122,7 +123,8 @@ ### 手順 (親 = このセッション。worktree 内から実行)
      **shard agent は consult しない** (子は素の `proposed` finding のみ)。
 6. **teardown**: `BUGHUNT_ORCHESTRATOR=1 scripts/bug-hunt-shard.sh teardown --run-id {ts} [--drop-db]`。
    その後、手順2 の `--hold-lock` 常駐プロセスを終了して lock 解放。
-7. **インベントリ修正の反映**: 統合 report に記録した採用分のみを screens.md / operations.md / stories に反映する。
+7. **目録修正の反映**: 統合 report に記録した採用分のみを `inventory/annotations.toml` (割当・区分・理由) /
+   `inventory/notes-*.md` (散文) / stories に反映し、`python3 scripts/bug-hunt-inventory.py generate` を走らせる。
 8. **adjudication 追記の規律 (人手判断時のみ)**: finding を誤検知 / 意図的仕様 / won't-fix と確定したら、
    cross-session の再 triage を避けるため `ledger/adjudications.jsonl` に 1 行 append (既存行は編集しない)。
    詳細スキーマは `ledger/README.md`。
@@ -198,36 +200,35 @@ ### 環境の前提知識
 | テストアカウント | ManualTestSeeder が投入 (`{role}-{plan}@example.com` / `multi-org@example.com` / `unverified@example.com`、全員 `password123`)。管理画面 admin は `admin@example.com` / `password12345` (AdminUserSeeder) |
 | 管理画面 MFA | `.env.bughunt.local` の `ADMIN_MFA_REQUIRED=false` で無効化 (email+password でログイン可) |
 
-## Phase 1: インベントリ鮮度確認 (初回はスケルトンから生成)
+## Phase 1: 目録の鮮度確認 (生成物なので手で書かない)
 
-screens.md (画面) と operations.md (操作) が現実と乖離していないかを確認する。**テンプレート初期状態では
-両ファイルは空スケルトン**なので、初回は下記で `route:list` から生成して埋める:
+screens.md (画面) と operations.md (操作) は**生成物**である。実装の機械事実
+(`php artisan bughunt:inventory-scan`) と、人が書く注釈・散文を合成して作る。
+まずドリフトが無いことを確認する:
 
 ```bash
-# 画面 (GET × inertia)
-php artisan route:list --json | python3 -c "
-import json,sys
-for r in json.load(sys.stdin):
-    if 'GET' not in r['method']: continue
-    uri=r['uri']; mw=str(r.get('middleware',[]))
-    if uri.startswith(('api/','admin','_','.well-known','storage','sanctum','livewire','oauth','mcp')) or 'debug' in uri: continue
-    if 'web' not in mw: continue
-    print(uri, r.get('name') or '-')" | sort
-
-# 操作 (非GET × web セッション面)
-php artisan route:list --json | python3 -c "
-import json,sys
-for r in json.load(sys.stdin):
-    m=r['method'].split('|')[0]
-    if m in ('GET','HEAD','OPTIONS'): continue
-    mw=str(r.get('middleware',[])); name=r.get('name') or '-'
-    if 'web' not in mw: continue
-    if name.startswith(('cashier','passport','livewire')) or 'webhook' in name: continue
-    print(m, r['uri'], name)" | sort -k2
+scripts/bug-hunt-inventory-check.sh   # exit 0=一致 / 2=致命 / 3=ドリフト
 ```
 
-- インベントリに無い新ルートは追記し、どのストーリーに割り当てるか決める。消えたルートは落とす。
-- ドリフト検知は `scripts/bug-hunt-inventory-check.sh` でも実行できる (exit 0=差分なし / 3=差分あり)。
+- **exit 3 (ドリフト)** の出力は 3 種類に分かれる。
+  - `[注釈] 未注釈の route: …` — 実装に route が増えた。
+    `.claude/skills/app-bug-hunt/inventory/annotations.toml` に 1 行足す
+    (画面なら `kind` = 画面 / JSON、割当なら `story` = S1..S7 と `kubun` = 通常 / 逸、
+    探索の分母に載せないなら `kubun` = 外 と 30 文字以上の `reason`)。
+  - `[注釈] 実装に無い route の注釈が残っている: …` — route が消えた。注釈も消す。
+  - `[生成物] 生成物が再生成の結果と一致しない: …` — 再生成し忘れか手編集。下記を走らせる。
+- 注釈を直したら再生成する (**表の行は手で書かない**):
+
+```bash
+python3 scripts/bug-hunt-inventory.py generate
+```
+
+- **exit 2 (致命)** は抽出そのものが成立していない (抽出条件を満たさない環境 / 母集合 0 件 /
+  壊れた注釈)。目録には触れずに原因を直す。
+- 散文 (画面の既知の仕様・認可契約など) は `inventory/notes-screens.md` /
+  `inventory/notes-operations.md` に書く。**ノートに表を書かない**
+  (連結先を読む `coverage/correlate.py` が操作行として拾ってしまうため、段 2 が拒否する)。
+- 見るのは `web` group を宣言した面だけである (機械向け API / 管理画面 / MCP / webhook には沈黙する)。
 - このフェーズは数分以内に留める。
 
 ## Phase 2: ストーリー実走 (本体)
@@ -456,7 +457,9 @@ ### Phase 4b: worktree のクローズ (既定の worktree 走行時)
 
 ## メンテナンス規約
 
-- 新画面・新フローを実装したら screens.md / operations.md と該当ストーリーを更新する。
-  新しい書き込みルートは必ずいずれかのストーリーに割り当てる (ドリフト検知は inventory-check.sh)。
+- 新画面・新フローを実装したら `inventory/annotations.toml` に注釈を 1 行足して再生成し
+  (`python3 scripts/bug-hunt-inventory.py generate`)、該当ストーリーを更新する。
+  新しい書き込みルートは必ずいずれかのストーリーに割り当てる (未注釈は inventory-check.sh が exit 3)。
+  **screens.md / operations.md を直接編集しない** (生成物であり、byte 比較で赤くなる)。
 - ストーリーカードの「期待」は設計の正 (devnotes/docs) への参照を持つこと。カード自体が仕様の正本になってはならない。
 - 同じ finding が 2 回連続で「要確認」のまま放置されたら、仕様を確定させる TODO を提案する。
diff --git a/.claude/skills/app-bug-hunt/capability-catalog.md b/.claude/skills/app-bug-hunt/capability-catalog.md
index 7e917c4..b7571dd 100644
--- a/.claude/skills/app-bug-hunt/capability-catalog.md
+++ b/.claude/skills/app-bug-hunt/capability-catalog.md
@@ -3,6 +3,12 @@ # Capability Catalog (機能一覧・bug-hunt 正本) — AI-CUE
 `ledger/findings.schema.json` の必須フィールド `capability_tag` と、stories/ カードが消化する
 機能単位を指す **capability_id の語彙正本**。
 
+> **本表は生成物ではない** (人が書く。テンプレート正典との差は `docs/template-divergence.md` D20)。
+> ただし `capability_id 索引` の表については、**代表機構列の route 名が実在すること**と
+> **id が重複しないこと**を `scripts/bug-hunt-inventory-check.sh` の段 4 が検査する
+> (`*` で終わる記法は前方一致で 1 件以上。丸括弧の説明とパスは route 名候補にしない)。
+> **網羅性は検査しない** (本表は overlay であり MECE を主張しないため)。
+
 - これは「機構 (route / job / CLI) を **user-value で grouping した overlay**」であり **MECE ではない**
   (完全性を主張しない)。分母の正本は `screens.md` (画面) と `operations.md` (書き込み操作) の 2 つで、
   本表はその上に「利用者にとって何が達成できるか」を重ねたもの。
diff --git a/.claude/skills/app-bug-hunt/inventory/annotations.toml b/.claude/skills/app-bug-hunt/inventory/annotations.toml
new file mode 100644
index 0000000..0fa000b
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/inventory/annotations.toml
@@ -0,0 +1,669 @@
+# bug-hunt 目録の注釈 (人が書く。生成器は読むだけで書き換えない)。
+#
+# 目録本体 (screens.md / operations.md) は生成物である。実装から取れる事実 (URL / route 名 /
+# メソッド / 画面題名) は生成器が入れるので、ここには**実装から導けない意味だけ**を書く。
+#
+#   kind   画面表の route で必須 (画面 / JSON)。操作表の route には書けない
+#   story  区分が 通常 / 逸 のとき必須 (S1..S7)。区分が 外 / 終 には書けない
+#   kubun  常に必須 (通常 / 逸 / 終 / 外)
+#   reason 区分が 外 / 終 のとき必須・30 文字以上。それ以外には書けない
+#
+# 許すのはこの 4 項目だけで、未知の項目・未知の語彙・定義域のずれは
+# `scripts/bug-hunt-inventory-check.sh` が exit 3 (ドリフト) で落とす。
+schema_version = 1
+
+[routes."billing.auto-recharge.setup"]
+story = "S5"
+kubun = "通常"
+
+[routes."billing.auto-recharge.update"]
+story = "S5"
+kubun = "通常"
+
+[routes."billing.checkout"]
+story = "S5"
+kubun = "通常"
+
+[routes."billing.contact.update"]
+story = "S5"
+kubun = "通常"
+
+[routes."billing.index"]
+kind = "画面"
+story = "S5"
+kubun = "通常"
+
+[routes."billing.plan.change"]
+story = "S5"
+kubun = "通常"
+
+[routes."billing.plans"]
+kind = "画面"
+story = "S5"
+kubun = "通常"
+
+[routes."billing.portal"]
+story = "S5"
+kubun = "通常"
+
+[routes."billing.tickets.checkout"]
+story = "S5"
+kubun = "通常"
+
+[routes."billing.tickets.show"]
+kind = "画面"
+story = "S5"
+kubun = "通常"
+
+[routes."capture.csrf-cookie"]
+kind = "JSON"
+story = "S3"
+kubun = "通常"
+
+[routes."capture.home"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
+[routes."capture.manuals.index"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
+[routes."capture.manuals.show"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
+[routes."capture.takes.adopt"]
+story = "S3"
+kubun = "通常"
+
+[routes."capture.takes.destroy"]
+story = "S3"
+kubun = "通常"
+
+[routes."capture.takes.downloaded"]
+story = "S3"
+kubun = "通常"
+
+[routes."capture.takes.playback"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
+[routes."capture.takes.store"]
+story = "S3"
+kubun = "通常"
+
+[routes."capture.takes.update"]
+story = "S3"
+kubun = "通常"
+
+[routes."capture.takes.upload-url"]
+story = "S3"
+kubun = "通常"
+
+[routes."contact"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."contact.store"]
+story = "S1"
+kubun = "通常"
+
+[routes."contact.thanks"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."dashboard"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."debug.bfcache-trial"]
+kind = "画面"
+kubun = "外"
+reason = "履歴復元の実機受入確認のための検証ページであり製品の利用者が到達する画面ではないため分母に載せない"
+
+[routes."debug.bfcache-trial.away"]
+kind = "画面"
+kubun = "外"
+reason = "履歴復元の実機受入確認で離脱先に使う検証ページであり製品の利用者が到達する画面ではないため分母に載せない"
+
+[routes."debug.login"]
+kind = "画面"
+kubun = "外"
+reason = "開発環境専用のログイン補助画面であり探索は POST の debug.login-as で前提を組むため分母に載せない"
+
+[routes."debug.login-as"]
+story = "S1"
+kubun = "通常"
+
+[routes."home"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."invitations.accept"]
+kind = "画面"
+story = "S2"
+kubun = "通常"
+
+[routes."invitations.accept-in-app"]
+story = "S2"
+kubun = "通常"
+
+[routes."invitations.accept.store"]
+story = "S2"
+kubun = "通常"
+
+[routes."legal.commerce-disclosure"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."legal.privacy"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."legal.terms"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."login"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."login.store"]
+story = "S1"
+kubun = "通常"
+
+[routes."logout"]
+story = "S1"
+kubun = "通常"
+
+[routes."manage.users.index"]
+kind = "画面"
+story = "S4"
+kubun = "通常"
+
+[routes."notifications.index"]
+kind = "画面"
+story = "S6"
+kubun = "通常"
+
+[routes."notifications.open"]
+story = "S6"
+kubun = "通常"
+
+[routes."notifications.read"]
+story = "S6"
+kubun = "通常"
+
+[routes."notifications.read-all"]
+story = "S6"
+kubun = "通常"
+
+[routes."onboarding.activate-personal"]
+story = "S1"
+kubun = "通常"
+
+[routes."onboarding.billing-required"]
+kind = "画面"
+story = "S2"
+kubun = "通常"
+
+[routes."onboarding.checkout"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."organizations.api-keys.index"]
+kind = "画面"
+story = "S4"
+kubun = "通常"
+
+[routes."organizations.api-keys.revoke"]
+story = "S4"
+kubun = "通常"
+
+[routes."organizations.api-keys.sessions.index"]
+kind = "画面"
+story = "S4"
+kubun = "通常"
+
+[routes."organizations.api-keys.sessions.revoke"]
+story = "S4"
+kubun = "通常"
+
+[routes."organizations.api-keys.store"]
+story = "S4"
+kubun = "通常"
+
+[routes."organizations.create"]
+kind = "画面"
+story = "S4"
+kubun = "通常"
+
+[routes."organizations.invitations.revoke"]
+story = "S2"
+kubun = "通常"
+
+[routes."organizations.invitations.store"]
+story = "S2"
+kubun = "通常"
+
+[routes."organizations.members.destroy"]
+story = "S2"
+kubun = "通常"
+
+[routes."organizations.members.two-factor.reset"]
+story = "S2"
+kubun = "通常"
+
+[routes."organizations.members.update"]
+story = "S2"
+kubun = "通常"
+
+[routes."organizations.onboarding.cli"]
+kind = "画面"
+story = "S4"
+kubun = "通常"
+
+[routes."organizations.onboarding.mcp"]
+kind = "画面"
+story = "S4"
+kubun = "通常"
+
+[routes."organizations.settings"]
+kind = "画面"
+story = "S4"
+kubun = "通常"
+
+[routes."organizations.store"]
+story = "S4"
+kubun = "通常"
+
+[routes."organizations.switch"]
+story = "S4"
+kubun = "通常"
+
+[routes."organizations.transfer-ownership"]
+story = "S4"
+kubun = "通常"
+
+[routes."organizations.two-factor-requirement.update"]
+story = "S4"
+kubun = "通常"
+
+[routes."organizations.update"]
+story = "S4"
+kubun = "通常"
+
+[routes."passkey.confirm"]
+story = "S6"
+kubun = "通常"
+
+[routes."passkey.confirm-options"]
+kind = "JSON"
+story = "S6"
+kubun = "通常"
+
+[routes."passkey.destroy"]
+story = "S6"
+kubun = "通常"
+
+[routes."passkey.login"]
+story = "S1"
+kubun = "通常"
+
+[routes."passkey.login-options"]
+kind = "JSON"
+story = "S1"
+kubun = "通常"
+
+[routes."passkey.registration-options"]
+kind = "JSON"
+story = "S6"
+kubun = "通常"
+
+[routes."passkey.store"]
+story = "S6"
+kubun = "通常"
+
+[routes."password.confirm"]
+kind = "画面"
+story = "S6"
+kubun = "通常"
+
+[routes."password.confirm.store"]
+story = "S6"
+kubun = "通常"
+
+[routes."password.confirmation"]
+kind = "JSON"
+kubun = "外"
+reason = "再認証が有効かどうかだけを返す状態問い合わせであり画面として開く経路ではないため分母に載せない"
+
+[routes."password.email"]
+story = "S1"
+kubun = "通常"
+
+[routes."password.request"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."password.reset"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."password.update"]
+story = "S1"
+kubun = "通常"
+
+[routes."pricing"]
+kind = "画面"
+story = "S5"
+kubun = "通常"
+
+[routes."projects.categories.destroy"]
+story = "S4"
+kubun = "通常"
+
+[routes."projects.categories.index"]
+kind = "画面"
+story = "S4"
+kubun = "通常"
+
+[routes."projects.categories.reorder"]
+story = "S4"
+kubun = "通常"
+
+[routes."projects.categories.store"]
+story = "S4"
+kubun = "通常"
+
+[routes."projects.categories.update"]
+story = "S4"
+kubun = "通常"
+
+[routes."projects.create"]
+kind = "画面"
+story = "S4"
+kubun = "通常"
+
+[routes."projects.destroy"]
+story = "S4"
+kubun = "通常"
+
+[routes."projects.edit"]
+kind = "画面"
+story = "S4"
+kubun = "通常"
+
+[routes."projects.index"]
+kind = "画面"
+story = "S4"
+kubun = "通常"
+
+[routes."projects.items.destroy"]
+story = "S4"
+kubun = "通常"
+
+[routes."projects.items.store"]
+story = "S4"
+kubun = "通常"
+
+[routes."projects.items.update"]
+story = "S4"
+kubun = "通常"
+
+[routes."projects.manuals.analyze"]
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.create"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.destroy"]
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.download"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.duplicate"]
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.edit"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.jobs.show"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.preview"]
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.render"]
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.render-jobs.playback"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.render-jobs.show"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.scenario.update"]
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.show"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.source-documents.store"]
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.store"]
+story = "S3"
+kubun = "通常"
+
+[routes."projects.manuals.update"]
+story = "S3"
+kubun = "通常"
+
+[routes."projects.members.destroy"]
+story = "S4"
+kubun = "通常"
+
+[routes."projects.members.store"]
+story = "S4"
+kubun = "通常"
+
+[routes."projects.show"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
+[routes."projects.store"]
+story = "S4"
+kubun = "通常"
+
+[routes."projects.update"]
+story = "S4"
+kubun = "通常"
+
+[routes."recent-auth.confirm"]
+kind = "画面"
+story = "S6"
+kubun = "通常"
+
+[routes."recent-auth.password"]
+story = "S6"
+kubun = "通常"
+
+[routes."recent-auth.status"]
+kind = "画面"
+story = "S6"
+kubun = "通常"
+
+[routes."register"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."register.store"]
+story = "S1"
+kubun = "通常"
+
+[routes."seo.ai"]
+kind = "JSON"
+kubun = "外"
+reason = "生成 AI のクローラ向けの機械可読 route であり人が操作する画面ではないため分母に載せない"
+
+[routes."seo.llms"]
+kind = "JSON"
+kubun = "外"
+reason = "生成 AI のクローラ向けの機械可読 route であり人が操作する画面ではないため分母に載せない"
+
+[routes."seo.robots"]
+kind = "JSON"
+kubun = "外"
+reason = "クローラ向けの機械可読 route であり人が操作する画面ではないため探索の分母に載せない"
+
+[routes."seo.sitemap"]
+kind = "JSON"
+kubun = "外"
+reason = "クローラ向けの機械可読 route であり人が操作する画面ではないため探索の分母に載せない"
+
+[routes."session.status"]
+kind = "JSON"
+story = "S6"
+kubun = "通常"
+
+[routes."settings"]
+kind = "画面"
+story = "S6"
+kubun = "通常"
+
+[routes."settings.account.deletion-request.destroy"]
+story = "S6"
+kubun = "通常"
+
+[routes."settings.account.deletion-request.store"]
+story = "S6"
+kubun = "通常"
+
+[routes."settings.account.destroy"]
+story = "S6"
+kubun = "通常"
+
+[routes."settings.password.store"]
+story = "S6"
+kubun = "通常"
+
+[routes."settings.security"]
+kind = "画面"
+story = "S6"
+kubun = "通常"
+
+[routes."social.callback"]
+kind = "画面"
+kubun = "外"
+reason = "外部の識別提供者から戻る受け口であり実際の識別提供者なしには到達できないため分母に載せない"
+
+[routes."social.redirect"]
+kind = "画面"
+kubun = "外"
+reason = "外部の識別提供者へ出ていく遷移であり隔離した探索環境の外へ出てしまうため分母に載せない"
+
+[routes."two-factor.confirm"]
+story = "S6"
+kubun = "通常"
+
+[routes."two-factor.disable"]
+story = "S6"
+kubun = "通常"
+
+[routes."two-factor.enable"]
+story = "S6"
+kubun = "通常"
+
+[routes."two-factor.login"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."two-factor.login.store"]
+story = "S1"
+kubun = "通常"
+
+[routes."two-factor.qr-code"]
+kind = "JSON"
+kubun = "外"
+reason = "第二要素の秘密を図として返す開示 endpoint であり単独で開くと秘密が走行記録に残るため分母に載せない"
+
+[routes."two-factor.recovery-codes"]
+kind = "JSON"
+kubun = "外"
+reason = "復旧コードを返す開示 endpoint であり単独で開くと秘密が走行記録に残るため分母に載せない"
+
+[routes."two-factor.regenerate-recovery-codes"]
+story = "S6"
+kubun = "通常"
+
+[routes."two-factor.secret-key"]
+kind = "JSON"
+kubun = "外"
+reason = "第二要素の秘密そのものを返す開示 endpoint であり単独で開くと秘密が走行記録に残るため分母に載せない"
+
+[routes."user-password.update"]
+story = "S6"
+kubun = "通常"
+
+[routes."user-profile-information.update"]
+story = "S6"
+kubun = "通常"
+
+[routes."verification.notice"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."verification.send"]
+story = "S1"
+kubun = "通常"
+
+[routes."verification.verify"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."webhooks.ses"]
+kubun = "外"
+reason = "外部の配信基盤からの通知を受ける機械向けの受け口でありブラウザ操作で叩く経路ではないため分母に載せない"
diff --git a/.claude/skills/app-bug-hunt/inventory/notes-operations.md b/.claude/skills/app-bug-hunt/inventory/notes-operations.md
new file mode 100644
index 0000000..4e490af
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/inventory/notes-operations.md
@@ -0,0 +1,51 @@
+<!--
+  operations.md の末尾へそのまま連結される散文。人が書く (生成器は中身を読まない)。
+  **表を書かないこと** — coverage/correlate.py は operations.md を頭から走査し、
+  直近のヘッダの列割当で `|` 始まりの行を操作行として読むため、ここに表があると
+  注釈に無い行が操作として数えられる。段 2 が表の混入を drift として拒否する。
+-->
+
+## 課金ゲート allowlist と認可 (P4 反転後、要検出)
+
+`billing.*` / `billing.tickets.*` / `billing.auto-recharge.*` / `billing.contact.update` /
+`onboarding.*` / `notifications.*` は **`require-active-subscription` group の外**にある構造的
+allowlist で、未契約・支払い不健全な組織でも到達できなければならない (`routes/web.php` の
+gate group コメントが正本)。ここが 402/リダイレクトで詰むと「契約するための画面が契約して
+いないと開けない」= 詰み finding (H4)。
+
+- `billing.auto-recharge.update` / `billing.auto-recharge.setup` / `billing.contact.update` /
+  `billing.checkout` / `billing.plan.change` / `billing.tickets.checkout` の認可は Controller 冒頭の
+  `Gate::authorize('manageBilling')` (owner / admin)。member は 403、他組織はそもそも
+  current org スコープ (route parameter なし) で構造的に到達不能。
+- `onboarding.activate-personal` は `throttle:10,1` 付き。連打時に 429 が UX として
+  説明されるか (無反応にならないか) を見る。
+- 二重課金の観点は S5 の逸脱アイデア参照 (`attempt_token` 冪等 / live pending dedup)。
+
+## パスキー / ログイン手段の認可・guard 契約 (T106/T107 後、要検出)
+
+正本は `docs/auth-security-mechanisms.md` §5・§6。**認証系は IDOR・詰みが最も出やすい面**
+なので、以下の 4 つは必ず破壊を試みる。
+
+- **他人の passkey は 404** (`{passkey}` は `SelfScopedPasskeyBinder` が
+  「認証ユーザー所有 + 数値正規化」を担う explicit binder。403 で存在を漏らさない
+  = セキュリティ不変条件 2 の実装点)。**他組織・他ユーザーの passkey id を
+  `passkey.destroy` に流し込んで 404 以外が返れば finding (Critical)**。
+- **唯一のログイン手段は消せない** (`ensure-login-method` middleware)。
+  パスキーだけのユーザーが唯一の passkey を削除しようとしたとき、
+  **403 で突き放さず「先に別の手段を登録してください」と行き先が示される**こと
+  (行き先のない詰みを作らない = H4)。
+- **登録・削除は再認証の後ろ** (`RequireRecentAuth`)。再認証が切れた状態で直 POST して
+  通ったら finding。再認証を求められたとき、**パスキーしか持たないユーザーが
+  `recent-auth.confirm` で詰まない**こと (T107 の `passkeyAvailable` 配線が効いているか)。
+- **TOTP confirmed なら passkey login は拒否** (`PasskeyLoginPolicy`)。vendor の
+  `PasskeyLoginController::store()` は Fortify の two-factor challenge を通らないため、
+  TOTP を confirmed 済みのユーザーが `passkey.login` で入れたら **assurance の後退** =
+  finding (Critical)。
+- **`throttle:passkeys` / `settings.password.store` の `throttle:6,1`**。
+  連打で 429 になったとき**画面上で説明される**こと (無反応にしない)。
+
+`settings.password.store` は **SSO / パスキーのみで登録したユーザーがパスワードを
+初めて設定する経路** (T107 で新設)。既存の `user-password.update` (現行パスワード必須) とは
+別物なので、**現行パスワードを持たないユーザーが到達できること**、および
+**既にパスワードを持つユーザーがこの経路で現行パスワード検証を迂回できないこと**の
+両方を見る。
diff --git a/.claude/skills/app-bug-hunt/inventory/notes-screens.md b/.claude/skills/app-bug-hunt/inventory/notes-screens.md
new file mode 100644
index 0000000..a8b339c
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/inventory/notes-screens.md
@@ -0,0 +1,69 @@
+<!--
+  screens.md の末尾へそのまま連結される散文。人が書く (生成器は中身を読まない)。
+  表を書かないこと (連結先を読む coverage/correlate.py が操作行として拾ってしまう)。
+-->
+
+## 画面に関する既知の仕様 (散文)
+
+**非 Inertia の GET (画面ではないが分母に載せているもの)**:
+`capture.csrf-cookie` (撮影 PWA の CSRF cookie 発行) と `session.status`
+(bfcache guard `resources/js/lib/bfcache-guard.ts` が pageshow 直後に叩く
+セッション有効性プローブ。auth グループの**外**にあり guest でも 200 +
+`authenticated: false`) は Inertia ページを返さないが、ブラウザ挙動の契約に
+直結するためインベントリに残す (S3 / S6 で観測する)。
+パスキーの `passkey.*-options` 3 本も同じ扱い (次節)。
+
+## パスキー options endpoint の扱い (要検出)
+
+`passkey.*-options` の 3 本は**画面ではなく WebAuthn の challenge を返す JSON GET**
+(`capture.csrf-cookie` / `session.status` と同じ扱いで表に載せている)。
+bug-hunt はこれらを**単独で開くのではなく**、S1/S6 のパスキー操作を UI から実走した
+副作用として通過させる。加えて逸脱アイデアとして直叩きを行う:
+
+- `passkey.registration-options` / `passkey.confirm-options` は `RequireRecentAuth` /
+  auth の配下。**未ログイン・再認証切れで直叩きしたときに 401/302 で止まり、
+  challenge が漏れない**こと。
+- `passkey.login-options` は guest 配下。**メールアドレスを列挙できる応答差
+  (存在するユーザーと存在しないユーザーで応答が変わる)** が出ないこと (存在オラクル)。
+- 3 本とも `throttle:passkeys` 配下。連打時の 429 が**画面上で説明される**こと
+  (無反応で詰まないこと。H4)。
+
+## 課金ゲート着地 (P4 ゲート反転) の画面遷移
+
+> 未契約組織は業務 route group に入れない (`require-active-subscription`)。遮断時の着地は
+> **`manageBilling` 保持者 → `onboarding.checkout` / 非保持者 → `onboarding.billing-required`**
+> (正本: `docs/billing-gate-inversion-runbook.md`、運用契約: `docs/architecture.md`
+> §サブスク契約 Checkout とオンボーディング着地)。
+
+- `onboarding.checkout` は**離脱ガード付き**: 契約済み (有効 sub / free personal) は
+  `billing.index` へ、`manageBilling` 非保持者は `onboarding.billing-required` へ逃がす。
+- `onboarding.billing-required` も同様に、利用可なら `dashboard`、`manageBilling` 保持者なら
+  `onboarding.checkout` へ逃がす。**どちらの画面も「行き先のない詰み」を作らないこと**が契約で、
+  ここでループ・403・空画面が出たら finding (H4/H10)。
+- `?plan=` は org スコープ session へ積んで canonical URL へ 303 する (query が残らない)。
+  リロードしても選択が消えない (peek) こと。
+
+## ナビゲーション/レイアウト規約 (T069 左サイドバー、参照アプリ aigenba 準拠)
+
+> ログイン後の全画面は `templates/AppLayout.svelte` の**左サイドバー型シェル**を共有する
+> (設計正本: `devnotes/20260716-1757-login-sidebar-nav/`)。bug-hunt はこの構造規約への
+> 準拠を横断ヒューリスティクス H11/H13 とあわせて全認証画面で検査する。
+
+**左サイドバー nav 項目 (desktop 固定 / mobile ドロワー) — ここに出てよいもの:**
+- ダッシュボード `/dashboard`(常時)、プロジェクト `/projects`(組織あり)、
+  メンバー `/manage/users`(`canManageMembers`)、API キー `/organizations/{slug}/api-keys`(`canManageApiKeys`)、
+  請求 `/billing`(組織あり)
+
+**下部ユーザー/組織ポップアップ (SidebarUserMenu) — ここに出るべきもの (左 nav に出してはいけない):**
+- **個人設定 `/settings`**、組織設定 `/organizations/{slug}/settings`、CLI/MCP セットアップ、
+  法務(利用規約/プライバシー/特商法)、ログアウト、組織切替
+- **規約 (要検出)**: 「個人設定 `/settings`」は**下部ポップアップ専用**。左サイドバー nav 項目としては
+  出さない(T069 で設定はポップアップへ移動した)。左 nav に「設定」が重複掲載されていれば finding
+  (H10 相当: 直前設計との矛盾 / 二重掲載)。
+- 通知はベル(`notification-bell` / mobile `notification-bell-mobile`)単一導線。左 nav 項目にしない。
+
+**ページ幅/レイアウト準拠 (要検出、H11/H13):**
+- 各ページ本文はサイドバーのオフセット(desktop 256/64px、mobile 0)配下の `<main>` コンテナ内に収まり、
+  **横スクロール・要素はみ出し・レイアウト幅非準拠が無い**こと。旧レイアウトの `max-w-6xl` 中央寄せを外したため、
+  独自に幅を仮定していたページ(テーブル/ワイド要素)が新シェル幅に非準拠になっていないかを desktop/mobile で確認する。
+- desktop(≥1024)/tablet(768)/mobile(375) で本文が破綻せず、サイドバー折りたたみ(64px)時も本文幅が追従すること。
diff --git a/app/Console/Commands/Bughunt/InventoryScanCommand.php b/app/Console/Commands/Bughunt/InventoryScanCommand.php
new file mode 100644
index 0000000..d570f9a
--- /dev/null
+++ b/app/Console/Commands/Bughunt/InventoryScanCommand.php
@@ -0,0 +1,112 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Bughunt;
+
+use App\DataTransferObjects\Bughunt\InventoryRouteData;
+use App\DataTransferObjects\Bughunt\InventoryScanData;
+use Illuminate\Console\Command;
+use Illuminate\Routing\Router;
+use RuntimeException;
+
+/**
+ * bug-hunt 目録の機械事実 (route 定義と画面題名) を JSON で標準出力へ書き出す。
+ *
+ * **面の判定・分類・除外は 1 つも持たない**。それらは生成器
+ * (scripts/bug-hunt-inventory.py) の責務であり、同じ規則を 2 言語に置かない。
+ * したがって本コマンドは名前の無い route も落とさずに**全 route** を出力する。
+ */
+final class InventoryScanCommand extends Command
+{
+    protected $signature = 'bughunt:inventory-scan';
+
+    protected $description = 'bug-hunt 目録の機械事実 (route 定義と画面題名) を JSON で出力する';
+
+    public function handle(Router $router): int
+    {
+        // 抽出条件: routes/web.php の debug route 登録条件と**同一の述語**。
+        // 満たさない環境で走らせると母集合が黙って変わるため、標準出力には触れずに落とす
+        // (条件を変えるときは routes/web.php と Feature テストの両方を直すこと)。
+        if (! ($this->laravel->isLocal() || $this->laravel->runningUnitTests())) {
+            // 理由は標準エラーへ。標準出力には 1 バイトも出さない (壊れた入力を後段へ渡さない)。
+            $this->output->getErrorStyle()->error(
+                '抽出条件を満たさない環境では走らせない (local もしくはテスト実行時のみ)'
+            );
+
+            return self::FAILURE; // = 1。生成器はこれを致命 (exit 2) へ写像する
+        }
+
+        $titles = $this->appTitles();
+
+        $routes = [];
+        // getRoutes() は Route[] を返す (反復子ではなく明示の配列取得を使う)。
+        foreach ($router->getRoutes()->getRoutes() as $route) {
+            $name = $route->getName();
+            $routes[] = new InventoryRouteData(
+                name: $name,
+                uri: $route->uri(),
+                // 空文字を残すと list<non-empty-string> が成立しないので落とす。
+                // 落とした結果 methods が空になる route は生成器の段 1 が致命として拾う。
+                methods: $this->nonEmptyStrings($route->methods()),
+                // gatherMiddleware() は**宣言のまま**を返す (group 名 `web` が残る)。
+                // Router::gatherRouteMiddleware() は group を展開して `web` を消すので使わない。
+                middleware: $this->nonEmptyStrings($route->gatherMiddleware()),
+                action: $route->getActionName(),
+                title: $name === null ? null : ($titles[$name] ?? null),
+            );
+        }
+
+        $this->output->writeln(json_encode(
+            (new InventoryScanData($routes))->toArray(),
+            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
+        ));
+
+        return self::SUCCESS;
+    }
+
+    /**
+     * 画面題名 (config('seo.app_titles')) を route 名 → 題名の表として取り出す。
+     *
+     * config は mixed なので、境界でキー・値の型を検査してから DTO へ渡す。
+     *
+     * @return array<string, string>
+     */
+    private function appTitles(): array
+    {
+        $configured = config('seo.app_titles', []);
+        if (! is_array($configured)) {
+            throw new RuntimeException('config(seo.app_titles) が配列ではない');
+        }
+
+        $titles = [];
+        foreach ($configured as $name => $title) {
+            if (! is_string($name) || ! is_string($title)) {
+                throw new RuntimeException('config(seo.app_titles) のキーと値は文字列であること');
+            }
+            $titles[$name] = $title;
+        }
+
+        return $titles;
+    }
+
+    /**
+     * 文字列でない要素 (Closure 等) と空文字を落とす。
+     *
+     * PHPDoc だけで list<non-empty-string> を主張せず、ループで組み立てて推論を通す。
+     *
+     * @param  array<array-key, mixed>  $values
+     * @return list<non-empty-string>
+     */
+    private function nonEmptyStrings(array $values): array
+    {
+        $out = [];
+        foreach ($values as $value) {
+            if (is_string($value) && $value !== '') {
+                $out[] = $value;
+            }
+        }
+
+        return $out;
+    }
+}
diff --git a/app/DataTransferObjects/Bughunt/InventoryRouteData.php b/app/DataTransferObjects/Bughunt/InventoryRouteData.php
new file mode 100644
index 0000000..2462592
--- /dev/null
+++ b/app/DataTransferObjects/Bughunt/InventoryRouteData.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Bughunt;
+
+/**
+ * bug-hunt 目録の機械事実 1 件 (= route オブジェクト 1 件)。
+ *
+ * **判定を持たない**。面 (web セッション面かどうか) の判定・表の分割・除外は
+ * すべて生成器 (scripts/bug-hunt-inventory.py) の責務で、ここは事実を写すだけである
+ * (同じ規則を PHP と Python の 2 か所に置かない)。
+ */
+final readonly class InventoryRouteData
+{
+    /**
+     * @param  list<non-empty-string>  $methods  HEAD を含む宣言どおりの HTTP メソッド
+     * @param  list<non-empty-string>  $middleware  宣言のままの middleware (group 名 `web` を含みうる)
+     */
+    public function __construct(
+        public ?string $name,
+        public string $uri,
+        public array $methods,
+        public array $middleware,
+        public ?string $action,
+        public ?string $title,
+    ) {}
+
+    /**
+     * @return array{
+     *   name: string|null,
+     *   uri: string,
+     *   methods: list<non-empty-string>,
+     *   middleware: list<non-empty-string>,
+     *   action: string|null,
+     *   title: string|null
+     * }
+     */
+    public function toArray(): array
+    {
+        return [
+            'name' => $this->name,
+            'uri' => $this->uri,
+            'methods' => $this->methods,
+            'middleware' => $this->middleware,
+            'action' => $this->action,
+            'title' => $this->title,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Bughunt/InventoryScanData.php b/app/DataTransferObjects/Bughunt/InventoryScanData.php
new file mode 100644
index 0000000..d6e8f9f
--- /dev/null
+++ b/app/DataTransferObjects/Bughunt/InventoryScanData.php
@@ -0,0 +1,48 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Bughunt;
+
+/**
+ * 抽出コマンド (bughunt:inventory-scan) の出力全体。
+ *
+ * `schema_version` と `extraction_condition` は生成器が受け取り側で照合する
+ * (どちらかが食い違ったら致命として落ちる = 母集合が黙って変わることを防ぐ)。
+ */
+final readonly class InventoryScanData
+{
+    public const SCHEMA_VERSION = 1;
+
+    /** 抽出条件のラベル。環境名ではない (local 実行と Pest 実行で同一になる)。 */
+    public const EXTRACTION_CONDITION = 'local-or-unit-tests';
+
+    /** @param  list<InventoryRouteData>  $routes */
+    public function __construct(public array $routes) {}
+
+    /**
+     * @return array{
+     *   schema_version: int,
+     *   extraction_condition: non-empty-string,
+     *   routes: list<array{
+     *     name: string|null,
+     *     uri: string,
+     *     methods: list<non-empty-string>,
+     *     middleware: list<non-empty-string>,
+     *     action: string|null,
+     *     title: string|null
+     *   }>
+     * }
+     */
+    public function toArray(): array
+    {
+        return [
+            'schema_version' => self::SCHEMA_VERSION,
+            'extraction_condition' => self::EXTRACTION_CONDITION,
+            'routes' => array_map(
+                static fn (InventoryRouteData $route): array => $route->toArray(),
+                $this->routes,
+            ),
+        ];
+    }
+}
diff --git a/scripts/README.md b/scripts/README.md
index 125a266..eae608d 100644
--- a/scripts/README.md
+++ b/scripts/README.md
@@ -32,7 +32,9 @@ ## スクリプト一覧
 | `setup-browser-testing.contract.test.ts` | `setup-browser-testing.sh` の契約テスト (決定表の sandbox 実走 / 静的契約 / pin された実 Playwright の出力との突合) | `pnpm test` |
 | `run-browser-test.sh` | Browser テスト (pest-plugin-browser) を**グローバルテストロック配下**で並列上限付きで実行。**Chromium / WebKit の 2 レーンが契約** (bfcache 復元シナリオは WebKit レーンが正本)。残留 playwright run-server を前後で掃除する (`@playwright/` = bug-hunt 側は除外)。起動時に bughunt ポート `:8010..8018` の best-effort pre-flight guard を掛ける (cap=4 より広く取るのは残留検出のため) | `composer test:browser` 等から呼び出し。レーン限定は `BROWSER_TEST_LANES` / 並列度は `BROWSER_TEST_PROCESSES` |
 | `bug-hunt-shard.sh` | bug-hunt シャードオーケストレータ。隔離環境 (DB `bug_hunt(_N)` / `:8010+N`) の provision / serve / teardown と、**dev DB を wipe しないための用途別 DB wrapper + 3-way hard-deny guard** を提供する (AGENTS.md §bug-hunt) | `/app-bug-hunt` から。`self-test` は実資源に触れず guard を検証 |
-| `bug-hunt-inventory-check.sh` | bug-hunt インベントリのドリフト検知。`route:list` と `.claude/skills/app-bug-hunt/{screens,operations}.md` の差分 (新ルート未追記 / 消失) を出す (exit 3 = 差分あり) | route 追加・削除時 / bug-hunt 実行前 |
+| `bug-hunt-inventory.py` | bug-hunt 目録 (`.claude/skills/app-bug-hunt/{screens,operations}.md`) の生成器兼検査器。`generate` は実装の機械事実 + 注釈 (`inventory/annotations.toml`) + 散文 (`inventory/notes-*.md`) から 2 ファイルを作り、`check` は同じ合成をメモリ上で行って byte 比較する (**1 バイトも書かない**)。exit 0=一致 / 2=致命 / 3=ドリフト | route 追加・削除時に `generate` / CI と bug-hunt 実行前に `check` |
+| `bug-hunt-inventory-check.sh` | bug-hunt 目録のドリフト検査の起動口。判定は持たず `bug-hunt-inventory.py check` を exec するだけ (同じ規則を 2 か所に置かない) | route 追加・削除時 / bug-hunt 実行前 / CI (`php` job) |
+| `tests/test_bug_hunt_inventory.py` | `bug-hunt-inventory.py` の自己テスト (標準ライブラリのみ)。実 `php` を呼ばず fake scanner で段 1..4 と差し替えの失敗経路を検証する | `composer test` (`tests/Architecture/BughuntInventoryToolSelfTest.php` が起動) |
 | `bughunt-worktree-hook.sh` | PreToolUse(Bash) ガード。`bug-hunt-shard.sh provision` の **main 直叩き** (worktree 指紋なし) を harness 層で拒否する (拒否は終了コード 97。起動子が 97 だけを 2 へ写す)。判定は bash の組み込みだけで完結し、外部コマンドを 1 つも使わない | `.claude/settings.json` に常設配線 (AGENTS.md §常設 hook 配線) |
 | `code-review-graph-update-hook.sh` | PostToolUse(Write/Edit) hook。コード索引 (code-review-graph) を `flock` 排他 + 内側 20 秒の時間切れ付きで差分更新する。何が起きても終了コード 0 で終わり、標準出力は常に空。告知はセッションごと・理由ごとに標準エラー 1 行だけ | `.claude/settings.json` に常設配線 (AGENTS.md §常設 hook 配線) |
 | `claude` | Claude Code を VSCode 拡張のネイティブバイナリ経由で起動 | (内部スクリプト) |
diff --git a/scripts/bug-hunt-inventory-check.sh b/scripts/bug-hunt-inventory-check.sh
index 5ef8d5d..ee02d6e 100755
--- a/scripts/bug-hunt-inventory-check.sh
+++ b/scripts/bug-hunt-inventory-check.sh
@@ -1,97 +1,13 @@
 #!/usr/bin/env bash
 #
-# scripts/bug-hunt-inventory-check.sh — bug-hunt インベントリ ドリフト検知 (テンプレート版)
+# scripts/bug-hunt-inventory-check.sh — bug-hunt 目録のドリフト検査 (起動のみ)
 #
-# 設計: 参照実装 (派生アプリ) の bug-hunt 基盤の汎用移植
+# 判定は scripts/bug-hunt-inventory.py に一本化してある。**このスクリプトに判定を戻さない**
+# (同じ規則が 2 か所に増えると必ず食い違う)。
 #
-# route:list の GET×inertia(web) / 非GET×web 集合と
-# .claude/skills/app-bug-hunt/{screens.md,operations.md} の差分を検出する。
-# 新ルート未追記・消失ルート未削除を warning 出力する (手動運用が既定、CI 任意)。
-#
-# 使い方: scripts/bug-hunt-inventory-check.sh   (exit 0=差分なし、3=差分あり)
+# 使い方: scripts/bug-hunt-inventory-check.sh
+#   exit 0=一致 / 2=致命 (抽出不能・抽出条件不一致・母集合 0 件等) / 3=ドリフト
 set -euo pipefail
 
 WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
-cd "${WORKSPACE}"
-
-SKILL_DIR=".claude/skills/app-bug-hunt"
-SCREENS="${SKILL_DIR}/screens.md"
-OPS="${SKILL_DIR}/operations.md"
-
-drift=0
-
-# screens.md / operations.md が「設計上ブラウザ非対象」と明記しているルート名 prefix。
-# これらは UX ブラウザ監査の対象外として意図的にインベントリ表から外しているため、
-# drift 検出 (新ルート未追記) からも除外する。新たに非対象を増やす場合は両方を更新すること。
-# filament.* は S9 (管理画面) が screens.md/operations.md に手動メンテで載せる admin guard ルート。
-# route:list 抽出側 (forward) は uri prefix 'admin' で既に除外済み。reverse (消失検知) でも除外し、
-# admin インベントリ行が誤って「消失候補」warning にならないようにする。
-OUT_OF_SCOPE_PREFIXES='seo.|social.|recent-auth.sso.|two-factor.qr-code|two-factor.secret-key|two-factor.recovery-codes|password.confirmation|cashier.|passport.|livewire|default-livewire|mcp.|oauth.|webhooks.|sanctum.|filament.'
-
-# 対象 GET×inertia(web) のルート名 (admin/api/debug/mcp/seo/oauth/xhr-only 等は除外)。
-get_screen_names() {
-    php artisan route:list --json | OOS="${OUT_OF_SCOPE_PREFIXES}" python3 -c "
-import json,os,re,sys
-oos=re.compile('^('+os.environ['OOS']+')')
-for r in json.load(sys.stdin):
-    if 'GET' not in r['method']: continue
-    uri=r['uri']; mw=str(r.get('middleware',[]))
-    if uri.startswith(('api/','admin','_','.well-known','storage','sanctum','livewire','oauth','mcp')) or 'debug' in uri: continue
-    if 'web' not in mw: continue
-    name=r.get('name')
-    if not name or oos.match(name): continue
-    print(name)" | sort -u
-}
-
-# 対象 非GET×web の操作名 (webhook/passport/livewire/out-of-scope は除外)。
-get_op_names() {
-    php artisan route:list --json | OOS="${OUT_OF_SCOPE_PREFIXES}" python3 -c "
-import json,os,re,sys
-oos=re.compile('^('+os.environ['OOS']+')')
-for r in json.load(sys.stdin):
-    m=r['method'].split('|')[0]
-    if m in ('GET','HEAD','OPTIONS'): continue
-    mw=str(r.get('middleware',[])); name=r.get('name')
-    if 'web' not in mw or not name: continue
-    if oos.match(name) or 'webhook' in name: continue
-    print(name)" | sort -u
-}
-
-check() {
-    local label=$1 file=$2; shift 2
-    local names; names="$("$@")"
-    echo "== ${label} =="
-    local n
-    while IFS= read -r n; do
-        [[ -z "${n}" ]] && continue
-        if ! grep -qF "${n}" "${file}"; then
-            echo "  [新ルート未追記] ${n} が ${file} に無い"
-            drift=3
-        fi
-    done <<< "${names}"
-    # file に書かれた route 名が route:list から消えていないか (簡易: name 列を抽出して照合)
-    local listed
-    listed="$(grep -oE '[a-z0-9-]+\.[a-z0-9.-]+|^\| `?/' "${file}" 2>/dev/null || true)"
-    # 消失検知は名前トークン単位で行う (誤検知を避けるため warning のみ)
-    while IFS= read -r tok; do
-        [[ -z "${tok}" ]] && continue
-        # out-of-scope として表に記録した名前は消失検知から除外する。
-        echo "${tok}" | grep -qE "^(${OUT_OF_SCOPE_PREFIXES})" && continue
-        case "${tok}" in
-            *.*)
-                if ! echo "${names}" | grep -qF "${tok}"; then
-                    echo "  [消失候補] ${file} の '${tok}' が現 route:list に無い (削除漏れの可能性)"
-                fi
-                ;;
-        esac
-    done < <(grep -oE '\| [a-z0-9-]+\.[a-z0-9.-]+ ' "${file}" | tr -d '| ' | sort -u)
-}
-
-check "screens (GET×inertia)" "${SCREENS}" get_screen_names
-check "operations (非GET×web)" "${OPS}" get_op_names
-
-if [[ "${drift}" == 3 ]]; then
-    echo "drift 検出: インベントリと route:list に差分あり (上記を確認)"
-    exit 3
-fi
-echo "drift なし: インベントリは route:list と整合"
+exec python3 "${WORKSPACE}/scripts/bug-hunt-inventory.py" check "$@"
diff --git a/scripts/bug-hunt-inventory.py b/scripts/bug-hunt-inventory.py
new file mode 100644
index 0000000..b5ca38d
--- /dev/null
+++ b/scripts/bug-hunt-inventory.py
@@ -0,0 +1,699 @@
+#!/usr/bin/env python3
+"""bug-hunt 目録 (画面一覧 / 操作一覧) の生成器兼検査器。
+
+目録は**生成物**である。実装から取れる機械事実 (route 定義 / 画面題名) と、人が書く注釈
+(`inventory/annotations.toml`) と散文 (`inventory/notes-*.md`) を合成して
+`.claude/skills/app-bug-hunt/{screens,operations}.md` を作る。
+
+    generate … 段 1 → 2 → 4 を通してから 2 ファイルを書き替える
+    check    … 段 1 → 2 → 3 → 4 を通す。**1 バイトも書かない**
+
+    段 1 (抽出)         抽出コマンドが成功し、宣言した抽出条件で走り、母集合が 0 件でない
+    段 2 (注釈)         注釈の集合 = 面の集合。語彙・必須・形式・複合 method を検査する
+    段 3 (生成物)       メモリ上で再生成した内容と現物を byte 比較する
+    段 4 (機能カタログ) capability-catalog.md の代表機構が実在し、id が重複しない
+
+終了コード: 0=一致 / 2=致命 (抽出不能・抽出条件不一致・母集合 0 件・空名・重複名・
+入力ファイル不在・壊れた TOML・想定外例外) / 3=ドリフト (段 2 / 3 / 4 の違反)。
+**1 と 4 以上は使わない** (argparse が引数エラーで返す 2 は「致命」の側に落ちる)。
+
+保証しないもの: 見るのは web group を宣言した面だけである (機械向け API / Filament 管理画面 /
+MCP / webhook には沈黙する)。注釈の**内容**の妥当性・画面題名の欠落・機能カタログの網羅性も見ない。
+
+依存は標準ライブラリのみ (AGENTS.md §bug-hunt)。
+"""
+from __future__ import annotations
+
+import argparse
+import json
+import os
+import re
+import subprocess
+import sys
+import tomllib
+import traceback
+from dataclasses import dataclass
+from pathlib import Path
+from typing import Callable
+
+# --------------------------------------------------------------------------- #
+# 語彙と定数 (規則の正本)
+# --------------------------------------------------------------------------- #
+STAGE1, STAGE2, STAGE3, STAGE4 = "抽出", "注釈", "生成物", "機能カタログ"
+EXIT_OK, EXIT_FATAL, EXIT_DRIFT = 0, 2, 3
+
+SCHEMA_VERSION = 1
+# PHP 側 App\DataTransferObjects\Bughunt\InventoryScanData::EXTRACTION_CONDITION と一致させる。
+EXTRACTION_CONDITION = "local-or-unit-tests"
+
+# 面から除く先頭セグメント。**この 2 つだけ**にする (死んだ除外規則を並べない)。
+SURFACE_EXCLUDED_SEGMENTS = ("oauth",)          # 先頭セグメント完全一致
+SURFACE_EXCLUDED_PREFIXES = ("livewire",)       # 先頭セグメントの前方一致 (livewire-{hash})
+
+KUBUN_VOCABULARY = ("通常", "逸", "終", "外")
+# coverage/correlate.py の定数と一致させる (自己テストが import して照合する)。
+KUBUN_OUT_OF_SCOPE, KUBUN_DEVIATE = "外", "逸"
+KUBUN_NEEDS_STORY = ("通常", "逸")
+KUBUN_NEEDS_REASON = ("外", "終")
+
+STORY_IDS = tuple(f"S{i}" for i in range(1, 8))
+SCREEN_KINDS = ("画面", "JSON")
+ANNOTATION_KEYS = ("kind", "story", "kubun", "reason")
+REASON_MIN_LENGTH = 30
+
+GET_LIKE_METHODS = ("GET", "HEAD", "OPTIONS")
+
+# 表のセルへ出る値に許さない文字 (correlate.py が split("|") で読むためエスケープ規約は作らない)。
+FORBIDDEN_CELL_CHARS = ("|", "\r", "\n")
+# 箇条書きへ出る理由に許さない文字 (制御文字)。
+CONTROL_CHAR_RE = re.compile(r"[\x00-\x1f\x7f]")
+
+CAPABILITY_TABLE_HEADER = "| id | 機能 (actor→outcome) | 代表機構 (route name) |"
+CAPABILITY_ID_RE = re.compile(r"^[A-Z]{2,5}-[0-9]{2}$")
+BACKTICK_TOKEN_RE = re.compile(r"`([^`]+)`")
+
+SKILL_DIR = Path(".claude/skills/app-bug-hunt")
+ANNOTATIONS_PATH = SKILL_DIR / "inventory" / "annotations.toml"
+NOTES_SCREENS_PATH = SKILL_DIR / "inventory" / "notes-screens.md"
+NOTES_OPERATIONS_PATH = SKILL_DIR / "inventory" / "notes-operations.md"
+SCREENS_PATH = SKILL_DIR / "screens.md"
+OPERATIONS_PATH = SKILL_DIR / "operations.md"
+CATALOG_PATH = SKILL_DIR / "capability-catalog.md"
+
+GENERATED_NOTICE = (
+    "> **このファイルは生成物である。手で編集しない。**\n"
+    "> 直し方: `.claude/skills/app-bug-hunt/inventory/annotations.toml` (割当・区分・理由) か\n"
+    "> `inventory/notes-*.md` (散文) を直してから "
+    "`python3 scripts/bug-hunt-inventory.py generate` を走らせる。\n"
+    "> 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。\n"
+    "> ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。\n"
+)
+
+Scanner = Callable[[Path], object]
+
+
+class FatalError(Exception):
+    """検査を成立させられない状態 (終了コード 2)。"""
+
+
+@dataclass(frozen=True)
+class RouteFact:
+    """抽出できた route 1 件の機械事実。"""
+
+    name: str
+    uri: str
+    methods: tuple[str, ...]
+    title: str | None
+
+    @property
+    def write_methods(self) -> tuple[str, ...]:
+        """非 GET のメソッド (昇順)。"""
+        return tuple(sorted(m for m in self.methods if m not in GET_LIKE_METHODS))
+
+
+@dataclass(frozen=True)
+class Facts:
+    """段 1 が確定させた母集合。"""
+
+    screens: tuple[RouteFact, ...]
+    operations: tuple[RouteFact, ...]
+    compound: tuple[str, ...]      # GET/HEAD と非 GET を併せ持つ route 名
+    all_names: frozenset[str]      # 面に限らない全 route 名 (段 4 の照合母集合)
+
+    @property
+    def surface(self) -> tuple[RouteFact, ...]:
+        return self.screens + self.operations
+
+
+# --------------------------------------------------------------------------- #
+# 段 1: 抽出
+# --------------------------------------------------------------------------- #
+def scan(repo_root: Path) -> object:
+    """`php artisan bughunt:inventory-scan` を走らせて JSON を読む。"""
+    try:
+        proc = subprocess.run(
+            ["php", "artisan", "bughunt:inventory-scan"],
+            cwd=repo_root,
+            capture_output=True,
+            text=True,
+        )
+    except OSError as exc:
+        raise FatalError(f"[{STAGE1}] 抽出コマンドを起動できない: {exc}") from exc
+
+    if proc.returncode != 0:
+        raise FatalError(
+            f"[{STAGE1}] 抽出コマンドが非 0 終了 (code={proc.returncode}): "
+            f"{proc.stderr.strip() or '(標準エラーなし)'}"
+        )
+    try:
+        return json.loads(proc.stdout)
+    except json.JSONDecodeError as exc:
+        raise FatalError(f"[{STAGE1}] 抽出結果が JSON として読めない: {exc}") from exc
+
+
+def _is_excluded_surface(uri: str) -> bool:
+    """面から除く先頭セグメントか。"""
+    segment = uri.split("/", 1)[0]
+    if segment in SURFACE_EXCLUDED_SEGMENTS:
+        return True
+    return any(segment.startswith(prefix) for prefix in SURFACE_EXCLUDED_PREFIXES)
+
+
+def split_surface(data: object) -> Facts:
+    """抽出結果から web 面を切り出し、画面表と操作表へ排他的に分ける。"""
+    if not isinstance(data, dict):
+        raise FatalError(f"[{STAGE1}] 抽出結果が表ではない")
+    if data.get("schema_version") != SCHEMA_VERSION:
+        raise FatalError(
+            f"[{STAGE1}] schema_version が {SCHEMA_VERSION} ではない: {data.get('schema_version')!r}"
+        )
+    if data.get("extraction_condition") != EXTRACTION_CONDITION:
+        raise FatalError(
+            f"[{STAGE1}] 抽出条件が {EXTRACTION_CONDITION!r} ではない: "
+            f"{data.get('extraction_condition')!r}"
+        )
+    routes = data.get("routes")
+    if not isinstance(routes, list):
+        raise FatalError(f"[{STAGE1}] routes が並びではない")
+
+    all_names: list[str] = []
+    screens: list[RouteFact] = []
+    operations: list[RouteFact] = []
+    compound: list[str] = []
+
+    for raw in routes:
+        if not isinstance(raw, dict):
+            raise FatalError(f"[{STAGE1}] route の項目が表ではない: {raw!r}")
+        name = raw.get("name")
+        uri = raw.get("uri")
+        methods = raw.get("methods")
+        middleware = raw.get("middleware")
+        title = raw.get("title")
+        if not isinstance(uri, str) or not isinstance(methods, list) or not isinstance(middleware, list):
+            raise FatalError(f"[{STAGE1}] route の項目の形が契約外: {raw!r}")
+        if name is not None and not isinstance(name, str):
+            raise FatalError(f"[{STAGE1}] route 名が文字列でも空でもない: {raw!r}")
+        if title is not None and not isinstance(title, str):
+            raise FatalError(f"[{STAGE1}] 画面題名が文字列でも空でもない: {raw!r}")
+
+        if isinstance(name, str) and name != "":
+            all_names.append(name)
+
+        # 面の判定: middleware の**要素**に group 名 `web` があること (文字列化した部分一致にしない)。
+        if "web" not in middleware or _is_excluded_surface(uri):
+            continue
+        if not isinstance(name, str) or name == "":
+            raise FatalError(
+                f"[{STAGE1}] web 面の route に名前が無い (目録の join キーを作れない): {uri}"
+            )
+        if not methods:
+            raise FatalError(f"[{STAGE1}] web 面の route に HTTP メソッドが 1 つも無い: {name}")
+
+        fact = RouteFact(name=name, uri=uri, methods=tuple(str(m) for m in methods), title=title)
+        if fact.write_methods:
+            operations.append(fact)
+            # GET / HEAD と非 GET の併存は現在の注釈モデルで表せない (段 2 が drift にする)。
+            # 黙って画面の分母から落とさないよう、操作表側へ入れたうえで印を付ける。
+            if any(m in ("GET", "HEAD") for m in fact.methods):
+                compound.append(name)
+        else:
+            screens.append(fact)
+
+    duplicates = sorted({n for n in all_names if all_names.count(n) > 1})
+    if duplicates:
+        raise FatalError(f"[{STAGE1}] route 名が重複している: {', '.join(duplicates)}")
+    if not screens and not operations:
+        raise FatalError(f"[{STAGE1}] web 面の母集合が 0 件 (抽出が壊れた走行を緑にしない)")
+
+    return Facts(
+        screens=tuple(sorted(screens, key=lambda f: f.name)),
+        operations=tuple(sorted(operations, key=lambda f: f.name)),
+        compound=tuple(sorted(compound)),
+        all_names=frozenset(all_names),
+    )
+
+
+# --------------------------------------------------------------------------- #
+# 段 2: 注釈
+# --------------------------------------------------------------------------- #
+def load_annotations(path: Path) -> dict[str, dict[str, object]]:
+    """注釈 TOML を読む (読み取り専用。生成器は注釈ファイルを書き換えない)。"""
+    if not path.is_file():
+        raise FatalError(f"[{STAGE2}] 注釈ファイルが無い: {path}")
+    try:
+        data = tomllib.loads(path.read_text(encoding="utf-8"))
+    except tomllib.TOMLDecodeError as exc:
+        raise FatalError(f"[{STAGE2}] 注釈ファイルが TOML として読めない: {exc}") from exc
+
+    if data.get("schema_version") != SCHEMA_VERSION:
+        raise FatalError(
+            f"[{STAGE2}] 注釈の schema_version が {SCHEMA_VERSION} ではない: "
+            f"{data.get('schema_version')!r}"
+        )
+    routes = data.get("routes", {})
+    if not isinstance(routes, dict):
+        raise FatalError(f"[{STAGE2}] 注釈の routes が表ではない")
+    for name, entry in routes.items():
+        if not isinstance(entry, dict):
+            raise FatalError(f"[{STAGE2}] 注釈 {name} が表ではない")
+
+    unknown_top = sorted(k for k in data if k not in ("schema_version", "routes"))
+    if unknown_top:
+        # 書いたのに効かない項目を残さない (打ち間違いを黙って捨てない)。
+        raise FatalError(f"[{STAGE2}] 未知のトップレベル項目: {', '.join(unknown_top)}")
+
+    return {str(name): dict(entry) for name, entry in routes.items()}
+
+
+def _annotation_value(entry: dict[str, object], key: str) -> str | None:
+    value = entry.get(key)
+    return value if isinstance(value, str) else None
+
+
+def validate_annotations(facts: Facts, annotations: dict[str, dict[str, object]]) -> list[str]:
+    """注釈の定義域一致・語彙・形式・複合 method を検査し、違反行を全件返す。"""
+    violations: list[str] = []
+
+    screen_names = {f.name for f in facts.screens}
+    surface_names = {f.name for f in facts.surface}
+
+    for name in sorted(surface_names - set(annotations)):
+        violations.append(f"[{STAGE2}] 未注釈の route: {name}")
+    for name in sorted(set(annotations) - surface_names):
+        violations.append(f"[{STAGE2}] 実装に無い route の注釈が残っている: {name}")
+
+    for name in facts.compound:
+        violations.append(
+            f"[{STAGE2}] GET/HEAD と非 GET を併せ持つ route は現在の注釈モデルで表せない: {name}"
+        )
+
+    for name in sorted(surface_names & set(annotations)):
+        entry = annotations[name]
+        prefix = f"[{STAGE2}] {name}:"
+
+        unknown = sorted(k for k in entry if k not in ANNOTATION_KEYS)
+        if unknown:
+            violations.append(f"{prefix} 未知の項目: {', '.join(unknown)}")
+
+        kubun = _annotation_value(entry, "kubun")
+        if kubun is None:
+            violations.append(f"{prefix} kubun が無い")
+        elif kubun not in KUBUN_VOCABULARY:
+            violations.append(f"{prefix} 未知の区分: {kubun} (許すのは {'/'.join(KUBUN_VOCABULARY)})")
+
+        kind = _annotation_value(entry, "kind")
+        if name in screen_names:
+            if kind is None:
+                violations.append(f"{prefix} 画面表の route には kind が要る")
+            elif kind not in SCREEN_KINDS:
+                violations.append(f"{prefix} 未知の種別: {kind} (許すのは {'/'.join(SCREEN_KINDS)})")
+        elif kind is not None:
+            violations.append(f"{prefix} 操作表の route に kind は書けない")
+
+        story = _annotation_value(entry, "story")
+        if kubun in KUBUN_NEEDS_STORY:
+            if story is None:
+                violations.append(f"{prefix} 区分 {kubun} には story が要る")
+            elif story not in STORY_IDS:
+                violations.append(f"{prefix} 未知のストーリー: {story}")
+        elif kubun in KUBUN_NEEDS_REASON and story is not None:
+            # 表では `-` に潰れて見えなくなる古い割当を残さない。
+            violations.append(f"{prefix} 区分 {kubun} に story は書けない")
+
+        reason = _annotation_value(entry, "reason")
+        if kubun in KUBUN_NEEDS_REASON:
+            if reason is None:
+                violations.append(f"{prefix} 区分 {kubun} には理由が要る")
+            elif len(reason) < REASON_MIN_LENGTH:
+                violations.append(
+                    f"{prefix} 理由が {REASON_MIN_LENGTH} 文字未満 ({len(reason)} 文字)"
+                )
+            elif CONTROL_CHAR_RE.search(reason):
+                violations.append(f"{prefix} 理由に制御文字が入っている")
+        elif reason is not None:
+            violations.append(f"{prefix} 区分 {kubun} に理由は書けない")
+
+        for key in ("kind", "story", "kubun"):
+            value = _annotation_value(entry, key)
+            if value is not None and any(c in value for c in FORBIDDEN_CELL_CHARS):
+                violations.append(f"{prefix} {key} に表を壊す文字 (| / 改行) が入っている")
+
+    for fact in facts.surface:
+        for label, value in (("route 名", fact.name), ("URL", fact.uri), ("画面名", fact.title or "")):
+            if any(c in value for c in FORBIDDEN_CELL_CHARS):
+                violations.append(
+                    f"[{STAGE2}] {fact.name}: {label} に表を壊す文字 (| / 改行) が入っている"
+                )
+
+    return violations
+
+
+def check_notes(notes_operations: str) -> list[str]:
+    """散文ノートが下流ローダを騙す表を持たないことを検査する。
+
+    `coverage/correlate.py` は operations.md を頭から走査し、直近に見たヘッダの列割当で
+    以降の `|` 始まりの行を操作行として読む。連結される notes-operations.md に表があると
+    注釈に無い行が操作として読まれてしまうため、**表そのものを置かせない**。
+    """
+    violations = []
+    for lineno, raw in enumerate(notes_operations.splitlines(), start=1):
+        if raw.strip().startswith("|"):
+            violations.append(
+                f"[{STAGE2}] notes-operations.md {lineno} 行目: 散文ノートに表を置かない "
+                "(correlate.py が操作行として読んでしまう)"
+            )
+    return violations
+
+
+# --------------------------------------------------------------------------- #
+# 段 3 の素材: 生成物のレンダリング
+# --------------------------------------------------------------------------- #
+def _story_cell(entry: dict[str, object]) -> str:
+    story = _annotation_value(entry, "story")
+    return story if story is not None else "-"
+
+
+def _out_of_scope_section(
+    routes: tuple[RouteFact, ...], annotations: dict[str, dict[str, object]]
+) -> str:
+    lines = ["## 対象外の理由", ""]
+    rows = [
+        f"- `{fact.name}` — {_annotation_value(annotations[fact.name], 'reason')}"
+        for fact in routes
+        if _annotation_value(annotations[fact.name], "kubun") in KUBUN_NEEDS_REASON
+    ]
+    lines.extend(rows if rows else ["対象外に分類した route は無い。"])
+
+    return "\n".join(lines) + "\n"
+
+
+def render_screens(
+    facts: Facts, annotations: dict[str, dict[str, object]], notes: str
+) -> str:
+    out_of_scope = sum(
+        1
+        for fact in facts.screens
+        if _annotation_value(annotations[fact.name], "kubun") in KUBUN_NEEDS_REASON
+    )
+    lines = [
+        "# 画面インベントリ (screens.md) — AI-CUE",
+        "",
+        GENERATED_NOTICE.rstrip("\n"),
+        "",
+        f"bug-hunt カバレッジの分母となる「画面」(GET × web セッション面) の一覧。"
+        f"全 {len(facts.screens)} 件 (うち対象外 {out_of_scope} 件)。",
+        "",
+        "## GET × web 一覧 (画面 + 画面に付随する JSON GET)",
+        "",
+        "| route (URL) | name | 種別 | 画面名 | 割当ストーリー | 区分 |",
+        "|---|---|---|---|---|---|",
+    ]
+    for fact in facts.screens:
+        entry = annotations[fact.name]
+        lines.append(
+            f"| {fact.uri} | {fact.name} | {_annotation_value(entry, 'kind')} | "
+            f"{fact.title or '-'} | {_story_cell(entry)} | {_annotation_value(entry, 'kubun')} |"
+        )
+    body = "\n".join(lines) + "\n"
+
+    return body + "\n" + _out_of_scope_section(facts.screens, annotations) + "\n" + notes
+
+
+def render_operations(
+    facts: Facts, annotations: dict[str, dict[str, object]], notes: str
+) -> str:
+    out_of_scope = sum(
+        1
+        for fact in facts.operations
+        if _annotation_value(annotations[fact.name], "kubun") in KUBUN_NEEDS_REASON
+    )
+    lines = [
+        "# 操作インベントリ (operations.md) — AI-CUE",
+        "",
+        GENERATED_NOTICE.rstrip("\n"),
+        "",
+        f"bug-hunt カバレッジの分母となる「書き込み操作」(非 GET × web セッション面) の一覧。"
+        f"全 {len(facts.operations)} 件 (うち対象外 {out_of_scope} 件)。"
+        "列は method / route / name / story / 区分 の 5 列固定 "
+        "(coverage/correlate.py の入力契約。ヘッダ名を変えない)。",
+        "",
+        "## 操作一覧 (web セッション面)",
+        "",
+        "| method | route | name | story | 区分 |",
+        "|---|---|---|---|---|",
+    ]
+    for fact in facts.operations:
+        entry = annotations[fact.name]
+        lines.append(
+            f"| {','.join(fact.write_methods)} | {fact.uri} | {fact.name} | "
+            f"{_story_cell(entry)} | {_annotation_value(entry, 'kubun')} |"
+        )
+    body = "\n".join(lines) + "\n"
+
+    return body + "\n" + _out_of_scope_section(facts.operations, annotations) + "\n" + notes
+
+
+# --------------------------------------------------------------------------- #
+# 段 4: 機能カタログの参照整合
+# --------------------------------------------------------------------------- #
+def check_catalog(catalog_text: str, facts: Facts) -> list[str]:
+    """capability-catalog.md の代表機構が実在し、id が重複しないことを検査する。
+
+    対象はヘッダが CAPABILITY_TABLE_HEADER の表**だけ** (責務境界・割当規則の表は見ない)。
+    網羅性 (すべての route が id を持つか) は見ない (overlay なので網羅を主張しない)。
+    """
+    violations: list[str] = []
+    seen: list[str] = []
+    inside = False
+
+    for raw in catalog_text.splitlines():
+        line = raw.strip()
+        if line == CAPABILITY_TABLE_HEADER:
+            inside = True
+            continue
+        if not inside:
+            continue
+        if not line.startswith("|"):
+            inside = False
+            continue
+        cols = [c.strip() for c in line.strip("|").split("|")]
+        if len(cols) < 3 or set("".join(cols)) <= set("- "):
+            continue
+
+        capability_id, mechanisms = cols[0], cols[2]
+        if not CAPABILITY_ID_RE.match(capability_id):
+            violations.append(f"[{STAGE4}] id の書式が契約外: {capability_id}")
+        elif capability_id in seen:
+            violations.append(f"[{STAGE4}] id が重複している: {capability_id}")
+        seen.append(capability_id)
+
+        for token in BACKTICK_TOKEN_RE.findall(mechanisms):
+            token = token.strip()
+            # パス (routes/api.php) は route 名候補にしない。丸括弧の説明はそもそも
+            # バッククォートで囲まれていないので候補に入らない。
+            if "/" in token:
+                continue
+            if token.endswith("*"):
+                if not any(n.startswith(token[:-1]) for n in facts.all_names):
+                    violations.append(
+                        f"[{STAGE4}] {capability_id}: 前方一致する route が 1 件も無い: {token}"
+                    )
+            elif token not in facts.all_names:
+                violations.append(f"[{STAGE4}] {capability_id}: 実在しない route 名: {token}")
+
+    if not seen:
+        raise FatalError(f"[{STAGE4}] 機能カタログの表が見つからない (ヘッダが変わっていないか)")
+
+    return violations
+
+
+# --------------------------------------------------------------------------- #
+# 差し替え (generate)
+# --------------------------------------------------------------------------- #
+def _read_text(path: Path, stage: str) -> str:
+    if not path.is_file():
+        raise FatalError(f"[{stage}] 入力ファイルが無い: {path}")
+
+    return path.read_text(encoding="utf-8")
+
+
+def _replace_atomically(pairs: list[tuple[Path, str]]) -> None:
+    """2 ファイルを、部分的な成果物を残さずに差し替える。
+
+    完全な多ファイル原子性は作らない (生成物 2 つに対して過剰)。保証するのは
+    「通常の置換失敗では呼び出し前の 2 ファイルが byte 単位で保たれる」ことまでで、
+    復元にも失敗したら**元 / 一時 / 控えのどれも消さず**に全パスを標準エラーへ出す。
+    """
+    temps: list[Path] = []
+    # 元ファイルが未生成のときの控えは None (「無かった」状態へ戻せるようにする)。
+    backups: list[Path | None] = []
+
+    def cleanup(paths: list[Path] | list[Path | None]) -> None:
+        for path in paths:
+            if path is None:
+                continue
+            try:
+                path.unlink()
+            except OSError:
+                pass
+
+    try:
+        for path, content in pairs:
+            temp = path.with_suffix(path.suffix + ".tmp-generate")
+            temp.write_text(content, encoding="utf-8", newline="\n")
+            temps.append(temp)
+        for path in (p for p, _ in pairs):
+            if not path.is_file():
+                backups.append(None)
+                continue
+            backup = path.with_suffix(path.suffix + ".bak-generate")
+            backup.write_bytes(path.read_bytes())
+            backups.append(backup)
+    except OSError as exc:
+        cleanup(temps)
+        cleanup(backups)
+        raise FatalError(f"[{STAGE3}] 生成物の準備に失敗した (元ファイルは無傷): {exc}") from exc
+
+    replaced = 0
+    try:
+        for index, (path, _) in enumerate(pairs):
+            os.replace(temps[index], path)
+            replaced += 1
+    except OSError as exc:
+        if replaced == 0:
+            cleanup(temps)
+            cleanup(backups)
+            raise FatalError(
+                f"[{STAGE3}] 生成物の差し替えに失敗した (元ファイルは無傷。再実行せよ): {exc}"
+            ) from exc
+        try:
+            for index in range(replaced):
+                backup = backups[index]
+                if backup is None:
+                    # 元は存在しなかったので「無かった」状態へ戻す。
+                    pairs[index][0].unlink()
+                else:
+                    os.replace(backup, pairs[index][0])
+        except OSError as restore_exc:
+            print(
+                f"[{STAGE3}] 差し替えの復元に失敗した。手で戻すこと (何も消していない):\n"
+                + "\n".join(
+                    f"  元={pairs[i][0]} 一時={temps[i]} 控え={backups[i]}"
+                    for i in range(len(pairs))
+                ),
+                file=sys.stderr,
+            )
+            raise FatalError(f"[{STAGE3}] 復元にも失敗した: {restore_exc}") from restore_exc
+        cleanup(temps[replaced:])
+        cleanup(backups[replaced:])
+        raise FatalError(
+            f"[{STAGE3}] 生成物の差し替えに失敗し、控えから元へ戻した (再実行せよ): {exc}"
+        ) from exc
+
+    try:
+        for backup in backups:
+            if backup is not None:
+                backup.unlink()
+    except OSError as exc:
+        # 生成の成功は取り消さない。残ったパスを明示する。
+        print(
+            f"[{STAGE3}] 控えの削除に失敗した (生成は完了している。手で消すこと): "
+            + ", ".join(str(b) for b in backups if b is not None),
+            file=sys.stderr,
+        )
+        raise FatalError(f"[{STAGE3}] 控えの削除に失敗した: {exc}") from exc
+
+
+# --------------------------------------------------------------------------- #
+# 公開 entry
+# --------------------------------------------------------------------------- #
+def _prepare(repo_root: Path, scanner: Scanner | None) -> tuple[Facts, dict[str, dict[str, object]], str, str]:
+    """段 1 と入力の読み込みまでを行う。"""
+    facts = split_surface((scanner or scan)(repo_root))
+    annotations = load_annotations(repo_root / ANNOTATIONS_PATH)
+    notes_screens = _read_text(repo_root / NOTES_SCREENS_PATH, STAGE2)
+    notes_operations = _read_text(repo_root / NOTES_OPERATIONS_PATH, STAGE2)
+
+    return facts, annotations, notes_screens, notes_operations
+
+
+def _report(violations: list[str]) -> int:
+    for line in violations:
+        print(line, file=sys.stderr)
+    print(f"ドリフト {len(violations)} 件 (再生成するか注釈を直すこと)", file=sys.stderr)
+
+    return EXIT_DRIFT
+
+
+def run_check(repo_root: Path, *, scanner: Scanner | None = None) -> int:
+    """段 1 → 2 → 3 → 4 を通す。**1 バイトも書かない**。"""
+    facts, annotations, notes_screens, notes_operations = _prepare(repo_root, scanner)
+
+    violations = validate_annotations(facts, annotations) + check_notes(notes_operations)
+    if violations:
+        return _report(violations)
+
+    for path, rendered in (
+        (repo_root / SCREENS_PATH, render_screens(facts, annotations, notes_screens)),
+        (repo_root / OPERATIONS_PATH, render_operations(facts, annotations, notes_operations)),
+    ):
+        if _read_text(path, STAGE3) != rendered:
+            violations.append(
+                f"[{STAGE3}] 生成物が再生成の結果と一致しない: {path.name} "
+                "(python3 scripts/bug-hunt-inventory.py generate を走らせること)"
+            )
+
+    violations += check_catalog(_read_text(repo_root / CATALOG_PATH, STAGE4), facts)
+    if violations:
+        return _report(violations)
+
+    print(
+        f"一致: 画面 {len(facts.screens)} 件 / 操作 {len(facts.operations)} 件 "
+        f"(抽出条件 {EXTRACTION_CONDITION})"
+    )
+
+    return EXIT_OK
+
+
+def run_generate(repo_root: Path, *, scanner: Scanner | None = None) -> int:
+    """段 1 → 2 → 4 を通してから 2 ファイルを書き替える。"""
+    facts, annotations, notes_screens, notes_operations = _prepare(repo_root, scanner)
+
+    violations = validate_annotations(facts, annotations) + check_notes(notes_operations)
+    violations += check_catalog(_read_text(repo_root / CATALOG_PATH, STAGE4), facts)
+    if violations:
+        return _report(violations)
+
+    _replace_atomically([
+        (repo_root / SCREENS_PATH, render_screens(facts, annotations, notes_screens)),
+        (repo_root / OPERATIONS_PATH, render_operations(facts, annotations, notes_operations)),
+    ])
+    print(
+        f"生成完了: 画面 {len(facts.screens)} 件 / 操作 {len(facts.operations)} 件 "
+        f"(抽出条件 {EXTRACTION_CONDITION})"
+    )
+
+    return EXIT_OK
+
+
+def main(argv: list[str] | None = None) -> int:
+    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
+    parser.add_argument("command", choices=("check", "generate"))
+    args = parser.parse_args(argv)
+
+    # リポジトリルートは自分の位置から決める (どの cwd から起動しても結果は同じ)。
+    repo_root = Path(__file__).resolve().parent.parent
+    try:
+        return run_check(repo_root) if args.command == "check" else run_generate(repo_root)
+    except FatalError as exc:
+        print(str(exc), file=sys.stderr)
+
+        return EXIT_FATAL
+    except Exception:  # noqa: BLE001 — 想定外は 0 に畳まず traceback を出して致命にする
+        traceback.print_exc()
+
+        return EXIT_FATAL
+
+
+if __name__ == "__main__":
+    sys.exit(main())
diff --git a/scripts/tests/test_bug_hunt_inventory.py b/scripts/tests/test_bug_hunt_inventory.py
new file mode 100644
index 0000000..2612013
--- /dev/null
+++ b/scripts/tests/test_bug_hunt_inventory.py
@@ -0,0 +1,531 @@
+#!/usr/bin/env python3
+"""scripts/bug-hunt-inventory.py の自己テスト (標準ライブラリのみ)。
+
+実 `php` を呼ばない。抽出は fake scanner (固定の JSON を返す callable) で駆動するので
+決定論で速く、DB にも APP_KEY にも依存しない。
+
+    cd scripts/tests && python3 -m unittest test_bug_hunt_inventory
+
+`composer test` からは tests/Architecture/BughuntInventoryToolSelfTest.php が起動する。
+"""
+from __future__ import annotations
+
+import importlib.util
+import io
+import shutil
+import sys
+import tempfile
+import unittest
+from contextlib import redirect_stderr, redirect_stdout
+from pathlib import Path
+
+REPO_ROOT = Path(__file__).resolve().parents[2]
+GENERATOR_PATH = REPO_ROOT / "scripts/bug-hunt-inventory.py"
+
+
+def _load_generator():
+    """ファイル名にハイフンを含むので通常の import ができない (この読み込み自体もテストの一部)。"""
+    spec = importlib.util.spec_from_file_location("bug_hunt_inventory", GENERATOR_PATH)
+    assert spec is not None and spec.loader is not None
+    module = importlib.util.module_from_spec(spec)
+    # dataclass の遅延注釈解決が sys.modules を引くので、実行前に登録する。
+    sys.modules[spec.name] = module
+    spec.loader.exec_module(module)
+
+    return module
+
+
+inv = _load_generator()
+
+
+# --------------------------------------------------------------------------- #
+# fixture
+# --------------------------------------------------------------------------- #
+def route(name, uri, methods, middleware=("web",), title=None, action="App\\Http\\C@m"):
+    return {
+        "name": name,
+        "uri": uri,
+        "methods": list(methods),
+        "middleware": list(middleware),
+        "action": action,
+        "title": title,
+    }
+
+
+BASE_ROUTES = [
+    route("dashboard", "dashboard", ["GET", "HEAD"], title="ダッシュボード"),
+    route("session.status", "session/status", ["GET", "HEAD"]),
+    route("seo.robots", "robots.txt", ["GET", "HEAD"]),
+    route("projects.store", "projects", ["POST"]),
+    route("projects.destroy", "projects/{project}", ["DELETE"]),
+    # web 面ではないもの (面の判定の負の対照)
+    route("cashier.webhook", "stripe/webhook", ["POST"], middleware=["throttle:webhook-stripe"]),
+    route("passport.token", "oauth/token", ["POST"], middleware=["web"]),
+    route("livewire.update", "livewire-18f43797/update", ["POST"], middleware=["web"]),
+    route(None, "vendor/asset.js", ["GET", "HEAD"], middleware=[]),
+]
+
+BASE_ANNOTATIONS = """schema_version = 1
+
+[routes."dashboard"]
+kind = "画面"
+story = "S1"
+kubun = "通常"
+
+[routes."projects.destroy"]
+story = "S4"
+kubun = "通常"
+
+[routes."projects.store"]
+story = "S4"
+kubun = "通常"
+
+[routes."seo.robots"]
+kind = "JSON"
+kubun = "外"
+reason = "クローラ向けの機械可読 route であり人が操作する画面ではないため探索の分母に載せない"
+
+[routes."session.status"]
+kind = "JSON"
+story = "S6"
+kubun = "通常"
+"""
+
+BASE_CATALOG = """# Capability Catalog
+
+## capability_id 索引
+
+| id | 機能 (actor→outcome) | 代表機構 (route name) |
+|---|---|---|
+| PROJ-01 | owner→プロジェクト CRUD | `projects.store` / `projects.*` |
+| PLAT-01 | platform→管理パネル | (admin panel) |
+| AK-04 | automation→REST API | `routes/api.php` |
+"""
+
+
+def fake_scanner(routes=None, *, schema_version=1, condition=inv.EXTRACTION_CONDITION, payload=None):
+    """抽出コマンドの代わり。`payload` を渡すと生の値をそのまま返す。"""
+
+    def scanner(_repo_root):
+        if payload is not None:
+            return payload
+
+        return {
+            "schema_version": schema_version,
+            "extraction_condition": condition,
+            "routes": BASE_ROUTES if routes is None else routes,
+        }
+
+    return scanner
+
+
+class SandboxCase(unittest.TestCase):
+    """生成器が読む 6 ファイルを持つ sandbox を組み立てる。"""
+
+    def setUp(self):
+        self.root = Path(tempfile.mkdtemp(prefix="bhi-"))
+        self.addCleanup(shutil.rmtree, self.root, ignore_errors=True)
+        (self.root / inv.ANNOTATIONS_PATH).parent.mkdir(parents=True, exist_ok=True)
+        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS)
+        self.write(inv.NOTES_SCREENS_PATH, "## 画面の散文\n\nここは人が書く。\n")
+        self.write(inv.NOTES_OPERATIONS_PATH, "## 操作の散文\n\nここは人が書く。\n")
+        self.write(inv.CATALOG_PATH, BASE_CATALOG)
+        self.write(inv.SCREENS_PATH, "placeholder\n")
+        self.write(inv.OPERATIONS_PATH, "placeholder\n")
+
+    def write(self, relative: Path, content: str) -> Path:
+        path = self.root / relative
+        path.parent.mkdir(parents=True, exist_ok=True)
+        path.write_text(content, encoding="utf-8", newline="\n")
+
+        return path
+
+    def read(self, relative: Path) -> str:
+        return (self.root / relative).read_text(encoding="utf-8")
+
+    def run_check(self, scanner=None) -> tuple[int, str]:
+        return self._capture(inv.run_check, scanner)
+
+    def run_generate(self, scanner=None) -> tuple[int, str]:
+        return self._capture(inv.run_generate, scanner)
+
+    def _capture(self, entry, scanner) -> tuple[int, str]:
+        out, err = io.StringIO(), io.StringIO()
+        with redirect_stdout(out), redirect_stderr(err):
+            try:
+                code = entry(self.root, scanner=scanner or fake_scanner())
+            except inv.FatalError as exc:
+                return inv.EXIT_FATAL, f"{out.getvalue()}{err.getvalue()}{exc}"
+
+        return code, out.getvalue() + err.getvalue()
+
+    def generate_then(self, scanner=None):
+        code, output = self.run_generate(scanner)
+        self.assertEqual(inv.EXIT_OK, code, output)
+
+
+# --------------------------------------------------------------------------- #
+# 段 1: 抽出 (致命)
+# --------------------------------------------------------------------------- #
+class ExtractionTest(SandboxCase):
+    def test_非0終了の抽出コマンドは致命(self):
+        def failing(_root):
+            raise inv.FatalError("[抽出] 抽出コマンドが非 0 終了")
+
+        code, output = self.run_check(failing)
+        self.assertEqual(inv.EXIT_FATAL, code, output)
+
+    def test_抽出条件の不一致は致命(self):
+        code, output = self.run_check(fake_scanner(condition="production"))
+        self.assertEqual(inv.EXIT_FATAL, code)
+        self.assertIn("抽出条件", output)
+
+    def test_schema_version_の不一致は致命(self):
+        code, _ = self.run_check(fake_scanner(schema_version=2))
+        self.assertEqual(inv.EXIT_FATAL, code)
+
+    def test_routes_が並びでないのは致命(self):
+        code, _ = self.run_check(fake_scanner(payload={
+            "schema_version": 1, "extraction_condition": inv.EXTRACTION_CONDITION, "routes": {},
+        }))
+        self.assertEqual(inv.EXIT_FATAL, code)
+
+    def test_抽出結果が表でないのは致命(self):
+        code, _ = self.run_check(fake_scanner(payload=["not", "a", "table"]))
+        self.assertEqual(inv.EXIT_FATAL, code)
+
+    def test_母集合0件は致命(self):
+        code, output = self.run_check(fake_scanner([route("api.ping", "api/ping", ["GET"], middleware=[])]))
+        self.assertEqual(inv.EXIT_FATAL, code)
+        self.assertIn("母集合が 0 件", output)
+
+    def test_web面の無名routeは致命(self):
+        code, output = self.run_check(fake_scanner(BASE_ROUTES + [route(None, "anon", ["GET", "HEAD"])]))
+        self.assertEqual(inv.EXIT_FATAL, code)
+        self.assertIn("名前が無い", output)
+
+    def test_名前の重複は致命(self):
+        code, output = self.run_check(fake_scanner(BASE_ROUTES + [route("dashboard", "dash2", ["GET"])]))
+        self.assertEqual(inv.EXIT_FATAL, code)
+        self.assertIn("重複", output)
+
+    def test_面の外の無名routeは許容(self):
+        # vendor の資材配信のような無名 route は抽出結果に出るが目録には入らない。
+        self.generate_then()
+        code, output = self.run_check()
+        self.assertEqual(inv.EXIT_OK, code, output)
+
+    def test_注釈ファイル不在は致命(self):
+        (self.root / inv.ANNOTATIONS_PATH).unlink()
+        code, _ = self.run_check()
+        self.assertEqual(inv.EXIT_FATAL, code)
+
+    def test_壊れたTOMLは致命(self):
+        self.write(inv.ANNOTATIONS_PATH, "schema_version = 1\n[routes.\n")
+        code, _ = self.run_check()
+        self.assertEqual(inv.EXIT_FATAL, code)
+
+    def test_散文ノート不在は致命(self):
+        (self.root / inv.NOTES_OPERATIONS_PATH).unlink()
+        code, _ = self.run_check()
+        self.assertEqual(inv.EXIT_FATAL, code)
+
+
+# --------------------------------------------------------------------------- #
+# 面の判定
+# --------------------------------------------------------------------------- #
+class SurfaceTest(unittest.TestCase):
+    def facts(self, routes=None):
+        return inv.split_surface(fake_scanner(routes)(Path(".")))
+
+    def test_throttle_webhook_stripe_を_web_面と誤認しない(self):
+        # middleware の**要素**を見る (文字列化した部分一致にしない)。
+        self.assertNotIn("cashier.webhook", {f.name for f in self.facts().operations})
+
+    def test_oauth_と_livewire_ハッシュは面から外す(self):
+        names = {f.name for f in self.facts().surface}
+        self.assertNotIn("passport.token", names)
+        self.assertNotIn("livewire.update", names)
+
+    def test_画面表と操作表の直和が_web_面になる(self):
+        facts = self.facts()
+        self.assertEqual(
+            {f.name for f in facts.screens} | {f.name for f in facts.operations},
+            {"dashboard", "session.status", "seo.robots", "projects.store", "projects.destroy"},
+        )
+        self.assertEqual(len(facts.screens) + len(facts.operations), len(facts.surface))
+        self.assertEqual(set(), {f.name for f in facts.screens} & {f.name for f in facts.operations})
+
+    def test_全route名は面に限らない(self):
+        # 段 4 の照合母集合は admin / api 面も含む。
+        self.assertIn("cashier.webhook", self.facts().all_names)
+
+    def test_複合methodは操作表へ入れたうえで印を付ける(self):
+        facts = self.facts(BASE_ROUTES + [route("both", "both", ["GET", "HEAD", "POST"])])
+        self.assertIn("both", {f.name for f in facts.operations})
+        self.assertEqual(("both",), facts.compound)
+
+
+class VocabularyParityTest(unittest.TestCase):
+    def test_区分の語彙が_correlate_と一致する(self):
+        sys.path.insert(0, str(REPO_ROOT / ".claude/skills/app-bug-hunt/coverage"))
+        try:
+            import correlate
+        finally:
+            sys.path.pop(0)
+        self.assertEqual(correlate.KUBUN_OUT_OF_SCOPE, inv.KUBUN_OUT_OF_SCOPE)
+        self.assertEqual(correlate.KUBUN_DEVIATE, inv.KUBUN_DEVIATE)
+
+
+# --------------------------------------------------------------------------- #
+# 段 2: 注釈 (ドリフト)
+# --------------------------------------------------------------------------- #
+class AnnotationTest(SandboxCase):
+    def assert_drift(self, needle: str):
+        code, output = self.run_check()
+        self.assertEqual(inv.EXIT_DRIFT, code, output)
+        self.assertIn(needle, output)
+
+    def replace(self, old: str, new: str):
+        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS.replace(old, new))
+
+    def test_未注釈のroute(self):
+        self.replace('[routes."projects.store"]\nstory = "S4"\nkubun = "通常"\n', "")
+        self.assert_drift("未注釈の route: projects.store")
+
+    def test_実装に無いrouteの注釈残置(self):
+        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS + '\n[routes."gone.index"]\nkind = "画面"\nstory = "S1"\nkubun = "通常"\n')
+        self.assert_drift("実装に無い route の注釈が残っている: gone.index")
+
+    def test_未知の区分(self):
+        self.replace('[routes."dashboard"]\nkind = "画面"\nstory = "S1"\nkubun = "通常"',
+                     '[routes."dashboard"]\nkind = "画面"\nstory = "S1"\nkubun = "重要"')
+        self.assert_drift("未知の区分")
+
+    def test_未知の項目(self):
+        self.replace('[routes."dashboard"]\nkind = "画面"', '[routes."dashboard"]\nmemo = "x"\nkind = "画面"')
+        self.assert_drift("未知の項目: memo")
+
+    def test_理由が30文字未満(self):
+        self.replace("クローラ向けの機械可読 route であり人が操作する画面ではないため探索の分母に載せない", "短い理由")
+        self.assert_drift("30 文字未満")
+
+    def test_story欠落(self):
+        self.replace('[routes."projects.store"]\nstory = "S4"\n', '[routes."projects.store"]\n')
+        self.assert_drift("story が要る")
+
+    def test_区分外にstoryを書けない(self):
+        self.replace('[routes."seo.robots"]\nkind = "JSON"', '[routes."seo.robots"]\nkind = "JSON"\nstory = "S1"')
+        self.assert_drift("story は書けない")
+
+    def test_画面routeのkind欠落(self):
+        self.replace('[routes."dashboard"]\nkind = "画面"\n', '[routes."dashboard"]\n')
+        self.assert_drift("kind が要る")
+
+    def test_操作routeにkindは書けない(self):
+        self.replace('[routes."projects.store"]\n', '[routes."projects.store"]\nkind = "画面"\n')
+        self.assert_drift("kind は書けない")
+
+    def test_セル値に表を壊す文字(self):
+        self.replace('story = "S1"\nkubun = "通常"', 'story = "S1|S2"\nkubun = "通常"')
+        self.assert_drift("表を壊す文字")
+
+    def test_機械事実側のセル値に表を壊す文字(self):
+        routes = [r for r in BASE_ROUTES if r["name"] != "dashboard"]
+        routes.append(route("dashboard", "dash|board", ["GET", "HEAD"]))
+        code, output = self.run_check(fake_scanner(routes))
+        self.assertEqual(inv.EXIT_DRIFT, code, output)
+        self.assertIn("表を壊す文字", output)
+
+    def test_複合methodはドリフト(self):
+        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS + '\n[routes."both"]\nstory = "S1"\nkubun = "通常"\n')
+        code, output = self.run_check(fake_scanner(BASE_ROUTES + [route("both", "both", ["GET", "HEAD", "POST"])]))
+        self.assertEqual(inv.EXIT_DRIFT, code, output)
+        self.assertIn("併せ持つ route", output)
+
+    def test_未知のトップレベル項目は致命(self):
+        self.write(inv.ANNOTATIONS_PATH, 'version = 1\n' + BASE_ANNOTATIONS)
+        code, output = self.run_check()
+        self.assertEqual(inv.EXIT_FATAL, code, output)
+
+    def test_散文ノートの表はドリフト(self):
+        self.generate_then()
+        self.write(inv.NOTES_OPERATIONS_PATH, "## 操作の散文\n\n| name | story | 区分 |\n|---|---|---|\n| fake.route | S1 | 通常 |\n")
+        code, output = self.run_check()
+        self.assertEqual(inv.EXIT_DRIFT, code, output)
+        self.assertIn("散文ノートに表を置かない", output)
+
+
+# --------------------------------------------------------------------------- #
+# 段 3: 生成物
+# --------------------------------------------------------------------------- #
+class GeneratedFilesTest(SandboxCase):
+    def test_生成してから検査すると一致する(self):
+        self.generate_then()
+        code, output = self.run_check()
+        self.assertEqual(inv.EXIT_OK, code, output)
+
+    def test_目録が未生成でも初回生成できる(self):
+        (self.root / inv.SCREENS_PATH).unlink()
+        (self.root / inv.OPERATIONS_PATH).unlink()
+        self.generate_then()
+        self.assertEqual(inv.EXIT_OK, self.run_check()[0])
+
+    def test_生成物のbyte不一致はドリフト(self):
+        self.generate_then()
+        self.write(inv.SCREENS_PATH, self.read(inv.SCREENS_PATH) + "手で足した行\n")
+        code, output = self.run_check()
+        self.assertEqual(inv.EXIT_DRIFT, code, output)
+        self.assertIn("screens.md", output)
+
+    def test_checkは1バイトも書かない(self):
+        self.generate_then()
+        before = {
+            path: ((self.root / path).read_bytes(), (self.root / path).stat().st_mtime_ns)
+            for path in (inv.SCREENS_PATH, inv.OPERATIONS_PATH, inv.ANNOTATIONS_PATH)
+        }
+        self.assertEqual(inv.EXIT_OK, self.run_check()[0])
+        for path, (content, mtime) in before.items():
+            self.assertEqual(content, (self.root / path).read_bytes())
+            self.assertEqual(mtime, (self.root / path).stat().st_mtime_ns)
+        self.assertEqual([], sorted(p.name for p in (self.root / inv.SKILL_DIR).glob("*.tmp-generate")))
+
+    def test_生成物に生成物である宣言がある(self):
+        self.generate_then()
+        for path in (inv.SCREENS_PATH, inv.OPERATIONS_PATH):
+            self.assertIn("このファイルは生成物である", self.read(path))
+
+    def test_操作表は5列でヘッダ名を変えない(self):
+        self.generate_then()
+        self.assertIn("| method | route | name | story | 区分 |", self.read(inv.OPERATIONS_PATH))
+
+    def test_generateは段2違反のとき1ファイルも書かない(self):
+        before = (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH))
+        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS.replace('kubun = "通常"', 'kubun = "重要"'))
+        code, output = self.run_generate()
+        self.assertEqual(inv.EXIT_DRIFT, code, output)
+        self.assertEqual(before, (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH)))
+
+    def test_区分外のstory欄はハイフンになる(self):
+        self.generate_then()
+        rows = [l for l in self.read(inv.SCREENS_PATH).splitlines() if "seo.robots" in l and l.startswith("|")]
+        self.assertEqual(1, len(rows))
+        self.assertEqual("-", [c.strip() for c in rows[0].strip("|").split("|")][4])
+
+
+class ReplaceFailureTest(SandboxCase):
+    """置換に失敗しても、呼び出し前の 2 ファイルが byte 単位で保たれること。"""
+
+    def patch_replace(self, failing_calls: set[int]):
+        original = inv.os.replace
+        calls = {"n": 0}
+
+        def fake(src, dst):
+            calls["n"] += 1
+            if calls["n"] in failing_calls:
+                raise OSError(f"注入した失敗 (呼び出し {calls['n']} 回目)")
+
+            return original(src, dst)
+
+        inv.os.replace = fake
+        self.addCleanup(setattr, inv.os, "replace", original)
+
+        return calls
+
+    def test_1本目の置換失敗で2ファイルとも無傷(self):
+        before = (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH))
+        self.patch_replace({1})
+        code, output = self.run_generate()
+        self.assertEqual(inv.EXIT_FATAL, code, output)
+        self.assertEqual(before, (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH)))
+        self.assertEqual([], sorted(p.name for p in (self.root / inv.SKILL_DIR).glob("*generate")))
+
+    def test_2本目の置換失敗は控えから戻す(self):
+        before = (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH))
+        self.patch_replace({2})
+        code, output = self.run_generate()
+        self.assertEqual(inv.EXIT_FATAL, code, output)
+        self.assertEqual(before, (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH)))
+        self.assertEqual([], sorted(p.name for p in (self.root / inv.SKILL_DIR).glob("*generate")))
+
+    def test_未生成からの初回生成で2本目が失敗したら1本目も消す(self):
+        (self.root / inv.SCREENS_PATH).unlink()
+        (self.root / inv.OPERATIONS_PATH).unlink()
+        self.patch_replace({2})
+        code, output = self.run_generate()
+        self.assertEqual(inv.EXIT_FATAL, code, output)
+        self.assertFalse((self.root / inv.SCREENS_PATH).exists())
+        self.assertFalse((self.root / inv.OPERATIONS_PATH).exists())
+
+    def test_復元にも失敗したら何も消さずに全パスを出す(self):
+        self.patch_replace({2, 3})
+        code, output = self.run_generate()
+        self.assertEqual(inv.EXIT_FATAL, code, output)
+        self.assertIn("復元に失敗", output)
+        leftovers = sorted(p.name for p in (self.root / inv.SKILL_DIR).glob("*generate"))
+        self.assertIn("screens.md.bak-generate", leftovers)
+        self.assertIn("operations.md.tmp-generate", leftovers)
+
+
+# --------------------------------------------------------------------------- #
+# 段 4: 機能カタログ
+# --------------------------------------------------------------------------- #
+class CatalogTest(SandboxCase):
+    def setUp(self):
+        super().setUp()
+        self.generate_then()
+
+    def test_実在しない代表機構はドリフト(self):
+        self.write(inv.CATALOG_PATH, BASE_CATALOG.replace("`projects.store`", "`projects.missing`"))
+        code, output = self.run_check()
+        self.assertEqual(inv.EXIT_DRIFT, code, output)
+        self.assertIn("実在しない route 名", output)
+
+    def test_idの重複はドリフト(self):
+        self.write(inv.CATALOG_PATH, BASE_CATALOG + "| PROJ-01 | 重複 | `projects.store` |\n")
+        code, output = self.run_check()
+        self.assertEqual(inv.EXIT_DRIFT, code, output)
+        self.assertIn("重複", output)
+
+    def test_前方一致が1件も当たらないとドリフト(self):
+        self.write(inv.CATALOG_PATH, BASE_CATALOG.replace("`projects.*`", "`nowhere.*`"))
+        code, output = self.run_check()
+        self.assertEqual(inv.EXIT_DRIFT, code, output)
+        self.assertIn("前方一致", output)
+
+    def test_括弧書きとパスのセルは無視される(self):
+        # BASE_CATALOG は (admin panel) と `routes/api.php` を含む。これらで落ちないこと。
+        self.assertEqual(inv.EXIT_OK, self.run_check()[0])
+
+    def test_表が見つからないのは致命(self):
+        self.write(inv.CATALOG_PATH, "# Capability Catalog\n\n表が無い。\n")
+        code, output = self.run_check()
+        self.assertEqual(inv.EXIT_FATAL, code, output)
+
+
+# --------------------------------------------------------------------------- #
+# 下流ローダとの結合
+# --------------------------------------------------------------------------- #
+class CorrelateIntegrationTest(SandboxCase):
+    def test_生成した操作表を_correlate_が同じ集合として読める(self):
+        self.generate_then()
+        sys.path.insert(0, str(REPO_ROOT / ".claude/skills/app-bug-hunt/coverage"))
+        try:
+            import correlate
+        finally:
+            sys.path.pop(0)
+
+        loaded = correlate.load_operations(str(self.root / inv.OPERATIONS_PATH))
+        self.assertEqual({"projects.store", "projects.destroy"}, set(loaded))
+        self.assertEqual("S4", loaded["projects.store"]["story"])
+        self.assertEqual("通常", loaded["projects.store"]["kubun"])
+        self.assertEqual("projects", loaded["projects.store"]["operation"])
+
+        # load_operations() は重複を「最初の定義を優先」で畳むので、重複が隠れないことを見る。
+        text = self.read(inv.OPERATIONS_PATH)
+        for name in loaded:
+            self.assertEqual(1, text.count(f"| {name} |"), name)
+
+
+if __name__ == "__main__":
+    unittest.main()
diff --git a/tests/Architecture/BugHuntInventoryCheckInvariantTest.php b/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
index 7296421..263479e 100644
--- a/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
+++ b/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
@@ -5,21 +5,22 @@
 use Symfony\Component\Process\Process;
 
 /*
- * Architecture invariant: bug-hunt インベントリ ドリフト検出器の最小契約。
+ * Architecture invariant: bug-hunt 目録のドリフト検査の契約 (T176 で作り替え)。
  *
- * SoT = scripts/bug-hunt-inventory-check.sh 本体 + AGENTS.md §bug-hunt
- * (「ドリフト検知は scripts/bug-hunt-inventory-check.sh」)。
+ * 目録 (.claude/skills/app-bug-hunt/{screens,operations}.md) は**生成物**であり、
+ * 判定は scripts/bug-hunt-inventory.py に一本化してある。シェル
+ * (scripts/bug-hunt-inventory-check.sh) は起動するだけで判定を持たない。
  *
  * 固定する不変条件:
- *   - スクリプトが存在し実行可能で、fail-closed (set -euo pipefail) であること
- *   - インベントリ正本が `.claude/skills/app-bug-hunt/{screens.md,operations.md}` であること
- *   - **exit code 規約 0=一致 / 3=ドリフト** を実際に満たすこと
- *   - route:list 取得に失敗したときに exit 0 (fail-open) を返さないこと
+ *   - シェルが存在し実行可能で、fail-closed (set -euo pipefail) であること
+ *   - シェルが判定 (route:list / grep / 対象外の語彙) を**持たない**こと
+ *   - 目録の正本パスを持つのは生成器側であること
+ *   - **exit code 規約 0=一致 / 2=致命 / 3=ドリフト** を実際に満たすこと
+ *   - 抽出に失敗したときに exit 0 (fail-open) を返さないこと
  *
- * exit code 規約は「静的に読める宣言」ではなく **実走で** 検証する。ただし実 route:list
- * (artisan boot + DB) には依存させない: スクリプトを一時 sandbox へ複製し、`php` を
- * 固定 JSON を吐く shim に差し替えて走らせる (決定論・DB 不使用)。
- * これが本 gate の負のコントロール (drift fixture で実際に exit 3 になること) も兼ねる。
+ * exit code 規約は「静的に読める宣言」ではなく **実走で** 検証する。ただし実 artisan
+ * (boot + APP_KEY + DB) には依存させない: 一時 sandbox へ道具一式を複製し、`php` を
+ * 固定の scan JSON を吐く shim に差し替えて走らせる (決定論・DB 不使用)。
  */
 
 function bhicScriptPath(): string
@@ -27,30 +28,65 @@ function bhicScriptPath(): string
     return base_path('scripts/bug-hunt-inventory-check.sh');
 }
 
+function bhicGeneratorPath(): string
+{
+    return base_path('scripts/bug-hunt-inventory.py');
+}
+
+/** sandbox 内の相対パス (生成器が持つ正本パスと同じ場所へ置く)。 */
+const BHIC_SKILL_DIR = '.claude/skills/app-bug-hunt';
+
 /**
- * sandbox を組み立てる。scripts/ にスクリプト複製、.claude/skills/app-bug-hunt/ に
- * インベントリ fixture、bin/ に `php` shim (固定 route:list JSON を吐く) を置く。
+ * sandbox を組み立てる。scripts/ に検査シェルと生成器、skill ディレクトリに注釈・散文ノート・
+ * 機能カタログ、bin/ に `php` shim (固定の scan JSON を吐く) を置く。
  *
- * @param  list<array{method: string, uri: string, middleware: list<string>, name: string}>  $routes
- * @param  bool  $phpFails  true なら shim が失敗する (route:list 取得失敗の再現)
+ * 目録 2 ファイルは置かない (テスト側が generate を走らせて作る = 実挙動で組み立てる)。
+ *
+ * @param  bool  $phpFails  true なら shim が失敗する (抽出失敗の再現)
  */
-function bhicMakeSandbox(array $routes, string $screensMd, string $operationsMd, bool $phpFails = false): string
+function bhicMakeSandbox(bool $phpFails = false): string
 {
     $sandbox = sys_get_temp_dir().'/bhic-'.bin2hex(random_bytes(6));
     mkdir($sandbox.'/scripts', 0o755, true);
-    mkdir($sandbox.'/.claude/skills/app-bug-hunt', 0o755, true);
+    mkdir($sandbox.'/'.BHIC_SKILL_DIR.'/inventory', 0o755, true);
     mkdir($sandbox.'/bin', 0o755, true);
 
     copy(bhicScriptPath(), $sandbox.'/scripts/bug-hunt-inventory-check.sh');
-    file_put_contents($sandbox.'/.claude/skills/app-bug-hunt/screens.md', $screensMd);
-    file_put_contents($sandbox.'/.claude/skills/app-bug-hunt/operations.md', $operationsMd);
+    copy(bhicGeneratorPath(), $sandbox.'/scripts/bug-hunt-inventory.py');
+
+    $scan = [
+        'schema_version' => 1,
+        'extraction_condition' => 'local-or-unit-tests',
+        'routes' => [
+            [
+                'name' => 'dashboard', 'uri' => 'dashboard', 'methods' => ['GET', 'HEAD'],
+                'middleware' => ['web'], 'action' => 'App\\Http\\Controllers\\X@index', 'title' => 'ダッシュボード',
+            ],
+            [
+                'name' => 'projects.store', 'uri' => 'projects', 'methods' => ['POST'],
+                'middleware' => ['web'], 'action' => 'App\\Http\\Controllers\\X@store', 'title' => null,
+            ],
+        ],
+    ];
+    $json = json_encode($scan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    file_put_contents($sandbox.'/scan.json', $json);
 
-    $json = json_encode($routes, JSON_UNESCAPED_SLASHES);
-    file_put_contents($sandbox.'/routes.json', is_string($json) ? $json : '[]');
+    file_put_contents(
+        $sandbox.'/'.BHIC_SKILL_DIR.'/inventory/annotations.toml',
+        "schema_version = 1\n\n[routes.\"dashboard\"]\nkind = \"画面\"\nstory = \"S1\"\nkubun = \"通常\"\n\n"
+        ."[routes.\"projects.store\"]\nstory = \"S4\"\nkubun = \"通常\"\n"
+    );
+    file_put_contents($sandbox.'/'.BHIC_SKILL_DIR.'/inventory/notes-screens.md', "## 画面の散文\n\n人が書く。\n");
+    file_put_contents($sandbox.'/'.BHIC_SKILL_DIR.'/inventory/notes-operations.md', "## 操作の散文\n\n人が書く。\n");
+    file_put_contents(
+        $sandbox.'/'.BHIC_SKILL_DIR.'/capability-catalog.md',
+        "# Capability Catalog\n\n| id | 機能 (actor→outcome) | 代表機構 (route name) |\n|---|---|---|\n"
+        ."| PROJ-01 | owner→プロジェクト作成 | `projects.store` |\n"
+    );
 
     $shim = $phpFails
-        ? "#!/usr/bin/env bash\necho 'route:list failed' >&2\nexit 1\n"
-        : "#!/usr/bin/env bash\ncat \"\$(dirname \"\$0\")/../routes.json\"\n";
+        ? "#!/usr/bin/env bash\necho 'inventory-scan failed' >&2\nexit 1\n"
+        : "#!/usr/bin/env bash\ncat \"\$(dirname \"\$0\")/../scan.json\"\n";
     file_put_contents($sandbox.'/bin/php', $shim);
     chmod($sandbox.'/bin/php', 0o755);
 
@@ -75,28 +111,28 @@ function bhicRemoveSandbox(string $sandbox): void
 /**
  * python3 の存在を **前提条件として固定**する (skip しない)。
  *
- * bug-hunt-inventory-check.sh は route:list の突合に `python3 -c` を使う (AGENTS.md §bug-hunt:
- * 「Python ツールは stdlib のみ」)。python3 が無い環境ではスクリプト自体が動かないため、
- * skip して green にすると「exit code 規約が未検証のまま合格」になる (impl-review R1 Warning)。
+ * 判定は python3 の生成器が持つ。python3 が無い環境では検査そのものが動かないため、
+ * skip して green にすると「exit code 規約が未検証のまま合格」になる。
  * 環境不備は skip ではなく fail として顕在化させる。
  */
 function bhicRequirePython3(): void
 {
     expect((new Process(['which', 'python3']))->run())->toBe(
         0,
-        'python3 が PATH に無い。bug-hunt-inventory-check.sh は python3 必須 (環境不備を skip で隠さない)'
+        'python3 が PATH に無い。bug-hunt 目録の検査は python3 必須 (環境不備を skip で隠さない)'
     );
 }
 
 /**
- * sandbox 内でスクリプトを走らせ [exitCode, output] を返す。
+ * sandbox 内でコマンドを走らせ [exitCode, output] を返す。
  *
+ * @param  list<string>  $command
  * @return array{0: int|null, 1: string}
  */
-function bhicRunSandbox(string $sandbox): array
+function bhicRunSandbox(string $sandbox, array $command): array
 {
     $process = new Process(
-        ['bash', $sandbox.'/scripts/bug-hunt-inventory-check.sh'],
+        $command,
         $sandbox,
         ['PATH' => $sandbox.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin')]
     );
@@ -105,6 +141,36 @@ function bhicRunSandbox(string $sandbox): array
     return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
 }
 
+/**
+ * sandbox で目録を生成してから検査シェルを走らせる。
+ *
+ * @return array{0: int|null, 1: string}
+ */
+function bhicGenerate(string $sandbox): array
+{
+    return bhicRunSandbox($sandbox, ['python3', $sandbox.'/scripts/bug-hunt-inventory.py', 'generate']);
+}
+
+/** @return array{0: int|null, 1: string} */
+function bhicCheck(string $sandbox): array
+{
+    return bhicRunSandbox($sandbox, ['bash', $sandbox.'/scripts/bug-hunt-inventory-check.sh']);
+}
+
+/** シェルの実装行 (コメント行と空行を除く)。説明コメントで誤検知しないため。 */
+function bhicShellCodeLines(): string
+{
+    $src = file_get_contents(bhicScriptPath());
+    expect($src)->toBeString();
+    /** @var string $src */
+    $lines = array_filter(
+        explode("\n", $src),
+        static fn (string $line): bool => trim($line) !== '' && ! str_starts_with(trim($line), '#'),
+    );
+
+    return implode("\n", $lines);
+}
+
 test('scripts/bug-hunt-inventory-check.sh が存在し実行可能で fail-closed であること', function (): void {
     $path = bhicScriptPath();
     expect(file_exists($path))->toBeTrue('bug-hunt-inventory-check.sh が見つからない');
@@ -114,91 +180,110 @@ function bhicRunSandbox(string $sandbox): array
     expect($src)->toBeString();
     /** @var string $src */
     expect($src)->toContain('set -euo pipefail');
+    // exit 規約の宣言がスクリプト冒頭に残ること (運用者向けの契約表明)。
+    expect($src)->toMatch('/exit 0=.*2=.*3=/u');
 });
 
-test('インベントリ正本が .claude/skills/app-bug-hunt/{screens,operations}.md であること', function (): void {
-    $src = file_get_contents(bhicScriptPath());
+test('シェルが判定を持たず、生成器を起動するだけであること', function (): void {
+    $code = bhicShellCodeLines();
+
+    expect($code)->toContain('scripts/bug-hunt-inventory.py');
+    // 判定の語彙がシェルへ戻っていないこと (同じ規則を 2 か所に置かない)。
+    foreach (['route:list', 'grep', 'OUT_OF_SCOPE', 'python3 -c'] as $forbidden) {
+        expect(str_contains($code, $forbidden))->toBeFalse("判定がシェルへ戻っている: {$forbidden}");
+    }
+});
+
+test('目録の正本パスを持つのは生成器側であること', function (): void {
+    $src = file_get_contents(bhicGeneratorPath());
     expect($src)->toBeString();
     /** @var string $src */
     expect($src)->toContain('.claude/skills/app-bug-hunt');
-    expect($src)->toMatch('/SCREENS="\$\{SKILL_DIR\}\/screens\.md"/');
-    expect($src)->toMatch('/OPS="\$\{SKILL_DIR\}\/operations\.md"/');
-    // exit 規約の宣言がスクリプト冒頭に残ること (運用者向けの契約表明)。
-    expect($src)->toMatch('/exit 0=.*3=/u');
+    expect($src)->toContain('"screens.md"');
+    expect($src)->toContain('"operations.md"');
+    expect($src)->toContain('"annotations.toml"');
 });
 
 test('exit code 規約 0=一致 / 3=ドリフト を実走で満たすこと (sandbox / DB 不使用)', function (): void {
     bhicRequirePython3();
 
-    $screens = "# screens\n\n| ルート名 | URI |\n|---|---|\n| dashboard | /dashboard |\n";
-    $operations = "# operations\n\n| ルート名 | 操作 |\n|---|---|\n| projects.store | 作成 |\n";
-
-    // (1) 一致: route:list の全ルートがインベントリに載っている → exit 0
-    $match = bhicMakeSandbox([
-        ['method' => 'GET|HEAD', 'uri' => 'dashboard', 'middleware' => ['web'], 'name' => 'dashboard'],
-        ['method' => 'POST', 'uri' => 'projects', 'middleware' => ['web'], 'name' => 'projects.store'],
-    ], $screens, $operations);
+    $sandbox = bhicMakeSandbox();
     try {
-        [$code, $out] = bhicRunSandbox($match);
+        [$genCode, $genOut] = bhicGenerate($sandbox);
+        expect($genCode)->toBe(0, "生成に失敗した:\n".$genOut);
+
+        // (1) 一致: 生成直後は差分なし → exit 0
+        [$code, $out] = bhicCheck($sandbox);
         expect($code)->toBe(0, "一致 fixture は exit 0 であるべき:\n".$out);
-        expect($out)->toContain('drift なし');
+        expect($out)->toContain('一致');
+
+        // (2) 生成物の手編集 → exit 3
+        $screens = $sandbox.'/'.BHIC_SKILL_DIR.'/screens.md';
+        file_put_contents($screens, file_get_contents($screens)."手で足した行\n");
+        [$code, $out] = bhicCheck($sandbox);
+        expect($code)->toBe(3, "生成物の手編集は exit 3 であるべき:\n".$out);
+        expect($out)->toContain('screens.md');
     } finally {
-        bhicRemoveSandbox($match);
+        bhicRemoveSandbox($sandbox);
     }
 });
 
-/*
- * 負のコントロール: 未追記ルート (screens / operations 双方) で実際に exit 3 になること。
- * gate が空振り (常に 0) でないことをここで担保する。
- */
-test('負のコントロール: 未追記ルートがあれば exit 3 (ドリフト) になること', function (): void {
+test('負のコントロール: 未注釈の route があれば exit 3 (ドリフト) になること', function (): void {
     bhicRequirePython3();
 
-    $screens = "# screens\n\n| ルート名 | URI |\n|---|---|\n| dashboard | /dashboard |\n";
-    $operations = "# operations\n\n| ルート名 | 操作 |\n|---|---|\n| projects.store | 作成 |\n";
-
-    // screens 側ドリフト: 未追記の GET×web ルート
-    $screenDrift = bhicMakeSandbox([
-        ['method' => 'GET|HEAD', 'uri' => 'dashboard', 'middleware' => ['web'], 'name' => 'dashboard'],
-        ['method' => 'GET|HEAD', 'uri' => 'reports', 'middleware' => ['web'], 'name' => 'reports.index'],
-        ['method' => 'POST', 'uri' => 'projects', 'middleware' => ['web'], 'name' => 'projects.store'],
-    ], $screens, $operations);
+    $sandbox = bhicMakeSandbox();
     try {
-        [$code, $out] = bhicRunSandbox($screenDrift);
-        expect($code)->toBe(3, "screens 未追記は exit 3 であるべき:\n".$out);
+        expect(bhicGenerate($sandbox)[0])->toBe(0);
+
+        // 実装に route を足して注釈を足さない = 意味の欠落
+        $scan = json_decode((string) file_get_contents($sandbox.'/scan.json'), true, 512, JSON_THROW_ON_ERROR);
+        expect($scan)->toBeArray();
+        /** @var array{routes: list<array<string, mixed>>} $scan */
+        $scan['routes'][] = [
+            'name' => 'reports.index', 'uri' => 'reports', 'methods' => ['GET', 'HEAD'],
+            'middleware' => ['web'], 'action' => 'App\\Http\\Controllers\\X@index', 'title' => null,
+        ];
+        file_put_contents($sandbox.'/scan.json', json_encode($scan, JSON_THROW_ON_ERROR));
+
+        [$code, $out] = bhicCheck($sandbox);
+        expect($code)->toBe(3, "未注釈 route は exit 3 であるべき:\n".$out);
         expect($out)->toContain('reports.index');
     } finally {
-        bhicRemoveSandbox($screenDrift);
+        bhicRemoveSandbox($sandbox);
     }
+});
 
-    // operations 側ドリフト: 未追記の 非GET×web ルート
-    $opDrift = bhicMakeSandbox([
-        ['method' => 'GET|HEAD', 'uri' => 'dashboard', 'middleware' => ['web'], 'name' => 'dashboard'],
-        ['method' => 'POST', 'uri' => 'projects', 'middleware' => ['web'], 'name' => 'projects.store'],
-        ['method' => 'DELETE', 'uri' => 'projects/{project}', 'middleware' => ['web'], 'name' => 'projects.destroy'],
-    ], $screens, $operations);
+test('負のコントロール: 抽出に失敗したとき exit 2 になること (fail-open を返さない)', function (): void {
+    bhicRequirePython3();
+
+    $ok = bhicMakeSandbox();
     try {
-        [$code, $out] = bhicRunSandbox($opDrift);
-        expect($code)->toBe(3, "operations 未追記は exit 3 であるべき:\n".$out);
-        expect($out)->toContain('projects.destroy');
+        expect(bhicGenerate($ok)[0])->toBe(0);
+        $screens = file_get_contents($ok.'/'.BHIC_SKILL_DIR.'/screens.md');
+        $operations = file_get_contents($ok.'/'.BHIC_SKILL_DIR.'/operations.md');
     } finally {
-        bhicRemoveSandbox($opDrift);
+        bhicRemoveSandbox($ok);
     }
-});
 
-test('負のコントロール: route:list 取得に失敗したとき exit 0 (fail-open) を返さないこと', function (): void {
-    bhicRequirePython3();
-
-    $sandbox = bhicMakeSandbox(
-        [['method' => 'GET|HEAD', 'uri' => 'dashboard', 'middleware' => ['web'], 'name' => 'dashboard']],
-        "| dashboard | /dashboard |\n",
-        "| projects.store | 作成 |\n",
-        phpFails: true
-    );
+    $sandbox = bhicMakeSandbox(phpFails: true);
     try {
-        [$code, $out] = bhicRunSandbox($sandbox);
-        expect($code)->not->toBe(0, "route:list 失敗時に exit 0 を返してはならない (fail-open):\n".$out);
+        // 生成物は揃っている (差分が原因で落ちたのではないことを明確にする)。
+        file_put_contents($sandbox.'/'.BHIC_SKILL_DIR.'/screens.md', $screens);
+        file_put_contents($sandbox.'/'.BHIC_SKILL_DIR.'/operations.md', $operations);
+
+        [$code, $out] = bhicCheck($sandbox);
+        expect($code)->toBe(2, "抽出失敗は exit 2 であるべき (fail-open にしない):\n".$out);
     } finally {
         bhicRemoveSandbox($sandbox);
     }
 });
+
+test('生成物 2 本が「生成物である」と宣言していること (手編集の抑止を消さない)', function (): void {
+    foreach (['screens.md', 'operations.md'] as $name) {
+        $content = file_get_contents(base_path(BHIC_SKILL_DIR.'/'.$name));
+        expect($content)->toBeString();
+        /** @var string $content */
+        // 生成物宣言 (手編集の抑止) が消えたら赤くする。
+        expect($content)->toContain('このファイルは生成物である');
+    }
+});
diff --git a/tests/Architecture/BughuntInventoryToolSelfTest.php b/tests/Architecture/BughuntInventoryToolSelfTest.php
new file mode 100644
index 0000000..024b5f7
--- /dev/null
+++ b/tests/Architecture/BughuntInventoryToolSelfTest.php
@@ -0,0 +1,67 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Process\Process;
+
+/*
+ * Architecture invariant: bug-hunt 目録の生成器 (Python) の自己テストを
+ * `composer test` の下で実走させる。
+ *
+ * 対象は 1 モジュール:
+ *   - test_bug_hunt_inventory … 生成器兼検査器の段 1..4 と fail-closed の作法
+ *
+ * ここに結線しないと「不変条件はテストへの登録まで含めて実装済み」を満たさない
+ * (禁止事項 1)。生成器が沈黙して緑を返すようになっても気づけないため。
+ *
+ * 先例は BughuntCoverageToolSelfTest: python3 の不在は **skip ではなく fail** で
+ * 顕在化させる (環境不備を skip で隠すと「未検証のまま合格」になる)。
+ */
+
+/** 生成器の自己テストの置き場 (作業ディレクトリ)。 */
+function bitsTestsDir(): string
+{
+    return base_path('scripts/tests');
+}
+
+/**
+ * scripts/tests で `python3 -m unittest <modules...>` を実走し [exitCode, output] を返す。
+ *
+ * @param  list<string>  $modules
+ * @return array{0: int|null, 1: string}
+ */
+function bitsRunUnittest(array $modules): array
+{
+    // PYTHONDONTWRITEBYTECODE: __pycache__ を作らせない (scripts/ 配下の台帳検査
+    // ScriptsReadmeInventoryTest の母集団を生成物で汚さないため)。
+    $process = new Process(
+        ['python3', '-m', 'unittest', ...$modules],
+        bitsTestsDir(),
+        ['PYTHONDONTWRITEBYTECODE' => '1'],
+    );
+    $process->setTimeout(120);
+    $process->run();
+
+    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
+}
+
+test('python3 が PATH にあること (環境不備を skip で隠さない)', function (): void {
+    expect((new Process(['which', 'python3']))->run())->toBe(
+        0,
+        'python3 が PATH に無い。bug-hunt 目録の生成器は python3 必須 (stdlib のみ)。'
+    );
+});
+
+test('生成器の Python 自己テストが composer test の下で通ること', function (): void {
+    expect(is_dir(bitsTestsDir()))->toBeTrue('scripts/tests が見つからない: '.bitsTestsDir());
+
+    [$code, $out] = bitsRunUnittest(['test_bug_hunt_inventory']);
+
+    expect($code)->toBe(0, "bug-hunt 目録の生成器の自己テストが失敗しました:\n".$out);
+});
+
+test('負の対照: 存在しないモジュール名を渡すと非 0 になること (空振り gate を作らない)', function (): void {
+    [$code] = bitsRunUnittest(['test_no_such_module_exists']);
+
+    expect($code)->not->toBe(0, '存在しないモジュールでも 0 が返る = 実走していない疑い');
+});
diff --git a/tests/Architecture/ScriptsReadmeInventoryTest.php b/tests/Architecture/ScriptsReadmeInventoryTest.php
index 3dd9107..9f5da61 100644
--- a/tests/Architecture/ScriptsReadmeInventoryTest.php
+++ b/tests/Architecture/ScriptsReadmeInventoryTest.php
@@ -45,6 +45,12 @@ function scriptsDirectoryFiles(string $scriptsDir): array
             continue;
         }
         $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($scriptsDir) + 1));
+        // Python の bytecode キャッシュは git 管理外の生成物 (.gitignore 済み)。
+        // 台帳は「人が書いたスクリプト」の一覧なので、自己テストを手で走らせた副産物で
+        // 無関係な gate が赤くならないように母集団から外す。
+        if (str_contains($relative, '__pycache__/')) {
+            continue;
+        }
         $found[] = $relative;
     }
 
diff --git a/tests/Feature/Bughunt/InventoryScanCommandTest.php b/tests/Feature/Bughunt/InventoryScanCommandTest.php
new file mode 100644
index 0000000..d496745
--- /dev/null
+++ b/tests/Feature/Bughunt/InventoryScanCommandTest.php
@@ -0,0 +1,153 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Bughunt\InventoryScanData;
+use Illuminate\Support\Facades\Artisan;
+use Illuminate\Support\Facades\Route;
+use Tests\Support\SplitConsoleOutput;
+
+/*
+ * 目録の機械事実を書き出す抽出コマンド (bughunt:inventory-scan) の契約 (T176)。
+ *
+ * このコマンドは「事実の書き出し」だけを行う。面の判定・分類・除外は 1 つも持たない
+ * (判定は生成器 scripts/bug-hunt-inventory.py に一本化してあり、同じ規則を 2 言語へ置かない)。
+ * よって全 route を出力し、名前の無い route も落とさない。
+ *
+ * 抽出条件 (local もしくはテスト実行中) は routes/web.php の debug route 登録条件と
+ * **同じ述語**であり、二重管理になっている。片方だけ変えると母集合が黙って変わるので、
+ * 条件を変えるときは routes/web.php と本テストの両方を直すこと。
+ */
+
+/**
+ * コマンドを実行し [終了コード, 標準出力, 標準エラー] を返す。
+ *
+ * @return array{0: int, 1: string, 2: string}
+ */
+function inventoryScanRun(): array
+{
+    $output = new SplitConsoleOutput;
+    $exitCode = Artisan::call('bughunt:inventory-scan', [], $output);
+
+    return [$exitCode, $output->stdout(), $output->stderr()];
+}
+
+/**
+ * 抽出結果 (成功時) を配列で受け取る。
+ *
+ * @return array<string, mixed>
+ */
+function inventoryScanOutput(): array
+{
+    [$exitCode, $stdout] = inventoryScanRun();
+    expect($exitCode)->toBe(0, '抽出コマンドが非 0 で終了した');
+
+    $decoded = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
+    expect($decoded)->toBeArray();
+    /** @var array<string, mixed> $decoded */
+
+    return $decoded;
+}
+
+/**
+ * 出力から route 名で 1 件引く。
+ *
+ * @param  array<string, mixed>  $output
+ * @return array<string, mixed>|null
+ */
+function inventoryScanRoute(array $output, string $name): ?array
+{
+    $routes = $output['routes'];
+    expect($routes)->toBeArray();
+    /** @var list<mixed> $routes */
+    foreach ($routes as $route) {
+        if (is_array($route) && ($route['name'] ?? null) === $name) {
+            /** @var array<string, mixed> $route */
+            return $route;
+        }
+    }
+
+    return null;
+}
+
+test('抽出結果が 1 行の JSON で、宣言した形を持つこと', function (): void {
+    [$exitCode, $stdout] = inventoryScanRun();
+    expect($exitCode)->toBe(0);
+    expect(substr_count(trim($stdout), "\n"))->toBe(0, '出力は 1 行の JSON であること (人間向けの装飾を混ぜない)');
+
+    $output = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
+    expect($output)->toBeArray();
+    /** @var array<string, mixed> $output */
+    expect($output['schema_version'])->toBe(InventoryScanData::SCHEMA_VERSION);
+    expect($output['extraction_condition'])->toBe(InventoryScanData::EXTRACTION_CONDITION);
+    expect($output['routes'])->toBeArray()->not->toBeEmpty();
+
+    $routes = $output['routes'];
+    expect($routes)->toBeArray();
+    /** @var list<mixed> $routes */
+    $route = $routes[0];
+    expect($route)->toBeArray();
+    /** @var array<string, mixed> $route */
+    expect(array_keys($route))->toBe(['name', 'uri', 'methods', 'middleware', 'action', 'title']);
+});
+
+test('web group を宣言した route の middleware に文字列 web がそのまま残ること', function (): void {
+    $route = inventoryScanRoute(inventoryScanOutput(), 'dashboard');
+
+    expect($route)->not->toBeNull();
+    /** @var array<string, mixed> $route */
+    // gatherMiddleware() は group を展開しない (web が消えたら生成器の面の判定が壊れる)。
+    expect($route['middleware'])->toContain('web');
+});
+
+test('config(seo.app_titles) にある route は題名が引け、無い route は null になること', function (): void {
+    config(['seo.app_titles' => ['dashboard' => 'ダッシュボード']]);
+
+    $output = inventoryScanOutput();
+
+    $withTitle = inventoryScanRoute($output, 'dashboard');
+    expect($withTitle)->not->toBeNull();
+    /** @var array<string, mixed> $withTitle */
+    expect($withTitle['title'])->toBe('ダッシュボード');
+
+    $withoutTitle = inventoryScanRoute($output, 'login');
+    expect($withoutTitle)->not->toBeNull();
+    /** @var array<string, mixed> $withoutTitle */
+    expect($withoutTitle['title'])->toBeNull();
+});
+
+test('名前の無い route も出力に含まれること (面の判定はコマンドの責務ではない)', function (): void {
+    Route::get('bughunt-inventory-scan-anonymous', fn () => 'ok');
+
+    $output = inventoryScanOutput();
+    $routes = $output['routes'];
+    expect($routes)->toBeArray();
+    /** @var list<array<string, mixed>> $routes */
+    $anonymous = array_values(array_filter(
+        $routes,
+        fn (array $route): bool => $route['uri'] === 'bughunt-inventory-scan-anonymous',
+    ));
+
+    expect($anonymous)->toHaveCount(1);
+    expect($anonymous[0]['name'])->toBeNull();
+});
+
+test('抽出条件を満たさない環境では非 0 終了し、標準出力に 1 バイトも出さないこと', function (): void {
+    // Laravel 12 の isLocal() は $this['env'] === 'local'、runningUnitTests() は
+    // bound('env') && $this['env'] === 'testing' で、どちらも同じ束縛 env を読む。
+    // よって env を差し替えれば両方を false にできる。detectEnvironment() は
+    // $_SERVER['argv'] を見る経路があるので使わない。
+    $original = app('env');
+
+    try {
+        app()->instance('env', 'production');
+
+        [$exitCode, $stdout, $stderr] = inventoryScanRun();
+
+        expect($exitCode)->not->toBe(0, '抽出条件を満たさない環境では成功にしない');
+        expect($stdout)->toBe('', '壊れた入力を後段へ渡さない (標準出力へは 1 バイトも出さない)');
+        expect($stderr)->not->toBe('', '理由は標準エラーへ出す');
+    } finally {
+        app()->instance('env', $original);
+    }
+});
diff --git a/tests/Support/SplitConsoleOutput.php b/tests/Support/SplitConsoleOutput.php
new file mode 100644
index 0000000..dea847d
--- /dev/null
+++ b/tests/Support/SplitConsoleOutput.php
@@ -0,0 +1,69 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use LogicException;
+use Symfony\Component\Console\Output\BufferedOutput;
+use Symfony\Component\Console\Output\ConsoleOutputInterface;
+use Symfony\Component\Console\Output\ConsoleSectionOutput;
+use Symfony\Component\Console\Output\Output;
+use Symfony\Component\Console\Output\OutputInterface;
+
+/**
+ * 標準出力と標準エラーを別々の buffer に貯める Console 出力。
+ *
+ * `Artisan::call()` の既定 buffer は `BufferedOutput` (= ConsoleOutputInterface ではない) なので、
+ * Symfony の `getErrorStyle()` が標準エラーへ落ちず**同じ buffer**へ書き戻る。
+ * それでは「標準出力には 1 バイトも出さない」という契約を検証できないため、
+ * 2 本を分けて受け取れる出力をテスト側から注入する。
+ */
+final class SplitConsoleOutput extends Output implements ConsoleOutputInterface
+{
+    private string $stdout = '';
+
+    private OutputInterface $stderr;
+
+    public function __construct()
+    {
+        parent::__construct(OutputInterface::VERBOSITY_NORMAL, false);
+
+        $this->stderr = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
+    }
+
+    /** 標準出力へ書かれた内容。 */
+    public function stdout(): string
+    {
+        return $this->stdout;
+    }
+
+    /** 標準エラーへ書かれた内容。 */
+    public function stderr(): string
+    {
+        $stderr = $this->stderr;
+
+        return $stderr instanceof BufferedOutput ? $stderr->fetch() : '';
+    }
+
+    public function getErrorOutput(): OutputInterface
+    {
+        return $this->stderr;
+    }
+
+    public function setErrorOutput(OutputInterface $error): void
+    {
+        $this->stderr = $error;
+    }
+
+    public function section(): ConsoleSectionOutput
+    {
+        // 区画付き出力は実ストリームを要する。抽出コマンドは使わないので到達しない。
+        throw new LogicException('SplitConsoleOutput は section() を提供しない');
+    }
+
+    protected function doWrite(string $message, bool $newline): void
+    {
+        $this->stdout .= $message.($newline ? PHP_EOL : '');
+    }
+}

```

## 文書差分 (git diff: AGENTS.md / docs/template-divergence.md / scripts/README.md と生成物 2 本)

```diff
diff --git a/.claude/skills/app-bug-hunt/operations.md b/.claude/skills/app-bug-hunt/operations.md
index ba745cc..3ef4565 100644
--- a/.claude/skills/app-bug-hunt/operations.md
+++ b/.claude/skills/app-bug-hunt/operations.md
@@ -1,24 +1,24 @@
 # 操作インベントリ (operations.md) — AI-CUE
 
-> bug-hunt カバレッジの分母となる「書き込み操作」(非GET × web セッション面) の一覧。`php artisan route:list`
-> から生成しストーリー (S1..S7) を割り当てた。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
-> 列フォーマット: markdown leading-pipe 5 列 `| method | route | name | story | 区分 |` (correlate.py 依存)。
+> **このファイルは生成物である。手で編集しない。**
+> 直し方: `.claude/skills/app-bug-hunt/inventory/annotations.toml` (割当・区分・理由) か
+> `inventory/notes-*.md` (散文) を直してから `python3 scripts/bug-hunt-inventory.py generate` を走らせる。
+> 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。
+> ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。
+
+bug-hunt カバレッジの分母となる「書き込み操作」(非 GET × web セッション面) の一覧。全 79 件 (うち対象外 1 件)。列は method / route / name / story / 区分 の 5 列固定 (coverage/correlate.py の入力契約。ヘッダ名を変えない)。
 
 ## 操作一覧 (web セッション面)
 
 | method | route | name | story | 区分 |
 |---|---|---|---|---|
+| POST | billing/auto-recharge/setup | billing.auto-recharge.setup | S5 | 通常 |
+| POST | billing/auto-recharge | billing.auto-recharge.update | S5 | 通常 |
 | POST | billing/checkout | billing.checkout | S5 | 通常 |
+| PATCH | billing/contact | billing.contact.update | S5 | 通常 |
 | POST | billing/plan | billing.plan.change | S5 | 通常 |
 | POST | billing/portal | billing.portal | S5 | 通常 |
-| POST | billing/auto-recharge | billing.auto-recharge.update | S5 | 通常 |
-| POST | billing/auto-recharge/setup | billing.auto-recharge.setup | S5 | 通常 |
-| PATCH | billing/contact | billing.contact.update | S5 | 通常 |
 | POST | purchase-tickets/checkout | billing.tickets.checkout | S5 | 通常 |
-| POST | onboarding/activate-personal | onboarding.activate-personal | S1 | 通常 |
-| POST | notifications/read-all | notifications.read-all | S6 | 通常 |
-| POST | notifications/{notification}/open | notifications.open | S6 | 通常 |
-| POST | notifications/{notification}/read | notifications.read | S6 | 通常 |
 | POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/adopt | capture.takes.adopt | S3 | 通常 |
 | DELETE | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take} | capture.takes.destroy | S3 | 通常 |
 | POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/downloaded | capture.takes.downloaded | S3 | 通常 |
@@ -27,23 +27,27 @@ ## 操作一覧 (web セッション面)
 | POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/upload-url | capture.takes.upload-url | S3 | 通常 |
 | POST | contact | contact.store | S1 | 通常 |
 | POST | debug/login/{userId} | debug.login-as | S1 | 通常 |
-| POST | invitations/accept | invitations.accept.store | S2 | 通常 |
 | POST | invitations/{invitation}/accept-in-app | invitations.accept-in-app | S2 | 通常 |
+| POST | invitations/accept | invitations.accept.store | S2 | 通常 |
 | POST | login | login.store | S1 | 通常 |
 | POST | logout | logout | S1 | 通常 |
-| DELETE | organizations/{organization:slug}/api-keys/{apiKey} | organizations.api-keys.revoke | S4 | 通常 |
-| DELETE | organizations/{organization:slug}/api-keys/sessions/{oauthSession} | organizations.api-keys.sessions.revoke | S4 | 通常 |
-| POST | organizations/{organization:slug}/api-keys | organizations.api-keys.store | S4 | 通常 |
-| DELETE | organizations/{organization:slug}/invitations/{invitation} | organizations.invitations.revoke | S2 | 通常 |
-| POST | organizations/{organization:slug}/invitations | organizations.invitations.store | S2 | 通常 |
-| DELETE | organizations/{organization:slug}/members/{user} | organizations.members.destroy | S2 | 通常 |
-| DELETE | organizations/{organization:slug}/members/{user}/two-factor | organizations.members.two-factor.reset | S2 | 通常 |
-| PATCH | organizations/{organization:slug}/members/{user} | organizations.members.update | S2 | 通常 |
+| POST | notifications/{notification}/open | notifications.open | S6 | 通常 |
+| POST | notifications/{notification}/read | notifications.read | S6 | 通常 |
+| POST | notifications/read-all | notifications.read-all | S6 | 通常 |
+| POST | onboarding/activate-personal | onboarding.activate-personal | S1 | 通常 |
+| DELETE | organizations/{organization}/api-keys/{apiKey} | organizations.api-keys.revoke | S4 | 通常 |
+| DELETE | organizations/{organization}/api-keys/sessions/{oauthSession} | organizations.api-keys.sessions.revoke | S4 | 通常 |
+| POST | organizations/{organization}/api-keys | organizations.api-keys.store | S4 | 通常 |
+| DELETE | organizations/{organization}/invitations/{invitation} | organizations.invitations.revoke | S2 | 通常 |
+| POST | organizations/{organization}/invitations | organizations.invitations.store | S2 | 通常 |
+| DELETE | organizations/{organization}/members/{user} | organizations.members.destroy | S2 | 通常 |
+| DELETE | organizations/{organization}/members/{user}/two-factor | organizations.members.two-factor.reset | S2 | 通常 |
+| PATCH | organizations/{organization}/members/{user} | organizations.members.update | S2 | 通常 |
 | POST | organizations | organizations.store | S4 | 通常 |
 | POST | organizations/{organization}/switch | organizations.switch | S4 | 通常 |
-| POST | organizations/{organization:slug}/transfer-ownership | organizations.transfer-ownership | S4 | 通常 |
-| PATCH | organizations/{organization:slug}/two-factor-requirement | organizations.two-factor-requirement.update | S4 | 通常 |
-| PATCH | organizations/{organization:slug} | organizations.update | S4 | 通常 |
+| POST | organizations/{organization}/transfer-ownership | organizations.transfer-ownership | S4 | 通常 |
+| PATCH | organizations/{organization}/two-factor-requirement | organizations.two-factor-requirement.update | S4 | 通常 |
+| PATCH | organizations/{organization} | organizations.update | S4 | 通常 |
 | POST | passkeys/confirm | passkey.confirm | S6 | 通常 |
 | DELETE | user/passkeys/{passkey} | passkey.destroy | S6 | 通常 |
 | POST | passkeys/login | passkey.login | S1 | 通常 |
@@ -60,8 +64,8 @@ ## 操作一覧 (web セッション面)
 | POST | projects/{project}/items | projects.items.store | S4 | 通常 |
 | PATCH | projects/{project}/items/{item} | projects.items.update | S4 | 通常 |
 | POST | projects/{project}/manuals/{manual}/analyze | projects.manuals.analyze | S3 | 通常 |
-| POST | projects/{project}/manuals/{manual}/duplicate | projects.manuals.duplicate | S3 | 通常 |
 | DELETE | projects/{project}/manuals/{manual} | projects.manuals.destroy | S3 | 通常 |
+| POST | projects/{project}/manuals/{manual}/duplicate | projects.manuals.duplicate | S3 | 通常 |
 | POST | projects/{project}/manuals/{manual}/preview | projects.manuals.preview | S3 | 通常 |
 | POST | projects/{project}/manuals/{manual}/render | projects.manuals.render | S3 | 通常 |
 | PUT | projects/{project}/manuals/{manual}/scenario | projects.manuals.scenario.update | S3 | 通常 |
@@ -74,9 +78,9 @@ ## 操作一覧 (web セッション面)
 | PATCH | projects/{project} | projects.update | S4 | 通常 |
 | POST | recent-auth/password | recent-auth.password | S6 | 通常 |
 | POST | register | register.store | S1 | 通常 |
-| DELETE | settings/account | settings.account.destroy | S6 | 通常 |
-| POST | settings/account/deletion-request | settings.account.deletion-request.store | S6 | 通常 |
 | DELETE | settings/account/deletion-request | settings.account.deletion-request.destroy | S6 | 通常 |
+| POST | settings/account/deletion-request | settings.account.deletion-request.store | S6 | 通常 |
+| DELETE | settings/account | settings.account.destroy | S6 | 通常 |
 | POST | settings/password | settings.password.store | S6 | 通常 |
 | POST | user/confirmed-two-factor-authentication | two-factor.confirm | S6 | 通常 |
 | DELETE | user/two-factor-authentication | two-factor.disable | S6 | 通常 |
@@ -86,6 +90,18 @@ ## 操作一覧 (web セッション面)
 | PUT | user/password | user-password.update | S6 | 通常 |
 | PUT | user/profile-information | user-profile-information.update | S6 | 通常 |
 | POST | email/verification-notification | verification.send | S1 | 通常 |
+| POST | ses/notification | webhooks.ses | - | 外 |
+
+## 対象外の理由
+
+- `webhooks.ses` — 外部の配信基盤からの通知を受ける機械向けの受け口でありブラウザ操作で叩く経路ではないため分母に載せない
+
+<!--
+  operations.md の末尾へそのまま連結される散文。人が書く (生成器は中身を読まない)。
+  **表を書かないこと** — coverage/correlate.py は operations.md を頭から走査し、
+  直近のヘッダの列割当で `|` 始まりの行を操作行として読むため、ここに表があると
+  注釈に無い行が操作として数えられる。段 2 が表の混入を drift として拒否する。
+-->
 
 ## 課金ゲート allowlist と認可 (P4 反転後、要検出)
 
diff --git a/.claude/skills/app-bug-hunt/screens.md b/.claude/skills/app-bug-hunt/screens.md
index 482c24e..c81adeb 100644
--- a/.claude/skills/app-bug-hunt/screens.md
+++ b/.claude/skills/app-bug-hunt/screens.md
@@ -1,74 +1,108 @@
 # 画面インベントリ (screens.md) — AI-CUE
 
-> bug-hunt カバレッジの分母となる「画面」(GET × inertia × web) の一覧。`php artisan route:list` から生成し
-> ストーリー (S1..S7) を割り当てた。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
-> 対象外 (seo/social/sso/2fa下位/legal confirmation 等) は OUT_OF_SCOPE_PREFIXES で除外済み。
+> **このファイルは生成物である。手で編集しない。**
+> 直し方: `.claude/skills/app-bug-hunt/inventory/annotations.toml` (割当・区分・理由) か
+> `inventory/notes-*.md` (散文) を直してから `python3 scripts/bug-hunt-inventory.py generate` を走らせる。
+> 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。
+> ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。
+
+bug-hunt カバレッジの分母となる「画面」(GET × web セッション面) の一覧。全 68 件 (うち対象外 13 件)。
 
 ## GET × web 一覧 (画面 + 画面に付随する JSON GET)
 
-> 本表は「GET × web セッション面」の一覧であり、**Inertia 画面だけではない**。
-> 以下は画面ではなく**画面に付随する JSON GET** として載せている
-> (bug-hunt は単独で開かず、対応する画面操作の副作用として通過させる):
-> `capture.csrf-cookie` / `session.status` / `passkey.registration-options` /
-> `passkey.login-options` / `passkey.confirm-options`
-
-| route (URL) | name | 割当ストーリー |
-|---|---|---|
-| / | home | S1 |
-| app | capture.home | S3 |
-| app/csrf-cookie | capture.csrf-cookie | S3 |
-| app/projects/{project}/manuals | capture.manuals.index | S3 |
-| app/projects/{project}/manuals/{manual} | capture.manuals.show | S3 |
-| app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback | capture.takes.playback | S3 |
-| billing | billing.index | S5 |
-| billing-required | onboarding.billing-required | S2 |
-| billing/plans | billing.plans | S5 |
-| commerce-disclosure | legal.commerce-disclosure | S1 |
-| contact | contact | S1 |
-| contact/thanks | contact.thanks | S1 |
-| dashboard | dashboard | S1 |
-| email/verify | verification.notice | S1 |
-| email/verify/{id}/{hash} | verification.verify | S1 |
-| forgot-password | password.request | S1 |
-| invitations/accept | invitations.accept | S2 |
-| login | login | S1 |
-| manage/users | manage.users.index | S4 |
-| notifications | notifications.index | S6 |
-| onboarding/checkout | onboarding.checkout | S1 |
-| organizations/create | organizations.create | S4 |
-| organizations/{organization:slug}/api-keys | organizations.api-keys.index | S4 |
-| organizations/{organization:slug}/api-keys/sessions | organizations.api-keys.sessions.index | S4 |
-| organizations/{organization:slug}/onboarding/cli | organizations.onboarding.cli | S4 |
-| organizations/{organization:slug}/onboarding/mcp | organizations.onboarding.mcp | S4 |
-| organizations/{organization:slug}/settings | organizations.settings | S4 |
-| passkeys/confirm/options | passkey.confirm-options | S6 |
-| passkeys/login/options | passkey.login-options | S1 |
-| pricing | pricing | S5 |
-| privacy | legal.privacy | S1 |
-| purchase-tickets | billing.tickets.show | S5 |
-| projects | projects.index | S4 |
-| projects/create | projects.create | S4 |
-| projects/{project} | projects.show | S3 |
-| projects/{project}/categories | projects.categories.index | S4 |
-| projects/{project}/edit | projects.edit | S4 |
-| projects/{project}/manuals/create | projects.manuals.create | S3 |
-| projects/{project}/manuals/{manual} | projects.manuals.show | S3 |
-| projects/{project}/manuals/{manual}/download | projects.manuals.download | S3 |
-| projects/{project}/manuals/{manual}/edit | projects.manuals.edit | S3 |
-| projects/{project}/manuals/{manual}/jobs/{analysisJob} | projects.manuals.jobs.show | S3 |
-| projects/{project}/manuals/{manual}/render-jobs/{renderJob} | projects.manuals.render-jobs.show | S3 |
-| projects/{project}/manuals/{manual}/render-jobs/{renderJob}/playback | projects.manuals.render-jobs.playback | S3 |
-| recent-auth/confirm | recent-auth.confirm | S6 |
-| recent-auth/status | recent-auth.status | S6 |
-| register | register | S1 |
-| reset-password/{token} | password.reset | S1 |
-| session/status | session.status | S6 |
-| settings | settings | S6 |
-| settings/security | settings.security | S6 |
-| terms | legal.terms | S1 |
-| two-factor-challenge | two-factor.login | S1 |
-| user/confirm-password | password.confirm | S6 |
-| user/passkeys/options | passkey.registration-options | S6 |
+| route (URL) | name | 種別 | 画面名 | 割当ストーリー | 区分 |
+|---|---|---|---|---|---|
+| billing | billing.index | 画面 | プランとお支払い | S5 | 通常 |
+| billing/plans | billing.plans | 画面 | プラン比較 | S5 | 通常 |
+| purchase-tickets | billing.tickets.show | 画面 | チケットを購入 | S5 | 通常 |
+| app/csrf-cookie | capture.csrf-cookie | JSON | - | S3 | 通常 |
+| app | capture.home | 画面 | - | S3 | 通常 |
+| app/projects/{project}/manuals | capture.manuals.index | 画面 | 撮影するマニュアルを選ぶ | S3 | 通常 |
+| app/projects/{project}/manuals/{manual} | capture.manuals.show | 画面 | - | S3 | 通常 |
+| app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback | capture.takes.playback | 画面 | - | S3 | 通常 |
+| contact | contact | 画面 | お問い合わせ | S1 | 通常 |
+| contact/thanks | contact.thanks | 画面 | お問い合わせ完了 | S1 | 通常 |
+| dashboard | dashboard | 画面 | ダッシュボード | S1 | 通常 |
+| debug/bfcache-trial | debug.bfcache-trial | 画面 | - | - | 外 |
+| debug/bfcache-trial/away | debug.bfcache-trial.away | 画面 | - | - | 外 |
+| debug/login | debug.login | 画面 | - | - | 外 |
+| / | home | 画面 | - | S1 | 通常 |
+| invitations/accept | invitations.accept | 画面 | 組織への招待 | S2 | 通常 |
+| commerce-disclosure | legal.commerce-disclosure | 画面 | - | S1 | 通常 |
+| privacy | legal.privacy | 画面 | - | S1 | 通常 |
+| terms | legal.terms | 画面 | - | S1 | 通常 |
+| login | login | 画面 | ログイン | S1 | 通常 |
+| manage/users | manage.users.index | 画面 | ユーザー管理 | S4 | 通常 |
+| notifications | notifications.index | 画面 | 通知 | S6 | 通常 |
+| billing-required | onboarding.billing-required | 画面 | 課金手続き中です | S2 | 通常 |
+| onboarding/checkout | onboarding.checkout | 画面 | プランの選択 | S1 | 通常 |
+| organizations/{organization}/api-keys | organizations.api-keys.index | 画面 | API キー | S4 | 通常 |
+| organizations/{organization}/api-keys/sessions | organizations.api-keys.sessions.index | 画面 | 接続セッション | S4 | 通常 |
+| organizations/create | organizations.create | 画面 | 組織の作成 | S4 | 通常 |
+| organizations/{organization}/onboarding/cli | organizations.onboarding.cli | 画面 | CLI 導入ガイド | S4 | 通常 |
+| organizations/{organization}/onboarding/mcp | organizations.onboarding.mcp | 画面 | MCP 導入ガイド | S4 | 通常 |
+| organizations/{organization}/settings | organizations.settings | 画面 | 組織設定 | S4 | 通常 |
+| passkeys/confirm/options | passkey.confirm-options | JSON | - | S6 | 通常 |
+| passkeys/login/options | passkey.login-options | JSON | - | S1 | 通常 |
+| user/passkeys/options | passkey.registration-options | JSON | - | S6 | 通常 |
+| user/confirm-password | password.confirm | 画面 | パスワードの確認 | S6 | 通常 |
+| user/confirmed-password-status | password.confirmation | JSON | - | - | 外 |
+| forgot-password | password.request | 画面 | パスワードリセット | S1 | 通常 |
+| reset-password/{token} | password.reset | 画面 | パスワードリセット | S1 | 通常 |
+| pricing | pricing | 画面 | - | S5 | 通常 |
+| projects/{project}/categories | projects.categories.index | 画面 | カテゴリ管理 | S4 | 通常 |
+| projects/create | projects.create | 画面 | プロジェクトの作成 | S4 | 通常 |
+| projects/{project}/edit | projects.edit | 画面 | プロジェクトの編集 | S4 | 通常 |
+| projects | projects.index | 画面 | プロジェクト | S4 | 通常 |
+| projects/{project}/manuals/create | projects.manuals.create | 画面 | 動画マニュアルの作成 | S3 | 通常 |
+| projects/{project}/manuals/{manual}/download | projects.manuals.download | 画面 | - | S3 | 通常 |
+| projects/{project}/manuals/{manual}/edit | projects.manuals.edit | 画面 | - | S3 | 通常 |
+| projects/{project}/manuals/{manual}/jobs/{analysisJob} | projects.manuals.jobs.show | 画面 | - | S3 | 通常 |
+| projects/{project}/manuals/{manual}/render-jobs/{renderJob}/playback | projects.manuals.render-jobs.playback | 画面 | - | S3 | 通常 |
+| projects/{project}/manuals/{manual}/render-jobs/{renderJob} | projects.manuals.render-jobs.show | 画面 | - | S3 | 通常 |
+| projects/{project}/manuals/{manual} | projects.manuals.show | 画面 | - | S3 | 通常 |
+| projects/{project} | projects.show | 画面 | - | S3 | 通常 |
+| recent-auth/confirm | recent-auth.confirm | 画面 | 本人確認 | S6 | 通常 |
+| recent-auth/status | recent-auth.status | 画面 | - | S6 | 通常 |
+| register | register | 画面 | アカウント登録 | S1 | 通常 |
+| ai.txt | seo.ai | JSON | - | - | 外 |
+| llms.txt | seo.llms | JSON | - | - | 外 |
+| robots.txt | seo.robots | JSON | - | - | 外 |
+| sitemap.xml | seo.sitemap | JSON | - | - | 外 |
+| session/status | session.status | JSON | - | S6 | 通常 |
+| settings | settings | 画面 | 設定 | S6 | 通常 |
+| settings/security | settings.security | 画面 | セキュリティ設定 | S6 | 通常 |
+| auth/{provider}/callback | social.callback | 画面 | - | - | 外 |
+| auth/{provider}/redirect/{intent} | social.redirect | 画面 | - | - | 外 |
+| two-factor-challenge | two-factor.login | 画面 | 2要素認証 | S1 | 通常 |
+| user/two-factor-qr-code | two-factor.qr-code | JSON | - | - | 外 |
+| user/two-factor-recovery-codes | two-factor.recovery-codes | JSON | - | - | 外 |
+| user/two-factor-secret-key | two-factor.secret-key | JSON | - | - | 外 |
+| email/verify | verification.notice | 画面 | メール認証 | S1 | 通常 |
+| email/verify/{id}/{hash} | verification.verify | 画面 | - | S1 | 通常 |
+
+## 対象外の理由
+
+- `debug.bfcache-trial` — 履歴復元の実機受入確認のための検証ページであり製品の利用者が到達する画面ではないため分母に載せない
+- `debug.bfcache-trial.away` — 履歴復元の実機受入確認で離脱先に使う検証ページであり製品の利用者が到達する画面ではないため分母に載せない
+- `debug.login` — 開発環境専用のログイン補助画面であり探索は POST の debug.login-as で前提を組むため分母に載せない
+- `password.confirmation` — 再認証が有効かどうかだけを返す状態問い合わせであり画面として開く経路ではないため分母に載せない
+- `seo.ai` — 生成 AI のクローラ向けの機械可読 route であり人が操作する画面ではないため分母に載せない
+- `seo.llms` — 生成 AI のクローラ向けの機械可読 route であり人が操作する画面ではないため分母に載せない
+- `seo.robots` — クローラ向けの機械可読 route であり人が操作する画面ではないため探索の分母に載せない
+- `seo.sitemap` — クローラ向けの機械可読 route であり人が操作する画面ではないため探索の分母に載せない
+- `social.callback` — 外部の識別提供者から戻る受け口であり実際の識別提供者なしには到達できないため分母に載せない
+- `social.redirect` — 外部の識別提供者へ出ていく遷移であり隔離した探索環境の外へ出てしまうため分母に載せない
+- `two-factor.qr-code` — 第二要素の秘密を図として返す開示 endpoint であり単独で開くと秘密が走行記録に残るため分母に載せない
+- `two-factor.recovery-codes` — 復旧コードを返す開示 endpoint であり単独で開くと秘密が走行記録に残るため分母に載せない
+- `two-factor.secret-key` — 第二要素の秘密そのものを返す開示 endpoint であり単独で開くと秘密が走行記録に残るため分母に載せない
+
+<!--
+  screens.md の末尾へそのまま連結される散文。人が書く (生成器は中身を読まない)。
+  表を書かないこと (連結先を読む coverage/correlate.py が操作行として拾ってしまう)。
+-->
+
+## 画面に関する既知の仕様 (散文)
 
 **非 Inertia の GET (画面ではないが分母に載せているもの)**:
 `capture.csrf-cookie` (撮影 PWA の CSRF cookie 発行) と `session.status`
diff --git a/AGENTS.md b/AGENTS.md
index b1f2b6d..ad5346d 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -414,8 +414,20 @@ ## bug-hunt (LLM 探索的バグハント、オプトイン)
   **保証しないもの**は `docs/architecture.md` §パイプライン通し確認 が正本。
 - **worktree 既定**: bug-hunt は worktree から走る (`scripts/bughunt-worktree-hook.sh` の PreToolUse ガードが
   main 直叩きを早期に止める。配線は `.claude/settings.json` に常設済み。§常設 hook 配線)。
-- **スケルトン**: `screens.md` / `operations.md` / `stories/` はテンプレートでは空スケルトン。初回に
-  `php artisan route:list` から生成する (SKILL.md Phase 1)。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
+- **目録は生成物 (T176)**: `screens.md` / `operations.md` は手で書かない。実装の機械事実
+  (`php artisan bughunt:inventory-scan`) と、人が書く注釈 (`inventory/annotations.toml`) ・
+  散文 (`inventory/notes-*.md`) から `python3 scripts/bug-hunt-inventory.py generate` で作る。
+  route を足したら**注釈を 1 行足して再生成する** (表の行は手で書かない)。
+  ドリフト検査は `scripts/bug-hunt-inventory-check.sh` (判定は生成器側。exit 0=一致 /
+  2=致命 / 3=ドリフト) で、守るのは 4 つ — 再生成の忘れ・生成物の手編集 (段 3 の byte 比較) /
+  意味の欠落 = 新しい route に割当も対象外理由も無い (段 2) / 抽出の故障 = 環境違い・母集合 0 件
+  (段 1) / 機能カタログの代表機構が実在しないこと (段 4)。
+  **見るのは `web` group を宣言した面だけ**で、機械向け API・Filament 管理画面・MCP・webhook には
+  沈黙する。面として除くのは先頭セグメントの `oauth` と `livewire-{hash}` の 2 つだけで、
+  それ以外で `web` を宣言した route は必ず目録に入り注釈を要求される。web 面のうち探索の分母に
+  載せないものは注釈の区分 `外` として**目録に見える形で**理由付きで宣言する。
+  テンプレート正典との差 (機能カタログを生成しない / 注釈は TOML / 中間 JSON を持たない) は
+  `docs/template-divergence.md` **D20**。`stories/` はテンプレートでは空スケルトンのままである。
 - **capability 語彙**: finding の `capability_tag` の正本は
   `.claude/skills/app-bug-hunt/capability-catalog.md`(SOP→シナリオ→撮影→レンダの責務境界を
   先に定義し、その上に capability_id を割り当てる。未割当は `unmapped`・tag 不能は `unknown`)。
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 5661a87..bcf22be 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -886,3 +886,71 @@ ### 関連
   `tests/Architecture/PostBootRouteMutationInventoryTest.php`
 - 設計: `devnotes/20260815-2100-route-cache-middleware-attach/`
 - 契約の正本: `docs/app-integration-guide.md` §7c
+
+---
+
+## D20 ✅ bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 列・中間 JSON 無しで実装する
+
+家系の正典 (機能台帳 `bughunt-inventory-generation` の t1) は、bug-hunt の分母 (画面一覧 /
+操作一覧 / 機能カタログ) を実装から生成し、人が書く注釈ファイルと段階的なドリフト検査で守る形である。
+本アプリはこの**方式そのものは採る**が、次の 3 点で正典と形が違うので登録する。
+
+| 観点 | 家系の正典 / テンプレート | 本アプリ |
+|---|---|---|
+| 機能カタログ (`capability-catalog.md`) | 生成物。3 列は 機能 / 対応する画面 / 対応する操作 | **生成しない**。3 列は `id` / `機能 (actor→outcome)` / `代表機構 (route name)` を維持し、参照整合だけを検査する |
+| 注釈ファイル | `inventory/annotations.yaml` | **`inventory/annotations.toml`** |
+| 中間成果物 | `inventory/inventory.json` をコミットする | **持たない** (生成・検査の実行中にだけ存在する) |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **id 列は所見記録の語彙正本である**。`.claude/skills/app-bug-hunt/ledger/findings.schema.json` の
+   必須項目 `capability_tag` は機能カタログの id を値に取る。正典の 3 列には id 列が無く、
+   寄せると語彙の供給元が消えて `unknown` / `unmapped` の判定基準ごと壊れる。
+   また「機能 ↔ 画面 / 操作」の対応は注釈側が route ごとに持つので、カタログにも書くと
+   同じ対応関係が 2 か所に載る (家系が AG-044 でやめた形)。カタログ本体は
+   「機構を利用者価値で束ねた overlay であり MECE ではない」と自ら宣言しており、
+   実装から導けない = 生成対象にしても保守量は減らない。
+2. **注釈が TOML なのは Python の依存規約の帰結である**。`AGENTS.md` §bug-hunt が
+   Python ツールを標準ライブラリのみと定めており、本環境に PyYAML は無い (実測)。
+   `tomllib` は標準ライブラリにあるので、YAML を採ると依存追加か自前パーサのどちらかが要る
+   (どちらも「自前機構の前に公式作法を確認する」に反する)。
+3. **中間 JSON に読み手がいない**。下流の照合器 (`coverage/correlate.py`) が読むのは
+   `operations.md` の name 列であって中間 JSON ではない。コミットするとドリフト面が 1 つ増えるだけで、
+   守るものが無い。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「目録は実装と注釈から再生成でき、ずれていたら CI が落ちる」
+
+| 不変条件 | 担い手 |
+|---|---|
+| 抽出が成功し、宣言した抽出条件で走り、母集合が 0 件でないこと (段 1) | `scripts/bug-hunt-inventory.py` (exit 2) / `scripts/tests/test_bug_hunt_inventory.py` |
+| 注釈の集合が面の集合と一致し、語彙・必須・理由の長さを満たすこと (段 2) | 同上 (exit 3)。未注釈も残置注釈も許さない |
+| 生成物が再生成の結果と byte 一致すること (段 3) | 同上 (exit 3)。手編集と再生成の忘れをまとめて捕まえる |
+| 機能カタログの代表機構が実在し、id が重複しないこと (段 4) | 同上 (exit 3) |
+| 検査シェルが判定を持たず、終了コード 0 / 2 / 3 を実際に返すこと | `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` (sandbox 実走) |
+| 生成器の自己テストが `composer test` の下で実走すること | `tests/Architecture/BughuntInventoryToolSelfTest.php` |
+| 抽出コマンドが事実だけを書き出すこと (面の判定を持たない) | `tests/Feature/Bughunt/InventoryScanCommandTest.php` |
+
+**保証範囲を誇張しない**: 見るのは `web` group を宣言した面だけで、機械向け API (`api/`) /
+Filament の管理画面 (`/admin`) / MCP / webhook には**沈黙する** (実測でこれらは `web` を
+宣言していない)。面として除くのは先頭セグメントの `oauth` と `livewire-{hash}` の 2 つだけで、
+それ以外で `web` を宣言した route は必ず目録に入り注釈を要求される。
+注釈の**内容**の妥当性 (割当が適切か) は見ない。画面題名の欠落も検出しない。
+機能カタログの網羅性も見ない (代表機構の実在と id の一意性まで)。
+目録の母集合は T164 の記録器が観測しうる route の**部分集合**であり、両者は一致しない。
+
+### 再検討の条件 (解消条件)
+
+- 家系の正典が id 列を持つ形へ変わったとき (機能カタログの生成を採り直す)
+- 本リポジトリの Python に依存を足す裁定が出たとき (注釈を YAML へ寄せる)
+- 中間 JSON を読む道具が家系に現れたとき
+
+### 関連
+
+- 実装: `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` /
+  `.claude/skills/app-bug-hunt/inventory/`
+- gate: `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` /
+  `tests/Architecture/BughuntInventoryToolSelfTest.php` /
+  `tests/Feature/Bughunt/InventoryScanCommandTest.php`
+- 設計: `devnotes/20260815-2100-bughunt-inventory-generator/`
diff --git a/scripts/README.md b/scripts/README.md
index 125a266..eae608d 100644
--- a/scripts/README.md
+++ b/scripts/README.md
@@ -32,7 +32,9 @@ ## スクリプト一覧
 | `setup-browser-testing.contract.test.ts` | `setup-browser-testing.sh` の契約テスト (決定表の sandbox 実走 / 静的契約 / pin された実 Playwright の出力との突合) | `pnpm test` |
 | `run-browser-test.sh` | Browser テスト (pest-plugin-browser) を**グローバルテストロック配下**で並列上限付きで実行。**Chromium / WebKit の 2 レーンが契約** (bfcache 復元シナリオは WebKit レーンが正本)。残留 playwright run-server を前後で掃除する (`@playwright/` = bug-hunt 側は除外)。起動時に bughunt ポート `:8010..8018` の best-effort pre-flight guard を掛ける (cap=4 より広く取るのは残留検出のため) | `composer test:browser` 等から呼び出し。レーン限定は `BROWSER_TEST_LANES` / 並列度は `BROWSER_TEST_PROCESSES` |
 | `bug-hunt-shard.sh` | bug-hunt シャードオーケストレータ。隔離環境 (DB `bug_hunt(_N)` / `:8010+N`) の provision / serve / teardown と、**dev DB を wipe しないための用途別 DB wrapper + 3-way hard-deny guard** を提供する (AGENTS.md §bug-hunt) | `/app-bug-hunt` から。`self-test` は実資源に触れず guard を検証 |
-| `bug-hunt-inventory-check.sh` | bug-hunt インベントリのドリフト検知。`route:list` と `.claude/skills/app-bug-hunt/{screens,operations}.md` の差分 (新ルート未追記 / 消失) を出す (exit 3 = 差分あり) | route 追加・削除時 / bug-hunt 実行前 |
+| `bug-hunt-inventory.py` | bug-hunt 目録 (`.claude/skills/app-bug-hunt/{screens,operations}.md`) の生成器兼検査器。`generate` は実装の機械事実 + 注釈 (`inventory/annotations.toml`) + 散文 (`inventory/notes-*.md`) から 2 ファイルを作り、`check` は同じ合成をメモリ上で行って byte 比較する (**1 バイトも書かない**)。exit 0=一致 / 2=致命 / 3=ドリフト | route 追加・削除時に `generate` / CI と bug-hunt 実行前に `check` |
+| `bug-hunt-inventory-check.sh` | bug-hunt 目録のドリフト検査の起動口。判定は持たず `bug-hunt-inventory.py check` を exec するだけ (同じ規則を 2 か所に置かない) | route 追加・削除時 / bug-hunt 実行前 / CI (`php` job) |
+| `tests/test_bug_hunt_inventory.py` | `bug-hunt-inventory.py` の自己テスト (標準ライブラリのみ)。実 `php` を呼ばず fake scanner で段 1..4 と差し替えの失敗経路を検証する | `composer test` (`tests/Architecture/BughuntInventoryToolSelfTest.php` が起動) |
 | `bughunt-worktree-hook.sh` | PreToolUse(Bash) ガード。`bug-hunt-shard.sh provision` の **main 直叩き** (worktree 指紋なし) を harness 層で拒否する (拒否は終了コード 97。起動子が 97 だけを 2 へ写す)。判定は bash の組み込みだけで完結し、外部コマンドを 1 つも使わない | `.claude/settings.json` に常設配線 (AGENTS.md §常設 hook 配線) |
 | `code-review-graph-update-hook.sh` | PostToolUse(Write/Edit) hook。コード索引 (code-review-graph) を `flock` 排他 + 内側 20 秒の時間切れ付きで差分更新する。何が起きても終了コード 0 で終わり、標準出力は常に空。告知はセッションごと・理由ごとに標準エラー 1 行だけ | `.claude/settings.json` に常設配線 (AGENTS.md §常設 hook 配線) |
 | `claude` | Claude Code を VSCode 拡張のネイティブバイナリ経由で起動 | (内部スクリプト) |

```

## 実測

- `composer test`: 5097 tests, 5095 passed, 2 skipped, 0 failed (21780 assertions)
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (1501) / `pnpm build`: 全 green
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106): 全 green
- `scripts/tests` で `python3 -m unittest test_bug_hunt_inventory`: 50 tests OK
- `bash scripts/bug-hunt-inventory-check.sh`: exit 0 (画面 68 件 / 操作 79 件)
- 移行の実測: 旧表の行は 1 件も落ちず、新しく見える route は 14 件
  (すべて区分 `外` + 30 文字以上の理由を人が記入)
- 生成後の operations.md を `coverage/correlate.py` の `load_operations()` で読むと 79 件で
  操作表と完全一致した

## 質問

上記の観点でレビューし、[Critical] があれば必ず指摘してほしい。
