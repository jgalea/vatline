<?php

namespace Vatright\Tests\Integration\Ledger;

require_once __DIR__ . '/LedgerWriterTest.php';

class LedgerWriterPostsStorageTest extends LedgerWriterTest {

	protected function storage_is_hpos(): bool {
		return false;
	}
}
