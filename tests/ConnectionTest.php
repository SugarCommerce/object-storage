<?php
/**
 * Connection tests.
 *
 * @package SugarCommerce\ObjectStorage\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\HttpClient\Fake;
use SugarCommerce\ObjectStorage\{Bucket, Connection};
use SugarCommerce\S3Signer\Provider;
use SugarCommerce\Sanitize\Input;

/**
 * Class ConnectionTest
 */
final class ConnectionTest extends TestCase {

	/**
	 * What somebody types to connect an R2 bucket, on an account id that is
	 * obviously nobody's.
	 */
	public const array TYPED = array(
		'provider'   => 'r2',
		'account_id' => 'abcdef0123456789abcdef0123456789',
		'bucket'     => 'seller-files',
		'access_key' => 'SELLERACCESSKEY0001',
		'secret_key' => 'seller-secret-that-must-never-be-shown',
	);

	/**
	 * The typed fields, with some changed.
	 *
	 * @param array<string, string> $changes   Fields to replace.
	 * @param array<int, string>    $providers Providers allowed.
	 */
	private static function typed( array $changes = array(), array $providers = Connection::PROVIDERS ): Connection|string {
		return Connection::from_array( $changes + self::TYPED, $providers );
	}

	/* ─── Taking it in ──────────────────────────────────────────────── */

	public function test_a_complete_r2_bucket_is_accepted(): void {
		$connection = self::typed();

		$this->assertInstanceOf( Connection::class, $connection );
		$this->assertSame( Provider::R2, $connection->provider );
		$this->assertSame( 'https://abcdef0123456789abcdef0123456789.r2.cloudflarestorage.com', $connection->origin() );
		$this->assertSame( '', $connection->problem() );
	}

	/**
	 * Every way a field can be unusable is caught before anything is sent,
	 * with a sentence saying which.
	 *
	 * @param array<string, string> $changes  Fields to replace.
	 * @param string                $mentions What the sentence mentions.
	 */
	#[DataProvider( 'unusable' )]
	public function test_unusable_fields_are_refused_with_a_reason( array $changes, string $mentions ): void {
		$result = self::typed( $changes );

		$this->assertIsString( $result );
		$this->assertStringContainsStringIgnoringCase( $mentions, $result );
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public static function unusable(): array {
		return array(
			'no provider'         => array( array( 'provider' => 'dropbox' ), 'who runs' ),
			'bad bucket'          => array( array( 'bucket' => 'No Spaces' ), 'bucket name' ),
			'dotted, virtual'     => array( array( 'provider' => 'aws', 'bucket' => 'my.files' ), 'without dots' ),
			'blank key'           => array( array( 'access_key' => '' ), 'access key' ),
			'secret with a space' => array( array( 'secret_key' => 'two words' ), 'secret' ),
			'region with a slash' => array( array( 'provider' => 'aws', 'region' => 'eu/west' ), 'region' ),
			'dotted region'       => array( array( 'provider' => 'aws', 'region' => 'evil.example.com#' ), 'region' ),
			'no account id'       => array( array( 'account_id' => '' ), 'account id' ),
			// No typed endpoint, at any address: a host the user runs can
			// show the scanner one file and everybody else another.
			'any endpoint'        => array( array( 'provider' => 'other', 'endpoint' => 'https://s3.example.com' ), 'who runs' ),
			'self-hosted MinIO'   => array( array( 'provider' => 'minio', 'endpoint' => '10.0.0.5' ), 'who runs' ),
		);
	}

	public function test_an_application_can_narrow_the_providers(): void {
		$this->assertInstanceOf( Connection::class, self::typed( array( 'provider' => 'linode', 'region' => 'us-east-1' ) ) );
		$this->assertIsString( self::typed( array( 'provider' => 'linode', 'region' => 'us-east-1' ), array( 'r2', 'aws' ) ) );
	}

	/**
	 * Allowing a provider whose host would be typed changes nothing: there
	 * is still no field to type it in.
	 */
	public function test_a_typed_endpoint_provider_cannot_be_allowed_in(): void {
		$this->assertIsString( self::typed( array( 'provider' => 'minio' ), array( 'minio' ) ) );
		$this->assertStringContainsString( 'typed', ( new Connection( Provider::MinIO, 'files', 'k', 's' ) )->problem() );
	}

	/**
	 * @param array<string, string> $changes Fields to replace.
	 * @param string                $origin  Where it reaches.
	 */
	#[DataProvider( 'official' )]
	public function test_each_provider_reaches_its_official_host( array $changes, string $origin ): void {
		$connection = self::typed( $changes );

		$this->assertInstanceOf( Connection::class, $connection );
		$this->assertSame( $origin, $connection->origin() );
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public static function official(): array {
		return array(
			'r2'           => array( array(), 'https://abcdef0123456789abcdef0123456789.r2.cloudflarestorage.com' ),
			'aws default'  => array( array( 'provider' => 'aws', 'region' => '' ), 'https://seller-files.s3.amazonaws.com' ),
			'aws regional' => array( array( 'provider' => 'aws', 'region' => 'EU-West-2' ), 'https://seller-files.s3.eu-west-2.amazonaws.com' ),
			'backblaze'    => array( array( 'provider' => 'backblaze', 'region' => 'us-west-004' ), 'https://seller-files.s3.us-west-004.backblazeb2.com' ),
			'digitalocean' => array( array( 'provider' => 'digitalocean', 'region' => 'ams3' ), 'https://seller-files.ams3.digitaloceanspaces.com' ),
			'wasabi'       => array( array( 'provider' => 'wasabi', 'region' => 'eu-central-1' ), 'https://seller-files.s3.eu-central-1.wasabisys.com' ),
		);
	}

	/**
	 * A field another provider would use, or an endpoint, is dropped rather
	 * than stored.
	 */
	public function test_fields_the_provider_does_not_use_are_not_kept(): void {
		$connection = self::typed( array( 'provider' => 'aws', 'region' => 'eu-west-2', 'endpoint' => 'https://evil.example' ) );

		$this->assertInstanceOf( Connection::class, $connection );
		$this->assertSame( '', $connection->account_id );
		$this->assertStringNotContainsString( 'evil', $connection->to_json() );
	}

	/* ─── Storing it ────────────────────────────────────────────────── */

	public function test_a_stored_connection_comes_back_the_same(): void {
		$connection = self::typed();
		$restored   = Connection::from_json( $connection->to_json() );

		$this->assertNotNull( $restored );
		$this->assertTrue( $connection->same_bucket( $restored ) );
		$this->assertSame( $connection->secret_key, $restored->secret_key );
	}

	public function test_a_document_that_is_not_one_reads_as_nothing(): void {
		$this->assertNull( Connection::from_json( 'not json' ) );
		$this->assertNull( Connection::from_json( '{"provider":"r2"}' ) );
		$this->assertNull( Connection::from_json( self::typed()->to_json(), array( 'aws' ) ), 'Rules that got stricter apply.' );
	}

	public function test_new_keys_for_the_same_bucket_are_the_same_bucket(): void {
		$this->assertTrue( self::typed()->same_bucket( self::typed( array( 'access_key' => 'ANOTHERKEY0002', 'secret_key' => 'another-secret' ) ) ) );
		$this->assertFalse( self::typed()->same_bucket( self::typed( array( 'bucket' => 'other-files' ) ) ) );
	}

	/* ─── Using it ──────────────────────────────────────────────────── */

	public function test_the_bucket_uses_the_client_it_is_given(): void {
		$http   = new Fake();
		$bucket = self::typed()->bucket( $http );

		$this->assertInstanceOf( Bucket::class, $bucket );
		$this->assertSame( 'seller-files', $bucket->name() );

		(void) $bucket->delete( 'k' );

		$this->assertStringStartsWith( 'https://abcdef0123456789abcdef0123456789.r2.cloudflarestorage.com/seller-files/k', $http->sent( 'DELETE' )[0]['url'] );
	}

	/* ─── Showing it ────────────────────────────────────────────────── */

	/**
	 * The masked form is all any page ever gets: no secret at all, and only
	 * the ends of anything else that identifies the owner.
	 */
	public function test_the_masked_form_shows_no_secret(): void {
		$masked = self::typed()->masked();
		$shown  = (string) json_encode( $masked );

		$this->assertSame( 'Cloudflare R2', $masked['label'] );
		$this->assertSame( 'auto', $masked['region'] );
		$this->assertStringNotContainsString( self::TYPED['secret_key'], $shown );
		$this->assertStringNotContainsString( self::TYPED['access_key'], $shown );
		$this->assertStringNotContainsString( self::TYPED['account_id'], $shown );
		$this->assertStringNotContainsString( self::TYPED['bucket'], $shown );
		$this->assertSame( 'SELL…0001', $masked['access_key'] );
		$this->assertStringEndsWith( '.r2.cloudflarestorage.com', $masked['host'] );
	}

	/**
	 * @param string $value  Value.
	 * @param string $masked What is shown.
	 */
	#[DataProvider( 'masks' )]
	public function test_a_mask_keeps_only_the_ends( string $value, string $masked ): void {
		$this->assertSame( $masked, Connection::mask( $value ) );
		$this->assertSame( Input::mask( $value ), Connection::mask( $value ), 'One rule for masking, in sanitize.' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function masks(): array {
		return array(
			'short'  => array( 'media', 'm…' ),
			'middle' => array( 'seller-files', 'sel…es' ),
			'long'   => array( 'SELLERACCESSKEY0001', 'SELL…0001' ),
		);
	}

	/**
	 * A dump in an error page or a log line is a way out for a secret.
	 */
	public function test_dumping_the_object_does_not_show_the_secret(): void {
		$this->assertStringNotContainsString( self::TYPED['secret_key'], print_r( self::typed(), true ) );
		$this->assertStringNotContainsString( self::TYPED['secret_key'], var_export( self::typed()->masked(), true ) );
	}
}
