<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use FilesystemIterator;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\Support\PhpTokenScan;
use Tests\Support\QueuedJobPopulation;
use Webmozart\Assert\Assert;

/**
 * キュー投入の commit 後ずらし (deferral) を検出する純関数群。
 *
 * 【5 種の検出器】`Queue::shouldDispatchAfterCommit()` の解決順
 * (ShouldQueueAfterCommit → job の `$afterCommit` プロパティ → 接続 config) に
 * 1:1 で対応させ、どの層からも迂回できないようにしている。
 * - D1 `->afterCommit(` / `?->afterCommit(`  … PendingDispatch の明示指定
 * - D2 `DB::afterCommit(`                    … トランザクション callback への退避
 * - D3 宣言的迂回 interface の実装            … **リフレクション判定** (文字列走査ではない)。
 *   `ShouldQueueAfterCommit` に加え **`ShouldHandleEventsAfterCommit`** も見る
 *   (`Events\Dispatcher::handlerShouldBeDispatchedAfterDatabaseTransactions()` が
 *   この interface でも commit 後ずらしを発動するため。ShouldQueue な listener では
 *   これが**キュー投入そのもの**を commit 後へずらす)
 * - D4 config の `after_commit => true`       … sync 以外の接続
 * - D6 `ShouldDispatchAfterCommit` の実装      … **event クラス側**の commit 後ずらし。
 *   `Events\Dispatcher::dispatch()` がこの interface を見て**イベント発火ごと** commit 後へ回すため、
 *   queued listener がぶら下がっていると enqueue も commit 後になる。
 *   event には marker interface が無く母集団を静的に絞れないので、**`app/` の全クラス**という
 *   超集合を deny-by-default で見る (Codex 実装レビュー 確認ラウンド)
 * - D5 `Queueable` の `$afterCommit` プロパティ … **プロパティ (既定値 + constructor promotion)
 *   はリフレクション** + **実行時代入は token 走査**。`public $afterCommit = true;` /
 *   `public function __construct(public bool $afterCommit = ...)` / `$this->afterCommit = true;` は
 *   **D1〜D4 のどれにも映らない第 3 の迂回路**であり、これを落とすと「0 件 pin」の主張が嘘になる
 *
 * 【D3 / D5(既定値) の母集団は `ShouldQueue` 実装だけでは足りない — Mailable を足す】
 * `Mailable` は **`ShouldQueue` を実装していなくても** `Mail::to(...)->queue()` /
 * `Mail::queue()` でキューへ載る。このとき vendor の `SendQueuedMailable::__construct()` が
 * `$mailable instanceof ShouldQueueAfterCommit ? true : ($mailable->afterCommit ?? null)` を
 * **wrapper job へコピーする**ため、非 `ShouldQueue` な Mailable の
 * `public $afterCommit = true;` / `implements ShouldQueueAfterCommit` が
 * そのまま commit 後ずらしになる。
 * **本リポジトリでは現に `CreateInquiryAction` が `Mail::to(...)->queue(...)` を使っている**
 * (仮想の穴ではない。現行 2 クラスは `ShouldQueue` を併記しているので今は母集団に入るが、
 * 併記を外した瞬間に検出器から消える)。
 * よって D3 / D5(既定値) の母集団は
 * **`QueuedJobPopulation::shouldQueueClasses()` ∪ `QueuedJobPopulation::mailableClasses()`** とする。
 * Notification と listener は vendor 側 (`NotificationSender` / `Events\Dispatcher`) が
 * `ShouldQueue` を要求するため `shouldQueueClasses()` で尽きており、追加は要らない。
 *
 * 【D3 を文字列走査にしない理由】文字列走査だと「`ShouldQueueAfterCommit` を継承した中間
 * interface を implement する」「親クラス経由で implement される」形を丸ごと見落とす。
 * 家系の申し送り (「grep afterCommit は interface 名に一致しないので宣言的迂回が丸ごと
 * 見えない」) への正しい応答は、grep を強化することではなく判定を型システム側へ移すこと。
 *
 * 【D1 / D2 / D5(代入) は「文字列 grep」ではなく token 走査で行う】
 * 既存の `Tests\Support\PhpTokenScan::normalize()` を再利用する
 * (`token_get_all()` の正規化。`T_WHITESPACE` / `T_COMMENT` / `T_DOC_COMMENT` を除去済み)。
 * 素の `str_contains()` にすると **本設計自身が破綻する** — 契約の反転 docblock は
 * 旧主張として `->afterCommit()` を引用するため、コメントを見る検出器では
 * 反転を書いた瞬間に gate が落ちる。token 走査ならコメントも文字列リテラルも
 * (`T_CONSTANT_ENCAPSED_STRING` を明示除外して) 対象外にできる。
 *
 * 【引数で母集団を受け取る理由】テストが fixture ディレクトリツリー / ダミークラス /
 * 擬似 config を同じ関数へ食わせて「列挙 → 読み込み → 検出」の**全経路**を通せるようにするため。
 * 検出関数だけを直接叩く形にすると「検出器は生きているが実ファイルが渡されていない」
 * 偽グリーンを閉じられない。
 *
 * 【保証しないもの (誇張しない)】token 走査でも**動的な迂回**には沈黙する —
 * `$m = 'afterCommit'; $job->$m();` / helper・facade alias で包んだ呼び出し /
 * `$this->afterCommit = $flag;` のような動的値 / vendor 内の afterCommit 使用。
 * (D3 と D5(既定値) はリフレクション判定なので中間 interface・親クラス経由まで拾う)
 */
final class QueueDispatchDeferralInventory
{
    /**
     * D1/D2/D5(代入) の走査母集団となる first-party ランタイム PHP のルート。
     * **`app/` だけでは狭い** — `DB::afterCommit` は `routes/console.php` や
     * `bootstrap/app.php` にも書けるため。
     * `vendor/` / `tests/` / `storage/` は対象外 (前者は自リポジトリの管轄外、
     * 後者 2 つはランタイム経路ではない)。この定数が母集団境界の唯一の正本。
     *
     * @var list<string>
     */
    public const RUNTIME_ROOTS = ['app', 'routes', 'bootstrap', 'database', 'config'];

    /**
     * 指定ディレクトリ配下の PHP ファイル絶対パス (昇順) を列挙する純関数。
     * **ルートを引数で受ける**ことで、負のコントロールが fixture root を渡して
     * 「列挙 → 読み込み → 検出」の**列挙部分まで**同じコードを通せる。
     *
     * ★ **引数は絶対パス**である。相対ルートを受けて内部で `base_path()` を掛ける形にすると、
     *   `sys_get_temp_dir()` 配下の fixture root を渡したときにパスが連結されて列挙できない。
     *   本番側の相対→絶対変換は `runtimePhpFiles()` が行う。
     *
     * ★ 各入力について「**絶対パスであること**」「**存在するディレクトリであること**」を
     *   明示検査し、満たさなければ例外を投げる (docblock だけの契約にしない)。
     *   タイポで存在しないルートを渡したときに黙って 0 件を返すと、母集団 0 件 fail の
     *   意図が空洞化するため。
     *
     * @param  list<string>  $absoluteRoots  絶対パスの既存ディレクトリ
     * @return list<string>
     */
    public static function phpFilesUnder(array $absoluteRoots): array
    {
        $paths = [];
        foreach ($absoluteRoots as $root) {
            Assert::true(
                str_starts_with($root, DIRECTORY_SEPARATOR),
                "phpFilesUnder() には絶対パスを渡すこと (受け取った値: {$root})",
            );
            Assert::directory($root, "phpFilesUnder() のルートが存在しません: {$root}");

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                Assert::isInstanceOf($file, SplFileInfo::class);
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * 本番母集団 (RUNTIME_ROOTS を絶対パスへ変換して列挙する)。
     *
     * @return list<string>
     */
    public static function runtimePhpFiles(): array
    {
        return self::phpFilesUnder(array_map(
            static fn (string $root): string => base_path($root),
            self::RUNTIME_ROOTS,
        ));
    }

    /**
     * D1 (`->afterCommit(`) と D2 (`DB::afterCommit(`) を token 走査で検出する。
     *
     * @param  list<string>  $paths
     * @return list<array{path: string, line: int, kind: string}>
     */
    public static function detectInFiles(array $paths): array
    {
        $hits = [];
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            Assert::string($source, "ファイルを読み込めません: {$path}");

            $tokens = PhpTokenScan::normalize($source);
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];
                $name = $tokens[$i + 1] ?? null;
                $open = $tokens[$i + 2] ?? null;
                if ($name === null || $name['id'] !== T_STRING || $name['text'] !== 'afterCommit') {
                    continue;
                }
                if ($open === null || $open['text'] !== '(') {
                    continue; // 呼び出しでない (プロパティ参照など) は D5 の担当
                }

                $id = $token['id'];
                if ($id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR) {
                    $hits[] = ['path' => $path, 'line' => $name['line'], 'kind' => 'D1'];

                    continue;
                }
                if ($id === T_DOUBLE_COLON) {
                    // `DB::afterCommit(` / `Illuminate\Support\Facades\DB::afterCommit(`
                    $hits[] = ['path' => $path, 'line' => $name['line'], 'kind' => 'D2'];
                }
            }
        }

        return $hits;
    }

    /**
     * D3: 宣言的迂回 interface を implement するクラス。
     * `ShouldQueueAfterCommit` と `ShouldHandleEventsAfterCommit` の**両方**を見る
     * (`ReflectionClass::implementsInterface()` なので中間 interface / 親クラス経由も拾う)。
     *
     * @param  list<class-string>  $classes
     * @return list<class-string>
     */
    public static function detectAfterCommitInterfaces(array $classes): array
    {
        $hits = [];
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->implementsInterface(ShouldQueueAfterCommit::class)
                || $reflection->implementsInterface(ShouldHandleEventsAfterCommit::class)) {
                $hits[] = $reflection->getName();
            }
        }

        return $hits;
    }

    /**
     * D6: `ShouldDispatchAfterCommit` を implement するクラス (event 側の commit 後ずらし)。
     * 母集団は `QueuedJobPopulation::appClasses()` (= `app/` の全クラス) を渡す想定。
     *
     * @param  list<class-string>  $classes
     * @return list<class-string>
     */
    public static function detectDispatchAfterCommitEvents(array $classes): array
    {
        $hits = [];
        foreach ($classes as $class) {
            if ((new ReflectionClass($class))->implementsInterface(ShouldDispatchAfterCommit::class)) {
                $hits[] = $class;
            }
        }

        return $hits;
    }

    /**
     * D3 / D5(既定値) の母集団 = `ShouldQueue` 実装 ∪ Mailable subclass。
     * **和集合にする理由**は class docblock を参照。重複は除去し昇順で返す。
     *
     * @return list<class-string>
     */
    public static function deferralCandidateClasses(): array
    {
        return self::mergeCandidateClasses(
            QueuedJobPopulation::shouldQueueClasses(),
            QueuedJobPopulation::mailableClasses(),
        );
    }

    /**
     * 和集合の生成そのものを**引数で検証できる形**に切り出した純関数。
     *
     * ★ **なぜ切り出すか**: 現状の本リポジトリでは `mailableClasses()` ⊆ `shouldQueueClasses()`
     *   (2 つの Mailable が `implements ShouldQueue` を併記している) のため、
     *   `deferralCandidateClasses()` を「shouldQueueClasses だけ返す」に潰しても**結果が変わらず、
     *   ブラックボックスのテストでは検出できない**。和集合を取る意図そのものは、
     *   ここへ disjoint な集合を食わせる負のコントロールで固定する。
     *   (併記が外れて集合が乖離した瞬間に、D3 / D5 の 0 件 pin が実効を持つ)
     *
     * @param  list<class-string>  $shouldQueueClasses
     * @param  list<class-string>  $mailableClasses
     * @return list<class-string>
     */
    public static function mergeCandidateClasses(array $shouldQueueClasses, array $mailableClasses): array
    {
        $classes = array_values(array_unique(array_merge($shouldQueueClasses, $mailableClasses)));
        sort($classes);

        return $classes;
    }

    /**
     * D4: `after_commit => true` を持つ接続名 (sync を除く)。
     *
     * @param  array<mixed>  $connections
     * @return list<string> 違反した接続名
     */
    public static function detectAfterCommitEnabledConnections(array $connections): array
    {
        $hits = [];
        foreach ($connections as $name => $config) {
            if ($name === 'sync') {
                continue; // sync の after_commit=true は M1 の契約 (R4 が別途固定する)
            }
            if (! is_array($config)) {
                continue;
            }
            if (($config['after_commit'] ?? null) === true) {
                $hits[] = (string) $name;
            }
        }

        sort($hits);

        return $hits;
    }

    /**
     * D5 (既定値): `$afterCommit` プロパティの既定値が「commit 後ずらしを起こす値」のクラス。
     * `ReflectionClass::getDefaultProperties()` を使う (**インスタンス化しない**ので、
     * コンストラクタ引数が必要な job でも判定できる)。
     *
     * ★ **判定は vendor と同じ真偽値文脈 (`(bool) $value`)** である。設計は `=== true` の
     *   厳密比較を指定していたが、vendor 側 (`Queue::shouldDispatchAfterCommit()`) は
     *   `isset($job->afterCommit)` で拾った値を**そのまま真偽値文脈で**評価するため、
     *   `1` / `'yes'` のような truthy 値でも commit 後ずらしが起きる
     *   (`=== true` だけだとすり抜ける)。逆に `null` / `false` / `0` / `''` は
     *   vendor 側でも発動しないので**違反にしない** (設計が禁じた偽陽性の失敗形)。
     *
     * ★ **constructor promotion の `$afterCommit` は値に依らず違反**である。
     *   promoted parameter は呼び出し側が `new SomeJob(afterCommit: true)` で任意に渡せるため、
     *   既定値が `false` でも 0 件 pin の穴になる (Codex 実装レビュー Round 2)。
     *   promoted property の既定値は `getDefaultProperties()` に現れないため、
     *   プロパティ宣言だけを見る実装ではこの経路が丸ごと見えない。
     *
     * @param  list<class-string>  $classes
     * @return list<class-string>
     */
    public static function detectAfterCommitProperty(array $classes): array
    {
        $hits = [];
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);

            $defaults = $reflection->getDefaultProperties();
            if (array_key_exists('afterCommit', $defaults) && self::defersAfterCommit($defaults['afterCommit'])) {
                $hits[] = $reflection->getName();

                continue;
            }

            $constructor = $reflection->getConstructor();
            if ($constructor === null) {
                continue;
            }
            foreach ($constructor->getParameters() as $parameter) {
                // promoted な $afterCommit は**既定値に依らず**違反 (呼び出し側が任意に渡せる)
                if ($parameter->getName() === 'afterCommit' && $parameter->isPromoted()) {
                    $hits[] = $reflection->getName();

                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * 単一トークンのリテラルを真偽値へ評価する (評価できなければ null)。
     * `true` / 非ゼロ数値 / `'0'` 以外の非空文字列が truthy、
     * `false` / `null` / `0` / `''` / `'0'` が falsy。それ以外 (変数・式・配列) は null。
     *
     * @param  array{id: int|null, text: string, line: int}  $token
     */
    private static function truthyLiteral(array $token): ?bool
    {
        if ($token['id'] === T_STRING) {
            return match (strtolower($token['text'])) {
                'true' => true,
                'false', 'null' => false,
                default => null, // 定数参照は評価しない
            };
        }
        if ($token['id'] === T_LNUMBER || $token['id'] === T_DNUMBER) {
            // ★ 基数付きリテラル (0x / 0b / 0o / 先頭 0 の 8 進) と数値区切り `_` を扱う。
            //   素の `(float) '0x1'` は 0.0 になり、truthy な値を falsy と誤判定する。
            $text = str_replace('_', '', strtolower($token['text']));

            if (str_starts_with($text, '0x')) {
                return (float) hexdec(substr($text, 2)) !== 0.0;
            }
            if (str_starts_with($text, '0b')) {
                return (float) bindec(substr($text, 2)) !== 0.0;
            }
            if (str_starts_with($text, '0o')) {
                return (float) octdec(substr($text, 2)) !== 0.0;
            }
            if (preg_match('/^0[0-7]+$/', $text) === 1) {
                return (float) octdec($text) !== 0.0;   // 旧式 8 進 (先頭 0)
            }

            return ((float) $text) !== 0.0;
        }
        if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
            $literal = substr($token['text'], 1, -1);
            // ★ エスケープを含む文字列は**評価不能に倒す** (`"\x30"` の実行時値は "0" = falsy だが、
            //   ソース表記のままだと truthy と誤判定して偽陽性になる)。完全なエスケープ評価は行わない。
            if (str_contains($literal, '\\')) {
                return null;
            }

            return $literal !== '' && $literal !== '0';
        }

        return null;
    }

    /**
     * その既定値が commit 後ずらしを発動させるか。
     * vendor (`Queue::shouldDispatchAfterCommit()`) が真偽値文脈で評価するため、判定も同じにする。
     */
    private static function defersAfterCommit(mixed $value): bool
    {
        return (bool) $value;
    }

    /**
     * D5 (実行時代入): `->afterCommit = <truthy リテラル>` の **token 走査**。
     * `$this->afterCommit = true;` (自クラス内) と `$job->afterCommit = 1;`
     * (外部からの代入) の**両方**を拾う = 判定は receiver を問わず
     * `T_OBJECT_OPERATOR` + `afterCommit` + `=` + <リテラル 1 個> + `;` の並びで行う。
     *
     * ★ **truthy 判定は vendor と同じ真偽値文脈**である (`true` だけでなく `1` / `'yes'` も違反。
     *   `false` / `null` / `0` / `''` / `'0'` は違反にしない)。`= true` だけを見る実装では
     *   `$this->afterCommit = 1;` がすり抜ける (Codex 実装レビュー Round 3)。
     *
     * ★ **保証しないもの**: 評価するのは**単一リテラルの代入**だけである。
     *   変数 (`= $flag`)・定数・式 (`= 1 + 1`)・関数呼び出し・配列、および
     *   **エスケープを含む文字列リテラル** (`= "\x31"`) は真偽値を静的に決められないため
     *   **検出しない** (0 件 pin の既知の穴。誇張しない)。
     *   数値は基数付き (`0x` / `0b` / `0o` / 先頭 0 の 8 進) と数値区切り `_` まで評価する。
     *
     * @param  list<string>  $paths
     * @return list<array{path: string, line: int}>
     */
    public static function detectAfterCommitAssignments(array $paths): array
    {
        $hits = [];
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            Assert::string($source, "ファイルを読み込めません: {$path}");

            $tokens = PhpTokenScan::normalize($source);
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                $operator = $tokens[$i];
                if ($operator['id'] !== T_OBJECT_OPERATOR && $operator['id'] !== T_NULLSAFE_OBJECT_OPERATOR) {
                    continue;
                }
                $name = $tokens[$i + 1] ?? null;
                $assign = $tokens[$i + 2] ?? null;
                $value = $tokens[$i + 3] ?? null;
                if ($name === null || $name['id'] !== T_STRING || $name['text'] !== 'afterCommit') {
                    continue;
                }
                if ($assign === null || $assign['text'] !== '=') {
                    continue;
                }
                $terminator = $tokens[$i + 4] ?? null;
                if ($value === null || $terminator === null || $terminator['text'] !== ';') {
                    continue; // 単一リテラルの代入以外は静的に真偽値を決められない (既知の穴)
                }
                if (self::truthyLiteral($value) !== true) {
                    continue; // falsy リテラル / 評価不能はいずれも違反にしない
                }

                $hits[] = ['path' => $path, 'line' => $name['line']];
            }
        }

        return $hits;
    }
}
