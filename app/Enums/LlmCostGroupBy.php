<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * コストレポートの集計軸 (閉じた語彙)。
 *
 * ★ ここが「集計層が知ってよい llm_call_logs の列」の**唯一の宣言点**である。
 *   列名リテラルを本 enum の外へ出さない (SQL へ素通しさせない型境界)。
 * ★ すべて素の列 GROUP BY とし、GROUP BY キーへ SQL 関数を適用しない (driver 差を持ち込まない)。
 *   既存 index を使えるかどうかは期間条件と実行計画に依存する (index 前提の設計にしない)。
 */
enum LlmCostGroupBy: string
{
    case PromptTemplate = 'prompt_template';   // どの段が
    case Model = 'model';                      // どのモデルが
    case Organization = 'organization';        // どの組織が
    case Subject = 'subject';                  // どの対象が (多態)

    /**
     * 集計キーを構成する列。
     *
     * @return non-empty-list<string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::PromptTemplate => ['prompt_template'],
            self::Model => ['model'],
            self::Organization => ['organization_id'],
            self::Subject => ['subject_type', 'subject_id'],
        };
    }

    /** `--group-by` オプションのヘルプ用 (語彙の列挙を文字列で二重管理しない)。 */
    public static function optionList(): string
    {
        return implode('|', array_map(static fn (self $case): string => $case->value, self::cases()));
    }
}
