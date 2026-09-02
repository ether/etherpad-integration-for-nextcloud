<?php

declare(strict_types=1);

namespace OCP\BackgroundJob;

if (!interface_exists(IJob::class)) {
	interface IJob {
	}
}
