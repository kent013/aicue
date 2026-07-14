<?php

declare(strict_types=1);

namespace App\Http\Controllers\Testing;

use App\Http\Controllers\Controller;
use App\Services\Storage\Fakes\FakeObjectStore;
use App\Services\Storage\Fakes\FakeStorageChecksumMismatch;
use App\Services\Storage\Fakes\FakeStorageKey;
use App\Services\Storage\Fakes\FakeStorageOverCapacity;
use App\Support\FakeStorageGate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * fake storage の signed PUT 受け口 (実 S3 presigned PUT の emulation)。
 * gate 成立時のみ route 登録されるが、route cache 残存対策で実行時にも同一 predicate で再検証する。
 * response()->json() 不使用 (noContent / abort は DTO 規約対象外)。
 */
final class PutFakeStorageObjectController extends Controller
{
    public function __invoke(Request $request, FakeStorageGate $gate, FakeObjectStore $store): Response
    {
        abort_unless($gate->enabled(), 404); // route cache 残存対策の実行時再検証 (登録条件と同一 predicate)

        // signed パラメータ (署名済 = 改竄不能)
        $key = (string) $request->query('key');
        $signedChecksum = (string) $request->query('checksum');
        abort_if($key === '' || $signedChecksum === '', 400);
        // key プレフィックス最小検証 (署名前提でも多層防御。横断読取/書込面積を縮小)
        abort_unless(FakeStorageKey::isAllowed($key), 400);

        // checksum 三者一致の 1/2: 署名パラメータ == リクエストヘッダ (ヘッダ送信契約の検証)
        $header = $request->header('x-amz-checksum-sha256');
        abort_if(
            ! is_string($header) || ! hash_equals($signedChecksum, $header),
            400,
            'x-amz-checksum-sha256 ヘッダが署名 checksum と一致しません',
        );

        $contentType = (string) ($request->header('Content-Type') ?: 'application/octet-stream');
        $input = $request->getContent(asResource: true); // php://input ストリーム (未消費)

        try {
            // 3/3: 実 body の checksum == 期待値 (FakeObjectStore が担保)
            $store->storeStreamed($key, $input, $contentType, $signedChecksum);
        } catch (FakeStorageChecksumMismatch) {
            abort(400, 'アップロード内容が checksum と一致しません');
        } catch (FakeStorageOverCapacity) {
            abort(413, 'アップロードサイズが上限を超えています');
        }

        return response()->noContent(); // 204 = 実 S3 PUT 成功と同じ扱い (フロントは ok を見るだけ)
    }
}
