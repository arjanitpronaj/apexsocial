# ApexSocial — Full Technical System Audit (2026-05-28)

> **This dated snapshot redirects to the canonical audit.**  
> Full content (Revision 2): **[`FULL_SYSTEM_AUDIT.md`](./FULL_SYSTEM_AUDIT.md)**

| Field | Value |
|-------|--------|
| Audit date | 2026-05-28 |
| Revision | 2 |
| Status | Active — use `FULL_SYSTEM_AUDIT.md` for all updates |

## Quick reference (Rev 2)

- **Authoritative stack:** PHP (XAMPP) → MySQL; PHP → Flask ML `:5000`; Browser ↔ Python WS `:8080`; PHP push → `:8081`
- **Legacy (do not run on same port):** C# SignalR `Backend/` — documentation drift in `README.md`
- **ML:** Dual models (`pipeline.pkl`, `scam_pipeline.pkl`), `char_wb` ngrams (3–5), threshold 52%, hybrid `analyze()`
- **Critical gaps:** plaintext passwords (demo), no CSRF, XSS via `innerHTML`, default API keys

For all 21 sections (executive summary, security matrix, ML pipeline, file tree, appendices), open [`FULL_SYSTEM_AUDIT.md`](./FULL_SYSTEM_AUDIT.md).
