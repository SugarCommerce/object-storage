<?php
/**
 * How one step of the connection test went.
 *
 * @package   SugarCommerce\ObjectStorage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage;

/**
 * Class ProbeStep
 *
 * @since 1.3.0
 */
final readonly class ProbeStep {

	/**
	 * @since 1.3.0
	 *
	 * @param ProbeCheck      $check  What was tried.
	 * @param ProbeFault|null $fault  What went wrong, or null when it passed.
	 * @param int             $status The HTTP status that decided it, or 0.
	 * @param string          $detail For Unreachable, the transport's reason
	 *                                (for a log, not a page); for CorsPolicy,
	 *                                the policy's reason; for CorsOrigin, the
	 *                                origin. Otherwise ''.
	 */
	public function __construct(
		public ProbeCheck $check,
		public ?ProbeFault $fault = null,
		public int $status = 0,
		public string $detail = '',
	) {}

	/**
	 * Whether the step passed.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return null === $this->fault;
	}
}
