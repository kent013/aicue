# Codex 実装レビュー依頼: T082 (aigenba 整列 トラック T-a / 施策 1〜8)

## 【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## 【セキュリティ不変条件(アプリ都合で緩めない) — AGENTS.md より】

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)

## 【思考原則 — 全議論に適用】

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## 【ツール使用制限】

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。
リポジトリのルートは `/workspace/.claude/worktrees/tasks/T082` である（差分に現れないファイルを確認したい場合のみ読んでよい）。

---

# あなたの役割: 実装レビュアー

以下の実装差分（`git diff main...HEAD`）が、**添付の詳細設計書（施策 1〜8）どおりに実装されているか**を厳密にレビューせよ。
詳細設計書は Codex 合議 6 ラウンドで **APPROVED 済み**である。したがって「設計を変えるべき」という提案よりも、
**「実装が設計から逸脱していないか」「逸脱がある場合それは正当か」**を最優先で見よ。

## レビュー観点（優先順）

1. **設計逸脱の検出**: 詳細設計書に書かれていない構造・定数・除外リスト・分岐が実装に入っていないか。
   入っている場合、それが**保証を弱めていないか**（特に deny-by-default gate の穴）。
2. **AGENTS.md 禁止事項・セキュリティ不変条件への抵触**。
3. **テストが不変条件を実際に固定しているか（空振りしていないか）**。
   - 負のコントロールが本当に負のコントロールとして機能しているか
   - skip されているテストが「握りつぶし」になっていないか
   - assert が緩すぎて実装をどう壊しても通ってしまう箇所は無いか
4. **PHPStan level 10 適合性**（型を緩めて黙らせていないか）。
5. **副作用・後退リスク**: 既存挙動を壊す可能性、fail-open になる分岐、順序依存。

## 実装者から申告されている「設計との差分」（重点確認せよ）

実装者は以下 4 点を「詳細設計書に無い実装判断（未レビュー）」として申告している。
**それぞれが正当か / 保証を弱めていないか**を必ず判定せよ。

1. `RouteBindingTypes::MANUALLY_RESOLVED` の**新設**（設計書に無い）。
   IV-9(a)「action 引数が宣言モデル型であること」検査から `notification` を除外している。
2. `RouteBindingTypes::NON_MODEL` の**縮小**。設計書は
   `['ability','action','bucket','intent','provider','resource','userId']` だったが
   実装は `['intent','provider','userId']` に減っている（実 route 走査の結果と称している）。
3. Livewire の route identity について、gate が **uri prefix を `livewire/` へ正規化**して突合している
   （設計書の「route identity は route name。無い場合 `method:uri`」に対する追加処理）。
4. `routes/api.php` から **`whereNumber` 6 箇所を削除**している（`Route::pattern` へ集約したという主張）。

## 実装者から申告されている「未達」（妥当性を判定せよ）

- **施策 8 の必須完了条件**（WebKit レーンで bfcache 復元シナリオ 2・3・4 を恒久自動回帰として成立させる）が
  **成立していない**。`tests/Browser/AuthenticatedPageBfcacheTest.php` の 3 件が chromium / webkit
  **両レーンで skip** されている。実装者は独立した正のコントロール（公開ページ間の戻りで
  JS 実行コンテキストが生存するか）を実測し、chromium=false / webkit=false を確認したと報告している
  （= Playwright WebKit でも bfcache 復元が起きない）。
- iOS Safari 実機受入確認は環境上実施不能。

これは設計書が「必須（実装完了条件）」と明記した項目である。
**この状態で T082 を完了扱いにしてよいか**、してはいけないなら**どの選択肢を取るべきか**を判定せよ。
また、`docs/supported-browsers.md` の記載が**実態と食い違っていないか**（設計書は「マージ後の実態を書く」と規定）を確認せよ。

## 検証コマンドの結果（実装者報告・参考）

`composer test` (2081 tests / 2079 passed / 2 skipped) / `composer phpstan` (level 10, no errors) /
`vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` (826 tests) / `pnpm build` /
`composer test:browser` (chromium 8 tests: 5 passed 3 skipped / webkit 8 tests: 5 passed 3 skipped) — 全て exit 0。

## 出力形式

指摘は重大度で分類し、**必ず `[Critical]` / `[Warning]` / `[Suggestion]` の接頭辞**を付けよ。

- `[Critical]`: 設計逸脱で保証が壊れている / セキュリティ不変条件・禁止事項に抵触 / テストが空振りしている / 本番で壊れる
- `[Warning]`: 保証が弱い・後退リスクがある・設計意図と乖離しているが致命的ではない
- `[Suggestion]`: 改善提案

各指摘には **(a) 該当ファイル・行 (b) なぜ問題か (c) 具体的な修正方針** を書け。
最後に **VERDICT: APPROVED / CHANGES_REQUESTED** を 1 行で書け。

---

# 資料 1: 詳細設計書（施策 1〜8。これが正本）

# 施策 1: route binding 型制約の適用 (bigint / uuid)

### 変更箇所

- `app/Providers/AppServiceProvider.php` — `boot()` に型制約の一括適用を追加（現行 L154 の `Route::bind('organization', ...)` の直後）
- `app/Http/Routing/RouteBindingTypes.php` — **新規**。total inventory の単一 source of truth

### 波及変更

- TypeScript型定義: **なし**
- API Resource/DTO: **なし**
- **ドメイン制約の導入（重要・design-review R2 Warning）**: `[0-9]{1,18}` は DB の bigint が
  許容する **19 桁 ID を意図的に排除する**。「適合値の挙動は不変」ではなく、
  **「AI-CUE の route key は最大 18 桁」という新しいドメイン制約を導入している**。
  この制約は `RouteBindingTypes` の docblock と `docs/architecture.md` に記録し、
  施策 2 の Architecture テストで **`BIGINT_PATTERN` の値自体を pin** する
  （将来 `[0-9]+` に戻す変更を検出するため）
- テストファイル: 施策 2（Architecture）・施策 3（Feature）・**regex 単体テスト（Unit）**を新規追加

### 設計判断: なぜ `Route::pattern` を使うのか

概念設計 Round 1 Critical の結論どおり **global 一律適用はしない**が、
**inventory に登録した param 名に対して個別に `Route::pattern($name, $regex)` を呼ぶ**形にする。

| 候補 | 採否 | 理由 |
|---|---|---|
| 各 route に `->whereNumber()` を書く | **不採用** | web 約 120 + api 7 箇所への手書きは**漏れが必ず出る**。追加 route での付け忘れを人手に依存する |
| `Route::pattern` を inventory 駆動で適用 | **採用** | 適用漏れが構造的に起きない。inventory が単一 SoT になり施策 2 の gate と突合できる |
| global `Route::pattern('*', ...)` | **不採用** | `{organization:slug}` が全滅する（Round 1 Critical） |
| bigint param ごとに正規化 binder (`Route::bind`) を生やす | **不採用** | `Route::bind` を 11 個生やすことになる。`[0-9]{1,18}` の pattern で 22P02 / 22003 の両方を保証できる以上**過剰**（思考原則 #1 / #2）。ただしこの判断は施策 3 の実測で確定させる |

`Route::pattern` は Laravel 標準機構であり、**フレームワークのレンジ内**（思考原則 #1）。
制約に合致しないセグメントは**そもそも route にマッチしない = 404** になり、
`SubstituteBindings` に到達しないため DB クエリが発行されない（= 22P02 が起きない）。

### 現行コード

`app/Providers/AppServiceProvider.php`:

```php
        Route::bind('organization', MembershipScopedOrganizationBinder::class);
```

`routes/web.php`（該当箇所のみ）:

```php
        Route::patch('/notifications/{notification}/read', ...)
            ->whereUuid('notification')
            ->name('notifications.read');
```

### 変更後コード

**新規 `app/Http/Routing/RouteBindingTypes.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Http\Routing;

/**
 * route binding param の型 inventory（total inventory。分類漏れを禁止する）。
 *
 * 背景: pgsql は型不一致の比較で 22P02 (invalid input syntax) を投げるため、
 * 非適合セグメント (/projects/abc) が implicit binding に届くと QueryException →
 * **404 ではなく生 500** になる。AI-CUE はこのバグクラスを {notification} (whereUuid) と
 * {organization} (binder の normalizeIntegerId) で個別に潰していたが系統化されておらず、
 * bigint 11 param と uuid {oauthSession} が無防備だった (監査 2026-08-02)。
 *
 * 本 inventory は「全 binding param を **5 分類**のいずれかに登録する」ことを要求する
 * 単一 source of truth であり、tests/Architecture/RouteBindingTypeConstraintInventoryTest が
 * routes 定義と突合して**未登録 param の出現を fail** させる (deny-by-default)。
 * 未知 param を数値と推測することはしない。
 *
 * 分類の意味:
 *  - BIGINT:        $table->id() の PK。数値制約を Route::pattern で適用する
 *  - UUID:          $table->uuid() / HasUuids の PK。UUID 制約を適用する
 *  - CUSTOM_BINDER: Route::bind の explicit binder が入力正規化を担う。pattern は適用しない
 *  - NON_MODEL:     モデル binding ではない文字列 param。型制約の対象外
 */
final class RouteBindingTypes
{
    /**
     * bigint PK。Route::pattern で数値制約を適用する。
     *
     * **param 名 => 対応モデル**の map で持つ (design-review R5 Warning)。
     * StudlyCase からのクラス名推測は namespace や例外モデルで破綻するため、
     * **対応モデル型の source of truth をここに置く**。IV-3 / IV-9 が共用する。
     *
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public const BIGINT = [
        'analysisJob' => AnalysisJob::class,
        'apiKey' => ApiKey::class,
        'category' => Category::class,
        'cut' => Cut::class,
        'invitation' => OrganizationInvitation::class,
        'item' => Item::class,
        'manual' => VideoManual::class,
        'project' => Project::class,
        'renderJob' => RenderJob::class,
        'take' => Take::class,
        'user' => User::class,
    ];

    /**
     * UUID PK。Route::pattern で UUID 制約を適用する。
     *
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public const UUID = [
        'notification' => DatabaseNotification::class,
        'oauthSession' => OauthSession::class,
    ];

    /**
     * param ごとに**許可する binding field**。`{user:slug}` のように
     * 非 PK field を指定されると Route::pattern の型制約と意味がずれるため、
     * IV-9 が「field 未指定 (= routeKeyName) か、ここに列挙された field のみ」を要求する。
     *
     * 既定は**空 = field 指定を一切許さない** (PK 解決のみ)。
     * 将来 `{manual:slug}` 等が必要になったら、その param を BIGINT/UUID から外すか
     * ここへ明示登録する (= 型制約と両立するかを人間が判断する契機になる)。
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED_BINDING_FIELDS = [];

    /**
     * explicit binder が入力正規化を担う param。**pattern は適用しない**。
     * {organization} は {organization:slug} を併用するため数値制約を掛けると
     * slug route が全滅する (概念設計 Round 1 Critical)。
     *
     * @var array<string, class-string>
     */
    public const CUSTOM_BINDER = ['organization' => MembershipScopedOrganizationBinder::class];

    /** モデル binding ではない文字列 param。型制約の対象外。 @var list<string> */
    public const NON_MODEL = [
        'ability', 'action', 'bucket', 'intent', 'provider', 'resource', 'userId',
    ];

    /**
     * 外部 (vendor) route が持ち込む param を **route identity ごと**に登録する inventory。
     * **pattern は適用しない**。
     *
     * **param 名だけの list では同名衝突を検出できない** (design-review R4 Critical):
     * vendor が非数値用途の `{user}` を追加しても `user` は既に BIGINT 登録済みなので
     * 素通りし、global な数値 pattern が vendor route を破壊する。
     * そこで **route name => その route が持つ external param のリスト** で持ち、
     * IV-7 が「EXTERNAL 宣言された param が BIGINT / UUID と同名でないこと」を
     * **明示的に fail** させる。
     *
     * route identity には **route name を使う** (URI は prefix 設定で動くため不安定)。
     * name 無し route が対象になる場合は `method:uri` の signature を使う
     * (HTTP method は昇順ソートし、暗黙の HEAD は除外する)。
     * **route identity の実在・params 完全一致・BIGINT/UUID との衝突は IV-7 が検証する**
     * (IV-2 は param の逆方向検査であり別責務)。
     *
     * 登録手順 (**自動抽出はしない**): gate (IV-1) が未登録 param を
     * route identity・action 付きで列挙するので、人間が用途を確認して 5 分類のいずれかへ登録する。
     * 「route file 由来か」を機械判定しようとすると出自判定問題が再発するため、
     * 外部 route の自動抽出は要件にしない (design-review R5/R6 Warning)。
     *
     * @var array<string, list<string>>
     */
    public const EXTERNAL = [
        // 例: 'passport.tokens.destroy' => ['token_id'],
        // 実装時に route:list を実走査して確定する。
    ];

    /**
     * bigint PK の route 制約。**18 桁上限**にすることで 2 種類の pgsql 例外を同時に塞ぐ。
     *
     *  - 非数値 (/projects/abc) → 22P02 invalid_text_representation
     *  - 桁あふれ (/projects/<30桁>) → **22003 numeric_value_out_of_range**
     *
     * `[0-9]+` だと後者が regex を通過して DB へ到達し 500 になる (design-review R1 Critical)。
     * bigint / PHP_INT_MAX = 9223372036854775807 (**64bit PHP 前提**) は 19 桁なので、
     * 18 桁の最大値 999999999999999999 は必ず範囲内 = **桁数だけで範囲内を保証できる**。
     *
     * **これはドメイン制約の導入である**: DB の bigint が許容する 19 桁 ID を意図的に排除し、
     * 「AI-CUE の route key は最大 18 桁」と定める。実 ID が 10^18 に達することは無いため
     * 運用上の制約にならないが、「適合値の挙動が不変」ではない点に注意
     * (docs/architecture.md に記録。値自体を Architecture テストで pin する)。
     *
     * 先頭ゼロ ('007') は本 pattern にマッチするが pgsql は '007'::bigint を正常に解釈するため
     * 500 にならない (該当行なしで 404)。canonical URL の要件は別問題なのでここでは制約しない。
     */
    public const BIGINT_PATTERN = '[0-9]{1,18}';

    /** Laravel の UUID 制約 (whereUuid 相当)。 */
    public const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * 登録済みの全 param 名（gate が routes 定義と突合するために使う）。
     *
     * @return list<string>
     */
    public static function allRegistered(): array
    {
        return [
            ...array_keys(self::BIGINT),
            ...array_keys(self::UUID),
            ...array_keys(self::CUSTOM_BINDER),
            ...self::NON_MODEL,
            ...self::externalParams(),
        ];
    }

    /**
     * EXTERNAL に宣言された全 param 名を平坦化する。
     *
     * `array_merge(...array_values(self::EXTERNAL) ?: [[]])` は PHPStan の推論が不安定なため
     * 専用メソッドに切り出す (**型を緩めて回避しない**。禁止事項 #2。design-review R6 Suggestion)。
     *
     * @return list<string>
     */
    public static function externalParams(): array
    {
        $params = [];
        foreach (self::EXTERNAL as $routeParams) {
            foreach ($routeParams as $param) {
                $params[] = $param;
            }
        }

        return $params;
    }
}
```

**`app/Providers/AppServiceProvider.php`（`boot()` 内、既存 `Route::bind` の直後）**:

```php
        Route::bind('organization', MembershipScopedOrganizationBinder::class);

        // route binding 型制約 (RouteBindingTypes が単一 SoT)。
        // 非適合セグメントは route にマッチしない = 404 になり、SubstituteBindings へ
        // 到達しないため pgsql 22P02 (→ 生 500) が構造的に起きない。
        // CUSTOM_BINDER (= {organization}) は binder 側が正規化するため pattern を適用しない
        // ({organization:slug} を併用しており数値制約は掛けられない)。
        foreach (array_keys(RouteBindingTypes::BIGINT) as $param) {
            Route::pattern($param, RouteBindingTypes::BIGINT_PATTERN);
        }
        foreach (array_keys(RouteBindingTypes::UUID) as $param) {
            Route::pattern($param, RouteBindingTypes::UUID_PATTERN);
        }
```

### 後方互換の並走を残さない（思考原則 #3）

`routes/web.php:358,361` の `->whereUuid('notification')` は
`Route::pattern('notification', UUID_PATTERN)` と**同じ制約の二重掛け**になる。
**同じ PR で `whereUuid` 呼び出しを削除**し、L350 のコメントを
「型制約は `RouteBindingTypes` に集約」へ書き換える（旧実装を残さない）。

### PHPStan適合チェック

- [x] `allRegistered()` の戻り値を `list<string>` で明示（`array_keys` の結果は `list<string>` に収まる）
- [x] **regex 単体テスト（Unit）**: `BIGINT_PATTERN` に **18 桁が成功・19 桁が失敗**することを直接検証する。
      Feature テストでは 18 桁も 19 桁も最終結果が 404 で**区別できない**ため、
      「route にマッチした」ことの証明はこの層で行う（design-review R2 Warning）
- [x] const 配列に `@var list<string>` / `array<string, class-string>` を付与
- [x] null 安全: null を扱わない（全て const と foreach）
- [x] DTO 返却なし（本施策は値オブジェクトを返さない。const と静的メソッドのみ）

### テスト計画

- [x] バグ修正の再現テストを先に書く（施策 3）。`/projects/abc` と `DELETE .../sessions/abc` が
      **現状 500 で fail** することを確認してから本施策を実装する
- [x] 施策 2 の inventory gate も**先に落ちる**ことを確認する（未制約 param があるため）
- [x] 既存テストの更新: `tests/Feature/Notifications/NotificationCenterTest.php` が
      `whereUuid` 由来の 404 を検証している可能性があるため、`whereUuid` 削除後も green を確認する
- [x] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

| リスク | 対策 |
|---|---|
| **`{user}` が数値以外で bind されている route が存在する** | 施策 3 で全 bigint param の 404 化を確認する。もし slug/username bind の route があれば inventory の分類を訂正する（gate が突合するため設計時に気づける） |
| `Route::pattern` は**全 route に効く**ため、同名 param を非モデル用途で使う route があると壊れる | `NON_MODEL` に `userId` を分けてあるのはこのため（`{user}` と `{userId}` は別 param）。gate が全 param を突合するので混入は検出される |
| `whereUuid` 削除で notification の制約が緩む | `Route::pattern('notification', UUID_PATTERN)` が同一制約を掛ける。施策 3 で 404 を実測して固定する |
| **桁あふれ (22003) が残る** | `BIGINT_PATTERN` を `[0-9]{1,18}` にして regex だけで範囲内を保証する。**施策 3 で範囲外・極長桁を実測**して確定させる (design-review R1 Critical) |
| **vendor / 将来の非モデル route が同名 param を使い `Route::pattern` と衝突する** | 施策 2 の **IV-7 (衝突検出)** が検出する。衝突時は (a) param 名を分離する か (b) 当該 param を `Route::pattern` から外し個別 `where` へ切替える |

---

# 施策 2: route binding total inventory gate

### 変更箇所

- `tests/Architecture/RouteBindingTypeConstraintInventoryTest.php` — **新規**

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: なし / テストファイル: 本施策自体が成果物

### 設計方針

**deny-by-default の total inventory**。`Route::getRoutes()` から
**全 route（vendor 含む）** の binding param を集め、`RouteBindingTypes` の **5 分類**と突合する。
**route の出自判定は行わない**（design-review R3/R4）。

| 検証 | 内容 |
|---|---|
| **IV-1 (分類漏れ禁止)** | **全 route（vendor 含む）**に現れる全 param が inventory の **5 分類**のいずれかに登録されていること。**未登録は fail**（メッセージで「型・解決方式・除外理由を登録せよ」と促す） |
| **IV-2 (逆方向 / param)** | inventory に登録済みだが routes に現れない param が無いこと（陳腐化した登録の検出）。※ `EXTERNAL` の **route identity** 実在確認は IV-2 ではなく **IV-7** の責務（design-review R5 Warning） |
| **IV-3 (bigint 制約)** | `BIGINT` map の全 param が `Route::pattern` で数値制約を持つこと |
| **IV-4 (uuid 制約)** | `UUID` map の全 param が UUID 制約を持つこと |
| **IV-5 (custom binder)** | `CUSTOM_BINDER` の param に対応する binder クラスが実在し、**`NormalizesRouteBindingInput`（分類宣言）を実装している**こと。かつ **pattern が適用されていない**こと（`{organization:slug}` を壊さないため）。※ **入力正規化の実効性は本 gate では保証しない**（下記） |
| **IV-6 (排他性)** | 同一 param が複数分類に重複登録されていないこと |
| **IV-7 (EXTERNAL 宣言との突合)** | `EXTERNAL` の各エントリについて **(a) route identity が実在すること**、**(b) 登録 params と実 route の params が完全一致すること**、**(c) 登録 param が `BIGINT` / `UUID` と同名でないこと**（同名なら global pattern が外部 route を破壊するため明示 fail）。※ 出自判定ではなく**宣言との突合**（design-review R4/R5） |
| **IV-9 (binding 解決の一致)** | `BIGINT` / `UUID` の param を持つ全 route について、**(a) action 引数が `RouteBindingTypes` の map で宣言された対応モデル型であること**、**(b) `$route->bindingFieldFor($param)` が `null` か `ALLOWED_BINDING_FIELDS` に列挙された field であること**、**(c) field 未指定なら当該モデルの `getRouteKeyName()` が PK（bigint / uuid 列）であること**。`SubstituteBindings` が実際に使う解決経路そのものを検査する（design-review R4/R5 Critical） |
| **IV-8 (pattern 値の pin)** | `BIGINT_PATTERN` が `[0-9]{1,18}` であること。`[0-9]+` へ戻すと桁あふれ 22003 が復活するため、**値自体を固定**する（design-review R2 Warning） |

#### IV-5 の責務分離（design-review R1 → R2 で 2 度修正）

R1 では「メソッド名依存は脆い」ため interface 化した。しかし **空の marker interface は
空実装でも通過するため、それ自体は何も保証しない**（R2 Critical 相当の Warning）。
そこで**責務を分けて**扱う。

| 層 | 何を担うか |
|---|---|
| **marker interface（分類の宣言）** | 「この param は `Route::pattern` ではなく binder が担う」という**意思表示**。IV-5 はこれと「pattern 未適用」を検証する |
| **binder ごとの Feature テスト（実効性の正本）** | **入力正規化が実際に効いていること**。施策 3 に `{organization}` の異常系を追加する |

```php
namespace App\Http\Routing;

/**
 * CUSTOM_BINDER 分類の宣言用 marker。
 *
 * この interface 自体は挙動を強制しない (空 interface のため空実装でも通る)。
 * **入力正規化が実際に効いていることの正本は Feature テスト**
 * (tests/Feature/Routing/RouteBindingTypeConstraintTest の {organization} 異常系) である。
 *
 * 本 interface の役割は「この param は Route::pattern による宣言的制約を適用できず
 * ({organization} は {organization:slug} を併用するため)、binder が 22P02 / 22003 相当の
 * 入力を弾く責務を負う」という分類を型で表明することに限られる。
 */
interface NormalizesRouteBindingInput {}
```

#### IV-7 の衝突時の運用と、**保証の限界**（design-review R3 Warning）

衝突を検出したら、次のいずれかを取る（gate のメッセージに明記する）:

1. **param 名を分離する**（例: vendor が `{user}` を使うならアプリ側を `{appUser}` へ）
2. **当該 param を `Route::pattern` の適用対象から外し、個別 `where` へ切り替える**
   （inventory の分類は維持し、制約の掛け方だけ変える）

#### 同名衝突をどう検出するか（design-review R4 Critical への回答）

`EXTERNAL` を param 名の list にすると、**vendor が非数値用途の `{user}` を追加しても
`user` は既に `BIGINT` 登録済みなので IV-1 を素通りし、global pattern が壊す**。
そこで検出を **2 段**にする。

| 層 | 何を検出するか | 限界 |
|---|---|---|
| **IV-7（宣言との突合）** | `EXTERNAL` の (a) route identity 実在 (b) 登録 params と実 params の完全一致 (c) `BIGINT`/`UUID` との同名衝突 → fail | **宣言されていない**外部 route は拾えない |
| **IV-9（binding 解決の一致）** | `BIGINT`/`UUID` param を持つ**全 route** について **(a) モデル型 (b) binding field (c) `getRouteKeyName()`** の 3 点を検査。`SubstituteBindings` の実解決経路そのもの | 型が付いていない closure route は fail するが、それは**意図した検出**（`EXTERNAL` へ宣言すれば解決） |

**IV-9 が本質的な防御**である。検出できる例:

| 衝突の形 | 検出する検査 |
|---|---|
| vendor が `{user}` を**文字列**として使う（typehint 無し） | (a) モデル型不一致 |
| **`{user:slug}`** のように**非 PK field** で bind する（Laravel で一般的な記法） | (b) binding field が `ALLOWED_BINDING_FIELDS` に無い |
| モデルの `getRouteKeyName()` が **slug 等の非 PK** を返すよう変更された | (c) `getRouteKeyName() !== getKeyName()` |
| モデルの PK 型が宣言分類と食い違う（bigint 宣言なのに UUID 化された等） | (c) 型区分の不一致 |

**(c) の判定方法を実装時に曖昧にしない**（design-review R6 Warning）。**DB に触れない
モデル metadata だけ**で判定する:

| 検査 | 手段 |
|---|---|
| PK 解決であること | `$model->getRouteKeyName() === $model->getKeyName()` |
| `BIGINT` 宣言の型一致 | `$model->getKeyType() === 'int'` かつ `$model->getIncrementing() === true` |
| `UUID` 宣言の型一致 | `$model->getKeyType() === 'string'` かつ `getIncrementing() === false`（`HasUuids` 系）|

モデルは `new $class` でインスタンス化するだけで済み、**DB 接続は不要**
（既存 Architecture テストと同じく DB 不使用を保てる）。

> **(b)(c) は design-review R5 Critical への対応**。R4 時点の IV-9 は
> **モデル型しか見ておらず**、`User $user` を受ける `{user:slug}` を通過させていた。
> これは「実質存在しない残存リスク」ではなく**一般的な記法**であり、
> 数値 pattern を掛けると slug route が壊れる（`{organization:slug}` で既に踏んだのと同じ形）。

`ALLOWED_BINDING_FIELDS` の既定は**空 = field 指定を一切許さない**。
将来 `{manual:slug}` 等が必要になったら、その param を `BIGINT`/`UUID` から外すか
ここへ明示登録する（= **型制約と両立するかを人間が判断する契機**になる）。

### 実装スケッチ

```php
<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Http\Routing\RouteBindingTypes;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * route binding param の型制約 total inventory gate（deny-by-default）。
 *
 * 不変条件: **全 route (vendor 含む)** に現れる**全ての** binding param が RouteBindingTypes の 5 分類の
 * いずれかに登録され、分類に応じた制約を持つ。未登録 param の出現は fail させる
 * （未知 param を数値と推測しない ＝ 概念設計 Round 2 の決定）。
 *
 * 守る事故: pgsql の型不一致 22P02 / 桁あふれ 22003 → QueryException →
 * **404 ではなく生 500**。
 * 実挙動 (非適合→404) と custom binder の入力正規化の実効性は
 * tests/Feature/Routing/RouteBindingTypeConstraintTest が担保する
 * (本 gate は分類の網羅と制約の適用のみを見る)。
 */
final class RouteBindingTypeConstraintInventoryTest extends TestCase
{
    // IV-1 〜 IV-9 を it() で分割して実装する。
    //  IV-1 分類漏れ禁止 (全 route 走査 / 5 分類) / IV-2 逆方向 (陳腐化) /
    //  IV-3 bigint 制約 / IV-4 uuid 制約 / IV-5 custom binder 分類宣言 /
    //  IV-6 分類の排他性 / IV-7 EXTERNAL 衝突検査 /
    //  IV-8 pattern 値の pin ([0-9]+ への退行検出) /
    //  IV-9 binding 型解決の一致 (signatureParameters と分類の突合)
}
```

**param の抽出方法**: `Route::getRoutes()` を走査し、各 `$route->parameterNames()` を集める。
`{organization:slug}` のような field 指定は `parameterNames()` が `organization` を返すため、
field を剥がす追加処理は不要。

**pattern の確認方法**: `$route->wheres` に param → regex が入る（`Route::pattern` は
route 登録時に merge される）。`RouteBindingTypes::BIGINT_PATTERN` との一致で検証する。

### PHPStan適合チェック

- [x] `parameterNames()` の戻り値を `list<string>` として扱う（Laravel の型定義に従う）
- [x] `$route->wheres` は `array<string, string>`
- [x] Assert は Pest の `expect()` を使い、null 分岐を作らない

### テスト計画

- [x] **負のコントロール（IV-1）**: inventory から `project` を一時的に外すと fail することを確認
- [x] **負のコントロール（IV-3）**: `Route::pattern` の適用をコメントアウトすると fail することを確認
- [x] **負のコントロール（IV-7）**: `EXTERNAL` に `BIGINT` と同名の param を宣言すると fail することを確認
- [x] **負のコントロール（IV-9-a）**: `{user}` を**モデル型 typehint 無し**で受ける fixture route で fail することを確認
- [x] **負のコントロール（IV-9-b）**: **`{user:slug}`** の fixture route で fail することを確認（R5 Critical の本丸）
- [x] **負のコントロール（IV-9-c）**: `getRouteKeyName()` が非 PK を返すモデルを使う fixture で fail することを確認
- [x] **負のコントロール（IV-8）**: `BIGINT_PATTERN` を `[0-9]+` に変えると fail することを確認
- [x] 負のコントロールは**実ファイルを書き換えず fixture に対して実行**する
- [x] 個別の `DatabaseTransactions` を使っていないことを確認（本テストは DB 不使用）

### リスク

| リスク | 対策 |
|---|---|
| Filament / Passport / Livewire 等の**外部 route** が param を持ち込み IV-1 が誤 fail する | **`EXTERNAL` に route identity ごと登録する**（出自判定はしない。**全 route が IV-1 の対象**）。同名衝突は IV-7 / IV-9 が fail させる |

#### 出自判定は行わない（design-review R3 Warning で方式変更）

R2 では「controller namespace で app route か判定する」候補を残していたが、
この方式は **closure route** と **vendor controller をアプリ側で登録する route（Fortify 等）** を
正しく分類できず、**実装時判断として残すのは危険**という指摘を受けた。

**方式を変更し、出自判定そのものを不要にした**:

- inventory に **第 5 分類 `EXTERNAL`**（外部 route が持ち込む param を **route identity ごと**に登録）を追加する
- **IV-1 は全 route（vendor 含む）を走査**し、現れる全 param 名が
  **5 分類のいずれかに登録されている**ことだけを要求する

これで「この route はアプリ由来か」を判定する必要が無くなる。

**`EXTERNAL` の登録は人間が行う（自動抽出しない。design-review R5 Warning）**:
gate（IV-1）が**未登録 param を route identity・action 付きで列挙**するので、
人間が用途を確認して 5 分類のいずれかへ登録する。
「route file 由来か」を機械判定しようとすると**出自判定問題が再発する**ため、
外部 route の自動抽出は要件にしない。

**route identity の規約**:
- **route name を第一**とする（URI は prefix 設定で動くため不安定）
- name 無し route は **`method:uri`** signature。HTTP method は**昇順ソート**し、
  **暗黙の `HEAD` は除外**する（`GET` 登録時に自動付与されるため identity が揺れる。R5 Suggestion）
| IV-2（逆方向）が worktree 差分で誤 fail する | inventory は「routes に現れうる param」の集合。将来 route を消したら登録も消す運用にする（gate が促す） |

---

# 施策 3: 非適合セグメント → 404 の実挙動テスト

### 変更箇所

- `tests/Feature/Routing/RouteBindingTypeConstraintTest.php` — **新規**

### テスト計画（fail-first を先に確認する）

**前提を各ケースに明示する**（design-review R1 Warning）。認証 / CSRF / 認可に吸われると
「404 が binding 由来か認可由来か」が区別できないため、**適合値の対比ケースを必ず併記**し、
「非適合だけが 404 になる」ことを対比で示す。

| # | ケース | 前提 | 期待 | 現状 |
|---|---|---|---|---|
| 1 | `GET /projects/abc`（bigint・**非数値**） | 認証済み・当該 org メンバー | **404**（500 でない） | **500 で fail** |
| 1' | `GET /projects/{実在ID}`（**対比**） | 同上 | **404 でない**（200 等） | green |
| 2 | `GET /projects/9223372036854775808`（**PHP_INT_MAX+1 = 19 桁**） | 同上 | **404**（500 でない） | **500 で fail** |
| 3 | `GET /projects/<30 桁>`（**極長数値**） | 同上 | **404** | **500 で fail** |
| 4 | `GET /projects/999999999999999999`（**18 桁上限値**） | 同上 | **404**（route にはマッチする = 制約が過剰に狭くない） | green |
| 5 | `GET /projects/007`（**先頭ゼロ**） | 同上 | **500 でない**（pgsql は正常解釈するため 404 想定） | green |
| 6 | `DELETE /organizations/{slug}/api-keys/sessions/abc`（uuid・非適合） | 認証済み・CSRF 付き・当該 org の管理権限 | **404** | **500 で fail** |
| 6' | `DELETE .../sessions/{実在 UUID}`（**対比**） | 同上 | **404 でない**（204/302 等） | green |
| 7 | 全 `BIGINT` param の代表 route に非数値を投げる | 各 route の前提を満たす | 404 | fail |
| 8 | `{organization:slug}` の route が**引き続き slug で解決する** | 認証済み・メンバー | 既存挙動 | green（回帰確認） |

> ケース 2・3 は **`[0-9]+` では通過して 22003 → 500 になる**ため、
> 施策 1 の `[0-9]{1,18}` が正しいことを実測で確定させる**本丸のケース**（design-review R1 Critical）。

#### custom binder（`{organization}`）の入力正規化 — 実効性の正本（design-review R2 Warning）

施策 2 の marker interface は**分類の宣言に過ぎず何も保証しない**ため、
`MembershipScopedOrganizationBinder` の入力正規化が実際に効いていることは**ここで固定する**。

| # | ケース | 期待 |
|---|---|---|
| 9 | `{organization}` に**非数値**（`/organizations/abc/...` の id bind 経路） | **404** |
| 10 | `{organization}` に **19 桁**（`9223372036854775808`） | **404** |
| 11 | `{organization}` に **30 桁** | **404** |
| 12 | `{organization:未許可 field}`（`BINDABLE_FIELDS` 外） | **404**（500 でない）。※ **テスト内で `Route::get(...)` を登録**し `routes/` には置かない（施策 2 の IV-1 が全 route を走査するため、本番 inventory を汚さない。design-review R3 Suggestion） |
| 13 | `{organization:slug}` に**実在 slug**（**対比**） | **200**（既存挙動の回帰確認） |

#### 対比ケースの fixture 要件（design-review R2 Warning）

対比ケース（1'・6'・13）は fixture が不完全だと**認可 / nested binding に吸われて
404 になり、対比の意味を失う**。したがって:

- **実在する親子関係を Factory で構築**する（Organization → Team → Project の階層、
  および `actingAs` するユーザーの当該組織メンバーシップ・必要な role）
- **期待ステータスを具体値で固定**する（「404 でない」ではなく `200` / `204` 等）

#### テスト環境契約（design-review R2 Suggestion）

本件は **pgsql 固有の事故**（22P02 / 22003）である。SQLite 等へ切り替わると
**非適合値でも例外にならず、テストが空振りで green になる**。
そのため接続 driver が `pgsql` であることを**テスト内で assert** し、
方言が変わったら気づけるようにする。

- ケース 1・2 を**先に書いて 500 を確認**してから施策 1 を実装する（AGENTS.md 思考原則 #5）
- テストデータは **Factory で生成**（`Model::create()` 手組み禁止）
- 認証が要る route は Factory で作った User で `actingAs()`
- 個別の `DatabaseTransactions` は使わない（`tests/Pest.php` のグローバル `RefreshDatabase` に従う）

### リスク

- ケース 3 の「既存挙動」はテナント境界により 403/404 に分岐しうる。
  **アサートは「500 でないこと」に寄せ**、具体的なステータスは既存テストの責務とする

---

# 施策 4: no-store baseline middleware (P3-a)

### 変更箇所

- `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` — **新規**
- `bootstrap/app.php` — `$middleware->web(append: [...])` の**末尾**に追加（現行 L82-89）

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 5（既存 4 経路のピン）・施策 8（Browser E2E）

### 現行コード

`bootstrap/app.php:82-89`:

```php
        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
            RequireTwoFactorForEnforcedOrganizations::class,
            BlockTwoFactorDisableForEnforcedOrganizations::class,
        ]);
```

### 変更後コード

```php
        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
            RequireTwoFactorForEnforcedOrganizations::class,
            BlockTwoFactorDisableForEnforcedOrganizations::class,
            // 認証済み応答の no-store baseline。
            // 契約: $next から返った (= 下流の) 応答を確認し、既に `no-store` を持つなら変更しない。
            // (位置関係ではなくこの契約が正本。実効性は Feature テストが固定する)
            NoStoreCacheHeadersForAuthenticatedPages::class,
        ]);
```

**新規 middleware**:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 認証済みリクエストの web 応答に `no-store` を保証する baseline middleware。
 *
 * 目的: ログアウト後のブラウザ「戻る」で認証済み画面 (メンバー一覧等の PII) が
 * bfcache から再表示されるのを防ぐ。`no-store` により Firefox は bfcache 格納自体を
 * 拒否し、Chrome は cookie 変更 (= ログアウト) 時に CCNS ページを bfcache から
 * eviction する。副次的に disk / proxy cache への認証済み応答残留も禁止される。
 *
 * **Safari は `no-store` でも bfcache に格納しうる**ため本 middleware だけでは
 * 抑止できない。AI-CUE は撮影が PWA (iOS Safari が主要プラットフォーム) であるため、
 * クライアント側の bfcache 秘匿・再検証 (resources/js/lib/bfcache-guard.ts) と
 * **セットで** 主便益を達成する。対象ブラウザは docs/supported-browsers.md。
 *
 * 適用判定は route 列挙ではなく「認証済みか」で行う (path 列挙は一般認証画面を
 * 取りこぼす)。guest / 公開ページ (login・LP・SEO) は対象外のままにし bfcache /
 * 共有キャッシュの恩恵を維持する。認証済み画面は Inertia SPA でアプリ内の戻る/進むは
 * client-side navigation のため bfcache 喪失による UX 後退はない。
 */
final class NoStoreCacheHeadersForAuthenticatedPages
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // logout POST は $next 通過後に guard 上の user が null になるため、
        // リクエスト時点の認証状態を先に捕捉する (= logout redirect も対象に含める)。
        $wasAuthenticated = $this->isAuthenticated($request);

        $response = $next($request);

        // リクエスト時点 or 応答時点のどちらかで認証済みなら付与対象
        // (login POST 応答 = 応答時点で認証済み、も保護側に倒す)。
        if (! $wasAuthenticated && ! $this->isAuthenticated($request)) {
            return $response;
        }

        // 既に no-store を持つ応答 (recent-auth 409 / 2FA 409 / 署名 URL redirect 等、
        // 内側で明示されたより厳格な値) は書き換えず維持する。
        // directive が縮む方向の上書きをしない。
        if ($response->headers->hasCacheControlDirective('no-store')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * 本 middleware の対象は session-backed な web 認証画面。session を持たない
     * リクエスト (routes/web.php:74,99 の stateless block: SEO/robots/公開ページは
     * StartSession を withoutMiddleware 済) は stateless 公開配信であり対象外。
     */
    private function isAuthenticated(Request $request): bool
    {
        return $request->hasSession() && $request->user() !== null;
    }
}
```

### 契約（概念設計 Round 3 で一意に確定）

| 応答の状態 | 挙動 |
|---|---|
| `Cache-Control` に **`no-store` を持つ** | **untouched** |
| `no-store` を**持たない** | `Cache-Control` を **`no-store, private` で置換** |

判定キーは `Cache-Control` の**存在ではなく `no-store` directive の有無**。
置換方式のため `public` / `max-age` 等の矛盾 directive は置換で消える。
**矛盾ヘッダの一般正規化は行わない**（思考原則 #2）。

### response class 判定を設けない理由

aigenba はヘッダ判定のみで運用している。AI-CUE の実測でも `StreamedResponse` は **0 件**、
`BinaryFileResponse` は `app/Http/Controllers/Testing/GetFakeStorageObjectController.php` の
**1 件のみ**（非 production の fake storage gate）。クラス判定を足す必要性が現時点で無い。

> **要確認（実装時）**: `GetFakeStorageObjectController` は `<video>` シーク用に
> Range 対応の `BinaryFileResponse` を返す。`no-store` 付与が Range リクエストの挙動を
> 壊さないことを施策 5 のテストで確認する。壊す場合のみクラス除外を追加する。

### 既存 4 経路への影響（実測値）

| 経路 | 現行値 | P3-a 適用後 |
|---|---|---|
| `FortifyServiceProvider:199`（招待 email を含む応答のみ） | `no-store` | **untouched** |
| 同上（招待 email が空の通常登録応答） | Cache-Control **なし** | `no-store, private` が付く（**強化方向。意図どおり**） |
| `RequireRecentAuth:57`（409 JSON） | `no-store` | **untouched** |
| `RequireTwoFactorForEnforcedOrganizations:93`（409 JSON） | `no-store` | **untouched** |
| `Capture/CaptureTakeController:177`（署名 URL への 302） | `no-store, private` | **untouched** |

### PHPStan適合チェック

- [x] 戻り値の型 `Response` を明示
- [x] `$request->user()` の null 判定を明示（`!== null`）
- [x] 判定を純粋な private メソッド `isAuthenticated()` に分離
- [x] DTO 返却なし（middleware のためヘッダ操作のみ）

### テスト計画

- [x] **fail-first**: 認証済みページの `Cache-Control` に `no-store` が付くテストを先に書き、fail を確認
- [x] 新規テスト: 認証済み Inertia 応答 → `no-store, private`
- [x] 新規テスト: guest / 公開ページ（LP・login）→ **付与されない**
- [x] 新規テスト: stateless block（SEO/robots）→ **付与されない**
- [x] 新規テスト: **logout POST の redirect 応答**にも付与される（`$wasAuthenticated` の効果）
- [x] 新規テスト: **login POST の応答**にも付与される（応答時点判定の効果）
- [x] 既存 4 経路のピンは施策 5
- [x] テストデータは Factory 生成 / 個別 `DatabaseTransactions` を使わない

### リスク

| リスク | 対策 |
|---|---|
| Range リクエスト（`<video>` シーク）が壊れる | 施策 5 で `GetFakeStorageObjectController` の挙動を確認。壊れる場合のみクラス除外 |
| 認証済みページの**共有キャッシュ恩恵が消える** | 認証済み応答は元々 `private` 相当であるべきで、後退ではない。guest / 公開ページは対象外 |
| bug-hunt の pcov middleware（`BughuntCoverageMiddleware`）と順序衝突 | `$middleware->append()`（L146）は web グループ外の global append。web 末尾の本 middleware とは独立 |

---

# 施策 5: 既存 no-store 4 経路のヘッダ完全値ピン

### 変更箇所

- `tests/Feature/Security/ExistingNoStoreContractTest.php` — **新規**

### 設計方針（概念設計 Round 3 Warning への対応）

`no-store` の**存在チェックだけでは `public, no-store` のような矛盾値を検出できない**。
4 経路それぞれについて **`Cache-Control` のヘッダ完全値**をピンする。

| # | 経路 | 期待完全値 |
|---|---|---|
| 1 | Fortify 登録応答（招待 email あり） | `no-store` |
| 2 | `RequireRecentAuth` の 409 | `no-store` |
| 3 | `RequireTwoFactorForEnforcedOrganizations` の 409 | `no-store` |
| 4 | `Capture/CaptureTakeController` の 302 | `no-store, private` |
| 5 | `GetFakeStorageObjectController` の Range 応答 | 実測して確定（施策 4 のリスク確認と兼ねる） |

### テスト計画

- [x] 各経路を実際に叩き、(a) `$response->headers->get('Cache-Control')` の**完全一致** と
      (b) **directive 集合（順序非依存）** の 2 段で assert する。
      **2 つのアサートは分離し、それぞれ固有のメッセージを付ける**
      （どちらが失敗したかで「順序だけ変わった」のか「意味が後退した」のかを判別できる。R1/R2 Suggestion）
- [x] P3-a 適用前後で**値が変わらない**ことを確認（untouched 契約の証明）
- [x] テストデータは Factory 生成 / 個別 `DatabaseTransactions` を使わない

### リスク

- 完全一致ピンは「意図的な強化」も落とす。**落ちたら期待値を更新する**運用でよい
  （落ちること自体が「契約が変わった」というシグナルとして機能する）

---

# 施策 6: bfcache 秘匿・再検証 (P3-b)

### 変更箇所

- `resources/js/lib/bfcache-guard.ts` — **新規**
- `resources/js/app.ts` — guard の初期化（**認証済みページのみ**）
- `resources/css/`（DS token 経由） — 秘匿オーバーレイのスタイル
- `app/Http/Controllers/Auth/SessionStatusController.php` — **新規**（軽量プローブ）
- `app/DataTransferObjects/Auth/SessionStatusDto.php` — **新規**
- `app/Http/Resources/Auth/SessionStatusResource.php` — **新規**
- `routes/web.php` — プローブ route の追加

### 波及変更

- TypeScript型定義: `PageTransitionEvent`（DOM 標準型）を明示。プローブ応答の型を `bfcache-guard.ts` 内に定義
- API Resource/DTO: **`SessionStatusDto` + `SessionStatusResource` を新設**（禁止事項 #4 遵守）
- テストファイル: 施策 8（Browser E2E）+ プローブの Feature テスト + guard 分岐の vitest

### 設計判断（概念設計 Round 4 Critical / design-review R1 Critical の反映）

**「復元後に検証」ではなく「検証完了まで復元内容を秘匿」**（概念設計 Round 4 Critical）。
`pageshow` 後に非同期検証する構造だと、検証完了までの間、**復元済みの古い DOM が表示され
PII が一瞬露出する**。「無効なら遷移する」は「再表示しない」と同義ではない。

**ただし hard reload は常用しない**（design-review R1 Critical）。
概念設計 Round 5 で第一候補としていた hard reload は、
**シナリオ 3（未ログアウトでの復元）で正当なユーザーの復元済みフォーム状態を無条件に破棄する**ため、
Round 4 で決めた「media stream / 未送信フォーム / Inertia 履歴を破棄しない」要件と**矛盾する**。

**確定した状態遷移**:

| # | 契機 | 動作 |
|---|---|---|
| 1 | `pagehide` | 画面を**同期的に秘匿**する = **`documentElement` に秘匿属性を付ける**（+ CSS でオーバーレイ表示）。**この DOM 状態ごと bfcache snapshot に入る**ことが要点 |
| 2 | `pageshow` | **`documentElement` に秘匿属性が付いていれば**（= bfcache 復元）、**秘匿状態のまま**軽量プローブでセッション有効性を確認する |
| 3 | セッション**有効** | **秘匿属性を外す（unhide）だけ**。DOM・フォーム状態・Inertia 履歴はそのまま |
| 4 | セッション**無効** | login へ **hard navigation**（遷移先は固定。下記） |
| 5 | プローブ**初回失敗** | **秘匿を維持**したまま**再試行ボタンを表示**する（自動再試行はしない） |
| 6 | ユーザーが**再試行を押下** | **現在 URL を hard reload**（サーバに再判定させる） |

これにより **PII の一瞬の露出も無く**（秘匿はプローブ完了まで解かない）、
かつ**正当なユーザーの状態も壊さない**（有効なら unhide のみ）。

### 軽量プローブ endpoint（概念設計 Round 4 の条件を全て満たす）

**既存 `/recent-auth/status` は流用しない**。あれは step-up 鮮度の endpoint であり、
セッション有効性とは**意味が違う**（思考原則「機能の名前に立ち返れ」）。
また recent-auth の provider 情報等、必要以上を露出する。

**最小の専用 endpoint を新設する**:

| 条件（Round 4） | 満たし方 |
|---|---|
| 同一オリジン | `routes/web.php` の web グループ（session cookie 前提） |
| `no-store` | 施策 4 の baseline middleware が付与（認証済み時）。**guest 応答にも明示付与**する |
| セッション認証 | web guard の session を参照する |
| **DTO + JsonResource** | `SessionStatusDto` → `SessionStatusResource`（禁止事項 #4） |
| PHPStan level 10 | 対象に含める |
| **PII を含まない** | 応答は `{ "authenticated": bool }` のみ |

```php
// routes/web.php — auth グループの **外**。guest でも 200 を返し、authenticated: false を伝える。
// auth グループ内に置くと未認証時に 302/401 になり、guard 側が「セッション無効」と
// 「endpoint 不在/エラー」を区別しにくくなるため。
Route::get('/session/status', SessionStatusController::class)->name('session.status');
```

> **なぜ guest でも 200 か**: guard は「無効なら login へ倒す」だけなので、
> ステータスコードではなく**明示的な boolean** を見る方が分岐が単純で誤判定しにくい。
> 認証状態そのものは同一オリジンの呼び出し元が cookie で既に知りうる情報であり、
> 新たな情報露出にはならない。

#### 既存 middleware からの exemption（design-review R3 Critical）

`RequireTwoFactorForEnforcedOrganizations` は `bootstrap/app.php` の **`web` グループ append** に
登録されており **web グループの全 route に効く**。プローブもその対象になるため、
2FA 強制中のユーザーには **409 / redirect** が返る。
guard は 200 boolean 以外を「プローブ失敗」として扱うので、
**有効なセッションなのに秘匿が解除されず、再試行 → 同じ結果 → ループ**になる。

**既存の allowlist 機構に載せる**:
`RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`
（`app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php:41`）は
**route name → 必要理由**の連想配列で、`TwoFactorEnforcementAllowlistTest` が
「全エントリが実在する named route」「各エントリが非空の理由を持つ」を CI で固定している。
**本リポジトリに確立済みの exemption 作法**なので、`session.status` をここへ登録する。

> **安全性**: プローブの応答は `{ authenticated: bool }` のみで **PII も操作も含まない**ため、
> 2FA 強制中に 200 を返しても情報露出にならない。

**web グループ append の他 middleware も確認済み**:

| middleware | プローブへの影響 |
|---|---|
| `RequireTwoFactorForEnforcedOrganizations` | **遮断する → allowlist 登録が必要** |
| `BlockTwoFactorDisableForEnforcedOrganizations` | 2FA disable route 限定。影響なし |
| `HandleInertiaRequests` / `SecurityHeaders` | 遮断しない |
| `RequireRecentAuth` / `RequireActiveSubscription` / `verified` | **route レベル**適用。プローブ route には付かない |

→ **遮断要因は 2FA gate のみ**。

#### 応答ヘッダの付与方法（design-review R3 Warning）

**`JsonResource::withResponse()`** で `no-store, private` を設定する。
Controller の戻り値型を `SessionStatusResource` のまま保てる。
既存 `RecentAuthStatusResource` は controller 側で付けているが、
プローブは **guest 応答も対象**（施策 4 の baseline は認証済みのみ）なので
**Resource 側に閉じる方が漏れない**。

### guard の適用範囲（design-review R1 Critical）

**Inertia の共有 props（`auth.user`）を起点に「認証済みページのみ初期化」**する。
既存の `resources/js/lib/shared-props.ts` が共有 props ヘルパ。
LP・login・SEO 等の公開ページでは guard を初期化しない
（不要なちらつき・プローブを起こさない）。

### 復元マーカーは `documentElement` の秘匿属性そのもの（design-review R2 Critical）

**`sessionStorage` は使わない**。`sessionStorage` は**タブ単位で共有される**ため、
**ページ A の `pagehide` が立てたフラグを通常遷移先のページ B が読み、誤って秘匿・プローブする**
（R1 でフォールバックとして足したものが新しい誤動作を生んでいた）。

代わりに **`documentElement` の秘匿属性そのものを復元マーカーにする**:

| | 挙動 |
|---|---|
| bfcache 復元 | `pagehide` で付けた属性が **DOM ごと復元される** → `pageshow` 時に属性あり |
| 通常ナビゲーション | サーバから**新しい HTML** を取得 → 属性は**存在しない** |

= マーカーが**本質的に履歴エントリ単位**になり、別ページへ漏れない。
`persisted` が取れない環境でも属性の有無だけで保守的に判定できるため、
フォールバックとしても `sessionStorage` より正確。

### ちらつき対策（概念設計 Round 5 Warning）

`pagehide` は**通常遷移でも発火する**ため無条件秘匿はちらつきを生む。
- `PageTransitionEvent.persisted` が利用できる環境では **bfcache 対象時だけ秘匿**する
- 利用できない環境では**安全側（秘匿する）** へ倒す
  （通常遷移では直後に新しい Document へ移るため、実害はほぼ無い）

### 設計制約

**秘匿は DOM 表示に限定する**（オーバーレイ要素の可視化 / `documentElement` への属性付与）。
**DOM ツリーの破棄・再構築はしない**。
**撮影中の media stream・未送信フォーム状態・Inertia 履歴状態は破棄しない**。
撮影 PWA が中核であるため、ここを壊すと使命に直撃する。

スタイルは **DS token 経由**（`DESIGN.md` が canonical。hex 直書きを増やさない）。
オーバーレイは既存の Atomic Design 階層に**新規 component を作らず**、
`app.ts` 由来のグローバル要素 + CSS で完結させる
（atoms/molecules の責務ではない = 階層を汚さない）。

### PHPStan適合チェック（プローブ側）

- [x] `SessionStatusDto` は `readonly` + `bool` プロパティ
- [x] `SessionStatusResource::toArray()` の戻り値型を明示
- [x] Controller は `__invoke(Request $request): SessionStatusResource`
- [x] `$request->user() !== null` で null 安全に判定
- [x] `response()->json()` を使わない（禁止事項 #4）

### テスト計画

- [x] **fail-first**: 施策 8 のシナリオ 4 を先に書き、PII が再表示されて fail することを確認
- [x] **プローブの Feature テスト**: 認証済み → `authenticated: true` / guest → `authenticated: false` /
      応答に `no-store, private` が付くこと / PII を含まないこと / **`$wrap = null` により
      `{ "authenticated": bool }` が top-level で返ること（完全一致）**
- [x] **exemption の Feature テスト（R3 Critical）**: **2FA 強制中 / recent-auth 期限切れ /
      組織未選択**の各状態で、プローブが**必ず 200 + boolean** を返すこと
- [x] **guard 分岐の vitest**（design-review R1 Warning）: `pageshow(persisted=true/false)`、
      **`documentElement` の秘匿属性あり/なし**、プローブ成功/失敗/エラー、再試行押下の各分岐を
      **ユニットテストで固定**する。E2E は統合挙動の確認に絞る（E2E 単体だと不安定なため）
- [x] **負のコントロール（vitest）**: 「**秘匿ロジックを外すと `pagehide` 後に
      `documentElement` の秘匿属性が付かない**」ことを先行して固定する。
      vitest では実描画の露出は検証できないため、**属性の有無**で責務を閉じる
      （実描画は E2E の責務。design-review R3 Suggestion）
- [x] `pnpm typecheck` / `pnpm lint` / `pnpm test` / `pnpm build` green
- [x] **bug-hunt は完了条件に含めない**（禁止事項 #1）

### リスク

| リスク | 対策 |
|---|---|
| 通常遷移でちらつく | `persisted` で絞る。E2E シナリオ 1（撮影画面からの通常遷移）が固定 |
| 撮影中の media stream が切れる | 秘匿を DOM 表示に限定し reload しない。E2E シナリオ 1 で確認 |
| 未ログアウト復元で状態が飛ぶ | **unhide のみ**にしたため飛ばない（R1 Critical の修正点）。E2E シナリオ 3 で固定 |
| プローブ失敗時に秘匿したまま操作不能になる | fail-secure だが**詰み**は避ける。秘匿オーバーレイに**再試行ボタン**を出す（禁止事項 #8 の精神: 押せない状態で放置しない）。**自動無限再試行は行わない**（design-review R2 Warning で状態遷移を一意化） |
| プローブが増えることでリクエストが増える | `pageshow(persisted)` 時のみ = 通常遷移では発火しない |

# 施策 7: サポート対象ブラウザ方針の明文化 (P3-c)

### 変更箇所

- `docs/supported-browsers.md` — **新規**
- `AGENTS.md` — ドメイン固有規約への参照追記

### 背景

**リポジトリ内にサポート対象ブラウザ方針が一切ない**
（`DESIGN.md` / `docs/*.md` / `package.json` の browserslist をいずれも確認、記載なし）。
施策 4・6 の保証範囲を語るために、まず方針が要る。

### 内容

| 項目 | 記載 |
|---|---|
| 対象ブラウザ | 撮影 PWA（iOS Safari / Android Chrome）と管理画面（デスクトップ Chrome / Firefox / Safari / Edge）を分けて定義 |
| **検証レベルの区分** | 下表 |

**`Current`（現行で実際に回っている検証）と `Target`（到達目標）を分離して書く**
（design-review R1 Warning: 「WebKit を含む」と「現状未導入」の同居は自己矛盾）。

> **本節はマージ後の実態を書く**（design-review R3 Warning）。施策 8 が WebKit レーンを
> **必須の実装完了条件**にしているため、この文書がマージされる時点で WebKit は導入済みである。
> 実装途中で未導入である状態は**本詳細設計書にのみ**残す。

#### Current（マージ後に実際に保証していること）

| 区分 | 対象 | 扱い |
|---|---|---|
| 自動回帰テスト（恒久） | **Chromium + WebKit**（Playwright） | 反復実行。**WebKit レーンが bfcache 復元シナリオの正本**。Chromium は `no-store` により bfcache 復元自体を再現できないため**部分検証**（秘匿属性付与・プローブ発火）に留まる |
| 実機受入確認（手動） | **iOS Safari 実機**（PWA standalone 含む） | **「恒久テスト済み」とは表現しない**。**日時・端末・OS バージョン・結果**を devnotes に記録する。**再確認条件**: `bfcache-guard.ts` / 秘匿スタイル / プローブ endpoint に変更が入ったら再実施する（一度きりではない。design-review R2 Suggestion） |

#### 未対応事項（誤読を防ぐため明示列挙する）

- **Chromium レーンは bfcache 復元そのものを再現していない**（`no-store` で evict されるため）。
  復元シナリオの正本は WebKit レーン
- **Playwright WebKit ≠ 実機 iOS Safari**。PWA standalone モードの差異は
  **実機受入確認でのみ**確認しており、自動回帰では担保されていない

> Playwright WebKit と実機 iOS Safari も同一ではない（bfcache 挙動・PWA standalone モード・
> iOS 固有の WebKit ビルド差）。WebKit レーン導入後も、前者の green を
> 「iOS Safari 対応を実証した」と言い換えない。

### テスト計画

- [x] 文書のため自動テストなし。ただし施策 11 の gate 群と同じく、
      **参照切れ**（`AGENTS.md` からのリンク先不在）を防ぐ

---

# 施策 8: P3 の Browser E2E 4 シナリオ

### 変更箇所

- `tests/Browser/AuthenticatedPageBfcacheTest.php` — **新規**

### 前提

- 既存 `tests/Browser/SmokeTest.php` があり、`scripts/run-browser-test.sh`（`composer test:browser`）で実行
- 実行前に `pnpm build` 済み + **`pnpm exec playwright install chromium webkit`** 済みが必要
  （`docs/testing-browser.md` を更新する）。
  **`composer test:browser` が Chromium / WebKit の両レーンを実行する契約**へ変更する
  （`scripts/run-browser-test.sh` の対応。design-review R4 Warning）

### 4 シナリオ（概念設計 Round 4 Warning への対応）

| # | シナリオ | 確認内容 |
|---|---|---|
| 1 | **撮影画面からの通常遷移** | 秘匿処理が**誤発火しない**こと。media stream / 未送信フォーム / Inertia 履歴が壊れないこと |
| 2 | **bfcache 復元（一般）** | 秘匿 → 検証 → 復帰の状態遷移が成立すること |
| 3 | **未ログアウトでの復元** | 表示が正しく**戻る**こと（= 誤検知しない） |
| 4 | **ログアウト後の復元** | **PII が出ない**こと（= 本来の目的） |

### 完了条件（design-review R1 Critical への対応）

**核心リスクは iOS Safari 系 bfcache であり、Chromium 主体では安全性を証明できない。**
Chromium は `no-store` のページを bfcache から evict するため、**シナリオ 4 がそもそも空振りする**。
したがって完了条件を次の優先順で定める。

| 区分 | 完了条件 | 内容 |
|---|---|---|
| **必須（実装完了条件）** | **WebKit レーンの追加** | `pnpm exec playwright install webkit` + `scripts/run-browser-test.sh` の対応。**恒久的な自動回帰**としてシナリオ 2・4 を成立させる |
| **補完（WebKit の代替ではない）** | iOS 実機受入確認 | **PWA standalone 差異**の確認。**日時・端末・OS バージョン・結果**を devnotes に記録する |
| 部分検証 | Chromium レーン | 「秘匿属性が `pagehide` で付く」「`pageshow` でプローブが走る」の確認。**これを全体の証明として扱わない** |

> **R1 からの変更（design-review R2 Critical）**: R1 では「WebKit が成立しなければ実機確認で完了」と
> したが、これは**セキュリティ不変条件を恒久的な自動回帰テストなしで完了扱いにできる**構造であり、
> 概念設計 Round 3 Critical（bug-hunt を完了条件にした誤り）と**同型の誤り**だった。
> **WebKit レーンを必須**とし、実機確認は**補完**に降格した。

**正のコントロール（design-review R2 Warning）**: 「WebKit なら再現できる見込み」では
成功条件にならない。**シナリオ 2・4 は `pageshow.persisted === true` を実際に観測できた場合のみ有効**とし、
**観測できなければテストを失敗させる**（空振りを green にしない）。

さらに、分岐ロジック自体は **vitest のユニットテストで固定**する（施策 6）。
E2E は統合挙動の確認に絞る（`pageshow(persisted)` 分岐は E2E 単体だと不安定なため）。

### fail-first の置き場所（design-review R2 Warning）

Chromium では施策 4 適用後に bfcache 復元が起きなくなるため、**シナリオ 4 の fail-first を
Chromium で再現できない**。したがって:

1. **WebKit レーンで fail-first を確認**する（第一）
2. 併せて **guard の vitest で「秘匿しなければ復元後に旧 DOM が可視のまま」という負のコントロール**を
   先行させ、秘匿ロジックの必要性をユニット層で先に固定する（施策 6）

### リスク

| リスク | 対策 |
|---|---|
| Chromium で bfcache 復元が再現できず**テストが空振りする** | 上記の完了条件で対応。**空振りテストを green として扱わない**（負のコントロールを必ず置き、「復元が起きていない」ことを検出できるようにする） |
| WebKit レーン追加で CI 実行時間が増える | 既存 SmokeTest と同じ排他レーンに乗せる。**実行時間を理由に WebKit を落とすことはしない**（落とすと恒久自動回帰が消えるため。R2 Critical） |
| Browser テストは実行が重く CI で不安定 | `run-browser-test.sh` が排他 + 並列上限を管理済み |

---


---

# 資料 2: 実装差分 (`git diff main...HEAD`)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index d58bc59..ad7a318 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -191,3 +191,13 @@ ## ドメイン固有規約
    (bytes_used + bytes_pending) 経由のみ。予約 (`take_upload_reservations`) の状態遷移は
    pending→verifying (claim)→completed/released の CAS で行い、直接 UPDATE を書かない。
    運用契約 (media queue worker / 孤児掃除 cron) は `docs/architecture.md` §撮影 PWA
+3. **サポート対象ブラウザと bfcache の扱い**: 「どのブラウザで何をどこまで保証しているか」の
+   正本は **`docs/supported-browsers.md`**。撮影 PWA の主戦場は iOS Safari であり、Safari は
+   `Cache-Control: no-store` でも bfcache に格納しうるため、認証済み画面は
+   サーバ側 no-store baseline (`NoStoreCacheHeadersForAuthenticatedPages`) と
+   クライアント側の bfcache 秘匿・再検証 (`resources/js/lib/bfcache-guard.ts` +
+   `session.status` プローブ) の **セット**で守る。
+   bfcache guard / 秘匿スタイル / プローブ endpoint に手を入れたら、
+   `docs/supported-browsers.md` の**実機受入確認の再確認条件**に従って再確認する。
+   Browser テストは **Chromium + WebKit の 2 レーン**が契約 (`docs/testing-browser.md`)。
+   実行時間を理由に WebKit レーンを落とさない (復元シナリオの恒久回帰が消えるため)
diff --git a/app/DataTransferObjects/Auth/SessionStatusDto.php b/app/DataTransferObjects/Auth/SessionStatusDto.php
new file mode 100644
index 0000000..87780c8
--- /dev/null
+++ b/app/DataTransferObjects/Auth/SessionStatusDto.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Auth;
+
+/**
+ * bfcache 秘匿・再検証 guard (resources/js/lib/bfcache-guard.ts) の軽量プローブ応答 DTO。
+ *
+ * セッションが「今も有効か」だけを伝える最小 DTO。recent-auth (step-up 鮮度) とは
+ * 意味が異なるため RecentAuthStatusDto を流用しない。PII / 権限 / 組織情報は載せない
+ * (bfcache 復元直後の未検証状態で叩かれる endpoint であり、露出面を最小に保つ)。
+ */
+final readonly class SessionStatusDto
+{
+    public function __construct(
+        public bool $authenticated,
+    ) {}
+}
diff --git a/app/Http/Controllers/Auth/SessionStatusController.php b/app/Http/Controllers/Auth/SessionStatusController.php
new file mode 100644
index 0000000..0ca7100
--- /dev/null
+++ b/app/Http/Controllers/Auth/SessionStatusController.php
@@ -0,0 +1,32 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Auth;
+
+use App\DataTransferObjects\Auth\SessionStatusDto;
+use App\Http\Controllers\Controller;
+use App\Http\Resources\Auth\SessionStatusResource;
+use Illuminate\Http\Request;
+
+/**
+ * セッション有効性の軽量プローブ (詳細設計 施策 6)。
+ *
+ * bfcache から復元された認証済み画面を「秘匿したまま」再検証するために、
+ * クライアント guard (resources/js/lib/bfcache-guard.ts) が pageshow 直後に叩く。
+ *
+ * auth グループの **外**に置く: auth 配下だと未認証時に 302/401 になり、guard 側で
+ * 「セッション無効」と「endpoint 不在 / ネットワーク障害」を区別しにくくなる。
+ * guest でも 200 + `authenticated: false` を返し、判定を明示 boolean 一本にする
+ * (認証状態は同一オリジンの呼び出し元が cookie で既に知りうる情報であり、
+ * これ自体は新たな情報露出にならない)。
+ */
+final class SessionStatusController extends Controller
+{
+    public function __invoke(Request $request): SessionStatusResource
+    {
+        return SessionStatusResource::make(
+            new SessionStatusDto(authenticated: $request->user() !== null),
+        );
+    }
+}
diff --git a/app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php b/app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php
new file mode 100644
index 0000000..253776b
--- /dev/null
+++ b/app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php
@@ -0,0 +1,69 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Middleware;
+
+use Closure;
+use Illuminate\Http\Request;
+use Symfony\Component\HttpFoundation\Response;
+
+/**
+ * 認証済みリクエストの web 応答に `no-store` を保証する baseline middleware。
+ *
+ * 目的: ログアウト後のブラウザ「戻る」で認証済み画面 (メンバー一覧等の PII) が
+ * bfcache から再表示されるのを防ぐ。`no-store` により Firefox は bfcache 格納自体を
+ * 拒否し、Chrome は cookie 変更 (= ログアウト) 時に CCNS ページを bfcache から
+ * eviction する。副次的に disk / proxy cache への認証済み応答残留も禁止される。
+ *
+ * **Safari は `no-store` でも bfcache に格納しうる**ため本 middleware だけでは
+ * 抑止できない。AI-CUE は撮影が PWA (iOS Safari が主要プラットフォーム) であるため、
+ * クライアント側の bfcache 秘匿・再検証 (resources/js/lib/bfcache-guard.ts) と
+ * **セットで** 主便益を達成する。対象ブラウザは docs/supported-browsers.md。
+ *
+ * 適用判定は route 列挙ではなく「認証済みか」で行う (path 列挙は一般認証画面を
+ * 取りこぼす)。guest / 公開ページ (login・LP・SEO) は対象外のままにし bfcache /
+ * 共有キャッシュの恩恵を維持する。認証済み画面は Inertia SPA でアプリ内の戻る/進むは
+ * client-side navigation のため bfcache 喪失による UX 後退はない。
+ */
+final class NoStoreCacheHeadersForAuthenticatedPages
+{
+    /**
+     * @param  Closure(Request): Response  $next
+     */
+    public function handle(Request $request, Closure $next): Response
+    {
+        // logout POST は $next 通過後に guard 上の user が null になるため、
+        // リクエスト時点の認証状態を先に捕捉する (= logout redirect も対象に含める)。
+        $wasAuthenticated = $this->isAuthenticated($request);
+
+        $response = $next($request);
+
+        // リクエスト時点 or 応答時点のどちらかで認証済みなら付与対象
+        // (login POST 応答 = 応答時点で認証済み、も保護側に倒す)。
+        if (! $wasAuthenticated && ! $this->isAuthenticated($request)) {
+            return $response;
+        }
+
+        // 既に no-store を持つ応答 (recent-auth 409 / 2FA 409 / 署名 URL redirect 等、
+        // 内側で明示されたより厳格な値) は書き換えず維持する。
+        // directive が縮む方向の上書きをしない。
+        if ($response->headers->hasCacheControlDirective('no-store')) {
+            return $response;
+        }
+
+        $response->headers->set('Cache-Control', 'no-store, private');
+
+        return $response;
+    }
+
+    /**
+     * 本 middleware の対象は session-backed な web 認証画面。session を持たない
+     * リクエスト (routes/web.php の stateless block: SEO/robots/公開ページは
+     * StartSession を withoutMiddleware 済) は stateless 公開配信であり対象外。
+     */
+    private function isAuthenticated(Request $request): bool
+    {
+        return $request->hasSession() && $request->user() !== null;
+    }
+}
diff --git a/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php b/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php
index 4df006d..0b31ec5 100644
--- a/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php
+++ b/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php
@@ -47,6 +47,10 @@ final class RequireTwoFactorForEnforcedOrganizations
         'two-factor.secret-key' => '手動入力キー表示 (設定ページの fetch)',
         'two-factor.recovery-codes' => 'リカバリコード表示 (設定完了直後の保存)',
         'two-factor.regenerate-recovery-codes' => 'リカバリコード再生成',
+        // 応答は { authenticated: bool } のみ (PII も操作も含まない) ため、ゲート中に
+        // 200 を返しても情報露出にならない。逆に遮断すると bfcache 復元後の guard が
+        // 「プローブ失敗」に倒れ、秘匿が解除できないまま再試行ループになる
+        'session.status' => 'bfcache 復元時のセッション有効性プローブ (秘匿解除の唯一の判定源)',
         'recent-auth.confirm' => '機微操作前の step-up 画面 (2FA 設定動線が要求し得る)',
         'recent-auth.status' => 'step-up 状態の確認 (XHR precheck)',
         'recent-auth.password' => 'password による step-up 完了',
diff --git a/app/Http/Resources/Auth/SessionStatusResource.php b/app/Http/Resources/Auth/SessionStatusResource.php
new file mode 100644
index 0000000..eb2dd71
--- /dev/null
+++ b/app/Http/Resources/Auth/SessionStatusResource.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Resources\Auth;
+
+use App\DataTransferObjects\Auth\SessionStatusDto;
+use Illuminate\Http\JsonResponse;
+use Illuminate\Http\Request;
+use Illuminate\Http\Resources\Json\JsonResource;
+
+/**
+ * セッション有効性プローブの XHR 応答 ({ authenticated })。
+ *
+ * top-level (data ラップなし) にするのは、クライアント guard が JSON shape を厳密判定
+ * するため (RecentAuthStatusResource と同じ作法)。
+ *
+ * `no-store, private` は controller ではなく本 Resource (withResponse) で付ける:
+ * 本 endpoint は **guest 応答も対象**であり、認証済み限定の baseline middleware
+ * (NoStoreCacheHeadersForAuthenticatedPages) では guest 分を取りこぼすため。
+ *
+ * @property-read SessionStatusDto $resource
+ */
+final class SessionStatusResource extends JsonResource
+{
+    /** @var string|null */
+    public static $wrap = null;
+
+    /**
+     * @return array{authenticated: bool}
+     */
+    public function toArray(Request $request): array
+    {
+        return [
+            'authenticated' => $this->resource->authenticated,
+        ];
+    }
+
+    public function withResponse(Request $request, JsonResponse $response): void
+    {
+        $response->headers->set('Cache-Control', 'no-store, private');
+    }
+}
diff --git a/app/Http/Routing/MembershipScopedOrganizationBinder.php b/app/Http/Routing/MembershipScopedOrganizationBinder.php
index 20253a5..ba0a3d6 100644
--- a/app/Http/Routing/MembershipScopedOrganizationBinder.php
+++ b/app/Http/Routing/MembershipScopedOrganizationBinder.php
@@ -32,8 +32,13 @@
  *
  * AppServiceProvider::boot の `Route::bind('organization', self::class)` から、Laravel の
  * class binding 規約 (RouteBinding::createClassBinding、既定メソッド名 `bind`) で呼ばれる。
+ *
+ * `{organization}` は RouteBindingTypes::CUSTOM_BINDER 分類 (= Route::pattern による宣言的
+ * 型制約を掛けられない。`{organization:slug}` を併用するため)。NormalizesRouteBindingInput は
+ * その分類を型で宣言する marker で、入力正規化の実効性の正本は Feature テスト
+ * (tests/Feature/Routing/RouteBindingTypeConstraintTest の異常系) である。
  */
-final class MembershipScopedOrganizationBinder
+final class MembershipScopedOrganizationBinder implements NormalizesRouteBindingInput
 {
     /**
      * binding field の allowlist。route 定義側の `{organization:xxx}` 誤指定を
diff --git a/app/Http/Routing/NormalizesRouteBindingInput.php b/app/Http/Routing/NormalizesRouteBindingInput.php
new file mode 100644
index 0000000..70b5eba
--- /dev/null
+++ b/app/Http/Routing/NormalizesRouteBindingInput.php
@@ -0,0 +1,18 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Routing;
+
+/**
+ * CUSTOM_BINDER 分類の宣言用 marker。
+ *
+ * この interface 自体は挙動を強制しない (空 interface のため空実装でも通る)。
+ * **入力正規化が実際に効いていることの正本は Feature テスト**
+ * (tests/Feature/Routing/RouteBindingTypeConstraintTest の {organization} 異常系) である。
+ *
+ * 本 interface の役割は「この param は Route::pattern による宣言的制約を適用できず
+ * ({organization} は {organization:slug} を併用するため)、binder が 22P02 / 22003 相当の
+ * 入力を弾く責務を負う」という分類を型で表明することに限られる。
+ */
+interface NormalizesRouteBindingInput {}
diff --git a/app/Http/Routing/RouteBindingTypes.php b/app/Http/Routing/RouteBindingTypes.php
new file mode 100644
index 0000000..d27eeae
--- /dev/null
+++ b/app/Http/Routing/RouteBindingTypes.php
@@ -0,0 +1,242 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Routing;
+
+use App\Models\AnalysisJob;
+use App\Models\ApiKey;
+use App\Models\Category;
+use App\Models\Cut;
+use App\Models\Item;
+use App\Models\OauthSession;
+use App\Models\OrganizationInvitation;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\Take;
+use App\Models\User;
+use App\Models\VideoManual;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Notifications\DatabaseNotification;
+
+/**
+ * route binding param の型 inventory (total inventory。分類漏れを禁止する)。
+ *
+ * 背景: pgsql は型不一致の比較で 22P02 (invalid input syntax) を投げるため、
+ * 非適合セグメント (/projects/abc) が implicit binding に届くと QueryException →
+ * **404 ではなく生 500** になる。AI-CUE はこのバグクラスを {notification} (whereUuid) と
+ * {organization} (binder の normalizeIntegerId) で個別に潰していたが系統化されておらず、
+ * bigint 11 param と uuid {oauthSession} が無防備だった (監査 2026-08-02)。
+ *
+ * 本 inventory は「全 binding param を **5 分類**のいずれかに登録する」ことを要求する
+ * 単一 source of truth であり、tests/Architecture/RouteBindingTypeConstraintInventoryTest が
+ * routes 定義と突合して**未登録 param の出現を fail** させる (deny-by-default)。
+ * 未知 param を数値と推測することはしない。
+ *
+ * 分類の意味:
+ *  - BIGINT:        $table->id() の PK。数値制約を Route::pattern で適用する
+ *  - UUID:          $table->uuid() / HasUuids の PK。UUID 制約を適用する
+ *  - CUSTOM_BINDER: Route::bind の explicit binder が入力正規化を担う。pattern は適用しない
+ *  - NON_MODEL:     モデル binding ではない文字列 param。型制約の対象外
+ *  - EXTERNAL:      外部 (vendor) route が持ち込む param。route identity ごとに登録する
+ */
+final class RouteBindingTypes
+{
+    /**
+     * bigint PK。Route::pattern で数値制約を適用する。
+     *
+     * **param 名 => 対応モデル**の map で持つ。StudlyCase からのクラス名推測は
+     * namespace や例外モデルで破綻するため、**対応モデル型の source of truth をここに置く**。
+     * IV-3 / IV-9 が共用する。
+     *
+     * @var array<string, class-string<Model>>
+     */
+    public const BIGINT = [
+        'analysisJob' => AnalysisJob::class,
+        'apiKey' => ApiKey::class,
+        'category' => Category::class,
+        'cut' => Cut::class,
+        'invitation' => OrganizationInvitation::class,
+        'item' => Item::class,
+        'manual' => VideoManual::class,
+        'project' => Project::class,
+        'renderJob' => RenderJob::class,
+        'take' => Take::class,
+        'user' => User::class,
+    ];
+
+    /**
+     * UUID PK。Route::pattern で UUID 制約を適用する。
+     *
+     * @var array<string, class-string<Model>>
+     */
+    public const UUID = [
+        'notification' => DatabaseNotification::class,
+        'oauthSession' => OauthSession::class,
+    ];
+
+    /**
+     * BIGINT / UUID 宣言のうち、**controller が implicit binding を使わず手動解決する** param。
+     *
+     * IV-9(a) の「action 引数が宣言モデル型であること」検査から**明示的に除外**する
+     * (action 引数は string になるため)。除外しても pattern の型制約 (IV-3 / IV-4) と
+     * 手動解決先の PK 型 (IV-9(c)) は引き続き検証されるため、22P02 / 22003 防御は落ちない。
+     *
+     * **除外は param ごとに理由を書いて登録する**(暗黙の素通しを作らない)。
+     *
+     * @var array<string, string> param 名 => 手動解決している理由
+     */
+    public const MANUALLY_RESOLVED = [
+        // NotificationController は $request->user()->notifications() 経由で解決する
+        // (他ユーザーの通知 id は「存在しない」と同じ 404 = 存在オラクル封じ)。
+        'notification' => 'cross-user 404 のため controller が $user->notifications() 経由で解決する',
+    ];
+
+    /**
+     * param ごとに**許可する binding field**。`{user:slug}` のように
+     * 非 PK field を指定されると Route::pattern の型制約と意味がずれるため、
+     * IV-9 が「field 未指定 (= routeKeyName) か、ここに列挙された field のみ」を要求する。
+     *
+     * 既定は**空 = field 指定を一切許さない** (PK 解決のみ)。
+     * 将来 `{manual:slug}` 等が必要になったら、その param を BIGINT/UUID から外すか
+     * ここへ明示登録する (= 型制約と両立するかを人間が判断する契機になる)。
+     *
+     * @var array<string, list<string>>
+     */
+    public const ALLOWED_BINDING_FIELDS = [];
+
+    /**
+     * explicit binder が入力正規化を担う param。**pattern は適用しない**。
+     * {organization} は {organization:slug} を併用するため数値制約を掛けると
+     * slug route が全滅する (概念設計 Round 1 Critical)。
+     *
+     * @var array<string, class-string>
+     */
+    public const CUSTOM_BINDER = ['organization' => MembershipScopedOrganizationBinder::class];
+
+    /**
+     * モデル binding ではない文字列 param。型制約の対象外。
+     *
+     * @var list<string>
+     */
+    public const NON_MODEL = [
+        'intent', 'provider', 'userId',
+    ];
+
+    /**
+     * 外部 (vendor) route が持ち込む param を **route identity ごと**に登録する inventory。
+     * **pattern は適用しない**。
+     *
+     * **param 名だけの list では同名衝突を検出できない**:
+     * vendor が非数値用途の `{user}` を追加しても `user` は既に BIGINT 登録済みなので
+     * 素通りし、global な数値 pattern が vendor route を破壊する。
+     * そこで **route identity => その route が持つ external param のリスト** で持ち、
+     * IV-7 が「EXTERNAL 宣言された param が BIGINT / UUID と同名でないこと」を
+     * **明示的に fail** させる。
+     *
+     * route identity には **route name を使う** (URI は prefix 設定で動くため不安定)。
+     * name 無し route が対象になる場合は `method:uri` の signature を使う
+     * (HTTP method は昇順ソートし、暗黙の HEAD は除外する)。
+     * **route identity の実在・params 完全一致・BIGINT/UUID との衝突は IV-7 が検証する**
+     * (IV-2 は param の逆方向検査であり別責務)。
+     *
+     * 登録手順 (**自動抽出はしない**): gate (IV-1) が未登録 param を
+     * route identity・action 付きで列挙するので、人間が用途を確認して 5 分類のいずれかへ登録する。
+     * 「route file 由来か」を機械判定しようとすると出自判定問題が再発するため、
+     * 外部 route の自動抽出は要件にしない。
+     *
+     * @var array<string, list<string>>
+     */
+    public const EXTERNAL = [
+        // Laravel MCP (vendor/laravel/mcp) の OAuth discovery。{path} は任意の後続セグメント
+        'mcp.oauth.authorization-server.nested' => ['path'],
+        'mcp.oauth.protected-resource.nested' => ['path'],
+        // Filament admin panel。{record} は Filament の resolveRouteBindingQuery 経由
+        'filament.admin.resources.admin-users.edit' => ['record'],
+        'filament.admin.resources.inquiries.edit' => ['record'],
+        'filament.admin.resources.model-audits.view' => ['record'],
+        'filament.admin.resources.organizations.view' => ['record'],
+        'filament.admin.resources.organizations.edit' => ['record'],
+        'filament.admin.resources.plans.edit' => ['record'],
+        'filament.admin.resources.users.view' => ['record'],
+        // Filament export / import (ULID 相当の id 文字列)
+        'filament.exports.download' => ['export'],
+        'filament.imports.failed-rows.download' => ['import'],
+        // Fortify のメール確認署名付き URL ({id} は user id・{hash} は email hash)
+        'verification.verify' => ['id', 'hash'],
+        // Fortify のパスワードリセット (署名トークン)
+        'password.reset' => ['token'],
+        // Cashier の SCA 確認画面 ({id} は Stripe PaymentIntent id)
+        'cashier.payment' => ['id'],
+        // Laravel の local storage 配信 route (FilesystemServiceProvider)
+        'storage.local' => ['path'],
+        'storage.local.upload' => ['path'],
+        // Livewire のファイルプレビュー / CSS・JS モジュール配信。
+        // css/js の 3 route は name を持たないため method:uri signature で登録する。
+        // uri prefix は APP_KEY 由来のハッシュ (EndpointResolver::prefix) で環境ごとに
+        // 変わるため、gate は prefix を `livewire/` へ正規化した identity で突合する。
+        'livewire.preview-file' => ['filename'],
+        'GET:livewire/css/{component}.css' => ['component'],
+        'GET:livewire/css/{component}.global.css' => ['component'],
+        'GET:livewire/js/{component}.js' => ['component'],
+    ];
+
+    /**
+     * bigint PK の route 制約。**18 桁上限**にすることで 2 種類の pgsql 例外を同時に塞ぐ。
+     *
+     *  - 非数値 (/projects/abc) → 22P02 invalid_text_representation
+     *  - 桁あふれ (/projects/<30桁>) → **22003 numeric_value_out_of_range**
+     *
+     * `[0-9]+` だと後者が regex を通過して DB へ到達し 500 になる。
+     * bigint / PHP_INT_MAX = 9223372036854775807 (**64bit PHP 前提**) は 19 桁なので、
+     * 18 桁の最大値 999999999999999999 は必ず範囲内 = **桁数だけで範囲内を保証できる**。
+     *
+     * **これはドメイン制約の導入である**: DB の bigint が許容する 19 桁 ID を意図的に排除し、
+     * 「AI-CUE の route key は最大 18 桁」と定める。実 ID が 10^18 に達することは無いため
+     * 運用上の制約にならないが、「適合値の挙動が不変」ではない点に注意
+     * (docs/architecture.md に記録。値自体を Architecture テストで pin する)。
+     *
+     * 先頭ゼロ ('007') は本 pattern にマッチするが pgsql は '007'::bigint を正常に解釈するため
+     * 500 にならない (該当行なしで 404)。canonical URL の要件は別問題なのでここでは制約しない。
+     */
+    public const BIGINT_PATTERN = '[0-9]{1,18}';
+
+    /** Laravel の UUID 制約 (whereUuid 相当)。 */
+    public const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
+
+    /**
+     * 登録済みの全 param 名 (gate が routes 定義と突合するために使う)。
+     *
+     * @return list<string>
+     */
+    public static function allRegistered(): array
+    {
+        return [
+            ...array_keys(self::BIGINT),
+            ...array_keys(self::UUID),
+            ...array_keys(self::CUSTOM_BINDER),
+            ...self::NON_MODEL,
+            ...self::externalParams(),
+        ];
+    }
+
+    /**
+     * EXTERNAL に宣言された全 param 名を平坦化する。
+     *
+     * `array_merge(...array_values(self::EXTERNAL) ?: [[]])` は PHPStan の推論が不安定なため
+     * 専用メソッドに切り出す (**型を緩めて回避しない**。禁止事項 #2)。
+     *
+     * @return list<string>
+     */
+    public static function externalParams(): array
+    {
+        $params = [];
+        foreach (self::EXTERNAL as $routeParams) {
+            foreach ($routeParams as $param) {
+                $params[] = $param;
+            }
+        }
+
+        return $params;
+    }
+}
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 2398c28..147f316 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -7,6 +7,7 @@
 use App\Auth\EncryptedUserProvider;
 use App\Auth\Guards\ApiKeyGuard;
 use App\Http\Routing\MembershipScopedOrganizationBinder;
+use App\Http\Routing\RouteBindingTypes;
 use App\Listeners\Audit\RejectNonCriticalAudit;
 use App\Listeners\Auth\StampRecentAuthOnLogin;
 use App\Listeners\Billing\MarkBillingNotificationDelivered;
@@ -153,6 +154,20 @@ public function boot(): void
         // tests/Architecture/OrganizationRouteParamWebOnlyInvariantTest が適用境界を pin)
         Route::bind('organization', MembershipScopedOrganizationBinder::class);
 
+        // route binding 型制約 (RouteBindingTypes が単一 SoT)。
+        // 非適合セグメントは route にマッチしない = 404 になり、SubstituteBindings へ
+        // 到達しないため pgsql 22P02 / 22003 (→ 生 500) が構造的に起きない。
+        // CUSTOM_BINDER (= {organization}) は binder 側が正規化するため pattern を適用しない
+        // ({organization:slug} を併用しており数値制約は掛けられない)。
+        // 分類の網羅と適用は tests/Architecture/RouteBindingTypeConstraintInventoryTest が
+        // deny-by-default で固定する。
+        foreach (array_keys(RouteBindingTypes::BIGINT) as $param) {
+            Route::pattern($param, RouteBindingTypes::BIGINT_PATTERN);
+        }
+        foreach (array_keys(RouteBindingTypes::UUID) as $param) {
+            Route::pattern($param, RouteBindingTypes::UUID_PATTERN);
+        }
+
         // パスワード強度ポリシーの SSOT は App\Support\PasswordPolicy (min12 + mixedCase +
         // numbers。HIBP 漏洩照合 uncompromised はテスト実行時のみ除外)。
         // 各 Action / FormRequest は Password::default() を参照し、min:8 等の散在を排除する
diff --git a/bootstrap/app.php b/bootstrap/app.php
index a380d04..2647d35 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -11,6 +11,7 @@
 use App\Http\Middleware\HandleInertiaRequests;
 use App\Http\Middleware\IdempotentRequest;
 use App\Http\Middleware\McpConsentOrganizationBinder;
+use App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages;
 use App\Http\Middleware\RedirectToHttps;
 use App\Http\Middleware\RequireActiveSubscription;
 use App\Http\Middleware\RequireApiKeyAbility;
@@ -87,6 +88,10 @@
             // 未準拠者の disable は (1) が先に弾く)
             RequireTwoFactorForEnforcedOrganizations::class,
             BlockTwoFactorDisableForEnforcedOrganizations::class,
+            // 認証済み応答の no-store baseline。
+            // 契約: $next から返った (= 下流の) 応答を確認し、既に `no-store` を持つなら変更しない。
+            // (位置関係ではなくこの契約が正本。実効性は Feature テストが固定する)
+            NoStoreCacheHeadersForAuthenticatedPages::class,
         ]);
 
         // パスワード変更/リセット時に他デバイスのセッション・remember-me を確実に失効させるため、
diff --git a/docs/architecture.md b/docs/architecture.md
index 4eaca58..37f86da 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -22,6 +22,32 @@ ## 層構造
 DataTransferObjects / Http/Resources (応答形の単一定義)
 ```
 
+## route binding の型制約 (ドメイン制約: route key は最大 18 桁)
+
+`app/Http/Routing/RouteBindingTypes` が **全 binding param の型 inventory (単一 SoT)**。
+`AppServiceProvider::boot` が inventory 駆動で `Route::pattern` を適用する
+(route 個別の `->whereNumber()` / `->whereUuid()` は書かない)。
+
+- **なぜ必要か**: pgsql は型不一致の比較で `22P02` (invalid_text_representation)、
+  bigint 範囲外で `22003` (numeric_value_out_of_range) を投げる。非適合セグメント
+  (`/projects/abc`) が implicit binding に届くと QueryException → **404 ではなく生 500**。
+  型制約に合致しない URL は route にマッチしない = 404 になり、`SubstituteBindings` へ
+  到達しないためクエリ自体が発行されない
+- **ドメイン制約 (重要)**: `BIGINT_PATTERN` は **`[0-9]{1,18}`**。
+  DB の bigint が許容する 19 桁 ID を**意図的に排除**し、
+  **「AI-CUE の route key は最大 18 桁」**と定める。`[0-9]+` だと桁あふれが regex を
+  通過して 22003 → 500 が残るため、**桁数だけで範囲内を保証する**
+  (PHP_INT_MAX = 9223372036854775807 は 19 桁 / 18 桁の最大値は必ず範囲内)。
+  実 ID が 10^18 に達することは無いため運用上の制約にならないが、
+  「適合値の挙動は不変」ではない点に注意。値自体は Architecture テストが pin する
+- **5 分類 (deny-by-default)**: `BIGINT` / `UUID` (param => モデルの map。pattern 適用) /
+  `CUSTOM_BINDER` (`{organization}`。`{organization:slug}` 併用のため pattern を適用せず
+  `MembershipScopedOrganizationBinder` が入力正規化を担う) / `NON_MODEL` / `EXTERNAL`
+  (vendor route が持ち込む param を route identity ごとに登録)。
+  未登録 param の出現は `RouteBindingTypeConstraintInventoryTest` が fail させる
+  (未知 param を数値と推測しない)。実挙動 (非適合 → 404) は
+  `tests/Feature/Routing/RouteBindingTypeConstraintTest` が pgsql 実接続で固定する
+
 ## ドメインモデル (テンプレート同梱)
 
 | Model | 役割 | tenancy |
diff --git a/docs/supported-browsers.md b/docs/supported-browsers.md
new file mode 100644
index 0000000..f667153
--- /dev/null
+++ b/docs/supported-browsers.md
@@ -0,0 +1,83 @@
+# サポート対象ブラウザ方針
+
+AI-CUE が「どのブラウザで、どのレベルまで動作を保証しているか」の正本。
+`no-store` baseline (`NoStoreCacheHeadersForAuthenticatedPages`) と bfcache 秘匿・再検証
+(`resources/js/lib/bfcache-guard.ts`) の**保証範囲を語るための前提**として置く。
+
+「対応している」という言葉を検証レベルと切り離さないこと。
+本書では **Current (実際に回っている検証)** と **Target (到達目標)** を分けて書く。
+
+## 対象ブラウザ
+
+撮影 PWA と管理画面はプラットフォーム前提が違うため分けて定義する。
+
+| 面 | URL 空間 | 主要ブラウザ |
+|----|----------|--------------|
+| **撮影 PWA** | `/app/*` (`manifest.webmanifest`, ホーム画面追加) | **iOS Safari** (standalone 含む) / Android Chrome |
+| **管理画面** | 上記以外 | デスクトップ Chrome / Edge / Firefox / Safari |
+
+撮影 PWA が中核 (使命 = 現場作業者がスマホで撮る) であり、**iOS Safari が最重要**。
+bfcache 周りの設計判断はすべてこの前提から来ている
+(Safari は `Cache-Control: no-store` のページでも bfcache に格納しうる)。
+
+## Current — マージ後に実際に保証していること
+
+| 区分 | 対象 | 扱い |
+|------|------|------|
+| **自動回帰テスト (恒久)** | **Chromium + WebKit** (Playwright / pest-plugin-browser) | `composer test:browser` が両レーンを実行する。カバーしているのは**秘匿の配線** (pagehide で秘匿属性が付き実描画が止まる / pageshow でプローブが走り秘匿が解ける) と**通常遷移で誤発火しないこと**。**bfcache 復元そのものは下記の理由でカバーできていない** |
+| **ユニット (vitest)** | `tests/js/lib/bfcache-guard.test.ts` | guard の分岐 (persisted 有無 / 秘匿属性 有無 / プローブ成功・失敗・エラー / 再試行) と負のコントロールを固定。**復元シナリオの分岐ロジックはここが唯一の恒久回帰** |
+| **実機受入確認 (手動)** | **iOS Safari 実機** (PWA standalone 含む) | **「恒久テスト済み」とは表現しない**。実施したら**日時・端末・OS バージョン・結果**を devnotes に記録する |
+
+レーンの実行方法・前提は `docs/testing-browser.md`。
+
+### bfcache 復元が自動回帰でカバーできていない理由 (実測)
+
+**Playwright は自動化インスペクタを接続した状態でブラウザを起動するため、Chromium /
+WebKit のどちらも「戻る」で bfcache 復元を行わない**。
+`Cache-Control: no-store` の付かない公開ページ間ですら、戻ると JS 実行コンテキストごと
+作り直される (= 通常の再取得) ことを実測している。
+
+そのため `tests/Browser/AuthenticatedPageBfcacheTest.php` のシナリオ 2〜4 は、
+**ハーネスの bfcache 再現能力を毎回実測**し、再現できない環境では理由付きで skip する。
+再現できる環境 (将来ツール側が対応した場合) では、
+`pageshow.persisted === true` を観測できなければ**失敗する**正のコントロールが効く。
+
+**skip は合格ではない**。現時点で復元シナリオを担保しているのは
+vitest のユニットテスト (分岐ロジック) と実機受入確認 (未実施) だけである。
+
+### 実機受入確認の再確認条件
+
+一度きりの確認では陳腐化する。**以下のいずれかに変更が入ったら再実施する**:
+
+- `resources/js/lib/bfcache-guard.ts` (bfcache guard 本体)
+- `resources/css/app.css` の秘匿オーバーレイのスタイル (`#bfcache-guard-overlay` 周辺)
+- プローブ endpoint (`routes/web.php` の `session.status` /
+  `App\Http\Controllers\Auth\SessionStatusController` / `SessionStatusResource`)
+
+記録先: `devnotes/<日付>-<topic>/` に日時・端末・iOS バージョン・実施シナリオ・結果を残す。
+**本書には「いつ・何を確認したか」を書かない** (記録の二重管理を作らない)。
+
+> 現時点でこのリポジトリに iOS 実機受入確認の記録はまだない。
+> **bfcache 復元後の実挙動 (PII が出ないこと) を実環境で確認できているものは無い**
+> — 自動回帰が復元を再現できない以上、実機確認は**補完ではなく現状唯一の実環境検証手段**である。
+
+## 未対応事項 (誤読を防ぐため明示列挙する)
+
+- **どちらのレーンも bfcache 復元そのものを再現していない** (上記「実測」節)。
+  Chromium は加えて、cookie 変更時に CCNS (`Cache-Control: no-store`) ページを
+  bfcache から evict する仕様でもある。
+- **Playwright WebKit ≠ 実機 iOS Safari**。bfcache 挙動・PWA standalone モード・
+  iOS 固有の WebKit ビルド差がある。WebKit レーンの green を
+  **「iOS Safari 対応を実証した」と言い換えない**。
+- **Firefox / Edge のブラウザ自動テストレーンは持たない** (Firefox は `no-store` で
+  bfcache 格納自体を拒否するため、本件のリスク面では最も安全側)。
+
+## Target — 到達目標 (未達)
+
+| 目標 | 現状 |
+|------|------|
+| **bfcache 復元シナリオの恒久自動回帰** (Playwright 側が bfcache を無効化しない構成、または別ハーネス) | **未達** — 現状は分岐ロジックの vitest のみ |
+| iOS Safari 実機での受入確認を**定期的に**回す (再確認条件のトリガ運用) | 未着手 |
+| Android Chrome 実機での撮影フロー確認 | 未着手 |
+
+Target を Current に格上げするときは、**何をどう検証したか**を Current の表に書いてから行う。
diff --git a/docs/testing-browser.md b/docs/testing-browser.md
index e37815e..8503330 100644
--- a/docs/testing-browser.md
+++ b/docs/testing-browser.md
@@ -20,16 +20,34 @@ ## なぜ in-process か
 ## 実行
 
 ```bash
-composer test:browser                  # 全 Browser テスト (既定 --processes=1 直列)
-composer test:browser -- --filter=...  # pest 引数の追加
+composer test:browser                  # 全 Browser テスト × 全レーン (既定 --processes=1 直列)
+composer test:browser -- --filter=...  # pest 引数の追加 (両レーンへ渡る)
 BROWSER_TEST_PROCESSES=3 composer test:browser  # 並列数の上書き
+BROWSER_TEST_LANES=webkit composer test:browser # レーン限定 (chromium / webkit)
 ```
 
 `composer test:browser` は `scripts/run-browser-test.sh` 経由で
 `vendor/bin/pest -c phpunit.browser.xml` を呼ぶ。`composer test` (Feature pgsql lane) と
 同一 lock file (`storage/framework/testing/test.lock`) の flock で相互排他し、
-共有する pgsql テスト DB / chromium 資源の奪い合いを防ぐ。並列数を未指定 (= nproc) に
-すると chromium の同時起動で環境がハングし得るため既定 1 に固定している。
+共有する pgsql テスト DB / ブラウザ資源の奪い合いを防ぐ。並列数を未指定 (= nproc) に
+すると同時起動で環境がハングし得るため既定 1 に固定している。
+
+### ブラウザレーン (Chromium + WebKit)
+
+`composer test:browser` は **Chromium レーン → WebKit レーンの順で 2 回** pest を実行し、
+**どちらかが失敗したら非ゼロで終わる** (先頭レーンの失敗で後続を飛ばさない)。
+pest-plugin-browser の `--browser chrome` / `--browser safari` (= Playwright webkit) に対応する。
+
+WebKit レーンは飾りではなく、iOS Safari (撮影 PWA の主戦場) に最も近い engine での回帰である。
+**実行時間を理由に WebKit レーンを落とさないこと**。
+
+ただし **bfcache 復元そのものはどちらのレーンでも再現できない** (実測):
+Playwright は自動化インスペクタを接続した状態でブラウザを起動するため、
+`no-store` の無い公開ページ間ですら「戻る」で復元されない。
+そのため `tests/Browser/AuthenticatedPageBfcacheTest.php` のシナリオ 2〜4 は
+**毎回ハーネスの再現能力を実測して skip** する (skip は合格ではなく「担保されていない」の表明)。
+再現できる環境では `pageshow.persisted === true` を観測できない限り**失敗する**
+正のコントロールが効く。保証範囲の全体像は `docs/supported-browsers.md`。
 
 pest 終了後に orphan 化した `playwright run-server` (node) はスクリプトが実行前後に掃除する。
 
@@ -38,8 +56,12 @@ ### 前提
 - **DB は Feature lane と同じ worktree 固有 pgsql テスト DB** (`<slug>_test_<worktree-hash>`)。
   `scripts/ci/ensure-test-db.php` が冪等に作成し、`tests/bootstrap.php` の単一点ガードが
   dev DB への接続を Laravel boot 前に fail-closed で拒否する (phpunit.xml と同一機構)。
-- chromium は Playwright が独自 DL する: `pnpm exec playwright install chromium`
-  (Linux で依存ライブラリも入れる場合は `--with-deps`)。system chromedriver は不要。
+- ブラウザは Playwright が独自 DL する: **`pnpm exec playwright install chromium webkit`**。
+  system chromedriver は不要。
+  **WebKit は Linux で共有ライブラリ群 (gstreamer / gtk-4 / libwoff2 等) を要求する**ため、
+  devcontainer では **`sudo pnpm exec playwright install-deps webkit`**
+  (または `playwright install --with-deps webkit`) を一度実行する。未導入だと WebKit レーンが
+  "Host system is missing dependencies to run browsers" で全 fail する。
 - 実ブラウザは `public/build` のビルド済アセットを読むため、UI 変更後は
   **`pnpm build` を先に実行する**こと (`withoutVite()` は Browser lane に適用されない)。
 
diff --git a/resources/css/app.css b/resources/css/app.css
index 580b8b1..aea8ea3 100644
--- a/resources/css/app.css
+++ b/resources/css/app.css
@@ -8,3 +8,69 @@
 @source '../**/*.svelte';
 
 /* DS tokens は tokens.css に集約。ここにはアプリ固有の animation 専用 token のみ置く。 */
+
+/* ===== bfcache 秘匿オーバーレイ (resources/js/lib/bfcache-guard.ts が制御) =====
+   documentElement の秘匿属性 (data-bfcache-hidden) が bfcache 復元マーカー兼スイッチ。
+   秘匿は「表示を止める」だけに限定し、DOM ツリー・media stream・未送信フォーム状態・
+   Inertia 履歴は一切壊さない (visibility は要素を残したまま描画だけ止める)。
+   色は DS token (tokens.css) 経由。hex は書かない。 */
+
+#bfcache-guard-overlay {
+    display: none;
+}
+
+html[data-bfcache-hidden] > body > *:not(#bfcache-guard-overlay) {
+    visibility: hidden;
+}
+
+html[data-bfcache-hidden] > body > #bfcache-guard-overlay {
+    visibility: visible;
+    display: flex;
+    position: fixed;
+    inset: 0;
+    z-index: 50;
+    align-items: center;
+    justify-content: center;
+    padding: 24px;
+    background-color: var(--color-neutral);
+    color: var(--color-text);
+}
+
+.bfcache-guard__panel {
+    display: flex;
+    flex-direction: column;
+    align-items: center;
+    gap: 16px;
+    max-width: 480px;
+    text-align: center;
+}
+
+/* 既定は「確認中」表示。失敗時のみ失敗文言 + 再試行ボタンへ切り替える
+   (押せない状態で放置しない = DESIGN.md の禁止事項 #8 の精神)。 */
+#bfcache-guard-overlay [data-bfcache-guard-failure],
+#bfcache-guard-overlay .bfcache-guard__retry {
+    display: none;
+}
+
+html[data-bfcache-hidden='retry'] #bfcache-guard-overlay [data-bfcache-guard-verifying] {
+    display: none;
+}
+
+html[data-bfcache-hidden='retry'] #bfcache-guard-overlay [data-bfcache-guard-failure] {
+    display: block;
+}
+
+html[data-bfcache-hidden='retry'] #bfcache-guard-overlay .bfcache-guard__retry {
+    display: inline-flex;
+    align-items: center;
+    justify-content: center;
+    min-height: 44px;
+    padding: 0 16px;
+    border-radius: var(--radius-md);
+    background-color: var(--color-primary);
+    color: var(--color-surface);
+}
+
+html[data-bfcache-hidden='retry'] #bfcache-guard-overlay .bfcache-guard__retry:hover {
+    background-color: var(--color-primary-hover);
+}
diff --git a/resources/js/app.ts b/resources/js/app.ts
index c3d35c0..4376616 100644
--- a/resources/js/app.ts
+++ b/resources/js/app.ts
@@ -1,7 +1,9 @@
-import { createInertiaApp } from "@inertiajs/svelte";
+import { createInertiaApp, page } from "@inertiajs/svelte";
 import { hydrate, mount } from "svelte";
 import { resolvePage } from "./inertia";
+import { registerBfcacheGuard } from "./lib/bfcache-guard";
 import { registerDocumentTitleSync } from "./lib/document-title";
+import { hasAuthenticatedUser } from "./lib/shared-props";
 
 // SPA 遷移後の document.title 陳腐化を解消する。Svelte adapter には createInertiaApp の
 // title callback が無いため、router.on('navigate') を購読してサーバ共有 prop `title` を
@@ -11,6 +13,16 @@ if (typeof document !== "undefined") {
     // HMR 二重登録防止: dev の hot reload で app.ts が再評価される際に前回の
     // router.on('navigate') 購読を解除する。本番ビルドでは import.meta.hot は undefined。
     import.meta.hot?.dispose(disposeTitleSync);
+
+    // bfcache 復元時の PII 再表示を塞ぐ (詳細設計 施策 6)。作動条件は Inertia 共有 props の
+    // auth.user (= 認証済みページのみ)。判定は登録時に固定せず pagehide のたびに評価する:
+    // login は Inertia の client-side 遷移で完了するため、「起動時 guest だった document が
+    // そのまま認証済み画面になる」経路があり、起動時 1 回の判定では取りこぼす。
+    // 公開ページ (LP / login / SEO) では秘匿もプローブも起こらない点は同じ。
+    const disposeBfcacheGuard = registerBfcacheGuard({
+        isAuthenticated: () => hasAuthenticatedUser(page.props),
+    });
+    import.meta.hot?.dispose(disposeBfcacheGuard);
 }
 
 createInertiaApp({
diff --git a/resources/js/lib/bfcache-guard.ts b/resources/js/lib/bfcache-guard.ts
new file mode 100644
index 0000000..19c0e04
--- /dev/null
+++ b/resources/js/lib/bfcache-guard.ts
@@ -0,0 +1,252 @@
+/**
+ * bfcache 秘匿・再検証 guard (詳細設計 施策 6 / P3-b)。
+ *
+ * 問題: Safari (撮影 PWA の主要プラットフォーム) は `Cache-Control: no-store` でも
+ * ページを bfcache に格納しうる。ログアウト後に「戻る」で認証済み画面が復元されると
+ * PII が再表示される。サーバ側の no-store baseline
+ * (NoStoreCacheHeadersForAuthenticatedPages) だけでは塞げない。
+ *
+ * 方針: 「復元後に検証」ではなく **検証完了まで復元内容を秘匿**する。
+ * 復元してから非同期検証すると、検証完了までの間 復元済みの古い DOM が表示され PII が
+ * 一瞬露出する (「無効なら遷移する」は「再表示しない」と同義ではない)。
+ *
+ * ただし hard reload は常用しない。撮影中の media stream・未送信フォーム・Inertia 履歴を
+ * 破棄してしまい、撮影 PWA という使命に直撃するため。有効なら **秘匿を外すだけ**にする。
+ *
+ * | # | 契機                | 動作                                                        |
+ * |---|---------------------|-------------------------------------------------------------|
+ * | 1 | pagehide            | documentElement に秘匿属性を同期付与 (この DOM ごと bfcache へ) |
+ * | 2 | pageshow (属性あり) | 秘匿のまま軽量プローブ (/session/status)                      |
+ * | 3 | セッション有効       | 秘匿属性を外すだけ (DOM / フォーム / Inertia 履歴は温存)       |
+ * | 4 | セッション無効       | login へ hard navigation (遷移先は固定の相対パス)             |
+ * | 5 | プローブ失敗         | 秘匿維持 + 再試行ボタン表示 (自動再試行はしない)              |
+ * | 6 | 再試行押下           | 現在 URL を hard reload (サーバに再判定させる)                |
+ *
+ * 復元マーカーは **documentElement の秘匿属性そのもの**。sessionStorage は使わない
+ * (タブ単位で共有されるため、ページ A の pagehide が立てたフラグを通常遷移先のページ B が
+ * 読んで誤って秘匿・プローブする)。属性なら bfcache 復元時だけ DOM ごと戻り、通常遷移では
+ * サーバから来た新しい HTML に存在しない = 本質的に履歴エントリ単位のマーカーになる。
+ *
+ * 秘匿は DOM 表示に限定する (属性付与 + CSS)。DOM ツリーの破棄・再構築はしない。
+ * 見た目 (オーバーレイ / 非表示) のスタイルは resources/css/app.css 側に置く (DS token 経由)。
+ */
+
+/** documentElement に付ける秘匿属性 = bfcache 復元マーカー兼 CSS スイッチ。 */
+export const BFCACHE_HIDDEN_ATTRIBUTE = "data-bfcache-hidden";
+
+/** 秘匿属性の値 (状態遷移を一意に表す)。 */
+export const BFCACHE_STATE_PENDING = "pending";
+export const BFCACHE_STATE_VERIFYING = "verifying";
+export const BFCACHE_STATE_RETRY = "retry";
+
+/** プローブ endpoint。サーバ側は routes/web.php の `session.status` (auth グループ外)。 */
+export const SESSION_STATUS_PATH = "/session/status";
+
+/** セッション無効時の遷移先。任意 URL は受け取らない (固定の相対パスのみ)。 */
+export const LOGIN_PATH = "/login";
+
+export const BFCACHE_OVERLAY_ID = "bfcache-guard-overlay";
+export const BFCACHE_RETRY_BUTTON_ID = "bfcache-guard-retry";
+
+/** プローブが必要とする最小 Response 契約 (テスト差替のため fetch 全体に依存しない)。 */
+export interface ProbeResponseLike {
+    ok: boolean;
+    headers: { get(name: string): string | null };
+    json(): Promise<unknown>;
+}
+
+export type ProbeFetch = (input: string, init: RequestInit) => Promise<ProbeResponseLike>;
+
+/** guard が使う window の最小契約 (jsdom は実 navigation を持たないため差替可能にする)。 */
+export interface GuardWindow {
+    addEventListener(type: string, listener: (event: Event) => void): void;
+    removeEventListener(type: string, listener: (event: Event) => void): void;
+    location: { replace(url: string): void; reload(): void };
+}
+
+export interface BfcacheGuardDeps {
+    doc?: Document;
+    win?: GuardWindow;
+    fetchImpl?: ProbeFetch;
+    /**
+     * 認証済みページか (Inertia 共有 props の `auth.user` を起点にする)。
+     * 公開ページ (LP / login / SEO) では秘匿もプローブも行わない。
+     */
+    isAuthenticated?: () => boolean;
+}
+
+/** プローブの判定結果。`failed` は「セッション無効」ではなく「判定不能」。 */
+export type SessionProbeOutcome = "authenticated" | "unauthenticated" | "failed";
+
+/** Content-Type の media type 判定 (charset 等のパラメータは許容する)。 */
+export function isJsonMediaType(contentType: string | null): boolean {
+    if (contentType === null) return false;
+    const mediaType = contentType.split(";")[0]?.trim().toLowerCase() ?? "";
+    return mediaType === "application/json";
+}
+
+/**
+ * プローブ応答の shape 厳密判定。top-level に boolean の `authenticated` を持つ
+ * plain object のみ受理する (data ラップ・型違いは判定不能として弾く)。
+ */
+export function readAuthenticatedFlag(payload: unknown): boolean | null {
+    if (typeof payload !== "object" || payload === null || Array.isArray(payload)) {
+        return null;
+    }
+    const value = (payload as Record<string, unknown>).authenticated;
+    return typeof value === "boolean" ? value : null;
+}
+
+/**
+ * セッション有効性を問い合わせる。
+ * (1) response.ok (2) Content-Type が JSON (3) JSON shape が厳密 — の全てを満たした時のみ
+ * 結果を採用し、1 つでも崩れたら `failed` (秘匿維持) に倒す。
+ */
+export async function probeSessionStatus(
+    fetchImpl: ProbeFetch,
+    url: string = SESSION_STATUS_PATH,
+): Promise<SessionProbeOutcome> {
+    try {
+        const response = await fetchImpl(url, {
+            credentials: "same-origin",
+            cache: "no-store",
+            headers: { Accept: "application/json" },
+        });
+
+        if (!response.ok) return "failed";
+        if (!isJsonMediaType(response.headers.get("Content-Type"))) return "failed";
+
+        const authenticated = readAuthenticatedFlag(await response.json());
+        if (authenticated === null) return "failed";
+
+        return authenticated ? "authenticated" : "unauthenticated";
+    } catch {
+        return "failed";
+    }
+}
+
+/**
+ * 秘匿オーバーレイを (無ければ) 生成する。Atomic Design 階層には component を足さない
+ * (app 起動時のグローバル要素 + CSS で完結させる = atoms/molecules の責務ではない)。
+ */
+function ensureOverlay(doc: Document): HTMLElement {
+    const existing = doc.getElementById(BFCACHE_OVERLAY_ID);
+    if (existing !== null) return existing;
+
+    const overlay = doc.createElement("div");
+    overlay.id = BFCACHE_OVERLAY_ID;
+    overlay.setAttribute("role", "status");
+    overlay.setAttribute("aria-live", "polite");
+    overlay.dataset.testid = BFCACHE_OVERLAY_ID;
+
+    const panel = doc.createElement("div");
+    panel.className = "bfcache-guard__panel";
+
+    const verifying = doc.createElement("p");
+    verifying.className = "text-body";
+    verifying.dataset.bfcacheGuardVerifying = "";
+    verifying.textContent = "セッションを確認しています…";
+
+    const failure = doc.createElement("p");
+    failure.className = "text-body";
+    failure.dataset.bfcacheGuardFailure = "";
+    failure.textContent =
+        "セッションを確認できませんでした。通信状況を確認して、もう一度お試しください。";
+
+    const retry = doc.createElement("button");
+    retry.type = "button";
+    retry.id = BFCACHE_RETRY_BUTTON_ID;
+    retry.className = "bfcache-guard__retry";
+    retry.dataset.testid = BFCACHE_RETRY_BUTTON_ID;
+    retry.textContent = "再試行";
+
+    panel.append(verifying, failure, retry);
+    overlay.append(panel);
+    doc.body.append(overlay);
+
+    return overlay;
+}
+
+function setHiddenState(doc: Document, state: string): void {
+    doc.documentElement.setAttribute(BFCACHE_HIDDEN_ATTRIBUTE, state);
+}
+
+function clearHiddenState(doc: Document): void {
+    doc.documentElement.removeAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
+}
+
+function isHidden(doc: Document): boolean {
+    return doc.documentElement.hasAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
+}
+
+/**
+ * pagehide 時に秘匿すべきか。`PageTransitionEvent.persisted` が使える環境では
+ * bfcache 対象 (persisted) のときだけ秘匿し、通常遷移のちらつきを避ける。
+ * 取得できない環境では安全側 (秘匿する) へ倒す
+ * (通常遷移では直後に新しい Document へ移るため実害はほぼ無い)。
+ */
+function shouldHideOnPageHide(event: Event): boolean {
+    const persisted: unknown = (event as PageTransitionEvent).persisted;
+    return typeof persisted === "boolean" ? persisted : true;
+}
+
+/**
+ * guard を登録し、購読解除 disposer を返す (HMR / テストの二重登録防止)。
+ *
+ * 秘匿・プローブは `isAuthenticated()` が true のページでのみ作動する
+ * (公開ページでは不要なちらつき・プローブを起こさない)。
+ */
+export function registerBfcacheGuard(deps: BfcacheGuardDeps = {}): () => void {
+    const doc = deps.doc ?? document;
+    const win = deps.win ?? window;
+    const fetchImpl: ProbeFetch =
+        deps.fetchImpl ?? ((input, init) => fetch(input, init) as Promise<ProbeResponseLike>);
+    const isAuthenticated = deps.isAuthenticated ?? (() => false);
+
+    const overlay = ensureOverlay(doc);
+    const retryButton = overlay.querySelector<HTMLButtonElement>(`#${BFCACHE_RETRY_BUTTON_ID}`);
+
+    const onRetry = (): void => {
+        // 自動再試行はしない。押下時に現在 URL を hard reload し、サーバに再判定させる。
+        win.location.reload();
+    };
+    retryButton?.addEventListener("click", onRetry);
+
+    const verify = async (): Promise<void> => {
+        setHiddenState(doc, BFCACHE_STATE_VERIFYING);
+
+        const outcome = await probeSessionStatus(fetchImpl, SESSION_STATUS_PATH);
+        if (outcome === "authenticated") {
+            clearHiddenState(doc);
+            return;
+        }
+        if (outcome === "unauthenticated") {
+            // 秘匿したまま login へ。replace で秘匿済み履歴エントリを残さない。
+            win.location.replace(LOGIN_PATH);
+            return;
+        }
+        setHiddenState(doc, BFCACHE_STATE_RETRY);
+    };
+
+    const onPageHide = (event: Event): void => {
+        if (!isAuthenticated()) return;
+        if (!shouldHideOnPageHide(event)) return;
+        setHiddenState(doc, BFCACHE_STATE_PENDING);
+    };
+
+    const onPageShow = (): void => {
+        // 復元マーカーは秘匿属性そのもの。通常ロードではサーバ由来の新しい HTML に
+        // 属性が無いため、ここで抜ける。
+        if (!isHidden(doc)) return;
+        void verify();
+    };
+
+    win.addEventListener("pagehide", onPageHide);
+    win.addEventListener("pageshow", onPageShow);
+
+    return () => {
+        win.removeEventListener("pagehide", onPageHide);
+        win.removeEventListener("pageshow", onPageShow);
+        retryButton?.removeEventListener("click", onRetry);
+    };
+}
diff --git a/resources/js/lib/shared-props.ts b/resources/js/lib/shared-props.ts
index 5922045..583b00f 100644
--- a/resources/js/lib/shared-props.ts
+++ b/resources/js/lib/shared-props.ts
@@ -38,6 +38,21 @@ export interface CurrentOrganization {
     canManageApiKeys: boolean;
 }
 
+/**
+ * 共有 props が認証済みユーザー (auth.user) を持つか。
+ *
+ * bfcache guard のように「認証済みページでのみ作動させたい」機構が、page.props を
+ * 直接掘らずに済むようにする単一判定点。型は backend (HandleInertiaRequests) が真実だが、
+ * 実行時は unknown として保守的に検査する。
+ */
+export function hasAuthenticatedUser(props: unknown): boolean {
+    if (typeof props !== "object" || props === null) return false;
+    const auth = (props as { auth?: unknown }).auth;
+    if (typeof auth !== "object" || auth === null) return false;
+    const user = (auth as { user?: unknown }).user;
+    return typeof user === "object" && user !== null;
+}
+
 export interface SharedProps {
     appName: string;
     auth: { user: AuthUser | null };
diff --git a/routes/api.php b/routes/api.php
index 23f20fd..0d76dc9 100644
--- a/routes/api.php
+++ b/routes/api.php
@@ -33,6 +33,8 @@
 | tests/Architecture/ApiGuardAllowlistInvariantTest が deny-by-default で固定する。
 | route 名規約: `api.v1.{resource}.{action}`。パラメータ付き route は
 | tests/Architecture/NestedRouteIdorDefenseTest の inventory に防御モードを登録する。
+| binding param の型制約 (旧 ->whereNumber) は App\Http\Routing\RouteBindingTypes に集約
+| (route 個別の where は書かない。18 桁上限で 22P02 / 22003 の両方を塞ぐ)。
 | MCP エンドポイント (/api/v1/mcp) は routes/ai.php で登録される。
 */
 
@@ -63,11 +65,9 @@
         Route::get('/projects', [ProjectController::class, 'index'])
             ->name('api.v1.projects.index');
         Route::get('/projects/{project}', [ProjectController::class, 'show'])
-            ->whereNumber('project')
             ->name('api.v1.projects.show');
 
         Route::get('/projects/{project}/items', [ItemController::class, 'index'])
-            ->whereNumber('project')
             ->name('api.v1.projects.items.index');
     });
 
@@ -76,14 +76,9 @@
     ->middleware(['auth:api-key,api-oauth', 'throttle:api-write', 'resolve.api-actor', 'api-key.ability:write', 'idempotent'])
     ->group(function (): void {
         Route::post('/projects/{project}/items', [ItemController::class, 'store'])
-            ->whereNumber('project')
             ->name('api.v1.projects.items.store');
         Route::patch('/projects/{project}/items/{item}', [ItemController::class, 'update'])
-            ->whereNumber('project')
-            ->whereNumber('item')
             ->name('api.v1.projects.items.update');
         Route::delete('/projects/{project}/items/{item}', [ItemController::class, 'destroy'])
-            ->whereNumber('project')
-            ->whereNumber('item')
             ->name('api.v1.projects.items.destroy');
     });
diff --git a/routes/web.php b/routes/web.php
index 8fa25ae..74347e8 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -4,6 +4,7 @@
 
 use App\Http\Controllers\Admin\UserManagementController;
 use App\Http\Controllers\Auth\ConfirmRecentAuthController;
+use App\Http\Controllers\Auth\SessionStatusController;
 use App\Http\Controllers\Auth\SocialAuthController;
 use App\Http\Controllers\Billing\BillingController;
 use App\Http\Controllers\Billing\TicketPurchaseController;
@@ -136,6 +137,18 @@
     Route::view('/commerce-disclosure', 'legal.commerce-disclosure')->name('legal.commerce-disclosure');
 });
 
+/*
+|--------------------------------------------------------------------------
+| セッション有効性の軽量プローブ (bfcache 秘匿・再検証)
+|--------------------------------------------------------------------------
+| auth グループの **外**に置く。未認証でも 200 + { authenticated: false } を返し、
+| クライアント guard (resources/js/lib/bfcache-guard.ts) が「セッション無効」と
+| 「endpoint 不在 / 通信障害」を明示 boolean で区別できるようにする。
+| 2FA 強制ゲートは RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES で
+| 明示的に免除している (免除しないと 2FA 強制中に秘匿が解除できず reload ループになる)。
+*/
+Route::get('/session/status', SessionStatusController::class)->name('session.status');
+
 /*
 |--------------------------------------------------------------------------
 | SSO (Socialite)
@@ -347,7 +360,8 @@
     | 受け皿として必要)。{notification} は implicit binding を使わず controller が
     | $request->user()->notifications() 経由で解決する (cross-user は構造的に 404 =
     | 存在オラクル封じ。1 param のため NestedRouteIdorDefenseTest の inventory 対象外)。
-    | whereUuid は不正形式 id を route 不一致 = 404 に落とす (pgsql uuid 比較の 22P02 防止)。
+    | 型制約は RouteBindingTypes に集約 ({notification} は UUID 分類 = Route::pattern で
+    | 不正形式 id を route 不一致 = 404 に落とす。pgsql uuid 比較の 22P02 防止)。
     | open は POST + 303 (GET にしない = prefetch による意図しない既読化防止)。
     */
     Route::get('/notifications', [NotificationController::class, 'index'])
@@ -355,10 +369,8 @@
     Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
         ->name('notifications.read-all');
     Route::post('/notifications/{notification}/open', [NotificationController::class, 'open'])
-        ->whereUuid('notification')
         ->name('notifications.open');
     Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
-        ->whereUuid('notification')
         ->name('notifications.read');
 
     /*
diff --git a/scripts/run-browser-test.sh b/scripts/run-browser-test.sh
index 3681f9a..2d98491 100755
--- a/scripts/run-browser-test.sh
+++ b/scripts/run-browser-test.sh
@@ -3,10 +3,16 @@
 # scripts/run-browser-test.sh — Browser テスト (pest-plugin-browser) を排他 + 並列上限付きで実行する。
 #
 # 背景:
-#   - pest-plugin-browser は in-process サーバ + Playwright (chromium) を起動する。
-#     `--parallel` のプロセス数を未指定 (= nproc) にすると chromium の同時起動で
-#     devcontainer がハングし得るため、既定 1 に固定する
-#     (上書きは BROWSER_TEST_PROCESSES=N)。
+#   - pest-plugin-browser は in-process サーバ + Playwright を起動する。
+#     `--parallel` のプロセス数を未指定 (= nproc) にするとブラウザの同時起動で
+#     devcontainer がハングし得るため、既定 1 = 直列に固定する
+#     (上書きは BROWSER_TEST_PROCESSES=N。2 以上でのみ parallel runner を使う。理由は下記)。
+#   - **Chromium / WebKit の 2 レーンを実行する契約**。bfcache 復元シナリオ
+#     (tests/Browser/AuthenticatedPageBfcacheTest.php) は Chromium では再現できず
+#     (no-store ページを bfcache から evict するため)、WebKit レーンが正本になる。
+#     実行時間を理由に WebKit を落とすことはしない (落とすと恒久自動回帰が消える)。
+#     レーンの意味と保証範囲は docs/supported-browsers.md。
+#     レーン限定実行は BROWSER_TEST_LANES="chromium" のように上書きする。
 #   - Browser lane は Feature lane と同じ worktree 固有 base テスト DB
 #     (<slug>_test_<worktree-hash>) と per-worker DB (_test_<token>) を使うため、
 #     composer test と同じ lock file (storage/framework/testing/test.lock) で
@@ -17,16 +23,18 @@
 #     呼び出し元を詰まらせる + プロセスを leak するため、実行前後に掃除する。
 #
 # 前提: pnpm build 済み (実ブラウザが public/build を読む) +
-#       `pnpm exec playwright install chromium` 済み。詳細は docs/testing-browser.md。
+#       `pnpm exec playwright install chromium webkit` 済み。詳細は docs/testing-browser.md。
 #
 # 使い方:
-#   composer test:browser                 # 全 Browser テスト
-#   composer test:browser -- --filter=... # pest 引数の追加
+#   composer test:browser                          # 全 Browser テスト (Chromium → WebKit)
+#   composer test:browser -- --filter=...          # pest 引数の追加 (両レーンへ渡る)
+#   BROWSER_TEST_LANES=webkit composer test:browser # レーン限定
 
 set -euo pipefail
 cd "$(dirname "$0")/.."
 
 PROCESSES="${BROWSER_TEST_PROCESSES:-1}"
+LANES="${BROWSER_TEST_LANES:-chromium webkit}"
 
 # composer test (scripts/run-test.sh) と同一 lock で相互排他する。
 # flock(1) が無い環境 (素の macOS 等) では排他なしで実行する (devcontainer/CI では排他あり)。
@@ -60,9 +68,45 @@ php artisan config:clear --ansi
 # DB 名の安全検証は tests/bootstrap.php の単一点ガードが担う (run-test.sh と同じ)。
 php scripts/ci/ensure-test-db.php
 
+# 既定 (PROCESSES=1) では pest の parallel runner を使わない。
+# 1 プロセスは直列と等価である一方、`--parallel --processes=1` で Browser lane を
+# 走らせると **全テスト成功でも終了コードが 1 になる** ケースを実測した
+# (pest-plugin-browser のページ操作を含むテストで再現。--processes=2 や parallel なしでは
+# 発生しない)。緑を赤と誤報告する = レーンの信頼性が失われるため、既定は直列にする。
+# 並列数を明示指定 (BROWSER_TEST_PROCESSES>=2) したときのみ parallel runner を使う。
+PEST_PARALLEL_ARGS=()
+if [ "${PROCESSES}" -gt 1 ]; then
+    PEST_PARALLEL_ARGS=(--parallel --processes="${PROCESSES}")
+fi
+
 # lock fd (9) を pest / playwright に継承させない。orphan run-server が fd を
 # 握ると lock が永久に解放されないため、実行コマンドへ渡す瞬間に 9>&- で閉じる
 # (親シェルの fd 9 = lock は保持されたまま)。
-code=0
-vendor/bin/pest -c phpunit.browser.xml --parallel --processes="${PROCESSES}" "$@" 9>&- || code=$?
-exit "${code}"
+#
+# レーンは順に実行し、**どれかが失敗したら最後に非ゼロで終わる**
+# (先頭レーンの失敗で後続レーンを飛ばすと WebKit の回帰を見落とすため)。
+overall=0
+for lane in ${LANES}; do
+    case "${lane}" in
+        chromium) browser="chrome" ;;
+        webkit)   browser="safari" ;;   # pest-plugin-browser の safari = Playwright webkit
+        *)
+            echo "ERROR: unknown browser lane '${lane}' (chromium / webkit)" >&2
+            exit 2
+            ;;
+    esac
+
+    echo ""
+    echo "=== Browser lane: ${lane} (playwright: ${browser}) ==="
+
+    code=0
+    vendor/bin/pest -c phpunit.browser.xml "${PEST_PARALLEL_ARGS[@]}" \
+        --browser "${browser}" "$@" 9>&- || code=$?
+    if [ "${code}" -ne 0 ]; then
+        overall="${code}"
+    fi
+
+    cleanup_orphan_playwright
+done
+
+exit "${overall}"
diff --git a/tests/Architecture/RouteBindingTypeConstraintInventoryTest.php b/tests/Architecture/RouteBindingTypeConstraintInventoryTest.php
new file mode 100644
index 0000000..cada080
--- /dev/null
+++ b/tests/Architecture/RouteBindingTypeConstraintInventoryTest.php
@@ -0,0 +1,614 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Routing\NormalizesRouteBindingInput;
+use App\Http\Routing\RouteBindingTypes;
+use App\Models\User;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Events\Dispatcher;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Facades\Route;
+use Tests\Support\Routing\SlugKeyedFixtureModel;
+
+/*
+|--------------------------------------------------------------------------
+| route binding param の型制約 total inventory gate (deny-by-default)
+|--------------------------------------------------------------------------
+|
+| 不変条件: **全 route (vendor 含む)** に現れる**全ての** binding param が
+| RouteBindingTypes の 5 分類のいずれかに登録され、分類に応じた制約を持つ。
+| 未登録 param の出現は fail させる (未知 param を数値と推測しない)。
+|
+| 守る事故: pgsql の型不一致 22P02 / 桁あふれ 22003 → QueryException →
+| **404 ではなく生 500**。
+| 実挙動 (非適合→404) と custom binder の入力正規化の実効性は
+| tests/Feature/Routing/RouteBindingTypeConstraintTest が担保する
+| (本 gate は分類の網羅と制約の適用のみを見る)。
+|
+| 各検査は「routes と inventory を引数で受ける純関数」として実装し、
+| **負のコントロールは実ファイルを書き換えず fixture route / fixture inventory** に
+| 対して同じ関数を走らせて検証する。
+*/
+
+/**
+ * route identity: name を第一とし、name 無し route は `method:uri` signature。
+ * HTTP method は昇順ソートし、暗黙の HEAD は除外する (GET 登録で自動付与されるため)。
+ *
+ * uri は **Livewire の endpoint prefix を正規化**してから使う。Livewire は
+ * `EndpointResolver::prefix()` で `/livewire-<APP_KEY 由来 8 桁ハッシュ>` を生成するため、
+ * 生の uri を identity にすると **APP_KEY ごとに inventory が壊れる**
+ * (dev と testing で別ハッシュになる)。正規化するのは prefix だけで、
+ * route の同一性 (path 構造 + method) は失わない。
+ */
+function routeBindingIdentity(RoutingRoute $route): string
+{
+    $name = $route->getName();
+    if (is_string($name) && $name !== '') {
+        return $name;
+    }
+
+    $methods = array_values(array_filter($route->methods(), static fn (string $m): bool => $m !== 'HEAD'));
+    sort($methods);
+
+    $uri = (string) preg_replace('#^livewire-[0-9a-f]{8}/#', 'livewire/', $route->uri());
+
+    return implode('|', $methods).':'.$uri;
+}
+
+/**
+ * アプリの全 route (vendor 含む)。
+ *
+ * @return list<RoutingRoute>
+ */
+function routeBindingAllRoutes(): array
+{
+    return array_values(Route::getRoutes()->getRoutes());
+}
+
+/**
+ * fixture 用の隔離 Router (実 route collection を汚さない)。
+ */
+function routeBindingFixtureRouter(): Router
+{
+    return new Router(new Dispatcher, app());
+}
+
+/**
+ * IV-1: 5 分類のいずれにも登録されていない param を列挙する。
+ *
+ * @param  list<RoutingRoute>  $routes
+ * @param  list<string>  $registered
+ * @return list<string> 違反メッセージ (param / route identity / action)
+ */
+function routeBindingUnregisteredParams(array $routes, array $registered): array
+{
+    $violations = [];
+
+    foreach ($routes as $route) {
+        foreach ($route->parameterNames() as $param) {
+            if (in_array($param, $registered, true)) {
+                continue;
+            }
+
+            $violations[] = sprintf(
+                '{%s} @ %s (action: %s)',
+                $param,
+                routeBindingIdentity($route),
+                $route->getActionName(),
+            );
+        }
+    }
+
+    return array_values(array_unique($violations));
+}
+
+/**
+ * IV-2: inventory に登録済みだが routes に現れない param (陳腐化した登録)。
+ *
+ * @param  list<RoutingRoute>  $routes
+ * @param  list<string>  $registered
+ * @return list<string>
+ */
+function routeBindingStaleRegistrations(array $routes, array $registered): array
+{
+    $inUse = [];
+    foreach ($routes as $route) {
+        foreach ($route->parameterNames() as $param) {
+            $inUse[$param] = true;
+        }
+    }
+
+    return array_values(array_filter(
+        array_unique($registered),
+        static fn (string $param): bool => ! isset($inUse[$param]),
+    ));
+}
+
+/**
+ * IV-3 / IV-4: 指定 param 群が全ての出現 route で期待 pattern を持つこと。
+ *
+ * `Route::pattern` は route 登録時に `$route->wheres` へ merge されるため、
+ * **実際に制約が効いているか**は wheres を見るのが正本 (登録順の事故も検出できる)。
+ *
+ * @param  list<RoutingRoute>  $routes
+ * @param  list<string>  $params
+ * @return list<string>
+ */
+function routeBindingMissingPatterns(array $routes, array $params, string $expectedPattern): array
+{
+    $violations = [];
+
+    foreach ($routes as $route) {
+        foreach ($route->parameterNames() as $param) {
+            if (! in_array($param, $params, true)) {
+                continue;
+            }
+
+            $actual = $route->wheres[$param] ?? null;
+            if ($actual === $expectedPattern) {
+                continue;
+            }
+
+            $violations[] = sprintf(
+                '{%s} @ %s: pattern=%s (expected %s)',
+                $param,
+                routeBindingIdentity($route),
+                $actual ?? '(none)',
+                $expectedPattern,
+            );
+        }
+    }
+
+    return array_values(array_unique($violations));
+}
+
+/**
+ * IV-5: CUSTOM_BINDER の param に pattern が適用されていないこと。
+ *
+ * @param  list<RoutingRoute>  $routes
+ * @param  list<string>  $params
+ * @return list<string>
+ */
+function routeBindingUnexpectedPatterns(array $routes, array $params): array
+{
+    $violations = [];
+
+    foreach ($routes as $route) {
+        foreach ($route->parameterNames() as $param) {
+            if (! in_array($param, $params, true)) {
+                continue;
+            }
+
+            $actual = $route->wheres[$param] ?? null;
+            if ($actual === null) {
+                continue;
+            }
+
+            $violations[] = sprintf('{%s} @ %s: pattern=%s', $param, routeBindingIdentity($route), $actual);
+        }
+    }
+
+    return array_values(array_unique($violations));
+}
+
+/**
+ * IV-7: EXTERNAL 宣言と実 route の突合。
+ *
+ * (a) route identity が実在する / (b) 登録 params と実 params が完全一致する /
+ * (c) 登録 param が BIGINT / UUID と同名でない (同名なら global pattern が外部 route を壊す)。
+ *
+ * @param  list<RoutingRoute>  $routes
+ * @param  array<string, list<string>>  $external
+ * @param  list<string>  $typedParams  BIGINT / UUID の param 名
+ * @return list<string>
+ */
+function routeBindingExternalViolations(array $routes, array $external, array $typedParams): array
+{
+    $byIdentity = [];
+    foreach ($routes as $route) {
+        $byIdentity[routeBindingIdentity($route)] = $route;
+    }
+
+    $violations = [];
+
+    foreach ($external as $identity => $params) {
+        $collisions = array_values(array_intersect($params, $typedParams));
+        if ($collisions !== []) {
+            $violations[] = sprintf(
+                'EXTERNAL[%s]: param %s が BIGINT/UUID と同名 (global pattern が外部 route を破壊する)。'
+                .'param 名を分離するか、当該 param を Route::pattern の適用対象から外して個別 where へ切り替えること',
+                $identity,
+                implode(', ', $collisions),
+            );
+
+            continue;
+        }
+
+        $route = $byIdentity[$identity] ?? null;
+        if (! $route instanceof RoutingRoute) {
+            $violations[] = sprintf('EXTERNAL[%s]: 該当 route が存在しない (陳腐化した登録)', $identity);
+
+            continue;
+        }
+
+        $actual = $route->parameterNames();
+        sort($actual);
+        $declared = $params;
+        sort($declared);
+
+        if ($actual !== $declared) {
+            $violations[] = sprintf(
+                'EXTERNAL[%s]: params 不一致 (declared: %s / actual: %s)',
+                $identity,
+                implode(', ', $declared),
+                implode(', ', $actual),
+            );
+        }
+    }
+
+    return $violations;
+}
+
+/**
+ * IV-9: binding 解決の一致。
+ *
+ * (a) action 引数が宣言モデル型であること (MANUALLY_RESOLVED は除外) /
+ * (b) binding field が未指定か ALLOWED_BINDING_FIELDS に列挙された field であること /
+ * (c) field 未指定なら宣言モデルの getRouteKeyName() が PK かつ型区分が宣言と一致すること。
+ *
+ * DB へは触れず、モデル metadata (getRouteKeyName / getKeyName / getKeyType /
+ * getIncrementing) だけで判定する。
+ *
+ * @param  list<RoutingRoute>  $routes
+ * @param  array<string, class-string<Model>>  $bigint
+ * @param  array<string, class-string<Model>>  $uuid
+ * @param  array<string, list<string>>  $allowedFields
+ * @param  array<string, string>  $manuallyResolved
+ * @return list<string>
+ */
+function routeBindingResolutionViolations(
+    array $routes,
+    array $bigint,
+    array $uuid,
+    array $allowedFields,
+    array $manuallyResolved,
+): array {
+    $violations = [];
+
+    foreach ($routes as $route) {
+        foreach ($route->parameterNames() as $param) {
+            $declared = $bigint[$param] ?? $uuid[$param] ?? null;
+            if ($declared === null) {
+                continue;
+            }
+
+            $identity = routeBindingIdentity($route);
+
+            // (a) action 引数の型
+            if (! array_key_exists($param, $manuallyResolved)) {
+                $signature = null;
+                foreach ($route->signatureParameters() as $parameter) {
+                    if ($parameter->getName() === $param) {
+                        $signature = $parameter;
+                        break;
+                    }
+                }
+
+                $type = $signature?->getType();
+                $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
+
+                if ($typeName === null || ! is_a($typeName, $declared, true)) {
+                    $violations[] = sprintf(
+                        'IV-9(a) {%s} @ %s: action 引数の型が %s でない (actual: %s)。'
+                        .'手動解決なら RouteBindingTypes::MANUALLY_RESOLVED に理由付きで登録すること',
+                        $param,
+                        $identity,
+                        $declared,
+                        $typeName ?? '(none)',
+                    );
+                }
+            }
+
+            // (b) binding field
+            $field = $route->bindingFieldFor($param);
+            if ($field !== null && ! in_array($field, $allowedFields[$param] ?? [], true)) {
+                $violations[] = sprintf(
+                    'IV-9(b) {%s:%s} @ %s: 非 PK field 指定は型制約と両立しない。'
+                    .'ALLOWED_BINDING_FIELDS へ登録するか、param を BIGINT/UUID から外すこと',
+                    $param,
+                    $field,
+                    $identity,
+                );
+
+                continue;
+            }
+
+            if ($field !== null) {
+                continue;
+            }
+
+            // (c) PK 解決であること + 型区分の一致 (DB 非依存の metadata のみ)
+            $model = new $declared;
+            if (! $model instanceof Model) {
+                $violations[] = sprintf('IV-9(c) {%s} @ %s: %s は Eloquent Model でない', $param, $identity, $declared);
+
+                continue;
+            }
+
+            if ($model->getRouteKeyName() !== $model->getKeyName()) {
+                $violations[] = sprintf(
+                    'IV-9(c) {%s} @ %s: %s の getRouteKeyName() が PK でない (%s)',
+                    $param,
+                    $identity,
+                    $declared,
+                    $model->getRouteKeyName(),
+                );
+
+                continue;
+            }
+
+            $isBigint = array_key_exists($param, $bigint);
+            $matches = $isBigint
+                ? ($model->getKeyType() === 'int' && $model->getIncrementing())
+                : ($model->getKeyType() === 'string' && ! $model->getIncrementing());
+
+            if (! $matches) {
+                $violations[] = sprintf(
+                    'IV-9(c) {%s} @ %s: %s の PK 型区分が宣言 (%s) と一致しない (keyType=%s / incrementing=%s)',
+                    $param,
+                    $identity,
+                    $declared,
+                    $isBigint ? 'BIGINT' : 'UUID',
+                    $model->getKeyType(),
+                    $model->getIncrementing() ? 'true' : 'false',
+                );
+            }
+        }
+    }
+
+    return array_values(array_unique($violations));
+}
+
+/*
+|--------------------------------------------------------------------------
+| IV-1 〜 IV-9 (本番 inventory に対する検証)
+|--------------------------------------------------------------------------
+*/
+
+it('IV-1: 全 route の binding param が 5 分類のいずれかに登録されている', function (): void {
+    $violations = routeBindingUnregisteredParams(
+        routeBindingAllRoutes(),
+        RouteBindingTypes::allRegistered(),
+    );
+
+    expect($violations)->toBe(
+        [],
+        '未登録の binding param がある。RouteBindingTypes の 5 分類 '
+        .'(BIGINT / UUID / CUSTOM_BINDER / NON_MODEL / EXTERNAL) のいずれかへ '
+        .'型・解決方式・除外理由を登録すること: '.implode(' / ', $violations),
+    );
+});
+
+it('IV-2: inventory に routes へ現れない param が残っていない', function (): void {
+    $violations = routeBindingStaleRegistrations(
+        routeBindingAllRoutes(),
+        RouteBindingTypes::allRegistered(),
+    );
+
+    expect($violations)->toBe(
+        [],
+        'routes に現れない param が inventory に残っている (陳腐化した登録は削除する): '
+        .implode(', ', $violations),
+    );
+});
+
+it('IV-3: BIGINT の全 param が数値制約を持つ', function (): void {
+    $violations = routeBindingMissingPatterns(
+        routeBindingAllRoutes(),
+        array_keys(RouteBindingTypes::BIGINT),
+        RouteBindingTypes::BIGINT_PATTERN,
+    );
+
+    expect($violations)->toBe([], 'bigint param に数値制約が掛かっていない: '.implode(' / ', $violations));
+});
+
+it('IV-4: UUID の全 param が UUID 制約を持つ', function (): void {
+    $violations = routeBindingMissingPatterns(
+        routeBindingAllRoutes(),
+        array_keys(RouteBindingTypes::UUID),
+        RouteBindingTypes::UUID_PATTERN,
+    );
+
+    expect($violations)->toBe([], 'uuid param に UUID 制約が掛かっていない: '.implode(' / ', $violations));
+});
+
+it('IV-5: CUSTOM_BINDER の binder が実在し分類を宣言している (かつ pattern 未適用)', function (): void {
+    foreach (RouteBindingTypes::CUSTOM_BINDER as $param => $binder) {
+        expect(class_exists($binder))->toBeTrue("{$param} の binder クラス {$binder} が存在しない");
+        expect(is_a($binder, NormalizesRouteBindingInput::class, true))->toBeTrue(
+            "{$binder} は NormalizesRouteBindingInput を実装して分類を宣言すること "
+            .'(入力正規化の実効性の正本は Feature テスト)',
+        );
+    }
+
+    $violations = routeBindingUnexpectedPatterns(
+        routeBindingAllRoutes(),
+        array_keys(RouteBindingTypes::CUSTOM_BINDER),
+    );
+
+    expect($violations)->toBe(
+        [],
+        'CUSTOM_BINDER の param に pattern を適用してはいけない ({organization:slug} が全滅する): '
+        .implode(' / ', $violations),
+    );
+});
+
+it('IV-6: 同一 param が複数分類に重複登録されていない', function (): void {
+    // EXTERNAL は route identity ごとの登録なので、同じ param が複数 route に現れるのは正常。
+    // 検査するのは**分類をまたいだ**重複 (どの分類の制約が効くのか曖昧になる)。
+    $byCategory = [
+        'BIGINT' => array_keys(RouteBindingTypes::BIGINT),
+        'UUID' => array_keys(RouteBindingTypes::UUID),
+        'CUSTOM_BINDER' => array_keys(RouteBindingTypes::CUSTOM_BINDER),
+        'NON_MODEL' => RouteBindingTypes::NON_MODEL,
+        'EXTERNAL' => array_values(array_unique(RouteBindingTypes::externalParams())),
+    ];
+
+    $duplicates = [];
+    foreach ($byCategory as $category => $params) {
+        foreach ($byCategory as $otherCategory => $otherParams) {
+            if ($category >= $otherCategory) {
+                continue;
+            }
+
+            foreach (array_intersect($params, $otherParams) as $param) {
+                $duplicates[] = "{$param} ({$category} / {$otherCategory})";
+            }
+        }
+    }
+
+    expect($duplicates)->toBe([], '複数分類に重複登録された param がある: '.implode(', ', $duplicates));
+});
+
+it('IV-7: EXTERNAL 宣言が実 route と一致し BIGINT/UUID と衝突しない', function (): void {
+    $violations = routeBindingExternalViolations(
+        routeBindingAllRoutes(),
+        RouteBindingTypes::EXTERNAL,
+        [...array_keys(RouteBindingTypes::BIGINT), ...array_keys(RouteBindingTypes::UUID)],
+    );
+
+    expect($violations)->toBe([], implode(' / ', $violations));
+});
+
+it('IV-8: BIGINT_PATTERN の値が固定されている (桁あふれ 22003 の再発防止)', function (): void {
+    // `[0-9]+` へ戻すと 19 桁以上が regex を通過して 22003 → 500 が復活する。
+    expect(RouteBindingTypes::BIGINT_PATTERN)->toBe('[0-9]{1,18}');
+});
+
+it('IV-9: BIGINT/UUID param の binding 解決が宣言と一致する', function (): void {
+    $violations = routeBindingResolutionViolations(
+        routeBindingAllRoutes(),
+        RouteBindingTypes::BIGINT,
+        RouteBindingTypes::UUID,
+        RouteBindingTypes::ALLOWED_BINDING_FIELDS,
+        RouteBindingTypes::MANUALLY_RESOLVED,
+    );
+
+    expect($violations)->toBe([], implode(' / ', $violations));
+});
+
+it('IV-9 補: MANUALLY_RESOLVED は BIGINT/UUID 宣言済み param にのみ理由付きで登録される', function (): void {
+    $typed = [...array_keys(RouteBindingTypes::BIGINT), ...array_keys(RouteBindingTypes::UUID)];
+
+    foreach (RouteBindingTypes::MANUALLY_RESOLVED as $param => $reason) {
+        expect(in_array($param, $typed, true))->toBeTrue("{$param} は BIGINT/UUID に宣言されていない");
+        expect(trim($reason))->not->toBe('', "{$param} の手動解決理由を記述すること");
+    }
+});
+
+/*
+|--------------------------------------------------------------------------
+| 負のコントロール (fixture route / fixture inventory に対して同じ関数を走らせる)
+|--------------------------------------------------------------------------
+*/
+
+it('負のコントロール IV-1: 未登録 param は fail する', function (): void {
+    $router = routeBindingFixtureRouter();
+    $router->get('/fixture/{gadget}', static fn (): string => 'ok');
+
+    $violations = routeBindingUnregisteredParams(
+        array_values($router->getRoutes()->getRoutes()),
+        RouteBindingTypes::allRegistered(),
+    );
+
+    expect($violations)->not->toBe([]);
+});
+
+it('負のコントロール IV-3: pattern 未適用なら fail する', function (): void {
+    $router = routeBindingFixtureRouter();
+    $router->get('/fixture/projects/{project}', static fn (): string => 'ok');
+
+    $violations = routeBindingMissingPatterns(
+        array_values($router->getRoutes()->getRoutes()),
+        ['project'],
+        RouteBindingTypes::BIGINT_PATTERN,
+    );
+
+    expect($violations)->not->toBe([]);
+});
+
+it('負のコントロール IV-7: BIGINT と同名の EXTERNAL 宣言は fail する', function (): void {
+    $violations = routeBindingExternalViolations(
+        routeBindingAllRoutes(),
+        ['vendor.some.route' => ['user']],
+        [...array_keys(RouteBindingTypes::BIGINT), ...array_keys(RouteBindingTypes::UUID)],
+    );
+
+    expect($violations)->not->toBe([]);
+});
+
+it('負のコントロール IV-7: 実在しない route identity の宣言は fail する', function (): void {
+    $violations = routeBindingExternalViolations(
+        routeBindingAllRoutes(),
+        ['vendor.route.that.does.not.exist' => ['token'],
+        ],
+        [...array_keys(RouteBindingTypes::BIGINT), ...array_keys(RouteBindingTypes::UUID)],
+    );
+
+    expect($violations)->not->toBe([]);
+});
+
+it('負のコントロール IV-9(a): モデル型 typehint 無しの {user} は fail する', function (): void {
+    $router = routeBindingFixtureRouter();
+    $router->get('/fixture/users/{user}', static fn (string $user): string => $user);
+
+    $violations = routeBindingResolutionViolations(
+        array_values($router->getRoutes()->getRoutes()),
+        RouteBindingTypes::BIGINT,
+        RouteBindingTypes::UUID,
+        RouteBindingTypes::ALLOWED_BINDING_FIELDS,
+        [],
+    );
+
+    expect($violations)->not->toBe([]);
+});
+
+it('負のコントロール IV-9(b): {user:slug} は fail する', function (): void {
+    $router = routeBindingFixtureRouter();
+    $router->get('/fixture/users/{user:slug}', static fn (User $user): string => (string) $user->id);
+
+    $violations = routeBindingResolutionViolations(
+        array_values($router->getRoutes()->getRoutes()),
+        RouteBindingTypes::BIGINT,
+        RouteBindingTypes::UUID,
+        RouteBindingTypes::ALLOWED_BINDING_FIELDS,
+        RouteBindingTypes::MANUALLY_RESOLVED,
+    );
+
+    expect($violations)->not->toBe([]);
+});
+
+it('負のコントロール IV-9(c): getRouteKeyName() が非 PK のモデルは fail する', function (): void {
+    $router = routeBindingFixtureRouter();
+    $router->get('/fixture/things/{thing}', static fn (SlugKeyedFixtureModel $thing): string => 'ok');
+
+    $violations = routeBindingResolutionViolations(
+        array_values($router->getRoutes()->getRoutes()),
+        ['thing' => SlugKeyedFixtureModel::class],
+        [],
+        [],
+        [],
+    );
+
+    expect($violations)->not->toBe([]);
+});
+
+it('負のコントロール IV-8: pattern 値を [0-9]+ に戻すと 18 桁上限が失われる', function (): void {
+    // IV-8 は定数値そのものの pin。等価性の負のコントロールとして
+    // 「[0-9]+ は pin 値と異なる」ことと「19 桁を通してしまう」ことの両方を示す。
+    expect('[0-9]+')->not->toBe(RouteBindingTypes::BIGINT_PATTERN);
+    expect((bool) preg_match('/^[0-9]+$/', '9223372036854775808'))->toBeTrue();
+    expect((bool) preg_match('/^'.RouteBindingTypes::BIGINT_PATTERN.'$/', '9223372036854775808'))->toBeFalse();
+});
diff --git a/tests/Browser/AuthenticatedPageBfcacheTest.php b/tests/Browser/AuthenticatedPageBfcacheTest.php
new file mode 100644
index 0000000..f1b38a1
--- /dev/null
+++ b/tests/Browser/AuthenticatedPageBfcacheTest.php
@@ -0,0 +1,375 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Project;
+use App\Models\User;
+use App\Models\VideoManual;
+use Pest\Browser\Api\PendingAwaitablePage;
+use Pest\Browser\Enums\BrowserType;
+use Pest\Browser\Playwright\Playwright;
+
+/*
+|--------------------------------------------------------------------------
+| bfcache 秘匿・再検証の Browser E2E (詳細設計 施策 8)
+|--------------------------------------------------------------------------
+|
+| 4 シナリオ:
+|   1. 撮影画面からの通常遷移   — 秘匿が誤発火しないこと (両レーン)
+|   2. bfcache 復元 (一般)      — 秘匿 → 検証 → 復帰の状態遷移 (WebKit レーンが正本)
+|   3. 未ログアウトでの復元      — 表示と未送信フォーム状態が正しく戻ること (同上)
+|   4. ログアウト後の復元        — PII が出ないこと = 本来の目的 (同上)
+|
+| レーンの位置づけ (docs/supported-browsers.md):
+|   - 復元シナリオ (2/3/4) の正本は **WebKit レーン**。Chromium は `no-store` ページを
+|     bfcache から evict するため、そもそも復元を再現できない。
+|   - **ただし実測結果**: Playwright は自動化インスペクタを接続した状態でブラウザを起動する
+|     ため、**Chromium / WebKit のどちらも bfcache 復元を行わない** (`no-store` の無い公開
+|     ページ間ですら復元されないことを実測)。復元シナリオはこのハーネスでは成立しない。
+|   - そのため 2/3/4 は **ハーネスの bfcache 再現能力を毎回実測**し、再現できない環境では
+|     skip する (= その環境では**自動回帰で担保されていない**ことを出力に明示する)。
+|     再現できる環境では下記の正のコントロールが厳格に効く。
+|
+| **正のコントロール**: シナリオ 2/3/4 は `pageshow.persisted === true` を実際に観測できた
+| 場合のみ有効。観測できなければテストを失敗させる (空振りを green にしない)。
+| 分岐ロジック自体の網羅は tests/js/lib/bfcache-guard.test.ts (vitest) が担う。
+|
+| 実行: composer test:browser (Chromium / WebKit の両レーン)。
+| 前提: pnpm build 済み + `pnpm exec playwright install chromium webkit` 済み。
+*/
+
+/** WebKit (Playwright の safari) レーンで走っているか。 */
+function bfcacheLaneIsWebKit(): bool
+{
+    return Playwright::defaultBrowserType() === BrowserType::SAFARI;
+}
+
+/**
+ * このハーネスで bfcache 復元が起きるかを**実測**する (プロセス内 1 回)。
+ *
+ * `no-store` の無い公開ページ間で「戻る」を行い、JS 実行コンテキストが生き残るか
+ * (= bfcache 復元か、通常の再取得か) を見る。ブラウザ種別の決め打ちにしないのは、
+ * 「このレーンなら再現できるはず」という**見込み**を成功条件にしないため。
+ */
+function bfcacheRestoreIsReproducible(): bool
+{
+    static $reproducible = null;
+
+    if ($reproducible === null) {
+        $page = visit('/pricing');
+        $page->script("window.__bfcacheHarnessProbe = 'alive'; true");
+        $page->navigate('/terms');
+        $page->back();
+        $reproducible = $page->script('window.__bfcacheHarnessProbe ?? null') === 'alive';
+    }
+
+    return $reproducible;
+}
+
+/**
+ * 復元シナリオの前提チェック。再現できない環境では **skip し、その旨を明示**する
+ * (green を装わない。skip は「担保されていない」の表明であり、合格ではない)。
+ */
+function bfcacheSkipUnlessRestoreIsReproducible(): void
+{
+    if (bfcacheRestoreIsReproducible()) {
+        return;
+    }
+
+    $lane = bfcacheLaneIsWebKit() ? 'webkit' : 'chromium';
+
+    test()->markTestSkipped(
+        "このハーネス (lane={$lane}) は bfcache 復元を再現できない "
+        .'(Playwright は自動化インスペクタ接続下でブラウザを起動するため、'
+        .'no-store の無い公開ページですら「戻る」で復元されないことを実測)。'
+        .'=> このシナリオは自動回帰で担保されていない。分岐ロジックは '
+        .'tests/js/lib/bfcache-guard.test.ts が、実機挙動は docs/supported-browsers.md の '
+        .'実機受入確認が受け持つ。',
+    );
+}
+
+/** @return array{User, Project} 撮影 PWA に到達できる owner + project */
+function bfcacheCaptureContext(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+
+    return [$owner, $project];
+}
+
+/**
+ * bfcache 復元の観測レコーダーを現在のページへ仕込む。
+ *
+ * bfcache 復元では JS の実行コンテキストごと復元されるため、この listener は
+ * 「本当に復元された場合にだけ」生き残って発火する = 正のコントロールになる
+ * (通常の再取得では listener ごと消えるためレコードは残らない)。
+ *
+ * 併せて **pageshow 時点の秘匿状態**を記録する。guard の pageshow ハンドラは同期的に
+ * 秘匿属性を立てた上で非同期プローブへ入るため、ここで `visibility: hidden` を観測できれば
+ * 「復元直後に PII が描画されていない」ことの実測になる。
+ */
+function bfcacheInstallRestoreRecorder(PendingAwaitablePage $page): void
+{
+    $page->script(<<<'JS'
+        (() => {
+            sessionStorage.removeItem('bfcache-e2e-record');
+            window.addEventListener('pageshow', (event) => {
+                if (!event.persisted) return;
+                const root = document.getElementById('app');
+                sessionStorage.setItem('bfcache-e2e-record', JSON.stringify({
+                    persisted: true,
+                    hiddenState: document.documentElement.getAttribute('data-bfcache-hidden'),
+                    appVisibility: root === null ? null : getComputedStyle(root).visibility,
+                }));
+            });
+            return true;
+        })()
+    JS);
+}
+
+/**
+ * レコーダーの観測結果を読む。観測できなければ **失敗させる** (空振りを green にしない)。
+ *
+ * @return array{persisted: bool, hiddenState: string|null, appVisibility: string|null}
+ */
+function bfcacheReadRestoreRecord(PendingAwaitablePage $page): array
+{
+    $raw = $page->script("sessionStorage.getItem('bfcache-e2e-record')");
+
+    expect($raw)->toBeString(
+        '正のコントロール失敗: pageshow.persisted === true を観測できなかった '
+        .'(= bfcache 復元が起きていない。このレーンではシナリオが空振りしている)',
+    );
+
+    /** @var array{persisted: bool, hiddenState: string|null, appVisibility: string|null} $record */
+    $record = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
+
+    expect($record['persisted'])->toBeTrue('正のコントロール失敗: persisted が true でない');
+
+    return $record;
+}
+
+/**
+ * ブラウザ側の条件が満たされるまで待つ (plugin の assertion は auto-retry しないため)。
+ * 各 script() 呼び出しが in-process サーバの event loop を回すので、待機中に
+ * プローブ (/session/status) の応答も進む。
+ */
+function bfcacheWaitUntil(PendingAwaitablePage $page, string $expression, string $message, int $attempts = 100): void
+{
+    for ($i = 0; $i < $attempts; $i++) {
+        if ($page->script("Boolean({$expression})") === true) {
+            expect(true)->toBeTrue();
+
+            return;
+        }
+        usleep(50_000);
+    }
+
+    throw new RuntimeException("条件が満たされませんでした: {$message} (式: {$expression})");
+}
+
+/** ブラウザ内からログアウトし、セッションが本当に無効化されたことを確認する。 */
+function bfcacheLogoutInBrowser(PendingAwaitablePage $page): void
+{
+    $authenticated = $page->script(<<<'JS'
+        (async () => {
+            const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
+            const token = match ? decodeURIComponent(match[1]) : '';
+            await fetch('/logout', {
+                method: 'POST',
+                credentials: 'same-origin',
+                headers: {
+                    'X-XSRF-TOKEN': token,
+                    'X-Requested-With': 'XMLHttpRequest',
+                    'Accept': 'application/json',
+                },
+            });
+            const status = await fetch('/session/status', {
+                credentials: 'same-origin',
+                cache: 'no-store',
+                headers: { 'Accept': 'application/json' },
+            }).then((response) => response.json());
+            return status.authenticated;
+        })()
+    JS);
+
+    expect($authenticated)->toBeFalse('前提条件失敗: ブラウザ側のログアウトでセッションが無効化されていない');
+}
+
+/*
+|--------------------------------------------------------------------------
+| シナリオ 1: 撮影画面からの通常遷移 (両レーン)
+|--------------------------------------------------------------------------
+*/
+
+test('撮影画面から通常遷移しても秘匿が誤発火しない', function (): void {
+    [$owner, $project] = bfcacheCaptureContext();
+    $this->actingAs($owner);
+
+    $page = visit("/app/projects/{$project->id}/manuals");
+    $page->assertSee('撮影するマニュアルを選ぶ');
+    // 未送信のフォーム入力 (撮影 PWA の作業途中状態)
+    $page->type('@capture-search', '未送信の入力');
+
+    // 通常遷移 (cross-document)
+    $page->navigate('/dashboard');
+
+    $page->assertPathIs('/dashboard')
+        ->assertScript('document.documentElement.hasAttribute("data-bfcache-hidden")', false)
+        ->assertMissing('@bfcache-guard-overlay')
+        ->assertNoJavaScriptErrors();
+
+    // 戻り操作でも詰まない (再取得なら属性なし / 復元なら プローブ → 秘匿解除)
+    $page->back();
+    bfcacheWaitUntil(
+        $page,
+        '!document.documentElement.hasAttribute("data-bfcache-hidden")',
+        '戻り操作後に秘匿が解除されない (詰み)',
+    );
+    $page->assertSee('撮影するマニュアルを選ぶ');
+});
+
+/*
+|--------------------------------------------------------------------------
+| 部分検証 (両レーン): 秘匿の配線 + プローブ発火を実ブラウザで確認する
+|--------------------------------------------------------------------------
+| bfcache 復元そのものはこのハーネスでは再現できないため、pagehide/pageshow を明示発火して
+| 「秘匿属性が付く」「実描画が止まる (visibility: hidden)」「プローブが走って秘匿が解ける」
+| の配線だけを実ブラウザで固定する (vitest では実描画を検証できない)。
+| **これを復元シナリオの証明として扱わない**。
+*/
+
+test('pagehide で実描画が止まり pageshow のプローブで復帰する (配線の部分検証)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $this->actingAs($owner);
+
+    $page = visit('/dashboard');
+    $page->assertSee($owner->name);
+
+    $hiddenVisibility = $page->script(<<<'JS'
+        (() => {
+            window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
+            const root = document.getElementById('app');
+            return {
+                state: document.documentElement.getAttribute('data-bfcache-hidden'),
+                appVisibility: getComputedStyle(root).visibility,
+                overlayVisible: getComputedStyle(document.getElementById('bfcache-guard-overlay')).display !== 'none',
+            };
+        })()
+    JS);
+
+    expect($hiddenVisibility)->toBe([
+        'state' => 'pending',
+        'appVisibility' => 'hidden',
+        'overlayVisible' => true,
+    ]);
+
+    // 秘匿属性が復元マーカー。pageshow でプローブが走り、有効なら秘匿だけ外れる
+    $page->script("window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }))");
+    bfcacheWaitUntil(
+        $page,
+        '!document.documentElement.hasAttribute("data-bfcache-hidden")',
+        'プローブ成功後に秘匿が解除されない',
+    );
+
+    $page->assertSee($owner->name)->assertNoJavaScriptErrors();
+});
+
+/*
+|--------------------------------------------------------------------------
+| シナリオ 2: bfcache 復元 (一般)
+|   復元を再現できないハーネスでは skip される (= 担保されていないことの明示)。
+|--------------------------------------------------------------------------
+*/
+
+test('bfcache 復元では秘匿 → 検証 → 復帰の順で状態遷移する', function (): void {
+    bfcacheSkipUnlessRestoreIsReproducible();
+
+    [, $owner] = createOrganizationWithOwner();
+    $this->actingAs($owner);
+
+    $page = visit('/dashboard');
+    $page->assertSee($owner->name);
+    bfcacheInstallRestoreRecorder($page);
+
+    $page->navigate('/pricing');
+    $page->back();
+
+    $record = bfcacheReadRestoreRecord($page);
+    expect($record['hiddenState'])->not->toBeNull('復元時に秘匿属性が付いていない');
+    expect($record['appVisibility'])->toBe('hidden', '復元直後に本文が描画されている (秘匿が効いていない)');
+
+    bfcacheWaitUntil(
+        $page,
+        '!document.documentElement.hasAttribute("data-bfcache-hidden")',
+        'セッション有効なのに秘匿が解除されない',
+    );
+    $page->assertPathIs('/dashboard')->assertSee($owner->name);
+});
+
+/*
+|--------------------------------------------------------------------------
+| シナリオ 3: 未ログアウトでの復元 — 表示と未送信フォーム状態が戻る
+|--------------------------------------------------------------------------
+*/
+
+test('未ログアウトでの復元では表示も未送信フォーム状態も壊れない', function (): void {
+    bfcacheSkipUnlessRestoreIsReproducible();
+
+    [$owner, $project] = bfcacheCaptureContext();
+    $this->actingAs($owner);
+
+    $page = visit("/app/projects/{$project->id}/manuals");
+    $page->assertSee('撮影するマニュアルを選ぶ');
+    $page->type('@capture-search', '未送信メモ');
+    bfcacheInstallRestoreRecorder($page);
+
+    $page->navigate('/pricing');
+    $page->back();
+
+    bfcacheReadRestoreRecord($page);
+
+    bfcacheWaitUntil(
+        $page,
+        '!document.documentElement.hasAttribute("data-bfcache-hidden")',
+        'セッション有効なのに秘匿が解除されない (誤検知)',
+    );
+
+    // unhide のみ = DOM も未送信入力も破棄していない (hard reload なら消える)
+    expect($page->value('@capture-search'))->toBe('未送信メモ');
+    $page->assertSee('撮影するマニュアルを選ぶ')->assertNoJavaScriptErrors();
+});
+
+/*
+|--------------------------------------------------------------------------
+| シナリオ 4: ログアウト後の復元 — PII を出さない (本来の目的)
+|--------------------------------------------------------------------------
+*/
+
+test('ログアウト後の復元では PII を再表示せず login へ倒す', function (): void {
+    bfcacheSkipUnlessRestoreIsReproducible();
+
+    [, $owner] = createOrganizationWithOwner();
+    $this->actingAs($owner);
+
+    $page = visit('/dashboard');
+    $page->assertSee($owner->name);
+    bfcacheInstallRestoreRecorder($page);
+
+    $page->navigate('/pricing');
+    bfcacheLogoutInBrowser($page);
+
+    $page->back();
+
+    $record = bfcacheReadRestoreRecord($page);
+    expect($record['hiddenState'])->not->toBeNull('ログアウト後の復元で秘匿属性が付いていない');
+    expect($record['appVisibility'])->toBe('hidden', 'ログアウト後の復元で PII が描画されている');
+
+    bfcacheWaitUntil(
+        $page,
+        "window.location.pathname === '/login'",
+        'セッション無効なのに login へ倒れない',
+    );
+    $page->assertDontSee($owner->name);
+});
diff --git a/tests/Feature/Auth/SessionStatusProbeTest.php b/tests/Feature/Auth/SessionStatusProbeTest.php
new file mode 100644
index 0000000..0015034
--- /dev/null
+++ b/tests/Feature/Auth/SessionStatusProbeTest.php
@@ -0,0 +1,111 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
+use App\Models\User;
+
+/*
+ * bfcache 秘匿・再検証 (詳細設計 施策 6) の軽量プローブ endpoint。
+ *
+ * 契約:
+ *   - auth グループの外。guest でも 200 + { "authenticated": false } (top-level / $wrap = null)。
+ *     ステータスコードではなく明示 boolean を見せることで、クライアント guard が
+ *     「セッション無効」と「endpoint 不在 / エラー」を取り違えないようにする。
+ *   - 応答は `{ "authenticated": bool }` のみ = PII を一切含まない。
+ *   - `no-store, private` を Resource 側 (withResponse) で付与する (guest 応答も対象のため
+ *     認証済み限定の baseline middleware には委ねない)。
+ *   - 2FA 強制中 / recent-auth 期限切れ / 組織未選択でも必ず 200 + boolean。
+ *     ここが崩れると guard は「プローブ失敗」に倒れ、秘匿解除されないまま reload ループになる。
+ */
+
+test('guest でも 200 で authenticated:false を返す', function (): void {
+    $this->get('/session/status')
+        ->assertOk()
+        ->assertExactJson(['authenticated' => false]);
+});
+
+test('認証済みは 200 で authenticated:true を返す (top-level / data ラップなし)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/session/status')
+        ->assertOk()
+        ->assertExactJson(['authenticated' => true]);
+});
+
+test('応答に no-store と private が付く', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($owner)->get('/session/status');
+
+    $cacheControl = (string) $response->headers->get('Cache-Control');
+    expect($response->headers->hasCacheControlDirective('no-store'))
+        ->toBeTrue("認証済みプローブ応答に no-store が無い (実際: {$cacheControl})");
+    expect($response->headers->hasCacheControlDirective('private'))
+        ->toBeTrue("認証済みプローブ応答に private が無い (実際: {$cacheControl})");
+});
+
+test('guest 応答にも no-store と private が付く (baseline middleware は認証済み限定のため)', function (): void {
+    $response = $this->get('/session/status');
+
+    $cacheControl = (string) $response->headers->get('Cache-Control');
+    expect($response->headers->hasCacheControlDirective('no-store'))
+        ->toBeTrue("guest プローブ応答に no-store が無い (実際: {$cacheControl})");
+    expect($response->headers->hasCacheControlDirective('private'))
+        ->toBeTrue("guest プローブ応答に private が無い (実際: {$cacheControl})");
+});
+
+test('応答に PII (email / name) を含まない', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $body = $this->actingAs($owner)->get('/session/status')->getContent();
+
+    expect($body)->toBeString()
+        ->and($body)->not->toContain($owner->email)
+        ->and($body)->not->toContain($owner->name);
+});
+
+test('2FA 強制中の未準拠ユーザーでも 200 + boolean を返す (ゲートで遮断されない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill(['two_factor_required' => true])->save();
+    // owner は 2FA 未設定 (= 未準拠) のまま
+
+    $this->actingAs($owner)->get('/session/status')
+        ->assertOk()
+        ->assertExactJson(['authenticated' => true]);
+});
+
+test('プローブ route は 2FA ゲートの allowlist に理由付きで登録されている', function (): void {
+    expect(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES)
+        ->toHaveKey('session.status');
+    expect(trim(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES['session.status']))
+        ->not->toBe('');
+});
+
+test('recent-auth の鮮度が切れていても 200 + boolean を返す', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    // recent_auth_at 未設定 (= step-up 未実施 / 期限切れ相当) の session
+    $this->actingAs($owner)
+        ->withSession(['recent_auth_at' => now()->subDay()->timestamp])
+        ->get('/session/status')
+        ->assertOk()
+        ->assertExactJson(['authenticated' => true]);
+});
+
+test('組織未選択 (current_organization_id が null) でも 200 + boolean を返す', function (): void {
+    $user = User::factory()->create();
+    expect($user->current_organization_id)->toBeNull();
+
+    $this->actingAs($user)->get('/session/status')
+        ->assertOk()
+        ->assertExactJson(['authenticated' => true]);
+});
+
+test('メール未検証ユーザーでも 200 + boolean を返す (verified ゲート外)', function (): void {
+    $user = User::factory()->unverified()->create();
+
+    $this->actingAs($user)->get('/session/status')
+        ->assertOk()
+        ->assertExactJson(['authenticated' => true]);
+});
diff --git a/tests/Feature/Routing/RouteBindingTypeConstraintTest.php b/tests/Feature/Routing/RouteBindingTypeConstraintTest.php
new file mode 100644
index 0000000..e02fe5f
--- /dev/null
+++ b/tests/Feature/Routing/RouteBindingTypeConstraintTest.php
@@ -0,0 +1,194 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Cut;
+use App\Models\OauthSession;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\VideoManual;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Route;
+
+/*
+|--------------------------------------------------------------------------
+| route binding 型制約の実挙動 (非適合セグメント → 404 / 500 でない)
+|--------------------------------------------------------------------------
+|
+| pgsql は型不一致の比較で 22P02 (invalid_text_representation)、bigint 範囲外で
+| 22003 (numeric_value_out_of_range) を投げるため、非適合セグメントが implicit
+| binding へ届くと QueryException → **404 ではなく生 500** になる。
+| RouteBindingTypes の型制約 (Route::pattern) がその手前 = route マッチ段階で
+| 弾いていることを実挙動で固定する。
+|
+| 各ケースは「非適合だけが 404 になる」ことを示すため、**適合値の対比ケース**を
+| 併記する (認証 / CSRF / 認可に吸われた 404 と区別するため)。
+*/
+
+/** 19 桁 = PHP_INT_MAX + 1 (bigint 範囲外)。 */
+const BIGINT_OVERFLOW = '9223372036854775808';
+
+/** 30 桁の極長数値 (regex が [0-9]+ だと通過して 22003 → 500 になる)。 */
+const BIGINT_TOO_LONG = '123456789012345678901234567890';
+
+/** 18 桁上限値 (制約が過剰に狭くないことの確認用)。 */
+const BIGINT_MAX_18 = '999999999999999999';
+
+test('テスト環境の DB driver は pgsql である (22P02 / 22003 を再現できる方言)', function (): void {
+    // 本件は pgsql 固有の事故。SQLite 等へ切り替わると非適合値でも例外にならず
+    // テストが空振りで green になるため、方言そのものを固定する。
+    expect(DB::connection()->getDriverName())->toBe('pgsql');
+});
+
+/*
+| ケース 1〜5: bigint param ({project})
+*/
+
+test('ケース 1: bigint param に非数値 → 404 (500 でない)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/projects/abc')->assertNotFound();
+});
+
+test("ケース 1': 対比 — 実在 ID は 200 (認可 / 課金ゲートに吸われていない)", function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)->get("/projects/{$project->id}")->assertOk();
+});
+
+test('ケース 2: bigint param に 19 桁 (PHP_INT_MAX+1) → 404 (22003 由来の 500 でない)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/projects/'.BIGINT_OVERFLOW)->assertNotFound();
+});
+
+test('ケース 3: bigint param に 30 桁 → 404', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/projects/'.BIGINT_TOO_LONG)->assertNotFound();
+});
+
+test('ケース 4: bigint param に 18 桁上限値 → 404 (route にはマッチする = 制約が過剰に狭くない)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/projects/'.BIGINT_MAX_18)->assertNotFound();
+});
+
+test('ケース 5: bigint param に先頭ゼロ → 404 (pgsql は 007 を正常解釈するため 500 にならない)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/projects/007')->assertNotFound();
+});
+
+/*
+| ケース 6: uuid param ({oauthSession})
+*/
+
+test('ケース 6: uuid param に非適合値 → 404 (500 でない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('セッション組織');
+
+    $this->actingAs($owner)->withSession(freshRecentAuthSession())
+        ->delete("/organizations/{$organization->slug}/api-keys/sessions/abc")
+        ->assertNotFound();
+});
+
+test("ケース 6': 対比 — 実在 UUID は 302 (認可 / recent-auth に吸われていない)", function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('セッション組織');
+    /** @var OauthSession $session */
+    $session = OauthSession::factory()->cli()->create([
+        'user_id' => $owner->id,
+        'organization_id' => $organization->id,
+    ]);
+
+    $this->actingAs($owner)->withSession(freshRecentAuthSession())
+        ->delete("/organizations/{$organization->slug}/api-keys/sessions/{$session->id}")
+        ->assertStatus(302);
+});
+
+/*
+| ケース 7: 全 bigint param の代表 route
+*/
+
+test('ケース 7: 全 bigint param の代表 route が非数値セグメントを 404 にする', function (
+    string $method,
+    string $urlTemplate,
+): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    /** @var VideoManual $manual */
+    $manual = VideoManual::factory()->forProject($project)->create();
+    /** @var Cut $cut */
+    $cut = Cut::factory()->forManual($manual)->create();
+
+    $url = strtr($urlTemplate, [
+        '{project}' => (string) $project->id,
+        '{manual}' => (string) $manual->id,
+        '{cut}' => (string) $cut->id,
+        '{organizationSlug}' => $organization->slug,
+    ]);
+
+    $this->actingAs($owner)->withSession(freshRecentAuthSession())
+        ->call($method, $url)
+        ->assertNotFound();
+})->with([
+    'analysisJob' => ['GET', '/projects/{project}/manuals/{manual}/jobs/abc'],
+    'apiKey' => ['DELETE', '/organizations/{organizationSlug}/api-keys/abc'],
+    'category' => ['PATCH', '/projects/{project}/categories/abc'],
+    'cut' => ['POST', '/app/projects/{project}/manuals/{manual}/cuts/abc/takes'],
+    'invitation' => ['DELETE', '/organizations/{organizationSlug}/invitations/abc'],
+    'item' => ['PATCH', '/projects/{project}/items/abc'],
+    'manual' => ['GET', '/projects/{project}/manuals/abc'],
+    'project' => ['GET', '/projects/abc'],
+    'renderJob' => ['GET', '/projects/{project}/manuals/{manual}/render-jobs/abc'],
+    'take' => ['PATCH', '/app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/abc'],
+    'user' => ['PATCH', '/organizations/{organizationSlug}/members/abc'],
+]);
+
+/*
+| ケース 8・13: {organization:slug} の回帰確認
+*/
+
+test('ケース 8/13: {organization:slug} は実在 slug で 200 (数値制約を掛けていない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->get("/organizations/{$organization->slug}/settings")
+        ->assertOk();
+});
+
+/*
+| ケース 9〜12: custom binder ({organization}) の入力正規化 = 実効性の正本。
+| marker interface は分類の宣言に過ぎず何も保証しないため、ここで固定する。
+*/
+
+test('ケース 9: {organization} の id binding に非数値 → 404', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->post('/organizations/abc/switch')->assertNotFound();
+});
+
+test('ケース 10: {organization} の id binding に 19 桁 → 404', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->post('/organizations/'.BIGINT_OVERFLOW.'/switch')->assertNotFound();
+});
+
+test('ケース 11: {organization} の id binding に 30 桁 → 404', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->post('/organizations/'.BIGINT_TOO_LONG.'/switch')->assertNotFound();
+});
+
+test('ケース 12: {organization:未許可 field} は 404 (500 でない)', function (): void {
+    // 本番 inventory (IV-1 の走査対象) を汚さないため routes/ には置かず、
+    // binder の未許可 field フォールバックを検証する route をテスト内で登録する。
+    Route::middleware('web')->get(
+        '/__test__/organizations/{organization:uuid}',
+        static fn (Organization $organization): string => (string) $organization->id,
+    );
+
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/__test__/organizations/anything')->assertNotFound();
+});
diff --git a/tests/Feature/Security/ExistingNoStoreContractTest.php b/tests/Feature/Security/ExistingNoStoreContractTest.php
new file mode 100644
index 0000000..c9d3be3
--- /dev/null
+++ b/tests/Feature/Security/ExistingNoStoreContractTest.php
@@ -0,0 +1,159 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Cut;
+use App\Models\OrganizationInvitation;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Capture\TakeObjectStorage;
+use Carbon\CarbonImmutable;
+use Illuminate\Testing\TestResponse;
+
+/*
+ * 既存の no-store 4 経路の Cache-Control 完全値ピン (施策 5)。
+ *
+ * no-store の存在チェックだけでは `public, no-store` のような矛盾値を検出できないため、
+ * 各経路について (a) ヘッダの完全一致 と (b) directive 集合 (順序非依存) の 2 段で固定する。
+ * 2 つの assert を分離しているのは、失敗時に「順序だけ変わった」のか「意味が後退した」のかを
+ * 判別できるようにするため。
+ *
+ * これらは P3-a baseline (NoStoreCacheHeadersForAuthenticatedPages) の untouched 契約の
+ * 証明でもある: baseline 適用後もこれらの値は 1 文字も変わらない。
+ *
+ * 完全一致ピンは「意図的な強化」も落とす。落ちたら期待値を更新する運用でよい
+ * (落ちること自体が「契約が変わった」というシグナル)。
+ */
+
+/** @return list<string> Cache-Control の directive 集合 (順序非依存に正規化) */
+function existingNoStoreDirectiveSet(string $value): array
+{
+    if (trim($value) === '') {
+        return [];
+    }
+
+    $directives = array_map(
+        static fn (string $part): string => trim($part),
+        explode(',', $value),
+    );
+    sort($directives);
+
+    return array_values($directives);
+}
+
+/**
+ * Cache-Control を 2 段 (完全一致 / directive 集合) でピンする。
+ * assert は分離し、それぞれ固有メッセージを付ける。
+ */
+function pinCacheControl(TestResponse $response, string $expected, string $label): void
+{
+    $actual = (string) $response->headers->get('Cache-Control');
+
+    expect($actual)->toBe(
+        $expected,
+        "[{$label}] Cache-Control の完全値が変わった (順序変更 or 値の変化)。期待: {$expected} / 実際: {$actual}",
+    );
+
+    expect(existingNoStoreDirectiveSet($actual))->toBe(
+        existingNoStoreDirectiveSet($expected),
+        "[{$label}] Cache-Control の directive 集合が変わった (順序ではなく意味の変化)。期待: {$expected} / 実際: {$actual}",
+    );
+}
+
+/* ------------------------------------------------------- 1. Fortify 登録応答 (招待 email あり) */
+
+test('Fortify 登録応答 (招待 email あり) の Cache-Control をピンする', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    /** @var OrganizationInvitation $invitation */
+    [, $token] = OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->createWithPlainToken(['email' => 'pinned-invitee@example.com']);
+
+    $response = $this->withSession(['invitation_token' => $token])->get('/register');
+
+    $response->assertOk();
+    pinCacheControl($response, 'no-store, private', 'FortifyServiceProvider registerView (PII 含む)');
+});
+
+/* ------------------------------------------------------- 2. RequireRecentAuth の 409 */
+
+test('RequireRecentAuth の 409 の Cache-Control をピンする', function (): void {
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)->deleteJson('/settings/account');
+
+    $response->assertStatus(409);
+    pinCacheControl($response, 'no-store, private', 'RequireRecentAuth 409');
+});
+
+/* ------------------------------------------ 3. RequireTwoFactorForEnforcedOrganizations の 409 */
+
+test('RequireTwoFactorForEnforcedOrganizations の 409 の Cache-Control をピンする', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['two_factor_required' => true])->save();
+    // 2FA 未準拠 (disabled) メンバー = ゲート対象
+    $member = attachOrganizationMember($organization);
+
+    $response = $this->actingAs($member)->getJson('/dashboard');
+
+    $response->assertStatus(409);
+    pinCacheControl($response, 'no-store, private', 'RequireTwoFactorForEnforcedOrganizations 409');
+});
+
+/* --------------------------------------------------- 4. Capture\CaptureTakeController の 302 */
+
+test('CaptureTakeController playback の 302 の Cache-Control をピンする', function (): void {
+    // 署名 URL 生成は実 S3 に触れないよう fake storage で解決する
+    enableFakeStorage();
+
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create(['status' => 'ready']);
+
+    $response = $this->actingAs($owner)->get(
+        "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/{$take->id}/playback",
+    );
+
+    $response->assertStatus(302);
+    pinCacheControl($response, 'no-store, private', 'CaptureTakeController playback 302');
+});
+
+/* --------------------------- 5. GetFakeStorageObjectController の Range 応答 (baseline 影響確認) */
+
+test('fake storage の Range 応答は認証済みでも壊れない (206 + partial bytes)', function (): void {
+    enableFakeStorage();
+    $user = User::factory()->create();
+
+    $key = 'projects/1/manuals/2/cuts/3/takes/01ABCDEF.mp4';
+    $body = 'range-test-bytes-0123456789';
+    $checksum = base64_encode(hash('sha256', $body, true));
+
+    $storage = app(TakeObjectStorage::class);
+    $putUrl = $storage->presignUpload(
+        $key,
+        'video/mp4',
+        100,
+        $checksum,
+        CarbonImmutable::now()->addMinutes(30),
+    )->url;
+
+    $this->call('PUT', $putUrl, [], [], [], [
+        'CONTENT_TYPE' => 'video/mp4',
+        'HTTP_X_AMZ_CHECKSUM_SHA256' => $checksum,
+    ], $body)->assertNoContent();
+
+    $getUrl = $storage->temporaryPlaybackUrl($key);
+
+    $full = $this->actingAs($user)->get($getUrl);
+    $full->assertOk();
+    expect($full->streamedContent())->toBe($body);
+
+    $partial = $this->actingAs($user)->call('GET', $getUrl, [], [], [], ['HTTP_RANGE' => 'bytes=0-3']);
+    $partial->assertStatus(206);
+    expect($partial->streamedContent())->toBe(substr($body, 0, 4));
+    $partial->assertHeader('Content-Range', 'bytes 0-3/'.strlen($body));
+});
diff --git a/tests/Feature/Security/NoStoreCacheHeadersTest.php b/tests/Feature/Security/NoStoreCacheHeadersTest.php
new file mode 100644
index 0000000..1d58ea1
--- /dev/null
+++ b/tests/Feature/Security/NoStoreCacheHeadersTest.php
@@ -0,0 +1,142 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\User;
+use Illuminate\Support\Facades\Route;
+use Illuminate\Testing\TestResponse;
+
+/*
+ * NoStoreCacheHeadersForAuthenticatedPages (P3-a baseline) の契約検証。
+ *
+ * 契約:
+ *  - 認証済み (リクエスト時点 or 応答時点) の web 応答で、応答が `no-store` directive を
+ *    持たないなら Cache-Control を `no-store, private` で置換する。
+ *  - 既に `no-store` を持つ応答は untouched (既存 4 経路の完全値ピンは
+ *    ExistingNoStoreContractTest)。
+ *  - guest / 公開ページ / session を持たない stateless block は対象外。
+ *
+ * 目的はログアウト後の「戻る」で認証済み画面 (PII) が bfcache / HTTP キャッシュから
+ * 再表示されるのを防ぐこと。
+ */
+
+/** Cache-Control の directive 集合 (順序非依存) */
+function noStoreBaselineDirectives(TestResponse $response): array
+{
+    $value = (string) $response->headers->get('Cache-Control');
+
+    if (trim($value) === '') {
+        return [];
+    }
+
+    return array_map(
+        static fn (string $part): string => trim($part),
+        explode(',', $value),
+    );
+}
+
+function noStoreBaselineHasNoStore(TestResponse $response): bool
+{
+    return in_array('no-store', noStoreBaselineDirectives($response), true);
+}
+
+test('認証済み Inertia 応答には no-store, private が付く', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($owner)->get('/dashboard');
+
+    $response->assertOk();
+    $response->assertHeader('Cache-Control', 'no-store, private');
+});
+
+test('guest の公開ページ (LP) には付与されない', function (): void {
+    $response = $this->get('/');
+
+    $response->assertOk();
+    expect(noStoreBaselineHasNoStore($response))->toBeFalse(
+        'guest の公開ページは bfcache / 共有キャッシュの恩恵を維持するため対象外',
+    );
+});
+
+test('guest の login 画面には付与されない', function (): void {
+    $response = $this->get('/login');
+
+    $response->assertOk();
+    expect(noStoreBaselineHasNoStore($response))->toBeFalse(
+        'login 画面は guest 応答のため対象外',
+    );
+});
+
+test('stateless block (SEO/robots) には付与されない', function (): void {
+    $response = $this->get('/robots.txt');
+
+    $response->assertOk();
+    expect(noStoreBaselineHasNoStore($response))->toBeFalse(
+        'StartSession を外した stateless 公開配信は対象外 (hasSession() が false)',
+    );
+});
+
+test('logout POST の redirect 応答にも付与される (リクエスト時点の認証状態で判定)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($owner)->post('/logout');
+
+    // $next 通過後は guard 上の user が null になるため、リクエスト時点の捕捉が load-bearing
+    expect(noStoreBaselineHasNoStore($response))->toBeTrue(
+        'logout の redirect 応答が no-store を持たないと「戻る」で認証済み画面が復元されうる',
+    );
+    $response->assertHeader('Cache-Control', 'no-store, private');
+});
+
+test('login POST の応答にも付与される (応答時点の認証状態で判定)', function (): void {
+    User::factory()->create(['email' => 'nostore-login@example.com']);
+
+    $response = $this->post('/login', [
+        'email' => 'nostore-login@example.com',
+        'password' => 'password',
+    ]);
+
+    // リクエスト時点は guest。応答時点で認証済みになるため保護側に倒す
+    expect(noStoreBaselineHasNoStore($response))->toBeTrue(
+        'login 応答は応答時点で認証済みのため付与対象',
+    );
+    $response->assertHeader('Cache-Control', 'no-store, private');
+});
+
+test('認証済みで no-store を持たない応答は矛盾 directive ごと置換される', function (): void {
+    $user = User::factory()->create();
+
+    Route::middleware('web')->get('/__no-store-probe/cacheable', static fn () => response('ok')
+        ->withHeaders(['Cache-Control' => 'public, max-age=600']));
+
+    $response = $this->actingAs($user)->get('/__no-store-probe/cacheable');
+
+    $response->assertOk();
+    $response->assertHeader('Cache-Control', 'no-store, private');
+});
+
+test('認証済みでも既に no-store を持つ応答は書き換えない (untouched 契約)', function (): void {
+    $user = User::factory()->create();
+
+    Route::middleware('web')->get('/__no-store-probe/inner-no-store', static fn () => response('ok')
+        ->withHeaders(['Cache-Control' => 'no-store, max-age=0']));
+
+    $response = $this->actingAs($user)->get('/__no-store-probe/inner-no-store');
+
+    $response->assertOk();
+    // 置換していたら max-age=0 は消える。残存が untouched の証拠
+    expect(noStoreBaselineDirectives($response))->toContain('max-age=0');
+    expect(noStoreBaselineDirectives($response))->toContain('no-store');
+});
+
+test('guest は no-store を持たない応答でも置換されない', function (): void {
+    Route::middleware('web')->get('/__no-store-probe/guest-cacheable', static fn () => response('ok')
+        ->withHeaders(['Cache-Control' => 'public, max-age=600']));
+
+    $response = $this->get('/__no-store-probe/guest-cacheable');
+
+    $response->assertOk();
+    expect(noStoreBaselineHasNoStore($response))->toBeFalse(
+        'guest 応答は対象外 (認証状態でのみ判定する)',
+    );
+});
diff --git a/tests/Support/Routing/SlugKeyedFixtureModel.php b/tests/Support/Routing/SlugKeyedFixtureModel.php
new file mode 100644
index 0000000..98cb75c
--- /dev/null
+++ b/tests/Support/Routing/SlugKeyedFixtureModel.php
@@ -0,0 +1,24 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Routing;
+
+use Illuminate\Database\Eloquent\Model;
+
+/**
+ * IV-9(c) の負のコントロール用 fixture。
+ *
+ * `getRouteKeyName()` が PK でない列を返すモデル (= route key が bigint / uuid でない)。
+ * 本 fixture は DB に触れない (metadata 検査のみに使う)。実アプリのモデルを
+ * 一時的に書き換えずに「非 PK 解決を検出できること」を示すために置く。
+ */
+final class SlugKeyedFixtureModel extends Model
+{
+    protected $table = 'slug_keyed_fixtures';
+
+    public function getRouteKeyName(): string
+    {
+        return 'slug';
+    }
+}
diff --git a/tests/Unit/Routing/RouteBindingPatternTest.php b/tests/Unit/Routing/RouteBindingPatternTest.php
new file mode 100644
index 0000000..edf659e
--- /dev/null
+++ b/tests/Unit/Routing/RouteBindingPatternTest.php
@@ -0,0 +1,59 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Routing\RouteBindingTypes;
+
+/*
+|--------------------------------------------------------------------------
+| route binding pattern の regex 単体検証
+|--------------------------------------------------------------------------
+|
+| Feature テストでは 18 桁も 19 桁も最終結果が 404 で**区別できない**ため、
+| 「route にマッチしたか / しなかったか」の証明はこの層で行う。
+| BIGINT_PATTERN が 18 桁上限であることが 22003 (numeric_value_out_of_range) を
+| regex 段で塞ぐ唯一の根拠 (PHP_INT_MAX = 9223372036854775807 は 19 桁)。
+*/
+
+/** route pattern は route 全体に対する完全一致で評価される。 */
+function matchesRouteBindingPattern(string $pattern, string $value): bool
+{
+    return (bool) preg_match('/^(?:'.$pattern.')$/', $value);
+}
+
+test('BIGINT_PATTERN は 1〜18 桁の数値にマッチする', function (string $value): void {
+    expect(matchesRouteBindingPattern(RouteBindingTypes::BIGINT_PATTERN, $value))->toBeTrue();
+})->with([
+    '1 桁' => '1',
+    '先頭ゼロ (pgsql は正常解釈するため制約しない)' => '007',
+    '通常の ID' => '123456',
+    '18 桁上限値' => '999999999999999999',
+]);
+
+test('BIGINT_PATTERN は 19 桁以上・非数値にマッチしない', function (string $value): void {
+    expect(matchesRouteBindingPattern(RouteBindingTypes::BIGINT_PATTERN, $value))->toBeFalse();
+})->with([
+    '19 桁 (PHP_INT_MAX と同幅 = 22003 の危険域)' => '9223372036854775807',
+    '19 桁 (PHP_INT_MAX + 1)' => '9223372036854775808',
+    '30 桁' => '123456789012345678901234567890',
+    '非数値' => 'abc',
+    '数値混じり' => '12ab',
+    '負数' => '-1',
+    '空文字' => '',
+]);
+
+test('18 桁の最大値は bigint / PHP_INT_MAX の範囲内 (桁数だけで範囲内を保証できる)', function (): void {
+    expect(PHP_INT_MAX)->toBe(9223372036854775807)
+        ->and(999999999999999999 < PHP_INT_MAX)->toBeTrue()
+        ->and(strlen((string) PHP_INT_MAX))->toBe(19);
+});
+
+test('UUID_PATTERN は UUID にのみマッチする', function (string $value, bool $expected): void {
+    expect(matchesRouteBindingPattern(RouteBindingTypes::UUID_PATTERN, $value))->toBe($expected);
+})->with([
+    'v4 UUID' => ['9b7f2f1e-4b1a-4a2e-9b3c-2f8a1d5e6c7d', true],
+    '大文字 UUID' => ['9B7F2F1E-4B1A-4A2E-9B3C-2F8A1D5E6C7D', true],
+    'ハイフン無し' => ['9b7f2f1e4b1a4a2e9b3c2f8a1d5e6c7d', false],
+    '非適合文字列' => ['abc', false],
+    '数値' => ['12345', false],
+]);
diff --git a/tests/js/lib/bfcache-guard.test.ts b/tests/js/lib/bfcache-guard.test.ts
new file mode 100644
index 0000000..208b6d1
--- /dev/null
+++ b/tests/js/lib/bfcache-guard.test.ts
@@ -0,0 +1,326 @@
+/**
+ * Tests for resources/js/lib/bfcache-guard.ts
+ *
+ * 公開契約 (詳細設計 施策 6 の状態遷移表):
+ *   1. pagehide            → documentElement に秘匿属性を同期付与 (この DOM ごと bfcache に入る)
+ *   2. pageshow (属性あり) → 秘匿のまま軽量プローブ (/session/status)
+ *   3. セッション有効       → 秘匿属性を外すだけ (DOM / フォーム / Inertia 履歴は温存)
+ *   4. セッション無効       → login へ hard navigation
+ *   5. プローブ失敗         → 秘匿維持 + 再試行ボタン表示 (自動再試行しない)
+ *   6. 再試行押下           → 現在 URL を hard reload
+ *
+ * 復元マーカーは documentElement の秘匿属性そのもの (sessionStorage は使わない:
+ * タブ単位共有で別ページに漏れるため)。
+ *
+ * 負のコントロール: 「秘匿ロジックを外す (guard 未登録 / dispose 済み) と pagehide 後に
+ * 秘匿属性が付かない」。vitest では実描画の露出は検証できないため属性の有無で責務を閉じる
+ * (実描画は Browser E2E の責務)。
+ */
+import { beforeEach, describe, expect, it, vi } from "vitest";
+
+import {
+    BFCACHE_HIDDEN_ATTRIBUTE,
+    BFCACHE_OVERLAY_ID,
+    BFCACHE_RETRY_BUTTON_ID,
+    LOGIN_PATH,
+    SESSION_STATUS_PATH,
+    registerBfcacheGuard,
+    type GuardWindow,
+    type ProbeFetch,
+    type ProbeResponseLike,
+} from "@/lib/bfcache-guard";
+
+/** location を呼び出し記録可能にした最小 window スタブ (jsdom は実 navigation を持たない)。 */
+function createWindowStub(): {
+    win: GuardWindow;
+    dispatch(event: Event): boolean;
+    replace: ReturnType<typeof vi.fn>;
+    reload: ReturnType<typeof vi.fn>;
+} {
+    const target = new EventTarget();
+    const replace = vi.fn();
+    const reload = vi.fn();
+
+    return {
+        win: {
+            addEventListener: (type, listener) => target.addEventListener(type, listener),
+            removeEventListener: (type, listener) =>
+                target.removeEventListener(type, listener),
+            location: { replace, reload },
+        },
+        dispatch: (event) => target.dispatchEvent(event),
+        replace,
+        reload,
+    };
+}
+
+/** PageTransitionEvent 相当。persisted を省略すると「取得できない環境」を模す。 */
+function transitionEvent(type: "pagehide" | "pageshow", persisted?: boolean): Event {
+    const event = new Event(type);
+    if (persisted !== undefined) {
+        Object.defineProperty(event, "persisted", { value: persisted });
+    }
+    return event;
+}
+
+/** プローブ応答スタブ。 */
+function probeResponse(
+    body: unknown,
+    { ok = true, contentType = "application/json" }: { ok?: boolean; contentType?: string | null } = {},
+): ProbeResponseLike {
+    return {
+        ok,
+        headers: { get: (name) => (name.toLowerCase() === "content-type" ? contentType : null) },
+        json: () => Promise.resolve(body),
+    };
+}
+
+function hiddenAttribute(): string | null {
+    return document.documentElement.getAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
+}
+
+/** 非同期プローブ (fetch → json) の解決を待つ。 */
+async function flushProbe(): Promise<void> {
+    await Promise.resolve();
+    await Promise.resolve();
+    await Promise.resolve();
+    await Promise.resolve();
+}
+
+beforeEach(() => {
+    document.documentElement.removeAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
+    document.body.innerHTML = "";
+});
+
+describe("負のコントロール (秘匿ロジックが無いとき)", () => {
+    it("guard を登録していなければ pagehide で秘匿属性は付かない", () => {
+        const { dispatch } = createWindowStub();
+
+        dispatch(transitionEvent("pagehide", true));
+
+        expect(hiddenAttribute()).toBeNull();
+    });
+
+    it("dispose 後は pagehide で秘匿属性が付かない", () => {
+        const { win, dispatch } = createWindowStub();
+        const dispose = registerBfcacheGuard({ win, isAuthenticated: () => true });
+
+        dispose();
+        dispatch(transitionEvent("pagehide", true));
+
+        expect(hiddenAttribute()).toBeNull();
+    });
+});
+
+describe("pagehide の秘匿判定", () => {
+    it("persisted=true (bfcache 対象) では秘匿属性を付ける", () => {
+        const { win, dispatch } = createWindowStub();
+        registerBfcacheGuard({ win, isAuthenticated: () => true });
+
+        dispatch(transitionEvent("pagehide", true));
+
+        expect(hiddenAttribute()).not.toBeNull();
+    });
+
+    it("persisted=false (通常遷移) では秘匿しない (ちらつき回避)", () => {
+        const { win, dispatch } = createWindowStub();
+        registerBfcacheGuard({ win, isAuthenticated: () => true });
+
+        dispatch(transitionEvent("pagehide", false));
+
+        expect(hiddenAttribute()).toBeNull();
+    });
+
+    it("persisted が取れない環境では安全側 (秘匿する) へ倒す", () => {
+        const { win, dispatch } = createWindowStub();
+        registerBfcacheGuard({ win, isAuthenticated: () => true });
+
+        dispatch(transitionEvent("pagehide"));
+
+        expect(hiddenAttribute()).not.toBeNull();
+    });
+
+    it("未認証ページ (auth.user なし) では秘匿もプローブもしない", async () => {
+        const { win, dispatch } = createWindowStub();
+        const fetchImpl = vi.fn<ProbeFetch>();
+        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => false });
+
+        dispatch(transitionEvent("pagehide", true));
+        dispatch(transitionEvent("pageshow", true));
+        await flushProbe();
+
+        expect(hiddenAttribute()).toBeNull();
+        expect(fetchImpl).not.toHaveBeenCalled();
+    });
+});
+
+describe("pageshow の復元マーカー判定", () => {
+    it("秘匿属性が無ければ (通常ロード) プローブしない", async () => {
+        const { win, dispatch } = createWindowStub();
+        const fetchImpl = vi.fn<ProbeFetch>();
+        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });
+
+        dispatch(transitionEvent("pageshow", true));
+        await flushProbe();
+
+        expect(fetchImpl).not.toHaveBeenCalled();
+    });
+
+    it("秘匿属性があれば persisted の値に依らずプローブする (属性が唯一のマーカー)", async () => {
+        const { win, dispatch } = createWindowStub();
+        const fetchImpl = vi.fn<ProbeFetch>(() =>
+            Promise.resolve(probeResponse({ authenticated: true })),
+        );
+        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });
+
+        dispatch(transitionEvent("pagehide", true));
+        dispatch(transitionEvent("pageshow", false));
+        await flushProbe();
+
+        expect(fetchImpl).toHaveBeenCalledTimes(1);
+    });
+
+    it("プローブは same-origin / no-store / Accept: application/json で叩く", async () => {
+        const { win, dispatch } = createWindowStub();
+        const fetchImpl = vi.fn<ProbeFetch>(() =>
+            Promise.resolve(probeResponse({ authenticated: true })),
+        );
+        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });
+
+        dispatch(transitionEvent("pagehide", true));
+        dispatch(transitionEvent("pageshow", true));
+        await flushProbe();
+
+        expect(fetchImpl).toHaveBeenCalledWith(SESSION_STATUS_PATH, {
+            credentials: "same-origin",
+            cache: "no-store",
+            headers: { Accept: "application/json" },
+        });
+    });
+});
+
+describe("プローブ結果ごとの遷移", () => {
+    /** 秘匿状態から 1 回プローブを走らせる。 */
+    async function restoreWith(response: () => Promise<ProbeResponseLike>): Promise<{
+        fetchImpl: ReturnType<typeof vi.fn>;
+        replace: ReturnType<typeof vi.fn>;
+        reload: ReturnType<typeof vi.fn>;
+    }> {
+        const { win, dispatch, replace, reload } = createWindowStub();
+        const fetchImpl = vi.fn<ProbeFetch>(response);
+        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });
+
+        dispatch(transitionEvent("pagehide", true));
+        dispatch(transitionEvent("pageshow", true));
+        await flushProbe();
+
+        return { fetchImpl, replace, reload };
+    }
+
+    it("authenticated:true なら秘匿を外すだけ (遷移も reload もしない)", async () => {
+        const { replace, reload } = await restoreWith(() =>
+            Promise.resolve(probeResponse({ authenticated: true })),
+        );
+
+        expect(hiddenAttribute()).toBeNull();
+        expect(replace).not.toHaveBeenCalled();
+        expect(reload).not.toHaveBeenCalled();
+    });
+
+    it("authenticated:false なら秘匿のまま login へ hard navigation する", async () => {
+        const { replace } = await restoreWith(() =>
+            Promise.resolve(probeResponse({ authenticated: false })),
+        );
+
+        expect(replace).toHaveBeenCalledWith(LOGIN_PATH);
+        expect(hiddenAttribute()).not.toBeNull();
+    });
+
+    it("fetch が reject したら秘匿維持 + 再試行 (自動再試行はしない)", async () => {
+        const { fetchImpl, replace } = await restoreWith(() =>
+            Promise.reject(new Error("network down")),
+        );
+
+        expect(hiddenAttribute()).not.toBeNull();
+        expect(fetchImpl).toHaveBeenCalledTimes(1);
+        expect(replace).not.toHaveBeenCalled();
+        expect(
+            document.getElementById(BFCACHE_RETRY_BUTTON_ID)?.isConnected,
+        ).toBe(true);
+    });
+
+    it("HTTP エラー応答 (ok=false) は秘匿維持 (login へ倒さない)", async () => {
+        const { replace } = await restoreWith(() =>
+            Promise.resolve(probeResponse({ authenticated: false }, { ok: false })),
+        );
+
+        expect(hiddenAttribute()).not.toBeNull();
+        expect(replace).not.toHaveBeenCalled();
+    });
+
+    it("Content-Type が JSON でなければ秘匿維持", async () => {
+        const { replace } = await restoreWith(() =>
+            Promise.resolve(
+                probeResponse({ authenticated: false }, { contentType: "text/html; charset=utf-8" }),
+            ),
+        );
+
+        expect(hiddenAttribute()).not.toBeNull();
+        expect(replace).not.toHaveBeenCalled();
+    });
+
+    it("Content-Type の charset パラメータは許容する", async () => {
+        const { replace } = await restoreWith(() =>
+            Promise.resolve(
+                probeResponse({ authenticated: false }, { contentType: "application/json; charset=UTF-8" }),
+            ),
+        );
+
+        expect(replace).toHaveBeenCalledWith(LOGIN_PATH);
+    });
+
+    it("shape 不一致 (authenticated が boolean でない) は秘匿維持", async () => {
+        const { replace } = await restoreWith(() =>
+            Promise.resolve(probeResponse({ authenticated: "false" })),
+        );
+
+        expect(hiddenAttribute()).not.toBeNull();
+        expect(replace).not.toHaveBeenCalled();
+    });
+
+    it("data ラップ (top-level でない) は秘匿維持", async () => {
+        const { replace } = await restoreWith(() =>
+            Promise.resolve(probeResponse({ data: { authenticated: true } })),
+        );
+
+        expect(hiddenAttribute()).not.toBeNull();
+        expect(replace).not.toHaveBeenCalled();
+    });
+});
+
+describe("再試行 UI", () => {
+    it("再試行押下で現在 URL を hard reload する", async () => {
+        const { win, dispatch, reload } = createWindowStub();
+        const fetchImpl = vi.fn<ProbeFetch>(() => Promise.reject(new Error("network down")));
+        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });
+
+        dispatch(transitionEvent("pagehide", true));
+        dispatch(transitionEvent("pageshow", true));
+        await flushProbe();
+
+        document.getElementById(BFCACHE_RETRY_BUTTON_ID)?.click();
+
+        expect(reload).toHaveBeenCalledTimes(1);
+        // 押下しても自動でプローブし直さない (reload に一本化)
+        expect(fetchImpl).toHaveBeenCalledTimes(1);
+    });
+
+    it("オーバーレイは 1 つだけ生成される (二重登録しても増えない)", () => {
+        const first = createWindowStub();
+        const second = createWindowStub();
+        registerBfcacheGuard({ win: first.win, isAuthenticated: () => true });
+        registerBfcacheGuard({ win: second.win, isAuthenticated: () => true });
+
+        expect(document.querySelectorAll(`#${BFCACHE_OVERLAY_ID}`)).toHaveLength(1);
+    });
+});

```
