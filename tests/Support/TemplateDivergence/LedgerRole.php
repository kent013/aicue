<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 指紋台帳の役割。検査の意味 (鮮度 / 突合) を切り替える単一の軸。
 *
 * - `template` = 提供元 (laravel-claude-template)。検査は「指紋台帳の鮮度」。
 * - `app` = 生成された子アプリ。検査は裁定 (3a)/(3b) の突合。
 *
 * 反転はテンプレートからのアプリ生成時に行う (feature `template-init-bootstrap` の担当)。
 */
enum LedgerRole: string
{
    case Template = 'template';
    case App = 'app';
}
