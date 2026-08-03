全体判定: **CHANGES_REQUESTED**

1. **使命との整合性**
- [Critical] 「ログアウトしたら他人に PII を見せない」という導入前提を、この設計だけで満たしたとは言い切れません。`Inertia::clearHistory()` が消すのは**そのタブの `sessionStorage` 鍵**だけで、同一ブラウザの別タブに残った Inertia history は復号可能なままです。共有端末の脅威モデルとまだズレがあります。  
  修正提案: 保証範囲を「**当該タブの Inertia `popstate` 復元**」に明示的に限定するか、別タブ伝播 (`localStorage` / `BroadcastChannel` など) を後続の必須セキュリティ課題として切り出してください。
- [Suggestion] 同一タブでの logout 後 back による PII 再表示を潰す、という狙い自体は North Star を支える土台として妥当です。

2. **禁止事項違反**
- [Suggestion] 自前 `popstate` ガードを増やさず、Inertia/Fortify の公式機構で閉じる方針は「フレームワークのレンジ内でやる」「後方互換の並走を残さない」に沿っています。
- [Suggestion] `response()->json()` 直書きや Prism 直呼びにも触れておらず、禁止事項への抵触は見当たりません。

3. **実現可能性**
- [Warning] 設計は `POST /logout -> 302 -> GET /` の**着地先が Inertia 応答であること**に依存しています。`clearHistory` は「次の Inertia 応答」でしか消費されないので、将来 redirect 先やホーム実装が変わると静かに壊れます。  
  修正提案: `LogoutResponse` 側で Inertia endpoint を契約として固定するか、少なくとも Feature テストで「logout 着地が Inertia で `clearHistory` を消費する」ことを明示的に縛ってください。
- [Suggestion] `EncryptHistory` のグローバル適用と `clearHistory()` の組み合わせ自体は、Laravel 12 + Inertia の範囲で十分実装可能です。

4. **期待効果の妥当性**
- [Warning] 「公開ページへ戻った場合の追加コストは再取得 1 回だけ」は少し軽く見積もっています。鍵破棄で失うのは再取得だけではなく、**remember されたフォーム状態・スクロール位置・一時 UI state** も含みます。  
  修正提案: この UX 退行を設計書に明記し、許容判断を入れてください。許容できないなら、global 適用ではなく auth 配下 Inertia 面への限定適用を再評価すべきです。
- [Suggestion] F-4-01 の再現手順そのものに対しては、期待効果は合理的です。

5. **リスク**
- [Critical] 文書更新案が実装保証を広く書きすぎています。`/admin` のような非 Inertia 面や、前述の**同一ブラウザ別タブ**はこの設計の防御範囲外です。それなのに `AGENTS.md` / `supported-browsers.md` を「認証済み画面一般の 3 枚の網」と書き換えると、契約誤記になります。  
  修正提案: 文書の主語を「**Inertia ベースの同一タブ履歴復元**」へ狭め、非 Inertia 面・別タブ・セッション失効時は残存リスクとして分離記載してください。
- [Suggestion] `bfcache-guard.ts` を残して Safari の真の bfcache と Inertia history を責務分割する判断は妥当です。

6. **スコープの適切さ**
- [Suggestion] middleware + logout response binding + 文書/テストに絞るのは、過剰に広げない良いスコープです。
- [Warning] ただし `AGENTS.md` への規約化は repo 全体の不変条件になります。実装が Inertia 面限定なら、規約文言も同じ粒度まで狭めないとスコープ過大です。  
  修正提案: 規約文言を「PWA/Inertia 認証画面」に限定し、Filament 等の別スタックへ自動拡張される書き方を避けてください。

7. **型安全性**
- [Suggestion] `LogoutResponse` contract 差し替えは Laravel/Fortify の既存パターンに沿っており、DTO/JsonResource 方針や PHPStan level 10 を壊す設計には見えません。
- [Suggestion] Feature テストで `encryptHistory` / `clearHistory` の payload 契約と、通常応答に `clearHistory` が混入しない負のコントロールを固定する方針は適切です。

要点は1つです。**同一タブの F-4-01 を塞ぐ設計としては良いが、文書上の保証範囲が実装より広い**。そこを縮めるか、別タブまで含めて塞ぐ追加仮説を立てるかを先に決めるべきです。