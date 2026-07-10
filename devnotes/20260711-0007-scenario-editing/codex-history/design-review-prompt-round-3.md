# 詳細設計レビュー Round 3: Round 2 指摘への対応報告

Round 2 の全 Warning / Suggestion に対応した。差分は以下。再レビューし全体判定を出してほしい。

## 対応マトリクス（要約）

### [Warning] 施策2: groupBy の型崩れ → 対応
fromManual() の骨子を書き換え: `$cuts` に `Collection<int, Cut>`、`$grouped` に
`Collection<int, Collection<int, Cut>>` の PHPDoc を明示。default 引数 (`get(0, collect())`) を
やめ、型付き空 Collection 変数で `$grouped->get(0) ?? $empty` と受ける。Eloquent Collection →
base Collection は `toBase()` を明示。

### [Warning] 施策6: fetch reject が未処理 Promise → 対応
save() に catch を追加。ネットワーク断・419 回復 GET / 再試行 PUT の reject も同経路で捕捉し、
作業コピー保持のまま「通信に失敗しました」を表示（未処理 Promise を漏らさない）。

### [Warning] 施策6: 422 の shape 防御が未確定 → 対応
422 分岐を具体化: `{ errors: Record<string, string[]> }` を実行時 type guard
（isValidationErrors）で判別し、JSON 破損・期待外 shape は汎用エラーへフォールバック。

### [Warning] 施策7: 通信失敗経路の Vitest 未網羅 → 対応
「PUT の reject」「419 回復 GET の reject（retried フラグで多重 retry なし）」「422 body 不正の
フォールバック」を追加（すべて作業コピー保持を検証）。

### [Suggestion] 5 件（prepareForValidation の最小変更注記 / upsertCut コメント残骸 /
ManualServiceBoundaryTest 表記 / payloadSteps の新規生成 + snapshot clone / 型付き定数の維持）
→ すべて対応・設計へ反映済み。

## 改訂後の該当セクション抜粋

### 施策2 fromManual()（改訂後）
        // 1 パス整形: parent_cut_id で groupBy し O(n) で組み上げる (per-step where の O(n^2) 回避)。
        // PHPStan level 10: groupBy の戻りは型が崩れやすいため PHPDoc で明示し、
        // 空スコープも型付きの空 Collection で受ける (mixed 汚染防止)
        /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
        $cuts = $manual->cuts()->orderBy('sort_order')->get();
        /** @var \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, Cut>> $grouped */
        $grouped = $cuts->toBase()->groupBy(fn (Cut $cut): int => $cut->parent_cut_id ?? 0);
        /** @var \Illuminate\Support\Collection<int, Cut> $empty */
        $empty = new \Illuminate\Support\Collection();
        $steps = [];
        foreach ($grouped->get(0) ?? $empty as $step) {
            $points = ($grouped->get($step->id) ?? $empty)
                ->map(fn (Cut $cut): ScenarioPointData => ScenarioPointData::fromCut($cut))
                ->values()->all();
            $steps[] = ScenarioStepData::fromCut($step, $points);
        }

        return new self($manual->scenario_version, $steps);

### 施策3 prepareForValidation 注記（改訂後）
     * narration / subtitle_secondary の null → '' 正規化はここで行う (下書き途中の空セル許容)。
     * DTO / DB は非 null 文字列で統一 (Request と Service で責務を分散させない)。
     *
     * 注意: 正規化は「キーが存在し、かつ値が null の場合だけ」行う (array_key_exists 判定)。
     * キー欠落を '' で補完すると present ルールが無効化され、未知キー・保護キーを含む
     * 元配列を作り直すと missing ルールの検査対象が失われるため、既存配列への最小変更に留める。
     */
    protected function prepareForValidation(): void { /* steps.*(.points.*) の 2 キーのみ null→'' */ }

### 施策6 save() / handleResponse（改訂後）
async function save(): Promise<void> {
    if (saving) return; // 多重送信ガード (disabled にはしない。押下は受けて即 return)
    saving = true;
    errors = {};
    conflict = null;
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
        const body = (await res.json()) as ScenarioDocument;
        applySaved(body); // 確定 id 取り込み + version 更新 + スナップショット更新 + 成功トースト
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

### 施策7 Vitest（改訂後）
- ScenarioEditor.test.ts: 行追加/削除/▲▼/dirty（正規化比較）/EmptyState/保存成功で id 反映/
  409 で作業コピー保持 + バナー/419 は cookie 再取得後 1 回だけ自動リトライ/401 メッセージ +
  作業コピー保持/保存中の再押下は no-op（fetch 1 回のみ）
- 通信失敗経路: PUT の reject → 作業コピー保持 + 汎用エラー + 未処理 Promise なし /
  419 回復 GET の reject → 同経路・多重 retry なし / 422 body 不正 → 汎用エラーフォールバック

詳細設計全文は /workspace/devnotes/20260711-0007-scenario-editing/detailed-design.md（読み込み可）。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 残る指摘は [Critical] [Warning] [Suggestion] で分類し修正案を添える
- 日本語で出力
