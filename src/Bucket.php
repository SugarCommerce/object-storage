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
	 *
	 * @param int $bytes File size.
	 *
	 * @return int
	 */
	public static function part_size( int $bytes ): int {
		return max( self::PART_MIN, (int) ceil( $bytes / self::PART_LIMIT ) );
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
}
