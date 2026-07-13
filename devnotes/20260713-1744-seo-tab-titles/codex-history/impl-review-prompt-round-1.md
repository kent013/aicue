## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: レビュアーの役割

あなたは Laravel 12 + Svelte 5 + Inertia アプリのコードレビュアーである。TODO T029「未設定画面のブラウザタブ title 追加」の実装差分をレビューせよ。

レビュー観点:
- **設計との一致性**: 詳細設計書 (S1: config/seo.php への 6 ルート追加 / S2: SeoManagerTest への Feature テスト追加) の通りに実装されているか
- **正確性**: config キー・値・route name が正しいか。ロジック上のバグはないか
- **PHPStan 適合性 (level 10)**: 型を緩めていないか。mixed の放置がないか
- **DTO/JsonResource パターン**: 本変更は HTTP レスポンス body を作らない (config + test のみ) ため非該当だが、逸脱がないか確認
- **テスト網羅性**: 6 ルートを網羅し、drift 検出まで担保しているか。テストファーストで fail→green を確認したか
- **セキュリティ**: tenant 境界・認可・入力処理に干渉していないか
- **DESIGN.md 準拠 / Atomic Design 準拠**: 本変更は frontend を触らないため非該当だが確認

出力形式:
- ファイルごとに判定
- 指摘を Critical / Warning / Suggestion に分類
- 全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示

---

## user

### 詳細設計書 (抜粋)

S1: `config/seo.php` の `app_titles` 連想配列末尾に、未登録だった 6 ルートの固有タイトルを追加する。文言は各画面の h1 見出しと一致させる (タブ title と画面見出しの表現一貫性)。いずれも静的見出しで足りるため controller の setPrivateTitle 上書きは不要。

| route name | h1 見出し (SoT) | app_titles 値 |
|------------|-----------------|---------------|
| `projects.categories.index` | カテゴリ管理 | カテゴリ管理 |
| `manage.users.index` | ユーザー管理 | ユーザー管理 |
| `organizations.api-keys.index` | API キー | API キー |
| `organizations.api-keys.sessions.index` | 接続セッション | 接続セッション |
| `organizations.onboarding.cli` | CLI 導入ガイド | CLI 導入ガイド |
| `organizations.onboarding.mcp` | MCP 導入ガイド | MCP 導入ガイド |

S2: `tests/Feature/Seo/SeoManagerTest.php` に 6 ルートを dataset で網羅する `it(...)` を追加。実 config/seo.php の app_titles を検証対象にし (beforeEach は site_name=Acme のみ上書き)、`resolveDocumentTitle()` が `{固有名} | Acme` を返すこと + config の実値にエントリが存在すること (drift 検出) を検証する。

**実装時の設計からの逸脱 (レビュー対象)**: 詳細設計の擬似コードは drift 検出に `config("seo.app_titles.{$routeName}")` を使っていたが、route name 自体が dot を含む (例: `projects.categories.index`) ため Laravel の config() dot 記法では nested key と解釈され null になる。そのため `config('seo.app_titles')` で配列を取得し `Assert::isArray()` で narrow した上でリテラルキー `$appTitles[$routeName]` で参照するよう修正した。

### 実装差分 (git diff)

```diff
diff --git a/config/seo.php b/config/seo.php
index a32a64b..722433a 100644
--- a/config/seo.php
+++ b/config/seo.php
@@ -108,6 +108,23 @@
         // 動画マニュアル (show/edit/撮影 show は controller が setPrivateTitle で
         // マニュアル名を供給。create のみ静的 = 対象実体が未存在のため)
         'projects.manuals.create' => '動画マニュアルの作成',
+        /*
+        | 以下は各画面の h1 見出しと一致させる (タブ title と画面見出しの表現一貫性)。
+        | いずれも静的見出しで足りるため controller の setPrivateTitle 上書きは不要。
+        | 見出し文言を変えるときは本 map も追随させること (SeoManagerTest が固有 title を固定)。
+        */
+        // カテゴリ管理 (projects.categories.index — Admin/Categories.svelte h1「カテゴリ管理」)
+        'projects.categories.index' => 'カテゴリ管理',
+        // ユーザー管理 (manage.users.index — Admin/Users.svelte h1「ユーザー管理」)
+        'manage.users.index' => 'ユーザー管理',
+        // API キー (organizations.api-keys.index — ApiKeys/Index.svelte h1「API キー」)
+        'organizations.api-keys.index' => 'API キー',
+        // 接続セッション (organizations.api-keys.sessions.index — ApiKeys/Sessions.svelte h1「接続セッション」)
+        'organizations.api-keys.sessions.index' => '接続セッション',
+        // CLI 導入ガイド (organizations.onboarding.cli — Onboarding/Cli.svelte h1「CLI 導入ガイド」)
+        'organizations.onboarding.cli' => 'CLI 導入ガイド',
+        // MCP 導入ガイド (organizations.onboarding.mcp — Onboarding/Mcp.svelte h1「MCP 導入ガイド」)
+        'organizations.onboarding.mcp' => 'MCP 導入ガイド',
     ],
 
 ];
diff --git a/tests/Feature/Seo/SeoManagerTest.php b/tests/Feature/Seo/SeoManagerTest.php
index dbb75f1..68a45e8 100644
--- a/tests/Feature/Seo/SeoManagerTest.php
+++ b/tests/Feature/Seo/SeoManagerTest.php
@@ -5,6 +5,7 @@
 use App\Support\Seo\SeoManager;
 use App\Support\Seo\SeoMeta;
 use App\Support\Seo\SeoUrl;
+use Webmozart\Assert\Assert;
 
 /*
  * SeoManager: リクエスト単位のメタ保持 (scoped 束縛 = Octane 安全) と
@@ -81,3 +82,28 @@
     expect($manager->resolvePrivateTitle('projects.show'))->toBe('My Project')
         ->and($manager->resolveDocumentTitle('projects.show'))->toBe('My Project | Acme');
 });
+
+it('resolveDocumentTitle: 未登録だった 6 アプリ画面が固有 title を返す (仕様固定・h1 と一致)', function (
+    string $routeName,
+    string $expectedFragment,
+): void {
+    // 実 config/seo.php の app_titles を検証対象にする (beforeEach は site_name のみ上書き)。
+    $manager = new SeoManager;
+
+    expect($manager->resolveDocumentTitle($routeName))
+        ->toBe("{$expectedFragment} | Acme");
+
+    // config の実値にも固有名が存在すること (エントリ欠落の drift を検出)。
+    // route name 自体が dot を含む (例: projects.categories.index) ため
+    // config() の dot 記法では引けない。配列を取得しリテラルキーで参照する。
+    $appTitles = config('seo.app_titles');
+    Assert::isArray($appTitles);
+    expect($appTitles[$routeName] ?? null)->toBe($expectedFragment);
+})->with([
+    'カテゴリ管理' => ['projects.categories.index', 'カテゴリ管理'],
+    'ユーザー管理' => ['manage.users.index', 'ユーザー管理'],
+    'API キー' => ['organizations.api-keys.index', 'API キー'],
+    '接続セッション' => ['organizations.api-keys.sessions.index', '接続セッション'],
+    'CLI 導入ガイド' => ['organizations.onboarding.cli', 'CLI 導入ガイド'],
+    'MCP 導入ガイド' => ['organizations.onboarding.mcp', 'MCP 導入ガイド'],
+]);
```

### テスト結果

- `vendor/bin/pest tests/Feature/Seo/SeoManagerTest.php`: 13 passed, 25 assertions (新規 6 dataset ケース含む)。テストファースト: S1 未適用で 6 ケース fail (`Acme` のみ) → S1 適用で green を確認済み。
- `composer test` (全体): 1565 passed, 2 skipped, 0 failed。
- `composer phpstan`: No errors (level 10)。
- `vendor/bin/pint --test`: passed。
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (476 passed) / `pnpm build`: すべて green。

### 参考: 6 ルートの実在と h1 一致は route:list / grep で確認済み

全 6 route name は `php artisan route:list` に存在。h1 見出しは各 Svelte ファイル (`resources/js/Pages/Admin/Categories.svelte` 他) の `<h1 class="text-h2">` と文字列一致することを grep で確認済み。
