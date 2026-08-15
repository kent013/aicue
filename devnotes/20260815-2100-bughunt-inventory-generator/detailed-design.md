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
  (書いたのに効いていない注釈を残さない)。**トップレベルの未知項目も同じく drift (exit 3)**
  であり、致命 (exit 2) にはしない (実装時の是正: 当初の実装は読み込み時に例外にしていたが、
  それでは終了コード規約と食い違うので、注釈の集合として持ち回って段 2 で列挙する形にした)
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
  (規則が単純になり、抜けも塞がる)。**同じ規則を `notes-screens.md` にも課す** —
  画面側に直接の危険は無いが、連結先ごとに規則が変わる方が事故のもとになる

### 段 4 (機能カタログの参照整合) の契約

- 対象はヘッダが `| id | 機能 (actor→outcome) | 代表機構 (route name) |` の表**だけ**
  (`capability-catalog.md` の他の表 — 責務境界・割当規則 — は見ない)
- id は `^[A-Z]{2,5}-[0-9]{2}$`。**重複したら drift**
- 代表機構セルは**バッククォートで囲まれた token だけ**を route 名候補とする。
  `/` 区切りの複数記載を許す。丸括弧の説明 (`(機構横断)` / `(admin panel)` /
  `(クライアント状態。route なし)`) とパス (`routes/api.php`) は候補にしない。
  **パスの判定は「区切りを含み、かつ拡張子で終わる」ことで行う** (実装時の是正:
  当初は「`/` を含めば候補から外す」としていたが、それでは `projects/store` のような
  打ち間違いが素通りして段 4 が空振りする)
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
