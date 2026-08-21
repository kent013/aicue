# 対応マトリクス: conceptual-review Round 1

## [Critical] 観点3 (F-3-01): 「Input の error prop に流し込む」が確認済み機構と不整合
- 判断: 対応する
- 根拠: DESIGN.md §FormField は「エラー文言 + aria 配線は molecule (FormField) の責務」と定める。
  独立 `<p>` に文言・別経路で `Input.error` boolean を渡す当初案は文言と aria の出所が分かれ、canonical でない。
- 対応内容: 方針を「per-field のエラー文字列を各 **FormField の `error` prop** に渡す」に変更。FormField が
  FormError の描画・`invalid`→`Input.aria-invalid`・`aria-describedby`→errorId の配線を既存機構で一括で行う。
  独立表示していた `<p data-testid="auto-recharge-range-error">` は撤去 (文言二重化を避ける)。FormField 自体は
  改変せず既存 `error` prop を使うだけ。既存 JS テスト (range-error testId 参照) は aria-invalid 属性 +
  文言 (getByText) の assert に更新する (カバレッジは削除せず移設)。

## [Warning] 観点2/観点5: テスト追加の明記 (両 finding)
- 判断: 対応する
- 対応内容: 実装方針に PHP Feature テスト (F-4-01) と JS コンポーネントテスト (F-3-01) の追加を明記。
  詳細設計の「テスト計画」に Codex 提示の 4 経路 (fresh メール変更→verification.notice+flash / 氏名のみ→back /
  expectsJson→200 JSON / stale+recent-auth 完了後も flash 残存) を反映。

## [Warning] 観点4: `!hasVerifiedEmail()` 単独は状態から操作原因を推測している
- 判断: 対応する
- 根拠: より精密で将来の経路追加に頑健。`$request->user()` は当該リクエストで action が保存した同一インスタンスを
  memo 返しするため、保存直後の Eloquent 変更追跡が使える。
- 対応内容: 判定を **`$user->wasChanged('email')`** に変更 (= 「今このリクエストで email が変わった」の操作事実)。
  メール変更分岐のみ email_verified_at を null 化するので、これは「未認証化 = 認証導線が必要」と同値かつ精密。
  氏名のみ・同一 email early-return は wasChanged('email')=false で従来 `back()` を維持。

## [Warning] 観点5 (F-3-01): 両フィールドを常に invalid にすると誤った修正対象を伝える
- 判断: 対応する
- 対応内容: 範囲エラーの原因ごとに invalid フィールドを 1 つに限定する。
  parsedThreshold===null → threshold のみ / parsedMax===null → max のみ / parsedMax<=parsedThreshold → max のみ
  (文言「リチャージ後の残高は開始残高より大きい値」が指す先)。threshold-first の短絡評価で同時に 2 つ invalid には
  ならない契約をテストで固定。

## [Warning] 観点7: 型安全性 (ユーザー取得・戻り値型)
- 判断: 対応する
- 対応内容: 詳細設計で `$request->user()` を `Assert::isInstanceOf`/instanceof で User に narrowing、
  戻り値型 `JsonResponse|RedirectResponse` を維持、DTO/JsonResource 新設なし (仕様固定 JSON 200 を変えない) を明記。

## [Suggestion] 観点1/3/6: 使命整合・局所修正・スコープ限定の妥当性
- 判断: 見送る (肯定的評価。追加対応不要)
