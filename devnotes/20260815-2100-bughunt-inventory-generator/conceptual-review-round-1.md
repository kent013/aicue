全体判定: **CHANGES_REQUESTED**

概念の方向性は妥当です。手書き分母を生成物へ移し、注釈を deny-by-default にする設計は、T134/T142 の再発防止に直結しています。ただし、実装前に詰めるべき契約がいくつか残っています。特に「route:list 由来の抽出仕様」「注釈定義域」「production 禁止の実装境界」「DTO/型安全の扱い」が曖昧なままだと、生成器自体が新しい偽緑源になります。

## 1. 使命との整合性

[Warning] bug-hunt 分母改善が North Star に貢献する経路は妥当だが、対象を「撮影 PWA」に寄せる説明がやや広いです。  
本設計の直接効果は「探索的バグハントの分母の信頼性向上」であり、「標準化されたマニュアル動画作成」への貢献は間接的です。PWA の詰み・認可漏れ検出の精度を上げる、という範囲に限定して書くと主張が強くなります。

修正提案: 期待効果の使命貢献を「撮影 PWA の品質保証に限定した補助的貢献」と明記してください。

[Suggestion] 「ここまで回れた」という判断の正当性を守る、という表現は良いです。T164 の実行済み route 記録と本設計の分母生成をつなげる説明は、この設計の核として残すべきです。

## 2. 禁止事項違反

[Critical] `bughunt:inventory-scan` が標準出力へ JSON を出す設計は、禁止事項の `response()->json()` 直書きとは別物ですが、Laravel 側の JSON 契約をどう型付けするかが未定義です。  
Console Command だから HTTP response ではありません。ただし、scan 出力が後段 Python の入力契約になるため、配列を雑に組んで `json_encode` するだけだと DTO/型安全の観点で弱いです。

修正提案: PHP 側に `BughuntInventoryScanData` / `BughuntRouteData` などの DTO を置き、`toArray()` の形を PHPStan level 10 で固定してください。Console Command は DTO の配列だけを JSON 化する、という責務に限定するのがよいです。

[Warning] production では実行しない、という方針は正しいですが、実装境界が曖昧です。  
`app()->isProduction()` で落とすだけなのか、`APP_ENV` 宣言との一致検査も PHP 側で行うのか、Python 側で行うのかが分かれていません。

修正提案: `bughunt:inventory-scan --expected-env=local` のように期待環境を明示し、Command 側で現在環境と一致しなければ非 0 終了にしてください。Python 側は scan JSON 内の env を再検証する二重チェックでよいです。

[Suggestion] prompt / LLM / DB 破壊操作には触れておらず、禁止事項 3, 5, 6 への抵触は見当たりません。

## 3. 実現可能性

[Warning] `route:list` を基準にするなら、抽出ソースを明確にする必要があります。  
Artisan の `route:list --json` を使うのか、Laravel の `Route::getRoutes()` を直接読むのかで安定性が違います。人間向け表の parse は避けるべきです。

修正提案: Console Command 内では `Illuminate\Routing\Router` / `RouteCollectionInterface` から route 定義を直接取り、method / uri / name / middleware / action を構造データとして抽出してください。`route:list` 相当の情報、という表現に留めるのが安全です。

[Warning] 画面題名を `config('seo.app_titles')` から取る設計は、route name と title key の対応規則が未定義です。  
対応できない route が出たときに未注釈扱いなのか、title 欠落 drift なのか、画面側だけ対象外なのかが曖昧です。

修正提案: scan JSON に `title_key` / `title` / `title_status` を持たせるか、注釈側で screen title を補えるようにするかを詳細設計で決めてください。少なくとも「title 欠落は exit 3 の drift」とするかどうかを明記すべきです。

[Suggestion] Python stdlib の TOML 採用は妥当です。`tomllib` は読み取り専用なので、生成器が TOML を書かない設計とも噛み合っています。

## 4. 期待効果の妥当性

[Warning] 「表に行を書き忘れる事故は構造的に起こらなくなる」はやや強すぎます。  
route 抽出対象から漏れる、環境違いで route が出ない、注釈の分類ミスで `外` に落とす、といった事故は残ります。

修正提案: 「実装から抽出できる route について、生成物への行追加漏れは byte 比較で検出できる」程度に弱めてください。

[Warning] 偽緑 3 種は消せますが、別の偽緑が生まれない保証はまだ不足しています。  
特に「注釈集合 = 機械事実の集合」は強い一方、対象外 route の理由の品質までは見ません。理由が空でないだけなら、意味の欠落は残ります。

修正提案: `out_of_scope_reason` は 30 文字以上などの形式制約を設けるか、少なくとも空文字禁止・カテゴリ必須にしてください。既存の exemption inventory と同じ思想に寄せるとレビューしやすいです。

## 5. リスク

[Critical] 注釈の定義域を「機械事実の集合」と完全一致にすると、screen と operation の非対称をどう表すかが危険です。  
GET 画面、POST 操作、debug route、OAuth callback、2FA secret など、同じ web route でも一覧上の意味が違います。1 route = 1 注釈だけだと、screens.md と operations.md の両方に対する分類を表しきれない可能性があります。

修正提案: 注釈は route ごとに `screen_scope` と `operation_scope` を分ける設計にしてください。例: `screen = "in" | "out"`、`operation = "in" | "out" | "waived"`。そうすれば現在の debug.login / debug.login-as の非対称も理由付きで表現できます。

[Warning] `inventory.json` を生成物としてコミットする意義がまだ弱いです。  
`screens.md` / `operations.md` が生成物なら、中間成果物の JSON もコミット対象にすると drift 面が増えます。一方で correlate やデバッグに使うなら価値があります。

修正提案: `inventory.json` を誰が読むのかを明記してください。後続ツールが使わないなら生成対象から外し、check 時の一時生成だけにする方がスコープは小さいです。

[Warning] `generate` が全段の検査を通ったときだけ書く方針は安全ですが、段 3 の「生成物一致」を generate 前にどう扱うかが不明です。  
generate は既存生成物と一致しないから書き換えるコマンドです。check と同じ段 3 をそのまま必須にすると generate が常に drift で止まる可能性があります。

修正提案: `check` は byte 比較を行う、`generate` は byte 比較以外の段を通したうえで atomic replace する、と分けてください。

## 6. スコープの適切さ

[Warning] 段 4 の capability-catalog 検査は、本設計の主題から少し外れています。  
「web 面の 2 目録の生成まで」と言いながら、catalog の route 参照検査を同じ生成器に入れると責務が広がります。

修正提案: 段 4 は同一スクリプトに入れるとしても、明確に `check-catalog` 相当の独立関数・独立テストにしてください。初回 PR では段 1-3 に絞り、段 4 は別 PR でもよいです。

[Suggestion] capability-catalog を生成しない判断は妥当です。id が finding 語彙正本であること、overlay であり MECE ではないことは、生成対象にしない理由として筋が通っています。

## 7. 型安全性

[Critical] PHPStan level 10 を通すための DTO 境界が不足しています。  
Console Command、scan result、route entry、environment metadata、title metadata の型が設計されていません。array shape だけで押すと後から PHPStan 対応が苦しくなります。

修正提案: 最低限、以下の DTO を設計に追加してください。

- `BughuntInventoryScanData`
- `BughuntInventoryRouteData`
- `BughuntInventoryEnvironmentData`
- 必要なら `BughuntInventoryTitleData`

各 DTO は `fromRoute()` / `toArray()` を持ち、Command は DTO を組み立てるだけにします。

[Warning] Python 側も stdlib とはいえ、TOML / JSON の schema 検証が必要です。  
PHPStan の対象外なので、Python の自己テストで型契約を守る必要があります。

修正提案: `scripts/tests/test_bug_hunt_inventory.py` で、欠落キー、未知語彙、余剰注釈、空母集合、環境不一致、生成物 byte mismatch、想定外例外の exit code を固定してください。

---

結論として、設計の狙いは承認可能な方向です。ただし現状のまま実装に進むには、注釈モデルと scan DTO 契約が粗いです。特に `screen_scope` / `operation_scope` の分離、`generate` と `check` の段 3 の扱い、Laravel 側 DTO の明記を直せば、APPROVED に近い設計になります。