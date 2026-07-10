<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 動画マニュアル / AI 解析の設定 (doc/10 §10.5 / §10.7 / §10.8)
|--------------------------------------------------------------------------
*/

return [
    // AI 解析 1 回のチケット消費 (doc/10 §10.5 COST_ANALYSIS)
    'analysis_ticket_cost' => 1,

    // LLM 出力 JSON の検証失敗時の有界リトライ回数 (§10.7-2。計 1+N 試行)
    'analysis_llm_max_retries' => 2,

    // LLM 入力上限 (UTF-8 bytes)。token budget 導出: context 200,000 - 出力予約 16,000
    // - 固定プロンプト 4,000 = 180,000 token。byte-fallback BPE では token 数 <= バイト数が
    // 安全側上界のため strlen で保証する (AnalysisTokenBudgetInvariantTest が算術を固定)
    'analysis_max_text_bytes' => 150_000,

    // 抽出テキストの実質空判定 (これ未満は「テキストを抽出できません」)
    'analysis_min_text_bytes' => 100,

    // stale ジョブ回復閾値 (分)。queued: dispatch 喪失、running: worker 異常終了
    'analysis_stale_after_minutes' => 30,

    // SOP アップロード上限 (bytes) と許可拡張子 (mime rule 用)
    'source_document_max_bytes' => 20 * 1024 * 1024,
    'source_document_mimes' => ['pdf', 'xlsx', 'xls', 'txt'],
];
