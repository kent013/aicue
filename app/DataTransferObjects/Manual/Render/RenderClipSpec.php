<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Render;

/**
 * カット 1 枚分のクリップ仕様 (マニフェスト構成要素。version 固定の実体)。
 * 字幕本文は AssSubtitleWriter だけがファイルへ出力する (filtergraph へは入らない)。
 */
final readonly class RenderClipSpec
{
    public function __construct(
        public int $cutId,
        /** 手順N / 急所N-M (派生。エラー表示・ログ用) */
        public string $label,
        public RenderClipSource $source,
        /**
         * 素材の S3 キー (Placeholder は null)。
         * TakeStill には**画像**が入りうるため「動画のパス」という名前にしない (旧名 takeVideoPath)。
         * compose 側は種別で分岐せず、この 1 本を入力に取る
         */
        public ?string $takeSourcePath,
        /** TakeStill のみ (静止画の表示秒数) */
        public ?int $stillDisplaySeconds,
        public ?string $subtitlePrimary,
        public string $subtitleSecondary,
    ) {}
}
