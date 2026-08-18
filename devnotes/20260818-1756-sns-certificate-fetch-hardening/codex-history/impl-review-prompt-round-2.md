# Round 1 の指摘への対応

# 対応マトリクス: impl-review Round 1

## [Warning] C3 のロック site 検出が完全修飾名を解決していない

- 判断: 対応する
- 根拠: 指摘のとおり。`use Illuminate\Support\Facades\Cache as LockCache;` と書けば
  短名照合をすり抜け、ロック取得を 2 本に増やしても C3 が黙る。
  AGENTS.md「静的検査 (gate) と走査器の共通規約」(a) 違反であり、
  permit 1 という主張の根拠が実際には成立していなかった。
- 対応内容:
  - `snsCertStaticCallIndexes()` を `PhpReferenceScanner::references()` ベースへ書き換え、
    受け手を**完全修飾名まで解決**してから `Illuminate\Support\Facades\Cache` と突き合わせる。
    **未解決の受け手 (`$facade::lock()` 等) は候補に含める** (拾いすぎ側 = 見逃さない側へ倒す。規約 (b))。
  - 負例を追加: 別名つき取り込み / 完全修飾形 / 未解決の受け手の 3 形で `lockCalls` が 2 になること。
  - 正例を追加: 別名で `Cache` という短名が**別のクラス**を指す形を数えないこと (誤検出しない側)。

## [Warning] C8 / C9 が宣言している全判定の両方向を固定していない

- 判断: 対応する
- 根拠: 規約 (c)「検出力は負例で裏取りする (両方向)」と詳細設計 E の要求に未達だった。
  とくに C11 の順序判定は gate 本文にインラインで書いていたため、
  判定器そのものを合成入力に当てられなかった。
- 対応内容:
  - C11 の順序判定を純関数 `snsCertPromotionOrderViolations()` へ抽出し、gate 本文はそれを使う。
  - 負例を追加: 昇格が署名検証より前 / 昇格が 2 件 / 署名検証の site が無い、の 3 形。
  - 正例を追加: 規定どおりの順序を違反にしないこと。
  - C1 / C13b の正例を追加: 対象外のクラス参照 (`Illuminate\Http\Client\Response` /
    別名つきの `Symfony\Component\HttpFoundation\Response`) を違反にしないこと。

## [Warning] `Cache::swap()` が T228 の実行時キャッシュガードを置き換えている

- 判断: 対応する
- 根拠: 指摘のとおり。`Cache::swap()` は facade の解決済みインスタンスとコンテナの `cache` 束縛を
  素の `CacheManager` の部分モックへ差し替えるため、そのテストの間だけ
  `PlainDataGuardedRepository` を経由しなくなる。現時点でオブジェクトを書いていなくても、
  「テスト中のキャッシュ書き込みを受け皿の側で捕まえる」という不変条件 11 の被覆が
  1 ファイルぶん静かに消える形は残すべきでない。
- 対応内容:
  - `Cache` facade の差し替えをやめ、**本物の保管方式を実際に壊す**形に置き換えた
    (`useBrokenSnsCertificateCacheStore(bool $valueTableExists, bool $lockTableExists)`)。
    database driver は値とロックで**別の表**を使うので、
    「値の表だけ無い」= 読み書きだけ失敗 / 「ロックの表だけ無い」= ロック取得だけ失敗、
    を別々に再現できる。guard 付き受け皿はそのまま効いている。
  - 接続は**テスト専用の sqlite in-memory** にした。本番のテスト DB (pgsql) 上で存在しない表を
    引くと外側の transaction が abort し (`RefreshDatabase`)、後続の DB 操作がすべて
    別の理由で失敗して検証にならないためである。
  - あわせて**正のコントロール**を各テストへ入れた
    (F10: `Cache::get` が実際に `QueryException` になること /
     F11: `Cache::put` が実際に `QueryException` になること /
     F16: `Cache::lock(...)->get()` が実際に `QueryException` になること)。
    これが無いと「単に miss だった」「単に競合しなかった」と区別できない。
  - F15 (排他非対応の保管方式は fail-fast) は従来どおり `storage` driver
    (`StorageStore` は `LockProvider` を実装しない) で再現する。
  - L2 目録の件数を 6 → 7 (正のコントロールの `Cache::put` 1 件ぶん)、
    L3 の rationale から `swap` の記述を落とした。

## [Warning] 追加した L2 / L3 目録が `Cache::swap()` を固定・拒否していない

- 判断: 対応する (上の対応で解消)
- 根拠: 目録は分類であって迂回の禁止ではない。指摘の本質は
  「実行時ガードの迂回そのものをやめること」なので、目録側ではなくテスト側を直した。
- 対応内容: `Cache::swap()` の呼び出しを差分から除去した (0 件)。

## [Suggestion] docs/architecture.md の機械固定の説明が検出力より強い

- 判断: 対応する (上の C3 修正で解消)
- 根拠: C3 が完全修飾名ベースになったので、「単一ロックキー = permit 1 を機械固定する」
  という記述と実際の検出力が一致した。走査根の外・変数経由・列挙していない通信の原語に
  効かないことは既に「保証しないもの」3 に書いてある。
- 対応内容: 記述の変更は不要 (実装側が説明に追いついた)。


## 修正後のファイル


### `tests/Architecture/SnsCertificateFetchContractTest.php` (修正後の全文)

```php
<?php

declare(strict_types=1);

use App\Services\Mail\Sns\SnsCertificateFetcher;
use Tests\Support\PhpReferenceScanner;
use Tests\Support\PhpTokenScan;
use Tests\Support\ReferenceKind;

/*
 * Architecture 契約: **SNS 署名検証の証明書取得口**の形を機械で固定する。
 *
 * SoT = 家系の機能台帳 feature `mail-ses-suppression` の正典 t1 (裁定 AG-199) と
 * devnotes/20260818-1756-sns-certificate-fetch-hardening/。
 *
 * ★**汎用の走査器は作らない**。守る対象が名指しの数ファイルなので、既存の中立走査器
 *   (`Tests\Support\PhpReferenceScanner` = namespace / use / group use を解いて完全修飾名を返す) と
 *   `Tests\Support\PhpTokenScan` の字句列だけを使い、判定条件を本ファイルに持つ
 *   (AGENTS.md 思考原則 2「今必要なものだけ作る」)。
 *
 * ## 走査根 (SNS_CERT_CONTRACT_ROOTS)
 *
 *   app/Services/Mail/Sns/                              (ディレクトリ全体)
 *   app/Http/Middleware/VerifySnsSignature.php
 *   app/Http/Controllers/Webhooks/SesNotificationController.php
 *
 * 昇格 site の唯一性 (C11) だけは `app/` **全体**を走査根にする。
 * 根が実在しないときは **fail-fast** (無言で空集合にしない)。
 *
 * ## 検査
 *
 *  - C1  取得口の唯一性     走査根で HTTP client 型を参照するファイルが取得口ちょうど 1 件 (exact-fit)
 *  - C2  時間の大小関係     0 < connect <= request、0 < post、request + post <= lock 寿命、
 *                          0 < cache 寿命、0 < 応答サイズ上限
 *  - C3  単一ロックキー     permit 定数 === 1 / `Cache::lock(` の site が 1 件 /
 *                          第 1 引数が `self::CERT_FETCH_LOCK_KEY` ちょうど / ロックキー定数が 1 本
 *  - C4  待ち上限は 0       取得口に `->block(` の site が 0 件 (待たない実装であることの構造的固定)
 *  - C5  TLS 検証を切らない 走査根に `withoutVerifying` の site と `'verify' => false` が 0 件
 *  - C10 取得の配線         取得口に `connectTimeout(` / `timeout(` / `withoutRedirecting(` が
 *                          それぞれ 1 件、前 2 つの引数が `Config::integer('services.sns_certificate.…')`、
 *                          `->throw(` が 0 件
 *  - C11 昇格 site の唯一性 `app/` 全体で `rememberVerified(` の呼び出しが 1 件で、署名検証器の中の
 *                          `validate(` より後ろの行にある
 *  - C12 未解決を落とす     走査根の参照位置に部分修飾名が現れたら失敗させる
 *  - C13a 通信の原語 (関数) 走査根に file_get_contents / fopen / curl_init / curl_exec /
 *                          stream_context_create の**呼び出し**が 0 件
 *  - C13b 通信の原語 (クラス) 走査根に GuzzleHttp\Client / Symfony の HttpClient の参照が 0 件
 *  - C6  空振り検知         走査根が 3 つとも解決でき、走査ファイル数 > 0、C1 の母集団が空でない、
 *                          C3 / C10 の token 走査が対象 site を検出している、`app/` 走査が空でない
 *  - C7  解決できない形     読めないファイル / トークン化できない入力は**未解決として失敗**させる
 *  - C8  自己検査 (負例)    合成入力に対し各判定器が違反を検出する
 *  - C9  自己検査 (正例)    合成入力に対し規定どおりの書き方を違反と判定しない
 *
 * ## 語彙一致の区切り (AGENTS.md 走査規約 (e))
 *
 * 語彙の一致は **`PhpTokenScan::normalize()` が返すトークン境界**で区切り、トークンの値の
 * **完全一致**で判定する (部分文字列一致も正規表現の語境界も使わない)。
 * 関数名の語彙 (C13a) は「完全一致 + 直後が `(` + 直前が `->` / `?->` / `::` / `function` /
 * `new` でない」で判定し、クラス参照の語彙 (C13b) は `PhpReferenceScanner` が返す
 * **完全修飾名**で照合する — **走査方法が違う**。
 *
 * ## 保証しないもの (誇張しない)
 *
 *  - **変数経由の指定** (`$m = 'withoutVerifying'; $req->{$m}();`)、実行時に組み立てた
 *    オプション配列、可変関数 (`$fn = 'file_get_contents'; $fn($url);`) には**無言で効かない**
 *  - **走査根と同じ名前空間にローカル定義された同名関数**。C13a は名前空間の相対解決を
 *    追わないので「グローバル関数を呼んでいる」と判定する (拾いすぎ側 = 見逃さない側へ倒す)
 *  - **C10 は配線 site が同じ連鎖の上にあることまでは証明しない** (取得口の中に
 *    それぞれの site があることしか見ない)
 *  - **波括弧つきの名前空間宣言 (`namespace App\Example { … }`) は保証対象外**である。
 *    C12 は「brace 深さ 0 の `use` を import とみなす」ので、この書き方では import が
 *    読み飛ばされず**未解決として落ちる** (fail-closed 側)
 *  - **グループ use の内側の関数 import** (`use App\{function f};`) は別名表を作れないので、
 *    見つけた時点で**未解決として落とす** (fail-closed 側)
 *  - 走査根の**外**にある証明書取得。`app/` 全体の `Http::` facade 参照は
 *    `ExternalSeamInventory` の担当で、**注入された `HttpFactory` は同目録の母集団に入らない**
 *    (この非対称は `docs/architecture.md` に書く)
 *  - C13a / C13b の語彙は**列挙**であり、通信の原語を網羅しているとは主張しない
 *  - リポジトリの外にある設定 (php.ini の `openssl.cafile` など)
 *
 * DB 不使用 (Architecture lane は TestCase のみ)。
 */

/**
 * 走査根 (リポジトリルートからの相対パス)。ディレクトリでもファイルでもよい。
 *
 * @var list<string>
 */
const SNS_CERT_CONTRACT_ROOTS = [
    'app/Services/Mail/Sns',
    'app/Http/Middleware/VerifySnsSignature.php',
    'app/Http/Controllers/Webhooks/SesNotificationController.php',
];

/** 証明書取得口 (唯一の外部 HTTP 到達点)。 */
const SNS_CERT_FETCHER_PATH = 'app/Services/Mail/Sns/SnsCertificateFetcher.php';

/** 署名検証器 (昇格を呼んでよい唯一のファイル)。 */
const SNS_CERT_VERIFIER_PATH = 'app/Services/Mail/Sns/AwsSnsSignatureVerifier.php';

/**
 * HTTP client の受け手型 (完全修飾名)。
 *
 * @var list<string>
 */
const SNS_CERT_HTTP_CLIENT_TYPES = [
    'Illuminate\Http\Client\Factory',
    'Illuminate\Support\Facades\Http',
];

/**
 * 通信の原語 (関数)。すべて小文字で持つ (PHP の関数名は大文字小文字を区別しない)。
 *
 * @var list<string>
 */
const SNS_CERT_FORBIDDEN_FUNCTIONS = [
    'file_get_contents', 'fopen', 'curl_init', 'curl_exec', 'stream_context_create',
];

/**
 * 通信の原語 (クラス。完全修飾名)。
 *
 * @var list<string>
 */
const SNS_CERT_FORBIDDEN_CLASSES = [
    'GuzzleHttp\Client',
    'Symfony\Component\HttpClient\HttpClient',
];

/** 予算 config の接頭辞。 */
const SNS_CERT_BUDGET_PREFIX = 'services.sns_certificate.';

// ---------------------------------------------------------------------------
// 入力の解決 (C6 / C7)
// ---------------------------------------------------------------------------

/**
 * ファイルを読む。読めなければ**未解決として失敗**させる (C7)。
 */
function snsCertReadSource(string $absolutePath): string
{
    if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
        throw new RuntimeException("走査対象が読めません: {$absolutePath}");
    }

    $source = file_get_contents($absolutePath);
    if ($source === false || $source === '') {
        throw new RuntimeException("走査対象を読み出せません: {$absolutePath}");
    }

    return $source;
}

/**
 * 字句列。トークン化できない (空になる) 入力は**未解決として失敗**させる (C7)。
 *
 * @return list<array{id: int|null, text: string, line: int}>
 */
function snsCertTokens(string $source): array
{
    $tokens = PhpTokenScan::normalize($source);
    if ($tokens === []) {
        throw new RuntimeException('走査対象をトークン化できません');
    }

    return $tokens;
}

/**
 * 走査根の配下にある PHP ファイル (絶対パス)。
 *
 * @return list<string>
 */
function snsCertPhpFilesUnder(string $absolutePath): array
{
    if (is_file($absolutePath)) {
        return str_ends_with($absolutePath, '.php') ? [$absolutePath] : [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS)
    );
    /** @var SplFileInfo $entry */
    foreach ($iterator as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            $files[] = $entry->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * 走査根のソース (相対パス => 中身)。根が実在しなければ fail-fast (C6)。
 *
 * @return array<string, string>
 */
function snsCertContractSources(): array
{
    $sources = [];
    foreach (SNS_CERT_CONTRACT_ROOTS as $root) {
        $absolute = base_path($root);
        if (! file_exists($absolute)) {
            throw new RuntimeException("走査根が実在しません: {$root}");
        }
        foreach (snsCertPhpFilesUnder($absolute) as $file) {
            $sources[str_replace(base_path().'/', '', $file)] = snsCertReadSource($file);
        }
    }
    ksort($sources);

    return $sources;
}

/**
 * `app/` 全体のソース (相対パス => 中身)。C11 専用。
 *
 * @return array<string, string>
 */
function snsCertAppSources(): array
{
    $base = base_path('app');
    if (! is_dir($base)) {
        throw new RuntimeException('走査根が実在しません: app');
    }

    $sources = [];
    foreach (snsCertPhpFilesUnder($base) as $file) {
        $sources[str_replace(base_path().'/', '', $file)] = snsCertReadSource($file);
    }
    ksort($sources);

    return $sources;
}

// ---------------------------------------------------------------------------
// 字句判定の部品 (すべて純関数。合成入力にも当てられる)
// ---------------------------------------------------------------------------

/**
 * `$x->name(` / `$x?->name(` の呼び出し site の添字。
 *
 * @param  list<array{id: int|null, text: string, line: int}>  $tokens
 * @return list<int>
 */
function snsCertMethodCallIndexes(array $tokens, string $name): array
{
    $indexes = [];
    $count = count($tokens);
    for ($i = 1; $i < $count; $i++) {
        if ($tokens[$i]['id'] !== T_STRING || $tokens[$i]['text'] !== $name) {
            continue;
        }
        $previousId = $tokens[$i - 1]['id'];
        if ($previousId !== T_OBJECT_OPERATOR && $previousId !== T_NULLSAFE_OBJECT_OPERATOR) {
            continue;
        }
        $next = $tokens[$i + 1] ?? null;
        if ($next === null || $next['id'] !== null || $next['text'] !== '(') {
            continue;
        }
        $indexes[] = $i;
    }

    return $indexes;
}

/**
 * `Receiver::name(` の静的呼び出し site の添字 (**受け手を完全修飾名まで解決して**絞る)。
 *
 * ★短名の一致では駄目である (AGENTS.md 走査規約 (a))。
 *   `use Illuminate\Support\Facades\Cache as LockCache;` と書けば `LockCache::lock(...)` は
 *   短名照合をすり抜け、ロック取得を 2 本に増やしても検査が黙る。
 * ★**未解決の受け手 (`$x::lock()` / `static::lock()` 等) は候補に含める** —
 *   拾いすぎ側 = 見逃さない側へ倒す (規約 (b))。
 *
 * @return list<int> `PhpReferenceScanner::tokens()` の添字
 */
function snsCertStaticCallIndexes(string $relativePath, string $source, string $receiverFqcn, string $name): array
{
    $indexes = [];
    foreach (PhpReferenceScanner::references($relativePath, $source)->sites as $site) {
        if ($site->kind !== ReferenceKind::StaticCall || $site->name !== $name) {
            continue;
        }
        if ($site->receiver->isResolved() && ! $site->receiver->is($receiverFqcn)) {
            continue;
        }
        $indexes[] = $site->tokenIndex;
    }

    return $indexes;
}

/**
 * 呼び出し site の**第 1 引数**が `self::{定数名}` ちょうどか。
 *
 * @param  list<array{id: int|null, text: string, line: int}>  $tokens
 */
function snsCertFirstArgumentIsClassConstant(array $tokens, int $callIndex, string $constantName): bool
{
    $open = $tokens[$callIndex + 1] ?? null;
    if ($open === null || $open['id'] !== null || $open['text'] !== '(') {
        return false;
    }
    $self = $tokens[$callIndex + 2] ?? null;
    $colon = $tokens[$callIndex + 3] ?? null;
    $name = $tokens[$callIndex + 4] ?? null;
    $terminator = $tokens[$callIndex + 5] ?? null;

    return $self !== null && $self['id'] === T_STRING && $self['text'] === 'self'
        && $colon !== null && $colon['id'] === T_DOUBLE_COLON
        && $name !== null && $name['id'] === T_STRING && $name['text'] === $constantName
        && $terminator !== null && $terminator['id'] === null
        && ($terminator['text'] === ',' || $terminator['text'] === ')');
}

/**
 * 呼び出し site の第 1 引数が `Config::integer('…')` のときの config キー。違えば null。
 *
 * @param  list<array{id: int|null, text: string, line: int}>  $tokens
 */
function snsCertConfigIntegerArgument(array $tokens, int $callIndex): ?string
{
    $expected = [
        [$callIndex + 1, null, '('],
        [$callIndex + 2, T_STRING, 'Config'],
        [$callIndex + 3, T_DOUBLE_COLON, null],
        [$callIndex + 4, T_STRING, 'integer'],
        [$callIndex + 5, null, '('],
    ];
    foreach ($expected as [$index, $id, $text]) {
        $token = $tokens[$index] ?? null;
        if ($token === null || $token['id'] !== $id) {
            return null;
        }
        if ($text !== null && $token['text'] !== $text) {
            return null;
        }
    }

    $literal = $tokens[$callIndex + 6] ?? null;
    if ($literal === null || $literal['id'] !== T_CONSTANT_ENCAPSED_STRING) {
        return null;
    }
    $close1 = $tokens[$callIndex + 7] ?? null;
    $close2 = $tokens[$callIndex + 8] ?? null;
    if ($close1 === null || $close1['id'] !== null || $close1['text'] !== ')') {
        return null;
    }
    if ($close2 === null || $close2['id'] !== null || $close2['text'] !== ')') {
        return null;
    }

    return trim($literal['text'], "'\"");
}

/**
 * クラス定数の宣言名の一覧 (型つき定数 `private const string X = …` にも対応)。
 *
 * @param  list<array{id: int|null, text: string, line: int}>  $tokens
 * @return list<string>
 */
function snsCertConstantNames(array $tokens): array
{
    $names = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]['id'] !== T_CONST) {
            continue;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === ';') {
                break;
            }
            $next = $tokens[$j + 1] ?? null;
            if ($tokens[$j]['id'] === T_STRING && $next !== null && $next['id'] === null && $next['text'] === '=') {
                $names[] = $tokens[$j]['text'];

                break;
            }
        }
    }

    return $names;
}

// ---------------------------------------------------------------------------
// 各検査の判定器 (純関数)
// ---------------------------------------------------------------------------

/**
 * C1 / C13b: 名前参照の完全修飾名のうち、与えた集合に属するもの。
 *
 * @param  list<string>  $targets
 * @return list<string>
 */
function snsCertMatchingReferences(string $relativePath, string $source, array $targets): array
{
    $found = [];
    foreach (PhpReferenceScanner::references($relativePath, $source)->sites as $site) {
        if (in_array($site->name, $targets, true)) {
            $found[] = $site->name;
        }
    }

    return array_values(array_unique($found));
}

/**
 * C3: ロックの契約。
 *
 * @return array{lockCalls: int, firstArgumentOk: bool, lockKeyConstants: int}
 */
function snsCertLockContract(string $relativePath, string $source): array
{
    $tokens = snsCertTokens($source);
    $indexes = snsCertStaticCallIndexes($relativePath, $source, 'Illuminate\Support\Facades\Cache', 'lock');
    $firstArgumentOk = $indexes !== [];
    foreach ($indexes as $index) {
        if (! snsCertFirstArgumentIsClassConstant($tokens, $index, 'CERT_FETCH_LOCK_KEY')) {
            $firstArgumentOk = false;
        }
    }

    $lockKeyConstants = 0;
    foreach (snsCertConstantNames($tokens) as $name) {
        if (str_ends_with($name, '_LOCK_KEY')) {
            $lockKeyConstants++;
        }
    }

    return [
        'lockCalls' => count($indexes),
        'firstArgumentOk' => $firstArgumentOk,
        'lockKeyConstants' => $lockKeyConstants,
    ];
}

/**
 * C4: 待ち上限が構造的に 0 か (`->block(` の site 数)。
 */
function snsCertBlockingWaitCount(string $source): int
{
    return count(snsCertMethodCallIndexes(snsCertTokens($source), 'block'));
}

/**
 * C5: TLS 検証を無効化している site の説明。
 *
 * ★**メソッド呼び出しの `->verify(` は判定に使わない** — 走査根の `VerifySnsSignature` が
 *   `$this->verifier->verify($message)` を持つため、禁じると正当なコードで必ず赤くなる。
 *
 * @return list<string>
 */
function snsCertTlsDisablingSites(string $source): array
{
    $tokens = snsCertTokens($source);
    $found = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // (i) `withoutVerifying` に**完全一致**するトークン
        if ($token['id'] === T_STRING && $token['text'] === 'withoutVerifying') {
            $found[] = "withoutVerifying@L{$token['line']}";

            continue;
        }

        // (ii) `'verify' => false`
        if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING || trim($token['text'], "'\"") !== 'verify') {
            continue;
        }
        $arrow = $tokens[$i + 1] ?? null;
        $value = $tokens[$i + 2] ?? null;
        if ($arrow === null || $arrow['id'] !== T_DOUBLE_ARROW) {
            continue;
        }
        if ($value !== null && $value['id'] === T_STRING && mb_strtolower($value['text']) === 'false') {
            $found[] = "verify=>false@L{$token['line']}";
        }
    }

    return $found;
}

/**
 * C10: 取得の配線。
 *
 * @return array{connectTimeout: int, timeout: int, withoutRedirecting: int, throw: int, budgetKeys: list<string>}
 */
function snsCertFetchWiring(string $source): array
{
    $tokens = snsCertTokens($source);
    $budgetKeys = [];
    foreach (['connectTimeout', 'timeout'] as $method) {
        foreach (snsCertMethodCallIndexes($tokens, $method) as $index) {
            $key = snsCertConfigIntegerArgument($tokens, $index);
            $budgetKeys[] = $key ?? "{$method}:unresolved";
        }
    }

    return [
        'connectTimeout' => count(snsCertMethodCallIndexes($tokens, 'connectTimeout')),
        'timeout' => count(snsCertMethodCallIndexes($tokens, 'timeout')),
        'withoutRedirecting' => count(snsCertMethodCallIndexes($tokens, 'withoutRedirecting')),
        'throw' => count(snsCertMethodCallIndexes($tokens, 'throw')),
        'budgetKeys' => $budgetKeys,
    ];
}

/**
 * C11: 呼び出し site の行番号 (メソッド呼び出しのみ = 宣言は数えない)。
 *
 * @return list<int>
 */
function snsCertMethodCallLines(string $source, string $name): array
{
    $tokens = snsCertTokens($source);
    $lines = [];
    foreach (snsCertMethodCallIndexes($tokens, $name) as $index) {
        $lines[] = $tokens[$index]['line'];
    }

    return $lines;
}

/**
 * C11: 昇格が「署名検証のあと」に置かれているかの違反一覧 (純関数)。
 *
 * 違反は 3 つ — 昇格が 1 件でない / 署名検証の site が 1 件も無い /
 * 昇格が署名検証より前の行にある。
 *
 * @return list<string>
 */
function snsCertPromotionOrderViolations(string $source): array
{
    $validateLines = snsCertMethodCallLines($source, 'validate');
    $promotionLines = snsCertMethodCallLines($source, 'rememberVerified');

    $violations = [];
    if (count($promotionLines) !== 1) {
        $violations[] = '昇格 site が 1 件ではありません: '.count($promotionLines).' 件';
    }
    if ($validateLines === []) {
        $violations[] = '署名検証 (validate) の site がありません';
    }
    if ($violations !== []) {
        return $violations;
    }

    if ($promotionLines[0] <= max($validateLines)) {
        $violations[] = "昇格 (L{$promotionLines[0]}) が署名検証 (L".max($validateLines).') より前にあります';
    }

    return $violations;
}

/**
 * C12: 参照位置に現れた部分修飾名 (走査器が解決しないため**未解決として落とす**)。
 *
 * `use` は 3 種類あるので文脈で区別する:
 *  (i)   名前空間スコープ (brace 深さ 0) の import は `;` まで読み飛ばす
 *  (ii)  直前が `)` の closure capture は対応する `)` まで読み飛ばす
 *  (iii) **クラス本体の中の trait use は読み飛ばさない** (参照位置なので解決するか落とす)
 *
 * `T_NAMESPACE` は `;` または `{` まで読み飛ばす (`{` は深さに数えるので、波括弧つきの
 * 名前空間宣言では以降の import が読み飛ばされず**未解決として落ちる** = fail-closed)。
 * `T_NAME_FULLY_QUALIFIED` (先頭が `\`) と `T_NAME_RELATIVE` は絶対に解決できるので対象外。
 *
 * @return list<string>
 */
function snsCertUnresolvedQualifiedNames(string $source): array
{
    $tokens = snsCertTokens($source);
    $count = count($tokens);
    $found = [];
    $depth = 0;

    for ($i = 0; $i < $count; $i++) {
        $id = $tokens[$i]['id'];
        $text = $tokens[$i]['text'];

        if ($id === T_NAMESPACE) {
            while ($i < $count) {
                $current = $tokens[$i];
                if ($current['id'] === null && ($current['text'] === ';' || $current['text'] === '{')) {
                    if ($current['text'] === '{') {
                        $depth++;
                    }

                    break;
                }
                $i++;
            }

            continue;
        }

        if ($id === T_USE) {
            $previous = $tokens[$i - 1] ?? null;
            if ($previous !== null && $previous['id'] === null && $previous['text'] === ')') {
                // closure capture: 対応する `)` まで読み飛ばす
                $parens = 0;
                for (; $i < $count; $i++) {
                    if ($tokens[$i]['id'] !== null) {
                        continue;
                    }
                    if ($tokens[$i]['text'] === '(') {
                        $parens++;
                    } elseif ($tokens[$i]['text'] === ')') {
                        $parens--;
                        if ($parens === 0) {
                            break;
                        }
                    }
                }

                continue;
            }

            if ($depth === 0) {
                // 名前空間スコープの import: `;` まで読み飛ばす
                while ($i < $count && ! ($tokens[$i]['id'] === null && $tokens[$i]['text'] === ';')) {
                    $i++;
                }

                continue;
            }

            // クラス本体の trait use は読み飛ばさない (参照位置として扱う)
            continue;
        }

        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;

            continue;
        }
        if ($id === null && $text === '{') {
            $depth++;

            continue;
        }
        if ($id === null && $text === '}') {
            $depth--;

            continue;
        }

        if ($id === T_NAME_QUALIFIED) {
            $found[] = $text;
        }
    }

    return $found;
}

/**
 * `use function` の別名表 (小文字の別名 => 小文字の関数名)。
 *
 * グループの内側に関数 import を書く形 (`use App\{function f};`) は別名表を作れないので
 * **未解決として失敗**させる (fail-closed)。
 *
 * @param  list<array{id: int|null, text: string, line: int}>  $tokens
 * @return array<string, string>
 */
function snsCertFunctionAliases(array $tokens): array
{
    $aliases = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]['id'] !== T_USE) {
            continue;
        }
        $next = $tokens[$i + 1] ?? null;
        if ($next === null) {
            continue;
        }
        if ($next['id'] !== T_FUNCTION) {
            // グループの内側に関数 import があるかを見る (`use App\{function f};`)
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === ';') {
                    break;
                }
                if ($tokens[$j]['id'] === T_FUNCTION) {
                    throw new RuntimeException('グループ use の内側の関数 import は解決できません');
                }
            }

            continue;
        }

        $name = null;
        $alias = null;
        $expectAlias = false;
        for ($j = $i + 2; $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token['id'] === null && ($token['text'] === ';' || $token['text'] === ',')) {
                if ($name !== null) {
                    $segments = explode('\\', ltrim($name, '\\'));
                    $short = $alias ?? $segments[count($segments) - 1];
                    $aliases[mb_strtolower($short)] = mb_strtolower(ltrim($name, '\\'));
                }
                $name = null;
                $alias = null;
                $expectAlias = false;
                if ($token['text'] === ';') {
                    $i = $j;

                    break;
                }

                continue;
            }
            if ($token['id'] === T_AS) {
                $expectAlias = true;

                continue;
            }
            if ($expectAlias) {
                if ($token['id'] === T_STRING) {
                    $alias = $token['text'];
                }

                continue;
            }
            if (in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = $token['text'];
            }
        }
    }

    return $aliases;
}

/**
 * C13a: 通信の原語 (関数) の呼び出し site。
 *
 * 判定は `T_STRING` だけを見ない — `\file_get_contents` (`T_NAME_FULLY_QUALIFIED`) と
 * `namespace\file_get_contents` (`T_NAME_RELATIVE`) も**関数呼び出し位置**で解決する。
 * `use function X as Y;` の別名も表を作って解決する。部分修飾名は C12 が落とす。
 *
 * @return list<string>
 */
function snsCertForbiddenFunctionCalls(string $source): array
{
    $tokens = snsCertTokens($source);
    $aliases = snsCertFunctionAliases($tokens);
    $found = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $id = $tokens[$i]['id'];
        if ($id !== T_STRING && $id !== T_NAME_FULLY_QUALIFIED && $id !== T_NAME_RELATIVE) {
            continue;
        }
        $next = $tokens[$i + 1] ?? null;
        if ($next === null || $next['id'] !== null || $next['text'] !== '(') {
            continue;
        }
        $previousId = $tokens[$i - 1]['id'] ?? null;
        if (in_array($previousId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_ATTRIBUTE], true)) {
            continue;
        }

        $text = $tokens[$i]['text'];
        $resolved = match ($id) {
            T_NAME_FULLY_QUALIFIED => mb_strtolower(ltrim($text, '\\')),
            T_NAME_RELATIVE => mb_strtolower(substr($text, strlen('namespace\\'))),
            default => $aliases[mb_strtolower($text)] ?? mb_strtolower($text),
        };

        if (in_array($resolved, SNS_CERT_FORBIDDEN_FUNCTIONS, true)) {
            $found[] = $text;
        }
    }

    return $found;
}

// ---------------------------------------------------------------------------
// C6 / C7: 空振り検知と解決できない形
// ---------------------------------------------------------------------------

test('C6: 走査根が 3 つとも解決でき、走査ファイル数が 0 でない', function (): void {
    foreach (SNS_CERT_CONTRACT_ROOTS as $root) {
        expect(file_exists(base_path($root)))->toBeTrue("走査根が実在しません: {$root}");
    }

    $sources = snsCertContractSources();
    expect(count($sources))->toBeGreaterThan(0)
        ->and(array_keys($sources))->toContain(SNS_CERT_FETCHER_PATH)
        ->and(array_keys($sources))->toContain(SNS_CERT_VERIFIER_PATH);

    expect(count(snsCertAppSources()))->toBeGreaterThan(0);
});

test('C7: 読めないファイル / トークン化できない入力は未解決として失敗する', function (): void {
    expect(fn () => snsCertReadSource(base_path('app/Services/Mail/Sns/__missing__.php')))
        ->toThrow(RuntimeException::class);

    expect(fn () => snsCertTokens(''))->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// C1 / C13b: 取得口の唯一性と通信の原語 (クラス)
// ---------------------------------------------------------------------------

test('C1: 走査根で HTTP client を参照するファイルは証明書取得口ちょうど 1 件', function (): void {
    $referencing = [];
    foreach (snsCertContractSources() as $relative => $source) {
        if (snsCertMatchingReferences($relative, $source, SNS_CERT_HTTP_CLIENT_TYPES) !== []) {
            $referencing[] = $relative;
        }
    }

    // 空振り検知 (C6): 母集団が空のまま緑にならない
    expect($referencing)->not->toBeEmpty()
        ->and($referencing)->toBe([SNS_CERT_FETCHER_PATH]);
});

test('C13b: 走査根は通信の原語 (クラス) を参照しない', function (): void {
    foreach (snsCertContractSources() as $relative => $source) {
        expect(snsCertMatchingReferences($relative, $source, SNS_CERT_FORBIDDEN_CLASSES))
            ->toBe([], "{$relative} が通信の原語 (クラス) を参照しています");
    }
});

test('C13a: 走査根は通信の原語 (関数) を呼ばない', function (): void {
    foreach (snsCertContractSources() as $relative => $source) {
        expect(snsCertForbiddenFunctionCalls($source))
            ->toBe([], "{$relative} が通信の原語 (関数) を呼んでいます");
    }
});

test('C12: 走査根の参照位置に部分修飾名が無い (未解決を残さない)', function (): void {
    foreach (snsCertContractSources() as $relative => $source) {
        expect(snsCertUnresolvedQualifiedNames($source))
            ->toBe([], "{$relative} に解決できない部分修飾名があります");
    }
});

// ---------------------------------------------------------------------------
// C2: 時間の大小関係
// ---------------------------------------------------------------------------

test('C2: 証明書取得の予算は大小関係を満たす', function (): void {
    $connect = config()->integer(SNS_CERT_BUDGET_PREFIX.'connect_timeout_seconds');
    $request = config()->integer(SNS_CERT_BUDGET_PREFIX.'request_timeout_seconds');
    $post = config()->integer(SNS_CERT_BUDGET_PREFIX.'post_fetch_budget_seconds');
    $lockTtl = config()->integer(SNS_CERT_BUDGET_PREFIX.'lock_ttl_seconds');
    $cacheTtl = config()->integer(SNS_CERT_BUDGET_PREFIX.'cache_ttl_seconds');
    $maxBytes = config()->integer(SNS_CERT_BUDGET_PREFIX.'max_bytes');

    expect($connect)->toBeGreaterThan(0)
        ->and($request)->toBeGreaterThanOrEqual($connect)
        ->and($post)->toBeGreaterThan(0)
        ->and($request + $post)->toBeLessThanOrEqual($lockTtl)
        ->and($cacheTtl)->toBeGreaterThan(0)
        ->and($maxBytes)->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------------
// C3 / C4 / C5 / C10: 取得口の形
// ---------------------------------------------------------------------------

test('C3: 単一ロックキーと permit 1 が 1 対 1 で対応する', function (): void {
    expect(SnsCertificateFetcher::CERT_FETCH_PERMITS)->toBe(1);

    $contract = snsCertLockContract(
        SNS_CERT_FETCHER_PATH,
        snsCertReadSource(base_path(SNS_CERT_FETCHER_PATH)),
    );

    expect($contract['lockCalls'])->toBe(1)
        ->and($contract['firstArgumentOk'])->toBeTrue()
        ->and($contract['lockKeyConstants'])->toBe(1);
});

test('C4: 取得口は待たない (block の site を持たない)', function (): void {
    expect(snsCertBlockingWaitCount(snsCertReadSource(base_path(SNS_CERT_FETCHER_PATH))))->toBe(0);
});

test('C5: 走査根は TLS 検証を無効化していない', function (): void {
    foreach (snsCertContractSources() as $relative => $source) {
        expect(snsCertTlsDisablingSites($source))
            ->toBe([], "{$relative} が TLS 検証を無効化しています");
    }
});

test('C10: 取得の配線 (時間予算 / redirect 禁止 / 2xx 判定) が揃っている', function (): void {
    $wiring = snsCertFetchWiring(snsCertReadSource(base_path(SNS_CERT_FETCHER_PATH)));

    expect($wiring['connectTimeout'])->toBe(1)
        ->and($wiring['timeout'])->toBe(1)
        ->and($wiring['withoutRedirecting'])->toBe(1)
        ->and($wiring['throw'])->toBe(0)
        ->and($wiring['budgetKeys'])->toBe([
            SNS_CERT_BUDGET_PREFIX.'connect_timeout_seconds',
            SNS_CERT_BUDGET_PREFIX.'request_timeout_seconds',
        ]);
});

// ---------------------------------------------------------------------------
// C11: 昇格 site の唯一性
// ---------------------------------------------------------------------------

test('C11: 昇格は署名検証器の validate より後ろの 1 箇所だけ', function (): void {
    $sites = [];
    foreach (snsCertAppSources() as $relative => $source) {
        foreach (snsCertMethodCallLines($source, 'rememberVerified') as $line) {
            $sites[] = "{$relative}@L{$line}";
        }
    }

    expect($sites)->toHaveCount(1)
        ->and($sites[0])->toStartWith(SNS_CERT_VERIFIER_PATH.'@L');

    expect(snsCertPromotionOrderViolations(snsCertReadSource(base_path(SNS_CERT_VERIFIER_PATH))))
        ->toBe([]);
});

// ---------------------------------------------------------------------------
// C8: 検出器の自己検査 (負例)
// ---------------------------------------------------------------------------

test('C8: C1 / C13b は違反した合成入力を検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    use Illuminate\Support\Facades\Http;
    use GuzzleHttp\Client;
    final class Bad
    {
        public function fetch(string $url): string
        {
            return (new Client)->get($url)->getBody()->getContents().Http::get($url)->body();
        }
    }
    PHP;

    expect(snsCertMatchingReferences('synthetic.php', $source, SNS_CERT_HTTP_CLIENT_TYPES))
        ->toBe(['Illuminate\Support\Facades\Http']);
    expect(snsCertMatchingReferences('synthetic.php', $source, SNS_CERT_FORBIDDEN_CLASSES))
        ->toBe(['GuzzleHttp\Client']);
});

test('C8: C3 はロックキーが 2 本の合成入力を検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    use Illuminate\Support\Facades\Cache;
    final class Bad
    {
        private const string CERT_FETCH_LOCK_KEY = 'a';
        private const string OTHER_LOCK_KEY = 'b';
        public function run(): void
        {
            Cache::lock(self::CERT_FETCH_LOCK_KEY, 8)->get();
            Cache::lock(self::OTHER_LOCK_KEY, 8)->get();
        }
    }
    PHP;

    $contract = snsCertLockContract('synthetic.php', $source);

    expect($contract['lockCalls'])->toBe(2)
        ->and($contract['lockKeyConstants'])->toBe(2)
        ->and($contract['firstArgumentOk'])->toBeFalse();
});

test('C8: C3 は別名つき取り込み / 完全修飾形 / 未解決の受け手のロックも数える', function (): void {
    // ★短名の一致で判定していると、この 3 形はどれも「ロックは 1 本」に見えてしまう。
    $aliased = <<<'PHP'
    <?php
    namespace App\Example;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Cache as LockCache;
    final class Bad
    {
        private const string CERT_FETCH_LOCK_KEY = 'a';
        public function run(): void
        {
            Cache::lock(self::CERT_FETCH_LOCK_KEY, 8)->get();
            LockCache::lock('another-lock', 8)->get();
        }
    }
    PHP;

    $fullyQualified = <<<'PHP'
    <?php
    namespace App\Example;
    use Illuminate\Support\Facades\Cache;
    final class Bad
    {
        private const string CERT_FETCH_LOCK_KEY = 'a';
        public function run(): void
        {
            Cache::lock(self::CERT_FETCH_LOCK_KEY, 8)->get();
            \Illuminate\Support\Facades\Cache::lock('another-lock', 8)->get();
        }
    }
    PHP;

    $unresolvedReceiver = <<<'PHP'
    <?php
    namespace App\Example;
    use Illuminate\Support\Facades\Cache;
    final class Bad
    {
        private const string CERT_FETCH_LOCK_KEY = 'a';
        public function run(string $facade): void
        {
            Cache::lock(self::CERT_FETCH_LOCK_KEY, 8)->get();
            $facade::lock('another-lock', 8)->get();
        }
    }
    PHP;

    expect(snsCertLockContract('synthetic.php', $aliased)['lockCalls'])->toBe(2);
    expect(snsCertLockContract('synthetic.php', $fullyQualified)['lockCalls'])->toBe(2);
    expect(snsCertLockContract('synthetic.php', $unresolvedReceiver)['lockCalls'])->toBe(2);
});

test('C8: C4 は待つ実装の合成入力を検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    use Illuminate\Support\Facades\Cache;
    final class Bad
    {
        public function run(): void
        {
            Cache::lock('k', 8)->block(5);
        }
    }
    PHP;

    expect(snsCertBlockingWaitCount($source))->toBe(1);
});

test('C8: C5 は TLS 検証を切る 2 形を検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    use Illuminate\Support\Facades\Http;
    final class Bad
    {
        public function run(string $url): void
        {
            Http::withoutVerifying()->get($url);
            Http::withOptions(['verify' => false])->get($url);
        }
    }
    PHP;

    expect(snsCertTlsDisablingSites($source))->toHaveCount(2);
});

test('C8: C10 は配線を外した合成入力を検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    final class Bad
    {
        public function run(string $url): string
        {
            return $this->http->timeout(5)->get($url)->throw()->body();
        }
    }
    PHP;

    $wiring = snsCertFetchWiring($source);

    expect($wiring['connectTimeout'])->toBe(0)
        ->and($wiring['withoutRedirecting'])->toBe(0)
        ->and($wiring['throw'])->toBe(1)
        ->and($wiring['budgetKeys'])->toBe(['timeout:unresolved']);
});

test('C8: C11 は昇格 site を数え、宣言は数えない', function (): void {
    $declaration = <<<'PHP'
    <?php
    namespace App\Example;
    final class Fetcher
    {
        public function rememberVerified(string $pem): void {}
    }
    PHP;

    $callSite = <<<'PHP'
    <?php
    namespace App\Example;
    final class Verifier
    {
        public function verify(): void
        {
            $this->validator->validate($message);
            $this->certificates->rememberVerified($url, $pem);
        }
    }
    PHP;

    expect(snsCertMethodCallLines($declaration, 'rememberVerified'))->toBe([]);
    expect(snsCertMethodCallLines($callSite, 'rememberVerified'))->toHaveCount(1);
    expect(snsCertMethodCallLines($callSite, 'validate'))->toHaveCount(1);
});

test('C8: C11 は昇格が署名検証より前 / 2 件 / 署名検証なしの合成入力を検出する', function (): void {
    $promotedTooEarly = <<<'PHP'
    <?php
    namespace App\Example;
    final class Verifier
    {
        public function verify(): void
        {
            $this->certificates->rememberVerified($url, $pem);
            $this->validator->validate($message);
        }
    }
    PHP;

    $promotedTwice = <<<'PHP'
    <?php
    namespace App\Example;
    final class Verifier
    {
        public function verify(): void
        {
            $this->validator->validate($message);
            $this->certificates->rememberVerified($url, $pem);
            $this->certificates->rememberVerified($other, $pem);
        }
    }
    PHP;

    $withoutValidation = <<<'PHP'
    <?php
    namespace App\Example;
    final class Verifier
    {
        public function verify(): void
        {
            $this->certificates->rememberVerified($url, $pem);
        }
    }
    PHP;

    expect(snsCertPromotionOrderViolations($promotedTooEarly))->not->toBe([]);
    expect(snsCertPromotionOrderViolations($promotedTwice))->not->toBe([]);
    expect(snsCertPromotionOrderViolations($withoutValidation))->not->toBe([]);
});

test('C8: C12 は本体中の部分修飾参照と trait use の部分修飾名を検出する', function (): void {
    $body = <<<'PHP'
    <?php
    namespace App\Example;
    use Illuminate\Support\Facades;
    final class Bad
    {
        public function run(string $url): void
        {
            Facades\Http::get($url);
        }
    }
    PHP;

    $traitUse = <<<'PHP'
    <?php
    namespace App\Example;
    final class Bad
    {
        use Some\QualifiedTrait { m as alias; }
        public function run(): void {}
    }
    PHP;

    expect(snsCertUnresolvedQualifiedNames($body))->toBe(['Facades\Http']);
    expect(snsCertUnresolvedQualifiedNames($traitUse))->toBe(['Some\QualifiedTrait']);
});

test('C8: C13a は通信の原語の 4 形を検出する', function (): void {
    $plain = <<<'PHP'
    <?php
    namespace App\Example;
    final class Bad
    {
        public function run(string $url): string { return file_get_contents($url); }
    }
    PHP;

    $fullyQualified = <<<'PHP'
    <?php
    namespace App\Example;
    final class Bad
    {
        public function run(string $url): string { return \file_get_contents($url); }
    }
    PHP;

    $relative = <<<'PHP'
    <?php
    namespace App\Example;
    final class Bad
    {
        public function run(string $url): string { return namespace\file_get_contents($url); }
    }
    PHP;

    $aliased = <<<'PHP'
    <?php
    namespace App\Example;
    use function file_get_contents as fetchCertificate;
    final class Bad
    {
        public function run(string $url): string { return fetchCertificate($url); }
    }
    PHP;

    expect(snsCertForbiddenFunctionCalls($plain))->toHaveCount(1);
    expect(snsCertForbiddenFunctionCalls($fullyQualified))->toHaveCount(1);
    expect(snsCertForbiddenFunctionCalls($relative))->toHaveCount(1);
    expect(snsCertForbiddenFunctionCalls($aliased))->toHaveCount(1);
});

test('C8: グループ use の内側の関数 import は未解決として落ちる', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    use App\Support\{function helper, Thing};
    final class Bad
    {
        public function run(): void {}
    }
    PHP;

    expect(fn () => snsCertForbiddenFunctionCalls($source))->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// C9: 検出器の自己検査 (正例)
// ---------------------------------------------------------------------------

test('C9: C5 は正当な verify 呼び出しと verify => true を違反にしない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    use Illuminate\Support\Facades\Http;
    final class Good
    {
        public function run(string $url): void
        {
            $this->verifier->verify($message);
            Http::withOptions(['verify' => true])->get($url);
        }
    }
    PHP;

    expect(snsCertTlsDisablingSites($source))->toBe([]);
});

test('C9: C5 / C13a の語彙は接頭辞つき・打ち消しつき・接尾辞つきを違反にしない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    final class Good
    {
        public function run(string $url): void
        {
            $this->client->myWithoutVerifying();
            $this->client->notWithoutVerifying();
            $this->client->withoutVerifyingSomething();
            myfile_get_contents($url);
            not_file_get_contents($url);
            file_get_contentsX($url);
        }
    }
    PHP;

    expect(snsCertTlsDisablingSites($source))->toBe([]);
    expect(snsCertForbiddenFunctionCalls($source))->toBe([]);
});

test('C9: C13a はメソッド名としての同名呼び出しを違反にしない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    final class Good
    {
        public function run(): void
        {
            $obj->file_get_contents();
            $obj?->fopen();
            Reader::curl_init();
        }
        private function fopen(): void {}
    }
    PHP;

    expect(snsCertForbiddenFunctionCalls($source))->toBe([]);
});

test('C9: C12 は宣言・closure capture・完全修飾 trait use・絶対名を違反にしない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    use Illuminate\Support\Facades\Http;
    use App\Support\{Alpha, Beta};
    trait LocalTrait {}
    final class Good
    {
        use \App\Concerns\Imported { m as alias; }
        public function run(string $url): void
        {
            $cb = function (string $x) use ($url): string {
                return $x.$url;
            };
            Http::get($url);
            \App\Foo::bar();
            $cb($url);
        }
    }
    PHP;

    expect(snsCertUnresolvedQualifiedNames($source))->toBe([]);
});

test('C9: C10 は規定どおりの配線を違反にしない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    use Illuminate\Support\Facades\Config;
    final class Good
    {
        public function run(string $url): string
        {
            return $this->http
                ->connectTimeout(Config::integer('services.sns_certificate.connect_timeout_seconds'))
                ->timeout(Config::integer('services.sns_certificate.request_timeout_seconds'))
                ->withoutRedirecting()
                ->get($url)
                ->body();
        }
    }
    PHP;

    $wiring = snsCertFetchWiring($source);

    expect($wiring['connectTimeout'])->toBe(1)
        ->and($wiring['timeout'])->toBe(1)
        ->and($wiring['withoutRedirecting'])->toBe(1)
        ->and($wiring['throw'])->toBe(0)
        ->and($wiring['budgetKeys'])->toBe([
            'services.sns_certificate.connect_timeout_seconds',
            'services.sns_certificate.request_timeout_seconds',
        ]);
});

test('C9: C3 は規定どおりのロック 1 本を違反にしない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    use Illuminate\Support\Facades\Cache;
    final class Good
    {
        public const int CERT_FETCH_PERMITS = 1;
        private const string CERT_FETCH_LOCK_KEY = 'sns:cert:fetch';
        public function run(): void
        {
            $lock = Cache::lock(self::CERT_FETCH_LOCK_KEY, 8);
        }
    }
    PHP;

    $contract = snsCertLockContract('synthetic.php', $source);

    expect($contract['lockCalls'])->toBe(1)
        ->and($contract['firstArgumentOk'])->toBeTrue()
        ->and($contract['lockKeyConstants'])->toBe(1);
});

test('C9: C3 は同名の別クラスの静的呼び出しを数えない', function (): void {
    // ★別名つき取り込みで `Cache` という短名が別のクラスを指す形。完全修飾名で突き合わせて
    //   いれば数えないが、短名一致だと誤検出になる。
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    use App\Support\OwnCache as Cache;
    final class Good
    {
        public function run(): void
        {
            Cache::lock('unrelated', 8);
        }
    }
    PHP;

    expect(snsCertLockContract('synthetic.php', $source)['lockCalls'])->toBe(0);
});

test('C9: C11 は規定どおりの順序を違反にしない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    final class Verifier
    {
        public function verify(): void
        {
            $this->validator->validate($message);
            $this->certificates->rememberVerified($url, $pem);
        }
    }
    PHP;

    expect(snsCertPromotionOrderViolations($source))->toBe([]);
});

test('C9: C1 / C13b は対象外のクラス参照を違反にしない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Example;
    use Illuminate\Http\Client\Response;
    use Illuminate\Support\Facades\Cache;
    use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
    final class Good
    {
        public function run(Response $response): void
        {
            Cache::get('k');
            $status = SymfonyResponse::HTTP_OK;
        }
    }
    PHP;

    expect(snsCertMatchingReferences('synthetic.php', $source, SNS_CERT_HTTP_CLIENT_TYPES))->toBe([]);
    expect(snsCertMatchingReferences('synthetic.php', $source, SNS_CERT_FORBIDDEN_CLASSES))->toBe([]);
});

```


### `tests/Feature/Mail/SnsCertificateFetcherTest.php` (修正後の全文)

```php
<?php

declare(strict_types=1);

use App\Services\Mail\Sns\SnsCertificateFetcher;
use App\Services\Mail\Sns\SnsCertificateUrl;
use App\Services\Mail\Sns\SnsSignatureInvalidException;
use App\Services\Mail\Sns\SnsVerificationUnavailableException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kent013\SsrfPin\UrlSafetyInspector;
use Tests\Support\SnsTestData;

/*
 * SNS 証明書取得口 (App\Services\Mail\Sns\SnsCertificateFetcher) の振る舞い。
 *
 * ★リクエストオプション (接続 / 全体 timeout / TLS 検証) は Laravel の `Http::fake()` からは
 *   観測できないため、その配線は tests/Architecture/SnsCertificateFetchContractTest.php の
 *   C10 / C5 が字句で固定する。ここでは扱わない。
 * ★キャッシュ / ロックの障害は **本物の保管方式を実際に壊して**再現する
 *   (`useBrokenSnsCertificateCacheStore()` の docblock 参照)。
 *   - `Illuminate\Contracts\Cache\Store` を自前実装しない — 受け手型・保管先型の実装宣言は
 *     CachePayloadPlainDataGateTest の L4d が実行時層の 2 本だけに固定しているためである
 *   - `Cache::swap()` で manager ごと差し替えることもしない — 実行時層
 *     (`Tests\Support\Cache\PlainDataCacheGuard`) の受け皿を経由しなくなり、
 *     キャッシュ素データ規約 (セキュリティ不変条件 11) の被覆がこのテストだけ消えるためである
 */

beforeEach(function (): void {
    // ★テスト専用の array store へ既定を切り替える (前のテストの実体は捨てる)。
    //   `Cache::flush()` は使わない — store 全体を消すので rate limiter・lock・
    //   他テストの値まで巻き添えにする。
    useFreshSnsCertificateCacheStore();
    bindSnsDnsResolver(['203.0.113.10']);
});

function snsCertUrl(string $url = SnsTestData::CERT_URL): SnsCertificateUrl
{
    return SnsCertificateUrl::fromString($url);
}

function snsCertFetcher(): SnsCertificateFetcher
{
    return app(SnsCertificateFetcher::class);
}

function snsCertCacheKey(string $url = SnsTestData::CERT_URL): string
{
    return SnsCertificateFetcher::CACHE_PREFIX.hash('sha256', $url);
}

/**
 * **本物の保管方式を実際に壊して**既定に据える (guard 付き受け皿はそのまま維持する)。
 *
 * 値の表 / ロックの表を作るか作らないかで、キャッシュ読み書きの失敗とロック取得の失敗を
 * **別々に**再現できる (database driver は値とロックで別の表を使うため)。
 *
 * ★接続は**テスト専用の sqlite in-memory** にする。本番のテスト DB (pgsql) 上で
 *   存在しない表を引くと、その瞬間に外側の transaction が abort し (`RefreshDatabase`)、
 *   後続の DB 操作がすべて別の理由で失敗して検証にならないためである。
 * ★`Cache` facade の差し替えは行わないので、実行時層の受け皿 (`PlainDataGuardedRepository`) が
 *   この store の書き込みも従来どおり検査する。
 */
function useBrokenSnsCertificateCacheStore(bool $valueTableExists, bool $lockTableExists): void
{
    config(['database.connections.sns_cert_broken' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);
    DB::purge('sns_cert_broken');

    $connection = DB::connection('sns_cert_broken');
    if ($valueTableExists) {
        $connection->statement('create table cache (key varchar primary key, value text, expiration integer)');
    }
    if ($lockTableExists) {
        $connection->statement('create table cache_locks (key varchar primary key, owner varchar, expiration integer)');
    }

    config(['cache.stores.sns_cert_broken' => [
        'driver' => 'database',
        'connection' => 'sns_cert_broken',
        'lock_connection' => 'sns_cert_broken',
        'table' => 'cache',
        'lock_table' => 'cache_locks',
    ]]);
    config(['cache.default' => 'sns_cert_broken']);
}

test('F0 (正のコントロール): 正常系 fixture は SSRF 検査を通る', function (): void {
    // 境界 (config/ssrf-pin.php + vendor の deny CIDR) が変わったらここが最初に赤くなる。
    expect(app(UrlSafetyInspector::class)->inspect(SnsTestData::CERT_URL)->allowed)->toBeTrue();
});

test('F1: キャッシュに載っていれば取りに行かない', function (): void {
    Http::fake();
    Cache::put(snsCertCacheKey(), SnsTestData::certificatePem(), 3600);

    expect(snsCertFetcher()->cached(snsCertUrl()))->toBe(SnsTestData::certificatePem());

    Http::assertNothingSent();
});

test('F2: 昇格しなければキャッシュに載らない (要件 6 の負例)', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response(SnsTestData::certificatePem(), 200)]);

    $first = snsCertFetcher()->fetchSerialized(snsCertUrl());
    $second = snsCertFetcher()->fetchSerialized(snsCertUrl());

    expect($first->fromCache)->toBeFalse()
        ->and($second->fromCache)->toBeFalse();

    Http::assertSentCount(2);
});

test('F3: private IP に解決される host は恒久拒否 (403 系) で取りに行かない', function (): void {
    Http::fake();
    bindSnsDnsResolver(['10.0.0.5']);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertNothingSent();
});

test('F4: DNS 解決失敗は一時障害 (503 系) で取りに行かない', function (): void {
    Http::fake();
    bindSnsDnsResolver([]);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsVerificationUnavailableException::class);

    Http::assertNothingSent();
});

test('F5: PEM として読めない応答は恒久拒否でキャッシュに固定しない', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response('not a pem', 200)]);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsSignatureInvalidException::class);
    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertSentCount(2);
    expect(Cache::get(snsCertCacheKey()))->toBeNull();
});

test('F6: 応答サイズ超過は恒久拒否でキャッシュに固定しない', function (): void {
    config(['services.sns_certificate.max_bytes' => 16]);
    Http::fake([SnsTestData::CERT_URL => Http::response(SnsTestData::certificatePem(), 200)]);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsSignatureInvalidException::class);

    expect(Cache::get(snsCertCacheKey()))->toBeNull();
});

test('F7: HTTP エラー応答は一時障害 (503 系)', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response('', 500)]);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsVerificationUnavailableException::class);
});

test('F8: 接続失敗は一時障害 (503 系)', function (): void {
    Http::fake(fn () => throw new ConnectionException('boom'));

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsVerificationUnavailableException::class);
});

test('F9: プログラム不具合は写像せず伝播する (要件 7 の核)', function (): void {
    Http::fake(fn () => throw new LogicException('boom'));

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(LogicException::class);
});

test('F10: キャッシュ読みの例外は miss 扱いにする', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response(SnsTestData::certificatePem(), 200)]);
    // 値の表だけ無い = 読み書きは失敗するがロックは生きている状態。
    useBrokenSnsCertificateCacheStore(valueTableExists: false, lockTableExists: true);

    // 正のコントロール: 読みが本当に例外になる store であること (miss と区別する)
    expect(fn () => Cache::get(snsCertCacheKey()))->toThrow(QueryException::class);

    expect(snsCertFetcher()->cached(snsCertUrl()))->toBeNull();
    expect(snsCertFetcher()->fetchSerialized(snsCertUrl())->pem)->toBe(SnsTestData::certificatePem());
});

test('F11: キャッシュ書きの例外は握る (署名検証を止めない)', function (): void {
    useBrokenSnsCertificateCacheStore(valueTableExists: false, lockTableExists: true);

    // 正のコントロール: 書きが本当に例外になる store であること
    expect(fn () => Cache::put(snsCertCacheKey(), 'probe', 60))->toThrow(QueryException::class);

    // 昇格は best-effort。書けなくても署名検証は済んでいるので落とさない。
    expect(fn () => snsCertFetcher()->rememberVerified(snsCertUrl(), SnsTestData::certificatePem()))
        ->not->toThrow(QueryException::class);
});

test('F12: 読み戻せない値は forget して miss 扱いにする', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response(SnsTestData::certificatePem(), 200)]);
    Cache::put(snsCertCacheKey(), 'not a pem', 3600);

    expect(snsCertFetcher()->cached(snsCertUrl()))->toBeNull();
    expect(Cache::get(snsCertCacheKey()))->toBeNull();

    expect(snsCertFetcher()->fetchSerialized(snsCertUrl())->pem)->toBe(SnsTestData::certificatePem());
    Http::assertSentCount(1);
});

test('F13: ロック保持中の別要求は 503 系で自分では取りに行かない', function (): void {
    Http::fake();
    $held = Cache::lock('sns:cert:fetch', 10);
    expect($held->get())->toBeTrue();

    try {
        expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
            ->toThrow(SnsVerificationUnavailableException::class);
        Http::assertNothingSent();
    } finally {
        $held->release();
    }
});

test('F14: ロック取得後の再確認で hit したら fromCache で返し解放する', function (): void {
    Http::fake();
    Cache::put(snsCertCacheKey(), SnsTestData::certificatePem(), 3600);

    $certificate = snsCertFetcher()->fetchSerialized(snsCertUrl());

    expect($certificate->fromCache)->toBeTrue()
        ->and($certificate->pem)->toBe(SnsTestData::certificatePem());
    Http::assertNothingSent();

    // 解放されている (確認のために取った lock は必ず戻す)
    $probe = Cache::lock('sns:cert:fetch', 10);
    expect($probe->get())->toBeTrue();
    $probe->release();
});

test('F15: 排他非対応の保管方式は fail-fast (503 に化けさせない)', function (): void {
    Http::fake();
    // storage driver の StorageStore は LockProvider を実装しないため、
    // Repository::__call の素通しで「未定義メソッド呼び出し」の Error になる。
    config(['cache.stores.sns_cert_no_lock' => ['driver' => 'storage', 'disk' => 'local', 'path' => 'sns-cert-test']]);
    config(['cache.default' => 'sns_cert_no_lock']);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))->toThrow(Error::class);

    Http::assertNothingSent();
});

test('F16: ロック基盤の例外は 503 系 (排他なしの取得へ退避しない)', function (): void {
    Http::fake();
    // ロックの表だけ無い = 値の読み書きは生きているがロック取得が失敗する状態。
    useBrokenSnsCertificateCacheStore(valueTableExists: true, lockTableExists: false);

    // 正のコントロール: ロック取得が本当に例外になる store であること (競合と区別する)
    expect(fn () => Cache::lock('sns:cert:probe', 5)->get())->toThrow(QueryException::class);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsVerificationUnavailableException::class);

    Http::assertNothingSent();
});

test('F17: ロックは成功時も失敗時も解放される', function (array $fake, string $exception): void {
    Http::fake([SnsTestData::CERT_URL => $fake['response']]);

    try {
        snsCertFetcher()->fetchSerialized(snsCertUrl());
        expect($exception)->toBe('');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($exception);
    }

    $probe = Cache::lock('sns:cert:fetch', 10);
    expect($probe->get())->toBeTrue();
    $probe->release();
})->with([
    '成功' => [fn (): array => ['response' => Http::response(SnsTestData::certificatePem(), 200)], ''],
    'HTTP 失敗' => [fn (): array => ['response' => Http::response('', 500)], SnsVerificationUnavailableException::class],
    'PEM 不正' => [fn (): array => ['response' => Http::response('not a pem', 200)], SnsSignatureInvalidException::class],
]);

test('F18: 3xx を受理せず Location へ追従しない', function (): void {
    Http::fake([
        SnsTestData::CERT_URL => Http::response('', 302, ['Location' => 'https://evil.example/x.pem']),
    ]);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsVerificationUnavailableException::class);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === SnsTestData::CERT_URL);
});

test('F19: キャッシュ寿命は設定値どおり', function (): void {
    snsCertFetcher()->rememberVerified(snsCertUrl(), SnsTestData::certificatePem());

    // 移動前に載っていることを確かめる (別理由の null と区別する)
    expect(snsCertFetcher()->cached(snsCertUrl()))->toBe(SnsTestData::certificatePem());

    $this->travel(Config::integer('services.sns_certificate.cache_ttl_seconds') + 1)->seconds();

    expect(snsCertFetcher()->cached(snsCertUrl()))->toBeNull();
});

test('F20: URL が違えばキャッシュキーも違う', function (): void {
    $other = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-other99.pem';

    snsCertFetcher()->rememberVerified(snsCertUrl(), SnsTestData::certificatePem());

    expect(snsCertFetcher()->cached(snsCertUrl()))->toBe(SnsTestData::certificatePem())
        ->and(snsCertFetcher()->cached(snsCertUrl($other)))->toBeNull()
        ->and(snsCertCacheKey())->not->toBe(snsCertCacheKey($other));
});

test('F21: 共用ヘルパは専用 store だけを作り直す', function (): void {
    // ★共通の beforeEach が既にヘルパを呼んでいるので、「既定 store の目印が残る」形では
    //   検査にならない。**別名の store に目印を置いて**それが維持されることを確かめる。
    config(['cache.stores.sns_cert_sentinel' => ['driver' => 'array', 'serialize' => false]]);
    Cache::store('sns_cert_sentinel')->put('sentinel', 'kept', 60);
    Cache::put('discarded', 'value', 60);

    useFreshSnsCertificateCacheStore();

    expect(Cache::store('sns_cert_sentinel')->get('sentinel'))->toBe('kept')
        ->and(Cache::get('discarded'))->toBeNull();

    // 2 回目も専用 store だけが作り直される
    Cache::put('discarded', 'value', 60);
    useFreshSnsCertificateCacheStore();

    expect(Cache::store('sns_cert_sentinel')->get('sentinel'))->toBe('kept')
        ->and(Cache::get('discarded'))->toBeNull();
});

```


## 目録の変更 (tests/Architecture/CachePayloadPlainDataGateTest.php)

- L2 (書き込み経路) の `tests/Feature/Mail/SnsCertificateFetcherTest.php::put` を count 6 → 7。
- L3 (面) の同ファイルの rationale から `swap` の記述を落とした。
- それ以外 (`app/Services/Mail/Sns/SnsCertificateFetcher.php::put` / `tests/Pest.php`) は Round 1 のまま。

## 検証結果 (Round 2 時点)

- `composer test` (全体): 後述のとおり再実行して green を確認する
- 対象範囲の再実行: tests/Feature/Mail/ + tests/Unit/Mail/ + CachePayloadPlainDataGateTest +
  SnsCertificateFetchContractTest = 161 tests / 160 passed / 1 skipped (既存の skip) / 784 assertions、
  risky 0
- `composer phpstan`: No errors
- `vendor/bin/pint --test`: passed

Round 1 の 4 件の Warning すべてに対応した。再レビューし、全体判定を APPROVED か
CHANGES_REQUESTED の 1 語で書いてほしい。
