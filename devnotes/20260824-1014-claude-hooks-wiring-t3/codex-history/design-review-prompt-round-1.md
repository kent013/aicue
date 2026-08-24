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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
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
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- 本件は Claude Code の hook 配線 (開発時のみ動く機構) の変更であり、アプリの実行時コードを 1 行も変えない。UI/frontend の変更も無い。したがって DESIGN.md / Atomic Design / Inertia Props / DTO の観点は該当箇所が無ければ「該当なし」と述べてよい。
- 本件は家系 (6 リポジトリ) の機能台帳 lctl が確定した正典 t3 への追従である。正典の不変条件 i1〜i15 は合議で確定済みで、正典そのものの再議論は本設計の役割ではない。ただし「正典の解釈が誤っている」「aicue の既存不変条件を壊す」「テストが実際には赤にならない/緑にならない」という指摘は歓迎する。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）— とくに bash / JSON / PHP の境界の実挙動
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、テストファーストで本当に赤くなるか）
5. DTO/JsonResource パターンの遵守（該当なしなら該当なしと述べる）
6. Inertia Props vs API Responseの使い分け（同上）
7. 副作用・後退リスク
8. 波及変更の網羅性（他の検査・文書・台帳が変更対象に含まれているか）
9. セキュリティ（AGENTS.md のセキュリティ不変条件。とくに「保証範囲を誇張しない」規約と、hook の終了コード意味論の変更による退行）
10. DESIGN.md準拠 / 11. Atomic Design準拠（UI 変更を含む場合のみ）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: claude-hooks-wiring-t3 (家系正典 t3 への追従)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

本 TODO は**開発の進め方の層** (Claude Code の hook 配線) であり、アプリの実行時コードを 1 行も変えない。
使命への貢献は「6 リポジトリで同じ開発規律が効く」という間接効果に限る (誇張しない)。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

**本件で触れるのは 1 (テスト) だけである** — 4〜8 は該当箇所が無い (アプリの実行時コードを変えない)。
2 は新設する PHP の純関数に `mixed` を残さないことで守る。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）— 本件は DB を触らないため該当なし
- **DTO + JsonResource** パターン — 本件は該当なし
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント（git 追跡下の PHP 全数が対象）
- **禁止する文**: `echo` / `goto` / `global` / 開始タグ付きの出力記法は書かない
  (`ForbiddenStatementTokenInvariantTest` が字句で検出する)

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) (Codex `gpt-5.6-terra` Round 5 で APPROVED)
- 正典: lctl feature `claude-hooks-wiring` / design doc_sha `f75752ce3010` / 正典の版 **t3** / 不変条件 i1〜i15
- 台帳のセル: aicue = `update_pending` / `pre-t3` → `t3`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 配線台帳検査を正典 t3 の向きへ反転する (i5/i7 の静的層 + pass-through の実起動層) | `tests/Architecture/ClaudeHooksWiringTest.php` | 最高 |
| S2 | 内側の上限と配線の時間切れの数値比較を新設する (i8) | `tests/Architecture/ClaudeHooksWiringTest.php` / `scripts/code-review-graph-update-hook.sh` | 高 |
| S3 | ローカル層のトップレベルを全数申告制にする (i10) | `tests/Architecture/ClaudeHooksWiringTest.php` | 高 |
| S4 | 起動子を直呼び 1 行へ戻す (i5/i6/i7) | `.claude/settings.json` | 最高 |
| S5 | bug-hunt ガードの拒否コードを 97 → 2 にする (i7 の従属変更) | `scripts/bughunt-worktree-hook.sh` / `scripts/README.md` | 最高 |
| S6 | 塞がない脅威 3 点と、覆わない編集経路の回収根拠・撤回規則を書く (i14/i15) | `tests/Architecture/ClaudeHooksWiringTest.php` / `AGENTS.md` | 高 |
| S7 | 乖離台帳の移送 (D18 縮小 + D50 新設 + 採用時債務の 1 行削除 + 件数 pin) | `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php` / `tests/Support/TemplateDivergence/adoption-debt.tsv` | 高 |

**実装順序 (テストファースト)**: S1 → S2 → S3 → (ここまでで赤を確認) → S4 → S5 → S6 → S7。
S1〜S3 の検査を先に書くと**現行の設定・スクリプトで必ず落ちる** (下記「テスト計画」の赤の条件)。

### 波及変更 (全施策共通の棚卸し)

- TypeScript 型定義: **なし** (frontend を 1 行も触らない)
- Inertia Props / API Resource / DTO: **なし**
- route / migration: **なし**
- テストファイル: `tests/Architecture/ClaudeHooksWiringTest.php` のみ。
  他に本件の 4 ファイルを参照する検査は無いことを確認済み
  (`grep -rl "DENY_EXIT_CODE\|bughunt-worktree-hook"` の結果は追跡下では
  `.claude/settings.json` / `AGENTS.md` / `docs/template-divergence.md` /
  `docs/template-fingerprints.json` / `docs/worktree-isolation-strategy.md` /
  `scripts/README.md` / `scripts/bughunt-worktree-hook.sh` / 本検査の 8 件。
  `docs/worktree-isolation-strategy.md` は拒否コードに触れていないので変更不要)
- 文書: `AGENTS.md` のマーカー区間と `scripts/README.md` の 1 行 (下記 S5/S6)
- 乖離台帳: S7 (必須。`docs/template-fingerprints.json` の母集合に 5 パスが在る)

---

## S1: 配線台帳検査を正典 t3 の向きへ反転する

### 変更箇所

- `tests/Architecture/ClaudeHooksWiringTest.php`
  - L9-23 冒頭 docblock (層の説明 + i14/i15 は S6)
  - L36-53 `CLAUDE_HOOKS_WIRING` (`deny_exit_code` を落とす)
  - L123-144 `claudeHooksExpectedCommand()`
  - L499-527 S05/S06 (`deny_exit_code` の引数を落とす)
  - L529-563 S06b (**判定を反転**)
  - L1063-1087 / L1120-1148 / L1150-1165 拒否コードの期待値 97 → 2 (S5 と連動)
  - L1209-1276 B41〜B50 (**写像の実証 → pass-through の実証**)
  - L1278-1305 B51 (維持。番号だけ繰り上げ)

### 現行コード (要点)

```php
const CLAUDE_HOOKS_WIRING = [
    'PreToolUse' => [
        ['matcher' => 'Bash', 'script' => 'scripts/bughunt-worktree-hook.sh', 'timeout' => 10, 'deny_exit_code' => 97],
    ],
    'PostToolUse' => [
        ['matcher' => 'Write|Edit', 'script' => 'scripts/code-review-graph-update-hook.sh', 'timeout' => 30, 'deny_exit_code' => null],
    ],
];

function claudeHooksExpectedCommand(string $script, ?int $denyExitCode): string
{
    $conditions = '[ -n "$d" ] && [ "${d#/}" != "$d" ] && [ "${d//../}" = "$d" ]'
        .' && [ -d "$d/scripts" ] && [ ! -L "$d/scripts" ] && [ -f "$f" ] && [ ! -L "$f" ]';
    $inner = 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/'.$script.'; ';
    $inner .= $denyExitCode === null
        ? 'if '.$conditions.'; then /bin/bash -p "$f"; fi; exit 0'
        : 's=0; if '.$conditions.'; then /bin/bash -p "$f"; s=$?; fi; '
            .'if [ "$s" = '.$denyExitCode.' ]; then exit 2; fi; exit 0';

    return "/bin/bash -p -c '".$inner."'";
}
```

### 変更後コード

```php
/**
 * 配線台帳。ここに書かれた形と `.claude/settings.json` が完全一致しなければ落ちる。
 *
 * `matcher` の `Write|Edit` は **`Write` と `Edit` のときだけ**発火する。
 * 部分一致で将来の派生ツールを自動で拾うとは書かない (書くと嘘になる)。
 *
 * **拒否コードは台帳に持たない** (家系の正典 t3 の i7)。起動子は終了コードを写像しないので、
 * hook が返した値がそのまま harness へ届く — `PreToolUse` の **2 だけがブロック**で、
 * それ以外の非 0 はブロックしない異常として面に出る。
 *
 * @var array<string, list<array{matcher: string, script: string, timeout: int}>>
 */
const CLAUDE_HOOKS_WIRING = [
    'PreToolUse' => [
        ['matcher' => 'Bash', 'script' => 'scripts/bughunt-worktree-hook.sh', 'timeout' => 10],
    ],
    'PostToolUse' => [
        ['matcher' => 'Write|Edit', 'script' => 'scripts/code-review-graph-update-hook.sh', 'timeout' => 30],
    ],
];

/** bug-hunt ガードが拒否を表す終了コード (harness の唯一の拒否信号)。 */
const CLAUDE_HOOKS_DENY_EXIT_CODE = 2;

/**
 * 起動子の正準形を台帳側で組み立てる (設定を書き換えたら必ずここと食い違って落ちる)。
 *
 * 起動子の仕事は**スクリプトを起こすこと 1 つだけ**である (i5 / i6 / i7):
 *  - `/bin/bash` の絶対パス (起動子自身が検索パスで解決される形を禁じる)
 *  - `-p` (特権モード。継承したシェル関数と `BASH_ENV` / `ENV` を無効化する)
 *  - `$CLAUDE_PROJECT_DIR` を根にした絶対パスでスクリプトを直に起動する
 * 引数・条件分岐・終了コードの写像・インラインのシェル片は 1 つも持たない。
 */
function claudeHooksExpectedCommand(string $script): string
{
    return '/bin/bash -p "$CLAUDE_PROJECT_DIR/'.$script.'"';
}

/**
 * 起動子の形の違反を列挙する (純関数。走査器)。
 *
 * **走査対象**: 設定ファイルから取り出した起動コマンド文字列 1 本と、台帳側が組み立てた文字列 1 本。
 * **判定**: 半角空白 1 文字を区切りとしてトークンへ割り、**トークンの完全一致**で見る
 * (部分文字列一致や正規表現の語境界に頼らない = AGENTS.md 共通規約 (e))。
 * 期待するトークンは 3 個ちょうどで、順に `/bin/bash` / `-p` / `"$CLAUDE_PROJECT_DIR/<台帳のスクリプト>"`。
 *
 * **保証しないもの / fail-closed の倒し方**:
 *  - 区切りは**半角空白 1 文字だけ**である。タブ・改行・連続空白・引用符の内側の空白を含む形は
 *    「トークンへ割れない形」として**違反にする** (合格側へ倒さない)。したがって本走査は
 *    「引用の解釈が要る書き方」を許可しない = shell parser を持たない代わりに母集団を狭める
 *  - 起動先スクリプトの**中身**は見ない (隣接 feature の領分)。見るのは配線の文字列だけである
 *
 * @return list<string>
 */
function claudeHooksLauncherFormViolations(string $command, string $script): array
{
    $violations = [];

    // 解釈できない空白 (タブ・改行・連続空白) は割る前に落とす
    if (preg_match('/[\t\r\n]/', $command) === 1 || str_contains($command, '  ')) {
        $violations[] = "起動子をトークンへ割れない (タブ・改行・連続空白を含む): {$command}";

        return $violations;
    }

    $tokens = explode(' ', $command);
    $expected = ['/bin/bash', '-p', '"$CLAUDE_PROJECT_DIR/'.$script.'"'];

    if ($tokens !== $expected) {
        $violations[] = sprintf(
            '起動子が正準形でない (期待 %s / 実際 %s)',
            implode(' ', $expected),
            $command,
        );
    }

    // 「起動子が余計な仕事を持たない」ことを、正準形の一致とは独立にトークン語彙で見る。
    // (正準形の比較だけだと、期待値の側を緩めたときに何が失われたのか読めない)
    foreach (['-c', '&&', '||', ';', 'if', 'then', 'fi', 'exit', '[', 'eval', 'env'] as $forbidden) {
        if (in_array($forbidden, $tokens, true)) {
            $violations[] = "起動子が起動以外の仕事を持っている (禁止トークン {$forbidden}): {$command}";
        }
    }

    return $violations;
}
```

S06b の反転:

```php
test('S06b: 起動子が直呼び + privileged mode で、起動以外の仕事を 1 つも持たないこと', function (): void {
    // 設定の実文字列と台帳側の組み立ての**両方**を同じ述語に通す
    // (片方だけだと「台帳を緩めた」か「設定を緩めた」かのどちらかを取り逃がす)。
    $checked = 0;

    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
        foreach ($entries as $entry) {
            foreach ([
                '設定ファイル' => claudeHooksLauncherCommand($event),
                '台帳の組み立て' => claudeHooksExpectedCommand($entry['script']),
            ] as $source => $command) {
                $violations = claudeHooksLauncherFormViolations($command, $entry['script']);
                expect($violations)->toBe([], "{$event} ({$source}):\n".implode("\n", $violations));
            }
            $checked++;
        }
    }

    // 母集団が空でないこと (走査根の改名・台帳の空振りで緑にならないように)
    expect($checked)->toBe(2, '必須 2 配線を検査していない (i2)');
});

test('S06c (負のコントロール): 起動子の形の走査が違反を実際に検出すること', function (string $command): void {
    expect(claudeHooksLauncherFormViolations($command, 'scripts/x.sh'))->not->toBe([]);
})->with([
    'インライン形 (-c)' => ['/bin/bash -p -c \'d=${CLAUDE_PROJECT_DIR:-}; exit 0\''],
    '追加引数' => ['/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh" --verbose'],
    '条件分岐' => ['/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh"; if [ "$s" = 97 ]; then exit 2; fi'],
    'インラインのシェル片' => ['/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh" && echo done'],
    '起動子が検索パス解決' => ['bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh"'],
    '特権モードが無い' => ['/bin/bash "$CLAUDE_PROJECT_DIR/scripts/x.sh"'],
    '相対パス' => ['/bin/bash -p "scripts/x.sh"'],
    'タブ区切り (解釈できない形)' => ["/bin/bash -p\t\"\$CLAUDE_PROJECT_DIR/scripts/x.sh\""],
]);

test('S06d (正のコントロール): 正典どおりの形は違反ゼロであること', function (): void {
    expect(claudeHooksLauncherFormViolations('/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh"', 'scripts/x.sh'))
        ->toBe([]);
});
```

実起動層 (B41〜B47) の差し替え:

```php
test('B41: PreToolUse の起動子が内側の終了コードをそのまま返すこと', function (int $inner): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksWriteExitStub($sandbox.'/scripts/bughunt-worktree-hook.sh', $inner);
        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PreToolUse'), $sandbox);

        expect($result['exitCode'])->toBe($inner, "内側が {$inner} なのに起動子が畳んでいる");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    '通過 (0)' => [0],
    'ブロックしない異常 (1)' => [1],
    '拒否 (2)' => [2],
    'ブロックしない異常 (3)' => [3],
    '旧拒否コード (97) が特別扱いされないこと' => [97],
    '実行不能 (127)' => [127],
]);

test('B42: PostToolUse の起動子も内側の終了コードをそのまま返すこと', function (int $inner): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksWriteExitStub($sandbox.'/scripts/code-review-graph-update-hook.sh', $inner);
        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PostToolUse'), $sandbox);

        expect($result['exitCode'])->toBe($inner);
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([[0], [1], [3], [97]]);

test('B43: 標準入力が起動子を通って内側へそのまま届くこと', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        // 内側で標準入力を読んで書き出すスクリプト (payload が欠けたら中身が空になる)
        File::put($sandbox.'/scripts/bughunt-worktree-hook.sh', <<<BASH
        #!/bin/bash
        IFS= read -r -N 1048576 -t 5 payload || true
        printf '%s' "\${payload}" > '{$sandbox}/received.txt'
        exit 0
        BASH);
        chmod($sandbox.'/scripts/bughunt-worktree-hook.sh', 0700);

        $payload = claudeHooksBashPayload('ls -la');
        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PreToolUse'), $sandbox, input: $payload);

        expect($result['exitCode'])->toBe(0);
        expect(claudeHooksReadFile($sandbox.'/received.txt'))->toBe($payload, '標準入力が内側へ届いていない');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B44: 起動先が無い / 起動元の位置が未設定なら 127 で終わり、ブロックにならないこと', function (string $case): void {
    $sandbox = claudeHooksSandbox();
    $projectDir = $sandbox;

    try {
        match ($case) {
            '起動先が無い' => File::delete($sandbox.'/scripts/bughunt-worktree-hook.sh'),
            'CLAUDE_PROJECT_DIR が無い' => $projectDir = null,
        };

        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PreToolUse'), $projectDir);

        // 127 = bash がスクリプトを開けない。**2 ではない**ので Bash ツールはブロックされない
        expect($result['exitCode'])->toBe(127, "{$case}: 起動できなかったのに 127 で終わっていない");
        expect($result['exitCode'])->not->toBe(CLAUDE_HOOKS_DENY_EXIT_CODE);
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with(['起動先が無い', 'CLAUDE_PROJECT_DIR が無い']);

test('B45 (i14 の非保証の実証): 起動子は起動先も起動元の位置も検証しないこと', function (string $case): void {
    // 旧実装 (写像器) が持っていた 7 条件の検証は t3 で撤去した。
    // 「撤去したこと」を実挙動で見えるようにしておく (黙って落とさない)。
    $sandbox = claudeHooksSandbox();
    $projectDir = $sandbox;
    $cwd = null;

    try {
        claudeHooksWriteExitStub($sandbox.'/scripts/bughunt-worktree-hook.sh', 0);

        match ($case) {
            '起動元の位置が相対値' => (function () use ($sandbox, &$projectDir, &$cwd): void {
                $projectDir = basename($sandbox);
                $cwd = dirname($sandbox);
            })(),
            '起動元の位置が .. を含む' => $projectDir = dirname($sandbox).'/../'.basename(dirname($sandbox)).'/'.basename($sandbox),
            '起動先が symlink' => (function () use ($sandbox): void {
                claudeHooksWriteExitStub($sandbox.'/scripts/real-hook.sh', 0);
                File::delete($sandbox.'/scripts/bughunt-worktree-hook.sh');
                symlink($sandbox.'/scripts/real-hook.sh', $sandbox.'/scripts/bughunt-worktree-hook.sh');
            })(),
        };

        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PreToolUse'), $projectDir, $cwd);

        expect($result['exitCode'])->toBe(0, "{$case}: 起動子が内側を起こしていない (t3 では検証しないのが正)");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with(['起動元の位置が相対値', '起動元の位置が .. を含む', '起動先が symlink']);
```

`claudeHooksRunLauncher()` に `input` 引数を足す (既定は空文字列 = 現行と同じ挙動):

```php
function claudeHooksRunLauncher(string $command, ?string $projectDir, ?string $cwd = null, string $input = ''): array
{
    $env = ['/usr/bin/env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin'];
    if ($projectDir !== null) {
        $env[] = 'CLAUDE_PROJECT_DIR='.$projectDir;
    }

    return claudeHooksRun([...$env, '/bin/bash', '-c', $command], $input, $cwd);
}
```

B51 は内容を変えず **B46** へ改番する (`-p` の実証。i6)。
拒否コードを見ている既存テスト (B26 系 / B29 / B34〜B36) は `97` を
`CLAUDE_HOOKS_DENY_EXIT_CODE` へ差し替える (S5 と同じ変更で行う)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`claudeHooksLauncherFormViolations(): array` に `@return list<string>`)
- [x] null安全 (`claudeHooksLauncherCommand()` 内の `Assert` は既存のまま)
- [x] DTO を返している (該当なし。テストヘルパは `list<string>` を返す)
- [x] Genericsの型パラメータが正しい (`CLAUDE_HOOKS_WIRING` の `@var` から `deny_exit_code` を落とす)

### テスト計画

- [x] **先に赤くする**: S06b を反転して走らせると、現行の `.claude/settings.json`
      (`/bin/bash -p -c '…'`) が「禁止トークン `-c`」「正準形でない」で落ちる
- [x] **先に赤くする**: B41 を入れると現行の起動子は 97→2 / 他→0 に畳むので
      6 ケースのうち 5 ケースが落ちる (0 だけ通る)
- [x] 既存テストの更新: S05/S06 (`deny_exit_code` 引数の削除) / B26 系・B29・B34〜B36 (期待値 2)
- [x] 新規テスト: S06c (負例 8 形) / S06d (正例) / B43 (標準入力の到達) / B44 (127) / B45 (非保証の実証)
- [x] 個別の `DatabaseTransactions` を使っていない (本ファイルは DB を触らない)

### リスク

- **拒否がブロックになる経路が harness の 2 に一本化される**ため、`scripts/bughunt-worktree-hook.sh` に
  構文エラーが入ると Bash ツールが止まる。S09 (`bash -n`) が着地前に止める。
- B45 は「撤去した検証」を実挙動で固定するので、将来検証を復活させると赤くなる
  (それは**正典に反する変更**なので赤が正しい)。

---

## S2: 内側の上限と配線の時間切れの数値比較 (i8)

### 変更箇所

- `tests/Architecture/ClaudeHooksWiringTest.php` (新設: 内側上限の申告 const / 抽出の純関数 / S13・S13b・S13c)
- `tests/Architecture/ClaudeHooksWiringTest.php` L921-948 (B17 の `30.0` 直書き) / L950-966 (B18 の `'20 秒'` 直書き)
- `scripts/code-review-graph-update-hook.sh` L9 (実行契約の記述) / L138 (`timeout -k 5` → `-k 2`)

### 現行コード

```bash
# scripts/code-review-graph-update-hook.sh
#  5. 呼び出し側の時間切れ (30 秒) より内側 (20 秒) で自分から諦める
...
timeout -k 5 "${INNER_TIMEOUT_SECONDS}" \
    code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
```

```php
// B17 / B18 (抜粋)
expect($elapsed)->toBeLessThan(30.0, '呼び出し側 timeout (30 秒) を超えた');
expect($result['errorOutput'])->toContain('20 秒');
expect($result['elapsed'])->toBeLessThan(45.0, '内側の時間切れ (20 秒) が効いていない');
```

### 変更後コード

```bash
#  5. 内側の上限が呼び出し側の時間切れより**必ず小さい**:
#     標準入力待ち 5 秒 + 更新本体 20 秒 + KILL までの猶予 2 秒 = 最悪 27 秒 < 30 秒。
#     台帳テストがこの 3 値と `.claude/settings.json` の timeout を数値で取り出して比較する
...
timeout -k 2 "${INNER_TIMEOUT_SECONDS}" \
    code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
```

```php
/**
 * 内側の上限の申告 (i8)。値そのものはスクリプト本文から取り出すので**ここには書かない** —
 * 書くのは「どの数値を持つ契約か」だけである (数値を 2 か所に書くと必ず食い違う)。
 *
 * `body` / `kill` が false なのは、そのスクリプトが外部プロセスを 1 つも起こさないため
 * (bug-hunt ガードの判定は bash の組み込みだけで完結する)。
 *
 * @var array<string, array{stdin: bool, body: bool, kill: bool}>
 */
const CLAUDE_HOOKS_INNER_LIMIT_SHAPE = [
    'scripts/bughunt-worktree-hook.sh' => ['stdin' => true, 'body' => false, 'kill' => false],
    'scripts/code-review-graph-update-hook.sh' => ['stdin' => true, 'body' => true, 'kill' => true],
];

/**
 * 起動先が自分で諦める内側の上限を、スクリプト本文から**数値で**取り出す (純関数。走査器)。
 *
 * **走査対象**: 台帳の 2 スクリプトの本文。
 * **抽出する 3 値**:
 *  - `stdin` … `read -r -N <bytes> -t <秒>` の秒数 (標準入力を待つ上限)
 *  - `body`  … `readonly INNER_TIMEOUT_SECONDS=<秒>` (更新本体の上限)
 *  - `kill`  … `timeout -k <秒> "${INNER_TIMEOUT_SECONDS}"` の猶予 (TERM で終わらない相手を KILL するまで)
 *
 * **fail-closed**: 申告で `true` の値が抽出できない場合、申告で `false` の値が現れた場合、
 * 数値以外 (単位つき・変数展開) の場合は**違反として返す**。抽出できなかった値を 0 として
 * 合算へ混ぜない (見逃す方向へ倒さない = AGENTS.md 共通規約 (b))。
 *
 * @return array{limits: array{stdin: ?int, body: ?int, kill: ?int}, violations: list<string>}
 */
function claudeHooksInnerLimits(string $body, string $script): array
{
    /** @var array{stdin: bool, body: bool, kill: bool} $shape */
    $shape = CLAUDE_HOOKS_INNER_LIMIT_SHAPE[$script];

    $patterns = [
        'stdin' => '/read -r -N \d+ -t (\d+) /',
        'body' => '/^readonly INNER_TIMEOUT_SECONDS=(\d+)$/m',
        'kill' => '/timeout -k (\d+) "\$\{INNER_TIMEOUT_SECONDS\}"/',
    ];

    $limits = ['stdin' => null, 'body' => null, 'kill' => null];
    $violations = [];

    foreach ($patterns as $key => $pattern) {
        $found = preg_match($pattern, $body, $matches) === 1;

        if ($shape[$key] && ! $found) {
            $violations[] = "{$script}: 内側の上限 [{$key}] を数値として取り出せない (申告は必要)";

            continue;
        }
        if (! $shape[$key] && $found) {
            $violations[] = "{$script}: 申告に無い内側の上限 [{$key}] が現れた (申告を同じ変更で更新すること)";

            continue;
        }
        if ($found) {
            $limits[$key] = (int) $matches[1];
        }
    }

    return ['limits' => $limits, 'violations' => $violations];
}
```

```php
test('S13: 内側の上限の合計が配線の時間切れより小さいこと (数値を両方から取って比較する)', function (): void {
    $checked = 0;

    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
        foreach ($entries as $entry) {
            $extracted = claudeHooksInnerLimits(claudeHooksReadFile(base_path($entry['script'])), $entry['script']);
            expect($extracted['violations'])->toBe([], implode("\n", $extracted['violations']));

            // 設定ファイル側の timeout も**設定から**取る (台帳の写しではなく実値を見る)
            $harness = claudeHooksHookTimeout($event);
            expect($harness)->toBe($entry['timeout'], "{$event}: 設定の timeout が台帳と違う");

            $worst = array_sum(array_map(static fn (?int $v): int => $v ?? 0, $extracted['limits']));
            expect($worst)->toBeLessThan(
                $harness,
                sprintf('%s: 内側の上限の合計 %d 秒が配線の時間切れ %d 秒より内側でない', $event, $worst, $harness),
            );
            $checked++;
        }
    }

    expect($checked)->toBe(2, '必須 2 配線を検査していない (i2)');
});

test('S13b (負のコントロール): 内側の上限の走査が違反を実際に検出すること', function (string $body, string $script): void {
    $extracted = claudeHooksInnerLimits($body, $script);
    expect($extracted['violations'])->not->toBe([]);
})->with([
    '本体の上限が読めない (変数展開)' => [
        "IFS= read -r -N 1048576 -t 5 input\nreadonly INNER_TIMEOUT_SECONDS=\$FOO\ntimeout -k 2 \"\${INNER_TIMEOUT_SECONDS}\"\n",
        'scripts/code-review-graph-update-hook.sh',
    ],
    'KILL 猶予が単位つき' => [
        "IFS= read -r -N 1048576 -t 5 input\nreadonly INNER_TIMEOUT_SECONDS=20\ntimeout -k 2s \"\${INNER_TIMEOUT_SECONDS}\"\n",
        'scripts/code-review-graph-update-hook.sh',
    ],
    '標準入力待ちが無い' => [
        "readonly INNER_TIMEOUT_SECONDS=20\ntimeout -k 2 \"\${INNER_TIMEOUT_SECONDS}\"\n",
        'scripts/code-review-graph-update-hook.sh',
    ],
    '申告に無い上限が現れた' => [
        "IFS= read -r -N 1048576 -t 5 input\nreadonly INNER_TIMEOUT_SECONDS=20\n",
        'scripts/bughunt-worktree-hook.sh',
    ],
]);

test('S13c (負のコントロール): 数値の関係が崩れたら比較が落ちること', function (): void {
    // 現行値 (5 + 20 + 2 = 27 < 30) が、本体を 25 秒にすると 32 ≥ 30 で崩れることを純関数で示す。
    // 実ファイルを書き換えずに「関係の崩れ」を検出できることの裏取りである。
    $mutated = "IFS= read -r -N 1048576 -t 5 input\nreadonly INNER_TIMEOUT_SECONDS=25\n"
        ."timeout -k 2 \"\${INNER_TIMEOUT_SECONDS}\"\n";
    $extracted = claudeHooksInnerLimits($mutated, 'scripts/code-review-graph-update-hook.sh');

    expect($extracted['violations'])->toBe([]);
    $worst = array_sum(array_map(static fn (?int $v): int => $v ?? 0, $extracted['limits']));
    expect($worst)->toBeGreaterThanOrEqual(30, '変異した入力で関係が崩れていない (負例が機能していない)');
});
```

設定から timeout を取るヘルパ (既存の `claudeHooksLauncherCommand()` と同じ形):

```php
/** 設定ファイルから hook の時間切れを取り出す。 */
function claudeHooksHookTimeout(string $event): int
{
    $settings = claudeHooksSettings();
    Assert::isArray($settings['hooks']);
    Assert::keyExists($settings['hooks'], $event);
    $group = $settings['hooks'][$event];
    Assert::isArray($group);
    Assert::isArray($group[0]);
    Assert::isArray($group[0]['hooks']);
    Assert::isArray($group[0]['hooks'][0]);
    $timeout = $group[0]['hooks'][0]['timeout'];
    Assert::integer($timeout);

    return $timeout;
}
```

B17 / B18 の直書きの除去:

```php
// B17
expect($elapsed)->toBeLessThan(
    (float) claudeHooksHookTimeout('PostToolUse'),
    '呼び出し側 timeout を超えた',
);

// B18
$inner = claudeHooksInnerLimits(
    claudeHooksReadFile(base_path('scripts/code-review-graph-update-hook.sh')),
    'scripts/code-review-graph-update-hook.sh',
)['limits']['body'];
Assert::integer($inner);
expect($result['errorOutput'])->toContain("{$inner} 秒");
expect($result['elapsed'])->toBeLessThan((float) $inner + 25.0, '内側の時間切れが効いていない');
```

### PHPStan適合チェック

- [x] `claudeHooksInnerLimits()` の戻り値は shape 付き配列 (`?int` を明示)
- [x] `null` 安全: 合算前に `?? 0` へ落とすのは**申告で false の値だけ**である
      (申告で true の値は抽出できなければ違反として先に返るので 0 混入が起きない)
- [x] `Assert::integer()` で `mixed` を narrow してから比較へ渡す
- [x] Generics: `CLAUDE_HOOKS_INNER_LIMIT_SHAPE` に `@var array<string, array{stdin: bool, body: bool, kill: bool}>`

### テスト計画

- [x] **先に赤くする**: S13 を入れると現行の `timeout -k 5` で 5+20+5=30 ≥ 30 となり落ちる
      (**この赤が `-k 2` へ変える唯一の理由**である)
- [x] 新規テスト: S13 / S13b (負例 4 形) / S13c (関係の崩れ)
- [x] 既存テスト更新: B17 / B18 の直書きを設定・スクリプト由来へ
- [x] `bash -n` (S09) と B18 の実挙動で `-k 2` が壊れていないことを確認

### リスク

- `-k 2` は「TERM を無視する相手を KILL するまでの猶予」が 3 秒短くなる。索引ツールが
  TERM を無視して 2 秒以内に終わらない場合、KILL される (現行も 5 秒後に KILL される
  = 差は待ち時間だけで、結果は同じ)。**KILL 猶予そのものが効くことの実測 (家系の motivation が持つ)
  は本件では持たない** — 前提は GNU coreutils の仕様であり、i8 が要求するのは数値の関係だけである。

---

## S3: ローカル層のトップレベルを全数申告制にする (i10)

### 変更箇所

- `tests/Architecture/ClaudeHooksWiringTest.php` L565-577 (S07 を差し替え) + 申告 const + 純関数 + 負例

### 現行コード

```php
test('S07: .claude/settings.local.json は hooks キーを持てないこと (常設配線をローカルから殺さない)', function (): void {
    $path = base_path('.claude/settings.local.json');
    if (! is_file($path)) {
        expect(true)->toBeTrue('ローカル設定は無い (常設配線を上書きする経路も無い)');

        return;
    }

    $decoded = json_decode(claudeHooksReadFile($path), true);
    Assert::isArray($decoded);
    expect(array_key_exists('hooks', $decoded))
        ->toBeFalse('.claude/settings.local.json に hooks を置かないこと (常設配線をローカルから殺す経路になる)');
});
```

### 変更後コード

```php
/**
 * ローカル層 (`.claude/settings.local.json`) のトップレベルに置いてよい項目 (全数申告制)。
 *
 * **現在は空である** = ローカル層はどのトップレベル項目も持てない。常設配線をローカルから
 * 無効化する経路を作らないためで、hook を止める個別の設定項目 (`disableAllHooks` 等) を
 * 名指しで並べる形は採らない — 全数申告は**未知の項目も拒む**ので上位互換であり、
 * 正本を持たない外部の設定スキーマへ追随し続ける負債を作らない (家系の正典 t3 の i10)。
 *
 * 置きたい項目が出たら**ここを同じ変更で更新する**。ただし `hooks` は申告に足せない
 * (S07c が固定する)。
 *
 * @var list<string>
 */
const CLAUDE_HOOKS_LOCAL_TOP_LEVEL_KEYS = [];

/**
 * ローカル層の設定の違反を列挙する (純関数。走査器)。
 *
 * **走査対象**: `.claude/settings.local.json` の生バイト列。
 * **判定**: トップレベルの項目名の集合を申告と突き合わせる (値は見ない)。
 *
 * **fail-closed の 2 分類** (どちらも合格側へ倒さない):
 *  - JSON の構文が壊れている (`JsonException`)
 *  - 構文は正しいがトップレベルが JSON オブジェクトでない
 *
 * `json_decode(..., associative: true)` は使わない — 連想配列へ落とすと `{}` と `[]` が
 * どちらも `[]` になり、「オブジェクトでない」を検出できなくなる。
 *
 * @return list<string>
 */
function claudeHooksLocalSettingsViolations(string $json): array
{
    try {
        /** @var mixed $decoded */
        $decoded = json_decode(json: $json, associative: false, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return ['ローカル設定が JSON として壊れている: '.$exception->getMessage()];
    }

    if (! $decoded instanceof stdClass) {
        return ['ローカル設定のトップレベルが JSON オブジェクトでない'];
    }

    $keys = array_keys(get_object_vars($decoded));
    $violations = [];

    // `hooks` は申告の中身に関わらず必ず違反 (常設配線をローカルから殺す経路そのもの)
    if (in_array('hooks', $keys, true)) {
        $violations[] = 'ローカル設定に hooks がある (常設配線をローカルから無効化する経路を作らない)';
    }

    foreach (array_values(array_diff($keys, CLAUDE_HOOKS_LOCAL_TOP_LEVEL_KEYS, ['hooks'])) as $unexpected) {
        $violations[] = "ローカル設定に申告の無いトップレベル項目がある: {$unexpected}";
    }

    return $violations;
}
```

```php
test('S07: .claude/settings.local.json のトップレベルが全数申告どおりであること (i10)', function (): void {
    $path = base_path('.claude/settings.local.json');

    if (! is_file($path)) {
        // ファイルが無い = 上書きする経路が無い。**空振りではない**ことを明示する
        // (「存在するときは全キーを照合する」ことは S07b が合成入力で固定する)
        expect(CLAUDE_HOOKS_LOCAL_TOP_LEVEL_KEYS)->toBe([], 'ローカル層に置ける項目の申告が空でない');

        return;
    }

    $violations = claudeHooksLocalSettingsViolations(claudeHooksReadFile($path));
    expect($violations)->toBe([], implode("\n", $violations));
});

test('S07b (負のコントロール): ローカル層の走査が違反を実際に検出すること', function (string $json): void {
    expect(claudeHooksLocalSettingsViolations($json))->not->toBe([]);
})->with([
    'hooks を持つ' => ['{"hooks": {}}'],
    '申告に無い項目を持つ' => ['{"permissions": {"allow": []}}'],
    'トップレベルがオブジェクトでない' => ['[]'],
    'JSON の構文が壊れている' => ['{'],
]);

test('S07c: 空のオブジェクトは合格し、申告に hooks を足せないこと', function (): void {
    expect(claudeHooksLocalSettingsViolations('{}'))->toBe([]);
    expect(in_array('hooks', CLAUDE_HOOKS_LOCAL_TOP_LEVEL_KEYS, true))
        ->toBeFalse('申告に hooks を足してはならない (i10)');
});
```

### PHPStan適合チェック

- [x] `mixed` を `stdClass` へ narrow してからキー集合を取る
- [x] 戻り値は `list<string>` (`array_values()` で再添字)
- [x] `JsonException` を捕まえる (`JSON_THROW_ON_ERROR` を付けたので投げる)
- [x] 配列返却ではあるが DTO 化しない (テスト内の走査器であり、既存の違反リスト方式に合わせる)

### テスト計画

- [x] **先に赤くする**: 合成入力 `{"permissions": {...}}` は現行 S07 では通る (hooks が無い) が
      新しい走査では落ちる = S07b の 2 番目が旧実装で緑になることをもって「拡張が効いた」ことを示す
      (旧 S07 は文字列ではなくファイルしか見ないので、負例そのものを先に書いて赤を作る)
- [x] 新規テスト: S07b (負例 4 形) / S07c (正例 + 申告の制約)
- [x] 現況 (ファイル不在) でも空振りにならないよう、申告の空を明示的に検査する

### リスク

- 開発者が個人的に `.claude/settings.local.json` を作ると**そのワークスペースで検査が赤くなる**。
  これは i10 の意図した挙動である (ローカル層に何かを置くなら台帳を同じ変更で更新する)。
  CI は未追跡ファイルを見ないので、CI が赤くなることはない。

---

## S4: 起動子を直呼び 1 行へ戻す (i5/i6/i7)

### 変更箇所

- `.claude/settings.json` L9 / L21 (2 本の `command`)

### 変更後コード

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash",
        "hooks": [
          {
            "type": "command",
            "command": "/bin/bash -p \"$CLAUDE_PROJECT_DIR/scripts/bughunt-worktree-hook.sh\"",
            "timeout": 10
          }
        ]
      }
    ],
    "PostToolUse": [
      {
        "matcher": "Write|Edit",
        "hooks": [
          {
            "type": "command",
            "command": "/bin/bash -p \"$CLAUDE_PROJECT_DIR/scripts/code-review-graph-update-hook.sh\"",
            "timeout": 30
          }
        ]
      }
    ]
  }
}
```

- `matcher` / `timeout` / キーの順序 (`type` → `command` → `timeout`) は動かさない
  (S05/S06 が `array_keys()` の順序まで固定している)。
- トップレベルは `hooks` だけ (S03 の全数申告のまま)。

### テスト計画

- [x] S05/S06 (完全一致) / S06b (形) / B41〜B46 (実起動) が緑になること
- [x] **後方互換の並走を残さない**: 写像器の分岐を残した「移行用の設定」は作らない

### リスク

- 配線は**新しいセッションを開始するまで反映されない**。実装セッション内では旧配線
  (写像器つき) が動き続けるため、`bug-hunt provision` の直叩きは実装中も 2 ではなく
  旧経路でブロックされる。実配線の確認は次セッションの人手確認に回す (実装完了の判定条件には
  含めない — テストは配線文字列そのものを別プロセスで起こして検証するため)。

---

## S5: bug-hunt ガードの拒否コードを 97 → 2 にする (i7 の従属変更)

### 変更箇所

- `scripts/bughunt-worktree-hook.sh` L49-51 (コメント + 定数)
- `scripts/README.md` L52 (台帳の行)
- `tests/Architecture/ClaudeHooksWiringTest.php` の期待値 (S1 と同じ変更で行う)

### 現行コード

```bash
# 拒否は終了コード 97 で表す。.claude/settings.json の起動子が 97 だけを 2 へ写像するため、
# 構文エラー (2) や実行不能 (126/127) が Bash ツールをブロックすることは無い。
readonly DENY_EXIT_CODE=97
```

### 変更後コード

```bash
# 拒否は終了コード 2 で表す — これは harness が「この操作をブロックせよ」と解釈する唯一の値である
# (`PreToolUse` の 2 だけがブロックで、それ以外の非 0 はブロックしない異常として面に出る)。
# 起動子は終了コードを写像しないので、ここで返す値がそのまま harness へ届く (家系の正典 t3 の i7)。
# **帰結として、このスクリプトの構文エラー (bash が返す 2) もブロックになる**。畳んで隠さないのは、
# 畳むと配線ミスと実行時の異常を harness も人も区別できなくなるからである。
# 構文エラーが main へ着くこと自体は台帳テストの `bash -n` 検査 (S09) が止める。
readonly DENY_EXIT_CODE=2
```

`scripts/README.md` の該当セル:

```
| `bughunt-worktree-hook.sh` | PreToolUse(Bash) ガード。`bug-hunt-shard.sh provision` の **main 直叩き** (worktree 指紋なし) を harness 層で拒否する (拒否は終了コード **2** = harness の唯一の拒否信号。起動子は写像をしないのでそのまま届く)。判定は bash の組み込みだけで完結し、外部コマンドを 1 つも使わない | `.claude/settings.json` に常設配線 (AGENTS.md §常設 hook 配線) |
```

### 波及変更

- テストファイル: B26 系 (`'B28 main からの直叩き' => [..., 97]` 等) / B29 / B34 / B36 の期待値を
  `CLAUDE_HOOKS_DENY_EXIT_CODE` へ。B26 系の分岐 `if ($expected === 97)` も定数へ。
- 隣接 feature との整合: 本変更は正典が `bug-hunt-exec-infra` の領分と明記した部分に触れる。
  理由は「配線側の i7 追従に伴う従属変更」であり、bug-hunt 側の実行契約
  (判定が外部コマンドに依存しない / 標準出力は常に空 / 解釈できない入力は拒否側へ倒す) は変えない。
  実装完了時の lctl 報告に**この 1 点を従属変更として明記する** (セル判定の食い違いを避ける)。

### テスト計画

- [x] **先に赤くする**: 期待値を 2 に書き換えると、現行スクリプト (97 を返す) で B26 系が落ちる
- [x] B29 (敵対的な検索パスでも拒否が 2 で返る) / B34・B36 (解釈できない入力の拒否) が緑
- [x] 標準出力が空であることの既存検査は変えない

### リスク

- `scripts/bug-hunt-shard.sh` 側の自己検証 (`self-test`) はこの終了コードを参照していない
  (grep で確認済み)。したがって bug-hunt 本体の挙動は変わらない。

---

## S6: 塞がない脅威と、覆わない編集経路の始末を書く (i14/i15)

### 変更箇所

- `tests/Architecture/ClaudeHooksWiringTest.php` L9-23 (冒頭 docblock) と `CLAUDE_HOOKS_WIRING` の docblock
- `AGENTS.md` L388-419 (`CLAUDE_HOOKS_WIRING:BEGIN` … `:END` の区間)

### 変更後コード (検査ファイルの冒頭 docblock)

```php
/*
 * 常設 hook 配線の台帳 (deny-by-default) と、hook スクリプトの実挙動ゲート。
 *
 * 本テストは 2 層で構成する:
 *  - 静的層 (S01〜S13c): `.claude/settings.json` が下の台帳と**完全一致**することを見る。
 *    台帳に無い hook・イベント・トップレベルキーはすべて違反 = 配線の正本が 1 か所になる。
 *    ローカル層 (`.claude/settings.local.json`) のトップレベルも全数申告制で、申告に `hooks` は
 *    足せない。内側の上限と配線の時間切れは**数値を両方から取って比較**する。
 *  - 実起動層 (B01〜B46): hook スクリプトと起動子を**別プロセスで本当に起動**して、
 *    終了コード・標準出力の空・告知の回数・排他・敵対的な検索パス・symlink の置き場での
 *    振る舞いを実証する。静的検査だけでは「書いてあるが効いていない」を検出できない。
 *
 * -------------------------------------------------------------------------
 * **配線層が塞がないもの** (家系の正典 t3 の i14。緑であることを実際より強く読ませないために書く):
 *  1. **起動子 `/bin/bash` 自体を差し替えられる攻撃者**。起動子を絶対パスで書くのは検索パス経由の
 *     すり替えを防ぐためで、`/bin/bash` そのものを置き換えられる相手には何も効かない
 *  2. **`$CLAUDE_PROJECT_DIR` を含む環境変数を仕込める攻撃者**。起動先のパスはこの変数から
 *     組まれる。t3 の起動子は値を検証しない (B45 がその挙動を実挙動で見えるようにしている)。
 *     `-p` が塞ぐのは継承したシェル関数と `BASH_ENV` / `ENV` **だけ**である
 *  3. **リポジトリの外に置かれた設定層**。hook の設定は利用者層・管理者層にも置け、管理者は
 *     プロジェクト層の hook をまとめて無効化できる。リポジトリ内の検査からは原理的に見えない
 *
 * **索引更新の配線が覆わない編集経路** (i15):
 *  `matcher` は `Write|Edit` なので、**シェル経由の変更 (Bash ツール) は索引更新を起こさない**。
 *  それでも索引が置いていかれないのは、索引更新が**差分方式** (直前の索引時点からの差分を見る)
 *  であり、シェルで変えたファイルも**次の `Write` / `Edit` の起動でまとめて回収される**からである。
 *  回収されるのは「いつか」ではなく「次の編集時」であり、その間だけ索引が古いことは受容する。
 *  **撤回規則**: (a) シェルで変えたファイルが次の `Write` / `Edit` の起動でも索引へ入っていない
 *  実測が 1 件でも出た、(b) 索引ツールが差分方式でなくなった — このどちらかが起きたら、
 *  **`matcher` へ `Bash` を足すのではなく**、家系の未決論点へ差し戻す
 *  (`Bash` の hook 入力には編集対象のパスが無く対象外拡張子での早期打ち切りが原理的に効かないため、
 *  最頻ツールの呼び出しごとに索引更新の実プロセスが起きる = 正典が費用構造で外している)。
 *  差し戻す先は「セッション開始時に索引状態を出す任意の配線」と「配線の非同期実行」の 2 案である。
 * -------------------------------------------------------------------------
 *
 * 本テストは DB を触らない (ファイル読み取りと別プロセス起動のみ)。
 * 関数名を `claudeHooks` 接頭辞で始めるのは、Pest が全テストを 1 プロセスへ読み込むため
 * 素の名前が他の Architecture テストと衝突するからである。
 */
```

### 変更後コード (`AGENTS.md` のマーカー区間)

```markdown
<!-- CLAUDE_HOOKS_WIRING:BEGIN -->
## 常設 hook 配線

`.claude/settings.json` は git 追跡下の**配線の正本**である。配線されている hook は 2 本:

| イベント | 対象 | スクリプト | 役割 |
|---|---|---|---|
| PreToolUse | Bash | `scripts/bughunt-worktree-hook.sh` | bug-hunt provision の main 直叩きを止める |
| PostToolUse | Write / Edit | `scripts/code-review-graph-update-hook.sh` | コード索引の差分更新 |

- 対象は **`Write` と `Edit` の 2 つだけ**である。matcher が英数字・下線・`|` だけで
  出来ているときは正規表現にされず、`|` で分割して**完全一致**で比べられるためで、
  `NotebookEdit` のような派生ツールには一致しない。これは **Claude Code 2.1.233 で
  本体を実読して確かめた挙動**であり(記録は
  `devnotes/20260815-2015-todo-T172/matcher-semantics-evidence.md`)、
  **Claude Code を更新したら人手で再確認する**。
  台帳テスト(`ClaudeHooksWiringTest`)が固定するのは**設定に書かれた matcher 文字列だけ**で、
  本体側の判定機序が変わったことは**検出しない**(文字列が同じまま意味だけ変われば緑のままである)。
  `^(…)$` のようなアンカーは足さない(文字集合から外れて正規表現の経路へ移るだけで、
  意味論の変化を防げるわけではない)。
- **起動子はスクリプトを起こすだけ**である
  (`/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/<name>"`)。`-p`(特権モード)は継承した
  シェル関数と `BASH_ENV` / `ENV` を無効化するために付ける(スクリプトの 1 行目より前に効く層で、
  スクリプト内のどの防御でも代替できない)。**終了コードの写像・起動前の条件分岐・
  インラインのシェル片は置かない** — hook の終了コードは harness の唯一の制御信号
  (`PreToolUse` の **2 はブロック**、それ以外の非 0 はブロックしない異常として面に出る)で、
  畳むと配線ミスと実行時の異常を harness も人も区別できなくなる(家系の正典 t3)。
  bug-hunt ガードの拒否も **2** である。
  **帰結として、hook スクリプトの構文エラー(bash が返す 2)は Bash 操作をブロックする**。
  これは意図した交換であり、着地前に台帳テストの `bash -n` 検査が構文エラーを止める。
- **内側の上限は配線の時間切れより小さい**(検問 10 秒 / 索引更新 30 秒)。台帳テストは
  3 値(標準入力待ち / 更新本体の上限 / KILL までの猶予)を**スクリプト本文と設定の両方から
  数値で取り出して比較**する(文字列一致では数値の関係が崩れたことを検出できない)。
- 前提コマンド: `flock` / `timeout`(どちらも欠けると索引更新は走らず、セッションごとに
  1 行だけ告知する)。
- `.claude/settings.local.json` は**トップレベル項目を 1 つも持てない**(全数申告が空)。
  常設配線をローカル層から無効化する経路を作らないためで、項目を置きたくなったら
  台帳テストの申告を同じ変更で更新する(`hooks` は申告に足せない)。
- **配線層が塞がない範囲**(起動子自体の差し替え / 環境変数を仕込める攻撃者 /
  リポジトリ外の設定層)と、**索引更新が覆わない編集経路の始末**(シェル経由の変更が次の
  `Write` / `Edit` で回収される根拠と撤回規則)の**正本は
  `tests/Architecture/ClaudeHooksWiringTest.php` の冒頭**にある。本書には写さない
  (2 か所に書くと必ず食い違う)。
- **`code-review-graph install` / `init` / `uninstall` を実行しないこと**。これらは MCP 設定・
  hook 配線・本ファイルへの指示注入まで行い、**配線の正本が二重化する**。配線を変えるときは
  `.claude/settings.json` と `tests/Architecture/ClaudeHooksWiringTest.php` の台帳を同じ
  変更で直す。
- 配線を変えたら**新しいセッションを開始するまで反映されない**(設定はセッション開始時に
  1 度だけ読まれる)。
<!-- CLAUDE_HOOKS_WIRING:END -->
```

### 波及変更

- S12a が区間内に要求する語 (`code-review-graph install` / `uninstall` / `.claude/settings.json` /
  2 本のスクリプト名) は**すべて残す**。区間ごと消さない。
- `AGENTS.md` は `docs/template-fingerprints.json` の母集合に**無い** (実測) ので、
  乖離台帳への影響は無い。

### テスト計画

- [x] S12a が緑であること (マーカーと必要な語の実在)
- [x] docblock の記述そのものは機械検査を置かない (概念設計の「スコープ外」に明記)

### リスク

- 文書が実装より強い保証を主張しないことは人手レビューで見る。i14 の 3 点を
  検査ファイル側に置くのは、家系の先行実装 (テンプレート / spirux) と同じ形である。

---

## S7: 乖離台帳の移送 (D18 縮小 + D50 新設 + 採用時債務の削除)

### 変更箇所

- `docs/template-divergence.md` L11 (件数の明示行) / L1014-1072 (D18) / 末尾 (D50 を追加)
- `tests/Support/TemplateDivergence/LedgerPins.php` L22 / L34
- `tests/Support/TemplateDivergence/adoption-debt.tsv` L80 (1 行削除)

### 実測した突合の前提 (2026-08-24)

| パス | 正典側の指紋 | 現在のアプリ側 | 現況の説明 |
|---|---|---|---|
| `.claude/settings.json` | `d967a4be…` | `4e926a49…` | D18 に登録済み |
| `scripts/bughunt-worktree-hook.sh` | `55f47206…` | `381279b6…` | D18 に登録済み |
| `scripts/code-review-graph-update-hook.sh` | `0a10e0b2…` | `81cabed8…` | D18 に登録済み |
| `tests/Architecture/ClaudeHooksWiringTest.php` | `1e2d1eab…` | `04c6385e…` | **採用時債務** (`adoption-debt.tsv`) |
| `scripts/README.md` | `207e4dc9…` | `bb07fb39…` | D20 に登録済み (追加の drift は検出対象外) |

テンプレート側の起動子は `/bin/bash "$CLAUDE_PROJECT_DIR/scripts/…"` (`-p` 無し。lctl `get_source`
で `laravel-claude-template@5dd85a6` を実読) なので、**追従後も `-p` の差は残る** = D18 は消せない。
債務パスは内容を変えると `mutatedDebtPaths` で落ちるため、**逸脱の登録へ移す**。

### 変更後コード (D18 の書き換え)

```markdown
## D18 hook の起動子に特権モードを足し、2 本の hook スクリプトを外部コマンド非依存で持つ

| 行 | 内容 |
|---|---|
| 対象パス | `.claude/settings.json` / `scripts/bughunt-worktree-hook.sh` / `scripts/code-review-graph-update-hook.sh` |
| 業務要件起因の説明 | 継承したシェル関数と `BASH_ENV` はスクリプトの 1 行目より前に効くので起動子でしか塞げず、検問の判定が外部コマンドに依存すると検索パスが壊れた環境で拒否対象が黙って通る |
| 揃え続ける不変条件と保証機構 | 配線は常設 2 本で起動子は絶対パスの直呼び、終了コードは畳まず素通し、拒否は 2、内側の上限は配線の時間切れより小さい。`ClaudeHooksWiringTest` が固定する |
| 再判定の条件 | テンプレートが特権モードを取り込んだとき / Claude Code が hook の終了コードの扱いを変えたとき / 家系が起動子の形を再確定したとき |
| 決めた日 | 2026-08-15 |
| 決めた人 | 開発者 |
| 根拠 | T172 |
| 状態 | 恒久 |
| 見直し期限 | — |

常設 hook 配線 (家系の feature `claude-hooks-wiring` / 正典の版 t3) のうち、
**起動子に足した旗 1 つ**と**2 本のスクリプトの実装**がテンプレートと違う。
配線されている hook の本数・対象・置き場所・時間切れは正典どおりである。

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 起動子 | `/bin/bash "$CLAUDE_PROJECT_DIR/scripts/…"` | 同じ形に `-p` (特権モード) を足す |
| 検問の判定 | `grep` / `printf` などの外部コマンドに依存する | bash の組み込みだけで完結し、外部コマンドを 1 つも起こさない |
| 索引更新の実装 | 別実装 | 標準入力を 1 回だけ読み (最大 1 MiB / 5 秒)、告知は目印ファイルの排他的作成で 1 回に抑える |

### なぜ正当な差分か (logic-driven)

1. **`-p` は家系の正典 t3 (i6) が要求する形**で、テンプレートは未追従である。継承したシェル関数と
   `BASH_ENV` / `ENV` はスクリプトの 1 行目より前に効くため、スクリプト内のどの防御でも塞げない。
   塞げる唯一の層が起動子であり、交換関係の無い上位互換である。
2. **検問の判定を外部コマンドに依存させない**。以前は `cat` / `python3` / `grep` に依存しており、
   検索パスからそれらを解決できない環境では 127 で終わって**拒否対象が黙って通っていた**
   (無音の素通り)。判定を組み込みだけで書くと検索パスが壊れても挙動が変わらない。
3. **終了コードは畳まない**。旧実装は起動子で「97 だけを 2 へ写し、それ以外を 0 に畳む」形だったが、
   正典 t3 (i7) はこれを採らない — harness の唯一の制御信号を潰すと配線ミスと実行時の異常を
   区別できなくなるためである。畳まない帰結として構文エラー (bash の 2) がブロックになり得るが、
   `ClaudeHooksWiringTest` の `bash -n` 検査が着地前に止める。

### 揃えている不変条件 (これは保証し続ける)

> 「配線は常設で、起動子は絶対パスの直呼びで、終了コードは素通しで、排他はスクリプト内にあり、
> 配線は台帳テストで完全一致 pin される」

1. `.claude/settings.json` は git 追跡下の配線の正本で、見本ファイル方式は復活させない (S02 / S08)
2. 起動子は `/bin/bash -p "$CLAUDE_PROJECT_DIR/…"` の 3 トークンで、起動以外の仕事を持たない
   (S06b / S06c / S06d)
3. 内側の終了コードがそのまま返る (B41 / B42)、拒否は 2 である (B26 系)
4. 索引更新の排他は hook スクリプト内の `flock` が持つ (B16 / B17)
5. hook 種別 / matcher / 起動コマンド文字列 / timeout / トップレベルキーを完全一致で pin する
   (S03〜S06)。内側の上限との数値の関係も pin する (S13)

### 関連

- 実装: `.claude/settings.json` / `scripts/bughunt-worktree-hook.sh` /
  `scripts/code-review-graph-update-hook.sh`
- gate: `tests/Architecture/ClaudeHooksWiringTest.php`
- 設計: `devnotes/20260815-1539-claude-hooks-settings-wiring/` (常設化) /
  `devnotes/20260824-1014-claude-hooks-wiring-t3/` (正典 t3 追従)
- 規約の正本: `AGENTS.md` §常設 hook 配線
```

### 変更後コード (D50 の新設。ファイル末尾へ追加)

```markdown
---

## D50 hook 配線の台帳検査を、正典 t3 の要求を満たす独自構成で持つ

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/ClaudeHooksWiringTest.php` |
| 業務要件起因の説明 | 本アプリの検問と索引更新は「外部コマンドに依存しない判定」「目印ファイルの排他的作成による告知抑止」という独自の実行契約を持ち、その契約と家系の正典 t3 が要求する検査 (内側と外側の数値比較 / 設定 2 層の全数申告 / 配線文字列そのものの実起動 / 非保証の明記) を同じ 1 ファイルで固定する必要がある |
| 揃え続ける不変条件と保証機構 | 検査のファイル名と 2 層構成 (静的な層 + 実起動の層) を維持し、配線 (できごと / 照合条件 / 起動コマンド文字列 / 時間切れ) を既定拒否の台帳と完全一致で固定する。件数は `Tests\Support\TemplateDivergence\LedgerPins` が pin する |
| 再判定の条件 | テンプレートが正典 t3 追従の検査を取り込んだとき / 家系が正典の次の版を確定したとき |
| 決めた日 | 2026-08-24 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260824-1014-claude-hooks-wiring-t3/ |
| 状態 | 恒久 |
| 見直し期限 | — |

採用時点ではこの食い違いに説明が無く、採用時債務 (D34 の一覧) として凍結されていた。
正典 t3 追従で検査の中身を書き換えるため、**債務ではなく意図的逸脱として説明を書く**
(債務パスは内容を変えると突合 gate の債務規則で落ちる = 「変更したまま債務に残す」は選べない)。

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 実起動層が起こすもの | hook スクリプトを直接起動する | **設定から取り出した起動コマンド文字列そのもの**を起動する (正典 t3 の i11) |
| 内側の上限と配線の時間切れ | 数値の比較を持たない | 3 値をスクリプト本文と設定の両方から取り出して比較する (i8) |
| ローカル層の設定 | `hooks` の不在と、hook を止める設定項目 3 つの名指し | トップレベルの**全数申告** (申告は空。`hooks` は申告に足せない) (i10) |
| 非保証の明記 | 冒頭に一部 | 塞がない脅威 3 点と、覆わない編集経路の回収根拠・撤回規則を冒頭に書く (i14 / i15) |
| 走査域の空振り検査 | 持たない | glob ごとに「非空が契約か」を申告し、走査根を差し替えた負のコントロールを持つ (S12c) |

### なぜ正当な差分か (logic-driven)

1. **検査は自リポジトリの実行契約を検査する**。本アプリの 2 本のスクリプトは
   D18 のとおりテンプレートと別実装であり、その契約 (外部コマンド非依存 / 告知の 1 回性 /
   symlink の置き場での無書き込み) を実証する実起動層はテンプレートの検査では代替できない。
2. **正典 t3 が要求する 4 点をテンプレートがまだ持たない**。テンプレートのセルも
   `update_pending (pre-t3)` であり、追従の順序が逆になっている (本アプリが先行する)。
3. **1 ファイルに集めるのは正典の要求 (i13)** である。ファイル名も正典が固定しており、
   本アプリはそれに従っている (逸脱は中身だけである)。

### 揃えている不変条件 (これは保証し続ける)

> 「配線の検査は 1 ファイル (`tests/Architecture/ClaudeHooksWiringTest.php`) に集め、
> 静的な層と実起動の層の 2 層で、台帳に無い配線をすべて違反とする既定拒否を保つ」

- 台帳と設定の完全一致 (S03〜S06) と、台帳に無いイベント・キーの既定拒否
- 実起動層が別プロセスで hook と起動子を本当に起こすこと (B01〜B46)
- 新設・変更した走査には負例と正例を置き、母集団の非空を検査すること
  (S06c / S06d / S07b / S07c / S12c / S13b / S13c)

### 関連

- 実装: `tests/Architecture/ClaudeHooksWiringTest.php`
- 設計: `devnotes/20260824-1014-claude-hooks-wiring-t3/`
- 関連する登録: D18 (起動子と 2 本のスクリプト) / D34 (採用時債務の一覧)
```

### 件数 pin と債務一覧

```php
// tests/Support/TemplateDivergence/LedgerPins.php
public const int DIVERGENCE_ENTRY_COUNT = 47;   // 46 → 47 (D50 を新設)
public const int ADOPTION_DEBT_COUNT = 147;     // 148 → 147 (配線台帳検査を登録へ移した)
```

- `docs/template-divergence.md` L11 の `登録エントリ: 46 件` → `登録エントリ: 47 件`
  (宣言行 / 見出しの実数 / `LedgerPins` の**3 点一致**が形式検査の要求)
- `tests/Support/TemplateDivergence/adoption-debt.tsv` から
  `tests/Architecture/ClaudeHooksWiringTest.php\t04c6385e…` の 1 行を削る
  (ヘッダ行の `template_ledger_commit` は動かさない)

### テスト計画

- [x] `TemplateDivergenceLedgerFormatTest` (TD1〜TD12): 件数の 3 点一致 / 登録メタ表 9 行 /
      状態の値域 / 対象パスの実在と重複なし / 根拠 (`devnotes/20260824-1014-claude-hooks-wiring-t3/`) の実在
- [x] `TemplateDivergenceFingerprintTest`:
      F9 (3a = 未登録の食い違いゼロ / 3b = 一致へ戻ったのに残る登録ゼロ) /
      F10 (`mutatedDebtPaths` ゼロ = 債務パスを触っていない、`doubleDeclaredPaths` ゼロ = 債務と登録の
      二重宣言なし) / F11 (債務件数 = pin) / F12 (引退の掃除。pin > 0 なので一覧は残る)
- [x] **赤の確認**: S1〜S3 を入れて検査ファイルを書き換えた時点で F10 が
      `mutatedDebtPaths` で落ちる。S7 を当てて緑にする (**この順序が債務移送の必然性の証明**である)

### リスク

- 3 ファイルのうち 1 つだけを直すと別種の違反で落ちる (債務を消して登録を書かなければ 3a、
  登録を書いて債務を消さなければ `doubleDeclaredPaths`、件数 pin を忘れれば F11)。
  **同じコミットで 3 つとも直す**。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 変更が 1 つの不変条件セット (正典 t3) の追従で閉じており、検査 → 設定 → スクリプト → 文書 → 台帳が**同じコミットで揃わないと必ず赤**になる (写像器を残したまま検査だけ反転すると S06b/B41 が落ち、債務移送を分けると F10 が落ちる)。段階的に分割しても中間状態が緑にならないので incremental の利点が無い |
| 競合リスク | 低。触るファイルは hook 配線の 4 本 + 文書 2 本 + 乖離台帳 3 本で、アプリコードと 1 行も重ならない。ただし**乖離台帳の 3 ファイル (`docs/template-divergence.md` / `LedgerPins.php` / `adoption-debt.tsv`) は他の TODO と衝突しやすい** — D 番号と件数 pin は main の最新を取り込んでから確定する (先例: T252 が採番衝突で D49 へ繰り上げた) |

## 検証コマンド (全 green でコミット)

- `composer test` (`ClaudeHooksWiringTest` / `TemplateDivergenceLedgerFormatTest` /
  `TemplateDivergenceFingerprintTest` / `StrictTypesDeclarationGateTest` /
  `ForbiddenStatementTokenInvariantTest` を含む)
- `composer phpstan` (level 10)
- `vendor/bin/pint --test`
- `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
  (frontend は変更しないが、規約どおり全数を回す)

## 実装後の申し送り (lctl への報告に含める)

1. aicue セルの `status` / `version` を `implemented` / `t3` へ (満たした条文と、採らなかった
   任意配線 i4 を明記)。
2. `scripts/bughunt-worktree-hook.sh` の拒否コード変更は**隣接 feature
   (`bug-hunt-exec-infra`) の領分への従属変更**である旨を明記する。
3. 反映はセッションを開き直してから (設定はセッション開始時に 1 度だけ読まれる)。
   次セッションで「bug-hunt provision の main 直叩きが実際にブロックされる」ことを人手で確認する。

## 関連する現行コード

### .claude/settings.json (全文)

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash",
        "hooks": [
          {
            "type": "command",
            "command": "/bin/bash -p -c 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/scripts/bughunt-worktree-hook.sh; s=0; if [ -n \"$d\" ] && [ \"${d#/}\" != \"$d\" ] && [ \"${d//../}\" = \"$d\" ] && [ -d \"$d/scripts\" ] && [ ! -L \"$d/scripts\" ] && [ -f \"$f\" ] && [ ! -L \"$f\" ]; then /bin/bash -p \"$f\"; s=$?; fi; if [ \"$s\" = 97 ]; then exit 2; fi; exit 0'",
            "timeout": 10
          }
        ]
      }
    ],
    "PostToolUse": [
      {
        "matcher": "Write|Edit",
        "hooks": [
          {
            "type": "command",
            "command": "/bin/bash -p -c 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/scripts/code-review-graph-update-hook.sh; if [ -n \"$d\" ] && [ \"${d#/}\" != \"$d\" ] && [ \"${d//../}\" = \"$d\" ] && [ -d \"$d/scripts\" ] && [ ! -L \"$d/scripts\" ] && [ -f \"$f\" ] && [ ! -L \"$f\" ]; then /bin/bash -p \"$f\"; fi; exit 0'",
            "timeout": 30
          }
        ]
      }
    ]
  }
}
```

### tests/Architecture/ClaudeHooksWiringTest.php (静的層と起動子の実起動層の抜粋)

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Webmozart\Assert\Assert;

/*
 * 常設 hook 配線の台帳 (deny-by-default) と、hook スクリプトの実挙動ゲート。
 *
 * 本テストは 2 層で構成する:
 *  - 静的層 (S01〜S12c): `.claude/settings.json` が下の台帳と**完全一致**することを見る。
 *    台帳に無い hook・イベント・トップレベルキーはすべて違反 = 配線の正本が 1 か所になる。
 *    末尾の S12c は S12b の走査域が空振りしていないことの検査である。
 *  - 実起動層 (B01〜B51): hook スクリプトと起動子を**別プロセスで本当に起動**して、
 *    終了コード・標準出力の空・告知の回数・排他・敵対的な検索パス・symlink の置き場での
 *    振る舞いを実証する。静的検査だけでは「書いてあるが効いていない」を検出できない。
 *
 * 本テストは DB を触らない (ファイル読み取りと別プロセス起動のみ)。
 * 関数名を `claudeHooks` 接頭辞で始めるのは、Pest が全テストを 1 プロセスへ読み込むため
 * 素の名前が他の Architecture テストと衝突するからである。
 */

/** 設定ファイルのトップレベルに置いてよいキー (全数申告制)。 */
const CLAUDE_HOOKS_TOP_LEVEL_KEYS = ['hooks'];

/**
 * 配線台帳。ここに書かれた形と `.claude/settings.json` が完全一致しなければ落ちる。
 *
 * `matcher` の `Write|Edit` は **`Write` と `Edit` のときだけ**発火する。
 * 部分一致で将来の派生ツールを自動で拾うとは書かない (書くと嘘になる)。
 *
 * @var array<string, list<array{matcher: string, script: string, timeout: int, deny_exit_code: int|null}>>
 */
const CLAUDE_HOOKS_WIRING = [
    'PreToolUse' => [
        [
            'matcher' => 'Bash',
            'script' => 'scripts/bughunt-worktree-hook.sh',
            'timeout' => 10,
            'deny_exit_code' => 97,
        ],
    ],
    'PostToolUse' => [
        [
            'matcher' => 'Write|Edit',
            'script' => 'scripts/code-review-graph-update-hook.sh',
            'timeout' => 30,
            'deny_exit_code' => null,
        ],
    ],
];

/**
 * 索引の対象外拡張子。`scripts/code-review-graph-update-hook.sh` の `SKIP_EXTENSIONS` と
 * 完全一致すること (索引ツールを更新したらここも棚卸しする)。
 *
 * @var list<string>
 */
const CLAUDE_HOOKS_SKIP_EXTENSIONS = ['md', 'txt', 'json', 'yaml', 'yml', 'lock', 'log'];

/** 検索パス安全化ブロックの開始・終了マーカー (2 本の hook で byte 一致する)。 */
const CLAUDE_HOOKS_PROLOGUE_BEGIN = '# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---';
const CLAUDE_HOOKS_PROLOGUE_END = '# ---8< /SHARED_PATH_PROLOGUE >8---';

/**
 * S12b の走査対象 (実行面のファイルのみ)。文書は走査しない —
 * 禁止を説明する文章にコマンド名が出るのは正常であり、走査すると必ず落ちるためである。
 *
// =============================================================================
// ヘルパ (静的層)
// =============================================================================

/** ファイルを読む (読めなければ明示 fail し string へ narrow する)。 */
function claudeHooksReadFile(string $path): string
{
    Assert::fileExists($path);
    $contents = file_get_contents($path);
    Assert::string($contents, "読み込めません: {$path}");

    return $contents;
}

/**
 * `.claude/settings.json` を配列として読む。
 *
 * @return array<string, mixed>
 */
function claudeHooksSettings(): array
{
    $decoded = json_decode(claudeHooksReadFile(base_path('.claude/settings.json')), true);
    Assert::isArray($decoded, '.claude/settings.json が JSON オブジェクトではない');

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * 起動子の文字列を台帳側で組み立てる (設定を書き換えたら必ずここと食い違って落ちる)。
 *
 * 起動子が持つ 3 つの役割:
 *  1. 起動先の検証 (絶対パス / `..` を含まない / `scripts` が symlink でない実ディレクトリ /
 *     起動先が symlink でない通常ファイル)。1 つでも欠ければ内側を起動しない
 *  2. 終了コードの写像 (PreToolUse は 97 だけを 2 へ写す。それ以外はすべて 0)
 *  3. 環境からのシェル関数の遮断 (`-p` = privileged mode)
 */
function claudeHooksExpectedCommand(string $script, ?int $denyExitCode): string
{
    $conditions = '[ -n "$d" ] && [ "${d#/}" != "$d" ] && [ "${d//../}" = "$d" ]'
        .' && [ -d "$d/scripts" ] && [ ! -L "$d/scripts" ] && [ -f "$f" ] && [ ! -L "$f" ]';

    $inner = 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/'.$script.'; ';
    $inner .= $denyExitCode === null
        ? 'if '.$conditions.'; then /bin/bash -p "$f"; fi; exit 0'
        : 's=0; if '.$conditions.'; then /bin/bash -p "$f"; s=$?; fi; '
            .'if [ "$s" = '.$denyExitCode.' ]; then exit 2; fi; exit 0';

    return "/bin/bash -p -c '".$inner."'";
}

/**
 * 検索パス安全化ブロックを取り出す。マーカーが 1 組でなければ違反として文字列を返す。
 *
 * shell parser は作らない。見るのは (1) マーカーが 1 組 (2) ブロックの byte 列
 * (3) 開始マーカーより前が shebang・コメント・空行だけ、の 3 点だけである。
 *
 * @return array{block: string, violations: list<string>}
 */
function claudeHooksPrologueBlock(string $contents, string $label): array
{
    $violations = [];

    $beginCount = substr_count($contents, CLAUDE_HOOKS_PROLOGUE_BEGIN);
    $endCount = substr_count($contents, CLAUDE_HOOKS_PROLOGUE_END);
    if ($beginCount !== 1 || $endCount !== 1) {
        return [
            'block' => '',
            'violations' => ["{$label}: 検索パス安全化ブロックのマーカーが 1 組でない (begin={$beginCount} end={$endCount})"],
        ];
    }

    $begin = strpos($contents, CLAUDE_HOOKS_PROLOGUE_BEGIN);
    $end = strpos($contents, CLAUDE_HOOKS_PROLOGUE_END);
    Assert::integer($begin);
    Assert::integer($end);
    if ($end < $begin) {
        return ['block' => '', 'violations' => ["{$label}: 終了マーカーが開始マーカーより前にある"]];
    }

    $block = substr($contents, $begin, $end - $begin + strlen(CLAUDE_HOOKS_PROLOGUE_END));

    // 開始マーカーより前は shebang・コメント・空行だけであること
    // (= 最初の外部コマンド呼び出しより前にプロローグがある、が自動的に成立する)
    foreach (preg_split('/\r\n|\r|\n/', substr($contents, 0, $begin)) ?: [] as $index => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $violations[] = "{$label}: 検索パス安全化ブロックより前に実行される行がある (".($index + 1)." 行目: {$trimmed})";
    }

    return ['block' => $block, 'violations' => $violations];
}

// =============================================================================
// ヘルパ (実起動層)
// =============================================================================

/** 実起動層で必要な外部コマンドの絶対パスを解決する。 */
function claudeHooksResolveExecutable(string $name): string
{
    foreach (['/usr/local/bin/', '/usr/bin/', '/bin/'] as $dir) {
        if (is_executable($dir.$name)) {
            return $dir.$name;
        }
/** 台帳から起動子の実文字列を取り出す (台帳の写しではなく本物を走らせるため)。 */
function claudeHooksLauncherCommand(string $event): string
{
    $settings = claudeHooksSettings();
    Assert::isArray($settings['hooks']);
    Assert::keyExists($settings['hooks'], $event);
    $group = $settings['hooks'][$event];
    Assert::isArray($group);
    Assert::isArray($group[0]);
    Assert::isArray($group[0]['hooks']);
    Assert::isArray($group[0]['hooks'][0]);
    $command = $group[0]['hooks'][0]['command'];
    Assert::string($command);

    return $command;
}

/**
 * 起動子そのものを走らせる。`CLAUDE_PROJECT_DIR` を渡さないときは環境から消える。
 *
 * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
 */
function claudeHooksRunLauncher(string $command, ?string $projectDir, ?string $cwd = null): array
{
    $env = ['/usr/bin/env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin'];
    if ($projectDir !== null) {
        $env[] = 'CLAUDE_PROJECT_DIR='.$projectDir;
    }

    return claudeHooksRun([...$env, '/bin/bash', '-c', $command], '', $cwd);
}

/** 起動子の内側に置く「終了コードだけを返す」スクリプト。 */
function claudeHooksWriteExitStub(string $path, int $exitCode): void
{
    File::put($path, "#!/bin/bash\nexit {$exitCode}\n");
    chmod($path, 0700);
}

// =============================================================================
// 静的層
// =============================================================================

test('S01: .claude/settings.json が実在し有効な JSON であること', function (): void {
    expect(claudeHooksSettings())->toBeArray();
});
test('S05/S06: 各 hook の matcher / 起動文字列 / timeout が台帳と完全一致すること', function (): void {
    $settings = claudeHooksSettings();
    Assert::isArray($settings['hooks']);

    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
        $group = $settings['hooks'][$event];
        Assert::isArray($group);
        expect($group)->toHaveCount(count($entries), "{$event} の登録数が台帳と違う");

        foreach ($entries as $index => $entry) {
            $matcherGroup = $group[$index];
            Assert::isArray($matcherGroup);
            expect(array_keys($matcherGroup))->toBe(['matcher', 'hooks']);
            expect($matcherGroup['matcher'])->toBe($entry['matcher']);

            Assert::isArray($matcherGroup['hooks']);
            expect($matcherGroup['hooks'])->toHaveCount(1);
            $hook = $matcherGroup['hooks'][0];
            Assert::isArray($hook);
            expect(array_keys($hook))->toBe(['type', 'command', 'timeout']);
            expect($hook['type'])->toBe('command');
            expect($hook['timeout'])->toBe($entry['timeout']);
            expect($hook['command'])->toBe(
                claudeHooksExpectedCommand($entry['script'], $entry['deny_exit_code']),
                "{$event} の起動文字列が台帳と 1 文字でも違う",
            );
        }
    }
});

test('S06b: 起動子が privileged mode / 起動先検証 / 終了コード写像の 3 役をすべて持つこと', function (): void {
    // claudeHooksExpectedCommand() は台帳側の組み立てなので、そこが緩んでも S05 は緑のままになる。
    // 「何が書かれていなければならないか」を独立に固定する。
    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
        foreach ($entries as $entry) {
            $command = claudeHooksExpectedCommand($entry['script'], $entry['deny_exit_code']);

            expect($command)->toStartWith("/bin/bash -p -c '", "{$event}: 起動子が絶対パス + privileged mode でない");
            claudeHooksExpectContains($command, '/bin/bash -p "$f"', "{$event}: 内側の起動が privileged mode でない");

            foreach ([
                '[ -n "$d" ]',                    // 未設定を弾く
                '[ "${d#/}" != "$d" ]',           // 絶対パスであること
                '[ "${d//../}" = "$d" ]',         // `..` を含まないこと
                '[ -d "$d/scripts" ]',            // scripts が実ディレクトリ
                '[ ! -L "$d/scripts" ]',          // scripts が symlink でない
                '[ -f "$f" ]',                    // 起動先が通常ファイル
                '[ ! -L "$f" ]',                  // 起動先が symlink でない
            ] as $condition) {
                claudeHooksExpectContains($command, $condition, "{$event}: 起動先の検証が無い");
            }

            if ($entry['deny_exit_code'] === null) {
                claudeHooksExpectNotContains($command, 'exit 2', "{$event}: ブロックしない hook が 2 を返しうる");
            } else {
                claudeHooksExpectContains(
                    $command,
                    'if [ "$s" = '.$entry['deny_exit_code'].' ]; then exit 2; fi',
                    "{$event}: 拒否コードの写像が無い",
                );
            }
            expect($command)->toEndWith("exit 0'", "{$event}: 既定で 0 に畳んでいない");
        }
    }
});

test('S07: .claude/settings.local.json は hooks キーを持てないこと (常設配線をローカルから殺さない)', function (): void {
    $path = base_path('.claude/settings.local.json');
    if (! is_file($path)) {
        expect(true)->toBeTrue('ローカル設定は無い (常設配線を上書きする経路も無い)');

        return;
    }

    $decoded = json_decode(claudeHooksReadFile($path), true);
    Assert::isArray($decoded);
    expect(array_key_exists('hooks', $decoded))
        ->toBeFalse('.claude/settings.local.json に hooks を置かないこと (常設配線をローカルから殺す経路になる)');
});

test('S08: 見本ファイル方式が復活していないこと', function (): void {
    expect(is_file(base_path('.claude/settings.bughunt-hook.example.json')))
        ->toBeFalse('オプトインの見本ファイルは常設配線と並走させない (後方互換の並走を残さない)');
});

test('S09: 台帳の 2 スクリプトが実在し bash -n を通ること', function (): void {
    foreach (CLAUDE_HOOKS_WIRING as $entries) {
        foreach ($entries as $entry) {
            $path = base_path($entry['script']);
            expect(is_file($path))->toBeTrue("{$entry['script']} が無い");

            $result = Process::timeout(30)->run(['bash', '-n', $path]);
            expect($result->exitCode())->toBe(0, "{$entry['script']} が bash -n を通らない:\n".$result->errorOutput());
        }
    }
});

test('S10: 2 本の検索パス安全化ブロックが byte 一致し、どちらもファイル先頭にあること', function (): void {
    $blocks = [];
    $violations = [];

    foreach (CLAUDE_HOOKS_WIRING as $entries) {
    } finally {
        $holder?->stop();
        File::deleteDirectory($sandbox);
    }
});

test('B17: 並行起動しても更新は 1 回だけであること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        // 3 秒かかる更新にして、後続が確実にロック競合へ落ちるようにする
        claudeHooksInstallToolStub($sandbox, "exec '".claudeHooksResolveExecutable('sleep')."' 3\n");

        $startedAt = microtime(true);
        $processes = [];
        for ($i = 0; $i < 5; $i++) {
            $processes[] = Process::timeout(60)
                ->input(claudeHooksWritePayload("app/File{$i}.php", "sess-{$i}"))
                ->start([
                    '/usr/bin/env', '-i', 'PATH='.claudeHooksPathWithTool($sandbox),
                    '/bin/bash', $sandbox.'/scripts/code-review-graph-update-hook.sh',
                ]);
        }
        foreach ($processes as $process) {
            expect($process->wait()->exitCode())->toBe(0);
        }
        $elapsed = microtime(true) - $startedAt;

        expect(claudeHooksInvocations($sandbox))->toBe(1, '排他が効いておらず更新が重複起動された');
        expect($elapsed)->toBeLessThan(30.0, '呼び出し側 timeout (30 秒) を超えた');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B18: 終わらない更新を内側の時間切れで打ち切り、その旨を 1 行告知すること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox, "exec '".claudeHooksResolveExecutable('sleep')."' 120\n");

        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect(claudeHooksWarningLines($result['errorOutput']))->toBe(1);
        expect($result['errorOutput'])->toContain('20 秒');
        expect($result['elapsed'])->toBeLessThan(45.0, '内側の時間切れ (20 秒) が効いていない');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B19: 更新が失敗したらその旨を 1 行告知して 0 で終えること', function (): void {
    $sandbox = claudeHooksSandbox();

// 実起動層: bug-hunt ガード (B26〜B40b)
// =============================================================================

test('B26/B28/B30〜B33/B40/B40b: provision の直叩きだけを拒否すること', function (string $command, int $expected): void {
    $sandbox = claudeHooksSandbox();

    try {
        $result = claudeHooksRunBughuntHook($sandbox, claudeHooksBashPayload($command));

        expect($result['exitCode'])->toBe($expected, "コマンド [{$command}] の判定が違う");
        expect($result['output'])->toBe('', '標準出力は常に空でなければならない');
        if ($expected === 97) {
            expect($result['errorOutput'])->toContain('bug-hunt provision');
        } else {
            expect($result['errorOutput'])->toBe('');
        }
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    'B26 無関係なコマンド' => ['ls -la', 0],
    'B28 main からの直叩き' => ['scripts/bug-hunt-shard.sh provision --shard 1', 97],
    'B30 worktree から' => ['cd .claude/worktrees/tasks/x && scripts/bug-hunt-shard.sh provision', 0],
    'B31 明示解除' => ['BUGHUNT_ALLOW_MAIN=1 scripts/bug-hunt-shard.sh provision', 0],
    'B32 self-test dryrun' => ['BUGHUNT_SELFTEST_DRYRUN=1 scripts/bug-hunt-shard.sh provision', 0],
    'B40 間に別語が入る言及' => ['scripts/bug-hunt-shard.sh scaffold x provision', 0],
    'B40b provision-all' => ['scripts/bug-hunt-shard.sh provision-all', 97],
]);

test('B37: JSON が / を \\/ へ逃がしていても worktree の指紋を取りこぼさないこと', function (): void {
    $sandbox = claudeHooksSandbox();
// =============================================================================
// 実起動層: 起動子 (B41〜B51)
// =============================================================================

test('B41〜B49: PreToolUse の起動子が 97 だけを 2 へ写し、それ以外を 0 に畳むこと', function (string $case, int $expected): void {
    $sandbox = claudeHooksSandbox();
    $command = claudeHooksLauncherCommand('PreToolUse');
    $script = $sandbox.'/scripts/bughunt-worktree-hook.sh';
    $projectDir = $sandbox;
    $cwd = null;

    try {
        match ($case) {
            'B41 拒否 (97)' => claudeHooksWriteExitStub($script, 97),
            'B42 通過 (0)' => claudeHooksWriteExitStub($script, 0),
            'B43 構文エラー (2)' => claudeHooksWriteExitStub($script, 2),
            'B44 起動先が無い' => File::delete($script),
            'B45 CLAUDE_PROJECT_DIR が無い' => (function () use ($script, &$projectDir): void {
                claudeHooksWriteExitStub($script, 97);
                $projectDir = null;
            })(),
            'B46 相対値' => (function () use ($script, $sandbox, &$projectDir, &$cwd): void {
                claudeHooksWriteExitStub($script, 97);
                $projectDir = basename($sandbox);
                $cwd = dirname($sandbox);
            })(),
            'B47 .. を含む' => (function () use ($script, $sandbox, &$projectDir): void {
                claudeHooksWriteExitStub($script, 97);
                $projectDir = dirname($sandbox).'/../'.basename(dirname($sandbox)).'/'.basename($sandbox);
            })(),
            'B48 scripts が symlink' => (function () use ($script, $sandbox): void {
                claudeHooksWriteExitStub($script, 97);
                rename($sandbox.'/scripts', $sandbox.'/real-scripts');
                symlink($sandbox.'/real-scripts', $sandbox.'/scripts');
            })(),
            'B49 起動先が symlink' => (function () use ($script, $sandbox): void {
                claudeHooksWriteExitStub($sandbox.'/scripts/real-hook.sh', 97);
                File::delete($script);
                symlink($sandbox.'/scripts/real-hook.sh', $script);
            })(),
        };

        $result = claudeHooksRunLauncher($command, $projectDir, $cwd);

        expect($result['exitCode'])->toBe($expected, "{$case}: 起動子の写像が違う");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    'B41 拒否 (97)' => ['B41 拒否 (97)', 2],
    'B42 通過 (0)' => ['B42 通過 (0)', 0],
    'B43 構文エラー (2)' => ['B43 構文エラー (2)', 0],
    'B44 起動先が無い' => ['B44 起動先が無い', 0],
    'B45 CLAUDE_PROJECT_DIR が無い' => ['B45 CLAUDE_PROJECT_DIR が無い', 0],
    'B46 相対値' => ['B46 相対値', 0],
    'B47 .. を含む' => ['B47 .. を含む', 0],
    'B48 scripts が symlink' => ['B48 scripts が symlink', 0],
    'B49 起動先が symlink' => ['B49 起動先が symlink', 0],
]);

test('B50: PostToolUse の起動子は内側の終了コードにかかわらず常に 0 を返すこと', function (int $inner): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksWriteExitStub($sandbox.'/scripts/code-review-graph-update-hook.sh', $inner);
        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PostToolUse'), $sandbox);

        expect($result['exitCode'])->toBe(0, "内側が {$inner} のとき起動子が 0 を返していない");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([[0], [1], [2], [97], [127]]);

test('B51: 起動子が環境からのシェル関数を内側へ継承させないこと (privileged mode)', function (): void {
    $sandbox = claudeHooksSandbox();
    $command = claudeHooksLauncherCommand('PreToolUse');

    try {
        // 内側で「注入した関数が見えるか」を自分で記録するスクリプト
        File::put($sandbox.'/scripts/bughunt-worktree-hook.sh', <<<BASH
        #!/bin/bash
        if [ "\$(type -t claude_hooks_probe)" = "function" ]; then
            touch '{$sandbox}/FUNC-LEAKED'
        fi
        exit 0
        BASH);
        chmod($sandbox.'/scripts/bughunt-worktree-hook.sh', 0700);

        $wrapper = "claude_hooks_probe() { :; }\nexport -f claude_hooks_probe\nexec ".$command;
        $result = claudeHooksRun([
            '/usr/bin/env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin', 'CLAUDE_PROJECT_DIR='.$sandbox,
            '/bin/bash', '-c', $wrapper,
        ]);

        expect($result['exitCode'])->toBe(0);
        expect(is_file($sandbox.'/FUNC-LEAKED'))
            ->toBeFalse('環境から注入したシェル関数が hook へ継承された (privileged mode が効いていない)');
    } finally {
        File::deleteDirectory($sandbox);
    }
});
```

### scripts/bughunt-worktree-hook.sh (抜粋: プロローグ以降)

```bash
    export PATH
}
_hook_sanitize_path
# ---8< /SHARED_PATH_PROLOGUE >8---

# 拒否は終了コード 97 で表す。.claude/settings.json の起動子が 97 だけを 2 へ写像するため、
# 構文エラー (2) や実行不能 (126/127) が Bash ツールをブロックすることは無い。
readonly DENY_EXIT_CODE=97

# 標準入力は 1 回だけ読む。最大 1 MiB / 最大 5 秒 (閉じない相手に待ち続けない)。
input=''
IFS= read -r -N 1048576 -t 5 input || true

# 段 0: 対象語が無ければ外部コマンドを 1 つも起こさずに通す (無関係なコマンドは構造的に無影響)
case "${input}" in
    *bug-hunt-shard.sh*) ;;
    *) exit 0 ;;
esac

# 段 1: tool_input.command を取り出す (JSON エスケープは我々が探すバイト列を増やす方向にしか働かない)
command_text=''
extracted=0
if [[ "${input}" =~ \"command\"[[:space:]]*:[[:space:]]*\"((\\.|[^\"\\])*)\" ]]; then
    command_text="${BASH_REMATCH[1]}"
    extracted=1
fi

# 段 2: 判定
#  - 抽出できた: 抽出値だけで判定する (許可シグナル 2 種とも有効)
#  - 抽出できない: 明示解除 BUGHUNT_ALLOW_MAIN= だけを生入力で見る
#    (痕跡 .claude/worktrees/ は偶然そこにあり得るので抽出失敗時は評価しない)
if [ "${extracted}" -eq 1 ]; then
    subject="${command_text}"
    allow_regex='(\.claude\\?/worktrees\\?/|BUGHUNT_ALLOW_MAIN=|BUGHUNT_SELFTEST_DRYRUN=)'
else
    subject="${input}"
    allow_regex='BUGHUNT_ALLOW_MAIN='
fi

# 実行の検出は「bug-hunt-shard.sh の直後の空白 + provision」に限る
# (コミットメッセージ等の文字列言及では誤発火しない)。JSON の \n \t \r 表記も空白として受ける。
[[ "${subject}" =~ bug-hunt-shard\.sh([[:space:]]|\\[nrt])+provision ]] || exit 0
[[ "${subject}" =~ ${allow_regex} ]] && exit 0

# 拒否メッセージも組み込みで出す (ヒアドキュメント + cat を使わない)。
# これで**このスクリプトは外部コマンドを 1 つも使わない**ことになり、
# 検索パスがどれだけ壊れていても挙動が変わらない。
printf '%s\n' \
    '⛔ bug-hunt provision を worktree 外から直叩きしようとしています (skill app-bug-hunt の Phase 0a スキップ)。' \
    'bug-hunt は worktree から走るのが既定です (main を直接汚さず todo/ ブランチに隔離するため)。次のいずれかで起動してください:' \
    '  1) /app-bug-hunt 経由 (推奨。Phase 0a が worktree を自動で切る)' \
    '  2) scripts/setup-worktree.sh bughunt-<task-id> で worktree を切り、その worktree 内' \
    '     (cd .claude/worktrees/tasks/bughunt-<task-id>) から本スクリプトを実行' \
    '  3) 意図的な main 走行 (--keep-db 連続再走など asset 既存の単発確認) のみ コマンド先頭に BUGHUNT_ALLOW_MAIN=1 を付ける' \
    >&2
exit "${DENY_EXIT_CODE}"
```

### scripts/code-review-graph-update-hook.sh (抜粋: 契約・上限・段 8)

```bash
#!/usr/bin/env bash
# PostToolUse(Write|Edit) — コード索引 (code-review-graph) の差分更新。
#
# 実行契約 (tests/Architecture/ClaudeHooksWiringTest.php が実挙動で固定する):
#  1. 何が起きても終了コード 0 で終わる (編集作業を止めない)
#  2. 標準出力は常に空
#  3. 告知は標準エラーに 1 行だけ。セッションごと・理由ごとに 1 回だけ
#  4. 更新は必ず flock で排他する。安全に排他できない環境では更新しない
#  5. 呼び出し側の時間切れ (30 秒) より内側 (20 秒) で自分から諦める
#  6. 作業ディレクトリと環境変数に依存しない (リポジトリルートは自分の位置から解決する)
#  7. 最初の外部コマンド呼び出しより前に検索パスを安全化する
#  8. 置き場・ロック・告知フラグが symlink なら何も書かずに終える
#  9. 索引の対象外の拡張子では更新を起動しない (副作用ゼロ)
#
# 索引ツール自身の install / uninstall は実行しないこと (配線の正本が二重化する。AGENTS.md)。


# 呼び出し側 (.claude/settings.json) の 30 秒より内側で自分から諦める
readonly INNER_TIMEOUT_SECONDS=20
# 索引の対象外の拡張子 (台帳テストが完全一致で固定する。索引ツール更新時は棚卸しすること)
readonly SKIP_EXTENSIONS='md txt json yaml yml lock log'

state_dir=''
session_id='unknown'

# 告知: 標準エラーに 1 行だけ。セッションごと・理由ごとに 1 回だけ。
# 目印ファイルの作成は noclobber (O_CREAT|O_EXCL) なので、
#  - 既にあれば作成に失敗する = 重複抑止そのもの (読み書きの競合が起きない)
#  - 目印が symlink でも作成に失敗する = 検査と作成が原子的 (TOCTOU が無い)
emit_warning() {
    local reason="$1" message="$2" flag
    flag="${state_dir}/warned-${reason}-${session_id}"
    ( set -C; : > "${flag}" ) 2> /dev/null || return 0
    printf 'code-review-graph: %s\n' "${message}" >&2
    return 0
}

# --- 段 1: 標準入力を 1 回だけ読む (最大 1 MiB / 最大 5 秒) -------------------
input=''
IFS= read -r -N 1048576 -t 5 input || true

# --- 段 2: 対象外拡張子なら副作用ゼロで終わる --------------------------------
file_path=''
if [[ "${input}" =~ \"file_path\"[[:space:]]*:[[:space:]]*\"([^\"]*)\" ]]; then
# --- 段 6: 排他 (非ブロッキング。取れなければ黙って終わる) --------------------
# ロックは flock で取る (プロセスが落ちても解放されるため。ディレクトリロックは
# 落ちたときに解放されず索引更新が恒久的に止まるので採らない)。
# 帰結として、ロックファイルの差し替え (TOCTOU) までは防がない = 保証範囲を下げてある。
lock_file="${state_dir}/update.lock"
[ -L "${lock_file}" ] && exit 0
if ! command -v flock > /dev/null 2>&1; then
    emit_warning 'no-flock' 'flock が無いため索引を更新しません (排他できない環境では更新しない契約です)'
    exit 0
fi
# ★ `exec 9> file 2>/dev/null` と書いてはいけない: コマンドを伴わない exec の
#   リダイレクトは**シェル全体へ永続適用**され、以降の告知 (契約 3) が消える。
#   波括弧のグループなら fd 9 だけが残り、標準エラーの差し替えはグループの外で戻る。
{ exec 9> "${lock_file}"; } 2> /dev/null || exit 0
flock -n 9 || exit 0

# --- 段 7: 前提コマンドの在否 ------------------------------------------------
if ! command -v code-review-graph > /dev/null 2>&1; then
    emit_warning 'tool-missing' \
        'コード索引ツールが未導入です (uv tool install code-review-graph==2.3.7 → code-review-graph build)'
    exit 0
fi
if ! command -v timeout > /dev/null 2>&1; then
    emit_warning 'no-timeout' 'timeout が無いため索引を更新しません (時間切れを保証できないためです)'
    exit 0
fi

# --- 段 8: 差分更新 ----------------------------------------------------------
timeout -k 5 "${INNER_TIMEOUT_SECONDS}" \
    code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
status=$?
case "${status}" in
    0) ;;
    124|137) emit_warning 'update-timeout' \
        "索引の差分更新が ${INNER_TIMEOUT_SECONDS} 秒で終わらなかったため中断しました" ;;
    *) emit_warning 'update-failed' \
        "索引の差分更新に失敗しました (終了コード ${status}。code-review-graph build を試してください)" ;;
esac

# --- 段 9: 常に成功で終わる --------------------------------------------------
exit 0
```

### AGENTS.md の現行マーカー区間

```markdown
<!-- CLAUDE_HOOKS_WIRING:BEGIN -->
## 常設 hook 配線

`.claude/settings.json` は git 追跡下の**配線の正本**である。配線されている hook は 2 本:

| イベント | 対象 | スクリプト | 役割 |
|---|---|---|---|
| PreToolUse | Bash | `scripts/bughunt-worktree-hook.sh` | bug-hunt provision の main 直叩きを止める |
| PostToolUse | Write / Edit | `scripts/code-review-graph-update-hook.sh` | コード索引の差分更新 |

- 対象は **`Write` と `Edit` の 2 つだけ**である。matcher が英数字・下線・`|` だけで
  出来ているときは正規表現にされず、`|` で分割して**完全一致**で比べられるためで、
  `NotebookEdit` のような派生ツールには一致しない。これは **Claude Code 2.1.233 で
  本体を実読して確かめた挙動**であり(記録は
  `devnotes/20260815-2015-todo-T172/matcher-semantics-evidence.md`)、
  **Claude Code を更新したら人手で再確認する**。
  台帳テスト(`ClaudeHooksWiringTest`)が固定するのは**設定に書かれた matcher 文字列だけ**で、
  本体側の判定機序が変わったことは**検出しない**(文字列が同じまま意味だけ変われば緑のままである)。
  `^(…)$` のようなアンカーは足さない(文字集合から外れて正規表現の経路へ移るだけで、
  意味論の変化を防げるわけではない)。
- 起動子は終了コードの写像器を兼ねる。**PreToolUse をブロックできるのはスクリプトが
  意図して返す 97 だけ**で、構文エラー・ファイル不在・実行不能はすべて 0 に畳まれる
  (hook の故障がセッションの Bash 操作を止めない)。
- 前提コマンド: `flock` / `timeout`(どちらも欠けると索引更新は走らず、セッションごとに
  1 行だけ告知する)。
- **`code-review-graph install` / `init` / `uninstall` を実行しないこと**。これらは MCP 設定・
  hook 配線・本ファイルへの指示注入まで行い、**配線の正本が二重化する**。配線を変えるときは
  `.claude/settings.json` と `tests/Architecture/ClaudeHooksWiringTest.php` の台帳を同じ
  変更で直す。
- 配線を変えたら**新しいセッションを開始するまで反映されない**(設定はセッション開始時に
  1 度だけ読まれる)。
<!-- CLAUDE_HOOKS_WIRING:END -->
```
