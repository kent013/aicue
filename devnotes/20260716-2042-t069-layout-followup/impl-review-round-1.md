**全体判定: REQUEST_CHANGES**

- 方針（S1/S2/S3）自体は詳細設計に整合し、`PageContent` 導入・`AppLayout` の幅責務分離・arch テスト追加の方向は妥当です。  
- ただし、提示差分だけでも**設計の「実効幅を実質変えない」原則との齟齬**と、**運用規約の強制力不足（例外拡大リスク）**が見えるため、このままは承認不可です。

---

**`resources/js/components/templates/AppLayout.svelte`**  
**判定: APPROVE**

- [Suggestion] `Settings` 削除と `/settings` ナビ項目削除は S1 要件どおり。  
- [Suggestion] コメントで「SidebarUserMenu に一本化」を明示しており意図が追いやすい。  
- [Warning] コメント依存ではなく、テスト側で desktop/mobile 両方の負例が必須（設計書どおり）。`tests/js/components/templates/AppLayout.test.ts` の実装内容を最終確認してください。

---

**`resources/js/components/templates/PageContent.svelte`**  
**判定: APPROVE**

- [Suggestion] `maxWidth` を union + `Record` で閉じており、DS 純度・Tailwind class 消失対策として適切。  
- [Suggestion] `w-full + mx-auto + max-w-*` の責務が明確で、primitive として過不足なし。  
- [Warning] `testId` デフォルトを契約に据えるなら、将来変更時に壊れやすいため、テストは class assertion 主体を継続する方針を徹底してください（設計と一致）。

---

**`tests/js/components/templates/PageContent.test.ts`**  
**判定: APPROVE**

- [Suggestion] children 描画・中央寄せ・幅マッピング・`testId` 差し替えまで網羅されており十分。  
- [Suggestion] 幅ケースに `4xl/7xl` を含めたのは S3 の代表幅を意識できていて良い。  

---

**`tests/js/architecture/page-content-usage.test.ts`**  
**判定: REQUEST_CHANGES**

- [Warning] 文字列/正規表現ベースとしては堅実ですが、`importsAppLayout` が default import 1 形のみ前提（`import { ... }` は Svelte では通常ないが、将来の書き方差分には脆い）。  
- [Warning] allowlist が `Capture/Show.svelte` のみで、今後例外追加時のレビュー運用頼み。**「理由コメント必須」だけでは実効性が弱い**。  
- [Suggestion] 最低限、allowlist エントリを `{ path, reason }` 構造にして reason 空文字を fail させると、規約逸脱を機械的に抑止できます（過剰実装ではない）。

---

**`resources/js/pages/Manuals/Edit.svelte`**  
**判定: APPROVE**

- [Suggestion] 外側 `PageContent maxWidth="4xl"` + 内側 `max-w-2xl` を保持し、設計書の「二段 max-w 許容（別目的）」に一致。  
- [Suggestion] 見出しを `PageContent` 内に含める移行パターンも統一的。  

---

**`resources/js/pages/Billing/PurchaseTickets.svelte`**  
**判定: APPROVE**

- [Suggestion] ルートの `max-w-3xl` を `PageContent maxWidth="3xl"` に移管し、内側 `max-w-xs` を維持している点は設計どおり。  
- [Suggestion] 振る舞い・testid を維持しており回帰リスクは低い。  

---

**`resources/js/pages/Invitations/Accept.svelte`**  
**判定: REQUEST_CHANGES**

- [Critical] 設計書はこのページを「現行全幅→`7xl` で実効上ほぼ全幅維持」としていますが、実装は元々 `mx-auto max-w-md` の狭幅フォーム。  
  この差分では `PageContent maxWidth="7xl"` を追加しても内側 `max-w-md` が支配的で、**分類ロジック（全幅グループ）と実態が不一致**です。  
- [Critical] 「23ページ同一パターン」で進めた前提なら、ここは割当表の誤りか移行規則の例外未定義です。設計ドキュメントと実装のどちらかを正として**明示的に揃える**必要があります。  
- [Suggestion] このページは実態的にフォーム幅ページなので `2xl` 系に寄せるか、`7xl + max-w-md` を採る理由を設計書へ追記してください（テスト名にも理由反映）。

---

**レビュー観点サマリ**

- 設計一致: 一部不一致（`Invitations/Accept` の幅分類）。  
- 正確性: おおむね良好。  
- PHPStan/DTO-JsonResource/BE: 変更なしで問題なし。  
- テスト網羅: 方向は良いが、allowlist 統制をもう一段機械化したい。  
- セキュリティ: 本件フロント範囲で新規懸念なし。  
- DESIGN.md/DS token: 提示範囲で hex 直書きなし。  
- Atomic Design: `templates`/`pages` の依存方向は順守。

必要なら次に、`Invitations/Accept` を含む「23ページ割当表」の整合チェック観点（どのページを `2xl/3xl/4xl/7xl` にすべきか）を、差分一覧ベースで短く監査リスト化します。