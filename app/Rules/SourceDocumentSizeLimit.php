<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * SOP アップロードの容量上限を判定する共通 Rule (画像・スキャン SOP の OCR 対応)。
 *
 * 画像 (image/) は画像専用の小さい上限 (`manual.source_document_image_max_bytes`)、
 * それ以外は既存の上限 (`manual.source_document_max_bytes`) を適用する。
 *
 * **判定材料はサーバー側の実バイト sniff 結果 (`UploadedFile::getMimeType()`) だけ**にする。
 * `getClientMimeType()` / `getClientOriginalExtension()` はクライアント申告であり
 * 偽装できるため、上限選択の材料にしない (JPEG バイトを `.pdf` にリネームして
 * 20MB 上限側へ迂回する攻撃を防ぐ)。
 *
 * 「画像かどうか」はファイルの実バイトの性質であり、受理可否そのもの
 * (`AcceptedSourceDocumentTypes`) とは別概念。ここでは受理可否の判定に依存しない
 * 固定の判定を使う (許可判定と容量分類の責務を混同しない。MIME の受理可否そのものは
 * `mimes:` ルールが担当し、本 Rule は「受理された後の容量分類」だけを担当する)。
 */
final class SourceDocumentSizeLimit implements ValidationRule
{
    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('ファイルを添付してください。');

            return;
        }

        // getMimeType() はサーバー側の実バイト sniff 結果 (finfo)。
        // 取得できなかった場合は fail-closed (画像扱いにしない = 緩い方の判定に倒さない)
        $mime = $value->getMimeType();
        if ($mime === null) {
            $fail('ファイルの形式を確認できません。');

            return;
        }

        $isImage = str_starts_with($mime, 'image/');
        $limit = $isImage
            ? config()->integer('manual.source_document_image_max_bytes')
            : config()->integer('manual.source_document_max_bytes');

        // getSize() の戻り値型は int|false (取得失敗を表現しうる)。int でなければ
        // fail-closed (上限内として扱わない)
        $size = $value->getSize();
        if (! is_int($size)) {
            $fail('ファイルサイズを確認できません。');

            return;
        }

        if ($size > $limit) {
            $fail($isImage
                ? '画像が大きすぎます。縮小するか、より小さいファイルでアップロードしてください。'
                : 'ファイルが大きすぎます。分割してアップロードしてください。');
        }
    }
}
