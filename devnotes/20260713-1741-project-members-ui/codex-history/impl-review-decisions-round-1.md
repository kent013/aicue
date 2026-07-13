# 対応マトリクス: impl-review Round 1

## [Critical] `changeMemberRole` のグローバル送信ロックが別行の変更を無言で捨てうる
- 判断: 反論する（現行のグローバル直列ロックを維持）+ 一部対応（挙動を明示する回帰テストを追加）
- 根拠:
  1. **先人の知恵（既存流儀との一致）**: 本実装のグローバルロックは、詳細設計が明示的に参照する
     `resources/js/pages/Admin/Users.svelte` の `changeRole`（`changingRole` boolean の単一ロック）と
     同一セマンティクス。同ページはレビュー済み・出荷済みで同じ「別行の連続変更は直列化される」挙動を持つ。
     ここだけ独自の行単位並行制御を導入すると、レビュー済みパターンからの不整合な逸脱になる。
  2. **Inertia の visit キャンセル意味論から見て直列ロックの方が安全**: Inertia は同時 visit を
     基本的にキャンセルするため、行単位で並行 `router.post` を許すと**先行（実行中）の保存が
     後続にキャンセルされて失われる**。グローバルロックは実行中の 1 件を確定させ、後続を弾くため、
     少なくとも 1 件を決定論的に完了させる。これは「両方失う」より安全。
  3. **表示ずれは自己修復する**: store は `back()->with('success', ...)` を返し、成功時に Inertia が
     props を再取得して members を再描画するため、弾かれた行の native select も次の props refresh で
     サーバ真値に戻る（恒久的な desync は残らない）。error 時は `router.reload({ only: [...] })` で明示再同期。
  4. **禁止事項8 / 設計判断の尊重**: 詳細設計は「ロール select に disabled を付けない」を Codex design-review
     Round 1 Critical への対応として明文化している。in-flight での disabled 追加はこの確定済み設計判断に反する。
- 対応内容: グローバルロックは維持。挙動を「無言・未検証」から「文書化・検証済み」へ格上げするため、
  `tests/js/pages/ProjectsShow.test.ts` に「ロール変更処理中は次のロール変更を受け付けない (二重送信ガード)」
  テストを追加（連続 change で `router.post` が 1 回のみ・先行 payload が採用されることを固定）。

## [Warning] ロール変更の二重送信ガードを直接検証するテストがない
- 判断: 対応する
- 根拠: 退行検知として有効かつ低コスト（Codex の Critical 補強要望とも一致）。
- 対応内容: 上記の in-flight ガード回帰テストを追加（`postSpy` を onFinish 未発火 mock にして
  `changingRoleId` を張ったまま 2 連続 change → 1 回のみ送信を assert）。

## [Warning] `array_column(memberRows, 'id')` / Inertia serializer 変更に対して brittle
- 判断: 見送る（現状問題なし）
- 根拠: `memberRows` の shape は同一 Controller 内の PHPDoc（`list<array{id:int,...}>`）で固定され、
  S3 Feature テストが shape（id/name のみ・除外契約）を回帰固定している。Collection/array 差異は
  テスト helper（`assignableRows` / `emailVisibilityRows`）が吸収しており、実害は出ていない。
  将来 serializer を変える場合はテストが落ちて検知できるため、現時点での予防的複雑化は禁止事項6（不必要な複雑化）に反する。

## [Suggestion] 群
- 判断: 対応不要（肯定的所見）。設計一致・PHPStan 適合・Atomic/DS 準拠・禁止事項4/8 適合の確認であり変更不要。
