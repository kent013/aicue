# 対応マトリクス: impl-review Round 1

判定: **APPROVED** (Critical 0 / Warning 1 / Suggestion 3)。
Round 1 で APPROVED のため合議ループは 1 ラウンドで終了する。以下は Warning / Suggestion の捌き。

## [Warning] Security.svelte: `fetchStringField()` が HTTP 種別 (特に 401/419) を畳むため、セッション期限切れ時の切り分け性が下がる

- 判断: **見送る (今回は対応しない)**
- 根拠:
  1. **到達しにくい経路である**。`loadEnrollmentAssets()` は `enableTwoFactor()` の Inertia POST
     (`/user/two-factor-authentication`) が `onSuccess` になった直後にしか呼ばれない。
     セッションが切れていれば、その POST 自体が先に login へ倒れる (Inertia の 409/redirect 経路) ため、
     「enrollment 素材の fetch だけが 401/419 で落ちる」のは POST 成功後に窓が閉じた狭いケースに限られる。
  2. **区別した値の使い道が現時点で無い**。詳細設計 §施策 C の判断根拠に記録したとおり、
     表示文言も再試行導線も同一であり、種別を保持しても使わない (思考原則 2: 今必要なものだけ作る)。
  3. **再認証導線の新設は明示的なスコープ外**。`two-factor.qr-code` / `two-factor.secret-key` への
     recent-auth / 再認証ゲートは `devnotes/20260713-1653-twofa-recent-auth/conceptual-design.md:67` が
     「enable/confirm の enrollment 再設計と一体で扱う」と記録済みの別課題であり、
     片方にだけ導線を足すと記録済みの境界を設計レビューなしに動かすことになる。
  4. 現行実装でも**詰まない**: 両方失敗時は `enrollment-assets-error` Alert + 再試行ボタンが常在し、
     セッションが切れている場合は次のサーバ遷移で login へ倒れる (行き先のない詰みは作らない)。
- 対応内容: コード変更なし。Codex の [Suggestion] (401/419 専用導線) も同じ理由で将来課題として扱う。

## [Suggestion] Browser テストの `timeoutMs=3000` を環境変数で上書き可能にする

- 判断: **見送る**
- 根拠: この 3000ms は「CI の遅さを吸収するための待ち時間」ではなく **判定の制御条件そのもの**
  (toast の auto-dismiss 4 秒より短く取ることで「見えなかった」を auto-dismiss と混同しない)。
  外から上書き可能にすると 4 秒を超える値を渡された瞬間に制御条件が壊れ、
  fail の分類 (H-a 支持 / 原因判定不能) が意味を失う。
  また、遅延で先に壊れるのは着地判定側であり、その場合は `landedWithinDeadline=false` として
  「その他の fail (原因判定不能)」に分類されるため、toast 側の fail と混同する事故は起きない。
- 対応内容: コード変更なし。

## [Suggestion] DESIGN.md §Toast の追記は実装と整合

- 判断: 指摘なし (肯定的評価)。対応不要。

## [Suggestion] 逸脱点 3 件 (submit selector / script polling / QR 失敗 toast → Alert) はいずれも妥当

- 判断: 追加対応なし。3 点とも `deviations_from_design` に記録済み。
