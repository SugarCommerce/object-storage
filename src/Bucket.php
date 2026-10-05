<?php
/**
 * One S3-compatible bucket.
 *
 * @package   SugarCommerce\ObjectStorage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage;

use SugarCommerce\HttpClient\Client as Http;
use SugarCommerce\S3Signer\Cors\Policy;
use SugarCommerce\S3Signer\{Method, Signer};

/**
 * Class Bucket
 *
 * sugarcommerce/s3-signer does the signing and deliberately no I/O. This is
 * the I/O, for the handful of calls an application that stores files for
 * other people actually makes — and the S3 behaviours each of them has to
 * know about:
 *
 *   - CompleteMultipartUpload can answer **200 with an error document**.
 *   - An upload that is started and never finished keeps its parts, and is
 *     billed for them, until it is aborted.
 *   - A size declared by a browser is a claim; `size()` asks the bucket.
 *   - A bucket with no CORS configuration answers 404, which is an answer.
 *
 * The HTTP client's SSRF guard is for URLs a user supplied. A bucket
 * endpoint is operator configuration — and may legitimately be a MinIO on
 * localhost — so pass a client with `without_ssrf()` if yours is one.
 */
final readonly class Bucket {

	/**
	 * The smallest part S3 accepts, except for the last.
	 */
	public const int PART_MIN = 5 * 1024 * 1024;

	/**
	 * Parts per upload, with headroom under S3's 10,000 so a miscounted
	 * final part cannot push a large upload over.
	 */
	public const int PART_LIMIT = 9_000;

	/**
	 * Parts ListParts returns in one page, at most.
	 */
	private const int PARTS_PAGE = 1000;

	/**
	 * Pages of parts to follow before giving up: S3 allows 10,000 parts,
	 * which is ten pages, and a listing still going after that is broken.
	 */
	private const int PARTS_PAGES = 11;

	/**
	 * Most bytes stat() reads. One is asked for; a server that ignores the
	 * Range header is cut off here rather than read whole.
	 */
	private const int STAT_BYTES = 1024;

	/**
	 * @param Signer $signer Presigner for the provider.
	 * @param string $name   Bucket name.
	 * @param Http   $http   HTTP client. Substituted in tests.
	 */
	public function __construct(
		private Signer $signer,
		private string $name,
		private Http $http = new Http(),
	) {}

	/**
	 * Part size for a file of a given size.
	 *
	 * Scales up so a very large file still fits inside the part limit,
	 * rather than failing at part 10,001 after an hour of uploading.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Takes a floor.
	 *
	 * @param int $bytes File size.
	 * @param int $floor Smallest part to use, when larger than S3's minimum —
	 *                   fewer round trips for an uploader that can afford
	 *                   to retry bigger parts. Never below PART_MIN.
	 *
	 * @return int
	 */
	public static function part_size( int $bytes, int $floor = self::PART_MIN ): int {
		return max( self::PART_MIN, $floor, (int) ceil( $bytes / self::PART_LIMIT ) );
	}

	/**
	 * The bucket's name.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Open a multipart upload and return its id.
	 *
	 * Server-side, because the id only exists once storage has answered and
	 * every part needs it. Parsed with a pattern rather than an XML parser:
	 * one field, from a contract that has not changed in fifteen years.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key       Object key.
	 * @param string $mime_type Content-Type to store.
	 *
	 * @return string|null The upload id, or null when storage refused.
	 */
	#[\NoDiscard( 'Null means storage refused to start the upload.' )]
	public function start_upload( string $key, string $mime_type = 'application/octet-stream' ): ?string {
		$request  = $this->signer->sign_create_multipart_upload( $this->name, $key, $mime_type );
		$response = $this->http->post( $request->url, '', $request->headers );

		if ( ! $response->ok ) {
			return null;
		}

		return 1 === preg_match( '#<UploadId>([^<]+)</UploadId>#', $response->body, $matches ) ? $matches[1] : null;
	}

	/**
	 * Presigned URLs for a batch of parts, for the browser to PUT to.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $key       Object key.
	 * @param string          $upload_id Multipart upload id.
	 * @param array<int, int> $parts     Part numbers, 1-based.
	 *
	 * @return array<int, string> Part number => URL. Numbers out of range are
	 *                            left out.
	 */
	public function sign_parts( string $key, string $upload_id, array $parts ): array {
		$urls = array();

		foreach ( $parts as $number ) {
			$number = (int) $number;

			if ( $number >= 1 && $number <= 10_000 ) {
				$urls[ $number ] = $this->signer->presign_upload_part( $this->name, $key, $upload_id, $number );
			}
		}

		return $urls;
	}

	/**
	 * Finish a multipart upload.
	 *
	 * @since 1.0.0
	 *
	 * @param string                                           $key       Object key.
	 * @param string                                           $upload_id Multipart upload id.
	 * @param array<int, array{PartNumber: int, ETag: string}> $parts     The parts, with their ETags.
	 *
	 * @return bool False on any refusal — including a 200 carrying an error
	 *              document, which is how S3 reports a failure it found
	 *              after it had started answering.
	 */
	#[\NoDiscard( 'False means the upload was not assembled — abort it, or its parts stay and are billed.' )]
	public function finish_upload( string $key, string $upload_id, array $parts ): bool {
		if ( array() === $parts ) {
			return false;
		}

		$request  = $this->signer->sign_complete_multipart_upload( $this->name, $key, $upload_id, $parts );
		$response = $this->http->post( $request->url, $request->body, $request->headers );

		return $response->ok && ! str_contains( $response->body, '<Error>' );
	}

	/**
	 * Abandon a multipart upload and free its parts.
	 *
	 * An upload started and never finished keeps every part it received, and
	 * they are billed, until this is called or a lifecycle rule clears them.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Multipart upload id.
	 *
	 * @return bool
	 */
	#[\NoDiscard( 'False means the parts are still in storage and still billed.' )]
	public function abort_upload( string $key, string $upload_id ): bool {
		$request = $this->signer->sign_abort_multipart_upload( $this->name, $key, $upload_id );

		return $this->http->request( 'DELETE', $request->url, null, $request->headers )->ok;
	}

	/**
	 * Every part storage holds for an unfinished upload, in order.
	 *
	 * What resuming needs. A browser that reloads halfway through a 6 GB
	 * upload remembers which parts it sent, but storage decides what counts:
	 * a part the browser saw finish may not have been kept, and one it never
	 * heard back about may have been. So the browser's list is a hint and
	 * this is the answer. Followed page by page, a thousand parts at a time.
	 *
	 * @since 1.3.0
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Multipart upload id.
	 *
	 * @return array<int, array{PartNumber: int, ETag: string, Size: int}>|null
	 *         Null when storage would not say — most often because the upload
	 *         was finished or aborted and no longer exists, which a caller
	 *         should treat as "start again", not as "nothing sent yet".
	 */
	public function parts( string $key, string $upload_id ): ?array {
		$parts  = array();
		$marker = 0;

		for ( $page = 0; $page < self::PARTS_PAGES; $page++ ) {
			$request  = $this->signer->sign_list_parts( $this->name, $key, $upload_id, self::PARTS_PAGE, $marker );
			$response = $this->http->request( 'GET', $request->url, null, $request->headers );

			// The 200-with-an-error-document case again: an answer is only a
			// listing if it says it is one.
			if ( ! $response->ok || ! str_contains( $response->body, '<ListPartsResult' ) ) {
				return null;
			}

			$listing = self::parse_list_parts( $response->body );
			$parts   = array_merge( $parts, $listing['parts'] );

			if ( ! $listing['more'] || $listing['next'] <= $marker ) {
				return $parts;
			}

			$marker = $listing['next'];
		}

		return null;
	}

	/**
	 * Read a ListPartsResult document.
	 *
	 * Patterns rather than an XML parser, the way an upload id is read: a
	 * handful of fields, from a contract that has not changed in fifteen
	 * years, and no entity expansion to think about. ETags come back without
	 * their quotes, ready to hand to finish_upload().
	 *
	 * @since 1.3.0
	 *
	 * @param string $xml Response body.
	 *
	 * @return array{parts: array<int, array{PartNumber: int, ETag: string, Size: int}>, more: bool, next: int}
	 *         The parts in order; whether there is another page; the marker
	 *         to ask for it with.
	 */
	public static function parse_list_parts( string $xml ): array {
		$parts = array();

		preg_match_all( '#<Part>(.*?)</Part>#s', $xml, $blocks );

		foreach ( $blocks[1] as $block ) {
			$number = (int) self::field( $block, 'PartNumber' );
			$etag   = trim( html_entity_decode( self::field( $block, 'ETag' ), ENT_QUOTES | ENT_XML1 ), '"' );

			if ( $number >= 1 && '' !== $etag ) {
				$parts[] = array( 'PartNumber' => $number, 'ETag' => $etag, 'Size' => (int) self::field( $block, 'Size' ) );
			}
		}

		usort( $parts, static fn ( array $a, array $b ): int => $a['PartNumber'] <=> $b['PartNumber'] );

		return array(
			'parts' => $parts,
			'more'  => 'true' === strtolower( self::field( $xml, 'IsTruncated' ) ),
			'next'  => (int) self::field( $xml, 'NextPartNumberMarker' ),
		);
	}

	/**
	 * What the bucket says about an object now: its ETag, whole length and
	 * last modification.
	 *
	 * A one-byte ranged read of a presigned URL — the URL is signed for GET,
	 * so a HEAD would not verify — whose headers carry all three. For telling
	 * whether an object somebody else can write to is still the one you
	 * checked: an overwrite changes the length or the time whatever it does
	 * to the ETag, so a crafted ETag collision does not pass for the same
	 * object.
	 *
	 * @since 1.3.0
	 *
	 * @param string $key Object key.
	 *
	 * @return array{etag: string, size: int, modified: string}|null Null when
	 *         storage would not answer, or did not say all of it: without a
	 *         Content-Range there is no length, and without an ETag nothing
	 *         to compare.
	 */
	public function stat( string $key ): ?array {
		$response = $this->http->with_max_bytes( self::STAT_BYTES )->get( $this->url( $key, 60 ), array( 'Range' => 'bytes=0-0' ) );

		if ( ! $response->ok || 1 !== preg_match( '#/(\d+)$#', (string) $response->header( 'content-range' ), $total ) ) {
			return null;
		}

		$etag = trim( (string) $response->header( 'etag' ), "\" \t" );

		return '' === $etag ? null : array( 'etag' => $etag, 'size' => (int) $total[1], 'modified' => (string) $response->header( 'last-modified' ) );
	}

	/**
	 * Some bytes of an object.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key  Object key.
	 * @param int    $from First byte.
	 * @param int    $to   Last byte, inclusive.
	 *
	 * @return string
	 *
	 * @throws StorageError When storage does not answer. An empty read and an
	 *                      empty file look the same afterwards, and only one
	 *                      of them should be waved through.
	 */
	public function read( string $key, int $from, int $to ): string {
		$response = $this->http->get( $this->url( $key, 60 ), array( 'Range' => sprintf( 'bytes=%d-%d', $from, $to ) ) );

		if ( ! $response->ok ) {
			throw new StorageError( 'read', $response->status, $response->body );
		}

		return $response->body;
	}

	/**
	 * How many bytes the bucket holds for an object.
	 *
	 * A one-byte ranged read, whose Content-Range carries the total. The
	 * presigned URL is for GET, and the method is part of the signature, so
	 * it cannot be reused for a HEAD.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Object key.
	 *
	 * @return int|null Bytes, or null when storage would not say.
	 */
	public function size( string $key ): ?int {
		$response = $this->http->get( $this->url( $key, 60 ), array( 'Range' => 'bytes=0-0' ) );

		if ( ! $response->ok ) {
			return null;
		}

		if ( 1 === preg_match( '#/(\d+)$#', (string) $response->header( 'content-range' ), $matches ) ) {
			return (int) $matches[1];
		}

		// A 200 means the range was ignored and the whole object came back.
		return 200 === $response->status ? strlen( $response->body ) : null;
	}

	/**
	 * Write a small object from memory.
	 *
	 * The small case, deliberately. A real upload goes browser to bucket so
	 * the bytes never enter PHP; this is for what is already in memory.
	 *
	 * @since 1.0.0
	 *
	 * @since 1.1.0 Takes a Cache-Control.
	 *
	 * @param string $key           Object key.
	 * @param string $body          Contents.
	 * @param string $mime_type     Content-Type to store.
	 * @param string $cache_control Cache-Control to store, or ''. For an
	 *                              object served straight from a public
	 *                              bucket, where this is the only caching
	 *                              anybody gets to set.
	 *
	 * @return bool
	 */
	#[\NoDiscard( 'False means nothing was stored.' )]
	public function put( string $key, string $body, string $mime_type = 'application/octet-stream', string $cache_control = '' ): bool {
		$headers = array( 'content-type' => $mime_type );

		if ( '' !== $cache_control ) {
			$headers['cache-control'] = $cache_control;
		}

		$request = $this->signer->sign( Method::PUT, $this->name, $key, array(), $body, $headers );

		return $this->http->request( 'PUT', $request->url, $body, $request->headers )->ok;
	}

	/**
	 * Write a file from disk, a part at a time.
	 *
	 * For what is too big to hold in memory and too big for one PUT — a
	 * database backup, an export. A file smaller than a part goes up in one
	 * put(); anything larger is a multipart upload from this server, one
	 * part in memory at a time, sized by part_size(). A part refused, or an
	 * assembly that fails, aborts the upload: its parts would otherwise stay
	 * in the bucket, and be billed, until a lifecycle rule found them.
	 *
	 * @since 1.3.0
	 *
	 * @param string $key       Object key.
	 * @param string $path      Local file to send.
	 * @param string $mime_type Content-Type to store.
	 *
	 * @return bool True when storage holds the whole file.
	 */
	#[\NoDiscard( 'False means nothing was stored.' )]
	public function upload_file( string $key, string $path, string $mime_type = 'application/octet-stream' ): bool {
		$bytes  = is_file( $path ) ? (int) filesize( $path ) : -1;
		$handle = $bytes >= 0 ? fopen( $path, 'rb' ) : false;

		if ( false === $handle ) {
			return false;
		}

		try {
			$part_size = self::part_size( $bytes );

			if ( $bytes <= $part_size ) {
				return $this->put( $key, (string) stream_get_contents( $handle ), $mime_type );
			}

			$upload_id = $this->start_upload( $key, $mime_type );

			if ( null === $upload_id ) {
				return false;
			}

			$parts = $this->send_parts( $key, $upload_id, $handle, $part_size );

			if ( null !== $parts && $this->finish_upload( $key, $upload_id, $parts ) ) {
				return true;
			}

			(void) $this->abort_upload( $key, $upload_id );

			return false;
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * PUT every part of an open file to an upload, in order.
	 *
	 * @param string   $key       Object key.
	 * @param string   $upload_id Multipart upload id.
	 * @param resource $handle    The file, at its start.
	 * @param int      $part_size Bytes per part.
	 *
	 * @return array<int, array{PartNumber: int, ETag: string}>|null Null when
	 *         a part was refused or came back without an ETag.
	 */
	private function send_parts( string $key, string $upload_id, $handle, int $part_size ): ?array {
		$parts = array();

		for ( $number = 1; ! feof( $handle ); $number++ ) {
			$chunk = (string) stream_get_contents( $handle, $part_size );

			if ( '' === $chunk ) {
				break;
			}

			$response = $this->http->put( $this->signer->presign_upload_part( $this->name, $key, $upload_id, $number ), $chunk );
			$etag     = trim( (string) $response->header( 'etag' ), '"' );

			if ( ! $response->ok || '' === $etag ) {
				return null;
			}

			$parts[] = array( 'PartNumber' => $number, 'ETag' => $etag );
		}

		return $parts;
	}

	/**
	 * Copy an object from another bucket into this one, inside storage.
	 *
	 * Nothing passes through PHP, whatever the size. Both buckets must be
	 * reachable with these credentials. The copy gets its own type and
	 * caching, which is the point of copying something private into a
	 * public bucket.
	 *
	 * @since 1.1.0
	 *
	 * @param Bucket $source        Where it is now.
	 * @param string $source_key    Its key there.
	 * @param string $key           Its key here.
	 * @param string $content_type  Content-Type for the copy.
	 * @param string $cache_control Cache-Control for the copy, or ''.
	 *
	 * @return bool True when storage confirmed it.
	 */
	#[\NoDiscard( 'False means nothing was copied.' )]
	public function copy_from( Bucket $source, string $source_key, string $key, string $content_type, string $cache_control = '' ): bool {
		$request  = $this->signer->sign_copy_object( $source->name, $source_key, $this->name, $key, $content_type, $cache_control );
		$response = $this->http->request( 'PUT', $request->url, '', $request->headers );

		// A copy, like a multipart completion, can fail inside a 200.
		return $response->ok && ! str_contains( $response->body, '<Error>' );
	}

	/**
	 * A URL that shows an object in the browser and stays the same for a
	 * window, so a page shown twice in that window is fetched once.
	 *
	 * For thumbnails and previews somebody may look at again and again:
	 * every fetch from storage is a billable read, and a URL that changed on
	 * every page load would make every one of them a fetch.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key          Object key.
	 * @param string $content_type What the browser should treat it as.
	 * @param int    $window       Seconds the URL stays the same. Default an hour.
	 *
	 * @return string
	 */
	public function view_url( string $key, string $content_type, int $window = 3600 ): string {
		return $this->signer->presign_cacheable( $this->name, $key, $window, $content_type );
	}

	/**
	 * A presigned URL that plays or shows in the browser, rather than
	 * downloading.
	 *
	 * @since 1.1.0
	 *
	 * @param string $key          Object key.
	 * @param int    $ttl          Seconds it works for.
	 * @param string $content_type What the browser should treat it as.
	 *
	 * @return string
	 */
	public function stream_url( string $key, int $ttl, string $content_type ): string {
		return $this->signer->presign_inline( $this->name, $key, $ttl, $content_type );
	}

	/**
	 * Where this bucket's URLs point, for a Content-Security-Policy.
	 *
	 * @since 1.1.0
	 *
	 * @return string e.g. `https://files.acct.r2.cloudflarestorage.com`.
	 */
	public function origin(): string {
		return $this->signer->origin( $this->name );
	}

	/**
	 * Delete an object.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Object key.
	 *
	 * @return bool True when storage confirmed it.
	 */
	#[\NoDiscard( 'False means the object may still be there.' )]
	public function delete( string $key ): bool {
		$request = $this->signer->sign_delete_object( $this->name, $key );

		return $this->http->request( 'DELETE', $request->url, null, $request->headers )->ok;
	}

	/**
	 * A presigned URL to download an object.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key      Object key.
	 * @param int    $ttl      Seconds it works for.
	 * @param string $filename What the browser should save it as, or ''.
	 *
	 * @return string
	 */
	public function url( string $key, int $ttl = 300, string $filename = '' ): string {
		return $this->signer->presign_get( $this->name, $key, $ttl, $filename );
	}

	/**
	 * Whether the bucket answers these credentials at all.
	 *
	 * A one-key listing: the cheapest call that proves the endpoint, the
	 * bucket name and the keys are all right, for a health check.
	 *
	 * @since 1.0.0
	 *
	 * @return array{ok: bool, error: string}
	 */
	public function reachable(): array {
		$request  = $this->signer->sign_list_objects( $this->name, 1 );
		$response = $this->http->request( 'GET', $request->url, null, $request->headers );

		return $response->ok
			? array( 'ok' => true, 'error' => '' )
			: array( 'ok' => false, 'error' => self::describe( $response->status, '' !== $response->body ? $response->body : $response->error ) );
	}

	/**
	 * The bucket's CORS policy.
	 *
	 * @since 1.0.0
	 *
	 * @return array{ok: bool, policy: Policy|null, error: string}
	 */
	public function cors(): array {
		$request  = $this->signer->sign_get_bucket_cors( $this->name );
		$response = $this->http->request( $request->method->value, $request->url, null, $request->headers );

		// No configuration at all answers 404 — an answer, not a failure.
		if ( 404 === $response->status ) {
			return array( 'ok' => true, 'policy' => new Policy(), 'error' => '' );
		}

		if ( 200 !== $response->status ) {
			return array( 'ok' => false, 'policy' => null, 'error' => self::describe( $response->status, $response->body ) );
		}

		try {
			return array( 'ok' => true, 'policy' => Policy::from_xml( $response->body ), 'error' => '' );
		} catch ( \RuntimeException $e ) {
			return array( 'ok' => false, 'policy' => null, 'error' => $e->getMessage() );
		}
	}

	/**
	 * Replace the bucket's CORS policy. There is no way to add one rule.
	 *
	 * @since 1.0.0
	 *
	 * @param Policy $policy The policy to store.
	 *
	 * @return array{ok: bool, error: string}
	 */
	public function set_cors( Policy $policy ): array {
		$request  = $this->signer->sign_put_bucket_cors( $this->name, $policy );
		$response = $this->http->request( $request->method->value, $request->url, $request->body, $request->headers );

		return $response->ok
			? array( 'ok' => true, 'error' => '' )
			: array( 'ok' => false, 'error' => self::describe( $response->status, $response->body ) );
	}

	/**
	 * A refusal, as one line.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 *
	 * @return string
	 */
	private static function describe( int $status, string $body ): string {
		return 'HTTP ' . $status . ': ' . substr( strip_tags( $body ), 0, 160 );
	}

	/**
	 * The text of the first element with this name, or ''.
	 *
	 * @param string $xml  Document or fragment.
	 * @param string $name Element name.
	 *
	 * @return string
	 */
	private static function field( string $xml, string $name ): string {
		return 1 === preg_match( '#<' . $name . '>([^<]*)</' . $name . '>#', $xml, $match ) ? $match[1] : '';
	}
}
