【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

【禁止事項】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

【役割 (system)】

あなたはシニア Laravel/Svelte エンジニアとして、T012「コピー崩れの修正 (F-01 APP_NAME 未展開 / F-02 未翻訳キー)」の**レビュー指摘修正 diff** の最終実装レビュー (impl-review) を行う。

対象リポジトリ: Laravel 12 + Inertia + Svelte 5 (worktree: `/workspace/.claude/worktrees/tasks/T012`)。
必要ならファイル読み込みで周辺コードを確認してよい (書き込み・コマンド実行は禁止)。

レビュー観点:
1. 正確性・型・整合 (ロジック/エッジ/null 安全、PHPStan lv10 通過前提、設計施策の実装漏れ、既存不変条件/テスト非破壊)
2. 「ラベルは Svelte の label 文言を正とする (語彙ズレ禁止)」原則との整合
3. FormRequest 化に伴う挙動変化 (ProhibitsProtectedKeys の missing rule 追加が既存クライアント/テストを壊さないか)
4. テストの妥当性 (期待文言の厳密一致、ロケール明示、テスト形骸化がないか)

出力形式: 指摘を [Critical] / [Warning] / [Suggestion] に分類し、各指摘にファイル・行・根拠・修正案を付す。指摘が無い区分は「なし」と明記する。最後に総評を 3 行以内で書く。

---

【データ (user)】

## 修正対象となったレビュー指摘 (前ラウンド Warning 2 件)

1. `OrganizationController::store` のインライン `$request->validate(['name'=>...])` が attribute 上書きを持たず、グローバル `'name'=>'名前'` にフォールバック。Organizations/Create.svelte:30 のラベル「組織名」と語彙ズレ (UpdateOrganizationRequest では是正済みなのに作成経路だけ残存)。
2. `StoreProjectRequest` / `UpdateProjectRequest` の `'name'` も同様にグローバル「名前」へフォールバックするが、Projects/Create.svelte:35 / Edit.svelte:40 のラベルは「プロジェクト名」。

## 今回の修正内容 (diff + 新規ファイル全文)

前提: 本 diff の基底 (main...HEAD 済みコミット) には lang/ja/validation.php の attributes 全域補完、ValidationAttributeCoverageTest (inline validation の tokenizer 走査を含む deny-by-default Architecture テスト)、FormRequestProhibitedKeyTest (全 FormRequest に ProhibitsProtectedKeys を強制) が含まれる。

検証済み: composer test (1518 passed / 2 skipped) / composer phpstan (No errors) / pint --test / pnpm lint / typecheck / test (427 passed) / build 全 green。

```diff
diff --git a/app/Http/Controllers/Organizations/OrganizationController.php b/app/Http/Controllers/Organizations/OrganizationController.php
index eb295d7..a6138b1 100644
--- a/app/Http/Controllers/Organizations/OrganizationController.php
+++ b/app/Http/Controllers/Organizations/OrganizationController.php
@@ -6,6 +6,7 @@
 
 use App\Enums\TwoFactorStatus;
 use App\Http\Controllers\Controller;
+use App\Http\Requests\Organizations\StoreOrganizationRequest;
 use App\Http\Requests\Organizations\UpdateOrganizationRequest;
 use App\Models\Organization;
 use App\Models\User;
@@ -34,15 +35,12 @@ public function create(): Response
     }
 
     /** 新規組織作成 → provisioning (Default Team 込み) → 作成した組織へ切替 */
-    public function store(Request $request, OrganizationProvisioningService $provisioning): RedirectResponse
+    public function store(StoreOrganizationRequest $request, OrganizationProvisioningService $provisioning): RedirectResponse
     {
         $user = $request->user();
         Assert::isInstanceOf($user, User::class);
 
-        $request->validate([
-            'name' => ['required', 'string', 'max:255'],
-        ]);
-        $name = $request->input('name');
+        $name = $request->validated('name');
         Assert::string($name);
 
         $organization = $provisioning->provision($user, $name);
diff --git a/app/Http/Requests/Organizations/UpdateOrganizationRequest.php b/app/Http/Requests/Organizations/UpdateOrganizationRequest.php
index 566b29a..15a7357 100644
--- a/app/Http/Requests/Organizations/UpdateOrganizationRequest.php
+++ b/app/Http/Requests/Organizations/UpdateOrganizationRequest.php
@@ -29,4 +29,14 @@ public function rules(): array
             'name' => ['required', 'string', 'max:255'],
         ], $this->protectedKeyMissingRules());
     }
+
+    /**
+     * @return array<string, string>
+     */
+    public function attributes(): array
+    {
+        // UI ラベル (Organizations/Settings.svelte「組織名」) と揃える。
+        // グローバル attributes の 'name' => '名前' より優先される局所上書き。
+        return ['name' => '組織名'];
+    }
 }
diff --git a/app/Http/Requests/Projects/StoreProjectRequest.php b/app/Http/Requests/Projects/StoreProjectRequest.php
index c11026d..7047bfd 100644
--- a/app/Http/Requests/Projects/StoreProjectRequest.php
+++ b/app/Http/Requests/Projects/StoreProjectRequest.php
@@ -33,4 +33,15 @@ public function rules(): array
             'description' => ['nullable', 'string', 'max:2000'],
         ], $this->protectedKeyMissingRules());
     }
+
+    /**
+     * @return array<string, string>
+     */
+    public function attributes(): array
+    {
+        // UI ラベル (Projects/Create.svelte「プロジェクト名」) と揃える。
+        // グローバル attributes の 'name' => '名前' より優先される局所上書き。
+        // description はグローバルの「説明」がラベルと一致するため上書き不要。
+        return ['name' => 'プロジェクト名'];
+    }
 }
diff --git a/app/Http/Requests/Projects/UpdateProjectRequest.php b/app/Http/Requests/Projects/UpdateProjectRequest.php
index 4192682..881af08 100644
--- a/app/Http/Requests/Projects/UpdateProjectRequest.php
+++ b/app/Http/Requests/Projects/UpdateProjectRequest.php
@@ -30,4 +30,15 @@ public function rules(): array
             'description' => ['nullable', 'string', 'max:2000'],
         ], $this->protectedKeyMissingRules());
     }
+
+    /**
+     * @return array<string, string>
+     */
+    public function attributes(): array
+    {
+        // UI ラベル (Projects/Edit.svelte「プロジェクト名」) と揃える。
+        // グローバル attributes の 'name' => '名前' より優先される局所上書き。
+        // description はグローバルの「説明」がラベルと一致するため上書き不要。
+        return ['name' => 'プロジェクト名'];
+    }
 }
```

### 新規: app/Http/Requests/Organizations/StoreOrganizationRequest.php

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 組織作成。認可は「認証済みユーザーなら誰でも作成可」のため常に true
 * (FormRequest は validation 単独責務 = テンプレート規約)。
 */
class StoreOrganizationRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'name' => ['required', 'string', 'max:255'],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        // UI ラベル (Organizations/Create.svelte「組織名」) と揃える。
        // グローバル attributes の 'name' => '名前' より優先される局所上書き
        // (UpdateOrganizationRequest と対称)。
        return ['name' => '組織名'];
    }
}
```

### 新規: tests/Feature/Organizations/OrganizationCreateCopyTest.php

```php
<?php

declare(strict_types=1);

/**
 * 組織作成フォームのバリデーション文言 (T012 レビュー指摘由来)。
 *
 * 更新経路 (organizations.update) は OrganizationSettingsCopyTest が担う。
 * 作成経路 (organizations.store) も同一エンティティ・同一ラベル (Organizations/Create.svelte
 * 「組織名」) のため、StoreOrganizationRequest::attributes() の局所上書きで語彙を揃える。
 */

// StoreOrganizationRequest::attributes() の局所上書きが効き、グローバルの「名前」ではなく
// UI ラベル (Organizations/Create.svelte「組織名」) 準拠の「組織名」で表示されることを
// 厳密一致で検証する (表示文言そのものが検証対象)
test('組織作成で組織名が空だと局所上書きされた日本語ラベルのエラー文言が返る', function (): void {
    // .env.testing は APP_LOCALE=en のため、日本語文言の検証対象ロケールを明示する
    $this->app->setLocale('ja');

    [, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)
        ->from(route('organizations.create'))
        ->post(route('organizations.store'), ['name' => '']);

    $response->assertSessionHasErrors(['name' => '組織名は必須項目です。']);
});
```

### 新規: tests/Feature/Projects/ProjectCopyTest.php

```php
<?php

declare(strict_types=1);

use App\Models\Project;

/**
 * プロジェクト作成・更新フォームのバリデーション文言 (T012 レビュー指摘由来)。
 *
 * Projects/Create.svelte:35 / Edit.svelte:40 のフォームラベルは「プロジェクト名」のため、
 * Store/UpdateProjectRequest::attributes() の局所上書きでグローバルの「名前」ではなく
 * ラベル準拠の語彙でエラー文言を返す (語彙ズレ禁止 = lang/ja/validation.php の規約)。
 */
test('プロジェクト作成で名前が空だと UI ラベル準拠のエラー文言が返る', function (): void {
    // .env.testing は APP_LOCALE=en のため、日本語文言の検証対象ロケールを明示する
    $this->app->setLocale('ja');

    [, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)
        ->from(route('projects.create'))
        ->post(route('projects.store'), ['name' => '']);

    $response->assertSessionHasErrors(['name' => 'プロジェクト名は必須項目です。']);
});

test('プロジェクト更新で名前が空だと UI ラベル準拠のエラー文言が返る', function (): void {
    $this->app->setLocale('ja');

    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $response = $this->actingAs($owner)
        ->from(route('projects.edit', $project))
        ->patch(route('projects.update', $project), ['name' => '']);

    $response->assertSessionHasErrors(['name' => 'プロジェクト名は必須項目です。']);
});
```

## 質問

上記修正について impl-review を行い、[Critical] / [Warning] / [Suggestion] を報告せよ。
