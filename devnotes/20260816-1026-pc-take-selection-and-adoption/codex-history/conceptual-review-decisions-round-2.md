# 対応マトリクス: conceptual-review Round 2

## [Warning] 「編集ゼロの最後の 1 ピース」は効果の過大表現

- 判断: **対応する**
- 根拠: 指摘のとおり。本改善は AI が採否を判断するものではなく、**手動の採用判断を
  PC 面へ着地させる**ものである。使命の語をそのまま流用すると、やらないことを約束することになる。
- 対応内容: 期待効果の書き出しを「現行の手動採用フローが PC 面に着地していない、という欠落を埋める」
  に書き換え、「自動採用・編集判断の不要化は本改善の対象外」と明記した。

## [Warning] `takeSummaries` の「1 クエリ集約」は Eloquent の実際と一致しない

- 判断: **対応する**
- 根拠: `withCount` は cuts の SELECT に副問い合わせを畳むが、`with('adoptedTake')` は
  別クエリになる。「1 クエリ」は事実に反する。
- 対応内容: 「**cut 件数に依存しない定数本のクエリ (N+1 を作らない)**」に訂正し、
  テストも「cut を増やしてもクエリ本数が増えないこと」を固定する形に変更した。

## [Warning] `processing` の状態更新方法が未定義

- 判断: **対応する (ただし polling は作らない)**
- 根拠: 実装を読むと、`TakeRegistrationService::finalize()` は
  `$take->forceFill(['status' => TakeStatus::Ready, ...])` で**登録時に ready を確定**しており、
  現時点で `processing` へ遷移する経路は 1 本も存在しない (`TakeStatus` は schema 先取り)。
  存在しない遷移のために polling を先回りで作るのは思考原則 2 に反する。
- 対応内容: D7 に「v1 では登録時に `ready` 確定 = 通常アップロード直後に採用できる」ことと、
  「`uploading` / `processing` / `failed` は**再取得 (画面の再読み込み) で反映する**。
  polling はエンコード経路が実在してから足す」ことを明記。期待効果も
  「一連の操作で直ちに完了する」とは書かない形に限定した。

## [Warning] ページ props への署名 URL / 内部パス流入の防止を型で保証せよ

- 判断: **対応する (方針を変更)**
- 根拠: `CaptureCutData` / `CaptureTakeData` は `playback_url` / `download_ack_token` の
  スロットを構造として持つ (PWA では採用テイクにだけ値が入る)。これを PC ページへ
  そのまま合成すると、「今は null だから安全」という**運用依存の安全**になる。
  PC 面の shape は署名 URL の口を**そもそも持たない**べきである
  (思考原則 4: 似ているが別物を統合しない)。
- 対応内容: `TakeSelectionPageData` が `CaptureCutData` を合成する方針を撤回し、
  **署名 URL / `video_path` / `thumbnail_path` を構造として持たない専用 DTO**
  (`TakeSelectionPageData` + `SelectableTakeData`) を定義。公開 array shape を概念設計に列挙し、
  「Inertia props に S3 署名 URL・`video_path`・`thumbnail_path` が現れないこと」を
  Feature テストの必須項目に追加した。

## [Suggestion] 禁止事項・スコープ・型安全性は解消

- 判断: **維持** (変更なし)
