<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Models\Project;
use App\Rules\SourceDocumentSizeLimit;
use App\Support\Manual\AcceptedSourceDocumentTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * VideoManual 作成。
 *
 * 入力名の境界: カテゴリ選択の入力名は保護キー (category_id) と別名の **category**
 * (値は category id、null 可 = 未分類)。category_id を直送すると missing rule で 422。
 * exists の project スコープは検証時点の保証であり、保存時は Service が
 * ロック済み project relation から再解決して associate する (二段構え)。
 */
class StoreVideoManualRequest extends FormRequest
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
        $project = $this->route('project');
        $projectId = $project instanceof Project ? $project->id : 0;

        return array_merge([
            'title' => ['required', 'string', 'max:200'],
            'category' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('project_id', $projectId),
            ],
            // SOP 同時アップロード (任意。multipart)。保存時は Service が内容 sniff で再判定する
            'document' => [
                'nullable',
                'file',
                'mimes:'.implode(',', AcceptedSourceDocumentTypes::extensions()),
                new SourceDocumentSizeLimit,
            ],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * mimes ルールの汎用文言を、現在受理している形式の案内へ差し替える
     * (画像・スキャン SOP の OCR 対応。`StoreSourceDocumentRequest` と同じ方針)。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $formats = AcceptedSourceDocumentTypes::imagesEnabled()
            ? 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'
            : 'PDF・Excel・テキスト形式';

        return [
            'document.mimes' => "対応していないファイル形式です。{$formats}でアップロードし直してください。",
        ];
    }
}
