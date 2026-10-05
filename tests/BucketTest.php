<?php
/**
 * Bucket tests.
 *
 * @package SugarCommerce\ObjectStorage\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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

	public function test_part_size_can_have_a_larger_floor_but_never_a_smaller_one(): void {
		$this->assertSame( 8 * 1024 * 1024, Bucket::part_size( 1_000, 8 * 1024 * 1024 ) );
		$this->assertSame( Bucket::PART_MIN, Bucket::part_size( 1_000, 1 ) );
		$this->assertSame( Bucket::part_size( 5_000_000_000_000 ), Bucket::part_size( 5_000_000_000_000, 8 * 1024 * 1024 ), 'The limit still wins for a huge file.' );
	}

	public function test_a_bucket_knows_its_name(): void {
		$this->assertSame( 'files', $this->bucket( $this->scripted() )->name() );
	}

	/* ─── What an unfinished upload holds (1.3.0) ───────────────────── */

	public function test_a_part_listing_is_read_in_order_with_quotes_off(): void {
		$listing = Bucket::parse_list_parts(
			'<ListPartsResult><IsTruncated>false</IsTruncated>'
			. '<Part><PartNumber>2</PartNumber><ETag>&quot;bbb&quot;</ETag><Size>5</Size></Part>'
			. '<Part><PartNumber>1</PartNumber><ETag>"aaa"</ETag><Size>8</Size></Part>'
			. '<Part><PartNumber>0</PartNumber><ETag>"nonsense"</ETag><Size>1</Size></Part>'
			. '</ListPartsResult>'
		);

		$this->assertSame(
			array( array( 'PartNumber' => 1, 'ETag' => 'aaa', 'Size' => 8 ), array( 'PartNumber' => 2, 'ETag' => 'bbb', 'Size' => 5 ) ),
			$listing['parts']
		);
		$this->assertFalse( $listing['more'] );
	}

	/**
	 * A thousand parts a page: a 20 GB upload is more than one.
	 */
	public function test_a_long_listing_is_followed_to_the_end(): void {
		$http = ( new Fake() )
			->on( 'GET', '*part-number-marker=1*', new Response( true, 200, '<ListPartsResult><IsTruncated>false</IsTruncated><Part><PartNumber>2</PartNumber><ETag>"b"</ETag><Size>1</Size></Part></ListPartsResult>' ) )
			->on( 'GET', '*', new Response( true, 200, '<ListPartsResult><IsTruncated>true</IsTruncated><NextPartNumberMarker>1</NextPartNumberMarker><Part><PartNumber>1</PartNumber><ETag>"a"</ETag><Size>1</Size></Part></ListPartsResult>' ) );

		$parts = $this->bucket( $http )->parts( 'k', 'u' );

		$this->assertSame( array( 1, 2 ), array_column( (array) $parts, 'PartNumber' ) );
		$this->assertCount( 2, $http->sent( 'GET' ) );
	}

	/**
	 * Gone, or an error document: not a listing of nothing, which would
	 * resume by sending every part to an upload that no longer exists.
	 *
	 * @param Response $answer What storage said.
	 */
	#[DataProvider( 'not_listings' )]
	public function test_an_upload_storage_does_not_have_lists_as_null( Response $answer ): void {
		$this->assertNull( $this->bucket( $this->scripted( $answer ) )->parts( 'k', 'u' ) );
	}

	/**
	 * @return array<string, array{0: Response}>
	 */
	public static function not_listings(): array {
		return array(
			'no such upload'       => array( new Response( false, 404, '<Error><Code>NoSuchUpload</Code></Error>' ) ),
			'an error inside 200'  => array( new Response( true, 200, '<Error><Code>InternalError</Code></Error>' ) ),
		);
	}

	public function test_a_listing_that_never_ends_is_given_up_on(): void {
		$http = ( new Fake() )->on( 'GET', '*', static fn ( string $method, string $url ): Response => new Response( true, 200, sprintf(
			'<ListPartsResult><IsTruncated>true</IsTruncated><NextPartNumberMarker>%d</NextPartNumberMarker></ListPartsResult>',
			1 + (int) ( preg_match( '/part-number-marker=(\d+)/', $url, $m ) ? $m[1] : 0 )
		) ) );

		$this->assertNull( $this->bucket( $http )->parts( 'k', 'u' ) );
		$this->assertCount( 11, $http->sent( 'GET' ) );
	}

	public function test_the_listing_is_a_signed_request_for_that_upload(): void {
		$http = $this->scripted( new Response( true, 200, '<ListPartsResult></ListPartsResult>' ) );

		$this->assertSame( array(), $this->bucket( $http )->parts( 'acct_X/live/abc', 'up-9' ) );

		$sent = $http->sent( 'GET' )[0];

		$this->assertStringContainsString( 'uploadId=up-9', $sent['url'] );
		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256', $sent['headers']['Authorization'] );
	}

	/* ─── What an object is now (1.3.0) ─────────────────────────────── */

	public function test_stat_reads_the_etag_length_and_time_from_a_ranged_read(): void {
		$http = $this->scripted( new Response( true, 206, 'x', headers: array(
			'content-range' => 'bytes 0-0/1234',
			'etag'          => '"abc"',
			'last-modified' => 'Mon, 05 Oct 2026 09:00:00 GMT',
		) ) );

		$this->assertSame(
			array( 'etag' => 'abc', 'size' => 1234, 'modified' => 'Mon, 05 Oct 2026 09:00:00 GMT' ),
			$this->bucket( $http )->stat( 'a/b' )
		);
		$this->assertSame( 'bytes=0-0', $http->sent( 'GET' )[0]['headers']['Range'] );
	}

	/**
	 * @param Response $answer What storage said.
	 */
	#[DataProvider( 'unsaid' )]
	public function test_stat_without_all_of_it_is_unknown( Response $answer ): void {
		$this->assertNull( $this->bucket( $this->scripted( $answer ) )->stat( 'a/b' ) );
	}

	/**
	 * @return array<string, array{0: Response}>
	 */
	public static function unsaid(): array {
		return array(
			'refused'          => array( new Response( false, 403 ) ),
			'no content-range' => array( new Response( true, 200, 'whole', headers: array( 'etag' => '"e"' ) ) ),
			'no etag'          => array( new Response( true, 206, 'x', headers: array( 'content-range' => 'bytes 0-0/9' ) ) ),
		);
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

	/* ─── A file from disk (1.3.0) ──────────────────────────────────── */

	/**
	 * A file on disk of a given size.
	 *
	 * @param int $bytes Its length.
	 *
	 * @return string Its path, removed after the test.
	 */
	private function file( int $bytes ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'os' );

		file_put_contents( $path, str_repeat( 'b', $bytes ) );
		register_shutdown_function( static fn () => @unlink( $path ) );

		return $path;
	}

	/**
	 * A storage that starts uploads, takes parts with an ETag each, and
	 * completes them.
	 *
	 * @param Response|null $part What a part PUT gets, when not the usual.
	 */
	private function multipart( ?Response $part = null ): Fake {
		return ( new Fake( new Response( true, 204 ) ) )
			->on( 'POST', '*?uploads*', new Response( true, 200, '<InitiateMultipartUploadResult><UploadId>up-1</UploadId></InitiateMultipartUploadResult>' ) )
			->on( 'POST', '*uploadId=*', new Response( true, 200, '<CompleteMultipartUploadResult/>' ) )
			->on( 'PUT', '*partNumber=*', $part ?? static fn ( string $method, string $url ): Response => new Response( true, 200, headers: array(
				'etag' => '"e' . ( preg_match( '/partNumber=(\d+)/', $url, $m ) ? $m[1] : '' ) . '"',
			) ) );
	}

	public function test_a_small_file_goes_up_in_one_put(): void {
		$http = $this->multipart();

		$this->assertTrue( $this->bucket( $http )->upload_file( 'backups/a.db', $this->file( 1000 ) ) );
		$this->assertSame( array( 'PUT' ), $http->methods() );
		$this->assertSame( 1000, strlen( (string) $http->sent( 'PUT' )[0]['body'] ) );
	}

	/**
	 * Bigger than a part: started, sent a part at a time, and assembled
	 * from the ETags each part came back with.
	 */
	public function test_a_large_file_is_sent_in_parts(): void {
		$http = $this->multipart();

		$this->assertTrue( $this->bucket( $http )->upload_file( 'backups/a.db', $this->file( Bucket::PART_MIN + 10 ), 'application/vnd.sqlite3' ) );
		$this->assertSame( array( 'POST', 'PUT', 'PUT', 'POST' ), $http->methods() );

		$parts = $http->sent( 'PUT' );

		$this->assertSame( Bucket::PART_MIN, strlen( (string) $parts[0]['body'] ) );
		$this->assertSame( 10, strlen( (string) $parts[1]['body'] ) );
		$this->assertStringContainsString( 'partNumber=2', $parts[1]['url'] );

		$complete = (string) $http->sent( 'POST', '*uploadId=up-1*' )[0]['body'];

		$this->assertStringContainsString( '<PartNumber>1</PartNumber><ETag>&quot;e1&quot;</ETag>', $complete );
		$this->assertStringContainsString( '<PartNumber>2</PartNumber><ETag>&quot;e2&quot;</ETag>', $complete );
	}

	/**
	 * A refused part leaves an upload whose parts are billed until it is
	 * aborted, so it is.
	 *
	 * @param Response $part What a part PUT gets.
	 */
	#[DataProvider( 'refused_parts' )]
	public function test_a_refused_part_aborts_the_upload( Response $part ): void {
		$http = $this->multipart( $part );

		$this->assertFalse( $this->bucket( $http )->upload_file( 'k', $this->file( Bucket::PART_MIN + 1 ) ) );
		$this->assertCount( 1, $http->sent( 'DELETE', '*uploadId=up-1*' ) );
		$this->assertCount( 1, $http->sent( 'PUT' ), 'Nothing more is sent after a refusal.' );
	}

	/**
	 * @return array<string, array{0: Response}>
	 */
	public static function refused_parts(): array {
		return array(
			'refused'      => array( new Response( false, 403 ) ),
			'with no etag' => array( new Response( true, 200 ) ),
		);
	}

	public function test_a_file_that_is_not_there_sends_nothing(): void {
		$http = $this->multipart();

		$this->assertFalse( $this->bucket( $http )->upload_file( 'k', '/nonexistent/' . bin2hex( random_bytes( 4 ) ) ) );
		$this->assertSame( array(), $http->methods() );
	}
}
