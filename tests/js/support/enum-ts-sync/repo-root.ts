/**
 * リポジトリのルートだけを持つ最下層のモジュール。
 *
 * 母集団 (`population.ts`) と program (`program.ts`) が互いを参照するため、
 * 両方が要る 1 つの値だけをここへ切り出してある (循環取り込みを作らない)。
 * `program.ts` は後方の呼び出し側のために同じ名前で再輸出する。
 */
import path from "node:path";
import { fileURLToPath } from "node:url";

/** リポジトリのルート (tests/js/support/enum-ts-sync から 4 つ上)。 */
export const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../../..");
