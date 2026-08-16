/**
 * 静止画テイクのエンコード規約 (**この 3 値の唯一の所在**)。
 * シャッター経路とファイル正規化経路の両方がここから読む (component に直書きしない)。
 *
 * PHP config には置かない — サーバはこの 3 値をまったく使わず、サーバが強制するのは
 * capture.max_still_bytes (バイト数) だけである。使わない値を props で往復させると
 * 二重管理になる。既定値の出力は通常 1 MB 未満で max_still_bytes (16 MiB) に十分収まる。
 */
export const STILL_MAX_EDGE = 1920;
export const STILL_JPEG_QUALITY = 0.85;
export const STILL_CONTENT_TYPE = "image/jpeg";

/** 長辺 STILL_MAX_EDGE に収まる描画寸法 (縮小のみ。拡大はしない) */
export function fitWithinMaxEdge(
    width: number,
    height: number,
): { width: number; height: number } {
    const longest = Math.max(width, height);
    if (longest <= STILL_MAX_EDGE || longest === 0) return { width, height };
    const scale = STILL_MAX_EDGE / longest;
    return { width: Math.round(width * scale), height: Math.round(height * scale) };
}

/**
 * 任意の描画可能ソース (HTMLVideoElement / HTMLImageElement) を JPEG blob へ再エンコードする。
 *
 * **失敗は必ず `null` で返す (reject しない)**。`drawImage()` は tainted canvas 等で throw し、
 * `toBlob()` も実装によっては throw しうる。呼び出し側に `.catch()` を配って回ると必ず漏れるので、
 * **契約をこの 1 か所で閉じる** (canvas 2d 取得不可 / 寸法 0 / 例外 / toBlob が null = すべて null)。
 * 呼び出し側は null を見たら**原本を送らずエラー表示する**。
 */
export async function encodeStillJpeg(
    source: CanvasImageSource,
    naturalWidth: number,
    naturalHeight: number,
): Promise<Blob | null> {
    try {
        const size = fitWithinMaxEdge(naturalWidth, naturalHeight);
        if (size.width === 0 || size.height === 0) return null;
        const canvas = document.createElement("canvas");
        canvas.width = size.width;
        canvas.height = size.height;
        const context = canvas.getContext("2d");
        if (context === null) return null;
        context.drawImage(source, 0, 0, size.width, size.height);

        return await new Promise((resolve) => {
            try {
                canvas.toBlob((blob) => resolve(blob), STILL_CONTENT_TYPE, STILL_JPEG_QUALITY);
            } catch {
                resolve(null);
            }
        });
    } catch {
        return null;
    }
}

/**
 * ファイル選択で選ばれた画像を正規化する (再エンコード)。
 * - 断定できること: 出力 JPEG は **EXIF を持たない** ので、サーバ/ffmpeg 側で向きを解釈する
 *   必要が無い。寸法上限も同時に効く。
 * - 断定しないこと: 「<img> デコード時にブラウザが必ず EXIF 向きを適用する」とは書かない
 *   (デコード API とブラウザで差がある)。
 */
export function normalizeStillFile(file: File): Promise<Blob | null> {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        let settled = false;
        const finish = (value: Blob | null): void => {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            URL.revokeObjectURL(url);
            resolve(value);
        };
        const timer = setTimeout(() => finish(null), 5_000);
        image.onload = () => {
            // encodeStillJpeg は reject しない契約だが、二重に閉じる (未処理 rejection を残さない)
            void encodeStillJpeg(image, image.naturalWidth, image.naturalHeight)
                .then(finish)
                .catch(() => finish(null));
        };
        image.onerror = () => finish(null);
        image.src = url;
    });
}
