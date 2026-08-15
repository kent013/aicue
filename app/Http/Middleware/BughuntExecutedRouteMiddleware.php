<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 実行済み route の記録 (bug-hunt): 「どの操作を実際に叩けたか」を走行中に機械記録する観測器。
 *
 * **位置が意味を持つ**: 本 middleware は web グループの末尾かつ bootstrap/app.php の
 * priority list の鎖の最後に置く。したがって handle() に到達したことは
 * 「認証・テナント境界 404・2FA 強制・メール検証・課金ゲート・退会凍結をすべて通過し、
 * controller 呼び出しの直前まで到達した」という機械的事実を意味する。
 * 上流のいずれかが短絡した要求は記録されず、その route は未実行のまま worklist に残る。
 *
 * **罠**: route middleware の terminate() は Kernel::terminateMiddleware が
 * gatherRouteMiddleware() の全件を回すため、**実際に handle() が走ったかに関係なく**呼ばれる。
 * よって handle() で request attribute に目印を立て、terminate() は目印があるときだけ書く。
 *
 * 出力 (coverage/build_executed.py が consume する契約・JSONL 追記、run×shard ごとに 1 ファイル):
 *   storage/bughunt-executed/{run}-{shard}.jsonl に 1 行 1 要求:
 *     {"run_id":"…","shard":"0","route_name":"projects.store","method":"POST",
 *      "path":"/organizations/1/projects","status":"ok","http_status":302}
 *   書き込みに失敗したら同ディレクトリの {run}-{shard}.error へ理由を追記する
 *   (生成器はこのマーカーを見つけたら終了コード 3 で落ちる = 部分欠測を静かに通さない)。
 */
final class BughuntExecutedRouteMiddleware
{
    /** handle() 到達の目印 (request-scoped。middleware インスタンスに状態を持たせない)。 */
    public const REACHED_ATTRIBUTE = 'bughunt.executed.reached';

    /** run / shard に許す書式 (出力パスの組み立てに入るため狭くする)。 */
    private const TOKEN_PATTERN = '/\A[A-Za-z0-9_.-]+\z/';

    /**
     * 二重の門。どちらか偽なら handle / terminate は完全 no-op。
     * (1) config('bughunt.executed.enabled') — env 既定 false
     * (2) production でない — 誤設定時の構造的な防壁
     * 加えて run / shard の書式検査を通らなければ無効とする。
     */
    public static function enabled(): bool
    {
        if (config('bughunt.executed.enabled') !== true) {
            return false;
        }
        if (app()->isProduction()) {
            return false;
        }

        return self::token('run') !== null && self::token('shard') !== null;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (self::enabled()) {
            // ここに到達した = 上流の遮断 middleware をすべて通過した。
            $request->attributes->set(self::REACHED_ATTRIBUTE, true);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! self::enabled()) {
            return;
        }
        if ($request->attributes->get(self::REACHED_ATTRIBUTE) !== true) {
            return; // 上流で短絡した = この route には到達していない
        }

        $run = self::token('run');
        $shard = self::token('shard');
        if ($run === null || $shard === null) {
            return; // enabled() で検査済みだが、型を絞るために再確認する
        }

        try {
            $line = self::buildLine($run, $shard, $request, $response);
            if ($line === null) {
                self::markFailure($run, $shard, 'json_encode failed');

                return;
            }
            self::append(self::outputPath($run, $shard), $line);
        } catch (Throwable $e) {
            // 観測器は機能を壊さない。応答は既に送出済み。
            Log::warning('bughunt executed-route capture failed', ['message' => $e->getMessage()]);
            self::markFailure($run, $shard, $e->getMessage());
        }
    }

    /**
     * 1 要求を JSONL の 1 行 (末尾改行込み) に組み立てる。json_encode 失敗時は null。
     */
    private static function buildLine(string $run, string $shard, Request $request, Response $response): ?string
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;

        /** @var array{run_id: string, shard: string, route_name: string|null, method: string, path: string, status: string, http_status: int} $row */
        $row = [
            'run_id' => $run,
            'shard' => $shard,
            'route_name' => is_string($name) && $name !== '' ? $name : null,
            'method' => $request->getMethod(),           // 常に大文字の HTTP method
            'path' => $request->getPathInfo(),
            'status' => self::classify($request, $response),
            'http_status' => $response->getStatusCode(),
        ];

        $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json."\n";
    }

    /**
     * ok / blocked の写像。判定不能は過小申告 (blocked) 側へ倒す。
     *
     *   2xx                              → ok
     *   3xx かつ session に errors がある → blocked (FormRequest 不合格。middleware より後に起きる)
     *   3xx (その他)                     → ok (controller が返した正常なリダイレクト)
     *   それ以外                          → blocked
     *
     * ここに到達している時点で認証・課金ゲート等はすべて通過済みなので、
     * 「ゲートに遮断された 302」と「正常な PRG」を状態コードで見分ける必要は無い。
     *
     * `errors` は「今回のリクエストで flash されたもの」だけが残る:
     * Store::save() が ageFlashData() を呼び、前リクエストの flash はここで忘れられる。
     * 保存は StartSession::handleStatefulRequest() が下流の応答を受け取った後、
     * **応答の巻き戻り中**に saveSession() を呼んで行う。記録器の terminate() は
     * その後 (Kernel の terminate 処理) に走るので、読む時点では世代更新済みである。
     * **framework の内部実装に依存する**ので、
     * 「直前に不合格 → 次のリクエストの成功 302 が ok」を Feature テストで固定する。
     */
    private static function classify(Request $request, Response $response): string
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return 'ok';
        }
        if ($status >= 300 && $status < 400) {
            if ($request->hasSession() && $request->session()->has('errors')) {
                return 'blocked';
            }

            return 'ok';
        }

        return 'blocked';
    }

    /** 出力先。env でパスを受け取らない (任意の場所へ書ける口を作らない)。 */
    public static function outputPath(string $run, string $shard): string
    {
        return storage_path('bughunt-executed'.DIRECTORY_SEPARATOR.$run.'-'.$shard.'.jsonl');
    }

    public static function failurePath(string $run, string $shard): string
    {
        return storage_path('bughunt-executed'.DIRECTORY_SEPARATOR.$run.'-'.$shard.'.error');
    }

    /** config から run / shard を取り、書式検査を通ったものだけ返す。 */
    private static function token(string $key): ?string
    {
        $value = config('bughunt.executed.'.$key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return preg_match(self::TOKEN_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * 1 行を 1 回の追記で書く (並行要求で行が混線しないよう LOCK_EX)。
     * 失敗したら失敗マーカーを残す。
     */
    private static function append(string $path, string $line): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('failed to append '.$path);
        }
    }

    /** best-effort。ここが書けない障害は検出できない (詳細設計の「保証しないもの」)。 */
    private static function markFailure(string $run, string $shard, string $reason): void
    {
        $path = self::failurePath($run, $shard);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            // append() より前に落ちた場合 (buildLine 失敗等) はディレクトリが無いことがある。
            @mkdir($dir, 0o775, true);
        }
        @file_put_contents($path, $reason."\n", FILE_APPEND | LOCK_EX);
    }
}
