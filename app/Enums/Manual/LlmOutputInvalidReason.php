<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * LLM 出力の検証失敗分類 (report ログで機械集計する。文字列 drift を型で防止)。
 *
 * ★**2 つの軸が同居する**。`SchemaViolation` 以外の 6 つは「読めなかった」の内側の細分で、
 *   `SchemaViolation` は「読めたが形が違う」という別の軸である (家系の正典 v1 の i5)。
 * ★区分の目的は**再試行の可否の分岐ではない**。可否は
 *   `AnalysisPipeline::isTransient()` が例外型 1 つで決めており (全区分 retryable)、
 *   区分は集計のためだけに存在する。
 */
enum LlmOutputInvalidReason: string
{
    /** 囲み (コードフェンス) の開きの印が 1 つも無い (素の JSON もここに落ちる) */
    case FenceAbsent = 'fence_absent';

    /** 採った囲みの外にもう 1 つ囲みの印がある (差し込みを受け取らない) */
    case FenceMultiple = 'fence_multiple';

    /** 囲みの中身が JSON として読めない / 値の後に余剰トークンがある */
    case SyntaxBroken = 'syntax_broken';

    /** 最上位が入れ物 (object / list) ではない (scalar / null / 空のブロック) */
    case TopLevelNotContainer = 'top_level_not_container';

    /**
     * 値が完結しないまま終端に達した = **切り詰めの推定**。
     *
     * ★これは**構造からの推定であって断定ではない** (正典 i6)。提供元が返す停止の理由の正本は
     *   `llm_call_logs.finish_reason` (`Prism\Prism\Enums\FinishReason` の値。失敗系は
     *   sentinel `'failed'`) であり、本区分はその列を書き換えない。値の綴りに `inferred` を
     *   含めるのは、記録を読む人が**断定と読み違えない**ようにするためである。
     */
    case ValueIncompleteInferred = 'value_incomplete_inferred';

    /** 値は完結したが閉じの囲みが無い (切り詰めと区別する) */
    case ClosingFenceAbsent = 'closing_fence_absent';

    /** 読めたが形が違う (必須キー欠落・型不一致・有界性違反・parent_no 不整合) */
    case SchemaViolation = 'schema_violation';

    /**
     * 例外へ渡す固定文。
     *
     * ★**応答本文を含めない** (正典 i9)。区分ごとの固定文だけを返し、応答の断片・
     *   `json_last_error_msg()` / `JsonException::getMessage()` は入れない
     *   (例外の `getMessage()` を記録や画面へ流す経路が将来生まれても本文が漏れない)。
     * ★`SchemaViolation` は呼び出し側が具体的な違反内容を渡すため、ここでは既定文としてだけ持つ。
     */
    public function detail(): string
    {
        return match ($this) {
            self::FenceAbsent => 'コードフェンスの開始記号が見つかりません',
            self::FenceMultiple => 'コードフェンスがブロックの外にもう 1 つあります',
            self::SyntaxBroken => 'コードフェンス内が JSON として読めません',
            self::TopLevelNotContainer => '最上位が object / array ではありません',
            self::ValueIncompleteInferred => '値が完結しないまま応答が終わっています (切り詰めの推定)',
            self::ClosingFenceAbsent => 'コードフェンスの終了記号が見つかりません',
            self::SchemaViolation => 'スキーマ違反です',
        };
    }
}
