<?php

namespace Levy\EuVat\Registry;

use Levy\EuVat\Domain\ValidationResult;
use Levy\EuVat\Domain\VatNumber;

interface Registry {

	public function id(): string;

	public function supports( VatNumber $n ): bool;

	/**
	 * Never throws: every failure comes back as an UNAVAILABLE result.
	 */
	public function check( VatNumber $n, ?VatNumber $requester ): ValidationResult;
}
