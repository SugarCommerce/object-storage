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
- 🗄️ **Big files from the server** — `upload_file()` sends a file from disk a part at a time, and aborts what it started if a part is refused.
- ⏯️ **Resuming** — `parts()` asks storage which parts it really kept (ListParts, every page), rather than trusting the browser's memory.
- 🔎 **Has it changed?** — `stat()` reads an object's ETag, length and modification time from a one-byte read, for objects somebody else can overwrite.
- 🔑 **Bring-your-own buckets** — `Connection` takes a user's provider, region and keys, refuses any host that is not the provider's own, and only ever shows itself masked.
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

There is **no endpoint field**. Every request — uploads, reading a file back to scan it, a download proxy — goes to the endpoint host, and a host the user could type is one they control: pointed at your network, or a server that shows your scanner one file and everybody else another. The host is built from the provider, region and account id and must be the provider's own (`Provider::is_own_endpoint()`), so MinIO and "Other" can never be a `Connection`. `masked()` never includes the secret, and `var_dump()` / `print_r()` show only the masked form.

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

92 tests, every one against `sugarcommerce/http-client`'s `Fake`, so nothing reaches a bucket: multipart start, sign, finish (including the error inside a 200), abort, server-side `upload_file()` in one PUT or in parts, ListParts paging and its dead ends, ranged reads, sizes, `stat()`, CORS, copies, view and stream URLs, `Connection` validation, storage and masking, and every `Probe` step and fault. `MustCheckTest` keeps `#[\NoDiscard]` on the results that must be checked.

## License

GPL-2.0-or-later
