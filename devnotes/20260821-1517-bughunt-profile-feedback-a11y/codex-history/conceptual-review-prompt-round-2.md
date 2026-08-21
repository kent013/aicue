# 概念設計レビュー Round 2 — 指摘への対応

Round 1 の指摘を反映しました。対応内容は以下です。全体判定 (APPROVED / CHANGES_REQUESTED) を再度お願いします。

## [Critical] 観点3 (F-3-01) への対応
方針を修正しました。per-field のエラー**文字列**を各 **`FormField` の既存 `error` prop** に渡します
(当初案の「Input の error prop へ直接 boolean」を撤回)。これにより:
- FormField が FormError の文言描画、`invalid`(=`Boolean(error)`)→snippet→`Input` の `aria-invalid`、
  `aria-describedby`→errorId を既存機構で一括配線 (DESIGN.md §FormField の canonical パターン)。
- FormField 自体は改変しない (既存 prop を使うだけ)。
- 従来 FormField 外に独立表示していた統合エラー `<p data-testid="auto-recharge-range-error">` は撤去
  (FormField 内 FormError と文言二重化を避ける)。
- 既存 JS テスト (range-error testId 参照) は「対象 spinbutton の aria-invalid + 文言 getByText」assert へ更新。

## [Warning] 観点4 (email 変更検知) への対応
`! $user->hasVerifiedEmail()` 単独をやめ、**`$user->wasChanged('email')`** を条件にします。
`$request->user()` は当該リクエストで action が `save()` した同一インスタンスを memo 返しするため、
保存直後の Eloquent 変更追跡で「今このリクエストで email が変わった」を直接判定します。氏名のみ・同一 email
early-return は `wasChanged('email')=false` で従来 `back()` を維持。メール変更分岐のみが email_verified_at を
null 化するので、この判定は未認証化と同値かつ精密です。

## [Warning] 観点5 (F-3-01 誤った修正対象) への対応
原因ごとに invalid フィールドを 1 つに限定します (threshold-first 短絡):
- `parsedThreshold===null` → threshold のみ
- `parsedMax===null` → max のみ
- `parsedMax<=parsedThreshold` → max のみ (文言「リチャージ後の残高は開始残高より大きい値」が指す欄)
同時に 2 欄 invalid にはならない契約をテストで固定します。

## [Warning] 観点2/観点5 (テスト) への対応
実装方針にテストを明記。詳細設計の「テスト計画」に以下 (Codex 提示) を反映予定:
- fresh メール変更で `verification.notice` へ redirect + success flash が載る (Feature)
- 氏名のみ更新は従来どおり `back()` + 既存メッセージ (Feature)
- `expectsJson()` は空 200 JSON 応答 (Feature)
- stale session の recent-auth 完了後も最終画面で flash が消えない (Feature)
- threshold/max それぞれの範囲エラーで対象 spinbutton のみ `aria-invalid=true` (JS)

## [Warning] 観点7 (型安全性) への対応
`$request->user()` を `Assert::isInstanceOf(..., User::class)` / instanceof で User に narrowing し、
戻り値型 `JsonResponse|RedirectResponse` を維持。DTO/JsonResource 新設なし (仕様固定 JSON 200 不変)。

---

## 更新後の該当セクション (抜粋)

### 改善アイデア F-4-01
`ProfileUpdatedResponse` を `$user->wasChanged('email')` で分岐。true なら `verification.notice` へ
success flash 付き 302 (`/email/verify` は auth のみで中間 302 なし → flash 生存 → flash-to-toast がトースト)。
false は従来 `back()->with('success','プロフィールを更新しました。')`。expectsJson は JSON 200 維持。

### 改善アイデア F-3-01
per-field エラー文字列を各 FormField の error prop へ。FormField が aria-invalid/aria-describedby/文言を配線。
統合 `<p>` 撤去。原因フィールドを 1 つに限定。既存テストは aria-invalid + 文言 assert へ更新。
