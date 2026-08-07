<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * inline throttle (`throttle:{max},{decay}` / パラメータなし) を持つことが
 * 正しいと裁定された route の分類。
 *
 * `tests/Architecture/InlineThrottleInventoryTest.php` が deny-by-default で
 * 「named limiter へ移すか、本 enum + 具体的根拠付きで目録登録するか」を機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★分類は route 単位ではなく **bucket signature の性質**で定義する。
 *   inline のキーは `ThrottleRequests::resolveRequestSignature()` が決め、
 *   認証済みなら user id、未認証なら `{domain}|{ip}` になる。
 *   したがって「その route が inline のときどちらのキーになりうるか」が分類の軸である。
 *
 * ★**自前 route 向けの case は 1 つも定義しない** (意図的)。
 *   各 case は **action class の vendor 名前空間** (`Laravel\Passport\` / `Livewire\`) を
 *   premise として機械検査するため、`App\...` の自前 controller はどの case にも当てはまらない。
 *   自前 route に inline を足すと目録に登録できず必ず fail する。
 *   これが AGENTS.md ドメイン規約 5「レーンを分けたいときは inline ではなく
 *   named limiter を新設する」の機械化である
 *   (premise の名前空間リスト自体を書き換えれば当然すり抜けられるが、
 *    その差分は必ずレビューに現れる = 無言で通ることが無い)。
 */
enum InlineThrottleBucketRationale: string
{
    /**
     * session guard も認証 middleware も通らず、キーが IP へ倒れる vendor route。
     *
     * ★保証範囲を誇張しない: 下の適用条件が閉じているのは
     *   **session guard と framework の認証 middleware という 2 つの構造的経路**だけで、
     *   「`$request->user()` が絶対に null」を意味しない
     *   (独自 middleware が user resolver を差し替える余地は残る)。
     *
     * 適用条件 (すべて機械検査される):
     *  1. action class が宣言済みの vendor 名前空間由来 (`Laravel\Passport\`)
     *  2. 実効 middleware 列に `StartSession` が無い
     *  3. 実効 middleware 列に `AuthenticatesRequests` 実装が無い
     * かつ (人間の裁定として) vendor が throttle をハードコードしており
     * 設定でも `RouteThrottleBinder` でも置換できないこと
     * (置換しようとすると二重付与になり `ThrottleCoverageInventoryTest` が fail する)。
     */
    case VendorStatelessIpBucket = 'vendor_stateless_ip_bucket';

    /**
     * 認証状態によってキーが user id にも IP にもなりうる vendor route。
     *
     * 適用条件 (1〜3 は機械検査される):
     *  1. action class が宣言済みの vendor 名前空間由来 (`Livewire\`)
     *  2. 実効 middleware 列に `StartSession` が有る
     *  3. 実効 middleware 列に `AuthenticatesRequests` 実装が無い
     * かつ (人間の裁定として) vendor の controller middleware / package 設定が
     * throttle を決めており、上書きに vendor 設定ファイル全体の公開が要ること
     * (浅い merge により同一セクションの他キーを巻き添えで失う)。
     * ★**この case の上限は 1**。2 本目が現れたら「認証済み actor の bucket を
     *   2 本の route が共有する」= 本 TODO が潰した障害の再来なので、
     *   named limiter 化か vendor 設定の公開かを必ず再検討すること。
     */
    case VendorMixedUserOrIpBucket = 'vendor_mixed_user_or_ip_bucket';
}
