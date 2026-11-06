<?php
/**
 * @file plugins/generic/journalMetrics/classes/JournalMetricsCompat.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsCompat
 * @brief The few runtime differences between OJS 3.4 and 3.5, in one place.
 *
 * Every branch is chosen by capability (class / method / column exists),
 * never by version number.
 */

namespace APP\plugins\generic\journalMetrics\classes;

use Illuminate\Support\Facades\Schema;
use PKP\core\PKPRequest;

class JournalMetricsCompat {
	/**
	 * OJS 3.5 runs plugin tasks through the core scheduler
	 * (HasTaskScheduler); OJS 3.4 through the Acron plugin.
	 */
	public static function hasCoreScheduler(): bool {
		return class_exists('\PKP\scheduledTask\PKPScheduler');
	}

	/**
	 * CSRF token of the current session: 3.4 getCSRFToken(), 3.5 token().
	 */
	public static function csrfToken(PKPRequest $request): string {
		$session = $request->getSession();
		return method_exists($session, 'token')
			? (string) $session->token()
			: (string) $session->getCSRFToken();
	}

	/**
	 * Answer with 404: 3.4 Dispatcher::handle404(), 3.5 throws the HTTP
	 * exception (handle404() no longer exists there).
	 */
	public static function notFound(PKPRequest $request): void {
		$dispatcher = $request->getDispatcher();
		if (method_exists($dispatcher, 'handle404')) {
			$dispatcher->handle404();
			return;
		}
		throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
	}

	/**
	 * OJS 3.5 adds start / end dates to role memberships (masthead).
	 * SQL fragment keeping only memberships that have not ended; empty on
	 * 3.4, where every membership is current.
	 *
	 * @param string $alias alias of the user_user_groups table
	 */
	public static function activeMembershipClause(string $alias = 'uug'): string {
		static $hasDateEnd = null;
		if ($hasDateEnd === null) {
			$hasDateEnd = Schema::hasColumn('user_user_groups', 'date_end');
		}
		return $hasDateEnd ? " AND ({$alias}.date_end IS NULL OR {$alias}.date_end > NOW())" : '';
	}
}
