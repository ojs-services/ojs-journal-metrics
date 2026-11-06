<?php
/**
 * @file plugins/generic/journalMetrics/classes/compat/HasTaskSchedulerPolyfill.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * OJS 3.5 plugins register scheduled tasks by implementing
 * PKP\plugins\interfaces\HasTaskScheduler. OJS 3.4 does not have the
 * interface (tasks go through Acron there), so a class implementing it
 * would not load. This file declares the identical interface ONLY when
 * the running OJS lacks it; OJS 3.4 never calls it. Loaded from
 * JournalMetricsPlugin.php, guarded by interface_exists().
 */

namespace PKP\plugins\interfaces;

interface HasTaskScheduler {
	public function registerSchedules(\PKP\scheduledTask\PKPScheduler $scheduler): void;
}
