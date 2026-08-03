**施策別判定**

- `B-1 flash→toast E2E再現テスト`: **APPROVE**
  - [Warning] `waitForVisibleSelector()` が `querySelector` の存在判定のみで「可視性」を担保していません。  
    修正案: `getComputedStyle(el).visibility !== "hidden"` + `display !== "none"` + `getClientRects().length > 0` を使って実可視を判定。
  - [Suggestion] 3秒固定は CI 揺らぎで flaky 化しやすいです。`landed` と `toast` を分ける思想は良いので、`toast` 側のみ 3500ms 程度へ緩和を検討。

- `C 2FA手動セットアップキー + QRアクセシブルネーム`: **REQUEST_CHANGES**
  - [Critical] `confirming` 開始直後、取得中でも `qr-unavailable` と `setup-key-unavailable` が先に表示されうる設計です（失敗前に失敗文言が出る）。  
    修正案: `loadingEnrollmentAssets === true` 専用分岐を追加し、警告 Alert は「取得完了後に欠損が確定した場合のみ」表示。
  - [Warning] `loadEnrollmentAssets()` の再試行連打でレスポンス逆転時に古い結果が上書きされる競合余地があります。  
    修正案: リクエスト連番（`requestId`）を持ち、最新リクエスト以外の結果を破棄。
  - [Suggestion] a11y 的に取得中コンテナへ `aria-busy="true"` を付与すると支援技術で意図が伝わりやすいです。

- `A-1/A-2 Guest/Auth layoutのflash取り込み + 持ち越し境界`: **APPROVE**
  - [Warning] 「ToastContainer はアプリで1箇所のみ mount」の不変条件との整合が**未検証**です（抜粋外に root 側 mount がある可能性）。  
    修正案: `AppLayout`/root の実装を確認し、重複 mount があり得るなら片側を撤去。加えて JS テストで重複描画が起きないことを固定。
  - [Suggestion] Feature テストの `assertSessionHas('success')` は良いですが、可能ならメッセージ文言も 1 件だけ固定して回帰耐性を上げるとより堅牢。

- `B-2 ToastContainerライフサイクル境界正規化（条件付き）`: **REQUEST_CHANGES**
  - [Warning] 「全ページ遷移で unmount される」が前提ですが、これは**未検証**で、根因仮説としては弱いです。  
    修正案: 先に `B-1` fail 時のトレース（遷移前後で unmount/mount が実際に起きたか）をテストで観測してから適用可否を決定。
  - [Warning] `onDestroy(clearToasts)` 削除後の cleanup 境界が未認証 layout 初期化に偏るため、想定外レイアウト経路で残留し得ます。  
    修正案: 「未認証到達時クリア」を正本にするなら、その境界を Architecture/JS テストで明文化して固定。

**全体判定**

- **CHANGES_REQUESTED**

**補足（レビュー観点 3,5,6,9,10,11）**

- PHPStan/DTO/JsonResource: 今回の主変更は TS/UI とテスト中心で、方針逸脱は見当たりません。  
- Inertia vs API: `flash` は Inertia props、2FA素材は Fortify GET 直 fetch で使い分けは妥当。  
- セキュリティ: 新規権限境界追加なし。ただし `{@html qrSvg}` は既存同様の信頼境界依存（未検証）。  
- DESIGN/Atomic: token 直書き増加なし、`pages -> atoms/molecules` 方向で規約適合。