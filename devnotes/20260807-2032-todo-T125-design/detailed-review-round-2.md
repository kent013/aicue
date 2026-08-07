## 施策別判定

| 施策 | 判定 |
|---|---|
| S1 `RateLimiterKeys` helper | APPROVE |
| S2 認証面 4 レーン | APPROVE |
| S3 業務面 2 レーン | APPROVE |
| S4 route 適用 | APPROVE |
| S5 inline 残置目録 gate | REQUEST_CHANGES |
| S6 レーン割当 gate | APPROVE |
| S7 キー規約検査 | APPROVE |
| S8 behavioral proof | REQUEST_CHANGES |
| S9 追随更新 | APPROVE |

## 指摘

### [Warning] S5 は自前 route を vendor case へ登録できてしまう

設計では「自前 route に当てはまる case がないため登録できない」としていますが、現在の premise は middleware 構成しか検査しません。

たとえば自前の web route が以下を満たせば、`VendorMixedUserOrIpBucket` として登録できます。

- `StartSession` あり
- `Authenticate` なし
- exact count を同時更新

つまり deny-by-default のレビュー摩擦はありますが、「vendor route だけ」という不変条件は機械化されていません。また、`StartSession` がないことだけでは、独自のステートレス認証などによって `$request->user()` が常に null であることまでは証明できません。

修正案:

- premise に vendor provenance を追加する。
- 少なくとも action/controller の namespaceまたは既知の action classを case ごとに固定する。
- Passport は `Laravel\Passport\...`、Livewire は `Livewire\...` 由来であることを検査する。
- enum docblock の「登録できず必ず fail」は、この検査を追加するまでは「登録には case 件数と premise の明示変更が必要」に弱める。

route 名だけの固定より、実効 action の由来も検査する方が vendor 更新検知として堅牢です。

### [Warning] S8 の消費元が実際に throttle を通った証明が一部不足している

`expectNotThrottled()` によって、cross-lane probe 側の false green は閉じています。さらにS6が割当を固定するため、「別 limiter に差し替わったがヘッダだけ出る」変更も、テスト群全体では検出できます。

一方、Livewire テストの消費元6回は依然として「429ではない」だけです。署名検査や middleware 順の変更で、この6回が bucket を消費しなくなった場合、`recent-auth.password` の probe は正常でもテストがgreenになります。つまり、このテスト単体が主張する「Livewireを6回踏んだ結果の独立性」が空振りする余地があります。

修正案:

- Livewireの各応答にも `X-RateLimit-Remaining` の存在を要求する。
- 可能なら初回値から1ずつ減ることを確認する。
- 少なくとも6回目の残数が初回より5減っていることを固定する。

同様に、各「使い切る」ループは11回目の429で消費を証明できていますが、Livewireだけは上限60のため、その証明がありません。

### [Suggestion] mutation一覧と実装手順の範囲を同期する

mutation表は `M2''''`、`M5'`、`M6'`、`M9`まで増えていますが、実装順序8は「M1〜M8」となっています。

`M1〜M9および枝番`、または「mutation表の全項目」と書き換えると実施漏れを防げます。M9は次の二段階として記録すると明確です。

1. ヘッダ検査を外してthrottleを剥がすと偽greenになる。
2. throttleを剥がしたままヘッダ検査を戻すと期待どおり赤になる。

## 重点観点への回答

1. `expectNotThrottled()`自体は妥当です。S6の割当gateと組み合わせれば、別limiterへの差し替えも検出できます。ただし消費元側、特にLivewireの空振りは別問題として残ります。
2. `!==`によるexact fitの摩擦は受容すべきです。vendor routeの増減は分類根拠と共有リスクを再評価すべきイベントであり、静かに通す変更ではありません。
3. 固定probe値への依存は妥当です。`rateLimiterProbeUser()`のID変更で壊れるのは、期待値の正本が二重になっていることを知らせる望ましい壊れ方です。保守性を上げるならIDを専用helper/定数へ集約できますが、必須ではありません。
4. 全routeのnamed limiter実在検査はS6の責務から大きく逸脱していません。「割当先が実在する」は同じ配線不変条件です。現時点で別ファイル化は不要です。
5. `--parallel`上の問題は見当たりません。array storeはプロセス内、各テストはapp再生成、FeatureデータはFactory生成であり、プロセス間共有bucketは発生しません。

## 全体判定

**CHANGES_REQUESTED**

Round 1のCritical 2件・Warning 3件は実質的に解消されています。残件は、S5の「vendor限定」という主張の機械保証と、S8のLivewire消費元の空振り防止です。閾値の変更はなく、`6/min`・`10/min`・`60/min`はいずれも維持されています。