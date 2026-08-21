# 対応マトリクス: design-review Round 2

## 施策1 (APPROVE) [Suggestion] dataset payload のドット記法考慮
- 判断: 対応する。現保護キーは全てトップレベルである前提を dataset コメント/テスト名に明示。

## 施策2 (APPROVE) [Suggestion] form.reset 時の selectedFileName 掃除
- 判断: 対応する。成功時は別画面遷移で不要だが、同一画面に残る送信経路が入るなら同時消去する旨を明記。

## 施策3 (APPROVE) [Suggestion] x3
- SourceDocumentUpload を変更ファイル一覧から除去 → 対応 (一覧から外し Factory を代わりに明記)。
- 日時 locale/timezone 契約 → 対応 (uploadedAt は ISO8601 固定、表示整形は Svelte、SSR 未配線ゆえ
  hydration ずれ無し、将来 SSR 時は明示指定と実装メモ)。
- Assert::notNull 後の型確定 → 対応 (CarbonInterface で isInstanceOf 絞り込み、型を緩めない)。

## 施策4 (REQUEST_CHANGES) [Warning] before-event テストの空振り
- 判断: 対応する
- 根拠: mock router.reload/Link が実際に before を発火する保証が無いと 0 件で green になる。
- 対応: mock router の全 visit 入口を共通 before-event emitter へ通す配線。通常フローで現 URL reload が
  最低 1 件観測されること (母集団非空) を assert。禁止 destination を合成入力で流す負のコントロールを追加。
  「違反ゼロ」と「母集団ゼロ」を区別。

## 施策5 (REQUEST_CHANGES) [Warning] x3 + [Suggestion]
- new URL() 失敗時の扱い未定義 → 対応: isInAppUrl は try/catch で解析不能を拒否側 (false) に。
  canonicalize も例外を漏らさず null 返し。malformed / 異常 scheme / dot-segment テスト追加。
- visitExplicitly の同期 before 発火依存 → 対応: 第一候補は native anchor でトークン機構を不要化
  (PC 詳細リンク削除も検討)。wrapper を残す場合は同期発火 → intent 消費 → router.visit 戻りの契約を
  テストで固定し、非同期発火時に誤許可しない負例を置く。
- リスク欄の「認証失効は正規遷移として通す」が本文の限定契約と矛盾 → 対応: リスク欄を限定契約に統一
  (ハードビジットは対象外で妨げない / client-side programmatic は認証失効を推測せず明示 intent のみ許可)。
- 状態保証表のヘッダ二重 → 対応: 重複ヘッダ行を削除。
