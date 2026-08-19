<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\VideoManualStatus;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\VideoManual;
use App\Support\Manual\AcceptedSourceDocumentTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * SOP (SourceDocument) の保存。追記型 immutable (更新・削除 API を持たない。
 * 差し替え = 新規行を追加。過去の analysis_jobs の参照と監査性を保つ。概念設計 §2)。
 *
 * - file_path はサーバ生成 (projects/{pid}/manuals/{mid}/source-documents/{ulid}.{ext})
 * - 専用 route 経由 (storeForManual) は VideoManual 行ロック + 状態 guard
 *   (analyze trigger と直列化)
 */
class SourceDocumentService
{
    /** 専用 route (POST .../source-documents)。draft/ready のみ許可 */
    public function storeForManual(Project $project, VideoManual $manual, UploadedFile $file): SourceDocument
    {
        return DB::transaction(function () use ($project, $manual, $file): SourceDocument {
            // 共有ロック規約: analyze trigger の「最新 document 選択」と直列化する
            /** @var VideoManual $locked */
            $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [VideoManualStatus::Draft, VideoManualStatus::Ready], true)) {
                // Inertia form 経路のため 422 (ValidationException) で返す (409 JSON は XHR 専用契約)
                throw ValidationException::withMessages([
                    'document' => ['解析中・書き出し中・公開済みのマニュアルには手順書を追加できません。'],
                ]);
            }

            return $this->appendDocument($locked, $file);
        });
    }

    /**
     * VideoManualService::create の tx 内から呼ぶ (新規 manual は競合なし・状態 guard 不要)。
     *
     * ファイル書き込みは行 insert より先。行 insert 失敗時は best-effort で即時削除し
     * 孤児ファイルの常態化を防ぐ (tx rollback 経路の残渣はストレージ Quota フェーズの掃除対象)。
     */
    public function appendDocument(VideoManual $manual, UploadedFile $file): SourceDocument
    {
        // サーバ側 MIME 再判定 (polyglot 対策): クライアント拡張子でなく内容 sniff
        // (getMimeType = finfo) が許可集合に含まれることを検証。不一致は 422
        $sniffedMime = $file->getMimeType();
        if ($sniffedMime === null || ! in_array($sniffedMime, self::allowedMimeTypes(), true)) {
            throw ValidationException::withMessages([
                'document' => ['対応していないファイル形式です (PDF / Excel / テキストのみ)。'],
            ]);
        }

        // 画像は 1 手順書につき 1 枚だけを受理する (画像・スキャン SOP の OCR 対応。
        // 概念設計 §入り口 1)。複数画像を束ねて 1 つの SOP として扱う機能はスコープ外なので、
        // 2 枚目以降は明示的に拒否する (暗黙に無視・別 SOP として黙って作成しない)。
        // 判定は storeForManual() が既に取っている VideoManual 行ロックの内側で行うため
        // (appendDocument は create() の tx 内、または storeForManual() の tx 内からのみ呼ばれる)、
        // 追加の競合対策は不要。
        if (str_starts_with($sniffedMime, 'image/')
            && $manual->sourceDocuments()->where('mime', 'like', 'image/%')->exists()) {
            throw ValidationException::withMessages([
                'document' => ['画像の手順書は 1 枚までです。複数ページの手順書は PDF でアップロードしてください。'],
            ]);
        }

        $size = $file->getSize();
        Assert::integer($size, 'アップロードファイルのサイズを取得できません');

        $extension = strtolower($file->getClientOriginalExtension());
        $path = sprintf(
            'projects/%d/manuals/%d/source-documents/%s.%s',
            $manual->project_id,
            $manual->id,
            (string) Str::ulid(),
            $extension,
        );
        Storage::putFileAs(dirname($path), $file, basename($path));
        try {
            $document = $manual->sourceDocuments()->make([
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $sniffedMime,
                'size_bytes' => $size,
            ]);
            $document->save();
        } catch (Throwable $exception) {
            Storage::delete($path); // best-effort (失敗しても rethrow を優先)

            throw $exception;
        }

        return $document;
    }

    /**
     * 許可 MIME (内容 sniff 値)。単一の情報源は `AcceptedSourceDocumentTypes`
     * (画像・スキャン SOP の OCR 対応。フラグに連動して画像 MIME を合成する)。
     *
     * @return list<string>
     */
    private static function allowedMimeTypes(): array
    {
        return AcceptedSourceDocumentTypes::mimes();
    }
}
