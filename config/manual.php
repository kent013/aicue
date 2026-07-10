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

    // ── レンダ (doc/10 §10.5 / §10.8-1 / 概念設計 §9) ──────────────────
    'render_ticket_cost' => 3,                    // COST_RENDER (v1 固定。係数化は後続)
    'render_stale_after_minutes' => 30,           // running の stale 閾値
    'render_queued_stale_after_minutes' => 10,    // queued の短 SLA (編集ブロック最小化)
    'render_max_total_source_ms' => 1_200_000,    // 尺上限ソフトゲート (20 分)
    'render_default_take_duration_ms' => 60_000,  // duration_ms NULL テイクの保守的代用値
    'render_max_inflight_previews_per_org' => 3,  // org 同時 preview 上限
    'preview_placeholder_seconds' => 3,           // 採用テイク欠落 cut のプレースホルダ尺
    'render_resolution' => '1920x1080',
    'render_fps' => 30,
    'render_ffmpeg_binary' => env('RENDER_FFMPEG_BINARY', 'ffmpeg'),
    'render_ffprobe_binary' => env('RENDER_FFPROBE_BINARY', 'ffprobe'),
    'render_subtitle_font' => env('RENDER_SUBTITLE_FONT', 'Noto Sans CJK JP'),
    'render_playback_url_ttl_minutes' => 10,      // preview 再生 / DL 署名 URL の TTL
];
