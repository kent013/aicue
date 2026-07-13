【アプリの使命 (North Star)】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。
データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: 役割

あなたは Laravel + Svelte アプリのコードレビュアーである。以下の改善実装をレビューせよ。

レビュー観点:
- 設計との一致性 (詳細設計書どおりに実装されているか)
- 正確性 (ロジック・回帰の有無)
- PHPStan level 10 適合性
- DTO/JsonResource パターン (HTTP body を新規作成する場合)
- テスト網羅性 (テストファースト・回帰防止が担保されているか)
- セキュリティ不変条件
- DESIGN.md 準拠 / Atomic Design 準拠 (今回 resources/js・resources/css の変更はなし)

出力形式:
- ファイルごとに判定
- 指摘は Critical / Warning / Suggestion に分類
- 末尾に全体判定 **APPROVED** または **CHANGES_REQUESTED** を明記

---

## user

### 詳細設計書 (要約)

TODO T034 / topic=notifications-tab-title。bug-hunt 回帰 F-4-02 (Low) = 前回 T029 (seo-tab-titles) の取りこぼし。T029 は `config/seo.php` の `app_titles` に未登録アプリ画面ルートの固有タイトルを追加したが `notifications.index` を含めていなかった。結果 `/notifications` のタブ title がサイト名「AI-CUE」のみになる (画面固有名が出ない)。

解決経路: `SeoManager::resolveDocumentTitle($routeName)` → private(noindex) 経路 → `resolvePrivateTitle()` → `config('seo.app_titles')[$routeName] ?? null`。`notifications.index` 未登録のため null → `SeoTitle::compose(null)` が site_name のみ返す。

対応画面 `resources/js/pages/Notifications/Index.svelte` の h1 は「通知」。既存の「app_titles 固有名は画面 h1 と一致させる」方針に従い固有名を「通知」とする。

施策:
- S1: `config/seo.php` の `app_titles` に `'notifications.index' => '通知'` を追加 (既存コメント様式踏襲)。
- S2: `tests/Feature/Seo/SeoManagerTest.php` の data-driven テストのデータセットに `'通知' => ['notifications.index', '通知']` を 1 行追加。テスト説明の件数依存表現「6 アプリ画面」を件数非依存表現に変更 (design-review Round 1 [Warning] 反映)。

実装順序: テストファースト。S2 のデータ 1 行を先に追加し S1 未適用で fail 確認 → S1 で green。

### 実装差分 (git diff)

```diff
diff --git a/config/seo.php b/config/seo.php
index 722433a..4d01880 100644
--- a/config/seo.php
+++ b/config/seo.php
@@ -125,6 +125,8 @@
         'organizations.onboarding.cli' => 'CLI 導入ガイド',
         // MCP 導入ガイド (organizations.onboarding.mcp — Onboarding/Mcp.svelte h1「MCP 導入ガイド」)
         'organizations.onboarding.mcp' => 'MCP 導入ガイド',
+        // 通知一覧 (notifications.index — Notifications/Index.svelte h1「通知」)
+        'notifications.index' => '通知',
     ],
 
 ];
diff --git a/tests/Feature/Seo/SeoManagerTest.php b/tests/Feature/Seo/SeoManagerTest.php
index 68a45e8..c091416 100644
--- a/tests/Feature/Seo/SeoManagerTest.php
+++ b/tests/Feature/Seo/SeoManagerTest.php
@@ -83,7 +83,7 @@
         ->and($manager->resolveDocumentTitle('projects.show'))->toBe('My Project | Acme');
 });
 
-it('resolveDocumentTitle: 未登録だった 6 アプリ画面が固有 title を返す (仕様固定・h1 と一致)', function (
+it('resolveDocumentTitle: 未登録だったアプリ画面が固有 title を返す (仕様固定・h1 と一致)', function (
     string $routeName,
     string $expectedFragment,
 ): void {
@@ -106,4 +106,6 @@
     '接続セッション' => ['organizations.api-keys.sessions.index', '接続セッション'],
     'CLI 導入ガイド' => ['organizations.onboarding.cli', 'CLI 導入ガイド'],
     'MCP 導入ガイド' => ['organizations.onboarding.mcp', 'MCP 導入ガイド'],
+    // F-4-02 (T029 取りこぼし) 回帰防止: 通知一覧 (Notifications/Index.svelte h1「通知」)
+    '通知' => ['notifications.index', '通知'],
 ]);
```

### テスト結果

- テストファースト確認: S2 追加 + S1 未適用で `SeoManagerTest` の「通知」ケースが `'通知 | Acme'` 期待に対し `'Acme'` 実測で fail (再現成功)。
- S1 適用後: `composer test -- --filter=SeoManagerTest` → 14 passed / 27 assertions (green)。
- 全 Pest: `composer test` → 1610 passed, 2 skipped, 0 failed。
- `composer phpstan` (level 10): No errors。
- `vendor/bin/pint --test`: passed。
- `pnpm lint` / `pnpm typecheck`: OK。`pnpm build`: OK。`pnpm test`: 該当 3 ファイルは並列負荷での render timeout flake、単独再実行で 48/48 green (本変更は PHP-only で無関係)。

上記実装をレビューし、全体判定を示せ。
