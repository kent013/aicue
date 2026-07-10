<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * reCAPTCHA siteverify が transport error / timeout / 5xx で判定不能だった場合に
 * fail-open しつつ監視へ上げるための例外。
 *
 * fail-open は UX 優先 (Google 側障害でユーザーの送信を止めない) だが、
 * spam 流入の見逃しを検知できるよう report() で監視に積む。
 */
class RecaptchaUnavailableException extends RuntimeException {}
