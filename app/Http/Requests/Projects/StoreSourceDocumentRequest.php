<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Rules\SourceDocumentSizeLimit;
use App\Support\Manual\AcceptedSourceDocumentTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Webmozart\Assert\Assert;

/**
 * SOP (SourceDocument) の後付けアップロード (POST .../manuals/{manual}/source-documents)。
 * 追記型 immutable (差し替え = 新規行)。保護キー (video_manual_id 等) は missing で 422。
 * mime rule は拡張子ベースの入口検証で、保存時に Service が内容 sniff で再判定する (二段構え)。
 *
 * 許可拡張子・容量上限は `AcceptedSourceDocumentTypes` / `SourceDocumentSizeLimit`
 * (画像・スキャン SOP の OCR 対応) を単一の情報源にする。
 */
class StoreSourceDocumentRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'document' => [
                'required',
                'file',
                'mimes:'.implode(',', AcceptedSourceDocumentTypes::extensions()),
                new SourceDocumentSizeLimit,
            ],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * mimes ルールの汎用文言を、現在受理している形式の案内へ差し替える
     * (画像・スキャン SOP の OCR 対応。HEIC 等の非対応形式で「JPEG / PNG で保存し直す」
     * という次アクションを示す。受理形式はフラグに連動するため固定文言にしない)。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.mimes' => '対応していないファイル形式です。'
                .AcceptedSourceDocumentTypes::formatsLabel()
                .'でアップロードし直してください。',
        ];
    }

    /** validated('document') を UploadedFile へ narrow するヘルパ (mixed を返さない) */
    public function validatedDocument(): UploadedFile
    {
        $file = $this->validated('document');
        Assert::isInstanceOf($file, UploadedFile::class);

        return $file;
    }
}
