## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

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

あなたはコードレビュアーとして Laravel + Svelte アプリ (aicue) の改善実装をレビューする。

## レビュー観点
- 設計との一致性 (詳細設計書の施策 S1〜S7 が漏れなく・過不足なく実装されているか)
- 正確性 (走査器の正規表現・純関数の分岐・fail-closed の倒し方に穴が無いか)
- PHPStan level 10 適合性
- DTO / JsonResource パターン (本件は該当なし)
- テスト網羅性 (負例・正例・母集団の非空・トークン完全一致の 3 形)
- セキュリティ (hook 終了コードの意味論変更で新たに生まれる経路)
- DESIGN.md 準拠 / Atomic Design 準拠 (本件は frontend を 1 行も触らないため該当なし)
- 文書 (AGENTS.md マーカー区間・scripts/README.md・乖離台帳) が実装より強い保証を主張していないか

## 出力形式
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 末尾に全体判定を **APPROVED** または **CHANGES_REQUESTED** で書く

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
| S6 | 塞がない脅威 3 点と、覆わない編集経路の回収根拠・撤回規則を書く (i14/i15) | `tests/Architecture/ClaudeHooksWiringTest.php` / `AGENTS.md` (根拠は `devnotes/20260824-1014-claude-hooks-wiring-t3/code-review-graph-diff-premise.md`) | 高 |
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
  - L529-563 S06b (**判定を反転**。S06c / S06d / S06e を新設)
  - L1063-1087 / L1120-1148 / L1150-1165 拒否コードの期待値 97 → 2 (S5 と連動)
  - L1209-1276 旧 B41〜B50 (**写像の実証 → pass-through の実証**。新採番は B41〜B45)
  - L1278-1305 旧 B51 (維持。新採番は **B46**)

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

    // 「起動子が余計な仕事を持たない」ことを、正準形の一致とは**独立に**トークン語彙で見る。
    // 判定は別の純関数に置く (この分岐の検出力を単独で裏取りできるようにするため)。
    foreach (claudeHooksLauncherForbiddenTokens($command) as $forbidden) {
        $violations[] = "起動子が起動以外の仕事を持っている (禁止トークン {$forbidden}): {$command}";
    }

    return $violations;
}

/**
 * 起動子に現れてはならないトークンを列挙する (純関数。走査器)。
 *
 * **判定は半角空白で割ったトークンの完全一致**である (AGENTS.md 共通規約 (e))。
 * 部分文字列一致にすると `xif` / `!if` / `ifx` のような無関係な語まで拾い、
 * 逆に許可語の除去を部分文字列で書くと本物の `if` まで消える。
 *
 * **区切りは半角空白 1 文字だけ**である。タブ・改行・連続空白を含む形は
 * 呼び出し側 (`claudeHooksLauncherFormViolations()`) が先に違反として落とす。
 *
 * @return list<string> 見つかった禁止トークン (出現順)
 */
function claudeHooksLauncherForbiddenTokens(string $command): array
{
    $vocabulary = ['-c', '&&', '||', ';', 'if', 'then', 'fi', 'exit', '[', 'eval', 'env', 'sh'];

    $found = [];
    foreach (explode(' ', $command) as $token) {
        if (in_array($token, $vocabulary, true)) {
            $found[] = $token;
        }
    }

    return $found;
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
    expect(claudeHooksLauncherForbiddenTokens('/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh"'))->toBe([]);
});

test('S06e (語彙判定の裏取り): 禁止トークンの検出が単独で効いていること', function (): void {
    // S06c の負例はすべて「正準形でない」だけでも赤になるので、語彙判定の分岐は
    // **単独で**裏取りする (この検査があるので `claudeHooksLauncherForbiddenTokens()` を
    // 空実装にすると赤になる)。
    expect(claudeHooksLauncherForbiddenTokens('/bin/bash -p -c \'exit 0\''))->toBe(['-c']);
    expect(claudeHooksLauncherForbiddenTokens('/bin/bash -p "$d/x.sh" ; if [ 1 ] ; then exit 2 ; fi'))
        ->toBe([';', 'if', '[', ';', 'then', 'exit', ';', 'fi']);

    // 区切りで割ったトークンの完全一致であること (接頭辞・打ち消し・接尾辞の 3 形は拾わない)
    foreach (['xif', '!if', 'ifx', 'exits', '-cx', 'ifexit'] as $lookalike) {
        expect(claudeHooksLauncherForbiddenTokens('/bin/bash -p "$d/x.sh" '.$lookalike))
            ->toBe([], "トークン完全一致でない判定になっている: {$lookalike}");
    }
});
```

実起動層 (新採番 B41〜B45) の差し替え:

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
})->with([[0], [1], [2], [3], [97], [127]]);   // **1 つも畳まない**契約なので 2 と 127 も落とさない

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
            'CLAUDE_PROJECT_DIR が無い' => (function () use (&$projectDir): void {
                // 前提を明示する: 未設定だとパスが `/scripts/…` に潰れるので、
                // ホスト側にその実体が無いことを確かめてから 127 を期待する
                expect(is_file('/scripts/bughunt-worktree-hook.sh'))
                    ->toBeFalse('ホストに /scripts/bughunt-worktree-hook.sh が実在するため本ケースの前提が崩れている');
                $projectDir = null;
            })(),
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
    // **ここで実証するのは「明示的に非保証にした 4 形」だけ**であり、非保証の全体を
    // 網羅するものではない (全体の正本は冒頭 docblock の i14 の 3 点である)。
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
            'scripts が symlink' => (function () use ($sandbox): void {
                rename($sandbox.'/scripts', $sandbox.'/real-scripts');
                symlink($sandbox.'/real-scripts', $sandbox.'/scripts');
            })(),
        };

        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PreToolUse'), $projectDir, $cwd);

        expect($result['exitCode'])->toBe(0, "{$case}: 起動子が内側を起こしていない (t3 では検証しないのが正)");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with(['起動元の位置が相対値', '起動元の位置が .. を含む', '起動先が symlink', 'scripts が symlink']);
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
- [x] 新規テスト: S06c (正準形の負例 8 形) / S06d (正例) / **S06e (語彙判定の単独の裏取り)** /
      B43 (標準入力の到達) / B44 (127) / B45 (非保証の実証 4 形)
- [x] 個別の `DatabaseTransactions` を使っていない (本ファイルは DB を触らない)

### リスク

- **拒否がブロックになる経路が harness の 2 に一本化される**ため、`scripts/bughunt-worktree-hook.sh` に
  構文エラーが入ると Bash ツールが止まる。S09 (`bash -n`) が着地前に止める。
- B45 は「撤去した検証」を実挙動で固定するので、将来検証を復活させると赤くなる
  (それは**正典に反する変更**なので赤が正しい)。

---

## S2: 内側の上限と配線の時間切れの数値比較 (i8)

### 変更箇所

- `tests/Architecture/ClaudeHooksWiringTest.php` (新設: 内側上限の申告 const / 抽出の純関数 / 候補行の計数 / 関係の純関数 / 合成本文のヘルパ / S13・S13b・S13c・S13d・S13e)
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
#  5. **明示している 3 つの上限の和**が呼び出し側の時間切れより小さい:
#     標準入力待ち 5 秒 + 更新本体 20 秒 + KILL までの猶予 2 秒 = 27 秒 < 30 秒。
#     台帳テストがこの 3 値と `.claude/settings.json` の timeout を数値で取り出して比較する。
#     **和は「明示した待ちの合計」であって全体の最悪時間ではない** (前処理とプロセス起動の
#     時間は含まない。含める設計 = 前処理ごと内側 timeout で囲む形は採っていない)
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
 * **抽出する 3 値** (どれも**行全体の正準形**で当てる。行頭・行末を固定するのでコメント行
 * (`#` で始まる行) は候補にならない):
 *  - `stdin` … `IFS= read -r -N <bytes> -t <秒> input || true` の秒数 (標準入力を待つ上限)
 *  - `body`  … `readonly INNER_TIMEOUT_SECONDS=<秒>` (更新本体の上限)
 *  - `kill`  … `timeout -k <秒> "${INNER_TIMEOUT_SECONDS}" \` の猶予
 *              (TERM で終わらない相手を KILL するまで)
 *
 * **fail-closed** (見逃す方向へ倒さない = AGENTS.md 共通規約 (b)):
 *  - 申告で必要な形は**ちょうど 1 件**であること。0 件 (数値以外・単位つき・変数展開・
 *    コメントアウト) と 2 件以上 (重複・囮の実行行) はどちらも違反にする
 *  - **候補走査 (`claudeHooksInnerLimitCandidateCounts()`) が申告対象と分類した行**のうち、
 *    正準形でないものが 1 件でも現れたら違反にする (候補の語彙と、その語彙が拾わない書き方 =
 *    絶対パス・別名・変数経由 は候補走査の docblock が正本)
 *  - 抽出できた値は**正の整数**であること (`0` は `timeout` の意味論を壊すので拒否する)
 *  - 台帳に無いスクリプトを渡されたら違反として返す (未知を黙って空で通さない)
 *
 * **保証しないもの**: 見るのは**行の形と数値だけ**であり、shell の制御フロー (その行が
 * 実際に実行されるか・別の待ちが挟まっているか) は見ない。したがって
 * 「実行時の上限を証明する」とは書けない — 主張できるのは
 * 「**明示された 3 つの上限の宣言**が配線の時間切れより小さい」までである。
 *
 * @return array{limits: array{stdin: ?int, body: ?int, kill: ?int}, violations: list<string>}
 */
function claudeHooksInnerLimits(string $body, string $script): array
{
    if (! array_key_exists($script, CLAUDE_HOOKS_INNER_LIMIT_SHAPE)) {
        return [
            'limits' => ['stdin' => null, 'body' => null, 'kill' => null],
            'violations' => ["{$script}: 内側の上限の申告が無い (台帳と申告を同じ変更で更新すること)"],
        ];
    }

    /** @var array{stdin: bool, body: bool, kill: bool} $shape */
    $shape = CLAUDE_HOOKS_INNER_LIMIT_SHAPE[$script];

    // 行全体の正準形。`^` と `$` を複数行モードで固定するので `# read …` は当たらない。
    // `kill` は**次の行の更新本体まで**含めて当てる (猶予が更新の起動に接続していることを見る)。
    $patterns = [
        'stdin' => '/^IFS= read -r -N \d+ -t (\d+) input \|\| true$/m',
        'body' => '/^readonly INNER_TIMEOUT_SECONDS=(\d+)$/m',
        // PHP の単一引用符では `\\\\` が正規表現の `\\` (= リテラルのバックスラッシュ 1 文字) になる
        'kill' => '/^timeout -k (\d+) "\$\{INNER_TIMEOUT_SECONDS\}" \\\\\n +code-review-graph update /m',
    ];

    $limits = ['stdin' => null, 'body' => null, 'kill' => null];
    $violations = [];
    $candidates = claudeHooksInnerLimitCandidateCounts($body);

    foreach ($patterns as $key => $pattern) {
        $count = preg_match_all($pattern, $body, $matches);
        Assert::integer($count, "{$script}: 内側の上限 [{$key}] の走査が失敗した");

        // **候補母集団と正準形の一致数が同じであること**。これが無いと、正準形の行 1 本と
        // 非正準の実行行 (別の変数で上限を渡す行など) が**併存**していても検出できない。
        if ($candidates[$key] !== $count) {
            $violations[] = "{$script}: 内側の上限 [{$key}] に正準形でない実行行がある"
                ." (候補 {$candidates[$key]} 件 / 正準形 {$count} 件)";

            continue;
        }

        if (! $shape[$key]) {
            if ($count > 0) {
                $violations[] = "{$script}: 申告に無い内側の上限 [{$key}] が {$count} 件現れた"
                    .' (申告を同じ変更で更新すること)';
            }

            continue;
        }
        if ($count !== 1) {
            $violations[] = "{$script}: 内側の上限 [{$key}] の宣言が 1 件でない (実測 {$count} 件)"
                .' — 数値として取り出せない形・重複・候補語彙に一致する囮の行は違反である';

            continue;
        }

        $value = (int) $matches[1][0];
        if ($value <= 0) {
            $violations[] = "{$script}: 内側の上限 [{$key}] が正の整数でない (実測 {$value})";

            continue;
        }

        $limits[$key] = $value;
    }

    return ['limits' => $limits, 'violations' => $violations];
}

/**
 * 内側の上限に関わる**候補行**の数を数える (純関数)。
 *
 * 正準形に一致する行だけを数えると「正準形 1 本 + 非正準の実行行」の併存を見逃す。
 * そこで**コメント行を除いた実行行**のうち、関連する語彙を持つ行を候補として別に数え、
 * 呼び出し側が「候補数 == 正準形の一致数」を要求する。
 *
 * **区切りの宣言**: 行は半角空白・タブで**トークン**へ割り、代入は最初の `=` で
 * 左辺と右辺へ割る。判定はトークンの**完全一致**である (部分文字列一致に頼らない = 共通規約 (e))。
 * 候補の語彙は次の 3 つ:
 *  - `stdin` … トークンに `read` と `-t` の両方がある行
 *  - `body`  … 代入の左辺が `INNER_TIMEOUT_SECONDS` の行
 *  - `kill`  … トークンに `timeout` と `-k` の両方がある行
 *
 * **保証しないもの (誇張しない)**: 検出できるのは**宣言した語彙にトークン完全一致する
 * 非正準行の併存**だけである。同じ操作を別の書き方で行う行 — 絶対パス (`/usr/bin/timeout`)・
 * 別名・変数経由 (`"${TIMEOUT_BIN}"`) — は**候補にならないので併存を検出しない**。
 * 逆に `env timeout -k 2 …` は `timeout` と `-k` の両トークンを持つので**候補になる**
 * (行の先頭語だけを見る判定ではない)。
 * 語彙を増やして追いかけない (書き方の全数は列挙できない)。起動子の側で余計なトークンを
 * 禁じているのと違い、スクリプト本文は隣接 feature の領分なので、ここは
 * 「正準形の行が 1 本あること + 宣言した語彙の別行が無いこと」までを見る層である。
 *
 * @return array{stdin: int, body: int, kill: int}
 */
function claudeHooksInnerLimitCandidateCounts(string $body): array
{
    $counts = ['stdin' => 0, 'body' => 0, 'kill' => 0];

    foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue; // コメント行と空行は実行行ではない
        }

        $tokens = preg_split('/[ \t]+/', $trimmed) ?: [];
        if (in_array('read', $tokens, true) && in_array('-t', $tokens, true)) {
            $counts['stdin']++;
        }
        if (in_array('timeout', $tokens, true) && in_array('-k', $tokens, true)) {
            $counts['kill']++;
        }
        foreach ($tokens as $token) {
            if (str_contains($token, '=') && explode('=', $token, 2)[0] === 'INNER_TIMEOUT_SECONDS') {
                $counts['body']++;
            }
        }
    }

    return $counts;
}

/**
 * 合成した hook 本文 (S13b / S13d 用)。**基準は実ファイルと同じ正準形**で、
 * 各データセットは `str_replace()` で**1 か所だけ**変異させる
 * (複数箇所が同時に壊れていると、狙った分岐を消しても別の理由で赤いままになる)。
 *
 * nowdoc (`<<<'BASH'`) を使うのでバックスラッシュはそのまま 1 文字として入る
 * (二重引用符のエスケープの曖昧さを持ち込まない)。基準本文には**囮のコメント行**を
 * 1 本入れてあり、コメントが候補にならないことが同時に固定される。
 */
function claudeHooksSyntheticUpdateHookBody(string $mutate = '', string $replacement = ''): string
{
    $body = <<<'BASH'
        #!/usr/bin/env bash
        # 囮: IFS= read -r -N 1048576 -t 5 input || true
        IFS= read -r -N 1048576 -t 5 input || true
        readonly INNER_TIMEOUT_SECONDS=20
        timeout -k 2 "${INNER_TIMEOUT_SECONDS}" \
            code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
        BASH;

    if ($mutate === '') {
        return $body;
    }

    // **変異元は本文にちょうど 1 か所**であること。`str_replace()` は全出現を置換するので、
    // 存在検査だけだと「1 か所だけ変異させる」が壊れる (基準本文には囮のコメント行があり、
    // 実行行と同じ文字列を含む。stdin の変異元は先頭に改行を付けて一意にする)。
    Assert::same(
        substr_count($body, $mutate),
        1,
        "合成本文の変異元が 1 か所でない: {$mutate}",
    );

    return str_replace($mutate, $replacement, $body);
}

/**
 * 内側の上限と配線の時間切れの**関係**を判定する (純関数)。
 *
 * S13 (実ファイル) と S13c (変異させた入力) の**両方がこの関数を呼ぶ**。
 * 比較を検査の中に直接書くと、比較を消しても変異テストが緑のままになる。
 *
 * 判定するのは「**明示された 3 上限の宣言の和** < 配線の時間切れ」であり、
 * 前処理・プロセス起動の時間は含まない (含められないので主張もしない)。
 *
 * @param  array{stdin: ?int, body: ?int, kill: ?int}  $limits
 * @return list<string>
 */
function claudeHooksInnerLimitRelationViolations(array $limits, int $harness, string $label): array
{
    $declared = array_filter($limits, static fn (?int $value): bool => $value !== null);
    if ($declared === []) {
        return ["{$label}: 内側の上限が 1 つも取れていない (関係を判定できない)"];
    }

    $sum = array_sum($declared);
    if ($sum >= $harness) {
        return [sprintf(
            '%s: 明示された内側の上限の和 %d 秒が配線の時間切れ %d 秒より内側でない',
            $label,
            $sum,
            $harness,
        )];
    }

    return [];
}
```

```php
test('S13: 明示された内側の上限の和が配線の時間切れより小さいこと (数値を両方から取って比較する)', function (): void {
    // 申告の母集団が台帳とちょうど一致すること (申告の余剰・不足を黙って通さない)
    $ledgerScripts = [];
    foreach (CLAUDE_HOOKS_WIRING as $entries) {
        foreach ($entries as $entry) {
            $ledgerScripts[] = $entry['script'];
        }
    }
    sort($ledgerScripts);
    $declaredScripts = array_keys(CLAUDE_HOOKS_INNER_LIMIT_SHAPE);
    sort($declaredScripts);
    expect($declaredScripts)->toBe($ledgerScripts, '内側の上限の申告が台帳のスクリプト集合と一致しない');

    $checked = 0;

    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
        foreach ($entries as $entry) {
            $extracted = claudeHooksInnerLimits(claudeHooksReadFile(base_path($entry['script'])), $entry['script']);
            expect($extracted['violations'])->toBe([], implode("\n", $extracted['violations']));

            // 設定ファイル側の timeout も**設定から**取る (台帳の写しではなく実値を見る)
            $harness = claudeHooksHookTimeout($event);
            expect($harness)->toBe($entry['timeout'], "{$event}: 設定の timeout が台帳と違う");

            // 関係の判定は純関数へ (S13c が同じ関数を呼ぶ = **共通関数の中の**比較を
            // 消したり向きを逆にしたら負例が赤くなる)
            $violations = claudeHooksInnerLimitRelationViolations($extracted['limits'], $harness, $event);
            expect($violations)->toBe([], implode("\n", $violations));
            $checked++;
        }
    }

    expect($checked)->toBe(2, '必須 2 配線を検査していない (i2)');
});

test('S13b (負のコントロール): 内側の上限の走査が違反を実際に検出すること', function (string $body, string $script): void {
    // **基準の合成本文から 1 か所だけ変異させる** (複数箇所が同時に壊れていると、
    // 狙った分岐を消しても別の理由で赤いままになり、分岐の裏取りにならない)。
    $extracted = claudeHooksInnerLimits($body, $script);
    expect($extracted['violations'])->not->toBe([]);
})->with([
    '必要な正準形が 0 件 (変数展開)' => [
        claudeHooksSyntheticUpdateHookBody(
            'readonly INNER_TIMEOUT_SECONDS=20',
            'readonly INNER_TIMEOUT_SECONDS=$FOO',
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '必要な正準形が 2 件 (重複宣言)' => [
        claudeHooksSyntheticUpdateHookBody(
            'readonly INNER_TIMEOUT_SECONDS=20',
            "readonly INNER_TIMEOUT_SECONDS=20\nreadonly INNER_TIMEOUT_SECONDS=99",
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '正準形と非正準の実行行が併存する' => [
        claudeHooksSyntheticUpdateHookBody(
            'code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1',
            "code-review-graph update -q --repo \"\${repo_root}\" > /dev/null 2>&1\n"
                .'timeout -k "${OTHER}" 99 code-review-graph update -q',
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '標準入力待ちが数値でない' => [
        claudeHooksSyntheticUpdateHookBody(
            // 先頭の改行で**実行行だけ**に一意化する (囮のコメント行は `# ` が前に付くので当たらない)
            "\nIFS= read -r -N 1048576 -t 5 input || true",
            "\nIFS= read -r -N 1048576 -t \"\${UNBOUNDED}\" input || true",
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '値が 0 (timeout の意味論が壊れる)' => [
        claudeHooksSyntheticUpdateHookBody('timeout -k 2 ', 'timeout -k 0 '),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '猶予が更新本体へ接続していない' => [
        claudeHooksSyntheticUpdateHookBody(
            'code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1',
            '    true',
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '申告に無い上限が現れた (検問側に本体の宣言がある)' => [
        claudeHooksSyntheticUpdateHookBody(),
        'scripts/bughunt-worktree-hook.sh',
    ],
    '台帳に無いスクリプト' => [
        claudeHooksSyntheticUpdateHookBody(),
        'scripts/unknown-hook.sh',
    ],
]);

test('S13d (正のコントロール): 実ファイルと合成の基準本文から 3 値がちょうど取れること', function (): void {
    // 実ファイル
    $real = claudeHooksInnerLimits(
        claudeHooksReadFile(base_path('scripts/code-review-graph-update-hook.sh')),
        'scripts/code-review-graph-update-hook.sh',
    );
    expect($real['violations'])->toBe([]);
    expect($real['limits'])->toBe(['stdin' => 5, 'body' => 20, 'kill' => 2]);

    // 合成の基準本文 (変異していない = 違反ゼロ)。囮のコメント行があっても件数は増えない
    $synthetic = claudeHooksInnerLimits(
        claudeHooksSyntheticUpdateHookBody(),
        'scripts/code-review-graph-update-hook.sh',
    );
    expect($synthetic['violations'])->toBe([]);
    expect($synthetic['limits'])->toBe(['stdin' => 5, 'body' => 20, 'kill' => 2]);

    // 検問側 (本体と猶予を持たない申告)
    $guard = claudeHooksInnerLimits(
        claudeHooksReadFile(base_path('scripts/bughunt-worktree-hook.sh')),
        'scripts/bughunt-worktree-hook.sh',
    );
    expect($guard['violations'])->toBe([]);
    expect($guard['limits'])->toBe(['stdin' => 5, 'body' => null, 'kill' => null]);
});

test('S13c (負のコントロール): 関係の判定が崩れた数値を落とすこと', function (?int $stdin, ?int $body, ?int $kill, int $harness, bool $shouldFail): void {
    // **S13 と同じ関数**を呼ぶので、**共通関数の中の**比較を消したり向きを逆にしたらここが赤くなる
    // (S13 から呼び出しごと削除された場合はここでは分からない — それは S13 の本文を読むレビューの担当)。
    // dataset を `?int` の 3 引数に分けるのは、closure の `array` に要素型を書けないためである
    // (PHPStan level 10 は iterable value type の欠落を落とす)。
    $violations = claudeHooksInnerLimitRelationViolations(
        ['stdin' => $stdin, 'body' => $body, 'kill' => $kill],
        $harness,
        'テスト入力',
    );

    expect($violations === [])->toBe(! $shouldFail);
})->with([
    '索引更新の現行値 (27 < 30)' => [5, 20, 2, 30, false],
    '等しい (30 は内側でない)' => [5, 20, 5, 30, true],
    '超える (32 > 30)' => [5, 25, 2, 30, true],
    '検問の現行値 (5 < 10)' => [5, null, null, 10, false],
    '1 つも取れていない' => [null, null, null, 30, true],
]);
```

test('S13e (候補計数の裏取り): 候補の語彙が区切りトークンの完全一致で判定されること', function (): void {
    // 候補計数だけを直接検査する (S13b は「併存を検出できる」ことしか示さないので、
    // **誤検出しない側**をここで固定する = AGENTS.md 共通規約 (e) の 3 形)。
    // 正例
    expect(claudeHooksInnerLimitCandidateCounts('IFS= read -r -N 10 -t 5 input || true'))
        ->toBe(['stdin' => 1, 'body' => 0, 'kill' => 0]);
    expect(claudeHooksInnerLimitCandidateCounts('readonly INNER_TIMEOUT_SECONDS=20'))
        ->toBe(['stdin' => 0, 'body' => 1, 'kill' => 0]);
    expect(claudeHooksInnerLimitCandidateCounts('timeout -k 2 "${X}" \\'))
        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 1]);

    // 宣言した区切り: タブでも割れる
    expect(claudeHooksInnerLimitCandidateCounts("timeout\t-k\t2"))
        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 1]);

    // 行の先頭語だけを見る判定ではない (トークンのどこにあっても候補になる)
    expect(claudeHooksInnerLimitCandidateCounts('env timeout -k 2 "${X}"'))
        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 1]);

    // コメント行と空行は実行行ではない
    expect(claudeHooksInnerLimitCandidateCounts("# timeout -k 2\n\n   # readonly INNER_TIMEOUT_SECONDS=20"))
        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 0]);

    // 負例: 接頭辞つき・打ち消しつき・接尾辞つきは候補にしない
    foreach ([
        'xtimeout -k 2', '!timeout -k 2', 'timeoutx -k 2',
        'xread -r -t 5', '!read -r -t 5', 'readx -r -t 5',
        'XINNER_TIMEOUT_SECONDS=20', '!INNER_TIMEOUT_SECONDS=20', 'INNER_TIMEOUT_SECONDSX=20',
    ] as $lookalike) {
        expect(claudeHooksInnerLimitCandidateCounts($lookalike))
            ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 0], "トークン完全一致でない判定になっている: {$lookalike}");
    }
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
// 実測の上限は**設定由来の値** (配線の時間切れ) を使う。根拠の無い余裕の数値を持ち込まない
// (この stub は 120 秒眠るので、内側の時間切れが効いていなければ必ず超える)。
// 数値の関係そのものは静的層 (S13) が見るので、ここは「内側が実際に発火する」ことだけを見る。
expect($result['elapsed'])->toBeLessThan(
    (float) claudeHooksHookTimeout('PostToolUse'),
    '内側の時間切れが効いていない (配線の時間切れまで走ってしまっている)',
);
```

### PHPStan適合チェック

- [x] `claudeHooksInnerLimits()` の戻り値は shape 付き配列 (`?int` を明示)
- [x] `null` 安全: 合算は `array_filter()` で `null` を落としてから行い、**0 を混ぜない**
      (申告で true の値は抽出できなければ違反として先に返るので、残る `null` は
      「その配線が持たない上限」だけである)
- [x] `Assert::integer()` で `mixed` を narrow してから比較へ渡す
- [x] Generics: `CLAUDE_HOOKS_INNER_LIMIT_SHAPE` に `@var array<string, array{stdin: bool, body: bool, kill: bool}>`

### テスト計画

- [x] **先に赤くする**: S13 を入れると現行の `timeout -k 5` で 5+20+5=30 ≥ 30 となり落ちる
      (**この赤が `-k 2` へ変える唯一の理由**である)
- [x] 新規テスト: S13 (実ファイル + 申告の母集団一致) / S13b (抽出の負例 8 形。**基準の合成本文から
      1 か所だけ変異**させる) / S13c (関係の負例。**S13 と同じ純関数**を呼ぶ) /
      S13d (実ファイル + 合成の基準本文 + 検問側の正のコントロール) /
      **S13e (候補計数の 3 形の裏取り + 区切りの宣言 + コメント行の除外)**
- [x] 既存テスト更新: B17 / B18 の直書きを設定・スクリプト由来へ
- [x] `bash -n` (S09) と B18 の実挙動で `-k 2` が壊れていないことを確認

### リスク

- `-k 2` は「TERM を無視する相手を KILL するまでの猶予」が 3 秒短くなる。索引ツールが
  TERM を無視して 2 秒以内に終わらない場合、KILL される (現行も 5 秒後に KILL される
  = 差は待ち時間だけで、結果は同じ)。**KILL 猶予そのものが効くことの実測 (家系の motivation が持つ)
  は本件では持たない** — 前提は GNU coreutils の仕様であり、i8 が要求するのは数値の関係だけである。
- **保証範囲を誇張しない**: 検査が見るのは行の形と数値だけで、shell の制御フローは見ない。
  したがって「実行時に必ず 27 秒以内で終わる」とは書かない (書けない)。主張は
  「明示された 3 上限の宣言の和 < 配線の時間切れ」までであり、この文言をスクリプトのコメント・
  `AGENTS.md`・検査の docblock の 3 か所で**同じ言い方に揃える**。

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

- [x] **先に赤くする (3 段)**: (1) まず**現行挙動そのまま** (`hooks` の不在だけを見る) を純関数へ移し、
      合成入力の負例 (`{"hooks": {}}`) と正例 (`{}`) で緑を確認する →
      (2) `{"permissions": {"allow": []}}` の負例を足す = **旧挙動は許してしまうので赤になる** →
      (3) 判定を全数申告へ広げて緑にする。赤の理由が「純関数がまだ無い」ではなく
      「旧挙動が未知の項目を許す」であることを、この順序で担保する
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
# **帰結として、意図した拒否以外の理由で 2 が返っても同じくブロックになる** (bash が構文エラーで
# 返す 2 はその一例)。畳んで隠さないのは、畳むと配線ミスと実行時の異常を harness も人も
# 区別できなくなるからである。構文エラーが main へ着くこと自体は台帳テストの `bash -n` 検査 (S09)
# が止める。
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
 *  - 静的層 (S01〜S13e): `.claude/settings.json` が下の台帳と**完全一致**することを見る。
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
 *  **条件を満たす変更は次の編集時に回収される**。条件は「**追跡下のパス**であり、作業ツリーの内容が
 *  `HEAD~1` と違うこと」で、これを満たす限りシェルで変えたファイルも次の `Write` / `Edit` が
 *  起こす更新でまとめて索引へ入る。その間だけ索引が古いことは受容する。
 *  **根拠は外部ツールの実装である** — `code-review-graph==2.3.7` (`docker/Dockerfile` が版を固定)
 *  の `update` は既定で `git diff --name-only HEAD~1 --`、つまり**1 つ前のコミットから
 *  作業ツリーまで**の差分を対象にする (実読記録:
 *  `devnotes/20260824-1014-claude-hooks-wiring-t3/code-review-graph-diff-premise.md`)。
 *  **回収されない経路が 2 系統ある (受容する)**:
 *   (1) **未追跡の新規ファイル**。`git diff` は未追跡ファイルを列挙しない。これは作った道具に依らず、
 *       `Write` で作った新規ファイルも `git add` されるまで同じである
 *       = **照合条件に `Bash` を足しても塞がらない** (穴は matcher の選択と直交する)
 *   (2) **差分基準から外れた過去のコミットの変更**。コミットしたあと `Write` / `Edit` を挟まずに
 *       さらにコミットを重ねると `HEAD~1` からの差分に現れない
 *  どちらも配線層では塞げない (`--base` を変える経路も `git add` を起こす経路も配線には無い)。
 *  **無条件の「回収される」とは書かない**。
 *  **本テストはこの前提を機械検証しない** (差分の基準・除外規則・索引状態の更新はツール側の実装)。
 *  したがって**索引ツールを更新したら、matcher の意味論と併せてこの差分回収の前提も
 *  人手で再確認する** (確認項目は上記の実読記録の 5 点)。
 *  **撤回規則**: (a) **上の 2 系統以外**で索引へ入らない実測が出た、(b) 索引ツールの版を上げて
 *  差分基準や未追跡ファイルの扱いが変わった、(c) 上の 2 系統が**実害**として観測された
 *  (索引が古いままコード探索が誤った結果を返した) — このいずれかが起きたら、
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
  **帰結として、意図した拒否以外の理由で hook が 2 を返しても Bash 操作はブロックされる**
  (構文エラーで bash が返す 2 はその一例)。これは意図した交換であり、着地前に台帳テストの
  `bash -n` 検査が構文エラーを止める。
- **明示された内側の上限の和が配線の時間切れより小さい**(検問 10 秒 / 索引更新 30 秒)。
  台帳テストは 3 値(標準入力待ち / 更新本体の上限 / KILL までの猶予)を**スクリプト本文と
  設定の両方から数値で取り出して比較**する(文字列一致では数値の関係が崩れたことを検出できない)。
  **和は明示した待ちの合計であって全体の最悪時間ではない**(前処理とプロセス起動の時間は含まない)。
- 前提コマンド: `flock` / `timeout`(どちらも欠けると索引更新は走らず、セッションごとに
  1 行だけ告知する)。索引更新が**差分方式**であること(= 条件を満たす**追跡下**の変更が次の
  `Write` / `Edit` で回収される前提)は**索引ツール側の実装**であり、台帳テストは機械検証しない。実読記録は
  `devnotes/20260824-1014-claude-hooks-wiring-t3/code-review-graph-diff-premise.md`。
  既定の差分基準は `HEAD~1` から作業ツリーまでで、回収されるのは**追跡下のパス**に限る —
  **未追跡の新規ファイル**(`Write` で作ったものも `git add` まで同じ)と、
  **コミット後に編集を挟まずコミットを重ねた変更**は回収されない。
  **索引ツールを更新したら、matcher の意味論と併せてこの前提も人手で再確認する**。
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
## D18 hook の起動子に特権モードを足し、bug-hunt 検問の判定を外部コマンド非依存で持つ

| 行 | 内容 |
|---|---|
| 対象パス | `.claude/settings.json` / `scripts/bughunt-worktree-hook.sh` / `scripts/code-review-graph-update-hook.sh` |
| 業務要件起因の説明 | 継承したシェル関数と `BASH_ENV` はスクリプトの 1 行目より前に効くので起動子でしか塞げず、検問の判定が外部コマンドに依存すると検索パスが壊れた環境で拒否対象が黙って通る (実測で無音の素通りが起きていた) |
| 揃え続ける不変条件と保証機構 | 配線は常設 2 本で起動子は絶対パスの直呼び、終了コードは畳まず素通し、拒否は 2、明示された内側の 3 上限の宣言の和は配線の時間切れより小さい。`ClaudeHooksWiringTest` が固定する |
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
| **検問 (bug-hunt ガード) の判定** | `grep` / `printf` などの外部コマンドに依存する | bash の組み込みだけで完結し、外部コマンドを 1 つも起こさない |
| 索引更新の実装 | 別実装 | 標準入力を 1 回だけ読み (最大 1 MiB / 5 秒)、告知は目印ファイルの排他的作成で 1 回に抑える |

**「外部コマンド非依存」は検問 (bug-hunt ガード) の判定だけの性質である** — 索引更新 hook は
契約上 `flock` / `timeout` / `code-review-graph` に依存し (無ければ更新せず 1 行告知する)、
テンプレートと同じくこの 3 つを前提にしている。ここを混ぜて書くと保証範囲の誇張になる。

### なぜ正当な差分か (logic-driven)

1. **`-p` は家系の正典 t3 (i6) が要求する形**で、テンプレートは未追従である。継承したシェル関数と
   `BASH_ENV` / `ENV` はスクリプトの 1 行目より前に効くため、スクリプト内のどの防御でも塞げない。
   塞げる唯一の層が起動子であり、交換関係の無い上位互換である。
2. **検問 (bug-hunt ガード) の判定を外部コマンドに依存させない**。以前は `cat` / `python3` /
   `grep` に依存しており、検索パスからそれらを解決できない環境では 127 で終わって
   **拒否対象が黙って通っていた** (無音の素通り)。判定を組み込みだけで書くと検索パスが壊れても
   挙動が変わらない。索引更新 hook は前提コマンドを必要とするので、**無ければ更新せず
   1 行だけ告知する**形で成立させている (依存を消すのではなく、依存が欠けたときの挙動を固定する)。
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
   (S03〜S06)。明示された内側の上限の和と配線の時間切れの関係も pin する
   (S13 と、同じ純関数を通る負例 S13c)

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
| 業務要件起因の説明 | 本アプリの検問は「外部コマンドに依存しない判定」、索引更新は「目印ファイルの排他的作成による告知抑止」という独自の実行契約を持ち、その契約と家系の正典 t3 が要求する検査 (内側と外側の数値比較 / 設定 2 層の全数申告 / 配線文字列そのものの実起動 / 非保証の明記) を同じ 1 ファイルで固定する必要がある |
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
| 内側の上限と配線の時間切れ | 数値の比較を持たない | 3 値をスクリプト本文と設定の両方から取り出し、**関係の判定を純関数へ切り出して**比較する (i8。同じ関数を負例 S13c が呼ぶ) |
| ローカル層の設定 | `hooks` の不在と、hook を止める設定項目 3 つの名指し | トップレベルの**全数申告** (申告は空。`hooks` は申告に足せない) (i10) |
| 非保証の明記 | 冒頭に一部 | 塞がない脅威 3 点と、覆わない編集経路の回収根拠・撤回規則を冒頭に書く (i14 / i15) |
| 走査域の空振り検査 | 持たない | glob ごとに「非空が契約か」を申告し、走査根を差し替えた負のコントロールを持つ (S12c) |

### なぜ正当な差分か (logic-driven)

1. **検査は自リポジトリの実行契約を検査する**。本アプリの 2 本のスクリプトは
   D18 のとおりテンプレートと別実装であり、その契約 (**bug-hunt 検問の**外部コマンド非依存な判定 /
   **索引更新の**告知の 1 回性 / **索引更新の**置き場が symlink なら何も書かないこと) を
   実証する実起動層はテンプレートの検査では代替できない。
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
  (S06c / S06d / S06e / S07b / S07c / S12c / S13b / S13c / S13d / S13e)

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
4. **正典 i15 の根拠の言い方の是正を提案する**。正典 (と spirux の台帳) は「直前の索引時点からの差分を
   見るので回収される」と書いているが、実装は「1 つ前のコミットから作業ツリーまでの差分」であり
   **未追跡の新規ファイルは対象外**である (実読記録
   `devnotes/20260824-1014-claude-hooks-wiring-t3/code-review-graph-diff-premise.md`)。
   結論 (`Bash` を照合条件に足さない) は変わらない — 未追跡の穴は matcher と直交するので
   `Bash` を足しても解消せず、むしろ正典の判断を補強する。**根拠の記述だけが不正確**なので報告する。


## 実装時の採番の読み替え (設計書の仮採番からの差分)

設計書は乖離台帳の新設を「D50」、件数 pin を「46 → 47 / 148 → 147」と仮に書いているが、
実装時点の main では D50 / D51 が別 TODO (T255 / T256) に使われ、
DIVERGENCE_ENTRY_COUNT は 48、ADOPTION_DEBT_COUNT は 147 だった。
よって実装では **新設を D52**、**DIVERGENCE_ENTRY_COUNT 48 → 49**、
**ADOPTION_DEBT_COUNT 147 → 146** としている (設計の意図は同じで、現物から数え直したもの)。

## テスト結果 (全 green)

- `composer test` (全 7715 件): 7712 passed / 1 failed / 2 skipped。
  失敗した 1 件は `BughuntSelfTestExecutionTest` (bug-hunt self-test の pid 所有確認) で、
  **本変更を当てていない main でも同じく失敗する既存の flake** (実行ホストに live pid が多いため)。
  本件が触る `ClaudeHooksWiringTest` / `TemplateDivergence*Test` を含む
  `tests/Architecture` 全 1694 件は passed。
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (2398) / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106): 全 green
- テストファーストの赤の確認: S1〜S3 を当てた時点で 21 件が赤
  (S05/S06 の完全一致 / S06b の形 / S13 の 5+20+5=30 ≥ 30 / S13d の 3 値 /
  B26 系・B29・B34/B36 の拒否コード / B41・B42 の畳み / B44 の 127)。
  S4〜S7 を当てて緑になった。

---

## 実装差分 (git diff)

```diff

diff --git a/.claude/settings.json b/.claude/settings.json
index b81bb144..bdba1a68 100644
--- a/.claude/settings.json
+++ b/.claude/settings.json
@@ -6,7 +6,7 @@
         "hooks": [
           {
             "type": "command",
-            "command": "/bin/bash -p -c 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/scripts/bughunt-worktree-hook.sh; s=0; if [ -n \"$d\" ] && [ \"${d#/}\" != \"$d\" ] && [ \"${d//../}\" = \"$d\" ] && [ -d \"$d/scripts\" ] && [ ! -L \"$d/scripts\" ] && [ -f \"$f\" ] && [ ! -L \"$f\" ]; then /bin/bash -p \"$f\"; s=$?; fi; if [ \"$s\" = 97 ]; then exit 2; fi; exit 0'",
+            "command": "/bin/bash -p \"$CLAUDE_PROJECT_DIR/scripts/bughunt-worktree-hook.sh\"",
             "timeout": 10
           }
         ]
@@ -18,7 +18,7 @@
         "hooks": [
           {
             "type": "command",
-            "command": "/bin/bash -p -c 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/scripts/code-review-graph-update-hook.sh; if [ -n \"$d\" ] && [ \"${d#/}\" != \"$d\" ] && [ \"${d//../}\" = \"$d\" ] && [ -d \"$d/scripts\" ] && [ ! -L \"$d/scripts\" ] && [ -f \"$f\" ] && [ ! -L \"$f\" ]; then /bin/bash -p \"$f\"; fi; exit 0'",
+            "command": "/bin/bash -p \"$CLAUDE_PROJECT_DIR/scripts/code-review-graph-update-hook.sh\"",
             "timeout": 30
           }
         ]
diff --git a/AGENTS.md b/AGENTS.md
index 24938d85..58bd35f5 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -405,11 +405,37 @@ ## 常設 hook 配線
   本体側の判定機序が変わったことは**検出しない**(文字列が同じまま意味だけ変われば緑のままである)。
   `^(…)$` のようなアンカーは足さない(文字集合から外れて正規表現の経路へ移るだけで、
   意味論の変化を防げるわけではない)。
-- 起動子は終了コードの写像器を兼ねる。**PreToolUse をブロックできるのはスクリプトが
-  意図して返す 97 だけ**で、構文エラー・ファイル不在・実行不能はすべて 0 に畳まれる
-  (hook の故障がセッションの Bash 操作を止めない)。
+- **起動子はスクリプトを起こすだけ**である
+  (`/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/<name>"`)。`-p`(特権モード)は継承した
+  シェル関数と `BASH_ENV` / `ENV` を無効化するために付ける(スクリプトの 1 行目より前に効く層で、
+  スクリプト内のどの防御でも代替できない)。**終了コードの写像・起動前の条件分岐・
+  インラインのシェル片は置かない** — hook の終了コードは harness の唯一の制御信号
+  (`PreToolUse` の **2 はブロック**、それ以外の非 0 はブロックしない異常として面に出る)で、
+  畳むと配線ミスと実行時の異常を harness も人も区別できなくなる(家系の正典 t3)。
+  bug-hunt ガードの拒否も **2** である。
+  **帰結として、意図した拒否以外の理由で hook が 2 を返しても Bash 操作はブロックされる**
+  (構文エラーで bash が返す 2 はその一例)。これは意図した交換であり、着地前に台帳テストの
+  `bash -n` 検査が構文エラーを止める。
+- **明示された内側の上限の和が配線の時間切れより小さい**(検問 10 秒 / 索引更新 30 秒)。
+  台帳テストは 3 値(標準入力待ち / 更新本体の上限 / KILL までの猶予)を**スクリプト本文と
+  設定の両方から数値で取り出して比較**する(文字列一致では数値の関係が崩れたことを検出できない)。
+  **和は明示した待ちの合計であって全体の最悪時間ではない**(前処理とプロセス起動の時間は含まない)。
 - 前提コマンド: `flock` / `timeout`(どちらも欠けると索引更新は走らず、セッションごとに
-  1 行だけ告知する)。
+  1 行だけ告知する)。索引更新が**差分方式**であること(= 条件を満たす**追跡下**の変更が次の
+  `Write` / `Edit` で回収される前提)は**索引ツール側の実装**であり、台帳テストは機械検証しない。実読記録は
+  `devnotes/20260824-1014-claude-hooks-wiring-t3/code-review-graph-diff-premise.md`。
+  既定の差分基準は `HEAD~1` から作業ツリーまでで、回収されるのは**追跡下のパス**に限る —
+  **未追跡の新規ファイル**(`Write` で作ったものも `git add` まで同じ)と、
+  **コミット後に編集を挟まずコミットを重ねた変更**は回収されない。
+  **索引ツールを更新したら、matcher の意味論と併せてこの前提も人手で再確認する**。
+- `.claude/settings.local.json` は**トップレベル項目を 1 つも持てない**(全数申告が空)。
+  常設配線をローカル層から無効化する経路を作らないためで、項目を置きたくなったら
+  台帳テストの申告を同じ変更で更新する(`hooks` は申告に足せない)。
+- **配線層が塞がない範囲**(起動子自体の差し替え / 環境変数を仕込める攻撃者 /
+  リポジトリ外の設定層)と、**索引更新が覆わない編集経路の始末**(シェル経由の変更が次の
+  `Write` / `Edit` で回収される根拠と撤回規則)の**正本は
+  `tests/Architecture/ClaudeHooksWiringTest.php` の冒頭**にある。本書には写さない
+  (2 か所に書くと必ず食い違う)。
 - **`code-review-graph install` / `init` / `uninstall` を実行しないこと**。これらは MCP 設定・
   hook 配線・本ファイルへの指示注入まで行い、**配線の正本が二重化する**。配線を変えるときは
   `.claude/settings.json` と `tests/Architecture/ClaudeHooksWiringTest.php` の台帳を同じ
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 156501b1..de96d672 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 48 件
+登録エントリ: 49 件
 
 ## 記録の原則
 
@@ -1011,62 +1011,70 @@ ### 関連
 
 ---
 
-## D18 hook の起動子を「起動先の検証 + 終了コードの写像器」にする
+## D18 hook の起動子に特権モードを足し、bug-hunt 検問の判定を外部コマンド非依存で持つ
 
 | 行 | 内容 |
 |---|---|
 | 対象パス | `.claude/settings.json` / `scripts/bughunt-worktree-hook.sh` / `scripts/code-review-graph-update-hook.sh` |
-| 業務要件起因の説明 | hook の故障がセッションの操作を止めてはならず、起動先の検証は起動された後では手遅れなので起動子にしか置けない |
-| 揃え続ける不変条件と保証機構 | 配線は常設で起動子は絶対パス、排他はスクリプト内にあり、配線は台帳テストで完全一致 pin される。`ClaudeHooksWiringTest` が固定する |
-| 再判定の条件 | Claude Code が hook の終了コードの扱いを変えたとき / 家系が起動子の形を確定したとき |
+| 業務要件起因の説明 | 継承したシェル関数と `BASH_ENV` はスクリプトの 1 行目より前に効くので起動子でしか塞げず、検問の判定が外部コマンドに依存すると検索パスが壊れた環境で拒否対象が黙って通る (実測で無音の素通りが起きていた) |
+| 揃え続ける不変条件と保証機構 | 配線は常設 2 本で起動子は絶対パスの直呼び、終了コードは畳まず素通し、拒否は 2、明示された内側の 3 上限の宣言の和は配線の時間切れより小さい。`ClaudeHooksWiringTest` が固定する |
+| 再判定の条件 | テンプレートが特権モードを取り込んだとき / Claude Code が hook の終了コードの扱いを変えたとき / 家系が起動子の形を再確定したとき |
 | 決めた日 | 2026-08-15 |
 | 決めた人 | 開発者 |
 | 根拠 | T172 |
 | 状態 | 恒久 |
 | 見直し期限 | — |
 
-常設 hook 配線 (家系の feature `claude-hooks-wiring`) を取り込むにあたり、**起動子の形だけ**
-テンプレートと変えた。配線されている hook の本数・対象・スクリプトの置き場所は正典どおりである。
+常設 hook 配線 (家系の feature `claude-hooks-wiring` / 正典の版 t3) のうち、
+**起動子に足した旗 1 つ**と**2 本のスクリプトの実装**がテンプレートと違う。
+配線されている hook の本数・対象・置き場所・時間切れは正典どおりである。
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
-| 起動子 | `/bin/bash "$CLAUDE_PROJECT_DIR/scripts/…"` (スクリプトを直に起動) | `/bin/bash -p -c '…'` で起動先を検証してから起動し、終了コードを写像する |
-| hook の終了コードの扱い | スクリプトの終了コードがそのまま harness へ届く | PreToolUse は **97 だけ**を 2 (ブロック) へ写し、それ以外はすべて 0 に畳む |
-| 環境からのシェル関数 | 内側へ継承される | `-p` (privileged mode) で遮断する |
+| 起動子 | `/bin/bash "$CLAUDE_PROJECT_DIR/scripts/…"` | 同じ形に `-p` (特権モード) を足す |
+| **検問 (bug-hunt ガード) の判定** | `grep` / `printf` などの外部コマンドに依存する | bash の組み込みだけで完結し、外部コマンドを 1 つも起こさない |
+| 索引更新の実装 | 別実装 | 標準入力を 1 回だけ読み (最大 1 MiB / 5 秒)、告知は目印ファイルの排他的作成で 1 回に抑える |
 
-### なぜ正当な差分か (logic-driven)
+**「外部コマンド非依存」は検問 (bug-hunt ガード) の判定だけの性質である** — 索引更新 hook は
+契約上 `flock` / `timeout` / `code-review-graph` に依存し (無ければ更新せず 1 行告知する)、
+テンプレートと同じくこの 3 つを前提にしている。ここを混ぜて書くと保証範囲の誇張になる。
 
-1. **hook の故障がセッションを止めてはならない**。bash は構文エラーでも 2 を返し、
-   PreToolUse の 2 は Bash ツールをブロックする。テンプレートの形では hook スクリプトの
-   1 文字のタイプミスが、そのセッションの Bash 操作を全滅させうる。
-   写像器を**設定ファイル側**に置くと、スクリプトの退行から独立して「拒否できるのは
-   意図した 97 だけ」を保てる。
-2. **起動先の検証は起動子にしか置けない**。`CLAUDE_PROJECT_DIR` が相対値・`..` 入り・
-   `scripts/` が symlink・起動先が symlink のいずれかなら、内側を起動しないのが正しい。
-   これはスクリプトが起動された後では手遅れである。
-3. **シェル関数の注入**は、判定を組み込みだけで書いても環境から乗っ取れる。
-   遮断は起動の瞬間 (`-p`) にしかできない。
+### なぜ正当な差分か (logic-driven)
 
-検査はすべて bash の組み込み (`[` / パラメータ展開) で行い、外部コマンドを 1 つも使わない。
+1. **`-p` は家系の正典 t3 (i6) が要求する形**で、テンプレートは未追従である。継承したシェル関数と
+   `BASH_ENV` / `ENV` はスクリプトの 1 行目より前に効くため、スクリプト内のどの防御でも塞げない。
+   塞げる唯一の層が起動子であり、交換関係の無い上位互換である。
+2. **検問 (bug-hunt ガード) の判定を外部コマンドに依存させない**。以前は `cat` / `python3` /
+   `grep` に依存しており、検索パスからそれらを解決できない環境では 127 で終わって
+   **拒否対象が黙って通っていた** (無音の素通り)。判定を組み込みだけで書くと検索パスが壊れても
+   挙動が変わらない。索引更新 hook は前提コマンドを必要とするので、**無ければ更新せず
+   1 行だけ告知する**形で成立させている (依存を消すのではなく、依存が欠けたときの挙動を固定する)。
+3. **終了コードは畳まない**。旧実装は起動子で「97 だけを 2 へ写し、それ以外を 0 に畳む」形だったが、
+   正典 t3 (i7) はこれを採らない — harness の唯一の制御信号を潰すと配線ミスと実行時の異常を
+   区別できなくなるためである。畳まない帰結として構文エラー (bash の 2) がブロックになり得るが、
+   `ClaudeHooksWiringTest` の `bash -n` 検査が着地前に止める。
 
 ### 揃えている不変条件 (これは保証し続ける)
 
-> 「配線は常設で、起動子は絶対パスで、排他はスクリプト内にあり、配線は台帳テストで
-> 完全一致 pin される」
+> 「配線は常設で、起動子は絶対パスの直呼びで、終了コードは素通しで、排他はスクリプト内にあり、
+> 配線は台帳テストで完全一致 pin される」
 
-1. `.claude/settings.json` は git 追跡下の配線の正本で、見本ファイル方式は復活させない
-   (`ClaudeHooksWiringTest` の S02 / S08)
-2. 起動子は `/bin/bash` の絶対パスで始まる (S06b)
-3. 索引更新の排他は hook スクリプト内の `flock` が持つ (B16 / B17)
-4. hook 種別 / matcher / 起動コマンド文字列 / timeout / トップレベルキーを完全一致で pin する
-   (S03〜S06)。97 → 2 の写像そのものも実起動で固定する (B41〜B50)
+1. `.claude/settings.json` は git 追跡下の配線の正本で、見本ファイル方式は復活させない (S02 / S08)
+2. 起動子は `/bin/bash -p "$CLAUDE_PROJECT_DIR/…"` の 3 トークンで、起動以外の仕事を持たない
+   (S06b / S06c / S06d)
+3. 内側の終了コードがそのまま返る (B41 / B42)、拒否は 2 である (B26 系)
+4. 索引更新の排他は hook スクリプト内の `flock` が持つ (B16 / B17)
+5. hook 種別 / matcher / 起動コマンド文字列 / timeout / トップレベルキーを完全一致で pin する
+   (S03〜S06)。明示された内側の上限の和と配線の時間切れの関係も pin する
+   (S13 と、同じ純関数を通る負例 S13c)
 
 ### 関連
 
 - 実装: `.claude/settings.json` / `scripts/bughunt-worktree-hook.sh` /
   `scripts/code-review-graph-update-hook.sh`
 - gate: `tests/Architecture/ClaudeHooksWiringTest.php`
-- 設計: `devnotes/20260815-1539-claude-hooks-settings-wiring/`
+- 設計: `devnotes/20260815-1539-claude-hooks-settings-wiring/` (常設化) /
+  `devnotes/20260824-1014-claude-hooks-wiring-t3/` (正典 t3 追従)
 - 規約の正本: `AGENTS.md` §常設 hook 配線
 
 ---
@@ -2974,3 +2982,59 @@ ### 関連
 - 実装: `tests/Architecture/EnvExampleInvariantTest.php`
 - 設計: `devnotes/20260824-1014-gate-env-example-sync-t2/` (t1 化は `devnotes/20260817-1309-todo-t213-env-example-gate-t1/`)
 - 家系: lctl feature `gate-env-example-sync` (`canonical_version: t2` / 2026-08-22 確定)
+
+
+---
+
+## D52 hook 配線の台帳検査を、正典 t3 の要求を満たす独自構成で持つ
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Architecture/ClaudeHooksWiringTest.php` |
+| 業務要件起因の説明 | 本アプリの検問は「外部コマンドに依存しない判定」、索引更新は「目印ファイルの排他的作成による告知抑止」という独自の実行契約を持ち、その契約と家系の正典 t3 が要求する検査 (内側と外側の数値比較 / 設定 2 層の全数申告 / 配線文字列そのものの実起動 / 非保証の明記) を同じ 1 ファイルで固定する必要がある |
+| 揃え続ける不変条件と保証機構 | 検査のファイル名と 2 層構成 (静的な層 + 実起動の層) を維持し、配線 (できごと / 照合条件 / 起動コマンド文字列 / 時間切れ) を既定拒否の台帳と完全一致で固定する。件数は `Tests\Support\TemplateDivergence\LedgerPins` が pin する |
+| 再判定の条件 | テンプレートが正典 t3 追従の検査を取り込んだとき / 家系が正典の次の版を確定したとき |
+| 決めた日 | 2026-08-24 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260824-1014-claude-hooks-wiring-t3/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+採用時点ではこの食い違いに説明が無く、採用時債務 (D34 の一覧) として凍結されていた。
+正典 t3 追従で検査の中身を書き換えるため、**債務ではなく意図的逸脱として説明を書く**
+(債務パスは内容を変えると突合 gate の債務規則で落ちる = 「変更したまま債務に残す」は選べない)。
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 実起動層が起こすもの | hook スクリプトを直接起動する | **設定から取り出した起動コマンド文字列そのもの**を起動する (正典 t3 の i11) |
+| 内側の上限と配線の時間切れ | 数値の比較を持たない | 3 値をスクリプト本文と設定の両方から取り出し、**関係の判定を純関数へ切り出して**比較する (i8。同じ関数を負例 S13c が呼ぶ) |
+| ローカル層の設定 | `hooks` の不在と、hook を止める設定項目 3 つの名指し | トップレベルの**全数申告** (申告は空。`hooks` は申告に足せない) (i10) |
+| 非保証の明記 | 冒頭に一部 | 塞がない脅威 3 点と、覆わない編集経路の回収根拠・撤回規則を冒頭に書く (i14 / i15) |
+| 走査域の空振り検査 | 持たない | glob ごとに「非空が契約か」を申告し、走査根を差し替えた負のコントロールを持つ (S12c) |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **検査は自リポジトリの実行契約を検査する**。本アプリの 2 本のスクリプトは
+   D18 のとおりテンプレートと別実装であり、その契約 (**bug-hunt 検問の**外部コマンド非依存な判定 /
+   **索引更新の**告知の 1 回性 / **索引更新の**置き場が symlink なら何も書かないこと) を
+   実証する実起動層はテンプレートの検査では代替できない。
+2. **正典 t3 が要求する 4 点をテンプレートがまだ持たない**。テンプレートのセルも
+   `update_pending (pre-t3)` であり、追従の順序が逆になっている (本アプリが先行する)。
+3. **1 ファイルに集めるのは正典の要求 (i13)** である。ファイル名も正典が固定しており、
+   本アプリはそれに従っている (逸脱は中身だけである)。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「配線の検査は 1 ファイル (`tests/Architecture/ClaudeHooksWiringTest.php`) に集め、
+> 静的な層と実起動の層の 2 層で、台帳に無い配線をすべて違反とする既定拒否を保つ」
+
+- 台帳と設定の完全一致 (S03〜S06) と、台帳に無いイベント・キーの既定拒否
+- 実起動層が別プロセスで hook と起動子を本当に起こすこと (B01〜B46)
+- 新設・変更した走査には負例と正例を置き、母集団の非空を検査すること
+  (S06c / S06d / S06e / S07b / S07c / S12c / S13b / S13c / S13d / S13e)
+
+### 関連
+
+- 実装: `tests/Architecture/ClaudeHooksWiringTest.php`
+- 設計: `devnotes/20260824-1014-claude-hooks-wiring-t3/`
+- 関連する登録: D18 (起動子と 2 本のスクリプト) / D34 (採用時債務の一覧)
diff --git a/scripts/README.md b/scripts/README.md
index d52ec425..42a567d4 100644
--- a/scripts/README.md
+++ b/scripts/README.md
@@ -49,7 +49,7 @@ ## スクリプト一覧
 | `bug-hunt-inventory.py` | bug-hunt 目録 (`.claude/skills/app-bug-hunt/{screens,operations}.md`) の生成器兼検査器。`generate` は実装の機械事実 + 注釈 (`inventory/annotations.toml`) + 散文 (`inventory/notes-*.md`) + シナリオカードの前付け (`stories/S*.md` の `covers_*` = **割当の正本**) から 2 ファイルを作り、`check` は同じ合成をメモリ上で行って byte 比較する (**1 バイトも書かない**)。exit 0=一致 / 2=致命 / 3=ドリフト | route 追加・削除時に `generate` / CI と bug-hunt 実行前に `check` |
 | `bug-hunt-inventory-check.sh` | bug-hunt 目録のドリフト検査の起動口。判定は持たず `bug-hunt-inventory.py check` を exec するだけ (同じ規則を 2 か所に置かない) | route 追加・削除時 / bug-hunt 実行前 / CI (`php` job) |
 | `tests/test_bug_hunt_inventory.py` | `bug-hunt-inventory.py` の自己テスト (標準ライブラリのみ)。実 `php` を呼ばず fake scanner で段 1..4 と差し替えの失敗経路を検証する | `composer test` (`tests/Architecture/BughuntInventoryToolSelfTest.php` が起動) |
-| `bughunt-worktree-hook.sh` | PreToolUse(Bash) ガード。`bug-hunt-shard.sh provision` の **main 直叩き** (worktree 指紋なし) を harness 層で拒否する (拒否は終了コード 97。起動子が 97 だけを 2 へ写す)。判定は bash の組み込みだけで完結し、外部コマンドを 1 つも使わない | `.claude/settings.json` に常設配線 (AGENTS.md §常設 hook 配線) |
+| `bughunt-worktree-hook.sh` | PreToolUse(Bash) ガード。`bug-hunt-shard.sh provision` の **main 直叩き** (worktree 指紋なし) を harness 層で拒否する (拒否は終了コード **2** = harness の唯一の拒否信号。起動子は写像をしないのでそのまま届く)。判定は bash の組み込みだけで完結し、外部コマンドを 1 つも使わない | `.claude/settings.json` に常設配線 (AGENTS.md §常設 hook 配線) |
 | `code-review-graph-update-hook.sh` | PostToolUse(Write/Edit) hook。コード索引 (code-review-graph) を `flock` 排他 + 内側 20 秒の時間切れ付きで差分更新する。何が起きても終了コード 0 で終わり、標準出力は常に空。告知はセッションごと・理由ごとに標準エラー 1 行だけ | `.claude/settings.json` に常設配線 (AGENTS.md §常設 hook 配線) |
 | `claude` | Claude Code を VSCode 拡張のネイティブバイナリ経由で起動 (2 つの置き場 `~/.vscode` / `~/.vscode-server` から最新版を選ぶ。platform が完全一致する拡張が無ければ拾い直して警告する) | (内部スクリプト) |
 | `claude-wrapper.test.ts` | `claude` の回帰テスト (最新版の選択 / 完全一致が無いときの拾い直しと警告 / 未検出時の終了 / 既定フラグの前置と opt-out / 引数のそのまま転送) | `pnpm test` |
diff --git a/scripts/bughunt-worktree-hook.sh b/scripts/bughunt-worktree-hook.sh
index 2e6c2b94..46c21861 100755
--- a/scripts/bughunt-worktree-hook.sh
+++ b/scripts/bughunt-worktree-hook.sh
@@ -46,9 +46,14 @@ _hook_sanitize_path() {
 _hook_sanitize_path
 # ---8< /SHARED_PATH_PROLOGUE >8---
 
-# 拒否は終了コード 97 で表す。.claude/settings.json の起動子が 97 だけを 2 へ写像するため、
-# 構文エラー (2) や実行不能 (126/127) が Bash ツールをブロックすることは無い。
-readonly DENY_EXIT_CODE=97
+# 拒否は終了コード 2 で表す — これは harness が「この操作をブロックせよ」と解釈する唯一の値である
+# (`PreToolUse` の 2 だけがブロックで、それ以外の非 0 はブロックしない異常として面に出る)。
+# 起動子は終了コードを写像しないので、ここで返す値がそのまま harness へ届く (家系の正典 t3 の i7)。
+# **帰結として、意図した拒否以外の理由で 2 が返っても同じくブロックになる** (bash が構文エラーで
+# 返す 2 はその一例)。畳んで隠さないのは、畳むと配線ミスと実行時の異常を harness も人も
+# 区別できなくなるからである。構文エラーが main へ着くこと自体は台帳テストの `bash -n` 検査 (S09)
+# が止める。
+readonly DENY_EXIT_CODE=2
 
 # 標準入力は 1 回だけ読む。最大 1 MiB / 最大 5 秒 (閉じない相手に待ち続けない)。
 input=''
diff --git a/scripts/code-review-graph-update-hook.sh b/scripts/code-review-graph-update-hook.sh
index 2b888ebc..336a69c9 100644
--- a/scripts/code-review-graph-update-hook.sh
+++ b/scripts/code-review-graph-update-hook.sh
@@ -6,7 +6,11 @@
 #  2. 標準出力は常に空
 #  3. 告知は標準エラーに 1 行だけ。セッションごと・理由ごとに 1 回だけ
 #  4. 更新は必ず flock で排他する。安全に排他できない環境では更新しない
-#  5. 呼び出し側の時間切れ (30 秒) より内側 (20 秒) で自分から諦める
+#  5. **明示している 3 つの上限の和**が呼び出し側の時間切れより小さい:
+#     標準入力待ち 5 秒 + 更新本体 20 秒 + KILL までの猶予 2 秒 = 27 秒 < 30 秒。
+#     台帳テストがこの 3 値と `.claude/settings.json` の timeout を数値で取り出して比較する。
+#     **和は「明示した待ちの合計」であって全体の最悪時間ではない** (前処理とプロセス起動の
+#     時間は含まない。含める設計 = 前処理ごと内側 timeout で囲む形は採っていない)
 #  6. 作業ディレクトリと環境変数に依存しない (リポジトリルートは自分の位置から解決する)
 #  7. 最初の外部コマンド呼び出しより前に検索パスを安全化する
 #  8. 置き場・ロック・告知フラグが symlink なら何も書かずに終える
@@ -135,7 +139,7 @@ if ! command -v timeout > /dev/null 2>&1; then
 fi
 
 # --- 段 8: 差分更新 ----------------------------------------------------------
-timeout -k 5 "${INNER_TIMEOUT_SECONDS}" \
+timeout -k 2 "${INNER_TIMEOUT_SECONDS}" \
     code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
 status=$?
 case "${status}" in
diff --git a/tests/Architecture/ClaudeHooksWiringTest.php b/tests/Architecture/ClaudeHooksWiringTest.php
index 61452dfb..f7f8e3a0 100644
--- a/tests/Architecture/ClaudeHooksWiringTest.php
+++ b/tests/Architecture/ClaudeHooksWiringTest.php
@@ -10,13 +10,54 @@
  * 常設 hook 配線の台帳 (deny-by-default) と、hook スクリプトの実挙動ゲート。
  *
  * 本テストは 2 層で構成する:
- *  - 静的層 (S01〜S12c): `.claude/settings.json` が下の台帳と**完全一致**することを見る。
+ *  - 静的層 (S01〜S13e): `.claude/settings.json` が下の台帳と**完全一致**することを見る。
  *    台帳に無い hook・イベント・トップレベルキーはすべて違反 = 配線の正本が 1 か所になる。
- *    末尾の S12c は S12b の走査域が空振りしていないことの検査である。
- *  - 実起動層 (B01〜B51): hook スクリプトと起動子を**別プロセスで本当に起動**して、
+ *    ローカル層 (`.claude/settings.local.json`) のトップレベルも全数申告制で、申告に `hooks` は
+ *    足せない。内側の上限と配線の時間切れは**数値を両方から取って比較**する。
+ *    S12c は S12b の走査域が空振りしていないことの検査である。
+ *  - 実起動層 (B01〜B46): hook スクリプトと起動子を**別プロセスで本当に起動**して、
  *    終了コード・標準出力の空・告知の回数・排他・敵対的な検索パス・symlink の置き場での
  *    振る舞いを実証する。静的検査だけでは「書いてあるが効いていない」を検出できない。
  *
+ * -------------------------------------------------------------------------
+ * **配線層が塞がないもの** (家系の正典 t3 の i14。緑であることを実際より強く読ませないために書く):
+ *  1. **起動子 `/bin/bash` 自体を差し替えられる攻撃者**。起動子を絶対パスで書くのは検索パス経由の
+ *     すり替えを防ぐためで、`/bin/bash` そのものを置き換えられる相手には何も効かない
+ *  2. **`$CLAUDE_PROJECT_DIR` を含む環境変数を仕込める攻撃者**。起動先のパスはこの変数から
+ *     組まれる。t3 の起動子は値を検証しない (B45 がその挙動を実挙動で見えるようにしている)。
+ *     `-p` が塞ぐのは継承したシェル関数と `BASH_ENV` / `ENV` **だけ**である
+ *  3. **リポジトリの外に置かれた設定層**。hook の設定は利用者層・管理者層にも置け、管理者は
+ *     プロジェクト層の hook をまとめて無効化できる。リポジトリ内の検査からは原理的に見えない
+ *
+ * **索引更新の配線が覆わない編集経路** (i15):
+ *  `matcher` は `Write|Edit` なので、**シェル経由の変更 (Bash ツール) は索引更新を起こさない**。
+ *  **条件を満たす変更は次の編集時に回収される**。条件は「**追跡下のパス**であり、作業ツリーの内容が
+ *  `HEAD~1` と違うこと」で、これを満たす限りシェルで変えたファイルも次の `Write` / `Edit` が
+ *  起こす更新でまとめて索引へ入る。その間だけ索引が古いことは受容する。
+ *  **根拠は外部ツールの実装である** — `code-review-graph==2.3.7` (`docker/Dockerfile` が版を固定)
+ *  の `update` は既定で `git diff --name-only HEAD~1 --`、つまり**1 つ前のコミットから
+ *  作業ツリーまで**の差分を対象にする (実読記録:
+ *  `devnotes/20260824-1014-claude-hooks-wiring-t3/code-review-graph-diff-premise.md`)。
+ *  **回収されない経路が 2 系統ある (受容する)**:
+ *   (1) **未追跡の新規ファイル**。`git diff` は未追跡ファイルを列挙しない。これは作った道具に依らず、
+ *       `Write` で作った新規ファイルも `git add` されるまで同じである
+ *       = **照合条件に `Bash` を足しても塞がらない** (穴は matcher の選択と直交する)
+ *   (2) **差分基準から外れた過去のコミットの変更**。コミットしたあと `Write` / `Edit` を挟まずに
+ *       さらにコミットを重ねると `HEAD~1` からの差分に現れない
+ *  どちらも配線層では塞げない (`--base` を変える経路も `git add` を起こす経路も配線には無い)。
+ *  **無条件の「回収される」とは書かない**。
+ *  **本テストはこの前提を機械検証しない** (差分の基準・除外規則・索引状態の更新はツール側の実装)。
+ *  したがって**索引ツールを更新したら、matcher の意味論と併せてこの差分回収の前提も
+ *  人手で再確認する** (確認項目は上記の実読記録の 5 点)。
+ *  **撤回規則**: (a) **上の 2 系統以外**で索引へ入らない実測が出た、(b) 索引ツールの版を上げて
+ *  差分基準や未追跡ファイルの扱いが変わった、(c) 上の 2 系統が**実害**として観測された
+ *  (索引が古いままコード探索が誤った結果を返した) — このいずれかが起きたら、
+ *  **`matcher` へ `Bash` を足すのではなく**、家系の未決論点へ差し戻す
+ *  (`Bash` の hook 入力には編集対象のパスが無く対象外拡張子での早期打ち切りが原理的に効かないため、
+ *  最頻ツールの呼び出しごとに索引更新の実プロセスが起きる = 正典が費用構造で外している)。
+ *  差し戻す先は「セッション開始時に索引状態を出す任意の配線」と「配線の非同期実行」の 2 案である。
+ * -------------------------------------------------------------------------
+ *
  * 本テストは DB を触らない (ファイル読み取りと別プロセス起動のみ)。
  * 関数名を `claudeHooks` 接頭辞で始めるのは、Pest が全テストを 1 プロセスへ読み込むため
  * 素の名前が他の Architecture テストと衝突するからである。
@@ -31,7 +72,11 @@
  * `matcher` の `Write|Edit` は **`Write` と `Edit` のときだけ**発火する。
  * 部分一致で将来の派生ツールを自動で拾うとは書かない (書くと嘘になる)。
  *
- * @var array<string, list<array{matcher: string, script: string, timeout: int, deny_exit_code: int|null}>>
+ * **拒否コードは台帳に持たない** (家系の正典 t3 の i7)。起動子は終了コードを写像しないので、
+ * hook が返した値がそのまま harness へ届く — `PreToolUse` の **2 だけがブロック**で、
+ * それ以外の非 0 はブロックしない異常として面に出る。
+ *
+ * @var array<string, list<array{matcher: string, script: string, timeout: int}>>
  */
 const CLAUDE_HOOKS_WIRING = [
     'PreToolUse' => [
@@ -39,7 +84,6 @@
             'matcher' => 'Bash',
             'script' => 'scripts/bughunt-worktree-hook.sh',
             'timeout' => 10,
-            'deny_exit_code' => 97,
         ],
     ],
     'PostToolUse' => [
@@ -47,11 +91,43 @@
             'matcher' => 'Write|Edit',
             'script' => 'scripts/code-review-graph-update-hook.sh',
             'timeout' => 30,
-            'deny_exit_code' => null,
         ],
     ],
 ];
 
+/** bug-hunt ガードが拒否を表す終了コード (harness の唯一の拒否信号)。 */
+const CLAUDE_HOOKS_DENY_EXIT_CODE = 2;
+
+/**
+ * 内側の上限の申告 (家系の正典 t3 の i8)。値そのものはスクリプト本文から取り出すので
+ * **ここには書かない** — 書くのは「どの数値を持つ契約か」だけである
+ * (数値を 2 か所に書くと必ず食い違う)。
+ *
+ * `body` / `kill` が false なのは、そのスクリプトが外部プロセスを 1 つも起こさないため
+ * (bug-hunt ガードの判定は bash の組み込みだけで完結する)。
+ *
+ * @var array<string, array{stdin: bool, body: bool, kill: bool}>
+ */
+const CLAUDE_HOOKS_INNER_LIMIT_SHAPE = [
+    'scripts/bughunt-worktree-hook.sh' => ['stdin' => true, 'body' => false, 'kill' => false],
+    'scripts/code-review-graph-update-hook.sh' => ['stdin' => true, 'body' => true, 'kill' => true],
+];
+
+/**
+ * ローカル層 (`.claude/settings.local.json`) のトップレベルに置いてよい項目 (全数申告制)。
+ *
+ * **現在は空である** = ローカル層はどのトップレベル項目も持てない。常設配線をローカルから
+ * 無効化する経路を作らないためで、hook を止める個別の設定項目 (`disableAllHooks` 等) を
+ * 名指しで並べる形は採らない — 全数申告は**未知の項目も拒む**ので上位互換であり、
+ * 正本を持たない外部の設定スキーマへ追随し続ける負債を作らない (家系の正典 t3 の i10)。
+ *
+ * 置きたい項目が出たら**ここを同じ変更で更新する**。ただし `hooks` は申告に足せない
+ * (S07c が固定する)。
+ *
+ * @var list<string>
+ */
+const CLAUDE_HOOKS_LOCAL_TOP_LEVEL_KEYS = [];
+
 /**
  * 索引の対象外拡張子。`scripts/code-review-graph-update-hook.sh` の `SKIP_EXTENSIONS` と
  * 完全一致すること (索引ツールを更新したらここも棚卸しする)。
@@ -121,26 +197,346 @@ function claudeHooksSettings(): array
 }
 
 /**
- * 起動子の文字列を台帳側で組み立てる (設定を書き換えたら必ずここと食い違って落ちる)。
+ * 起動子の正準形を台帳側で組み立てる (設定を書き換えたら必ずここと食い違って落ちる)。
+ *
+ * 起動子の仕事は**スクリプトを起こすこと 1 つだけ**である (家系の正典 t3 の i5 / i6 / i7):
+ *  - `/bin/bash` の絶対パス (起動子自身が検索パスで解決される形を禁じる)
+ *  - `-p` (特権モード。継承したシェル関数と `BASH_ENV` / `ENV` を無効化する)
+ *  - `$CLAUDE_PROJECT_DIR` を根にした絶対パスでスクリプトを直に起動する
+ * 引数・条件分岐・終了コードの写像・インラインのシェル片は 1 つも持たない。
+ */
+function claudeHooksExpectedCommand(string $script): string
+{
+    return '/bin/bash -p "$CLAUDE_PROJECT_DIR/'.$script.'"';
+}
+
+/**
+ * 起動子の形の違反を列挙する (純関数。走査器)。
+ *
+ * **走査対象**: 設定ファイルから取り出した起動コマンド文字列 1 本と、台帳側が組み立てた文字列 1 本。
+ * **判定**: 半角空白 1 文字を区切りとしてトークンへ割り、**トークンの完全一致**で見る
+ * (部分文字列一致や正規表現の語境界に頼らない = AGENTS.md 静的検査の共通規約 (e))。
+ * 期待するトークンは 3 個ちょうどで、順に `/bin/bash` / `-p` / `"$CLAUDE_PROJECT_DIR/<台帳のスクリプト>"`。
+ *
+ * **保証しないもの / fail-closed の倒し方**:
+ *  - 区切りは**半角空白 1 文字だけ**である。タブ・改行・連続空白・引用符の内側の空白を含む形は
+ *    「トークンへ割れない形」として**違反にする** (合格側へ倒さない)。したがって本走査は
+ *    「引用の解釈が要る書き方」を許可しない = shell parser を持たない代わりに母集団を狭める
+ *  - 起動先スクリプトの**中身**は見ない (隣接 feature の領分)。見るのは配線の文字列だけである
+ *
+ * @return list<string>
+ */
+function claudeHooksLauncherFormViolations(string $command, string $script): array
+{
+    $violations = [];
+
+    // 解釈できない空白 (タブ・改行・連続空白) は割る前に落とす
+    if (preg_match('/[\t\r\n]/', $command) === 1 || str_contains($command, '  ')) {
+        $violations[] = "起動子をトークンへ割れない (タブ・改行・連続空白を含む): {$command}";
+
+        return $violations;
+    }
+
+    $tokens = explode(' ', $command);
+    $expected = ['/bin/bash', '-p', '"$CLAUDE_PROJECT_DIR/'.$script.'"'];
+
+    if ($tokens !== $expected) {
+        $violations[] = sprintf(
+            '起動子が正準形でない (期待 %s / 実際 %s)',
+            implode(' ', $expected),
+            $command,
+        );
+    }
+
+    // 「起動子が余計な仕事を持たない」ことを、正準形の一致とは**独立に**トークン語彙で見る。
+    // 判定は別の純関数に置く (この分岐の検出力を単独で裏取りできるようにするため)。
+    foreach (claudeHooksLauncherForbiddenTokens($command) as $forbidden) {
+        $violations[] = "起動子が起動以外の仕事を持っている (禁止トークン {$forbidden}): {$command}";
+    }
+
+    return $violations;
+}
+
+/**
+ * 起動子に現れてはならないトークンを列挙する (純関数。走査器)。
+ *
+ * **判定は半角空白で割ったトークンの完全一致**である (AGENTS.md 静的検査の共通規約 (e))。
+ * 部分文字列一致にすると `xif` / `!if` / `ifx` のような無関係な語まで拾い、
+ * 逆に許可語の除去を部分文字列で書くと本物の `if` まで消える。
+ *
+ * **区切りは半角空白 1 文字だけ**である。タブ・改行・連続空白を含む形は
+ * 呼び出し側 (`claudeHooksLauncherFormViolations()`) が先に違反として落とす。
+ *
+ * @return list<string> 見つかった禁止トークン (出現順)
+ */
+function claudeHooksLauncherForbiddenTokens(string $command): array
+{
+    $vocabulary = ['-c', '&&', '||', ';', 'if', 'then', 'fi', 'exit', '[', 'eval', 'env', 'sh'];
+
+    $found = [];
+    foreach (explode(' ', $command) as $token) {
+        if (in_array($token, $vocabulary, true)) {
+            $found[] = $token;
+        }
+    }
+
+    return $found;
+}
+
+/**
+ * ローカル層の設定の違反を列挙する (純関数。走査器)。
+ *
+ * **走査対象**: `.claude/settings.local.json` の生バイト列。
+ * **判定**: トップレベルの項目名の集合を申告と突き合わせる (値は見ない)。
+ *
+ * **fail-closed の 2 分類** (どちらも合格側へ倒さない):
+ *  - JSON の構文が壊れている (`JsonException`)
+ *  - 構文は正しいがトップレベルが JSON オブジェクトでない
+ *
+ * `json_decode(..., associative: true)` は使わない — 連想配列へ落とすと `{}` と `[]` が
+ * どちらも `[]` になり、「オブジェクトでない」を検出できなくなる。
+ *
+ * @return list<string>
+ */
+function claudeHooksLocalSettingsViolations(string $json): array
+{
+    try {
+        /** @var mixed $decoded */
+        $decoded = json_decode(json: $json, associative: false, flags: JSON_THROW_ON_ERROR);
+    } catch (JsonException $exception) {
+        return ['ローカル設定が JSON として壊れている: '.$exception->getMessage()];
+    }
+
+    if (! $decoded instanceof stdClass) {
+        return ['ローカル設定のトップレベルが JSON オブジェクトでない'];
+    }
+
+    $keys = array_keys(get_object_vars($decoded));
+    $violations = [];
+
+    // `hooks` は申告の中身に関わらず必ず違反 (常設配線をローカルから殺す経路そのもの)
+    if (in_array('hooks', $keys, true)) {
+        $violations[] = 'ローカル設定に hooks がある (常設配線をローカルから無効化する経路を作らない)';
+    }
+
+    foreach (array_values(array_diff($keys, CLAUDE_HOOKS_LOCAL_TOP_LEVEL_KEYS, ['hooks'])) as $unexpected) {
+        $violations[] = "ローカル設定に申告の無いトップレベル項目がある: {$unexpected}";
+    }
+
+    return $violations;
+}
+
+/**
+ * 起動先が自分で諦める内側の上限を、スクリプト本文から**数値で**取り出す (純関数。走査器)。
+ *
+ * **走査対象**: 台帳の 2 スクリプトの本文。
+ * **抽出する 3 値** (どれも**行全体の正準形**で当てる。行頭・行末を固定するのでコメント行
+ * (`#` で始まる行) は候補にならない):
+ *  - `stdin` … `IFS= read -r -N <bytes> -t <秒> input || true` の秒数 (標準入力を待つ上限)
+ *  - `body`  … `readonly INNER_TIMEOUT_SECONDS=<秒>` (更新本体の上限)
+ *  - `kill`  … `timeout -k <秒> "${INNER_TIMEOUT_SECONDS}" \` の猶予
+ *              (TERM で終わらない相手を KILL するまで)
+ *
+ * **fail-closed** (見逃す方向へ倒さない = AGENTS.md 静的検査の共通規約 (b)):
+ *  - 申告で必要な形は**ちょうど 1 件**であること。0 件 (数値以外・単位つき・変数展開・
+ *    コメントアウト) と 2 件以上 (重複・囮の実行行) はどちらも違反にする
+ *  - **候補走査 (`claudeHooksInnerLimitCandidateCounts()`) が申告対象と分類した行**のうち、
+ *    正準形でないものが 1 件でも現れたら違反にする (候補の語彙と、その語彙が拾わない書き方 =
+ *    絶対パス・別名・変数経由 は候補走査の docblock が正本)
+ *  - 抽出できた値は**正の整数**であること (`0` は `timeout` の意味論を壊すので拒否する)
+ *  - 台帳に無いスクリプトを渡されたら違反として返す (未知を黙って空で通さない)
+ *
+ * **保証しないもの**: 見るのは**行の形と数値だけ**であり、shell の制御フロー (その行が
+ * 実際に実行されるか・別の待ちが挟まっているか) は見ない。したがって
+ * 「実行時の上限を証明する」とは書けない — 主張できるのは
+ * 「**明示された 3 つの上限の宣言**が配線の時間切れより小さい」までである。
+ *
+ * @return array{limits: array{stdin: ?int, body: ?int, kill: ?int}, violations: list<string>}
+ */
+function claudeHooksInnerLimits(string $body, string $script): array
+{
+    if (! array_key_exists($script, CLAUDE_HOOKS_INNER_LIMIT_SHAPE)) {
+        return [
+            'limits' => ['stdin' => null, 'body' => null, 'kill' => null],
+            'violations' => ["{$script}: 内側の上限の申告が無い (台帳と申告を同じ変更で更新すること)"],
+        ];
+    }
+
+    /** @var array{stdin: bool, body: bool, kill: bool} $shape */
+    $shape = CLAUDE_HOOKS_INNER_LIMIT_SHAPE[$script];
+
+    // 行全体の正準形。`^` と `$` を複数行モードで固定するので `# read …` は当たらない。
+    // `kill` は**次の行の更新本体まで**含めて当てる (猶予が更新の起動に接続していることを見る)。
+    $patterns = [
+        'stdin' => '/^IFS= read -r -N \d+ -t (\d+) input \|\| true$/m',
+        'body' => '/^readonly INNER_TIMEOUT_SECONDS=(\d+)$/m',
+        // PHP の単一引用符では `\\\\` が正規表現の `\\` (= リテラルのバックスラッシュ 1 文字) になる
+        'kill' => '/^timeout -k (\d+) "\$\{INNER_TIMEOUT_SECONDS\}" \\\\\n +code-review-graph update /m',
+    ];
+
+    $limits = ['stdin' => null, 'body' => null, 'kill' => null];
+    $violations = [];
+    $candidates = claudeHooksInnerLimitCandidateCounts($body);
+
+    foreach ($patterns as $key => $pattern) {
+        $count = preg_match_all($pattern, $body, $matches);
+        Assert::integer($count, "{$script}: 内側の上限 [{$key}] の走査が失敗した");
+
+        // **候補母集団と正準形の一致数が同じであること**。これが無いと、正準形の行 1 本と
+        // 非正準の実行行 (別の変数で上限を渡す行など) が**併存**していても検出できない。
+        if ($candidates[$key] !== $count) {
+            $violations[] = "{$script}: 内側の上限 [{$key}] に正準形でない実行行がある"
+                ." (候補 {$candidates[$key]} 件 / 正準形 {$count} 件)";
+
+            continue;
+        }
+
+        if (! $shape[$key]) {
+            if ($count > 0) {
+                $violations[] = "{$script}: 申告に無い内側の上限 [{$key}] が {$count} 件現れた"
+                    .' (申告を同じ変更で更新すること)';
+            }
+
+            continue;
+        }
+        if ($count !== 1) {
+            $violations[] = "{$script}: 内側の上限 [{$key}] の宣言が 1 件でない (実測 {$count} 件)"
+                .' — 数値として取り出せない形・重複・候補語彙に一致する囮の行は違反である';
+
+            continue;
+        }
+
+        $value = (int) $matches[1][0];
+        if ($value <= 0) {
+            $violations[] = "{$script}: 内側の上限 [{$key}] が正の整数でない (実測 {$value})";
+
+            continue;
+        }
+
+        $limits[$key] = $value;
+    }
+
+    return ['limits' => $limits, 'violations' => $violations];
+}
+
+/**
+ * 内側の上限に関わる**候補行**の数を数える (純関数)。
+ *
+ * 正準形に一致する行だけを数えると「正準形 1 本 + 非正準の実行行」の併存を見逃す。
+ * そこで**コメント行を除いた実行行**のうち、関連する語彙を持つ行を候補として別に数え、
+ * 呼び出し側が「候補数 == 正準形の一致数」を要求する。
+ *
+ * **区切りの宣言**: 行は半角空白・タブで**トークン**へ割り、代入は最初の `=` で
+ * 左辺と右辺へ割る。判定はトークンの**完全一致**である
+ * (部分文字列一致に頼らない = AGENTS.md 静的検査の共通規約 (e))。候補の語彙は次の 3 つ:
+ *  - `stdin` … トークンに `read` と `-t` の両方がある行
+ *  - `body`  … 代入の左辺が `INNER_TIMEOUT_SECONDS` の行
+ *  - `kill`  … トークンに `timeout` と `-k` の両方がある行
+ *
+ * **保証しないもの (誇張しない)**: 検出できるのは**宣言した語彙にトークン完全一致する
+ * 非正準行の併存**だけである。同じ操作を別の書き方で行う行 — 絶対パス (`/usr/bin/timeout`)・
+ * 別名・変数経由 (`"${TIMEOUT_BIN}"`) — は**候補にならないので併存を検出しない**。
+ * 逆に `env timeout -k 2 …` は `timeout` と `-k` の両トークンを持つので**候補になる**
+ * (行の先頭語だけを見る判定ではない)。
+ * 語彙を増やして追いかけない (書き方の全数は列挙できない)。起動子の側で余計なトークンを
+ * 禁じているのと違い、スクリプト本文は隣接 feature の領分なので、ここは
+ * 「正準形の行が 1 本あること + 宣言した語彙の別行が無いこと」までを見る層である。
+ *
+ * @return array{stdin: int, body: int, kill: int}
+ */
+function claudeHooksInnerLimitCandidateCounts(string $body): array
+{
+    $counts = ['stdin' => 0, 'body' => 0, 'kill' => 0];
+
+    foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
+        $trimmed = trim($line);
+        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
+            continue; // コメント行と空行は実行行ではない
+        }
+
+        $tokens = preg_split('/[ \t]+/', $trimmed) ?: [];
+        if (in_array('read', $tokens, true) && in_array('-t', $tokens, true)) {
+            $counts['stdin']++;
+        }
+        if (in_array('timeout', $tokens, true) && in_array('-k', $tokens, true)) {
+            $counts['kill']++;
+        }
+        foreach ($tokens as $token) {
+            if (str_contains($token, '=') && explode('=', $token, 2)[0] === 'INNER_TIMEOUT_SECONDS') {
+                $counts['body']++;
+            }
+        }
+    }
+
+    return $counts;
+}
+
+/**
+ * 合成した hook 本文 (S13b / S13d 用)。**基準は実ファイルと同じ正準形**で、
+ * 各データセットは `str_replace()` で**1 か所だけ**変異させる
+ * (複数箇所が同時に壊れていると、狙った分岐を消しても別の理由で赤いままになる)。
+ *
+ * nowdoc (`<<<'BASH'`) を使うのでバックスラッシュはそのまま 1 文字として入る
+ * (二重引用符のエスケープの曖昧さを持ち込まない)。基準本文には**囮のコメント行**を
+ * 1 本入れてあり、コメントが候補にならないことが同時に固定される。
+ */
+function claudeHooksSyntheticUpdateHookBody(string $mutate = '', string $replacement = ''): string
+{
+    $body = <<<'BASH'
+        #!/usr/bin/env bash
+        # 囮: IFS= read -r -N 1048576 -t 5 input || true
+        IFS= read -r -N 1048576 -t 5 input || true
+        readonly INNER_TIMEOUT_SECONDS=20
+        timeout -k 2 "${INNER_TIMEOUT_SECONDS}" \
+            code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
+        BASH;
+
+    if ($mutate === '') {
+        return $body;
+    }
+
+    // **変異元は本文にちょうど 1 か所**であること。`str_replace()` は全出現を置換するので、
+    // 存在検査だけだと「1 か所だけ変異させる」が壊れる (基準本文には囮のコメント行があり、
+    // 実行行と同じ文字列を含む。stdin の変異元は先頭に改行を付けて一意にする)。
+    Assert::same(
+        substr_count($body, $mutate),
+        1,
+        "合成本文の変異元が 1 か所でない: {$mutate}",
+    );
+
+    return str_replace($mutate, $replacement, $body);
+}
+
+/**
+ * 内側の上限と配線の時間切れの**関係**を判定する (純関数)。
+ *
+ * S13 (実ファイル) と S13c (変異させた入力) の**両方がこの関数を呼ぶ**。
+ * 比較を検査の中に直接書くと、比較を消しても変異テストが緑のままになる。
+ *
+ * 判定するのは「**明示された 3 上限の宣言の和** < 配線の時間切れ」であり、
+ * 前処理・プロセス起動の時間は含まない (含められないので主張もしない)。
  *
- * 起動子が持つ 3 つの役割:
- *  1. 起動先の検証 (絶対パス / `..` を含まない / `scripts` が symlink でない実ディレクトリ /
- *     起動先が symlink でない通常ファイル)。1 つでも欠ければ内側を起動しない
- *  2. 終了コードの写像 (PreToolUse は 97 だけを 2 へ写す。それ以外はすべて 0)
- *  3. 環境からのシェル関数の遮断 (`-p` = privileged mode)
+ * @param  array{stdin: ?int, body: ?int, kill: ?int}  $limits
+ * @return list<string>
  */
-function claudeHooksExpectedCommand(string $script, ?int $denyExitCode): string
+function claudeHooksInnerLimitRelationViolations(array $limits, int $harness, string $label): array
 {
-    $conditions = '[ -n "$d" ] && [ "${d#/}" != "$d" ] && [ "${d//../}" = "$d" ]'
-        .' && [ -d "$d/scripts" ] && [ ! -L "$d/scripts" ] && [ -f "$f" ] && [ ! -L "$f" ]';
+    $declared = array_filter($limits, static fn (?int $value): bool => $value !== null);
+    if ($declared === []) {
+        return ["{$label}: 内側の上限が 1 つも取れていない (関係を判定できない)"];
+    }
 
-    $inner = 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/'.$script.'; ';
-    $inner .= $denyExitCode === null
-        ? 'if '.$conditions.'; then /bin/bash -p "$f"; fi; exit 0'
-        : 's=0; if '.$conditions.'; then /bin/bash -p "$f"; s=$?; fi; '
-            .'if [ "$s" = '.$denyExitCode.' ]; then exit 2; fi; exit 0';
+    $sum = array_sum($declared);
+    if ($sum >= $harness) {
+        return [sprintf(
+            '%s: 明示された内側の上限の和 %d 秒が配線の時間切れ %d 秒より内側でない',
+            $label,
+            $sum,
+            $harness,
+        )];
+    }
 
-    return "/bin/bash -p -c '".$inner."'";
+    return [];
 }
 
 /**
@@ -439,19 +835,36 @@ function claudeHooksLauncherCommand(string $event): string
     return $command;
 }
 
+/** 設定ファイルから hook の時間切れを取り出す。 */
+function claudeHooksHookTimeout(string $event): int
+{
+    $settings = claudeHooksSettings();
+    Assert::isArray($settings['hooks']);
+    Assert::keyExists($settings['hooks'], $event);
+    $group = $settings['hooks'][$event];
+    Assert::isArray($group);
+    Assert::isArray($group[0]);
+    Assert::isArray($group[0]['hooks']);
+    Assert::isArray($group[0]['hooks'][0]);
+    $timeout = $group[0]['hooks'][0]['timeout'];
+    Assert::integer($timeout);
+
+    return $timeout;
+}
+
 /**
  * 起動子そのものを走らせる。`CLAUDE_PROJECT_DIR` を渡さないときは環境から消える。
  *
  * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
  */
-function claudeHooksRunLauncher(string $command, ?string $projectDir, ?string $cwd = null): array
+function claudeHooksRunLauncher(string $command, ?string $projectDir, ?string $cwd = null, string $input = ''): array
 {
     $env = ['/usr/bin/env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin'];
     if ($projectDir !== null) {
         $env[] = 'CLAUDE_PROJECT_DIR='.$projectDir;
     }
 
-    return claudeHooksRun([...$env, '/bin/bash', '-c', $command], '', $cwd);
+    return claudeHooksRun([...$env, '/bin/bash', '-c', $command], $input, $cwd);
 }
 
 /** 起動子の内側に置く「終了コードだけを返す」スクリプト。 */
@@ -519,61 +932,97 @@ function claudeHooksWriteExitStub(string $path, int $exitCode): void
             expect($hook['type'])->toBe('command');
             expect($hook['timeout'])->toBe($entry['timeout']);
             expect($hook['command'])->toBe(
-                claudeHooksExpectedCommand($entry['script'], $entry['deny_exit_code']),
+                claudeHooksExpectedCommand($entry['script']),
                 "{$event} の起動文字列が台帳と 1 文字でも違う",
             );
         }
     }
 });
 
-test('S06b: 起動子が privileged mode / 起動先検証 / 終了コード写像の 3 役をすべて持つこと', function (): void {
-    // claudeHooksExpectedCommand() は台帳側の組み立てなので、そこが緩んでも S05 は緑のままになる。
-    // 「何が書かれていなければならないか」を独立に固定する。
+test('S06b: 起動子が直呼び + privileged mode で、起動以外の仕事を 1 つも持たないこと', function (): void {
+    // 設定の実文字列と台帳側の組み立ての**両方**を同じ述語に通す
+    // (片方だけだと「台帳を緩めた」か「設定を緩めた」かのどちらかを取り逃がす)。
+    $checked = 0;
+
     foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
         foreach ($entries as $entry) {
-            $command = claudeHooksExpectedCommand($entry['script'], $entry['deny_exit_code']);
-
-            expect($command)->toStartWith("/bin/bash -p -c '", "{$event}: 起動子が絶対パス + privileged mode でない");
-            claudeHooksExpectContains($command, '/bin/bash -p "$f"', "{$event}: 内側の起動が privileged mode でない");
-
             foreach ([
-                '[ -n "$d" ]',                    // 未設定を弾く
-                '[ "${d#/}" != "$d" ]',           // 絶対パスであること
-                '[ "${d//../}" = "$d" ]',         // `..` を含まないこと
-                '[ -d "$d/scripts" ]',            // scripts が実ディレクトリ
-                '[ ! -L "$d/scripts" ]',          // scripts が symlink でない
-                '[ -f "$f" ]',                    // 起動先が通常ファイル
-                '[ ! -L "$f" ]',                  // 起動先が symlink でない
-            ] as $condition) {
-                claudeHooksExpectContains($command, $condition, "{$event}: 起動先の検証が無い");
-            }
-
-            if ($entry['deny_exit_code'] === null) {
-                claudeHooksExpectNotContains($command, 'exit 2', "{$event}: ブロックしない hook が 2 を返しうる");
-            } else {
-                claudeHooksExpectContains(
-                    $command,
-                    'if [ "$s" = '.$entry['deny_exit_code'].' ]; then exit 2; fi',
-                    "{$event}: 拒否コードの写像が無い",
-                );
+                '設定ファイル' => claudeHooksLauncherCommand($event),
+                '台帳の組み立て' => claudeHooksExpectedCommand($entry['script']),
+            ] as $source => $command) {
+                $violations = claudeHooksLauncherFormViolations($command, $entry['script']);
+                expect($violations)->toBe([], "{$event} ({$source}):\n".implode("\n", $violations));
             }
-            expect($command)->toEndWith("exit 0'", "{$event}: 既定で 0 に畳んでいない");
+            $checked++;
         }
     }
+
+    // 母集団が空でないこと (走査根の改名・台帳の空振りで緑にならないように)
+    expect($checked)->toBe(2, '必須 2 配線を検査していない (i2)');
+});
+
+test('S06c (負のコントロール): 起動子の形の走査が違反を実際に検出すること', function (string $command): void {
+    expect(claudeHooksLauncherFormViolations($command, 'scripts/x.sh'))->not->toBe([]);
+})->with([
+    'インライン形 (-c)' => ['/bin/bash -p -c \'d=${CLAUDE_PROJECT_DIR:-}; exit 0\''],
+    '追加引数' => ['/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh" --verbose'],
+    '条件分岐' => ['/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh"; if [ "$s" = 97 ]; then exit 2; fi'],
+    'インラインのシェル片' => ['/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh" && printf done'],
+    '起動子が検索パス解決' => ['bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh"'],
+    '特権モードが無い' => ['/bin/bash "$CLAUDE_PROJECT_DIR/scripts/x.sh"'],
+    '相対パス' => ['/bin/bash -p "scripts/x.sh"'],
+    'タブ区切り (解釈できない形)' => ["/bin/bash -p\t\"\$CLAUDE_PROJECT_DIR/scripts/x.sh\""],
+]);
+
+test('S06d (正のコントロール): 正典どおりの形は違反ゼロであること', function (): void {
+    expect(claudeHooksLauncherFormViolations('/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh"', 'scripts/x.sh'))
+        ->toBe([]);
+    expect(claudeHooksLauncherForbiddenTokens('/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh"'))->toBe([]);
+});
+
+test('S06e (語彙判定の裏取り): 禁止トークンの検出が単独で効いていること', function (): void {
+    // S06c の負例はすべて「正準形でない」だけでも赤になるので、語彙判定の分岐は
+    // **単独で**裏取りする (この検査があるので `claudeHooksLauncherForbiddenTokens()` を
+    // 空実装にすると赤になる)。
+    expect(claudeHooksLauncherForbiddenTokens('/bin/bash -p -c \'exit 0\''))->toBe(['-c']);
+    expect(claudeHooksLauncherForbiddenTokens('/bin/bash -p "$d/x.sh" ; if [ 1 ] ; then exit 2 ; fi'))
+        ->toBe([';', 'if', '[', ';', 'then', 'exit', ';', 'fi']);
+
+    // 区切りで割ったトークンの完全一致であること (接頭辞・打ち消し・接尾辞の 3 形は拾わない)
+    foreach (['xif', '!if', 'ifx', 'exits', '-cx', 'ifexit'] as $lookalike) {
+        expect(claudeHooksLauncherForbiddenTokens('/bin/bash -p "$d/x.sh" '.$lookalike))
+            ->toBe([], "トークン完全一致でない判定になっている: {$lookalike}");
+    }
 });
 
-test('S07: .claude/settings.local.json は hooks キーを持てないこと (常設配線をローカルから殺さない)', function (): void {
+test('S07: .claude/settings.local.json のトップレベルが全数申告どおりであること (i10)', function (): void {
     $path = base_path('.claude/settings.local.json');
+
     if (! is_file($path)) {
-        expect(true)->toBeTrue('ローカル設定は無い (常設配線を上書きする経路も無い)');
+        // ファイルが無い = 上書きする経路が無い。**空振りではない**ことを明示する
+        // (「存在するときは全キーを照合する」ことは S07b が合成入力で固定する)
+        expect(CLAUDE_HOOKS_LOCAL_TOP_LEVEL_KEYS)->toBe([], 'ローカル層に置ける項目の申告が空でない');
 
         return;
     }
 
-    $decoded = json_decode(claudeHooksReadFile($path), true);
-    Assert::isArray($decoded);
-    expect(array_key_exists('hooks', $decoded))
-        ->toBeFalse('.claude/settings.local.json に hooks を置かないこと (常設配線をローカルから殺す経路になる)');
+    $violations = claudeHooksLocalSettingsViolations(claudeHooksReadFile($path));
+    expect($violations)->toBe([], implode("\n", $violations));
+});
+
+test('S07b (負のコントロール): ローカル層の走査が違反を実際に検出すること', function (string $json): void {
+    expect(claudeHooksLocalSettingsViolations($json))->not->toBe([]);
+})->with([
+    'hooks を持つ' => ['{"hooks": {}}'],
+    '申告に無い項目を持つ' => ['{"permissions": {"allow": []}}'],
+    'トップレベルがオブジェクトでない' => ['[]'],
+    'JSON の構文が壊れている' => ['{'],
+]);
+
+test('S07c: 空のオブジェクトは合格し、申告に hooks を足せないこと', function (): void {
+    expect(claudeHooksLocalSettingsViolations('{}'))->toBe([]);
+    expect(in_array('hooks', CLAUDE_HOOKS_LOCAL_TOP_LEVEL_KEYS, true))
+        ->toBeFalse('申告に hooks を足してはならない (i10)');
 });
 
 test('S08: 見本ファイル方式が復活していないこと', function (): void {
@@ -687,6 +1136,178 @@ function claudeHooksWriteExitStub(string $path, int $exitCode): void
     }
 });
 
+test('S13: 明示された内側の上限の和が配線の時間切れより小さいこと (数値を両方から取って比較する)', function (): void {
+    // 申告の母集団が台帳とちょうど一致すること (申告の余剰・不足を黙って通さない)
+    $ledgerScripts = [];
+    foreach (CLAUDE_HOOKS_WIRING as $entries) {
+        foreach ($entries as $entry) {
+            $ledgerScripts[] = $entry['script'];
+        }
+    }
+    sort($ledgerScripts);
+    $declaredScripts = array_keys(CLAUDE_HOOKS_INNER_LIMIT_SHAPE);
+    sort($declaredScripts);
+    expect($declaredScripts)->toBe($ledgerScripts, '内側の上限の申告が台帳のスクリプト集合と一致しない');
+
+    $checked = 0;
+
+    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
+        foreach ($entries as $entry) {
+            $extracted = claudeHooksInnerLimits(claudeHooksReadFile(base_path($entry['script'])), $entry['script']);
+            expect($extracted['violations'])->toBe([], implode("\n", $extracted['violations']));
+
+            // 設定ファイル側の timeout も**設定から**取る (台帳の写しではなく実値を見る)
+            $harness = claudeHooksHookTimeout($event);
+            expect($harness)->toBe($entry['timeout'], "{$event}: 設定の timeout が台帳と違う");
+
+            // 関係の判定は純関数へ (S13c が同じ関数を呼ぶ = **共通関数の中の**比較を
+            // 消したり向きを逆にしたら負例が赤くなる)
+            $violations = claudeHooksInnerLimitRelationViolations($extracted['limits'], $harness, $event);
+            expect($violations)->toBe([], implode("\n", $violations));
+            $checked++;
+        }
+    }
+
+    expect($checked)->toBe(2, '必須 2 配線を検査していない (i2)');
+});
+
+test('S13b (負のコントロール): 内側の上限の走査が違反を実際に検出すること', function (string $body, string $script): void {
+    // **基準の合成本文から 1 か所だけ変異させる** (複数箇所が同時に壊れていると、
+    // 狙った分岐を消しても別の理由で赤いままになり、分岐の裏取りにならない)。
+    $extracted = claudeHooksInnerLimits($body, $script);
+    expect($extracted['violations'])->not->toBe([]);
+})->with([
+    '必要な正準形が 0 件 (変数展開)' => [
+        claudeHooksSyntheticUpdateHookBody(
+            'readonly INNER_TIMEOUT_SECONDS=20',
+            'readonly INNER_TIMEOUT_SECONDS=$FOO',
+        ),
+        'scripts/code-review-graph-update-hook.sh',
+    ],
+    '必要な正準形が 2 件 (重複宣言)' => [
+        claudeHooksSyntheticUpdateHookBody(
+            'readonly INNER_TIMEOUT_SECONDS=20',
+            "readonly INNER_TIMEOUT_SECONDS=20\nreadonly INNER_TIMEOUT_SECONDS=99",
+        ),
+        'scripts/code-review-graph-update-hook.sh',
+    ],
+    '正準形と非正準の実行行が併存する' => [
+        claudeHooksSyntheticUpdateHookBody(
+            'code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1',
+            "code-review-graph update -q --repo \"\${repo_root}\" > /dev/null 2>&1\n"
+                .'timeout -k "${OTHER}" 99 code-review-graph update -q',
+        ),
+        'scripts/code-review-graph-update-hook.sh',
+    ],
+    '標準入力待ちが数値でない' => [
+        claudeHooksSyntheticUpdateHookBody(
+            // 先頭の改行で**実行行だけ**に一意化する (囮のコメント行は `# ` が前に付くので当たらない)
+            "\nIFS= read -r -N 1048576 -t 5 input || true",
+            "\nIFS= read -r -N 1048576 -t \"\${UNBOUNDED}\" input || true",
+        ),
+        'scripts/code-review-graph-update-hook.sh',
+    ],
+    '値が 0 (timeout の意味論が壊れる)' => [
+        claudeHooksSyntheticUpdateHookBody('timeout -k 2 ', 'timeout -k 0 '),
+        'scripts/code-review-graph-update-hook.sh',
+    ],
+    '猶予が更新本体へ接続していない' => [
+        claudeHooksSyntheticUpdateHookBody(
+            'code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1',
+            '    true',
+        ),
+        'scripts/code-review-graph-update-hook.sh',
+    ],
+    '申告に無い上限が現れた (検問側に本体の宣言がある)' => [
+        claudeHooksSyntheticUpdateHookBody(),
+        'scripts/bughunt-worktree-hook.sh',
+    ],
+    '台帳に無いスクリプト' => [
+        claudeHooksSyntheticUpdateHookBody(),
+        'scripts/unknown-hook.sh',
+    ],
+]);
+
+test('S13c (負のコントロール): 関係の判定が崩れた数値を落とすこと', function (?int $stdin, ?int $body, ?int $kill, int $harness, bool $shouldFail): void {
+    // **S13 と同じ関数**を呼ぶので、**共通関数の中の**比較を消したり向きを逆にしたらここが赤くなる
+    // (S13 から呼び出しごと削除された場合はここでは分からない — それは S13 の本文を読むレビューの担当)。
+    // dataset を `?int` の 3 引数に分けるのは、closure の `array` に要素型を書けないためである
+    // (PHPStan level 10 は iterable value type の欠落を落とす)。
+    $violations = claudeHooksInnerLimitRelationViolations(
+        ['stdin' => $stdin, 'body' => $body, 'kill' => $kill],
+        $harness,
+        'テスト入力',
+    );
+
+    expect($violations === [])->toBe(! $shouldFail);
+})->with([
+    '索引更新の現行値 (27 < 30)' => [5, 20, 2, 30, false],
+    '等しい (30 は内側でない)' => [5, 20, 5, 30, true],
+    '超える (32 > 30)' => [5, 25, 2, 30, true],
+    '検問の現行値 (5 < 10)' => [5, null, null, 10, false],
+    '1 つも取れていない' => [null, null, null, 30, true],
+]);
+
+test('S13d (正のコントロール): 実ファイルと合成の基準本文から 3 値がちょうど取れること', function (): void {
+    // 実ファイル
+    $real = claudeHooksInnerLimits(
+        claudeHooksReadFile(base_path('scripts/code-review-graph-update-hook.sh')),
+        'scripts/code-review-graph-update-hook.sh',
+    );
+    expect($real['violations'])->toBe([], implode("\n", $real['violations']));
+    expect($real['limits'])->toBe(['stdin' => 5, 'body' => 20, 'kill' => 2]);
+
+    // 合成の基準本文 (変異していない = 違反ゼロ)。囮のコメント行があっても件数は増えない
+    $synthetic = claudeHooksInnerLimits(
+        claudeHooksSyntheticUpdateHookBody(),
+        'scripts/code-review-graph-update-hook.sh',
+    );
+    expect($synthetic['violations'])->toBe([], implode("\n", $synthetic['violations']));
+    expect($synthetic['limits'])->toBe(['stdin' => 5, 'body' => 20, 'kill' => 2]);
+
+    // 検問側 (本体と猶予を持たない申告)
+    $guard = claudeHooksInnerLimits(
+        claudeHooksReadFile(base_path('scripts/bughunt-worktree-hook.sh')),
+        'scripts/bughunt-worktree-hook.sh',
+    );
+    expect($guard['violations'])->toBe([], implode("\n", $guard['violations']));
+    expect($guard['limits'])->toBe(['stdin' => 5, 'body' => null, 'kill' => null]);
+});
+
+test('S13e (候補計数の裏取り): 候補の語彙が区切りトークンの完全一致で判定されること', function (): void {
+    // 候補計数だけを直接検査する (S13b は「併存を検出できる」ことしか示さないので、
+    // **誤検出しない側**をここで固定する = AGENTS.md 静的検査の共通規約 (e) の 3 形)。
+    // 正例
+    expect(claudeHooksInnerLimitCandidateCounts('IFS= read -r -N 10 -t 5 input || true'))
+        ->toBe(['stdin' => 1, 'body' => 0, 'kill' => 0]);
+    expect(claudeHooksInnerLimitCandidateCounts('readonly INNER_TIMEOUT_SECONDS=20'))
+        ->toBe(['stdin' => 0, 'body' => 1, 'kill' => 0]);
+    expect(claudeHooksInnerLimitCandidateCounts('timeout -k 2 "${X}" \\'))
+        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 1]);
+
+    // 宣言した区切り: タブでも割れる
+    expect(claudeHooksInnerLimitCandidateCounts("timeout\t-k\t2"))
+        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 1]);
+
+    // 行の先頭語だけを見る判定ではない (トークンのどこにあっても候補になる)
+    expect(claudeHooksInnerLimitCandidateCounts('env timeout -k 2 "${X}"'))
+        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 1]);
+
+    // コメント行と空行は実行行ではない
+    expect(claudeHooksInnerLimitCandidateCounts("# timeout -k 2\n\n   # readonly INNER_TIMEOUT_SECONDS=20"))
+        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 0]);
+
+    // 負例: 接頭辞つき・打ち消しつき・接尾辞つきは候補にしない
+    foreach ([
+        'xtimeout -k 2', '!timeout -k 2', 'timeoutx -k 2',
+        'xread -r -t 5', '!read -r -t 5', 'readx -r -t 5',
+        'XINNER_TIMEOUT_SECONDS=20', '!INNER_TIMEOUT_SECONDS=20', 'INNER_TIMEOUT_SECONDSX=20',
+    ] as $lookalike) {
+        expect(claudeHooksInnerLimitCandidateCounts($lookalike))
+            ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 0], "トークン完全一致でない判定になっている: {$lookalike}");
+    }
+});
+
 // =============================================================================
 // 実起動層: 索引更新 hook (B01〜B25)
 // =============================================================================
@@ -941,7 +1562,10 @@ function claudeHooksWriteExitStub(string $path, int $exitCode): void
         $elapsed = microtime(true) - $startedAt;
 
         expect(claudeHooksInvocations($sandbox))->toBe(1, '排他が効いておらず更新が重複起動された');
-        expect($elapsed)->toBeLessThan(30.0, '呼び出し側 timeout (30 秒) を超えた');
+        expect($elapsed)->toBeLessThan(
+            (float) claudeHooksHookTimeout('PostToolUse'),
+            '呼び出し側 timeout を超えた',
+        );
     } finally {
         File::deleteDirectory($sandbox);
     }
@@ -958,8 +1582,21 @@ function claudeHooksWriteExitStub(string $path, int $exitCode): void
         expect($result['exitCode'])->toBe(0);
         expect($result['output'])->toBe('');
         expect(claudeHooksWarningLines($result['errorOutput']))->toBe(1);
-        expect($result['errorOutput'])->toContain('20 秒');
-        expect($result['elapsed'])->toBeLessThan(45.0, '内側の時間切れ (20 秒) が効いていない');
+
+        $inner = claudeHooksInnerLimits(
+            claudeHooksReadFile(base_path('scripts/code-review-graph-update-hook.sh')),
+            'scripts/code-review-graph-update-hook.sh',
+        )['limits']['body'];
+        Assert::integer($inner);
+        expect($result['errorOutput'])->toContain("{$inner} 秒");
+
+        // 実測の上限は**設定由来の値** (配線の時間切れ) を使う。根拠の無い余裕の数値を持ち込まない
+        // (この stub は 120 秒眠るので、内側の時間切れが効いていなければ必ず超える)。
+        // 数値の関係そのものは静的層 (S13) が見るので、ここは「内側が実際に発火する」ことだけを見る。
+        expect($result['elapsed'])->toBeLessThan(
+            (float) claudeHooksHookTimeout('PostToolUse'),
+            '内側の時間切れが効いていない (配線の時間切れまで走ってしまっている)',
+        );
     } finally {
         File::deleteDirectory($sandbox);
     }
@@ -1068,7 +1705,7 @@ function claudeHooksWriteExitStub(string $path, int $exitCode): void
 
         expect($result['exitCode'])->toBe($expected, "コマンド [{$command}] の判定が違う");
         expect($result['output'])->toBe('', '標準出力は常に空でなければならない');
-        if ($expected === 97) {
+        if ($expected === CLAUDE_HOOKS_DENY_EXIT_CODE) {
             expect($result['errorOutput'])->toContain('bug-hunt provision');
         } else {
             expect($result['errorOutput'])->toBe('');
@@ -1078,12 +1715,12 @@ function claudeHooksWriteExitStub(string $path, int $exitCode): void
     }
 })->with([
     'B26 無関係なコマンド' => ['ls -la', 0],
-    'B28 main からの直叩き' => ['scripts/bug-hunt-shard.sh provision --shard 1', 97],
+    'B28 main からの直叩き' => ['scripts/bug-hunt-shard.sh provision --shard 1', CLAUDE_HOOKS_DENY_EXIT_CODE],
     'B30 worktree から' => ['cd .claude/worktrees/tasks/x && scripts/bug-hunt-shard.sh provision', 0],
     'B31 明示解除' => ['BUGHUNT_ALLOW_MAIN=1 scripts/bug-hunt-shard.sh provision', 0],
     'B32 self-test dryrun' => ['BUGHUNT_SELFTEST_DRYRUN=1 scripts/bug-hunt-shard.sh provision', 0],
     'B40 間に別語が入る言及' => ['scripts/bug-hunt-shard.sh scaffold x provision', 0],
-    'B40b provision-all' => ['scripts/bug-hunt-shard.sh provision-all', 97],
+    'B40b provision-all' => ['scripts/bug-hunt-shard.sh provision-all', CLAUDE_HOOKS_DENY_EXIT_CODE],
 ]);
 
 test('B37: JSON が / を \\/ へ逃がしていても worktree の指紋を取りこぼさないこと', function (): void {
@@ -1138,7 +1775,7 @@ function claudeHooksWriteExitStub(string $path, int $exitCode): void
         $denied = claudeHooksRunBughuntHook(
             $sandbox, claudeHooksBashPayload('scripts/bug-hunt-shard.sh provision'), '', $sandbox.'/cwd',
         );
-        expect($denied['exitCode'])->toBe(97, '検索パスが壊れると拒否対象が黙って通っている');
+        expect($denied['exitCode'])->toBe(CLAUDE_HOOKS_DENY_EXIT_CODE, '検索パスが壊れると拒否対象が黙って通っている');
         expect($denied['errorOutput'])->toContain('bug-hunt provision');
 
         expect(glob($sandbox.'/FAKE-*') ?: [])->toBe([], '判定経路が外部コマンドに依存している');
@@ -1159,9 +1796,9 @@ function claudeHooksWriteExitStub(string $path, int $exitCode): void
         File::deleteDirectory($sandbox);
     }
 })->with([
-    'B34 解除なし' => ['{"tool_input": {"comm scripts/bug-hunt-shard.sh provision', 97],
+    'B34 解除なし' => ['{"tool_input": {"comm scripts/bug-hunt-shard.sh provision', CLAUDE_HOOKS_DENY_EXIT_CODE],
     'B35 明示解除あり' => ['{"tool_input": {"comm BUGHUNT_ALLOW_MAIN=1 scripts/bug-hunt-shard.sh provision', 0],
-    'B36 痕跡だけ' => ['{"tool_input": {"comm .claude/worktrees/ scripts/bug-hunt-shard.sh provision', 97],
+    'B36 痕跡だけ' => ['{"tool_input": {"comm .claude/worktrees/ scripts/bug-hunt-shard.sh provision', CLAUDE_HOOKS_DENY_EXIT_CODE],
 ]);
 
 test('B38: 標準入力が空でも閉じない相手でも 0 で終えること', function (): void {
@@ -1203,79 +1840,128 @@ function claudeHooksWriteExitStub(string $path, int $exitCode): void
 });
 
 // =============================================================================
-// 実起動層: 起動子 (B41〜B51)
+// 実起動層: 起動子 (B41〜B46)
 // =============================================================================
 
-test('B41〜B49: PreToolUse の起動子が 97 だけを 2 へ写し、それ以外を 0 に畳むこと', function (string $case, int $expected): void {
+test('B41: PreToolUse の起動子が内側の終了コードをそのまま返すこと', function (int $inner): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksWriteExitStub($sandbox.'/scripts/bughunt-worktree-hook.sh', $inner);
+        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PreToolUse'), $sandbox);
+
+        expect($result['exitCode'])->toBe($inner, "内側が {$inner} なのに起動子が畳んでいる");
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+})->with([
+    '通過 (0)' => [0],
+    'ブロックしない異常 (1)' => [1],
+    '拒否 (2)' => [2],
+    'ブロックしない異常 (3)' => [3],
+    '旧拒否コード (97) が特別扱いされないこと' => [97],
+    '実行不能 (127)' => [127],
+]);
+
+test('B42: PostToolUse の起動子も内側の終了コードをそのまま返すこと', function (int $inner): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksWriteExitStub($sandbox.'/scripts/code-review-graph-update-hook.sh', $inner);
+        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PostToolUse'), $sandbox);
+
+        expect($result['exitCode'])->toBe($inner);
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+})->with([[0], [1], [2], [3], [97], [127]]);   // **1 つも畳まない**契約なので 2 と 127 も落とさない
+
+test('B43: 標準入力が起動子を通って内側へそのまま届くこと', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        // 内側で標準入力を読んで書き出すスクリプト (payload が欠けたら中身が空になる)
+        File::put($sandbox.'/scripts/bughunt-worktree-hook.sh', <<<BASH
+        #!/bin/bash
+        IFS= read -r -N 1048576 -t 5 payload || true
+        printf '%s' "\${payload}" > '{$sandbox}/received.txt'
+        exit 0
+        BASH);
+        chmod($sandbox.'/scripts/bughunt-worktree-hook.sh', 0700);
+
+        $payload = claudeHooksBashPayload('ls -la');
+        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PreToolUse'), $sandbox, input: $payload);
+
+        expect($result['exitCode'])->toBe(0);
+        expect(claudeHooksReadFile($sandbox.'/received.txt'))->toBe($payload, '標準入力が内側へ届いていない');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B44: 起動先が無い / 起動元の位置が未設定なら 127 で終わり、ブロックにならないこと', function (string $case): void {
     $sandbox = claudeHooksSandbox();
-    $command = claudeHooksLauncherCommand('PreToolUse');
-    $script = $sandbox.'/scripts/bughunt-worktree-hook.sh';
     $projectDir = $sandbox;
-    $cwd = null;
 
     try {
         match ($case) {
-            'B41 拒否 (97)' => claudeHooksWriteExitStub($script, 97),
-            'B42 通過 (0)' => claudeHooksWriteExitStub($script, 0),
-            'B43 構文エラー (2)' => claudeHooksWriteExitStub($script, 2),
-            'B44 起動先が無い' => File::delete($script),
-            'B45 CLAUDE_PROJECT_DIR が無い' => (function () use ($script, &$projectDir): void {
-                claudeHooksWriteExitStub($script, 97);
+            '起動先が無い' => File::delete($sandbox.'/scripts/bughunt-worktree-hook.sh'),
+            'CLAUDE_PROJECT_DIR が無い' => (function () use (&$projectDir): void {
+                // 前提を明示する: 未設定だとパスが `/scripts/…` に潰れるので、
+                // ホスト側にその実体が無いことを確かめてから 127 を期待する
+                expect(is_file('/scripts/bughunt-worktree-hook.sh'))
+                    ->toBeFalse('ホストに /scripts/bughunt-worktree-hook.sh が実在するため本ケースの前提が崩れている');
                 $projectDir = null;
             })(),
-            'B46 相対値' => (function () use ($script, $sandbox, &$projectDir, &$cwd): void {
-                claudeHooksWriteExitStub($script, 97);
-                $projectDir = basename($sandbox);
-                $cwd = dirname($sandbox);
-            })(),
-            'B47 .. を含む' => (function () use ($script, $sandbox, &$projectDir): void {
-                claudeHooksWriteExitStub($script, 97);
-                $projectDir = dirname($sandbox).'/../'.basename(dirname($sandbox)).'/'.basename($sandbox);
-            })(),
-            'B48 scripts が symlink' => (function () use ($script, $sandbox): void {
-                claudeHooksWriteExitStub($script, 97);
-                rename($sandbox.'/scripts', $sandbox.'/real-scripts');
-                symlink($sandbox.'/real-scripts', $sandbox.'/scripts');
-            })(),
-            'B49 起動先が symlink' => (function () use ($script, $sandbox): void {
-                claudeHooksWriteExitStub($sandbox.'/scripts/real-hook.sh', 97);
-                File::delete($script);
-                symlink($sandbox.'/scripts/real-hook.sh', $script);
-            })(),
         };
 
-        $result = claudeHooksRunLauncher($command, $projectDir, $cwd);
+        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PreToolUse'), $projectDir);
 
-        expect($result['exitCode'])->toBe($expected, "{$case}: 起動子の写像が違う");
+        // 127 = bash がスクリプトを開けない。**2 ではない**ので Bash ツールはブロックされない
+        expect($result['exitCode'])->toBe(127, "{$case}: 起動できなかったのに 127 で終わっていない");
+        expect($result['exitCode'])->not->toBe(CLAUDE_HOOKS_DENY_EXIT_CODE);
     } finally {
         File::deleteDirectory($sandbox);
     }
-})->with([
-    'B41 拒否 (97)' => ['B41 拒否 (97)', 2],
-    'B42 通過 (0)' => ['B42 通過 (0)', 0],
-    'B43 構文エラー (2)' => ['B43 構文エラー (2)', 0],
-    'B44 起動先が無い' => ['B44 起動先が無い', 0],
-    'B45 CLAUDE_PROJECT_DIR が無い' => ['B45 CLAUDE_PROJECT_DIR が無い', 0],
-    'B46 相対値' => ['B46 相対値', 0],
-    'B47 .. を含む' => ['B47 .. を含む', 0],
-    'B48 scripts が symlink' => ['B48 scripts が symlink', 0],
-    'B49 起動先が symlink' => ['B49 起動先が symlink', 0],
-]);
+})->with(['起動先が無い', 'CLAUDE_PROJECT_DIR が無い']);
 
-test('B50: PostToolUse の起動子は内側の終了コードにかかわらず常に 0 を返すこと', function (int $inner): void {
+test('B45 (i14 の非保証の実証): 起動子は起動先も起動元の位置も検証しないこと', function (string $case): void {
+    // 旧実装 (写像器) が持っていた 7 条件の検証は正典 t3 で撤去した。
+    // **ここで実証するのは「明示的に非保証にした 4 形」だけ**であり、非保証の全体を
+    // 網羅するものではない (全体の正本は冒頭 docblock の i14 の 3 点である)。
     $sandbox = claudeHooksSandbox();
+    $projectDir = $sandbox;
+    $cwd = null;
 
     try {
-        claudeHooksWriteExitStub($sandbox.'/scripts/code-review-graph-update-hook.sh', $inner);
-        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PostToolUse'), $sandbox);
+        claudeHooksWriteExitStub($sandbox.'/scripts/bughunt-worktree-hook.sh', 0);
+
+        match ($case) {
+            '起動元の位置が相対値' => (function () use ($sandbox, &$projectDir, &$cwd): void {
+                $projectDir = basename($sandbox);
+                $cwd = dirname($sandbox);
+            })(),
+            '起動元の位置が .. を含む' => $projectDir = dirname($sandbox).'/../'.basename(dirname($sandbox)).'/'.basename($sandbox),
+            '起動先が symlink' => (function () use ($sandbox): void {
+                claudeHooksWriteExitStub($sandbox.'/scripts/real-hook.sh', 0);
+                File::delete($sandbox.'/scripts/bughunt-worktree-hook.sh');
+                symlink($sandbox.'/scripts/real-hook.sh', $sandbox.'/scripts/bughunt-worktree-hook.sh');
+            })(),
+            'scripts が symlink' => (function () use ($sandbox): void {
+                rename($sandbox.'/scripts', $sandbox.'/real-scripts');
+                symlink($sandbox.'/real-scripts', $sandbox.'/scripts');
+            })(),
+        };
+
+        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PreToolUse'), $projectDir, $cwd);
 
-        expect($result['exitCode'])->toBe(0, "内側が {$inner} のとき起動子が 0 を返していない");
+        expect($result['exitCode'])->toBe(0, "{$case}: 起動子が内側を起こしていない (正典 t3 では検証しないのが正)");
     } finally {
         File::deleteDirectory($sandbox);
     }
-})->with([[0], [1], [2], [97], [127]]);
+})->with(['起動元の位置が相対値', '起動元の位置が .. を含む', '起動先が symlink', 'scripts が symlink']);
 
-test('B51: 起動子が環境からのシェル関数を内側へ継承させないこと (privileged mode)', function (): void {
+test('B46: 起動子が環境からのシェル関数を内側へ継承させないこと (privileged mode)', function (): void {
     $sandbox = claudeHooksSandbox();
     $command = claudeHooksLauncherCommand('PreToolUse');
 
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index 4082e384..f7c18ea4 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -19,7 +19,7 @@ final class LedgerPins
     private function __construct() {}
 
     /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
-    public const int DIVERGENCE_ENTRY_COUNT = 48;
+    public const int DIVERGENCE_ENTRY_COUNT = 49;
 
     /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
     public const int FINGERPRINT_POPULATION_COUNT = 281;
@@ -31,7 +31,7 @@ private function __construct() {}
      *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
      *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
      */
-    public const int ADOPTION_DEBT_COUNT = 147;
+    public const int ADOPTION_DEBT_COUNT = 146;
 
     /**
      * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
diff --git a/tests/Support/TemplateDivergence/adoption-debt.tsv b/tests/Support/TemplateDivergence/adoption-debt.tsv
index 757cad32..8402b722 100644
--- a/tests/Support/TemplateDivergence/adoption-debt.tsv
+++ b/tests/Support/TemplateDivergence/adoption-debt.tsv
@@ -77,7 +77,6 @@ tests/Architecture/BillingRetentionTargetInventoryTest.php	338da106bfe063adb4f23
 tests/Architecture/BugHuntSkillInvariantTest.php	7ac57d13113b5bb97c6aa252d30f825f8438f3c275281fedabc5e8fd41a837b4
 tests/Architecture/BughuntOrchestratorGateInvariantTest.php	d6c12c7a5faba29643a98f3b8bcabb31b10d957ea59845c4d6b34f0dfa2cc299
 tests/Architecture/CarbonOverflowArithmeticGateTest.php	30dbbf0af932e1aba992d7ba61379bdc002d30da68f3f23a0fae1f0200e1d9d1
-tests/Architecture/ClaudeHooksWiringTest.php	04c6385e626e87c1c073dc4efcd3e93c9fda2b95034792ee0e8a30d861e2a9ce
 tests/Architecture/DefensiveInstructionsPresenceTest.php	10ee98844f033287a78052d8b31b79c29de0014f0f94324da2f3904068847be0
 tests/Architecture/DocumentTitleCoverageTest.php	1da53f28a7df69f0c53cb42df3b3b203bde65848ccac5386096c42310a10e1f4
 tests/Architecture/ForbiddenStatementTokenInvariantTest.php	4c45743b98209f0e8a105c0e2f360495db20579fa6141e91d43e2f2e44cd9d4f

```
