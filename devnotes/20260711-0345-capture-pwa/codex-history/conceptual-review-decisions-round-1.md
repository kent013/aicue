# 対応マトリクス: conceptual-review Round 1

## [Critical] D6 downloaded_at を署名 URL 発行時に打刻するのは事実の代理として弱い
- 判断: 対応する
- 根拠: 指摘どおり URL 発行は実 DL を保証せず、詳細画面を開いただけで削除不可になると再撮影・差し替えの中核 UX を壊す。
- 対応内容: D6 を「明示 ACK 方式」に全面改訂。専用エンドポイント `POST .../takes/{take}/downloaded` を追加し、クライアントが実ダウンロード完了後に ACK した時点で `downloaded_at` を打刻。ACK は冪等。端末単位の配信台帳（take_device_deliveries）は v1 では過剰設計として見送り（必要時に昇格）と明記。エンドポイント表・実装方針・ルート一覧に反映。

## [Warning] sync payload の cut_id が protected key 混入の温床
- 判断: 対応する
- 対応内容: 入力名を保護キーと別名の `cut` に変更（Category の `category` 入力名と同じ境界規約）。ネスト位置にも `takes.*.cut_id => missing` を張る。Service 入力は `CaptureSyncInput`（`list<ClientTakeFingerprint>`）の明示 DTO に固定（D8 改訂）。

## [Warning] Inertia と JSON の経路分離が曖昧 / PWA 専用 URL 空間
- 判断: 対応する（一部設計判断を明確化して維持）
- 対応内容: エンドポイント表を新設し「GET（画面）= Inertia、書き込み・sync = XHR JSON + Resource 名明示」の二分を明文化。URL 空間は `/app/...` プレフィックス自体が撮影 PWA 専用空間（PC 管理 UI は `/projects/...`）であり、§10.3 のパス形（.../manuals, .../cuts/{cut}/takes...）を保ったまま分離要求を満たすため `/capture/` セグメントの追加はしない（doc/10 確定仕様への忠実性を優先）。

## [Warning] IndexedDB blob 保持は iOS Safari で楽観的
- 判断: 対応する
- 対応内容: D9 を「即時アップロード優先」に改訂。オンライン時は録画確定後すぐアップロードし成功したら blob を保持しない。IndexedDB はオフライン/失敗時の一時バッファに限定。保存失敗時は再撮影/再選択導線へ、保持中サイズを UI に明示。

## [Warning] 「課金基盤の完成」は過大
- 判断: 対応する
- 対応内容: 期待効果を「保存容量の上限制御を構造的に導入」に修正し、「運用上の残課題」節（既存超過組織の扱い・監視・使用量表示 UI）を新設。

## [Warning] D10 project_member への adopt/delete 付与は広い
- 判断: 反論する（根拠を設計に追記）
- 根拠: doc/10 §10.5 の確定仕様が「撮影者 = take capture/upload/**adopt**」と明記。doc/05 の UX も撮影者が並べ替え（先頭=採用候補）・アップロード前の選択削除を行う前提。誤操作リスクは D6（DL 済み削除不可）+ D5（ready 前採用不可・ロック直列化）で抑制。capture_record/curate の 2 分割は確定仕様に対する過剰分割で、必要になれば Policy 局所変更で追加可能。
- 対応内容: D10 に上記根拠を明記。

## [Warning] QuotaService::check の size-1 補正は数式トリック
- 判断: 対応する
- 対応内容: `QuotaService::checkAddition(org, key, current, addition)`（`current + addition > limit` で例外）を QuotaService に追加する設計へ変更（D3 改訂）。「判定は QuotaService 経由のみ」の規約を維持しつつ呼び出し側のトリックを排除。

## [Warning] スコープが広い（A/B 分割の提案）
- 判断: 対応する（設計は一体・実装を分割）
- 根拠: チケット・Quota 予約・登録検証は相互依存で分割設計すると境界仕様が二重化する。一方、実装・検証の段階分けは有効。
- 対応内容: 実装方針に 4 段（A 基盤 / B API core / C 同期・掃除 / D PWA front）の incremental TODO 分割と各完了条件を明記。

## [Warning] 型安全性（DTO 未定義で連想配列化しやすい）
- 判断: 対応する
- 対応内容: D12 を新設し「Service は連想配列を受け取らない・返さない」を原則化、主要 DTO（TakeUploadTicketData / PresignedUploadData / ObjectMetadataData / TakeUploadInput / TakeRegistrationInput / CaptureSyncInput / CaptureManualDetailData ほか）を列挙。D11 に TakeObjectStorage の戻り値 DTO 固定を追記。

## [Suggestion] 成功条件の明文化
- 判断: 対応する
- 対応内容: 背景・課題に Round 1 成功条件（単一端末で cut 表示→録画→登録→採用が完結 + Quota/tenant/冪等のテスト担保）を明記。

## [Suggestion] JSON endpoint ごとの Resource 名の明記
- 判断: 対応する
- 対応内容: エンドポイント表に返却 Resource / props 型を明記。

## [Suggestion] render/preview/transcode を外す判断
- 判断: 現状維持（承認された判断）
