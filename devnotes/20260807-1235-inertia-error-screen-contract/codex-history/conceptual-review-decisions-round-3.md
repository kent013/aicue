# 対応マトリクス: conceptual-review Round 3

Codex 全体判定: CHANGES_REQUESTED / [Critical] 0 件・[Warning] 5 件・[Suggestion] 3 件。

## [Warning] 観点 3: `X-Inertia-Version` の nullable 比較条件が未定義

- 判断: **対応する**
- 根拠: 正当かつ重要。`Inertia\Middleware::version()` の戻りは `?string` であり、
  `null === null` を「同じ build」と誤認すると配備境界が空洞化する。
  さらに aicue のテスト環境では `public/build/manifest.json` の有無が不定なので、
  この穴は実際に踏みうる。
- 対応内容: 「配備境界」節に **nullable の扱い (deny-by-default)** を追加。
  比較は両辺が非空文字列のときだけ成立とし、片方でも null / 空文字なら差し替えない。
  「version を持たない環境では機能が無効になる」ことを fail-safe な既定として明記。
  テストは `config(['app.asset_url' => …])` で version を決定的な非空文字列にし、
  build 生成物への依存を持ち込まない方針も明記。
  素通しテスト表に (version 欠落 / 空文字 / 現 version null / 一致 = 正のコントロール) を追加。

## [Warning] 観点 3: version 解決の失敗が fail-safe の外に出る

- 判断: **対応する**
- 根拠: 正当。manifest 読みは I/O であり throw しうる。fail-safe の範囲が
  `toResponse()` だけだと、原応答すら返せずに 500 が二重に壊れる。
- 対応内容: 「判定と生成の全体を fail-safe で包む」を明記
  (version 取得 → 目録判定 → route 解決 → DTO 生成 → `toResponse()` → ヘッダ移植の全段)。
  素通しテスト表に「version resolver が throw → 原応答と完全一致」ケースを追加。

## [Warning] 観点 4: eager 化の gate はソース文字列だけでは不十分

- 判断: **一部対応する / 一部反論する**
- 根拠 (対応する部分): 「`{ eager: true }` の文字列がある」だけでは eager 化の**性質**を
  保証しないという指摘は正当。よって振る舞いで固定する。
- 根拠 (反論する部分): 「`pnpm build` 後の manifest / bundle graph を検査する」案は**採らない**。
  `pnpm test` (vitest) を `pnpm build` の生成物に依存させると、テストレーンが build レーンに
  従属し、build 未実行の環境で恒常的に赤くなる。AGENTS.md の検証コマンド一覧は
  各コマンドが独立に green にできることを前提にしており (`verification-commands-doc-sync` が
  その一覧を固定している)、この従属関係を作るのは既存の運用契約に反する。
- 対応内容: gate を 2 段にした。
  1. **振る舞い**: `resolvePage("Error")` が遅延 loader を 1 度も呼ばずに component を返すことを
     単体テストで固定 (遅延 map をスパイに差し替え、呼ばれたら fail)
  2. **ソース**: eager glob の対象が `./pages/Error.svelte` であることを Architecture テストで固定
     (glob が広がって全ページ eager 化する退行も検出)
  併せて「この gate は resolver が遅延 loader を呼ばないところまでしか保証しない」ことを
  docblock に明記し、保証範囲を誇張しない。

## [Warning] 観点 5: `Retry-After` の本文解釈とヘッダ移植の関係が曖昧

- 判断: **対応する** (Codex 提示の 2 案のうち**後者**を採る)
- 根拠: 「解釈を SoT に一本化する」と言いながらヘッダだけ原値を素通しするのは自己矛盾。
  正規発行経路 (Laravel の `ThrottleRequests`) だけを契約対象にするなら、
  本文 / API `details.retry_after` / HTTP ヘッダの三者が同じ SoT を通る後者が一貫する。
- 対応内容: 「待ち時間 (429)」節と「差し替え後に保持するヘッダ (allowlist)」表を修正。
  `RetryAfterSeconds::parse()` が成功したときだけ**正規化した整数**をヘッダへ設定し、
  パース不能なら載せない。

## [Warning] 観点 6: 撤回済み変更が実装方針表に残っている

- 判断: **対応する** (単純な反映漏れ)
- 根拠: 指摘のとおり設計が自己矛盾していた。
- 対応内容:
  - 実装方針表から `tests/Feature/Security/TenantBoundaryPrecedenceTest.php` の行を削除
  - 「待ち時間 (429)」の「寄せても (c) の実挙動は変わらない」を
    「現在の正規発行経路では不変、不正形式は意図的に厳格化」に修正
  - スコープ外 (c) の「実挙動は不変」も同じ表現に統一

## [Suggestion] 観点 1 / 観点 2 / 観点 7 の肯定的評価

- 判断: **見送る** (対応不要)
- ただし観点 7 の補足 (「`RetryAfterSeconds::parse()` の戻り値が `int<0,max>|null` と
  推論されるよう、負数除外後の戻り経路を PHPStan に認識させる」) は詳細設計の
  PHPStan 適合チェックへ引き継ぐ。
