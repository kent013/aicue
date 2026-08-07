全体判定: **CHANGES_REQUESTED**

Round 1 の Critical 1 は解消されています。一方、Critical 2 の置き換えはまだ 900 秒継承を構造的に閉じ切れていません。また、帯の 240 秒予算とデプロイ順序にも追加条件が必要です。

## 1. 使命との整合性

[Suggestion] 主目的を web 経路の有限化、帯短縮を従目的とした整理は妥当です。撮影テイク登録の停止を直接扱っており、North Star との関係も明確です。

T127 を昇格させない判断にも問題ありません。今回の変更後に実測してから再判断する境界が保たれています。

## 2. 禁止事項違反

[Suggestion] 明示的な違反はありません。Architecture test、behavioral test、既存 invariant 更新まで実装範囲に含まれており、「テストなしの実装完了」にもなっていません。

ただし、設計の保証範囲を超えて「全経路を閉じた」と報告しないことが必要です。後述の S3 inventory は現状のままでは完全な deny-by-default ではありません。

## 3. 実現可能性

[Critical] 「生の SDK クライアントを握れる口」の目録だけでは、S3 の 900 秒継承を塞げません。

`Storage::disk('s3')` から次のような Filesystem API を直接呼ぶコードは、アプリ側で `getClient()` を呼ばずに内部の `S3Client` を使用できます。

- `exists()` / `fileExists()`
- `size()`
- `lastModified()`
- `mimeType()`
- `readStream()` / `writeStream()`
- `get()` / `put()`
- `delete()` / `copy()` / `move()`

このうち metadata 相当の操作が web 経路へ追加されても、`->getClient()` と `new Aws\Client` の走査には現れず、disk 既定の 900 秒を継承します。また、AWS SDK には `Aws\Sdk::createClient()` など、直接 `new ...Client` 以外の構築経路もあります。

修正提案:

- 「AWS client の取得点」ではなく、`Storage::disk('s3')`、`Storage::disk(config由来)`、`Filesystem` 注入などを含む、**S3 disk の利用境界**を exact-fit にする。
- より堅い案は、アプリコードからの S3 利用を `TakeObjectStorage` / `RenderObjectStorage` など既存 adapter に限定し、Facade・Filesystem・S3Client の直接利用を Architecture test で禁止することです。
- adapter の各公開メソッドを `ControlPlane` / `DataPlane` に分類し、ControlPlane は必ず短い command options を通すよう behavioral test で固定する。
- 「クライアントを得る口は2パターンしかない」という断定は削除する。

つまり、置き換えの発想はよいのですが、母集団を「クライアント取得」ではなく「S3 能力への到達境界」に置く必要があります。

## 4. 期待効果

[Warning] `timeout × attempts` は「実効上限」ではなく、HTTP 試行に割り当てた timeout 予算です。DNS、credential provider、endpoint discovery、retry backoffなどがその外側に入り得ます。

修正提案:

- 表の列名を「HTTP 試行 timeout 予算」などへ変更する。
- S3 HeadObject の 15 秒についても、「SDK 操作全体が厳密に15秒以内」ではなく「HTTP request timeout 15秒、SDK retryなし」と記述する。
- php-fpm 枯渇リスクを有限化する主張は維持してよいですが、厳密な wall-clock deadline とは表現しない。

## 5. リスク

[Warning] Stripe の getter-only テストだけでは、pin の配線を独立に検証したことにならない可能性があります。

アプリの `boot()` が一度 static client を変更すると、同一プロセス内の application refresh 後にも状態が残り得ます。そのため、テスト順によっては対象の boot 配線が欠けても ambient global state を読んで green になる余地があります。「テスト自身が setter を呼ばない」ことと「テストが独立している」ことは別です。

修正提案:

- 汎用の退避・復元 helper までは不要です。
- Stripe pin 専用テストで初期状態を既知にし、provider boot 前後を検査して `finally` で元の HTTP client と `maxNetworkRetries` を戻す。
- 併せて、アプリ側の `ApiRequestor::setHttpClient()` 呼び出し点が pin 配線だけであることを inventory で固定する。

したがって、「helper は不要」は妥当ですが、「setter を一切使わないから隔離不要」という反論は不十分です。

## 6. スコープの適切さ

[Warning] `240 < 300` は外部呼び出しだけを数えた序列であり、ジョブ全体の実行上限を直接証明していません。

8回の Stripe timeout で240秒を使い切った場合、残り60秒にはDB処理、ロック待ち、ログ、例外処理、後始末なども入ります。また、Stripe 呼び出しが将来9回になれば270秒となり、余白は30秒です。

修正提案:

- 「最大8呼び出し」を Architecture test または gateway interaction test で固定する。
- 60秒を非外部処理の余白として採用する根拠を docs に明記する。
- 呼び出し数を機械固定できない場合は、worker timeoutをより保守的に置くか、外部予算の算出をジョブごとの目録にする。

[Warning] デプロイ順序は supervisor 先行だけでは不足です。必要な順序は次です。

1. `--timeout=300`, `retry_after=600` にする。
2. SDK timeout pin を含む新コードへ全 worker を入れ替える。
3. 旧コード・旧 worker が残っていないことを確認する。
4. `retry_after=360` を反映する。

旧コードが Stripe 80秒前提のまま残った状態で `retry_after=360` にすると、新旧 worker の混在中に帯の根拠が崩れます。このローリングデプロイ条件も runbook に必要です。

## 7. 型安全性

[Suggestion] shape 付き static method は妥当です。ただし `mode: string` では値域が広いため、PHPStan上可能なら literal string 型まで狭めるのが適切です。

```php
array{
    http: array{connect_timeout: int, timeout: int},
    retries: array{mode: 'legacy', max_attempts: int}
}
```

per-command 側も `@retries: int` と `@http` のshapeを別メソッドで返す設計で問題ありません。

## Round 2 の回答

1. Critical 1 は解消済みです。Critical 2 は未解消で、S3 client取得点ではなくS3利用境界の目録が必要です。
2. 汎用helperを作らない判断は妥当ですが、getter-onlyでは隔離と独立性が不足します。専用テスト内の退避・復元は必要です。
3. `240 < 300 < 360` は成立しますが、最大8呼び出しと60秒余白の固定が必要です。デプロイは「supervisor先行 → 新コードへ全worker更新 → retry_after短縮」の3段階です。
4. 残件は、S3利用境界の母集団、Stripe static状態の独立検査、240秒予算のドリフト防止、ローリングデプロイ条件の4点です。これらを反映すれば APPROVED にできます。