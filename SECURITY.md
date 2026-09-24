# Security

## Reporting

Report a vulnerability privately through GitHub's **Report a vulnerability** button on the repository's Security tab, or by email to `security@arraypress.com`. Please do not open a public issue for anything exploitable.

Include what version you are on, what you did, and what happened. A failing test case is the fastest possible report.

Expect an acknowledgement within a few days, and a fix or an explanation before any public disclosure.

## What these libraries guarantee

Each package states its own boundaries in its README. Across all of them:

- **Untrusted input never becomes executable output.** Values that reach a serialiser — SQL, MIME, PDF, a URL, a header — are validated or escaped for that specific grammar. Where a value is interpolated rather than bound, it is validated against a strict pattern and the method throws rather than guess.
- **Secrets are compared in constant time.** Signatures, tokens, one-time codes and API keys go through `hash_equals`, never `===`.
- **Randomness is cryptographic.** `random_bytes` and `random_int` only; no `mt_rand`, no `uniqid`, no hashes of the current time.
- **Nothing writes to output.** No package echoes, prints, or sets a header. What you do with a returned string is yours to escape for its context.

## What they do not do

**They do not HTML-escape for you.** A library that escaped on the way out would double-escape for every caller that escapes correctly, and would silently corrupt values written to JSON, a database, or a file. Escaping belongs at the point of output, in the encoding of the place it is going.

What these libraries do instead is guarantee that what comes back is *safe to escape* — no invalid UTF-8, no control characters, no smuggled scheme, nothing that changes meaning depending on where it lands. Escape it anyway.

## Supported versions

The current major version receives security fixes. PHP 8.5 or later is required throughout; older PHP versions are not supported and will not be patched.
