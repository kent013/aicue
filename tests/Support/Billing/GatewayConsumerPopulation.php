<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Support\QueuedJobPopulation;

/**
 * 「決済 gateway (`AutoRechargeGatewayInterface`) を注入される app クラス」の母集団を決める唯一の実装。
 *
 * ★判定は **constructor と全メソッドの引数型**に interface が現れることだけを見る
 *   (`QueuedJobPopulation` と同じ作法で `app/` を走査 → PSR-4 → `class_exists()` → Reflection)。
 *   gateway の**実装クラス** (`implements AutoRechargeGatewayInterface`) は
 *   「注入される側」ではないので母集団に入らない。
 * ★**走査の縮み**は gate の代表クラス検査で拾う (母集団が 0 件に落ちても green にならない)。
 */
final class GatewayConsumerPopulation
{
    /** @return list<class-string> */
    public static function classes(): array
    {
        $classes = [];
        foreach (QueuedJobPopulation::appPhpFiles() as $path) {
            $class = QueuedJobPopulation::classNameForPath($path);
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if (! self::injectsGateway($reflection)) {
                continue;
            }

            $classes[] = $reflection->getName();
        }

        sort($classes);

        return $classes;
    }

    /** @param ReflectionClass<object> $reflection */
    private static function injectsGateway(ReflectionClass $reflection): bool
    {
        $methods = $reflection->getMethods();
        $constructor = $reflection->getConstructor();
        if ($constructor instanceof ReflectionMethod) {
            $methods[] = $constructor;
        }

        foreach ($methods as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType && $type->getName() === AutoRechargeGatewayInterface::class) {
                    return true;
                }
            }
        }

        return false;
    }
}
