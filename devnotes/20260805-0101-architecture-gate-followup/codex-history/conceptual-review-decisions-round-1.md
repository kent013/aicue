# 対応マトリクス: conceptual-review Round 1

## [Critical] `<meta name="description">` 禁止の根拠不足 (description の backend SoT が示されていない)

- 判断: **反論する (+ 指摘の建設的部分は設計に取り込む)**
- 根拠: description には **既にサーバ単一 SoT が存在する**。実ファイルで確認済み:
  - 設定ソース: `config/seo.php` の `default_description`
    (`env('SEO_DEFAULT_DESCRIPTION', '')`、`.env.example:137` に枠あり)
  - DTO: `app/Support/Seo/SeoMeta.php` の `public string $description` +
    `withDescription()`
  - 公開ページ描画: `app/Support/Seo/SeoRenderer.php::render()` が
    `<meta name="description">` / `og:description` / `twitter:description` を描画
  - 認証配下: `renderPrivate()` は description を**意図的に出さない** (noindex ページに
    メタを残さない)
  そして `resources/js/pages/` には公開ページの実体が含まれる:
  - `Welcome.svelte` ← `HomeController` (`home`, full 分類)
  - `Guest/Pricing.svelte` ← `PricingController` (`pricing`, full 分類) が
    **`->withDescription('AI-CUE の料金プラン…')` を実際に供給している** (L62)
  よってここで `<svelte:head><meta name="description">` を書くと、同一 `<head>` に
  description が **2 個並ぶ** (クローラから見た明確な defect) 上、サーバ側にしかない
  `og:description` / `twitter:description` と食い違い、**SNS カードと検索結果の説明文が
  別物になる**。認証配下でも「noindex なのに description だけ生える」不整合が復活する。
  つまり description を含めるのは「守るべき契約を先に増やす」のではなく、
  **既にサーバ側にある契約を破らせないための同一の禁止**である。
- 対応内容: 指摘の建設的部分 (「backend の description 解決経路・設定ソース・
  共有経路を設計に書け」) は正当なので、概念設計 §課題 4 に
  「`<meta name="description">` も同じ gate で禁止する根拠 (サーバ SoT の所在)」
  節を新設し、上記の対比表と具体ファイル/行を明記した。
  併せてスコープが膨らまないよう **`og:description` / `twitter:description` は
  今回対象外**と明示した (「今必要なものだけ作る」)。

## [Warning] `AGENTS.md` 追記案「`*NoOverflow` 必須」が `*WithOverflow` 明示許可と衝突

- 判断: **対応する**
- 根拠: 指摘のとおり。規約文と gate の契約がずれると、規約を読んだ人が
  `*WithOverflow` を「規約違反だが gate は通る」と誤解する (最悪の状態)。
- 対応内容: 概念設計 §実装方針 1 の追記文面を
  「月/年/四半期の加減算は**暗黙 overflow メソッドを禁止**する。既定は `*NoOverflow`、
  overflow が要件なら `*WithOverflow` を明示して意図をコードに残す」へ変更し、
  「必須」と書くと衝突する理由も併記した。

## [Warning] `setPrivateTitle` をメソッド本体固定にすると helper 抽象化で偽陽性が増える

- 判断: **対応する**
- 根拠: responder 化 / private helper への抽出は Laravel で十分ありうる。
  ただし無制限追跡は gate を小さな静的解析器に肥大化させ、逆に精度も落ちる。
  指摘の「追跡しないなら allowlist 条件を仕様として先に固定せよ」が本質。
- 対応内容: 射程を **1 hop (同一クラスの private/protected メソッド)** に仕様固定し、
  それ以上の間接化 (trait / 基底クラス / 別クラス responder) は
  「静的に決定できない」として**理由付き allowlist を要求する**と概念設計に明記した
  (`INERTIA_DYNAMIC_ALLOWLIST` と同じ deny-by-default の考え方)。

## [Warning] route 名単位 gate の説明に `Invitations/Invalid` の分岐タイトル話が混ざり責務境界が曖昧

- 判断: **対応する**
- 根拠: 指摘のとおり。gate の射程を誤解させる書き方だった。
- 対応内容: 概念設計 §実装方針 3 に「責務境界: gate が守る範囲と、手動是正の範囲」
  節を新設し、表で「route 既定タイトル = gate が強制」「分岐ごとのタイトル =
  gate 対象外」と分離。`Invitations/Invalid` は **施策 3b (gate なし・手動是正)** として
  施策一覧の表にも独立行で切り出した。分岐タイトルの機械強制は follow-up 議題と明記。

## [Suggestion] `git ls-files` 走査は未追跡ファイルを拾わない点を注記すべき

- 判断: **対応する**
- 根拠: 開発時の体感 (「add したら急に赤くなる」) を説明しておく価値がある。
- 対応内容: §実装方針 2 に「git 追跡ベースの既知の限界」を追記。
  gate が守るべき境界は commit / CI であり実効性は損なわれないこと、
  テスト冒頭コメントに明記することを設計に含めた。
  併せて gate 3 は `Route::getRoutes()` 走査なのでこの限界を持たない旨も明記。

## [Suggestion] `Route` action 解決結果とトークン走査結果の型を小さな DTO 風に固めるとよい

- 判断: **対応する (ただし DTO クラスは作らない形で)**
- 根拠: 型を先に固める意図には全面的に賛成。ただし `InertiaRenderPageExistsInvariantTest`
  の既存作法は「テストファイル内の純関数 + PHPDoc array shape」であり、
  テスト専用 DTO クラスを `app/` に増やすのは本アプリの規約 (本番コードでない型を
  `app/` に置かない) と逆行する。
- 対応内容: §制約 に「走査結果の型」を追記。array shape
  (`array{page: string, location: string}` 等) を**先に決めてから実装する**ことを
  詳細設計で固定すると明記した。

## [Suggestion] その他 (使命整合・実現可能性・スコープ・型安全性)

- 判断: 見送る (肯定的評価のため対応不要)
