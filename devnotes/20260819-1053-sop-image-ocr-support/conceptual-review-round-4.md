全体判定: **CHANGES_REQUESTED**

Round 3 の主要論点には概ね適切に対応されています。特に、SOP 内の手順文とモデル操作を狙う注入文の区別、画像 injection 評価の公開前必須化、費用上限の表現是正、provider 固有性・失敗率・単一読み込みの明確化は改善されています。

ただし、媒体 DTO union を vendor 型へ変換する箇所の PHP 設計が成立しないため、型安全性の観点で修正が必要です。

### 1. 使命との整合性

- [Suggestion] 紙・写真・スキャン PDF しかない現場を起点にできるようにするため、North Star への寄与は直接的で大きいです。既存の成功経路を変えず、現在必ず失敗する入力だけを救済する範囲設定も適切です。

### 2. 禁止事項違反

- [Warning] 非対応 provider の fail-fast が、チケット予約との順序で明確ではありません。`startJob` 後に provider 非対応を検知する場合、必ず `release` されること、`commit` されないことを仕様・Feature テストで固定してください。

  修正提案: provider/model 対応可否を「予約前に検証する」のか「予約後でも必ず `failJob` により release する」のかを明記し、非対応 provider の場合に reserve/commit/release がそれぞれ期待どおり高々 1 回になるテストを追加してください。

- [Suggestion] prompt を YAML に置き、factory → `PromptDefense` → `GuardedPrompt` の一本道を維持する方針は、禁止事項 5・6 と整合しています。

### 3. 実現可能性

- [Critical] `match ($media)` を DTO 型に対する網羅的分岐として使う記述は、PHP 8 では実現できません。PHP の `match` は値の厳密比較であり、`ImageAnalysisMediaData` のような型パターンには一致しません。したがって「`match (true)` は使わず、default なしの型網羅 `match`」という設計は Laravel 12 / PHP の言語仕様上成立しません。

  修正提案: 次のいずれかに設計を改めてください。

  - 媒体 DTO に `toVendorMedia()` を持たせず、窓口だけが扱える visitor 方式を導入する。
  - 窓口内で `if ($media instanceof ImageAnalysisMediaData) { … } elseif ($media instanceof PdfAnalysisMediaData) { … } else { throw LogicException…; }` とし、union 型・PHPStan・静的 gate で対象型を固定する。
  - `match (true)` を使うなら、これは真偽値ぼかしではなく PHP における型判別の必要な表現であることを明記し、`default` では例外にして型追加時の更新漏れをテストと PHPStan で検出する。

  現状のままでは実装不能であり、「新しい媒体型を足したら PHPStan が落ちる」という主張も保証されません。

- [Warning] 「検証済み DTO だけが窓口へ届くため、未検証バイト列を型で消す」は、DTO の生成子が公開なら成立しません。PHP の union は媒体の種類を表せますが、値が検証済みであることまでは表せません。

  修正提案: 両 DTO を `final` にし、コンストラクタを `private` としたうえで、検証処理を通った場合だけ生成できる named constructor を用意してください。DTO 生成の責務を 1 サービスに閉じ、未検証状態は別の内部値オブジェクトに分離するか、DTO 化前の値として扱ってください。

### 4. 期待効果の妥当性

- [Warning] 指標の説明に「3 つとも `llm_call_logs` から出る」とありますが、OCR 失敗率は直後の定義どおり `analysis_jobs` の終端状態との突合が必要です。両記述が矛盾しています。

  修正提案: 「入力 token の中央値と OCR 比率は `llm_call_logs`、OCR 失敗率は OCR テンプレートで開始したジョブ集合と `analysis_jobs` の終端状態を結合して算出」と明記してください。

- [Suggestion] 「呼び出し回数が同じでも媒体 token が増えうる」と認め、公開後の実費計測を先に定義している点は妥当です。見積りと実 token 上限保証を区別したことも適切です。

### 5. リスク

- [Warning] 画像内 prompt injection の手動評価を production 有効化の条件にした点は改善です。しかし、この評価は provider/model、system prompt、媒体マッピング、SDK 更新で実効性が変わりえます。一度だけの承認では変更後の安全性を担保できません。

  修正提案: 「OCR 用 provider/model、媒体 YAML、防御指示、Prism/Anthropic の媒体マッピングに変更が入る場合は、production 継続前に同じ評価セットで再評価・再承認する」を rollout 条件に加えてください。少なくとも設定 pin の更新と手動評価記録を同じ変更単位で要求すべきです。

- [Warning] 画像に顔・氏名・印影が含まれうるというリスク認識は正しい一方、公開前の法務確認だけでは利用者がアップロード前に判断できないおそれがあります。

  修正提案: 法務文面の整備を公開条件にしたうえで、アップロード画面にも「画像・PDF は AI 解析のため外部 provider に送信される。不要な個人情報や機密情報が写らないよう確認する」と、法務承認済みの短い案内を表示してください。

### 6. スコープの適切さ

- [Suggestion] ローカル OCR、ページ画像化、自動回転、複数画像の結合を外し、既存 pipeline の extract 入力形態だけを拡張する判断は v1 として適切です。

- [Suggestion] 「画像は 1 SOP につき 1 枚」を UI と拒否文言で明示し、暗黙の無視や別 SOP 化をしない方針は、利用者の誤解を減らせます。

### 7. 型安全性

- [Critical] 媒体 DTO の union 自体は良い方向ですが、前述の `match ($media)` による型分岐は PHPStan level 10 を通す実装になりません。DTO の検証済み性も union だけでは保証できません。

  修正提案: DTO を `final`・private constructor・検証済み生成経路に固定し、窓口での DTO 分岐は PHP の実際の型判別機構に合わせて設計してください。そのうえで、次をテスト・静的 gate に含めてください。

  - 未検証 DTO を生成できないこと
  - vendor 媒体型の生成が `PromptDefense` のみであること
  - JPEG / PNG / PDF の各 DTO が正しい vendor content block へ変換されること
  - 新しい媒体 DTO を追加した際に、変換・上限検証・契約テストの更新漏れが検知されること

この型分岐の設計を実装可能な形に訂正できれば、Round 3 の Critical 指摘はほぼ解消されており、承認可能な設計になります。