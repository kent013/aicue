# 対応マトリクス: design-review Round 1

Codex 判定: CHANGES_REQUESTED / Critical 0 / Warning 6 / Suggestion 4
施策別: 0 APPROVE / A APPROVE / B REQUEST_CHANGES / C REQUEST_CHANGES /
D REQUEST_CHANGES / E REQUEST_CHANGES / 乖離台帳 APPROVE / 第二層を作らない判断 APPROVE

**Warning 6 件すべてと Suggestion 4 件すべてに対応した。反論・見送りは無い。**

---

## [Warning] 施策 B: 「名前 → 版」比較だけでは許容差分を機械的に保証できない
- 判断: **対応する**
- 根拠: 指摘が正しい。同じ版のまま `source.reference` / `dist` / `require` /
  `autoload` だけが動く形は「名前 → 版」の写像では検出できない。
  設計文が「対象エントリと content-hash 以外は不変」と宣言しているのに
  検証手段がそれより弱いのは、**検査が設計より緩い**状態であり、
  本アプリが繰り返し嫌ってきた偽グリーンそのものである。
- 対応内容: 施策 B の照合手順を書き換えた。
  (1) lock の**構造比較**に変える — ルートの `content-hash` /
      対象パッケージのエントリ全体 / 事前承認した新規依存のエントリの
      3 つを除外し、**残りが完全一致**することを検査する
      (`packages` / `packages-dev` の両方 + ルートの他キーも対象)。
  (2) 対象エントリの `version` / `source.type` / `source.url` /
      `source.reference` / `require` を個別に assert する手順を明示。
  (3) **新規依存が見積り (0 件) に反して出た場合は自動的に許容せず設計へ戻る**
      ことを合格条件へ明記した (「事前承認した」ものだけが除外対象である)。
  (4) 照合スクリプトを構造比較版へ差し替えた。

## [Suggestion] 施策 B: Composer 自身の版を実装記録へ残す
- 判断: **対応する**
- 根拠: lock のメタデータ差分は Composer の版でも変わる。原因追跡が安くなる。
- 対応内容: 施策 B の記録項目に `composer --version` の出力
  (設計時点の実測: `Composer version 2.10.2 2026-07-01 11:24:45`) と
  `php -v` (`PHP 8.4.24`) を残すことを足した。

## [Warning] 施策 C: S4 の docblock の主張と検査対象が一致していない
- 判断: **対応する**
- 根拠: **これは実質的な検出力の穴で、指摘が完全に正しい。**
  docblock は「A + AAAA の全件を検査する」と書いているのに、
  実際のケースは A レコード内の 2 件だけだった。
  「A が 1 件でも公開なら AAAA を無視する」という後退が入っても緑になる。
  セキュリティ境界の検査としてこれは許容できない
  (攻撃者は A に公開 IP を置き AAAA に内部アドレスを置けばよい)。
- 対応内容: S4 を 1 ケースから **3 ケースの dataset** へ拡張した。
  - `A 内で 公開 + 特殊用途`
  - `公開 A + 特殊用途 AAAA` (family 交差)
  - `特殊用途 A + 公開 AAAA` (family 交差・逆向き)

  3 つすべてが `NotGloballyReachable` になることを固定する。
  **上流 v0.4.1 と現 vendor v0.2.0 の両方に対して実測して確認した** —
  v0.4.1 は 3 件すべて deny、v0.2.0 は 3 件すべて allow (= 3 件が期待 fail になる)。
  あわせて S3 に「公開 A + 公開 AAAA」を足し、
  **両 family が揃っていれば通る**ことを正のコントロールで固定した
  (これが無いと「AAAA があると必ず deny」という壊れ方で S4 が緑になる)。
  → 版上げ前の期待 fail は **11 件から 13 件**へ変わった (再実測済み)。

## [Warning] 施策 C: gate 整合表の `print` が AGENTS.md と矛盾する
- 判断: **対応する**
- 根拠: 指摘が正しい。**現物を確認した** — `AGENTS.md` L217 に
  「**語彙を勝手に増やさない**。`print` は正典が対象外と定めており」とあり、
  `tests/Support/ForbiddenStatement/ForbiddenStatementKind.php` の docblock も
  「`print` は正典が明示的に対象外としており、禁止語彙の拡張は台帳の議題」と書いている。
  設計文が禁止語彙を 1 つ多く書いていたのは、検出力の説明としての誤りである
  (実装違反ではないが、後任が「print も禁止だ」と誤って広げる誘因になる)。
- 対応内容: 整合表から `print` を削除し、実際の対象である
  「出力する文 (`echo`) / 飛び越す文 (`goto`) / 大域を持ち込む文 (`global`) /
  開始タグ付き出力記法 (`<?=`)」の 4 語彙だけを書いた。
  併せて「語彙を勝手に増やさない」ことも注記した。

## [Suggestion] 施策 C: 「順序が本質」は「両方が resolve より前」と書くのが正確
- 判断: **対応する**
- 根拠: 正確な指摘。`bind` と `forgetInstance` の**相互の**順序は入れ替えても等価で、
  本質は「**どちらも `app(UrlSafetyInspector::class)` より前**」であることだけである。
  「順序が本質」とだけ書くと、後任が bind / forgetInstance の相互順序を
  触れないものと誤解する。
- 対応内容: gate の docblock の該当箇所を
  「`bind` と `forgetInstance` の相互順序は問わない。本質は**両方が resolve より前**に
  あることである」へ書き換えた。

## [Warning] 施策 D: literal 検索だけでは版上げの全影響を列挙したことにならない
- 判断: **対応する**
- 根拠: 指摘が正しい。TEST-NET 系の文字列検索は「たまたま literal で書かれている」
  fixture しか見つけられず、定数や fixture 関数を経由して特殊用途アドレスを
  返す形は取りこぼす。母集団は「分類を通る DNS 応答 fixture 全体」であるべきで、
  そこへ到達するには**シンボル単位**で呼び出し元を辿る必要がある。
- 対応内容: 施策 D に**シンボル単位の全数調査**の節を新設し、
  指摘された 6 シンボル + 2 件を**実際に走査してその結果を表として記録**した。
  この調査で**新しい事実が 1 件出た** —
  `tests/Feature/Mail/SesSignatureMiddlewareTest.php:98` に
  `bindSnsDnsResolver(['10.0.0.5'])` があり、
  「変更しない呼び出し」の表に載せ漏れていた (private なので挙動は変わらないが、
  母集団の記録としては欠けていた)。表に追加した。
  調査の結論として次も確定した:
  - `PinnedHttpClient` の**実使用は 0 件** (docblock と config のコメントだけ)
    → `max_body_bytes` を app 側 config に持たない判断の裏が取れた
  - `SsrfDenyReason` の参照は `SnsCertificateFetcher` の**単一比較 1 か所だけ**で
    網羅 `match` が無い → case 追加で PHPStan が落ちないことの裏が取れた
  - `FakeDnsResolver` の生成は `tests/Pest.php` の 1 か所だけ
  - `bindSnsDnsResolver()` の呼び出しは全 6 件 (変更 3 / 据え置き 3)
  - 新たに拒否される 8 区間のうち、fixture として使われているのは
    TEST-NET-3 のみ (他 7 区間は 0 件)

## [Warning] 施策 E: 「安全境界は config に pin」と「境界の一部は登録簿」が矛盾して読める / 「^0.4 以降」は将来版まで保証するように読める
- 判断: **対応する**
- 根拠: 両方とも正しい。
  (1) 既存文と追記が同じ「安全境界」という語を別の対象に使っており、
      責務の切れ目が読めない。
  (2) 「`^0.4` 以降」は自然言語として 0.5 系以降も同じ方式であることを
      約束してしまう。実際に固定できるのは**いま採用している 0.4 系の契約**だけで、
      `^0.4` は 0.5 を取らない制約なので、将来版の方式は未知である。
- 対応内容: 施策 E の文面を責務で 2 段に分けて書き直した。
  - **アプリ設定の 5 値**は `config/ssrf-pin.php` と `SsrfPinBoundaryTest` で pin
  - **分類の実装と登録簿**は `composer.lock` の package revision と
    `SsrfPinSpecialPurposeRangeRegressionTest` で受ける

  「`^0.4` 以降」を「**現在採用している 0.4 系**」へ改め、
  将来版は「gate が赤くなった時点で再評価する」という監視条件に留めた。

## [Warning] 横断: AGENTS.md が要求する検証コマンドが完了条件から漏れている
- 判断: **対応する**
- 根拠: 指摘が正しい。**現物を確認した** — `AGENTS.md` L335-341 の
  `<!-- VERIFICATION_COMMANDS:BEGIN -->` 節が 10 コマンドを列挙しており、
  `tests/js/architecture/verification-commands-doc-sync.test.ts` が
  `package.json` の検証系 script との同期を deny-by-default で強制している。
  受け入れ基準には `pnpm test` / `pnpm typecheck:packages` /
  `pnpm build:packages` / `pnpm test:packages` の 4 件が欠けていた。
  frontend 無変更でもリポジトリの完了契約なので省略できない。
- 対応内容: 受け入れ基準の該当項目を、`VERIFICATION_COMMANDS` 節の
  **10 コマンドの全数**へ差し替えた (`composer test` / `composer phpstan` /
  `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` /
  `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` /
  `pnpm test:packages`)。

## [Suggestion] 施策 0: 対象ファイルを直接指定した差分確認のほうが判定しやすい
- 判断: **対応する**
- 根拠: `git diff --name-only` 全体を目で見る形は、無関係な dirty 差分があると
  判定がぶれる。パスを指定して「空であること」を見るほうが機械的。
- 対応内容: 施策 0 の検証手順を
  `git diff --name-only -- <4 パス>` が**空**であることを見る形へ変え、
  `git status --porcelain` でも同じ 4 パスを確認する手順にした。

## [Suggestion] 乖離台帳 / 第二層を作らない判断は妥当 (APPROVE)
- 判断: **見送る** (肯定の評であり対応不要)
- 根拠: 「登録を避けたいから登録しない、という判断にはなっていない」と
  明示的に評価されたので、根拠の書き方は現状のまま維持する。
