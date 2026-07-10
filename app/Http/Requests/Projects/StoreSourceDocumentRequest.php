<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Webmozart\Assert\Assert;

/**
 * SOP (SourceDocument) の後付けアップロード (POST .../manuals/{manual}/source-documents)。
 * 追記型 immutable (差し替え = 新規行)。保護キー (video_manual_id 等) は missing で 422。
 * mime rule は拡張子ベースの入口検証で、保存時に Service が内容 sniff で再判定する (二段構え)。
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
                'mimes:'.implode(',', $this->allowedExtensions()),
                'max:'.intdiv(config()->integer('manual.source_document_max_bytes'), 1024), // KB 単位
            ],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * 許可拡張子 (config manual.source_document_mimes) を list<string> へ narrow する。
     *
     * @return list<string>
     */
    private function allowedExtensions(): array
    {
        $mimes = config()->array('manual.source_document_mimes');
        Assert::allString($mimes);

        return array_values($mimes);
    }

    /** validated('document') を UploadedFile へ narrow するヘルパ (mixed を返さない) */
    public function validatedDocument(): UploadedFile
    {
        $file = $this->validated('document');
        Assert::isInstanceOf($file, UploadedFile::class);

        return $file;
    }
}
