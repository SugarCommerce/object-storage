<?php
/**
 * Probe tests.
 *
 * @package SugarCommerce\ObjectStorage\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\HttpClient\{Client, Fake, Response};
use SugarCommerce\ObjectStorage\{Connection, Probe, ProbeCheck, ProbeFault, ProbeStep};
use SugarCommerce\S3Signer\Cors\{Policy, Rule};

/**
 * Class ProbeTest
 *
 * Against a scripted bucket: what each step asks, and what it reports.
 */
final class ProbeTest extends TestCase {

	/**
	 * Where the uploader runs.
	 */
	private const string ORIGIN = 'https://app.example.com';

	/**
	 * A bucket answering the connection test.
	 *
	 * @param string $fails '' passes. 'keys' refuses everything (403),
	 *                      'cors' has no rule for the origin and keys that
	 *                      cannot read the policy, 'etag' has a rule that
	 *                      forgets ExposeHeaders, 'scoped' passes with keys
	 *                      that cannot read the policy, 'hidden' is scoped
	 *                      and lets the browser in without exposing ETag,
	 *                      'altered' reads back something else.
	 *
	 * @return Fake
	 */
	private static function bucket( string $fails = '' ): Fake {
		$fake = new Fake( new Response( true, 204 ) );

		if ( 'keys' === $fails ) {
			return $fake->on( '*', '*', new Response( false, 403, '<Error><Code>AccessDenied</Code></Error>' ) );
		}

		$rule    = 'etag' === $fails ? new Rule( array( self::ORIGIN ), array( 'PUT', 'POST' ) ) : Rule::browser_upload( array( self::ORIGIN ) );
		$browser = 'cors' === $fails
			? new Response( false, 403, '' )
			: static fn ( string $method, string $url, ?string $body, array $headers ): Response => new Response( true, 200, '', headers: array(
				'access-control-allow-origin'  => (string) ( $headers['Origin'] ?? '' ),
				'access-control-allow-methods' => 'PUT, POST',
			) );

		return $fake
			->on( 'GET', '*?cors*', in_array( $fails, array( 'cors', 'scoped', 'hidden' ), true )
				? new Response( false, 403, '<Error><Code>AccessDenied</Code></Error>' )
				: new Response( true, 200, ( new Policy( array( $rule ) ) )->to_xml() ) )
			->on( 'HEAD', '*', new Response( false, 404 ) )
			->on( 'PUT', '*', new Response( true, 200, '', headers: in_array( $fails, array( 'cors', 'hidden' ), true ) ? array() : array( 'access-control-expose-headers' => 'ETag' ) ) )
			->on( 'GET', '*', new Response( true, 200, 'altered' === $fails ? 'rewritten' : Probe::BODY ) )
			->on( 'OPTIONS', '*', $browser );
	}

	/**
	 * Run the test.
	 *
	 * @param Client             $http    The bucket.
	 * @param array<int, string> $origins Where the uploader runs.
	 *
	 * @return array<int, ProbeStep>
	 */
	private static function probe( Client $http, array $origins = array( self::ORIGIN ) ): array {
		return ( new Probe( $http ) )->run( Connection::from_array( ConnectionTest::TYPED ), $origins );
	}

	/**
	 * @param array<int, ProbeStep> $steps Steps.
	 *
	 * @return array<int, ProbeFault|null>
	 */
	private static function faults( array $steps ): array {
		return array_map( static fn ( ProbeStep $step ): ?ProbeFault => $step->fault, $steps );
	}

	public function test_a_working_bucket_passes_every_step(): void {
		$http  = self::bucket();
		$steps = self::probe( $http );

		$this->assertTrue( Probe::passed( $steps ) );
		$this->assertSame( ProbeCheck::cases(), array_map( static fn ( ProbeStep $step ): ProbeCheck => $step->check, $steps ) );
		// HEAD, PUT, GET, DELETE of the test object, then its CORS policy.
		$this->assertSame( array( 'HEAD', 'PUT', 'GET', 'DELETE', 'GET' ), $http->methods() );
	}

	/**
	 * The test object goes under the prefix, carries the uploader's Origin
	 * so the answer shows the CORS headers, and is removed again.
	 */
	public function test_the_test_object_is_written_under_its_prefix_and_removed(): void {
		$http = self::bucket();

		( new Probe( $http, 'mine-test/', Probe::BODY ) )->run( Connection::from_array( ConnectionTest::TYPED ), array( self::ORIGIN ) );

		$put    = $http->sent( 'PUT' )[0];
		$delete = $http->sent( 'DELETE' )[0]['url'];

		$this->assertStringContainsString( '/seller-files/mine-test/', $put['url'] );
		$this->assertSame( self::ORIGIN, $put['headers']['Origin'] );
		$this->assertSame( Probe::BODY, $put['body'] );
		$this->assertSame( strtok( $put['url'], '?' ), strtok( $delete, '?' ) );
	}

	public function test_refused_keys_stop_at_the_first_step(): void {
		$http  = self::bucket( 'keys' );
		$steps = self::probe( $http );

		$this->assertFalse( Probe::passed( $steps ) );
		$this->assertSame( array( ProbeFault::Refused, ProbeFault::Skipped, ProbeFault::Skipped, ProbeFault::Skipped, ProbeFault::Skipped ), self::faults( $steps ) );
		$this->assertSame( 403, $steps[0]->status );
		$this->assertSame( array( 'HEAD' ), $http->methods() );
	}

	/**
	 * No answer reads the same whatever the reason, so the test cannot be
	 * used to ask what is on the network; the reason is in the detail, for
	 * a log.
	 */
	public function test_every_refusal_to_connect_is_the_same_fault(): void {
		foreach ( array( 'blocked: Host resolves to a private or reserved address.', 'Could not resolve host' ) as $error ) {
			$step = self::probe( ( new Fake() )->on( '*', '*', Response::failure( $error ) ) )[0];

			$this->assertSame( ProbeFault::Unreachable, $step->fault );
			$this->assertSame( $error, $step->detail );
		}
	}

	/**
	 * @param int        $status What the bucket answered.
	 * @param ProbeFault $fault  What that stands for.
	 */
	#[DataProvider( 'statuses' )]
	public function test_a_status_names_what_to_check( int $status, ProbeFault $fault ): void {
		$this->assertSame( $fault, ProbeFault::of_status( $status ) );
	}

	/**
	 * @return array<string, array{0: int, 1: ProbeFault}>
	 */
	public static function statuses(): array {
		return array(
			'no answer'      => array( 0, ProbeFault::Unreachable ),
			'refused'        => array( 403, ProbeFault::Refused ),
			'no such bucket' => array( 404, ProbeFault::Missing ),
			'moved'          => array( 301, ProbeFault::Elsewhere ),
			'wrong region'   => array( 400, ProbeFault::Elsewhere ),
			'anything else'  => array( 500, ProbeFault::Failed ),
		);
	}

	public function test_something_else_read_back_is_altered(): void {
		$this->assertSame( ProbeFault::Altered, self::probe( self::bucket( 'altered' ) )[2]->fault );
	}

	/**
	 * Nothing in the test needs more than a line back; a bucket that sends
	 * more is not read into memory.
	 */
	public function test_answers_are_capped(): void {
		$caps = new \ArrayObject();
		$http = new readonly class( $caps ) extends Client {
			public function __construct( private \ArrayObject $caps ) {
				parent::__construct();
			}

			#[\Override]
			public function request( string $method, string $url, ?string $body = null, array $headers = array() ): Response {
				$this->caps[] = $this->max_bytes;

				return new Response( false, 403 );
			}
		};

		self::probe( $http );

		$this->assertSame( array( Probe::MAX_READ ), $caps->getArrayCopy() );
	}

	/**
	 * A rule that allows uploads but forgets ExposeHeaders is the mistake
	 * that fails at the last step of every upload.
	 */
	public function test_a_policy_that_forgets_etag_fails(): void {
		$step = self::probe( self::bucket( 'etag' ) )[4];

		$this->assertSame( ProbeFault::CorsPolicy, $step->fault );
		$this->assertStringContainsString( 'ETag', $step->detail );
	}

	/**
	 * Keys that may not read the policy are normal for a well-scoped key;
	 * the bucket is then asked the way a browser would ask it.
	 */
	public function test_keys_that_cannot_read_the_policy_are_checked_by_preflight(): void {
		$http  = self::bucket( 'scoped' );
		$steps = self::probe( $http );

		$this->assertTrue( Probe::passed( $steps ) );
		$this->assertSame( 'PUT', $http->sent( 'OPTIONS' )[0]['headers']['Access-Control-Request-Method'] );
		$this->assertSame( self::ORIGIN, $http->sent( 'OPTIONS' )[0]['headers']['Origin'] );
	}

	public function test_a_refused_preflight_names_the_origin(): void {
		$step = self::probe( self::bucket( 'cors' ) )[4];

		$this->assertSame( ProbeFault::CorsOrigin, $step->fault );
		$this->assertSame( self::ORIGIN, $step->detail );
	}

	public function test_a_preflight_that_passes_still_needs_etag_exposed(): void {
		$this->assertSame( ProbeFault::EtagHidden, self::probe( self::bucket( 'hidden' ) )[4]->fault );
	}

	public function test_no_origins_skips_the_cors_question(): void {
		$http  = self::bucket( 'cors' );
		$steps = self::probe( $http, array() );

		$this->assertTrue( Probe::passed( $steps ) );
		$this->assertSame( array(), $http->sent( 'OPTIONS' ) );
		$this->assertArrayNotHasKey( 'Origin', $http->sent( 'PUT' )[0]['headers'] );
	}

	public function test_no_steps_is_not_a_pass(): void {
		$this->assertFalse( Probe::passed( array() ) );
	}

	public function test_each_check_says_what_it_was_doing(): void {
		$this->assertSame( 'write to', ProbeCheck::Write->verb() );
		$this->assertSame( '', ProbeCheck::Cors->verb() );
	}
}
