<?php
/**
 * A storage call that did not produce an answer.
 *
 * @package   SugarCommerce\ObjectStorage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage;

/**
 * Class StorageError
 *
 * Thrown where an empty answer and no answer would look the same afterwards —
 * reading bytes back, where '' is also what an empty file returns.
 */
final class StorageError extends \RuntimeException {

	/**
	 * @param string $operation What was being done, e.g. "read".
	 * @param int    $status    HTTP status, 0 when nothing answered.
	 * @param string $detail    Start of the response, for the log.
	 */
	public function __construct(
		public readonly string $operation,
		public readonly int $status,
		string $detail = '',
	) {
		parent::__construct( sprintf( 'Storage %s failed (%d)%s', $operation, $status, '' === $detail ? '' : ': ' . substr( $detail, 0, 200 ) ) );
	}
}
