【アプリの使命 (North Star)】
<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (ただし phpstan.neon の paths は app/config/database/routes であり tests/ は解析対象外)
- Pest テストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC

【本件の性質】
- 変更するのは tests/Architecture/EnvExampleInvariantTest.php ただ 1 本。アプリ実行コード・見本 env ファイル・config は 1 行も変えない。
- 家系（複数リポジトリ）の機能台帳 lctl の feature `gate-env-example-sync` について、裁定 AG-007 が定めた統合形（値の固定 × キーの網羅 + 行の形式 + 重複 + 台帳の誠実性）へ追従する作業。
- 参考として「正典側の実装（laravel-claude-template の同名テスト全文）」と「本リポジトリの現行実装（t0 の形）」を末尾に添付する。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）— とくに正規表現・解析器の境界条件
2. 既存コードとの整合性（命名規約、パターン）
3. PHPStan level 10 適合性（将来 tests を解析対象へ入れても通るか）
4. テスト計画の網羅性（fail-first が実質的に成立しているか。反証表に穴はないか）
5. 副作用・後退リスク（現行 t0 の 5 本の検査能力が本当に吸収されているか）
6. セキュリティ（保証範囲の誇張が無いか。偽グリーンを新たに作っていないか）
7. 受け入れ条件が本当に機械検証可能か（コマンドが実際に判定になるか）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: 環境見本ファイルの検査を AG-007 の統合形 (台帳の版の呼び名は t1) へ追従させる (aicue:T213)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

加えて設計フロー側の禁止: **既存テストの削除・上書き** / **やたらに複雑な案を提案する**。

本 TODO はアプリの実行コードを 1 行も触らないため 3〜8 は非該当。
1 (テストなしの完了報告) と「既存テストの削除・上書き」が本設計の主な制約である。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。ただし `phpstan.neon` の paths は
  app / config / database / routes であり **`tests/` は解析対象外**。
  本設計は将来 tests を解析対象へ入れても通る書き方 (戻り値 shape の phpdoc・
  `array_map` + closure を避けて `foreach` で組む) を採る。
- **Pest** テストフレームワーク (`composer test`)。
- **RefreshDatabase** はグローバル適用済み。本設計の検査は DB を使わない。
- **コードフォーマット**: `vendor/bin/pint --test` (Laravel preset。`pint.json` は無い)。
- PHP 8.4 + Laravel 12。

## 概念設計リファレンス

`devnotes/20260817-1309-todo-t213-env-example-gate-t1/conceptual-design.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 解析器の分離 (純粋関数 + ファイル読み取りのアダプタ) と反証の検査 | `tests/Architecture/EnvExampleInvariantTest.php` | High |
| 2 | 値の固定の台帳化 (行の完全一致) | 同上 | High |
| 3 | キー網羅の台帳 (4 分類 36 件) | 同上 | High |
| 4 | 行の形式の検査 / 重複の検査 | 同上 | High |
| 5 | 台帳の誠実性の検査 | 同上 | High |
| 6 | 既存の t0 の検査 5 本の置換と、`${VAR}` 検査の温存 | 同上 | High |

**施策 1〜6 は同一ファイルの 1 回の書き換えで入る。分割してコミットしない**
(途中の状態で「値の固定が消えているのに新しい台帳がまだ無い」時間帯を作らないため)。

## 変更ファイル一覧

### 新規

なし。

### 変更

| ファイル | 変更内容 |
|---|---|
| `tests/Architecture/EnvExampleInvariantTest.php` | 全面書き換え (107 行 → 概算 250 行前後) |

### 削除

なし。

### 変更しないと決めているファイル (受け入れ条件で機械確認する)

- `.env.example` / `.env.testing` / `.env.bughunt.local.example`
- `app/` 配下すべて (`app/Support/ProductionEnvGuard.php` を含む)
- `config/` 配下すべて
- `docs/` 配下 (本 TODO は AGENTS.md / docs の記述を増やさない。理由は下記)

> **docs を触らない理由**: 本 TODO が足すのは既存の不変条件テストの強度であり、
> 新しい規約・新しい運用契約を作らない。AGENTS.md にも `docs/architecture.md` にも
> 「見本ファイルの検査」の節は現在無く、節を新設すると **同じ事実 (台帳の中身) が
> テストと文書の 2 か所に載る**。台帳の正本はテストファイルの定数だけにする。

## 施策 1: 解析器の分離と反証の検査

### 変更箇所

`tests/Architecture/EnvExampleInvariantTest.php` (全体)

### 波及変更

- TypeScript 型定義: なし
- API Resource / DTO: なし
- テストファイル: 本ファイルのみ (他のテストからこのファイルの関数・定数を参照している箇所は無い)
- 実行コード: なし

### 現行コード (抜粋)

```php
test('.env.example に SESSION_SECURE_COOKIE=true が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('SESSION_SECURE_COOKIE=true');
});
```

### 変更後コード

```php
/**
 * 見本ファイルの本文を行単位で解析する (**純粋関数**。ファイルを読まない)。
 *
 * 行の分類:
 *   - 空白だけの行     → 実効値に影響しないので飛ばす
 *   - `^\s*#` の行     → コメント。同上
 *   - それ以外         → 素の代入行 `^[A-Z][A-Z0-9_]*=` **のみ**受理する
 *
 * ★これは dotenv の構文検査ではない。dotenv は `export FOO=1` も小文字のキーも読むが、
 *   本リポジトリの見本ファイルではそれらを許さない (存在検査・重複検査の母集合から
 *   外れたまま実効値だけを変えられる迂回になるため)。「見本に許す最小の書式」である。
 *
 * ★重複キーの値は **最初に現れた方**を記録する。dotenv は同一ファイル内の重複を
 *   **後に現れた方**で解決する (実測: vlucas/phpdotenv の immutable / mutable の双方で
 *   後勝ち)。両者は食い違うので、重複が 1 件でもあると値の固定の検査は
 *   「実効値ではない値」を見ることになる。だから重複そのものを違反にする
 *   (どちらの解決順に合わせるかを選ばない)。
 *
 * 改行は CRLF / CR / LF のいずれでも行に割る (行末に CR を残さない)。
 * 値は前後の空白を落とさない (見本に書いてあるとおりを返す)。
 *
 * @return array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * }
 */
function envExampleParseContents(string $contents): array
{
    $lines = preg_split('/\r\n|\r|\n/', $contents);
    expect($lines)->toBeArray();
    /** @var list<string> $lines */
    $values = [];
    $duplicateKeys = [];
    $malformedLineNumbers = [];

    foreach ($lines as $index => $line) {
        if (trim($line) === '') {
            continue;
        }
        if (preg_match('/^\s*#/', $line) === 1) {
            continue;
        }
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
            $malformedLineNumbers[] = $index + 1;

            continue;
        }
        $key = $matches[1];
        if (array_key_exists($key, $values)) {
            // 同じキーが 3 回以上でも、重複の一覧にはキー名を 1 度だけ載せる (診断の安定)。
            if (! in_array($key, $duplicateKeys, true)) {
                $duplicateKeys[] = $key;
            }

            continue;
        }
        $values[$key] = $matches[2];
    }

    return [
        'values' => $values,
        'duplicateKeys' => $duplicateKeys,
        'malformedLineNumbers' => $malformedLineNumbers,
    ];
}

/**
 * `.env.example` を読んで解析する (**入出力のアダプタ**。判定は持たない)。
 *
 * @return array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * }
 */
function envExampleParse(): array
{
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */

    return envExampleParseContents($contents);
}
```

**分離する理由**: 見本ファイルは現に適合しているため、台帳駆動の 4 本は書いた瞬間に緑になる。
それでは「壊れたら赤くなる」ことを誰も確かめていない。純粋関数にしておけば、
**壊した入力を合成して食わせる反証の検査を恒久で置ける** (見本ファイルを実際に壊さずに済む)。
これが本設計のテストファーストの土台である。

### 反証の検査 (データ駆動)

`Pest` の `->with()` で以下の表を回す。各行は「合成した本文」→「期待する解析結果」。

| # | 合成した本文 | 期待 | 何を固定するか |
|---|---|---|---|
| R1 | `# SESSION_SECURE_COOKIE=true` | `values` が空 | **コメント偽装**。t0 の部分一致はこれを通していた |
| R2 | `   # コメント` (先頭に空白) | `values` が空・形式違反 0 件 | 字下げしたコメントを違反にしない |
| R3 | `A=1` + 空行 + `B=2` | `values` = A,B / 形式違反 0 件 | 正常系の下限 |
| R4 | `A=1\nA=2` | `duplicateKeys` = `['A']`、`values['A']` = `'1'` | **重複の検出**と、解析器が**先勝ち**で記録すること |
| R5 | `A=1\nA=2\nA=3` | `duplicateKeys` = `['A']` (1 件) | 3 回以上でも一覧は 1 件 |
| R6 | `A=1\nB=2\nA=3\nB=4` | `duplicateKeys` = `['A','B']` | 複数キーの重複を取りこぼさない |
| R7 | `export A=1` | 形式違反 1 行目 | `export` つきの迂回 |
| R8 | `  A=1` (先頭に空白) | 形式違反 1 行目 | 字下げした代入の迂回 |
| R9 | `a=1` (小文字のキー) | 形式違反 1 行目 | 小文字のキーの迂回 |
| R10 | `A =1` / `A= 1` | どちらも形式違反 | 等号の前後の空白 |
| R11 | `--- 区切り ---` | 形式違反 1 行目 | 素の区切り線 |
| R12 | `1A=1` (数字始まりのキー) | 形式違反 1 行目 | キーの先頭は英大文字だけ |
| R13 | `A=1\r\nB=2` (CRLF) | `values['A']` = `'1'` (CR を含まない) | 行末の CR を残さない |
| R14 | `A= 1 ` (値の前後に空白) | `values['A']` = `' 1 '` | 値を trim しない (見本のとおりを返す) |
| R15 | `A=1\nexport B=2\nc=3` | 形式違反が 2 行目と 3 行目 | **行番号が 1 始まりで正しい** |
| R16 | 空文字列 | すべて空 | 端 (空ファイル) で落ちない |

> Round 2 の助言にある 4 種は R1 (コメント偽装) / R4 (重複と解決順) / R7〜R12 (不正書式) が
> 受け持つ。「前勝ち / 後勝ち」は R4 で **解析器が先勝ち**であることを固定し、
> **dotenv が後勝ち**であることは docblock に実測として書く
> (テストで dotenv の挙動そのものを固定しにいかない — vendor の挙動は本 gate の責務ではない。
> 重複を 1 件も許さないので、どちらが勝つかに設計が依存しない)。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (array shape を phpdoc で固定)
- [x] null 安全 (`file_get_contents` の戻り値を `expect()->toBeString()` で確定させる。
      正典と同じ形)
- [x] DTO を返している — **非該当** (テストの補助関数であり、アプリの層をまたがない)
- [x] Generics の型パラメータ — `list<string>` / `list<int>` / `array<string, string>` を明示
- [x] `array_map` + closure を使わない (引数のネイティブ型が `array<mixed, mixed>` になり
      level 10 で落ちうるため。`foreach` で組む)

### テスト計画

- [x] 反証の検査 16 件を **先に書く** (関数が未定義なので必ず赤になる)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 反証の表が増えるとファイルが長くなる。ただしこれは検査の実体であり、
  「壊れたら赤くなる」ことを証明する唯一の手段なので削らない。

## 施策 2: 値の固定の台帳化

### 変更後コード

```php
/**
 * 値の固定: 裁定 AG-007 が名指しする 2 件。
 * 緩めるには家系の機能台帳側の裁定変更が要る (本リポジトリ単独では動かせない)。
 *
 * ★形式はキーと値の組の**リスト**にする (キー付きの連想配列にしない)。
 *   連想配列のリテラルは同じ定数の中の重複キーをコンパイル時に後勝ちで無音に潰すため、
 *   「行を足しただけに見える差分」で既存の固定を反転できてしまう。
 *   リストなら重複がそのまま残り、下の誠実性の検査が同じ機構で捕まえられる。
 */
const ENV_EXAMPLE_VALUE_PINS_AG007_CORE = [
    ['key' => 'SESSION_SECURE_COOKIE', 'value' => 'true'],
    ['key' => 'SESSION_ENCRYPT', 'value' => 'true'],
];

/**
 * 値の固定: 本リポジトリ固有の追加 (裁定で必須とされたものではない純増。個別に理由を書く)。
 * - ADMIN_MFA_REQUIRED=true: false にすると管理画面の二要素が実質無効になる。
 *   local の値が本番へ写る事故の側が危険なので、見本は安全側で固定する。
 * - MCP_STRICT_TRANSPORT=true: false にすると Origin を送らないクライアントを受け入れる
 *   (DNS 再バインドの面が広がる)。
 */
const ENV_EXAMPLE_VALUE_PINS_AICUE = [
    ['key' => 'ADMIN_MFA_REQUIRED', 'value' => 'true'],
    ['key' => 'MCP_STRICT_TRANSPORT', 'value' => 'true'],
];

/**
 * 値の固定の台帳の合成 (重複した組を保持したまま連結する)。
 *
 * @return list<array{key: string, value: string}>
 */
function envExampleValuePinEntries(): array
{
    return array_merge(ENV_EXAMPLE_VALUE_PINS_AG007_CORE, ENV_EXAMPLE_VALUE_PINS_AICUE);
}

test('a: .env.example は安全側の既定値を行の完全一致で満たす', function (): void {
    $parsed = envExampleParse();

    // 失敗時に出すのは**キー名だけ**である (見本の実値を出力しない)。
    $violations = [];
    foreach (envExampleValuePinEntries() as $entry) {
        if (($parsed['values'][$entry['key']] ?? null) !== $entry['value']) {
            $violations[] = $entry['key'];
        }
    }

    expect($violations)->toBe([]);
});
```

### 現状との突き合わせ (実測)

| キー | `.env.example` の実値 | 判定 |
|---|---|---|
| `SESSION_SECURE_COOKIE` | `true` (35 行目) | 緑 |
| `SESSION_ENCRYPT` | `true` (32 行目) | 緑 |
| `ADMIN_MFA_REQUIRED` | `true` (223 行目) | 緑 |
| `MCP_STRICT_TRANSPORT` | `true` (122 行目) | 緑 |

### リスク

- `MCP_STRICT_TRANSPORT` は MCP 面を廃止したら見本から消えうる。そのとき赤になるが、
  それは「台帳から外す」という判断がレビューに乗る形なので意図どおりである。

## 施策 3: キー網羅の台帳 (4 分類 36 件)

### 変更後コード

```php
/**
 * キー網羅の台帳。分類ごとに定数を分ける (平らな 1 本の配列にしない)。
 * 削るときに「どの根拠を外すのか」がレビューで見えるようにするためである。
 *
 * ★台帳は**床**であって天井ではない。`.env.example` に任意のキーを足すことは責務外で、
 *   完全一致の集合にはしない (正典と同じ判断)。
 *
 * (i) 新しい環境を立てるときに要る座標。`composer setup` と
 *     `scripts/setup-worktree.sh` の案内が `.env.example` をそのまま `.env` にするため、
 *     ここが欠けると「動かない .env」が出来上がる。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_SETUP = [
    'APP_NAME',
    'APP_ENV',
    'APP_KEY',
    'APP_URL',
    'APP_LOCALE',
    'DB_CONNECTION',
    'SESSION_DRIVER',
    'QUEUE_CONNECTION',
    'CACHE_STORE',
];

/**
 * (ii) 本番の起動時に検査される座標のうち、**現在 `.env.example` に素の代入行として
 *      提示済みのもの**。正本は app/Support/ProductionEnvGuard.php で、依存は一方向である
 *      (guard が変われば本台帳が古くなる。機械では結線しない — 理由は概念設計のスコープ外 9)。
 *
 * ★これは guard の要求の**写しではない**。guard は SECURITY_HSTS_ENABLED /
 *   SECURITY_CSP_ENABLED も本番で true と要求するが、この 2 つは `.env.example` に
 *   1 行も無く、載せるには見本の書き方の判断が要るため本台帳には入れない
 *   (概念設計のスコープ外 3。**この 2 件の欠落は検出しない**)。
 *
 * ★SESSION_SECURE_COOKIE / ADMIN_MFA_REQUIRED 等は値の固定の台帳が値ごと押さえるため
 *   ここには載せない (台帳をまたぐ二重登録は下の誠実性の検査が禁じる)。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD = [
    'CIPHERSWEET_KEY',
    'STRIPE_WEBHOOK_SECRET',
    'DEBUG_LOGIN_USER',
    'DEBUG_LOGIN_PASSWORD',
    'PRIMARY_HOST',
    'TRUSTED_HOSTS_ADDITIONAL',
    'TRUSTED_HOSTS_WILDCARD_SUFFIXES',
    'TRUSTED_PROXIES',
    'PASSKEYS_USER_HANDLE_SECRET',
];

/**
 * (iii) 提示が無いと環境ごとに別の名前が発明されて食い違う座標
 *       (外部との統合の秘密と、アプリ固有の座標)。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION = [
    'STRIPE_KEY',
    'STRIPE_SECRET',
    'OPENAI_API_KEY',
    'ANTHROPIC_API_KEY',
    'GEMINI_API_KEY',
    'GOOGLE_CLIENT_ID',
    'GOOGLE_CLIENT_SECRET',
    'RECAPTCHA_SITE_KEY',
    'RECAPTCHA_SECRET_KEY',
    'MCP_ALLOWED_ORIGINS',
    'PASSPORT_PRIVATE_KEY',
    'PASSPORT_PUBLIC_KEY',
    'TEMPLATE_APP_SLUG',
    'LEGAL_CONSENT_VERSION',
];

/**
 * (iv) 撮影テイクとレンダ成果物の保管先。本リポジトリ固有の分類である。
 *      撮影 PWA は presigned URL で直接アップロードし、合成した動画も同じ保管先へ置く。
 *      ここが欠けた環境では**撮った映像を保存できない** = 使命の中核が動かない。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE = [
    'AWS_ACCESS_KEY_ID',
    'AWS_SECRET_ACCESS_KEY',
    'AWS_DEFAULT_REGION',
    'AWS_BUCKET',
];

/**
 * キー網羅の台帳の合成 (4 分類の連結)。
 *
 * @return list<string>
 */
function envExampleRequiredKeys(): array
{
    return array_merge(
        ENV_EXAMPLE_REQUIRED_KEYS_SETUP,
        ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD,
        ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION,
        ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE,
    );
}

test('b: .env.example は必須キーの台帳を網羅する', function (): void {
    $parsed = envExampleParse();

    $missing = array_values(array_diff(envExampleRequiredKeys(), array_keys($parsed['values'])));

    expect($missing)->toBe([]);
});
```

### 現状との突き合わせ (実測)

`.env.example` の素の代入キーは **81 件**。上の 36 件はすべてその中にある
(本設計の作成時に、正典と同じ解析規則で数え上げて確認した)。
**台帳化で赤くなる既存キーは 1 件も無い。**

### リスク

- キーを消すたびに赤くなる = 摩擦が増える。これは意図した摩擦である
  (消してよいかの判断をレビューに乗せる)。
- 台帳が `ProductionEnvGuard` に自動追随しない。guard に新しい必須項目が増えたとき、
  本台帳は静かに古くなる。**これは保証しないものとして明記する**
  (機械で結ぶには config ファイルの構文解析が要り、今必要な範囲を超える)。

## 施策 4: 行の形式の検査 / 重複の検査

### 変更後コード

```php
test('c-1: .env.example の非空・非コメント行は素の代入行 (KEY=) だけである', function (): void {
    $parsed = envExampleParse();

    // `export` つき・先頭に空白がある代入・小文字のキーは、存在検査と重複検査の
    // 母集合から外れたまま実効値だけを変えられる迂回になるので、行の形ごと禁じる。
    // ★これは dotenv の構文検査ではない (dotenv はこれらを読む)。
    //   「本リポジトリの見本ファイルに許す最小の書式」である。
    expect($parsed['malformedLineNumbers'])->toBe([]);
});

test('c-2: .env.example の代入キーは一意である (重複で値の固定を無音で覆せなくする)', function (): void {
    $parsed = envExampleParse();

    expect($parsed['duplicateKeys'])->toBe([]);
});
```

### リスク

- 将来 `.env.example` に区切り線やアスキーアートを書きたくなると赤になる。
  `#` で始めれば通るので実害は小さい (現在の見本も分類の見出しをすべて `# ---` で書いている)。

## 施策 5: 台帳の誠実性の検査

### 変更後コード

```php
test('台帳の誠実性: 値の固定とキー網羅の二重登録・台帳の中の重複が無い', function (): void {
    // 値の固定は存在の検査を含むので、キー網羅への二重登録は台帳の腐敗になる
    // (どちらを緩めたのか追えなくなる)。機械的に禁じる。
    $required = envExampleRequiredKeys();

    $pinKeys = [];
    foreach (envExampleValuePinEntries() as $entry) {
        $pinKeys[] = $entry['key'];
    }

    // 組のリスト形式は重複を保持するので、この一意性の検査 1 本で
    // 台帳の中 (同じ定数の中) と台帳の間 (2 つの定数にまたがる重複) の両方を捕まえられる。
    expect(array_values(array_unique($pinKeys)))->toBe($pinKeys);
    expect(array_values(array_intersect($required, $pinKeys)))->toBe([]);
    expect(array_values(array_unique($required)))->toBe($required);
});
```

### 現状との突き合わせ

値の固定 4 件と必須キー 36 件に重複・交差は無い (本設計作成時に手で突き合わせて確認した)。

## 施策 6: 既存の t0 の検査の置換と `${VAR}` 検査の温存

### 置換の対応表 (検査能力が落ちないことの証明)

| 現行 (t0) の検査 | 置換後 | 強度 |
|---|---|---|
| `toContain('SESSION_SECURE_COOKIE=true')` | 値の固定台帳 (行の完全一致) | **強くなる** (コメント偽装・部分一致・重複を封鎖) |
| `toContain('SESSION_ENCRYPT=true')` | 同上 | **強くなる** |
| `toContain('TRUSTED_PROXIES=')` | キー網羅台帳 (ii) | **強くなる** (コメント行を通さない) |
| `toMatch('/^PASSKEYS_USER_HANDLE_SECRET=/m')` | キー網羅台帳 (ii) | **同等以上** (行頭一致に加え、行の形式と重複も見る) |
| `toContain('TEMPLATE_APP_SLUG=')` | キー網羅台帳 (iii) | **強くなる** |

**5 本すべてが同一以上の強度で吸収される。テストの削除ではなく台帳への置換である**
(禁止事項「既存テストの削除・上書き」に対する説明はこの表が正本)。

### `${VAR}` 検査は無改変で残す

```php
/** @var array<string, array<string, string>> */
const ENV_EXTERNAL_REF_ALLOWLIST = [ /* 現行のまま */ ];

function collectUnresolvedEnvRefs(string $relativePath): array { /* 現行のまま */ }

test('コミット対象 env ファイルに自己参照・前方参照の ${VAR} が無い', function (): void {
    /* 現行のまま。.env.example / .env.bughunt.local.example / .env.testing の 3 枚 */
});
```

**2 つの解析規則が同じファイルに同居することを、ファイル冒頭のコメントで明記する。**

| | 新しい解析器 (`envExampleParseContents`) | `${VAR}` 検査 (`collectUnresolvedEnvRefs`) |
|---|---|---|
| 対象 | `.env.example` の 1 枚だけ | 見本 3 枚 |
| `export` つきの行 | **違反にする** | 意図的に許容する |
| 先頭に空白のある代入 | **違反にする** | 意図的に許容する |
| 見るもの | キーと値・重複・行の形 | 値の中の `${VAR}` の解決可能性 |

受理の規則が逆向きなので **統合しない**。統合すると片方の意図が壊れる
(`.env.bughunt.local.example` 側で将来 `export` つきの行が混ざりうるという前提を、
`.env.example` の厳しさで潰してしまう)。緑のまま動いている検査を書き換える利得も無い。
`.env.example` については厳しい方 (行の形式の検査) が先に赤くなるので、
緩い側の許容は残り 2 枚にしか意味を持たない — この関係もコメントに書く。

### 名前の衝突 (プロセス大域)

Pest は全テストファイルを同じプロセスに読み込むため、file スコープの関数名・定数名は
リポジトリ全体で一意でなければならない (既に `LegalConsentVersionSingleSourceTest.php` が
`LEGAL_CONSENT_ENV_NAME` 等を file スコープに置いている)。本設計が新設する名前:

| 種別 | 名前 | 既存との衝突 |
|---|---|---|
| 定数 | `ENV_EXAMPLE_VALUE_PINS_AG007_CORE` | 無し |
| 定数 | `ENV_EXAMPLE_VALUE_PINS_AICUE` | 無し |
| 定数 | `ENV_EXAMPLE_REQUIRED_KEYS_SETUP` | 無し |
| 定数 | `ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD` | 無し |
| 定数 | `ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION` | 無し |
| 定数 | `ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE` | 無し |
| 関数 | `envExampleParseContents` | 無し |
| 関数 | `envExampleParse` | 無し |
| 関数 | `envExampleValuePinEntries` | 無し |
| 関数 | `envExampleRequiredKeys` | 無し |

実装時に `rg -n 'ENV_EXAMPLE_|function envExample' tests/` で 0 件を確認してから書き始める。

## テストファースト計画 (どのテストを先に赤にするか)

見本ファイルは既に適合しているため、**台帳駆動の 5 本は書いた瞬間に緑になる**。
これでは「fail を確認してから実装に入る」(思考原則 5) を満たさない。
そこで **2 段構え**にする。

### 段 1 (恒久・機械): 反証の検査を先に書く

1. `envExampleParseContents` を **まだ書かず**、施策 1 の反証の表 16 件だけを書く。
2. `composer test -- --filter=EnvExampleInvariant` を走らせ、
   **`Call to undefined function envExampleParseContents()` で 16 件が赤**であることを確認する。
   → 出力を `devnotes/20260817-1309-todo-t213-env-example-gate-t1/red-first-evidence.md` に貼る。
3. 解析器を実装して 16 件を緑にする。
4. 台帳の 5 本を書き足す (ここは最初から緑)。

### 段 2 (一度きり・手作業): 見本を壊して赤を実測する

段 1 は解析器の挙動を固定するが、「台帳駆動の 5 本が `.env.example` の破壊に反応すること」
までは示さない。実装セッション中に **`.env.example` を 6 通りに壊し、対応するテストが
実際に赤くなること**を実測して記録する (壊した内容は必ず元へ戻し、**コミットしない**)。

| # | 壊し方 | 赤くなるはずの検査 |
|---|---|---|
| B1 | `SESSION_SECURE_COOKIE=true` → `# SESSION_SECURE_COOKIE=true` | a (値の固定) |
| B2 | `SESSION_ENCRYPT=false` の行を末尾に足す | c-2 (重複) と a |
| B3 | `TRUSTED_PROXIES=` の行を消す | b (キー網羅) |
| B4 | `AWS_BUCKET=` を `export AWS_BUCKET=` に変える | c-1 (行の形式) と b |
| B5 | `MCP_STRICT_TRANSPORT=true` を `MCP_STRICT_TRANSPORT=false` に変える | a |
| B6 | 台帳側で `TRUSTED_PROXIES` を値の固定とキー網羅の両方に登録する | 台帳の誠実性 |

記録は `red-first-evidence.md` に「壊し方 → 赤くなった検査名 → 復元の確認 (`git diff --exit-code`)」の
表で残す。**B1 と B2 は現行の t0 の 5 本では緑のまま通る**ことも同じ表に併記する
(塞いだ穴の実体を示す)。

> B6 だけは台帳側を壊すので、`.env.example` には触らない。

## 受け入れ条件 (機械検証可能な形)

| # | 条件 | 検証コマンド |
|---|---|---|
| AC1 | 見本ファイル 3 枚が 1 バイトも変わっていない | `git diff --exit-code main -- .env.example .env.testing .env.bughunt.local.example` が 0 |
| AC2 | 変更ファイルが `tests/Architecture/EnvExampleInvariantTest.php` のちょうど 1 本 (devnotes は除く) | `git diff --name-only main -- . ':!devnotes'` の出力がその 1 行だけ |
| AC3 | 対象テストが全件緑 | `composer test -- --filter=EnvExampleInvariant` が exit 0 |
| AC4 | 対象テストの本数が 5 (台帳駆動) + 1 (`${VAR}`) + 16 (反証) = **22 件**である | 上記コマンドの件数表示 |
| AC5 | `red-first-evidence.md` に段 1 の赤の記録と段 2 の 6 行の表がある | ファイルの存在と行数 |
| AC6 | 新設した名前が 10 件ちょうどで、他ファイルと衝突していない | `rg -n 'ENV_EXAMPLE_\|function envExample' tests/` が当該ファイルだけを返す |
| AC7 | 全検証コマンドが green | 下記「全検証コマンド」 |

### 全検証コマンド (すべて green であること)

```bash
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

- `composer phpstan` は `tests/` を解析対象に含まないため本変更で結果は動かない。
  それでも **回して green を確認する** (回さないと「動かないはず」が確認されない)。
- フロントエンド側の 7 本は本変更と無関係だが、AGENTS.md の検証コマンド一覧が
  「全 green でコミット」と定めているため全部回す。

## 保証しないもの / やらないと決めたこと

| # | 保証しない / やらない | 理由 |
|---|---|---|
| N1 | 実行中の `.env`・プロセスの環境変数・設定キャッシュは見ない | 見るのは `.env.example` の中身だけ。見本の検査であって環境の検査ではない |
| N2 | `SECURITY_HSTS_ENABLED` / `SECURITY_CSP_ENABLED` の欠落を検出しない | 見本に 1 行も無く、載せ方 (値つきかコメントか) を決める別の判断が要る。本 TODO の分界は「検査だけを増やす」 |
| N3 | キー網羅は存在だけを見る (空の値も通る) | 「提示されている」ことの検査。正しい値が入っているかは各設定の検査と本番起動時の検査の担当 |
| N4 | 値の固定は台帳の 4 件だけ。他の値の変化には沈黙する | 固定を増やすほど見本の自由度が下がる。安全側に倒す価値がある値だけ選ぶ |
| N5 | キー網羅を完全一致の集合にしない (床であって天井ではない) | 任意のキーの追加を禁じる理由が無い (正典と同じ判断) |
| N6 | `.env.testing` / `.env.bughunt.local.example` に値の固定とキー網羅を広げない | テストレーンの正しい値は本番の安全側と逆向き (`SESSION_ENCRYPT=false` が正) |
| N7 | 行の形式の検査は dotenv の構文検査ではない | dotenv が読める書式のうち見本に許さないものを違反にするだけ。「通れば dotenv が読める」は保証しない |
| N8 | 制御文字・不可視文字を見ない | 正典の 4 部品に無い。必要になったら家系の議題として起こす |
| N9 | 禁止キーの台帳を持たない (偽の外部サービスのフラグが見本に載らないことの検査) | 正典の 4 部品ではない。3 つのフラグは現在見本に無く、仮に載っても本番起動時の検査が止めるので被害が閉じている |
| N10 | 台帳の件数を完全一致で固定しない | 台帳が検査と同じファイルのリテラル定数なので、削除は差分に直接現れる。件数を別に書くと同じ事実が 2 か所になる |
| N11 | `ProductionEnvGuard` と台帳を機械で結線しない | guard が読むのは config のキーであって環境変数名ではない。結ぶには config の構文解析が要る (思考原則 2) |
| N12 | `${VAR}` 検査と新しい解析器を統合しない | 受理の規則が逆向き。統合すると片方の意図が壊れる |
| N13 | AGENTS.md / `docs/` に節を新設しない | 台帳の正本をテストの定数 1 か所に置く。文書へ写すと必ず食い違う |
| N14 | 見本と config の既定値が一致していることは検査しない | これは「提示の検査」であって「同期の検査」ではない。名前 (`.env.example と config の同期強制`) に対して**実際に保証するのは提示まで**である |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 変更は `tests/Architecture/EnvExampleInvariantTest.php` の 1 ファイルに閉じ、他の施策と競合しない。アプリの実行コード・見本ファイル・設定に触らないため、並行して走る他の TODO の差分と重ならない |
| 競合リスク | 同ファイルを触る他の TODO が無いこと (現在の Open な TODO に env 見本を触るものは無い)。ただし `.env.example` にキーを足す TODO が並行すると、キー網羅の台帳ではなく `.env.example` 側が動くだけなので競合にはならない (台帳は床であり、追加は自由) |

## 実装後の申し送り (家系の台帳への報告に使う材料)

実装が終わったら家系の機能台帳へ実装の報告を出す。報告に必要な事実:

- 4 部品 (値の固定 4 / キー網羅 36 (4 分類) / 行の形式 / 重複) と台帳の誠実性の検査がそろったこと
- 見本ファイルとアプリの実行コードを 1 行も変えていないこと (増えたのは検査だけ)
- 本リポジトリだけが持つ `${VAR}` の検査を無改変で残したこと (還流候補として印が付いている)
- 正典に対する差分: 分類が 3 つではなく **4 つ**である (撮影・レンダ成果物の保管先を
  独立した分類として足した)。必須キーの集合はリポジトリごとに違うため、
  逐語の移植ではなく本リポジトリの座標から導出した等価の実装である
- 採らなかった純増 2 つ (禁止キーの台帳 / 台帳の件数の固定) と、その理由


---

## 関連する現行コード

### 変更対象の現行実装 (tests/Architecture/EnvExampleInvariantTest.php)

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
 * client IP の信頼境界 (T108 S5)。production で未宣言だと起動時 fail-fast するため、
 * .env.example に必ず提示して「設定し忘れてデプロイが落ちる」事故を減らす。
 */

test('.env.example に TRUSTED_PROXIES が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('TRUSTED_PROXIES=');
});

/*
 * パスキーの利用者ハンドル導出鍵。production で未宣言だと起動時 fail-fast するため
 * (App\Support\PasskeyConfigValidator)、.env.example に必ず提示して
 * 「設定し忘れてデプロイが落ちる」事故を減らす (TRUSTED_PROXIES と同じ理由)。
 */

test('.env.example に PASSKEYS_USER_HANDLE_SECRET が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    // **行頭一致**で見る (toContain だとコメント行 `# PASSKEYS_USER_HANDLE_SECRET=` でも通り、
    // 「宣言行として提示されている」ことを固定できないため)。
    expect($contents)->toMatch('/^PASSKEYS_USER_HANDLE_SECRET=/m');
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

/*
 * env ファイルの `${VAR}` nested variable は「同一ファイル内の先行定義 or 実行環境変数」しか
 * 解決できない (APP_ENV 別ロードでは他ファイルを継承しない)。自己参照 (VAR="${VAR}") や
 * 前方参照はリテラル文字列がそのまま画面に露出する事故になる (bug-hunt F-01 の実例:
 * .env.bughunt.local の APP_NAME="${APP_NAME}" が全画面のタイトル/ロゴ/フッターに露出)。
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
        // export プレフィックス付き定義も将来混在しうるため許容する
        if (preg_match('/^(?:export\s+)?([A-Z0-9_]+)=(.*)$/', $trimmed, $m) !== 1) {
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

### 正典側の実装 (laravel-claude-template の同名テスト。参考)

```php
<?php

declare(strict_types=1);

/*
 * .env.example の invariant gate (lctl feature gate-env-example-sync / 正典 t1)。
 *
 * t1 = 値 pin × キー網羅の和集合 (lctl 裁定 AG-007)。t0 (toContain による値 pin 4 本)
 * を台帳駆動へ置換したもので、t0 の検査能力は VALUE_PINS / REQUIRED_KEYS が
 * 同一以上の強度で吸収している (テスト削除ではなく台帳化置換)。
 *
 * .env.example は「生きた既定値」である: setup-worktree.sh が .env 不在時に
 * そのままコピーし、CI probe も .env として使う。欠けたキー・危険な値は
 * ドキュメントの欠落ではなく実環境の欠落になる。
 *
 * 4 部品:
 *   (a) VALUE_PINS   — 行完全一致の値 pin (コメント行・部分一致の偽グリーン封鎖)
 *   (b) REQUIRED_KEYS — 必須キーの存在 (キー網羅の床。値は見ない)
 *   (c-1) 行形式ガード — 非空・非コメント行は素の `KEY=` 形式のみ
 *         (export / 先頭空白付き代入による検査母集合からの迂回を封鎖)
 *   (c-2) 重複ガード — キーの全キー一意 (dotenv 後勝ちで pin を無音で覆す経路の封鎖)
 *
 * deny-by-default の射程は台帳 (a)(b) と構造 (c) であり、.env.example への
 * 任意キーの追加は責務外 (exact-set にしない判断は概念設計 3-4 R3)。
 * 失敗メッセージはキー名・行番号のみ (値を出さない)。
 */

/**
 * 値 pin: AG-007 core (裁定 decision が名指しする template の値 pin)。
 * 緩めるには lctl の裁定変更が要る。
 *
 * 形式は entry のリスト (キー付き連想配列にしない)。連想配列リテラルは同一 const 内の
 * 重複キーをコンパイル時に後勝ちで無音に潰すため、「追加に見える diff」で既存 pin を
 * 反転できてしまう。リスト形式は重複をそのまま保持するので、下の誠実性検査が
 * 台帳内・台帳間の重複を同一機構で捕捉できる (REQUIRED_KEYS のリスト形式と対称)。
 */
const ENV_EXAMPLE_VALUE_PINS_AG007_CORE = [
    ['key' => 'SESSION_SECURE_COOKIE', 'value' => 'true'],
    ['key' => 'SESSION_ENCRYPT', 'value' => 'true'],
];

/**
 * 値 pin: template 固有の追加 pin (裁定必須ではない純増。個別理由付き)。
 * - ADMIN_MFA_REQUIRED=true: false 化は管理画面 MFA の実質無効化
 *   (local の値が本番へコピーされる事故側が危険)
 * - MCP_STRICT_TRANSPORT=true: false 化は Origin 無しクライアントの受け入れ
 *   (DNS rebinding 面の拡大)
 */
const ENV_EXAMPLE_VALUE_PINS_TEMPLATE = [
    ['key' => 'ADMIN_MFA_REQUIRED', 'value' => 'true'],
    ['key' => 'MCP_STRICT_TRANSPORT', 'value' => 'true'],
];

/**
 * キー網羅台帳 (spirux のキー網羅 20 に対応する template 版 t1 等価実装)。
 * 分類ごとにグループを分けて保持する (削るときに「どの根拠を外すのか」が
 * レビュー可能な形。フラット 1 配列にしない)。
 *
 * (i) 初期セットアップ必須座標 (setup-worktree.sh の .env コピーで実効になる)
 */
const ENV_EXAMPLE_REQUIRED_KEYS_SETUP = [
    'APP_NAME',
    'APP_ENV',
    'APP_KEY',
    'APP_URL',
    'APP_LOCALE',
    'DB_CONNECTION',
    'SESSION_DRIVER',
    'QUEUE_CONNECTION',
    'CACHE_STORE',
];

/**
 * (ii) ProductionEnvGuard が本番で要求する座標 (app/Support/ProductionEnvGuard.php が
 * 正本。提示面に無いと「立て忘れ → 本番起動失敗」の発見が deploy 当日になる)。
 * SESSION_SECURE_COOKIE / ADMIN_MFA_REQUIRED 等は VALUE_PINS が値ごと押さえるため
 * ここには載せない (台帳間の二重登録は下の誠実性検査が禁止する)。
 * SECURITY_HSTS_ENABLED / SECURITY_CSP_ENABLED は「local の .env.example では
 * コメント提示が正」のため載せない (C16 の comment-ok 台帳が管轄)。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD = [
    'CIPHERSWEET_KEY',
    'STRIPE_WEBHOOK_SECRET',
    'DEBUG_LOGIN_USER',
    'DEBUG_LOGIN_PASSWORD',
    'PRIMARY_HOST',
    'TRUSTED_HOSTS_ADDITIONAL',
    'TRUSTED_HOSTS_WILDCARD_SUFFIXES',
];

/**
 * (iii) opt-in 統合の秘密・座標 (提示が無いと子アプリが独自名で env を発明して
 * ドリフトする)。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_OPT_IN = [
    'STRIPE_KEY',
    'STRIPE_SECRET',
    'OPENAI_API_KEY',
    'ANTHROPIC_API_KEY',
    'GEMINI_API_KEY',
    'GOOGLE_CLIENT_ID',
    'GOOGLE_CLIENT_SECRET',
    'RECAPTCHA_SITE_KEY',
    'RECAPTCHA_SECRET_KEY',
    'MCP_ALLOWED_ORIGINS',
    'PASSPORT_PRIVATE_KEY',
    'PASSPORT_PUBLIC_KEY',
    'PASSKEYS_USER_HANDLE_SECRET',
    'TEMPLATE_APP_SLUG',
    'LEGAL_CONSENT_VERSION',
];

/**
 * .env.example を行単位でパースする (本 gate 専用 helper。他ファイルと共用しない)。
 *
 * 行の分類: blank (空白のみ) / comment (`^\s*#`) は dotenv の実効値に影響しないため
 * 許容し、それ以外は素の代入行 `^[A-Z][A-Z0-9_]*=` のみを受理する。
 * CRLF は行分割で除去する (以降の判定はすべて行単位の文字列処理)。
 *
 * @return array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * }
 */
function envExampleParse(): array
{
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    $lines = preg_split('/\r\n|\r|\n/', $contents);
    expect($lines)->toBeArray();
    /** @var list<string> $lines */
    $values = [];
    $duplicateKeys = [];
    $malformedLineNumbers = [];

    foreach ($lines as $index => $line) {
        if (trim($line) === '') {
            continue;
        }
        if (preg_match('/^\s*#/', $line) === 1) {
            continue;
        }
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
            $malformedLineNumbers[] = $index + 1;

            continue;
        }
        $key = $matches[1];
        if (array_key_exists($key, $values)) {
            // 同一キー 3 回以上でも重複リストにはキー名を 1 回だけ載せる (診断の安定)。
            if (! in_array($key, $duplicateKeys, true)) {
                $duplicateKeys[] = $key;
            }

            continue;
        }
        $values[$key] = $matches[2];
    }

    return [
        'values' => $values,
        'duplicateKeys' => $duplicateKeys,
        'malformedLineNumbers' => $malformedLineNumbers,
    ];
}

/**
 * 値 pin 台帳の合成 (AG-007 core + template 固有)。重複 entry を保持したまま連結する。
 *
 * @return list<array{key: string, value: string}>
 */
function envExampleValuePinEntries(): array
{
    return array_merge(ENV_EXAMPLE_VALUE_PINS_AG007_CORE, ENV_EXAMPLE_VALUE_PINS_TEMPLATE);
}

/**
 * キー網羅台帳の合成 (3 分類の連結)。
 *
 * @return list<string>
 */
function envExampleRequiredKeys(): array
{
    return array_merge(
        ENV_EXAMPLE_REQUIRED_KEYS_SETUP,
        ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD,
        ENV_EXAMPLE_REQUIRED_KEYS_OPT_IN,
    );
}

test('c-1: .env.example の非空・非コメント行は素の代入行 (KEY=) のみである', function (): void {
    $parsed = envExampleParse();

    // export 形・先頭空白付き代入・小文字キーは、重複/存在検査の母集合から外れたまま
    // dotenv の実効値だけを変えられる迂回になるため、行形式ごと禁止する。
    expect($parsed['malformedLineNumbers'])->toBe([]);
});

test('c-2: .env.example の代入キーは一意である (dotenv 後勝ちによる pin 無音上書きの封鎖)', function (): void {
    $parsed = envExampleParse();

    expect($parsed['duplicateKeys'])->toBe([]);
});

test('a: .env.example は secure 既定の値 pin を行完全一致で満たす (t0 値 pin の強化置換)', function (): void {
    $parsed = envExampleParse();

    // キー名のみ列挙する (要求値は台帳定数が正本。ファイル側の実値は出力しない)。
    $violations = [];
    foreach (envExampleValuePinEntries() as $entry) {
        if (($parsed['values'][$entry['key']] ?? null) !== $entry['value']) {
            $violations[] = $entry['key'];
        }
    }

    expect($violations)->toBe([]);
});

test('b: .env.example は必須キー台帳を網羅する (t0 存在 pin の台帳化置換 + キー網羅の床)', function (): void {
    $parsed = envExampleParse();

    $missing = array_values(array_diff(envExampleRequiredKeys(), array_keys($parsed['values'])));

    expect($missing)->toBe([]);
});

test('台帳の誠実性: 値 pin とキー網羅の二重登録・台帳内重複が無い', function (): void {
    // 値 pin は存在検査を包含するため、REQUIRED_KEYS への二重登録は台帳の腐敗
    // (どちらを緩めたか追えなくなる)。機械的に禁止する。
    $required = envExampleRequiredKeys();
    // array_map + closure は引数のネイティブ型が array<mixed, mixed> になり
    // PHPStan level 10 で落ちうるため、helper の array shape をそのまま推論できる
    // foreach で組み立てる。
    $pinKeys = [];
    foreach (envExampleValuePinEntries() as $entry) {
        $pinKeys[] = $entry['key'];
    }

    // entry リスト形式は重複キーを保持するため、この一意性検査 1 本で
    // 台帳内 (同一 const 内) と台帳間 (core / template 間) の重複を両方捕捉する。
    expect(array_values(array_unique($pinKeys)))->toBe($pinKeys);
    expect(array_values(array_intersect($required, $pinKeys)))->toBe([]);
    expect(array_values(array_unique($required)))->toBe($required);
});

```
