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

## あなたの役割

Laravel + Svelte アプリの改善実装をレビューするコードレビュアーである。

## レビュー観点

1. **設計との一致性**: 詳細設計書の施策 1〜5 が設計どおり実装されているか。設計から意図的に逸脱した箇所は、その理由が実装内 (docblock / コメント / devnotes) に残っているか
2. **正確性**: 判定器 (`StrictTypesDeclarationScanner`) の**逆向きの乖離** (判定器が「宣言済み」と言うのに実際は厳密化が効かない形) を作る穴が無いか。走査域の列挙 (`TrackedPhpSourceFiles`) に fail-open (無音で空を返す / 無音 skip する) 経路が無いか
3. **PHPStan 適合性** (level 10)
4. **DTO / JsonResource パターン** (本変更はテストとファイル冒頭の 1 行追加が中心なのでアプリ層の変更はほぼ無い)
5. **テスト網羅性**: 正の対照・負の対照が揃っているか。gate の空振り (走査域が消える / 判定器が壊れる) を検出できるか
6. **セキュリティ**: 一時ファイル / 別プロセス起動 / 再帰削除の扱いに危険が無いか
7. **DESIGN.md 準拠**: `/DESIGN.md` が design token の canonical source。color / radius / typography は token 経由で参照し hex 直書き (`#RRGGBB`) を増やさない。token 値を変更する diff は `resources/css/tokens.css` と同一 diff 内で同期しているか (運用契約は `docs/design-system.md`)
8. **Atomic Design 準拠**: `resources/js/components/` は `atoms/molecules/organisms/templates` の責務分離に従う。atom は単機能・状態を持たない、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide を使い、SVG 直書きを増やさない

> 注: 本 diff には `resources/js/` / `resources/css/` の変更が 1 件も無いため、観点 7・8 は「増やしていないこと」の確認で足りる。

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に**全体判定**を `APPROVED` または `CHANGES_REQUESTED` の 1 語で書く

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
**冒頭が正準形でも、後ろに `strict_types` を含む declare が続く形は受理しない** —
今の PHP では実効が strict のままなので安全側だが、「後に書いた方が勝つ」へ仕様が変われば
そのまま fail-open になるためである。

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

あわせて import も入れ替える。`Symfony\Component\Process\Process` の参照は
同ファイル内で L48 の 1 箇所だけ (実測) なので、ヘルパ削除と同時に `use` 文も消す:

```diff
-use Symfony\Component\Process\Process;
+use Tests\Support\TrackedPhpSourceFiles;
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
- [ ] 後片付けは `finally` で行い、テスト失敗時も一時ディレクトリを残さない。
      再帰削除の前に **削除対象が確保した一時ディレクトリ直下であること**を確認してから消す
      (誤ったパスを再帰削除しないための guard)
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
 * ★**先頭の正準形だけでは終わらない — 後続の `strict_types` 再宣言があれば未宣言に倒す**。
 *   PHP 8.4 の実測では `declare(strict_types=1); declare(strict_types=0);` の実効は
 *   **strict のまま**だが (1 が 1 度でもあれば実効)、
 *   (a) 表記を 1 つに揃えるという本 gate の規約に反すること、
 *   (b) 「後に書いた方が勝つ」へ言語仕様が変わった場合に
 *       判定器 true / 実効 false という**逆向きの乖離 = fail-open** になること、
 *   の 2 つの理由で拒否する。`declare(ticks=1)` のように `strict_types` を含まない
 *   後続の declare は拒否しない (厳密化に関係しないため)。
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

        return self::hasCanonicalHead($tokens) && ! self::hasLaterStrictTypesDeclare($tokens);
    }

    /**
     * 冒頭が正準形か。
     *
     * [0] T_OPEN_TAG / [1] T_DECLARE / [2] '(' / [3] T_STRING(strict_types)
     * [4] '=' / [5] T_LNUMBER('1') / [6] ')' / [7] ';'
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function hasCanonicalHead(array $tokens): bool
    {
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

    /**
     * 冒頭の正準形より後ろに、`strict_types` を含む declare が現れるか。
     *
     * ★`'strict_types'` という**文字列リテラル**は T_CONSTANT_ENCAPSED_STRING であって
     *   T_STRING ではないため、配列リテラル (`['strict_types' => 1]`) は誤検出しない。
     * ★引数部の終端は**括弧の深さで追う**。`declare(ticks=(1), strict_types=1)` のように
     *   引数の中に括弧があると、最初の `)` で打ち切る実装では後続の `strict_types` を
     *   取りこぼす (= 見落としの向きの穴になる)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function hasLaterStrictTypesDeclare(array $tokens): bool
    {
        $count = count($tokens);
        for ($i = 8; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_DECLARE) {
                continue;
            }

            $depth = 0;
            for ($j = $i + 1; $j < $count; $j++) {
                $text = $tokens[$j]['text'];
                if ($text === '(') {
                    $depth++;

                    continue;
                }
                if ($text === ')') {
                    $depth--;
                    if ($depth <= 0) {
                        break; // この declare の引数部が閉じた
                    }

                    continue;
                }
                if ($tokens[$j]['id'] === T_STRING && mb_strtolower($text) === 'strict_types') {
                    return true;
                }
            }
        }

        return false;
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
 * 「その PHP ソースで厳密な型検査が実際に効くか」を**別プロセスで実測**する。
 *
 * ★判定器の自己検査で使う。表に書いた真値が PHP の版で変わっても、
 *   実測との突き合わせがあれば「判定器が実効性の下界である」ことは崩れない。
 * ★書き込み先はシステムの一時ディレクトリで、リポジトリ内には何も残さない。
 *
 * ★**検体自身の出力や制御フローを判定材料にしない**。判定は
 *   「追記したプローブに到達し、その場で観測した結果」だけを見る:
 *   1. 終了コードが 0 でない (Fatal / Parse error で読み込めない) → 厳密化は成立しない = false
 *   2. 終了コードが 0 なら、標準出力は **nonce つきの標識と完全一致**していなければならない。
 *      一致しない場合 (検体が自分で出力した / `exit` や `__halt_compiler()` で
 *      プローブへ到達しなかった / `?>` の後ろとして素通しされた) は**実測不能**として
 *      例外にする。false を返して黙らない (fail-open 防止)
 *   3. 標識が `STRICT-<nonce>` なら true、`WEAK-<nonce>` なら false
 *   関数名も nonce つきにして、検体側の関数と衝突しないようにする。
 */
final class StrictTypesRuntimeProbe
{
    /**
     * @param  string  $phpSource  判定器へ渡すのと**同一の完全な PHP ソース**
     * @return bool 厳密化が実際に効いたか
     *
     * @throws RuntimeException 検体がプローブと干渉して実測できないとき
     */
    public static function strictTypesInEffect(string $phpSource): bool
    {
        // tempnam() は実ファイルを作る。拡張子を足すと**元のファイルが残る**ため、
        // 戻り値のパスへそのまま書く (php は拡張子に関係なく実行できる)。
        $path = tempnam(sys_get_temp_dir(), 'strict-probe-');
        if ($path === false) {
            throw new RuntimeException('実測用の一時ファイルを作れませんでした');
        }

        $nonce = bin2hex(random_bytes(8));
        $probe = <<<PHP

            function probe_{$nonce}(int \$value): int { return \$value; }
            try { probe_{$nonce}("1"); echo 'WEAK-{$nonce}'; }
            catch (\\TypeError \$e) { echo 'STRICT-{$nonce}'; }
            PHP;

        try {
            if (file_put_contents($path, rtrim($phpSource, "\n")."\n".$probe) === false) {
                throw new RuntimeException("実測用の一時ファイルを書けませんでした: {$path}");
            }

            $process = new Process([PHP_BINARY, '-d', 'error_reporting=E_ALL', $path]);
            $process->run();

            if (! $process->isSuccessful()) {
                return false; // 読み込めないソース (Fatal / Parse error) は厳密化が成立しない
            }

            $output = trim($process->getOutput());
            if ($output === 'STRICT-'.$nonce) {
                return true;
            }
            if ($output === 'WEAK-'.$nonce) {
                return false;
            }

            throw new RuntimeException(
                '実測用のプローブへ到達しませんでした (検体が自分で出力した / exit した可能性があります)。'
                ."出力: {$output}"
            );
        } finally {
            @unlink($path);
        }
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`bool`)
- [x] null 安全 (`tempnam()` の false、`file_put_contents()` の false を扱う。
      `tempnam()` の戻り値へ拡張子を連結しない = 元ファイルを残さない)
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
- [ ] 負の対照 10 (**後続の再宣言**): 次の 2 つが `false` になる
      (実効は現行 PHP では strict のままだが、表記を揃える規約として拒否し、
      かつ言語仕様が変わったときの fail-open を先に塞ぐ)
      - `<?php declare(strict_types=1); declare(strict_types=0);`
      - `<?php declare(strict_types=1); declare(strict_types=1);`
- [ ] 正の対照 (再宣言の境界): `<?php declare(strict_types=1); declare(ticks=1);` は
      `true` のまま (`strict_types` を含まない後続 declare は拒否しない)
- [ ] 安全側の乖離 (実効だが未宣言と判定する) を**明示的に固定**: `01` / `0x1` / `0b1` /
      `declare(ticks=1, strict_types=1)` / `declare(strict_types=(1))` /
      同一 declare 内の重複指定 / 2 文目の declare (`declare(ticks=1); declare(strict_types=1);`) が
      `false` になる
- [ ] **実測との突き合わせ**: 検体は**完全な PHP ソース**として持ち (判定器へ渡すものと同一の文字列)、
      同じ文字列を `StrictTypesRuntimeProbe::strictTypesInEffect()` にも渡して、
      「判定器が `true` なら実測も必ず `true`」を要求する
      (= 判定器は実効性の下界。**逆向きの乖離 0 件**を機械的に固定する)。
      文字列リテラル・配列リテラルだけの検体も実行可能な完全ソースにしておく
- [ ] **検体表の制約**: 検体は自分では何も出力せず `exit` / `?>` / `__halt_compiler()` を
      持たない形で書く (実測器がプローブへ到達できる形にする)。この制約が破れたら
      実測器が例外を投げるので、破れたまま緑になることはない
- [ ] 実測器そのものの健全性 (実測器が常に同じ値を返す壊れ方を検出する):
      - 宣言なしの源で `false`、正準形で `true`
      - 読み込めない源 (`declare(strict_types=true);`) で `false` (終了コード 0 以外)
      - **プローブへ到達しない源で例外**: `<?php declare(strict_types=1); echo 'STRICT'; exit;` と
        `?>` で閉じた源。**検体自身が `STRICT` と出力しても真にならない**ことを固定する
        (実測: 前者は「到達しなかった」例外、後者は追記分が素通し出力されて例外になる)
- [ ] 実ファイルでの疎通: `tests/Support/PhpTokenScan.php` を読んで `true`、
      `resources/views/app.blade.php` を読んで `false` になる
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **実測器の設計を試作で確認済み** (`devnotes/20260815-1534-strict-types-baseline-gate/` の
  設計時実測): 宣言なし=false / 正準形=true / `namespace` を含む正準形=true /
  後置・`true` 値=終了コード 255 で false / 再宣言=true (実効は strict) /
  コメントのみ・文字列リテラルのみ=false / 自前出力 + `exit`=例外 / `?>` で閉じる=例外。
  Round 2 で指摘された「検体自身の出力で真になる」経路は塞がっている
- **別プロセス起動のコスト**: 検体 20 件前後 × 1 プロセス ≒ 1 秒未満を見込む。
  超えるようなら実測との突き合わせを「乖離の向きが問題になる検体」に絞る
  (絞ってもこのテストの主張は保てる)
- **`PHP_BINARY` が使えない実行環境**: **プロセスの起動そのものに失敗した場合は
  Symfony Process が例外を投げる** (silent に `false` を返して緑にしない)。
  PHP は起動したが Parse / Fatal で終了コードが 0 以外になった場合は `false` を返す
  (= そのソースでは厳密化が成立しない、という測定結果である)。この 2 つは別の契約である
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
    //   (prefix ごとに個別の失敗メッセージを出す = どの走査域が消えたか分かるようにする)
    $prefixes = ['app/', 'tests/', 'config/', 'database/', 'routes/', 'bootstrap/', 'public/'];
    foreach ($prefixes as $prefix) {
        $found = array_filter($targets, fn (array $t): bool => str_starts_with($t['relative'], $prefix));
        expect($found)->not->toBeEmpty("走査域から {$prefix} が消えています");
    }

    // 空振り防止 4: 判定器が壊れていない (自己検査ファイルを消されても gate 単独で気付く)
    expect(StrictTypesDeclarationScanner::declaresStrictTypes("<?php\n"))->toBeFalse();
    expect(StrictTypesDeclarationScanner::declaresStrictTypes("<?php\n\ndeclare(strict_types=1);\n"))->toBeTrue();
    expect(StrictTypesDeclarationScanner::declaresStrictTypes(
        "<?php declare(strict_types=1); declare(strict_types=0);\n"
    ))->toBeFalse();

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
補足 1: 01 / 0x1 / declare(ticks=1, strict_types=1) / 冒頭より後ろでの strict_types の
        再宣言などは PHP としては有効だが、本リポジトリは表記を上の正準形 1 つに揃えるため
        受理しない。
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
- [ ] `composer test:browser` が green
- [ ] **`public/index.php` を front controller として実サーバで起動して疎通する**
      (`php -S <host>:<port> -t public public/index.php` に対する `GET /up` が 200)。
      Browser lane は in-process サーバ (テストプロセス自身の HttpKernel) で走るため
      `public/index.php` を読み込まない = `composer test:browser` はこのファイルを覆わない
      (T167 実装時に判明。設計の当初記述を訂正した)
- [ ] `php -l public/index.php` / `php -l bootstrap/app.php` / `php -l bootstrap/providers.php`
- [ ] **順序を決めた起動確認** (config cache が残っていると変更後の評価を見ないため):
      1. `php artisan config:clear` (キャッシュを外す)
      2. `php artisan route:list` (キャッシュ無しで config を実評価して起動する)
      3. `php artisan config:cache` → `php artisan config:clear`
         (キャッシュ生成経路でも config 評価が通ることの確認と後始末)
      いずれも読み取り側の操作で dev DB へ破壊操作をしない
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
- 実効ではあるが正準形でない書き方 (`01` 等) は**受理しない** (安全側の乖離)。
  **冒頭の正準形より後ろに `strict_types` を含む declare がある形も受理しない**
  (現行 PHP の実効は strict のままだが、表記を揃える規約であり、
  「後に書いた方が勝つ」へ仕様が変わったときの fail-open も同時に塞ぐ)
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
- **実効性の完全再現ではない**。実効だが正準形でない書き方 (`01` / 複合指令 /
  冒頭より後ろでの `strict_types` の再宣言) は未宣言として落とす (安全側)
- **副作用の検出は主要経路まで**。動的呼び出し・container 解決・env 依存の分岐は静的解析の外
- **`vendor:publish` による宣言の消失は防げない** (次の gate 実行で検出されるだけ)


## 実装差分 (git diff)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 0d0076b..fce66b0 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -149,7 +149,9 @@ ## テストレーンの外部 HTTP 出口 (既定拒否)
 
 ## 実装規約
 
-- `declare(strict_types=1)` + 日本語コメント。Controller は薄く(Service 委譲)、
+- `declare(strict_types=1)` + 日本語コメント。**宣言は git 追跡下の PHP 全数が対象**で、
+  免除の登録簿は持たない(`StrictTypesDeclarationGateTest` が deny-by-default で強制。
+  `*.blade.php` は PHP ソースではないため対象外)。Controller は薄く(Service 委譲)、
   transaction は Service 内。保護キーは forceFill / relation で明示代入
 - 月 / 年 / 四半期の加減算は**暗黙 overflow メソッドを禁止**する。既定は
   `addMonthNoOverflow` / `subYearNoOverflow` 等の `*NoOverflow`、overflow が要件なら
diff --git a/app/Http/Controllers/Controller.php b/app/Http/Controllers/Controller.php
index 8677cd5..e2af3d2 100644
--- a/app/Http/Controllers/Controller.php
+++ b/app/Http/Controllers/Controller.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 namespace App\Http\Controllers;
 
 abstract class Controller
diff --git a/app/Models/Permission.php b/app/Models/Permission.php
index 515fdfc..7bafd75 100644
--- a/app/Models/Permission.php
+++ b/app/Models/Permission.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 namespace App\Models;
 
 use Laratrust\Models\Permission as PermissionModel;
diff --git a/app/Models/Role.php b/app/Models/Role.php
index f4a9718..3c1ad35 100644
--- a/app/Models/Role.php
+++ b/app/Models/Role.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 namespace App\Models;
 
 use Laratrust\Models\Role as RoleModel;
diff --git a/app/Models/Team.php b/app/Models/Team.php
index a6338f1..54549ed 100644
--- a/app/Models/Team.php
+++ b/app/Models/Team.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 namespace App\Models;
 
 use Laratrust\Models\Team as LaratrustTeam;
diff --git a/bootstrap/app.php b/bootstrap/app.php
index aa062ed..bf7c206 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use App\Exceptions\ApiExceptionRenderer;
 use App\Exceptions\Billing\InsufficientTicketsException;
 use App\Exceptions\Billing\QuotaExceededException;
diff --git a/bootstrap/providers.php b/bootstrap/providers.php
index bfb1301..f2f574e 100644
--- a/bootstrap/providers.php
+++ b/bootstrap/providers.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use App\Providers\AppServiceProvider;
 use App\Providers\ExternalClientTimeoutServiceProvider;
 use App\Providers\FakeExternalsServiceProvider;
diff --git a/config/app.php b/config/app.php
index 12fcdfa..03220a5 100644
--- a/config/app.php
+++ b/config/app.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 return [
 
     /*
diff --git a/config/audit.php b/config/audit.php
index eda5d56..6fb0ef9 100644
--- a/config/audit.php
+++ b/config/audit.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use App\Auth\ResolveAuditCauser;
 use App\Models\ModelAudit;
 use OwenIt\Auditing\Resolvers\IpAddressResolver;
diff --git a/config/auth.php b/config/auth.php
index 9c93c49..a06db7a 100644
--- a/config/auth.php
+++ b/config/auth.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use App\Models\AdminUser;
 use App\Models\User;
 
diff --git a/config/cache.php b/config/cache.php
index 097945e..b809fc6 100644
--- a/config/cache.php
+++ b/config/cache.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use Illuminate\Support\Str;
 
 return [
diff --git a/config/cashier.php b/config/cashier.php
index 4a009f2..143857d 100644
--- a/config/cashier.php
+++ b/config/cashier.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use App\Enums\Billing\HandledStripeWebhookEvent;
 use Laravel\Cashier\Console\WebhookCommand;
 use Laravel\Cashier\Invoices\DompdfInvoiceRenderer;
diff --git a/config/ciphersweet.php b/config/ciphersweet.php
index 4f0f085..6644849 100644
--- a/config/ciphersweet.php
+++ b/config/ciphersweet.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 return [
     /**
      * This controls which cryptographic backend will be used by CipherSweet.
diff --git a/config/database.php b/config/database.php
index e8f23dc..fa73749 100644
--- a/config/database.php
+++ b/config/database.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use Illuminate\Support\Str;
 use Pdo\Mysql;
 
diff --git a/config/filesystems.php b/config/filesystems.php
index d6e7c64..3102411 100644
--- a/config/filesystems.php
+++ b/config/filesystems.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use App\Support\ExternalClientTimeouts;
 
 return [
diff --git a/config/fortify.php b/config/fortify.php
index c3d91b7..8d15c0a 100644
--- a/config/fortify.php
+++ b/config/fortify.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use Laravel\Fortify\Features;
 
 /*
diff --git a/config/laratrust.php b/config/laratrust.php
index 4f75b16..a9d56f4 100644
--- a/config/laratrust.php
+++ b/config/laratrust.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use App\Models\Permission;
 use App\Models\Role;
 use App\Models\Team;
diff --git a/config/logging.php b/config/logging.php
index c5e6067..4cd1ade 100644
--- a/config/logging.php
+++ b/config/logging.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use App\Logging\RedactSensitiveFields;
 use Monolog\Handler\NullHandler;
 use Monolog\Handler\StreamHandler;
diff --git a/config/mail.php b/config/mail.php
index 580570c..1956b9a 100644
--- a/config/mail.php
+++ b/config/mail.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 return [
 
     /*
diff --git a/config/mcp.php b/config/mcp.php
index 36c7921..86654d3 100644
--- a/config/mcp.php
+++ b/config/mcp.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 return [
 
     /*
diff --git a/config/passport.php b/config/passport.php
index 18edc61..96583a6 100644
--- a/config/passport.php
+++ b/config/passport.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use App\Http\Middleware\McpConsentOrganizationBinder;
 
 return [
diff --git a/config/prism.php b/config/prism.php
index 99f3ea5..baae0e7 100644
--- a/config/prism.php
+++ b/config/prism.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 return [
     'prism_server' => [
         // The middleware that will be applied to the Prism Server routes.
diff --git a/config/queue.php b/config/queue.php
index b10c52f..6085704 100644
--- a/config/queue.php
+++ b/config/queue.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 return [
 
     /*
diff --git a/config/services.php b/config/services.php
index ef09b3c..868cff9 100644
--- a/config/services.php
+++ b/config/services.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use App\Support\ExternalClientTimeouts;
 
 return [
diff --git a/config/session.php b/config/session.php
index f7c945b..d21a4af 100644
--- a/config/session.php
+++ b/config/session.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use Illuminate\Support\Str;
 
 return [
diff --git a/database/migrations/0001_01_01_000000_create_users_table.php b/database/migrations/0001_01_01_000000_create_users_table.php
index 772dbd4..07bd124 100644
--- a/database/migrations/0001_01_01_000000_create_users_table.php
+++ b/database/migrations/0001_01_01_000000_create_users_table.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use Illuminate\Database\Migrations\Migration;
 use Illuminate\Database\Schema\Blueprint;
 use Illuminate\Support\Facades\Schema;
diff --git a/database/migrations/0001_01_01_000001_create_cache_table.php b/database/migrations/0001_01_01_000001_create_cache_table.php
index 06dc7a5..dad1789 100644
--- a/database/migrations/0001_01_01_000001_create_cache_table.php
+++ b/database/migrations/0001_01_01_000001_create_cache_table.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use Illuminate\Database\Migrations\Migration;
 use Illuminate\Database\Schema\Blueprint;
 use Illuminate\Support\Facades\Schema;
diff --git a/database/migrations/0001_01_01_000002_create_jobs_table.php b/database/migrations/0001_01_01_000002_create_jobs_table.php
index edac6fe..46e8b62 100644
--- a/database/migrations/0001_01_01_000002_create_jobs_table.php
+++ b/database/migrations/0001_01_01_000002_create_jobs_table.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use Illuminate\Database\Migrations\Migration;
 use Illuminate\Database\Schema\Blueprint;
 use Illuminate\Support\Facades\Schema;
diff --git a/database/migrations/2026_06_11_071031_add_two_factor_columns_to_users_table.php b/database/migrations/2026_06_11_071031_add_two_factor_columns_to_users_table.php
index 45739ef..10c2df0 100644
--- a/database/migrations/2026_06_11_071031_add_two_factor_columns_to_users_table.php
+++ b/database/migrations/2026_06_11_071031_add_two_factor_columns_to_users_table.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use Illuminate\Database\Migrations\Migration;
 use Illuminate\Database\Schema\Blueprint;
 use Illuminate\Support\Facades\Schema;
diff --git a/database/migrations/2026_06_11_071055_create_blind_indexes_table.php b/database/migrations/2026_06_11_071055_create_blind_indexes_table.php
index 23d65ad..c632e47 100644
--- a/database/migrations/2026_06_11_071055_create_blind_indexes_table.php
+++ b/database/migrations/2026_06_11_071055_create_blind_indexes_table.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use Illuminate\Database\Migrations\Migration;
 use Illuminate\Database\Schema\Blueprint;
 use Illuminate\Support\Facades\Schema;
diff --git a/database/migrations/2026_06_11_073836_laratrust_setup_tables.php b/database/migrations/2026_06_11_073836_laratrust_setup_tables.php
index ef37ce6..f754a16 100644
--- a/database/migrations/2026_06_11_073836_laratrust_setup_tables.php
+++ b/database/migrations/2026_06_11_073836_laratrust_setup_tables.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use Illuminate\Database\Migrations\Migration;
 use Illuminate\Database\Schema\Blueprint;
 use Illuminate\Support\Facades\Schema;
diff --git a/database/migrations/2026_06_11_100000_create_admin_users_table.php b/database/migrations/2026_06_11_100000_create_admin_users_table.php
index 0e762c9..e691de3 100644
--- a/database/migrations/2026_06_11_100000_create_admin_users_table.php
+++ b/database/migrations/2026_06_11_100000_create_admin_users_table.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use Illuminate\Database\Migrations\Migration;
 use Illuminate\Database\Schema\Blueprint;
 use Illuminate\Support\Facades\Schema;
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index cf831a0..8d8bdf9 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -604,3 +604,65 @@ ### 関連
   `.claude/skills/app-bug-hunt/coverage/build_executed.py` /
   `.claude/skills/app-bug-hunt/coverage/correlate.py`
 - 設計: `devnotes/20260815-1113-bughunt-route-capture-failclosed/`
+
+---
+
+## D15 ✅ strict_types gate の走査域を追跡下 PHP 全数にし、未宣言一覧を持たない
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| テスト名 | `StrictTypesBaselineInvariantTest` | `StrictTypesDeclarationGateTest` |
+| 走査域 | `app/` のファイル走査 | **git 追跡下の `*.php` − `*.blade.php`** (実測 1543 本) |
+| 未宣言一覧 (baseline) | 常に空を契約として保持 | **持たない** (免除機構そのものが無い) |
+| 判定器 | 構造判定 (値は数値リテラル 1 の完全一致) | 構造判定 + **実測照合器との突き合わせ** |
+
+### なぜ正当な差分か(logic-driven)
+
+走査域を `app/` に限ると、`config/` `database/` `bootstrap/` `public/` の未宣言 28 本が
+規約の外に残り続ける。本アプリは容量予約 (bytes) やチケット枚数のように、数値と文字列の
+取り違えがそのまま容量・金額の誤りになる領域を持つため、「どこか 1 枚だけ緩い」状態を
+残さないことに意味がある。実測ではこの 28 本は 1 行の追加だけで解消でき、うち 25 本は
+PHPStan level 10 の解析対象でもあるので、**追加した宣言の副作用は静的解析で機械的に確認できる**。
+
+未宣言一覧 (baseline) を持たないのは、導入時点の未宣言 32 本を同一変更で是正して 0 件から
+始めるためである。空の登録簿とそれを双方向比較する仕組みは 1 件も違反を守らないまま
+複雑さだけを足すことになる (「今必要なものだけ作る」)。既存の
+`QueueDispatchAtomicityInventoryTest` が採っているのと同じ形である。
+
+判定器が実測照合器 (`StrictTypesRuntimeProbe`) と突き合わせるのは、「判定器は宣言済みと
+言うのに実際は厳密化されない」という**逆向きの乖離 = fail-open** を機械的に 0 件へ固定する
+ためである。判定器は実効性の下界であり、安全側の乖離 (実効だが受理しない形) だけを許す。
+
+### 揃えている不変条件(これは保証し続ける)
+
+> 「宣言を欠く PHP ファイルが新しく増えない」
+
+- 走査域が広いので、テンプレートが保証する `app/` の範囲は**包含している**
+- 空の baseline と本アプリの「登録簿なし」は、守っている集合が同じ (未宣言 0 件)
+- テンプレート取り込みで `StrictTypesBaselineInvariantTest` が入ってきた場合は、
+  **2 本立てにせず本 gate へ統合する** (同じ事実を 2 箇所で宣言しない)
+- どうしても宣言できないファイルが将来出た場合も、なし崩しに allow-list を足さない。
+  設計レビューを通してから機構を新設する
+
+### 保証しないもの (誇張しない)
+
+- `artisan` など拡張子が `.php` でない PHP ファイル / 未追跡 (git add 前) のファイルは見ない
+  (gate が守る境界は commit / CI である)
+- `*.blade.php` は見ない。テンプレートであり PHP ソースファイルではないため、免除ではなく対象外
+- 宣言の有無だけを見る。型の緩さそのもの (level 10 で検出される型不一致) は PHPStan の担当
+- 実効ではあるが正準形でない書き方 (`01` / `0x1` / `declare(ticks=1, strict_types=1)` 等) は
+  **受理しない** (安全側の乖離)。**冒頭の正準形より後ろに `strict_types` を含む declare が
+  ある形も受理しない** (現行 PHP の実効は strict のままだが、表記を揃える規約であり、
+  「後に書いた方が勝つ」へ仕様が変わったときの fail-open も同時に塞ぐ)
+- `php artisan vendor:publish` 直後に宣言が失われることは防げない (検出はする)
+
+### 関連
+
+- 実装: `tests/Architecture/StrictTypesDeclarationGateTest.php` /
+  `tests/Support/StrictTypesDeclarationScanner.php` /
+  `tests/Support/StrictTypesRuntimeProbe.php` / `tests/Support/TrackedPhpSourceFiles.php`
+- 自己検査: `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` /
+  `tests/Unit/Architecture/TrackedPhpSourceFilesTest.php`
+- 設計: `devnotes/20260815-1534-strict-types-baseline-gate/`
+- テンプレート側の根拠: `tests/Architecture/StrictTypesBaselineInvariantTest.php`
+  (家系の裁定 AG-010 (2026-08-05)「テンプレートへ還流し家系の標準装備とする」)
diff --git a/public/index.php b/public/index.php
index ee8f07e..6aa752c 100644
--- a/public/index.php
+++ b/public/index.php
@@ -1,5 +1,7 @@
 <?php
 
+declare(strict_types=1);
+
 use Illuminate\Foundation\Application;
 use Illuminate\Http\Request;
 
diff --git a/tests/Architecture/NoNonCompoundGlobalUseTest.php b/tests/Architecture/NoNonCompoundGlobalUseTest.php
index 97062fa..7c742b0 100644
--- a/tests/Architecture/NoNonCompoundGlobalUseTest.php
+++ b/tests/Architecture/NoNonCompoundGlobalUseTest.php
@@ -2,7 +2,7 @@
 
 declare(strict_types=1);
 
-use Symfony\Component\Process\Process;
+use Tests\Support\TrackedPhpSourceFiles;
 
 /*
  * Architecture invariant: **namespace 宣言の無い PHP ファイル**の global スコープに
@@ -26,7 +26,9 @@
  *     RefreshDatabase が死に **全テストが全滅する**
  * つまり「今日は raw output 汚染で済んでいるが、いつ全滅へ化けてもおかしくない非決定的な地雷」。
  *
- * 走査対象: git 追跡下の *.php (ただし *.blade.php を除く)。
+ * 走査対象: git 追跡下の *.php (ただし *.blade.php を除く)。列挙は
+ * `Tests\Support\TrackedPhpSourceFiles` に集約してある (同じ列挙を 2 本持たない。
+ * 走査域の定義と限界は同クラスの docblock が正本)。
  * git 管理下に限ることで vendor/ node_modules/ .claude/worktrees/ storage/ を
  * **自動的に**除外できる (明示 exclude リストを保守しなくてよい)。
  * **既知の限界**: 未追跡 (git add 前) のファイルは走査されない。gate が守る境界は
@@ -37,39 +39,6 @@
  * allowlist は設けない: 非複合 global use に正当な用途は存在しない (常に無効な import)。
  */
 
-/**
- * git 追跡下の PHP ソースファイル一覧 (blade を除く)。
- *
- * @return list<array{absolute: string, relative: string}>
- */
-function nonCompoundUseScanTargets(): array
-{
-    $root = base_path();
-    $process = new Process(['git', 'ls-files', '-z', '*.php'], $root);
-    $process->run();
-
-    if (! $process->isSuccessful()) {
-        throw new RuntimeException(
-            'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
-            .$process->getErrorOutput()
-        );
-    }
-
-    $files = [];
-    foreach (explode("\0", $process->getOutput()) as $relative) {
-        if ($relative === '' || str_ends_with($relative, '.blade.php')) {
-            continue;
-        }
-        $absolute = $root.'/'.$relative;
-        if (! is_file($absolute)) {
-            continue; // 削除済みだが index に残っている等
-        }
-        $files[] = ['absolute' => $absolute, 'relative' => $relative];
-    }
-
-    return $files;
-}
-
 /**
  * index 以降で最初の significant token の index。
  *
@@ -235,7 +204,7 @@ function nonCompoundUseCollectAll(): array
     $namespaceless = 0;
     $total = 0;
 
-    foreach (nonCompoundUseScanTargets() as $target) {
+    foreach (TrackedPhpSourceFiles::all(base_path()) as $target) {
         $source = file_get_contents($target['absolute']);
         if (! is_string($source)) {
             continue;
diff --git a/tests/Architecture/StrictTypesDeclarationGateTest.php b/tests/Architecture/StrictTypesDeclarationGateTest.php
new file mode 100644
index 0000000..86d9237
--- /dev/null
+++ b/tests/Architecture/StrictTypesDeclarationGateTest.php
@@ -0,0 +1,91 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\StrictTypesDeclarationScanner;
+use Tests\Support\TrackedPhpSourceFiles;
+
+/*
+ * Architecture invariant: **git 追跡下の PHP ソース全数**が冒頭で
+ * `declare(strict_types=1);` を宣言している。
+ *
+ * なぜ全数か: PHP は既定で "1" と 1 を黙って行き来させる。宣言を欠くファイルが 1 枚あると
+ * そこだけ暗黙変換が復活し、取り違えが実行時まで表に出ない。容量予約 (bytes) や
+ * チケット枚数のように数値と文字列の取り違えが金額・容量の誤りになる領域を持つため、
+ * 「どこか 1 枚だけ緩い」状態を構造的に作らない。
+ *
+ * **免除の登録簿 (baseline / allow-list) を持たない**。導入時点の未宣言 32 本を同一変更で
+ * 是正して 0 件から始めるので、登録簿は 1 件も守らないまま複雑さだけを足すことになる
+ * (`QueueDispatchAtomicityInventoryTest` と同じ形 = 免除機構そのものが無い)。
+ * **どうしても宣言できないファイルが将来出た場合も、なし崩しに allow-list を足さない。
+ * 設計レビュー (app-design) を通してから機構を新設すること。**
+ *
+ * 走査域 (追跡下 `*.php` − `*.blade.php`) の定義と限界は
+ * `Tests\Support\TrackedPhpSourceFiles` の docblock が正本。
+ * 判定の正準形と「実効だが受理しない形」は `Tests\Support\StrictTypesDeclarationScanner` が正本。
+ *
+ * 家系との関係: laravel-claude-template は `StrictTypesBaselineInvariantTest` で
+ * **app のみ**を走査し空の baseline を持つ。本 gate は走査域が広く baseline を持たない
+ * (`docs/template-divergence.md` D15)。
+ */
+
+test('git 追跡下の PHP は全数 declare(strict_types=1) を宣言している', function (): void {
+    $targets = TrackedPhpSourceFiles::all(base_path());
+
+    // 空振り防止 1: 走査対象が 0 件なら赤 (走査域が消えても緑にならないようにする)
+    expect($targets)->not->toBeEmpty();
+
+    // 空振り防止 2: 母集団の床値 (実測 1543)。走査域が黙って狭まると赤くなる
+    expect(count($targets))->toBeGreaterThanOrEqual(1400);
+
+    // 空振り防止 3: 代表ディレクトリが母集団に含まれること
+    //   (prefix ごとに個別の失敗メッセージを出す = どの走査域が消えたか分かるようにする)
+    $prefixes = ['app/', 'tests/', 'config/', 'database/', 'routes/', 'bootstrap/', 'public/'];
+    foreach ($prefixes as $prefix) {
+        $found = array_filter($targets, fn (array $target): bool => str_starts_with($target['relative'], $prefix));
+        expect($found)->not->toBeEmpty("走査域から {$prefix} が消えています");
+    }
+
+    // 空振り防止 4: 判定器が壊れていない (自己検査ファイルを消されても gate 単独で気付く)
+    expect(StrictTypesDeclarationScanner::declaresStrictTypes("<?php\n"))->toBeFalse();
+    expect(StrictTypesDeclarationScanner::declaresStrictTypes("<?php\n\ndeclare(strict_types=1);\n"))->toBeTrue();
+    expect(StrictTypesDeclarationScanner::declaresStrictTypes(
+        "<?php declare(strict_types=1); declare(strict_types=0);\n"
+    ))->toBeFalse();
+
+    $undeclared = [];
+    foreach ($targets as $target) {
+        $source = file_get_contents($target['absolute']);
+        if ($source === false) {
+            // 無音 skip すると宣言漏れを見逃す (fail-open) ため、読めないファイルは落とす
+            throw new RuntimeException("読み取れないファイルがあります: {$target['relative']}");
+        }
+        if (! StrictTypesDeclarationScanner::declaresStrictTypes($source)) {
+            $undeclared[] = $target['relative'];
+        }
+    }
+
+    expect($undeclared)->toBe([], strictTypesGateFailureMessage($undeclared));
+});
+
+/**
+ * 失敗メッセージ (直し方まで書く)。
+ *
+ * @param  list<string>  $undeclared
+ */
+function strictTypesGateFailureMessage(array $undeclared): string
+{
+    $list = implode(PHP_EOL, array_map(static fn (string $path): string => "  - {$path}", $undeclared));
+
+    return 'declare(strict_types=1) を欠く PHP ファイルがあります ('.count($undeclared).' 件):'.PHP_EOL
+        .$list.PHP_EOL
+        .'直し方: 各ファイルの <?php の直後に次の 1 行を置く (前に他の文・出力を置かない):'.PHP_EOL
+        .'  '.StrictTypesDeclarationScanner::CANONICAL_DECLARATION.PHP_EOL
+        .'補足 1: 01 / 0x1 / declare(ticks=1, strict_types=1) / 冒頭より後ろでの strict_types の'.PHP_EOL
+        .'        再宣言などは PHP としては有効だが、本リポジトリは表記を上の正準形 1 つに揃えるため'.PHP_EOL
+        .'        受理しない。'.PHP_EOL
+        .'補足 2: `php artisan vendor:publish` の直後は骨組み由来ファイルが宣言を失う。'.PHP_EOL
+        .'        publish した内容を確認したうえで宣言を足してから commit すること。'.PHP_EOL
+        .'補足 3: 免除の登録簿は意図的に持たない。宣言できない事情ができたときは'.PHP_EOL
+        .'        allow-list を足す前に設計レビューを通すこと。';
+}
diff --git a/tests/Support/StrictTypesDeclarationScanner.php b/tests/Support/StrictTypesDeclarationScanner.php
new file mode 100644
index 0000000..19ae980
--- /dev/null
+++ b/tests/Support/StrictTypesDeclarationScanner.php
@@ -0,0 +1,120 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+/**
+ * PHP ソースが冒頭で `declare(strict_types=1);` を宣言しているかを判定する純関数。
+ *
+ * ★正規表現・部分文字列判定にしない。コメントや文字列リテラル中の
+ *   `declare(strict_types=1)` という**記述**を宣言と誤認するため
+ *   (負の対照で固定する)。走査は `PhpTokenScan::normalize()` (空白・コメント除去済み) に対して行う。
+ *
+ * ★**受理するのは正準形だけ**である:
+ *     <?php  declare ( strict_types = 1 ) ;
+ *   (キーワード・指令名の大小は無視。空白とコメントは透過)
+ *   PHP 8.4 の実測では `01` / `0x1` / `0b1` / `declare(ticks=1, strict_types=1)` /
+ *   同一 declare 内の重複指定 / 2 文目の declare も**実際には厳密化が効く**が、
+ *   本判定器はこれらを**未宣言側に倒す** (安全側の乖離)。
+ *   本 gate は PHP の意味論の再現ではなく、リポジトリ内の表記を 1 つに揃える規約検査だからである。
+ *
+ * ★**先頭の正準形だけでは終わらない — 後続の `strict_types` 再宣言があれば未宣言に倒す**。
+ *   PHP 8.4 の実測では `declare(strict_types=1); declare(strict_types=0);` の実効は
+ *   **strict のまま**だが (1 が 1 度でもあれば実効)、
+ *   (a) 表記を 1 つに揃えるという本 gate の規約に反すること、
+ *   (b) 「後に書いた方が勝つ」へ言語仕様が変わった場合に
+ *       判定器 true / 実効 false という**逆向きの乖離 = fail-open** になること、
+ *   の 2 つの理由で拒否する。`declare(ticks=1)` のように `strict_types` を含まない
+ *   後続の declare は拒否しない (厳密化に関係しないため)。
+ *
+ * ★**逆向きの乖離は 1 件も許さない** — 「判定器は宣言済みと言うのに実際は厳密化されない」形が
+ *   あると gate が嘘をつく。`StrictTypesDeclarationScannerTest` が
+ *   `StrictTypesRuntimeProbe` (別プロセスで実際に型不一致が起きるかを測る) と
+ *   突き合わせ、乖離の向きを機械的に固定する。
+ */
+final class StrictTypesDeclarationScanner
+{
+    /** 正準形の宣言 (失敗メッセージで提示する)。 */
+    public const string CANONICAL_DECLARATION = 'declare(strict_types=1);';
+
+    public static function declaresStrictTypes(string $phpSource): bool
+    {
+        $tokens = PhpTokenScan::normalize($phpSource);
+
+        return self::hasCanonicalHead($tokens) && ! self::hasLaterStrictTypesDeclare($tokens);
+    }
+
+    /**
+     * 冒頭が正準形か。
+     *
+     * [0] T_OPEN_TAG / [1] T_DECLARE / [2] '(' / [3] T_STRING(strict_types)
+     * [4] '=' / [5] T_LNUMBER('1') / [6] ')' / [7] ';'
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function hasCanonicalHead(array $tokens): bool
+    {
+        if (count($tokens) < 8) {
+            return false;
+        }
+        if ($tokens[0]['id'] !== T_OPEN_TAG || $tokens[1]['id'] !== T_DECLARE) {
+            return false; // 先頭に inline HTML / shebang / 他の文があれば未宣言
+        }
+        if ($tokens[2]['text'] !== '(' || $tokens[3]['id'] !== T_STRING) {
+            return false;
+        }
+        if (mb_strtolower($tokens[3]['text']) !== 'strict_types') {
+            return false;
+        }
+        if ($tokens[4]['text'] !== '=' || $tokens[5]['id'] !== T_LNUMBER || $tokens[5]['text'] !== '1') {
+            return false; // 値 0 / 01 / true / 式 はすべて未宣言側
+        }
+
+        return $tokens[6]['text'] === ')' && $tokens[7]['text'] === ';'; // ブロック形 `{` は未宣言側
+    }
+
+    /**
+     * 冒頭の正準形より後ろに、`strict_types` を含む declare が現れるか。
+     *
+     * ★`'strict_types'` という**文字列リテラル**は T_CONSTANT_ENCAPSED_STRING であって
+     *   T_STRING ではないため、配列リテラル (`['strict_types' => 1]`) は誤検出しない。
+     * ★引数部の終端は**括弧の深さで追う**。`declare(ticks=(1), strict_types=1)` のように
+     *   引数の中に括弧があると、最初の `)` で打ち切る実装では後続の `strict_types` を
+     *   取りこぼす (= 見落としの向きの穴になる)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function hasLaterStrictTypesDeclare(array $tokens): bool
+    {
+        $count = count($tokens);
+        for ($i = 8; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_DECLARE) {
+                continue;
+            }
+
+            $depth = 0;
+            for ($j = $i + 1; $j < $count; $j++) {
+                $text = $tokens[$j]['text'];
+                if ($text === '(') {
+                    $depth++;
+
+                    continue;
+                }
+                if ($text === ')') {
+                    $depth--;
+                    if ($depth <= 0) {
+                        break; // この declare の引数部が閉じた
+                    }
+
+                    continue;
+                }
+                if ($tokens[$j]['id'] === T_STRING && mb_strtolower($text) === 'strict_types') {
+                    return true;
+                }
+            }
+        }
+
+        return false;
+    }
+}
diff --git a/tests/Support/StrictTypesRuntimeProbe.php b/tests/Support/StrictTypesRuntimeProbe.php
new file mode 100644
index 0000000..692781a
--- /dev/null
+++ b/tests/Support/StrictTypesRuntimeProbe.php
@@ -0,0 +1,80 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use RuntimeException;
+use Symfony\Component\Process\Process;
+
+/**
+ * 「その PHP ソースで厳密な型検査が実際に効くか」を**別プロセスで実測**する。
+ *
+ * ★判定器の自己検査で使う。表に書いた真値が PHP の版で変わっても、
+ *   実測との突き合わせがあれば「判定器が実効性の下界である」ことは崩れない。
+ * ★書き込み先はシステムの一時ディレクトリで、リポジトリ内には何も残さない。
+ *
+ * ★**検体自身の出力や制御フローを判定材料にしない**。判定は
+ *   「追記したプローブに到達し、その場で観測した結果」だけを見る:
+ *   1. 終了コードが 0 でない (Fatal / Parse error で読み込めない) → 厳密化は成立しない = false
+ *   2. 終了コードが 0 なら、標準出力は **nonce つきの標識と完全一致**していなければならない。
+ *      一致しない場合 (検体が自分で出力した / `exit` や `__halt_compiler()` で
+ *      プローブへ到達しなかった / `?>` の後ろとして素通しされた) は**実測不能**として
+ *      例外にする。false を返して黙らない (fail-open 防止)
+ *   3. 標識が `STRICT-<nonce>` なら true、`WEAK-<nonce>` なら false
+ *   関数名も nonce つきにして、検体側の関数と衝突しないようにする。
+ */
+final class StrictTypesRuntimeProbe
+{
+    /**
+     * @param  string  $phpSource  判定器へ渡すのと**同一の完全な PHP ソース**
+     * @return bool 厳密化が実際に効いたか
+     *
+     * @throws RuntimeException 検体がプローブと干渉して実測できないとき
+     */
+    public static function strictTypesInEffect(string $phpSource): bool
+    {
+        // tempnam() は実ファイルを作る。拡張子を足すと**元のファイルが残る**ため、
+        // 戻り値のパスへそのまま書く (php は拡張子に関係なく実行できる)。
+        $path = tempnam(sys_get_temp_dir(), 'strict-probe-');
+        if ($path === false) {
+            throw new RuntimeException('実測用の一時ファイルを作れませんでした');
+        }
+
+        $nonce = bin2hex(random_bytes(8));
+        $probe = <<<PHP
+
+            function probe_{$nonce}(int \$value): int { return \$value; }
+            try { probe_{$nonce}("1"); echo 'WEAK-{$nonce}'; }
+            catch (\\TypeError \$e) { echo 'STRICT-{$nonce}'; }
+            PHP;
+
+        try {
+            if (file_put_contents($path, rtrim($phpSource, "\n")."\n".$probe) === false) {
+                throw new RuntimeException("実測用の一時ファイルを書けませんでした: {$path}");
+            }
+
+            $process = new Process([PHP_BINARY, '-d', 'error_reporting=E_ALL', $path]);
+            $process->run();
+
+            if (! $process->isSuccessful()) {
+                return false; // 読み込めないソース (Fatal / Parse error) は厳密化が成立しない
+            }
+
+            $output = trim($process->getOutput());
+            if ($output === 'STRICT-'.$nonce) {
+                return true;
+            }
+            if ($output === 'WEAK-'.$nonce) {
+                return false;
+            }
+
+            throw new RuntimeException(
+                '実測用のプローブへ到達しませんでした (検体が自分で出力した / exit した可能性があります)。'
+                ."出力: {$output}"
+            );
+        } finally {
+            @unlink($path);
+        }
+    }
+}
diff --git a/tests/Support/TrackedPhpSourceFiles.php b/tests/Support/TrackedPhpSourceFiles.php
new file mode 100644
index 0000000..61c7409
--- /dev/null
+++ b/tests/Support/TrackedPhpSourceFiles.php
@@ -0,0 +1,61 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use RuntimeException;
+use Symfony\Component\Process\Process;
+
+/**
+ * git 追跡下の PHP ソースファイル (blade を除く) を列挙する純関数。
+ *
+ * ★同じ列挙を 2 本持たない。`NoNonCompoundGlobalUseTest` (既存) と
+ *   `StrictTypesDeclarationGateTest` の両方がここを使う。
+ * ★git 管理下に限ることで vendor/ node_modules/ .claude/worktrees/ storage/ を
+ *   **自動的に**除外できる (明示 exclude リストを保守しなくてよい)。
+ * ★`*.blade.php` は**規則の段階で母集団に入れない**。blade はテンプレートであり
+ *   先頭が PHP コードではない (PHP としては `<?php` より前に出力が始まる) ため、
+ *   PHP ソースファイルに課す規約の対象にならない。免除ではなく対象外である。
+ * ★**保証しないもの**: (a) 未追跡 (git add 前) のファイルは列挙されない。
+ *   gate が守る境界は commit / CI であり、そこでは必ず追跡下にある。
+ *   (b) 拡張子が `.php` でない PHP ファイル (`artisan` など) は列挙されない。
+ *   (c) git が無い環境では**沈黙して空を返さず例外にする** (fail-open 防止)。
+ * ★利用側は「自分が期待する母集団」を必ず pin すること (床値 + 代表パス)。
+ *   共用したことで一方の都合の変更が他方の走査域を黙って変えるのを防ぐ。
+ */
+final class TrackedPhpSourceFiles
+{
+    /**
+     * @param  string  $root  git worktree の root (絶対パス)
+     * @return list<array{absolute: string, relative: string}> relative の昇順
+     */
+    public static function all(string $root): array
+    {
+        $process = new Process(['git', 'ls-files', '-z', '--', '*.php'], $root);
+        $process->run();
+
+        if (! $process->isSuccessful()) {
+            throw new RuntimeException(
+                'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
+                .$process->getErrorOutput()
+            );
+        }
+
+        $files = [];
+        foreach (explode("\0", $process->getOutput()) as $relative) {
+            if ($relative === '' || str_ends_with($relative, '.blade.php')) {
+                continue;
+            }
+            $absolute = $root.'/'.$relative;
+            if (! is_file($absolute)) {
+                continue; // 削除済みだが index に残っている等
+            }
+            $files[] = ['absolute' => $absolute, 'relative' => $relative];
+        }
+
+        usort($files, fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));
+
+        return $files;
+    }
+}
diff --git a/tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php b/tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php
new file mode 100644
index 0000000..74798f8
--- /dev/null
+++ b/tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php
@@ -0,0 +1,122 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\StrictTypesDeclarationScanner;
+use Tests\Support\StrictTypesRuntimeProbe;
+
+/*
+ * `StrictTypesDeclarationScanner` の正負両方向と、実測器との乖離の向きを固定する。
+ *
+ * ★負のコントロール (コメント内・文字列リテラル内の記述) が本 scanner の存在理由である。
+ *   部分文字列判定で書くと、宣言を消してコメントに残しただけのファイルが緑になり
+ *   「そのファイルで厳密化が効いている」という保証にならない。
+ *
+ * ★**乖離は片方向しか許さない**。scanner が true と言ったら実測も必ず true でなければ
+ *   ならない (= scanner は実効性の下界)。逆向き (scanner true / 実測 false) は
+ *   gate が嘘をつくことになるので 1 件も許さない。安全側の乖離
+ *   (実効だが scanner false) は下の検体表に明示して固定する。
+ *
+ * ★検体は**自分では何も出力せず** `exit` / `?>` / `__halt_compiler()` を持たない
+ *   完全な PHP ソースとして書く。この制約が破れると実測器が例外を投げるので、
+ *   破れたまま緑になることはない。
+ */
+
+/**
+ * 検体表。
+ *
+ * @return list<array{0: string, 1: string, 2: bool, 3: bool}>
+ *                                                             [ラベル, 完全な PHP ソース, scanner の期待値, 実測の期待値]
+ */
+function strictTypesScannerSpecimens(): array
+{
+    return [
+        // --- 正の対照 (scanner true / 実測 true) ---
+        ['正準形', "<?php\n\ndeclare(strict_types=1);\n\n\$x = 1;\n", true, true],
+        ['正準形 + 先頭コメント', "<?php\n\n/* まえおき */\ndeclare(strict_types=1);\n", true, true],
+        ['大文字', "<?php\n\nDECLARE(STRICT_TYPES=1);\n", true, true],
+        ['空白揺れ', "<?php\n\ndeclare ( strict_types = 1 ) ;\n", true, true],
+        // 後続の declare でも strict_types を含まないものは拒否しない (厳密化に関係しないため)
+        ['後続 declare(ticks=1)', "<?php\n\ndeclare(strict_types=1);\ndeclare(ticks=1);\n", true, true],
+
+        // --- 負の対照 (scanner false / 実測 false) ---
+        ['コメント内の記述のみ', "<?php\n\n// declare(strict_types=1);\n\$x = 1;\n", false, false],
+        ['文字列リテラル内の記述のみ', "<?php\n\n\$x = 'declare(strict_types=1);';\n", false, false],
+        ['値 0', "<?php\n\ndeclare(strict_types=0);\n", false, false],
+        ['ブロック形', "<?php\n\ndeclare(strict_types=1) { }\n", false, false],
+        ['後置 (namespace の後)', "<?php\n\nnamespace A;\n\ndeclare(strict_types=1);\n", false, false],
+        ['別指令のみ', "<?php\n\ndeclare(ticks=1);\n", false, false],
+        ['宣言なし', "<?php\n\n\$x = 1;\n", false, false],
+        ['<?php の前に文字', "X<?php\n\ndeclare(strict_types=1);\n", false, false],
+        ['配列リテラルのキー', "<?php\n\n\$x = ['strict_types' => 1];\n", false, false],
+
+        // --- 安全側の乖離 (実効だが scanner は未宣言に倒す) ---
+        // 冒頭より後ろの strict_types 再宣言。現行 PHP の実効は strict のままだが、
+        // 表記を 1 つに揃える規約として拒否し、「後に書いた方が勝つ」へ仕様が
+        // 変わったときの fail-open も同時に塞ぐ。
+        ['後続の再宣言 (値 0)', "<?php\n\ndeclare(strict_types=1);\ndeclare(strict_types=0);\n", false, true],
+        ['後続の再宣言 (値 1)', "<?php\n\ndeclare(strict_types=1);\ndeclare(strict_types=1);\n", false, true],
+        ['値 01', "<?php\n\ndeclare(strict_types=01);\n", false, true],
+        ['値 0x1', "<?php\n\ndeclare(strict_types=0x1);\n", false, true],
+        ['値 0b1', "<?php\n\ndeclare(strict_types=0b1);\n", false, true],
+        ['複合指令', "<?php\n\ndeclare(ticks=1, strict_types=1);\n", false, true],
+        ['冗長な括弧', "<?php\n\ndeclare(strict_types=(1));\n", false, true],
+        ['2 文目の declare', "<?php\n\ndeclare(ticks=1);\ndeclare(strict_types=1);\n", false, true],
+        // 引数部を括弧の深さで追わないと、後続の strict_types を取りこぼす形
+        ['入れ子括弧つき複合指令', "<?php\n\ndeclare(ticks=(1), strict_types=1);\n", false, true],
+    ];
+}
+
+test('strict_types 判定器: 検体表どおりに判定する', function (): void {
+    foreach (strictTypesScannerSpecimens() as [$label, $source, $expected]) {
+        expect(StrictTypesDeclarationScanner::declaresStrictTypes($source))
+            ->toBe($expected, "検体『{$label}』の判定が期待と違います");
+    }
+});
+
+test('strict_types 判定器: 実効性の下界である (逆向きの乖離が 0 件)', function (): void {
+    foreach (strictTypesScannerSpecimens() as [$label, $source, $expectedDeclared, $expectedInEffect]) {
+        $inEffect = StrictTypesRuntimeProbe::strictTypesInEffect($source);
+
+        expect($inEffect)->toBe($expectedInEffect, "検体『{$label}』の実測が期待と違います");
+
+        if ($expectedDeclared) {
+            expect($inEffect)->toBeTrue(
+                "検体『{$label}』は判定器が宣言済みと言うのに厳密化が効いていません (gate が嘘をつく向きの乖離)"
+            );
+        }
+    }
+});
+
+test('strict_types 実測器: 読み込めないソースは false を返す', function (): void {
+    // declare(strict_types=true); は Fatal error になり読み込めない = 厳密化が成立しない
+    expect(StrictTypesRuntimeProbe::strictTypesInEffect("<?php\n\ndeclare(strict_types=true);\n"))
+        ->toBeFalse();
+});
+
+test('strict_types 実測器: 検体自身の出力を判定材料にしない', function (): void {
+    // 検体が 'STRICT' と出力して exit しても真にならない (プローブへ到達していない)
+    expect(fn () => StrictTypesRuntimeProbe::strictTypesInEffect(
+        "<?php\n\ndeclare(strict_types=1);\necho 'STRICT';\nexit;\n"
+    ))->toThrow(RuntimeException::class);
+
+    /*
+     * PHP の閉じタグで閉じた検体は、追記したプローブが PHP コードとして解釈されず
+     * そのまま出力される = 実測不能として例外にする。
+     * (このコメントを 1 行コメントで書かないこと。閉じタグは 1 行コメントを終端する。)
+     */
+    expect(fn () => StrictTypesRuntimeProbe::strictTypesInEffect(
+        "<?php\n\ndeclare(strict_types=1);\n?>\n"
+    ))->toThrow(RuntimeException::class);
+});
+
+test('strict_types 判定器: 実ファイルに対して疎通する', function (): void {
+    $declared = file_get_contents(base_path('tests/Support/PhpTokenScan.php'));
+    expect($declared)->not->toBeFalse();
+    expect(StrictTypesDeclarationScanner::declaresStrictTypes((string) $declared))->toBeTrue();
+
+    // blade は PHP ソースファイルではない (gate の母集団にも入らない)
+    $blade = file_get_contents(base_path('resources/views/app.blade.php'));
+    expect($blade)->not->toBeFalse();
+    expect(StrictTypesDeclarationScanner::declaresStrictTypes((string) $blade))->toBeFalse();
+});
diff --git a/tests/Unit/Architecture/TrackedPhpSourceFilesTest.php b/tests/Unit/Architecture/TrackedPhpSourceFilesTest.php
new file mode 100644
index 0000000..3073bad
--- /dev/null
+++ b/tests/Unit/Architecture/TrackedPhpSourceFilesTest.php
@@ -0,0 +1,122 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Process\Process;
+use Tests\Support\TrackedPhpSourceFiles;
+
+/*
+ * `TrackedPhpSourceFiles` の列挙結果を、一時 git リポジトリを作って固定する。
+ *
+ * ★実リポジトリでは負の対照 (未追跡ファイル・index に残った削除済みファイル) を
+ *   作れないため、検体は一時ディレクトリに用意する。
+ * ★git が無い / git worktree でない場所を渡したときは**空配列で黙らず例外**にする。
+ *   ここで黙ると、この列挙器に乗る gate が丸ごと空振りして緑になる (fail-open)。
+ */
+
+/**
+ * 検体用の一時 git リポジトリを作る。
+ *
+ * @return string 作成したディレクトリの絶対パス
+ */
+function trackedPhpSourceFilesFixtureRepository(): string
+{
+    // --parallel での衝突を避けるため、名前の確保は tempnam() で行う
+    // (実ファイルが作られるので、消してから同名のディレクトリにする)。
+    $path = tempnam(sys_get_temp_dir(), 'tracked-php-');
+    if ($path === false) {
+        throw new RuntimeException('検体用の一時ディレクトリ名を確保できませんでした');
+    }
+    unlink($path);
+    if (! mkdir($path, 0o700) && ! is_dir($path)) {
+        throw new RuntimeException("検体用の一時ディレクトリを作れませんでした: {$path}");
+    }
+
+    $run = function (array $command) use ($path): void {
+        $process = new Process($command, $path);
+        $process->run();
+        if (! $process->isSuccessful()) {
+            throw new RuntimeException(
+                '検体リポジトリの準備に失敗しました: '.implode(' ', $command).' / '.$process->getErrorOutput()
+            );
+        }
+    };
+
+    $run(['git', 'init', '-q']);
+
+    mkdir($path.'/sub', 0o700);
+    file_put_contents($path.'/a.php', "<?php\n");
+    file_put_contents($path.'/sub/b.php', "<?php\n");
+    file_put_contents($path.'/c.blade.php', "<div></div>\n");
+    file_put_contents($path.'/d.txt', "text\n");
+    file_put_contents($path.'/tracked-then-deleted.php', "<?php\n");
+
+    $run(['git', 'add', 'a.php', 'sub/b.php', 'c.blade.php', 'd.txt', 'tracked-then-deleted.php']);
+
+    // 追跡した後にファイルだけ消す (index には残る)
+    unlink($path.'/tracked-then-deleted.php');
+
+    // 未追跡ファイル
+    file_put_contents($path.'/untracked.php', "<?php\n");
+
+    return $path;
+}
+
+/** 確保した一時ディレクトリだけを再帰削除する (誤ったパスを消さないための guard 付き)。 */
+function trackedPhpSourceFilesCleanup(string $path): void
+{
+    $expectedPrefix = rtrim(sys_get_temp_dir(), '/').'/tracked-php-';
+    if (! str_starts_with($path, $expectedPrefix) || ! is_dir($path)) {
+        throw new RuntimeException("後片付けの対象が想定外のパスです: {$path}");
+    }
+
+    $process = new Process(['rm', '-rf', $path]);
+    $process->run();
+}
+
+test('追跡下 PHP 列挙器: 追跡下の *.php だけを昇順で返す', function (): void {
+    $repository = trackedPhpSourceFilesFixtureRepository();
+
+    try {
+        $files = TrackedPhpSourceFiles::all($repository);
+        $relatives = array_column($files, 'relative');
+
+        // 正の対照: 追跡下の *.php だけが昇順で並ぶ
+        //   負の対照 1: blade (c.blade.php) を含まない
+        //   負の対照 2: 未追跡 (untracked.php) を含まない
+        //   負の対照 3: 拡張子違い (d.txt) を含まない
+        //   負の対照 4: index に残った削除済み (tracked-then-deleted.php) を含まない
+        expect($relatives)->toBe(['a.php', 'sub/b.php']);
+
+        // absolute は root からの実在パスであること
+        foreach ($files as $file) {
+            expect($file['absolute'])->toBe($repository.'/'.$file['relative']);
+            expect(is_file($file['absolute']))->toBeTrue();
+        }
+    } finally {
+        trackedPhpSourceFilesCleanup($repository);
+    }
+});
+
+test('追跡下 PHP 列挙器: git worktree でない場所は空配列でなく例外にする', function (): void {
+    $path = tempnam(sys_get_temp_dir(), 'tracked-php-');
+    expect($path)->not->toBeFalse();
+    unlink((string) $path);
+    mkdir((string) $path, 0o700);
+
+    try {
+        expect(fn () => TrackedPhpSourceFiles::all((string) $path))->toThrow(RuntimeException::class);
+    } finally {
+        trackedPhpSourceFilesCleanup((string) $path);
+    }
+});
+
+test('追跡下 PHP 列挙器: 実リポジトリに対して疎通する', function (): void {
+    $files = TrackedPhpSourceFiles::all(base_path());
+    $relatives = array_column($files, 'relative');
+
+    expect(count($files))->toBeGreaterThanOrEqual(1400);
+    expect($relatives)->toContain('tests/Support/TrackedPhpSourceFiles.php');
+    // blade が母集団に入らないことを実リポジトリでも確認する
+    expect($relatives)->not->toContain('resources/views/app.blade.php');
+});

```

## テスト結果

```
composer test        : 4801 tests / 4799 passed / 0 failed / 2 skipped / 20378 assertions
composer phpstan     : No errors (level 10, 916 files)
vendor/bin/pint --test: passed
pnpm lint / typecheck : passed
pnpm test            : 136 files / 1501 tests passed
pnpm build           : built
pnpm typecheck:packages / build:packages / test:packages : passed (10 files / 106 tests)
composer test:browser: chromium 25 passed + 3 skipped / webkit 25 passed + 3 skipped

テストファーストの実測 4 段:
  段 1 施策 4 の前に gate を実行 → 赤 (未宣言 32 件を列挙。設計の一覧と完全一致)
  段 2 32 本へ宣言を追加 → 緑
  段 3 config/mail.php の宣言を一時的に外す → 赤 (1 件を列挙)。復元済み
  段 4 走査域を app/ へ一時的に狭める → 赤 (760 < 1400 の床値で落ちる)。復元済み

```

## 実装メモ (devnotes)

# T167 実装メモ: declare(strict_types=1) の全数強制 gate

- 設計: `devnotes/20260815-1534-strict-types-baseline-gate/detailed-design.md`
- ブランチ: `todo/T167`

## 実装順序 (設計どおり 2 → 1 → 3 → 4 → 5)

1. 施策 2: `tests/Support/StrictTypesDeclarationScanner.php` /
   `tests/Support/StrictTypesRuntimeProbe.php` /
   `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php`
2. 施策 1: `tests/Support/TrackedPhpSourceFiles.php` /
   `tests/Unit/Architecture/TrackedPhpSourceFilesTest.php` /
   `tests/Architecture/NoNonCompoundGlobalUseTest.php` (内部ヘルパの付け替え)
3. 施策 3: `tests/Architecture/StrictTypesDeclarationGateTest.php`
4. 施策 4: 未宣言 32 本への宣言追加
5. 施策 5: `AGENTS.md` / `docs/template-divergence.md` D15

## 判定器と実測器の突き合わせ (実装前に PHP 8.4.24 で実測)

設計の表を実装した検体表で再実測し、**すべて設計どおり**だった。

| 検体 | 判定器 | 実測 |
|------|--------|------|
| 正準形 / 先頭コメント / 大文字 / 空白揺れ / 後続 `declare(ticks=1)` | true | true |
| コメント内の記述のみ / 文字列リテラル内のみ / 値 0 / ブロック形 / 後置 / 別指令のみ / 宣言なし / `<?php` の前に文字 / 配列リテラルのキー | false | false |
| 後続の再宣言 (値 0 / 値 1) / `01` / `0x1` / `0b1` / 複合指令 / 冗長な括弧 / 2 文目の declare / 入れ子括弧つき複合指令 | false | **true** (安全側の乖離) |

逆向きの乖離 (判定器 true / 実測 false) は **0 件**。実測器の健全性も確認した
(読み込めない源 = false、自前出力 + `exit` = 例外、閉じタグで閉じた源 = 例外)。

## テストファーストの 4 段 (実測記録)

| 段 | 操作 | 結果 |
|----|------|------|
| 1 | 施策 4 の前に gate を実行 | **赤**。未宣言 32 件を列挙し、設計の一覧と完全一致 |
| 2 | 施策 4 で 32 本に宣言を追加して再実行 | **緑** |
| 3 | `config/mail.php` の宣言を一時的に外して再実行 | **赤** (`config/mail.php` 1 件を列挙)。実施後にファイルを復元済み |
| 4 | 走査域を `app/` へ一時的に狭めて再実行 | **赤** (`Failed asserting that 760 is equal to 1400 or is greater than 1400`)。実施後に gate を復元済み |

段 1 の 32 件は `app/` 4 / `config/` 18 / `database/migrations/` 7 / `bootstrap/` 2 /
`public/` 1 で、設計の前提実測値と一致した。

## 実装中に見つけた PHP の落とし穴 (記録)

`?>` は**1 行コメント (`//`) を終端して PHP モードを抜ける**。検体の説明を
`// ?> で閉じた検体は…` と 1 行コメントで書いたところ、以降がインライン HTML として
扱われ parse error になった (`vendor/bin/pint --test` が検出)。同種の説明は
ブロックコメントで書く (ブロックコメント内の `?>` は終端しない)。該当箇所には
再発防止の注意書きを残した。

## 走査域の性質を実測で確認したこと

`TrackedPhpSourceFiles` は **git 追跡下**しか列挙しない。新規テストファイルを
`git add` する前は gate の母集団に入らず、`TrackedPhpSourceFilesTest` の
「実リポジトリに対して疎通する」が赤くなることで気付いた (設計どおりの挙動)。
gate が守る境界が commit / CI であることの実地確認になっている。

## 検証コマンドの結果

| コマンド | 結果 |
|----------|------|
| `composer test` | 4801 tests / 4799 passed / 0 failed / 2 skipped |
| `composer phpstan` | No errors (level 10、916 ファイル) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` / `pnpm typecheck` | passed |
| `pnpm test` | 136 files / 1501 tests passed |
| `pnpm build` | built |
| `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | passed (10 files / 106 tests) |
| `composer test:browser` | chromium 25 passed / 3 skipped、webkit 25 passed / 3 skipped |

### 起動確認 (config cache の影響を外した順序)

1. `php artisan config:clear` → OK
2. `php artisan route:list` → `Showing [211] routes`
3. `php artisan config:cache` → OK / `php artisan config:clear` → OK
4. `php -l public/index.php` / `php -l bootstrap/app.php` / `php -l bootstrap/providers.php` → syntax OK
5. `public/index.php` を**実サーバで起動**して疎通: `php -S 127.0.0.1:8099 -t public public/index.php`
   に対する `GET /up` が **HTTP 200**

> 補足: 設計の施策 4 テスト計画は「`public/index.php` は `composer test:browser` でのみ通る経路」と
> 書いているが、Browser lane は in-process サーバ (テストプロセス自身の HttpKernel) で走るため
> **`public/index.php` を読み込まない**。そこで上の 5 を追加し、`public/index.php` を実際に
> front controller として起動する経路で直接確認した (`composer test:browser` も設計どおり実行して緑)。

