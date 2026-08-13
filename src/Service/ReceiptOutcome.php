<?php
/**
 * What happened when goods were received.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Service;

use Oxysoft\OxySuppliers\Domain\Receipt;

/**
 * The answer to "I pressed the button, what happened?".
 *
 * `ALREADY_RECORDED` is deliberately **not** an error. It is what a second
 * press, a reload or a retried request gets, and the right thing to show
 * somebody is the receipt that already exists — not a failure, and certainly
 * not a second delivery.
 */
final class ReceiptOutcome {

	public const RECORDED         = 'recorded';
	public const ALREADY_RECORDED = 'already_recorded';
	public const BUSY             = 'busy';
	public const NOTHING          = 'nothing';
	public const TOO_MANY         = 'too_many';
	public const FAILED           = 'failed';

	/**
	 * Hold the answer.
	 *
	 * @param string       $status  One of the constants above.
	 * @param Receipt|null $receipt The receipt, when there is one.
	 * @param string       $detail  Something to show a person, when it helps.
	 */
	public function __construct(
		public readonly string $status,
		public readonly ?Receipt $receipt = null,
		public readonly string $detail = ''
	) {
	}

	/**
	 * Whether the goods are now on the shelf as far as the plugin is concerned.
	 *
	 * True for a second press as well: the delivery was recorded, once.
	 *
	 * @return bool
	 */
	public function is_recorded(): bool {
		return self::RECORDED === $this->status || self::ALREADY_RECORDED === $this->status;
	}
}
