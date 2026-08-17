<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * コード到達カバレッジ (bug-hunt): app/ の実行された行/未到達行を bug-hunt 走行中のみ収集する観測器。
 *
 * 設計の honest 前提: 開発コンテナ (docker/Dockerfile) では pcov を使えるが、収集を有効にするのは
 * bug-hunt が serve を起動するときだけである。CI と本番でコード到達の収集を有効にする構成は
 * 本リポジトリに存在せず (CI の workflow に pcov の導入記述は無く、デプロイ定義そのものが無い)、
 * リポジトリの外にある本番構成がどうなっているかは分からない。よって拡張の有無に関わらず、
 * 設定 config('bughunt.pcov.enabled') (値の出所は env の BUGHUNT_PCOV) と
 * function_exists('\pcov\start') の **二重 guard** は必要であり、
 * どちらかが偽なら本 middleware は完全 no-op で安全である (handle は $next をそのまま返すだけ)。
 *
 * 役割分担:
 *  - handle:    per-request で pcov を初期化 (clear → start)。gate 内のみ。
 *  - terminate: pcov\collect → app/ 配下に限定 → covered/all 行集合を JSONL で追記。
 *               観測器が機能を壊さないよう全体を try/catch し、失敗は Log::warning のみ。
 *
 * 出力 (C4 merge_pcov.py が consume する契約・JSONL 追記、shard ごとに 1 ファイル):
 *   storage/bughunt-coverage/{run}-{shard}.json に 1 行 1 file:
 *     {"file":"app/Http/Controllers/...","covered":[12,13],"all":[12,13,14]}
 *   追記なので C4 が同一ファイルを union merge する。
 *
 * 主出力は uncovered (未到達) であり、covered%/line% は副 (gaming 防止)。本 middleware は
 * 生の covered/all を吐くだけで % は計算しない (集計は C4 に委ねる)。
 */
final class BughuntCoverageMiddleware
{
    /**
     * 設定 + function_exists の二重 guard。どちらか偽なら handle/terminate は完全 no-op。
     * 拡張が読み込まれていない実行環境では function_exists 側が常に false を返す。
     */
    public static function enabled(): bool
    {
        return (bool) config('bughunt.pcov.enabled', false)
            && function_exists('\pcov\start');
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::enabled()) {
            return $next($request);
        }

        // per-request 初期化。直前の残骸を clear してから start。
        // Codex R1 (handle も try/catch 化推奨) は不採用: \pcov\clear/\pcov\start は pcov stub で never-throw
        // 宣言のため try/catch は PHPStan L10 の dead catch (catch.neverThrown) になり、ignore 抑制も規約禁止。
        // かつ pcov の実行時失敗は fatal (catchable Throwable でない) ため try/catch は実効防御にならない。
        // よって型保証によりリクエストは壊れない。終端集計 (terminate) は file I/O 等を含むため別途 try/catch 済。
        \pcov\clear();
        \pcov\start();

        return $next($request);
    }

    /**
     * 応答送出後に pcov の結果を集計し、app/ 限定で JSONL を追記する。
     * 収集失敗で応答や後続リクエストを壊さないよう全体を try/catch。
     */
    public function terminate(Request $request, Response $response): void
    {
        $enabled = self::enabled();
        if (! $enabled) {
            return;
        }

        try {
            \pcov\stop();

            // \pcov\collect(\pcov\all) は到達ファイルの「全 executable 行」を line=>count で返す:
            //   count > 0  = covered (実行回数)
            //   count == -1 = executable だが未到達 (= 母数 all には入るが covered には入らない)
            // (pcov 1.0.12 / PHP 8.4 で実証)。filter 不要なので空 filter 経由の segfault も踏まない。
            // ※ collect は呼ぶとバッファを drain するため 1 回だけ呼ぶ (2 回目は空になる)。
            /** @var array<string, array<int, int>> $data */
            $data = \pcov\collect(\pcov\all);

            $appPrefix = self::appPrefix();
            $lines = self::buildLines($data, $appPrefix);
            if ($lines === '') {
                return;
            }

            $path = self::outputPath(self::runId($request), self::shardId($request));
            self::appendJsonl($path, $lines);
        } catch (Throwable $e) {
            // 観測器は機能を壊さない。収集失敗は warning のみで握りつぶす。
            Log::warning('bughunt coverage collection failed', [
                'message' => $e->getMessage(),
            ]);
        } finally {
            // collect が例外でも pcov の蓄積を次リクエストへ持ち越さない。
            // ここに到達する時点で enabled=true が保証される (enabled=false は冒頭 return)。
            // \pcov\clear() は拡張関数で throw しないため追加の try/catch は不要 (PHPStan: dead catch)。
            \pcov\clear();
        }
    }

    /**
     * pcov\collect(\pcov\all) の生結果 (絶対パス => [line => count]) を app/ 限定で JSONL 文字列に整形する。
     * pcov は到達ファイルの全 executable 行を返し、count>0=covered / count<0(=-1)=未到達。よって
     * covered = count>0 の行 / all = 返ってきた全行 (executable 行) となり all ⊋ covered。
     * これにより未到達行 (validation/権限/例外分岐) が初めて観測できる。
     * 「一度も到達しないファイル」は pcov 結果に現れない (= 静的 audit / 操作到達カバレッジの責務)。
     *
     * @param  array<string, array<int, int>>  $data  pcov\collect(\pcov\all) の生結果 (line => count)
     */
    public static function buildLines(array $data, string $appPrefix): string
    {
        $out = '';
        foreach ($data as $absFile => $lineMap) {
            if (! str_starts_with($absFile, $appPrefix)) {
                continue;
            }

            $all = [];
            $covered = [];
            foreach ($lineMap as $line => $count) {
                $lineNo = (int) $line;
                $all[] = $lineNo;          // pcov が返す行は executable (count>0 or -1)
                if ($count > 0) {
                    $covered[] = $lineNo;  // count>0 のみ covered
                }
            }
            if ($all === []) {
                continue;
            }
            sort($all);
            sort($covered);

            $relative = self::toRelative($absFile, $appPrefix);
            $row = json_encode(
                ['file' => $relative, 'covered' => $covered, 'all' => $all],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            if ($row === false) {
                continue;
            }
            $out .= $row."\n";
        }

        return $out;
    }

    /**
     * run/shard から出力ファイル名を組む純関数 (pcov 実体不要・単体テスト対象)。
     */
    public static function outputPath(string $runId, string $shardId): string
    {
        return storage_path('bughunt-coverage'.DIRECTORY_SEPARATOR.$runId.'-'.$shardId.'.json');
    }

    /**
     * app/ 配下判定に使う絶対 prefix (末尾 separator 付き)。
     */
    private static function appPrefix(): string
    {
        return base_path('app').DIRECTORY_SEPARATOR;
    }

    private static function toRelative(string $absFile, string $appPrefix): string
    {
        // app/ 配下を 'app/...' 相対 (C4 契約の表記) に正規化する。
        $tail = substr($absFile, strlen($appPrefix));

        return 'app/'.str_replace(DIRECTORY_SEPARATOR, '/', $tail);
    }

    private static function appendJsonl(string $path, string $lines): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // 並列 shard は別ファイルだが、同一 shard 内の per-request 追記が衝突しないよう LOCK_EX。
        file_put_contents($path, $lines, FILE_APPEND | LOCK_EX);
    }

    private static function runId(Request $request): string
    {
        return self::nonEmpty(config('bughunt.pcov.run'))
            ?? self::nonEmpty($request->header('X-Bughunt-Run'))
            ?? 'unknown-run';
    }

    private static function shardId(Request $request): string
    {
        return self::nonEmpty(config('bughunt.pcov.shard'))
            ?? self::nonEmpty($request->header('X-Bughunt-Shard'))
            ?? 'unknown-shard';
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
