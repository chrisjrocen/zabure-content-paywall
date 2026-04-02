<?php
/**
 * Database class for Zabure Content Paywall.
 *
 * Handles all CRUD operations on the wp_zabure_paywall_sessions table.
 *
 * @package ZabureContentPaywall
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Zabure_Database
 *
 * All SQL queries live here — never outside this class.
 */
class Zabure_Database {

	/**
	 * The sessions table name (without prefix).
	 */
	const TABLE_NAME = 'zabure_paywall_sessions';

	/**
	 * Get the full table name including the WordPress table prefix.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create the sessions table on plugin activation.
	 *
	 * Uses dbDelta() so it is safe to call on upgrades as well.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		$table      = self::get_table_name();
		$charset    = $wpdb->get_charset_collate();
		$sql        = "CREATE TABLE {$table} (
			id                    BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			session_token         VARCHAR(64)      NOT NULL,
			user_id               BIGINT UNSIGNED  NOT NULL,
			post_id               BIGINT UNSIGNED  NOT NULL,
			amount                INT UNSIGNED     NOT NULL,
			currency              VARCHAR(10)      NOT NULL,
			zabure_link_id        VARCHAR(100)     DEFAULT NULL,
			zabure_transaction_id VARCHAR(100)     DEFAULT NULL,
			zabure_external_ref   VARCHAR(100)     DEFAULT NULL,
			status                ENUM('pending','redirect_received','completed','failed','expired') NOT NULL DEFAULT 'pending',
			source                ENUM('redirect','webhook','manual') DEFAULT NULL,
			initiated_at          DATETIME         NOT NULL,
			completed_at          DATETIME         DEFAULT NULL,
			expires_at            DATETIME         NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY   uq_session_token (session_token),
			KEY          idx_user_id      (user_id),
			KEY          idx_post_id      (post_id),
			KEY          idx_status       (status)
		) ENGINE=InnoDB {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a new session record.
	 *
	 * @param array $data Associative array of column => value pairs.
	 * @return int|false The new row ID, or false on failure.
	 */
	public static function insert_session( array $data ): int|false {
		global $wpdb;

		$result = $wpdb->insert(
			self::get_table_name(),
			$data,
			self::get_format_array( $data )
		);

		if ( false === $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a session by its token.
	 *
	 * @param string $token The 64-character hex session token.
	 * @return object|null The session row, or null if not found.
	 */
	public static function get_session_by_token( string $token ): object|null {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_token = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$token
			)
		);

		return $row ?: null;
	}

	/**
	 * Get the most recent pending session for a given user/post combination.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @param int $post_id The WordPress post ID.
	 * @return object|null
	 */
	public static function get_pending_session_by_user_post( int $user_id, int $post_id ): object|null {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE user_id = %d
				  AND post_id = %d
				  AND status  = 'pending'
				  AND expires_at > NOW()
				ORDER BY initiated_at DESC
				LIMIT 1",
				$user_id,
				$post_id
			)
		);

		return $row ?: null;
	}

	/**
	 * Update a session's status, and optionally set extra columns.
	 *
	 * @param int    $id         The session row ID.
	 * @param string $status     New status value.
	 * @param array  $extra_data Additional column => value pairs to update.
	 * @return bool True on success, false on failure.
	 */
	public static function update_session_status( int $id, string $status, array $extra_data = [] ): bool {
		global $wpdb;

		$data = array_merge( [ 'status' => $status ], $extra_data );

		$result = $wpdb->update(
			self::get_table_name(),
			$data,
			[ 'id' => $id ],
			self::get_format_array( $data ),
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Get sessions matching amount + currency within a time window.
	 *
	 * Used by webhook Match Strategy A to correlate a webhook payment
	 * with a session that has been confirmed via browser redirect.
	 *
	 * @param int    $amount   Payment amount in smallest currency unit.
	 * @param string $currency Currency code.
	 * @param int    $minutes  How many minutes back to search (default: 30).
	 * @return array Array of session row objects.
	 */
	public static function get_sessions_by_amount_currency_window( int $amount, string $currency, int $minutes = 30 ): array {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE amount   = %d
				  AND currency = %s
				  AND expires_at > NOW()
				  AND initiated_at >= DATE_SUB(NOW(), INTERVAL %d MINUTE)
				ORDER BY initiated_at DESC",
				$amount,
				$currency,
				$minutes
			)
		);

		return $rows ?: [];
	}

	/**
	 * Get all sessions, optionally filtered.
	 *
	 * Used by the admin Payment Logs page.
	 *
	 * @param array $filters Optional filters: status, user_id, post_id, date_from, date_to, limit, offset.
	 * @return array Array of session row objects.
	 */
	public static function get_all_sessions( array $filters = [] ): array {
		global $wpdb;

		$table  = self::get_table_name();
		$where  = [];
		$params = [];

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_text_field( $filters['status'] );
		}

		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}

		if ( ! empty( $filters['post_id'] ) ) {
			$where[]  = 'post_id = %d';
			$params[] = (int) $filters['post_id'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'initiated_at >= %s';
			$params[] = sanitize_text_field( $filters['date_from'] );
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'initiated_at <= %s';
			$params[] = sanitize_text_field( $filters['date_to'] );
		}

		$limit  = isset( $filters['limit'] ) ? (int) $filters['limit'] : 100;
		$offset = isset( $filters['offset'] ) ? (int) $filters['offset'] : 0;

		$sql = "SELECT * FROM {$table}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql .= ' ORDER BY initiated_at DESC';
		$sql .= " LIMIT {$limit} OFFSET {$offset}";

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $sql );
		}

		return $rows ?: [];
	}

	/**
	 * Mark sessions past their expires_at timestamp as 'expired'.
	 *
	 * Can be called via WP-Cron or on admin page load.
	 *
	 * @return int Number of sessions updated.
	 */
	public static function expire_old_sessions(): int {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'expired'
				WHERE status IN ('pending','redirect_received')
				  AND expires_at < NOW()"
			)
		);

		return (int) $result;
	}

	/**
	 * Check whether a transaction ID already exists in the sessions table.
	 *
	 * Used for webhook deduplication.
	 *
	 * @param string $transaction_id The Zabure transaction ID.
	 * @return bool True if a duplicate exists.
	 */
	public static function transaction_id_exists( string $transaction_id ): bool {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE zabure_transaction_id = %s",
				$transaction_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Flush WordPress rewrite rules.
	 *
	 * Called on plugin deactivation to remove the /zabure-return/ rewrite rule.
	 *
	 * @return void
	 */
	public static function flush_rewrite_rules(): void {
		flush_rewrite_rules();
	}

	/**
	 * Determine the sprintf format string for each value in a data array.
	 *
	 * @param array $data The data array being inserted/updated.
	 * @return array Array of format strings (%s, %d, %f).
	 */
	private static function get_format_array( array $data ): array {
		$integer_cols = [ 'id', 'user_id', 'post_id', 'amount' ];
		$formats      = [];

		foreach ( array_keys( $data ) as $col ) {
			$formats[] = in_array( $col, $integer_cols, true ) ? '%d' : '%s';
		}

		return $formats;
	}

	/**
	 * Drop the sessions table and all plugin data.
	 *
	 * Called by uninstall.php when zabure_delete_data_on_uninstall is true.
	 *
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
