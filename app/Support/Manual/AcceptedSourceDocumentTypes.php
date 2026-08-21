<?php

declare(strict_types=1);

namespace App\Support\Manual;

/**
 * 受理する SourceDocument の形式の唯一の情報源。
 * config の静的な拡張子リストに画像拡張子 (jpg/jpeg/png) を加えた固定集合を返し、
 * FormRequest / Service / フロント Props の全てがここを経由することで、
 * 受理形式が 1 箇所で一貫する。
 *
 * 画像・スキャン SOP の OCR 対応 (旧 `manual.ocr_analysis_enabled` フラグ) は
 * オーナー決定により撤去済みで、画像受理は常時有効である
 * (経緯は docs/rollout-checklists.md 「画像・スキャン SOP の OCR 対応」節)。
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

        return [...$base, ...self::IMAGE_EXTENSIONS];
    }

    /** @return list<string> 内容 sniff MIME (SourceDocumentService::allowedMimeTypes 相当) */
    public static function mimes(): array
    {
        return [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/plain',
            ...self::IMAGE_MIMES,
        ];
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
     * 受理形式の人間向けラベル (法務確認を経た文面。FormRequest の 422 文言と
     * 作成画面の help 文言が共有する)。
     *
     * **機械導出しない**: 拡張子リストから日本語の文を組み立てる形にすると
     * config を触った副作用で文面が変わりうるため、承認済みの文をそのまま持つ。
     * 乖離は AcceptedSourceDocumentTypesTest の前提 pin (拡張子集合が現在値ちょうど) が検出する。
     */
    public static function formatsLabel(): string
    {
        return 'PDF・Excel・テキスト形式、または JPEG・PNG の画像';
    }
}
