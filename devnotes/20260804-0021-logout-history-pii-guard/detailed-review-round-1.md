**全体判定**
- **CHANGES_REQUESTED**
- 設計の方向性は正しく、特に `EncryptHistory` + `clearHistory` の機構理解と Fortify の実行順序理解は概ね正確です。  
- ただし「空振り green 防止」と「将来後退の検出力」をもう一段上げる修正が必要です。

**施策1: Inertia history暗号化 (`bootstrap/app.php:84`)**
- 判定: **APPROVE**
- [Critical] なし
- [Warning] なし
- [Suggestion] `encryptHistory: true` 契約を守る Architecture/Feature テストを追加済みなので十分。コメントも経路A/B/Cの責務分離が明確。

**施策2: LogoutResponse差し替え (`app/Http/Responses/Fortify/LogoutResponse.php:1`, `app/Providers/FortifyServiceProvider.php:1`)**
- 判定: **REQUEST_CHANGES**
- [Warning] `Inertia::clearHistory()` を全クライアントで無条件実行すると、非 Inertia JSON ログアウトでも server-side フラグが残り、次の Inertia 応答で意図せず履歴クリアが発火し得ます。  
  修正案: `X-Inertia` ヘッダ有無（または web+Inertia 経路）で `clearHistory()` 実行を分岐し、非 Inertia JSON では従来挙動のみ維持。
- [Suggestion] `route('home')` 固定は妥当ですが、将来の着地変更事故を防ぐため「logout 着地は Inertia 応答であること」の契約テストを明示（現行案の Feature テストを強制契約として残す）。

**施策3: Featureテスト (`tests/Feature/Security/InertiaHistoryGuardTest.php:1`)**
- 判定: **REQUEST_CHANGES**
- [Warning] 現行ケースは「POST `/logout` → 後続 GET」を手動分離しており、実際の Inertia リダイレクト連鎖（`X-Inertia` 経路）そのものの保証が弱いです。  
  修正案: `X-Inertia` ヘッダ付き logout リクエストを追加し、実運用経路で `clearHistory` が着地ページに載ることを固定。
- [Suggestion] `/pricing` が将来 Inertia でなくなった場合に false negative になり得るため、1回消費テストは Inertia保証済みルート（例: `home`）で完結させると堅い。

**施策4: Browserテスト (`tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php:1`)**
- 判定: **REQUEST_CHANGES**
- [Warning] `assertDontSee($owner->name)` は「一瞬表示された後に消えた」ケースを取り逃す可能性があります（瞬間フラッシュ検出不能）。  
  修正案: `back()` 前に `MutationObserver` で `__piiSeen` フラグを仕込み、遷移完了後 `__piiSeen === false` を検証して“途中フレーム露出なし”を機械的に保証。
- [Suggestion] `window.history.state?.page instanceof ArrayBuffer` は良い正のコントロールですが、将来の Inertia 実装変更に備え「暗号化されていること」を抽象化したヘルパに寄せると保守性が上がる。

**施策5: 経路B/Cコメント整理 (`resources/js/lib/bfcache-guard.ts:1`, `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php:1`)**
- 判定: **APPROVE**
- [Critical] なし
- [Warning] なし
- [Suggestion] コメントのみ変更で挙動不変、責務分離の明文化として適切。

**施策6: 契約文書更新 (`docs/supported-browsers.md:1`, `docs/testing-browser.md:1`, `AGENTS.md:1`)**
- 判定: **APPROVE**
- [Critical] なし
- [Warning] なし
- [Suggestion] 「同一タブ限定」「非 Inertia 面対象外」「非セキュア文脈で degrade」を明記しており、保証主語の過剰拡大を防げています。

**本件固有観点への回答**
- Inertia暗号化+clearHistory成立性: **成立**（同一タブ・`crypto.subtle` 利用可能・着地が Inertia 応答の条件下）。
- `LogoutResponse::toResponse()` タイミング: **妥当**（Fortify の invalidate 後セッションに積み、次 Inertia 応答で `pull`）。
- 経路B/C共存: **競合は軽微で許容**（主に race による二重再訪問候補だが着地整合）。
- Browserテスト正のコントロール: **概ね良いが瞬間露出検知が不足**。
- 見落とし後退リスク: **Inertia実経路テスト不足** と **非 Inertia JSON logout の副作用**。