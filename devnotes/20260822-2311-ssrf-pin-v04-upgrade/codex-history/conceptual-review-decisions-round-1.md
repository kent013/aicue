# 対応マトリクス: conceptual-review Round 1

Codex 判定: CHANGES_REQUESTED / Critical 0 / Warning 5 / Suggestion 6

## [Warning] lock 差分を「本体 1 件だけ」に狭めると正当な更新を失敗扱いにしうる
- 判断: **対応する**
- 根拠: 指摘は正しい。v0.4.1 は `guzzlehttp/psr7: ^2.4` と
  `psr/http-message: ^1.1 || ^2.0` を新たに require するので、
  「動くのは 1 件だけ」を無条件の合格条件にすると、
  新規依存の追加が必要な場合に判定が矛盾する。
  ただし aicue の lock を実測した結果、両者は既に
  `guzzlehttp/psr7 2.13.0` / `psr/http-message 2.0` として入っており、
  制約を満たすため**実際には新規取得は起きない**見込みである。
  「見込み」を合格条件に焼き込むのは危険なので、許容差分を明示する形へ改める。
- 対応内容: 概念設計の施策 B を書き換え、
  (1) 更新前に v0.4.1 の require を lock の現物と突き合わせて
      「新規に取る必要があるか」を先に判定する手順を入れ、
  (2) 許容差分を「`kent013/laravel-ssrf-pin` 1 件 + v0.4.1 が新たに要求する依存のうち
      lock に無かったもの + `content-hash`」と明示し、
  (3) それ以外のパッケージの版が動いたら**やり直す** (`--with-all-dependencies` を使わない)
  ことを条件として書いた。

## [Warning] resolver 差し替えが singleton の Inspector へ届かない可能性
- 判断: **対応する**
- 根拠: 指摘は正しく、かつ実装を実読して裏が取れる。
  `SsrfPinServiceProvider::register()` は
  `$this->app->singleton(UrlSafetyInspector::class, …)` で登録しており、
  `DnsResolverInterface` を後から bind しても**既に解決済みの instance は作り直されない**。
  既存の `tests/Pest.php::bindSnsDnsResolver()` はこれを知っていて
  `app()->forgetInstance(UrlSafetyInspector::class);` を必ず呼んでいる。
  概念設計はこの作法を「同じ作法で」と一言で済ませていて、
  肝心の `forgetInstance` を明示していなかった。
- 対応内容: 施策 C の書き方に
  「bind → `forgetInstance(UrlSafetyInspector::class)` → `app(...)` で解決」の
  3 手順を明示し、順序を守らないと**前のケースの resolver で判定してしまい偽グリーンになる**
  ことを理由付きで書いた。

## [Warning] 「登録簿が古くなると fail-open しうる」は不正確
- 判断: **対応する**
- 根拠: 指摘が正しい。v0.4 の `Unclassified` は**拒否**であり、
  「表に無い = 許可」という v0.2 型の fail-open は消えている。
  古い登録簿で起きうるのは「IANA が新たに『公開到達不可』へ分類した区間を
  まだ公開到達可能として持っている」= **分類の陳腐化**である
  (逆向き — 公開へ戻った区間を拒否し続ける — もありうる)。
  spirux の AGENTS.md の表現をそのまま写そうとしていたのが原因。
- 対応内容: 施策 E の記述を
  「未分類は拒否されるが、登録簿の到達可能性の判断が最新の IANA の状態を
  反映しない可能性がある (陳腐化)」へ改めた。「fail-open」の語は使わない。

## [Warning] 正の fixture が「実在・実到達の確認」へ化ける危険
- 判断: **対応する**
- 根拠: 妥当な予防。fixture は `FakeDnsResolver` が返す**分類上の入力値**であって、
  そのアドレスへ実際に接続できるかは 1 度も確かめていない
  (Architecture / Feature レーンは `StrayHttpRequestGuard` が外向き HTTP を既定拒否する)。
  意図をコメントに残さないと、後任が「本当に到達するか確かめよう」と
  外向き通信を足す余地が残る。
- 対応内容: 施策 D に、fixture の出所へ
  「これは分類表が globally reachable と判定する DNS 応答値であり、
  実在ホスト・実到達性の検証ではない。ここから外向き通信を足さない」を
  docblock として置くことを明記した。

## [Warning] 新設 gate が「導入版の分類能力」を担保する唯一の検査になるので必須ケースを明示列挙せよ
- 判断: **対応する**
- 根拠: 正しい。データプロバイダで畳むと「どの区間を見ているか」が
  差分から読めなくなり、1 件そっと削っても気付けない。
  本 gate は第二層 (package 契約検査) を持たない aicue で
  「入った版が実際に何を備えているか」を見る唯一の検査になるので、
  ケースの母集合が差分に現れる形でなければならない。
- 対応内容: 概念設計の施策 C に**必須ケース表**を追加し、
  8 区間 (IPv4 4 / IPv6 4) を区間名つきの個別ケースとして、
  期待 deny 理由 (`NotGloballyReachable`) まで書いた。
  古典区分・正のコントロール・混在応答・登録簿版も同じ表に入れた。
  詳細設計ではこの表がテストのケース名と 1 対 1 に対応する形で具体化する。

## [Suggestion] 登録簿版 pin は将来のパッチ更新で意図的に赤くなる旨を明記
- 判断: **対応する** (安いので取り込む)
- 対応内容: 施策 C の必須ケース表の登録簿版の行に
  「上流が登録簿を更新したらここが赤くなる。これは意図であり、
  更新時に登録簿の差分と回帰ケースを見直すための入口である」と書いた。

## [Suggestion] 脅威モデルの限定を維持する (任意 URL SSRF が直ちに防げるとは書かない)
- 判断: **対応する**
- 対応内容: 期待効果に「保証しないもの」を明示的に追加した
  (host は型で `sns.<region>.amazonaws.com` に固定されており、
  悪用には DNS の支配が要る / DNS rebinding は解消しない)。

## [Suggestion] PHPStan level 10 との整合 (resolver の型・ケース値の shape)
- 判断: **対応する**
- 対応内容: 制約の節に、fake resolver は package 同梱の
  `Kent013\SsrfPin\Testing\FakeDnsResolver` (`DnsResolverInterface` 実装) を使い
  自作しないこと、ケース値は `array<string, list<string>>` の形を守り
  `mixed` へ逃げないことを追記した。

## [Suggestion] 使命との整合性 / 触らない判断 / 型安全性は妥当
- 判断: **見送る** (肯定の評であり対応不要)
