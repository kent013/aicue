# 対応マトリクス: conceptual-review Round 1

## [Critical] 1. 別タブに残る Inertia history は復号可能なまま (保証範囲と脅威モデルのズレ)
- 判断: **一部対応する / 一部反論する**
- 根拠:
  - 事実認定は正しい。`sessionStorage` はタブ単位であり、`Inertia::clearHistory()` が消すのは
    **その応答を受け取ったタブの鍵**だけ (`@inertiajs/core` `src/history.ts::clear()` →
    `SessionStorage.remove('historyKey'/'historyIv')`)。別タブ B の履歴は B 自身の鍵で復号できる。
  - ただし**追加の露出は実質生じない**。タブ B が開いているということは、
    **タブ B の画面には既に認証済み DOM (PII) が表示されている**。B で「戻る」を押して
    別の認証済みページが復元されても、覗き見できる情報の質は変わらない。
    「開いたままのタブが古い認証済み DOM を表示し続ける」問題は履歴復元とは別カテゴリで、
    塞ぐには全タブへのセッション失効伝播 (BroadcastChannel 等の自前機構) が要る。
    これは原則 1 (フレームワークのレンジ内) / 原則 2 (今必要なものだけ) に反する。
  - よって「後続の**必須**セキュリティ課題として切り出す」は採らない。
- 対応内容: 保証範囲を「**Inertia 面 / ログアウトを実行したタブの `popstate` 履歴復元**」に
  明示限定する。別タブは残存リスクとして概念設計・`docs/supported-browsers.md` に理由付きで記載する
  (「開いたままのタブは既に PII を表示しているため、履歴復元による追加露出は無い」も含めて書く)。

## [Critical] 2. 文書更新案の主語が実装より広い (非 Inertia 面 / 別タブ / セッション失効を含んでしまう)
- 判断: **対応する**
- 根拠: 指摘のとおり。`AGENTS.md` #3 と `docs/supported-browsers.md` を
  「認証済み画面一般の 3 枚の網」と書くと、Filament (`/admin`) のような非 Inertia 面や
  別タブ・セッション失効まで保証しているように読める = 契約誤記。
- 対応内容: 文書の主語を「**Inertia (PWA / 管理画面) の認証済み画面**」に狭める。
  経路 C の保証は「同一タブ・明示ログアウト」に限定し、
  非 Inertia 面 (`/admin`) / 別タブ / セッション失効を**残存リスク節**として分離記載する。

## [Warning] 3. logout 着地が Inertia 応答であることへの暗黙依存
- 判断: **対応する**
- 根拠: `clearHistory` フラグは session に積まれ「次の Inertia 応答」でしか消費されない
  (`Inertia\Response::__construct` の `session()->pull`)。着地が非 Inertia になると
  フラグが宙に浮き、**静かに** 防御が消える。現状 `config('fortify.redirects.logout')` は
  未設定 (`config/fortify.php` に `redirects` キー無し) だが、設定 1 つで壊れる構造は残せない。
- 対応内容:
  (a) アプリ側 `LogoutResponse` は `Fortify::redirects()` を経由せず **`route('home')` へ固定**し、
      「着地は Inertia 応答であること」を docblock の契約として明記する
      (設定由来のドリフト経路を残さない = 原則 3)。
  (b) Feature テストで「`POST /logout` → 着地 `GET /` の Inertia page に `clearHistory: true` が載る」
      を直接固定する (着地が非 Inertia 化したら落ちる)。

## [Warning] 4. 鍵破棄で失うのは再取得だけでなく remember 済みフォーム状態・スクロール位置も
- 判断: **対応する (設計書に明記して許容判断を書く)**
- 根拠:
  - 事実確認: `history.clear()` は `window.history` のエントリ自体は消さず、鍵のみ消す。
    復号不能になったエントリへ戻ると `onMissingHistoryItem` → `router.visit(..., replace: true)` で
    サーバから取り直すため、そのエントリに紐づく `rememberedState` / `scrollRegions` は失われる。
  - ただし失われるのは **ログアウト以前に作られた履歴エントリ**に限られる。
    ログアウト後に作られるエントリは新しい鍵で暗号化され通常どおり復元できる。
    現在表示中のページの `rememberedState` はメモリ上の `history.current` にあり影響を受けない。
  - 「auth 配下限定適用」への切替は採らない: 認証済み route は `['auth','verified']` グループの外にも
    複数あり (`Route::middleware('auth')` の招待受諾 POST、`onboarding.*` 等)、
    限定適用は inventory ドリフトと専用 Architecture テストを生む (原則 2 に反する)。
    公開ページの履歴エントリが失うのは「ログアウト前に見ていた LP のスクロール位置」程度で、
    セキュリティ上の便益と釣り合う。
- 対応内容: 概念設計の「期待効果」「制約」に UX 退行 (再取得 + remember/scroll 喪失、
  影響範囲はログアウト前エントリのみ) と許容根拠を明記する。

## [Warning] 6. AGENTS.md 規約化の粒度がスコープ過大
- 判断: **対応する**
- 根拠: Critical 2 と同根。`AGENTS.md` #3 は repo 全体の不変条件になるため、
  Inertia 面限定の実装を汎用規約として書くと別スタック (Filament) へ自動拡張されて読まれる。
- 対応内容: 規約文言を「**Inertia の認証済み画面**」に限定し、
  経路 A/B/C の担当を明記した上で「非 Inertia 面は本規約の対象外」と書く。

## [Suggestion] 各項目
- 使命整合・禁止事項・型安全性・テスト方針への肯定的評価は反映不要 (現方針を維持)。
