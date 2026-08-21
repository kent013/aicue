# 対応マトリクス: impl-review Round 1

## [Warning→実質 Critical] recipientEmailMatches=false でも organizationName を payload 送信 (組織名開示)
- 判断: 対応する
- 根拠: 設計 施策2 は「organizationName は不一致時も渡すが画面では一致時のみ表示」としていたが、
  Inertia の初期 payload / devtools から非受信者が組織名を読めるため、非開示要件と
  コメント・テスト名 (「組織名を出さない」) が payload 層で成立していない。正当な指摘。
- 対応内容:
  - Controller: `organizationName => $recipientEmailMatches ? $organization->name : null`。
  - Accept.svelte: Props `organizationName: string | null`。description の一致分岐でのみ参照 (不一致分岐は組織名を使わない)。
  - Feature T3: `->where('organizationName', null)` を追加し payload 層での非開示を回帰固定。

## [Suggestion] MemberRemovalAccessTest 冒頭の表が dashboard=200 を契約として書くが assertion していない
- 判断: 対応する
- 根拠: 表とテストの範囲を一致させる (説明過剰を避ける)。dashboard 200 は cheap に assert できる。
- 対応内容: T7 (自然除名・current=null) と T7b (stale current) に dashboard 200 の assertion を追加。

## [Suggestion] InvitationsAccept.test.ts は DOM 非表示のみで payload 機密性は保証しない
- 判断: 対応する (責務分離を明示)
- 根拠: Codex も「payload 機密性は Feature 側で」と述べており、上記 T3 の null assertion で Feature 側が担保する。
- 対応内容: Svelte テストはそのまま (DOM 表示契約)。payload 非開示は T3 が担保する旨をコメントで明示。

## OK 判定・追認
- DirectFetchInventory 非登録の判断は正当と追認された (型宣言だけでなく private + actor 行 + 権限非拡張が根拠。
  将来 visibility / 取得元が変われば再評価が要る、という留保も受け入れる)。
