<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * テイク (撮影素材) の状態 (doc/10 §10.1)。
 * フェーズ1では schema 先取りのみで遷移経路なし (撮影 PWA フェーズで配線)。
 */
enum TakeStatus: string
{
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
