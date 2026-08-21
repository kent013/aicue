# 対応マトリクス: conceptual-review Round 1

## [Critical] before ガードでは window.location / 409+X-Inertia-Location のハード遷移を止められず、PC 詳細への決定的 Inertia 経路も未発見。恒久ガードは主要障害の再発防止策として成立しない
- 判断: 対応する
- 根拠: 指摘は正しい。`before` は Inertia visit のキャンセル機構であり、ハードロードは対象外。「ガード 1 本で主障害を防ぐ」は誤り。
- 対応内容: F-1-02 を 2 フェーズに再構成。(A) 必須成果 = クリーンな単一セッションでの再現・分類 (document nav vs XHR、URL、initiator、`X-Inertia`/`X-Inertia-Location` の記録)。(B) アプリ起因の programmatic な `/app/` 外 Inertia visit が確認できた場合のみ、その発火元を直す + 検出済み経路への回帰防止に限定した狭いガード。ハードビジットは能動阻止しないがスコープ外の理由を厳密化。

## [Critical] 明示リンク押下由来 visit を before だけで堅牢に判定できない (URL だけでは user-click と programmatic を区別不能)
- 判断: 対応する
- 根拠: URL 一致では偽陽性/偽陰性が出る。正しい。
- 対応内容: ガードの原則を「Capture から `/app/` 外への programmatic visit は既定で拒否」に変更。PC 詳細への正規遷移を許すのは、リンクの click ハンドラで張る**一回限り・短命の明示遷移トークン**が立っている visit のみ (利用者操作を明確に帰属)。併せて撮影 PWA に PC 詳細リンクを置き続けるべきかも詳細設計で再検討 (最小化を検討)。

## [Critical] 「ハードビジットは同一 URL 着地で実害小」は根拠不足 (同一 URL でも選択カット・ファイル選択・進行中アップロード・未採用 take 等の一時状態は失われ得る)
- 判断: 対応する
- 根拠: 正しい。クライアント一時状態の喪失を「実害小」と断ずるのは早計。
- 対応内容: ハードロードで失われるクライアント状態を列挙し、失う前提で「復帰導線・未採用 take の可視性・アップロード再開」を Browser テストで固定する方針に変更。PC 詳細への遷移がこの前提で説明できない点も再現段で切り分ける。

## [Warning] 計測を「実装の最初」と書いたが、恒久テレメトリか一時診断かが曖昧。恒久なら保存先/PII/削除条件/テストが要る。ガード/DTO/FormRequest はテストファースト
- 判断: 対応する
- 根拠: テストファースト原則 (AGENTS.md 思考原則 5)。計測の性質を明示すべき。
- 対応内容: 計測は**開発時の一時診断 + Browser テストの観測**として扱い、製品コードに恒久テレメトリを残さない。ガード/DTO/FormRequest は各々 fail テスト先行を詳細設計に明記。

## [Warning] beforeunload だけでは遷移先/発火元/ネットワーク種別を特定できない
- 判断: 対応する
- 対応内容: 再現は Browser テスト (playwright ハーネス) 側で document navigation と XHR/fetch を分けて記録し、URL・`X-Inertia`/`X-Inertia-Location`・initiator を残す方式に変更。`beforeunload` は補助に格下げ。

## [Warning] URL 空間で広く `/app/` 外遷移を止めると認証失効/権限変更/障害ページ/将来の正規遷移まで覆い、利用者を Capture に閉じ込める
- 判断: 対応する
- 対応内容: ガード対象を「Capture/Show が自ら起こし得ない既知の外部 programmatic visit」に狭める。認証失効・エラー応答・外部リダイレクト時の挙動を明文化。reloadManual・明示リンク・戻る/進む・offline/online 復帰の回帰テストを置く。

## [Warning] SOP メタデータはファイル名に業務/個人情報を含み得る。hasDocument より露出が増える
- 判断: 対応する
- 対応内容: `VideoManualController::show` の既存認可・組織境界の内側で、当該 manual に属する最新 source document のみを relation 経由で取得。DTO は `name`/`sizeBytes`/`uploadedAt` の最小限。他組織・他 manual のメタが出ない Feature テストを追加。

## [Warning] F-1-02 恒久ガードは原因未確定のまま入れるので現状過大。ハーネス多重実行が真因ならアプリ修正だけでは不十分
- 判断: 対応する
- 対応内容: 必須成果を「単一セッションでの再現・分類・回帰テスト」に置き直し、恒久コードは確認したアプリ起因経路のみ。ハーネス多重実行はスコープ外だが、検出・失敗させる責任 (orchestrator) と申し送り先を明記。

## [Warning] SourceDocumentSummaryData の nullable/日時表現/サイズ型を契約化しないと型ずれ
- 判断: 対応する
- 対応内容: `name: string`/`sizeBytes: int`/`uploadedAt: string(ISO8601)` の不変 DTO。未添付は DTO を null。表示整形 (サイズ単位・日時) は Svelte 側。DTO に表示文言を混ぜない。`toArray()` と TS Props 型と Feature テストで同一契約を固定。

## [Suggestion] create は「選択したファイル」、show は「現在登録されている手順書」と文言を分ける / SOP 起点の意味づけ
- 判断: 対応する (Suggestion だが安価で意味が明確になる)
- 対応内容: create は選択中ファイル名 (未送信であることが分かる文言)、show は登録済み手順書の現況、と文言を分けて詳細設計に明記。

## [Suggestion] adopt の Feature テストは 422/正常採用/別 manual・別 cut・別組織 404/認可 403 の順序まで固定
- 判断: 対応する
- 対応内容: F-1-03 のテスト計画にこの観点を追加。
