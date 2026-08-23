<?php

declare(strict_types=1);

namespace App\Enums\EnterpriseSso;

/**
 * token endpoint の client 認証方式。**body 漏洩面が小さい basic を優先する**。
 */
enum TokenEndpointAuthMethod: string
{
    case ClientSecretBasic = 'client_secret_basic';
    case ClientSecretPost = 'client_secret_post';
}
