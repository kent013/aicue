# 対応マトリクス: design-review Round 1

## [Critical] checksum_sha256 のカラム長 (64) とバリデーション (base64 44 文字) の不整合
- 判断: 対応する
- 対応内容: base64 固定で統一。migration を `string('checksum_sha256', 44)` に修正、コメントも「SHA-256 の base64 表現（44 文字固定）」へ統一（施策1）。Request の `size:44` / `Sha256Checksum`（デコード後 32 bytes）とも整合。

## [Critical] createPresignedRequest の期限指定が int timestamp（実装依存）
- 判断: 対応する
- 対応内容: `createPresignedRequest($command, $expiresAt)`（CarbonImmutable = DateTimeInterface）へ変更（施策3）。`TakeObjectStorageTest` で期限・署名クエリ・`x-amz-checksum-sha256` 署名ヘッダを実 SDK オブジェクトで固定検証（概念 Round 5 の指摘と統合済み）。

## [Critical] scopeBindings の relation 推論依存で {manual}∈{project} 保証が崩れうる
- 判断: 対応する（既存規約の明文化 + 二重防御の徹底）
- 根拠: relation 名は route param 推論と一致させる既存規約（VideoManual.php の manuals() 命名理由）で、フェーズ1 の manuals 系 route で実績あり。ただし単独依存はしない。
- 対応内容: routes コメントに「二重防御」を明記: 全書き込み Service は tx 内で `$project->manuals()->…->cuts()->…->takes()` の連鎖 firstOrFail 再解決を必須（施策4/5/6/8 の Service 実装が既にこの形）。挙動担保として capture 全 nested route の cross-parent 404 を実 HTTP で検証する Feature テストを施策7 テスト計画に追加（relation 推論名が変われば必ず fail して検出）。

## [Warning] TakeUploadReservation に organization() relation がない
- 判断: 対応する
- 対応内容: `organization(): BelongsTo<Organization, $this>` + PHPDoc を追加（施策1）。

## [Warning] claim 後 headObject 失敗時の pending 戻しに期限超過考慮がない
- 判断: 対応する
- 対応内容: pending 戻し前に `expires_at->isFuture()` を再確認。期限切れは released へ倒して 422（再取得促し）で統一（施策5 + テスト追加）。

## [Warning] DeleteTakeObjectsJob の重複 path 未除去
- 判断: 対応する
- 対応内容: dispatch 前に `array_values(array_unique($paths))`（施策9）。

## [Warning] CSRF 再取得が window.location.pathname の HTML GET
- 判断: 対応する
- 対応内容: 専用の軽量エンドポイント `GET /app/csrf-cookie`（204 no content。web group 通過で XSRF-TOKEN cookie 更新。仕様固定 endpoint のため body なし）を routes に追加（施策7）。http.ts は同 endpoint を使用し、再発行失敗（非 2xx / network error）は再試行せず元の 419 を返してキュー保留 + UI 通知へ（施策10）。

## [Suggestion] checkAddition のオーバーフローガード
- 判断: 対応する
- 対応内容: `current > PHP_INT_MAX - $addition` を超過扱いに（施策2）。

## [Suggestion] 検出 4 に代入式検出を追加
- 判断: 対応する
- 対応内容: 識別子出現検出に加え `['adopted_take_id' => ...]` 配列キーと `->adopted_take_id =` 代入を検出 2 と同型の token パターンで個別検出（施策6）。
