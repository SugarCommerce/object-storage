<?php
/**
 * Results that must not be ignored.
 *
 * @package   SugarCommerce\ObjectStorage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\ObjectStorage\Bucket;

/**
 * Class MustCheckTest
 *
 * These answer "did it work?" or "is it allowed?", and code that ignores the
 * answer behaves as though it were yes. #[\NoDiscard] makes PHP warn where a
 * result is dropped; this keeps the attribute from being lost in a refactor.
 */
final class MustCheckTest extends TestCase {

	/**
	 * @return array<string, array{string}>
	 */
	public static function methods(): array {
		return array_combine( array( 'start_upload', 'finish_upload', 'abort_upload', 'put', 'copy_from', 'delete' ), array_map( static fn ( string $m ): array => array( $m ), array( 'start_upload', 'finish_upload', 'abort_upload', 'put', 'copy_from', 'delete' ) ) );
	}

	#[DataProvider( 'methods' )]
	public function test_the_result_must_be_used( string $method ): void {
		$attributes = ( new \ReflectionMethod( Bucket::class, $method ) )->getAttributes( \NoDiscard::class );

		$this->assertCount( 1, $attributes, Bucket::class . '::' . $method . ' lost #[\NoDiscard].' );
		$this->assertNotSame( '', $attributes[0]->newInstance()->message, 'Say why it matters.' );
	}
}
