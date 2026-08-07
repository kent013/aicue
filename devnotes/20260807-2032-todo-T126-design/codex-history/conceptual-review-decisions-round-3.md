# 対応マトリクス: conceptual-review Round 3

## [Critical] Gate A に迂回経路がある (`app('filesystem')->disk('s3')` / DI 注入 / attribute 注入)

- 判断: **対応する** (指摘どおり「呼び出しの発見」から「クラスの allowlist」へ張り替える)
- 根拠: 指摘の 3 例はいずれも `Storage::disk(` 固定の走査を通らない。
  提案された「S3/Flysystem に触れてよいクラスを adapter へ限定する」方が
  deny-by-default として安定する、という判断に同意する。
- 対応内容: Gate A の母集団を **「次のいずれかを持つ app/ 配下のクラス集合」** へ変更した:
  - `Illuminate\Support\Facades\Storage` / `Illuminate\Container\Attributes\Storage` の参照
  - `->disk(` / `::disk(` の呼び出し (**receiver を問わない**)
  - `Illuminate\Contracts\Filesystem\Filesystem` / `Illuminate\Filesystem\FilesystemManager` /
    `Illuminate\Filesystem\FilesystemAdapter` / `Illuminate\Filesystem\AwsS3V3Adapter` /
    `League\Flysystem\` の型参照 (**DI 注入でも型は必ず現れる**)
  - `Aws\` 名前空間への任意の参照
  - `->getClient(`

  登録できるのは adapter とその fake だけで、他は型付き enum 免除 + 30 文字以上の根拠が要る。
  加えて **保証範囲の断り書き**を入れた (文字列キーの container 解決だけで型参照も
  `disk(` も出さない経路は検出できない。規約として docs に書く)。

## [Warning] `NoNetwork` という名称・説明が強すぎる (credential provider はネットワークへ出うる)

- 判断: **対応する**
- 対応内容: case 名を **`NoObjectRequest`** へ変更し、定義を
  「S3 オブジェクト API を送信しない。**credential 解決は保証外**」に改めた。

## [Warning] `app/Http/` の参照ファイル目録は弱い (Controller → Service → adapter で抜ける)

- 判断: **対応する** (補助目録を Feature テストへ差し替える)
- 根拠: 指摘のとおり、参照ファイル目録では中間 Service を挟むと検出できない。
  提案された「既存 web 経路については Feature テストで `BoundedControl` だけが
  呼ばれることを固定する」方が、同じコストで実効性が高い。
- 対応内容: 補助を **Feature テスト**に置き換えた。撮影テイク登録エンドポイントを実行し、
  spy adapter が記録した呼び出しメソッド集合が `NoObjectRequest` ∪ `BoundedControl` に
  含まれる (= `Bulk` を 1 つも呼ばない) ことを assert する。
  保証範囲の断り書き (「規約であって証明ではない」) はそのまま残す。

## [Warning] `200 + 100 = 300 = worker --timeout` は等号で不十分

- 判断: **対応する** (指摘の値をそのまま採用)
- 根拠: worker は `--timeout` 到達で SIGALRM に落とされるため、
  「許容予算を使い切っても完了できる」関係でなければ不変条件として意味がない。
- 対応内容: 序列を
  **`外部予算 200 + 局所処理予算 90 = 290 < worker --timeout 300 < retry_after 360`**
  へ改めた。gate は厳密不等号で検査する。デプロイ手順の値 (`--timeout=300`) は変わらない。

## [Warning] 計数 fake の単一成功経路では最長経路を証明できない

- 判断: **対応する**
- 対応内容: 代表経路をデータセット化して各経路で `<= 10` を検証する:
  (a) 成功 (customer 新規) / (b) 成功 (customer 既存) /
  (c) カード拒否 → invoice void の後始末 / (d) 既存 invoice の再利用 (finalize 済み)。
  将来分岐が増えたらデータセットへの追加を要求できる形にする。

## [Suggestion] `try/finally` の範囲を退避直後から取る

- 判断: **対応する**。詳細設計のテストコードで退避直後に `try` を開く形を明示する。
