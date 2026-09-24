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
- 📏 **The real size** — from a one-byte ranged read's `Content-Range`.
- 🔍 **Ranged reads** — throwing on failure, because an empty read and an empty file look alike.
- 🔗 **Presigned downloads** — with the filename the browser should save.
- ▶️ **Presigned streams** — `stream_url()` for an `<audio>` or `<video>` element, typed and inline; `origin()` for the CSP.
- 📋 **Server-side copy** — `copy_from()` another bucket, with new type and caching, nothing through PHP.
- 🌐 **Bucket CORS** — read it, replace it, with `s3-signer`'s `Policy`.

## Requirements

PHP 8.5+, `sugarcommerce/s3-signer`, `sugarcommerce/http-client`

## Installation

```bash
composer require sugarcommerce/object-storage
```

## Usage

```php
use SugarCommerce\ObjectStorage\Bucket;
use SugarCommerce\S3Signer\Provider;

$bucket = new Bucket( Provider::R2->signer( $key, $secret, 'auto', $account_id, '' ), 'files' );

$upload = $bucket->start_upload( $object_key );
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

## License

GPL-2.0-or-later
