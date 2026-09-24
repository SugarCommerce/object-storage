<?php
/**
 * Bucket tests.
 *
 * @package SugarCommerce\ObjectStorage\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\HttpClient\{Fake, Response};
use SugarCommerce\ObjectStorage\{Bucket, StorageError};
use SugarCommerce\S3Signer\Provider;

/**
 * Class BucketTest
 */
final class BucketTest extends TestCase {

	/**
	 * A client answering these, in order, then 200.
	 */
	private function scripted( Response ...$answers ): Fake {
		return ( new Fake() )->queue( ...$answers );
	}

	private function bucket( Fake $http ): Bucket {
		return new Bucket( Provider::R2->signer( 'key', 'secret', 'auto', 'acct', '' ), 'files', $http );
	}

	public function test_starting_an_upload_reads_back_its_id(): void {
		$http = $this->scripted( new Response( true, 200, '<InitiateMultipartUploadResult><UploadId>abc-123</UploadId></InitiateMultipartUploadResult>' ) );

		$this->assertSame( 'abc-123', $this->bucket( $http )->start_upload( 'a/b' ) );
		$this->assertSame( 'POST', $http->requests[0]['method'] );
	}

	public function test_a_refused_start_has_no_id(): void {
		$this->assertNull( $this->bucket( $this->scripted( new Response( false, 403 ) ) )->start_upload( 'a/b' ) );
	}

	public function test_part_numbers_out_of_range_are_not_signed(): void {
		$urls = $this->bucket( $this->scripted() )->sign_parts( 'a/b', 'u', array( 0, 1, 2, 10_001 ) );

		$this->assertSame( array( 1, 2 ), array_keys( $urls ) );
	}

	/**
	 * S3 reports some failures inside a 200.
	 */
	public function test_a_completion_answered_with_an_error_document_failed(): void {
		$http = $this->scripted( new Response( true, 200, '<Error><Code>InvalidPart</Code></Error>' ) );

		$this->assertFalse( $this->bucket( $http )->finish_upload( 'a/b', 'u', array( array( 'PartNumber' => 1, 'ETag' => '"x"' ) ) ) );
	}

	public function test_a_completion_with_no_parts_is_refused_without_asking(): void {
		$http = $this->scripted();

		$this->assertFalse( $this->bucket( $http )->finish_upload( 'a/b', 'u', array() ) );
		$this->assertCount( 0, $http->requests );
	}

	public function test_aborting_deletes_the_upload(): void {
		$http = $this->scripted( new Response( true, 204 ) );

		$this->assertTrue( $this->bucket( $http )->abort_upload( 'a/b', 'u' ) );
		$this->assertSame( 'DELETE', $http->requests[0]['method'] );
		$this->assertStringContainsString( 'uploadId=u', $http->requests[0]['url'] );
	}

	public function test_a_read_that_storage_refused_throws(): void {
		$this->expectException( StorageError::class );

		$this->bucket( $this->scripted( new Response( false, 500 ) ) )->read( 'a/b', 0, 9 );
	}

	public function test_a_read_asks_for_its_range(): void {
		$http = $this->scripted( new Response( true, 206, 'abc' ) );

		$this->assertSame( 'abc', $this->bucket( $http )->read( 'a/b', 10, 12 ) );
		$this->assertSame( 'bytes=10-12', $http->requests[0]['headers']['Range'] );
	}

	/**
	 * A browser's declared size is a claim; this is the bucket's answer.
	 */
	public function test_the_size_comes_from_the_content_range(): void {
		$http = $this->scripted( new Response( true, 206, 'x', headers: array( 'content-range' => 'bytes 0-0/5000000' ) ) );

		$this->assertSame( 5_000_000, $this->bucket( $http )->size( 'a/b' ) );
	}

	public function test_a_whole_object_answer_is_its_own_size(): void {
		$this->assertSame( 3, $this->bucket( $this->scripted( new Response( true, 200, 'abc' ) ) )->size( 'a/b' ) );
	}

	public function test_an_unanswered_size_is_unknown(): void {
		$this->assertNull( $this->bucket( $this->scripted( new Response( false, 404 ) ) )->size( 'a/b' ) );
	}

	/**
	 * No CORS configuration at all is an answer, not a failure.
	 */
	public function test_a_bucket_with_no_cors_has_an_empty_policy(): void {
		$result = $this->bucket( $this->scripted( new Response( false, 404 ) ) )->cors();

		$this->assertTrue( $result['ok'] );
		$this->assertNotNull( $result['policy'] );
	}

	public function test_reachable_says_why_not(): void {
		$this->assertTrue( $this->bucket( $this->scripted( new Response( true, 200 ) ) )->reachable()['ok'] );

		$refused = $this->bucket( $this->scripted( new Response( false, 403, '<Error>AccessDenied</Error>' ) ) )->reachable();

		$this->assertFalse( $refused['ok'] );
		$this->assertStringContainsString( '403', $refused['error'] );
	}

	public function test_part_size_scales_to_stay_inside_the_limit(): void {
		$this->assertSame( Bucket::PART_MIN, Bucket::part_size( 1_000 ) );
		$this->assertLessThanOrEqual( Bucket::PART_LIMIT, (int) ceil( 5e12 / Bucket::part_size( 5_000_000_000_000 ) ) );
	}

	public function test_a_download_url_is_presigned(): void {
		$this->assertStringContainsString( 'X-Amz-Signature', $this->bucket( $this->scripted() )->url( 'a/b', 60, 'Master.wav' ) );
	}

	public function test_put_can_set_caching(): void {
		$http = $this->scripted( new Response( true, 200 ) );

		$this->assertTrue( $this->bucket( $http )->put( 'p/a.json', '[1]', 'application/json', 'public, max-age=60' ) );
		$this->assertSame( 'public, max-age=60', $http->requests[0]['headers']['cache-control'] );
		$this->assertSame( 'application/json', $http->requests[0]['headers']['content-type'] );
	}

	public function test_a_copy_is_one_put_naming_the_source(): void {
		$http   = $this->scripted( new Response( true, 200, '<CopyObjectResult/>' ) );
		$source = new Bucket( Provider::R2->signer( 'key', 'secret', 'auto', 'acct', '' ), 'private', $http );

		$this->assertTrue( $this->bucket( $http )->copy_from( $source, 'a/b.wav', 'p/b.wav', 'audio/wav', 'public, max-age=31536000' ) );
		$this->assertSame( 'PUT', $http->requests[0]['method'] );
		$this->assertSame( '/private/a/b.wav', $http->requests[0]['headers']['x-amz-copy-source'] );
	}

	public function test_a_copy_that_failed_inside_a_200_failed(): void {
		$http   = $this->scripted( new Response( true, 200, '<Error><Code>AccessDenied</Code></Error>' ) );
		$source = new Bucket( Provider::R2->signer( 'key', 'secret', 'auto', 'acct', '' ), 'private', $http );

		$this->assertFalse( $this->bucket( $http )->copy_from( $source, 'a', 'b', 'audio/wav' ) );
	}

	/**
	 * Two page loads in one window, one URL: the second is the browser's
	 * cached copy, not another read from storage.
	 */
	public function test_a_view_url_is_stable_and_cacheable(): void {
		$bucket = $this->bucket( $this->scripted() );
		$url    = $bucket->view_url( 'a/b.png', 'image/png', 600 );

		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'private, max-age=600', $query['response-cache-control'] );
		$this->assertSame( 'image/png', $query['response-content-type'] );
		$this->assertSame( 0, strtotime( $query['X-Amz-Date'] ) % 600, 'Signed on a window boundary.' );
	}

	public function test_a_stream_url_plays_rather_than_downloads(): void {
		$url = $this->bucket( $this->scripted() )->stream_url( 'a/b', 3600, 'audio/mpeg' );

		$this->assertStringContainsString( 'response-content-type=audio%2Fmpeg', $url );
		$this->assertStringContainsString( 'response-content-disposition=inline', $url );
		$this->assertStringStartsWith( $this->bucket( $this->scripted() )->origin(), $url );
	}
}
