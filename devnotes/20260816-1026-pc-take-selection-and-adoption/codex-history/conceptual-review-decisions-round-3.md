# 対応マトリクス: conceptual-review Round 3

## [Warning] アップロード成功後の partial reload 対象が props 構造と一致していない

- 判断: **対応する**
- 根拠: 指摘のとおり自分の書き間違い。D2 で `cut` と `takes` をトップレベルの別 props に
  確定したのに、D4 では `only: ['cut']` と書いていた。これでは新しいテイクが画面に出ない。
- 対応内容: D4 を `router.reload({ only: ['cut', 'takes'] })` に訂正
  (採用状態 `cut.adopted_take_id` も同じ再取得で更新するため両方を指定する)。
  必須成果物の Vitest に「アップロード成功後に `cut` と `takes` を partial reload する」を追加した。

## [Warning] 「制約・前提」の DTO 方針が D2 と矛盾している

- 判断: **対応する**
- 根拠: Round 2 で「既存 Capture DTO は合成しない」と決めたのに、
  「制約・前提」に Round 1 時点の記述 (`CaptureCutData` / `CaptureTakeData` の再利用) が
  残っていた。実装者はこちらを読む可能性があり、署名 URL 流入リスクが復活する。
- 対応内容: 「制約・前提」を専用 DTO 方針へ統一し、
  「`CaptureCutData` / `CaptureTakeData` は合成・再利用しない」と明記した。

## [Warning] PHP array shape と Svelte 側型の nullable 性を一致させる

- 判断: **対応する**
- 対応内容: D2 の公開 shape に**型注記**を入れ、
  `status` は文字列一般ではなく既存の `TakeStatus` union
  (`"uploading" | "processing" | "ready" | "failed"`)、
  `type` は `"step" | "point"` として扱うことを明記した。
  `subtitle_primary: string|null` / `subtitle_secondary: string` / `narration: string` は
  Cut モデルの実際の nullable 性 (`CaptureCutData` の phpdoc と同じ) に合わせる。

## [Suggestion] 使命・スコープ・polling 不採用・takeSummaries は解消

- 判断: **維持** (変更なし)
