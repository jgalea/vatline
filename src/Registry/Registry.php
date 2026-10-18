<?php

namespace Vatline\Registry;

use Vatline\Domain\ValidationResult;
use Vatline\Domain\VatNumber;

interface Registry {

	public function id(): string;

	public function supports( VatNumber $n ): bool;

	/**
	 * Never throws: every failure comes back as an UNAVAILABLE result.
	 */
	public function check( VatNumber $n, ?VatNumber $requester ): ValidationResult;
}
