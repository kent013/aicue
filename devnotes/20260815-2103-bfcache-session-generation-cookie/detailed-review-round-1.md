仮説: この設計の主目的は「bfcache 復元時に、古い認証済み DOM を秘匿したまま、同一セッション世代であることまで確認してから開示する」ことです。成功条件は、欠落・不一致・契約ずれ・通信失敗のどれも秘匿解除へ倒れないこと、かつ PHP/Inertia/TS の契約が片側変更で壊れないことです。

全体判定: **CHANGES_REQUESTED**

## S1: 印の供給元と世代 cookie の発行

判定: **REQUEST_CHANGES**

[Warning] `session.same_site` / `session.secure` / `session.path` / `session.domain` の扱いが、Laravel の session cookie と完全一致しない可能性があります。  
設計では「セッション cookie と同じ属性」と書いていますが、`same_site` が string でない場合に `Lax` へ倒す、`secure` を `(bool)` cast する、という実装は Laravel 側の cookie 生成規則とズレる余地があります。

修正案: session cookie と同じ意味論になるよう、Laravel の cookie factory 側に寄せるか、少なくとも `sameSite` は `null` を維持し、`secure` も config の `null` 意味を壊さない実装にしてください。テストも「期待値を固定」ではなく「session cookie と同一属性」を比較する形が望ましいです。

[Suggestion] `bootstrap/app.php` の例に `IssueSessionEpochCookie` と `SessionEpoch` の import 追加が明記されていません。実装時の落とし穴なので、変更箇所に含めてください。

## S2: 描画世代を Inertia 共有 prop で配る

判定: **REQUEST_CHANGES**

[Warning] `Inertia::always(SessionEpoch::current($request))` は即時評価に見えます。`HandleInertiaRequests::share()` は middleware の早い段階で呼ばれるため、同一リクエスト中に session ID が再生成される Inertia 応答があると、cookie は応答後の世代、prop は要求前の世代になる可能性があります。

修正案: `Inertia::always(fn (): ?string => SessionEpoch::current($request))` のように遅延評価へ寄せ、cookie と shared prop が同じ応答時点の session ID から導出されることを Feature テストで固定してください。

[Suggestion] `sessionEpoch` は履歴に入る値なので「秘密ではないがセッション ID ではない」説明は妥当です。PII 非該当の整理も設計に入っており、この点は良いです。

## S3: プローブに世代の照合を足す

判定: **APPROVE**

大筋は妥当です。DTO + JsonResource パターンを維持し、`response()->json()` を使わず、Cookie ヘッダを照合根拠に使わない点も正しいです。

[Suggestion] `SessionStatusResource` の docblock は現行 `{ authenticated }` から `{ authenticated, sessionEpochMatches }` へ更新対象に含めてください。実装差分では見落とされやすいです。

## S4: ガードに同期判定を前置し開示条件を厳格化

判定: **REQUEST_CHANGES**

[Warning] `readSessionEpochCookie()` の `decodeURIComponent(...)` は malformed percent encoding で例外を投げます。復元直後の同期判定で例外が出ると、秘匿は維持されても状態遷移が止まり、再試行 UI にも進まない可能性があります。

修正案: decode を `try/catch` し、失敗時は `null` に倒してください。テストに `session_epoch=%E0%A4%A` のような壊れた値を追加してください。

[Warning] `probeSessionStatus(fetchImpl, readRenderedEpoch(), SESSION_STATUS_PATH)` と書かれていますが、関数シグネチャの変更が明示されていません。現行は `(fetchImpl, url)` なので、設計のままだと実装時に引数順の事故が起きやすいです。

修正案: 変更後シグネチャを明記してください。例:  
`probeSessionStatus(fetchImpl: ProbeFetch, renderedEpoch: string | null, url: string = SESSION_STATUS_PATH)`  
あわせて既存呼び出しの更新範囲をテストで固定してください。

[Warning] S4 本文では `readCurrentEpoch` は既定値に任せる設計ですが、S7 では `app.ts` に `readCurrentEpoch` 配線があることを検査すると書かれており矛盾しています。

修正案: どちらかに統一してください。契約検査で固定するなら `app.ts` に明示的に `readCurrentEpoch: () => readSessionEpochCookie(document.cookie)` を渡す。既定値を正本にするなら S7 の検査対象から `readCurrentEpoch` を外してください。

## S5: 検証ページの状態語彙を追随させる

判定: **REQUEST_CHANGES**

[Warning] `stale-session-reloaded` に `redirect-observed` が付くと `unauthenticated-redirected` と判定する設計ですが、`reloading` は「未認証」だけでなく「認証済みだが世代不一致」でも発生します。別ユーザー・別セッションで同じ URL が認証済み表示に戻る場合、意味的に `unauthenticated-redirected` ではありません。

修正案: `redirect-observed` が `/login` 到達を示すイベントなのか、単なる目視確認なのかを明確にしてください。前者ならイベント payload に遷移先種別を含めて判定する、後者なら verdict 名を `stale-session-confirmed` などに分ける方が安全です。

## S6: 理由記述の差し替えと文書の更新

判定: **APPROVE**

文書方針は妥当です。`no-store` を bfcache の絶対抑止として書かない、特定ブラウザだけを例外扱いしない、という修正は現実の保証範囲に合っています。

[Suggestion] `/admin` 非対象は未対応事項だけでなく、リスク受容の理由も短く添えるとレビュー時に誤ってスコープ漏れ扱いされにくいです。

## S7: 契約ずれの検査と文書の期限検査

判定: **REQUEST_CHANGES**

[Warning] S4 との矛盾により、`readCurrentEpoch` の契約検査が実装方針と一致していません。

修正案: S4 と同じく、`readCurrentEpoch` を明示配線する設計にするか、検査対象を `readRenderedEpoch` のみにしてください。

[Warning] 「応答キーを `SessionStatusResource` を実際に `toArray()` して得る」とありますが、Resource が DTO を前提にするため、テスト側で DTO の構築が必要です。ここを曖昧にすると文字列直書きに戻りやすいです。

修正案: テスト設計に `new SessionStatusDto(authenticated: true, sessionEpochMatches: true)` を Resource に渡して key を抽出する、という具体手順を明記してください。

## 追加確認

DESIGN.md / Atomic Design については、今回 UI component を増やさず既存 overlay の状態だけを増やすため、大きな違反は見当たりません。

DTO/JsonResource と Inertia Props/API Response の使い分けも基本方針は正しいです。ただし、S2 の評価タイミングと S4/S7 の契約矛盾は、実装後に「常に読み直し」や「テストだけが不自然に赤い」状態を作りやすいため、修正してから実装に進むべきです。