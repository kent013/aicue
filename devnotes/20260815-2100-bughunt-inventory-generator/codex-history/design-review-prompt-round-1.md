## アプリの使命 (North Star) — AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 — AGENTS.md より

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---
あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン
- Python ツールは標準ライブラリのみ (PyYAML なし、tomllib あり)
- 本件は本番アプリ機能ではなく、開発支援スキル (.claude/skills/app-bug-hunt/) の目録保守方式の変更である

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert 使用）
4. テスト計画の網羅性（各施策に Pest / Python テスト、RefreshDatabase グローバル適用に従う）
5. DTO パターンの遵守
6. 副作用・後退リスク
7. 波及変更の網羅性（既存テスト・CI・下流ツール correlate.py が変更対象に含まれているか）
8. セキュリティ（AGENTS.md のセキュリティ不変条件。本件は Console Command と開発スクリプトのみ）
9. fail-closed の徹底（終了コード規約、部分的な成果物を残さないこと）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

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
            $this->components->error('抽出条件を満たさない環境では走らせない (local もしくはテスト実行時のみ)');

            return self::FAILURE; // = 1。生成器はこれを致命 (exit 2) へ写像する
        }

        $titles = $this->appTitles();          // config 境界で mixed を排除する
        $routes = [];
        foreach ($router->getRoutes() as $route) {
            $name = $route->getName();
            $routes[] = new InventoryRouteData(
                name: $name,
                uri: $route->uri(),
                methods: array_values(array_filter($route->methods(), is_string(...))),
                middleware: array_values(array_filter($route->gatherMiddleware(), is_string(...))),
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
- [ ] 抽出条件を満たさない環境 (`$this->app->detectEnvironment(fn () => 'production')`) では
      非 0 終了し、標準出力に JSON を出さない

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
| `kind` | 画面表の route で必須 / 操作表の route では禁止 | 段 2 |
| `story` | 区分が `通常` / `逸` のとき必須。値は `S1`..`S7` | 段 2 |
| `kubun` | 常に必須。`通常` / `逸` / `終` / `外` のみ | 段 2 |
| `reason` | 区分が `外` / `終` のとき必須・**30 文字以上** | 段 2 |

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
4. 移行スクリプトは `devnotes/` に置いたままにする (`scripts/` へ昇格させない)

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
```

- `scan()` は `subprocess.run(["php", "artisan", "bughunt:inventory-scan"], cwd=repo_root)`。
  非 0 終了 / JSON parse 失敗 / `schema_version` 不一致 / `extraction_condition` 不一致は**致命**
- 面の判定は概念設計どおり: middleware の**要素**に `web` があり、
  先頭セグメントが除外表に当たらないもの。**文字列化した部分一致はしない**
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

### fail-closed の作法

- `check` は**ファイルを 1 バイトも書かない** (レンダリング結果はメモリ上で比較)
- `generate` は段 1 / 2 / 4 を通ってから、ファイルごとに
  「同じディレクトリの一時ファイルへ書く → `os.replace()`」を行う。
  途中で落ちたら**そのファイルは書き換わらない**。
  2 ファイル間の同時更新は保証しない (部分更新は次の `check` が段 3 で検出する)
- 段 2 / 段 4 の違反は**例外にせず drift 行として全件列挙**してから exit 3
  (家系 spirux で「注釈欠落が `KeyError` → exit 1」になった実績があるため先回りで塞ぐ)

### テスト計画 (施策 5-a に実体を置く)

- [ ] **先に赤くする**: `scripts/tests/test_bug_hunt_inventory.py` を先に書く (import 失敗で赤)
- [ ] 致命 (exit 2): 抽出コマンド非 0 / JSON 壊れ / `extraction_condition` 不一致 /
      母集合 0 件 / 空名 / 重複名 / ノート不在 / 壊れた TOML / 想定外例外
- [ ] ドリフト (exit 3): 未注釈 route / 残置注釈 / 未知の区分 / 30 文字未満の理由 /
      story 欠落 / 画面 route の `kind` 欠落 / 操作 route への `kind` / 複合 method / 生成物 byte 不一致
- [ ] 一致 (exit 0) と、`check` が 1 バイトも書かないこと (mtime と内容で確認)
- [ ] `generate` が段 2 失敗時に**1 ファイルも書かない**こと
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

- [ ] シェルが**判定を持たない**こと: `route:list` / `grep` / `OUT_OF_SCOPE` の語を含まないことを静的に固定
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
| `docs/template-divergence.md` | **D19** を追加 (テンプレート正典との 3 点の差: 機能カタログを生成せず 3 列を維持 / 注釈は TOML / 中間 JSON を持たない)。「揃えている不変条件」に段 2・段 4 を書く |
| `AGENTS.md` §bug-hunt | 「スケルトン」の記述を「目録は生成物」へ改め、再生成コマンドとドリフト検査の役割 (何を守るのか) を 3 行で書く |
| `scripts/README.md` | `bug-hunt-inventory.py` を台帳へ追加、`bug-hunt-inventory-check.sh` の説明を薄い呼び出しへ更新、`scripts/tests/test_bug_hunt_inventory.py` を追加 |
| `.claude/skills/app-bug-hunt/SKILL.md` | Phase 1 の「`route:list` から手で生成する」手順を `generate` / `check` へ差し替え。メンテナンス規約の「新画面を実装したら 2 ファイルを更新する」を「注釈を 1 行足して再生成する」へ |
| `.claude/skills/app-bug-hunt/capability-catalog.md` | 冒頭に「本表は生成物ではない。ただし代表機構列の route 名と id の一意性は段 4 が検査する」と明記 |

### D19 に書く内容 (骨子)

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

- 抽出対象は web 面だけ。`api/` / Filament `/admin` / MCP / oauth / livewire には**沈黙する**
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

---

## 関連する現行コード

### scripts/bug-hunt-inventory-check.sh (全文・これを薄い呼び出しへ作り替える)

```bash
#!/usr/bin/env bash
#
# scripts/bug-hunt-inventory-check.sh — bug-hunt インベントリ ドリフト検知 (テンプレート版)
#
# 設計: 参照実装 (派生アプリ) の bug-hunt 基盤の汎用移植
#
# route:list の GET×inertia(web) / 非GET×web 集合と
# .claude/skills/app-bug-hunt/{screens.md,operations.md} の差分を検出する。
# 新ルート未追記・消失ルート未削除を warning 出力する (手動運用が既定、CI 任意)。
#
# 使い方: scripts/bug-hunt-inventory-check.sh   (exit 0=差分なし、3=差分あり)
set -euo pipefail

WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
cd "${WORKSPACE}"

SKILL_DIR=".claude/skills/app-bug-hunt"
SCREENS="${SKILL_DIR}/screens.md"
OPS="${SKILL_DIR}/operations.md"

drift=0

# screens.md / operations.md が「設計上ブラウザ非対象」と明記しているルート名 prefix。
# これらは UX ブラウザ監査の対象外として意図的にインベントリ表から外しているため、
# drift 検出 (新ルート未追記) からも除外する。新たに非対象を増やす場合は両方を更新すること。
# filament.* は S9 (管理画面) が screens.md/operations.md に手動メンテで載せる admin guard ルート。
# route:list 抽出側 (forward) は uri prefix 'admin' で既に除外済み。reverse (消失検知) でも除外し、
# admin インベントリ行が誤って「消失候補」warning にならないようにする。
OUT_OF_SCOPE_PREFIXES='seo.|social.|recent-auth.sso.|two-factor.qr-code|two-factor.secret-key|two-factor.recovery-codes|password.confirmation|cashier.|passport.|livewire|default-livewire|mcp.|oauth.|webhooks.|sanctum.|filament.'

# 対象 GET×inertia(web) のルート名 (admin/api/debug/mcp/seo/oauth/xhr-only 等は除外)。
get_screen_names() {
    php artisan route:list --json | OOS="${OUT_OF_SCOPE_PREFIXES}" python3 -c "
import json,os,re,sys
oos=re.compile('^('+os.environ['OOS']+')')
for r in json.load(sys.stdin):
    if 'GET' not in r['method']: continue
    uri=r['uri']; mw=str(r.get('middleware',[]))
    if uri.startswith(('api/','admin','_','.well-known','storage','sanctum','livewire','oauth','mcp')) or 'debug' in uri: continue
    if 'web' not in mw: continue
    name=r.get('name')
    if not name or oos.match(name): continue
    print(name)" | sort -u
}

# 対象 非GET×web の操作名 (webhook/passport/livewire/out-of-scope は除外)。
get_op_names() {
    php artisan route:list --json | OOS="${OUT_OF_SCOPE_PREFIXES}" python3 -c "
import json,os,re,sys
oos=re.compile('^('+os.environ['OOS']+')')
for r in json.load(sys.stdin):
    m=r['method'].split('|')[0]
    if m in ('GET','HEAD','OPTIONS'): continue
    mw=str(r.get('middleware',[])); name=r.get('name')
    if 'web' not in mw or not name: continue
    if oos.match(name) or 'webhook' in name: continue
    print(name)" | sort -u
}

check() {
    local label=$1 file=$2; shift 2
    local names; names="$("$@")"
    echo "== ${label} =="
    local n
    while IFS= read -r n; do
        [[ -z "${n}" ]] && continue
        if ! grep -qF "${n}" "${file}"; then
            echo "  [新ルート未追記] ${n} が ${file} に無い"
            drift=3
        fi
    done <<< "${names}"
    # file に書かれた route 名が route:list から消えていないか (簡易: name 列を抽出して照合)
    local listed
    listed="$(grep -oE '[a-z0-9-]+\.[a-z0-9.-]+|^\| `?/' "${file}" 2>/dev/null || true)"
    # 消失検知は名前トークン単位で行う (誤検知を避けるため warning のみ)
    while IFS= read -r tok; do
        [[ -z "${tok}" ]] && continue
        # out-of-scope として表に記録した名前は消失検知から除外する。
        echo "${tok}" | grep -qE "^(${OUT_OF_SCOPE_PREFIXES})" && continue
        case "${tok}" in
            *.*)
                if ! echo "${names}" | grep -qF "${tok}"; then
                    echo "  [消失候補] ${file} の '${tok}' が現 route:list に無い (削除漏れの可能性)"
                fi
                ;;
        esac
    done < <(grep -oE '\| [a-z0-9-]+\.[a-z0-9.-]+ ' "${file}" | tr -d '| ' | sort -u)
}

check "screens (GET×inertia)" "${SCREENS}" get_screen_names
check "operations (非GET×web)" "${OPS}" get_op_names

if [[ "${drift}" == 3 ]]; then
    echo "drift 検出: インベントリと route:list に差分あり (上記を確認)"
    exit 3
fi
echo "drift なし: インベントリは route:list と整合"
```

### .claude/skills/app-bug-hunt/coverage/correlate.py (目録を読む下流ツール。抜粋)

```python
依存は標準ライブラリのみ。参考スタイル: ledger/findings.schema.json (finding 形)。

operations.md のフォーマット (fix-gate #3):
  app operations.md は markdown leading-pipe の **5 列** が基本:
    | method | route | name | story | 区分 |
  ヘッダ strip("|").split("|") 後の index は 0=method, 1=route(URL), 2=name,
  3=story, 4=区分。**route NAME = name 列 (= index 2)** を join キーに使う
  (URL 列 index 1 を誤抽出すると graph join が失敗する)。
  S8 の API/CLI 面のみ **6 列** (`| method | route | api route name | CLI | story | 区分 |`)。
  本ローダはヘッダ行から name / story / 区分 列の index を動的に決めるため、5 列/6 列
  どちらの節も同じ正しい列を拾える。

使い方:
    python3 correlate.py --route-list route.json --operations operations.md \
      --findings findings.jsonl --executed executed.json \
      --graph-db /workspace/.code-review-graph/graph.db \
      --run-id 20260618-082101 [--json] [--hotspot-threshold 2]

  --route-list を省くと `php artisan route:list --json` を subprocess 取得する。
  --executed は必須 (build_executed.py が作った executed.json を渡す)。
UNTESTED = "untested"
UNKNOWN_GAP = "unknown_graph_gap"

# operations.md の区分。'外'=分母外、'逸'=逸脱のみ(未実行でも警告しない)。
KUBUN_OUT_OF_SCOPE = "外"
KUBUN_DEVIATE = "逸"
ALL_KUBUN = {"◎", "○", "逸", "終", "外"}

# app operations.md の name 列ヘッダ候補 (5 列='name' / S8 6 列='api route name')。
_NAME_HEADERS = ("name", "api route name", "route name", "route_name")
_STORY_HEADERS = ("story",)
_KUBUN_HEADERS = ("区分",)

    """operations.md の表をパースし route_name -> {operation, story, kubun} を返す。

    fix-gate #3: app は 5 列 (`method|route|name|story|区分`)、S8 のみ 6 列。
    join キーは **name 列** (URL の route 列ではない)。ヘッダ行から name/story/区分 列の
    index を動的に決めるため 5 列/6 列いずれの節でも正しい列を拾う。
    operation ラベルは route 列 (URL) を採用する (人間可読の機構名として最も近い)。

    kubun(区分): ◎/○/逸/終/外。'外'(対象外) と '逸'(逸脱のみ) は分母調整の材料。
    複数 route を含むセルは各 route に同じ operation/story/kubun を割り当てる。
    """
    result: dict[str, dict] = {}
    text = Path(path).read_text(encoding="utf-8")
    # 直近に見たヘッダの列割当 (節ごとに更新)。未検出のうちはパース対象外。
    idx: tuple[int, int, int] | None = None
    for raw in text.splitlines():
        line = raw.strip()
        if not line.startswith("|"):
            continue
        if "---" in line:
            continue
        cols = [c.strip() for c in line.strip("|").split("|")]
        # ヘッダ行を検出したら列割当を更新して次行へ。
        maybe = _header_indices(cols)
        if maybe is not None:
            idx = maybe
            continue
        if idx is None:
            continue
        name_idx, story_idx, kubun_idx = idx
        if max(name_idx, story_idx, kubun_idx) >= len(cols):
            continue
        name_cell = cols[name_idx]
        story = cols[story_idx]
        kubun = cols[kubun_idx]
        # operation ラベルは route(URL) 列。無ければ name 列を流用。
        op_idx = 1 if len(cols) > 1 and name_idx != 1 else name_idx
        operation = _BACKTICK_RE.sub("", cols[op_idx]) if op_idx < len(cols) else name_cell
        # 区分セルから先頭の区分記号を取り出す (脚注付き "外 (...)" 等)
        kubun_sym = next((k for k in ALL_KUBUN if kubun.startswith(k)), kubun)
        for name in _parse_route_cell(name_cell):
            # 既出 route は最初の定義を優先 (operations.md の重複定義に強い)
            result.setdefault(name, {
                "operation": _FOOTNOTE_RE.sub("", operation).strip() or operation,
                "story": story,
                "kubun": kubun_sym,
            })
    return result


def _expand_findings_paths(path: str) -> list[str]:
    """--findings の引数を実ファイルパス群へ展開。
```

### .claude/skills/app-bug-hunt/operations.md (現行・先頭 20 行)

```markdown
# 操作インベントリ (operations.md) — AI-CUE

> bug-hunt カバレッジの分母となる「書き込み操作」(非GET × web セッション面) の一覧。`php artisan route:list`
> から生成しストーリー (S1..S7) を割り当てた。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
> 列フォーマット: markdown leading-pipe 5 列 `| method | route | name | story | 区分 |` (correlate.py 依存)。

## 操作一覧 (web セッション面)

| method | route | name | story | 区分 |
|---|---|---|---|---|
| POST | billing/checkout | billing.checkout | S5 | 通常 |
| POST | billing/plan | billing.plan.change | S5 | 通常 |
| POST | billing/portal | billing.portal | S5 | 通常 |
| POST | billing/auto-recharge | billing.auto-recharge.update | S5 | 通常 |
| POST | billing/auto-recharge/setup | billing.auto-recharge.setup | S5 | 通常 |
| PATCH | billing/contact | billing.contact.update | S5 | 通常 |
| POST | purchase-tickets/checkout | billing.tickets.checkout | S5 | 通常 |
| POST | onboarding/activate-personal | onboarding.activate-personal | S1 | 通常 |
| POST | notifications/read-all | notifications.read-all | S6 | 通常 |
| POST | notifications/{notification}/open | notifications.open | S6 | 通常 |
```

### tests/Architecture/BugHuntInventoryCheckInvariantTest.php (現行・sandbox 組み立て部の抜粋)

```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Architecture invariant: bug-hunt インベントリ ドリフト検出器の最小契約。
 *
 * SoT = scripts/bug-hunt-inventory-check.sh 本体 + AGENTS.md §bug-hunt
 * (「ドリフト検知は scripts/bug-hunt-inventory-check.sh」)。
 *
 * 固定する不変条件:
 *   - スクリプトが存在し実行可能で、fail-closed (set -euo pipefail) であること
 *   - インベントリ正本が `.claude/skills/app-bug-hunt/{screens.md,operations.md}` であること
 *   - **exit code 規約 0=一致 / 3=ドリフト** を実際に満たすこと
 *   - route:list 取得に失敗したときに exit 0 (fail-open) を返さないこと
 *
 * exit code 規約は「静的に読める宣言」ではなく **実走で** 検証する。ただし実 route:list
 * (artisan boot + DB) には依存させない: スクリプトを一時 sandbox へ複製し、`php` を
 * 固定 JSON を吐く shim に差し替えて走らせる (決定論・DB 不使用)。
 * これが本 gate の負のコントロール (drift fixture で実際に exit 3 になること) も兼ねる。
 */

function bhicScriptPath(): string
{
    return base_path('scripts/bug-hunt-inventory-check.sh');
}

/**
 * sandbox を組み立てる。scripts/ にスクリプト複製、.claude/skills/app-bug-hunt/ に
 * インベントリ fixture、bin/ に `php` shim (固定 route:list JSON を吐く) を置く。
 *
 * @param  list<array{method: string, uri: string, middleware: list<string>, name: string}>  $routes
 * @param  bool  $phpFails  true なら shim が失敗する (route:list 取得失敗の再現)
 */
function bhicMakeSandbox(array $routes, string $screensMd, string $operationsMd, bool $phpFails = false): string
{
    $sandbox = sys_get_temp_dir().'/bhic-'.bin2hex(random_bytes(6));
    mkdir($sandbox.'/scripts', 0o755, true);
    mkdir($sandbox.'/.claude/skills/app-bug-hunt', 0o755, true);
    mkdir($sandbox.'/bin', 0o755, true);

    copy(bhicScriptPath(), $sandbox.'/scripts/bug-hunt-inventory-check.sh');
    file_put_contents($sandbox.'/.claude/skills/app-bug-hunt/screens.md', $screensMd);
    file_put_contents($sandbox.'/.claude/skills/app-bug-hunt/operations.md', $operationsMd);

    $json = json_encode($routes, JSON_UNESCAPED_SLASHES);
    file_put_contents($sandbox.'/routes.json', is_string($json) ? $json : '[]');

    $shim = $phpFails
        ? "#!/usr/bin/env bash\necho 'route:list failed' >&2\nexit 1\n"
        : "#!/usr/bin/env bash\ncat \"\$(dirname \"\$0\")/../routes.json\"\n";
    file_put_contents($sandbox.'/bin/php', $shim);
    chmod($sandbox.'/bin/php', 0o755);

    return $sandbox;
}

function bhicRemoveSandbox(string $sandbox): void
test('exit code 規約 0=一致 / 3=ドリフト を実走で満たすこと (sandbox / DB 不使用)', function (): void {
    bhicRequirePython3();

    $screens = "# screens\n\n| ルート名 | URI |\n|---|---|\n| dashboard | /dashboard |\n";
    $operations = "# operations\n\n| ルート名 | 操作 |\n|---|---|\n| projects.store | 作成 |\n";

    // (1) 一致: route:list の全ルートがインベントリに載っている → exit 0
    $match = bhicMakeSandbox([
        ['method' => 'GET|HEAD', 'uri' => 'dashboard', 'middleware' => ['web'], 'name' => 'dashboard'],
        ['method' => 'POST', 'uri' => 'projects', 'middleware' => ['web'], 'name' => 'projects.store'],
    ], $screens, $operations);
    try {
        [$code, $out] = bhicRunSandbox($match);
        expect($code)->toBe(0, "一致 fixture は exit 0 であるべき:\n".$out);
        expect($out)->toContain('drift なし');
    } finally {
        bhicRemoveSandbox($match);
    }
});

/*
```

### tests/Architecture/BughuntCoverageToolSelfTest.php (新テストの手本・抜粋)

```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Architecture invariant: bug-hunt のカバレッジ道具 (Python) の自己テストを
 * `composer test` の下で実走させる。
 *
 * 対象は 3 モジュール:
 *   - test_correlate      … 照合器の fail-closed 契約 (主入力が揃わない走行を成功にしない)
 *   - test_build_executed … 実行済み route の記録の集約器 (同上)
 *   - test_naming_no_stale … 旧 fail-open 文言・旧語彙の再混入検知
 *
 * ここに結線しないと「不変条件はテストへの登録まで含めて実装済み」を満たさない
 * (禁止事項 1)。禁止語が戻っても、照合器が fail-open へ戻っても、緑のままになるため。
 * `test_merge_pcov` はコード到達カバレッジ (別 feature) の担当なので本目録には入れない。
 *
 * 先例は BugHuntInventoryCheckInvariantTest: python3 の不在は **skip ではなく fail** で
 * 顕在化させる (環境不備を skip で隠すと「未検証のまま合格」になる)。
 */

/** カバレッジ道具の置き場 (作業ディレクトリ)。 */
function bctCoverageDir(): string
{
    return base_path('.claude/skills/app-bug-hunt/coverage');
}

/**
 * coverage ディレクトリで `python3 -m unittest <modules...>` を実走し [exitCode, output] を返す。
 *
 * @param  list<string>  $modules
 * @return array{0: int|null, 1: string}
 */
function bctRunUnittest(array $modules): array
{
    $process = new Process(['python3', '-m', 'unittest', ...$modules], bctCoverageDir());
    $process->setTimeout(120);
    $process->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

test('python3 が PATH にあること (環境不備を skip で隠さない)', function (): void {
    expect((new Process(['which', 'python3']))->run())->toBe(
        0,
        'python3 が PATH に無い。bug-hunt のカバレッジ道具は python3 必須 (stdlib のみ)。'
    );
});

test('カバレッジ道具の Python 自己テスト 3 本が composer test の下で通ること', function (): void {
    expect(is_dir(bctCoverageDir()))->toBeTrue('coverage ディレクトリが見つからない: '.bctCoverageDir());

    [$code, $out] = bctRunUnittest(['test_correlate', 'test_build_executed', 'test_naming_no_stale']);

    expect($code)->toBe(0, "bug-hunt カバレッジ道具の自己テストが失敗しました:\n".$out);
});

test('負の対照: 存在しないモジュール名を渡すと非 0 になること (空振り gate を作らない)', function (): void {
```
