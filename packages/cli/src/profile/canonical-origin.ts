export function canonicalOrigin(apiUrl: string): string {
    let u: URL;
    try {
        u = new URL(apiUrl);
    } catch {
        throw new Error(`Invalid api_url: ${apiUrl}`);
    }
    if (u.protocol !== "https:" && u.protocol !== "http:") {
        throw new Error(`Unsupported protocol: ${u.protocol}`);
    }
    const defaultPort = u.protocol === "https:" ? "443" : "80";
    const port = u.port !== "" ? u.port : defaultPort;
    return `${u.protocol}//${u.hostname.toLowerCase()}:${port}`;
}
