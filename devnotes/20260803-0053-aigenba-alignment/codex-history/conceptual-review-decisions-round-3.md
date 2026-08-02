# 対応マトリクス: conceptual-review Round 3

## [Critical] 観点2 — Browser 検証手段が「E2E **または** bug-hunt」では禁止事項 #1 に抵触する

- **判断: 対応する**
- **根拠**: 全面的に正しい。bug-hunt は**自由探索型で、走行ごとに何を見るかが変わる**
  (`.claude/skills/app-bug-hunt/SKILL.md`)。一度の確認で「テスト済み」を主張できる性質のものではなく、
  恒久的な回帰テストの代替にならない。AGENTS.md 禁止事項 #1 は
  「不変条件は**対応する Architecture/Feature テストへの登録まで含めて**『実装済み』」と定義しており、
  私の「E2E または bug-hunt」という書き方はこの定義を緩めていた。
- **対応内容**: P3 の**完了条件を自動 Browser E2E テストの成立に固定**した。
  - 恒久テストの配置場所: `tests/Browser/`
  - 標準実行コマンド: `scripts/run-browser-test.sh` (`docs/testing-browser.md` が運用契約)
  - **bug-hunt は追加の探索確認としてのみ**扱い、完了条件から外した。

## [Warning] 観点3 — P3 のヘッダ契約が内部矛盾している

- **判断: 対応する（私の記述誤り。aigenba 実装を確認して契約を確定した）**
- **根拠**: 指摘のとおり「既存ヘッダを上書きしない」と「`no-store` が無ければ付与する」は
  `private, max-age=60` のようなケースで両立しない。**私の Round 2 の記述が誤り**だった。

  aigenba の実装 (`app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php:56-63`) を
  読み直したところ、実際の契約は Codex 提案の**後者**だった:

  ```php
  // 既に no-store を持つ応答 (recent-auth 409 / SSE stream 等、内側で明示された
  // より厳格な値) は書き換えず維持する。directive が縮む方向の上書きをしない。
  if ($response->headers->hasCacheControlDirective('no-store')) {
      return $response;
  }
  $response->headers->set('Cache-Control', 'no-store, private');
  ```

  = **`no-store` directive を持つなら untouched。持たないなら `Cache-Control` 全体を
  `no-store, private` で置換する**。`private, max-age=60` は `no-store, private` に置き換わる。
  「合わせる」方針からも、セキュリティ baseline の目的からも、これが正解。

- **対応内容**: 契約を**一意に**書き直した。
  - 判定キー: `Cache-Control` の**存在ではなく `no-store` directive の有無**。
  - `no-store` 有り → untouched (内側で明示されたより厳格な値を尊重。directive が縮む方向の上書きをしない)。
  - `no-store` 無し → `Cache-Control` を **`no-store, private` で置換**。
    これにより `public` / `max-age` 等の矛盾 directive は**置換によって消える** (別途の正規化ロジックは不要)。
  - Round 2 で私が書いた「既存ヘッダを上書きしない」という記述は**撤回**した。

- **併せて aigenba 実装から取り込んだ細部** (Round 2 までの私の設計に欠けていた):
  - **認証判定は `$next()` の前に捕捉する**。logout POST は `$next` 通過後に guard 上の user が
    null になるため、リクエスト時点の状態を先に取らないと **logout redirect 自体が対象から漏れる**。
    さらに応答時点でも判定し、どちらかが認証済みなら付与する
    (login POST 応答 = 応答時点で認証済み、も保護側に倒す)。
  - **判定は `$request->hasSession() && $request->user() !== null`**。
    session を持たないリクエスト (AI-CUE も `routes/web.php:74,99` で
    `withoutMiddleware([..., StartSession::class])` の stateless block を持つ) は
    公開配信として素通しする。**AI-CUE にも同じ構造があるため、この判定はそのまま適用できる**。

- **Round 2 の「response class による除外」について**: aigenba はクラス判定を持たず
  **ヘッダ判定のみ**で運用している。AI-CUE 側の実測でも `BinaryFileResponse` は
  `app/Http/Controllers/Testing/GetFakeStorageObjectController.php` の 1 件のみ (非 production 用の
  fake storage gate)、`StreamedResponse` は 0 件。**クラス判定を足す必要性が現時点で無い**ため、
  aigenba と揃えてヘッダ判定のみとし、`Testing/` の 1 件は詳細設計で挙動を確認する
  (思考原則 #2「今必要なものだけ作る」)。

## [Warning] 観点4 — 「既存4経路が untouched」と「将来の矛盾値を検出する」は同義でない

- **判断: 対応する**
- **根拠**: 正当。`no-store` の存在だけを見るテストでは `public, no-store` を検出できない。
- **対応内容**: 既存 4 経路のテスト契約を**ヘッダ完全値の固定**に変更した
  (`FortifyServiceProvider:199` = `no-store` / `RequireRecentAuth:57` = `no-store` /
   `RequireTwoFactorForEnforcedOrganizations:93` = `no-store` /
   `CaptureTakeController:177` = `no-store, private`)。
  実測値を詳細設計で確定し、完全一致でピンする。

## [Critical 相当に格上げ] 観点5 — Safari をスコープ外にする判断は AI-CUE では成立しない

- **判断: 対応する（Codex は Warning としたが、AI-CUE では格上げが必要と判断した）**
- **根拠**: Codex は「サポートブラウザ方針に基づく除外であることを前提にせよ」と条件付きで
  認めているが、**AI-CUE ではその前提が成立しない**。
  - AGENTS.md の使命に「**撮影は PWA (同一オリジン・セッション認証)**」「**スマホ (PWA) で
    ナビゲーション撮影**」と明記されている。**iOS Safari は主要プラットフォーム**であり、
    「サポート対象外」にはできない。
  - 現場の共用端末という P3 の想定シナリオは、まさにスマホ / タブレット共用を含む。
  - リポジトリを調べたが、**サポート対象ブラウザの方針はどこにも文書化されていない**
    (`DESIGN.md` / `docs/*.md` / `package.json` の browserslist を確認、いずれも記載なし)。
    つまり「方針に基づく除外」という逃げ道自体が存在しない。
  - したがって `no-store` だけでは **P3 の主便益が主要プラットフォームで達成されない**。
    これを「限界として記録」で済ませると、Round 3 Critical と同じ「過大申告」になる。
- **対応内容**:
  1. P3 を **2 コンポーネント構成**にした。
     - **P3-a (サーバ)**: `no-store` baseline middleware (aigenba 整列分)。
       Firefox は bfcache 格納を拒否、Chrome は cookie 変更時に CCNS ページを evict。
     - **P3-b (クライアント)**: `pageshow` の `event.persisted === true` を検知したときに
       **再検証 / 再読込**する。iOS Safari のように `no-store` でも bfcache へ格納するブラウザで、
       復元表示を潰す。**これは aigenba に無い AI-CUE 固有の追加**であり、
       乖離として台帳に記録し **aigenba へ返す候補**とする (aigenba も PWA を持つなら同じ穴がある)。
  2. **サポート対象ブラウザ方針を明文化する**ことを P3 の成果物に追加した
     (現状どこにも無いため、`DESIGN.md` または `docs/` に記載する。詳細設計で置き場所を決める)。
  3. 成果指標から「Safari はスコープ外」という記述を削除し、
     **P3-a + P3-b で主要プラットフォームを覆う**構成に改めた。

## [Warning] 観点1 — 冒頭の「保証する」と後段の「Safari スコープ外」が矛盾

- **判断: 対応する**
- **根拠**: 正当。上記のとおり Safari を外さない構成にしたため、矛盾自体が解消される。
- **対応内容**: 主便益の表現を「**サポート対象ブラウザで再表示を防止する**」に変更し、
  対象ブラウザの明示を詳細設計の必須項目とした。

## [Suggestion] 観点6 / 観点7 — トラック分割・型安全性の方針は妥当

- **判断: 対応不要**（肯定的評価）
