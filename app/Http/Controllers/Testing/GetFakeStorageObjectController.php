<?php

declare(strict_types=1);

namespace App\Http\Controllers\Testing;

use App\Http\Controllers\Controller;
use App\Services\Render\RenderObjectStorage;
use App\Services\Storage\Fakes\FakeObjectStore;
use App\Services\Storage\Fakes\FakeStorageKey;
use App\Support\FakeStorageGate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * fake storage の signed GET serve (実 S3 署名 GET の emulation)。Range 対応 (<video> シーク可)。
 * head() で「存在 + 完了 + content_type 取得」を一括判定する (sidecar 欠損=未完了は null→404。
 * 500 化しない)。破損 sidecar は fail-loud (RuntimeException→500) で検出する。
 */
final class GetFakeStorageObjectController extends Controller
{
    public function __invoke(Request $request, FakeStorageGate $gate, FakeObjectStore $store, RenderObjectStorage $disposition): BinaryFileResponse
    {
        abort_unless($gate->enabled(), 404);

        $key = (string) $request->query('key');
        abort_if($key === '', 400);
        abort_unless(FakeStorageKey::isAllowed($key), 400);

        $meta = $store->head($key);
        abort_if($meta === null, 404);

        $headers = ['Content-Type' => $meta->contentType ?? 'application/octet-stream'];
        $filename = $request->query('filename');
        if (is_string($filename) && $filename !== '') {
            // verbatim ではなく contentDisposition() で再生成 (ヘッダ注入面を作らない)
            $headers['Content-Disposition'] = $disposition->contentDisposition($filename);
        }

        // response()->file = BinaryFileResponse (Range 対応 = <video> シーク可)
        return response()->file($store->absolutePath($key), $headers);
    }
}
