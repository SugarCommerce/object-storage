# Object Storage

A small client for one S3-compatible bucket — R2, S3, B2, MinIO: multipart uploads the browser does directly, ranged reads, the real stored size, presigned downloads and the bucket's CORS policy. Built on `sugarcommerce/s3-signer` and `sugarcommerce/http-client`.

## Why

`s3-signer` signs requests and deliberately does no I/O. Every application that stores files for other people then writes the same handful of calls around it — and relearns the same S3 behaviours the hard way:

- **CompleteMultipartUpload can answer 200 with an error document.** Checking the status alone records an upload that failed.
- **An upload that is never finished keeps its parts**, and is billed for them, until it is aborted.
- **A size the browser declared is a claim.** Quotas and scanners need the bucket's answer.
- **A bucket with no CORS configuration answers 404**, which is an answer rather than an outage.

## Features

- 📤 **Browser-direct multipart** — start, sign parts in batches, finish, abort.
- 🗄️ **Big files from the server** — `upload_file()` sends a file from disk a part at a time, and aborts what it started if a part is refused; `download_file()` brings one back to disk as it arrives, keeping it only when it is whole.
- 📦 **Off-site copies** — `Archive` sends a backup to the bucket through a seal hook you supply, lists what it sent (no bucket listing needed), keeps the newest N, and fetches one back without ever overwriting a file it was not told to.
- ⏯️ **Resuming** — `parts()` asks storage which parts it really kept (ListParts, every page), rather than trusting the browser's memory.
- 🔎 **Has it changed?** — `stat()` reads an object's ETag, length and modification time from a one-byte read, for objects somebody else can overwrite.
- 🔑 **Bring-your-own buckets** — `Connection` takes a user's provider, region and keys, refuses any host that is not the provider's own (unless the operator typed it), and only ever shows itself masked.
- 🩺 **A connection test** — `Probe` reaches, writes, reads back, deletes and checks CORS with a tiny object, and says which step failed and why, as codes you word.
- 📏 **The real size** — from a one-byte ranged read's `Content-Range`.
- 🔍 **Ranged reads** — throwing on failure, because an empty read and an empty file look alike.
- 🔗 **Presigned downloads** — with the filename the browser should save.
- ▶️ **Presigned streams** — `stream_url()` for an `<audio>` or `<video>` element, typed and inline; `origin()` for the CSP.
- 📋 **Server-side copy** — `copy_from()` another bucket, with new type and caching, nothing through PHP.
- 🌐 **Bucket CORS** — read it, replace it, with `s3-signer`'s `Policy`.

## Requirements

PHP 8.5+, `sugarcommerce/s3-signer`, `sugarcommerce/http-client`, `sugarcommerce/sanitize` (for `Connection::mask()`, which is `Input::mask()`)

## Installation

```bash
composer require sugarcommerce/object-storage
```

## Usage

```php
use SugarCommerce\ObjectStorage\{Bucket, StorageError};
use SugarCommerce\S3Signer\Provider;

$bucket = new Bucket( Provider::R2->signer( $key, $secret, 'auto', $account_id, '' ), 'files' );

try {
    $upload = $bucket->start_upload( $object_key );
} catch ( StorageError $e ) {
    error_log( $e->getMessage() ); // "Storage start_upload failed (403): SignatureDoesNotMatch: …"
    return;                        // $e->error_code says which fix: a rolled secret, a revoked key, no bucket
}

$urls   = $bucket->sign_parts( $object_key, $upload, range( 1, $parts ) ); // hand to the browser

if ( ! $bucket->finish_upload( $object_key, $upload, $etags ) ) {
    $bucket->abort_upload( $object_key, $upload );
}

$size = $bucket->size( $object_key );                 // what really arrived
$head = $bucket->read( $object_key, 0, 65535 );       // for an inspection
$url  = $bucket->url( $object_key, 300, 'Master.wav' );
```

`read()` and `size()` never take in more than they asked for: a backwards or negative range is refused before any request (storage answers a range it cannot parse with the whole object), and the answer is cut off in transit past the range — or past 1 KiB for `size()` — rather than read whole into memory. `json_encode()` of a `Connection` gives its masked view, like `var_dump()`; `to_json()` is the only way to the secret.

The HTTP client's SSRF guard is for URLs users supply. A bucket endpoint is your configuration and may be a MinIO on localhost — pass `new Client()->without_ssrf()` if so.

### Publishing a copy

```php
$public->copy_from( $private, $key, 'previews/' . $key, 'audio/mpeg', 'public, max-age=31536000, immutable' );
$public->put( 'previews/' . $sha . '.json', $peaks, 'application/json', 'public, max-age=31536000, immutable' );
```

### A file from disk

```php
if ( ! $bucket->upload_file( 'backups/' . basename( $path ), $path, 'application/vnd.sqlite3' ) ) {
    // nothing is left behind: a refused part aborts the upload
}
```

For what the server itself has to send and cannot hold — a database backup, an export. A file smaller than a part goes in one `put()`; anything larger is a multipart upload from the server, one part in memory at a time, sized by `part_size()`. A part refused, or returned without an ETag, aborts the upload, because its parts would otherwise be billed until a lifecycle rule found them.

### Off-site copies

```php
use SugarCommerce\ObjectStorage\Archive;

$archive = new Archive( $bucket, '/var/backups/app/remote.json', 'backups/', keep: 14,
    seal: $sealer->seal_file( ... ),   // seal file A into file B
    open: $sealer->open_file( ... ) ); // open file B back into file A

$key     = $archive->send( $backups->take() );        // 'backups/app-20261024-030000.db.sealed', or null
$deleted = $archive->prune();                          // all but the newest 14, by the order sent
$archive->keys();                                      // newest first
$archive->fetch( $key, '/var/backups/app/restored.db' );                 // false: missing, or will not open
Archive::restore( $downloaded, $out, $sealer->open_file( ... ) );        // a copy already on disk
$archive->capture( $db->snapshot( ... ), 'app-' . gmdate( 'Ymd-His' ) . '.db' ); // write, send, remove
```

The bookkeeping half of an off-site backup. What was sent goes in a JSON list beside the local copies once storage has it, so keys that cannot list the bucket can still prune it; a failed upload leaves the list as it was, and a failed delete stays listed and is tried again. A list that does not parse, or names a key outside the prefix, throws and is left alone: a fresh one would forget every copy already in the bucket, and a list naming any key would have `prune()` delete it.

Sealing is the two hooks, so whether it streams or reads a file whole is the hook's business. Each is called `( $from, $to )` with `$to` an empty owner-only file; false means it failed, anything else (void included) that it worked. Without hooks a copy goes up as it is. The sealed copy, a captured copy, a download and a half-opened restore are temporary files, removed on every way out, exceptions included. A restore is opened beside its target, made owner-only and linked into place, so a copy that will not open leaves nothing, and a target that exists throws unless `overwrite: true`. `fetch()` takes any key under the prefix, listed or not, since the server that kept the list may be what was lost.

### Resuming an upload

```php
$parts = $bucket->parts( $object_key, $upload_id );

if ( null === $parts ) {
    // Finished, aborted or gone: start again — not "nothing sent yet".
}

// [ [ 'PartNumber' => 1, 'ETag' => 'abc…', 'Size' => 8388608 ], … ] — ready for finish_upload()
```

`Bucket::part_size( $bytes, floor: 8 * 1024 * 1024 )` picks a part size with a floor above S3's 5 MiB minimum, still scaling up to stay inside the part limit.

### Has it changed?

An object in a bucket somebody else can write to is not necessarily the one you checked. `stat()` reads what the bucket says now:

```php
$now = $bucket->stat( $key );   // [ 'etag' => …, 'size' => …, 'modified' => … ] or null

if ( null === $now || $now !== $checked ) {
    // refuse, and check it again
}
```

A one-byte ranged GET of a presigned URL (signed for GET, so a HEAD would not verify), capped at 1 KiB in case the server ignores the range. Compare all three: an overwrite changes the length or the time whatever it does to the ETag.

### Buckets your users bring

`Connection` is the value object for a bucket someone else chose — a seller's own R2, a customer's S3 — checked once on the way in:

```php
use SugarCommerce\ObjectStorage\Connection;

$connection = Connection::from_array( $_POST, [ 'r2', 'aws', 'backblaze' ] );

if ( is_string( $connection ) ) {
    return $connection;   // 'Paste your Cloudflare account id: …'
}

$stored = $sealer->seal( $connection->to_json() );   // it holds the secret
$bucket = $connection->bucket( new Client() );       // keep the SSRF guard
$page   = $connection->masked();                     // all a page ever gets
```

There is **no endpoint field**. Every request — uploads, reading a file back to scan it, a download proxy — goes to the endpoint host, and a host the user could type is one they control: pointed at your network, or a server that shows your scanner one file and everybody else another. The host is built from the provider, region and account id and must be the provider's own (`Provider::is_own_endpoint()`), so by default MinIO and "Other" can never be a `Connection`. `masked()` never includes the secret, and `var_dump()` / `print_r()` show only the masked form.

#### A host the operator typed

An install's own MinIO, or an S3-compatible service named in its configuration, has a host somebody typed — the operator, which is a different thing from a user. Say so, and allow the providers in `Connection::TYPED`:

```php
$connection = Connection::from_array(
    [ 'provider' => 'minio', 'endpoint' => getenv( 'STORAGE_ENDPOINT' ), 'bucket' => 'media', /* keys */ ],
    [ ...Connection::PROVIDERS, ...Connection::TYPED ],
    true,   // the operator's own configuration
);

$connection->endpoint;   // 'minio.internal:9000' — scheme, path and case taken off
$stored = Connection::from_json( $json, [ ...Connection::PROVIDERS, ...Connection::TYPED ], true );
```

Both are needed: listing `minio` without the flag is still refused, so a form whose providers came from `Provider::options()` does not start taking hosts. The endpoint must be a host name with an optional port (1–65535) — no user, path, query or IPv6 brackets, which the signer cannot sign for — and a provider that builds its own host never keeps one. `to_json()` writes `endpoint` only when there is one, so a connection stored before 2.2.0 is the same document today. The HTTP client's SSRF guard still applies: a MinIO on a private address needs a client whose policy allows it.

### Testing a connection

```php
use SugarCommerce\ObjectStorage\{Probe, ProbeFault};

$steps = ( new Probe( new Client(), prefix: 'myapp-connection-test/' ) )->run( $connection, [ 'https://app.example.com' ] );

Probe::passed( $steps );          // bool

foreach ( $steps as $step ) {
    $step->check;                 // ProbeCheck::Reach | Write | Read | Delete | Cors
    $step->fault;                 // null, or ProbeFault::Refused, Missing, Elsewhere, Altered, CorsOrigin, EtagHidden, …
    $step->detail;                // the CORS reason, the origin refused, or (Unreachable) a reason for your log only
}
```

With a tiny object it does everything an upload later will: a HEAD (404 is the answer wanted), a PUT carrying the uploader's `Origin`, a presigned GET checked byte for byte, a DELETE, and the CORS question — the stored policy when the keys may read it, otherwise a browser-style preflight per origin plus whether the PUT's answer exposed `ETag`. Steps stop at the first failure. Every answer is capped at 64 KiB. Faults are codes so you word them; `Unreachable` deliberately covers both "the network failed" and "the client refused a private address", so the test cannot be used to map your network.

## Testing

```bash
composer install
composer test
```

154 tests, every one against `sugarcommerce/http-client`'s `Fake`, so nothing reaches a bucket: multipart start, sign, finish (including the error inside a 200), abort, server-side `upload_file()` in one PUT or in parts, `download_file()` whole or not at all, `Archive` sending, sealing, pruning, a corrupt or tampered list, fetching and refusing to overwrite, ListParts paging and its dead ends, ranged reads, sizes, `stat()`, CORS, copies, view and stream URLs, `Connection` validation, typed operator endpoints, storage and masking, and every `Probe` step and fault. `MustCheckTest` keeps `#[\NoDiscard]` on the results that must be checked.

## License

GPL-2.0-or-later
