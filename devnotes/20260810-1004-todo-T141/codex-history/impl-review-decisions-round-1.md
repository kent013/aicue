# 対応マトリクス: impl-review Round 1

## [Critical] Cashier API allowlist が exact-fit になっていない

- 判断: **対応する**
- 根拠: 指摘のとおり。根拠文さえ書けば `charge` / `cancelNow` を allowlist へ足して
  **検出面を静かに狭められる**。これは gate の fail-open であり、
  `ExternalSeamInventoryTest` が「規則が名乗れる種別」を二重宣言で pin しているのと同じ穴だった。
- 対応内容: 検査 7 に **allowlist キー集合の exact-fit pin** を追加した
  (`['active', 'subscriptions', 'user']` と一致しなければ赤)。
  検出面を狭めるには **2 箇所**を触らせる (意図的な摩擦)。

## [Critical] container binding 経由の実装到達を閉包が辿れない

- 判断: **対応する**
- 根拠: 妥当。`App\Contracts\Foo` を型注入して provider で concrete に bind する形は
  Laravel では通常の依存辺であり、interface で閉包が止まると実装側の `Stripe\StripeClient` に
  到達しない。「依存閉包」という名前が果たすべき役割を満たしていなかった。
- 対応内容: **`implements` の逆向きの辺**を導入した。閉包に interface が入ったら、
  その app/ 内実装クラスを閉包へ引き込む (`deletionPathImplementedInterfaces()` +
  `deletionPathTraverse()` の implementors)。fixture 8 形目で固定した。
  - **`extends` は逆向きに辿らない**。`AccountController extends Controller` から
    app/ の全 Controller が閉包に入り信号が死ぬため。この限定と、
    残る穴 (abstract 基底への bind / closure binding / contextual binding / 別名文字列 bind) は
    冒頭 docblock の「保証しないもの」へ明記した。正のコントロール fixture でも固定した。
  - 実アプリの閉包メンバーは 53 件のまま変化なし (現時点で閉包内に App の interface が無いため)。
    すなわち今日の効果は 0 で、**将来 interface 注入が入ったときに効く**予防である。

## [Warning] `->{'stripe'}()` の literal 動的メソッド呼び出しが検出から落ちる

- 判断: **対応する**
- 根拠: そのとおり。動的からも記号照合からも落ちていたので、この 1 つの書き方で素通りできた。
- 対応内容: `deletionPathLiteralDynamicCalls()` を追加し、literal の `->{'name'}()` /
  `::{'name'}()` を**通常の呼び出しと同じ規則で分類**するようにした (fixture 7 形目)。
  literal は「動的」には二重計上しない。

## [Warning] `DeletionPathSeamExemption` の照合キーが docblock と実装でずれている

- 判断: **対応する**
- 根拠: 実装は `path:line` 入りの記述子を照合キーにしており、docblock の宣言
  (`{FQCN}#{記号}`) と一致していなかった。行移動で免除が壊れる脆い設計でもあった。
- 対応内容: hit を `array{symbol, descriptor}` に分け、**symbol は行番号を含まない安定キー**にした。
  照合は `$class.'#'.$hit['symbol']`。enum の docblock に symbol の実例 4 形を書いた。

## [Warning] 自己参照コントロールが設計どおりでない (edges 未確認 / root の exact-fit が弱い)

- 判断: **対応する**
- 根拠: 妥当。「到達 0 件」と書いていたが実際は edges を見ておらず、
  しかも本ファイルは免除 enum を import するので **0 件は成立しない** = 実装より強い保証を謳っていた。
  root の exact-fit が無かったことは mutation M1 が緑だった事実とも符合する。
- 対応内容:
  - 検査 5 を **edges の exact-fit pin** に変えた
    (`['App\Enums\Security\DeletionPathSeamExemption']` ちょうど)。docblock も実装に合わせた。
  - **検査 8 (起点集合の exact-fit pin)** を新設した。起点を静かに減らせなくなった。

## [Warning] A2 の「API を呼ばない」テストが StripeGatewayInterface にしか効かない

- 判断: **対応する**
- 根拠: 妥当。Cashier / Stripe SDK を直接使えば mock を経由せず、Feature テストは緑のまま通る。
- 対応内容: **検査 9** を新設し、`MarkStripeCustomerRedactedCommand` 自身を静的走査して
  決済事業者記号 0 件・動的呼び出し 0 件を固定した (このコマンドは退会経路の閉包に入らないため
  名指しの検査が要る)。Feature テストの mock は残す (並存)。

## [Warning] architecture.md の未 pin 記述と pin 解消の追記が矛盾して見える

- 判断: **対応する (ただし既存行は書き換えず「畳む」)**
- 根拠: 指摘は妥当。ただし本タスクは並列実行中で、共有ファイルの既存行の書き換えは
  他タスクとの衝突源になるという制約がある。Codex 自身が挙げた 2 案のうち
  「**過去経緯として明確に畳む**」方を採った。
- 対応内容: 直上 bullet の直後に「**⚠ 直上の bullet は経緯として残した過去記述である**。
  2 点は T141 で解消済みで、現在状態は直下の 3 bullet が正本」という bullet を挿入した。

## [Suggestion] runbook の tinker 案内を dry-run 出力に寄せる

- 判断: **対応する**
- 根拠: A3 の「新しい探索経路を作らない」という意図とちょうど一致する。
  dry-run は 1 列も書かないので確認手順として安全でもある。
- 対応内容: §2 手順 1 を `billing:mark-stripe-customer-redacted <id>` の dry-run 出力へ差し替えた。

## [Suggestion] `--customer=` で期待値照合する余地

- 判断: **見送る**
- 根拠: 思考原則 2 (今必要なものだけ作る) と詳細設計 A2 の範囲外。現時点で
  `stripe_id` が差し替わる経路は存在せず、照合すべき「期待値」の出所も運用チケットしかない。
  監査精度が問題になるのは「差し替え経路ができたとき」であり、そのときに
  記録列 (`stripe_customer_redacted_id`) との突合を入れれば足りる (列は既にある)。
  runbook §4 に「事業者側 job id は運用チケットに残す」と明記して補っている。

## [Suggestion] / [Approved] その他

- migration / Organization / factory / DirectFetchInventory は Approved のため対応なし。
