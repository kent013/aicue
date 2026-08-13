# 対応マトリクス: conceptual-review Round 1

## [Critical] 「production コードを一切変更しない」の表現が不正確 (§2)
- 判断: **対応する**
- 根拠: 指摘のとおり。`/debug` の route / controller / Inertia ページ追加はアプリコード変更である。
  意図していたのは「検証対象の挙動を変えない」であり、書き方が不正確だった。
- 対応内容: 「**検証対象** (`bfcache-guard.ts` / 秘匿 CSS / `/session/status` / 秘匿の発火経路) の
  挙動は一切変更しない。追加するのは local/debug 限定の観測ページのみ」へ書き換えた。

## [Critical] sessionStorage ログの保存項目を allowlist 化せよ (§3)
- 判断: **対応する**
- 根拠: 指摘が正しい。ログが `/login` 遷移をまたいで残る設計である以上、
  そこにダミー PII やセッション状態が入ると検証ページ自身が新しい漏えい源になる。
  「証跡を devnotes に貼る」運用なので、貼った先にも波及する。
- 対応内容: 保存可能項目を allowlist として概念設計に明記した。
  timestamp / event 種別 / `persisted` / guard 属性値 / context token の短縮ハッシュ /
  `display-mode` / 試行 ID に限定し、氏名・email・URL query・cookie・レスポンス本文・
  ダミー PII 文字列そのものを**保存禁止**と明記。

## [Critical] debug ページ専用の env フラグを追加せよ / 露出リスク (§5)
- 判断: **一部対応する（専用フラグは反論、リスク明記は対応）**
- 根拠（専用フラグへの反論）:
  1. **要求された統制は既に存在する。** `LocalOnly` は `DEBUG_LOGIN_USER` /
     `DEBUG_LOGIN_PASSWORD` が未設定なら 404 に倒れる (fail-secure)。
     つまり「明示的な env による opt-in」は既に必須条件になっている。
  2. **さらに production 側に起動時 fail-fast がある。** `config/debug.php` の注記どおり
     防御は三層で、第三層は `ProductionEnvGuard` が production での `DEBUG_LOGIN_*` 残置を
     起動時に落とす。誤公開時に「設定が残っていた」状態は production では起動しない。
  3. **本ページは同一ゲート上の `/debug/login` より権限が低い。** `/debug/login` は
     パスワード無しで任意ユーザーになれる。本ページは `auth` の背後で偽のダミーを表示し
     観測値を出すだけで、新たな権限も新たなデータ露出も足さない。
     **より弱い経路にだけ 4 つ目の独自フラグを足す**のは統制として一貫せず、
     ゲート機構を二系統に分岐させる (思考原則 2「今必要なものだけ作る」に反する)。
- 対応内容（リスク明記は受け入れ）: 「本ページ追加によりトンネル運用時の露出面が増える」ことと、
  トンネルの運用規律（検証中のみ起動する / Basic 認証の資格情報を他と使い回さない /
  検証後に停止する）を制約セクションに明記した。

## [Warning] 使命への位置づけを明確にせよ (§1)
- 判断: **対応する**
- 対応内容: 「撮影 PWA を使った後、ログアウト後の履歴復元で PII が露出しないことを
  確認するための検証支援」と限定して記述し直した。

## [Warning] JSON endpoint 追加は `response()->json()` 直書き禁止に抵触しうる (§2)
- 判断: **対応する**
- 対応内容: 「新規 JSON endpoint を作らない。サーバ→クライアントは Inertia props のみ」を
  実装方針に明記した。観測値はすべてクライアント側で生成されるため、そもそもサーバ取得が不要。

## [Warning] 有効な試行条件を画面で明示せよ (§3)
- 判断: **対応する（指摘を超えて拡張）**
- 根拠: 指摘の検討中に、当初案の証拠 #2 (JS 実行コンテキスト生存トークン) 単独では
  **真の bfcache 復元と Inertia の同一 Document 復元 (経路 C) を区別できない**ことに気づいた。
  Inertia の client-side navigation では Document が破棄されないため token も不変になる。
- 対応内容: 判定を真理値表として明文化した (概念設計「有効試行の判定」節)。
  `pagehide`/`pageshow` の観測有無が経路 C との区別に、`persisted` と token の組が
  真の復元と再取得の区別に効く。3 つが揃って初めて有効試行とする。

## [Warning] logout 導線は既存の Inertia history clear 契約を壊さない方式に限定せよ (§6)
- 判断: **対応する（指摘どおり。当初案を撤回）**
- 根拠: 指摘を受けて確認したところ、`tests/js/architecture/logout-call-site-inventory.test.ts` が
  **logout は Inertia visit (`router.post`) 一本**であることを deny-by-default で固定しており、
  同一ファイル内の `fetch`/`axios` 併用を違反として検出する。
  検討していた fetch ベースの logout は既存テストに弾かれる。
- 対応内容: **新しい logout 導線を一切作らない**方針にした。相方ページは既存 `AppLayout` を
  使い、そこに元からあるユーザーメニューの logout（inventory 登録済みの既存 call site）で
  ログアウトする。inventory への追記も発生しない。
  あわせて「A から離脱する遷移は **full document navigation** でなければ bfcache に入らない」
  ことを設計の中核制約として明記した（Inertia visit では同一 Document のままで経路 C になる）。

## [Warning] 検証ページに `unload`/`beforeunload` を登録しないことをテストで固定せよ (§5)
- 判断: **対応する**
- 根拠: 妥当。将来の改修で 1 行入るだけで検証が恒久的に空振りになり、
  しかも**空振りは緑に見える**ため誰も気づかない。既存の
  `tests/js/architecture/` に同種の deny-by-default テストが多数ある（前例あり）。
- 対応内容: 施策として JS architecture テストの追加を実装方針に含めた。

## [Warning] 型境界が未定義 (§7)
- 判断: **対応する**
- 対応内容: Inertia props は最小化（試行 ID の初期値程度）、クライアント内ログは
  TypeScript の discriminated union (`pagehide` / `pageshow` / `guard-state` / `verdict`) で
  定義する方針を明記した。

## [Suggestion] persisted と context token の食い違いの扱いを定義せよ (§4)
- 判断: **対応する**
- 対応内容: 上記の真理値表に「観測矛盾」を第三の判定として組み込んだ。
  合格でも単なる無効でもなく**要調査**として扱う。

## [Suggestion] 試行 ID・各時刻・最終判定・無効理由を表示せよ (§4)
- 判断: **対応する**
- 対応内容: 画面表示項目とコピー用テキストの必須項目に加えた。

## [Suggestion] passkey / 経路 C を混ぜないスコープ判断は適切 (§6)
- 判断: 追加対応なし（現状維持）
