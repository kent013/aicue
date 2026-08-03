# 役割・前提

【アプリの使命 (North Star) — AGENTS.md より】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 標準作業を起点に AI が教材設計し撮影を指示する（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。
データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。方向性が間違っているなら、設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
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

# 詳細設計: bugfix-i18n-copy — コピー崩れの修正 (F-01 APP_NAME 未展開 / F-02 未翻訳キー)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン（AGENTS.md参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260712-0926-bugfix-i18n-copy/conceptual-design.md`（Codex 概念レビュー Round 3 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | F-01: bughunt env の APP_NAME 自己参照解消 | `.env.bughunt.local.example` / `.env.bughunt.local`(非コミット) | 高 |
| 2 | F-01: env example の自己参照/前方参照禁止 invariant | `tests/Architecture/EnvExampleInvariantTest.php` | 高 |
| 3 | F-02: `lang/ja/validation.php` attributes の全域補完 | `lang/ja/validation.php` | 高 |
| 4 | F-02: attributes カバレッジ invariant (fail-closed) | `tests/Architecture/ValidationAttributeCoverageTest.php`(新規) | 高 |
| 5 | F-02: 表示文言の再現 Feature テスト | `tests/Feature/Inquiry/ContactSubmissionTest.php` / `tests/Feature/Organizations/TwoFactorEnforcementTest.php` | 高 |

---

## 施策 1: F-01 bughunt env の APP_NAME 自己参照解消

### 変更箇所

- ファイル: `.env.bughunt.local.example` (L25)
- ファイル: `.env.bughunt.local` (L25、gitignore 済み。**同一実装ステップで直接編集**するがコミット対象外)

### 波及変更

- TypeScript型定義: なし（`shared-props.ts` の `appName: string` は不変）
- API Resource/DTO: なし
- テストファイル: 施策 2 の invariant テストが再発を防止

### 現行コード

```dotenv
APP_ENV=bughunt.local
APP_NAME="${APP_NAME}"
# bug-hunt はユーザー向け文言 (日本語) の検証環境
APP_LOCALE=ja
```

`APP_ENV=bughunt.local` では `.env.bughunt.local` のみがロードされ、`${APP_NAME}` の
nested variable は「同一ファイル内の先行定義 or 実行環境変数」しか解決できないため、
自己参照はリテラル `${APP_NAME}` のまま `config('app.name')` に流れる
（`HandleInertiaRequests::share()` の `appName` 経由で全画面のタイトル/ロゴ/フッターに露出）。

### 変更後コード

```dotenv
APP_ENV=bughunt.local
# 表示用アプリ名。dev の .env と同じ実値を書く (このファイルは単独ロードされるため
# "${APP_NAME}" のような自己参照は解決されず、リテラルがそのまま画面に露出する)
APP_NAME="AI-CUE"
# bug-hunt はユーザー向け文言 (日本語) の検証環境
APP_LOCALE=ja
```

`.env.bughunt.local`（実ファイル）にも同一の変更を適用する。

### PHPStan適合チェック

- 対象外（env ファイルのみ。PHP コード変更なし）

### テスト計画

- [x] 施策 2 の invariant テストが「自己参照」を機械検出する（先にテストを書き、現行の
  `.env.bughunt.local.example` で **fail することを確認**してから env を修正 = テストファースト）
- [ ] 手動検証: `scripts/bug-hunt-shard.sh provision` 再実行後、`http://127.0.0.1:8010/` の
  タブタイトル・ヘッダーロゴ・フッターに「AI-CUE」が表示されることを確認（実装フェーズの verify 手順）

### リスク

- なし（bughunt 隔離環境の表示設定のみ。DB/ガード/アプリロジックに不接触）
- `AppNameHardcodeTest`（slug ハードコード検査）は env ファイルを走査対象にしておらず、
  検査対象も `TEMPLATE_APP_SLUG` 値（表示名 APP_NAME とは別物）のため抵触しない

---

## 施策 2: F-01 env example の自己参照/前方参照禁止 invariant

### 変更箇所

- ファイル: `tests/Architecture/EnvExampleInvariantTest.php`（テスト追加。既存テストは変更しない）

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: なし
- テストファイル: 本施策自体がテスト追加

### 現行コード

既存は `.env.example` の特定キー存在チェックのみ（SESSION_SECURE_COOKIE 等）。
`${VAR}` 参照の健全性は未検査。

### 変更後コード

```php
/*
 * env ファイルの `${VAR}` nested variable は「同一ファイル内の先行定義」しか解決できない
 * (APP_ENV 別ロードでは他ファイルを継承しない)。自己参照 (VAR="${VAR}") や前方参照は
 * リテラル文字列がそのまま画面に露出する事故になる (bug-hunt F-01 の実例)。
 *
 * 意図的に「実行環境からの外部注入」を期待する参照は ENV_EXTERNAL_REF_ALLOWLIST に
 * ファイル => 変数名 => 理由 で登録する (deny-by-default)。
 */

/** @var array<string, array<string, string>> */
const ENV_EXTERNAL_REF_ALLOWLIST = [
    // '.env.example' => ['SOME_VAR' => '理由'],
];

/**
 * @return array<int, array{file: string, line: int, ref: string}> 違反一覧
 */
function collectUnresolvedEnvRefs(string $relativePath): array
{
    $contents = file_get_contents(base_path($relativePath));
    expect($contents)->toBeString();
    /** @var string $contents */
    $defined = [];
    $violations = [];

    foreach (explode("\n", $contents) as $i => $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $trimmed, $m) !== 1) {
            continue;
        }
        [$_, $key, $value] = $m;

        // 値の中の ${VAR} 参照を全て検査 (定義行より前に VAR 定義が無ければ違反)
        if (preg_match_all('/\$\{([A-Z0-9_]+)\}/', $value, $refs) > 0) {
            foreach ($refs[1] as $ref) {
                $allowed = ENV_EXTERNAL_REF_ALLOWLIST[$relativePath][$ref] ?? null;
                if ($allowed === null && ! array_key_exists($ref, $defined)) {
                    $violations[] = ['file' => $relativePath, 'line' => $i + 1, 'ref' => $ref];
                }
            }
        }

        // 定義の登録は参照検査の後 (VAR="${VAR}" の自己参照を違反にするため)
        $defined[$key] = true;
    }

    return $violations;
}

test('コミット対象 env ファイルに自己参照・前方参照の ${VAR} が無い', function (): void {
    $violations = [];
    foreach (['.env.example', '.env.bughunt.local.example', '.env.testing'] as $file) {
        $violations = array_merge($violations, collectUnresolvedEnvRefs($file));
    }
    expect($violations)->toBe([], '未解決の ${VAR} 参照: '.json_encode($violations, JSON_UNESCAPED_SLASHES));
});
```

検証済みの現状データ: `.env.example` は `APP_NAME`(L1)→`MAIL_FROM_NAME`(L58)/`VITE_APP_NAME`(L72)、
`APP_URL`→`GOOGLE_REDIRECT_URI`(L180) の後方参照のみで pass。`.env.testing` も pass。
`.env.bughunt.local.example` は L25 の自己参照で **fail → 施策 1 で修正後 pass**（テストファースト成立）。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`array<int, array{file: string, line: int, ref: string}>`）
- [x] null安全（`file_get_contents` の false を expect で排除）
- [x] DTOを返している: 対象外（テストコード内部の値運搬のみ）
- [x] Genericsの型パラメータ: 対象外

### テスト計画

- [x] 再現テスト先行: 現行 `.env.bughunt.local.example` で fail することを確認してから施策 1 を適用
- [x] 新規テスト: `EnvExampleInvariantTest`「コミット対象 env ファイルに自己参照・前方参照の ${VAR} が無い」
- [x] `DatabaseTransactions` 不使用（ファイル走査のみ）

### リスク

- 将来「実行環境からの注入」を意図した `${VAR}` を書く場合に fail する → ALLOWLIST で理由付き許容
  （deny-by-default。既定は空）

---

## 施策 3: F-02 `lang/ja/validation.php` attributes の全域補完

### 変更箇所

- ファイル: `lang/ja/validation.php` (L181-188 の `'attributes'` 配列、および L10-15 のヘッダ docblock)

### 波及変更

- TypeScript型定義: なし（エラーメッセージは `form.errors.{field}` の文字列のまま。型不変）
- API Resource/DTO: なし
- テストファイル: 施策 4/5 が検証

### ラベル対応表（既存 UI = Svelte フォーム label を正とする）

棚卸し対象: `app/Http/Requests/**` 全 19 クラスの `rules()` 全キー + `app/` 配下の inline validate
(`OrganizationController` name/enabled, `OrganizationOwnershipController` user_id,
`OrganizationMemberController` reason, `InvitationAcceptanceController` token,
`ConfirmRecentAuthController` password, `ProjectMemberController` user_id/role,
`app/Actions/Fortify/*` name/email/password/current_password, `CreateAdminCommand` name/email/password)。
`['missing']` のみの保護キー（`ProhibitsProtectedKeys::protectedKeyMissingRules()` 由来）は
ユーザー入力フィールドではないため対象外（施策 4 で機械的に除外）。

| フィールド | UI ラベル（出典） | attributes ラベル |
|-----------|----------------|------------------|
| company_name | 会社・組織名 (Contact/Index.svelte) | 会社・組織名 |
| message | お問い合わせ内容 (Contact/Index.svelte) | お問い合わせ内容 |
| type | お問い合わせ種別 (Contact/Index.svelte) | お問い合わせ種別 |
| source | (hidden 導線パラメータ) | 参照元 |
| website | (honeypot。通常ユーザーには非表示) | ウェブサイト |
| g-recaptcha-response | (reCAPTCHA token) | reCAPTCHA |
| reason | 理由 (Admin/Users.svelte「理由 (10 文字以上…)」) | 理由 |
| role | ロール (Admin/Users.svelte, 招待フォーム) | ロール |
| token | (招待受諾トークン) | 招待トークン |
| enabled | 2 段階認証の必須化 (Organizations/Settings.svelte) | 2 段階認証の必須化 |
| user_id | 移譲先のメンバー / メンバー (選択 UI) | 対象ユーザー |
| plan_code | プラン (Billing) | プラン |
| count | 購入枚数 (PurchaseTickets.svelte「購入枚数は…」) | 購入枚数 |
| attempt_token | (二重送信防止トークン) | 操作トークン |
| abilities / abilities.* | 権限 (ApiKeys「読み取り (read)」「書き込み (write)」) | 権限 |
| description | 説明 (Projects) | 説明 |
| note | メモ (Items) | メモ |
| title | タイトル (Manuals) | タイトル |
| category | カテゴリ (Manuals) | カテゴリ |
| document | 手順書 (SOP) (Manuals/Create.svelte) | 手順書ファイル |
| order / order.* | (カテゴリ並べ替え DnD) | 表示順 |
| lang | 字幕言語 (ダウンロード) | 字幕言語 |
| expected_version | (シナリオ楽観ロック) | シナリオバージョン |
| steps / steps.* | 手順 (シナリオ編集) | 手順 |
| steps.*.points / steps.*.points.* | 撮影ポイント (シナリオ編集) | 撮影ポイント |
| steps.*.id / steps.*.points.*.id | (行 ID) | ID |
| steps.*.scene / steps.*.points.*.scene | シーン (何を撮るか) | シーン |
| steps.*.shot_type / steps.*.points.*.shot_type | 画角 | 画角 |
| steps.*.shooting_point / steps.*.points.*.shooting_point | 撮影ポイント | 撮影ポイント |
| steps.*.narration / steps.*.points.*.narration | ナレーション | ナレーション |
| steps.*.subtitle_primary / steps.*.points.*.subtitle_primary | 字幕① (要点・100文字まで) | 字幕① |
| steps.*.subtitle_secondary / steps.*.points.*.subtitle_secondary | 字幕② (補足) | 字幕② |
| steps.*.material_type / steps.*.points.*.material_type | 素材 | 素材 |
| steps.*.static_display_seconds / steps.*.points.*.static_display_seconds | 静止表示秒数 (1〜60) | 静止表示秒数 |
| ticket | (撮影セッションチケット) | 撮影チケット |
| client_take_id / takes.*.client_take_id | (端末側テイク ID) | テイクID |
| duration_ms | (撮影時間 ms) | 撮影時間 |
| captured_at | (撮影日時) | 撮影日時 |
| video_path | (動画パス) | 動画ファイル |
| size_bytes | (ファイルサイズ) | ファイルサイズ |
| status | (テイク状態) | ステータス |
| sort_order | (並び順) | 並び順 |
| content_type | (MIME) | ファイル形式 |
| checksum_sha256 | (チェックサム) | チェックサム |
| comment | コメント (テイクレビュー) | コメント |
| position | (テイク表示位置) | 表示位置 |
| downloaded_at | (DL 日時) | ダウンロード日時 |
| ack_token | (DL 確認トークン) | 確認トークン |
| takes | (同期ペイロード) | テイク一覧 |
| takes.*.cut | (対象カット) | カット |
| takes.*.cut_id | (対象カット ID) | カットID |

既存 6 キー（name/email/password/password_confirmation/current_password/terms_accepted）は不変。
`name` はフォームごとに「お名前/組織名/プロジェクト名/キー名」等と揺れるが、グローバル attributes は
1 対 1 のため汎用の「名前」を維持する（フォーム個別最適が必要になった場合のみ
FormRequest::attributes() で上書きする、という規約をヘッダ docblock に追記）。

### 現行コード

```php
    'attributes' => [
        'name' => '名前',
        'email' => 'メールアドレス',
        'password' => 'パスワード',
        'password_confirmation' => 'パスワード（確認）',
        'current_password' => '現在のパスワード',
        'terms_accepted' => '利用規約への同意',
    ],
```

### 変更後コード

```php
    'attributes' => [
        // --- 認証・アカウント (既存) ---
        'name' => '名前',
        'email' => 'メールアドレス',
        'password' => 'パスワード',
        'password_confirmation' => 'パスワード（確認）',
        'current_password' => '現在のパスワード',
        'terms_accepted' => '利用規約への同意',
        // --- お問い合わせ ---
        'company_name' => '会社・組織名',
        'message' => 'お問い合わせ内容',
        'type' => 'お問い合わせ種別',
        'source' => '参照元',
        'website' => 'ウェブサイト',
        'g-recaptcha-response' => 'reCAPTCHA',
        // --- 組織・メンバー管理 ---
        'reason' => '理由',
        'role' => 'ロール',
        'token' => '招待トークン',
        'enabled' => '2 段階認証の必須化',
        'user_id' => '対象ユーザー',
        'abilities' => '権限',
        'abilities.*' => '権限',
        // --- 課金 ---
        'plan_code' => 'プラン',
        'count' => '購入枚数',
        'attempt_token' => '操作トークン',
        // --- プロジェクト・マニュアル ---
        'description' => '説明',
        'note' => 'メモ',
        'title' => 'タイトル',
        'category' => 'カテゴリ',
        'document' => '手順書ファイル',
        'order' => '表示順',
        'order.*' => '表示順',
        'lang' => '字幕言語',
        // --- シナリオ編集 (steps.* は数値 index → * 正規化で解決される) ---
        'expected_version' => 'シナリオバージョン',
        'steps' => '手順',
        'steps.*' => '手順',
        'steps.*.points' => '撮影ポイント',
        'steps.*.points.*' => '撮影ポイント',
        'steps.*.id' => 'ID',
        'steps.*.scene' => 'シーン',
        'steps.*.shot_type' => '画角',
        'steps.*.shooting_point' => '撮影ポイント',
        'steps.*.narration' => 'ナレーション',
        'steps.*.subtitle_primary' => '字幕①',
        'steps.*.subtitle_secondary' => '字幕②',
        'steps.*.material_type' => '素材',
        'steps.*.static_display_seconds' => '静止表示秒数',
        'steps.*.points.*.id' => 'ID',
        'steps.*.points.*.scene' => 'シーン',
        'steps.*.points.*.shot_type' => '画角',
        'steps.*.points.*.shooting_point' => '撮影ポイント',
        'steps.*.points.*.narration' => 'ナレーション',
        'steps.*.points.*.subtitle_primary' => '字幕①',
        'steps.*.points.*.subtitle_secondary' => '字幕②',
        'steps.*.points.*.material_type' => '素材',
        'steps.*.points.*.static_display_seconds' => '静止表示秒数',
        // --- 撮影 PWA ---
        'ticket' => '撮影チケット',
        'client_take_id' => 'テイクID',
        'duration_ms' => '撮影時間',
        'captured_at' => '撮影日時',
        'video_path' => '動画ファイル',
        'size_bytes' => 'ファイルサイズ',
        'status' => 'ステータス',
        'sort_order' => '並び順',
        'content_type' => 'ファイル形式',
        'checksum_sha256' => 'チェックサム',
        'comment' => 'コメント',
        'position' => '表示位置',
        'downloaded_at' => 'ダウンロード日時',
        'ack_token' => '確認トークン',
        'takes' => 'テイク一覧',
        'takes.*.cut' => 'カット',
        'takes.*.client_take_id' => 'テイクID',
        'takes.*.cut_id' => 'カットID',
    ],
```

ヘッダ docblock（L10-12 付近）に追記する規約:

```php
 * 2. `attributes`: `:attribute` placeholder を日本語ラベルへ置換する map。
 *    **ラベルは対応する Svelte フォームの label 文言を正とする** (語彙ズレ禁止)。
 *    フィールド追加時の登録漏れは tests/Architecture/ValidationAttributeCoverageTest が検出する。
 *    同名キーでフォーム毎にラベルを変えたい場合のみ FormRequest::attributes() で上書きする。
```

### PHPStan適合チェック

- [x] 対象は返却配列リテラルのみ（既存 `@return array<string, mixed>` 型に適合）

### テスト計画

- [x] 施策 4 の Architecture テストが現行 6 キーの状態で fail することを先に確認（テストファースト）
- [x] 施策 5 の Feature テストが表示文言を検証

### リスク

- 汎用ラベル（名前/ステータス等）が一部フォームの UI 文言と完全一致しない → ヘッダ規約 +
  必要時の `FormRequest::attributes()` 上書きで将来対応（今回のフォームでは意味が通ることを対応表で確認済み）
- `confirmed` メッセージ（`:attributeと:attribute確認が一致しません。`）等の合成文は既存挙動のまま（変更しない）

---

## 施策 4: F-02 attributes カバレッジ invariant（fail-closed）

### 変更箇所

- ファイル: `tests/Architecture/ValidationAttributeCoverageTest.php`（新規）

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: なし

### 変更後コード（仕様の骨子）

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Lang;

/*
 * ユーザー向けバリデーション文言の deny-by-default inventory (bug-hunt F-02 由来):
 *
 * 検査 1: app/Http/Requests 配下の全 FormRequest の rules() 全キー (dotted/wildcard 含む) が
 *         lang/ja/validation.php の attributes に登録されていること。
 * 検査 2: app/ 配下 (app/Http/Requests を除く) の inline validation 呼び出し
 *         (`->validate(` / `->validateWithBag(` / `Validator::make(`) をトークナイザで検出し、
 *         ルール配列リテラルから抽出した全キーが attributes に登録されていること。
 *         ルール配列を静的抽出できない呼び出しは UNPARSEABLE_CALL_INVENTORY に理由付きで
 *         登録されていない限り fail (fail-closed: 未解析を成功扱いしない)。
 *
 * 規約: validation の呼び出し経路を追加する場合 (`validator()` helper 等) は、本テストの
 *       検出対象パターンにも必ず追加すること。
 */

/**
 * 属性ラベル不要キーの除外 inventory (クラス => キー => 理由)。
 *
 * @var array<class-string, array<string, string>>
 */
const ATTRIBUTE_ALLOWLIST = [
    // 既定は空。属性名を含まない個別 messages() を全 rule に定義したキーのみ登録可。
];

/**
 * rules() をリクエスト文脈なしで安全に呼べないクラスの除外 inventory (クラス => 理由)。
 *
 * @var array<class-string, string>
 */
const RULES_UNINSTANTIABLE_INVENTORY = [
    // 既定は空 (現状の全 19 クラスは route 未バインドでも null-safe に呼べることを確認済み)。
];

/**
 * ルール配列を静的抽出できない inline 呼び出しの除外 inventory (ファイル@呼び出し => 理由)。
 *
 * @var array<string, string>
 */
const UNPARSEABLE_CALL_INVENTORY = [
    // 既定は空 (現状の inline 呼び出しは全て配列リテラル)。
];

// --- 検査 1 (FormRequest) ---
// allFormRequestClasses() 相当の列挙 (FormRequestProhibitedKeyTest と同一パターン。
// 関数名は Pest のグローバル関数衝突を避け validationCoverage* プレフィックスにする)
// 各クラスを $class::create('/', 'POST') で生成し setContainer(app()) → rules() を呼ぶ。
// - 値が ['missing'] のみのキーは保護キー deny-guard のため対象外
// - キーの数値セグメントは '*' に正規化
// - Lang::get('validation.attributes') (array) に含まれなければ違反
test('全 FormRequest の rules() キーが validation attributes に登録されている', ...);

// --- 検査 2 (inline validation, fail-closed) ---
// app/ 配下の *.php (app/Http/Requests 除く) を走査し、token_get_all() で
// コメント・文字列リテラルを除外した上で以下を検出する:
//   T_OBJECT_OPERATOR + 'validate' / 'validateWithBag'
//   'Validator' + T_DOUBLE_COLON + 'make' (use エイリアス含む単純名一致)
// 呼び出しごとにルール配列引数 (validate 系は第 1、make は第 2) を追跡し、
// '[' ... ']' の深さ 1 にある T_CONSTANT_ENCAPSED_STRING キー ('key' =>) を抽出する。
// 引数が配列リテラルでない場合は "相対パス@行番号の呼び出し種別" を violation とし、
// UNPARSEABLE_CALL_INVENTORY に登録が無ければ fail。
test('inline validation のルールキーが validation attributes に登録されている (fail-closed)', ...);
```

実装上の要点:

- `Lang::get('validation.attributes')` はアプリロケール (`ja`) で解決される（`.env.testing` は
  `APP_LOCALE` 未指定でも `config/app.php` の locale 既定に従う。テスト内で
  `app()->setLocale('ja')` を明示して環境非依存にする）。
- 検査 1 のキー正規化: `preg_replace('/(^|\.)\d+(\.|$)/', '$1*$2', $key)` 相当。ただし rules() の
  キーは定義時点で `*` を含むため、実際には正規化はほぼ恒等（防御的に適用）。
- ワイルドカードキーの照合は「完全一致」とする（`steps.*.scene` は attributes に同名キーが必要）。
  Laravel 実行時は `steps.0.scene` → primaryAttribute `steps.*.scene` で同じキーを引くため整合する。
- 検査 2 の検出対象は `->validate(` / `->validateWithBag(` / `Validator::make(` の 3 種
  （現状の全 inline 呼び出しを網羅していることを棚卸しで確認済み:
  Organization/Ownership/Member/InvitationAcceptance/ConfirmRecentAuth/ProjectMember の各 Controller +
  Fortify Actions 4 件 + CreateAdminCommand）。
- 静的解析ヘルパの戻り値は `array<string, list<string>>`（ファイル => キーリスト）等、
  具体的な array shape を PHPDoc で固定する。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（ヘルパ関数に array shape PHPDoc）
- [x] null安全（`file_get_contents`/`token_get_all` の失敗を早期 expect で排除）
- [x] DTO: 対象外（テストコード）
- [x] Generics: `array<class-string, ...>` 形式の inventory

### テスト計画

- [x] テストファースト: attributes 補完（施策 3）前に本テストを書き、検査 1 が `message` 等の
  未登録キーで fail、検査 2 が `reason` 等で fail することを確認 → 施策 3 適用で green
- [x] `RefreshDatabase` グローバル適用に従う（DB は使わないが個別 `DatabaseTransactions` を書かない）

### リスク

- FormRequest instantiate 時に将来のクラスが route/auth に強依存すると error → `RULES_UNINSTANTIABLE_INVENTORY`
  へ理由付き登録の逃げ道（fail-closed: 未登録なら例外でテスト fail）
- トークナイザ検査の誤検出（同名メソッド `validate` を持つ独自クラス等）→ 呼び出し検出は
  過剰検出側に倒れる（fail-closed）。誤検出は `UNPARSEABLE_CALL_INVENTORY` で理由付き除外

---

## 施策 5: F-02 表示文言の再現 Feature テスト

### 変更箇所

- ファイル: `tests/Feature/Inquiry/ContactSubmissionTest.php`（テスト追加。既存テストは変更しない）
- ファイル: `tests/Feature/Organizations/TwoFactorEnforcementTest.php`（テスト追加。既存テストは変更しない）

### 波及変更

- なし（テスト追加のみ）

### 変更後コード

ContactSubmissionTest（既存の `validInquiryPayload()` ヘルパを流用）:

```php
// F-02 再現: 未翻訳キーではなくユーザー向け日本語ラベルの文言が返る
test('お問い合わせ内容が空だと日本語ラベルのエラー文言が返る', function (): void {
    $response = $this->from('/contact')
        ->post('/contact', validInquiryPayload(['message' => '']));

    // 検証対象は「表示文言」。エラー bag のキーが 'message' であること自体は仕様
    $response->assertSessionHasErrors(['message' => 'お問い合わせ内容は必須項目です。']);
});
```

TwoFactorEnforcementTest（既存の `tfeResetUrl()` ヘルパ・actor 準備パターンを流用）:

```php
// F-02 再現: reason 未入力時に内部キー 'reason' ではなく日本語ラベルの文言が返る
test('2FA 解除の理由が空だと日本語ラベルのエラー文言が返る', function (): void {
    // 既存テスト「owner が member の 2FA を解除できる」と同一のセットアップで
    // reason を空にして送信する
    ...
    $response->assertSessionHasErrors(['reason' => '理由は必須項目です。']);
});
```

`assertSessionHasErrors(['field' => '期待文言'])` は**メッセージ本文の一致**を検証する
（Round 1 Critical 対応: エラー bag のキーではなく表示文言を検証面にする）。

### テスト計画

- [x] 再現テスト先行: attributes 補完前に追加し、fail（現状は「messageは必須項目です。」）を確認
- [x] 既存テストの削除・上書きなし
- [x] Factory 使用（TwoFactorEnforcementTest の既存パターンに従う）

### リスク

- 文言をハードコードで検証するため、将来 `lang` の required 文言を変えるとテスト修正が必要
  （意図的な結合: 「ユーザーに何が表示されるか」が検証対象のため許容）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 変更対象が env / lang / テストのみでアプリロジックに不接触。単一 worktree・単一 PR で完結し、他 finding 修正 (F-03/F-05/F-07 等) とファイル競合しない |
| 競合リスク | `lang/ja/validation.php` を他タスクが同時に触る場合のみ (現状該当なし)。bug-hunt 統合レポート起点の他 TODO とは独立 |

## 実装手順（テストファースト順序）

1. 施策 2 のテスト追加 → `.env.bughunt.local.example` の自己参照で fail を確認
2. 施策 4 のテスト追加 → attributes 未登録キーで fail を確認
3. 施策 5 のテスト追加 → 「messageは必須項目です。」で fail を確認
4. 施策 1 (env 修正) → 施策 2 green
5. 施策 3 (attributes 補完) → 施策 4/5 green
6. `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` 全 green
7. 手動 verify: `scripts/bug-hunt-shard.sh provision` → home のタイトル/フッターに AI-CUE 表示、
   contact フォーム空送信で「お問い合わせ内容は必須項目です。」表示

---

## 関連する現行コード

### lang/ja/validation.php (抜粋: ヘッダ + attributes)
```php
<?php

declare(strict_types=1);

/**
 * laravel-lang/lang (publisher: `php artisan lang:add ja`) で publish した日本語翻訳を
 * 基底に、当アプリ固有のカスタマイズを 2 点だけ重ねている:
 *
 * 1. `password.uncompromised`: `Password::uncompromised()` (= HIBP 漏洩照合) を
 *    「漏洩した可能性」ではなく確定として伝えるため上書き。PasswordPolicy が本 rule を使う。
 * 2. `attributes`: `:attribute` placeholder を `resources/js/pages/Auth/Register.svelte`
 *    の form label と揃った日本語ラベルへ置換する map。
 *
 * 上記以外は laravel-lang/lang の publish 結果に追従させる。Laravel 側で rule key が
 * 増減した場合は `php artisan lang:add ja` を再実行し、1/2 のカスタマイズを再適用する。
 *
 * @return array<string, mixed>
 */
return [
    'accepted' => ':attributeを承認してください。',
...

    /*
     * `:attribute` placeholder を form 上の表示ラベルと揃った日本語に置換する map。
     * 値は `resources/js/pages/Auth/Register.svelte` 等の `<Input label="...">` と一致させる。
     * laravel-lang/lang は当 section を publish しないため、 本アプリ側で手動メンテする。
     */
    'attributes' => [
        'name' => '名前',
        'email' => 'メールアドレス',
        'password' => 'パスワード',
        'password_confirmation' => 'パスワード（確認）',
        'current_password' => '現在のパスワード',
        'terms_accepted' => '利用規約への同意',
    ],
];
```

### .env.bughunt.local.example (L24-28)
```dotenv
APP_ENV=bughunt.local
APP_NAME="${APP_NAME}"
# bug-hunt はユーザー向け文言 (日本語) の検証環境
APP_LOCALE=ja
APP_URL=http://127.0.0.1:8010
```

### tests/Architecture/EnvExampleInvariantTest.php (全文)
```php
<?php

declare(strict_types=1);

/*
 * production deploy 時に SESSION_SECURE_COOKIE / SESSION_ENCRYPT を立て忘れないよう
 * .env.example に必ず提示する invariant (aigenba T425 SEC03 由来)。
 */

test('.env.example に SESSION_SECURE_COOKIE=true が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('SESSION_SECURE_COOKIE=true');
});

test('.env.example に SESSION_ENCRYPT=true が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('SESSION_ENCRYPT=true');
});

/*
 * テンプレート規約: 環境座標 (config/template.php) のキーは .env.example に必ず提示する。
 */

test('.env.example に TEMPLATE_APP_SLUG が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('TEMPLATE_APP_SLUG=');
});
```

### tests/Architecture/FormRequestProhibitedKeyTest.php (パターン参照用・全文)
```php
<?php

declare(strict_types=1);

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/*
 * mass-assignment 入口防御の deny-by-default inventory:
 * app/Http/Requests 配下の全 FormRequest は ProhibitsProtectedKeys を use し、
 * rules() の戻り値に保護キーの missing rule を含めなければならない。
 *
 * 例外を許す場合は ALLOWLIST に「クラス名 => 理由」を追記し、
 * docs/template-divergence.md に記録すること。
 */

const FORM_REQUEST_ALLOWLIST = [
    // 'App\Http\Requests\FooRequest' => '理由',
];

/**
 * @return list<class-string>
 */
function allFormRequestClasses(): array
{
    $classes = [];
    $base = app_path('Http/Requests');
    if (! is_dir($base)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace([$base.'/', '.php', '/'], ['', '', '\\'], $file->getPathname());
        $class = 'App\\Http\\Requests\\'.$relative;
        if (class_exists($class)
            && is_subclass_of($class, FormRequest::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

test('全 FormRequest が ProhibitsProtectedKeys を use している (deny-by-default)', function (): void {
    $violations = [];

    foreach (allFormRequestClasses() as $class) {
        if (array_key_exists($class, FORM_REQUEST_ALLOWLIST)) {
            continue;
        }
        $traits = class_uses_recursive($class);
        if (! in_array(ProhibitsProtectedKeys::class, $traits, true)) {
            $violations[] = $class;
        }
    }

    expect($violations)->toBe([]);
});
```

### app/Http/Requests/StoreInquiryRequest.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Inquiry\InquiryType;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Rules\Recaptcha;
use App\Services\Captcha\RecaptchaVerifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 公開フォーム (POST /contact) の入力検証。
 *
 * 設計上の重要な不変条件:
 * - honeypot (`website`) は **検証を fail させない**。値が入っていても validation を通し、
 *   Controller が isHoneypotFilled() を見て「保存せず通常成功と同一レスポンス」に倒す
 *   (bot に成否を悟らせない)。captcha を素の required にすると、honeypot を埋めた
 *   captcha 無し bot が Controller 到達前に validation error になり silent success が
 *   壊れるため、honeypot 充填時は空ルール (一切 validation しない) を返す。
 */
class StoreInquiryRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // コピペ時の前後空白で validation fail しないよう scalar email だけ trim。
        // 正規化本体 (lowercase 含む) は EmailNormalizer (CreateInquiryData) に集約。
        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => trim($email)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // honeypot 充填時は一切 validation しない (空ルール)。bot を黙って捨てる経路
        // (Controller の silent success) に渡すため、website が配列・長大文字列でも 422 に
        // しない (= silent success を絶対に崩さない)。bot 経路は Controller が Action を
        // 呼ばないため mass-assign は発生しない。
        if ($this->isHoneypotFilled()) {
            return [];
        }

        return array_replace([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
            'type' => ['required', Rule::enum(InquiryType::class)],
            'source' => ['nullable', 'string', 'max:50'],
            // 利用規約・プライバシーポリシーへの同意必須 (登録 CreateNewUser 踏襲)。
            'terms_accepted' => ['accepted'],
            'g-recaptcha-response' => [
                'required',
                'string',
                new Recaptcha(app(RecaptchaVerifier::class), $this->ip()),
            ],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terms_accepted.accepted' => '利用規約・プライバシーポリシーへの同意が必要です。',
            // token 未取得 (script ブロック / ロード失敗 / 失効) はユーザーに再試行を促す。
            // 既定メッセージだと属性名 "g-recaptcha-response" がそのまま露出するため必須。
            'g-recaptcha-response.required' => 'reCAPTCHAの確認に失敗しました。ページを再読み込みのうえ、もう一度お試しください。',
        ];
    }

    public function isHoneypotFilled(): bool
    {
        return $this->filled('website');
    }
}
```

### app/Http/Requests/Projects/StoreCategoryRequest.php (route 依存 null-safe パターンの代表)
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Category 作成。
 *
 * - 親 FK (project_id) は URL から導出するため payload では受け付けない (missing rule)
 * - sort_order は Service が末尾採番する契約のため入力から除外 (rules 外 = 送っても無視)
 * - name の project 内ユニークは通常入力を Request で 422 に、並行競合は Service の
 *   ロック後再検査 (assertNameUnique) が 422 に先回りする二段構え
 */
class StoreCategoryRequest extends FormRequest
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
        $project = $this->route('project');
        $projectId = $project instanceof Project ? $project->id : 0;

        return array_merge([
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('categories', 'name')->where('project_id', $projectId),
            ],
        ], $this->protectedKeyMissingRules());
    }
}
```

### app/Http/Requests/Projects/UpdateScenarioRequest.php (rules 抜粋)
```php
    public function rules(): array
    {
        return array_merge(
            [
                'expected_version' => ['required', 'integer', 'min:0'],
                'steps' => ['present', 'array', 'max:'.ScenarioLimits::MAX_STEPS],
                // points キー欠落はクライアント直列化バグ。行単位で明示エラーにする
                'steps.*' => ['array', 'required_array_keys:points'],
                'steps.*.points' => ['present', 'array', 'max:'.ScenarioLimits::MAX_POINTS_PER_STEP],
                'steps.*.points.*' => ['array'],
            ],
            $this->cutRowRules('steps.*'),
            $this->cutRowRules('steps.*.points.*'),
            $this->nestedProtectedKeyRules('steps.*'),
            $this->nestedProtectedKeyRules('steps.*.points.*'),
            $this->protectedKeyMissingRules(),
        );
    }
    private function cutRowRules(string $prefix): array
    {
        return [
            "{$prefix}.id" => ['nullable', 'integer'],
            "{$prefix}.scene" => ['required', 'string', 'max:1000'],
            "{$prefix}.shot_type" => ['required', Rule::enum(ShotType::class)],
            "{$prefix}.shooting_point" => ['nullable', 'string', 'max:1000'],
            "{$prefix}.narration" => ['present', 'string', 'max:2000'],
            "{$prefix}.subtitle_primary" => ['nullable', 'string', 'max:100'],
            "{$prefix}.subtitle_secondary" => ['present', 'string', 'max:2000'],
            "{$prefix}.material_type" => ['nullable', Rule::enum(MaterialType::class)],
            "{$prefix}.static_display_seconds" => ['nullable', 'integer', 'min:1', 'max:60'],
        ];
    }
```

### app/Http/Requests/Concerns/ProhibitsProtectedKeys.php (抜粋)
```php
trait ProhibitsProtectedKeys
{
    /**
     * @param  list<string>  $except
     * @return array<string, list<string>>
     */
    protected function protectedKeyMissingRules(array $except = []): array
    {
        $rules = [];
        foreach (MassAssignmentProtectedKeys::all() as $key) {
            if (! in_array($key, $except, true)) {
                $rules[$key] = ['missing'];
            }
        }

        return $rules;
    }
}
```

### app/Http/Controllers/Organizations/OrganizationMemberController.php (reason inline validate 抜粋)
```php

        // 空白のみ入力は Laravel 既定の TrimStrings が trim 済み ('' → required で拒否)
        /** @var array{reason: string} $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

```

### tests/Feature/Inquiry/ContactSubmissionTest.php (抜粋 L1-60)
```php
<?php

declare(strict_types=1);

use App\Enums\Inquiry\InquiryStatus;
use App\Mail\InquiryAcknowledgementMail;
use App\Mail\InquiryReceivedMail;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Mail;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed> 正当な問い合わせ POST payload
 */
function validInquiryPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'general',
        'name' => '山田 太郎',
        'email' => 'taro@example.com',
        'company_name' => 'テスト株式会社',
        'message' => 'テンプレートについて相談したいです。',
        'terms_accepted' => '1',
        // testing では reCAPTCHA secret 未設定 = fail-open のため任意 token で通る
        'g-recaptcha-response' => 'test-token',
    ], $overrides);
}

// CACHE_STORE=array (per-test 揮発) のため throttle:inquiry のヒットはテスト間で漏れない

test('問い合わせフォームが表示される', function (): void {
    $this->get('/contact')->assertOk();
});

test('thanks ページは直接 GET でも 200 (PII なし固定表示)', function (): void {
    $this->get('/contact/thanks')->assertOk();
});

test('正当な問い合わせが保存され、通知 2 通が queue される (同意の証跡つき)', function (): void {
    Mail::fake();

    $response = $this->post('/contact', validInquiryPayload());

    $response->assertRedirect(route('contact.thanks'));

    $inquiry = Inquiry::whereBlind('email', 'inquiry_email_index', 'taro@example.com')->firstOrFail();
    expect($inquiry->status)->toBe(InquiryStatus::Open);
    expect($inquiry->name)->toBe('山田 太郎');
    expect($inquiry->company_name)->toBe('テスト株式会社');
    expect($inquiry->terms_accepted_at)->not->toBeNull();
    expect($inquiry->consent_version)->toBe(config()->string('legal.consent_version'));

    // 運営宛 (PII あり) + 送信者宛 ack (固定文面) の 2 通
    Mail::assertQueued(InquiryReceivedMail::class, function (InquiryReceivedMail $mail): bool {
        return $mail->hasTo(config()->string('legal.inquiry_recipient'));
    });
    Mail::assertQueued(InquiryAcknowledgementMail::class, function (InquiryAcknowledgementMail $mail): bool {
        return $mail->hasTo('taro@example.com');
    });
});
```

### tests/Feature/Organizations/TwoFactorEnforcementTest.php (抜粋 L320-360)
```php
        ->delete('/user/two-factor-authentication')
        ->assertRedirect();

    expect($member->fresh()->two_factor_secret)->toBeNull();
});

// ──────────────────────────── 管理者によるメンバー 2FA リセット ────────────────────────────

test('Owner がメンバーの 2FA を解除: secret 消去 + 本人通知 + 監査記録 (理由込み)', function (): void {
    Notification::fake();
    [$organization, $owner] = tfeCreateOrganization();
    $member = tfeAddMember($organization, 'enabled');

    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->from(route('organizations.settings', $organization))
        ->delete(tfeResetUrl($organization, $member), ['reason' => '端末紛失によるロックアウト救済'])
        ->assertRedirect(route('organizations.settings', $organization))
        ->assertSessionHas('success');

    expect($member->fresh()->two_factor_secret)->toBeNull();
    expect($member->fresh()->two_factor_confirmed_at)->toBeNull();

    Notification::assertSentTo($member, TwoFactorResetSecurityNotification::class);

    $event = SecurityAuditEvent::query()
        ->where('event_type', 'org_member_two_factor_reset')
        ->sole();
    expect($event->user_id)->toBe($member->id);
    expect($event->metadata)->toMatchArray([
        'organization_id' => $organization->id,
        'actor_user_id' => $owner->id,
        'reason' => '端末紛失によるロックアウト救済',
    ]);
});

test('鮮度切れの Owner のリセットは recent-auth confirm へ 302 (解除されない)', function (): void {
    [$organization, $owner] = tfeCreateOrganization();
    $member = tfeAddMember($organization, 'enabled');

    $this->actingAs($owner)
```
