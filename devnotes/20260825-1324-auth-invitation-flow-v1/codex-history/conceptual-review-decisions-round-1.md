# 対応マトリクス: conceptual-review Round 1

## [Critical] 招待行ロックだけでは組織論理削除との競合を閉じられない (観点 3)
- 判断: 対応する (設計の記述を精密化) + 一部反論 (前提事実の訂正)
- 根拠: 指摘の懸念 (組織行のロック無しでは attach 直前の削除を防げない) は正当だが、
  前提が実コードと異なる。`joinOrganization()` は**冒頭**の `lockForMembershipWrite()` で
  organizations 行の `lockForUpdate` を**既に取得**しており (canonical 順序 users 昇順 →
  organizations、その後に招待行ロック)、組織の論理削除 = 同じ organizations 行の UPDATE は
  この行ロックで直列化される (`OrganizationMembershipService.php` L404-448 実読)。
  欠けているのは「ロック取得後に生存を読み直す」ことだけ。
- 対応内容: 施策 A を書き直した — (1) joinOrganization が既に組織行ロックを保持している事実と、
  ロック順序を一切変えない (新しい順序を作らない = デッドロックを導入しない) ことを明文化。
  (2) 招待行ロック下の再検証に「organizations を default scope (論理削除除外) で whereKey →
  exists 再確認」を追加。(3) TOCTOU 再現テスト (事前検証通過後に組織を論理削除して
  joinOrganization 相当へ到達 → 受諾不能 + membership 行なし) を既存の
  「受諾済み招待で joinOrganization 相当に到達しても no-op」テストと同じ手法で固定。

## [Warning] markEmailAsVerified() が Verified イベントを即時発火する懸念 (観点 3)
- 判断: 対応する (方式を forceFill 明示に確定) + 事実訂正
- 根拠: framework の `markEmailAsVerified()` は forceFill + save のみで**イベントを発火しない**
  (vendor/laravel/framework/src/Illuminate/Auth/MustVerifyEmail.php L24-29 実読)。
  `Verified` を発火するのは確認リンクの controller 側。aicue に `Verified` の listener は無い
  (実測。PasskeyVerified は別 event)。
- 対応内容: 施策 C を「`email_verified_at` を明示 forceFill で立てる (terms_accepted_at と
  同じ流儀)、`Verified` は発火しない (意味論が別。listener 実在せず)、判断は実装コメントに
  残す」へ確定。あわせて `SendEmailVerificationNotification` listener の抑止条件
  (`hasVerifiedEmail()`) を vendor 実読で確認した旨と、「招待経由登録で VerifyEmail 通知が
  1 通も送られない」の Notification fake テストを明記。
  さらに事実確認の過程で見つかった波及 — `RegisterResponse` が無条件に verification.notice へ
  redirect する — を施策 C に取り込み、verified 登録者は `app.entry` へ直接 redirect する
  分岐を明示した。

## [Warning] 3 経路の早期判定だけでは並行削除時の安全性を保証できない (観点 4)
- 判断: 対応する
- 対応内容: 上記 Critical と同一対応 (ロック下の生存再検証 + TOCTOU 再現テスト)。
  加えて実測で新事実を発見し設計へ反映 — show() は無効判定 → guest 分岐 → 組織 Assert の順の
  ため、guest + 論理削除組織では 500 ではなく「token が session に入り登録 POST が 500」に
  なる。畳み込みを guest 分岐より前に置くことを施策 A に明記。さらに単一解決口
  `findActiveByPlainToken` へ `whereHas('organization')` を足し、prefill / rule / register 経路を
  一括で畳む形へ改めた。

## [Warning] KeySoT gate の検出範囲 (単引用符のみ?) (観点 5)
- 判断: 一部反論 + 対応する
- 根拠: テンプレ実装は `trim($token[1], '\'"')` で**単・二重引用符の両方**を復元して比較する
  (T_CONSTANT_ENCAPSED_STRING は両引用符の非補間 literal を含む)。「単引用符だけ」は
  実装事実と異なる。動的組み立て・別名鍵が保証外である点はテンプレ docblock が既に明記。
- 対応内容: 設計に判定方式 (両引用符の復元比較) を明記し、IC-2 の負例・正例へ
  二重引用符 literal のケースを追加すること、docblock の保証範囲 (動的鍵・別名鍵・tests/ は
  対象外) の踏襲を明記した。

## [Warning] InvitationContinuation の公開契約と DI 方式が未記載 (観点 7)
- 判断: 対応する (契約の明文化) + 一部反論 (constructor injection は採らない)
- 根拠: `Session` をメソッド引数で受けるのがテンプレ正典形 (final readonly の無状態クラス。
  「書き込みメソッドを呼ぶ受け手は型で示す」規約)。session の constructor 注入は
  リクエスト外文脈で壊れやすく、家系の形とも割れる。
- 対応内容: 公開契約 `remember(Session, string): void` / `resolve(Session): ?string` /
  `forget(Session): void` を設計へ明記。mixed の絞り込みは resolve() 内部に閉じ
  呼び出し側へ漏らさない。型衛生 (非文字列・空文字・配列・数値 → forget + null) の
  Unit テストを明記。

## [Suggestion] 確認メール抑止の fake notification 検証 (観点 4) — 採用 (施策 C に明記済み)
## [Suggestion] ログ・例外報告の扱い (観点 5) — 見送り (今回の 3 経路は例外を投げなくなるため
  例外報告への露出自体が消える。ログの出し分けは現状も無い)
## [Suggestion] 使命整合・スコープ・禁止事項 (観点 1/2/6) — 現状維持でよいとの評価。対応不要
