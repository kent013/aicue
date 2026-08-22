<?php

declare(strict_types=1);

namespace Tests\Support\Security;

use App\Enums\Mcp\ToolName;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * 機械が使う経路の**入口を全数抽出**する走査器 (家系裁定 AG-047 / 不変条件 I14)。
 *
 * ## 4 つの面と抽出方法
 *
 * | 面 | 抽出方法 | fail-closed |
 * |---|---|---|
 * | api | `Route::getRoutes()` の uri が `api/` で始まる **named route** | 母集団が空 |
 * | console | **application-defined の command だけ** (`App\Console\Commands\` 配下の具象 + `routes/console.php` の無名 command) | 走査根が不在 / 母集団が空 |
 * | filament | 対象 panel に属する **application-defined の構成要素全件** (Resource / Page / Widget) | **未知の構成種別で fail** |
 * | mcp | `App\Enums\Mcp\ToolName` の全ケース | enum が空 |
 *
 * ★**vendor の command / route は対象外**である。I14 の責務はアプリが書く経路であり、
 *   vendor の内部解決は保証範囲の外である (この構文について検出力を主張しない)。
 * ★入口の識別子は `面:名前` の形で安定させる (台帳のキーになる)。
 *
 * ## 保証しないもの (誇張しない)
 *
 * - 実行時に条件付きで登録される入口 (env で分岐する route / command) は、
 *   その条件が偽のときには**見えない**。
 * - Filament の構成種別は vendor 更新で増える。**未知種別は fail-closed** にしてあるので
 *   増えたことには気付けるが、増えた種別の中身は見ない。
 */
final class MachinePlaneEntryPoints
{
    /** application-defined command の名前空間接頭辞。 */
    private const string COMMAND_NAMESPACE = 'App\\Console\\Commands\\';

    /** `routes/console.php` に無名で書かれた command (vendor と区別できないため名指しする)。 */
    private const array INLINE_CONSOLE_COMMANDS = [
        'inspire',
        'billing:detect-orphan-billing-organizations',
    ];

    /** Filament で認める構成種別 (未知種別が現れたら fail-closed)。 */
    private const array KNOWN_FILAMENT_KINDS = ['resource', 'page', 'widget'];

    /**
     * 入口の識別子を昇順で返す。
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $entries = [
            ...self::apiEntries(),
            ...self::consoleEntries(),
            ...self::filamentEntries(),
            ...self::mcpEntries(),
        ];
        sort($entries);

        return array_values(array_unique($entries));
    }

    /** @return list<string> */
    public static function apiEntries(): array
    {
        $entries = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $name = $route->getName();
            if ($name === null) {
                continue;   // vendor が登録した無名 route (laravel/mcp の registrar)
            }
            $entries[] = 'api:'.$name;
        }

        if ($entries === []) {
            throw new RuntimeException('api 面の母集団が空です (uri が api/ の named route が 1 本もありません)');
        }

        return $entries;
    }

    /** @return list<string> */
    public static function consoleEntries(): array
    {
        $root = base_path('app/Console/Commands');
        if (! is_dir($root)) {
            throw new RuntimeException('走査根が存在しません: app/Console/Commands');
        }

        $entries = [];
        foreach (Artisan::all() as $name => $command) {
            $class = $command::class;
            if (! str_starts_with($class, self::COMMAND_NAMESPACE)) {
                if (! in_array($name, self::INLINE_CONSOLE_COMMANDS, true)) {
                    continue;   // vendor command
                }
            }
            $entries[] = 'console:'.$name;
        }

        if ($entries === []) {
            throw new RuntimeException('console 面の母集団が空です');
        }

        return $entries;
    }

    /** @return list<string> */
    public static function filamentEntries(): array
    {
        $panel = Filament::getPanel('admin');

        $entries = [];
        $unknown = [];
        $groups = [
            'resource' => $panel->getResources(),
            'page' => $panel->getPages(),
            'widget' => $panel->getWidgets(),
        ];
        foreach ($groups as $kind => $classes) {
            if (! in_array($kind, self::KNOWN_FILAMENT_KINDS, true)) {
                $unknown[] = $kind;

                continue;
            }
            foreach ($classes as $class) {
                if (! str_starts_with((string) $class, 'App\\')) {
                    continue;   // vendor 同梱の構成要素
                }
                $entries[] = 'filament:'.$kind.':'.$class;
            }
        }

        if ($unknown !== []) {
            throw new RuntimeException('Filament の未知の構成種別: '.implode(', ', $unknown));
        }
        if ($entries === []) {
            throw new RuntimeException('filament 面の母集団が空です');
        }

        return $entries;
    }

    /** @return list<string> */
    public static function mcpEntries(): array
    {
        $entries = [];
        foreach (ToolName::cases() as $tool) {
            $entries[] = 'mcp:'.$tool->value;
        }

        if ($entries === []) {
            throw new RuntimeException('mcp 面の母集団が空です');
        }

        return $entries;
    }
}
