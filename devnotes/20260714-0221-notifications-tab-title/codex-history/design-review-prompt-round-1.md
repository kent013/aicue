【アプリの使命（North Star）】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」。競合(tebiki)と異なり標準作業を起点に AI が教材設計し撮影を指示する。熟練者の暗黙知を形式知へ変換する装置(SECI)。v1 スコープ: 字幕のみ / 撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

（detailed-design.md 全文を以下に添付。要旨: config/seo.php の app_titles に 'notifications.index' => '通知' を 1 行追加し、SeoManagerTest の data-driven ケースに notifications.index を 1 行追加する。新規モデル・新規経路・HTTP body 生成なし。テストファースト = 先にテスト追加で fail 確認 → config 追加で green。実装モード incremental。）

### 施策一覧
| # | 施策名 | 変更ファイル |
|---|--------|------------|
| S1 | app_titles に 'notifications.index' => '通知' を追加 | config/seo.php |
| S2 | SeoManagerTest の data-driven ケースに notifications.index を追加 | tests/Feature/Seo/SeoManagerTest.php |

### S1 変更後 (config/seo.php app_titles 末尾)
```php
        'organizations.onboarding.mcp' => 'MCP 導入ガイド',
        // 通知一覧 (notifications.index — Notifications/Index.svelte h1「通知」)
        'notifications.index' => '通知',
    ],
```

### S2 変更後 (tests/Feature/Seo/SeoManagerTest.php の ->with([...]))
```php
})->with([
    'カテゴリ管理' => ['projects.categories.index', 'カテゴリ管理'],
    'ユーザー管理' => ['manage.users.index', 'ユーザー管理'],
    'API キー' => ['organizations.api-keys.index', 'API キー'],
    '接続セッション' => ['organizations.api-keys.sessions.index', '接続セッション'],
    'CLI 導入ガイド' => ['organizations.onboarding.cli', 'CLI 導入ガイド'],
    'MCP 導入ガイド' => ['organizations.onboarding.mcp', 'MCP 導入ガイド'],
    '通知' => ['notifications.index', '通知'],
]);
```
併せて it 説明の「未登録だった 6 アプリ画面」の件数「6」を「7」(または件数非依存表現) に更新。

---

## 関連する現行コード

### config/seo.php (app_titles 抜粋 — 末尾コメント様式)
```php
    'app_titles' => [
        'dashboard' => 'ダッシュボード',
        // ... (認証フロー・設定・組織・課金・プロジェクト・マニュアル) ...
        // カテゴリ管理 (projects.categories.index — Admin/Categories.svelte h1「カテゴリ管理」)
        'projects.categories.index' => 'カテゴリ管理',
        // ユーザー管理 (manage.users.index — Admin/Users.svelte h1「ユーザー管理」)
        'manage.users.index' => 'ユーザー管理',
        // API キー (organizations.api-keys.index — ApiKeys/Index.svelte h1「API キー」)
        'organizations.api-keys.index' => 'API キー',
        // 接続セッション (organizations.api-keys.sessions.index — ApiKeys/Sessions.svelte h1「接続セッション」)
        'organizations.api-keys.sessions.index' => '接続セッション',
        // CLI 導入ガイド (organizations.onboarding.cli — Onboarding/Cli.svelte h1「CLI 導入ガイド」)
        'organizations.onboarding.cli' => 'CLI 導入ガイド',
        // MCP 導入ガイド (organizations.onboarding.mcp — Onboarding/Mcp.svelte h1「MCP 導入ガイド」)
        'organizations.onboarding.mcp' => 'MCP 導入ガイド',
    ],
```

### app/Support/Seo/SeoManager.php (解決経路)
```php
    public function resolveDocumentTitle(?string $routeName): string
    {
        if ($this->meta !== null) {
            return $this->meta->title;
        }
        if ($routeName !== null && $this->isMinimal($routeName)) {
            return SeoTitle::compose($this->minimalTitle($routeName));
        }
        return SeoTitle::compose($this->resolvePrivateTitle($routeName));
    }

    public function resolvePrivateTitle(?string $routeName): ?string
    {
        if ($this->privateTitle !== null) {
            return $this->privateTitle;
        }
        if ($routeName === null) {
            return null;
        }
        /** @var array<string, string> $appTitles */
        $appTitles = config('seo.app_titles', []);
        return $appTitles[$routeName] ?? null;
    }
```

### routes/web.php (notifications)
```php
Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('/notifications/read-all', ...)->name('notifications.read-all');
Route::post('/notifications/{notification}/open', ...)->name('notifications.open');
Route::post('/notifications/{notification}/read', ...)->name('notifications.read');
```

### resources/js/pages/Notifications/Index.svelte (h1)
```svelte
<h1 class="text-h2">通知</h1>
```

### tests/Feature/Seo/SeoManagerTest.php (該当テスト頭)
```php
it('resolveDocumentTitle: 未登録だった 6 アプリ画面が固有 title を返す (仕様固定・h1 と一致)', function (
    string $routeName,
    string $expectedFragment,
): void {
    $manager = new SeoManager;
    expect($manager->resolveDocumentTitle($routeName))->toBe("{$expectedFragment} | Acme");
    $appTitles = config('seo.app_titles');
    Assert::isArray($appTitles);
    expect($appTitles[$routeName] ?? null)->toBe($expectedFragment);
})->with([ /* 上記データセット */ ]);
// beforeEach は seo.site_name='Acme' 等のみ config 上書き。app_titles は実 config/seo.php を使用。
```
