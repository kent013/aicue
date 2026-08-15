<?php

declare(strict_types=1);

namespace App\Console\Commands\Bughunt;

use App\DataTransferObjects\Bughunt\InventoryRouteData;
use App\DataTransferObjects\Bughunt\InventoryScanData;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use RuntimeException;

/**
 * bug-hunt 目録の機械事実 (route 定義と画面題名) を JSON で標準出力へ書き出す。
 *
 * **面の判定・分類・除外は 1 つも持たない**。それらは生成器
 * (scripts/bug-hunt-inventory.py) の責務であり、同じ規則を 2 言語に置かない。
 * したがって本コマンドは名前の無い route も落とさずに**全 route** を出力する。
 */
final class InventoryScanCommand extends Command
{
    protected $signature = 'bughunt:inventory-scan';

    protected $description = 'bug-hunt 目録の機械事実 (route 定義と画面題名) を JSON で出力する';

    public function handle(Router $router): int
    {
        // 抽出条件: routes/web.php の debug route 登録条件と**同一の述語**。
        // 満たさない環境で走らせると母集合が黙って変わるため、標準出力には触れずに落とす
        // (条件を変えるときは routes/web.php と Feature テストの両方を直すこと)。
        if (! ($this->laravel->isLocal() || $this->laravel->runningUnitTests())) {
            // 理由は標準エラーへ。標準出力には 1 バイトも出さない (壊れた入力を後段へ渡さない)。
            $this->output->getErrorStyle()->error(
                '抽出条件を満たさない環境では走らせない (local もしくはテスト実行時のみ)'
            );

            return self::FAILURE; // = 1。生成器はこれを致命 (exit 2) へ写像する
        }

        $titles = $this->appTitles();

        $routes = [];
        // getRoutes() は Route[] を返す (反復子ではなく明示の配列取得を使う)。
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            $routes[] = new InventoryRouteData(
                name: $name,
                uri: $route->uri(),
                // 空文字を残すと list<non-empty-string> が成立しないので落とす。
                // 落とした結果 methods が空になる route は生成器の段 1 が致命として拾う。
                methods: $this->nonEmptyStrings($route->methods()),
                // gatherMiddleware() は**宣言のまま**を返す (group 名 `web` が残る)。
                // Router::gatherRouteMiddleware() は group を展開して `web` を消すので使わない。
                middleware: $this->nonEmptyStrings($route->gatherMiddleware()),
                action: $route->getActionName(),
                title: $name === null ? null : ($titles[$name] ?? null),
            );
        }

        $this->output->writeln(json_encode(
            (new InventoryScanData($routes))->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return self::SUCCESS;
    }

    /**
     * 画面題名 (config('seo.app_titles')) を route 名 → 題名の表として取り出す。
     *
     * config は mixed なので、境界でキー・値の型を検査してから DTO へ渡す。
     *
     * @return array<string, string>
     */
    private function appTitles(): array
    {
        $configured = config('seo.app_titles', []);
        if (! is_array($configured)) {
            throw new RuntimeException('config(seo.app_titles) が配列ではない');
        }

        $titles = [];
        foreach ($configured as $name => $title) {
            if (! is_string($name) || ! is_string($title)) {
                throw new RuntimeException('config(seo.app_titles) のキーと値は文字列であること');
            }
            $titles[$name] = $title;
        }

        return $titles;
    }

    /**
     * 文字列でない要素 (Closure 等) と空文字を落とす。
     *
     * PHPDoc だけで list<non-empty-string> を主張せず、ループで組み立てて推論を通す。
     *
     * @param  array<array-key, mixed>  $values
     * @return list<non-empty-string>
     */
    private function nonEmptyStrings(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }
}
