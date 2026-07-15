## Round 2: 概念設計の修正と対応

Round 1 の指摘への対応です。対応マトリクスと修正点を示します。

### [Warning] microphone 緩和は過剰 → 反論します
撮影 recorder は `resources/js/components/features/capture/CameraRecorder.svelte` L177-179 で
以下を要求しています:

```js
stream ??= await navigator.mediaDevices.getUserMedia({
    video: videoConstraints(), // facingMode
    audio: true,
});
```

W3C Media Capture 上、Permissions-Policy で許可されない kind (camera / microphone) が 1 つでも
requested constraints に含まれると getUserMedia 呼び出し**全体**が reject されます。したがって
`microphone=()` のままでは camera を (self) にしても撮影は起動できません (audio track 取得が
Permissions-Policy で拒否される)。microphone=(self) は「あったら便利」ではなく、v1 の現行撮影コードが
現に必要としている必須値です。→ microphone=(self) は維持します。

### [Warning] テスト計画未記載 → 対応しました
概念設計に「テスト観点 (概念)」節を追加しました:
1. capture ルート (capture.manuals.show) 応答に camera=(self), microphone=(self) が含まれる
2. 非 capture ルート (/) は camera=(), microphone=() を維持
3. capture でも geolocation=() / payment=(self stripe) が不変 (他 directive 回帰)
4. capture 用 config が空文字 (opt-out) のとき非送出
5. 既存 SecurityHeadersTest の非退行

### [Warning] /app/* 全域緩和は影響半径を広げる / 専用 middleware 案 → 反論します
(a) 緩和は camera=(self) / microphone=(self) = 同一オリジン self のみで cross-origin は開きません。
(b) capture group は全て同一オリジン・session 認証の小さな凝集 group。実際に camera を使うのは
    capture.manuals.show ですが、同一オリジンの XSS は結局その show ページ経由で camera を要求できるため、
    他の capture ページに header を付与するか否かで XSS blast radius は縮まりません。
(c) 書き込み系 (takes.*) は XHR JSON 応答で、Permissions-Policy は document の browsing context に効く
    ヘッダのため JSON XHR 応答には実効せず無害です。
(d) 1 ルートにだけ専用 middleware を付けるのは「今必要なものだけ / フレームワークのレンジ内で最小」に反し、
    得られる安全性向上に乏しい。route group (routeIs('capture.*')) 単位が適切な altitude と判断します。

### [Suggestion] routeIs('capture.*') / private helper (?string) → 採用しました
判定を `$request->routeIs('capture.*')` に、値選択を `resolvePermissionsPolicy(Request): ?string`
helper に閉じ、null/空 opt-out contract を戻り値型で表現します。

---

## 修正後の概念設計 (該当節)

### 実装方針 (概要)
1. config/security.php に capture 専用 Permissions-Policy 値 (capture_permissions_policy) を追加。
   既定値 `geolocation=(), microphone=(self), camera=(self), payment=(self "https://js.stripe.com")`。
   env 上書き可 (SECURITY_CAPTURE_PERMISSIONS_POLICY)、null/空で非送出の contract を踏襲。
2. SecurityHeaders で `$request->routeIs('capture.*')` を判定し capture 用値を送る。
3. 値選択を `resolvePermissionsPolicy(Request): ?string` helper に閉じ、返り値を is_string() && !== '' で
   narrow して set。null/空 opt-out は共通。

上記対応で全 Warning が解消したかご確認ください。全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
