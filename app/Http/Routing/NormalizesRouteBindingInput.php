<?php

declare(strict_types=1);

namespace App\Http\Routing;

/**
 * CUSTOM_BINDER 分類の宣言用 marker。
 *
 * この interface 自体は挙動を強制しない (空 interface のため空実装でも通る)。
 * **入力正規化が実際に効いていることの正本は Feature テスト**
 * (tests/Feature/Routing/RouteBindingTypeConstraintTest の {organization} 異常系) である。
 *
 * 本 interface の役割は「この param は Route::pattern による宣言的制約を適用できず
 * ({organization} は {organization:slug} を併用するため)、binder が 22P02 / 22003 相当の
 * 入力を弾く責務を負う」という分類を型で表明することに限られる。
 */
interface NormalizesRouteBindingInput {}
