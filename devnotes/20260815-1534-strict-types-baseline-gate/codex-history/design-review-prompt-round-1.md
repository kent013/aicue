# アプリの使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
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

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (解析対象は app / config / database / routes)
- Pest テストフレームワーク (Unit / Feature は RefreshDatabase + --parallel、Architecture はファイル走査中心で DB 不使用)
- 本件は**テストとフレームワーク骨組みファイルだけを触る静的検査の追加**であり、アプリの振る舞いは変えない

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)。特に token 走査の判定器が正しいか
2. 既存コードとの整合性 (命名規約、既存 tests/Support 走査器 + tests/Unit/Architecture 自己検査の作法)
3. PHPStan level 10 適合性
4. テスト計画の網羅性 (各施策に Pest テスト。負の対照による検出力の裏取りが十分か)
5. 副作用・後退リスク (config / bootstrap / public/index.php への declare 追加)
6. 波及変更の網羅性
7. セキュリティ (本件は静的検査だが、gate が fail-open になる経路が無いか)
8. 走査域・免除機構の設計判断 (追跡下 PHP 全数 / baseline を持たない / 判定器を実効性の下界にする)
9. 既存テスト `NoNonCompoundGlobalUseTest` からの母集団列挙の切り出しが安全か

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: strict-types-baseline-gate (declare(strict_types=1) の全数強制)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。解析対象は `app` / `config` / `database` / `routes`
- **Pest** テストフレームワーク (`composer test`)
- **RefreshDatabase** + `--parallel` 並列実行 (`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止)
- テストデータは必ず Factory で生成 (本設計は静的検査のみで DB を使わない)
- アーリーリターン推奨 / コードフォーマットは `composer fix` (Pint)
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260815-1534-strict-types-baseline-gate/conceptual-design.md`
- 概念設計レビュー: 同ディレクトリ `conceptual-review-round-1.md` (APPROVED) /
  `codex-history/conceptual-review-decisions-round-1.md`

## 前提となる実測値 (HEAD, 2026-08-15)

| 測定 | 値 |
|------|-----|
| git 追跡下の `*.php` | 1567 本 |
| うち `*.blade.php` | 24 本 |
| 走査母集団 (`*.php` − `*.blade.php`) | **1543 本** |
| 母集団のうち `declare(strict_types=1)` を欠くもの | **32 本** |

未宣言 32 本の内訳:

```
app/Http/Controllers/Controller.php
app/Models/Permission.php
app/Models/Role.php
app/Models/Team.php
bootstrap/app.php
bootstrap/providers.php
config/app.php  config/audit.php  config/auth.php  config/cache.php
config/cashier.php  config/ciphersweet.php  config/database.php  config/filesystems.php
config/fortify.php  config/laratrust.php  config/logging.php  config/mail.php
config/mcp.php  config/passport.php  config/prism.php  config/queue.php
config/services.php  config/session.php
database/migrations/0001_01_01_000000_create_users_table.php
database/migrations/0001_01_01_000001_create_cache_table.php
database/migrations/0001_01_01_000002_create_jobs_table.php
database/migrations/2026_06_11_071031_add_two_factor_columns_to_users_table.php
database/migrations/2026_06_11_071055_create_blind_indexes_table.php
database/migrations/2026_06_11_073836_laratrust_setup_tables.php
database/migrations/2026_06_11_100000_create_admin_users_table.php
public/index.php
```

`app/` の 4 本は laravel-claude-template が `laravel-claude-template@f11048b` で
是正済みのものと同一集合である (テンプレート追従の取り込み漏れ)。

## PHP 8.4 での実測 (判定器仕様の根拠)

`function f(int $x) {} f("1");` が TypeError になるかで「厳密化が実際に効いたか」を測った。

| 先頭に置いた形 | 実際の効果 |
|----------------|-----------|
| `declare(strict_types=1);` | 効く |
| `declare(strict_types=01);` / `0x1` / `0b1` | 効く |
| `DECLARE(STRICT_TYPES=1);` | 効く (キーワード・指令名とも大小無視) |
| `declare(ticks=1, strict_types=1);` / `declare(strict_types=1, ticks=1);` | 効く |
| `declare(strict_types=(1));` | 効く |
| `declare(strict_types=1, strict_types=0);` / 逆順 | 効く (1 が 1 度でもあれば実効) |
| `declare(ticks=1); declare(strict_types=1);` | 効く (2 文目でも受理される) |
| `declare(strict_types=0);` | 効かない |
| `<?php` の直後に `/* コメント */` を挟む | 効く |
| shebang `#!/usr/bin/env php` の次行 | 効く (CLI が shebang を剥がす) |
| `declare(strict_types=true);` / `"1"` / `0+1` | Fatal error (実行不能) |
| `declare(strict_types=1) { … }` (ブロック形) | Fatal error |
| `namespace A;` の後 / `<?php` の前に文字がある | Fatal error |
| `declare(strict_types=1,);` | Parse error |

**判定器はこの真値の下界にする**。受理するのは正準形だけとし、実効だが珍しい形は未宣言側へ倒す。
逆向きの乖離 (判定器が宣言済みと言うのに実効でない) は 1 件も許さない。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 走査対象 (追跡下 PHP) の列挙器を `tests/Support/` へ切り出す | `tests/Support/TrackedPhpSourceFiles.php` (新規) / `tests/Unit/Architecture/TrackedPhpSourceFilesTest.php` (新規) / `tests/Architecture/NoNonCompoundGlobalUseTest.php` (付け替え) | High |
| 2 | 宣言判定器と実測照合器 | `tests/Support/StrictTypesDeclarationScanner.php` (新規) / `tests/Support/StrictTypesRuntimeProbe.php` (新規) / `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` (新規) | High |
| 3 | gate 本体 (未宣言 0 件・deny-by-default・免除機構なし) | `tests/Architecture/StrictTypesDeclarationGateTest.php` (新規) | High |
| 4 | 未宣言 32 本への `declare(strict_types=1)` 追加 | `app/` 4 / `config/` 18 / `database/migrations/` 7 / `bootstrap/` 2 / `public/index.php` | High |
| 5 | 規約と逸脱の記録 | `AGENTS.md` / `docs/template-divergence.md` | Medium |

**実装順序**: 2 → 1 → 3 (赤を確認) → 4 (緑にする) → 5。
施策 3 の gate は施策 4 の前に**必ず 32 本を列挙して赤くなること**を実測する (テストファースト)。

---

## 施策 1: 走査対象 (追跡下 PHP) の列挙器を `tests/Support/` へ切り出す

### 変更箇所

- 新規: `tests/Support/TrackedPhpSourceFiles.php`
- 新規: `tests/Unit/Architecture/TrackedPhpSourceFilesTest.php`
- 変更: `tests/Architecture/NoNonCompoundGlobalUseTest.php` (L41-72 の
  `nonCompoundUseScanTargets()` を共用列挙器の呼び出しへ差し替え)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `NoNonCompoundGlobalUseTest` の**内部ヘルパのみ**差し替える。
  test 名・assertion・失敗メッセージ書式は変えない (既存テストの削除・上書きに当たらない)

### 現行コード (`tests/Architecture/NoNonCompoundGlobalUseTest.php` L41-72、要約)

```php
function nonCompoundUseScanTargets(): array
{
    $root = base_path();
    $process = new Process(['git', 'ls-files', '-z', '*.php'], $root);
    $process->run();
    if (! $process->isSuccessful()) { throw new RuntimeException('git ls-files の実行に失敗しました …'); }
    $files = [];
    foreach (explode("\0", $process->getOutput()) as $relative) {
        if ($relative === '' || str_ends_with($relative, '.blade.php')) { continue; }
        $absolute = $root.'/'.$relative;
        if (! is_file($absolute)) { continue; }
        $files[] = ['absolute' => $absolute, 'relative' => $relative];
    }

    return $files;
}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * git 追跡下の PHP ソースファイル (blade を除く) を列挙する純関数。
 *
 * ★同じ列挙を 2 本持たない。`NoNonCompoundGlobalUseTest` (既存) と
 *   `StrictTypesDeclarationGateTest` (本設計) の両方がここを使う。
 * ★git 管理下に限ることで vendor/ node_modules/ .claude/worktrees/ storage/ を
 *   **自動的に**除外できる (明示 exclude リストを保守しなくてよい)。
 * ★`*.blade.php` は**規則の段階で母集団に入れない**。blade はテンプレートであり
 *   先頭が PHP コードではない (PHP としては `<?php` より前に出力が始まる) ため、
 *   PHP ソースファイルに課す規約の対象にならない。免除ではなく対象外である。
 * ★**保証しないもの**: (a) 未追跡 (git add 前) のファイルは列挙されない。
 *   gate が守る境界は commit / CI であり、そこでは必ず追跡下にある。
 *   (b) 拡張子が `.php` でない PHP ファイル (`artisan` など) は列挙されない。
 *   (c) git が無い環境では**沈黙して空を返さず例外にする** (fail-open 防止)。
 * ★利用側は「自分が期待する母集団」を必ず pin すること (床値 + 代表パス)。
 *   共用したことで一方の都合の変更が他方の走査域を黙って変えるのを防ぐ。
 */
final class TrackedPhpSourceFiles
{
    /**
     * @param  string  $root  git worktree の root (絶対パス)
     * @return list<array{absolute: string, relative: string}> relative の昇順
     */
    public static function all(string $root): array
    {
        $process = new Process(['git', 'ls-files', '-z', '--', '*.php'], $root);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
                .$process->getErrorOutput()
            );
        }

        $files = [];
        foreach (explode("\0", $process->getOutput()) as $relative) {
            if ($relative === '' || str_ends_with($relative, '.blade.php')) {
                continue;
            }
            $absolute = $root.'/'.$relative;
            if (! is_file($absolute)) {
                continue; // 削除済みだが index に残っている等
            }
            $files[] = ['absolute' => $absolute, 'relative' => $relative];
        }

        usort($files, fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));

        return $files;
    }
}
```

`NoNonCompoundGlobalUseTest` 側は次の 1 行に置き換える (ヘルパ関数ごと削除する):

```php
foreach (TrackedPhpSourceFiles::all(base_path()) as $target) { … }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`list<array{absolute: string, relative: string}>`)
- [x] null 安全 (`Process::getOutput()` は string を返す。`is_file()` で存在確認)
- [x] 配列返却だが DTO 化しない — テスト専用の内部ヘルパであり、
      既存 `tests/Support/*Scanner` と同じ形 (アプリの DTO 規約の対象外)
- [x] Generics の型パラメータ: `usort` の callable に型宣言を書く

### テスト計画

`tests/Unit/Architecture/TrackedPhpSourceFilesTest.php` (新規)。
**一時 git リポジトリを作って列挙結果を固定する** (実リポジトリでは負の対照が作れないため):

- [ ] `sys_get_temp_dir()` に一意な作業ディレクトリを作り (`--parallel` 衝突回避のため
      `uniqid()` ではなく `tempnam()` で確保した名前を使う)、`git init -q` する
- [ ] 次を置いて `git add` する: `a.php` / `sub/b.php` / `c.blade.php` / `d.txt`
- [ ] さらに `untracked.php` を**追跡せずに**置く
- [ ] `tracked-then-deleted.php` を add した後にファイルだけ削除する (index には残る)
- [ ] 正の対照: 戻り値の `relative` が `['a.php', 'sub/b.php']` **完全一致**であること
- [ ] 負の対照 1: blade (`c.blade.php`) が含まれないこと
- [ ] 負の対照 2: 未追跡 (`untracked.php`) が含まれないこと
- [ ] 負の対照 3: 拡張子違い (`d.txt`) が含まれないこと
- [ ] 負の対照 4: index に残った削除済みファイルが含まれないこと
- [ ] 異常系: git worktree でないディレクトリを渡すと `RuntimeException` を投げること
      (**空配列を返して黙らないこと** = fail-open 防止)
- [ ] 実リポジトリに対する健全性: `TrackedPhpSourceFiles::all(base_path())` が
      1400 件以上を返し、`tests/Support/TrackedPhpSourceFiles.php` 自身を含むこと
- [ ] 後片付けは `finally` で行い、テスト失敗時も一時ディレクトリを残さない
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **既存 gate の走査域が変わる**: 切り出し時に挙動を変えないため、`git ls-files -z '*.php'` /
  blade 除外 / `is_file` 確認の 3 点をそのまま移す。`--` を足したのは
  パス指定であることを明示するためで、結果集合は変わらない (実測で件数一致を確認する)
- **並列実行での一時ディレクトリ衝突**: `tempnam()` 由来の一意名で回避する
- **`usort` の追加で `NoNonCompoundGlobalUseTest` の失敗メッセージ順序が変わる**:
  順序は assertion に影響しない (件数 0 を要求するテスト) ため問題ない

---

## 施策 2: 宣言判定器と実測照合器

### 変更箇所

- 新規: `tests/Support/StrictTypesDeclarationScanner.php`
- 新規: `tests/Support/StrictTypesRuntimeProbe.php`
- 新規: `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 新規のみ

### 変更後コード (判定器)

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP ソースが冒頭で `declare(strict_types=1);` を宣言しているかを判定する純関数。
 *
 * ★正規表現・部分文字列判定にしない。コメントや文字列リテラル中の
 *   `declare(strict_types=1)` という**記述**を宣言と誤認するため
 *   (負の対照で固定する)。走査は `PhpTokenScan::normalize()` (空白・コメント除去済み) に対して行う。
 *
 * ★**受理するのは正準形だけ**である:
 *     <?php  declare ( strict_types = 1 ) ;
 *   (キーワード・指令名の大小は無視。空白とコメントは透過)
 *   PHP 8.4 の実測では `01` / `0x1` / `0b1` / `declare(ticks=1, strict_types=1)` /
 *   同一 declare 内の重複指定 / 2 文目の declare も**実際には厳密化が効く**が、
 *   本判定器はこれらを**未宣言側に倒す** (安全側の乖離)。
 *   本 gate は PHP の意味論の再現ではなく、リポジトリ内の表記を 1 つに揃える規約検査だからである。
 *
 * ★**逆向きの乖離は 1 件も許さない** — 「判定器は宣言済みと言うのに実際は厳密化されない」形が
 *   あると gate が嘘をつく。`StrictTypesDeclarationScannerTest` が
 *   `StrictTypesRuntimeProbe` (別プロセスで実際に型不一致が起きるかを測る) と
 *   突き合わせ、乖離の向きを機械的に固定する。
 */
final class StrictTypesDeclarationScanner
{
    /** 正準形の宣言 (失敗メッセージで提示する)。 */
    public const string CANONICAL_DECLARATION = 'declare(strict_types=1);';

    public static function declaresStrictTypes(string $phpSource): bool
    {
        $tokens = PhpTokenScan::normalize($phpSource);

        // [0] T_OPEN_TAG / [1] T_DECLARE / [2] '(' / [3] T_STRING(strict_types)
        // [4] '=' / [5] T_LNUMBER('1') / [6] ')' / [7] ';'
        if (count($tokens) < 8) {
            return false;
        }
        if ($tokens[0]['id'] !== T_OPEN_TAG || $tokens[1]['id'] !== T_DECLARE) {
            return false; // 先頭に inline HTML / shebang / 他の文があれば未宣言
        }
        if ($tokens[2]['text'] !== '(' || $tokens[3]['id'] !== T_STRING) {
            return false;
        }
        if (mb_strtolower($tokens[3]['text']) !== 'strict_types') {
            return false;
        }
        if ($tokens[4]['text'] !== '=' || $tokens[5]['id'] !== T_LNUMBER || $tokens[5]['text'] !== '1') {
            return false; // 値 0 / 01 / true / 式 はすべて未宣言側
        }

        return $tokens[6]['text'] === ')' && $tokens[7]['text'] === ';'; // ブロック形 `{` は未宣言側
    }
}
```

### 変更後コード (実測照合器)

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * 「ある冒頭の書き方で PHP の厳密な型検査が実際に効くか」を**別プロセスで実測**する。
 *
 * ★判定器の自己検査で使う。表に書いた真値が PHP の版で変わっても、
 *   実測との突き合わせがあれば「判定器が実効性の下界である」ことは崩れない。
 * ★書き込み先はシステムの一時ディレクトリで、リポジトリ内には何も残さない。
 */
final class StrictTypesRuntimeProbe
{
    /**
     * @param  string  $header  `<?php` を含むファイル冒頭 (末尾に改行不要)
     * @return bool 厳密化が実際に効いたか (Fatal / Parse error は false)
     */
    public static function strictTypesInEffect(string $header): bool
    {
        $path = tempnam(sys_get_temp_dir(), 'strict-probe-').'.php';
        $probe = <<<'PHP'

            function probeTarget(int $value): int { return $value; }
            try { probeTarget("1"); echo 'WEAK'; } catch (\TypeError $e) { echo 'STRICT'; }
            PHP;

        try {
            if (file_put_contents($path, $header."\n".$probe) === false) {
                throw new RuntimeException("実測用の一時ファイルを書けませんでした: {$path}");
            }

            $process = new Process([PHP_BINARY, '-d', 'error_reporting=E_ALL', $path]);
            $process->run();

            return trim($process->getOutput()) === 'STRICT';
        } finally {
            @unlink($path);
        }
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`bool`)
- [x] null 安全 (`tempnam()` の false、`file_put_contents()` の false を扱う)
- [x] DTO 返却は不要 (真偽値 1 つ)
- [x] `PhpTokenScan::normalize()` の戻り型 (`list<array{id: int|null, text: string, line: int}>`) に
      沿って添字アクセスする

### テスト計画

`tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` (新規)。
**検体表を 1 つ持ち、正の対照・負の対照・実測との突き合わせを同じ表から回す**:

- [ ] 正の対照: `<?php declare(strict_types=1);` / 空行入り / 直前にコメント /
      大文字 (`DECLARE(STRICT_TYPES=1);`) / 空白揺れ (`declare ( strict_types = 1 ) ;`) が
      すべて `true` になる
- [ ] 負の対照 1 (**この scanner の存在理由**): コメント内の
      `// declare(strict_types=1);` だけがある源が `false` になる
- [ ] 負の対照 2: 文字列リテラル `$x = 'declare(strict_types=1);';` だけがある源が `false`
- [ ] 負の対照 3: 値 0 (`declare(strict_types=0);`) が `false`
- [ ] 負の対照 4: ブロック形 (`declare(strict_types=1) { }`) が `false`
- [ ] 負の対照 5: 後置 (`namespace A; declare(strict_types=1);`) が `false`
- [ ] 負の対照 6: 別指令のみ (`declare(ticks=1);`) が `false`
- [ ] 負の対照 7: 宣言なし (`<?php` のみ) が `false`
- [ ] 負の対照 8: `<?php` の前に文字がある (`X<?php declare(strict_types=1);`) が `false`
- [ ] 負の対照 9: 配列リテラル (`['strict_types' => 1]`) が `false`
- [ ] 安全側の乖離 (実効だが未宣言と判定する) を**明示的に固定**: `01` / `0x1` / `0b1` /
      `declare(ticks=1, strict_types=1)` / `declare(strict_types=(1))` /
      同一 declare 内の重複指定 / 2 文目の declare が `false` になる
- [ ] **実測との突き合わせ**: 上記全検体について `StrictTypesRuntimeProbe::strictTypesInEffect()` を
      呼び、「判定器が `true` なら実測も必ず `true`」を要求する
      (= 判定器は実効性の下界。**逆向きの乖離 0 件**を機械的に固定する)
- [ ] 実測器そのものの健全性: 宣言なしの源で実測が `false`、正準形で `true` になる
      (実測器が常に同じ値を返す壊れ方を検出する)
- [ ] 実ファイルでの疎通: `tests/Support/PhpTokenScan.php` を読んで `true`、
      `resources/views/app.blade.php` を読んで `false` になる
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **別プロセス起動のコスト**: 検体 20 件前後 × 1 プロセス ≒ 1 秒未満を見込む。
  超えるようなら実測との突き合わせを「乖離の向きが問題になる検体」に絞る
  (絞ってもこのテストの主張は保てる)
- **`PHP_BINARY` が使えない実行環境**: Process が失敗したら実測器は例外にする
  (silent に `false` を返して緑にしない)
- **判定器が厳しすぎて誤って赤くなる**: 実測で本リポジトリの 1543 本中 1511 本が
  正準形であることを確認済み。残り 32 本は施策 4 で正準形にする

---

## 施策 3: gate 本体

### 変更箇所

- 新規: `tests/Architecture/StrictTypesDeclarationGateTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし / 既存テストの変更: なし

### 変更後コード (骨子)

```php
<?php

declare(strict_types=1);

use Tests\Support\StrictTypesDeclarationScanner;
use Tests\Support\TrackedPhpSourceFiles;

/*
 * Architecture invariant: **git 追跡下の PHP ソース全数**が冒頭で
 * `declare(strict_types=1);` を宣言している。
 *
 * なぜ全数か: PHP は既定で "1" と 1 を黙って行き来させる。宣言を欠くファイルが 1 枚あると
 * そこだけ暗黙変換が復活し、取り違えが実行時まで表に出ない。容量予約 (bytes) や
 * チケット枚数のように数値と文字列の取り違えが金額・容量の誤りになる領域を持つため、
 * 「どこか 1 枚だけ緩い」状態を構造的に作らない。
 *
 * **免除の登録簿 (baseline / allow-list) を持たない**。導入時点の未宣言 32 本を同一変更で
 * 是正して 0 件から始めるので、登録簿は 1 件も守らないまま複雑さだけを足すことになる
 * (`QueueDispatchAtomicityInventoryTest` と同じ形 = 免除機構そのものが無い)。
 * **どうしても宣言できないファイルが将来出た場合も、なし崩しに allow-list を足さない。
 * 設計レビュー (app-design) を通してから機構を新設すること。**
 *
 * 走査域 (追跡下 `*.php` − `*.blade.php`) の定義と限界は
 * `Tests\Support\TrackedPhpSourceFiles` の docblock が正本。
 * 判定の正準形と「実効だが受理しない形」は `Tests\Support\StrictTypesDeclarationScanner` が正本。
 *
 * 家系との関係: laravel-claude-template は `StrictTypesBaselineInvariantTest` で
 * **app のみ**を走査し空の baseline を持つ。本 gate は走査域が広く baseline を持たない
 * (`docs/template-divergence.md` D15)。
 */

test('git 追跡下の PHP は全数 declare(strict_types=1) を宣言している', function (): void {
    $targets = TrackedPhpSourceFiles::all(base_path());

    // 空振り防止 1: 走査対象が 0 件なら赤 (走査域が消えても緑にならないようにする)
    expect($targets)->not->toBeEmpty();

    // 空振り防止 2: 母集団の床値 (実測 1543)。走査域が黙って狭まると赤くなる
    expect(count($targets))->toBeGreaterThanOrEqual(1400);

    // 空振り防止 3: 代表ディレクトリが母集団に含まれること
    $prefixes = ['app/', 'tests/', 'config/', 'database/', 'routes/', 'bootstrap/', 'public/'];
    foreach ($prefixes as $prefix) { /* いずれかの relative が prefix で始まることを要求 */ }

    // 空振り防止 4: 判定器が壊れていない (自己検査ファイルを消されても gate 単独で気付く)
    expect(StrictTypesDeclarationScanner::declaresStrictTypes("<?php\n"))->toBeFalse();
    expect(StrictTypesDeclarationScanner::declaresStrictTypes("<?php\n\ndeclare(strict_types=1);\n"))->toBeTrue();

    $undeclared = [];
    foreach ($targets as $target) {
        $source = file_get_contents($target['absolute']);
        if ($source === false) {
            throw new RuntimeException("読み取れないファイルがあります: {$target['relative']}");
        }
        if (! StrictTypesDeclarationScanner::declaresStrictTypes($source)) {
            $undeclared[] = $target['relative'];
        }
    }

    expect($undeclared)->toBe([], /* 下記の失敗メッセージ */);
});
```

### 失敗メッセージの仕様

```
declare(strict_types=1) を欠く PHP ファイルがあります (N 件):
  - <相対パス>
  …
直し方: 各ファイルの <?php の直後に次の 1 行を置く (前に他の文・出力を置かない):
  declare(strict_types=1);
補足 1: 01 / 0x1 / declare(ticks=1, strict_types=1) 等は PHP としては有効だが、
        本リポジトリは表記を上の正準形 1 つに揃えるため受理しない。
補足 2: `php artisan vendor:publish` の直後は骨組み由来ファイルが宣言を失う。
        publish した内容を確認したうえで宣言を足してから commit すること。
補足 3: 免除の登録簿は意図的に持たない。宣言できない事情ができたときは
        allow-list を足す前に設計レビューを通すこと。
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (test closure は `void`)
- [x] null 安全 (`file_get_contents()` の false を例外にする = 無音 skip しない)
- [x] DTO 返却なし (テスト)
- [x] Generics: `TrackedPhpSourceFiles::all()` の戻り型注釈をそのまま使う
- 注: `tests/` は PHPStan 解析対象外 (`app` / `config` / `database` / `routes` のみ) だが、
  型注釈は既存テストと同水準で書く

### テスト計画

gate 自体がテストである。**テストファーストの手順を実測で残す**:

- [ ] 段 1: 施策 4 の前に gate を走らせ、**未宣言 32 本を列挙して赤くなる**ことを確認する
      (件数と一覧が上表と一致すること)
- [ ] 段 2: 施策 4 で宣言を足し、gate が緑になることを確認する
- [ ] 段 3: 任意の 1 ファイルから宣言を一時的に外すと再び赤くなることを確認する
      (実施記録を `devnotes/.../notes-implementation.md` に残す。ファイルは元に戻す)
- [ ] 段 4: 床値の pin が効くことを確認する (走査域を一時的に `app/` へ狭めると赤になる)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **1543 ファイルの tokenize による実行時間**: 実測して 5 秒を超えるようなら、
  判定を打ち切り可能な形 (先頭 8 トークンで決着) にしてあるので tokenize 自体を
  `PhpToken::tokenize()` に変えるなどの調整を行う。**先読みバイト数で切る最適化は採らない**
  (長い冒頭コメントがあるファイルで偽陽性になるため)
- **git が無い環境で走らせると例外**: 意図どおり (fail-open 防止)。
  既存 `NoNonCompoundGlobalUseTest` も同じ前提に立っている

---

## 施策 4: 未宣言 32 本への `declare(strict_types=1)` 追加

### 変更箇所

- `app/Http/Controllers/Controller.php` / `app/Models/Team.php` / `app/Models/Role.php` /
  `app/Models/Permission.php`
- `config/` 18 本 / `database/migrations/` 7 本 / `bootstrap/app.php` / `bootstrap/providers.php` /
  `public/index.php`

いずれも `<?php` の直後に空行 1 つを挟んで `declare(strict_types=1);` を置く (Pint の既定書式)。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし (既存テストの期待値に影響しない)

### 現行コード (`app/Models/Team.php`)

```php
<?php

namespace App\Models;

use Laratrust\Models\Team as LaratrustTeam;

class Team extends LaratrustTeam
{
    protected $fillable = ['name', 'display_name', 'description'];
    public $guarded = [];
}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Laratrust\Models\Team as LaratrustTeam;

class Team extends LaratrustTeam
{
    protected $fillable = ['name', 'display_name', 'description'];
    public $guarded = [];
}
```

### 副作用の検討 (vendor 由来の基底を継ぐ 4 本)

`declare(strict_types=1)` は **ファイル単位**で、そのファイル**の中に書かれた呼び出し**にだけ効く。
継承関係を通じて親クラス (Laratrust の `Role` / `Permission` / `Team`) の挙動を変えることはない
(親のファイルには宣言が無いままなので、親の中の呼び出しは緩いままである)。

対象 4 本の中身を実読した結果:

| ファイル | 中身 | ファイル内の関数呼び出し | 影響 |
|----------|------|-------------------------|------|
| `app/Http/Controllers/Controller.php` | 空の abstract class | なし | なし |
| `app/Models/Team.php` | `$fillable` / `$guarded` の宣言のみ | なし | なし |
| `app/Models/Role.php` | 同上 | なし | なし |
| `app/Models/Permission.php` | 同上 | なし | なし |

**呼び出しが 1 つも無いため、この 4 本については挙動の変化が原理的に起きない**。
ただし「継承先が strict になる」といった誤解を避けるため、この事実は実装時に
`composer test` + `composer phpstan` の green で裏を取る。

### 副作用の検討 (骨組み由来 28 本)

- `config/` 18 本: 実測では関数呼び出しの引数に `env()` を渡している箇所はすべて
  `(string)` cast 済みである (`explode(',', (string) env('LOG_STACK', 'single'))` 等)。
  `config` は PHPStan の解析対象なので、暗黙変換に頼った呼び出しがあれば
  `composer phpstan` が静的に検出する
- `database/migrations/` 7 本: `Schema::` / `Blueprint` の呼び出しのみ。PHPStan 解析対象
- `bootstrap/` 2 本 + `public/index.php`: PHPStan 解析対象外。起動そのもので確認する

**保証範囲を誇張しない**: 静的解析とテストで覆えるのは主要経路までである。
動的呼び出し・container 解決・env の値によって分岐する経路は静的解析の外にある。

### PHPStan 適合チェック

- [x] 新規エラーが出た場合は**明示 cast / 値の正規化 / DTO 化で直す**
- [x] 型を緩めて黙らせる (widen)・`@phpstan-ignore-line`・baseline 化は**行わない** (禁止事項 2)
- [x] `composer phpstan` が `No errors` であること

### テスト計画

- [ ] 施策 3 の gate が緑になること (これが本施策の主契約)
- [ ] `composer test` 全数 green (`bootstrap/` の 2 本は全レーンの起動が通る)
- [ ] `composer phpstan` が `No errors` (`app` / `config` / `database` / `routes` = 29 本を覆う)
- [ ] `composer test:browser` が green (`public/index.php` は実サーバ起動でのみ通る経路)
- [ ] `php -l public/index.php` / `php -l bootstrap/app.php` / `php -l bootstrap/providers.php`
- [ ] `php artisan route:list` / `php artisan config:clear` が成功する
      (config 評価時の関数呼び出しが厳密化された状態で通ることの確認。
      どちらも読み取り側で dev DB へ破壊操作をしない)
- [ ] `vendor/bin/pint --test` が green (宣言の書式が Pint 既定と一致する)

### リスク

- **`vendor:publish` で宣言が失われる**: 骨組み由来ファイルを再 publish すると宣言が消え、
  gate が赤くなる。これは検出であって事故ではない。手順は失敗メッセージと
  `docs/template-divergence.md` に書く
- **`config/` への宣言追加で新規 PHPStan エラー**: 出た場合は明示 cast で直す。
  直せない規模なら該当ファイルだけ設計へ差し戻す (widen も baseline 化もしない)
- **テンプレート追従時の衝突**: テンプレートも同じ 4 本を是正済みなので `app/` は衝突しない。
  `config/` はテンプレート側が未宣言のままなので、取り込み時に 1 行の衝突が起きうる

---

## 施策 5: 規約と逸脱の記録

### 変更箇所

- `AGENTS.md` 実装規約 (先頭行)
- `docs/template-divergence.md` (D15 を追加)

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: なし (`AGENTS.md` の検証コマンド節は触らないため
  `verification-commands-doc-sync.test.ts` に影響しない)

### 現行コード (`AGENTS.md` 実装規約)

```markdown
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く(Service 委譲)、
  transaction は Service 内。保護キーは forceFill / relation で明示代入
```

### 変更後コード

```markdown
- `declare(strict_types=1)` + 日本語コメント。**宣言は git 追跡下の PHP 全数が対象**で、
  免除の登録簿は持たない(`StrictTypesDeclarationGateTest` が deny-by-default で強制。
  `*.blade.php` は PHP ソースではないため対象外)。Controller は薄く(Service 委譲)、
  transaction は Service 内。保護キーは forceFill / relation で明示代入
```

### `docs/template-divergence.md` D15 の骨子

```markdown
## D15 ✅ strict_types gate の走査域を追跡下 PHP 全数にし、未宣言一覧を持たない

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| テスト名 | `StrictTypesBaselineInvariantTest` | `StrictTypesDeclarationGateTest` |
| 走査域 | `app/` のファイル走査 | **git 追跡下の `*.php` − `*.blade.php`** |
| 未宣言一覧 (baseline) | 常に空を契約として保持 | **持たない** (免除機構そのものが無い) |
| 判定器 | 構造判定 (値は数値リテラル 1 の完全一致) | 構造判定 + **実測照合器との突き合わせ** |

### なぜ正当な差分か (logic-driven)
…app に限ると config / database / bootstrap / public の未宣言 28 本が規約の外に残る。
実測でこの 28 本は 1 行の追加だけで解消でき、うち 25 本は PHPStan の解析対象でもある…

### 揃えている不変条件 (これは保証し続ける)
> 「宣言を欠く PHP ファイルが新しく増えない」
- 走査域が広いので、テンプレートが保証する `app/` の範囲は**包含している**
- 空の baseline と本アプリの「登録簿なし」は、守っている集合が同じ (未宣言 0 件)
- テンプレート取り込みで `StrictTypesBaselineInvariantTest` が入ってきた場合は、
  **2 本立てにせず本 gate へ統合する** (同じ事実を 2 箇所で宣言しない)

### 保証しないもの
- `artisan` など拡張子が `.php` でない PHP ファイル / 未追跡ファイル
- 実効ではあるが正準形でない書き方 (`01` 等) は**受理しない** (安全側の乖離)
- `vendor:publish` 直後に宣言が失われることは防げない (検出はする)

### 関連
- 実装: `tests/Architecture/StrictTypesDeclarationGateTest.php` /
  `tests/Support/StrictTypesDeclarationScanner.php` / `tests/Support/TrackedPhpSourceFiles.php`
- 設計: `devnotes/20260815-1534-strict-types-baseline-gate/`
- 家系の裁定: AG-010 (2026-08-05)「テンプレートへ還流し家系の標準装備とする」
```

### テスト計画

- [ ] `docs/template-divergence.md` の D 番号が既存の最大値 (D14) の次であること (目視 + grep)
- [ ] `AGENTS.md` の検証コマンド節 (`VERIFICATION_COMMANDS` マーカー) を触っていないこと
      (`pnpm test` の `verification-commands-doc-sync.test.ts` が green)
- [ ] `pnpm test` / `pnpm lint` に影響がないこと

### リスク

- **文書だけが増えて実効が無い**: 実効は施策 3 の gate が持つ。文書はその説明であり、
  gate の失敗メッセージからも同じ手順に到達できるようにしてある
- **D 番号の衝突**: 並行する設計が同時に D15 を使う可能性がある。実装時に
  `docs/template-divergence.md` の最大値を取り直して採番する

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規テスト 5 本 + 既存テスト 1 本の内部ヘルパ差し替え + 32 本への 1 行追加で完結する。アプリの振る舞いを変えず、他施策と共有する状態も持たない |
| 競合リスク | (a) `config/` `bootstrap/` を触る他タスクと行単位で競合しうる (追加位置がファイル冒頭なので解決は容易)。(b) `docs/template-divergence.md` の D 番号は実装時に採り直す。(c) `tests/Architecture/NoNonCompoundGlobalUseTest.php` を触る他タスクとは競合する (現状そのようなタスクは無い) |

## この設計が保証しないもの (誇張しない)

- **未追跡ファイルは見ない**。gate が守る境界は commit / CI である
- **`artisan` を見ない**。拡張子が `.php` でないため母集団に入らない
  (実測では shebang があっても宣言は有効なので、将来含める余地はある)
- **`*.blade.php` を見ない**。テンプレートであり PHP ソースファイルではない
- **宣言の有無だけを見る**。型の緩さそのもの (level 10 で検出される型不一致) は PHPStan の担当
- **実効性の完全再現ではない**。実効だが正準形でない書き方は未宣言として落とす (安全側)
- **副作用の検出は主要経路まで**。動的呼び出し・container 解決・env 依存の分岐は静的解析の外
- **`vendor:publish` による宣言の消失は防げない** (次の gate 実行で検出されるだけ)


---

## 関連する現行コード

### tests/Architecture/NoNonCompoundGlobalUseTest.php (L1-72 抜粋 — 母集団列挙の現行実装)
```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Architecture invariant: **namespace 宣言の無い PHP ファイル**の global スコープに
 * 非複合名の `use` を書かない。
 *
 * SoT = PHP の言語仕様。namespace 無しファイルでの非複合 use は
 *   Warning: The use statement with non-compound name 'X' has no effect
 * を出し、**import として何の効果も持たない** (参照は global にフォールバックする)。
 * `use function` / `use const` でも **まったく同じ warning** が出る (実測):
 *   use RuntimeException;   → Warning ...'RuntimeException'...
 *   use function strlen;    → Warning ...'strlen'...
 *   use const PHP_VERSION;  → Warning ...'PHP_VERSION'...
 *   use function Foo\bar;   → (複合名なので正常)
 *
 * なぜ「出力が汚れるだけ」で済ませないか (実測):
 *   - この warning が set_error_handler に届くかは **環境依存** (opcache 状態 /
 *     ファイルの初回コンパイル時点)。同一 devcontainer で「届く」「届かない」両方を観測した
 *   - 届いた場合、Laravel の HandleExceptions::handleError は
 *     `error_reporting() & $level` (本アプリは -1) で **ErrorException を throw する**
 *   - migration は Migrator が実行時に require する = そこで throw されれば
 *     RefreshDatabase が死に **全テストが全滅する**
 * つまり「今日は raw output 汚染で済んでいるが、いつ全滅へ化けてもおかしくない非決定的な地雷」。
 *
 * 走査対象: git 追跡下の *.php (ただし *.blade.php を除く)。
 * git 管理下に限ることで vendor/ node_modules/ .claude/worktrees/ storage/ を
 * **自動的に**除外できる (明示 exclude リストを保守しなくてよい)。
 * **既知の限界**: 未追跡 (git add 前) のファイルは走査されない。gate が守る境界は
 * commit / CI であり、そこでは必ず追跡下にあるため実効性は損なわれない。
 * git 不在は環境不備として silent skip せず fail させる
 * (tests/js/architecture/pages-path-case-invariant.test.ts と同じ作法)。
 *
 * allowlist は設けない: 非複合 global use に正当な用途は存在しない (常に無効な import)。
 */

/**
 * git 追跡下の PHP ソースファイル一覧 (blade を除く)。
 *
 * @return list<array{absolute: string, relative: string}>
 */
function nonCompoundUseScanTargets(): array
{
    $root = base_path();
    $process = new Process(['git', 'ls-files', '-z', '*.php'], $root);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(
            'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
            .$process->getErrorOutput()
        );
    }

    $files = [];
    foreach (explode("\0", $process->getOutput()) as $relative) {
        if ($relative === '' || str_ends_with($relative, '.blade.php')) {
            continue;
        }
        $absolute = $root.'/'.$relative;
        if (! is_file($absolute)) {
            continue; // 削除済みだが index に残っている等
        }
        $files[] = ['absolute' => $absolute, 'relative' => $relative];
    }

    return $files;
}

```

### tests/Support/PhpTokenScan.php (全文 — 再利用する正規化)
```php
<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP ソースの静的走査で共有する `token_get_all()` の正規化 (純関数)。
 *
 * ★同じ正規化を 2 本持たない。`QueuedJobLeaseInventoryTest` (既存) と
 *   `ExternalClientBoundaryScanner` (T126) の両方がここを使う。
 * ★Pest のファイルスコープ関数はテストファイル間で衝突しうるため、
 *   `Tests\Support\QueueLeaseConfig` と同じくクラスの static メソッドへ集約する。
 */
final class PhpTokenScan
{
    /**
     * `token_get_all()` を「空白・コメントを除いた添字連番のリスト」へ正規化する。
     *
     * 単一文字トークン (`{` / `}` / `;` など) は `id => null` で表現し、
     * 行番号は直前トークンの行を引き継ぐ (単一文字トークンは行情報を持たないため)。
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    public static function normalize(string $phpSource): array
    {
        $normalized = [];
        foreach (token_get_all($phpSource) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $normalized[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];

                continue;
            }

            $line = $normalized === [] ? 0 : $normalized[count($normalized) - 1]['line'];
            $normalized[] = ['id' => null, 'text' => $token, 'line' => $line];
        }

        return $normalized;
    }
}

```

### tests/Unit/Architecture/PestTestNameScannerTest.php (全文 — 自己検査の既存作法)
```php
<?php

declare(strict_types=1);

use Tests\Support\PestTestNameScanner;

/*
 * `PestTestNameScanner` の正負両方向を固定する。
 *
 * ★負のコントロール (テスト 2) が本 scanner の存在理由である。委譲先 gate の test 名同定を
 *   単純な `str_contains()` で書くと、test を改名しても旧名がコメントや別のリテラルに残れば
 *   緑になってしまい「その名前の test が実在する」という保証にならない。
 */

test('test 名スキャナ: test() / it() の第 1 引数を抽出する', function (): void {
    $source = <<<'PHP'
    <?php
    test('最初のテスト', function (): void {});
    it('二番目のテスト', function (): void {});
    PHP;

    expect(PestTestNameScanner::names($source))->toBe(['最初のテスト', '二番目のテスト']);
});

test('test 名スキャナ: コメントにだけある名前は抽出しない', function (): void {
    $source = <<<'PHP'
    <?php
    // test('消えた名前', function (): void {});
    /* test('別の消えた名前', function (): void {}); */
    test('生きている名前', function (): void {});
    PHP;

    expect(PestTestNameScanner::names($source))->toBe(['生きている名前']);
});

test('test 名スキャナ: 文字列リテラル中の test( は抽出しない', function (): void {
    $source = <<<'PHP'
    <?php
    $x = "test('偽物')";
    PHP;

    expect(PestTestNameScanner::names($source))->toBe([]);
});

test('test 名スキャナ: メソッド呼び出しの test() は抽出しない', function (): void {
    $source = <<<'PHP'
    <?php
    $object->test('偽物1');
    $object?->test('偽物2');
    SomeClass::test('偽物3');
    PHP;

    expect(PestTestNameScanner::names($source))->toBe([]);
});

test('test 名スキャナ: function test() の宣言は抽出しない', function (): void {
    $source = <<<'PHP'
    <?php
    function test(string $name): void {}
    PHP;

    expect(PestTestNameScanner::names($source))->toBe([]);
});

test('test 名スキャナ: 実ファイル (SocialProviderTrustPolicyTest) から委譲先 test 名を抽出できる', function (): void {
    $source = file_get_contents(base_path('tests/Architecture/SocialProviderTrustPolicyTest.php'));

    expect($source)->toBeString()
        ->and(PestTestNameScanner::names((string) $source))
        ->toContain('全 SSO provider が capability / email_trust を明示宣言している');
});

```

### 宣言を足す app/ 4 本 (全文)
```php


// ---- app/Http/Controllers/Controller.php ----
<?php

namespace App\Http\Controllers;

abstract class Controller
{
    //
}

// ---- app/Models/Team.php ----
<?php

namespace App\Models;

use Laratrust\Models\Team as LaratrustTeam;

class Team extends LaratrustTeam
{
    /**
     * パッケージ既定は $guarded = [] (全開放) のため、明示的に許可リスト化する
     * (tests/Architecture/MassAssignmentSafetyTest の不変条件)。
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'display_name', 'description'];

    public $guarded = [];
}

// ---- app/Models/Role.php ----
<?php

namespace App\Models;

use Laratrust\Models\Role as RoleModel;

class Role extends RoleModel
{
    /**
     * パッケージ既定は $guarded = [] (全開放) のため、明示的に許可リスト化する
     * (tests/Architecture/MassAssignmentSafetyTest の不変条件)。
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'display_name', 'description'];

    public $guarded = [];
}

// ---- app/Models/Permission.php ----
<?php

namespace App\Models;

use Laratrust\Models\Permission as PermissionModel;

class Permission extends PermissionModel
{
    /**
     * パッケージ既定は $guarded = [] (全開放) のため、明示的に許可リスト化する
     * (tests/Architecture/MassAssignmentSafetyTest の不変条件)。
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'display_name', 'description'];

    public $guarded = [];
}

```

### phpstan.neon (先頭)
```yaml
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 10
    paths:
        - app
        - config
        - database
        - routes
    excludePaths:
        - vendor
    ignoreErrors:
        # AppliesCriticalActionContextToAudit は派生アプリの Auditable モデル向けに
```

### tests/Pest.php (レーン構成の抜粋)
```php
/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature / Unit は TestCase + RefreshDatabase。
| Architecture はファイル走査中心のため DB を使わない (TestCase のみ)。
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Vite manifest 不在でも view が描画できるよう test では Vite をスタブする
```
