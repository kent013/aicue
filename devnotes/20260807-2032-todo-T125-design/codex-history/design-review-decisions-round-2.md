# 対応マトリクス: design-review Round 2

[Critical] 0 件。[Warning] 2 件・[Suggestion] 1 件。**すべて対応**（反論なし）。

## [Warning] S5 は自前 route を vendor case へ登録できてしまう（「vendor 限定」が機械化されていない）
- 判断: **対応する**
- 根拠: 指摘のとおり。premise が middleware 構成しか見ていないため、
  `StartSession` あり `Authenticate` なしの**自前 web route** は
  `VendorMixedUserOrIpBucket` の条件を満たしてしまう
  （件数宣言を同時に上げれば通ってしまい、残るのはレビュー摩擦だけになる）。
  また「`StartSession` が無い」だけでは `$request->user()` が常に null であることまでは示せない。
- 対応内容:
  1. `inlineThrottleCaseVendorNamespaces()` を新設し、case ごとに許す
     **action class の名前空間接頭辞**を宣言する
     （`VendorStatelessIpBucket` = `Laravel\Passport\` /
      `VendorMixedUserOrIpBucket` = `Livewire\`）。
     premise はこの接頭辞との一致を必須にした（Closure action も false = 由来を証明できない）。
     実測値は `route:list` の action 列で確認済み
     （`Laravel\Passport\Http\Controllers\AccessTokenController@issueToken` /
      `Laravel\Passport\Http\Controllers\DeviceCodeController`（invokable）/
      `Livewire\Features\SupportFileUploads\FileUploadController@handle`）。
  2. 判定を `Authenticate` 具象クラスではなく
     **`Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests` インターフェース**に変更し、
     stateless の premise に「認証 middleware が無いこと」も加えた
     （framework の priority list もこのインターフェースで並べているため、
      vendor / Filament の独自 Authenticate 実装も拾える）。
  3. **保証範囲を誇張しない**注記を premise の docblock に明記した:
     ここで閉じているのは「session guard」と「framework の認証 middleware」という
     2 つの構造的経路だけで、独自 middleware が user resolver を差し替える余地は残る。
  4. enum の docblock を、名前空間 premise によって
     「自前 route はどの case にも当てはまらない」ことが機械保証される旨に更新。
     併せて「名前空間リスト自体を書き換えればすり抜けられるが、
     その差分は必ずレビューに現れる」という目録型 gate の限界も明記した
     （Codex の「docblock を弱めよ」という提案は、機械検査を追加したことで不要になったが、
      すり抜け経路の明示という形で実質的に取り込んでいる）。
  5. mutation **M10**（自前 controller の route を vendor case へ登録しようとする）を追加し、
     この保証が実際に働くことを確認する手順にした。

## [Warning] S8 の Livewire 消費元が空振りしうる
- 判断: **対応する**
- 根拠: 指摘のとおり。他のレーンは「N+1 回目が 429」でループが実際に枠を消費したことを
  証明できるが、`livewire.upload-file` だけは上限 60 のためループ内で 429 に到達せず、
  署名検査や middleware 順の変更で 1 枠も消費しなくなっても probe 側は緑になる。
  これは本 TODO の**中心的な回帰テスト**なので空振りは致命的。
- 対応内容: ループを書き換え、各応答で `X-RateLimit-Remaining` の存在を検査したうえで
  **6 回目の残数が初回より 5 減っていること**を固定した
  （`expect($remainings[5])->toBe($remainings[0] - 5, ...)`）。
  空振り保証の表にも 1 行追加。

## [Suggestion] mutation 一覧と実装手順の範囲が同期していない
- 判断: **対応する**
- 対応内容: 実装順序 8 を「mutation 表の全項目」と明示し、
  M1 / M2 / M2' / M2'' / M2''' / M2'''' / M3 / M4 / M5 / M5' / M6 / M6' / M7 / M8 /
  M9-a / M9-b / M10 を列挙した。
  M9 は提案どおり **2 段階**に分割した:
  - M9-a: throttle を剥がし、かつヘッダ検査も外す → **緑のままになることを観測**（問題の再現）
  - M9-b: ヘッダ検査だけ戻す → 期待どおり赤になる（検出器が効いていることの証明）

## [補足] Codex の重点観点への回答に対する確認
- exact fit の摩擦は受容（Codex も同意）。
- probe 固定値への依存は「望ましい壊れ方」（Codex も同意）。定数への集約は必須でないため見送る
  （AGENTS.md 思考原則 2）。
- S6 の named limiter 実在検査を全 route に広げた件は責務逸脱ではない（Codex も同意）。別ファイル化しない。
- `--parallel` の安定性に問題なし（Codex も同意）。
