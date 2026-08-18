全体判定: CHANGES_REQUESTED

1. 使命との整合性

- [Suggestion] 顧客の SOP・シナリオ・撮影テイクを扱う経路の逆シリアライズ面を減らすため、North Star を支える基盤的な改善として妥当です。機能価値を直接増やす施策ではないため、「顧客価値の前提となる安全性」と位置付ける現状の説明が適切です。

2. 禁止事項違反

- [Warning] 施策 G はテスト専用機構の範囲を越え、`prism-prompt` の本番テンプレートキャッシュを無効化します。これは許容される安全対策ですが、「テストレーンだけ」と読むと矛盾します。
  - 修正提案: 制約・前提を「A〜F はテストレーン限定、G は既定でオブジェクトを保存する vendor 機能を本番でも無効化する設定変更」と明確に分離してください。また環境変数で再有効化できないことを ConfigHardeningTest で実効値まで pin してください。

- [Suggestion] `response()->json()`、LLM の直呼び、disabled UI などの禁止事項には該当しません。DTO／JsonResource も本件の変更対象外です。

3. 実現可能性

- [Critical] 「`CacheManager::repository()` を包めば全 driver・全書き込みを捕捉できる」という前提は不十分です。少なくとも tag cache は `Repository::tags()` が `TaggedCache` を返す経路を持ち、guard 付き Repository を継承しただけでは、その後の `put()` が検査を迂回し得ます。また `put()` だけの検査では `add()`、`forever()`、`putMany()`、`increment()`／`decrement()`、`remember()` 経由の保存を網羅したことになりません。
  - 修正提案: guard が対象とする Laravel の「値を保存し得る公開 API」の完全な funnel matrix を設計に明記し、`TaggedCache` を含む経路を guard 付き実装へ戻す、または明示的に hard fail する方針を決めてください。各 API が「検査される」「値を保存しない」「禁止される」のいずれかであることを Feature テストで固定してください。

- [Warning] `Cache::extend()`、`getStore()`、直接生成を静的に禁止するだけでは、公開 API から native store を得る他の到達経路が残るおそれがあります。L4 の検査対象を先に固定しないと、「Repository を通る」という実装仮定だけで穴が残ります。
  - 修正提案: Laravel 12 の `CacheManager`／`Repository`／`TaggedCache` の公開メソッドを基準に、受け皿を迂回して Store に到達する API を棚卸ししてください。許容しないものは L4 で deny-by-default、許容するものは実行時 guard に確実に接続してください。

4. 期待効果の妥当性

- [Warning] 実行時層は vendor を「実際に実行した場合」にのみ捕捉します。`PromptTemplate::fromYaml()` のように現在の呼び出し元がないコードを、防御済みであるかのように読める表現は正確ではありません。これは静的層だけが見える例ではなく、現時点ではどちらの層も実行上は発火しない休眠経路です。
  - 修正提案: 期待効果を「将来 vendor 経路がテストで実行された際の値検査を追加する」と限定してください。加えて施策 G により当該経路を既定で無効化するなら、その効果は runtime guard ではなく設定による閉鎖として分けて記述してください。

- [Suggestion] array store の非直列化性は runtime 値検査を否定する根拠にならない、という AG-151 に沿った訂正は合理的です。

5. リスク

- [Critical] 全レーンに install することで、framework／vendor の未調査の書き込みが大量に露出する可能性があります。10 ファイル以上で止める基準だけでは、1〜9 件の vendor 違反に対して「設定無効化・パッケージ修正・不使用」のいずれを誰が決めるかが不明確です。テストを常時赤のままにするリスクがあります。
  - 修正提案: 実装前に、検査対象 API を通る既存テスト一式で検出される書き込みの記録方法と、露出時の判断責任を決めてください。vendor 由来で即時に解消できない場合は、実装を未完了として設計／台帳議題へ戻すことを明記してください。

- [Warning] accumulator を static に置く場合、テストの失敗・例外・Pest の hook 順序によって次のテストへ違反が漏れるリスクがあります。
  - 修正提案: `install → flush → reset` の正常系だけでなく、テスト本体が例外を投げた場合、アプリが例外を握り潰した場合、複数違反の場合の reset を Feature テストで固定してください。

6. スコープの適切さ

- [Warning] A〜H は、runtime guard、既存 1455 行 gate の拡張、設定変更、複数の規約文書、テンプレート差分登録まで含みます。目的は一貫していますが、L4 の新設と G の本番設定変更は特に独立した検証対象です。
  - 修正提案: 実装順を「runtime guard と結線 → L4 → prism-prompt 設定閉鎖 → 文書・差分台帳」とし、各段階でテストが緑であることを完了条件にしてください。G が既存の本番利用に影響するなら、設定変更の根拠を独立してレビュー可能にしてください。

- [Suggestion] RateLimiter を本 feature の保証範囲から明示的に外す判断は、既存の責務分担を尊重しており妥当です。runtime guard の docblock に保証外として明記する方針も適切です。

7. 型安全性

- [Warning] `Repository` の継承・差し替えは Laravel の戻り値契約、`TaggedCache`、Store の型、Facade 解決との整合が必要です。概念設計にはクラス間の型契約がまだありません。
  - 修正提案: `GuardedCacheRepository`、manager、値検査器、例外、accumulator の公開メソッドと戻り値を先に定義し、Laravel 12 のシグネチャと完全互換にしてください。`mixed` を入口で受ける場合も、再帰検査後の扱いを型で曖昧にせず、PHPStan level 10 と正負テストで保証してください。

- [Suggestion] UI・HTTP レスポンス・DTO／JsonResource の変更を含まないため、それらのパターンへの影響はありません。ただしテスト Support も PHPStan 対象である以上、配列・自己参照検査・例外収集の型契約は実装設計で明示すべきです。