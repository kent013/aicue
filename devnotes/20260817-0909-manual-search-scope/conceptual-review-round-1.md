全体判定: **APPROVED**

重大な設計不備はありません。使命・セキュリティ制約・既存アーキテクチャに沿っており、実装に進める水準です。ただし、実装時に固定すべき境界条件がいくつかあります。

**1. 使命との整合性**

[Suggestion] カット本文検索は North Star に合っています。  
現場作業者が「タイトル」ではなく作業語で探す、という仮説は妥当です。特に PWA のシナリオ選択で効く改善です。

[Suggestion] `scene` を narration/subtitle に足して「カット本文」とする判断は妥当です。  
`scene` が唯一必須で、撮影対象そのものを表すなら、これを外す方が「原稿検索」として空振りを生みます。呼称を「原稿」ではなく「カット本文」と分けている点も良いです。

**2. 禁止事項違反**

[Warning] OR 条件の括り漏れはテナント境界破壊につながるため、設計どおり Feature テスト必須です。  
修正提案: `project_id` / `status` / `created_by` の外へ `orWhere` が漏れないことを、別 project・別 status・別 creator のヒット語を持つデータで固定してください。

[Suggestion] 作成者名検索を作らない判断は妥当です。  
CipherSweet の blind index で部分一致を作らない、平文検索列や n-gram blind index を足さない、という判断は PII 制約に沿っています。

**3. 実現可能性**

[Warning] `ManualKeywordSearch::apply()` の Builder 型は、Eloquent Builder / relation query の両方で PHPStan level 10 が通る形に寄せる必要があります。  
修正提案: 引数型を実際の呼び出し元に合わせ、必要なら `Illuminate\Database\Eloquent\Builder` の generic annotation を付けてください。`whereExists` / `whereHas` のクロージャ内も型が崩れやすいです。

[Suggestion] PC と PWA で検索対象を書き分けない判断は妥当です。  
同じ検索窓で対象範囲が違う方が利用者に説明しづらく、今回の「共通述語化」は保守性も上がります。

**4. 期待効果の妥当性**

[Warning] 「検索語 200 字切り詰めは広く当たるだけ」という説明は、UX 上の副作用を少し過小評価しています。  
修正提案: 実装コメントでは「負荷制御のため上限を設ける。長文貼り付けでは先頭 200 文字で検索される」と明記し、効果説明を過度に正当化しない方が安全です。

[Suggestion] placeholder の変更は必須です。  
「タイトルで検索」のままだと実装後に UI が嘘をつくため、「タイトル・本文で検索」への変更はスコープに含めて正しいです。

**5. リスク**

[Warning] 性能見立ては概ね妥当ですが、`EXISTS` の書き方次第で `cuts` 側の索引効果が変わります。  
修正提案: `cuts.video_manual_id = video_manuals.id` を相関条件の先頭に置き、`video_manual_id` index を追加してください。可能なら検索あり一覧の query count だけでなく、少量でも複数 manual/cut の結果正しさをテストしてください。

[Warning] PWA が `.get()` で全件返す既存仕様は、今回の主原因ではないものの将来の性能リスクです。  
修正提案: 今回は pagination 追加をスコープ外でよいですが、Conditional に「published/ready manuals が一定数を超えたら PWA 一覧ページング」を加えると見立てがより堅いです。

[Suggestion] `pg_trgm` を採らない判断は現時点では妥当です。  
想定規模が 10^5 cuts 程度なら、まず FK index と実測で十分です。拡張導入を先送りする判断は過剰設計回避に合っています。

**6. スコープの適切さ**

[Suggestion] スコープは適切です。  
SOP 原本、ILIKE、ランキング、ハイライト、作成者 select フィルタを外しているため、今回の改善目的がぼやけていません。

[Warning] T053 の台帳不整合は、実装タスク完了時に放置しない方がよいです。  
修正提案: 実装 PR では `docs/TODO-closed.md` の T053 に訂正注記を入れるか、別 TODO として明示してください。設計書だけに残すと後続が再び誤認します。

**7. 型安全性**

[Warning] `normalize()` は日本語入力を前提に、バイト数ではなく文字数で切る必要があります。  
修正提案: `mb_substr` または Laravel の multibyte-safe な helper を使い、`MAX_KEYWORD_LENGTH = 200` が「200 文字」なのか「200 bytes」なのかをテストで固定してください。

[Suggestion] DTO/JsonResource パターンへの影響は小さいです。  
props shape を変えないため新規 DTO は不要、という判断で問題ありません。検索条件の VO/Service 化で PHPStan に寄せやすい設計です。