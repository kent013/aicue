以下、**詳細設計のみ**を対象にレビューします（概念方針 D1〜D12 は承認済み前提）。

## 施策別判定

- 施策1（DBスキーマ）: **REQUEST_CHANGES**
- 施策2（Quota拡張）: **APPROVE**
- 施策3（ストレージ基盤）: **REQUEST_CHANGES**
- 施策4（upload-url発行）: **REQUEST_CHANGES**
- 施策5（テイク登録）: **REQUEST_CHANGES**
- 施策6（テイク管理）: **APPROVE**
- 施策7（routes/Controller/Policy）: **REQUEST_CHANGES**
- 施策8（sync）: **APPROVE**
- 施策9（S3掃除）: **REQUEST_CHANGES**
- 施策10（PWAフロント）: **REQUEST_CHANGES**

---

## 指摘（重要度付き）

### [Critical] `checksum_sha256` のカラム長とバリデーションが矛盾
- 該当: 施策1/4/5
- 問題: 設計文では「base64(32 bytes)」としつつ、`take_upload_reservations.checksum_sha256` は `string(64)`、Request は `size:44`。仕様不整合で truncation/保存失敗/比較不一致を誘発。
- 修正案:
  - どちらかに統一（推奨: **base64固定**）。
  - DB を `string('checksum_sha256', 44)` に変更。
  - コメントも “hex” 記述を除去して base64 に統一。

### [Critical] presign の有効期限指定が AWS SDK の期待型と不整合の恐れ
- 該当: 施策3
- 問題: `createPresignedRequest($command, $expiresAt->getTimestamp())` は実装依存で誤解を招く。DateTimeInterface/相対文字列を使う方が安全。
- 修正案:
  - `createPresignedRequest($command, $expiresAt)`（`CarbonImmutable`）に統一。
  - 併せて単体テストで期限文字列/署名クエリを固定検証。

### [Critical] route model binding だけでは `{manual}` が `{project}` 配下保証にならない可能性
- 該当: 施策7
- 問題: `scopeBindings()` は relation 名推論依存。`{manual}` が `VideoManual` のため `Project::manuals()` に確実一致する命名/実装前提を満たすか要確認。外れると IDOR 防御が崩れる。
- 修正案:
  - 既存方針どおり Controller/service で `project->manuals()->whereKey(...)->firstOrFail()` を必須化（既に多くで採用、全エンドポイントで徹底）。
  - `NestedRouteIdorDefenseTest` に「relation推論名が変わっても fail する」ケースを追加。

### [Warning] `TakeUploadReservation` に `organization()` relation がない
- 該当: 施策1/3
- 問題: orgキーを非正規化保持するのに relation 不在は可読性・保守性低下（型補助も弱い）。
- 修正案:
  - `organization(): BelongsTo<Organization, $this>` を追加。
  - PHPDoc `@property-read Organization $organization` を追加。

### [Warning] 予約claim後 `headObject` 失敗時の pending戻しで期限超過考慮が弱い
- 該当: 施策5
- 問題: claim直後に期限超過していても pending復帰し得る。再試行判定が曖昧。
- 修正案:
  - pending戻し前に `expires_at > now()` を再確認。
  - 期限切れなら `released` に遷移し 422（再取得促し）で統一。

### [Warning] `DeleteTakeObjectsJob` の重複path未除去
- 該当: 施策9
- 問題: 同一キーが複数回削除され得る（冪等だが無駄I/O）。
- 修正案:
  - dispatch前に `array_values(array_unique($paths))` を適用。

### [Warning] PWAのCSRF再取得が `window.location.pathname` GET 固定
- 該当: 施策10
- 問題: HTML応答が重く、失敗時ハンドリングが弱い。  
- 修正案:
  - 既存に軽量CSRF再発行エンドポイントがあればそれを使用。
  - なければ 419再試行回数・タイムアウト・ネットワークエラーを明示処理。

### [Suggestion] Quota加算のオーバーフロー防止
- 該当: 施策2
- 提案: `current > PHP_INT_MAX - addition` なら即超過扱いにするガードを追加（PHPStan観点でも意図が明確）。

### [Suggestion] `ScenarioWritePathInventoryTest` 検出4に「代入式」も含める
- 該当: 施策6
- 提案: 識別子検出だけでなく、`['adopted_take_id' => ...]` と `->adopted_take_id =` を個別に検出して抜け漏れ低減。

---

## レビュー観点サマリ

- 正確性: 中核フローは良好だが、**checksum表現統一**と**nested binding保証**は必須修正。
- 既存整合: DTO/Resource志向、Service責務分離は整合。
- PHPStan10: ほぼ適合。relation追加・型境界の明文化でさらに堅くなる。
- テスト計画: 全体的に高品質。Critical修正分のケース追加を推奨。
- セキュリティ: 設計姿勢は良い。IDOR防御の技術的担保をもう一段固定化すべき。
- UI規約: disabled禁止・DS/Lucide方針は準拠。

---

## 全体判定

**CHANGES_REQUESTED**

上記 Critical 3点（特に checksum と nested route担保）を解消すれば、Round 2 で **APPROVED** 相当です。