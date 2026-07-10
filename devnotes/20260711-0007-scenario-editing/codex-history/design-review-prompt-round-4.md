# 詳細設計レビュー Round 4: Round 3 指摘への対応報告

Round 3 の残指摘（Warning 1 件・Suggestion 1 件）に両方対応した。再レビューし全体判定を出してほしい。

## 対応マトリクス

### [Warning] save() 開始時の genericError 未クリア → 対応
save() 冒頭（errors / conflict クリアの並び）に `genericError = null` を追加。
Vitest に「失敗 → 再保存成功で旧 genericError が消える」ケースを追加。

### [Suggestion] 成功レスポンスの実行時検証 → 対応
`isScenarioDocument` type guard を追加。成功応答の JSON 破損・shape 不一致は汎用エラー
（再読み込み案内）へフォールバックし applySaved を呼ばない。Vitest に「成功応答の shape 不正」
ケースを追加。

## 改訂後の該当コード（抜粋）

async function save(): Promise<void> {
    if (saving) return; // 多重送信ガード (disabled にはしない。押下は受けて即 return)
    saving = true;
    errors = {};
    conflict = null;
    genericError = null; // 前回の失敗表示をクリア (再保存成功後に旧エラーを残さない)
    try {
        const res = await putScenario();
        await handleResponse(res);
    } catch {
        // ネットワーク断・fetch reject (419 回復 GET / 再試行 PUT の reject も含む)。
        // 作業コピーは保持したまま汎用エラーを表示 (未処理 Promise を漏らさない)
        genericError = "通信に失敗しました。接続を確認して再度お試しください。";
    } finally {
        saving = false;
    }
}

async function putScenario(): Promise<Response> {
    return fetch(`/projects/${projectId}/manuals/${manualId}/scenario`, {
        method: "PUT",
        headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-XSRF-TOKEN": csrfToken(),
            "X-Requested-With": "XMLHttpRequest",
        },
        credentials: "same-origin",
        body: JSON.stringify({ expected_version: version, steps: payloadSteps() }),
    });
}

async function handleResponse(res: Response, retried = false): Promise<void> {
    if (res.ok) {
        // 成功応答も実行時検証 (JSON 破損・期待外 shape は汎用エラーへフォールバック)
        const body = (await res.json().catch(() => null)) as unknown;
        if (isScenarioDocument(body)) {
            applySaved(body); // 確定 id 取り込み + version 更新 + スナップショット更新 + 成功トースト
            return;
        }
        genericError = "保存結果の取得に失敗しました。画面を再読み込みしてください。";
        return;
    }
    if (res.status === 419 && !retried) {
        // CSRF 失効: cookie を再取得して 1 回だけ自動リトライ (doc/10 §10.8-3 の共通処理方針)
        await fetch(window.location.pathname, { credentials: "same-origin", headers: { Accept: "text/html" } });
        await handleResponse(await putScenario(), true);
        return;
    }
    if (res.status === 401 || res.status === 419) {
        // セッション失効: 作業コピーは破棄せず、別タブでの再ログインを案内 (リダイレクトしない)
        genericError = "セッションが切れました。別のタブでログインし直してから、もう一度保存してください。";
        return;
    }
    if (res.status === 409) {
        const body = (await res.json().catch(() => null)) as ScenarioConflictBody | null;
        if (body?.code === "scenario_conflict") { conflict = body; return; } // 作業コピーは保持
    }
    if (res.status === 422) {
        // Laravel 標準 { errors: Record<string, string[]> } を実行時に判別。
        // JSON 破損・期待外 shape は汎用エラーへフォールバック (防御的パース)
        const body = (await res.json().catch(() => null)) as { errors?: unknown } | null;
        if (body !== null && isValidationErrors(body.errors)) {
            errors = body.errors; // "steps.0.points.1.scene" 形式のキーを行別セルに表示
            return;
        }
    }
    genericError = "保存に失敗しました。時間をおいて再度お試しください。";
}

/** Record<string, string[]> かを実行時検証する type guard */
function isValidationErrors(value: unknown): value is Record<string, string[]> { /* ... */ }

/** 成功応答 (scenario_version: number + steps 配列) の type guard */
function isScenarioDocument(value: unknown): value is ScenarioDocument { /* ... */ }

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 残る指摘は [Critical] [Warning] [Suggestion] で分類
- 日本語で出力
