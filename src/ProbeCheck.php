<?php
/**
 * One thing the connection test tries.
 *
 * @package   SugarCommerce\ObjectStorage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage;

/**
 * Enum ProbeCheck
 *
 * The steps of Probe::run(), in the order they run. Each is something an
 * application storing files in the bucket will later do for real.
 *
 * @since 1.3.0
 */
enum ProbeCheck: string {

	/** The endpoint answers and the keys are accepted. */
	case Reach = 'reach';

	/** The keys may write. */
	case Write = 'write';

	/** And read back what they wrote, by presigned URL. */
	case Read = 'read';

	/** And delete it, which cleaning up a refused upload needs. */
	case Delete = 'delete';

	/** A browser on the uploader's page may upload straight to it. */
	case Cors = 'cors';

	/**
	 * The step as a verb with its preposition, for a sentence like "could
	 * not write to the bucket".
	 *
	 * @since 1.3.0
	 *
	 * @return string '' for the CORS check, which is not a request of its own.
	 */
	public function verb(): string {
		return match ( $this ) {
			self::Reach  => 'reach',
			self::Write  => 'write to',
			self::Read   => 'read from',
			self::Delete => 'delete from',
			self::Cors   => '',
		};
	}
}
