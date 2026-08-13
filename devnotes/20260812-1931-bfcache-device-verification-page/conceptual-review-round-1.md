全体判定: **CHANGES_REQUESTED**

概念の方向性は妥当です。T085 の「bfcache が実際に起きたか分からない」欠陥を、検証ページ側の観測で潰す発想は North Star に間接的だが重要に貢献します。特に iOS Safari / PWA / ログアウト後復元の扱いは、撮影 PWA の信頼性に直結します。

ただし、現設計のままだと **検証対象を壊す可能性** と **本番非到達の保証の弱さ** が残るため、このまま承認はできません。

## 1. 使命との整合性

[Warning] 使命への貢献は「直接機能改善」ではなく「撮影 PWA の安全性検証の信頼性向上」です。主張は成立しますが、設計書上でこの位置づけを明確にした方がよいです。

修正提案: 「現場作業者が撮影 PWA を使った後、ログアウト後の履歴復元で PII や業務情報が露出しないことを確認するための検証支援」と明記してください。North Star への接続は、撮影導線の安全性・信頼性に限定して述べるのが妥当です。

## 2. 禁止事項違反

[Critical] 「production コードを一切変更しない」としつつ、`/debug` の route / Inertia page / controller 追加はアプリコード変更です。意図は「本番挙動を変更しない」だと思われますが、表現が不正確です。

修正提案: 要件を「検証対象である `bfcache-guard.ts` / 秘匿 CSS / `/session/status` の挙動は変更しない。追加するのは local/debug 限定の観測ページのみ」と書き換えてください。

[Warning] Inertia ページがデータ取得用に JSON endpoint を追加する場合、`response()->json()` 直書き禁止に抵触しやすいです。

修正提案: 追加 endpoint を作らず、Inertia props で初期値を渡すか、必要なら DTO / JsonResource を使う方針を明記してください。

## 3. 実現可能性

[Warning] `auth` 必須 + `LocalOnly` + Basic 認証 + iOS 実機 HTTPS トンネルの組み合わせは実現可能ですが、手順の失敗要因が多いです。Basic 認証、Laravel 認証、PWA standalone、HTTPS、Safari の bfcache 条件が重なります。

修正提案: 詳細設計では「有効な試行条件」を画面で明示してください。最低限、`auth: true`、`display-mode`、`persisted`、`pageshow count`、`context token unchanged`、`guard transition observed` を表示し、どれか欠けたら「無効」と判定する設計にしてください。

[Critical] `sessionStorage` にログを残す設計は、ログアウト後に `/login` へ飛んだ場合の証跡復元としては成立しますが、ログ自体にダミー PII やセッション状態が含まれると、検証ページが別の漏えい源になります。

修正提案: 保存するログ項目を allowlist 化してください。保存可は timestamp、event name、`persisted`、guard attribute state、context token の短縮ハッシュ程度に限定し、ユーザー名・email・URL query・cookie・レスポンス本文は保存禁止と明記してください。

## 4. 期待効果の妥当性

[Warning] 「スクリーンショット 1 枚が証跡」は合理的ですが、1 枚だけでは操作順序や失敗試行の履歴が曖昧になる可能性があります。

修正提案: 画面に「試行 ID」「開始時刻」「離脱時刻」「復元時刻」「最終判定」「無効理由」を出してください。コピー用テキストにも同じ項目を含めると、スクリーンショットと devnotes の整合が取りやすくなります。

[Suggestion] `persisted === false` を無効試行にするのはよいですが、`persisted` と context token が食い違った場合の扱いも定義した方がよいです。

例: `persisted=false` かつ token unchanged は「ブラウザ申告と実測の不一致」、`persisted=true` かつ token changed は「観測矛盾」として、合格ではなく要調査にする。

## 5. リスク

[Critical] `/debug/*` を `APP_ENV=local` のまま HTTPS トンネルで実機到達させる運用は、LocalOnly が環境名依存である以上、露出リスクがあります。Basic 認証があるとはいえ、検証ページに認証済み画面・ログアウト導線・状態観測が集まるため、誤公開時の影響を軽く見ない方がよいです。

修正提案: route 登録ゲート + `LocalOnly` に加えて、このページ専用の明示 env フラグを要求してください。例: `BFCache_DEBUG_PROBE_ENABLED=true` かつ `app()->isLocal()` の両方。既存 `/debug/login` と揃える必要があるなら、少なくとも詳細設計で「本ページ追加によって local tunnel の露出面が増える」ことをリスクとして明記してください。

[Warning] 検証ページ自身が bfcache 条件に影響する可能性があります。MutationObserver、sessionStorage 書き込み、copy 操作、Basic 認証、追加 UI は原則問題ないとしても、将来 `beforeunload` / `unload` / keepalive fetch 等が入ると検証が空振りになります。

修正提案: Architecture または JS テストで、検証ページに `unload` / `beforeunload` を登録しないことを固定する方針を入れてください。

## 6. スコープの適切さ

[Warning] 「相方ページを 1 枚用意する」は妥当ですが、ログアウト導線をどう作るかが危険です。禁止事項にもある通り、ログアウト導線を非 Inertia 経路や JSON 204 完結で増やすと、既存の Inertia history clear 契約を壊す可能性があります。

修正提案: ログアウトは既存の許可済み call site を使う、または既存 logout form/component を再利用する、と明記してください。新しい logout API / fetch / JSON 完結導線は作らない方針にすべきです。

[Suggestion] passkey 実機確認や経路 C を混ぜないスコープ判断は適切です。

## 7. 型安全性

[Warning] 現時点の概念設計では DTO / props の型境界が未定義です。Svelte 側だけで完結する観測値が多い一方、Inertia props を渡すなら型の形を固定する必要があります。

修正提案: サーバから渡す props は最小化し、必要なら `DebugBfcacheProbePageData` のような DTO を置く方針にしてください。クライアント内ログも TypeScript の union type で `pageshow` / `pagehide` / `guard-state` / `verdict` を定義すると、PHPStan level 10 / JS typecheck の両方に乗せやすいです。

**結論**

設計意図は良いです。ただし承認条件は次の修正です。

1. 「production コードを変更しない」を「検証対象の production 挙動を変更しない」に修正する。
2. debug ページの露出条件を強める、または露出リスクを明示して追加防御を設計する。
3. `sessionStorage` ログの保存項目を allowlist 化する。
4. logout 導線は既存 Inertia history clear 契約を壊さない方式に限定する。
5. 有効試行 / 無効試行 / 観測矛盾の判定ルールを明文化する。