const O = { a: 1, b: 2 } as const;

export type X = keyof typeof O;
