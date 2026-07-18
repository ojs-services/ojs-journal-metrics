<?php
/**
 * @file plugins/generic/countryStats/classes/JournalMetricsCountryDAO.inc.php
 *
 * @class JournalMetricsCountryDAO
 * @brief Database queries for Journal Metrics plugin (ported verbatim from the Country Statistics plugin DAO).
 */

import('lib.pkp.classes.db.DAO');

class JournalMetricsCountryDAO extends DAO {

	// STATUS_PUBLISHED = 3 in OJS 3.3
	const STATUS_PUBLISHED = 3;

	/**
	 * Get unique author count grouped by country.
	 *
	 * @param int    $contextId       Journal context ID
	 * @param string $uniqueStrategy  'orcid', 'email', or 'name'
	 * @param string $orcidFallback   'email_name', 'email', 'name', 'none'
	 * @param string $dateStart       Optional start date (Y-m-d)
	 * @param string $dateEnd         Optional end date (Y-m-d)
	 * @return array  [ ['country'=>'TR', 'author_count'=>123], ... ]
	 */
	public function getAuthorsByCountry($contextId, $uniqueStrategy = 'orcid', $orcidFallback = 'email_name', $dateStart = null, $dateEnd = null) {
		$params = array((int)$contextId);

		// Build the unique identifier expression based on strategy
		$uniqueExpr = $this->_buildUniqueExpr($uniqueStrategy, $orcidFallback);

		$dateWhere = $this->_buildDateWhere($dateStart, $dateEnd, $params);

		$sql = "
			SELECT country, COUNT(*) AS author_count
			FROM (
				SELECT
					{$uniqueExpr} AS unique_id,
					MAX(COALESCE(c.setting_value, '')) AS country
				FROM authors a
				JOIN publications p ON (a.publication_id = p.publication_id)
				JOIN submissions s ON (p.submission_id = s.submission_id)
				LEFT JOIN author_settings c ON (a.author_id = c.author_id AND c.setting_name = 'country')
				LEFT JOIN author_settings orcid ON (a.author_id = orcid.author_id AND orcid.setting_name = 'orcid')
				LEFT JOIN author_settings gn ON (a.author_id = gn.author_id AND gn.setting_name = 'givenName' AND gn.locale = s.locale)
				LEFT JOIN author_settings fn ON (a.author_id = fn.author_id AND fn.setting_name = 'familyName' AND fn.locale = s.locale)
				WHERE s.status = " . self::STATUS_PUBLISHED . "
				  AND s.context_id = ?
				  AND p.publication_id = s.current_publication_id
				  {$dateWhere}
				GROUP BY unique_id
			) AS unique_authors
			WHERE country != ''
			GROUP BY country
			ORDER BY author_count DESC
		";

		$result = $this->retrieve($sql, $params);
		return $this->_resultToArray($result);
	}

	/**
	 * Get article count grouped by country.
	 * An article counts +1 per unique country in its author list.
	 *
	 * @param int    $contextId
	 * @param string $dateStart
	 * @param string $dateEnd
	 * @return array  [ ['country'=>'TR', 'article_count'=>99], ... ]
	 */
	public function getArticlesByCountry($contextId, $dateStart = null, $dateEnd = null) {
		$params = array((int)$contextId);
		$dateWhere = $this->_buildDateWhere($dateStart, $dateEnd, $params);

		$sql = "
			SELECT
				c.setting_value AS country,
				COUNT(DISTINCT s.submission_id) AS article_count
			FROM authors a
			JOIN publications p ON (a.publication_id = p.publication_id)
			JOIN submissions s ON (p.submission_id = s.submission_id)
			JOIN author_settings c ON (a.author_id = c.author_id AND c.setting_name = 'country')
			WHERE s.status = " . self::STATUS_PUBLISHED . "
			  AND s.context_id = ?
			  AND p.publication_id = s.current_publication_id
			  AND c.setting_value IS NOT NULL
			  AND TRIM(c.setting_value) != ''
			  {$dateWhere}
			GROUP BY c.setting_value
			ORDER BY article_count DESC
		";

		$result = $this->retrieve($sql, $params);
		return $this->_resultToArray($result);
	}

	/**
	 * Get summary totals.
	 *
	 * @param int    $contextId
	 * @param string $uniqueStrategy
	 * @param string $orcidFallback
	 * @param string $dateStart
	 * @param string $dateEnd
	 * @return array ['totalArticles'=>int, 'totalAuthors'=>int, 'totalCountries'=>int]
	 */
	public function getTotals($contextId, $uniqueStrategy = 'orcid', $orcidFallback = 'email_name', $dateStart = null, $dateEnd = null) {
		// Total published articles
		$params1 = array((int)$contextId);
		$dateWhere1 = $this->_buildDateWhere($dateStart, $dateEnd, $params1);

		$sql1 = "
			SELECT COUNT(DISTINCT s.submission_id) AS cnt
			FROM submissions s
			JOIN publications p ON (p.submission_id = s.submission_id AND p.publication_id = s.current_publication_id)
			WHERE s.status = " . self::STATUS_PUBLISHED . "
			  AND s.context_id = ?
			  {$dateWhere1}
		";
		$result1 = $this->retrieve($sql1, $params1);
		$row1 = $result1->current();
		$totalArticles = $row1 ? (int)$row1->cnt : 0;

		// Total unique authors + total countries
		$params2 = array((int)$contextId);
		$uniqueExpr = $this->_buildUniqueExpr($uniqueStrategy, $orcidFallback);
		$dateWhere2 = $this->_buildDateWhere($dateStart, $dateEnd, $params2);

		$sql2 = "
			SELECT
				COUNT(*) AS total_authors,
				COUNT(DISTINCT CASE WHEN country != '' THEN country END) AS total_countries
			FROM (
				SELECT
					{$uniqueExpr} AS unique_id,
					MAX(COALESCE(c.setting_value, '')) AS country
				FROM authors a
				JOIN publications p ON (a.publication_id = p.publication_id)
				JOIN submissions s ON (p.submission_id = s.submission_id)
				LEFT JOIN author_settings c ON (a.author_id = c.author_id AND c.setting_name = 'country')
				LEFT JOIN author_settings orcid ON (a.author_id = orcid.author_id AND orcid.setting_name = 'orcid')
				LEFT JOIN author_settings gn ON (a.author_id = gn.author_id AND gn.setting_name = 'givenName' AND gn.locale = s.locale)
				LEFT JOIN author_settings fn ON (a.author_id = fn.author_id AND fn.setting_name = 'familyName' AND fn.locale = s.locale)
				WHERE s.status = " . self::STATUS_PUBLISHED . "
				  AND s.context_id = ?
				  AND p.publication_id = s.current_publication_id
				  {$dateWhere2}
				GROUP BY unique_id
			) AS unique_authors
		";
		$result2 = $this->retrieve($sql2, $params2);
		$row2 = $result2->current();

		return array(
			'totalArticles'  => $totalArticles,
			'totalAuthors'   => $row2 ? (int)$row2->total_authors : 0,
			'totalCountries' => $row2 ? (int)$row2->total_countries : 0,
		);
	}

	// ------------------------------------------------------------------
	//  Private helpers
	// ------------------------------------------------------------------

	/**
	 * Build SQL expression for unique author identification.
	 */
	private function _buildUniqueExpr($strategy, $orcidFallback) {
		switch ($strategy) {
			case 'email':
				return "LOWER(TRIM(a.email))";

			case 'name':
				return "CONCAT(LOWER(TRIM(COALESCE(gn.setting_value,''))), '|', LOWER(TRIM(COALESCE(fn.setting_value,''))))";

			case 'orcid':
			default:
				switch ($orcidFallback) {
					case 'email':
						return "COALESCE(NULLIF(TRIM(orcid.setting_value),''), LOWER(TRIM(a.email)))";

					case 'name':
						return "COALESCE(NULLIF(TRIM(orcid.setting_value),''), CONCAT(LOWER(TRIM(COALESCE(gn.setting_value,''))), '|', LOWER(TRIM(COALESCE(fn.setting_value,'')))))";

					case 'none':
						return "NULLIF(TRIM(orcid.setting_value),'')";

					case 'email_name':
					default:
						return "COALESCE(
							NULLIF(TRIM(orcid.setting_value),''),
							NULLIF(LOWER(TRIM(a.email)),''),
							CONCAT(LOWER(TRIM(COALESCE(gn.setting_value,''))), '|', LOWER(TRIM(COALESCE(fn.setting_value,''))))
						)";
				}
		}
	}

	/**
	 * Build date-range WHERE clause.
	 */
	private function _buildDateWhere($dateStart, $dateEnd, &$params) {
		$where = '';
		if ($dateStart) {
			$where .= " AND p.date_published >= ?";
			$params[] = $dateStart;
		}
		if ($dateEnd) {
			$where .= " AND p.date_published <= ?";
			$params[] = $dateEnd;
		}
		return $where;
	}

	/**
	 * Convert ADORecordSet result to a plain array.
	 */
	private function _resultToArray($result) {
		$rows = array();
		foreach ($result as $row) {
			$rows[] = (array)$row;
		}
		return $rows;
	}
}
