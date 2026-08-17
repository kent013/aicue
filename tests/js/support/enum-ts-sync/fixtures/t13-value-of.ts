const O = { a: "va", b: "vb" } as const;

export type X = (typeof O)[keyof typeof O];
