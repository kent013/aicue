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

    // LLM 呼び出しの有界リトライ回数 (§10.7-2。計 1+N 試行)。JSON 検証失敗と transient な
    // provider/connection 例外の両方に適用する (AnalysisPipeline::withBoundedRetry)
    'analysis_llm_max_retries' => 2,

    // AI 解析パイプライン全体の実時間 deadline (秒)。AnalysisPipeline::run() 入口を T0 とし、
    // 各 LLM 試行の「開始可否」だけを決めるソフト予算 (走行中の呼び出しは中断しない)。
    // 値 = 3 段 × prompt YAML の client_options.timeout (360s) = 全段にフル ceiling の
    // 1 回を許す最小値。ハード上限は RunManualAnalysis::$timeout (SIGALRM)。
    'analysis_deadline_seconds' => 1080,

    // LLM 入力上限 (UTF-8 bytes)。token budget 導出: context 200,000 - 出力予約 16,000
    // - 固定プロンプト 4,000 = 180,000 token。byte-fallback BPE では token 数 <= バイト数が
    // 安全側上界のため strlen で保証する (AnalysisTokenBudgetInvariantTest が算術を固定)
    'analysis_max_text_bytes' => 150_000,

    // 抽出テキストの実質空判定 (これ未満は「本文が短すぎます」。PDF の 0 バイトのみ unextractable)
    'analysis_min_text_bytes' => 100,

    // 抽出テキストが「日本語の手順書本文」と言えるかの下限 (空白を除く文字数に占める
    // かな/漢字/全角記号/半角カナの比率)。これ未満は LLM に渡さず insufficientJapaneseText。
    // v1 の原稿は日本語 (doc/08 §182 / config/app.php の locale=ja) であることが前提。
    // 導出 (devnotes/20260804-0900-sop-pdf-mojibake): 破損クラスの実測は 0.000 (glyph ノイズ /
    // 欧文) 〜 0.020 (SJIS 化け未修復) で誤受理側に 5 倍、正当な日本語 SOP は復元後 0.661 /
    // 型番を極端に詰めた対照でも 0.196 で誤拒否側に約 2 倍のマージンがある。
    // 誤拒否は運用ログ (reason=insufficient_japanese_text) で観測できるようにしてあり、
    // field データが出るまでこの値は動かさない。
    'analysis_min_japanese_ratio' => 0.10,

    // stale ジョブ回復閾値 (分)。queued: dispatch 喪失、running: worker 異常終了
    'analysis_stale_after_minutes' => 30,

    // ── シナリオ導入/総括カット (概念設計 §改善アイデア) ──────────────
    // 総括カットの要点再掲に載せる最大件数 (先頭から)。0 以下は builder が 1 件扱いに補正。
    'summary_recap_max_points' => 3,
    // 導入/総括の作業名補間で用いるタイトルの truncate 上限 (subtitle_primary=100 に収める)。
    'scenario_bookend_title_max_chars' => 60,

    // SOP アップロード上限 (bytes) と許可拡張子 (mime rule 用)
    'source_document_max_bytes' => 20 * 1024 * 1024,
    'source_document_mimes' => ['pdf', 'xlsx', 'xls', 'txt'],

    // ── 画像・スキャン SOP の OCR 対応 ──────────────────────────────
    // 画像受理 + PDF の OCR フォールバックは無条件で有効 (旧 rollout gate
    // `manual.ocr_analysis_enabled` はオーナー決定により撤去済み。
    // 経緯は docs/rollout-checklists.md 「画像・スキャン SOP の OCR 対応」節)。

    // 画像専用の容量上限 (既存の source_document_max_bytes とは別枠、より小さい値)。
    // 一次情報: Anthropic Vision ドキュメント (platform.claude.com/docs/en/build-with-claude/vision
    // 「Image limits and costs」、2026-08-19 参照)。API 直接利用時の 1 画像あたり上限は
    // base64 エンコード後 10MB。base64 は生バイトの約 4/3 なので生バイトの実上限は
    // 10MB * 3/4 ≈ 7.5MiB。マージンを取り 7MiB (7 * 1024 * 1024) を上限にする。
    'source_document_image_max_bytes' => 7 * 1024 * 1024,

    // 画像 1 辺の最大 px。一次情報: 同上ドキュメント「maximum dimensions per image are
    // 8000x8000 px」(request 全体の hard limit)。この値をそのまま送信前の上限に反映する
    // (provider の拒否を待たない)。
    'analysis_ocr_max_dimension' => 8000,

    // 画像の最大画素数。provider のドキュメントに画素数そのものの hard limit の記載は無い
    // (辺長の hard limit はあるが画素数は無い) ため、これは一次情報由来の hard limit ではなく
    // 自前の工学的な上限 (スマートフォン写真相当の解像度を上限にし、処理コストの見積りを
    // 現実的な範囲に保つ)。8,000,000 px (約 8MP)。
    'analysis_ocr_max_pixels' => 8_000_000,

    // PDF (OCR フォールバック) の最大ページ数。一次情報: Anthropic PDF support ドキュメント
    // (platform.claude.com/docs/en/build-with-claude/pdf-support、2026-08-19 参照)。
    // 1 request あたりの hard limit は 600 ページ (ただし context window が 1M token 未満の
    // モデルでは 100 ページ)。sop-extract-media.yaml が pin する claude-sonnet-4-5-20250929 は
    // 200k context (< 1M) のため hard limit は 100 ページ。token budget
    // (AnalysisTokenBudgetInvariantTest) にも収まる十分小さい値として 20 を既定にする。
    'analysis_ocr_max_pages' => 20,

    // ── レンダ (doc/10 §10.5 / §10.8-1 / 概念設計 §9) ──────────────────
    'render_ticket_cost' => 3,                    // COST_RENDER (v1 固定。係数化は後続)
    'render_stale_after_minutes' => 30,           // running の stale 閾値
    'render_queued_stale_after_minutes' => 10,    // queued の短 SLA (編集ブロック最小化)
    'render_max_total_source_ms' => 1_200_000,    // 尺上限ソフトゲート (20 分)
    'render_default_take_duration_ms' => 60_000,  // duration_ms NULL テイクの保守的代用値
    'render_max_inflight_previews_per_org' => 3,  // org 同時 preview 上限
    'preview_placeholder_seconds' => 3,           // 採用テイク欠落 cut のプレースホルダ尺
    // 静止画カットの表示秒 (cuts.static_display_seconds 未指定時)。
    // 編集画面の入力範囲 (1〜60) の内側に置く。env() は持たせない (運用で変える値ではない)
    'default_still_display_seconds' => 5,
    // ffmpeg / ffprobe の 1 回あたり heap 確保上限 (バイト)。画素数爆弾で worker を落とさない
    'ffmpeg_max_alloc_bytes' => 536_870_912,      // 512 MiB
    'render_resolution' => '1920x1080',
    'render_fps' => 30,
    'render_ffmpeg_binary' => env('RENDER_FFMPEG_BINARY', 'ffmpeg'),
    'render_ffprobe_binary' => env('RENDER_FFPROBE_BINARY', 'ffprobe'),
    'render_subtitle_font' => env('RENDER_SUBTITLE_FONT', 'Noto Sans CJK JP'),
    'render_playback_url_ttl_minutes' => 10,      // preview 再生 / DL 署名 URL の TTL
];
