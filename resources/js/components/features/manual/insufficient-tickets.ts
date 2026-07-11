import type { InsufficientTicketsBody } from "@/types/manual";

/**
 * 402 応答 body がチケット残高不足 (InsufficientTicketsResource 契約) かの type guard。
 * code 厳格一致で自分宛て応答のみ購入導線を出す (他エラーで誤表示しない)。
 * AnalysisPanel / RenderPanel 共通 (features/manual 内に閉じる)。
 */
export function isInsufficientTickets(body: unknown): body is InsufficientTicketsBody {
    return (
        body !== null &&
        typeof body === "object" &&
        (body as { code?: unknown }).code === "insufficient_tickets"
    );
}
