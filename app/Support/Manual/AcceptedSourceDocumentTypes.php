<?php

declare(strict_types=1);

namespace App\Support\Manual;

/**
 * 受理する SourceDocument の形式の唯一の情報源 (画像・スキャン SOP の OCR 対応)。
 * config の静的な拡張子リストと `manual.ocr_analysis_enabled` フラグを合成し、
 * FormRequest / Service / フロント Props の全てがここを経由することで、
 * 画像受理の有効・無効が 1 箇所で一貫する。
 */
final class AcceptedSourceDocumentTypes
{
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    private const array IMAGE_MIMES = ['image/jpeg', 'image/png'];

    /** @return list<string> 拡張子 (FormRequest の mimes: ルール・フロント accept 属性用) */
    public static function extensions(): array
    {
        /** @var list<string> $base */
        $base = config()->array('manual.source_document_mimes');

        return self::imagesEnabled() ? [...$base, ...self::IMAGE_EXTENSIONS] : $base;
    }

    /** @return list<string> 内容 sniff MIME (SourceDocumentService::allowedMimeTypes 相当) */
    public static function mimes(): array
    {
        $base = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/plain',
        ];

        return self::imagesEnabled() ? [...$base, ...self::IMAGE_MIMES] : $base;
    }

    /**
     * フロント `<input accept>` 属性用の文字列 (拡張子のみ)。
     */
    public static function acceptAttribute(): string
    {
        $parts = array_map(static fn (string $ext): string => ".{$ext}", self::extensions());

        return implode(',', $parts);
    }

    /**
     * フロントの画像対応可否表示用 (accept 属性の文字列を解析して画像対応可否を
     * 判定させないための専用の真偽値)。
     */
    public static function imagesEnabled(): bool
    {
        return config()->boolean('manual.ocr_analysis_enabled');
    }

    /**
     * 受理形式の人間向けラベル (法務確認を経た文面。FormRequest の 422 文言と
     * 作成画面の help 文言が共有する)。
     *
     * **機械導出しない**: 拡張子リストから日本語の文を組み立てる形にすると
     * config を触った副作用で文面が変わりうるため、承認済みの 2 文をそのまま持つ。
     * 乖離は AcceptedSourceDocumentTypesTest の前提 pin (基底拡張子集合・
     * 画像拡張子集合が現在値ちょうど) が検出する。
     */
    public static function formatsLabel(): string
    {
        return self::imagesEnabled()
            ? 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'
            : 'PDF・Excel・テキスト形式';
    }
}
