# 概念設計レビュー Round 2: 対応結果の再レビュー依頼

Round 1 の指摘に対する対応を以下に示します。改訂済み概念設計の全文は
/workspace/devnotes/20260711-0345-capture-pwa/conceptual-design.md を再読してください（ファイル読み込みは許可されています）。

## 対応マトリクス

### [Critical] D6 downloaded_at の打刻契機が弱い → 対応
- **明示 ACK 方式**へ全面改訂: `POST .../takes/{take}/downloaded` を新設し、クライアントが実 DL 完了後に ACK した時点で打刻（URL 発行時の打刻を廃止）。ACK は冪等。DELETE は downloaded_at 非 null のみ 422。未 ACK の採用中 take は削除可能（ロック tx 内で adopted_take_id null 化 + DB nullOnDelete FK が最終防波堤）。端末単位配信台帳は v1 過剰設計として見送りを明記。

### [Warning] sync の cut_id 混入リスク → 対応
- 入力名を `cut` に変更（既存の `category` 入力名と同じ「保護キーと別名」境界規約）+ ネスト位置に `takes.*.cut_id => missing`。Service 入力は `CaptureSyncInput`（`list<ClientTakeFingerprint>`）に固定。

### [Warning] Inertia/JSON 経路の曖昧さ → 対応（URL 空間は根拠を添えて維持）
- エンドポイント表を新設し「GET = Inertia ページ / 書き込み・sync = XHR JSON + Resource 名明示」の二分を明文化。
- `/capture/` セグメント追加はしない: `/app/...` プレフィックス自体が撮影 PWA 専用空間（PC 管理 UI は `/projects/...`）で URL 分離は達成済み。パス形は doc/10 §10.3 の確定仕様（.../manuals, .../cuts/{cut}/takes...）に忠実であることを優先。

### [Warning] IndexedDB blob 保持の楽観性 → 対応
- 「即時アップロード優先」に改訂: オンラインなら録画確定後すぐ送信、成功時は blob を保持しない。IndexedDB はオフライン/失敗時バッファ限定。保存失敗は再撮影/再選択導線へ。保持中サイズを UI 明示。

### [Warning] 「課金基盤の完成」過大 → 対応
- 「保存容量の上限制御を構造的に導入」へ修正 + 「運用上の残課題」節を新設（既存超過組織・監視・使用量表示 UI）。

### [Warning] D10 撮影者への adopt/delete → 反論（根拠明記）
- doc/10 §10.5 確定仕様が「撮影者 = take capture/upload/**adopt**」と明記、doc/05 UX も撮影者がテイク並べ替え（先頭=採用候補）・アップロード前の選択削除を行う前提。誤操作は D6 + ready 前採用不可 + ロック直列化で抑制。2 分割は確定仕様に対する過剰分割で、必要時に Policy 局所変更で追加可能。

### [Warning] QuotaService の size-1 トリック → 対応
- `QuotaService::checkAddition(org, key, current, addition)`（current + addition > limit で QuotaExceededException）を追加する設計へ変更。判定窓口の一元化規約は維持。

### [Warning] スコープ過大 → 対応（設計一体・実装 4 段分割）
- 実装方針に A 基盤 / B API core / C 同期・掃除 / D PWA front の incremental 分割と完了条件を明記。チケット・Quota・登録検証は相互依存のため設計自体は一体とする根拠も記載。

### [Warning] 型安全性 → 対応
- D12 新設: 「Service は連想配列を受けない・返さない」原則 + 主要 DTO 列挙（TakeUploadTicketData / PresignedUploadData / ObjectMetadataData / TakeUploadInput / TakeRegistrationInput / CaptureSyncInput / CaptureManualDetailData ほか）。TakeObjectStorage の戻り値も DTO 固定。

### [Suggestion] 成功条件明文化 / Resource 名明記 → いずれも対応（背景節・エンドポイント表に反映）

## 依頼

改訂後の概念設計を再レビューし、全体判定（APPROVED / CHANGES_REQUESTED）を出してください。残る Critical/Warning があれば修正提案を添えてください。日本語で出力してください。
