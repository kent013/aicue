/**
 * effect スコープ内でコールバックを実行するための test ヘルパ。
 *
 * @inertiajs/svelte v3 の useForm は内部で `$effect` を使うため、コンポーネント外で呼ぶと
 * `effect_orphan` で落ちる。`$effect` rune は `.svelte.ts` でのみ使えるため本ヘルパに切り出す。
 */
export function inEffectRoot<R>(callback: () => R): R {
  let result!: R;
  const dispose = $effect.root(() => {
    result = callback();
  });
  dispose();
  return result;
}
