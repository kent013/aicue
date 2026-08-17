/**
 * T25 の増える側。`registry-base` の Registry をモジュール拡張で広げる。
 * **外部モジュールとして成立させる** (import を持たせないと `declare module` が
 * 大域宣言側の解釈になり拡張にならない)。
 */
import "./registry-base";

declare module "./registry-base" {
    interface Registry {
        b: "b";
    }
}
