import { createInterface } from "node:readline/promises";
import { ExitCode, exitWith } from "../exit-codes.js";

export async function promptApiKey(): Promise<string> {
    if (!process.stdin.isTTY) {
        console.error(
            "Error: --stdin required when no TTY is attached (for API key input).",
        );
        exitWith(ExitCode.GeneralError);
    }
    const rl = createInterface({
        input: process.stdin,
        output: process.stderr,
    });
    const prevRaw = process.stdin.isRaw;
    if (process.stdin.setRawMode) process.stdin.setRawMode(true);
    try {
        const line = await rl.question("API Key: ");
        return line.trim();
    } finally {
        if (process.stdin.setRawMode) {
            process.stdin.setRawMode(prevRaw ?? false);
        }
        rl.close();
    }
}

export async function readStdin(): Promise<string> {
    const chunks: Buffer[] = [];
    for await (const c of process.stdin) chunks.push(Buffer.from(c));
    return Buffer.concat(chunks).toString("utf-8").trim();
}

export async function confirmPrompt(msg: string): Promise<boolean> {
    if (!process.stdin.isTTY) return false;
    const rl = createInterface({
        input: process.stdin,
        output: process.stderr,
    });
    try {
        const ans = (await rl.question(`${msg} [y/N]: `)).trim().toLowerCase();
        return ans === "y" || ans === "yes";
    } finally {
        rl.close();
    }
}
